<?php
/* =========================================================================
   Client API DigiKey (Product Search v4 REST + OAuth2)
   ---------------------------------------------------------------------------
   Fournit :
     - digikeyIsConfigured()  : les identifiants sont-ils renseignés ?
     - digikeyGetAccessToken(): jeton OAuth2 (mis en cache, durée expires_in)
     - digikeySearchParts()   : recherche de composants (MPN, référence
                                distributeur ou mot-clé), résultats normalisés
                                au même format que Nexar, Mouser et Farnell.

   Particularités de cette API :
     - authentification OAuth2 « client_credentials » (2-legged) ;
     - l'en-tête X-DIGIKEY-Client-Id est obligatoire sur chaque appel ;
     - la recherche se fait en POST avec un corps JSON ;
     - quotas : 120 requêtes/minute et 1000 requêtes/jour (erreur 429).

   Les identifiants se définissent dans config.local.php :
   DIGIKEY_CLIENT_ID / DIGIKEY_CLIENT_SECRET.

   Documentation : https://developer.digikey.com/products/product-information/productsearch
   ========================================================================= */

require_once __DIR__ . '/config.php';

const DIGIKEY_TOKEN_URL  = 'https://api.digikey.com/v1/oauth2/token';
const DIGIKEY_SEARCH_URL = 'https://api.digikey.com/products/v4/search/keyword';

/**
 * Les identifiants DigiKey sont-ils renseignés ?
 */
function digikeyIsConfigured(): bool {
    return trim((string) DIGIKEY_CLIENT_ID) !== '' && trim((string) DIGIKEY_CLIENT_SECRET) !== '';
}

/**
 * Requête HTTP générique (curl).
 *
 * @return array{code:int, body:string, error:string}
 */
function digikeyHttpRequest(string $url, array $headers, ?string $body = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
 * Chemin du fichier de cache du jeton.
 */
function digikeyTokenCachePath(): string {
    return __DIR__ . '/tmp/digikey_token.json';
}

/**
 * Récupère un jeton d'accès OAuth2 (client_credentials), avec cache local.
 * Le jeton est renouvelé 60 s avant son expiration.
 */
function digikeyGetAccessToken(bool $forceRefresh = false): ?string {
    $cachePath = digikeyTokenCachePath();

    if (!$forceRefresh && is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)
            && !empty($cached['access_token'])
            && (int) ($cached['expires_at'] ?? 0) > time() + 60
        ) {
            return (string) $cached['access_token'];
        }
    }

    $response = digikeyHttpRequest(DIGIKEY_TOKEN_URL, [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => trim((string) DIGIKEY_CLIENT_ID),
        'client_secret' => trim((string) DIGIKEY_CLIENT_SECRET),
    ]));

    if ($response['code'] !== 200) {
        return null;
    }

    $payload = json_decode($response['body'], true);
    if (!is_array($payload) || empty($payload['access_token'])) {
        return null;
    }

    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($cachePath, json_encode([
        'access_token' => (string) $payload['access_token'],
        'expires_at'   => time() + max(60, (int) ($payload['expires_in'] ?? 600)),
    ]));

    return (string) $payload['access_token'];
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function digikeyNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Extrait une description lisible d'un produit DigiKey.
 */
function digikeyDescription(array $product): string {
    $description = (array) ($product['Description'] ?? []);

    $value = trim((string) ($description['DetailedDescription'] ?? ''));
    if ($value === '') {
        $value = trim((string) ($description['ProductDescription'] ?? ''));
    }

    return $value;
}

/**
 * Détermine si le composant est en montage de surface (SMD).
 */
function digikeyDetectSmd(string $package): string {
    $boitier = strtoupper(trim($package));
    if ($boitier === '') {
        return 'No';
    }

    // Familles traversantes courantes : on les exclut d'abord.
    $traversants = ['DIP', 'PDIP', 'DIL', 'TO-92', 'TO-220', 'TO-247', 'TO-3', 'TO-18', 'TO-39', 'TO-126', 'THROUGH'];
    foreach ($traversants as $needle) {
        if (strpos($boitier, $needle) !== false) {
            return 'No';
        }
    }

    // Familles CMS.
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
 * Recherche une valeur de paramètre produit par libellé (insensible à la casse).
 */
function digikeyFindParameter(array $product, array $labels): ?string {
    foreach ((array) ($product['Parameters'] ?? []) as $parameter) {
        $label = mb_strtolower(trim((string) ($parameter['ParameterText'] ?? '')), 'UTF-8');
        if ($label === '') {
            continue;
        }

        foreach ($labels as $candidate) {
            if (mb_strpos($label, mb_strtolower($candidate, 'UTF-8')) !== false) {
                $value = trim((string) ($parameter['ValueText'] ?? ''));
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
function digikeyImageUrl(array $product): string {
    return trim((string) ($product['PhotoUrl'] ?? ''));
}

/**
 * Détermine la meilleure offre : premier palier de prix de la première variation.
 */
function digikeyFindBestOffer(array $product): ?array {
    $variation = (array) (($product['ProductVariations'] ?? [])[0] ?? []);

    $price = null;
    $quantity = 1;
    foreach ((array) ($variation['StandardPricing'] ?? []) as $tier) {
        $unitPrice = isset($tier['UnitPrice']) ? (float) $tier['UnitPrice'] : null;
        if ($unitPrice === null) {
            continue;
        }

        $break = max(1, (int) ($tier['BreakQuantity'] ?? 1));
        if ($price === null || $break < $quantity) {
            $price    = $unitPrice;
            $quantity = $break;
        }
    }

    if ($price === null) {
        return null;
    }

    return [
        'price'         => $price,
        'currency'      => 'EUR',
        'quantity'      => $quantity,
        'supplier_name' => 'DigiKey',
        'supplier_ref'  => (string) ($variation['DigiKeyProductNumber'] ?? ''),
        'inventory'     => (int) ($product['QuantityAvailable'] ?? 0),
        'click_url'     => trim((string) ($product['ProductUrl'] ?? '')),
        'tax'           => 'excl',
    ];
}

/**
 * Normalise un produit DigiKey au même format que les autres sources.
 */
function digikeyNormalizePart(array $product): array {
    $variation = (array) (($product['ProductVariations'] ?? [])[0] ?? []);

    $mpn = trim((string) ($product['ManufacturerProductNumber'] ?? ''));
    $sku = trim((string) ($variation['DigiKeyProductNumber'] ?? ''));
    $label = $mpn !== '' ? $mpn : $sku;

    $description = digikeyDescription($product);

    $packageRaw = (string) (digikeyFindParameter($product, ['package / case', 'case', 'boîtier', 'package', 'mounting type']) ?? '');
    if ($packageRaw === '') {
        $packageRaw = (string) ($variation['PackageType']['Name'] ?? '');
    }

    $pinsRaw = (string) (digikeyFindParameter($product, ['number of pins', 'pin count', 'pins', 'broches']) ?? '');
    $pins = null;
    if ($pinsRaw !== '' && preg_match('/\d+/', $pinsRaw, $matches)) {
        $pins = (int) $matches[0];
    }

    $skus = [];
    foreach ((array) ($product['ProductVariations'] ?? []) as $variant) {
        $ref = trim((string) ($variant['DigiKeyProductNumber'] ?? ''));
        if ($ref !== '' && !in_array($ref, $skus, true)) {
            $skus[] = $ref;
        }
    }

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => $description,
        'manufacturer' => (string) (($product['Manufacturer']['Name'] ?? '')),
        'category'     => (string) (($product['Category']['Name'] ?? '')),
        'package'      => $packageRaw,
        'pins'         => $pins,
        'smd'          => digikeyDetectSmd($packageRaw),
        'datasheet'    => trim((string) ($product['DatasheetUrl'] ?? '')),
        'image_url'    => digikeyImageUrl($product),
        'best_offer'   => digikeyFindBestOffer($product),
        'skus'         => $skus,
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis référence DigiKey exacte,
 * puis correspondances partielles.
 */
function digikeyRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = digikeyNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (digikeyNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez DigiKey et renvoie une liste normalisée.
 *
 * $limit : null = utilise le réglage DIGIKEY_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function digikeySearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if (!digikeyIsConfigured()) {
        return ['ok' => false, 'message' => 'API DigiKey non configurée : renseignez Client ID et Client Secret dans Paramètres → Réglage API.'];
    }

    if ($limit === null) {
        $limit = (int) DIGIKEY_SEARCH_LIMIT;
    }
    $limit = max(1, min(50, $limit));

    $token = digikeyGetAccessToken();
    if ($token === null) {
        return ['ok' => false, 'message' => 'Impossible d\'obtenir un jeton DigiKey : vérifiez le Client ID et le Client Secret.'];
    }

    $body = json_encode([
        'Keywords' => $query,
        'Limit'    => $limit,
        'Offset'   => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $response = digikeyHttpRequest(DIGIKEY_SEARCH_URL, [
        'Authorization: Bearer ' . $token,
        'X-DIGIKEY-Client-Id: ' . trim((string) DIGIKEY_CLIENT_ID),
        'X-DIGIKEY-Locale-Site: ' . DIGIKEY_LOCALE_SITE,
        'X-DIGIKEY-Locale-Language: ' . DIGIKEY_LOCALE_LANGUAGE,
        'X-DIGIKEY-Locale-Currency: ' . DIGIKEY_LOCALE_CURRENCY,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);

    if ($response['error'] !== '') {
        return ['ok' => false, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] === 401 || $response['code'] === 403) {
        return ['ok' => false, 'message' => 'Accès refusé par DigiKey (HTTP ' . $response['code'] . ') : identifiants invalides ou droits insuffisants.'];
    }

    if ($response['code'] === 429) {
        return ['ok' => false, 'message' => 'Quota DigiKey atteint (120 requêtes/minute, 1000/jour). Réessayez dans quelques instants.'];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $detail = '';
        $error  = json_decode($response['body'], true);
        if (is_array($error)) {
            $detail = (string) ($error['title'] ?? $error['detail'] ?? '');
        }
        return ['ok' => false, 'message' => 'Réponse DigiKey inattendue (HTTP ' . $response['code'] . ')' . ($detail !== '' ? ' : ' . $detail : '.')];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Réponse DigiKey illisible.'];
    }

    $parts = [];
    foreach ((array) ($data['Products'] ?? []) as $rawProduct) {
        if (is_array($rawProduct)) {
            $parts[] = digikeyNormalizePart($rawProduct);
        }
    }

    // Tri par pertinence (tri stable : l'ordre DigiKey est conservé à score égal).
    $queryNormalized = digikeyNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return digikeyRelevanceScore($a, $queryNormalized) <=> digikeyRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => (int) ($data['ProductsCount'] ?? count($parts)),
        'results' => array_slice($parts, 0, $limit),
    ];
}
