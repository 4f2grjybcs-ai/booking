<?php
// Créneaux ajoutés depuis l'agenda, en plus des horaires habituels :
//  - ponctuels : une date précise (date_from = date_to, sans jours de semaine)
//  - récurrents : certains jours de la semaine (1 = lundi … 7 = dimanche) entre deux dates (date_to facultative)
// Si l'heure existe déjà ce jour-là, les places s'ajoutent à celles du créneau habituel.

if (!defined('ABSPATH')) {
    exit;
}

function fvr_opening_row(array $o): array
{
    return [
        'id' => (int) $o['id'], 'time' => $o['time'], 'capacity' => (int) $o['capacity'],
        'from' => $o['date_from'], 'to' => $o['date_to'] ?: '', 'weekdays' => $o['weekdays'] ?: '',
        'note' => $o['note'] ?? '',
    ];
}

// Créneaux ajoutés qui touchent la période [from, to]
function fvr_openings_between(string $from, string $to): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('openings')
        . " WHERE date_from <= %s AND (date_to IS NULL OR date_to = '' OR date_to >= %s) ORDER BY time, date_from", $to, $from), ARRAY_A);
    return array_map('fvr_opening_row', $rows ?: []);
}

function fvr_opening_matches(array $o, string $date): bool
{
    if ($date < $o['from'] || ($o['to'] !== '' && $date > $o['to'])) {
        return false;
    }
    if ($o['weekdays'] === '') {
        return true;
    }
    $dow = (string) gmdate('N', strtotime($date . ' 12:00:00'));
    return in_array($dow, explode(',', $o['weekdays']), true);
}

/**
 * Horaires réservables d'un jour : [heure => capacité], horaires habituels + créneaux ajoutés.
 */
function fvr_slots_for_date(string $date): array
{
    global $wpdb;
    $out = [];
    foreach ($wpdb->get_results('SELECT time, capacity, valid_until FROM ' . fvr_table('slots') . ' WHERE active = 1', ARRAY_A) as $s) {
        if (empty($s['valid_until']) || $date <= $s['valid_until']) {
            $out[$s['time']] = (int) $s['capacity'];
        }
    }
    foreach (fvr_openings_between($date, $date) as $o) {
        if (fvr_opening_matches($o, $date)) {
            $out[$o['time']] = ($out[$o['time']] ?? 0) + $o['capacity'];
        }
    }
    ksort($out);
    return $out;
}

function fvr_save_opening(array $p, int $id = 0)
{
    global $wpdb;
    $time = (string) ($p['time'] ?? '');
    $capacity = (int) ($p['capacity'] ?? 0);
    $from = (string) ($p['from'] ?? '');
    $recurring = !empty($p['recurring']) && $p['recurring'] !== '0';
    $to = $recurring ? (string) ($p['to'] ?? '') : $from;
    $days = array_values(array_unique(array_filter(array_map('intval', (array) ($p['weekdays'] ?? [])), function ($d) {
        return $d >= 1 && $d <= 7;
    })));
    sort($days);
    if (!preg_match('/^\d{2}:\d{2}$/', $time) || $capacity < 1 || $capacity > 50) {
        return new WP_Error('invalid', 'Indiquez une heure et un nombre de places (1 à 50).', ['status' => 422]);
    }
    if (!fvr_valid_date($from) || ($to !== '' && (!fvr_valid_date($to) || $to < $from))) {
        return new WP_Error('invalid', 'Dates invalides.', ['status' => 422]);
    }
    if ($recurring && !$days) {
        return new WP_Error('invalid', 'Choisissez au moins un jour de la semaine.', ['status' => 422]);
    }
    $data = [
        'time' => $time, 'capacity' => $capacity, 'date_from' => $from, 'date_to' => $to !== '' ? $to : null,
        'weekdays' => $recurring ? implode(',', $days) : '', 'note' => sanitize_text_field($p['note'] ?? ''),
    ];
    if ($id) {
        $wpdb->update(fvr_table('openings'), $data, ['id' => $id]);
        return $id;
    }
    $data['created_at'] = fvr_now();
    if (!$wpdb->insert(fvr_table('openings'), $data)) {
        return new WP_Error('db', "Erreur lors de l'enregistrement.", ['status' => 500]);
    }
    return (int) $wpdb->insert_id;
}

// Tous les créneaux ajoutés encore actifs (pour la liste de gestion dans l'agenda)
function fvr_openings_current(): array
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('openings')
        . " WHERE date_to IS NULL OR date_to = '' OR date_to >= %s ORDER BY date_from, time", fvr_today()), ARRAY_A);
    return array_map('fvr_opening_row', $rows ?: []);
}

add_action('rest_api_init', function () {
    register_rest_route('fvr/v1', '/admin/openings', [
        'methods'             => 'GET',
        'permission_callback' => 'fvr_can_edit_calendar',
        'callback'            => function () {
            return ['openings' => fvr_openings_current()];
        },
    ]);
    register_rest_route('fvr/v1', '/admin/opening', [
        'methods'             => 'POST',
        'permission_callback' => 'fvr_can_edit_calendar',
        'callback'            => function (WP_REST_Request $req) {
            $p = (array) ($req->get_json_params() ?: $req->get_body_params());
            $saved = fvr_save_opening($p, (int) ($p['id'] ?? 0));
            return is_wp_error($saved) ? $saved : ['ok' => true, 'id' => $saved];
        },
    ]);
    register_rest_route('fvr/v1', '/admin/opening/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'permission_callback' => 'fvr_can_edit_calendar',
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $wpdb->delete(fvr_table('openings'), ['id' => (int) $req['id']]);
            return ['ok' => true];
        },
    ]);
});
