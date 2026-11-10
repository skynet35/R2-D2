<?php
require_once 'session_init.php';
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$action = $_POST['action'] ?? '';
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header('Location: index.php?error=' . urlencode('Veuillez remplir tous les champs'));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?error=' . urlencode('Email invalide'));
    exit();
}

try {
    $pdo = getConnection();
    
    if ($action === 'login') {
        // Connexion
        $stmt = $pdo->prepare("SELECT id, email, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            header('Location: components.php');
            exit();
        } else {
            header('Location: index.php?error=' . urlencode('Email ou mot de passe incorrect'));
            exit();
        }
        
    } elseif ($action === 'register') {
        // Inscription
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (strlen($password) < 6) {
            header('Location: index.php?error=' . urlencode('Le mot de passe doit contenir au moins 6 caractères'));
            exit();
        }
        
        if ($password !== $confirm_password) {
            header('Location: index.php?error=' . urlencode('Les mots de passe ne correspondent pas'));
            exit();
        }
        
        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->fetch()) {
            header('Location: index.php?error=' . urlencode('Cet email est déjà utilisé'));
            exit();
        }
        
        // Créer le nouvel utilisateur
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
        
        if ($stmt->execute([$email, $hashed_password])) {
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_email'] = $email;
            header('Location: components.php');
            exit();
        } else {
            header('Location: index.php?error=' . urlencode('Erreur lors de l\'inscription'));
            exit();
        }
        
    } else {
        header('Location: index.php?error=' . urlencode('Action invalide'));
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Erreur base de données: " . $e->getMessage());
    header('Location: index.php?error=' . urlencode('Erreur de base de données'));
    exit();
}
?>