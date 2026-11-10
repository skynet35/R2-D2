<?php
/* =========================================================================
   Endpoint AJAX — recherche de composants via l'API RS Components
   ---------------------------------------------------------------------------
   Réponse JSON (même format que nexar_search.php, mouser_search.php et
   farnell_search.php) :
     { "ok": true,  "hits": 42, "results": [ { mpn, manufacturer, ... } ] }
     { "ok": false, "message": "..." }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'rs_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function rsJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    rsJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

if (!rsIsConfigured()) {
    rsJsonResponse(['ok' => false, 'message' => 'API RS Components non configurée : renseignez la clé API (App ID) dans Paramètres → Réglage API.']);
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    rsJsonResponse(['ok' => false, 'message' => 'Saisissez au moins 2 caractères.']);
}

// Limite : paramètre d'URL facultatif, sinon réglage RS_SEARCH_LIMIT.
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

$result = rsSearchParts($query, $limit);

if (!$result['ok']) {
    rsJsonResponse(['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche RS Components.']);
}

rsJsonResponse([
    'ok'      => true,
    'hits'    => $result['hits'] ?? count($result['results']),
    'results' => $result['results'],
]);
