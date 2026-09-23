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
            'note' => $e['note'], 'blocks' => (int) $e['blocks'],
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
