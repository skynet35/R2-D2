<?php
@ini_set('display_errors', '0');
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Helper: normaliser un chemin (Windows / Linux) pour éviter les erreurs file_exists
function resolveAbsPath($projectRelative) {
    $projectRelative = ltrim(str_replace('\\', '/', (string)$projectRelative), '/');
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $projectRelative);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file_id = (int)($_POST['file_id'] ?? 0);
    $project_id_param = (int)($_POST['project_id'] ?? 0);

    if ($file_id <= 0) {
        $redirect = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'projects.php';
        header('Location: ' . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'error=invalid_parameters');
        exit();
    }

    try {
        $pdo = getConnection();

        // Récupérer TOUTES les infos du fichier + project_id + owner (pour sécurité)
        $stmt = $pdo->prepare("
            SELECT pf.id, pf.file_path, pf.original_name, pf.display_name, pf.project_id, p.owner as owner
            FROM project_files pf 
            JOIN projects p ON pf.project_id = p.id 
            WHERE pf.id = ? AND p.owner = ?
            LIMIT 1
        ");
        $stmt->execute([$file_id, $_SESSION['user_id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            $redirect = $project_id_param > 0
                ? "project_detail.php?id=$project_id_param&tab=files&error=file_not_found#files"
                : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'projects.php');
            header('Location: ' . $redirect);
            exit();
        }

        $project_id = (int)$file['project_id'];

        // Supprimer le fichier physique (normalisé, Windows/Linux compatible)
        $absPath = resolveAbsPath($file['file_path']);
        if (file_exists($absPath) && is_file($absPath)) {
            @unlink($absPath);
        }

        // Supprimer la ligne en BDD
        $stmtDel = $pdo->prepare("DELETE FROM project_files WHERE id = ? AND project_id = ?");
        $stmtDel->execute([$file_id, $project_id]);

        // Redirection : RESTER SUR L'ONGLET où on était (HTTP_REFERER si dispo, sinon files)
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $loc = $_SERVER['HTTP_REFERER'];
            $sep = (strpos($loc, '?') !== false) ? '&' : '?';
            header('Location: ' . $loc . $sep . 'success=file_deleted');
        } else {
            header("Location: project_detail.php?id=$project_id&tab=files&success=file_deleted#files");
        }
        exit();

    } catch (Throwable $e) {
        $project_id = $project_id_param > 0 ? $project_id_param : ($file['project_id'] ?? 0);
        if ($project_id > 0) {
            $redirect = "project_detail.php?id=$project_id&tab=files&error=delete_failed#files";
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $loc = $_SERVER['HTTP_REFERER'];
                $sep = (strpos($loc, '?') !== false) ? '&' : '?';
                $redirect = $loc . $sep . 'error=delete_failed';
            }
            header('Location: ' . $redirect);
        } else {
            header('Location: projects.php?error=delete_failed');
        }
        exit();
    }
}

// Méthode GET ou autre → retour sur la page du projet (si on a l'id) ou projects
$file_id = (int)($_GET['id'] ?? 0);
$redirect = 'projects.php';
if ($file_id > 0) {
    try {
        require_once __DIR__ . '/config.php';
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT pf.project_id FROM project_files pf JOIN projects p ON p.id = pf.project_id WHERE pf.id = ? AND p.owner = ?");
        $stmt->execute([$file_id, $_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $redirect = "project_detail.php?id=" . (int)$row['project_id'] . "&tab=files#files";
        }
    } catch (Throwable $e) {}
}
header('Location: ' . $redirect);
exit();
