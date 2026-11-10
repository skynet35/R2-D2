<?php
@ini_set('display_errors', '0');
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

function resolveAbsPath($projectRelative) {
    $projectRelative = ltrim(str_replace('\\', '/', (string)$projectRelative), '/');
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $projectRelative);
}

function fallbackDownloadName($fallback, $original) {
    $n = !empty($original) ? $original : ($fallback ?? 'fichier');
    $n = preg_replace('/[^\p{L}\p{N}_.-]+/u', '_', (string)$n);
    return trim($n, '._-') ?: 'fichier.bin';
}

$file_id = (int)($_GET['id'] ?? 0);
if ($file_id <= 0) {
    header('Location: projects.php?error=invalid_file');
    exit();
}

try {
    $pdo = getConnection();

    $stmt = $pdo->prepare("
        SELECT pf.file_path, pf.original_name, pf.display_name, pf.file_type, pf.file_size, p.name as project_name, p.owner as owner
        FROM project_files pf 
        JOIN projects p ON pf.project_id = p.id 
        WHERE pf.id = ? AND p.owner = ?
        LIMIT 1
    ");
    $stmt->execute([$file_id, $_SESSION['user_id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        header('Location: projects.php?error=file_not_found');
        exit();
    }

    $absPath = resolveAbsPath($file['file_path']);

    if (!file_exists($absPath) || !is_file($absPath)) {
        // Fallback : essayer directement concaténation sans resolve (chemin absolu stocké)
        if (strpos($file['file_path'], ':') !== false || str_starts_with($file['file_path'], '/')) {
            $absPath = $file['file_path'];
        }
        if (!file_exists($absPath) || !is_file($absPath)) {
            header('Location: projects.php?error=file_not_exists');
            exit();
        }
    }

    $size = @filesize($absPath) ?: (int)($file['file_size'] ?? 0);
    $mime = !empty($file['file_type']) ? $file['file_type'] : 'application/octet-stream';

    // Si type générique, deviner depuis extension
    if ($mime === 'application/octet-stream' || empty($mime)) {
        $ext = strtolower(pathinfo($file['original_name'] ?? $file['file_path'], PATHINFO_EXTENSION));
        $map = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'txt' => 'text/plain', 'csv' => 'text/csv',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        if (isset($map[$ext])) $mime = $map[$ext];
    }

    $downloadName = fallbackDownloadName($file['display_name'] ?? null, $file['original_name'] ?? pathinfo($absPath, PATHINFO_BASENAME));

    while (ob_get_level() > 0) @ob_end_clean();
    if (function_exists('header_remove')) @header_remove();

    @header('Content-Type: ' . $mime, true);
    @header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName), true);
    if ($size > 0) {
        @header('Content-Length: ' . $size, true);
    }
    @header('Content-Transfer-Encoding: binary', true);
    @header('Cache-Control: no-store, no-cache, must-revalidate', true);
    @header('Pragma: no-cache', true);
    @header('Expires: Thu, 01 Jan 1970 00:00:00 GMT', true);
    @header('Accept-Ranges: bytes', true);
    @http_response_code(200);

    // Envoyer le fichier par petits morceaux (évite memory_limit pour les gros fichiers)
    if ($fp = @fopen($absPath, 'rb')) {
        while (!feof($fp)) {
            echo fread($fp, 8192);
            if (connection_status() !== CONNECTION_NORMAL) {
                @fclose($fp);
                exit();
            }
        }
        @fclose($fp);
    } else {
        readfile($absPath);
    }
    exit();

} catch (Throwable $e) {
    header('Location: projects.php?error=database_error');
    exit();
}
