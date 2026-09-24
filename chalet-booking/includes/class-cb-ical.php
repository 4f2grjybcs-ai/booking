<?php
/**
 * Synchronisation iCal (Airbnb, Booking.com, Google Agenda…).
 *
 * - Export : https://votre-site.ch/?chalet-booking-ical=JETON
 * - Import : URLs .ics renseignées dans les réglages, synchronisées toutes les heures.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_ICal {

	const HOOK = 'cb_ical_sync';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'maybe_serve_feed' ) );
		// Filet de sécurité si l'événement planifié a disparu.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function feed_url() {
		return add_query_arg( 'chalet-booking-ical', CB_Settings::get( 'ical_token' ), home_url( '/' ) );
	}

	public static function maybe_serve_feed() {
		if ( ! isset( $_GET['chalet-booking-ical'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['chalet-booking-ical'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! hash_equals( (string) CB_Settings::get( 'ical_token' ), $token ) ) {
			status_header( 403 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="chalet.ics"' );
		echo self::build_feed(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function build_feed() {
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$bookings = CB_DB::query(
			array(
				'status'         => CB_DB::BLOCKING_STATUSES,
				'exclude_source' => 'ical',
				'from'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			)
		);

		$out   = array();
		$out[] = 'BEGIN:VCALENDAR';
		$out[] = 'VERSION:2.0';
		$out[] = 'PRODID:-//Chalet Booking//WordPress//FR';
		$out[] = 'CALSCALE:GREGORIAN';
		$out[] = 'METHOD:PUBLISH';
		$out[] = 'X-WR-CALNAME:' . self::escape( CB_Settings::get( 'chalet_name' ) );
		foreach ( $bookings as $b ) {
			$out[] = 'BEGIN:VEVENT';
			$out[] = 'UID:cb-' . $b->id . '@' . $host;
			$out[] = 'DTSTAMP:' . get_gmt_from_date( $b->created_at, 'Ymd\THis\Z' );
			$out[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $b->check_in );
			$out[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', $b->check_out );
			// Pas de données personnelles dans le flux public.
			$out[] = 'SUMMARY:' . ( 'blocked' === $b->status ? 'Not available' : 'Reserved' );
			$out[] = 'END:VEVENT';
		}
		$out[] = 'END:VCALENDAR';

		return implode( "\r\n", $out ) . "\r\n";
	}

	private static function escape( $text ) {
		return addcslashes( (string) $text, ",;\\" );
	}

	/**
	 * Importe tous les calendriers externes. Retourne [url => nombre d'événements | message d'erreur].
	 */
	public static function sync() {
		$report = array();
		$urls   = array_filter( preg_split( '/\s+/', (string) CB_Settings::get( 'ical_import_urls' ) ) );
		$feeds  = array();

		foreach ( $urls as $url ) {
			$feed           = substr( md5( $url ), 0, 12 );
			$feeds[ $feed ] = true;
			$response       = wp_remote_get( $url, array( 'timeout' => 20 ) );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				// On garde les anciennes données plutôt que de libérer des dates par erreur.
				$report[ $url ] = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
				continue;
			}

			$events = self::parse( wp_remote_retrieve_body( $response ) );
			CB_DB::delete_feed( $feed );
			foreach ( $events as $event ) {
				CB_DB::insert(
					array(
						'status'       => 'blocked',
						'source'       => 'ical',
						'feed'         => $feed,
						'check_in'     => $event['start'],
						'check_out'    => $event['end'],
						'name'         => mb_substr( $event['summary'], 0, 190 ),
						'external_uid' => mb_substr( $event['uid'], 0, 255 ),
					)
				);
			}
			$report[ $url ] = count( $events );
		}

		// Supprime les données des calendriers retirés des réglages.
		global $wpdb;
		$table = CB_DB::table();
		foreach ( $wpdb->get_col( "SELECT DISTINCT feed FROM {$table} WHERE source = 'ical'" ) as $feed ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! isset( $feeds[ $feed ] ) ) {
				CB_DB::delete_feed( $feed );
			}
		}

		update_option(
			'cb_ical_last_sync',
			array(
				'time'   => time(),
				'report' => $report,
			),
			false
		);
		return $report;
	}

	/**
	 * Extrait les événements (dates) d'un fichier .ics.
	 */
	public static function parse( $ics ) {
		// Dépliage des lignes (RFC 5545 §3.1).
		$ics    = preg_replace( "/\r?\n[ \t]/", '', (string) $ics );
		$lines  = preg_split( "/\r?\n/", $ics );
		$events = array();
		$cur    = null;

		foreach ( $lines as $line ) {
			if ( 'BEGIN:VEVENT' === $line ) {
				$cur = array(
					'start'   => '',
					'end'     => '',
					'summary' => '',
					'uid'     => '',
					'status'  => '',
				);
				continue;
			}
			if ( 'END:VEVENT' === $line ) {
				if ( $cur && $cur['start'] && 'CANCELLED' !== $cur['status'] ) {
					if ( ! $cur['end'] || $cur['end'] <= $cur['start'] ) {
						$cur['end'] = gmdate( 'Y-m-d', strtotime( $cur['start'] . ' +1 day' ) );
					}
					unset( $cur['status'] );
					$events[] = $cur;
				}
				$cur = null;
				continue;
			}
			if ( null === $cur || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $prop, $value ) = explode( ':', $line, 2 );
			$name                  = strtoupper( strtok( $prop, ';' ) );
			switch ( $name ) {
				case 'DTSTART':
					$cur['start'] = self::parse_date( $value );
					break;
				case 'DTEND':
					$cur['end'] = self::parse_date( $value );
					break;
				case 'SUMMARY':
					$cur['summary'] = stripcslashes( $value );
					break;
				case 'UID':
					$cur['uid'] = $value;
					break;
				case 'STATUS':
					$cur['status'] = strtoupper( trim( $value ) );
					break;
			}
		}
		return $events;
	}

	private static function parse_date( $value ) {
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $value ), $m ) ) {
			return "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		return '';
	}
}
