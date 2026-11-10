<?php
while (ob_get_level() > 0) @ob_end_clean();
if (function_exists('header_remove')) @header_remove();
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(E_ALL);
header_register_callback(function() {
    if (function_exists('header_remove')) header_remove('X-Powered-By');
});

function sendJson($payload, $code = 200) {
    while (ob_get_level() > 0) @ob_end_clean();
    if (function_exists('header_remove')) @header_remove();
    @http_response_code($code);
    @header('Content-Type: application/json; charset=utf-8', true);
    @header('X-Content-Type-Options: nosniff', true);
    @header('Cache-Control: no-store, no-cache, must-revalidate', true);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $json = preg_replace('/^\xEF\xBB\xBF+/', '', (string)$json);
    echo trim($json);
    exit();
}

try {
    require_once __DIR__ . '/session_init.php';
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    sendJson(['success' => false, 'message' => 'Init error: ' . $e->getMessage()], 500);
}

if (!isset($_SESSION['user_id'])) {
    sendJson(['success' => false, 'message' => 'Non autorisé'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJson(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$project_id = (int)($_POST['project_id'] ?? 0);
$item_id = (int)($_POST['item_id'] ?? 0);
$progress_change = (int)($_POST['progress_change'] ?? 0);

if ($project_id <= 0 || $item_id <= 0) {
    sendJson(['success' => false, 'message' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    sendJson(['success' => false, 'message' => 'DB error: ' . $e->getMessage()], 500);
}

try {
    $stmtCheck = $pdo->prepare("SELECT p.owner, pi.quantity, pi.quantity_completed, pi.unit, pi.name 
                                FROM project_items pi JOIN projects p ON p.id = pi.project_id 
                                WHERE pi.id = ? AND pi.project_id = ?");
    $stmtCheck->execute([$item_id, $project_id]);
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJson(['success' => false, 'message' => 'Élément projet non trouvé'], 404);
    }
    if ((int)$row['owner'] !== (int)$_SESSION['user_id']) {
        sendJson(['success' => false, 'message' => 'Non autorisé (propriétaire)'], 403);
    }

    $total_quantity = (float)$row['quantity'];
    $current_completed = (float)($row['quantity_completed'] ?? 0);
    $unit = strtolower(trim((string)$row['unit']));

    $increment = 1;
    if (strpos($unit, 'heure') !== false || strpos($unit, 'h') !== false) {
        $increment = 0.5;
    }

    if ($progress_change > 0) {
        $new_completed = min($total_quantity, $current_completed + $increment);
    } else {
        $new_completed = max(0, $current_completed - $increment);
    }
    $new_progress = $total_quantity > 0 ? min(100, round(($new_completed / $total_quantity) * 100, 1)) : 0;

    $new_status = 'En attente';
    if ($new_progress >= 100) $new_status = 'Terminé';
    elseif ($new_progress > 0) $new_status = 'En cours';

    $stmt = $pdo->prepare("UPDATE project_items SET quantity_completed = ?, status = ? WHERE id = ? AND project_id = ?");
    $stmt->execute([$new_completed, $new_status, $item_id, $project_id]);

    $stmtComponentTotals = $pdo->prepare("SELECT COALESCE(SUM(quantity_used), 0) AS total_used, COALESCE(SUM(quantity_needed), 0) AS total_needed, COUNT(*) AS component_count FROM project_components WHERE project_id = ?");
    $stmtComponentTotals->execute([$project_id]);
    $componentTotals = $stmtComponentTotals->fetch(PDO::FETCH_ASSOC) ?: ['total_used' => 0, 'total_needed' => 0, 'component_count' => 0];
    $totalUsed = (int)($componentTotals['total_used'] ?? 0);
    $totalNeeded = (int)($componentTotals['total_needed'] ?? 0);
    $componentCount = (int)($componentTotals['component_count'] ?? 0);
    $componentsProgress = $totalNeeded > 0 ? min(100, round(($totalUsed / $totalNeeded) * 100, 1)) : 0;

    $stmtItemTotals = $pdo->prepare("SELECT COALESCE(SUM(quantity_completed), 0) AS items_completed_quantity, COALESCE(SUM(quantity), 0) AS items_total_quantity, COUNT(*) AS item_count FROM project_items WHERE project_id = ?");
    $stmtItemTotals->execute([$project_id]);
    $itemTotals = $stmtItemTotals->fetch(PDO::FETCH_ASSOC) ?: ['items_completed_quantity' => 0, 'items_total_quantity' => 0, 'item_count' => 0];
    $itemsCompletedQuantity = (float)($itemTotals['items_completed_quantity'] ?? 0);
    $itemsTotalQuantity = (float)($itemTotals['items_total_quantity'] ?? 0);
    $itemCount = (int)($itemTotals['item_count'] ?? 0);
    $itemsProgress = $itemsTotalQuantity > 0 ? min(100, round(($itemsCompletedQuantity / $itemsTotalQuantity) * 100, 1)) : 0;

    $totalElements = $componentCount + $itemCount;
    if ($totalElements > 0) {
        $globalProgress = (($componentsProgress * $componentCount) + ($itemsProgress * $itemCount)) / $totalElements;
    } else {
        $globalProgress = 0;
    }
    $globalProgress = min(100, round($globalProgress, 1));

    sendJson([
        'success' => true,
        'message' => 'Progression mise à jour',
        'total_quantity' => $total_quantity,
        'completed_quantity' => $new_completed,
        'unit' => $row['unit'],
        'progress_percent' => $new_progress,
        'status' => $new_status,
        'can_minus' => $new_completed > 0,
        'can_plus' => $new_completed < $total_quantity,
        'total_used' => $totalUsed,
        'total_needed' => $totalNeeded,
        'items_completed_quantity' => $itemsCompletedQuantity,
        'items_total_quantity' => $itemsTotalQuantity,
        'global_progress_percent' => $globalProgress,
    ], 200);
} catch (Throwable $e) {
    sendJson(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
}
