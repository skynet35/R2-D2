//! Fournisseurs.
//!
//! Reprend `suppliers_management.php` : coordonnées complètes (site, courriel,
//! téléphone, adresse, pays, notes) rattachées à l'utilisateur connecté. Les
//! composants pointent vers le fournisseur par `data.supplier_id` : le nombre de
//! composants est donc affiché avant toute suppression, qui détache les
//! composants concernés.
//!
//! Les actions sont appelées en arrière-plan et la liste est remplacée par le
//! fragment `_suppliers_list.html`, sans rechargement de page.
//!
//! Chaque fournisseur peut porter un logo : un fichier déposé dans `img/`
//! (préfixe `supplier_`, comme `edit_supplier.php`) ou une URL distante.

use std::collections::HashMap;
use std::path::Path;

use axum::extract::{Multipart, State};
use axum::response::Html;
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;
use crate::util;

use super::page_context;
use super::projects::{MAX_IMAGE_BYTES, read_multipart, web_path};

const MAX_TEXT: usize = 255;

/// Extensions acceptées pour un logo déposé (liste de `edit_supplier.php`).
const LOGO_EXTENSIONS: &[&str] = &["jpg", "jpeg", "png", "gif", "webp", "svg"];

#[derive(Debug, FromRow)]
struct SupplierRow {
    id: i64,
    name: Option<String>,
    contact_person: Option<String>,
    email: Option<String>,
    phone: Option<String>,
    website: Option<String>,
    address: Option<String>,
    country: Option<String>,
    notes: Option<String>,
    logo_path: Option<String>,
    logo_url: Option<String>,
    component_count: i64,
}

struct Listing {
    suppliers: Vec<Value>,
    stats: Value,
}

fn clip(value: &str, max: usize) -> String {
    value.chars().take(max).collect()
}

fn clean(value: &str, max: usize) -> String {
    clip(value.trim(), max)
}

/// Champ facultatif : une saisie vide devient `NULL` en base.
fn optional(value: &str, max: usize) -> Option<String> {
    let text = clean(value, max);
    if text.is_empty() { None } else { Some(text) }
}

/// Chemin affichable du logo : fichier déposé dans `img/` sinon URL distante.
fn logo_src(path: Option<&str>, url: Option<&str>) -> String {
    let path = path.unwrap_or_default().trim();
    if !path.is_empty() {
        return web_path(path);
    }
    url.unwrap_or_default().trim().to_string()
}

/// Fournisseurs de l'utilisateur, avec leur nombre de composants rattachés.
async fn listing(pool: &MySqlPool, owner: i64) -> AppResult<Listing> {
    let rows: Vec<SupplierRow> = sqlx::query_as(
        "SELECT s.id, s.name, s.contact_person, s.email, s.phone, s.website, s.address, \
                s.country, s.notes, s.logo_path, s.logo_url, COUNT(d.id) AS component_count \
         FROM suppliers s \
         LEFT JOIN data d ON d.supplier_id = s.id AND d.owner = s.owner \
         WHERE s.owner = ? \
         GROUP BY s.id, s.name, s.contact_person, s.email, s.phone, s.website, s.address, \
                  s.country, s.notes, s.logo_path, s.logo_url \
         ORDER BY s.name",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;

    let unattached: (i64,) =
        sqlx::query_as("SELECT COUNT(*) FROM data WHERE owner = ? AND supplier_id IS NULL")
            .bind(owner)
            .fetch_one(pool)
            .await?;

    let mut linked = 0_i64;
    let mut suppliers = Vec::with_capacity(rows.len());
    for row in &rows {
        linked += row.component_count;
        suppliers.push(json!({
            "id": row.id,
            "name": row.name.clone().unwrap_or_default(),
            "contact": row.contact_person.clone().unwrap_or_default(),
            "email": row.email.clone().unwrap_or_default(),
            "phone": row.phone.clone().unwrap_or_default(),
            "website": row.website.clone().unwrap_or_default(),
            "address": row.address.clone().unwrap_or_default(),
            "country": row.country.clone().unwrap_or_default(),
            "notes": row.notes.clone().unwrap_or_default(),
            "logo": logo_src(row.logo_path.as_deref(), row.logo_url.as_deref()),
            "logo_url": row.logo_url.clone().unwrap_or_default(),
            "components": row.component_count,
        }));
    }

    let stats = json!({
        "suppliers": suppliers.len(),
        "linked": linked,
        "unattached": unattached.0,
    });

    Ok(Listing { suppliers, stats })
}

// ------------------------------------------------------------------- Listing

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let mut context = page_context(
        &state,
        &user,
        "suppliers",
        "Fournisseurs",
        "Coordonnées et suivi des fournisseurs de composants",
    )
    .await;
    context.insert("suppliers".to_string(), json!(data.suppliers));
    context.insert("stats".to_string(), data.stats);
    context.insert("suppliers_json".to_string(), json!(data.suppliers));

    templates::render(&state.templates, "suppliers.html", Value::Object(context))
}

/// Fragment de liste utilisé pour un rafraîchissement sans rechargement.
pub async fn refresh(State(state): State<AppState>, user: CurrentUser) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let data = listing(&pool, user.id).await?;

    let context = json!({ "suppliers": &data.suppliers });
    let html = templates::render(&state.templates, "_suppliers_list.html", context)?.0;

    Ok(Json(json!({
        "ok": true,
        "html": html,
        "stats": data.stats,
        "suppliers": data.suppliers,
    })))
}

// ------------------------------------------------------------------ Saisie

/// Saisie du formulaire fournisseur. Le logo est traité à part : il peut
/// provenir d'un fichier déposé, qui n'est pas un champ texte.
struct SupplierForm {
    supplier_id: i64,
    name: String,
    contact_person: String,
    website: String,
    email: String,
    phone: String,
    address: String,
    country: String,
    notes: String,
}

struct Fields {
    name: String,
    contact: Option<String>,
    website: Option<String>,
    email: Option<String>,
    phone: Option<String>,
    address: Option<String>,
    country: Option<String>,
    notes: Option<String>,
}

impl SupplierForm {
    /// Reconstruit la saisie depuis les champs texte d'un corps `multipart`.
    fn from_fields(fields: &HashMap<String, String>) -> Self {
        let value = |key: &str| fields.get(key).cloned().unwrap_or_default();
        Self {
            supplier_id: value("supplier_id").trim().parse().unwrap_or(0),
            name: value("name"),
            contact_person: value("contact_person"),
            website: value("website"),
            email: value("email"),
            phone: value("phone"),
            address: value("address"),
            country: value("country"),
            notes: value("notes"),
        }
    }

    /// Valide la saisie et normalise les champs facultatifs.
    fn fields(&self) -> Result<Fields, &'static str> {
        let name = clean(&self.name, MAX_TEXT);
        if name.is_empty() {
            return Err("Le nom du fournisseur est obligatoire.");
        }
        Ok(Fields {
            name,
            contact: optional(&self.contact_person, MAX_TEXT),
            website: optional(&self.website, MAX_TEXT),
            email: optional(&self.email, MAX_TEXT),
            phone: optional(&self.phone, 50),
            address: optional(&self.address, 1000),
            country: optional(&self.country, 100),
            notes: optional(&self.notes, 2000),
        })
    }
}

// ---------------------------------------------------------------------- Logo

/// Logo retenu : fichier déposé dans `img/` (prioritaire) ou URL distante.
struct Logo {
    path: Option<String>,
    url: Option<String>,
}

/// Nom de fichier dont l'extension fait partie des formats d'image acceptés.
fn has_logo_extension(file_name: &str) -> bool {
    let extension = file_name.rsplit('.').next().unwrap_or_default();
    LOGO_EXTENSIONS
        .iter()
        .any(|allowed| extension.eq_ignore_ascii_case(allowed))
}

/// Enregistre le logo déposé sous `img/supplier_<nom>_<suffixe>.<ext>`, sinon
/// retient l'URL saisie. Reprend la logique de `edit_supplier.php`.
fn store_logo(root: &Path, name: &str, upload: Option<&(String, Vec<u8>)>, url: &str) -> Logo {
    if let Some((file_name, data)) = upload {
        if data.len() > MAX_IMAGE_BYTES {
            tracing::warn!("Logo « {file_name} » refusé : fichier trop volumineux.");
        } else if !has_logo_extension(file_name) {
            tracing::warn!("Logo « {file_name} » refusé : format d'image non pris en charge.");
        } else if let Some(extension) = util::detect_image_extension(data) {
            let directory = root.join("img");
            if std::fs::create_dir_all(&directory).is_ok() {
                let safe = util::safe_file_name(name);
                let stored = format!("supplier_{safe}_{}.{extension}", util::unique_suffix());
                if std::fs::write(directory.join(&stored), data).is_ok() {
                    tracing::debug!("Logo « {file_name} » enregistré sous img/{stored}");
                    return Logo {
                        path: Some(format!("img/{stored}")),
                        url: None,
                    };
                }
            }
            tracing::warn!("Logo « {file_name} » non enregistré.");
        }
    }

    Logo {
        path: None,
        url: util::sanitize_external_url(url),
    }
}

/// Supprime un logo précédent, uniquement s'il a été déposé par l'application.
fn remove_logo_file(root: &Path, path: Option<&str>) {
    let Some(relative) = path.map(str::trim).filter(|value| !value.is_empty()) else {
        return;
    };
    if !util::is_internal_image(relative) || !relative.contains("supplier_") {
        return;
    }
    let _ = std::fs::remove_file(root.join(relative.replace('\\', "/")));
}

// ------------------------------------------------------------------- Actions

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

pub async fn add(
    State(state): State<AppState>,
    user: CurrentUser,
    multipart: Multipart,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let (inputs, mut files) = read_multipart(multipart).await?;
    let form = SupplierForm::from_fields(&inputs);
    let fields = match form.fields() {
        Ok(fields) => fields,
        Err(message) => return Ok(failure(message)),
    };

    let upload = files.remove("logo");
    let logo = store_logo(
        &state.root,
        &fields.name,
        upload.as_ref(),
        inputs.get("logo_url").map(String::as_str).unwrap_or_default(),
    );

    sqlx::query(
        "INSERT INTO suppliers (name, contact_person, website, email, phone, address, country, \
                notes, logo_path, logo_url, owner) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(&fields.name)
    .bind(&fields.contact)
    .bind(&fields.website)
    .bind(&fields.email)
    .bind(&fields.phone)
    .bind(&fields.address)
    .bind(&fields.country)
    .bind(&fields.notes)
    .bind(&logo.path)
    .bind(&logo.url)
    .bind(user.id)
    .execute(&pool)
    .await?;

    tracing::info!("Fournisseur « {} » créé par {}", fields.name, user.email);
    Ok(Json(json!({ "ok": true })))
}

pub async fn update(
    State(state): State<AppState>,
    user: CurrentUser,
    multipart: Multipart,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let (inputs, mut files) = read_multipart(multipart).await?;
    let form = SupplierForm::from_fields(&inputs);
    if form.supplier_id <= 0 {
        return Ok(failure("Fournisseur introuvable."));
    }
    let fields = match form.fields() {
        Ok(fields) => fields,
        Err(message) => return Ok(failure(message)),
    };

    let current: Option<(Option<String>, Option<String>)> = sqlx::query_as(
        "SELECT logo_path, logo_url FROM suppliers WHERE id = ? AND owner = ? LIMIT 1",
    )
    .bind(form.supplier_id)
    .bind(user.id)
    .fetch_optional(&pool)
    .await?;
    let Some((current_path, current_url)) = current else {
        return Ok(failure("Fournisseur introuvable."));
    };

    // « Retirer le logo » l'emporte ; sinon un fichier déposé ou une URL saisie
    // remplace l'existant ; sans saisie, le logo courant est conservé.
    let clear = inputs
        .get("logo_clear")
        .is_some_and(|value| !value.is_empty() && value != "0");
    let stored = store_logo(
        &state.root,
        &fields.name,
        files.remove("logo").as_ref(),
        inputs.get("logo_url").map(String::as_str).unwrap_or_default(),
    );

    let (logo_path, logo_url) = if clear {
        (None, None)
    } else if stored.path.is_some() || stored.url.is_some() {
        (stored.path, stored.url)
    } else {
        (current_path.clone(), current_url)
    };

    if current_path != logo_path {
        remove_logo_file(&state.root, current_path.as_deref());
    }

    let result = sqlx::query(
        "UPDATE suppliers SET name = ?, contact_person = ?, website = ?, email = ?, phone = ?, \
                address = ?, country = ?, notes = ?, logo_path = ?, logo_url = ? \
         WHERE id = ? AND owner = ?",
    )
    .bind(&fields.name)
    .bind(&fields.contact)
    .bind(&fields.website)
    .bind(&fields.email)
    .bind(&fields.phone)
    .bind(&fields.address)
    .bind(&fields.country)
    .bind(&fields.notes)
    .bind(&logo_path)
    .bind(&logo_url)
    .bind(form.supplier_id)
    .bind(user.id)
    .execute(&pool)
    .await?;
    if result.rows_affected() == 0 {
        return Ok(failure("Fournisseur introuvable."));
    }

    tracing::info!(
        "Fournisseur {} mis à jour par {}",
        form.supplier_id,
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}

#[derive(Debug, Deserialize)]
pub struct SupplierIdForm {
    #[serde(default)]
    pub supplier_id: i64,
}

/// Supprime un fournisseur et détache les composants qui le référençaient.
pub async fn delete(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(form): Form<SupplierIdForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    if form.supplier_id <= 0 {
        return Ok(failure("Fournisseur introuvable."));
    }

    let current: Option<(Option<String>, Option<String>)> =
        sqlx::query_as("SELECT name, logo_path FROM suppliers WHERE id = ? AND owner = ? LIMIT 1")
            .bind(form.supplier_id)
            .bind(user.id)
            .fetch_optional(&pool)
            .await?;
    let Some((name, logo_path)) = current else {
        return Ok(failure("Fournisseur introuvable."));
    };

    let mut tx = pool.begin().await?;
    sqlx::query("UPDATE data SET supplier_id = NULL WHERE supplier_id = ? AND owner = ?")
        .bind(form.supplier_id)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    sqlx::query("DELETE FROM suppliers WHERE id = ? AND owner = ?")
        .bind(form.supplier_id)
        .bind(user.id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;

    // Comme `delete_supplier.php`, le fichier du logo disparaît avec la fiche.
    if let Some(relative) = logo_path.as_deref().map(str::trim)
        && util::is_internal_image(relative)
    {
        let _ = std::fs::remove_file(state.root.join(relative.replace('\\', "/")));
    }

    tracing::info!(
        "Fournisseur « {} » supprimé par {}",
        name.unwrap_or_default(),
        user.email
    );
    Ok(Json(json!({ "ok": true })))
}
