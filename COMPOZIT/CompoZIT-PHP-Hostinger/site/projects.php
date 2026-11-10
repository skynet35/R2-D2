<?php
require_once 'session_init.php';
require_once 'config.php';

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

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_project':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $status = $_POST['status'] ?? 'En cours';
                
                if (!empty($name)) {
                    try {
                        $image_path = null;
                        
                        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
                        $project_folder_abs = __DIR__ . '/projets/' . $safe_name;
                        $project_folder_rel = 'projets/' . $safe_name;
                        
                        if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] === UPLOAD_ERR_OK) {
                            if (!is_dir($project_folder_abs)) {
                                mkdir($project_folder_abs, 0755, true);
                            }
                            
                            $file_extension = strtolower(pathinfo($_FILES['project_image']['name'], PATHINFO_EXTENSION));
                            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                            $max_size = 10 * 1024 * 1024;
                            
                            if (in_array($file_extension, $allowed_extensions) && $_FILES['project_image']['size'] <= $max_size) {
                                $target_path_abs = null;
                                $target_path_rel = null;
                                $compression_ok = false;
                                
                                if (extension_loaded('gd')) {
                                    $info = @getimagesize($_FILES['project_image']['tmp_name']);
                                    if ($info) {
                                        list($width, $height, $type) = $info;
                                        $max_w = 1920;
                                        $max_h = 1080;
                                        $ratio = min($max_w / $width, $max_h / $height, 1);
                                        $new_w = max(1, (int)round($width * $ratio));
                                        $new_h = max(1, (int)round($height * $ratio));
                                        
                                        $src = false;
                                        switch ($type) {
                                            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($_FILES['project_image']['tmp_name']); break;
                                            case IMAGETYPE_PNG: $src = @imagecreatefrompng($_FILES['project_image']['tmp_name']); break;
                                            case IMAGETYPE_GIF: $src = @imagecreatefromgif($_FILES['project_image']['tmp_name']); break;
                                            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($_FILES['project_image']['tmp_name']) : false; break;
                                        }
                                        
                                        if ($src) {
                                            $dst = imagecreatetruecolor($new_w, $new_h);
                                            if ($type === IMAGETYPE_PNG) {
                                                imagealphablending($dst, false);
                                                imagesavealpha($dst, true);
                                            }
                                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
                                            $filename = 'project_image_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                                            if (@imagejpeg($dst, $project_folder_abs . '/' . $filename, 92)) {
                                                $compression_ok = true;
                                                $target_path_rel = $project_folder_rel . '/' . $filename;
                                            }
                                            @imagedestroy($src);
                                            @imagedestroy($dst);
                                        }
                                    }
                                }
                                
                                if (!$compression_ok) {
                                    $filename = 'project_image_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
                                    if (move_uploaded_file($_FILES['project_image']['tmp_name'], $project_folder_abs . '/' . $filename)) {
                                        $target_path_rel = $project_folder_rel . '/' . $filename;
                                    }
                                }
                                
                                $image_path = $target_path_rel;
                            }
                        }
                        
                        if ($image_path) {
                            try {
                                $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status, image_path) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([$_SESSION['user_id'], $name, $description, $status, $image_path]);
                            } catch (Exception $e) {
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status, image_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                                    $stmt->execute([$_SESSION['user_id'], $name, $description, $status, $image_path]);
                                } catch (Exception $e2) {
                                    $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$_SESSION['user_id'], $name, $description, $status]);
                                }
                            }
                        } else {
                            try {
                                $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                                $stmt->execute([$_SESSION['user_id'], $name, $description, $status]);
                            } catch (Exception $e) {
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$_SESSION['user_id'], $name, $description, $status]);
                                } catch (Exception $e2) {
                                    $stmt = $pdo->prepare("INSERT INTO projects (owner, name, description, status, image_path, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NOW(), NOW())");
                                    $stmt->execute([$_SESSION['user_id'], $name, $description, $status]);
                                }
                            }
                        }
                        
                        header('Location: projects.php?success=project_created');
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors de la création du projet : " . $e->getMessage();
                    }
                } else {
                    $error = "Le nom du projet est obligatoire.";
                }
                break;
                
            case 'delete_project':
                $project_id = (int)($_POST['project_id'] ?? 0);
                if ($project_id > 0) {
                    try {
                        $pdo->beginTransaction();
                        
                        // 1) Récupérer les infos du projet avant suppression
                        $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND owner = ?");
                        $stmtProj->execute([$project_id, $_SESSION['user_id']]);
                        $projectToDelete = $stmtProj->fetch(PDO::FETCH_ASSOC);
                        
                        if ($projectToDelete) {
                            // 2) Supprimer project_components (composants liés)
                            $stmt = $pdo->prepare("DELETE FROM project_components WHERE project_id = ?");
                            $stmt->execute([$project_id]);
                            
                            // 3) Supprimer project_files (fichiers liés en BDD)
                            $stmt = $pdo->prepare("DELETE FROM project_files WHERE project_id = ?");
                            $stmt->execute([$project_id]);
                            
                            // 4) Supprimer le DOSSIER PHYSIQUE du projet (projets/NomDuProjet/)
                            $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $projectToDelete['name']);
                            $projectDir = __DIR__ . DIRECTORY_SEPARATOR . 'projets' . DIRECTORY_SEPARATOR . $safe_name;
                            if (is_dir($projectDir)) {
                                // Fonction récursive pour vider & supprimer le dossier
                                $deleteDir = function($dir) use (&$deleteDir) {
                                    if (!is_dir($dir)) return;
                                    $items = array_diff(scandir($dir), ['.', '..']);
                                    foreach ($items as $item) {
                                        $path = $dir . DIRECTORY_SEPARATOR . $item;
                                        is_dir($path) ? $deleteDir($path) : @unlink($path);
                                    }
                                    @rmdir($dir);
                                };
                                $deleteDir($projectDir);
                            }
                            
                            // 5) Supprimer aussi l'image du projet si elle est HORS du dossier projets/
                            if (!empty($projectToDelete['image_path']) && file_exists($projectToDelete['image_path'])) {
                                $imgPath = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $projectToDelete['image_path']), DIRECTORY_SEPARATOR);
                                if (file_exists($imgPath) && is_file($imgPath) && strpos($imgPath, 'projets' . DIRECTORY_SEPARATOR) === false) {
                                    @unlink($imgPath);
                                }
                            }
                            
                            // 6) Finalement : supprimer le projet lui-même
                            try {
                                $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND owner = ?");
                                $stmt->execute([$project_id, $_SESSION['user_id']]);
                            } catch (Exception $eDel) {
                                // Fallback si updated_at trigger ou autre, mais normalement pas besoin
                                $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND owner = ?");
                                $stmt->execute([$project_id, $_SESSION['user_id']]);
                            }
                        }
                        
                        $pdo->commit();
                        header('Location: projects.php?success=project_deleted');
                        exit();
                    } catch (PDOException $e) {
                        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                        $error = "Erreur lors de la suppression : " . $e->getMessage();
                    }
                }
                break;

            case 'rename_project':
                $project_id = (int)($_POST['project_id'] ?? 0);
                $new_name = trim($_POST['name'] ?? '');
                $new_description = trim($_POST['description'] ?? '');
                $new_status = $_POST['status'] ?? 'En cours';
                if ($project_id > 0 && !empty($new_name)) {
                    try {
                        $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND owner = ?");
                        $stmtProj->execute([$project_id, $_SESSION['user_id']]);
                        $projectToRename = $stmtProj->fetch(PDO::FETCH_ASSOC);

                        if ($projectToRename) {
                            $old_name = $projectToRename['name'];
                            $old_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $old_name);
                            $new_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $new_name);
                            $old_dir = __DIR__ . DIRECTORY_SEPARATOR . 'projets' . DIRECTORY_SEPARATOR . $old_safe;
                            $new_dir = __DIR__ . DIRECTORY_SEPARATOR . 'projets' . DIRECTORY_SEPARATOR . $new_safe;
                            $new_image_path = $projectToRename['image_path'];

                            if ($old_safe !== $new_safe) {
                                if (is_dir($old_dir)) {
                                    if (!is_dir($new_dir)) {
                                        @rename($old_dir, $new_dir);
                                    } else {
                                        $items = @scandir($old_dir);
                                        if ($items) {
                                            $items = array_diff($items, ['.', '..']);
                                            foreach ($items as $item) {
                                                @rename($old_dir . DIRECTORY_SEPARATOR . $item, $new_dir . DIRECTORY_SEPARATOR . $item);
                                            }
                                        }
                                        @rmdir($old_dir);
                                    }
                                }

                                $old_path_prefix_web  = "projets/$old_safe/";
                                $new_path_prefix_web  = "projets/$new_safe/";
                                $old_path_prefix_sys  = str_replace('/', DIRECTORY_SEPARATOR, $old_path_prefix_web);
                                $new_path_prefix_sys  = str_replace('/', DIRECTORY_SEPARATOR, $new_path_prefix_web);

                                if (!empty($new_image_path)) {
                                    $img_norm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $new_image_path);
                                    if (strpos($img_norm, $old_path_prefix_sys) !== false) {
                                        $new_image_path = $new_path_prefix_web . basename($new_image_path);
                                    }
                                }

                                try {
                                    $stmtFiles = $pdo->prepare("UPDATE project_files SET file_path = REPLACE(file_path, ?, ?) WHERE project_id = ? AND file_path LIKE ?");
                                    $stmtFiles->execute([$old_path_prefix_web, $new_path_prefix_web, $project_id, $old_path_prefix_web . '%']);
                                } catch (Exception $eFilesUpd) {
                                }

                                try {
                                    $stmtFix = $pdo->prepare("SELECT id, file_path FROM project_files WHERE project_id = ?");
                                    $stmtFix->execute([$project_id]);
                                    $fileFixRows = $stmtFix->fetchAll(PDO::FETCH_ASSOC);
                                    if ($fileFixRows) {
                                        $updFix = $pdo->prepare("UPDATE project_files SET file_path = ? WHERE id = ? AND project_id = ?");
                                        foreach ($fileFixRows as $frow) {
                                            $fn = basename($frow['file_path']);
                                            if (empty($fn)) continue;
                                            $expected_new = $new_path_prefix_web . $fn;
                                            if (rtrim($frow['file_path'], '/') !== rtrim($expected_new, '/')) {
                                                @$updFix->execute([$expected_new, $frow['id'], $project_id]);
                                            }
                                        }
                                    }
                                } catch (Exception $eFix) {
                                }
                            }

                            try {
                                $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, status = ?, image_path = ?, updated_at = NOW() WHERE id = ? AND owner = ?");
                                $stmt->execute([$new_name, $new_description, $new_status, $new_image_path, $project_id, $_SESSION['user_id']]);
                            } catch (Exception $eUpd) {
                                try {
                                    $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, status = ?, image_path = ? WHERE id = ? AND owner = ?");
                                    $stmt->execute([$new_name, $new_description, $new_status, $new_image_path, $project_id, $_SESSION['user_id']]);
                                } catch (Exception $eUpd2) {
                                    $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, status = ? WHERE id = ? AND owner = ?");
                                    $stmt->execute([$new_name, $new_description, $new_status, $project_id, $_SESSION['user_id']]);
                                }
                            }
                        }

                        $returnTo = $_POST['return_to'] ?? '';
                        if ($returnTo === 'detail') {
                            header('Location: project_detail.php?id=' . $project_id . '&success=project_renamed');
                        } else {
                            header('Location: projects.php?success=project_renamed');
                        }
                        exit();
                    } catch (PDOException $e) {
                        $error = "Erreur lors du renommage : " . $e->getMessage();
                    }
                } else {
                    $error = "Le nom du projet est obligatoire.";
                }
                break;
        }
    }
}

// Récupérer les projets de l'utilisateur
try {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               COUNT(pc.id) as component_count,
               SUM(pc.quantity_needed) as total_components_needed,
               SUM(pc.quantity_used) as total_components_used,
               p.image_path
        FROM projects p 
        LEFT JOIN project_components pc ON p.id = pc.project_id 
        WHERE p.owner = ? 
        GROUP BY p.id 
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Erreur lors de la récupération des projets : " . $e->getMessage());
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
    <title>Gestion des Projets</title>
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

        .create-project-section {
            background: var(--bg-card);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-info {
            background: var(--accent-teal);
            color: white;
        }

        .btn-info:hover {
            filter: brightness(0.95);
            transform: translateY(-1px);
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .project-card {
            background: var(--bg-card);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 1px solid var(--border-muted);
            position: relative;
            overflow: hidden;
        }

        .project-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }

        .project-image-wrap {
            position: relative;
            margin-bottom: 15px;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
        }
        
        .project-image {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--border-muted);
            display: block;
        }

        .project-image-placeholder {
            width: 100%;
            height: 150px;
            background: linear-gradient(135deg, var(--bg-subtle) 0%, var(--border-muted) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 3em;
            border: 1px solid var(--border-muted);
        }
        
        .project-image-edit-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, rgba(0,0,0,0) 55%);
            opacity: 0;
            transition: opacity 0.25s ease;
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            padding: 10px;
            border-radius: 10px;
        }
        
        .project-image-wrap:hover .project-image-edit-overlay {
            opacity: 1;
        }
        
        .project-image-edit-btn {
            background: rgba(255,255,255,0.95);
            color: #495057;
            border: none;
            padding: 5px 11px;
            border-radius: 7px;
            font-size: 12px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            backdrop-filter: blur(5px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            transition: all 0.2s ease;
        }
        
        .project-image-edit-btn:hover {
            background: var(--bg-card);
            transform: translateY(-1px);
        }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .project-title {
            font-size: 1.4em;
            font-weight: bold;
            color: var(--text-primary);
            margin-bottom: 5px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .project-edit-inline-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: #adb5bd;
            border: 1px solid transparent;
            padding: 3px 5px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.72em;
            line-height: 1;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-1px);
            transition: all 0.2s ease;
            vertical-align: middle;
            margin-left: 2px;
        }

        .project-header:hover .project-edit-inline-btn,
        .project-edit-inline-btn:focus-visible {
            opacity: 1;
            visibility: visible;
        }

        .project-edit-inline-btn:hover {
            background: rgba(79, 70, 229, 0.08);
            color: #4f46e5;
            border-color: rgba(79, 70, 229, 0.2);
            transform: translateY(-1px);
        }

        .project-edit-inline-btn:active {
            transform: translateY(0);
        }

        .project-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-en-cours {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-termine {
            background: #d4edda;
            color: #155724;
        }

        .status-en-attente {
            background: #fff3cd;
            color: #856404;
        }

        .status-annule {
            background: #f8d7da;
            color: #721c24;
        }

        .project-description {
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .project-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: var(--bg-subtle);
            border-radius: 10px;
        }

        .stat-number {
            font-size: 1.8em;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        .project-actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
        }

        .no-projects {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .no-projects h3 {
            font-size: 1.5em;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
            }
            
            .project-actions {
                flex-direction: column;
            }
        }

        /* ===== MODALE CONFIRMATION SUPPRESSION ===== */

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(3px);
            animation: fadeIn 0.2s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-box {
            background: var(--bg-card);
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            box-shadow:
                0 25px 50px rgba(15, 23, 42, 0.25),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
            animation: slideUp 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modal-box-header {
            padding: 24px 24px 14px;
            border-bottom: 1px solid var(--bg-muted);
        }

        .modal-icon-wrap {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            display: grid;
            place-items: center;
            margin: 0 auto 14px;
            font-size: 26px;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.35);
        }

        .modal-box h3 {
            margin: 0 0 4px;
            font-size: 1.35rem;
            text-align: center;
            color: #0f172a;
            font-weight: 900;
        }

        .modal-box .modal-subtitle {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.92rem;
            margin: 0;
        }

        .modal-box-body {
            padding: 20px 24px;
        }

        .modal-project-name {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 12px;
            padding: 12px 16px;
            text-align: center;
            font-weight: 900;
            color: #b91c1c;
            font-size: 1.05rem;
            margin-bottom: 16px;
            word-break: break-word;
        }

        .modal-warnings {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 14px 16px;
        }

        .modal-warnings h4 {
            color: #b45309;
            font-size: 0.85rem;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-warnings ul {
            margin: 0;
            padding-left: 18px;
            color: #78350f;
            font-size: 0.88rem;
            line-height: 1.6;
        }

        .modal-warnings li {
            margin-bottom: 3px;
        }

        .modal-box-footer {
            padding: 16px 24px 22px;
            border-top: 1px solid var(--bg-muted);
            display: flex;
            gap: 12px;
        }

        .modal-cancel-btn {
            flex: 1;
            padding: 12px 18px;
            font-size: 0.95rem;
            font-weight: 700;
            border: 1.5px solid var(--border-color);
            background: var(--bg-primary);
            color: #475569;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .modal-cancel-btn:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
            color: #4338ca;
        }

        .modal-delete-btn {
            flex: 1.2;
            padding: 12px 18px;
            font-size: 0.95rem;
            font-weight: 800;
            border: none;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .modal-delete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(239, 68, 68, 0.4);
        }

        .modal-delete-btn:active {
            transform: translateY(0);
        }

        /* RENAME MODAL */
        .modal-rename-btn {
            flex: 1.2;
            padding: 12px 18px;
            font-size: 0.95rem;
            font-weight: 800;
            border: none;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .modal-rename-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(99, 102, 241, 0.4);
        }

        .modal-rename-btn:active {
            transform: translateY(0);
        }

        .rename-form-group {
            margin-bottom: 16px;
        }

        .rename-form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.88rem;
            color: #334155;
            margin-bottom: 6px;
        }

        .rename-form-group input[type=text],
        .rename-form-group select,
        .rename-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            font-size: 0.95rem;
            border: 1.5px solid var(--border-strong);
            border-radius: 10px;
            background: var(--bg-primary);
            color: #0f172a;
            box-sizing: border-box;
            font-family: inherit;
            transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }

        .rename-form-group input[type=text]:focus,
        .rename-form-group select:focus,
        .rename-form-group textarea:focus {
            outline: none;
            border-color: #6366f1;
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .rename-form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .rename-form-hint {
            margin-top: 6px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .modal-rename-project-old {
            background: #eef2ff;
            border: 1px dashed #c7d2fe;
            color: #3730a3;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 700;
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 16px;
        }

        .btn-delete-trigger {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color: white;
            border: none;
            font-weight: 700;
            padding: 9px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3);
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn-delete-trigger:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(220, 38, 38, 0.45);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🚀</div>
                    <div>
                        <h1>Gestion des Projets</h1>
                        <p>Suivez vos projets électroniques de A à Z</p>
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
            <!-- Messages de succès et d'erreur -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <?php
                    switch($_GET['success']) {
                        case 'project_created':
                            echo "✅ Projet créé avec succès !";
                            break;
                        case 'project_renamed':
                            echo "✅ Projet renommé avec succès !";
                            break;
                        case 'project_deleted':
                            echo "✅ Projet supprimé avec succès !";
                            break;
                        case 'component_added':
                            echo "✅ Composant ajouté au projet !";
                            break;
                        default:
                            echo "✅ Opération réussie !";
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Formulaire de création de projet -->
            <div class="create-project-section">
                <h2 style="margin-bottom: 20px; color: var(--text-primary);">➕ Créer un nouveau projet</h2>
                <form method="POST" action="projects.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_project">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 250px; gap: 20px; align-items: end;">
                        <div class="form-group">
                            <label for="name">Nom du projet *</label>
                            <input type="text" id="name" name="name" required placeholder="Ex: Robot autonome">
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <input type="text" id="description" name="description" placeholder="Description courte du projet">
                        </div>
                        <div class="form-group">
                            <label for="status">Statut</label>
                            <select id="status" name="status">
                                <option value="En cours">En cours</option>
                                <option value="En attente">En attente</option>
                                <option value="Terminé">Terminé</option>
                                <option value="Annulé">Annulé</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="project_image">Image du projet (optionnel)</label>
                            <input type="file" id="project_image" name="project_image" accept="image/jpeg,image/png,image/gif,image/webp" style="padding: 8px; font-size: 13px;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: 15px;">🚀 Créer le projet</button>
                </form>
            </div>

            <!-- Liste des projets -->
            <?php if (empty($projects)): ?>
                <div class="no-projects">
                    <h3>Aucun projet trouvé</h3>
                    <p>Créez votre premier projet pour commencer à organiser vos composants !</p>
                </div>
            <?php else: ?>
                <div class="projects-grid">
                    <?php foreach ($projects as $project): ?>
                        <div class="project-card">
                            <!-- Image du projet -->
                            <div class="project-image-wrap"
                                 onclick="window.location.href='project_detail.php?id=<?php echo (int)$project['id']; ?>'"
                                 title="Ouvrir le projet">
                                <?php if (!empty($project['image_path']) && file_exists($project['image_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($project['image_path']) . '?t=' . filemtime($project['image_path']); ?>" alt="Image du projet" class="project-image">
                                <?php else: ?>
                                    <div class="project-image-placeholder">
                                        🚀
                                    </div>
                                <?php endif; ?>
                                <div class="project-image-edit-overlay">
                                    <a href="project_detail.php?id=<?php echo (int)$project['id']; ?>#image" class="project-image-edit-btn" title="Modifier l'image" onclick="event.stopPropagation();">
                                        <i class="fas fa-camera" style="font-size: 11px;"></i> Image
                                    </a>
                                </div>
                            </div>
                            
                            <div class="project-header">
                                <div>
                                    <div class="project-title">
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <button type="button"
                                                class="project-edit-inline-btn"
                                                title="Renommer / modifier le projet"
                                                onclick="openRenameModal(<?php echo (int)$project['id']; ?>, '<?php echo htmlspecialchars(addslashes($project['name'])); ?>', '<?php echo htmlspecialchars(addslashes($project['description'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($project['status'])); ?>')">
                                            ✏️
                                        </button>
                                    </div>
                                    <small style="color: var(--text-muted);">Créé le <?php echo date('d/m/Y', strtotime($project['created_at'])); ?></small>
                                </div>
                                <span class="project-status status-<?php echo strtolower(str_replace(' ', '-', $project['status'])); ?>">
                                    <?php echo htmlspecialchars($project['status']); ?>
                                </span>
                            </div>
                            
                            <?php if ($project['description']): ?>
                                <div class="project-description">
                                    <?php echo htmlspecialchars($project['description']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="project-stats">
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $project['component_count']; ?></div>
                                    <div class="stat-label">Composants</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo $project['total_components_needed'] ?? 0; ?></div>
                                    <div class="stat-label">Quantité totale</div>
                                </div>
                            </div>
                            
                            <div class="project-actions">
                                <a href="project_detail.php?id=<?php echo $project['id']; ?>" class="btn btn-info">👁️ Voir détails</a>
                                <button type="button" class="btn-delete-trigger"
                                        onclick="openDeleteModal(<?php echo (int)$project['id']; ?>, '<?php echo htmlspecialchars(addslashes($project['name'])); ?>')">
                                    🗑️ Supprimer
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <!-- ===== MODALE CONFIRMATION SUPPRESSION PROJET ===== -->
    <div class="modal-overlay" id="deleteProjectModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-box">
            <form method="POST" id="deleteProjectForm">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="project_id" id="deleteProjectId">

                <div class="modal-box-header">
                    <div class="modal-icon-wrap">⚠️</div>
                    <h3 id="deleteModalTitle">Supprimer ce projet ?</h3>
                    <p class="modal-subtitle">Attention — cette action est irréversible</p>
                </div>

                <div class="modal-box-body">
                    <div class="modal-project-name" id="deleteProjectName">—</div>

                    <div class="modal-warnings">
                        <h4>🛑 Ce qui sera définitivement supprimé :</h4>
                        <ul>
                            <li>Tous les composants liés au projet</li>
                            <li>Tous les fichiers & documents joints</li>
                            <li>L'image de couverture du projet</li>
                            <li>Le dossier complet du projet (<code>projets/NomDuProjet/</code>)</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-box-footer">
                    <button type="button" class="modal-cancel-btn" onclick="closeDeleteModal()">Annuler</button>
                    <button type="submit" class="modal-delete-btn">✅ Oui, supprimer définitivement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== MODALE RENOMMER PROJET ===== -->
    <div class="modal-overlay" id="renameProjectModal" role="dialog" aria-modal="true" aria-labelledby="renameModalTitle">
        <div class="modal-box">
            <form method="POST" id="renameProjectForm">
                <input type="hidden" name="action" value="rename_project">
                <input type="hidden" name="project_id" id="renameProjectId">

                <div class="modal-box-header">
                    <div class="modal-icon-wrap" style="background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color:#4338ca;">✏️</div>
                    <h3 id="renameModalTitle">Renommer / Modifier le projet</h3>
                    <p class="modal-subtitle">Mettez à jour le nom, la description ou le statut du projet</p>
                </div>

                <div class="modal-box-body">
                    <div class="modal-rename-project-old" id="renameProjectOldName">—</div>

                    <div class="rename-form-group">
                        <label for="renameProjectName">Nom du projet *</label>
                        <input type="text" id="renameProjectName" name="name" required placeholder="Ex: Projet Robotique">
                        <div class="rename-form-hint">⚠️ Le nom est utilisé pour le dossier physique du projet (<code>projets/NomDuProjet/</code>)</div>
                    </div>

                    <div class="rename-form-group">
                        <label for="renameProjectDescription">Description</label>
                        <textarea id="renameProjectDescription" name="description" placeholder="Courte description du projet..."></textarea>
                    </div>

                    <div class="rename-form-group">
                        <label for="renameProjectStatus">Statut</label>
                        <select id="renameProjectStatus" name="status">
                            <option value="En cours">🚧 En cours</option>
                            <option value="Terminé">✅ Terminé</option>
                            <option value="En pause">⏸️ En pause</option>
                            <option value="Abandonné">❌ Abandonné</option>
                            <option value="Planifié">📋 Planifié</option>
                        </select>
                    </div>
                </div>

                <div class="modal-box-footer">
                    <button type="button" class="modal-cancel-btn" onclick="closeRenameModal()">Annuler</button>
                    <button type="submit" class="modal-rename-btn">💾 Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>

    <script>
        // ===== MODALE SUPPRESSION PROJET =====
        const modal = document.getElementById('deleteProjectModal');
        const deleteForm = document.getElementById('deleteProjectForm');
        const deleteProjectIdInput = document.getElementById('deleteProjectId');
        const deleteProjectNameSpan = document.getElementById('deleteProjectName');

        function openDeleteModal(projectId, projectName) {
            deleteProjectIdInput.value = projectId;
            deleteProjectNameSpan.textContent = projectName;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Fermer en cliquant sur l'overlay (hors boîte)
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeDeleteModal();
        });

        // Fermer avec Échap
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeDeleteModal();
            }
        });

        // ===== MODALE RENOMMER PROJET =====
        const renameModal = document.getElementById('renameProjectModal');
        const renameProjectIdInput = document.getElementById('renameProjectId');
        const renameProjectNameInput = document.getElementById('renameProjectName');
        const renameProjectDescInput = document.getElementById('renameProjectDescription');
        const renameProjectStatusInput = document.getElementById('renameProjectStatus');
        const renameProjectOldNameSpan = document.getElementById('renameProjectOldName');
        const renameForm = document.getElementById('renameProjectForm');

        function openRenameModal(projectId, projectName, projectDesc, projectStatus) {
            renameProjectIdInput.value = projectId;
            renameProjectNameInput.value = projectName || '';
            renameProjectDescInput.value = projectDesc || '';
            renameProjectOldNameSpan.textContent = 'Nom actuel : ' + (projectName || '—');
            if (renameProjectStatusInput) {
                let found = false;
                for (const opt of renameProjectStatusInput.options) {
                    if (opt.value === projectStatus) {
                        renameProjectStatusInput.value = projectStatus;
                        found = true;
                        break;
                    }
                }
                if (!found) renameProjectStatusInput.value = 'En cours';
            }
            renameModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => renameProjectNameInput.focus(), 60);
        }

        function closeRenameModal() {
            renameModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        renameModal.addEventListener('click', function(e) {
            if (e.target === renameModal) closeRenameModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && renameModal.classList.contains('active')) {
                closeRenameModal();
            }
        });

        renameForm.addEventListener('submit', function(e) {
            if (!renameProjectNameInput.value || !renameProjectNameInput.value.trim()) {
                e.preventDefault();
                alert('Le nom du projet est obligatoire.');
                renameProjectNameInput.focus();
            }
        });
    </script>
    </div>
</body>
</html>

