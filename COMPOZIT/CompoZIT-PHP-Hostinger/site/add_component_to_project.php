<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

// Vérifier que les données requises sont présentes
if (!isset($_POST['project_id']) || !isset($_POST['component_id']) || !isset($_POST['quantity'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Données manquantes']);
    exit;
}

$project_id = (int)$_POST['project_id'];
$component_id = (int)$_POST['component_id'];
$quantity = (int)$_POST['quantity'];
$notes = $_POST['notes'] ?? '';

if ($quantity < 1) {
    echo json_encode(['success' => false, 'message' => 'La quantité doit être supérieure à 0']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Vérifier que le projet appartient à l'utilisateur
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND owner = ?");
    $stmt->execute([$project_id, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Projet non trouvé ou non autorisé']);
        exit;
    }
    
    // Vérifier que le composant existe
    $stmt = $pdo->prepare("SELECT id FROM data WHERE id = ?");
    $stmt->execute([$component_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Composant non trouvé']);
        exit;
    }
    
    // Vérifier si le composant est déjà dans le projet
    $stmt = $pdo->prepare("SELECT id, quantity_needed FROM project_components WHERE project_id = ? AND component_id = ?");
    $stmt->execute([$project_id, $component_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Mettre à jour la quantité existante
        $new_quantity = $existing['quantity_needed'] + $quantity;
        
        // Essayer d'abord avec updated_at si la colonne existe
        try {
            $stmt = $pdo->prepare("UPDATE project_components SET quantity_needed = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_quantity, $notes, $existing['id']]);
        } catch (Exception $e) {
            // Si updated_at n'existe pas, faire sans
            $stmt = $pdo->prepare("UPDATE project_components SET quantity_needed = ?, notes = ? WHERE id = ?");
            $stmt->execute([$new_quantity, $notes, $existing['id']]);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Quantité mise à jour (nouvelle quantité: ' . $new_quantity . ')'
        ]);
    } else {
        // Ajouter le composant au projet
        $stmt = $pdo->prepare("
            INSERT INTO project_components (project_id, component_id, quantity_needed, quantity_used, notes, added_at) 
            VALUES (?, ?, ?, 0, ?, NOW())
        ");
        $stmt->execute([$project_id, $component_id, $quantity, $notes]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Composant ajouté au projet avec succès'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>