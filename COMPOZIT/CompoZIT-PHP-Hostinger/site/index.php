<?php
define('COMPOZIT_BOOTSTRAP', true);
require_once 'session_init.php';

if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'config.php')) {
    http_response_code(500);
    die('Fichier config.php introuvable. Réinstallez CompoZ\'IT.');
}

require_once 'config.php';

$dbConnectionOk = false;
$dbConnectionError = '';
$currentDbName = defined('DB_NAME') ? DB_NAME : '?';

try {
    $pdo = getConnection();
    if ($pdo) {
        try { $pdo->query('SELECT 1'); } catch (Throwable $eTry) { $pdo = null; throw $eTry; }
        $dbConnectionOk = true;
    }
} catch (Throwable $e) {
    $dbConnectionOk = false;
    $dbConnectionError = $e->getMessage();
}

if (!$dbConnectionOk) {
    http_response_code(500);
    $err = htmlspecialchars($dbConnectionError ?: 'Erreur inconnue');
    $dbn = htmlspecialchars($currentDbName);
    echo <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>CompoZ'IT — Base de données indisponible</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="stylesheet" href="assets/theme.css?v=20261109b">
<script src="assets/theme.js?v=20261109b" defer></script>
<style>
 body{margin:0;background:#0f172a;color:var(--border-color);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
 .card{background:var(--text-primary);border:1px solid #334155;border-radius:16px;max-width:560px;width:100%;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,.35)}
 h1{margin:0 0 10px;font-size:22px;color:var(--bg-muted)}
 .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#fecaca;font-size:12px;font-weight:600;margin-bottom:16px}
 code{background:#0b1220;border:1px solid #334155;border-radius:8px;padding:10px 12px;display:block;font-size:12px;color:#fca5a5;white-space:pre-wrap;word-break:break-word;margin:10px 0 18px;line-height:1.5}
 ul{margin:6px 0 0 18px;padding:0;color:var(--border-strong)}
 li{margin:6px 0;font-size:14px}
 a{color:#818cf8}
 .dbg{font-size:12px;color:var(--text-muted);margin-top:16px;border-top:1px dashed #334155;padding-top:14px}
</style></head><body>
<div class="card">
 <span class="pill">⚠️ BASE DE DONNÉES INDISPONIBLE</span>
 <h1>Impossible de se connecter à MySQL</h1>
 <p style="color:var(--border-strong);margin:4px 0 16px;font-size:14px">
 CompoZ'IT n'arrive pas à joindre la base <b style="color:var(--bg-primary)">$dbn</b>. Vérifiez :
 </p>
 <ul>
 <li>✅ <b>MySQL est démarré</b> dans XAMPP / WAMP Control Panel</li>
 <li>✅ La base <code style="display:inline;padding:2px 6px;margin:0">$dbn</code> existe dans phpMyAdmin</li>
 <li>✅ Identifiants MySQL corrects dans <b><code style="display:inline;padding:2px 6px;margin:0">config.local.php</code></b>
 <ul style="margin-top:6px">
 <li>Mot de passe root vide par défaut sur XAMPP, sinon mettez le votre.</li>
 <li>Créez <code style="display:inline;padding:2px 6px;margin:0">config.local.php</code> à partir du template <code style="display:inline;padding:2px 6px;margin:0">config.local.template.php</code> si besoin.</li>
 </ul>
 </li>
 </ul>
 <p style="color:var(--border-strong);font-size:14px">
 Une fois corrigé : <a href="index.php">↻ Recharger cette page</a>.
 </p>
 <div class="dbg">
 Détail technique (visible en mode dev uniquement) :<br>
 <code>$err</code>
 </div>
</div></body></html>
HTML;
    exit();
}

// Vérifier si l'utilisateur est déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: components.php');
    exit();
}

$error = '';
$success = '';

if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
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
    <title>Connexion - Simple Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .page {
            flex: 1;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            position: relative;
            background: var(--bg-card);
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .db-gear {
            position: absolute;
            top: 14px;
            right: 14px;
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1px solid #243858;
            background: #101a2d;
            color: #93a4c0;
            text-decoration: none;
            font-size: 15px;
            line-height: 1;
        }

        .db-gear:hover {
            color: #e8eefb;
            border-color: #5f79b8;
        }
        
        .title {
            text-align: center;
            margin-bottom: 2rem;
            color: var(--text-primary);
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .form-tabs {
            display: flex;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            flex: 1;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .form-container {
            display: none;
        }
        
        .form-container.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-weight: 500;
        }
        
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .success {
            background: #efe;
            color: #363;
            border: 1px solid #cfc;
        }

        .site-footer {
            margin-top: auto;
            width: 100%;
            padding: 1rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-subtle);
            color: var(--text-secondary);
            font-size: 0.9em;
        }

        .db-info {
            background: var(--bg-subtle);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
            text-align: center;
            color: #495057;
            font-size: 0.95rem;
        }

        .db-info strong {
            color: var(--text-primary);
        }

        .db-info a {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            text-decoration: none;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <main class="page">
        <div class="container">
            <a href="db_setup.php" class="db-gear" title="Réglage de la base de données" aria-label="Réglage de la base de données">&#9881;</a>

            <h1 class="title">🔐 Simple Login</h1>

            <div class="db-info">
                Base de données : <strong><?php echo htmlspecialchars($currentDbName); ?></strong>
                <br>
                <small style="color:var(--text-muted)">Réglage des identifiants MySQL via l'engrenage en haut à droite.</small>
            </div>
            
            <?php if ($error): ?>
                <div class="message error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="message success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="form-tabs">
                <div class="tab active" onclick="showForm('login')">Connexion</div>
                <div class="tab" onclick="showForm('register')">Inscription</div>
            </div>
            
            <!-- Formulaire de connexion -->
            <div id="login-form" class="form-container active">
                <form action="auth.php" method="POST">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label for="login-email">📧 Email</label>
                        <input type="email" id="login-email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="login-password">🔒 Mot de passe</label>
                        <input type="password" id="login-password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn">Se connecter</button>
                </form>
            </div>
            
            <!-- Formulaire d'inscription -->
            <div id="register-form" class="form-container">
                <form action="auth.php" method="POST">
                    <input type="hidden" name="action" value="register">
                    
                    <div class="form-group">
                        <label for="register-email">📧 Email</label>
                        <input type="email" id="register-email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="register-password">🔒 Mot de passe</label>
                        <input type="password" id="register-password" name="password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm-password">🔒 Confirmer le mot de passe</label>
                        <input type="password" id="confirm-password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn">S'inscrire</button>
                </form>
                
                <div style="margin-top: 1rem; text-align: center;">
                    <p style="color:var(--text-muted);font-size:13px">
                        ⚙️ Identifiants MySQL ? <a href="db_setup.php" style="color:#8aa2d3">Réglage de la base de données</a>
                    </p>
                </div>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        Créé par Jérémy Leroy - Version 1.1 - Copyright © 2025 - Tous droits réservés selon les termes de la licence Creative Commons CC BY-NC-SA 3.0
    </footer>

    <script>
        function showForm(formType) {
            // Masquer tous les formulaires
            document.querySelectorAll('.form-container').forEach(form => {
                form.classList.remove('active');
            });
            
            // Désactiver tous les onglets
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Afficher le formulaire sélectionné
            document.getElementById(formType + '-form').classList.add('active');
            
            // Activer l'onglet sélectionné
            event.target.classList.add('active');
        }
    </script>
</body>
</html>

