<?php
/* =========================================================================
   IDENTIFIANTS MySQL - HEBERGEMENT HOSTINGER
   ---------------------------------------------------------------------------
   Fichier genere pour le deploiement en ligne de CompoZ'IT (version PHP).
   Il contient des secrets : il est protege contre le telechargement par le
   .htaccess du dossier. Ne le supprime pas, ne le renomme pas.
   ---------------------------------------------------------------------------
   NE PAS MODIFIER config.php : toutes les valeurs personnalisables sont ici.
   ========================================================================= */

/* --- Base MySQL Hostinger ---
   L'application et la base sont sur le meme serveur : l'hote est "localhost"
   (le plus rapide, et c'est l'adresse fournie par hPanel).
   Toutes ces valeurs se retrouvent dans hPanel -> Bases de donnees -> MySQL. */
define('DB_HOST',     'localhost');
define('DB_NAME',     'uxxxxxx_compozit');
define('DB_USER',     'uxxxxxxx_mr-j');
define('DB_PASSWORD', 'password');

/* --- API Nexar / Octopart (import de composants) ---
   Modifiables aussi depuis Parametres -> Reglage API. */
define('NEXAR_CLIENT_ID',     'API');
define('NEXAR_CLIENT_SECRET', 'API');
define('NEXAR_SEARCH_LIMIT',  5);

/* --- API Mouser (import de composants) --- */
define('MOUSER_API_KEY',      '');
define('MOUSER_SEARCH_LIMIT', 10);

/* --- API Farnell / element14 / Newark (import de composants) --- */
define('FARNELL_API_KEY',      '');
define('FARNELL_STORE_ID',     'fr.farnell.com');
define('FARNELL_SEARCH_LIMIT', 10);

/* --- API DigiKey (import de composants) ---
   Non renseignee : DigiKey n'apparait pas comme source de recherche. */
define('DIGIKEY_CLIENT_ID',     '');
define('DIGIKEY_CLIENT_SECRET', '');
define('DIGIKEY_SEARCH_LIMIT',  10);

/* --- API TME (import de composants) --- */
define('TME_TOKEN',        '');
define('TME_SECRET',       '');
define('TME_SEARCH_LIMIT', 10);

/* --- API LCSC Electronics (import de composants) ---
   Catalogue public JLCPCB / LCSC : aucune cle requise. */
define('LCSC_SEARCH_LIMIT', 10);

/* --- API RS Components (import de composants) ---
   Non renseignee : RS n'apparait pas comme source de recherche. */
// define('RS_API_KEY',      '');
// define('RS_STORE_ID',     'fr.rs-online.com');
// define('RS_SEARCH_LIMIT', 10);
