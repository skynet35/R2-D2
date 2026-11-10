//! Bibliothèque d'images : inventaire du dossier `img/`.
//!
//! Le dossier `img/` est partagé par toute l'application (images de composants
//! et de boîtiers, logos de fournisseurs, de clients et de casiers, images de
//! projets) : cet écran est donc global, sans filtrage par propriétaire. Il sert
//! à voir ce qui est stocké, à repérer ce qui ne va pas — fichiers illisibles
//! (page HTML d'un CDN enregistrée sous une extension d'image) et références en
//! base qui ne pointent sur aucun fichier — puis à renommer, supprimer ou
//! ajouter des images.
//!
//! Le renommage et la suppression répercutent l'opération sur les colonnes
//! d'image des six tables concernées, afin qu'aucune fiche ne reste pointée sur
//! un fichier absent.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::time::SystemTime;

use axum::extract::{Multipart, Query, State};
use axum::response::{Html, Json};
use axum::Form;
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{AssertSqlSafe, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;
use crate::util;

use super::page_context;
use super::project_detail::human_size;
use super::projects::read_multipart;

/// Extensions admises pour un fichier déposé dans `img/`.
const IMAGE_EXTENSIONS: &[&str] = &["jpg", "jpeg", "png", "gif", "svg", "webp"];

/// Taille maximale d'une image déposée (alignée sur les autres écrans).
const MAX_IMAGE_BYTES: usize = 10 * 1024 * 1024;

/// Colonnes qui référencent un fichier de `img/`. `suppliers.logo_url` en est
/// écartée : elle ne contient que des adresses distantes (`http…`).
const REFERENCES: &[(&str, &str)] = &[
    ("data", "image_path"),
    ("packages", "image_path"),
    ("projects", "image_path"),
    ("suppliers", "logo_path"),
    ("location", "logo_path"),
    ("clients", "logo_path"),
];

// ------------------------------------------------------------------ Inventaire

/// Nom de fichier saisi par le navigateur : refuse séparateurs et remontée de
/// dossier, pour qu'aucune action ne sorte du dossier `img/`.
fn plain_file_name(value: &str) -> Option<String> {
    let trimmed = value.trim();
    if trimmed.is_empty() || trimmed.chars().count() > 255 {
        return None;
    }
    if trimmed.contains('/') || trimmed.contains('\\') || trimmed.contains("..") {
        return None;
    }
    Some(trimmed.to_string())
}

/// Extension d'un nom de fichier, en minuscules.
fn file_extension(file_name: &str) -> String {
    file_name
        .rsplit_once('.')
        .map(|(_, extension)| extension.to_ascii_lowercase())
        .unwrap_or_default()
}

/// Vrai si l'extension fait partie des formats d'image reconnus.
fn has_image_extension(file_name: &str) -> bool {
    IMAGE_EXTENSIONS.contains(&file_extension(file_name).as_str())
}

/// Nom du fichier de `img/` désigné par une valeur de colonne, en minuscules.
///
/// Les deux conventions cohabitent en base : `img/<nom>` (`data.image_path`) et
/// `/img/<nom>` (`packages.image_path`, hérité du PHP). Une adresse distante ne
/// désigne aucun fichier local et renvoie `None`.
fn referenced_file(value: &str) -> Option<String> {
    let trimmed = value.trim();
    if trimmed.is_empty() || trimmed.starts_with("http://") || trimmed.starts_with("https://") {
        return None;
    }
    let normalized = trimmed.trim_start_matches('/').replace('\\', "/");
    let relative = normalized.strip_prefix("img/")?;
    let file_name = relative.rsplit('/').next()?;
    if file_name.is_empty() {
        None
    } else {
        Some(file_name.to_ascii_lowercase())
    }
}

/// Date de dernière modification d'un fichier, au format `jj/mm/aaaa hh:mm`.
fn date_label(modified: Option<SystemTime>) -> String {
    let Some(time) = modified else {
        return "—".to_string();
    };
    let moment = sqlx::types::chrono::DateTime::<sqlx::types::chrono::Local>::from(time);
    moment.format("%d/%m/%Y %H:%M").to_string()
}

/// Une valeur de colonne d'image relevée en base, regroupée par valeur exacte.
struct ReferenceHit {
    table: &'static str,
    column: &'static str,
    value: String,
    count: i64,
}

/// Contenu du dossier `img/` confronté aux références de la base.
struct Inventory {
    images: Vec<Value>,
    missing: Vec<Value>,
    stats: Value,
}

async fn inventory(pool: &MySqlPool, root: &Path) -> AppResult<Inventory> {
    let directory = root.join("img");

    // Toutes les valeurs d'image de la base, colonne par colonne : six requêtes
    // agrégées plutôt qu'un relevé par fichier.
    let mut references: Vec<ReferenceHit> = Vec::new();
    for (table, column) in REFERENCES {
        let statement = format!(
            "SELECT {column} AS value, COUNT(*) AS total FROM {table} \
             WHERE {column} IS NOT NULL AND {column} <> '' GROUP BY {column}"
        );
        let rows: Vec<(String, i64)> = sqlx::query_as(AssertSqlSafe(statement))
            .fetch_all(pool)
            .await?;
        for (value, count) in rows {
            references.push(ReferenceHit {
                table,
                column,
                value,
                count,
            });
        }
    }

    let mut usage: BTreeMap<String, i64> = BTreeMap::new();
    for reference in &references {
        if let Some(file_name) = referenced_file(&reference.value) {
            *usage.entry(file_name).or_insert(0) += reference.count;
        }
    }

    // Tous les fichiers présents, images reconnues ou non.
    let mut files: BTreeMap<String, PathBuf> = BTreeMap::new();
    if let Ok(entries) = std::fs::read_dir(&directory) {
        for entry in entries.flatten() {
            let path = entry.path();
            if !path.is_file() {
                continue;
            }
            let Some(file_name) = path.file_name().and_then(|value| value.to_str()) else {
                continue;
            };
            files.insert(file_name.to_ascii_lowercase(), path);
        }
    }

    let mut images = Vec::with_capacity(files.len());
    let mut total_size = 0_i64;
    let mut broken = 0_i64;
    let mut unused = 0_i64;

    for (key, path) in &files {
        let file_name = path
            .file_name()
            .and_then(|value| value.to_str())
            .unwrap_or_default()
            .to_string();
        let metadata = std::fs::metadata(path).ok();
        let size = metadata.as_ref().map(|meta| meta.len() as i64).unwrap_or(0);
        let modified = metadata.as_ref().and_then(|meta| meta.modified().ok());
        // Le contenu est vérifié, pas seulement l'extension : une page HTML
        // d'erreur enregistrée sous `.svg` est signalée comme illisible.
        let readable = util::is_image_file(path);
        let used = usage.get(key).copied().unwrap_or(0);
        let state = if !readable {
            broken += 1;
            "broken"
        } else if used == 0 {
            unused += 1;
            "unused"
        } else {
            "used"
        };

        total_size += size;
        images.push(json!({
            "filename": file_name,
            "name": file_name.rsplit_once('.').map(|(stem, _)| stem).unwrap_or(&file_name),
            "extension": file_extension(&file_name),
            "size": size,
            "size_label": human_size(size),
            "modified": date_label(modified),
            "readable": readable,
            "used": used,
            "state": state,
            "src": format!("/img/{file_name}"),
        }));
    }

    // Références pointant sur un fichier absent : ce qui se traduit par une
    // vignette cassée dans l'application.
    let mut missing: Vec<Value> = references
        .iter()
        .filter_map(|reference| {
            let file_name = referenced_file(&reference.value)?;
            if files.contains_key(&file_name) {
                return None;
            }
            Some(json!({
                "table": reference.table,
                "column": reference.column,
                "value": reference.value,
                "count": reference.count,
            }))
        })
        .collect();
    missing.sort_by(|left, right| {
        right["count"]
            .as_i64()
            .unwrap_or(0)
            .cmp(&left["count"].as_i64().unwrap_or(0))
    });

    let missing_refs: i64 = missing
        .iter()
        .map(|entry| entry["count"].as_i64().unwrap_or(0))
        .sum();

    let stats = json!({
        "files": files.len(),
        "size_label": human_size(total_size),
        "broken": broken,
        "unused": unused,
        "missing": missing.len(),
        "missing_refs": missing_refs,
    });

    Ok(Inventory {
        images,
        missing,
        stats,
    })
}

// ------------------------------------------------------------------- Affichage

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let inventory = inventory(&pool, &state.root).await?;

    let mut context = page_context(
        &state,
        &user,
        "images",
        "Images",
        "Bibliothèque du dossier img/ — diagnostic, renommage, suppression et ajout",
    )
    .await;
    context.insert("images".to_string(), json!(inventory.images));
    context.insert("missing".to_string(), json!(inventory.missing));
    context.insert("stats".to_string(), inventory.stats);

    templates::render(&state.templates, "images.html", Value::Object(context))
}

/// Fragment de la bibliothèque, pour un rafraîchissement sans rechargement.
pub async fn refresh(State(state): State<AppState>, user: CurrentUser) -> AppResult<Json<Value>> {
    let _ = user;
    let pool = state.require_pool().await?;
    let inventory = inventory(&pool, &state.root).await?;

    let context = json!({
        "images": &inventory.images,
        "missing": &inventory.missing,
    });
    let html = templates::render(&state.templates, "_images_list.html", context)?.0;

    Ok(Json(json!({
        "ok": true,
        "html": html,
        "stats": inventory.stats,
    })))
}

#[derive(Debug, Deserialize)]
pub struct ReferenceParams {
    filename: Option<String>,
}

/// Détail des références en base pour un fichier : alimente le message de
/// confirmation avant suppression.
pub async fn references(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<ReferenceParams>,
) -> AppResult<Json<Value>> {
    let _ = user;
    let pool = state.require_pool().await?;
    let Some(file_name) = params.filename.as_deref().and_then(plain_file_name) else {
        return Ok(Json(json!({ "ok": false, "error": "Nom de fichier invalide." })));
    };

    let (total, details) = reference_hits(&pool, &file_name).await?;
    Ok(Json(json!({ "ok": true, "total": total, "references": details })))
}

/// Nombre de fiches qui pointent sur `img/<fichier>` ou `/img/<fichier>`,
/// colonne par colonne.
async fn reference_hits(pool: &MySqlPool, file_name: &str) -> AppResult<(i64, Vec<Value>)> {
    let plain = format!("img/{file_name}");
    let slashed = format!("/img/{file_name}");
    let mut total = 0_i64;
    let mut details = Vec::new();

    for (table, column) in REFERENCES {
        let statement = format!("SELECT COUNT(*) FROM {table} WHERE {column} IN (?, ?)");
        let count: i64 = sqlx::query_scalar(AssertSqlSafe(statement))
            .bind(&plain)
            .bind(&slashed)
            .fetch_one(pool)
            .await?;
        if count > 0 {
            total += count;
            details.push(json!({ "table": table, "column": column, "count": count }));
        }
    }

    Ok((total, details))
}

// -------------------------------------------------------------------- Actions

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

/// Ajoute un fichier à `img/`. L'extension retenue est celle déduite du
/// contenu : un `.jpg` qui contient en réalité un PNG est enregistré en `.png`.
pub async fn upload(
    State(state): State<AppState>,
    user: CurrentUser,
    multipart: Multipart,
) -> AppResult<Json<Value>> {
    let (inputs, mut files) = read_multipart(multipart).await?;

    let Some((original, data)) = files.remove("image") else {
        return Ok(failure("Aucun fichier reçu."));
    };
    if data.len() > MAX_IMAGE_BYTES {
        return Ok(failure("Fichier trop volumineux : 10 Mo au maximum."));
    }
    if !has_image_extension(&original) {
        return Ok(failure(
            "Format non pris en charge : jpg, jpeg, png, gif, svg ou webp attendu.",
        ));
    }
    let Some(extension) = util::detect_image_extension(&data) else {
        return Ok(failure(&format!(
            "« {original} » n'est pas une image lisible (le contenu ne correspond à aucun format)."
        )));
    };

    let wanted = inputs.get("name").map(String::as_str).unwrap_or("");
    let stem = if wanted.trim().is_empty() {
        original
            .rsplit_once('.')
            .map(|(stem, _)| stem.to_string())
            .unwrap_or_else(|| original.clone())
    } else {
        wanted.trim().to_string()
    };
    let base = util::safe_file_name(&stem);
    if base.is_empty() {
        return Ok(failure("Le nom du fichier est obligatoire."));
    }

    let directory = state.root.join("img");
    if std::fs::create_dir_all(&directory).is_err() {
        return Ok(failure("Dossier img/ inaccessible en écriture."));
    }
    let file_name = format!("{base}.{extension}");
    if directory.join(&file_name).exists() {
        return Ok(failure(&format!(
            "« {file_name} » existe déjà : renommez l'image ou supprimez l'ancienne."
        )));
    }
    if std::fs::write(directory.join(&file_name), &data).is_err() {
        return Ok(failure("Écriture du fichier impossible."));
    }

    tracing::info!("Image « {file_name} » ajoutée par {}", user.email);
    Ok(Json(json!({ "ok": true, "filename": file_name })))
}

#[derive(Debug, Deserialize)]
pub struct RenameForm {
    #[serde(default)]
    pub filename: String,
    #[serde(default)]
    pub name: String,
}

/// Renomme un fichier de `img/` et répercute le nouveau nom sur les fiches qui
/// le référençaient.
pub async fn rename(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<RenameForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some(old_name) = plain_file_name(&form.filename) else {
        return Ok(failure("Nom de fichier invalide."));
    };

    let directory = state.root.join("img");
    let source = directory.join(&old_name);
    if !source.is_file() {
        return Ok(failure(&format!("« {old_name} » est introuvable.")));
    }

    // L'extension du fichier est conservée : elle est déduite de son contenu et
    // non de la saisie. Un suffixe identique tapé par erreur est ignoré.
    let extension = file_extension(&old_name);
    let mut base = form.name.trim().to_string();
    if !extension.is_empty() {
        let suffix = format!(".{extension}");
        if base.len() > suffix.len() && base.to_ascii_lowercase().ends_with(&suffix) {
            base.truncate(base.len() - suffix.len());
        }
    }
    let base = util::safe_file_name(&base);
    if base.is_empty() {
        return Ok(failure("Le nouveau nom est obligatoire."));
    }

    let old_stem = old_name.rsplit_once('.').map(|(stem, _)| stem).unwrap_or(&old_name);
    if base == old_stem {
        return Ok(failure("Le nouveau nom est identique à l'ancien."));
    }

    let new_name = if extension.is_empty() {
        base.clone()
    } else {
        format!("{base}.{extension}")
    };
    let target = directory.join(&new_name);
    if target.exists() {
        return Ok(failure(&format!("« {new_name} » existe déjà.")));
    }
    if let Err(error) = std::fs::rename(&source, &target) {
        tracing::warn!("Renommage de « {old_name} » impossible : {error}");
        return Ok(failure("Renommage impossible sur le disque."));
    }

    let updated = replace_references(&pool, &old_name, &new_name).await?;

    tracing::info!(
        "Image « {old_name} » renommée en « {new_name} » par {} ({updated} référence(s) mise(s) à jour)",
        user.email
    );
    Ok(Json(json!({
        "ok": true,
        "filename": new_name,
        "references": updated,
    })))
}

/// Remplace `img/<ancien>` et `/img/<ancien>` par le nouveau nom dans toutes
/// les colonnes d'image. Renvoie le nombre de fiches mises à jour.
async fn replace_references(pool: &MySqlPool, old_name: &str, new_name: &str) -> AppResult<u64> {
    let old_plain = format!("img/{old_name}");
    let old_slashed = format!("/img/{old_name}");
    let new_plain = format!("img/{new_name}");
    let new_slashed = format!("/img/{new_name}");
    let mut updated = 0_u64;

    for (table, column) in REFERENCES {
        let statement = format!(
            "UPDATE {table} SET {column} = CASE {column} WHEN ? THEN ? ELSE ? END \
             WHERE {column} IN (?, ?)"
        );
        let result = sqlx::query(AssertSqlSafe(statement))
            .bind(&old_plain)
            .bind(&new_plain)
            .bind(&new_slashed)
            .bind(&old_plain)
            .bind(&old_slashed)
            .execute(pool)
            .await?;
        updated += result.rows_affected();
    }

    Ok(updated)
}

#[derive(Debug, Deserialize)]
pub struct DeleteForm {
    #[serde(default)]
    pub filename: String,
    /// « 1 » pour détacher les fiches qui pointent sur l'image supprimée.
    #[serde(default)]
    pub detach: Option<String>,
}

/// Supprime un fichier de `img/`. Si des fiches le référencent, l'opération
/// n'aboutit que si elles sont détachées en même temps : une image supprimée
/// alors que des fiches la réclament s'afficherait cassée partout.
pub async fn delete(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<DeleteForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some(file_name) = plain_file_name(&form.filename) else {
        return Ok(failure("Nom de fichier invalide."));
    };

    let path = state.root.join("img").join(&file_name);
    if !path.is_file() {
        return Ok(failure(&format!("« {file_name} » est introuvable.")));
    }

    let (total, details) = reference_hits(&pool, &file_name).await?;
    let detach = form
        .detach
        .as_deref()
        .is_some_and(|value| !value.is_empty() && value != "0");

    if total > 0 && !detach {
        return Ok(Json(json!({
            "ok": false,
            "error": format!(
                "« {file_name} » est encore utilisée par {total} fiche(s) : cochez le détachement pour la supprimer."
            ),
            "references": details,
            "total": total,
        })));
    }

    if total > 0 {
        let plain = format!("img/{file_name}");
        let slashed = format!("/img/{file_name}");
        for (table, column) in REFERENCES {
            let statement = format!("UPDATE {table} SET {column} = NULL WHERE {column} IN (?, ?)");
            sqlx::query(AssertSqlSafe(statement))
                .bind(&plain)
                .bind(&slashed)
                .execute(&pool)
                .await?;
        }
    }

    if let Err(error) = std::fs::remove_file(&path) {
        tracing::warn!("Suppression de « {file_name} » impossible : {error}");
        return Ok(failure("Suppression impossible sur le disque."));
    }

    tracing::info!(
        "Image « {file_name} » supprimée par {} ({total} référence(s) détachée(s))",
        user.email
    );
    Ok(Json(json!({ "ok": true, "detached": total })))
}
