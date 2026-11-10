<?php
// Activer l'affichage des erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'auth.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Récupérer l'ID du projet
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($project_id <= 0) {
    die('ID de projet invalide');
}

// Récupérer les informations du projet
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$project_id, $_SESSION['user_id']]);
$project = $stmt->fetch();

if (!$project) {
    die('Projet non trouvé');
}

// Récupérer les composants du projet
$stmt = $pdo->prepare("
    SELECT pc.quantity_needed, c.name, c.manufacturer, c.package, c.description, c.quantity as stock
    FROM project_components pc 
    JOIN components c ON pc.component_id = c.id 
    WHERE pc.project_id = ?
    ORDER BY c.name
");
$stmt->execute([$project_id]);
$components = $stmt->fetchAll();

// Récupérer les travaux et matériaux
$stmt = $pdo->prepare("
    SELECT item_name, description, quantity, unit_price, total_price 
    FROM project_items 
    WHERE project_id = ?
    ORDER BY item_name
");
$stmt->execute([$project_id]);
$items = $stmt->fetchAll();

// Récupérer les fichiers du projet
$stmt = $pdo->prepare("
    SELECT display_name, file_size, uploaded_at 
    FROM project_files 
    WHERE project_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$project_id]);
$files = $stmt->fetchAll();

// Fonction pour formater la taille des fichiers
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// Générer le HTML pour le PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fiche Projet - ' . htmlspecialchars($project['name']) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .project-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .project-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .total-row {
            background-color: #e8f5e8;
            font-weight: bold;
        }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-en-cours {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status-termine {
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="project-title">Fiche de Projet</div>
        <div style="font-size: 20px; color: #666;">' . htmlspecialchars($project['name']) . '</div>
    </div>
    
    <div class="project-info">
        <strong>Statut:</strong> <span class="status status-' . strtolower(str_replace(' ', '-', $project['status'])) . '">' . htmlspecialchars($project['status']) . '</span><br>
        <strong>Créé le:</strong> ' . date('d/m/Y à H:i', strtotime($project['created_at'])) . '<br>';
        
if ($project['description']) {
    $html .= '<strong>Description:</strong> ' . htmlspecialchars($project['description']) . '<br>';
}

$html .= '
    </div>';

// Section Composants
if (!empty($components)) {
    $html .= '
    <div class="section">
        <div class="section-title">Composants du Projet (' . count($components) . ')</div>
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Fabricant</th>
                    <th>Boîtier</th>
                    <th>Quantité</th>
                    <th>Stock</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($components as $component) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($component['name']) . '</td>
                    <td>' . htmlspecialchars($component['manufacturer'] ?: 'N/A') . '</td>
                    <td>' . htmlspecialchars($component['package'] ?: 'N/A') . '</td>
                    <td>' . $component['quantity_needed'] . '</td>
                    <td>' . $component['stock'] . '</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </div>';
}

// Section Travaux et Matériaux
if (!empty($items)) {
    $html .= '
    <div class="section">
        <div class="section-title">Travaux et Matériaux (' . count($items) . ')</div>
        <table>
            <thead>
                <tr>
                    <th>Article</th>
                    <th>Quantité</th>
                    <th>Prix Unitaire</th>
                    <th>Total</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>';
    
    $total_cost = 0;
    foreach ($items as $item) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['item_name']) . '</td>
                    <td>' . $item['quantity'] . '</td>
                    <td>' . number_format($item['unit_price'], 2) . ' €</td>
                    <td>' . number_format($item['total_price'], 2) . ' €</td>
                    <td>' . htmlspecialchars($item['description'] ?: '') . '</td>
                </tr>';
        $total_cost += $item['total_price'];
    }
    
    $html .= '
                <tr class="total-row">
                    <td colspan="3">TOTAL</td>
                    <td>' . number_format($total_cost, 2) . ' €</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>';
}

// Section Fichiers
if (!empty($files)) {
    $html .= '
    <div class="section">
        <div class="section-title">Documents et Fichiers (' . count($files) . ')</div>
        <table>
            <thead>
                <tr>
                    <th>Nom du fichier</th>
                    <th>Taille</th>
                    <th>Date d\'ajout</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($files as $file) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($file['display_name']) . '</td>
                    <td>' . formatFileSize($file['file_size']) . '</td>
                    <td>' . date('d/m/Y H:i', strtotime($file['uploaded_at'])) . '</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
    </div>';
}

$html .= '
    <div style="margin-top: 50px; text-align: center; font-size: 12px; color: #666;">
        Généré le ' . date('d/m/Y à H:i') . ' - Gestionnaire de Composants
    </div>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid #ddd; background-color: #f8f9fa; color: #666; font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>
</body>
</html>';

// Utiliser wkhtmltopdf si disponible, sinon afficher le HTML
if (isset($_GET['preview'])) {
    // Mode prévisualisation HTML
    echo $html;
} else {
    // Essayer de générer un PDF avec wkhtmltopdf
    $filename = 'Projet_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $project['name']) . '_' . date('Y-m-d') . '.pdf';
    
    // Créer un fichier HTML temporaire
    $temp_html = tempnam(sys_get_temp_dir(), 'project_') . '.html';
    file_put_contents($temp_html, $html);
    
    // Essayer wkhtmltopdf
    $wkhtmltopdf = 'wkhtmltopdf';
    $temp_pdf = tempnam(sys_get_temp_dir(), 'project_') . '.pdf';
    
    $command = "\"$wkhtmltopdf\" --page-size A4 --margin-top 0.75in --margin-right 0.75in --margin-bottom 0.75in --margin-left 0.75in \"$temp_html\" \"$temp_pdf\" 2>&1";
    
    exec($command, $output, $return_code);
    
    if ($return_code === 0 && file_exists($temp_pdf)) {
        // PDF généré avec succès
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($temp_pdf));
        readfile($temp_pdf);
        unlink($temp_pdf);
    } else {
        // Fallback: afficher le HTML avec option d'impression
        echo '<script>window.print();</script>';
        echo $html;
    }
    
    unlink($temp_html);
}
?>