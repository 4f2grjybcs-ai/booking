<?php
/**
 * Réglages du chalet (tarifs, saisons, règles de séjour, e-mails, iCal).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Settings {

	const OPTION = 'cb_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			'chalet_name'          => 'Chalet Verbier',
			'currency'             => 'CHF',
			'max_guests'           => 10,
			'base_price'           => 450,
			'base_min_nights'      => 3,
			'cleaning_fee'         => 250,
			'tourist_tax'          => 0,
			'weekly_discount'      => 0,
			'min_advance_days'     => 1,
			'max_months_ahead'     => 18,
			'check_in_time'        => '16:00',
			'check_out_time'       => '10:00',
			'admin_email'          => get_option( 'admin_email' ),
			'payment_instructions' => '',
			'terms_url'            => '',
			'ical_import_urls'     => '',
			'ical_token'           => '',
			'seasons'              => array(),
			'languages'            => array( 'en', 'de', 'es' ),
			'language_button'      => 1,
		);
	}

	public static function get( $key = null ) {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		if ( empty( $settings['ical_token'] ) ) {
			$settings['ical_token'] = wp_generate_password( 32, false );
			update_option( self::OPTION, $settings );
		}
		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Saisons triées, chacune : name, start, end (Y-m-d, inclus), price, min_nights, arrival_day (-1 = libre, 0 = dimanche … 6 = samedi).
	 */
	public static function seasons() {
		$seasons = (array) self::get( 'seasons' );
		usort(
			$seasons,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);
		return $seasons;
	}

	public static function register() {
		register_setting(
			'cb_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		// Lecture directe de l'option : self::get() peut elle-même appeler update_option().
		$current = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$out     = $current;
		$input   = is_array( $input ) ? $input : array();

		$out['chalet_name']          = sanitize_text_field( $input['chalet_name'] ?? $current['chalet_name'] );
		$out['currency']             = strtoupper( sanitize_text_field( $input['currency'] ?? $current['currency'] ) );
		$out['max_guests']           = max( 1, absint( $input['max_guests'] ?? $current['max_guests'] ) );
		$out['base_price']           = max( 0, (float) ( $input['base_price'] ?? $current['base_price'] ) );
		$out['base_min_nights']      = max( 1, absint( $input['base_min_nights'] ?? $current['base_min_nights'] ) );
		$out['cleaning_fee']         = max( 0, (float) ( $input['cleaning_fee'] ?? $current['cleaning_fee'] ) );
		$out['tourist_tax']          = max( 0, (float) ( $input['tourist_tax'] ?? $current['tourist_tax'] ) );
		$out['weekly_discount']      = min( 100, max( 0, (float) ( $input['weekly_discount'] ?? $current['weekly_discount'] ) ) );
		$out['min_advance_days']     = absint( $input['min_advance_days'] ?? $current['min_advance_days'] );
		$out['max_months_ahead']     = max( 1, absint( $input['max_months_ahead'] ?? $current['max_months_ahead'] ) );
		$out['check_in_time']        = sanitize_text_field( $input['check_in_time'] ?? $current['check_in_time'] );
		$out['check_out_time']       = sanitize_text_field( $input['check_out_time'] ?? $current['check_out_time'] );
		$out['admin_email']          = sanitize_email( $input['admin_email'] ?? $current['admin_email'] );
		$out['payment_instructions'] = sanitize_textarea_field( $input['payment_instructions'] ?? $current['payment_instructions'] );
		$out['terms_url']            = esc_url_raw( $input['terms_url'] ?? $current['terms_url'] );

		$urls = preg_split( '/\s+/', (string) ( $input['ical_import_urls'] ?? '' ) );
		$urls = array_filter( array_map( 'esc_url_raw', $urls ) );
		$out['ical_import_urls'] = implode( "\n", $urls );

		if ( ! empty( $input['ical_token'] ) && is_string( $input['ical_token'] ) ) {
			$out['ical_token'] = preg_replace( '/[^A-Za-z0-9]/', '', $input['ical_token'] );
		}
		if ( ! empty( $input['regenerate_token'] ) || empty( $out['ical_token'] ) ) {
			$out['ical_token'] = wp_generate_password( 32, false );
		}

		// Langues : la case « section langues » n'est envoyée que par la page Réglages.
		if ( isset( $input['languages_present'] ) ) {
			$out['languages']       = array_values( array_intersect( array( 'en', 'de', 'es' ), (array) ( $input['languages'] ?? array() ) ) );
			$out['language_button'] = empty( $input['language_button'] ) ? 0 : 1;
		}

		$seasons = array();
		if ( ! empty( $input['seasons'] ) && is_array( $input['seasons'] ) ) {
			foreach ( $input['seasons'] as $row ) {
				$start = self::sanitize_date( $row['start'] ?? '' );
				$end   = self::sanitize_date( $row['end'] ?? '' );
				if ( ! $start || ! $end || $end < $start ) {
					continue;
				}
				$arrival = isset( $row['arrival_day'] ) ? (int) $row['arrival_day'] : -1;
				$seasons[] = array(
					'name'        => sanitize_text_field( $row['name'] ?? '' ),
					'start'       => $start,
					'end'         => $end,
					'price'       => max( 0, (float) ( $row['price'] ?? 0 ) ),
					'min_nights'  => max( 1, absint( $row['min_nights'] ?? 1 ) ),
					'arrival_day' => ( $arrival >= 0 && $arrival <= 6 ) ? $arrival : -1,
				);
			}
		}
		$out['seasons'] = $seasons;

		// Resynchroniser les calendriers externes si la liste a changé.
		if ( $out['ical_import_urls'] !== $current['ical_import_urls'] ) {
			wp_schedule_single_event( time() + 5, 'cb_ical_sync' );
		}

		return $out;
	}

	public static function sanitize_date( $value ) {
		$d = DateTime::createFromFormat( '!Y-m-d', (string) $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	public static function format_price( $amount ) {
		return self::get( 'currency' ) . ' ' . number_format( (float) $amount, 2, '.', "'" );
	}

	public static function weekday_names() {
		return array(
			-1 => __( 'Libre', 'chalet-booking' ),
			0  => __( 'Dimanche', 'chalet-booking' ),
			1  => __( 'Lundi', 'chalet-booking' ),
			2  => __( 'Mardi', 'chalet-booking' ),
			3  => __( 'Mercredi', 'chalet-booking' ),
			4  => __( 'Jeudi', 'chalet-booking' ),
			5  => __( 'Vendredi', 'chalet-booking' ),
			6  => __( 'Samedi', 'chalet-booking' ),
		);
	}
}
