//! Client Mouser.
//!
//! Portage fidèle de `mouser_api.php` : authentification par clé API en
//! paramètre d'URL, recherche « partnumber » puis repli sur « keyword », et
//! normalisation au même format que Nexar.

use std::path::Path;

use reqwest::header::ACCEPT;
use serde_json::{Value, json};

use super::{Credentials, Offer, Part};
use tracing::warn;

const SEARCH_URL: &str = "https://api.mouser.com/api/v1/search/";

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
            "API Mouser non configurée : renseignez la clé API dans « API fournisseurs »."
                .to_string(),
        );
    }

    let limit = limit.clamp(1, 50);

    // 1) Recherche par référence (MPN ou n° Mouser).
    let mut raw_parts = mouser_request(
        client,
        &api_key,
        "partnumber",
        &json!({
            "SearchByPartRequest": {
                "mouserPartNumber": query,
                "partSearchOptions": "BeginsWith",
            }
        }),
    )
    .await?;

    // 2) Repli sur la recherche par mot-clé : c'est ce qui permet de retrouver
    //    un composant décrit en langage naturel.
    if raw_parts.is_empty() {
        raw_parts = mouser_request(
            client,
            &api_key,
            "keyword",
            &json!({
                "SearchByKeywordRequest": {
                    "keyword": query,
                    "records": limit,
                    "startingRecord": 0,
                    "searchOptions": "None",
                }
            }),
        )
        .await?;
    }

    let mut parts: Vec<Part> = raw_parts
        .iter()
        .filter(|raw| raw.is_object())
        .map(normalize_part)
        .collect();

    // Tri par pertinence, stable : l'ordre Mouser est conservé à score égal.
    let query_normalized = super::normalize_ref(query);
    parts.sort_by_key(|part| relevance_score(part, &query_normalized));

    parts.truncate(limit as usize);

    Ok(parts)
}

// ------------------------------------------------------------------ Requêtes

/// Exécute une recherche Mouser et renvoie les parts brutes.
async fn mouser_request(
    client: &reqwest::Client,
    api_key: &str,
    endpoint: &str,
    payload: &Value,
) -> Result<Vec<Value>, String> {
    let mut url = reqwest::Url::parse(&format!("{SEARCH_URL}{endpoint}"))
        .map_err(|_| "Adresse Mouser invalide.".to_string())?;
    url.query_pairs_mut().append_pair("apiKey", api_key);

    let response = client
        .post(url)
        .header(ACCEPT, "application/json")
        .json(payload)
        .send()
        .await
        .map_err(|error| {
            warn!("Échec de la requête Mouser : {error}");
            "Erreur réseau lors de l'appel à Mouser.".to_string()
        })?;

    let status = response.status();
    if status == reqwest::StatusCode::FORBIDDEN {
        return Err(
            "Clé API Mouser refusée (403). Vérifiez la clé et son autorisation.".to_string(),
        );
    }
    if !status.is_success() {
        return Err(format!(
            "Réponse Mouser inattendue (HTTP {}).",
            status.as_u16()
        ));
    }

    let body = response.text().await.map_err(|error| {
        warn!("Réponse Mouser illisible : {error}");
        "Réponse Mouser illisible.".to_string()
    })?;

    // Mouser renvoie parfois un corps vide lorsqu'il n'y a aucun résultat.
    if body.trim().is_empty() {
        return Ok(Vec::new());
    }

    let data: Value = serde_json::from_str(&body).map_err(|error| {
        warn!("Réponse Mouser illisible : {error}");
        "Réponse Mouser illisible.".to_string()
    })?;

    // Les erreurs applicatives arrivent en HTTP 200 : il faut les inspecter.
    if let Some(errors) = data.get("Errors").and_then(|value| value.as_array()) {
        if let Some(first) = errors.first() {
            let message = first_string(first, &["Message"]);
            let message = if message.is_empty() {
                "erreur inconnue".to_string()
            } else {
                message
            };
            return Err(format!("Mouser : {message}"));
        }
    }

    Ok(data
        .get("SearchResults")
        .and_then(|results| results.get("Parts"))
        .and_then(|value| value.as_array())
        .cloned()
        .unwrap_or_default())
}

// -------------------------------------------------------------- Normalisation

/// Normalise une part Mouser en fiche commune.
fn normalize_part(raw: &Value) -> Part {
    let attributes = array_of(raw.get("ProductAttributes"));

    let package = find_attribute(
        &attributes,
        &["package / case", "package", "case", "boîtier"],
    )
    .unwrap_or_default();
    let pins_raw = find_attribute(
        &attributes,
        &[
            "number of pins",
            "pin count",
            "pins",
            "number of positions",
            "broches",
        ],
    )
    .unwrap_or_default();
    let mounting = find_attribute(
        &attributes,
        &[
            "mounting style",
            "mounting type",
            "termination style",
            "type de montage",
        ],
    )
    .unwrap_or_default();

    let pins = first_number(&pins_raw);

    let mpn = first_string(raw, &["ManufacturerPartNumber"]);
    let mouser_ref = first_string(raw, &["MouserPartNumber"]);
    let label = if !mpn.is_empty() { mpn } else { mouser_ref.clone() };

    let smd = match smd_from_mounting(&mounting) {
        Some(true) => "Yes".to_string(),
        Some(false) => "No".to_string(),
        None => super::detect_smd(&package),
    };

    Part {
        mpn: label.clone(),
        name: label,
        description: first_string(raw, &["Description"]),
        manufacturer: first_string(raw, &["Manufacturer"]),
        category: first_string(raw, &["Category"]),
        package,
        pins,
        smd,
        datasheet: first_string(raw, &["DataSheetUrl"]),
        image_url: first_string(raw, &["ImagePath"]),
        best_offer: find_best_offer(raw, &mouser_ref),
        skus: if mouser_ref.is_empty() {
            Vec::new()
        } else {
            vec![mouser_ref]
        },
        ..Default::default()
    }
}

/// Recherche la valeur d'un attribut produit par une liste de noms possibles.
fn find_attribute(attributes: &[Value], names: &[&str]) -> Option<String> {
    for attribute in attributes {
        let attribute_name = first_string(attribute, &["AttributeName"]).to_ascii_lowercase();
        if attribute_name.is_empty() {
            continue;
        }
        for name in names {
            if attribute_name.contains(&name.to_ascii_lowercase()) {
                let value = first_string(attribute, &["AttributeValue"]);
                if !value.is_empty() {
                    return Some(value);
                }
            }
        }
    }
    None
}

/// Meilleure offre : seul vendeur, la référence fournisseur est le n° Mouser.
fn find_best_offer(raw: &Value, mouser_ref: &str) -> Option<Offer> {
    let mut best: Option<Offer> = None;

    for tier in array_of(raw.get("PriceBreaks")) {
        let Some(price) = parse_price(&first_string(&tier, &["Price"])) else {
            continue;
        };

        let quantity = int_or(tier.get("Quantity"), 1).max(1);
        if best.as_ref().is_none_or(|current| price < current.price) {
            best = Some(Offer {
                price,
                currency: first_string(&tier, &["Currency"]),
                quantity,
                supplier_name: "Mouser".to_string(),
                supplier_ref: mouser_ref.to_string(),
                inventory: int_or(raw.get("AvailabilityInStock"), 0),
                click_url: first_string(raw, &["ProductDetailUrl"]),
                tax: "excl".to_string(),
            });
        }
    }

    best
}

/// Convertit un prix Mouser (« $0.10 », « 1,23 € ») en nombre.
fn parse_price(raw: &str) -> Option<f64> {
    let clean: String = raw
        .chars()
        .filter(|character| character.is_ascii_digit() || *character == ',' || *character == '.' || *character == '-')
        .collect();
    if clean.is_empty() {
        return None;
    }

    let normalized = if clean.contains(',') && !clean.contains('.') {
        clean.replace(',', ".") // virgule décimale
    } else {
        clean.replace(',', "") // virgule = séparateur de milliers
    };

    normalized.parse::<f64>().ok()
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
