<?php
require_once 'session_init.php';
require_once 'config.php';
require_once 'package_utils.php';
require_once 'component_params.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: components.php?error=invalid_id');
    exit();
}

$component_id = (int)$_GET['id'];

try {
    $pdo = getConnection();
    
    $stmt = $pdo->prepare("SELECT * FROM data WHERE id = ? AND owner = ?");
    $stmt->execute([$component_id, $_SESSION['user_id']]);
    $component = $stmt->fetch();
    
    if (!$component) {
        header('Location: components.php?error=component_not_found');
        exit();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $package = trim($_POST['package'] ?? '');
        $pins = !empty($_POST['pins']) ? (int)$_POST['pins'] : null;
        $smd = $_POST['smd'] ?? 'No';
        $quantity = (int)($_POST['quantity'] ?? 1);
        $order_quantity = (int)($_POST['order_quantity'] ?? 0);
        $price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
        $location_id = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $datasheet = sanitizeExternalUrl($_POST['datasheet'] ?? '');
        $comment = trim($_POST['comment'] ?? '');
        $category = !empty($_POST['category']) ? (int)$_POST['category'] : null;
        $public = $_POST['public'] ?? 'No';
        $url = sanitizeExternalUrl($_POST['url'] ?? '');
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $supplier_reference = trim($_POST['supplier_reference'] ?? '');
        
        $image_path = $component['image_path'];
        $image_type = $_POST['image_type'] ?? '';
        
        if ($image_type === 'existing' && !empty($_POST['existing_image'])) {
            $image_path = trim($_POST['existing_image']);
        } elseif ($image_type === 'upload' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'img/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'component_' . $component_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                    if ($image_path && file_exists($image_path)) {
                        unlink($image_path);
                    }
                    $image_path = $upload_path;
                }
            }
        } elseif ($image_type === 'url' && !empty($_POST['image_url'])) {
            $image_url = trim($_POST['image_url']);

            if (strpos($image_url, 'img/') === 0 && file_exists($image_url)) {
                // Image déjà présente dans le dossier img/
                $image_path = $image_url;
            } else {
                $remoteUrl = sanitizeExternalUrl($image_url);
                if ($remoteUrl !== '') {
                    // Rapatrier l'image distante sous img/<Nom>.<ext> (repli sur l'URL)
                    $image_path = saveComponentImage($name, $remoteUrl);
                }
            }
        } elseif ($image_type === 'none') {
            if ($image_path && file_exists($image_path) && strpos($image_path, 'uploads/') === 0) {
                unlink($image_path);
            }
            $image_path = null;
        }
        
        if (empty($name)) {
            $error = "Le nom du composant est obligatoire.";
        } elseif ($quantity < 0) {
            $error = "La quantité ne peut pas être négative.";
        } else {
            $sql = "UPDATE data SET 
                name = ?, manufacturer = ?, package = ?, pins = ?, smd = ?, marking = ?,
                quantity = ?, order_quantity = ?, price = ?, location_id = ?, datasheet = ?, 
                comment = ?, category = ?, public = ?, url = ?, image_path = ?, 
                supplier_id = ?, supplier_reference = ?
                WHERE id = ? AND owner = ?";
            
            try {
                $stmt = $pdo->prepare($sql);
                $result = $stmt->execute([
                    $name,
                    $manufacturer ?: null,
                    $package ?: null,
                    $pins,
                    $smd,
                    trim((string) ($_POST['marking'] ?? '')) ?: null,
                    $quantity,
                    $order_quantity,
                    $price,
                    $location_id,
                    $datasheet ?: null,
                    $comment ?: null,
                    $category,
                    $public,
                    $url ?: null,
                    $image_path,
                    $supplier_id,
                    $supplier_reference ?: null,
                    $component_id,
                    $_SESSION['user_id']
                ]);
                
                if ($result) {
                    compozitSaveComponentParams($pdo, $component_id, $category, $_POST);

                    // Créer le boîtier s'il n'existe pas encore, et enregistrer son image
                    registerComponentPackage($pdo, $package, $pins, $smd, $image_path, (int) $_SESSION['user_id'], $name);

                    header('Location: components.php?success=component_updated');
                    exit();
                } else {
                    $error = "Erreur lors de la mise à jour du composant.";
                }
            } catch(PDOException $e) {
                error_log("Erreur PDO lors de la mise à jour : " . $e->getMessage());
                $error = "❌ Erreur de base de données : " . $e->getMessage();
            }
        }
    }
    
    $stmt = $pdo->query("SELECT * FROM category_head ORDER BY name");
    $categories = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT cs.*, ch.name as parent_name, cs.category_head_id as parent_id FROM category_sub cs LEFT JOIN category_head ch ON cs.category_head_id = ch.id ORDER BY cs.category_head_id, cs.name");
    $subcategories = $stmt->fetchAll();
    $paramDefinitionsMap = compozitGetAllParamDefinitionsMap($pdo);
    $componentParamValues = compozitGetComponentParamValues($pdo, $component_id);
    
    $component_category_head = null;
    if ($component['category']) {
        foreach ($subcategories as $subcat) {
            if ($subcat['id'] == $component['category']) {
                $component_category_head = $subcat['parent_id'];
                break;
            }
        }
    }
    
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
    
    $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
    $suppliers = $stmt->fetchAll();

    // Récupérer les fabricants de l'utilisateur
    $stmt = $pdo->prepare("SELECT name FROM manufacturers WHERE owner = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $manufacturers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Récupérer les packages existants
    $stmt = $pdo->query("SELECT name FROM packages ORDER BY name");
    $packages_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch(PDOException $e) {
    error_log("Erreur lors de la modification du composant : " . $e->getMessage());
    header('Location: components.php?error=database_error');
    exit();
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
    <title>Modifier un Composant</title>
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
        .btn-primary { background: var(--accent-green); color: white; }
        .btn-secondary { background: var(--accent-blue); color: white; }
        .btn-success { background: var(--accent-green); color: white; }

        .content-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
        }
        .back-link {
            margin-bottom: 20px;
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
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .error {
            background: #fef2f2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid #fecaca;
            font-size: 14px;
        }
        .location-available {
            background-color: #dcfce7 !important;
            color: #166534 !important;
        }
        .location-occupied {
            background-color: #fef2f2 !important;
            color: #991b1b !important;
        }
        .autocomplete-wrapper {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            box-shadow: var(--shadow-md);
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
        }
        .suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
        }
        .suggestion-item:hover {
            background-color: var(--bg-muted);
        }
        .suggestion-item:last-child {
            border-bottom: none;
        }
        .existing-images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .existing-image-item {
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 5px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .existing-image-item:hover {
            border-color: var(--accent-indigo);
        }
        .existing-image-item.selected {
            border-color: var(--accent-green);
            background-color: var(--bg-muted);
        }
        .existing-image-item img {
            width: 100%;
            height: 80px;
            object-fit: cover;
            border-radius: 3px;
        }
        .existing-image-item .image-name {
            font-size: 12px;
            margin-top: 5px;
            word-break: break-word;
            color: var(--text-secondary);
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
        .form-actions {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        footer {
            margin-top: 2rem;
            padding: 1rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-card);
            color: var(--text-secondary);
            font-size: 0.9em;
            border-radius: var(--radius-lg);
        }

        /* --- Menu mobile : bulle en haut à gauche + tiroir -------------------- */
        .cz-mob-btn,
        .cz-mob-overlay,
        .cz-mob-drawer {
            display: none;
        }

        /* --- Adaptation smartphone ------------------------------------------- */
        @media (max-width: 700px) {
            /* Le menu du haut (en-tete + navigation) passe dans la bulle */
            body.cz-site-modern .cz-sidebar,
            body.cz-site-modern .app-header {
                display: none !important;
            }

            .container {
                padding: 66px 12px 12px;
            }

            .content-card {
                padding: 16px;
                border-radius: var(--radius-md);
            }

            /* Le formulaire passe sur une seule colonne */
            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                margin-bottom: 16px;
            }

            /* 16px : evite le zoom automatique des champs sur iOS */
            .form-group input,
            .form-group select,
            .form-group textarea {
                font-size: 16px;
            }

            .existing-images-grid {
                grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
            }

            .dynamic-params-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            /* --- Bulle et tiroir --- */
            .cz-mob-btn {
                display: grid;
                place-items: center;
                position: fixed;
                top: 12px;
                left: 12px;
                width: 46px;
                height: 46px;
                border-radius: 50%;
                border: 1px solid #243858;
                background: #0d182c;
                color: #e8eefb;
                font-size: 20px;
                line-height: 1;
                cursor: pointer;
                z-index: 1200;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .45);
            }

            .cz-mob-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(3, 7, 16, .62);
                opacity: 0;
                visibility: hidden;
                transition: opacity .22s ease, visibility .22s;
                z-index: 1250;
            }

            .cz-mob-overlay.is-open {
                opacity: 1;
                visibility: visible;
            }

            .cz-mob-drawer {
                display: block;
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: min(86vw, 330px);
                padding: 18px 14px 24px;
                background: #061127;
                border-right: 1px solid #14213a;
                overflow-y: auto;
                transform: translateX(-100%);
                transition: transform .22s ease;
                z-index: 1300;
            }

            .cz-mob-drawer.is-open {
                transform: translateX(0);
            }

            .cz-mob-close {
                position: absolute;
                top: 12px;
                right: 12px;
                width: 34px;
                height: 34px;
                border-radius: 9px;
                border: 1px solid #243858;
                background: #101a2d;
                color: #c3cee2;
                font-size: 15px;
                line-height: 1;
                cursor: pointer;
            }

            .cz-mob-foot {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-top: 18px;
                padding-top: 16px;
                border-top: 1px solid #16233c;
            }

            .cz-mob-logout {
                display: block;
                text-align: center;
                padding: 12px 14px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                background: #101a2d;
                color: #c3cee2;
                border: 1px solid #243858;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">✏️</div>
                    <div>
                        <h1>Modifier un Composant</h1>
                        <p>Mettez à jour les informations du composant</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <nav class="nav-buttons">
                <a href="components.php">← Retour</a>
                <a href="components.php">📦 Composants</a>
                <a href="add_component.php">➕ Créer</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </nav>
        </header>

        <div class="content-card">
            <div class="back-link">
                <a href="components.php" class="btn btn-ghost">← Retour à la liste</a>
            </div>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Nom du composant *</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($component['name']); ?>" required>
                    </div>
                    <div class="form-group autocomplete-wrapper">
                        <label for="manufacturer">Fabricant</label>
                        <input type="text" id="manufacturer" name="manufacturer" value="<?php echo htmlspecialchars($component['manufacturer'] ?? ''); ?>" autocomplete="off">
                        <div id="manufacturer-suggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div class="form-group autocomplete-wrapper">
                        <label for="package">Package</label>
                        <input type="text" id="package" name="package" value="<?php echo htmlspecialchars($component['package'] ?? ''); ?>" autocomplete="off">
                        <div id="package-suggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div class="form-group">
                        <label for="pins">Nombre de pins</label>
                        <input type="number" id="pins" name="pins" value="<?php echo $component['pins'] ?? ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="smd">SMD</label>
                        <select id="smd" name="smd">
                            <option value="No" <?php echo $component['smd'] === 'No' ? 'selected' : ''; ?>>Non</option>
                            <option value="Yes" <?php echo $component['smd'] === 'Yes' ? 'selected' : ''; ?>>Oui</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="marking">Code de marquage (CMS)</label>
                        <input type="text" id="marking" name="marking" value="<?php echo htmlspecialchars($component['marking'] ?? ''); ?>" autocomplete="off" spellcheck="false" placeholder="Ex: A7, B8, 65V…">
                    </div>
                    <div class="form-group">
                        <label for="quantity">Quantité *</label>
                        <input type="number" id="quantity" name="quantity" value="<?php echo $component['quantity']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="order_quantity">Quantité à commander</label>
                        <input type="number" id="order_quantity" name="order_quantity" value="<?php echo $component['order_quantity'] ?? 0; ?>">
                    </div>
                    <div class="form-group">
                        <label for="price">Prix (€)</label>
                        <input type="number" id="price" name="price" step="0.01" min="0" value="<?php echo $component['price'] ?? ''; ?>" placeholder="Ex: 2.50">
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
                                <option value="<?php echo $location['id']; ?>" class="<?php echo $statusClass; ?>" <?php echo $component['location_id'] == $location['id'] ? 'selected' : ''; ?>>
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
                                <option value="<?php echo $cat['id']; ?>" <?php echo $component_category_head == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="category">Sous-catégorie</label>
                        <select id="category" name="category" onchange="loadSubcategoryImages()">
                            <option value="">Sélectionner d'abord une catégorie principale</option>
                            <?php foreach ($subcategories as $subcat): ?>
                                <option value="<?php echo $subcat['id']; ?>" data-parent="<?php echo $subcat['parent_id']; ?>" <?php echo $component['category'] == $subcat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subcat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group dynamic-params-group" id="dynamic_params_group" style="display: none;">
                        <div class="dynamic-params-head">
                            <strong>Paramètres techniques Diodes</strong>
                            <span>Ces champs servent à détailler la diode et à alimenter la recherche d'équivalents.</span>
                        </div>
                        <div class="dynamic-params-grid" id="dynamic_params_container"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="supplier_id">Fournisseur</label>
                        <select id="supplier_id" name="supplier_id">
                            <option value="">Sélectionner un fournisseur</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $component['supplier_id'] == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="supplier_reference">Référence fournisseur</label>
                        <input type="text" id="supplier_reference" name="supplier_reference" value="<?php echo htmlspecialchars($component['supplier_reference'] ?? ''); ?>" placeholder="Ex: REF-12345">
                    </div>

                    <div class="form-group">
                        <label for="datasheet">Datasheet (URL)</label>
                        <input type="url" id="datasheet" name="datasheet" value="<?php echo htmlspecialchars($component['datasheet'] ?? ''); ?>">
                        <div id="datasheet_hint" style="display:none; margin-top:8px; font-size:12.5px; color: var(--text-secondary);"></div>
                        <div class="datasheet-tools">
                            <button type="button" id="datasheet_open_btn" class="datasheet-tool-btn" disabled onclick="openEditDatasheetLink()">📄 Ouvrir la datasheet</button>
                        </div>
                        <?php if (!empty($component['datasheet_file'])): ?>
                            <div style="margin-top: 8px; font-size: 12.5px; color: var(--text-muted);">
                                PDF joint :
                                <a href="<?php echo htmlspecialchars($component['datasheet_file']); ?>" target="_blank">voir le fichier</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="public">
                            <input type="checkbox" id="public" name="public" value="1" <?php echo $component['public'] === 'Yes' ? 'checked' : ''; ?>>
                            Composant public
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="image_type">Type d'image</label>
                    <select id="image_type" name="image_type" onchange="toggleImageInput()">
                        <option value="">Conserver l'image actuelle</option>
                        <option value="existing">Image existante</option>
                        <option value="upload">Importer une image</option>
                        <option value="url">Lien vers une image</option>
                        <option value="none">Supprimer l'image</option>
                    </select>
                    <?php
                    // Aperçu de l'image actuellement liée. Le chemin peut être un fichier
                    // local (img/xxx.jpg) ou une URL distante (image importée via une API).
                    $editImage = trim((string)($component['image_path'] ?? ''));
                    $editImageIsUrl = $editImage !== '' && preg_match('#^(https?:)?//#i', $editImage);
                    $editImageExists = $editImage !== '' && ($editImageIsUrl || file_exists($editImage));
                    ?>
                    <div id="current_image_preview" style="margin-top: 12px; display: flex; align-items: center; gap: 14px;">
                        <?php if ($editImageExists): ?>
                            <img src="<?php echo htmlspecialchars($editImage); ?>" alt="Image actuelle" style="max-width: 90px; max-height: 90px; object-fit: contain; background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 4px;">
                            <div style="font-size: 12px; color: var(--text-secondary); word-break: break-all;">
                                <strong>Image actuelle</strong><br>
                                <code><?php echo htmlspecialchars($editImage); ?></code>
                            </div>
                        <?php elseif ($editImage !== ''): ?>
                            <div style="font-size: 13px; color: #b91c1c;">⚠️ Image liée introuvable : <code><?php echo htmlspecialchars($editImage); ?></code></div>
                        <?php else: ?>
                            <div style="font-size: 13px; color: var(--text-secondary);">📷 Aucune image liée à ce composant</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group" id="existing_images_group" style="display: none;">
                    <label>Images existantes de la même sous-catégorie</label>
                    <div id="existing-images-container"></div>
                    <input type="hidden" id="selected_existing_image" name="existing_image">
                </div>
                <div class="form-group" id="image_upload_group" style="display: none;">
                    <label for="image">Image du composant (fichier)</label>
                    <input type="file" id="image" name="image" accept="image/*">
                </div>
                <div class="form-group" id="image_url_group" style="display: none;">
                    <label for="image_url">URL de l'image</label>
                    <input type="url" id="image_url" name="image_url" placeholder="https://exemple.com/image.jpg" onchange="updateImagePreview()">
                    <div id="image_preview" style="margin-top: 10px; display: none;">
                        <img id="preview_img" src="" alt="Aperçu" style="max-width: 150px; max-height: 150px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                </div>
                <div class="form-group">
                    <label for="comment">Commentaire</label>
                    <textarea id="comment" name="comment" rows="3"><?php echo htmlspecialchars($component['comment'] ?? ''); ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Sauvegarder les modifications</button>
                    <a href="components.php" class="btn btn-ghost">Annuler</a>
                </div>
            </form>
        </div>

        <footer>
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>

    <script>
        const diodeParamDefinitions = <?php echo json_encode($paramDefinitionsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const dynamicParamValues = <?php echo json_encode($componentParamValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const allSubcategories = [];
        <?php foreach ($subcategories as $subcat): ?>
        allSubcategories.push({
            id: '<?php echo $subcat['id']; ?>',
            name: '<?php echo addslashes($subcat['name']); ?>',
            parent_id: '<?php echo $subcat['parent_id']; ?>',
            selected: <?php echo $component['category'] == $subcat['id'] ? 'true' : 'false'; ?>
        });
        <?php endforeach; ?>

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
        
        function updateSubcategories() {
            const categoryHead = document.getElementById('category_head');
            const category = document.getElementById('category');
            const selectedParentId = categoryHead.value;
            const currentSelection = category.value;
            
            category.innerHTML = '<option value="">Sélectionner une sous-catégorie</option>';
            
            if (selectedParentId) {
                allSubcategories.forEach(subcat => {
                    if (subcat.parent_id === selectedParentId) {
                        const option = document.createElement('option');
                        option.value = subcat.id;
                        option.textContent = subcat.name;
                        if (subcat.selected || subcat.id === currentSelection) {
                            option.selected = true;
                        }
                        category.appendChild(option);
                    }
                });
            } else {
                category.innerHTML = '<option value="">Sélectionner d\'abord une catégorie principale</option>';
            }

            renderDynamicParams(category.value);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($component_category_head): ?>
            document.getElementById('category_head').value = '<?php echo $component_category_head; ?>';
            <?php endif; ?>
            updateSubcategories();
            renderDynamicParams(document.getElementById('category').value);
        });
         
         function toggleImageInput() {
             const imageType = document.getElementById('image_type').value;
             const uploadGroup = document.getElementById('image_upload_group');
             const urlGroup = document.getElementById('image_url_group');
             const existingGroup = document.getElementById('existing_images_group');
             const imageUrlInput = document.getElementById('image_url');
             
             uploadGroup.style.display = 'none';
             urlGroup.style.display = 'none';
             existingGroup.style.display = 'none';
             
             if (imageType === 'url') {
                 urlGroup.style.display = 'block';
                 imageUrlInput.removeAttribute('disabled');
                 imageUrlInput.type = 'url';
             } else {
                 imageUrlInput.setAttribute('disabled', 'disabled');
                 imageUrlInput.type = 'text';
                 if (imageType === 'upload') {
                     uploadGroup.style.display = 'block';
                 } else if (imageType === 'existing') {
                     existingGroup.style.display = 'block';
                     loadSubcategoryImages();
                 }
             }
         }
         
         function loadSubcategoryImages() {
             const categorySelect = document.getElementById('category');
             const subcategoryId = categorySelect.value;
             const container = document.getElementById('existing-images-container');

             renderDynamicParams(subcategoryId);
             
             if (!subcategoryId) {
                 container.innerHTML = '<p style="color: var(--text-secondary); padding: 10px;">Veuillez d\'abord sélectionner une sous-catégorie.</p>';
                 return;
             }
             
             fetch('get_subcategory_images.php?subcategory_id=' + subcategoryId)
                 .then(response => response.json())
                 .then(data => {
                     displayExistingImages(data);
                 })
                 .catch(error => {
                     console.error('Erreur:', error);
                     container.innerHTML = '<p style="color: var(--accent-red);">Erreur lors du chargement des images.</p>';
                 });
         }
         
         function displayExistingImages(images) {
             const container = document.getElementById('existing-images-container');
             
             if (images.length === 0) {
                 container.innerHTML = '<p style="color: var(--text-secondary); padding: 10px;">Aucune image trouvée pour cette sous-catégorie.</p>';
                 return;
             }
             
             let html = '<div class="existing-images-grid">';
             images.forEach(image => {
                 const imageName = image.image_path.split('/').pop();
                 html += `
                     <div class="existing-image-item" onclick="selectExistingImage('${image.image_path}', this)">
                         <img src="${image.image_path}" alt="${imageName}" onerror="this.src='img/placeholder.png'">
                         <div class="image-name">${imageName}</div>
                     </div>
                 `;
             });
             html += '</div>';
             
             container.innerHTML = html;
         }
         
         function selectExistingImage(imagePath, element) {
             document.querySelectorAll('.existing-image-item').forEach(item => {
                 item.classList.remove('selected');
             });
             
             element.classList.add('selected');
             document.getElementById('selected_existing_image').value = imagePath;
         }
         
         function setupPackageAutocomplete() {
             const packageInput = document.getElementById('package');
             const suggestionsDiv = document.getElementById('package-suggestions');
             
             packageInput.addEventListener('input', function() {
                 const query = this.value.trim();
                 
                 if (query.length === 0) {
                     suggestionsDiv.innerHTML = '';
                     suggestionsDiv.style.display = 'none';
                     return;
                 }
                 
                 fetch('get_packages.php?search=' + encodeURIComponent(query))
                     .then(response => response.json())
                     .then(data => {
                         if (Array.isArray(data) && data.length > 0) {
                             let html = '';
                             data.forEach(pkg => {
                                 const pkgName = (typeof pkg === 'string') ? pkg : (pkg.name || '');
                                 if (!pkgName) return;
                                 const extra = [];
                                 if (typeof pkg === 'object' && pkg.package_type) extra.push(pkg.package_type);
                                 if (typeof pkg === 'object' && pkg.pin_count) extra.push(`${pkg.pin_count} pins`);
                                 if (typeof pkg === 'object' && pkg.mounting_type) extra.push(pkg.mounting_type);
                                 const extraHtml = extra.length ? ` <small style="color:var(--text-muted); margin-left:8px;">${extra.join(' · ')}</small>` : '';
                                 html += `<div class="suggestion-item" onclick="selectPackage('${pkgName.replace(/'/g, "\\'")}')">${pkgName}${extraHtml}</div>`;
                             });
                             suggestionsDiv.innerHTML = html;
                             suggestionsDiv.style.display = 'block';
                         } else {
                             suggestionsDiv.innerHTML = '';
                             suggestionsDiv.style.display = 'none';
                         }
                     })
                     .catch(error => {
                         console.error('Erreur:', error);
                         suggestionsDiv.innerHTML = '';
                         suggestionsDiv.style.display = 'none';
                     });
             });
             
             document.addEventListener('click', function(e) {
                 if (!packageInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                     suggestionsDiv.style.display = 'none';
                 }
             });
         }
         
         function setupManufacturerAutocomplete() {
             const manuInput = document.getElementById('manufacturer');
             const suggestionsDiv = document.getElementById('manufacturer-suggestions');
             if (!manuInput || !suggestionsDiv) return;
             const manuList = Array.isArray(MANUFACTURERS_LIST) ? MANUFACTURERS_LIST : [];
             
             manuInput.addEventListener('input', function() {
                 const query = this.value.trim().toLowerCase();
                 if (query.length === 0) {
                     suggestionsDiv.innerHTML = '';
                     suggestionsDiv.style.display = 'none';
                     return;
                 }
                 
                 const matches = manuList.filter(m => m.toLowerCase().includes(query)).slice(0, 20);
                 
                 if (matches.length > 0) {
                     suggestionsDiv.innerHTML = matches.map(m => 
                         `<div class="suggestion-item" onclick="selectManufacturer('${m.replace(/'/g, "\\'")}')">${m}</div>`
                     ).join('');
                     suggestionsDiv.style.display = 'block';
                 } else {
                     suggestionsDiv.innerHTML = '';
                     suggestionsDiv.style.display = 'none';
                 }
             });
             
             document.addEventListener('click', function(e) {
                 if (!manuInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                     suggestionsDiv.style.display = 'none';
                 }
             });
         }
         
         function selectManufacturer(manufacturerName) {
             const el = document.getElementById('manufacturer');
             if (el) el.value = manufacturerName;
             const div = document.getElementById('manufacturer-suggestions');
             if (div) div.style.display = 'none';
         }
         
         function selectPackage(packageName) {
             document.getElementById('package').value = packageName;
             document.getElementById('package-suggestions').style.display = 'none';
             checkAndSetPackageImage(packageName);
         }
         
         function checkAndSetPackageImage(packageName) {
             const imageExtensions = ['png', 'jpg', 'jpeg', 'svg'];
             let imageFound = false;
             
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
                         document.getElementById('image_type').value = 'url';
                         toggleImageInput();
                         document.getElementById('image_url').value = imagePath;
                         
                         const imageUrlInput = document.getElementById('image_url');
                         imageUrlInput.removeAttribute('disabled');
                         imageUrlInput.type = 'text';
                         
                         updateImagePreview();
                         
                         const imageUrlGroup = document.getElementById('image_url_group');
                         if (!imageUrlGroup.querySelector('.auto-image-notice')) {
                             const notice = document.createElement('div');
                             notice.className = 'auto-image-notice';
                             notice.style.cssText = 'color: var(--accent-green); font-size: 12px; margin-top: 5px;';
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
             
             testImageExtension(0);
         }
         
         const editDatasheetCache = {};

         function getEditDatasheetUrl() {
             const input = document.getElementById('datasheet');
             return input ? input.value.trim() : '';
         }

         function updateEditDatasheetTools() {
             const openBtn = document.getElementById('datasheet_open_btn');
             if (openBtn) {
                 openBtn.disabled = (getEditDatasheetUrl() === '');
             }
         }

         function openEditDatasheetLink() {
             const url = getEditDatasheetUrl();
             if (!url) { return; }
             window.open(url, '_blank', 'noopener');
         }

         function autoFillEditDatasheetIfMissing() {
             const input = document.getElementById('datasheet');
             const hint = document.getElementById('datasheet_hint');
             const nameInput = document.getElementById('name');
             const manufacturerInput = document.getElementById('manufacturer');

             if (!input || !hint || !nameInput || !manufacturerInput) { return; }
             if (input.value.trim() !== '') {
                 updateEditDatasheetTools();
                 return;
             }

             const mpn = nameInput.value.trim();
             const manufacturer = manufacturerInput.value.trim();
             if (mpn.length < 2) {
                 hint.style.display = 'none';
                 return;
             }

             const key = mpn + '|' + manufacturer;
             input.dataset.fallbackFor = key;
             input.value = 'https://www.alldatasheet.com/view.jsp?Searchword=' + encodeURIComponent(mpn);
             hint.textContent = '🔎 Aucune datasheet enregistrée : lien de recherche alldatasheet proposé pour « ' + mpn + ' ». Recherche d\'un PDF officiel en cours…';
             hint.style.display = 'block';
             updateEditDatasheetTools();

             const upgrade = function(entry) {
                 if (input.dataset.fallbackFor !== key) { return; }
                 if (entry.url) {
                     input.value = entry.url;
                     hint.textContent = '📄 Fiche technique récupérée depuis ' + entry.source + ' pour « ' + mpn + ' ». Modifiable.';
                 } else {
                     hint.textContent = '🔎 Aucune datasheet trouvée automatiquement : lien de recherche alldatasheet proposé pour « ' + mpn + ' ». Modifiable.';
                 }
                 updateEditDatasheetTools();
             };

             if (editDatasheetCache[key] !== undefined) {
                 upgrade(editDatasheetCache[key]);
                 return;
             }

             const params = new URLSearchParams({
                 mpn: mpn,
                 manufacturer: manufacturer
             });

             fetch('datasheet_lookup.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                 .then(response => response.json())
                 .then(data => {
                     const entry = (data && data.ok && data.datasheet)
                         ? { url: data.datasheet, source: data.source || 'Datasheets.com' }
                         : { url: '', source: '' };
                     editDatasheetCache[key] = entry;
                     upgrade(entry);
                 })
                 .catch(() => {
                     editDatasheetCache[key] = { url: '', source: '' };
                     upgrade(editDatasheetCache[key]);
                 });
         }

         const MANUFACTURERS_LIST = <?php echo json_encode($manufacturers ?? [], JSON_UNESCAPED_UNICODE); ?>;
         
         document.addEventListener('DOMContentLoaded', function() {
             setupPackageAutocomplete();
             setupManufacturerAutocomplete();

             const datasheetInput = document.getElementById('datasheet');
             const nameInput = document.getElementById('name');
             const manufacturerInput = document.getElementById('manufacturer');
             const hint = document.getElementById('datasheet_hint');

             if (datasheetInput && hint) {
                 datasheetInput.addEventListener('input', function() {
                     this.dataset.fallbackFor = '';
                     hint.style.display = 'none';
                     updateEditDatasheetTools();
                 });
             }

             if (nameInput) {
                 nameInput.addEventListener('blur', autoFillEditDatasheetIfMissing);
                 nameInput.addEventListener('change', autoFillEditDatasheetIfMissing);
             }
             if (manufacturerInput) {
                 manufacturerInput.addEventListener('blur', autoFillEditDatasheetIfMissing);
                 manufacturerInput.addEventListener('change', autoFillEditDatasheetIfMissing);
             }

             autoFillEditDatasheetIfMissing();
             updateEditDatasheetTools();
         });
        
        function updateImagePreview() {
            const imageUrl = document.getElementById('image_url').value;
            const previewDiv = document.getElementById('image_preview');
            const previewImg = document.getElementById('preview_img');
            
            if (imageUrl) {
                previewImg.src = imageUrl;
                previewImg.onload = function() {
                    previewDiv.style.display = 'block';
                };
                previewImg.onerror = function() {
                    previewDiv.style.display = 'none';
                };
            } else {
                previewDiv.style.display = 'none';
            }
        }
    </script>

    <!-- Menu mobile : bulle en haut à gauche + tiroir (téléphone uniquement) -->
    <button type="button" class="cz-mob-btn" id="czMobBtn" aria-label="Ouvrir le menu" aria-expanded="false">&#9776;</button>
    <div class="cz-mob-overlay" id="czMobOverlay"></div>
    <nav class="cz-mob-drawer" id="czMobDrawer" aria-label="Menu principal">
        <button type="button" class="cz-mob-close" id="czMobClose" aria-label="Fermer le menu">&#10005;</button>
        <div id="czMobBody"></div>
        <div class="cz-mob-foot">
            <a href="logout.php" class="cz-mob-logout">&#128682; Déconnexion</a>
        </div>
    </nav>

    <script>
    (function () {
        'use strict';

        var MOBILE_QUERY = '(max-width: 700px)';

        function isMobile() {
            return window.matchMedia ? window.matchMedia(MOBILE_QUERY).matches : false;
        }

        function init() {
            var btn = document.getElementById('czMobBtn');
            var drawer = document.getElementById('czMobDrawer');
            var overlay = document.getElementById('czMobOverlay');
            var closeBtn = document.getElementById('czMobClose');
            var slot = document.getElementById('czMobBody');
            if (!btn || !drawer || !overlay || !slot) {
                return;
            }

            // Reproduire le menu de navigation construit par theme.js
            var sidebar = document.querySelector('.cz-sidebar');
            if (sidebar) {
                for (var i = 0; i < sidebar.children.length; i++) {
                    slot.appendChild(sidebar.children[i].cloneNode(true));
                }
            }

            function openDrawer() {
                drawer.classList.add('is-open');
                overlay.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                document.body.style.overflow = 'hidden';
            }

            function closeDrawer() {
                drawer.classList.remove('is-open');
                overlay.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }

            btn.addEventListener('click', function () {
                if (drawer.classList.contains('is-open')) {
                    closeDrawer();
                } else {
                    openDrawer();
                }
            });
            overlay.addEventListener('click', closeDrawer);
            if (closeBtn) {
                closeBtn.addEventListener('click', closeDrawer);
            }
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeDrawer();
                }
            });

            var links = drawer.querySelectorAll('a');
            for (var l = 0; l < links.length; l++) {
                links[l].addEventListener('click', closeDrawer);
            }

            window.addEventListener('resize', function () {
                if (!isMobile()) {
                    closeDrawer();
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
    </script>
</body>
</html>
