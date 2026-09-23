# Système de réservation – Fly Verbier

## Version WordPress (recommandée si vous n'avez accès qu'à WordPress)

Le dossier `wordpress/flyverbier-reservation/` est une extension WordPress.

1. Zipper le dossier `flyverbier-reservation` (ou utiliser le ZIP fourni).
2. WordPress → **Extensions → Ajouter → Téléverser une extension** → choisir le ZIP → **Installer** → **Activer**.
3. Un menu **Réservations** apparaît dans l'administration (liste, ajout, paramètres).
4. Créer une page « Réserver » contenant le shortcode `[reservation_parapente]`.
5. Vérifier **Réglages → Général → Fuseau horaire** = Zurich.

### Lien administrateur secret (v1.4)

- **Réservations → Paramètres → Votre agenda administrateur** : lien qui ouvre l'agenda en mode modification
  sans connexion WordPress (idéal sur téléphone, « Ajouter à l'écran d'accueil »). Ne pas le partager ;
  « Générer un nouveau lien administrateur » invalide immédiatement l'ancien.

### Pilotes (v1.3)

- **Réservations → Pilotes** : profils (nom, téléphone, e-mail, couleur), **ordre par défaut** (pilote n°1, n°2…),
  lien personnel (planning + abonnement agenda de ses seuls vols), envoi du lien par WhatsApp.
- Chaque passager reçoit un pilote : attribution automatique dans l'ordre par défaut en sautant les pilotes
  déjà en vol à la même heure ; les places restantes sont « à définir » et se choisissent dans la réservation.
- Agenda du pilote sur mobile : barre « ‹ date › », journée 8h–18h, balayage gauche/droite, filtre « Mes vols / Tous ».
- Choix de l'heure : menu déroulant 8h00–18h00 par quarts d'heure.

### Agenda style Google Agenda (v1.2)

- Vues Jour / Semaine / Mois / Planning (liste, vue par défaut sur téléphone), mini-calendrier,
  ligne « maintenant », raccourcis clavier (J, S, M, P, T, ←/→, / pour chercher, C pour créer).
- Recherche rapide par nom, téléphone (079… ou +41 79…) ou référence.
- Fiche de modification plein écran sur téléphone : statut en un tap, horaires avec places libres,
  +/− passagers, boutons Appeler / SMS / WhatsApp / E-mail.
- Clic dans une case vide pour créer, glisser-déposer pour déplacer (ordinateur).

### Calendrier (v1.1)

- **Réservations → Calendrier** : vues jour / semaine / mois, taux de remplissage par créneau,
  modification d'une réservation en cliquant dessus, déplacement par glisser-déposer, ajout avec « + ».
- **Lien pour les pilotes** (Réservations → Paramètres) : page web en lecture seule, sans prix ni e-mails,
  adaptée au téléphone. Le même lien, ouvert par l'administrateur connecté, permet de modifier.
  « Générer un nouveau lien » invalide l'ancien.
- **Abonnement agenda** (.ics) pour Google Agenda / iPhone / Outlook, mis à jour automatiquement.

Les données sont enregistrées dans la base de données WordPress (tables `wp_fvr_*`) ;
elles sont incluses dans les sauvegardes habituelles du site.

---

## Version autonome (PHP + SQLite, nécessite un accès FTP)

Réservation en ligne de vols en parapente biplace, avec :

- **Page client** (`index.html`) : choix du vol, de la date, de l'horaire et du nombre de passagers,
  coordonnées, poids, message. Les places restantes sont affichées en temps réel et la surréservation
  est impossible.
- **Back office** (`admin/`) : liste des réservations par jour, changement de statut
  (en attente / confirmée / effectuée / annulée), recherche, création manuelle (réservations par
  téléphone), modification (report météo), notes internes, export Excel (CSV).
  Paramètres : types de vol et prix, créneaux horaires et capacité, jours fermés, mot de passe.
- **Sauvegarde** : toutes les données sont enregistrées dans une base SQLite
  (`data/reservations.sqlite`), créée automatiquement au premier accès.
- **E-mails** : notification à l'administrateur et récapitulatif au client (via `mail()` de PHP).

Aucune dépendance à installer : il suffit d'un hébergement **PHP 8+ avec SQLite**
(Infomaniak, Hostpoint, OVH, etc. l'ont par défaut).

## Installation (ex. test sur centreparapente.ch)

1. Copier tout le dossier par FTP dans un sous-dossier du site, par exemple `/reservation/`.
2. Ouvrir `config.php` et adapter `site_name`, `admin_email` (reçoit les nouvelles réservations)
   et `from_email` (une adresse existante du domaine, sinon les e-mails risquent de finir en spam).
3. Vérifier que le dossier `data/` est accessible en écriture par PHP (droits 750 ou 755).
4. Aller sur `https://centreparapente.ch/reservation/admin/` : au premier accès, vous choisissez
   le mot de passe administrateur.
5. Dans **Paramètres**, régler vos vols, prix, horaires et capacité (nombre de passagers par créneau).
6. Page client : `https://centreparapente.ch/reservation/`

Pour passer en production sur flyverbier.ch, refaire la même copie sur ce site
(sans copier `data/reservations.sqlite`, pour repartir d'une base vide).

### Intégrer la page dans le site existant

Ajouter un lien « Réserver » vers `/reservation/`, ou intégrer le formulaire dans une page
(WordPress, Wix, etc.) avec :

```html
<iframe src="https://flyverbier.ch/reservation/" style="width:100%;height:1700px;border:0"></iframe>
```

## Sécurité et sauvegardes

- Les fichiers `.htaccess` bloquent l'accès web à `data/`, `lib/` et `config.php` (serveurs Apache).
  Sur un serveur **nginx**, bloquer ces chemins dans la configuration.
- Le site doit être en **HTTPS** (certificat Let's Encrypt gratuit chez la plupart des hébergeurs).
- **Sauvegarde** : télécharger régulièrement `data/reservations.sqlite` par FTP (c'est l'intégralité
  des données), ou utiliser l'export CSV du back office.

## Tester en local

```bash
php -S localhost:8000
# client : http://localhost:8000/   back office : http://localhost:8000/admin/
```

## Structure

| Fichier | Rôle |
|---|---|
| `index.html`, `assets/app.js`, `assets/style.css` | Page de réservation client |
| `api.php` | API utilisée par la page client (vols, disponibilités, réservation) |
| `admin/index.php` | Back office |
| `lib/bootstrap.php` | Base de données, disponibilités, e-mails |
| `config.php` | Réglages |
| `data/` | Base de données SQLite (créée automatiquement) |
