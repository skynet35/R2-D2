<?php
@ob_start();

$debugLogFile = __DIR__ . '/upload_project_image_debug.log';
$debugLog = [];
$debugLog['start'] = date('Y-m-d H:i:s');
$debugLog['_SERVER_REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
$debugLog['_FILES'] = isset($_FILES) ? array_keys($_FILES) : [];
$debugLog['_POST_KEYS'] = isset($_POST) ? array_keys($_POST) : [];

function writeDebugLog() {
    global $debugLogFile, $debugLog;
    try {
        $existing = @file_get_contents($debugLogFile);
        $line = "\n========== [" . date('Y-m-d H:i:s') . "] ==========\n" . print_r($debugLog, true) . "\n";
        @file_put_contents($debugLogFile, $existing . $line, FILE_APPEND);
    } catch (Exception $e) {}
}

/**
 * Nettoie TOUS les niveaux d'output buffering, efface TOUS les headers envoyés,
 * et renvoie un JSON PROPRE (garanti sans BOM / espaces / notices avant)
 */
function sendJsonClean($payload, $httpCode = 200) {
    // 1) Nettoyer absolument tous les buffers (parfois il y en a 2+ ouverts : output_buffering php.ini + notre ob_start)
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    // 2) Supprimer les headers qui auraient pu être posés avant (ex: session_set_cookie_params, warning)
    if (function_exists('header_remove')) {
        @header_remove();
    }
    // 3) Poser nos vrais headers, en REMPLAÇANT (true) tout ce qui aurait pu exister avant
    if ($httpCode >= 100 && $httpCode < 600) {
        @http_response_code($httpCode);
    }
    @header('Content-Type: application/json; charset=utf-8', true);
    @header('X-Content-Type-Options: nosniff', true);
    @header('Cache-Control: no-store, no-cache, must-revalidate', true);
    @header('Pragma: no-cache', true);
    @header('Expires: Thu, 01 Jan 1970 00:00:00 GMT', true);
    // 4) Encoder JSON, supprimer BOM UTF-8 s'il est revenu + tout espace début/fin parasite
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $json = preg_replace('/^\xEF\xBB\xBF+/', '', (string)$json);
    $json = trim($json);
    echo $json;
    exit();
}

register_shutdown_function(function() {
    global $debugLog;
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $debugLog['fatal_error'] = $error;
        writeDebugLog();
        if (!headers_sent()) {
            try {
                while (ob_get_level() > 0) @ob_end_clean();
                @header_remove();
                @http_response_code(500);
                @header('Content-Type: application/json; charset=utf-8', true);
                $json = json_encode([
                    'success' => false,
                    'message' => 'Erreur fatale PHP: ' . ($error['message'] ?? 'inconnue') . ' dans ' . basename($error['file'] ?? '?') . ':' . ($error['line'] ?? '?')
                ], JSON_UNESCAPED_UNICODE);
                $json = preg_replace('/^\xEF\xBB\xBF+/', '', (string)$json);
                echo trim($json);
            } catch (Throwable $e2) {}
        }
        exit();
    }
    writeDebugLog();
});

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(E_ALL);

try {
    require_once __DIR__ . '/session_init.php';
    require_once __DIR__ . '/config.php';
    $debugLog['session_loaded'] = true;
    $debugLog['user_id'] = $_SESSION['user_id'] ?? null;
} catch (Throwable $e) {
    $debugLog['init_error'] = [get_class($e) => $e->getMessage(), 'line' => $e->getLine()];
    sendJsonClean(['success' => false, 'message' => 'Erreur initialisation: ' . $e->getMessage()], 500);
}

if (!isset($_SESSION['user_id'])) {
    sendJsonClean(['success' => false, 'message' => 'Non autorisé (session user_id absent)'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJsonClean(['success' => false, 'message' => 'Méthode non autorisée: ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET')], 400);
}

$project_id = (int)($_POST['project_id'] ?? 0);
$action = $_POST['action'] ?? 'upload';
$debugLog['project_id'] = $project_id;
$debugLog['action'] = $action;

if ($project_id <= 0) {
    sendJsonClean(['success' => false, 'message' => 'ID de projet invalide: ' . $project_id], 400);
}

try {
    $pdo = getConnection();
    $debugLog['db_connected'] = true;
} catch (Throwable $e) {
    $debugLog['db_error'] = ['msg' => $e->getMessage(), 'line' => $e->getLine()];
    sendJsonClean(['success' => false, 'message' => 'Erreur base de données: ' . $e->getMessage()], 500);
}

try {
    $stmt = $pdo->prepare("SELECT id, name, image_path FROM projects WHERE id = ? AND owner = ?");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    $debugLog['project_found'] = !empty($project);
    if ($project) {
        $debugLog['project_name'] = $project['name'];
        $debugLog['old_image_path'] = $project['image_path'];
    }
} catch (Throwable $e) {
    $debugLog['select_project_error'] = ['msg' => $e->getMessage(), 'sql' => 'SELECT id, name, image_path FROM projects...'];
    sendJsonClean(['success' => false, 'message' => 'Erreur lecture projet: ' . $e->getMessage()], 500);
}

if (!$project) {
    sendJsonClean(['success' => false, 'message' => 'Projet non trouvé (id=' . $project_id . ', user=' . $_SESSION['user_id'] . ')'], 404);
}

$safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']);
$project_folder_abs = __DIR__ . '/projets/' . $safe_name;
$project_folder_rel = 'projets/' . $safe_name;
$debugLog['safe_name'] = $safe_name;
$debugLog['project_folder_abs'] = $project_folder_abs;

if ($action === 'delete') {
    try {
        if (!empty($project['image_path']) && file_exists(__DIR__ . '/' . $project['image_path'])) {
            $img_abs = __DIR__ . '/' . $project['image_path'];
            if (strpos(str_replace('\\', '/', $project['image_path']), 'projets/') === 0
                || strpos(str_replace('\\', '/', $project['image_path']), 'img/') === 0) {
                @unlink($img_abs);
                $debugLog['old_file_deleted'] = $project['image_path'];
            }
        }
        $stmt = $pdo->prepare("UPDATE projects SET image_path = NULL WHERE id = ?");
        $stmt->execute([$project_id]);
        sendJsonClean(['success' => true, 'message' => 'Image supprimée'], 200);
    } catch (Throwable $e) {
        $debugLog['delete_error'] = ['msg' => $e->getMessage(), 'line' => $e->getLine()];
        sendJsonClean(['success' => false, 'message' => 'Erreur suppression: ' . $e->getMessage()], 500);
    }
}

if ($action !== 'upload') {
    sendJsonClean(['success' => false, 'message' => 'Action inconnue: ' . $action], 400);
}

if (!isset($_FILES['project_image'])) {
    $debugLog['missing_field'] = '$_FILES[project_image] absent';
    sendJsonClean(['success' => false, 'message' => 'Aucun fichier reçu (champ project_image absent)'], 400);
}

$file = $_FILES['project_image'];
$debugLog['file_info'] = [
    'name' => $file['name'] ?? null,
    'size' => $file['size'] ?? null,
    'error' => $file['error'] ?? null,
    'type' => $file['type'] ?? null,
];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errorCodes = [
        UPLOAD_ERR_INI_SIZE => 'Fichier trop grand (php.ini - upload_max_filesize)',
        UPLOAD_ERR_FORM_SIZE => 'Fichier trop grand (formulaire MAX_FILE_SIZE)',
        UPLOAD_ERR_PARTIAL => 'Upload partiel (interrompu)',
        UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné',
        UPLOAD_ERR_NO_TMP_DIR => 'Répertoire temporaire PHP manquant',
        UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque',
        UPLOAD_ERR_EXTENSION => 'Upload bloqué par une extension PHP',
    ];
    $code = $file['error'] ?? -1;
    $msg = $errorCodes[$code] ?? ('Code erreur #' . $code);
    sendJsonClean(['success' => false, 'message' => 'Erreur upload: ' . $msg], 400);
}

if (!is_dir($project_folder_abs)) {
    if (!@mkdir($project_folder_abs, 0755, true)) {
        sendJsonClean(['success' => false, 'message' => 'Impossible de créer le dossier projet: ' . $project_folder_abs], 500);
    }
    $debugLog['folder_created'] = true;
}
if (!is_writable($project_folder_abs)) {
    sendJsonClean(['success' => false, 'message' => 'Dossier projet non accessible en écriture: ' . $project_folder_abs], 500);
}
$debugLog['folder_exists'] = true;
$debugLog['folder_writable'] = is_writable($project_folder_abs);

$original_name = $file['name'] ?? 'file';
$file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$debugLog['extension'] = $file_extension;

if (!in_array($file_extension, $allowed_extensions)) {
    sendJsonClean(['success' => false, 'message' => 'Type de fichier invalide: "' . $file_extension . '". Utilisez JPG, PNG, GIF ou WebP.'], 400);
}

if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
    sendJsonClean(['success' => false, 'message' => 'Fichier trop volumineux: ' . round(($file['size'] ?? 0) / 1048576, 2) . ' Mo (max 10 Mo)'], 413);
}

$finfo = @new finfo(FILEINFO_MIME_TYPE);
$detected_mime = @$finfo->file($file['tmp_name']);
$debugLog['detected_mime'] = $detected_mime;
$allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($detected_mime, $allowed_mimes) && !in_array($file['type'] ?? '', $allowed_mimes)) {
    sendJsonClean(['success' => false, 'message' => 'Type MIME invalide: détecté "' . ($detected_mime ?? '?') . '"'], 400);
}

$target_path = null;
$compression_ok = false;
$filename = 'project_image_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
$full_target = $project_folder_abs . '/' . $filename;
$relative_target = $project_folder_rel . '/' . $filename;
$debugLog['target'] = $full_target;

if (extension_loaded('gd')) {
    $info = @getimagesize($file['tmp_name']);
    $debugLog['getimagesize'] = $info ? [$info[0], $info[1], $info[2] ?? '?'] : false;
    if ($info) {
        list($width, $height, $type) = $info;
        $max_w = 1920;
        $max_h = 1080;
        $ratio = min($max_w / $width, $max_h / $height, 1);
        $new_w = max(1, (int)round($width * $ratio));
        $new_h = max(1, (int)round($height * $ratio));
        $debugLog['new_size'] = [$new_w, $new_h];

        $src = false;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
            case IMAGETYPE_PNG: $src = @imagecreatefrompng($file['tmp_name']); break;
            case IMAGETYPE_GIF: $src = @imagecreatefromgif($file['tmp_name']); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false; break;
        }
        $debugLog['src_created'] = $src ? 'yes' : 'no';

        if ($src) {
            $dst = imagecreatetruecolor($new_w, $new_h);
            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
            if (@imagejpeg($dst, $full_target, 92)) {
                $compression_ok = true;
                $target_path = $relative_target;
            } else {
                $debugLog['imagejpeg_failed'] = error_get_last();
            }
            @imagedestroy($src);
            @imagedestroy($dst);
        }
    }
}

if (!$compression_ok) {
    $debugLog['gd_failed_using_move'] = true;
    $filename = 'project_image_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
    $full_target = $project_folder_abs . '/' . $filename;
    $relative_target = $project_folder_rel . '/' . $filename;
    if (!@move_uploaded_file($file['tmp_name'], $full_target)) {
        $debugLog['move_uploaded_failed'] = error_get_last();
        sendJsonClean(['success' => false, 'message' => 'Impossible de sauvegarder le fichier dans ' . $full_target . '. Vérifiez permissions.'], 500);
    }
    $target_path = $relative_target;
}

$debugLog['final_path'] = $target_path;
$debugLog['file_exists'] = $target_path ? file_exists(__DIR__ . '/' . $target_path) : false;
$debugLog['file_size'] = $target_path ? (@filesize(__DIR__ . '/' . $target_path) ?: 0) : 0;

if (!empty($project['image_path']) && $project['image_path'] !== $target_path) {
    $old_abs = __DIR__ . '/' . $project['image_path'];
    if (file_exists($old_abs)) {
        $oldNormalized = str_replace('\\', '/', $project['image_path']);
        if (strpos($oldNormalized, 'projets/') === 0 || strpos($oldNormalized, 'img/') === 0) {
            @unlink($old_abs);
            $debugLog['deleted_old_file'] = $old_abs;
        }
    }
}

try {
    $stmt = $pdo->prepare("UPDATE projects SET image_path = ? WHERE id = ?");
    $stmt->execute([$target_path, $project_id]);
    $debugLog['db_updated'] = true;
} catch (Throwable $e) {
    $debugLog['update_error'] = ['msg' => $e->getMessage(), 'line' => $e->getLine()];
    try {
        $stmt = $pdo->prepare("UPDATE projects SET image_path = ? WHERE id = ? AND owner = ?");
        $stmt->execute([$target_path, $project_id, $_SESSION['user_id']]);
        $debugLog['db_updated_retry'] = true;
    } catch (Throwable $e2) {
        $debugLog['update_retry_error'] = ['msg' => $e2->getMessage(), 'line' => $e2->getLine()];
        sendJsonClean(['success' => false, 'message' => 'Erreur mise à jour base: ' . $e->getMessage() . ' / retry: ' . $e2->getMessage()], 500);
    }
}

$fileCache = @filemtime(__DIR__ . '/' . $target_path) ?: time();
$finalUrl = $target_path . '?t=' . $fileCache;
$debugLog['final_url'] = $finalUrl;

sendJsonClean([
    'success' => true,
    'message' => 'Image enregistrée dans projets/' . $safe_name . '/' . $filename . ' (' . round($debugLog['file_size'] / 1024, 1) . ' Ko)',
    'image_path' => $finalUrl
], 200);
