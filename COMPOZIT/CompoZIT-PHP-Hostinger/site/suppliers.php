<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

try {
    $pdo = getConnection();
    
    $sql_suppliers = "
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            website VARCHAR(255),
            email VARCHAR(255),
            phone VARCHAR(50),
            address TEXT,
            logo_path VARCHAR(255),
            notes TEXT,
            owner INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($sql_suppliers);
    
    $sql_contacts = "
        CREATE TABLE IF NOT EXISTS supplier_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            phone VARCHAR(50),
            position VARCHAR(255),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            INDEX idx_supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($sql_contacts);
    
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE owner = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $suppliers = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Erreur de base de données : " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Gestion des Fournisseurs - ECDB</title>
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --bg-muted: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            --accent-indigo: #6366f1;
            --accent-indigo-light: #e0e7ff;
            --accent-purple: #8b5cf6;
            --accent-pink: #ec4899;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --accent-teal: #14b8a6;
            --accent-orange: #f97316;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container {
            max-width: 1480px;
            margin: 0 auto;
            padding: 20px;
        }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--radius-xl);
            padding: 28px 32px 32px;
            margin-bottom: 24px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.2);
            position: relative;
            overflow: hidden;
        }
        .app-header::before {
            content: '';
            position: absolute;
            top: -80px;
            right: -80px;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.15), transparent 70%);
            border-radius: 50%;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            z-index: 2;
            margin-bottom: 20px;
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .header-icon {
            width: 52px;
            height: 52px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .header-title p {
            font-size: 13px;
            opacity: 0.85;
            margin-top: 3px;
        }
        .user-chip {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2);
            font-size: 13px;
        }
        .logout-link {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 6px;
            position: relative;
            z-index: 2;
        }
        .nav-buttons a {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            padding: 10px 18px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.2);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .nav-buttons a:hover {
            background: rgba(255,255,255,0.28);
            transform: translateY(-1px);
        }
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn:active { transform: translateY(0); }
        .btn-indigo  { background: var(--accent-indigo); color: white; }
        .btn-purple  { background: var(--accent-purple); color: white; }
        .btn-danger  { background: var(--accent-red); color: white; }
        .btn-ghost   { background: var(--bg-muted); color: var(--text-secondary); }
        .btn-ghost:hover { background: var(--border-color); }
        .btn-sm      { padding: 6px 11px; font-size: 12px; border-radius: 6px; gap: 4px; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

        .btn-primary {
            background: var(--accent-green);
            color: white;
        }

        .btn-secondary {
            background: var(--accent-blue);
            color: white;
        }

        .btn-success {
            background: var(--accent-green);
            color: white;
        }

        .btn-info {
            background: var(--accent-teal);
            color: white;
        }

        .content {
            padding: 30px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .suppliers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .supplier-card {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            background: #f9f9f9;
            transition: transform 0.3s ease;
        }

        .supplier-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .supplier-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .supplier-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-right: 15px;
            object-fit: cover;
            border: 2px solid var(--border-color);
        }

        .supplier-name {
            font-size: 18px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .supplier-info {
            margin-bottom: 10px;
        }

        .supplier-info strong {
            color: var(--text-secondary);
        }

        .contacts-list {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        .contact-item {
            background: var(--bg-card);
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 768px) {
            .suppliers-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">🏭</div>
                <div>
                    <h1>Fournisseurs</h1>
                    <p>Gérez vos contacts, logos et références fournisseurs</p>
                </div>
            </div>
            <div class="user-chip">
                <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
            </div>
        </div>
        <div class="nav-buttons">
            <a href="components.php">📦 Composants</a>
            <a href="create_component.php">➕ Créer</a>
            <a href="projects.php">🚀 Projets</a>
            <a href="settings.php">⚙️ Paramètres</a>
        </div>
        </div>
        <div class="content">
            <?php if (isset($_GET['success'])): ?>
                <div class="success">
                    <?php
                    switch($_GET['success']) {
                        case 'supplier_added':
                            echo "Fournisseur ajouté avec succès !";
                            break;
                        case 'supplier_updated':
                            echo "Fournisseur modifié avec succès !";
                            break;
                        case 'supplier_deleted':
                            echo "Fournisseur supprimé avec succès !";
                            break;
                        default:
                            echo "Opération réussie !";
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div style="text-align: center; margin-bottom: 30px;">
                <a href="add_supplier.php" class="btn btn-primary">➕ Nouveau Fournisseur</a>
            </div>

            <?php if (empty($suppliers)): ?>
                <div style="text-align: center; padding: 50px; color: var(--text-secondary);">
                    <h3>Aucun fournisseur trouvé</h3>
                    <p>Commencez par ajouter votre premier fournisseur !</p>
                    <a href="add_supplier.php" class="btn btn-primary">➕ Ajouter un fournisseur</a>
                </div>
            <?php else: ?>
                <div class="suppliers-grid">
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php
                        $stmt = $pdo->prepare("SELECT * FROM supplier_contacts WHERE supplier_id = ? ORDER BY name");
                        $stmt->execute([$supplier['id']]);
                        $contacts = $stmt->fetchAll();
                        ?>
                        <div class="supplier-card">
                            <div class="supplier-header">
                                <?php if ($supplier['logo_path']): ?>
                                    <img src="<?php echo htmlspecialchars($supplier['logo_path']); ?>" alt="Logo" class="supplier-logo">
                                <?php else: ?>
                                    <div class="supplier-logo" style="background: var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 24px;">🏢</div>
                                <?php endif; ?>
                                <div class="supplier-name"><?php echo htmlspecialchars($supplier['name']); ?></div>
                            </div>
                            
                            <?php if ($supplier['website']): ?>
                                <div class="supplier-info">
                                    <strong>Site web:</strong> <a href="<?php echo htmlspecialchars($supplier['website']); ?>" target="_blank"><?php echo htmlspecialchars($supplier['website']); ?></a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($supplier['email']): ?>
                                <div class="supplier-info">
                                    <strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>"><?php echo htmlspecialchars($supplier['email']); ?></a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($supplier['phone']): ?>
                                <div class="supplier-info">
                                    <strong>Téléphone:</strong> <?php echo htmlspecialchars($supplier['phone']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($supplier['address']): ?>
                                <div class="supplier-info">
                                    <strong>Adresse:</strong> <?php echo nl2br(htmlspecialchars($supplier['address'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($supplier['notes']): ?>
                                <div class="supplier-info">
                                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($supplier['notes'])); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($contacts)): ?>
                                <div class="contacts-list">
                                    <strong>Contacts (<?php echo count($contacts); ?>):</strong>
                                    <?php foreach ($contacts as $contact): ?>
                                        <div class="contact-item">
                                            <strong><?php echo htmlspecialchars($contact['name']); ?></strong>
                                            <?php if ($contact['position']): ?>
                                                <br><em><?php echo htmlspecialchars($contact['position']); ?></em>
                                            <?php endif; ?>
                                            <?php if ($contact['email']): ?>
                                                <br>📧 <a href="mailto:<?php echo htmlspecialchars($contact['email']); ?>"><?php echo htmlspecialchars($contact['email']); ?></a>
                                            <?php endif; ?>
                                            <?php if ($contact['phone']): ?>
                                                <br>📞 <?php echo htmlspecialchars($contact['phone']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 15px; text-align: center;">
                                <a href="edit_supplier.php?id=<?php echo $supplier['id']; ?>" class="btn btn-primary">✏️ Modifier</a>
                                <a href="delete_supplier.php?id=<?php echo $supplier['id']; ?>" class="btn btn-danger" onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce fournisseur ?')">🗑️ Supprimer</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
</body>
</html>


