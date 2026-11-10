//! Recherche par code de marquage CMS.
//!
//! Les boîtiers CMS ne portent pas la référence complète mais un code court
//! (2 à 4 caractères) : cette page permet de retrouver le composant à partir de
//! ce code. Les correspondances proviennent de la table locale
//! `marking_codes`, alimentée par un relevé ponctuel de la base publique
//! smd.yooneed.one (voir `base_de_donnees/marking_codes.sql`).
//!
//! Le même point d'entrée JSON alimente l'autocomplétion du champ « Code de
//! marquage » du formulaire de composant.

use axum::extract::{Query, State};
use axum::response::Html;
use axum::Json;
use serde::Deserialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;

/// Lignes renvoyées à la page de recherche.
const PAGE_RESULTS: i64 = 100;
/// Plafond du paramètre `limit` fourni par le client.
const MAX_RESULTS: i64 = 200;

#[derive(Debug, FromRow)]
struct MarkingRow {
    code: String,
    part_number: String,
    manufacturer: String,
    package_name: String,
    function_label: String,
    description: String,
}

impl MarkingRow {
    fn to_value(&self) -> Value {
        json!({
            "code": self.code,
            // `name` reprend la référence fabricant : c'est le champ attendu par
            // le rendu commun des suggestions.
            "name": self.part_number,
            "part_number": self.part_number,
            "manufacturer": self.manufacturer,
            "package": self.package_name,
            "function": self.function_label,
            "description": self.description,
        })
    }
}

#[derive(Debug, Deserialize)]
pub struct MarkingQuery {
    #[serde(default)]
    pub search: String,
    #[serde(default)]
    pub limit: Option<i64>,
}

/// Neutralise les jokers `LIKE` du terme saisi (les codes contiennent parfois
/// `_`, qui est un joker).
fn like_term(term: &str) -> String {
    let mut escaped = String::with_capacity(term.len() + 2);
    for character in term.chars() {
        if matches!(character, '\\' | '%' | '_') {
            escaped.push('\\');
        }
        escaped.push(character);
    }
    escaped
}

/// La table de marquage est installée par un import séparé : tant qu'il n'a pas
/// été fait, la page doit l'expliquer plutôt que renvoyer une erreur SQL.
async fn installed(pool: &MySqlPool) -> bool {
    sqlx::query_as::<_, (String,)>("SELECT code FROM marking_codes LIMIT 1")
        .fetch_optional(pool)
        .await
        .is_ok()
}

async fn total(pool: &MySqlPool) -> i64 {
    sqlx::query_as::<_, (i64,)>("SELECT COUNT(*) FROM marking_codes")
        .fetch_one(pool)
        .await
        .map(|row| row.0)
        .unwrap_or(0)
}

/// Recherche par code exact, puis par code commençant par la saisie, puis par
/// référence fabricant. Les codes exacts remontent en tête.
async fn find(pool: &MySqlPool, term: &str, limit: i64) -> Result<Vec<MarkingRow>, sqlx::Error> {
    let prefix = format!("{}%", like_term(term));
    let contains = format!("%{}%", like_term(term));

    sqlx::query_as(
        "SELECT code, part_number, manufacturer, package_name, function_label, description \
         FROM marking_codes \
         WHERE code = ? OR code LIKE ? ESCAPE '\\\\' OR part_number LIKE ? ESCAPE '\\\\' \
         ORDER BY (code = ?) DESC, CHAR_LENGTH(code), code, part_number \
         LIMIT ?",
    )
    .bind(term)
    .bind(&prefix)
    .bind(&contains)
    .bind(term)
    .bind(limit)
    .fetch_all(pool)
    .await
}

// ------------------------------------------------------------------------- Page

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let pool = state.require_pool().await?;
    let ready = installed(&pool).await;

    let mut context = page_context(
        &state,
        &user,
        "marking",
        "Marquage CMS",
        "Retrouver un composant à partir du code imprimé sur son boîtier",
    )
    .await;
    context.insert("ready".to_string(), json!(ready));
    context.insert(
        "total".to_string(),
        json!(if ready { total(&pool).await } else { 0 }),
    );

    templates::render(&state.templates, "marking.html", Value::Object(context))
}

// ------------------------------------------------------- Recherche (page + formulaire)

pub async fn search_api(
    State(state): State<AppState>,
    _user: CurrentUser,
    Query(params): Query<MarkingQuery>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;
    let term = params.search.trim();

    if term.chars().count() < 1 {
        return Ok(Json(json!({
            "ok": false,
            "message": "Saisissez un code de marquage.",
            "results": [],
        })));
    }

    if !installed(&pool).await {
        return Ok(Json(json!({
            "ok": false,
            "message": "La base de marquage n'est pas installée sur cette base de données.",
            "results": [],
        })));
    }

    let limit = params
        .limit
        .unwrap_or(PAGE_RESULTS)
        .clamp(1, MAX_RESULTS);
    let rows = find(&pool, term, limit).await?;
    let results: Vec<Value> = rows.iter().map(MarkingRow::to_value).collect();

    let message = if results.is_empty() {
        format!("Aucune correspondance pour « {term} ».")
    } else {
        format!(
            "{} correspondance{} pour « {term} »{}.",
            results.len(),
            if results.len() > 1 { "s" } else { "" },
            if results.len() as i64 == limit {
                " (liste limitée)"
            } else {
                ""
            }
        )
    };

    Ok(Json(json!({
        "ok": !results.is_empty(),
        "message": message,
        "results": results,
    })))
}
