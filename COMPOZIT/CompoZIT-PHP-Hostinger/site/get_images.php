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

// Dossier des images
$img_dir = 'img/';
$images = [];

// Vérifier si le dossier existe
if (is_dir($img_dir)) {
    // Scanner le dossier pour les fichiers image
    $files = scandir($img_dir);
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $file_path = $img_dir . $file;
            $file_extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            // Vérifier si c'est un fichier image
            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                $images[] = [
                    'filename' => $file,
                    'path' => $file_path,
                    'name' => pathinfo($file, PATHINFO_FILENAME)
                ];
            }
        }
    }
}

// Retourner la liste des images en JSON
header('Content-Type: application/json');
echo json_encode(['images' => $images]);
?>