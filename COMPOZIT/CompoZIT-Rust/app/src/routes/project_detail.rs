//! Fiche détaillée d'un projet.
//!
//! Reprend `project_detail.php` : quatre onglets (vue d'ensemble, composants,
//! travaux et matériaux, documents et photos) et une progression globale
//! pondérée entre l'avancement des composants et celui des travaux.
//!
//! Chaque action (ajout d'un composant, quantités, avancement d'un travail,
//! dépôt ou suppression de document) répond par le fragment
//! `_project_detail_body.html` recalculé, ce qui évite tout rechargement.

use axum::extract::{Multipart, Path as RoutePath, State};
use axum::response::{IntoResponse, Redirect, Response};
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;
use super::projects::{
    MAX_IMAGE_BYTES, MAX_NAME, clip, owned_project, read_multipart, remove_project_folder,
    safe_extension, status_key, store_project_file,
};

const MAX_NOTE: usize = 2000;
const MAX_FILE_BYTES: usize = 50 * 1024 * 1024;

const ITEM_TYPES: [&str; 3] = ["travail", "matériel", "service"];
const FILE_CATEGORIES: [&str; 6] = [
    "schema", "photo", "datasheet", "programme", "document", "autre",
];

const IMAGE_EXTENSIONS: [&str; 5] = ["jpg", "jpeg", "png", "gif", "webp"];

#[derive(Debug, FromRow)]
struct DetailRow {
    id: i64,
    name: String,
    description: Option<String>,
    image_path: Option<String>,
    status: Option<String>,
    created_label: Option<String>,
    updated_label: Option<String>,
}

#[derive(Debug, FromRow)]
struct ComponentRow {
    pc_id: i64,
    component_id: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    stock: i64,
    needed: i64,
    used: i64,
    notes: Option<String>,
    price: f64,
}

#[derive(Debug, FromRow)]
struct ItemRow {
    id: i64,
    kind: String,
    name: String,
    description: Option<String>,
    quantity: f64,
    completed: f64,
    unit: Option<String>,
    unit_price: f64,
    total_price: f64,
    status: Option<String>,
}

#[derive(Debug, FromRow)]
struct FileRow {
    id: i64,
    file_name: String,
    original_name: String,
    display_name: Option<String>,
    file_path: String,
    file_type: Option<String>,
    file_size: i64,
    file_category: Option<String>,
    description: Option<String>,
    uploaded_label: Option<String>,
}

// ------------------------------------------------------------------- Utilitaires

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

/// Affiche un nombre en retirant la partie décimale inutile (3 → « 3 »,
/// 2,5 → « 2,5 »).
fn trim_number(value: f64) -> String {
    if (value.fract()).abs() < f64::EPSILON {
        format!("{}", value.round() as i64)
    } else {
        let text = format!("{value:.2}");
        text.trim_end_matches('0').trim_end_matches('.').replace('.', ",")
    }
}

/// Valeur d'un `<input type="number">` : point décimal et zéros inutiles retirés.
fn input_number(value: f64) -> String {
    trim_number(value).replace(',', ".")
}

fn price_label(value: f64) -> String {
    format!("{value:.2} €").replace('.', ",")
}

fn round1(value: f64) -> f64 {
    (value * 10.0).round() / 10.0
}

pub fn human_size(bytes: i64) -> String {
    const UNITS: [&str; 4] = ["o", "Ko", "Mo", "Go"];
    let mut size = bytes as f64;
    let mut index = 0;
    while size >= 1024.0 && index < UNITS.len() - 1 {
        size /= 1024.0;
        index += 1;
    }
    if index == 0 {
        format!("{} {}", bytes, UNITS[0])
    } else {
        format!("{size:.1} {}", UNITS[index]).replace('.', ",")
    }
}

fn file_icon(category: &str) -> &'static str {
    match category {
        "schema" => "📐",
        "photo" => "🖼️",
        "datasheet" => "📊",
        "programme" => "💾",
        "document" => "📄",
        _ => "📎",
    }
}

fn item_type_label(kind: &str) -> &'static str {
    match kind {
        "travail" => "Travail",
        "matériel" => "Matériel",
        _ => "Service",
    }
}

/// Une unité exprimée en heures (« heures », « h », « h 30 »…).
fn is_hours(unit: &str) -> bool {
    let lowered = unit.to_lowercase();
    lowered.contains("heure")
        || lowered == "h"
        || lowered.starts_with("h ")
        || lowered.contains(" h")
}

/// Pas d'incrémentation d'un travail : une demi-heure pour les durées.
fn item_step(unit: &str) -> f64 {
    if is_hours(unit) { 0.5 } else { 1.0 }
}

// ------------------------------------------------------------------- Lecture

async fn load_detail(
    pool: &MySqlPool,
    owner: i64,
    project_id: i64,
) -> AppResult<Option<Value>> {
    let Some((_, _)) = owned_project(pool, owner, project_id).await? else {
        return Ok(None);
    };

    let project: DetailRow = sqlx::query_as(
        "SELECT id, name, description, image_path, status, \
                DATE_FORMAT(created_at, '%d/%m/%Y') AS created_label, \
                DATE_FORMAT(updated_at, '%d/%m/%Y à %H:%i') AS updated_label \
         FROM projects WHERE id = ? AND owner = ? LIMIT 1",
    )
    .bind(project_id)
    .bind(owner)
    .fetch_one(pool)
    .await?;

    let components = sqlx::query_as::<_, ComponentRow>(
        "SELECT pc.id AS pc_id, pc.component_id, d.name, d.manufacturer, d.package, \
                CAST(COALESCE(d.quantity, 0) AS SIGNED) AS stock, \
                CAST(COALESCE(pc.quantity_needed, 0) AS SIGNED) AS needed, \
                CAST(COALESCE(pc.quantity_used, 0) AS SIGNED) AS used, \
                CAST(COALESCE(d.price, 0) AS DOUBLE) AS price, \
                pc.notes \
         FROM project_components pc \
         JOIN data d ON d.id = pc.component_id AND d.owner = ? \
         WHERE pc.project_id = ? \
         ORDER BY d.name ASC",
    )
    .bind(owner)
    .bind(project_id)
    .fetch_all(pool)
    .await?;

    let items = sqlx::query_as::<_, ItemRow>(
        "SELECT id, type AS kind, name, description, \
                CAST(COALESCE(quantity, 0) AS DOUBLE) AS quantity, \
                CAST(COALESCE(quantity_completed, 0) AS DOUBLE) AS completed, \
                unit, \
                CAST(COALESCE(unit_price, 0) AS DOUBLE) AS unit_price, \
                CAST(COALESCE(total_price, 0) AS DOUBLE) AS total_price, \
                status \
         FROM project_items WHERE project_id = ? ORDER BY added_at ASC, id ASC",
    )
    .bind(project_id)
    .fetch_all(pool)
    .await?;

    let files = sqlx::query_as::<_, FileRow>(
        "SELECT id, file_name, original_name, display_name, file_path, file_type, file_size, \
                file_category, description, \
                DATE_FORMAT(uploaded_at, '%d/%m/%Y') AS uploaded_label \
         FROM project_files WHERE project_id = ? ORDER BY uploaded_at DESC, id DESC",
    )
    .bind(project_id)
    .fetch_all(pool)
    .await?;

    // ----- Progression pondérée, identique aux calculs de `project_detail.php`.
    let total_needed: i64 = components.iter().map(|row| row.needed).sum();
    let total_used: i64 = components.iter().map(|row| row.used).sum();
    let items_quantity: f64 = items.iter().map(|row| row.quantity).sum();
    let items_completed: f64 = items.iter().map(|row| row.completed).sum();

    let components_progress = if total_needed > 0 {
        (total_used as f64 / total_needed as f64) * 100.0
    } else {
        0.0
    }
    .min(100.0);
    let items_progress = if items_quantity > 0.0 {
        (items_completed / items_quantity) * 100.0
    } else {
        0.0
    }
    .min(100.0);

    let total_count = components.len() + items.len();
    let global_progress = if total_count == 0 {
        0.0
    } else {
        let weights = total_count as f64;
        ((components_progress * components.len() as f64)
            + (items_progress * items.len() as f64))
            / weights
    }
    .min(100.0);

    let items_cost: f64 = items.iter().map(|row| row.total_price).sum();

    // ----- Coûts : composants (prix unitaire × quantités) + travaux & matériaux.
    let components_cost: f64 = components
        .iter()
        .map(|row| row.price * row.needed as f64)
        .sum();
    let total_cost = components_cost + items_cost;

    // ----- Temps passé : travaux dont l'unité est une durée.
    let hours_total: f64 = items
        .iter()
        .filter(|row| is_hours(row.unit.as_deref().unwrap_or("")))
        .map(|row| row.quantity)
        .sum();
    let hours_done: f64 = items
        .iter()
        .filter(|row| is_hours(row.unit.as_deref().unwrap_or("")))
        .map(|row| row.completed)
        .sum();

    let status = project.status.clone().unwrap_or_else(|| "En cours".to_string());
    let image_path = super::projects::web_path(project.image_path.as_deref().unwrap_or_default());

    let components_json: Vec<Value> = components
        .iter()
        .map(|row| {
            let progress = if row.needed > 0 {
                ((row.used as f64 / row.needed as f64) * 100.0).min(100.0).round() as i64
            } else {
                0
            };
            json!({
                "pc_id": row.pc_id,
                "component_id": row.component_id,
                "name": row.name.clone().unwrap_or_default(),
                "manufacturer": row.manufacturer.clone().unwrap_or_default(),
                "package": row.package.clone().unwrap_or_default(),
                "stock": row.stock,
                "needed": row.needed,
                "used": row.used,
                "notes": row.notes.clone().unwrap_or_default(),
                "progress": progress,
            })
        })
        .collect();

    let items_json: Vec<Value> = items
        .iter()
        .map(|row| {
            let unit = row.unit.clone().unwrap_or_else(|| "unité".to_string());
            let progress = if row.quantity > 0.0 {
                ((row.completed / row.quantity) * 100.0).min(100.0).round() as i64
            } else {
                0
            };
            json!({
                "id": row.id,
                "kind": row.kind,
                "kind_label": item_type_label(&row.kind),
                "name": row.name,
                "description": row.description.clone().unwrap_or_default(),
                "quantity_input": input_number(row.quantity),
                "quantity_label": trim_number(row.quantity),
                "completed_label": trim_number(row.completed),
                "unit": unit,
                "unit_price_input": input_number(row.unit_price),
                "unit_price_label": price_label(row.unit_price),
                "total_price_label": price_label(row.total_price),
                "status": row.status.clone().unwrap_or_else(|| "En attente".to_string()),
                "progress": progress,
                "step": item_step(&unit),
                "can_minus": row.completed > 0.0,
                "can_plus": row.completed < row.quantity,
            })
        })
        .collect();

    let files_json: Vec<Value> = files
        .iter()
        .map(|row| {
            let category = row.file_category.clone().unwrap_or_else(|| "autre".to_string());
            json!({
                "id": row.id,
                "file_name": row.file_name,
                "original_name": row.original_name,
                "display_name": row.display_name.clone().unwrap_or_else(|| row.original_name.clone()),
                "path": super::projects::web_path(&row.file_path),
                "type": row.file_type.clone().unwrap_or_default(),
                "size_label": human_size(row.file_size),
                "category": category,
                "icon": file_icon(&category),
                "description": row.description.clone().unwrap_or_default(),
                "uploaded": row.uploaded_label.clone().unwrap_or_default(),
                "is_image": IMAGE_EXTENSIONS.contains(&safe_extension(&row.file_name).as_str()),
            })
        })
        .collect();

    let context = json!({
        "project": {
            "id": project.id,
            "name": project.name,
            "description": project.description.clone().unwrap_or_default(),
            "image_path": image_path,
            "has_image": !image_path.is_empty(),
            "status": status,
            "status_key": status_key(&status),
            "created": project.created_label.clone().unwrap_or_default(),
            "updated": project.updated_label.clone().unwrap_or_default(),
            "folder": super::projects::safe_folder(&project.name),
        },
        "stats": {
            "components": components_json.len(),
            "total_needed": total_needed,
            "total_used": total_used,
            "items": items_json.len(),
            "items_quantity": trim_number(items_quantity),
            "items_completed": trim_number(items_completed),
            "items_cost": price_label(items_cost),
            "components_cost": price_label(components_cost),
            "total_cost": price_label(total_cost),
            "hours": format!("{} h", trim_number(hours_done)),
            "hours_total": trim_number(hours_total),
            "files": files_json.len(),
            "progress": round1(global_progress),
            "components_progress": round1(components_progress),
            "items_progress": round1(items_progress),
        },
        "components": components_json,
        "items": items_json,
        "files": files_json,
        "item_types": ITEM_TYPES
            .iter()
            .map(|kind| json!({ "value": kind, "label": item_type_label(kind) }))
            .collect::<Vec<Value>>(),
        "file_categories": FILE_CATEGORIES
            .iter()
            .map(|category| json!({ "value": category, "label": category, "icon": file_icon(category) }))
            .collect::<Vec<Value>>(),
        "statuses": super::projects::STATUSES,
    });

    Ok(Some(context))
}

/// Rend le fragment détaillé d'un projet, ou `None` s'il n'appartient pas à
/// l'utilisateur.
async fn render_body(
    state: &AppState,
    pool: &MySqlPool,
    owner: i64,
    project_id: i64,
) -> AppResult<Option<Json<Value>>> {
    let Some(context) = load_detail(pool, owner, project_id).await? else {
        return Ok(None);
    };
    // Le fragment attend le même contexte « detail » que la page complète.
    let html = templates::render(
        &state.templates,
        "_project_detail_body.html",
        json!({ "detail": context }),
    )?
    .0;

    let mut payload = context;
    if let Some(object) = payload.as_object_mut() {
        object.insert("ok".to_string(), json!(true));
        object.insert("html".to_string(), json!(html));
    }
    Ok(Some(Json(payload)))
}

// ------------------------------------------------------------------------ Page

pub async fn detail(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let Some(context) = load_detail(&pool, user.id, project_id).await? else {
        return Ok(Redirect::to("/projects").into_response());
    };

    let name = context
        .get("project")
        .and_then(|project| project.get("name"))
        .and_then(|value| value.as_str())
        .unwrap_or("Projet")
        .to_string();

    let mut page = page_context(&state, &user, "projects", &name, "Fiche détaillée du projet").await;
    page.insert("detail".to_string(), context);
    Ok(templates::render(&state.templates, "project_detail.html", Value::Object(page))?.into_response())
}

/// Rafraîchissement du corps de la fiche (utilisé par les actions AJAX).
pub async fn refresh(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

// ------------------------------------------------------------------ Composants

#[derive(Debug, Deserialize)]
pub struct AddComponentForm {
    #[serde(default)]
    pub component_id: i64,
    #[serde(default)]
    pub quantity_needed: i64,
}

pub async fn add_component(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<AddComponentForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }
    let needed = form.quantity_needed.max(1);
    if form.component_id <= 0 {
        return Ok(failure("Sélectionnez un composant."));
    }

    let known: Option<(i64,)> = sqlx::query_as("SELECT id FROM data WHERE id = ? AND owner = ? LIMIT 1")
        .bind(form.component_id)
        .bind(user.id)
        .fetch_optional(&pool)
        .await?;
    if known.is_none() {
        return Ok(failure("Composant introuvable."));
    }

    let existing: Option<(i64,)> =
        sqlx::query_as("SELECT id FROM project_components WHERE project_id = ? AND component_id = ? LIMIT 1")
            .bind(project_id)
            .bind(form.component_id)
            .fetch_optional(&pool)
            .await?;

    match existing {
        Some((pc_id,)) => {
            sqlx::query("UPDATE project_components SET quantity_needed = quantity_needed + ? WHERE id = ?")
                .bind(needed)
                .bind(pc_id)
                .execute(&pool)
                .await?;
        }
        None => {
            sqlx::query(
                "INSERT INTO project_components (project_id, component_id, quantity_needed, quantity_used) \
                 VALUES (?, ?, ?, 0)",
            )
            .bind(project_id)
            .bind(form.component_id)
            .bind(needed)
            .execute(&pool)
            .await?;
        }
    }

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

#[derive(Debug, Deserialize)]
pub struct ComponentUpdateForm {
    #[serde(default)]
    pub pc_id: i64,
    #[serde(default)]
    pub field: String,
    #[serde(default)]
    pub value: f64,
}

pub async fn update_component(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ComponentUpdateForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }

    let column = match form.field.as_str() {
        "needed" => "quantity_needed",
        "used" => "quantity_used",
        _ => return Ok(failure("Champ inconnu.")),
    };
    let value = form.value.max(0.0).round() as i64;

    let statement = format!(
        "UPDATE project_components SET {column} = ? WHERE id = ? AND project_id = ?"
    );
    let result = sqlx::query(sqlx::AssertSqlSafe(statement))
        .bind(value)
        .bind(form.pc_id)
        .bind(project_id)
        .execute(&pool)
        .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Ligne introuvable."));
    }

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

#[derive(Debug, Deserialize)]
pub struct ComponentIdForm {
    #[serde(default)]
    pub pc_id: i64,
}

pub async fn delete_component(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ComponentIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }
    sqlx::query("DELETE FROM project_components WHERE id = ? AND project_id = ?")
        .bind(form.pc_id)
        .bind(project_id)
        .execute(&pool)
        .await?;

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

// --------------------------------------------------------- Travaux & matériaux

#[derive(Debug, Deserialize)]
pub struct ItemForm {
    /// Renseigné uniquement pour la modification d'une ligne existante.
    #[serde(default)]
    pub item_id: i64,
    #[serde(default)]
    pub kind: String,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub description: String,
    #[serde(default)]
    pub quantity: f64,
    #[serde(default)]
    pub unit: String,
    #[serde(default)]
    pub unit_price: f64,
}

/// Champs d'une ligne travaux / matériaux / service, validés et normalisés.
struct ItemValues {
    kind: &'static str,
    name: String,
    description: Option<String>,
    unit: String,
    quantity: f64,
    unit_price: f64,
}

fn normalize_item(form: &ItemForm) -> Result<ItemValues, &'static str> {
    let name = clip(form.name.trim(), MAX_NAME);
    if name.is_empty() {
        return Err("Le libellé est obligatoire.");
    }
    let kind = ITEM_TYPES
        .iter()
        .find(|candidate| candidate.eq_ignore_ascii_case(form.kind.trim()))
        .copied()
        .unwrap_or("travail");
    let unit = clip(form.unit.trim(), 50);
    let description = form.description.trim();
    Ok(ItemValues {
        kind,
        name,
        description: if description.is_empty() {
            None
        } else {
            Some(clip(description, MAX_NOTE))
        },
        unit: if unit.is_empty() { "unité".to_string() } else { unit },
        quantity: form.quantity.max(0.0),
        unit_price: form.unit_price.max(0.0),
    })
}

pub async fn add_item(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ItemForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }

    let values = match normalize_item(&form) {
        Ok(values) => values,
        Err(message) => return Ok(failure(message)),
    };

    sqlx::query(
        "INSERT INTO project_items \
         (project_id, type, name, description, quantity, quantity_completed, unit, unit_price, status) \
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'En attente')",
    )
    .bind(project_id)
    .bind(values.kind)
    .bind(&values.name)
    .bind(values.description.as_deref())
    .bind(values.quantity)
    .bind(&values.unit)
    .bind(values.unit_price)
    .execute(&pool)
    .await?;

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

/// Modifie une ligne existante : type (travail / matériel / service), libellé,
/// quantité, unité, prix unitaire et note.
pub async fn edit_item(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ItemForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }

    let values = match normalize_item(&form) {
        Ok(values) => values,
        Err(message) => return Ok(failure(message)),
    };
    let ItemValues {
        kind,
        name,
        description,
        unit,
        quantity,
        unit_price,
    } = values;

    let result = sqlx::query(
        "UPDATE project_items SET type = ?, name = ?, description = ?, quantity = ?, unit = ?, unit_price = ? \
         WHERE id = ? AND project_id = ?",
    )
    .bind(kind)
    .bind(&name)
    .bind(description.as_deref())
    .bind(quantity)
    .bind(&unit)
    .bind(unit_price)
    .bind(form.item_id)
    .bind(project_id)
    .execute(&pool)
    .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Ligne introuvable."));
    }

    // L'avancement ne peut pas dépasser la nouvelle quantité : on le recadre,
    // puis on recalcule l'état (les valeurs mises à jour sont réutilisées dans
    // le même ordre par MySQL).
    sqlx::query(
        "UPDATE project_items \
         SET quantity_completed = LEAST(COALESCE(quantity_completed, 0), quantity), \
             status = CASE \
                 WHEN COALESCE(quantity_completed, 0) <= 0 THEN 'En attente' \
                 WHEN COALESCE(quantity_completed, 0) >= quantity THEN 'Terminé' \
                 ELSE 'En cours' END \
         WHERE id = ? AND project_id = ?",
    )
    .bind(form.item_id)
    .bind(project_id)
    .execute(&pool)
    .await?;

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

#[derive(Debug, Deserialize)]
pub struct ItemProgressForm {
    #[serde(default)]
    pub item_id: i64,
    #[serde(default)]
    pub change: f64,
}

/// Fait avancer (ou reculer) un travail. Le pas vaut 0,5 pour les durées.
pub async fn update_item(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ItemProgressForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }

    let current: Option<(f64, f64, Option<String>)> = sqlx::query_as(
        "SELECT CAST(COALESCE(quantity, 0) AS DOUBLE), CAST(COALESCE(quantity_completed, 0) AS DOUBLE), unit \
         FROM project_items WHERE id = ? AND project_id = ? LIMIT 1",
    )
    .bind(form.item_id)
    .bind(project_id)
    .fetch_optional(&pool)
    .await?;

    let Some((quantity, completed, unit)) = current else {
        return Ok(failure("Ligne introuvable."));
    };

    let step = item_step(unit.as_deref().unwrap_or("unité"));
    let change = if form.change == 0.0 {
        step
    } else if form.change > 0.0 {
        step
    } else {
        -step
    };
    let next = (completed + change).clamp(0.0, quantity);
    let status = if next <= 0.0 {
        "En attente"
    } else if next >= quantity {
        "Terminé"
    } else {
        "En cours"
    };

    sqlx::query("UPDATE project_items SET quantity_completed = ?, status = ? WHERE id = ? AND project_id = ?")
        .bind(next)
        .bind(status)
        .bind(form.item_id)
        .bind(project_id)
        .execute(&pool)
        .await?;

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

#[derive(Debug, Deserialize)]
pub struct ItemIdForm {
    #[serde(default)]
    pub item_id: i64,
}

pub async fn delete_item(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<ItemIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }
    sqlx::query("DELETE FROM project_items WHERE id = ? AND project_id = ?")
        .bind(form.item_id)
        .bind(project_id)
        .execute(&pool)
        .await?;

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

// ---------------------------------------------------------------- Documents

/// Dépose un document (ou une photo) dans le dossier du projet.
pub async fn upload_file(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    multipart: Multipart,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some((_, project_name)) = owned_project(&pool, user.id, project_id).await? else {
        return Ok(failure("Projet introuvable."));
    };

    let (fields, mut files) = read_multipart(multipart).await?;
    let Some((original_name, data)) = files.remove("file") else {
        return Ok(failure("Aucun fichier sélectionné."));
    };
    if data.len() > MAX_FILE_BYTES {
        return Ok(failure("Fichier trop volumineux (50 Mo maximum)."));
    }

    let extension = safe_extension(&original_name);
    let category = fields
        .get("file_category")
        .map(|value| value.trim().to_string())
        .filter(|value| FILE_CATEGORIES.contains(&value.as_str()))
        .unwrap_or_else(|| "autre".to_string());

    let stamp = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs())
        .unwrap_or(0);
    let file_name = format!("doc_{stamp}.{extension}");

    let Some(path) = store_project_file(&state.root, &project_name, &file_name, &data) else {
        return Ok(failure("Enregistrement du fichier impossible."));
    };

    let display_name = fields
        .get("display_name")
        .map(|value| clip(value.trim(), MAX_NAME))
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| clip(&original_name, MAX_NAME));
    let description = fields
        .get("description")
        .map(|value| clip(value.trim(), MAX_NOTE))
        .filter(|value| !value.is_empty());

    let mime = guess_mime(&extension);
    sqlx::query(
        "INSERT INTO project_files \
         (project_id, file_name, original_name, display_name, file_path, file_type, file_size, \
          file_category, description) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(project_id)
    .bind(&file_name)
    .bind(clip(&original_name, MAX_NAME))
    .bind(&display_name)
    .bind(&path)
    .bind(mime)
    .bind(data.len() as i64)
    .bind(&category)
    .bind(description)
    .execute(&pool)
    .await?;

    tracing::info!("Document « {display_name} » ajouté au projet {project_id} par {}", user.email);
    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

#[derive(Debug, Deserialize)]
pub struct FileIdForm {
    #[serde(default)]
    pub file_id: i64,
}

pub async fn delete_file(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    Form(form): Form<FileIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if owned_project(&pool, user.id, project_id).await?.is_none() {
        return Ok(failure("Projet introuvable."));
    }

    let stored: Option<(String,)> =
        sqlx::query_as("SELECT file_path FROM project_files WHERE id = ? AND project_id = ? LIMIT 1")
            .bind(form.file_id)
            .bind(project_id)
            .fetch_optional(&pool)
            .await?;

    sqlx::query("DELETE FROM project_files WHERE id = ? AND project_id = ?")
        .bind(form.file_id)
        .bind(project_id)
        .execute(&pool)
        .await?;

    if let Some((relative,)) = stored
        && let Some(path) = super::projects::local_project_path(&state.root, &relative)
    {
        let _ = std::fs::remove_file(path);
    }

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

/// Remplace l'image de couverture du projet.
pub async fn upload_image(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(project_id): RoutePath<i64>,
    multipart: Multipart,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let Some((_, project_name)) = owned_project(&pool, user.id, project_id).await? else {
        return Ok(failure("Projet introuvable."));
    };

    let (_, mut files) = read_multipart(multipart).await?;
    let Some((original_name, data)) = files.remove("project_image") else {
        return Ok(failure("Aucune image sélectionnée."));
    };
    if data.len() > MAX_IMAGE_BYTES {
        return Ok(failure("Image trop volumineuse (10 Mo maximum)."));
    }
    let extension = safe_extension(&original_name);
    if !IMAGE_EXTENSIONS.contains(&extension.as_str()) {
        return Ok(failure("Format d'image non pris en charge (jpg, png, gif ou webp)."));
    }

    let stamp = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_secs())
        .unwrap_or(0);
    let file_name = format!("couverture_{stamp}.{extension}");
    let Some(path) = store_project_file(&state.root, &project_name, &file_name, &data) else {
        return Ok(failure("Enregistrement de l'image impossible."));
    };

    let previous: Option<(Option<String>,)> =
        sqlx::query_as("SELECT image_path FROM projects WHERE id = ? AND owner = ? LIMIT 1")
            .bind(project_id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;

    sqlx::query("UPDATE projects SET image_path = ? WHERE id = ? AND owner = ?")
        .bind(&path)
        .bind(project_id)
        .bind(user.id)
        .execute(&pool)
        .await?;

    if let Some((Some(old),)) = previous
        && old != path
        && let Some(old_path) = super::projects::local_project_path(&state.root, &old)
    {
        let _ = std::fs::remove_file(old_path);
    }

    match render_body(&state, &pool, user.id, project_id).await? {
        Some(payload) => Ok(payload),
        None => Ok(failure("Projet introuvable.")),
    }
}

/// Supprime un projet depuis la fiche détaillée puis renvoie à la liste.
#[derive(Debug, Deserialize)]
pub struct DeleteProjectForm {
    #[serde(default)]
    pub project_id: i64,
}

pub async fn delete_project(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<DeleteProjectForm>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let Some((_, name)) = owned_project(&pool, user.id, form.project_id).await? else {
        return Ok(Redirect::to("/projects").into_response());
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
    Ok(Redirect::to("/projects?success=project_deleted").into_response())
}

/// Type MIME approximatif déduit de l'extension (colonne `file_type`).
fn guess_mime(extension: &str) -> &'static str {
    match extension {
        "pdf" => "application/pdf",
        "png" => "image/png",
        "jpg" | "jpeg" => "image/jpeg",
        "gif" => "image/gif",
        "webp" => "image/webp",
        "svg" => "image/svg+xml",
        "zip" => "application/zip",
        "txt" => "text/plain",
        "csv" => "text/csv",
        "doc" => "application/msword",
        "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "xls" => "application/vnd.ms-excel",
        "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "stl" => "model/stl",
        "step" | "stp" => "model/step",
        _ => "application/octet-stream",
    }
}
