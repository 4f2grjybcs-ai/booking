<?php
/**
 * Stockage des réservations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_DB {

	const DB_VERSION = '1';

	/** Statuts qui rendent les nuits indisponibles. */
	const BLOCKING_STATUSES = array( 'pending', 'confirmed', 'blocked' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'cb_bookings';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(20) NOT NULL DEFAULT 'pending',
				source varchar(20) NOT NULL DEFAULT 'site',
				check_in date NOT NULL,
				check_out date NOT NULL,
				adults smallint(5) unsigned NOT NULL DEFAULT 0,
				children smallint(5) unsigned NOT NULL DEFAULT 0,
				name varchar(190) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				message text NULL,
				total decimal(10,2) NOT NULL DEFAULT 0,
				price_details longtext NULL,
				external_uid varchar(255) NOT NULL DEFAULT '',
				feed varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY dates (check_in, check_out),
				KEY status (status),
				KEY feed (feed)
			) {$charset};"
		);

		update_option( 'cb_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'cb_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function insert( array $data ) {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'status'        => 'pending',
				'source'        => 'site',
				'adults'        => 0,
				'children'      => 0,
				'name'          => '',
				'email'         => '',
				'phone'         => '',
				'message'       => '',
				'total'         => 0,
				'price_details' => '',
				'external_uid'  => '',
				'feed'          => '',
				'created_at'    => current_time( 'mysql' ),
			)
		);
		if ( is_array( $data['price_details'] ) ) {
			$data['price_details'] = wp_json_encode( $data['price_details'] );
		}
		$ok = $wpdb->insert( self::table(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function update_status( $id, $status ) {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Réservations filtrées.
	 *
	 * @param array $args status (string|array), from, to (Y-m-d), exclude_source.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$statuses     = (array) $args['status'];
			$where[]      = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params       = array_merge( $params, $statuses );
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}
		if ( ! empty( $args['exclude_source'] ) ) {
			$where[]  = 'source <> %s';
			$params[] = $args['exclude_source'];
		}
		// Chevauchement avec [from, to).
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'check_out > %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'check_in < %s';
			$params[] = $args['to'];
		}

		$order = ( isset( $args['order'] ) && 'DESC' === $args['order'] ) ? 'DESC' : 'ASC';
		$sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY check_in {$order}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete_feed( $feed ) {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'source' => 'ical',
				'feed'   => $feed,
			)
		);
	}
}
