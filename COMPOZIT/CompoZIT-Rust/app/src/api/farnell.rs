//! Client Farnell / element14 / Newark.
//!
//! Portage fidèle de `farnell_api.php` : API REST Product Search, une seule API
//! pour Farnell (EU), Newark (US) et element14 (APAC) selon le magasin, prix
//! sans devise (déduite du magasin) et libellés d'attributs localisés.

use std::path::Path;

use reqwest::header::ACCEPT;
use serde_json::Value;

use super::{Credentials, Offer, Part};
use tracing::warn;

const SEARCH_URL: &str = "https://api.element14.com/catalog/products";
const DEFAULT_STORE: &str = "fr.farnell.com";

pub async fn search_parts(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let _ = root;
    let query = query.trim();
    if query.is_empty() {
        return Err("Saisissez une référence ou un mot-clé.".to_string());
    }

    let api_key = super::clean_secret(&credentials.api_key);
    if api_key.is_empty() {
        return Err(
            "API Farnell non configurée : renseignez la clé API dans « API fournisseurs »."
                .to_string(),
        );
    }

    let store = store_domain(credentials);
    let limit = limit.clamp(1, 50);

    // 1) Recherche par référence fabricant.
    let mut products = farnell_request(client, &api_key, &store, &format!("manuPartNum:{query}"), limit).await?;

    // 2) Repli sur la recherche par mot-clé.
    if products.is_empty() {
        products = farnell_request(client, &api_key, &store, &format!("any:{query}"), limit).await?;
    }

    let mut parts: Vec<Part> = products
        .iter()
        .filter(|raw| raw.is_object())
        .map(|raw| normalize_part(raw, &store))
        .collect();

    // Tri par pertinence, stable : l'ordre Farnell est conservé à score égal.
    let query_normalized = super::normalize_ref(query);
    parts.sort_by_key(|part| relevance_score(part, &query_normalized));

    parts.truncate(limit as usize);

    Ok(parts)
}

// ------------------------------------------------------------------ Requêtes

/// Exécute une recherche Farnell et renvoie les produits bruts.
async fn farnell_request(
    client: &reqwest::Client,
    api_key: &str,
    store: &str,
    term: &str,
    limit: u32,
) -> Result<Vec<Value>, String> {
    let mut url = reqwest::Url::parse(SEARCH_URL).map_err(|_| "Adresse Farnell invalide.".to_string())?;
    {
        let mut pairs = url.query_pairs_mut();
        pairs.append_pair("term", term);
        pairs.append_pair("storeInfo.id", store);
        pairs.append_pair("resultsSettings.offset", "0");
        pairs.append_pair("resultsSettings.numberOfResults", &limit.to_string());
        pairs.append_pair("resultsSettings.responseGroup", "large");
        pairs.append_pair("callInfo.responseDataFormat", "json");
        pairs.append_pair("callInfo.apiKey", api_key);
    }

    let response = client
        .get(url)
        .header(ACCEPT, "application/json")
        .send()
        .await
        .map_err(|error| {
            warn!("Échec de la requête Farnell : {error}");
            "Erreur réseau lors de l'appel à Farnell.".to_string()
        })?;

    let status = response.status();
    if status == reqwest::StatusCode::FORBIDDEN || status == reqwest::StatusCode::UNAUTHORIZED {
        return Err(format!(
            "Accès refusé par Farnell (HTTP {}) : clé API invalide, ou débit d'appels dépassé \
             (2 appels/seconde). Réessayez dans quelques secondes.",
            status.as_u16()
        ));
    }
    if status == reqwest::StatusCode::BAD_REQUEST {
        return Err(
            "Requête Farnell invalide (400). Vérifiez le magasin régional.".to_string(),
        );
    }
    if !status.is_success() {
        return Err(format!(
            "Réponse Farnell inattendue (HTTP {}).",
            status.as_u16()
        ));
    }

    let body = response.text().await.map_err(|error| {
        warn!("Réponse Farnell illisible : {error}");
        "Réponse Farnell illisible.".to_string()
    })?;

    if body.trim().is_empty() {
        return Ok(Vec::new());
    }

    let data: Value = serde_json::from_str(&body).map_err(|error| {
        warn!("Réponse Farnell illisible : {error}");
        "Réponse Farnell illisible.".to_string()
    })?;

    // Le nom de l'enveloppe varie selon le type de recherche :
    // manufacturerPartNumberSearchReturn, keywordSearchReturn,
    // premierFarnellPartNumberReturn…
    if let Some(products) = extract_products(&data) {
        return Ok(products);
    }

    // Aucune enveloppe attendue : il s'agit d'une erreur applicative.
    Err(format!("Farnell : {}", error_message(&data)))
}

/// Recherche l'enveloppe « …SearchReturn » et en extrait les produits.
fn extract_products(data: &Value) -> Option<Vec<Value>> {
    let object = data.as_object()?;
    for (key, value) in object {
        if key.ends_with("SearchReturn") && (value.is_object() || value.is_array()) {
            return Some(
                value
                    .get("products")
                    .and_then(|products| products.as_array())
                    .cloned()
                    .unwrap_or_default(),
            );
        }
    }
    None
}

/// Message d'erreur applicative renvoyé par l'API Farnell.
fn error_message(data: &Value) -> String {
    let message = data
        .get("errorResponse")
        .map(|response| first_string(response, &["message"]))
        .unwrap_or_default();
    if !message.is_empty() {
        return message;
    }

    data.get("Errors")
        .and_then(|value| value.as_array())
        .and_then(|errors| errors.first())
        .map(|first| first_string(first, &["Message"]))
        .filter(|message| !message.is_empty())
        .unwrap_or_else(|| "réponse inexploitable.".to_string())
}

// -------------------------------------------------------------- Normalisation

/// Normalise un produit Farnell en fiche commune.
fn normalize_part(raw: &Value, store: &str) -> Part {
    let attributes = array_of(raw.get("attributes"));

    let package = find_attribute(
        &attributes,
        &["boîtier", "boitier", "package / case", "case", "package"],
    )
    .unwrap_or_default();
    let pins_raw = find_attribute(
        &attributes,
        &[
            "nbre de broches",
            "nombre de broches",
            "broches",
            "number of pins",
            "pin count",
            "pins",
        ],
    )
    .unwrap_or_default();
    let mounting = find_attribute(&attributes, &["montage", "mounting", "termination"])
        .unwrap_or_default();

    let pins = first_number(&pins_raw);

    let mpn = first_string(raw, &["translatedManufacturerPartNumber"]);
    let sku = first_string(raw, &["sku"]);
    let label = if !mpn.is_empty() { mpn } else { sku.clone() };

    let manufacturer = {
        let vendor = first_string(raw, &["vendorName"]);
        if vendor.is_empty() {
            first_string(raw, &["brandName"])
        } else {
            vendor
        }
    };

    let smd = detect_smd(&mounting, &package);

    Part {
        mpn: label.clone(),
        name: label,
        description: first_string(raw, &["displayName"]),
        manufacturer,
        category: String::new(),
        package,
        pins,
        smd,
        datasheet: first_datasheet(raw),
        image_url: image_url(raw, store),
        best_offer: find_best_offer(raw, store),
        skus: if sku.is_empty() { Vec::new() } else { vec![sku] },
        ..Default::default()
    }
}

/// Recherche la valeur d'un attribut produit à partir de plusieurs libellés.
fn find_attribute(attributes: &[Value], labels: &[&str]) -> Option<String> {
    for attribute in attributes {
        let label = first_string(attribute, &["attributeLabel"]).to_ascii_lowercase();
        if label.is_empty() {
            continue;
        }
        for candidate in labels {
            if label.contains(&candidate.to_ascii_lowercase()) {
                let value = first_string(attribute, &["attributeValue"]);
                if !value.is_empty() {
                    return Some(value);
                }
            }
        }
    }
    None
}

/// Détecte le montage de surface ; à défaut, se rabat sur la famille du boîtier.
fn detect_smd(mounting: &str, package: &str) -> String {
    if !mounting.is_empty() {
        let lower = mounting.to_ascii_lowercase();
        if lower.contains("surface") || lower.contains("smd") || lower.contains("smt") {
            return "Yes".to_string();
        }
        if lower.contains("travers") || lower.contains("through") || lower.contains("insertion") {
            return "No".to_string();
        }
    }

    super::detect_smd(package)
}

/// Premier lien de datasheet non vide.
fn first_datasheet(raw: &Value) -> String {
    for document in array_of(raw.get("datasheets")) {
        let url = first_string(&document, &["url"]);
        if !url.is_empty() {
            return url;
        }
    }
    String::new()
}

/// Construit l'URL de la vignette produit (gabarit stable pour tous les magasins).
fn image_url(raw: &Value, store: &str) -> String {
    let base_name = raw
        .get("image")
        .map(|image| first_string(image, &["baseName"]))
        .unwrap_or_default();
    if base_name.is_empty() {
        return String::new();
    }

    let base_name = base_name.trim_start_matches('/');
    format!("https://{store}/productimages/standard/en_GB/{base_name}")
}

/// Meilleure offre : premier palier de prix, donc la plus petite quantité.
fn find_best_offer(raw: &Value, store: &str) -> Option<Offer> {
    let mut best: Option<Offer> = None;

    for tier in array_of(raw.get("prices")) {
        let Some(cost) = tier.get("cost").filter(|value| !value.is_null()) else {
            continue;
        };
        let Some(price) = super::as_number(cost) else {
            continue;
        };

        let quantity = int_or(tier.get("from"), 1).max(1);
        if best.as_ref().is_none_or(|current| quantity < current.quantity) {
            best = Some(Offer {
                price,
                currency: currency(store).to_string(),
                quantity,
                supplier_name: supplier_name(store).to_string(),
                supplier_ref: first_string(raw, &["sku"]),
                inventory: inventory(raw),
                click_url: String::new(),
                tax: "excl".to_string(),
            });
        }
    }

    best
}

/// Stock disponible : « stock.level », à défaut « inv », à défaut 0.
fn inventory(raw: &Value) -> i64 {
    let level = raw.get("stock").and_then(|stock| stock.get("level"));
    if level.map(|value| !value.is_null()).unwrap_or(false) {
        int_or(level, 0)
    } else {
        int_or(raw.get("inv"), 0)
    }
}

/// Score de pertinence (0 = meilleur).
fn relevance_score(part: &Part, query_normalized: &str) -> i32 {
    if query_normalized.is_empty() {
        return 3;
    }

    let mpn_normalized = super::normalize_ref(&part.mpn);

    if !mpn_normalized.is_empty() && mpn_normalized == query_normalized {
        return 0;
    }

    for sku in &part.skus {
        if super::normalize_ref(sku) == query_normalized {
            return 1;
        }
    }

    if !mpn_normalized.is_empty() && mpn_normalized.contains(query_normalized) {
        return 2;
    }

    3
}

// ------------------------------------------------------------------ Magasin

/// Magasin régional interrogé (détermine catalogue, prix et devise).
fn store_domain(credentials: &Credentials) -> String {
    let store = super::clean_store(&credentials.store_id);
    if store.is_empty() {
        DEFAULT_STORE.to_string()
    } else {
        store
    }
}

/// Nom du distributeur affiché, déduit du magasin régional.
fn supplier_name(store: &str) -> &'static str {
    let store = store.to_ascii_lowercase();
    if store.contains("newark") {
        "Newark"
    } else if store.contains("farnell") {
        "Farnell"
    } else {
        "element14"
    }
}

/// Devise du magasin (l'API ne renvoie pas la devise dans le bloc « prices »).
fn currency(store: &str) -> &'static str {
    let store = store.to_ascii_lowercase();
    if store.contains("newark") {
        return if store.contains("canada") { "CAD" } else { "USD" };
    }
    if store.contains("uk.farnell") {
        return "GBP";
    }
    "EUR"
}

// ------------------------------------------------------------------ Helpers

fn array_of(value: Option<&Value>) -> Vec<Value> {
    value.and_then(|value| value.as_array()).cloned().unwrap_or_default()
}

/// Première clé non nulle, convertie en texte (sémantique du `??` de PHP).
fn first_string(value: &Value, keys: &[&str]) -> String {
    for key in keys {
        if let Some(found) = value.get(*key) {
            if !found.is_null() {
                return match found {
                    Value::String(text) => text.trim().to_string(),
                    Value::Number(number) => number.to_string(),
                    Value::Bool(flag) => flag.to_string(),
                    _ => String::new(),
                };
            }
        }
    }
    String::new()
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
