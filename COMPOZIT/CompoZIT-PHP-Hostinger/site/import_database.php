﻿<?php
/* =========================================================================
   CompoZ'IT - Import / Export de la base de donnees
   -------------------------------------------------------------------------
   Page unique de sauvegarde et de restauration :
     - Sauvegarde SQL   : structure (SHOW CREATE TABLE) + donnees (SELECT *),
                          construites en PHP car mysqldump n'existe pas sur un
                          hebergement mutualise.
     - Export CSV       : inventaire des composants, UTF-8 avec BOM, Excel.
     - Restauration SQL : le fichier .sql est decoupe en requetes individuelles
                          puis execute requete par requete, cles etrangeres
                          desactivees.
     - Import CSV       : mise a jour ou ajout des composants de la table data.
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';
require_once 'component_params.php';
require_once 'mouser_api.php';
require_once 'farnell_api.php';
require_once 'lcsc_api.php';
require_once 'tme_api.php';
require_once 'rs_api.php';
require_once 'digikey_api.php';
require_once 'nexar_api.php';

// Verifier si l'utilisateur est connecte
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

/* Limite volontairement raisonnable : le fichier est charge entierement en
   memoire. Au-dela, passer par phpMyAdmin (onglet Importer). */
define('COMPOZIT_IMPORT_MAX_SIZE', 20 * 1024 * 1024); // 20 Mo
define('COMPOZIT_PARAM_ENRICH_BATCH', 40);

/* =====================================================================
   Restauration SQL
   ===================================================================== */

/**
 * Decoupe un fichier .sql en requetes individuelles.
 *
 * Un point-virgule ne separe deux requetes que s'il est rencontre hors
 * chaine de caracteres ('...', "..."), hors identifiant (`...`), hors
 * commentaire (-- , # , block) et hors commentaire conditionnel /*! ... *\/.
 */
function compozitSplitSql(string $sql): array
{
    $statements = [];
    $current    = '';
    $length     = strlen($sql);

    $inSingle    = false;
    $inDouble    = false;
    $inBacktick  = false;
    $inLine      = false;
    $inBlock     = false;
    $keepComment = false;   // commentaire conditionnel /*! ... */ (execute par MySQL)

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = ($i + 1 < $length) ? $sql[$i + 1] : '';

        // --- Commentaire de fin de ligne ---
        if ($inLine) {
            if ($char === "\n") {
                $inLine = false;
                $current .= $char;
            }
            continue;
        }

        // --- Commentaire de bloc ---
        if ($inBlock) {
            if ($char === '*' && $next === '/') {
                $inBlock = false;
                $i++;
                if ($keepComment) {
                    $current .= '*/';
                }
                continue;
            }
            if ($keepComment) {
                $current .= $char;
            }
            continue;
        }

        // --- A l'interieur d'une chaine ou d'un identifiant ---
        if ($inSingle || $inDouble || $inBacktick) {
            $current .= $char;

            if ($char === '\\' && !$inBacktick) {   // sequence d'echappement
                if ($next !== '') {
                    $current .= $next;
                    $i++;
                }
                continue;
            }
            if ($inSingle && $char === "'")       { $inSingle = false; }
            elseif ($inDouble && $char === '"')   { $inDouble = false; }
            elseif ($inBacktick && $char === '`') { $inBacktick = false; }
            continue;
        }

        // --- Hors chaine et hors commentaire ---
        if ($char === "'") { $inSingle = true;   $current .= $char; continue; }
        if ($char === '"') { $inDouble = true;   $current .= $char; continue; }
        if ($char === '`') { $inBacktick = true; $current .= $char; continue; }

        if ($char === '#') {
            $inLine = true;
            continue;
        }
        if ($char === '-' && $next === '-') {
            $inLine = true;
            $i++;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlock     = true;
            $keepComment = ($i + 2 < $length && $sql[$i + 2] === '!');
            $i++;
            if ($keepComment) {
                $current .= '/*!';
            }
            continue;
        }

        if ($char === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

/**
 * Certaines requetes d'un export (ajoutees par phpMyAdmin selon les options
 * cochees) visent le serveur lui-meme et non le contenu de la base :
 * sur un hebergement mutualise elles echouent inutilement.
 */
function compozitSkipStatement(string $statement): bool
{
    $head = strtoupper(substr(ltrim($statement), 0, 30));

    return strpos($head, 'USE ') === 0
        || strpos($head, 'CREATE DATABASE') === 0
        || strpos($head, 'DROP DATABASE') === 0;
}

/* =====================================================================
   Sauvegarde SQL (export)
   ===================================================================== */

/**
 * Tables de l'application, dans un ordre ou chaque table referencee est
 * creee avant celles qui la referencent.
 */
function compozitBackupTables(): array
{
    return [
        'users',
        'category_head',
        'category_sub',
        'location',
        'suppliers',
        'supplier_contacts',
        'packages',
        'manufacturers',
        'projects',
        'project_files',
        'project_items',
        'data',
        'project_components',
    ];
}

/**
 * Prepare une valeur pour une clause VALUES.
 *
 * PDO::quote() echappe les apostrophes, les antislashs mais aussi les
 * retours a la ligne (\n, \r) et les caracteres de controle : le fichier
 * reste valide apres un passage par un serveur Windows.
 */
function compozitBackupValue(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return $pdo->quote((string) $value);
}

/** Une requete INSERT groupee (200 lignes maximum par requete). */
function compozitBackupInsert(string $table, string $columnList, array $tuples): string
{
    return "INSERT INTO `{$table}` ({$columnList}) VALUES\n"
         . implode(",\n", $tuples) . ";\n";
}

/**
 * Construit le contenu complet du fichier de sauvegarde.
 */
function compozitBackupDump(PDO $pdo, string $database): string
{
    $sql  = "-- -----------------------------------------------------------------\n";
    $sql .= "-- CompoZ'IT - sauvegarde de la base `{$database}`\n";
    $sql .= "-- Generee le " . date('d/m/Y a H:i:s') . "\n";
    $sql .= "-- Restauration : page Import de l'application ou phpMyAdmin.\n";
    $sql .= "-- -----------------------------------------------------------------\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET time_zone = '+00:00';\n\n";

    /* Cles etrangeres desactivees : les tables peuvent etre remplacees dans
       n'importe quel ordre, meme si elles se referencent entre elles. */
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $dumped   = [];
    $rows     = 0;

    // --- Structure : une section par table presente dans la base ---
    foreach (compozitBackupTables() as $table) {
        if (!in_array($table, $existing, true)) {
            continue;
        }

        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);

        $sql .= "--\n-- Table `{$table}`\n--\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $create[1] . ";\n\n";

        $dumped[] = $table;
    }

    // --- Donnees ---
    foreach ($dumped as $table) {
        $stmt = $pdo->query("SELECT * FROM `{$table}`");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $sql .= "-- Table `{$table}` : aucun enregistrement\n\n";
            continue;
        }

        $sql .= "--\n-- Donnees de la table `{$table}`\n--\n";

        $columnList = '`' . implode('`, `', array_keys($row)) . '`';
        $tuples     = [];

        while ($row !== false) {
            $values = [];
            foreach ($row as $value) {
                $values[] = compozitBackupValue($pdo, $value);
            }
            $tuples[] = '(' . implode(', ', $values) . ')';
            $rows++;

            if (count($tuples) >= 200) {
                $sql .= compozitBackupInsert($table, $columnList, $tuples);
                $tuples = [];
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($tuples) {
            $sql .= compozitBackupInsert($table, $columnList, $tuples);
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
    $sql .= "-- Fin de la sauvegarde : {$rows} enregistrements, "
          . count($dumped) . " tables.\n";

    return $sql;
}

/* =====================================================================
   CSV des composants (import et export)
   ===================================================================== */

/** Colonnes gerees par l'import/export CSV des composants. */
function compozitCsvColumns(): array
{
    return [
        'id',
        'owner',
        'name',
        'manufacturer',
        'package',
        'pins',
        'smd',
        'quantity',
        'location',
        'order_quantity',
        'price',
        'datasheet',
        'comment',
        'category',
        'public',
        'url',
        'image_path',
        'created_at',
        'supplier_id',
        'supplier_reference',
    ];
}

/** Renvoie les colonnes disponibles dans la table data. */
function compozitDataColumns(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `data`");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (PDOException $e) {
        return [];
    }
}

function compozitCsvLocationLabel(?string $casier, ?string $tiroir, ?string $compartiment, ?string $storageType = null): string
{
    $casier = trim((string) $casier);
    $tiroir = trim((string) $tiroir);
    $compartiment = trim((string) $compartiment);

    if ($casier === '' && $tiroir === '' && $compartiment === '') {
        return '';
    }

    if (in_array($storageType, ['boite', 'classeur', 'etagere'], true)) {
        return $casier . '-' . $compartiment;
    }

    if ($casier !== '' && $tiroir !== '' && $compartiment !== '') {
        return $casier . $tiroir . '-' . $compartiment;
    }

    return trim(implode('-', array_filter([$casier, $tiroir, $compartiment], static function ($value): bool {
        return $value !== '';
    })), '-');
}

/** Formate une valeur pour le CSV d'export. */
function compozitCsvExportValue(string $column, $value, ?array $row = null): string
{
    if ($column === 'location') {
        return compozitCsvLocationLabel(
            $row['location_casier'] ?? '',
            $row['location_tiroir'] ?? '',
            $row['location_compartiment'] ?? '',
            $row['location_storage_type'] ?? ''
        );
    }

    if ($value === null) {
        return '';
    }

    $text = (string) $value;

    if ($column === 'created_at' && $text !== '') {
        $timestamp = strtotime($text);
        if ($timestamp !== false) {
            return date('d/m/Y H:i', $timestamp);
        }
    }

    return $text;
}

/** Echappe une ligne CSV en UTF-8 avec separateur ; compatible Excel. */
function compozitCsvLine(array $values): string
{
    $escaped = [];

    foreach ($values as $value) {
        $text = (string) $value;
        $mustQuote = strpos($text, ';') !== false
            || strpos($text, '"') !== false
            || strpos($text, "\n") !== false
            || strpos($text, "\r") !== false
            || strpos($text, "\t") !== false;

        if ($mustQuote) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        $escaped[] = $text;
    }

    return implode(';', $escaped) . "\r\n";
}

/** Construit l'export CSV des composants du compte courant. */
function compozitCsvDump(PDO $pdo, int $owner): string
{
    $wanted   = compozitCsvColumns();
    $existing = array_fill_keys(compozitDataColumns($pdo), true);

    if (!$existing) {
        throw new RuntimeException("La table des composants est introuvable.");
    }

    $select = [];
    foreach ($wanted as $column) {
        if ($column === 'location') {
            $select[] = "l.casier AS `location_casier`";
            $select[] = "l.tiroir AS `location_tiroir`";
            $select[] = "l.compartiment AS `location_compartiment`";
            $select[] = "l.storage_type AS `location_storage_type`";
            $select[] = "NULL AS `location`";
            continue;
        }
        if (isset($existing[$column])) {
            $select[] = "d.`{$column}`";
        } else {
            $select[] = "NULL AS `{$column}`";
        }
    }

    $sql = "SELECT " . implode(', ', $select) . " FROM `data` d";
    $sql .= " LEFT JOIN `location` l ON l.id = d.location_id";
    if (isset($existing['owner'])) {
        $sql .= " WHERE d.`owner` = :owner";
    }
    $sql .= " ORDER BY d.`id` ASC";

    $stmt = $pdo->prepare($sql);
    if (isset($existing['owner'])) {
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
    }
    $stmt->execute();

    $csv = "\xEF\xBB\xBF";
    $csv .= compozitCsvLine($wanted);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($wanted as $column) {
            $values[] = compozitCsvExportValue($column, $row[$column] ?? null, $row);
        }
        $csv .= compozitCsvLine($values);
    }

    return $csv;
}

/** Normalise un nom de colonne provenant du fichier. */
function compozitCsvHeader(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value);
    $value = strtolower($value);
    $value = str_replace([' ', '-'], '_', $value);
    if ($value === 'emplacement') {
        return 'location';
    }
    return $value;
}

/** Detecte le separateur le plus probable dans la premiere ligne utile. */
function compozitCsvSeparator(string $content): string
{
    foreach (preg_split("/\r\n|\n|\r/", $content) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $scores = [
            ';'  => substr_count($line, ';'),
            ','  => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($scores);
        $separator = (string) array_key_first($scores);
        return $scores[$separator] > 0 ? $separator : ';';
    }

    return ';';
}

/** Lit un CSV/TSV en preservant les retours a la ligne dans les cellules. */
function compozitParseCsv(string $content): array
{
    $separator = compozitCsvSeparator($content);
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException("Impossible de lire le fichier CSV.");
    }

    fwrite($handle, $content);
    rewind($handle);

    $headers = null;
    $rows = [];
    $line = 0;

    while (($fields = fgetcsv($handle, 0, $separator)) !== false) {
        $line++;

        if ($fields === [null] || !array_filter($fields, static function ($value) {
            return trim((string) $value) !== '';
        })) {
            continue;
        }

        if ($headers === null) {
            $headers = array_map('compozitCsvHeader', $fields);
            continue;
        }

        $row = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $row[$header] = array_key_exists($index, $fields) ? (string) $fields[$index] : '';
        }
        $row['__line'] = $line;
        $rows[] = $row;
    }

    fclose($handle);

    return [$rows, $separator, $headers ?: []];
}

/** Nettoie une valeur CSV simple. */
function compozitCsvValue(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (strlen($value) >= 2 && $value[0] === '`' && substr($value, -1) === '`') {
        $value = substr($value, 1, -1);
    }

    return $value;
}

/** Normalise Oui/Non pour les champs booleens historiques du site. */
function compozitCsvYesNo(?string $value): ?string
{
    $value = compozitCsvValue($value);
    if ($value === null) {
        return null;
    }

    $normalized = strtolower($value);
    if (in_array($normalized, ['yes', 'oui', 'true', '1'], true)) {
        return 'Yes';
    }
    if (in_array($normalized, ['no', 'non', 'false', '0'], true)) {
        return 'No';
    }

    return $value;
}

/** Convertit une date importee vers le format MySQL. */
function compozitCsvDate(?string $value): ?string
{
    $value = compozitCsvValue($value);
    if ($value === null) {
        return null;
    }

    $formats = [
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd/m/Y',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/** Prepare une ligne importee en fonction des colonnes disponibles. */
function compozitCsvRecord(array $row, int $owner, array $available): array
{
    $present = array_fill_keys($available, true);
    $record  = [];

    if (array_key_exists('location', $row) || array_key_exists('location_id', $row)) {
        $locationValue = compozitCsvValue($row['location'] ?? ($row['location_id'] ?? null));
        $record['__location_label'] = $locationValue;
    }

    foreach (compozitCsvColumns() as $column) {
        if ($column === 'location') {
            continue;
        }
        if (!isset($present[$column])) {
            continue;
        }

        switch ($column) {
            case 'owner':
                $record[$column] = (string) $owner;
                break;

            case 'smd':
            case 'public':
                $record[$column] = compozitCsvYesNo($row[$column] ?? null);
                break;

            case 'created_at':
                $record[$column] = compozitCsvDate($row[$column] ?? null);
                break;

            case 'price':
                $value = compozitCsvValue($row[$column] ?? null);
                if ($value !== null && strpos($value, ',') !== false && strpos($value, '.') === false) {
                    $value = str_replace(',', '.', $value);
                }
                $record[$column] = $value;
                break;

            default:
                $record[$column] = compozitCsvValue($row[$column] ?? null);
                break;
        }
    }

    return $record;
}

function compozitParseLocationLabel(string $value, ?string $storageType = null): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/\s+/', '', $value);
    if ($value === null || $value === '') {
        return null;
    }

    if (in_array($storageType, ['boite', 'classeur', 'etagere'], true)
        && preg_match('/^(.+)-(\d+)$/', $value, $matches) === 1) {
        return [
            'casier' => $matches[1],
            'tiroir' => '1',
            'compartiment' => $matches[2],
        ];
    }

    if (preg_match('/^([A-Za-z]+)(\d+)-(\d+)$/', $value, $matches) === 1) {
        return [
            'casier' => $matches[1],
            'tiroir' => $matches[2],
            'compartiment' => $matches[3],
        ];
    }

    if (preg_match('/^([A-Za-z]+)-?(\d+)$/', $value, $matches) === 1) {
        return [
            'casier' => $matches[1],
            'tiroir' => $matches[2],
            'compartiment' => '1',
        ];
    }

    if (preg_match('/^([^-\/]+)[-\/]([^-\/]+)[-\/]([^-\/]+)$/', $value, $matches) === 1) {
        return [
            'casier' => trim($matches[1]),
            'tiroir' => trim($matches[2]),
            'compartiment' => trim($matches[3]),
        ];
    }

    return [
        'casier' => $value,
        'tiroir' => '1',
        'compartiment' => '1',
    ];
}

function compozitFindOrCreateLocationId(PDO $pdo, int $owner, ?string $label, ?string $storageType = null): ?int
{
    $label = compozitCsvValue($label);
    if ($label === null) {
        return null;
    }

    $existingByLabel = $pdo->prepare("
        SELECT `id`
        FROM `location`
        WHERE `owner` = ?
          AND CASE
                WHEN `storage_type` IN ('boite', 'classeur', 'etagere')
                    THEN CONCAT(`casier`, '-', `compartiment`)
                ELSE CONCAT(`casier`, `tiroir`, '-', `compartiment`)
              END = ?
        LIMIT 1
    ");
    $existingByLabel->execute([$owner, $label]);
    $matchedId = $existingByLabel->fetchColumn();
    if ($matchedId !== false) {
        return (int) $matchedId;
    }

    if (ctype_digit($label)) {
        return (int) $label;
    }

    $parsed = compozitParseLocationLabel($label, $storageType);
    if ($parsed === null) {
        return null;
    }

    $select = $pdo->prepare("
        SELECT `id`
        FROM `location`
        WHERE `owner` = ? AND `casier` = ? AND `tiroir` = ? AND `compartiment` = ?
        LIMIT 1
    ");
    $select->execute([$owner, $parsed['casier'], $parsed['tiroir'], $parsed['compartiment']]);
    $existingId = $select->fetchColumn();
    if ($existingId !== false) {
        return (int) $existingId;
    }

    try {
        $insert = $pdo->prepare("
            INSERT INTO `location` (`owner`, `casier`, `tiroir`, `compartiment`, `description`, `storage_type`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $owner,
            $parsed['casier'],
            $parsed['tiroir'],
            $parsed['compartiment'],
            null,
            $storageType ?: 'casier',
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23000') {
            throw $e;
        }

        $select->execute([$owner, $parsed['casier'], $parsed['tiroir'], $parsed['compartiment']]);
        $existingId = $select->fetchColumn();
        return $existingId !== false ? (int) $existingId : null;
    }
}

/** Verifie si un identifiant existe deja. */
function compozitDataIdExists(PDO $pdo, int $id, ?int $owner = null): bool
{
    $sql = "SELECT `id` FROM `data` WHERE `id` = ?";
    $params = [$id];

    if ($owner !== null) {
        $sql .= " AND `owner` = ?";
        $params[] = $owner;
    }

    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/** Insere un composant a partir d'un enregistrement CSV. */
function compozitInsertCsvComponent(PDO $pdo, array $record): int
{
    $columns = [];
    $placeholders = [];
    $values = [];

    foreach ($record as $column => $value) {
        if (strpos((string) $column, '__') === 0) {
            continue;
        }
        if ($column === 'id' && $value === null) {
            continue;
        }
        $columns[] = "`{$column}`";
        $placeholders[] = '?';
        $values[] = $value;
    }

    $sql = "INSERT INTO `data` (" . implode(', ', $columns) . ") VALUES ("
         . implode(', ', $placeholders) . ")";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    if (isset($record['id']) && $record['id'] !== null && ctype_digit((string) $record['id'])) {
        return (int) $record['id'];
    }

    return (int) $pdo->lastInsertId();
}

/** Met a jour un composant existant a partir d'un enregistrement CSV. */
function compozitUpdateCsvComponent(PDO $pdo, int $id, int $owner, array $record): void
{
    $assignments = [];
    $values = [];

    foreach ($record as $column => $value) {
        if (strpos((string) $column, '__') === 0) {
            continue;
        }
        if ($column === 'id') {
            continue;
        }
        $assignments[] = "`{$column}` = ?";
        $values[] = $value;
    }

    $values[] = $id;
    $values[] = $owner;

    $sql = "UPDATE `data` SET " . implode(', ', $assignments)
         . " WHERE `id` = ? AND `owner` = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

/* =====================================================================
   Enrichissement non destructif des paramètres techniques après import CSV
   ===================================================================== */

function compozitParamNormalizeText(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = mb_strtolower($value, 'UTF-8');
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = strtolower($ascii);
        }
    }

    return $value;
}

function compozitParamNormalizeRef(string $value): string
{
    return preg_replace('/[^a-z0-9]+/i', '', compozitParamNormalizeText($value)) ?? '';
}

function compozitParamNumber($value): string
{
    return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
}

function compozitParamFirstNonEmpty(array $values): string
{
    foreach ($values as $value) {
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function compozitParamParseFirstMetric(string $text, array $patterns, ?callable $transform = null): string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches) !== 1) {
            continue;
        }

        $raw = str_replace(',', '.', (string) ($matches[1] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            continue;
        }

        $value = (float) $raw;
        if ($transform !== null) {
            $value = $transform($value, $matches);
        }

        if (!is_numeric($value)) {
            continue;
        }

        return compozitParamNumber($value);
    }

    return '';
}

function compozitParamLabelPattern(array $labels): string
{
    $parts = [];
    foreach ($labels as $label) {
        $parts[] = preg_quote(compozitParamNormalizeText($label), '/');
    }
    return implode('|', $parts);
}

function compozitParamExtractVoltage(string $text, array $labels): string
{
    $escaped = compozitParamLabelPattern($labels);
    return compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, ['/(?:' . $escaped . ')[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*v\b/i']),
        compozitParamParseFirstMetric($text, ['/(\d+(?:[.,]\d+)?)\s*v\b[^\n]{0,18}(?:' . $escaped . ')/i']),
    ]);
}

function compozitParamExtractCurrentAmp(string $text, array $labels): string
{
    $escaped = compozitParamLabelPattern($labels);
    $patterns = [
        '/(?:' . $escaped . ')[^\d]{0,14}(\d+(?:[.,]\d+)?)\s*(ma|a)\b/i',
        '/(\d+(?:[.,]\d+)?)\s*(ma|a)\b[^\n]{0,18}(?:' . $escaped . ')/i',
    ];
    $transform = static function (float $value, array $matches): float {
        return strtolower((string) ($matches[2] ?? 'a')) === 'ma' ? $value / 1000 : $value;
    };

    return compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, [$patterns[0]], $transform),
        compozitParamParseFirstMetric($text, [$patterns[1]], $transform),
    ]);
}

function compozitParamExtractCurrentMicro(string $text, array $labels): string
{
    $escaped = compozitParamLabelPattern($labels);
    $patterns = [
        '/(?:' . $escaped . ')[^\d]{0,14}(\d+(?:[.,]\d+)?)\s*(ua|µa|ma)\b/i',
        '/(\d+(?:[.,]\d+)?)\s*(ua|µa|ma)\b[^\n]{0,18}(?:' . $escaped . ')/i',
    ];
    $transform = static function (float $value, array $matches): float {
        return strtolower((string) ($matches[2] ?? 'ua')) === 'ma' ? $value * 1000 : $value;
    };

    return compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, [$patterns[0]], $transform),
        compozitParamParseFirstMetric($text, [$patterns[1]], $transform),
    ]);
}

function compozitParamExtractPowerW(string $text, array $labels): string
{
    $escaped = compozitParamLabelPattern($labels);
    $patterns = [
        '/(?:' . $escaped . ')[^\d]{0,14}(\d+(?:[.,]\d+)?)\s*(mw|w)\b/i',
        '/(\d+(?:[.,]\d+)?)\s*(mw|w)\b[^\n]{0,18}(?:' . $escaped . ')/i',
    ];
    $transform = static function (float $value, array $matches): float {
        return strtolower((string) ($matches[2] ?? 'w')) === 'mw' ? $value / 1000 : $value;
    };

    return compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, [$patterns[0]], $transform),
        compozitParamParseFirstMetric($text, [$patterns[1]], $transform),
    ]);
}

function compozitParamExtractTolerance(string $text): string
{
    return compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, ['/(?:tolerance|tol)[^\d]{0,10}(\d+(?:[.,]\d+)?)\s*%/i']),
        compozitParamParseFirstMetric($text, ['/(?:\+\/-|±)\s*(\d+(?:[.,]\d+)?)\s*%/i']),
        compozitParamParseFirstMetric($text, ['/(\d+(?:[.,]\d+)?)\s*%/i']),
    ]);
}

function compozitParamExtractVf(string $text): string
{
    return compozitParamFirstNonEmpty([
        compozitParamExtractVoltage($text, ['vf', 'v_f', 'forward voltage', 'forward volt', 'voltage drop', 'dropout']),
        compozitParamParseFirstMetric($text, ['/\b(\d+(?:[.,]\d+)?)\s*v\b[^\n]{0,22}(?:at|@)\s*\d/i']),
    ]);
}

function compozitParamExtractIfsm(string $text): string
{
    return compozitParamFirstNonEmpty([
        compozitParamExtractCurrentAmp($text, ['ifsm', 'i_fsm', 'forward surge current', 'surge current', 'non repetitive surge', 'peak forward surge current']),
        compozitParamParseFirstMetric(
            $text,
            ['/\b(\d+(?:[.,]\d+)?)\s*(ma|a)\b[^\n]{0,28}(?:ifsm|surge)/i'],
            static function (float $value, array $matches): float {
                return strtolower((string) ($matches[2] ?? 'a')) === 'ma' ? $value / 1000 : $value;
            }
        ),
    ]);
}

function compozitImportNormalizeUnit(string $unit): string
{
    $unit = trim($unit);
    if ($unit === '') {
        return '';
    }

    $unit = str_replace(['µ', 'μ'], 'u', $unit);
    $unit = str_replace(['Ω', 'Ω', 'ω'], 'ohm', $unit);
    $unit = str_replace(['°C', 'ºC', '°c', 'ºc'], 'C', $unit);
    $unit = strtolower($unit);
    $unit = str_replace([' ', "\t", "\r", "\n"], '', $unit);
    $unit = str_replace(['ohms', 'ohm.', 'kohms', 'kohm.', 'mohms', 'mohm.', 'megohms'], ['ohm', 'ohm', 'kohm', 'kohm', 'mohm', 'mohm', 'megohm'], $unit);

    return $unit;
}

function compozitImportUnitMeta(string $unit): ?array
{
    static $meta = [
        'uv' => ['dimension' => 'voltage', 'factor' => 1e-6],
        'mv' => ['dimension' => 'voltage', 'factor' => 1e-3],
        'v' => ['dimension' => 'voltage', 'factor' => 1],
        'kv' => ['dimension' => 'voltage', 'factor' => 1e3],
        'ua' => ['dimension' => 'current', 'factor' => 1e-6],
        'ma' => ['dimension' => 'current', 'factor' => 1e-3],
        'a' => ['dimension' => 'current', 'factor' => 1],
        'ka' => ['dimension' => 'current', 'factor' => 1e3],
        'uw' => ['dimension' => 'power', 'factor' => 1e-6],
        'mw' => ['dimension' => 'power', 'factor' => 1e-3],
        'w' => ['dimension' => 'power', 'factor' => 1],
        'kw' => ['dimension' => 'power', 'factor' => 1e3],
        'hz' => ['dimension' => 'frequency', 'factor' => 1],
        'khz' => ['dimension' => 'frequency', 'factor' => 1e3],
        'mhz' => ['dimension' => 'frequency', 'factor' => 1e6],
        'ghz' => ['dimension' => 'frequency', 'factor' => 1e9],
        'pf' => ['dimension' => 'capacitance', 'factor' => 1e-12],
        'nf' => ['dimension' => 'capacitance', 'factor' => 1e-9],
        'uf' => ['dimension' => 'capacitance', 'factor' => 1e-6],
        'mf' => ['dimension' => 'capacitance', 'factor' => 1e-3],
        'f' => ['dimension' => 'capacitance', 'factor' => 1],
        'nh' => ['dimension' => 'inductance', 'factor' => 1e-9],
        'uh' => ['dimension' => 'inductance', 'factor' => 1e-6],
        'mh' => ['dimension' => 'inductance', 'factor' => 1e-3],
        'h' => ['dimension' => 'inductance', 'factor' => 1],
        'uohm' => ['dimension' => 'resistance', 'factor' => 1e-6],
        'mohm' => ['dimension' => 'resistance', 'factor' => 1e-3],
        'ohm' => ['dimension' => 'resistance', 'factor' => 1],
        'kohm' => ['dimension' => 'resistance', 'factor' => 1e3],
        'megohm' => ['dimension' => 'resistance', 'factor' => 1e6],
        'ns' => ['dimension' => 'time', 'factor' => 1e-9],
        'us' => ['dimension' => 'time', 'factor' => 1e-6],
        'ms' => ['dimension' => 'time', 'factor' => 1e-3],
        's' => ['dimension' => 'time', 'factor' => 1],
        'nm' => ['dimension' => 'length', 'factor' => 1e-9],
        'um' => ['dimension' => 'length', 'factor' => 1e-6],
        'mm' => ['dimension' => 'length', 'factor' => 1e-3],
        'cm' => ['dimension' => 'length', 'factor' => 1e-2],
        'm' => ['dimension' => 'length', 'factor' => 1],
        'in' => ['dimension' => 'length', 'factor' => 0.0254],
        'kb' => ['dimension' => 'memory', 'factor' => 1024],
        'mb' => ['dimension' => 'memory', 'factor' => 1024 * 1024],
        'gb' => ['dimension' => 'memory', 'factor' => 1024 * 1024 * 1024],
        '%' => ['dimension' => 'percent', 'factor' => 1],
        'ppm' => ['dimension' => 'ppm', 'factor' => 1],
        'g' => ['dimension' => 'g', 'factor' => 1],
        'nm' => ['dimension' => 'length', 'factor' => 1e-9],
        'lm' => ['dimension' => 'lumen', 'factor' => 1],
        'cd' => ['dimension' => 'candela', 'factor' => 1],
        'mcd' => ['dimension' => 'candela', 'factor' => 1e-3],
        'jc' => ['dimension' => 'thermal', 'factor' => 1],
    ];

    return $meta[$unit] ?? null;
}

function compozitImportConvertUnitValue(float $value, string $sourceUnit, ?string $targetUnit): ?string
{
    $source = compozitImportNormalizeUnit($sourceUnit);
    $target = compozitImportNormalizeUnit((string) $targetUnit);

    if ($target === '') {
        return compozitParamNumber($value);
    }

    if ($source === $target) {
        return compozitParamNumber($value);
    }

    $sourceMeta = compozitImportUnitMeta($source);
    $targetMeta = compozitImportUnitMeta($target);

    if ($sourceMeta === null || $targetMeta === null || $sourceMeta['dimension'] !== $targetMeta['dimension']) {
        return null;
    }

    return compozitParamNumber(($value * $sourceMeta['factor']) / $targetMeta['factor']);
}

function compozitImportDefinitionAliases(array $definition): array
{
    $sourceValues = [
        (string) ($definition['label'] ?? ''),
        str_replace('_', ' ', (string) ($definition['param_key'] ?? '')),
        str_replace('_', '', (string) ($definition['param_key'] ?? '')),
    ];

    $aliases = [];
    foreach ($sourceValues as $value) {
        $normalized = preg_replace('/\s+/', ' ', trim(compozitParamNormalizeText($value)));
        if ($normalized === '' || $normalized === null) {
            continue;
        }

        foreach ([
            $normalized,
            str_replace(['(', ')', '.', ','], ' ', $normalized),
            str_replace(['(', ')', '.', ',', ' ', '-', '/'], '', $normalized),
            str_replace(['(', ')', '.', ',', '-'], ' ', $normalized),
        ] as $variant) {
            $variant = preg_replace('/\s+/', ' ', trim((string) $variant));
            if ($variant !== '') {
                $aliases[$variant] = true;
            }
        }
    }

    $list = array_keys($aliases);
    usort($list, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });

    return $list;
}

function compozitImportAliasPattern(array $definition): string
{
    $parts = [];
    foreach (compozitImportDefinitionAliases($definition) as $alias) {
        $parts[] = preg_quote($alias, '/');
    }
    return implode('|', $parts);
}

function compozitImportUnitTokens(?string $unit): array
{
    $target = compozitImportNormalizeUnit((string) $unit);
    if ($target === '') {
        return [];
    }

    $groups = [
        'v' => ['uv', 'mv', 'v', 'kv'],
        'a' => ['ua', 'ma', 'a', 'ka'],
        'w' => ['uw', 'mw', 'w', 'kw'],
        'hz' => ['hz', 'khz', 'mhz', 'ghz'],
        'f' => ['pf', 'nf', 'uf', 'mf', 'f'],
        'h' => ['nh', 'uh', 'mh', 'h'],
        'ohm' => ['uohm', 'mohm', 'ohm', 'kohm', 'megohm'],
        's' => ['ns', 'us', 'ms', 's'],
        'm' => ['nm', 'um', 'mm', 'cm', 'm', 'in'],
        'mm' => ['nm', 'um', 'mm', 'cm', 'm', 'in'],
        'nm' => ['nm', 'um', 'mm', 'cm', 'm'],
        'kb' => ['kb', 'mb', 'gb'],
    ];

    if (isset($groups[$target])) {
        return $groups[$target];
    }

    foreach ($groups as $tokens) {
        if (in_array($target, $tokens, true)) {
            return $tokens;
        }
    }

    return [$target];
}

function compozitImportExtractNumberByDefinition(string $text, array $definition): string
{
    $aliasPattern = compozitImportAliasPattern($definition);
    if ($aliasPattern === '') {
        return '';
    }

    $patterns = [];
    $targetUnit = (string) ($definition['unit'] ?? '');
    $unitTokens = compozitImportUnitTokens($targetUnit);

    if ($unitTokens) {
        $unitPattern = implode('|', array_map(static function (string $token): string {
            return preg_quote($token, '/');
        }, $unitTokens));

        $patterns[] = '/(?:' . $aliasPattern . ')[^a-z0-9]{0,10}([-+]?\d+(?:[.,]\d+)?)\s*(' . $unitPattern . ')\b/i';
        $patterns[] = '/([-+]?\d+(?:[.,]\d+)?)\s*(' . $unitPattern . ')\b[^\n,;|]{0,20}(?:' . $aliasPattern . ')\b/i';

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = (float) str_replace(',', '.', (string) $matches[1]);
                $converted = compozitImportConvertUnitValue($value, (string) $matches[2], $targetUnit);
                if ($converted !== null && $converted !== '') {
                    return $converted;
                }
            }
        }

        // Repli « unité nue » : si aucun alias n'est trouvé à proximité, on cherche
        // quand même la première occurrence d'un nombre suivi de l'unité attendue
        // (ex. « 2.7-5.5V » donne 5.5 V, « 55ns » donne 55 ns). Utile quand le
        // commentaire contient les caractéristiques sans les libeller explicitement.
        // Le signe +/- n'est pas capté ici pour éviter de confondre le séparateur
        // d'une fourchette (« 2.7-5.5 V ») avec une valeur négative.
        $barePattern = '/(?<![a-z0-9])(\d+(?:[.,]\d+)?)\s*(' . $unitPattern . ')\b/i';
        if (preg_match_all($barePattern, $text, $allMatches, PREG_SET_ORDER) > 0) {
            $candidates = [];
            foreach ($allMatches as $match) {
                $value = (float) str_replace(',', '.', (string) $match[1]);
                $converted = compozitImportConvertUnitValue($value, (string) $match[2], $targetUnit);
                if ($converted !== null && $converted !== '') {
                    $candidates[] = $converted;
                }
            }
            if ($candidates) {
                // En cas de fourchette (ex. « 2.7-5.5 V »), on retient la valeur
                // la plus élevée (tension max), sinon la première trouvée.
                return count($candidates) > 1 ? max($candidates) : $candidates[0];
            }
        }
    }

    $plainPatterns = [
        '/(?:' . $aliasPattern . ')[^a-z0-9]{0,10}([-+]?\d+(?:[.,]\d+)?)/i',
        '/([-+]?\d+(?:[.,]\d+)?)[^\n,;|]{0,20}(?:' . $aliasPattern . ')\b/i',
    ];

    foreach ($plainPatterns as $pattern) {
        if (preg_match($pattern, $text, $matches) === 1) {
            return compozitParamNumber((float) str_replace(',', '.', (string) $matches[1]));
        }
    }

    return '';
}

function compozitImportExtractTextByDefinition(string $text, array $definition): string
{
    $aliasPattern = compozitImportAliasPattern($definition);
    if ($aliasPattern === '') {
        return '';
    }

    $patterns = [
        '/(?:' . $aliasPattern . ')\s*(?:\:|=|->|-)\s*([^\n,;|]{1,40})/i',
        '/(?:' . $aliasPattern . ')[^a-z0-9]{1,6}([a-z0-9][^\n,;|]{1,32})/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches) !== 1) {
            continue;
        }

        $value = trim((string) $matches[1]);
        $value = trim($value, " .:-");
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function compozitImportParseGenericParamsFromText(string $text, array $definitions): array
{
    if ($text === '' || !$definitions) {
        return [];
    }

    $values = [];
    foreach ($definitions as $key => $definition) {
        $value = (($definition['value_type'] ?? 'text') === 'number')
            ? compozitImportExtractNumberByDefinition($text, $definition)
            : compozitImportExtractTextByDefinition($text, $definition);

        if ($value !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

function compozitImportParseDiodeParamsFromText(string $text, int $subcategoryId): array
{
    if ($text === '') {
        return [];
    }

    $values = [];

    if ($subcategoryId !== 406) {
        if (preg_match('/\bsimple\b/i', $text) === 1) {
            $values['polarity'] = 'Simple';
        } elseif (preg_match('/\b(double|dual)\b/i', $text) === 1) {
            $values['polarity'] = 'Double';
        }
    }

    $voltage = compozitParamFirstNonEmpty([
        compozitParamExtractVoltage($text, ['vrrm', 'vr']),
        compozitParamParseFirstMetric($text, ['/(\d+(?:[.,]\d+)?)\s*v\b/i']),
    ]);
    $currentA = compozitParamFirstNonEmpty([
        compozitParamExtractCurrentAmp($text, ['if(av)', 'if avg', 'if average', 'average forward current', 'forward current', 'io']),
        compozitParamParseFirstMetric(
            $text,
            ['/(\d+(?:[.,]\d+)?)\s*(ma|a)\b/i'],
            static function (float $value, array $matches): float {
                return strtolower((string) ($matches[2] ?? 'a')) === 'ma' ? $value / 1000 : $value;
            }
        ),
    ]);
    $powerW = compozitParamFirstNonEmpty([
        compozitParamExtractPowerW($text, ['power dissipation', 'pd', 'power']),
        compozitParamParseFirstMetric(
            $text,
            ['/(\d+(?:[.,]\d+)?)\s*(mw|w)\b/i'],
            static function (float $value, array $matches): float {
                return strtolower((string) ($matches[2] ?? 'w')) === 'mw' ? $value / 1000 : $value;
            }
        ),
    ]);
    $tolerance = compozitParamExtractTolerance($text);
    $timeNs = compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, ['/(?:trr|reverse recovery|recovery time)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*ns\b/i']),
        compozitParamParseFirstMetric($text, ['/(\d+(?:[.,]\d+)?)\s*ns\b/i']),
    ]);
    $capPf = compozitParamFirstNonEmpty([
        compozitParamParseFirstMetric($text, ['/(?:cj|junction capacitance|capacitance)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*pf\b/i']),
        compozitParamParseFirstMetric($text, ['/(\d+(?:[.,]\d+)?)\s*pf\b/i']),
    ]);
    $currentUa = compozitParamExtractCurrentMicro($text, ['ir', 'reverse current', 'leakage current']);
    $vf = compozitParamExtractVf($text);
    $ifsm = compozitParamExtractIfsm($text);

    if ($subcategoryId === 404) {
        $values['vz'] = $voltage;
        $values['tolerance'] = $tolerance;
        $values['power'] = $powerW;
    } elseif ($subcategoryId === 402) {
        $values['vr'] = $voltage;
        $values['if_avg'] = $currentA;
        $values['vf'] = $vf;
        $values['ir'] = $currentUa;
    } elseif ($subcategoryId === 403) {
        $values['vr'] = $voltage;
        $values['if_avg'] = $currentA;
        $values['trr'] = $timeNs;
        $values['cj'] = $capPf;
    } elseif ($subcategoryId === 406) {
        $values['vrrm'] = $voltage;
        $values['if_av'] = $currentA;
        $values['ifsm'] = $ifsm;
        if (preg_match('/3\s*-?\s*phase|triphase/i', $text) === 1) {
            $values['phases'] = '3';
        } elseif (preg_match('/1\s*-?\s*phase|monophase/i', $text) === 1) {
            $values['phases'] = '1';
        }
    } elseif ($subcategoryId === 401) {
        $values['vrrm'] = $voltage;
        $values['if_avg'] = $currentA;
        $values['ifsm'] = $ifsm;
        $values['vf'] = $vf;
        if (preg_match('/ultra\s*fast/i', $text) === 1) {
            $values['recovery'] = 'Ultra-fast';
        } elseif (preg_match('/\bfast\b/i', $text) === 1) {
            $values['recovery'] = 'Fast';
        } elseif (preg_match('/standard/i', $text) === 1) {
            $values['recovery'] = 'Standard';
        }
    }

    return array_filter($values, static function ($value): bool {
        return trim((string) $value) !== '';
    });
}

function compozitImportParseParamsFromText(string $text, int $subcategoryId, array $definitions): array
{
    $generic = compozitImportParseGenericParamsFromText($text, $definitions);
    $diode = compozitImportParseDiodeParamsFromText($text, $subcategoryId);
    return $diode + $generic;
}

function compozitImportPartText(array $part): string
{
    $chunks = [
        $part['category'] ?? '',
        $part['description'] ?? '',
        $part['spec_text'] ?? '',
        $part['mpn'] ?? '',
        $part['package'] ?? '',
        $part['datasheet'] ?? '',
    ];

    if (!empty($part['best_offer']) && is_array($part['best_offer'])) {
        $chunks[] = $part['best_offer']['supplier_ref'] ?? '';
        $chunks[] = $part['best_offer']['supplier_name'] ?? '';
    }

    return compozitParamNormalizeText(implode(' ', array_filter($chunks, static function ($value): bool {
        return trim((string) $value) !== '';
    })));
}

function compozitImportComponentText(array $component): string
{
    return compozitParamNormalizeText(implode(' ', array_filter([
        $component['category_name'] ?? '',
        $component['subcategory_name'] ?? '',
        $component['name'] ?? '',
        $component['manufacturer'] ?? '',
        $component['package'] ?? '',
        $component['comment'] ?? '',
        $component['supplier_reference'] ?? '',
        $component['datasheet'] ?? '',
    ], static function ($value): bool {
        return trim((string) $value) !== '';
    })));
}

function compozitImportPreferredSources(?string $supplierName): array
{
    $name = compozitParamNormalizeText((string) $supplierName);

    if ($name !== '') {
        if (strpos($name, 'mouser') !== false) {
            return ['mouser'];
        }
        if (strpos($name, 'farnell') !== false || strpos($name, 'newark') !== false || strpos($name, 'element14') !== false) {
            return ['farnell'];
        }
        if (preg_match('/\btme\b/i', $name) === 1) {
            return ['tme'];
        }
        if (strpos($name, 'lcsc') !== false) {
            return ['lcsc'];
        }
        if (preg_match('/\brs\b/i', $name) === 1 || strpos($name, 'radios') !== false) {
            return ['rs'];
        }
        if (strpos($name, 'digikey') !== false) {
            return ['digikey'];
        }
        if (strpos($name, 'nexar') !== false || strpos($name, 'octopart') !== false) {
            return ['nexar'];
        }
    }

    return ['tme', 'lcsc', 'mouser', 'farnell', 'rs', 'digikey'];
}

function compozitImportSearchSource(string $source, string $query): array
{
    switch ($source) {
        case 'mouser':
            return mouserSearchParts($query, 3);
        case 'farnell':
            return farnellSearchParts($query, 3);
        case 'lcsc':
            return lcscSearchParts($query, 3);
        case 'tme':
            return tmeSearchParts($query, 3);
        case 'rs':
            return function_exists('rsSearchParts') ? rsSearchParts($query, 3) : ['ok' => false, 'message' => 'RS indisponible.'];
        case 'digikey':
            return function_exists('digikeySearchParts') ? digikeySearchParts($query, 3) : ['ok' => false, 'message' => 'DigiKey indisponible.'];
        case 'nexar':
            return function_exists('nexarSearchParts') ? nexarSearchParts($query, 3) : ['ok' => false, 'message' => 'Nexar indisponible.'];
        default:
            return ['ok' => false, 'message' => 'Source inconnue.'];
    }
}

/* Préfixes de référence fournisseur fréquemment notés dans le commentaire
   d'un composant : « FA : ... » (Farnell), « MO : ... » (Mouser), « RS ... »,
   « DK ... » (DigiKey), etc. Cette table fait le lien préfixe -> API. */
function compozitImportCommentRefSourceMap(): array
{
    return [
        'FA' => 'farnell', 'FARNELL' => 'farnell', 'E14' => 'farnell', 'NEWARK' => 'farnell',
        'MO' => 'mouser', 'MOU' => 'mouser', 'MOUSER' => 'mouser',
        'RS' => 'rs', 'RSWWW' => 'rs', 'RSONLINE' => 'rs', 'RADIOSPARES' => 'rs',
        'TME' => 'tme',
        'LCSC' => 'lcsc',
        'DK' => 'digikey', 'DIGIKEY' => 'digikey',
        'NX' => 'nexar', 'NEXAR' => 'nexar', 'OCTOPART' => 'nexar',
    ];
}

/**
 * Extrait du commentaire les références fournisseur du type « FA : 123456 ».
 *
 * @return array<int, array{source:string, ref:string}>
 */
function compozitImportCommentRefs(?string $comment): array
{
    $comment = trim((string) $comment);
    if ($comment === '') {
        return [];
    }

    $map = compozitImportCommentRefSourceMap();
    $prefixes = array_keys($map);
    usort($prefixes, static function ($a, $b) {
        return strlen($b) - strlen($a);
    });
    $pattern = '/\b(' . implode('|', array_map('preg_quote', $prefixes)) . ')\s*[:#=\-]?\s*([A-Za-z0-9][A-Za-z0-9\-\.\/]{2,})\b/i';

    if (preg_match_all($pattern, $comment, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $refs = [];
    $seen = [];
    foreach ($matches as $match) {
        $source = $map[strtoupper($match[1])] ?? null;
        $ref = trim((string) $match[2]);
        if ($source === null || $ref === '') {
            continue;
        }
        $key = $source . '|' . strtolower($ref);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $refs[] = ['source' => $source, 'ref' => $ref];
    }

    return $refs;
}

function compozitImportSelectPart(array $results, string $query): ?array
{
    if (!$results) {
        return null;
    }

    $queryNormalized = compozitParamNormalizeRef($query);
    foreach ($results as $part) {
        $mpn = compozitParamNormalizeRef((string) ($part['mpn'] ?? ''));
        if ($queryNormalized !== '' && $mpn !== '' && $mpn === $queryNormalized) {
            return $part;
        }
    }

    return is_array($results[0] ?? null) ? $results[0] : null;
}

function compozitImportFindPartForComponent(array $component): ?array
{
    // 1. Référence fournisseur déduite du commentaire (« FA : ... », « MO : ... »).
    $commentRefs = compozitImportCommentRefs($component['comment'] ?? '');

    // 2. Recherches génériques de repli (référence enregistrée, nom, fabricant + nom).
    $genericQueries = [];
    foreach ([
        trim((string) ($component['supplier_reference'] ?? '')),
        trim((string) ($component['name'] ?? '')),
        trim((string) (($component['manufacturer'] ?? '') . ' ' . ($component['name'] ?? ''))),
    ] as $query) {
        if ($query === '' || mb_strlen($query, 'UTF-8') < 2 || in_array($query, $genericQueries, true)) {
            continue;
        }
        $genericQueries[] = $query;
    }

    $preferred = compozitImportPreferredSources($component['supplier_name'] ?? '');

    $attempts = [];
    $seen = [];
    $add = function (string $source, string $query) use (&$attempts, &$seen) {
        $query = trim($query);
        if ($source === '' || $query === '' || mb_strlen($query, 'UTF-8') < 2) {
            return;
        }
        $key = $source . '|' . compozitParamNormalizeRef($query);
        if ($key === $source . '|' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $attempts[] = [$source, $query];
    };

    foreach ($commentRefs as $ref) {
        $add($ref['source'], $ref['ref']);
    }
    foreach ($preferred as $source) {
        foreach ($genericQueries as $query) {
            $add($source, $query);
        }
    }

    foreach ($attempts as $attempt) {
        $result = compozitImportSearchSource($attempt[0], $attempt[1]);
        if (empty($result['ok']) || empty($result['results']) || !is_array($result['results'])) {
            continue;
        }

        $part = compozitImportSelectPart($result['results'], $attempt[1]);
        if ($part !== null) {
            return $part;
        }
    }

    return null;
}

function compozitImportParamDefinitions(PDO $pdo, int $subcategoryId): array
{
    static $cache = [];

    if (!isset($cache[$subcategoryId])) {
        $rows = compozitGetParamDefinitions($pdo, $subcategoryId);
        $cache[$subcategoryId] = [];
        foreach ($rows as $row) {
            $cache[$subcategoryId][$row['param_key']] = $row;
        }
    }

    return $cache[$subcategoryId];
}

function compozitImportSaveMissingParams(PDO $pdo, int $dataId, int $subcategoryId, array $values): int
{
    $definitions = compozitImportParamDefinitions($pdo, $subcategoryId);
    if (!$definitions || !$values) {
        return 0;
    }

    $existing = compozitGetComponentParamValues($pdo, $dataId);
    $upsert = $pdo->prepare("
        INSERT INTO `data_params` (`data_id`, `param_id`, `value_num`, `value_txt`)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `value_num` = IFNULL(`data_params`.`value_num`, VALUES(`value_num`)),
            `value_txt` = IFNULL(`data_params`.`value_txt`, VALUES(`value_txt`))
    ");

    $saved = 0;
    foreach ($values as $key => $rawValue) {
        if (!isset($definitions[$key])) {
            continue;
        }

        $current = trim((string) ($existing[$key] ?? ''));
        if ($current !== '') {
            continue;
        }

        $definition = $definitions[$key];
        if (($definition['value_type'] ?? 'text') === 'number') {
            $value = trim(str_replace(',', '.', (string) $rawValue));
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            $upsert->execute([$dataId, (int) $definition['id'], (float) $value, null]);
        } else {
            $value = trim((string) $rawValue);
            if ($value === '') {
                continue;
            }
            $upsert->execute([$dataId, (int) $definition['id'], null, $value]);
        }

        $saved++;
    }

    return $saved;
}

/**
 * Enrichit un lot de composants : chaque paramètre manquant est recherché dans
 * le commentaire, la référence fournisseur, le nom puis les API ; les valeurs
 * déjà présentes ne sont jamais écrasées.
 *
 * @return array{checked:int,enriched:int,paramsAdded:int,notFound:int,withoutDefinitions:int,retryIds:int[]}
 */
function compozitEnrichParamsBatch(PDO $pdo, int $owner, array $idsBatch): array
{
    $stats = [
        'checked' => 0,
        'enriched' => 0,
        'paramsAdded' => 0,
        'notFound' => 0,
        'withoutDefinitions' => 0,
        'retryIds' => [],
    ];

    $idsBatch = array_values(array_unique(array_filter(array_map('intval', $idsBatch))));
    if (!$idsBatch) {
        return $stats;
    }

    compozitEnsureParamTables($pdo);

    $placeholders = implode(',', array_fill(0, count($idsBatch), '?'));
    $sql = "
        SELECT d.*, cs.name AS subcategory_name, ch.name AS category_name, s.name AS supplier_name
        FROM `data` d
        LEFT JOIN `category_sub` cs ON cs.id = d.category
        LEFT JOIN `category_head` ch ON ch.id = cs.category_head_id
        LEFT JOIN `suppliers` s ON s.id = d.supplier_id
        WHERE d.owner = ? AND d.id IN ($placeholders)
        ORDER BY d.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$owner], $idsBatch));
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($components as $component) {
        $stats['checked']++;
        $subcategoryId = (int) ($component['category'] ?? 0);

        $definitions = compozitImportParamDefinitions($pdo, $subcategoryId);
        if (!$definitions) {
            $stats['withoutDefinitions']++;
            continue;
        }

        $existing = compozitGetComponentParamValues($pdo, (int) $component['id']);
        $missingKeys = [];
        foreach ($definitions as $key => $definition) {
            if (trim((string) ($existing[$key] ?? '')) === '') {
                $missingKeys[$key] = true;
            }
        }

        if (!$missingKeys) {
            continue;
        }

        $foundValues = array_intersect_key(
            compozitImportParseParamsFromText(compozitImportComponentText($component), $subcategoryId, $definitions),
            $missingKeys
        );

        if (count($foundValues) < count($missingKeys)) {
            $part = compozitImportFindPartForComponent($component);
            if ($part !== null) {
                $apiValues = array_intersect_key(
                    compozitImportParseParamsFromText(compozitImportPartText($part), $subcategoryId, $definitions),
                    $missingKeys
                );
                $foundValues = $foundValues + $apiValues;
            }
        }

        $saved = compozitImportSaveMissingParams($pdo, (int) $component['id'], $subcategoryId, $foundValues);
        if ($saved > 0) {
            $stats['enriched']++;
            $stats['paramsAdded'] += $saved;
        }

        if (count($foundValues) < count($missingKeys)) {
            $stats['retryIds'][] = (int) $component['id'];
            $stats['notFound']++;
        }
    }

    return $stats;
}

/* =====================================================================
   Telechargement des fichiers (sauvegarde SQL et export CSV)
   ===================================================================== */

if (isset($_GET['download'])) {
    // Rien ne doit etre affiche avant les en-tetes du fichier telecharge.
    @ini_set('display_errors', '0');
    @set_time_limit(300);

    $kind = (string) $_GET['download'];

    try {
        $pdo = getConnection();

        /* Les dates sont exportees en UTC et la sauvegarde contient la meme
           consigne : la restauration redonne exactement les memes instants,
           quel que soit le fuseau du serveur de destination. */
        $pdo->exec("SET time_zone = '+00:00'");

        if ($kind === 'csv') {
            $dump = compozitCsvDump($pdo, (int) $_SESSION['user_id']);
        } else {
            $dump = compozitBackupDump($pdo, DB_NAME);
        }
    } catch (Throwable $e) {
        header('Location: import_database.php?error=' . urlencode($e->getMessage()));
        exit();
    }

    if ($kind === 'csv') {
        $filename = 'compozit_composants_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
    } else {
        $filename = 'compozit_' . date('Y-m-d_H-i-s') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dump));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $dump;
    exit();
}

/* =====================================================================
   Traitement des formulaires (restauration SQL et import CSV)
   ===================================================================== */

$message = '';
$error   = '';
$showLocationPreview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!isset($_FILES['sql_file'])) {
        $error = "Aucun fichier recu (limite d'envoi du serveur depassee ?).";
    } elseif ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        switch ($_FILES['sql_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = "Fichier trop volumineux pour le serveur ("
                       . ini_get('upload_max_filesize') . " maximum). "
                       . "Utilise phpMyAdmin pour un fichier plus gros.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $error = "Aucun fichier selectionne.";
                break;
            default:
                $error = "L'envoi du fichier a echoue (code "
                       . (int) $_FILES['sql_file']['error'] . ").";
        }
    } elseif ($_FILES['sql_file']['size'] > COMPOZIT_IMPORT_MAX_SIZE) {
        $error = "Fichier trop volumineux (20 Mo maximum).";
    } else {
        $content = file_get_contents($_FILES['sql_file']['tmp_name']);

        if ($content === false || trim($content) === '') {
            $error = "Le fichier est vide ou illisible.";
        } else {
            // Retirer un eventuel BOM UTF-8 en tete de fichier.
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }

            $statements = compozitSplitSql($content);

            if (!$statements) {
                $error = "Aucune requete SQL trouvee dans le fichier.";
            } else {
                @set_time_limit(300);
                @ini_set('memory_limit', '256M');

                $executed = 0;
                $ignored  = 0;
                $started  = microtime(true);

                try {
                    $pdo = getConnection();

                    /* Desactivation temporaire des controles de cles
                       etrangeres : l'ordre des tables du fichier importe
                       n'a alors plus d'importance. */
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

                    try {
                        foreach ($statements as $statement) {
                            if (compozitSkipStatement($statement)) {
                                $ignored++;
                                continue;
                            }
                            $pdo->exec($statement);
                            $executed++;
                        }
                    } finally {
                        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    }

                    $seconds = number_format(microtime(true) - $started, 1, ',', ' ');
                    $message = "Restauration terminee : {$executed} requetes executees"
                             . " en {$seconds} s.";
                    if ($ignored > 0) {
                        $message .= " ({$ignored} requete(s) de configuration du "
                                   . "serveur ignoree(s).)";
                    }
                } catch (PDOException $e) {
                    $error = "Echec a la requete n°" . ($executed + 1)
                           . " : " . $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (!isset($_FILES['csv_file'])) {
        $error = "Aucun fichier recu (limite d'envoi du serveur depassee ?).";
    } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        switch ($_FILES['csv_file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error = "Fichier trop volumineux pour le serveur ("
                       . ini_get('upload_max_filesize') . " maximum).";
                break;
            case UPLOAD_ERR_NO_FILE:
                $error = "Aucun fichier selectionne.";
                break;
            default:
                $error = "L'envoi du fichier a echoue (code "
                       . (int) $_FILES['csv_file']['error'] . ").";
        }
    } elseif ($_FILES['csv_file']['size'] > COMPOZIT_IMPORT_MAX_SIZE) {
        $error = "Fichier trop volumineux (20 Mo maximum).";
    } else {
        $content = file_get_contents($_FILES['csv_file']['tmp_name']);

        if ($content === false || trim($content) === '') {
            $error = "Le fichier est vide ou illisible.";
        } else {
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }

            try {
                [$rows, $separator, $headers] = compozitParseCsv($content);
            } catch (Throwable $e) {
                $rows = [];
                $separator = ';';
                $headers = [];
                $error = $e->getMessage();
            }

            if ($error === '' && !$rows) {
                $error = "Aucune ligne de composant lisible dans le fichier CSV.";
            }

            if ($error === '' && !in_array('name', $headers, true) && !in_array('id', $headers, true)) {
                $error = "En-tete CSV invalide : il faut au minimum les colonnes id ou name.";
            }

            if ($error === '') {
                $owner = (int) $_SESSION['user_id'];

                // --- Étape 1 : détecter les emplacements qui seront créés ---
                $locationLabels = [];
                foreach ($rows as $row) {
                    $rec = compozitCsvRecord($row, $owner, []);
                    if (!empty($rec['__location_label'])) {
                        $locationLabels[$rec['__location_label']] = true;
                    }
                }
                $locationLabels = array_keys($locationLabels);

                $newLocations = [];
                if ($locationLabels) {
                    try {
                        $pdo = getConnection();
                        // On ne crée rien ici, on vérifie juste l'existence
                        $sel = $pdo->prepare("
                            SELECT CASE
                                WHEN storage_type IN ('boite', 'classeur', 'etagere')
                                    THEN CONCAT(casier, '-', compartiment)
                                ELSE CONCAT(casier, tiroir, '-', compartiment)
                            END AS lbl
                            FROM location
                            WHERE owner = ?
                        ");
                        $sel->execute([$owner]);
                        $existing = array_flip($sel->fetchAll(PDO::FETCH_COLUMN));
                        foreach ($locationLabels as $lbl) {
                            if (isset($existing[$lbl])) {
                                continue;
                            }
                            $parsed = compozitParseLocationLabel($lbl);
                            if ($parsed === null) continue;
                            $norm = $parsed['casier'] . $parsed['tiroir'] . '-' . $parsed['compartiment'];
                            if (!isset($existing[$norm])) {
                                $newLocations[$lbl] = $lbl;
                            }
                        }
                    } catch (Throwable $e) {
                        // si la table location n'existe pas, on considère tout comme nouveau
                        foreach ($locationLabels as $lbl) {
                            $newLocations[$lbl] = $lbl;
                        }
                    }
                }

                if ($newLocations) {
                    // On stocke les lignes parsées en session pour l'étape de confirmation
                    $_SESSION['compozit_pending_csv'] = [
                        'rows' => $rows,
                        'headers' => $headers,
                        'separator' => $separator,
                        'new_locations' => $newLocations,
                    ];
                    $showLocationPreview = true;
                } else {
                    // Aucun nouvel emplacement : import direct
                    @set_time_limit(300);
                    @ini_set('memory_limit', '256M');

                    $inserted = 0;
                    $updated = 0;
                    $skipped = 0;
                    $started = microtime(true);
                    $touchedIds = [];

                    try {
                        $pdo = getConnection();
                        $available = compozitDataColumns($pdo);

                        if (!$available) {
                            throw new RuntimeException("La table des composants est introuvable.");
                        }

                        $pdo->beginTransaction();

                        try {
                            foreach ($rows as $row) {
                                $record = compozitCsvRecord($row, $owner, $available);
                                if (array_key_exists('__location_label', $record)) {
                                    $record['location_id'] = compozitFindOrCreateLocationId($pdo, $owner, $record['__location_label']);
                                }
                                $name = $record['name'] ?? null;
                                $idValue = $record['id'] ?? null;
                                $id = ($idValue !== null && ctype_digit((string) $idValue)) ? (int) $idValue : null;

                                if ($name === null && $id === null) {
                                    $skipped++;
                                    continue;
                                }

                                try {
                                    if ($id !== null && compozitDataIdExists($pdo, $id, $owner)) {
                                        compozitUpdateCsvComponent($pdo, $id, $owner, $record);
                                        $updated++;
                                        $touchedIds[] = $id;
                                    } else {
                                        if ($id !== null && compozitDataIdExists($pdo, $id, null)) {
                                            $record['id'] = null;
                                        }
                                        $touchedIds[] = compozitInsertCsvComponent($pdo, $record);
                                        $inserted++;
                                    }
                                } catch (Throwable $e) {
                                    $line = isset($row['__line']) ? (int) $row['__line'] : 0;
                                    throw new RuntimeException(
                                        "Erreur a la ligne CSV {$line} : " . $e->getMessage(),
                                        0,
                                        $e
                                    );
                                }
                            }

                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $e;
                        }

                        $seconds = number_format(microtime(true) - $started, 1, ',', ' ');
                        $_SESSION['compozit_last_import_ids'] = array_values(array_unique(array_map('intval', $touchedIds)));
                        $sepLabel = $separator === "\t" ? 'tabulation' : $separator;
                        $message = "Import CSV termine : {$inserted} ajoute(s), {$updated} mis a jour, {$skipped} ignore(s)"
                                 . " en {$seconds} s (separateur detecte : {$sepLabel}).";
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_csv_import') {
    $pending = $_SESSION['compozit_pending_csv'] ?? null;

    if (!$pending || empty($pending['rows'])) {
        $error = "Aucun import en attente. Veuillez re-importer votre fichier CSV.";
    } else {
        $rows = $pending['rows'];
        $headers = $pending['headers'];
        $separator = $pending['separator'];

        // Construire la carte nom original -> nom modifie
        $locationMap = [];
        $locationTypeMap = [];
        $storageTypes = getLocationStorageTypes();
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'loc_type_') === 0) {
                $original = substr($key, 9);
                if (isset($storageTypes[(string)$val])) {
                    $locationTypeMap[$original] = (string)$val;
                }
            } elseif (strpos($key, 'loc_') === 0) {
                $original = substr($key, 4);
                $modified = compozitCsvValue((string)$val);
                if ($modified !== null) {
                    $locationMap[$original] = $modified;
                }
            }
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        $owner = (int) $_SESSION['user_id'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $started = microtime(true);
        $touchedIds = [];

        try {
            $pdo = getConnection();
            $available = compozitDataColumns($pdo);

            if (!$available) {
                throw new RuntimeException("La table des composants est introuvable.");
            }

            $pdo->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $record = compozitCsvRecord($row, $owner, $available);
                    if (array_key_exists('__location_label', $record)) {
                        $lbl = $record['__location_label'];
                        // Utiliser le nom modifié si présent, sinon l'original
                        $finalLabel = $locationMap[$lbl] ?? $lbl;
                        $storageType = $locationTypeMap[$lbl] ?? null;
                        $record['location_id'] = compozitFindOrCreateLocationId($pdo, $owner, $finalLabel, $storageType);
                    }
                    $name = $record['name'] ?? null;
                    $idValue = $record['id'] ?? null;
                    $id = ($idValue !== null && ctype_digit((string) $idValue)) ? (int) $idValue : null;

                    if ($name === null && $id === null) {
                        $skipped++;
                        continue;
                    }

                    try {
                        if ($id !== null && compozitDataIdExists($pdo, $id, $owner)) {
                            compozitUpdateCsvComponent($pdo, $id, $owner, $record);
                            $updated++;
                            $touchedIds[] = $id;
                        } else {
                            if ($id !== null && compozitDataIdExists($pdo, $id, null)) {
                                $record['id'] = null;
                            }
                            $touchedIds[] = compozitInsertCsvComponent($pdo, $record);
                            $inserted++;
                        }
                    } catch (Throwable $e) {
                        $line = isset($row['__line']) ? (int) $row['__line'] : 0;
                        throw new RuntimeException(
                            "Erreur a la ligne CSV {$line} : " . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $seconds = number_format(microtime(true) - $started, 1, ',', ' ');
            $_SESSION['compozit_last_import_ids'] = array_values(array_unique(array_map('intval', $touchedIds)));
            unset($_SESSION['compozit_pending_csv']);
            $sepLabel = $separator === "\t" ? 'tabulation' : $separator;
            $createdLocs = count($pending['new_locations']);
            $message = "Import CSV termine : {$inserted} ajoute(s), {$updated} mis a jour, {$skipped} ignore(s)"
                     . " en {$seconds} s ({$createdLocs} emplacement(s) cree(s), separateur : {$sepLabel}).";
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_csv_import') {
    unset($_SESSION['compozit_pending_csv']);
    $message = "Import annule.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enrich_import_params') {
    $ids = array_values(array_unique(array_filter(array_map('intval', $_SESSION['compozit_last_import_ids'] ?? []))));

    if (!$ids) {
        $error = "Aucune liste importée récente à enrichir.";
    } else {
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        $owner = (int) $_SESSION['user_id'];
        $idsBatch = array_slice($ids, 0, COMPOZIT_PARAM_ENRICH_BATCH);
        $remaining = array_slice($ids, COMPOZIT_PARAM_ENRICH_BATCH);
        $started = microtime(true);

        try {
            $pdo = getConnection();
            $stats = compozitEnrichParamsBatch($pdo, $owner, $idsBatch);
            $_SESSION['compozit_last_import_ids'] = array_values(array_unique(array_merge($remaining, $stats['retryIds'])));

            $seconds = number_format(microtime(true) - $started, 1, ',', ' ');
            $message = "Recherche terminée : {$stats['checked']} composant(s) analysé(s), {$stats['enriched']} enrichi(s), {$stats['paramsAdded']} paramètre(s) ajouté(s), {$stats['notFound']} sans résultat complet, {$stats['withoutDefinitions']} sans définitions de paramètres, en {$seconds} s.";

            if (!empty($_SESSION['compozit_last_import_ids'])) {
                $message .= " Il reste " . count($_SESSION['compozit_last_import_ids']) . " composant(s) à retenter ou à compléter manuellement.";
            }
        } catch (Throwable $e) {
            $error = "Recherche des paramètres impossible : " . $e->getMessage();
        }
    }
}

/* Recherche de caractéristiques sur TOUTE la base, en tâche de fond (AJAX + barre de progression).
   Le navigateur enchaîne les lots successifs jusqu'au bout, en renvoyant l'avancement en JSON. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enrich_all_params_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    $owner = (int) $_SESSION['user_id'];

    try {
        $pdo = getConnection();

        if (!empty($_POST['reset'])) {
            $_SESSION['compozit_enrich_all_ids'] = null;
            $_SESSION['compozit_enrich_all_total'] = 0;
            $_SESSION['compozit_enrich_all_stats'] = ['enriched' => 0, 'paramsAdded' => 0];
        }

        $queue = array_values(array_unique(array_filter(array_map('intval', $_SESSION['compozit_enrich_all_ids'] ?? []))));

        if (!$queue) {
            $stmt = $pdo->prepare("SELECT id FROM `data` WHERE owner = ? ORDER BY id ASC");
            $stmt->execute([$owner]);
            $queue = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $_SESSION['compozit_enrich_all_total'] = count($queue);
        }

        $total = (int) ($_SESSION['compozit_enrich_all_total'] ?? count($queue));
        $stats = $_SESSION['compozit_enrich_all_stats'] ?? ['enriched' => 0, 'paramsAdded' => 0];

        if (!$queue) {
            echo json_encode([
                'ok'          => true,
                'done'        => true,
                'total'       => $total,
                'processed'   => $total,
                'remaining'   => 0,
                'enriched'    => (int) $stats['enriched'],
                'paramsAdded' => (int) $stats['paramsAdded'],
            ]);
            exit();
        }

        $idsBatch  = array_slice($queue, 0, COMPOZIT_PARAM_ENRICH_BATCH);
        $remaining = array_slice($queue, COMPOZIT_PARAM_ENRICH_BATCH);

        $batchStats = compozitEnrichParamsBatch($pdo, $owner, $idsBatch);
        $_SESSION['compozit_enrich_all_ids'] = $remaining;

        $stats['enriched']    = (int) $stats['enriched'] + (int) $batchStats['enriched'];
        $stats['paramsAdded'] = (int) $stats['paramsAdded'] + (int) $batchStats['paramsAdded'];
        $_SESSION['compozit_enrich_all_stats'] = $stats;

        echo json_encode([
            'ok'          => true,
            'done'        => empty($remaining),
            'total'       => $total,
            'processed'   => $total - count($remaining),
            'remaining'   => count($remaining),
            'enriched'    => (int) $stats['enriched'],
            'paramsAdded' => (int) $stats['paramsAdded'],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

/* Recherche de caractéristiques pour un composant précis (bouton de la fiche). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enrich_single_params') {
    $componentId = (int) ($_POST['component_id'] ?? 0);

    if ($componentId <= 0) {
        header('Location: components.php?error=' . urlencode('Composant invalide.'));
        exit();
    }

    @set_time_limit(120);
    $redirect = 'component_sheet.php?id=' . $componentId;

    try {
        $pdo = getConnection();
        $stats = compozitEnrichParamsBatch($pdo, (int) $_SESSION['user_id'], [$componentId]);
        $redirect .= '&param_added=' . (int) $stats['paramsAdded'] . '&param_checked=' . (int) $stats['checked'];
    } catch (Throwable $e) {
        $redirect .= '&param_error=' . urlencode($e->getMessage());
    }

    header('Location: ' . $redirect);
    exit();
}

$error = $error !== '' ? $error : (isset($_GET['error']) ? (string) $_GET['error'] : '');

// Tables effectivement presentes dans la base (information affichee).
$tablesPresent = [];
$lastImportIds = array_values(array_unique(array_filter(array_map('intval', $_SESSION['compozit_last_import_ids'] ?? []))));
try {
    $pdo      = getConnection();
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tablesPresent = array_values(array_intersect(compozitBackupTables(), $existing));
} catch (PDOException $e) {
    $error = $error !== '' ? $error : $e->getMessage();
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
    <title>Sauvegardes - Gestion des Composants</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 28px 32px 32px;
            margin-bottom: 24px;
            box-shadow: 0 20px 40px rgba(102,126,234,0.2);
        }
        .header-top {
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 16px; margin-bottom: 20px;
        }
        .header-title { display: flex; align-items: center; gap: 14px; }
        .header-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
        .header-title p { font-size: 13px; opacity: 0.85; margin-top: 3px; }
        .user-chip {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15);
            padding: 8px 16px; border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2); font-size: 13px;
            white-space: nowrap;
        }
        .logout-link {
            color: white; text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 500;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-buttons a {
            background: rgba(255,255,255,0.15);
            color: white; padding: 10px 18px; border-radius: 999px;
            text-decoration: none; font-weight: 500; font-size: 14px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .nav-buttons a:hover { background: rgba(255,255,255,0.28); }
        .section-title {
            font-size: 13px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--text-secondary);
            margin: 26px 4px 12px;
        }
        .section-title:first-of-type { margin-top: 0; }
        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            font-weight: 600; font-size: 14px; line-height: 1.5;
        }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);
        }
        .card + .card { margin-top: 20px; }
        .card h2 { font-size: 20px; margin-bottom: 10px; }
        .card p { color: var(--text-secondary); font-size: 14px; line-height: 1.6; }
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            border-radius: 10px;
            padding: 14px 18px;
            margin: 20px 0;
            font-size: 13px;
            line-height: 1.6;
        }
        form { margin-top: 22px; }
        input[type="file"] {
            display: block; width: 100%;
            padding: 16px;
            border: 2px dashed var(--border-strong);
            border-radius: 12px;
            background: var(--bg-muted);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            margin-bottom: 18px;
        }
        .btn {
            padding: 14px 26px; border: none; border-radius: 8px;
            cursor: pointer; text-decoration: none; font-weight: 600;
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 15px; transition: all 0.18s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(0,0,0,0.15); }
        .btn-primary { background: #6366f1; color: white; }
        .btn-success { background: #10b981; color: white; }
        .enrich-progress { margin-top: 20px; }
        .enrich-progress-bar {
            width: 100%; height: 22px; border-radius: 999px;
            background: var(--bg-muted); overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .enrich-progress-fill {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, #10b981, #34d399);
            transition: width 0.25s ease;
        }
        .enrich-progress-text {
            margin-top: 8px; font-size: 13px;
            color: var(--text-secondary); font-weight: 600;
        }
        .enrich-result {
            margin-top: 18px; padding: 14px 18px; border-radius: 10px;
            font-size: 14px; font-weight: 600;
            background: #d4edda; color: #155724; border: 1px solid #c3e6cb;
        }
        .enrich-result.is-error {
            background: #f8d7da; color: #721c24; border-color: #f5c6cb;
        }
        .details {
            margin-top: 22px; padding-top: 18px;
            border-top: 1px solid var(--border-color);
            font-size: 13px; color: var(--text-secondary); line-height: 1.7;
        }
        .details code {
            background: var(--bg-muted); padding: 2px 6px;
            border-radius: 4px; font-size: 12px;
        }
        .details ul { margin: 8px 0 0 20px; }
        .download-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">💾</div>
                    <div>
                        <h1>Sauvegardes</h1>
                        <p>Restauration, sauvegarde SQL et échange CSV des composants</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="components.php">📦 Composants</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <p class="section-title">Sauvegarde (export)</p>

        <div class="card">
            <h2>💾 Sauvegarde SQL</h2>
            <p>
                Le fichier téléchargé contient la structure et les données de
                <?php echo count($tablesPresent); ?> tables
                (composants, emplacements, projets, fournisseurs, fabricants,
                catégories, utilisateurs…).
                Il se restaure avec la page <strong>Restauration</strong> ci-dessous,
                avec phpMyAdmin, ou avec la version de bureau de CompoZ'IT.
            </p>

            <div class="download-row">
                <a href="?download=1" class="btn btn-success">📥 Générer et télécharger la sauvegarde</a>
            </div>

            <div class="details">
                <strong>Bon à savoir</strong>
                <ul>
                    <li>Nom du fichier :
                        <code>compozit_AAAA-MM-JJ_HH-MM-SS.sql</code></li>
                    <li>Les images (<code>img/</code>), les fiches techniques
                        (<code>datasheets/</code>) et les fichiers de projets
                        (<code>projets/</code>) ne sont pas dans le fichier SQL :
                        ce sont des fichiers, à copier séparément.</li>
                    <li>Pour une sauvegarde régulière, la même opération est
                        disponible dans phpMyAdmin (onglet Exporter).</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <h2>🧾 Export CSV des composants</h2>
            <p>
                Exporte uniquement l'inventaire des composants au format
                <code>.csv</code>, avec les colonnes
                <code>id</code>, <code>owner</code>, <code>name</code>,
                <code>manufacturer</code>, <code>package</code>,
                <code>pins</code>, <code>smd</code>, <code>quantity</code>,
                <code>location</code>, <code>order_quantity</code>,
                <code>price</code>, <code>datasheet</code>, <code>comment</code>,
                <code>category</code>, <code>public</code>, <code>url</code>,
                <code>image_path</code>, <code>created_at</code>,
                <code>supplier_id</code> et <code>supplier_reference</code>.
            </p>

            <div class="download-row">
                <a href="?download=csv" class="btn btn-success">📄 Télécharger le CSV</a>
            </div>

            <div class="details">
                <strong>Bon à savoir</strong>
                <ul>
                    <li>Le fichier est encodé en UTF-8 avec BOM, pour une
                        ouverture directe dans Excel.</li>
                    <li>Le separateur est <code>;</code>.</li>
                    <li>Ce format sert a echanger les composants sans toucher
                        aux autres tables de l'application.</li>
                </ul>
            </div>
        </div>

        <p class="section-title">Restauration (import)</p>

        <div class="card">
            <h2>♻️ Restaurer une sauvegarde</h2>
            <p>
                Sélectionne un fichier <code>.sql</code> produit par la section
                <strong>Sauvegarde</strong> ci-dessus, par phpMyAdmin ou par la
                version de bureau de CompoZ'IT.
            </p>

            <div class="warning">
                ⚠️ <strong>Les tables présentes dans le fichier sont remplacées</strong>
                (supprimées puis recréées avec les données de la sauvegarde).
                Les tables absentes du fichier et les images ne sont pas touchées.
                Fais un export avant d'importer, par précaution.
            </div>

            <form method="post" enctype="multipart/form-data"
                  onsubmit="return confirm('Remplacer les tables présentes dans le fichier par celles de la sauvegarde ?');">
                <input type="hidden" name="action" value="import">
                <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
                <input type="file" name="sql_file" accept=".sql,.txt" required>
                <button type="submit" class="btn btn-primary">♻️ Restaurer la base</button>
            </form>

            <div class="details">
                <strong>Limites du serveur</strong>
                <ul>
                    <li>Taille maximale par envoi :
                        <code><?php echo htmlspecialchars(ini_get('upload_max_filesize')); ?></code>
                        (limite de cette page : 20 Mo).</li>
                    <li>Pour un fichier plus gros, utilise l'onglet
                        <strong>Importer</strong> de phpMyAdmin (hPanel →
                        Bases de données).</li>
                    <li>Les erreurs de clés étrangères sont neutralisées
                        pendant l'import : l'ordre des tables du fichier n'a
                        pas d'importance.</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <h2>🧾 Importer des composants en CSV</h2>
            <p>
                Sélectionne un fichier <code>.csv</code>, <code>.txt</code>
                ou tabulé exporté depuis un tableur. Les composants existants
                sont mis à jour si leur <code>id</code> existe déjà pour ton
                compte ; sinon ils sont ajoutés.
            </p>

            <div class="warning">
                ⚠️ <strong>Import composants uniquement</strong> :
                ce mode ne remplace pas toute la base. Il agit sur la table
                <code>data</code> et attend les colonnes du format CompoZ'IT
                (<code>id</code>, <code>owner</code>, <code>name</code>,
                <code>manufacturer</code>, <code>package</code>,
                <code>pins</code>, <code>smd</code>, <code>quantity</code>,
                <code>location</code>, <code>order_quantity</code>,
                <code>price</code>, <code>datasheet</code>, <code>comment</code>,
                <code>category</code>, <code>public</code>, <code>url</code>,
                <code>image_path</code>, <code>created_at</code>,
                <code>supplier_id</code> et <code>supplier_reference</code>).
            </div>

            <form method="post" enctype="multipart/form-data"
                  onsubmit="return confirm('Importer ou mettre a jour les composants contenus dans ce fichier CSV ?');">
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="MAX_FILE_SIZE" value="20971520">
                <input type="file" name="csv_file" accept=".csv,.txt,.tsv" required>
                <button type="submit" class="btn btn-primary">🧾 Importer le CSV</button>
            </form>

            <?php if ($showLocationPreview && !empty($_SESSION['compozit_pending_csv']['new_locations'])):
                $newLocs = $_SESSION['compozit_pending_csv']['new_locations'];
            ?>
                <div class="warning" style="margin-top:18px;">
                    <strong>⚠️ Nouveaux emplacements détectés</strong><br>
                    Les emplacements ci-dessous n'existent pas encore et seront
                    créés automatiquement. Tu peux modifier leur nom avant de
                    confirmer l'import.
                </div>

                <form method="post" style="margin-top:14px;">
                    <input type="hidden" name="action" value="confirm_csv_import">
                    <table class="csv-locations-table" style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                        <thead>
                            <tr style="border-bottom:2px solid #243858;">
                                <th style="text-align:left;padding:8px;color:#93a4c0;font-size:0.8rem;">Emplacement d'origine (CSV)</th>
                                <th style="text-align:left;padding:8px;color:#93a4c0;font-size:0.8rem;">Nom à créer (modifiable)</th>
                                <th style="text-align:left;padding:8px;color:#93a4c0;font-size:0.8rem;">Type de rangement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($newLocs as $orig => $default):
                                $inputName = 'loc_' . $orig;
                                $typeInputName = 'loc_type_' . $orig;
                            ?>
                                <tr style="border-bottom:1px solid #1f2b46;">
                                    <td style="padding:8px;color:#e8eefb;font-family:monospace;"><?php echo htmlspecialchars($orig); ?></td>
                                    <td style="padding:8px;">
                                        <input type="text"
                                               name="<?php echo htmlspecialchars($inputName); ?>"
                                               value="<?php echo htmlspecialchars($default); ?>"
                                               style="width:100%;padding:6px 10px;background:#101a2d;border:1px solid #243858;border-radius:6px;color:#e8eefb;font-size:0.9rem;">
                                    </td>
                                    <td style="padding:8px;">
                                        <select name="<?php echo htmlspecialchars($typeInputName, ENT_QUOTES, 'UTF-8'); ?>"
                                                style="width:100%;padding:6px 10px;background:#101a2d;border:1px solid #243858;border-radius:6px;color:#e8eefb;font-size:0.9rem;">
                                            <?php foreach (getLocationStorageTypes() as $typeKey => $typeMeta): ?>
                                                <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($typeMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">✅ Confirmer l'import</button>
                        <button type="submit" name="action" value="cancel_csv_import" class="btn btn-ghost">❌ Annuler</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="details">
                <strong>Formats acceptes</strong>
                <ul>
                    <li>Separateurs detectes automatiquement :
                        <code>;</code>, <code>,</code> ou tabulation.</li>
                    <li>Les dates <code>created_at</code> peuvent etre au format
                        <code>JJ/MM/AAAA HH:MM</code> ou <code>AAAA-MM-JJ HH:MM:SS</code>.</li>
                    <li>La colonne <code>location</code> accepte un libellé comme
                        <code>A25-1</code>, <code>D12-3</code>, <code>CL70</code> ou <code>CL71</code>.
                        Si l'emplacement n'existe pas encore, il est créé automatiquement.</li>
                    <li>La colonne <code>owner</code> du fichier est ignoree :
                        les lignes importees sont rattachees a l'utilisateur connecte.</li>
                </ul>
            </div>
        </div>

        <?php if (!empty($lastImportIds)): ?>
            <div class="card">
                <h2>🔎 Trouver les paramètres techniques</h2>
                <p>
                    Lance une recherche <strong>sans forcer</strong> sur la dernière
                    liste importée (<?php echo count($lastImportIds); ?> composant(s) encore en attente).
                    Les valeurs déjà présentes ne sont jamais écrasées.
                </p>

                <div class="warning">
                    ℹ️ Ce remplissage automatique s'appuie sur
                    <strong>toutes les catégories et sous-catégories</strong>
                    qui ont des définitions de paramètres dynamiques. Le moteur
                    essaye d'abord le texte déjà importé
                    (<code>nom</code>, <code>commentaire</code>, <code>réf. fournisseur</code>, etc.),
                    puis les API fournisseurs quand une correspondance propre est trouvée.
                    Les diodes gardent en plus un parseur spécialisé pour aller plus loin.
                </div>

                <form method="post" onsubmit="return confirm('Chercher automatiquement les paramètres manquants sur la dernière liste importée, sans écraser les valeurs existantes ?');">
                    <input type="hidden" name="action" value="enrich_import_params">
                    <button type="submit" class="btn btn-success">🔎 Trouver les paramètres</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>🔎 Rechercher les caractéristiques (toute la base)</h2>
            <p>
                Lance une recherche <strong>sans forcer</strong> sur l'ensemble de vos composants,
                à tout moment. Les valeurs déjà présentes ne sont jamais écrasées : seules les
                caractéristiques manquantes sont complétées. Le scan couvre <strong>tous</strong>
                les composants d'un coup, avec une barre de progression (aucun changement de page).
            </p>

            <div class="warning">
                ℹ️ Le moteur s'appuie d'abord sur le texte déjà connu
                (<code>nom</code>, <code>commentaire</code>, <code>réf. fournisseur</code>…),
                notamment les références distributeur indiquées dans le commentaire
                (<code>FA</code> Farnell, <code>MO</code> Mouser, <code>RS</code>, <code>TME</code>…),
                puis interroge les API fournisseurs.
            </div>

            <button type="button" id="enrichAllBtn" class="btn btn-success">🔎 Rechercher sur toute la base</button>

            <div class="enrich-progress" id="enrichAllProgress" hidden>
                <div class="enrich-progress-bar">
                    <div class="enrich-progress-fill" id="enrichAllFill"></div>
                </div>
                <div class="enrich-progress-text" id="enrichAllText"></div>
            </div>

            <div class="enrich-result" id="enrichAllResult" hidden></div>
        </div>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('enrichAllBtn');
        var wrap = document.getElementById('enrichAllProgress');
        var fill = document.getElementById('enrichAllFill');
        var text = document.getElementById('enrichAllText');
        var result = document.getElementById('enrichAllResult');
        if (!btn) { return; }

        var running = false;
        var totals = { enriched: 0, paramsAdded: 0 };

        function call(reset) {
            var body = new URLSearchParams();
            body.set('action', 'enrich_all_params_ajax');
            if (reset) { body.set('reset', '1'); }
            return fetch('import_database.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
        }

        function step(reset) {
            call(reset).then(function (data) {
                if (!data || !data.ok) {
                    running = false;
                    btn.disabled = false;
                    text.textContent = 'Erreur : ' + ((data && data.error) ? data.error : 'inconnue');
                    return;
                }

                totals.enriched = data.enriched || 0;
                totals.paramsAdded = data.paramsAdded || 0;

                var total = data.total || 0;
                var processed = data.processed || 0;
                var percent = total > 0 ? Math.floor(processed * 100 / total) : 100;
                fill.style.width = percent + '%';
                text.textContent = processed + ' / ' + total + ' composants analysés (' + percent + '%)'
                    + ' — ' + totals.paramsAdded + ' paramètre(s) ajouté(s)';

                if (data.done) {
                    running = false;
                    btn.disabled = false;
                    btn.textContent = '🔎 Relancer sur toute la base';
                    result.hidden = false;
                    result.className = 'enrich-result';
                    result.innerHTML = '✅ Analyse terminée : <strong>' + totals.enriched
                        + '</strong> composant(s) enrichi(s), <strong>' + totals.paramsAdded
                        + '</strong> paramètre(s) ajouté(s).';
                    return;
                }

                step(false);
            })['catch'](function (err) {
                running = false;
                btn.disabled = false;
                text.textContent = 'Erreur réseau : ' + err;
            });
        }

        btn.addEventListener('click', function () {
            if (running) { return; }
            if (!confirm('Rechercher les caractéristiques manquantes sur toute la base, sans écraser les valeurs existantes ?')) { return; }
            running = true;
            btn.disabled = true;
            wrap.hidden = false;
            result.hidden = true;
            fill.style.width = '0%';
            text.textContent = 'Initialisation…';
            totals = { enriched: 0, paramsAdded: 0 };
            step(true);
        });
    })();
    </script>
</body>
</html>
