<?php
// Notifications sur le téléphone des pilotes (Web Push, sans service payant).
//
// Le pilote active les notifications depuis son planning (lien personnel). Il est prévenu quand un vol
// lui est attribué, retiré, déplacé ou annulé. Chiffrement standard RFC 8291 (aes128gcm) et
// authentification VAPID (RFC 8292), réalisés avec OpenSSL — aucune bibliothèque externe.

if (!defined('ABSPATH')) {
    exit;
}

function fvr_b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function fvr_b64u_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

// Point public P-256 non compressé (0x04 || X || Y) d'une clé OpenSSL
function fvr_ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

// Clé publique OpenSSL à partir d'un point P-256 brut (65 octets)
function fvr_ec_public_key(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

function fvr_new_ec_key()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

// Clés VAPID du site, créées une seule fois
function fvr_vapid(): array
{
    $v = get_option('fvr_vapid');
    if (is_array($v) && !empty($v['private']) && !empty($v['public'])) {
        return $v;
    }
    $key = fvr_new_ec_key();
    if (!$key) {
        return [];
    }
    openssl_pkey_export($key, $pem);
    $v = ['private' => $pem, 'public' => fvr_b64u_encode(fvr_ec_public_raw($key))];
    update_option('fvr_vapid', $v, false);
    return $v;
}

function fvr_push_available(): bool
{
    return function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && (bool) fvr_vapid();
}

// Signature ECDSA DER → format JWS (R || S, 64 octets)
function fvr_der_to_jose(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function fvr_vapid_header(string $endpoint): string
{
    $v = fvr_vapid();
    $parts = wp_parse_url($endpoint);
    $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $sub = fvr_settings()['admin_email'] ?: get_option('admin_email');
    $jwt = fvr_b64u_encode(wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
        . fvr_b64u_encode(wp_json_encode(['aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => 'mailto:' . $sub]));
    openssl_sign($jwt, $der, $v['private'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $jwt . '.' . fvr_b64u_encode(fvr_der_to_jose($der)) . ', k=' . $v['public'];
}

// Chiffrement du message (RFC 8291, aes128gcm)
function fvr_push_encrypt(string $payload, string $uaPublicB64, string $authB64): ?string
{
    $uaPublic = fvr_b64u_decode($uaPublicB64);
    $auth = fvr_b64u_decode($authB64);
    if (strlen($uaPublic) !== 65 || strlen($auth) < 16) {
        return null;
    }
    $eph = fvr_new_ec_key();
    $uaKey = fvr_ec_public_key($uaPublic);
    if (!$eph || !$uaKey) {
        return null;
    }
    $asPublic = fvr_ec_public_raw($eph);
    $shared = openssl_pkey_derive($uaKey, $eph, 32);
    if ($shared === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Envoie une notification à tous les appareils d'un pilote. Retourne le nombre d'envois réussis.
 */
function fvr_push_to_pilot(int $pilotId, array $message): int
{
    global $wpdb;
    if (!fvr_push_available()) {
        return 0;
    }
    $subs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('push') . ' WHERE pilot_id = %d', $pilotId), ARRAY_A);
    $ok = 0;
    foreach ($subs as $sub) {
        $body = fvr_push_encrypt(wp_json_encode($message), $sub['p256dh'], $sub['auth']);
        if ($body === null) {
            continue;
        }
        $res = wp_remote_post($sub['endpoint'], [
            'timeout' => 8,
            'headers' => [
                'Authorization'    => fvr_vapid_header($sub['endpoint']),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type'     => 'application/octet-stream',
                'TTL'              => (string) DAY_IN_SECONDS,
                'Urgency'          => 'high',
            ],
            'body' => $body,
        ]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 300) {
            $ok++;
            $wpdb->update(fvr_table('push'), ['last_ok' => fvr_now()], ['id' => $sub['id']]);
        } elseif (in_array($code, [404, 410], true)) {
            // Abonnement expiré (notifications désactivées, appli supprimée…)
            $wpdb->delete(fvr_table('push'), ['id' => $sub['id']]);
        }
    }
    return $ok;
}

// ---------- Détection des changements sur les vols des pilotes ----------

function fvr_booking_snapshot(int $id): ?array
{
    global $wpdb;
    $b = $wpdb->get_row($wpdb->prepare('SELECT id, date, time, status, name, passengers, flight_name FROM ' . fvr_table('bookings')
        . ' WHERE id = %d', $id), ARRAY_A);
    if (!$b) {
        return null;
    }
    $b['pilots'] = array_values(array_filter(fvr_assignments([$b])[$id] ?? []));
    return $b;
}

function fvr_short_date(string $date): string
{
    $days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $t = strtotime($date . ' 12:00:00');
    return $days[(int) gmdate('w', $t)] . ' ' . (int) gmdate('j', $t) . ' ' . $months[(int) gmdate('n', $t) - 1];
}

$GLOBALS['fvr_push_queue'] = [];

/**
 * Compare l'état d'une réservation avant / après et prépare les notifications des pilotes concernés.
 */
function fvr_notify_changes(?array $before, ?array $after): void
{
    $ref = $after ?: $before;
    if (!$ref) {
        return;
    }
    $was = $before && $before['status'] !== 'cancelled' ? $before['pilots'] : [];
    $now = $after && $after['status'] !== 'cancelled' ? $after['pilots'] : [];
    $label = function ($b) {
        return fvr_short_date($b['date']) . ' à ' . $b['time'] . ' · ' . $b['name'] . ' (' . (int) $b['passengers'] . ' pax)';
    };
    foreach (array_unique(array_merge($was, $now)) as $pid) {
        $in = in_array($pid, $now, true);
        $had = in_array($pid, $was, true);
        $msg = null;
        if ($in && !$had) {
            $msg = ['title' => '🪂 Nouveau vol pour toi', 'body' => $label($after), 'date' => $after['date']];
        } elseif (!$in && $had) {
            $cancelled = $after && $after['status'] === 'cancelled';
            $msg = ['title' => $after ? ($cancelled ? '❌ Vol annulé' : '↩️ Vol retiré de ton planning') : '❌ Vol supprimé',
                    'body' => $label($before), 'date' => $before['date']];
        } elseif ($before['date'] !== $after['date'] || $before['time'] !== $after['time']) {
            $msg = ['title' => '🕑 Vol déplacé', 'body' => $after['name'] . ' : ' . fvr_short_date($before['date']) . ' ' . $before['time']
                . ' → ' . fvr_short_date($after['date']) . ' ' . $after['time'], 'date' => $after['date']];
        } elseif ((int) $before['passengers'] !== (int) $after['passengers'] || $before['flight_name'] !== $after['flight_name']) {
            $msg = ['title' => '✏️ Vol modifié', 'body' => $label($after) . ' · ' . $after['flight_name'], 'date' => $after['date']];
        }
        // Pas de notification pour les vols passés
        if ($msg && max($before['date'] ?? '', $after['date'] ?? '') >= fvr_today()) {
            $GLOBALS['fvr_push_queue'][] = [(int) $pid, $msg];
        }
    }
}

// Envoi groupé à la fin de la requête (après la réponse si le serveur le permet) : l'agenda reste rapide
add_action('shutdown', function () {
    global $wpdb;
    $queue = $GLOBALS['fvr_push_queue'] ?? [];
    if (!$queue) {
        return;
    }
    $GLOBALS['fvr_push_queue'] = [];
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $tokens = [];
    foreach ($queue as [$pid, $msg]) {
        if (!isset($tokens[$pid])) {
            $tokens[$pid] = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . fvr_table('pilots') . ' WHERE id = %d', $pid));
        }
        $msg['url'] = add_query_arg(['fvr_planning' => $tokens[$pid], 'd' => $msg['date']], home_url('/'));
        $msg['tag'] = 'fvr-' . md5($msg['title'] . $msg['body']);
        fvr_push_to_pilot($pid, $msg);
    }
});

// ---------- Service worker, manifeste et abonnement ----------

add_action('template_redirect', function () {
    if (isset($_GET['fvr_sw'])) {
        nocache_headers();
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        readfile(__DIR__ . '/../assets/sw.js');
        exit;
    }
    if (isset($_GET['fvr_manifest'])) {
        $token = (string) wp_unslash($_GET['fvr_manifest']);
        $ctx = fvr_token_context($token);
        if (!$ctx) {
            status_header(404);
            exit;
        }
        $name = !empty($ctx['pilot']) ? 'Planning ' . $ctx['pilot']['name'] : 'Planning';
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode([
            'name' => $name . ' · ' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'short_name' => $name,
            'start_url' => add_query_arg('fvr_planning', $token, home_url('/')),
            'scope' => home_url('/'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#0b57d0',
            'icons' => [['src' => FVR_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                        ['src' => FVR_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png']],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}, 5);

add_action('rest_api_init', function () {
    $pilotOnly = function (WP_REST_Request $req) { return fvr_pilot_from_request($req) !== null; };

    // Enregistre l'abonnement d'un appareil du pilote
    register_rest_route('fvr/v1', '/pilot/push', [
        'methods'             => 'POST',
        'permission_callback' => $pilotOnly,
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $pilot = fvr_pilot_from_request($req);
            $p = (array) $req->get_json_params();
            $endpoint = esc_url_raw((string) ($p['endpoint'] ?? ''), ['https']);
            $keys = (array) ($p['keys'] ?? []);
            if (!$endpoint || empty($keys['p256dh']) || empty($keys['auth'])) {
                return new WP_Error('invalid', 'Abonnement invalide.', ['status' => 422]);
            }
            $hash = hash('sha256', $endpoint);
            $wpdb->delete(fvr_table('push'), ['endpoint_hash' => $hash]);
            $wpdb->insert(fvr_table('push'), [
                'pilot_id' => $pilot['id'], 'endpoint' => $endpoint, 'endpoint_hash' => $hash,
                'p256dh' => sanitize_text_field($keys['p256dh']), 'auth' => sanitize_text_field($keys['auth']),
                'created_at' => fvr_now(),
            ]);
            return ['ok' => true];
        },
    ]);

    register_rest_route('fvr/v1', '/pilot/push', [
        'methods'             => 'DELETE',
        'permission_callback' => $pilotOnly,
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $pilot = fvr_pilot_from_request($req);
            $endpoint = (string) ($req->get_json_params()['endpoint'] ?? '');
            $wpdb->delete(fvr_table('push'), ['endpoint_hash' => hash('sha256', $endpoint), 'pilot_id' => $pilot['id']]);
            return ['ok' => true];
        },
    ]);

    // Notification de test envoyée au pilote lui-même
    register_rest_route('fvr/v1', '/pilot/push-test', [
        'methods'             => 'POST',
        'permission_callback' => $pilotOnly,
        'callback'            => function (WP_REST_Request $req) {
            $pilot = fvr_pilot_from_request($req);
            $sent = fvr_push_to_pilot($pilot['id'], [
                'title' => '🔔 Notifications activées', 'body' => 'Tu seras prévenu quand un vol t\'est attribué, déplacé ou annulé.',
                'url' => fvr_pilot_planning_url($pilot), 'tag' => 'fvr-test',
            ]);
            return $sent ? ['ok' => true, 'sent' => $sent] : new WP_Error('push', 'Envoi impossible pour le moment.', ['status' => 502]);
        },
    ]);
});

function fvr_push_count(int $pilotId): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . fvr_table('push') . ' WHERE pilot_id = %d', $pilotId));
}
