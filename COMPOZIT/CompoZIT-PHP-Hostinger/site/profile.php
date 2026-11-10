<?php
require_once 'session_init.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo = getConnection();
        
        if ($action === 'update_email') {
            $new_email = trim($_POST['new_email'] ?? '');
            
            if (empty($new_email)) {
                $error = 'Veuillez saisir un email';
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Email invalide';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$new_email, $_SESSION['user_id']]);
                
                if ($stmt->fetch()) {
                    $error = 'Cet email est déjà utilisé par un autre compte';
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    if ($stmt->execute([$new_email, $_SESSION['user_id']])) {
                        $_SESSION['user_email'] = $new_email;
                        $message = 'Email mis à jour avec succès';
                    } else {
                        $error = 'Erreur lors de la mise à jour de l\'email';
                    }
                }
            }
        } elseif ($action === 'update_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = 'Veuillez remplir tous les champs';
            } elseif (strlen($new_password) < 6) {
                $error = 'Le nouveau mot de passe doit contenir au moins 6 caractères';
            } elseif ($new_password !== $confirm_password) {
                $error = 'Les nouveaux mots de passe ne correspondent pas';
            } else {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                if (!$user || !password_verify($current_password, $user['password'])) {
                    $error = 'Mot de passe actuel incorrect';
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($stmt->execute([$hashed_password, $_SESSION['user_id']])) {
                        $message = 'Mot de passe mis à jour avec succès';
                    } else {
                        $error = 'Erreur lors de la mise à jour du mot de passe';
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error = 'Erreur de base de données : ' . $e->getMessage();
    }
}

try {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération des données utilisateur';
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
    <title>Profil - Gestion des Composants</title>
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
            background: var(--accent-indigo);
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

        .profile-section {
            background: var(--bg-subtle);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 2px solid #dee2e6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
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

        .section-title {
            font-size: 1.5em;
            margin-bottom: 20px;
            color: var(--text-primary);
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }

        .current-info {
            background: #e3f2fd;
            color: var(--text-primary, #1e293b);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2196f3;
        }

        /* Mode sombre : le fond bleu clair est remplacé par un fond du thème
           pour que le texte reste lisible. */
        html[data-theme="dark"] .current-info {
            background: var(--bg-muted);
            border-left-color: #60a5fa;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
        <div class="header-top">
            <div class="header-title">
                <div class="header-icon">👤</div>
                <div>
                    <h1>Profil Utilisateur</h1>
                    <p>Gérez vos informations de compte et préférences</p>
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
            <?php if ($message): ?>
                <div class="alert alert-success">
                    ✅ <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="profile-section">
                <h2 class="section-title">📧 Modifier l'email</h2>
                
                <div class="current-info">
                    <strong>Email actuel :</strong> <?php echo htmlspecialchars($user['email'] ?? $_SESSION['user_email']); ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_email">
                    
                    <div class="form-group">
                        <label for="new_email">Nouvel email :</label>
                        <input type="email" id="new_email" name="new_email" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">💾 Mettre à jour l'email</button>
                    </div>
                </form>
            </div>

            <div class="profile-section">
                <h2 class="section-title">🔒 Modifier le mot de passe</h2>

                <form method="POST">
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="form-group">
                        <label for="current_password">Mot de passe actuel :</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">Nouveau mot de passe :</label>
                        <input type="password" id="new_password" name="new_password" required minlength="6">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirmer le nouveau mot de passe :</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">🔐 Mettre à jour le mot de passe</button>
                    </div>
                </form>
            </div>

            <div class="profile-section">
                <h2 class="section-title">🗄️ Configuration Base de Données</h2>
                
                <div class="current-info">
                    <strong>Base de données actuelle :</strong> <?php echo htmlspecialchars(DB_NAME); ?>
                </div>
                
                <p style="margin-top:10px;color:#475569;font-size:14px">
                    Les identifiants MySQL sont stockés dans le fichier <code style="background:var(--bg-muted);border:1px solid var(--border-color);border-radius:6px;padding:2px 6px;font-family:ui-monospace,Consolas,monospace">config.local.php</code> à la racine du projet.
                    Modifiez directement ce fichier avec vos nouveaux identifiants (copiez le modèle <code style="background:var(--bg-muted);border:1px solid var(--border-color);border-radius:6px;padding:2px 6px;font-family:ui-monospace,Consolas,monospace">config.local.template.php</code> si besoin).
                </p>
            </div>
        </div>
        <footer style="margin-top: 2rem; padding: 1rem; text-align: center; border-top: 1px solid var(--border-color); background-color: var(--bg-subtle); color: var(--text-secondary); font-size: 0.9em;">
            Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
        </footer>
    </div>
</body>
</html>


