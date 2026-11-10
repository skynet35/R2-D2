<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Récupérer les projets de l'utilisateur
    $stmt = $pdo->prepare("SELECT id, name, description, status FROM projects WHERE owner = ? ORDER BY name ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['projects' => $projects]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>