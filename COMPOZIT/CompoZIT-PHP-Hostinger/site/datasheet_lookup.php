<?php
/* =========================================================================
   Endpoint AJAX — repli de fiche technique (datasheet) entre sources
   ---------------------------------------------------------------------------
   Quand la source interrogée ne fournit pas de datasheet, on cherche le PDF
   officiel auprès des autres sources configurées, dans l'ordre :
   Datasheets.com, TME, Farnell, LCSC, DigiKey, Mouser puis Nexar.

   Entrée  : ?mpn=...&manufacturer=...&exclude=tme
             (fabricant facultatif ; « exclude » évite de réinterroger la
              source qui vient déjà de répondre sans datasheet)
   Sortie  : { "ok": true, "datasheet": "https://…pdf", "source": "Farnell", "mpn": "…" }
             { "ok": false, "message": "…" }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'nexar_api.php';
require_once 'mouser_api.php';
require_once 'farnell_api.php';
require_once 'digikey_api.php';
require_once 'tme_api.php';
require_once 'lcsc_api.php';
require_once 'datasheets_api.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function datasheetJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Parmi les résultats normalisés d'une source, retient la meilleure datasheet.
 * On privilégie une correspondance exacte de MPN et, si le fabricant est connu,
 * du fabricant ; à défaut on prend le premier résultat qui en fournit une
 * (les résultats sont déjà triés par pertinence).
 */
function datasheetPickFromResults(array $results, string $mpn, string $manufacturer): string {
    $mpnNormalized  = nexarNormalizeRef($mpn);
    $manuNormalized = nexarNormalizeRef($manufacturer);

    $best = '';
    $bestRank = null;

    foreach ($results as $part) {
        $url = trim((string) ($part['datasheet'] ?? ''));
        if ($url === '') {
            continue;
        }

        $partRef  = nexarNormalizeRef((string) ($part['mpn'] ?? ''));
        $sameMpn  = $partRef !== '' && $partRef === $mpnNormalized;
        $sameManu = $manuNormalized === '' || nexarNormalizeRef((string) ($part['manufacturer'] ?? '')) === $manuNormalized;

        // La référence doit avoir un rapport avec celle demandée : on refuse un
        // PDF qui proviendrait d'un composant sans lien (ex. « LM317 » pour
        // une recherche « LM358 »).
        $related = $sameMpn || ($partRef !== '' && $mpnNormalized !== ''
            && (strpos($partRef, $mpnNormalized) !== false || strpos($mpnNormalized, $partRef) !== false));

        if (!$related) {
            continue;
        }

        $rank = ($sameMpn && $sameManu) ? 0 : ($sameMpn ? 1 : 2);

        if ($bestRank === null || $rank < $bestRank) {
            $best = $url;
            $bestRank = $rank;
            if ($rank === 0) {
                break;
            }
        }
    }

    return $best;
}

if (!isset($_SESSION['user_id'])) {
    datasheetJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

$mpn          = trim((string) ($_GET['mpn'] ?? ''));
$manufacturer = trim((string) ($_GET['manufacturer'] ?? ''));
$exclude      = strtolower(trim((string) ($_GET['exclude'] ?? '')));

if (mb_strlen($mpn) < 2) {
    datasheetJsonResponse(['ok' => false, 'message' => 'Référence trop courte pour une recherche de datasheet.']);
}

// Ordre d'interrogation : sources les plus susceptibles de fournir un PDF d'abord.
$sources = [
    'datasheets' => ['label' => 'Datasheets.com', 'configured' => 'datasheetsIsConfigured', 'search' => 'datasheetsSearchParts'],
    'tme'     => ['label' => 'TME',     'configured' => 'tmeIsConfigured',     'search' => 'tmeSearchParts'],
    'farnell' => ['label' => 'Farnell', 'configured' => 'farnellIsConfigured', 'search' => 'farnellSearchParts'],
    'lcsc'    => ['label' => 'LCSC',    'configured' => 'lcscIsConfigured',    'search' => 'lcscSearchParts'],
    'digikey' => ['label' => 'DigiKey', 'configured' => 'digikeyIsConfigured', 'search' => 'digikeySearchParts'],
    'mouser'  => ['label' => 'Mouser',  'configured' => 'mouserIsConfigured',  'search' => 'mouserSearchParts'],
    'nexar'   => ['label' => 'Nexar',   'configured' => 'nexarIsConfigured',   'search' => 'nexarSearchParts'],
];

foreach ($sources as $key => $source) {
    if ($key === $exclude) {
        continue;
    }

    $configured = $source['configured'];
    if (is_callable($configured) && !$configured()) {
        continue;
    }

    $search = $source['search'];
    if (!is_callable($search)) {
        continue;
    }

    $result = $search($mpn, 5);
    if (empty($result['ok'])) {
        continue;
    }

    $url = datasheetPickFromResults((array) ($result['results'] ?? []), $mpn, $manufacturer);
    if ($url !== '') {
        datasheetJsonResponse([
            'ok'        => true,
            'datasheet' => $url,
            'source'    => $source['label'],
            'mpn'       => $mpn,
        ]);
    }
}

datasheetJsonResponse(['ok' => false, 'message' => 'Aucune fiche technique trouvée pour « ' . $mpn . ' » parmi les sources configurées.']);
