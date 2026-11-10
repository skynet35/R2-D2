//! Fabricants (marques de composants).
//!
//! Reprend `manufacturers.php` : la liste est dérivée de la table
//! `manufacturers`, le nombre de composants est compté dans `data` par
//! correspondance de nom, et le nom sert de clé de rapprochement. Un renommage
//! propage donc la nouvelle valeur sur les composants, une suppression les
//! détache (fabricant vidé) avant de retirer la ligne.
//!
//! Les actions sont appelées en arrière-plan et la liste est remplacée par le
//! fragment `_manufacturers_list.html`, sans rechargement de page.

use axum::extract::State;
use axum::response::Html;
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;

const MAX_NAME: usize = 255;

#[derive(Debug, FromRow)]
struct ManufacturerRow {
    id: i64,
    name: Option<String>,
    component_count: i64,
}

struct Listing {
    manufacturers: Vec<Value>,
    stats: Value,
}

fn clip(value: &str, max: usize) -> String {
    value.chars().take(max).collect()
}

fn clean_name(value: &str) -> String {
    clip(value.trim(), MAX_NAME)
}

/// Fabricants de l'utilisateur, avec leur nombre de composants.
async fn listing(pool: &MySqlPool, owner: i64) -> AppResult<Listing> {
    let rows: Vec<ManufacturerRow> = sqlx::query_as(
        "SELECT m.id, m.name, COUNT(d.id) AS component_count \
         FROM manufacturers m \
         LEFT JOIN data d ON d.manufacturer = m.name AND d.owner = m.owner \
         WHERE m.owner = ? \
         GROUP BY m.id, m.name \
         ORDER BY m.name",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;

    let unattached: (i64,) = sqlx::query_as(
        "SELECT COUNT(*) FROM data \
         WHERE owner = ? AND (manufacturer IS NULL OR TRIM(manufacturer) = '')",
    )
    .bind(owner)
    .fetch_one(pool)
    .await?;

    let mut linked = 0_i64;
    let mut manufacturers = Vec::with_capacity(rows.len());
    for row in &rows {
        linked += row.component_count;
        manufacturers.push(json!({
            "id": row.id,
            "name": row.name.clone().unwrap_or_default(),
            "components": row.component_count,
        }));
    }

    let stats = json!({
        "manufacturers": manufacturers.len(),
        "linked": linked,
        "unattached": unattached.0,
    });

    Ok(Listing {
        manufacturers,
        stats,
    })
}

// ------------------------------------------------------------------- Listing

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let mut context = page_context(
        &state,
        &user,
        "manufacturers",
        "Fabricants",
        "Référencement des marques de composants électroniques",
    )
    .await;
    context.insert("manufacturers".to_string(), json!(data.manufacturers));
    context.insert("stats".to_string(), data.stats);

    templates::render(&state.templates, "manufacturers.html", Value::Object(context))
}

/// Fragment de liste utilisé pour un rafraîchissement sans rechargement.
pub async fn refresh(State(state): State<AppState>, user: CurrentUser) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let context = json!({ "manufacturers": &data.manufacturers });
    let html = templates::render(&state.templates, "_manufacturers_list.html", context)?.0;

    Ok(Json(json!({
        "ok": true,
        "html": html,
        "stats": data.stats,
    })))
}

// ------------------------------------------------------------------- Actions

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

#[derive(Debug, Deserialize)]
pub struct ManufacturerForm {
    #[serde(default)]
    pub name: String,
}

pub async fn add(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<ManufacturerForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.name);
    if name.is_empty() {
        return Ok(failure("Le nom du fabricant est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM manufacturers WHERE owner = ? AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1",
    )
    .bind(user.id)
    .bind(&name)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Ce fabricant existe déjà."));
    }

    sqlx::query("INSERT INTO manufacturers (name, owner) VALUES (?, ?)")
        .bind(&name)
        .bind(user.id)
        .execute(&pool)
        .await?;

    tracing::info!("Fabricant « {name} » créé par {}", user.email);
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct RenameManufacturerForm {
    #[serde(default)]
    pub manufacturer_id: i64,
    #[serde(default)]
    pub new_name: String,
}

/// Renomme un fabricant et répercute le nom sur ses composants.
pub async fn rename(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<RenameManufacturerForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.new_name);
    if form.manufacturer_id <= 0 || name.is_empty() {
        return Ok(failure("Le nom du fabricant est obligatoire."));
    }

    let current: Option<(Option<String>,)> =
        sqlx::query_as("SELECT name FROM manufacturers WHERE id = ? AND owner = ? LIMIT 1")
            .bind(form.manufacturer_id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;
    let Some((Some(old_name),)) = current else {
        return Ok(failure("Fabricant introuvable."));
    };

    if old_name.eq_ignore_ascii_case(&name) {
        return Ok(Json(json!({ "ok": true })));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM manufacturers WHERE owner = ? AND LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1",
    )
    .bind(user.id)
    .bind(&name)
    .bind(form.manufacturer_id)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Un fabricant avec ce nom existe déjà."));
    }

    let mut tx = pool.begin().await?;
    sqlx::query("UPDATE manufacturers SET name = ? WHERE id = ? AND owner = ?")
        .bind(&name)
        .bind(form.manufacturer_id)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    sqlx::query("UPDATE data SET manufacturer = ? WHERE manufacturer = ? AND owner = ?")
        .bind(&name)
        .bind(&old_name)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;

    tracing::info!(
        "Fabricant « {old_name} » renommé en « {name} » par {}",
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct ManufacturerIdForm {
    #[serde(default)]
    pub manufacturer_id: i64,
}

/// Supprime un fabricant et détache ses composants.
pub async fn delete(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<ManufacturerIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if form.manufacturer_id <= 0 {
        return Ok(failure("Fabricant introuvable."));
    }

    let current: Option<(Option<String>,)> =
        sqlx::query_as("SELECT name FROM manufacturers WHERE id = ? AND owner = ? LIMIT 1")
            .bind(form.manufacturer_id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;
    let Some((Some(old_name),)) = current else {
        return Ok(failure("Fabricant introuvable."));
    };

    let mut tx = pool.begin().await?;
    sqlx::query("UPDATE data SET manufacturer = NULL WHERE manufacturer = ? AND owner = ?")
        .bind(&old_name)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    sqlx::query("DELETE FROM manufacturers WHERE id = ? AND owner = ?")
        .bind(form.manufacturer_id)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;

    tracing::info!("Fabricant « {old_name} » supprimé par {}", user.email);
    Ok(Json(json!({ "ok": true })))
}
