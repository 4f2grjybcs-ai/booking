<?php
// Pilotes : profils, lien personnel, attribution des pilotes aux réservations.
//
// Chaque passager vole avec un pilote : une réservation de 3 passagers a 3 places (sièges) à attribuer.
// Les pilotes ayant un « ordre par défaut » (1, 2, …) sont proposés automatiquement dans cet ordre,
// en sautant ceux qui volent déjà à la même heure. Les places restantes sont « à définir ».

if (!defined('ABSPATH')) {
    exit;
}

function fvr_pilots(bool $activeOnly = false): array
{
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . fvr_table('pilots') . ($activeOnly ? ' WHERE active = 1' : '')
        . ' ORDER BY active DESC, (default_rank = 0), default_rank, name', ARRAY_A);
    return array_map(function ($p) {
        $p['id'] = (int) $p['id'];
        $p['default_rank'] = (int) $p['default_rank'];
        $p['active'] = (int) $p['active'];
        return $p;
    }, $rows ?: []);
}

function fvr_pilot_by_token($token): ?array
{
    global $wpdb;
    if (!is_string($token) || strlen($token) < 20) {
        return null;
    }
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . fvr_table('pilots') . ' WHERE token = %s AND active = 1', $token), ARRAY_A);
    if (!$p || !hash_equals($p['token'], $token)) {
        return null;
    }
    $p['id'] = (int) $p['id'];
    return $p;
}

function fvr_new_pilot_token(): string
{
    return wp_generate_password(32, false, false);
}

function fvr_pilot_planning_url(array $pilot): string
{
    return add_query_arg('fvr_planning', $pilot['token'], home_url('/'));
}

function fvr_pilot_ics_url(array $pilot): string
{
    return add_query_arg('fvr_ics', $pilot['token'], home_url('/'));
}

/**
 * Attributions pour plusieurs réservations : [booking_id => [pilot_id|0 par siège, …]]
 */
function fvr_assignments(array $bookings): array
{
    global $wpdb;
    $ids = array_map(function ($b) { return (int) $b['id']; }, $bookings);
    $out = [];
    foreach ($bookings as $b) {
        $out[(int) $b['id']] = array_fill(0, max(1, (int) $b['passengers']), 0);
    }
    if (!$ids) {
        return $out;
    }
    $rows = $wpdb->get_results('SELECT booking_id, seat, pilot_id FROM ' . fvr_table('assign')
        . ' WHERE booking_id IN (' . implode(',', $ids) . ')', ARRAY_A);
    foreach ($rows as $r) {
        $bid = (int) $r['booking_id'];
        $seat = (int) $r['seat'];
        if (isset($out[$bid]) && $seat >= 1 && $seat <= count($out[$bid])) {
            $out[$bid][$seat - 1] = (int) $r['pilot_id'];
        }
    }
    return $out;
}

// Pilotes déjà occupés à cette date et heure (hors réservations annulées et hors réservation en cours)
function fvr_busy_pilots(string $date, string $time, int $excludeBooking = 0): array
{
    global $wpdb;
    $busy = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT a.pilot_id FROM ' . fvr_table('assign') . ' a JOIN ' . fvr_table('bookings') . ' b ON b.id = a.booking_id'
        . " WHERE b.date = %s AND b.time = %s AND b.status <> 'cancelled' AND b.id <> %d", $date, $time, $excludeBooking)));
    // Les pilotes absents (absence déclarée) ne sont pas proposés
    return array_values(array_unique(array_merge($busy, fvr_absent_pilots($date, $time))));
}

/**
 * Complète les sièges « auto » avec les pilotes par défaut disponibles.
 * $seats : liste de valeurs par siège — id de pilote, 0 (à définir) ou 'auto'.
 */
function fvr_resolve_seats(array $seats, int $pax, string $date, string $time, int $bookingId = 0): array
{
    $pax = max(1, $pax);
    $seats = array_slice(array_values($seats), 0, $pax);
    while (count($seats) < $pax) {
        $seats[] = 'auto';
    }
    $valid = [];
    foreach (fvr_pilots() as $p) {
        $valid[$p['id']] = $p;
    }
    $used = [];
    foreach ($seats as $i => $v) {
        if ($v !== 'auto') {
            $id = (int) $v;
            // Pilote inconnu ou déjà utilisé sur une autre place de cette réservation : à définir
            $seats[$i] = ($id && isset($valid[$id]) && !in_array($id, $used, true)) ? $id : 0;
            if ($seats[$i]) {
                $used[] = $seats[$i];
            }
        }
    }
    $busy = fvr_busy_pilots($date, $time, $bookingId);
    $defaults = array_filter($valid, function ($p) { return $p['active'] && $p['default_rank'] > 0; });
    uasort($defaults, function ($a, $b) { return $a['default_rank'] - $b['default_rank']; });
    foreach ($seats as $i => $v) {
        if ($v !== 'auto') {
            continue;
        }
        $seats[$i] = 0;
        foreach ($defaults as $p) {
            if (!in_array($p['id'], $used, true) && !in_array($p['id'], $busy, true)) {
                $seats[$i] = $p['id'];
                $used[] = $p['id'];
                break;
            }
        }
    }
    return array_map('intval', $seats);
}

function fvr_set_assignments(int $bookingId, array $seats): void
{
    global $wpdb;
    $wpdb->delete(fvr_table('assign'), ['booking_id' => $bookingId]);
    foreach (array_values($seats) as $i => $pilotId) {
        if ((int) $pilotId > 0) {
            $wpdb->insert(fvr_table('assign'), ['booking_id' => $bookingId, 'seat' => $i + 1, 'pilot_id' => (int) $pilotId]);
        }
    }
}

// Met à jour les pilotes d'une réservation après création / modification
function fvr_update_booking_pilots(int $bookingId, $requested, bool $isNew): void
{
    global $wpdb;
    $b = $wpdb->get_row($wpdb->prepare('SELECT id, date, time, passengers FROM ' . fvr_table('bookings') . ' WHERE id = %d', $bookingId), ARRAY_A);
    if (!$b) {
        return;
    }
    if (is_string($requested)) {
        $requested = $requested === '' ? [] : explode(',', $requested);
    }
    if (is_array($requested)) {
        $seats = array_map(function ($v) { return $v === 'auto' ? 'auto' : (int) $v; }, $requested);
    } elseif ($isNew) {
        $seats = [];  // tout en automatique
    } else {
        // Pas de changement demandé : on garde les pilotes actuels (ajusté au nombre de passagers)
        $seats = fvr_assignments([$b])[$bookingId];
        $seats = array_map(function ($v) { return $v ?: 0; }, $seats);
        if (count($seats) < (int) $b['passengers']) {
            $seats = array_merge($seats, array_fill(0, (int) $b['passengers'] - count($seats), 0));
        }
    }
    fvr_set_assignments($bookingId, fvr_resolve_seats($seats, (int) $b['passengers'], $b['date'], $b['time'], $bookingId));
}

function fvr_delete_booking(int $id): void
{
    global $wpdb;
    $before = fvr_booking_snapshot($id);
    $wpdb->delete(fvr_table('assign'), ['booking_id' => $id]);
    $wpdb->delete(fvr_table('bookings'), ['id' => $id]);
    fvr_notify_changes($before, null);
}

function fvr_pilot_names(array $seats, array $pilotsById): array
{
    return array_map(function ($id) use ($pilotsById) {
        return $id && isset($pilotsById[$id]) ? $pilotsById[$id]['name'] : 'à définir';
    }, $seats);
}
