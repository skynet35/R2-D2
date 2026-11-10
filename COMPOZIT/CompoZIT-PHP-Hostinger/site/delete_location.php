<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$pdo = getConnection();

$location_id = intval($_GET['id'] ?? 0);

if ($location_id <= 0) {
    header('Location: locations.php?error=invalid');
    exit();
}

try {
    // Vérifier que l'emplacement appartient à l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM location WHERE id = ? AND owner = ?");
    $stmt->execute([$location_id, $user_id]);
    $location = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$location) {
        header('Location: locations.php?error=not_found');
        exit();
    }
    
    // Vérifier si l'emplacement est utilisé par des composants
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data WHERE location_id = ?");
    $stmt->execute([$location_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        header('Location: locations.php?error=in_use');
        exit();
    }
    
    // Supprimer l'emplacement
    $stmt = $pdo->prepare("DELETE FROM location WHERE id = ? AND owner = ?");
    $stmt->execute([$location_id, $user_id]);
    
    header('Location: locations.php?success=deleted');
    exit();
    
} catch (PDOException $e) {
    header('Location: locations.php?error=database');
    exit();
}
?>