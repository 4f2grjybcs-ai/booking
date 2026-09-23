<?php
// Back office : gestion des réservations, des vols, des horaires et des jours fermés.

require __DIR__ . '/../lib/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('fv_admin');
session_start();
header('X-Frame-Options: DENY');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$page = $_GET['page'] ?? 'bookings';

function redirect(string $url, string $flash = '', bool $error = false): void
{
    if ($flash) {
        $_SESSION['flash'] = [$flash, $error];
    }
    header('Location: ' . $url);
    exit;
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

// ---------- Connexion / création du mot de passe ----------

$passwordHash = setting('admin_password');

if ($page === 'logout') {
    session_destroy();
    redirect('./');
}

if (empty($_SESSION['admin'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $pw = $_POST['password'] ?? '';
        if (!$passwordHash) {
            if (strlen($pw) < 8) {
                $err = 'Le mot de passe doit contenir au moins 8 caractères.';
            } elseif ($pw !== ($_POST['password2'] ?? '')) {
                $err = 'Les deux mots de passe ne correspondent pas.';
            } else {
                set_setting('admin_password', password_hash($pw, PASSWORD_DEFAULT));
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                redirect('./');
            }
        } elseif (password_verify($pw, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            redirect('./');
        } else {
            sleep(1);
            $err = 'Mot de passe incorrect.';
        }
    }
    admin_header('Connexion', false);
    ?>
    <div class="login">
      <h1><?= h(config('site_name')) ?> · Back office</h1>
      <form method="post" class="panel">
        <?= csrf_field() ?>
        <?php if (!$passwordHash): ?>
          <p>Première utilisation : choisissez le mot de passe administrateur.</p>
        <?php endif ?>
        <?php if ($err): ?><p class="error"><?= h($err) ?></p><?php endif ?>
        <label>Mot de passe <input type="password" name="password" required autofocus></label>
        <?php if (!$passwordHash): ?>
          <label>Confirmer le mot de passe <input type="password" name="password2" required></label>
        <?php endif ?>
        <button class="btn-primary"><?= $passwordHash ? 'Se connecter' : 'Créer le mot de passe' ?></button>
      </form>
    </div>
    <?php
    admin_footer();
    exit;
}

// ---------- Actions (POST) ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $back = $_POST['back'] ?? './';
    if (!preg_match('#^\./(\?[\w=&%.\-+]*)?$#', $back)) {
        $back = './';
    }
    $pdo = db();

    switch ($a) {
        case 'status':
            if (!array_key_exists($_POST['status'] ?? '', status_labels())) {
                redirect($back, 'Statut invalide.', true);
            }
            $pdo->prepare('UPDATE bookings SET status = ?, updated_at = ? WHERE id = ?')
                ->execute([$_POST['status'], now(), (int) $_POST['id']]);
            redirect($back, 'Statut mis à jour.');

        case 'save_booking':
            $id = (int) ($_POST['id'] ?? 0);
            $f = array_map('trim', $_POST);
            if (!valid_date($f['date'] ?? '') || !preg_match('/^\d{2}:\d{2}$/', $f['time'] ?? '')
                || ($f['name'] ?? '') === '' || (int) ($f['passengers'] ?? 0) < 1
                || !array_key_exists($f['status'] ?? '', status_labels())) {
                redirect('./?page=edit&id=' . $id, 'Veuillez remplir date, heure, nom et passagers.', true);
            }
            $st = $pdo->prepare('SELECT name FROM flights WHERE id = ?');
            $st->execute([(int) $f['flight_id']]);
            $flightName = $st->fetchColumn() ?: ($f['flight_name'] ?? 'Vol');
            $vals = [$f['date'], $f['time'], (int) $f['flight_id'] ?: null, $flightName, (float) $f['price'],
                     (int) $f['passengers'], $f['name'], $f['email'] ?? '', $f['phone'] ?? '',
                     $f['weights'] ?? '', $f['message'] ?? '', $f['status'], $f['admin_notes'] ?? '', now()];
            if ($id) {
                $pdo->prepare('UPDATE bookings SET date=?, time=?, flight_id=?, flight_name=?, price=?, passengers=?,
                               name=?, email=?, phone=?, weights=?, message=?, status=?, admin_notes=?, updated_at=?
                               WHERE id = ?')->execute(array_merge($vals, [$id]));
                redirect('./?page=edit&id=' . $id, 'Réservation enregistrée.');
            }
            $pdo->prepare('INSERT INTO bookings (date, time, flight_id, flight_name, price, passengers, name, email,
                           phone, weights, message, status, admin_notes, updated_at, reference, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge($vals, [generate_reference(), now()]));
            redirect('./?page=edit&id=' . $pdo->lastInsertId(), 'Réservation créée.');

        case 'delete_booking':
            $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([(int) $_POST['id']]);
            redirect('./', 'Réservation supprimée.');

        case 'save_flight':
            $id = (int) ($_POST['id'] ?? 0);
            $vals = [trim($_POST['name']), trim($_POST['description'] ?? ''), trim($_POST['duration'] ?? ''),
                     (float) $_POST['price'], isset($_POST['active']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
            if ($vals[0] === '') {
                redirect('./?page=settings', 'Le nom du vol est requis.', true);
            }
            if ($id) {
                $pdo->prepare('UPDATE flights SET name=?, description=?, duration=?, price=?, active=?, sort_order=? WHERE id=?')
                    ->execute(array_merge($vals, [$id]));
            } else {
                $pdo->prepare('INSERT INTO flights (name, description, duration, price, active, sort_order) VALUES (?,?,?,?,?,?)')
                    ->execute($vals);
            }
            redirect('./?page=settings', 'Vol enregistré.');

        case 'delete_flight':
            $pdo->prepare('UPDATE bookings SET flight_id = NULL WHERE flight_id = ?')->execute([(int) $_POST['id']]);
            $pdo->prepare('DELETE FROM flights WHERE id = ?')->execute([(int) $_POST['id']]);
            redirect('./?page=settings', 'Vol supprimé.');

        case 'save_slot':
            $id = (int) ($_POST['id'] ?? 0);
            $time = $_POST['time'] ?? '';
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
                redirect('./?page=settings', 'Heure invalide.', true);
            }
            $vals = [$time, max(0, (int) $_POST['capacity']), isset($_POST['active']) ? 1 : 0];
            try {
                if ($id) {
                    $pdo->prepare('UPDATE slots SET time=?, capacity=?, active=? WHERE id=?')->execute(array_merge($vals, [$id]));
                } else {
                    $pdo->prepare('INSERT INTO slots (time, capacity, active) VALUES (?,?,?)')->execute($vals);
                }
            } catch (PDOException $e) {
                redirect('./?page=settings', 'Ce créneau existe déjà.', true);
            }
            redirect('./?page=settings', 'Créneau enregistré.');

        case 'delete_slot':
            $pdo->prepare('DELETE FROM slots WHERE id = ?')->execute([(int) $_POST['id']]);
            redirect('./?page=settings', 'Créneau supprimé.');

        case 'block_date':
            $from = $_POST['from'] ?? '';
            $to = ($_POST['to'] ?? '') ?: $from;
            if (!valid_date($from) || !valid_date($to) || $to < $from) {
                redirect('./?page=settings', 'Dates invalides.', true);
            }
            $st = $pdo->prepare('INSERT OR REPLACE INTO blocked_dates (date, reason) VALUES (?, ?)');
            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                $st->execute([$d, trim($_POST['reason'] ?? '')]);
            }
            redirect('./?page=settings', 'Jour(s) fermé(s) enregistré(s).');

        case 'unblock_date':
            $pdo->prepare('DELETE FROM blocked_dates WHERE date = ?')->execute([$_POST['date'] ?? '']);
            redirect('./?page=settings', 'Jour réouvert.');

        case 'change_password':
            if (!password_verify($_POST['current'] ?? '', setting('admin_password'))) {
                redirect('./?page=settings', 'Mot de passe actuel incorrect.', true);
            }
            if (strlen($_POST['new'] ?? '') < 8) {
                redirect('./?page=settings', 'Le nouveau mot de passe doit contenir au moins 8 caractères.', true);
            }
            set_setting('admin_password', password_hash($_POST['new'], PASSWORD_DEFAULT));
            redirect('./?page=settings', 'Mot de passe modifié.');
    }
    redirect('./');
}

// ---------- Filtres communs ----------

function booking_filters(): array
{
    $where = [];
    $args = [];
    $from = $_GET['from'] ?? date('Y-m-d');
    $to = $_GET['to'] ?? '';
    if (valid_date($from)) { $where[] = 'date >= ?'; $args[] = $from; }
    if (valid_date($to)) { $where[] = 'date <= ?'; $args[] = $to; }
    if (!empty($_GET['status']) && array_key_exists($_GET['status'], status_labels())) {
        $where[] = 'status = ?';
        $args[] = $_GET['status'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR reference LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        array_push($args, $q, $q, $q, $q);
    }
    $sql = 'SELECT * FROM bookings' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY date, time, id';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

// ---------- Export CSV (ouvrable dans Excel) ----------

if ($page === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Référence', 'Date', 'Heure', 'Vol', 'Passagers', 'Prix CHF', 'Nom', 'E-mail', 'Téléphone',
                   'Poids', 'Message', 'Statut', 'Notes internes', 'Créée le'], ';', '"', '\\');
    $labels = status_labels();
    foreach (booking_filters() as $b) {
        fputcsv($out, [$b['reference'], $b['date'], $b['time'], $b['flight_name'], $b['passengers'], $b['price'],
                       $b['name'], $b['email'], $b['phone'], $b['weights'], $b['message'],
                       $labels[$b['status']] ?? $b['status'], $b['admin_notes'], $b['created_at']], ';', '"', '\\');
    }
    exit;
}

// ---------- Mise en page ----------

function admin_header(string $title, bool $nav = true): void
{
    global $page;
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · Back office</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin">
<?php if ($nav): ?>
  <nav class="admin-nav">
    <strong><?= h(config('site_name')) ?> · Back office</strong>
    <a href="./" class="<?= $page === 'bookings' ? 'active' : '' ?>">Réservations</a>
    <a href="./?page=edit" class="<?= $page === 'edit' ? 'active' : '' ?>">+ Nouvelle</a>
    <a href="./?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Paramètres</a>
    <a href="../" target="_blank">Page client ↗</a>
    <a href="./?page=logout">Déconnexion</a>
  </nav>
  <?php if (!empty($_SESSION['flash'])): [$msg, $isErr] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash<?= $isErr ? ' err' : '' ?>"><?= h($msg) ?></div>
  <?php endif ?>
<?php endif;
}

function admin_footer(): void
{
    echo "</div>\n</body>\n</html>\n";
}

function status_select(string $current, string $name = 'status', string $extra = ''): string
{
    $html = '<select name="' . $name . '" ' . $extra . '>';
    foreach (status_labels() as $k => $label) {
        $html .= '<option value="' . $k . '"' . ($k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

// ---------- Page : édition / création d'une réservation ----------

if ($page === 'edit') {
    $id = (int) ($_GET['id'] ?? 0);
    $b = ['id' => 0, 'reference' => '', 'date' => date('Y-m-d'), 'time' => '', 'flight_id' => 0, 'flight_name' => '',
          'price' => 0, 'passengers' => 1, 'name' => '', 'email' => '', 'phone' => '', 'weights' => '',
          'message' => '', 'status' => 'confirmed', 'admin_notes' => '', 'created_at' => ''];
    if ($id) {
        $st = db()->prepare('SELECT * FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $b = $st->fetch() ?: redirect('./', 'Réservation introuvable.', true);
    }
    $flights = db()->query('SELECT * FROM flights ORDER BY sort_order, id')->fetchAll();
    $slots = db()->query('SELECT time FROM slots ORDER BY time')->fetchAll(PDO::FETCH_COLUMN);
    if ($b['time'] && !in_array($b['time'], $slots, true)) {
        $slots[] = $b['time'];
        sort($slots);
    }
    admin_header($id ? 'Réservation ' . $b['reference'] : 'Nouvelle réservation');
    ?>
    <h1><?= $id ? 'Réservation ' . h($b['reference']) : 'Nouvelle réservation (téléphone, sur place…)' ?></h1>
    <?php if ($id): ?><p class="muted">Reçue le <?= h($b['created_at']) ?></p><?php endif ?>
    <form method="post" class="panel">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_booking">
      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <input type="hidden" name="flight_name" value="<?= h($b['flight_name']) ?>">
      <div class="grid-form">
        <label>Date <input type="date" name="date" value="<?= h($b['date']) ?>" required></label>
        <label>Heure
          <select name="time" required>
            <?php foreach ($slots as $t): ?>
              <option<?= $t === $b['time'] ? ' selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label>Vol
          <select name="flight_id" id="flight_id">
            <?php if ($id && !$b['flight_id']): ?>
              <option value="0" data-price="<?= h($b['price'] / max(1, $b['passengers'])) ?>" selected><?= h($b['flight_name']) ?> (supprimé)</option>
            <?php endif ?>
            <?php foreach ($flights as $f): ?>
              <option value="<?= $f['id'] ?>" data-price="<?= h($f['price']) ?>"<?= (int) $f['id'] === (int) $b['flight_id'] ? ' selected' : '' ?>>
                <?= h($f['name']) ?> (CHF <?= h($f['price']) ?>)
              </option>
            <?php endforeach ?>
          </select>
        </label>
        <label>Passagers <input type="number" name="passengers" id="passengers" min="1" value="<?= (int) $b['passengers'] ?>"></label>
        <label>Prix total CHF <input type="number" step="0.01" name="price" id="price" value="<?= h($b['price']) ?>"></label>
        <label>Statut <?= status_select($b['status']) ?></label>
        <label>Nom <input type="text" name="name" value="<?= h($b['name']) ?>" required></label>
        <label>E-mail <input type="email" name="email" value="<?= h($b['email']) ?>"></label>
        <label>Téléphone <input type="tel" name="phone" value="<?= h($b['phone']) ?>"></label>
        <label>Poids <input type="text" name="weights" value="<?= h($b['weights']) ?>"></label>
      </div>
      <p><label>Message du client <textarea name="message" rows="3"><?= h($b['message']) ?></textarea></label></p>
      <p><label>Notes internes (pilote, paiement…) <textarea name="admin_notes" rows="3"><?= h($b['admin_notes']) ?></textarea></label></p>
      <button class="btn-secondary">Enregistrer</button>
      <?php if ($b['email']): ?>
        <a class="btn-small" href="mailto:<?= h($b['email']) ?>">Écrire au client</a>
      <?php endif ?>
      <?php if ($b['phone']): ?>
        <a class="btn-small" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $b['phone'])) ?>">Appeler</a>
      <?php endif ?>
    </form>
    <?php if ($id): ?>
      <form method="post" onsubmit="return confirm('Supprimer définitivement cette réservation ?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_booking">
        <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
        <button class="btn-small danger">Supprimer la réservation</button>
      </form>
    <?php endif ?>
    <script>
      // Recalcule le prix total quand on change le vol ou le nombre de passagers
      (function () {
        var f = document.getElementById('flight_id'), p = document.getElementById('passengers'), t = document.getElementById('price');
        function upd() { t.value = (parseFloat(f.selectedOptions[0].dataset.price) || 0) * (parseInt(p.value, 10) || 0); }
        f.addEventListener('change', upd); p.addEventListener('input', upd);
        <?php if (!$id): ?>upd();<?php endif ?>
      })();
    </script>
    <?php
    admin_footer();
    exit;
}

// ---------- Page : paramètres ----------

if ($page === 'settings') {
    $flights = db()->query('SELECT * FROM flights ORDER BY sort_order, id')->fetchAll();
    $slots = db()->query('SELECT * FROM slots ORDER BY time')->fetchAll();
    $blocked = db()->query("SELECT * FROM blocked_dates WHERE date >= date('now', '-30 days') ORDER BY date")->fetchAll();
    admin_header('Paramètres');
    ?>
    <h1>Paramètres</h1>

    <div class="panel">
      <h2>Types de vol</h2>
      <div class="table-wrap"><table>
        <tr><th>Ordre</th><th>Nom</th><th>Description</th><th>Durée</th><th>Prix CHF</th><th>Actif</th><th></th></tr>
        <?php foreach (array_merge($flights, [['id' => 0, 'name' => '', 'description' => '', 'duration' => '', 'price' => '', 'active' => 1, 'sort_order' => count($flights)]]) as $f): ?>
          <?php $fid = 'flight-' . (int) $f['id']; ?>
          <tr>
            <td style="width:70px"><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int) $f['sort_order'] ?>"></td>
            <td><input form="<?= $fid ?>" type="text" name="name" value="<?= h($f['name']) ?>" placeholder="<?= $f['id'] ? '' : 'Nouveau vol…' ?>"></td>
            <td><input form="<?= $fid ?>" type="text" name="description" value="<?= h($f['description']) ?>"></td>
            <td style="width:100px"><input form="<?= $fid ?>" type="text" name="duration" value="<?= h($f['duration']) ?>"></td>
            <td style="width:100px"><input form="<?= $fid ?>" type="number" step="0.01" name="price" value="<?= h($f['price']) ?>"></td>
            <td><input form="<?= $fid ?>" type="checkbox" name="active" <?= $f['active'] ? 'checked' : '' ?>></td>
            <td style="white-space:nowrap">
              <form method="post" id="<?= $fid ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_flight">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button class="btn-small"><?= $f['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
              </form>
              <?php if ($f['id']): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Supprimer ce vol ?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_flight">
                  <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                  <button class="btn-small danger">×</button>
                </form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </table></div>
    </div>

    <div class="panel">
      <h2>Créneaux horaires</h2>
      <p class="muted">La capacité correspond au nombre de passagers possibles par créneau (≈ nombre de pilotes disponibles).</p>
      <div class="table-wrap"><table>
        <tr><th>Heure</th><th>Capacité</th><th>Actif</th><th></th></tr>
        <?php foreach (array_merge($slots, [['id' => 0, 'time' => '', 'capacity' => 4, 'active' => 1]]) as $s): ?>
          <?php $sid = 'slot-' . (int) $s['id']; ?>
          <tr>
            <td><input form="<?= $sid ?>" type="time" name="time" value="<?= h($s['time']) ?>" required></td>
            <td><input form="<?= $sid ?>" type="number" min="0" name="capacity" value="<?= (int) $s['capacity'] ?>"></td>
            <td><input form="<?= $sid ?>" type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>></td>
            <td style="white-space:nowrap">
              <form method="post" id="<?= $sid ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_slot">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn-small"><?= $s['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
              </form>
              <?php if ($s['id']): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Supprimer ce créneau ?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_slot">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn-small danger">×</button>
                </form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </table></div>
    </div>

    <div class="panel">
      <h2>Jours fermés (météo, vacances…)</h2>
      <form method="post" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="block_date">
        <label>Du <input type="date" name="from" required></label>
        <label>Au (facultatif) <input type="date" name="to"></label>
        <label>Raison <input type="text" name="reason" placeholder="Météo, fermeture…"></label>
        <button class="btn-secondary">Fermer ces jours</button>
      </form>
      <?php if ($blocked): ?>
        <table style="margin-top:1rem">
          <?php foreach ($blocked as $bd): ?>
            <tr>
              <td><?= h(format_date_fr($bd['date'])) ?></td>
              <td><?= h($bd['reason']) ?></td>
              <td>
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unblock_date">
                  <input type="hidden" name="date" value="<?= h($bd['date']) ?>">
                  <button class="btn-small">Réouvrir</button>
                </form>
              </td>
            </tr>
          <?php endforeach ?>
        </table>
      <?php endif ?>
    </div>

    <div class="panel">
      <h2>Changer le mot de passe</h2>
      <form method="post" class="grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label>Mot de passe actuel <input type="password" name="current" required></label>
        <label>Nouveau mot de passe <input type="password" name="new" minlength="8" required></label>
        <button class="btn-secondary">Modifier</button>
      </form>
    </div>
    <?php
    admin_footer();
    exit;
}

// ---------- Page : liste des réservations ----------

$bookings = booking_filters();
$labels = status_labels();
$active = array_filter($bookings, fn($b) => $b['status'] !== 'cancelled');
$qs = $_SERVER['QUERY_STRING'] ?? '';
$back = './' . ($qs ? '?' . $qs : '');

admin_header('Réservations');
?>
<div class="stats">
  <div class="stat"><span class="muted">Réservations</span><b><?= count($active) ?></b></div>
  <div class="stat"><span class="muted">Passagers</span><b><?= array_sum(array_column($active, 'passengers')) ?></b></div>
  <div class="stat"><span class="muted">En attente</span><b><?= count(array_filter($bookings, fn($b) => $b['status'] === 'pending')) ?></b></div>
  <div class="stat"><span class="muted">Chiffre d'affaires</span><b>CHF <?= number_format(array_sum(array_column($active, 'price')), 0, '.', "'") ?></b></div>
</div>

<form class="panel filters" method="get">
  <label>Du <input type="date" name="from" value="<?= h($_GET['from'] ?? date('Y-m-d')) ?>"></label>
  <label>Au <input type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>"></label>
  <label>Statut
    <select name="status">
      <option value="">Tous</option>
      <?php foreach ($labels as $k => $l): ?>
        <option value="<?= $k ?>"<?= ($_GET['status'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Recherche <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Nom, e-mail, réf…"></label>
  <button class="btn-secondary">Filtrer</button>
  <a class="btn-small" href="./?from=">Tout l'historique</a>
  <a class="btn-small" href="./?page=export&amp;<?= h($qs) ?>">Exporter Excel (CSV)</a>
</form>

<div class="panel table-wrap">
  <?php if (!$bookings): ?>
    <p class="muted">Aucune réservation pour ces critères.</p>
  <?php else: ?>
  <table>
    <tr><th>Heure</th><th>Réf.</th><th>Client</th><th>Contact</th><th>Vol</th><th>Pax</th><th>Poids</th><th>Prix</th><th>Statut</th><th></th></tr>
    <?php $lastDate = null; foreach ($bookings as $b): ?>
      <?php if ($b['date'] !== $lastDate): $lastDate = $b['date']; ?>
        <tr class="day-head"><td colspan="10"><?= h(ucfirst(format_date_fr($b['date']))) ?></td></tr>
      <?php endif ?>
      <tr>
        <td><?= h($b['time']) ?></td>
        <td><a href="./?page=edit&amp;id=<?= (int) $b['id'] ?>"><?= h($b['reference']) ?></a></td>
        <td>
          <?= h($b['name']) ?>
          <?php if ($b['message']): ?><br><small class="muted" title="<?= h($b['message']) ?>">💬 <?= h(mb_strimwidth($b['message'], 0, 40, '…')) ?></small><?php endif ?>
          <?php if ($b['admin_notes']): ?><br><small class="muted" title="<?= h($b['admin_notes']) ?>">📝 <?= h(mb_strimwidth($b['admin_notes'], 0, 40, '…')) ?></small><?php endif ?>
        </td>
        <td>
          <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $b['phone'])) ?>"><?= h($b['phone']) ?></a><br>
          <a href="mailto:<?= h($b['email']) ?>"><?= h($b['email']) ?></a>
        </td>
        <td><?= h($b['flight_name']) ?></td>
        <td class="num"><?= (int) $b['passengers'] ?></td>
        <td><?= h($b['weights']) ?></td>
        <td class="num"><?= number_format($b['price'], 0, '.', "'") ?></td>
        <td>
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="back" value="<?= h($back) ?>">
            <?= status_select($b['status'], 'status', 'onchange="this.form.submit()" aria-label="Statut"') ?>
          </form>
        </td>
        <td><a class="btn-small" href="./?page=edit&amp;id=<?= (int) $b['id'] ?>">Modifier</a></td>
      </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</div>
<?php
admin_footer();
