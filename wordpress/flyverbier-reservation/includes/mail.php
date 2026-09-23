<?php
// E-mails : expéditeur « Fly Verbier » (et non « WordPress »), confirmation immédiate au client,
// récapitulatif complet à l'administrateur, textes modifiables avec des mots-clés.

if (!defined('ABSPATH')) {
    exit;
}

function fvr_site_domain(): string
{
    return preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
}

function fvr_mail_defaults(): array
{
    return [
        'from_name'        => 'Fly Verbier',
        'from_email'       => 'reservation@' . fvr_site_domain(),
        'auto_confirm'     => 1,
        'client_subject'   => 'Votre vol en parapente est confirmé – {date} à {heure}',
        'client_message'   => "Bonjour {prenom},\n\nVotre vol est réservé ! Voici le récapitulatif de votre réservation.",
        'client_info'      => '',
        'client_signature' => "En cas de météo défavorable, nous vous contacterons pour déplacer votre vol.\n\nÀ bientôt dans les airs !\nL'équipe Fly Verbier",
        'screen_message'   => 'Votre vol est réservé ! Un e-mail de confirmation vous a été envoyé à {email}.',
        'reply_to'         => '',
        'terms'            => '',
        'terms_in_email'   => 1,
    ];
}

function fvr_placeholders(array $b): array
{
    $first = trim(explode(' ', trim($b['name']))[0] ?? '');
    return [
        '{prenom}' => $first ?: $b['name'], '{nom}' => $b['name'], '{date}' => fvr_format_date($b['date']),
        '{heure}' => $b['time'], '{vol}' => $b['flight_name'], '{passagers}' => (string) (int) $b['passengers'],
        '{total}' => 'CHF ' . number_format((float) $b['price'], 2, '.', "'"), '{reference}' => $b['reference'],
        '{telephone}' => $b['phone'], '{email}' => $b['email'], '{poids}' => $b['weights'],
    ];
}

function fvr_fill(string $text, array $b): string
{
    return strtr($text, fvr_placeholders($b));
}

// ---------- Expéditeur ----------

// Remplace « WordPress <wordpress@…> » par le nom et l'adresse choisis (sans écraser un plugin SMTP configuré)
add_filter('wp_mail_from_name', function ($name) {
    return $name === 'WordPress' ? fvr_settings()['from_name'] : $name;
});
add_filter('wp_mail_from', function ($email) {
    return stripos($email, 'wordpress@') === 0 ? fvr_settings()['from_email'] : $email;
});

/**
 * Envoie un e-mail HTML + texte (meilleure délivrabilité), avec l'expéditeur du site.
 */
function fvr_mail(string $to, string $subject, string $html, string $text, string $replyTo = ''): bool
{
    if (!is_email($to)) {
        return false;
    }
    $s = fvr_settings();
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $s['from_name'] . ' <' . $s['from_email'] . '>',
    ];
    if ($replyTo && is_email($replyTo)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $alt = function ($phpmailer) use ($text, $s) {
        $phpmailer->AltBody = $text;
        // Adresse de retour alignée sur l'expéditeur (SPF), si aucun plugin SMTP ne l'a déjà définie
        if (empty($phpmailer->Sender) && is_email($s['from_email'])) {
            $phpmailer->Sender = $s['from_email'];
        }
    };
    add_action('phpmailer_init', $alt);
    $ok = wp_mail($to, $subject, $html, $headers);
    remove_action('phpmailer_init', $alt);
    return $ok;
}

// ---------- Mise en page ----------

function fvr_mail_layout(string $title, string $bodyHtml): string
{
    $brand = esc_html(fvr_settings()['from_name']);
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . esc_html($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f3f4;font-family:Arial,Helvetica,sans-serif;color:#1f1f1f;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f4;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#0b57d0;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold;">' . $brand . '</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.55;">' . $bodyHtml . '</td></tr>'
        . '</table><p style="font-size:12px;color:#5f6368;margin:14px 0 0;">' . $brand . ' · ' . esc_html(fvr_site_domain()) . '</p>'
        . '</td></tr></table></body></html>';
}

function fvr_mail_rows(array $rows): string
{
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;background:#f8f9fa;border-radius:8px;">';
    foreach ($rows as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $h .= '<tr><td style="padding:8px 12px;color:#5f6368;font-size:13px;width:38%;vertical-align:top;border-bottom:1px solid #eceff1;">' . esc_html($label)
            . '</td><td style="padding:8px 12px;font-size:14px;font-weight:bold;vertical-align:top;border-bottom:1px solid #eceff1;">' . $value . '</td></tr>';
    }
    return $h . '</table>';
}

function fvr_mail_button(string $url, string $label): string
{
    return '<p style="margin:20px 0 4px;"><a href="' . esc_url($url) . '" style="display:inline-block;background:#0b57d0;color:#ffffff;text-decoration:none;'
        . 'padding:12px 22px;border-radius:24px;font-weight:bold;">' . esc_html($label) . '</a></p>';
}

function fvr_nl2p(string $text): string
{
    $paras = preg_split("/\n{2,}/", trim($text));
    return implode('', array_map(function ($p) {
        return '<p style="margin:0 0 12px;">' . nl2br(esc_html($p)) . '</p>';
    }, array_filter($paras, 'strlen')));
}

function fvr_rows_text(array $rows): string
{
    $out = '';
    foreach ($rows as $label => $value) {
        if ($value !== '' && $value !== null) {
            $out .= $label . ' : ' . wp_strip_all_tags(str_replace('<br>', ', ', $value)) . "\n";
        }
    }
    return $out;
}

// ---------- E-mails d'une réservation ----------

function fvr_booking_row(int $id): ?array
{
    global $wpdb;
    $b = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . fvr_table('bookings') . ' WHERE id = %d', $id), ARRAY_A);
    if (!$b) {
        return null;
    }
    $pilotsById = array_column(fvr_pilots(), null, 'id');
    $b['pilot_names'] = fvr_pilot_names(fvr_assignments([$b])[$id] ?? [], $pilotsById);
    return $b;
}

function fvr_client_rows(array $b): array
{
    return [
        'Référence' => esc_html($b['reference']),
        'Vol'       => esc_html($b['flight_name']),
        'Date'      => esc_html(ucfirst(fvr_format_date($b['date']))),
        'Heure'     => esc_html($b['time']),
        'Passagers' => (int) $b['passengers'],
        'Total'     => 'CHF ' . number_format((float) $b['price'], 2, '.', "'"),
    ];
}

function fvr_send_client_email(array $b): bool
{
    $s = fvr_settings();
    $subject = fvr_fill($s['client_subject'], $b);
    $info = trim(fvr_fill($s['client_info'], $b));
    $html = fvr_nl2p(fvr_fill($s['client_message'], $b))
        . fvr_mail_rows(fvr_client_rows($b))
        . ($info ? '<h3 style="font-size:15px;margin:18px 0 8px;">Informations pratiques</h3>' . fvr_nl2p($info) : '')
        . fvr_nl2p(fvr_fill($s['client_signature'], $b));
    $text = fvr_fill($s['client_message'], $b) . "\n\n" . fvr_rows_text(fvr_client_rows($b))
        . ($info ? "\nInformations pratiques\n" . $info . "\n" : '') . "\n" . fvr_fill($s['client_signature'], $b);
    // Conditions générales acceptées lors de la réservation
    if (trim(wp_strip_all_tags($s['terms'])) !== '' && $s['terms_in_email']) {
        $html .= '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #dadce0;font-size:12px;color:#5f6368;">'
            . '<p style="margin:0 0 8px;font-weight:bold;">Conditions générales (acceptées lors de la réservation)</p>'
            . wp_kses_post(wpautop($s['terms'])) . '</div>';
        $text .= "\n\n---\nConditions générales (acceptées lors de la réservation)\n" . wp_strip_all_tags($s['terms']);
    }
    // Réponses du client : adresse choisie, sinon l'adresse de réservation
    return fvr_mail($b['email'], $subject, fvr_mail_layout($subject, $html), $text, $s['reply_to'] ?: $s['admin_email']);
}

function fvr_send_admin_email(array $b): bool
{
    $s = fvr_settings();
    if (!$s['admin_email']) {
        return false;
    }
    $labels = fvr_status_labels();
    $tel = preg_replace('/[^0-9+]/', '', $b['phone']);
    $rows = fvr_client_rows($b) + [
        'Statut'    => esc_html($labels[$b['status']] ?? $b['status']),
        'Client'    => esc_html($b['name']),
        'Téléphone' => $b['phone'] ? '<a href="tel:' . esc_attr($tel) . '" style="color:#0b57d0;">' . esc_html($b['phone']) . '</a>' : '',
        'E-mail'    => $b['email'] ? '<a href="mailto:' . esc_attr($b['email']) . '" style="color:#0b57d0;">' . esc_html($b['email']) . '</a>' : '',
        'Poids'     => $b['weights'] ? esc_html($b['weights']) . ' kg' : '',
        'Pilotes'   => esc_html(implode(', ', $b['pilot_names'])),
        'Message'   => $b['message'] ? nl2br(esc_html($b['message'])) : '',
        'Reçue le'  => esc_html(mysql2date('d.m.Y à H:i', $b['created_at'])),
    ];
    $subject = 'Nouvelle réservation ' . $b['reference'] . ' – ' . fvr_short_date($b['date']) . ' ' . $b['time'] . ' – '
        . $b['name'] . ' (' . (int) $b['passengers'] . ' pax)';
    $url = add_query_arg('d', $b['date'], fvr_admin_planning_url());
    $html = '<p style="margin:0 0 4px;font-size:17px;font-weight:bold;">Nouvelle réservation en ligne</p>'
        . fvr_mail_rows($rows) . fvr_mail_button($url, 'Ouvrir dans l\'agenda');
    $text = "Nouvelle réservation en ligne\n\n" . fvr_rows_text($rows) . "\nAgenda : " . $url;
    return fvr_mail($s['admin_email'], $subject, fvr_mail_layout($subject, $html), $text, $b['email']);
}

function fvr_send_booking_emails(int $id): void
{
    $b = fvr_booking_row($id);
    if (!$b) {
        return;
    }
    fvr_send_admin_email($b);
    if (fvr_settings()['send_client_email']) {
        fvr_send_client_email($b);
    }
}

// Message affiché au client juste après la réservation
function fvr_screen_message(int $id): string
{
    $b = fvr_booking_row($id);
    return $b ? fvr_fill(fvr_settings()['screen_message'], $b) : '';
}

// Réservation fictive pour l'e-mail de test
function fvr_sample_booking(): array
{
    return [
        'id' => 0, 'reference' => fvr_settings()['ref_prefix'] . '-EXEMPLE', 'date' => gmdate('Y-m-d', strtotime(fvr_today() . ' +7 days')),
        'time' => '10:00', 'flight_name' => 'Vol découverte', 'passengers' => 2, 'price' => 360, 'name' => 'Marie Exemple',
        'email' => fvr_settings()['admin_email'], 'phone' => '+41 79 123 45 67', 'weights' => '62, 80', 'message' => 'Anniversaire de Paul',
        'status' => 'confirmed', 'created_at' => fvr_now(), 'pilot_names' => ['à définir', 'à définir'],
    ];
}

// ---------- Conditions générales ----------

function fvr_terms_html(): string
{
    $terms = fvr_settings()['terms'];
    return trim(wp_strip_all_tags($terms)) === '' ? '' : wp_kses_post(wpautop($terms));
}

// Affiche les conditions générales sur une page : [conditions_parapente]
add_shortcode('conditions_parapente', function () {
    $html = fvr_terms_html();
    return $html ? '<div class="fvr-terms-page">' . $html . '</div>' : '';
});
