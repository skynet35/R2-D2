<?php
require_once 'session_init.php';
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé', 'session_user_id' => ($_SESSION['user_id'] ?? 'NOT SET')]);
    exit;
}

// Vérifier si parent_id est fourni
if (!isset($_GET['parent_id']) || empty($_GET['parent_id'])) {
    echo json_encode([]);
    exit;
}

$parent_id = intval($_GET['parent_id']);
$user_id = $_SESSION['user_id'];

try {
    // Connexion à la base de données
    $pdo = getConnection();
    // Récupérer les sous-catégories pour la catégorie parent donnée
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM category_sub 
        WHERE category_head_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$parent_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($subcategories);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de base de données']);
}
?>