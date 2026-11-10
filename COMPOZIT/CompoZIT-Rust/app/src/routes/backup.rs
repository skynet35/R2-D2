//! Écran « Sauvegardes » : réglage de la sauvegarde automatique et gestion des
//! fichiers produits.
//!
//! Les réglages (activation, récurrence, heure, rétention) sont enregistrés
//! dans `config.toml`, à la suite de ceux du serveur et de la base. Les
//! fichiers eux-mêmes sont déposés dans le dossier `backups/`, à côté de
//! l'exécutable.

use axum::extract::{Path, State};
use axum::http::header;
use axum::response::{Html, IntoResponse, Response};
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Map, Value};

use crate::backup;
use crate::config::BackupConfig;
use crate::error::{AppError, AppResult};
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;

/// Récurrences proposées, en jours.
const INTERVALS: [(u32, &str); 6] = [
    (1, "Chaque jour"),
    (2, "Tous les 2 jours"),
    (3, "Tous les 3 jours"),
    (7, "Chaque semaine"),
    (15, "Toutes les 2 semaines"),
    (30, "Chaque mois"),
];

// -------------------------------------------------------------------- Écran

pub async fn page(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let settings = state.backup_config().await;
    let directory = backup::directory(&state.root);

    let mut context = page_context(
        &state,
        &user,
        "backup",
        "Sauvegardes",
        "Copie locale de la base de données",
    )
    .await;

    for (key, value) in status_map(&state, &settings) {
        context.insert(key, value);
    }
    context.insert(
        "directory".to_string(),
        json!(directory.display().to_string()),
    );
    context.insert("intervals".to_string(), json!(interval_options(&settings)));
    context.insert("hours".to_string(), json!(hour_options(&settings)));

    templates::render(&state.templates, "backup.html", Value::Object(context))
}

fn interval_options(settings: &BackupConfig) -> Vec<Value> {
    INTERVALS
        .iter()
        .map(|(days, label)| {
            json!({ "days": days, "label": label, "selected": *days == settings.interval_days })
        })
        .collect()
}

fn hour_options(settings: &BackupConfig) -> Vec<Value> {
    (0..24)
        .map(|hour| {
            json!({
                "value": hour,
                "label": format!("{hour:02}:00"),
                "selected": hour == settings.hour,
            })
        })
        .collect()
}

/// Données de l'écran, réutilisées telles quelles après une action.
fn status_map(state: &AppState, settings: &BackupConfig) -> Map<String, Value> {
    let directory = backup::directory(&state.root);
    let files = backup::list(&directory);
    let total: u64 = files.iter().map(|file| file.size).sum();

    let mut map = Map::new();
    map.insert("settings".to_string(), json!(settings));
    map.insert("files".to_string(), json!(files));
    map.insert("count".to_string(), json!(files.len()));
    map.insert("total_label".to_string(), json!(backup::size_label(total)));
    map.insert("last_run".to_string(), json!(backup::last_run_label(settings)));
    map.insert(
        "next_run".to_string(),
        json!(backup::next_run_label(settings, sqlx::types::chrono::Local::now())),
    );
    map
}

async fn status(state: &AppState) -> Value {
    let settings = state.backup_config().await;
    Value::Object(status_map(state, &settings))
}

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

// ------------------------------------------------------------- Enregistrement

#[derive(Debug, Deserialize)]
pub struct BackupForm {
    #[serde(default)]
    pub enabled: Option<String>,
    #[serde(default)]
    pub interval_days: Option<String>,
    #[serde(default)]
    pub hour: Option<String>,
    #[serde(default)]
    pub keep: Option<String>,
}

pub async fn save(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<BackupForm>,
) -> AppResult<Json<Value>> {
    let current = state.backup_config().await;

    let enabled = matches!(
        form.enabled.as_deref().map(str::trim),
        Some("on") | Some("true") | Some("1")
    );

    let interval_days = match number(&form.interval_days, 1) {
        Ok(value) if (1..=90).contains(&value) => value,
        _ => return Ok(failure("La récurrence doit être comprise entre 1 et 90 jours.")),
    };
    let hour = match number(&form.hour, 0) {
        Ok(value) if value <= 23 => value,
        _ => return Ok(failure("L'heure doit être comprise entre 0 et 23.")),
    };
    let keep = match number(&form.keep, 10) {
        Ok(value) if (1..=100).contains(&value) => value,
        _ => return Ok(failure("Le nombre de fichiers conservés doit être compris entre 1 et 100.")),
    };

    let updated = BackupConfig {
        enabled,
        interval_days,
        hour,
        keep,
        last_run: current.last_run,
    };
    state.save_backup(updated.clone()).await?;

    tracing::info!(
        "Sauvegarde automatique {} (tous les {} jour(s) à {:02}:00, {} conservée(s)) — {}",
        if enabled { "activée" } else { "désactivée" },
        interval_days,
        hour,
        keep,
        user.email
    );

    Ok(Json(json!({
        "ok": true,
        "message": if enabled {
            format!("Sauvegarde enregistrée : {} à {:02}:00.", interval_label(interval_days), hour)
        } else {
            "Sauvegarde automatique désactivée.".to_string()
        },
        "status": status(&state).await,
    })))
}

fn number(value: &Option<String>, default: u32) -> Result<u32, ()> {
    match value.as_deref().map(str::trim) {
        None | Some("") => Ok(default),
        Some(raw) => raw.parse::<u32>().map_err(|_| ()),
    }
}

fn interval_label(days: u32) -> String {
    INTERVALS
        .iter()
        .find(|(value, _)| *value == days)
        .map(|(_, label)| label.to_string())
        .unwrap_or_else(|| format!("Tous les {days} jours"))
}

// --------------------------------------------------------------- Exécution

/// Déclenche une sauvegarde immédiate, sans attendre l'échéance.
pub async fn run(
    State(state): State<AppState>,
    user: CurrentUser,
) -> AppResult<Json<Value>> {
    let Some(pool) = state.pool().await else {
        return Ok(failure("Aucune base n'est connectée : vérifiez l'écran « Base de données »."));
    };

    let settings = state.backup_config().await;
    let database = state.database_config().await.database;

    let file = match backup::run(&pool, &state.root, &database, settings.keep).await {
        Ok(file) => file,
        Err(message) => return Ok(failure(&message)),
    };

    tracing::info!("Sauvegarde manuelle écrite par {} : {}", user.email, file.name);

    let mut updated = settings;
    updated.last_run = sqlx::types::chrono::Local::now()
        .format("%Y-%m-%d %H:%M:%S")
        .to_string();
    state.save_backup(updated).await?;

    Ok(Json(json!({
        "ok": true,
        "message": format!("Sauvegarde créée : {} ({})", file.name, file.size_label),
        "status": status(&state).await,
    })))
}

#[derive(Debug, Deserialize)]
pub struct FileForm {
    pub name: String,
}

pub async fn delete(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<FileForm>,
) -> AppResult<Json<Value>> {
    let directory = backup::directory(&state.root);
    if let Err(message) = backup::delete(&directory, form.name.trim()) {
        return Ok(failure(&message));
    }

    tracing::info!("Sauvegarde « {} » supprimée par {}", form.name, user.email);
    Ok(Json(json!({
        "ok": true,
        "message": format!("Sauvegarde « {} » supprimée.", form.name),
        "status": status(&state).await,
    })))
}

/// Téléchargement d'un fichier de sauvegarde.
pub async fn download(
    State(state): State<AppState>,
    _user: CurrentUser,
    Path(name): Path<String>,
) -> AppResult<Response> {
    let directory = backup::directory(&state.root);
    let path = backup::safe_path(&directory, &name).map_err(AppError::Message)?;

    let body = std::fs::read(&path)
        .map_err(|error| AppError::Message(format!("Lecture de « {name} » impossible : {error}")))?;

    let disposition = format!("attachment; filename=\"{name}\"");
    Ok((
        [
            (header::CONTENT_TYPE, "application/sql; charset=utf-8".to_string()),
            (header::CONTENT_DISPOSITION, disposition),
        ],
        body,
    )
        .into_response())
}
