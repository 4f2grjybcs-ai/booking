<?php
// Événements de l'agenda (météo, compétition, pilote absent, groupe privé…).
// Un événement bloque les places encore libres des créneaux qu'il recouvre :
// toutes (blocks = 0) ou un nombre donné (ex. 2 pilotes absents). Les réservations existantes restent.

if (!defined('ABSPATH')) {
    exit;
}

define('FVR_SLOT_MINUTES', 60); // durée d'un créneau / d'un vol

function fvr_minutes(string $t): int
{
    $p = explode(':', $t);
    return (int) $p[0] * 60 + (int) ($p[1] ?? 0);
}

function fvr_events_between(string $from, string $to): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('events')
        . ' WHERE date BETWEEN %s AND %s ORDER BY date, all_day DESC, start_time', $from, $to), ARRAY_A);
    return array_map(function ($e) {
        return [
            'id' => (int) $e['id'], 'date' => $e['date'], 'all_day' => (int) $e['all_day'],
            'start' => $e['start_time'], 'end' => $e['end_time'], 'title' => $e['title'],
            'note' => $e['note'], 'blocks' => (int) $e['blocks'], 'pilot_id' => (int) ($e['pilot_id'] ?? 0),
        ];
    }, $rows ?: []);
}

/**
 * Nombre de places bloquées par les événements sur un créneau (PHP_INT_MAX = tout est bloqué).
 */
function fvr_event_blocked(array $events, string $date, string $time): int
{
    $start = fvr_minutes($time);
    $end = $start + FVR_SLOT_MINUTES;
    $blocked = 0;
    foreach ($events as $e) {
        if ($e['date'] !== $date) {
            continue;
        }
        if (!$e['all_day'] && !(fvr_minutes($e['start']) < $end && fvr_minutes($e['end']) > $start)) {
            continue;
        }
        if ($e['blocks'] <= 0) {
            return PHP_INT_MAX;
        }
        $blocked += $e['blocks'];
    }
    return $blocked;
}

function fvr_save_event(array $p, int $id = 0)
{
    global $wpdb;
    $allDay = !empty($p['all_day']) && $p['all_day'] !== '0' ? 1 : 0;
    $start = (string) ($p['start'] ?? '');
    $end = (string) ($p['end'] ?? '');
    $title = sanitize_text_field($p['title'] ?? '');
    if (!fvr_valid_date($p['date'] ?? null) || $title === '') {
        return new WP_Error('invalid', 'Indiquez un titre et une date.', ['status' => 422]);
    }
    if ($allDay) {
        $start = '00:00';
        $end = '23:59';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $end <= $start) {
        return new WP_Error('invalid', "L'heure de fin doit être après l'heure de début.", ['status' => 422]);
    }
    $data = [
        'date' => $p['date'], 'all_day' => $allDay, 'start_time' => $start, 'end_time' => $end, 'title' => $title,
        'note' => sanitize_textarea_field($p['note'] ?? ''), 'blocks' => max(0, (int) ($p['blocks'] ?? 0)),
        'pilot_id' => ((int) ($p['pilot_id'] ?? 0)) ?: null,
        'updated_at' => fvr_now(),
    ];
    if ($id) {
        $wpdb->update(fvr_table('events'), $data, ['id' => $id]);
        return $id;
    }
    $data['created_at'] = fvr_now();
    if (!$wpdb->insert(fvr_table('events'), $data)) {
        return new WP_Error('db', "Erreur lors de l'enregistrement.", ['status' => 500]);
    }
    return (int) $wpdb->insert_id;
}

add_action('rest_api_init', function () {
    register_rest_route('fvr/v1', '/admin/event', [
        'methods'             => 'POST',
        'permission_callback' => 'fvr_can_edit_calendar',
        'callback'            => function (WP_REST_Request $req) {
            $p = (array) ($req->get_json_params() ?: $req->get_body_params());
            $saved = fvr_save_event($p, (int) ($p['id'] ?? 0));
            return is_wp_error($saved) ? $saved : ['ok' => true, 'id' => $saved];
        },
    ]);
    register_rest_route('fvr/v1', '/admin/event/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'permission_callback' => 'fvr_can_edit_calendar',
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $wpdb->delete(fvr_table('events'), ['id' => (int) $req['id']]);
            return ['ok' => true];
        },
    ]);
});

// ---------- Absences déclarées par les pilotes ----------

// Pilotes absents sur un créneau (date + heure de début d'un vol)
function fvr_absent_pilots(string $date, string $time): array
{
    $out = [];
    $start = fvr_minutes($time);
    $end = $start + FVR_SLOT_MINUTES;
    foreach (fvr_events_between($date, $date) as $e) {
        if ($e['pilot_id'] && ($e['all_day'] || (fvr_minutes($e['start']) < $end && fvr_minutes($e['end']) > $start))) {
            $out[] = $e['pilot_id'];
        }
    }
    return $out;
}

// Vols auxquels le pilote est attribué pendant une absence
function fvr_absence_conflicts(int $pilotId, array $days, bool $allDay, string $start, string $end): array
{
    global $wpdb;
    if (!$days) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare('SELECT b.* FROM ' . fvr_table('bookings') . ' b JOIN ' . fvr_table('assign')
        . " a ON a.booking_id = b.id WHERE a.pilot_id = %d AND b.status <> 'cancelled' AND b.date BETWEEN %s AND %s ORDER BY b.date, b.time",
        $pilotId, min($days), max($days)), ARRAY_A);
    return array_values(array_filter($rows, function ($b) use ($days, $allDay, $start, $end) {
        if (!in_array($b['date'], $days, true)) {
            return false;
        }
        $s = fvr_minutes($b['time']);
        return $allDay || (fvr_minutes($start) < $s + FVR_SLOT_MINUTES && fvr_minutes($end) > $s);
    }));
}

function fvr_pilot_from_request(WP_REST_Request $req): ?array
{
    $ctx = fvr_token_context($req->get_param('token'));
    return $ctx['pilot'] ?? null;
}

add_action('rest_api_init', function () {
    register_rest_route('fvr/v1', '/pilot/absence', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) { return fvr_pilot_from_request($req) !== null; },
        'callback'            => function (WP_REST_Request $req) {
            $pilot = fvr_pilot_from_request($req);
            $p = (array) ($req->get_json_params() ?: $req->get_body_params());
            $from = (string) ($p['date'] ?? '');
            $to = (string) ($p['to'] ?? '') ?: $from;
            if (!fvr_valid_date($from) || !fvr_valid_date($to) || $to < $from) {
                return new WP_Error('invalid', 'Dates invalides.', ['status' => 422]);
            }
            if ($from < fvr_today()) {
                return new WP_Error('invalid', 'Impossible de déclarer une absence dans le passé.', ['status' => 422]);
            }
            $days = [];
            for ($d = $from; $d <= $to && count($days) < 92; $d = gmdate('Y-m-d', strtotime($d . ' 12:00:00 +1 day'))) {
                $days[] = $d;
            }
            if ($to > end($days)) {
                return new WP_Error('invalid', 'Absence limitée à 3 mois à la fois.', ['status' => 422]);
            }
            $allDay = !empty($p['all_day']) && $p['all_day'] !== '0';
            $note = sanitize_textarea_field($p['note'] ?? '');
            $ids = [];
            foreach ($days as $d) {
                $saved = fvr_save_event([
                    'date' => $d, 'all_day' => $allDay ? 1 : 0, 'start' => $p['start'] ?? '', 'end' => $p['end'] ?? '',
                    'title' => 'Absence · ' . $pilot['name'], 'note' => $note, 'blocks' => 1, 'pilot_id' => $pilot['id'],
                ]);
                if (is_wp_error($saved)) {
                    return $saved;
                }
                $ids[] = $saved;
            }
            $conflicts = fvr_absence_conflicts($pilot['id'], $days, $allDay, (string) ($p['start'] ?? ''), (string) ($p['end'] ?? ''));

            // Prévient l'administrateur
            $admin = fvr_settings()['admin_email'];
            if ($admin) {
                $when = ($from === $to ? fvr_format_date($from) : 'du ' . fvr_format_date($from) . ' au ' . fvr_format_date($to))
                    . ($allDay ? ' (journée entière)' : ' de ' . $p['start'] . ' à ' . $p['end']);
                $body = $pilot['name'] . " a déclaré une absence $when." . ($note ? "\nMotif : $note" : '');
                if ($conflicts) {
                    $body .= "\n\nATTENTION, " . $pilot['name'] . " est attribué à ces vols :\n" . implode("\n", array_map(function ($b) {
                        return '- ' . fvr_format_date($b['date']) . ' à ' . $b['time'] . ' : ' . $b['name'] . ' (' . $b['passengers'] . ' pax)';
                    }, $conflicts));
                }
                $body .= "\n\nAgenda : " . fvr_admin_planning_url();
                wp_mail($admin, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Absence de ' . $pilot['name'], $body);
            }
            return ['ok' => true, 'ids' => $ids, 'conflicts' => array_map(function ($b) {
                return ['date' => $b['date'], 'time' => $b['time'], 'name' => $b['name'], 'passengers' => (int) $b['passengers']];
            }, $conflicts)];
        },
    ]);

    register_rest_route('fvr/v1', '/pilot/absence/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'permission_callback' => function (WP_REST_Request $req) { return fvr_pilot_from_request($req) !== null; },
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $pilot = fvr_pilot_from_request($req);
            // Un pilote ne peut supprimer que ses propres absences
            $n = $wpdb->delete(fvr_table('events'), ['id' => (int) $req['id'], 'pilot_id' => $pilot['id']]);
            return $n ? ['ok' => true] : new WP_Error('forbidden', 'Absence introuvable.', ['status' => 404]);
        },
    ]);
});
