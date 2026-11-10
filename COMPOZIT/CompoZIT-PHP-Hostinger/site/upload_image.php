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
    echo json_encode(['success' => false, 'error' => 'Non autorisé', 'session_user_id' => ($_SESSION['user_id'] ?? 'NOT SET')]);
    exit();
}

// Vérifier que c'est une requête POST avec un fichier
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Aucun fichier fourni']);
    exit();
}

$uploadedFile = $_FILES['image'];

// Vérifier les erreurs d'upload
if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'upload du fichier']);
    exit();
}

// Vérifier le type de fichier
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
if (!in_array($uploadedFile['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Type de fichier non autorisé. Formats acceptés: JPG, PNG, GIF, SVG, WebP']);
    exit();
}

// Vérifier la taille du fichier (max 5MB)
if ($uploadedFile['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Le fichier est trop volumineux (max 5MB)']);
    exit();
}

// Générer un nom de fichier unique
$fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
$fileName = uniqid('img_') . '.' . $fileExtension;
$uploadPath = 'img/' . $fileName;

// Créer le dossier img s'il n'existe pas
if (!is_dir('img')) {
    mkdir('img', 0755, true);
}

// Déplacer le fichier uploadé
if (move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
    echo json_encode([
        'success' => true, 
        'image_path' => $uploadPath,
        'image_name' => $fileName
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Erreur lors de la sauvegarde du fichier']);
}
?>