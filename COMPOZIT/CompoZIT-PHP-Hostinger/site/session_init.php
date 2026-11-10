<?php
// Configuration des sessions - utiliser un répertoire local
$localTmp = dirname(__FILE__) . '/tmp';
if (!is_dir($localTmp)) {
    mkdir($localTmp, 0777, true);
}

// S'assurer que le répertoire est accessible
if (is_dir($localTmp) && is_writable($localTmp)) {
    ini_set('session.save_path', $localTmp);
} else {
    // Fallback au répertoire temporaire système
    ini_set('session.save_path', sys_get_temp_dir());
}

ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 1440);

// Démarrer la session avec gestion d'erreurs
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Exception $e) {
    // En cas d'erreur, réessayer avec le répertoire temporaire système
    ini_set('session.save_path', sys_get_temp_dir());
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}