<?php
/* =========================================================================
   Client API Datasheets.com (recherche de fiches techniques)
   ---------------------------------------------------------------------------
   Fournit :
     - datasheetsIsConfigured()  : la cle API est-elle renseignee ?
     - datasheetsSearchParts()   : recherche par MPN / mot-cle, resultats
                                   normalises au meme format que les autres
                                   sources.

   Particularites de cette API :
     - authentification par simple cle API dans l'en-tete Authorization ;
     - endpoint unique GET /api/v1/search ;
     - la reponse expose directement datasheetUrl.

   Documentation : https://www.datasheets.com/api-docs
   ========================================================================= */

require_once __DIR__ . '/config.php';

const DATASHEETS_SEARCH_URL = 'https://www.datasheets.com/api/v1/search';

/**
 * La cle API Datasheets.com est-elle renseignee ?
 */
function datasheetsIsConfigured(): bool {
    return trim((string) DATASHEETS_API_KEY) !== '';
}

/**
 * Requete HTTP GET vers l'API Datasheets.com.
 *
 * @return array{code:int, body:string, error:string}
 */
function datasheetsHttpRequest(string $url, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . trim((string) DATASHEETS_API_KEY),
        ],
        CURLOPT_USERAGENT      => 'CompoZIT/1.0 (+Datasheets.com)',
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = (string) curl_error($ch);
    curl_close($ch);

    return [
        'code'  => $httpCode,
        'body'  => $response === false ? '' : (string) $response,
        'error' => $curlError,
    ];
}

/**
 * Normalise une reference pour comparaison (majuscules, sans separateurs).
 */
function datasheetsNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Tente de deduire le type de montage a partir du boitier.
 */
function datasheetsDetectSmd(string $package): string {
    $boitier = strtoupper(trim($package));
    if ($boitier === '') {
        return 'No';
    }

    $traversants = ['DIP', 'PDIP', 'DIL', 'TO-92', 'TO-220', 'TO-247', 'TO-3', 'TO-18',
                    'TO-39', 'TO-126', 'DO-41', 'DO-35', 'DO-15', 'DO-27', 'AXIAL', 'THROUGH'];
    foreach ($traversants as $needle) {
        if (strpos($boitier, $needle) !== false) {
            return 'No';
        }
    }

    return 'Yes';
}

/**
 * Normalise un resultat Datasheets.com au meme format que les autres sources.
 */
function datasheetsNormalizePart(array $part): array {
    $mpn = trim((string) ($part['mpn'] ?? ''));
    $category = trim(implode(' / ', array_filter([
        trim((string) ($part['category1'] ?? '')),
        trim((string) ($part['category2'] ?? '')),
        trim((string) ($part['category3'] ?? '')),
        trim((string) ($part['category4'] ?? '')),
    ])));

    return [
        'mpn'          => $mpn,
        'name'         => $mpn,
        'description'  => trim((string) ($part['description'] ?? $part['title'] ?? '')),
        'manufacturer' => trim((string) ($part['manufacturer'] ?? '')),
        'category'     => $category,
        'package'      => trim((string) ($part['packageType'] ?? '')),
        'pins'         => null,
        'smd'          => datasheetsDetectSmd((string) ($part['packageType'] ?? '')),
        'datasheet'    => trim((string) ($part['datasheetUrl'] ?? '')),
        'image_url'    => trim((string) ($part['primaryImageUrl'] ?? '')),
        'best_offer'   => null,
        'skus'         => [],
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis fabricant exact, puis
 * correspondance partielle sur la reference.
 */
function datasheetsRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = datasheetsNormalizeRef((string) ($part['mpn'] ?? ''));
    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 1;
    }

    return 2;
}

/**
 * Recherche des composants chez Datasheets.com et renvoie une liste normalisee.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function datasheetsSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une reference ou un mot-cle.'];
    }

    if (!datasheetsIsConfigured()) {
        return ['ok' => false, 'message' => 'API Datasheets.com non configuree : renseignez la cle API dans Parametres -> Reglage API.'];
    }

    $limit = $limit ?? (int) DATASHEETS_SEARCH_LIMIT;
    $limit = max(1, min(10, $limit));

    $url = DATASHEETS_SEARCH_URL . '?' . http_build_query([
        'q'     => $query,
        'limit' => $limit,
        'page'  => 1,
    ]);

    $response = datasheetsHttpRequest($url);

    if ($response['error'] !== '') {
        return ['ok' => false, 'message' => 'Erreur reseau : ' . $response['error']];
    }

    if ($response['code'] === 401) {
        return ['ok' => false, 'message' => 'Cle API Datasheets.com refusee (401).'];
    }

    if ($response['code'] === 429) {
        return ['ok' => false, 'message' => 'Limite de requetes Datasheets.com atteinte (429). Reessayez dans quelques secondes.'];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'message' => 'Reponse Datasheets.com inattendue (HTTP ' . $response['code'] . ').'];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Reponse Datasheets.com illisible.'];
    }

    $results = [];
    foreach ((array) ($data['results'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $results[] = datasheetsNormalizePart($row);
    }

    $queryNormalized = datasheetsNormalizeRef($query);
    usort($results, function (array $a, array $b) use ($queryNormalized): int {
        $scoreA = datasheetsRelevanceScore($a, $queryNormalized);
        $scoreB = datasheetsRelevanceScore($b, $queryNormalized);
        if ($scoreA !== $scoreB) {
            return $scoreA <=> $scoreB;
        }

        return strcmp((string) ($a['mpn'] ?? ''), (string) ($b['mpn'] ?? ''));
    });

    return [
        'ok'      => true,
        'hits'    => (int) ($data['count'] ?? count($results)),
        'results' => array_slice($results, 0, $limit),
    ];
}
