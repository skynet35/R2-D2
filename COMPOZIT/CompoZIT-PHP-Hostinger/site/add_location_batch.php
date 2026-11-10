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
$success_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $casier = trim($_POST['casier'] ?? '');
    $premier_tiroir = intval($_POST['premier_tiroir'] ?? 10);
    $tiroirs_horizontal = intval($_POST['tiroirs_horizontal'] ?? 6);
    $tiroirs_vertical = intval($_POST['tiroirs_vertical'] ?? 3);
    $compartiments_par_tiroir = intval($_POST['compartiments_par_tiroir'] ?? 4);
    $description = trim($_POST['description'] ?? '');
    $storage_type = trim($_POST['storage_type'] ?? 'casier');

    if (!isset($storageTypes[$storage_type])) {
        $storage_type = 'casier';
    }
    $isFlatStorage = in_array($storage_type, ['boite', 'classeur', 'etagere'], true);
    
    if (empty($casier)) {
        $error_message = 'Le nom du casier est obligatoire.';
    } elseif ($tiroirs_horizontal < 1 || $tiroirs_horizontal > 20) {
        $error_message = 'Le nombre de tiroirs horizontaux doit être entre 1 et 20.';
    } elseif ($tiroirs_vertical < 1 || $tiroirs_vertical > 10) {
        $error_message = 'Le nombre de tiroirs verticaux doit être entre 1 et 10.';
    } elseif ($compartiments_par_tiroir < 1 || $compartiments_par_tiroir > 10) {
        $error_message = 'Le nombre de compartiments par tiroir doit être entre 1 et 10.';
    } else {
        try {
            $pdo->beginTransaction();
            
            $created_locations = [];
            $skipped_locations = [];
            $flatCompartmentIndex = 0;
            
            for ($ligne = 0; $ligne < $tiroirs_vertical; $ligne++) {
                $tiroir_ligne = $premier_tiroir + ($ligne * 10);
                
                for ($col = 0; $col < $tiroirs_horizontal; $col++) {
                    $tiroir = $tiroir_ligne + $col;
                    
                    for ($compartiment = 1; $compartiment <= $compartiments_par_tiroir; $compartiment++) {
                        $locationTiroir = $isFlatStorage ? '1' : (string)$tiroir;
                        $locationCompartment = $isFlatStorage ? ++$flatCompartmentIndex : (string)$compartiment;
                        $stmt = $pdo->prepare("SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ?");
                        $stmt->execute([$user_id, $casier, $locationTiroir, $locationCompartment]);
                        
                        if ($stmt->fetch()) {
                            $skipped_locations[] = $isFlatStorage
                                ? "$casier-$locationCompartment"
                                : "$casier-$tiroir-$compartiment";
                            continue;
                        }
                        
                        $location_description = $description
                            ? ($isFlatStorage
                                ? "$description (Emplacement $locationCompartment)"
                                : "$description (Ligne " . ($ligne + 1) . ", Tiroir $tiroir, Compartiment $compartiment)")
                            : "";
                        $stmt = $pdo->prepare("INSERT INTO location (owner, casier, tiroir, compartiment, description, storage_type) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$user_id, $casier, $locationTiroir, $locationCompartment, $location_description, $storage_type]);
                        
                        $created_locations[] = $isFlatStorage
                            ? "$casier-$locationCompartment"
                            : "$casier-$tiroir-$compartiment";
                        $success_count++;
                    }
                }
            }

            $stmt = $pdo->prepare("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?");
            $stmt->execute([$storage_type, $user_id, $casier]);
            
            $pdo->commit();
            
            if ($success_count > 0) {
                header('Location: locations.php?success=batch_added&count=' . $success_count);
                exit();
            } else {
                $error_message = 'Aucun emplacement créé. Tous les emplacements existent déjà.';
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = 'Erreur lors de la création des emplacements: ' . $e->getMessage();
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
    <title>Ajouter des Emplacements en Lot</title>
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
            max-width: 900px;
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
        .form-row {
            display: flex;
            gap: 15px;
        }
        .form-row .form-group {
            flex: 1;
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
        .preview-box {
            background: linear-gradient(180deg, var(--bg-primary) 0%, #eef2f7 100%);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-top: 20px;
        }
        .preview-box h4 {
            color: var(--text-secondary);
            margin-bottom: 14px;
            font-size: 14px;
            font-weight: 800;
        }
        .preview-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .preview-summary-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .preview-cabinet-wrap {
            overflow-x: auto;
            padding-bottom: 6px;
        }
        .preview-cabinet {
            min-width: max-content;
            padding: 16px;
            border-radius: 16px;
            background: linear-gradient(180deg, var(--border-strong) 0%, var(--text-muted) 100%);
            border: 2px solid var(--text-secondary);
            box-shadow:
                0 16px 30px rgba(15, 23, 42, 0.16),
                inset 0 1px 0 rgba(255,255,255,0.7),
                inset 0 -4px 8px rgba(0,0,0,0.12);
        }
        .preview-cabinet-grid {
            display: grid;
            gap: 12px;
            align-items: start;
        }
        .preview-drawer {
            width: 120px;
            border-radius: 12px;
            background: linear-gradient(180deg, #ffffff 0%, var(--bg-primary) 100%);
            border: 1px solid var(--border-strong);
            padding: 10px 10px 12px;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.9),
                0 4px 12px rgba(15, 23, 42, 0.08);
            position: relative;
        }
        .preview-drawer::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -1px;
            transform: translateX(-50%);
            width: 44px;
            height: 7px;
            border-radius: 0 0 8px 8px;
            background: linear-gradient(180deg, var(--text-secondary) 0%, #475569 100%);
            box-shadow: 0 3px 5px rgba(0,0,0,0.15);
        }
        .preview-drawer-head {
            display: flex;
            flex-direction: column;
            gap: 3px;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--border-strong);
            text-align: center;
        }
        .preview-drawer-title {
            font-size: 0.92rem;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.01em;
        }
        .preview-drawer-meta {
            font-size: 0.68rem;
            color: var(--text-secondary);
            font-weight: 700;
        }
        .preview-slots {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
        }
        .preview-slot {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 4px 6px;
            border-radius: 7px;
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #bfdbfe;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
            font-family: 'Courier New', monospace;
            font-size: 0.73rem;
            font-weight: 700;
            color: #1e3a8a;
            text-align: center;
            line-height: 1.15;
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
    <script>
        function updatePreview() {
            const casier = document.getElementById('casier').value || 'X';
            const premierTiroir = parseInt(document.getElementById('premier_tiroir').value) || 10;
            const tiroirsHorizontal = parseInt(document.getElementById('tiroirs_horizontal').value) || 6;
            const tiroirsVertical = parseInt(document.getElementById('tiroirs_vertical').value) || 3;
            const compartimentsParTiroir = parseInt(document.getElementById('compartiments_par_tiroir').value) || 4;
            const storageType = document.getElementById('storage_type').value;
            const isFlatStorage = ['boite', 'classeur', 'etagere'].includes(storageType);
            
            const previewDiv = document.getElementById('preview');
            const totalTiroirs = tiroirsHorizontal * tiroirsVertical;
            const totalCount = totalTiroirs * compartimentsParTiroir;
            let html = '<h4>Aperçu de la grappe qui sera créée :</h4>';

            if (isFlatStorage) {
                html += `
                    <div class="preview-summary">
                        <div class="preview-summary-item">🗄️ Conteneur : ${casier}</div>
                        <div class="preview-summary-item">📦 ${totalCount} cases numérotées à la suite</div>
                    </div>
                    <div class="preview-cabinet-wrap">
                        <div class="preview-cabinet">
                            <div class="preview-slots" style="grid-template-columns:repeat(8,minmax(90px,1fr));">
                `;
                for (let slot = 1; slot <= totalCount; slot++) {
                    html += `<div class="preview-slot">${casier}-${slot}</div>`;
                }
                html += `
                            </div>
                        </div>
                    </div>
                `;
                previewDiv.innerHTML = html;
                return;
            }

            html += `
                <div class="preview-summary">
                    <div class="preview-summary-item">🗄️ Casier : ${casier}</div>
                    <div class="preview-summary-item">↔️ ${tiroirsHorizontal} tiroirs horizontaux</div>
                    <div class="preview-summary-item">↕️ ${tiroirsVertical} lignes verticales</div>
                    <div class="preview-summary-item">🧩 ${compartimentsParTiroir} compartiments / tiroir</div>
                    <div class="preview-summary-item">📦 ${totalCount} emplacements au total</div>
                </div>
                <div class="preview-cabinet-wrap">
                    <div class="preview-cabinet">
                        <div class="preview-cabinet-grid" style="grid-template-columns: repeat(${tiroirsHorizontal}, 120px);">
            `;

            for (let ligne = 0; ligne < tiroirsVertical; ligne++) {
                const tiroirLigne = premierTiroir + (ligne * 10);
                for (let col = 0; col < tiroirsHorizontal; col++) {
                    const tiroir = tiroirLigne + col;
                    html += `
                        <div class="preview-drawer">
                            <div class="preview-drawer-head">
                                <div class="preview-drawer-title">${casier}${tiroir}</div>
                                <div class="preview-drawer-meta">Tiroir ${ligne + 1}.${col + 1}</div>
                            </div>
                            <div class="preview-slots">
                    `;

                    for (let compartiment = 1; compartiment <= compartimentsParTiroir; compartiment++) {
                        html += `<div class="preview-slot">${casier}${tiroir}-${compartiment}</div>`;
                    }

                    html += `
                            </div>
                        </div>
                    `;
                }
            }

            html += `
                        </div>
                    </div>
                </div>
            `;
            previewDiv.innerHTML = html;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = ['casier', 'premier_tiroir', 'tiroirs_horizontal', 'tiroirs_vertical', 'compartiments_par_tiroir', 'storage_type'];
            inputs.forEach(id => {
                document.getElementById(id).addEventListener('input', updatePreview);
            });
            updatePreview();
        });
    </script>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🔢</div>
                    <div>
                        <h1>Ajouter des Emplacements en Lot</h1>
                        <p>Génération rapide d'emplacements multiples</p>
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
                <h3>ℹ️ Création par grille</h3>
                <p><strong>Principe:</strong> créer une vraie grappe visuelle de tiroirs, organisée en lignes et colonnes, avec les compartiments de chaque tiroir.</p>
                <p><strong>Exemple:</strong> Premier tiroir A10, 6 tiroirs horizontaux, 3 lignes verticales, 4 compartiments par tiroir</p>
                <p><strong>Résultat:</strong> l’aperçu ci-dessous reprend exactement la forme de la grappe qui sera créée.</p>
                <p>• Ligne 1: A10-1, A10-2, A10-3, A10-4, A11-1, A11-2, A11-3, A11-4, etc.</p>
                <p>• Ligne 2: A20-1, A20-2, A20-3, A20-4, A21-1, A21-2, A21-3, A21-4, etc.</p>
                <p>• Ligne 3: A30-1, A30-2, A30-3, A30-4, A31-1, A31-2, A31-3, A31-4, etc.</p>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="casier">Nom du Casier <span class="required">*</span></label>
                    <input type="text" id="casier" name="casier" required 
                           placeholder="Ex: A, B, C1, ELEC1, etc." 
                           value="<?php echo htmlspecialchars($_POST['casier'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="premier_tiroir">Premier Tiroir <span class="required">*</span></label>
                    <input type="number" id="premier_tiroir" name="premier_tiroir" 
                           min="1" max="99" required 
                           placeholder="Ex: 10 pour commencer par A10"
                           value="<?php echo htmlspecialchars($_POST['premier_tiroir'] ?? '10'); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="tiroirs_horizontal">Tiroirs Horizontaux <span class="required">*</span></label>
                        <input type="number" id="tiroirs_horizontal" name="tiroirs_horizontal" 
                               min="1" max="20" required 
                               placeholder="Nombre par ligne"
                               value="<?php echo htmlspecialchars($_POST['tiroirs_horizontal'] ?? '6'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="tiroirs_vertical">Lignes Verticales <span class="required">*</span></label>
                        <input type="number" id="tiroirs_vertical" name="tiroirs_vertical" 
                               min="1" max="10" required 
                               placeholder="Nombre de lignes"
                               value="<?php echo htmlspecialchars($_POST['tiroirs_vertical'] ?? '3'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="compartiments_par_tiroir">Compartiments par Tiroir <span class="required">*</span></label>
                    <input type="number" id="compartiments_par_tiroir" name="compartiments_par_tiroir" 
                           min="1" max="10" required 
                           placeholder="Nombre de compartiments par tiroir"
                           value="<?php echo htmlspecialchars($_POST['compartiments_par_tiroir'] ?? '4'); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (optionnel)</label>
                    <textarea id="description" name="description" 
                              placeholder="Description générale pour tous les emplacements..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
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

                <div class="preview-box" id="preview">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">🚀 Créer tous les Emplacements</button>
                    <a href="locations.php" class="btn btn-ghost">❌ Annuler</a>
                </div>
            </form>
        </div>
        <app-footer>Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0</app-footer>
    </div>
</body>
</html>


