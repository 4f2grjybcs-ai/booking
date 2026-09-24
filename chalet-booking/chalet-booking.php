<?php
/**
 * Plugin Name:       Chalet Booking
 * Description:       Réservation en ligne pour un chalet de location de vacances (calendrier de disponibilités, tarifs par saison, demandes de réservation, synchronisation iCal).
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Chalet Verbier
 * License:           GPL-2.0-or-later
 * Text Domain:       chalet-booking
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CB_VERSION', '1.3.0' );
define( 'CB_FILE', __FILE__ );
define( 'CB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_URL', plugin_dir_url( __FILE__ ) );

require_once CB_DIR . 'includes/class-cb-db.php';
require_once CB_DIR . 'includes/class-cb-settings.php';
require_once CB_DIR . 'includes/class-cb-availability.php';
require_once CB_DIR . 'includes/class-cb-pricing.php';
require_once CB_DIR . 'includes/class-cb-emails.php';
require_once CB_DIR . 'includes/class-cb-content.php';
require_once CB_DIR . 'includes/class-cb-description.php';
require_once CB_DIR . 'includes/class-cb-i18n.php';
require_once CB_DIR . 'includes/class-cb-ical.php';
require_once CB_DIR . 'includes/class-cb-rest.php';
require_once CB_DIR . 'includes/class-cb-shortcode.php';
require_once CB_DIR . 'includes/class-cb-admin.php';
require_once CB_DIR . 'includes/class-cb-backoffice.php';
require_once CB_DIR . 'includes/class-cb-manage-api.php';

register_activation_hook( __FILE__, array( 'CB_DB', 'install' ) );
register_activation_hook( __FILE__, array( 'CB_ICal', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'CB_ICal', 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'chalet-booking', false, dirname( plugin_basename( CB_FILE ) ) . '/languages' );
		CB_DB::maybe_upgrade();
		CB_Settings::init();
		CB_Emails::init();
		CB_Content::init();
		CB_Description::init();
		CB_I18n::init();
		CB_ICal::init();
		CB_REST::init();
		CB_Shortcode::init();
		CB_Admin::init();
		CB_Backoffice::init();
		CB_Manage_API::init();
	}
);
