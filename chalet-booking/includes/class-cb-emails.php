<?php
/**
 * Notifications par e-mail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Emails {

	private static function summary( $booking ) {
		$fmt   = get_option( 'date_format' );
		$lines = array(
			/* translators: %s: date */
			sprintf( __( 'Arrivée : %1$s dès %2$s', 'chalet-booking' ), date_i18n( $fmt, strtotime( $booking->check_in ) ), CB_Settings::get( 'check_in_time' ) ),
			/* translators: %s: date */
			sprintf( __( 'Départ : %1$s avant %2$s', 'chalet-booking' ), date_i18n( $fmt, strtotime( $booking->check_out ) ), CB_Settings::get( 'check_out_time' ) ),
			/* translators: 1: adultes, 2: enfants */
			sprintf( __( 'Voyageurs : %1$d adulte(s), %2$d enfant(s)', 'chalet-booking' ), $booking->adults, $booking->children ),
			'',
		);
		$details = json_decode( (string) $booking->price_details, true );
		if ( ! empty( $details['lines'] ) ) {
			foreach ( $details['lines'] as $line ) {
				$lines[] = $line['label'] . ' — ' . CB_Settings::format_price( $line['amount'] );
			}
		}
		/* translators: %s: montant */
		$lines[] = sprintf( __( 'TOTAL : %s', 'chalet-booking' ), CB_Settings::format_price( $booking->total ) );
		return implode( "\n", $lines );
	}

	private static function send( $to, $subject, $body, $reply_to = '' ) {
		$headers = array();
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	public static function new_request( $booking ) {
		$chalet = CB_Settings::get( 'chalet_name' );
		$admin  = CB_Settings::get( 'admin_email' );

		// Au client.
		self::send(
			$booking->email,
			/* translators: %s: nom du chalet */
			sprintf( __( '%s — Demande de réservation reçue', 'chalet-booking' ), $chalet ),
			/* translators: %s: nom du client */
			sprintf( __( 'Bonjour %s,', 'chalet-booking' ), $booking->name ) . "\n\n"
			. __( 'Merci pour votre demande. Nous la vérifions et revenons vers vous rapidement pour la confirmer.', 'chalet-booking' ) . "\n\n"
			. self::summary( $booking ) . "\n\n"
			. $chalet,
			$admin
		);

		// Au propriétaire.
		self::send(
			$admin,
			/* translators: 1: nom du chalet, 2: nom du client */
			sprintf( __( '[%1$s] Nouvelle demande de %2$s', 'chalet-booking' ), $chalet, $booking->name ),
			sprintf(
				"%s\n%s\n%s\n\n%s\n\n%s\n%s\n\n%s",
				$booking->name,
				$booking->email,
				$booking->phone,
				self::summary( $booking ),
				__( 'Message :', 'chalet-booking' ),
				$booking->message,
				admin_url( 'admin.php?page=chalet-booking&view=' . $booking->id )
			),
			$booking->email
		);
	}

	public static function confirmed( $booking ) {
		$chalet  = CB_Settings::get( 'chalet_name' );
		$payment = CB_Settings::get( 'payment_instructions' );

		self::send(
			$booking->email,
			/* translators: %s: nom du chalet */
			sprintf( __( '%s — Votre réservation est confirmée', 'chalet-booking' ), $chalet ),
			/* translators: %s: nom du client */
			sprintf( __( 'Bonjour %s,', 'chalet-booking' ), $booking->name ) . "\n\n"
			. __( 'Nous avons le plaisir de confirmer votre séjour à Verbier.', 'chalet-booking' ) . "\n\n"
			. self::summary( $booking ) . "\n\n"
			. ( $payment ? __( 'Modalités de paiement :', 'chalet-booking' ) . "\n" . $payment . "\n\n" : '' )
			. __( 'Au plaisir de vous accueillir !', 'chalet-booking' ) . "\n" . $chalet,
			CB_Settings::get( 'admin_email' )
		);
	}

	public static function cancelled( $booking ) {
		$chalet = CB_Settings::get( 'chalet_name' );
		self::send(
			$booking->email,
			/* translators: %s: nom du chalet */
			sprintf( __( '%s — Votre demande de réservation', 'chalet-booking' ), $chalet ),
			/* translators: %s: nom du client */
			sprintf( __( 'Bonjour %s,', 'chalet-booking' ), $booking->name ) . "\n\n"
			. __( 'Nous sommes désolés, nous ne pouvons pas donner suite à votre réservation pour les dates suivantes :', 'chalet-booking' ) . "\n\n"
			. self::summary( $booking ) . "\n\n"
			. __( 'N’hésitez pas à nous contacter pour d’autres dates.', 'chalet-booking' ) . "\n" . $chalet,
			CB_Settings::get( 'admin_email' )
		);
	}
}
