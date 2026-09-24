<?php
/**
 * API REST publique utilisée par le formulaire de réservation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_REST {

	const NS = 'chalet-booking/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function availability() {
		$tz    = wp_timezone();
		$today = new DateTime( 'today', $tz );
		$from  = $today->format( 'Y-m-d' );
		$to    = ( clone $today )->modify( '+' . ( (int) CB_Settings::get( 'max_months_ahead' ) + 1 ) . ' months' )->format( 'Y-m-d' );

		$seasons = array();
		foreach ( CB_Settings::seasons() as $s ) {
			$seasons[] = array(
				'start'      => $s['start'],
				'end'        => $s['end'],
				'price'      => (float) $s['price'],
				'minNights'  => (int) $s['min_nights'],
				'arrivalDay' => (int) $s['arrival_day'],
			);
		}

		$response = rest_ensure_response(
			array(
				'booked'         => CB_Availability::booked_nights( $from, $to ),
				'seasons'        => $seasons,
				'basePrice'      => (float) CB_Settings::get( 'base_price' ),
				'baseMinNights'  => (int) CB_Settings::get( 'base_min_nights' ),
				'earliest'       => ( clone $today )->modify( '+' . (int) CB_Settings::get( 'min_advance_days' ) . ' days' )->format( 'Y-m-d' ),
				'latest'         => ( clone $today )->modify( '+' . (int) CB_Settings::get( 'max_months_ahead' ) . ' months' )->format( 'Y-m-d' ),
				'maxGuests'      => (int) CB_Settings::get( 'max_guests' ),
				'currency'       => CB_Settings::get( 'currency' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function quote( WP_REST_Request $request ) {
		$check_in  = (string) $request->get_param( 'check_in' );
		$check_out = (string) $request->get_param( 'check_out' );
		$adults    = absint( $request->get_param( 'adults' ) );
		$children  = absint( $request->get_param( 'children' ) );

		$valid = CB_Availability::validate_stay( $check_in, $check_out, max( 1, $adults ), $children );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), array( 'status' => 400 ) );
		}
		return self::format_quote( CB_Pricing::quote( $check_in, $check_out, max( 1, $adults ) ) );
	}

	private static function format_quote( array $quote ) {
		foreach ( $quote['lines'] as &$line ) {
			$line['formatted'] = CB_Settings::format_price( $line['amount'] );
		}
		$quote['totalFormatted'] = CB_Settings::format_price( $quote['total'] );
		return $quote;
	}

	public static function book( WP_REST_Request $request ) {
		// Piège à robots : champ caché qui doit rester vide.
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return new WP_Error( 'cb_spam', __( 'Demande refusée.', 'chalet-booking' ), array( 'status' => 400 ) );
		}

		// Limite simple : 5 demandes par heure et par adresse IP.
		$ip_key = 'cb_rl_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits >= 5 ) {
			return new WP_Error( 'cb_rate', __( 'Trop de demandes. Merci de réessayer plus tard ou de nous contacter directement.', 'chalet-booking' ), array( 'status' => 429 ) );
		}

		$check_in  = (string) $request->get_param( 'check_in' );
		$check_out = (string) $request->get_param( 'check_out' );
		$adults    = absint( $request->get_param( 'adults' ) );
		$children  = absint( $request->get_param( 'children' ) );
		$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone     = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$message   = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( '' === $name || ! is_email( $email ) || '' === $phone ) {
			return new WP_Error( 'cb_contact', __( 'Merci d’indiquer votre nom, un e-mail valide et un téléphone.', 'chalet-booking' ), array( 'status' => 400 ) );
		}
		if ( CB_Content::terms_required() && ! rest_sanitize_boolean( $request->get_param( 'terms' ) ) ) {
			return new WP_Error( 'cb_terms', __( 'Merci d’accepter les conditions de location.', 'chalet-booking' ), array( 'status' => 400 ) );
		}

		// Verrou pour éviter deux réservations simultanées des mêmes dates.
		global $wpdb;
		$lock = 'cb_booking_lock_' . get_current_blog_id();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'cb_busy', __( 'Le service est momentanément occupé, veuillez réessayer.', 'chalet-booking' ), array( 'status' => 503 ) );
		}

		$valid = CB_Availability::validate_stay( $check_in, $check_out, $adults, $children );
		if ( is_wp_error( $valid ) ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), array( 'status' => 400 ) );
		}

		$quote = CB_Pricing::quote( $check_in, $check_out, $adults );
		$id    = CB_DB::insert(
			array(
				'status'        => 'pending',
				'source'        => 'site',
				'check_in'      => $check_in,
				'check_out'     => $check_out,
				'adults'        => $adults,
				'children'      => $children,
				'name'          => $name,
				'email'         => $email,
				'phone'         => $phone,
				'message'       => $message,
				'total'         => $quote['total'],
				'price_details' => $quote,
				'lang'          => CB_I18n::active(),
			)
		);
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );

		if ( ! $id ) {
			return new WP_Error( 'cb_db', __( 'Une erreur est survenue, merci de réessayer.', 'chalet-booking' ), array( 'status' => 500 ) );
		}

		set_transient( $ip_key, $hits + 1, HOUR_IN_SECONDS );
		CB_Emails::new_request( CB_DB::get( $id ) );

		return array(
			'id'      => $id,
			'message' => __( 'Merci ! Votre demande de réservation a bien été envoyée. Vous allez recevoir un e-mail récapitulatif et nous vous confirmerons la réservation rapidement.', 'chalet-booking' ),
		);
	}
}
