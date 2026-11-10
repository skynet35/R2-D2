<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$pdo = getConnection();
$storageTypes = getLocationStorageTypes();

// Traitement des messages
$success_message = '';
$error_message = '';

if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $success_message = 'Emplacement ajouté avec succès!';
            break;
        case 'batch_added':
            $success_message = 'Emplacements créés par grappe avec succès!';
            break;
        case 'updated':
            $success_message = 'Emplacement modifié avec succès!';
            break;
        case 'deleted':
            $success_message = 'Emplacement supprimé avec succès!';
            break;
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'exists':
            $error_message = 'Cet emplacement existe déjà!';
            break;
        case 'invalid':
            $error_message = 'Données invalides!';
            break;
        case 'not_found':
            $error_message = 'Emplacement non trouvé!';
            break;
        case 'in_use':
            $error_message = 'Impossible de supprimer: cet emplacement est utilisé par des composants!';
            break;
    }
}

// Traitement AJAX : changement rapide de type pour un casier entier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_casier_type') {
    header('Content-Type: application/json');
    $casier = trim($_POST['casier'] ?? '');
    $new_type = trim($_POST['storage_type'] ?? '');
    $storageTypes = getLocationStorageTypes();
    if (empty($casier) || !isset($storageTypes[$new_type])) {
        echo json_encode(['ok' => false, 'error' => 'Données invalides']);
        exit();
    }
    try {
        $stmt = $pdo->prepare("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?");
        $stmt->execute([$new_type, $user_id, $casier]);
        $meta = $storageTypes[$new_type];
        echo json_encode([
            'ok' => true,
            'icon' => $meta['icon'],
            'label' => $meta['label'],
            'casier' => $casier,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Erreur base de données']);
    }
    exit();
}

// Traitement AJAX : renommage du nom principal d'un conteneur (casier/classeur/étagère/boîte)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_casier') {
    header('Content-Type: application/json');
    $old_name = trim($_POST['casier'] ?? '');
    $new_name = trim($_POST['new_name'] ?? '');

    if ($old_name === '' || $new_name === '') {
        echo json_encode(['ok' => false, 'error' => 'Nom invalide']);
        exit();
    }

    if ($old_name === $new_name) {
        echo json_encode(['ok' => true, 'renamed' => 0]);
        exit();
    }

    try {
        // Vérifie qu'aucun autre conteneur de même propriétaire ne porte déjà ce nouveau nom
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM location WHERE owner = ? AND casier = ? AND casier <> ?");
        $stmt->execute([$user_id, $new_name, $old_name]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => "Un conteneur nommé '$new_name' existe déjà"]);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE location SET casier = ? WHERE owner = ? AND casier = ?");
        $stmt->execute([$new_name, $user_id, $old_name]);
        $renamed = $stmt->rowCount();

        if ($renamed === 0) {
            echo json_encode(['ok' => false, 'error' => "Conteneur '$old_name' introuvable"]);
            exit();
        }

        echo json_encode(['ok' => true, 'old' => $old_name, 'new' => $new_name, 'renamed' => $renamed]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Erreur base de données']);
    }
    exit();
}

// Traitement AJAX : suppression d'un casier/classeur/étagère/boîte TOTALEMENT vide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_empty_casier') {
    header('Content-Type: application/json');
    $casier = trim($_POST['casier'] ?? '');
    if (empty($casier)) {
        echo json_encode(['ok' => false, 'error' => 'Données invalides']);
        exit();
    }
    try {
        // 1) Vérification serveur IMPÉRATIVE : est-ce que ce casier contient réellement 0 composant ?
        //    (double sécurité : on ne fait PAS confiance au client)
        $stmt = $pdo->prepare("
            SELECT COUNT(d.id) AS nb_components
            FROM location l
            LEFT JOIN data d ON d.location_id = l.id
            WHERE l.owner = ? AND l.casier = ?
        ");
        $stmt->execute([$user_id, $casier]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$check || (int)$check['nb_components'] > 0) {
            echo json_encode(['ok' => false, 'error' => 'Ce conteneur n’est pas vide (contient des composants) — suppression impossible.']);
            exit();
        }

        // 2) Vérifier qu'il y a bien des emplacements à supprimer pour cet owner+casier
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM location WHERE owner = ? AND casier = ?");
        $stmt->execute([$user_id, $casier]);
        $cnt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cnt || (int)$cnt['c'] === 0) {
            echo json_encode(['ok' => false, 'error' => 'Conteneur introuvable.']);
            exit();
        }

        // 3) On supprime TOUS les emplacements liés à ce casier
        $stmt = $pdo->prepare("DELETE FROM location WHERE owner = ? AND casier = ?");
        $stmt->execute([$user_id, $casier]);

        echo json_encode(['ok' => true, 'casier' => $casier, 'deleted' => (int)$cnt['c']]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Erreur base de données : ' . $e->getMessage()]);
    }
    exit();
}

// Récupérer tous les emplacements de l'utilisateur
$stmt = $pdo->prepare("SELECT l.*, 
                             COUNT(d.id) as component_count,
                             COALESCE(SUM(d.quantity), 0) as total_quantity
                      FROM location l 
                      LEFT JOIN data d ON l.id = d.location_id 
                      WHERE l.owner = ? 
                      GROUP BY l.id 
                      ORDER BY l.casier, l.tiroir, l.compartiment");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les noms des composants pour chaque emplacement (1er + total)
$componentNames = [];
if (!empty($locations)) {
    $locationIds = array_column($locations, 'id');
    $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
    
    $stmtNames = $pdo->prepare("SELECT d.location_id, d.name, d.quantity,
                                       ROW_NUMBER() OVER (PARTITION BY d.location_id ORDER BY d.quantity DESC, d.id) as rn
                                FROM data d 
                                WHERE d.location_id IN ($placeholders)");
    $stmtNames->execute($locationIds);
    $rows = $stmtNames->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $r) {
        $lid = $r['location_id'];
        if (!isset($componentNames[$lid])) {
            $componentNames[$lid] = [
                'first_name' => $r['name'],
                'first_qty'  => (int)$r['quantity'],
                'total'      => 0
            ];
        }
        $componentNames[$lid]['total']++;
    }
}

// Injecter les infos composant dans $locations
foreach ($locations as &$loc) {
    $lid = $loc['id'];
    if (isset($componentNames[$lid])) {
        $loc['component_first_name'] = $componentNames[$lid]['first_name'];
        $loc['component_first_qty']  = $componentNames[$lid]['first_qty'];
        $loc['component_total_refs'] = $componentNames[$lid]['total'];
    } else {
        $loc['component_first_name'] = null;
        $loc['component_first_qty']  = 0;
        $loc['component_total_refs'] = 0;
    }
}
unset($loc);

// Récupérer les IDs des tiroirs pour chaque casier/tiroir
$tiroir_ids = [];
foreach ($locations as $location) {
    $key = $location['casier'] . '-' . $location['tiroir'];
    if (!isset($tiroir_ids[$key])) {
        $tiroir_ids[$key] = $location['id'];
    }
}

function getFillClass($qty, $comp_count) {
    if ($qty <= 0 && $comp_count <= 0) return 'empty';
    if ($qty <= 5) return 'low';
    if ($qty <= 25) return 'medium';
    return 'full';
}

function getStorageTypeMeta($storageType, $storageTypes) {
    $key = trim((string)$storageType);
    if ($key === '' || !isset($storageTypes[$key])) {
        $key = 'casier';
    }

    return [
        'key' => $key,
        'label' => $storageTypes[$key]['label'],
        'icon' => $storageTypes[$key]['icon'],
    ];
}

$total_all_qty = 0;
$total_all_comps = 0;
$total_all_tiroirs = 0;
$total_all_compartiments = 0;

$casiersForStats = [];
foreach ($locations as $location) {
    $total_all_qty += (int)$location['total_quantity'];
    $total_all_comps += (int)$location['component_count'];
    $total_all_compartiments++;
    $tk = $location['casier'] . '-' . $location['tiroir'];
    if (!isset($casiersForStats[$tk])) {
        $casiersForStats[$tk] = true;
        $total_all_tiroirs++;
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
    <title>Gestion des Emplacements</title>
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

        .content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 30px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
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

        .action-buttons {
            margin-bottom: 30px;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: var(--accent-green);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--accent-pink) 0%, var(--accent-red) 100%);
            color: white;
        }

        .btn-danger {
            background: var(--accent-red);
            color: white;
        }

        .locations-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--bg-card);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .locations-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }

        .locations-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .locations-table tr:hover {
            background-color: var(--bg-subtle);
        }

        .location-code {
            font-family: 'Courier New', monospace;
            background: var(--bg-subtle);
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
        }

        .component-count {
            background: #e3f2fd;
            color: #1976d2;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.9em;
            font-weight: bold;
        }

        .no-locations {
            text-align: center;
            padding: 50px;
            color: var(--text-secondary);
            font-size: 1.2em;
        }

        .view-toggle {
            margin-bottom: 20px;
            text-align: center;
        }

        .toggle-btn {
            background: var(--bg-subtle);
            border: 2px solid #dee2e6;
            padding: 10px 20px;
            margin: 0 5px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: bold;
            font-size: 0.92rem;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .toggle-btn:hover {
            border-color: #667eea;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.15);
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 6px 18px rgba(102, 126, 234, 0.3);
        }

        /* ===========================================
           NOUVEAU SYSTEME DE CASIERS REALISTES
           =========================================== */

        .casiers-real-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 22px;
            margin-bottom: 30px;
        }

        /* ======= CASIER COMPACT (carte cliquable) ======= */

        .casier-compact-card {
            background: linear-gradient(135deg, #ffffff 0%, var(--bg-primary) 100%);
            border: 2px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 18px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        }

        .casier-compact-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .casier-compact-card:hover {
            transform: translateY(-4px);
            border-color: #667eea;
            box-shadow:
                0 14px 28px rgba(102, 126, 234, 0.18),
                0 4px 10px rgba(15, 23, 42, 0.08);
        }

        .casier-compact-card:hover::before {
            height: 6px;
        }

        .casier-compact-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px dashed var(--border-color);
        }

        .casier-compact-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .casier-compact-title h3 {
            margin: 0;
            font-size: 1.05rem;
            color: #0f172a;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .casier-rename-btn {
            background: rgba(99, 102, 241, 0.12);
            color: #4f46e5;
            border: 1px solid rgba(99, 102, 241, 0.35);
            border-radius: 6px;
            padding: 1px 7px;
            font-size: 0.72rem;
            cursor: pointer;
            font-weight: 700;
            line-height: 1.5;
            transition: all 0.15s ease;
        }

        .casier-rename-btn:hover {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
        }

        .casier-detail-title h2 .casier-rename-btn {
            font-size: 0.78rem;
            padding: 3px 10px;
        }

        .casier-compact-title small {
            color: var(--text-secondary);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .casier-compact-arrow {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: grid;
            place-items: center;
            font-size: 1rem;
            font-weight: 900;
            transition: all 0.3s ease;
        }

        .casier-compact-card:hover .casier-compact-arrow {
            transform: translateX(3px) scale(1.08);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.4);
        }

        .casier-compact-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .compact-stat {
            background: var(--bg-card);
            border: 1px solid var(--bg-muted);
            border-radius: 10px;
            padding: 10px 11px;
            transition: all 0.2s ease;
        }

        .casier-compact-card:hover .compact-stat {
            border-color: #e0e7ff;
        }

        .compact-stat .cs-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 2px;
        }

        .compact-stat .cs-value {
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.1;
        }

        .compact-stat.tiroirs .cs-value { color: #667eea; }
        .compact-stat.compartiments .cs-value { color: #0ea5e9; }
        .compact-stat.pieces .cs-value { color: #10b981; }
        .compact-stat.references .cs-value { color: #f59e0b; }

        @media (max-width: 640px) {
            .casiers-real-grid { grid-template-columns: 1fr; }
        }

        .casier-real {
            background: linear-gradient(180deg, var(--border-color) 0%, var(--border-strong) 50%, var(--text-muted) 100%);
            border: 3px solid var(--text-secondary);
            border-radius: 14px;
            padding: 18px 16px 22px;
            box-shadow:
                0 15px 30px rgba(15, 23, 42, 0.2),
                inset 0 1px 0 rgba(255,255,255,0.8),
                inset 0 -3px 8px rgba(0,0,0,0.15);
            position: relative;
            overflow: visible;
        }

        .casier-real::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 4px;
            background: linear-gradient(90deg, transparent, rgba(71, 85, 105, 0.6), transparent);
            border-radius: 0 0 4px 4px;
        }

        .casier-real-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #475569, #334155);
            color: var(--bg-primary);
            padding: 10px 14px;
            border-radius: 9px;
            margin-bottom: 16px;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.15),
                0 2px 6px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: all 0.25s ease;
        }

        .casier-real-header:hover {
            background: linear-gradient(180deg, #5b6e87, #3f4e65);
            transform: translateY(-1px);
        }

        .casier-real-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .casier-real-badge {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #fde68a 0%, #fbbf24 100%);
            color: #78350f;
            font-weight: 900;
            font-size: 1.4rem;
            display: grid;
            place-items: center;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.6),
                0 3px 8px rgba(180, 83, 9, 0.25);
            letter-spacing: 0.5px;
            overflow: hidden;
            padding: 2px;
            flex-shrink: 0;
        }

        .casier-real-badge.casier-badge-logo {
            background: linear-gradient(135deg, #ffffff 0%, var(--bg-muted) 100%);
            border: 2px solid var(--border-color);
            padding: 4px;
        }

        .casier-real-badge.casier-badge-logo.casier-badge-large {
            padding: 6px;
        }

        .casier-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .casier-logo-letter {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            font-weight: 900;
            line-height: 1;
            white-space: nowrap;
        }

        .casier-badge-clickable {
            cursor: pointer;
            position: relative;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .casier-badge-clickable:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.25);
            outline: 2px dashed var(--accent-indigo, #6366f1);
            outline-offset: 2px;
        }
        .casier-badge-edit {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 20px;
            height: 20px;
            background: var(--accent-indigo, #6366f1);
            color: #fff !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            border-radius: 50%;
            display: grid;
            place-items: center;
            box-shadow: 0 2px 6px rgba(79,70,229,0.4);
            opacity: 0;
            transform: scale(0.6);
            transition: all 0.2s ease;
            pointer-events: none;
            line-height: 1 !important;
            letter-spacing: 0 !important;
        }
        .casier-badge-clickable:hover .casier-badge-edit {
            opacity: 1;
            transform: scale(1);
        }
        .casier-real-badge.casier-badge-large .casier-badge-edit {
            width: 24px;
            height: 24px;
            font-size: 13px !important;
            top: -8px;
            right: -8px;
        }

        /* Modal changement rapide de type/logo */
        .type-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
        }
        .type-modal-overlay.visible { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes popIn { from { opacity: 0; transform: translateY(20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .type-modal {
            background: var(--bg-card, #fff);
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            padding: 26px 26px 22px;
            animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid var(--border-color);
        }
        .type-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .type-modal-header h3 {
            margin: 0;
            font-size: 1.15rem;
            color: var(--text-primary);
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .type-modal-header h3 .casier-chip {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            color: #4338ca;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 900;
            border: 1px solid #c7d2fe;
        }
        .type-modal-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
            line-height: 1;
            padding: 2px 8px;
            border-radius: 8px;
            transition: all 0.15s;
        }
        .type-modal-close:hover { background: var(--bg-muted); color: var(--accent-red); }
        .type-modal-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 10px;
        }
        .type-modal-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }
        .type-btn {
            border: 2px solid var(--border-color);
            background: var(--bg-muted);
            border-radius: 12px;
            padding: 14px 6px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            color: var(--text-primary);
        }
        .type-btn:hover:not(:disabled) {
            border-color: var(--accent-indigo, #6366f1);
            background: #eef2ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }
        .type-btn.active {
            border-color: var(--accent-indigo, #6366f1);
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            box-shadow: inset 0 0 0 1px #6366f1, 0 4px 14px rgba(99, 102, 241, 0.25);
        }
        .type-btn.active .type-icon,
        .type-btn:hover:not(:disabled) .type-icon { transform: scale(1.12); }
        .type-btn .type-icon {
            font-size: 2rem;
            transition: transform 0.2s ease;
            line-height: 1;
        }
        .type-btn .type-name {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .type-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(0.5);
        }
        .type-modal-divider {
            height: 1px;
            background: var(--border-color);
            margin: 14px 0 16px;
        }
        .type-modal-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .type-edit-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 10px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #78350f;
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            border: 1px solid #fcd34d;
            transition: all 0.15s;
            cursor: pointer;
            font-family: inherit;
        }
        .type-edit-link:hover {
            background: linear-gradient(135deg, #fde68a, #fcd34d);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(202, 138, 4, 0.25);
        }
        .type-status {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            display: none;
            text-align: center;
        }
        .type-status.loading {
            display: block;
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
        }
        .type-status.success {
            display: block;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .type-status.error {
            display: block;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .casier-real-title h3 {
            font-size: 1rem;
            margin: 0 0 2px 0;
            color: var(--bg-primary);
        }

        .casier-real-title span {
            font-size: 0.78rem;
            color: var(--border-strong);
            opacity: 0.9;
        }

        .casier-real-stats {
            display: flex;
            gap: 6px;
        }

        .stat-pill {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            color: var(--bg-muted);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            backdrop-filter: blur(4px);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .stat-pill.primary {
            background: linear-gradient(135deg, rgba(56,189,248,0.25), rgba(59,130,246,0.25));
            border-color: rgba(125, 211, 252, 0.4);
            color: #e0f2fe;
        }

        .stat-pill.success {
            background: linear-gradient(135deg, rgba(52,211,153,0.25), rgba(16,185,129,0.25));
            border-color: rgba(110, 231, 183, 0.4);
            color: #d1fae5;
        }

        /* ======= CORPS DU CASIER (tiroirs) ======= */

        .casier-real-body {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* ======= TIROIR ======= */

        .tiroir-real {
            background: linear-gradient(180deg, var(--bg-muted) 0%, var(--border-color) 100%);
            border: 2px solid var(--text-muted);
            border-radius: 10px;
            padding: 12px 14px 14px;
            position: relative;
            transition: all 0.25s ease;
            box-shadow:
                0 3px 6px rgba(15, 23, 42, 0.08),
                inset 0 1px 0 rgba(255,255,255,0.9);
        }

        .tiroir-real:hover {
            transform: translateY(-2px);
            box-shadow:
                0 7px 15px rgba(15, 23, 42, 0.13),
                inset 0 1px 0 rgba(255,255,255,0.9);
            border-color: #667eea;
        }

        /* Poignée du tiroir */
        .tiroir-real::before {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -1px;
            transform: translateX(-50%);
            width: 70px;
            height: 7px;
            background: linear-gradient(180deg, var(--text-secondary), #475569);
            border-radius: 0 0 6px 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .tiroir-real-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--border-strong);
        }

        .tiroir-real-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-weight: 800;
            color: #334155;
            font-size: 0.88rem;
        }

        .tiroir-num {
            display: inline-grid;
            place-items: center;
            width: 26px;
            height: 26px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 7px;
            font-size: 0.82rem;
            font-weight: 900;
            box-shadow: 0 2px 5px rgba(102, 126, 234, 0.3);
        }

        .tiroir-real-meta {
            display: flex;
            gap: 6px;
        }

        .meta-chip {
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .meta-chip.id-chip {
            background: #e0e7ff;
            color: #4338ca;
        }

        .meta-chip.qty-chip {
            background: #dcfce7;
            color: #166534;
        }

        /* ======= GRILLE COMPARTIMENTS ======= */

        .compartiments-grid-real {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(108px, 1fr));
            gap: 8px;
        }

        /* ======= COMPARTIMENT INDIVIDUEL ======= */

        .compartiment-real {
            position: relative;
            border-radius: 8px;
            padding: 9px 7px 11px;
            border: 2px solid;
            background: linear-gradient(180deg, #ffffff, var(--bg-primary));
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            text-align: center;
            gap: 2px;
            overflow: hidden;
        }

        /* Variantes de couleur selon remplissage */
        .compartiment-real.empty {
            border-color: var(--border-color);
            background: linear-gradient(180deg, #ffffff, var(--bg-primary));
        }

        .compartiment-real.low {
            border-color: #bfdbfe;
            background: linear-gradient(180deg, #eff6ff, #dbeafe);
        }

        .compartiment-real.medium {
            border-color: #fde68a;
            background: linear-gradient(180deg, #fffbeb, #fef3c7);
        }

        .compartiment-real.full {
            border-color: #bbf7d0;
            background: linear-gradient(180deg, #f0fdf4, #dcfce7);
        }

        .compartiment-real:hover {
            transform: translateY(-2px) scale(1.02);
            z-index: 2;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
        }

        .compartiment-real.empty:hover { box-shadow: 0 8px 18px rgba(148, 163, 184, 0.25); }
        .compartiment-real.low:hover { box-shadow: 0 8px 18px rgba(59, 130, 246, 0.25); }
        .compartiment-real.medium:hover { box-shadow: 0 8px 18px rgba(245, 158, 11, 0.25); }
        .compartiment-real.full:hover { box-shadow: 0 8px 18px rgba(34, 197, 94, 0.25); }

        /* Étiquette du compartiment */
        .compartiment-tag {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-weight: 800;
            font-size: 0.78rem;
            padding: 2px 7px;
            border-radius: 5px;
            color: #0f172a;
            line-height: 1.2;
        }

        .empty .compartiment-tag { background: var(--bg-muted); color: var(--text-secondary); }
        .low .compartiment-tag { background: #dbeafe; color: #1e40af; }
        .medium .compartiment-tag { background: #fde68a; color: #854d0e; }
        .full .compartiment-tag { background: #bbf7d0; color: #15803d; }

        .compartiment-id-mini {
            font-size: 0.58rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: -1px;
        }

        .compartiment-quantity {
            font-weight: 900;
            font-size: 0.85rem;
            line-height: 1;
            margin: 2px 0 4px;
        }

        .empty .compartiment-quantity { color: var(--text-muted); }
        .low .compartiment-quantity { color: #2563eb; }
        .medium .compartiment-quantity { color: #d97706; }
        .full .compartiment-quantity { color: #16a34a; }

        .compartiment-quantity .unit {
            font-weight: 500;
            font-size: 0.65rem;
            margin-left: 1px;
            opacity: 0.75;
        }

        .compartiment-component-name {
            font-size: 0.72rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
            margin: 2px 0 5px;
            padding: 0 3px;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .empty .compartiment-component-name { color: var(--text-muted); font-style: italic; font-weight: 500; }
        .low .compartiment-component-name { color: #1e3a8a; }
        .medium .compartiment-component-name { color: #854d0e; }
        .full .compartiment-component-name { color: #14532d; }

        .compartiment-component-name .extra {
            font-weight: 500;
            font-size: 0.62rem;
            opacity: 0.75;
            margin-left: 2px;
        }

        .compartiment-actions-real {
            display: flex;
            gap: 3px;
            margin-top: auto;
            flex-wrap: wrap;
            justify-content: center;
        }

        .icon-btn {
            width: 21px;
            height: 21px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            display: inline-grid;
            place-items: center;
            font-size: 0.65rem;
            color: white;
            font-weight: 700;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .icon-btn:hover { transform: scale(1.15); }
        .icon-btn.view { background: #3b82f6; }
        .icon-btn.view:hover { background: #2563eb; }
        .icon-btn.edit { background: #f59e0b; }
        .icon-btn.edit:hover { background: #d97706; }
        .icon-btn.del { background: #ef4444; }
        .icon-btn.del:hover { background: #dc2626; }
        .icon-btn.del:disabled, .icon-btn.del.disabled {
            background: var(--border-color);
            color: var(--text-muted);
            cursor: not-allowed;
            transform: none;
        }

        /* Petit repère visuel "remplissage" */
        .compartiment-fill-dot {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 7px;
            height: 7px;
            border-radius: 999px;
            box-shadow: 0 0 0 2px white;
        }
        .empty .compartiment-fill-dot { background: var(--border-strong); }
        .low .compartiment-fill-dot { background: #3b82f6; }
        .medium .compartiment-fill-dot { background: #f59e0b; }
        .full .compartiment-fill-dot { background: #22c55e; }

        /* Légende */
        .legend-bar {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 24px;
            padding: 14px 18px;
            background: var(--bg-primary);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8rem;
            color: #475569;
            font-weight: 600;
        }

        .legend-dot {
            width: 16px;
            height: 16px;
            border-radius: 5px;
            border: 2px solid;
        }

        .legend-dot.empty { border-color: var(--border-color); background: var(--bg-primary); }
        .legend-dot.low { border-color: #bfdbfe; background: #dbeafe; }
        .legend-dot.medium { border-color: #fde68a; background: #fef3c7; }
        .legend-dot.full { border-color: #bbf7d0; background: #dcfce7; }

        /* ===========================================
           CASIER DÉTAILLÉ (quand on clique sur un casier)
           =========================================== */

        .casier-detail-view {
            display: none;
            margin-top: 20px;
        }

        .casier-detail-view.active {
            display: block;
        }

        .casier-detail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 14px;
            padding: 16px 18px;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #eef2f7 100%);
            border-radius: 14px;
            border: 1px solid #d7e0ea;
        }

        .back-btn-real {
            background: var(--text-secondary);
            color: white;
            border: none;
            padding: 9px 17px;
            border-radius: 9px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .back-btn-real:hover { background: #475569; transform: translateY(-1px); }

        .casier-delete-btn {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 7px 13px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.18s ease;
        }
        .casier-delete-btn:hover {
            background: #ef4444;
            color: white;
            border-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        .casier-delete-btn.compact {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 5;
            padding: 5px 9px;
            font-size: 0.72rem;
            border-radius: 7px;
            background: rgba(254, 226, 226, 0.92);
            backdrop-filter: blur(4px);
        }
        .casier-detail-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .casier-detail-title {
            display: inline-flex;
            align-items: center;
            gap: 14px;
        }
        .casier-detail-title .casier-real-badge {
            width: 56px; height: 56px; font-size: 1.7rem; border-radius: 12px;
        }
        .casier-detail-title h2 {
            margin: 0;
            font-size: 1.35rem;
            color: #0f172a;
        }
        .casier-detail-title small {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .stat-grid-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(110px, 1fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .summary-card {
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .summary-card .summary-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 4px;
        }
        .summary-card .summary-value {
            font-size: 1.2rem;
            font-weight: 900;
            color: #0f172a;
        }
        .summary-card.tiroirs .summary-value { color: #667eea; }
        .summary-card.compartiments .summary-value { color: #0ea5e9; }
        .summary-card.pieces .summary-value { color: #10b981; }
        .summary-card.references .summary-value { color: #f59e0b; }

        .casier-drawer-wrap {
            overflow-x: auto;
            padding-bottom: 6px;
        }

        .casier-drawer-cabinet {
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

        .casier-drawer-grid {
            display: flex;
            flex-direction: column;
            gap: 14px;
            align-items: center;
        }

        .casier-drawer-row {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: center;
            width: 100%;
        }

        .casier-drawer-row-label {
            display: inline-flex;
            align-items: center;
            align-self: flex-start;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(51, 65, 85, 0.16);
            color: var(--text-primary);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .casier-drawer-row-grid {
            display: grid;
            gap: 12px;
            align-items: start;
            justify-content: center;
            width: max-content;
        }

        /* =======================================
           VUE "PLATE" (sans tiroirs) : classeur / étagère
           ======================================= */
        .casier-flat-wrap {
            padding: 18px;
            border-radius: 16px;
            border: 2px solid var(--border-color);
            background: var(--bg-muted, var(--bg-primary));
        }
        .casier-flat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--border-color);
        }
        .casier-flat-header h4 {
            margin: 0;
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .casier-flat-header h4::before {
            font-size: 1.25rem;
            line-height: 1;
        }
        .casier-flat-header[data-type="classeur"] h4::before { content: '🗂️'; }
        .casier-flat-header[data-type="etagere"] h4::before { content: '🪜'; }
        .casier-flat-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .casier-flat-chips .meta-chip {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--bg-muted);
            color: var(--text-secondary);
        }
        .casier-flat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 12px;
        }
        @media (max-width: 640px) {
            .casier-flat-grid { grid-template-columns: 1fr; }
        }

        /* ==== DETAIL - tiroir agrandi ==== */
        .tiroir-real-large {
            width: 120px;
            background: linear-gradient(180deg, #ffffff 0%, var(--bg-primary) 100%);
            border: 1px solid var(--border-strong);
            border-radius: 12px;
            padding: 10px 10px 12px;
            margin-bottom: 0;
            position: relative;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.9),
                0 4px 12px rgba(15, 23, 42, 0.08);
        }
        .tiroir-real-large::before {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -1px;
            transform: translateX(-50%);
            width: 44px;
            height: 7px;
            background: linear-gradient(180deg, var(--text-secondary) 0%, #475569 100%);
            border-radius: 0 0 8px 8px;
            box-shadow: 0 3px 5px rgba(0,0,0,0.15);
        }

        .tiroir-real-large-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 10px;
            padding: 0 0 8px;
            border-bottom: 1px dashed var(--border-strong);
            border-radius: 0;
            background: transparent;
            color: inherit;
        }

        .tiroir-large-title {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            width: 100%;
        }
        .tiroir-large-title .tiroir-num {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            font-size: 0.82rem;
        }
        .tiroir-large-title h3 {
            margin: 0;
            font-size: 0.88rem;
            color: #0f172a;
            text-align: center;
        }
        .tiroir-large-title p {
            margin: 0;
            font-size: 0.65rem;
            color: var(--text-secondary);
            text-align: center;
        }

        .tiroir-large-meta {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .tiroir-large-meta .meta-chip,
        .tiroir-large-meta .stat-pill {
            font-size: 0.58rem;
            padding: 3px 5px;
            border-radius: 999px;
            line-height: 1.1;
        }

        .compartiments-grid-real-large {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            justify-content: stretch;
        }

        .compartiment-real-large {
            width: 100%;
            min-height: 46px;
            padding: 6px 4px;
            border-radius: 8px;
            position: relative;
            border: 2px solid;
            background: linear-gradient(180deg, #ffffff, var(--bg-primary));
            transition: all 0.18s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,0.8),
                0 3px 8px rgba(15, 23, 42, 0.05);
        }
        .compartiment-real-large.empty { border-color: var(--border-color); background: linear-gradient(180deg, #ffffff, var(--bg-primary)); }
        .compartiment-real-large.low { border-color: #bfdbfe; background: linear-gradient(180deg, #eff6ff, #dbeafe); }
        .compartiment-real-large.medium { border-color: #fde68a; background: linear-gradient(180deg, #fffbeb, #fef3c7); }
        .compartiment-real-large.full { border-color: #bbf7d0; background: linear-gradient(180deg, #f0fdf4, #dcfce7); }

        .compartiment-real-large:hover {
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.12);
        }

        .compartiment-real-large::after {
            content: '';
            position: absolute;
            left: 8px;
            right: 8px;
            bottom: 6px;
            height: 6px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            filter: blur(2px);
            opacity: 0.45;
        }

        .compartiment-real-large .compartiment-tag {
            font-size: 0.7rem;
            font-weight: 800;
            line-height: 1.15;
            padding: 0;
            border-radius: 0;
            background: transparent;
            position: relative;
            z-index: 1;
            letter-spacing: -0.01em;
        }

        .compartiment-real-large .compartiment-fill-dot {
            top: 6px; right: 6px; width: 7px; height: 7px;
        }

        .compartiment-real-large .compartiment-id-mini,
        .compartiment-real-large .compartiment-quantity,
        .compartiment-real-large .compartiment-component-name,
        .compartiment-real-large .compartiment-actions-real {
            display: none;
        }

        .compartiment-real-large .compartiment-lock {
            position: absolute;
            left: 6px;
            top: 5px;
            font-size: 0.62rem;
            color: var(--text-secondary);
            z-index: 1;
            opacity: 0.9;
        }

        .compartiment-real-large .compartiment-corner {
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: 7px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.85);
        }

        .location-action-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            z-index: 1200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .location-action-backdrop.active {
            display: flex;
        }

        .location-action-modal {
            width: 100%;
            max-width: 420px;
            background: var(--bg-card);
            border-radius: 18px;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            animation: locationModalIn 0.2s ease;
        }

        @keyframes locationModalIn {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .location-action-head {
            padding: 18px 20px 14px;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #eef2ff 100%);
            border-bottom: 1px solid var(--border-color);
        }

        .location-action-head h3 {
            margin: 0;
            font-size: 1.05rem;
            color: #0f172a;
        }

        .location-action-head p {
            margin: 5px 0 0 0;
            font-size: 0.83rem;
            color: var(--text-secondary);
        }

        .location-action-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 12px;
            padding: 9px 14px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 900;
            letter-spacing: 0.02em;
            box-shadow: 0 8px 18px rgba(102, 126, 234, 0.24);
        }

        .location-action-body {
            padding: 18px 20px 20px;
        }

        .location-action-status {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .location-action-status.empty {
            background: var(--bg-primary);
            color: #475569;
            border: 1px solid var(--border-color);
        }

        .location-action-status.used {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .location-action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .location-action-buttons .btn {
            justify-content: center;
            padding: 11px 14px;
        }

        .location-action-close {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }

        .no-locations {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-secondary);
            font-size: 1.1em;
            background: linear-gradient(180deg, var(--bg-primary), var(--bg-muted));
            border: 2px dashed var(--border-strong);
            border-radius: 14px;
        }

        .no-locations h3 {
            color: #475569;
            margin-bottom: 8px;
        }

        @media (max-width: 640px) {
            .stat-grid-summary { grid-template-columns: repeat(2, 1fr); }
            .casiers-real-grid { grid-template-columns: 1fr; }
            .casier-detail-title h2 { font-size: 1.1rem; }
            .tiroir-real-large { width: 100px; }
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
                        <h1>Gestion des Emplacements</h1>
                        <p>Organisez votre stockage avec les casiers, tiroirs et compartiments</p>
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
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <div class="action-buttons">
                <a href="add_location.php" class="btn btn-primary">➕ Ajouter un Emplacement</a>
                <a href="add_location_batch.php" class="btn btn-success">📦 Créer par Grappe</a>
            </div>

            <div class="view-toggle">
                <button class="toggle-btn active" onclick="showCasiersView()">🗂️ Vue Casiers</button>
                <button class="toggle-btn" onclick="showTableView()">📋 Vue Tableau</button>
            </div>

            <?php if (empty($locations)): ?>
                <div class="no-locations">
                    <h3>📭 Aucun emplacement trouvé</h3>
                    <p>Commencez par créer vos premiers emplacements !</p>
                    <div style="margin-top:18px;">
                        <a href="add_location.php" class="btn btn-primary">➕ Ajouter un emplacement</a>
                        <a href="add_location_batch.php" class="btn btn-success">📦 Créer par grappe</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Vue Casiers -->
                <div id="casiers-view">
                    <?php
                    $casiers = [];
                    $first_location_ids = [];
                    foreach ($locations as $location) {
                        $casier = $location['casier'];
                        if (!isset($first_location_ids[$casier])) {
                            $first_location_ids[$casier] = (int)$location['id'];
                        }
                        if (!isset($casiers[$casier])) {
                            $casierStorageMeta = getStorageTypeMeta($location['storage_type'] ?? 'casier', $storageTypes);
                            $logo_path = null;
                            if (!empty($location['logo_path']) && file_exists($location['logo_path'])) {
                                $logo_path = $location['logo_path'];
                            }
                            $casiers[$casier] = [
                                'tiroirs' => [],
                                'total_components' => 0,
                                'total_quantity' => 0,
                                'total_tiroirs' => 0,
                                'total_compartiments' => 0,
                                'storage_type' => $casierStorageMeta['key'],
                                'storage_label' => $casierStorageMeta['label'],
                                'storage_icon' => $casierStorageMeta['icon'],
                                'logo_path' => $logo_path,
                                'first_location_id' => (int)$location['id'],
                            ];
                        } else {
                            if (empty($casiers[$casier]['logo_path']) && !empty($location['logo_path']) && file_exists($location['logo_path'])) {
                                $casiers[$casier]['logo_path'] = $location['logo_path'];
                            }
                        }

                        $tiroir = $location['tiroir'];
                        if (!isset($casiers[$casier]['tiroirs'][$tiroir])) {
                            $casiers[$casier]['tiroirs'][$tiroir] = [];
                            $casiers[$casier]['total_tiroirs']++;
                        }

                        $casiers[$casier]['tiroirs'][$tiroir][] = $location;
                        $casiers[$casier]['total_compartiments']++;
                        $casiers[$casier]['total_components'] += (int)$location['component_count'];
                        $casiers[$casier]['total_quantity'] += (int)$location['total_quantity'];
                    }
                    ksort($casiers);
                    ?>

                    <div class="casiers-real-grid">
                        <?php foreach ($casiers as $casier_letter => $casier_data): ?>
                            <div class="casier-compact-card"
                                 onclick="selectCasier('<?php echo htmlspecialchars($casier_letter); ?>')"
                                 title="Ouvrir <?php echo htmlspecialchars(strtolower($casier_data['storage_label'])); ?> <?php echo htmlspecialchars($casier_letter); ?>">
                                <?php
                                    $casierIsEmpty = (int)$casier_data['total_components'] === 0 && (int)$casier_data['total_quantity'] === 0;
                                    $casierFullLabel = htmlspecialchars($casier_data['storage_label'] . ' ' . $casier_letter, ENT_QUOTES);
                                    if ($casierIsEmpty):
                                ?>
                                    <button type="button"
                                            class="casier-delete-btn compact"
                                            onclick="event.stopPropagation(); deleteEmptyCasier('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo $casierFullLabel; ?>', false);"
                                            title="Supprimer ce <?php echo htmlspecialchars(strtolower($casier_data['storage_label'])); ?> (totalement vide)">
                                        🗑 Supprimer
                                    </button>
                                <?php endif; ?>
                                <div class="casier-compact-top">
                                    <div class="casier-compact-left">
                                        <?php if (!empty($casier_data['logo_path'])): ?>
                                            <div class="casier-real-badge casier-badge-logo casier-badge-clickable"
                                                 onclick="event.stopPropagation(); openTypeModal('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_type'], ENT_QUOTES); ?>', <?php echo (int)$casier_data['first_location_id']; ?>);"
                                                 title="Changer le type ou le logo de <?php echo htmlspecialchars($casier_letter); ?> (cliquez ici)">
                                                <img src="<?php echo htmlspecialchars($casier_data['logo_path']); ?>" alt="<?php echo htmlspecialchars($casier_data['storage_label']); ?>" class="casier-logo-img">
                                                <span class="casier-badge-edit">✎</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="casier-real-badge casier-badge-clickable"
                                                 onclick="event.stopPropagation(); openTypeModal('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_type'], ENT_QUOTES); ?>', <?php echo (int)$casier_data['first_location_id']; ?>);"
                                                 title="Changer le type ou le logo de <?php echo htmlspecialchars($casier_letter); ?> (cliquez ici)">
                                                <?php echo htmlspecialchars($casier_data['storage_icon']); ?>
                                                <span class="casier-badge-edit">✎</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="casier-compact-title">
                                            <h3>
                                                <?php echo htmlspecialchars($casier_data['storage_label'] . ' ' . $casier_letter); ?>
                                                <button type="button"
                                                        class="casier-rename-btn"
                                                        onclick="event.stopPropagation(); renameCasier('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_label'], ENT_QUOTES); ?>');"
                                                        title="Renommer ce <?php echo htmlspecialchars(strtolower($casier_data['storage_label'])); ?>">✎</button>
                                            </h3>
                                            <small>Cliquez pour ouvrir</small>
                                        </div>
                                    </div>
                                    <div class="casier-compact-arrow">→</div>
                                </div>
                                <div class="casier-compact-stats">
                                    <div class="compact-stat tiroirs">
                                        <div class="cs-label"><?php echo in_array($casier_data['storage_type'], ['boite', 'classeur', 'etagere'], true) ? 'Lignes' : 'Tiroirs'; ?></div>
                                        <div class="cs-value"><?php echo $casier_data['total_tiroirs']; ?></div>
                                    </div>
                                    <div class="compact-stat compartiments">
                                        <div class="cs-label">Compartiments</div>
                                        <div class="cs-value"><?php echo $casier_data['total_compartiments']; ?></div>
                                    </div>
                                    <div class="compact-stat pieces">
                                        <div class="cs-label">Pièces</div>
                                        <div class="cs-value"><?php echo $casier_data['total_quantity']; ?></div>
                                    </div>
                                    <div class="compact-stat references">
                                        <div class="cs-label">Références</div>
                                        <div class="cs-value"><?php echo $casier_data['total_components']; ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Détails du casier sélectionné (vue agrandie) -->
                    <?php foreach ($casiers as $casier_letter => $casier_data):
                        $casierIsEmpty = (int)$casier_data['total_components'] === 0 && (int)$casier_data['total_quantity'] === 0;
                        $casierFullLabel = htmlspecialchars($casier_data['storage_label'] . ' ' . $casier_letter, ENT_QUOTES);
                    ?>
                        <div id="casier-<?php echo htmlspecialchars($casier_letter); ?>" class="casier-detail-view">
                            <div class="casier-detail-header">
                                <button class="back-btn-real" onclick="deselectCasier()">← Retour</button>
                                <div class="casier-detail-title">
                                    <?php if (!empty($casier_data['logo_path'])): ?>
                                        <div class="casier-real-badge casier-badge-logo casier-badge-large casier-badge-clickable"
                                             onclick="openTypeModal('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_type'], ENT_QUOTES); ?>', <?php echo (int)$casier_data['first_location_id']; ?>);"
                                             title="Changer le type ou le logo de <?php echo htmlspecialchars($casier_letter); ?> (cliquez ici)">
                                            <img src="<?php echo htmlspecialchars($casier_data['logo_path']); ?>" alt="<?php echo htmlspecialchars($casier_data['storage_label']); ?>" class="casier-logo-img">
                                            <span class="casier-badge-edit">✎</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="casier-real-badge casier-badge-clickable"
                                             onclick="openTypeModal('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_type'], ENT_QUOTES); ?>', <?php echo (int)$casier_data['first_location_id']; ?>);"
                                             title="Changer le type ou le logo de <?php echo htmlspecialchars($casier_letter); ?> (cliquez ici)">
                                            <?php echo htmlspecialchars($casier_data['storage_icon']); ?>
                                            <span class="casier-badge-edit">✎</span>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h2>
                                            <?php echo htmlspecialchars($casier_data['storage_label'] . ' ' . $casier_letter); ?>
                                            <button type="button"
                                                    class="casier-rename-btn"
                                                    onclick="event.stopPropagation(); renameCasier('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($casier_data['storage_label'], ENT_QUOTES); ?>');"
                                                    title="Renommer ce <?php echo htmlspecialchars(strtolower($casier_data['storage_label'])); ?>">✎ Renommer</button>
                                        </h2>
                                        <small>Vue détaillée — cliquez sur un compartiment pour le modifier ou le supprimer</small>
                                    </div>
                                </div>
                                <div class="casier-detail-header-right">
                                    <?php if ($casierIsEmpty): ?>
                                        <button type="button"
                                                class="casier-delete-btn"
                                                onclick="deleteEmptyCasier('<?php echo htmlspecialchars($casier_letter, ENT_QUOTES); ?>', '<?php echo $casierFullLabel; ?>', true);"
                                                title="Supprimer ce <?php echo htmlspecialchars(strtolower($casier_data['storage_label'])); ?> (totalement vide)">
                                            🗑 Supprimer ce conteneur
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="legend-bar">
                                <span class="legend-item"><span class="legend-dot empty"></span> Vide</span>
                                <span class="legend-item"><span class="legend-dot low"></span> Peu rempli (≤ 5)</span>
                                <span class="legend-item"><span class="legend-dot medium"></span> Moyennement rempli (≤ 25)</span>
                                <span class="legend-item"><span class="legend-dot full"></span> Bien rempli (> 25)</span>
                            </div>

                            <div class="stat-grid-summary">
                                <div class="summary-card tiroirs">
                                    <div class="summary-label"><?php echo in_array($casier_data['storage_type'], ['boite', 'classeur', 'etagere'], true) ? 'Lignes' : 'Tiroirs'; ?></div>
                                    <div class="summary-value"><?php echo $casier_data['total_tiroirs']; ?></div>
                                </div>
                                <div class="summary-card compartiments">
                                    <div class="summary-label">Compartiments</div>
                                    <div class="summary-value"><?php echo $casier_data['total_compartiments']; ?></div>
                                </div>
                                <div class="summary-card pieces">
                                    <div class="summary-label">Pièces totales</div>
                                    <div class="summary-value"><?php echo $casier_data['total_quantity']; ?></div>
                                </div>
                                <div class="summary-card references">
                                    <div class="summary-label">Références</div>
                                    <div class="summary-value"><?php echo $casier_data['total_components']; ?></div>
                                </div>
                            </div>

                            <?php
                            $isFlatView = in_array($casier_data['storage_type'], ['classeur', 'etagere', 'boite'], true);
                            if ($isFlatView):
                                $flatLocations = [];
                                ksort($casier_data['tiroirs']);
                                foreach ($casier_data['tiroirs'] as $tiroir_number => $tiroir_locations) {
                                    usort($tiroir_locations, fn($a, $b) => (int)$a['compartiment'] - (int)$b['compartiment']);
                                    foreach ($tiroir_locations as $tl) $flatLocations[] = ['tiroir' => $tiroir_number, 'loc' => $tl];
                                }
                                usort($flatLocations, function($a, $b) {
                                    $dt = (int)$a['tiroir'] - (int)$b['tiroir'];
                                    if ($dt !== 0) return $dt;
                                    return (int)$a['loc']['compartiment'] - (int)$b['loc']['compartiment'];
                                });
                            ?>
                            <div class="casier-flat-wrap">
                                <div class="casier-flat-header" data-type="<?php echo htmlspecialchars($casier_data['storage_type']); ?>">
                                    <h4><?php echo htmlspecialchars($casier_data['storage_label'] . ' ' . $casier_letter); ?> — emplacements (<?php echo count($flatLocations); ?>)</h4>
                                    <div class="casier-flat-chips">
                                        <span class="meta-chip">📦 <?php echo $casier_data['total_compartiments']; ?> emplacements</span>
                                        <span class="meta-chip">🧩 <?php echo $casier_data['total_components']; ?> références</span>
                                        <span class="meta-chip">⚙️ <?php echo $casier_data['total_quantity']; ?> pièces</span>
                                    </div>
                                </div>
                                <div class="casier-flat-grid">
                            <?php
                            foreach ($flatLocations as $flatEntry):
                                $tiroir_number = $flatEntry['tiroir'];
                                $loc = $flatEntry['loc'];
                                $fillCls = getFillClass((int)$loc['total_quantity'], (int)$loc['component_count']);
                                $label = htmlspecialchars($casier_letter . '-' . $loc['compartiment']);
                            ?>
                                    <div class="compartiment-real-large <?php echo $fillCls; ?>"
                                         title="<?php echo $label; ?>"
                                         onclick="openLocationActions(<?php echo (int)$loc['id']; ?>, '<?php echo htmlspecialchars($label, ENT_QUOTES); ?>', <?php echo (int)$loc['component_count'] === 0 ? 'true' : 'false'; ?>)">
                                        <div class="compartiment-fill-dot"></div>
                                        <?php if ((int)$loc['component_count'] > 0): ?>
                                            <div class="compartiment-lock" title="Contient des composants">🔒</div>
                                        <?php endif; ?>
                                        <div class="compartiment-tag"><?php echo $label; ?></div>
                                        <div class="compartiment-id-mini">ID <?php echo (int)$loc['id']; ?></div>
                                        <div class="compartiment-quantity">
                                            <?php echo (int)$loc['total_quantity']; ?>
                                            <span class="unit"> pièces</span>
                                        </div>
                                        <div class="compartiment-component-name">
                                            <?php if (!empty($loc['component_first_name'])): ?>
                                                <?php echo htmlspecialchars($loc['component_first_name']); ?>
                                                <?php if ((int)$loc['component_total_refs'] > 1): ?>
                                                    <span class="extra">(+<?php echo (int)$loc['component_total_refs'] - 1; ?>)</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                (vide)
                                            <?php endif; ?>
                                        </div>
                                        <div class="compartiment-actions-real" onclick="event.stopPropagation()">
                                            <a href="edit_location.php?id=<?php echo (int)$loc['id']; ?>" class="icon-btn edit" title="Modifier">✎</a>
                                            <?php if ((int)$loc['component_count'] === 0): ?>
                                                <a href="delete_location.php?id=<?php echo (int)$loc['id']; ?>"
                                                   class="icon-btn del"
                                                   title="Supprimer"
                                                   onclick="return confirm('Supprimer l\'emplacement <?php echo $label; ?> ?')">🗑</a>
                                            <?php else: ?>
                                                <span class="icon-btn del disabled" title="Contient des composants">🔒</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="compartiment-corner"></div>
                                    </div>
                            <?php endforeach; ?>
                                </div>
                            </div>
                            <?php else: ?>

                            <div class="casier-drawer-wrap">
                                <div class="casier-drawer-cabinet">
                                    <div class="casier-drawer-grid">
                            <?php
                            ksort($casier_data['tiroirs']);
                            $tiroirs_par_dizaine = [];
                            foreach ($casier_data['tiroirs'] as $tiroir_number => $tiroir_locations) {
                                $dizaine = ((int) floor(((int)$tiroir_number) / 10)) * 10;
                                $tiroirs_par_dizaine[$dizaine][$tiroir_number] = $tiroir_locations;
                            }
                            ksort($tiroirs_par_dizaine);
                            foreach ($tiroirs_par_dizaine as $dizaine => $tiroirs_ligne):
                            ?>
                                    <div class="casier-drawer-row">
                                        <div class="casier-drawer-row-label">Ligne <?php echo (int)$dizaine; ?></div>
                                        <div class="casier-drawer-row-grid" style="grid-template-columns: repeat(<?php echo count($tiroirs_ligne); ?>, 120px);">
                            <?php
                            foreach ($tiroirs_ligne as $tiroir_number => $tiroir_locations):
                                usort($tiroir_locations, fn($a, $b) => (int)$a['compartiment'] - (int)$b['compartiment']);
                                $tiroir_qty = 0;
                                $tiroir_comps = 0;
                                foreach ($tiroir_locations as $tl) {
                                    $tiroir_qty += (int)$tl['total_quantity'];
                                    $tiroir_comps += (int)$tl['component_count'];
                                }
                                $tiroir_id = $tiroir_ids[$casier_letter . '-' . $tiroir_number] ?? null;
                            ?>
                            <div class="tiroir-real-large">
                                <div class="tiroir-real-large-header">
                                    <div class="tiroir-large-title">
                                        <span class="tiroir-num"><?php echo (int)$tiroir_number; ?></span>
                                        <div>
                                            <h3>Tiroir <?php echo htmlspecialchars($tiroir_number); ?></h3>
                                            <p><?php echo count($tiroir_locations); ?> cases cliquables</p>
                                        </div>
                                    </div>
                                    <div class="tiroir-large-meta">
                                        <?php if ($tiroir_id): ?>
                                            <span class="meta-chip id-chip">Tiroir ID <?php echo (int)$tiroir_id; ?></span>
                                        <?php endif; ?>
                                        <span class="meta-chip qty-chip"><?php echo count($tiroir_locations); ?> compartiments</span>
                                        <span class="stat-pill primary" style="background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe;">
                                            <?php echo $tiroir_comps; ?> références
                                        </span>
                                        <span class="stat-pill success" style="background:#dcfce7;color:#166534;border-color:#bbf7d0;">
                                            <?php echo $tiroir_qty; ?> pièces
                                        </span>
                                    </div>
                                </div>
                                <div class="compartiments-grid-real-large">
                                    <?php foreach ($tiroir_locations as $loc):
                                        $fillCls = getFillClass((int)$loc['total_quantity'], (int)$loc['component_count']);
                                        $label = htmlspecialchars($casier_letter . $tiroir_number . '-' . $loc['compartiment']);
                                        $viewLink = 'components.php?location_id=' . (int)$loc['id'];
                                    ?>
                                    <div class="compartiment-real-large <?php echo $fillCls; ?>"
                                         title="<?php echo $label; ?>"
                                         onclick="openLocationActions(<?php echo (int)$loc['id']; ?>, '<?php echo htmlspecialchars($label, ENT_QUOTES); ?>', <?php echo (int)$loc['component_count'] === 0 ? 'true' : 'false'; ?>)">
                                        <div class="compartiment-fill-dot"></div>
                                        <?php if ((int)$loc['component_count'] > 0): ?>
                                            <div class="compartiment-lock" title="Contient des composants">🔒</div>
                                        <?php endif; ?>
                                        <div class="compartiment-tag"><?php echo $label; ?></div>
                                        <div class="compartiment-id-mini">ID <?php echo (int)$loc['id']; ?></div>
                                        <div class="compartiment-quantity">
                                            <?php echo (int)$loc['total_quantity']; ?>
                                            <span class="unit"> pièces</span>
                                        </div>
                                        <div class="compartiment-component-name">
                                            <?php if (!empty($loc['component_first_name'])): ?>
                                                <?php echo htmlspecialchars($loc['component_first_name']); ?>
                                                <?php if ((int)$loc['component_total_refs'] > 1): ?>
                                                    <span class="extra">(+<?php echo (int)$loc['component_total_refs'] - 1; ?>)</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                (vide)
                                            <?php endif; ?>
                                        </div>
                                        <div class="compartiment-actions-real" onclick="event.stopPropagation()">
                                            <a href="edit_location.php?id=<?php echo (int)$loc['id']; ?>" class="icon-btn edit" title="Modifier">✎</a>
                                            <?php if ((int)$loc['component_count'] === 0): ?>
                                                <a href="delete_location.php?id=<?php echo (int)$loc['id']; ?>"
                                                   class="icon-btn del"
                                                   title="Supprimer"
                                                   onclick="return confirm('Supprimer l\'emplacement <?php echo $label; ?> ?')">🗑</a>
                                            <?php else: ?>
                                                <span class="icon-btn del disabled" title="Contient des composants">🔒</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="compartiment-corner"></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                                        </div>
                                    </div>
                            <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Vue Tableau -->
                <div id="table-view" style="display:none;">
                    <table class="locations-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Casier</th>
                                <th>Tiroir</th>
                                <th>Compartiment</th>
                                <th>Description</th>
                                <th>Références</th>
                                <th>Pièces</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locations as $loc):
                                $fillCls = getFillClass((int)$loc['total_quantity'], (int)$loc['component_count']);
                                $isFlatLocation = in_array($loc['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                $locationCode = $isFlatLocation
                                    ? $loc['casier'] . '-' . $loc['compartiment']
                                    : $loc['casier'] . $loc['tiroir'] . '-' . $loc['compartiment'];
                                $storageMeta = getStorageTypeMeta($loc['storage_type'] ?? 'casier', $storageTypes);
                            ?>
                            <tr>
                                <td>
                                    <span class="location-code">
                                        <?php echo htmlspecialchars($locationCode); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                        <span class="casier-real-badge" style="width:28px;height:28px;border-radius:6px;font-size:0.85rem;"><?php echo htmlspecialchars($loc['casier']); ?></span>
                                        <strong><?php echo htmlspecialchars($storageMeta['label'] . ' ' . $loc['casier']); ?></strong>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isFlatLocation): ?>
                                        <span style="color:var(--text-secondary);">—</span>
                                    <?php else: ?>
                                        <span style="display:inline-flex;align-items:center;gap:6px;">
                                            <span class="tiroir-num" style="width:26px;height:26px;border-radius:6px;font-size:0.78rem;"><?php echo htmlspecialchars($loc['tiroir']); ?></span>
                                            Tiroir <?php echo htmlspecialchars($loc['tiroir']); ?>
                                            <br><small style="color:var(--text-secondary);font-size:0.72rem;">ID <?php echo (int)$loc['id']; ?></small>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($loc['compartiment']); ?></strong></td>
                                <td style="font-size:0.85rem;color:#475569;"><?php echo htmlspecialchars($loc['description'] ?? '—'); ?></td>
                                <td>
                                    <span class="component-count">
                                        <?php echo (int)$loc['component_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight:800;color:<?php
                                        switch($fillCls) {
                                            case 'empty': echo '#94a3b8'; break;
                                            case 'low': echo '#2563eb'; break;
                                            case 'medium': echo '#d97706'; break;
                                            case 'full': echo '#16a34a'; break;
                                        }
                                    ?>;">
                                        <?php echo (int)$loc['total_quantity']; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="components.php?location_id=<?php echo (int)$loc['id']; ?>" class="btn btn-primary btn-small" style="background:linear-gradient(135deg,#3b82f6,#2563eb);padding:4px 9px;font-size:0.75rem;border-radius:6px;">👁 Voir</a>
                                    <a href="edit_location.php?id=<?php echo (int)$loc['id']; ?>" class="btn btn-warning btn-small" style="padding:4px 9px;font-size:0.75rem;border-radius:6px;">✏️ Modifier</a>
                                    <?php if ((int)$loc['component_count'] === 0): ?>
                                        <a href="delete_location.php?id=<?php echo (int)$loc['id']; ?>"
                                           class="btn btn-danger btn-small"
                                           style="padding:4px 9px;font-size:0.75rem;border-radius:6px;"
                                           onclick="return confirm('Supprimer ?')">🗑️</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="locationActionBackdrop" class="location-action-backdrop">
            <div class="location-action-modal">
                <div class="location-action-head">
                    <h3>Actions du compartiment</h3>
                    <p>Sélectionne l’action à effectuer sur cet emplacement.</p>
                    <div id="locationActionCode" class="location-action-code">A1-1</div>
                </div>
                <div class="location-action-body">
                    <div id="locationActionStatus" class="location-action-status empty">Compartiment vide : modification et suppression disponibles.</div>
                    <div class="location-action-buttons">
                        <a id="locationEditLink" href="#" class="btn btn-warning">✏️ Modifier</a>
                        <a id="locationDeleteLink" href="#" class="btn btn-danger">🗑️ Supprimer</a>
                    </div>
                    <div class="location-action-close">
                        <button type="button" class="btn btn-ghost" onclick="closeLocationActions()">Fermer</button>
                    </div>
                </div>
            </div>
        </div>

    <script>
        const locationActionBackdrop = document.getElementById('locationActionBackdrop');
        const locationActionCode = document.getElementById('locationActionCode');
        const locationActionStatus = document.getElementById('locationActionStatus');
        const locationEditLink = document.getElementById('locationEditLink');
        const locationDeleteLink = document.getElementById('locationDeleteLink');

        function showCasiersView() {
            document.getElementById('casiers-view').style.display = 'block';
            document.getElementById('table-view').style.display = 'none';
            const tg = document.querySelectorAll('.toggle-btn');
            if (tg[0]) tg[0].classList.add('active');
            if (tg[1]) tg[1].classList.remove('active');

            document.querySelectorAll('.casier-detail-view').forEach(d => d.classList.remove('active'));
            const grid = document.querySelector('.casiers-real-grid');
            if (grid) grid.style.display = '';
        }

        function showTableView() {
            document.getElementById('casiers-view').style.display = 'none';
            document.getElementById('table-view').style.display = 'block';
            const tg = document.querySelectorAll('.toggle-btn');
            if (tg[0]) tg[0].classList.remove('active');
            if (tg[1]) tg[1].classList.add('active');
        }

        function selectCasier(casierLetter) {
            const grid = document.querySelector('.casiers-real-grid');
            if (grid) grid.style.display = 'none';

            document.querySelectorAll('.casier-detail-view').forEach(d => d.classList.remove('active'));

            const target = document.getElementById('casier-' + casierLetter);
            if (target) {
                target.classList.add('active');
                window.scrollTo({ top: target.offsetTop - 24, behavior: 'smooth' });
            }
        }

        function deselectCasier() {
            const grid = document.querySelector('.casiers-real-grid');
            if (grid) {
                grid.style.display = '';
                window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' });
            }
            document.querySelectorAll('.casier-detail-view').forEach(d => d.classList.remove('active'));
        }

        function renameCasier(casierLetter, storageLabel) {
            const currentName = casierLetter;
            const newName = prompt(
                "Renommer " + storageLabel + " '" + currentName + "' en :",
                currentName
            );
            if (newName === null) return;

            const trimmed = newName.trim();
            if (trimmed === '') {
                alert("Le nom ne peut pas être vide.");
                return;
            }
            if (trimmed === currentName) return;

            const fd = new FormData();
            fd.append('action', 'rename_casier');
            fd.append('casier', currentName);
            fd.append('new_name', trimmed);

            fetch(window.location.href, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) {
                        alert("❌ Renommage impossible : " + (data && data.error ? data.error : "erreur inconnue"));
                        return;
                    }
                    // Recharge la page pour mettre à jour toutes les références (ids, titres, etc.)
                    window.location.reload();
                })
                .catch(err => {
                    alert("❌ Erreur réseau : " + err.message);
                });
        }

        function deleteEmptyCasier(casierLetter, casierLabel, fromDetailedView) {
            const msg = "⚠️ Êtes-vous SÛR de vouloir SUPPRIMER DÉFINITIVEMENT \"" + casierLabel + "\" ?\n\n"
                      + "Tous ses emplacements vides seront effacés.\n"
                      + "Cette action est IRRÉVERSIBLE.";
            if (!confirm(msg)) return;

            const fd = new FormData();
            fd.append('action', 'delete_empty_casier');
            fd.append('casier', casierLetter);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) {
                        alert("❌ Suppression impossible : " + (data && data.error ? data.error : "erreur inconnue"));
                        return;
                    }
                    // 1) Retirer la vue détaillée (div#casier-XX)
                    const detail = document.getElementById('casier-' + casierLetter);
                    if (detail) detail.remove();

                    // 2) Retirer la carte compacte associée
                    const cards = document.querySelectorAll('.casier-compact-card');
                    for (const c of cards) {
                        const oc = c.getAttribute('onclick') || '';
                        if (oc.indexOf("selectCasier('" + casierLetter + "')") !== -1
                            || oc.indexOf('selectCasier("' + casierLetter + '")') !== -1) {
                            c.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                            c.style.opacity = '0';
                            c.style.transform = 'scale(0.92)';
                            setTimeout(() => c.remove(), 260);
                            break;
                        }
                    }

                    // 3) Si on était en vue détaillée → revenir en grille
                    if (fromDetailedView) deselectCasier();

                    // 4) Petit feedback
                    alert("✅ " + casierLabel + " a été supprimé avec succès (" + data.deleted + " emplacement(s)).");
                })
                .catch(err => {
                    console.error(err);
                    alert("❌ Erreur réseau lors de la suppression.");
                });
        }

        function openLocationActions(locationId, label, canDelete) {
            locationActionCode.textContent = label;
            locationEditLink.href = 'edit_location.php?id=' + locationId;

            if (canDelete) {
                locationActionStatus.className = 'location-action-status empty';
                locationActionStatus.textContent = 'Compartiment vide : modification et suppression disponibles.';
                locationDeleteLink.href = 'delete_location.php?id=' + locationId;
                locationDeleteLink.classList.remove('btn-ghost');
                locationDeleteLink.classList.add('btn-danger');
                locationDeleteLink.style.pointerEvents = '';
                locationDeleteLink.style.opacity = '';
                locationDeleteLink.onclick = function() {
                    return confirm('Supprimer l’emplacement ' + label + ' ?');
                };
            } else {
                locationActionStatus.className = 'location-action-status used';
                locationActionStatus.textContent = 'Compartiment utilisé : suppression verrouillée tant qu’il contient des composants.';
                locationDeleteLink.href = '#';
                locationDeleteLink.classList.remove('btn-danger');
                locationDeleteLink.classList.add('btn-ghost');
                locationDeleteLink.style.pointerEvents = 'none';
                locationDeleteLink.style.opacity = '0.6';
                locationDeleteLink.onclick = null;
            }

            locationActionBackdrop.classList.add('active');
        }

        function closeLocationActions() {
            locationActionBackdrop.classList.remove('active');
        }

        if (locationActionBackdrop) {
            locationActionBackdrop.addEventListener('click', function(e) {
                if (e.target === locationActionBackdrop) {
                    closeLocationActions();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeLocationActions();
            }
        });
    </script>

    <!-- Modal changement rapide type/logo -->
    <div class="type-modal-overlay" id="typeModalOverlay" role="dialog" aria-modal="true">
        <div class="type-modal" id="typeModal">
            <div class="type-modal-header">
                <h3>⚙️ Type & logo — <span class="casier-chip" id="typeModalChip">?</span></h3>
                <button class="type-modal-close" id="typeModalClose" title="Fermer">×</button>
            </div>

            <div class="type-modal-label">Changer le type (appliqué à TOUT le casier)</div>
            <div class="type-modal-grid" id="typeModalGrid">
                <?php foreach ($storageTypes as $k => $meta): ?>
                    <button class="type-btn" data-type="<?=htmlspecialchars($k)?>" type="button">
                        <span class="type-icon"><?=htmlspecialchars($meta['icon'])?></span>
                        <span class="type-name"><?=htmlspecialchars($meta['label'])?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="type-modal-divider"></div>

            <div class="type-modal-actions">
                <a class="type-edit-link" id="typeModalEditLink" href="#">
                    🖼️ Définir un logo (ouvrir l'édition complète)
                </a>
            </div>

            <div class="type-status" id="typeModalStatus" role="status" aria-live="polite"></div>
        </div>
    </div>

    <script>
    (function() {
        const STORAGE_META = <?=json_encode($storageTypes)?>;
        const overlay = document.getElementById('typeModalOverlay');
        const closeBtn = document.getElementById('typeModalClose');
        const chip = document.getElementById('typeModalChip');
        const grid = document.getElementById('typeModalGrid');
        const editLink = document.getElementById('typeModalEditLink');
        const status = document.getElementById('typeModalStatus');

        let currentCasier = null;
        let currentType = null;

        function setStatus(kind, message) {
            status.className = 'type-status ' + kind;
            status.textContent = message || '';
        }
        function clearStatus() {
            status.className = 'type-status';
            status.textContent = '';
        }

        function highlightActive() {
            grid.querySelectorAll('.type-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.type === currentType);
                btn.disabled = false;
            });
        }

        function updateBadgesForCasier(casierLetter, newIcon, newLabel) {
            const hasLogoBadge = (badge) => badge.classList.contains('casier-badge-logo');

            document.querySelectorAll('.casier-real-badge').forEach(badge => {
                let title = badge.getAttribute('title') || '';
                let isThisCasier = false;

                if (title.includes('Type et logo de ' + casierLetter) ||
                    title.includes('de ' + casierLetter + ' (') ||
                    title.includes(' de ' + casierLetter) ||
                    title.includes(' de ' + casierLetter + ' ')) {
                    isThisCasier = true;
                }
                if (!isThisCasier) {
                    const onclick = (badge.getAttribute('onclick') || '');
                    if (onclick.includes("'" + casierLetter + "'")) isThisCasier = true;
                }
                if (!isThisCasier) {
                    const parentCard = badge.closest('.casier-compact-card');
                    if (parentCard && parentCard.onclick) {
                        const onStr = parentCard.onclick.toString();
                        if (onStr.includes("'" + casierLetter + "'")) isThisCasier = true;
                    }
                    const parentView = badge.closest('.casier-detail-view');
                    if (parentView && parentView.id === 'casier-' + casierLetter) isThisCasier = true;
                }
                if (!isThisCasier) return;

                if (!hasLogoBadge(badge)) {
                    badge.textContent = '';
                    badge.appendChild(document.createTextNode(newIcon));
                    const edit = document.createElement('span');
                    edit.className = 'casier-badge-edit';
                    edit.textContent = '✎';
                    badge.appendChild(edit);
                } else {
                    const letterSpan = badge.querySelector('.casier-logo-letter');
                    if (letterSpan) letterSpan.textContent = casierLetter;
                }
            });

            document.querySelectorAll('h3, h2').forEach(h => {
                const parentCard = h.closest('.casier-compact-card');
                const parentView = h.closest('.casier-detail-view');
                let isThisCasier = false;
                if (parentCard && parentCard.onclick && parentCard.onclick.toString().includes("'" + casierLetter + "'")) isThisCasier = true;
                if (parentView && parentView.id === 'casier-' + casierLetter) isThisCasier = true;
                if (!isThisCasier) return;

                const text = (h.textContent || '').trim();
                if (/^[A-Z][a-zàâçéèêëîïôûùüÿñæœ]+ [A-Z]$/.test(text)) {
                    h.textContent = newLabel + ' ' + casierLetter;
                }
            });
        }

        window.openTypeModal = function(casierLetter, activeType, firstLocationId) {
            currentCasier = casierLetter;
            currentType = activeType;
            chip.textContent = casierLetter;
            editLink.href = 'edit_location.php?id=' + encodeURIComponent(firstLocationId);
            highlightActive();
            clearStatus();
            overlay.classList.add('visible');
        };

        function closeModal() {
            overlay.classList.remove('visible');
            currentCasier = null;
            currentType = null;
        }

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('visible')) closeModal();
        });

        grid.addEventListener('click', async (e) => {
            const btn = e.target.closest('.type-btn');
            if (!btn || !currentCasier) return;
            const newType = btn.dataset.type;
            if (newType === currentType) return;

            grid.querySelectorAll('.type-btn').forEach(b => b.disabled = true);
            setStatus('loading', 'Application en cours…');

            try {
                const fd = new FormData();
                fd.append('action', 'set_casier_type');
                fd.append('casier', currentCasier);
                fd.append('storage_type', newType);

                const res = await fetch(window.location.href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                });
                const data = await res.json();
                if (data && data.ok) {
                    currentType = newType;
                    highlightActive();
                    updateBadgesForCasier(currentCasier, data.icon || STORAGE_META[newType].icon,
                                          data.label || STORAGE_META[newType].label);
                    setStatus('success', '✓ Type changé : ' + (data.label || STORAGE_META[newType].label));
                    setTimeout(closeModal, 900);
                } else {
                    setStatus('error', '✗ Erreur : ' + (data?.error || 'Inconnue'));
                    highlightActive();
                }
            } catch (err) {
                setStatus('error', '✗ Erreur réseau : ' + err.message);
                highlightActive();
            }
        });
    })();
    </script>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>
    </div>
</body>
</html>
