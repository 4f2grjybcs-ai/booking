<?php
/**
 * Calcul du prix d'un séjour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Pricing {

	public static function night_price( $date ) {
		$season = CB_Availability::season_for( $date );
		return $season ? (float) $season['price'] : (float) CB_Settings::get( 'base_price' );
	}

	/**
	 * Devis détaillé.
	 *
	 * @return array nights, lines[] (label, amount), total
	 */
	public static function quote( $check_in, $check_out, $adults ) {
		$nights = CB_Availability::nights( $check_in, $check_out );
		$count  = count( $nights );

		// Regroupe les nuits consécutives au même prix/saison pour un détail lisible.
		$groups = array();
		foreach ( $nights as $night ) {
			$season = CB_Availability::season_for( $night );
			$label  = $season && $season['name'] ? CB_I18n::t( $season['name'] ) : __( 'Tarif standard', 'chalet-booking' );
			$price  = self::night_price( $night );
			$key    = $label . '|' . $price;
			$last   = end( $groups );
			if ( $last && $last['key'] === $key ) {
				$groups[ count( $groups ) - 1 ]['n']++;
			} else {
				$groups[] = array(
					'key'   => $key,
					'label' => $label,
					'price' => $price,
					'n'     => 1,
				);
			}
		}

		$lines    = array();
		$subtotal = 0;
		foreach ( $groups as $g ) {
			$amount    = $g['price'] * $g['n'];
			$subtotal += $amount;
			$lines[]   = array(
				/* translators: 1: saison, 2: nombre de nuits, 3: prix par nuit */
				'label'  => sprintf( __( '%1$s : %2$d × %3$s', 'chalet-booking' ), $g['label'], $g['n'], CB_Settings::format_price( $g['price'] ) ),
				'amount' => $amount,
			);
		}

		$discount_pct = (float) CB_Settings::get( 'weekly_discount' );
		if ( $count >= 7 && $discount_pct > 0 ) {
			$discount = round( $subtotal * $discount_pct / 100, 2 );
			$lines[]  = array(
				/* translators: %s: pourcentage */
				'label'  => sprintf( __( 'Rabais séjour d’une semaine ou plus (%s %%)', 'chalet-booking' ), rtrim( rtrim( number_format( $discount_pct, 2, '.', '' ), '0' ), '.' ) ),
				'amount' => -$discount,
			);
		}

		$cleaning = (float) CB_Settings::get( 'cleaning_fee' );
		if ( $cleaning > 0 ) {
			$lines[] = array(
				'label'  => __( 'Frais de nettoyage final', 'chalet-booking' ),
				'amount' => $cleaning,
			);
		}

		$tax = (float) CB_Settings::get( 'tourist_tax' );
		if ( $tax > 0 && $adults > 0 ) {
			$lines[] = array(
				/* translators: 1: adultes, 2: nuits, 3: montant */
				'label'  => sprintf( __( 'Taxe de séjour : %1$d adulte(s) × %2$d nuit(s) × %3$s', 'chalet-booking' ), $adults, $count, CB_Settings::format_price( $tax ) ),
				'amount' => round( $tax * $adults * $count, 2 ),
			);
		}

		$total = 0;
		foreach ( $lines as $line ) {
			$total += $line['amount'];
		}

		return array(
			'nights' => $count,
			'lines'  => $lines,
			'total'  => round( $total, 2 ),
		);
	}
}
