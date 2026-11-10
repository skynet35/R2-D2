<?php
/* =========================================================================
   Client API Nexar / Octopart (scope "supply")
   ---------------------------------------------------------------------------
   Fournit :
     - nexarIsConfigured()      : les identifiants sont-ils renseignés ?
     - nexarGetAccessToken()    : jeton OAuth2 (mis en cache 24 h dans tmp/)
     - nexarGraphQL()           : appel GraphQL authentifié
     - nexarSearchParts()       : recherche de composants (MPN ou référence distributeur),
                                  données normalisées et triées par pertinence

   Les identifiants NEXAR_CLIENT_ID / NEXAR_CLIENT_SECRET se définissent dans
   config.local.php (fichier ignoré par Git).
   ========================================================================= */

require_once __DIR__ . '/config.php';

const NEXAR_TOKEN_URL   = 'https://identity.nexar.com/connect/token';
const NEXAR_GRAPHQL_URL = 'https://api.nexar.com/graphql';
const NEXAR_SCOPE       = 'supply.domain';

/**
 * Les identifiants Nexar sont-ils renseignés ?
 */
function nexarIsConfigured(): bool {
    return NEXAR_CLIENT_ID !== '' && NEXAR_CLIENT_SECRET !== '';
}

/**
 * Requête HTTP générique (curl).
 *
 * @return array{code:int, body:string, error:string}
 */
function nexarHttpRequest(string $url, array $headers, ?string $body = null, int $timeout = 20): array {
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

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
function nexarTokenCachePath(): string {
    return __DIR__ . '/tmp/nexar_token.json';
}

/**
 * Récupère un jeton d'accès OAuth2 (client_credentials), avec cache local.
 * Le jeton Nexar est valable 24 h ; on le renouvelle 60 s avant expiration.
 */
function nexarGetAccessToken(bool $forceRefresh = false): ?string {
    $cachePath = nexarTokenCachePath();

    if (!$forceRefresh && is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)
            && !empty($cached['access_token'])
            && (int) ($cached['expires_at'] ?? 0) > time() + 60
        ) {
            return (string) $cached['access_token'];
        }
    }

    $response = nexarHttpRequest(NEXAR_TOKEN_URL, [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => NEXAR_CLIENT_ID,
        'client_secret' => NEXAR_CLIENT_SECRET,
        'scope'         => NEXAR_SCOPE,
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
        'access_token' => $payload['access_token'],
        'expires_at'   => time() + (int) ($payload['expires_in'] ?? 86400),
    ]));

    return (string) $payload['access_token'];
}

/**
 * Exécute une requête GraphQL authentifiée.
 * En cas de 401 (jeton expiré), un seul nouvel essai est tenté avec un jeton neuf.
 *
 * @return array{ok:bool, data?:array, error?:string, message?:string}
 */
function nexarGraphQL(string $query, array $variables = [], bool $retryOnUnauthorized = true): array {
    if (!nexarIsConfigured()) {
        return ['ok' => false, 'error' => 'not_configured', 'message' => 'Identifiants Nexar non configurés.'];
    }

    $token = nexarGetAccessToken();
    if ($token === null) {
        return ['ok' => false, 'error' => 'auth_failed', 'message' => "Impossible d'obtenir un jeton Nexar. Vérifiez le Client ID / Client Secret et le scope de l'application."];
    }

    $response = nexarHttpRequest(NEXAR_GRAPHQL_URL, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ], json_encode(['query' => $query, 'variables' => $variables]));

    if ($response['code'] === 401 && $retryOnUnauthorized) {
        $token = nexarGetAccessToken(true);
        if ($token !== null) {
            $response = nexarHttpRequest(NEXAR_GRAPHQL_URL, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ], json_encode(['query' => $query, 'variables' => $variables]));
        }
    }

    if ($response['body'] === '') {
        return ['ok' => false, 'error' => 'network_error', 'message' => $response['error'] !== '' ? $response['error'] : 'Aucune réponse du serveur Nexar.'];
    }

    $payload = json_decode($response['body'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'bad_response', 'message' => 'Réponse Nexar illisible.'];
    }

    if (!empty($payload['errors'])) {
        $firstError = $payload['errors'][0]['message'] ?? 'Erreur GraphQL inconnue.';
        return ['ok' => false, 'error' => 'graphql_error', 'message' => (string) $firstError];
    }

    if ($response['code'] !== 200) {
        return ['ok' => false, 'error' => 'http_' . $response['code'], 'message' => 'Le service Nexar a répondu avec le code HTTP ' . $response['code'] . '.'];
    }

    return ['ok' => true, 'data' => $payload['data'] ?? []];
}

/**
 * Requête de recherche : recherche générale Octopart (MPN, description et
 * références distributeurs), avec identité, specs, datasheet, image et offres.
 *
 * supSearch (et non supSearchMpn) est indispensable pour retrouver un composant
 * à partir d'une référence distributeur (ex. référence RS « 489-0043 »).
 */
function nexarSearchQuery(): string {
    return <<<'GRAPHQL'
query Search($q: String!, $limit: Int!, $country: String!, $currency: String!) {
  supSearch(q: $q, limit: $limit, country: $country, currency: $currency) {
    hits
    results {
      part {
        id
        mpn
        name
        shortDescription
        manufacturer { name }
        category { name path }
        bestDatasheet { url }
        bestImage { url }
        specs { attribute { name } value displayValue unitsName unitsSymbol }
        sellers(authorizedOnly: true) {
          company { name }
          offers {
            sku
            inventoryLevel
            clickUrl
            prices { price currency convertedPrice convertedCurrency quantity }
          }
        }
      }
    }
  }
}
GRAPHQL;
}

/**
 * Extrait une valeur de spécification par mots-clés sur le nom de l'attribut.
 */
function nexarFindSpec(array $specs, array $keywords): ?array {
    foreach ($specs as $spec) {
        $attributeName = mb_strtolower((string) ($spec['attribute']['name'] ?? ''), 'UTF-8');
        if ($attributeName === '') {
            continue;
        }
        foreach ($keywords as $keyword) {
            if (strpos($attributeName, mb_strtolower($keyword, 'UTF-8')) !== false) {
                return $spec;
            }
        }
    }
    return null;
}

/**
 * Détermine la meilleure offre (prix le plus bas, au plus petit palier de quantité).
 *
 * Si $searchedRef correspond à la référence d'un distributeur (cas d'une
 * recherche par référence RS / Farnell / Mouser), les offres portant cette
 * référence sont privilégiées : c'est celle que l'utilisateur a demandée.
 */
function nexarFindBestOffer(array $sellers, string $searchedRef = ''): ?array {
    $candidates = [];

    foreach ($sellers as $seller) {
        $sellerName = (string) ($seller['company']['name'] ?? '');

        foreach ((array) ($seller['offers'] ?? []) as $offer) {
            $prices = (array) ($offer['prices'] ?? []);
            if (empty($prices)) {
                continue;
            }

            // Palier correspondant à la plus petite quantité commandable.
            $tier = null;
            foreach ($prices as $price) {
                $quantity = (int) ($price['quantity'] ?? 0);
                if ($tier === null || $quantity < (int) ($tier['quantity'] ?? 0)) {
                    $tier = $price;
                }
            }

            if ($tier === null || $tier['price'] === null) {
                continue;
            }

            // On privilégie le prix converti (EUR) quand il est fourni.
            if (isset($tier['convertedPrice']) && $tier['convertedPrice'] !== null) {
                $priceValue = (float) $tier['convertedPrice'];
                $currencyValue = (string) ($tier['convertedCurrency'] ?? '');
            } else {
                $priceValue = (float) $tier['price'];
                $currencyValue = (string) ($tier['currency'] ?? '');
            }

            $candidates[] = [
                'price'            => $priceValue,
                'currency'         => $currencyValue,
                'quantity'         => (int) ($tier['quantity'] ?? 1),
                'supplier_name'    => $sellerName,
                'supplier_ref'     => (string) ($offer['sku'] ?? ''),
                'inventory'        => (int) ($offer['inventoryLevel'] ?? 0),
                'click_url'        => (string) ($offer['clickUrl'] ?? ''),
                'tax'              => 'excl',
            ];
        }
    }

    $searchedRefNormalized = nexarNormalizeRef($searchedRef);
    if ($searchedRefNormalized !== '') {
        $matching = [];
        foreach ($candidates as $candidate) {
            if (nexarNormalizeRef($candidate['supplier_ref']) === $searchedRefNormalized) {
                $matching[] = $candidate;
            }
        }
        if ($matching) {
            $candidates = $matching;
        }
    }

    $best = null;
    foreach ($candidates as $candidate) {
        if ($best === null || $candidate['price'] < $best['price']) {
            $best = $candidate;
        }
    }

    return $best;
}

/**
 * Normalise une référence pour comparaison (majuscules, sans séparateurs).
 * « 489-0043 » et « 4890043 » deviennent identiques.
 */
function nexarNormalizeRef(string $value): string {
    return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8'));
}

/**
 * Récupère toutes les références distributeurs (SKU) attachées à une part.
 */
function nexarCollectSkus(array $sellers): array {
    $skus = [];

    foreach ($sellers as $seller) {
        foreach ((array) ($seller['offers'] ?? []) as $offer) {
            $sku = trim((string) ($offer['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
    }

    return $skus;
}

/**
 * Score de pertinence d'une part vis-à-vis de la recherche (0 = meilleur).
 *
 * Octopart classe par pertinence globale ; on reproduit ce comportement en
 * remontant d'abord les correspondances strictes, y compris sur les références
 * distributeurs (indispensable pour une référence type « 489-0043 »).
 */
function nexarRelevanceScore(array $part, string $queryNormalized): int {
    if ($queryNormalized === '') {
        return 4;
    }

    $mpnNormalized = nexarNormalizeRef((string) ($part['mpn'] ?? ''));

    if ($mpnNormalized !== '' && $mpnNormalized === $queryNormalized) {
        return 0; // MPN exact
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (nexarNormalizeRef((string) $sku) === $queryNormalized) {
            return 1; // référence distributeur exacte
        }
    }

    if ($mpnNormalized !== ''
        && (strpos($mpnNormalized, $queryNormalized) !== false || strpos($queryNormalized, $mpnNormalized) !== false)
    ) {
        return 2; // MPN partiellement correspondant
    }

    foreach ((array) ($part['skus'] ?? []) as $sku) {
        if (strpos(nexarNormalizeRef((string) $sku), $queryNormalized) !== false) {
            return 3; // référence distributeur partiellement correspondante
        }
    }

    return 4;
}

/**
 * Normalise une part Nexar en structure exploitable par le formulaire.
 *
 * $searchedRef : référence saisie par l'utilisateur, utilisée pour privilégier
 * l'offre correspondante (utile lors d'une recherche par référence distributeur).
 */
function nexarNormalizePart(array $part, string $searchedRef = ''): array {
    $specs = (array) ($part['specs'] ?? []);
    $sellers = (array) ($part['sellers'] ?? []);

    $packageSpec = nexarFindSpec($specs, ['package', 'case', 'boitier', 'encapsulation']);
    $pinsSpec    = nexarFindSpec($specs, ['number of pins', 'pin count', 'pins', 'broches']);
    $mountSpec   = nexarFindSpec($specs, ['mounting type', 'mount']);

    $packageValue = '';
    if ($packageSpec !== null) {
        $packageValue = (string) ($packageSpec['displayValue'] ?? $packageSpec['value'] ?? '');
    }

    $pins = null;
    if ($pinsSpec !== null) {
        $pinsRaw = (string) ($pinsSpec['displayValue'] ?? $pinsSpec['value'] ?? '');
        if (preg_match('/\d+/', $pinsRaw, $matches)) {
            $pins = (int) $matches[0];
        }
    }

    $mounting = '';
    if ($mountSpec !== null) {
        $mounting = (string) ($mountSpec['displayValue'] ?? $mountSpec['value'] ?? '');
    }

    return [
        'mpn'              => (string) ($part['mpn'] ?? ''),
        'name'             => (string) ($part['name'] ?? ''),
        'description'      => (string) ($part['shortDescription'] ?? ''),
        'manufacturer'     => (string) ($part['manufacturer']['name'] ?? ''),
        'category'         => (string) ($part['category']['name'] ?? ''),
        'package'          => $packageValue,
        'pins'             => $pins,
        'smd'              => (stripos($mounting, 'smd') !== false || stripos($mounting, 'surface') !== false) ? 'Yes' : 'No',
        'datasheet'        => (string) ($part['bestDatasheet']['url'] ?? ''),
        'image_url'        => (string) ($part['bestImage']['url'] ?? ''),
        'best_offer'       => nexarFindBestOffer($sellers, $searchedRef),
        'skus'             => nexarCollectSkus($sellers),
    ];
}

/**
 * Recherche des composants (MPN ou référence distributeur) et renvoie une liste
 * normalisée, triée par pertinence (le meilleur résultat en premier).
 *
 * $limit : null = utilise le réglage NEXAR_SEARCH_LIMIT (Paramètres → Réglage API).
 *
 * @return array{ok:bool, hits?:int, results?:array, message?:string, error?:string}
 */
function nexarSearchParts(string $query, ?int $limit = null, string $country = 'FR', string $currency = 'EUR'): array {
    $query = trim($query);
    if ($query === '') {
        return ['ok' => false, 'error' => 'empty_query', 'message' => 'Saisissez une référence (MPN ou référence distributeur).'];
    }

    if ($limit === null) {
        $limit = (int) NEXAR_SEARCH_LIMIT;
    }

    $limit = max(1, min(30, $limit));

    $response = nexarGraphQL(nexarSearchQuery(), [
        'q'        => $query,
        'limit'    => $limit,
        'country'  => $country,
        'currency' => $currency,
    ]);

    if (!$response['ok']) {
        return $response;
    }

    $search = $response['data']['supSearch'] ?? [];
    $results = $search['results'] ?? [];
    $parts = [];

    foreach ($results as $row) {
        $part = $row['part'] ?? null;
        if (is_array($part)) {
            $parts[] = nexarNormalizePart($part, $query);
        }
    }

    // Tri par pertinence : MPN exact, puis référence distributeur exacte, puis
    // correspondances partielles. Le tri PHP est stable, l'ordre Octopart est
    // donc conservé à score égal.
    $queryNormalized = nexarNormalizeRef($query);
    usort($parts, function (array $a, array $b) use ($queryNormalized) {
        return nexarRelevanceScore($a, $queryNormalized) <=> nexarRelevanceScore($b, $queryNormalized);
    });

    return [
        'ok'      => true,
        'hits'    => (int) ($search['hits'] ?? count($parts)),
        'results' => $parts,
    ];
}
