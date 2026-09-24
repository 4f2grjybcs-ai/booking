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
- **E-mails configurables** : nom et adresse d’expéditeur, objet et texte de chaque e-mail avec variables (`{name}`, `{check_in}`, `{summary}`…), activation par e-mail, copie cachée, **SMTP intégré**, e-mail de test et journal des envois.
- **Galerie photos** triable (shortcode `[chalet_gallery]`) avec visionneuse plein écran (flèches, clavier, balayage sur mobile).
- **Conditions générales** rédigées dans l’admin (shortcode `[chalet_terms]`), dépliables et à accepter dans le formulaire.
- **Description du chalet** (shortcode `[chalet_description]`) : présentation, chiffres clés, pièces avec photo, équipements par catégorie, inclus / en supplément, règlement intérieur, situation et accès. Se remplit dans *Réservations → Description du chalet*.
- **Multilingue : français, anglais, allemand, espagnol** avec bouton 🌐 de changement de langue (voir ci-dessous).
- **Back-office sans WordPress** (voir ci-dessous).
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

## Back-office (gestion sans passer par WordPress)

Menu **Réservations → Réglages → « Créer la page de gestion »** : crée la page `/gestion` (shortcode `[chalet_backoffice]`), qui s’affiche en plein écran avec sa propre page de connexion, sur ordinateur comme sur téléphone (ajoutez-la à l’écran d’accueil).

- **Accueil** : demandes à traiter (confirmer / refuser en un geste), client actuellement présent, arrivées, départs (planning ménage), paiements à recevoir, chiffre d’affaires, taux d’occupation, état de la synchronisation Airbnb / Booking.
- **Calendrier** : vue mensuelle colorée par statut ; toucher deux jours pour bloquer des dates ou créer une réservation.
- **Réservations** : recherche, filtres, fiche complète : modifier dates / voyageurs / prix, suivi des paiements (acompte 30 %, tout payé, solde), notes internes, appeler, WhatsApp, e-mail libre ou renvoi d’un modèle, confirmer / annuler / réactiver / supprimer.
- **Nouvelle entrée** : réservation confirmée, option en attente ou blocage, avec calcul automatique du prix et alerte de chevauchement.
- **Tarifs** : prix de base, nettoyage, taxe de séjour, rabais, saisons (avec copie sur l’année suivante).
- **Statistiques** : chiffre d’affaires et occupation par mois, prix moyen par nuit.
- **Plus** : export CSV (Excel), synchronisation manuelle, journal des e-mails, déconnexion.

**Donner accès à quelqu’un d’autre** (conciergerie, famille…) : *Utilisateurs → Ajouter* avec le rôle **« Gestionnaire du chalet »**. Cette personne n’accède qu’au back-office : wp-admin lui est fermé.

## Langues (FR / EN / DE / ES)

- **Bouton 🌐** en bas à gauche de toutes les pages (désactivable), ou shortcode `[chalet_langues]` à placer où vous voulez. À la première visite, la langue du navigateur est proposée ; le choix est ensuite mémorisé.
- **Textes du plugin** (calendrier, formulaire, prix, erreurs, équipements, e-mails par défaut) : déjà traduits.
- **Vos textes** (présentation, pièces, règles, conditions générales, noms de saisons, légendes de photos, titres des pages et du menu, e-mails personnalisés) : à traduire dans **Réservations → Traductions**, avec un lien DeepL par texte. Sans traduction, le français s’affiche.
- **E-mails** : le client reçoit ses e-mails dans la langue utilisée sur le site ; le propriétaire les reçoit en français.
- Choix des langues proposées : **Réservations → Réglages → Langues du site**.
- Le back-office et l’administration restent en français.
- Si Polylang, WPML ou TranslatePress est installé, le plugin suit sa langue et masque son propre bouton.

## E-mails : si vous ne les recevez pas

WordPress envoie par défaut via le serveur de l’hébergeur, souvent bloqué ou classé en spam. Dans **Réservations → E-mails** :

1. Renseignez une **adresse d’expéditeur** de votre domaine (ex. `reservation@votre-chalet.ch`).
2. Remplissez la section **SMTP** avec les paramètres de votre messagerie (Infomaniak : `mail.infomaniak.com`, port 587, TLS ; Gmail : `smtp.gmail.com`, port 587, TLS, avec un mot de passe d’application).
3. Cliquez sur **Envoyer le test**, puis consultez le **journal** en bas de page : il affiche l’erreur exacte en cas d’échec.

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
│   ├── class-cb-admin.php        Pages d’administration (wp-admin)
│   ├── class-cb-content.php      Galerie photos et conditions générales
│   ├── class-cb-description.php  Description du chalet (pièces, équipements, règles)
│   ├── class-cb-i18n.php         Langues : sélecteur, traductions, e-mails dans la langue du client
│   ├── class-cb-backoffice.php   Back-office : accès, rôle, page plein écran
│   └── class-cb-manage-api.php   API privée du back-office
├── languages/                    Traductions EN / DE / ES (.po / .mo) et modèle .pot
└── assets/                       JS / CSS (réservation, galerie, back-office)
```

Prérequis : WordPress 6.0+, PHP 7.4+, MySQL/MariaDB.
