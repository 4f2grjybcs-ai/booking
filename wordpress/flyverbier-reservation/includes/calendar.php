<?php
// Calendrier des réservations :
//  - page web partageable avec les pilotes (lien secret, lecture seule) : /?fvr_planning=JETON
//  - même calendrier modifiable par l'administrateur (connecté à WordPress) et dans wp-admin
//  - abonnement agenda (Google / Apple / Outlook) : /?fvr_ics=JETON

if (!defined('ABSPATH')) {
    exit;
}

function fvr_planning_token(): string
{
    $token = get_option('fvr_planning_token');
    if (!$token) {
        $token = fvr_regenerate_planning_token();
    }
    return $token;
}

function fvr_regenerate_planning_token(): string
{
    $token = wp_generate_password(32, false, false);
    update_option('fvr_planning_token', $token, false);
    return $token;
}

function fvr_valid_token($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(fvr_planning_token(), $token);
}

/**
 * Jeton de lien : ['pilot' => null] pour le lien d'équipe, ['pilot' => [...]] pour un lien personnel, null si invalide.
 */
function fvr_token_context($token): ?array
{
    if (fvr_valid_token($token)) {
        return ['pilot' => null];
    }
    $pilot = fvr_pilot_by_token($token);
    return $pilot ? ['pilot' => $pilot] : null;
}

function fvr_can_view_calendar(WP_REST_Request $req): bool
{
    return current_user_can(fvr_cap()) || fvr_token_context($req->get_param('token')) !== null;
}

function fvr_planning_url(): string
{
    return add_query_arg('fvr_planning', fvr_planning_token(), home_url('/'));
}

function fvr_ics_url(): string
{
    return add_query_arg('fvr_ics', fvr_planning_token(), home_url('/'));
}

// ---------- Données du calendrier ----------

// Les pilotes ne voient ni les prix ni les e-mails des clients
function fvr_booking_view(array $b, bool $canEdit): array
{
    $fields = ['id', 'reference', 'date', 'time', 'flight_id', 'flight_name', 'passengers', 'name', 'phone',
               'weights', 'message', 'status', 'admin_notes'];
    if ($canEdit) {
        array_push($fields, 'price', 'email', 'created_at');
    }
    $out = array_intersect_key($b, array_flip($fields));
    $out['id'] = (int) $out['id'];
    $out['passengers'] = (int) $out['passengers'];
    $out['flight_id'] = (int) $out['flight_id'];
    if (isset($out['price'])) {
        $out['price'] = (float) $out['price'];
    }
    return $out;
}

// Recherche rapide (nom, téléphone, référence, e-mail) parmi les réservations récentes et à venir
function fvr_search_bookings(string $q, bool $canEdit): array
{
    global $wpdb;
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $like = '%' . $wpdb->esc_like($q) . '%';
    $where = ['name LIKE %s', 'reference LIKE %s'];
    $args = [$like, $like];
    if ($canEdit) {
        $where[] = 'email LIKE %s';
        $args[] = $like;
    }
    $digits = preg_replace('/\D/', '', $q);
    if (strlen($digits) >= 3) {
        // Ignore espaces, points et tirets dans les numéros ; « 079… » retrouve aussi « +41 79… »
        $where[] = "REPLACE(REPLACE(REPLACE(phone, ' ', ''), '.', ''), '-', '') LIKE %s";
        $args[] = '%' . $wpdb->esc_like(ltrim($digits, '0')) . '%';
    }
    $args[] = gmdate('Y-m-d', strtotime(fvr_today() . ' -60 days'));
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('bookings') . ' WHERE (' . implode(' OR ', $where)
        . ') AND date >= %s ORDER BY date, time LIMIT 30', $args), ARRAY_A);
    return fvr_bookings_with_pilots($rows, $canEdit);
}

function fvr_bookings_with_pilots(array $rows, bool $canEdit): array
{
    $assign = fvr_assignments($rows);
    return array_map(function ($b) use ($canEdit, $assign) {
        $out = fvr_booking_view($b, $canEdit);
        $out['pilots'] = $assign[(int) $b['id']] ?? [];
        return $out;
    }, $rows);
}

function fvr_calendar_data(string $from, string $to, bool $canEdit): array
{
    global $wpdb;
    $bookings = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . fvr_table('bookings') . ' WHERE date BETWEEN %s AND %s ORDER BY date, time, id', $from, $to), ARRAY_A);

    $bookings = fvr_bookings_with_pilots($bookings, $canEdit);

    $data = [
        'from'     => $from,
        'to'       => $to,
        'today'    => fvr_today(),
        'can_edit' => $canEdit,
        'slots'    => array_map(function ($s) {
            return ['time' => $s['time'], 'capacity' => (int) $s['capacity'], 'active' => (int) $s['active']];
        }, $wpdb->get_results('SELECT time, capacity, active FROM ' . fvr_table('slots') . ' ORDER BY time', ARRAY_A)),
        'blocked'  => $wpdb->get_results($wpdb->prepare(
            'SELECT date, reason FROM ' . fvr_table('blocked') . ' WHERE date BETWEEN %s AND %s', $from, $to), ARRAY_A),
        'bookings' => $bookings,
        'pilots'   => array_map(function ($p) {
            return ['id' => $p['id'], 'name' => $p['name'], 'color' => $p['color'], 'rank' => $p['default_rank'], 'active' => $p['active']];
        }, fvr_pilots()),
    ];
    if ($canEdit) {
        $data['flights'] = array_map(function ($f) {
            return ['id' => (int) $f['id'], 'name' => $f['name'], 'price' => (float) $f['price'], 'active' => (int) $f['active']];
        }, $wpdb->get_results('SELECT id, name, price, active FROM ' . fvr_table('flights') . ' ORDER BY sort_order, id', ARRAY_A));
    }
    return $data;
}

// ---------- API ----------

add_action('rest_api_init', function () {
    register_rest_route('fvr/v1', '/calendar', [
        'methods'             => 'GET',
        'permission_callback' => 'fvr_can_view_calendar',
        'callback'            => function (WP_REST_Request $req) {
            $from = (string) $req->get_param('from');
            $to = (string) $req->get_param('to');
            if (!fvr_valid_date($from) || !fvr_valid_date($to) || $to < $from
                || strtotime($to) - strtotime($from) > 100 * DAY_IN_SECONDS) {
                return new WP_Error('invalid', 'Période invalide.', ['status' => 400]);
            }
            $res = rest_ensure_response(fvr_calendar_data($from, $to, current_user_can(fvr_cap())));
            $res->header('Cache-Control', 'no-store');
            $res->header('X-Robots-Tag', 'noindex');
            return $res;
        },
    ]);

    register_rest_route('fvr/v1', '/search', [
        'methods'             => 'GET',
        'permission_callback' => 'fvr_can_view_calendar',
        'callback'            => function (WP_REST_Request $req) {
            $res = rest_ensure_response(['bookings' => fvr_search_bookings((string) $req->get_param('q'), current_user_can(fvr_cap()))]);
            $res->header('Cache-Control', 'no-store');
            return $res;
        },
    ]);

    $adminOnly = function () {
        return current_user_can(fvr_cap());
    };

    register_rest_route('fvr/v1', '/admin/booking', [
        'methods'             => 'POST',
        'permission_callback' => $adminOnly,
        'callback'            => function (WP_REST_Request $req) {
            $p = (array) ($req->get_json_params() ?: $req->get_body_params());
            $saved = fvr_save_booking($p, (int) ($p['id'] ?? 0));
            return is_wp_error($saved) ? $saved : ['ok' => true, 'id' => $saved];
        },
    ]);

    register_rest_route('fvr/v1', '/admin/booking/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'permission_callback' => $adminOnly,
        'callback'            => function (WP_REST_Request $req) {
            fvr_delete_booking((int) $req['id']);
            return ['ok' => true];
        },
    ]);
});

// ---------- Affichage de l'application calendrier ----------

function fvr_calendar_config(bool $canEdit, string $token = '', ?array $pilot = null): array
{
    return [
        'me'       => $pilot ? ['id' => (int) $pilot['id'], 'name' => $pilot['name']] : null,
        'api'      => esc_url_raw(rest_url('fvr/v1/')),
        'token'    => $token,
        'nonce'    => $canEdit ? wp_create_nonce('wp_rest') : '',
        'canEdit'  => $canEdit,
        'today'    => fvr_today(),
        'site'     => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        'statuses' => fvr_status_labels(),
        'icsUrl'   => $pilot ? fvr_pilot_ics_url($pilot) : ($token ? fvr_ics_url() : ''),
        'editUrl'  => $canEdit ? admin_url('admin.php?page=fvr-edit&id=') : '',
    ];
}

function fvr_calendar_container(): string
{
    return '<div id="fvr-calendar" class="fvr-cal"><p class="fvr-cal-loading">Chargement du calendrier…</p></div>';
}

// Page autonome (hors thème) : planning des pilotes, ou calendrier modifiable si l'administrateur est connecté
add_action('template_redirect', function () {
    if (isset($_GET['fvr_ics'])) {
        fvr_output_ics((string) wp_unslash($_GET['fvr_ics']));
    }
    if (!isset($_GET['fvr_planning'])) {
        return;
    }
    $token = (string) wp_unslash($_GET['fvr_planning']);
    $canEdit = current_user_can(fvr_cap());
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $ctx = fvr_token_context($token);
    if (!$canEdit && !$ctx) {
        status_header(403);
        wp_die('Ce lien de planning n\'est pas (ou plus) valide. Demandez le nouveau lien à l\'administrateur.', 'Lien invalide', ['response' => 403]);
    }
    $pilot = $ctx['pilot'] ?? null;
    $config = fvr_calendar_config($canEdit, $ctx ? $token : '', $pilot);
    $site = get_bloginfo('name');
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Planning">
<title><?php echo esc_html($pilot ? 'Planning de ' . $pilot['name'] : 'Planning · ' . $site); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(FVR_URL . 'assets/calendar.css?ver=' . FVR_VERSION); ?>">
</head>
<body class="fvr-cal-page">
<?php echo fvr_calendar_container(); ?>
<script>window.fvrCalendar = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url(FVR_URL . 'assets/calendar.js?ver=' . FVR_VERSION); ?>"></script>
</body>
</html>
<?php
    exit;
});

// Page « Calendrier » dans l'administration WordPress
add_action('admin_menu', function () {
    add_submenu_page('fvr', 'Calendrier des réservations', 'Calendrier', fvr_cap(), 'fvr-calendar', function () {
        echo '<div class="wrap"><h1 class="wp-heading-inline">Calendrier</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(fvr_planning_url()) . '" target="_blank">Ouvrir en plein écran ↗</a>';
        echo '<hr class="wp-header-end">';
        echo fvr_calendar_container();
        echo '</div>';
    }, 1);
}, 20);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'fvr-calendar') === false) {
        return;
    }
    wp_enqueue_style('fvr-calendar', FVR_URL . 'assets/calendar.css', [], FVR_VERSION);
    wp_enqueue_script('fvr-calendar', FVR_URL . 'assets/calendar.js', [], FVR_VERSION, true);
    wp_add_inline_script('fvr-calendar', 'window.fvrCalendar = ' . wp_json_encode(fvr_calendar_config(true, fvr_planning_token())) . ';', 'before');
});

// ---------- Abonnement agenda (.ics) ----------

function fvr_ics_escape(string $s): string
{
    return str_replace(["\\", "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\\;'], $s);
}

function fvr_output_ics(string $token): void
{
    global $wpdb;
    $ctx = fvr_token_context($token);
    if (!$ctx) {
        status_header(403);
        exit('Lien invalide');
    }
    $pilot = $ctx['pilot'];
    $from = gmdate('Y-m-d', strtotime(fvr_today() . ' -30 days'));
    if ($pilot) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT b.* FROM ' . fvr_table('bookings') . ' b JOIN ' . fvr_table('assign')
            . " a ON a.booking_id = b.id WHERE a.pilot_id = %d AND b.date >= %s AND b.status <> 'cancelled' ORDER BY b.date, b.time",
            $pilot['id'], $from), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('bookings')
            . " WHERE date >= %s AND status <> 'cancelled' ORDER BY date, time", $from), ARRAY_A);
    }
    $assign = fvr_assignments($rows);
    $pilotsById = [];
    foreach (fvr_pilots() as $p) {
        $pilotsById[$p['id']] = $p;
    }
    $tz = wp_timezone();
    $utc = new DateTimeZone('UTC');
    $labels = fvr_status_labels();
    $host = wp_parse_url(home_url(), PHP_URL_HOST);

    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Reservation Parapente//FR', 'CALSCALE:GREGORIAN',
              'METHOD:PUBLISH', 'X-WR-CALNAME:' . fvr_ics_escape($pilot ? 'Mes vols – ' . get_bloginfo('name') : get_bloginfo('name') . ' – Vols'),
              'REFRESH-INTERVAL;VALUE=DURATION:PT30M', 'X-PUBLISHED-TTL:PT30M'];
    foreach ($rows as $b) {
        $start = new DateTime($b['date'] . ' ' . $b['time'], $tz);
        $end = (clone $start)->modify('+1 hour');
        $summary = $b['name'] . ' (' . (int) $b['passengers'] . ' pax) – ' . $b['flight_name']
            . ($b['status'] === 'pending' ? ' [à confirmer]' : '');
        $desc = "Réf. {$b['reference']}\nStatut : " . ($labels[$b['status']] ?? $b['status'])
            . "\nTéléphone : {$b['phone']}"
            . ($b['weights'] ? "\nPoids : {$b['weights']}" : '')
            . ($b['message'] ? "\nMessage : {$b['message']}" : '')
            . "\nPilotes : " . implode(', ', fvr_pilot_names($assign[(int) $b['id']] ?? [], $pilotsById))
            . ($b['admin_notes'] ? "\nNotes : {$b['admin_notes']}" : '');
        array_push($lines,
            'BEGIN:VEVENT',
            'UID:fvr-' . (int) $b['id'] . '@' . $host,
            'DTSTAMP:' . (new DateTime($b['updated_at'], $tz))->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . fvr_ics_escape($summary),
            'DESCRIPTION:' . fvr_ics_escape($desc),
            'STATUS:' . ($b['status'] === 'pending' ? 'TENTATIVE' : 'CONFIRMED'),
            'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="planning.ics"');
    // Lignes limitées à 75 octets (norme iCalendar)
    foreach ($lines as $line) {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $limit = 74;
        }
        echo $out . $line . "\r\n";
    }
    exit;
}
