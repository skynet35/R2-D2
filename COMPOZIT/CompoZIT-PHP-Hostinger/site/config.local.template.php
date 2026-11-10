<?php
/* =========================================================================
   MODÈLE (Template) de configuration locale
   ---------------------------------------------------------------------------
   1. Copiez ce fichier et nommez le :   config.local.php
   2. Remplissez avec les vrais identifiants de VOTRE base MySQL
   3. N'éditez PAS config.php directement !
   ---------------------------------------------------------------------------
   config.local.php est automatiquement ignoré par Git (voir .gitignore).
   ========================================================================= */

/* --- Base MySQL --- */
define('DB_HOST',     'localhost');
define('DB_NAME',     'compozit');
define('DB_USER',     'root');
define('DB_PASSWORD', '');

/* --- API Nexar / Octopart (import de composants) ---
   Créez une application sur https://portal.nexar.com/ avec le scope "supply.domain",
   puis collez ici le Client ID et le Client Secret (onglet "Authorization" de l'app).
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API.
   Sans ces valeurs, le panneau de recherche Nexar est simplement masqué. */
// define('NEXAR_CLIENT_ID',     '');
// define('NEXAR_CLIENT_SECRET', '');
// define('NEXAR_SEARCH_LIMIT',  5);   // nombre de résultats par recherche (1 à 30)

/* --- API Mouser (import de composants) ---
   Créez une clé API "Search" sur https://www.mouser.com/ :
   My Mouser → My Account → section "APIs" → Manage. La clé peut rester en
   "pending authorization" quelques heures avant d'être active.
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API. */
// define('MOUSER_API_KEY',      '');
// define('MOUSER_SEARCH_LIMIT', 10);  // nombre de résultats par recherche (1 à 50)

/* --- API Farnell / element14 / Newark (import de composants) ---
   Créez une clé API sur https://partner.element14.com/ :
   My API Keys → Get API Keys (type "Product Search API", tier "Basic").
   La clé est une chaîne alphanumérique de 24 caractères.
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API. */
// define('FARNELL_API_KEY',      '');
// define('FARNELL_STORE_ID',     'fr.farnell.com');  // uk.farnell.com, www.newark.com, au.element14.com…
// define('FARNELL_SEARCH_LIMIT', 10);  // nombre de résultats par recherche (1 à 50)

/* --- API DigiKey (import de composants) ---
   Créez une application OAuth2 sur https://developer.digikey.com/ → My Apps :
   récupérez le Client ID et le Client Secret (flux "client_credentials").
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API. */
// define('DIGIKEY_CLIENT_ID',     '');
// define('DIGIKEY_CLIENT_SECRET', '');
// define('DIGIKEY_SEARCH_LIMIT',  10);  // nombre de résultats par recherche (1 à 50)

/* --- API TME (import de composants) ---
   Créez une application sur https://developers.tme.eu/ : le "token" fait
   50 caractères, le "secret" 20 caractères (OAuth2 client_credentials).
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API. */
// define('TME_TOKEN',        '');  // 50 caractères
// define('TME_SECRET',       '');  // 20 caractères
// define('TME_SEARCH_LIMIT', 10);  // nombre de résultats par recherche (1 à 100)

/* --- API LCSC Electronics (import de composants) ---
   Catalogue public JLCPCB / LCSC (jlcpcb.com/parts) : aucune clé requise.
   LCSC est donc toujours proposé comme source de recherche. */
// define('LCSC_SEARCH_LIMIT', 10);  // nombre de résultats par recherche (1 à 100)

/* --- API Datasheets.com (recherche de fiches techniques) ---
   Générez une clé sur https://www.datasheets.com/account/api puis collez-la
   ici. Cette source sert de repli automatique pour compléter la datasheet
   d'un composant si la source d'origine n'en fournit pas. */
// define('DATASHEETS_API_KEY',      '');
// define('DATASHEETS_SEARCH_LIMIT', 5);   // nombre de résultats inspectés (1 à 10)

/* --- API RS Components / RS Online (import de composants) ---
   L'accès à l'API RS s'obtient auprès de votre commercial RS Components :
   demandez un « App ID » dédié à votre compte. RS ne publie pas sa
   documentation d'API publiquement ; l'URL d'appel se règle dans rs_api.php.
   Ces valeurs sont également modifiables depuis Paramètres → Réglage API. */
// define('RS_API_KEY',      '');  // App ID fourni par RS Components
// define('RS_STORE_ID',     'fr.rs-online.com');  // uk.rs-online.com, us.rs-online.com…
// define('RS_SEARCH_LIMIT', 10);  // nombre de résultats par recherche (1 à 50)

/* --- Environnement --- */
// define('COMPOZIT_ENV',   'development');  // 'development' | 'staging' | 'production'
// define('COMPOZIT_DEBUG', true);
