//! Fiche produit d'un composant (lecture seule).
//!
//! Reprend `component_sheet.php` : identité, emplacement au format plat,
//! quantité ajustable sans rechargement, datasheet et paramètres techniques.
//! Les tables communes aux deux applications (`data`, `location`,
//! `category_*`, `suppliers`, `param_definitions`/`data_params`) sont relues
//! telles quelles, sans duplication de la donnée.

use axum::extract::{Path as RoutePath, Query, State};
use axum::response::{IntoResponse, Redirect, Response};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::components::{flat_location_label, with_image_src};
use super::page_context;

#[derive(Debug, Deserialize)]
pub struct SheetParams {
    param_added: Option<String>,
    param_error: Option<String>,
}

/// Ligne complète d'un composant pour la fiche, jointures comprises.
#[derive(Debug, FromRow, Serialize)]
struct SheetComponent {
    id: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    pins: Option<i32>,
    smd: Option<String>,
    marking: Option<String>,
    quantity: Option<i32>,
    datasheet: Option<String>,
    datasheet_file: Option<String>,
    comment: Option<String>,
    supplier_reference: Option<String>,
    image_path: Option<String>,
    category_name: Option<String>,
    subcategory_name: Option<String>,
    supplier_name: Option<String>,
    casier: Option<String>,
    tiroir: Option<String>,
    compartiment: Option<String>,
    storage_type: Option<String>,
    viewed_at: Option<String>,
}

pub async fn sheet(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
    Query(query): Query<SheetParams>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let Some(component) = load_sheet(&pool, id, user.id).await? else {
        return Ok(Redirect::to("/components?error=component_not_found").into_response());
    };

    let params = component_params(&pool, id).await;

    let mut context = page_context(
        &state,
        &user,
        "components",
        component.get("name").and_then(Value::as_str).unwrap_or("Composant"),
        "Fiche détaillée du composant",
    )
    .await;
    context.insert("component".to_string(), component);
    context.insert("params".to_string(), json!(params));
    context.insert("flash".to_string(), sheet_flash(query.param_added, query.param_error));

    Ok(templates::render(&state.templates, "component_sheet.html", Value::Object(context))?.into_response())
}

/// Charge le composant et ses libellés associés, en calculant l'emplacement au
/// format plat (rangements `boite`/`classeur`/`etagere`) comme la version PHP.
async fn load_sheet(pool: &MySqlPool, id: i64, owner: i64) -> AppResult<Option<Value>> {
    let row: Option<SheetComponent> = sqlx::query_as(
        "SELECT d.id, d.name, d.manufacturer, d.package, d.pins, d.smd, d.marking, d.quantity, \
         d.datasheet, d.datasheet_file, d.comment, d.supplier_reference, d.image_path, \
         ch.name AS category_name, cs.name AS subcategory_name, s.name AS supplier_name, \
         l.casier, l.tiroir, l.compartiment, l.storage_type, \
         DATE_FORMAT(NOW(), '%d/%m/%Y à %H:%i:%s') AS viewed_at \
         FROM data d \
         LEFT JOIN category_sub cs ON cs.id = d.category \
         LEFT JOIN category_head ch ON ch.id = cs.category_head_id \
         LEFT JOIN location l ON l.id = d.location_id \
         LEFT JOIN suppliers s ON s.id = d.supplier_id \
         WHERE d.id = ? AND d.owner = ?",
    )
    .bind(id)
    .bind(owner)
    .fetch_optional(pool)
    .await?;

    Ok(row.map(|component| {
        let location_label = flat_location_label(
            component.casier.as_deref(),
            component.tiroir.as_deref(),
            component.compartiment.as_deref(),
            component.storage_type.as_deref(),
        );
        let mut value = with_image_src(component, "image_path");
        if let Some(map) = value.as_object_mut() {
            map.insert(
                "location_label".to_string(),
                location_label.map_or(Value::Null, |label| json!(label)),
            );
        }
        value
    }))
}

/// Paramètres techniques non vides, dans l'ordre d'affichage de la fiche.
async fn component_params(pool: &MySqlPool, id: i64) -> Vec<Value> {
    match crate::params::rows_for_component(pool, id).await {
        Ok(rows) => rows
            .iter()
            .filter_map(|row| {
                let value = crate::params::format_value(row);
                if value.is_empty() {
                    return None;
                }
                Some(json!({ "label": row.label, "value": value }))
            })
            .collect(),
        Err(error) => {
            tracing::warn!("Paramètres techniques indisponibles (composant {id}) : {error}");
            Vec::new()
        }
    }
}

/// Flash renvoyé par la recherche de caractéristiques (`param_added` /
/// `param_error`), comme `component_sheet.php`.
fn sheet_flash(param_added: Option<String>, param_error: Option<String>) -> Value {
    if let Some(error) = param_error
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
    {
        return json!({ "kind": "error", "text": format!("Recherche impossible : {error}") });
    }

    match param_added
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
    {
        Some(code) => {
            let count: i64 = code.parse().unwrap_or(0);
            let text = if count == 0 {
                "Recherche terminée : aucune nouvelle caractéristique trouvée pour ce composant."
                    .to_string()
            } else {
                format!("Recherche terminée : {count} paramètre(s) ajouté(s).")
            };
            json!({ "kind": "ok", "text": text })
        }
        None => Value::Null,
    }
}
