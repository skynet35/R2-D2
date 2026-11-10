<?php
/* =========================================================================
   Réglage de la base de données MySQL
   ---------------------------------------------------------------------------
   Accessible depuis la page de connexion (engrenage) pour la première
   installation, et depuis Paramètres → Base de données SQL.

   Ce fichier n'inclut volontairement PAS config.php : la page doit rester
   utilisable même lorsque la base de données est injoignable.
   Les identifiants sont écrits dans config.local.php (sauvegarde .bak créée
   avant chaque écriture), le reste du fichier est préservé.
   ========================================================================= */

require_once 'session_init.php';

$configPath     = __DIR__ . '/config.local.php';
$configExists   = file_exists($configPath);
$configWritable = $configExists ? is_writable($configPath) : is_writable(__DIR__);

if ($configExists) {
    require_once $configPath;
}
if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))     define('DB_NAME', 'compozit');
if (!defined('DB_USER'))     define('DB_USER', 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', '');

/**
 * Teste une connexion MySQL sans interrompre le script.
 *
 * @return array{ok:bool, message:string}
 */
function compozitProbeDb(string $host, string $name, string $user, string $password): array {
    // Délai court : la page doit répondre vite quand la base est injoignable.
    $previousTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '5');

    try {
        $dsn = 'mysql:host=' . $host;
        if ($name !== '') {
            $dsn .= ';dbname=' . $name;
        }
        $dsn .= ';charset=utf8';

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->query('SELECT 1');

        return ['ok' => true, 'message' => 'Connexion réussie.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        if ($previousTimeout !== false) {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }
    }
}

/**
 * Écrit DB_HOST / DB_NAME / DB_USER / DB_PASSWORD dans config.local.php en
 * préservant le reste du fichier. Crée le fichier s'il n'existe pas.
 *
 * @param  array{host:string, name:string, user:string, password:string} $values
 * @return array{ok:bool, message?:string}
 */
function compozitWriteDbConfig(array $values): array {
    $path     = __DIR__ . '/config.local.php';
    $exists   = file_exists($path);
    $original = '';
    $content  = '';

    if ($exists) {
        $original = @file_get_contents($path);
        if ($original === false) {
            return ['ok' => false, 'message' => 'Impossible de lire config.local.php. Vérifiez les droits du fichier.'];
        }
        if (strpos($original, '<?php') !== 0) {
            return ['ok' => false, 'message' => "config.local.php n'a pas le format attendu : écriture annulée par précaution."];
        }
        $content = $original;
    }

    $constants = [
        'DB_HOST'     => $values['host'],
        'DB_NAME'     => $values['name'],
        'DB_USER'     => $values['user'],
        'DB_PASSWORD' => $values['password'],
    ];

    foreach ($constants as $name => $value) {
        // var_export() produit un littéral PHP correctement échappé.
        $line    = "define('" . $name . "', " . var_export((string) $value, true) . ");";
        $pattern = "/^[ \t]*define\(\s*'" . preg_quote($name, '/') . "'\s*,[^\n]*$/m";

        if ($exists && preg_match($pattern, $content)) {
            // Callback obligatoire : dans preg_replace(), les antislashs du
            // remplacement seraient interprétés comme des échappements.
            $content = (string) preg_replace_callback($pattern, function () use ($line) {
                return $line;
            }, $content, 1);
        } else {
            $content = rtrim($content) . "\n" . $line . "\n";
        }
    }

    if ($exists) {
        if (@file_put_contents($path . '.bak', $original, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Impossible de créer la sauvegarde config.local.php.bak.'];
        }
    } else {
        $content = "<?php\n"
            . "/* Identifiants MySQL - générés depuis CompoZ'IT (Réglage de la base de données) */\n"
            . ltrim($content) . "\n";
    }

    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        return ['ok' => false, 'message' => "Impossible d'écrire dans config.local.php. Vérifiez les droits du fichier."];
    }

    return ['ok' => true];
}

$isLoggedIn  = isset($_SESSION['user_id']);
$probe       = compozitProbeDb(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);
$dbReachable = $probe['ok'];

/* La modification est réservée aux utilisateurs connectés, sauf lorsque la
   base est injoignable : c'est le cas de la première installation, où la page
   doit rester accessible depuis l'écran de connexion. */
$readOnly = !$isLoggedIn && $dbReachable;

$error   = null;
$success = null;
$values  = ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASSWORD];

if (isset($_GET['db_saved'])) {
    $success = 'Identifiants de la base de données enregistrés.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['host'] = trim((string) ($_POST['db_host'] ?? ''));
    $values['name'] = trim((string) ($_POST['db_name'] ?? ''));
    $values['user'] = trim((string) ($_POST['db_user'] ?? ''));

    // Champ vide : on conserve le mot de passe déjà enregistré.
    $submittedPassword  = (string) ($_POST['db_password'] ?? '');
    $values['password'] = $submittedPassword !== '' ? $submittedPassword : DB_PASSWORD;

    $redirect = (string) ($_POST['redirect'] ?? 'db_setup.php');
    if (!in_array($redirect, ['db_setup.php', 'settings.php'], true)) {
        $redirect = 'db_setup.php';
    }

    $success = null;
    // Bouton "Tester" : on vérifie la connexion sans rien enregistrer.
    $testOnly = isset($_POST['db_test']);

    if ($readOnly) {
        $error = "Connectez-vous pour modifier la configuration de la base de données.";
    } elseif ($values['host'] === '' || $values['name'] === '' || $values['user'] === '') {
        $error = "L'hôte, le nom de la base et l'utilisateur sont obligatoires.";
    } else {
        $test = compozitProbeDb($values['host'], $values['name'], $values['user'], $values['password']);

        if (!$test['ok']) {
            $error = 'Connexion impossible avec ces paramètres : ' . $test['message'];
        } elseif ($testOnly) {
            $success = 'Connexion réussie : la base « ' . $values['name'] . ' » répond correctement. Rien n\'a été enregistré.';
        } else {
            $write = compozitWriteDbConfig($values);

            if (!$write['ok']) {
                $error = $write['message'];
            } else {
                header('Location: ' . $redirect . '?db_saved=1');
                exit();
            }
        }
    }
}

$backLink = $isLoggedIn
    ? ['href' => 'settings.php', 'label' => 'Retour aux paramètres']
    : ['href' => 'index.php', 'label' => 'Retour à la connexion'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){document.documentElement.setAttribute('data-theme','dark');})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Base de données SQL - CompoZ'IT</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #070b14;
            color: #e8eefb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .cz-db-wrap {
            flex: 1;
            width: 100%;
            max-width: 720px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .cz-db-card {
            background: #0d182c;
            border: 1px solid #1f2b46;
            border-radius: 16px;
            padding: 30px;
        }
        .cz-db-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .cz-db-gear {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            border-radius: 11px;
            border: 1px solid #243858;
            background: #101a2d;
            font-size: 20px;
        }
        .cz-db-sub {
            color: #93a4c0;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .cz-db-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 22px;
        }
        .cz-db-status.is-on  { background: rgba(47,158,107,.14);  color: #6fd3a2; border: 1px solid rgba(47,158,107,.40); }
        .cz-db-status.is-off { background: rgba(160,91,102,.14);  color: #e0a0a8; border: 1px solid rgba(160,91,102,.45); }
        .cz-db-status .cz-db-dot { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
        .cz-db-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .cz-db-field label {
            display: block;
            margin-bottom: 6px;
            color: #93a4c0;
            font-size: 13px;
            font-weight: 600;
        }
        .cz-db-field input {
            width: 100%;
            padding: 10px 12px;
            background: #101a2d;
            border: 1px solid #243858;
            border-radius: 8px;
            color: #e8eefb;
            font-size: 14px;
            font-family: inherit;
        }
        .cz-db-field input:focus { outline: none; border-color: #5f79b8; }
        .cz-db-field .cz-db-help {
            display: block;
            margin-top: 5px;
            color: #7488a8;
            font-size: 11.5px;
        }
        .cz-db-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .cz-db-btn {
            padding: 11px 20px;
            border: none;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .cz-db-btn-primary { background: #6e88c4; color: #eef4ff; border: 1px solid #8aa2d3; }
        .cz-db-btn-primary:hover { background: #7d95cd; }
        .cz-db-btn-ghost { background: #101a2d; color: #c3cee2; border: 1px solid #243858; }
        .cz-db-btn-ghost:hover { color: #e8eefb; border-color: #5f79b8; }
        .cz-db-btn-test { background: #16243d; color: #a9bde4; border: 1px solid #2a3f63; }
        .cz-db-btn-test:hover { background: #1b2c49; color: #e8eefb; border-color: #5f79b8; }
        .cz-db-alert {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13.5px;
            line-height: 1.5;
        }
        .cz-db-alert.is-error   { background: rgba(160,91,102,.14); color: #e0a0a8; border: 1px solid rgba(160,91,102,.45); }
        .cz-db-alert.is-success { background: rgba(47,158,107,.14); color: #6fd3a2; border: 1px solid rgba(47,158,107,.40); }
        .cz-db-alert.is-warning { background: rgba(190,150,80,.14); color: #dcc08a; border: 1px solid rgba(190,150,80,.40); }
        .cz-db-msg {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 9px;
            background: #0b1220;
            border: 1px solid #162238;
            color: #93a4c0;
            font-size: 12.5px;
            line-height: 1.5;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <div class="cz-db-wrap">
        <div class="cz-db-card">
            <div class="cz-db-title">
                <span class="cz-db-gear">&#9881;</span>
                <span>Base de données SQL</span>
            </div>
            <p class="cz-db-sub">
                Connexion MySQL utilisée par CompoZ'IT. Les identifiants sont enregistrés
                dans <code>config.local.php</code>.
            </p>

            <div class="cz-db-status <?php echo $dbReachable ? 'is-on' : 'is-off'; ?>">
                <span class="cz-db-dot"></span>
                <?php
                echo $dbReachable
                    ? 'Connecté à ' . htmlspecialchars(DB_NAME) . ' (' . htmlspecialchars(DB_HOST) . ')'
                    : 'Base de données injoignable';
                ?>
            </div>

            <?php if ($error): ?>
                <div class="cz-db-alert is-error">&#10007; <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="cz-db-alert is-success">&#10003; <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!$configWritable): ?>
                <div class="cz-db-alert is-warning">
                    &#9888; Le fichier <code>config.local.php</code> n'est pas accessible en écriture :
                    l'enregistrement échouera. Corrigez les droits du fichier (644 suffisent).
                </div>
            <?php endif; ?>

            <?php if ($readOnly): ?>
                <div class="cz-db-alert is-warning">
                    &#9888; La base de données répond : connectez-vous pour modifier ses identifiants.
                </div>
            <?php endif; ?>

            <form method="POST" action="db_setup.php">
                <input type="hidden" name="redirect" value="db_setup.php">

                <div class="cz-db-grid">
                    <div class="cz-db-field">
                        <label for="db_host">Serveur (hôte)</label>
                        <input type="text" id="db_host" name="db_host"
                               value="<?php echo htmlspecialchars($values['host']); ?>"
                               placeholder="localhost" <?php echo $readOnly ? 'disabled' : ''; ?> required>
                    </div>

                    <div class="cz-db-field">
                        <label for="db_name">Nom de la base</label>
                        <input type="text" id="db_name" name="db_name"
                               value="<?php echo htmlspecialchars($values['name']); ?>"
                               placeholder="compozit" <?php echo $readOnly ? 'disabled' : ''; ?> required>
                    </div>

                    <div class="cz-db-field">
                        <label for="db_user">Utilisateur</label>
                        <input type="text" id="db_user" name="db_user"
                               value="<?php echo htmlspecialchars($values['user']); ?>"
                               placeholder="root" <?php echo $readOnly ? 'disabled' : ''; ?> required>
                    </div>

                    <div class="cz-db-field">
                        <label for="db_password">Mot de passe</label>
                        <input type="password" id="db_password" name="db_password"
                               value="" autocomplete="new-password"
                               placeholder="Laisser vide pour conserver l'actuel"
                               <?php echo $readOnly ? 'disabled' : ''; ?>>
                        <span class="cz-db-help">Laissez vide pour ne pas changer le mot de passe enregistré.</span>
                    </div>
                </div>

                <div class="cz-db-actions">
                    <?php if (!$readOnly): ?>
                        <button type="submit" name="db_save" value="1" class="cz-db-btn cz-db-btn-primary">&#128190; Enregistrer</button>
                        <button type="submit" name="db_test" value="1" class="cz-db-btn cz-db-btn-test">&#128268; Tester la connexion</button>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($backLink['href']); ?>" class="cz-db-btn cz-db-btn-ghost">
                        &#8592; <?php echo htmlspecialchars($backLink['label']); ?>
                    </a>
                </div>
            </form>

            <?php if (!$dbReachable): ?>
                <div class="cz-db-msg">
                    Détail technique : <?php echo htmlspecialchars($probe['message']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
