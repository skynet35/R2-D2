<?php
require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}

// Vérifier que les données requises sont présentes
if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
    echo json_encode(['success' => false, 'message' => 'Le nom du projet est requis']);
    exit;
}

$name = trim($_POST['name']);
$description = trim($_POST['description'] ?? '');
$owner = $_SESSION['user_id'];

// Validation du nom du projet
if (strlen($name) < 2) {
    echo json_encode(['success' => false, 'message' => 'Le nom du projet doit contenir au moins 2 caractères']);
    exit;
}

if (strlen($name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Le nom du projet ne peut pas dépasser 100 caractères']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Vérifier si un projet avec ce nom existe déjà pour cet utilisateur
    $stmt = $pdo->prepare("SELECT id FROM projects WHERE owner = ? AND name = ?");
    $stmt->execute([$owner, $name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Un projet avec ce nom existe déjà']);
        exit;
    }
    
    // Créer le nouveau projet - essayer d'abord avec updated_at
    try {
        $stmt = $pdo->prepare("
            INSERT INTO projects (owner, name, description, status, created_at, updated_at) 
            VALUES (?, ?, ?, 'En cours', NOW(), NOW())
        ");
        $stmt->execute([$owner, $name, $description]);
    } catch (Exception $e) {
        // Si updated_at n'existe pas, essayer sans
        try {
            $stmt = $pdo->prepare("
                INSERT INTO projects (owner, name, description, status, created_at) 
                VALUES (?, ?, ?, 'En cours', NOW())
            ");
            $stmt->execute([$owner, $name, $description]);
        } catch (Exception $e2) {
            // En dernier recours, minimal
            $stmt = $pdo->prepare("
                INSERT INTO projects (owner, name, description, status) 
                VALUES (?, ?, ?, 'En cours')
            ");
            $stmt->execute([$owner, $name, $description]);
        }
    }
    
    $project_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Projet créé avec succès',
        'project_id' => $project_id,
        'project_name' => $name
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>