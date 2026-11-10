<?php
/* =========================================================================
   Endpoint AJAX — recherche de composants via l'API DigiKey
   ---------------------------------------------------------------------------
   Réponse JSON (même format que les autres sources) :
     { "ok": true,  "hits": 42, "results": [ { mpn, manufacturer, ... } ] }
     { "ok": false, "message": "..." }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'digikey_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function digikeyJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    digikeyJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

if (!digikeyIsConfigured()) {
    digikeyJsonResponse(['ok' => false, 'message' => 'API DigiKey non configurée : renseignez Client ID et Client Secret dans Paramètres → Réglage API.']);
}

$query = trim($_GET['q'] ?? '');
if (mb_strlen($query) < 2) {
    digikeyJsonResponse(['ok' => false, 'message' => 'Saisissez au moins 2 caractères.']);
}

// Limite : paramètre d'URL facultatif, sinon réglage DIGIKEY_SEARCH_LIMIT.
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

$result = digikeySearchParts($query, $limit);

if (!$result['ok']) {
    digikeyJsonResponse(['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche DigiKey.']);
}

digikeyJsonResponse([
    'ok'      => true,
    'hits'    => $result['hits'] ?? count($result['results']),
    'results' => $result['results'],
]);
