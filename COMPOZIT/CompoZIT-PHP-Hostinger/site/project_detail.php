<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Récupérer l'ID du projet
$project_id = (int)($_GET['id'] ?? 0);
if ($project_id <= 0) {
    header('Location: projects.php?error=invalid_project');
    exit();
}

// Connexion à la base de données
try {
    $pdo = getConnection();
} catch(PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_component':
                $component_id = (int)($_POST['component_id'] ?? 0);
                $quantity_needed = (int)($_POST['quantity_needed'] ?? 1);
                $notes = trim($_POST['notes'] ?? '');
                
                if ($component_id > 0 && $quantity_needed > 0) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO project_components (project_id, component_id, quantity_needed, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity_needed = quantity_needed + VALUES(quantity_needed), notes = VALUES(notes)");
                        $stmt->execute([$project_id, $component_id, $quantity_needed, $notes]);
                        header("Location: project_detail.php?id=$project_id&tab=components&success=component_added");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de l'ajout du composant : " . $e->getMessage();
                    }
                } else {
                    $error = "Veuillez sélectionner un composant et une quantité valide.";
                }
                break;
                
            case 'remove_component':
                $pc_id = (int)($_POST['pc_id'] ?? 0);
                if ($pc_id > 0) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM project_components WHERE id = ? AND project_id = ?");
                        $stmt->execute([$pc_id, $project_id]);
                        header("Location: project_detail.php?id=$project_id&tab=components&success=component_removed#components");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de la suppression : " . $e->getMessage();
                    }
                }
                break;
                
            case 'update_quantity_used':
                $pc_id = (int)($_POST['pc_id'] ?? 0);
                $quantity_used = (int)($_POST['quantity_used'] ?? 0);
                if ($pc_id > 0 && $quantity_used >= 0) {
                    try {
                        $stmt = $pdo->prepare("UPDATE project_components SET quantity_used = ? WHERE id = ? AND project_id = ?");
                        $stmt->execute([$quantity_used, $pc_id, $project_id]);
                        // Détecter l'onglet source via le referer ou un paramètre
                        $redirect_tab = 'overview'; // Par défaut
                        if (isset($_POST['source_tab'])) {
                            $redirect_tab = $_POST['source_tab'];
                        } elseif (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'tab=components') !== false) {
                            $redirect_tab = 'components';
                        }
                        header("Location: project_detail.php?id=$project_id&tab=$redirect_tab&success=quantity_updated");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de la mise à jour : " . $e->getMessage();
                    }
                }
                break;
                
            case 'add_project_item':
                $type = $_POST['item_type'];
                $name = trim($_POST['item_name']);
                $description = trim($_POST['item_description']);
                $quantity = floatval($_POST['item_quantity']);
                $unit = trim($_POST['item_unit']);
                $unit_price = floatval($_POST['item_unit_price']);
                
                if (empty($name) || $quantity <= 0 || $unit_price < 0) {
                    $error = "Veuillez remplir tous les champs obligatoires avec des valeurs valides.";
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO project_items (project_id, type, name, description, quantity, unit, unit_price)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$project_id, $type, $name, $description, $quantity, $unit, $unit_price]);
                        
                        header("Location: project_detail.php?id=$project_id&tab=materials&success=item_added#materials");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de l'ajout : " . $e->getMessage();
                    }
                }
                break;
                
            case 'remove_project_item':
                $item_id = intval($_POST['item_id']);
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM project_items WHERE id = ? AND project_id = ?");
                    $stmt->execute([$item_id, $project_id]);
                    
                    header("Location: project_detail.php?id=$project_id&tab=materials&success=item_removed#materials");
                    exit();
                } catch (PDOException $e) {
                    $error = "Erreur lors de la suppression : " . $e->getMessage();
                }
                break;
                
            case 'update_item_status':
                $item_id = intval($_POST['item_id']);
                $status = $_POST['item_status'];
                
                try {
                    $stmt = $pdo->prepare("UPDATE project_items SET status = ? WHERE id = ? AND project_id = ?");
                    $stmt->execute([$status, $item_id, $project_id]);
                    
                    header("Location: project_detail.php?id=$project_id&tab=materials&success=status_updated");
                    exit();
                } catch (PDOException $e) {
                    $error = "Erreur lors de la mise à jour : " . $e->getMessage();
                }
                break;
                
            case 'update_progress':
                $item_id = intval($_POST['item_id']);
                $progress_change = intval($_POST['progress_change']);
                
                try {
                    // Récupérer les informations de l'élément
                    $stmt = $pdo->prepare("SELECT quantity, quantity_completed, unit FROM project_items WHERE id = ? AND project_id = ?");
                    $stmt->execute([$item_id, $project_id]);
                    $item = $stmt->fetch();
                    
                    if ($item) {
                        $total_quantity = floatval($item['quantity']);
                        $current_completed = floatval($item['quantity_completed'] ?? 0);
                        $unit = strtolower(trim($item['unit']));
                        
                        // Déterminer l'incrément selon l'unité
                        $increment = 1; // Par défaut pour les pièces
                        if (strpos($unit, 'heure') !== false || strpos($unit, 'h') !== false) {
                            $increment = 0.5; // Demi-heure pour les heures
                        }
                        
                        // Calculer la nouvelle quantité complétée
                        if ($progress_change > 0) {
                            $new_completed = min($total_quantity, $current_completed + $increment);
                        } else {
                            $new_completed = max(0, $current_completed - $increment);
                        }
                        
                        // Calculer le nouveau pourcentage
                        $new_progress = $total_quantity > 0 ? ($new_completed / $total_quantity) * 100 : 0;
                        
                        // Déterminer le nouveau statut basé sur la progression
                        $new_status = 'En attente';
                        if ($new_progress >= 100) {
                            $new_status = 'Terminé';
                        } elseif ($new_progress > 0) {
                            $new_status = 'En cours';
                        }
                        
                        // Mettre à jour la quantité complétée et le statut
                        $stmt = $pdo->prepare("UPDATE project_items SET quantity_completed = ?, status = ? WHERE id = ? AND project_id = ?");
                        $stmt->execute([$new_completed, $new_status, $item_id, $project_id]);
                    }
                    
                    header("Location: project_detail.php?id=$project_id&tab=materials&success=progress_updated");
                    exit();
                } catch (PDOException $e) {
                    $error = "Erreur lors de la mise à jour de la progression : " . $e->getMessage();
                }
                break;
                
            case 'edit_project_item':
                $item_id = intval($_POST['item_id']);
                $type = $_POST['item_type'];
                $name = trim($_POST['item_name']);
                $description = trim($_POST['item_description']);
                $quantity = floatval($_POST['item_quantity']);
                $unit = trim($_POST['item_unit']);
                $unit_price = floatval($_POST['item_unit_price']);
                
                if ($item_id > 0 && !empty($name) && $quantity > 0 && $unit_price >= 0) {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE project_items 
                            SET type = ?, name = ?, description = ?, quantity = ?, unit = ?, unit_price = ?
                            WHERE id = ? AND project_id = ?
                        ");
                        $stmt->execute([$type, $name, $description, $quantity, $unit, $unit_price, $item_id, $project_id]);
                        
                        header("Location: project_detail.php?id=$project_id&tab=materials&success=item_updated#materials");
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de la modification : " . $e->getMessage();
                    }
                } else {
                    $error = "Veuillez remplir tous les champs obligatoires avec des valeurs valides.";
                }
                break;
                
            case 'rename_file':
                $file_id = intval($_POST['file_id']);
                $new_name = trim($_POST['new_name']);
                
                if ($file_id > 0 && !empty($new_name)) {
                    try {
                        // Vérifier que le fichier appartient au projet
                        $stmt = $pdo->prepare("SELECT id FROM project_files WHERE id = ? AND project_id = ?");
                        $stmt->execute([$file_id, $project_id]);
                        
                        if ($stmt->fetch()) {
                            // Mettre à jour le nom d'affichage
                            $stmt = $pdo->prepare("UPDATE project_files SET display_name = ? WHERE id = ? AND project_id = ?");
                            $stmt->execute([$new_name, $file_id, $project_id]);
                            
                            header("Location: project_detail.php?id=$project_id&tab=files&success=file_renamed#files");
                            exit();
                        } else {
                            $error = "Fichier non trouvé.";
                        }
                    } catch (PDOException $e) {
                        $error = "Erreur lors du renommage : " . $e->getMessage();
                    }
                } else {
                    $error = "Nom de fichier invalide.";
                }
                break;
                
            case 'edit_file':
                $file_id = intval($_POST['file_id']);
                $display_name = trim($_POST['display_name']);
                $description = trim($_POST['description']);
                
                if ($file_id > 0 && !empty($display_name)) {
                    try {
                        // Vérifier que le fichier appartient au projet
                        $stmt = $pdo->prepare("SELECT * FROM project_files WHERE id = ? AND project_id = ?");
                        $stmt->execute([$file_id, $project_id]);
                        $existing_file = $stmt->fetch();
                        
                        if ($existing_file) {
                            $file_path = $existing_file['file_path'];
                            
                            // Gérer le remplacement du fichier si un nouveau fichier est fourni
                            if (isset($_FILES['new_file']) && $_FILES['new_file']['error'] === UPLOAD_ERR_OK) {
                                $upload_file = $_FILES['new_file'];
                                $original_name = $upload_file['name'];
                                $file_size = $upload_file['size'];
                                $file_type = $upload_file['type'];
                                
                                // Limiter la taille des fichiers (50MB max)
                                if ($file_size <= 50 * 1024 * 1024) {
                                    $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                                    $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                                    $new_filename = $safe_filename . '_' . time() . '.' . $file_extension;
                                    
                                    // Créer le dossier du projet s'il n'existe pas
                                    // (minuscules obligatoires : les serveurs Linux sont sensibles a la casse)
                                    $project_folder = 'projets/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);
                                    if (!is_dir($project_folder)) {
                                        mkdir($project_folder, 0755, true);
                                    }
                                    
                                    $new_file_path = $project_folder . '/' . $new_filename;
                                    
                                    if (move_uploaded_file($upload_file['tmp_name'], $new_file_path)) {
                                        // Supprimer l'ancien fichier
                                        if (file_exists($existing_file['file_path'])) {
                                            unlink($existing_file['file_path']);
                                        }
                                        
                                        $file_path = $new_file_path;
                                        
                                        // Utiliser la catégorie fournie par l'utilisateur
                                        $file_category = $_POST['file_category'] ?? 'autre';
                                        
                                        // Mettre à jour avec le nouveau fichier
                                        $stmt = $pdo->prepare("UPDATE project_files SET display_name = ?, description = ?, file_path = ?, file_type = ?, file_size = ?, file_category = ?, original_name = ? WHERE id = ? AND project_id = ?");
                                        $stmt->execute([$display_name, $description, $file_path, $file_type, $file_size, $file_category, $original_name, $file_id, $project_id]);
                                    } else {
                                        $error = "Erreur lors du téléchargement du nouveau fichier.";
                                    }
                                } else {
                                    $error = "Le fichier est trop volumineux (50MB maximum).";
                                }
                            } else {
                                // Mettre à jour seulement le nom et la description
                                $stmt = $pdo->prepare("UPDATE project_files SET display_name = ?, description = ? WHERE id = ? AND project_id = ?");
                                $stmt->execute([$display_name, $description, $file_id, $project_id]);
                            }
                            
                            if (!isset($error)) {
                                header("Location: project_detail.php?id=$project_id&tab=files&success=file_updated#files");
                                exit();
                            }
                        } else {
                            $error = "Fichier non trouvé.";
                        }
                    } catch (PDOException $e) {
                        $error = "Erreur lors de la modification : " . $e->getMessage();
                    }
                } else {
                    $error = "Nom de fichier invalide.";
                }
                break;
        }
    }
}

// Récupérer les informations du projet
try {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND owner = ?");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $project = $stmt->fetch();
    
    if (!$project) {
        header('Location: projects.php?error=project_not_found');
        exit();
    }
} catch (PDOException $e) {
    die("Erreur lors de la récupération du projet : " . $e->getMessage());
}

// Récupérer les composants du projet avec les prix
try {
    $stmt = $pdo->prepare("
        SELECT pc.*, d.name as component_name, d.manufacturer, d.package, d.quantity as stock_quantity,
               d.price, l.casier, l.tiroir, l.compartiment, l.storage_type
        FROM project_components pc
        JOIN data d ON pc.component_id = d.id
        LEFT JOIN location l ON d.location_id = l.id
        WHERE pc.project_id = ?
        ORDER BY pc.added_at DESC
    ");
    $stmt->execute([$project_id]);
    $project_components = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Erreur lors de la récupération des composants : " . $e->getMessage());
}

// Calculer la progression globale et le coût total
$total_needed = 0;
$total_used = 0;
$total_cost = 0;
$total_cost_used = 0;

foreach ($project_components as $pc) {
    $total_needed += $pc['quantity_needed'];
    $total_used += $pc['quantity_used'];
    
    $component_price = $pc['price'] ?? 0;
    $total_cost += $component_price * $pc['quantity_needed'];
    $total_cost_used += $component_price * $pc['quantity_used'];
}

$components_progress = $total_needed > 0 ? ($total_used / $total_needed) * 100 : 0;
$components_progress = min(100, $components_progress);

// Récupérer les éléments de projet (travaux et matériaux)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM project_items 
        WHERE project_id = ?
        ORDER BY type, added_at DESC
    ");
    $stmt->execute([$project_id]);
    $project_items = $stmt->fetchAll();
    

} catch (PDOException $e) {
    die("Erreur lors de la récupération des éléments : " . $e->getMessage());
}

// Calculer le coût total des éléments et leur progression
$items_total_cost = 0;
$items_total_quantity = 0;
$items_completed_quantity = 0;

foreach ($project_items as $item) {
    $items_total_cost += $item['total_price'];
    $items_total_quantity += floatval($item['quantity']);
    $items_completed_quantity += floatval($item['quantity_completed'] ?? 0);
}

// Calculer la progression des travaux et matériaux
$items_progress = $items_total_quantity > 0 ? ($items_completed_quantity / $items_total_quantity) * 100 : 0;
$items_progress = min(100, $items_progress);

// Calculer la progression globale (moyenne pondérée des composants et des travaux/matériaux)
$total_elements = count($project_components) + count($project_items);
if ($total_elements > 0) {
    $components_weight = count($project_components) / $total_elements;
    $items_weight = count($project_items) / $total_elements;
    $global_progress = ($components_progress * $components_weight) + ($items_progress * $items_weight);
} else {
    $global_progress = 0;
}
$global_progress = min(100, $global_progress);

// Récupérer les fichiers du projet
try {
    $stmt = $pdo->prepare("
        SELECT * FROM project_files 
        WHERE project_id = ?
        ORDER BY file_category, uploaded_at DESC
    ");
    $stmt->execute([$project_id]);
    $project_files = $stmt->fetchAll();
} catch (PDOException $e) {
    $project_files = [];
}

// Récupérer tous les composants disponibles pour l'ajout
try {
    $stmt = $pdo->prepare("
        SELECT d.*, l.casier, l.tiroir, l.compartiment
        FROM data d
        LEFT JOIN location l ON d.location_id = l.id
        WHERE d.owner = ?
        ORDER BY d.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $available_components = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Erreur lors de la récupération des composants disponibles : " . $e->getMessage());
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
    <title>Projet: <?php echo htmlspecialchars($project['name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --accent-color: #f093fb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-color: #1f2937;
            --light-color: #f8fafc;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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

        .main-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header-content {
            position: relative;
            z-index: 2;
            max-width: 1420px;
            margin: 24px auto 0;
        }

        .project-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .project-avatar {
            width: 100px;
            height: 100px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.45);
            opacity: 0;
            transition: opacity 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 13px;
            color: white;
            border-radius: 18px;
        }

        .project-avatar:hover .image-overlay { opacity: 1; }

        .project-image-actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }

        .btn-sm-outline {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-sm-outline:hover { background: rgba(255,255,255,0.25); }

        .btn-sm-danger {
            background: rgba(239, 68, 68, 0.85);
            color: white;
            border: 1px solid rgba(239, 68, 68, 0.9);
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-sm-danger:hover { filter: brightness(1.05); }

        .project-info h1 {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .project-edit-inline-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: rgba(255,255,255,0.55);
            border: 1px solid transparent;
            padding: 4px 7px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.6em;
            line-height: 1;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-1px);
            transition: all 0.2s ease;
            vertical-align: middle;
            margin-left: 2px;
        }

        .project-info:hover .project-edit-inline-btn,
        .project-edit-inline-btn:focus-visible {
            opacity: 1;
            visibility: visible;
        }

        .project-edit-inline-btn:hover {
            background: rgba(255,255,255,0.18);
            color: white;
            border-color: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }

        .project-edit-inline-btn:active {
            transform: translateY(0);
        }

        /* ---- Modale Rename Projet (detail) ---- */
        .rename-project-modal-overlay {
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center;
            padding: 20px;
        }
        .rename-project-modal-overlay.open { display: flex; }
        .rename-project-modal-box {
            background: var(--bg-card);
            border-radius: 18px;
            width: 100%; max-width: 560px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.25);
            overflow: hidden;
            animation: renameModalPop .22s ease both;
        }
        @keyframes renameModalPop { from { transform: translateY(12px) scale(.97); opacity: 0; } to { transform: none; opacity: 1; } }
        .rename-project-modal-head {
            padding: 22px 26px 16px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
        }
        .rename-project-modal-head h3 { margin: 0 0 4px; font-size: 1.2rem; font-weight: 700; }
        .rename-project-modal-head p  { margin: 0; opacity: .9; font-size: .88rem; }
        .rename-project-modal-body { padding: 24px 26px 10px; }
        .rename-project-modal-foot { padding: 10px 26px 22px; display:flex; justify-content:flex-end; gap:10px; }
        .rename-form-oldname {
            padding: 10px 14px;
            background: rgba(99, 102, 241, 0.08);
            border: 1px dashed rgba(99, 102, 241, 0.35);
            color: #3730a3;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .rename-form-group { margin-bottom: 16px; }
        .rename-form-group label { display:block; margin-bottom:6px; font-weight:600; color:#334155; font-size: 14px; }
        .rename-form-group input,
        .rename-form-group select,
        .rename-form-group textarea {
            width: 100%; padding: 10px 12px;
            border: 1.5px solid var(--border-strong);
            border-radius: 10px; font-size: 14px;
            background: var(--bg-primary); transition: all .18s;
            font-family: inherit;
        }
        .rename-form-group textarea { resize: vertical; min-height: 72px; }
        .rename-form-group input:focus,
        .rename-form-group select:focus,
        .rename-form-group textarea:focus {
            outline: none; border-color: #6366f1;
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18);
        }
        .rename-hint { font-size: 12.5px; color: var(--text-secondary); margin-top: 6px; }
        .rename-btn-cancel {
            background: var(--bg-muted); color: #475569; border: 1px solid var(--border-color);
            padding: 10px 18px; border-radius: 10px; cursor: pointer; font-weight: 600; font-size: 14px;
            transition: all .18s;
        }
        .rename-btn-cancel:hover { background: var(--border-color); }
        .rename-btn-submit {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white; border: none;
            padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 14px;
            transition: all .18s;
            box-shadow: 0 6px 16px rgba(99,102,241,.3);
        }
        .rename-btn-submit:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(99,102,241,.32); }
        .rename-btn-submit:active { transform: translateY(0); }

        .project-meta {
            display: flex;
            gap: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.92;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Container principal */
        .content-container {
            flex: 1;
            background: var(--bg-card);
            margin: 0;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        /* Système d'onglets moderne */
        .tabs-header {
            background: var(--light-color);
            border-bottom: 1px solid var(--border-color);
            padding: 0 2rem;
            margin-top: 10mm;
        }

        .tabs-nav {
            display: flex;
            gap: 0;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .tabs-nav::-webkit-scrollbar {
            display: none;
        }

        .tab-button {
            background: rgba(102, 126, 234, 0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            padding: 1.25rem 2rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 3px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            border-radius: 8px 8px 0 0;
            margin-right: 2px;
        }

        .tab-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 8px 8px 0 0;
        }

        .tab-button:hover {
            color: white;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .tab-button.active {
            color: white;
            border-bottom-color: var(--primary-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            transform: translateY(-1px);
        }

        .tab-icon {
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .tab-content {
            display: none;
            padding: 2rem;
            animation: fadeInUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Cards modernes */
        .modern-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .modern-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Grille de statistiques */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.3);
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.9;
        }

        .stat-content h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-content p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Formulaires modernes */
        .modern-form {
            background: var(--light-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            font-size: 0.9rem;
        }

        .form-input, .form-select, .form-textarea {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: var(--bg-card);
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        /* Tables modernes */
        .modern-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .modern-table th {
            background: var(--light-color);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark-color);
            border-bottom: 1px solid var(--border-color);
        }

        .modern-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .modern-table tr:hover {
            background: var(--light-color);
        }

        /* Progress bar moderne */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success-color), var(--primary-color));
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content-container {
                margin: -1rem 1rem 1rem;
            }
            
            .tab-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Project Image Upload Overlay */
        .project-avatar {
            position: relative;
            cursor: pointer;
            overflow: hidden;
        }
        
        .project-avatar .image-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.6);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 18px;
            font-size: 0.85rem;
            gap: 4px;
            text-align: center;
            padding: 10px;
        }
        
        .project-avatar:hover .image-overlay {
            opacity: 1;
        }
        
        .image-overlay i {
            font-size: 1.8rem;
            margin-bottom: 4px;
        }
        
        .project-image-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 6px 14px;
            font-size: 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-sm-outline {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }
        .btn-sm-outline:hover { background: rgba(255,255,255,0.25); }
        
        .btn-sm-danger {
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-sm-danger:hover { background: #c82333; }
        
        /* Modal for image upload */
        .image-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(5px);
        }
        
        .image-modal-backdrop.active { display: flex; }
        
        .image-modal {
            background: var(--bg-card);
            border-radius: 18px;
            padding: 30px;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.25s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .image-modal h3 {
            margin: 0 0 20px 0;
            color: #1a1a2e;
            font-size: 1.3rem;
        }
        
        .drop-zone {
            border: 2.5px dashed var(--border-strong);
            border-radius: 12px;
            padding: 35px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--bg-primary);
        }
        
        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--primary-color, #667eea);
            background: #eef2ff;
        }
        
        .drop-zone i {
            font-size: 2.8rem;
            color: var(--primary-color, #667eea);
            margin-bottom: 10px;
        }
        
        .drop-zone p {
            margin: 5px 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .drop-zone p b { color: var(--primary-color, #667eea); }
        .drop-zone p.small { font-size: 0.75rem; color: var(--text-muted); }
        
        .image-modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            justify-content: flex-end;
        }
        
        .modal-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        
        .modal-btn.cancel { background: var(--bg-muted); color: #475569; }
        .modal-btn.cancel:hover { background: var(--border-color); }
        .modal-btn.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .modal-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3); }
        .modal-btn.primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        
        /* ===== MODALE SUPPRESSION FICHIER ===== */

        .del-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(3px);
            z-index: 9998;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            animation: modalFadeIn 0.2s ease;
        }
        .del-modal-backdrop.active { display: flex; }

        .del-modal {
            background: var(--bg-card);
            border-radius: 1.25rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.3);
            overflow: hidden;
            animation: modalFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .del-modal-header {
            padding: 1.75rem 1.75rem 1rem;
            text-align: center;
            border-bottom: 1px solid var(--bg-muted);
        }
        .del-modal-icon {
            width: 64px; height: 64px;
            border-radius: 1.25rem;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: white;
            display: grid; place-items: center;
            font-size: 28px; font-weight: 900;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4);
        }
        .del-modal h3 {
            margin: 0 0 0.35rem;
            font-size: 1.35rem;
            color: #0f172a;
            font-weight: 900;
        }
        .del-modal-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
        }
        .del-modal-body { padding: 1.25rem 1.75rem; }

        .del-modal-filename {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #b91c1c;
            font-weight: 800;
            border-radius: 0.85rem;
            padding: 0.9rem 1rem;
            text-align: center;
            margin-bottom: 1rem;
            word-break: break-word;
            font-size: 1rem;
        }

        .del-modal-warnings {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 0.85rem;
            padding: 0.9rem 1rem;
        }
        .del-modal-warnings h4 {
            color: #b45309;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.78rem;
            font-weight: 900;
            margin: 0 0 0.4rem;
            display: flex; align-items: center; gap: 0.4rem;
        }
        .del-modal-warnings p {
            margin: 0;
            color: #78350f;
            font-size: 0.88rem;
            line-height: 1.55;
        }

        .del-modal-actions {
            padding: 1rem 1.75rem 1.75rem;
            border-top: 1px solid var(--bg-muted);
            display: flex;
            gap: 0.85rem;
        }

        .del-btn {
            flex: 1;
            padding: 0.75rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 700;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .del-btn.cancel {
            background: var(--bg-muted);
            color: #475569;
        }
        .del-btn.cancel:hover { background: var(--border-color); color: var(--text-primary); }
        .del-btn.confirm {
            flex: 1.25;
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: white;
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.35);
        }
        .del-btn.confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(239, 68, 68, 0.45);
        }

        .upload-progress {
            margin-top: 15px;
            display: none;
        }
        
        .upload-progress.active { display: block; }
        
        .progress-bar {
            height: 8px;
            background: var(--border-color);
            border-radius: 999px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 999px;
        }
        
        .file-name {
            font-size: 0.85rem;
            color: #475569;
            margin-top: 6px;
        }
        
        .upload-success-msg, .upload-error-msg {
            margin-top: 15px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.88rem;
            display: none;
        }
        .upload-success-msg { background: #dcfce7; color: #166534; display: none; }
        .upload-error-msg { background: #fee2e2; color: #991b1b; display: none; }
        .upload-success-msg.show, .upload-error-msg.show { display: block; }
    </style>
</head>
<body data-project-id="<?php echo $project_id; ?>">
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🛠️</div>
                    <div>
                        <h1>Détails du Projet</h1>
                        <p>Suivez l'avancement et la composition de votre projet</p>
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
                <a href="projects.php">📋 Liste Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
            <div class="header-content">
                <div class="project-header">
                    <div>
                        <div class="project-avatar" id="projectAvatar" onclick="openImageModal()">
                            <?php if (!empty($project['image_path']) && file_exists($project['image_path'])): ?>
                                <img id="projectAvatarImg" src="<?php echo htmlspecialchars($project['image_path']) . '?t=' . filemtime($project['image_path']); ?>" alt="Image du projet" style="width: 100%; height: 100%; object-fit: cover; border-radius: 18px;">
                            <?php else: ?>
                                <i class="fas fa-microchip"></i>
                            <?php endif; ?>
                            <div class="image-overlay">
                                <i class="fas fa-camera"></i>
                                <span>Cliquer pour <b>changer</b> l'image</span>
                            </div>
                        </div>
                        <div class="project-image-actions">
                            <button type="button" class="btn-sm-outline" onclick="openImageModal()">
                                <i class="fas fa-image"></i> Changer l'image
                            </button>
                            <?php if (!empty($project['image_path']) && file_exists($project['image_path'])): ?>
                                <button type="button" class="btn-sm-danger" onclick="deleteProjectImage()">
                                    <i class="fas fa-trash"></i> Supprimer
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="project-info">
                        <h1>
                            <?php echo htmlspecialchars($project['name']); ?>
                            <button type="button"
                                    class="project-edit-inline-btn"
                                    title="Renommer / modifier le projet"
                                    onclick="openRenameProjectModal(<?php echo (int)$project['id']; ?>, '<?php echo htmlspecialchars(addslashes($project['name'])); ?>', '<?php echo htmlspecialchars(addslashes($project['description'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($project['status'])); ?>')">
                                ✏️
                            </button>
                        </h1>
                        <div class="project-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                Créé le <?php echo date('d/m/Y', strtotime($project['created_at'])); ?>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                Modifié le <?php echo date('d/m/Y', strtotime($project['updated_at'])); ?>
                            </div>
                            <?php if (!empty($project['description'])): ?>
                            <div class="meta-item">
                                <i class="fas fa-info-circle"></i>
                                <?php echo htmlspecialchars($project['description']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="main-container">
            <!-- Container principal -->
            <div class="content-container">
            <!-- Navigation par onglets -->
            <div class="tabs-header">
                <nav class="tabs-nav">
                    <button class="tab-button active" onclick="showTab('overview')">
                        <i class="fas fa-chart-pie tab-icon"></i>
                        Vue d'ensemble
                    </button>
                    <button class="tab-button" onclick="showTab('components')">
                        <i class="fas fa-microchip tab-icon"></i>
                        Composants
                    </button>
                    <button class="tab-button" onclick="showTab('materials')">
                        <i class="fas fa-tools tab-icon"></i>
                        Travaux & Matériaux
                    </button>
                    <button class="tab-button" onclick="showTab('files')">
                        <i class="fas fa-folder tab-icon"></i>
                        Documents & Photos
                    </button>
                </nav>
            </div>

            <!-- Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php
                    switch ($_GET['success']) {
                        case 'component_added': echo 'Composant ajouté avec succès !'; break;
                        case 'component_removed': echo 'Composant supprimé avec succès !'; break;
                        case 'quantity_updated': echo 'Quantité mise à jour avec succès !'; break;
                        case 'item_added': echo 'Élément ajouté avec succès !'; break;
                        case 'item_removed': echo 'Élément supprimé avec succès !'; break;
                        case 'status_updated': echo 'Statut mis à jour avec succès !'; break;
                        case 'project_renamed': echo '✅ Projet renommé / modifié avec succès !'; break;
                        case 'files_uploaded': echo '✅ Fichier(s) uploadé(s) avec succès !'; break;
                        default: echo 'Opération réussie !';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Contenu des onglets -->
            
            <!-- Onglet Vue d'ensemble -->
            <div id="overview" class="tab-content active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-microchip"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo count($project_components); ?></h3>
                            <p>Composants</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo count($project_items); ?></h3>
                            <p>Travaux & Matériaux</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo count($project_files); ?></h3>
                            <p>Documents</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-euro-sign"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($total_cost + $items_total_cost, 2); ?>€</h3>
                            <p>Coût total</p>
                        </div>
                    </div>
                </div>



                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-line"></i>
                            Progression du projet
                        </h3>
                        <span style="font-weight: 600; color: var(--primary-color);" data-project-progress-label><?php echo number_format($global_progress, 1); ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" data-project-progress-fill style="width: <?php echo $global_progress; ?>%"></div>
                    </div>
                    <p style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.9rem;" data-project-progress-summary>
                        <span data-project-total-used><?php echo $total_used; ?></span> composants utilisés sur <span data-project-total-needed><?php echo $total_needed; ?></span> nécessaires
                        •
                        <span data-project-items-completed><?php echo number_format($items_completed_quantity, 1); ?></span> / <span data-project-items-total><?php echo number_format($items_total_quantity, 1); ?></span> travaux &amp; matériaux
                    </p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                    <div class="modern-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-microchip"></i>
                                Résumé Composants
                            </h3>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                            <div style="text-align: center; padding: 1rem; background: var(--light-color); border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;" data-project-total-needed><?php echo $total_needed; ?></div>
                                <div style="font-size: 0.9rem; color: var(--text-secondary);">Nécessaires</div>
                            </div>
                            <div style="text-align: center; padding: 1rem; background: var(--light-color); border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--success-color); margin-bottom: 0.5rem;" data-project-total-used><?php echo $total_used; ?></div>
                                <div style="font-size: 0.9rem; color: var(--text-secondary);">Utilisés</div>
                            </div>
                        </div>
                    </div>

                    <div class="modern-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-euro-sign"></i>
                                Résumé Financier
                            </h3>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                            <div style="text-align: center; padding: 1rem; background: var(--light-color); border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;"><?php echo number_format($total_cost, 2); ?>€</div>
                                <div style="font-size: 0.9rem; color: var(--text-secondary);">Composants</div>
                            </div>
                            <div style="text-align: center; padding: 1rem; background: var(--light-color); border-radius: 8px;">
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning-color); margin-bottom: 0.5rem;"><?php echo number_format($items_total_cost, 2); ?>€</div>
                                <div style="font-size: 0.9rem; color: var(--text-secondary);">Travaux & Matériaux</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste des Composants -->
                <?php if (!empty($project_components)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-microchip"></i>
                            Composants du projet (<?php echo count($project_components); ?>)
                        </h3>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Composant</th>
                                    <th>Quantité</th>
                                    <th>Progression</th>
                                    <th>Prix</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($project_components, 0, 10) as $pc): ?>
                                    <?php
                                    $progress = $pc['quantity_needed'] > 0 ? ($pc['quantity_used'] / $pc['quantity_needed']) * 100 : 0;
                                    $progress = min(100, $progress);
                                    $component_price = $pc['price'] ?? 0;
                                    $total_component_cost = $component_price * $pc['quantity_needed'];
                                    ?>
                                    <tr data-component-row="<?php echo $pc['id']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($pc['component_name']); ?></strong>
                                            <?php if (isset($pc['manufacturer']) && $pc['manufacturer']): ?>
                                                <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($pc['manufacturer']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <form method="POST" style="display: inline;" data-quantity-form data-pc-id="<?php echo $pc['id']; ?>" data-action-type="minus">
                                                    <input type="hidden" name="action" value="update_quantity_used">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="quantity_used" value="<?php echo max(0, $pc['quantity_used'] - 1); ?>">
                                                    <input type="hidden" name="source_tab" value="overview">
                                                    <button type="submit" class="btn btn-sm" data-qty-btn="minus" style="background: #ef4444; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" <?php echo $pc['quantity_used'] <= 0 ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                </form>
                                                <span data-qty-display style="font-weight: 600; min-width: 60px; text-align: center;"><?php echo $pc['quantity_used']; ?> / <?php echo $pc['quantity_needed']; ?></span>
                                                <form method="POST" style="display: inline;" data-quantity-form data-pc-id="<?php echo $pc['id']; ?>" data-action-type="plus">
                                                    <input type="hidden" name="action" value="update_quantity_used">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="quantity_used" value="<?php echo min($pc['quantity_needed'], $pc['quantity_used'] + 1); ?>">
                                                    <input type="hidden" name="source_tab" value="overview">
                                                    <button type="submit" class="btn btn-sm" data-qty-btn="plus" style="background: #10b981; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" <?php echo $pc['quantity_used'] >= $pc['quantity_needed'] ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="progress-bar" style="width: 80px;">
                                                <div class="progress-fill" data-progress-fill style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <small style="color: var(--text-secondary);" data-progress-text><?php echo number_format($progress, 0); ?>%</small>
                                        </td>
                                        <td data-total-cost><?php echo number_format($total_component_cost, 2); ?>€</td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($project_components) > 10): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-secondary); font-style: italic;">
                                            ... et <?php echo count($project_components) - 10; ?> autres composants
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Liste des Travaux & Matériaux -->
                <?php if (!empty($project_items)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-tools"></i>
                            Travaux & Matériaux (<?php echo count($project_items); ?>)
                        </h3>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Type</th>
                                    <th>Quantité</th>
                                    <th>Progression</th>
                                    <th>Prix unitaire</th>
                                    <th>Coût total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($project_items, 0, 10) as $item): ?>
                                    <?php
                                    // Calculer la progression basée sur les quantités réelles
                                    $total_quantity = floatval($item['quantity']);
                                    $completed_quantity = floatval($item['quantity_completed'] ?? 0);
                                    $progress = $total_quantity > 0 ? ($completed_quantity / $total_quantity) * 100 : 0;
                                    $progress = min(100, max(0, $progress));
                                    $item_total_cost = $item['quantity'] * $item['unit_price'];
                                    ?>
                                    <tr data-item-row="<?php echo $item['id']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['name'] ?? $item['description']); ?></strong>
                                            <?php if (isset($item['description']) && $item['description'] && $item['description'] !== ($item['name'] ?? '')): ?>
                                                <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($item['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; 
                                                         background: <?php 
                                                             if ($item['type'] === 'travail') echo 'rgba(59, 130, 246, 0.1)';
                                                             elseif ($item['type'] === 'service') echo 'rgba(168, 85, 247, 0.1)';
                                                             else echo 'rgba(16, 185, 129, 0.1)';
                                                         ?>;
                                                         color: <?php 
                                                             if ($item['type'] === 'travail') echo '#3b82f6';
                                                             elseif ($item['type'] === 'service') echo '#a855f7';
                                                             else echo '#10b981';
                                                         ?>;">
                                                <?php echo ucfirst($item['type'] ?? 'matériel'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit'] ?? ''); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <button onclick="updateProgress(<?php echo $item['id']; ?>, -25)" data-item-btn-minus
                                                        style="background: #ef4444; color: white; border: none; border-radius: 4px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem;"
                                                        title="Diminuer la progression"
                                                        <?php echo $completed_quantity <= 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                                                    <div class="progress-bar" style="width: 80px;">
                                                        <div class="progress-fill" data-progress-fill style="width: <?php echo $progress; ?>%"></div>
                                                    </div>
                                                    <small style="color: var(--text-secondary); font-size: 0.75rem;" data-progress-text>
                                                        <span data-qty-completed><?php echo number_format($completed_quantity, 1); ?></span>
                                                        / <span data-qty-total><?php echo number_format($total_quantity, 1); ?></span>
                                                        <span data-qty-unit><?php echo htmlspecialchars($item['unit']); ?></span>
                                                    </small>
                                                </div>
                                                <button onclick="updateProgress(<?php echo $item['id']; ?>, 25)" data-item-btn-plus
                                                        style="background: #10b981; color: white; border: none; border-radius: 4px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem;"
                                                        title="Augmenter la progression"
                                                        <?php echo $completed_quantity >= $total_quantity ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td><?php echo number_format($item['unit_price'], 2); ?>€</td>
                                        <td><strong><?php echo number_format($item_total_cost, 2); ?>€</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (count($project_items) > 10): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-secondary); font-style: italic;">
                                            ... et <?php echo count($project_items) - 10; ?> autres éléments
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Liste des Documents -->
                <?php if (!empty($project_files)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-folder"></i>
                            Documents & Photos (<?php echo count($project_files); ?>)
                        </h3>
                    </div>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <div style="display: flex; flex-direction: column; gap: 1rem; padding: 0.5rem;">
                            <?php foreach (array_slice($project_files, 0, 12) as $file): ?>
                                <?php
                                // Déterminer la couleur de bordure selon la catégorie
                                $borderColor = '#e5e7eb'; // Couleur par défaut
                                $categoryIcon = 'fas fa-file';
                                switch($file['file_category'] ?? 'other') {
                                    case 'photo':
                                        $borderColor = '#10b981'; // Vert
                                        $categoryIcon = 'fas fa-image';
                                        break;
                                    case 'schéma':
                                    case 'schema':
                                        $borderColor = '#8b5cf6'; // Violet pour les schémas
                                        $categoryIcon = 'fas fa-drafting-compass';
                                        break;
                                    case 'datasheet':
                                        $borderColor = '#ef4444'; // Rouge
                                        $categoryIcon = 'fas fa-file-code';
                                        break;
                                    case 'programme':
                                        $borderColor = '#3b82f6'; // Bleu pour les programmes
                                        $categoryIcon = 'fas fa-code';
                                        break;
                                    case 'document':
                                        $borderColor = '#06b6d4'; // Cyan pour les documents
                                        $categoryIcon = 'fas fa-file-text';
                                        break;
                                    case 'autre':
                                        $borderColor = '#84cc16'; // Lime pour autres
                                        $categoryIcon = 'fas fa-file-alt';
                                        break;
                                    default:
                                        $borderColor = '#6b7280'; // Gris
                                        $categoryIcon = 'fas fa-file';
                                        break;
                                }
                                
                                $filename = isset($file['filename']) ? $file['filename'] : (isset($file['file_name']) ? $file['file_name'] : (isset($file['original_name']) ? $file['original_name'] : 'Fichier sans nom'));
                                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                ?>
                                
                                <!-- Ligne horizontale pour chaque fichier -->
                                <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; background: var(--bg-card); border: 2px solid <?php echo $borderColor; ?>; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s ease;" onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                    
                                    <!-- 1. Logo/Image (60px) -->
                                    <div style="width: 60px; height: 60px; flex-shrink: 0;">
                                        <?php if ($is_image && ($file['file_category'] ?? '') === 'photo'): ?>
                                            <div style="width: 100%; height: 100%; border-radius: 8px; overflow: hidden; border: 2px solid <?php echo $borderColor; ?>; background: var(--bg-primary); display: flex; align-items: center; justify-content: center;">
                                                <img src="<?php echo htmlspecialchars($file['file_path'] ?? ''); ?>" 
                                                     alt="<?php echo htmlspecialchars($file['display_name'] ?? $file['original_name'] ?? $filename); ?>"
                                                     style="width: 100%; height: 100%; object-fit: cover;"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                                <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: <?php echo $borderColor; ?>;">
                                                    <i class="<?php echo $categoryIcon; ?>" style="font-size: 1.5rem;"></i>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div style="width: 100%; height: 100%; background: <?php echo $borderColor; ?>20; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 2px solid <?php echo $borderColor; ?>;">
                                                <i class="<?php echo $categoryIcon; ?>" style="color: <?php echo $borderColor; ?>; font-size: 1.8rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- 2. Nom (éditable) -->
                                    <div style="flex: 1; min-width: 200px;">
                                        <div id="filename-display-overview-<?php echo $file['id']; ?>" style="font-weight: 600; color: var(--dark-color); font-size: 1rem; line-height: 1.4; word-break: break-word; cursor: pointer;" onclick="editFileNameOverview(<?php echo $file['id']; ?>)">
                                            <?php echo htmlspecialchars($file['display_name'] ?? $file['original_name'] ?? $filename); ?>
                                            <i class="fas fa-edit" style="margin-left: 0.5rem; font-size: 0.8rem; color: var(--text-muted);"></i>
                                        </div>
                                        <div id="filename-edit-overview-<?php echo $file['id']; ?>" style="display: none;">
                                            <input type="text" id="filename-input-overview-<?php echo $file['id']; ?>" 
                                                   value="<?php echo htmlspecialchars($file['display_name'] ?? $file['original_name'] ?? $filename); ?>"
                                                   style="width: 100%; padding: 0.5rem; border: 2px solid <?php echo $borderColor; ?>; border-radius: 4px; font-size: 0.9rem;">
                                            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                                                <button onclick="saveFileNameOverview(<?php echo $file['id']; ?>)" 
                                                        style="background: <?php echo $borderColor; ?>; color: white; border: none; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">
                                                    <i class="fas fa-check"></i> Sauver
                                                </button>
                                                <button onclick="cancelEditOverview(<?php echo $file['id']; ?>)" 
                                                        style="background: var(--text-secondary); color: white; border: none; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer;">
                                                    <i class="fas fa-times"></i> Annuler
                                                </button>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                            <span style="background: <?php echo $borderColor; ?>; color: white; padding: 0.125rem 0.5rem; border-radius: 12px; font-size: 0.7rem;">
                                                <?php echo ucfirst($file['file_category'] ?? 'autre'); ?>
                                            </span>
                                            • <?php echo strtoupper($extension); ?> • <?php echo isset($file['file_size']) ? number_format($file['file_size'] / 1024, 1) . ' KB' : 'Taille inconnue'; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- 3. Description -->
                                     <div style="flex: 1; min-width: 150px;">
                                         <?php if (isset($file['description']) && $file['description']) : ?>
                                             <div style="font-size: 0.875rem; color: #4b5563; line-height: 1.4;">
                                                 <?php echo htmlspecialchars($file['description']); ?>
                                             </div>
                                         <?php else: ?>
                                             <div style="font-size: 0.875rem; color: var(--text-muted); font-style: italic;">
                                                 Aucune description
                                             </div>
                                         <?php endif; ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                            <i class="fas fa-clock" style="margin-right: 0.25rem;"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($file['uploaded_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <!-- 4. Actions -->
                                    <div style="display: flex; gap: 0.5rem; flex-shrink: 0;">
                                        <?php if (isset($file['id'])): ?>
                                            <a href="download_project_file.php?id=<?php echo $file['id']; ?>" 
                                               style="background: #10b981; color: white; padding: 0.5rem; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; transition: all 0.2s;" 
                                               title="Télécharger"
                                               onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                                                <i class="fas fa-download" style="font-size: 0.875rem;"></i>
                                            </a>
                                            <button onclick="openEditFileModal(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['display_name'] ?? $file['original_name'] ?? $filename, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($file['description'] ?? '', ENT_QUOTES); ?>')" 
                                                    style="background: #3b82f6; color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; transition: all 0.2s;" 
                                                    title="Modifier"
                                                    onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                                                <i class="fas fa-edit" style="font-size: 0.875rem;"></i>
                                            </button>
                                            <form method="POST" action="delete_project_file.php" style="display: inline;" data-delete-file-form>
                                                <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                                <button type="button" 
                                                        style="background: #ef4444; color: white; border: none; padding: 0.5rem; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; transition: all 0.2s;"
                                                        title="Supprimer"
                                                        onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'"
                                                        onclick="openDeleteFileModal(<?php echo (int)$file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['display_name'] ?? $file['original_name'] ?? 'Fichier'), ENT_QUOTES); ?>')">
                                                    <i class="fas fa-trash" style="font-size: 0.875rem;"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($project_files) > 12): ?>
                                <div style="border: 2px dashed #d1d5db; border-radius: 12px; padding: 2rem; background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-muted) 100%); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: var(--text-secondary); min-height: 150px;">
                                    <i class="fas fa-folder-plus" style="font-size: 2rem; margin-bottom: 0.75rem; opacity: 0.5;"></i>
                                    <div style="font-weight: 600; margin-bottom: 0.25rem;">+ <?php echo count($project_files) - 12; ?> autres fichiers</div>
                                    <div style="font-size: 0.875rem; opacity: 0.7;">Consultez l'onglet Documents pour voir tous les fichiers</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Onglet Composants -->
            <div id="components" class="tab-content">
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus"></i>
                            Ajouter un composant
                        </h3>
                    </div>
                    
                    <form method="POST" class="modern-form">
                        <input type="hidden" name="action" value="add_component">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Composant</label>
                                <div style="position: relative;">
                                    <input type="text" id="component_search" class="form-input" placeholder="Rechercher un composant..." autocomplete="off" required>
                                    <input type="hidden" name="component_id" id="selected_component_id" required>
                                    <div id="component_suggestions" style="position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-card); border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto; z-index: 1000; display: none;"></div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Quantité nécessaire</label>
                                <input type="number" name="quantity_needed" class="form-input" min="1" value="1" required>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Notes (optionnel)</label>
                                <textarea name="notes" class="form-textarea" rows="2" placeholder="Notes sur l'utilisation de ce composant..."></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Ajouter le composant
                        </button>
                    </form>
                </div>

                <?php if (!empty($project_components)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Liste des composants (<?php echo count($project_components); ?>)
                        </h3>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Composant</th>
                                    <th>Fabricant</th>
                                    <th>Package</th>
                                    <th>Utilisée</th>
                                    <th>Besoin</th>
                                    <th>Progression</th>
                                    <th>Prix unitaire</th>
                                    <th>Coût total</th>
                                    <th>Localisation</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($project_components as $pc): ?>
                                    <?php
                                    $progress = $pc['quantity_needed'] > 0 ? ($pc['quantity_used'] / $pc['quantity_needed']) * 100 : 0;
                                    $progress = min(100, $progress);
                                    $component_price = $pc['price'] ?? 0;
                                    $total_component_cost = $component_price * $pc['quantity_needed'];
                                    ?>
                                    <tr data-component-row="<?php echo $pc['id']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($pc['component_name']); ?></strong>
                                            <?php if ($pc['notes']): ?>
                                                <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($pc['notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($pc['manufacturer'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($pc['package'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                                <form method="POST" style="display: inline;" data-quantity-form data-pc-id="<?php echo $pc['id']; ?>" data-action-type="minus">
                                                    <input type="hidden" name="action" value="update_quantity_used">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="source_tab" value="components">
                                                    <input type="hidden" name="quantity_used" value="<?php echo max(0, $pc['quantity_used'] - 1); ?>">
                                                    <button type="submit" class="btn btn-sm" data-qty-btn="minus" style="background: #ef4444; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" <?php echo $pc['quantity_used'] <= 0 ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline-flex; align-items: center; gap: 0.35rem;" data-quantity-form data-pc-id="<?php echo $pc['id']; ?>" data-action-type="manual">
                                                    <input type="hidden" name="action" value="update_quantity_used">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="source_tab" value="components">
                                                    <input type="number" name="quantity_used" data-qty-input value="<?php echo $pc['quantity_used']; ?>" 
                                                           min="0" max="<?php echo $pc['quantity_needed']; ?>" 
                                                           style="width: 56px; padding: 0.25rem; border: 1px solid var(--border-color); border-radius: 4px; text-align: center;">
                                                    <button type="submit" class="btn btn-sm btn-primary" data-qty-btn="check" style="padding: 0.25rem 0.4rem; font-size: 0.75rem;">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;" data-quantity-form data-pc-id="<?php echo $pc['id']; ?>" data-action-type="plus">
                                                    <input type="hidden" name="action" value="update_quantity_used">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="source_tab" value="components">
                                                    <input type="hidden" name="quantity_used" value="<?php echo min($pc['quantity_needed'], $pc['quantity_used'] + 1); ?>">
                                                    <button type="submit" class="btn btn-sm" data-qty-btn="plus" style="background: #10b981; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" <?php echo $pc['quantity_used'] >= $pc['quantity_needed'] ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                                <form method="POST" style="display: inline;" data-needed-form data-pc-id="<?php echo $pc['id']; ?>" data-needed-action="minus">
                                                    <input type="hidden" name="action" value="update_quantity_needed">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                                    <input type="hidden" name="change" value="-1">
                                                    <button type="submit" class="btn btn-sm" style="background: #6366f1; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" <?php echo $pc['quantity_needed'] <= 1 ? 'disabled' : ''; ?> data-needed-btn="minus">
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline-flex; align-items: center; gap: 0.35rem;" data-needed-form data-pc-id="<?php echo $pc['id']; ?>" data-needed-action="manual">
                                                    <input type="hidden" name="action" value="update_quantity_needed">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                                    <input type="number" name="quantity_needed" data-needed-input value="<?php echo $pc['quantity_needed']; ?>" 
                                                           min="1" 
                                                           style="width: 56px; padding: 0.25rem; border: 1px solid var(--border-color); border-radius: 4px; text-align: center; font-weight: 600; color: #6366f1;">
                                                    <button type="submit" class="btn btn-sm" style="background: #6366f1; color: white; padding: 0.25rem 0.4rem; border: none; border-radius: 4px; font-size: 0.75rem;" data-needed-btn="check">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;" data-needed-form data-pc-id="<?php echo $pc['id']; ?>" data-needed-action="plus">
                                                    <input type="hidden" name="action" value="update_quantity_needed">
                                                    <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                                    <input type="hidden" name="change" value="1">
                                                    <button type="submit" class="btn btn-sm" style="background: #6366f1; color: white; padding: 0.25rem 0.5rem; border: none; border-radius: 4px; font-size: 0.8rem;" data-needed-btn="plus">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="progress-bar" style="width: 100px;">
                                                <div class="progress-fill" data-progress-fill style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <small style="color: var(--text-secondary);" data-progress-text><?php echo number_format($progress, 1); ?>%</small>
                                        </td>
                                        <td><?php echo number_format($component_price, 2); ?>€</td>
                                        <td><strong data-total-cost><?php echo number_format($total_component_cost, 2); ?>€</strong></td>
                                        <td>
                                            <?php if ($pc['casier'] || $pc['tiroir'] || $pc['compartiment']): ?>
                                                <small style="color: var(--text-secondary);">
                                                    <?php
                                                        $isFlatLocation = in_array($pc['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                                        $locationParts = $isFlatLocation
                                                            ? [$pc['casier'], $pc['compartiment']]
                                                            : [$pc['casier'], $pc['tiroir'], $pc['compartiment']];
                                                        echo htmlspecialchars(implode(' - ', array_filter($locationParts)));
                                                    ?>
                                                </small>
                                            <?php else: ?>
                                                <small style="color: var(--text-secondary);">Non localisé</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_component">
                                                <input type="hidden" name="pc_id" value="<?php echo $pc['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce composant ?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="modern-card" style="text-align: center; padding: 3rem;">
                    <i class="fas fa-microchip" style="font-size: 3rem; color: var(--border-color); margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--text-secondary); margin-bottom: 0.5rem;">Aucun composant ajouté</h3>
                    <p style="color: var(--text-muted);">Commencez par ajouter des composants à votre projet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Onglet Travaux & Matériaux -->
            <div id="materials" class="tab-content">
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus"></i>
                            Ajouter un élément
                        </h3>
                    </div>
                    
                    <form method="POST" class="modern-form">
                        <input type="hidden" name="action" value="add_project_item">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Type</label>
                                <select name="item_type" class="form-select" required>
                                    <option value="">Sélectionner un type...</option>
                                    <option value="travail">Travail</option>
                                    <option value="materiel">Matériel</option>
                                    <option value="service">Service</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Nom</label>
                                <input type="text" name="item_name" class="form-input" required placeholder="Nom de l'élément">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Quantité</label>
                                <input type="number" name="item_quantity" class="form-input" step="0.01" min="0.01" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Unité</label>
                                <input type="text" name="item_unit" class="form-input" required placeholder="ex: pièces, heures, m²">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Prix unitaire (€)</label>
                                <input type="number" name="item_unit_price" class="form-input" step="0.01" min="0" required>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Description (optionnel)</label>
                                <textarea name="item_description" class="form-textarea" rows="2" placeholder="Description de l'élément..."></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Ajouter l'élément
                        </button>
                    </form>
                </div>

                <?php if (!empty($project_items)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Liste des éléments (<?php echo count($project_items); ?>)
                        </h3>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Type</th>
                                    <th>Quantité</th>
                                    <th>Progression</th>
                                    <th>Prix unitaire</th>
                                    <th>Coût total</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($project_items as $item): ?>
                                    <?php
                                    // Calculer la progression basée sur les quantités réelles
                                    $total_quantity = floatval($item['quantity']);
                                    $completed_quantity = floatval($item['quantity_completed'] ?? 0);
                                    $progress = $total_quantity > 0 ? ($completed_quantity / $total_quantity) * 100 : 0;
                                    $progress = min(100, max(0, $progress));
                                    $item_total_cost = $item['quantity'] * $item['unit_price'];
                                    ?>
                                    <tr data-item-row="<?php echo $item['id']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                            <?php if ($item['description']): ?>
                                                <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($item['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>

                                            <span style="padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; 
                                                         background: <?php 
                                                             if ($item['type'] === 'travail') echo 'rgba(59, 130, 246, 0.1)';
                                                             elseif ($item['type'] === 'service') echo 'rgba(168, 85, 247, 0.1)';
                                                             else echo 'rgba(16, 185, 129, 0.1)';
                                                         ?>;
                                                         color: <?php 
                                                             if ($item['type'] === 'travail') echo '#3b82f6';
                                                             elseif ($item['type'] === 'service') echo '#a855f7';
                                                             else echo '#10b981';
                                                         ?>;">                                                <?php echo ucfirst($item['type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <button onclick="updateProgress(<?php echo $item['id']; ?>, -25)" data-item-btn-minus
                                                        style="background: #ef4444; color: white; border: none; border-radius: 4px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem;"
                                                        title="Diminuer la progression"
                                                        <?php echo $completed_quantity <= 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.25rem;">
                                                    <div class="progress-bar" style="width: 80px;">
                                                        <div class="progress-fill" data-progress-fill style="width: <?php echo $progress; ?>%"></div>
                                                    </div>
                                                    <small style="color: var(--text-secondary); font-size: 0.75rem;" data-progress-text>
                                                        <span data-qty-completed><?php echo number_format($completed_quantity, 2); ?></span>
                                                        / <span data-qty-total><?php echo number_format($total_quantity, 2); ?></span>
                                                        <span data-qty-unit><?php echo htmlspecialchars($item['unit']); ?></span>
                                                    </small>
                                                </div>
                                                <button onclick="updateProgress(<?php echo $item['id']; ?>, 25)" data-item-btn-plus
                                                        style="background: #10b981; color: white; border: none; border-radius: 4px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.8rem;"
                                                        title="Augmenter la progression"
                                                        <?php echo $completed_quantity >= $total_quantity ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td><?php echo number_format($item['unit_price'], 2); ?>€</td>
                                        <td><strong><?php echo number_format($item_total_cost, 2); ?>€</strong></td>
                                        <td>
                                            <button onclick="editItem(<?php echo $item['id']; ?>, '<?php echo addslashes($item['name']); ?>', '<?php echo $item['type']; ?>', <?php echo $item['quantity']; ?>, '<?php echo addslashes($item['unit']); ?>', <?php echo $item['unit_price']; ?>, '<?php echo addslashes($item['description'] ?? ''); ?>')" 
                                                    class="btn btn-sm btn-warning" style="margin-right: 0.5rem;" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_project_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet élément ?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="modern-card" style="text-align: center; padding: 3rem;">
                    <i class="fas fa-tools" style="font-size: 3rem; color: var(--border-color); margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--text-secondary); margin-bottom: 0.5rem;">Aucun élément ajouté</h3>
                    <p style="color: var(--text-muted);">Ajoutez des travaux et matériaux à votre projet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Onglet Documents & Photos -->
            <div id="files" class="tab-content">
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-upload"></i>
                            Ajouter un fichier
                        </h3>
                    </div>
                    
                    <form action="upload_project_file.php" method="POST" enctype="multipart/form-data" class="modern-form">
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Fichier</label>
                                <input type="file" name="project_file" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Catégorie</label>
                                <select name="file_category" class="form-select" required>
                                    <option value="">Sélectionner une catégorie...</option>
                                    <option value="schema">Schéma</option>
                                    <option value="photo">Photo</option>
                                    <option value="datasheet">Datasheet</option>
                                    <option value="programme">Programme</option>
                                    <option value="document">Document</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Nom du fichier (optionnel)</label>
                                <input type="text" name="display_name" class="form-input" placeholder="Nom personnalisé pour le fichier...">
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label class="form-label">Description (optionnel)</label>
                                <textarea name="file_description" class="form-textarea" rows="2" placeholder="Description du fichier..."></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i>
                            Télécharger le fichier
                        </button>
                    </form>
                </div>

                <?php if (!empty($project_files)): ?>
                <div class="modern-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-folder-open"></i>
                            Fichiers du projet (<?php echo count($project_files); ?>)
                        </h3>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
                        <?php foreach ($project_files as $file): 

                            
                            // Définir les couleurs de bordure selon le type
                            $border_color = '#d1d5db'; // Couleur par défaut
                            $category_color = '#6b7280';
                            $icon = 'fa-file';
                            
                            switch($file['file_category']) {
                                case 'photo':
                                    $border_color = '#10b981'; // Vert pour les photos
                                    $category_color = '#10b981';
                                    $icon = 'fa-image';
                                    break;
                                case 'schema':
                                    $border_color = '#8b5cf6'; // Violet pour les schémas
                                    $category_color = '#8b5cf6';
                                    $icon = 'fa-project-diagram';
                                    break;
                                case 'documentation':
                                    $border_color = '#f59e0b'; // Orange pour la documentation
                                    $category_color = '#f59e0b';
                                    $icon = 'fa-file-alt';
                                    break;
                                case 'datasheet':
                                    $border_color = '#ef4444'; // Rouge pour les datasheets
                                    $category_color = '#ef4444';
                                    $icon = 'fa-file-code';
                                    break;
                                case 'programme':
                                    $border_color = '#3b82f6'; // Bleu pour les programmes
                                    $category_color = '#3b82f6';
                                    $icon = 'fa-code';
                                    break;
                                case 'document':
                                    $border_color = '#06b6d4'; // Cyan pour les documents
                                    $category_color = '#06b6d4';
                                    $icon = 'fa-file-text';
                                    break;
                                case 'autre':
                                case 'autres':
                                    $border_color = '#84cc16'; // Lime pour autres
                                    $category_color = '#84cc16';
                                    $icon = 'fa-file-alt';
                                    break;
                                case 'Schema':
                                case 'schéma':
                                case 'Schéma':
                                    $border_color = '#8b5cf6'; // Violet pour les schémas
                                    $category_color = '#8b5cf6';
                                    $icon = 'fa-project-diagram';
                                    break;
                                case 'Programme':
                                case 'programs':
                                case 'code':
                                    $border_color = '#3b82f6'; // Bleu pour les programmes
                                    $category_color = '#3b82f6';
                                    $icon = 'fa-code';
                                    break;
                                case 'Document':
                                case 'documents':
                                case 'doc':
                                    $border_color = '#06b6d4'; // Cyan pour les documents
                                    $category_color = '#06b6d4';
                                    $icon = 'fa-file-text';
                                    break;
                                default:
                                    $border_color = '#6b7280'; // Gris pour non définis
                                    $category_color = '#6b7280';
                                    $icon = 'fa-file';
                            }
                            
                            // Vérifier si c'est une image pour afficher une miniature
                            $is_image = in_array(strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        ?>
                            <div style="border: 3px solid <?php echo $border_color; ?>; border-radius: 12px; padding: 1.25rem; background: var(--light-color); box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.2s ease, box-shadow 0.2s ease;" 
                                 onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 16px rgba(0,0,0,0.15)'" 
                                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'">
                                
                                <!-- Miniature ou icône -->
                                <div style="text-align: center; margin-bottom: 1rem;">
                                    <?php if ($is_image && $file['file_category'] === 'photo'): ?>
                                        <div style="width: 120px; height: 120px; margin: 0 auto; border-radius: 8px; overflow: hidden; border: 2px solid <?php echo $border_color; ?>; background: var(--bg-primary); display: flex; align-items: center; justify-content: center;">
                                            <img src="<?php echo htmlspecialchars($file['file_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($file['original_name']); ?>"
                                                 style="width: 100%; height: 100%; object-fit: cover;"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                            <div style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center; color: <?php echo $category_color; ?>;">
                                                <i class="fas <?php echo $icon; ?>" style="font-size: 2.5rem;"></i>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div style="width: 120px; height: 120px; margin: 0 auto; border-radius: 8px; background: linear-gradient(135deg, <?php echo $border_color; ?>20, <?php echo $border_color; ?>10); display: flex; align-items: center; justify-content: center; border: 2px solid <?php echo $border_color; ?>;">
                                            <i class="fas <?php echo $icon; ?>" style="font-size: 3rem; color: <?php echo $category_color; ?>;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Informations du fichier -->
                                <div style="text-align: center;">
                                    <div id="filename-display-<?php echo $file['id']; ?>" style="font-weight: 600; color: var(--dark-color); word-break: break-word; margin-bottom: 0.5rem; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($file['display_name'] ?? $file['original_name']); ?>
                                    </div>
                                    <div id="filename-edit-<?php echo $file['id']; ?>" style="display: none; margin-bottom: 0.5rem;">
                                        <input type="text" id="filename-input-<?php echo $file['id']; ?>" 
                                               value="<?php echo htmlspecialchars($file['display_name'] ?? $file['original_name']); ?>"
                                               style="width: 100%; padding: 0.25rem; border: 1px solid <?php echo $border_color; ?>; border-radius: 4px; font-size: 0.9rem; text-align: center;">
                                        <div style="margin-top: 0.5rem;">
                                            <button onclick="saveFileName(<?php echo $file['id']; ?>)" 
                                                    style="background: <?php echo $border_color; ?>; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; margin-right: 0.25rem; cursor: pointer;">
                                                <i class="fas fa-check"></i> Sauver
                                            </button>
                                            <button onclick="cancelEdit(<?php echo $file['id']; ?>)" 
                                                    style="background: var(--text-secondary); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; cursor: pointer;">
                                                <i class="fas fa-times"></i> Annuler
                                            </button>
                                        </div>
                                    </div>
                                    <div style="display: inline-block; background: <?php echo $category_color; ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.75rem;">
                                        <?php echo ucfirst($file['file_category']); ?>
                                    </div>
                                </div>
                                
                                <?php if (isset($file['file_description']) && $file['file_description']): ?>
                                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.75rem; text-align: center; line-height: 1.4;">
                                        <?php echo htmlspecialchars($file['file_description']); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1rem; text-align: center; line-height: 1.3;">
                                    <div><strong>Taille:</strong> <?php echo number_format($file['file_size'] / 1024, 1); ?> KB</div>
                                    <div><strong>Ajouté:</strong> <?php echo date('d/m/Y H:i', strtotime($file['uploaded_at'])); ?></div>
                                </div>
                                
                                <div style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                                    <button onclick="editFileName(<?php echo $file['id']; ?>)" 
                                            style="background: var(--text-secondary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 0.25rem;">
                                        <i class="fas fa-edit"></i>
                                        <span>Renommer</span>
                                    </button>
                                    <a href="download_project_file.php?id=<?php echo $file['id']; ?>" 
                                       class="btn btn-sm" 
                                       style="background: <?php echo $border_color; ?>; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; display: flex; align-items: center; gap: 0.25rem;">
                                        <i class="fas fa-download"></i>
                                        <span>Télécharger</span>
                                    </a>
                                    <form method="POST" action="delete_project_file.php" style="display: inline;" data-delete-file-form>
                                        <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                        <button type="button" 
                                                style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 0.25rem;"
                                                onclick="openDeleteFileModal(<?php echo (int)$file['id']; ?>, '<?php echo htmlspecialchars(addslashes($file['display_name'] ?? $file['original_name'] ?? 'Fichier'), ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i>
                                            <span>Supprimer</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="modern-card" style="text-align: center; padding: 3rem;">
                    <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--border-color); margin-bottom: 1rem;"></i>
                    <h3 style="color: var(--text-secondary); margin-bottom: 0.5rem;">Aucun fichier ajouté</h3>
                    <p style="color: var(--text-muted);">Téléchargez des documents, photos ou schémas pour votre projet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <!-- Modal de modification de fichier -->
    <div id="editFileModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div style="background-color: var(--bg-card); margin: 5% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 600;">
                    <i class="fas fa-edit" style="margin-right: 0.5rem;"></i>
                    Modifier le fichier
                </h3>
                <button onclick="closeEditFileModal()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.2)'" onmouseout="this.style.backgroundColor='transparent'">
                    &times;
                </button>
            </div>
            <form id="editFileForm" method="POST" enctype="multipart/form-data" style="padding: 2rem;">
                <input type="hidden" name="action" value="edit_file">
                <input type="hidden" name="file_id" id="edit_file_id">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Nom du fichier</label>
                    <input type="text" name="display_name" id="edit_display_name" required 
                           style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Description</label>
                    <textarea name="description" id="edit_description" rows="3" 
                              style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; resize: vertical; transition: border-color 0.2s;"
                              onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e5e7eb'"
                              placeholder="Description du fichier (optionnel)"></textarea>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #374151;">Remplacer le fichier (optionnel)</label>
                    <input type="file" name="new_file" id="edit_new_file" 
                           style="width: 100%; padding: 0.75rem; border: 2px dashed #e5e7eb; border-radius: 8px; background: var(--bg-primary); transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#e5e7eb'">
                    <small style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem; display: block;">
                        Laissez vide pour conserver le fichier actuel
                    </small>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                    <button type="button" onclick="closeEditFileModal()" 
                            style="background: var(--text-secondary); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 500; transition: background-color 0.2s;"
                            onmouseover="this.style.backgroundColor='#4b5563'" onmouseout="this.style.backgroundColor='#6b7280'">
                        <i class="fas fa-times" style="margin-right: 0.5rem;"></i>
                        Annuler
                    </button>
                    <button type="button" onclick="submitEditFile()" 
                            style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 500; transition: transform 0.2s;"
                            onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fas fa-save" style="margin-right: 0.5rem;"></i>
                        Sauvegarder
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de suppression de fichier -->
    <div class="del-modal-backdrop" id="delFileModalBackdrop">
        <div class="del-modal">
            <div class="del-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 style="margin: 0 0 0.5rem 0; color: #1f2937; font-size: 1.35rem;">Supprimer ce fichier ?</h3>
            <div class="del-modal-filename" id="delFileName"></div>
            <div class="del-modal-warnings">
                <i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 0.5rem;"></i>
                <strong>Action irréversible.</strong> Le fichier sera définitivement supprimé de votre dossier projet ainsi que de la base de données.
            </div>
            <form method="POST" action="delete_project_file.php" id="delFileForm">
                <input type="hidden" name="file_id" id="delFileId">
                <input type="hidden" name="project_id" id="delFileProjectId" value="<?php echo $project_id; ?>">
            </form>
            <div class="del-modal-actions">
                <button type="button" class="del-btn cancel" onclick="closeDeleteFileModal()">
                    <i class="fas fa-times" style="margin-right: 0.4rem;"></i>
                    Annuler
                </button>
                <button type="button" class="del-btn confirm" onclick="confirmDeleteFile()">
                    <i class="fas fa-trash-alt" style="margin-right: 0.4rem;"></i>
                    Oui, supprimer définitivement
                </button>
            </div>
        </div>
    </div>

    <script>
        // Gestion des onglets
        function showTab(tabName) {
            // Fallback : si l'onglet demandé n'existe pas, revenir à overview
            const testContent = document.getElementById(tabName);
            if (!testContent) {
                tabName = 'overview';
            }
            // Masquer tous les contenus
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Désactiver tous les boutons
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => {
                button.classList.remove('active');
            });
            
            // Afficher le contenu sélectionné
            const selectedContent = document.getElementById(tabName);
            if (selectedContent) {
                selectedContent.classList.add('active');
            }
            
            // Activer le bouton sélectionné
            const selectedButton = document.querySelector(`[onclick="showTab('${tabName}')"]`);
            if (selectedButton) {
                selectedButton.classList.add('active');
            }
        }

        // Afficher l'onglet approprié au chargement
        document.addEventListener('DOMContentLoaded', function() {
            // Vérifier d'abord s'il y a un paramètre tab dans l'URL (priorité)
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            
            if (tabParam) {
                showTab(tabParam);
                return;
            }
            
            // Sinon, vérifier s'il y a une ancre dans l'URL
            if (window.location.hash === '#files') {
                showTab('files');
                return;
            }
            if (window.location.hash === '#components') {
                showTab('components');
                return;
            }
            if (window.location.hash === '#materials') {
                showTab('materials');
                return;
            }
            
            // Par défaut, afficher l'onglet overview
            showTab('overview');
        });

        // Autocomplétion pour les composants
        function setupAutocomplete() {
            const input = document.getElementById('component_name');
            if (!input) return;
            
            const availableComponents = <?php echo json_encode(array_column($available_components, 'name')); ?>;
            
            input.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                const suggestions = availableComponents.filter(comp => 
                    comp.toLowerCase().includes(value)
                ).slice(0, 5);
                
                // Afficher les suggestions (implémentation basique)
                console.log('Suggestions:', suggestions);
            });
        }

        // Fonction pour gérer la barre de recherche des composants
        function setupComponentSearch() {
            const searchInput = document.getElementById('component_search');
            const hiddenInput = document.getElementById('selected_component_id');
            const suggestionsDiv = document.getElementById('component_suggestions');
            
            if (!searchInput) return;
            
            // Données des composants disponibles
            const components = <?php echo json_encode($available_components); ?>;
            
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                
                if (query.length < 1) {
                    suggestionsDiv.style.display = 'none';
                    hiddenInput.value = '';
                    return;
                }
                
                // Filtrer les composants
                const filtered = components.filter(comp => {
                    const name = comp.name.toLowerCase();
                    const manufacturer = (comp.manufacturer || '').toLowerCase();
                    return name.includes(query) || manufacturer.includes(query);
                });
                
                // Afficher les suggestions
                if (filtered.length > 0) {
                    suggestionsDiv.innerHTML = '';
                    filtered.slice(0, 10).forEach(comp => {
                        const div = document.createElement('div');
                        div.className = 'cz-sugg-item';
                        div.innerHTML = `
                            <div class="cz-sugg-name">${comp.name}</div>
                            <div class="cz-sugg-meta">
                                ${comp.manufacturer ? comp.manufacturer + ' - ' : ''}Stock: ${comp.quantity}
                            </div>
                        `;
                        
                        div.addEventListener('mouseenter', function() {
                            this.style.backgroundColor = '#17253f';
                        });
                        
                        div.addEventListener('mouseleave', function() {
                            this.style.backgroundColor = '#0d182c';
                        });
                        
                        div.addEventListener('click', function() {
                            searchInput.value = comp.name + (comp.manufacturer ? ' - ' + comp.manufacturer : '');
                            hiddenInput.value = comp.id;
                            suggestionsDiv.style.display = 'none';
                        });
                        
                        suggestionsDiv.appendChild(div);
                    });
                    suggestionsDiv.style.display = 'block';
                } else {
                    suggestionsDiv.innerHTML = '<div class="cz-sugg-empty">Aucun composant trouvé</div>';
                    suggestionsDiv.style.display = 'block';
                    hiddenInput.value = '';
                }
            });
            
            // Fermer les suggestions en cliquant ailleurs
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                    suggestionsDiv.style.display = 'none';
                }
            });
            
            // Gérer les touches du clavier
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    suggestionsDiv.style.display = 'none';
                }
            });
        }
        
        // Initialiser l'autocomplétion et la recherche de composants
        document.addEventListener('DOMContentLoaded', function() {
            setupAutocomplete();
            setupComponentSearch();
        });

        // ====== MISES À JOUR AJAX (PAS DE RELOAD, PAS DE SAUT D'ONGLET) ======

        const CURRENT_PROJECT_ID = parseInt(document.body.getAttribute('data-project-id') || '0', 10);

        // === Aide : met à jour TOUTES les <tr> d'un composant (overview + onglet) ===
        function formatProgressPercent(value) {
            const num = Number(value || 0);
            return `${num.toFixed(1)}%`;
        }

        function formatProjectQuantity(value) {
            const num = Number(value || 0);
            return num.toLocaleString('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2
            });
        }

        function updateProjectSummary(data) {
            if (typeof data.total_used === 'undefined' || typeof data.total_needed === 'undefined') {
                return;
            }
            const totalUsedNodes = document.querySelectorAll('[data-project-total-used]');
            totalUsedNodes.forEach(el => { el.textContent = data.total_used; });

            const totalNeededNodes = document.querySelectorAll('[data-project-total-needed]');
            totalNeededNodes.forEach(el => { el.textContent = data.total_needed; });

            if (typeof data.items_completed_quantity !== 'undefined') {
                document.querySelectorAll('[data-project-items-completed]').forEach(el => {
                    el.textContent = formatProjectQuantity(data.items_completed_quantity);
                });
            }

            if (typeof data.items_total_quantity !== 'undefined') {
                document.querySelectorAll('[data-project-items-total]').forEach(el => {
                    el.textContent = formatProjectQuantity(data.items_total_quantity);
                });
            }

            if (typeof data.global_progress_percent !== 'undefined') {
                document.querySelectorAll('[data-project-progress-fill]').forEach(el => {
                    el.style.width = `${data.global_progress_percent}%`;
                });
                document.querySelectorAll('[data-project-progress-label]').forEach(el => {
                    el.textContent = formatProgressPercent(data.global_progress_percent);
                });
            }

            document.querySelectorAll('[data-project-progress-summary]').forEach(el => {
                const itemsCompleted = typeof data.items_completed_quantity !== 'undefined'
                    ? formatProjectQuantity(data.items_completed_quantity)
                    : (el.querySelector('[data-project-items-completed]')?.textContent || '0');
                const itemsTotal = typeof data.items_total_quantity !== 'undefined'
                    ? formatProjectQuantity(data.items_total_quantity)
                    : (el.querySelector('[data-project-items-total]')?.textContent || '0');
                el.innerHTML = `<span data-project-total-used>${data.total_used}</span> composants utilisés sur <span data-project-total-needed>${data.total_needed}</span> nécessaires • <span data-project-items-completed>${itemsCompleted}</span> / <span data-project-items-total>${itemsTotal}</span> travaux & matériaux`;
            });
        }

        function syncComponentForms(pcId, quantityUsed, quantityNeeded) {
            document.querySelectorAll(`[data-quantity-form][data-pc-id="${pcId}"]`).forEach(form => {
                const actionType = form.getAttribute('data-action-type') || '';
                const hiddenQty = form.querySelector('input[name="quantity_used"][type="hidden"]');
                const manualQty = form.querySelector('input[name="quantity_used"][data-qty-input]');
                if (manualQty) {
                    manualQty.value = quantityUsed;
                    manualQty.max = quantityNeeded;
                }
                if (hiddenQty) {
                    if (actionType === 'minus') {
                        hiddenQty.value = Math.max(0, quantityUsed - 1);
                    } else if (actionType === 'plus') {
                        hiddenQty.value = Math.min(quantityNeeded, quantityUsed + 1);
                    }
                }
            });

            document.querySelectorAll(`[data-needed-form][data-pc-id="${pcId}"]`).forEach(form => {
                const actionType = form.getAttribute('data-needed-action') || '';
                const manualNeeded = form.querySelector('input[name="quantity_needed"][data-needed-input]');
                if (manualNeeded) {
                    manualNeeded.value = quantityNeeded;
                    manualNeeded.min = 1;
                }
                const changeInput = form.querySelector('input[name="change"]');
                if (changeInput) {
                    changeInput.value = actionType === 'minus' ? '-1' : '1';
                }
            });
        }

        function updateAllComponentRows(pcId, data) {
            const canMinusNeeded = data.quantity_needed > 1;
            document.querySelectorAll(`tr[data-component-row="${pcId}"]`).forEach(row => {
                row.querySelectorAll('[data-qty-display]').forEach(el => {
                    el.textContent = `${data.quantity_used} / ${data.quantity_needed}`;
                });
                row.querySelectorAll('[data-qty-input]').forEach(el => {
                    el.value = data.quantity_used;
                    el.max = data.quantity_needed;
                });
                row.querySelectorAll('[data-qty-suffix]').forEach(el => {
                    el.textContent = `/ ${data.quantity_needed}`;
                });
                row.querySelectorAll('[data-needed-input]').forEach(el => {
                    el.value = data.quantity_needed;
                    el.min = 1;
                });
                row.querySelectorAll('[data-progress-fill]').forEach(el => {
                    el.style.width = `${data.progress_percent}%`;
                });
                row.querySelectorAll('[data-progress-text]').forEach(el => {
                    el.textContent = formatProgressPercent(data.progress_percent);
                });
                const canMinusUsed = (data.can_minus_used !== undefined) ? !!data.can_minus_used : !!data.can_minus;
                const canPlusUsed = (data.can_plus_used !== undefined) ? !!data.can_plus_used : !!data.can_plus;
                row.querySelectorAll('[data-qty-btn="minus"]').forEach(btn => {
                    btn.disabled = !canMinusUsed;
                });
                row.querySelectorAll('[data-qty-btn="plus"]').forEach(btn => {
                    btn.disabled = !canPlusUsed;
                });
                row.querySelectorAll('[data-qty-btn="check"]').forEach(btn => {
                    btn.disabled = false;
                });
                row.querySelectorAll('[data-needed-btn="minus"]').forEach(btn => {
                    btn.disabled = !canMinusNeeded;
                });
                row.querySelectorAll('[data-needed-btn="plus"]').forEach(btn => {
                    btn.disabled = false;
                });
                row.querySelectorAll('[data-needed-btn="check"]').forEach(btn => {
                    btn.disabled = false;
                });
                row.querySelectorAll('[data-total-cost]').forEach(el => {
                    if (data.total_cost !== undefined) el.textContent = `${data.total_cost}€`;
                });
            });

            // Mettre à jour aussi les formulaires nécessaires hors <tr> (modales, etc.)
            document.querySelectorAll(`[data-needed-form][data-pc-id="${pcId}"]`).forEach(form => {
                const minusBtn = form.querySelector('[data-needed-btn="minus"]');
                if (minusBtn) minusBtn.disabled = !canMinusNeeded;
            });
            syncComponentForms(pcId, data.quantity_used, data.quantity_needed);
            updateProjectSummary(data);
        }

        // === Aide : met à jour TOUTES les <tr> d'un project_items (overview + onglet) ===
        function updateAllItemRows(itemId, data) {
            document.querySelectorAll(`tr[data-item-row="${itemId}"]`).forEach(row => {
                row.querySelectorAll('[data-progress-fill]').forEach(el => {
                    el.style.width = `${data.progress_percent}%`;
                });
                row.querySelectorAll('[data-qty-completed]').forEach(el => {
                    const decimals = String(data.completed_quantity).includes('.') ? 2 : 1;
                    el.textContent = Number(data.completed_quantity).toFixed(decimals);
                });
                row.querySelectorAll('[data-qty-total]').forEach(el => {
                    const decimals = String(data.total_quantity).includes('.') ? 2 : 1;
                    el.textContent = Number(data.total_quantity).toFixed(decimals);
                });
                row.querySelectorAll('[data-qty-unit]').forEach(el => {
                    el.textContent = data.unit ?? '';
                });
                row.querySelectorAll('[data-progress-text]').forEach(text => {
                    const decimals = String(data.completed_quantity).includes('.') ? 2 : 1;
                    const qtyTotal = Number(data.total_quantity).toFixed(
                        String(data.total_quantity).includes('.') ? 2 : 1
                    );
                    const qtyComp = Number(data.completed_quantity).toFixed(decimals);
                    text.textContent = `${qtyComp} / ${qtyTotal} ${data.unit ?? ''}`;
                    // Progress % ? on garde la forme actuelle /
                });
                row.querySelectorAll('[data-item-btn-minus]').forEach(btn => {
                    btn.disabled = !data.can_minus;
                });
                row.querySelectorAll('[data-item-btn-plus]').forEach(btn => {
                    btn.disabled = !data.can_plus;
                });
            });
        }

        // === Intercepter TOUS les forms "update_quantity_used" (composants) en AJAX ===
        document.addEventListener('submit', async function(e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            const action = form.querySelector('input[name="action"]');
            if (!action || action.value !== 'update_quantity_used') return;
            // C'est bien un form de quantité utilisée composant → on l'intercepte !
            e.preventDefault();
            e.stopPropagation();

            const fd = new FormData(form);
            const pcId = parseInt(fd.get('pc_id') || '0', 10);
            let qtyUsed = parseInt(fd.get('quantity_used') || '0', 10);
            if (!pcId) return;
            if (isNaN(qtyUsed) || qtyUsed < 0) qtyUsed = 0;

            // Désactiver TOUS les boutons associés pendant l'AJAX
            const affectedButtons = document.querySelectorAll(
                `[data-quantity-form][data-pc-id="${pcId}"] [type="submit"],
                 tr[data-component-row="${pcId}"] [data-qty-btn]`
            );
            affectedButtons.forEach(b => b.disabled = true);

            try {
                const resp = await fetch('ajax_update_project_quantity.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        project_id: String(CURRENT_PROJECT_ID),
                        pc_id: String(pcId),
                        quantity_used: String(qtyUsed)
                    })
                });
                const txt = await resp.text();
                let data = null;
                try { data = JSON.parse(txt); }
                catch (eX) {
                    const m = txt.match(/\{[\s\S]*\}/);
                    if (m) data = JSON.parse(m[0]);
                }
                if (data && data.success) {
                    updateAllComponentRows(pcId, data);
                } else {
                    alert('Erreur: ' + (data?.message || 'Mise à jour échouée'));
                    affectedButtons.forEach(b => b.removeAttribute('disabled'));
                }
            } catch (err) {
                alert('Erreur réseau: ' + (err?.message || err));
                affectedButtons.forEach(b => b.removeAttribute('disabled'));
            }
        }, true);

        // === Intercepter TOUS les forms "update_quantity_needed" (composants) en AJAX ===
        document.addEventListener('submit', async function(e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            const actionInput = form.querySelector('input[name="action"]');
            if (!actionInput || actionInput.value !== 'update_quantity_needed') return;
            e.preventDefault();
            e.stopPropagation();

            const fd = new FormData(form);
            const pcId = parseInt(fd.get('pc_id') || '0', 10);
            let quantityNeeded = parseInt(fd.get('quantity_needed') || '0', 10);
            let change = parseInt(fd.get('change') || '0', 10);
            if (!pcId) return;

            const affectedButtons = document.querySelectorAll(
                `[data-needed-form][data-pc-id="${pcId}"] [type="submit"],
                 tr[data-component-row="${pcId}"] [data-needed-btn]`
            );
            affectedButtons.forEach(b => b.disabled = true);

            const body = new URLSearchParams({
                project_id: String(CURRENT_PROJECT_ID),
                pc_id: String(pcId),
            });
            if (!isNaN(change) && change !== 0) {
                body.append('change', String(change));
            } else if (!isNaN(quantityNeeded) && quantityNeeded > 0) {
                body.append('quantity_needed', String(quantityNeeded));
            } else {
                body.append('change', '0');
            }

            try {
                const resp = await fetch('ajax_update_project_component_needed.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body
                });
                const txt = await resp.text();
                let data = null;
                try { data = JSON.parse(txt); }
                catch (eX) {
                    const m = txt.match(/\{[\s\S]*\}/);
                    if (m) data = JSON.parse(m[0]);
                }
                if (data && data.success) {
                    updateAllComponentRows(pcId, data);
                    if (data.used_adjusted) {
                        console.warn(`Quantité utilisée ajustée automatiquement à ${data.quantity_used} car besoin réduit`);
                    }
                } else {
                    alert('Erreur: ' + (data?.message || 'Mise à jour échouée'));
                    affectedButtons.forEach(b => b.removeAttribute('disabled'));
                }
            } catch (err) {
                alert('Erreur réseau: ' + (err?.message || err));
                affectedButtons.forEach(b => b.removeAttribute('disabled'));
            }
        }, true);

        // === Nouvelle fonction updateProgress : AJAX, PLUS DE submit form => pas de reload / saut ===
        function updateProgress(itemId, change) {
            if (!CURRENT_PROJECT_ID) return;
            itemId = parseInt(itemId, 10);
            change = parseInt(change, 10);
            if (!itemId) return;

            // Désactiver temporairement les 2 boutons (overview + onglet)
            const btns = document.querySelectorAll(
                `tr[data-item-row="${itemId}"] [data-item-btn-minus],
                 tr[data-item-row="${itemId}"] [data-item-btn-plus]`
            );
            btns.forEach(b => b.disabled = true);

            fetch('ajax_update_project_progress.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({
                    project_id: String(CURRENT_PROJECT_ID),
                    item_id: String(itemId),
                    progress_change: String(change)
                })
            })
            .then(r => r.text())
            .then(txt => {
                let data = null;
                try { data = JSON.parse(txt); }
                catch (eX) {
                    const m = txt.match(/\{[\s\S]*\}/);
                    if (m) data = JSON.parse(m[0]);
                }
                if (data && data.success) {
                    updateAllItemRows(itemId, data);
                    updateProjectSummary(data);
                } else {
                    alert('Erreur: ' + (data?.message || 'Progression échouée'));
                    btns.forEach(b => b.removeAttribute('disabled'));
                }
            })
            .catch(err => {
                alert('Erreur réseau: ' + (err?.message || err));
                btns.forEach(b => b.removeAttribute('disabled'));
            });
        }

        // Fonctions pour le renommage des fichiers
        function editFileName(fileId) {
            document.getElementById('filename-display-' + fileId).style.display = 'none';
            document.getElementById('filename-edit-' + fileId).style.display = 'block';
            document.getElementById('filename-input-' + fileId).focus();
        }

        function cancelEdit(fileId) {
            document.getElementById('filename-display-' + fileId).style.display = 'block';
            document.getElementById('filename-edit-' + fileId).style.display = 'none';
        }

        function saveFileName(fileId) {
            const newName = document.getElementById('filename-input-' + fileId).value.trim();
            if (!newName) {
                alert('Le nom du fichier ne peut pas être vide.');
                return;
            }

            // Créer un formulaire pour envoyer la requête
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // Ajouter les champs cachés
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'rename_file';
            form.appendChild(actionInput);
            
            const fileIdInput = document.createElement('input');
            fileIdInput.type = 'hidden';
            fileIdInput.name = 'file_id';
            fileIdInput.value = fileId;
            form.appendChild(fileIdInput);
            
            const newNameInput = document.createElement('input');
            newNameInput.type = 'hidden';
            newNameInput.name = 'new_name';
            newNameInput.value = newName;
            form.appendChild(newNameInput);
            
            // Ajouter le formulaire au document et le soumettre
            document.body.appendChild(form);
            form.submit();
        }
        
        // Fonctions pour l'édition dans la vue d'ensemble
        function editFileNameOverview(fileId) {
            document.getElementById('filename-display-overview-' + fileId).style.display = 'none';
            document.getElementById('filename-edit-overview-' + fileId).style.display = 'block';
            document.getElementById('filename-input-overview-' + fileId).focus();
        }
        
        function saveFileNameOverview(fileId) {
            const newName = document.getElementById('filename-input-overview-' + fileId).value.trim();
            if (!newName) {
                alert('Le nom du fichier ne peut pas être vide.');
                return;
            }

            // Créer un formulaire pour envoyer la requête
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            // Ajouter les champs cachés
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'rename_file';
            form.appendChild(actionInput);
            
            const fileIdInput = document.createElement('input');
            fileIdInput.type = 'hidden';
            fileIdInput.name = 'file_id';
            fileIdInput.value = fileId;
            form.appendChild(fileIdInput);
            
            const newNameInput = document.createElement('input');
            newNameInput.type = 'hidden';
            newNameInput.name = 'new_name';
            newNameInput.value = newName;
            form.appendChild(newNameInput);
            
            // Ajouter le formulaire au document et le soumettre
            document.body.appendChild(form);
            form.submit();
        }
        
        function cancelEditOverview(fileId) {
            document.getElementById('filename-display-overview-' + fileId).style.display = 'block';
            document.getElementById('filename-edit-overview-' + fileId).style.display = 'none';
        }
        
        // Fonctions pour la modal de modification de fichier
        function openEditFileModal(fileId, displayName, description) {
            document.getElementById('edit_file_id').value = fileId;
            document.getElementById('edit_display_name').value = displayName;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_new_file').value = '';
            document.getElementById('editFileModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditFileModal() {
            document.getElementById('editFileModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Fermer la modale en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('editFileModal');
            if (event.target === modal) {
                closeEditFileModal();
            }
        }
        
        // Gérer la soumission du formulaire de modification de fichier
        function submitEditFile() {
            const form = document.getElementById('editFileForm');
            const displayName = document.getElementById('edit_display_name').value.trim();
            
            // Validation
            if (!displayName) {
                alert('Le nom du fichier ne peut pas être vide.');
                return;
            }
            
            const formData = new FormData(form);
            
            // Ajouter l'action
            formData.append('action', 'edit_file');
            
            // Afficher un indicateur de chargement
            const submitBtn = document.querySelector('#editFileModal button[onclick="submitEditFile()"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Modification en cours...';
            submitBtn.disabled = true;
            
            fetch('project_detail.php?id=<?php echo $project_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Recharger la page pour voir les modifications
                    window.location.reload();
                } else {
                    throw new Error('Erreur lors de la modification');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la modification du fichier');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Fonctions pour la modal de suppression de fichier
        function openDeleteFileModal(fileId, fileName) {
            document.getElementById('delFileId').value = fileId;
            document.getElementById('delFileName').textContent = fileName;
            document.getElementById('delFileModalBackdrop').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteFileModal() {
            document.getElementById('delFileModalBackdrop').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function confirmDeleteFile() {
            document.getElementById('delFileForm').submit();
        }

        // Fermer la modale suppression en cliquant à l'extérieur
        document.addEventListener('click', function(event) {
            const backdrop = document.getElementById('delFileModalBackdrop');
            if (event.target === backdrop) {
                closeDeleteFileModal();
            }
        });

        // Fermer la modale suppression avec la touche Échap
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && document.getElementById('delFileModalBackdrop').classList.contains('active')) {
                closeDeleteFileModal();
            }
        });
        
        // Fonctions pour la modification d'éléments
        function editItem(itemId, name, type, quantity, unit, unitPrice, description) {
            document.getElementById('edit_item_id').value = itemId;
            document.getElementById('edit_item_name').value = name;
            document.getElementById('edit_item_type').value = type;
            document.getElementById('edit_item_quantity').value = quantity;
            document.getElementById('edit_item_unit').value = unit;
            document.getElementById('edit_item_unit_price').value = unitPrice;
            document.getElementById('edit_item_description').value = description;
            document.getElementById('editItemModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditItemModal() {
            document.getElementById('editItemModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function submitEditItem() {
            const form = document.getElementById('editItemForm');
            const itemName = document.getElementById('edit_item_name').value.trim();
            
            // Validation
            if (!itemName) {
                alert('Le nom de l\'élément ne peut pas être vide.');
                return;
            }
            
            const formData = new FormData(form);
            formData.append('action', 'edit_project_item');
            
            // Afficher un indicateur de chargement
            const submitBtn = document.querySelector('#editItemModal button[onclick="submitEditItem()"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Modification en cours...';
            submitBtn.disabled = true;
            
            fetch('project_detail.php?id=<?php echo $project_id; ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.href = 'project_detail.php?id=<?php echo $project_id; ?>&tab=materials&success=item_updated#materials';
                } else {
                    throw new Error('Erreur lors de la modification');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la modification de l\'élément');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }
    </script>
    
    <!-- Modale de modification d'élément -->
    <div id="editItemModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div style="background-color: var(--light-color); margin: 5% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
            <div style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; padding: 1.5rem; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 1.5rem; font-weight: 600;">
                    <i class="fas fa-edit"></i>
                    Modifier l'élément
                </h2>
                <button onclick="closeEditItemModal()" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; padding: 0.5rem; border-radius: 50%; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor='rgba(255,255,255,0.2)'" onmouseout="this.style.backgroundColor='transparent'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="editItemForm" style="padding: 2rem;">
                <input type="hidden" id="edit_item_id" name="item_id">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Type</label>
                        <select id="edit_item_type" name="item_type" class="form-select" required>
                            <option value="">Sélectionner un type...</option>
                            <option value="travail">Travail</option>
                            <option value="materiel">Matériel</option>
                            <option value="service">Service</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Nom</label>
                        <input type="text" id="edit_item_name" name="item_name" class="form-input" required placeholder="Nom de l'élément">
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Quantité</label>
                        <input type="number" id="edit_item_quantity" name="item_quantity" class="form-input" step="0.01" min="0.01" required>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Unité</label>
                        <input type="text" id="edit_item_unit" name="item_unit" class="form-input" required placeholder="ex: pièces, heures, m²">
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Prix unitaire (€)</label>
                        <input type="number" id="edit_item_unit_price" name="item_unit_price" class="form-input" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color);">Description (optionnel)</label>
                    <textarea id="edit_item_description" name="item_description" class="form-textarea" rows="3" placeholder="Description de l'élément..."></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="closeEditItemModal()" style="padding: 0.75rem 1.5rem; border: 2px solid var(--border-color); background: transparent; color: var(--text-color); border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.backgroundColor='var(--light-color)'" onmouseout="this.style.backgroundColor='transparent'">
                        Annuler
                    </button>
                    <button type="button" onclick="submitEditItem()" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fas fa-save"></i>
                        Modifier l'élément
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL UPLOAD IMAGE PROJET -->
    <div class="image-modal-backdrop" id="imageModalBackdrop">
        <div class="image-modal" onclick="event.stopPropagation()">
            <h3><i class="fas fa-camera" style="color:#667eea;margin-right:8px;"></i>Image du projet</h3>
            
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('projectImageInput').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <p><b>Cliquez pour choisir</b> ou glissez-déposez une image</p>
                <p class="small">Formats acceptés : JPG, PNG, GIF, WebP — Max 10 Mo</p>
                <input type="file" id="projectImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
            </div>
            
            <div id="fileNameDisplay" class="file-name" style="display:none;"></div>
            
            <div class="upload-progress" id="uploadProgress">
                <div style="font-size:0.85rem;color:#475569;font-weight:600;">
                    <i class="fas fa-spinner fa-spin" style="margin-right:6px;color:#667eea;"></i>
                    <span id="uploadStatusText">Envoi en cours...</span>
                </div>
                <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
            </div>
            
            <div class="upload-success-msg" id="uploadSuccessMsg">
                <i class="fas fa-check-circle" style="margin-right:6px;"></i>
                <span id="successText">Image mise à jour avec succès !</span>
            </div>
            <div class="upload-error-msg" id="uploadErrorMsg">
                <i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>
                <span id="errorText">Erreur inconnue</span>
            </div>
            
            <div class="image-modal-actions">
                <button type="button" class="modal-btn cancel" onclick="closeImageModal()">Annuler</button>
                <button type="button" id="confirmUploadBtn" class="modal-btn primary" disabled onclick="confirmUpload()">
                    <i class="fas fa-upload"></i> Envoyer l'image
                </button>
            </div>
        </div>
    </div>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>

    <script>
    if (typeof CURRENT_PROJECT_ID === 'undefined' || !CURRENT_PROJECT_ID) {
        window.CURRENT_PROJECT_ID = <?php echo (int)$project_id; ?>;
    }
    
    let selectedFile = null;
    
    function openImageModal() {
        resetImageModal();
        document.getElementById('imageModalBackdrop').classList.add('active');
    }
    
    function closeImageModal() {
        document.getElementById('imageModalBackdrop').classList.remove('active');
        setTimeout(resetImageModal, 250);
    }
    
    function resetImageModal() {
        selectedFile = null;
        document.getElementById('projectImageInput').value = '';
        document.getElementById('fileNameDisplay').style.display = 'none';
        document.getElementById('fileNameDisplay').textContent = '';
        document.getElementById('confirmUploadBtn').disabled = true;
        document.getElementById('uploadProgress').classList.remove('active');
        document.getElementById('progressFill').style.width = '0%';
        document.getElementById('uploadSuccessMsg').classList.remove('show');
        document.getElementById('uploadErrorMsg').classList.remove('show');
        if (document.getElementById('dropZone')) document.getElementById('dropZone').classList.remove('dragover');
    }
    
    document.getElementById('imageModalBackdrop').addEventListener('click', function(e) {
        if (e.target === this) closeImageModal();
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('imageModalBackdrop').classList.contains('active')) {
            closeImageModal();
        }
    });
    
    const fileInput = document.getElementById('projectImageInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            handleFileSelect(e.target.files[0]);
        });
    }
    
    const dropZone = document.getElementById('dropZone');
    if (dropZone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, () => dropZone.classList.add('dragover'));
        });
        
        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover'));
        });
        
        dropZone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files[0]) handleFileSelect(dt.files[0]);
        });
    }
    
    function handleFileSelect(file) {
        if (!file) return;
        
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            showErrorMsg('Type de fichier invalide. Utilisez JPG, PNG, GIF ou WebP.');
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            showErrorMsg('Fichier trop volumineux. Maximum 10 Mo.');
            return;
        }
        
        selectedFile = file;
        document.getElementById('fileNameDisplay').style.display = 'block';
        document.getElementById('fileNameDisplay').innerHTML = '<i class="fas fa-file-image" style="margin-right:6px;color:#667eea;"></i>' +
            file.name + ' <span style="color:#94a3b8;">(' + formatFileSize(file.size) + ')</span>';
        document.getElementById('confirmUploadBtn').disabled = false;
        document.getElementById('uploadErrorMsg').classList.remove('show');
        document.getElementById('uploadSuccessMsg').classList.remove('show');
    }
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }
    
    function showErrorMsg(msg) {
        const el = document.getElementById('uploadErrorMsg');
        document.getElementById('errorText').textContent = msg;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), 5000);
    }
    
    function showSuccessMsg(msg) {
        const el = document.getElementById('uploadSuccessMsg');
        document.getElementById('successText').textContent = msg || 'Image mise à jour avec succès !';
        el.classList.add('show');
    }
    
    function confirmUpload() {
        if (!selectedFile) return;
        
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('project_id', CURRENT_PROJECT_ID);
        formData.append('project_image', selectedFile);
        
        const confirmBtn = document.getElementById('confirmUploadBtn');
        const progress = document.getElementById('uploadProgress');
        const progressFill = document.getElementById('progressFill');
        const statusText = document.getElementById('uploadStatusText');
        
        confirmBtn.disabled = true;
        progress.classList.add('active');
        document.getElementById('uploadErrorMsg').classList.remove('show');
        document.getElementById('uploadSuccessMsg').classList.remove('show');
        progressFill.style.width = '20%';
        statusText.textContent = 'Préparation de l\'envoi...';
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload_project_image.php', true);
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 80) + 20;
                progressFill.style.width = pct + '%';
                statusText.textContent = 'Envoi : ' + pct + '%';
            }
        };
        
        xhr.onloadstart = function() {
            progressFill.style.width = '15%';
        };
        
        xhr.onload = function() {
            progressFill.style.width = '100%';
            let resp = null;
            let rawResponse = xhr.responseText || '';
            try {
                // Tentative 1 : JSON.parse direct
                resp = JSON.parse(rawResponse);
            } catch (e) {
                // Tentative 2 : FALLBACK - extraire le JSON VALIDE même s'il y a du texte avant/après
                // (ex: BOM UTF-8, notice PHP, warning, echo parasite...)
                try {
                    let extracted = null;
                    const matchObj = rawResponse.match(/\{[\s\S]*\}/);
                    if (matchObj && matchObj[0]) extracted = matchObj[0];
                    if (!extracted) {
                        const matchArr = rawResponse.match(/\[[\s\S]*\]/);
                        if (matchArr && matchArr[0]) extracted = matchArr[0];
                    }
                    if (extracted) {
                        resp = JSON.parse(extracted);
                    }
                } catch (e2) {
                    resp = null;
                }
            }

            if (resp !== null) {
                if (resp.success) {
                    statusText.textContent = 'Terminé !';
                    showSuccessMsg(resp.message);
                    
                    const avatarImg = document.getElementById('projectAvatarImg');
                    if (avatarImg && resp.image_path) {
                        avatarImg.src = resp.image_path;
                    } else {
                        setTimeout(() => location.reload(), 900);
                        return;
                    }
                    
                    setTimeout(() => {
                        closeImageModal();
                    }, 1200);
                } else {
                    statusText.textContent = 'Erreur';
                    showErrorMsg(resp.message || 'Erreur inconnue');
                    confirmBtn.disabled = false;
                    progress.classList.remove('active');
                }
            } else {
                // ERREUR FINALE : impossible de parser même en fallback
                statusText.textContent = 'Erreur';
                let preview = rawResponse || '(réponse vide)';
                if (preview.length > 300) preview = preview.substring(0, 300) + '... [tronqué]';
                let statusInfo = xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
                const contentType = xhr.getResponseHeader && xhr.getResponseHeader('Content-Type') ? ' Content-Type: ' + xhr.getResponseHeader('Content-Type') : '';
                let fullMsg = 'Réponse invalide du serveur' + statusInfo + contentType + ':\n\n' + preview +
                    '\n\n--- ASTUCE --- Ouvrez la console (F12) > Network > la requête vers upload_project_image.php > Response pour voir la réponse complète, ou ouvrez le fichier upload_project_image_debug.log sur le serveur.';
                showErrorMsg('Réponse invalide - consultez upload_project_image_debug.log');
                console.error('=== ERREUR JSON PARSE ===');
                console.error('Raw response:', rawResponse);
                console.error('HTTP status:', xhr.status);
                console.error('Content-Type:', xhr.getResponseHeader ? xhr.getResponseHeader('Content-Type') : '?');
                alert(fullMsg);
                confirmBtn.disabled = false;
                progress.classList.remove('active');
            }
        };
        
        xhr.onerror = function() {
            statusText.textContent = 'Erreur réseau';
            showErrorMsg('Erreur réseau - vérifiez votre connexion');
            confirmBtn.disabled = false;
            progress.classList.remove('active');
        };
        
        xhr.send(formData);
    }
    
    function deleteProjectImage() {
        if (!confirm('Êtes-vous sûr de vouloir supprimer l\'image du projet ?')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', CURRENT_PROJECT_ID);
        
        fetch('upload_project_image.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(resp => {
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.message || 'Erreur');
                }
            })
            .catch(e => alert('Erreur: ' + e.message));
    }

    /* ===== MODALE RENOMMER PROJET (project_detail) ===== */
    function openRenameProjectModal(projectId, name, description, status) {
        document.getElementById('renameProjectDetailId').value = projectId;
        document.getElementById('renameProjectDetailCurrentName').textContent = String(name || '');
        const inpN = document.getElementById('renameProjectDetailName');
        const inpD = document.getElementById('renameProjectDetailDesc');
        const selS = document.getElementById('renameProjectDetailStatus');
        inpN.value = String(name || '');
        inpD.value = String(description || '');
        const wanted = String(status || 'En cours');
        let found = false;
        for (let i=0;i<selS.options.length;i++) {
            if (String(selS.options[i].value).toLowerCase() === wanted.toLowerCase()) {
                selS.selectedIndex = i; found = true; break;
            }
        }
        if (!found) selS.value = 'En cours';
        document.getElementById('renameProjectDetailOverlay').classList.add('open');
        setTimeout(() => inpN.focus(), 50);
    }
    function closeRenameProjectModal() {
        document.getElementById('renameProjectDetailOverlay').classList.remove('open');
    }
    document.addEventListener('click', function renameOverlayClick(e) {
        const ov = document.getElementById('renameProjectDetailOverlay');
        if (ov && e.target === ov) closeRenameProjectModal();
    });
    document.addEventListener('keydown', function renameOverlayEsc(e) {
        if (e.key === 'Escape' && document.getElementById('renameProjectDetailOverlay')?.classList.contains('open')) {
            closeRenameProjectModal();
        }
    });
    document.addEventListener('submit', function renameFormSubmit(e) {
        const f = e.target;
        if (f && f.id === 'renameProjectDetailForm') {
            const name = (f.querySelector('#renameProjectDetailName').value || '').trim();
            if (!name) { e.preventDefault(); alert('Le nom du projet est obligatoire.'); document.getElementById('renameProjectDetailName').focus(); return; }
        }
    }, true);
    </script>

    <!-- ===== MODALE RENOMMER PROJET ===== -->
    <div class="rename-project-modal-overlay" id="renameProjectDetailOverlay" role="dialog" aria-modal="true">
        <div class="rename-project-modal-box" role="document">
            <form method="POST" action="projects.php" id="renameProjectDetailForm">
                <input type="hidden" name="action" value="rename_project">
                <input type="hidden" name="return_to" value="detail">
                <input type="hidden" name="project_id" id="renameProjectDetailId" value="0">
                <div class="rename-project-modal-head">
                    <h3>✏️ Renommer / modifier le projet</h3>
                    <p>Mettez à jour le nom, la description ou le statut du projet</p>
                </div>
                <div class="rename-project-modal-body">
                    <div class="rename-form-oldname">Nom actuel : <span id="renameProjectDetailCurrentName">—</span></div>
                    <div class="rename-form-group">
                        <label for="renameProjectDetailName">Nom du projet <span style="color:#ef4444">*</span></label>
                        <input type="text" id="renameProjectDetailName" name="name" required maxlength="200"
                               placeholder="Ex: Projet Robotique">
                        <div class="rename-hint">
                            Le nom est utilisé pour le dossier physique <code>projets/NomDuProjet/</code>
                        </div>
                    </div>
                    <div class="rename-form-group">
                        <label for="renameProjectDetailDesc">Description</label>
                        <textarea id="renameProjectDetailDesc" name="description" rows="3"
                                  placeholder="Courte description du projet..."></textarea>
                    </div>
                    <div class="rename-form-group">
                        <label for="renameProjectDetailStatus">Statut</label>
                        <select id="renameProjectDetailStatus" name="status">
                            <option value="En cours">🚧 En cours</option>
                            <option value="Terminé">✅ Terminé</option>
                            <option value="En pause">⏸️ En pause</option>
                            <option value="Abandonné">❌ Abandonné</option>
                            <option value="Planifié">📋 Planifié</option>
                        </select>
                    </div>
                </div>
                <div class="rename-project-modal-foot">
                    <button type="button" class="rename-btn-cancel" onclick="closeRenameProjectModal()">
                        Annuler
                    </button>
                    <button type="submit" class="rename-btn-submit">
                        💾 Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    </div>
</body>
</html>
