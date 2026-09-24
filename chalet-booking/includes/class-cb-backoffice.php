<?php
/**
 * Back-office de gestion accessible sur le site, sans passer par wp-admin.
 *
 * Placez le shortcode [chalet_backoffice] sur une page (ex. « /gestion ») :
 * la page s'affiche alors en plein écran, avec son propre formulaire de connexion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Backoffice {

	const CAP  = 'cb_manage_bookings';
	const ROLE = 'cb_manager';

	public static function init() {
		add_shortcode( 'chalet_backoffice', array( __CLASS__, 'shortcode_fallback' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'save_post_page', array( __CLASS__, 'detect_page' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'keep_managers_out' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'admin_post_cb_create_backoffice_page', array( __CLASS__, 'create_page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rôles et accès                                                     */
	/* ------------------------------------------------------------------ */

	public static function install_roles() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				__( 'Gestionnaire du chalet', 'chalet-booking' ),
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		}
	}

	public static function can_manage() {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/** Gestionnaire « pur » (pas administrateur du site). */
	private static function is_manager_only( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && user_can( $user, self::CAP ) && ! user_can( $user, 'edit_posts' );
	}

	public static function url() {
		$id = (int) get_option( 'cb_backoffice_page_id' );
		return ( $id && 'publish' === get_post_status( $id ) ) ? get_permalink( $id ) : '';
	}

	public static function keep_managers_out() {
		if ( wp_doing_ajax() || ! self::is_manager_only() ) {
			return;
		}
		$url = self::url();
		if ( $url && ( ! isset( $GLOBALS['pagenow'] ) || ! in_array( $GLOBALS['pagenow'], array( 'admin-post.php', 'profile.php' ), true ) ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	public static function admin_bar( $show ) {
		return self::is_manager_only() ? false : $show;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_manager_only( $user ) && self::url() ) {
			return self::url();
		}
		return $redirect_to;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                               */
	/* ------------------------------------------------------------------ */

	public static function detect_page( $post_id, $post ) {
		if ( 'publish' === $post->post_status && has_shortcode( $post->post_content, 'chalet_backoffice' ) ) {
			update_option( 'cb_backoffice_page_id', (int) $post_id );
		}
	}

	public static function create_page() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cb_create_backoffice_page' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Gestion du chalet', 'chalet-booking' ),
				'post_name'      => 'gestion',
				'post_content'   => '[chalet_backoffice]',
				'comment_status' => 'closed',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'cb_backoffice_page_id', (int) $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking-settings' ) );
		exit;
	}

	/** Si le shortcode est rendu par le thème (cas rare), on renvoie vers la page plein écran. */
	public static function shortcode_fallback() {
		return '<p><a href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Ouvrir le back-office', 'chalet-booking' ) . '</a></p>';
	}

	public static function maybe_render() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, 'chalet_backoffice' ) ) {
			return;
		}
		if ( (int) get_option( 'cb_backoffice_page_id' ) !== (int) $post->ID && 'publish' === $post->post_status ) {
			update_option( 'cb_backoffice_page_id', (int) $post->ID );
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );

		if ( ! is_user_logged_in() ) {
			self::render_login( get_permalink( $post ) );
		} elseif ( ! self::can_manage() ) {
			self::render_shell( '<div class="bo-login"><h1>' . esc_html__( 'Accès refusé', 'chalet-booking' ) . '</h1><p>' . esc_html__( 'Votre compte n’a pas accès à la gestion du chalet.', 'chalet-booking' ) . '</p><p><a href="' . esc_url( wp_logout_url( get_permalink( $post ) ) ) . '">' . esc_html__( 'Se déconnecter', 'chalet-booking' ) . '</a></p></div>' );
		} else {
			self::render_app( get_permalink( $post ) );
		}
		exit;
	}

	private static function render_shell( $body, $scripts = '' ) {
		$title = CB_Settings::get( 'chalet_name' ) . ' — ' . __( 'Gestion', 'chalet-booking' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1f2a37">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?php echo esc_html( $title ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( CB_URL . 'assets/css/backoffice.css?ver=' . CB_VERSION ); ?>">
</head>
<body class="bo">
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php echo $scripts; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</body>
</html>
		<?php
	}

	private static function render_login( $redirect ) {
		$error = isset( $_GET['login'] ) && 'failed' === $_GET['login']; // phpcs:ignore WordPress.Security.NonceVerification
		ob_start();
		?>
		<div class="bo-login">
			<h1><?php echo esc_html( CB_Settings::get( 'chalet_name' ) ); ?></h1>
			<p class="bo-muted"><?php esc_html_e( 'Espace de gestion', 'chalet-booking' ); ?></p>
			<?php if ( $error ) : ?>
				<p class="bo-alert"><?php esc_html_e( 'Identifiant ou mot de passe incorrect.', 'chalet-booking' ); ?></p>
			<?php endif; ?>
			<?php
			wp_login_form(
				array(
					'redirect'       => $redirect,
					'label_username' => __( 'E-mail ou identifiant', 'chalet-booking' ),
					'label_password' => __( 'Mot de passe', 'chalet-booking' ),
					'label_remember' => __( 'Rester connecté', 'chalet-booking' ),
					'label_log_in'   => __( 'Se connecter', 'chalet-booking' ),
					'remember'       => true,
					'value_remember' => true,
				)
			);
			?>
			<p><a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>"><?php esc_html_e( 'Mot de passe oublié ?', 'chalet-booking' ); ?></a></p>
		</div>
		<?php
		self::render_shell( ob_get_clean() );
	}

	private static function render_app( $url ) {
		$user   = wp_get_current_user();
		$config = array(
			'api'       => esc_url_raw( rest_url( CB_REST::NS . '/manage' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'locale'    => str_replace( '_', '-', get_locale() ),
			'currency'  => CB_Settings::get( 'currency' ),
			'chalet'    => CB_Settings::get( 'chalet_name' ),
			'user'      => $user->display_name,
			'logout'    => wp_logout_url( $url ),
			'wpAdmin'   => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=chalet-booking' ) : '',
			'site'      => home_url( '/' ),
			'maxGuests' => (int) CB_Settings::get( 'max_guests' ),
			'weekdays'  => array_values( array_slice( CB_Settings::weekday_names(), 1, null, true ) ),
		);
		$scripts = '<script>window.CB_BO=' . wp_json_encode( $config ) . ';</script>'
			. '<script src="' . esc_url( CB_URL . 'assets/js/backoffice.js?ver=' . CB_VERSION ) . '"></script>';
		self::render_shell( '<div id="bo-app"><div class="bo-loading">' . esc_html__( 'Chargement…', 'chalet-booking' ) . '</div></div>', $scripts );
	}
}

// Échec de connexion depuis le back-office : revenir sur la page au lieu de wp-login.php.
add_action(
	'wp_login_failed',
	function () {
		$ref = wp_get_referer();
		$bo  = CB_Backoffice::url();
		if ( $ref && $bo && 0 === strpos( $ref, $bo ) ) {
			wp_safe_redirect( add_query_arg( 'login', 'failed', $bo ) );
			exit;
		}
	}
);
