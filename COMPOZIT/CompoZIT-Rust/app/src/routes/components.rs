use std::collections::HashMap;

use axum::extract::{Query, State};
use axum::response::{Html, IntoResponse, Response};
use axum::Json;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sqlx::{FromRow, MySql, MySqlPool, QueryBuilder};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;
use crate::util::escape_like;
use crate::util::is_image_file;

use super::page_context;

const PER_PAGE: i64 = 25;

const JOINS: &str = " FROM data d \
     LEFT JOIN location l ON l.id = d.location_id \
     LEFT JOIN category_sub cs ON cs.id = d.category \
     LEFT JOIN category_head ch ON ch.id = cs.category_head_id \
     LEFT JOIN suppliers s ON s.id = d.supplier_id";

const SELECT_COMPONENTS: &str = "SELECT d.id, d.name, d.manufacturer, d.package, d.pins, d.smd, \
     d.marking, d.quantity, \
     d.datasheet, d.datasheet_file, d.comment, d.supplier_reference, d.image_path, \
     CASE WHEN l.storage_type IN ('boite', 'classeur', 'etagere') \
          THEN CONCAT_WS('-', NULLIF(l.casier, ''), NULLIF(l.compartiment, '')) \
          ELSE CONCAT_WS('-', NULLIF(l.casier, ''), NULLIF(l.tiroir, ''), NULLIF(l.compartiment, '')) \
     END AS location_label, \
     l.storage_type AS location_type, cs.name AS category_sub, ch.name AS category_head, \
     s.name AS supplier_name";

#[derive(Debug, Serialize, FromRow)]
pub struct ComponentRow {
    pub id: i64,
    pub name: Option<String>,
    pub manufacturer: Option<String>,
    pub package: Option<String>,
    pub pins: Option<i32>,
    pub smd: Option<String>,
    pub marking: Option<String>,
    pub quantity: Option<i32>,
    pub datasheet: Option<String>,
    pub datasheet_file: Option<String>,
    pub comment: Option<String>,
    pub supplier_reference: Option<String>,
    pub image_path: Option<String>,
    pub location_label: Option<String>,
    pub location_type: Option<String>,
    pub category_sub: Option<String>,
    pub category_head: Option<String>,
    pub supplier_name: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct ListParams {
    q: Option<String>,
    head: Option<String>,
    sub: Option<String>,
    casier: Option<String>,
    page: Option<String>,
    success: Option<String>,
    error: Option<String>,
}

pub async fn list(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<ListParams>,
    Query(raw): Query<HashMap<String, String>>,
) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let owner = user.id;

    let search = clean(params.q);
    let casier = clean(params.casier);
    let category_head = clean(params.head).and_then(|value| value.parse::<i64>().ok());
    let subcategory = clean(params.sub).and_then(|value| value.parse::<i64>().ok());
    let requested_page = clean(params.page)
        .and_then(|value| value.parse::<i64>().ok())
        .unwrap_or(1)
        .max(1);

    let count_sql = format!("SELECT COUNT(*){JOINS}");
    let mut count_query = filtered(
        QueryBuilder::new(count_sql),
        owner,
        &search,
        category_head,
        subcategory,
        &casier,
    );
    let total: i64 = count_query.build_query_scalar().fetch_one(&pool).await?;

    let total_pages = ((total + PER_PAGE - 1) / PER_PAGE).max(1);
    let page = requested_page.min(total_pages);
    let offset = (page - 1) * PER_PAGE;

    let rows_sql = format!("{SELECT_COMPONENTS}{JOINS}");
    let mut rows_query = filtered(
        QueryBuilder::new(rows_sql),
        owner,
        &search,
        category_head,
        subcategory,
        &casier,
    );
    rows_query
        .push(" ORDER BY d.name ASC, d.id ASC LIMIT ")
        .push_bind(PER_PAGE)
        .push(" OFFSET ")
        .push_bind(offset);
    let components: Vec<ComponentRow> = rows_query.build_query_as().fetch_all(&pool).await?;

    // Paramètres techniques : résumé court par composant, comme la liste PHP.
    // Une base non migrée laisse simplement la colonne vide.
    let ids: Vec<i64> = components.iter().map(|row| row.id).collect();
    let param_rows = match crate::params::rows_map(&pool, &ids).await {
        Ok(map) => map,
        Err(error) => {
            tracing::warn!("Paramètres techniques indisponibles pour la liste : {error}");
            HashMap::new()
        }
    };

    let components: Vec<Value> = components
        .into_iter()
        .map(|row| {
            let id = row.id;
            let mut value = with_image_src(row, "image_path");
            let summary = param_rows
                .get(&id)
                .map(|rows| crate::params::build_summary(rows, 6))
                .unwrap_or_default();
            if let Some(map) = value.as_object_mut() {
                map.insert("params".to_string(), json!(summary));
            }
            value
        })
        .collect();

    let (kinds, units, locations, suppliers): (i64, i64, i64, i64) = sqlx::query_as(
        "SELECT COUNT(*), CAST(COALESCE(SUM(quantity), 0) AS SIGNED), \
         COUNT(DISTINCT location_id), COUNT(DISTINCT supplier_id) \
         FROM data WHERE owner = ?",
    )
    .bind(owner)
    .fetch_one(&pool)
    .await?;

    let head_options = category_heads(&pool).await?;
    let casier_options = casier_names(&pool, owner).await?;
    // Les sous-catégories ne sont proposées qu'une fois la catégorie choisie.
    let sub_options = match category_head {
        Some(head) => subcategory_options(&pool, head).await?,
        None => Vec::new(),
    };

    // Recherche d'équivalents : proposée dès qu'une sous-catégorie est choisie.
    // Les critères saisis portent le préfixe `eq_` suivi de la clé du paramètre.
    let mut equivalent_definitions: Vec<Value> = Vec::new();
    let mut equivalent_values: HashMap<String, String> = HashMap::new();
    let mut equivalent_matches: Vec<Value> = Vec::new();
    let mut has_equivalent_search = false;

    if let Some(sub) = subcategory {
        let definitions = crate::params::definitions_for_subcategory(&pool, sub)
            .await
            .unwrap_or_default();

        for definition in &definitions {
            let value = raw
                .get(&format!("eq_{}", definition.param_key))
                .map(|value| value.trim().to_string())
                .unwrap_or_default();
            if !value.is_empty() {
                has_equivalent_search = true;
            }
            equivalent_values.insert(definition.param_key.clone(), value);
        }

        equivalent_definitions = definitions
            .iter()
            .map(|definition| {
                json!({
                    "param_key": definition.param_key,
                    "label": definition.label,
                    "unit": definition.unit,
                    "value_type": definition.value_type,
                    "compare_mode": definition.compare_mode,
                })
            })
            .collect();

        if has_equivalent_search {
            equivalent_matches =
                search_equivalent_matches(&pool, owner, sub, &definitions, &equivalent_values).await?;
        }
    }

    let mut pages = Vec::new();
    let mut first = (page - 2).max(1);
    let last = (first + 4).min(total_pages);
    first = (last - 4).max(1);
    for number in first..=last {
        pages.push(number);
    }

    let mut link_query = String::new();
    if let Some(term) = &search {
        link_query.push_str(&format!("q={}&", encode(term)));
    }
    if let Some(head) = category_head {
        link_query.push_str(&format!("head={head}&"));
    }
    if let Some(sub) = subcategory {
        link_query.push_str(&format!("sub={sub}&"));
    }
    if let Some(name) = &casier {
        link_query.push_str(&format!("casier={}&", encode(name)));
    }

    let mut context = page_context(
        &state,
        &user,
        "components",
        "Composants",
        "Inventaire personnel — recherche, filtres et stock par emplacement",
    )
    .await;

    context.insert(
        "stats".to_string(),
        json!({ "kinds": kinds, "units": units, "locations": locations, "suppliers": suppliers }),
    );
    context.insert("components".to_string(), json!(components));
    context.insert("message".to_string(), flash(params.success, params.error));
    context.insert(
        "filters".to_string(),
        json!({
            "q": search.unwrap_or_default(),
            "head": category_head,
            "sub": subcategory,
            "casier": casier.unwrap_or_default(),
            "head_options": head_options,
            "sub_options": sub_options,
            "casier_options": casier_options,
        }),
    );
    context.insert(
        "pagination".to_string(),
        json!({
            "page": page,
            "total_pages": total_pages,
            "total": total,
            "per_page": PER_PAGE,
            "pages": pages,
            "query": link_query,
            "has_prev": page > 1,
            "has_next": page < total_pages,
        }),
    );
    context.insert("equivalent_definitions".to_string(), json!(equivalent_definitions));
    context.insert("equivalent_values".to_string(), json!(equivalent_values));
    context.insert("equivalent_search".to_string(), json!(has_equivalent_search));
    context.insert("equivalent_matches".to_string(), json!(equivalent_matches));

    templates::render(&state.templates, "components.html", Value::Object(context))
}

/// Message de retour affiché en haut de la liste après une action.
fn flash(success: Option<String>, error: Option<String>) -> Value {
    if let Some(code) = error.map(|value| value.trim().to_string()).filter(|value| !value.is_empty()) {
        let text = match code.as_str() {
            "quantity_not_zero" => {
                "Suppression impossible : ce composant est encore en stock. Mettez la quantité à 0 avant de le supprimer."
            }
            "component_not_found" => "Composant introuvable (il a peut-être déjà été supprimé).",
            "invalid_id" => "Identifiant de composant invalide.",
            _ => "L'opération n'a pas pu aboutir.",
        };
        return json!({ "kind": "error", "text": text });
    }

    if let Some(code) = success.map(|value| value.trim().to_string()).filter(|value| !value.is_empty()) {
        let text = match code.as_str() {
            "component_added" => "Composant ajouté à l'inventaire.",
            "component_updated" => "Composant mis à jour.",
            "component_deleted" => "Composant supprimé.",
            _ => "Opération effectuée.",
        };
        return json!({ "kind": "ok", "text": text });
    }

    Value::Null
}

// ------------------------------------------------------------ Autocomplétion

#[derive(Debug, Deserialize)]
pub struct SearchParams {
    search: Option<String>,
}

#[derive(Debug, Serialize, FromRow)]
pub struct SearchHit {
    pub id: i64,
    pub name: Option<String>,
    pub manufacturer: Option<String>,
    pub package: Option<String>,
    pub quantity: Option<i32>,
}

/// Alimente l'autocomplétion du nom de composant (même contrat que get_components.php).
pub async fn search_api(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<SearchParams>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let term = params.search.unwrap_or_default().trim().to_string();

    if term.is_empty() {
        return Ok(Json(Vec::<SearchHit>::new()).into_response());
    }

    let hits: Vec<SearchHit> = sqlx::query_as(
        "SELECT id, name, manufacturer, package, quantity FROM data \
         WHERE owner = ? AND name LIKE ? ORDER BY name ASC LIMIT 10",
    )
    .bind(user.id)
    .bind(format!("%{}%", escape_like(&term)))
    .fetch_all(&pool)
    .await?;

    Ok(Json(hits).into_response())
}

#[derive(Debug, Deserialize)]
pub struct DuplicateParams {
    name: Option<String>,
    manufacturer: Option<String>,
}

#[derive(Debug, Serialize, FromRow)]
pub struct DuplicateHit {
    pub id: i64,
    pub name: Option<String>,
    pub manufacturer: Option<String>,
    pub package: Option<String>,
    pub quantity: Option<i32>,
    pub location: Option<String>,
}

/// Recherche les homonymes exacts avant création (même contrat que check_duplicate.php).
pub async fn check_duplicate(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<DuplicateParams>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let name = params.name.unwrap_or_default().trim().to_string();
    let manufacturer = params.manufacturer.unwrap_or_default().trim().to_string();

    if name.chars().count() < 2 {
        return Ok(Json(json!({ "ok": true, "duplicates": [] })).into_response());
    }

    let rows: Vec<DuplicateHit> = sqlx::query_as(
        "SELECT d.id, d.name, d.manufacturer, d.package, d.quantity, \
         CONCAT_WS('/', l.casier, l.tiroir, l.compartiment) AS location \
         FROM data d LEFT JOIN location l ON l.id = d.location_id \
         WHERE d.owner = ? AND LOWER(TRIM(d.name)) = LOWER(?) \
         ORDER BY d.id ASC LIMIT 10",
    )
    .bind(user.id)
    .bind(&name)
    .fetch_all(&pool)
    .await?;

    let duplicates: Vec<Value> = rows
        .into_iter()
        .map(|row| {
            let same_manufacturer = !manufacturer.is_empty()
                && row
                    .manufacturer
                    .as_deref()
                    .map(|value| value.trim().eq_ignore_ascii_case(&manufacturer))
                    .unwrap_or(false);
            json!({
                "id": row.id,
                "name": row.name,
                "manufacturer": row.manufacturer,
                "package": row.package,
                "quantity": row.quantity,
                "location": row.location,
                "same_manufacturer": same_manufacturer,
            })
        })
        .collect();

    Ok(Json(json!({ "ok": true, "duplicates": duplicates })).into_response())
}

// ------------------------------------------------------- Ajustement de stock

#[derive(Debug, Deserialize)]
pub struct QuantityPayload {
    component_id: i64,
    change: i64,
}

/// Ajuste la quantité de stock (− / +) sans recharger la page
/// (même contrat que update_quantity.php).
pub async fn adjust_quantity(
    State(state): State<AppState>,
    user: CurrentUser,
    Json(payload): Json<QuantityPayload>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let current: Option<(i32,)> = sqlx::query_as("SELECT quantity FROM data WHERE id = ? AND owner = ?")
        .bind(payload.component_id)
        .bind(user.id)
        .fetch_optional(&pool)
        .await?;

    let Some((current,)) = current else {
        return Ok((
            axum::http::StatusCode::NOT_FOUND,
            Json(json!({ "error": "Composant non trouvé ou ne vous appartient pas." })),
        )
            .into_response());
    };

    let new_quantity = (current as i64 + payload.change).max(0);
    sqlx::query("UPDATE data SET quantity = ? WHERE id = ? AND owner = ?")
        .bind(new_quantity as i32)
        .bind(payload.component_id)
        .bind(user.id)
        .execute(&pool)
        .await?;

    Ok(Json(json!({ "success": true, "new_quantity": new_quantity })).into_response())
}

// ---------------------------------------------------------------- Référentiels

#[derive(Debug, Serialize)]
pub struct LookupOption {
    pub id: i64,
    pub name: String,
}

#[derive(Debug, Serialize)]
pub struct LocationOption {
    pub id: i64,
    pub casier: String,
    pub label: String,
    pub occupied: bool,
    pub storage_type: Option<String>,
}

pub async fn manufacturer_names(pool: &MySqlPool, owner: i64) -> AppResult<Vec<String>> {
    let rows: Vec<(String,)> =
        sqlx::query_as("SELECT name FROM manufacturers WHERE owner = ? ORDER BY name")
            .bind(owner)
            .fetch_all(pool)
            .await?;
    Ok(rows.into_iter().map(|(name,)| name).collect())
}

pub async fn supplier_options(pool: &MySqlPool) -> AppResult<Vec<LookupOption>> {
    let rows: Vec<(i64, String)> = sqlx::query_as("SELECT id, name FROM suppliers ORDER BY name")
        .fetch_all(pool)
        .await?;
    Ok(rows
        .into_iter()
        .map(|(id, name)| LookupOption { id, name })
        .collect())
}

/// Emplacements de stockage du propriétaire, avec le nombre de composants qui
/// les occupent. `exclude_component` écarte un composant du décompte : en
/// modification, l'emplacement du composant en cours ne doit pas passer pour
/// occupé par lui-même.
pub async fn location_options(
    pool: &MySqlPool,
    owner: i64,
    exclude_component: i64,
) -> AppResult<Vec<LocationOption>> {
    let rows: Vec<(i64, Option<String>, Option<String>, Option<String>, Option<String>, i64)> =
        sqlx::query_as(
            "SELECT l.id, l.casier, l.tiroir, l.compartiment, l.storage_type, \
             (SELECT COUNT(*) FROM data d WHERE d.location_id = l.id AND d.id <> ?) AS component_count \
             FROM location l WHERE l.owner = ? \
             ORDER BY l.casier, CAST(l.tiroir AS UNSIGNED), l.tiroir, \
             CAST(l.compartiment AS UNSIGNED), l.compartiment",
        )
        .bind(exclude_component)
        .bind(owner)
        .fetch_all(pool)
        .await?;

    Ok(rows
        .into_iter()
        .map(|(id, casier, tiroir, compartiment, storage_type, count)| {
            let casier = casier.unwrap_or_default();
            // Comme la version PHP : les rangements plats (boite/classeur/etagere)
            // n'affichent que `casier-compartiment`, les autres `casier+tiroir-compartiment`.
            let tiroir = tiroir.unwrap_or_default();
            let compartiment = compartiment.unwrap_or_default();
            let flat = matches!(storage_type.as_deref().unwrap_or(""), "boite" | "classeur" | "etagere");
            let label = if flat {
                format!("{casier}-{compartiment}")
            } else {
                format!("{casier}{tiroir}-{compartiment}")
            };
            LocationOption {
                id,
                casier,
                label,
                occupied: count > 0,
                storage_type,
            }
        })
        .collect())
}

pub async fn category_heads(pool: &MySqlPool) -> AppResult<Vec<LookupOption>> {
    let rows: Vec<(i64, String)> =
        sqlx::query_as("SELECT id, name FROM category_head WHERE name IS NOT NULL ORDER BY name")
            .fetch_all(pool)
            .await?;
    Ok(rows
        .into_iter()
        .map(|(id, name)| LookupOption { id, name })
        .collect())
}

pub async fn subcategory_options(pool: &MySqlPool, parent: i64) -> AppResult<Vec<LookupOption>> {
    let rows: Vec<(i64, String)> = sqlx::query_as(
        "SELECT id, name FROM category_sub WHERE category_head_id = ? ORDER BY name",
    )
    .bind(parent)
    .fetch_all(pool)
    .await?;
    Ok(rows
        .into_iter()
        .map(|(id, name)| LookupOption { id, name })
        .collect())
}

pub async fn casier_names(pool: &MySqlPool, owner: i64) -> AppResult<Vec<String>> {
    let rows: Vec<(String,)> = sqlx::query_as(
        "SELECT DISTINCT casier FROM location \
         WHERE owner = ? AND casier IS NOT NULL AND casier <> '' \
         ORDER BY casier",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;
    Ok(rows.into_iter().map(|(name,)| name).collect())
}

#[derive(Debug, Deserialize)]
pub struct ParentParams {
    parent_id: Option<String>,
}

pub async fn subcategories_api(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<ParentParams>,
) -> AppResult<Response> {
    let _ = user;
    let pool = state.require_pool().await?;
    let parent = clean(params.parent_id).and_then(|value| value.parse::<i64>().ok());
    let options = match parent {
        Some(parent) => subcategory_options(&pool, parent).await?,
        None => Vec::new(),
    };
    Ok(Json(options).into_response())
}

pub async fn suppliers_api(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let _ = user;
    let pool = state.require_pool().await?;
    Ok(Json(supplier_options(&pool).await?).into_response())
}

pub async fn manufacturers_api(
    State(state): State<AppState>,
    user: CurrentUser,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    Ok(Json(manufacturer_names(&pool, user.id).await?).into_response())
}

pub async fn locations_api(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    Ok(Json(location_options(&pool, user.id, 0).await?).into_response())
}

#[derive(Debug, Serialize, FromRow)]
pub struct PackageHit {
    pub name: String,
    pub package_type: Option<String>,
    pub pin_count: Option<i32>,
    pub mounting_type: Option<String>,
}

pub async fn packages_api(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<SearchParams>,
) -> AppResult<Response> {
    let _ = user;
    let pool = state.require_pool().await?;
    let term = params.search.unwrap_or_default().trim().to_string();

    let hits: Vec<PackageHit> = if term.is_empty() {
        sqlx::query_as(
            "SELECT name, package_type, pin_count, mounting_type FROM packages \
             ORDER BY name ASC LIMIT 20",
        )
        .fetch_all(&pool)
        .await?
    } else {
        sqlx::query_as(
            "SELECT name, package_type, pin_count, mounting_type FROM packages \
             WHERE name LIKE ? ORDER BY name ASC LIMIT 20",
        )
        .bind(format!("%{}%", escape_like(&term)))
        .fetch_all(&pool)
        .await?
    };

    Ok(Json(hits).into_response())
}

#[derive(Debug, Serialize)]
pub struct ImageHit {
    pub filename: String,
    pub path: String,
    pub name: String,
}

/// Liste les images disponibles dans `img/` pour le sélecteur du formulaire
/// (même contrat que get_images.php).
pub async fn images_api(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let _ = user;
    let directory = state.root.join("img");
    let mut images: Vec<ImageHit> = Vec::new();

    if let Ok(entries) = std::fs::read_dir(&directory) {
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
            // Écarte les fichiers illisibles déposés par une version antérieure
            // (page HTML d'un CDN enregistrée sous une extension d'image) : ils
            // s'affichaient comme des vignettes cassées dans la bibliothèque.
            if !is_image_file(&path) {
                continue;
            }
            let filename = path
                .file_name()
                .and_then(|value| value.to_str())
                .unwrap_or_default()
                .to_string();
            let name = path
                .file_stem()
                .and_then(|value| value.to_str())
                .unwrap_or_default()
                .to_string();
            images.push(ImageHit {
                path: format!("img/{filename}"),
                filename,
                name,
            });
        }
    }

    images.sort_by(|left, right| left.name.to_lowercase().cmp(&right.name.to_lowercase()));
    Ok(Json(json!({ "images": images })).into_response())
}

// -------------------------------------------------------------------- Outils

/// Convertit une ligne en objet JSON en y ajoutant `image_src`, l'URL prête à
/// l'emploi de l'image (les gabarits ne savent pas tester un préfixe `http`).
pub fn with_image_src<T: Serialize>(row: T, field: &str) -> Value {
    let mut value = serde_json::to_value(&row).unwrap_or(Value::Null);
    let path = value
        .get(field)
        .and_then(Value::as_str)
        .filter(|path| !path.is_empty())
        .map(str::to_string);

    if let Some(path) = path
        && let Some(map) = value.as_object_mut()
    {
        let src = if path.starts_with("http") {
            path
        } else {
            format!("/{path}")
        };
        map.insert("image_src".to_string(), json!(src));
    }
    value
}

fn filtered(
    mut query: QueryBuilder<MySql>,
    owner: i64,
    search: &Option<String>,
    category_head: Option<i64>,
    subcategory: Option<i64>,
    casier: &Option<String>,
) -> QueryBuilder<MySql> {
    query.push(" WHERE d.owner = ").push_bind(owner);

    if let Some(term) = search {
        let like = format!("%{}%", escape_like(term));
        query
            .push(" AND (d.name LIKE ")
            .push_bind(like.clone())
            .push(" OR d.manufacturer LIKE ")
            .push_bind(like.clone())
            .push(" OR d.package LIKE ")
            .push_bind(like.clone())
            .push(" OR d.comment LIKE ")
            .push_bind(like.clone())
            .push(" OR d.supplier_reference LIKE ")
            .push_bind(like)
            .push(")");
    }

    if let Some(head) = category_head {
        query.push(" AND cs.category_head_id = ").push_bind(head);
    }

    if let Some(sub) = subcategory {
        query.push(" AND cs.id = ").push_bind(sub);
    }

    if let Some(name) = casier {
        query.push(" AND l.casier = ").push_bind(name.clone());
    }

    query
}

// ------------------------------------------------------- Recherche d'équivalents

/// Ligne d'un composant équivalent (jointures emplacement/fournisseur).
#[derive(Debug, FromRow, Serialize)]
struct EquivalentRow {
    id: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    quantity: Option<i32>,
    image_path: Option<String>,
    datasheet: Option<String>,
    datasheet_file: Option<String>,
    comment: Option<String>,
    supplier_reference: Option<String>,
    casier: Option<String>,
    tiroir: Option<String>,
    compartiment: Option<String>,
    storage_type: Option<String>,
    supplier_name: Option<String>,
}

/// Critère retenu pour la recherche d'équivalents.
enum Criterion {
    Number { param_id: i64, value: f64, compare: String },
    Text { param_id: i64, value: String },
}

/// `compozitBuildEquivalentMatches` : composants de la même sous-catégorie dont
/// les paramètres renseignés correspondent — valeur « = » exacte, bornes
/// « >= » / « <= » respectées.
async fn search_equivalent_matches(
    pool: &MySqlPool,
    owner: i64,
    subcategory: i64,
    definitions: &[crate::params::ParamDefinition],
    values: &HashMap<String, String>,
) -> AppResult<Vec<Value>> {
    let mut criteria: Vec<Criterion> = Vec::new();
    for definition in definitions {
        let Some(raw) = values.get(&definition.param_key) else {
            continue;
        };
        let raw = raw.trim();
        if raw.is_empty() {
            continue;
        }
        if definition.value_type == "number" {
            let normalized = raw.replace(',', ".");
            let Ok(value) = normalized.parse::<f64>() else {
                continue;
            };
            criteria.push(Criterion::Number {
                param_id: definition.id,
                value,
                compare: definition.compare_mode.clone(),
            });
        } else {
            criteria.push(Criterion::Text {
                param_id: definition.id,
                value: raw.to_lowercase(),
            });
        }
    }

    if criteria.is_empty() {
        return Ok(Vec::new());
    }

    let mut query = QueryBuilder::new(
        "SELECT d.id, d.name, d.manufacturer, d.package, d.quantity, d.image_path, \
         d.datasheet, d.datasheet_file, d.comment, d.supplier_reference, \
         l.casier, l.tiroir, l.compartiment, l.storage_type, s.name AS supplier_name \
         FROM data d \
         LEFT JOIN location l ON l.id = d.location_id \
         LEFT JOIN suppliers s ON s.id = d.supplier_id \
         WHERE d.owner = ",
    );
    query.push_bind(owner);
    query.push(" AND d.category = ").push_bind(subcategory);

    for criterion in &criteria {
        query.push(" AND EXISTS (SELECT 1 FROM data_params dp WHERE dp.data_id = d.id AND dp.param_id = ");
        match criterion {
            Criterion::Number { param_id, value, compare } => {
                query.push_bind(*param_id);
                if compare == ">=" {
                    query.push(" AND dp.value_num >= ").push_bind(*value);
                } else if compare == "<=" {
                    query.push(" AND dp.value_num <= ").push_bind(*value);
                } else {
                    query
                        .push(" AND ABS(dp.value_num - ")
                        .push_bind(*value)
                        .push(") < 0.000001");
                }
            }
            Criterion::Text { param_id, value } => {
                query.push_bind(*param_id);
                query
                    .push(" AND LOWER(COALESCE(dp.value_txt, '')) = ")
                    .push_bind(value.clone());
            }
        }
        query.push(")");
    }
    query.push(" ORDER BY d.name ASC, d.id DESC");

    let rows: Vec<EquivalentRow> = query.build_query_as().fetch_all(pool).await?;
    let ids: Vec<i64> = rows.iter().map(|row| row.id).collect();
    let param_rows = crate::params::rows_map(pool, &ids).await.unwrap_or_default();

    let mut matches = Vec::with_capacity(rows.len());
    for row in rows {
        let id = row.id;
        let location_label = flat_location_label(
            row.casier.as_deref(),
            row.tiroir.as_deref(),
            row.compartiment.as_deref(),
            row.storage_type.as_deref(),
        );
        let datasheet_url = datasheet_url(row.datasheet.as_deref(), row.datasheet_file.as_deref());

        let params: Vec<Value> = param_rows
            .get(&id)
            .map(|rows| {
                rows.iter()
                    .take(6)
                    .filter_map(|row| {
                        let value = crate::params::format_value(row);
                        if value.is_empty() {
                            return None;
                        }
                        Some(json!({ "label": row.label, "value": value }))
                    })
                    .collect()
            })
            .unwrap_or_default();

        let mut value = with_image_src(row, "image_path");
        if let Some(map) = value.as_object_mut() {
            map.insert(
                "location_label".to_string(),
                location_label.map_or(Value::Null, |label| json!(label)),
            );
            map.insert(
                "datasheet_url".to_string(),
                datasheet_url.map_or(Value::Null, |url| json!(url)),
            );
            map.insert("params".to_string(), json!(params));
        }
        matches.push(value);
    }

    Ok(matches)
}

/// Emplacement au format plat, comme la version PHP : les rangements
/// `boite`/`classeur`/`etagere` n'affichent qu'un niveau (ex. « BOXCONNECT02-07 »),
/// les autres conservent `casier-tiroir-compartiment`.
pub fn flat_location_label(
    casier: Option<&str>,
    tiroir: Option<&str>,
    compartiment: Option<&str>,
    storage_type: Option<&str>,
) -> Option<String> {
    let casier = casier.unwrap_or("").trim();
    let tiroir = tiroir.unwrap_or("").trim();
    let compartiment = compartiment.unwrap_or("").trim();

    if casier.is_empty() && tiroir.is_empty() && compartiment.is_empty() {
        return None;
    }

    let flat = matches!(storage_type.unwrap_or(""), "boite" | "classeur" | "etagere");
    let label = if flat {
        format!("{casier}-{compartiment}")
    } else {
        [casier, tiroir, compartiment]
            .into_iter()
            .filter(|part| !part.is_empty())
            .collect::<Vec<_>>()
            .join("-")
    };
    Some(label)
}

/// Lien « datasheet » : l'URL externe prime, sinon le PDF déposé localement.
fn datasheet_url(datasheet: Option<&str>, file: Option<&str>) -> Option<String> {
    let datasheet = datasheet.unwrap_or("").trim();
    if !datasheet.is_empty() {
        return Some(datasheet.to_string());
    }
    let file = file.unwrap_or("").trim();
    if file.is_empty() {
        return None;
    }
    Some(format!("/{file}"))
}

pub fn clean(value: Option<String>) -> Option<String> {
    value
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

/// Encodage minimal pour les liens de pagination.
fn encode(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(byte as char)
            }
            b' ' => out.push('+'),
            other => out.push_str(&format!("%{other:02X}")),
        }
    }
    out
}
