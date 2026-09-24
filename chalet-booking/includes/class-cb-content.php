<?php
/**
 * Galerie photos et conditions générales de location.
 *
 * Shortcodes : [chalet_gallery] et [chalet_terms].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Content {

	const OPTION = 'cb_content';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'chalet_gallery', array( __CLASS__, 'gallery' ) );
		add_shortcode( 'chalet_terms', array( __CLASS__, 'terms' ) );
	}

	public static function defaults() {
		return array(
			'gallery_ids'    => array(),
			'terms_title'    => __( 'Conditions générales de location', 'chalet-booking' ),
			'terms_text'     => '',
			'terms_required' => 1,
		);
	}

	public static function get( $key = null ) {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return $settings[ $key ] ?? null;
	}

	public static function register() {
		register_setting(
			'cb_content_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$ids   = array_filter( array_map( 'absint', explode( ',', (string) ( $input['gallery_ids'] ?? '' ) ) ) );
		$ids   = array_values(
			array_filter(
				array_unique( $ids ),
				function ( $id ) {
					return wp_attachment_is_image( $id );
				}
			)
		);
		return array(
			'gallery_ids'    => $ids,
			'terms_title'    => sanitize_text_field( $input['terms_title'] ?? '' ),
			'terms_text'     => wp_kses_post( wp_unslash( $input['terms_text'] ?? '' ) ),
			'terms_required' => empty( $input['terms_required'] ) ? 0 : 1,
		);
	}

	/** Les conditions doivent-elles être acceptées dans le formulaire ? */
	public static function terms_required() {
		$has_terms = '' !== trim( wp_strip_all_tags( (string) self::get( 'terms_text' ) ) ) || CB_Settings::get( 'terms_url' );
		return $has_terms && self::get( 'terms_required' );
	}

	public static function register_assets() {
		wp_register_script( 'chalet-gallery', CB_URL . 'assets/js/gallery.js', array(), CB_VERSION, true );
	}

	/* ------------------------------------------------------------------ */
	/* Shortcodes                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * [chalet_gallery columns="3" limit="0" size="large"]
	 */
	public static function gallery( $atts ) {
		$atts = shortcode_atts(
			array(
				'columns' => 3,
				'limit'   => 0,
				'size'    => 'large',
			),
			$atts,
			'chalet_gallery'
		);
		$ids = (array) self::get( 'gallery_ids' );
		if ( (int) $atts['limit'] > 0 ) {
			$ids = array_slice( $ids, 0, (int) $atts['limit'] );
		}
		if ( ! $ids ) {
			return '';
		}

		wp_enqueue_style( 'chalet-booking' );
		wp_enqueue_script( 'chalet-gallery' );
		wp_localize_script(
			'chalet-gallery',
			'ChaletGallery',
			array(
				'close' => __( 'Fermer', 'chalet-booking' ),
				'prev'  => __( 'Photo précédente', 'chalet-booking' ),
				'next'  => __( 'Photo suivante', 'chalet-booking' ),
			)
		);

		$columns = max( 1, min( 6, (int) $atts['columns'] ) );
		$html    = '<div class="cb-gallery" style="--cb-cols:' . $columns . '" data-cb-gallery>';
		foreach ( $ids as $i => $id ) {
			$full = wp_get_attachment_image_src( $id, 'full' );
			if ( ! $full ) {
				continue;
			}
			$caption = CB_I18n::t( (string) wp_get_attachment_caption( $id ) );
			$html   .= sprintf(
				'<a class="cb-gallery-item%s" href="%s" data-caption="%s">%s</a>',
				0 === $i ? ' cb-gallery-first' : '',
				esc_url( $full[0] ),
				esc_attr( (string) $caption ),
				wp_get_attachment_image(
					$id,
					sanitize_key( $atts['size'] ),
					false,
					array(
						'loading' => 0 === $i ? 'eager' : 'lazy',
						'sizes'   => '(max-width: 600px) 100vw, ' . round( 100 / $columns ) . 'vw',
					)
				)
			);
		}
		return $html . '</div>';
	}

	/**
	 * [chalet_terms] — affiche les conditions générales.
	 */
	public static function terms() {
		$text = CB_I18n::t( (string) self::get( 'terms_text' ) );
		if ( '' === trim( $text ) ) {
			return '';
		}
		wp_enqueue_style( 'chalet-booking' );
		$title = CB_I18n::t( self::get( 'terms_title' ) );
		return '<div class="cb-terms" id="cb-terms">'
			. ( $title ? '<h2>' . esc_html( $title ) . '</h2>' : '' )
			. wpautop( wp_kses_post( $text ) )
			. '</div>';
	}

	/**
	 * Case à cocher « J'accepte les conditions » pour le formulaire de réservation.
	 */
	public static function checkbox_html() {
		if ( ! self::terms_required() ) {
			return '';
		}
		$url   = CB_Settings::get( 'terms_url' );
		$label = esc_html__( 'conditions générales de location', 'chalet-booking' );
		$link  = $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $label . '</a>'
			: '<button type="button" class="cb-terms-toggle" aria-expanded="false">' . $label . '</button>';

		$html  = '<label class="cb-check"><input type="checkbox" name="terms" value="1" required> ';
		/* translators: %s: lien vers les conditions */
		$html .= sprintf( esc_html__( 'J’ai lu et j’accepte les %s.', 'chalet-booking' ), $link );
		$html .= '</label>';
		if ( ! $url ) {
			$html .= '<div class="cb-terms-inline" hidden>' . wpautop( wp_kses_post( CB_I18n::t( (string) self::get( 'terms_text' ) ) ) ) . '</div>';
		}
		return $html;
	}
}
