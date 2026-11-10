<?php
require_once 'session_init.php';
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé', 'session_user_id' => ($_SESSION['user_id'] ?? 'NOT SET')]);
    exit();
}

// Vérifier que c'est une requête GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

// Ignorer le paramètre subcategory_id - on affiche toutes les images du dossier img/
$images = [];

// Scanner le dossier img/ pour toutes les images disponibles
$img_dir = 'img/';
if (is_dir($img_dir)) {
    $files = scandir($img_dir);
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $file_path = $img_dir . $file;
            $file_extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            // Vérifier si c'est un fichier image
            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                $images[] = [
                    'image_path' => $file_path,
                    'name' => pathinfo($file, PATHINFO_FILENAME)
                ];
            }
        }
    }
}

// Retourner la liste des images en JSON
header('Content-Type: application/json');
echo json_encode($images);
?>