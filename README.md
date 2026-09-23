# Système de réservation – Fly Verbier

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
