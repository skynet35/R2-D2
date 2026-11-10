<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_init.php';
require_once 'config.php';
require_once 'package_utils.php';
require_once 'component_params.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: components.php');
    exit();
}

// Fonction de compression et redimensionnement d'image (nécessite l'extension GD)
function compressAndResizeImage($source, $destination, $max_width, $max_height, $quality) {
    if (!extension_loaded('gd') || !function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
        return false;
    }
    $info = getimagesize($source);
    if ($info === false) return false;
    
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    $ratio = min($max_width / $width, $max_height / $height);
    $new_width = intval($width * $ratio);
    $new_height = intval($height * $ratio);
    
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
        default:
            return false;
    }
    
    if (!$image) return false;
    
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    if ($mime === 'image/png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefill($new_image, 0, 0, $transparent);
    }
    
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    $result = imagejpeg($new_image, $destination, $quality);
    
    imagedestroy($image);
    imagedestroy($new_image);
    
    return $result;
}

// Validation des données
$name = trim($_POST['name'] ?? '');
$manufacturer = trim($_POST['manufacturer'] ?? '');
$new_manufacturer = trim($_POST['new_manufacturer'] ?? '');

if ($manufacturer === '__new__' && !empty($new_manufacturer)) {
    $manufacturer = $new_manufacturer;
}
$package = trim($_POST['package'] ?? '');
$pins = !empty($_POST['pins']) ? (int)$_POST['pins'] : null;
$smd = $_POST['smd'] ?? 'No';
$quantity = (int)($_POST['quantity'] ?? 1);
$order_quantity = (int)($_POST['order_quantity'] ?? 0);
$price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
$location_id = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;

$datasheet = sanitizeExternalUrl($_POST['datasheet'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$category = !empty($_POST['category']) ? (int)$_POST['category'] : null;
$public = $_POST['public'] ?? 'No';
$url = sanitizeExternalUrl($_POST['url'] ?? '');
$supplier_id = (!empty($_POST['supplier_id']) && $_POST['supplier_id'] !== '__new__') ? (int)$_POST['supplier_id'] : null;
$new_supplier_name = trim($_POST['new_supplier_name'] ?? '');
$supplier_reference = trim($_POST['supplier_reference'] ?? '');

// Gestion du PDF de datasheet (optionnel) : l'URL reste la référence principale.
$datasheet_file = null;

if (isset($_FILES['datasheet_pdf']) && $_FILES['datasheet_pdf']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = 'datasheets/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['datasheet_pdf']['name'], PATHINFO_EXTENSION));
    $header = @file_get_contents($_FILES['datasheet_pdf']['tmp_name'], false, null, 0, 5);

    // Extension ET signature « %PDF- » : un fichier renommé est refusé.
    if ($file_extension === 'pdf' && $header === '%PDF-') {
        $filename = uniqid('datasheet_') . '.pdf';
        $target_path = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['datasheet_pdf']['tmp_name'], $target_path)) {
            $datasheet_file = $target_path;
        }
    }
}

// Gestion de l'image (upload, URL ou existante)
$image_path = null;
$image_type = $_POST['image_type'] ?? '';

if ($image_type === 'existing' && !empty($_POST['selected_existing_image'])) {
    $image_path = trim($_POST['selected_existing_image']);
} elseif ($image_type === 'upload' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = 'img/';
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $filename = uniqid('component_') . '.jpg';
        $target_path = $upload_dir . $filename;
        
        if (extension_loaded('gd') && function_exists('imagecreatefromjpeg')) {
            if (compressAndResizeImage($_FILES['image']['tmp_name'], $target_path, 800, 600, 85)) {
                $image_path = $target_path;
            }
        } else {
            $filename = uniqid('component_') . '.' . $file_extension;
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                $image_path = $target_path;
            }
        }
    }
} elseif ($image_type === 'url' && !empty($_POST['image_url'])) {
    $image_url = trim($_POST['image_url']);

    if (strpos($image_url, 'img/') === 0 && file_exists($image_url)) {
        // Image déjà présente dans le dossier img/
        $image_path = $image_url;
    } else {
        $remoteUrl = sanitizeExternalUrl($image_url);
        if ($remoteUrl !== '') {
            // Rapatrier l'image distante sous img/<Nom>.<ext> (repli sur l'URL)
            $image_path = saveComponentImage($name, $remoteUrl);
        }
    }
}

// Validation des champs obligatoires
if (empty($name)) {
    header('Location: create_component.php?error=name_required');
    exit();
}

if ($quantity < 0) {
    header('Location: create_component.php?error=invalid_quantity');
    exit();
}

try {
    $pdo = getConnection();
    
    if (!empty($manufacturer) && $manufacturer === $new_manufacturer) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO manufacturers (name, owner) VALUES (?, ?)");
            $stmt->execute([$manufacturer, $_SESSION['user_id']]);
        } catch (PDOException $e) {
        }
    }
    
    // Fournisseur saisi à la volée : on réutilise celui du même nom s'il existe
    // déjà, sinon on le crée, puis on le rattache au composant.
    if ($supplier_id === null && $new_supplier_name !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE name = ? AND owner = ? LIMIT 1");
            $stmt->execute([$new_supplier_name, $_SESSION['user_id']]);
            $existingId = $stmt->fetchColumn();

            if ($existingId !== false) {
                $supplier_id = (int) $existingId;
            } else {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, owner) VALUES (?, ?)");
                $stmt->execute([$new_supplier_name, $_SESSION['user_id']]);
                $supplier_id = (int) $pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de la création du fournisseur : " . $e->getMessage());
            $supplier_id = null;
        }
    }
    
    $sql = "INSERT INTO data (
        owner, name, manufacturer, package, pins, smd, marking, quantity, order_quantity, price,
        location_id, datasheet, datasheet_file, comment,
        category, public, url, image_path, supplier_id, supplier_reference
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $_SESSION['user_id'],
        $name,
        $manufacturer ?: null,
        $package ?: null,
        $pins,
        $smd,
        trim((string) ($_POST['marking'] ?? '')) ?: null,
        $quantity,
        $order_quantity,
        $price,
        $location_id,
        $datasheet ?: null,
        $datasheet_file,
        $comment ?: null,
        $category,
        $public,
        $url ?: null,
        $image_path,
        $supplier_id,
        $supplier_reference ?: null
    ]);
    
    if ($result) {
        $newComponentId = $pdo->lastInsertId();

        compozitSaveComponentParams($pdo, (int) $newComponentId, $category, $_POST);

        // Créer le boîtier s'il n'existe pas encore, et enregistrer son image
        registerComponentPackage($pdo, $package, $pins, $smd, $image_path, (int) $_SESSION['user_id'], $name);

        header('Location: components.php?success=component_added&id=' . $newComponentId);
    } else {
        $errorInfo = $stmt->errorInfo();
        error_log("SQL Error: " . print_r($errorInfo, true));
        header('Location: create_component.php?error=add_failed&name=' . urlencode($name));
    }
    
} catch(PDOException $e) {
    error_log("Erreur lors de l'ajout du composant : " . $e->getMessage());
    header('Location: create_component.php?error=database_error&message=' . urlencode($e->getMessage()));
}

exit();
?>
