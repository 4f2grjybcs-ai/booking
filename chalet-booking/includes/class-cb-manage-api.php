<?php
/**
 * API REST privée du back-office (/chalet-booking/v1/manage/…).
 * Réservée aux utilisateurs ayant la capacité « cb_manage_bookings ».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Manage_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return CB_Backoffice::can_manage();
	}

	public static function routes() {
		$ns    = CB_REST::NS;
		$route = function ( $path, $methods, $callback ) use ( $ns ) {
			register_rest_route(
				$ns,
				'/manage' . $path,
				array(
					'methods'             => $methods,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'can' ),
				)
			);
		};

		$route( '/dashboard', 'GET', 'dashboard' );
		$route( '/bookings', 'GET', 'list_bookings' );
		$route( '/bookings', 'POST', 'create_booking' );
		$route( '/bookings/(?P<id>\d+)', 'GET', 'get_booking' );
		$route( '/bookings/(?P<id>\d+)', 'POST', 'update_booking' );
		$route( '/bookings/(?P<id>\d+)', 'DELETE', 'delete_booking' );
		$route( '/bookings/(?P<id>\d+)/status', 'POST', 'set_status' );
		$route( '/bookings/(?P<id>\d+)/email', 'POST', 'email_guest' );
		$route( '/quote', 'GET', 'quote' );
		$route( '/pricing', 'GET', 'get_pricing' );
		$route( '/pricing', 'POST', 'save_pricing' );
		$route( '/stats', 'GET', 'stats' );
		$route( '/export', 'GET', 'export' );
		$route( '/sync', 'POST', 'sync' );
		$route( '/emails', 'GET', 'email_log' );
	}

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                        */
	/* ------------------------------------------------------------------ */

	private static function today() {
		return ( new DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	}

	private static function plus_days( $date, $days ) {
		return ( new DateTime( $date ) )->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function format( $b ) {
		if ( ! $b ) {
			return null;
		}
		$details = json_decode( (string) $b->price_details, true );
		$total   = (float) $b->total;
		$paid    = (float) $b->paid;
		if ( in_array( $b->status, array( 'blocked', 'cancelled' ), true ) || 'ical' === $b->source || $total <= 0 ) {
			$payment = 'n/a';
		} elseif ( $paid <= 0 ) {
			$payment = 'unpaid';
		} elseif ( $paid + 0.009 < $total ) {
			$payment = 'deposit';
		} else {
			$payment = 'paid';
		}
		return array(
			'id'           => (int) $b->id,
			'status'       => $b->status,
			'source'       => $b->source,
			'checkIn'      => $b->check_in,
			'checkOut'     => $b->check_out,
			'nights'       => count( CB_Availability::nights( $b->check_in, $b->check_out ) ),
			'adults'       => (int) $b->adults,
			'children'     => (int) $b->children,
			'name'         => $b->name,
			'email'        => $b->email,
			'phone'        => $b->phone,
			'message'      => (string) $b->message,
			'notes'        => (string) $b->notes,
			'total'        => $total,
			'paid'         => $paid,
			'balance'      => round( max( 0, $total - $paid ), 2 ),
			'payment'      => $payment,
			'priceLines'   => $details['lines'] ?? array(),
			'createdAt'    => $b->created_at,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Tableau de bord                                                    */
	/* ------------------------------------------------------------------ */

	public static function dashboard() {
		$today = self::today();
		$year  = substr( $today, 0, 4 );
		$fmt   = array( __CLASS__, 'format' );

		$pending  = array_map( $fmt, CB_DB::query( array( 'status' => 'pending' ) ) );
		$upcoming = CB_DB::query(
			array(
				'status' => array( 'confirmed', 'pending', 'blocked' ),
				'from'   => $today,
				'to'     => self::plus_days( $today, 60 ),
			)
		);

		$arrivals   = array();
		$departures = array();
		$current    = null;
		foreach ( $upcoming as $b ) {
			if ( 'blocked' === $b->status && 'ical' !== $b->source ) {
				continue;
			}
			if ( $b->check_in <= $today && $b->check_out > $today ) {
				$current = self::format( $b );
			}
			if ( $b->check_in >= $today && $b->check_in <= self::plus_days( $today, 30 ) ) {
				$arrivals[] = self::format( $b );
			}
			if ( $b->check_out >= $today && $b->check_out <= self::plus_days( $today, 14 ) ) {
				$departures[] = self::format( $b );
			}
		}

		// Chiffre d'affaires confirmé de l'année (par date d'arrivée) et soldes à encaisser.
		$revenue     = 0;
		$outstanding = 0;
		$to_collect  = array();
		foreach ( CB_DB::query( array( 'status' => 'confirmed', 'exclude_source' => 'ical' ) ) as $b ) {
			if ( substr( $b->check_in, 0, 4 ) === $year ) {
				$revenue += (float) $b->total;
			}
			$balance = (float) $b->total - (float) $b->paid;
			if ( $balance > 0.009 && $b->check_out >= $today ) {
				$outstanding += $balance;
				$to_collect[] = self::format( $b );
			}
		}

		// Taux d'occupation des 90 prochains jours.
		$window   = 90;
		$occupied = count( CB_Availability::booked_nights( $today, self::plus_days( $today, $window ) ) );

		$sync = get_option( 'cb_ical_last_sync' );

		return array(
			'today'       => $today,
			'pending'     => $pending,
			'arrivals'    => $arrivals,
			'departures'  => $departures,
			'current'     => $current,
			'toCollect'   => array_slice( $to_collect, 0, 10 ),
			'kpi'         => array(
				'revenueYear' => round( $revenue, 2 ),
				'year'        => (int) $year,
				'outstanding' => round( $outstanding, 2 ),
				'occupancy90' => round( 100 * $occupied / $window ),
				'pending'     => count( $pending ),
			),
			'sync'        => array(
				'enabled' => '' !== trim( (string) CB_Settings::get( 'ical_import_urls' ) ),
				'time'    => $sync ? (int) $sync['time'] : 0,
				'errors'  => $sync ? count( array_filter( (array) $sync['report'], 'is_string' ) ) : 0,
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Réservations                                                       */
	/* ------------------------------------------------------------------ */

	public static function list_bookings( WP_REST_Request $r ) {
		$args = array();
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		if ( in_array( $status, array( 'pending', 'confirmed', 'cancelled', 'blocked' ), true ) ) {
			$args['status'] = $status;
		}
		if ( $r->get_param( 'from' ) ) {
			$args['from'] = CB_Settings::sanitize_date( (string) $r->get_param( 'from' ) );
		}
		if ( $r->get_param( 'to' ) ) {
			$args['to'] = CB_Settings::sanitize_date( (string) $r->get_param( 'to' ) );
		}
		$period = sanitize_key( (string) $r->get_param( 'period' ) );
		if ( 'upcoming' === $period ) {
			$args['from'] = self::today();
		} elseif ( 'past' === $period ) {
			$args['to']    = self::today();
			$args['order'] = 'DESC';
		}
		if ( ! $r->get_param( 'include_ical' ) ) {
			$args['exclude_source'] = 'ical';
		}
		$search = sanitize_text_field( (string) $r->get_param( 'search' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		return array_map( array( __CLASS__, 'format' ), CB_DB::query( $args ) );
	}

	public static function get_booking( WP_REST_Request $r ) {
		$b = CB_DB::get( (int) $r['id'] );
		return $b ? self::format( $b ) : self::error( 'cb_not_found', __( 'Réservation introuvable.', 'chalet-booking' ), 404 );
	}

	/**
	 * Champs modifiables communs à la création et à la modification.
	 */
	private static function read_fields( WP_REST_Request $r, $existing = null ) {
		$get = function ( $key, $default ) use ( $r ) {
			$v = $r->get_param( $key );
			return null === $v ? $default : $v;
		};
		return array(
			'check_in'  => CB_Settings::sanitize_date( (string) $get( 'checkIn', $existing ? $existing->check_in : '' ) ),
			'check_out' => CB_Settings::sanitize_date( (string) $get( 'checkOut', $existing ? $existing->check_out : '' ) ),
			'adults'    => absint( $get( 'adults', $existing ? $existing->adults : 0 ) ),
			'children'  => absint( $get( 'children', $existing ? $existing->children : 0 ) ),
			'name'      => sanitize_text_field( (string) $get( 'name', $existing ? $existing->name : '' ) ),
			'email'     => sanitize_email( (string) $get( 'email', $existing ? $existing->email : '' ) ),
			'phone'     => sanitize_text_field( (string) $get( 'phone', $existing ? $existing->phone : '' ) ),
			'notes'     => sanitize_textarea_field( (string) $get( 'notes', $existing ? $existing->notes : '' ) ),
			'paid'      => max( 0, round( (float) $get( 'paid', $existing ? $existing->paid : 0 ), 2 ) ),
		);
	}

	private static function check_dates( $data, $exclude_id = 0 ) {
		if ( ! $data['check_in'] || ! $data['check_out'] || $data['check_out'] <= $data['check_in'] ) {
			return self::error( 'cb_dates', __( 'Dates invalides : le départ doit être après l’arrivée.', 'chalet-booking' ) );
		}
		$conflicts = CB_DB::query(
			array(
				'status' => CB_DB::BLOCKING_STATUSES,
				'from'   => $data['check_in'],
				'to'     => $data['check_out'],
			)
		);
		foreach ( $conflicts as $c ) {
			if ( (int) $c->id !== (int) $exclude_id ) {
				/* translators: 1: nom, 2: arrivée, 3: départ */
				return self::error( 'cb_conflict', sprintf( __( 'Ces dates chevauchent « %1$s » (%2$s → %3$s).', 'chalet-booking' ), $c->name ? $c->name : __( 'dates bloquées', 'chalet-booking' ), $c->check_in, $c->check_out ), 409 );
			}
		}
		return true;
	}

	public static function create_booking( WP_REST_Request $r ) {
		$type = in_array( $r->get_param( 'status' ), array( 'confirmed', 'pending', 'blocked' ), true ) ? $r->get_param( 'status' ) : 'confirmed';
		$data = self::read_fields( $r );
		$ok   = self::check_dates( $data );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$data['status'] = $type;
		$data['source'] = 'admin';
		if ( 'blocked' === $type ) {
			$data['adults'] = 0;
			$data['children'] = 0;
			$data['paid']   = 0;
		} else {
			$quote  = CB_Pricing::quote( $data['check_in'], $data['check_out'], max( 1, $data['adults'] ) );
			$manual = $r->get_param( 'total' );
			if ( null !== $manual && '' !== $manual ) {
				$data['total']         = round( (float) $manual, 2 );
				$data['price_details'] = abs( $data['total'] - $quote['total'] ) < 0.01 ? $quote : '';
			} else {
				$data['total']         = $quote['total'];
				$data['price_details'] = $quote;
			}
		}

		$id = CB_DB::insert( $data );
		if ( ! $id ) {
			return self::error( 'cb_db', __( 'Enregistrement impossible.', 'chalet-booking' ), 500 );
		}
		$b = CB_DB::get( $id );
		if ( 'confirmed' === $type && rest_sanitize_boolean( $r->get_param( 'notify' ) ) && is_email( $b->email ) ) {
			CB_Emails::confirmed( $b );
		}
		return self::format( $b );
	}

	public static function update_booking( WP_REST_Request $r ) {
		$b = CB_DB::get( (int) $r['id'] );
		if ( ! $b ) {
			return self::error( 'cb_not_found', __( 'Réservation introuvable.', 'chalet-booking' ), 404 );
		}
		if ( 'ical' === $b->source ) {
			return self::error( 'cb_ical', __( 'Cette réservation vient d’un calendrier externe : modifiez-la sur la plateforme d’origine.', 'chalet-booking' ) );
		}
		$data = self::read_fields( $r, $b );

		if ( ( $data['check_in'] !== $b->check_in || $data['check_out'] !== $b->check_out ) && in_array( $b->status, CB_DB::BLOCKING_STATUSES, true ) ) {
			$ok = self::check_dates( $data, $b->id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		if ( rest_sanitize_boolean( $r->get_param( 'recalculate' ) ) && 'blocked' !== $b->status ) {
			$quote                 = CB_Pricing::quote( $data['check_in'], $data['check_out'], max( 1, $data['adults'] ) );
			$data['total']         = $quote['total'];
			$data['price_details'] = $quote;
		} elseif ( null !== $r->get_param( 'total' ) && '' !== $r->get_param( 'total' ) ) {
			$total = round( (float) $r->get_param( 'total' ), 2 );
			if ( abs( $total - (float) $b->total ) >= 0.01 ) {
				$data['total']         = $total;
				$data['price_details'] = '';
			}
		}

		CB_DB::update( $b->id, $data );
		return self::format( CB_DB::get( $b->id ) );
	}

	public static function delete_booking( WP_REST_Request $r ) {
		$b = CB_DB::get( (int) $r['id'] );
		if ( ! $b ) {
			return self::error( 'cb_not_found', __( 'Réservation introuvable.', 'chalet-booking' ), 404 );
		}
		CB_DB::delete( $b->id );
		return array( 'deleted' => true );
	}

	public static function set_status( WP_REST_Request $r ) {
		$b      = CB_DB::get( (int) $r['id'] );
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		$notify = rest_sanitize_boolean( $r->get_param( 'notify' ) );
		if ( ! $b ) {
			return self::error( 'cb_not_found', __( 'Réservation introuvable.', 'chalet-booking' ), 404 );
		}
		if ( ! in_array( $status, array( 'pending', 'confirmed', 'cancelled' ), true ) || 'ical' === $b->source || 'blocked' === $b->status ) {
			return self::error( 'cb_status', __( 'Changement de statut impossible.', 'chalet-booking' ) );
		}
		// Réactiver une réservation annulée : vérifier que les dates sont toujours libres.
		if ( 'cancelled' === $b->status && 'cancelled' !== $status ) {
			$ok = self::check_dates( (array) array( 'check_in' => $b->check_in, 'check_out' => $b->check_out ), $b->id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		CB_DB::update_status( $b->id, $status );
		$fresh = CB_DB::get( $b->id );
		$sent  = null;
		if ( $notify && is_email( $fresh->email ) ) {
			if ( 'confirmed' === $status ) {
				$sent = CB_Emails::confirmed( $fresh );
			} elseif ( 'cancelled' === $status ) {
				$sent = CB_Emails::cancelled( $fresh );
			}
		}
		$out          = self::format( $fresh );
		$out['email'] = $fresh->email;
		$out['sent']  = $sent;
		return $out;
	}

	public static function email_guest( WP_REST_Request $r ) {
		$b = CB_DB::get( (int) $r['id'] );
		if ( ! $b || ! is_email( $b->email ) ) {
			return self::error( 'cb_email', __( 'Aucune adresse e-mail valide pour ce client.', 'chalet-booking' ) );
		}
		$template = sanitize_key( (string) $r->get_param( 'template' ) );
		if ( $template ) {
			if ( ! isset( CB_Emails::templates()[ $template ] ) ) {
				return self::error( 'cb_email', __( 'Modèle inconnu.', 'chalet-booking' ) );
			}
			$ok = CB_Emails::send_template( $template, $b, $b->email );
		} else {
			$subject = sanitize_text_field( (string) $r->get_param( 'subject' ) );
			$body    = sanitize_textarea_field( (string) $r->get_param( 'body' ) );
			if ( '' === $subject || '' === trim( $body ) ) {
				return self::error( 'cb_email', __( 'Objet et message obligatoires.', 'chalet-booking' ) );
			}
			$ok = CB_Emails::send_custom( $b->email, $subject, $body, $b );
		}
		if ( ! $ok ) {
			/* translators: %s: erreur */
			return self::error( 'cb_email', sprintf( __( 'Envoi impossible : %s', 'chalet-booking' ), CB_Emails::last_error() ), 500 );
		}
		return array( 'sent' => true );
	}

	public static function quote( WP_REST_Request $r ) {
		$in  = CB_Settings::sanitize_date( (string) $r->get_param( 'checkIn' ) );
		$out = CB_Settings::sanitize_date( (string) $r->get_param( 'checkOut' ) );
		if ( ! $in || ! $out || $out <= $in ) {
			return self::error( 'cb_dates', __( 'Dates invalides.', 'chalet-booking' ) );
		}
		$quote = CB_Pricing::quote( $in, $out, max( 1, absint( $r->get_param( 'adults' ) ) ) );
		// Avertissements sur les règles (non bloquants pour le propriétaire).
		$rules            = CB_Availability::validate_stay( $in, $out, max( 1, absint( $r->get_param( 'adults' ) ) ), absint( $r->get_param( 'children' ) ), false );
		$quote['warning'] = is_wp_error( $rules ) && 'cb_dates' !== $rules->get_error_code() ? $rules->get_error_message() : '';
		$free             = self::check_dates( array( 'check_in' => $in, 'check_out' => $out ), absint( $r->get_param( 'exclude' ) ) );
		$quote['conflict'] = is_wp_error( $free ) ? $free->get_error_message() : '';
		return $quote;
	}

	/* ------------------------------------------------------------------ */
	/* Tarifs                                                             */
	/* ------------------------------------------------------------------ */

	private static function pricing_keys() {
		return array( 'base_price', 'base_min_nights', 'cleaning_fee', 'tourist_tax', 'weekly_discount', 'max_guests', 'min_advance_days', 'payment_instructions' );
	}

	public static function get_pricing() {
		$s   = CB_Settings::get();
		$out = array();
		foreach ( self::pricing_keys() as $k ) {
			$out[ $k ] = $s[ $k ];
		}
		$out['seasons'] = CB_Settings::seasons();
		return $out;
	}

	public static function save_pricing( WP_REST_Request $r ) {
		$current = get_option( CB_Settings::OPTION, array() );
		$input   = wp_parse_args( is_array( $current ) ? $current : array(), CB_Settings::defaults() );
		foreach ( self::pricing_keys() as $k ) {
			if ( null !== $r->get_param( $k ) ) {
				$input[ $k ] = $r->get_param( $k );
			}
		}
		if ( is_array( $r->get_param( 'seasons' ) ) ) {
			$input['seasons'] = $r->get_param( 'seasons' );
		}
		update_option( CB_Settings::OPTION, CB_Settings::sanitize( $input ) );
		return self::get_pricing();
	}

	/* ------------------------------------------------------------------ */
	/* Statistiques et export                                             */
	/* ------------------------------------------------------------------ */

	public static function stats( WP_REST_Request $r ) {
		$year = absint( $r->get_param( 'year' ) );
		$year = $year >= 2000 && $year <= 2100 ? $year : (int) substr( self::today(), 0, 4 );

		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$first    = sprintf( '%04d-%02d-01', $year, $m );
			$next     = ( new DateTime( $first ) )->modify( '+1 month' )->format( 'Y-m-d' );
			$days     = (int) ( new DateTime( $first ) )->format( 't' );
			$months[] = array(
				'month'    => $m,
				'first'    => $first,
				'next'     => $next,
				'days'     => $days,
				'nights'   => 0,
				'revenue'  => 0,
				'bookings' => 0,
			);
		}

		$bookings = CB_DB::query(
			array(
				'status' => array( 'confirmed', 'blocked' ),
				'from'   => "{$year}-01-01",
				'to'     => ( $year + 1 ) . '-01-01',
			)
		);
		$total_nights  = 0;
		$total_revenue = 0;
		$count         = 0;
		$sources       = array( 'site' => 0, 'admin' => 0, 'ical' => 0 );

		foreach ( $bookings as $b ) {
			// Les blocages manuels (usage personnel, travaux) ne comptent pas comme occupation louée.
			if ( 'blocked' === $b->status && 'ical' !== $b->source ) {
				continue;
			}
			$nights = CB_Availability::nights( $b->check_in, $b->check_out );
			$per    = count( $nights ) ? (float) $b->total / count( $nights ) : 0;
			foreach ( $months as &$mo ) {
				foreach ( $nights as $n ) {
					if ( $n >= $mo['first'] && $n < $mo['next'] ) {
						$mo['nights']++;
						$mo['revenue'] += $per;
					}
				}
				if ( $b->check_in >= $mo['first'] && $b->check_in < $mo['next'] ) {
					$mo['bookings']++;
				}
			}
			unset( $mo );
			foreach ( $nights as $n ) {
				if ( substr( $n, 0, 4 ) === (string) $year ) {
					$total_nights++;
					$total_revenue += $per;
				}
			}
			if ( substr( $b->check_in, 0, 4 ) === (string) $year ) {
				$count++;
				$sources[ $b->source ] = ( $sources[ $b->source ] ?? 0 ) + 1;
			}
		}

		$days_in_year = (int) ( new DateTime( "{$year}-12-31" ) )->format( 'z' ) + 1;
		foreach ( $months as &$mo ) {
			$mo['revenue']   = round( $mo['revenue'], 2 );
			$mo['occupancy'] = round( 100 * $mo['nights'] / $mo['days'] );
			unset( $mo['first'], $mo['next'] );
		}
		unset( $mo );

		return array(
			'year'      => $year,
			'months'    => $months,
			'nights'    => $total_nights,
			'revenue'   => round( $total_revenue, 2 ),
			'bookings'  => $count,
			'occupancy' => round( 100 * $total_nights / $days_in_year ),
			'avgNight'  => $total_nights ? round( $total_revenue / max( 1, $total_nights - self::ical_nights( $bookings, $year ) ), 2 ) : 0,
			'sources'   => $sources,
		);
	}

	/** Nuits venant des calendriers externes (sans prix connu), exclues du prix moyen. */
	private static function ical_nights( $bookings, $year ) {
		$n = 0;
		foreach ( $bookings as $b ) {
			if ( 'ical' !== $b->source ) {
				continue;
			}
			foreach ( CB_Availability::nights( $b->check_in, $b->check_out ) as $night ) {
				if ( substr( $night, 0, 4 ) === (string) $year ) {
					$n++;
				}
			}
		}
		return $n;
	}

	public static function export( WP_REST_Request $r ) {
		$labels = array(
			'pending'   => 'En attente',
			'confirmed' => 'Confirmée',
			'cancelled' => 'Annulée',
			'blocked'   => 'Bloquée',
		);
		$rows   = CB_DB::query( array( 'order' => 'ASC' ) );
		$out    = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM pour Excel.
		$cols = array( 'N°', 'Statut', 'Origine', 'Arrivée', 'Départ', 'Nuits', 'Adultes', 'Enfants', 'Nom', 'E-mail', 'Téléphone', 'Total', 'Payé', 'Solde', 'Message', 'Notes', 'Créée le' );
		fputcsv( $out, $cols, ';' );
		foreach ( $rows as $b ) {
			$f = self::format( $b );
			fputcsv(
				$out,
				array(
					$f['id'],
					$labels[ $b->status ] ?? $b->status,
					$b->source,
					$b->check_in,
					$b->check_out,
					$f['nights'],
					$f['adults'],
					$f['children'],
					$b->name,
					$b->email,
					$b->phone,
					number_format( $f['total'], 2, '.', '' ),
					number_format( $f['paid'], 2, '.', '' ),
					number_format( $f['balance'], 2, '.', '' ),
					$b->message,
					$b->notes,
					$b->created_at,
				),
				';'
			);
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reservations-' . self::today() . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function sync() {
		$report = CB_ICal::sync();
		return array(
			'report' => $report,
			'time'   => time(),
		);
	}

	public static function email_log() {
		return array_slice( (array) get_option( CB_Emails::LOG, array() ), 0, 30 );
	}
}
