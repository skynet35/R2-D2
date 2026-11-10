//! Client Nexar / Octopart.
//!
//! Portage fidèle de `nexar_api.php` : jeton OAuth2 (client_credentials) mis en
//! cache dans `tmp/`, requête GraphQL `supSearch`, puis normalisation des parts
//! (identité, specs, datasheet, image, meilleure offre, SKU).

use std::path::Path;

use reqwest::header::ACCEPT;
use serde_json::{Value, json};

use super::{Credentials, Offer, Part};
use tracing::warn;

const TOKEN_URL: &str = "https://identity.nexar.com/connect/token";
const GRAPHQL_URL: &str = "https://api.nexar.com/graphql";
const SCOPE: &str = "supply.domain";

/// Requête de recherche Octopart : `supSearch` couvre le MPN, la description et
/// les références distributeurs (indispensable pour une référence type « 489-0043 »).
const SEARCH_QUERY: &str = r#"
query Search($q: String!, $limit: Int!, $country: String!, $currency: String!) {
  supSearch(q: $q, limit: $limit, country: $country, currency: $currency) {
    hits
    results {
      part {
        id
        mpn
        name
        shortDescription
        manufacturer { name }
        category { name path }
        bestDatasheet { url }
        bestImage { url }
        specs { attribute { name } value displayValue unitsName unitsSymbol }
        sellers(authorizedOnly: true) {
          company { name }
          offers {
            sku
            inventoryLevel
            clickUrl
            prices { price currency convertedPrice convertedCurrency quantity }
          }
        }
      }
    }
  }
}
"#;

pub async fn search_parts(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let query = query.trim();
    if query.is_empty() {
        return Err("Saisissez une référence (MPN ou référence distributeur).".to_string());
    }
    if super::clean_secret(&credentials.client_id).is_empty()
        || super::clean_secret(&credentials.client_secret).is_empty()
    {
        return Err("Identifiants Nexar non configurés.".to_string());
    }

    let limit = limit.clamp(1, 30);
    let variables = json!({
        "q": query,
        "limit": limit,
        "country": "FR",
        "currency": "EUR",
    });

    let data = graphql(client, root, credentials, SEARCH_QUERY, &variables).await?;

    let results = data
        .get("supSearch")
        .and_then(|search| search.get("results"))
        .and_then(|value| value.as_array())
        .cloned()
        .unwrap_or_default();

    let mut parts = Vec::new();
    for row in results {
        if let Some(part) = row.get("part") {
            if part.is_object() {
                parts.push(normalize_part(part, query));
            }
        }
    }

    // Tri par pertinence, stable : l'ordre Octopart est conservé à score égal.
    let query_normalized = super::normalize_ref(query);
    parts.sort_by_key(|part| relevance_score(part, &query_normalized));

    Ok(parts)
}

// --------------------------------------------------------------------- Jeton

/// Récupère un jeton d'accès OAuth2, avec cache local (60 s de marge).
async fn access_token(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    force: bool,
) -> Result<String, String> {
    if !force {
        if let Some(token) = super::cached_token(root, "nexar", 60) {
            return Ok(token);
        }
    }

    let client_id = super::clean_secret(&credentials.client_id);
    let client_secret = super::clean_secret(&credentials.client_secret);

    let response = client
        .post(TOKEN_URL)
        .header(ACCEPT, "application/json")
        .form(&[
            ("grant_type", "client_credentials"),
            ("client_id", client_id.as_str()),
            ("client_secret", client_secret.as_str()),
            ("scope", SCOPE),
        ])
        .send()
        .await
        .map_err(|error| {
            warn!("Échec de la requête d'authentification Nexar : {error}");
            "Impossible de joindre le service d'authentification Nexar.".to_string()
        })?;

    let status = response.status();
    if status == reqwest::StatusCode::UNAUTHORIZED {
        return Err("Identifiants Nexar refusés (401).".to_string());
    }
    if !status.is_success() {
        return Err(format!(
            "Identifiants Nexar refusés (HTTP {}).",
            status.as_u16()
        ));
    }

    let text = response.text().await.map_err(|error| {
        warn!("Réponse d'authentification Nexar illisible : {error}");
        "Réponse Nexar illisible.".to_string()
    })?;
    let payload: Value = serde_json::from_str(&text).map_err(|error| {
        warn!("Réponse d'authentification Nexar illisible : {error}");
        "Réponse Nexar illisible.".to_string()
    })?;

    let token = payload
        .get("access_token")
        .and_then(|value| value.as_str())
        .unwrap_or("")
        .trim()
        .to_string();
    if token.is_empty() {
        return Err("Réponse d'authentification Nexar incomplète.".to_string());
    }

    let expires = payload
        .get("expires_in")
        .and_then(|value| value.as_i64())
        .unwrap_or(86_400);
    super::store_token(root, "nexar", &token, expires);

    Ok(token)
}

// ------------------------------------------------------------------- GraphQL

/// Envoie une requête GraphQL authentifiée ; un seul nouvel essai en cas de 401.
async fn graphql(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    query: &str,
    variables: &Value,
) -> Result<Value, String> {
    let body = json!({ "query": query, "variables": variables });

    let mut token = access_token(client, root, credentials, false).await?;
    let mut response = post_graphql(client, &token, &body).await?;

    if response.status() == reqwest::StatusCode::UNAUTHORIZED {
        super::drop_token(root, "nexar");
        if let Ok(fresh) = access_token(client, root, credentials, true).await {
            token = fresh;
            response = post_graphql(client, &token, &body).await?;
        }
    }

    let status = response.status();
    let text = response.text().await.map_err(|error| {
        warn!("Réponse Nexar illisible : {error}");
        "Réponse Nexar illisible.".to_string()
    })?;

    if text.trim().is_empty() {
        return Err("Aucune réponse du serveur Nexar.".to_string());
    }

    let payload: Value = serde_json::from_str(&text).map_err(|error| {
        warn!("Réponse Nexar illisible : {error}");
        "Réponse Nexar illisible.".to_string()
    })?;

    if let Some(errors) = payload.get("errors").and_then(|value| value.as_array()) {
        if let Some(first) = errors.first() {
            let message = first
                .get("message")
                .and_then(|value| value.as_str())
                .unwrap_or("Erreur GraphQL inconnue.");
            return Err(message.to_string());
        }
    }

    if !status.is_success() {
        return Err(format!(
            "Le service Nexar a répondu avec le code HTTP {}.",
            status.as_u16()
        ));
    }

    Ok(payload.get("data").cloned().unwrap_or(Value::Null))
}

async fn post_graphql(
    client: &reqwest::Client,
    token: &str,
    body: &Value,
) -> Result<reqwest::Response, String> {
    client
        .post(GRAPHQL_URL)
        .header(ACCEPT, "application/json")
        .bearer_auth(token)
        .json(body)
        .send()
        .await
        .map_err(|error| {
            warn!("Échec de la requête GraphQL Nexar : {error}");
            "Impossible de joindre le service Nexar.".to_string()
        })
}

// -------------------------------------------------------------- Normalisation

/// Normalise une part Nexar en fiche commune.
fn normalize_part(part: &Value, searched_ref: &str) -> Part {
    let specs = array_of(part.get("specs"));
    let sellers = array_of(part.get("sellers"));

    let package_spec = find_spec(&specs, &["package", "case", "boitier", "encapsulation"]);
    let pins_spec = find_spec(&specs, &["number of pins", "pin count", "pins", "broches"]);
    let mount_spec = find_spec(&specs, &["mounting type", "mount"]);

    let package = package_spec
        .map(|spec| first_string(spec, &["displayValue", "value"]))
        .unwrap_or_default();

    let pins = pins_spec
        .map(|spec| first_string(spec, &["displayValue", "value"]))
        .and_then(|raw| first_number(&raw));

    let mounting = mount_spec
        .map(|spec| first_string(spec, &["displayValue", "value"]))
        .unwrap_or_default();

    let smd = match smd_from_mounting(&mounting) {
        Some(true) => "Yes".to_string(),
        Some(false) => "No".to_string(),
        None => super::detect_smd(&package),
    };

    Part {
        mpn: first_string(part, &["mpn"]),
        name: first_string(part, &["name"]),
        description: first_string(part, &["shortDescription"]),
        manufacturer: nested_string(part, "manufacturer", "name"),
        category: nested_string(part, "category", "name"),
        package,
        pins,
        smd,
        datasheet: nested_string(part, "bestDatasheet", "url"),
        image_url: nested_string(part, "bestImage", "url"),
        best_offer: find_best_offer(&sellers, searched_ref),
        skus: collect_skus(&sellers),
        ..Default::default()
    }
}

/// Extrait une spécification par mots-clés sur le nom de l'attribut.
fn find_spec<'a>(specs: &'a [Value], keywords: &[&str]) -> Option<&'a Value> {
    for spec in specs {
        let name = spec
            .get("attribute")
            .map(|attribute| first_string(attribute, &["name"]))
            .unwrap_or_default()
            .to_ascii_lowercase();
        if name.is_empty() {
            continue;
        }
        for keyword in keywords {
            if name.contains(&keyword.to_ascii_lowercase()) {
                return Some(spec);
            }
        }
    }
    None
}

/// Meilleure offre : prix le plus bas, en privilégiant la référence recherchée.
fn find_best_offer(sellers: &[Value], searched_ref: &str) -> Option<Offer> {
    let mut candidates: Vec<Offer> = Vec::new();

    for seller in sellers {
        let seller_name = seller
            .get("company")
            .map(|company| first_string(company, &["name"]))
            .unwrap_or_default();

        for offer in array_of(seller.get("offers")) {
            let prices = array_of(offer.get("prices"));
            if prices.is_empty() {
                continue;
            }

            // Palier correspondant à la plus petite quantité commandable.
            let mut tier: Option<&Value> = None;
            for price in &prices {
                let quantity = int_or(price.get("quantity"), 0);
                if tier.is_none() || quantity < int_or(tier.and_then(|t| t.get("quantity")), 0) {
                    tier = Some(price);
                }
            }

            let Some(tier) = tier else { continue };
            let Some(raw_price) = tier.get("price").filter(|value| !value.is_null()) else {
                continue;
            };

            // Le prix converti (EUR) est privilégié lorsqu'il est fourni.
            let converted = tier.get("convertedPrice").filter(|value| !value.is_null());
            let (price, currency) = match converted {
                Some(value) => (
                    super::as_number(value).unwrap_or(0.0),
                    first_string(tier, &["convertedCurrency"]),
                ),
                None => (
                    super::as_number(raw_price).unwrap_or(0.0),
                    first_string(tier, &["currency"]),
                ),
            };

            candidates.push(Offer {
                price,
                currency,
                quantity: int_or(tier.get("quantity"), 1),
                supplier_name: seller_name.clone(),
                supplier_ref: first_string(&offer, &["sku"]),
                inventory: int_or(offer.get("inventoryLevel"), 0),
                click_url: first_string(&offer, &["clickUrl"]),
                tax: "excl".to_string(),
            });
        }
    }

    let target = super::normalize_ref(searched_ref);
    if !target.is_empty() {
        let matching: Vec<Offer> = candidates
            .iter()
            .filter(|candidate| super::normalize_ref(&candidate.supplier_ref) == target)
            .cloned()
            .collect();
        if !matching.is_empty() {
            candidates = matching;
        }
    }

    candidates
        .into_iter()
        .reduce(|best, candidate| if candidate.price < best.price { candidate } else { best })
}

/// Récupère toutes les références distributeurs (SKU) attachées à une part.
fn collect_skus(sellers: &[Value]) -> Vec<String> {
    let mut skus = Vec::new();
    for seller in sellers {
        for offer in array_of(seller.get("offers")) {
            let sku = first_string(&offer, &["sku"]);
            if !sku.is_empty() {
                skus.push(sku);
            }
        }
    }
    skus
}

/// Score de pertinence (0 = meilleur).
fn relevance_score(part: &Part, query_normalized: &str) -> i32 {
    if query_normalized.is_empty() {
        return 4;
    }

    let mpn_normalized = super::normalize_ref(&part.mpn);

    if !mpn_normalized.is_empty() && mpn_normalized == query_normalized {
        return 0; // MPN exact
    }

    for sku in &part.skus {
        if super::normalize_ref(sku) == query_normalized {
            return 1; // référence distributeur exacte
        }
    }

    if !mpn_normalized.is_empty()
        && (mpn_normalized.contains(query_normalized)
            || query_normalized.contains(&mpn_normalized))
    {
        return 2; // MPN partiellement correspondant
    }

    for sku in &part.skus {
        if super::normalize_ref(sku).contains(query_normalized) {
            return 3; // référence distributeur partiellement correspondante
        }
    }

    4
}

// ------------------------------------------------------------------ Helpers

fn array_of(value: Option<&Value>) -> Vec<Value> {
    value.and_then(|value| value.as_array()).cloned().unwrap_or_default()
}

fn nested_string(value: &Value, object: &str, key: &str) -> String {
    value
        .get(object)
        .map(|inner| first_string(inner, &[key]))
        .unwrap_or_default()
}

/// Première clé non nulle, convertie en texte (sémantique du `??` de PHP).
fn first_string(value: &Value, keys: &[&str]) -> String {
    for key in keys {
        if let Some(found) = value.get(*key) {
            if !found.is_null() {
                return scalar_text(found);
            }
        }
    }
    String::new()
}

fn scalar_text(value: &Value) -> String {
    match value {
        Value::String(text) => text.trim().to_string(),
        Value::Number(number) => number.to_string(),
        Value::Bool(flag) => flag.to_string(),
        _ => String::new(),
    }
}

/// Entier lu dans une valeur JSON, avec la valeur par défaut en cas d'absence.
fn int_or(value: Option<&Value>, default: i64) -> i64 {
    match value {
        Some(value) if !value.is_null() => {
            super::as_number(value).map(|number| number as i64).unwrap_or(0)
        }
        _ => default,
    }
}

/// Premier groupe de chiffres d'un texte (équivalent de `preg_match('/\d+/')`).
fn first_number(text: &str) -> Option<i64> {
    let mut digits = String::new();
    for character in text.chars() {
        if character.is_ascii_digit() {
            digits.push(character);
        } else if !digits.is_empty() {
            break;
        }
    }
    digits.parse().ok()
}

/// Type de montage déduit d'un libellé ; `None` quand il n'est pas concluant.
fn smd_from_mounting(mounting: &str) -> Option<bool> {
    let lower = mounting.to_ascii_lowercase();
    if lower.contains("smd") || lower.contains("smt") || lower.contains("surface") {
        return Some(true);
    }
    if lower.contains("through") || lower.contains("travers") || lower.contains("insertion") {
        return Some(false);
    }
    None
}
