<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

try {
    $pdo = getConnection();

    // Traitement des actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {
                case 'add_category':
                    $name = trim($_POST['category_name'] ?? '');
                    if (!empty($name)) {
                        $stmt = $pdo->prepare("INSERT INTO category_head (name) VALUES (?)");
                        $stmt->execute([$name]);
                        header('Location: categories_management.php?success=category_added');
                        exit();
                    } else {
                        $error = "Le nom de la catégorie est obligatoire.";
                    }
                    break;

                case 'add_subcategory':
                    $name = trim($_POST['subcategory_name'] ?? '');
                    $parent_id = (int)($_POST['parent_category'] ?? 0);
                    if (!empty($name) && $parent_id > 0) {
                        $stmt = $pdo->prepare("SELECT id FROM category_head WHERE id = ?");
                        $stmt->execute([$parent_id]);
                        if (!$stmt->fetchColumn()) {
                            $error = "Catégorie parent introuvable.";
                            break;
                        }
                        $stmt = $pdo->prepare("SELECT id FROM category_sub WHERE category_head_id = ? ORDER BY id");
                        $stmt->execute([$parent_id]);
                        $existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                        $existingIds = array_map('intval', $existingIds);
                        sort($existingIds, SORT_NUMERIC);

                        $base = (int)($parent_id * 100);
                        $usedSuffixes = [];
                        foreach ($existingIds as $eid) {
                            if ($eid > $base && $eid <= $base + 99) {
                                $usedSuffixes[] = $eid - $base;
                            }
                        }
                        sort($usedSuffixes, SORT_NUMERIC);

                        $nextSuffix = 1;
                        foreach ($usedSuffixes as $s) {
                            if ($s === $nextSuffix) {
                                $nextSuffix++;
                            } elseif ($s > $nextSuffix) {
                                break;
                            }
                        }

                        if ($nextSuffix <= 99) {
                            $new_id = $base + $nextSuffix;
                        } else {
                            $maxId = $base + 99;
                            foreach ($existingIds as $eid) {
                                if ($eid > $maxId) $maxId = $eid;
                            }
                            $new_id = $maxId + 1;
                        }

                        $stmt = $pdo->prepare("INSERT INTO category_sub (id, name, category_head_id) VALUES (?, ?, ?)");
                        $stmt->execute([$new_id, $name, $parent_id]);
                        header('Location: categories_management.php?success=subcategory_added');
                        exit();
                    } else {
                        $error = "Le nom de la sous-catégorie et la catégorie parent sont obligatoires.";
                    }
                    break;

                case 'delete_category':
                    $id = (int)($_POST['category_id'] ?? 0);
                    if ($id > 0) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM category_sub WHERE category_head_id = ?");
                        $stmt->execute([$id]);
                        $subcategory_count = $stmt->fetchColumn();

                        if ($subcategory_count > 0) {
                            $error = "Impossible de supprimer cette catégorie car elle contient des sous-catégories.";
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM category_head WHERE id = ?");
                            $stmt->execute([$id]);
                            header('Location: categories_management.php?success=category_deleted');
                            exit();
                        }
                    }
                    break;

                case 'rename_category':
                    $id = (int)($_POST['category_id'] ?? 0);
                    $new_name = trim($_POST['new_name'] ?? '');
                    if ($id > 0 && !empty($new_name)) {
                        $stmt = $pdo->prepare("UPDATE category_head SET name = ? WHERE id = ?");
                        $stmt->execute([$new_name, $id]);
                        header('Location: categories_management.php?success=category_renamed');
                        exit();
                    } else {
                        $error = "Le nom de la catégorie est obligatoire.";
                    }
                    break;

                case 'rename_subcategory':
                    $id = (int)($_POST['subcategory_id'] ?? 0);
                    $new_name = trim($_POST['new_name'] ?? '');
                    if ($id > 0 && !empty($new_name)) {
                        $stmt = $pdo->prepare("UPDATE category_sub SET name = ? WHERE id = ?");
                        $stmt->execute([$new_name, $id]);
                        header('Location: categories_management.php?success=subcategory_renamed');
                        exit();
                    } else {
                        $error = "Le nom de la sous-catégorie est obligatoire.";
                    }
                    break;

                case 'delete_subcategory':
                    $id = (int)($_POST['subcategory_id'] ?? 0);
                    if ($id > 0) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM data WHERE category = ?");
                        $stmt->execute([$id]);
                        $component_count = $stmt->fetchColumn();

                        if ($component_count > 0) {
                            $error = "Impossible de supprimer cette sous-catégorie car elle est utilisée par $component_count composant(s). Vous pouvez seulement la renommer.";
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM category_sub WHERE id = ?");
                            $stmt->execute([$id]);
                            header('Location: categories_management.php?success=subcategory_deleted');
                            exit();
                        }
                    }
                    break;
            }
        } catch (PDOException $postEx) {
            $msg = $postEx->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false || stripos($msg, 'unique_sub_category') !== false) {
                $error = "Une sous-catégorie avec ce nom existe déjà dans cette catégorie.";
            } else {
                $error = "Erreur lors du traitement : " . $msg;
            }
        }
    }

    // Récupérer les catégories principales
    $stmt = $pdo->query("SELECT * FROM category_head ORDER BY name");
    $categories = $stmt->fetchAll();

    // Récupérer les sous-catégories avec le nombre de composants
    $stmt = $pdo->query("
        SELECT cs.*, ch.name as parent_name,
               COUNT(d.id) as component_count
        FROM category_sub cs
        LEFT JOIN category_head ch ON cs.category_head_id = ch.id
        LEFT JOIN data d ON cs.id = d.category
        GROUP BY cs.id, cs.name, cs.category_head_id, ch.name
        ORDER BY ch.name, cs.id
    ");
    $subcategories = $stmt->fetchAll();
    
    // Grouper les sous-catégories par catégorie parent
    $subcategories_by_category = [];
    $subcat_total_count = 0;
    $component_total_count = 0;
    $cat_stats = []; // id => [subs_count, components_count]
    foreach ($subcategories as $subcat) {
        $subcategories_by_category[$subcat['category_head_id']][] = $subcat;
        $subcat_total_count++;
        $c_count = (int)($subcat['component_count'] ?? 0);
        $component_total_count += $c_count;
        if (!isset($cat_stats[$subcat['category_head_id']])) {
            $cat_stats[$subcat['category_head_id']] = ['subs' => 0, 'comps' => 0];
        }
        $cat_stats[$subcat['category_head_id']]['subs']++;
        $cat_stats[$subcat['category_head_id']]['comps'] += $c_count;
    }
    
    $gradient_classes = ['gradient-a','gradient-b','gradient-c','gradient-d','gradient-e','gradient-f','gradient-g','gradient-h'];
    $cat_total_count = count($categories);

    // Map des IDs de sous-catégories existantes par catégorie parent (pour JS preview + calcul PHP)
    $subcat_ids_by_parent = [];
    foreach ($subcategories as $sc) {
        $pid = (int)$sc['category_head_id'];
        if (!isset($subcat_ids_by_parent[$pid])) $subcat_ids_by_parent[$pid] = [];
        $subcat_ids_by_parent[$pid][] = (int)$sc['id'];
    }
    foreach ($subcat_ids_by_parent as &$ids) sort($ids, SORT_NUMERIC);
    unset($ids);
    $subcat_ids_by_parent_json = json_encode($subcat_ids_by_parent, JSON_NUMERIC_CHECK);

} catch(PDOException $e) {
    $error = "Erreur de base de données : " . $e->getMessage();
    $cat_total_count = 0; $subcat_total_count = 0; $component_total_count = 0;
    $cat_stats = []; $gradient_classes = ['gradient-a'];
    $subcat_ids_by_parent = []; $subcat_ids_by_parent_json = '{}';
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
    <title>Gestion des Catégories - Composants</title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .stat-icon.indigo  { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: var(--accent-indigo); }
        .stat-icon.purple  { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: var(--accent-purple); }
        .stat-icon.teal    { background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: var(--accent-teal); }
        .stat-icon.amber   { background: linear-gradient(135deg, #fef3c7, #fde68a); color: var(--accent-amber); }
        .stat-label { color: var(--text-secondary); font-size: 13px; font-weight: 500; }
        .stat-value { font-size: 26px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; margin-top: 2px; }
        .stat-sub   { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        .quick-add-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 26px;
        }
        @media (max-width: 960px) {
            .quick-add-wrapper { grid-template-columns: 1fr; }
        }
        .quick-add-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
        }
        .quick-add-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 14px;
            color: var(--text-primary);
        }
        .quick-add-title .emoji {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .quick-add-title.indigo .emoji { background: var(--accent-indigo-light); }
        .quick-add-title.purple .emoji { background: #ede9fe; }
        .quick-add-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .quick-add-form .field {
            flex: 1;
            min-width: 160px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .quick-add-form label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .quick-add-form input,
        .quick-add-form select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--bg-primary);
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
        }
        .quick-add-form input:focus,
        .quick-add-form select:focus {
            border-color: var(--accent-indigo);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
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

        .page-section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 4px 4px 16px;
        }
        .page-section-title h2 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-section-title h2::before {
            content: '';
            width: 4px;
            height: 22px;
            background: linear-gradient(180deg, var(--accent-indigo), var(--accent-purple));
            border-radius: 3px;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 20px;
        }
        .category-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .category-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .category-card-head {
            padding: 16px 18px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        .category-card-head.gradient-a { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .category-card-head.gradient-b { background: linear-gradient(135deg, #3b82f6, #06b6d4); }
        .category-card-head.gradient-c { background: linear-gradient(135deg, #10b981, #14b8a6); }
        .category-card-head.gradient-d { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .category-card-head.gradient-e { background: linear-gradient(135deg, #ef4444, #ec4899); }
        .category-card-head.gradient-f { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        .category-card-head.gradient-g { background: linear-gradient(135deg, #0ea5e9, #3b82f6); }
        .category-card-head.gradient-h { background: linear-gradient(135deg, #84cc16, #10b981); }
        .cat-title-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cat-name {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .cat-id-chip {
            font-size: 10px;
            opacity: 0.85;
            background: rgba(255,255,255,0.18);
            padding: 2px 7px;
            border-radius: 999px;
            font-weight: 500;
        }
        .subcat-id-preview {
            margin-top: 4px;
            padding: 10px 14px;
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            background: #fafbff;
            font-size: 13px;
            color: var(--text-secondary);
            display: none;
        }
        .subcat-id-preview.visible { display: block; }
        .subcat-id-preview strong {
            color: var(--accent-indigo);
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.01em;
        }
        .subcat-id-preview .hint {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            color: var(--text-muted);
        }
        .cat-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .cat-badge {
            font-size: 11px;
            font-weight: 600;
            background: rgba(255,255,255,0.22);
            backdrop-filter: blur(8px);
            padding: 3px 9px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .cat-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: 10px;
        }
        .cat-chevron {
            margin-left: 6px;
            transition: transform 0.25s;
            opacity: 0.9;
            font-size: 13px;
        }
        .category-card.expanded .cat-chevron { transform: rotate(90deg); }
        .icon-btn {
            width: 30px; height: 30px;
            border: none;
            border-radius: 8px;
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.15s;
            display: flex; align-items: center; justify-content: center;
        }
        .icon-btn:hover { background: rgba(255,255,255,0.32); }
        .icon-btn.danger:hover { background: rgba(239,68,68,0.85); }
        .category-card-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease, padding 0.3s ease;
            padding: 0 18px;
            background: linear-gradient(180deg, #fafbfc, var(--bg-primary));
        }
        .category-card.expanded .category-card-body {
            max-height: 1000px;
            padding: 16px 18px 20px;
        }
        .subcat-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .subcat-item {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 11px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid var(--border-light);
            border-left: 4px solid var(--accent-blue);
            transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
        }
        .subcat-item:hover {
            transform: translateX(3px);
            box-shadow: var(--shadow-sm);
        }
        .subcat-item.level-empty    { border-left-color: var(--border-color); }
        .subcat-item.level-low      { border-left-color: var(--accent-blue); }
        .subcat-item.level-medium   { border-left-color: var(--accent-amber); }
        .subcat-item.level-high     { border-left-color: var(--accent-green); }
        .subcat-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .subcat-name {
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .subcat-id {
            font-size: 10px;
            color: var(--text-muted);
            background: var(--bg-muted);
            padding: 1px 6px;
            border-radius: 6px;
            font-weight: 500;
        }
        .subcat-right { display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
        .count-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .count-pill.lvl-empty  { background: var(--bg-muted);     color: var(--text-secondary); }
        .count-pill.lvl-low    { background: #dbeafe;              color: #1d4ed8; }
        .count-pill.lvl-medium { background: #fef3c7;              color: #92400e; }
        .count-pill.lvl-high   { background: #d1fae5;              color: #047857; }
        .subcat-actions { display: flex; gap: 5px; }
        .empty-body {
            text-align: center;
            padding: 20px 10px;
            color: var(--text-secondary);
            font-size: 13px;
            font-style: italic;
            background: var(--bg-card);
            border-radius: var(--radius-md);
            border: 1px dashed var(--border-color);
        }

        /* ---------- Modale ---------- */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
        }
        .modal-backdrop.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-box {
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            max-width: 520px;
            width: 100%;
            padding: 28px;
            box-shadow: 0 40px 80px rgba(0,0,0,0.25);
            animation: slideUp 0.25s ease;
        }
        .modal-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-light);
        }
        .modal-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            background: linear-gradient(135deg, var(--accent-indigo-light), #ede9fe);
            color: var(--accent-purple);
        }
        .modal-title { font-size: 18px; font-weight: 800; letter-spacing: -0.01em; }
        .modal-subtitle { font-size: 13px; color: var(--text-secondary); margin-top: 2px; }
        .field-block { display: flex; flex-direction: column; gap: 7px; margin-bottom: 18px; }
        .field-block label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .field-block input {
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 15px;
            background: var(--bg-primary);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .field-block input:focus {
            border-color: var(--accent-indigo);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 8px;
        }

        footer {
            margin-top: 36px;
            padding: 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🏷️</div>
                    <div>
                        <h1>Gestion des Catégories</h1>
                        <p>Organisez vos composants électroniques avec précision</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
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

        <!-- ============ STATS CARDS ============ -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon indigo">📁</div>
                <div>
                    <div class="stat-label">Catégories</div>
                    <div class="stat-value"><?php echo $cat_total_count; ?></div>
                    <div class="stat-sub">catégories principales</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">🏷️</div>
                <div>
                    <div class="stat-label">Sous-catégories</div>
                    <div class="stat-value"><?php echo $subcat_total_count; ?></div>
                    <div class="stat-sub">catégories détaillées</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon teal">📦</div>
                <div>
                    <div class="stat-label">Composants classés</div>
                    <div class="stat-value"><?php echo $component_total_count; ?></div>
                    <div class="stat-sub">pièces référencées</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon amber">📊</div>
                <div>
                    <div class="stat-label">Moyenne par cat.</div>
                    <div class="stat-value"><?php echo $cat_total_count > 0 ? round($subcat_total_count / $cat_total_count, 1) : '0'; ?></div>
                    <div class="stat-sub">sous-catégories en moyenne</div>
                </div>
            </div>
        </div>

        <!-- ============ QUICK ADD ROW ============ -->
        <div class="quick-add-wrapper">
            <div class="quick-add-card">
                <div class="quick-add-title indigo">
                    <span class="emoji">➕</span>
                    Ajouter une catégorie principale
                </div>
                <form method="POST" class="quick-add-form" onsubmit="return validateNewCategory(this);">
                    <input type="hidden" name="action" value="add_category">
                    <div class="field">
                        <label for="category_name">Nom de la catégorie</label>
                        <input type="text" id="category_name" name="category_name" required placeholder="Ex: Microcontrôleurs">
                    </div>
                    <button type="submit" class="btn btn-indigo">➕ Ajouter</button>
                </form>
            </div>
            <div class="quick-add-card">
                <div class="quick-add-title purple">
                    <span class="emoji">🏷️</span>
                    Ajouter une sous-catégorie
                </div>
                <form method="POST" class="quick-add-form" onsubmit="return validateNewSubcategory(this);">
                    <input type="hidden" name="action" value="add_subcategory">
                    <div class="field">
                        <label for="parent_category">Catégorie parent</label>
                        <select id="parent_category" name="parent_category" required onchange="updateSubcatIdPreview(this.value);">
                            <option value="">Sélectionner…</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">[ID <?php echo $cat['id']; ?>] <?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="subcategory_name">Nom de la sous-catégorie</label>
                        <input type="text" id="subcategory_name" name="subcategory_name" required placeholder="Ex: Ruban, Coaxial, Rouleau …">
                    </div>
                    <div id="subcatIdPreview" class="subcat-id-preview">
                        ID qui sera attribué : <strong id="subcatIdPreviewValue">—</strong>
                        <span class="hint" id="subcatIdPreviewHint"></span>
                    </div>
                    <button type="submit" class="btn btn-purple">🏷️ Ajouter</button>
                </form>
            </div>
        </div>

        <!-- ============ CATEGORIES ============ -->
        <div class="page-section-title">
            <h2>Catégories existantes</h2>
            <div style="color:var(--text-muted); font-size:13px;">Cliquez sur une catégorie pour déplier ses sous-catégories</div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div style="display:none;" id="toastSuccess" data-toast="<?php
                $t = match($_GET['success']) {
                    'category_added' => 'Catégorie ajoutée avec succès !',
                    'subcategory_added' => 'Sous-catégorie ajoutée avec succès !',
                    'category_deleted' => 'Catégorie supprimée avec succès !',
                    'subcategory_deleted' => 'Sous-catégorie supprimée avec succès !',
                    'category_renamed' => 'Catégorie renommée avec succès !',
                    'subcategory_renamed' => 'Sous-catégorie renommée avec succès !',
                    default => 'Opération réussie !'
                };
                echo htmlspecialchars($t);
            ?>"></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div style="display:none;" id="toastError" data-toast="<?php echo htmlspecialchars($error); ?>"></div>
        <?php endif; ?>

        <div class="categories-grid" id="categoriesContainer">
            <?php foreach ($categories as $i => $category):
                $grad = $gradient_classes[$i % count($gradient_classes)];
                $stats = $cat_stats[$category['id']] ?? ['subs' => 0, 'comps' => 0];
            ?>
                <div class="category-card" data-category-id="<?php echo $category['id']; ?>">
                    <div class="category-card-head <?php echo $grad; ?>" onclick="toggleCategory(<?php echo $category['id']; ?>, event)">
                        <div class="cat-title-row">
                            <div class="cat-name">
                                <?php echo htmlspecialchars($category['name']); ?>
                                <span class="cat-id-chip">ID <?php echo $category['id']; ?></span>
                            </div>
                            <div class="cat-badges">
                                <span class="cat-badge">🏷️ <?php echo $stats['subs']; ?> sous-cat.</span>
                                <span class="cat-badge">📦 <?php echo $stats['comps']; ?> pièces</span>
                            </div>
                        </div>
                        <div class="cat-actions">
                            <button type="button" class="icon-btn" onclick="event.stopPropagation(); showRenameCategoryForm(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name'], ENT_QUOTES); ?>')" title="Renommer">✏️</button>
                            <?php if ($stats['subs'] > 0): ?>
                                <button type="button" class="icon-btn danger" disabled title="Impossible : contient des sous-catégories">🔒</button>
                            <?php else: ?>
                                <form method="POST" style="display:inline;" onsubmit="event.stopPropagation(); return confirmCatDelete('<?php echo htmlspecialchars($category['name'], ENT_QUOTES); ?>');">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                    <button type="submit" class="icon-btn danger" onclick="event.stopPropagation();" title="Supprimer">🗑️</button>
                                </form>
                            <?php endif; ?>
                            <span class="cat-chevron">▶</span>
                        </div>
                    </div>
                    <div class="category-card-body">
                        <?php if (!empty($subcategories_by_category[$category['id']])): ?>
                            <div class="subcat-list">
                                <?php foreach ($subcategories_by_category[$category['id']] as $subcat):
                                    $cc = (int)($subcat['component_count'] ?? 0);
                                    $lvl = 'empty';
                                    if ($cc >= 1 && $cc <= 2) $lvl = 'low';
                                    elseif ($cc >= 3 && $cc <= 7) $lvl = 'medium';
                                    elseif ($cc >= 8) $lvl = 'high';
                                ?>
                                    <div class="subcat-item level-<?php echo $lvl; ?>">
                                        <div class="subcat-info">
                                            <div class="subcat-name">
                                                🏷️ <?php echo htmlspecialchars($subcat['name']); ?>
                                                <span class="subcat-id">#<?php echo $subcat['id']; ?></span>
                                            </div>
                                        </div>
                                        <div class="subcat-right">
                                            <span class="count-pill lvl-<?php echo $lvl; ?>">📦 <?php echo $cc; ?> composant<?php echo $cc > 1 ? 's' : ''; ?></span>
                                            <div class="subcat-actions">
                                                <button type="button" class="btn btn-sm btn-ghost" onclick="showRenameSubcategoryForm(<?php echo $subcat['id']; ?>, '<?php echo htmlspecialchars($subcat['name'], ENT_QUOTES); ?>')">✏️</button>
                                                <?php if ($cc > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-ghost" disabled title="Contient <?php echo $cc; ?> composant(s) — renommer seulement">🔒</button>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirmSubDelete('<?php echo htmlspecialchars($subcat['name'], ENT_QUOTES); ?>', 0);">
                                                        <input type="hidden" name="action" value="delete_subcategory">
                                                        <input type="hidden" name="subcategory_id" value="<?php echo $subcat['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Supprimer">🗑️</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-body">📭 Aucune sous-catégorie — ajoutez-en une dans le formulaire ci-dessus</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (count($categories) === 0): ?>
                <div style="grid-column:1/-1; text-align:center; padding:60px 20px; background:var(--bg-card); border-radius:var(--radius-lg); border:2px dashed var(--border-color); color:var(--text-secondary);">
                    <div style="font-size:48px; margin-bottom:12px;">🗂️</div>
                    <div style="font-size:18px; font-weight:700; color:var(--text-primary); margin-bottom:4px;">Aucune catégorie créée</div>
                    <div style="font-size:14px;">Utilisez le formulaire <strong>"+ Ajouter une catégorie principale"</strong> pour commencer</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ MODALE RENOMMER CATEGORIE ============ -->
        <div class="modal-backdrop" id="renameCategoryModal">
            <div class="modal-box">
                <div class="modal-head">
                    <div class="modal-icon">✏️</div>
                    <div>
                        <div class="modal-title">Renommer la catégorie</div>
                        <div class="modal-subtitle">Modifiez le nom de la catégorie principale</div>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="rename_category">
                    <input type="hidden" name="category_id" id="renameCategoryId">
                    <div class="field-block">
                        <label for="new_category_name">Nouveau nom</label>
                        <input type="text" id="new_category_name" name="new_name" required placeholder="Ex: Microcontrôleurs">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-ghost" onclick="closeCategoryModal()">Annuler</button>
                        <button type="submit" class="btn btn-indigo">✏️ Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============ MODALE RENOMMER SOUS-CATEGORIE ============ -->
        <div class="modal-backdrop" id="renameSubcategoryModal">
            <div class="modal-box">
                <div class="modal-head">
                    <div class="modal-icon">🏷️</div>
                    <div>
                        <div class="modal-title">Renommer la sous-catégorie</div>
                        <div class="modal-subtitle">Modifiez le nom de la sous-catégorie</div>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="rename_subcategory">
                    <input type="hidden" name="subcategory_id" id="renameSubcategoryId">
                    <div class="field-block">
                        <label for="new_subcategory_name">Nouveau nom</label>
                        <input type="text" id="new_subcategory_name" name="new_name" required placeholder="Ex: Arduino Mega">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-ghost" onclick="closeSubcategoryModal()">Annuler</button>
                        <button type="submit" class="btn btn-purple">✏️ Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============ TOAST ============ -->
        <div id="toast-container" style="position:fixed; top:24px; right:24px; z-index:9999; pointer-events:none;"></div>

    </div>

    <footer>
        Créé par Jérémy Leroy — Version 1.1 — Copyright © 2025 — Tous droits réservés selon la licence CC BY-NC-SA 3.0
    </footer>

    <script>
        // ============ Map IDs sous-catégories existantes par parent (pour preview ID hiérarchique) ============
        const SUBCAT_IDS_BY_PARENT = <?php echo $subcat_ids_by_parent_json; ?>;

        function computeNextSubcatId(parentId) {
            parentId = parseInt(parentId, 10);
            if (!parentId || parentId <= 0) return null;
            const existing = SUBCAT_IDS_BY_PARENT['' + parentId] || SUBCAT_IDS_BY_PARENT[parentId] || [];
            existing.sort(function (a, b) { return a - b; });
            const base = parentId * 100;
            const used = [];
            for (let i = 0; i < existing.length; i++) {
                const id = parseInt(existing[i], 10);
                if (id > base && id <= base + 99) used.push(id - base);
            }
            used.sort(function (a, b) { return a - b; });
            let nextSuffix = 1;
            for (let i = 0; i < used.length; i++) {
                const s = used[i];
                if (s === nextSuffix) nextSuffix++;
                else if (s > nextSuffix) break;
            }
            if (nextSuffix <= 99) return base + nextSuffix;
            let maxId = base + 99;
            for (let i = 0; i < existing.length; i++) {
                const id = parseInt(existing[i], 10);
                if (id > maxId) maxId = id;
            }
            return maxId + 1;
        }

        function updateSubcatIdPreview(parentId) {
            const preview = document.getElementById('subcatIdPreview');
            const valueEl = document.getElementById('subcatIdPreviewValue');
            const hintEl = document.getElementById('subcatIdPreviewHint');
            if (!preview || !valueEl) return;
            parentId = parseInt(parentId, 10);
            if (!parentId || parentId <= 0) {
                preview.classList.remove('visible');
                valueEl.textContent = '—';
                if (hintEl) hintEl.textContent = '';
                return;
            }
            const next = computeNextSubcatId(parentId);
            preview.classList.add('visible');
            valueEl.textContent = next;
            if (hintEl) {
                const base = parentId * 100;
                const suffix = next - base;
                if (suffix >= 1 && suffix <= 99) {
                    const xx = suffix < 10 ? ('0' + suffix) : ('' + suffix);
                    hintEl.textContent = `${parentId} × 100 + ${suffix} = ${next} (suffixe xx = ${xx}, premier libre)`;
                } else {
                    hintEl.textContent = `${parentId} × 100 + ${suffix} = ${next} (hors plage conventionnelle, plus de 99 sous-catégories dans cette catégorie)`;
                }
            }
        }

        // Init preview à l'ouverture (si la catégorie est déjà pré-selectionnée via navigateur)
        document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById('parent_category');
            if (sel && sel.value) updateSubcatIdPreview(sel.value);
        });

        // Données pour validation anti-doublon
        const existingCategories = <?php
            $arr = array_map(fn($c) => mb_strtolower(trim($c['name'])), $categories);
            echo json_encode($arr, JSON_UNESCAPED_UNICODE);
        ?>;

        // ============ Validation anti-doublon catégories ============
        function validateNewCategory(form) {
            const input = form.querySelector('input[name="category_name"]');
            const v = (input.value || '').trim();
            if (!v) return true;
            if (existingCategories.includes(mbStrtolower(v))) {
                showToast(`⚠️ Une catégorie nommée "${v}" existe déjà !`, 'warn');
                input.style.borderColor = '#ef4444';
                input.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.15)';
                input.focus();
                input.select();
                setTimeout(() => { input.style.borderColor = ''; input.style.boxShadow = ''; }, 2000);
                return false;
            }
            return true;
        }

        function validateNewSubcategory(form) {
            const sel = form.querySelector('select[name="parent_category"]');
            const input = form.querySelector('input[name="subcategory_name"]');
            const v = (input.value || '').trim();
            const pid = sel.value;
            if (!v || !pid) return true;
            // Vérifier les sous-catégories existantes dans cette catégorie
            const subsOfParent = <?php
                $out = [];
                foreach ($subcategories as $s) {
                    $out[$s['category_head_id']][] = mb_strtolower(trim($s['name']));
                }
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            ?>;
            const list = subsOfParent[pid] || [];
            if (list.includes(mbStrtolower(v))) {
                showToast(`⚠️ La sous-catégorie "${v}" existe déjà dans cette catégorie !`, 'warn');
                input.style.borderColor = '#ef4444';
                input.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.15)';
                input.focus();
                input.select();
                setTimeout(() => { input.style.borderColor = ''; input.style.boxShadow = ''; }, 2000);
                return false;
            }
            return true;
        }

        // Polyfill mb_strtolower léger
        function mbStrtolower(s) {
            return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        // ============ Toggle accordéon ============
        function toggleCategory(catId, evt) {
            const card = document.querySelector(`[data-category-id="${catId}"]`);
            if (!card) return;
            card.classList.toggle('expanded');
        }

        // ============ Confirmations suppressions ============
        function confirmCatDelete(name) {
            return confirm(`⚠️ Supprimer la catégorie "${name}" ?\n\nCette action est irréversible.`);
        }
        function confirmSubDelete(name, count) {
            if (count > 0) {
                alert(`❌ Impossible de supprimer "${name}" : ${count} composant(s) utilisent cette sous-catégorie.\n\nVous pouvez seulement la renommer.`);
                return false;
            }
            return confirm(`⚠️ Supprimer la sous-catégorie "${name}" ?\n\nCette action est irréversible.`);
        }

        // ============ Modales ============
        const catModal = document.getElementById('renameCategoryModal');
        const subModal = document.getElementById('renameSubcategoryModal');

        function showRenameCategoryForm(id, current) {
            document.getElementById('renameCategoryId').value = id;
            const inp = document.getElementById('new_category_name');
            inp.value = current;
            catModal.classList.add('active');
            setTimeout(() => { inp.focus(); inp.select(); }, 50);
        }
        function closeCategoryModal() { catModal.classList.remove('active'); }

        function showRenameSubcategoryForm(id, current) {
            document.getElementById('renameSubcategoryId').value = id;
            const inp = document.getElementById('new_subcategory_name');
            inp.value = current;
            subModal.classList.add('active');
            setTimeout(() => { inp.focus(); inp.select(); }, 50);
        }
        function closeSubcategoryModal() { subModal.classList.remove('active'); }

        // Fermeture clic hors modal
        catModal.addEventListener('click', e => { if (e.target === catModal) closeCategoryModal(); });
        subModal.addEventListener('click', e => { if (e.target === subModal) closeSubcategoryModal(); });

        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            closeCategoryModal();
            closeSubcategoryModal();
        });

        // ============ Toast ============
        function showToast(msg, kind = 'ok') {
            const wrap = document.getElementById('toast-container');
            const el = document.createElement('div');
            el.textContent = msg;
            const bg = kind === 'ok' ? 'linear-gradient(135deg, #10b981, #059669)'
                   : kind === 'warn' ? 'linear-gradient(135deg, #f59e0b, #d97706)'
                   : 'linear-gradient(135deg, #ef4444, #dc2626)';
            el.style.cssText = `
                background: ${bg};
                color: white;
                padding: 12px 18px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 13px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                margin-bottom: 10px;
                animation: toastIn 0.25s ease;
                pointer-events: auto;
                min-width: 220px;
            `;
            wrap.appendChild(el);
            setTimeout(() => {
                el.style.transition = 'opacity 0.3s, transform 0.3s';
                el.style.opacity = '0';
                el.style.transform = 'translateX(20px)';
                setTimeout(() => el.remove(), 320);
            }, 3200);
        }
        const style = document.createElement('style');
        style.textContent = `@keyframes toastIn { from { transform: translateX(40px); opacity: 0 } to { transform: translateX(0); opacity: 1 } }`;
        document.head.appendChild(style);

        // Afficher toast au chargement si param
        document.addEventListener('DOMContentLoaded', () => {
            const succ = document.getElementById('toastSuccess');
            const err = document.getElementById('toastError');
            if (succ?.dataset.toast) showToast(succ.dataset.toast, 'ok');
            if (err?.dataset.toast)  showToast(err.dataset.toast, 'err');
        });
    </script>
</body>
</html>

