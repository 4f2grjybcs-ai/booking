<?php
// Formulaire client : à insérer dans une page avec le shortcode [reservation_parapente]

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('reservation_parapente', function () {
    wp_enqueue_style('fvr-booking', FVR_URL . 'assets/booking.css', [], FVR_VERSION);
    wp_enqueue_script('fvr-booking', FVR_URL . 'assets/booking.js', [], FVR_VERSION, true);
    wp_localize_script('fvr-booking', 'fvrConfig', [
        'api'     => esc_url_raw(rest_url('fvr/v1/')),
        'maxDays' => (int) fvr_settings()['max_days_ahead'],
        'today'   => fvr_today(),
    ]);

    ob_start();
    ?>
    <div class="fvr-booking">
      <form class="fvr-form" novalidate>
        <section class="fvr-step">
          <h3><span class="fvr-num">1</span> Type de vol</h3>
          <div class="fvr-flights"><p class="fvr-muted">Chargement…</p></div>
        </section>

        <section class="fvr-step">
          <h3><span class="fvr-num">2</span> Date, heure et passagers</h3>
          <div class="fvr-row">
            <label>Date
              <input type="date" name="date" class="fvr-date" required>
            </label>
            <label>Nombre de passagers
              <select name="passengers" class="fvr-pax">
                <?php for ($i = 1; $i <= 8; $i++): ?><option><?php echo $i; ?></option><?php endfor; ?>
              </select>
            </label>
          </div>
          <div class="fvr-slots"><p class="fvr-muted">Choisissez une date pour voir les horaires disponibles.</p></div>
        </section>

        <section class="fvr-step">
          <h3><span class="fvr-num">3</span> Vos coordonnées</h3>
          <div class="fvr-row">
            <label>Nom et prénom <input type="text" name="name" required maxlength="100" autocomplete="name"></label>
            <label>Téléphone <input type="tel" name="phone" required autocomplete="tel" placeholder="+41 79 123 45 67"></label>
          </div>
          <div class="fvr-row">
            <label>E-mail <input type="email" name="email" required autocomplete="email"></label>
            <label>Poids des passagers (kg) <input type="text" name="weights" maxlength="200" placeholder="ex. 75, 62"></label>
          </div>
          <label class="fvr-block">Message (facultatif)
            <textarea name="message" rows="3" maxlength="2000" placeholder="Occasion spéciale, bon cadeau, questions…"></textarea>
          </label>
          <label class="fvr-hp" aria-hidden="true">Site web <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
          <label class="fvr-check">
            <input type="checkbox" name="accept" value="1" required>
            <span>Je confirme que les passagers sont en bonne santé et j'accepte que le vol puisse être
            déplacé ou annulé en raison de la météo.</span>
          </label>
        </section>

        <div class="fvr-summary" hidden></div>
        <p class="fvr-error" role="alert" hidden></p>
        <button type="submit" class="fvr-submit">Envoyer ma réservation</button>
      </form>

      <section class="fvr-done" hidden>
        <h3>Merci ! Votre demande est enregistrée.</h3>
        <p>Votre référence : <strong class="fvr-done-ref"></strong></p>
        <p>Nous vous contacterons rapidement pour confirmer votre vol. Un e-mail récapitulatif vous a été envoyé.</p>
      </section>
    </div>
    <?php
    return ob_get_clean();
});
