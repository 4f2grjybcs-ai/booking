<?php
/**
 * Disponibilités et règles de séjour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Availability {

	/**
	 * Nuits occupées (Y-m-d) entre $from inclus et $to exclu.
	 */
	public static function booked_nights( $from, $to, $exclude_id = 0 ) {
		$nights   = array();
		$bookings = CB_DB::query(
			array(
				'status' => CB_DB::BLOCKING_STATUSES,
				'from'   => $from,
				'to'     => $to,
			)
		);
		foreach ( $bookings as $b ) {
			if ( $exclude_id && (int) $b->id === (int) $exclude_id ) {
				continue;
			}
			foreach ( self::nights( max( $b->check_in, $from ), min( $b->check_out, $to ) ) as $night ) {
				$nights[ $night ] = true;
			}
		}
		ksort( $nights );
		return array_keys( $nights );
	}

	/**
	 * Liste des nuits d'un séjour [check_in, check_out).
	 */
	public static function nights( $check_in, $check_out ) {
		$out  = array();
		$d    = new DateTime( $check_in );
		$end  = new DateTime( $check_out );
		while ( $d < $end ) {
			$out[] = $d->format( 'Y-m-d' );
			$d->modify( '+1 day' );
		}
		return $out;
	}

	public static function is_available( $check_in, $check_out, $exclude_id = 0 ) {
		return empty( self::booked_nights( $check_in, $check_out, $exclude_id ) );
	}

	/**
	 * Saison applicable à une nuit donnée, ou null (tarif de base).
	 */
	public static function season_for( $date ) {
		foreach ( CB_Settings::seasons() as $season ) {
			if ( $date >= $season['start'] && $date <= $season['end'] ) {
				return $season;
			}
		}
		return null;
	}

	public static function min_nights_for( $check_in ) {
		$season = self::season_for( $check_in );
		return $season ? (int) $season['min_nights'] : (int) CB_Settings::get( 'base_min_nights' );
	}

	/**
	 * Vérifie une demande de séjour. Retourne true ou WP_Error.
	 */
	public static function validate_stay( $check_in, $check_out, $adults, $children, $check_availability = true ) {
		$check_in  = CB_Settings::sanitize_date( $check_in );
		$check_out = CB_Settings::sanitize_date( $check_out );
		if ( ! $check_in || ! $check_out ) {
			return new WP_Error( 'cb_dates', __( 'Veuillez choisir des dates d’arrivée et de départ valides.', 'chalet-booking' ) );
		}
		if ( $check_out <= $check_in ) {
			return new WP_Error( 'cb_dates', __( 'La date de départ doit être après la date d’arrivée.', 'chalet-booking' ) );
		}

		$tz       = wp_timezone();
		$today    = new DateTime( 'today', $tz );
		$earliest = ( clone $today )->modify( '+' . (int) CB_Settings::get( 'min_advance_days' ) . ' days' )->format( 'Y-m-d' );
		$latest   = ( clone $today )->modify( '+' . (int) CB_Settings::get( 'max_months_ahead' ) . ' months' )->format( 'Y-m-d' );

		if ( $check_in < $earliest ) {
			return new WP_Error( 'cb_dates', __( 'Cette date d’arrivée n’est plus réservable en ligne. Contactez-nous directement.', 'chalet-booking' ) );
		}
		if ( $check_out > $latest ) {
			return new WP_Error( 'cb_dates', __( 'Les réservations ne sont pas encore ouvertes pour ces dates.', 'chalet-booking' ) );
		}

		$nights     = count( self::nights( $check_in, $check_out ) );
		$min_nights = self::min_nights_for( $check_in );
		if ( $nights < $min_nights ) {
			/* translators: %d: nombre de nuits */
			return new WP_Error( 'cb_min_nights', sprintf( _n( 'Séjour minimum de %d nuit pour ces dates.', 'Séjour minimum de %d nuits pour ces dates.', $min_nights, 'chalet-booking' ), $min_nights ) );
		}

		$days = CB_Settings::weekday_names();

		$in_season = self::season_for( $check_in );
		if ( $in_season && $in_season['arrival_day'] >= 0 && (int) ( new DateTime( $check_in ) )->format( 'w' ) !== (int) $in_season['arrival_day'] ) {
			/* translators: %s: jour de la semaine */
			return new WP_Error( 'cb_arrival_day', sprintf( __( 'En cette période, les arrivées se font uniquement le %s.', 'chalet-booking' ), strtolower( $days[ $in_season['arrival_day'] ] ) ) );
		}

		$last_night = ( new DateTime( $check_out ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$out_season = self::season_for( $last_night );
		if ( $out_season && $out_season['arrival_day'] >= 0 && (int) ( new DateTime( $check_out ) )->format( 'w' ) !== (int) $out_season['arrival_day'] ) {
			/* translators: %s: jour de la semaine */
			return new WP_Error( 'cb_departure_day', sprintf( __( 'En cette période, les départs se font uniquement le %s.', 'chalet-booking' ), strtolower( $days[ $out_season['arrival_day'] ] ) ) );
		}

		$adults   = (int) $adults;
		$children = (int) $children;
		if ( $adults < 1 ) {
			return new WP_Error( 'cb_guests', __( 'Au moins un adulte est requis.', 'chalet-booking' ) );
		}
		$max = (int) CB_Settings::get( 'max_guests' );
		if ( $adults + $children > $max ) {
			/* translators: %d: capacité maximale */
			return new WP_Error( 'cb_guests', sprintf( __( 'Le chalet accueille %d personnes au maximum.', 'chalet-booking' ), $max ) );
		}

		if ( $check_availability && ! self::is_available( $check_in, $check_out ) ) {
			return new WP_Error( 'cb_unavailable', __( 'Désolé, ces dates ne sont plus disponibles.', 'chalet-booking' ) );
		}

		return true;
	}
}
