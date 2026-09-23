<?php
// API utilisée par le formulaire client : /wp-json/fvr/v1/...

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('fvr/v1', '/flights', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return ['flights' => fvr_active_flights()];
        },
    ]);

    register_rest_route('fvr/v1', '/availability', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            $date = (string) $req->get_param('date');
            if (!fvr_valid_date($date)) {
                return new WP_Error('invalid', 'Date invalide', ['status' => 400]);
            }
            $res = rest_ensure_response(['date' => $date, 'slots' => fvr_availability($date)]);
            $res->header('Cache-Control', 'no-store');
            return $res;
        },
    ]);

    register_rest_route('fvr/v1', '/book', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            $in = $req->get_json_params() ?: $req->get_body_params();
            // Champ piège anti-robots : doit rester vide
            if (!empty($in['website'])) {
                return new WP_Error('spam', 'Requête refusée', ['status' => 400]);
            }
            $r = fvr_create_booking((array) $in);
            if (is_wp_error($r)) {
                return $r;
            }
            return ['ok' => true] + $r;
        },
    ]);
});
