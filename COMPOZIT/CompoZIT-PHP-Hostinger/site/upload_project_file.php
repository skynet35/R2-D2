<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gérer l'action de mise à jour du nom de fichier
    if (isset($_POST['action']) && $_POST['action'] === 'update_file_name') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        
        header('Content-Type: application/json');
        
        if ($file_id <= 0 || empty($display_name)) {
            echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
            exit();
        }
        
        try {
            $pdo = getConnection();
            
            // Vérifier que le fichier appartient à un projet de l'utilisateur
            $stmt = $pdo->prepare("
                SELECT pf.id FROM project_files pf 
                JOIN projects p ON pf.project_id = p.id 
                WHERE pf.id = ? AND p.owner = ?
            ");
            $stmt->execute([$file_id, $_SESSION['user_id']]);
            
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Fichier non trouvé']);
                exit();
            }
            
            // Mettre à jour le nom d'affichage
            $stmt = $pdo->prepare("UPDATE project_files SET display_name = ? WHERE id = ?");
            $stmt->execute([$display_name, $file_id]);
            
            echo json_encode(['success' => true]);
            exit();
            
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Erreur de base de données']);
            exit();
        }
    }
    
    $project_id = (int)($_POST['project_id'] ?? 0);
    $file_category = trim($_POST['file_category'] ?? '');
    if (empty($file_category)) {
        $file_category = 'autre';
    }
    $description = trim($_POST['file_description'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    
    if ($project_id <= 0) {
        header('Location: project_detail.php?error=invalid_project');
        exit();
    }
    
    try {
        $pdo = getConnection();
        
        // Vérifier que le projet appartient à l'utilisateur
        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND owner = ?");
        $stmt->execute([$project_id, $_SESSION['user_id']]);
        $project = $stmt->fetch();
        
        if (!$project) {
            header('Location: projects.php?error=project_not_found');
            exit();
        }
        
        // Créer le dossier du projet
        $project_folder = __DIR__ . '/projets/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);
        if (!is_dir($project_folder)) {
            mkdir($project_folder, 0755, true);
        }
        
        $upload_success = false;
        $uploaded_files = [];
        
        // Gérer l'upload de l'image principale
        if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] === UPLOAD_ERR_OK) {
            $image_file = $_FILES['project_image'];
            $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if (in_array($image_file['type'], $allowed_image_types)) {
                $image_extension = pathinfo($image_file['name'], PATHINFO_EXTENSION);
                $image_filename = 'project_image_' . time() . '.' . $image_extension;
                $image_path = $project_folder . '/' . $image_filename;
                
                if (move_uploaded_file($image_file['tmp_name'], $image_path)) {
                    // Mettre à jour la base de données avec le chemin de l'image
                    $relative_path = 'projets/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']) . '/' . $image_filename;
                    $stmt = $pdo->prepare("UPDATE projects SET image_path = ? WHERE id = ?");
                    $stmt->execute([$relative_path, $project_id]);
                    $upload_success = true;
                }
            }
        }
        
        // Gérer l'upload des fichiers attachés
        if (isset($_FILES['project_file']) && $_FILES['project_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['project_file'];
            $original_name = $file['name'];
            $file_size = $file['size'];
            $file_type = $file['type'];
                    
            // Limiter la taille des fichiers (50MB max)
            if ($file_size <= 50 * 1024 * 1024) {
                $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
                $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
                $new_filename = $safe_filename . '_' . time() . '.' . $file_extension;
                $file_path = $project_folder . '/' . $new_filename;
                
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        // Enregistrer dans la base de données
                        $relative_path = 'projets/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']) . '/' . $new_filename;
                        $file_display_name = !empty($display_name) ? $display_name : $original_name;
                        $stmt = $pdo->prepare("
                            INSERT INTO project_files (project_id, file_name, original_name, display_name, file_path, file_type, file_size, file_category, description)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $project_id,
                            $new_filename,
                            $original_name,
                            $file_display_name,
                            $relative_path,
                            $file_type,
                            $file_size,
                            $file_category,
                            $description
                        ]);
                    $uploaded_files[] = $original_name;
                    $upload_success = true;
                }
            }
        }
        
        if ($upload_success) {
            $message = 'files_uploaded';
            if (!empty($uploaded_files)) {
                $message .= '&files=' . urlencode(implode(', ', $uploaded_files));
            }
            header('Location: project_detail.php?id=' . $project_id . '&tab=files&success=' . $message . '#files');
        } else {
            header('Location: project_detail.php?id=' . $project_id . '&tab=files&error=upload_failed#files');
        }
        
    } catch(PDOException $e) {
        header('Location: project_detail.php?id=' . $project_id . '&error=database_error#files');
    }
} else {
    header('Location: projects.php');
}
exit();
?>