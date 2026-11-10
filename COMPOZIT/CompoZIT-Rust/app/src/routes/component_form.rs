//! Création, modification et suppression d'un composant.
//!
//! Les règles métier reprennent celles de `add_component.php`,
//! `edit_component.php` et `delete_component.php` : mêmes colonnes écrites,
//! mêmes validations (nom obligatoire, quantité >= 0, PDF vérifié par sa
//! signature binaire), et création automatique du fabricant, du fournisseur
//! et du boîtier au passage.

use std::collections::HashMap;
use std::path::Path;

use axum::extract::{Multipart, Path as RoutePath, Query, State};
use axum::response::{Html, IntoResponse, Redirect, Response};
use serde::Deserialize;
use serde_json::{json, Map, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::{AppError, AppResult};
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;
use crate::util;

use super::components::{
    category_heads, location_options, manufacturer_names, subcategory_options, supplier_options,
    with_image_src,
};
use super::page_context;

/// Longueurs maximales des colonnes `varchar` de la table `data`.
const MAX_NAME: usize = 255;
const MAX_COMMENT: usize = 255;
const MAX_URL: usize = 255;

/// Tous les champs du formulaire, déjà nettoyés.
#[derive(Debug, Default)]
struct ComponentInput {
    name: String,
    manufacturer: String,
    new_manufacturer: String,
    package: String,
    pins: Option<i32>,
    smd: String,
    marking: String,
    quantity: i32,
    order_quantity: i32,
    price: Option<f64>,
    location_id: Option<i64>,
    category: Option<i64>,
    supplier_id: Option<i64>,
    new_supplier_name: String,
    supplier_reference: String,
    datasheet: String,
    datasheet_pdf: Option<(String, Vec<u8>)>,
    public: String,
    url: String,
    image_type: String,
    selected_existing_image: String,
    image: Option<(String, Vec<u8>)>,
    image_url: String,
    comment: String,
}

impl ComponentInput {
    /// Fabricant effectif : le nom saisi à la volée remplace le marqueur « __new__ ».
    fn effective_manufacturer(&self) -> Option<&str> {
        let value = match self.manufacturer.as_str() {
            "__new__" => self.new_manufacturer.as_str(),
            other => other,
        };
        Some(value).filter(|value| !value.is_empty())
    }

    fn is_new_manufacturer(&self) -> bool {
        !self.new_manufacturer.is_empty()
            && (self.manufacturer == "__new__" || self.manufacturer == self.new_manufacturer)
    }
}

// ------------------------------------------------------------------ Formulaire

#[derive(Debug, Default, Deserialize)]
pub struct FormMessage {
    error: Option<String>,
}

/// Message d'erreur renvoyé par un envoi de formulaire refusé.
fn form_error(params: &FormMessage) -> Value {
    match params.error.as_deref().map(str::trim) {
        Some("name_required") => json!("Le nom du composant est obligatoire."),
        Some("invalid_quantity") => json!("La quantité ne peut pas être négative."),
        _ => Value::Null,
    }
}

pub async fn new_form(
    State(state): State<AppState>,
    user: CurrentUser,
    Query(params): Query<FormMessage>,
) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let mut context = page_context(
        &state,
        &user,
        "components",
        "Nouveau composant",
        "Ajouter une référence à l'inventaire",
    )
    .await;

    context.insert("mode".to_string(), json!("create"));
    context.insert("form_action".to_string(), json!("/components"));
    context.insert("submit_label".to_string(), json!("Créer le composant"));
    context.insert("component".to_string(), empty_component());
    context.insert("subcategories".to_string(), json!([]));
    context.insert("selected_head".to_string(), Value::Null);
    context.insert("error".to_string(), form_error(&params));
    context.insert("param_definitions".to_string(), param_definitions_json(&pool).await);
    context.insert("param_values".to_string(), json!({}));
    fill_lookups(&mut context, &pool, user.id, None).await?;
    fill_api_sources(&mut context, &state);

    templates::render(&state.templates, "component_form.html", Value::Object(context))
}

pub async fn edit_form(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
    Query(params): Query<FormMessage>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let Some(component) = load_component(&pool, id, user.id).await? else {
        return Ok(Redirect::to("/components?error=component_not_found").into_response());
    };

    let current_sub = component.get("category").and_then(Value::as_i64);
    let mut selected_head = Value::Null;
    let subcategories = match current_sub {
        Some(sub) => {
            let parent: Option<(Option<i64>,)> =
                sqlx::query_as("SELECT category_head_id FROM category_sub WHERE id = ? LIMIT 1")
                    .bind(sub)
                    .fetch_optional(&pool)
                    .await?;
            match parent.and_then(|(head,)| head) {
                Some(head) => {
                    selected_head = json!(head);
                    subcategory_options(&pool, head).await?
                }
                None => Vec::new(),
            }
        }
        None => Vec::new(),
    };

    let mut context = page_context(
        &state,
        &user,
        "components",
        "Modifier le composant",
        component
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("Composant"),
    )
    .await;

    context.insert("mode".to_string(), json!("edit"));
    context.insert("form_action".to_string(), json!(format!("/components/{id}")));
    context.insert("submit_label".to_string(), json!("Enregistrer les modifications"));
    context.insert("subcategories".to_string(), json!(subcategories));
    context.insert("selected_head".to_string(), json!(selected_head));
    context.insert("error".to_string(), form_error(&params));
    context.insert("component".to_string(), component);
    context.insert("param_definitions".to_string(), param_definitions_json(&pool).await);
    let values = crate::params::component_values(&pool, id).await.unwrap_or_default();
    context.insert("param_values".to_string(), json!(values));
    fill_lookups(&mut context, &pool, user.id, Some(id)).await?;
    fill_api_sources(&mut context, &state);

    Ok(templates::render(&state.templates, "component_form.html", Value::Object(context))?.into_response())
}

async fn fill_lookups(
    context: &mut Map<String, Value>,
    pool: &MySqlPool,
    owner: i64,
    edited_component: Option<i64>,
) -> AppResult<()> {
    context.insert("manufacturers".to_string(), json!(manufacturer_names(pool, owner).await?));
    context.insert("suppliers".to_string(), json!(supplier_options(pool).await?));
    context.insert("categories".to_string(), json!(category_heads(pool).await?));

    // Regroupement par casier pour un menu déroulant lisible. Un emplacement
    // déjà occupé par un autre composant est masqué : un compartiment ne
    // contient qu'une seule référence.
    let mut groups: Vec<Value> = Vec::new();
    for location in location_options(pool, owner, edited_component.unwrap_or(0)).await? {
        if location.occupied {
            continue;
        }
        let last_matches = groups
            .last()
            .and_then(|group| group.get("casier"))
            .and_then(Value::as_str)
            .map(|casier| casier == location.casier)
            .unwrap_or(false);
        if !last_matches {
            groups.push(json!({
                "casier": location.casier,
                "locations": Vec::<Value>::new(),
            }));
        }
        if let Some(group) = groups.last_mut()
            && let Some(list) = group.get_mut("locations").and_then(Value::as_array_mut)
        {
            list.push(json!({
                "id": location.id,
                "label": location.label,
            }));
        }
    }
    context.insert("location_groups".to_string(), json!(groups));
    Ok(())
}

/// Sources proposées dans le bloc d'import du formulaire.
fn fill_api_sources(context: &mut Map<String, Value>, state: &AppState) {
    context.insert(
        "api_sources".to_string(),
        json!(super::api_settings::sources(&state.root)),
    );
}

/// Référentiel des paramètres techniques, groupé par sous-catégorie, remis au
/// script du formulaire. Une base non migrée laisse simplement un objet vide.
async fn param_definitions_json(pool: &MySqlPool) -> Value {
    match crate::params::all_definitions_map(pool).await {
        Ok(map) => crate::params::definitions_map_json(&map),
        Err(error) => {
            tracing::warn!("Référentiel des paramètres indisponible : {error}");
            json!({})
        }
    }
}

fn empty_component() -> Value {
    json!({
        "id": Value::Null,
        "name": "",
        "manufacturer": "",
        "package": "",
        "pins": Value::Null,
        "smd": "No",
        "marking": "",
        "quantity": 1,
        "order_quantity": 0,
        "price": Value::Null,
        "location_id": Value::Null,
        "datasheet": "",
        "datasheet_file": Value::Null,
        "comment": "",
        "category": Value::Null,
        "public": "No",
        "url": "",
        "image_path": Value::Null,
        "supplier_id": Value::Null,
        "supplier_reference": "",
    })
}

async fn load_component(pool: &MySqlPool, id: i64, owner: i64) -> AppResult<Option<Value>> {
    let row: Option<ComponentDetail> = sqlx::query_as(
        "SELECT id, name, manufacturer, package, pins, smd, marking, quantity, order_quantity, \
         CAST(price AS CHAR) AS price, location_id, datasheet, datasheet_file, comment, \
         category, public, url, image_path, supplier_id, supplier_reference \
         FROM data WHERE id = ? AND owner = ?",
    )
    .bind(id)
    .bind(owner)
    .fetch_optional(pool)
    .await?;

    Ok(row.map(|detail| with_image_src(detail, "image_path")))
}

#[derive(Debug, FromRow, serde::Serialize)]
struct ComponentDetail {
    id: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    pins: Option<i32>,
    smd: Option<String>,
    marking: Option<String>,
    quantity: Option<i32>,
    order_quantity: Option<i32>,
    price: Option<String>,
    location_id: Option<i64>,
    datasheet: Option<String>,
    datasheet_file: Option<String>,
    comment: Option<String>,
    category: Option<i64>,
    public: Option<String>,
    url: Option<String>,
    image_path: Option<String>,
    supplier_id: Option<i64>,
    supplier_reference: Option<String>,
}

// ------------------------------------------------------------------- Création

pub async fn create(
    State(state): State<AppState>,
    user: CurrentUser,
    multipart: Multipart,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let (fields, files) = read_multipart(multipart).await?;
    // Copie des champs bruts : `from_parts` consomme la table, et les
    // paramètres techniques y cherchent leurs clés « param_<clé> ».
    let param_source = fields.clone();
    let input = ComponentInput::from_parts(fields, files);

    if input.name.is_empty() {
        return Ok(Redirect::to("/components/new?error=name_required").into_response());
    }
    if input.quantity < 0 {
        return Ok(Redirect::to("/components/new?error=invalid_quantity").into_response());
    }

    let manufacturer = input.effective_manufacturer().map(str::to_string);
    if input.is_new_manufacturer()
        && let Some(name) = &manufacturer
    {
        let _ = sqlx::query("INSERT IGNORE INTO manufacturers (name, owner) VALUES (?, ?)")
            .bind(clip(name, MAX_NAME))
            .bind(user.id)
            .execute(&pool)
            .await;
    }

    let supplier_id = resolve_supplier(&pool, user.id, input.supplier_id, &input.new_supplier_name).await;
    let datasheet_file = store_datasheet(&state.root, &input.datasheet_pdf);
    let image_path = resolve_image(&state, &input, None).await;

    let result = sqlx::query(
        "INSERT INTO data (owner, name, manufacturer, package, pins, smd, marking, quantity, order_quantity, \
         price, location_id, datasheet, datasheet_file, comment, category, public, url, image_path, \
         supplier_id, supplier_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(user.id)
    .bind(clip(&input.name, MAX_NAME))
    .bind(manufacturer.as_deref().map(|value| clip(value, MAX_NAME)))
    .bind(optional(&input.package, MAX_NAME))
    .bind(input.pins)
    .bind(if input.smd.is_empty() { "No".to_string() } else { input.smd.clone() })
    .bind(optional(&input.marking, MAX_NAME))
    .bind(input.quantity)
    .bind(input.order_quantity)
    .bind(input.price)
    .bind(input.location_id)
    .bind(optional(&input.datasheet, MAX_URL))
    .bind(datasheet_file.as_deref())
    .bind(optional(&input.comment, MAX_COMMENT))
    .bind(input.category)
    .bind(&input.public)
    .bind(optional(&input.url, MAX_URL))
    .bind(image_path.as_deref().map(|value| clip(value, MAX_URL)))
    .bind(supplier_id)
    .bind(optional(&input.supplier_reference, MAX_NAME))
    .execute(&pool)
    .await?;

    let component_id = result.last_insert_id() as i64;

    if let Err(error) = crate::params::save(&pool, component_id, input.category, &param_source).await {
        tracing::warn!("Paramètres techniques non enregistrés (composant {component_id}) : {error}");
    }

    util::register_component_package(
        &pool,
        &state.root,
        &input.package,
        input.pins,
        &input.smd,
        image_path.as_deref(),
        user.id,
        &input.name,
    )
    .await;

    tracing::info!("Composant « {} » créé (id {component_id})", input.name);
    Ok(Redirect::to(&format!("/components?success=component_added&id={component_id}")).into_response())
}

// --------------------------------------------------------------- Modification

pub async fn update(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
    multipart: Multipart,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let existing: Option<(Option<String>, Option<String>)> =
        sqlx::query_as("SELECT image_path, datasheet_file FROM data WHERE id = ? AND owner = ?")
            .bind(id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;

    let Some((current_image, _)) = existing else {
        return Ok(Redirect::to("/components?error=component_not_found").into_response());
    };

    let (fields, files) = read_multipart(multipart).await?;
    let param_source = fields.clone();
    let input = ComponentInput::from_parts(fields, files);

    if input.name.is_empty() {
        return Ok(Redirect::to(&format!("/components/{id}/edit?error=name_required")).into_response());
    }
    if input.quantity < 0 {
        return Ok(Redirect::to(&format!("/components/{id}/edit?error=invalid_quantity")).into_response());
    }

    let manufacturer = input.effective_manufacturer().map(str::to_string);
    if input.is_new_manufacturer()
        && let Some(name) = &manufacturer
    {
        let _ = sqlx::query("INSERT IGNORE INTO manufacturers (name, owner) VALUES (?, ?)")
            .bind(clip(name, MAX_NAME))
            .bind(user.id)
            .execute(&pool)
            .await;
    }

    let supplier_id = resolve_supplier(&pool, user.id, input.supplier_id, &input.new_supplier_name).await;
    let image_path = resolve_image(&state, &input, current_image.as_deref()).await;

    sqlx::query(
        "UPDATE data SET name = ?, manufacturer = ?, package = ?, pins = ?, smd = ?, marking = ?, quantity = ?, \
         order_quantity = ?, price = ?, location_id = ?, datasheet = ?, comment = ?, category = ?, \
         public = ?, url = ?, image_path = ?, supplier_id = ?, supplier_reference = ? \
         WHERE id = ? AND owner = ?",
    )
    .bind(clip(&input.name, MAX_NAME))
    .bind(manufacturer.as_deref().map(|value| clip(value, MAX_NAME)))
    .bind(optional(&input.package, MAX_NAME))
    .bind(input.pins)
    .bind(if input.smd.is_empty() { "No".to_string() } else { input.smd.clone() })
    .bind(optional(&input.marking, MAX_NAME))
    .bind(input.quantity)
    .bind(input.order_quantity)
    .bind(input.price)
    .bind(input.location_id)
    .bind(optional(&input.datasheet, MAX_URL))
    .bind(optional(&input.comment, MAX_COMMENT))
    .bind(input.category)
    .bind(&input.public)
    .bind(optional(&input.url, MAX_URL))
    .bind(image_path.as_deref().map(|value| clip(value, MAX_URL)))
    .bind(supplier_id)
    .bind(optional(&input.supplier_reference, MAX_NAME))
    .bind(id)
    .bind(user.id)
    .execute(&pool)
    .await?;

    if let Err(error) = crate::params::save(&pool, id, input.category, &param_source).await {
        tracing::warn!("Paramètres techniques non enregistrés (composant {id}) : {error}");
    }

    util::register_component_package(
        &pool,
        &state.root,
        &input.package,
        input.pins,
        &input.smd,
        image_path.as_deref(),
        user.id,
        &input.name,
    )
    .await;

    tracing::info!("Composant {id} mis à jour");
    Ok(Redirect::to("/components?success=component_updated").into_response())
}

// ------------------------------------------------------------------ Suppression

pub async fn delete_component(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let current: Option<(Option<i32>,)> =
        sqlx::query_as("SELECT quantity FROM data WHERE id = ? AND owner = ?")
            .bind(id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;

    let Some((quantity,)) = current else {
        return Ok(Redirect::to("/components?error=component_not_found").into_response());
    };

    // Un composant encore en stock n'est jamais supprimé (règle de la version PHP).
    if quantity.unwrap_or(0) > 0 {
        return Ok(Redirect::to("/components?error=quantity_not_zero").into_response());
    }

    sqlx::query("DELETE FROM data WHERE id = ? AND owner = ?")
        .bind(id)
        .bind(user.id)
        .execute(&pool)
        .await?;

    tracing::info!("Composant {id} supprimé");
    Ok(Redirect::to("/components?success=component_deleted").into_response())
}

// ---------------------------------------------------------------------- Outils

fn optional(value: &str, max: usize) -> Option<String> {
    if value.is_empty() {
        None
    } else {
        Some(clip(value, max))
    }
}

/// Tronque une valeur à la taille de la colonne correspondante.
fn clip(value: &str, max: usize) -> String {
    if value.chars().count() <= max {
        return value.to_string();
    }
    value.chars().take(max).collect()
}

impl ComponentInput {
    fn from_parts(mut fields: HashMap<String, String>, mut files: HashMap<String, (String, Vec<u8>)>) -> Self {
        let take = |fields: &mut HashMap<String, String>, key: &str| {
            fields.remove(key).map(|value| value.trim().to_string()).unwrap_or_default()
        };

        let name = take(&mut fields, "name");
        let manufacturer = take(&mut fields, "manufacturer");
        let new_manufacturer = take(&mut fields, "new_manufacturer");
        let package = take(&mut fields, "package");
        let smd = take(&mut fields, "smd");
        let marking = take(&mut fields, "marking");
        let quantity = take(&mut fields, "quantity").parse::<i32>().unwrap_or(1);
        let order_quantity = take(&mut fields, "order_quantity").parse::<i32>().unwrap_or(0);

        let price = take(&mut fields, "price").replace(',', ".").parse::<f64>().ok();
        let pins = take(&mut fields, "pins").parse::<i32>().ok();
        let location_id = take(&mut fields, "location_id").parse::<i64>().ok();
        let category = take(&mut fields, "category").parse::<i64>().ok();
        let supplier_id = take(&mut fields, "supplier_id");

        let supplier_id = match supplier_id.as_str() {
            "" | "__new__" => None,
            other => other.parse::<i64>().ok(),
        };

        Self {
            name,
            manufacturer,
            new_manufacturer,
            package,
            pins: pins.filter(|value| *value > 0),
            smd: if smd == "Yes" { "Yes".to_string() } else { "No".to_string() },
            marking,
            quantity,
            order_quantity,
            price: price.filter(|value| *value >= 0.0),
            location_id: location_id.filter(|value| *value > 0),
            category: category.filter(|value| *value > 0),
            supplier_id,
            new_supplier_name: take(&mut fields, "new_supplier_name"),
            supplier_reference: take(&mut fields, "supplier_reference"),
            datasheet: take(&mut fields, "datasheet"),
            datasheet_pdf: files.remove("datasheet_pdf"),
            public: if fields.contains_key("public") { "Yes".to_string() } else { "No".to_string() },
            url: take(&mut fields, "url"),
            image_type: take(&mut fields, "image_type"),
            selected_existing_image: take(&mut fields, "selected_existing_image"),
            image: files.remove("image"),
            image_url: take(&mut fields, "image_url"),
            comment: take(&mut fields, "comment"),
        }
    }
}

/// Lit un corps `multipart/form-data` en séparant champs texte et fichiers.
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

/// Réutilise le fournisseur homonyme s'il existe, sinon le crée.
async fn resolve_supplier(
    pool: &MySqlPool,
    owner: i64,
    supplier_id: Option<i64>,
    new_name: &str,
) -> Option<i64> {
    if supplier_id.is_some() || new_name.is_empty() {
        return supplier_id;
    }

    match sqlx::query_as::<_, (i64,)>("SELECT id FROM suppliers WHERE name = ? LIMIT 1")
        .bind(new_name)
        .fetch_optional(pool)
        .await
    {
        Ok(Some((id,))) => Some(id),
        Ok(None) => {
            match sqlx::query("INSERT INTO suppliers (name, owner) VALUES (?, ?)")
                .bind(clip(new_name, MAX_NAME))
                .bind(owner)
                .execute(pool)
                .await
            {
                Ok(result) => Some(result.last_insert_id() as i64),
                Err(error) => {
                    tracing::warn!("Création du fournisseur « {new_name} » impossible : {error}");
                    None
                }
            }
        }
        Err(error) => {
            tracing::warn!("Recherche du fournisseur « {new_name} » impossible : {error}");
            None
        }
    }
}

/// Dépose la fiche technique PDF dans `datasheets/` si l'extension **et** la
/// signature binaire concordent.
fn store_datasheet(root: &Path, upload: &Option<(String, Vec<u8>)>) -> Option<String> {
    let (file_name, data) = upload.as_ref()?;
    if !util::is_pdf(file_name, data) {
        tracing::warn!("Fichier « {file_name} » refusé : ce n'est pas un PDF.");
        return None;
    }

    let directory = root.join("datasheets");
    if std::fs::create_dir_all(&directory).is_err() {
        return None;
    }
    let stored = format!("datasheet_{}.pdf", util::unique_suffix());
    if std::fs::write(directory.join(&stored), data).is_err() {
        return None;
    }

    Some(format!("datasheets/{stored}"))
}

/// Détermine le chemin d'image à enregistrer selon le mode choisi.
async fn resolve_image(
    state: &AppState,
    input: &ComponentInput,
    current: Option<&str>,
) -> Option<String> {
    match input.image_type.as_str() {
        "existing" => {
            let candidate = input.selected_existing_image.trim();
            if util::is_internal_image(candidate) {
                Some(candidate.to_string())
            } else {
                current.map(str::to_string)
            }
        }
        "upload" => {
            let (file_name, data) = input.image.as_ref()?;
            let extension = util::detect_image_extension(data)?;
            let directory = state.root.join("img");
            if std::fs::create_dir_all(&directory).is_err() {
                return None;
            }
            let stored = format!("component_{}.{}", util::unique_suffix(), extension);
            if std::fs::write(directory.join(&stored), data).is_err() {
                return None;
            }
            remove_previous_image(&state.root, current);
            tracing::debug!("Image « {file_name} » enregistrée sous img/{stored}");
            Some(format!("img/{stored}"))
        }
        "url" => {
            let raw = input.image_url.trim();
            if raw.is_empty() {
                return current.map(str::to_string);
            }
            if util::is_internal_image(raw) && state.root.join(raw).is_file() {
                return Some(raw.to_string());
            }
            let Some(url) = util::sanitize_external_url(raw) else {
                return current.map(str::to_string);
            };
            match import_remote_image(&state.root, &input.name, &url).await {
                Some(path) => Some(path),
                // Repli : on conserve l'URL distante plutôt que de perdre l'image.
                None => Some(clip(&url, MAX_URL)),
            }
        }
        "none" => {
            remove_previous_image(&state.root, current);
            None
        }
        _ => current.map(str::to_string),
    }
}

/// Rapatrie une image distante sous `img/<Nom>.<ext>`, en réutilisant le
/// fichier homonyme déjà présent (dédoublonnage).
async fn import_remote_image(root: &Path, component_name: &str, url: &str) -> Option<String> {
    let safe_name = util::safe_file_name(component_name);
    if safe_name.is_empty() {
        return None;
    }

    let directory = root.join("img");
    for extension in ["svg", "png", "jpg", "jpeg", "gif", "webp"] {
        let candidate = directory.join(format!("{safe_name}.{extension}"));
        if !candidate.is_file() {
            continue;
        }
        if util::is_image_file(&candidate) {
            return Some(format!("img/{safe_name}.{extension}"));
        }
        // Fichier illisible déposé par une version antérieure (page HTML d'un
        // CDN enregistrée par erreur) : on l'écarte et on retente l'import.
        tracing::warn!("Image « img/{safe_name}.{extension} » illisible : nouvel import.");
        let _ = std::fs::remove_file(&candidate);
    }

    let data = util::download(util::client(), url).await?;
    let extension = util::detect_image_extension(&data)?;
    std::fs::create_dir_all(&directory).ok()?;
    std::fs::write(directory.join(format!("{safe_name}.{extension}")), &data).ok()?;

    Some(format!("img/{safe_name}.{extension}"))
}

/// Supprime l'ancienne image si elle appartient au dossier `img/`.
fn remove_previous_image(root: &Path, previous: Option<&str>) {
    let Some(previous) = previous else {
        return;
    };
    if !util::is_internal_image(previous) {
        return;
    }
    let path = root.join(previous);
    if path.is_file() {
        let _ = std::fs::remove_file(path);
    }
}
