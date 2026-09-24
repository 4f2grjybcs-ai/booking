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
		add_action( 'admin_post_cb_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
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
		add_submenu_page( 'chalet-booking', __( 'E-mails', 'chalet-booking' ), __( 'E-mails', 'chalet-booking' ), self::CAP, 'chalet-booking-emails', array( __CLASS__, 'page_emails' ) );
		add_submenu_page( 'chalet-booking', __( 'Photos & conditions', 'chalet-booking' ), __( 'Photos & conditions', 'chalet-booking' ), self::CAP, 'chalet-booking-content', array( __CLASS__, 'page_content' ) );
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
			'mail_ok'   => __( 'E-mail de test envoyé. Vérifiez votre boîte de réception (et le dossier spam).', 'chalet-booking' ),
			'mail_fail' => __( 'Échec de l’envoi de l’e-mail de test. Voir le détail dans le journal ci-dessous.', 'chalet-booking' ),
			'conflict'  => __( 'Impossible : ces dates chevauchent une autre réservation.', 'chalet-booking' ),
			'invalid'   => __( 'Dates invalides.', 'chalet-booking' ),
		);
		$key   = sanitize_key( $_GET['cb_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = in_array( $key, array( 'conflict', 'invalid', 'mail_fail' ), true );
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

			<div class="card" style="max-width:900px">
				<h2><?php esc_html_e( 'Back-office (gestion sans WordPress)', 'chalet-booking' ); ?></h2>
				<?php $bo = CB_Backoffice::url(); ?>
				<?php if ( $bo ) : ?>
					<p><?php esc_html_e( 'Votre espace de gestion :', 'chalet-booking' ); ?> <a href="<?php echo esc_url( $bo ); ?>" target="_blank"><strong><?php echo esc_html( $bo ); ?></strong></a></p>
					<p class="description"><?php esc_html_e( 'Ajoutez-le à l’écran d’accueil de votre téléphone pour l’utiliser comme une application.', 'chalet-booking' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Créez la page de gestion (tableau de bord, calendrier, réservations, paiements, tarifs, statistiques) accessible depuis votre site, sur ordinateur ou téléphone.', 'chalet-booking' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cb_create_backoffice_page">
						<?php wp_nonce_field( 'cb_create_backoffice_page' ); ?>
						<?php submit_button( __( 'Créer la page de gestion', 'chalet-booking' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: lien vers Utilisateurs */
						esc_html__( 'Pour donner accès à une autre personne (conciergerie, famille…) sans lui ouvrir WordPress : %s et choisissez le rôle « Gestionnaire du chalet ».', 'chalet-booking' ),
						'<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'créez un utilisateur', 'chalet-booking' ) . '</a>'
					);
					?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'cb_settings_group' ); ?>

				<h2><?php esc_html_e( 'Chalet', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php
					$field( 'chalet_name', __( 'Nom du chalet', 'chalet-booking' ) );
					$field( 'max_guests', __( 'Capacité maximale (personnes)', 'chalet-booking' ), 'number', '', 'min="1"' );
					$field( 'check_in_time', __( 'Heure d’arrivée', 'chalet-booking' ), 'time' );
					$field( 'check_out_time', __( 'Heure de départ', 'chalet-booking' ), 'time' );
					$field( 'admin_email', __( 'E-mail de notification', 'chalet-booking' ), 'email', __( 'Reçoit les nouvelles demandes de réservation. Expéditeur et contenu des e-mails : menu Réservations → E-mails.', 'chalet-booking' ) );
					$field( 'terms_url', __( 'Lien vers les conditions de location', 'chalet-booking' ), 'url', __( 'Optionnel : lien vers une page externe. Sinon, rédigez vos conditions dans Réservations → Photos & conditions.', 'chalet-booking' ) );
					?>
					<tr><th><label for="cb-payment"><?php esc_html_e( 'Modalités de paiement', 'chalet-booking' ); ?></label></th><td>
						<textarea id="cb-payment" name="<?php echo esc_attr( $name ); ?>[payment_instructions]" rows="5" class="large-text"><?php echo esc_textarea( $s['payment_instructions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Ajoutées à l’e-mail de confirmation (ex. acompte de 30 % sous 7 jours, IBAN, solde 30 jours avant l’arrivée, caution…).', 'chalet-booking' ); ?></p>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Langues du site', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Langues proposées', 'chalet-booking' ); ?></th><td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[languages_present]" value="1">
						<label><input type="checkbox" checked disabled> Français <?php esc_html_e( '(langue principale)', 'chalet-booking' ); ?></label><br>
						<?php foreach ( array( 'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español' ) as $code => $label ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[languages][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $s['languages'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Traduisez vos propres textes (description, conditions, titres de pages…) dans Réservations → Traductions.', 'chalet-booking' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Bouton de langue', 'chalet-booking' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[language_button]" value="1" <?php checked( $s['language_button'] ); ?>> <?php esc_html_e( 'Afficher le bouton 🌐 de changement de langue en bas à gauche de toutes les pages', 'chalet-booking' ); ?></label>
						<p class="description"><?php printf( /* translators: %s: shortcode */ esc_html__( 'Vous pouvez aussi placer le sélecteur où vous voulez (ex. dans l’en-tête) avec le shortcode %s.', 'chalet-booking' ), '<code>[chalet_langues]</code>' ); ?></p>
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

	/* ------------------------------------------------------------------ */
	/* E-mails                                                            */
	/* ------------------------------------------------------------------ */

	public static function enqueue( $hook ) {
		if ( false !== strpos( $hook, 'chalet-booking-content' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
	}

	public static function page_emails() {
		$s     = CB_Emails::get();
		$name  = CB_Emails::OPTION;
		$log   = get_option( CB_Emails::LOG, array() );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = preg_replace( '/^www\./', '', (string) $host );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-mails automatiques', 'chalet-booking' ); ?></h1>
			<?php settings_errors(); ?>
			<?php self::notice(); ?>

			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Vous ne recevez pas les e-mails ? Par défaut, WordPress envoie les e-mails via le serveur de l’hébergeur, souvent bloqué ou classé en spam. La solution fiable est d’envoyer via le SMTP de votre messagerie (Infomaniak, Gmail, Microsoft 365…) : remplissez la section SMTP ci-dessous, puis utilisez « Envoyer un e-mail de test ».', 'chalet-booking' ); ?>
			</p></div>

			<form method="post" action="options.php">
				<?php settings_fields( 'cb_email_settings_group' ); ?>

				<h2><?php esc_html_e( 'Expéditeur', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<tr><th><label for="cb-from-name"><?php esc_html_e( 'Nom de l’expéditeur', 'chalet-booking' ); ?></label></th><td>
						<input type="text" id="cb-from-name" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( CB_Settings::get( 'chalet_name' ) ); ?>">
					</td></tr>
					<tr><th><label for="cb-from-email"><?php esc_html_e( 'Adresse de l’expéditeur', 'chalet-booking' ); ?></label></th><td>
						<input type="email" id="cb-from-email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="reservation@<?php echo esc_attr( $host ); ?>">
						<p class="description"><?php esc_html_e( 'Utilisez une adresse de votre propre domaine (celle du SMTP si vous en configurez un), sinon les e-mails risquent d’arriver en spam. Les réponses des clients vont à l’e-mail de notification défini dans les Réglages.', 'chalet-booking' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Copie', 'chalet-booking' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[bcc_admin]" value="1" <?php checked( $s['bcc_admin'] ); ?>> <?php esc_html_e( 'Recevoir en copie cachée les e-mails envoyés aux clients', 'chalet-booking' ); ?></label>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Contenu des e-mails', 'chalet-booking' ); ?></h2>
				<p><?php esc_html_e( 'Variables disponibles (remplacées automatiquement) :', 'chalet-booking' ); ?></p>
				<p style="columns:2;max-width:900px">
					<?php foreach ( CB_Emails::placeholders() as $tag => $label ) : ?>
						<code><?php echo esc_html( $tag ); ?></code> <?php echo esc_html( $label ); ?><br>
					<?php endforeach; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Vider un champ puis enregistrer rétablit le texte par défaut.', 'chalet-booking' ); ?></p>

				<?php foreach ( CB_Emails::templates() as $id => $tpl ) : $t = $s['templates'][ $id ]; ?>
					<div class="card" style="max-width:900px;margin-top:16px">
						<h3 style="margin-top:.5em"><?php echo esc_html( $tpl['label'] ); ?></h3>
						<p class="description"><?php echo esc_html( $tpl['desc'] ); ?></p>
						<p><label><input type="checkbox" name="<?php echo esc_attr( "{$name}[templates][{$id}][enabled]" ); ?>" value="1" <?php checked( $t['enabled'] ); ?>> <?php esc_html_e( 'Activé', 'chalet-booking' ); ?></label></p>
						<p><label><?php esc_html_e( 'Objet', 'chalet-booking' ); ?><br>
							<input type="text" class="large-text" name="<?php echo esc_attr( "{$name}[templates][{$id}][subject]" ); ?>" value="<?php echo esc_attr( $t['subject'] ); ?>"></label></p>
						<p><label><?php esc_html_e( 'Message', 'chalet-booking' ); ?><br>
							<textarea class="large-text" rows="10" name="<?php echo esc_attr( "{$name}[templates][{$id}][body]" ); ?>"><?php echo esc_textarea( $t['body'] ); ?></textarea></label></p>
					</div>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Serveur d’envoi SMTP (recommandé)', 'chalet-booking' ); ?></h2>
				<p><?php esc_html_e( 'Laissez vide si vous utilisez déjà une extension SMTP (WP Mail SMTP, FluentSMTP…). Exemples : Infomaniak mail.infomaniak.com port 587 TLS ; Gmail smtp.gmail.com port 587 TLS avec un « mot de passe d’application ».', 'chalet-booking' ); ?></p>
				<table class="form-table">
					<tr><th><label for="cb-smtp-host"><?php esc_html_e( 'Serveur', 'chalet-booking' ); ?></label></th><td><input type="text" id="cb-smtp-host" class="regular-text" name="<?php echo esc_attr( $name ); ?>[smtp_host]" value="<?php echo esc_attr( $s['smtp_host'] ); ?>" placeholder="mail.infomaniak.com"></td></tr>
					<tr><th><label for="cb-smtp-port"><?php esc_html_e( 'Port', 'chalet-booking' ); ?></label></th><td><input type="number" id="cb-smtp-port" class="small-text" name="<?php echo esc_attr( $name ); ?>[smtp_port]" value="<?php echo esc_attr( $s['smtp_port'] ); ?>"></td></tr>
					<tr><th><label for="cb-smtp-secure"><?php esc_html_e( 'Chiffrement', 'chalet-booking' ); ?></label></th><td>
						<select id="cb-smtp-secure" name="<?php echo esc_attr( $name ); ?>[smtp_secure]">
							<option value="tls" <?php selected( $s['smtp_secure'], 'tls' ); ?>>TLS (587)</option>
							<option value="ssl" <?php selected( $s['smtp_secure'], 'ssl' ); ?>>SSL (465)</option>
							<option value="" <?php selected( $s['smtp_secure'], '' ); ?>><?php esc_html_e( 'Aucun', 'chalet-booking' ); ?></option>
						</select>
					</td></tr>
					<tr><th><label for="cb-smtp-user"><?php esc_html_e( 'Identifiant', 'chalet-booking' ); ?></label></th><td><input type="text" id="cb-smtp-user" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[smtp_user]" value="<?php echo esc_attr( $s['smtp_user'] ); ?>"></td></tr>
					<tr><th><label for="cb-smtp-pass"><?php esc_html_e( 'Mot de passe', 'chalet-booking' ); ?></label></th><td>
						<input type="password" id="cb-smtp-pass" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[smtp_pass]" value="" placeholder="<?php echo $s['smtp_pass'] ? esc_attr__( '•••••••• (enregistré — laisser vide pour conserver)', 'chalet-booking' ) : ''; ?>">
						<?php if ( $s['smtp_pass'] ) : ?>
							<br><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[smtp_clear_pass]" value="1"> <?php esc_html_e( 'Effacer le mot de passe enregistré', 'chalet-booking' ); ?></label>
						<?php endif; ?>
					</td></tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Envoyer un e-mail de test', 'chalet-booking' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_test_email">
				<?php wp_nonce_field( 'cb_test_email' ); ?>
				<p>
					<select name="template">
						<?php foreach ( CB_Emails::templates() as $id => $tpl ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $tpl['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="lang">
						<?php foreach ( CB_I18n::languages() as $code => $l ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $l['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="email" name="to" class="regular-text" value="<?php echo esc_attr( CB_Settings::get( 'admin_email' ) ); ?>" required>
					<?php submit_button( __( 'Envoyer le test', 'chalet-booking' ), 'secondary', 'submit', false ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Enregistrez vos modifications avant de tester. Le test utilise une réservation fictive.', 'chalet-booking' ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Journal des derniers envois', 'chalet-booking' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th><?php esc_html_e( 'Date', 'chalet-booking' ); ?></th><th><?php esc_html_e( 'Destinataire', 'chalet-booking' ); ?></th><th><?php esc_html_e( 'Objet', 'chalet-booking' ); ?></th><th><?php esc_html_e( 'Résultat', 'chalet-booking' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Aucun envoi pour le moment.', 'chalet-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $row['time'] ) ); ?></td>
						<td><?php echo esc_html( $row['to'] ); ?></td>
						<td><?php echo esc_html( $row['subject'] ); ?></td>
						<td><?php echo $row['ok'] ? '<span style="color:#00a32a">✔ ' . esc_html__( 'Envoyé', 'chalet-booking' ) . '</span>' : '<span style="color:#b32d2e">✘ ' . esc_html( $row['error'] ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( '« Envoyé » signifie que le serveur a accepté l’e-mail. S’il n’arrive pas, regardez le dossier spam et configurez le SMTP.', 'chalet-booking' ); ?></p>
		</div>
		<?php
	}

	public static function handle_test_email() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'cb_test_email' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		$to       = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		$template = sanitize_key( $_POST['template'] ?? 'guest_request' );
		$sample       = CB_Emails::sample_booking();
		$sample->lang = array_key_exists( sanitize_key( $_POST['lang'] ?? '' ), CB_I18n::languages() ) ? sanitize_key( $_POST['lang'] ) : 'fr';
		$ok           = is_email( $to ) && CB_Emails::send_template( $template, $sample, $to );
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking-emails&cb_msg=' . ( $ok ? 'mail_ok' : 'mail_fail' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Photos & conditions                                                */
	/* ------------------------------------------------------------------ */

	public static function page_content() {
		$c    = CB_Content::get();
		$name = CB_Content::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Photos & conditions générales', 'chalet-booking' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'cb_content_group' ); ?>

				<h2><?php esc_html_e( 'Galerie photos', 'chalet-booking' ); ?></h2>
				<p><?php printf( /* translators: %s: shortcode */ esc_html__( 'Affichez la galerie avec le shortcode %s (options : columns="4", limit="6"). Glissez-déposez les photos pour changer l’ordre ; la première est mise en avant.', 'chalet-booking' ), '<code>[chalet_gallery]</code>' ); ?></p>
				<input type="hidden" id="cb-gallery-ids" name="<?php echo esc_attr( $name ); ?>[gallery_ids]" value="<?php echo esc_attr( implode( ',', $c['gallery_ids'] ) ); ?>">
				<ul id="cb-gallery-list">
					<?php foreach ( $c['gallery_ids'] as $id ) : ?>
						<li data-id="<?php echo (int) $id; ?>"><?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?><button type="button" class="cb-gallery-remove" aria-label="<?php esc_attr_e( 'Retirer', 'chalet-booking' ); ?>">&times;</button></li>
					<?php endforeach; ?>
				</ul>
				<p><button type="button" class="button" id="cb-gallery-add"><?php esc_html_e( 'Ajouter des photos', 'chalet-booking' ); ?></button></p>

				<h2><?php esc_html_e( 'Conditions générales de location', 'chalet-booking' ); ?></h2>
				<p><?php printf( /* translators: %s: shortcode */ esc_html__( 'Affichez-les sur une page avec %s. Dans le formulaire de réservation, le client peut les dérouler et doit les accepter.', 'chalet-booking' ), '<code>[chalet_terms]</code>' ); ?></p>
				<table class="form-table">
					<tr><th><label for="cb-terms-title"><?php esc_html_e( 'Titre', 'chalet-booking' ); ?></label></th><td><input type="text" id="cb-terms-title" class="regular-text" name="<?php echo esc_attr( $name ); ?>[terms_title]" value="<?php echo esc_attr( $c['terms_title'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Acceptation', 'chalet-booking' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[terms_required]" value="1" <?php checked( $c['terms_required'] ); ?>> <?php esc_html_e( 'Le client doit cocher « J’accepte les conditions » pour envoyer sa demande', 'chalet-booking' ); ?></label></td></tr>
				</table>
				<?php
				wp_editor(
					$c['terms_text'],
					'cb_terms_text',
					array(
						'textarea_name' => $name . '[terms_text]',
						'textarea_rows' => 18,
						'media_buttons' => false,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'À couvrir par exemple : acompte et solde, caution, annulation, nombre maximum de personnes, animaux, non-fumeur, horaires d’arrivée/départ, ménage, taxe de séjour, responsabilité. Faites-les relire par un professionnel.', 'chalet-booking' ); ?></p>

				<?php submit_button(); ?>
			</form>
		</div>
		<style>
			#cb-gallery-list{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
			#cb-gallery-list li{position:relative;margin:0;cursor:move}
			#cb-gallery-list li:first-child{outline:3px solid #2271b1}
			#cb-gallery-list img{display:block;width:110px;height:110px;object-fit:cover;border-radius:4px}
			.cb-gallery-remove{position:absolute;top:2px;right:2px;border:0;border-radius:50%;width:22px;height:22px;line-height:20px;background:#b32d2e;color:#fff;cursor:pointer}
		</style>
		<script>
		jQuery( function ( $ ) {
			var $list = $( '#cb-gallery-list' ), $input = $( '#cb-gallery-ids' ), frame;
			function save() {
				$input.val( $list.children().map( function () { return $( this ).data( 'id' ); } ).get().join( ',' ) );
			}
			$list.sortable( { update: save } );
			$list.on( 'click', '.cb-gallery-remove', function () { $( this ).parent().remove(); save(); } );
			$( '#cb-gallery-add' ).on( 'click', function () {
				if ( ! frame ) {
					frame = wp.media( { title: <?php echo wp_json_encode( __( 'Photos du chalet', 'chalet-booking' ) ); ?>, library: { type: 'image' }, multiple: 'add', button: { text: <?php echo wp_json_encode( __( 'Ajouter à la galerie', 'chalet-booking' ) ); ?> } } );
					frame.on( 'select', function () {
						frame.state().get( 'selection' ).each( function ( att ) {
							var a = att.toJSON();
							if ( $list.children( '[data-id="' + a.id + '"]' ).length ) { return; }
							var src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail : a ).url;
							$list.append( $( '<li>' ).attr( 'data-id', a.id ).append( $( '<img>' ).attr( 'src', src ), '<button type="button" class="cb-gallery-remove">&times;</button>' ) );
						} );
						save();
					} );
				}
				frame.open();
			} );
		} );
		</script>
		<?php
	}
}
