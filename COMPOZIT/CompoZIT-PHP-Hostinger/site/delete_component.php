<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Vérifier si l'ID du composant est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: components.php?error=invalid_id');
    exit();
}

$component_id = (int)$_GET['id'];

try {
    $pdo = getConnection();
    
    // Vérifier que le composant existe et appartient à l'utilisateur
    $stmt = $pdo->prepare("SELECT name, quantity FROM data WHERE id = ? AND owner = ?");
    $stmt->execute([$component_id, $_SESSION['user_id']]);
    $component = $stmt->fetch();
    
    if (!$component) {
        header('Location: components.php?error=component_not_found');
        exit();
    }
    
    // Vérifier que la quantité est à 0
    if ($component['quantity'] > 0) {
        $name = urlencode($component['name']);
        $quantity = $component['quantity'];
        header("Location: components.php?error=quantity_not_zero&name=$name&quantity=$quantity");
        exit();
    }
    
    // Supprimer le composant
    $stmt = $pdo->prepare("DELETE FROM data WHERE id = ? AND owner = ?");
    $result = $stmt->execute([$component_id, $_SESSION['user_id']]);
    
    if ($result) {
        header('Location: components.php?success=component_deleted&name=' . urlencode($component['name']));
    } else {
        header('Location: components.php?error=delete_failed');
    }
    
} catch(PDOException $e) {
    error_log("Erreur lors de la suppression du composant : " . $e->getMessage());
    header('Location: components.php?error=database_error');
}

exit();
?>