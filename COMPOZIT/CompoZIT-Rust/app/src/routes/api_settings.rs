//! Réglages des API fournisseurs et recherche d'une référence.
//!
//! Reprend `api_settings.php` pour la configuration : un formulaire par
//! fournisseur, une pastille verte ou rouge selon que les clés obligatoires
//! sont saisies, et l'enregistrement dans `api.toml` (fichier propre à la
//! version Rust, à côté de l'exécutable).
//!
//! La recherche alimente le bloc « Importer via une API fournisseur » du
//! formulaire de composant : une ou plusieurs sources sont interrogées en
//! parallèle, puis les fiches sont fusionnées par référence fabricant.

use std::collections::HashMap;
use std::path::Path;

use axum::extract::{Query, State};
use axum::response::Html;
use axum::{Form, Json};
use serde::Deserialize;
use serde_json::{json, Value};
use tokio::task::JoinSet;

use crate::api::{self, ApiConfig, Part, Provider, SearchOutcome};
use crate::error::{AppError, AppResult};
use crate::session::CurrentUser;
use crate::state::AppState;
use crate::templates;

use super::page_context;

/// Ordre de repli pour la fiche technique, de la source la plus fournie à la
/// plus générale.
const DATASHEET_ORDER: [Provider; 7] = [
    // Datasheets.com est un catalogue de fiches techniques : son lien PDF est
    // renseigné pour presque toutes les références.
    Provider::Datasheets,
    Provider::Tme,
    Provider::Farnell,
    Provider::Lcsc,
    Provider::Digikey,
    Provider::Mouser,
    Provider::Nexar,
];

// --------------------------------------------------------------- Écran de réglage

/// Sources proposées à l'import : celles dont les clés sont renseignées.
pub fn sources(root: &Path) -> Vec<Value> {
    ApiConfig::load(root)
        .configured()
        .into_iter()
        .map(|provider| json!({ "key": provider.key(), "label": provider.label() }))
        .collect()
}

/// Vue d'un fournisseur : pastille, champs à saisir et valeurs courantes.
fn provider_entry(config: &ApiConfig, provider: Provider) -> Value {
    let credentials = config.credentials(provider);
    let (minimum, maximum, _) = provider.limit_bounds();
    let tutorial = provider.help();

    let fields: Vec<Value> = provider
        .fields()
        .iter()
        .map(|spec| {
            json!({
                "name": spec.name,
                "label": spec.label,
                "secret": spec.secret,
                "hint": spec.hint,
                "value": credentials.value(spec.name),
            })
        })
        .collect();

    json!({
        "key": provider.key(),
        "label": provider.label(),
        "hint": provider.hint(),
        "fields": fields,
        // Marche à suivre pour obtenir les identifiants (modale d'aide).
        "help": {
            "mark": tutorial.mark,
            "site": tutorial.site,
            "site_label": tutorial.site_label,
            "steps": tutorial.steps,
            "note": tutorial.note,
        },
        "configured": config.is_configured(provider),
        "limit_min": minimum,
        "limit_max": maximum,
        "public": provider == Provider::Lcsc,
    })
}

pub async fn list(State(state): State<AppState>, user: CurrentUser) -> AppResult<Html<String>> {
    let config = ApiConfig::load(&state.root);
    let providers: Vec<Value> = Provider::ALL
        .into_iter()
        .map(|provider| provider_entry(&config, provider))
        .collect();

    let mut context = page_context(
        &state,
        &user,
        "api_settings",
        "API fournisseurs",
        "Import de fiches techniques depuis les distributeurs",
    )
    .await;
    context.insert("providers".to_string(), json!(providers));
    context.insert("ready".to_string(), json!(config.configured().len()));
    context.insert("total".to_string(), json!(Provider::ALL.len()));
    context.insert(
        "config_path".to_string(),
        json!(ApiConfig::file(&state.root).display().to_string()),
    );

    templates::render(&state.templates, "api_settings.html", Value::Object(context))
}

fn failure(message: &str) -> Json<Value> {
    Json(json!({ "ok": false, "error": message }))
}

/// Enregistre les identifiants d'un fournisseur (un formulaire à la fois).
pub async fn save(
    State(state): State<AppState>,
    user: CurrentUser,
    Form(values): Form<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let section = values.get("section").cloned().unwrap_or_default();
    let Some(provider) = Provider::from_key(&section) else {
        return Ok(failure("Fournisseur inconnu."));
    };

    let mut config = ApiConfig::load(&state.root);
    let previous = config.credentials(provider).clone();
    let mut draft = previous.clone();

    for spec in provider.fields() {
        let raw = values.get(spec.name).map(String::as_str).unwrap_or("");
        match spec.name {
            "search_limit" => {
                let (minimum, maximum, default) = provider.limit_bounds();
                draft.search_limit = raw
                    .trim()
                    .parse::<u32>()
                    .ok()
                    .filter(|value| *value > 0)
                    .unwrap_or(default)
                    .clamp(minimum, maximum);
            }
            "store_id" => {
                let cleaned = api::clean_store(raw);
                draft.store_id = if cleaned.is_empty() {
                    provider.default_store().to_string()
                } else {
                    cleaned
                };
            }
            name => draft.set(name, api::clean_secret(raw)),
        }
    }

    // Les champs qui vont par paire sont renseignés ensemble, ou pas du tout.
    for (left, right) in provider.required_pairs() {
        if draft.is_blank(left) != draft.is_blank(right) {
            return Ok(failure(
                "Renseignez les deux champs, ou laissez-les tous les deux vides.",
            ));
        }
    }

    // Des identifiants neufs rendent caducs les jetons conservés.
    let credentials_changed = draft.client_id != previous.client_id
        || draft.client_secret != previous.client_secret
        || draft.api_key != previous.api_key;
    if credentials_changed {
        api::drop_token(&state.root, provider.key());
    }

    *config.credentials_mut(provider) = draft;
    config.save(&state.root).map_err(|error| {
        AppError::Message(format!(
            "Écriture de {} impossible : {error}",
            ApiConfig::file(&state.root).display()
        ))
    })?;

    tracing::info!(
        "Réglages de l'API {} enregistrés par {}",
        provider.label(),
        user.email
    );

    Ok(Json(json!({
        "ok": true,
        "provider": provider_entry(&config, provider),
        "ready": config.configured().len(),
    })))
}

// ------------------------------------------------------------------ Recherche

#[derive(Debug, Deserialize)]
pub struct SearchQuery {
    /// Clé d'un fournisseur, ou « all » pour interroger tous ceux configurés.
    #[serde(default)]
    pub source: String,
    #[serde(default)]
    pub q: String,
}

/// Recherche une référence : les fiches sont fusionnées et dédoublonnées par
/// référence fabricant, comme dans la version PHP.
pub async fn search_api(
    State(state): State<AppState>,
    _user: CurrentUser,
    Query(params): Query<SearchQuery>,
) -> AppResult<Json<Value>> {
    let query = params.q.trim().to_string();
    if query.chars().count() < 2 {
        return Ok(Json(json!({
            "ok": false,
            "message": "Saisissez au moins 2 caractères.",
            "hits": 0,
            "results": [],
        })));
    }

    let config = ApiConfig::load(&state.root);
    let selected = params.source.trim();
    let providers: Vec<Provider> = if selected.is_empty() || selected == "all" {
        config.configured()
    } else {
        Provider::from_key(selected).into_iter().collect()
    };

    if providers.is_empty() {
        return Ok(Json(json!({
            "ok": false,
            "message": "Aucune API fournisseur n'est configurée : \
                        renseignez-la dans « API fournisseurs ».",
            "hits": 0,
            "results": [],
        })));
    }

    let client = api::client();
    let root = state.root.clone();
    let mut tasks = JoinSet::new();

    for (position, provider) in providers.iter().enumerate() {
        let client = client.clone();
        let root = root.clone();
        let config = config.clone();
        let query = query.clone();
        let provider = *provider;
        tasks.spawn(async move {
            let outcome = api::search(&client, &root, &config, provider, &query, None).await;
            (position, provider, outcome)
        });
    }

    // Les réponses sont replacées dans l'ordre des sources pour que la
    // première rencontrée reste en tête de liste.
    let mut responses: Vec<Option<(Provider, SearchOutcome)>> = vec![None; providers.len()];
    while let Some(joined) = tasks.join_next().await {
        match joined {
            Ok((position, provider, outcome)) => responses[position] = Some((provider, outcome)),
            Err(error) => tracing::warn!("Recherche fournisseur interrompue : {error}"),
        }
    }

    let mut entries: Vec<(String, Vec<Part>)> = Vec::new();
    let mut failures: Vec<String> = Vec::new();
    let mut hits = 0_usize;

    for (provider, outcome) in responses.into_iter().flatten() {
        if outcome.ok {
            hits += outcome.hits;
            entries.push((provider.label().to_string(), outcome.results));
        } else {
            failures.push(format!("{} ({})", provider.label(), outcome.message));
        }
    }

    let note = if failures.is_empty() {
        String::new()
    } else {
        format!(" ⚠ {}", failures.join(", "))
    };

    let results = merge(entries);
    if results.is_empty() {
        return Ok(Json(json!({
            "ok": false,
            "message": format!("Aucun composant trouvé pour « {query} ».{note}"),
            "hits": 0,
            "results": [],
        })));
    }

    let displayed = results.len();
    let found = hits.max(displayed);
    Ok(Json(json!({
        "ok": true,
        "message": format!(
            "{displayed} affiché(s) sur {found} trouvé(s) — \
             cliquez sur « Pré-remplir » pour remplir le formulaire.{note}"
        ),
        "hits": found,
        "results": results,
    })))
}

/// Fusionne les fiches de plusieurs sources. La première source rencontrée fait
/// foi ; les doublons complètent les champs manquants et la meilleure offre.
fn merge(entries: Vec<(String, Vec<Part>)>) -> Vec<Value> {
    let mut order: Vec<String> = Vec::new();
    let mut merged: HashMap<String, (Part, Vec<String>)> = HashMap::new();

    for (source, parts) in entries {
        for part in parts {
            let key = api::normalize_ref(&part.mpn);
            if key.is_empty() {
                continue;
            }
            match merged.get_mut(&key) {
                Some((target, sources)) => {
                    if !sources.contains(&source) {
                        sources.push(source.clone());
                    }
                    merge_part(target, &part);
                }
                None => {
                    order.push(key.clone());
                    merged.insert(key, (part, vec![source.clone()]));
                }
            }
        }
    }

    order
        .into_iter()
        .filter_map(|key| {
            let (part, sources) = merged.remove(&key)?;
            let mut value = serde_json::to_value(&part).ok()?;
            if let Some(object) = value.as_object_mut() {
                object.insert("sources".to_string(), json!(sources));
            }
            Some(value)
        })
        .collect()
}

fn merge_part(target: &mut Part, part: &Part) {
    for (left, right) in [
        (&mut target.manufacturer, &part.manufacturer),
        (&mut target.description, &part.description),
        (&mut target.category, &part.category),
        (&mut target.package, &part.package),
        (&mut target.datasheet, &part.datasheet),
        (&mut target.image_url, &part.image_url),
    ] {
        if left.is_empty() && !right.is_empty() {
            *left = right.clone();
        }
    }
    if target.pins.is_none() {
        target.pins = part.pins;
    }

    let Some(candidate) = part.best_offer.as_ref() else {
        return;
    };

    // On ne compare les prix qu'à devise égale, comme la version PHP.
    let replace = match target.best_offer.as_ref() {
        None => true,
        Some(current) => {
            !candidate.currency.is_empty()
                && candidate.currency == current.currency
                && candidate.price > 0.0
                && candidate.price < current.price
        }
    };

    if replace {
        target.best_offer = Some(candidate.clone());
        target.source = part.source.clone();
    }
}

// --------------------------------------------------------------- Fiche technique

#[derive(Debug, Deserialize)]
pub struct DatasheetQuery {
    #[serde(default)]
    pub mpn: String,
    /// Source d'origine : inutile de la réinterroger.
    #[serde(default)]
    pub exclude: String,
}

/// Cherche une fiche technique PDF auprès des autres fournisseurs quand la
/// source interrogée n'en fournit pas.
pub async fn datasheet_api(
    State(state): State<AppState>,
    _user: CurrentUser,
    Query(params): Query<DatasheetQuery>,
) -> AppResult<Json<Value>> {
    let reference = params.mpn.trim().to_string();
    if reference.chars().count() < 2 {
        return Ok(Json(json!({ "ok": false })));
    }

    let excluded = Provider::from_key(&params.exclude);
    let config = ApiConfig::load(&state.root);

    let mut providers: Vec<Provider> = DATASHEET_ORDER
        .into_iter()
        .filter(|provider| Some(*provider) != excluded && config.is_configured(*provider))
        .collect();
    // Un fournisseur absent de l'ordre de repli (RS) reste interrogeable.
    for provider in config.configured() {
        if Some(provider) != excluded && !providers.contains(&provider) {
            providers.push(provider);
        }
    }

    if providers.is_empty() {
        return Ok(Json(json!({ "ok": false })));
    }

    let client = api::client();
    let root = state.root.clone();
    let mut tasks = JoinSet::new();

    for (position, provider) in providers.iter().enumerate() {
        let client = client.clone();
        let root = root.clone();
        let config = config.clone();
        let query = reference.clone();
        let provider = *provider;
        tasks.spawn(async move {
            let outcome = api::search(&client, &root, &config, provider, &query, None).await;
            (position, provider, outcome)
        });
    }

    let mut found: Vec<Option<(Provider, String)>> = vec![None; providers.len()];
    while let Some(joined) = tasks.join_next().await {
        let Ok((position, provider, outcome)) = joined else {
            continue;
        };
        if !outcome.ok {
            continue;
        }
        if let Some(part) = outcome
            .results
            .into_iter()
            .find(|part| !part.datasheet.trim().is_empty() && api::refs_match(&part.mpn, &reference))
        {
            found[position] = Some((provider, part.datasheet));
        }
    }

    match found.into_iter().flatten().next() {
        Some((provider, datasheet)) => Ok(Json(json!({
            "ok": true,
            "datasheet": datasheet,
            "source": provider.label(),
        }))),
        None => Ok(Json(json!({ "ok": false }))),
    }
}
