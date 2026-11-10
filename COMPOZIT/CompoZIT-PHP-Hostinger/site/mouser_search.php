<?php
/* =========================================================================
   Endpoint AJAX — recherche de composants via l'API Mouser
   ---------------------------------------------------------------------------
   Réponse JSON (même format que nexar_search.php) :
     { "ok": true,  "hits": 42, "results": [ { mpn, manufacturer, ... } ] }
     { "ok": false, "message": "..." }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'mouser_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function mouserJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    mouserJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

if (!mouserIsConfigured()) {
    mouserJsonResponse(['ok' => false, 'message' => 'API Mouser non configurée : renseignez la clé API dans Paramètres → Réglage API.']);
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    mouserJsonResponse(['ok' => false, 'message' => 'Saisissez au moins 2 caractères.']);
}

// Limite : paramètre d'URL facultatif, sinon réglage MOUSER_SEARCH_LIMIT.
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

$result = mouserSearchParts($query, $limit);

if (!$result['ok']) {
    mouserJsonResponse(['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche Mouser.']);
}

mouserJsonResponse([
    'ok'      => true,
    'hits'    => $result['hits'] ?? count($result['results']),
    'results' => $result['results'],
]);
