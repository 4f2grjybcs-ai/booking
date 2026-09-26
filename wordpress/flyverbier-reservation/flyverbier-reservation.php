<?php
/**
 * Plugin Name:       Réservation Parapente
 * Description:       Réservation en ligne de vols biplace : formulaire client (shortcode [reservation_parapente]) et back office dans l'administration WordPress.
 * Version:           1.10.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Fly Verbier
 * Text Domain:       fvr
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FVR_VERSION', '1.10.0');
define('FVR_FILE', __FILE__);
define('FVR_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/install.php';
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/pilots.php';
require_once __DIR__ . '/includes/events.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/shortcode.php';
require_once __DIR__ . '/includes/calendar.php';
if (is_admin()) {
    require_once __DIR__ . '/includes/admin.php';
}

register_activation_hook(__FILE__, 'fvr_install');
add_action('plugins_loaded', 'fvr_maybe_upgrade');
