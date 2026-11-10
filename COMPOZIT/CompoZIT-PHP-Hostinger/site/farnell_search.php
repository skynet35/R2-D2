<?php
/* =========================================================================
   Endpoint AJAX — recherche de composants via l'API Farnell / element14
   ---------------------------------------------------------------------------
   Réponse JSON (même format que nexar_search.php et mouser_search.php) :
     { "ok": true,  "hits": 42, "results": [ { mpn, manufacturer, ... } ] }
     { "ok": false, "message": "..." }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'farnell_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function farnellJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    farnellJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

if (!farnellIsConfigured()) {
    farnellJsonResponse(['ok' => false, 'message' => 'API Farnell non configurée : renseignez la clé API dans Paramètres → Réglage API.']);
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    farnellJsonResponse(['ok' => false, 'message' => 'Saisissez au moins 2 caractères.']);
}

// Limite : paramètre d'URL facultatif, sinon réglage FARNELL_SEARCH_LIMIT.
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

$result = farnellSearchParts($query, $limit);

if (!$result['ok']) {
    farnellJsonResponse(['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche Farnell.']);
}

farnellJsonResponse([
    'ok'      => true,
    'hits'    => $result['hits'] ?? count($result['results']),
    'results' => $result['results'],
]);
