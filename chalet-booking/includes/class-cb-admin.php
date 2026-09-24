<?php
/**
 * Administration : liste des réservations, ajout/blocage de dates, réglages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cb_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_cb_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_cb_sync', array( __CLASS__, 'handle_sync' ) );
	}

	public static function menu() {
		global $wpdb;
		$table   = CB_DB::table();
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$badge   = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';

		add_menu_page( __( 'Réservations', 'chalet-booking' ), __( 'Réservations', 'chalet-booking' ) . $badge, self::CAP, 'chalet-booking', array( __CLASS__, 'page_bookings' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'chalet-booking', __( 'Réservations', 'chalet-booking' ), __( 'Toutes les réservations', 'chalet-booking' ), self::CAP, 'chalet-booking', array( __CLASS__, 'page_bookings' ) );
		add_submenu_page( 'chalet-booking', __( 'Ajouter / bloquer', 'chalet-booking' ), __( 'Ajouter / bloquer', 'chalet-booking' ), self::CAP, 'chalet-booking-add', array( __CLASS__, 'page_add' ) );
		add_submenu_page( 'chalet-booking', __( 'Réglages', 'chalet-booking' ), __( 'Réglages', 'chalet-booking' ), self::CAP, 'chalet-booking-settings', array( __CLASS__, 'page_settings' ) );
	}

	private static function status_labels() {
		return array(
			'pending'   => __( 'En attente', 'chalet-booking' ),
			'confirmed' => __( 'Confirmée', 'chalet-booking' ),
			'cancelled' => __( 'Annulée', 'chalet-booking' ),
			'blocked'   => __( 'Dates bloquées', 'chalet-booking' ),
		);
	}

	private static function action_url( $id, $do ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=cb_action&do=' . $do . '&id=' . (int) $id ),
			'cb_action_' . $do . '_' . (int) $id
		);
	}

	private static function notice() {
		if ( empty( $_GET['cb_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$messages = array(
			'confirmed' => __( 'Réservation confirmée, e-mail envoyé au client.', 'chalet-booking' ),
			'cancelled' => __( 'Réservation annulée.', 'chalet-booking' ),
			'deleted'   => __( 'Entrée supprimée.', 'chalet-booking' ),
			'added'     => __( 'Entrée ajoutée.', 'chalet-booking' ),
			'synced'    => __( 'Calendriers externes synchronisés.', 'chalet-booking' ),
			'conflict'  => __( 'Impossible : ces dates chevauchent une autre réservation.', 'chalet-booking' ),
			'invalid'   => __( 'Dates invalides.', 'chalet-booking' ),
		);
		$key   = sanitize_key( $_GET['cb_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = in_array( $key, array( 'conflict', 'invalid' ), true );
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error ? 'error' : 'success', esc_html( $messages[ $key ] ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Liste des réservations                                             */
	/* ------------------------------------------------------------------ */

	public static function page_bookings() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['view'] ) ) {
			self::page_view( absint( $_GET['view'] ) );
			return;
		}
		$labels = self::status_labels();
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'upcoming';
		// phpcs:enable

		$args = array( 'exclude_source' => 'ical' );
		if ( $status && isset( $labels[ $status ] ) ) {
			$args['status'] = $status;
		}
		if ( 'upcoming' === $period ) {
			$args['from'] = current_time( 'Y-m-d' );
		} else {
			$args['order'] = 'DESC';
		}
		$bookings = CB_DB::query( $args );
		$fmt      = get_option( 'date_format' );
		$base     = admin_url( 'admin.php?page=chalet-booking' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Réservations', 'chalet-booking' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=chalet-booking-add' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter / bloquer des dates', 'chalet-booking' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notice(); ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( add_query_arg( 'period', $period, $base ) ); ?>" <?php echo '' === $status ? 'class="current"' : ''; ?>><?php esc_html_e( 'Tous', 'chalet-booking' ); ?></a> |</li>
				<?php foreach ( $labels as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( array( 'status' => $key, 'period' => $period ), $base ) ); ?>" <?php echo $status === $key ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a> |</li>
				<?php endforeach; ?>
				<li>
					<?php if ( 'upcoming' === $period ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'status' => $status, 'period' => 'all' ), $base ) ); ?>"><?php esc_html_e( 'Afficher aussi les séjours passés', 'chalet-booking' ); ?></a>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'status' => $status, 'period' => 'upcoming' ), $base ) ); ?>"><?php esc_html_e( 'Séjours à venir uniquement', 'chalet-booking' ); ?></a>
					<?php endif; ?>
				</li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:60px">#</th>
						<th><?php esc_html_e( 'Statut', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Arrivée', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Départ', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Client', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Voyageurs', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Total', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'chalet-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $bookings ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'Aucune réservation.', 'chalet-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $bookings as $b ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'view', $b->id, $base ) ); ?>"><?php echo (int) $b->id; ?></a></td>
						<td><span class="cb-status cb-status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $labels[ $b->status ] ?? $b->status ); ?></span></td>
						<td><?php echo esc_html( date_i18n( $fmt, strtotime( $b->check_in ) ) ); ?></td>
						<td><?php echo esc_html( date_i18n( $fmt, strtotime( $b->check_out ) ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $b->name ); ?></strong>
							<?php if ( $b->email ) : ?><br><a href="mailto:<?php echo esc_attr( $b->email ); ?>"><?php echo esc_html( $b->email ); ?></a><?php endif; ?>
							<?php if ( $b->phone ) : ?><br><?php echo esc_html( $b->phone ); ?><?php endif; ?>
						</td>
						<td><?php echo 'blocked' === $b->status ? '—' : esc_html( $b->adults . ' + ' . $b->children ); ?></td>
						<td><?php echo 'blocked' === $b->status ? '—' : esc_html( CB_Settings::format_price( $b->total ) ); ?></td>
						<td><?php self::row_actions( $b ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<style>
				.cb-status{padding:2px 8px;border-radius:10px;font-size:12px;background:#eee}
				.cb-status-pending{background:#fff3cd}
				.cb-status-confirmed{background:#d4edda}
				.cb-status-cancelled{background:#f8d7da}
			</style>
		</div>
		<?php
	}

	private static function row_actions( $b ) {
		$links = array();
		if ( 'pending' === $b->status ) {
			$links[] = '<a class="button button-primary button-small" href="' . esc_url( self::action_url( $b->id, 'confirm' ) ) . '">' . esc_html__( 'Confirmer', 'chalet-booking' ) . '</a>';
		}
		if ( in_array( $b->status, array( 'pending', 'confirmed' ), true ) ) {
			$links[] = '<a class="button button-small" href="' . esc_url( self::action_url( $b->id, 'cancel' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Annuler cette réservation ?', 'chalet-booking' ) ) . '\')">' . esc_html__( 'Annuler', 'chalet-booking' ) . '</a>';
		}
		if ( in_array( $b->status, array( 'cancelled', 'blocked' ), true ) ) {
			$links[] = '<a class="button button-small button-link-delete" href="' . esc_url( self::action_url( $b->id, 'delete' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Supprimer définitivement ?', 'chalet-booking' ) ) . '\')">' . esc_html__( 'Supprimer', 'chalet-booking' ) . '</a>';
		}
		echo implode( ' ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function page_view( $id ) {
		$b = CB_DB::get( $id );
		if ( ! $b ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Réservation introuvable.', 'chalet-booking' ) . '</p></div>';
			return;
		}
		$labels  = self::status_labels();
		$fmt     = get_option( 'date_format' );
		$details = json_decode( (string) $b->price_details, true );
		?>
		<div class="wrap">
			<h1><?php /* translators: %d: numéro */ printf( esc_html__( 'Réservation #%d', 'chalet-booking' ), (int) $b->id ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=chalet-booking' ) ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'chalet-booking' ); ?></a></p>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Statut', 'chalet-booking' ); ?></th><td><?php echo esc_html( $labels[ $b->status ] ?? $b->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Dates', 'chalet-booking' ); ?></th><td><?php echo esc_html( date_i18n( $fmt, strtotime( $b->check_in ) ) . ' → ' . date_i18n( $fmt, strtotime( $b->check_out ) ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Client', 'chalet-booking' ); ?></th><td><?php echo esc_html( $b->name ); ?><br><a href="mailto:<?php echo esc_attr( $b->email ); ?>"><?php echo esc_html( $b->email ); ?></a><br><?php echo esc_html( $b->phone ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Voyageurs', 'chalet-booking' ); ?></th><td><?php /* translators: 1: adultes, 2: enfants */ printf( esc_html__( '%1$d adulte(s), %2$d enfant(s)', 'chalet-booking' ), (int) $b->adults, (int) $b->children ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Message', 'chalet-booking' ); ?></th><td><?php echo nl2br( esc_html( (string) $b->message ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Prix', 'chalet-booking' ); ?></th><td>
					<?php if ( ! empty( $details['lines'] ) ) : ?>
						<?php foreach ( $details['lines'] as $line ) : ?>
							<?php echo esc_html( $line['label'] . ' — ' . CB_Settings::format_price( $line['amount'] ) ); ?><br>
						<?php endforeach; ?>
					<?php endif; ?>
					<strong><?php echo esc_html( CB_Settings::format_price( $b->total ) ); ?></strong>
				</td></tr>
				<tr><th><?php esc_html_e( 'Reçue le', 'chalet-booking' ); ?></th><td><?php echo esc_html( mysql2date( $fmt . ' H:i', $b->created_at ) ); ?></td></tr>
			</table>
			<p><?php self::row_actions( $b ); ?></p>
		</div>
		<?php
	}

	public static function handle_action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cb_action_' . $do . '_' . $id ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		$b   = CB_DB::get( $id );
		$msg = '';
		if ( $b ) {
			switch ( $do ) {
				case 'confirm':
					CB_DB::update_status( $id, 'confirmed' );
					CB_Emails::confirmed( CB_DB::get( $id ) );
					$msg = 'confirmed';
					break;
				case 'cancel':
					CB_DB::update_status( $id, 'cancelled' );
					if ( 'site' === $b->source && 'pending' === $b->status ) {
						CB_Emails::cancelled( $b );
					}
					$msg = 'cancelled';
					break;
				case 'delete':
					CB_DB::delete( $id );
					$msg = 'deleted';
					break;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking&cb_msg=' . $msg ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Ajout manuel / blocage                                             */
	/* ------------------------------------------------------------------ */

	public static function page_add() {
		$max = (int) CB_Settings::get( 'max_guests' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ajouter une réservation ou bloquer des dates', 'chalet-booking' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'Pour une réservation prise par téléphone, choisissez « Réservation confirmée ». Pour un usage personnel ou des travaux, choisissez « Dates bloquées ».', 'chalet-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_add">
				<?php wp_nonce_field( 'cb_add' ); ?>
				<table class="form-table">
					<tr><th><label for="cb-type"><?php esc_html_e( 'Type', 'chalet-booking' ); ?></label></th><td>
						<select name="type" id="cb-type">
							<option value="blocked"><?php esc_html_e( 'Dates bloquées', 'chalet-booking' ); ?></option>
							<option value="confirmed"><?php esc_html_e( 'Réservation confirmée', 'chalet-booking' ); ?></option>
						</select>
					</td></tr>
					<tr><th><label for="cb-in"><?php esc_html_e( 'Arrivée', 'chalet-booking' ); ?></label></th><td><input type="date" id="cb-in" name="check_in" required></td></tr>
					<tr><th><label for="cb-out"><?php esc_html_e( 'Départ', 'chalet-booking' ); ?></label></th><td><input type="date" id="cb-out" name="check_out" required></td></tr>
					<tr><th><label for="cb-name"><?php esc_html_e( 'Nom / note', 'chalet-booking' ); ?></label></th><td><input type="text" id="cb-name" name="name" class="regular-text"></td></tr>
					<tr><th><label for="cb-email"><?php esc_html_e( 'E-mail', 'chalet-booking' ); ?></label></th><td><input type="email" id="cb-email" name="email" class="regular-text"></td></tr>
					<tr><th><label for="cb-phone"><?php esc_html_e( 'Téléphone', 'chalet-booking' ); ?></label></th><td><input type="text" id="cb-phone" name="phone" class="regular-text"></td></tr>
					<tr><th><?php esc_html_e( 'Voyageurs', 'chalet-booking' ); ?></th><td>
						<input type="number" name="adults" min="0" max="<?php echo (int) $max; ?>" value="2" class="small-text"> <?php esc_html_e( 'adultes', 'chalet-booking' ); ?>
						<input type="number" name="children" min="0" max="<?php echo (int) $max; ?>" value="0" class="small-text"> <?php esc_html_e( 'enfants', 'chalet-booking' ); ?>
					</td></tr>
					<tr><th><label for="cb-total"><?php esc_html_e( 'Prix total', 'chalet-booking' ); ?></label></th><td><input type="number" step="0.01" min="0" id="cb-total" name="total" class="regular-text" placeholder="<?php esc_attr_e( 'Vide = calcul automatique', 'chalet-booking' ); ?>"></td></tr>
					<tr><th><label for="cb-msg"><?php esc_html_e( 'Notes internes', 'chalet-booking' ); ?></label></th><td><textarea id="cb-msg" name="message" rows="3" class="large-text"></textarea></td></tr>
					<tr><th><?php esc_html_e( 'E-mail au client', 'chalet-booking' ); ?></th><td><label><input type="checkbox" name="notify" value="1"> <?php esc_html_e( 'Envoyer l’e-mail de confirmation (réservation confirmée uniquement)', 'chalet-booking' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Enregistrer', 'chalet-booking' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_add() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cb_add' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		$back      = admin_url( 'admin.php?page=chalet-booking-add' );
		$type      = ( isset( $_POST['type'] ) && 'confirmed' === $_POST['type'] ) ? 'confirmed' : 'blocked';
		$check_in  = CB_Settings::sanitize_date( sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) ) );
		$check_out = CB_Settings::sanitize_date( sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) ) );

		if ( ! $check_in || ! $check_out || $check_out <= $check_in ) {
			wp_safe_redirect( add_query_arg( 'cb_msg', 'invalid', $back ) );
			exit;
		}
		if ( ! CB_Availability::is_available( $check_in, $check_out ) ) {
			wp_safe_redirect( add_query_arg( 'cb_msg', 'conflict', $back ) );
			exit;
		}

		$adults = absint( $_POST['adults'] ?? 0 );
		$data   = array(
			'status'    => $type,
			'source'    => 'admin',
			'check_in'  => $check_in,
			'check_out' => $check_out,
			'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'     => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'     => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'message'   => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
		);
		if ( 'confirmed' === $type ) {
			$quote                 = CB_Pricing::quote( $check_in, $check_out, $adults );
			$manual                = isset( $_POST['total'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['total'] ) ) ) : '';
			$data['adults']        = $adults;
			$data['children']      = absint( $_POST['children'] ?? 0 );
			$data['total']         = '' !== $manual ? (float) $manual : $quote['total'];
			$data['price_details'] = '' !== $manual ? '' : $quote;
		}
		$id = CB_DB::insert( $data );

		if ( $id && 'confirmed' === $type && ! empty( $_POST['notify'] ) && is_email( $data['email'] ) ) {
			CB_Emails::confirmed( CB_DB::get( $id ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking&cb_msg=added' ) );
		exit;
	}

	public static function handle_sync() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cb_sync' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		CB_ICal::sync();
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking-settings&cb_msg=synced' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Réglages                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_settings() {
		$s    = CB_Settings::get();
		$name = CB_Settings::OPTION;
		$days = CB_Settings::weekday_names();
		$last = get_option( 'cb_ical_last_sync' );

		$field = function ( $key, $label, $type = 'text', $desc = '', $attrs = '' ) use ( $s, $name ) {
			printf(
				'<tr><th><label for="cb-%1$s">%2$s</label></th><td><input type="%3$s" id="cb-%1$s" name="%4$s[%1$s]" value="%5$s" class="%6$s" %7$s>%8$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $name ),
				esc_attr( $s[ $key ] ),
				'number' === $type ? 'small-text' : 'regular-text',
				$attrs, // phpcs:ignore WordPress.Security.EscapeOutput
				$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Réglages du chalet', 'chalet-booking' ); ?></h1>
			<?php settings_errors(); ?>
			<?php self::notice(); ?>
			<p><?php printf( /* translators: %s: shortcode */ esc_html__( 'Insérez le shortcode %s dans une page pour afficher le calendrier et le formulaire de réservation.', 'chalet-booking' ), '<code>[chalet_booking]</code>' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cb_settings_group' ); ?>

				<h2><?php esc_html_e( 'Chalet', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php
					$field( 'chalet_name', __( 'Nom du chalet', 'chalet-booking' ) );
					$field( 'max_guests', __( 'Capacité maximale (personnes)', 'chalet-booking' ), 'number', '', 'min="1"' );
					$field( 'check_in_time', __( 'Heure d’arrivée', 'chalet-booking' ), 'time' );
					$field( 'check_out_time', __( 'Heure de départ', 'chalet-booking' ), 'time' );
					$field( 'admin_email', __( 'E-mail de notification', 'chalet-booking' ), 'email', __( 'Reçoit les nouvelles demandes de réservation.', 'chalet-booking' ) );
					$field( 'terms_url', __( 'Lien vers les conditions de location', 'chalet-booking' ), 'url', __( 'Optionnel : si renseigné, le client doit cocher une case d’acceptation.', 'chalet-booking' ) );
					?>
					<tr><th><label for="cb-payment"><?php esc_html_e( 'Modalités de paiement', 'chalet-booking' ); ?></label></th><td>
						<textarea id="cb-payment" name="<?php echo esc_attr( $name ); ?>[payment_instructions]" rows="5" class="large-text"><?php echo esc_textarea( $s['payment_instructions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Ajoutées à l’e-mail de confirmation (ex. acompte de 30 % sous 7 jours, IBAN, solde 30 jours avant l’arrivée, caution…).', 'chalet-booking' ); ?></p>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Tarifs et règles', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php
					$field( 'currency', __( 'Devise', 'chalet-booking' ), 'text', '', 'maxlength="3" style="width:5em"' );
					$field( 'base_price', __( 'Prix par nuit hors saison', 'chalet-booking' ), 'number', __( 'Utilisé pour toute date qui n’est dans aucune saison ci-dessous.', 'chalet-booking' ), 'min="0" step="0.01"' );
					$field( 'base_min_nights', __( 'Nuits minimum hors saison', 'chalet-booking' ), 'number', '', 'min="1"' );
					$field( 'cleaning_fee', __( 'Frais de nettoyage final', 'chalet-booking' ), 'number', '', 'min="0" step="0.01"' );
					$field( 'tourist_tax', __( 'Taxe de séjour par adulte et par nuit', 'chalet-booking' ), 'number', __( 'Vérifiez le montant en vigueur auprès de la commune de Val de Bagnes / Verbier Tourisme. 0 = non facturée.', 'chalet-booking' ), 'min="0" step="0.01"' );
					$field( 'weekly_discount', __( 'Rabais dès 7 nuits (%)', 'chalet-booking' ), 'number', '', 'min="0" max="100" step="0.1"' );
					$field( 'min_advance_days', __( 'Délai minimum avant l’arrivée (jours)', 'chalet-booking' ), 'number', '', 'min="0"' );
					$field( 'max_months_ahead', __( 'Réservable jusqu’à (mois à l’avance)', 'chalet-booking' ), 'number', '', 'min="1"' );
					?>
				</table>

				<h2><?php esc_html_e( 'Saisons', 'chalet-booking' ); ?></h2>
				<p><?php esc_html_e( 'Définissez vos périodes tarifaires (Noël / Nouvel An, vacances de février, haute saison d’hiver, Pâques, été, Verbier Festival…). Les dates sont incluses. Le « jour d’arrivée » impose par exemple des séjours samedi → samedi.', 'chalet-booking' ); ?></p>
				<table class="widefat striped" id="cb-seasons">
					<thead><tr>
						<th><?php esc_html_e( 'Nom', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Du', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Au', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Prix / nuit', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Nuits min.', 'chalet-booking' ); ?></th>
						<th><?php esc_html_e( 'Jour d’arrivée/départ', 'chalet-booking' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php
					$rows   = CB_Settings::seasons();
					$rows[] = null; // Ligne modèle vide.
					foreach ( $rows as $i => $row ) :
						$tpl = null === $row;
						$row = $row ?: array( 'name' => '', 'start' => '', 'end' => '', 'price' => '', 'min_nights' => 7, 'arrival_day' => -1 );
						$p   = $tpl ? '__i__' : $i;
						?>
						<tr <?php echo $tpl ? 'class="cb-season-tpl" hidden' : ''; ?>>
							<td><input type="text" data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][name]" ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="<?php esc_attr_e( 'ex. Noël / Nouvel An', 'chalet-booking' ); ?>"></td>
							<td><input type="date" data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][start]" ); ?>" value="<?php echo esc_attr( $row['start'] ); ?>"></td>
							<td><input type="date" data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][end]" ); ?>" value="<?php echo esc_attr( $row['end'] ); ?>"></td>
							<td><input type="number" min="0" step="0.01" style="width:7em" data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][price]" ); ?>" value="<?php echo esc_attr( $row['price'] ); ?>"></td>
							<td><input type="number" min="1" style="width:5em" data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][min_nights]" ); ?>" value="<?php echo esc_attr( $row['min_nights'] ); ?>"></td>
							<td><select data-name="<?php echo esc_attr( "{$name}[seasons][{$p}][arrival_day]" ); ?>">
								<?php foreach ( $days as $k => $label ) : ?>
									<option value="<?php echo (int) $k; ?>" <?php selected( (int) $row['arrival_day'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><button type="button" class="button-link button-link-delete cb-remove-season"><?php esc_html_e( 'Supprimer', 'chalet-booking' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="cb-add-season"><?php esc_html_e( '+ Ajouter une saison', 'chalet-booking' ); ?></button></p>

				<h2><?php esc_html_e( 'Synchronisation des calendriers (iCal)', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Lien d’export', 'chalet-booking' ); ?></th><td>
						<input type="text" readonly class="large-text code" value="<?php echo esc_attr( CB_ICal::feed_url() ); ?>" onclick="this.select()">
						<p class="description"><?php esc_html_e( 'À coller dans Airbnb, Booking.com, etc. (« Importer un calendrier ») pour qu’ils bloquent les dates réservées ici.', 'chalet-booking' ); ?></p>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[regenerate_token]" value="1"> <?php esc_html_e( 'Générer un nouveau lien (l’ancien cessera de fonctionner)', 'chalet-booking' ); ?></label>
					</td></tr>
					<tr><th><label for="cb-ical"><?php esc_html_e( 'Calendriers à importer', 'chalet-booking' ); ?></label></th><td>
						<textarea id="cb-ical" name="<?php echo esc_attr( $name ); ?>[ical_import_urls]" rows="4" class="large-text code" placeholder="https://www.airbnb.ch/calendar/ical/….ics"><?php echo esc_textarea( $s['ical_import_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Une URL .ics par ligne (Airbnb, Booking.com, Google Agenda…). Les dates sont bloquées ici automatiquement, synchronisation toutes les heures.', 'chalet-booking' ); ?></p>
					</td></tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php if ( $s['ical_import_urls'] ) : ?>
				<h3><?php esc_html_e( 'Dernière synchronisation', 'chalet-booking' ); ?></h3>
				<?php if ( $last ) : ?>
					<p><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $last['time'] ) ); ?></p>
					<ul>
						<?php foreach ( (array) $last['report'] as $url => $result ) : ?>
							<li><code><?php echo esc_html( $url ); ?></code> — <?php echo is_int( $result ) ? esc_html( sprintf( /* translators: %d: nombre */ _n( '%d événement', '%d événements', $result, 'chalet-booking' ), $result ) ) : '<strong style="color:#b32d2e">' . esc_html( $result ) . '</strong>'; ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="cb_sync">
					<?php wp_nonce_field( 'cb_sync' ); ?>
					<?php submit_button( __( 'Synchroniser maintenant', 'chalet-booking' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var tbody = document.querySelector( '#cb-seasons tbody' );
			var tpl = tbody.querySelector( '.cb-season-tpl' );
			var n = tbody.querySelectorAll( 'tr:not(.cb-season-tpl)' ).length;
			// Seules les lignes visibles reçoivent un attribut name (la ligne modèle n'est pas envoyée).
			function activate( row ) {
				row.querySelectorAll( '[data-name]' ).forEach( function ( el ) {
					el.name = el.getAttribute( 'data-name' ).replace( '__i__', n );
				} );
				n++;
			}
			tbody.querySelectorAll( 'tr:not(.cb-season-tpl)' ).forEach( function ( row ) {
				row.querySelectorAll( '[data-name]' ).forEach( function ( el ) {
					el.name = el.getAttribute( 'data-name' );
				} );
			} );
			document.getElementById( 'cb-add-season' ).addEventListener( 'click', function () {
				var row = tpl.cloneNode( true );
				row.hidden = false;
				row.classList.remove( 'cb-season-tpl' );
				activate( row );
				tbody.insertBefore( row, tpl );
			} );
			tbody.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'cb-remove-season' ) ) {
					e.target.closest( 'tr' ).remove();
				}
			} );
		} )();
		</script>
		<?php
	}
}
