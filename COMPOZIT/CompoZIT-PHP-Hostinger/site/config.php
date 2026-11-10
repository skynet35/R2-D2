<?php
/* =========================================================================
   Configuration de la base de données
   ---------------------------------------------------------------------------
   NE PAS MODIFIER DIRECTEMENT CE FICHIER SUR VOTRE SERVEUR DE PRODUCTION !
   ---------------------------------------------------------------------------
   Pour personnaliser DB_HOST / DB_NAME / DB_USER / DB_PASSWORD :
   1. Copiez ce fichier     :    config.local.template.php  → config.local.php
   2. Editez config.local.php avec vos credentials
   3. Par défaut : config.local.php est DANS .gitignore (jamais commitésur GitHub)
   ========================================================================= */

/* --- Surcharge FACULTATIVE via fichier local (toujours chargé EN PREMIER, avant define() -- pour eviter les "Cannot redeclare") --- */
$_compozitLocalConfig = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (file_exists($_compozitLocalConfig)) {
    require_once $_compozitLocalConfig;
}
unset($_compozitLocalConfig);

/* --- Valeurs par défaut (fallback si config.local.php absent ou incomplet) --- */
if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))     define('DB_NAME', 'compozit');
if (!defined('DB_USER'))     define('DB_USER', 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', '');

/* --- API Nexar / Octopart (import de composants) ---
   Renseignez NEXAR_CLIENT_ID / NEXAR_CLIENT_SECRET dans config.local.php
   (ou via Paramètres → Réglage API).
   Vides par défaut : le panneau de recherche Nexar est alors masqué. */
if (!defined('NEXAR_CLIENT_ID'))     define('NEXAR_CLIENT_ID', '');
if (!defined('NEXAR_CLIENT_SECRET')) define('NEXAR_CLIENT_SECRET', '');

/* Nombre de résultats renvoyés par recherche Nexar (chaque résultat consommé
   est décompté du quota mensuel : gardez une valeur raisonnable). */
if (!defined('NEXAR_SEARCH_LIMIT'))  define('NEXAR_SEARCH_LIMIT', 5);

/* --- API Mouser (import de composants) ---
   Renseignez MOUSER_API_KEY dans config.local.php (ou via Paramètres → Réglage API).
   Vide par défaut : Mouser n'est alors pas proposé comme source de recherche. */
if (!defined('MOUSER_API_KEY'))      define('MOUSER_API_KEY', '');

/* Nombre de résultats renvoyés par recherche Mouser. Étant donné que la clé
   gratuite n'a pas de quota "parts", la valeur peut être plus généreuse. */
if (!defined('MOUSER_SEARCH_LIMIT')) define('MOUSER_SEARCH_LIMIT', 10);

/* --- API Farnell / element14 / Newark (import de composants) ---
   Renseignez FARNELL_API_KEY dans config.local.php (ou via Paramètres → Réglage API).
   Vide par défaut : Farnell n'est alors pas proposé comme source de recherche. */
if (!defined('FARNELL_API_KEY'))     define('FARNELL_API_KEY', '');

/* Magasin régional interrogé : fr.farnell.com, uk.farnell.com, www.newark.com,
   au.element14.com… Il détermine le catalogue, les prix et la devise. */
if (!defined('FARNELL_STORE_ID'))    define('FARNELL_STORE_ID', 'fr.farnell.com');

/* Nombre de résultats renvoyés par recherche Farnell (l'offre gratuite autorise
   environ 1 000 appels par jour). */
if (!defined('FARNELL_SEARCH_LIMIT')) define('FARNELL_SEARCH_LIMIT', 10);

/* --- API DigiKey (import de composants) ---
   Renseignez DIGIKEY_CLIENT_ID / DIGIKEY_CLIENT_SECRET dans config.local.php
   (ou via Paramètres → Réglage API). Vidés par défaut : DigiKey n'est alors
   pas proposé comme source de recherche. */
if (!defined('DIGIKEY_CLIENT_ID'))     define('DIGIKEY_CLIENT_ID', '');
if (!defined('DIGIKEY_CLIENT_SECRET')) define('DIGIKEY_CLIENT_SECRET', '');

/* Localisation des appels DigiKey : magasin, langue et devise. */
if (!defined('DIGIKEY_LOCALE_SITE'))     define('DIGIKEY_LOCALE_SITE', 'FR');
if (!defined('DIGIKEY_LOCALE_LANGUAGE')) define('DIGIKEY_LOCALE_LANGUAGE', 'fr');
if (!defined('DIGIKEY_LOCALE_CURRENCY')) define('DIGIKEY_LOCALE_CURRENCY', 'EUR');

/* Nombre de résultats renvoyés par recherche DigiKey (quota : 1000/jour). */
if (!defined('DIGIKEY_SEARCH_LIMIT')) define('DIGIKEY_SEARCH_LIMIT', 10);

/* --- API TME (import de composants) ---
   Renseignez TME_TOKEN / TME_SECRET dans config.local.php (ou via Paramètres →
   Réglage API). Vides par défaut : TME n'est alors pas proposé comme source. */
if (!defined('TME_TOKEN'))  define('TME_TOKEN', '');
if (!defined('TME_SECRET')) define('TME_SECRET', '');

/* Pays et devise utilisés pour les prix TME. */
if (!defined('TME_COUNTRY'))  define('TME_COUNTRY', 'FR');
if (!defined('TME_CURRENCY')) define('TME_CURRENCY', 'EUR');

/* Nombre de résultats renvoyés par recherche TME. */
if (!defined('TME_SEARCH_LIMIT')) define('TME_SEARCH_LIMIT', 10);

/* --- API LCSC Electronics (import de composants) ---
   Catalogue public JLCPCB / LCSC (jlcpcb.com/parts) : aucune clé requise.
   LCSC est donc toujours proposé comme source de recherche. */
if (!defined('LCSC_SEARCH_LIMIT')) define('LCSC_SEARCH_LIMIT', 10);

/* --- API Datasheets.com (recherche de fiches techniques) ---
   Renseignez DATASHEETS_API_KEY dans config.local.php (ou via Paramètres ->
   Réglage API). Vide par défaut : la source n'est alors pas utilisée comme
   repli automatique pour compléter les datasheets manquantes. */
if (!defined('DATASHEETS_API_KEY'))      define('DATASHEETS_API_KEY', '');
if (!defined('DATASHEETS_SEARCH_LIMIT')) define('DATASHEETS_SEARCH_LIMIT', 5);

/* --- API RS Components / RS Online (import de composants) ---
   Renseignez RS_API_KEY (App ID fourni par RS Components) dans config.local.php
   (ou via Paramètres → Réglage API). Vide par défaut : RS n'est alors pas
   proposé comme source de recherche. */
if (!defined('RS_API_KEY'))      define('RS_API_KEY', '');

/* Magasin régional interrogé : fr.rs-online.com, uk.rs-online.com,
   us.rs-online.com… Il détermine le catalogue, les prix et la devise. */
if (!defined('RS_STORE_ID'))     define('RS_STORE_ID', 'fr.rs-online.com');

/* Nombre de résultats renvoyés par recherche RS. */
if (!defined('RS_SEARCH_LIMIT')) define('RS_SEARCH_LIMIT', 10);

/* --- Vérification (empêche accès direct navigateur à config.php) --- */
if (!defined('COMPOZIT_BOOTSTRAP') && php_sapi_name() !== 'cli') {
    if (count(get_included_files()) === 1) {
        http_response_code(403);
        die('Forbidden');
    }
}

function ensureLocationStorageTypeColumn($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM location LIKE 'storage_type'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        if (!$column) {
            $pdo->exec("ALTER TABLE location ADD COLUMN storage_type VARCHAR(30) NOT NULL DEFAULT 'casier' AFTER description");
        }

        $pdo->exec("UPDATE location SET storage_type = 'casier' WHERE storage_type IS NULL OR storage_type = ''");
    } catch (PDOException $e) {
    }
}

function ensureLocationLogoPathColumn($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM location LIKE 'logo_path'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        if (!$column) {
            $pdo->exec("ALTER TABLE location ADD COLUMN logo_path VARCHAR(500) DEFAULT NULL AFTER storage_type");
        }
    } catch (PDOException $e) {
    }
}

/**
 * Ajoute la colonne datasheet_file à la table data si elle n'existe pas.
 * Elle stocke le chemin d'un PDF de fiche technique importé localement,
 * en complément de la colonne datasheet qui contient l'URL.
 */
function ensureDataDatasheetFileColumn($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM data LIKE 'datasheet_file'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        if (!$column) {
            $pdo->exec("ALTER TABLE data ADD COLUMN datasheet_file VARCHAR(500) DEFAULT NULL AFTER datasheet");
        }
    } catch (PDOException $e) {
    }
}

/**
 * Ajoute la colonne marking à la table data si elle n'existe pas.
 * Stocke le code imprimé sur le boîtier CMS (ex. A7, B8, 65V…),
 * distinct du package (boîtier mécanique) et de la référence fabricant.
 */
function ensureDataMarkingColumn($pdo) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM data LIKE 'marking'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        if (!$column) {
            $pdo->exec("ALTER TABLE data ADD COLUMN marking VARCHAR(80) DEFAULT NULL AFTER smd");
        }
    } catch (PDOException $e) {
    }
}

function getLocationStorageTypes() {
    return [
        'casier' => ['label' => 'Casier', 'icon' => '🗄️'],
        'boite' => ['label' => 'Boite', 'icon' => '📦'],
        'classeur' => ['label' => 'Classeur', 'icon' => '🗂️'],
        'etagere' => ['label' => 'Etagere', 'icon' => '🪜'],
    ];
}

function initDatabase() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8", DB_USER, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $safeDbName = str_replace('`', '', DB_NAME);

        /* Hebergement mutualise (Hostinger, OVH, ...) : la base est creee
           depuis le panneau d'administration et l'utilisateur MySQL n'a PAS
           le droit CREATE DATABASE. L'echec est donc volontairement ignore :
           on se contente ensuite de selectionner la base existante. */
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}`");
        } catch (PDOException $e) {
        }
        $pdo->exec("USE `{$safeDbName}`");

    // Créer la table users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Créer la table location
    $pdo->exec("CREATE TABLE IF NOT EXISTS location (
        id INT AUTO_INCREMENT PRIMARY KEY,
        casier VARCHAR(50) NOT NULL,
        tiroir VARCHAR(50) NOT NULL,
        compartiment VARCHAR(50) NOT NULL,
        description TEXT,
        owner INT NOT NULL,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_location (casier, tiroir, compartiment, owner)
    )");

    // Créer la table data
    $pdo->exec("CREATE TABLE IF NOT EXISTS data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        category VARCHAR(100),
        subcategory VARCHAR(100),
        quantity INT DEFAULT 0,
        location_id INT,
        owner INT NOT NULL,
        image_path VARCHAR(500),
        price DECIMAL(10,2),
        supplier_id INT,
        package_id INT,
        manufacturer_id INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (location_id) REFERENCES location(id) ON DELETE SET NULL,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Créer la table category_head
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        owner INT NOT NULL,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Créer la table category_sub
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_sub (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category_head_id INT NOT NULL,
        description TEXT,
        owner INT NOT NULL,
        FOREIGN KEY (category_head_id) REFERENCES category_head(id) ON DELETE CASCADE,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_sub_category (name, category_head_id, owner)
    )");

    // Créer la table suppliers
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        website VARCHAR(500),
        notes TEXT,
        logo_path VARCHAR(500),
        owner INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Créer la table supplier_contacts
    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        contact_type ENUM('email', 'phone', 'address', 'other') NOT NULL,
        contact_value TEXT NOT NULL,
        contact_label VARCHAR(100),
        is_primary BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
    )");

    // Créer la table projects
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('planning', 'in_progress', 'completed', 'on_hold') DEFAULT 'planning',
        start_date DATE,
        end_date DATE,
        owner INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Créer la table project_components
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_components (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        component_id INT NOT NULL,
        quantity_needed INT NOT NULL DEFAULT 1,
        quantity_used INT DEFAULT 0,
        notes TEXT,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (component_id) REFERENCES data(id) ON DELETE CASCADE,
        UNIQUE KEY unique_project_component (project_id, component_id)
    )");

    // Créer la table packages
    $pdo->exec("CREATE TABLE IF NOT EXISTS packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        image_path VARCHAR(500),
        owner INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Créer la table manufacturers
    $pdo->exec("CREATE TABLE IF NOT EXISTS manufacturers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        website VARCHAR(500),
        logo_path VARCHAR(500),
        owner INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (owner) REFERENCES users(id) ON DELETE CASCADE
    )");
        
        return true;
    } catch(PDOException $e) {
        die("Erreur d'initialisation de la base de données : " . $e->getMessage());
    }
}

/**
 * Valide une URL externe fournie par l'utilisateur ou par une API
 * (datasheet, fiche produit, image).
 *
 * Sont refusés : les espaces et caractères de contrôle, les chaînes trop
 * longues, et tout schéma autre que http(s) (javascript:, data:, file:…).
 * Une URL protocol-relative (« //exemple.com/fichier.pdf ») est préfixée en
 * https. Renvoie l'URL nettoyée, ou une chaîne vide si elle est invalide.
 */
function sanitizeExternalUrl($url, int $maxLength = 1000): string {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (strlen($url) > $maxLength || preg_match('/[\x00-\x20\x7F]/', $url)) {
        return '';
    }

    // URL protocol-relative → https
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    // Tout schéma autre que http(s) est refusé (javascript:, data:, file:…)
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
        return '';
    }

    return $url;
}

// Fonction pour obtenir une connexion à la base de données
function getConnection() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensureLocationStorageTypeColumn($pdo);
        ensureLocationLogoPathColumn($pdo);
        ensureDataDatasheetFileColumn($pdo);
        ensureDataMarkingColumn($pdo);
        return $pdo;
    } catch(PDOException $e) {
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }
}

// Initialiser la base de données
initDatabase();
?>
