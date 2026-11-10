<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    function cleanSupplierFilename($name, $ext = null) {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9._-]+/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');
        if ($ext !== null) {
            $name .= '.' . ltrim(strtolower($ext), '.');
        }
        return $name;
    }
    
    $logo_path = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK && !empty($name)) {
        $upload_dir = 'img/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $filename = 'supplier_' . cleanSupplierFilename($name) . '_' . time() . '.' . $file_extension;
            $target_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                $logo_path = $target_path;
            }
        }
    }
    
    if (empty($name)) {
        $error = "Le nom du fournisseur est obligatoire.";
    } else {
        try {
            $pdo = getConnection();
            
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (name, website, email, phone, address, logo_path, notes, owner) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $name,
                $website ?: null,
                $email ?: null,
                $phone ?: null,
                $address ?: null,
                $logo_path,
                $notes ?: null,
                $_SESSION['user_id']
            ]);
            
            if ($result) {
                $supplier_id = $pdo->lastInsertId();
                
                $contacts = $_POST['contacts'] ?? [];
                foreach ($contacts as $contact) {
                    $contact_name = trim($contact['name'] ?? '');
                    $contact_email = trim($contact['email'] ?? '');
                    $contact_phone = trim($contact['phone'] ?? '');
                    $contact_position = trim($contact['position'] ?? '');
                    $contact_notes = trim($contact['notes'] ?? '');
                    
                    if (!empty($contact_name)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO supplier_contacts (supplier_id, name, email, phone, position, notes) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $supplier_id,
                            $contact_name,
                            $contact_email ?: null,
                            $contact_phone ?: null,
                            $contact_position ?: null,
                            $contact_notes ?: null
                        ]);
                    }
                }
                
                header('Location: suppliers.php?success=supplier_added');
                exit();
            } else {
                $error = "Erreur lors de l'ajout du fournisseur.";
            }
            
        } catch(PDOException $e) {
            $error = "Erreur de base de données : " . $e->getMessage();
        }
    }
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
    <title>Ajouter un Fournisseur</title>
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
        .btn-primary { background: var(--accent-green); color: white; }
        .btn-secondary { background: var(--accent-blue); color: white; }
        .btn-success { background: var(--accent-green); color: white; }

        .content-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 13px;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-indigo);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        .error {
            background: #fef2f2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            border: 1px solid #fecaca;
            font-size: 14px;
        }
        .contacts-section {
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 24px;
            margin-top: 24px;
            background: var(--bg-muted);
        }
        .contacts-section h3 {
            color: var(--text-primary);
            font-size: 16px;
            margin-bottom: 8px;
        }
        .contacts-section > p {
            color: var(--text-secondary);
            font-size: 13px;
            margin-bottom: 16px;
        }
        .contact-item {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 15px;
            background: var(--bg-card);
            position: relative;
        }
        .contact-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .contact-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }
        .remove-contact {
            background: var(--accent-red);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            padding: 6px 12px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: background 0.15s;
        }
        .remove-contact:hover {
            background: #dc2626;
        }
        .small-muted {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
            display: block;
        }
        .form-actions {
            margin-top: 30px;
            text-align: center;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        footer {
            margin-top: 2rem;
            padding: 1rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-card);
            color: var(--text-secondary);
            font-size: 0.9em;
            border-radius: var(--radius-lg);
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">➕</div>
                    <div>
                        <h1>Ajouter un Fournisseur</h1>
                        <p>Enregistrer un nouveau fournisseur avec ses contacts et son logo</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <nav class="nav-buttons">
                <a href="suppliers.php">← Fournisseurs</a>
                <a href="components.php">📦 Composants</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </nav>
        </header>

        <div class="content-card">
            <div style="margin-bottom: 20px;">
                <a href="suppliers.php" class="btn btn-ghost">← Retour aux fournisseurs</a>
            </div>

            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="name">Nom du fournisseur *</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="website">Site web</label>
                        <input type="url" id="website" name="website" value="<?php echo htmlspecialchars($_POST['website'] ?? ''); ?>" placeholder="https://exemple.com">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Téléphone</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="address">Adresse</label>
                    <textarea id="address" name="address" placeholder="Adresse complète du fournisseur"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="logo">Logo de l'entreprise</label>
                    <input type="file" id="logo" name="logo" accept="image/*,.svg">
                    <span class="small-muted">Formats acceptés: JPG, PNG, GIF, WebP, SVG</span>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Notes et commentaires sur le fournisseur"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="contacts-section">
                    <h3>👥 Contacts</h3>
                    <p>Ajoutez les contacts de ce fournisseur</p>
                    
                    <div id="contacts-container">
                    </div>
                    
                    <button type="button" onclick="addContact()" class="btn btn-secondary">➕ Ajouter un contact</button>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Enregistrer le fournisseur</button>
                    <a href="suppliers.php" class="btn btn-ghost">Annuler</a>
                </div>
            </form>
        </div>

        <footer>
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>

    <script>
        let contactIndex = 0;

        function addContact() {
            const container = document.getElementById('contacts-container');
            const contactDiv = document.createElement('div');
            contactDiv.className = 'contact-item';
            contactDiv.innerHTML = `
                <div class="contact-header">
                    <span class="contact-title">Contact ${contactIndex + 1}</span>
                    <button type="button" class="remove-contact" onclick="removeContact(this)">Supprimer</button>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Nom (facultatif)</label>
                        <input type="text" name="contacts[${contactIndex}][name]">
                    </div>
                    <div class="form-group">
                        <label>Poste</label>
                        <input type="text" name="contacts[${contactIndex}][position]" placeholder="Ex: Responsable commercial">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="contacts[${contactIndex}][email]">
                    </div>
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="contacts[${contactIndex}][phone]">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="contacts[${contactIndex}][notes]" placeholder="Notes sur ce contact"></textarea>
                </div>
            `;
            container.appendChild(contactDiv);
            contactIndex++;
        }

        function removeContact(button) {
            button.closest('.contact-item').remove();
        }
    </script>
</body>
</html>


