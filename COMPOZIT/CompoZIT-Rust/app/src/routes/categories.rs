//! Catégories et sous-catégories de composants.
//!
//! L'arborescence reprend celle de `categories_management.php` : les
//! sous-catégories portent un identifiant hiérarchique
//! (`catégorie parente × 100 + rang`), une catégorie ne se supprime que si elle
//! est vide, et une sous-catégorie utilisée par des composants ne peut être que
//! renommée.
//!
//! Les actions sont appelées en arrière-plan : la liste est rafraîchie par le
//! fragment `_categories_list.html`, ce qui évite tout rechargement de page.

use std::collections::HashMap;

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
struct HeadRow {
    id: i64,
    name: Option<String>,
}

#[derive(Debug, FromRow)]
struct SubRow {
    id: i64,
    name: Option<String>,
    category_head_id: Option<i64>,
    component_count: i64,
}

/// Palier d'occupation d'une sous-catégorie (mêmes seuils que la version PHP).
fn level(count: i64) -> &'static str {
    match count {
        0 => "empty",
        1..=2 => "low",
        3..=7 => "medium",
        _ => "full",
    }
}

fn clip(value: &str, max: usize) -> String {
    value.chars().take(max).collect()
}

/// Identifiant de la prochaine sous-catégorie : `parent × 100 + premier rang libre`.
fn next_sub_id(existing: &[i64], parent: i64) -> i64 {
    let base = parent * 100;
    let mut used: Vec<i64> = existing
        .iter()
        .filter(|id| **id > base && **id <= base + 99)
        .map(|id| id - base)
        .collect();
    used.sort_unstable();

    let mut next = 1;
    for suffix in used {
        if suffix == next {
            next += 1;
        } else if suffix > next {
            break;
        }
    }

    if next <= 99 {
        base + next
    } else {
        existing.iter().copied().max().unwrap_or(base + 99) + 1
    }
}

/// Arborescence complète, prête pour le gabarit.
struct Catalogue {
    categories: Vec<Value>,
    sub_ids: HashMap<i64, Vec<i64>>,
    stats: Value,
}

async fn catalogue(pool: &MySqlPool, owner: i64) -> AppResult<Catalogue> {
    let heads: Vec<HeadRow> =
        sqlx::query_as("SELECT id, name FROM category_head ORDER BY name")
            .fetch_all(pool)
            .await?;

    let subs: Vec<SubRow> = sqlx::query_as(
        "SELECT cs.id, cs.name, cs.category_head_id, COUNT(d.id) AS component_count \
         FROM category_sub cs \
         LEFT JOIN data d ON d.category = cs.id AND d.owner = ? \
         GROUP BY cs.id, cs.name, cs.category_head_id \
         ORDER BY cs.id",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;

    let mut grouped: HashMap<i64, Vec<Value>> = HashMap::new();
    let mut sub_ids: HashMap<i64, Vec<i64>> = HashMap::new();
    let mut total_subs = 0_i64;
    let mut total_components = 0_i64;

    for row in &subs {
        let parent = row.category_head_id.unwrap_or(0);
        let count = row.component_count;
        total_subs += 1;
        total_components += count;
        sub_ids.entry(parent).or_default().push(row.id);
        grouped.entry(parent).or_default().push(json!({
            "id": row.id,
            "name": row.name.clone().unwrap_or_default(),
            "components": count,
            "level": level(count),
        }));
    }
    for ids in sub_ids.values_mut() {
        ids.sort_unstable();
    }

    let mut categories = Vec::new();
    for head in &heads {
        let children = grouped.remove(&head.id).unwrap_or_default();
        let subs_count = children.len();
        let components: i64 = children
            .iter()
            .map(|item| item["components"].as_i64().unwrap_or(0))
            .sum();
        categories.push(json!({
            "id": head.id,
            "name": head.name.clone().unwrap_or_default(),
            "subcategories": children,
            "subs": subs_count,
            "components": components,
        }));
    }

    let stats = json!({
        "categories": categories.len(),
        "subcategories": total_subs,
        "components": total_components,
    });

    Ok(Catalogue {
        categories,
        sub_ids,
        stats,
    })
}

// ------------------------------------------------------------------- Listing

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let data = catalogue(&pool, user.id).await?;

    let mut context = page_context(
        &state,
        &user,
        "categories",
        "Catégories",
        "Arborescence des catégories et sous-catégories de composants",
    )
    .await;
    context.insert("categories".to_string(), json!(data.categories));
    context.insert("stats".to_string(), data.stats);
    context.insert("subcat_ids".to_string(), json!(data.sub_ids));

    templates::render(&state.templates, "categories.html", Value::Object(context))
}

/// Fragment de liste : utilisé pour rafraîchir l'affichage sans recharger la page.
pub async fn refresh(State(state): State<AppState>, user: CurrentUser) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let data = catalogue(&pool, user.id).await?;

    let context = json!({ "categories": &data.categories });
    let html = templates::render(&state.templates, "_categories_list.html", context)?.0;

    Ok(Json(json!({
        "ok": true,
        "html": html,
        "stats": data.stats,
        "categories": data.categories,
        "subcat_ids": data.sub_ids,
    })))
}

// ------------------------------------------------------------------- Actions

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

fn clean_name(value: &str) -> String {
    clip(value.trim(), MAX_NAME)
}

#[derive(Debug, Deserialize)]
pub struct CategoryForm {
    #[serde(default)]
    pub name: String,
}

/// Ajoute une catégorie principale.
pub async fn add_category(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<CategoryForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.name);
    if name.is_empty() {
        return Ok(failure("Le nom de la catégorie est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM category_head WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1",
    )
    .bind(&name)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Cette catégorie existe déjà."));
    }

    sqlx::query("INSERT INTO category_head (name) VALUES (?)")
        .bind(&name)
        .execute(&pool)
        .await?;

    tracing::info!("Catégorie « {name} » créée par {}", user.email);
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct SubcategoryForm {
    #[serde(default)]
    pub subcategory_name: String,
    #[serde(default)]
    pub parent_category: String,
}

/// Ajoute une sous-catégorie ; l'identifiant suit la règle de la version PHP.
pub async fn add_subcategory(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<SubcategoryForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.subcategory_name);
    let parent: i64 = form.parent_category.trim().parse().unwrap_or(0);

    if name.is_empty() || parent <= 0 {
        return Ok(failure(
            "Le nom de la sous-catégorie et la catégorie parent sont obligatoires.",
        ));
    }

    let parent_exists: Option<(i64,)> =
        sqlx::query_as("SELECT id FROM category_head WHERE id = ? LIMIT 1")
            .bind(parent)
            .fetch_optional(&pool)
            .await?;
    if parent_exists.is_none() {
        return Ok(failure("Catégorie parent introuvable."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM category_sub WHERE category_head_id = ? AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1",
    )
    .bind(parent)
    .bind(&name)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure(
            "Une sous-catégorie portant ce nom existe déjà dans cette catégorie.",
        ));
    }

    let existing: Vec<(i64,)> =
        sqlx::query_as("SELECT id FROM category_sub WHERE category_head_id = ? ORDER BY id")
            .bind(parent)
            .fetch_all(&pool)
            .await?;
    let ids: Vec<i64> = existing.into_iter().map(|row| row.0).collect();
    let new_id = next_sub_id(&ids, parent);

    sqlx::query("INSERT INTO category_sub (id, name, category_head_id) VALUES (?, ?, ?)")
        .bind(new_id)
        .bind(&name)
        .bind(parent)
        .execute(&pool)
        .await?;

    tracing::info!("Sous-catégorie {new_id} « {name} » créée par {}", user.email);
    Ok(Json(json!({ "ok": true, "id": new_id })))
}

#[derive(Debug, Deserialize)]
pub struct RenameCategoryForm {
    #[serde(default)]
    pub category_id: i64,
    #[serde(default)]
    pub new_name: String,
}

pub async fn rename_category(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<RenameCategoryForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.new_name);
    if form.category_id <= 0 || name.is_empty() {
        return Ok(failure("Le nom de la catégorie est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM category_head WHERE LOWER(TRIM(name)) = LOWER(?) AND id <> ? LIMIT 1",
    )
    .bind(&name)
    .bind(form.category_id)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure("Une autre catégorie porte déjà ce nom."));
    }

    let result = sqlx::query("UPDATE category_head SET name = ? WHERE id = ?")
        .bind(&name)
        .bind(form.category_id)
        .execute(&pool)
        .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Catégorie introuvable."));
    }

    tracing::info!(
        "Catégorie {} renommée en « {name} » par {}",
        form.category_id,
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct RenameSubcategoryForm {
    #[serde(default)]
    pub subcategory_id: i64,
    #[serde(default)]
    pub new_name: String,
}

pub async fn rename_subcategory(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<RenameSubcategoryForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let name = clean_name(&form.new_name);
    if form.subcategory_id <= 0 || name.is_empty() {
        return Ok(failure("Le nom de la sous-catégorie est obligatoire."));
    }

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM category_sub WHERE LOWER(TRIM(name)) = LOWER(?) AND id <> ? \
         AND category_head_id = (SELECT category_head_id FROM category_sub WHERE id = ?) LIMIT 1",
    )
    .bind(&name)
    .bind(form.subcategory_id)
    .bind(form.subcategory_id)
    .fetch_optional(&pool)
    .await?;
    if duplicate.is_some() {
        return Ok(failure(
            "Une sous-catégorie portant ce nom existe déjà dans cette catégorie.",
        ));
    }

    let result = sqlx::query("UPDATE category_sub SET name = ? WHERE id = ?")
        .bind(&name)
        .bind(form.subcategory_id)
        .execute(&pool)
        .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Sous-catégorie introuvable."));
    }

    tracing::info!(
        "Sous-catégorie {} renommée en « {name} » par {}",
        form.subcategory_id,
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct CategoryIdForm {
    #[serde(default)]
    pub category_id: i64,
}

/// Supprime une catégorie vide ; les catégories peuplées sont protégées.
pub async fn delete_category(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<CategoryIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if form.category_id <= 0 {
        return Ok(failure("Catégorie introuvable."));
    }

    let count: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM category_sub WHERE category_head_id = ?")
        .bind(form.category_id)
        .fetch_one(&pool)
        .await?;
    if count.0 > 0 {
        return Ok(failure(
            "Impossible de supprimer cette catégorie car elle contient des sous-catégories.",
        ));
    }

    let result = sqlx::query("DELETE FROM category_head WHERE id = ?")
        .bind(form.category_id)
        .execute(&pool)
        .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Catégorie introuvable."));
    }

    tracing::info!("Catégorie {} supprimée par {}", form.category_id, user.email);
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct SubcategoryIdForm {
    #[serde(default)]
    pub subcategory_id: i64,
}

/// Supprime une sous-catégorie inutilisée.
pub async fn delete_subcategory(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<SubcategoryIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if form.subcategory_id <= 0 {
        return Ok(failure("Sous-catégorie introuvable."));
    }

    let count: (i64,) = sqlx::query_as("SELECT COUNT(*) FROM data WHERE category = ? AND owner = ?")
        .bind(form.subcategory_id)
        .bind(user.id)
        .fetch_one(&pool)
        .await?;
    if count.0 > 0 {
        return Ok(failure(&format!(
            "Impossible de supprimer cette sous-catégorie : {0} composant(s) l'utilisent. \
             Vous pouvez seulement la renommer.",
            count.0
        )));
    }

    let result = sqlx::query("DELETE FROM category_sub WHERE id = ?")
        .bind(form.subcategory_id)
        .execute(&pool)
        .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Sous-catégorie introuvable."));
    }

    tracing::info!(
        "Sous-catégorie {} supprimée par {}",
        form.subcategory_id,
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}
