//! Sauvegarde locale de la base de données.
//!
//! Le vidage est produit par l'application elle-même, en interrogeant le
//! serveur MySQL : aucun `mysqldump` n'est requis. C'est ce qui permet de
//! sauvegarder aussi bien la base locale (XAMPP) qu'une base distante
//! (hébergement), sans installer d'outil supplémentaire.
//!
//! Le fichier obtenu est un script SQL classique — `DROP TABLE`, `CREATE
//! TABLE`, `INSERT` — réimportable tel quel dans phpMyAdmin, HeidiSQL ou avec
//! la commande `mysql`.
//!
//! Une tâche de fond vérifie régulièrement l'échéance et déclenche la
//! sauvegarde sans intervention.

use std::path::{Path, PathBuf};
use std::time::Duration;

use serde::Serialize;
use sqlx::types::chrono::{DateTime, Local, NaiveDate, NaiveDateTime};
use sqlx::{MySqlPool, Row};

use crate::config::BackupConfig;
use crate::state::AppState;

/// Intervalle entre deux vérifications de l'échéance.
const CHECK_INTERVAL: Duration = Duration::from_secs(60);

/// Nombre de lignes regroupées dans une même instruction `INSERT`.
const ROWS_PER_STATEMENT: usize = 200;

/// Dossier des sauvegardes, à côté de l'exécutable.
pub fn directory(root: &Path) -> PathBuf {
    root.join("backups")
}

/// Description d'un fichier de sauvegarde, telle qu'affichée dans l'écran.
#[derive(Debug, Clone, Serialize)]
pub struct BackupFile {
    pub name: String,
    pub size: u64,
    pub size_label: String,
    /// Date d'écriture, au format « AAAA-MM-JJ HH:MM:SS ».
    pub created: String,
}

// ------------------------------------------------------------------ Exécution

/// Écrit un nouveau fichier de sauvegarde, puis applique la rétention.
pub async fn run(
    pool: &MySqlPool,
    root: &Path,
    database: &str,
    keep: u32,
) -> Result<BackupFile, String> {
    let directory = directory(root);
    std::fs::create_dir_all(&directory).map_err(|error| {
        format!(
            "Création du dossier « {} » impossible : {error}",
            directory.display()
        )
    })?;

    let body = dump(pool).await?;
    let name = format!("compozit_{}_{}.sql", slug(database), stamp());
    let path = directory.join(&name);

    std::fs::write(&path, body)
        .map_err(|error| format!("Écriture de « {} » impossible : {error}", path.display()))?;

    prune(&directory, keep);

    describe(&path).ok_or_else(|| format!("« {} » a été écrite mais reste illisible.", path.display()))
}

/// Script SQL complet : schéma puis données de chaque table.
async fn dump(pool: &MySqlPool) -> Result<String, String> {
    let database: String = sqlx::query_scalar("SELECT DATABASE()")
        .fetch_one(pool)
        .await
        .map_err(|error| format!("Nom de la base illisible : {error}"))?;

    let tables: Vec<String> = sqlx::query_scalar(
        "SELECT TABLE_NAME FROM information_schema.TABLES \
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' \
         ORDER BY TABLE_NAME",
    )
    .fetch_all(pool)
    .await
    .map_err(|error| format!("Liste des tables illisible : {error}"))?;

    let mut out = String::new();
    out.push_str("-- Sauvegarde CompoZ'IT\n");
    out.push_str(&format!("-- Base : {database}\n"));
    out.push_str(&format!(
        "-- Généré le {}\n",
        Local::now().format("%Y-%m-%d %H:%M:%S")
    ));
    out.push_str("--\n");
    out.push_str("-- Restauration : importer ce fichier dans phpMyAdmin, ou lancer\n");
    out.push_str("--   mysql -h <hote> -u <utilisateur> -p <base> < fichier.sql\n\n");
    out.push_str("SET NAMES utf8mb4;\n");
    out.push_str("SET FOREIGN_KEY_CHECKS = 0;\n\n");

    for table in &tables {
        let create: String = sqlx::query(sqlx::AssertSqlSafe(format!(
            "SHOW CREATE TABLE {}",
            quote(table)
        )))
        .fetch_one(pool)
        .await
        .map_err(|error| format!("Structure de « {table} » illisible : {error}"))?
        .try_get(1)
        .map_err(|error| format!("Structure de « {table} » illisible : {error}"))?;

        out.push_str(&format!("--\n-- Table {table}\n--\n"));
        out.push_str(&format!("DROP TABLE IF EXISTS {};\n", quote(table)));
        out.push_str(&create);
        out.push_str(";\n\n");

        let columns: Vec<(String, String)> = sqlx::query_as(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS \
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? \
             ORDER BY ORDINAL_POSITION",
        )
        .bind(table)
        .fetch_all(pool)
        .await
        .map_err(|error| format!("Colonnes de « {table} » illisibles : {error}"))?;

        if columns.is_empty() {
            continue;
        }

        let names: Vec<String> = columns.iter().map(|(name, _)| quote(name)).collect();
        let values: Vec<String> = columns.iter().map(|(name, kind)| literal(name, kind)).collect();

        // Les virgules sont concaténées en tant que texte : écrites directement
        // entre les expressions, elles seraient prises pour des séparateurs
        // d'arguments de `CONCAT` et disparaîtraient de l'instruction produite.
        let mut arguments: Vec<String> = Vec::with_capacity(values.len() * 2);
        for (index, value) in values.iter().enumerate() {
            if index > 0 {
                arguments.push("','".to_string());
            }
            arguments.push(value.clone());
        }

        // Le serveur construit lui-même chaque ligne de valeurs : elles sont
        // donc mises en forme par MySQL, sans conversion côté application ni
        // risque d'erreur de type.
        let statement = format!(
            "SELECT CONCAT('(', {}, ')') FROM {}",
            arguments.join(", "),
            quote(table)
        );

        let rows: Vec<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
            .fetch_all(pool)
            .await
            .map_err(|error| format!("Données de « {table} » illisibles : {error}"))?;

        // Les lignes sont regroupées en `INSERT` multiples : un fichier de
        // plusieurs centaines de milliers de lignes se restaure ainsi en
        // quelques secondes au lieu de plusieurs minutes.
        for (index, row) in rows.iter().enumerate() {
            if index % ROWS_PER_STATEMENT == 0 {
                if index > 0 {
                    out.push_str(";\n");
                }
                out.push_str(&format!(
                    "INSERT INTO {} ({}) VALUES\n",
                    quote(table),
                    names.join(", ")
                ));
            } else {
                out.push_str(",\n");
            }
            out.push_str(row);
        }
        if !rows.is_empty() {
            out.push_str(";\n");
        }
        out.push('\n');
    }

    out.push_str("SET FOREIGN_KEY_CHECKS = 1;\n");
    Ok(out)
}

/// Expression SQL produisant la valeur d'une colonne sous forme littérale.
fn literal(column: &str, data_type: &str) -> String {
    let name = quote(column);
    match data_type {
        // Les nombres sont convertis en texte par le serveur : la précision
        // des décimaux est conservée.
        "tinyint" | "smallint" | "mediumint" | "int" | "integer" | "bigint" | "decimal"
        | "numeric" | "float" | "double" | "real" | "year" => {
            format!("IFNULL(CAST({name} AS CHAR),'NULL')")
        }
        // Les données binaires sont écrites en hexadécimal : la copie est
        // exacte quel que soit l'encodage.
        "bit" | "binary" | "varbinary" | "tinyblob" | "blob" | "mediumblob" | "longblob" => {
            format!("IF({name} IS NULL,'NULL',CONCAT('0x',HEX({name})))")
        }
        // Une colonne `TIMESTAMP` est mémorisée en UTC et relue selon le
        // fuseau de la session : l'écrire en texte appliquerait deux fois le
        // décalage (une fois à la lecture, une fois à l'import). L'instant
        // brut est donc inscrit dans le fichier sous forme d'expression
        // `FROM_UNIXTIME(...)`, que le serveur réévalue dans son propre
        // fuseau au moment de la restauration.
        "timestamp" => format!(
            "IF({name} IS NULL,'NULL',CONCAT('FROM_UNIXTIME(',UNIX_TIMESTAMP({name}),')'))"
        ),
        // Tout le reste (texte, dates, JSON) : échappement assuré par QUOTE.
        // `QUOTE` protège les guillemets et les barres obliques inverses mais
        // recopie tels quels les retours à la ligne. Ceux-ci sont donc
        // remplacés par `\r` et `\n` : la valeur tient sur une seule ligne du
        // fichier et retraverse sans perte les lecteurs qui réécrivent les
        // fins de ligne — c'est le cas du client MySQL sous Windows, qui
        // ramène tout CRLF à un simple LF.
        _ => format!(
            "IF({name} IS NULL,'NULL',REPLACE(REPLACE(QUOTE({name}),CHAR(13),'\\\\r'),CHAR(10),'\\\\n'))"
        ),
    }
}

/// Identifiant SQL protégé par des accents graves.
fn quote(name: &str) -> String {
    format!("`{}`", name.replace('`', "``"))
}

// ---------------------------------------------------------------- Fichiers

/// Fichiers de sauvegarde présents, du plus récent au plus ancien.
pub fn list(directory: &Path) -> Vec<BackupFile> {
    let Ok(entries) = std::fs::read_dir(directory) else {
        return Vec::new();
    };

    let mut files: Vec<BackupFile> = entries
        .flatten()
        .map(|entry| entry.path())
        .filter(|path| path.extension().is_some_and(|value| value == "sql"))
        .filter_map(|path| describe(&path))
        .collect();

    files.sort_by(|left, right| right.created.cmp(&left.created));
    files
}

fn describe(path: &Path) -> Option<BackupFile> {
    let metadata = std::fs::metadata(path).ok()?;
    let name = path.file_name()?.to_string_lossy().to_string();
    let created: DateTime<Local> = metadata.modified().ok()?.into();

    Some(BackupFile {
        name,
        size: metadata.len(),
        size_label: size_label(metadata.len()),
        created: created.format("%Y-%m-%d %H:%M:%S").to_string(),
    })
}

/// Supprime les sauvegardes au-delà du nombre conservé.
fn prune(directory: &Path, keep: u32) {
    let keep = keep.max(1) as usize;
    for file in list(directory).into_iter().skip(keep) {
        let path = directory.join(&file.name);
        match std::fs::remove_file(&path) {
            Ok(()) => tracing::info!("Ancienne sauvegarde supprimée : {}", file.name),
            Err(error) => tracing::warn!("Suppression de « {} » impossible : {error}", file.name),
        }
    }
}

/// Supprime un fichier de sauvegarde désigné par son nom.
pub fn delete(directory: &Path, name: &str) -> Result<(), String> {
    let path = safe_path(directory, name)?;
    std::fs::remove_file(&path).map_err(|error| format!("Suppression impossible : {error}"))
}

/// Chemin d'un fichier de sauvegarde, en refusant toute remontée de dossier.
pub fn safe_path(directory: &Path, name: &str) -> Result<PathBuf, String> {
    let valid = !name.is_empty()
        && !name.contains('/')
        && !name.contains('\\')
        && !name.contains("..")
        && name.ends_with(".sql");
    if !valid {
        return Err("Nom de fichier invalide.".to_string());
    }
    Ok(directory.join(name))
}

pub fn size_label(bytes: u64) -> String {
    const KO: f64 = 1024.0;
    const MO: f64 = KO * 1024.0;
    let value = bytes as f64;
    if value >= MO {
        format!("{:.1} Mo", value / MO)
    } else if value >= KO {
        format!("{:.0} Ko", value / KO)
    } else {
        format!("{bytes} o")
    }
}

fn stamp() -> String {
    Local::now().format("%Y-%m-%d_%H-%M-%S").to_string()
}

/// Nom de fichier sûr, dérivé du nom de la base.
fn slug(value: &str) -> String {
    let cleaned: String = value
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || character == '-' || character == '_' {
                character
            } else {
                '_'
            }
        })
        .collect();
    let trimmed = cleaned.trim_matches('_');
    if trimmed.is_empty() {
        "base".to_string()
    } else {
        trimmed.chars().take(40).collect()
    }
}

// ------------------------------------------------------------- Planification

/// Échéance de la prochaine sauvegarde, à afficher dans l'écran.
pub fn next_run_label(settings: &BackupConfig, now: DateTime<Local>) -> String {
    if !settings.enabled {
        return "Sauvegarde automatique désactivée.".to_string();
    }

    let hour = settings.hour.min(23);
    let interval = settings.interval_days.max(1) as i64;

    let Some(previous) = last_run_date(settings) else {
        return format!("aujourd'hui à {hour:02}:00");
    };

    let elapsed = (now.date_naive() - previous).num_days();
    let remaining = interval - elapsed;

    if remaining > 1 {
        format!("dans {remaining} jours, à {hour:02}:00")
    } else if remaining == 1 {
        format!("demain à {hour:02}:00")
    } else if current_hour(now) < hour {
        format!("aujourd'hui à {hour:02}:00")
    } else {
        "dès que possible (échéance dépassée)".to_string()
    }
}

fn last_run_date(settings: &BackupConfig) -> Option<NaiveDate> {
    NaiveDateTime::parse_from_str(settings.last_run.trim(), "%Y-%m-%d %H:%M:%S")
        .ok()
        .map(|value| value.date())
}

/// Heure locale, lue depuis le formatage.
///
/// `sqlx` ne réexporte que quelques types de `chrono` : le trait `Timelike`
/// n'est pas disponible ici, on passe donc par le texte formaté.
fn current_hour(now: DateTime<Local>) -> u32 {
    now.format("%H").to_string().parse().unwrap_or(0)
}

/// Vrai si la sauvegarde doit être déclenchée maintenant.
///
/// Le déclenchement a lieu à partir de l'heure choisie : si l'application
/// n'était pas lancée à ce moment-là, la sauvegarde est rattrapée au premier
/// démarrage suivant au lieu d'être perdue.
fn due(settings: &BackupConfig, now: DateTime<Local>, last: Option<NaiveDate>) -> bool {
    if !settings.enabled || current_hour(now) < settings.hour.min(23) {
        return false;
    }
    match last {
        Some(previous) => {
            (now.date_naive() - previous).num_days() >= settings.interval_days.max(1) as i64
        }
        None => true,
    }
}

/// Lance la surveillance en tâche de fond : vérification toutes les minutes.
pub fn spawn(state: AppState) {
    tokio::spawn(async move {
        loop {
            tokio::time::sleep(CHECK_INTERVAL).await;
            if let Err(error) = tick(&state).await {
                tracing::warn!("Sauvegarde automatique : {error}");
            }
        }
    });
}

async fn tick(state: &AppState) -> Result<(), String> {
    let settings = state.backup_config().await;
    let now = Local::now();

    if !due(&settings, now, last_run_date(&settings)) {
        return Ok(());
    }

    let Some(pool) = state.pool().await else {
        return Err("aucune base connectée.".to_string());
    };

    let database = state.database_config().await.database;
    let file = run(&pool, &state.root, &database, settings.keep).await?;
    tracing::info!("Sauvegarde automatique écrite : {}", file.name);

    let mut updated = settings;
    updated.last_run = now.format("%Y-%m-%d %H:%M:%S").to_string();
    state
        .save_backup(updated)
        .await
        .map_err(|error| format!("réglages non enregistrés : {error:?}"))
}

/// Horodatage de la dernière sauvegarde, pour l'affichage.
pub fn last_run_label(settings: &BackupConfig) -> String {
    if settings.last_run.trim().is_empty() {
        "Aucune sauvegarde".to_string()
    } else {
        settings.last_run.clone()
    }
}
