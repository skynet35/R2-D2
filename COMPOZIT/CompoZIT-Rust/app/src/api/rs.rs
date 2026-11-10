//! Client RS Components / RS Online (Product Search).
//!
//! L'accès se fait avec un « App ID » transmis dans l'en-tête `X-API-KEY` ; le
//! magasin régional détermine le catalogue, les prix et la devise.
//!
//! RS Components ne publie pas sa documentation d'API : l'URL et l'en-tête
//! ci-dessous constituent le point d'entrée attendu, regroupés en tête de
//! fichier afin d'être ajustés facilement.

use std::path::Path;

use serde_json::Value;

use super::{Credentials, Offer, Part};

/// Point d'entrée de l'API de recherche RS.
const SEARCH_URL: &str = "https://api.rs-online.com/api/v1/search";
/// En-tête d'authentification RS.
const API_KEY_HEADER: &str = "X-API-KEY";

/// Recherche des composants chez RS Components et renvoie une liste normalisée.
pub async fn search_parts(
    client: &reqwest::Client,
    _root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let store = store_domain(&credentials.store_id);
    let limit_text = limit.to_string();

    let response = client
        .get(SEARCH_URL)
        .query(&[("q", query), ("limit", limit_text.as_str()), ("store", store.as_str())])
        .header("Accept", "application/json")
        .header(API_KEY_HEADER, super::clean_secret(&credentials.api_key))
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau de la recherche RS");
            "Erreur réseau : impossible de joindre RS Components.".to_string()
        })?;

    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();

    if status == 401 || status == 403 {
        tracing::warn!(status, "accès refusé par RS Components");
        return Err(format!(
            "Accès refusé par RS Components (HTTP {status}) : App ID invalide ou non autorisé."
        ));
    }

    if status == 404 {
        return Err(
            "Point d'entrée RS introuvable (404). L'URL de l'API RS doit être ajustée.".to_string(),
        );
    }

    if !(200..300).contains(&status) {
        return Err(format!("Réponse RS inattendue (HTTP {status})."));
    }

    if body.trim().is_empty() {
        return Ok(Vec::new());
    }

    let data: Value = serde_json::from_str(&body).map_err(|_| "Réponse RS illisible.".to_string())?;

    let products = match extract_products(&data) {
        Some(products) => products,
        None => {
            let message = super::pick(&data, &["message", "error"]);
            let message = if message.is_empty() {
                "réponse inexploitable.".to_string()
            } else {
                message
            };
            return Err(format!("RS : {message}"));
        }
    };

    let mut parts = Vec::new();
    for product in products {
        if product.is_object() {
            parts.push(normalize_part(&product, &store));
        }
    }

    // Tri par pertinence (tri stable : l'ordre RS est conservé à score égal).
    let query_normalized = super::normalize_ref(query);
    parts.sort_by(|left, right| {
        relevance_score(left, &query_normalized).cmp(&relevance_score(right, &query_normalized))
    });
    parts.truncate(limit as usize);

    Ok(parts)
}

/// Extrait la liste de produits d'une réponse JSON, quelle que soit
/// l'enveloppe utilisée (`products`, `results`, `items`…).
fn extract_products(data: &Value) -> Option<Vec<Value>> {
    for key in ["products", "results", "items", "documents", "parts"] {
        if let Some(list) = data.get(key).and_then(|value| value.as_array()) {
            return Some(list.clone());
        }
    }

    // Certaines réponses encapsulent la liste dans un objet « data ».
    if let Some(inner) = data.get("data") {
        if inner.is_object() {
            return extract_products(inner);
        }
    }

    None
}

/// Magasin régional interrogé (détermine catalogue, prix et devise).
fn store_domain(value: &str) -> String {
    let store = super::clean_store(value);
    if store.is_empty() {
        "fr.rs-online.com".to_string()
    } else {
        store
    }
}

/// Devise du magasin, quand l'API ne la renvoie pas dans le prix.
fn store_currency(store: &str) -> String {
    let store = store.to_ascii_lowercase();

    if store.contains("uk.rs-online") {
        return "GBP".to_string();
    }
    if store.contains("us.rs-online") || store.contains("us.rsdelivers") {
        return "USD".to_string();
    }

    "EUR".to_string()
}

/// Détermine la meilleure offre (premier palier de prix, donc la plus petite
/// quantité).
fn find_best_offer(product: &Value, store: &str) -> Option<Offer> {
    let mut price: Option<f64> = None;
    for key in ["price", "unitPrice", "cost", "priceExVat"] {
        if let Some(value) = product.get(key).and_then(super::as_number) {
            price = Some(value);
            break;
        }
    }

    // Repli sur un premier palier de prix (priceBreaks / prices).
    if price.is_none() {
        'outer: for key in ["priceBreaks", "prices"] {
            if let Some(breaks) = product.get(key).and_then(|value| value.as_array()) {
                for tier in breaks {
                    if let Some(value) = tier.get("price").and_then(super::as_number) {
                        price = Some(value);
                        break 'outer;
                    }
                }
            }
        }
    }

    let price = price?;

    let currency = super::pick(product, &["currency", "currencyCode"]);
    let currency = if currency.is_empty() {
        store_currency(store)
    } else {
        currency
    };

    Some(Offer {
        price,
        currency,
        quantity: product
            .get("minOrderQuantity")
            .or_else(|| product.get("quantity"))
            .and_then(super::as_number)
            .map(|value| value as i64)
            .unwrap_or(1)
            .max(1),
        supplier_name: "RS Components".to_string(),
        supplier_ref: super::pick(product, &["stockNumber", "rsStockNumber", "sku", "id"]),
        inventory: product
            .get("stock")
            .or_else(|| product.get("availability"))
            .or_else(|| product.get("inStock"))
            .and_then(super::as_number)
            .map(|value| value as i64)
            .unwrap_or(0),
        click_url: super::pick(product, &["url", "productUrl", "link"]),
        tax: "excl".to_string(),
    })
}

/// Normalise un produit RS au même format que les autres sources.
fn normalize_part(product: &Value, store: &str) -> Part {
    let mpn = super::pick(
        product,
        &["mpn", "manufacturerPartNumber", "translatedManufacturerPartNumber"],
    );
    let sku = super::pick(product, &["stockNumber", "rsStockNumber", "sku", "id"]);
    let label = if mpn.is_empty() { sku.clone() } else { mpn };
    let package = super::pick(product, &["package", "case", "packageCase"]);

    let mut manufacturer = super::pick(product, &["brand", "brandName", "manufacturer", "vendorName"]);
    if let Some(brand) = product.get("brand") {
        if brand.is_object() {
            manufacturer = super::pick(brand, &["name", "brandName"]);
        }
    }

    let description = super::pick(product, &["description", "name", "title", "displayName"]);

    let mut image = String::new();
    match product.get("image") {
        Some(Value::String(text)) => image = text.trim().to_string(),
        Some(value) if value.is_object() => image = super::pick(value, &["url", "imageUrl", "src"]),
        _ => {}
    }
    if image.is_empty() {
        image = super::pick(product, &["imageUrl", "thumbnail"]);
    }

    Part {
        mpn: label.clone(),
        name: label,
        description,
        manufacturer,
        category: super::pick(product, &["category", "categoryName", "productType"]),
        package: package.clone(),
        pins: None,
        smd: super::detect_smd(&package),
        datasheet: super::pick(product, &["datasheetUrl", "datasheet", "documentUrl"]),
        image_url: image,
        best_offer: find_best_offer(product, store),
        skus: if sku.is_empty() { Vec::new() } else { vec![sku] },
        ..Part::default()
    }
}

/// Score de pertinence (0 = meilleur) : MPN exact, puis référence RS exacte,
/// puis correspondances partielles.
fn relevance_score(part: &Part, query_normalized: &str) -> u8 {
    if query_normalized.is_empty() {
        return 3;
    }

    let mpn = super::normalize_ref(&part.mpn);
    if !mpn.is_empty() && mpn == query_normalized {
        return 0;
    }

    if part
        .skus
        .iter()
        .any(|sku| super::normalize_ref(sku) == query_normalized)
    {
        return 1;
    }

    if !mpn.is_empty() && mpn.contains(query_normalized) {
        return 2;
    }

    3
}
