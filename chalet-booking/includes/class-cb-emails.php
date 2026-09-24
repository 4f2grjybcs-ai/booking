<?php
/**
 * Notifications par e-mail : expéditeur, modèles modifiables, SMTP optionnel, journal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Emails {

	const OPTION = 'cb_email_settings';
	const LOG    = 'cb_email_log';

	/** Dernière erreur d'envoi remontée par wp_mail. */
	private static $last_error = '';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'cb_email_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Modèles                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Définition des e-mails : libellé, destinataire et contenu par défaut.
	 */
	public static function templates() {
		return array(
			'guest_request'   => array(
				'label'   => __( 'Client — demande reçue', 'chalet-booking' ),
				'desc'    => __( 'Envoyé au client dès qu’il envoie le formulaire.', 'chalet-booking' ),
				'subject' => __( '{chalet} — Demande de réservation reçue', 'chalet-booking' ),
				'body'    => __( "Bonjour {name},\n\nMerci pour votre demande. Nous la vérifions et revenons vers vous rapidement pour la confirmer.\n\n{summary}\n\nMeilleures salutations,\n{chalet}", 'chalet-booking' ),
			),
			'admin_request'   => array(
				'label'   => __( 'Propriétaire — nouvelle demande', 'chalet-booking' ),
				'desc'    => __( 'Envoyé à l’e-mail de notification à chaque nouvelle demande.', 'chalet-booking' ),
				'subject' => __( '[{chalet}] Nouvelle demande de {name} ({check_in} → {check_out})', 'chalet-booking' ),
				'body'    => __( "Nouvelle demande de réservation :\n\n{name}\n{email}\n{phone}\n\n{summary}\n\nMessage :\n{message}\n\nConfirmer ou refuser : {admin_link}", 'chalet-booking' ),
			),
			'guest_confirmed' => array(
				'label'   => __( 'Client — réservation confirmée', 'chalet-booking' ),
				'desc'    => __( 'Envoyé quand vous cliquez sur « Confirmer ».', 'chalet-booking' ),
				'subject' => __( '{chalet} — Votre réservation est confirmée', 'chalet-booking' ),
				'body'    => __( "Bonjour {name},\n\nNous avons le plaisir de confirmer votre séjour à Verbier.\n\n{summary}\n\nModalités de paiement :\n{payment}\n\nAu plaisir de vous accueillir !\n{chalet}", 'chalet-booking' ),
			),
			'guest_cancelled' => array(
				'label'   => __( 'Client — demande refusée', 'chalet-booking' ),
				'desc'    => __( 'Envoyé quand vous annulez une demande encore en attente.', 'chalet-booking' ),
				'subject' => __( '{chalet} — Votre demande de réservation', 'chalet-booking' ),
				'body'    => __( "Bonjour {name},\n\nNous sommes désolés, nous ne pouvons pas donner suite à votre demande pour les dates suivantes :\n\n{summary}\n\nN’hésitez pas à nous contacter pour d’autres dates.\n{chalet}", 'chalet-booking' ),
			),
		);
	}

	public static function placeholders() {
		return array(
			'{name}'       => __( 'nom du client', 'chalet-booking' ),
			'{email}'      => __( 'e-mail du client', 'chalet-booking' ),
			'{phone}'      => __( 'téléphone du client', 'chalet-booking' ),
			'{check_in}'   => __( 'date d’arrivée', 'chalet-booking' ),
			'{check_out}'  => __( 'date de départ', 'chalet-booking' ),
			'{nights}'     => __( 'nombre de nuits', 'chalet-booking' ),
			'{adults}'     => __( 'adultes', 'chalet-booking' ),
			'{children}'   => __( 'enfants', 'chalet-booking' ),
			'{total}'      => __( 'prix total', 'chalet-booking' ),
			'{summary}'    => __( 'récapitulatif complet (dates, voyageurs, détail du prix)', 'chalet-booking' ),
			'{message}'    => __( 'message du client', 'chalet-booking' ),
			'{payment}'    => __( 'modalités de paiement (réglages)', 'chalet-booking' ),
			'{booking_id}' => __( 'numéro de réservation', 'chalet-booking' ),
			'{admin_link}' => __( 'lien vers la réservation dans l’admin', 'chalet-booking' ),
			'{chalet}'     => __( 'nom du chalet', 'chalet-booking' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		$templates = array();
		foreach ( self::templates() as $key => $tpl ) {
			$templates[ $key ] = array(
				'enabled' => 1,
				'subject' => $tpl['subject'],
				'body'    => $tpl['body'],
			);
		}
		return array(
			'from_name'   => '',
			'from_email'  => '',
			'bcc_admin'   => 0,
			'templates'   => $templates,
			'smtp_host'   => '',
			'smtp_port'   => 587,
			'smtp_secure' => 'tls',
			'smtp_user'   => '',
			'smtp_pass'   => '',
		);
	}

	public static function get( $key = null ) {
		$defaults = self::defaults();
		$settings = wp_parse_args( get_option( self::OPTION, array() ), $defaults );
		foreach ( $defaults['templates'] as $id => $tpl ) {
			$settings['templates'][ $id ] = wp_parse_args( $settings['templates'][ $id ] ?? array(), $tpl );
		}
		if ( null === $key ) {
			return $settings;
		}
		return $settings[ $key ] ?? null;
	}

	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$out     = self::defaults();

		$out['from_name']  = sanitize_text_field( $input['from_name'] ?? '' );
		$out['from_email'] = sanitize_email( $input['from_email'] ?? '' );
		$out['bcc_admin']  = empty( $input['bcc_admin'] ) ? 0 : 1;

		foreach ( self::templates() as $id => $tpl ) {
			$row                      = $input['templates'][ $id ] ?? array();
			$subject                  = sanitize_text_field( $row['subject'] ?? '' );
			$body                     = sanitize_textarea_field( $row['body'] ?? '' );
			$out['templates'][ $id ] = array(
				'enabled' => empty( $row['enabled'] ) ? 0 : 1,
				// Champ vidé = retour au texte par défaut.
				'subject' => '' !== $subject ? $subject : $tpl['subject'],
				'body'    => '' !== trim( $body ) ? $body : $tpl['body'],
			);
		}

		$out['smtp_host']   = sanitize_text_field( $input['smtp_host'] ?? '' );
		$out['smtp_port']   = absint( $input['smtp_port'] ?? 587 ) ?: 587;
		$secure             = $input['smtp_secure'] ?? 'tls';
		$out['smtp_secure'] = in_array( $secure, array( '', 'ssl', 'tls' ), true ) ? $secure : 'tls';
		$out['smtp_user']   = sanitize_text_field( $input['smtp_user'] ?? '' );
		// Mot de passe laissé vide = conserver l'actuel.
		$pass             = isset( $input['smtp_pass'] ) ? (string) wp_unslash( $input['smtp_pass'] ) : '';
		$out['smtp_pass'] = '' !== $pass ? $pass : $current['smtp_pass'];
		if ( ! empty( $input['smtp_clear_pass'] ) ) {
			$out['smtp_pass'] = '';
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Envoi                                                              */
	/* ------------------------------------------------------------------ */

	private static function summary( $booking ) {
		$fmt   = get_option( 'date_format' );
		$lines = array(
			/* translators: 1: date, 2: heure */
			sprintf( __( 'Arrivée : %1$s dès %2$s', 'chalet-booking' ), date_i18n( $fmt, strtotime( $booking->check_in ) ), CB_Settings::get( 'check_in_time' ) ),
			/* translators: 1: date, 2: heure */
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

	private static function vars( $booking ) {
		$fmt = get_option( 'date_format' );
		return array(
			'{name}'       => $booking->name,
			'{email}'      => $booking->email,
			'{phone}'      => $booking->phone,
			'{check_in}'   => date_i18n( $fmt, strtotime( $booking->check_in ) ),
			'{check_out}'  => date_i18n( $fmt, strtotime( $booking->check_out ) ),
			'{nights}'     => count( CB_Availability::nights( $booking->check_in, $booking->check_out ) ),
			'{adults}'     => (int) $booking->adults,
			'{children}'   => (int) $booking->children,
			'{total}'      => CB_Settings::format_price( $booking->total ),
			'{summary}'    => self::summary( $booking ),
			'{message}'    => (string) $booking->message,
			'{payment}'    => (string) CB_Settings::get( 'payment_instructions' ),
			'{booking_id}' => (int) $booking->id,
			'{admin_link}' => admin_url( 'admin.php?page=chalet-booking&view=' . (int) $booking->id ),
			'{chalet}'     => CB_Settings::get( 'chalet_name' ),
		);
	}

	/**
	 * Envoie un modèle pour une réservation. Retourne true si wp_mail a accepté l'envoi.
	 */
	public static function send_template( $id, $booking, $to = null ) {
		$settings = self::get();
		$tpl      = $settings['templates'][ $id ] ?? null;
		if ( ! $tpl || ( empty( $tpl['enabled'] ) && null === $to ) ) {
			return false;
		}

		$admin     = CB_Settings::get( 'admin_email' );
		$for_admin = 'admin_request' === $id;
		if ( null === $to ) {
			$to = $for_admin ? $admin : $booking->email;
		}
		if ( ! is_email( $to ) ) {
			return false;
		}

		$vars    = self::vars( $booking );
		$subject = strtr( $tpl['subject'], $vars );
		// Supprime les lignes « Modalités de paiement : » vides si aucun texte n'est configuré.
		$body = strtr( $tpl['body'], $vars );
		$body = preg_replace( "/\n{3,}/", "\n\n", $body );

		$headers  = array();
		$reply_to = $for_admin ? $booking->email : $admin;
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		if ( ! $for_admin && $settings['bcc_admin'] && is_email( $admin ) && $admin !== $to ) {
			$headers[] = 'Bcc: ' . $admin;
		}

		return self::mail( $to, $subject, $body, $headers );
	}

	/**
	 * wp_mail avec l'expéditeur et le SMTP du plugin, et journalisation.
	 */
	private static function mail( $to, $subject, $body, $headers ) {
		$settings         = self::get();
		self::$last_error = '';

		$from_email = $settings['from_email'];
		$from_name  = $settings['from_name'] ? $settings['from_name'] : CB_Settings::get( 'chalet_name' );
		$set_from   = function ( $phpmailer ) use ( $from_email, $from_name ) {
			if ( $from_email ) {
				$phpmailer->setFrom( $from_email, $from_name, false );
			} else {
				$phpmailer->FromName = $from_name;
			}
		};
		$smtp       = array( __CLASS__, 'configure_smtp' );
		$on_fail    = function ( $error ) {
			self::$last_error = $error->get_error_message();
		};

		add_action( 'phpmailer_init', $set_from, 999 );
		if ( $settings['smtp_host'] ) {
			add_action( 'phpmailer_init', $smtp, 1000 );
		}
		add_action( 'wp_mail_failed', $on_fail );

		$ok = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'phpmailer_init', $set_from, 999 );
		remove_action( 'phpmailer_init', $smtp, 1000 );
		remove_action( 'wp_mail_failed', $on_fail );

		if ( ! $ok && '' === self::$last_error ) {
			self::$last_error = __( 'Le serveur a refusé l’envoi (fonction mail() indisponible ?).', 'chalet-booking' );
		}
		self::log( $to, $subject, $ok, self::$last_error );
		return $ok;
	}

	public static function configure_smtp( $phpmailer ) {
		$s = self::get();
		$phpmailer->isSMTP();
		$phpmailer->Host       = $s['smtp_host'];
		$phpmailer->Port       = (int) $s['smtp_port'];
		$phpmailer->SMTPSecure = $s['smtp_secure'];
		$phpmailer->SMTPAutoTLS = '' !== $s['smtp_secure'];
		$phpmailer->Timeout    = 15;
		if ( '' !== $s['smtp_user'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $s['smtp_user'];
			$phpmailer->Password = $s['smtp_pass'];
		}
	}

	public static function last_error() {
		return self::$last_error;
	}

	private static function log( $to, $subject, $ok, $error ) {
		$log = get_option( self::LOG, array() );
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'to'      => $to,
				'subject' => $subject,
				'ok'      => $ok ? 1 : 0,
				'error'   => $error,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 30 ), false );
	}

	/**
	 * Fausse réservation pour l'e-mail de test.
	 */
	public static function sample_booking() {
		$in    = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
		$out   = wp_date( 'Y-m-d', strtotime( '+37 days' ) );
		$quote = CB_Pricing::quote( $in, $out, 2 );
		return (object) array(
			'id'            => 0,
			'check_in'      => $in,
			'check_out'     => $out,
			'adults'        => 2,
			'children'      => 1,
			'name'          => 'Marie Exemple',
			'email'         => 'marie@example.com',
			'phone'         => '+41 79 000 00 00',
			'message'       => __( '(message d’exemple)', 'chalet-booking' ),
			'total'         => $quote['total'],
			'price_details' => wp_json_encode( $quote ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Événements                                                         */
	/* ------------------------------------------------------------------ */

	public static function new_request( $booking ) {
		self::send_template( 'guest_request', $booking );
		self::send_template( 'admin_request', $booking );
	}

	public static function confirmed( $booking ) {
		return self::send_template( 'guest_confirmed', $booking );
	}

	public static function cancelled( $booking ) {
		return self::send_template( 'guest_cancelled', $booking );
	}
}
