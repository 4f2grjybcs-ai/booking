<?php
// Back office dans l'administration WordPress : menu « Réservations ».

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    global $wpdb;
    $pending = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . fvr_table('bookings')
        . " WHERE status = 'pending' AND date >= '" . esc_sql(fvr_today()) . "'");
    $bubble = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';

    add_menu_page('Réservations', 'Réservations' . $bubble, fvr_cap(), 'fvr', 'fvr_page_bookings', 'dashicons-calendar-alt', 26);
    add_submenu_page('fvr', 'Réservations', 'Toutes les réservations', fvr_cap(), 'fvr', 'fvr_page_bookings');
    add_submenu_page('fvr', 'Nouvelle réservation', 'Ajouter', fvr_cap(), 'fvr-edit', 'fvr_page_edit');
    add_submenu_page('fvr', 'Pilotes', 'Pilotes', fvr_cap(), 'fvr-pilots', 'fvr_page_pilots');
    add_submenu_page('fvr', 'Paramètres des réservations', 'Paramètres', fvr_cap(), 'fvr-settings', 'fvr_page_settings');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'fvr') !== false) {
        wp_enqueue_style('fvr-admin', FVR_URL . 'assets/admin.css', [], FVR_VERSION);
    }
});

// ---------- Messages après une action ----------

function fvr_flash(string $msg, bool $error = false): void
{
    set_transient('fvr_flash_' . get_current_user_id(), [$msg, $error], 60);
}

function fvr_show_flash(): void
{
    $key = 'fvr_flash_' . get_current_user_id();
    $f = get_transient($key);
    if ($f) {
        delete_transient($key);
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $f[1] ? 'error' : 'success', esc_html($f[0]));
    }
}

function fvr_back(string $url, string $msg = '', bool $error = false): void
{
    if ($msg) {
        fvr_flash($msg, $error);
    }
    wp_safe_redirect($url);
    exit;
}

function fvr_form_open(string $action, string $extra = ''): string
{
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" ' . $extra . '>'
        . '<input type="hidden" name="action" value="fvr">'
        . '<input type="hidden" name="fvr_action" value="' . esc_attr($action) . '">'
        . wp_nonce_field('fvr', '_wpnonce', true, false);
}

// ---------- Traitement des formulaires ----------

add_action('admin_post_fvr', function () {
    global $wpdb;
    if (!current_user_can(fvr_cap())) {
        wp_die('Accès refusé.');
    }
    check_admin_referer('fvr');
    $p = wp_unslash($_POST);
    $settingsUrl = admin_url('admin.php?page=fvr-settings');
    $listUrl = admin_url('admin.php?page=fvr');
    $back = wp_get_referer() ?: $listUrl;

    switch ($p['fvr_action'] ?? '') {
        case 'status':
            if (!isset(fvr_status_labels()[$p['status'] ?? ''])) {
                fvr_back($back, 'Statut invalide.', true);
            }
            $before = fvr_booking_snapshot((int) $p['id']);
            $wpdb->update(fvr_table('bookings'), ['status' => $p['status'], 'updated_at' => fvr_now()], ['id' => (int) $p['id']]);
            fvr_notify_changes($before, fvr_booking_snapshot((int) $p['id']));
            fvr_back($back, 'Statut mis à jour.');

        case 'save_booking':
            $id = (int) ($p['id'] ?? 0);
            $saved = fvr_save_booking($p, $id);
            if (is_wp_error($saved)) {
                fvr_back(admin_url('admin.php?page=fvr-edit' . ($id ? '&id=' . $id : '')), $saved->get_error_message(), true);
            }
            fvr_back(admin_url('admin.php?page=fvr-edit&id=' . $saved), $id ? 'Réservation enregistrée.' : 'Réservation créée.');

        case 'delete_booking':
            fvr_delete_booking((int) $p['id']);
            fvr_back($listUrl, 'Réservation supprimée.');

        case 'save_flight':
            $id = (int) ($p['id'] ?? 0);
            $data = [
                'name' => sanitize_text_field($p['name'] ?? ''), 'description' => sanitize_textarea_field($p['description'] ?? ''),
                'duration' => sanitize_text_field($p['duration'] ?? ''), 'price' => (float) ($p['price'] ?? 0),
                'active' => empty($p['active']) ? 0 : 1, 'sort_order' => (int) ($p['sort_order'] ?? 0),
            ];
            if ($data['name'] === '') {
                fvr_back($settingsUrl, 'Le nom du vol est requis.', true);
            }
            $id ? $wpdb->update(fvr_table('flights'), $data, ['id' => $id]) : $wpdb->insert(fvr_table('flights'), $data);
            fvr_back($settingsUrl, 'Vol enregistré.');

        case 'delete_flight':
            $wpdb->update(fvr_table('bookings'), ['flight_id' => null], ['flight_id' => (int) $p['id']]);
            $wpdb->delete(fvr_table('flights'), ['id' => (int) $p['id']]);
            fvr_back($settingsUrl, 'Vol supprimé.');

        case 'save_slot':
            $id = (int) ($p['id'] ?? 0);
            $time = substr($p['time'] ?? '', 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
                fvr_back($settingsUrl, 'Heure invalide.', true);
            }
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . fvr_table('slots') . ' WHERE time = %s AND id <> %d', $time, $id));
            if ($exists) {
                fvr_back($settingsUrl, 'Ce créneau existe déjà.', true);
            }
            $data = ['time' => $time, 'capacity' => max(0, (int) ($p['capacity'] ?? 0)), 'active' => empty($p['active']) ? 0 : 1,
                     'valid_until' => fvr_valid_date($p['valid_until'] ?? '') ? $p['valid_until'] : null];
            $id ? $wpdb->update(fvr_table('slots'), $data, ['id' => $id]) : $wpdb->insert(fvr_table('slots'), $data);
            fvr_back($settingsUrl, 'Créneau enregistré.');

        case 'delete_slot':
            $wpdb->delete(fvr_table('slots'), ['id' => (int) $p['id']]);
            fvr_back($settingsUrl, 'Créneau supprimé.');

        case 'block_date':
            $from = $p['from'] ?? '';
            $to = ($p['to'] ?? '') ?: $from;
            if (!fvr_valid_date($from) || !fvr_valid_date($to) || $to < $from) {
                fvr_back($settingsUrl, 'Dates invalides.', true);
            }
            $reason = sanitize_text_field($p['reason'] ?? '');
            for ($d = $from, $n = 0; $d <= $to && $n < 400; $d = gmdate('Y-m-d', strtotime($d . ' 12:00:00 +1 day')), $n++) {
                $wpdb->replace(fvr_table('blocked'), ['date' => $d, 'reason' => $reason]);
            }
            fvr_back($settingsUrl, 'Jour(s) fermé(s) enregistré(s).');

        case 'unblock_date':
            $wpdb->delete(fvr_table('blocked'), ['date' => $p['date'] ?? '']);
            fvr_back($settingsUrl, 'Jour réouvert.');

        case 'save_pilot':
            $pilotsUrl = admin_url('admin.php?page=fvr-pilots');
            $id = (int) ($p['id'] ?? 0);
            $color = sanitize_hex_color($p['color'] ?? '') ?: '#0b57d0';
            $data = [
                'name' => sanitize_text_field($p['name'] ?? ''), 'phone' => sanitize_text_field($p['phone'] ?? ''),
                'email' => sanitize_email($p['email'] ?? ''), 'color' => $color,
                'default_rank' => max(0, (int) ($p['default_rank'] ?? 0)), 'active' => empty($p['active']) ? 0 : 1,
            ];
            if ($data['name'] === '') {
                fvr_back($pilotsUrl, 'Le nom du pilote est requis.', true);
            }
            if ($id) {
                $wpdb->update(fvr_table('pilots'), $data, ['id' => $id]);
            } else {
                $data['token'] = fvr_new_pilot_token();
                $wpdb->insert(fvr_table('pilots'), $data);
            }
            fvr_back($pilotsUrl, 'Pilote enregistré.');

        case 'delete_pilot':
            $wpdb->delete(fvr_table('assign'), ['pilot_id' => (int) $p['id']]);
            $wpdb->delete(fvr_table('pilots'), ['id' => (int) $p['id']]);
            fvr_back(admin_url('admin.php?page=fvr-pilots'), 'Pilote supprimé. Ses vols sont repassés « à définir ».');

        case 'push_test_pilot':
            $sent = fvr_push_to_pilot((int) $p['id'], ['title' => '🔔 Test', 'body' => 'Notification de test envoyée par l\'administrateur.', 'tag' => 'fvr-test']);
            fvr_back(admin_url('admin.php?page=fvr-pilots'), $sent ? 'Notification envoyée.' : 'Envoi impossible (appareil hors ligne ou notifications désactivées).', !$sent);

        case 'regen_pilot_token':
            $wpdb->update(fvr_table('pilots'), ['token' => fvr_new_pilot_token()], ['id' => (int) $p['id']]);
            fvr_back(admin_url('admin.php?page=fvr-pilots'), 'Nouveau lien créé pour ce pilote : l\'ancien ne fonctionne plus.');

        case 'regen_admin_token':
            fvr_regenerate_admin_token();
            fvr_back($settingsUrl, 'Nouveau lien administrateur créé : l\'ancien ne fonctionne plus. Pensez à remplacer l\'icône sur votre téléphone.');

        case 'regen_token':
            fvr_regenerate_planning_token();
            fvr_back($settingsUrl, 'Nouveau lien créé : l\'ancien lien ne fonctionne plus. Envoyez le nouveau à vos pilotes.');

        case 'save_period':
            $until = (string) ($p['booking_until'] ?? '');
            update_option('fvr_settings', array_merge(fvr_settings(), [
                'booking_until'  => fvr_valid_date($until) ? $until : '',
                'max_days_ahead' => max(1, (int) ($p['max_days_ahead'] ?? 365)),
            ]));
            fvr_back($settingsUrl, 'Période de réservation enregistrée.');

        case 'save_settings':
            update_option('fvr_settings', array_merge(fvr_settings(), [
                'admin_email'       => sanitize_email($p['admin_email'] ?? ''),
                'send_client_email' => empty($p['send_client_email']) ? 0 : 1,
                'auto_confirm'      => empty($p['auto_confirm']) ? 0 : 1,
                'ref_prefix'        => sanitize_text_field($p['ref_prefix'] ?? 'FV'),
                'from_name'         => sanitize_text_field($p['from_name'] ?? '') ?: fvr_mail_defaults()['from_name'],
                'from_email'        => sanitize_email($p['from_email'] ?? '') ?: fvr_mail_defaults()['from_email'],
                'client_subject'    => sanitize_text_field($p['client_subject'] ?? ''),
                'client_message'    => sanitize_textarea_field($p['client_message'] ?? ''),
                'client_info'       => sanitize_textarea_field($p['client_info'] ?? ''),
                'client_signature'  => sanitize_textarea_field($p['client_signature'] ?? ''),
                'screen_message'    => sanitize_textarea_field($p['screen_message'] ?? ''),
                'terms'             => wp_kses_post($p['terms'] ?? ''),
                'reply_to'          => sanitize_email($p['reply_to'] ?? ''),
                'terms_in_email'    => empty($p['terms_in_email']) ? 0 : 1,
            ]));
            fvr_back($settingsUrl, 'Réglages enregistrés.');

        case 'test_email':
            $sample = fvr_sample_booking();
            $ok = fvr_send_client_email($sample) && fvr_send_admin_email($sample);
            fvr_back($settingsUrl, $ok ? 'E-mails de test envoyés à ' . fvr_settings()['admin_email'] . '. Vérifiez aussi le dossier spam.'
                : "L'envoi a échoué : votre hébergement n'envoie pas d'e-mails. Installez l'extension « WP Mail SMTP ».", !$ok);
    }
    fvr_back($listUrl);
});

// ---------- Liste filtrée des réservations ----------

function fvr_filter_args(): array
{
    $g = wp_unslash($_GET);
    return [
        'from'   => isset($g['from']) ? (string) $g['from'] : fvr_today(),
        'to'     => (string) ($g['to'] ?? ''),
        'status' => (string) ($g['status'] ?? ''),
        'q'      => sanitize_text_field($g['q'] ?? ''),
    ];
}

function fvr_query_bookings(array $f): array
{
    global $wpdb;
    $where = ['1=1'];
    $args = [];
    if (fvr_valid_date($f['from'])) { $where[] = 'date >= %s'; $args[] = $f['from']; }
    if (fvr_valid_date($f['to'])) { $where[] = 'date <= %s'; $args[] = $f['to']; }
    if (isset(fvr_status_labels()[$f['status']])) { $where[] = 'status = %s'; $args[] = $f['status']; }
    if ($f['q'] !== '') {
        $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR reference LIKE %s)';
        $like = '%' . $wpdb->esc_like($f['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM ' . fvr_table('bookings') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY date, time, id';
    return $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
}

// Export CSV (ouvrable dans Excel)
add_action('admin_post_fvr_export', function () {
    if (!current_user_can(fvr_cap())) {
        wp_die('Accès refusé.');
    }
    check_admin_referer('fvr_export');
    $labels = fvr_status_labels();
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations-' . fvr_today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Référence', 'Date', 'Heure', 'Vol', 'Passagers', 'Prix CHF', 'Nom', 'E-mail', 'Téléphone',
                   'Poids', 'Message', 'Statut', 'Pilotes', 'Notes internes', 'Créée le'], ';', '"', '\\');
    $rows = fvr_query_bookings(fvr_filter_args());
    $assign = fvr_assignments($rows);
    $pilotsById = array_column(fvr_pilots(), null, 'id');
    foreach ($rows as $b) {
        fputcsv($out, [$b['reference'], $b['date'], $b['time'], $b['flight_name'], $b['passengers'], $b['price'],
                       $b['name'], $b['email'], $b['phone'], $b['weights'], $b['message'],
                       $labels[$b['status']] ?? $b['status'], implode(', ', fvr_pilot_names($assign[(int) $b['id']] ?? [], $pilotsById)),
                       $b['admin_notes'], $b['created_at']], ';', '"', '\\');
    }
    exit;
});

function fvr_status_select(string $current, string $extra = ''): string
{
    $html = '<select name="status" ' . $extra . '>';
    foreach (fvr_status_labels() as $k => $label) {
        $html .= '<option value="' . esc_attr($k) . '"' . selected($k, $current, false) . '>' . esc_html($label) . '</option>';
    }
    return $html . '</select>';
}

function fvr_chf($n): string
{
    return number_format((float) $n, 0, '.', "'");
}

// ---------- Page : liste des réservations ----------

function fvr_page_bookings(): void
{
    $f = fvr_filter_args();
    $bookings = fvr_query_bookings($f);
    $labels = fvr_status_labels();
    $active = array_filter($bookings, function ($b) { return $b['status'] !== 'cancelled'; });
    $pending = count(array_filter($bookings, function ($b) { return $b['status'] === 'pending'; }));
    $exportUrl = wp_nonce_url(add_query_arg(array_merge(['action' => 'fvr_export'], $f), admin_url('admin-post.php')), 'fvr_export');
    ?>
    <div class="wrap fvr-admin">
      <h1 class="wp-heading-inline">Réservations</h1>
      <a href="<?php echo esc_url(admin_url('admin.php?page=fvr-edit')); ?>" class="page-title-action">Ajouter</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=fvr-calendar')); ?>" class="page-title-action">Calendrier</a>
      <hr class="wp-header-end">
      <?php fvr_show_flash(); ?>

      <div class="fvr-stats">
        <div><span>Réservations</span><b><?php echo count($active); ?></b></div>
        <div><span>Passagers</span><b><?php echo array_sum(array_column($active, 'passengers')); ?></b></div>
        <div><span>En attente</span><b><?php echo $pending; ?></b></div>
        <div><span>Chiffre d'affaires</span><b>CHF <?php echo fvr_chf(array_sum(array_column($active, 'price'))); ?></b></div>
      </div>

      <form method="get" class="fvr-filters">
        <input type="hidden" name="page" value="fvr">
        <label>Du <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>"></label>
        <label>Au <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>"></label>
        <label>Statut
          <select name="status">
            <option value="">Tous</option>
            <?php foreach ($labels as $k => $l): ?>
              <option value="<?php echo esc_attr($k); ?>"<?php selected($k, $f['status']); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Recherche <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="Nom, e-mail, réf…"></label>
        <button class="button">Filtrer</button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=fvr&from=')); ?>">Tout l'historique</a>
        <a class="button" href="<?php echo esc_url($exportUrl); ?>">Exporter Excel (CSV)</a>
      </form>

      <?php if (!$bookings): ?>
        <p>Aucune réservation pour ces critères.</p>
      <?php else: ?>
      <table class="widefat striped fvr-table">
        <thead><tr><th>Heure</th><th>Réf.</th><th>Client</th><th>Contact</th><th>Vol</th><th class="num">Pax</th><th>Poids</th><th>Pilotes</th><th class="num">Prix</th><th>Statut</th></tr></thead>
        <tbody>
        <?php $assign = fvr_assignments($bookings); $pilotsById = array_column(fvr_pilots(), null, 'id'); ?>
        <?php $lastDate = null; foreach ($bookings as $b): $edit = admin_url('admin.php?page=fvr-edit&id=' . (int) $b['id']); ?>
          <?php if ($b['date'] !== $lastDate): $lastDate = $b['date']; ?>
            <tr class="fvr-day"><td colspan="10"><?php echo esc_html(ucfirst(fvr_format_date($b['date']))); ?></td></tr>
          <?php endif; ?>
          <tr>
            <td><?php echo esc_html($b['time']); ?></td>
            <td><a href="<?php echo esc_url($edit); ?>"><strong><?php echo esc_html($b['reference']); ?></strong></a></td>
            <td>
              <?php echo esc_html($b['name']); ?>
              <?php if ($b['message']): ?><br><small title="<?php echo esc_attr($b['message']); ?>">💬 <?php echo esc_html(mb_strimwidth($b['message'], 0, 40, '…')); ?></small><?php endif; ?>
              <?php if ($b['admin_notes']): ?><br><small title="<?php echo esc_attr($b['admin_notes']); ?>">📝 <?php echo esc_html(mb_strimwidth($b['admin_notes'], 0, 40, '…')); ?></small><?php endif; ?>
            </td>
            <td>
              <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $b['phone'])); ?>"><?php echo esc_html($b['phone']); ?></a><br>
              <a href="mailto:<?php echo esc_attr($b['email']); ?>"><?php echo esc_html($b['email']); ?></a>
            </td>
            <td><?php echo esc_html($b['flight_name']); ?></td>
            <td class="num"><?php echo (int) $b['passengers']; ?></td>
            <td><?php echo esc_html($b['weights']); ?></td>
            <td><?php foreach ($assign[(int) $b['id']] ?? [] as $pid): ?>
              <?php if ($pid && isset($pilotsById[$pid])): ?><span class="fvr-pilot" style="--c:<?php echo esc_attr($pilotsById[$pid]['color']); ?>"><?php echo esc_html($pilotsById[$pid]['name']); ?></span>
              <?php else: ?><span class="fvr-pilot fvr-pilot-missing">à définir</span><?php endif; ?>
            <?php endforeach; ?></td>
            <td class="num"><?php echo fvr_chf($b['price']); ?></td>
            <td>
              <?php echo fvr_form_open('status'); ?>
                <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                <?php echo fvr_status_select($b['status'], 'onchange="this.form.submit()" aria-label="Statut" class="fvr-status-' . esc_attr($b['status']) . '"'); ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php
}

// ---------- Page : ajout / modification d'une réservation ----------

function fvr_page_edit(): void
{
    global $wpdb;
    $id = (int) ($_GET['id'] ?? 0);
    $b = ['id' => 0, 'reference' => '', 'date' => fvr_today(), 'time' => '', 'flight_id' => 0, 'flight_name' => '',
          'price' => 0, 'passengers' => 1, 'name' => '', 'email' => '', 'phone' => '', 'weights' => '',
          'message' => '', 'status' => 'confirmed', 'admin_notes' => '', 'created_at' => ''];
    if ($id) {
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . fvr_table('bookings') . ' WHERE id = %d', $id), ARRAY_A);
        if (!$row) {
            echo '<div class="wrap"><p>Réservation introuvable.</p></div>';
            return;
        }
        $b = $row;
    }
    $flights = $wpdb->get_results('SELECT * FROM ' . fvr_table('flights') . ' ORDER BY sort_order, id', ARRAY_A);
    ?>
    <div class="wrap fvr-admin">
      <h1><?php echo $id ? 'Réservation ' . esc_html($b['reference']) : 'Nouvelle réservation (téléphone, sur place…)'; ?></h1>
      <?php fvr_show_flash(); ?>
      <?php if ($id): ?><p class="description">Reçue le <?php echo esc_html($b['created_at']); ?></p><?php endif; ?>

      <?php echo fvr_form_open('save_booking', 'class="fvr-panel"'); ?>
        <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
        <input type="hidden" name="flight_name" value="<?php echo esc_attr($b['flight_name']); ?>">
        <div class="fvr-grid">
          <label>Date <input type="date" name="date" value="<?php echo esc_attr($b['date']); ?>" required></label>
          <label>Heure
            <select name="time" required>
              <?php foreach (fvr_time_choices((string) $b['time']) as $t): ?><option<?php selected($t, $b['time']); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Vol
            <select name="flight_id" id="fvr-flight">
              <?php if ($id && !$b['flight_id']): ?>
                <option value="0" data-price="<?php echo esc_attr($b['price'] / max(1, $b['passengers'])); ?>" selected><?php echo esc_html($b['flight_name']); ?> (supprimé)</option>
              <?php endif; ?>
              <?php foreach ($flights as $fl): ?>
                <option value="<?php echo (int) $fl['id']; ?>" data-price="<?php echo esc_attr($fl['price']); ?>"<?php selected((int) $fl['id'], (int) $b['flight_id']); ?>>
                  <?php echo esc_html($fl['name']); ?> (CHF <?php echo fvr_chf($fl['price']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Passagers <input type="number" name="passengers" id="fvr-pax" min="1" value="<?php echo (int) $b['passengers']; ?>"></label>
          <label>Prix total CHF <input type="number" step="0.01" name="price" id="fvr-price" value="<?php echo esc_attr($b['price']); ?>"></label>
          <label>Statut <?php echo fvr_status_select($b['status']); ?></label>
          <label>Nom <input type="text" name="name" value="<?php echo esc_attr($b['name']); ?>" required></label>
          <label>E-mail <input type="email" name="email" value="<?php echo esc_attr($b['email']); ?>"></label>
          <label>Téléphone <input type="tel" name="phone" value="<?php echo esc_attr($b['phone']); ?>"></label>
          <label>Poids <input type="text" name="weights" value="<?php echo esc_attr($b['weights']); ?>"></label>
        </div>
        <?php
          $allPilots = fvr_pilots();
          $seats = $id ? (fvr_assignments([$b])[$id] ?? []) : array_fill(0, max(1, (int) $b['passengers']), 'auto');
          $busy = $id ? fvr_busy_pilots($b['date'], $b['time'], $id) : [];
          $pilotOptions = function ($selected) use ($allPilots, $busy, $id) {
              $h = $id ? '' : '<option value="auto"' . selected($selected, 'auto', false) . '>Automatique (ordre par défaut)</option>';
              $h .= '<option value="0"' . ($selected === 0 ? ' selected' : '') . '>— À définir —</option>';
              foreach ($allPilots as $pl) {
                  $h .= '<option value="' . (int) $pl['id'] . '"' . ($selected === $pl['id'] ? ' selected' : '') . '>' . esc_html($pl['name'])
                      . ($pl['default_rank'] ? ' (n°' . (int) $pl['default_rank'] . ')' : '') . (in_array($pl['id'], $busy, true) ? ' – déjà en vol à cette heure' : '')
                      . ($pl['active'] ? '' : ' – inactif') . '</option>';
              }
              return $h;
          };
        ?>
        <fieldset class="fvr-seats">
          <legend>Pilotes (un par passager)</legend>
          <?php if (!$allPilots): ?>
            <p class="description">Aucun pilote enregistré. <a href="<?php echo esc_url(admin_url('admin.php?page=fvr-pilots')); ?>">Créer les profils pilotes</a></p>
          <?php endif; ?>
          <div id="fvr-seats">
            <?php foreach ($seats as $i => $sel): ?>
              <label>Passager <?php echo $i + 1; ?> <select name="pilots[]"><?php echo $pilotOptions($sel); ?></select></label>
            <?php endforeach; ?>
          </div>
          <template id="fvr-seat-tpl"><label>Passager <span></span> <select name="pilots[]"><?php echo $pilotOptions($id ? 0 : 'auto'); ?></select></label></template>
        </fieldset>
        <label>Message du client <textarea name="message" rows="3"><?php echo esc_textarea($b['message']); ?></textarea></label>
        <label>Notes internes (paiement…) <textarea name="admin_notes" rows="3"><?php echo esc_textarea($b['admin_notes']); ?></textarea></label>
        <p>
          <button class="button button-primary">Enregistrer</button>
          <?php if ($b['email']): ?><a class="button" href="mailto:<?php echo esc_attr($b['email']); ?>">Écrire au client</a><?php endif; ?>
          <?php if ($b['phone']): ?><a class="button" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $b['phone'])); ?>">Appeler</a><?php endif; ?>
        </p>
      </form>

      <?php if ($id): ?>
        <?php echo fvr_form_open('delete_booking', 'onsubmit="return confirm(\'Supprimer définitivement cette réservation ?\')"'); ?>
          <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
          <button class="button-link-delete button-link">Supprimer la réservation</button>
        </form>
      <?php endif; ?>
      <p><a href="<?php echo esc_url(admin_url('admin.php?page=fvr')); ?>">← Retour aux réservations</a></p>
    </div>
    <script>
      // Recalcule le prix total quand on change le vol ou le nombre de passagers
      (function () {
        var f = document.getElementById('fvr-flight'), p = document.getElementById('fvr-pax'), t = document.getElementById('fvr-price');
        function upd() { if (f.selectedOptions[0]) t.value = (parseFloat(f.selectedOptions[0].dataset.price) || 0) * (parseInt(p.value, 10) || 0); }
        f.addEventListener('change', upd); p.addEventListener('input', upd);
        // Un sélecteur de pilote par passager
        var seats = document.getElementById('fvr-seats'), tpl = document.getElementById('fvr-seat-tpl');
        p.addEventListener('input', function () {
          var n = Math.max(1, parseInt(p.value, 10) || 1);
          while (seats.children.length < n) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.querySelector('span').textContent = seats.children.length + 1;
            seats.appendChild(node);
          }
          while (seats.children.length > n) seats.removeChild(seats.lastElementChild);
        });
        <?php if (!$id): ?>upd();<?php endif; ?>
      })();
    </script>
    <?php
}

// ---------- Page : paramètres ----------

function fvr_page_settings(): void
{
    global $wpdb;
    $flights = $wpdb->get_results('SELECT * FROM ' . fvr_table('flights') . ' ORDER BY sort_order, id', ARRAY_A);
    $slots = $wpdb->get_results('SELECT * FROM ' . fvr_table('slots') . ' ORDER BY time', ARRAY_A);
    $blocked = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . fvr_table('blocked') . ' WHERE date >= %s ORDER BY date',
        gmdate('Y-m-d', strtotime(fvr_today() . ' -30 days'))), ARRAY_A);
    $s = fvr_settings();
    $newFlight = ['id' => 0, 'name' => '', 'description' => '', 'duration' => '', 'price' => '', 'active' => 1, 'sort_order' => count($flights)];
    $newSlot = ['id' => 0, 'time' => '', 'capacity' => 4, 'active' => 1];
    ?>
    <div class="wrap fvr-admin">
      <h1>Paramètres des réservations</h1>
      <?php fvr_show_flash(); ?>

      <div class="fvr-panel">
        <h2>Afficher le formulaire sur le site</h2>
        <p>Créez une page (ex. « Réserver ») et insérez-y ce code (bloc « Code court » / « Shortcode ») :</p>
        <p><code class="fvr-code">[reservation_parapente]</code></p>
      </div>

      <div class="fvr-panel fvr-admin-link">
        <h2>🔒 Votre agenda administrateur (lien secret)</h2>
        <p>Ce lien ouvre l'agenda <strong>en mode modification, sans connexion WordPress</strong>.
          Ouvrez-le sur votre téléphone puis « Ajouter à l'écran d'accueil ».
          <strong>Ne le communiquez à personne</strong> : toute personne qui l'a peut modifier et supprimer des réservations.</p>
        <p class="fvr-linkrow"><input type="text" class="large-text code" readonly value="<?php echo esc_attr(fvr_admin_planning_url()); ?>" onclick="this.select()">
          <a class="button button-primary" href="<?php echo esc_url(fvr_admin_planning_url()); ?>" target="_blank">Ouvrir</a></p>
        <?php echo fvr_form_open('regen_admin_token', 'onsubmit="return confirm(\'L\\\'ancien lien administrateur ne fonctionnera plus. Continuer ?\')"'); ?>
          <p><button class="button">Générer un nouveau lien administrateur</button>
          <span class="description">À faire immédiatement si ce lien a été vu par quelqu'un d'autre ou si vous perdez votre téléphone.</span></p>
        </form>
      </div>

      <div class="fvr-panel">
        <h2>Calendrier des pilotes</h2>
        <p>Envoyez ce lien à vos pilotes (WhatsApp, e-mail…) : ils voient le planning des vols <strong>sans pouvoir rien modifier</strong>
          et sans voir les prix ni les e-mails des clients. Vous-même, connecté à WordPress, pouvez modifier les réservations depuis cette même page.</p>
        <p><input type="text" class="large-text code fvr-copy" readonly value="<?php echo esc_attr(fvr_planning_url()); ?>" onclick="this.select()">
          <a class="button" href="<?php echo esc_url(fvr_planning_url()); ?>" target="_blank">Ouvrir</a></p>
        <p class="description">Abonnement agenda (Google Agenda, iPhone, Outlook) — se met à jour automatiquement :</p>
        <p><input type="text" class="large-text code fvr-copy" readonly value="<?php echo esc_attr(fvr_ics_url()); ?>" onclick="this.select()"></p>
        <?php echo fvr_form_open('regen_token', 'onsubmit="return confirm(\'L\\\'ancien lien ne fonctionnera plus. Continuer ?\')"'); ?>
          <p><button class="button">Générer un nouveau lien</button>
          <span class="description">À faire si le lien a été transmis à une personne qui ne doit plus y avoir accès.</span></p>
        </form>
      </div>

      <?php
        $until = $s['booking_until'];
        $last = fvr_booking_last_day();
        $endOfMonth = wp_date('Y-m-t');
        $endOfNext = wp_date('Y-m-t', strtotime('first day of next month'));
        $seasonYear = (int) wp_date('Y') + (wp_date('m-d') > '10-31' ? 1 : 0);
      ?>
      <div class="fvr-panel">
        <h2>Période de réservation en ligne</h2>
        <p>Les clients peuvent réserver jusqu'au <strong><?php echo esc_html(fvr_format_date($last)); ?></strong>.
          Au-delà, aucun créneau n'est proposé sur le site (vous pouvez toujours ajouter des réservations vous-même).</p>
        <?php echo fvr_form_open('save_period', 'class="fvr-grid"'); ?>
          <label>Réservations ouvertes jusqu'au (facultatif)
            <input type="date" name="booking_until" id="fvr-until" value="<?php echo esc_attr($until); ?>"></label>
          <label>Et au maximum (jours à l'avance)
            <input type="number" min="1" name="max_days_ahead" value="<?php echo (int) $s['max_days_ahead']; ?>"></label>
          <div class="fvr-quick">
            <button type="button" class="button" data-until="<?php echo esc_attr($endOfMonth); ?>">Fin de ce mois</button>
            <button type="button" class="button" data-until="<?php echo esc_attr($endOfNext); ?>">Fin du mois prochain</button>
            <button type="button" class="button" data-until="<?php echo esc_attr($seasonYear . '-10-31'); ?>">Fin de saison (31 oct.)</button>
            <button type="button" class="button-link" data-until="">Aucune date limite</button>
          </div>
          <div><button class="button button-primary">Enregistrer la période</button></div>
        </form>
        <script>
          document.querySelectorAll('[data-until]').forEach(function (b) {
            b.addEventListener('click', function () { document.getElementById('fvr-until').value = b.getAttribute('data-until'); });
          });
        </script>
      </div>

      <div class="fvr-panel">
        <h2>Types de vol</h2>
        <table class="widefat fvr-table">
          <thead><tr><th>Ordre</th><th>Nom</th><th>Description</th><th>Durée</th><th>Prix CHF</th><th>Actif</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_merge($flights, [$newFlight]) as $fl): $fid = 'fvr-flight-' . (int) $fl['id']; ?>
            <tr>
              <td class="fvr-w-s"><input form="<?php echo $fid; ?>" type="number" name="sort_order" value="<?php echo (int) $fl['sort_order']; ?>"></td>
              <td><input form="<?php echo $fid; ?>" type="text" name="name" value="<?php echo esc_attr($fl['name']); ?>" placeholder="<?php echo $fl['id'] ? '' : 'Nouveau vol…'; ?>"></td>
              <td><input form="<?php echo $fid; ?>" type="text" name="description" value="<?php echo esc_attr($fl['description']); ?>"></td>
              <td class="fvr-w-m"><input form="<?php echo $fid; ?>" type="text" name="duration" value="<?php echo esc_attr($fl['duration']); ?>"></td>
              <td class="fvr-w-m"><input form="<?php echo $fid; ?>" type="number" step="0.01" name="price" value="<?php echo esc_attr($fl['price']); ?>"></td>
              <td><input form="<?php echo $fid; ?>" type="checkbox" name="active" value="1"<?php checked($fl['active'], 1); ?>></td>
              <td class="fvr-nowrap">
                <?php echo fvr_form_open('save_flight', 'id="' . $fid . '" class="fvr-inline"'); ?>
                  <input type="hidden" name="id" value="<?php echo (int) $fl['id']; ?>">
                  <button class="button button-small"><?php echo $fl['id'] ? 'Enregistrer' : 'Ajouter'; ?></button>
                </form>
                <?php if ($fl['id']): ?>
                  <?php echo fvr_form_open('delete_flight', 'class="fvr-inline" onsubmit="return confirm(\'Supprimer ce vol ?\')"'); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $fl['id']; ?>">
                    <button class="button button-small button-link-delete" aria-label="Supprimer">×</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="fvr-panel">
        <h2>Créneaux horaires</h2>
        <p class="description">La capacité correspond au nombre de passagers possibles par créneau (≈ nombre de pilotes disponibles).</p>
        <table class="widefat fvr-table fvr-table-narrow">
          <thead><tr><th>Heure</th><th>Capacité</th><th>Disponible jusqu'au <small>(facultatif)</small></th><th>Actif</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_merge($slots, [$newSlot]) as $sl): $sid = 'fvr-slot-' . (int) $sl['id']; ?>
            <tr>
              <td><input form="<?php echo $sid; ?>" type="time" name="time" step="900" value="<?php echo esc_attr($sl['time']); ?>" required></td>
              <td><input form="<?php echo $sid; ?>" type="number" min="0" name="capacity" value="<?php echo (int) $sl['capacity']; ?>"></td>
              <td><input form="<?php echo $sid; ?>" type="date" name="valid_until" value="<?php echo esc_attr($sl['valid_until'] ?? ''); ?>"></td>
              <td><input form="<?php echo $sid; ?>" type="checkbox" name="active" value="1"<?php checked($sl['active'], 1); ?>></td>
              <td class="fvr-nowrap">
                <?php echo fvr_form_open('save_slot', 'id="' . $sid . '" class="fvr-inline"'); ?>
                  <input type="hidden" name="id" value="<?php echo (int) $sl['id']; ?>">
                  <button class="button button-small"><?php echo $sl['id'] ? 'Enregistrer' : 'Ajouter'; ?></button>
                </form>
                <?php if ($sl['id']): ?>
                  <?php echo fvr_form_open('delete_slot', 'class="fvr-inline" onsubmit="return confirm(\'Supprimer ce créneau ?\')"'); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $sl['id']; ?>">
                    <button class="button button-small button-link-delete" aria-label="Supprimer">×</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="fvr-panel">
        <h2>Jours fermés (météo, vacances…)</h2>
        <?php echo fvr_form_open('block_date', 'class="fvr-grid"'); ?>
          <label>Du <input type="date" name="from" required></label>
          <label>Au (facultatif) <input type="date" name="to"></label>
          <label>Raison <input type="text" name="reason" placeholder="Météo, fermeture…"></label>
          <div><button class="button">Fermer ces jours</button></div>
        </form>
        <?php if ($blocked): ?>
          <table class="widefat striped fvr-table fvr-table-narrow" style="margin-top:1em">
            <?php foreach ($blocked as $bd): ?>
              <tr>
                <td><?php echo esc_html(ucfirst(fvr_format_date($bd['date']))); ?></td>
                <td><?php echo esc_html($bd['reason']); ?></td>
                <td>
                  <?php echo fvr_form_open('unblock_date', 'class="fvr-inline"'); ?>
                    <input type="hidden" name="date" value="<?php echo esc_attr($bd['date']); ?>">
                    <button class="button button-small">Réouvrir</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>

      <div class="fvr-panel">
        <h2>Réservation et e-mails</h2>
        <?php echo fvr_form_open('save_settings'); ?>
          <label class="fvr-check fvr-big"><input type="checkbox" name="auto_confirm" value="1"<?php checked($s['auto_confirm'], 1); ?>>
            <span><strong>Confirmation immédiate</strong> : le client réserve et son vol est confirmé tout de suite
            (sinon la réservation arrive « en attente » et vous la confirmez vous-même).</span></label>

          <h3>Expéditeur</h3>
          <div class="fvr-grid">
            <label>Nom de l'expéditeur <input type="text" name="from_name" value="<?php echo esc_attr($s['from_name']); ?>"></label>
            <label>Adresse d'expédition <input type="email" name="from_email" value="<?php echo esc_attr($s['from_email']); ?>"></label>
            <label>Vos e-mails de réservation arrivent à <input type="email" name="admin_email" value="<?php echo esc_attr($s['admin_email']); ?>"></label>
            <label>Quand le client répond à la confirmation, la réponse arrive à
              <input type="email" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>" placeholder="<?php echo esc_attr($s['admin_email']); ?>"></label>
            <label>Préfixe des références <input type="text" name="ref_prefix" maxlength="6" value="<?php echo esc_attr($s['ref_prefix']); ?>"></label>
          </div>
          <p class="description">Utilisez une adresse de votre domaine (ex. reservation@<?php echo esc_html(fvr_site_domain()); ?>) :
            une adresse Gmail ou autre en expéditeur finit souvent dans les spams.</p>

          <h3>E-mail de confirmation au client</h3>
          <label class="fvr-check"><input type="checkbox" name="send_client_email" value="1"<?php checked($s['send_client_email'], 1); ?>>
            Envoyer l'e-mail de confirmation au client</label>
          <label>Objet <input type="text" name="client_subject" class="large-text" value="<?php echo esc_attr($s['client_subject']); ?>"></label>
          <label>Message d'accueil <textarea name="client_message" rows="3" class="large-text"><?php echo esc_textarea($s['client_message']); ?></textarea></label>
          <p class="description">Le récapitulatif (référence, vol, date, heure, passagers, total) est ajouté automatiquement sous ce message.</p>
          <label>Informations pratiques (lieu de rendez-vous, quoi apporter, accès…)
            <textarea name="client_info" rows="4" class="large-text" placeholder="Rendez-vous au départ de la télécabine de Médran, 20 minutes avant l'heure du vol. Prévoir de bonnes chaussures et une veste chaude."><?php echo esc_textarea($s['client_info']); ?></textarea></label>
          <label>Signature / fin du message <textarea name="client_signature" rows="4" class="large-text"><?php echo esc_textarea($s['client_signature']); ?></textarea></label>

          <h3>Conditions générales</h3>
          <p class="description">Si ce texte est rempli, le client doit cocher « J'accepte les conditions générales » pour réserver
            (il peut les lire dans une fenêtre). Pour les afficher aussi sur une page du site : <code>[conditions_parapente]</code></p>
          <?php wp_editor($s['terms'], 'fvr_terms', ['textarea_name' => 'terms', 'textarea_rows' => 12, 'media_buttons' => false,
              'teeny' => true, 'quicktags' => false]); ?>
          <label class="fvr-check"><input type="checkbox" name="terms_in_email" value="1"<?php checked($s['terms_in_email'], 1); ?>>
            Joindre les conditions générales en bas de l'e-mail de confirmation</label>

          <h3>Message affiché à l'écran après la réservation</h3>
          <label><textarea name="screen_message" rows="2" class="large-text"><?php echo esc_textarea($s['screen_message']); ?></textarea></label>

          <p class="fvr-tags"><strong>Mots-clés disponibles</strong> (remplacés automatiquement) :
            <?php foreach (array_keys(fvr_placeholders(fvr_sample_booking())) as $tag): ?><code><?php echo esc_html($tag); ?></code> <?php endforeach; ?></p>
          <p><button class="button button-primary">Enregistrer</button></p>
        </form>
        <?php echo fvr_form_open('test_email', 'class="fvr-inline"'); ?>
          <p><button class="button">Envoyer un e-mail de test à <?php echo esc_html($s['admin_email']); ?></button>
            <span class="description">Vous recevez l'e-mail client et l'e-mail administrateur d'une réservation fictive.</span></p>
        </form>
      </div>
          <label class="fvr-check"><input type="checkbox" name="send_client_email" value="1"<?php checked($s['send_client_email'], 1); ?>>
            Envoyer un e-mail récapitulatif au client</label>
          <label>Texte de l'e-mail au client
            <textarea name="client_message" rows="3"><?php echo esc_textarea($s['client_message']); ?></textarea></label>
          <p><button class="button button-primary">Enregistrer les réglages</button></p>
        </form>
      </div>
    </div>
    <?php
}

// ---------- Page : pilotes ----------

function fvr_page_pilots(): void
{
    $pilots = fvr_pilots();
    $colors = ['#0b57d0', '#0b8043', '#e37400', '#8e24aa', '#d81b60', '#039be5', '#795548', '#3f51b5'];
    $new = ['id' => 0, 'name' => '', 'phone' => '', 'email' => '', 'color' => $colors[count($pilots) % count($colors)],
            'default_rank' => 0, 'active' => 1, 'token' => ''];
    $maxRank = max(5, count($pilots) + 1);
    ?>
    <div class="wrap fvr-admin">
      <h1>Pilotes</h1>
      <?php fvr_show_flash(); ?>
      <div class="fvr-panel">
        <p><strong>Ordre par défaut :</strong> lors d'une réservation, chaque passager reçoit automatiquement un pilote,
          dans cet ordre (n°1, puis n°2…), en sautant ceux qui volent déjà à la même heure.
          Les pilotes sans ordre ne sont jamais attribués automatiquement : les places restantes sont « à définir »
          et vous les choisissez dans la réservation (ou lors de la confirmation).</p>
        <p><strong>Lien personnel :</strong> chaque pilote a son propre lien vers le planning (ses vols en priorité, lecture seule)
          et son abonnement agenda qui ne contient que ses vols.</p>
      </div>

      <?php foreach (array_merge($pilots, [$new]) as $pl): $fid = 'fvr-pilot-' . (int) $pl['id']; ?>
        <div class="fvr-panel fvr-pilot-card<?php echo $pl['active'] ? '' : ' fvr-inactive'; ?>" style="--c:<?php echo esc_attr($pl['color']); ?>">
          <h2><?php echo $pl['id'] ? esc_html($pl['name']) : 'Ajouter un pilote'; ?>
            <?php if ($pl['id'] && $pl['default_rank']): ?><span class="fvr-rank">Pilote n°<?php echo (int) $pl['default_rank']; ?> par défaut</span><?php endif; ?>
            <?php if (!$pl['active']): ?><span class="fvr-rank">inactif</span><?php endif; ?></h2>
          <?php echo fvr_form_open('save_pilot', 'id="' . $fid . '"'); ?>
            <input type="hidden" name="id" value="<?php echo (int) $pl['id']; ?>">
            <div class="fvr-grid">
              <label>Nom <input type="text" name="name" value="<?php echo esc_attr($pl['name']); ?>" required placeholder="ex. Tristan"></label>
              <label>Téléphone <input type="tel" name="phone" value="<?php echo esc_attr($pl['phone']); ?>"></label>
              <label>E-mail <input type="email" name="email" value="<?php echo esc_attr($pl['email']); ?>"></label>
              <label>Ordre par défaut
                <select name="default_rank">
                  <option value="0">— Aucun (choisi manuellement)</option>
                  <?php for ($r = 1; $r <= $maxRank; $r++): ?>
                    <option value="<?php echo $r; ?>"<?php selected($r, (int) $pl['default_rank']); ?>>Pilote n°<?php echo $r; ?></option>
                  <?php endfor; ?>
                </select>
              </label>
              <label>Couleur <input type="color" name="color" value="<?php echo esc_attr($pl['color']); ?>"></label>
            </div>
            <label class="fvr-check"><input type="checkbox" name="active" value="1"<?php checked($pl['active'], 1); ?>> Actif (peut être attribué, lien fonctionnel)</label>
            <p><button class="button button-primary"><?php echo $pl['id'] ? 'Enregistrer' : 'Ajouter le pilote'; ?></button></p>
          </form>
          <?php if ($pl['id']): ?>
            <p class="description">Lien personnel à envoyer à <?php echo esc_html($pl['name']); ?> :</p>
            <p class="fvr-linkrow"><input type="text" class="large-text code" readonly value="<?php echo esc_attr(fvr_pilot_planning_url($pl)); ?>" onclick="this.select()">
              <a class="button" href="<?php echo esc_url(fvr_pilot_planning_url($pl)); ?>" target="_blank">Ouvrir</a>
              <?php if ($pl['phone']): ?>
                <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url('https://wa.me/' . preg_replace('/\D/', '', preg_replace('/^0(?!0)/', '41', preg_replace('/^00/', '', preg_replace('/[^0-9]/', '', $pl['phone'])))) . '?text=' . rawurlencode('Voici ton planning des vols : ' . fvr_pilot_planning_url($pl))); ?>">Envoyer par WhatsApp</a>
              <?php endif; ?>
            </p>
            <?php $devices = fvr_push_count((int) $pl['id']); ?>
            <p class="fvr-push-state">
              <?php if ($devices): ?>
                🔔 Notifications activées sur <?php echo $devices; ?> appareil<?php echo $devices > 1 ? 's' : ''; ?>
                <?php echo fvr_form_open('push_test_pilot', 'class="fvr-inline"'); ?>
                  <input type="hidden" name="id" value="<?php echo (int) $pl['id']; ?>">
                  <button class="button button-small">Envoyer une notification test</button>
                </form>
              <?php else: ?>
                🔕 Notifications pas encore activées — le pilote les active depuis son planning (bouton 🔔).
              <?php endif; ?>
            </p>
            <p class="description">Abonnement agenda (uniquement ses vols) :</p>
            <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr(fvr_pilot_ics_url($pl)); ?>" onclick="this.select()"></p>
            <p>
              <?php echo fvr_form_open('regen_pilot_token', 'class="fvr-inline" onsubmit="return confirm(\'L\\\'ancien lien de ce pilote ne fonctionnera plus. Continuer ?\')"'); ?>
                <input type="hidden" name="id" value="<?php echo (int) $pl['id']; ?>">
                <button class="button">Générer un nouveau lien</button>
              </form>
              <?php echo fvr_form_open('delete_pilot', 'class="fvr-inline" onsubmit="return confirm(\'Supprimer ce pilote ? Ses vols repasseront « à définir ».\')"'); ?>
                <input type="hidden" name="id" value="<?php echo (int) $pl['id']; ?>">
                <button class="button-link button-link-delete">Supprimer le pilote</button>
              </form>
            </p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}
