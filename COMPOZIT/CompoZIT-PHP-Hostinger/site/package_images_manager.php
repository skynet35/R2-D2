<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'run_auto_assign':
                $assignResult = include 'auto_assign_package_images.php';

                if (!is_array($assignResult)) {
                    $error = "Script d'assignation automatique introuvable.";
                } elseif (empty($assignResult['success'])) {
                    $error = "Assignation automatique : " . ($assignResult['error'] ?? 'erreur inconnue');
                } else {
                    $message = sprintf(
                        "Assignation automatique terminée : %d image(s) attribuée(s) sur %d composant(s) sans image.",
                        $assignResult['assigned_count'],
                        $assignResult['total_checked']
                    );
                }
                break;
                
            case 'preview_changes':
                try {
                    $pdo = getConnection();
                    $stmt = $pdo->prepare("
                        SELECT d.id, d.name, d.package, d.image_path 
                        FROM data d
                        WHERE (d.image_path IS NULL OR d.image_path = '' OR d.image_path = 'default.png') 
                        AND d.package IS NOT NULL 
                        AND d.package != ''
                        ORDER BY d.package, d.name
                    ");
                    $stmt->execute();
                    $previewData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $previewWithImages = [];
                    $previewWithoutImages = [];
                    
                    $imageDir = __DIR__ . '/img/';
                    foreach ($previewData as $component) {
                        $packageImage = null;
                        $cleanPackageName = preg_replace('/[^a-zA-Z0-9\-_]/', '', $component['package']);
                        $extensions = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
                        
                        foreach ($extensions as $ext) {
                            if (file_exists($imageDir . $cleanPackageName . '.' . $ext)) {
                                $packageImage = 'img/' . $cleanPackageName . '.' . $ext;
                                break;
                            }
                            if (file_exists($imageDir . $component['package'] . '.' . $ext)) {
                                $packageImage = 'img/' . $component['package'] . '.' . $ext;
                                break;
                            }
                        }
                        
                        if ($packageImage) {
                            $component['new_image'] = $packageImage;
                            $previewWithImages[] = $component;
                        } else {
                            $previewWithoutImages[] = $component;
                        }
                    }
                } catch (PDOException $e) {
                    $error = "Erreur lors de la prévisualisation : " . $e->getMessage();
                }
                break;

            case 'rename_image':
                $renameResult = compozitRenamePackageImage(
                    (string) ($_POST['old_name'] ?? ''),
                    (string) ($_POST['new_name'] ?? '')
                );

                // Appel en arriere-plan (fetch) : on renvoie du JSON et rien d'autre.
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($renameResult);
                    exit;
                }

                if (!empty($renameResult['success'])) {
                    $message = "Image renommée : « " . $renameResult['old'] . " » → « "
                             . $renameResult['new'] . " ».";
                    if (!empty($renameResult['updated'])) {
                        $message .= " " . (int) $renameResult['updated'] . " composant(s) mis à jour.";
                    }
                    if (!empty($renameResult['warning'])) {
                        $message .= " " . $renameResult['warning'];
                    }
                } else {
                    $error = $renameResult['error'] ?? 'Renommage impossible.';
                }
                break;

            case 'delete_image':
                $deleteResult = compozitDeletePackageImage((string) ($_POST['image_name'] ?? ''));

                // Appel en arriere-plan (fetch) : on renvoie du JSON et rien d'autre.
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($deleteResult);
                    exit;
                }

                if (!empty($deleteResult['success'])) {
                    $message = "Image supprimée : « " . $deleteResult['name'] . " ».";
                    if (!empty($deleteResult['updated'])) {
                        $message .= " " . (int) $deleteResult['updated'] . " composant(s) réinitialisé(s).";
                    }
                    if (!empty($deleteResult['warning'])) {
                        $message .= " " . $deleteResult['warning'];
                    }
                } else {
                    $error = $deleteResult['error'] ?? 'Suppression impossible.';
                }
                break;
        }
    }
}

/**
 * Renomme un fichier image present dans img/ et met a jour les composants
 * qui referencent l'ancien nom.
 *
 * Le nom de destination est volontairement restreint (pas de dossier, pas de
 * caractere exotique) : ce point d'ecriture ne doit pas permettre de sortir
 * du repertoire img/.
 */
function compozitRenamePackageImage(string $oldName, string $newName): array
{
    $allowedExtensions = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    $oldName = basename(trim($oldName));
    $newName = trim($newName);

    if ($oldName === '' || $newName === '') {
        return ['success' => false, 'error' => 'Nom de fichier manquant.'];
    }
    if (strpbrk($newName, '/\\') !== false || basename($newName) !== $newName) {
        return ['success' => false, 'error' => 'Le nouveau nom ne doit pas contenir de dossier.'];
    }
    if (preg_match('/^[A-Za-z0-9 ._()+\\-]+$/', $newName) !== 1) {
        return ['success' => false, 'error' => 'Caracteres non autorises : lettres, chiffres, espace, . _ - ( ) +'];
    }

    $oldExtension = strtolower((string) pathinfo($oldName, PATHINFO_EXTENSION));
    $newExtension = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));

    if (!in_array($oldExtension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => "Le fichier source n'est pas une image reconnue."];
    }
    if (!in_array($newExtension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => 'Extension invalide. Utilisez : '
            . implode(', ', $allowedExtensions) . '.'];
    }

    $directory = __DIR__ . '/img/';
    $oldPath   = $directory . $oldName;
    $newPath   = $directory . $newName;

    if (!is_file($oldPath)) {
        return ['success' => false, 'error' => "Le fichier « {$oldName} » est introuvable."];
    }
    if (strcasecmp($oldName, $newName) === 0) {
        return ['success' => false, 'error' => "Le nouveau nom est identique a l'ancien."];
    }
    if (file_exists($newPath)) {
        return ['success' => false, 'error' => "Un fichier « {$newName} » existe deja dans img/."];
    }
    if (!@rename($oldPath, $newPath)) {
        return ['success' => false, 'error' => "Le renommage du fichier a echoue (droits du dossier img/ ?)."];
    }

    /* La base pointe encore vers l'ancien fichier : on la met a jour pour ne
       pas casser les composants qui l'utilisaient (chemin « img/nom » ou
       « nom » seul selon l'origine de la valeur). */
    try {
        $pdo  = getConnection();
        $stmt = $pdo->prepare('UPDATE `data` SET `image_path` = ? WHERE `image_path` IN (?, ?)');
        $stmt->execute(['img/' . $newName, 'img/' . $oldName, $oldName]);

        return [
            'success' => true,
            'old'     => $oldName,
            'new'     => $newName,
            'updated' => (int) $stmt->rowCount(),
        ];
    } catch (Throwable $e) {
        /* Le renommage disque est fait : on informe sans faire echouer
           l'operation, sinon l'utilisateur croirait le fichier perdu. */
        return [
            'success' => true,
            'old'     => $oldName,
            'new'     => $newName,
            'updated' => 0,
            'warning' => 'Fichier renomme, mais mise a jour des composants impossible : ' . $e->getMessage(),
        ];
    }
}

/**
 * Supprime un fichier image present dans img/.
 *
 * Le nom est volontairement restreint (pas de dossier, extension image) :
 * ce point d'ecriture ne doit pas permettre de sortir du repertoire img/.
 * Les composants qui pointaient vers ce fichier sont remis a l'etat « sans
 * image » afin de ne pas laisser de vignette cassee.
 */
function compozitDeletePackageImage(string $name): array
{
    $allowedExtensions = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    $name = basename(trim($name));

    if ($name === '') {
        return ['success' => false, 'error' => 'Nom de fichier manquant.'];
    }
    if (strpbrk($name, '/\\') !== false || basename($name) !== $name) {
        return ['success' => false, 'error' => 'Nom de fichier invalide.'];
    }

    $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['success' => false, 'error' => "Ce fichier n'est pas une image reconnue."];
    }

    $path = __DIR__ . '/img/' . $name;
    if (!is_file($path)) {
        return ['success' => false, 'error' => "Le fichier « {$name} » est introuvable."];
    }
    if (!@unlink($path)) {
        return ['success' => false, 'error' => "La suppression a echoue (droits du dossier img/ ?)."];
    }

    /* Le fichier n'existe plus : on remet a vide les composants qui le
       referencaient (chemin « img/nom » ou « nom » selon l'origine). */
    try {
        $pdo  = getConnection();
        $stmt = $pdo->prepare('UPDATE `data` SET `image_path` = ? WHERE `image_path` IN (?, ?)');
        $stmt->execute(['', 'img/' . $name, $name]);

        return [
            'success' => true,
            'name'    => $name,
            'updated' => (int) $stmt->rowCount(),
        ];
    } catch (Throwable $e) {
        /* Le fichier est supprime : on informe sans faire echouer
           l'operation, sinon l'utilisateur croirait la suppression ratee. */
        return [
            'success' => true,
            'name'    => $name,
            'updated' => 0,
            'warning' => 'Fichier supprime, mais mise a jour des composants impossible : ' . $e->getMessage(),
        ];
    }
}

try {
    $pdo = getConnection();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data WHERE (image_path IS NULL OR image_path = '' OR image_path = 'default.png')");
    $stmt->execute();
    $componentsWithoutImage = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM data 
        WHERE (image_path IS NULL OR image_path = '' OR image_path = 'default.png') 
        AND package IS NOT NULL AND package != ''
    ");
    $stmt->execute();
    $componentsWithPackage = $stmt->fetch()['count'];
    
    $imageDir = __DIR__ . '/img/';
    $availableImages = [];
    if (is_dir($imageDir)) {
        $files = scandir($imageDir);
        foreach ($files as $file) {
            if (preg_match('/\.(svg|png|jpg|jpeg|gif|webp)$/i', $file)) {
                $availableImages[] = $file;
            }
        }
    }

    /* Combien de composants pointent vers chaque image ? Cela permet d'avertir
       l'utilisateur avant une suppression (les composants concernes reviennent
       a l'etat « sans image »). */
    $imageUsage = [];
    $usageStmt = $pdo->prepare("
        SELECT SUBSTRING_INDEX(image_path, '/', -1) AS file_name, COUNT(*) AS total
        FROM data
        WHERE image_path IS NOT NULL AND image_path != ''
        GROUP BY file_name
    ");
    $usageStmt->execute();
    foreach ($usageStmt->fetchAll(PDO::FETCH_ASSOC) as $usageRow) {
        $imageUsage[(string) $usageRow['file_name']] = (int) $usageRow['total'];
    }
    
} catch (PDOException $e) {
    $error = "Erreur de base de données : " . $e->getMessage();
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
    <title>Gestionnaire d'Images de Packages - Gestion des Composants</title>
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --bg-muted: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            --accent-indigo: #6366f1;
            --accent-indigo-light: #e0e7ff;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-teal: #14b8a6;
            --accent-orange: #f97316;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container {
            max-width: 1480px;
            margin: 0 auto;
            padding: 20px;
        }
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
            top: -80px;
            right: -80px;
            width: 260px;
            height: 260px;
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
        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .header-icon {
            width: 52px;
            height: 52px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .header-title p {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 3px;
        }
        .user-chip {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2);
            font-size: 13px;
        }
        .logout-link {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
            position: relative;
            z-index: 2;
        }
        .nav-buttons a {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.2);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-buttons a:hover {
            background: rgba(255,255,255,0.28);
            transform: translateY(-1px);
        }
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
        .btn:active { transform: translateY(0); }
        .btn-indigo  { background: var(--accent-indigo); color: white; }
        .btn-purple  { background: var(--accent-purple); color: white; }
        .btn-danger  { background: var(--accent-red); color: white; }
        .btn-ghost   { background: var(--bg-muted); color: var(--text-secondary); }
        .btn-ghost:hover { background: var(--border-color); }
        .btn-sm      { padding: 6px 11px; font-size: 12px; border-radius: 6px; gap: 4px; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

        .btn-primary {
            background: var(--accent-indigo);
            color: white;
        }

        .btn-secondary {
            background: var(--accent-blue);
            color: white;
        }

        .btn-success {
            background: var(--accent-green);
            color: white;
        }

        .btn-info {
            background: var(--accent-teal);
            color: white;
        }

        .btn-warning {
            background: var(--accent-amber);
            color: var(--text-primary);
        }

        .content {
            padding: 30px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-subtle);
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            border: 2px solid var(--border-muted);
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1em;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .action-section {
            background: var(--bg-subtle);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            border: 2px solid var(--border-muted);
        }

        .action-section h3 {
            color: var(--text-primary);
            margin-bottom: 15px;
            font-size: 1.3em;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: bold;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .preview-table th,
        .preview-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .preview-table th {
            background: var(--bg-subtle);
            font-weight: bold;
        }

        .preview-table tr:hover {
            background: var(--bg-muted);
        }

        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .image-item {
            position: relative;
            text-align: center;
            padding: 10px;
            background: var(--bg-subtle);
            border-radius: 8px;
            border: 1px solid var(--border-muted);
        }

        .image-item img {
            max-width: 60px;
            max-height: 60px;
            margin-bottom: 5px;
        }

        .image-name {
            font-size: 0.8em;
            color: var(--text-secondary);
            word-break: break-all;
        }

        .image-rename-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            padding: 3px 7px;
            border: none;
            border-radius: 8px;
            background: rgba(17, 24, 39, 0.78);
            color: #ffffff;
            font-size: 13px;
            line-height: 1;
            cursor: pointer;
            opacity: 0.9;
        }

        .image-rename-btn:hover {
            background: #6366f1;
            opacity: 1;
        }

        .image-delete-btn {
            position: absolute;
            top: 6px;
            left: 6px;
            padding: 3px 7px;
            border: none;
            border-radius: 8px;
            background: rgba(17, 24, 39, 0.78);
            color: #ffffff;
            font-size: 13px;
            line-height: 1;
            cursor: pointer;
            opacity: 0.9;
        }

        .image-delete-btn:hover {
            background: #ef4444;
            opacity: 1;
        }

        .image-item[data-editing="1"] {
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 3px var(--accent-indigo-light);
        }

        .image-rename-input {
            width: 100%;
            box-sizing: border-box;
            padding: 3px 5px;
            border: 1px solid var(--accent-indigo);
            border-radius: 6px;
            background: var(--bg-card);
            color: var(--text-primary);
            font-size: 0.8em;
            text-align: center;
            outline: none;
        }

        .cz-img-toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            z-index: 9999;
            max-width: 90vw;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
            display: none;
        }

        .cz-img-toast.is-ok  { background: #10b981; color: #ffffff; }
        .cz-img-toast.is-err { background: #ef4444; color: #ffffff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">🖼️</div>
                <div>
                    <h1>Gestion des Images de Boîtiers</h1>
                    <p>Associez les images aux packages (DIP, SOIC, SOT…)</p>
                </div>
            </div>
            <div class="user-chip">
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
            </div>
        </div>
        <div class="nav-buttons">
            <a href="components.php">📦 Composants</a>
            <a href="create_component.php">➕ Créer</a>
            <a href="projects.php">🚀 Projets</a>
            <a href="settings.php">⚙️ Paramètres</a>
        </div>
        </div>
        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $componentsWithoutImage; ?></div>
                    <div class="stat-label">Composants sans image</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $componentsWithPackage; ?></div>
                    <div class="stat-label">Avec package défini</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="czImageCount"><?php echo count($availableImages); ?></div>
                    <div class="stat-label">Images disponibles</div>
                </div>
            </div>

            <div class="action-section">
                <h3>🚀 Actions</h3>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="preview_changes">
                    <button type="submit" class="btn btn-info">
                        👁️ Prévisualiser les changements
                    </button>
                </form>
                
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="run_auto_assign">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Êtes-vous sûr de vouloir exécuter l\'assignation automatique ?')">
                        ⚡ Exécuter l'assignation automatique
                    </button>
                </form>
                
                <a href="settings.php" class="btn btn-primary">
                    ⚙️ Retour aux paramètres
                </a>
            </div>

            <?php if (isset($previewWithImages) || isset($previewWithoutImages)): ?>
                <div class="action-section">
                    <h3>👁️ Prévisualisation des changements</h3>
                    
                    <?php if (!empty($previewWithImages)): ?>
                        <h4 style="color: #28a745; margin-top: 20px;">✅ Composants qui recevront une image (<?php echo count($previewWithImages); ?>)</h4>
                        <p>Ces composants recevront automatiquement une image de package :</p>
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom du composant</th>
                                    <th>Package</th>
                                    <th>Image actuelle</th>
                                    <th>Nouvelle image</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewWithImages as $component): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($component['id']); ?></td>
                                        <td><?php echo htmlspecialchars($component['name']); ?></td>
                                        <td><?php echo htmlspecialchars($component['package']); ?></td>
                                        <td><?php echo htmlspecialchars($component['image_path'] ?: 'Aucune'); ?></td>
                                        <td>
                                            <img src="<?php echo htmlspecialchars($component['new_image']); ?>" alt="Package" style="max-width: 40px; max-height: 40px;">
                                            <?php echo htmlspecialchars($component['new_image']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    
                    <?php if (!empty($previewWithoutImages)): ?>
                        <h4 style="color: #dc3545; margin-top: 30px;">❌ Composants sans image trouvée (<?php echo count($previewWithoutImages); ?>)</h4>
                        <p>Ces composants ont un package défini mais aucune image correspondante n'a été trouvée :</p>
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom du composant</th>
                                    <th>Package</th>
                                    <th>Image actuelle</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewWithoutImages as $component): ?>
                                    <tr style="background-color: #fff5f5;">
                                        <td><?php echo htmlspecialchars($component['id']); ?></td>
                                        <td><?php echo htmlspecialchars($component['name']); ?></td>
                                        <td><?php echo htmlspecialchars($component['package']); ?></td>
                                        <td><?php echo htmlspecialchars($component['image_path'] ?: 'Aucune'); ?></td>
                                        <td style="color: #dc3545;">❌ Aucune image trouvée</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="action-section">
                <h3>🖼️ Images de packages disponibles</h3>
                <p style="color: var(--text-secondary); font-size: 0.92em;">
                    ✏️ pour renommer le fichier, 🗑️ pour le supprimer. Le nom du fichier doit
                    correspondre au <strong>nom du package</strong> (ex. <code>DIP-8.png</code>)
                    pour que l'assignation automatique le retrouve.
                </p>
                <div class="images-grid">
                    <?php foreach ($availableImages as $image): ?>
                        <div class="image-item"
                             data-image-name="<?php echo htmlspecialchars($image, ENT_QUOTES); ?>"
                             data-image-usage="<?php echo (int) ($imageUsage[$image] ?? 0); ?>">
                            <button type="button" class="image-rename-btn" title="Renommer l'image"
                                    onclick="czStartRename('<?php echo htmlspecialchars($image, ENT_QUOTES); ?>')">✏️</button>
                            <button type="button" class="image-delete-btn" title="Supprimer l'image"
                                    onclick="czDeleteImage('<?php echo htmlspecialchars($image, ENT_QUOTES); ?>')">🗑️</button>
                            <img src="img/<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($image); ?>" onerror="this.style.display='none'">
                            <div class="image-name"><?php echo htmlspecialchars($image); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
    <script>
    /* Renommage d'une image de package : edition en place, sans rechargement.
       Le script est volontairement place hors de .container : le relais
       moderne reconstruit ce conteneur et effacerait un script situe dedans. */
    function czImageItem(name) {
        var items = document.querySelectorAll('.image-item');
        for (var i = 0; i < items.length; i++) {
            if (items[i].getAttribute('data-image-name') === name) { return items[i]; }
        }
        return null;
    }

    function czImgToast(text, ok) {
        var toast = document.getElementById('cz-img-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'cz-img-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = text;
        toast.className = 'cz-img-toast ' + (ok ? 'is-ok' : 'is-err');
        toast.style.display = 'block';
        window.clearTimeout(toast._timer);
        toast._timer = window.setTimeout(function () { toast.style.display = 'none'; }, 4000);
    }

    /* Passe la vignette en edition : le nom devient un champ de saisie.
       Entree valide, Echap annule, un clic ailleurs annule. */
    function czStartRename(current) {
        var item = czImageItem(current);
        if (!item || item.getAttribute('data-editing') === '1') { return; }

        var label = item.querySelector('.image-name');
        if (!label) { return; }
        item.setAttribute('data-editing', '1');

        var dot  = current.lastIndexOf('.');
        var base = dot > 0 ? current.slice(0, dot) : current;
        var ext  = dot > 0 ? current.slice(dot) : '';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'image-rename-input';
        input.value = base;
        label.textContent = '';
        label.appendChild(input);
        input.focus();
        input.select();

        var done = false;
        var cancel = function () {
            if (done) { return; }
            done = true;
            item.removeAttribute('data-editing');
            label.textContent = current;
        };
        var commit = function () {
            if (done) { return; }
            var value = input.value.trim();
            if (value === '') { cancel(); return; }
            /* L'extension est conservee si l'utilisateur ne la retape pas. */
            if (value.indexOf('.') < 0 && ext) { value += ext; }
            if (value === current) { cancel(); return; }
            done = true;
            item.removeAttribute('data-editing');
            czSubmitRename(item, current, value);
        };

        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); commit(); }
            else if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
        });
        input.addEventListener('blur', cancel);
    }

    function czSubmitRename(item, current, proposed) {
        var body = new URLSearchParams();
        body.append('action', 'rename_image');
        body.append('ajax', '1');
        body.append('old_name', current);
        body.append('new_name', proposed);

        var label = item.querySelector('.image-name');

        fetch('package_images_manager.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.success) {
                if (label) { label.textContent = current; }
                czImgToast('❌ ' + ((data && data.error) || 'Renommage impossible.'), false);
                return;
            }

            item.setAttribute('data-image-name', data.new);
            var image = item.querySelector('img');
            if (image) {
                image.src = 'img/' + data.new;
                image.alt = data.new;
            }
            if (label) { label.textContent = data.new; }
            var button = item.querySelector('.image-rename-btn');
            if (button) { button.onclick = function () { czStartRename(data.new); }; }

            var text = '« ' + data.old + ' » renommé en « ' + data.new + ' »';
            if (data.updated) { text += ' — ' + data.updated + ' composant(s) mis à jour'; }
            czImgToast('✅ ' + text, true);
            if (data.warning) { window.alert('⚠️ ' + data.warning); }
        }).catch(function () {
            if (label) { label.textContent = current; }
            czImgToast('❌ Erreur réseau pendant le renommage.', false);
        });
    }

    /* Suppression d'une image de package : confirmation explicite, puis
       appel en arriere-plan et retrait de la vignette sans rechargement. */
    function czDeleteImage(name) {
        var item = czImageItem(name);
        if (!item) { return; }

        var usage = parseInt(item.getAttribute('data-image-usage') || '0', 10) || 0;
        var message = 'Supprimer définitivement l\'image « ' + name + ' » ?';
        if (usage > 0) {
            message += '\n\n⚠️ ' + usage + ' composant(s) utilisent cette image.'
                     + '\nIls repasseront à l\'état « sans image ».';
        }
        if (!window.confirm(message)) { return; }

        var body = new URLSearchParams();
        body.append('action', 'delete_image');
        body.append('ajax', '1');
        body.append('image_name', name);

        fetch('package_images_manager.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data || !data.success) {
                czImgToast('❌ ' + ((data && data.error) || 'Suppression impossible.'), false);
                return;
            }

            if (item.parentNode) { item.parentNode.removeChild(item); }

            var counter = document.getElementById('czImageCount');
            if (counter) {
                var left = parseInt(counter.textContent, 10);
                if (!isNaN(left) && left > 0) { counter.textContent = left - 1; }
            }

            var text = 'Image « ' + data.name + ' » supprimée';
            if (data.updated) { text += ' — ' + data.updated + ' composant(s) réinitialisé(s)'; }
            czImgToast('✅ ' + text, true);
            if (data.warning) { window.alert('⚠️ ' + data.warning); }
        }).catch(function () {
            czImgToast('❌ Erreur réseau pendant la suppression.', false);
        });
    }
    </script>
</body>
</html>

