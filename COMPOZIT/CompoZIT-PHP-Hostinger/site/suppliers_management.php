<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

try {
    $pdo = getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add') {
            $name = trim($_POST['name']);
            $website = trim($_POST['website']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $address = trim($_POST['address']);
            $notes = trim($_POST['notes']);
            
            if (!empty($name)) {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, website, email, phone, address, notes, owner) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $website ?: null, $email ?: null, $phone ?: null, $address ?: null, $notes ?: null, $_SESSION['user_id']]);
                $success = "Fournisseur ajouté avec succès !";
            } else {
                $error = "Le nom du fournisseur est obligatoire.";
            }
        }
        
        if ($action === 'edit') {
            $id = (int)$_POST['id'];
            $name = trim($_POST['name']);
            $website = trim($_POST['website']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $address = trim($_POST['address']);
            $notes = trim($_POST['notes']);
            
            if (!empty($name)) {
                $stmt = $pdo->prepare("UPDATE suppliers SET name = ?, website = ?, email = ?, phone = ?, address = ?, notes = ? WHERE id = ? AND owner = ?");
                $stmt->execute([$name, $website ?: null, $email ?: null, $phone ?: null, $address ?: null, $notes ?: null, $id, $_SESSION['user_id']]);
                $success = "Fournisseur modifié avec succès !";
            } else {
                $error = "Le nom du fournisseur est obligatoire.";
            }
        }
        
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND owner = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $success = "Fournisseur supprimé avec succès !";
        }
    }
    
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
    <title>Gestion des Fournisseurs</title>
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
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
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
            padding: 30px;
            border-radius: 10px;
            border: 2px solid #dee2e6;
            margin-bottom: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group textarea {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .suppliers-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--bg-card);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .suppliers-table th,
        .suppliers-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .suppliers-table th {
            background: var(--bg-subtle);
            font-weight: bold;
            color: var(--text-primary);
        }

        .suppliers-table tr:hover {
            background: var(--bg-muted);
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
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
            position: relative;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            top: 15px;
            right: 20px;
        }

        .close:hover {
            color: black;
        }

        .actions {
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">🏢</div>
                <div>
                    <h1>Gestion des Fournisseurs</h1>
                    <p>Administration avancée des fournisseurs</p>
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
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <h3>➕ Ajouter un nouveau fournisseur</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Nom du fournisseur *</label>
                            <input type="text" id="name" name="name" required placeholder="Ex: Mouser Electronics">
                        </div>
                        <div class="form-group">
                            <label for="website">Site web</label>
                            <input type="url" id="website" name="website" placeholder="https://www.exemple.com">
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" placeholder="contact@exemple.com">
                        </div>
                        <div class="form-group">
                            <label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone" placeholder="+33 1 23 45 67 89">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="address">Adresse</label>
                        <textarea id="address" name="address" placeholder="Adresse complète du fournisseur"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Notes et commentaires sur le fournisseur"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">✅ Ajouter le fournisseur</button>
                </form>
            </div>

            <h3>📋 Liste des fournisseurs (<?php echo count($suppliers); ?>)</h3>
            
            <?php if (empty($suppliers)): ?>
                <p>Aucun fournisseur trouvé. Ajoutez-en un ci-dessus !</p>
            <?php else: ?>
                <table class="suppliers-table">
                    <thead>
                        <tr>
                            <th>Nom</th>
                            <th>Site web</th>
                            <th>Email</th>
                            <th>Téléphone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($supplier['name']); ?></strong></td>
                                <td>
                                    <?php if ($supplier['website']): ?>
                                        <a href="<?php echo htmlspecialchars($supplier['website']); ?>" target="_blank">
                                            🌐 Visiter
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($supplier['email']): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>">
                                            📧 <?php echo htmlspecialchars($supplier['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($supplier['phone'] ?: '-'); ?></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-warning" onclick="editSupplier(<?php echo $supplier['id']; ?>)">✏️ Modifier</button>
                                        <button class="btn btn-danger" onclick="deleteSupplier(<?php echo $supplier['id']; ?>)">🗑️ Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeEditModal()">&times;</span>
                <h3>✏️ Modifier le fournisseur</h3>
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_name">Nom du fournisseur *</label>
                            <input type="text" id="edit_name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_website">Site web</label>
                            <input type="url" id="edit_website" name="website">
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email">
                        </div>
                        <div class="form-group">
                            <label for="edit_phone">Téléphone</label>
                            <input type="tel" id="edit_phone" name="phone">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_address">Adresse</label>
                        <textarea id="edit_address" name="address"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_notes">Notes</label>
                        <textarea id="edit_notes" name="notes"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">✅ Sauvegarder</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">❌ Annuler</button>
                </form>
            </div>
        </div>

        <script>
            const suppliers = <?php echo json_encode($suppliers); ?>;

            function editSupplier(id) {
                const supplier = suppliers.find(s => s.id == id);
                if (supplier) {
                    document.getElementById('edit_id').value = supplier.id;
                    document.getElementById('edit_name').value = supplier.name;
                    document.getElementById('edit_website').value = supplier.website || '';
                    document.getElementById('edit_email').value = supplier.email || '';
                    document.getElementById('edit_phone').value = supplier.phone || '';
                    document.getElementById('edit_address').value = supplier.address || '';
                    document.getElementById('edit_notes').value = supplier.notes || '';
                    document.getElementById('editModal').style.display = 'block';
                }
            }

            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
            }

            function deleteSupplier(id) {
                const supplier = suppliers.find(s => s.id == id);
                if (supplier && confirm(`Êtes-vous sûr de vouloir supprimer le fournisseur "${supplier.name}" ?`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            window.onclick = function(event) {
                const modal = document.getElementById('editModal');
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
        </script>

        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
</body>
</html>


