<?php
/* =========================================================================
   Client API Farnell / element14 / Newark (Product Search)
   ---------------------------------------------------------------------------
   Fournit :
     - farnellIsConfigured()  : la clé API est-elle renseignée ?
     - farnellSearchParts()   : recherche de composants (MPN, référence
                                distributeur ou mot-clé), résultats normalisés
                                au même format que Nexar, Mouser, DigiKey et RS.

   Particularités de cette API :
     - une seule clé API (« API Key ») couvre Farnell (Europe), Newark
       (Amérique du Nord) et element14 (Asie-Pacifique) ;
     - la clé est transmise en paramètre d'URL (callInfo.apiKey) ;
     - le magasin régional (FARNELL_STORE_ID) détermine le catalogue, les
       prix et la devise ;
     - la recherche se fait en GET sur api.element14.com/catalog/products.

   Les identifiants se définissent dans config.local.php :
   FARNELL_API_KEY / FARNELL_STORE_ID.

   Documentation : https://partner.element14.com/docs/Product_Search_API
   ========================================================================= */

require_once __DIR__ . '/config.php';

/* --- Point d'entrée de l'API Product Search (element14 / Farnell) --- */
const FARNELL_SEARCH_URL = 'https://api.element14.com/catalog/products';

/**
 * La clé API Farnell est-elle renseignée ?
 */
function farnellIsConfigured(): bool {
    return trim((string) FARNELL_API_KEY) !== '';
}

/**
 * Magasin régional interrogé (détermine catalogue, prix et devise).
 */
function farnellStoreDomain(): string {
    $store = trim((string) FARNELL_STORE_ID);
    return $store !== '' ? $store : 'fr.farnell.com';
}

/**
 * Nom du distributeur affiché selon le magasin régional.
 */
function farnellSupplierName(): string {
    $store = strtolower(farnellStoreDomain());

    if (strpos($store, 'newark') !== false) {
        return 'Newark';
    }
    if (strpos($store, 'element14') !== false) {
        return 'element14';
    }

    return 'Farnell';
}

/**
 * Devise du magasin. L'API ne renvoie pas toujours la devise dans le prix :
 * on se rabat sur la devise du magasin régional.
 */
function farnellCurrency(): string {
    $store = strtolower(farnellStoreDomain());

    if (strpos($store, 'uk.farnell') !== false) {
        return 'GBP';
    }
    if (strpos($store, 'newark') !== false) {
        return 'USD';
    }

    return 'EUR';
}

/**
 * Requête HTTP GET (curl) vers l'API Farnell.
 *
 * @return array{code:int, body:string, error:string}
 */
function farnellHttpRequest(string $url, int $timeout = 25): array {
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
 * l'enveloppe utilisée (keywordSearchReturn.products, results, items…).
 */
function farnellExtractProducts(array $data): ?array {
    $nodes = [$data];
    if (isset($data['keywordSearchReturn']) && is_array($data['keywordSearchReturn'])) {
        array_unshift($nodes, $data['keywordSearchReturn']);
    }

    foreach ($nodes as $node) {
        foreach (['products', 'results', 'items'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                return array_values($node[$key]);
            }
        }
    }

    // Certaines réponses encapsulent la liste dans un objet « data ».
    if (isset($data['data']) && is_array($data['data'])) {
        return farnellExtractProducts($data['data']);
    }

    return null;
}

/**
 * Récupère le nombre total de résultats annoncé par l'API.
 */
function farnellTotalResults(array $data): int {
    if (isset($data['keywordSearchReturn']['numberOfResults'])) {
        return (int) $data['keywordSearchReturn']['numberOfResults'];
    }

    foreach (['numberOfResults', 'total', 'count'] as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return (int) $data[$key];
        }
    }

    return 0;
}

/**
 * Extrait le message d'erreur d'une réponse Farnell, quelle que soit
 * l'enveloppe utilisée.
 */
function farnellErrorMessage(array $data): string {
    foreach ([
        $data['error'] ?? null,
        $data['message'] ?? null,
        $data['errorMessage'] ?? null,
        $data['keywordSearchReturn']['error'] ?? null,
        $data['keywordSearchReturn']['message'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return '';
}

/**
 * Exécute une recherche Farnell et renvoie les produits bruts.
 *
 * @return array{ok:bool, products:array, number:int, message?:string}
 */
function farnellRequest(string $term, int $limit): array {
    $params = [
        'term'                            => 'any:' . $term,
        'storeInfo.id'                    => farnellStoreDomain(),
        'resultsSettings.offset'          => '0',
        'resultsSettings.numberOfResults' => (string) $limit,
        'resultsSettings.responseGroup'   => 'large',
        'callInfo.responseDataFormat'     => 'json',
        'callInfo.apiKey'                 => trim((string) FARNELL_API_KEY),
    ];

    $response = farnellHttpRequest(FARNELL_SEARCH_URL . '?' . http_build_query($params));

    if ($response['error'] !== '') {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] === 401 || $response['code'] === 403) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Accès refusé par Farnell / element14 (HTTP ' . $response['code'] . ') : clé API invalide ou non autorisée.'];
    }

    if ($response['code'] === 404) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => "Point d'entrée Farnell introuvable (404). L'URL de l'API doit être ajustée dans farnell_api.php."];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Réponse Farnell inattendue (HTTP ' . $response['code'] . ').'];
    }

    if (trim($response['body']) === '') {
        return ['ok' => true, 'products' => [], 'number' => 0];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'products' => [], 'number' => 0, 'message' => 'Réponse Farnell illisible.'];
    }

    $products = farnellExtractProducts($data);
    if ($products === null) {
        $message = farnellErrorMessage($data);
        return [
            'ok'       => false,
            'products' => [],
            'number'   => 0,
            'message'  => 'Farnell : ' . ($message !== '' ? $message : 'réponse inexploitable.'),
        ];
    }

    $number = farnellTotalResults($data);

    return ['ok' => true, 'products' => $products, 'number' => $number > 0 ? $number : count($products)];
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function farnellNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Récupère une valeur dans un tableau à partir de plusieurs clés possibles.
 */
function farnellPick(array $source, array $keys): string {
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
 * Construit un bloc texte riche à partir des champs et attributs Farnell.
 * L'API peut exposer des noms différents selon les familles ; on concatène
 * les métadonnées disponibles pour augmenter la qualité du pré-remplissage.
 */
function farnellBuildSpecText(array $product): string {
    $chunks = [];

    foreach ([
        'translatedDescription',
        'description',
        'displayName',
        'name',
        'translatedManufacturerPartNumber',
        'manufacturerPartNumber',
        'mpn',
        'translatedPackage',
        'package',
        'packageName',
        'case',
        'category',
        'categoryName',
        'productType',
    ] as $key) {
        $value = trim((string) ($product[$key] ?? ''));
        if ($value !== '') {
            $chunks[] = $value;
        }
    }

    foreach ($product as $key => $value) {
        if (is_scalar($value)) {
            $normalizedKey = strtolower((string) $key);
            if (preg_match('/param|attribute|spec|character|volt|curr|power|surge|forward|recovery|capacit/i', $normalizedKey)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $chunks[] = $key . ' ' . $text;
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = farnellPick($item, ['attributeLabel', 'attributeName', 'name', 'label', 'displayName']);
                $val  = farnellPick($item, ['attributeValue', 'value', 'displayValue', 'text']);
                if ($name !== '' || $val !== '') {
                    $chunks[] = trim($name . ' ' . $val);
                }
            }
        }
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ; ', array_unique($chunks))));
}

/**
 * Convertit une valeur de prix (chaîne ou nombre) en float.
 */
function farnellToFloat($value): ?float {
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_string($value)) {
        $clean = str_replace([' ', ','], ['', '.'], trim($value));
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }

    return null;
}

/**
 * Détermine si le composant est en montage de surface (SMD) à partir du boîtier.
 */
function farnellDetectSmd(string $package): string {
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
 * Détermine la meilleure offre (premier palier de prix, donc la plus petite
 * quantité) à partir des paliers « prices » / « priceBreaks » de Farnell.
 */
function farnellFindBestOffer(array $product): ?array {
    $price    = null;
    $currency = farnellCurrency();
    $quantity = 1;

    foreach (['prices', 'priceBreaks'] as $key) {
        $breaks = (array) ($product[$key] ?? []);
        foreach ($breaks as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            $tierPrice = farnellToFloat($tier['price'] ?? null);
            if ($tierPrice === null) {
                continue;
            }
            $price = $tierPrice;
            $tierCurrency = farnellPick($tier, ['currency', 'currencyCode']);
            if ($tierCurrency !== '') {
                $currency = $tierCurrency;
            }
            $quantity = max(1, (int) ($tier['from'] ?? $tier['breakQuantity'] ?? 1));
            break 2;
        }
    }

    if ($price === null) {
        $flatPrice = farnellToFloat($product['unitPrice'] ?? $product['price'] ?? null);
        if ($flatPrice !== null) {
            $price = $flatPrice;
        }
    }

    if ($price === null) {
        return null;
    }

    $stock = $product['stock'] ?? null;
    if (is_array($stock)) {
        $inventory = (int) ($stock['level'] ?? $stock['amount'] ?? 0);
    } else {
        $inventory = is_numeric($stock) ? (int) $stock : 0;
    }

    return [
        'price'         => $price,
        'currency'      => $currency,
        'quantity'      => $quantity,
        'supplier_name' => farnellSupplierName(),
        'supplier_ref'  => farnellPick($product, ['sku', 'vendorPartNumber', 'productCode']),
        'inventory'     => $inventory,
        'click_url'     => farnellPick($product, ['productUrl', 'url', 'link']),
        'tax'           => 'excl',
    ];
}

/**
 * Normalise un produit Farnell au même format que les résultats des autres
 * sources, afin que le formulaire de création soit commun à toutes les sources.
 */
function farnellNormalizePart(array $product): array {
    $mpn     = farnellPick($product, ['translatedManufacturerPartNumber', 'manufacturerPartNumber', 'mpn']);
    $sku     = farnellPick($product, ['sku', 'vendorPartNumber', 'productCode']);
    $label   = $mpn !== '' ? $mpn : $sku;
    $package = farnellPick($product, ['translatedPackage', 'package', 'packageName', 'case']);

    $manufacturer = farnellPick($product, ['translatedBrandName', 'brandName', 'manufacturer', 'vendorName']);

    $description = farnellPick($product, ['translatedDescription', 'description', 'displayName', 'name']);

    $datasheet = '';
    if (isset($product['datasheets']) && is_array($product['datasheets'])) {
        foreach ($product['datasheets'] as $sheet) {
            if (is_array($sheet)) {
                $datasheet = farnellPick($sheet, ['url', 'datasheetUrl']);
            } elseif (is_string($sheet)) {
                $datasheet = trim($sheet);
            }
            if ($datasheet !== '') {
                break;
            }
        }
    }
    if ($datasheet === '') {
        $datasheet = farnellPick($product, ['datasheetUrl', 'datasheet']);
    }

    $image = '';
    if (isset($product['image']) && is_string($product['image'])) {
        $image = trim($product['image']);
    } elseif (is_array($product['image'] ?? null)) {
        $image = farnellPick((array) $product['image'], ['baseName', 'url', 'imageUrl', 'src']);
    }
    if ($image === '') {
        $image = farnellPick($product, ['imageUrl', 'thumbnail']);
    }

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => $description,
        'manufacturer' => $manufacturer,
        'category'     => farnellPick($product, ['category', 'categoryName', 'productType']),
        'package'      => $package,
        'pins'         => null,
        'smd'          => farnellDetectSmd($package),
        'datasheet'    => $datasheet,
        'image_url'    => $image,
        'spec_text'    => farnellBuildSpecText($product),
        'best_offer'   => farnellFindBestOffer($product),
        'skus'         => $sku !== '' ? [$sku] : [],
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis référence Farnell
 * exacte, puis correspondances partielles.
 */
function farnellRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = farnellNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (farnellNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez Farnell / element14 et renvoie une liste
 * normalisée.
 *
 * $limit : null = utilise le réglage FARNELL_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function farnellSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if (!farnellIsConfigured()) {
        return ['ok' => false, 'message' => 'API Farnell non configurée : renseignez la clé API dans Paramètres → Réglage API.'];
    }

    if ($limit === null) {
        $limit = (int) FARNELL_SEARCH_LIMIT;
    }
    $limit = max(1, min(50, $limit));

    $result = farnellRequest($query, $limit);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message'] ?? 'Erreur lors de la recherche Farnell.'];
    }

    $parts = [];
    foreach ($result['products'] as $entry) {
        // L'API Farnell encapsule chaque produit dans un objet « product ».
        $raw = (is_array($entry) && isset($entry['product']) && is_array($entry['product']))
            ? $entry['product']
            : $entry;
        if (is_array($raw)) {
            $parts[] = farnellNormalizePart($raw);
        }
    }

    // Tri par pertinence (tri PHP stable : l'ordre Farnell est conservé à score égal).
    $queryNormalized = farnellNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return farnellRelevanceScore($a, $queryNormalized) <=> farnellRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => $result['number'] > 0 ? $result['number'] : count($parts),
        'results' => array_slice($parts, 0, $limit),
    ];
}
