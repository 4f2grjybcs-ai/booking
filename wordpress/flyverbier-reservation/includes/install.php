<?php
// Création des tables dans la base de données WordPress.

if (!defined('ABSPATH')) {
    exit;
}

function fvr_table(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'fvr_' . $name;
}

function fvr_install(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE " . fvr_table('flights') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  description text NOT NULL,
  duration varchar(60) NOT NULL DEFAULT '',
  price decimal(10,2) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $charset;");

    dbDelta("CREATE TABLE " . fvr_table('slots') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  time char(5) NOT NULL,
  capacity int(11) NOT NULL DEFAULT 4,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY time (time)
) $charset;");

    dbDelta("CREATE TABLE " . fvr_table('blocked') . " (
  date date NOT NULL,
  reason varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (date)
) $charset;");

    dbDelta("CREATE TABLE " . fvr_table('bookings') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reference varchar(20) NOT NULL,
  date date NOT NULL,
  time char(5) NOT NULL,
  flight_id bigint(20) unsigned DEFAULT NULL,
  flight_name varchar(190) NOT NULL,
  price decimal(10,2) NOT NULL DEFAULT 0,
  passengers int(11) NOT NULL DEFAULT 1,
  name varchar(190) NOT NULL,
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  weights varchar(200) NOT NULL DEFAULT '',
  message text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  admin_notes text NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  KEY date_time (date,time)
) $charset;");

    dbDelta("CREATE TABLE " . fvr_table('pilots') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  phone varchar(40) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  color varchar(7) NOT NULL DEFAULT '#0b57d0',
  default_rank int(11) NOT NULL DEFAULT 0,
  token varchar(64) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token)
) $charset;");

    // Pilote attribué à chaque place (1 passager = 1 pilote en biplace)
    dbDelta("CREATE TABLE " . fvr_table('assign') . " (
  booking_id bigint(20) unsigned NOT NULL,
  seat int(11) NOT NULL,
  pilot_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (booking_id,seat),
  KEY pilot_id (pilot_id)
) $charset;");

    dbDelta("CREATE TABLE " . fvr_table('events') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  date date NOT NULL,
  all_day tinyint(1) NOT NULL DEFAULT 0,
  start_time char(5) NOT NULL DEFAULT '00:00',
  end_time char(5) NOT NULL DEFAULT '23:59',
  title varchar(190) NOT NULL,
  note text NOT NULL,
  blocks int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY date (date)
) $charset;");

    // Données de départ, uniquement lors de la première installation
    if (!(int) $wpdb->get_var('SELECT COUNT(*) FROM ' . fvr_table('flights'))) {
        $flights = [
            ['Vol découverte', 'Premier vol en biplace, idéal pour découvrir le parapente.', '~15 min', 180],
            ['Vol grand panorama', 'Vol plus long depuis le sommet, vue sur les Alpes.', '~25 min', 250],
            ['Vol thermique', 'Vol prolongé en thermique pour les amateurs de sensations.', '~40 min', 320],
        ];
        foreach ($flights as $i => $f) {
            $wpdb->insert(fvr_table('flights'), [
                'name' => $f[0], 'description' => $f[1], 'duration' => $f[2], 'price' => $f[3], 'sort_order' => $i,
            ]);
        }
    }
    if (!(int) $wpdb->get_var('SELECT COUNT(*) FROM ' . fvr_table('slots'))) {
        foreach (['08:30', '10:00', '11:30', '13:00', '14:30', '16:00'] as $t) {
            $wpdb->insert(fvr_table('slots'), ['time' => $t, 'capacity' => 4]);
        }
    }

    add_option('fvr_settings', fvr_default_settings());
    update_option('fvr_db_version', FVR_VERSION);
}

function fvr_maybe_upgrade(): void
{
    if (get_option('fvr_db_version') !== FVR_VERSION) {
        fvr_install();
    }
}
