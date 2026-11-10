<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

try {
    $pdo = getConnection();
} catch(PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = trim($_POST['name']);
                if (!empty($name)) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO manufacturers (name, owner) VALUES (?, ?)");
                        $result = $stmt->execute([$name, $_SESSION['user_id']]);
                        if ($result) {
                            $success = "Fabricant ajouté avec succès.";
                        } else {
                            $error = "Erreur lors de l'ajout du fabricant.";
                        }
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) {
                            $error = "Ce fabricant existe déjà.";
                        } else {
                            $error = "Erreur lors de l'ajout du fabricant.";
                        }
                    }
                } else {
                    $error = "Le nom du fabricant est obligatoire.";
                }
                break;
                
            case 'update':
                $old_name = trim($_POST['old_name']);
                $new_name = trim($_POST['new_name']);
                if (!empty($old_name) && !empty($new_name)) {
                    try {
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("UPDATE manufacturers SET name = ? WHERE name = ? AND owner = ?");
                        $result1 = $stmt->execute([$new_name, $old_name, $_SESSION['user_id']]);
                        
                        $stmt = $pdo->prepare("UPDATE data SET manufacturer = ? WHERE manufacturer = ? AND owner = ?");
                        $result2 = $stmt->execute([$new_name, $old_name, $_SESSION['user_id']]);
                        
                        if ($result1) {
                            $pdo->commit();
                            $success = "Fabricant modifié avec succès.";
                        } else {
                            $pdo->rollback();
                            $error = "Erreur lors de la modification du fabricant.";
                        }
                    } catch (PDOException $e) {
                        $pdo->rollback();
                        if ($e->getCode() == 23000) {
                            $error = "Un fabricant avec ce nom existe déjà.";
                        } else {
                            $error = "Erreur lors de la modification du fabricant.";
                        }
                    }
                } else {
                    $error = "Les noms de fabricant sont obligatoires.";
                }
                break;
            
            case 'delete':
                $name = trim($_POST['name']);
                if (!empty($name)) {
                    try {
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("UPDATE data SET manufacturer = NULL WHERE manufacturer = ? AND owner = ?");
                        $stmt->execute([$name, $_SESSION['user_id']]);
                        
                        $stmt = $pdo->prepare("DELETE FROM manufacturers WHERE name = ? AND owner = ?");
                        $result = $stmt->execute([$name, $_SESSION['user_id']]);
                        
                        if ($result) {
                            $pdo->commit();
                            $success = "Fabricant supprimé avec succès.";
                        } else {
                            $pdo->rollback();
                            $error = "Erreur lors de la suppression du fabricant.";
                        }
                    } catch (PDOException $e) {
                        $pdo->rollback();
                        $error = "Erreur lors de la suppression du fabricant.";
                    }
                } else {
                    $error = "Le nom du fabricant est obligatoire.";
                }
                break;
        }
    }
}

$stmt = $pdo->prepare("
    SELECT m.id, m.name, m.created_at, COUNT(d.id) as component_count 
    FROM manufacturers m 
    LEFT JOIN data d ON m.name = d.manufacturer AND d.owner = m.owner
    WHERE m.owner = ? 
    GROUP BY m.id, m.name, m.created_at
    ORDER BY m.name ASC
");
$stmt->execute([$_SESSION['user_id']]);
$manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Gestion des Fabricants</title>
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

        .btn-warning {
            background: var(--accent-amber);
            color: var(--text-primary);
        }

        .content {
            padding: 30px;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-container {
            background: var(--bg-subtle);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid var(--border-muted);
        }

        .form-container h3 {
            color: #495057;
            margin-bottom: 20px;
            font-size: 1.3em;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
        }

        .manufacturers-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--bg-card);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .manufacturers-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }

        .manufacturers-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .manufacturers-table tr:hover {
            background: var(--bg-subtle);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: var(--bg-card);
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 80%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .manufacturers-search {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .manufacturers-search input {
            flex: 1;
            min-width: 240px;
            padding: 12px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            background: var(--bg-card);
            color: var(--text-primary);
        }

        .manufacturers-search input:focus {
            outline: none;
            border-color: #667eea;
        }

        .manufacturers-search.is-exists input {
            border-color: var(--accent-green);
        }

        .manufacturers-search.is-new input {
            border-color: var(--accent-amber);
        }

        .manufacturers-search-count {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .manufacturers-table tr.is-hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">🏷️</div>
                <div>
                    <h1>Fabricants</h1>
                    <p>Référencement des marques de composants électroniques</p>
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
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($manufacturers); ?></div>
                    <div class="stat-label">Fabricants uniques</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo array_sum(array_column($manufacturers, 'component_count')); ?></div>
                    <div class="stat-label">Composants avec fabricant</div>
                </div>
            </div>

            <div class="form-container">
                <h3>➕ Ajouter un nouveau fabricant</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="name">Nom du fabricant *</label>
                        <input type="text" id="name" name="name" required placeholder="Ex: Arduino, Texas Instruments, STMicroelectronics">
                    </div>
                    <button type="submit" class="btn btn-primary">💾 Ajouter le fabricant</button>
                </form>
            </div>

            <div class="manufacturers-list">
                <h3>📋 Liste des fabricants</h3>
                <div class="manufacturers-search">
                    <input type="search" id="manufacturerSearch" placeholder="🔍 Rechercher un fabricant (voir s'il existe déjà)..." autocomplete="off" spellcheck="false">
                    <span class="manufacturers-search-count" id="manufacturerSearchCount"></span>
                </div>
                <?php if (empty($manufacturers)): ?>
                    <p>Aucun fabricant trouvé. Ajoutez des composants avec des fabricants pour les voir apparaître ici.</p>
                <?php else: ?>
                    <table class="manufacturers-table">
                        <thead>
                            <tr>
                                <th>Nom du fabricant</th>
                                <th>Nombre de composants</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manufacturers as $manufacturer): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($manufacturer['name']); ?></td>
                                    <td><?php echo $manufacturer['component_count']; ?></td>
                                    <td>
                                        <button class="btn btn-warning" onclick="editManufacturer('<?php echo htmlspecialchars($manufacturer['name']); ?>')">✏️ Modifier</button>
                                        <button class="btn btn-danger" onclick="deleteManufacturer('<?php echo htmlspecialchars($manufacturer['name']); ?>')">🗑️ Supprimer</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h3>✏️ Modifier le fabricant</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" id="old_name" name="old_name">
                    <div class="form-group">
                        <label for="new_name">Nouveau nom *</label>
                        <input type="text" id="new_name" name="new_name" required>
                    </div>
                    <button type="submit" class="btn btn-primary">💾 Sauvegarder</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Annuler</button>
                </form>
            </div>
        </div>

        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h3>🗑️ Supprimer le fabricant</h3>
                <p>Êtes-vous sûr de vouloir supprimer ce fabricant ? Cette action supprimera le fabricant de tous les composants associés.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" id="delete_name" name="name">
                    <button type="submit" class="btn btn-danger">🗑️ Confirmer la suppression</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Annuler</button>
                </form>
            </div>
        </div>

        <script>
            function editManufacturer(name) {
                document.getElementById('old_name').value = name;
                document.getElementById('new_name').value = name;
                document.getElementById('editModal').style.display = 'block';
            }

            function deleteManufacturer(name) {
                document.getElementById('delete_name').value = name;
                document.getElementById('deleteModal').style.display = 'block';
            }

            function closeModal() {
                document.getElementById('editModal').style.display = 'none';
                document.getElementById('deleteModal').style.display = 'none';
            }

            (function () {
                var input = document.getElementById('manufacturerSearch');
                if (!input) { return; }
                var box = input.closest('.manufacturers-search');
                var count = document.getElementById('manufacturerSearchCount');
                var rows = Array.prototype.slice.call(document.querySelectorAll('.manufacturers-table tbody tr'));
                var names = rows.map(function (row) {
                    var cell = row.querySelector('td');
                    return (cell ? cell.textContent : '').trim().toLowerCase();
                });

                function apply() {
                    var q = (input.value || '').trim().toLowerCase();
                    var shown = 0;
                    var exact = false;

                    rows.forEach(function (row, i) {
                        var match = !q || names[i].indexOf(q) >= 0;
                        row.classList.toggle('is-hidden', !match);
                        if (match) { shown++; }
                        if (q && names[i] === q) { exact = true; }
                    });

                    box.classList.toggle('is-exists', !!q && exact);
                    box.classList.toggle('is-new', !!q && !exact);

                    if (!q) {
                        count.textContent = rows.length + ' fabricant(s)';
                    } else if (exact) {
                        count.textContent = '✅ Ce fabricant existe déjà';
                    } else {
                        count.textContent = '➕ Aucun fabricant correspondant exactement';
                    }
                }

                input.addEventListener('input', apply);
                input.addEventListener('search', apply);
                apply();
            })();

            window.onclick = function(event) {
                const editModal = document.getElementById('editModal');
                const deleteModal = document.getElementById('deleteModal');
                if (event.target === editModal) {
                    editModal.style.display = 'none';
                }
                if (event.target === deleteModal) {
                    deleteModal.style.display = 'none';
                }
            }
        </script>

        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
</body>
</html>


