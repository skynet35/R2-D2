<?php
require_once 'session_init.php';
require_once 'config.php';
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

// Récupérer les filtres
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : null;
$subcategory_filter = isset($_GET['subcategory']) ? (int)$_GET['subcategory'] : null;

// Construire la requête avec filtres
$sql = "
    SELECT d.*, cs.name as subcategory_name, ch.name as category_name,
           l.casier, l.tiroir, l.compartiment, l.storage_type, s.name as supplier_name
    FROM data d 
    LEFT JOIN category_sub cs ON d.category = cs.id 
    LEFT JOIN category_head ch ON cs.category_head_id = ch.id 
    LEFT JOIN location l ON d.location_id = l.id
    LEFT JOIN suppliers s ON d.supplier_id = s.id
    WHERE d.owner = ?";

$params = [$_SESSION['user_id']];

if ($category_filter) {
    $sql .= " AND ch.id = ?";
    $params[] = $category_filter;
}

if ($subcategory_filter) {
    $sql .= " AND cs.id = ?";
    $params[] = $subcategory_filter;
}

$sql .= " ORDER BY d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$components = $stmt->fetchAll();
$componentParamRowsMap = !empty($components)
    ? compozitGetComponentParamRowsMap($pdo, array_column($components, 'id'))
    : [];

// Récupérer les catégories pour le menu de filtrage
$stmt = $pdo->query("SELECT * FROM category_head ORDER BY name");
$categories = $stmt->fetchAll();

// Récupérer les sous-catégories
$stmt = $pdo->query("SELECT * FROM category_sub ORDER BY category_head_id, name");
$subcategories = $stmt->fetchAll();

// Grouper les sous-catégories par catégorie principale
$subcategories_by_category = [];
foreach ($subcategories as $subcat) {
    $subcategories_by_category[$subcat['category_head_id']][] = $subcat;
}

$equivalentDefinitions = [];
$equivalentValues = [];
$equivalentMatches = [];
$equivalentParamRowsMap = [];
$hasEquivalentSearch = false;

if ($subcategory_filter) {
    $equivalentDefinitions = compozitGetParamDefinitions($pdo, $subcategory_filter);
    foreach ($equivalentDefinitions as $definition) {
        $key = $definition['param_key'];
        $equivalentValues[$key] = trim((string) ($_GET['eq_' . $key] ?? ''));
        if ($equivalentValues[$key] !== '') {
            $hasEquivalentSearch = true;
        }
    }

    if ($hasEquivalentSearch) {
        $equivalentMatches = compozitBuildEquivalentMatches($pdo, (int) $_SESSION['user_id'], $subcategory_filter, $equivalentValues);
        if (!empty($equivalentMatches)) {
            $equivalentParamRowsMap = compozitGetComponentParamRowsMap($pdo, array_column($equivalentMatches, 'id'));
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
    <title>CompoZ'IT - Gestion de composants électroniques</title>
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
            background: var(--accent-green);
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

        .content {
            padding: 30px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }



        .components-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--bg-card);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .components-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 10px;
            text-align: left;
            font-weight: bold;
        }

        .components-table td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .components-table tr:hover {
            background: var(--bg-subtle);
        }

        .quantity-badge {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .category-badge {
            background: #6c757d;
            color: white;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 11px;
        }

        .no-components {
            text-align: center;
            padding: 50px;
            color: var(--text-secondary);
            font-size: 18px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .filter-section {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* Recherche instantanee dans la liste des composants */
        .search-section {
            background: var(--bg-card);
            padding: 18px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-subtle);
            border: 2px solid var(--border-color);
            border-radius: 999px;
            padding: 2px 8px 2px 18px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .search-box:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.15);
        }

        .search-icon {
            font-size: 17px;
            opacity: 0.7;
            flex-shrink: 0;
        }

        .search-box input {
            flex: 1;
            min-width: 0;
            border: none;
            background: transparent;
            outline: none;
            font-size: 15px;
            padding: 11px 0;
            color: var(--text-primary);
            font-family: inherit;
        }

        .search-clear {
            border: none;
            background: var(--border-color);
            color: var(--text-secondary);
            width: 26px;
            height: 26px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 13px;
            line-height: 1;
            display: none;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .search-clear:hover {
            background: #dc3545;
            color: white;
        }

        .search-results {
            margin-top: 10px;
            font-size: 13px;
            color: var(--text-secondary);
            display: none;
        }

        .search-results strong {
            color: #667eea;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        .filter-group label {
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .filter-group select {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quantity-btn {
            width: 25px;
            height: 25px;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .quantity-btn.increase {
            background: #28a745;
            color: white;
        }

        .quantity-btn.decrease {
            background: #dc3545;
            color: white;
        }

        .quantity-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .quantity-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .quantity-display {
            min-width: 40px;
            text-align: center;
            font-weight: bold;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-filter {
            background: #6c757d;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-clear {
            background: #17a2b8;
        }

        .btn-clear:hover {
            background: #138496;
        }
        
        /* Styles pour les chips de catégories */
        .categories-container, .subcategories-container {
            margin-bottom: 20px;
        }
        
        .category-chips, .subcategory-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .category-chip, .subcategory-chip {
            background: linear-gradient(135deg, var(--bg-subtle) 0%, var(--border-muted) 100%);
            border: 2px solid #dee2e6;
            color: #495057;
            padding: 10px 16px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .category-chip:hover, .subcategory-chip:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .category-chip.active, .subcategory-chip.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .category-chip.active:hover, .subcategory-chip.active:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            transform: translateY(-1px);
        }
        
        .subcategories-container {
            padding-top: 15px;
            border-top: 1px solid var(--border-muted);
            animation: slideDown 0.3s ease;
        }

        .equivalent-search {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border-muted);
        }

        .equivalent-search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .equivalent-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .equivalent-field label {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }

        .equivalent-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .equivalent-field input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .equivalent-actions {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
        }

        .equivalent-results {
            margin-bottom: 22px;
        }

        .equivalent-empty {
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .equivalent-list {
            display: grid;
            gap: 12px;
        }

        .equivalent-card {
            display: grid;
            grid-template-columns: 68px minmax(0, 1fr) auto;
            gap: 12px 16px;
            align-items: center;
            padding: 14px 16px;
            border-radius: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .equivalent-thumb {
            width: 68px;
            height: 68px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border-light);
            background: var(--bg-muted);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .equivalent-thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .equivalent-main strong {
            display: block;
            margin-bottom: 4px;
            font-size: 16px;
        }

        .equivalent-meta-line {
            color: var(--text-secondary);
            font-size: 13px;
            margin-top: 2px;
        }

        .equivalent-params {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .equivalent-param-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(95, 121, 184, 0.16);
            border: 1px solid rgba(95, 121, 184, 0.35);
            color: var(--text-primary);
            font-size: 12px;
            line-height: 1.2;
        }

        .equivalent-param-pill strong {
            margin: 0;
            font-size: 12px;
        }

        .equivalent-side {
            text-align: right;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .equivalent-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .component-param-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .component-param-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(95, 121, 184, 0.16);
            border: 1px solid rgba(95, 121, 184, 0.35);
            color: var(--text-primary);
            font-size: 12px;
            line-height: 1.2;
        }

        .component-comment-tooltip {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .component-comment-trigger {
            min-width: 32px;
            justify-content: center;
            padding: 6px 8px;
        }

        .component-comment-slot {
            display: inline-flex;
            width: 32px;
            min-width: 32px;
            justify-content: center;
            align-items: center;
            vertical-align: middle;
        }

        .component-comment-slot.is-empty {
            visibility: hidden;
            pointer-events: none;
        }

        .component-comment-bubble {
            position: absolute;
            left: 50%;
            bottom: calc(100% + 10px);
            transform: translateX(-50%);
            width: min(320px, 70vw);
            padding: 10px 12px;
            border-radius: 10px;
            background: #0b1220;
            border: 1px solid #243858;
            color: #e8eefb;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
            white-space: normal;
            line-height: 1.45;
            text-align: left;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.16s ease, visibility 0.16s ease, transform 0.16s ease;
            z-index: 30;
        }

        .component-comment-bubble::after {
            content: '';
            position: absolute;
            left: 50%;
            top: 100%;
            transform: translateX(-50%);
            border-width: 7px;
            border-style: solid;
            border-color: #243858 transparent transparent transparent;
        }

        .component-comment-tooltip:hover .component-comment-bubble,
        .component-comment-tooltip:focus-within .component-comment-bubble {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(-2px);
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .components-table {
                font-size: 12px;
            }
            
            .components-table th,
            .components-table td {
                padding: 8px 5px;
            }

            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                min-width: auto;
            }

            .equivalent-search-grid {
                grid-template-columns: 1fr;
            }

            .equivalent-card {
                grid-template-columns: 56px minmax(0, 1fr);
            }

            .equivalent-side {
                grid-column: 1 / -1;
                text-align: left;
            }

            .equivalent-buttons {
                justify-content: flex-start;
            }
        }

        /* --- Vue mobile : liste des composants en cartes ---------------------- */
        /* Sur téléphone, chaque ligne devient une carte : image, désignation,
           emplacement et quantité. Les colonnes secondaires sont masquées. */
        @media (max-width: 700px) {
            .components-table thead {
                display: none;
            }

            .components-table,
            .components-table tbody {
                display: block;
                width: 100%;
                margin-top: 0;
                background: transparent;
                box-shadow: none;
                overflow: visible;
            }

            .components-table tr {
                display: grid;
                grid-template-columns: 64px minmax(0, 1fr) auto;
                grid-template-areas:
                    "img name qty"
                    "img loc  qty"
                    "act act  act";
                gap: 4px 14px;
                align-items: center;
                background: #0d182c;
                border: 1px solid #1f2b46;
                border-radius: 14px;
                padding: 14px;
                margin-bottom: 12px;
            }

            .components-table tr:hover {
                background: #0d182c;
            }

            .components-table td {
                display: block;
                padding: 0 !important;
                border-bottom: 0 !important;
            }

            .components-table td:nth-child(1) { grid-area: img; }
            .components-table td:nth-child(2) { grid-area: name; }
            .components-table td:nth-child(2) strong { font-size: 15.5px; color: #e8eefb; }
            .components-table td:nth-child(7) { grid-area: qty; justify-self: end; }
            .components-table td:nth-child(10) { grid-area: loc; font-size: 13px; color: #93a4c0; }
            .components-table td:nth-child(10)::before { content: "📍 "; }

            .components-table td:nth-child(13) {
                grid-area: act;
                margin-top: 10px;
                padding-top: 10px !important;
                border-top: 1px solid #1b2841 !important;
                white-space: normal !important;
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }

            .components-table td:nth-child(13) .btn {
                margin-right: 0 !important;
            }

            /* Colonnes secondaires masquées sur téléphone */
            .components-table td:nth-child(4),
            .components-table td:nth-child(5),
            .components-table td:nth-child(6),
            .components-table td:nth-child(11),
            .components-table td:nth-child(12) {
                display: none;
            }

            .stats {
                gap: 12px;
                margin-bottom: 18px;
            }

            /* Simplification : on ne garde que la recherche et la liste.
               Le reste (en-tête, stats, filtres, bouton Créer) passe dans le
               tiroir ouvert par la bulle en haut à gauche. */
            body.cz-site-modern .cz-sidebar,
            body.cz-site-modern .app-header,
            body.cz-site-modern .stats,
            body.cz-site-modern .filter-section,
            body.cz-site-modern .create-component-cta,
            body.cz-site-modern footer {
                display: none !important;
            }

            body.cz-site-modern .content {
                padding: 66px 14px 14px !important;
            }
        }

        /* --- Menu mobile : bulle en haut à gauche + tiroir -------------------- */
        .cz-mob-btn,
        .cz-mob-overlay,
        .cz-mob-drawer {
            display: none;
        }

        @media (max-width: 700px) {
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

            /* Les filtres déplacés dans le tiroir doivent rester visibles */
            body.cz-site-modern .cz-mob-drawer .filter-section {
                display: block !important;
                margin-top: 16px;
                margin-bottom: 0;
            }

            .cz-mob-foot {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-top: 18px;
                padding-top: 16px;
                border-top: 1px solid #16233c;
            }

            .cz-mob-cta,
            .cz-mob-logout {
                display: block;
                text-align: center;
                padding: 12px 14px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
            }

            .cz-mob-cta {
                background: #6e88c4;
                color: #eef4ff;
                border: 1px solid #8aa2d3;
            }

            .cz-mob-logout {
                background: #101a2d;
                color: #c3cee2;
                border: 1px solid #243858;
            }
        }

        /* Styles pour la modal de sélection d'images */
        .component-image {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .component-image:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .no-image-placeholder {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .no-image-placeholder:hover {
            background: var(--border-color) !important;
            transform: scale(1.05);
        }

        .image-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .image-modal-content {
            background-color: var(--bg-card);
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            position: relative;
        }

        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 15px;
        }

        .close-modal:hover {
            color: #000;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .image-option {
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: var(--bg-card);
        }

        .image-option:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .image-option img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 5px;
        }

        .image-option-name {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-secondary);
            word-break: break-word;
        }

        .remove-image-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .remove-image-btn:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .upload-image-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            margin-right: 10px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .upload-image-btn:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .upload-image-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }
        
        .upload-section {
            margin-bottom: 20px;
            padding: 15px;
            background: var(--bg-subtle);
            border-radius: 8px;
            border: 1px solid var(--border-muted);
        }
        
        .upload-progress {
            display: none;
            margin: 15px 0;
            padding: 10px;
            background: var(--bg-subtle);
            border-radius: 8px;
            border: 1px solid var(--border-muted);
        }
        
        .progress-bar {
            width: 100%;
            height: 20px;
            background: var(--border-muted);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s ease;
        }
        
        /* Styles pour la modal de suppression */
        .delete-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translate(-50%, -60%) scale(0.8);
            }
            to { 
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }
        
        .delete-modal-content {
            background: linear-gradient(135deg, #ffffff 0%, var(--bg-subtle) 100%);
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            overflow: hidden;
            min-width: 400px;
            max-width: 500px;
            animation: slideIn 0.4s ease;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .delete-modal-header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
            position: relative;
        }
        
        .delete-modal-header h3 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .delete-modal-body {
            padding: 30px;
            text-align: center;
        }
        
        .delete-modal-body p {
            margin: 15px 0;
            font-size: 1.1em;
            color: var(--text-primary);
        }
        
        .component-name {
            font-weight: bold;
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
            padding: 10px 15px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
            font-size: 1.2em !important;
        }
        
        .warning-text {
            color: #ff6b6b;
            font-weight: 600;
            font-size: 1em !important;
            margin-top: 20px !important;
        }
        
        .delete-modal-footer {
            padding: 20px 30px 30px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        .btn-cancel, .btn-delete {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-cancel {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-cancel:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #ff5252 0%, #e53e3e 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.4);
        }
        
        .btn-cancel:active, .btn-delete:active {
            transform: translateY(0);
        }
        
        @media (max-width: 480px) {
            .delete-modal-content {
                min-width: 90%;
                margin: 0 5%;
            }
            
            .delete-modal-footer {
                flex-direction: column;
            }
            
            .btn-cancel, .btn-delete {
                width: 100%;
            }
        }
            height: 20px;
            background: var(--border-muted);
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">📦</div>
                    <div>
                        <h1>Composants Électroniques</h1>
                        <p>Inventaire et gestion de votre stock de composants</p>
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
                        case 'component_updated':
                            echo "✅ Composant modifié avec succès !";
                            break;
                        case 'component_deleted':
                            $name = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : 'le composant';
                            echo "✅ " . $name . " a été supprimé avec succès !";
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
                            echo "❌ Erreur lors de l'ajout du composant.";
                            break;
                        case 'component_not_found':
                            echo "❌ Composant introuvable.";
                            break;
                        case 'delete_failed':
                            echo "❌ Erreur lors de la suppression.";
                            break;
                        case 'database_error':
                            echo "❌ Erreur de base de données.";
                            break;
                        case 'quantity_not_zero':
                            $name = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : 'le composant';
                            $quantity = isset($_GET['quantity']) ? (int)$_GET['quantity'] : 0;
                            echo "❌ Impossible de supprimer " . $name . " : la quantité doit être à 0 (actuellement " . $quantity . " unité(s)).";
                            break;
                        default:
                            echo "❌ Une erreur s'est produite.";
                            break;
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($components); ?></div>
                    <div class="stat-label">Composants Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo array_sum(array_column($components, 'quantity')); ?></div>
                    <div class="stat-label">Quantité Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count(array_unique(array_column($components, 'category'))); ?></div>
                    <div class="stat-label">Catégories Utilisées</div>
                </div>
            </div>

            <!-- Barre de recherche -->
            <div class="search-section">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="componentSearch" autocomplete="off" spellcheck="false"
                           placeholder="Rechercher un composant : nom, boîtier, fabricant, fournisseur, référence, emplacement, commentaire…">
                    <button type="button" class="search-clear" id="componentSearchClear" title="Effacer la recherche (Échap)">✕</button>
                </div>
                <div class="search-results" id="searchResults"></div>
            </div>

            <!-- Section de filtrage -->
            <div class="filter-section">
                <h3 style="margin-bottom: 15px; color: var(--text-primary);">🔍 Filtrer par catégorie</h3>
                
                <!-- Catégories principales -->
                <div class="categories-container">
                    <h4 style="margin-bottom: 10px; color: var(--text-secondary);">Catégories principales :</h4>
                    <div class="category-chips">
                        <div class="category-chip <?php echo !$category_filter ? 'active' : ''; ?>" onclick="selectCategory(null)">
                            📦 Toutes
                        </div>
                        <?php foreach ($categories as $cat): ?>
                            <div class="category-chip <?php echo ($category_filter == $cat['id']) ? 'active' : ''; ?>" 
                                 onclick="selectCategory(<?php echo $cat['id']; ?>)" 
                                 data-category-id="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Sous-catégories -->
                <div class="subcategories-container" id="subcategoriesContainer" <?php echo !$category_filter ? 'style="display: none;"' : ''; ?>>
                    <h4 style="margin-bottom: 10px; color: var(--text-secondary);">Sous-catégories :</h4>
                    <div class="subcategory-chips" id="subcategoryChips">
                        <div class="subcategory-chip <?php echo !$subcategory_filter ? 'active' : ''; ?>" onclick="selectSubcategory(null)">
                            📋 Toutes
                        </div>
                        <?php if ($category_filter && isset($subcategories_by_category[$category_filter])): ?>
                            <?php foreach ($subcategories_by_category[$category_filter] as $subcat): ?>
                                <div class="subcategory-chip <?php echo ($subcategory_filter == $subcat['id']) ? 'active' : ''; ?>" 
                                     onclick="selectSubcategory(<?php echo $subcat['id']; ?>)" 
                                     data-subcategory-id="<?php echo $subcat['id']; ?>">
                                    <?php echo htmlspecialchars($subcat['name']); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Formulaire caché pour la soumission -->
                <form method="GET" action="components.php" id="filterForm" style="display: none;">
                    <input type="hidden" name="category" id="categoryInput" value="<?php echo $category_filter; ?>">
                    <input type="hidden" name="subcategory" id="subcategoryInput" value="<?php echo $subcategory_filter; ?>">
                </form>

                <?php if (!empty($equivalentDefinitions)): ?>
                    <div class="equivalent-search">
                        <h4 style="margin-bottom: 6px; color: var(--text-primary);">Recherche d'équivalents Diodes</h4>
                        <p style="color: var(--text-secondary); font-size: 13px;">
                            Même sous-catégorie, paramètres "=" identiques, et bornes min/max respectées.
                        </p>
                        <form method="GET" action="components.php">
                            <input type="hidden" name="category" value="<?php echo (int) $category_filter; ?>">
                            <input type="hidden" name="subcategory" value="<?php echo (int) $subcategory_filter; ?>">
                            <div class="equivalent-search-grid">
                                <?php foreach ($equivalentDefinitions as $definition): ?>
                                    <?php
                                    $modeLabel = '=';
                                    if ($definition['compare_mode'] === '>=') {
                                        $modeLabel = 'min';
                                    } elseif ($definition['compare_mode'] === '<=') {
                                        $modeLabel = 'max';
                                    }
                                    $value = $equivalentValues[$definition['param_key']] ?? '';
                                    ?>
                                    <div class="equivalent-field">
                                        <label for="eq_<?php echo htmlspecialchars($definition['param_key']); ?>">
                                            <span><?php echo htmlspecialchars($definition['label']); ?></span>
                                            <span class="equivalent-meta"><?php echo htmlspecialchars($modeLabel . ($definition['unit'] ? ' · ' . $definition['unit'] : '')); ?></span>
                                        </label>
                                        <input
                                            type="<?php echo $definition['value_type'] === 'number' ? 'number' : 'text'; ?>"
                                            <?php if ($definition['value_type'] === 'number'): ?>step="any" inputmode="decimal"<?php endif; ?>
                                            id="eq_<?php echo htmlspecialchars($definition['param_key']); ?>"
                                            name="eq_<?php echo htmlspecialchars($definition['param_key']); ?>"
                                            value="<?php echo htmlspecialchars($value); ?>"
                                        >
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="equivalent-actions">
                                <button type="submit" class="btn btn-primary">🔎 Chercher les équivalents</button>
                                <a href="components.php?category=<?php echo (int) $category_filter; ?>&subcategory=<?php echo (int) $subcategory_filter; ?>" class="btn btn-secondary">Effacer ces critères</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($equivalentDefinitions) && $hasEquivalentSearch): ?>
                <div class="equivalent-results">
                    <h3 style="margin-bottom: 14px; color: var(--text-primary);">Composants équivalents</h3>
                    <?php if (empty($equivalentMatches)): ?>
                        <div class="equivalent-empty">Aucun composant ne correspond à tous les critères saisis.</div>
                    <?php else: ?>
                        <div class="equivalent-list">
                            <?php foreach ($equivalentMatches as $match): ?>
                                <?php
                                $isFlatLocation = in_array($match['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                $locationLabel = $isFlatLocation
                                    ? trim(($match['casier'] ?? '') . '-' . ($match['compartiment'] ?? ''), '-')
                                    : trim(($match['casier'] ?? '') . ($match['tiroir'] ?? '') . '-' . ($match['compartiment'] ?? ''), '-');
                                $paramRows = $equivalentParamRowsMap[(int) $match['id']] ?? [];
                                $datasheetUrl = trim((string) (($match['datasheet'] ?? '') ?: ($match['datasheet_file'] ?? '')));
                                ?>
                                <div class="equivalent-card">
                                    <div class="equivalent-thumb">
                                        <?php if (!empty($match['image_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($match['image_path']); ?>" alt="<?php echo htmlspecialchars($match['name']); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="equivalent-main">
                                        <strong><?php echo htmlspecialchars($match['name']); ?></strong>
                                        <div class="equivalent-meta-line">
                                            <?php echo htmlspecialchars($match['manufacturer'] ?: 'Fabricant non renseigné'); ?>
                                            <?php if (!empty($match['package'])): ?> · <?php echo htmlspecialchars($match['package']); ?><?php endif; ?>
                                            <?php if (!empty($match['supplier_name'])): ?> · <?php echo htmlspecialchars($match['supplier_name']); ?><?php endif; ?>
                                        </div>
                                        <?php if (!empty($match['comment'])): ?>
                                            <div class="equivalent-meta-line"><?php echo htmlspecialchars($match['comment']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($paramRows)): ?>
                                            <div class="equivalent-params">
                                                <?php foreach (array_slice($paramRows, 0, 6) as $paramRow): ?>
                                                    <?php $paramValue = compozitFormatParamValue($paramRow); ?>
                                                    <?php if ($paramValue === '') { continue; } ?>
                                                    <span class="equivalent-param-pill">
                                                        <strong><?php echo htmlspecialchars($paramRow['label']); ?></strong>
                                                        <span><?php echo htmlspecialchars($paramValue); ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="equivalent-side">
                                        <div><strong>Qté :</strong> <?php echo (int) $match['quantity']; ?></div>
                                        <?php if ($locationLabel !== ''): ?><div><strong>Empl. :</strong> <?php echo htmlspecialchars($locationLabel); ?></div><?php endif; ?>
                                        <div class="equivalent-buttons">
                                            <?php if ($datasheetUrl !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($datasheetUrl); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-info">📄 Datasheet</a>
                                            <?php endif; ?>
                                            <a href="component_sheet.php?id=<?php echo (int) $match['id']; ?>" class="btn btn-sm btn-primary">Ouvrir</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Bouton d'ajout -->
            <div class="create-component-cta" style="text-align: center; margin-bottom: 30px;">
                <a href="create_component.php" class="btn btn-primary" style="font-size: 16px; padding: 15px 30px;">➕ Créer un nouveau composant</a>
            </div>

            <!-- Liste des composants -->
            <?php if (empty($components)): ?>
                <div class="no-components">
                    <h3>Aucun composant trouvé</h3>
                    <p>Commencez par ajouter votre premier composant électronique !</p>
                </div>
            <?php else: ?>
                <table class="components-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Nom</th>
                            <th style="display: none;">Fabricant</th>
                            <th>Package</th>
                            <th>Pins</th>
                            <th>SMD</th>
                            <th>Quantité</th>
                            <th style="display: none;">Fournisseur</th>
                            <th style="display: none;">Réf. Fournisseur</th>
                            <th>Emplacement</th>
                            <th>Datasheet</th>
                            <th>Paramètres</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($components as $component): ?>
                            <?php
                                $componentParamRows = $componentParamRowsMap[(int) $component['id']] ?? [];
                                $componentParamSummary = compozitBuildParamSummary($componentParamRows, 6);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($component['image_path']): ?>
                                        <img src="<?php echo htmlspecialchars($component['image_path']); ?>" alt="Image du composant" class="component-image" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;" onclick="openImageModal(<?php echo $component['id']; ?>)" title="Cliquer pour changer l'image">
                                    <?php else: ?>
                                        <div class="no-image-placeholder" style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--text-secondary);" onclick="openImageModal(<?php echo $component['id']; ?>)" title="Cliquer pour ajouter une image">📷</div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php
                                    $displayName = htmlspecialchars($component['name']);
                                    if (($component['smd'] ?? '') === 'Yes' && !empty($component['marking'])) {
                                        $marking = trim((string) $component['marking']);
                                        if ($marking !== '' && stripos($component['name'], $marking) === false) {
                                            $displayName .= ' - <span class="badge-smd" style="font-size:0.85em;">' . htmlspecialchars($marking) . '</span>';
                                        }
                                    }
                                    echo $displayName;
                                ?></strong></td>
                                <td style="display: none;"><?php echo htmlspecialchars($component['manufacturer'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($component['package'] ?? '-'); ?></td>
                                <td><?php echo $component['pins'] ?? '-'; ?></td>
                                <td><?php echo $component['smd']; ?></td>
                                <td>
                                    <div class="quantity-controls">
                                        <button class="quantity-btn decrease" onclick="updateQuantity(<?php echo $component['id']; ?>, -1)" <?php echo ($component['quantity'] <= 0) ? 'disabled' : ''; ?>>-</button>
                                        <span class="quantity-display" id="quantity-<?php echo $component['id']; ?>"><?php echo $component['quantity']; ?></span>
                                        <button class="quantity-btn increase" onclick="updateQuantity(<?php echo $component['id']; ?>, 1)">+</button>
                                    </div>
                                </td>
                                <td style="display: none;"><?php echo htmlspecialchars($component['supplier_name'] ?? 'Non défini'); ?></td>
                                <td style="display: none;"><?php echo htmlspecialchars($component['supplier_reference'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($component['casier']): ?>
                                        <?php
                                            $isFlatLocation = in_array($component['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                            $componentLocation = $isFlatLocation
                                                ? $component['casier'] . '-' . $component['compartiment']
                                                : $component['casier'] . '-' . $component['tiroir'] . '-' . $component['compartiment'];
                                            echo htmlspecialchars($componentLocation);
                                        ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($component['datasheet']): ?>
                                        <a href="<?php echo htmlspecialchars($component['datasheet']); ?>" target="_blank" class="btn btn-info" style="font-size: 11px; padding: 3px 8px;">📄 Voir</a>
                                    <?php elseif (!empty($component['datasheet_file'])): ?>
                                        <a href="<?php echo htmlspecialchars($component['datasheet_file']); ?>" target="_blank" class="btn btn-info" style="font-size: 11px; padding: 3px 8px;">📄 PDF</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($componentParamSummary)): ?>
                                        <div class="component-param-summary">
                                            <?php foreach ($componentParamSummary as $paramItem): ?>
                                                <span class="component-param-pill"><?php echo htmlspecialchars($paramItem); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php if (!empty($component['comment'])): ?>
                                        <span class="component-comment-tooltip component-comment-slot" style="margin-right: 5px;">
                                            <button type="button" class="btn btn-ghost component-comment-trigger" aria-label="Voir le commentaire" title="Voir le commentaire">💬</button>
                                            <span class="component-comment-bubble"><?php echo nl2br(htmlspecialchars($component['comment'])); ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="component-comment-slot is-empty" style="margin-right: 5px;">
                                            <span class="btn btn-ghost component-comment-trigger" aria-hidden="true">💬</span>
                                        </span>
                                    <?php endif; ?>
                                    <a href="component_sheet.php?id=<?php echo $component['id']; ?>" class="btn btn-info" style="font-size: 12px; padding: 6px 10px; margin-right: 5px; display: inline-block;" title="Fiche produit">📋 Fiche</a>
                                    <button onclick="openProjectModal(<?php echo $component['id']; ?>, '<?php echo htmlspecialchars($component['name'], ENT_QUOTES); ?>')" class="btn btn-success" style="font-size: 12px; padding: 6px 10px; margin-right: 5px; display: inline-block;" title="Ajouter au projet">🚀 Projet</button>
                                    <a href="edit_component.php?id=<?php echo $component['id']; ?>" class="btn btn-secondary" style="font-size: 12px; padding: 6px 10px; margin-right: 5px; display: inline-block;" title="Modifier le composant">✏️</a>
                                    <?php if ($component['quantity'] > 0): ?>
                                        <span class="btn btn-danger" style="font-size: 12px; padding: 6px 10px; display: inline-block; opacity: 0.5; cursor: not-allowed;" title="Impossible de supprimer : quantité non nulle (<?php echo $component['quantity']; ?> unité(s))">🗑️</span>
                                    <?php else: ?>
                                        <button class="btn btn-danger" style="font-size: 12px; padding: 6px 10px; display: inline-block;" onclick="openDeleteModal(<?php echo $component['id']; ?>, '<?php echo htmlspecialchars($component['name'], ENT_QUOTES); ?>')" title="Supprimer le composant">🗑️</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="no-components" id="noSearchResults" style="display: none;">
                    <h3>Aucun composant ne correspond à la recherche</h3>
                    <p id="noSearchResultsTerm"></p>
                </div>
            <?php endif; ?>
        </div>
    
    <!-- Modal de sélection d'images -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <span class="close-modal" onclick="closeImageModal()">&times;</span>
            <h3>Choisir une image</h3>
            <p>Sélectionnez une image du dossier /img ou importez une nouvelle image :</p>
            
            <div class="upload-section">
                <input type="file" id="imageUpload" accept="image/*" style="display: none;" onchange="uploadImage()">
                <button class="upload-image-btn" onclick="document.getElementById('imageUpload').click()">📁 Importer une image du PC</button>
                <button class="remove-image-btn" onclick="removeImage()">🗑️ Supprimer l'image actuelle</button>
            </div>
            
            <div class="upload-progress" id="uploadProgress">
                 <p>Upload en cours...</p>
                 <div class="progress-bar">
                     <div class="progress-fill" id="progressFill"></div>
                 </div>
             </div>
             
             <div id="imageGrid" class="image-grid">
                 <!-- Les images seront chargées ici via JavaScript -->
             </div>
        </div>
    </div>
    
    <!-- Modal d'ajout au projet -->
    <div id="projectModal" class="image-modal">
        <div class="image-modal-content">
            <span class="close-modal" onclick="closeProjectModal()">&times;</span>
            <h3>Ajouter au projet</h3>
            <p id="componentName">Composant: </p>
            
            <div style="margin-bottom: 20px;">
                 <label for="projectSelect" style="display: block; margin-bottom: 5px; font-weight: bold;">Sélectionner un projet:</label>
                 <select id="projectSelect" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;" onchange="toggleNewProjectForm()">
                     <option value="">Chargement des projets...</option>
                 </select>
             </div>
             
             <!-- Formulaire de création de nouveau projet -->
             <div id="newProjectForm" style="display: none; margin-bottom: 20px; padding: 15px; border: 1px solid var(--border-color); border-radius: 4px; background-color: var(--bg-subtle);">
                 <h4 style="margin-top: 0; margin-bottom: 15px;">Créer un nouveau projet</h4>
                 <div style="margin-bottom: 10px;">
                     <label for="newProjectName" style="display: block; margin-bottom: 5px; font-weight: bold;">Nom du projet:</label>
                     <input type="text" id="newProjectName" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;" placeholder="Nom du nouveau projet">
                 </div>
                 <div style="margin-bottom: 10px;">
                     <label for="newProjectDescription" style="display: block; margin-bottom: 5px; font-weight: bold;">Description:</label>
                     <textarea id="newProjectDescription" rows="2" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; resize: vertical;" placeholder="Description du projet (optionnel)"></textarea>
                 </div>
             </div>
            
            <div style="margin-bottom: 20px;">
                <label for="componentQuantity" style="display: block; margin-bottom: 5px; font-weight: bold;">Quantité nécessaire:</label>
                <input type="number" id="componentQuantity" min="1" value="1" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label for="componentNotes" style="display: block; margin-bottom: 5px; font-weight: bold;">Notes (optionnel):</label>
                <textarea id="componentNotes" rows="3" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; resize: vertical;" placeholder="Notes sur l'utilisation de ce composant dans le projet..."></textarea>
            </div>
            
            <div style="text-align: right;">
                <button onclick="closeProjectModal()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; margin-right: 10px; cursor: pointer;">Annuler</button>
                <button onclick="addToProject()" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">Ajouter au projet</button>
            </div>
        </div>
    </div>
    
    <!-- Modal de confirmation de suppression -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <h3>⚠️ Confirmation de suppression</h3>
            </div>
            <div class="delete-modal-body">
                <p>Êtes-vous sûr de vouloir supprimer ce composant ?</p>
                <p id="componentToDelete" class="component-name"></p>
                <p class="warning-text">⚠️ Cette action est irréversible !</p>
            </div>
            <div class="delete-modal-footer">
                <button onclick="closeDeleteModal()" class="btn-cancel">❌ Annuler</button>
                <button onclick="confirmDelete()" class="btn-delete">🗑️ Supprimer</button>
            </div>
        </div>
    </div>
    
    <script>
        // Fonction pour mettre à jour la quantité
        function updateQuantity(componentId, change) {
            const quantityElement = document.getElementById('quantity-' + componentId);
            const currentQuantity = parseInt(quantityElement.textContent);
            const newQuantity = currentQuantity + change;
            
            if (newQuantity < 0) {
                return;
            }
            
            // Envoyer la requête AJAX
            fetch('update_quantity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'component_id=' + componentId + '&change=' + change
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    quantityElement.textContent = data.new_quantity;
                    
                    // Mettre à jour l'état du bouton diminuer
                    const decreaseBtn = quantityElement.parentElement.querySelector('.decrease');
                    if (data.new_quantity <= 0) {
                        decreaseBtn.disabled = true;
                    } else {
                        decreaseBtn.disabled = false;
                    }
                } else {
                    alert('Erreur: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la mise à jour de la quantité');
            });
        }
        
        // Données des sous-catégories pour le filtrage dynamique
        const subcategoriesData = <?php echo json_encode($subcategories_by_category); ?>;
        
        // Fonction pour mettre à jour les sous-catégories
        function updateSubcategories() {
            const categorySelect = document.getElementById('category');
            const subcategorySelect = document.getElementById('subcategory');
            const selectedCategory = categorySelect.value;
            
            // Vider les options actuelles
            subcategorySelect.innerHTML = '<option value="">Toutes les sous-catégories</option>';
            
            // Ajouter les nouvelles options si une catégorie est sélectionnée
            if (selectedCategory && subcategoriesData[selectedCategory]) {
                subcategoriesData[selectedCategory].forEach(function(subcat) {
                    const option = document.createElement('option');
                    option.value = subcat.id;
                    option.textContent = subcat.name;
                    subcategorySelect.appendChild(option);
                });
            }
        }
        
        // Variable globale pour stocker l'ID du composant en cours de modification
        let currentComponentId = null;
        
        // Fonctions pour la modal d'images
        function openImageModal(componentId) {
            currentComponentId = componentId;
            document.getElementById('imageModal').style.display = 'block';
            loadImages();
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
            currentComponentId = null;
        }
        
        function loadImages() {
            fetch('get_images.php')
            .then(response => response.json())
            .then(data => {
                if (data.images) {
                    const imageGrid = document.getElementById('imageGrid');
                    imageGrid.innerHTML = '';
                    
                    data.images.forEach(image => {
                        const imageOption = document.createElement('div');
                        imageOption.className = 'image-option';
                        imageOption.onclick = () => selectImage(image.path);
                        
                        imageOption.innerHTML = `
                            <img src="${image.path}" alt="${image.name}">
                            <div class="image-option-name">${image.name}</div>
                        `;
                        
                        imageGrid.appendChild(imageOption);
                    });
                }
            })
            .catch(error => {
                console.error('Erreur lors du chargement des images:', error);
                alert('Erreur lors du chargement des images');
            });
        }
        
        function selectImage(imagePath) {
            if (!currentComponentId) {
                alert('Erreur: Aucun composant sélectionné');
                return;
            }
            
            fetch('update_image.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'component_id=' + currentComponentId + '&image_path=' + encodeURIComponent(imagePath)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Recharger la page pour afficher la nouvelle image
                    location.reload();
                } else {
                    alert('Erreur lors de la mise à jour : ' + (data.error || 'Erreur inconnue'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }
        
        function removeImage() {
            if (!currentComponentId) {
                alert('Erreur: Aucun composant sélectionné');
                return;
            }
            
            if (confirm('Êtes-vous sûr de vouloir supprimer l\'image de ce composant ?')) {
                fetch('update_image.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'component_id=' + currentComponentId + '&image_path='
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Recharger la page pour afficher le changement
                        location.reload();
                    } else {
                        alert('Erreur lors de la suppression : ' + (data.error || 'Erreur inconnue'));
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur de communication avec le serveur');
                });
            }
        }
        
        // Fonction pour uploader une image
         function uploadImage() {
             const fileInput = document.getElementById('imageUpload');
             const file = fileInput.files[0];
             
             if (!file) {
                 return;
             }
             
             // Vérifier le type de fichier
             const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
             if (!allowedTypes.includes(file.type)) {
                 alert('Type de fichier non autorisé. Formats acceptés: JPG, PNG, GIF, SVG, WebP');
                 return;
             }
             
             // Vérifier la taille (max 5MB)
             if (file.size > 5 * 1024 * 1024) {
                 alert('Le fichier est trop volumineux (max 5MB)');
                 return;
             }
             
             const formData = new FormData();
             formData.append('image', file);
             
             const progressDiv = document.getElementById('uploadProgress');
             const progressFill = document.getElementById('progressFill');
             
             // Afficher la barre de progression
             progressDiv.style.display = 'block';
             progressFill.style.width = '0%';
             
             // Créer une requête XMLHttpRequest pour suivre le progrès
             const xhr = new XMLHttpRequest();
             
             xhr.upload.addEventListener('progress', function(e) {
                 if (e.lengthComputable) {
                     const percentComplete = (e.loaded / e.total) * 100;
                     progressFill.style.width = percentComplete + '%';
                 }
             });
             
             xhr.onload = function() {
                 progressDiv.style.display = 'none';
                 
                 if (xhr.status === 200) {
                     try {
                         const response = JSON.parse(xhr.responseText);
                         if (response.success) {
                             // Utiliser l'image uploadée pour le composant
                             selectImage(response.image_path);
                         } else {
                             alert('Erreur lors de l\'upload : ' + (response.error || 'Erreur inconnue'));
                         }
                     } catch (e) {
                         alert('Erreur lors du traitement de la réponse');
                     }
                 } else {
                     alert('Erreur lors de l\'upload du fichier');
                 }
                 
                 // Réinitialiser l'input file
                 fileInput.value = '';
             };
             
             xhr.onerror = function() {
                 progressDiv.style.display = 'none';
                 alert('Erreur de communication avec le serveur');
                 fileInput.value = '';
             };
             
             xhr.open('POST', 'upload_image.php');
             xhr.send(formData);
         }
         
         // Variables globales pour la modal de projet
         let currentProjectComponentId = null;
         
         // Fonctions pour la modal de projet
         function openProjectModal(componentId, componentName) {
             currentProjectComponentId = componentId;
             document.getElementById('componentName').textContent = 'Composant: ' + componentName;
             document.getElementById('projectModal').style.display = 'block';
             loadProjects();
         }
         
         function closeProjectModal() {
             document.getElementById('projectModal').style.display = 'none';
             currentProjectComponentId = null;
             document.getElementById('componentQuantity').value = '1';
             document.getElementById('componentNotes').value = '';
             document.getElementById('projectSelect').value = '';
             document.getElementById('newProjectForm').style.display = 'none';
             document.getElementById('newProjectName').value = '';
             document.getElementById('newProjectDescription').value = '';
         }
         
         function loadProjects() {
             fetch('get_projects.php')
             .then(response => response.json())
             .then(data => {
                 const projectSelect = document.getElementById('projectSelect');
                 projectSelect.innerHTML = '<option value="">Sélectionner un projet...</option>';
                 
                 // Ajouter l'option pour créer un nouveau projet
                 const newProjectOption = document.createElement('option');
                 newProjectOption.value = 'new';
                 newProjectOption.textContent = '+ Créer un nouveau projet';
                 newProjectOption.style.fontWeight = 'bold';
                 newProjectOption.style.color = '#28a745';
                 projectSelect.appendChild(newProjectOption);
                 
                 if (data.projects && data.projects.length > 0) {
                     data.projects.forEach(project => {
                         const option = document.createElement('option');
                         option.value = project.id;
                         option.textContent = project.name;
                         projectSelect.appendChild(option);
                     });
                 }
             })
             .catch(error => {
                 console.error('Erreur lors du chargement des projets:', error);
                 const projectSelect = document.getElementById('projectSelect');
                 projectSelect.innerHTML = '<option value="">Erreur de chargement</option>';
                 const newProjectOption = document.createElement('option');
                 newProjectOption.value = 'new';
                 newProjectOption.textContent = '+ Créer un nouveau projet';
                 newProjectOption.style.fontWeight = 'bold';
                 newProjectOption.style.color = '#28a745';
                 projectSelect.appendChild(newProjectOption);
             });
         }
         
         function toggleNewProjectForm() {
             const projectSelect = document.getElementById('projectSelect');
             const newProjectForm = document.getElementById('newProjectForm');
             
             if (projectSelect.value === 'new') {
                 newProjectForm.style.display = 'block';
             } else {
                 newProjectForm.style.display = 'none';
                 // Réinitialiser les champs du formulaire
                 document.getElementById('newProjectName').value = '';
                 document.getElementById('newProjectDescription').value = '';
             }
         }
         
         function addToProject() {
             const projectId = document.getElementById('projectSelect').value;
             const quantity = document.getElementById('componentQuantity').value;
             const notes = document.getElementById('componentNotes').value;
             
             if (!projectId) {
                 alert('Veuillez sélectionner un projet');
                 return;
             }
             
             if (!quantity || quantity < 1) {
                 alert('Veuillez entrer une quantité valide');
                 return;
             }
             
             // Si l'utilisateur veut créer un nouveau projet
             if (projectId === 'new') {
                 const newProjectName = document.getElementById('newProjectName').value.trim();
                 const newProjectDescription = document.getElementById('newProjectDescription').value.trim();
                 
                 if (!newProjectName) {
                     alert('Veuillez entrer un nom pour le nouveau projet');
                     return;
                 }
                 
                 // Créer le nouveau projet d'abord
                 fetch('create_project_ajax.php', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/x-www-form-urlencoded',
                     },
                     body: 'name=' + encodeURIComponent(newProjectName) + '&description=' + encodeURIComponent(newProjectDescription)
                 })
                 .then(response => response.json())
                 .then(data => {
                     if (data.success) {
                         // Ajouter le composant au nouveau projet
                         addComponentToExistingProject(data.project_id, quantity, notes);
                     } else {
                         alert('Erreur lors de la création du projet: ' + (data.message || 'Erreur inconnue'));
                     }
                 })
                 .catch(error => {
                     console.error('Erreur:', error);
                     alert('Erreur lors de la création du projet');
                 });
             } else {
                 // Ajouter à un projet existant
                 addComponentToExistingProject(projectId, quantity, notes);
             }
         }
         
         function addComponentToExistingProject(projectId, quantity, notes) {
             fetch('add_component_to_project.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                 },
                 body: 'project_id=' + projectId + '&component_id=' + currentProjectComponentId + '&quantity=' + quantity + '&notes=' + encodeURIComponent(notes)
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {
                     alert('Composant ajouté au projet avec succès!');
                     closeProjectModal();
                 } else {
                     alert('Erreur: ' + (data.message || 'Erreur inconnue'));
                 }
             })
             .catch(error => {
                 console.error('Erreur:', error);
                 alert('Erreur lors de l\'ajout au projet');
             });
         }
         
         // Variables globales pour la modal de suppression
         let deleteComponentId = null;
         
         // Fonctions pour la modal de suppression
         function openDeleteModal(componentId, componentName) {
             deleteComponentId = componentId;
             document.getElementById('componentToDelete').textContent = componentName;
             document.getElementById('deleteModal').style.display = 'block';
         }
         
         function closeDeleteModal() {
             document.getElementById('deleteModal').style.display = 'none';
             deleteComponentId = null;
         }
         
         function confirmDelete() {
             if (deleteComponentId) {
                 window.location.href = 'delete_component.php?id=' + deleteComponentId;
             }
         }
         
         // Fermer la modal en cliquant à l'extérieur
         window.onclick = function(event) {
             const imageModal = document.getElementById('imageModal');
             const projectModal = document.getElementById('projectModal');
             const deleteModal = document.getElementById('deleteModal');
             
             if (event.target === imageModal) {
                 closeImageModal();
             } else if (event.target === projectModal) {
                 closeProjectModal();
             } else if (event.target === deleteModal) {
                 closeDeleteModal();
             }
         }
         
         // Recherche instantanee dans la liste des composants
         // (le point-virgule initial protege du window.onclick = function {...} non termine ci-dessus)
         ;(function () {
             var input = document.getElementById('componentSearch');
             var table = document.querySelector('.components-table');
             if (!input || !table) { return; }
         
             var clearBtn = document.getElementById('componentSearchClear');
             var counter = document.getElementById('searchResults');
             var empty = document.getElementById('noSearchResults');
             var emptyTerm = document.getElementById('noSearchResultsTerm');
             var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
             var STORAGE_KEY = 'compozit-component-search';
         
             // Minuscules sans accents : « resistance » trouve « Résistance ».
             function normalize(text) {
                 text = (text || '').toLowerCase();
                 return text.normalize ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : text;
             }
         
             // Texte indexe de chaque ligne : toutes les colonnes sauf « Actions ».
             var haystacks = rows.map(function (row) {
                 var cells = row.cells;
                 var text = '';
                 for (var i = 0; i < cells.length - 1; i++) {
                     text += ' ' + cells[i].textContent;
                 }
                 return normalize(text);
             });
         
             function apply(term, remember) {
                 var needle = normalize(term.trim());
                 var visible = 0;
         
                 for (var i = 0; i < rows.length; i++) {
                     var match = needle === '' || haystacks[i].indexOf(needle) !== -1;
                     rows[i].style.display = match ? '' : 'none';
                     if (match) { visible++; }
                 }
         
                 clearBtn.style.display = term === '' ? 'none' : 'flex';
         
                 if (needle === '') {
                     counter.style.display = 'none';
                     empty.style.display = 'none';
                 } else {
                     counter.innerHTML = '<strong>' + visible + '</strong> composant(s) affiché(s) sur ' + rows.length;
                     counter.style.display = 'block';
                     empty.style.display = visible === 0 ? 'block' : 'none';
                     emptyTerm.textContent = 'Aucun résultat pour « ' + term + ' ».';
                 }
         
                 if (remember) {
                     try { sessionStorage.setItem(STORAGE_KEY, term); } catch (e) { /* stockage indisponible */ }
                 }
             }
         
             // Le terme survit au changement de categorie, qui recharge la page.
             var saved = '';
             try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) { /* stockage indisponible */ }
             input.value = saved;
             apply(saved, false);
         
             input.addEventListener('input', function () { apply(input.value, true); });
             input.addEventListener('keydown', function (event) {
                 if (event.key === 'Escape') {
                     input.value = '';
                     apply('', true);
                     event.preventDefault();
                 }
             });
             clearBtn.addEventListener('click', function () {
                 input.value = '';
                 apply('', true);
                 input.focus();
             });
         })();
         
         // Fonctions pour la gestion des catégories
         function selectCategory(categoryId) {
             // Sauvegarder la position de défilement actuelle
             sessionStorage.setItem('scrollPosition', window.pageYOffset);
             
             // Mettre à jour l'input caché
             document.getElementById('categoryInput').value = categoryId || '';
             document.getElementById('subcategoryInput').value = ''; // Reset subcategory
             
             // Mettre à jour les classes actives des catégories
             var categoryChips = document.querySelectorAll('.category-chip');
             categoryChips.forEach(function(chip) {
                 chip.classList.remove('active');
             });
             
             if (categoryId) {
                 var selectedChip = document.querySelector('.category-chip[data-category-id="' + categoryId + '"]');
                 if (selectedChip) {
                     selectedChip.classList.add('active');
                 }
                 // Afficher les sous-catégories
                 document.getElementById('subcategoriesContainer').style.display = 'block';
             } else {
                 // Sélection "Toutes" - activer le premier chip
                 categoryChips[0].classList.add('active');
                 // Masquer les sous-catégories
                 document.getElementById('subcategoriesContainer').style.display = 'none';
             }
             
             // Soumettre le formulaire
             document.getElementById('filterForm').submit();
         }
         
         function selectSubcategory(subcategoryId) {
             // Sauvegarder la position de défilement actuelle
             sessionStorage.setItem('scrollPosition', window.pageYOffset);
             
             // Mettre à jour l'input caché
             document.getElementById('subcategoryInput').value = subcategoryId || '';
             
             // Mettre à jour les classes actives des sous-catégories
             var subcategoryChips = document.querySelectorAll('.subcategory-chip');
             subcategoryChips.forEach(function(chip) {
                 chip.classList.remove('active');
             });
             
             if (subcategoryId) {
                 var selectedChip = document.querySelector('.subcategory-chip[data-subcategory-id="' + subcategoryId + '"]');
                 if (selectedChip) {
                     selectedChip.classList.add('active');
                 }
             } else {
                 // Sélection "Toutes" - activer le premier chip
                 subcategoryChips[0].classList.add('active');
             }
             
             // Soumettre le formulaire
             document.getElementById('filterForm').submit();
         }
         
         // Restaurer la position de défilement après le chargement de la page
         window.addEventListener('load', function() {
             var scrollPosition = sessionStorage.getItem('scrollPosition');
             if (scrollPosition) {
                 window.scrollTo(0, parseInt(scrollPosition));
                 sessionStorage.removeItem('scrollPosition');
             }
         });
    </script>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>
    </div>

    <!-- Menu mobile : bulle en haut à gauche + tiroir (téléphone uniquement) -->
    <button type="button" class="cz-mob-btn" id="czMobBtn" aria-label="Ouvrir le menu" aria-expanded="false">&#9776;</button>
    <div class="cz-mob-overlay" id="czMobOverlay"></div>
    <nav class="cz-mob-drawer" id="czMobDrawer" aria-label="Menu principal">
        <button type="button" class="cz-mob-close" id="czMobClose" aria-label="Fermer le menu">&#10005;</button>
        <div class="cz-mob-body" id="czMobBody"></div>
        <div class="cz-mob-foot">
            <a href="create_component.php" class="cz-mob-cta">&#10133; Créer un composant</a>
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

            // 1) Reproduire le menu de navigation construit par theme.js
            var sidebar = document.querySelector('.cz-sidebar');
            if (sidebar) {
                for (var i = 0; i < sidebar.children.length; i++) {
                    slot.appendChild(sidebar.children[i].cloneNode(true));
                }
            }

            // 2) Sur téléphone, déplacer les filtres de catégories dans le tiroir
            var filter = document.querySelector('.content .filter-section');
            var filterHome = filter ? filter.parentNode : null;
            var filterNext = filter ? filter.nextSibling : null;

            function placeFilter() {
                if (!filter || !filterHome) {
                    return;
                }
                if (isMobile()) {
                    if (filter.parentNode !== slot) {
                        slot.appendChild(filter);
                    }
                } else if (filter.parentNode !== filterHome) {
                    filterHome.insertBefore(filter, filterNext);
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
                placeFilter();
                if (!isMobile()) {
                    closeDrawer();
                }
            });

            placeFilter();
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
