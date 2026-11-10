<?php
/* =========================================================================
   Endpoint AJAX — recherche de composants via l'API Nexar / Octopart
   ---------------------------------------------------------------------------
   Réponse JSON :
     { "ok": true,  "results": [ { mpn, name, description, manufacturer, ... } ] }
     { "ok": false, "message": "..." }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'nexar_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function nexarJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    nexarJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

if (!nexarIsConfigured()) {
    nexarJsonResponse(['ok' => false, 'message' => 'API Nexar non configurée : renseignez NEXAR_CLIENT_ID et NEXAR_CLIENT_SECRET dans config.local.php.']);
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    nexarJsonResponse(['ok' => false, 'message' => 'Saisissez au moins 2 caractères.']);
}

// Limite : paramètre d'URL facultatif, sinon réglage NEXAR_SEARCH_LIMIT.
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

$result = nexarSearchParts($query, $limit);

if (!$result['ok']) {
    nexarJsonResponse(['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche Nexar.']);
}

nexarJsonResponse([
    'ok'      => true,
    'hits'    => $result['hits'] ?? count($result['results']),
    'results' => $result['results'],
]);
