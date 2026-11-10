//! Projets.
//!
//! Reprend `projects.php` : un projet appartient à un utilisateur, regroupe des
//! composants (`project_components`), des travaux et du matériel
//! (`project_items`) ainsi que des documents (`project_files`). Chaque projet
//! dispose d'un dossier `projets/<nom assaini>/` pour son image et ses pièces
//! jointes.
//!
//! La liste est rafraîchie sans rechargement à partir du fragment
//! `_projects_list.html` ; les actions renvoient du JSON.

use std::collections::HashMap;
use std::path::{Path, PathBuf};

use axum::extract::{Multipart, State};
use axum::response::Html;
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::{AppError, AppResult};
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;

pub const MAX_NAME: usize = 255;
pub const MAX_DESCRIPTION: usize = 5000;
pub const MAX_IMAGE_BYTES: usize = 10 * 1024 * 1024;

/// Statuts acceptés, alignés sur l'énumération de la table `projects`.
pub const STATUSES: [&str; 4] = ["En cours", "En pause", "Terminé", "Annulé"];

const PROJECT_OF_OWNER: &str = "SELECT id, name FROM projects WHERE id = ? AND owner = ? LIMIT 1";

#[derive(Debug, FromRow)]
struct ProjectRow {
    id: i64,
    name: String,
    description: Option<String>,
    image_path: Option<String>,
    status: Option<String>,
    created_label: Option<String>,
    component_count: i64,
    total_needed: i64,
    total_used: i64,
}

struct Listing {
    projects: Vec<Value>,
    stats: Value,
}

// ------------------------------------------------------------------- Utilitaires

pub fn clip(value: &str, max: usize) -> String {
    value.chars().take(max).collect()
}

/// Normalise un statut : retombe sur « En cours » si la valeur est inconnue.
pub fn normalize_status(value: &str) -> String {
    let value = value.trim();
    STATUSES
        .iter()
        .find(|candidate| candidate.eq_ignore_ascii_case(value))
        .map(|candidate| candidate.to_string())
        .unwrap_or_else(|| "En cours".to_string())
}

/// Classe CSS associée à un statut (`status-en-cours`, `status-termine`…).
pub fn status_key(status: &str) -> &'static str {
    match normalize_status(status).as_str() {
        "Terminé" => "status-termine",
        "En pause" => "status-en-pause",
        "Annulé" => "status-annule",
        _ => "status-en-cours",
    }
}

/// Nom de dossier sûr, comme `preg_replace('/[^a-zA-Z0-9_-]/', '_', $name)`.
pub fn safe_folder(name: &str) -> String {
    let mut folder: String = name
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || character == '_' || character == '-' {
                character
            } else {
                '_'
            }
        })
        .collect();
    folder.truncate(120);
    if folder.is_empty() {
        folder = "projet".to_string();
    }
    folder
}

/// Racine des dossiers de projets.
pub fn projects_dir(root: &Path) -> PathBuf {
    root.join("projets")
}

/// Supprime le dossier d'un projet (sans échouer si le dossier est absent).
pub fn remove_project_folder(root: &Path, name: &str) {
    let folder = projects_dir(root).join(safe_folder(name));
    if folder.is_dir() {
        if let Err(error) = std::fs::remove_dir_all(&folder) {
            tracing::warn!("Suppression de {} impossible : {error}", folder.display());
        }
    }
}

/// Chemin utilisable dans une page : la version PHP stocke `projets/…` sans
/// slash initial (chemin relatif au dossier, voisin des scripts), ce qui ne
/// résout plus depuis `/projects/<id>`. On rétablit donc un chemin absolu.
pub fn web_path(value: &str) -> String {
    let trimmed = value.trim();
    if trimmed.is_empty()
        || trimmed.starts_with('/')
        || trimmed.starts_with("http://")
        || trimmed.starts_with("https://")
    {
        return trimmed.to_string();
    }
    format!("/{}", trimmed.replace('\\', "/"))
}

/// Vérifie qu'un projet appartient bien à l'utilisateur.
pub async fn owned_project(pool: &MySqlPool, owner: i64, project_id: i64) -> AppResult<Option<(i64, String)>> {
    Ok(sqlx::query_as(PROJECT_OF_OWNER)
        .bind(project_id)
        .bind(owner)
        .fetch_optional(pool)
        .await?)
}

// ---------------------------------------------------------------------- Listing

async fn listing(pool: &MySqlPool, owner: i64) -> AppResult<Listing> {
    let rows: Vec<ProjectRow> = sqlx::query_as(
        "SELECT p.id, p.name, p.description, p.image_path, p.status, \
                DATE_FORMAT(p.created_at, '%d/%m/%Y') AS created_label, \
                COUNT(pc.id) AS component_count, \
                CAST(COALESCE(SUM(pc.quantity_needed), 0) AS SIGNED) AS total_needed, \
                CAST(COALESCE(SUM(pc.quantity_used), 0) AS SIGNED) AS total_used \
         FROM projects p \
         LEFT JOIN project_components pc ON pc.project_id = p.id \
         WHERE p.owner = ? \
         GROUP BY p.id, p.name, p.description, p.image_path, p.status, p.created_at \
         ORDER BY p.created_at DESC",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;

    let mut projects = Vec::with_capacity(rows.len());
    let mut total_components = 0_i64;
    let mut active = 0_i64;
    let mut finished = 0_i64;

    for row in &rows {
        let status = row.status.clone().unwrap_or_else(|| "En cours".to_string());
        total_components += row.component_count;
        if status == "Terminé" {
            finished += 1;
        } else if status == "En cours" {
            active += 1;
        }

        let progress = if row.total_needed > 0 {
            ((row.total_used as f64 / row.total_needed as f64) * 100.0).min(100.0).round() as i64
        } else {
            0
        };

        projects.push(json!({
            "id": row.id,
            "name": row.name,
            "description": row.description.clone().unwrap_or_default(),
            "image_path": web_path(row.image_path.as_deref().unwrap_or_default()),
            "status": status,
            "status_key": status_key(&status),
            "created": row.created_label.clone().unwrap_or_default(),
            "components": row.component_count,
            "total_needed": row.total_needed,
            "total_used": row.total_used,
            "progress": progress,
            "folder": safe_folder(&row.name),
        }));
    }

    let stats = json!({
        "projects": projects.len(),
        "components": total_components,
        "active": active,
        "finished": finished,
    });

    Ok(Listing { projects, stats })
}

// ------------------------------------------------------------------------ Pages

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let mut context = page_context(
        &state,
        &user,
        "projects",
        "Projets",
        "Suivi des réalisations, composants mobilisés et documents associés",
    )
    .await;
    context.insert("projects".to_string(), json!(data.projects));
    context.insert("projects_json".to_string(), json!(data.projects));
    context.insert("stats".to_string(), data.stats);
    context.insert("statuses".to_string(), json!(STATUSES));

    templates::render(&state.templates, "projects.html", Value::Object(context))
}

/// Fragment de liste pour un rafraîchissement sans rechargement.
pub async fn refresh(State(state): State<AppState>, user: CurrentUser) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let context = json!({ "projects": &data.projects });
    let html = templates::render(&state.templates, "_projects_list.html", context)?.0;

    Ok(Json(json!({
        "ok": true,
        "html": html,
        "stats": data.stats,
        "projects": data.projects,
    })))
}

// ---------------------------------------------------------------------- Actions

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

#[derive(Debug, Deserialize)]
pub struct ProjectForm {
    #[serde(default)]
    pub project_id: i64,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub description: String,
    #[serde(default)]
    pub status: String,
}

pub async fn create(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<ProjectForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clip(form.name.trim(), MAX_NAME);
    if name.is_empty() {
        return Ok(failure("Le nom du projet est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM projects WHERE owner = ? AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1",
    )
    .bind(user.id)
    .bind(&name)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Un projet porte déjà ce nom."));
    }

    let status = normalize_status(&form.status);
    let description = form.description.trim();
    let result = sqlx::query("INSERT INTO projects (owner, name, description, status) VALUES (?, ?, ?, ?)")
        .bind(user.id)
        .bind(&name)
        .bind(if description.is_empty() {
            None
        } else {
            Some(clip(description, MAX_DESCRIPTION))
        })
        .bind(&status)
        .execute(&pool)
        .await?;

    let project_id = result.last_insert_id() as i64;
    let folder = projects_dir(&state.root).join(safe_folder(&name));
    if let Err(error) = std::fs::create_dir_all(&folder) {
        tracing::warn!("Création de {} impossible : {error}", folder.display());
    }

    tracing::info!("Projet « {name} » créé (id {project_id}) par {}", user.email);
    Ok(Json(json!({ "ok": true, "project_id": project_id })))
}

/// Met à jour le nom, la description et le statut d'un projet.
pub async fn update(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<ProjectForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some((_, old_name)) = owned_project(&pool, user.id, form.project_id).await? else {
        return Ok(failure("Projet introuvable."));
    };

    let name = clip(form.name.trim(), MAX_NAME);
    if name.is_empty() {
        return Ok(failure("Le nom du projet est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM projects WHERE owner = ? AND LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1",
    )
    .bind(user.id)
    .bind(&name)
    .bind(form.project_id)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Un projet porte déjà ce nom."));
    }

    let status = normalize_status(&form.status);
    let description = form.description.trim();
    sqlx::query("UPDATE projects SET name = ?, description = ?, status = ? WHERE id = ? AND owner = ?")
        .bind(&name)
        .bind(if description.is_empty() {
            None
        } else {
            Some(clip(description, MAX_DESCRIPTION))
        })
        .bind(&status)
        .bind(form.project_id)
        .bind(user.id)
        .execute(&pool)
        .await?;

    // Le dossier suit le nom du projet, comme dans la version PHP.
    if old_name != name {
        let base = projects_dir(&state.root);
        let previous = base.join(safe_folder(&old_name));
        let next = base.join(safe_folder(&name));
        if previous.is_dir() && !next.exists() {
            let _ = std::fs::rename(&previous, &next);
        }
        let _ = std::fs::create_dir_all(&next);
        // Préfixe sans slash initial : il correspond aussi bien aux chemins
        // hérités du PHP (`projets/ancien/…`) qu'aux nouveaux (`/projets/…`).
        let prefix = format!("projets/{}/", safe_folder(&old_name));
        let replacement = format!("projets/{}/", safe_folder(&name));
        sqlx::query("UPDATE projects SET image_path = REPLACE(image_path, ?, ?) WHERE id = ? AND owner = ?")
            .bind(&prefix)
            .bind(&replacement)
            .bind(form.project_id)
            .bind(user.id)
            .execute(&pool)
            .await?;
        sqlx::query("UPDATE project_files SET file_path = REPLACE(file_path, ?, ?) WHERE project_id = ?")
            .bind(&prefix)
            .bind(&replacement)
            .bind(form.project_id)
            .execute(&pool)
            .await?;
    }

    tracing::info!("Projet « {name} » mis à jour par {}", user.email);
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct ProjectIdForm {
    #[serde(default)]
    pub project_id: i64,
}

/// Supprime un projet, ses liaisons, ses fichiers et son dossier.
pub async fn delete(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<ProjectIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some((_, name)) = owned_project(&pool, user.id, form.project_id).await? else {
        return Ok(failure("Projet introuvable."));
    };

    let mut tx = pool.begin().await?;
    for statement in [
        "DELETE FROM project_components WHERE project_id = ?",
        "DELETE FROM project_items WHERE project_id = ?",
        "DELETE FROM project_files WHERE project_id = ?",
    ] {
        sqlx::query(statement)
            .bind(form.project_id)
            .execute(&mut *tx)
            .await?;
    }
    sqlx::query("DELETE FROM projects WHERE id = ? AND owner = ?")
        .bind(form.project_id)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;

    remove_project_folder(&state.root, &name);

    tracing::info!("Projet « {name} » supprimé par {}", user.email);
    Ok(Json(json!({ "ok": true })))
}

// ------------------------------------------------------------------- Multipart

/// Lit un corps `multipart/form-data` en séparant champs texte et fichiers.
pub async fn read_multipart(
    mut multipart: Multipart,
) -> AppResult<(HashMap<String, String>, HashMap<String, (String, Vec<u8>)>)> {
    let mut fields = HashMap::new();
    let mut files = HashMap::new();

    while let Some(field) = multipart
        .next_field()
        .await
        .map_err(|error| AppError::Message(format!("Formulaire illisible : {error}")))?
    {
        let name = field.name().unwrap_or_default().to_string();
        if name.is_empty() {
            continue;
        }
        let file_name = field.file_name().map(|value| value.to_string());
        let data = field
            .bytes()
            .await
            .map_err(|error| AppError::Message(format!("Envoi interrompu : {error}")))?;

        match file_name {
            Some(file_name) if !data.is_empty() => {
                files.insert(name, (file_name, data.to_vec()));
            }
            _ => {
                fields.insert(name, String::from_utf8_lossy(&data).to_string());
            }
        }
    }

    Ok((fields, files))
}

/// Extension de fichier sûre (minuscules, alphanumérique).
pub fn safe_extension(file_name: &str) -> String {
    let extension = std::path::Path::new(file_name)
        .extension()
        .and_then(|value| value.to_str())
        .unwrap_or("bin")
        .to_ascii_lowercase();
    let sanitized: String = extension
        .chars()
        .filter(|character| character.is_ascii_alphanumeric())
        .collect();
    if sanitized.is_empty() {
        "bin".to_string()
    } else {
        sanitized
    }
}

/// Écrit un fichier dans le dossier du projet et renvoie son chemin stocké en
/// base (`projets/<dossier>/<fichier>`, sans slash initial), au même format que
/// la version PHP — `web_path` rétablit le slash au moment de l'affichage.
pub fn store_project_file(root: &Path, project_name: &str, file_name: &str, data: &[u8]) -> Option<String> {
    let folder = safe_folder(project_name);
    let directory = projects_dir(root).join(&folder);
    if std::fs::create_dir_all(&directory).is_err() {
        return None;
    }
    let path = directory.join(file_name);
    std::fs::write(&path, data).ok()?;
    Some(format!("projets/{folder}/{file_name}"))
}

/// Chemin disque correspondant à un chemin stocké `projets/...` (slash initial
/// facultatif).
pub fn local_project_path(root: &Path, relative: &str) -> Option<PathBuf> {
    let trimmed = relative.trim_start_matches(['/', '\\']);
    if !trimmed.starts_with("projets/") || trimmed.contains("..") {
        return None;
    }
    Some(root.join(trimmed.replace('\\', "/")))
}
