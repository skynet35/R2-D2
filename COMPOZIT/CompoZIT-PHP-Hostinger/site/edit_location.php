<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$pdo = getConnection();
$storageTypes = getLocationStorageTypes();

$error_message = '';
$location_id = intval($_GET['id'] ?? 0);

if ($location_id <= 0) {
    header('Location: locations.php?error=invalid');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM location WHERE id = ? AND owner = ?");
$stmt->execute([$location_id, $user_id]);
$location = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$location) {
    header('Location: locations.php?error=not_found');
    exit();
}

function cleanLocationFilename($name, $ext = null) {
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9._-]+/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');
    if ($ext !== null) {
        $name .= '.' . ltrim(strtolower($ext), '.');
    }
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $casier = trim($_POST['casier'] ?? '');
    $tiroir = trim($_POST['tiroir'] ?? '');
    $compartiment = trim($_POST['compartiment'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $storage_type = trim($_POST['storage_type'] ?? 'casier');
    $delete_logo = isset($_POST['delete_logo']) && $_POST['delete_logo'] === '1';

    if (!isset($storageTypes[$storage_type])) {
        $storage_type = 'casier';
    }

    $logo_path = $location['logo_path'] ?? null;

    if ($delete_logo) {
        if ($logo_path && file_exists($logo_path) && strpos(basename($logo_path), 'location_') === 0) {
            @unlink($logo_path);
        }
        $logo_path = null;
    }

    if (!$delete_logo) {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK && !empty($casier)) {
            $upload_dir = 'img/';

            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

            if (in_array($file_extension, $allowed_extensions)) {
                $filename = 'location_casier_' . cleanLocationFilename($casier) . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                    $old_logo = $location['logo_path'] ?? null;
                    if ($old_logo && file_exists($old_logo) && strpos(basename($old_logo), 'location_') === 0) {
                        @unlink($old_logo);
                    }
                    $logo_path = $target_path;
                }
            }
        } elseif (!empty($_POST['existing_logo'])) {
            $selected_logo = $_POST['existing_logo'];
            if (file_exists('img/' . basename($selected_logo))) {
                $old_logo = $location['logo_path'] ?? null;
                $new_logo = 'img/' . basename($selected_logo);
                if ($old_logo && $old_logo !== $new_logo && file_exists($old_logo) && strpos(basename($old_logo), 'location_') === 0) {
                    @unlink($old_logo);
                }
                $logo_path = $new_logo;
            }
        }
    }

    if (empty($casier) || empty($tiroir) || empty($compartiment)) {
        $error_message = 'Tous les champs obligatoires doivent être remplis.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ? AND id != ?");
            $stmt->execute([$user_id, $casier, $tiroir, $compartiment, $location_id]);

            if ($stmt->fetch()) {
                $error_message = 'Un emplacement avec ces coordonnées existe déjà.';
            } else {
                $stmt = $pdo->prepare("UPDATE location SET casier = ?, tiroir = ?, compartiment = ?, description = ?, storage_type = ?, logo_path = ? WHERE id = ? AND owner = ?");
                $stmt->execute([$casier, $tiroir, $compartiment, $description, $storage_type, $logo_path, $location_id, $user_id]);

                $stmt = $pdo->prepare("UPDATE location SET storage_type = ?, logo_path = ? WHERE owner = ? AND casier = ?");
                $stmt->execute([$storage_type, $logo_path, $user_id, $casier]);

                header('Location: locations.php?success=updated');
                exit();
            }
        } catch (PDOException $e) {
            $error_message = 'Erreur lors de la modification de l\'emplacement.';
        }
    }
} else {
    $_POST['casier'] = $location['casier'];
    $_POST['tiroir'] = $location['tiroir'];
    $_POST['compartiment'] = $location['compartiment'];
    $_POST['description'] = $location['description'];
    $_POST['storage_type'] = $location['storage_type'] ?? 'casier';
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
    <title>Modifier un Emplacement</title>
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
            margin: 0 auto 24px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.2);
            position: relative;
            overflow: hidden;
            max-width: 1480px;
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
        .btn-primary { background: var(--accent-green); color: white; }
        .btn-secondary { background: var(--accent-blue); color: white; }
        .btn-success { background: var(--accent-green); color: white; }
        .btn-info { background: var(--accent-teal); color: white; }
        .content {
            padding: 30px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            max-width: 720px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: all 0.15s;
            font-family: inherit;
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        .required {
            color: var(--accent-red);
        }
        .alert {
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
        }
        .alert-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
        }
        .info-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .info-box h3 {
            color: #92400e;
            margin-bottom: 10px;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-box p {
            color: #78350f;
            margin-bottom: 6px;
            font-size: 13.5px;
            line-height: 1.55;
        }
        .current-location {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .current-location h3 {
            color: #1e40af;
            margin-bottom: 10px;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .location-code {
            font-family: 'Courier New', monospace;
            background: var(--bg-muted);
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1.1em;
            display: inline-block;
            color: var(--text-primary);
        }
        .app-footer {
            margin-top: 2rem;
            padding: 1.2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 0.85em;
            display: block;
        }
        .small-muted {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
            display: block;
            line-height: 1.4;
        }
        .current-logo {
            max-width: 100px;
            max-height: 100px;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            padding: 4px;
            object-fit: contain;
        }
        .current-logo-box {
            margin-bottom: 15px;
            padding: 16px;
            border: 2px dashed var(--accent-green);
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        }
        .logo-option-box {
            margin-bottom: 15px;
            padding: 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-muted);
        }
        .logo-option-box h4 {
            margin: 0 0 10px 0;
            color: var(--text-primary);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">✏️</div>
                    <div>
                        <h1>Modifier un Emplacement</h1>
                        <p>Modifier les caractéristiques d'un emplacement existant</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="locations.php">← Emplacements</a>
                <a href="components.php">📦 Composants</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
        </div>
        <div class="content">
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="current-location">
                <h3>📍 Emplacement actuel</h3>
                <div class="location-code">
                    <?php
                        $isFlatLocation = in_array($location['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                        $locationCode = $isFlatLocation
                            ? $location['casier'] . '-' . $location['compartiment']
                            : $location['casier'] . '-' . $location['tiroir'] . '-' . $location['compartiment'];
                        echo htmlspecialchars($locationCode);
                    ?>
                </div>
            </div>

            <div class="info-box">
                <h3>⚠️ Attention</h3>
                <p>La modification de cet emplacement affectera tous les composants qui y sont stockés.</p>
                <p>Assurez-vous que les nouvelles coordonnées sont correctes.</p>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="casier">Casier <span class="required">*</span></label>
                    <input type="text" id="casier" name="casier" required 
                           placeholder="Ex: A, B, C1, etc." 
                           value="<?php echo htmlspecialchars($_POST['casier'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="tiroir">Tiroir <span class="required">*</span></label>
                    <input type="text" id="tiroir" name="tiroir" required 
                           placeholder="Ex: 1, 2, T1, etc." 
                           value="<?php echo htmlspecialchars($_POST['tiroir'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="compartiment">Compartiment <span class="required">*</span></label>
                    <input type="text" id="compartiment" name="compartiment" required 
                           placeholder="Ex: 1, 2, 3, 4" 
                           value="<?php echo htmlspecialchars($_POST['compartiment'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (optionnel)</label>
                    <textarea id="description" name="description" 
                              placeholder="Description de l'emplacement..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="storage_type">Type de rangement</label>
                    <select id="storage_type" name="storage_type">
                        <?php $selectedStorageType = $_POST['storage_type'] ?? 'casier'; ?>
                        <?php foreach ($storageTypes as $typeKey => $typeMeta): ?>
                            <option value="<?php echo htmlspecialchars($typeKey); ?>" <?php echo $selectedStorageType === $typeKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($typeMeta['icon'] . ' ' . $typeMeta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Logo personnalisé (optionnel)</label>
                    <?php if (!empty($location['logo_path']) && file_exists($location['logo_path'])): ?>
                        <div class="current-logo-box">
                            <p class="small-muted" style="margin-bottom: 8px;"><strong>Logo actuel du casier <?php echo htmlspecialchars($location['casier']); ?>:</strong></p>
                            <img src="<?php echo htmlspecialchars($location['logo_path']); ?>" alt="Logo actuel" class="current-logo">
                            <div style="margin-top: 10px;">
                                <label style="display:inline-flex;align-items:center;gap:6px;font-weight:500;font-size:13px;color:var(--accent-red);cursor:pointer;">
                                    <input type="checkbox" name="delete_logo" value="1" style="width:auto;margin:0;">
                                    Supprimer ce logo (revient à l'icône par défaut)
                                </label>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="small-muted" style="margin-bottom: 10px;">Aucun logo personnalisé défini. L'icône par défaut du type sera utilisée.</p>
                    <?php endif; ?>

                    <div class="logo-option-box">
                        <h4>📁 Choisir une image existante</h4>
                        <select name="existing_logo" id="existing_logo">
                            <option value="">-- Sélectionner une image du dossier img/ --</option>
                            <?php
                            $img_dir = 'img/';
                            if (is_dir($img_dir)) {
                                $images = glob($img_dir . '*.{jpg,jpeg,png,gif,webp,svg}', GLOB_BRACE);
                                $current_logo = $location['logo_path'] ?? '';
                                foreach ($images as $image) {
                                    $filename = basename($image);
                                    $selected = ($current_logo === $image) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($filename) . '" ' . $selected . '>' . htmlspecialchars($filename) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <div id="preview-container" style="margin-top: 10px;"></div>
                    </div>

                    <div class="logo-option-box">
                        <h4>📤 Ou uploader un nouveau fichier</h4>
                        <input type="file" id="logo" name="logo" accept="image/*,.svg" style="width: 100%;">
                        <span class="small-muted">Formats acceptés: JPG, PNG, GIF, WebP, SVG. Le logo sera appliqué à tout le casier.</span>
                    </div>

                    <span class="small-muted"><strong>Note:</strong> Si vous sélectionnez une image existante ET uploadez un nouveau fichier, le nouveau fichier sera prioritaire.</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Modifier l'Emplacement</button>
                    <a href="locations.php" class="btn btn-ghost">❌ Annuler</a>
                </div>
            </form>
        </div>
        <app-footer>Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0</app-footer>
    </div>
    <script>
        function previewExistingImage() {
            const select = document.getElementById('existing_logo');
            const previewContainer = document.getElementById('preview-container');
            if (select && previewContainer) {
                if (select.value) {
                    const imagePath = 'img/' + select.value;
                    previewContainer.innerHTML =
                        '<p style="margin:5px 0 6px 0;font-weight:600;color:var(--text-primary);font-size:13px;">Aperçu:</p>' +
                        '<img src="' + imagePath + '" alt="Aperçu" class="current-logo">';
                } else {
                    previewContainer.innerHTML = '';
                }
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            const existingLogoSelect = document.getElementById('existing_logo');
            if (existingLogoSelect) {
                existingLogoSelect.addEventListener('change', previewExistingImage);
                previewExistingImage();
            }
        });
    </script>
</body>
</html>


