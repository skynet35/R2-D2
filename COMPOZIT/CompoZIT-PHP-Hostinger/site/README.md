# CompoZ'IT — Gestion de stock électronique

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1.svg?logo=mysql&logoColor=white)](https://dev.mysql.com/)
[![License](https://img.shields.io/badge/Licence-MIT-green.svg)](LICENSE)

Un **système de gestion de composants électroniques**, de projets PCB, d'emplacements de stockage et de datasheets, **100% en PHP/MySQL** (sans dépendances externes, parfait pour XAMPP).

---

## 🚀 Fonctionnalités

### 🔐 Utilisateurs
- Inscription / Connexion sécurisée (hachage `password_hash()`, sessions, PDO prepared statements)
- Déconnexion, profil utilisateur

### 📦 Composants électroniques
- Catalogue complet **15+ infos par composant** (référence, fabricant, package, prix, stock, datasheet…)
- Emplacements physiques : **casier 🗄️ / boîte 📦 / classeur 🗂️ / étagère 🪜** (type + logo personnalisable par casier)
- Upload d'image composant, **autocomplete fabricant + package** avec infos contextuelles (type package / nombre de pins / montage)
- Gestion fournisseurs (contacts, logo)
- Catégories / Sous-catégories hiérarchiques

### 🚀 Projets électroniques
- Création de projets avec **image de couverture**, **description**, **statut** (En cours / Terminé / Pause / Abandonné / Planifié)
- **BOM** : composants nécessaires vs composants utilisés + progression auto
- Suivi financier : coût total projet + « Travaux & Matériaux »
- **Documents & Photos** : upload datasheet PDF, photos, ZIP, schémas…
- **Export PDF du projet + Export CSV BOM compatible fournisseurs
- **Renommage instantané** d'un projet (crayon inline → dossier physique + chemins BDD synchronisés (aucun fichier cassé)

### 🧰 Autres
- Packages, fabricants, fournisseurs
- Export données

---

## 📋 Prérequis

- **XAMPP / WAMP / MAMP** (ou tout serveur web)
  - **PHP 7.4 ou supérieur (testé sur PHP 8.0)
  - **MySQL 5.7+ ou 8.x**
  - Extension **PDO MySQL** activée
  - Extension **mbstring** (recommandé)
  - Upload fichier max. ≥ 16 Mo (recommandé)
- Navigateur moderne (Chrome / Firefox / Edge)

---

## 🛠️ Installation (2 options)

### Option A — Serveur PHP intégré (DÉMARRAGE 10 SECONDES)

1. **Cloner / dézipper le repo dans un dossier (ex: `C:\TRAE\Compoz-it`)

2. **Créer votre configuration locale**
   - Copier   `config.local.template.php`   →   renommer en  `config.local.php`
   - Dans ce fichier, saisir vos identifiants MySQL

3. **Démarrer le serveur**
   - Copier `start_server.template.bat` → `start_server.bat` (adapter `PHP_PATH` si besoin)
   - Double-cliquer sur `start_server.bat`
   - OU en ligne de commande :
     ```bash
     cd Compoz-it
     C:\xampp\php\php.exe -S localhost:8000
     ```

4. **Installer la base de données
   - Ouvrir phpMyAdmin : `http://localhost/phpmyadmin/`
   - Créer une base nommée `compozit`
   - **Importer** `compozit.sql` (structure + données exemple)
   - OU laisser `config.php` créer la structure automatiquement (mode vide)

5. **C'est prêt !** → `http://localhost:8000/`

### Option B — Dossier htdocs XAMPP classique

1. Copier le dossier `Compoz-it` dans `C:\xampp\htdocs\`
2. Renommer `config.local.template.php` en `config.local.php` et éditer les credentials
3. Démarrer Apache + MySQL dans XAMPP Control Panel
4. Importer `compozit.sql` dans phpMyAdmin (base `compozit`)
5. Aller sur `http://localhost/Compoz-it/`

---

## 🔑 Premier lancement — Compte administrateur

Par défaut si tu importes **`compozit.sql`**, un compte admin existe déjà :

| Email                 | Mot de passe |
|-----------------------|-------------|
| `admin@compozit.fr`   | `admin123`   |

👉 **Changez ce mot de passe immédiatement** dans `Profil` après connexion !**

Pour générer un hash custom :
```bash
php -r "echo password_hash('MON_MDP_SUPER_SOLIDE', PASSWORD_DEFAULT);"
```
Puis tu colles le résultat dans la colonne `users.password` via phpMyAdmin.

---

## ⚙️ Configuration détaillée (fichiers)

### `config.local.php` (fichier **JAMAIS committé — dans .gitignore)
```php
define('DB_HOST',     'localhost');
define('DB_NAME',     'compozit');
define('DB_USER',     'root');
define('DB_PASSWORD', '');   // votre mdp MySQL (vide par défaut XAMPP)
```

### `.gitignore`
- `config.local.php` (⚠️ JAMAIS commit vos passwords sur GitHub)
- `Projets/*` datasheet PDF / images uploadés par les utilisateurs
- `logs/*.log`, `tmp/*` sessions PHP
- `*.log`, `vscode`, `.idea` fichiers éditeur
- `start_server.bat` / `setup_auto_images.bat` (vos chemins locaux : copiez `.template.bat` d'abord !)

---

## 📁 Structure du code

```
Compoz-it/
├── 📄 index.php                       # Page connexion / inscription
├── 📄 components.php                    # Stock composants (recherche, tri)
├── 📄 projects.php                       # Liste projets (cards + crayon rename inline)
├── 📄 project_detail.php                # Détail 1 projet (BOM, docs, renommage crayon inline)
├── 📄 locations.php                     # Gestion emplacements (casier/boîte/classeur/étagère)
├── 📄 categories_management.php         # Hiérarchie catégories / sous-catégories
├── 📄 packages_management.php             # Packages CMS
├── 📄 suppliers_management.php               # Fournisseurs
├── 📄 manufacturers.php                   # Fabricants
├── 📄 settings.php / profile.php            # Paramètres / profil
│
├── 📄 config.php                    # ⚠️ NE PAS EDITER (chargement auto config.local.php
├── 📄 config.local.template.php      # ✏️ Modèle à copier en config.local.php
├── 📄 session_init.php                 # Sessions PHP (sécurité)
├── 📄 auth.php                        # Login / Register traitement (hash password)
│
├── 📤 upload_project_file.php        # Uploads fichiers joints / images projet
├── 📤 upload_project_image.php
├── 📤 upload_image.php
├── 📥 download_project_file.php
│
├── 📄 create_project_ajax.php            # AJAX endpoints
├── 📄 ajax_update_project_*.php             # (quantités / progression / BOM
├── 📄 get_packages.php / get_subcategories.php
│
├── 🗃️ compozit.sql                # ⭐ Export structure + données exemple
│
├── 🖼️ img/                       # Images packages, logos, placeholders
│                                # (Toujours commit ça sur Git OK)
└── 🚩 Projets/                   # (⚠️ Pas sur Git : contient uploads utilisateurs)
    ├── Nom_Projet_1/
    │   ├── project_image_*.png
    │   ├── datasheet.pdf
    │   └── ...
    └── ...
```

---

## 🔒 Sécurité

- ✅ Mots de passe hachés `password_hash()` (argon2id / bcrypt)
- ✅ Toutes les requêtes SQL = `PDO prepared statements (0 injection SQL)
- ✅ Toutes les valeurs affichées = `htmlspecialchars()` (0 XSS)
- ✅ `config.php` non accessible directement en navigateur (HTTP 403)
- ✅ Validation `owner` sur TOUS les UPDATE/DELETE (chaque utilisateur ne voit que SES données)
- ✅ Fichiers locaux sensibles (`config.local.php`, Projets/`, `logs/*`, `tmp/*`) ignorés via `.gitignore`
- ✅ Chemins uploads : whitelist caractères sur noms projets (A-Za-z0-9_-), pas de path traversal possible

---

## 🐛 Dépannage

| Symptôme | Cause fréquente | Solution
|---|---|---|
| **Erreur SQL « Unknown column storage_type »** | Ancienne BDD | Recharger une page `locations.php` → la migration `ensureLocationStorageTypeColumn()` dans `config.php` ajoute la colonne automatiquement
| **Page blanche** | Erreur PHP masquée | Ajouter `error_reporting(E_ALL); ini_set('display_errors', 1);` en haut de `index.php` puis rafraîchir
| **Upload fichiers échoue** | `upload_max_filesize` trop petit | Éditer `php.ini` (XAMPP) : `upload_max_filesize = 64M` et `post_max_size = 64M`
| **404 sur projets/NomDossier/** | Nom projet renommé mais fichiers non déplacés | La mise à jour des chemins `projects.image_path` et `project_files.file_path` est maintenant **automatique** au renommage du projet.
| **Login impossible** | Mot de passe BDD hashé | Réinitialiser via phpMyAdmin : `UPDATE users SET password = password_hash('admin123', PASSWORD_DEFAULT) WHERE email='admin@compozit.fr'`

---

## 📝 Notes pour déploiement en production

1. **Supprimer** `install.php` (si présent), `compozit.sql` (s'il contient vos données persos)
2. **Mettre `COMPOZIT_ENV='production'` dans `config.local.php`**
3. **Passer PHP en `display_errors=0` et `log_errors=1`** (php.ini)
4. **Forcer HTTPS** (Ajouter un `header('Strict-Transport-Security: max-age=31536000')` ou via `.htaccess`)
5. **Sauvegarde auto régulière de la BDD** : `mysqldump -u root compozit > backup_compozit_YYYYMMDD.sql`

---

## 🤝 Contribution

Toute PR / idée est la bienvenue :
- Suggestions UX
- Traduction EN/ES/DE
- Améliorations sécurité
- Nouvelles fonctions (scanner code-barres, alertes stock bas, etc.)

---

**Avec ❤️ pour tous les makers / bidouilleurs électroniques / boîtes remplies de résistances mal triées 😉
