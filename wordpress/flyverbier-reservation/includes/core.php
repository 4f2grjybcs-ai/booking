<?php
// Logique commune : réglages, disponibilités, création des réservations, e-mails.

if (!defined('ABSPATH')) {
    exit;
}

function fvr_cap(): string
{
    // Rôle requis pour gérer les réservations (administrateur par défaut)
    return apply_filters('fvr_capability', 'manage_options');
}

function fvr_default_settings(): array
{
    return [
        'admin_email'       => get_option('admin_email'),
        'send_client_email' => 1,
        'ref_prefix'        => 'FV',
        'max_days_ahead'    => 365,
        'client_message'    => "Merci pour votre demande de réservation. Nous vous contacterons pour confirmer votre vol selon les conditions météo.",
    ];
}

function fvr_settings(): array
{
    return array_merge(fvr_default_settings(), (array) get_option('fvr_settings', []));
}

function fvr_status_labels(): array
{
    return [
        'pending'   => 'En attente',
        'confirmed' => 'Confirmée',
        'done'      => 'Effectuée',
        'cancelled' => 'Annulée',
    ];
}

function fvr_valid_date($d): bool
{
    if (!is_string($d)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function fvr_today(): string
{
    return current_time('Y-m-d');
}

function fvr_now(): string
{
    return current_time('mysql');
}

function fvr_active_flights(): array
{
    global $wpdb;
    return $wpdb->get_results('SELECT id, name, description, duration, price FROM ' . fvr_table('flights')
        . ' WHERE active = 1 ORDER BY sort_order, id', ARRAY_A);
}

/**
 * Places restantes par créneau pour une date. [] si la date est fermée ou hors période.
 */
function fvr_availability(string $date): array
{
    global $wpdb;
    $today = fvr_today();
    $max = wp_date('Y-m-d', strtotime('+' . (int) fvr_settings()['max_days_ahead'] . ' days'));
    if ($date < $today || $date > $max) {
        return [];
    }
    if ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . fvr_table('blocked') . ' WHERE date = %s', $date))) {
        return [];
    }

    $taken = [];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT time, SUM(passengers) AS taken FROM ' . fvr_table('bookings')
        . " WHERE date = %s AND status <> 'cancelled' GROUP BY time", $date), ARRAY_A);
    foreach ($rows as $r) {
        $taken[$r['time']] = (int) $r['taken'];
    }

    $nowTime = current_time('H:i');
    $out = [];
    foreach ($wpdb->get_results('SELECT time, capacity FROM ' . fvr_table('slots') . ' WHERE active = 1 ORDER BY time', ARRAY_A) as $s) {
        if ($date === $today && $s['time'] <= $nowTime) {
            continue;
        }
        $out[] = ['time' => $s['time'], 'remaining' => max(0, (int) $s['capacity'] - ($taken[$s['time']] ?? 0))];
    }
    return $out;
}

function fvr_generate_reference(): string
{
    global $wpdb;
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper(fvr_settings()['ref_prefix'])) ?: 'R';
    do {
        $ref = $prefix . '-';
        for ($i = 0; $i < 6; $i++) {
            $ref .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while ($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . fvr_table('bookings') . ' WHERE reference = %s', $ref)));
    return $ref;
}

// Verrou MySQL pour éviter la surréservation si deux clients réservent le même créneau en même temps
function fvr_lock(bool $acquire): void
{
    global $wpdb;
    $suppress = $wpdb->suppress_errors(true);
    $wpdb->query($acquire ? "SELECT GET_LOCK('fvr_booking', 10)" : "SELECT RELEASE_LOCK('fvr_booking')");
    $wpdb->suppress_errors($suppress);
}

/**
 * Enregistre une réservation client. Retourne ['reference' => ..., 'total' => ...] ou WP_Error.
 */
function fvr_create_booking(array $in)
{
    global $wpdb;

    $date    = trim((string) ($in['date'] ?? ''));
    $time    = trim((string) ($in['time'] ?? ''));
    $pax     = (int) ($in['passengers'] ?? 0);
    $name    = sanitize_text_field($in['name'] ?? '');
    $email   = sanitize_email($in['email'] ?? '');
    $phone   = sanitize_text_field($in['phone'] ?? '');
    $weights = mb_substr(sanitize_text_field($in['weights'] ?? ''), 0, 200);
    $message = mb_substr(sanitize_textarea_field($in['message'] ?? ''), 0, 2000);

    $errors = [];
    if (!fvr_valid_date($date)) $errors[] = 'Date invalide.';
    if ($pax < 1 || $pax > 20) $errors[] = 'Nombre de passagers invalide.';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Nom requis.';
    if (!is_email($email)) $errors[] = 'E-mail invalide.';
    if (!preg_match('/^[0-9 +().\-]{6,25}$/', $phone)) $errors[] = 'Téléphone invalide.';
    if (empty($in['accept'])) $errors[] = 'Veuillez accepter les conditions.';

    $flight = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . fvr_table('flights') . ' WHERE id = %d AND active = 1',
        (int) ($in['flight_id'] ?? 0)), ARRAY_A);
    if (!$flight) $errors[] = 'Type de vol invalide.';

    if ($errors) {
        return new WP_Error('invalid', implode(' ', $errors), ['status' => 422]);
    }

    fvr_lock(true);
    $slot = null;
    foreach (fvr_availability($date) as $s) {
        if ($s['time'] === $time) {
            $slot = $s;
        }
    }
    if (!$slot) {
        fvr_lock(false);
        return new WP_Error('unavailable', "Ce créneau n'est pas disponible.", ['status' => 409]);
    }
    if ($slot['remaining'] < $pax) {
        fvr_lock(false);
        return new WP_Error('full', "Il ne reste que {$slot['remaining']} place(s) sur ce créneau.", ['status' => 409]);
    }

    $ref = fvr_generate_reference();
    $total = (float) $flight['price'] * $pax;
    $ok = $wpdb->insert(fvr_table('bookings'), [
        'reference' => $ref, 'date' => $date, 'time' => $time, 'flight_id' => $flight['id'],
        'flight_name' => $flight['name'], 'price' => $total, 'passengers' => $pax, 'name' => $name,
        'email' => $email, 'phone' => $phone, 'weights' => $weights, 'message' => $message,
        'status' => 'pending', 'admin_notes' => '', 'created_at' => fvr_now(), 'updated_at' => fvr_now(),
    ]);
    $bookingId = (int) $wpdb->insert_id;
    if ($ok) {
        fvr_update_booking_pilots($bookingId, null, true);
    }
    fvr_lock(false);
    if (!$ok) {
        return new WP_Error('db', 'Erreur serveur, veuillez réessayer.', ['status' => 500]);
    }

    fvr_send_emails($ref, $flight['name'], $date, $time, $pax, $total, $name, $email, $phone, $weights, $message);
    return ['reference' => $ref, 'total' => $total];
}

/**
 * Création / modification d'une réservation par l'administrateur (back office et calendrier).
 * Pas de contrôle de capacité : l'administrateur peut surbooker volontairement.
 * Retourne l'id de la réservation ou WP_Error.
 */
function fvr_save_booking(array $p, int $id = 0)
{
    global $wpdb;
    $status = (string) ($p['status'] ?? '');
    if (!fvr_valid_date($p['date'] ?? null) || !preg_match('/^\d{2}:\d{2}$/', (string) ($p['time'] ?? ''))
        || trim((string) ($p['name'] ?? '')) === '' || (int) ($p['passengers'] ?? 0) < 1
        || !isset(fvr_status_labels()[$status])) {
        return new WP_Error('invalid', 'Veuillez remplir date, heure, nom et passagers.', ['status' => 422]);
    }
    $existing = null;
    if ($id) {
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . fvr_table('bookings') . ' WHERE id = %d', $id), ARRAY_A);
        if (!$existing) {
            return new WP_Error('not_found', 'Réservation introuvable.', ['status' => 404]);
        }
    }
    $flightId = (int) ($p['flight_id'] ?? 0);
    $flightName = $flightId ? $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . fvr_table('flights') . ' WHERE id = %d', $flightId)) : null;
    $data = [
        'date' => $p['date'], 'time' => $p['time'],
        'flight_id' => $flightName ? $flightId : null,
        'flight_name' => $flightName ?: ($existing['flight_name'] ?? sanitize_text_field($p['flight_name'] ?? 'Vol')),
        'price' => (float) ($p['price'] ?? 0), 'passengers' => (int) $p['passengers'],
        'name' => sanitize_text_field($p['name']), 'email' => sanitize_email($p['email'] ?? ''),
        'phone' => sanitize_text_field($p['phone'] ?? ''), 'weights' => sanitize_text_field($p['weights'] ?? ''),
        'message' => sanitize_textarea_field($p['message'] ?? ''), 'status' => $status,
        'admin_notes' => sanitize_textarea_field($p['admin_notes'] ?? ''), 'updated_at' => fvr_now(),
    ];
    if ($id) {
        $wpdb->update(fvr_table('bookings'), $data, ['id' => $id]);
        fvr_update_booking_pilots($id, $p['pilots'] ?? null, false);
        return $id;
    }
    $data['reference'] = fvr_generate_reference();
    $data['created_at'] = fvr_now();
    if (!$wpdb->insert(fvr_table('bookings'), $data)) {
        return new WP_Error('db', "Erreur lors de l'enregistrement.", ['status' => 500]);
    }
    $id = (int) $wpdb->insert_id;
    fvr_update_booking_pilots($id, $p['pilots'] ?? null, true);
    return $id;
}

// Horaires proposés au choix : de 8h à 18h par quarts d'heure (+ l'heure actuelle si elle sort de cette plage)
function fvr_time_choices(string $current = ''): array
{
    $list = [];
    for ($m = 8 * 60; $m <= 18 * 60; $m += 15) {
        $list[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    if ($current !== '' && !in_array($current, $list, true)) {
        $list[] = $current;
        sort($list);
    }
    return $list;
}

function fvr_send_emails($ref, $flightName, $date, $time, $pax, $total, $name, $email, $phone, $weights, $message): void
{
    $s = fvr_settings();
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $summary = "Référence : $ref\n"
        . "Vol : $flightName\n"
        . 'Date : ' . fvr_format_date($date) . " à $time\n"
        . "Passagers : $pax\n"
        . 'Total : CHF ' . number_format($total, 2, '.', "'") . "\n"
        . "Nom : $name\nE-mail : $email\nTéléphone : $phone\n"
        . ($weights ? "Poids : $weights\n" : '')
        . ($message ? "\nMessage :\n$message\n" : '');

    if ($s['admin_email']) {
        wp_mail($s['admin_email'], "[$site] Nouvelle réservation $ref",
            "Nouvelle réservation :\n\n$summary\n" . admin_url('admin.php?page=fvr'),
            ['Reply-To: ' . $name . ' <' . $email . '>']);
    }
    if ($s['send_client_email']) {
        wp_mail($email, "$site - Demande de réservation $ref",
            "Bonjour $name,\n\n" . $s['client_message'] . "\n\n$summary\n$site");
    }
}

function fvr_format_date(string $date): string
{
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
               'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($date . ' 12:00:00');
    return $days[(int) gmdate('w', $t)] . ' ' . (int) gmdate('j', $t) . ' '
        . $months[(int) gmdate('n', $t) - 1] . ' ' . gmdate('Y', $t);
}
