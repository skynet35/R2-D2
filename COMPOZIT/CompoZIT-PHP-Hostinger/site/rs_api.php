<?php
/* =========================================================================
   Client API RS Components / RS Online (Product Search)
   ---------------------------------------------------------------------------
   Fournit :
     - rsIsConfigured()  : la clé API (App ID) est-elle renseignée ?
     - rsSearchParts()   : recherche de composants (MPN, référence RS,
                           mot-clé), résultats normalisés au même format que
                           Nexar, Mouser et Farnell.

   Particularités de cette API :
     - l'accès se fait avec un « App ID » fourni par RS Components
       (à demander à votre commercial RS) ;
     - l'App ID est transmis dans l'en-tête X-API-KEY ;
     - le magasin régional (RS_STORE_ID) détermine le catalogue, les prix
       et la devise.

   IMPORTANT : RS Components ne publie pas sa documentation d'API
   publiquement. Les URLs et en-têtes ci-dessous constituent le point
   d'entrée attendu et sont regroupés en tête de fichier afin d'être ajustés
   facilement dès que vous disposerez des spécifications réelles fournies par
   votre interlocuteur RS.
   ========================================================================= */

require_once __DIR__ . '/config.php';

/* --- Point d'entrée de l'API (à ajuster si RS fournit une URL dédiée) --- */
const RS_SEARCH_URL   = 'https://api.rs-online.com/api/v1/search';
const RS_API_KEY_HEAD = 'X-API-KEY';

/**
 * La clé API RS (App ID) est-elle renseignée ?
 */
function rsIsConfigured(): bool {
    return trim((string) RS_API_KEY) !== '';
}

/**
 * Magasin régional interrogé (détermine catalogue, prix et devise).
 */
function rsStoreDomain(): string {
    $store = trim((string) RS_STORE_ID);
    return $store !== '' ? $store : 'fr.rs-online.com';
}

/**
 * Nom du distributeur affiché.
 */
function rsSupplierName(): string {
    return 'RS Components';
}

/**
 * Devise du magasin. L'API ne renvoie pas toujours la devise dans le prix :
 * on se rabat sur la devise du magasin régional.
 */
function rsCurrency(): string {
    $store = strtolower(rsStoreDomain());

    if (strpos($store, 'uk.rs-online') !== false) {
        return 'GBP';
    }
    if (strpos($store, 'us.rs-online') !== false || strpos($store, 'us.rsdelivers') !== false) {
        return 'USD';
    }

    return 'EUR';
}

/**
 * Requête HTTP GET (curl) avec l'en-tête d'authentification RS.
 *
 * @return array{code:int, body:string, error:string}
 */
function rsHttpRequest(string $url, int $timeout = 25): array {
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
            RS_API_KEY_HEAD . ': ' . trim((string) RS_API_KEY),
        ],
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
 * Extrait la liste de produits d'une réponse JSON, quelle que soit
 * l'enveloppe utilisée (products, results, items…).
 */
function rsExtractProducts(array $data): ?array {
    foreach (['products', 'results', 'items', 'documents', 'parts'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
    }

    // Certaines réponses encapsulent la liste dans un objet « data ».
    if (isset($data['data']) && is_array($data['data'])) {
        return rsExtractProducts($data['data']);
    }

    return null;
}

/**
 * Exécute une recherche RS et renvoie les produits bruts.
 *
 * @return array{ok:bool, products:array, number:int, message?:string}
 */
function rsRequest(string $term, int $limit): array {
    $params = [
        'q'      => $term,
        'limit'  => (string) $limit,
        'store'  => rsStoreDomain(),
    ];

    $response = rsHttpRequest(RS_SEARCH_URL . '?' . http_build_query($params));

    if ($response['error'] !== '') {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] === 401 || $response['code'] === 403) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Accès refusé par RS Components (HTTP ' . $response['code'] . ') : App ID invalide ou non autorisé.'];
    }

    if ($response['code'] === 404) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => "Point d'entrée RS introuvable (404). L'URL de l'API RS doit être ajustée dans rs_api.php."];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Réponse RS inattendue (HTTP ' . $response['code'] . ').'];
    }

    if (trim($response['body']) === '') {
        return ['ok' => true, 'products' => [], 'number' => 0];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Réponse RS illisible.'];
    }

    $products = rsExtractProducts($data);
    if ($products === null) {
        $message = (string) ($data['message'] ?? $data['error'] ?? '');
        return [
            'ok'       => false,
            'products' => [],
            'number'   => 0,
            'message'  => 'RS : ' . ($message !== '' ? $message : 'réponse inexploitable.'),
        ];
    }

    $number = (int) ($data['total'] ?? $data['count'] ?? $data['numberOfResults'] ?? count($products));

    return ['ok' => true, 'products' => $products, 'number' => $number];
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function rsNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Récupère une valeur dans un tableau à partir de plusieurs clés possibles.
 */
function rsPick(array $source, array $keys): string {
    foreach ($keys as $key) {
        if (isset($source[$key]) && is_scalar($source[$key])) {
            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Détermine si le composant est en montage de surface (SMD) à partir du boîtier.
 */
function rsDetectSmd(string $package): string {
    $boitier = strtoupper(trim($package));
    if ($boitier === '') {
        return 'No';
    }

    $cms = ['SOIC', 'SO-', 'SOP', 'SSOP', 'TSSOP', 'MSOP', 'QFN', 'DFN', 'LGA', 'SOT', 'SOD',
            'SC-70', 'SC-88', 'TQFP', 'LQFP', 'QFP', 'BGA', 'CSP', 'MELF', 'DPAK', 'D2PAK',
            'TO-252', 'TO-263', 'TO-277', 'SMA', 'SMB', 'SMC',
            '0201', '0402', '0603', '0805', '1206', '1210', '1812', '2010', '2512'];
    foreach ($cms as $needle) {
        if (strpos($boitier, $needle) !== false) {
            return 'Yes';
        }
    }

    return 'No';
}

/**
 * Détermine la meilleure offre (premier palier de prix, donc la plus petite quantité).
 */
function rsFindBestOffer(array $product): ?array {
    $price = null;
    foreach (['price', 'unitPrice', 'cost', 'priceExVat'] as $key) {
        if (isset($product[$key]) && is_numeric($product[$key])) {
            $price = (float) $product[$key];
            break;
        }
    }

    // Repli sur un premier palier de prix (priceBreaks / prices).
    if ($price === null) {
        foreach (['priceBreaks', 'prices'] as $key) {
            $breaks = (array) ($product[$key] ?? []);
            foreach ($breaks as $tier) {
                if (is_array($tier) && isset($tier['price']) && is_numeric($tier['price'])) {
                    $price = (float) $tier['price'];
                    break 2;
                }
            }
        }
    }

    if ($price === null) {
        return null;
    }

    return [
        'price'         => $price,
        'currency'      => rsPick($product, ['currency', 'currencyCode']) ?: rsCurrency(),
        'quantity'      => max(1, (int) ($product['minOrderQuantity'] ?? $product['quantity'] ?? 1)),
        'supplier_name' => rsSupplierName(),
        'supplier_ref'  => rsPick($product, ['stockNumber', 'rsStockNumber', 'sku', 'id']),
        'inventory'     => (int) ($product['stock'] ?? $product['availability'] ?? $product['inStock'] ?? 0),
        'click_url'     => rsPick($product, ['url', 'productUrl', 'link']),
        'tax'           => 'excl',
    ];
}

/**
 * Normalise un produit RS au même format que les résultats des autres sources,
 * afin que le formulaire de création soit commun à toutes les sources.
 */
function rsNormalizePart(array $product): array {
    $mpn     = rsPick($product, ['mpn', 'manufacturerPartNumber', 'translatedManufacturerPartNumber']);
    $sku     = rsPick($product, ['stockNumber', 'rsStockNumber', 'sku', 'id']);
    $label   = $mpn !== '' ? $mpn : $sku;
    $package = rsPick($product, ['package', 'case', 'packageCase']);

    $manufacturer = rsPick($product, ['brand', 'brandName', 'manufacturer', 'vendorName']);
    if (is_array($product['brand'] ?? null)) {
        $manufacturer = rsPick((array) $product['brand'], ['name', 'brandName']);
    }

    $description = rsPick($product, ['description', 'name', 'title', 'displayName']);

    $image = '';
    if (isset($product['image']) && is_string($product['image'])) {
        $image = trim($product['image']);
    } elseif (is_array($product['image'] ?? null)) {
        $image = rsPick((array) $product['image'], ['url', 'imageUrl', 'src']);
    }
    if ($image === '') {
        $image = rsPick($product, ['imageUrl', 'thumbnail']);
    }

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => $description,
        'manufacturer' => $manufacturer,
        'category'     => rsPick($product, ['category', 'categoryName', 'productType']),
        'package'      => $package,
        'pins'         => null,
        'smd'          => rsDetectSmd($package),
        'datasheet'    => rsPick($product, ['datasheetUrl', 'datasheet', 'documentUrl']),
        'image_url'    => $image,
        'best_offer'   => rsFindBestOffer($product),
        'skus'         => $sku !== '' ? [$sku] : [],
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis référence RS exacte,
 * puis correspondances partielles.
 */
function rsRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = rsNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (rsNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez RS Components et renvoie une liste normalisée.
 *
 * $limit : null = utilise le réglage RS_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function rsSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if (!rsIsConfigured()) {
        return ['ok' => false, 'message' => 'API RS Components non configurée : renseignez la clé API (App ID) dans Paramètres → Réglage API.'];
    }

    if ($limit === null) {
        $limit = (int) RS_SEARCH_LIMIT;
    }
    $limit = max(1, min(50, $limit));

    $result = rsRequest($query, $limit);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche RS Components.'];
    }

    $parts = [];
    foreach ($result['products'] as $rawProduct) {
        if (is_array($rawProduct)) {
            $parts[] = rsNormalizePart($rawProduct);
        }
    }

    // Tri par pertinence (tri PHP stable : l'ordre RS est conservé à score égal).
    $queryNormalized = rsNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return rsRelevanceScore($a, $queryNormalized) <=> rsRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => $result['number'] > 0 ? $result['number'] : count($parts),
        'results' => array_slice($parts, 0, $limit),
    ];
}
