<?php
/**
 * Suppression complète des données lors de la désinstallation du plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cb_bookings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'cb_settings' );
delete_option( 'cb_db_version' );
delete_option( 'cb_ical_last_sync' );
delete_option( 'cb_email_settings' );
delete_option( 'cb_email_log' );
delete_option( 'cb_content' );
wp_clear_scheduled_hook( 'cb_ical_sync' );
