<?php
/* =========================================================================
   Client API TME (Transfer Multisort Elektronik) — API v2 REST + OAuth2
   ---------------------------------------------------------------------------
   Fournit :
     - tmeIsConfigured()      : les identifiants sont-ils renseignés ?
     - tmeGetAccessToken()    : jeton OAuth2 (mis en cache, durée expires_in)
     - tmeSearchParts()       : recherche de composants (MPN ou mot-clé),
                                résultats normalisés au même format que les
                                autres sources.

   Particularités de cette API :
     - authentification OAuth2 « client_credentials » avec HTTP Basic Auth :
       le « token » (50 caractères) sert d'identifiant, le « secret »
       (20 caractères) de mot de passe ;
     - la recherche se fait en GET, les prix et le stock par un second appel
       (/products/data) ;
     - le jeton v2 ne dure que 5 minutes : il est systématiquement mis en cache.

   Les identifiants se définissent dans config.local.php :
   TME_TOKEN / TME_SECRET.

   Documentation : https://developers.tme.eu/en/api-doc/v2
   ========================================================================= */

require_once __DIR__ . '/config.php';

const TME_API_BASE   = 'https://api.tme.eu';
const TME_TOKEN_PATH = '/auth/token';

/**
 * Les identifiants TME sont-ils renseignés ?
 */
function tmeIsConfigured(): bool {
    return trim((string) TME_TOKEN) !== '' && trim((string) TME_SECRET) !== '';
}

/**
 * Requête HTTP générique (curl), avec Basic Auth facultative.
 *
 * @return array{code:int, body:string, error:string}
 */
function tmeHttpRequest(string $url, array $headers = [], ?string $userpwd = null, ?string $body = null, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($userpwd !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

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
function tmeTokenCachePath(): string {
    return __DIR__ . '/tmp/tme_token.json';
}

/**
 * Récupère un jeton d'accès OAuth2 (client_credentials + Basic Auth), avec cache.
 * Le jeton TME v2 ne dure que 5 minutes ; on le renouvelle 30 s avant expiration.
 */
function tmeGetAccessToken(bool $forceRefresh = false): ?string {
    $cachePath = tmeTokenCachePath();

    if (!$forceRefresh && is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)
            && !empty($cached['access_token'])
            && (int) ($cached['expires_at'] ?? 0) > time() + 30
        ) {
            return (string) $cached['access_token'];
        }
    }

    $response = tmeHttpRequest(
        TME_API_BASE . TME_TOKEN_PATH,
        ['Content-Type: application/x-www-form-urlencoded'],
        trim((string) TME_TOKEN) . ':' . trim((string) TME_SECRET),
        'grant_type=client_credentials'
    );

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
        'expires_at'   => time() + max(60, (int) ($payload['expires_in'] ?? 300)),
    ]));

    return (string) $payload['access_token'];
}

/**
 * Exécute un appel GET authentifié vers l'API TME.
 *
 * @param  array<string, string|array> $params paramètres de requête
 * @return array{ok:bool, data?:array, message?:string}
 */
function tmeApiGet(string $path, array $params, string $token): array {
    $query = [];
    foreach ($params as $name => $value) {
        // Les paramètres répétables (mpns[], symbols[], scope[]) attendent la
        // notation tableau : « nom[]=valeur ». Sans crochets, TME répond 400.
        if (is_array($value)) {
            $encodedName = urlencode($name . '[]');
            foreach ($value as $item) {
                $query[] = $encodedName . '=' . urlencode((string) $item);
            }
        } else {
            $query[] = urlencode($name) . '=' . urlencode((string) $value);
        }
    }

    $url = TME_API_BASE . $path . ($query ? '?' . implode('&', $query) : '');

    $response = tmeHttpRequest($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    if ($response['error'] !== '') {
        return ['ok' => false, 'message' => 'Erreur réseau : ' . $response['error']];
    }

    if ($response['code'] === 401 || $response['code'] === 403) {
        return ['ok' => false, 'message' => 'Accès refusé par TME (HTTP ' . $response['code'] . ') : identifiants invalides ou droits insuffisants.'];
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        return ['ok' => false, 'message' => 'Réponse TME inattendue (HTTP ' . $response['code'] . ').'];
    }

    $payload = json_decode($response['body'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'Réponse TME illisible.'];
    }

    if (strtoupper((string) ($payload['status'] ?? 'OK')) !== 'OK') {
        $message = (string) ($payload['message'] ?? '');
        return ['ok' => false, 'message' => 'TME : ' . ($message !== '' ? $message : 'requête refusée.')];
    }

    return ['ok' => true, 'data' => (array) ($payload['data'] ?? [])];
}

/**
 * Extrait la liste de produits d'une réponse TME, quelle que soit l'enveloppe
 * (data.products.elements pour /products, data.elements pour /products/search).
 */
function tmeExtractElements(array $data): array {
    if (isset($data['products']['elements']) && is_array($data['products']['elements'])) {
        return $data['products']['elements'];
    }

    if (isset($data['elements']) && is_array($data['elements'])) {
        return $data['elements'];
    }

    return [];
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 */
function tmeNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Extrait le boîtier depuis la description TME.
 * TME ne renvoie pas d'attribut « case » exploitable : le boîtier figure dans
 * la description sous forme de segment, ex. « ... ; Ch: 2; 3÷30VDC; SO8; reel »
 */
function tmeExtractPackage(string $description): string {
    $segments = preg_split('/\s*;\s*/', trim($description));
    if (!is_array($segments)) {
        return '';
    }

    $tokens = 'SO|SOP|SSOP|TSSOP|MSOP|QSOP|QFN|DFN|LQFP|TQFP|QFP|BGA|CSP|LGA|SOT|SOD|SC'
            . '|SMA|SMB|SMC|MELF|DPAK|D2PAK|D3PAK|DIP|PDIP|DIL|TO|DO';
    $tokensRe = '/(?:' . $tokens . ')\d{1,3}[A-Z]{0,2}/i';
    $chipsRe  = '/\b(0201|0402|0603|0805|1206|1210|1812|2010|2512)\b/';

    foreach ($segments as $segment) {
        $segment = trim($segment);
        // On ignore les segments vides ou porteurs de valeurs (« Ch: 2 », « Ifsm: 27A »).
        if ($segment === '' || strpos($segment, ':') !== false) {
            continue;
        }
        if (!preg_match('/^[A-Z0-9.,\-\/]{1,24}$/i', $segment)) {
            continue;
        }
        if (preg_match($tokensRe, $segment) || preg_match($chipsRe, $segment)) {
            return $segment;
        }
    }

    return '';
}

/**
 * Détermine si le composant est en montage de surface (SMD).
 * TME signale « THT » (through-hole technology) dans la description pour les
 * composants traversants ; sinon on se base sur la famille de boîtier.
 */
function tmeDetectSmd(string $package, string $description = ''): string {
    if (preg_match('/(?:^|;\s*)THT(?:\s*;|\s|$)/i', $description)) {
        return 'No';
    }

    $boitier = strtoupper(trim($package));
    if ($boitier === '') {
        return 'No';
    }

    $traversants = ['DIP', 'PDIP', 'DIL', 'TO-92', 'TO-220', 'TO-247', 'TO-3', 'TO-18', 'TO-39', 'TO-126', 'THROUGH'];
    foreach ($traversants as $needle) {
        if (strpos($boitier, $needle) !== false) {
            return 'No';
        }
    }

    $cms = ['SOIC', 'SO-', 'SO', 'SOP', 'SSOP', 'TSSOP', 'MSOP', 'QFN', 'DFN', 'LGA', 'SOT', 'SOD',
            'SC-', 'SC7', 'TQFP', 'LQFP', 'QFP', 'BGA', 'CSP', 'MELF', 'DPAK', 'D2PAK',
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
 * Construit l'URL de la vignette produit depuis le bloc « assets ».
 */
function tmeImageUrl(array $product): string {
    $assets = (array) ($product['assets'] ?? []);
    $photo  = (array) ($assets['primary_photo'] ?? []);

    $url = trim((string) ($photo['high_resolution'] ?? ''));
    if ($url === '') {
        $url = trim((string) ($photo['thumbnail'] ?? ''));
    }

    // TME renvoie des URLs relatives au protocole (« //cdn... ») : on force HTTPS.
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    return $url;
}

/**
 * Normalise une URL de document TME (relative au protocole « //... »).
 */
function tmeDocumentUrl(string $url): string {
    $url = trim($url);
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }
    return $url;
}

/**
 * Extrait l'URL de la fiche technique depuis le bloc « documents » de
 * /products/files. TME marque les datasheets avec le type « DTE ».
 * Priorité : DTE au format PDF, puis DTE, puis PDF, puis premier document.
 */
function tmeFindDatasheet(array $element): string {
    $firstDoc = '';
    $firstPdf = '';
    $firstDte = '';
    $dtePdf   = '';

    foreach ((array) ($element['documents']['elements'] ?? []) as $doc) {
        if (!is_array($doc)) {
            continue;
        }

        $url = tmeDocumentUrl((string) ($doc['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $isDte = strtoupper(trim((string) ($doc['type'] ?? ''))) === 'DTE';
        $ext   = strtolower((string) pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: $url), PATHINFO_EXTENSION));
        $isPdf = $ext === 'pdf';

        if ($firstDoc === '') {
            $firstDoc = $url;
        }
        if ($isPdf && $firstPdf === '') {
            $firstPdf = $url;
        }
        if ($isDte && $firstDte === '') {
            $firstDte = $url;
        }
        if ($isDte && $isPdf && $dtePdf === '') {
            $dtePdf = $url;
        }
    }

    if ($dtePdf !== '') {
        return $dtePdf;
    }
    if ($firstDte !== '') {
        return $firstDte;
    }
    return $firstPdf !== '' ? $firstPdf : $firstDoc;
}

/**
 * Devise utilisée pour les prix TME.
 */
function tmeCurrency(): string {
    $currency = strtoupper(trim((string) TME_CURRENCY));
    return $currency !== '' ? $currency : 'EUR';
}

/**
 * Détermine la meilleure offre (premier palier de prix) pour un symbole donné,
 * à partir de la réponse de /products/data.
 *
 * TME renvoie des prix TTC (type « GROSS ») : on les ramène hors taxes pour
 * que toutes les sources expriment leurs prix de la même façon (HT).
 */
function tmeFindBestOffer(array $dataElement, string $supplierRef): ?array {
    $prices = (array) ($dataElement['prices'] ?? []);
    $best   = null;

    // Taux de TVA appliqué par TME (ex. { "type": "VAT", "rate": 20 }).
    $vatRate = (float) ($prices['tax']['rate'] ?? 0);
    $isGross = strtoupper((string) ($prices['type'] ?? '')) === 'GROSS';
    $divisor = ($isGross && $vatRate > 0) ? (1 + $vatRate / 100) : 1.0;

    foreach ((array) ($prices['elements'] ?? []) as $tier) {
        $price = isset($tier['price']) ? (float) $tier['price'] : null;
        if ($price === null) {
            continue;
        }

        $quantity = max(1, (int) ($tier['amount'] ?? 1));
        if ($best === null || $quantity < $best['quantity']) {
            $best = [
                'price'         => round($price / $divisor, 4),
                'currency'      => strtoupper((string) ($prices['currency'] ?? '')) ?: tmeCurrency(),
                'quantity'      => $quantity,
                'supplier_name' => 'TME',
                'supplier_ref'  => $supplierRef,
                'inventory'     => (int) ($dataElement['stock_quantity'] ?? 0),
                'click_url'     => '',
                'tax'           => 'excl',
                'vat_rate'      => ($isGross && $vatRate > 0) ? $vatRate : null,
            ];
        }
    }

    return $best;
}

/**
 * Normalise un produit TME au même format que les autres sources.
 * $dataElement : bloc issu de /products/data (prix, stock) pour le même symbole.
 * $datasheet   : URL de la fiche technique (issue de /products/files).
 */
function tmeNormalizePart(array $product, ?array $dataElement = null, string $datasheet = ''): array {
    $symbol = trim((string) ($product['symbol'] ?? ''));

    $symbols = (array) ($product['manufacturer_symbols'] ?? []);
    $mpn = trim((string) ($symbols[0] ?? ''));
    $label = $mpn !== '' ? $mpn : $symbol;

    $description = trim((string) ($product['description'] ?? ''));

    $package = tmeExtractPackage($description);

    $skus = [];
    if ($symbol !== '') {
        $skus[] = $symbol;
    }
    foreach ((array) ($product['manufacturer_symbols'] ?? []) as $ref) {
        $ref = trim((string) $ref);
        if ($ref !== '' && !in_array($ref, $skus, true)) {
            $skus[] = $ref;
        }
    }

    return [
        'mpn'          => $label,
        'name'         => $label,
        'description'  => $description,
        'manufacturer' => (string) (($product['manufacturer']['name'] ?? '')),
        'category'     => (string) (($product['category']['name'] ?? '')),
        'package'      => $package,
        'pins'         => null,
        'smd'          => tmeDetectSmd($package, $description),
        'datasheet'    => $datasheet,
        'image_url'    => tmeImageUrl($product),
        'best_offer'   => ($dataElement !== null ? tmeFindBestOffer($dataElement, $symbol) : null),
        'skus'         => $skus,
    ];
}

/**
 * Score de pertinence (0 = meilleur) : MPN exact, puis symbole TME exact,
 * puis correspondances partielles.
 */
function tmeRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 3;
    }

    $mpnNormalized = tmeNormalizeRef((string) $part['mpn']);

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0;
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (tmeNormalizeRef((string) $sku) === $queryNormalized) {
            return 1;
        }
    }

    if ($mpnNormalized !== '' && strpos($mpnNormalized, $queryNormalized) !== false) {
        return 2;
    }

    return 3;
}

/**
 * Recherche des composants chez TME et renvoie une liste normalisée.
 *
 * On interroge d'abord la référence fabricant (/products?mpns[]=…), puis on
 * bascule sur la recherche par mot-clé (/products/search?phrase=…) si aucune
 * référence ne correspond. Un second appel récupère prix et stock.
 *
 * $limit : null = utilise le réglage TME_SEARCH_LIMIT.
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string}
 */
function tmeSearchParts(string $query, ?int $limit = null): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'message' => 'Saisissez une référence ou un mot-clé.'];
    }

    if (!tmeIsConfigured()) {
        return ['ok' => false, 'message' => 'API TME non configurée : renseignez le token et le secret dans Paramètres → Réglage API.'];
    }

    if ($limit === null) {
        $limit = (int) TME_SEARCH_LIMIT;
    }
    $limit = max(1, min(100, $limit));

    $token = tmeGetAccessToken();
    if ($token === null) {
        return ['ok' => false, 'message' => 'Impossible d\'obtenir un jeton TME : vérifiez le token et le secret.'];
    }

    // 1) Recherche par référence fabricant.
    $response = tmeApiGet('/products', [
        'mpns'   => [$query],
        'limit'  => $limit,
        'scope'  => ['products', 'parameters'],
    ], $token);

    $elements = $response['ok'] ? tmeExtractElements($response['data']) : [];

    // 2) Repli sur la recherche par mot-clé (phrase : 2 à 40 caractères).
    if (empty($elements) && mb_strlen($query) >= 2) {
        $phrase   = mb_substr($query, 0, 40);
        $response = tmeApiGet('/products/search', [
            'phrase' => $phrase,
            'limit'  => $limit,
            'scope'  => ['products'],
        ], $token);

        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['message'] ?? 'Erreur lors de la recherche TME.'];
        }
        $elements = tmeExtractElements($response['data']);
    }

    if (!$response['ok']) {
        return ['ok' => false, 'message' => $response['message'] ?? 'Erreur lors de la recherche TME.'];
    }

    $elements = array_slice($elements, 0, $limit);

    // 3) Prix et stock pour les symboles trouvés (un seul appel groupé).
    $dataBySymbol = [];
    $symbols = [];
    foreach ($elements as $element) {
        $symbol = trim((string) ($element['symbol'] ?? ''));
        if ($symbol !== '') {
            $symbols[] = $symbol;
        }
    }

    if (!empty($symbols)) {
        $dataResponse = tmeApiGet('/products/data', [
            'symbols'  => $symbols,
            'currency' => tmeCurrency(),
            'country'  => trim((string) TME_COUNTRY) !== '' ? trim((string) TME_COUNTRY) : 'FR',
            'scope'    => ['prices', 'stock'],
        ], $token);

        if ($dataResponse['ok']) {
            foreach ((array) ($dataResponse['data']['elements'] ?? []) as $dataElement) {
                if (!is_array($dataElement)) {
                    continue;
                }
                $symbol = trim((string) ($dataElement['symbol'] ?? ''));
                if ($symbol !== '') {
                    $dataBySymbol[$symbol] = $dataElement;
                }
            }
        }
    }

    // 4) Fiches techniques (/products/files) : TME ne les renvoie pas dans la
    //    recherche, il faut un appel dédié.
    $datasheetBySymbol = [];
    if (!empty($symbols)) {
        $filesResponse = tmeApiGet('/products/files', [
            'symbols' => $symbols,
            'scope'   => ['files'],
        ], $token);

        if ($filesResponse['ok']) {
            foreach ((array) ($filesResponse['data']['elements'] ?? []) as $fileElement) {
                if (!is_array($fileElement)) {
                    continue;
                }
                $symbol = trim((string) ($fileElement['symbol'] ?? ''));
                $url    = tmeFindDatasheet($fileElement);
                if ($symbol !== '' && $url !== '') {
                    $datasheetBySymbol[$symbol] = $url;
                }
            }
        }
    }

    $parts = [];
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        $symbol = trim((string) ($element['symbol'] ?? ''));
        $parts[] = tmeNormalizePart(
            $element,
            $dataBySymbol[$symbol] ?? null,
            $datasheetBySymbol[$symbol] ?? ''
        );
    }

    // Tri par pertinence (tri stable : l'ordre TME est conservé à score égal).
    $queryNormalized = tmeNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return tmeRelevanceScore($a, $queryNormalized) <=> tmeRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => count($parts),
        'results' => array_slice($parts, 0, $limit),
    ];
}
