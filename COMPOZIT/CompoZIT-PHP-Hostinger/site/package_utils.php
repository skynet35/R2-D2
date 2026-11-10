<?php
/**
 * Gestion automatique des boîtiers (table `packages`).
 *
 * Lorsqu'un composant est créé ou modifié avec un nom de boîtier encore inconnu,
 * la fiche du boîtier est créée dans la table `packages` et l'image du composant
 * est enregistrée sous « img/<Nom du boîtier>.<ext> » (convention déjà utilisée
 * par package_images_manager.php).
 */

/**
 * Devine le type de boîtier (colonne `package_type`) à partir du nom.
 * Les valeurs renvoyées appartiennent à l'ENUM de la table `packages`.
 */
function guessPackageType(string $packageName): string
{
    $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($packageName));
    if ($normalized === '') {
        return 'Other';
    }

    // SO8 / SO-8 / SOP8 / SSOP8 → famille SOIC
    if (preg_match('/^(SOP|SSOP|SO)\d/', $normalized)) {
        return 'SOIC';
    }

    // Du plus spécifique au plus générique (sinon « TO » capterait « SOT »).
    foreach (['TSSOP', 'MSOP', 'SOIC', 'QFP', 'QFN', 'DFN', 'DIP', 'BGA', 'LGA', 'CSP', 'PLCC', 'PGA', 'SOT', 'TO', 'DO'] as $type) {
        if (strpos($normalized, $type) !== false) {
            return $type;
        }
    }

    return 'Other';
}

/**
 * Télécharge une image distante (URL http/https, éventuellement protocol-relative).
 * Renvoie le contenu binaire ou null en cas d'échec.
 */
function downloadRemoteImage(string $url)
{
    $url = strpos($url, '//') === 0 ? 'https:' . $url : $url;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Compoz-it',
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($data !== false && $code >= 200 && $code < 300) ? $data : null;
    }

    $data = @file_get_contents($url);

    return $data === false ? null : $data;
}

/**
 * Enregistre l'image du boîtier sous « img/<Nom du boîtier>.<ext> ».
 *
 * Accepte aussi bien un fichier local (déjà dans img/) qu'une URL distante
 * (image importée depuis une API fournisseur), qui est alors téléchargée.
 * Une image de boîtier déjà présente n'est jamais écrasée.
 * Renvoie le chemin relatif de l'image du boîtier, ou null en cas d'échec.
 */
function savePackageImage(string $packageName, ?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return null;
    }

    // Nom de fichier sûr, dérivé du nom du boîtier
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($packageName));
    $safeName = trim($safeName, '._-');
    if ($safeName === '') {
        return null;
    }

    $imageDir = 'img/';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0755, true);
    }

    $allowed = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    // Une image de boîtier portant déjà ce nom existe : on la conserve
    foreach ($allowed as $ext) {
        if (file_exists($imageDir . $safeName . '.' . $ext)) {
            return $imageDir . $safeName . '.' . $ext;
        }
    }

    $isUrl = (bool) preg_match('#^(https?:)?//#i', $imagePath);

    if ($isUrl) {
        $data = downloadRemoteImage($imagePath);
        if ($data === null || $data === '') {
            return null;
        }
        $extension = strtolower(pathinfo((string) parse_url($imagePath, PHP_URL_PATH), PATHINFO_EXTENSION));
    } elseif (is_file($imagePath)) {
        $data = @file_get_contents($imagePath);
        if ($data === false) {
            return null;
        }
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    } else {
        return null;
    }

    // Affiner l'extension réelle à partir du contenu
    if ($extension !== 'svg' && function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($data);
        if ($info !== false && !empty($info['mime'])) {
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            if (isset($mimeMap[$info['mime']])) {
                $extension = $mimeMap[$info['mime']];
            }
        }
    }

    if (!in_array($extension, $allowed, true)) {
        $extension = 'jpg';
    }

    $target = $imageDir . $safeName . '.' . $extension;
    if (@file_put_contents($target, $data) === false) {
        return null;
    }

    return $target;
}

/**
 * Enregistre localement l'image d'un composant sous « img/<Nom>.<ext> ».
 *
 * Utilisée lors d'un import depuis une API fournisseur : l'image distante est
 * téléchargée et rapatriée dans img/ sous le nom du composant (le MPN le plus
 * souvent). En cas d'échec du téléchargement, l'URL d'origine est conservée en
 * repli afin de ne jamais perdre l'image.
 *
 * Renvoie le chemin local, l'URL de repli, ou null si aucune image.
 */
function saveComponentImage(string $componentName, ?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return null;
    }

    // Déjà un fichier local : rien à rapatrier
    if (!preg_match('#^(https?:)?//#i', $imagePath)) {
        return $imagePath;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($componentName));
    $safeName = trim($safeName, '._-');
    if ($safeName === '') {
        return $imagePath; // pas de nom exploitable : on garde l'URL
    }

    $imageDir = 'img/';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0755, true);
    }

    $allowed = ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    // Une image portant déjà ce nom existe : on la réutilise (dédoublonnage)
    foreach ($allowed as $ext) {
        if (file_exists($imageDir . $safeName . '.' . $ext)) {
            return $imageDir . $safeName . '.' . $ext;
        }
    }

    $data = downloadRemoteImage($imagePath);
    if ($data === null || $data === '') {
        return $imagePath; // repli : on conserve l'URL distante
    }

    $extension = strtolower(pathinfo((string) parse_url($imagePath, PHP_URL_PATH), PATHINFO_EXTENSION));

    // Affiner l'extension réelle à partir du contenu
    if ($extension !== 'svg' && function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($data);
        if ($info !== false && !empty($info['mime'])) {
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            if (isset($mimeMap[$info['mime']])) {
                $extension = $mimeMap[$info['mime']];
            }
        }
    }

    if (!in_array($extension, $allowed, true)) {
        $extension = 'jpg';
    }

    $target = $imageDir . $safeName . '.' . $extension;
    if (@file_put_contents($target, $data) === false) {
        return $imagePath;
    }

    return $target;
}

/**
 * Valeurs qui désignent un type de montage ou une absence de boîtier plutôt
 * qu'un boîtier réel (« TRAVERSANTE », « SMD », « N/A »…). Elles ne doivent pas
 * créer de fiche dans la table `packages`.
 */
function isPlaceholderPackage(string $packageName): bool
{
    $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($packageName));
    if ($normalized === '') {
        return true;
    }

    $placeholders = [
        'TRAVERSANT', 'TRAVERSANTE', 'THROUGHHOLE', 'THT', 'PTH',
        'SMD', 'CMS', 'SMT', 'SURFACEMOUNT',
        'NA', 'NC', 'AUCUN', 'AUCUNE', 'INCONNU', 'UNKNOWN',
        'NONE', 'AUTRE', 'OTHER', 'DIVERS',
    ];

    return in_array($normalized, $placeholders, true);
}

/**
 * Normalise un nom de boîtier : casse homogène et alias courants.
 * Ex. « dil-14 » → « DIP-14 », « so8 » → « SOIC-8 », « to220 » → « TO-220 ».
 * Les autres noms sont seulement mis en majuscules (le préfixe indique la
 * famille : « DO-214AA (SMB) » reste inchangé).
 */
function normalizePackageName(string $packageName): string
{
    $name = strtoupper(trim((string) preg_replace('/\s+/', ' ', $packageName)));
    if ($name === '') {
        return '';
    }

    // Alias → appellation retenue par la table `packages`
    $aliases = [
        '/^(?:P?DIL|P?DIP)[\s-]?(\d+)$/' => 'DIP-$1',
        '/^(?:SO|SOP|SOIC)[\s-]?(\d+)$/' => 'SOIC-$1',
        '/^(?:P?LCC)[\s-]?(\d+)$/'       => 'PLCC-$1',
        '/^TO[\s-]?(\d+[A-Z]*)$/'        => 'TO-$1',
    ];

    foreach ($aliases as $pattern => $replacement) {
        if (preg_match($pattern, $name)) {
            return (string) preg_replace($pattern, $replacement, $name);
        }
    }

    return $name;
}

/**
 * Déduit le pas (pitch, en mm) et les dimensions d'un boîtier d'après son nom
 * et son nombre de broches. Renvoie [pitch, dimensions] ; les valeurs peuvent
 * être nulles lorsqu'elles ne sont pas déductibles.
 */
function guessPackageMetrics(string $packageName, ?int $pins): array
{
    $name = strtoupper((string) preg_replace('/[\s_]+/', '-', trim($packageName)));
    $count = ($pins !== null && $pins > 0) ? $pins : null;

    // Pas normalisé par famille de boîtier (mm).
    $pitchByFamily = [
        'TSSOP' => 0.65, 'MSOP' => 0.65, 'SSOP' => 0.65,
        'SOIC'  => 1.27, 'SOT'  => 0.95,
        'LQFP'  => 0.5, 'TQFP' => 0.5, 'QFP' => 0.5,
        'QFN'   => 0.5, 'DFN'  => 0.5,
        'DIP'   => 2.54, 'TO'   => 2.54, 'SO' => 1.27,
    ];

    // L'ordre compte : « SOT » doit être testé avant « SO », « LQFP » avant « QFP ».
    $pitch = null;
    foreach (['TSSOP', 'MSOP', 'SSOP', 'SOIC', 'SOT', 'LQFP', 'TQFP', 'QFP', 'QFN', 'DFN', 'DIP', 'TO', 'SO'] as $family) {
        if (strpos($name, $family) !== false) {
            $pitch = $pitchByFamily[$family];
            break;
        }
    }

    // Dimensions des boîtiers à deux rangées de broches (longueur ≈ n/2 × pas).
    $dimensions = null;
    if ($count !== null) {
        if (strpos($name, 'DIP') !== false) {
            $width = $count >= 24 ? 15.2 : 6.4;
            $dimensions = round($count / 2 * 2.54, 1) . 'x' . $width . 'mm';
        } elseif (strpos($name, 'SOIC') !== false) {
            $width = $count >= 20 ? 7.5 : 3.9;
            $dimensions = round($count / 2 * 1.27, 1) . 'x' . $width . 'mm';
        }
    }

    return [$pitch, $dimensions];
}

/**
 * Enregistre le boîtier d'un composant dans la table `packages` s'il n'existe pas,
 * et sauvegarde l'image du composant sous « img/<Nom du boîtier>.<ext> ».
 *
 * Ne lève jamais d'exception : une erreur ici ne doit pas empêcher
 * l'enregistrement du composant.
 */
function registerComponentPackage(PDO $pdo, string $packageName, ?int $pins, ?string $smd, ?string $imagePath, int $userId, string $componentName = ''): void
{
    $packageName = normalizePackageName($packageName);
    if ($packageName === '' || isPlaceholderPackage($packageName)) {
        return;
    }

    try {
        $packageImage = savePackageImage($packageName, $imagePath);

        $stmt = $pdo->prepare("SELECT id, image_path FROM packages WHERE name = ?");
        $stmt->execute([$packageName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Le boîtier existe déjà : on ne complète que l'image manquante
            if ($packageImage !== null && empty($existing['image_path'])) {
                $update = $pdo->prepare("UPDATE packages SET image_path = ? WHERE id = ?");
                $update->execute([$packageImage, $existing['id']]);
            }
            return;
        }

        if ($smd === 'No') {
            $mountingType = 'Through-hole';
        } elseif ($smd === 'Yes') {
            $mountingType = 'Surface-mount';
        } else {
            $mountingType = 'Both';
        }

        // Pas et dimensions déduits du nom du boîtier et du nombre de broches.
        [$pitch, $dimensions] = guessPackageMetrics($packageName, $pins);

        $description = $componentName !== ''
            ? 'Boîtier ajouté automatiquement depuis le composant « ' . $componentName . ' »'
            : 'Boîtier ajouté automatiquement depuis un composant';

        $insert = $pdo->prepare("INSERT INTO packages (name, description, pin_count, package_type, mounting_type, pitch, dimensions, image_path, owner) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([
            $packageName,
            $description,
            $pins,
            guessPackageType($packageName),
            $mountingType,
            $pitch,
            $dimensions,
            $packageImage,
            $userId,
        ]);
    } catch (PDOException $e) {
        error_log('Erreur lors de la création automatique du boîtier : ' . $e->getMessage());
    }
}
