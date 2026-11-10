<?php
/* =========================================================================
   CompoZ'IT - Recherche par code de marquage CMS (API JSON)
   -------------------------------------------------------------------------
   Les boitiers CMS ne portent pas la reference complete mais un code court
   (2 a 4 caracteres) : ce point d'entree retrouve le composant a partir de
   ce code, en interrogeant la table locale `marking_codes` (import separe).

   Entree  : ?search=65V&limit=100      (limit plafonne a 200)
   Sortie  : { "ok": true, "message": "...", "results": [ {...}, ... ] }

   Le meme point d'entree alimente l'autocompletion du champ « Code de
   marquage » du formulaire de composant.
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

/* Reponse JSON puis arret du script. */
function markingJsonResponse(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    markingJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.', 'results' => []], 401);
}

/* Lignes renvoyees a la page de recherche, et plafond du parametre « limit ». */
define('MARKING_PAGE_RESULTS', 100);
define('MARKING_MAX_RESULTS', 200);

/**
 * Neutralise les jokers LIKE du terme saisi : les codes de marquage
 * contiennent souvent « _ » (joker d'un caractere dans LIKE).
 */
function markingLikeTerm(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/**
 * La table de marquage est installee par un import separe : tant qu'il n'a pas
 * ete fait, la page doit l'expliquer plutot que renvoyer une erreur SQL.
 */
function markingInstalled(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT code FROM marking_codes LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Recherche par code exact, puis par code commencant par la saisie, puis par
 * reference fabricant. Les codes exacts remontent en tete.
 */
function markingFind(PDO $pdo, string $term, int $limit): array
{
    $prefix   = markingLikeTerm($term) . '%';
    $contains = '%' . markingLikeTerm($term) . '%';

    $stmt = $pdo->prepare(
        "SELECT code, part_number, manufacturer, package_name, function_label, description
           FROM marking_codes
          WHERE code = ? OR code LIKE ? ESCAPE '\\\\' OR part_number LIKE ? ESCAPE '\\\\'
          ORDER BY (code = ?) DESC, CHAR_LENGTH(code), code, part_number
          LIMIT " . (int) $limit
    );
    $stmt->execute([$term, $prefix, $contains, $term]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$term = trim((string) ($_GET['search'] ?? ''));

if ($term === '') {
    markingJsonResponse(['ok' => false, 'message' => 'Saisissez un code de marquage.', 'results' => []]);
}

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    markingJsonResponse(['ok' => false, 'message' => 'Base de données indisponible.', 'results' => []]);
}

if (!markingInstalled($pdo)) {
    markingJsonResponse([
        'ok' => false,
        'message' => "La base de marquage n'est pas installée sur cette base de données.",
        'results' => [],
    ]);
}

$limit = (int) ($_GET['limit'] ?? MARKING_PAGE_RESULTS);
if ($limit < 1) {
    $limit = MARKING_PAGE_RESULTS;
} elseif ($limit > MARKING_MAX_RESULTS) {
    $limit = MARKING_MAX_RESULTS;
}

try {
    $rows = markingFind($pdo, $term, $limit);
} catch (PDOException $e) {
    error_log('Erreur de recherche de marquage : ' . $e->getMessage());
    markingJsonResponse(['ok' => false, 'message' => 'Erreur de base de données.', 'results' => []]);
}

/* « name » reprend la reference fabricant : c'est le champ attendu par le
   rendu commun des listes de suggestions. */
$results = [];
foreach ($rows as $row) {
    $results[] = [
        'code'         => $row['code'],
        'name'         => $row['part_number'],
        'part_number'  => $row['part_number'],
        'manufacturer' => $row['manufacturer'],
        'package'      => $row['package_name'],
        'function'     => $row['function_label'],
        'description'  => $row['description'],
    ];
}

$count = count($results);
$message = $count === 0
    ? "Aucune correspondance pour « {$term} »."
    : $count . ' correspondance' . ($count > 1 ? 's' : '') . " pour « {$term} »"
      . ($count === $limit ? ' (liste limitée)' : '') . '.';

markingJsonResponse([
    'ok'      => $count > 0,
    'message' => $message,
    'results' => $results,
]);
