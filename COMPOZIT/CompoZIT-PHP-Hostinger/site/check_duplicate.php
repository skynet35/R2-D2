<?php
/* =========================================================================
   Endpoint AJAX — détection de doublon avant création d'un composant
   ---------------------------------------------------------------------------
   Recherche, pour l'utilisateur connecté, les fiches portant déjà la même
   référence (nom/MPN). Sert au formulaire de création pour prévenir avant
   de créer un second exemplaire du même composant.

   Entrée : ?name=<MPN>&manufacturer=<fabricant>  (fabricant facultatif)
   Sortie : { "ok": true, "duplicates": [ { id, name, manufacturer,
              quantity, package, location, same_manufacturer } ] }
            { "ok": false, "message": "…" }
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

// Réponse JSON puis arrêt du script.
function duplicateJsonResponse(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    duplicateJsonResponse(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
}

$name         = trim((string) ($_GET['name'] ?? ''));
$manufacturer = trim((string) ($_GET['manufacturer'] ?? ''));

if ($name === '') {
    duplicateJsonResponse(['ok' => false, 'message' => 'Référence manquante.']);
}

try {
    $pdo = getConnection();
} catch (PDOException $e) {
    duplicateJsonResponse(['ok' => false, 'message' => 'Erreur de connexion à la base de données.']);
}

// La comparaison porte sur le nom/MPN : c'est le seul champ toujours renseigné.
// Le fabricant est restitué pour que l'utilisateur juge s'il s'agit du même
// composant (les libellés fabricants ne sont pas normalisés en base).
$sql = "SELECT d.id, d.name, d.manufacturer, d.quantity, d.package,
               l.casier, l.tiroir, l.compartiment
        FROM data d
        LEFT JOIN location l ON d.location_id = l.id
        WHERE d.owner = ? AND LOWER(TRIM(d.name)) = LOWER(?)
        ORDER BY d.id DESC
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id'], $name]);

$duplicates = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rowManufacturer = trim((string) ($row['manufacturer'] ?? ''));

    $parts = [];
    foreach (['casier', 'tiroir', 'compartiment'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $duplicates[] = [
        'id'                => (int) $row['id'],
        'name'              => (string) $row['name'],
        'manufacturer'      => $rowManufacturer,
        'quantity'          => (int) $row['quantity'],
        'package'           => (string) ($row['package'] ?? ''),
        'location'          => implode('/', $parts),
        'same_manufacturer' => $manufacturer !== '' && $rowManufacturer !== ''
            && mb_strtolower($rowManufacturer, 'UTF-8') === mb_strtolower($manufacturer, 'UTF-8'),
    ];
}

duplicateJsonResponse(['ok' => true, 'duplicates' => $duplicates]);
