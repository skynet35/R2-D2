//! Import et export de l'inventaire des composants.
//!
//! Les formats échangés reprennent le système historique PHP : CSV au
//! séparateur `;`, export Excel `.xls` tabulé (sans dépendance XLSX) et JSON.
//! Les colonnes suivent l'ordre brut de la table `data`, pour rester
//! compatibles avec les anciens exports et avec les fichiers déjà manipulés
//! dans Excel.
//!
//! À l'import, une ligne sans identifiant connu est ajoutée ; une ligne dont
//! l'identifiant appartient déjà au propriétaire met le composant à jour. Les
//! lignes écartées sont listées avec leur motif plutôt que silencieusement
//! ignorées.

use std::collections::{HashMap, HashSet};
use std::sync::OnceLock;

use axum::extract::{Form, Multipart, State};
use axum::http::{header, HeaderValue};
use axum::response::{Html, IntoResponse, Redirect, Response};
use regex::Regex;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use sqlx::{FromRow, MySqlPool};
use tower_sessions::Session;

use crate::error::{AppError, AppResult};
use crate::session::{CurrentUser, SessionUser};
use crate::state::AppState;
use crate::templates;
use crate::util;

use super::page_context;

/// Colonnes des fichiers échangés, dans l'ordre historique du PHP.
const HEADERS: [&str; 21] = [
    "id",
    "owner",
    "name",
    "manufacturer",
    "package",
    "pins",
    "smd",
    "quantity",
    "location_id",
    "order_quantity",
    "price",
    "datasheet",
    "datasheet_file",
    "comment",
    "category",
    "public",
    "url",
    "image_path",
    "created_at",
    "supplier_id",
    "supplier_reference",
];
/// Taille maximale d'un fichier importé (alignée sur les autres écrans).
const MAX_IMPORT_BYTES: usize = 10 * 1024 * 1024;

/// Nombre de lignes en échec détaillées dans le compte rendu.
const ISSUES_LIMIT: usize = 100;

/// Types de rangement proposés lors de la création d'un emplacement
/// (mêmes clés que `config.php` : `casier`, `boite`, `classeur`, `etagere`).
const STORAGE_TYPES: [(&str, &str); 4] = [
    ("casier", "Casier"),
    ("boite", "Boîte"),
    ("classeur", "Classeur"),
    ("etagere", "Étagère"),
];

/// Import en attente de confirmation (nouveaux emplacements à créer),
/// conservé en session le temps que l'utilisateur valide la création.
const PENDING_KEY: &str = "compozit_pending_csv";

// --------------------------------------------------------------------- Écran

pub async fn page(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    render_page(&state, &user, Value::Null).await
}

pub async fn site_page() -> Redirect {
    Redirect::to("/import_database.php")
}

async fn site_user(state: &AppState) -> AppResult<CurrentUser> {
    let pool = state.require_pool().await?;
    let site = state.site_config().await;
    let username = site.username.trim().to_string();

    if username.is_empty() {
        return Err(AppError::Message(
            "Mode site : aucun identifiant n'est enregistré pour l'import/export CSV.".to_string(),
        ));
    }

    let user = sqlx::query_as::<_, (i64, String)>(
        "SELECT id, email FROM users WHERE email = ? LIMIT 1",
    )
    .bind(&username)
    .fetch_optional(&pool)
    .await?
    .map(|(id, email)| SessionUser {
        id,
        email,
        role: "administrateur".to_string(),
    })
    .ok_or_else(|| {
        AppError::Message(format!(
            "Mode site : le compte « {username} » est introuvable dans la base SQL."
        ))
    })?;

    Ok(CurrentUser(user))
}

async fn render_page(state: &AppState, user: &CurrentUser, report: Value) -> AppResult<Html<String>> {
    render_page_pending(state, user, report, None).await
}

/// Comme [`render_page`], mais affiche en plus l'aperçu des emplacements à
/// créer avant de confirmer l'import.
async fn render_page_pending(
    state: &AppState,
    user: &CurrentUser,
    report: Value,
    pending: Option<&PendingImport>,
) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;

    let (components, units): (i64, i64) = sqlx::query_as(
        "SELECT COUNT(*), CAST(COALESCE(SUM(quantity), 0) AS SIGNED) FROM data WHERE owner = ?",
    )
    .bind(user.id)
    .fetch_one(&pool)
    .await?;

    let mut context = page_context(
        state,
        user,
        "transfer",
        "Import / Export",
        "Sauvegarde et restauration de l'inventaire des composants",
    )
    .await;

    context.insert(
        "stats".to_string(),
        json!({ "components": components, "units": units }),
    );
    context.insert("columns".to_string(), json!(HEADERS));
    context.insert("report".to_string(), report);
    context.insert(
        "storage_types".to_string(),
        json!(
            STORAGE_TYPES
                .iter()
                .map(|(key, label)| json!({ "key": key, "label": label }))
                .collect::<Vec<_>>()
        ),
    );
    context.insert(
        "pending".to_string(),
        pending.map_or(Value::Null, |pending| {
            json!({ "file": pending.file_name, "locations": pending.new_locations })
        }),
    );

    templates::render(&state.templates, "transfer.html", Value::Object(context))
}

// ------------------------------------------------------------------- Export

#[derive(Debug, FromRow)]
struct ExportRow {
    id: i64,
    owner: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    pins: Option<i32>,
    smd: Option<String>,
    quantity: Option<i32>,
    location_id: Option<i64>,
    order_quantity: Option<i32>,
    price: Option<String>,
    datasheet: Option<String>,
    datasheet_file: Option<String>,
    comment: Option<String>,
    category: Option<i64>,
    public: Option<String>,
    url: Option<String>,
    image_path: Option<String>,
    created_at: Option<String>,
    supplier_id: Option<i64>,
    supplier_reference: Option<String>,
}

const EXPORT_SQL: &str = "SELECT d.id, d.owner, d.name, d.manufacturer, d.package, d.pins, \
     CAST(d.smd AS CHAR) AS smd, d.quantity, d.location_id, d.order_quantity, \
     CAST(d.price AS CHAR) AS price, d.datasheet, d.datasheet_file, d.comment, d.category, \
     CAST(d.public AS CHAR) AS public, d.url, d.image_path, CAST(d.created_at AS CHAR) AS created_at, \
     d.supplier_id, d.supplier_reference \
     FROM data d WHERE d.owner = ? ORDER BY d.name ASC";

async fn export_rows(pool: &MySqlPool, owner: i64) -> AppResult<Vec<ExportRow>> {
    Ok(sqlx::query_as::<_, ExportRow>(EXPORT_SQL)
        .bind(owner)
        .fetch_all(pool)
        .await?)
}

/// Valeurs d'une ligne, dans l'ordre de `HEADERS`.
fn row_values(row: &ExportRow) -> Vec<String> {
    vec![
        row.id.to_string(),
        row.owner.to_string(),
        row.name.clone().unwrap_or_default(),
        row.manufacturer.clone().unwrap_or_default(),
        row.package.clone().unwrap_or_default(),
        row.pins.map(|value| value.to_string()).unwrap_or_default(),
        row.smd.clone().unwrap_or_default(),
        row.quantity.unwrap_or(0).to_string(),
        row.location_id.map(|value| value.to_string()).unwrap_or_default(),
        row.order_quantity.unwrap_or(0).to_string(),
        row.price.clone().unwrap_or_default(),
        row.datasheet.clone().unwrap_or_default(),
        row.datasheet_file.clone().unwrap_or_default(),
        row.comment.clone().unwrap_or_default(),
        row.category.map(|value| value.to_string()).unwrap_or_default(),
        row.public.clone().unwrap_or_default(),
        row.url.clone().unwrap_or_default(),
        row.image_path.clone().unwrap_or_default(),
        row.created_at.clone().unwrap_or_default(),
        row.supplier_id.map(|value| value.to_string()).unwrap_or_default(),
        row.supplier_reference.clone().unwrap_or_default(),
    ]
}

pub async fn export_csv(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let rows = export_rows(&pool, user.id).await?;

    let mut csv = String::from('\u{FEFF}');
    csv.push_str(&plain_line(
        &HEADERS.iter().map(|value| value.to_string()).collect::<Vec<_>>(),
        ';',
    ));
    for row in &rows {
        csv.push_str(&quoted_csv_line(&row_values(row)));
    }

    Ok(file_response(
        csv,
        "text/csv; charset=utf-8",
        &format!("export_composants_{}.csv", timestamp()),
    ))
}

pub async fn site_export_csv(State(state): State<AppState>) -> AppResult<Response> {
    let user = site_user(&state).await?;
    export_csv(State(state), user).await
}

pub async fn export_xls(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let rows = export_rows(&pool, user.id).await?;

    let mut body = String::from('\u{FEFF}');
    body.push_str(&tab_line(
        &HEADERS.iter().map(|value| value.to_string()).collect::<Vec<_>>(),
    ));
    for row in &rows {
        body.push_str(&tab_line(&row_values(row)));
    }

    Ok(file_response(
        body,
        "application/vnd.ms-excel; charset=utf-8",
        &format!("export_composants_{}.xls", timestamp()),
    ))
}

pub async fn site_export_xls(State(state): State<AppState>) -> AppResult<Response> {
    let user = site_user(&state).await?;
    export_xls(State(state), user).await
}

pub async fn export_json(State(state): State<AppState>, user: CurrentUser) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let rows = export_rows(&pool, user.id).await?;

    let components: Vec<Value> = rows
        .iter()
        .map(|row| {
            let values = row_values(row);
            let mut object = Map::new();
            for (index, name) in HEADERS.iter().enumerate() {
                object.insert(
                    name.to_string(),
                    Value::String(values.get(index).cloned().unwrap_or_default()),
                );
            }
            Value::Object(object)
        })
        .collect();

    let count = components.len();
    let document = json!({
        "export_info": {
            "date": sqlx::types::chrono::Local::now().format("%Y-%m-%d %H:%M:%S").to_string(),
            "user_id": user.id,
            "total_components": count
        },
        "components": components,
    });

    let body = serde_json::to_string_pretty(&document).unwrap_or_else(|_| "{}".to_string());
    Ok(file_response(
        body,
        "application/json; charset=utf-8",
        &format!("export_composants_{}.json", timestamp()),
    ))
}

pub async fn site_export_json(State(state): State<AppState>) -> AppResult<Response> {
    let user = site_user(&state).await?;
    export_json(State(state), user).await
}

/// Modèle vierge : en-têtes et quelques exemples à recopier.
pub async fn template_csv(State(_state): State<AppState>, _user: CurrentUser) -> AppResult<Response> {
    let mut csv = String::from('\u{FEFF}');
    csv.push_str(&plain_line(
        &HEADERS.iter().map(|value| value.to_string()).collect::<Vec<_>>(),
        ';',
    ));
    csv.push_str(&csv_line(&sample(
        &["", "", "Résistance 1kΩ 1%", "VISHAY", "0805", "2", "Yes", "50", "12", "0", "0.05", "",
            "", "Lot de récupération", "1301", "No", "", "img/0805.png", "2026-10-21 10:00:00", "4",
            "CFR-25JB-1K0"],
    )));
    csv.push_str(&csv_line(&sample(
        &["", "", "Condensateur 100nF 50V", "MURATA", "0603", "2", "Yes", "120", "13", "0", "0.02", "",
            "", "", "202", "No", "", "img/0603.png", "2026-10-21 10:05:00", "1", "GRM188R71H104KA93D"],
    )));
    csv.push_str(&csv_line(&sample(
        &["", "", "Microcontrôleur ATmega328P", "MICROCHIP", "TQFP-32", "32", "Yes", "5", "21", "10",
            "2.80", "", "", "", "502", "No", "", "", "2026-10-21 10:10:00", "5", "ATMEGA328P-AU"],
    )));

    Ok(file_response(
        csv,
        "text/csv; charset=utf-8",
        "modele_composants.csv",
    ))
}

pub async fn site_template_csv(State(state): State<AppState>) -> AppResult<Response> {
    let user = site_user(&state).await?;
    template_csv(State(state), user).await
}

fn sample(values: &[&str; 21]) -> Vec<String> {
    values.iter().map(|value| value.to_string()).collect()
}

/// Assemble une ligne CSV : un champ est mis entre guillemets s'il contient le
/// séparateur, un guillemet ou un saut de ligne.
fn csv_line(values: &[String]) -> String {
    let cells: Vec<String> = values
        .iter()
        .map(|value| {
            if value.contains(';') || value.contains('"') || value.contains('\n') || value.contains('\r') {
                format!("\"{}\"", value.replace('"', "\"\""))
            } else {
                value.clone()
            }
        })
        .collect();
    plain_line(&cells, ';')
}

fn quoted_csv_line(values: &[String]) -> String {
    let cells: Vec<String> = values
        .iter()
        .map(|value| format!("\"{}\"", value.replace('"', "\"\"")))
        .collect();
    plain_line(&cells, ';')
}

fn tab_line(values: &[String]) -> String {
    let cells: Vec<String> = values
        .iter()
        .map(|value| value.replace('\t', " ").replace('\n', " ").replace('\r', " "))
        .collect();
    plain_line(&cells, '\t')
}

fn plain_line(values: &[String], separator: char) -> String {
    format!("{}\n", values.join(&separator.to_string()))
}

fn timestamp() -> String {
    sqlx::types::chrono::Local::now()
        .format("%Y-%m-%d_%H-%M-%S")
        .to_string()
}

fn file_response(body: String, content_type: &str, file_name: &str) -> Response {
    let mut response = body.into_response();
    let headers = response.headers_mut();
    if let Ok(value) = HeaderValue::from_str(content_type) {
        headers.insert(header::CONTENT_TYPE, value);
    }
    let disposition = format!("attachment; filename=\"{file_name}\"");
    headers.insert(
        header::CONTENT_DISPOSITION,
        HeaderValue::from_str(&disposition).unwrap_or_else(|_| HeaderValue::from_static("attachment")),
    );
    response
}

// ------------------------------------------------------------------- Import

/// Import en attente de confirmation (nouveaux emplacements détectés),
/// conservé en session le temps que l'utilisateur valide la création.
#[derive(Serialize, Deserialize)]
struct PendingImport {
    file_name: String,
    format: String,
    separator: String,
    records: Vec<HashMap<String, String>>,
    new_locations: Vec<String>,
}

pub async fn import(
    State(state): State<AppState>,
    user: CurrentUser,
    session: Session,
    multipart: Multipart,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let (records, file_name, format, separator) = match read_upload(multipart).await? {
        Ok(upload) => upload,
        Err(report) => return Ok(render_page(&state, &user, report).await?.into_response()),
    };

    // Étape 1 : repérer les emplacements absents de la base. S'il y en a, on
    // demande confirmation : leur nom et leur type restent modifiables et ils
    // ne sont créés qu'à la validation.
    let new_locations = detect_new_locations(&pool, user.id, &records).await?;
    if !new_locations.is_empty() {
        let pending = PendingImport {
            file_name,
            format: format.to_string(),
            separator,
            records,
            new_locations,
        };
        let _ = session.insert(PENDING_KEY, &pending).await;
        return Ok(
            render_page_pending(&state, &user, Value::Null, Some(&pending))
                .await?
                .into_response(),
        );
    }

    let report = apply_rows(
        &state,
        &pool,
        user.id,
        &records,
        &file_name,
        format,
        &separator,
        &HashMap::new(),
        &HashMap::new(),
    )
    .await?;
    Ok(render_page(&state, &user, report).await?.into_response())
}

pub async fn site_import(
    State(state): State<AppState>,
    multipart: Multipart,
) -> AppResult<Response> {
    let user = site_user(&state).await?;
    let pool = state.require_pool().await?;

    let report = match read_upload(multipart).await? {
        Ok((records, file_name, format, separator)) => {
            apply_rows(
                &state,
                &pool,
                user.id,
                &records,
                &file_name,
                format,
                &separator,
                &HashMap::new(),
                &HashMap::new(),
            )
            .await?
        }
        Err(report) => report,
    };

    let target = if report.get("ok").and_then(Value::as_bool).unwrap_or(false) {
        format!(
            "/import_database.php?csv_added={}&csv_updated={}&csv_skipped={}",
            report.get("added").and_then(Value::as_i64).unwrap_or(0),
            report.get("updated").and_then(Value::as_i64).unwrap_or(0),
            report.get("skipped").and_then(Value::as_i64).unwrap_or(0),
        )
    } else {
        "/import_database.php?csv_error=1".to_string()
    };
    Ok(Redirect::to(&target).into_response())
}

/// Confirme l'import en attente : crée les emplacements annoncés (nom et type
/// éventuellement modifiés) puis applique les lignes.
pub async fn confirm(
    State(state): State<AppState>,
    user: CurrentUser,
    session: Session,
    Form(form): Form<HashMap<String, String>>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;

    let Some(pending) = session.get::<PendingImport>(PENDING_KEY).await.ok().flatten() else {
        return Ok(render_page(
            &state,
            &user,
            failure("Aucun import en attente. Veuillez re-importer votre fichier CSV."),
        )
        .await?
        .into_response());
    };

    let mut location_map: HashMap<String, String> = HashMap::new();
    let mut location_type_map: HashMap<String, String> = HashMap::new();
    for (key, value) in &form {
        if let Some(original) = key.strip_prefix("loc_type_") {
            if STORAGE_TYPES.iter().any(|(storage, _)| storage == value) {
                location_type_map.insert(original.to_string(), value.clone());
            }
        } else if let Some(original) = key.strip_prefix("loc_") {
            let modified = value.trim();
            if !modified.is_empty() {
                location_map.insert(original.to_string(), modified.to_string());
            }
        }
    }

    let report = apply_rows(
        &state,
        &pool,
        user.id,
        &pending.records,
        &pending.file_name,
        &pending.format,
        &pending.separator,
        &location_map,
        &location_type_map,
    )
    .await?;
    let _ = session.remove::<PendingImport>(PENDING_KEY).await;
    Ok(render_page(&state, &user, report).await?.into_response())
}

/// Abandonne l'import en attente.
pub async fn cancel(
    State(state): State<AppState>,
    user: CurrentUser,
    session: Session,
) -> AppResult<Response> {
    let _ = session.remove::<PendingImport>(PENDING_KEY).await;
    Ok(render_page(&state, &user, json!({ "notice": "Import annulé." }))
        .await?
        .into_response())
}

/// Lit le fichier déposé et le convertit en lignes nommées. Une erreur de
/// lecture est renvoyée sous forme de compte rendu prêt à afficher.
#[allow(clippy::type_complexity)]
async fn read_upload(
    multipart: Multipart,
) -> AppResult<Result<(Vec<HashMap<String, String>>, String, &'static str, String), Value>> {
    let (_, files) = super::projects::read_multipart(multipart).await?;

    let Some((_, (file_name, data))) = files.into_iter().next() else {
        return Ok(Err(failure(
            "Aucun fichier reçu, ou fichier vide. Choisissez un fichier CSV ou JSON.",
        )));
    };

    if data.len() > MAX_IMPORT_BYTES {
        return Ok(Err(failure("Fichier trop volumineux : 10 Mo maximum.")));
    }

    let extension = extension_of(&file_name);
    let upload = match extension.as_str() {
        "json" => match parse_json(&data) {
            Ok(records) => (records, "JSON", String::new()),
            Err(message) => return Ok(Err(failure(&message))),
        },
        "csv" | "txt" | "xls" | "" => match String::from_utf8(data) {
            Ok(text) => {
                let separator = detect_separator(&text);
                let format = if extension == "xls" { "XLS" } else { "CSV" };
                (parse_csv(&text, separator), format, separator.to_string())
            }
            Err(_) => {
                return Ok(Err(failure(
                    "Le fichier n'est pas lisible en UTF-8. Enregistrez-le en CSV UTF-8 depuis Excel.",
                )));
            }
        },
        other => {
            return Ok(Err(failure(&format!(
                "Format « .{other} » non pris en charge : utilisez un fichier CSV, XLS ou JSON."
            ))));
        }
    };

    Ok(Ok((upload.0, file_name, upload.1, upload.2)))
}

/// Libellés d'emplacements du fichier qui n'existent pas encore en base.
async fn detect_new_locations(
    pool: &MySqlPool,
    owner: i64,
    records: &[HashMap<String, String>],
) -> AppResult<Vec<String>> {
    let lookups = Lookups::load(pool, owner).await?;
    let (existing_labels, existing_norms) = existing_location_index(pool, owner).await?;

    let mut new_locations: Vec<String> = Vec::new();
    let mut seen: HashSet<String> = HashSet::new();
    for record in records {
        let Some(parsed) = build_row(record, &lookups) else {
            continue;
        };
        let Some(label) = parsed.location_label else {
            continue;
        };
        if existing_labels.contains(&label) {
            continue;
        }
        if let Some(norm) = location_norm(&label)
            && existing_norms.contains(&norm)
        {
            continue;
        }
        if seen.insert(label.clone()) {
            new_locations.push(label);
        }
    }
    Ok(new_locations)
}

/// Insère ou met à jour chaque ligne et renvoie le compte rendu affiché.
/// `location_map` / `location_type_map` portent les renommages et types choisis
/// lors de l'étape de confirmation.
#[allow(clippy::too_many_arguments)]
async fn apply_rows(
    state: &AppState,
    pool: &MySqlPool,
    owner: i64,
    records: &[HashMap<String, String>],
    file_name: &str,
    format: &str,
    separator: &str,
    location_map: &HashMap<String, String>,
    location_type_map: &HashMap<String, String>,
) -> AppResult<Value> {
    let lookups = Lookups::load(pool, owner).await?;
    let owned: HashSet<i64> = sqlx::query_scalar("SELECT id FROM data WHERE owner = ?")
        .bind(owner)
        .fetch_all(pool)
        .await?
        .into_iter()
        .collect();
    let used_ids: HashSet<i64> = sqlx::query_scalar("SELECT id FROM data")
        .fetch_all(pool)
        .await?
        .into_iter()
        .collect();

    let mut added = 0i64;
    let mut updated = 0i64;
    let mut skipped = 0i64;
    let mut created_locations = 0i64;
    let mut issues: Vec<Value> = Vec::new();
    let mut location_cache: HashMap<String, i64> = HashMap::new();

    for (index, record) in records.iter().enumerate() {
        let line = index + 2; // ligne du fichier (en-tête = ligne 1)
        let Some(mut parsed) = build_row(record, &lookups) else {
            skipped += 1;
            push_issue(
                &mut issues,
                line,
                record.get("name").cloned().unwrap_or_default(),
                "Nom du composant manquant.",
            );
            continue;
        };

        // Emplacement : le libellé inconnu est créé à la volée, comme le PHP.
        if parsed.location_id.is_none() {
            if let Some(label) = parsed.location_label.clone() {
                let final_label = location_map.get(&label).cloned().unwrap_or(label.clone());
                let storage = location_type_map.get(&label).map(String::as_str);
                match find_or_create_location(pool, owner, &final_label, storage, &mut location_cache)
                    .await
                {
                    Ok((id, created)) => {
                        parsed.location_id = id;
                        if created {
                            created_locations += 1;
                        }
                    }
                    Err(error) => {
                        skipped += 1;
                        push_issue(&mut issues, line, parsed.name.clone(), &error);
                        continue;
                    }
                }
            }
        }

        let target = parsed.id.filter(|id| owned.contains(id));
        let result = match target {
            Some(id) => update_component(pool, owner, id, &parsed).await,
            None => {
                let imported_id = parsed.id.filter(|id| !used_ids.contains(id));
                insert_component(pool, owner, &parsed, imported_id).await
            }
        };

        match result {
            Ok(_) => {
                if target.is_some() {
                    updated += 1;
                } else {
                    added += 1;
                }
            }
            Err(error) => {
                skipped += 1;
                push_issue(&mut issues, line, parsed.name.clone(), &error);
                continue;
            }
        }

        if let Some(package) = Some(parsed.package.clone()).filter(|value| !value.is_empty()) {
            util::register_component_package(
                pool,
                &state.root,
                &package,
                parsed.pins,
                &parsed.smd,
                parsed.image_path.as_deref(),
                owner,
                &parsed.name,
            )
            .await;
        }
    }

    let truncated = issues.len() > ISSUES_LIMIT;
    issues.truncate(ISSUES_LIMIT);

    Ok(json!({
        "ok": true,
        "file": file_name,
        "format": format,
        "separator": separator,
        "total": records.len(),
        "added": added,
        "updated": updated,
        "skipped": skipped,
        "locations_created": created_locations,
        "issues": issues,
        "truncated": truncated,
    }))
}

fn push_issue(issues: &mut Vec<Value>, line: usize, name: String, message: &str) {
    issues.push(json!({ "line": line, "name": name, "message": message }));
}

fn failure(message: &str) -> Value {
    json!({ "ok": false, "text": message })
}

// ------------------------------------------------------- Ligne prête à écrire

struct ParsedRow {
    id: Option<i64>,
    name: String,
    manufacturer: Option<String>,
    package: String,
    pins: Option<i32>,
    smd: String,
    quantity: i32,
    order_quantity: i32,
    price: Option<f64>,
    location_id: Option<i64>,
    /// Libellé d'emplacement à résoudre (et créer au besoin) à l'application.
    location_label: Option<String>,
    category: Option<i64>,
    supplier_id: Option<i64>,
    supplier_reference: String,
    datasheet: String,
    datasheet_file: Option<String>,
    comment: String,
    url: String,
    image_path: Option<String>,
    public: String,
    created_at: Option<String>,
}

/// Traduit une ligne du fichier en valeurs prêtes pour la base. Renvoie `None`
/// quand le nom est absent (ligne inutilisable).
fn build_row(record: &HashMap<String, String>, lookups: &Lookups) -> Option<ParsedRow> {
    let value = |key: &str| record.get(key).map(|value| value.trim().to_string()).unwrap_or_default();

    let name = value("name");
    if name.is_empty() {
        return None;
    }

    // Emplacement : le PHP accepte la colonne `location` (libellé) ou
    // `location_id`. Un identifiant numérique n'est retenu que s'il existe ; un
    // libellé est résolu (et créé si besoin) au moment d'appliquer la ligne.
    let location_value = {
        let label = value("location");
        if label.is_empty() {
            value("location_id")
        } else {
            label
        }
    };
    let (location_id, location_label) = if location_value.is_empty() {
        (None, None)
    } else if let Ok(id) = location_value.parse::<i64>() {
        (
            Some(id).filter(|id| lookups.location_ids.contains(id)),
            None,
        )
    } else {
        (None, Some(location_value))
    };

    let category_value = value("category");
    let head_name = value("category_head");
    let sub_name = value("category_sub");
    let category = if !category_value.is_empty() {
        lookups.resolve_category(&head_name, &category_value)
    } else {
        lookups.resolve_category(&head_name, &sub_name)
    };

    let supplier = value("supplier");
    let supplier_id = if !value("supplier_id").is_empty() {
        value("supplier_id").parse::<i64>().ok().filter(|id| lookups.supplier_ids.contains(id))
    } else if supplier.is_empty() {
        None
    } else {
        lookups.resolve_supplier(&supplier)
    };

    Some(ParsedRow {
        id: value("id").parse::<i64>().ok(),
        name: clip(&name, 255),
        manufacturer: optional(&value("manufacturer"), 255),
        package: clip(&value("package"), 255),
        pins: parse_int(&value("pins")).filter(|value| *value > 0),
        smd: yes_no(&value("smd")),
        quantity: parse_int(&value("quantity")).unwrap_or(0).max(0),
        order_quantity: parse_int(&value("order_quantity")).unwrap_or(0).max(0),
        price: parse_decimal(&value("price")).filter(|value| *value >= 0.0),
        location_id,
        location_label,
        category,
        supplier_id,
        supplier_reference: clip(&value("supplier_reference"), 255),
        datasheet: clip(&value("datasheet"), 255),
        datasheet_file: optional(&value("datasheet_file"), 500),
        comment: clip(&value("comment"), 255),
        url: clip(&value("url"), 255),
        image_path: optional(&value("image_path"), 255),
        public: yes_no(&value("public")),
        created_at: parse_created_at(&value("created_at")),
    })
}

async fn insert_component(
    pool: &MySqlPool,
    owner: i64,
    row: &ParsedRow,
    imported_id: Option<i64>,
) -> Result<(), String> {
    let query = if imported_id.is_some() {
        "INSERT INTO data (id, owner, name, manufacturer, package, pins, smd, quantity, order_quantity, \
         price, location_id, datasheet, datasheet_file, comment, category, public, url, image_path, \
         supplier_id, supplier_reference, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))"
    } else {
        "INSERT INTO data (owner, name, manufacturer, package, pins, smd, quantity, order_quantity, \
         price, location_id, datasheet, datasheet_file, comment, category, public, url, image_path, \
         supplier_id, supplier_reference, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))"
    };

    let mut query = sqlx::query(query);
    if let Some(id) = imported_id {
        query = query.bind(id);
    }

    query
        .bind(owner)
        .bind(&row.name)
        .bind(row.manufacturer.as_deref())
        .bind(optional(&row.package, 255))
        .bind(row.pins)
        .bind(&row.smd)
        .bind(row.quantity)
        .bind(row.order_quantity)
        .bind(row.price)
        .bind(row.location_id)
        .bind(optional(&row.datasheet, 255))
        .bind(row.datasheet_file.as_deref())
        .bind(optional(&row.comment, 255))
        .bind(row.category)
        .bind(&row.public)
        .bind(optional(&row.url, 255))
        .bind(row.image_path.as_deref())
        .bind(row.supplier_id)
        .bind(optional(&row.supplier_reference, 255))
        .bind(row.created_at.as_deref())
        .execute(pool)
        .await
        .map_err(|error| format!("Ajout impossible : {error}"))
        .map(|_| ())
}

/// Le PDF joint à la fiche n'est pas touché : il n'est pas modifiable depuis un
/// tableur et serait perdu à chaque réimportation.
async fn update_component(pool: &MySqlPool, owner: i64, id: i64, row: &ParsedRow) -> Result<(), String> {
    sqlx::query(
        "UPDATE data SET name = ?, manufacturer = ?, package = ?, pins = ?, smd = ?, quantity = ?, \
         order_quantity = ?, price = ?, location_id = ?, datasheet = ?, comment = ?, category = ?, \
         public = ?, url = ?, image_path = ?, supplier_id = ?, supplier_reference = ? \
         WHERE id = ? AND owner = ?",
    )
    .bind(&row.name)
    .bind(row.manufacturer.as_deref())
    .bind(optional(&row.package, 255))
    .bind(row.pins)
    .bind(&row.smd)
    .bind(row.quantity)
    .bind(row.order_quantity)
    .bind(row.price)
    .bind(row.location_id)
    .bind(optional(&row.datasheet, 255))
    .bind(optional(&row.comment, 255))
    .bind(row.category)
    .bind(&row.public)
    .bind(optional(&row.url, 255))
    .bind(row.image_path.as_deref())
    .bind(row.supplier_id)
    .bind(optional(&row.supplier_reference, 255))
    .bind(id)
    .bind(owner)
    .execute(pool)
    .await
    .map_err(|error| format!("Mise à jour impossible : {error}"))
    .map(|_| ())
}

// --------------------------------------------------------------- Référentiels

/// Référentiels du propriétaire indexés par libellé normalisé, pour retrouver
/// une catégorie ou un fournisseur depuis son nom.
struct Lookups {
    location_ids: HashSet<i64>,
    supplier_ids: HashSet<i64>,
    suppliers: HashMap<String, i64>,
    subs: HashMap<String, i64>,
    sub_ids: HashSet<i64>,
    subs_by_head: HashMap<(String, String), i64>,
    heads: HashMap<String, i64>,
}

impl Lookups {
    async fn load(pool: &MySqlPool, owner: i64) -> AppResult<Self> {
        let location_ids: HashSet<i64> =
            sqlx::query_scalar("SELECT id FROM location WHERE owner = ?")
                .bind(owner)
                .fetch_all(pool)
                .await?
                .into_iter()
                .collect();

        let mut suppliers = HashMap::new();
        let mut supplier_ids = HashSet::new();
        let rows: Vec<(i64, Option<String>)> = sqlx::query_as("SELECT id, name FROM suppliers")
            .fetch_all(pool)
            .await?;
        for (id, name) in rows {
            supplier_ids.insert(id);
            if let Some(name) = name {
                suppliers.entry(key(&name)).or_insert(id);
            }
        }

        let mut heads = HashMap::new();
        let rows: Vec<(i64, Option<String>)> =
            sqlx::query_as("SELECT id, name FROM category_head")
                .fetch_all(pool)
                .await?;
        for (id, name) in rows {
            if let Some(name) = name {
                heads.entry(key(&name)).or_insert(id);
            }
        }

        let mut subs = HashMap::new();
        let mut sub_ids = HashSet::new();
        let mut subs_by_head = HashMap::new();
        let rows: Vec<(i64, Option<String>, Option<i64>, Option<String>)> = sqlx::query_as(
            "SELECT cs.id, cs.name, cs.category_head_id, ch.name FROM category_sub cs \
             LEFT JOIN category_head ch ON ch.id = cs.category_head_id",
        )
        .fetch_all(pool)
        .await?;
        for (id, name, head_id, head_name) in rows {
            let Some(name) = name else { continue };
            let sub_key = key(&name);
            sub_ids.insert(id);
            subs.entry(sub_key.clone()).or_insert(id);
            if let (Some(head_id), Some(head_name)) = (head_id, head_name) {
                subs_by_head.insert((key(&head_name), sub_key), id);
                subs_by_head.insert((head_id.to_string(), key(&name)), id);
            }
        }

        Ok(Self {
            location_ids,
            supplier_ids,
            suppliers,
            subs,
            sub_ids,
            subs_by_head,
            heads,
        })
    }

    /// Accepte le nom du fournisseur ou son identifiant numérique.
    fn resolve_supplier(&self, value: &str) -> Option<i64> {
        if let Ok(id) = value.trim().parse::<i64>()
            && self.supplier_ids.contains(&id)
        {
            return Some(id);
        }
        self.suppliers.get(&key(value)).copied()
    }

    /// Une sous-catégorie est cherchée par son nom, éventuellement restreinte à
    /// la catégorie principale. Une valeur qui désigne une catégorie
    /// principale est acceptée : le libellé seul suffit alors.
    fn resolve_category(&self, head: &str, sub: &str) -> Option<i64> {
        if sub.is_empty() {
            return None;
        }
        if let Ok(id) = sub.trim().parse::<i64>()
            && self.sub_ids.contains(&id)
        {
            return Some(id);
        }
        if !head.is_empty() {
            let head_key = self
                .heads
                .get(&key(head))
                .map(|id| id.to_string())
                .unwrap_or_else(|| key(head));
            if let Some(id) = self.subs_by_head.get(&(head_key, key(sub))) {
                return Some(*id);
            }
        }
        self.subs.get(&key(sub)).copied()
    }
}

// -------------------------------------------------------------- Emplacements

/// Un rangement « plat » (boîte, classeur, étagère) n'affiche qu'un niveau :
/// `casier-compartiment`, comme `config.php`.
fn is_flat_storage(storage_type: &str) -> bool {
    matches!(storage_type, "boite" | "classeur" | "etagere")
}

/// Libellé d'emplacement, comme `compozitCsvLocationLabel()` du PHP.
fn location_label(casier: &str, tiroir: &str, compartiment: &str, storage_type: &str) -> String {
    let casier = casier.trim();
    let tiroir = tiroir.trim();
    let compartiment = compartiment.trim();

    if casier.is_empty() && tiroir.is_empty() && compartiment.is_empty() {
        return String::new();
    }
    if is_flat_storage(storage_type) {
        return format!("{casier}-{compartiment}");
    }
    if !casier.is_empty() && !tiroir.is_empty() && !compartiment.is_empty() {
        return format!("{casier}{tiroir}-{compartiment}");
    }
    [casier, tiroir, compartiment]
        .into_iter()
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join("-")
}

fn flat_label_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^(.+)-(\d+)$").expect("motif d'emplacement plat"))
}

fn alnum_label_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^([A-Za-z]+)(\d+)-(\d+)$").expect("motif casierNN-CC"))
}

fn letter_num_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^([A-Za-z]+)-?(\d+)$").expect("motif casierNN"))
}

fn triple_label_regex() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| Regex::new(r"^([^-/]+)[-/]([^-/]+)[-/]([^-/]+)$").expect("motif triple"))
}

/// Décompose un libellé en `(casier, tiroir, compartiment)`, comme
/// `compozitParseLocationLabel()`.
fn parse_location_label(value: &str, storage_type: Option<&str>) -> Option<(String, String, String)> {
    let value: String = value
        .chars()
        .filter(|character| !character.is_whitespace())
        .collect();
    if value.is_empty() {
        return None;
    }

    if storage_type.map(is_flat_storage).unwrap_or(false)
        && let Some(caps) = flat_label_regex().captures(&value)
    {
        return Some((caps[1].to_string(), "1".to_string(), caps[2].to_string()));
    }
    if let Some(caps) = alnum_label_regex().captures(&value) {
        return Some((caps[1].to_string(), caps[2].to_string(), caps[3].to_string()));
    }
    if let Some(caps) = letter_num_regex().captures(&value) {
        return Some((caps[1].to_string(), caps[2].to_string(), "1".to_string()));
    }
    if let Some(caps) = triple_label_regex().captures(&value) {
        return Some((
            caps[1].trim().to_string(),
            caps[2].trim().to_string(),
            caps[3].trim().to_string(),
        ));
    }
    Some((value, "1".to_string(), "1".to_string()))
}

/// Forme normalisée `casier+tiroir-compartiment`, pour comparer un libellé
/// inconnu aux emplacements déjà présents.
fn location_norm(label: &str) -> Option<String> {
    parse_location_label(label, None)
        .map(|(casier, tiroir, compartiment)| format!("{casier}{tiroir}-{compartiment}"))
}

/// Libellés des emplacements existants (deux formats d'affichage confondus),
/// plus leur forme normalisée.
async fn existing_location_index(
    pool: &MySqlPool,
    owner: i64,
) -> AppResult<(HashSet<String>, HashSet<String>)> {
    let rows: Vec<(Option<String>, Option<String>, Option<String>, Option<String>)> = sqlx::query_as(
        "SELECT casier, tiroir, compartiment, storage_type FROM location WHERE owner = ?",
    )
    .bind(owner)
    .fetch_all(pool)
    .await?;

    let mut labels = HashSet::new();
    let mut norms = HashSet::new();
    for (casier, tiroir, compartiment, storage_type) in rows {
        let casier = casier.unwrap_or_default();
        let tiroir = tiroir.unwrap_or_default();
        let compartiment = compartiment.unwrap_or_default();
        let storage_type = storage_type.unwrap_or_default();
        labels.insert(location_label(&casier, &tiroir, &compartiment, &storage_type));
        norms.insert(format!("{casier}{tiroir}-{compartiment}"));
    }
    Ok((labels, norms))
}

/// Retrouve l'identifiant d'un emplacement par son libellé (les deux formats
/// d'affichage sont acceptés) ou son identifiant numérique, sinon le crée.
/// Renvoie `(identifiant, créé)`. Le cache évite les requêtes répétées.
async fn find_or_create_location(
    pool: &MySqlPool,
    owner: i64,
    label: &str,
    storage_type: Option<&str>,
    cache: &mut HashMap<String, i64>,
) -> Result<(Option<i64>, bool), String> {
    let label = label.trim().to_string();
    if label.is_empty() {
        return Ok((None, false));
    }
    if let Some(id) = cache.get(&label) {
        return Ok((Some(*id), false));
    }

    // 1. Emplacement déjà existant, quel que soit le format d'affichage.
    let existing: Option<i64> = sqlx::query_scalar(
        "SELECT id FROM location WHERE owner = ? AND \
         CASE WHEN storage_type IN ('boite', 'classeur', 'etagere') \
              THEN CONCAT(casier, '-', compartiment) \
              ELSE CONCAT(casier, tiroir, '-', compartiment) END = ? LIMIT 1",
    )
    .bind(owner)
    .bind(&label)
    .fetch_optional(pool)
    .await
    .map_err(|error| format!("Recherche d'emplacement impossible : {error}"))?;
    if let Some(id) = existing {
        cache.insert(label, id);
        return Ok((Some(id), false));
    }

    // 2. Identifiant numérique d'un emplacement existant.
    if let Ok(id) = label.parse::<i64>() {
        let known: Option<i64> =
            sqlx::query_scalar("SELECT id FROM location WHERE id = ? AND owner = ? LIMIT 1")
                .bind(id)
                .bind(owner)
                .fetch_optional(pool)
                .await
                .map_err(|error| format!("Recherche d'emplacement impossible : {error}"))?;
        if let Some(id) = known {
            cache.insert(label, id);
            return Ok((Some(id), false));
        }
    }

    // 3. Création à partir du libellé décomposé.
    let Some((casier, tiroir, compartiment)) = parse_location_label(&label, storage_type) else {
        return Ok((None, false));
    };

    let found: Option<i64> = sqlx::query_scalar(
        "SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ? LIMIT 1",
    )
    .bind(owner)
    .bind(&casier)
    .bind(&tiroir)
    .bind(&compartiment)
    .fetch_optional(pool)
    .await
    .map_err(|error| format!("Recherche d'emplacement impossible : {error}"))?;
    if let Some(id) = found {
        cache.insert(label, id);
        return Ok((Some(id), false));
    }

    let storage = storage_type.unwrap_or("casier");
    let result = sqlx::query(
        "INSERT INTO location (owner, casier, tiroir, compartiment, description, storage_type) \
         VALUES (?, ?, ?, ?, NULL, ?)",
    )
    .bind(owner)
    .bind(&casier)
    .bind(&tiroir)
    .bind(&compartiment)
    .bind(storage)
    .execute(pool)
    .await;

    match result {
        Ok(done) => {
            let id = done.last_insert_id() as i64;
            cache.insert(label, id);
            Ok((Some(id), true))
        }
        Err(sqlx::Error::Database(database)) if database.code().as_deref() == Some("23000") => {
            let found: Option<i64> = sqlx::query_scalar(
                "SELECT id FROM location WHERE owner = ? AND casier = ? AND tiroir = ? AND compartiment = ? LIMIT 1",
            )
            .bind(owner)
            .bind(&casier)
            .bind(&tiroir)
            .bind(&compartiment)
            .fetch_optional(pool)
            .await
            .map_err(|error| format!("Recherche d'emplacement impossible : {error}"))?;
            if let Some(id) = found {
                cache.insert(label, id);
                Ok((Some(id), false))
            } else {
                Ok((None, false))
            }
        }
        Err(error) => Err(format!("Création d'emplacement impossible : {error}")),
    }
}

// ---------------------------------------------------------------- Utilitaires

fn extension_of(file_name: &str) -> String {
    file_name
        .rsplit_once('.')
        .map(|(_, extension)| extension.to_ascii_lowercase())
        .unwrap_or_default()
}

/// Normalise un libellé ou un nom de colonne : sans accent, sans séparateur,
/// en majuscules — « Référence fournisseur » et « reference_fournisseur »
/// désignent donc la même colonne.
fn key(value: &str) -> String {
    value
        .chars()
        .filter(|character| !character.is_whitespace() && *character != '_' && *character != '-')
        .map(fold)
        .collect::<String>()
        .to_uppercase()
}

/// Lettre sans accent (é → E), les autres caractères sont conservés.
fn fold(character: char) -> char {
    match character {
        'à' | 'á' | 'â' | 'ã' | 'ä' | 'å' | 'À' | 'Á' | 'Â' | 'Ã' | 'Ä' | 'Å' => 'a',
        'ç' | 'Ç' => 'c',
        'è' | 'é' | 'ê' | 'ë' | 'È' | 'É' | 'Ê' | 'Ë' => 'e',
        'ì' | 'í' | 'î' | 'ï' | 'Ì' | 'Í' | 'Î' | 'Ï' => 'i',
        'ñ' | 'Ñ' => 'n',
        'ò' | 'ó' | 'ô' | 'õ' | 'ö' | 'Ò' | 'Ó' | 'Ô' | 'Õ' | 'Ö' => 'o',
        'ù' | 'ú' | 'û' | 'ü' | 'Ù' | 'Ú' | 'Û' | 'Ü' => 'u',
        'ý' | 'ÿ' | 'Ý' => 'y',
        other => other,
    }
}

fn optional(value: &str, max: usize) -> Option<String> {
    if value.is_empty() {
        None
    } else {
        Some(clip(value, max))
    }
}

fn clip(value: &str, max: usize) -> String {
    if value.chars().count() <= max {
        return value.to_string();
    }
    value.chars().take(max).collect()
}

/// Entier, en tolérant les séparateurs de milliers (espace, espace insécable).
fn parse_int(value: &str) -> Option<i32> {
    let cleaned: String = value
        .chars()
        .filter(|character| character.is_ascii_digit() || *character == '-')
        .collect();
    cleaned.parse::<i32>().ok()
}

/// Décimal, en acceptant la virgule française et les séparateurs de milliers.
fn parse_decimal(value: &str) -> Option<f64> {
    if value.trim().is_empty() {
        return None;
    }
    let cleaned: String = value
        .chars()
        .filter_map(|character| match character {
            '0'..='9' | '-' | '.' => Some(character),
            ',' => Some('.'),
            _ => None,
        })
        .collect();
    cleaned.parse::<f64>().ok()
}

/// Colonne « oui / non » de la base, qui contient « Yes » ou « No ».
fn yes_no(value: &str) -> String {
    match value.trim().to_lowercase().as_str() {
        "yes" | "y" | "oui" | "o" | "1" | "true" | "vrai" | "x" | "smd" => "Yes".to_string(),
        _ => "No".to_string(),
    }
}

// ------------------------------------------------------------- Lecture CSV/JSON

/// Séparateur le plus probable, déduit de la ligne d'en-tête.
fn detect_separator(input: &str) -> char {
    let header = input.lines().next().unwrap_or("");
    let mut best = (';', 0usize);
    for candidate in [';', ',', '\t', '|'] {
        let count = header.matches(candidate).count();
        if count > best.1 {
            best = (candidate, count);
        }
    }
    best.0
}

/// Découpe un CSV (RFC 4180) et convertit chaque ligne en colonnes nommées.
fn parse_csv(input: &str, separator: char) -> Vec<HashMap<String, String>> {
    let input = input.strip_prefix('\u{FEFF}').unwrap_or(input);
    let mut rows: Vec<Vec<String>> = Vec::new();
    let mut row: Vec<String> = Vec::new();
    let mut field = String::new();
    let mut quoted = false;
    let mut characters = input.chars().peekable();

    while let Some(character) = characters.next() {
        if quoted {
            if character == '"' {
                if characters.peek() == Some(&'"') {
                    characters.next();
                    field.push('"');
                } else {
                    quoted = false;
                }
            } else {
                field.push(character);
            }
            continue;
        }

        if character == '"' && field.trim().is_empty() {
            field.clear();
            quoted = true;
        } else if character == separator {
            row.push(std::mem::take(&mut field));
        } else if character == '\n' {
            row.push(std::mem::take(&mut field));
            if row.iter().any(|value| !value.trim().is_empty()) {
                rows.push(std::mem::take(&mut row));
            } else {
                row.clear();
            }
        } else if character != '\r' {
            field.push(character);
        }
    }

    row.push(field);
    if row.iter().any(|value| !value.trim().is_empty()) {
        rows.push(row);
    }

    let Some(headers) = rows.first() else {
        return Vec::new();
    };
    let columns: Vec<String> = headers.iter().map(|name| column_key(name)).collect();

    rows.iter()
        .skip(1)
        .map(|values| {
            let mut record = HashMap::new();
            for (index, column) in columns.iter().enumerate() {
                if let Some(value) = values.get(index) {
                    record.insert(column.clone(), value.trim().to_string());
                }
            }
            record
        })
        .collect()
}

/// Lit un tableau JSON, ou l'objet `{ "components": [ … ] }` produit par
/// l'export, puis convertit chaque entrée en colonnes nommées.
fn parse_json(data: &[u8]) -> Result<Vec<HashMap<String, String>>, String> {
    // Le BOM UTF-8 est ajouté par les éditeurs Windows : il n'est pas du JSON.
    let data = data.strip_prefix(&[0xEF, 0xBB, 0xBF]).unwrap_or(data);
    let value: Value =
        serde_json::from_slice(data).map_err(|error| format!("JSON illisible : {error}"))?;

    let items = match value {
        Value::Array(items) => items,
        Value::Object(map) => ["components", "composants", "items", "data"]
            .iter()
            .find_map(|key| map.get(*key).and_then(Value::as_array).cloned())
            .ok_or_else(|| {
                "Le fichier JSON doit contenir une liste de composants (clé « components »).".to_string()
            })?,
        _ => return Err("Le fichier JSON doit contenir une liste de composants.".to_string()),
    };

    let mut records = Vec::new();
    for item in items {
        let Value::Object(object) = item else { continue };
        let mut record = HashMap::new();
        for (name, value) in object {
            let value = match value {
                Value::Null => String::new(),
                Value::String(text) => text,
                Value::Bool(flag) => (if flag { "Yes" } else { "No" }).to_string(),
                other => other.to_string(),
            };
            record.insert(column_key(&name), value.trim().to_string());
        }
        records.push(record);
    }
    Ok(records)
}

/// Nom de colonne reconnu par l'import, à partir d'un intitulé de fichier.
fn column_key(name: &str) -> String {
    let normalized = key(name).to_lowercase();
    match normalized.as_str() {
        "id" | "identifiant" => "id",
        "owner" | "proprietaire" | "utilisateur" | "userid" => "owner",
        "name" | "nom" | "designation" | "libelle" | "composant" | "component" => "name",
        "manufacturer" | "fabricant" | "marque" | "manufacturier" => "manufacturer",
        "package" | "boitier" | "case" | "packagename" => "package",
        "pins" | "pin" | "broches" | "nombredepins" | "nbbroches" | "nbpin" | "pin_count" => "pins",
        "smd" | "montage" | "typemontage" => "smd",
        "quantity" | "quantite" | "qte" | "qty" | "stock" | "nombre" => "quantity",
        "orderquantity" | "quantitecommandee" | "acommander" | "commandes" => "order_quantity",
        "price" | "prix" | "prixunitaire" | "pu" => "price",
        "location" | "emplacement" | "casier" | "rangement" | "localisation" => "location",
        "locationid" => "location_id",
        "categoryhead" | "categorieprincipale" | "categorieparente" | "famille" => {
            "category_head"
        }
        "categorysub" | "souscategorie" | "sousfamille" | "categoriecomposant" => {
            "category_sub"
        }
        "category" | "categorie" => "category",
        "supplier" | "fournisseur" => "supplier",
        "supplierid" => "supplier_id",
        "supplierreference" | "referencefournisseur" | "reffournisseur" | "reference" | "ref" => {
            "supplier_reference"
        }
        "datasheet" | "liendatasheet" | "urldatasheet" => "datasheet",
        "datasheetfile" | "fichierdatasheet" | "fichetechnique" | "pdf" => "datasheet_file",
        "comment" | "commentaire" | "note" | "notes" | "remarque" | "remarques" => "comment",
        "url" | "lien" | "siteweb" => "url",
        "imagepath" | "image" | "vignette" | "photo" => "image_path",
        "public" | "partage" | "visible" => "public",
        "createdat" | "datecreation" | "created" => "created_at",
        _ => return normalized,
    }
    .to_string()
}

fn parse_created_at(value: &str) -> Option<String> {
    let trimmed = value.trim();
    if trimmed.is_empty() {
        return None;
    }

    let formats = [
        "%Y-%m-%d %H:%M:%S",
        "%Y-%m-%d %H:%M",
        "%d/%m/%Y %H:%M:%S",
        "%d/%m/%Y %H:%M",
        "%Y-%m-%d",
        "%d/%m/%Y",
    ];

    for format in formats {
        if let Ok(datetime) = sqlx::types::chrono::NaiveDateTime::parse_from_str(trimmed, format) {
            return Some(datetime.format("%Y-%m-%d %H:%M:%S").to_string());
        }
        if let Ok(date) = sqlx::types::chrono::NaiveDate::parse_from_str(trimmed, format) {
            return Some(
                date.and_hms_opt(0, 0, 0)
                    .unwrap_or_default()
                    .format("%Y-%m-%d %H:%M:%S")
                    .to_string(),
            );
        }
    }

    None
}
