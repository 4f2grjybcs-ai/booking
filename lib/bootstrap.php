<?php
// Chargement commun : configuration, base de données, fonctions utilitaires.

$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone']);

function config(string $key)
{
    global $CONFIG;
    return $CONFIG[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $path = config('db_path');
    $isNew = !file_exists($path);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($isNew) {
        install_schema($pdo);
    }
    return $pdo;
}

function install_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE flights (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            description TEXT DEFAULT '',
            duration    TEXT DEFAULT '',
            price       REAL NOT NULL DEFAULT 0,
            active      INTEGER NOT NULL DEFAULT 1,
            sort_order  INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE slots (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            time     TEXT NOT NULL UNIQUE,
            capacity INTEGER NOT NULL DEFAULT 4,
            active   INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE blocked_dates (
            date   TEXT PRIMARY KEY,
            reason TEXT DEFAULT ''
        );
        CREATE TABLE bookings (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            reference    TEXT NOT NULL UNIQUE,
            date         TEXT NOT NULL,
            time         TEXT NOT NULL,
            flight_id    INTEGER REFERENCES flights(id),
            flight_name  TEXT NOT NULL,
            price        REAL NOT NULL DEFAULT 0,
            passengers   INTEGER NOT NULL DEFAULT 1,
            name         TEXT NOT NULL,
            email        TEXT NOT NULL,
            phone        TEXT NOT NULL,
            weights      TEXT DEFAULT '',
            message      TEXT DEFAULT '',
            status       TEXT NOT NULL DEFAULT 'pending',
            admin_notes  TEXT DEFAULT '',
            created_at   TEXT NOT NULL,
            updated_at   TEXT NOT NULL
        );
        CREATE INDEX idx_bookings_date ON bookings(date, time);
    ");

    // Données de départ (modifiables dans le back office)
    $flights = [
        ['Vol découverte', 'Premier vol en biplace, idéal pour découvrir le parapente.', '~15 min', 180],
        ['Vol grand panorama', 'Vol plus long depuis le sommet, vue sur les Alpes.', '~25 min', 250],
        ['Vol thermique', 'Vol prolongé en thermique pour les amateurs de sensations.', '~40 min', 320],
    ];
    $st = $pdo->prepare('INSERT INTO flights (name, description, duration, price, sort_order) VALUES (?,?,?,?,?)');
    foreach ($flights as $i => $f) {
        $st->execute([$f[0], $f[1], $f[2], $f[3], $i]);
    }
    $st = $pdo->prepare('INSERT INTO slots (time, capacity) VALUES (?, 4)');
    foreach (['08:30', '10:00', '11:30', '13:00', '14:30', '16:00'] as $t) {
        $st->execute([$t]);
    }
}

function setting(string $key, $default = null)
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $key, $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function status_labels(): array
{
    return [
        'pending'   => 'En attente',
        'confirmed' => 'Confirmée',
        'done'      => 'Effectuée',
        'cancelled' => 'Annulée',
    ];
}

/**
 * Places restantes pour chaque créneau d'une date donnée.
 * Retourne [] si la date est fermée ou hors période.
 */
function availability(string $date): array
{
    $today = date('Y-m-d');
    $max = date('Y-m-d', strtotime('+' . (int) config('max_days_ahead') . ' days'));
    if ($date < $today || $date > $max) {
        return [];
    }
    $st = db()->prepare('SELECT 1 FROM blocked_dates WHERE date = ?');
    $st->execute([$date]);
    if ($st->fetchColumn()) {
        return [];
    }

    $st = db()->prepare("SELECT time, SUM(passengers) AS taken FROM bookings
                         WHERE date = ? AND status != 'cancelled' GROUP BY time");
    $st->execute([$date]);
    $taken = [];
    foreach ($st as $row) {
        $taken[$row['time']] = (int) $row['taken'];
    }

    $nowTime = date('H:i');
    $out = [];
    foreach (db()->query('SELECT time, capacity FROM slots WHERE active = 1 ORDER BY time') as $s) {
        if ($date === $today && $s['time'] <= $nowTime) {
            continue;
        }
        $out[] = [
            'time'      => $s['time'],
            'remaining' => max(0, (int) $s['capacity'] - ($taken[$s['time']] ?? 0)),
        ];
    }
    return $out;
}

function generate_reference(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $ref = 'FV-';
        for ($i = 0; $i < 6; $i++) {
            $ref .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = db()->prepare('SELECT 1 FROM bookings WHERE reference = ?');
        $st->execute([$ref]);
    } while ($st->fetchColumn());
    return $ref;
}

function send_mail(string $to, string $subject, string $body): void
{
    if (!$to) {
        return;
    }
    $headers = [
        'From: ' . config('site_name') . ' <' . config('from_email') . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

function format_date_fr(string $date): string
{
    $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
               'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($date);
    return $days[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' '
        . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}
