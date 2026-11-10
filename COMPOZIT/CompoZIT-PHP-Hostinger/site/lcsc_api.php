<?php
/* =========================================================================
   Client API LCSC Electronics (catalogue JLCPCB / LCSC)
   ---------------------------------------------------------------------------
   Fournit :
     - lcscIsConfigured()  : toujours vrai (API publique sans clé)
     - lcscSearchParts()   : recherche de composants (référence LCSC « C2457 »,
                             MPN ou mot-clé), résultats normalisés au même
                             format que les autres sources.

   Particularités de cette API :
     - aucune authentification n'est requise ;
     - la recherche utilise l'API publique du catalogue JLCPCB
       (jlcpcb.com/api/overseas-pcb-order/…) qui alimente la bibliothèque LCSC ;
     - la recherche est pertinente (correspondance sur la référence, le
       fabricant et la description) et les prix sont exprimés en dollars US.

   Documentation : https://jlcpcb.com/parts
   ========================================================================= */

require_once __DIR__ . '/config.php';

const LCSC_SEARCH_URL = 'https://jlcpcb.com/api/overseas-pcb-order/v1/shoppingCart/smtGood/selectSmtComponentList';

/**
 * La source LCSC est-elle disponible ? (aucune clé requise)
 */
function lcscIsConfigured(): bool {
    return true;
}

/**
 * Requête HTTP POST JSON (curl).
 *
 * @return array{code:int, body:string, error:string}
 */
function lcscHttpRequest(string $url, string $jsonBody, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Origin: https://jlcpcb.com',
            'Referer: https://jlcpcb.com/parts',
        ],
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
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
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function lcscNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Détermine si le composant est en montage de surface (SMD).
 * La bibliothèque JLCPCB/LCSC est majoritairement en CMS ; on se base sur le
 * boîtier, avec un repli « Yes » lorsqu'un schéma de boîtier est présent.
 */
function lcscDetectSmd(string $package): string {
    $boitier = strtoupper(trim($package));
    if ($boitier === '') {
        return 'No';
    }

    $traversants = ['DIP', 'PDIP', 'DIL', 'TO-92', 'TO-220', 'TO-247', 'TO-3', 'TO-18', 'TO-39',
                    'TO-126', 'DO-41', 'DO-35', 'DO-15', 'DO-27', 'AXIAL', 'THROUGH', 'PLUG-IN'];
    foreach ($traversants as $needle) {
        if (strpos($boitier, $needle) !== false) {
            return 'No';
        }
    }

    return 'Yes';
}

/**
 * Recherche une valeur d'attribut par mot-clé (insensible à la casse).
 */
function lcscFindAttribute(array $component, array $keywords): ?string {
    foreach ((array) ($component['attributes'] ?? []) as $attribute) {
        $name = mb_strtolower(trim((string) ($attribute['attribute_name_en'] ?? '')), 'UTF-8');
        if ($name === '') {
            continue;
        }

        foreach ($keywords as $keyword) {
            if (mb_strpos($name, mb_strtolower($keyword, 'UTF-8')) !== false) {
                $value = trim((string) ($attribute['attribute_value_name'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return null;
}

/**
 * Construit l'URL de la vignette produit.
 */
function lcscImageUrl(array $component): string {
    $url = trim((string) ($component['minImage'] ?? ''));
    if ($url !== '') {
        return $url;
    }

    foreach ((array) ($component['imageList'] ?? []) as $image) {
        $candidate = trim((string) ($image['productImage'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return trim((string) ($component['componentImageUrl'] ?? ''));
}

/**
 * Détermine le premier palier de prix (petite quantité) d'un composant.
 */
function lcscFindBestOffer(array $component): ?array {
    $best = null;
    foreach ((array) ($component['componentPrices'] ?? []) as $tier) {
        $price = isset($tier['productPrice']) ? (float) $tier['productPrice'] : null;
        if ($price === null) {
            continue;
        }

        $quantity = max(1, (int) ($tier['startNumber'] ?? 1));
        if ($best === null || $quantity < $best['quantity']) {
            $best = [
                'price'         => $price,
                'currency'      => 'USD',
                'quantity'      => $quantity,
                'supplier_name' => 'LCSC',
                'supplier_ref'  => (string) ($component['componentCode'] ?? ''),
                'inventory'     => (int) ($component['stockCount'] ?? 0),
                'click_url'     => trim((string) ($component['lcscGoodsUrl'] ?? '')),
                'tax'           => 'excl',
            ];
        }
    }

    // Repli : prix de départ fourni séparément.
    if ($best === null && isset($component['initialPrice']) && is_numeric($component['initialPrice'])) {
        $best = [
            'price'         => (float) $component['initialPrice'],
            'currency'      => 'USD',
            'quantity'      => max(1, (int) ($component['minPurchaseNum'] ?? 1)),
            'supplier_name' => 'LCSC',
            'supplier_ref'  => (string) ($component['componentCode'] ?? ''),
            'inventory'     => (int) ($component['stockCount'] ?? 0),
            'click_url'     => trim((string) ($component['lcscGoodsUrl'] ?? '')),
            'tax'           => 'excl',
        ];
    }

    return $best;
}

/**
 * Normalise un composant LCSC au même format que les autres sources.
 */
function lcscNormalizePart(array $component): array {
    $mpn  = trim((string) ($component['componentModelEn'] ?? ''));
    $lcsc = trim((string) ($component['componentCode'] ?? ''));
    $label = $mpn !== '' ? $mpn : $lcsc;

    $package = trim((string) ($component['componentSpecificationEn'] ?? ''));

    $pins = null;
    $pinsRaw = lcscFindAttribute($component, ['number of pins', 'pin count', 'pins']);
    if ($pinsRaw !== null && preg_match('/\d+/', $pinsRaw, $matches)) {
        $pins = (int) $matches[0];
    }

    $category = trim((string) ($component['componentTypeEn'] ?? ''));
    if ($category === '') {
        $category = trim((string) ($component['firstSortName'] ?? ''));
    }

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => trim((string) ($component['describe'] ?? '')),
        'manufacturer' => trim((string) ($component['componentBrandEn'] ?? '')),
        'category'     => $category,
        'package'      => $package,
        'pins'         => $pins,
        'smd'          => lcscDetectSmd($package),
        'datasheet'    => trim((string) ($component['dataManualUrl'] ?? '')),
        'image_url'    => lcscImageUrl($component),
        'best_offer'   => lcscFindBestOffer($component),
        'skus'         => $lcsc !== '' ? [$lcsc] : [],
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis référence LCSC exacte,
 * puis correspondances partielles.
 */
function lcscRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = lcscNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (lcscNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez LCSC et renvoie une liste normalisée.
 *
 * $limit : null = utilise le réglage LCSC_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function lcscSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if ($limit === null) {
        $limit = (int) LCSC_SEARCH_LIMIT;
    }
    $limit = max(1, min(100, $limit));

    $body = json_encode([
        'currentPage'          => 1,
        'pageSize'             => $limit,
        'keyword'              => $query,
        'componentLibraryType' => null,
        'searchSource'         => 'search',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $response = lcscHttpRequest(LCSC_SEARCH_URL, $body);

    if ($response['error'] !== '') {
        return ['ok' => false, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'message' => 'Réponse LCSC inattendue (HTTP ' . $response['code'] . ').'];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Réponse LCSC illisible.'];
    }

    if (isset($data['code']) && (int) $data['code'] !== 200) {
        return ['ok' => false, 'message' => 'LCSC : ' . (string) ($data['message'] ?? 'requête refusée.')];
    }

    $pageInfo = (array) ($data['data']['componentPageInfo'] ?? []);
    $rawList  = (array) ($pageInfo['list'] ?? []);

    $parts = [];
    foreach ($rawList as $rawComponent) {
        if (is_array($rawComponent)) {
            $parts[] = lcscNormalizePart($rawComponent);
        }
    }

    // Tri par pertinence (tri stable : l'ordre LCSC est conservé à score égal).
    $queryNormalized = lcscNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return lcscRelevanceScore($a, $queryNormalized) <=> lcscRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => (int) ($pageInfo['total'] ?? count($parts)),
        'results' => array_slice($parts, 0, $limit),
    ];
}
