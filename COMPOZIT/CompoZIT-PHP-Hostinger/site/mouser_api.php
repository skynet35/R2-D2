<?php
/* =========================================================================
   Client API Mouser (recherche de composants)
   ---------------------------------------------------------------------------
   Fournit :
     - mouserIsConfigured()  : la clé API est-elle renseignée ?
     - mouserSearchParts()   : recherche de composants (MPN, n° Mouser ou mot-clé),
                               résultats normalisés au même format que Nexar

   Particularités de l'API Mouser par rapport à Nexar :
     - authentification par simple clé API en paramètre d'URL (pas d'OAuth) ;
     - catalogue Mouser uniquement (pas d'agrégation multi-distributeurs) ;
     - quota exprimé en débit (appels/minute), pas en "parts" à vie.

   Documentation : https://www.mouser.com/api-hub/
   ========================================================================= */

require_once __DIR__ . '/config.php';

const MOUSER_SEARCH_URL = 'https://api.mouser.com/api/v1/search/';

/**
 * La clé API Mouser est-elle renseignée ?
 */
function mouserIsConfigured(): bool {
    return trim((string) MOUSER_API_KEY) !== '';
}

/**
 * Requête HTTP POST JSON vers l'API Mouser.
 *
 * @return array{code:int, body:string, error:string}
 */
function mouserHttpRequest(string $url, ?array $payload = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

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
 * Exécute une recherche Mouser et renvoie les parts brutes.
 *
 * @param  string $endpoint 'partnumber' | 'keyword'
 * @return array{ok:bool, parts:array, number:int, message?:string}
 */
function mouserRequest(string $endpoint, array $payload): array {
    $url = MOUSER_SEARCH_URL . $endpoint . '?apiKey=' . urlencode((string) MOUSER_API_KEY);
    $response = mouserHttpRequest($url, $payload);

    if ($response['error'] !== '') {
        return ['ok' => false, 'parts' => [], 'number' => 0, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] === 403) {
        return ['ok' => false, 'parts' => [], 'number' => 0, 'message' => 'Clé API Mouser refusée (403). Vérifiez la clé et son autorisation.'];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'parts' => [], 'number' => 0, 'message' => 'Réponse Mouser inattendue (HTTP ' . $response['code'] . ').'];
    }

    // Mouser renvoie parfois un corps vide lorsqu'il n'y a aucun résultat.
    if (trim($response['body']) === '') {
        return ['ok' => true, 'parts' => [], 'number' => 0];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'parts' => [], 'number' => 0, 'message' => 'Réponse Mouser illisible.'];
    }

    // Les erreurs applicatives arrivent en HTTP 200 : il faut les inspecter.
    if (!empty($data['Errors']) && is_array($data['Errors'])) {
        $first = (array) $data['Errors'][0];
        return [
            'ok'      => false,
            'parts'   => [],
            'number'  => 0,
            'message' => 'Mouser : ' . (string) ($first['Message'] ?? 'erreur inconnue'),
        ];
    }

    $results = (array) ($data['SearchResults'] ?? []);

    return [
        'ok'     => true,
        'parts'  => (array) ($results['Parts'] ?? []),
        'number' => (int) ($results['NumberOfResult'] ?? 0),
    ];
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function mouserNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Convertit un prix Mouser en nombre.
 * Mouser renvoie les prix sous forme de chaîne avec symbole : "$0.10", "1,23 €".
 */
function mouserParsePrice($raw): ?float {
    $clean = (string) preg_replace('/[^0-9,.\-]/', '', (string) $raw);
    if ($clean === '') {
        return null;
    }

    if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
        $clean = str_replace(',', '.', $clean);   // virgule décimale
    } else {
        $clean = str_replace(',', '', $clean);    // virgule = séparateur de milliers
    }

    return is_numeric($clean) ? (float) $clean : null;
}

/**
 * Recherche la valeur d'un attribut produit par une liste de noms possibles.
 * Les libellés Mouser varient d'une famille de produits à l'autre.
 */
function mouserFindAttribute(array $attributes, array $names): ?string {
    foreach ($attributes as $attribute) {
        $attributeName = mb_strtolower(trim((string) ($attribute['AttributeName'] ?? '')), 'UTF-8');
        if ($attributeName === '') {
            continue;
        }

        foreach ($names as $name) {
            if (mb_strpos($attributeName, mb_strtolower($name, 'UTF-8')) !== false) {
                $value = trim((string) ($attribute['AttributeValue'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return null;
}

/**
 * Construit un bloc texte riche avec les attributs techniques Mouser.
 * Cela permet au formulaire côté client d'extraire davantage de paramètres
 * (VF, IFSM, IR, etc.) sans multiplier les appels API.
 */
function mouserBuildSpecText(array $part): string {
    $chunks = [];

    foreach ([
        'Description',
        'Category',
        'Manufacturer',
        'ManufacturerPartNumber',
        'MouserPartNumber',
    ] as $key) {
        $value = trim((string) ($part[$key] ?? ''));
        if ($value !== '') {
            $chunks[] = $value;
        }
    }

    foreach ((array) ($part['ProductAttributes'] ?? []) as $attribute) {
        if (!is_array($attribute)) {
            continue;
        }
        $name  = trim((string) ($attribute['AttributeName'] ?? ''));
        $value = trim((string) ($attribute['AttributeValue'] ?? ''));
        if ($name !== '' || $value !== '') {
            $chunks[] = trim($name . ' ' . $value);
        }
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ; ', $chunks)));
}

/**
 * Détermine la meilleure offre (premier palier de prix le plus bas).
 * Mouser n'a qu'un seul vendeur : la référence fournisseur est le n° Mouser.
 */
function mouserFindBestOffer(array $part): ?array {
    $breaks = (array) ($part['PriceBreaks'] ?? []);
    $best = null;

    foreach ($breaks as $tier) {
        $price = mouserParsePrice($tier['Price'] ?? '');
        if ($price === null) {
            continue;
        }

        $quantity = (int) ($tier['Quantity'] ?? 1);
        if ($best === null || $price < $best['price']) {
            $best = [
                'price'         => $price,
                'currency'      => (string) ($tier['Currency'] ?? ''),
                'quantity'      => max(1, $quantity),
                'supplier_name' => 'Mouser',
                'supplier_ref'  => (string) ($part['MouserPartNumber'] ?? ''),
                'inventory'     => (int) ($part['AvailabilityInStock'] ?? 0),
                'click_url'     => (string) ($part['ProductDetailUrl'] ?? ''),
                'tax'           => 'excl',
            ];
        }
    }

    return $best;
}

/**
 * Normalise une part Mouser au même format que les résultats Nexar, afin que
 * l'interface du formulaire de création soit commune aux deux sources.
 */
function mouserNormalizePart(array $part): array {
    $attributes = (array) ($part['ProductAttributes'] ?? []);

    $package  = (string) (mouserFindAttribute($attributes, ['package / case', 'package', 'case', 'boîtier']) ?? '');
    $pinsRaw  = (string) (mouserFindAttribute($attributes, ['number of pins', 'pin count', 'pins', 'number of positions', 'broches']) ?? '');
    $mounting = (string) (mouserFindAttribute($attributes, ['mounting style', 'mounting type', 'termination style', 'type de montage']) ?? '');

    $pins = null;
    if ($pinsRaw !== '' && preg_match('/\d+/', $pinsRaw, $matches)) {
        $pins = (int) $matches[0];
    }

    $mpn = (string) ($part['ManufacturerPartNumber'] ?? '');
    $mouserRef = (string) ($part['MouserPartNumber'] ?? '');
    $label = $mpn !== '' ? $mpn : $mouserRef;

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => (string) ($part['Description'] ?? ''),
        'manufacturer' => (string) ($part['Manufacturer'] ?? ''),
        'category'     => (string) ($part['Category'] ?? ''),
        'package'      => $package,
        'pins'         => $pins,
        'smd'          => (stripos($mounting, 'smd') !== false
                           || stripos($mounting, 'smt') !== false
                           || stripos($mounting, 'surface') !== false) ? 'Yes' : 'No',
        'datasheet'    => (string) ($part['DataSheetUrl'] ?? ''),
        'image_url'    => (string) ($part['ImagePath'] ?? ''),
        'spec_text'    => mouserBuildSpecText($part),
        'best_offer'   => mouserFindBestOffer($part),
        'skus'         => $mouserRef !== '' ? [$mouserRef] : [],
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis n° Mouser exact,
 * puis correspondances partielles.
 */
function mouserRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = mouserNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (mouserNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez Mouser et renvoie une liste normalisée.
 *
 * On interroge d'abord l'endpoint "partnumber" (MPN ou n° Mouser), puis on
 * bascule sur la recherche par mot-clé si aucune référence ne correspond :
 * c'est ce qui permet de retrouver un composant décrit en langage naturel.
 *
 * $limit : null = utilise le réglage MOUSER_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function mouserSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if (!mouserIsConfigured()) {
        return ['ok' => false, 'message' => 'API Mouser non configurée : renseignez la clé API dans Paramètres → Réglage API.'];
    }

    if ($limit === null) {
        $limit = (int) MOUSER_SEARCH_LIMIT;
    }
    $limit = max(1, min(50, $limit));

    // 1) Recherche par référence (MPN ou n° Mouser).
    $result = mouserRequest('partnumber', [
        'SearchByPartRequest' => [
            'mouserPartNumber'  => $query,
            'partSearchOptions' => 'BeginsWith',
        ],
    ]);

    // 2) Repli sur la recherche par mot-clé.
    if ($result['ok'] && empty($result['parts'])) {
        $result = mouserRequest('keyword', [
            'SearchByKeywordRequest' => [
                'keyword'        => $query,
                'records'        => $limit,
                'startingRecord' => 0,
                'searchOptions'  => 'None',
            ],
        ]);
    }

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche Mouser.'];
    }

    $parts = [];
    foreach ($result['parts'] as $rawPart) {
        if (is_array($rawPart)) {
            $parts[] = mouserNormalizePart($rawPart);
        }
    }

    // Tri par pertinence (tri PHP stable : l'ordre Mouser est conservé à score égal).
    $queryNormalized = mouserNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return mouserRelevanceScore($a, $queryNormalized) <=> mouserRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => $result['number'] > 0 ? $result['number'] : count($parts),
        'results' => array_slice($parts, 0, $limit),
    ];
}
