//! Recherche des caractéristiques techniques manquantes.
//!
//! Reprend les deux points d'entrée de `import_database.php` :
//! - le bouton de la fiche enrichit un seul composant puis revient sur sa page
//!   avec un compte rendu (`param_added`) ;
//! - le scan global traite toute la base par lots successifs, en JSON, pour
//!   alimenter une barre de progression sans rechargement de page.
//!
//! Le moteur lui-même est dans [`crate::enrich`] : les valeurs déjà saisies ne
//! sont jamais écrasées, seules les caractéristiques absentes sont complétées.

use axum::extract::{Path as RoutePath, State};
use axum::response::{IntoResponse, Redirect, Response};
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use tower_sessions::Session;

use crate::api::ApiConfig;
use crate::enrich::{ENRICH_BATCH, enrich_batch};
use crate::error::AppResult;
use crate::session::CurrentUser;
use crate::state::AppState;

/// File d'attente du scan global, conservée en session entre deux lots.
const QUEUE_KEY: &str = "compozit_enrich_all_ids";
const TOTAL_KEY: &str = "compozit_enrich_all_total";
const STATS_KEY: &str = "compozit_enrich_all_stats";

// ------------------------------------------------------------- Composant seul

/// Recherche les caractéristiques d'un seul composant (bouton de la fiche).
pub async fn single(
    State(state): State<AppState>,
    user: CurrentUser,
    RoutePath(id): RoutePath<i64>,
) -> AppResult<Response> {
    if id <= 0 {
        return Ok(Redirect::to("/components?error=component_not_found").into_response());
    }

    let pool = state.require_pool().await?;
    let config = ApiConfig::load(&state.root);
    let client = crate::api::client();

    let target = match enrich_batch(&pool, &state.root, &config, &client, user.id, &[id]).await {
        Ok(stats) => format!(
            "/components/{id}/sheet?param_added={}&param_checked={}",
            stats.params_added, stats.checked
        ),
        Err(error) => format!(
            "/components/{id}/sheet?param_error={}",
            percent_encode(&error.to_string())
        ),
    };

    Ok(Redirect::to(&target).into_response())
}

// ------------------------------------------------------------------- Scan global

#[derive(Debug, Deserialize)]
pub struct AllForm {
    /// Présent à la première requête pour repartir d'une file vide.
    #[serde(default)]
    pub reset: Option<String>,
}

/// Scan de toute la base, lot par lot (`enrich_all_params_ajax`).
///
/// Le navigateur enchaîne les appels jusqu'à ce que `done` soit vrai ; chaque
/// réponse porte l'avancement, les paramètres ajoutés et s'il reste des
/// composants à traiter.
pub async fn all_ajax(
    State(state): State<AppState>,
    user: CurrentUser,
    session: Session,
    Form(form): Form<AllForm>,
) -> AppResult<Json<Value>> {
    let pool = state.require_pool().await?;

    // Comme `empty()` en PHP : « 0 » et la chaîne vide ne remettent pas la
    // recherche à zéro.
    let reset = form
        .reset
        .as_deref()
        .map(|value| !value.is_empty() && value != "0")
        .unwrap_or(false);
    if reset {
        let _ = session.insert(QUEUE_KEY, Vec::<i64>::new()).await;
        let _ = session.insert(TOTAL_KEY, 0i64).await;
        let _ = session.insert(STATS_KEY, (0i64, 0i64)).await;
    }

    let mut queue: Vec<i64> = session
        .get::<Vec<i64>>(QUEUE_KEY)
        .await
        .ok()
        .flatten()
        .unwrap_or_default();
    queue.retain(|id| *id != 0);

    let mut total: i64 = session.get::<i64>(TOTAL_KEY).await.ok().flatten().unwrap_or(0);
    if queue.is_empty() {
        queue = sqlx::query_scalar("SELECT id FROM data WHERE owner = ? ORDER BY id ASC")
            .bind(user.id)
            .fetch_all(&pool)
            .await?;
        total = queue.len() as i64;
        let _ = session.insert(TOTAL_KEY, total).await;
    } else if total <= 0 {
        total = queue.len() as i64;
        let _ = session.insert(TOTAL_KEY, total).await;
    }

    let mut stats: (i64, i64) = session
        .get::<(i64, i64)>(STATS_KEY)
        .await
        .ok()
        .flatten()
        .unwrap_or((0, 0));

    if queue.is_empty() {
        return Ok(Json(json!({
            "ok": true,
            "done": true,
            "total": total,
            "processed": total,
            "remaining": 0,
            "enriched": stats.0,
            "paramsAdded": stats.1,
        })));
    }

    let batch: Vec<i64> = queue.iter().copied().take(ENRICH_BATCH).collect();
    let remaining: Vec<i64> = queue.iter().copied().skip(ENRICH_BATCH).collect();

    let config = ApiConfig::load(&state.root);
    let client = crate::api::client();
    let batch_stats = match enrich_batch(&pool, &state.root, &config, &client, user.id, &batch).await {
        Ok(result) => result,
        Err(error) => return Ok(Json(json!({ "ok": false, "error": error.to_string() }))),
    };

    let _ = session.insert(QUEUE_KEY, &remaining).await;
    stats.0 += batch_stats.enriched as i64;
    stats.1 += batch_stats.params_added as i64;
    let _ = session.insert(STATS_KEY, stats).await;

    Ok(Json(json!({
        "ok": true,
        "done": remaining.is_empty(),
        "total": total,
        "processed": total - remaining.len() as i64,
        "remaining": remaining.len() as i64,
        "enriched": stats.0,
        "paramsAdded": stats.1,
    })))
}

/// Échappement d'une valeur pour une chaîne de requête, comme `urlencode()`.
fn percent_encode(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for byte in value.as_bytes() {
        match *byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(*byte as char)
            }
            b' ' => out.push('+'),
            other => out.push_str(&format!("%{other:02X}")),
        }
    }
    out
}
