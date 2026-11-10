<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_init.php';
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

$userId = $_SESSION['user_id'];
$stats = [
    'locations' => 0,
    'suppliers' => 0,
    'packages' => 0,
    'manufacturers' => 0,
    'components' => 0,
    'projects' => 0,
    'categories' => 0,
    'images' => 0
];
$error = null;

try {
    $pdo = getConnection();
    
    // Statistiques des emplacements
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM location WHERE owner = ?");
        $stmt->execute([$userId]);
        $stats['locations'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['locations'] = 0;
    }
    
    // Statistiques des fournisseurs (certaines tables n'ont pas owner = user, voir structure)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM suppliers");
        $stmt->execute();
        $stats['suppliers'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['suppliers'] = 0;
    }
    
    // Statistiques des packages
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM packages");
        $stmt->execute();
        $stats['packages'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['packages'] = 0;
    }
    
    // Statistiques des fabricants (depuis la table manufacturers si elle existe, sinon depuis data)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT name) as count FROM manufacturers");
        $stmt->execute();
        $stats['manufacturers'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT manufacturer) as count FROM data WHERE owner = ? AND manufacturer IS NOT NULL AND manufacturer != ''");
            $stmt->execute([$userId]);
            $stats['manufacturers'] = (int)$stmt->fetch()['count'];
        } catch (Exception $e2) {
            $stats['manufacturers'] = 0;
        }
    }
    
    // Statistiques des composants
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data WHERE owner = ?");
        $stmt->execute([$userId]);
        $stats['components'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['components'] = 0;
    }
    
    // Statistiques des projets
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM projects WHERE owner = ?");
        $stmt->execute([$userId]);
        $stats['projects'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['projects'] = 0;
    }
    
    // Statistiques des catégories
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM category_sub");
        $stats['categories'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['categories'] = 0;
    }
    
    // Statistiques des images
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM images");
        $stats['images'] = (int)$stmt->fetch()['count'];
    } catch (Exception $e) {
        $stats['images'] = 0;
    }
    
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
    <title>Paramètres - Gestion des Composants</title>
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

        .content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 30px;
        }

        .stats-overview {
            background: var(--bg-subtle);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 2px solid #dee2e6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border: 2px solid var(--border-muted);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1em;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .setting-card {
            background: var(--bg-card);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border: 2px solid var(--border-muted);
            transition: all 0.3s ease;
            text-align: center;
        }

        .setting-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.15);
            border-color: #667eea;
        }

        .setting-icon {
            font-size: 3em;
            margin-bottom: 20px;
            display: block;
        }

        .setting-title {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--text-primary);
            margin-bottom: 15px;
        }

        .setting-description {
            color: var(--text-secondary);
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .setting-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: var(--accent-indigo);
            color: white;
        }

        .btn-secondary {
            background: var(--text-secondary);
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

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: bold;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* --- Base de données SQL --- */
        .db-panel {
            margin-top: 30px;
            background: #0d182c;
            border: 1px solid #1f2b46;
            border-radius: 15px;
            padding: 26px;
        }

        .db-panel h3 {
            margin-bottom: 8px;
            font-size: 1.25em;
            color: #e8eefb;
        }

        .db-panel-hint {
            color: #93a4c0;
            font-size: 0.92em;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .db-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .db-status.is-on {
            background: rgba(47, 158, 107, 0.14);
            color: #6fd3a2;
            border: 1px solid rgba(47, 158, 107, 0.4);
        }

        .db-status .db-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
        }

        .db-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .db-field label {
            display: block;
            margin-bottom: 6px;
            color: #93a4c0;
            font-size: 13px;
            font-weight: 600;
        }

        .db-field input {
            width: 100%;
            padding: 10px 12px;
            background: #101a2d;
            border: 1px solid #243858;
            border-radius: 8px;
            color: #e8eefb;
            font-size: 14px;
            font-family: inherit;
        }

        .db-field input:focus {
            outline: none;
            border-color: #5f79b8;
        }

        .db-field .db-help {
            display: block;
            margin-top: 5px;
            color: #7488a8;
            font-size: 11.5px;
        }

        .db-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">⚙️</div>
                    <div>
                        <h1>Paramètres de l'Application</h1>
                        <p>Configurez l'application et vos préférences</p>
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
                <a href="marking.php">🔡 Marquage</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
        </div>
        <div class="content">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['db_saved'])): ?>
                <div class="alert alert-success">
                    ✅ Identifiants de la base de données enregistrés.
                </div>
            <?php endif; ?>

            <!-- Vue d'ensemble -->
            <div class="stats-overview">
                <h2>📊 Vue d'ensemble de votre inventaire</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['components']; ?></div>
                        <div class="stat-label">Composants</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['locations']; ?></div>
                        <div class="stat-label">Emplacements</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['suppliers']; ?></div>
                        <div class="stat-label">Fournisseurs</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['packages']; ?></div>
                        <div class="stat-label">Packages</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['manufacturers']; ?></div>
                        <div class="stat-label">Fabricants</div>
                    </div>
                </div>
            </div>

            <!-- Gestion des paramètres -->
            <h2>🔧 Gestion des paramètres</h2>
            <div class="settings-grid">
                <!-- Emplacements -->
                <div class="setting-card">
                    <div class="setting-icon">📍</div>
                    <div class="setting-title">Emplacements</div>
                    <div class="setting-description">
                        Gérez vos emplacements de stockage : tiroirs, étagères, boîtes, etc.
                        Organisez votre inventaire physique.
                    </div>
                    <div class="setting-actions">
                        <a href="locations.php" class="btn btn-primary">📋 Voir la liste</a>
                        <a href="locations.php#add" class="btn btn-success">➕ Ajouter</a>
                    </div>
                </div>

                <!-- Fournisseurs -->
                <div class="setting-card">
                    <div class="setting-icon">🏢</div>
                    <div class="setting-title">Fournisseurs</div>
                    <div class="setting-description">
                        Gérez vos fournisseurs : coordonnées, contacts, sites web.
                        Centralisez vos sources d'approvisionnement.
                    </div>
                    <div class="setting-actions">
                        <a href="suppliers.php" class="btn btn-primary">📋 Voir la liste</a>
                        <a href="suppliers_management.php" class="btn btn-info">⚙️ Gestion avancée</a>
                    </div>
                </div>

                <!-- Packages -->
                <div class="setting-card">
                    <div class="setting-icon">📦</div>
                    <div class="setting-title">Packages</div>
                    <div class="setting-description">
                        Gérez les types de boîtiers : DIP, SOIC, QFP, BGA, etc.
                        Définissez les caractéristiques techniques.
                    </div>
                    <div class="setting-actions">
                        <a href="packages_management.php" class="btn btn-primary">📋 Gestion complète</a>
                    </div>
                </div>

                <!-- Fabricants -->
                <div class="setting-card">
                    <div class="setting-icon">🏭</div>
                    <div class="setting-title">Fabricants</div>
                    <div class="setting-description">
                        Consultez la liste des fabricants de vos composants.
                        Analysez la répartition par marque.
                    </div>
                    <div class="setting-actions">
                        <a href="manufacturers.php" class="btn btn-primary">📋 Voir la liste</a>
                    </div>
                </div>

                <!-- Catégories -->
                <div class="setting-card">
                    <div class="setting-icon">🏷️</div>
                    <div class="setting-title">Catégories</div>
                    <div class="setting-description">
                        Gérez les catégories et sous-catégories de vos composants.
                        Organisez votre inventaire par type et fonction.
                    </div>
                    <div class="setting-actions">
                        <a href="categories_management.php" class="btn btn-primary">🏷️ Gestion complète</a>
                    </div>
                </div>

                <!-- Import/Export -->
                <div class="setting-card">
                    <div class="setting-icon">💾</div>
                    <div class="setting-title">Import/Export</div>
                    <div class="setting-description">
                        Sauvegardez toute la base en <code>.sql</code> et
                        importez ou exportez aussi vos composants au format
                        <code>.csv</code>.
                    </div>
                    <div class="setting-actions">
                        <a href="export_database.php" class="btn btn-success">📤 Export</a>
                        <a href="import_database.php" class="btn btn-info">📥 Import</a>
                    </div>
                </div>

                <!-- Images & boîtiers -->
                <div class="setting-card">
                    <div class="setting-icon">🖼️</div>
                    <div class="setting-title">Images &amp; boîtiers</div>
                    <div class="setting-description">
                        Scannez les images de boîtiers, associez-les automatiquement aux packages
                        et gérez les visuels des composants.
                    </div>
                    <div class="setting-actions">
                        <a href="package_images_manager.php" class="btn btn-primary">🖼️ Scanner les images</a>
                        <a href="packages_management.php" class="btn btn-info">📦 Packages</a>
                    </div>
                </div>

                <!-- Base de marquage CMS -->
                <div class="setting-card">
                    <div class="setting-icon">🔡</div>
                    <div class="setting-title">Base de marquage CMS</div>
                    <div class="setting-description">
                        Installez la table des codes imprimés sur les boîtiers
                        (2 à 4 caractères) : elle permet de retrouver une référence
                        complète à partir du code lu sur un composant.
                    </div>
                    <div class="setting-actions">
                        <a href="marking.php" class="btn btn-primary">🔎 Rechercher</a>
                        <a href="import_marking_codes.php" class="btn btn-info">📥 Installer la base</a>
                    </div>
                </div>

                <!-- Profile -->
                <div class="setting-card">
                    <div class="setting-icon">👤</div>
                    <div class="setting-title">Profile</div>
                    <div class="setting-description">
                        Gérez votre profil utilisateur : email, mot de passe.
                    </div>
                    <div class="setting-actions">
                        <a href="profile.php" class="btn btn-primary">⚙️ Gérer le profil</a>
                    </div>
                </div>

                <!-- Réglage API -->
                <div class="setting-card">
                    <div class="setting-icon">🔌</div>
                    <div class="setting-title">Réglage API</div>
                    <div class="setting-description">
                        Identifiants des API fournisseurs (Nexar / Octopart, Mouser, Farnell / element14,
                        DigiKey, TME, LCSC et Datasheets.com) et nombre de résultats par recherche, pour l'import
                        de composants et le repli automatique des datasheets.
                        <br><br>
                        <strong>État :</strong>
                        <?php
                        $nexarReady   = defined('NEXAR_CLIENT_ID') && NEXAR_CLIENT_ID !== ''
                                        && defined('NEXAR_CLIENT_SECRET') && NEXAR_CLIENT_SECRET !== '';
                        $mouserReady  = defined('MOUSER_API_KEY') && MOUSER_API_KEY !== '';
                        $farnellReady = defined('FARNELL_API_KEY') && FARNELL_API_KEY !== '';
                        $digikeyReady = defined('DIGIKEY_CLIENT_ID') && DIGIKEY_CLIENT_ID !== ''
                                        && defined('DIGIKEY_CLIENT_SECRET') && DIGIKEY_CLIENT_SECRET !== '';
                        $tmeReady     = defined('TME_TOKEN') && TME_TOKEN !== ''
                                        && defined('TME_SECRET') && TME_SECRET !== '';
                        $datasheetsReady = defined('DATASHEETS_API_KEY') && DATASHEETS_API_KEY !== '';
                        ?>
                        Nexar / Octopart : <?php echo $nexarReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                        <br>
                        Mouser : <?php echo $mouserReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                        <br>
                        Farnell / element14 : <?php echo $farnellReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                        <br>
                        DigiKey : <?php echo $digikeyReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                        <br>
                        TME : <?php echo $tmeReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                        <br>
                        LCSC Electronics : ✅ configurée (sans clé)
                        <br>
                        Datasheets.com : <?php echo $datasheetsReady ? '✅ configurée' : '⚠️ non configurée'; ?>
                    </div>
                    <div class="setting-actions">
                        <a href="api_settings.php" class="btn btn-primary">🔌 Régler l'API</a>
                    </div>
                </div>
            </div>

            <!-- Base de données SQL -->
            <div class="db-panel">
                <h3>🗄️ Base de données SQL</h3>
                <p class="db-panel-hint">
                    Connexion MySQL utilisée par CompoZ'IT. Les identifiants sont enregistrés
                    dans <code>config.local.php</code> (une sauvegarde <code>.bak</code> est créée
                    avant chaque modification).
                </p>

                <div class="db-status is-on">
                    <span class="db-dot"></span>
                    <span>Connecté à <strong><?php echo htmlspecialchars(DB_NAME); ?></strong>
                    (<?php echo htmlspecialchars(DB_HOST); ?>)</span>
                </div>

                <form method="POST" action="db_setup.php">
                    <input type="hidden" name="redirect" value="settings.php">

                    <div class="db-form-grid">
                        <div class="db-field">
                            <label for="settings-db-host">Serveur (hôte)</label>
                            <input type="text" id="settings-db-host" name="db_host"
                                   value="<?php echo htmlspecialchars(DB_HOST); ?>"
                                   placeholder="localhost" required>
                        </div>

                        <div class="db-field">
                            <label for="settings-db-name">Nom de la base</label>
                            <input type="text" id="settings-db-name" name="db_name"
                                   value="<?php echo htmlspecialchars(DB_NAME); ?>"
                                   placeholder="compozit" required>
                        </div>

                        <div class="db-field">
                            <label for="settings-db-user">Utilisateur</label>
                            <input type="text" id="settings-db-user" name="db_user"
                                   value="<?php echo htmlspecialchars(DB_USER); ?>"
                                   placeholder="root" required>
                        </div>

                        <div class="db-field">
                            <label for="settings-db-password">Mot de passe</label>
                            <input type="password" id="settings-db-password" name="db_password"
                                   value="" autocomplete="new-password"
                                   placeholder="Laisser vide pour conserver l'actuel">
                            <span class="db-help">Laissez vide pour ne pas changer le mot de passe enregistré.</span>
                        </div>
                    </div>

                    <div class="db-actions">
                        <button type="submit" class="btn btn-primary">💾 Enregistrer la connexion</button>
                        <a href="db_setup.php" class="btn btn-ghost">⚙️ Tester / ouvrir la page dédiée</a>
                    </div>
                </form>
            </div>
        </div>

    <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>
    </div>
</body>
</html>

