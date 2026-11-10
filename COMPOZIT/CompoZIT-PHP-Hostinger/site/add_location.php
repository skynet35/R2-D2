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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $casier = trim($_POST['casier'] ?? '');
    $tiroir = trim($_POST['tiroir'] ?? '');
    $compartiment = trim($_POST['compartiment'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $storage_type = trim($_POST['storage_type'] ?? 'casier');

    if (!isset($storageTypes[$storage_type])) {
        $storage_type = 'casier';
    }
    
    if (empty($casier) || empty($tiroir) || empty($compartiment)) {
        $error_message = 'Tous les champs obligatoires doivent être remplis.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ?");
            $stmt->execute([$user_id, $casier, $tiroir, $compartiment]);
            
            if ($stmt->fetch()) {
                header('Location: locations.php?error=exists');
                exit();
            }
            
            $stmt = $pdo->prepare("INSERT INTO location (owner, casier, tiroir, compartiment, description, storage_type) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $casier, $tiroir, $compartiment, $description, $storage_type]);

            $stmt = $pdo->prepare("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?");
            $stmt->execute([$storage_type, $user_id, $casier]);
            
            header('Location: locations.php?success=added');
            exit();
        } catch (PDOException $e) {
            $error_message = 'Erreur lors de l\'ajout de l\'emplacement.';
        }
    }
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
    <title>Ajouter un Emplacement</title>
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
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 24px;
        }
        .info-box h3 {
            color: #1e40af;
            margin-bottom: 10px;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-box p {
            color: #374151;
            margin-bottom: 6px;
            font-size: 13.5px;
            line-height: 1.55;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🗄️</div>
                    <div>
                        <h1>Ajouter un Emplacement</h1>
                        <p>Créer un nouveau casier / tiroir / compartiment</p>
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

            <div class="info-box">
                <h3>ℹ️ Information sur les emplacements</h3>
                <p><strong>Casier:</strong> Identifiant du casier principal (ex: A, B, C1, etc.)</p>
                <p><strong>Tiroir:</strong> Numéro ou nom du tiroir dans le casier (ex: 1, 2, T1, etc.)</p>
                <p><strong>Compartiment:</strong> Numéro du compartiment dans le tiroir (ex: 1, 2, 3, 4)</p>
                <p><strong>Code final:</strong> Casier-Tiroir-Compartiment (ex: A-1-1, B-2-3)</p>
            </div>

            <form method="POST">
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
                    <label for="storage_type">Type de rangement / logo</label>
                    <select id="storage_type" name="storage_type">
                        <?php $selectedStorageType = $_POST['storage_type'] ?? 'casier'; ?>
                        <?php foreach ($storageTypes as $typeKey => $typeMeta): ?>
                            <option value="<?php echo htmlspecialchars($typeKey); ?>" <?php echo $selectedStorageType === $typeKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($typeMeta['icon'] . ' ' . $typeMeta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Ajouter l'Emplacement</button>
                    <a href="locations.php" class="btn btn-ghost">❌ Annuler</a>
                </div>
            </form>
        </div>
        <app-footer>Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0</app-footer>
    </div>
</body>
</html>


