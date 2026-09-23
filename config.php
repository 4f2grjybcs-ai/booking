<?php
// Configuration du système de réservation.
// Modifiez ces valeurs selon votre site (flyverbier.ch, centreparapente.ch, ...).

return [
    // Nom affiché sur la page client et dans les e-mails
    'site_name'   => 'Fly Verbier',

    // Adresse qui reçoit une notification à chaque nouvelle réservation (vide = pas d'e-mail)
    'admin_email' => '',

    // Adresse d'expédition des e-mails (doit exister sur votre hébergement)
    'from_email'  => 'noreply@flyverbier.ch',

    // Envoyer un e-mail de confirmation au client
    'send_client_email' => true,

    // Fuseau horaire
    'timezone'    => 'Europe/Zurich',

    // Emplacement de la base de données (dossier protégé, hors d'accès web)
    'db_path'     => __DIR__ . '/data/reservations.sqlite',

    // Nombre de jours maximum à l'avance pour réserver
    'max_days_ahead' => 365,
];
