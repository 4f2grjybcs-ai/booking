<?php
/**
 * Description du chalet : chiffres clés, pièces, équipements, inclus / en supplément,
 * règlement intérieur et infos pratiques.
 *
 * Shortcode : [chalet_description] (option sections="facts,rooms,equipment,included,rules,access").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Description {

	const OPTION = 'cb_description';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'chalet_description', array( __CLASS__, 'render' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Listes de référence                                                */
	/* ------------------------------------------------------------------ */

	public static function room_types() {
		return array(
			'bedroom'  => __( 'Chambre', 'chalet-booking' ),
			'bathroom' => __( 'Salle de bain', 'chalet-booking' ),
			'shower'   => __( 'Salle d’eau', 'chalet-booking' ),
			'wc'       => __( 'WC séparé', 'chalet-booking' ),
			'living'   => __( 'Salon', 'chalet-booking' ),
			'dining'   => __( 'Salle à manger', 'chalet-booking' ),
			'kitchen'  => __( 'Cuisine', 'chalet-booking' ),
			'wellness' => __( 'Espace bien-être', 'chalet-booking' ),
			'ski'      => __( 'Local à skis', 'chalet-booking' ),
			'other'    => __( 'Autre', 'chalet-booking' ),
		);
	}

	/**
	 * Équipements proposés, par catégorie.
	 */
	public static function equipment_catalog() {
		return array(
			__( 'Cuisine', 'chalet-booking' )          => array(
				'kitchen'      => __( 'Cuisine entièrement équipée', 'chalet-booking' ),
				'oven'         => __( 'Four', 'chalet-booking' ),
				'microwave'    => __( 'Micro-ondes', 'chalet-booking' ),
				'dishwasher'   => __( 'Lave-vaisselle', 'chalet-booking' ),
				'coffee'       => __( 'Machine à café', 'chalet-booking' ),
				'kettle'       => __( 'Bouilloire', 'chalet-booking' ),
				'toaster'      => __( 'Grille-pain', 'chalet-booking' ),
				'raclette'     => __( 'Appareil à raclette', 'chalet-booking' ),
				'fondue'       => __( 'Caquelon à fondue', 'chalet-booking' ),
				'freezer'      => __( 'Congélateur', 'chalet-booking' ),
			),
			__( 'Confort', 'chalet-booking' )          => array(
				'fireplace'    => __( 'Cheminée', 'chalet-booking' ),
				'floorheat'    => __( 'Chauffage au sol', 'chalet-booking' ),
				'bedlinen'     => __( 'Linge de lit fourni', 'chalet-booking' ),
				'towels'       => __( 'Serviettes fournies', 'chalet-booking' ),
				'hairdryer'    => __( 'Sèche-cheveux', 'chalet-booking' ),
				'iron'         => __( 'Fer et table à repasser', 'chalet-booking' ),
				'washer'       => __( 'Lave-linge', 'chalet-booking' ),
				'dryer'        => __( 'Sèche-linge', 'chalet-booking' ),
			),
			__( 'Bien-être', 'chalet-booking' )        => array(
				'sauna'        => __( 'Sauna', 'chalet-booking' ),
				'jacuzzi'      => __( 'Jacuzzi / spa', 'chalet-booking' ),
				'hammam'       => __( 'Hammam', 'chalet-booking' ),
				'gym'          => __( 'Salle de fitness', 'chalet-booking' ),
			),
			__( 'Multimédia', 'chalet-booking' )       => array(
				'wifi'         => __( 'Wi-Fi', 'chalet-booking' ),
				'tv'           => __( 'Télévision', 'chalet-booking' ),
				'streaming'    => __( 'Netflix / streaming', 'chalet-booking' ),
				'speaker'      => __( 'Enceinte Bluetooth', 'chalet-booking' ),
				'desk'         => __( 'Espace de travail', 'chalet-booking' ),
			),
			__( 'Extérieur', 'chalet-booking' )        => array(
				'terrace'      => __( 'Terrasse', 'chalet-booking' ),
				'balcony'      => __( 'Balcon', 'chalet-booking' ),
				'garden'       => __( 'Jardin', 'chalet-booking' ),
				'bbq'          => __( 'Barbecue', 'chalet-booking' ),
				'outdoor_furn' => __( 'Mobilier de jardin', 'chalet-booking' ),
				'view'         => __( 'Vue sur les montagnes', 'chalet-booking' ),
			),
			__( 'Ski & montagne', 'chalet-booking' )   => array(
				'skiroom'      => __( 'Local à skis', 'chalet-booking' ),
				'bootwarmer'   => __( 'Chauffe-chaussures de ski', 'chalet-booking' ),
				'skiinout'     => __( 'Ski-in / ski-out', 'chalet-booking' ),
				'skibus'       => __( 'Arrêt de navette ski à proximité', 'chalet-booking' ),
			),
			__( 'Enfants', 'chalet-booking' )          => array(
				'babycot'      => __( 'Lit bébé', 'chalet-booking' ),
				'highchair'    => __( 'Chaise haute', 'chalet-booking' ),
				'games'        => __( 'Jeux de société', 'chalet-booking' ),
			),
			__( 'Pratique', 'chalet-booking' )         => array(
				'parking'      => __( 'Parking privé', 'chalet-booking' ),
				'garage'       => __( 'Garage', 'chalet-booking' ),
				'ev'           => __( 'Borne de recharge électrique', 'chalet-booking' ),
				'elevator'     => __( 'Ascenseur', 'chalet-booking' ),
				'keybox'       => __( 'Arrivée autonome (boîte à clés)', 'chalet-booking' ),
			),
		);
	}

	private static function equipment_keys() {
		$keys = array();
		foreach ( self::equipment_catalog() as $items ) {
			$keys = array_merge( $keys, array_keys( $items ) );
		}
		return $keys;
	}

	/* ------------------------------------------------------------------ */
	/* Données                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'intro'      => '',
			'surface'    => '',
			'floors'     => '',
			'beds'       => '',
			'bedrooms'   => '',
			'bathrooms'  => '',
			'rooms'      => array(),
			'equipment'  => array(),
			'equip_more' => '',
			'included'   => "Linge de lit et serviettes\nMénage final\nWi-Fi\nChauffage et électricité",
			'extras'     => '',
			'pets'       => 'no',
			'smoking'    => 'no',
			'parties'    => 'no',
			'rules_more' => '',
			'access'     => '',
		);
	}

	public static function get() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function register() {
		register_setting(
			'cb_description_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$types = self::room_types();
		$allow = array( 'yes', 'no', 'ask' );

		$rooms = array();
		foreach ( (array) ( $in['rooms'] ?? array() ) as $r ) {
			$name = sanitize_text_field( $r['name'] ?? '' );
			$desc = sanitize_textarea_field( $r['desc'] ?? '' );
			if ( '' === $name && '' === trim( $desc ) ) {
				continue;
			}
			$type    = sanitize_key( $r['type'] ?? 'other' );
			$rooms[] = array(
				'name'  => $name,
				'type'  => isset( $types[ $type ] ) ? $type : 'other',
				'floor' => sanitize_text_field( $r['floor'] ?? '' ),
				'beds'  => sanitize_text_field( $r['beds'] ?? '' ),
				'desc'  => $desc,
				'photo' => wp_attachment_is_image( absint( $r['photo'] ?? 0 ) ) ? absint( $r['photo'] ) : 0,
			);
		}

		return array(
			'intro'      => wp_kses_post( wp_unslash( $in['intro'] ?? '' ) ),
			'surface'    => sanitize_text_field( $in['surface'] ?? '' ),
			'floors'     => sanitize_text_field( $in['floors'] ?? '' ),
			'beds'       => sanitize_text_field( $in['beds'] ?? '' ),
			'bedrooms'   => sanitize_text_field( $in['bedrooms'] ?? '' ),
			'bathrooms'  => sanitize_text_field( $in['bathrooms'] ?? '' ),
			'rooms'      => $rooms,
			'equipment'  => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['equipment'] ?? array() ) ), self::equipment_keys() ) ),
			'equip_more' => sanitize_textarea_field( $in['equip_more'] ?? '' ),
			'included'   => sanitize_textarea_field( $in['included'] ?? '' ),
			'extras'     => sanitize_textarea_field( $in['extras'] ?? '' ),
			'pets'       => in_array( $in['pets'] ?? '', $allow, true ) ? $in['pets'] : 'no',
			'smoking'    => in_array( $in['smoking'] ?? '', $allow, true ) ? $in['smoking'] : 'no',
			'parties'    => in_array( $in['parties'] ?? '', $allow, true ) ? $in['parties'] : 'no',
			'rules_more' => sanitize_textarea_field( $in['rules_more'] ?? '' ),
			'access'     => sanitize_textarea_field( $in['access'] ?? '' ),
		);
	}

	private static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $text ) ) ) );
	}

	/** Nombre de pièces d'un type (sert de valeur par défaut aux chiffres clés). */
	private static function count_rooms( $d, $types ) {
		return count(
			array_filter(
				$d['rooms'],
				function ( $r ) use ( $types ) {
					return in_array( $r['type'], $types, true );
				}
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Affichage public                                                   */
	/* ------------------------------------------------------------------ */

	public static function render( $atts ) {
		$atts     = shortcode_atts( array( 'sections' => 'intro,facts,rooms,equipment,included,rules,access' ), $atts, 'chalet_description' );
		$sections = array_map( 'trim', explode( ',', $atts['sections'] ) );
		$d        = self::get();
		$html     = '';

		wp_enqueue_style( 'chalet-booking' );

		if ( in_array( 'intro', $sections, true ) && '' !== trim( wp_strip_all_tags( $d['intro'] ) ) ) {
			$html .= '<div class="cb-desc-intro">' . wpautop( wp_kses_post( $d['intro'] ) ) . '</div>';
		}

		if ( in_array( 'facts', $sections, true ) ) {
			$bedrooms  = '' !== $d['bedrooms'] ? $d['bedrooms'] : self::count_rooms( $d, array( 'bedroom' ) );
			$bathrooms = '' !== $d['bathrooms'] ? $d['bathrooms'] : self::count_rooms( $d, array( 'bathroom', 'shower' ) );
			$facts     = array(
				__( 'Voyageurs', 'chalet-booking' )     => (int) CB_Settings::get( 'max_guests' ),
				__( 'Chambres', 'chalet-booking' )      => $bedrooms,
				__( 'Salles de bain', 'chalet-booking' ) => $bathrooms,
				__( 'Lits', 'chalet-booking' )          => $d['beds'],
				__( 'Surface', 'chalet-booking' )       => '' !== $d['surface'] ? $d['surface'] . ' m²' : '',
				__( 'Étages', 'chalet-booking' )        => $d['floors'],
			);
			$items = '';
			foreach ( $facts as $label => $value ) {
				if ( '' === (string) $value || '0' === (string) $value ) {
					continue;
				}
				$long   = mb_strlen( (string) $value ) > 8;
				$items .= '<li' . ( $long ? ' class="cb-fact-long"' : '' ) . '><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span></li>';
			}
			if ( $items ) {
				$html .= '<ul class="cb-facts">' . $items . '</ul>';
			}
		}

		if ( in_array( 'rooms', $sections, true ) && $d['rooms'] ) {
			$types = self::room_types();
			$html .= '<section class="cb-desc-section"><h2>' . esc_html__( 'Les pièces', 'chalet-booking' ) . '</h2><div class="cb-rooms">';
			foreach ( $d['rooms'] as $r ) {
				$html .= '<article class="cb-room">';
				if ( $r['photo'] ) {
					$html .= wp_get_attachment_image( $r['photo'], 'medium_large', false, array( 'loading' => 'lazy', 'class' => 'cb-room-photo' ) );
				}
				$meta  = array_filter( array( $types[ $r['type'] ] ?? '', $r['floor'] ) );
				$html .= '<div class="cb-room-body"><h3>' . esc_html( $r['name'] ? $r['name'] : ( $types[ $r['type'] ] ?? '' ) ) . '</h3>'
					. ( $meta ? '<p class="cb-room-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>' : '' )
					. ( $r['beds'] ? '<p class="cb-room-beds">🛏 ' . esc_html( $r['beds'] ) . '</p>' : '' )
					. ( $r['desc'] ? '<p>' . nl2br( esc_html( $r['desc'] ) ) . '</p>' : '' )
					. '</div></article>';
			}
			$html .= '</div></section>';
		}

		if ( in_array( 'equipment', $sections, true ) ) {
			$groups = '';
			foreach ( self::equipment_catalog() as $group => $items ) {
				$on = array_intersect_key( $items, array_flip( $d['equipment'] ) );
				if ( ! $on ) {
					continue;
				}
				$groups .= '<div class="cb-equip-group"><h3>' . esc_html( $group ) . '</h3><ul class="cb-checklist">';
				foreach ( $on as $label ) {
					$groups .= '<li>' . esc_html( $label ) . '</li>';
				}
				$groups .= '</ul></div>';
			}
			$more = self::lines( $d['equip_more'] );
			if ( $more ) {
				$groups .= '<div class="cb-equip-group"><h3>' . esc_html__( 'Et aussi', 'chalet-booking' ) . '</h3><ul class="cb-checklist">';
				foreach ( $more as $line ) {
					$groups .= '<li>' . esc_html( $line ) . '</li>';
				}
				$groups .= '</ul></div>';
			}
			if ( $groups ) {
				$html .= '<section class="cb-desc-section"><h2>' . esc_html__( 'Équipements', 'chalet-booking' ) . '</h2><div class="cb-equip">' . $groups . '</div></section>';
			}
		}

		if ( in_array( 'included', $sections, true ) ) {
			$inc = self::lines( $d['included'] );
			$ext = self::lines( $d['extras'] );
			if ( $inc || $ext ) {
				$html .= '<section class="cb-desc-section"><div class="cb-two-cols">';
				if ( $inc ) {
					$html .= '<div><h2>' . esc_html__( 'Inclus dans le prix', 'chalet-booking' ) . '</h2><ul class="cb-checklist">' . implode( '', array_map( function ( $l ) {
						return '<li>' . esc_html( $l ) . '</li>';
					}, $inc ) ) . '</ul></div>';
				}
				if ( $ext ) {
					$html .= '<div><h2>' . esc_html__( 'En supplément', 'chalet-booking' ) . '</h2><ul class="cb-pluslist">' . implode( '', array_map( function ( $l ) {
						return '<li>' . esc_html( $l ) . '</li>';
					}, $ext ) ) . '</ul></div>';
				}
				$html .= '</div></section>';
			}
		}

		if ( in_array( 'rules', $sections, true ) ) {
			$labels = array(
				'pets'    => array( __( 'Animaux acceptés', 'chalet-booking' ), __( 'Animaux non acceptés', 'chalet-booking' ), __( 'Animaux sur demande', 'chalet-booking' ) ),
				'smoking' => array( __( 'Fumeurs acceptés', 'chalet-booking' ), __( 'Non-fumeur', 'chalet-booking' ), __( 'Fumeurs : sur demande', 'chalet-booking' ) ),
				'parties' => array( __( 'Fêtes autorisées', 'chalet-booking' ), __( 'Pas de fêtes ni d’événements', 'chalet-booking' ), __( 'Événements sur demande', 'chalet-booking' ) ),
			);
			$idx   = array( 'yes' => 0, 'no' => 1, 'ask' => 2 );
			$rules = array(
				/* translators: %s: heure */
				sprintf( __( 'Arrivée dès %s', 'chalet-booking' ), CB_Settings::get( 'check_in_time' ) ),
				/* translators: %s: heure */
				sprintf( __( 'Départ avant %s', 'chalet-booking' ), CB_Settings::get( 'check_out_time' ) ),
				/* translators: %d: personnes */
				sprintf( __( '%d personnes maximum', 'chalet-booking' ), (int) CB_Settings::get( 'max_guests' ) ),
			);
			foreach ( $labels as $key => $l ) {
				$rules[] = $l[ $idx[ $d[ $key ] ] ?? 1 ];
			}
			$rules = array_merge( $rules, self::lines( $d['rules_more'] ) );
			$html .= '<section class="cb-desc-section"><h2>' . esc_html__( 'Règlement intérieur', 'chalet-booking' ) . '</h2><ul class="cb-rules">' . implode( '', array_map( function ( $l ) {
				return '<li>' . esc_html( $l ) . '</li>';
			}, $rules ) ) . '</ul></section>';
		}

		if ( in_array( 'access', $sections, true ) ) {
			$acc = self::lines( $d['access'] );
			if ( $acc ) {
				$html .= '<section class="cb-desc-section"><h2>' . esc_html__( 'Situation et accès', 'chalet-booking' ) . '</h2><ul class="cb-access">';
				foreach ( $acc as $line ) {
					// « Libellé : valeur » → libellé en gras.
					$parts = array_map( 'trim', explode( ':', $line, 2 ) );
					$html .= '<li>' . ( 2 === count( $parts ) && '' !== $parts[1] ? '<strong>' . esc_html( $parts[0] ) . '</strong> ' . esc_html( $parts[1] ) : esc_html( $line ) ) . '</li>';
				}
				$html .= '</ul></section>';
			}
		}

		return $html ? '<div class="cb-description">' . $html . '</div>' : '';
	}

	/* ------------------------------------------------------------------ */
	/* Administration                                                     */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'chalet-booking', __( 'Description du chalet', 'chalet-booking' ), __( 'Description du chalet', 'chalet-booking' ), 'manage_options', 'chalet-booking-description', array( __CLASS__, 'page' ) );
	}

	public static function enqueue( $hook ) {
		if ( false !== strpos( $hook, 'chalet-booking-description' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
	}

	private static function select( $name, $value, $options ) {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	public static function page() {
		$d     = self::get();
		$n     = self::OPTION;
		$types = self::room_types();
		$yn    = array(
			'no'  => __( 'Non', 'chalet-booking' ),
			'yes' => __( 'Oui', 'chalet-booking' ),
			'ask' => __( 'Sur demande', 'chalet-booking' ),
		);
		$text  = function ( $key, $label, $placeholder = '', $desc = '' ) use ( $d, $n ) {
			printf(
				'<tr><th><label for="cbd-%1$s">%2$s</label></th><td><input type="text" id="cbd-%1$s" class="regular-text" name="%3$s[%1$s]" value="%4$s" placeholder="%5$s">%6$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $n ),
				esc_attr( $d[ $key ] ),
				esc_attr( $placeholder ),
				$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
			);
		};
		$area  = function ( $key, $label, $placeholder, $desc = '' ) use ( $d, $n ) {
			printf(
				'<tr><th><label for="cbd-%1$s">%2$s</label></th><td><textarea id="cbd-%1$s" class="large-text" rows="5" name="%3$s[%1$s]" placeholder="%4$s">%5$s</textarea>%6$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $n ),
				esc_attr( $placeholder ),
				esc_textarea( $d[ $key ] ),
				$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Description du chalet', 'chalet-booking' ); ?></h1>
			<?php settings_errors(); ?>
			<p><?php printf( /* translators: %s: shortcode */ esc_html__( 'Affichez cette description sur une page avec %s. Pour n’afficher qu’une partie : [chalet_description sections="rooms,equipment"] (parties : intro, facts, rooms, equipment, included, rules, access).', 'chalet-booking' ), '<code>[chalet_description]</code>' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cb_description_group' ); ?>

				<h2><?php esc_html_e( 'Présentation', 'chalet-booking' ); ?></h2>
				<?php
				wp_editor(
					$d['intro'],
					'cbd_intro',
					array(
						'textarea_name' => $n . '[intro]',
						'textarea_rows' => 8,
						'media_buttons' => false,
					)
				);
				?>

				<h2><?php esc_html_e( 'Chiffres clés', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php
					$text( 'surface', __( 'Surface (m²)', 'chalet-booking' ), '180' );
					$text( 'floors', __( 'Nombre d’étages', 'chalet-booking' ), '3' );
					$text( 'bedrooms', __( 'Chambres', 'chalet-booking' ), '', __( 'Vide = compté automatiquement depuis la liste des pièces.', 'chalet-booking' ) );
					$text( 'bathrooms', __( 'Salles de bain', 'chalet-booking' ), '', __( 'Vide = compté automatiquement depuis la liste des pièces.', 'chalet-booking' ) );
					$text( 'beds', __( 'Lits', 'chalet-booking' ), '3 lits doubles, 4 lits simples' );
					?>
					<tr><th><?php esc_html_e( 'Capacité', 'chalet-booking' ); ?></th><td><?php printf( /* translators: %d: personnes */ esc_html__( '%d personnes (modifiable dans Réglages).', 'chalet-booking' ), (int) CB_Settings::get( 'max_guests' ) ); ?></td></tr>
				</table>

				<h2><?php esc_html_e( 'Les pièces', 'chalet-booking' ); ?></h2>
				<p><?php esc_html_e( 'Une ligne par pièce : chambres, salles de bain, salon, cuisine, sauna, local à skis… Glissez-déposez pour changer l’ordre.', 'chalet-booking' ); ?></p>
				<div id="cbd-rooms">
					<?php
					$rows   = $d['rooms'];
					$rows[] = null;
					foreach ( $rows as $i => $r ) :
						$tpl = null === $r;
						$r   = $r ?: array( 'name' => '', 'type' => 'bedroom', 'floor' => '', 'beds' => '', 'desc' => '', 'photo' => 0 );
						$p   = $tpl ? '__i__' : $i;
						$f   = function ( $k ) use ( $n, $p ) {
							return esc_attr( "{$n}[rooms][{$p}][{$k}]" );
						};
						?>
						<div class="cbd-room<?php echo $tpl ? ' cbd-tpl' : ''; ?>" <?php echo $tpl ? 'hidden' : ''; ?>>
							<div class="cbd-photo">
								<input type="hidden" data-name="<?php echo $f( 'photo' ); // phpcs:ignore ?>" value="<?php echo (int) $r['photo']; ?>">
								<button type="button" class="cbd-pick" title="<?php esc_attr_e( 'Choisir une photo', 'chalet-booking' ); ?>"><?php echo $r['photo'] ? wp_get_attachment_image( $r['photo'], 'thumbnail' ) : '<span>' . esc_html__( '+ Photo', 'chalet-booking' ) . '</span>'; ?></button>
							</div>
							<div class="cbd-fields">
								<input type="text" data-name="<?php echo $f( 'name' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $r['name'] ); ?>" placeholder="<?php esc_attr_e( 'Nom (ex. Chambre parentale)', 'chalet-booking' ); ?>">
								<select data-name="<?php echo $f( 'type' ); // phpcs:ignore ?>">
									<?php foreach ( $types as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" data-name="<?php echo $f( 'floor' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $r['floor'] ); ?>" placeholder="<?php esc_attr_e( 'Étage (ex. 1er étage)', 'chalet-booking' ); ?>">
								<input type="text" data-name="<?php echo $f( 'beds' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $r['beds'] ); ?>" placeholder="<?php esc_attr_e( 'Lits (ex. 1 lit double 180×200)', 'chalet-booking' ); ?>">
								<textarea rows="2" data-name="<?php echo $f( 'desc' ); // phpcs:ignore ?>" placeholder="<?php esc_attr_e( 'Description / équipements de la pièce (salle de bain attenante, vue, TV…)', 'chalet-booking' ); ?>"><?php echo esc_textarea( $r['desc'] ); ?></textarea>
							</div>
							<div class="cbd-tools">
								<span class="cbd-handle dashicons dashicons-move" title="<?php esc_attr_e( 'Déplacer', 'chalet-booking' ); ?>"></span>
								<button type="button" class="button-link button-link-delete cbd-remove"><?php esc_html_e( 'Supprimer', 'chalet-booking' ); ?></button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<p><button type="button" class="button" id="cbd-add"><?php esc_html_e( '+ Ajouter une pièce', 'chalet-booking' ); ?></button></p>

				<h2><?php esc_html_e( 'Équipements', 'chalet-booking' ); ?></h2>
				<div class="cbd-equip">
					<?php foreach ( self::equipment_catalog() as $group => $items ) : ?>
						<fieldset>
							<legend><?php echo esc_html( $group ); ?></legend>
							<?php foreach ( $items as $k => $label ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[equipment][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $d['equipment'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					<?php endforeach; ?>
				</div>
				<table class="form-table">
					<?php $area( 'equip_more', __( 'Autres équipements', 'chalet-booking' ), __( "Un par ligne, ex. :\nBaby-foot\nPiano", 'chalet-booking' ) ); ?>
				</table>

				<h2><?php esc_html_e( 'Inclus / en supplément', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php
					$area( 'included', __( 'Inclus dans le prix', 'chalet-booking' ), '', __( 'Un élément par ligne.', 'chalet-booking' ) );
					$area( 'extras', __( 'En supplément', 'chalet-booking' ), __( "Un par ligne, ex. :\nMénage en cours de séjour : CHF 150\nChef à domicile : sur demande\nTransfert aéroport de Genève : sur demande", 'chalet-booking' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Règlement intérieur', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Animaux', 'chalet-booking' ); ?></th><td><?php echo self::select( $n . '[pets]', $d['pets'], $yn ); // phpcs:ignore ?></td></tr>
					<tr><th><?php esc_html_e( 'Fumeurs', 'chalet-booking' ); ?></th><td><?php echo self::select( $n . '[smoking]', $d['smoking'], $yn ); // phpcs:ignore ?></td></tr>
					<tr><th><?php esc_html_e( 'Fêtes / événements', 'chalet-booking' ); ?></th><td><?php echo self::select( $n . '[parties]', $d['parties'], $yn ); // phpcs:ignore ?></td></tr>
					<?php $area( 'rules_more', __( 'Autres règles', 'chalet-booking' ), __( "Une par ligne, ex. :\nPas de chaussures de ski dans le chalet\nSilence après 22 h\nCaution : CHF 1’000", 'chalet-booking' ) ); ?>
				</table>
				<p class="description"><?php esc_html_e( 'Les horaires d’arrivée/départ et la capacité sont repris automatiquement des Réglages.', 'chalet-booking' ); ?></p>

				<h2><?php esc_html_e( 'Situation et accès', 'chalet-booking' ); ?></h2>
				<table class="form-table">
					<?php $area( 'access', __( 'Distances et accès', 'chalet-booking' ), __( "Une par ligne, format « Libellé : valeur », ex. :\nTélécabine de Médran : 5 min à pied\nCentre de Verbier : 800 m\nArrêt navette gratuite : 100 m\nGare du Châble : 15 min en voiture\nAéroport de Genève : 2 h en voiture", 'chalet-booking' ) ); ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<style>
			#cbd-rooms .cbd-room{display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:8px;max-width:1000px}
			.cbd-photo button{width:96px;height:96px;border:1px dashed #8c8f94;border-radius:4px;background:#f6f7f7;cursor:pointer;padding:0;overflow:hidden}
			.cbd-photo img{width:96px;height:96px;object-fit:cover;display:block}
			.cbd-fields{flex:1;display:grid;grid-template-columns:2fr 1fr;gap:6px}
			.cbd-fields textarea{grid-column:1/-1}
			.cbd-tools{display:flex;flex-direction:column;gap:8px;align-items:center}
			.cbd-handle{cursor:move;color:#8c8f94}
			.cbd-equip{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;max-width:1100px}
			.cbd-equip fieldset{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px}
			.cbd-equip legend{font-weight:600;padding:0 4px}
			.cbd-equip label{display:block;margin:4px 0}
		</style>
		<script>
		jQuery( function ( $ ) {
			var $box = $( '#cbd-rooms' ), $tpl = $box.children( '.cbd-tpl' ), n = $box.children( ':not(.cbd-tpl)' ).length;
			function named( $row, i ) {
				$row.find( '[data-name]' ).each( function () {
					this.name = this.getAttribute( 'data-name' ).replace( /\[rooms\]\[[^\]]+\]/, '[rooms][' + i + ']' );
				} );
			}
			function renumber() {
				$box.children( ':not(.cbd-tpl)' ).each( function ( i ) { named( $( this ), i ); } );
			}
			renumber();
			if ( $.fn.sortable ) {
				$box.sortable( { handle: '.cbd-handle', items: '> .cbd-room:not(.cbd-tpl)', update: renumber } );
			}
			$( '#cbd-add' ).on( 'click', function () {
				var $row = $tpl.clone().removeClass( 'cbd-tpl' ).prop( 'hidden', false );
				$row.insertBefore( $tpl );
				renumber();
				$row.find( 'input[type=text]' ).first().trigger( 'focus' );
			} );
			$box.on( 'click', '.cbd-remove', function () {
				$( this ).closest( '.cbd-room' ).remove();
				renumber();
			} );
			$box.on( 'click', '.cbd-pick', function () {
				var $btn = $( this ), $input = $btn.siblings( 'input' );
				var frame = wp.media( { title: <?php echo wp_json_encode( __( 'Photo de la pièce', 'chalet-booking' ) ); ?>, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					$input.val( a.id );
					$btn.html( $( '<img>' ).attr( 'src', ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail : a ).url ) );
				} );
				frame.open();
			} );
		} );
		</script>
		<?php
	}
}
