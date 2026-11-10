<?php
require_once 'session_init.php';
require_once 'config.php';
require_once 'nexar_api.php';
require_once 'mouser_api.php';
require_once 'farnell_api.php';
require_once 'digikey_api.php';
require_once 'tme_api.php';
require_once 'lcsc_api.php';
require_once 'rs_api.php';
require_once 'component_params.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Connexion à la base de données
try {
    $pdo = getConnection();
} catch(PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer les catégories pour le formulaire d'ajout
$stmt = $pdo->query("SELECT * FROM category_head ORDER BY name");
$categories = $stmt->fetchAll();

// Récupérer les sous-catégories
$stmt = $pdo->query("SELECT cs.*, ch.name as parent_name FROM category_sub cs LEFT JOIN category_head ch ON cs.category_head_id = ch.id ORDER BY cs.category_head_id, cs.name");
$subcategories = $stmt->fetchAll();
$paramDefinitionsMap = compozitGetAllParamDefinitionsMap($pdo);

// Récupérer les emplacements de l'utilisateur avec le nombre de composants
$stmt = $pdo->prepare("SELECT l.*, COUNT(d.id) as component_count 
                      FROM location l 
                      LEFT JOIN data d ON l.id = d.location_id AND d.owner = ?
                      WHERE l.owner = ? 
                      GROUP BY l.id 
                      ORDER BY 
                        CASE WHEN COUNT(d.id) = 0 THEN 0 ELSE 1 END,
                        l.casier, l.tiroir, l.compartiment");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$locations = $stmt->fetchAll();

// Récupérer les fabricants de l'utilisateur
$stmt = $pdo->prepare("SELECT name FROM manufacturers WHERE owner = ? ORDER BY name");
$stmt->execute([$_SESSION['user_id']]);
$manufacturers = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les fournisseurs
$stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Créer un Composant</title>
    <style>
        :root {
            --bg-primary: #f5f7fa;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --text-muted: #718096;
            --accent-indigo: #667eea;
            --accent-purple: #764ba2;
            --accent-green: #48bb78;
            --accent-red: #f56565;
            --accent-amber: #ed8936;
            --accent-teal: #38b2ac;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f0f2f5 0%, #e6e9ef 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }

        .container {
            max-width: 1480px;
            margin: 0 auto;
            padding: 20px;
        }

        .app-header {
            background: linear-gradient(135deg, var(--accent-indigo) 0%, var(--accent-purple) 100%);
            color: white;
            padding: 28px 40px 22px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
            box-shadow: var(--shadow-lg);
        }

        .app-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
            pointer-events: none;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 1;
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
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(14px);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 4px 0;
            letter-spacing: -0.3px;
        }

        .header-title p {
            font-size: 13px;
            opacity: 0.85;
            margin: 0;
            line-height: 1.4;
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(14px);
            padding: 8px 12px 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            font-size: 13px;
        }

        .user-chip span {
            color: white;
            font-weight: 500;
        }

        .logout-link {
            background: rgba(255, 255, 255, 0.22);
            color: white;
            padding: 6px 12px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s ease;
            border: 1px solid rgba(255, 255, 255, 0.28);
        }

        .logout-link:hover {
            background: rgba(255, 255, 255, 0.34);
            transform: translateY(-1px);
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
            position: relative;
            z-index: 1;
            flex-wrap: wrap;
        }

        .nav-buttons a {
            background: rgba(255, 255, 255, 0.16);
            color: white;
            padding: 10px 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13.5px;
            transition: all 0.25s ease;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.24);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .nav-buttons a:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.14);
        }

        .nav-buttons a.active {
            background: rgba(255, 255, 255, 0.38);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
            border-color: rgba(255, 255, 255, 0.45);
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 13.5px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            line-height: 1.3;
        }

        .btn:hover {
            transform: translateY(-1.5px);
            box-shadow: 0 5px 14px rgba(0, 0, 0, 0.15);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-indigo {
            background: linear-gradient(135deg, var(--accent-indigo) 0%, var(--accent-purple) 100%);
            color: white;
        }

        .btn-purple {
            background: linear-gradient(135deg, #9f7aea 0%, var(--accent-purple) 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #fc8181 0%, var(--accent-red) 100%);
            color: white;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .btn-ghost:hover {
            background: #f7fafc;
            color: var(--text-primary);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-indigo) 0%, var(--accent-purple) 100%);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #68d391 0%, var(--accent-green) 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #4fd1c5 0%, var(--accent-teal) 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f6ad55 0%, var(--accent-amber) 100%);
            color: white;
        }

        .content {
            padding: 30px;
        }

        .form-container {
            background: var(--bg-subtle);
            padding: 30px;
            border-radius: 10px;
            border: 2px solid #dee2e6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        /* Styles pour les emplacements */
        .location-available {
            background-color: #d4edda !important;
            color: #155724 !important;
        }
        
        .location-occupied {
            background-color: #f8d7da !important;
            color: #721c24 !important;
        }
        
        /* Styles pour l'autocomplétion */
        .autocomplete-field {
            position: relative;
        }

        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 5px 5px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .suggestion-item {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }
        
        .suggestion-item:hover {
            background-color: var(--bg-muted);
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
        }
        
        /* Styles pour les images existantes */
        .existing-images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .existing-image-item {
            border: 2px solid var(--border-color);
            border-radius: 5px;
            padding: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .existing-image-item:hover {
            border-color: #667eea;
        }
        
        .existing-image-item.selected {
            border-color: #667eea;
            background-color: #f0f4ff;
        }
        
        .existing-image-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 3px;
        }
        
        .existing-image-item .image-name {
            font-size: 10px;
            margin-top: 5px;
            color: var(--text-secondary);
            word-break: break-word;
        }
        
        .form-group {
            position: relative;
        }

        .dynamic-params-group {
            grid-column: 1 / -1;
            margin-top: 4px;
            padding: 18px;
            border: 1px solid #28416d;
            border-radius: var(--radius-md);
            background: linear-gradient(180deg, #0a1930 0%, #0d1f3b 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03), 0 8px 22px rgba(0, 0, 0, 0.18);
        }

        .dynamic-params-head {
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(111, 149, 221, 0.18);
        }

        .dynamic-params-head strong {
            display: block;
            margin-bottom: 4px;
            color: #eef4ff;
            font-size: 15px;
        }

        .dynamic-params-head span {
            color: #9eb0d3;
            font-size: 13px;
        }

        .dynamic-params-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
        }

        .dynamic-param-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            color: #dfe8f8;
        }

        .dynamic-param-meta {
            font-size: 12px;
            color: #88a2d5;
            font-weight: 600;
        }

        .dynamic-params-group input,
        .dynamic-params-group select,
        .dynamic-params-group textarea {
            background: #08162b;
            border: 1px solid #20385e;
            color: #eef4ff;
        }

        .dynamic-params-group input:focus,
        .dynamic-params-group select:focus,
        .dynamic-params-group textarea:focus {
            border-color: #6e88c4;
            box-shadow: 0 0 0 3px rgba(110, 136, 196, 0.22);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .user-chip {
                width: 100%;
                justify-content: center;
                margin-bottom: 10px;
            }
            
            .header-top {
                flex-direction: column;
                gap: 14px;
            }
            
            .nav-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }

            .dynamic-params-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Styles pour l'import Nexar / Octopart */
        .nexar-panel {
            margin-bottom: 25px;
        }

        .nexar-hint {
            margin: 0 0 15px;
            color: var(--text-muted);
            font-size: 13.5px;
        }

        /* Lien de datasheet de repli proposé quand l'API n'en fournit pas */
        .datasheet-hint {
            margin-top: 8px;
            padding: 8px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius-sm);
            color: #92400e;
            font-size: 12.5px;
        }

        .datasheet-tools {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .datasheet-tool-btn {
            padding: 8px 12px;
            border: 1px solid #27406b;
            border-radius: var(--radius-sm);
            background: #0c1c36;
            color: #e8eefb;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .datasheet-tool-btn:hover:not(:disabled) {
            border-color: #5f79b8;
            background: #13284b;
        }

        .datasheet-tool-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .datasheet-preview-box {
            margin-top: 12px;
            display: none;
            border: 1px solid #27406b;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: #09162a;
        }

        .datasheet-preview-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #1f3355;
            background: #0d1f3b;
            color: #e8eefb;
            font-size: 13px;
        }

        .datasheet-preview-note {
            color: #93a4c0;
            font-size: 12px;
        }

        .datasheet-preview-frame {
            width: 100%;
            height: 520px;
            border: 0;
            background: #ffffff;
        }

        /* Note contextuelle affichée sous un champ de formulaire */
        .field-hint {
            margin-top: 6px;
            padding: 6px 10px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius-sm);
            color: #92400e;
            font-size: 12px;
        }

        /* Avertissement de doublon : la référence existe déjà dans l'inventaire */
        .duplicate-warning {
            margin-top: 12px;
            padding: 10px 14px;
            background: #fff7ed;
            border: 1px solid #fdba74;
            border-radius: var(--radius-sm);
            color: #9a3412;
            font-size: 13px;
            line-height: 1.5;
        }

        .duplicate-warning a {
            color: #9a3412;
            font-weight: 600;
        }

        .dup-add-btn {
            margin-left: 6px;
            padding: 3px 9px;
            border: 1px solid #fdba74;
            border-radius: var(--radius-sm);
            background: #ffffff;
            color: #9a3412;
            font-size: 12px;
            cursor: pointer;
        }

        .dup-add-btn:hover {
            background: #ffedd5;
        }

        .nexar-search-bar {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .nexar-help-link {
            flex: 0 0 auto;
            white-space: nowrap;
            font-size: 13px;
            font-weight: 600;
            color: var(--accent-indigo);
            text-decoration: none;
            padding: 8px 10px;
            border: 1px solid #dbe1f5;
            border-radius: 5px;
            background: #f6f8ff;
        }

        .nexar-help-link:hover {
            background: #eef2ff;
            text-decoration: none;
        }

        .nexar-search-bar input {
            flex: 1;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }

        .nexar-source-select {
            flex: 0 0 auto;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            background: var(--field-bg);
            color: var(--text-primary);
            cursor: pointer;
        }

        .nexar-source-select:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .nexar-search-bar input:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .nexar-status {
            margin-top: 12px;
            font-size: 13.5px;
            color: var(--text-secondary);
        }

        .nexar-results {
            margin-top: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .nexar-result {
            display: flex;
            gap: 15px;
            align-items: flex-start;
            background: var(--bg-card);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
        }

        .nexar-result-thumb {
            width: 70px;
            height: 70px;
            flex: 0 0 70px;
            object-fit: contain;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 5px;
        }

        .nexar-result-body {
            flex: 1;
            min-width: 0;
        }

        .nexar-result-title {
            font-weight: bold;
            color: var(--text-primary);
        }

        .nexar-result-meta,
        .nexar-result-desc {
            font-size: 12.5px;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        .nexar-result-desc {
            color: var(--text-muted);
        }

        .nexar-badge {
            display: inline-block;
            background: #eef2ff;
            color: var(--accent-indigo);
            border-radius: 4px;
            padding: 1px 6px;
            margin-right: 5px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .nexar-source-badge {
            display: inline-block;
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            border-radius: 4px;
            padding: 0 5px;
            margin-left: 6px;
            font-size: 10.5px;
            font-weight: 600;
            vertical-align: middle;
        }

        .nexar-result-action {
            flex: 0 0 auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">➕</div>
                <div>
                    <h1>Créer un Composant</h1>
                    <p>Ajouter un nouveau composant électronique à l'inventaire</p>
                </div>
            </div>
            <div class="user-chip">
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
            </div>
        </div>
        <div class="nav-buttons">
            <a href="components.php">📦 Composants</a>
            <a href="create_component.php" class="active">➕ Créer</a>
            <a href="marking.php">🔡 Marquage</a>
            <a href="projects.php">🚀 Projets</a>
            <a href="settings.php">⚙️ Paramètres</a>
        </div>
        </div>
        <div class="content">
            <!-- Messages de succès et d'erreur -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <?php
                    switch($_GET['success']) {
                        case 'component_added':
                            echo "✅ Composant ajouté avec succès !";
                            break;
                        default:
                            echo "✅ Opération réussie !";
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <?php
                    switch($_GET['error']) {
                        case 'name_required':
                            echo "❌ Le nom du composant est obligatoire.";
                            break;
                        case 'invalid_quantity':
                            echo "❌ La quantité doit être un nombre positif.";
                            break;
                        case 'add_failed':
                            echo "❌ Erreur lors de l'ajout du composant. Veuillez réessayer.";
                            break;
                        case 'database_error':
                            echo "❌ Erreur de base de données.";
                            if (isset($_GET['message']) && !empty($_GET['message'])) {
                                echo "<br><small style='opacity:0.8;'>Détail : " . htmlspecialchars($_GET['message']) . "</small>";
                            }
                            break;
                        default:
                            echo "❌ Une erreur s'est produite.";
                    }
                    ?>
                </div>
            <?php endif; ?>

            <!-- Import via une API fournisseur (Nexar / Octopart, Mouser, Farnell…) -->
            <?php
            $apiSources = [];
            if (nexarIsConfigured()) {
                $apiSources['nexar'] = 'Nexar / Octopart';
            }
            if (mouserIsConfigured()) {
                $apiSources['mouser'] = 'Mouser';
            }
            if (farnellIsConfigured()) {
                $apiSources['farnell'] = 'Farnell / element14';
            }
            if (digikeyIsConfigured()) {
                $apiSources['digikey'] = 'DigiKey';
            }
            if (tmeIsConfigured()) {
                $apiSources['tme'] = 'TME';
            }
            if (lcscIsConfigured()) {
                $apiSources['lcsc'] = 'LCSC Electronics';
            }
            if (rsIsConfigured()) {
                $apiSources['rs'] = 'RS Components';
            }
            ?>
            <?php if (!empty($apiSources)): ?>
            <div class="form-container nexar-panel">
                <h3>🔎 Importer via une API fournisseur</h3>
                <p class="nexar-hint">Saisissez une référence fabricant (MPN), une référence distributeur ou un mot-clé : le formulaire ci-dessous sera pré-rempli automatiquement.</p>
                <div class="nexar-search-bar">
                    <?php if (count($apiSources) > 1): ?>
                    <select id="api_source" class="nexar-source-select" title="Source de recherche">
                        <option value="all">🔀 Toutes les sources (résultats fusionnés)</option>
                        <?php foreach ($apiSources as $apiSourceKey => $apiSourceLabel): ?>
                            <option value="<?php echo htmlspecialchars($apiSourceKey); ?>"><?php echo htmlspecialchars($apiSourceLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" id="api_source" value="<?php echo htmlspecialchars((string) array_key_first($apiSources)); ?>">
                    <?php endif; ?>
                    <input type="text" id="nexar_query" placeholder="Ex : LM358, IRFB7430PBF, 489-0043 (réf. RS)" autocomplete="off"
                           onkeydown="if (event.key === 'Enter') { event.preventDefault(); nexarSearch(); }">
                    <button type="button" class="btn btn-primary" id="nexar_search_btn" onclick="nexarSearch()">🔎 Rechercher</button>
                    <a id="source_help_link" class="nexar-help-link" href="api_settings.php" target="_blank" rel="noopener"
                       title="Ouvrir le tutoriel de configuration de cette source">📘 Aide</a>
                </div>
                <div id="nexar_status" class="nexar-status"></div>
                <div id="duplicate_warning" class="duplicate-warning" style="display: none;"></div>
                <div id="nexar_results" class="nexar-results"></div>
            </div>
            <?php endif; ?>

            <!-- Formulaire de création -->
            <div class="form-container">
                <h3>📝 Informations du composant</h3>
                <form action="add_component.php" method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Nom du composant *</label>
                            <div class="autocomplete-field">
                                <input type="text" id="name" name="name" required placeholder="Ex: Arduino Uno R3" autocomplete="off">
                                <div id="name-suggestions" class="autocomplete-suggestions"></div>
                            </div>
                            <div id="name-duplicate-warning" class="duplicate-warning" style="display: none;"></div>
                        </div>
                        <div class="form-group">
                            <label for="marking_code">Code de marquage (CMS)</label>
                            <div class="autocomplete-field">
                                <input type="text" id="marking_code" name="marking" autocomplete="off" spellcheck="false"
                                       placeholder="Code lu sur le boîtier : 65V, J1, 2A…">
                                <div id="marking-suggestions" class="autocomplete-suggestions"></div>
                            </div>
                            <div class="field-hint">
                                Saisissez le code imprimé sur le boîtier : le nom, le fabricant
                                et le boîtier sont complétés automatiquement.
                                <a href="marking.php">Recherche complète</a>.
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="manufacturer">Fabricant</label>
                            <select id="manufacturer" name="manufacturer" onchange="toggleNewManufacturer()">
                                <option value="">Sélectionner un fabricant</option>
                                <?php foreach ($manufacturers as $manufacturer): ?>
                                    <option value="<?php echo htmlspecialchars($manufacturer); ?>">
                                        <?php echo htmlspecialchars($manufacturer); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__new__">➕ Nouveau fabricant</option>
                            </select>
                        </div>
                        <div class="form-group" id="new_manufacturer_group" style="display: none;">
                            <label for="new_manufacturer">Nom du nouveau fabricant</label>
                            <input type="text" id="new_manufacturer" name="new_manufacturer" placeholder="Ex: Arduino">
                        </div>
                        <div class="form-group">
                            <label for="package">Package</label>
                            <input type="text" id="package" name="package" placeholder="Ex: DIP-28, SOIC-8" autocomplete="off">
                            <div id="package-suggestions" class="autocomplete-suggestions"></div>
                        </div>
                        <div class="form-group">
                            <label for="pins">Nombre de pins</label>
                            <input type="number" id="pins" name="pins" placeholder="Ex: 14">
                        </div>
                        <div class="form-group">
                            <label for="smd">SMD</label>
                            <select id="smd" name="smd">
                                <option value="No">Non</option>
                                <option value="Yes">Oui</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantity">Quantité *</label>
                            <input type="number" id="quantity" name="quantity" value="1" required min="1">
                        </div>
                        <div class="form-group">
                            <label for="order_quantity">Quantité à commander</label>
                            <input type="number" id="order_quantity" name="order_quantity" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label for="price">Prix (€ HT)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" placeholder="Ex: 2.50">
                            <div id="price_hint" class="field-hint" style="display: none;"></div>
                        </div>
                        <div class="form-group">
                            <label for="supplier_id">Fournisseur</label>
                            <select id="supplier_id" name="supplier_id" onchange="toggleNewSupplier()">
                                <option value="">Sélectionner un fournisseur</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>">
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__new__">➕ Nouveau fournisseur</option>
                            </select>
                        </div>
                        <div class="form-group" id="new_supplier_group" style="display: none;">
                            <label for="new_supplier_name">Nom du nouveau fournisseur</label>
                            <input type="text" id="new_supplier_name" name="new_supplier_name" placeholder="Ex: RS Components">
                        </div>
                        <div class="form-group">
                            <label for="location_id">Emplacement</label>
                            <select id="location_id" name="location_id">
                                <option value="">Sélectionner un emplacement</option>
                                <?php foreach ($locations as $location): ?>
                                    <?php 
                                    $isOccupied = $location['component_count'] > 0;
                                    $statusClass = $isOccupied ? 'location-occupied' : 'location-available';
                                    $statusText = $isOccupied ? ' (Occupé)' : ' (Disponible)';
                                    $isFlatLocation = in_array($location['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                    $locationLabel = $isFlatLocation
                                        ? $location['casier'] . '-' . $location['compartiment']
                                        : $location['casier'] . $location['tiroir'] . '-' . $location['compartiment'];
                                    ?>
                                    <option value="<?php echo $location['id']; ?>" class="<?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($locationLabel); ?><?php echo $statusText; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="category_head">Catégorie principale</label>
                            <select id="category_head" name="category_head" onchange="updateSubcategories()">
                                <option value="">Sélectionner une catégorie</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="category">Sous-catégorie</label>
                            <select id="category" name="category" onchange="loadSubcategoryImages()">
                                <option value="">Sélectionner d'abord une catégorie principale</option>
                            </select>
                        </div>
                        <div class="form-group dynamic-params-group" id="dynamic_params_group" style="display: none;">
                            <div class="dynamic-params-head">
                                <strong>Paramètres techniques Diodes</strong>
                                <span>Ces champs supplémentaires servent à décrire précisément le composant et à rechercher des équivalents.</span>
                            </div>
                            <div class="dynamic-params-grid" id="dynamic_params_container"></div>
                        </div>
                        <div class="form-group">
                            <label for="supplier_reference">Référence fournisseur</label>
                            <input type="text" id="supplier_reference" name="supplier_reference" placeholder="Ex: REF-12345">
                        </div>
                    </div>
                    <div class="form-group">
                            <label for="datasheet">Datasheet (URL)</label>
                            <input type="url" id="datasheet" name="datasheet" placeholder="https://exemple.com/datasheet.pdf">
                            <div id="datasheet_hint" class="datasheet-hint" style="display: none;"></div>
                            <div class="datasheet-tools">
                                <button type="button" id="datasheet_open_btn" class="datasheet-tool-btn" disabled onclick="openDatasheetLink()">📄 Ouvrir la datasheet</button>
                                <button type="button" id="datasheet_preview_btn" class="datasheet-tool-btn" disabled onclick="toggleDatasheetPreview()">👁️ Aperçu intégré</button>
                            </div>
                            <div id="datasheet_preview_box" class="datasheet-preview-box">
                                <div class="datasheet-preview-head">
                                    <strong id="datasheet_preview_title">Aperçu datasheet</strong>
                                    <span class="datasheet-preview-note">Si le site bloque l'intégration, utilisez « Ouvrir la datasheet ».</span>
                                </div>
                                <iframe id="datasheet_preview_frame" class="datasheet-preview-frame" loading="lazy" referrerpolicy="no-referrer"></iframe>
                            </div>

                            <label for="datasheet_pdf" style="margin-top: 14px;">Datasheet (fichier PDF) — optionnel</label>
                            <input type="file" id="datasheet_pdf" name="datasheet_pdf" accept=".pdf,application/pdf">
                            <div id="datasheet_pdf_hint" class="field-hint" style="display: none;"></div>
                        </div>

                        <div class="form-group">
                            <label for="public">
                                <input type="checkbox" id="public" name="public" value="1">
                                Composant public
                            </label>
                        </div>
                    <div class="form-group">
                        <label for="image_type">Type d'image</label>
                        <select id="image_type" name="image_type" onchange="toggleImageInput()">
                            <option value="">Aucune image</option>
                            <option value="existing">Utiliser une image existante</option>
                            <option value="upload">Importer une image</option>
                            <option value="url">Lien vers une image</option>
                        </select>
                    </div>
                    <div class="form-group" id="existing_images_group" style="display: none;">
                        <label>Images disponibles</label>
                        <div id="existing-images-container" class="existing-images-grid"></div>
                        <input type="hidden" id="selected_existing_image" name="selected_existing_image">
                    </div>
                    <div class="form-group" id="image_upload_group" style="display: none;">
                        <label for="image">Image du composant (fichier)</label>
                        <input type="file" id="image" name="image" accept="image/*">
                    </div>
                    <div class="form-group" id="image_url_group" style="display: none;">
                        <label for="image_url">URL de l'image</label>
                        <input type="url" id="image_url" name="image_url" placeholder="https://exemple.com/image.jpg" onchange="updateImagePreview()">
                        <div id="image_preview" style="margin-top: 10px; display: none;">
                            <img id="preview_img" src="" alt="Aperçu" style="max-width: 150px; max-height: 150px; border: 1px solid var(--border-color); border-radius: 5px;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="comment">Commentaire</label>
                        <textarea id="comment" name="comment" rows="3" placeholder="Informations supplémentaires sur le composant..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">✅ Créer le composant</button>
                        <a href="components.php" class="btn btn-secondary">↩️ Retour à la liste</a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        const diodeParamDefinitions = <?php echo json_encode($paramDefinitionsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const dynamicParamValues = {};

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
            });
        }

        function compareLabel(mode) {
            if (mode === '>=') { return 'min'; }
            if (mode === '<=') { return 'max'; }
            return '=';
        }

        function snapshotDynamicParams() {
            document.querySelectorAll('#dynamic_params_container [name^="param_"]').forEach(function (field) {
                dynamicParamValues[field.name.replace('param_', '')] = field.value;
            });
        }

        function renderDynamicParams(subcategoryId) {
            snapshotDynamicParams();

            const group = document.getElementById('dynamic_params_group');
            const container = document.getElementById('dynamic_params_container');
            const definitions = diodeParamDefinitions[String(subcategoryId)] || [];

            if (!definitions.length) {
                group.style.display = 'none';
                container.innerHTML = '';
                return;
            }

            container.innerHTML = definitions.map(function (definition) {
                const key = definition.param_key;
                const label = definition.label;
                const meta = compareLabel(definition.compare_mode) + (definition.unit ? ' · ' + definition.unit : '');
                const value = escapeHtml(dynamicParamValues[key] || '');

                if (definition.value_type === 'number') {
                    return '<div class="form-group">'
                        + '<label class="dynamic-param-label" for="param_' + escapeHtml(key) + '"><span>' + escapeHtml(label)
                        + '</span><span class="dynamic-param-meta">' + escapeHtml(meta) + '</span></label>'
                        + '<input type="number" step="any" inputmode="decimal" id="param_' + escapeHtml(key)
                        + '" name="param_' + escapeHtml(key) + '" value="' + value + '">'
                        + '</div>';
                }

                return '<div class="form-group">'
                    + '<label class="dynamic-param-label" for="param_' + escapeHtml(key) + '"><span>' + escapeHtml(label)
                    + '</span><span class="dynamic-param-meta">' + escapeHtml(meta) + '</span></label>'
                    + '<input type="text" id="param_' + escapeHtml(key) + '" name="param_' + escapeHtml(key)
                    + '" value="' + value + '">'
                    + '</div>';
            }).join('');

            group.style.display = 'block';
        }

        function setDynamicParamValue(key, value) {
            if (value === null || value === undefined || value === '') {
                return;
            }
            dynamicParamValues[key] = String(value);
            const field = document.getElementById('param_' + key);
            if (field) {
                field.value = String(value);
            }
        }

        function normalizeApiText(value) {
            return String(value || '').toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function parseMetricValue(raw, transform, match) {
            raw = String(raw || '').replace(',', '.').trim();
            if (raw === '' || isNaN(Number(raw))) {
                return '';
            }
            const numeric = Number(raw);
            const value = transform ? transform(numeric, match || []) : numeric;
            return Number.isFinite(value) ? String(value) : '';
        }

        function parseFirstMetric(text, patterns, transform) {
            const list = Array.isArray(patterns) ? patterns : [patterns];
            for (let i = 0; i < list.length; i++) {
                const match = text.match(list[i]);
                if (!match) {
                    continue;
                }
                const parsed = parseMetricValue(match[1], transform, match);
                if (parsed !== '') {
                    return parsed;
                }
            }
            return '';
        }

        function firstNonEmpty(values) {
            for (let i = 0; i < values.length; i++) {
                if (values[i] !== '') {
                    return values[i];
                }
            }
            return '';
        }

        function diodeSourceText(part) {
            return normalizeApiText([
                part.category,
                part.description,
                part.spec_text,
                part.mpn,
                part.package,
                part.best_offer && part.best_offer.supplier_ref,
                part.best_offer && part.best_offer.supplier_name,
                part.datasheet
            ].filter(Boolean).join(' '));
        }

        function diodeExtractVoltage(text, labels) {
            const escaped = labels.map(function (label) { return label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|');
            return firstNonEmpty([
                parseFirstMetric(text, new RegExp('(?:' + escaped + ')[^\\d]{0,12}(\\d+(?:[.,]\\d+)?)\\s*v\\b', 'i')),
                parseFirstMetric(text, new RegExp('(\\d+(?:[.,]\\d+)?)\\s*v\\b[^\\n]{0,18}(?:' + escaped + ')', 'i'))
            ]);
        }

        function diodeExtractCurrentAmp(text, labels) {
            const escaped = labels.map(function (label) { return label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|');
            return firstNonEmpty([
                parseFirstMetric(text, new RegExp('(?:' + escaped + ')[^\\d]{0,14}(\\d+(?:[.,]\\d+)?)\\s*(ma|a)\\b', 'i'), function (value, match) {
                    return String(match[2]).toLowerCase() === 'ma' ? value / 1000 : value;
                }),
                parseFirstMetric(text, new RegExp('(\\d+(?:[.,]\\d+)?)\\s*(ma|a)\\b[^\\n]{0,18}(?:' + escaped + ')', 'i'), function (value, match) {
                    return String(match[2]).toLowerCase() === 'ma' ? value / 1000 : value;
                })
            ]);
        }

        function diodeExtractCurrentMicro(text, labels) {
            const escaped = labels.map(function (label) { return label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|');
            return firstNonEmpty([
                parseFirstMetric(text, new RegExp('(?:' + escaped + ')[^\\d]{0,14}(\\d+(?:[.,]\\d+)?)\\s*(ua|µa|ma)\\b', 'i'), function (value, match) {
                    const unit = String(match[2]).toLowerCase();
                    if (unit === 'ma') { return value * 1000; }
                    return value;
                }),
                parseFirstMetric(text, new RegExp('(\\d+(?:[.,]\\d+)?)\\s*(ua|µa|ma)\\b[^\\n]{0,18}(?:' + escaped + ')', 'i'), function (value, match) {
                    const unit = String(match[2]).toLowerCase();
                    if (unit === 'ma') { return value * 1000; }
                    return value;
                })
            ]);
        }

        function diodeExtractPower(text, labels) {
            const escaped = labels.map(function (label) { return label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('|');
            return firstNonEmpty([
                parseFirstMetric(text, new RegExp('(?:' + escaped + ')[^\\d]{0,14}(\\d+(?:[.,]\\d+)?)\\s*(mw|w)\\b', 'i'), function (value, match) {
                    return String(match[2]).toLowerCase() === 'mw' ? value / 1000 : value;
                }),
                parseFirstMetric(text, new RegExp('(\\d+(?:[.,]\\d+)?)\\s*(mw|w)\\b[^\\n]{0,18}(?:' + escaped + ')', 'i'), function (value, match) {
                    return String(match[2]).toLowerCase() === 'mw' ? value / 1000 : value;
                })
            ]);
        }

        function diodeExtractTolerance(text) {
            return firstNonEmpty([
                parseFirstMetric(text, [/(?:tolerance|tol)[^\d]{0,10}(\d+(?:[.,]\d+)?)\s*%/i]),
                parseFirstMetric(text, [/(?:\+\/-|±)\s*(\d+(?:[.,]\d+)?)\s*%/i]),
                parseFirstMetric(text, [/(\d+(?:[.,]\d+)?)\s*%/i])
            ]);
        }

        function diodeExtractVf(text) {
            return firstNonEmpty([
                diodeExtractVoltage(text, ['vf', 'v_f', 'forward voltage', 'forward volt', 'voltage drop', 'dropout']),
                parseFirstMetric(text, [/\b(\d+(?:[.,]\d+)?)\s*v\b[^\n]{0,22}(?:at|@)\s*\d/i])
            ]);
        }

        function diodeExtractIfsm(text) {
            return firstNonEmpty([
                diodeExtractCurrentAmp(text, ['ifsm', 'i_fsm', 'forward surge current', 'surge current', 'non repetitive surge', 'peak forward surge current']),
                parseFirstMetric(text, [/\b(\d+(?:[.,]\d+)?)\s*(ma|a)\b[^\n]{0,28}(?:ifsm|surge)/i], function (value, match) {
                    return String(match[2]).toLowerCase() === 'ma' ? value / 1000 : value;
                })
            ]);
        }

        function detectDiodeSubcategory(part) {
            const text = diodeSourceText(part);

            if (!/(diode|zener|schottky|bridge|pont|rectifier|redresse|signal|switching|commutation)/.test(text)) {
                return null;
            }
            if (/zener/.test(text)) { return 404; }
            if (/schottky/.test(text)) { return 402; }
            if (/(bridge|pont de diode|pont redresseur)/.test(text)) { return 406; }
            if (/(small signal|petits signaux|switching|commutation)/.test(text)) { return 403; }
            return 401;
        }

        function populateDiodeParamsFromApi(part, subcategoryId) {
            const text = diodeSourceText(part);

            if (!text) {
                return;
            }

            if (subcategoryId !== 406) {
                if (/simple/.test(text)) { setDynamicParamValue('polarity', 'Simple'); }
                if (/double|dual/.test(text)) { setDynamicParamValue('polarity', 'Double'); }
            }

            const voltage = firstNonEmpty([
                diodeExtractVoltage(text, ['vrrm', 'vr']),
                parseFirstMetric(text, /(\d+(?:[.,]\d+)?)\s*v\b/i)
            ]);
            const currentA = firstNonEmpty([
                diodeExtractCurrentAmp(text, ['if(av)', 'if avg', 'if average', 'average forward current', 'forward current', 'io']),
                parseFirstMetric(text, /(\d+(?:[.,]\d+)?)\s*(ma|a)\b/i, function (value, match) {
                    return String(match[2]).toLowerCase() === 'ma' ? value / 1000 : value;
                })
            ]);
            const powerW = firstNonEmpty([
                diodeExtractPower(text, ['power dissipation', 'pd', 'power']),
                parseFirstMetric(text, /(\d+(?:[.,]\d+)?)\s*(mw|w)\b/i, function (value, match) {
                    return String(match[2]).toLowerCase() === 'mw' ? value / 1000 : value;
                })
            ]);
            const tolerance = diodeExtractTolerance(text);
            const timeNs = firstNonEmpty([
                parseFirstMetric(text, [/(?:trr|reverse recovery|recovery time)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*ns\b/i]),
                parseFirstMetric(text, [/(\d+(?:[.,]\d+)?)\s*ns\b/i])
            ]);
            const capPf = firstNonEmpty([
                parseFirstMetric(text, [/(?:cj|junction capacitance|capacitance)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*pf\b/i]),
                parseFirstMetric(text, [/(\d+(?:[.,]\d+)?)\s*pf\b/i])
            ]);
            const currentUa = diodeExtractCurrentMicro(text, ['ir', 'reverse current', 'leakage current']);
            const vf = diodeExtractVf(text);
            const ifsm = diodeExtractIfsm(text);

            if (subcategoryId === 404) {
                setDynamicParamValue('vz', voltage);
                setDynamicParamValue('tolerance', tolerance);
                setDynamicParamValue('power', powerW);
            } else if (subcategoryId === 402) {
                setDynamicParamValue('vr', voltage);
                setDynamicParamValue('if_avg', currentA);
                setDynamicParamValue('vf', vf);
                setDynamicParamValue('ir', currentUa);
            } else if (subcategoryId === 403) {
                setDynamicParamValue('vr', voltage);
                setDynamicParamValue('if_avg', currentA);
                setDynamicParamValue('trr', timeNs);
                setDynamicParamValue('cj', capPf);
            } else if (subcategoryId === 406) {
                setDynamicParamValue('vrrm', voltage);
                setDynamicParamValue('if_av', currentA);
                setDynamicParamValue('ifsm', ifsm);
                if (/3\s*-?\s*phase|triphase/.test(text)) {
                    setDynamicParamValue('phases', '3');
                } else if (/1\s*-?\s*phase|monophase/.test(text)) {
                    setDynamicParamValue('phases', '1');
                }
            } else if (subcategoryId === 401) {
                setDynamicParamValue('vrrm', voltage);
                setDynamicParamValue('if_avg', currentA);
                setDynamicParamValue('ifsm', ifsm);
                setDynamicParamValue('vf', vf);
                if (/ultra\s*fast/.test(text)) {
                    setDynamicParamValue('recovery', 'Ultra-fast');
                } else if (/fast/.test(text)) {
                    setDynamicParamValue('recovery', 'Fast');
                } else if (/standard/.test(text)) {
                    setDynamicParamValue('recovery', 'Standard');
                }
            }
        }

        function selectApiDetectedSubcategory(subcategoryId) {
            const categoryHead = document.getElementById('category_head');
            const subcategorySelect = document.getElementById('category');

            if (!subcategoryId || !categoryHead || !subcategorySelect) {
                return Promise.resolve();
            }

            categoryHead.value = '4';
            subcategorySelect.innerHTML = '<option value="">Chargement...</option>';

            return fetch('get_subcategories.php?parent_id=4')
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    subcategorySelect.innerHTML = '<option value="">Sélectionner une sous-catégorie</option>';
                    data.forEach(function (subcat) {
                        const option = document.createElement('option');
                        option.value = subcat.id;
                        option.textContent = subcat.name;
                        if (String(subcat.id) === String(subcategoryId)) {
                            option.selected = true;
                        }
                        subcategorySelect.appendChild(option);
                    });
                    renderDynamicParams(subcategoryId);
                })
                .catch(function () {
                    renderDynamicParams(null);
                });
        }

        // Variables globales
        let packageTimeout;
        let currentPackages = [];

    /* --- Import Nexar / Octopart ------------------------------------------- */
    let nexarResultsCache = [];

    // Échappe une valeur issue de l'API avant insertion dans le DOM.
    function nexarEscape(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }

    // Normalise un libellé pour comparer fabricants / fournisseurs.
    function nexarNormalizeLabel(value) {
        return String(value === null || value === undefined ? '' : value).toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    // Sources interrogeables et libellés courts associés.
    const nexarEndpoints = {
        nexar:   'nexar_search.php',
        mouser:  'mouser_search.php',
        farnell: 'farnell_search.php',
        digikey: 'digikey_search.php',
        tme:     'tme_search.php',
        lcsc:    'lcsc_search.php',
        rs:      'rs_search.php'
    };

    const nexarSourceLabels = {
        nexar:   'Nexar',
        mouser:  'Mouser',
        farnell: 'Farnell',
        digikey: 'DigiKey',
        tme:     'TME',
        lcsc:    'LCSC',
        rs:      'RS'
    };

    function nexarSourceLabel(source) {
        return nexarSourceLabels[source] || source;
    }

    // Sources à interroger : celle qui est choisie, ou toutes si « all ».
    function nexarResolveSources() {
        const field = document.getElementById('api_source');
        const selected = field ? field.value : 'nexar';
        if (selected === 'all') {
            return Object.keys(nexarEndpoints);
        }
        return [selected];
    }

    // Pointe le lien « Aide » vers le tutoriel de la source sélectionnée.
    function nexarUpdateHelpLink() {
        const field = document.getElementById('api_source');
        const link = document.getElementById('source_help_link');
        if (!field || !link) { return; }

        const source = field.value;
        if (source && source !== 'all' && nexarEndpoints[source]) {
            link.href = 'api_settings.php#tuto-' + source;
            link.title = 'Ouvrir le tutoriel de configuration — ' + nexarSourceLabel(source);
        } else {
            link.href = 'api_settings.php';
            link.title = 'Ouvrir les réglages des API fournisseurs';
        }
    }

    // Mention des sources en échec, affichée en fin de ligne de statut.
    function nexarFailureNote(responses) {
        const failures = responses.filter(function (response) { return response.error; })
            .map(function (response) {
                return nexarSourceLabel(response.source) + ' (' + response.error + ')';
            });
        return failures.length ? ' ⚠ ' + failures.join(', ') : '';
    }

    /**
     * Complète une fiche déjà fusionnée avec les informations d'un doublon
     * et conserve l'offre la moins chère (à devise comparable).
     */
    function nexarMergePart(target, part, source) {
        if (target.__sources.indexOf(source) === -1) {
            target.__sources.push(source);
        }

        ['manufacturer', 'description', 'category', 'package', 'pins', 'datasheet', 'image_url'].forEach(function (field) {
            if (!target[field] && part[field]) { target[field] = part[field]; }
        });

        if (!part.best_offer) { return; }

        const candidate = part.best_offer;
        const current = target.best_offer;
        const sameCurrency = current && String(candidate.currency || '') === String(current.currency || '');

        if (!current || (sameCurrency && Number(candidate.price) > 0 && Number(candidate.price) < Number(current.price))) {
            target.best_offer = candidate;
            target.__source = source;
        }
    }

    /**
     * Fusionne les réponses des différentes sources en une liste unique,
     * dédoublonnée par référence fabricant (MPN) normalisée.
     * La première source qui renvoie une référence est conservée en tête et les
     * doublons viennent compléter les champs manquants / la meilleure offre.
     */
    function nexarMergeResults(responses) {
        const byKey = {};
        const order = [];
        let hits = 0;

        responses.forEach(function (response) {
            hits += response.hits || 0;
            response.results.forEach(function (part) {
                if (!part || !part.mpn) { return; }

                const key = nexarNormalizeLabel(part.mpn) || part.mpn;
                if (!byKey[key]) {
                    // On mémorise la source d'origine : elle sert à ne pas
                    // réinterroger cette même source lors du repli datasheet.
                    byKey[key] = Object.assign({}, part, {
                        __sources: [response.source],
                        __source: response.source
                    });
                    order.push(key);
                    return;
                }

                nexarMergePart(byKey[key], part, response.source);
            });
        });

        const results = order.map(function (key) { return byKey[key]; });

        return { results: results, hits: hits > 0 ? hits : results.length };
    }

    /**
     * Recherche unifiée : interroge en parallèle la source choisie (ou toutes
     * les sources configurées), puis fusionne et dédoublonne les résultats.
     */
    function nexarSearch() {
        const statusBox = document.getElementById('nexar_status');
        const resultsBox = document.getElementById('nexar_results');
        const button = document.getElementById('nexar_search_btn');
        const query = document.getElementById('nexar_query').value.trim();

        if (query.length < 2) {
            statusBox.textContent = 'Saisissez au moins 2 caractères.';
            return;
        }

        const sources = nexarResolveSources();

        button.disabled = true;
        statusBox.textContent = 'Recherche en cours…';
        resultsBox.innerHTML = '';
        nexarResultsCache = [];
        clearDuplicateWarning();

        // La limite de résultats est définie par le réglage API de chaque source.
        const requests = sources.map(function (source) {
            const endpoint = nexarEndpoints[source] || nexarEndpoints.nexar;

            return fetch(endpoint + '?q=' + encodeURIComponent(query))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        return { source: source, error: data.message || 'Erreur inconnue', results: [], hits: 0 };
                    }
                    const results = Array.isArray(data.results) ? data.results : [];
                    return {
                        source: source,
                        error: '',
                        results: results,
                        hits: (typeof data.hits === 'number' && data.hits > 0) ? data.hits : results.length
                    };
                })
                .catch(function (error) {
                    console.error(source, error);
                    return { source: source, error: 'communication impossible', results: [], hits: 0 };
                });
        });

        Promise.all(requests)
            .then(function (responses) {
                const merged = nexarMergeResults(responses);

                if (merged.results.length === 0) {
                    statusBox.textContent = 'Aucun composant trouvé pour « ' + query + ' ».' + nexarFailureNote(responses);
                    return;
                }

                nexarResultsCache = merged.results;
                statusBox.textContent = merged.results.length + ' affiché(s) sur ' + merged.hits
                    + ' trouvé(s) — cliquez sur « Pré-remplir » pour remplir le formulaire.'
                    + nexarFailureNote(responses);
                resultsBox.innerHTML = merged.results.map(nexarRenderResult).join('');
            })
            .catch(function (error) {
                console.error(error);
                statusBox.textContent = 'Erreur de communication avec le serveur.';
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function nexarRenderResult(part, index) {
        const meta = [];
        if (part.package) { meta.push('Package : ' + part.package); }
        if (part.pins) { meta.push(part.pins + ' pins'); }
        meta.push(part.smd === 'Yes' ? 'SMD' : 'Traversant');

        let offerHtml = '<span class="nexar-result-desc">Aucune offre disponible</span>';
        const offer = part.best_offer;
        if (offer) {
            const tier = (offer.quantity && offer.quantity > 1) ? ' dès ' + parseInt(offer.quantity, 10) + ' pcs' : '';
            const taxLabel = (offer.tax === 'incl') ? ' TTC' : ' HT';
            offerHtml = '<span class="nexar-badge">' + Number(offer.price).toFixed(4) + ' ' + nexarEscape(offer.currency) + taxLabel + tier + '</span>'
                + '<span class="nexar-result-desc">' + nexarEscape(offer.supplier_name)
                + (offer.supplier_ref ? ' · réf. ' + nexarEscape(offer.supplier_ref) : '')
                + (offer.inventory ? ' · stock ' + parseInt(offer.inventory, 10) : '')
                + '</span>';
        }

        const thumb = part.image_url
            ? '<img class="nexar-result-thumb" src="' + nexarEscape(part.image_url) + '" alt="" loading="lazy">'
            : '<div class="nexar-result-thumb"></div>';

        // Source(s) d'où provient la fiche (plusieurs en cas de recherche fusionnée).
        const sourceBadges = (Array.isArray(part.__sources) && part.__sources.length)
            ? part.__sources.map(function (source) {
                  return '<span class="nexar-source-badge">' + nexarEscape(nexarSourceLabel(source)) + '</span>';
              }).join('')
            : '';

        const datasheetButton = part.datasheet
            ? '<button type="button" class="btn btn-secondary" onclick="openResultDatasheet(' + index + ')">📄 Datasheet</button>'
            : '';

        return '<div class="nexar-result">'
            + thumb
            + '<div class="nexar-result-body">'
            + '<div class="nexar-result-title">' + nexarEscape(part.mpn)
            + (part.manufacturer ? ' — ' + nexarEscape(part.manufacturer) : '') + sourceBadges + '</div>'
            + '<div class="nexar-result-meta">' + nexarEscape(meta.join(' · ')) + '</div>'
            + '<div class="nexar-result-desc">' + nexarEscape(part.description) + '</div>'
            + '<div class="nexar-result-meta">' + offerHtml + '</div>'
            + '</div>'
            + '<div class="nexar-result-action">'
            + datasheetButton
            + '<button type="button" class="btn btn-success" onclick="nexarApply(' + index + ')">⬇️ Pré-remplir</button>'
            + '</div>'
            + '</div>';
    }

    function nexarSelectManufacturer(name) {
        if (!name) { return; }

        const select = document.getElementById('manufacturer');
        const target = nexarNormalizeLabel(name);

        for (const option of Array.from(select.options)) {
            if (option.value && option.value !== '__new__' && nexarNormalizeLabel(option.value) === target) {
                select.value = option.value;
                toggleNewManufacturer();
                return;
            }
        }

        // Fabricant inconnu : on bascule sur « Nouveau fabricant ».
        select.value = '__new__';
        toggleNewManufacturer();
        document.getElementById('new_manufacturer').value = name.trim();
    }

    function nexarSelectSupplier(name) {
        if (!name) { return; }

        const select = document.getElementById('supplier_id');
        const target = nexarNormalizeLabel(name);

        for (const option of Array.from(select.options)) {
            if (!option.value) { continue; }
            const label = nexarNormalizeLabel(option.textContent);
            if (label === target || (label !== '' && (label.indexOf(target) !== -1 || target.indexOf(label) !== -1))) {
                select.value = option.value;
                return;
            }
        }
    }

    /**
     * Remplit le champ Datasheet.
     * Si la source ne fournit pas de datasheet, on cherche le PDF officiel
     * auprès d'une autre source configurée et, à défaut,
     * on propose un lien de recherche alldatasheet (protégé par Cloudflare :
     * on ne peut pas en extraire le PDF, seulement proposer le lien).
     */
    const datasheetFallbackCache = {};

    function getDatasheetUrl() {
        const input = document.getElementById('datasheet');
        return input ? input.value.trim() : '';
    }

    function updateDatasheetTools() {
        const url = getDatasheetUrl();
        const openBtn = document.getElementById('datasheet_open_btn');
        const previewBtn = document.getElementById('datasheet_preview_btn');
        const previewBox = document.getElementById('datasheet_preview_box');
        const previewFrame = document.getElementById('datasheet_preview_frame');
        const previewTitle = document.getElementById('datasheet_preview_title');

        if (openBtn) { openBtn.disabled = (url === ''); }
        if (previewBtn) { previewBtn.disabled = (url === ''); }

        if (previewTitle) {
            previewTitle.textContent = url ? ('Aperçu datasheet — ' + url) : 'Aperçu datasheet';
        }

        if (!url && previewBox && previewFrame) {
            previewBox.style.display = 'none';
            previewFrame.src = 'about:blank';
        }
    }

    function openDatasheetLink() {
        const url = getDatasheetUrl();
        if (!url) { return; }
        window.open(url, '_blank', 'noopener');
    }

    function openResultDatasheet(index) {
        const part = nexarResultsCache[index];
        if (!part || !part.datasheet) {
            return;
        }
        window.open(part.datasheet, '_blank', 'noopener');
    }

    function toggleDatasheetPreview() {
        const url = getDatasheetUrl();
        const previewBox = document.getElementById('datasheet_preview_box');
        const previewFrame = document.getElementById('datasheet_preview_frame');

        if (!url || !previewBox || !previewFrame) {
            return;
        }

        const isOpen = previewBox.style.display === 'block';
        if (isOpen) {
            previewBox.style.display = 'none';
            previewFrame.src = 'about:blank';
            return;
        }

        previewBox.style.display = 'block';
        if (previewFrame.src !== url) {
            previewFrame.src = url;
        }
    }

    function applyDatasheet(datasheet, mpn, manufacturer, source) {
        const input = document.getElementById('datasheet');
        const hint = document.getElementById('datasheet_hint');

        if (datasheet) {
            input.value = datasheet;
            input.dataset.fallbackFor = '';
            hint.style.display = 'none';
            updateDatasheetTools();
            return;
        }

        if (!mpn) {
            input.value = '';
            input.dataset.fallbackFor = '';
            hint.style.display = 'none';
            updateDatasheetTools();
            return;
        }

        // Clé de la sélection courante : permet d'ignorer une réponse tardive
        // si l'utilisateur a entre-temps choisi un autre composant ou saisi
        // lui-même une URL.
        const key = mpn + '|' + (manufacturer || '');
        input.dataset.fallbackFor = key;

        // Repli immédiat, remplacé par le PDF si la recherche aboutit.
        input.value = 'https://www.alldatasheet.com/view.jsp?Searchword=' + encodeURIComponent(mpn);
        hint.textContent = '🔎 Aucune datasheet fournie par l\'API : lien de recherche alldatasheet proposé pour « ' + mpn + ' ». Recherche d\'un PDF officiel en cours…';
        hint.style.display = 'block';
        updateDatasheetTools();

        lookupDatasheet(mpn, manufacturer, source, key, input, hint);
    }

    /**
     * Interroge le repli inter-sources pour obtenir le PDF officiel, puis met à
     * jour le champ si la sélection n'a pas changé.
     */
    function lookupDatasheet(mpn, manufacturer, source, key, input, hint) {
        const upgrade = (entry) => {
            if (input.dataset.fallbackFor !== key) { return; }
            if (entry.url) {
                input.value = entry.url;
                hint.textContent = '📄 Fiche technique récupérée depuis ' + entry.source + ' pour « ' + mpn + ' ». Modifiable.';
            } else {
                hint.textContent = '🔎 Aucune datasheet trouvée automatiquement : lien de recherche alldatasheet proposé pour « ' + mpn + ' ». Modifiable.';
            }
            updateDatasheetTools();
        };

        if (datasheetFallbackCache[key] !== undefined) {
            upgrade(datasheetFallbackCache[key]);
            return;
        }

        const params = new URLSearchParams({
            mpn: mpn,
            manufacturer: manufacturer || '',
            exclude: source || ''
        });

        fetch('datasheet_lookup.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then((response) => response.json())
            .then((data) => {
                const entry = (data && data.ok && data.datasheet)
                    ? { url: data.datasheet, source: data.source || 'Nexar' }
                    : { url: '', source: '' };
                datasheetFallbackCache[key] = entry;
                upgrade(entry);
            })
            .catch(() => {
                datasheetFallbackCache[key] = { url: '', source: '' };
                upgrade(datasheetFallbackCache[key]);
            });
    }

    /**
     * Sur une creation manuelle, si le champ datasheet est vide mais que la
     * reference est renseignee, on tente aussi un remplissage automatique.
     */
    function autoFillDatasheetIfMissing() {
        const input = document.getElementById('datasheet');
        const mpnInput = document.getElementById('name');
        const manufacturerInput = document.getElementById('manufacturer');

        if (!input || !mpnInput || !manufacturerInput) { return; }
        if (input.value.trim() !== '') { return; }

        const mpn = mpnInput.value.trim();
        const manufacturer = manufacturerInput.value.trim();
        if (mpn.length < 2) { return; }

        applyDatasheet('', mpn, manufacturer, '');
    }

    /**
     * Signale sous le champ Prix les conversions appliquées par la source.
     * Toutes les sources communiquent un prix HT ; TME fournissant du TTC,
     * la valeur est ramenée hors taxes (voir tmeFindBestOffer()).
     */
    function showPriceHint(offer) {
        const hint = document.getElementById('price_hint');
        if (!hint) { return; }

        if (offer && offer.vat_rate) {
            hint.textContent = 'Prix TTC converti en HT (TVA ' + Number(offer.vat_rate) + ' %).';
            hint.style.display = 'block';
        } else {
            hint.textContent = '';
            hint.style.display = 'none';
        }
    }

    /* --- Détection de doublon --------------------------------------------- */
    let duplicateCache = {};
    let lastDuplicateQuery = { name: '', manufacturer: '' };

    // Cache le bandeau de doublon.
    function clearDuplicateWarning() {
        const box = document.getElementById('duplicate_warning');
        if (box) {
            box.style.display = 'none';
            box.innerHTML = '';
        }
        duplicateCache = {};
    }

    /**
     * Vérifie qu'une fiche ne porte pas déjà cette référence, et propose le
     * cas échéant d'ajouter la quantité à la fiche existante.
     */
    function checkDuplicate(name, manufacturer) {
        const box = document.getElementById('duplicate_warning');
        if (!box) { return; }

        name = (name || '').trim();
        manufacturer = (manufacturer || '').trim();
        lastDuplicateQuery = { name: name, manufacturer: manufacturer };

        if (name === '') {
            clearDuplicateWarning();
            return;
        }

        const params = new URLSearchParams({ name: name, manufacturer: manufacturer });

        fetch('check_duplicate.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.duplicates || data.duplicates.length === 0) {
                    clearDuplicateWarning();
                    return;
                }

                duplicateCache = {};
                data.duplicates.forEach(function (entry) { duplicateCache[entry.id] = entry; });

                const same = data.duplicates.filter(function (entry) { return entry.same_manufacturer; });

                let html = same.length > 0
                    ? '<strong>⚠️ Ce composant existe déjà dans votre inventaire.</strong>'
                    : '<strong>⚠️ Une fiche porte déjà cette référence.</strong>';

                html += '<ul style="margin: 6px 0 8px 18px; padding: 0;">';
                data.duplicates.forEach(function (entry) {
                    const bits = [];
                    if (entry.manufacturer) { bits.push(nexarEscape(entry.manufacturer)); }
                    if (entry.package) { bits.push(nexarEscape(entry.package)); }
                    if (entry.location) { bits.push('emplacement ' + nexarEscape(entry.location)); }
                    bits.push(entry.quantity + ' en stock');

                    html += '<li>« <a href="component_sheet.php?id=' + entry.id + '" target="_blank" rel="noopener">'
                          + nexarEscape(entry.name) + '</a> » — ' + bits.join(' · ')
                          + ' <button type="button" class="dup-add-btn" onclick="addQuantityToExisting(' + entry.id + ')">➕ Ajouter la quantité à cette fiche</button>'
                          + '</li>';
                });
                html += '</ul>';

                html += same.length > 0
                    ? 'Vous pouvez créer une seconde fiche, ou ajouter la quantité saisie à la fiche existante.'
                    : 'Vérifiez qu\'il s\'agit bien du même composant avant de créer une seconde fiche.';

                box.innerHTML = html;
                box.style.display = 'block';
            })
            .catch(function (error) {
                console.error(error);
                clearDuplicateWarning();
            });
    }

    /**
     * Ajoute la quantité saisie dans le formulaire à une fiche existante
     * (réutilise update_quantity.php, qui gère le contrôle de propriété).
     */
    function addQuantityToExisting(componentId) {
        const entry = duplicateCache[componentId];
        if (!entry) { return; }

        const quantityField = document.getElementById('quantity');
        const amount = Math.max(1, parseInt(quantityField && quantityField.value ? quantityField.value : '1', 10) || 1);

        if (!confirm('Ajouter ' + amount + ' unité(s) à la fiche « ' + entry.name + ' » (quantité actuelle : ' + entry.quantity + ') ?')) {
            return;
        }

        fetch('update_quantity.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'component_id=' + encodeURIComponent(componentId) + '&change=' + encodeURIComponent(amount)
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    alert('Échec de la mise à jour : ' + ((data && data.error) || 'erreur inconnue'));
                    return;
                }
                entry.quantity = data.new_quantity;
                alert('Quantité mise à jour : ' + data.new_quantity + ' unité(s) pour « ' + entry.name + ' ».');
                checkDuplicate(lastDuplicateQuery.name, lastDuplicateQuery.manufacturer);
            })
            .catch(function (error) {
                console.error(error);
                alert('Erreur de communication avec le serveur.');
            });
    }

    function nexarApply(index) {
        const part = nexarResultsCache[index];
        if (!part) { return; }

        if (part.mpn) { document.getElementById('name').value = part.mpn; }
        nexarSelectManufacturer(part.manufacturer);

        if (part.package) { document.getElementById('package').value = part.package; }
        if (part.pins) { document.getElementById('pins').value = part.pins; }
        document.getElementById('smd').value = (part.smd === 'Yes') ? 'Yes' : 'No';

        applyDatasheet(part.datasheet, part.mpn, part.manufacturer, part.__source);
        if (part.description) { document.getElementById('comment').value = part.description; }

        if (part.best_offer) {
            const offer = part.best_offer;
            if (offer.price) { document.getElementById('price').value = Number(offer.price).toFixed(2); }
            if (offer.supplier_ref) { document.getElementById('supplier_reference').value = offer.supplier_ref; }
            nexarSelectSupplier(offer.supplier_name);
        }
        showPriceHint(part.best_offer);

        if (part.image_url) {
            document.getElementById('image_type').value = 'url';
            toggleImageInput();
            document.getElementById('image_url').value = part.image_url;
            updateImagePreview();
        }

        const diodeSubcategoryId = detectDiodeSubcategory(part);
        if (diodeSubcategoryId) {
            selectApiDetectedSubcategory(diodeSubcategoryId).then(function () {
                populateDiodeParamsFromApi(part, diodeSubcategoryId);
            });
        }

        // Prévenir si cette référence existe déjà dans l'inventaire.
        checkDuplicate(part.mpn, part.manufacturer);

        document.getElementById('name').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    function toggleNewManufacturer() {
        const manufacturerSelect = document.getElementById('manufacturer');
        const newManufacturerGroup = document.getElementById('new_manufacturer_group');
        
        if (manufacturerSelect.value === '__new__') {
            newManufacturerGroup.style.display = 'block';
        } else {
            newManufacturerGroup.style.display = 'none';
            document.getElementById('new_manufacturer').value = '';
        }
    }
    
    function toggleNewSupplier() {
        const supplierSelect = document.getElementById('supplier_id');
        const newSupplierGroup = document.getElementById('new_supplier_group');
        
        if (supplierSelect.value === '__new__') {
            newSupplierGroup.style.display = 'block';
        } else {
            newSupplierGroup.style.display = 'none';
            document.getElementById('new_supplier_name').value = '';
        }
    }
    
    function updateSubcategories() {
        const categoryHead = document.getElementById('category_head').value;
        const subcategorySelect = document.getElementById('category');
        
        // Vider les sous-catégories
        subcategorySelect.innerHTML = '<option value="">Chargement...</option>';
        
        if (categoryHead === '') {
            subcategorySelect.innerHTML = '<option value="">Sélectionner d\'abord une catégorie principale</option>';
            renderDynamicParams(null);
            return;
        }
        
        // Récupérer les sous-catégories via AJAX
        fetch('get_subcategories.php?parent_id=' + categoryHead)
            .then(response => response.json())
            .then(data => {
                subcategorySelect.innerHTML = '<option value="">Sélectionner une sous-catégorie</option>';
                data.forEach(subcat => {
                    const option = document.createElement('option');
                    option.value = subcat.id;
                    option.textContent = subcat.name;
                    subcategorySelect.appendChild(option);
                });
                renderDynamicParams(null);
            })
            .catch(error => {
                console.error('Erreur:', error);
                subcategorySelect.innerHTML = '<option value="">Erreur de chargement</option>';
                renderDynamicParams(null);
            });
    }
    
    function loadSubcategoryImages() {
        renderDynamicParams(document.getElementById('category').value);
        const imageType = document.getElementById('image_type').value;
        
        if (imageType === 'existing') {
            fetch('get_subcategory_images.php')
                .then(response => response.json())
                .then(data => {
                    displayExistingImages(data);
                })
                .catch(error => {
                    console.error('Erreur lors du chargement des images:', error);
                    const container = document.getElementById('existing-images-container');
                    container.innerHTML = '<p style="color: #dc3545;">Erreur lors du chargement des images</p>';
                });
        }
    }
    
    function displayExistingImages(images) {
        const container = document.getElementById('existing-images-container');
        
        if (images.length === 0) {
            container.innerHTML = '<p style="color: #666; font-style: italic;">Aucune image disponible</p>';
            return;
        }
        
        let html = '';
        images.forEach(image => {
            const imageName = image.image_path.split('/').pop();
            html += `
                <div class="existing-image-item" onclick="selectExistingImage('${image.image_path}', this)">
                    <img src="${image.image_path}" alt="${imageName}" onerror="this.src='img/placeholder.png'">
                    <div class="image-name">${imageName}</div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    function selectExistingImage(imagePath, element) {
        // Retirer la sélection précédente
        document.querySelectorAll('.existing-image-item').forEach(item => {
            item.classList.remove('selected');
        });
        
        // Ajouter la sélection à l'élément cliqué
        element.classList.add('selected');
        
        // Mettre à jour le champ caché
        document.getElementById('selected_existing_image').value = imagePath;
    }
    
    function toggleImageInput() {
        const imageType = document.getElementById('image_type').value;
        const existingGroup = document.getElementById('existing_images_group');
        const uploadGroup = document.getElementById('image_upload_group');
        const urlGroup = document.getElementById('image_url_group');
        const imageUrlInput = document.getElementById('image_url');
        
        existingGroup.style.display = 'none';
        uploadGroup.style.display = 'none';
        urlGroup.style.display = 'none';
        
        // Désactiver la validation pour le champ URL quand il n'est pas utilisé
         if (imageType === 'url') {
             urlGroup.style.display = 'block';
             imageUrlInput.removeAttribute('disabled');
             imageUrlInput.type = 'url';
         } else {
             imageUrlInput.setAttribute('disabled', 'disabled');
             imageUrlInput.type = 'text'; // Changer le type pour éviter la validation URL
             if (imageType === 'existing') {
                 existingGroup.style.display = 'block';
                 loadSubcategoryImages();
             } else if (imageType === 'upload') {
                 uploadGroup.style.display = 'block';
             }
         }
    }
    
    // Autocomplétion pour les packages
    function setupPackageAutocomplete() {
        const packageInput = document.getElementById('package');
        const suggestionsDiv = document.getElementById('package-suggestions');
        
        packageInput.addEventListener('input', function() {
            clearTimeout(packageTimeout);
            const query = this.value.trim();
            
            if (query.length === 0) {
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.style.display = 'none';
                return;
            }
            
            packageTimeout = setTimeout(() => {
                fetch('get_packages.php?search=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(packages => {
                        if (Array.isArray(packages) && packages.length === 1 && packages[0].name && 
                            packages[0].name.toLowerCase() === query.toLowerCase()) {
                            const exactPkg = packages[0];
                            if (exactPkg.pin_count) {
                                document.getElementById('pins').value = exactPkg.pin_count;
                            }
                            checkAndSetPackageImage(exactPkg.name);
                        }
                        displayPackageSuggestions(packages, suggestionsDiv, packageInput);
                    })
                    .catch(error => {
                        console.error('Erreur lors de la récupération des packages:', error);
                    });
            }, 300);
        });
        
        packageInput.addEventListener('blur', function() {
            const query = this.value.trim();
            if (query.length >= 2) {
                fetch('get_packages.php?search=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(packages => {
                        if (Array.isArray(packages)) {
                            const exactMatch = packages.find(p => 
                                (typeof p === 'string' ? p : p.name).toLowerCase() === query.toLowerCase()
                            );
                            if (exactMatch) {
                                const pkg = typeof exactMatch === 'string' ? { name: exactMatch } : exactMatch;
                                if (pkg.pin_count) {
                                    document.getElementById('pins').value = pkg.pin_count;
                                }
                                checkAndSetPackageImage(pkg.name);
                            } else {
                                checkAndSetPackageImage(query);
                            }
                        }
                    })
                    .catch(error => {
                        checkAndSetPackageImage(query);
                    });
            }
        });
        
        // Cacher les suggestions quand on clique ailleurs
        document.addEventListener('click', function(e) {
            if (!packageInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                suggestionsDiv.style.display = 'none';
            }
        });
    }
    
    function displayPackageSuggestions(packages, suggestionsDiv, inputElement) {
        suggestionsDiv.innerHTML = '';
        
        if (packages.length === 0) {
            suggestionsDiv.style.display = 'none';
            return;
        }
        
        packages.forEach(pkg => {
            const packageName = typeof pkg === 'string' ? pkg : pkg.name;
            const pinCount = typeof pkg === 'object' && pkg.pin_count ? pkg.pin_count : null;
            
            const suggestionItem = document.createElement('div');
            suggestionItem.className = 'suggestion-item';
            suggestionItem.style.cssText = 'display: flex; justify-content: space-between; align-items: center;';
            
            const nameSpan = document.createElement('span');
            nameSpan.textContent = packageName;
            nameSpan.style.fontWeight = '500';
            
            let infoHtml = '';
            if (pinCount) {
                infoHtml += `<span style="color: #666; font-size: 12px; margin-left: 10px;">${pinCount} pins</span>`;
            }
            if (typeof pkg === 'object' && pkg.mounting_type) {
                infoHtml += `<span style="color: #888; font-size: 11px; margin-left: 8px; background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">${pkg.mounting_type}</span>`;
            }
            
            suggestionItem.innerHTML = `<span style="font-weight:500;">${packageName}</span>${infoHtml}`;
            
            suggestionItem.onclick = () => {
                inputElement.value = packageName;
                suggestionsDiv.style.display = 'none';
                
                if (pinCount) {
                    document.getElementById('pins').value = pinCount;
                }
                
                checkAndSetPackageImage(packageName);
            };
            suggestionsDiv.appendChild(suggestionItem);
        });
        
        suggestionsDiv.style.display = 'block';
    }
    
    // Autocomplétion du nom du composant : propose les composants existants dès
    // 2 caractères saisis, afin d'éviter la création de doublons.
    function setupNameAutocomplete() {
        const nameInput = document.getElementById('name');
        const suggestionsDiv = document.getElementById('name-suggestions');
        const warningDiv = document.getElementById('name-duplicate-warning');

        if (!nameInput || !suggestionsDiv) { return; }

        let timeout;
        let items = [];
        let activeIndex = -1;
        // Derniers résultats reçus : sert à détecter un doublon exact une fois la
        // liste refermée (l'avertissement est masqué tant que la liste est ouverte).
        let lastResults = [];

        function closeSuggestions() {
            suggestionsDiv.innerHTML = '';
            suggestionsDiv.style.display = 'none';
            items = [];
            activeIndex = -1;
        }

        function highlight() {
            Array.from(suggestionsDiv.children).forEach((child, index) => {
                child.style.background = (index === activeIndex) ? 'var(--bg-muted)' : '';
            });
            const current = suggestionsDiv.children[activeIndex];
            if (current && current.scrollIntoView) {
                current.scrollIntoView({ block: 'nearest' });
            }
        }

        // Avertissement lorsqu'un composant porte exactement le même nom.
        function showDuplicateWarning(item) {
            warningDiv.innerHTML = '';
            warningDiv.appendChild(document.createTextNode('⚠️ Un composant nommé '));

            const strong = document.createElement('strong');
            strong.textContent = item.name;
            warningDiv.appendChild(strong);

            const details = [item.manufacturer, item.package].filter(Boolean).join(' · ');
            if (details !== '') {
                warningDiv.appendChild(document.createTextNode(' (' + details + ')'));
            }

            warningDiv.appendChild(document.createTextNode(' existe déjà. '));

            const link = document.createElement('a');
            link.href = 'edit_component.php?id=' + encodeURIComponent(item.id);
            link.textContent = 'Ouvrir la fiche existante';
            warningDiv.appendChild(link);

            warningDiv.appendChild(document.createTextNode(' pour éviter un doublon.'));
            warningDiv.style.display = 'block';
        }

        // Détecte un doublon exact à partir de la liste déjà reçue, une fois la
        // liste de suggestions refermée.
        function updateDuplicateWarning() {
            const value = nameInput.value.trim().toLowerCase();
            const match = value === ''
                ? null
                : lastResults.find(c => String(c.name).toLowerCase() === value);

            if (match) {
                showDuplicateWarning(match);
            } else {
                warningDiv.style.display = 'none';
            }
        }

        function selectItem(item) {
            if (!item) { return; }
            nameInput.value = item.name;
            closeSuggestions();
            updateDuplicateWarning();
        }

        function displaySuggestions(results) {
            closeSuggestions();

            // La liste ouverte occupe la place sous le champ : l'avertissement est
            // masqué pour ne pas se superposer.
            warningDiv.style.display = 'none';

            if (results.length === 0) {
                return;
            }

            items = results;

            results.forEach(item => {
                const row = document.createElement('div');
                row.className = 'suggestion-item';
                row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:10px;';

                const nameSpan = document.createElement('span');
                nameSpan.style.fontWeight = '500';
                nameSpan.textContent = item.name;
                row.appendChild(nameSpan);

                const details = [item.manufacturer, item.package].filter(Boolean).join(' · ');
                if (details !== '') {
                    const infoSpan = document.createElement('span');
                    infoSpan.style.cssText = 'color:var(--text-secondary);font-size:12px;white-space:nowrap;';
                    infoSpan.textContent = details;
                    row.appendChild(infoSpan);
                }

                // mousedown : le blur de l'input fermerait la liste avant le clic.
                row.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    selectItem(item);
                });

                suggestionsDiv.appendChild(row);
            });

            suggestionsDiv.style.display = 'block';
        }

        nameInput.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();

            if (query.length < 2) {
                closeSuggestions();
                lastResults = [];
                updateDuplicateWarning();
                return;
            }

            timeout = setTimeout(() => {
                fetch('get_components.php?search=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(results => {
                        lastResults = Array.isArray(results) ? results : [];
                        displaySuggestions(lastResults);
                    })
                    .catch(error => {
                        console.error('Erreur lors de la recherche des composants:', error);
                        closeSuggestions();
                    });
            }, 250);
        });

        // Navigation au clavier : flèches pour parcourir, Entrée pour valider, Échap pour fermer.
        nameInput.addEventListener('keydown', function(event) {
            if (suggestionsDiv.style.display !== 'block') { return; }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                highlight();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlight();
            } else if (event.key === 'Enter') {
                if (activeIndex >= 0) {
                    event.preventDefault();
                    selectItem(items[activeIndex]);
                }
            } else if (event.key === 'Escape') {
                closeSuggestions();
                updateDuplicateWarning();
            }
        });

        // Cacher les suggestions quand on clique ailleurs
        document.addEventListener('click', function(event) {
            if (!nameInput.contains(event.target) && !suggestionsDiv.contains(event.target)) {
                closeSuggestions();
                updateDuplicateWarning();
            }
        });
    }

    /* --- Code de marquage (CMS) -------------------------------------------
       Les boitiers CMS portent un code court (2 a 4 caracteres) au lieu de la
       reference complete. Ce champ sert uniquement de raccourci : la saisie
       propose les references correspondantes et remplit la fiche, comme la
       page « Marquage ». Il n'est pas enregistre (il n'est pas transmis). */

    // Renseigne nom, fabricant, boitier, datasheet et commentaire depuis une
    // correspondance de marquage.
    function applyMarking(item) {
        if (!item) { return; }

        const reference = item.part_number || item.name || '';
        const markingInput = document.getElementById('marking_code');

        // Le code lu sur le boitier fait partie du nom : « BAV99WPT - A7 ».
        const label = (reference !== '' && item.code) ? reference + ' - ' + item.code : reference;

        // Commentaire : code lu, fonction du boitier et caracteristiques
        // techniques, ex. « B4: NPN transistor Sw, 40V, 200mA, 200mW ».
        const details = [item.function, item.description].filter(Boolean).join(' ');
        const comment = [(item.code ? item.code + ':' : ''), details].filter(Boolean).join(' ');

        if (markingInput && item.code) { markingInput.value = item.code; }
        if (label) { document.getElementById('name').value = label; }

        nexarSelectManufacturer(item.manufacturer);

        if (item.package) {
            document.getElementById('package').value = item.package;
            checkAndSetPackageImage(item.package);
        }

        // La fiche technique se cherche sur la reference seule, pas sur le nom affiche.
        if (reference) { applyDatasheet('', reference, ''); }
        if (comment) { document.getElementById('comment').value = comment; }

        // Prévenir si ce nom existe déjà dans l'inventaire.
        checkDuplicate(label, item.manufacturer || '');
    }

    // Autocomplétion du code de marquage : propose les références dès le
    // premier caractère (les codes usuels font 2 à 4 caractères).
    function setupMarkingAutocomplete() {
        const markingInput = document.getElementById('marking_code');
        const suggestionsDiv = document.getElementById('marking-suggestions');

        if (!markingInput || !suggestionsDiv) { return; }

        let timeout;
        let items = [];
        let activeIndex = -1;

        function closeSuggestions() {
            suggestionsDiv.innerHTML = '';
            suggestionsDiv.style.display = 'none';
            items = [];
            activeIndex = -1;
        }

        function highlight() {
            Array.from(suggestionsDiv.children).forEach((child, index) => {
                child.style.background = (index === activeIndex) ? 'var(--bg-muted)' : '';
            });
            const current = suggestionsDiv.children[activeIndex];
            if (current && current.scrollIntoView) {
                current.scrollIntoView({ block: 'nearest' });
            }
        }

        function selectItem(item) {
            if (!item) { return; }
            closeSuggestions();
            applyMarking(item);
        }

        function displaySuggestions(results) {
            closeSuggestions();

            if (results.length === 0) { return; }

            items = results;

            results.forEach(item => {
                const row = document.createElement('div');
                row.className = 'suggestion-item';
                row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:10px;';

                const codeSpan = document.createElement('span');
                codeSpan.style.cssText = 'font-family:Consolas,monospace;font-weight:700;letter-spacing:0.05em;';
                codeSpan.textContent = item.code;
                row.appendChild(codeSpan);

                const details = [item.part_number || item.name, item.manufacturer, item.package]
                    .filter(Boolean).join(' · ');
                if (details !== '') {
                    const infoSpan = document.createElement('span');
                    infoSpan.style.cssText = 'color:var(--text-secondary);font-size:12px;white-space:nowrap;';
                    infoSpan.textContent = details;
                    row.appendChild(infoSpan);
                }

                // mousedown : le blur de l'input fermerait la liste avant le clic.
                row.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    selectItem(item);
                });

                suggestionsDiv.appendChild(row);
            });

            suggestionsDiv.style.display = 'block';
        }

        markingInput.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();

            if (query.length < 1) {
                closeSuggestions();
                return;
            }

            timeout = setTimeout(() => {
                fetch('marking_search.php?limit=12&search=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        displaySuggestions((data && data.results) ? data.results : []);
                    })
                    .catch(error => {
                        console.error('Erreur lors de la recherche de marquage:', error);
                        closeSuggestions();
                    });
            }, 250);
        });

        // Navigation au clavier : flèches pour parcourir, Entrée pour valider, Échap pour fermer.
        markingInput.addEventListener('keydown', function(event) {
            if (suggestionsDiv.style.display !== 'block') { return; }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                highlight();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlight();
            } else if (event.key === 'Enter') {
                if (activeIndex >= 0) {
                    event.preventDefault();
                    selectItem(items[activeIndex]);
                }
            } else if (event.key === 'Escape') {
                closeSuggestions();
            }
        });

        // Cacher les suggestions quand on clique ailleurs
        document.addEventListener('click', function(event) {
            if (!markingInput.contains(event.target) && !suggestionsDiv.contains(event.target)) {
                closeSuggestions();
            }
        });
    }

    // Reprise d'une recherche lancée depuis la page « Marquage CMS ».
    function consumeMarkingPrefill() {
        let raw;
        try {
            raw = window.sessionStorage.getItem('compozit.marking');
            window.sessionStorage.removeItem('compozit.marking');
        } catch (error) {
            return;
        }

        if (!raw) { return; }

        try {
            applyMarking(JSON.parse(raw));
        } catch (error) {
            // Contenu illisible : le formulaire reste vierge.
        }
    }

    // Fonction pour vérifier et définir automatiquement l'image du package
    function checkAndSetPackageImage(packageName) {
        // Vérifier si une image existe pour ce package
        const imageExtensions = ['png', 'jpg', 'jpeg', 'svg'];
        let imageFound = false;
        
        // Fonction pour tester chaque extension
        function testImageExtension(index) {
            if (index >= imageExtensions.length || imageFound) {
                return;
            }
            
            const ext = imageExtensions[index];
            const imagePath = `img/${packageName}.${ext}`;
            const img = new Image();
            
            img.onload = function() {
                    if (!imageFound) {
                        imageFound = true;
                        // Définir automatiquement l'image trouvée
                        document.getElementById('image_type').value = 'url';
                        toggleImageInput();
                        document.getElementById('image_url').value = imagePath;
                        
                        // S'assurer que le champ URL est activé mais garder le type text pour éviter la validation
                         const imageUrlInput = document.getElementById('image_url');
                         imageUrlInput.removeAttribute('disabled');
                         imageUrlInput.type = 'text'; // Utiliser 'text' car c'est un chemin relatif, pas une URL complète
                        
                        // Afficher l'aperçu de l'image
                        updateImagePreview();
                        
                        // Afficher un message informatif
                        const imageUrlGroup = document.getElementById('image_url_group');
                        if (!imageUrlGroup.querySelector('.auto-image-notice')) {
                            const notice = document.createElement('div');
                            notice.className = 'auto-image-notice';
                            notice.style.cssText = 'color: #28a745; font-size: 12px; margin-top: 5px;';
                            notice.textContent = '✓ Image du package détectée automatiquement';
                            imageUrlGroup.appendChild(notice);
                        }
                    }
                };
            
            img.onerror = function() {
                testImageExtension(index + 1);
            };
            
            img.src = imagePath;
        }
        
        // Commencer le test avec la première extension
        testImageExtension(0);
    }
    
    // Fonction pour mettre à jour l'aperçu de l'image
    function updateImagePreview() {
        const imageUrl = document.getElementById('image_url').value;
        const previewDiv = document.getElementById('image_preview');
        const previewImg = document.getElementById('preview_img');
        
        if (imageUrl.trim() !== '') {
            previewImg.src = imageUrl;
            previewDiv.style.display = 'block';
            
            // Gérer les erreurs de chargement d'image
            previewImg.onerror = function() {
                previewDiv.style.display = 'none';
            };
        } else {
            previewDiv.style.display = 'none';
        }
    }
    
        // Initialiser l'autocomplétion au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            setupPackageAutocomplete();
            setupNameAutocomplete();
            setupMarkingAutocomplete();
            consumeMarkingPrefill();
            toggleNewManufacturer();
            toggleNewSupplier();
            renderDynamicParams(document.getElementById('category').value);

            // Le lien « Aide » pointe vers le tutoriel de la source sélectionnée
            // dans les réglages des API (accordéon ouvert automatiquement).
            const sourceField = document.getElementById('api_source');
            if (sourceField) {
                sourceField.addEventListener('change', nexarUpdateHelpLink);
                nexarUpdateHelpLink();
            }

            // Dès que l'utilisateur modifie la datasheet à la main, le message
            // de repli n'est plus pertinent et la réponse différée est ignorée.
            document.getElementById('datasheet').addEventListener('input', function() {
                this.dataset.fallbackFor = '';
                document.getElementById('datasheet_hint').style.display = 'none';
                updateDatasheetTools();
            });

            const nameField = document.getElementById('name');
            const manufacturerField = document.getElementById('manufacturer');
            if (nameField) {
                nameField.addEventListener('blur', autoFillDatasheetIfMissing);
                nameField.addEventListener('change', autoFillDatasheetIfMissing);
            }
            if (manufacturerField) {
                manufacturerField.addEventListener('blur', autoFillDatasheetIfMissing);
                manufacturerField.addEventListener('change', autoFillDatasheetIfMissing);
            }
            autoFillDatasheetIfMissing();
            updateDatasheetTools();

            // Confirme le PDF de datasheet choisi (l'URL reste prioritaire).
            const datasheetPdf = document.getElementById('datasheet_pdf');
            if (datasheetPdf) {
                datasheetPdf.addEventListener('change', function() {
                    const hint = document.getElementById('datasheet_pdf_hint');
                    const file = this.files && this.files[0];

                    if (!file) {
                        hint.style.display = 'none';
                        return;
                    }

                    hint.textContent = '📎 PDF joint : ' + file.name
                        + ' (' + Math.round(file.size / 1024) + ' Ko) — il sera enregistré avec le composant.';
                    hint.style.display = 'block';
                });
            }
        });
        </script>

        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em; border-radius: var(--radius-lg); max-width: 1480px; margin-left: auto; margin-right: auto;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
</body>
</html>
