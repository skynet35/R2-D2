<?php
/* =========================================================================
   Réglage API — paramètres de connexion aux API fournisseurs
   ---------------------------------------------------------------------------
   Nexar / Octopart, Mouser, Farnell / element14, DigiKey, TME, LCSC et RS.
   Permet de définir les identifiants et le nombre de résultats par recherche.
   Les valeurs sont écrites dans config.local.php (fichier local non versionné),
   ce qui évite toute migration de base de données.
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'nexar_api.php';
require_once 'mouser_api.php';
require_once 'farnell_api.php';
require_once 'digikey_api.php';
require_once 'tme_api.php';
require_once 'lcsc_api.php';
require_once 'datasheets_api.php';
require_once 'rs_api.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

/**
 * Met à jour les constantes demandées dans config.local.php en préservant tout
 * le reste du fichier (une sauvegarde .bak est créée avant écriture).
 *
 * @param  array<string, string|int> $constants nom => valeur
 * @return array{ok:bool, message?:string}
 */
function updateLocalConfigConstants(array $constants): array {
    $path = __DIR__ . '/config.local.php';

    $original = @file_get_contents($path);
    if ($original === false) {
        return ['ok' => false, 'message' => 'Impossible de lire config.local.php. Vérifiez les droits du fichier.'];
    }

    if (strpos($original, '<?php') !== 0) {
        return ['ok' => false, 'message' => "config.local.php n'a pas le format attendu : écriture annulée par précaution."];
    }

    $content = $original;

    foreach ($constants as $name => $value) {
        // var_export() produit un littéral PHP correctement échappé (\\ et \').
        $literal = is_int($value) ? (string) $value : var_export((string) $value, true);

        $line    = "define('" . $name . "', " . $literal . ");";
        $pattern = "/^[ \t]*define\(\s*'" . preg_quote($name, '/') . "'\s*,[^\n]*$/m";

        if (preg_match($pattern, $content)) {
            // Callback obligatoire : dans preg_replace(), les antislashs du
            // remplacement seraient interprétés comme des échappements.
            $content = (string) preg_replace_callback($pattern, function () use ($line) {
                return $line;
            }, $content, 1);
        } else {
            $content = rtrim($content) . "\n" . $line . "\n";
        }
    }

    if ($content === $original) {
        return ['ok' => true];
    }

    // Garde-fou : on refuse d'écraser le fichier si les identifiants MySQL ont disparu.
    if (strpos($content, 'DB_NAME') === false) {
        return ['ok' => false, 'message' => 'Écriture annulée : le fichier généré serait incomplet.'];
    }

    if (@file_put_contents($path . '.bak', $original, LOCK_EX) === false) {
        return ['ok' => false, 'message' => 'Impossible de créer la sauvegarde config.local.php.bak.'];
    }

    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        return ['ok' => false, 'message' => "Impossible d'écrire dans config.local.php. Vérifiez les droits du fichier."];
    }

    return ['ok' => true];
}

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requested = (string) ($_POST['section'] ?? 'nexar');
    $section = in_array($requested, ['nexar', 'mouser', 'farnell', 'digikey', 'tme', 'lcsc', 'datasheets', 'rs'], true) ? $requested : 'nexar';

    if ($section === 'mouser') {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $apiKey = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['mouser_api_key'] ?? '')));

        $mouserLimit = (int) ($_POST['mouser_search_limit'] ?? 10);
        $mouserLimit = max(1, min(50, $mouserLimit));

        $write = updateLocalConfigConstants([
            'MOUSER_API_KEY'      => $apiKey,
            'MOUSER_SEARCH_LIMIT' => $mouserLimit,
        ]);

        if (!$write['ok']) {
            $error = $write['message'] ?? 'Échec de l\'enregistrement.';
        } else {
            header('Location: api_settings.php?success=mouser');
            exit();
        }
    } elseif ($section === 'farnell') {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $farnellKey   = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['farnell_api_key'] ?? '')));
        $farnellStore = (string) preg_replace('/[^a-z0-9.\-]/i', '', trim((string) ($_POST['farnell_store_id'] ?? '')));

        if ($farnellStore === '') {
            $farnellStore = 'fr.farnell.com';
        }

        $farnellLimit = (int) ($_POST['farnell_search_limit'] ?? 10);
        $farnellLimit = max(1, min(50, $farnellLimit));

        $write = updateLocalConfigConstants([
            'FARNELL_API_KEY'      => $farnellKey,
            'FARNELL_STORE_ID'     => $farnellStore,
            'FARNELL_SEARCH_LIMIT' => $farnellLimit,
        ]);

        if (!$write['ok']) {
            $error = $write['message'] ?? 'Échec de l\'enregistrement.';
        } else {
            header('Location: api_settings.php?success=farnell');
            exit();
        }
    } elseif ($section === 'digikey') {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $clientId     = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['digikey_client_id'] ?? '')));
        $clientSecret = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['digikey_client_secret'] ?? '')));

        $digikeyLimit = (int) ($_POST['digikey_search_limit'] ?? 10);
        $digikeyLimit = max(1, min(50, $digikeyLimit));

        if (($clientId === '') !== ($clientSecret === '')) {
            $error = 'Renseignez le Client ID ET le Client Secret DigiKey (ou laissez les deux vides pour désactiver la source).';
        } else {
            $write = updateLocalConfigConstants([
                'DIGIKEY_CLIENT_ID'     => $clientId,
                'DIGIKEY_CLIENT_SECRET' => $clientSecret,
                'DIGIKEY_SEARCH_LIMIT'  => $digikeyLimit,
            ]);

            if (!$write['ok']) {
                $error = $write['message'] ?? 'Échec de l\'enregistrement.';
            } else {
                // Les identifiants ont peut-être changé : le jeton en cache est obsolète.
                @unlink(digikeyTokenCachePath());

                header('Location: api_settings.php?success=digikey');
                exit();
            }
        }
    } elseif ($section === 'tme') {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $token  = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['tme_token'] ?? '')));
        $secret = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['tme_secret'] ?? '')));

        $tmeLimit = (int) ($_POST['tme_search_limit'] ?? 10);
        $tmeLimit = max(1, min(100, $tmeLimit));

        if (($token === '') !== ($secret === '')) {
            $error = 'Renseignez le token ET le secret TME (ou laissez les deux vides pour désactiver la source).';
        } else {
            $write = updateLocalConfigConstants([
                'TME_TOKEN'        => $token,
                'TME_SECRET'       => $secret,
                'TME_SEARCH_LIMIT' => $tmeLimit,
            ]);

            if (!$write['ok']) {
                $error = $write['message'] ?? 'Échec de l\'enregistrement.';
            } else {
                // Les identifiants ont peut-être changé : le jeton en cache est obsolète.
                @unlink(tmeTokenCachePath());

                header('Location: api_settings.php?success=tme');
                exit();
            }
        }
    } elseif ($section === 'lcsc') {
        $lcscLimit = (int) ($_POST['lcsc_search_limit'] ?? 10);
        $lcscLimit = max(1, min(100, $lcscLimit));

        $write = updateLocalConfigConstants([
            'LCSC_SEARCH_LIMIT' => $lcscLimit,
        ]);

        if (!$write['ok']) {
            $error = $write['message'] ?? 'Échec de l\'enregistrement.';
        } else {
            header('Location: api_settings.php?success=lcsc');
            exit();
        }
    } elseif ($section === 'datasheets') {
        $apiKey = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['datasheets_api_key'] ?? '')));

        $datasheetsLimit = (int) ($_POST['datasheets_search_limit'] ?? 5);
        $datasheetsLimit = max(1, min(10, $datasheetsLimit));

        $write = updateLocalConfigConstants([
            'DATASHEETS_API_KEY'      => $apiKey,
            'DATASHEETS_SEARCH_LIMIT' => $datasheetsLimit,
        ]);

        if (!$write['ok']) {
            $error = $write['message'] ?? 'Échec de l\'enregistrement.';
        } else {
            header('Location: api_settings.php?success=datasheets');
            exit();
        }
    } elseif ($section === 'rs') {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $rsKey   = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['rs_api_key'] ?? '')));
        $rsStore = (string) preg_replace('/[^a-z0-9.\-]/i', '', trim((string) ($_POST['rs_store_id'] ?? '')));

        if ($rsStore === '') {
            $rsStore = 'fr.rs-online.com';
        }

        $rsLimit = (int) ($_POST['rs_search_limit'] ?? 10);
        $rsLimit = max(1, min(50, $rsLimit));

        $write = updateLocalConfigConstants([
            'RS_API_KEY'      => $rsKey,
            'RS_STORE_ID'     => $rsStore,
            'RS_SEARCH_LIMIT' => $rsLimit,
        ]);

        if (!$write['ok']) {
            $error = $write['message'] ?? 'Échec de l\'enregistrement.';
        } else {
            header('Location: api_settings.php?success=rs');
            exit();
        }
    } else {
        // Les retours à la ligne d'un copier-coller casseraient le fichier de config.
        $clientId     = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['nexar_client_id'] ?? '')));
        $clientSecret = (string) preg_replace('/[\r\n]+/', '', trim((string) ($_POST['nexar_client_secret'] ?? '')));

        $searchLimit = (int) ($_POST['nexar_search_limit'] ?? 5);
        $searchLimit = max(1, min(30, $searchLimit));

        if (($clientId === '') !== ($clientSecret === '')) {
            $error = 'Renseignez le Client ID ET le Client Secret (ou laissez les deux vides pour désactiver le panneau Nexar).';
        } else {
            $write = updateLocalConfigConstants([
                'NEXAR_CLIENT_ID'     => $clientId,
                'NEXAR_CLIENT_SECRET' => $clientSecret,
                'NEXAR_SEARCH_LIMIT'  => $searchLimit,
            ]);

            if (!$write['ok']) {
                $error = $write['message'] ?? 'Échec de l\'enregistrement.';
            } else {
                // Les identifiants ont peut-être changé : le jeton en cache est obsolète.
                @unlink(nexarTokenCachePath());

                header('Location: api_settings.php?success=nexar');
                exit();
            }
        }
    }
}

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'mouser':
            $success = 'Réglage API Mouser enregistré. Le nouveau paramètre est actif immédiatement.';
            break;
        case 'farnell':
            $success = 'Réglage API Farnell enregistré. Les nouveaux paramètres sont actifs immédiatement.';
            break;
        case 'digikey':
            $success = 'Réglage API DigiKey enregistré. Les nouveaux paramètres sont actifs immédiatement.';
            break;
        case 'tme':
            $success = 'Réglage API TME enregistré. Les nouveaux paramètres sont actifs immédiatement.';
            break;
        case 'lcsc':
            $success = 'Réglage API LCSC Electronics enregistré. Le nouveau paramètre est actif immédiatement.';
            break;
        case 'datasheets':
            $success = 'Réglage API Datasheets.com enregistré. Le nouveau paramètre est actif immédiatement.';
            break;
        case 'rs':
            $success = 'Réglage API RS Components enregistré. Les nouveaux paramètres sont actifs immédiatement.';
            break;
        default:
            $success = 'Réglage API Nexar enregistré. Les nouveaux paramètres sont actifs immédiatement.';
    }
}

$currentClientId     = (string) NEXAR_CLIENT_ID;
$currentClientSecret = (string) NEXAR_CLIENT_SECRET;
$currentLimit        = (int) NEXAR_SEARCH_LIMIT;
$isConfigured        = nexarIsConfigured();

$currentMouserApiKey = (string) MOUSER_API_KEY;
$currentMouserLimit  = (int) MOUSER_SEARCH_LIMIT;
$isMouserConfigured  = mouserIsConfigured();

$currentFarnellApiKey = (string) FARNELL_API_KEY;
$currentFarnellStore  = (string) FARNELL_STORE_ID;
$currentFarnellLimit  = (int) FARNELL_SEARCH_LIMIT;
$isFarnellConfigured  = farnellIsConfigured();

$currentDigikeyClientId     = (string) DIGIKEY_CLIENT_ID;
$currentDigikeyClientSecret = (string) DIGIKEY_CLIENT_SECRET;
$currentDigikeyLimit        = (int) DIGIKEY_SEARCH_LIMIT;
$isDigikeyConfigured        = digikeyIsConfigured();

$currentTmeToken  = (string) TME_TOKEN;
$currentTmeSecret = (string) TME_SECRET;
$currentTmeLimit  = (int) TME_SEARCH_LIMIT;
$isTmeConfigured  = tmeIsConfigured();

$currentLcscLimit = (int) LCSC_SEARCH_LIMIT;

$currentDatasheetsApiKey = (string) DATASHEETS_API_KEY;
$currentDatasheetsLimit  = (int) DATASHEETS_SEARCH_LIMIT;
$isDatasheetsConfigured  = datasheetsIsConfigured();

$currentRsApiKey = (string) RS_API_KEY;
$currentRsStore  = (string) RS_STORE_ID;
$currentRsLimit  = (int) RS_SEARCH_LIMIT;
$isRsConfigured  = rsIsConfigured();

$configWritable      = is_writable(__DIR__ . '/config.local.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Réglage API - Gestion des Composants</title>
    <style>
        :root {
            --bg-card: #ffffff;
            --bg-muted: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --accent-indigo: #6366f1;
            --accent-purple: #8b5cf6;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-teal: #14b8a6;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--radius-xl);
            padding: 28px 32px 32px;
            margin-bottom: 24px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.2);
            position: relative;
            overflow: hidden;
        }
        .app-header::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.15), transparent 70%);
            border-radius: 50%;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 2;
            margin-bottom: 20px;
        }
        .header-title { display: flex; align-items: center; gap: 14px; }
        .header-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
        .header-title p { font-size: 13px; opacity: 0.85; margin-top: 3px; }
        .user-chip {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15);
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2);
            font-size: 13px;
        }
        .logout-link {
            color: white; text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 500;
            transition: background 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px; position: relative; z-index: 2; }
        .nav-buttons a {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .nav-buttons a:hover { background: rgba(255,255,255,0.28); transform: translateY(-1px); }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-indigo { background: var(--accent-indigo); color: white; }
        .btn-ghost  { background: var(--bg-muted); color: var(--text-secondary); }
        .btn-ghost:hover { background: var(--border-color); }
        .btn-sm     { padding: 8px 12px; font-size: 12px; }

        .content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 30px;
        }
        .page-title { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
        .page-subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: 22px; }

        .alert {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
        }
        .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        .status-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: var(--bg-muted);
            border: 1px solid var(--border-color);
            font-size: 14px;
            font-weight: 600;
            cursor: default;
        }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
        .status-dot.on  { background: var(--accent-green); box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
        .status-dot.off { background: var(--accent-red); box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            margin-bottom: 24px;
        }
        .card h2 { font-size: 17px; font-weight: 700; margin-bottom: 18px; }

        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        .field .hint { font-size: 12px; color: var(--text-secondary); margin-top: 6px; line-height: 1.5; }
        .input-row { display: flex; gap: 8px; align-items: center; }
        input[type="text"], input[type="password"], input[type="number"] {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--bg-card);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        input[type="text"]:focus, input[type="password"]:focus, input[type="number"]:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .input-number { max-width: 120px; }
        .form-actions { display: flex; gap: 12px; align-items: center; margin-top: 6px; }

        .tuto { line-height: 1.7; font-size: 14px; color: var(--text-primary); }
        .tuto ol { margin: 0 0 18px 20px; }
        .tuto ol li { margin-bottom: 12px; }
        .tuto code {
            background: var(--bg-muted);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
            font-family: 'Consolas', 'Courier New', monospace;
        }
        .tuto a { color: var(--accent-indigo); font-weight: 600; }
        .tuto .note {
            background: var(--bg-primary);
            border-left: 3px solid var(--accent-teal);
            padding: 12px 16px;
            border-radius: 0 8px 8px 0;
            margin-top: 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .tuto .note strong { color: var(--text-primary); }

        /* Tutoriels repliables, rattachés à la fiche de chaque fournisseur */
        .tuto-accordion {
            margin-top: 22px;
            border-top: 1px dashed var(--border-color);
            padding-top: 4px;
        }
        .tuto-accordion > summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 0 2px;
            font-size: 14px;
            font-weight: 700;
            color: var(--accent-indigo);
            user-select: none;
        }
        .tuto-accordion > summary::-webkit-details-marker { display: none; }
        .tuto-accordion > summary::after {
            content: '▾';
            margin-left: auto;
            font-size: 13px;
            color: var(--text-secondary);
            transition: transform 0.2s ease;
        }
        .tuto-accordion[open] > summary::after { transform: rotate(180deg); }
        .tuto-accordion > summary:hover { color: var(--accent-purple); }
        .tuto-body { padding-top: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🔌</div>
                    <div>
                        <h1>Réglage API</h1>
                        <p>Connexion aux API Nexar / Octopart, Mouser, Farnell / element14, DigiKey, TME, LCSC et Datasheets.com</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="settings.php">⚙️ Paramètres</a>
                <a href="components.php">📦 Composants</a>
                <a href="create_component.php">➕ Créer</a>
            </div>
        </div>

        <div class="content">
            <div class="page-title">🔌 Paramètres des API fournisseurs</div>
            <div class="page-subtitle">Ces réglages permettent d'importer un composant depuis sa référence (MPN, référence distributeur) ou un mot-clé dans la page « Créer un composant ». Ils servent aussi au repli automatique des datasheets manquantes.</div>

            <?php if ($error !== null): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== null): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!$configWritable): ?>
                <div class="alert alert-warning">⚠️ Le fichier <code>config.local.php</code> n'est pas accessible en écriture : l'enregistrement échouera. Corrigez les droits du fichier.</div>
            <?php endif; ?>

            <div class="status-badges">
                <span class="status-chip" title="<?php echo $isConfigured ? 'Configurée : proposée sur la page « Créer un composant ».' : 'Non configurée : masquée tant que les identifiants sont vides.'; ?>">
                    <span class="status-dot <?php echo $isConfigured ? 'on' : 'off'; ?>"></span>Nexar / Octopart
                </span>

                <span class="status-chip" title="<?php echo $isMouserConfigured ? 'Configurée : proposée sur la page « Créer un composant ».' : 'Non configurée : masquée tant que la clé API est vide.'; ?>">
                    <span class="status-dot <?php echo $isMouserConfigured ? 'on' : 'off'; ?>"></span>Mouser
                </span>

                <span class="status-chip" title="<?php echo $isFarnellConfigured ? 'Configurée : proposée sur la page « Créer un composant » (magasin ' . htmlspecialchars($currentFarnellStore) . ').' : 'Non configurée : masquée tant que la clé API est vide.'; ?>">
                    <span class="status-dot <?php echo $isFarnellConfigured ? 'on' : 'off'; ?>"></span>Farnell / element14
                </span>

                <span class="status-chip" title="<?php echo $isDigikeyConfigured ? 'Configurée : proposée sur la page « Créer un composant ».' : 'Non configurée : masquée tant que les identifiants OAuth2 sont vides.'; ?>">
                    <span class="status-dot <?php echo $isDigikeyConfigured ? 'on' : 'off'; ?>"></span>DigiKey
                </span>

                <span class="status-chip" title="<?php echo $isTmeConfigured ? 'Configurée : proposée sur la page « Créer un composant ».' : 'Non configurée : masquée tant que le token et le secret sont vides.'; ?>">
                    <span class="status-dot <?php echo $isTmeConfigured ? 'on' : 'off'; ?>"></span>TME
                </span>

                <span class="status-chip" title="Disponible sans clé (catalogue public JLCPCB / LCSC) : toujours proposée sur la page « Créer un composant ».">
                    <span class="status-dot on"></span>LCSC Electronics
                </span>

                <span class="status-chip" title="<?php echo $isDatasheetsConfigured ? 'Configurée : utilisée comme source de repli pour compléter les datasheets manquantes.' : 'Non configurée : non utilisée tant que la clé API est vide.'; ?>">
                    <span class="status-dot <?php echo $isDatasheetsConfigured ? 'on' : 'off'; ?>"></span>Datasheets.com
                </span>

                <span class="status-chip" title="<?php echo $isRsConfigured ? 'Configurée : proposée sur la page « Créer un composant » (magasin ' . htmlspecialchars($currentRsStore) . ').' : 'Non configurée : masquée tant que la clé API (App ID) est vide.'; ?>">
                    <span class="status-dot <?php echo $isRsConfigured ? 'on' : 'off'; ?>"></span>RS Components
                </span>
            </div>

            <!-- Formulaire de réglage — Nexar / Octopart -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="nexar">
                <h2>🔑 Nexar / Octopart — identifiants et recherche</h2>

                <div class="field">
                    <label for="nexar_client_id">Client ID</label>
                    <input type="text" id="nexar_client_id" name="nexar_client_id"
                           value="<?php echo htmlspecialchars($currentClientId); ?>"
                           placeholder="ex : 07e44e91-9160-4b97-9fcd-9db3324f5c26">
                    <div class="hint">Identifiant public de votre application Nexar (onglet « Authorization »).</div>
                </div>

                <div class="field">
                    <label for="nexar_client_secret">Client Secret</label>
                    <div class="input-row">
                        <input type="password" id="nexar_client_secret" name="nexar_client_secret"
                               value="<?php echo htmlspecialchars($currentClientSecret); ?>"
                               placeholder="ex : KDgBo2UDKuP7NrO4c2csfUpv22XliqVa7o9U">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleSecret()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">Clé secrète associée. Stockée dans <code>config.local.php</code>, un fichier local exclu du dépôt Git.</div>
                </div>

                <div class="field">
                    <label for="nexar_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="nexar_search_limit" name="nexar_search_limit"
                           class="input-number" min="1" max="30" step="1"
                           value="<?php echo (int) $currentLimit; ?>">
                    <div class="hint">
                        Entre 1 et 30. Chaque résultat renvoyé décompte une part de votre quota API :
                        une valeur élevée améliore les chances de trouver un composant, mais épuise le quota plus vite.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-nexar">
                    <summary>📘 Tutoriel — Créer un compte Nexar et récupérer les identifiants</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte Nexar</strong> — rendez-vous sur
                                <a href="https://portal.nexar.com/" target="_blank" rel="noopener">portal.nexar.com</a>
                                et inscrivez-vous (gratuit). Validez le lien reçu par e-mail.
                            </li>
                            <li>
                                <strong>Créer une application</strong> — dans le portail, ouvrez la section
                                <code>Apps</code> puis créez une nouvelle application (nom libre, par exemple
                                <code>Compoz-it</code>).
                            </li>
                            <li>
                                <strong>Activer le scope <code>supply.domain</code></strong> — dans les réglages de
                                l'application, cochez le scope <code>supply.domain</code>. Sans ce scope, la recherche
                                de composants renverra une erreur d'autorisation.
                            </li>
                            <li>
                                <strong>Copier les identifiants</strong> — ouvrez l'onglet <code>Authorization</code>
                                de l'application : vous y trouverez le <strong>Client ID</strong> et le
                                <strong>Client Secret</strong>. Copiez-les tous les deux.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez-les dans le formulaire ci-dessus,
                                choisissez le nombre de résultats souhaité, puis cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a> :
                                le panneau « 🔎 Importer via une API fournisseur » doit apparaître avec Nexar dans le
                                sélecteur de source. Testez par exemple <code>LM358</code> ou une référence distributeur
                                comme <code>489-0043</code>.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>À propos du quota :</strong> le plan d'évaluation gratuit de Nexar est limité
                            (100 parts constatées sur cette application) et se renouvelle périodiquement. Chaque
                            résultat renvoyé par une recherche est décompté, y compris les résultats non utilisés.
                            Si vous recevez le message « You have exceeded your part limit », réduisez le nombre de
                            résultats par recherche ou utilisez un plan supérieur.
                        </div>

                        <div class="note">
                            <strong>Sécurité :</strong> le Client Secret est un mot de passe. Ne le partagez pas et
                            ne le committez jamais dans Git — il est enregistré dans <code>config.local.php</code>,
                            qui est exclu du dépôt.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — Mouser -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="mouser">
                <h2>🔑 Mouser — clé API et recherche</h2>

                <div class="field">
                    <label for="mouser_api_key">Clé API Mouser (Search API Key)</label>
                    <div class="input-row">
                        <input type="password" id="mouser_api_key" name="mouser_api_key"
                               value="<?php echo htmlspecialchars($currentMouserApiKey); ?>"
                               placeholder="ex : 1a2b3c4d-5e6f-7890-abcd-ef1234567890">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleMouserKey()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        Clé unique envoyée à chaque requête (pas de Client Secret). Laissez vide pour masquer
                        Mouser dans le sélecteur de source. Stockée dans <code>config.local.php</code>, un fichier
                        local exclu du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="mouser_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="mouser_search_limit" name="mouser_search_limit"
                           class="input-number" min="1" max="50" step="1"
                           value="<?php echo (int) $currentMouserLimit; ?>">
                    <div class="hint">
                        Entre 1 et 50. Mouser ne facture pas de quota « par résultat » comme Nexar, mais limite
                        le débit à environ 30 requêtes par minute sur l'offre gratuite.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-mouser">
                    <summary>📘 Tutoriel — Créer une clé API Mouser</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte Mouser</strong> — rendez-vous sur
                                <a href="https://www.mouser.com/" target="_blank" rel="noopener">mouser.com</a>
                                et créez un compte (gratuit).
                            </li>
                            <li>
                                <strong>Ouvrir la section APIs</strong> — dans le menu
                                <code>My Mouser</code> → <code>My Account</code>, cherchez la section
                                <code>APIs</code> puis cliquez sur <code>Manage</code>.
                            </li>
                            <li>
                                <strong>Demander une clé</strong> — créez une clé de type
                                <strong>Search API</strong> (nom libre, par exemple <code>Compoz-it</code>).
                            </li>
                            <li>
                                <strong>Attendre l'autorisation</strong> — une clé Search API passe par une validation
                                manuelle et peut rester quelques heures en statut « pending authorization ».
                            </li>
                            <li>
                                <strong>Copier la clé</strong> — une fois active, copiez la valeur de la clé
                                (une suite de caractères de type UUID).
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez-la dans le formulaire ci-dessus,
                                choisissez le nombre de résultats souhaité, puis cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a>,
                                sélectionnez « Mouser » dans le sélecteur de source et testez une référence comme
                                <code>GRM155R71C104KA88D</code>.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>À propos de la clé :</strong> Mouser utilise une clé unique transmise dans l'URL de
                            chaque requête, sans OAuth. Ne la partagez pas. Si Mouser renvoie
                            « Invalid Authorization », la clé est probablement encore en attente d'autorisation.
                        </div>

                        <div class="note">
                            <strong>Différence avec Nexar :</strong> Mouser limite le nombre d'appels (environ 30 par
                            minute) plutôt que le nombre de résultats. Rechercher par référence exacte donne les
                            meilleurs résultats ; à défaut, l'application bascule automatiquement sur une recherche
                            par mot-clé.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — Farnell / element14 -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="farnell">
                <h2>🔑 Farnell / element14 — clé API et recherche</h2>

                <div class="field">
                    <label for="farnell_api_key">Clé API Farnell (API Key)</label>
                    <div class="input-row">
                        <input type="password" id="farnell_api_key" name="farnell_api_key"
                               value="<?php echo htmlspecialchars($currentFarnellApiKey); ?>"
                               placeholder="ex : 5r4cytded7uedy4vtg7nyym7">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleFarnellKey()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        Chaîne alphanumérique de 24 caractères (pas de Client Secret). Laissez vide pour
                        masquer Farnell dans le sélecteur de source. Stockée dans <code>config.local.php</code>,
                        un fichier local exclu du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="farnell_store_id">Magasin régional</label>
                    <input type="text" id="farnell_store_id" name="farnell_store_id"
                           value="<?php echo htmlspecialchars($currentFarnellStore); ?>"
                           placeholder="fr.farnell.com">
                    <div class="hint">
                        Détermine le catalogue, les prix et la devise.
                        Exemples : <code>fr.farnell.com</code> (France, EUR), <code>uk.farnell.com</code>
                        (Royaume-Uni, GBP), <code>de.farnell.com</code> (EUR),
                        <code>www.newark.com</code> (États-Unis, USD),
                        <code>au.element14.com</code> (Asie-Pacifique).
                    </div>
                </div>

                <div class="field">
                    <label for="farnell_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="farnell_search_limit" name="farnell_search_limit"
                           class="input-number" min="1" max="50" step="1"
                           value="<?php echo (int) $currentFarnellLimit; ?>">
                    <div class="hint">
                        Entre 1 et 50. L'offre gratuite autorise environ 2 appels par seconde et
                        1 000 appels par jour.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-farnell">
                    <summary>📘 Tutoriel — Créer une clé API Farnell / element14</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte</strong> — rendez-vous sur
                                <a href="https://partner.element14.com/member/register" target="_blank" rel="noopener">partner.element14.com</a>
                                et inscrivez-vous (gratuit, sans carte bancaire).
                            </li>
                            <li>
                                <strong>Ouvrir « My API Keys »</strong> — une fois connecté, ouvrez la page
                                <a href="https://partner.element14.com/apps/mykeys" target="_blank" rel="noopener">My API Keys</a>
                                puis cliquez sur <code>Get API Keys</code>.
                            </li>
                            <li>
                                <strong>Décrire l'application</strong> — renseignez un nom libre (par exemple
                                <code>Compozit</code>), le type <code>Desktop application</code>, « 1-10 » utilisateurs,
                                non commerciale et sans publicité.
                            </li>
                            <li>
                                <strong>Demander la clé</strong> — cochez « Issue a new key for Product Search API »,
                                choisissez le tier <code>Basic</code>, acceptez les conditions puis validez.
                            </li>
                            <li>
                                <strong>Copier la clé</strong> — elle s'affiche sur la page <code>My API Keys</code> :
                                24 caractères alphanumériques. Vérifiez que son statut est <code>active</code>.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez-la dans le formulaire ci-dessus,
                                indiquez votre magasin régional (par défaut <code>fr.farnell.com</code>), puis
                                cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a>,
                                sélectionnez « Farnell / element14 » dans le sélecteur de source et testez une référence
                                comme <code>LM358DR2G</code> ou un code Farnell comme <code>2102546</code>.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>Une seule clé pour trois enseignes :</strong> la même API couvre Farnell (Europe),
                            Newark (Amérique du Nord) et element14 (Asie-Pacifique). Seul le magasin régional change :
                            le catalogue est identique, seuls les prix, le stock et la devise diffèrent.
                        </div>

                        <div class="note">
                            <strong>Sécurité :</strong> la clé API est un secret transmis dans l'URL de chaque requête.
                            Ne la partagez pas et ne la committez jamais dans Git — elle est enregistrée dans
                            <code>config.local.php</code>, qui est exclu du dépôt.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — DigiKey -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="digikey">
                <h2>🔑 DigiKey — identifiants OAuth2 et recherche</h2>

                <div class="field">
                    <label for="digikey_client_id">Client ID</label>
                    <input type="text" id="digikey_client_id" name="digikey_client_id"
                           value="<?php echo htmlspecialchars($currentDigikeyClientId); ?>"
                           placeholder="ex : 1a2b3c4d5e6f7g8h9i0j">
                    <div class="hint">Identifiant de votre application DigiKey (My Apps → OAuth 2.0).</div>
                </div>

                <div class="field">
                    <label for="digikey_client_secret">Client Secret</label>
                    <div class="input-row">
                        <input type="password" id="digikey_client_secret" name="digikey_client_secret"
                               value="<?php echo htmlspecialchars($currentDigikeyClientSecret); ?>"
                               placeholder="ex : AbCdEfGhIjKlMnOpQrStUvWxYz123456">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleDigikeySecret()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        Clé secrète associée (flux « client_credentials »). Laissez les deux champs vides pour
                        masquer DigiKey dans le sélecteur de source. Stocké dans <code>config.local.php</code>,
                        un fichier local exclu du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="digikey_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="digikey_search_limit" name="digikey_search_limit"
                           class="input-number" min="1" max="50" step="1"
                           value="<?php echo (int) $currentDigikeyLimit; ?>">
                    <div class="hint">
                        Entre 1 et 50. DigiKey limite à 120 requêtes/minute et 1 000 requêtes/jour sur
                        l'offre gratuite.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-digikey">
                    <summary>📘 Tutoriel — Créer une application DigiKey et récupérer les identifiants</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte</strong> — rendez-vous sur
                                <a href="https://www.digikey.fr/" target="_blank" rel="noopener">digikey.fr</a>
                                (ou digikey.com) et créez un compte (un compte professionnel n'est pas obligatoire
                                pour l'accès développeur).
                            </li>
                            <li>
                                <strong>Ouvrir le portail développeur</strong> — connectez-vous sur
                                <a href="https://developer.digikey.com/" target="_blank" rel="noopener">developer.digikey.com</a>
                                puis ouvrez <code>My Apps</code> et cliquez sur <code>Create App</code>.
                            </li>
                            <li>
                                <strong>Décrire l'application</strong> — renseignez un nom libre (par exemple
                                <code>Compoz-it</code>) et une description.
                            </li>
                            <li>
                                <strong>Choisir l'API « Product Information »</strong> — cochez la <em>ProductSearch</em>
                                (Product Information v4). L'application doit avoir accès à la recherche de produits.
                            </li>
                            <li>
                                <strong>Choisir le flux OAuth « Client Credentials »</strong> — sélectionnez
                                <code>Client Credentials</code> (2-legged). C'est le flux adapté à une application
                                serveur sans connexion utilisateur : il délivre le token directement avec le
                                Client ID et le Client Secret.
                            </li>
                            <li>
                                <strong>Copier les identifiants</strong> — après création, l'application affiche le
                                <strong>Client ID</strong> et le <strong>Client Secret</strong>. Copiez-les tous les deux.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez-les dans le formulaire ci-dessus,
                                choisissez le nombre de résultats, puis cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a>,
                                sélectionnez « DigiKey » dans le sélecteur de source et testez une référence comme
                                <code>LM358DR2G</code> ou <code>NE555P</code>.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>À propos des quotas :</strong> DigiKey limite l'offre gratuite à 120 requêtes par
                            minute et 1 000 requêtes par jour. La localisation (magasin, langue, devise) est fixée à
                            la France / EUR par défaut, ce qui détermine le prix affiché.
                        </div>

                        <div class="note">
                            <strong>Sécurité :</strong> le Client Secret est un mot de passe. Ne le partagez pas et
                            ne le committez jamais dans Git — il est enregistré dans <code>config.local.php</code>,
                            qui est exclu du dépôt.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — TME -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="tme">
                <h2>🔑 TME — token, secret et recherche</h2>

                <div class="field">
                    <label for="tme_token">Token de l'application</label>
                    <div class="input-row">
                        <input type="password" id="tme_token" name="tme_token"
                               value="<?php echo htmlspecialchars($currentTmeToken); ?>"
                               placeholder="50 caractères">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleTmeToken()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">Identifiant OAuth2 de votre application TME (50 caractères).</div>
                </div>

                <div class="field">
                    <label for="tme_secret">Secret de l'application</label>
                    <div class="input-row">
                        <input type="password" id="tme_secret" name="tme_secret"
                               value="<?php echo htmlspecialchars($currentTmeSecret); ?>"
                               placeholder="20 caractères">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleTmeSecret()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        Clé secrète associée (20 caractères). Laissez les deux champs vides pour masquer TME
                        dans le sélecteur de source. Stocké dans <code>config.local.php</code>, un fichier local
                        exclu du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="tme_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="tme_search_limit" name="tme_search_limit"
                           class="input-number" min="1" max="100" step="1"
                           value="<?php echo (int) $currentTmeLimit; ?>">
                    <div class="hint">Entre 1 et 100 (limite imposée par l'API TME).</div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-tme">
                    <summary>📘 Tutoriel — Créer une application TME et récupérer token + secret</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte</strong> — rendez-vous sur
                                <a href="https://www.tme.eu/" target="_blank" rel="noopener">tme.eu</a> et créez un
                                compte client (gratuit).
                            </li>
                            <li>
                                <strong>Ouvrir le portail développeur</strong> — connectez-vous sur
                                <a href="https://developers.tme.eu/" target="_blank" rel="noopener">developers.tme.eu</a>
                                puis ouvrez la section <code>My applications</code>.
                            </li>
                            <li>
                                <strong>Créer une application</strong> — ajoutez une application (nom libre, par exemple
                                <code>Compoz-it</code>) et cochez l'accès à l'API <code>Products</code>.
                            </li>
                            <li>
                                <strong>Copier le token et le secret</strong> — la page de l'application affiche un
                                <strong>token</strong> de 50 caractères (identifiant) et un <strong>secret</strong> de
                                20 caractères (mot de passe). Copiez-les tous les deux.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez-les dans le formulaire ci-dessus,
                                choisissez le nombre de résultats, puis cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a>,
                                sélectionnez « TME » dans le sélecteur de source et testez une référence comme
                                <code>LM358</code>.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>API v2 :</strong> cette intégration utilise la version 2 de l'API TME (OAuth2).
                            Les applications créées après le 14 mai 2026 ne sont compatibles qu'avec cette version,
                            ce qui est le cas ici.
                        </div>

                        <div class="note">
                            <strong>Sécurité :</strong> le token et le secret sont des identifiants confidentiels.
                            Ne les partagez pas et ne les committez jamais dans Git — ils sont enregistrés dans
                            <code>config.local.php</code>, qui est exclu du dépôt.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — LCSC Electronics -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="lcsc">
                <h2>🔑 LCSC Electronics — recherche</h2>

                <div class="field">
                    <label for="lcsc_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="lcsc_search_limit" name="lcsc_search_limit"
                           class="input-number" min="1" max="100" step="1"
                           value="<?php echo (int) $currentLcscLimit; ?>">
                    <div class="hint">
                        Entre 1 et 100. LCSC utilise le catalogue public JLCPCB / LCSC : aucune clé ni
                        compte n'est nécessaire.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-lcsc">
                    <summary>📘 Tutoriel — LCSC Electronics : aucune configuration requise</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Aucun compte nécessaire</strong> — la recherche LCSC s'appuie sur le
                                catalogue public JLCPCB / LCSC (<code>jlcpcb.com/parts</code>), qui recense
                                l'ensemble des références LCSC.
                            </li>
                            <li>
                                <strong>Utilisation immédiate</strong> — LCSC est toujours proposé dans le sélecteur de
                                source de la page <a href="create_component.php">Créer un composant</a>.
                            </li>
                            <li>
                                <strong>Rechercher</strong> — saisissez une référence LCSC (par exemple <code>C8734</code>),
                                une référence fabricant (par exemple <code>STM32F103C8T6</code>) ou un mot-clé.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>À propos des prix :</strong> LCSC affiche ses prix en dollars US (USD) et fournit
                            le stock disponible ainsi qu'un lien direct vers la fiche produit LCSC.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — Datasheets.com -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="datasheets">
                <h2>🔑 Datasheets.com — clé API et repli datasheet</h2>

                <div class="field">
                    <label for="datasheets_api_key">Clé API Datasheets.com</label>
                    <div class="input-row">
                        <input type="password" id="datasheets_api_key" name="datasheets_api_key"
                               value="<?php echo htmlspecialchars($currentDatasheetsApiKey); ?>"
                               placeholder="ex : ds_live_xxxxxxxxxxxxxxxxxxxxx">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleDatasheetsKey()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        Utilisée pour compléter automatiquement la datasheet d'un composant quand la source choisie
                        n'en fournit pas. Stockée dans <code>config.local.php</code>, un fichier local exclu du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="datasheets_search_limit">Nombre de résultats inspectés</label>
                    <input type="number" id="datasheets_search_limit" name="datasheets_search_limit"
                           class="input-number" min="1" max="10" step="1"
                           value="<?php echo (int) $currentDatasheetsLimit; ?>">
                    <div class="hint">
                        Entre 1 et 10. Ce réglage sert uniquement au repli automatique des datasheets manquantes :
                        en pratique, 5 suffit généralement.
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-datasheets">
                    <summary>📘 Tutoriel — Générer une clé API Datasheets.com</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Créer un compte</strong> — rendez-vous sur
                                <a href="https://www.datasheets.com/" target="_blank" rel="noopener">datasheets.com</a>
                                puis connectez-vous à votre compte.
                            </li>
                            <li>
                                <strong>Générer la clé</strong> — ouvrez
                                <a href="https://www.datasheets.com/account/api" target="_blank" rel="noopener">la page API</a>
                                puis créez une clé d'accès.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez la clé ci-dessus puis cliquez sur
                                « Enregistrer ».
                            </li>
                            <li>
                                <strong>Utilisation</strong> — lors de la création ou de la modification d'un composant,
                                si aucun lien de datasheet n'est déjà fourni, CompoZ'IT interrogera automatiquement
                                Datasheets.com pour compléter le champ.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>Rôle de cette source :</strong> Datasheets.com n'ajoute pas une nouvelle recherche
                            fournisseur dans le sélecteur principal. Elle sert de repli spécialisé pour trouver une
                            fiche technique officielle lorsqu'un composant n'en a pas encore.
                        </div>
                    </div>
                </details>
            </form>

            <!-- Formulaire de réglage — RS Components -->
            <form method="post" class="card" autocomplete="off">
                <input type="hidden" name="section" value="rs">
                <h2>🔑 RS Components — clé API et recherche</h2>

                <div class="field">
                    <label for="rs_api_key">Clé API RS (App ID)</label>
                    <div class="input-row">
                        <input type="password" id="rs_api_key" name="rs_api_key"
                               value="<?php echo htmlspecialchars($currentRsApiKey); ?>"
                               placeholder="ex : 1a2b3c4d-5e6f-7890-abcd-ef1234567890">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleRsKey()" title="Afficher / masquer">👁</button>
                    </div>
                    <div class="hint">
                        App ID fourni par votre commercial RS Components. Laissez vide pour masquer RS dans le
                        sélecteur de source. Stockée dans <code>config.local.php</code>, un fichier local exclu
                        du dépôt Git.
                    </div>
                </div>

                <div class="field">
                    <label for="rs_store_id">Magasin régional</label>
                    <input type="text" id="rs_store_id" name="rs_store_id"
                           value="<?php echo htmlspecialchars($currentRsStore); ?>"
                           placeholder="ex : fr.rs-online.com">
                    <div class="hint">
                        Domaine du magasin interrogé : <code>fr.rs-online.com</code>, <code>uk.rs-online.com</code>,
                        <code>us.rs-online.com</code>… Il détermine le catalogue, les prix et la devise.
                    </div>
                </div>

                <div class="field">
                    <label for="rs_search_limit">Nombre de résultats par recherche</label>
                    <input type="number" id="rs_search_limit" name="rs_search_limit"
                           class="input-number" min="1" max="50" step="1"
                           value="<?php echo (int) $currentRsLimit; ?>">
                    <div class="hint">Entre 1 et 50.</div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-indigo">💾 Enregistrer</button>
                    <a href="settings.php" class="btn btn-ghost">↩️ Retour aux paramètres</a>
                </div>

                <details class="tuto-accordion" id="tuto-rs">
                    <summary>📘 Tutoriel — Obtenir un accès API RS Components</summary>
                    <div class="tuto-body tuto">
                        <ol>
                            <li>
                                <strong>Contacter RS Components</strong> — l'API RS n'est pas ouverte en
                                libre-service : l'accès s'obtient auprès de votre commercial RS
                                (<a href="https://fr.rs-online.com/" target="_blank" rel="noopener">fr.rs-online.com</a>).
                            </li>
                            <li>
                                <strong>Demander un App ID</strong> — demandez à votre interlocuteur RS un
                                <strong>App ID</strong> associé à votre compte client. C'est cet identifiant qui
                                autorise les appels à l'API.
                            </li>
                            <li>
                                <strong>Renseigner le magasin</strong> — indiquez le domaine de votre magasin RS
                                (<code>fr.rs-online.com</code> par défaut) pour interroger le bon catalogue et la
                                bonne devise.
                            </li>
                            <li>
                                <strong>Coller et enregistrer</strong> — collez l'App ID dans le formulaire ci-dessus,
                                choisissez le nombre de résultats, puis cliquez sur « Enregistrer ».
                            </li>
                            <li>
                                <strong>Vérifier</strong> — ouvrez <a href="create_component.php">Créer un composant</a> :
                                « RS Components » doit apparaître dans le sélecteur de source. Testez une référence RS
                                (par exemple <code>489-0043</code>) ou un MPN.
                            </li>
                        </ol>

                        <div class="note">
                            <strong>Important :</strong> RS Components ne publie pas sa documentation d'API
                            publiquement. Le point d'entrée utilisé par l'application est défini en tête de
                            <code>rs_api.php</code> : ajustez-le si RS vous communique une URL ou un mode
                            d'authentification différents.
                        </div>

                        <div class="note">
                            <strong>Sécurité :</strong> l'App ID est un identifiant confidentiel. Ne le partagez pas
                            et ne le committez jamais dans Git — il est enregistré dans <code>config.local.php</code>,
                            qui est exclu du dépôt.
                        </div>
                    </div>
                </details>
            </form>

        </div>

        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>

    <script>
        // Affiche / masque le Client Secret pour éviter les erreurs de saisie.
        function toggleSecret() {
            const input = document.getElementById('nexar_client_secret');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque la clé API Mouser.
        function toggleMouserKey() {
            const input = document.getElementById('mouser_api_key');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque la clé API Farnell.
        function toggleFarnellKey() {
            const input = document.getElementById('farnell_api_key');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque le Client Secret DigiKey.
        function toggleDigikeySecret() {
            const input = document.getElementById('digikey_client_secret');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque le token TME.
        function toggleTmeToken() {
            const input = document.getElementById('tme_token');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque le secret TME.
        function toggleTmeSecret() {
            const input = document.getElementById('tme_secret');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque la clé API Datasheets.com.
        function toggleDatasheetsKey() {
            const input = document.getElementById('datasheets_api_key');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Affiche / masque la clé API RS (App ID).
        function toggleRsKey() {
            const input = document.getElementById('rs_api_key');
            input.type = (input.type === 'password') ? 'text' : 'password';
        }

        // Aide contextuelle : les liens « api_settings.php#tuto-<source> » doivent
        // ouvrir l'accordéon du fournisseur concerné (un <details> ne s'ouvre pas
        // automatiquement lorsqu'on cible son ancre).
        function openTutoFromHash() {
            const hash = window.location.hash;
            if (!hash || hash.indexOf('#tuto-') !== 0) { return; }

            const details = document.getElementById(hash.slice(1));
            if (!details) { return; }

            details.open = true;
            details.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.addEventListener('DOMContentLoaded', openTutoFromHash);
        window.addEventListener('hashchange', openTutoFromHash);
    </script>
</body>
</html>


