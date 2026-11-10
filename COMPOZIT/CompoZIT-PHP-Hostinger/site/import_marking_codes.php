<?php
/* =========================================================================
   CompoZ'IT - Import de la base de marquage CMS
   -------------------------------------------------------------------------
   La table `marking_codes` (~423 000 codes relevés sur smd.yooneed.one) est
   livrée à part, dans le fichier base_de_donnees/marking_codes.sql (environ
   60 Mo). Trop volumineux pour l'envoi par formulaire ou pour un import en
   une seule requete, le fichier est lu sur le serveur morceau par morceau :
   chaque appel insere les instructions suivantes et renvoie sa position,
   que la page rappelle jusqu'a la fin du fichier.

   Entree  : ?action=step&offset=12345678     (un lot d'instructions)
             ?action=status                    (etat courant)
             ?action=delete                    (supprime le fichier .sql)
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';

/* Verifier si l'utilisateur est connecte */
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

/* Fichier de marquage, deploye avec le site (ce dossier n'est pas
   telechargeable : le .htaccess du site bloque les fichiers *.sql). */
define('MARKING_SQL_FILE', __DIR__ . '/base_de_donnees/marking_codes.sql');

/* Budget d'un lot : nombre d'instructions et duree, pour rester sous les
   limites d'execution du mutualise. */
define('MARKING_STEP_STATEMENTS', 200);
define('MARKING_STEP_SECONDS', 6.0);

function markingImportJson(array $payload, int $httpCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/** La table de marquage est-elle deja installee ? */
function markingImportInstalled(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT code FROM marking_codes LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function markingImportRows(PDO $pdo): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM marking_codes')->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Certaines instructions d'un export visent le serveur lui-meme et non le
 * contenu de la base : sur un hebergement mutualise elles echouent inutilement.
 */
function markingImportSkip(string $statement): bool
{
    $head = strtoupper(substr(ltrim($statement), 0, 30));

    return strpos($head, 'USE ') === 0
        || strpos($head, 'CREATE DATABASE') === 0
        || strpos($head, 'DROP DATABASE') === 0
        || strpos($head, 'SET ') === 0;
}

/**
 * Insere les instructions suivantes du fichier, a partir de la position
 * donnee, jusqu'a epuiser son budget. Une instruction se termine par un
 * point-virgule en fin de ligne (une ligne de donnees se termine par une
 * virgule, un point-virgule n'apparait donc jamais au milieu).
 *
 * Renvoie la nouvelle position, le nombre d'instructions executees et
 * l'erreur eventuelle.
 */
function markingImportStep(PDO $pdo, string $file, int $offset): array
{
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return ['offset' => $offset, 'statements' => 0, 'error' => 'Fichier illisible.'];
    }

    /* Relecture possible du meme point : aucun etat n'est conserve entre deux
       appels, l'import reprend exactement ou il s'etait arrete. */
    if ($offset > 0 && fseek($handle, $offset) !== 0) {
        fclose($handle);
        return ['offset' => $offset, 'statements' => 0, 'error' => 'Position invalide.'];
    }

    $started    = microtime(true);
    $statements = 0;
    $error      = '';

    try {
        while (!feof($handle)) {
            $buffer = '';

            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $buffer .= $line;
                if (substr(rtrim($line), -1) === ';') {
                    break;
                }
            }

            $trimmed = trim($buffer);
            if ($trimmed === '') {
                break;
            }
            /* Derniere instruction du fichier sans point-virgule : ignoree,
               elle serait de toute facon incomplete. */
            if (substr(rtrim($buffer), -1) !== ';') {
                break;
            }

            if (!markingImportSkip($trimmed)) {
                $pdo->exec($trimmed);
            }
            $statements++;

            if ($statements >= MARKING_STEP_STATEMENTS
                || (microtime(true) - $started) > MARKING_STEP_SECONDS) {
                break;
            }
        }
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }

    $position = ftell($handle);
    fclose($handle);

    return [
        'offset'     => ($position === false) ? $offset : $position,
        'statements' => $statements,
        'error'      => $error,
    ];
}

$action = (string) ($_GET['action'] ?? '');

/* --------------------------------------------------------------- Actions */

if ($action !== '') {
    try {
        $pdo = getConnection();
    } catch (Throwable $e) {
        markingImportJson(['ok' => false, 'error' => 'Base de données indisponible.'], 500);
    }

    if ($action === 'status') {
        markingImportJson([
            'ok'        => true,
            'installed' => markingImportInstalled($pdo),
            'rows'      => markingImportRows($pdo),
            'file'      => is_readable(MARKING_SQL_FILE),
            'size'      => is_readable(MARKING_SQL_FILE) ? (int) filesize(MARKING_SQL_FILE) : 0,
        ]);
    }

    if ($action === 'step') {
        if (!is_readable(MARKING_SQL_FILE)) {
            markingImportJson([
                'ok'    => false,
                'error' => 'Fichier base_de_donnees/marking_codes.sql absent du serveur.',
            ]);
        }

        @set_time_limit(60);

        $result = markingImportStep($pdo, MARKING_SQL_FILE, max(0, (int) ($_GET['offset'] ?? 0)));
        $size   = (int) filesize(MARKING_SQL_FILE);
        $done   = ($result['offset'] >= $size) || $result['error'] !== '';

        markingImportJson([
            'ok'         => $result['error'] === '',
            'offset'     => $result['offset'],
            'total'      => $size,
            'statements' => $result['statements'],
            'done'       => $done,
            'rows'       => $done && $result['error'] === '' ? markingImportRows($pdo) : null,
            'error'      => $result['error'],
        ]);
    }

    if ($action === 'delete') {
        if (is_file(MARKING_SQL_FILE) && @unlink(MARKING_SQL_FILE)) {
            markingImportJson(['ok' => true]);
        }
        markingImportJson(['ok' => false, 'error' => 'Suppression impossible.']);
    }

    markingImportJson(['ok' => false, 'error' => 'Action inconnue.'], 400);
}

/* ------------------------------------------------------------- Affichage */

ini_set('display_errors', '0');

$installed = false;
$rows      = 0;
$error     = '';
$fileReady = is_readable(MARKING_SQL_FILE);
$fileSize  = $fileReady ? (int) filesize(MARKING_SQL_FILE) : 0;

try {
    $pdo       = getConnection();
    $installed = markingImportInstalled($pdo);
    $rows      = $installed ? markingImportRows($pdo) : 0;
} catch (Throwable $e) {
    $error = 'Base de données indisponible : ' . $e->getMessage();
}

/* Octets -> unité lisible. */
function markingImportSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }
    return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Base de marquage CMS - Gestion des Composants</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border-radius: 20px; padding: 28px 32px 32px;
            margin-bottom: 24px; box-shadow: 0 20px 40px rgba(102,126,234,0.2);
        }
        .header-top {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 16px; margin-bottom: 20px;
        }
        .header-title { display: flex; align-items: center; gap: 14px; }
        .header-icon {
            width: 52px; height: 52px; background: rgba(255,255,255,0.2);
            border-radius: 14px; display: flex; align-items: center;
            justify-content: center; font-size: 26px;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
        .header-title p { font-size: 13px; opacity: 0.85; margin-top: 3px; }
        .user-chip {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15); padding: 8px 16px;
            border-radius: 999px; border: 1px solid rgba(255,255,255,0.2);
            font-size: 13px; white-space: nowrap;
        }
        .logout-link {
            color: white; text-decoration: none; background: rgba(255,255,255,0.2);
            padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 500;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-buttons a {
            background: rgba(255,255,255,0.15); color: white; padding: 10px 18px;
            border-radius: 999px; text-decoration: none; font-weight: 500;
            font-size: 14px; border: 1px solid rgba(255,255,255,0.2);
        }
        .nav-buttons a:hover { background: rgba(255,255,255,0.28); }
        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            font-weight: 600; font-size: 14px; line-height: 1.5;
        }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 28px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);
        }
        .card h2 { font-size: 20px; margin-bottom: 10px; }
        .card p { color: var(--text-secondary); font-size: 14px; line-height: 1.6; }
        .status-line {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            margin: 18px 0 6px; font-size: 14px;
        }
        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
        }
        .pill-ok   { background: #d4edda; color: #155724; }
        .pill-warn { background: #fff3cd; color: #856404; }
        .warning {
            background: #fff3cd; color: #856404; border: 1px solid #ffeeba;
            border-radius: 10px; padding: 14px 18px; margin: 18px 0;
            font-size: 13px; line-height: 1.6;
        }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
        .btn {
            padding: 13px 24px; border: none; border-radius: 8px; cursor: pointer;
            text-decoration: none; font-weight: 600; display: inline-flex;
            align-items: center; gap: 8px; font-size: 14px; transition: all 0.18s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(0,0,0,0.15); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-ghost   { background: var(--bg-muted); color: var(--text-secondary); }
        .btn-danger  { background: #ef4444; color: white; }
        .progress-wrap { margin-top: 22px; display: none; }
        .progress-bar {
            height: 14px; background: var(--bg-muted); border-radius: 999px;
            overflow: hidden; border: 1px solid var(--border-color);
        }
        .progress-fill {
            height: 100%; width: 0; border-radius: 999px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.25s ease;
        }
        .progress-text {
            margin-top: 10px; font-size: 13px; color: var(--text-secondary);
            display: flex; justify-content: space-between; gap: 12px;
        }
        .details {
            margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border-color);
            font-size: 13px; color: var(--text-secondary); line-height: 1.7;
        }
        .details code {
            background: var(--bg-muted); padding: 2px 6px; border-radius: 4px; font-size: 12px;
        }
        .details ul { margin: 8px 0 0 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🔡</div>
                    <div>
                        <h1>Base de marquage CMS</h1>
                        <p>Installation de la table des codes imprimés sur les boîtiers</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="components.php">📦 Composants</a>
                <a href="marking.php">🔎 Marquage</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>🔡 Recherche par code de marquage</h2>
            <p>
                Les boîtiers CMS portent un code court (2 à 4 caractères) au lieu de la
                référence complète. La page <strong>Marquage</strong> permet de retrouver
                la référence à partir de ce code : elle s'appuie sur la table
                <code>marking_codes</code>, installée une seule fois ci-dessous.
            </p>

            <div class="status-line">
                <span class="pill <?php echo $installed ? 'pill-ok' : 'pill-warn'; ?>">
                    <?php if ($installed): ?>
                        ✅ Table installée — <?php echo number_format($rows, 0, ',', ' '); ?> codes indexés
                    <?php else: ?>
                        ⚠️ Table absente
                    <?php endif; ?>
                </span>
                <span class="pill <?php echo $fileReady || $installed ? 'pill-ok' : 'pill-warn'; ?>">
                    <?php if ($fileReady): ?>
                        📄 Fichier présent — <?php echo htmlspecialchars(markingImportSize($fileSize)); ?>
                    <?php elseif ($installed): ?>
                        🗑️ Fichier supprimé du serveur — normal après installation
                    <?php else: ?>
                        📄 Fichier <code>base_de_donnees/marking_codes.sql</code> absent
                    <?php endif; ?>
                </span>
            </div>

            <?php if (!$fileReady && !$installed): ?>
                <div class="warning">
                    ⚠️ Dépose le fichier <code>base_de_donnees/marking_codes.sql</code> (livré
                    avec le pack, environ 60 Mo) dans le dossier
                    <code>base_de_donnees/</code> de ton site par FTP (FileZilla), puis
                    recharge cette page. Le fichier n'est pas téléchargeable
                    depuis le web : le <code>.htaccess</code> bloque les <code>.sql</code>.
                </div>
            <?php elseif (!$fileReady && $installed): ?>
                <div class="warning" style="background:#e8f5e9;color:#1b5e20;border-color:#c8e6c9;">
                    ✅ La table est déjà installée. Le fichier <code>base_de_donnees/marking_codes.sql</code>
                    a probablement été supprimé après l'import pour libérer de l'espace : c'est normal.
                    Redépose-le seulement si tu veux réinstaller la base de marquage.
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="button" class="btn btn-primary" id="import_btn"
                        <?php echo $fileReady ? '' : 'disabled'; ?>>
                    📥 <?php echo $installed ? 'Réinstaller la base de marquage' : 'Installer la base de marquage'; ?>
                </button>
                <a href="marking.php" class="btn btn-ghost">🔎 Ouvrir la recherche</a>
                <button type="button" class="btn btn-danger" id="delete_btn"
                        <?php echo $fileReady ? '' : 'disabled'; ?>>
                    🗑️ Supprimer le fichier .sql du serveur
                </button>
            </div>

            <div class="progress-wrap" id="progress_wrap">
                <div class="progress-bar"><div class="progress-fill" id="progress_fill"></div></div>
                <div class="progress-text">
                    <span id="progress_label">Préparation…</span>
                    <span id="progress_percent">0 %</span>
                </div>
            </div>

            <div class="details">
                <strong>Ce que fait cette page</strong>
                <ul>
                    <li>Le fichier est lu sur le serveur par lots de quelques
                        mégaoctets : ni la mémoire, ni le temps d'exécution, ni la
                        taille d'envoi du serveur ne sont mis à l'épreuve.</li>
                    <li>L'opération est sans risque pour ton inventaire : seule la
                        table <code>marking_codes</code> est créée puis remplie.</li>
                    <li>Il faut compter quelques dizaines de secondes, la page
                        affiche sa progression.</li>
                    <li>Une fois l'installation terminée, le fichier de 60 Mo n'est
                        plus utile : supprime-le pour libérer de l'espace.</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var importBtn = document.getElementById('import_btn');
        var deleteBtn = document.getElementById('delete_btn');
        var wrap      = document.getElementById('progress_wrap');
        var fill      = document.getElementById('progress_fill');
        var label     = document.getElementById('progress_label');
        var percent   = document.getElementById('progress_percent');

        var running = false;

        function sizeLabel(bytes) {
            if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
            return Math.round(bytes / 1024) + ' Ko';
        }

        function setProgress(value, text) {
            fill.style.width = value + '%';
            percent.textContent = value + ' %';
            label.textContent = text;
        }

        /* Un lot : le serveur insere les instructions suivantes et renvoie sa
           nouvelle position ; on rappelle jusqu'a la fin du fichier. */
        function step(offset, inserted) {
            fetch('import_marking_codes.php?action=step&offset=' + offset, {
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) || 'Import interrompu.');
                    }

                    inserted += data.statements;

                    var ratio = data.total > 0 ? (data.offset / data.total) * 100 : 0;
                    setProgress(Math.min(100, Math.round(ratio)),
                        inserted + ' instructions insérées — ' + sizeLabel(data.offset) + ' / ' + sizeLabel(data.total));

                    if (data.done) {
                        setProgress(100, 'Installation terminée : '
                            + (data.rows === null ? '' : data.rows.toLocaleString('fr-FR') + ' codes indexés.'));
                        label.textContent += ' Rechargement…';
                        window.setTimeout(function () { window.location.reload(); }, 1200);
                        return;
                    }

                    step(data.offset, inserted);
                })
                .catch(function (error) {
                    running = false;
                    importBtn.disabled = false;
                    label.textContent = '❌ ' + error.message;
                });
        }

        if (importBtn) {
            importBtn.addEventListener('click', function () {
                if (running) { return; }
                if (!window.confirm('Installer (ou réinstaller) la base de marquage CMS ?\n\n'
                        + 'La table marking_codes sera recréée et remplie : cela peut prendre quelques dizaines de secondes.')) {
                    return;
                }
                running = true;
                importBtn.disabled = true;
                deleteBtn.disabled = true;
                wrap.style.display = 'block';
                setProgress(0, 'Démarrage de l\'import…');
                step(0, 0);
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                if (!window.confirm('Supprimer le fichier base_de_donnees/marking_codes.sql du serveur ?\n\n'
                        + 'La table installée n\'est pas touchée, seule la copie du fichier est effacée.')) {
                    return;
                }
                deleteBtn.disabled = true;
                fetch('import_marking_codes.php?action=delete', { headers: { 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            window.location.reload();
                        } else {
                            deleteBtn.disabled = false;
                            window.alert((data && data.error) || 'Suppression impossible.');
                        }
                    })
                    .catch(function () {
                        deleteBtn.disabled = false;
                        window.alert('Suppression impossible.');
                    });
            });
        }
    })();
    </script>
</body>
</html>


