<?php
/**
 * API JSON pour l'application Android CompoZ'IT.
 * ---------------------------------------------------------------------------
 * L'application mobile ne se connecte PAS directement à MySQL : elle appelle
 * ce fichier en HTTPS, qui détient déjà les identifiants de la base (config.php).
 * Aucune donnée de connexion n'est donc stockée sur le téléphone, et le fait
 * que l'IP du téléphone change en 4G/5G n'a plus aucune importance.
 *
 * Authentification : un jeton de longue durée, remis par l'action « login »,
 * à transmettre ensuite dans l'en-tête « Authorization: Bearer <jeton> ».
 *
 * Actions (paramètre « action », en GET ou POST) :
 *   ping      test de joignabilité du site, sans authentification
 *   login     e-mail + mot de passe  -> jeton
 *   me        vérifie un jeton et renvoie l'utilisateur
 *   logout    invalide le jeton
 *   list      liste / recherche de l'inventaire
 *   detail    fiche complète d'un composant
 *   quantity  fixe la quantité (valeur exacte ou écart « delta »)
 * ---------------------------------------------------------------------------
 * Déploiement : ce fichier suffit, il crée lui-même sa table de jetons.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const TOKEN_LIFETIME_DAYS = 60;
const MAX_RESULTS = 500;

/* =========================================================================
   Fonctions utilitaires
   ========================================================================= */

/** Réponse JSON puis arrêt du script. */
function api_reply(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

function api_fail(string $message, int $code = 400): void
{
    api_reply(['ok' => false, 'error' => $message], $code);
}

/** Paramètre reçu en POST, sinon en GET. */
function api_param(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $_GET[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Jeton présenté par l'application : en-tête Bearer, sinon paramètre. */
function api_bearer(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return api_param('token');
}

/** Échappe les caractères spéciaux de LIKE (% _ \). */
function api_escape_like(string $term): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
}

/** Crée la table des jetons si elle n'existe pas encore. */
function api_ensure_token_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY unique_token (token_hash),
            KEY user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('mobile_api: creation mobile_tokens impossible : ' . $e->getMessage());
    }
    $done = true;
}

/** Utilisateur correspondant à l'e-mail, ou null. */
function api_find_user(PDO $pdo, string $email): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT id, email, password, role, active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        /* Schéma ancien (sans « role » ni « active ») : colonnes de base suffisent. */
        $stmt = $pdo->prepare("SELECT id, email, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['role'] = 'utilisateur';
            $row['active'] = 1;
        }
        return $row ?: null;
    }
}

/** Vérifie le jeton et renvoie l'utilisateur associé, sinon null. */
function api_require_user(PDO $pdo): array
{
    $token = api_bearer();
    if ($token === '' || strlen($token) < 32) {
        api_fail('Jeton manquant.', 401);
    }

    api_ensure_token_table($pdo);
    $hash = hash('sha256', $token);

    $stmt = $pdo->prepare("SELECT t.id AS token_id, t.user_id, u.email, u.role
        FROM mobile_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > NOW()");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_fail('Session expirée, reconnectez-vous.', 401);
    }

    /* Jeton glissant : chaque appel repousse l'échéance. */
    $pdo->prepare("UPDATE mobile_tokens SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")
        ->execute([TOKEN_LIFETIME_DAYS, $row['token_id']]);

    return [
        'id' => (int) $row['user_id'],
        'email' => (string) $row['email'],
        'role' => (string) ($row['role'] ?? 'utilisateur'),
    ];
}

function api_role_label(string $role): string
{
    return $role === 'administrateur' ? 'Administrateur' : 'Utilisateur';
}

/* =========================================================================
   Lecture des composants
   ========================================================================= */

const API_SELECT = "SELECT d.id, d.name, d.manufacturer, d.package, d.pins, d.quantity,
        d.datasheet, d.datasheet_file, d.comment, d.supplier_reference, d.image_path, d.price,
        CASE
            WHEN l.storage_type IN ('boite', 'classeur', 'etagere')
                THEN CONCAT_WS('-', l.casier, l.compartiment)
            ELSE CONCAT_WS(' · ', l.casier, l.tiroir, l.compartiment)
        END AS location_label,
        cs.name AS category_sub, ch.name AS category_head
    FROM data d
    LEFT JOIN location l ON l.id = d.location_id
    LEFT JOIN category_sub cs ON cs.id = d.category
    LEFT JOIN category_head ch ON ch.id = cs.category_head_id";

/** Prix affiché sans zéros inutiles : « 1.20 » devient « 1.2 ». */
function api_price($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $price = (string) $value;
    if (strpos($price, '.') !== false) {
        $price = rtrim(rtrim($price, '0'), '.');
    }
    return $price;
}

/** Transforme une ligne SQL en objet attendu par l'application. */
function api_component(array $row): array
{
    $text = static function ($value): string {
        return $value === null ? '' : (string) $value;
    };

    return [
        'id' => (int) $row['id'],
        'name' => $text($row['name']),
        'manufacturer' => $text($row['manufacturer']),
        'package_name' => $text($row['package']),
        'pins' => $row['pins'] === null ? null : (int) $row['pins'],
        'quantity' => (int) ($row['quantity'] ?? 0),
        'datasheet' => $text($row['datasheet']),
        'datasheet_file' => $text($row['datasheet_file']),
        'comment' => $text($row['comment']),
        'supplier_reference' => $text($row['supplier_reference']),
        'image_path' => $text($row['image_path']),
        'price' => api_price($row['price']),
        'location_label' => $text($row['location_label']),
        'category_sub' => $text($row['category_sub']),
        'category_head' => $text($row['category_head']),
    ];
}

/* =========================================================================
   Traitement
   ========================================================================= */

$action = api_param('action');

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    error_log('mobile_api: connexion base impossible : ' . $e->getMessage());
    api_fail('Base de données injoignable.', 500);
}

/* ------------------------------------------------------------------ ping */
if ($action === 'ping') {
    api_reply([
        'ok' => true,
        'app' => 'compozit',
        'api' => 1,
        'time' => date('c'),
    ]);
}

/* ----------------------------------------------------------------- login */
if ($action === 'login') {
    $email = api_param('email');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        api_fail('Adresse e-mail et mot de passe requis.');
    }

    $user = api_find_user($pdo, $email);

    if (!$user || !password_verify($password, (string) $user['password'])) {
        api_fail('Adresse e-mail ou mot de passe incorrect.', 401);
    }

    if (isset($user['active']) && (int) $user['active'] !== 1) {
        api_fail('Ce compte est désactivé.', 403);
    }

    api_ensure_token_table($pdo);

    /* Ménage : les jetons périmés sont supprimés à chaque connexion. */
    $pdo->exec("DELETE FROM mobile_tokens WHERE expires_at < NOW()");

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO mobile_tokens (user_id, token_hash, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))")
        ->execute([(int) $user['id'], hash('sha256', $token), TOKEN_LIFETIME_DAYS]);

    $role = (string) ($user['role'] ?? 'utilisateur');

    api_reply([
        'ok' => true,
        'token' => $token,
        'expires_in_days' => TOKEN_LIFETIME_DAYS,
        'user' => [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'role' => $role,
            'role_label' => api_role_label($role),
        ],
    ]);
}

/* -------------------------------------------------------------------- me */
if ($action === 'me') {
    $user = api_require_user($pdo);
    api_reply([
        'ok' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'role_label' => api_role_label($user['role']),
        ],
    ]);
}

/* ---------------------------------------------------------------- logout */
if ($action === 'logout') {
    $token = api_bearer();
    if ($token !== '') {
        api_ensure_token_table($pdo);
        $pdo->prepare("DELETE FROM mobile_tokens WHERE token_hash = ?")
            ->execute([hash('sha256', $token)]);
    }
    api_reply(['ok' => true]);
}

/* ------------------------------------------------------------------ list */
if ($action === 'list') {
    $user = api_require_user($pdo);

    $search = api_param('q');
    $limit = (int) api_param('limit');
    if ($limit <= 0) {
        $limit = MAX_RESULTS;
    }
    $limit = min($limit, MAX_RESULTS);

    $sql = API_SELECT . ' WHERE d.owner = ?';
    $params = [$user['id']];

    if ($search !== '') {
        $sql .= " AND (d.name LIKE ? OR d.manufacturer LIKE ? OR d.package LIKE ?
                    OR d.comment LIKE ? OR d.supplier_reference LIKE ?)";
        $like = '%' . api_escape_like($search) . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    $sql .= ' ORDER BY d.name ASC, d.id ASC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_reply([
        'ok' => true,
        'count' => count($rows),
        'components' => array_map('api_component', $rows),
    ]);
}

/* ---------------------------------------------------------------- detail */
if ($action === 'detail') {
    $user = api_require_user($pdo);

    $id = (int) api_param('id');
    if ($id <= 0) {
        api_fail('Identifiant de composant manquant.');
    }

    $stmt = $pdo->prepare(API_SELECT . ' WHERE d.owner = ? AND d.id = ?');
    $stmt->execute([$user['id'], $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_fail('Ce composant n\'existe pas dans votre inventaire.', 404);
    }

    api_reply(['ok' => true, 'component' => api_component($row)]);
}

/* -------------------------------------------------------------- quantity */
if ($action === 'quantity') {
    $user = api_require_user($pdo);

    $id = (int) api_param('id');
    if ($id <= 0) {
        api_fail('Identifiant de composant manquant.');
    }

    $stmt = $pdo->prepare("SELECT quantity FROM data WHERE id = ? AND owner = ?");
    $stmt->execute([$id, $user['id']]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        api_fail('Ce composant n\'existe pas dans votre inventaire.', 404);
    }

    $current = (int) $current;

    /* Soit un écart (« delta », utilisé par les boutons − / +), soit une
       valeur exacte. Dans les deux cas la quantité ne descend pas sous zéro. */
    $delta = api_param('delta');
    if ($delta !== '') {
        $newQuantity = max(0, $current + (int) $delta);
    } else {
        $value = (int) api_param('quantity', (string) $current);
        $newQuantity = max(0, $value);
    }

    $pdo->prepare("UPDATE data SET quantity = ? WHERE id = ? AND owner = ?")
        ->execute([$newQuantity, $id, $user['id']]);

    api_reply(['ok' => true, 'id' => $id, 'quantity' => $newQuantity]);
}

api_fail('Action inconnue.', 400);
