<?php
// API publique utilisée par la page de réservation client.
//   GET  api.php?action=flights            -> liste des vols proposés
//   GET  api.php?action=availability&date= -> places restantes par créneau
//   POST api.php?action=book               -> enregistre une réservation

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'flights') {
        $flights = db()->query('SELECT id, name, description, duration, price FROM flights
                                WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
        respond(['flights' => $flights, 'site_name' => config('site_name')]);
    }

    if ($action === 'availability') {
        $date = $_GET['date'] ?? '';
        if (!valid_date($date)) {
            respond(['error' => 'Date invalide'], 400);
        }
        respond(['date' => $date, 'slots' => availability($date)]);
    }

    if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        // Champ piège anti-robots : doit rester vide
        if (!empty($in['website'])) {
            respond(['error' => 'Requête refusée'], 400);
        }

        $date   = trim($in['date'] ?? '');
        $time   = trim($in['time'] ?? '');
        $flight = (int) ($in['flight_id'] ?? 0);
        $pax    = (int) ($in['passengers'] ?? 0);
        $name   = trim($in['name'] ?? '');
        $email  = trim($in['email'] ?? '');
        $phone  = trim($in['phone'] ?? '');
        $weights = mb_substr(trim($in['weights'] ?? ''), 0, 200);
        $message = mb_substr(trim($in['message'] ?? ''), 0, 2000);

        $errors = [];
        if (!valid_date($date)) $errors[] = 'Date invalide.';
        if ($pax < 1 || $pax > 20) $errors[] = 'Nombre de passagers invalide.';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Nom requis.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail invalide.';
        if (!preg_match('/^[0-9 +().\-]{6,25}$/', $phone)) $errors[] = 'Téléphone invalide.';
        if (empty($in['accept'])) $errors[] = 'Veuillez accepter les conditions.';

        $st = db()->prepare('SELECT * FROM flights WHERE id = ? AND active = 1');
        $st->execute([$flight]);
        $flightRow = $st->fetch();
        if (!$flightRow) $errors[] = 'Type de vol invalide.';

        if ($errors) {
            respond(['error' => implode(' ', $errors)], 422);
        }

        // Transaction exclusive : évite la surréservation si deux clients réservent en même temps
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        $slot = null;
        foreach (availability($date) as $s) {
            if ($s['time'] === $time) {
                $slot = $s;
            }
        }
        if (!$slot) {
            $pdo->exec('ROLLBACK');
            respond(['error' => "Ce créneau n'est pas disponible."], 409);
        }
        if ($slot['remaining'] < $pax) {
            $pdo->exec('ROLLBACK');
            respond(['error' => "Il ne reste que {$slot['remaining']} place(s) sur ce créneau."], 409);
        }

        $ref = generate_reference();
        $total = $flightRow['price'] * $pax;
        $pdo->prepare('INSERT INTO bookings (reference, date, time, flight_id, flight_name, price, passengers,
                       name, email, phone, weights, message, status, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$ref, $date, $time, $flightRow['id'], $flightRow['name'], $total, $pax,
                       $name, $email, $phone, $weights, $message, 'pending', now(), now()]);
        $pdo->exec('COMMIT');

        $site = config('site_name');
        $summary = "Référence : $ref\n"
            . "Vol : {$flightRow['name']}\n"
            . 'Date : ' . format_date_fr($date) . " à $time\n"
            . "Passagers : $pax\n"
            . 'Total : CHF ' . number_format($total, 2, '.', "'") . "\n"
            . "Nom : $name\nE-mail : $email\nTéléphone : $phone\n"
            . ($weights ? "Poids : $weights\n" : '')
            . ($message ? "\nMessage :\n$message\n" : '');

        send_mail(config('admin_email'), "[$site] Nouvelle réservation $ref", "Nouvelle réservation :\n\n$summary");
        if (config('send_client_email')) {
            send_mail($email, "$site - Demande de réservation $ref",
                "Bonjour $name,\n\nMerci pour votre demande de réservation. Nous vous contacterons "
                . "pour confirmer votre vol selon les conditions météo.\n\n$summary\n$site");
        }

        respond(['ok' => true, 'reference' => $ref, 'total' => $total]);
    }

    respond(['error' => 'Action inconnue'], 404);
} catch (Throwable $e) {
    try {
        db()->exec('ROLLBACK');
    } catch (Throwable $ignored) {
    }
    error_log('[reservation] ' . $e->getMessage());
    respond(['error' => 'Erreur serveur, veuillez réessayer.'], 500);
}
