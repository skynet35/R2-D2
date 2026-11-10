<?php
// Activer le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_init.php';
require_once 'config.php';

// Définir les en-têtes JSON
header('Content-Type: application/json; charset=utf-8');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé', 'session_user_id' => ($_SESSION['user_id'] ?? 'NOT SET')]);
    exit();
}

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

// Récupérer les données POST
if (!isset($_POST['component_id']) || !isset($_POST['change'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Données manquantes']);
    exit();
}

$component_id = (int)$_POST['component_id'];
$change = (int)$_POST['change'];

try {
    $pdo = getConnection();
    
    // Récupérer le composant actuel (vérifier qu'il appartient à l'utilisateur)
    $sql = "SELECT quantity FROM data WHERE id = ? AND owner = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$component_id, $_SESSION['user_id']]);
    $component = $stmt->fetch();
    
    if (!$component) {
        // Debug: vérifier si le composant existe sans la condition owner
        $debugStmt = $pdo->prepare("SELECT id, name, quantity, owner FROM data WHERE id = ?");
        $debugStmt->execute([$component_id]);
        $debugComponent = $debugStmt->fetch();
        
        $debugInfo = [
            'error' => 'Composant non trouvé ou ne vous appartient pas',
            'component_id' => $component_id,
            'user_id' => $_SESSION['user_id'],
            'component_exists' => $debugComponent ? 'YES' : 'NO',
            'component_owner' => $debugComponent['owner'] ?? 'NOT FOUND',
            'sql_query' => $sql
        ];
        
        http_response_code(404);
        echo json_encode($debugInfo);
        exit();
    }
    
    $current_quantity = (int)$component['quantity'];
    
    // Calculer la nouvelle quantité
    $new_quantity = max(0, $current_quantity + $change); // Ne pas descendre en dessous de 0
    
    // Mettre à jour la quantité
    $stmt = $pdo->prepare("UPDATE data SET quantity = ? WHERE id = ? AND owner = ?");
    $result = $stmt->execute([$new_quantity, $component_id, $_SESSION['user_id']]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'new_quantity' => $new_quantity
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur lors de la mise à jour']);
    }
    
} catch(PDOException $e) {
    error_log("Erreur lors de la mise à jour de la quantité : " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de base de données']);
}
?>