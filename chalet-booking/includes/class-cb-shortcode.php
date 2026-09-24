<?php
/**
 * Shortcode [chalet_booking] : calendrier + formulaire de demande.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CB_Shortcode {

	public static function init() {
		add_shortcode( 'chalet_booking', array( __CLASS__, 'render' ) );
		// Enregistré sur « init » : les thèmes bloc exécutent les shortcodes avant « wp_enqueue_scripts ».
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'chalet-booking', CB_URL . 'assets/css/booking.css', array(), CB_VERSION );
		wp_register_script( 'chalet-booking', CB_URL . 'assets/js/booking.js', array(), CB_VERSION, true );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'months' => 2 ), $atts, 'chalet_booking' );

		wp_enqueue_style( 'chalet-booking' );
		wp_enqueue_script( 'chalet-booking' );
		wp_localize_script(
			'chalet-booking',
			'ChaletBooking',
			array(
				'api'      => esc_url_raw( rest_url( CB_REST::NS ) ),
				'months'   => max( 1, min( 4, (int) $atts['months'] ) ),
				'locale'   => CB_I18n::languages()[ CB_I18n::active() ]['bcp47'],
				'lang'     => CB_I18n::active(),
				'lowerDays' => in_array( CB_I18n::active(), array( 'fr', 'es' ), true ),
				'i18n'     => array(
					'selectArrival'   => __( 'Choisissez votre date d’arrivée.', 'chalet-booking' ),
					'selectDeparture' => __( 'Choisissez votre date de départ.', 'chalet-booking' ),
					'minNights'       => __( 'Minimum %d nuits', 'chalet-booking' ),
					'arrivalOnly'     => __( 'Arrivées le %s uniquement', 'chalet-booking' ),
					'nights'          => __( '%d nuits', 'chalet-booking' ),
					'total'           => __( 'Total', 'chalet-booking' ),
					'loading'         => __( 'Chargement…', 'chalet-booking' ),
					'error'           => __( 'Une erreur est survenue, merci de réessayer.', 'chalet-booking' ),
					'prev'            => __( 'Mois précédent', 'chalet-booking' ),
					'next'            => __( 'Mois suivant', 'chalet-booking' ),
					'from'            => __( 'dès', 'chalet-booking' ),
					'perNight'        => __( '/ nuit', 'chalet-booking' ),
					'weekdays'        => array_values( array_slice( CB_Settings::weekday_names(), 1, null, true ) ),
				),
			)
		);

		$max   = (int) CB_Settings::get( 'max_guests' );

		ob_start();
		?>
		<div class="cb-booking" data-cb-booking>
			<div class="cb-calendar-wrap">
				<div class="cb-calendar-nav">
					<button type="button" class="cb-prev" aria-label="<?php esc_attr_e( 'Mois précédent', 'chalet-booking' ); ?>">&lsaquo;</button>
					<button type="button" class="cb-next" aria-label="<?php esc_attr_e( 'Mois suivant', 'chalet-booking' ); ?>">&rsaquo;</button>
				</div>
				<div class="cb-calendar" aria-live="polite"></div>
				<ul class="cb-legend">
					<li><span class="cb-swatch cb-swatch-free"></span><?php esc_html_e( 'Disponible', 'chalet-booking' ); ?></li>
					<li><span class="cb-swatch cb-swatch-booked"></span><?php esc_html_e( 'Réservé', 'chalet-booking' ); ?></li>
					<li><span class="cb-swatch cb-swatch-selected"></span><?php esc_html_e( 'Votre séjour', 'chalet-booking' ); ?></li>
				</ul>
			</div>

			<div class="cb-summary">
				<p class="cb-hint"><?php esc_html_e( 'Chargement…', 'chalet-booking' ); ?></p>
				<p class="cb-dates" hidden></p>
				<div class="cb-quote" hidden></div>
				<p class="cb-error" role="alert" hidden></p>
			</div>

			<form class="cb-form" hidden novalidate>
				<div class="cb-row">
					<label><?php esc_html_e( 'Adultes', 'chalet-booking' ); ?>
						<select name="adults">
							<?php for ( $i = 1; $i <= $max; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>" <?php selected( $i, min( 2, $max ) ); ?>><?php echo (int) $i; ?></option>
							<?php endfor; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Enfants', 'chalet-booking' ); ?>
						<select name="children">
							<?php for ( $i = 0; $i < $max; $i++ ) : ?>
								<option value="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></option>
							<?php endfor; ?>
						</select>
					</label>
				</div>
				<label><?php esc_html_e( 'Nom et prénom', 'chalet-booking' ); ?> *
					<input type="text" name="name" required autocomplete="name">
				</label>
				<div class="cb-row">
					<label><?php esc_html_e( 'E-mail', 'chalet-booking' ); ?> *
						<input type="email" name="email" required autocomplete="email">
					</label>
					<label><?php esc_html_e( 'Téléphone', 'chalet-booking' ); ?> *
						<input type="tel" name="phone" required autocomplete="tel">
					</label>
				</div>
				<label><?php esc_html_e( 'Message (heure d’arrivée, forfaits de ski, demandes particulières…)', 'chalet-booking' ); ?>
					<textarea name="message" rows="4"></textarea>
				</label>
				<div class="cb-hp" aria-hidden="true">
					<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>
				<?php echo CB_Content::checkbox_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<button type="submit" class="cb-submit"><?php esc_html_e( 'Envoyer ma demande de réservation', 'chalet-booking' ); ?></button>
				<p class="cb-note"><?php esc_html_e( 'Aucun paiement n’est demandé à ce stade : nous vous confirmons la disponibilité et les modalités par e-mail.', 'chalet-booking' ); ?></p>
			</form>

			<div class="cb-success" role="status" hidden></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
