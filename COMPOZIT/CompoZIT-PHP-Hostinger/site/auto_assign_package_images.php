<?php
/* =========================================================================
   CompoZ'IT - Assignation automatique des images de boitiers
   ---------------------------------------------------------------------------
   Attribue a chaque composant sans image l'image de son boitier (package)
   lorsqu'un fichier correspondant existe dans img/.

   Ce fichier est concu pour etre INCLUS par package_images_manager.php :

       $result = include 'auto_assign_package_images.php';

   Il ne produit aucune sortie HTML et renvoie un tableau :

       ['success' => bool, 'assigned_count' => int, 'total_checked' => int]
       ['success' => false, 'error' => '...', ...] en cas de probleme

   Il peut aussi etre lance en ligne de commande (php auto_assign_package_images.php).
   ========================================================================= */

require_once __DIR__ . '/config.php';

/* Le script est dans public_html : en dehors de la ligne de commande, il
   exige une session ouverte pour ne pas offrir un point d'ecriture public. */
if (PHP_SAPI !== 'cli' && !isset($_SESSION['user_id'])) {
    return [
        'success'        => false,
        'error'          => 'Non autorise',
        'assigned_count' => 0,
        'total_checked'  => 0,
    ];
}

/**
 * Extensions d'images acceptees, par ordre de priorite.
 *
 * Le PNG et le JPEG passent avant le SVG : les trois boitiers fournis en
 * vectoriel (TO-220, DIP-8, SOT-23) existent aussi en bitmap, et
 * l'application Android ne decode pas le SVG.
 */
function compozitPackageImageExtensions(): array
{
    return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
}

/**
 * Cherche dans $imageDirectory (chemin absolu termine par un separateur)
 * l'image correspondant au nom de boitier $package.
 *
 * Renvoie le chemin relatif utilisable en base (ex. "img/DIP-8.svg") ou null.
 * Le nom de fichier renvoye est celui reellement present sur le disque :
 * indispensable sur un hebergement Linux, qui distingue les majuscules.
 */
function compozitFindPackageImage(string $package, string $imageDirectory): ?string
{
    $package = trim($package);
    if ($package === '' || !is_dir($imageDirectory)) {
        return null;
    }

    /* Repertoire des images, indexe par nom en minuscules. */
    $noms = [];
    foreach ((array) glob($imageDirectory . '*') as $file) {
        if (!preg_match('/\.(svg|png|jpe?g|gif|webp)$/i', $file)) {
            continue;
        }
        $basename = basename($file);
        $noms[strtolower($basename)] = $basename;
    }
    if (!$noms) {
        return null;
    }
    ksort($noms, SORT_STRING);

    /* 1) Nom de fichier identique au boitier, casse mise a part
          (« Radial » trouve « RADIAL.jpg »). */
    foreach (compozitPackageImageExtensions() as $extension) {
        $cle = strtolower($package . '.' . $extension);
        if (isset($noms[$cle])) {
            return 'img/' . $noms[$cle];
        }
    }

    /* 2) Nom identique une fois les separateurs retires
          (« Mini-2 F-3B » trouve « Mini2-F3-B.png »). */
    $compact = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $package));
    if ($compact !== '') {
        foreach ($noms as $basename) {
            $nomCompact = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($basename, PATHINFO_FILENAME)));
            if ($nomCompact === $compact) {
                return 'img/' . $basename;
            }
        }
    }

    /* 3) Nom du boitier contenu dans le nom du fichier
          (« TO-263 » trouve « TO-263 (D2PAK).jpg ») : on retient le nom
          le plus court, puis le premier dans l'ordre alphabetique. */
    $meilleur = null;
    foreach ($noms as $basename) {
        if (stripos($basename, $package) === false) {
            continue;
        }
        if ($meilleur === null
            || strlen($basename) < strlen($meilleur)
            || (strlen($basename) === strlen($meilleur) && strcmp($basename, $meilleur) < 0)) {
            $meilleur = $basename;
        }
    }

    return $meilleur === null ? null : 'img/' . $meilleur;
}

/**
 * Parcourt les composants sans image dotes d'un boitier et leur attribue
 * l'image correspondante. Renvoie le bilan de l'operation.
 */
function compozitAutoAssignPackageImages(PDO $pdo): array
{
    $imageDirectory = __DIR__ . '/img/';

    $statement = $pdo->query(
        "SELECT id, name, package FROM data
          WHERE (image_path IS NULL OR image_path = '' OR image_path = 'default.png')
            AND package IS NOT NULL AND package != ''
          ORDER BY package, name"
    );
    $components = $statement->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare('UPDATE data SET image_path = ? WHERE id = ?');

    /* Une seule recherche d'image par boitier, quel que soit le nombre
       de composants qui le partagent. */
    $imageByPackage = [];
    $assigned = 0;

    foreach ($components as $component) {
        $package = (string) $component['package'];

        if (!array_key_exists($package, $imageByPackage)) {
            $imageByPackage[$package] = compozitFindPackageImage($package, $imageDirectory);
        }

        $imagePath = $imageByPackage[$package];
        if ($imagePath === null) {
            continue;
        }

        $update->execute([$imagePath, $component['id']]);
        $assigned++;
    }

    return [
        'success'        => true,
        'assigned_count' => $assigned,
        'total_checked'  => count($components),
    ];
}

/* --- Execution --- */
try {
    $result = compozitAutoAssignPackageImages(getConnection());
} catch (Throwable $e) {
    $result = [
        'success'        => false,
        'error'          => $e->getMessage(),
        'assigned_count' => 0,
        'total_checked'  => 0,
    ];
}

/* En ligne de commande, on affiche le bilan ; en inclusion, on le renvoie. */
if (PHP_SAPI === 'cli') {
    if ($result['success']) {
        echo $result['assigned_count'] . ' image(s) attribuee(s) sur '
           . $result['total_checked'] . " composant(s) sans image." . PHP_EOL;
    } else {
        echo 'Erreur : ' . $result['error'] . PHP_EOL;
    }
}

return $result;
