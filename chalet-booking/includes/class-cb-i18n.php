<?php
/**
 * Multilingue : français, anglais, allemand, espagnol.
 *
 * - Les textes du plugin sont traduits par les fichiers languages/chalet-booking-{locale}.mo.
 * - Les textes saisis par le propriétaire (description, conditions, saisons, titres de pages…)
 *   sont traduits dans Réservations → Traductions ; sans traduction, le français s'affiche.
 * - Sélecteur de langue : bouton flottant et/ou shortcode [chalet_langues].
 * - Si Polylang, WPML ou TranslatePress est actif, sa langue courante est suivie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_I18n {

	const OPTION = 'cb_translations';
	const COOKIE = 'cb_lang';

	/** Langue appliquée actuellement aux textes du plugin. */
	private static $active = null;

	public static function languages() {
		return array(
			'fr' => array( 'locale' => 'fr_FR', 'bcp47' => 'fr-CH', 'label' => 'Français', 'short' => 'FR' ),
			'en' => array( 'locale' => 'en_US', 'bcp47' => 'en-GB', 'label' => 'English', 'short' => 'EN' ),
			'de' => array( 'locale' => 'de_DE', 'bcp47' => 'de-CH', 'label' => 'Deutsch', 'short' => 'DE' ),
			'es' => array( 'locale' => 'es_ES', 'bcp47' => 'es-ES', 'label' => 'Español', 'short' => 'ES' ),
		);
	}

	/** Langues activées dans les réglages (le français toujours en premier). */
	public static function enabled() {
		$on  = (array) CB_Settings::get( 'languages' );
		$out = array( 'fr' );
		foreach ( array_keys( self::languages() ) as $code ) {
			if ( 'fr' !== $code && in_array( $code, $on, true ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'remember_choice' ), 1 );
		add_action( 'wp', array( __CLASS__, 'apply_current' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'apply_for_rest' ), 1 );
		add_filter( 'language_attributes', array( __CLASS__, 'html_lang' ) );
		add_action( 'wp_footer', array( __CLASS__, 'floating_switcher' ) );
		add_shortcode( 'chalet_langues', array( __CLASS__, 'switcher_shortcode' ) );
		add_shortcode( 'chalet_languages', array( __CLASS__, 'switcher_shortcode' ) );

		// Titres de pages et menus traduits (textes saisis par le propriétaire).
		add_filter( 'the_title', array( __CLASS__, 'filter_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu_items' ) );
		add_filter( 'render_block', array( __CLASS__, 'filter_navigation_block' ), 10, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_cb_save_translations', array( __CLASS__, 'save' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Détection                                                          */
	/* ------------------------------------------------------------------ */

	private static function valid( $code ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );
		return in_array( $code, self::enabled(), true ) ? $code : '';
	}

	/** Extension multilingue active qui pilote la langue (Polylang, WPML, TranslatePress). */
	private static function from_multilingual_plugin() {
		if ( function_exists( 'pll_current_language' ) ) {
			return self::valid( pll_current_language( 'slug' ) );
		}
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return self::valid( ICL_LANGUAGE_CODE );
		}
		global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( ! empty( $TRP_LANGUAGE ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			return self::valid( $TRP_LANGUAGE ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		return '';
	}

	public static function has_multilingual_plugin() {
		return function_exists( 'pll_current_language' ) || defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'TRP_Translate_Press' );
	}

	/** Langue demandée par le visiteur. */
	public static function current() {
		$plugin = self::from_multilingual_plugin();
		if ( $plugin ) {
			return $plugin;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['lang'] ) && self::valid( sanitize_key( wp_unslash( $_GET['lang'] ) ) ) ) {
			return self::valid( sanitize_key( wp_unslash( $_GET['lang'] ) ) );
		}
		// phpcs:enable
		if ( isset( $_COOKIE[ self::COOKIE ] ) && self::valid( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) ) {
			return self::valid( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		}
		// Première visite : langue du navigateur si elle est proposée.
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) ) as $part ) {
				$code = self::valid( trim( $part ) );
				if ( $code ) {
					return $code;
				}
			}
		}
		return 'fr';
	}

	/** Langue actuellement appliquée (pour les textes et les dates). */
	public static function active() {
		return self::$active ? self::$active : 'fr';
	}

	public static function remember_choice() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( is_admin() || ! isset( $_GET['lang'] ) ) {
			return;
		}
		$code = self::valid( sanitize_key( wp_unslash( $_GET['lang'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $code && ! headers_sent() ) {
			setcookie( self::COOKIE, $code, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
			$_COOKIE[ self::COOKIE ] = $code;
		}
	}

	public static function apply_current() {
		if ( is_admin() ) {
			return;
		}
		self::switch_to( self::current() );
	}

	public static function apply_for_rest() {
		// Le back-office reste en français ; l'API publique suit la langue du visiteur.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'chalet-booking' ) && false === strpos( $uri, '/manage' ) ) {
			self::switch_to( self::current() );
		}
	}

	/**
	 * Charge les traductions du plugin dans la langue donnée.
	 */
	public static function switch_to( $code ) {
		$langs = self::languages();
		$code  = isset( $langs[ $code ] ) ? $code : 'fr';
		if ( self::$active === $code ) {
			return;
		}
		unload_textdomain( 'chalet-booking' );
		// Empêche WordPress de recharger « juste à temps » la traduction de la langue du site
		// (unload_textdomain ne le fait que si le domaine était déjà chargé).
		$GLOBALS['l10n_unloaded']                     = (array) ( $GLOBALS['l10n_unloaded'] ?? array() );
		$GLOBALS['l10n_unloaded']['chalet-booking'] = true;
		if ( 'fr' !== $code ) {
			load_textdomain( 'chalet-booking', CB_DIR . 'languages/chalet-booking-' . $langs[ $code ]['locale'] . '.mo' );
		}
		self::$active = $code;
	}

	/**
	 * Exécute $callback avec les textes du plugin dans la langue $code, puis revient à la langue précédente.
	 */
	public static function with( $code, callable $callback ) {
		$previous = self::active();
		self::switch_to( $code );
		try {
			return $callback();
		} finally {
			self::switch_to( $previous );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Textes du propriétaire                                             */
	/* ------------------------------------------------------------------ */

	public static function key( $source ) {
		return md5( trim( str_replace( "\r\n", "\n", (string) $source ) ) );
	}

	/**
	 * Traduit un texte saisi par le propriétaire (retourne le français si pas de traduction).
	 */
	public static function t( $source, $code = null ) {
		$code = $code ? $code : self::active();
		if ( 'fr' === $code || '' === trim( (string) $source ) ) {
			return $source;
		}
		static $all = null;
		if ( null === $all ) {
			$all = get_option( self::OPTION, array() );
		}
		$key = self::key( $source );
		return isset( $all[ $code ][ $key ] ) && '' !== trim( $all[ $code ][ $key ] ) ? $all[ $code ][ $key ] : $source;
	}

	/** Traduit chaque ligne d'un texte multiligne. */
	public static function t_lines( $text, $code = null ) {
		return implode( "\n", array_map( function ( $line ) use ( $code ) {
			return self::t( $line, $code );
		}, preg_split( '/\r?\n/', (string) $text ) ) );
	}

	/**
	 * Tous les textes du propriétaire à traduire, regroupés par section.
	 *
	 * @return array section => [ [source, multiline(bool), html(bool)] ]
	 */
	public static function sources() {
		$out  = array();
		$add  = function ( $section, $text, $multiline = false, $html = false ) use ( &$out ) {
			$text = trim( str_replace( "\r\n", "\n", (string) $text ) );
			if ( '' === $text ) {
				return;
			}
			$out[ $section ][ self::key( $text ) ] = array( $text, $multiline, $html );
		};
		$lines = function ( $section, $text ) use ( $add ) {
			foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
				$add( $section, $line );
			}
		};

		// Pages et menus.
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
			$add( __( 'Titres des pages', 'chalet-booking' ), $page->post_title );
		}

		// Description du chalet.
		$d   = CB_Description::get();
		$sec = __( 'Description du chalet', 'chalet-booking' );
		$add( $sec, $d['intro'], true, true );
		$add( $sec, $d['beds'] );
		foreach ( $d['rooms'] as $r ) {
			$add( $sec, $r['name'] );
			$add( $sec, $r['floor'] );
			$add( $sec, $r['beds'] );
			$add( $sec, $r['desc'], true );
		}
		$lines( $sec, $d['equip_more'] );
		$lines( $sec, $d['included'] );
		$lines( $sec, $d['extras'] );
		$lines( $sec, $d['rules_more'] );
		$lines( $sec, $d['access'] );

		// Conditions générales, galerie.
		$c   = CB_Content::get();
		$sec = __( 'Conditions générales et photos', 'chalet-booking' );
		$add( $sec, $c['terms_title'] );
		$add( $sec, $c['terms_text'], true, true );
		foreach ( (array) $c['gallery_ids'] as $id ) {
			$add( $sec, wp_get_attachment_caption( $id ) );
		}

		// Tarifs et paiement.
		$sec = __( 'Tarifs et paiement', 'chalet-booking' );
		foreach ( CB_Settings::seasons() as $s ) {
			$add( $sec, $s['name'] );
		}
		$add( $sec, CB_Settings::get( 'payment_instructions' ), true );

		// E-mails personnalisés (les textes par défaut sont déjà traduits).
		$defaults = self::with( 'fr', array( 'CB_Emails', 'templates' ) );
		$sec      = __( 'E-mails aux clients (textes personnalisés)', 'chalet-booking' );
		foreach ( CB_Emails::get( 'templates' ) as $id => $tpl ) {
			if ( 'admin_request' === $id || ! isset( $defaults[ $id ] ) ) {
				continue;
			}
			if ( $tpl['subject'] !== $defaults[ $id ]['subject'] ) {
				$add( $sec, $tpl['subject'] );
			}
			if ( $tpl['body'] !== $defaults[ $id ]['body'] ) {
				$add( $sec, $tpl['body'], true );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Dates                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Date longue dans la langue active (ex. « samedi 19 décembre 2026 »), indépendante des packs de langue WordPress.
	 */
	public static function date( $ymd, $code = null ) {
		$code  = $code ? $code : self::active();
		$langs = self::languages();
		$ts    = strtotime( $ymd . ' 12:00:00 UTC' );
		if ( class_exists( 'IntlDateFormatter' ) ) {
			$f = new IntlDateFormatter( $langs[ $code ]['locale'], IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC' );
			$s = $f->format( $ts );
			if ( $s ) {
				return $s;
			}
		}
		return gmdate( 'fr' === $code || 'de' === $code ? 'd.m.Y' : ( 'en' === $code ? 'd/m/Y' : 'd/m/Y' ), $ts );
	}

	/** Nom de jour en milieu de phrase (minuscule en français et en espagnol). */
	public static function inline_day( $name ) {
		return in_array( self::active(), array( 'fr', 'es' ), true ) ? mb_strtolower( $name ) : $name;
	}

	/* ------------------------------------------------------------------ */
	/* Titres de pages et menus                                           */
	/* ------------------------------------------------------------------ */

	private static function front_translating() {
		return ! is_admin() && 'fr' !== self::active();
	}

	public static function filter_title( $title, $post_id = 0 ) {
		if ( ! self::front_translating() || ( $post_id && 'page' !== get_post_type( $post_id ) ) ) {
			return $title;
		}
		return self::t( $title );
	}

	public static function filter_document_title( $parts ) {
		if ( self::front_translating() && isset( $parts['title'] ) ) {
			$parts['title'] = self::t( $parts['title'] );
		}
		return $parts;
	}

	public static function filter_menu_items( $items ) {
		if ( self::front_translating() ) {
			foreach ( $items as $item ) {
				$item->title = self::t( $item->title );
			}
		}
		return $items;
	}

	/** Menus des thèmes « bloc » : remplace les libellés de pages traduits. */
	public static function filter_navigation_block( $html, $block ) {
		if ( ! self::front_translating() || empty( $block['blockName'] ) || ! in_array( $block['blockName'], array( 'core/navigation', 'core/page-list', 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
			return $html;
		}
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $page ) {
				$tr = self::t( $page->post_title );
				if ( $tr !== $page->post_title ) {
					$map[ '>' . esc_html( $page->post_title ) . '<' ] = '>' . esc_html( $tr ) . '<';
				}
			}
		}
		return $map ? strtr( $html, $map ) : $html;
	}

	/* ------------------------------------------------------------------ */
	/* Sélecteur de langue                                                */
	/* ------------------------------------------------------------------ */

	public static function html_lang( $output ) {
		if ( is_admin() || self::has_multilingual_plugin() || count( self::enabled() ) < 2 ) {
			return $output;
		}
		$langs = self::languages();
		return preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $langs[ self::active() ]['bcp47'] ) . '"', $output );
	}

	private static function links() {
		$langs = self::languages();
		$out   = array();
		foreach ( self::enabled() as $code ) {
			$out[ $code ] = array(
				'url'    => add_query_arg( 'lang', $code, remove_query_arg( 'lang' ) ),
				'label'  => $langs[ $code ]['label'],
				'short'  => $langs[ $code ]['short'],
				'active' => self::active() === $code,
			);
		}
		return $out;
	}

	public static function switcher_shortcode() {
		if ( count( self::enabled() ) < 2 ) {
			return '';
		}
		wp_enqueue_style( 'chalet-booking' );
		$html = '<nav class="cb-lang-inline" aria-label="Language">';
		foreach ( self::links() as $code => $l ) {
			$html .= '<a href="' . esc_url( $l['url'] ) . '" hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $l['active'] ? ' aria-current="true" class="active"' : '' ) . ' title="' . esc_attr( $l['label'] ) . '">' . esc_html( $l['short'] ) . '</a>';
		}
		return $html . '</nav>';
	}

	public static function floating_switcher() {
		if ( is_admin() || ! CB_Settings::get( 'language_button' ) || count( self::enabled() ) < 2 || self::has_multilingual_plugin() ) {
			return;
		}
		$links = self::links();
		$cur   = $links[ self::active() ] ?? reset( $links );
		?>
		<div class="cb-lang-float">
			<details>
				<summary aria-label="Language / Langue"><span aria-hidden="true">🌐</span> <?php echo esc_html( $cur['short'] ); ?></summary>
				<ul>
					<?php foreach ( $links as $code => $l ) : ?>
						<li><a href="<?php echo esc_url( $l['url'] ); ?>" hreflang="<?php echo esc_attr( $code ); ?>" lang="<?php echo esc_attr( $code ); ?>"<?php echo $l['active'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $l['label'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<style>
			.cb-lang-float{position:fixed;left:16px;bottom:16px;z-index:9999;font:14px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
			.cb-lang-float summary{list-style:none;cursor:pointer;background:#fff;color:#222;border:1px solid #ddd;border-radius:999px;padding:8px 14px;box-shadow:0 2px 10px rgba(0,0,0,.12);font-weight:600}
			.cb-lang-float summary::-webkit-details-marker{display:none}
			.cb-lang-float ul{position:absolute;bottom:calc(100% + 6px);left:0;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #ddd;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.15);min-width:140px}
			.cb-lang-float li{margin:0}
			.cb-lang-float a{display:block;padding:8px 10px;border-radius:6px;color:#222;text-decoration:none}
			.cb-lang-float a:hover{background:#f2f2f2}
			.cb-lang-float a[aria-current]{font-weight:700;background:#f2f2f2}
		</style>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page d'administration « Traductions »                              */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'chalet-booking', __( 'Traductions', 'chalet-booking' ), __( 'Traductions', 'chalet-booking' ), 'manage_options', 'chalet-booking-translations', array( __CLASS__, 'page' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cb_save_translations' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'chalet-booking' ) );
		}
		$code = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		if ( ! in_array( $code, array( 'en', 'de', 'es' ), true ) ) {
			wp_die( esc_html__( 'Langue inconnue.', 'chalet-booking' ) );
		}
		$all     = get_option( self::OPTION, array() );
		$sources = array();
		foreach ( self::sources() as $items ) {
			$sources += $items;
		}
		$posted = isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean  = array();
		foreach ( $posted as $key => $value ) {
			$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
			if ( ! isset( $sources[ $key ] ) ) {
				continue;
			}
			$value = $sources[ $key ][2] ? wp_kses_post( $value ) : ( $sources[ $key ][1] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
			if ( '' !== trim( $value ) ) {
				$clean[ $key ] = $value;
			}
		}
		// On garde aussi les traductions de textes momentanément absents (ex. saison supprimée puis recréée).
		$all[ $code ] = $clean + array_diff_key( (array) ( $all[ $code ] ?? array() ), $sources );
		update_option( self::OPTION, $all, false );
		wp_safe_redirect( admin_url( 'admin.php?page=chalet-booking-translations&lang=' . $code . '&saved=1' ) );
		exit;
	}

	public static function page() {
		$langs   = self::languages();
		$enabled = array_values( array_diff( self::enabled(), array( 'fr' ) ) );
		$code    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ( $enabled[0] ?? 'en' ); // phpcs:ignore WordPress.Security.NonceVerification
		$code    = isset( $langs[ $code ] ) && 'fr' !== $code ? $code : 'en';
		$all     = get_option( self::OPTION, array() );
		$mine    = (array) ( $all[ $code ] ?? array() );
		$groups  = self::sources();
		$total   = 0;
		$done    = 0;
		foreach ( $groups as $items ) {
			foreach ( $items as $key => $item ) {
				$total++;
				$done += ( isset( $mine[ $key ] ) && '' !== trim( $mine[ $key ] ) ) ? 1 : 0;
			}
		}
		$deepl = array(
			'en' => 'en',
			'de' => 'de',
			'es' => 'es',
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Traductions', 'chalet-booking' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Traductions enregistrées.', 'chalet-booking' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Les textes du plugin (calendrier, formulaire, équipements, e-mails par défaut…) sont déjà traduits. Ici, vous traduisez vos propres textes. Un champ vide affiche le texte français. Si vous modifiez un texte français, sa traduction est à refaire.', 'chalet-booking' ); ?></p>

			<nav class="nav-tab-wrapper">
				<?php foreach ( array( 'en', 'de', 'es' ) as $c ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=chalet-booking-translations&lang=' . $c ) ); ?>" class="nav-tab <?php echo $c === $code ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $langs[ $c ]['label'] ); ?><?php echo in_array( $c, $enabled, true ) ? '' : ' ' . esc_html__( '(désactivée)', 'chalet-booking' ); ?></a>
				<?php endforeach; ?>
			</nav>

			<p><strong><?php printf( /* translators: 1: traduits, 2: total */ esc_html__( '%1$d textes traduits sur %2$d', 'chalet-booking' ), (int) $done, (int) $total ); ?></strong></p>

			<?php if ( ! $total ) : ?>
				<p><?php esc_html_e( 'Aucun texte à traduire pour le moment : remplissez d’abord la description du chalet, les conditions générales, etc.', 'chalet-booking' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cb_save_translations">
				<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>">
				<?php wp_nonce_field( 'cb_save_translations' ); ?>
				<?php foreach ( $groups as $section => $items ) : ?>
					<h2><?php echo esc_html( $section ); ?></h2>
					<table class="widefat striped cb-tr-table">
						<thead><tr><th style="width:45%">Français</th><th><?php echo esc_html( $langs[ $code ]['label'] ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $items as $key => $item ) : list( $text, $multi, $html ) = $item; $value = $mine[ $key ] ?? ''; ?>
							<tr class="<?php echo '' === trim( $value ) ? 'cb-tr-missing' : ''; ?>">
								<td>
									<div class="cb-tr-source"><?php echo $html ? wp_kses_post( wpautop( $text ) ) : nl2br( esc_html( $text ) ); ?></div>
									<a class="cb-tr-deepl" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://www.deepl.com/translator#fr/' . $deepl[ $code ] . '/' . rawurlencode( $html ? wp_strip_all_tags( $text ) : $text ) ); ?>"><?php esc_html_e( 'Traduire avec DeepL ↗', 'chalet-booking' ); ?></a>
								</td>
								<td>
									<?php if ( $multi ) : ?>
										<textarea name="tr[<?php echo esc_attr( $key ); ?>]" rows="<?php echo (int) min( 14, max( 3, substr_count( $text, "\n" ) + 2 ) ); ?>" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
										<?php if ( $html ) : ?><p class="description"><?php esc_html_e( 'Mise en forme HTML autorisée (<strong>, <h3>, <p>…).', 'chalet-booking' ); ?></p><?php endif; ?>
									<?php else : ?>
										<input type="text" name="tr[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="large-text">
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
				<?php submit_button( __( 'Enregistrer les traductions', 'chalet-booking' ) ); ?>
			</form>
			<?php endif; ?>
		</div>
		<style>
			.cb-tr-table{max-width:1200px;margin-bottom:16px}
			.cb-tr-table td{vertical-align:top}
			.cb-tr-source{white-space:normal}
			.cb-tr-source p{margin:0 0 6px}
			.cb-tr-deepl{font-size:12px}
			.cb-tr-missing td:first-child{box-shadow:inset 3px 0 0 #dba617}
		</style>
		<?php
	}
}
