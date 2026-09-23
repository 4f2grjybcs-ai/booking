<?php
// Back office dans l'administration WordPress : menu « Réservations ».

if (!defined('ABSPATH')) {
    exit;
}

function fvr_cap(): string
{
    // Rôle requis pour gérer les réservations (administrateur par défaut)
    return apply_filters('fvr_capability', 'manage_options');
}

add_action('admin_menu', function () {
    global $wpdb;
    $pending = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . fvr_table('bookings')
        . " WHERE status = 'pending' AND date >= '" . esc_sql(fvr_today()) . "'");
    $bubble = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';

    add_menu_page('Réservations', 'Réservations' . $bubble, fvr_cap(), 'fvr', 'fvr_page_bookings', 'dashicons-calendar-alt', 26);
    add_submenu_page('fvr', 'Réservations', 'Toutes les réservations', fvr_cap(), 'fvr', 'fvr_page_bookings');
    add_submenu_page('fvr', 'Nouvelle réservation', 'Ajouter', fvr_cap(), 'fvr-edit', 'fvr_page_edit');
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
            $wpdb->update(fvr_table('bookings'), ['status' => $p['status'], 'updated_at' => fvr_now()], ['id' => (int) $p['id']]);
            fvr_back($back, 'Statut mis à jour.');

        case 'save_booking':
            $id = (int) ($p['id'] ?? 0);
            $editUrl = admin_url('admin.php?page=fvr-edit' . ($id ? '&id=' . $id : ''));
            if (!fvr_valid_date($p['date'] ?? '') || !preg_match('/^\d{2}:\d{2}$/', $p['time'] ?? '')
                || trim($p['name'] ?? '') === '' || (int) ($p['passengers'] ?? 0) < 1
                || !isset(fvr_status_labels()[$p['status'] ?? ''])) {
                fvr_back($editUrl, 'Veuillez remplir date, heure, nom et passagers.', true);
            }
            $flightId = (int) ($p['flight_id'] ?? 0);
            $flightName = $flightId ? $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . fvr_table('flights') . ' WHERE id = %d', $flightId)) : null;
            $data = [
                'date' => $p['date'], 'time' => $p['time'],
                'flight_id' => $flightName ? $flightId : null,
                'flight_name' => $flightName ?: sanitize_text_field($p['flight_name'] ?? 'Vol'),
                'price' => (float) $p['price'], 'passengers' => (int) $p['passengers'],
                'name' => sanitize_text_field($p['name']), 'email' => sanitize_email($p['email'] ?? ''),
                'phone' => sanitize_text_field($p['phone'] ?? ''), 'weights' => sanitize_text_field($p['weights'] ?? ''),
                'message' => sanitize_textarea_field($p['message'] ?? ''), 'status' => $p['status'],
                'admin_notes' => sanitize_textarea_field($p['admin_notes'] ?? ''), 'updated_at' => fvr_now(),
            ];
            if ($id) {
                $wpdb->update(fvr_table('bookings'), $data, ['id' => $id]);
                fvr_back($editUrl, 'Réservation enregistrée.');
            }
            $data['reference'] = fvr_generate_reference();
            $data['created_at'] = fvr_now();
            $wpdb->insert(fvr_table('bookings'), $data);
            fvr_back(admin_url('admin.php?page=fvr-edit&id=' . $wpdb->insert_id), 'Réservation créée.');

        case 'delete_booking':
            $wpdb->delete(fvr_table('bookings'), ['id' => (int) $p['id']]);
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
            $data = ['time' => $time, 'capacity' => max(0, (int) ($p['capacity'] ?? 0)), 'active' => empty($p['active']) ? 0 : 1];
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

        case 'save_settings':
            update_option('fvr_settings', [
                'admin_email'       => sanitize_email($p['admin_email'] ?? ''),
                'send_client_email' => empty($p['send_client_email']) ? 0 : 1,
                'ref_prefix'        => sanitize_text_field($p['ref_prefix'] ?? 'FV'),
                'max_days_ahead'    => max(1, (int) ($p['max_days_ahead'] ?? 365)),
                'client_message'    => sanitize_textarea_field($p['client_message'] ?? ''),
            ]);
            fvr_back($settingsUrl, 'Réglages enregistrés.');
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
                   'Poids', 'Message', 'Statut', 'Notes internes', 'Créée le'], ';', '"', '\\');
    foreach (fvr_query_bookings(fvr_filter_args()) as $b) {
        fputcsv($out, [$b['reference'], $b['date'], $b['time'], $b['flight_name'], $b['passengers'], $b['price'],
                       $b['name'], $b['email'], $b['phone'], $b['weights'], $b['message'],
                       $labels[$b['status']] ?? $b['status'], $b['admin_notes'], $b['created_at']], ';', '"', '\\');
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
        <thead><tr><th>Heure</th><th>Réf.</th><th>Client</th><th>Contact</th><th>Vol</th><th class="num">Pax</th><th>Poids</th><th class="num">Prix</th><th>Statut</th></tr></thead>
        <tbody>
        <?php $lastDate = null; foreach ($bookings as $b): $edit = admin_url('admin.php?page=fvr-edit&id=' . (int) $b['id']); ?>
          <?php if ($b['date'] !== $lastDate): $lastDate = $b['date']; ?>
            <tr class="fvr-day"><td colspan="9"><?php echo esc_html(ucfirst(fvr_format_date($b['date']))); ?></td></tr>
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
    $slots = $wpdb->get_col('SELECT time FROM ' . fvr_table('slots') . ' ORDER BY time');
    if ($b['time'] && !in_array($b['time'], $slots, true)) {
        $slots[] = $b['time'];
        sort($slots);
    }
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
              <?php foreach ($slots as $t): ?><option<?php selected($t, $b['time']); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
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
        <label>Message du client <textarea name="message" rows="3"><?php echo esc_textarea($b['message']); ?></textarea></label>
        <label>Notes internes (pilote, paiement…) <textarea name="admin_notes" rows="3"><?php echo esc_textarea($b['admin_notes']); ?></textarea></label>
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
          <thead><tr><th>Heure</th><th>Capacité</th><th>Actif</th><th></th></tr></thead>
          <tbody>
          <?php foreach (array_merge($slots, [$newSlot]) as $sl): $sid = 'fvr-slot-' . (int) $sl['id']; ?>
            <tr>
              <td><input form="<?php echo $sid; ?>" type="time" name="time" value="<?php echo esc_attr($sl['time']); ?>" required></td>
              <td><input form="<?php echo $sid; ?>" type="number" min="0" name="capacity" value="<?php echo (int) $sl['capacity']; ?>"></td>
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
        <h2>E-mails et réglages</h2>
        <?php echo fvr_form_open('save_settings'); ?>
          <div class="fvr-grid">
            <label>E-mail qui reçoit les nouvelles réservations
              <input type="email" name="admin_email" value="<?php echo esc_attr($s['admin_email']); ?>"></label>
            <label>Préfixe des références
              <input type="text" name="ref_prefix" maxlength="6" value="<?php echo esc_attr($s['ref_prefix']); ?>"></label>
            <label>Réservation possible jusqu'à (jours à l'avance)
              <input type="number" min="1" name="max_days_ahead" value="<?php echo (int) $s['max_days_ahead']; ?>"></label>
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
