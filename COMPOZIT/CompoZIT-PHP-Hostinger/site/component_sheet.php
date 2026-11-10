<?php
require_once 'session_init.php';

// Vérification de l'authentification
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

// Inclusion de la configuration
require_once 'config.php';
require_once 'component_params.php';

// Connexion à la base de données
try {
    $pdo = getConnection();
} catch (PDOException $e) {
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}

// Récupération de l'ID du composant
$component_id = $_GET['id'] ?? null;
if (!$component_id) {
    header('Location: components.php');
    exit();
}

// Récupération des informations du composant
try {
    $stmt = $pdo->prepare('SELECT d.*, ch.name as category_name, cs.name as subcategory_name, l.casier, l.tiroir, l.compartiment, l.storage_type, s.name as supplier_name, d.supplier_reference FROM data d LEFT JOIN category_sub cs ON d.category = cs.id LEFT JOIN category_head ch ON cs.category_head_id = ch.id LEFT JOIN location l ON d.location_id = l.id LEFT JOIN suppliers s ON d.supplier_id = s.id WHERE d.id = ? AND d.owner = ?');
    $stmt->execute([$component_id, $_SESSION['user_id']]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$component) {
        header('Location: components.php');
        exit();
    }
} catch (PDOException $e) {
    die('Erreur lors de la récupération du composant : ' . $e->getMessage());
}

$componentParamRows = compozitGetComponentParamRows($pdo, (int) $component['id']);
$componentDatasheetUrl = trim((string) (($component['datasheet'] ?? '') ?: ($component['datasheet_file'] ?? '')));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Fiche Produit - <?php echo htmlspecialchars($component['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            padding-top: 2rem;
        }
        .product-card {
            background: var(--bg-card);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .product-header {
            background: linear-gradient(45deg, #4CAF50, #45a049);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .product-image {
            width: 150px;
            height: 150px;
            object-fit: contain;
            background: white;
            border-radius: 10px;
            padding: 10px;
            margin: 0 auto 1rem auto;
            display: block;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .no-image {
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.2);
            border: 2px dashed rgba(255,255,255,0.5);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem auto;
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }
        .product-body {
            padding: 2rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .info-row.info-row-top {
            align-items: flex-start;
        }
        .info-label {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 1.1rem;
        }
        .info-value {
            font-size: 1.1rem;
            color: var(--text-primary);
        }
        .badge-smd {
            background: linear-gradient(45deg, #FF6B6B, #FF8E53);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: bold;
        }
        .quantity-highlight {
            background: linear-gradient(45deg, #4ECDC4, #44A08D);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.2rem;
        }
        .price-highlight {
            background: linear-gradient(45deg, #FFD93D, #FF6B6B);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.3rem;
        }
        .action-section {
            background: var(--bg-subtle);
            padding: 1.5rem;
            border-radius: 10px;
            margin-top: 2rem;
        }
        .params-card {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: 12px;
            background: linear-gradient(180deg, #0a1930 0%, #0d1f3b 100%);
            border: 1px solid #26406a;
            box-shadow: 0 12px 30px rgba(0,0,0,0.18);
            color: #eef4ff;
        }
        .params-card h5 {
            margin: 0 0 0.4rem;
            color: #eef4ff;
        }
        .params-card p {
            margin: 0 0 1rem;
            color: #9eb0d3;
            font-size: 0.95rem;
        }
        .params-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .param-box {
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(8, 22, 43, 0.95);
            border: 1px solid #20385e;
        }
        .param-box-label {
            color: #9eb0d3;
            font-size: 0.85rem;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .param-box-value {
            color: #eef4ff;
            font-size: 1rem;
            font-weight: 700;
        }
        .btn-custom {
            margin: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn-edit {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }
        .btn-delete {
            background: linear-gradient(45deg, #FF6B6B, #FF8E53);
            color: white;
        }
        .btn-back {
            background: linear-gradient(45deg, #4ECDC4, #44A08D);
            color: white;
        }
        .btn-params {
            background: linear-gradient(45deg, #f6c343, #e0a010);
            color: #1b1300;
        }
        .param-flash {
            margin: 0 0 1.25rem;
            padding: 0.9rem 1.1rem;
            border-radius: 10px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .param-flash.success {
            background: rgba(46, 160, 67, 0.15);
            border-color: #2ea043;
            color: #7ee2a0;
        }
        .param-flash.error {
            background: rgba(248, 81, 73, 0.15);
            border-color: #f85149;
            color: #ffb3ae;
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            color: white;
        }
        .system-info {
            background: var(--border-muted);
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        .quantity-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quantity-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .quantity-btn:disabled {
            background: var(--border-strong);
            cursor: not-allowed;
            opacity: 0.6;
        }
        .quantity-display {
            background: linear-gradient(45deg, #4ECDC4, #44A08D);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.2rem;
            min-width: 80px;
            text-align: center;
        }
        .btn-delete.disabled {
             background: var(--border-strong) !important;
             cursor: not-allowed;
             opacity: 0.6;
         }
         .product-image {
             cursor: pointer;
             transition: all 0.3s ease;
         }
         .product-image:hover {
             transform: scale(1.05);
             box-shadow: 0 5px 15px rgba(0,0,0,0.3);
         }
         .no-image {
             cursor: pointer;
             transition: all 0.3s ease;
         }
         .no-image:hover {
             background: var(--border-muted);
             transform: scale(1.05);
         }
         .image-modal {
             display: none;
             position: fixed;
             z-index: 1000;
             left: 0;
             top: 0;
             width: 100%;
             height: 100%;
             background-color: rgba(0,0,0,0.5);
         }
         .image-modal-content {
             background-color: var(--bg-card);
             margin: 5% auto;
             padding: 20px;
             border-radius: 10px;
             width: 80%;
             max-width: 600px;
             max-height: 80vh;
             overflow-y: auto;
         }
         .image-grid {
             display: grid;
             grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
             gap: 15px;
             margin-top: 20px;
         }
         .image-option {
             border: 2px solid var(--border-color);
             border-radius: 8px;
             padding: 10px;
             text-align: center;
             cursor: pointer;
             transition: all 0.3s ease;
         }
         .image-option:hover {
             border-color: #667eea;
             transform: translateY(-2px);
             box-shadow: 0 5px 15px rgba(0,0,0,0.1);
         }
         .image-option img {
             max-width: 100%;
             max-height: 100px;
             object-fit: contain;
         }
         .image-option-name {
             margin-top: 8px;
             font-size: 12px;
             color: var(--text-secondary);
         }
         .close-modal {
             color: #aaa;
             float: right;
             font-size: 28px;
             font-weight: bold;
             cursor: pointer;
         }
         .close-modal:hover {
             color: #000;
         }
         .remove-image-btn {
             background: #dc3545;
             color: white;
             border: none;
             padding: 10px 20px;
             border-radius: 5px;
             cursor: pointer;
             margin-top: 15px;
         }
         .remove-image-btn:hover {
             background: #c82333;
             transform: translateY(-1px);
         }

        .upload-section {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .upload-image-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            margin-right: 10px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .upload-image-btn:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .upload-image-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
        }

        .upload-progress {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: var(--bg-subtle);
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: var(--border-muted);
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #28a745;
            width: 0%;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="product-card">
                    <div class="product-header">
                        <?php
                        // L'image peut être un fichier local (img/xxx.jpg) ou une URL distante
                        // (image importée depuis une API fournisseur). file_exists() ne fonctionne
                        // que pour les chemins locaux : on ne l'appelle donc pas sur une URL.
                        $sheetImage = trim((string)($component['image_path'] ?? ''));
                        $sheetImageIsUrl = $sheetImage !== '' && preg_match('#^(https?:)?//#i', $sheetImage);
                        if ($sheetImage !== '' && ($sheetImageIsUrl || file_exists($sheetImage))):
                        ?>
                            <img src="<?php echo htmlspecialchars($sheetImage); ?>" alt="<?php echo htmlspecialchars($component['name']); ?>" class="product-image" onclick="openImageModal()" title="Cliquer pour changer l'image">
                        <?php else: ?>
                            <div class="no-image" onclick="openImageModal()" title="Cliquer pour ajouter une image">
                                📷 Aucune image
                            </div>
                        <?php endif; ?>
                        <h1 class="mb-0"><?php echo htmlspecialchars($component['name']); ?></h1>
                        <p class="mb-0 mt-2">Fiche détaillée du composant</p>
                    </div>
                    
                    <!-- Boutons d'action en haut -->
                    <div class="action-section">
                        <div class="text-center">
                            <a href="edit_component.php?id=<?php echo $component['id']; ?>" class="btn-custom btn-edit">
                                ✏️ Modifier le composant
                            </a>
                            <a href="components.php" class="btn-custom btn-back">
                                🔙 Retour à la liste
                            </a>
                            <form method="post" action="import_database.php" style="display:inline-block;margin:0;vertical-align:middle;"
                                  onsubmit="return confirm('Rechercher les caractéristiques manquantes de ce composant, sans écraser les valeurs existantes ?');">
                                <input type="hidden" name="action" value="enrich_single_params">
                                <input type="hidden" name="component_id" value="<?php echo (int) $component['id']; ?>">
                                <button type="submit" class="btn-custom btn-params">🔎 Rechercher les caractéristiques</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="product-body">
                        <?php
                        $sheetParamAdded = isset($_GET['param_added']) ? (int) $_GET['param_added'] : null;
                        $sheetParamError = isset($_GET['param_error']) ? trim((string) $_GET['param_error']) : '';
                        ?>
                        <?php if ($sheetParamError !== ''): ?>
                            <div class="param-flash error">⚠️ Recherche impossible : <?php echo htmlspecialchars($sheetParamError); ?></div>
                        <?php elseif ($sheetParamAdded !== null): ?>
                            <div class="param-flash success">
                                ✅ Recherche terminée : <?php echo (int) $sheetParamAdded; ?> paramètre(s) ajouté(s).
                                <?php if ((int) $sheetParamAdded === 0): ?> Aucune nouvelle caractéristique trouvée pour ce composant.<?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <span class="info-label">🆔 ID Composant</span>
                            <span class="info-value">#<?php echo htmlspecialchars($component['id']); ?></span>
                        </div>
                        
                        <div class="info-row">
                             <span class="info-label">📦 Catégorie</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['category_name'] ?? 'Non définie'); ?></span>
                         </div>
                         
                         <div class="info-row">
                             <span class="info-label">📂 Sous-catégorie</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['subcategory_name'] ?? 'Non définie'); ?></span>
                         </div>
                         
                         <div class="info-row">
                             <span class="info-label">🏭 Fabricant</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['manufacturer'] ?? 'Non spécifié'); ?></span>
                         </div>
                         
                         <div class="info-row">
                             <span class="info-label">📦 Package</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['package'] ?? 'Non spécifié'); ?></span>
                         </div>
                         
                         <div class="info-row">
                             <span class="info-label">🏪 Fournisseur</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['supplier_name'] ?? 'Non spécifié'); ?></span>
                         </div>
                         
                         <?php if (!empty($component['supplier_reference'])): ?>
                         <div class="info-row">
                             <span class="info-label">🔖 Référence fournisseur</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['supplier_reference']); ?></span>
                         </div>
                         <?php endif; ?>
                         
                         <div class="info-row">
                             <span class="info-label">🔧 Type SMD</span>
                             <span class="info-value">
                                 <?php if ($component['smd'] == 'Yes'): ?>
                                     <span class="badge-smd">OUI</span>
                                 <?php else: ?>
                                     <span class="text-muted">NON</span>
                                 <?php endif; ?>
                             </span>
                         </div>
                         
                         <?php if (!empty($component['marking'])): ?>
                         <div class="info-row">
                             <span class="info-label">🔡 Code boîtier (CMS)</span>
                             <span class="info-value">
                                 <span class="badge-smd"><?php echo htmlspecialchars($component['marking']); ?></span>
                             </span>
                         </div>
                         <?php endif; ?>
                         
                         <div class="info-row">
                             <span class="info-label">📍 Emplacement</span>
                             <span class="info-value">
                                 <?php 
                                 if ($component['casier'] || $component['tiroir'] || $component['compartiment']) {
                                     $isFlatLocation = in_array($component['storage_type'] ?? '', ['boite', 'classeur', 'etagere'], true);
                                     $locationLabel = $isFlatLocation
                                         ? $component['casier'] . '-' . $component['compartiment']
                                         : implode('-', array_filter([$component['casier'], $component['tiroir'], $component['compartiment']]));
                                     echo htmlspecialchars($locationLabel);
                                 } else {
                                     echo 'Non défini';
                                 }
                                 ?>
                             </span>
                         </div>
                        
                        <div class="info-row">
                            <span class="info-label">📊 Quantité</span>
                            <div class="quantity-controls">
                                <button class="quantity-btn decrease" onclick="updateQuantity(<?php echo $component['id']; ?>, -1)" <?php echo ($component['quantity'] <= 0) ? 'disabled' : ''; ?>>-</button>
                                <span class="quantity-display" id="quantity-<?php echo $component['id']; ?>"><?php echo htmlspecialchars($component['quantity']); ?> unités</span>
                                <button class="quantity-btn increase" onclick="updateQuantity(<?php echo $component['id']; ?>, 1)">+</button>
                            </div>
                        </div>
                        
                        <?php if (isset($component['pins']) && $component['pins']): ?>
                         <div class="info-row">
                             <span class="info-label">📌 Nombre de pins</span>
                             <span class="info-value"><?php echo htmlspecialchars($component['pins']); ?></span>
                         </div>
                         <?php endif; ?>

                        <?php if ($componentDatasheetUrl !== ''): ?>
                        <div class="info-row info-row-top">
                            <span class="info-label">📄 Datasheet</span>
                            <span class="info-value">
                                <a href="<?php echo htmlspecialchars($componentDatasheetUrl); ?>" target="_blank" rel="noopener" class="btn-custom btn-back">
                                    Ouvrir la datasheet
                                </a>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($component['comment'])): ?>
                        <div class="info-row">
                            <span class="info-label">💬 Commentaires</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($component['comment'])); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($componentParamRows)): ?>
                        <div class="params-card">
                            <h5>Paramètres techniques</h5>
                            <p>Valeurs utilisées pour décrire précisément le composant et alimenter la recherche d'équivalents.</p>
                            <div class="params-grid">
                                <?php foreach ($componentParamRows as $paramRow): ?>
                                    <?php $paramValue = compozitFormatParamValue($paramRow); ?>
                                    <?php if ($paramValue === '') { continue; } ?>
                                    <div class="param-box">
                                        <div class="param-box-label"><?php echo htmlspecialchars($paramRow['label']); ?></div>
                                        <div class="param-box-value"><?php echo htmlspecialchars($paramValue); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Bouton de suppression en bas -->
                        <div class="action-section">
                            <h5 class="mb-3">🛠️ Action de suppression</h5>
                            <div class="text-center">
                                <?php if ($component['quantity'] > 0): ?>
                                    <span class="btn-custom btn-delete disabled" title="Impossible de supprimer : quantité non nulle (<?php echo $component['quantity']; ?> unités)">
                                        🗑️ Supprimer le composant
                                    </span>
                                    <p class="text-muted mt-2">⚠️ Pour supprimer ce composant, la quantité doit être à 0</p>
                                <?php else: ?>
                                    <a href="delete_component.php?id=<?php echo $component['id']; ?>" 
                                       class="btn-custom btn-delete" 
                                       onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce composant ?')">
                                        🗑️ Supprimer le composant
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="system-info">
                            <strong>ℹ️ Informations système :</strong><br>
                            Composant consulté le <?php echo date('d/m/Y à H:i:s'); ?><br>
                            Utilisateur connecté : <?php echo htmlspecialchars($_SESSION['username'] ?? 'Utilisateur'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de sélection d'images -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <span class="close-modal" onclick="closeImageModal()">&times;</span>
            <h3>Choisir une image</h3>
            <p>Sélectionnez une image du dossier /img ou importez une nouvelle image :</p>
            
            <div class="upload-section">
                <input type="file" id="imageUpload" accept="image/*" style="display: none;" onchange="uploadImage()">
                <button class="upload-image-btn" onclick="document.getElementById('imageUpload').click()">📁 Importer une image du PC</button>
                <button class="remove-image-btn" onclick="removeImage()">🗑️ Supprimer l'image actuelle</button>
            </div>
            
            <div class="upload-progress" id="uploadProgress">
                 <p>Upload en cours...</p>
                 <div class="progress-bar">
                     <div class="progress-fill" id="progressFill"></div>
                 </div>
             </div>
             
             <div id="imageGrid" class="image-grid">
                 <!-- Les images seront chargées ici via JavaScript -->
             </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fonction pour mettre à jour la quantité
        function updateQuantity(componentId, change) {
            const quantityElement = document.getElementById('quantity-' + componentId);
            const currentQuantity = parseInt(quantityElement.textContent.replace(' unités', ''));
            const newQuantity = currentQuantity + change;
            
            if (newQuantity < 0) {
                return;
            }
            
            // Envoyer la requête AJAX
            fetch('update_quantity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'component_id=' + componentId + '&change=' + change
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mettre à jour l'affichage
                    quantityElement.textContent = data.new_quantity + ' unités';
                    
                    // Mettre à jour l'état du bouton diminuer
                    const decreaseBtn = document.querySelector('.quantity-btn.decrease');
                    if (data.new_quantity <= 0) {
                        decreaseBtn.disabled = true;
                    } else {
                        decreaseBtn.disabled = false;
                    }
                    
                    // Recharger la page pour mettre à jour la contrainte de suppression
                    if ((currentQuantity > 0 && data.new_quantity === 0) || (currentQuantity === 0 && data.new_quantity > 0)) {
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    }
                } else {
                    alert('Erreur lors de la mise à jour : ' + (data.error || 'Erreur inconnue'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }
         
         // Fonctions pour la modal d'images
         function openImageModal() {
             document.getElementById('imageModal').style.display = 'block';
             loadImages();
         }
         
         function closeImageModal() {
             document.getElementById('imageModal').style.display = 'none';
         }
         
         function loadImages() {
             fetch('get_images.php')
             .then(response => response.json())
             .then(data => {
                 if (data.images) {
                     const imageGrid = document.getElementById('imageGrid');
                     imageGrid.innerHTML = '';
                     
                     data.images.forEach(image => {
                         const imageOption = document.createElement('div');
                         imageOption.className = 'image-option';
                         imageOption.onclick = () => selectImage(image.path);
                         
                         imageOption.innerHTML = `
                             <img src="${image.path}" alt="${image.name}">
                             <div class="image-option-name">${image.name}</div>
                         `;
                         
                         imageGrid.appendChild(imageOption);
                     });
                 }
             })
             .catch(error => {
                 console.error('Erreur lors du chargement des images:', error);
                 alert('Erreur lors du chargement des images');
             });
         }
         
         function selectImage(imagePath) {
             const componentId = <?php echo $component['id']; ?>;
             
             fetch('update_image.php', {
                 method: 'POST',
                 headers: {
                     'Content-Type': 'application/x-www-form-urlencoded',
                 },
                 body: 'component_id=' + componentId + '&image_path=' + encodeURIComponent(imagePath)
             })
             .then(response => response.json())
             .then(data => {
                 if (data.success) {
                     // Recharger la page pour afficher la nouvelle image
                     location.reload();
                 } else {
                     alert('Erreur lors de la mise à jour : ' + (data.error || 'Erreur inconnue'));
                 }
             })
             .catch(error => {
                 console.error('Erreur:', error);
                 alert('Erreur de communication avec le serveur');
             });
         }
         
         function removeImage() {
             const componentId = <?php echo $component['id']; ?>;
             
             if (confirm('Êtes-vous sûr de vouloir supprimer l\'image de ce composant ?')) {
                 fetch('update_image.php', {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/x-www-form-urlencoded',
                     },
                     body: 'component_id=' + componentId + '&image_path='
                 })
                 .then(response => response.json())
                 .then(data => {
                     if (data.success) {
                         // Recharger la page pour afficher le changement
                         location.reload();
                     } else {
                         alert('Erreur lors de la suppression : ' + (data.error || 'Erreur inconnue'));
                     }
                 })
                 .catch(error => {
                     console.error('Erreur:', error);
                     alert('Erreur de communication avec le serveur');
                 });
             }
         }
         
         // Fonction pour uploader une image
         function uploadImage() {
             const fileInput = document.getElementById('imageUpload');
             const file = fileInput.files[0];
             
             if (!file) {
                 return;
             }
             
             // Vérifier le type de fichier
             const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
             if (!allowedTypes.includes(file.type)) {
                 alert('Type de fichier non autorisé. Formats acceptés: JPG, PNG, GIF, SVG, WebP');
                 return;
             }
             
             // Vérifier la taille (max 5MB)
             if (file.size > 5 * 1024 * 1024) {
                 alert('Le fichier est trop volumineux (max 5MB)');
                 return;
             }
             
             const formData = new FormData();
             formData.append('image', file);
             
             const progressDiv = document.getElementById('uploadProgress');
             const progressFill = document.getElementById('progressFill');
             
             // Afficher la barre de progression
             progressDiv.style.display = 'block';
             progressFill.style.width = '0%';
             
             // Créer une requête XMLHttpRequest pour suivre le progrès
             const xhr = new XMLHttpRequest();
             
             xhr.upload.addEventListener('progress', function(e) {
                 if (e.lengthComputable) {
                     const percentComplete = (e.loaded / e.total) * 100;
                     progressFill.style.width = percentComplete + '%';
                 }
             });
             
             xhr.onload = function() {
                 progressDiv.style.display = 'none';
                 
                 if (xhr.status === 200) {
                     try {
                         const response = JSON.parse(xhr.responseText);
                         if (response.success) {
                             // Utiliser l'image uploadée pour le composant
                             selectImage(response.image_path);
                         } else {
                             alert('Erreur lors de l\'upload : ' + (response.error || 'Erreur inconnue'));
                         }
                     } catch (e) {
                         alert('Erreur lors du traitement de la réponse');
                     }
                 } else {
                     alert('Erreur lors de l\'upload du fichier');
                 }
                 
                 // Réinitialiser l'input file
                 fileInput.value = '';
             };
             
             xhr.onerror = function() {
                 progressDiv.style.display = 'none';
                 alert('Erreur de communication avec le serveur');
                 fileInput.value = '';
             };
             
             xhr.open('POST', 'upload_image.php');
             xhr.send(formData);
         }
         
         // Fermer la modal en cliquant à l'extérieur
         window.onclick = function(event) {
             const modal = document.getElementById('imageModal');
             if (event.target === modal) {
                 closeImageModal();
             }
         }
     </script>
 
    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>
</body>
 </html>
