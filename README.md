# Chalet Booking — plugin WordPress

Plugin de réservation pour un chalet de location de vacances à Verbier.

## Fonctionnalités

- **Calendrier de disponibilités** avec sélection arrivée → départ (jours de rotation gérés : un client peut arriver le jour où le précédent repart).
- **Tarifs par saison** (Noël / Nouvel An, février, Pâques, été…), prix hors saison, **minimum de nuits** et **jour d’arrivée imposé** (ex. samedi → samedi) par saison.
- **Devis automatique** : nuits, rabais dès 7 nuits, frais de nettoyage, taxe de séjour par adulte et par nuit. Montants en CHF (devise modifiable).
- **Demande de réservation** : le client envoie une demande (sans paiement en ligne). Les dates sont bloquées immédiatement et le propriétaire confirme ou refuse depuis l’admin.
- **E-mails automatiques** : accusé de réception au client, notification au propriétaire, confirmation (avec modalités de paiement) ou refus.
- **Admin** : liste des réservations, confirmation / annulation, ajout d’une réservation téléphonique, blocage de dates (usage personnel, travaux).
- **Synchronisation iCal** avec Airbnb, Booking.com, Google Agenda… (export d’un lien `.ics` + import de calendriers externes toutes les heures) pour éviter les doubles réservations.
- Protection anti-spam (champ piège + limite de 5 demandes/heure par IP) et verrou contre les réservations simultanées.

## Installation

1. Compresser le dossier `chalet-booking/` en `chalet-booking.zip`.
2. WordPress → *Extensions → Ajouter → Téléverser une extension* → choisir le zip → *Activer*.
3. Menu **Réservations → Réglages** : nom du chalet, capacité, prix, saisons, e-mail de notification, modalités de paiement.
4. Créer une page (ex. « Réserver ») contenant le shortcode :

   ```
   [chalet_booking]
   ```

   Option : `[chalet_booking months="1"]` pour n’afficher qu’un mois (1 à 4, défaut 2).

## Synchronisation avec Airbnb / Booking.com

- **Export** : copier le « Lien d’export » affiché dans les réglages et l’importer dans Airbnb / Booking.com (*Disponibilités → Synchroniser les calendriers → Importer*).
- **Import** : coller les liens `.ics` d’Airbnb / Booking.com dans « Calendriers à importer » (un par ligne). Synchronisation toutes les heures, ou bouton « Synchroniser maintenant ».

> WP-Cron ne tourne que lorsque le site reçoit des visites. Pour une synchronisation fiable, configurez une tâche cron serveur qui appelle `wp-cron.php` (la plupart des hébergeurs le proposent).

## Personnalisation de l’apparence

Les couleurs se changent dans le CSS du thème :

```css
.cb-booking {
	--cb-accent: #1f4e79;      /* couleur principale */
	--cb-accent-soft: #dce8f3; /* jours du séjour sélectionné */
	--cb-free: #eef6ee;        /* jours disponibles */
	--cb-booked: #d9d9d9;      /* jours réservés */
}
```

## Structure

```
chalet-booking/
├── chalet-booking.php            Fichier principal
├── uninstall.php                 Nettoyage à la désinstallation
├── includes/
│   ├── class-cb-db.php           Table des réservations
│   ├── class-cb-settings.php     Réglages et saisons
│   ├── class-cb-availability.php Disponibilités et règles de séjour
│   ├── class-cb-pricing.php      Calcul du prix
│   ├── class-cb-emails.php       E-mails
│   ├── class-cb-ical.php         Export / import iCal
│   ├── class-cb-rest.php         API REST (disponibilités, devis, réservation)
│   ├── class-cb-shortcode.php    Shortcode [chalet_booking]
│   └── class-cb-admin.php        Pages d’administration
└── assets/                       JS du calendrier et CSS
```

Prérequis : WordPress 6.0+, PHP 7.4+, MySQL/MariaDB.
