//! Gestion des emplacements : vue « conteneurs » (casiers, boîtes, classeurs,
//! étagères), vue tableau, changement de type et suppression d'un conteneur.
//!
//! Chaque emplacement est identifié par un casier, un tiroir et un
//! compartiment. Le type de rangement s'applique à tout le conteneur, et un
//! conteneur ne peut être supprimé que s'il ne contient aucun composant :
//! les règles reprennent celles de `locations.php`.

use std::collections::HashMap;
use std::path::Path;

use axum::extract::{Multipart, Path as RoutePath, Query, State};
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::{Form, Json};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sqlx::{AssertSqlSafe, FromRow, MySqlPool};

use crate::error::{AppError, AppResult};
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;
use crate::util;

use super::components::clean;
use super::page_context;

#[derive(Debug, Clone, Serialize)]
pub struct StorageType {
    pub key: &'static str,
    pub label: &'static str,
    pub icon: &'static str,
}

/// Types de rangement proposés, identiques à `getLocationStorageTypes()`.
static STORAGE_TYPES: [StorageType; 4] = [
    StorageType { key: "casier", label: "Casier", icon: "🗄️" },
    StorageType { key: "boite", label: "Boite", icon: "📦" },
    StorageType { key: "classeur", label: "Classeur", icon: "🗂️" },
    StorageType { key: "etagere", label: "Etagere", icon: "🪜" },
];

pub fn storage_types() -> Vec<StorageType> {
    STORAGE_TYPES.to_vec()
}

pub fn is_valid_storage_type(key: &str) -> bool {
    STORAGE_TYPES.iter().any(|item| item.key == key)
}

/// Métadonnées d'un type, avec repli sur « casier » comme dans la version PHP.
pub fn storage_meta(key: Option<&str>) -> &'static StorageType {
    let key = key.unwrap_or_default().trim();
    STORAGE_TYPES
        .iter()
        .find(|item| item.key == key)
        .unwrap_or(&STORAGE_TYPES[0])
}

/// Clé de type normalisée (`location.storage_type` peut être vide en base).
pub fn storage_key(value: Option<&str>) -> String {
    storage_meta(value).key.to_string()
}

/// Classe de remplissage d'un compartiment (mêmes seuils que `getFillClass()`).
pub fn fill_class(quantity: i64, components: i64) -> &'static str {
    if quantity <= 0 && components <= 0 {
        "empty"
    } else if quantity <= 5 {
        "low"
    } else if quantity <= 25 {
        "medium"
    } else {
        "full"
    }
}

/// Chemin directement utilisable dans un `src="…"` (les gabarits ne testent pas
/// les préfixes).
pub fn logo_src(path: Option<&str>) -> Option<String> {
    let path = path?.trim();
    if path.is_empty() {
        return None;
    }
    Some(if path.starts_with("http") {
        path.to_string()
    } else {
        format!("/{path}")
    })
}

// ------------------------------------------------------------------- Listing

#[derive(Debug, FromRow)]
struct LocationRow {
    id: i64,
    casier: Option<String>,
    tiroir: Option<String>,
    compartiment: Option<String>,
    description: Option<String>,
    storage_type: Option<String>,
    logo_path: Option<String>,
    component_count: i64,
    total_quantity: i64,
}

#[derive(Debug, Deserialize)]
pub struct ListParams {
    success: Option<String>,
    error: Option<String>,
    count: Option<String>,
    open: Option<String>,
}

pub async fn list(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<ListParams>,
) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let owner = user.id;

    let rows: Vec<LocationRow> = sqlx::query_as(
        "SELECT l.id, l.casier, l.tiroir, l.compartiment, l.description, l.storage_type, l.logo_path, \
         COUNT(d.id) AS component_count, CAST(COALESCE(SUM(d.quantity), 0) AS SIGNED) AS total_quantity \
         FROM location l LEFT JOIN data d ON d.location_id = l.id \
         WHERE l.owner = ? \
         GROUP BY l.id, l.casier, l.tiroir, l.compartiment, l.description, l.storage_type, l.logo_path \
         ORDER BY l.casier, CAST(l.tiroir AS UNSIGNED), l.tiroir, \
         CAST(l.compartiment AS UNSIGNED), l.compartiment",
    )
    .bind(owner)
    .fetch_all(&pool)
    .await?;

    // Composant mis en avant dans chaque compartiment : le plus stocké.
    let ids: Vec<String> = rows.iter().map(|row| row.id.to_string()).collect();
    let mut lead: HashMap<i64, (Option<String>, i64)> = HashMap::new();
    if !ids.is_empty() {
        let sql = format!(
            "SELECT location_id, name, quantity FROM data WHERE location_id IN ({}) \
             ORDER BY location_id, quantity DESC, id",
            ids.join(", ")
        );
        let leads: Vec<(i64, Option<String>, Option<i32>)> =
            sqlx::query_as(AssertSqlSafe(sql)).fetch_all(&pool).await?;
        for (location_id, name, quantity) in leads {
            lead.entry(location_id)
                .or_insert((name, quantity.unwrap_or(0) as i64));
        }
    }

    struct Casier {
        storage_type: String,
        logo_path: Option<String>,
        first_id: i64,
        tiroirs: Vec<(String, Vec<usize>)>,
    }

    let mut order: Vec<String> = Vec::new();
    let mut casiers: HashMap<String, Casier> = HashMap::new();

    for (index, row) in rows.iter().enumerate() {
        let letter = row.casier.clone().unwrap_or_default();
        let entry = casiers.entry(letter.clone()).or_insert_with(|| {
            order.push(letter.clone());
            Casier {
                storage_type: storage_key(row.storage_type.as_deref()),
                logo_path: logo_src(row.logo_path.as_deref()),
                first_id: row.id,
                tiroirs: Vec::new(),
            }
        });
        if entry.logo_path.is_none() {
            entry.logo_path = logo_src(row.logo_path.as_deref());
        }

        let tiroir = row.tiroir.clone().unwrap_or_default();
        match entry.tiroirs.last_mut() {
            Some((number, list)) if *number == tiroir => list.push(index),
            _ => entry.tiroirs.push((tiroir, vec![index])),
        }
    }

    let mut containers: Vec<Value> = Vec::new();
    let mut table_rows: Vec<Value> = Vec::new();
    let (mut all_drawers, mut all_compartments, mut all_components, mut all_quantity) = (0i64, 0i64, 0i64, 0i64);

    for letter in &order {
        let casier = &casiers[letter];
        let meta = storage_meta(Some(&casier.storage_type));
        let is_flat = matches!(casier.storage_type.as_str(), "classeur" | "etagere");

        let mut components = 0i64;
        let mut quantity = 0i64;
        let mut flat_cells: Vec<Value> = Vec::new();
        let mut rows_json: Vec<Value> = Vec::new();
        let mut current_label: Option<i64> = None;
        let mut current_drawers: Vec<Value> = Vec::new();

        for (tiroir, indices) in &casier.tiroirs {
            let decade = (tiroir.parse::<i64>().unwrap_or(0) / 10) * 10;
            if current_label != Some(decade) {
                if let Some(label) = current_label {
                    rows_json.push(json!({
                        "label": format!("Ligne {label}"),
                        "drawers": std::mem::take(&mut current_drawers),
                    }));
                }
                current_label = Some(decade);
            }

            let mut cells: Vec<Value> = Vec::new();
            let (mut drawer_quantity, mut drawer_components) = (0i64, 0i64);

            for index in indices {
                let row = &rows[*index];
                let cell = cell_value(row, letter, tiroir, lead.get(&row.id).cloned());
                drawer_quantity += row.total_quantity;
                drawer_components += row.component_count;
                components += row.component_count;
                quantity += row.total_quantity;
                cells.push(cell.clone());
                flat_cells.push(cell);
            }

            current_drawers.push(json!({
                "number": tiroir,
                "id": indices.first().map(|index| rows[*index].id),
                "compartments": indices.len(),
                "components": drawer_components,
                "quantity": drawer_quantity,
                "cells": cells,
            }));
        }
        if let Some(label) = current_label {
            rows_json.push(json!({
                "label": format!("Ligne {label}"),
                "drawers": current_drawers,
            }));
        }

        let drawers = casier.tiroirs.len() as i64;
        let compartments = casier.tiroirs.iter().map(|(_, list)| list.len() as i64).sum::<i64>();
        all_drawers += drawers;
        all_compartments += compartments;
        all_components += components;
        all_quantity += quantity;

        containers.push(json!({
            "letter": letter,
            "label": meta.label,
            "icon": meta.icon,
            "storage_type": casier.storage_type,
            "logo_src": casier.logo_path,
            "first_id": casier.first_id,
            "is_empty": components == 0 && quantity == 0,
            "is_flat": is_flat,
            "drawers": drawers,
            "compartments": compartments,
            "components": components,
            "quantity": quantity,
            "flat_cells": flat_cells,
            "rows": rows_json,
        }));
    }

    for row in &rows {
        let casier = row.casier.clone().unwrap_or_default();
        let tiroir = row.tiroir.clone().unwrap_or_default();
        let compartiment = row.compartiment.clone().unwrap_or_default();
        let (first_name, _) = lead.get(&row.id).cloned().unwrap_or((None, 0));
        table_rows.push(json!({
            "id": row.id,
            "letter": casier,
            "code": format!("{casier}{tiroir}-{compartiment}"),
            "casier": casier,
            "tiroir": tiroir,
            "compartiment": compartiment,
            "description": row.description.clone().unwrap_or_default(),
            "components": row.component_count,
            "quantity": row.total_quantity,
            "first_name": first_name,
            "extra_refs": (row.component_count - 1).max(0),
            "fill": fill_class(row.total_quantity, row.component_count),
            "occupied": row.component_count > 0,
        }));
    }

    let mut context = page_context(
        &state,
        &user,
        "locations",
        "Emplacements",
        "Casiers, tiroirs et compartiments — organisation du stockage physique",
    )
    .await;

    context.insert("containers".to_string(), json!(containers));
    context.insert("targets".to_string(), json!(table_rows));
    context.insert("storage_types".to_string(), json!(storage_types()));
    context.insert(
        "stats".to_string(),
        json!({
            "containers": order.len(),
            "drawers": all_drawers,
            "compartments": all_compartments,
            "pieces": all_quantity,
            "references": all_components,
        }),
    );
    context.insert("message".to_string(), flash(params.success, params.error, params.count));
    context.insert("open".to_string(), json!(clean(params.open).unwrap_or_default()));

    templates::render(&state.templates, "locations.html", Value::Object(context))
}

/// Ligne de compartiment présentée dans les vues « conteneur » et « tableau ».
fn cell_value(
    row: &LocationRow,
    letter: &str,
    tiroir: &str,
    lead: Option<(Option<String>, i64)>,
) -> Value {
    let compartment = row.compartiment.clone().unwrap_or_default();
    let (first_name, _) = lead.unwrap_or((None, 0));
    json!({
        "id": row.id,
        "letter": letter,
        "label": format!("{letter}{tiroir}-{compartment}"),
        "quantity": row.total_quantity,
        "components": row.component_count,
        "first_name": first_name,
        "extra_refs": (row.component_count - 1).max(0),
        "fill": fill_class(row.total_quantity, row.component_count),
        "occupied": row.component_count > 0,
    })
}

/// Message de retour affiché en haut de la page après une action.
pub fn flash(success: Option<String>, error: Option<String>, count: Option<String>) -> Value {
    if let Some(code) = clean(error) {
        let text = match code.as_str() {
            "exists" => "Cet emplacement existe déjà !",
            "required" => "Tous les champs obligatoires doivent être remplis.",
            "invalid" => "Données invalides !",
            "not_found" => "Emplacement non trouvé !",
            "in_use" => "Suppression impossible : cet emplacement contient des composants.",
            _ => "L'opération n'a pas pu aboutir.",
        };
        return json!({ "kind": "error", "text": text });
    }

    if let Some(code) = clean(success) {
        let text = match code.as_str() {
            "added" => "Emplacement ajouté avec succès !".to_string(),
            "updated" => "Emplacement modifié avec succès !".to_string(),
            "deleted" => "Emplacement supprimé avec succès !".to_string(),
            "batch_added" => {
                let added = clean(count).and_then(|value| value.parse::<i64>().ok()).unwrap_or(0);
                format!("{added} emplacement(s) créé(s) par grappe.")
            }
            _ => "Opération effectuée.".to_string(),
        };
        return json!({ "kind": "ok", "text": text });
    }

    Value::Null
}

// ------------------------------------------------- Actions sans rechargement

#[derive(Debug, Deserialize)]
pub struct CasierTypeForm {
    casier: String,
    storage_type: String,
}

/// Applique un nouveau type de rangement à tout un conteneur.
pub async fn set_casier_type(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<CasierTypeForm>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let casier = form.casier.trim().to_string();
    let storage_type = form.storage_type.trim().to_ascii_lowercase();

    if casier.is_empty() || !is_valid_storage_type(&storage_type) {
        return Ok(Json(json!({ "ok": false, "error": "Données invalides" })).into_response());
    }

    sqlx::query("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?")
        .bind(&storage_type)
        .bind(user.id)
        .bind(&casier)
        .execute(&pool)
        .await?;

    let meta = storage_meta(Some(&storage_type));
    Ok(Json(json!({
        "ok": true,
        "icon": meta.icon,
        "label": meta.label,
        "casier": casier,
    }))
    .into_response())
}

#[derive(Debug, Deserialize)]
pub struct CasierForm {
    casier: String,
}

/// Supprime un conteneur entier, uniquement s'il ne contient aucun composant.
pub async fn delete_empty_casier(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<CasierForm>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let casier = form.casier.trim().to_string();

    if casier.is_empty() {
        return Ok(Json(json!({ "ok": false, "error": "Données invalides" })).into_response());
    }

    // Double contrôle côté serveur : le client ne décide jamais de la vacuité.
    let (used,): (i64,) = sqlx::query_as(
        "SELECT COUNT(d.id) FROM location l LEFT JOIN data d ON d.location_id = l.id \
         WHERE l.owner = ? AND l.casier = ?",
    )
    .bind(user.id)
    .bind(&casier)
    .fetch_one(&pool)
    .await?;

    if used > 0 {
        return Ok(Json(json!({
            "ok": false,
            "error": "Ce conteneur n'est pas vide (il contient des composants) — suppression impossible.",
        }))
        .into_response());
    }

    let (existing,): (i64,) =
        sqlx::query_as("SELECT COUNT(*) FROM location WHERE owner = ? AND casier = ?")
            .bind(user.id)
            .bind(&casier)
            .fetch_one(&pool)
            .await?;

    if existing == 0 {
        return Ok(Json(json!({ "ok": false, "error": "Conteneur introuvable." })).into_response());
    }

    sqlx::query("DELETE FROM location WHERE owner = ? AND casier = ?")
        .bind(user.id)
        .bind(&casier)
        .execute(&pool)
        .await?;

    tracing::info!("Conteneur {casier} supprimé ({existing} emplacements)");
    Ok(Json(json!({ "ok": true, "casier": casier, "deleted": existing })).into_response())
}

#[derive(Debug, Deserialize)]
pub struct DeletePayload {
    location_id: i64,
}

/// Supprime un compartiment vide.
pub async fn delete_location(
    State(state): State<AppState>,
    user: CurrentUser,
    Json(payload): Json<DeletePayload>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let existing: Option<(Option<String>, Option<String>, Option<String>)> = sqlx::query_as(
        "SELECT casier, tiroir, compartiment FROM location WHERE id = ? AND owner = ?",
    )
    .bind(payload.location_id)
    .bind(user.id)
    .fetch_optional(&pool)
    .await?;

    let Some((casier, tiroir, compartiment)) = existing else {
        return Ok(Json(json!({ "ok": false, "error": "Emplacement introuvable." })).into_response());
    };

    let (used,): (i64,) = sqlx::query_as("SELECT COUNT(*) FROM data WHERE location_id = ?")
        .bind(payload.location_id)
        .fetch_one(&pool)
        .await?;

    if used > 0 {
        return Ok(Json(json!({
            "ok": false,
            "error": "Cet emplacement contient des composants — suppression impossible.",
        }))
        .into_response());
    }

    sqlx::query("DELETE FROM location WHERE id = ? AND owner = ?")
        .bind(payload.location_id)
        .bind(user.id)
        .execute(&pool)
        .await?;

    let label = format!(
        "{}{}-{}",
        casier.unwrap_or_default(),
        tiroir.unwrap_or_default(),
        compartiment.unwrap_or_default()
    );
    tracing::info!("Emplacement {label} supprimé");
    Ok(Json(json!({ "ok": true, "id": payload.location_id, "label": label })).into_response())
}

// --------------------------------------------------------------- Formulaires

/// Longueur maximale des colonnes `varchar` de la table `location`.
const MAX_FIELD: usize = 255;

#[derive(Debug, Default, Deserialize)]
pub struct FormMessage {
    error: Option<String>,
}

/// Message d'erreur affiché par un formulaire refusé.
fn form_error(params: &FormMessage) -> Value {
    match clean(params.error.clone()).as_deref() {
        Some("required") => json!("Tous les champs obligatoires doivent être remplis."),
        Some("exists") => json!("Un emplacement avec ces coordonnées existe déjà."),
        Some("not_found") => json!("Emplacement non trouvé."),
        Some(_) => json!("Données invalides."),
        None => Value::Null,
    }
}

#[derive(Debug, FromRow)]
struct LocationDetail {
    id: i64,
    casier: Option<String>,
    tiroir: Option<String>,
    compartiment: Option<String>,
    description: Option<String>,
    storage_type: Option<String>,
    logo_path: Option<String>,
}

async fn load_location(pool: &MySqlPool, id: i64, owner: i64) -> AppResult<Option<LocationDetail>> {
    Ok(sqlx::query_as(
        "SELECT id, casier, tiroir, compartiment, description, storage_type, logo_path \
         FROM location WHERE id = ? AND owner = ?",
    )
    .bind(id)
    .bind(owner)
    .fetch_optional(pool)
    .await?)
}

/// Clé de type valide, ou repli sur « casier » comme dans la version PHP.
fn normalize_storage(value: &str) -> String {
    let key = value.trim().to_ascii_lowercase();
    if is_valid_storage_type(&key) {
        key
    } else {
        "casier".to_string()
    }
}

/// Valeurs du formulaire, toujours des chaînes (jamais `null`).
fn location_json(detail: Option<&LocationDetail>) -> Value {
    match detail {
        Some(row) => json!({
            "id": row.id,
            "casier": row.casier.clone().unwrap_or_default(),
            "tiroir": row.tiroir.clone().unwrap_or_default(),
            "compartiment": row.compartiment.clone().unwrap_or_default(),
            "description": row.description.clone().unwrap_or_default(),
            "storage_type": storage_key(row.storage_type.as_deref()),
            "logo_src": logo_src(row.logo_path.as_deref()),
        }),
        None => json!({
            "id": Value::Null,
            "casier": "",
            "tiroir": "",
            "compartiment": "",
            "description": "",
            "storage_type": "casier",
            "logo_src": Value::Null,
        }),
    }
}

/// Images du dossier `img/`, proposées comme logo de conteneur.
fn image_files(root: &Path) -> Vec<String> {
    let mut names: Vec<String> = Vec::new();
    if let Ok(entries) = std::fs::read_dir(root.join("img")) {
        for entry in entries.flatten() {
            let path = entry.path();
            if !path.is_file() {
                continue;
            }
            let extension = path
                .extension()
                .and_then(|value| value.to_str())
                .unwrap_or_default()
                .to_ascii_lowercase();
            if !matches!(extension.as_str(), "jpg" | "jpeg" | "png" | "gif" | "svg" | "webp") {
                continue;
            }
            if let Some(name) = path.file_name().and_then(|value| value.to_str()) {
                names.push(name.to_string());
            }
        }
    }
    names.sort_by_key(|name| name.to_lowercase());
    names
}

/// Encode une valeur pour un paramètre d'URL.
fn encode(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => out.push(byte as char),
            b' ' => out.push('+'),
            other => out.push_str(&format!("%{other:02X}")),
        }
    }
    out
}

fn clip(value: &str, max: usize) -> String {
    if value.chars().count() <= max {
        return value.to_string();
    }
    value.chars().take(max).collect()
}

// ------------------------------------------------------- Création unitaire

pub async fn new_form(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<FormMessage>,
) -> AppResult<Html<String>> {
    let mut context = page_context(
        &state,
        &user,
        "locations",
        "Nouvel emplacement",
        "Casier, tiroir et compartiment",
    )
    .await;

    context.insert("mode".to_string(), json!("create"));
    context.insert("form_action".to_string(), json!("/locations"));
    context.insert("submit_label".to_string(), json!("Créer l'emplacement"));
    context.insert("location".to_string(), location_json(None));
    context.insert("storage_types".to_string(), json!(storage_types()));
    context.insert("images".to_string(), json!(Vec::<String>::new()));
    context.insert("error".to_string(), form_error(&params));

    templates::render(&state.templates, "location_form.html", Value::Object(context))
}

#[derive(Debug, Deserialize)]
pub struct LocationInput {
    casier: String,
    tiroir: String,
    compartiment: String,
    #[serde(default)]
    description: String,
    #[serde(default)]
    storage_type: String,
}

/// Ajoute un emplacement ; le type choisi est propagé à tout le conteneur.
pub async fn create(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<LocationInput>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let casier = form.casier.trim().to_string();
    let tiroir = form.tiroir.trim().to_string();
    let compartiment = form.compartiment.trim().to_string();
    if casier.is_empty() || tiroir.is_empty() || compartiment.is_empty() {
        return Ok(Redirect::to("/locations/new?error=required").into_response());
    }

    let storage_type = normalize_storage(&form.storage_type);

    let existing: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ? LIMIT 1",
    )
    .bind(user.id)
    .bind(&casier)
    .bind(&tiroir)
    .bind(&compartiment)
    .fetch_optional(&pool)
    .await?;

    if existing.is_some() {
        return Ok(Redirect::to("/locations?error=exists").into_response());
    }

    sqlx::query(
        "INSERT INTO location (owner, casier, tiroir, compartiment, description, storage_type) \
         VALUES (?, ?, ?, ?, ?, ?)",
    )
    .bind(user.id)
    .bind(clip(&casier, MAX_FIELD))
    .bind(clip(&tiroir, MAX_FIELD))
    .bind(clip(&compartiment, MAX_FIELD))
    .bind(clip(form.description.trim(), 1000))
    .bind(&storage_type)
    .execute(&pool)
    .await?;

    sqlx::query("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?")
        .bind(&storage_type)
        .bind(user.id)
        .bind(&casier)
        .execute(&pool)
        .await?;

    tracing::info!("Emplacement {casier}{tiroir}-{compartiment} créé");
    Ok(Redirect::to(&format!("/locations?success=added&open={}", encode(&casier))).into_response())
}

// ----------------------------------------------------- Modification

pub async fn edit_form(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
    Query(params): Query<FormMessage>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let Some(detail) = load_location(&pool, id, user.id).await? else {
        return Ok(Redirect::to("/locations?error=not_found").into_response());
    };

    let mut context = page_context(
        &state,
        &user,
        "locations",
        "Modifier l'emplacement",
        "Les composants stockés ici suivront le changement de coordonnées",
    )
    .await;

    context.insert("mode".to_string(), json!("edit"));
    context.insert("form_action".to_string(), json!(format!("/locations/{id}")));
    context.insert("submit_label".to_string(), json!("Enregistrer les modifications"));
    context.insert("location".to_string(), location_json(Some(&detail)));
    context.insert("storage_types".to_string(), json!(storage_types()));
    context.insert("images".to_string(), json!(image_files(&state.root)));
    context.insert("error".to_string(), form_error(&params));

    Ok(templates::render(&state.templates, "location_form.html", Value::Object(context))?.into_response())
}

#[derive(Debug, Default, Deserialize)]
struct LocationUpdate {
    casier: String,
    tiroir: String,
    compartiment: String,
    description: String,
    storage_type: String,
    delete_logo: Option<String>,
    existing_logo: String,
    logo: Option<(String, Vec<u8>)>,
}

/// Enregistre les modifications d'un emplacement, logo compris.
pub async fn update(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
    multipart: Multipart,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let Some(current) = load_location(&pool, id, user.id).await? else {
        return Ok(Redirect::to("/locations?error=not_found").into_response());
    };

    let (fields, files) = read_multipart(multipart).await?;
    let mut input = LocationUpdate::from_parts(fields, files);

    input.casier = input.casier.trim().to_string();
    input.tiroir = input.tiroir.trim().to_string();
    input.compartiment = input.compartiment.trim().to_string();
    if input.casier.is_empty() || input.tiroir.is_empty() || input.compartiment.is_empty() {
        return Ok(Redirect::to(&format!("/locations/{id}/edit?error=required")).into_response());
    }

    let storage_type = normalize_storage(&input.storage_type);
    let logo_path = resolve_logo(&state.root, &current.logo_path, &input);

    let duplicate: Option<(i64,)> = sqlx::query_as(
        "SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ? \
         AND id != ? LIMIT 1",
    )
    .bind(user.id)
    .bind(&input.casier)
    .bind(&input.tiroir)
    .bind(&input.compartiment)
    .bind(id)
    .fetch_optional(&pool)
    .await?;

    if duplicate.is_some() {
        return Ok(Redirect::to(&format!("/locations/{id}/edit?error=exists")).into_response());
    }

    sqlx::query(
        "UPDATE location SET casier = ?, tiroir = ?, compartiment = ?, description = ?, \
         storage_type = ?, logo_path = ? WHERE id = ? AND owner = ?",
    )
    .bind(clip(&input.casier, MAX_FIELD))
    .bind(clip(&input.tiroir, MAX_FIELD))
    .bind(clip(&input.compartiment, MAX_FIELD))
    .bind(clip(input.description.trim(), 1000))
    .bind(&storage_type)
    .bind(logo_path.as_deref())
    .bind(id)
    .bind(user.id)
    .execute(&pool)
    .await?;

    sqlx::query("UPDATE location SET storage_type = ?, logo_path = ? WHERE owner = ? AND casier = ?")
        .bind(&storage_type)
        .bind(logo_path.as_deref())
        .bind(user.id)
        .bind(&input.casier)
        .execute(&pool)
        .await?;

    tracing::info!("Emplacement {id} modifié");
    Ok(Redirect::to(&format!(
        "/locations?success=updated&open={}",
        encode(&input.casier)
    ))
    .into_response())
}

impl LocationUpdate {
    fn from_parts(
        mut fields: HashMap<String, String>,
        mut files: HashMap<String, (String, Vec<u8>)>,
    ) -> Self {
        let take = |fields: &mut HashMap<String, String>, key: &str| {
            fields
                .remove(key)
                .map(|value| value.trim().to_string())
                .unwrap_or_default()
        };

        Self {
            casier: take(&mut fields, "casier"),
            tiroir: take(&mut fields, "tiroir"),
            compartiment: take(&mut fields, "compartiment"),
            description: take(&mut fields, "description"),
            storage_type: take(&mut fields, "storage_type"),
            delete_logo: fields.remove("delete_logo"),
            existing_logo: take(&mut fields, "existing_logo"),
            logo: files.remove("logo"),
        }
    }
}

/// Détermine le logo à conserver : fichier déposé, image existante, ou
/// suppression demandée. Le fichier déposé est prioritaire.
fn resolve_logo(root: &Path, current: &Option<String>, input: &LocationUpdate) -> Option<String> {
    let removed = input.delete_logo.as_deref() == Some("1");

    if !removed
        && let Some((file_name, data)) = input.logo.as_ref()
        && let Some(extension) = logo_extension(file_name)
    {
        let directory = root.join("img");
        if std::fs::create_dir_all(&directory).is_err() {
            return current.clone();
        }
        let slug = util::safe_file_name(&input.casier).to_lowercase();
        let stored = format!(
            "location_casier_{}_{}.{extension}",
            if slug.is_empty() { "casier" } else { slug.as_str() },
            util::unique_suffix()
        );
        if std::fs::write(directory.join(&stored), data).is_err() {
            return current.clone();
        }
        remove_logo(root, current.as_deref());
        tracing::debug!("Logo « {file_name} » enregistré sous img/{stored}");
        return Some(format!("img/{stored}"));
    }

    if !removed && !input.existing_logo.is_empty() {
        let file_name = Path::new(input.existing_logo.trim())
            .file_name()
            .and_then(|value| value.to_str())
            .unwrap_or_default()
            .to_string();
        let candidate = format!("img/{file_name}");
        if util::is_internal_image(&candidate) && root.join(&candidate).is_file() {
            if current.as_deref() != Some(candidate.as_str()) {
                remove_logo(root, current.as_deref());
            }
            return Some(candidate);
        }
    }

    if removed {
        remove_logo(root, current.as_deref());
        return None;
    }

    current.clone()
}

/// Extension acceptée pour un logo, ou `None` si le format ne convient pas.
fn logo_extension(file_name: &str) -> Option<String> {
    let extension = file_name.rsplit('.').next()?.to_ascii_lowercase();
    if matches!(extension.as_str(), "jpg" | "jpeg" | "png" | "gif" | "webp" | "svg") {
        Some(extension)
    } else {
        None
    }
}

/// Supprime un logo uniquement s'il a été déposé par l'application.
fn remove_logo(root: &Path, path: Option<&str>) {
    let Some(path) = path else {
        return;
    };
    if !util::is_internal_image(path) {
        return;
    }
    let name = Path::new(path)
        .file_name()
        .and_then(|value| value.to_str())
        .unwrap_or_default();
    if !name.starts_with("location_") {
        return;
    }
    let target = root.join(path);
    if target.is_file() {
        let _ = std::fs::remove_file(target);
    }
}

async fn read_multipart(
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

// ------------------------------------------------------ Création par grappe

#[derive(Debug, Deserialize)]
pub struct BatchInput {
    casier: String,
    #[serde(default)]
    premier_tiroir: String,
    #[serde(default)]
    tiroirs_horizontal: String,
    #[serde(default)]
    tiroirs_vertical: String,
    #[serde(default)]
    compartiments_par_tiroir: String,
    #[serde(default)]
    description: String,
    #[serde(default)]
    storage_type: String,
}

fn batch_form_values(input: Option<&BatchInput>) -> Value {
    match input {
        Some(form) => json!({
            "casier": form.casier,
            "premier_tiroir": integer(&form.premier_tiroir, 10),
            "tiroirs_horizontal": integer(&form.tiroirs_horizontal, 6),
            "tiroirs_vertical": integer(&form.tiroirs_vertical, 3),
            "compartiments_par_tiroir": integer(&form.compartiments_par_tiroir, 4),
            "description": form.description,
            "storage_type": normalize_storage(&form.storage_type),
        }),
        None => json!({
            "casier": "",
            "premier_tiroir": 10,
            "tiroirs_horizontal": 6,
            "tiroirs_vertical": 3,
            "compartiments_par_tiroir": 4,
            "description": "",
            "storage_type": "casier",
        }),
    }
}

fn integer(value: &str, fallback: i64) -> i64 {
    value.trim().parse::<i64>().unwrap_or(fallback)
}

async fn render_batch(
    state: &AppState,
    user: &CurrentUser,
    input: Option<&BatchInput>,
    error: Option<String>,
) -> AppResult<Html<String>> {
    let mut context = page_context(
        state,
        user,
        "locations",
        "Créer par grappe",
        "Générer d'un coup les tiroirs et compartiments d'un conteneur",
    )
    .await;

    context.insert("form".to_string(), batch_form_values(input));
    context.insert("storage_types".to_string(), json!(storage_types()));
    context.insert("error".to_string(), json!(error));

    templates::render(&state.templates, "location_batch.html", Value::Object(context))
}

pub async fn batch_form(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    render_batch(&state, &user, None, None).await
}

/// Crée les emplacements d'une grappe ; les coordonnées déjà présentes sont
/// ignorées, comme dans `add_location_batch.php`.
pub async fn batch_create(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<BatchInput>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let casier = form.casier.trim().to_string();
    let premier_tiroir = integer(&form.premier_tiroir, 10);
    let horizontal = integer(&form.tiroirs_horizontal, 6);
    let vertical = integer(&form.tiroirs_vertical, 3);
    let par_tiroir = integer(&form.compartiments_par_tiroir, 4);
    let storage_type = normalize_storage(&form.storage_type);

    let message = if casier.is_empty() {
        Some("Le nom du conteneur est obligatoire.".to_string())
    } else if !(1..=20).contains(&horizontal) {
        Some("Le nombre de tiroirs par ligne doit être compris entre 1 et 20.".to_string())
    } else if !(1..=10).contains(&vertical) {
        Some("Le nombre de lignes doit être compris entre 1 et 10.".to_string())
    } else if !(1..=10).contains(&par_tiroir) {
        Some("Le nombre de compartiments par tiroir doit être compris entre 1 et 10.".to_string())
    } else {
        None
    };

    if let Some(message) = message {
        return Ok(render_batch(&state, &user, Some(&form), Some(message)).await?.into_response());
    }

    let description = clip(form.description.trim(), 900);
    let mut transaction = pool.begin().await?;
    let mut created = 0i64;

    for ligne in 0..vertical {
        let tiroir_base = premier_tiroir + ligne * 10;
        for colonne in 0..horizontal {
            let tiroir = (tiroir_base + colonne).to_string();
            for compartiment in 1..=par_tiroir {
                let compartiment = compartiment.to_string();

                let existing: Option<(i64,)> = sqlx::query_as(
                    "SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? \
                     AND compartiment = ? LIMIT 1",
                )
                .bind(user.id)
                .bind(&casier)
                .bind(&tiroir)
                .bind(&compartiment)
                .fetch_optional(&mut *transaction)
                .await?;

                if existing.is_some() {
                    continue;
                }

                let detail = if description.is_empty() {
                    String::new()
                } else {
                    format!(
                        "{description} (Ligne {}, Tiroir {tiroir}, Compartiment {compartiment})",
                        ligne + 1
                    )
                };

                sqlx::query(
                    "INSERT INTO location (owner, casier, tiroir, compartiment, description, storage_type) \
                     VALUES (?, ?, ?, ?, ?, ?)",
                )
                .bind(user.id)
                .bind(clip(&casier, MAX_FIELD))
                .bind(&tiroir)
                .bind(&compartiment)
                .bind(clip(&detail, 1000))
                .bind(&storage_type)
                .execute(&mut *transaction)
                .await?;

                created += 1;
            }
        }
    }

    sqlx::query("UPDATE location SET storage_type = ? WHERE owner = ? AND casier = ?")
        .bind(&storage_type)
        .bind(user.id)
        .bind(&casier)
        .execute(&mut *transaction)
        .await?;

    transaction.commit().await?;

    if created == 0 {
        return Ok(render_batch(
            &state,
            &user,
            Some(&form),
            Some("Aucun emplacement créé : toutes ces coordonnées existent déjà.".to_string()),
        )
        .await?
        .into_response());
    }

    tracing::info!("Grappe {casier} : {created} emplacement(s) créé(s)");
    Ok(Redirect::to(&format!(
        "/locations?success=batch_added&count={created}&open={}",
        encode(&casier)
    ))
    .into_response())
}
