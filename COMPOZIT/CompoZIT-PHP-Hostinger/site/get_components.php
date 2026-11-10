<?php
require_once 'session_init.php';
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

// Vérifier que c'est une requête GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

// En deçà de 2 caractères, la recherche est trop large : on ne renvoie rien.
if (mb_strlen($search) < 2) {
    echo json_encode([]);
    exit();
}

try {
    $pdo = getConnection();

    // Composants possédés par l'utilisateur dont le nom contient la saisie.
    $stmt = $pdo->prepare("
        SELECT id, name, manufacturer, package, quantity
        FROM data
        WHERE owner = ? AND name LIKE ?
        ORDER BY name ASC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id'], '%' . $search . '%']);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    error_log("Erreur lors de la récupération des composants : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de base de données']);
}
?>
