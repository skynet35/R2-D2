//! Client DigiKey (Product Search v4 REST + OAuth2).
//!
//! L'authentification est de type OAuth2 « client_credentials » (2-legged) et
//! l'en-tête `X-DIGIKEY-Client-Id` est obligatoire sur chaque appel. La
//! recherche se fait en POST avec un corps JSON. Le jeton est mis en cache une
//! heure et renouvelé 60 s avant son expiration.

use std::path::Path;

use serde_json::{json, Value};

use super::{Credentials, Offer, Part};

/// Point d'entrée OAuth2 de DigiKey.
const TOKEN_URL: &str = "https://api.digikey.com/v1/oauth2/token";
/// Recherche de produits par mot-clé.
const SEARCH_URL: &str = "https://api.digikey.com/products/v4/search/keyword";

/// Locale demandée à DigiKey (catalogue, langue et devise français).
const LOCALE_SITE: &str = "FR";
const LOCALE_LANGUAGE: &str = "fr";
const LOCALE_CURRENCY: &str = "EUR";

const MESSAGE_JETON: &str =
    "Impossible d'obtenir un jeton DigiKey : vérifiez le Client ID et le Client Secret.";

/// Recherche des composants chez DigiKey et renvoie une liste normalisée.
pub async fn search_parts(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let client_id = super::clean_secret(&credentials.client_id);
    let client_secret = super::clean_secret(&credentials.client_secret);

    let token = access_token(client, root, &client_id, &client_secret).await?;

    let body = json!({
        "Keywords": query,
        "Limit": limit,
        "Offset": 0,
    });

    let response = client
        .post(SEARCH_URL)
        .header("Authorization", format!("Bearer {token}"))
        .header("X-DIGIKEY-Client-Id", client_id)
        .header("X-DIGIKEY-Locale-Site", LOCALE_SITE)
        .header("X-DIGIKEY-Locale-Language", LOCALE_LANGUAGE)
        .header("X-DIGIKEY-Locale-Currency", LOCALE_CURRENCY)
        .header("Accept", "application/json")
        .json(&body)
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau de la recherche DigiKey");
            "Erreur réseau : impossible de joindre DigiKey.".to_string()
        })?;

    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();

    if status == 401 || status == 403 {
        tracing::warn!(status, "accès refusé par DigiKey");
        return Err(format!(
            "Accès refusé par DigiKey (HTTP {status}) : identifiants invalides ou droits insuffisants."
        ));
    }

    if status == 429 {
        return Err(
            "Quota DigiKey atteint (120 requêtes/minute, 1000/jour). Réessayez dans quelques instants."
                .to_string(),
        );
    }

    if !(200..300).contains(&status) {
        let error: Value = serde_json::from_str(&body).unwrap_or(Value::Null);
        let detail = super::pick(&error, &["title", "detail"]);
        return Err(if detail.is_empty() {
            format!("Réponse DigiKey inattendue (HTTP {status}).")
        } else {
            format!("Réponse DigiKey inattendue (HTTP {status}) : {detail}")
        });
    }

    let data: Value =
        serde_json::from_str(&body).map_err(|_| "Réponse DigiKey illisible.".to_string())?;

    let mut parts = Vec::new();
    if let Some(products) = data.get("Products").and_then(|value| value.as_array()) {
        for product in products {
            if product.is_object() {
                parts.push(normalize_part(product));
            }
        }
    }

    // Tri par pertinence (tri stable : l'ordre DigiKey est conservé à score égal).
    let query_normalized = super::normalize_ref(query);
    parts.sort_by(|left, right| {
        relevance_score(left, &query_normalized).cmp(&relevance_score(right, &query_normalized))
    });
    parts.truncate(limit as usize);

    Ok(parts)
}

/// Récupère un jeton d'accès OAuth2, avec cache local (renouvelé 60 s avant
/// son expiration).
async fn access_token(
    client: &reqwest::Client,
    root: &Path,
    client_id: &str,
    client_secret: &str,
) -> Result<String, String> {
    if let Some(token) = super::cached_token(root, "digikey", 60) {
        return Ok(token);
    }

    let response = client
        .post(TOKEN_URL)
        .form(&[
            ("grant_type", "client_credentials"),
            ("client_id", client_id),
            ("client_secret", client_secret),
        ])
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau lors de l'obtention du jeton DigiKey");
            MESSAGE_JETON.to_string()
        })?;

    if response.status().as_u16() != 200 {
        tracing::warn!(status = response.status().as_u16(), "jeton DigiKey refusé");
        return Err(MESSAGE_JETON.to_string());
    }

    let payload: Value = response
        .json()
        .await
        .map_err(|_| MESSAGE_JETON.to_string())?;

    let token = texte(payload.get("access_token"));
    if token.is_empty() {
        return Err(MESSAGE_JETON.to_string());
    }

    let expires = payload
        .get("expires_in")
        .and_then(super::as_number)
        .map(|value| value as i64)
        .unwrap_or(600)
        .max(60);
    super::store_token(root, "digikey", &token, expires);

    Ok(token)
}

/// Normalise un produit DigiKey au même format que les autres sources.
fn normalize_part(product: &Value) -> Part {
    let variation = product.get("ProductVariations").and_then(|value| value.get(0));

    let mpn = texte(product.get("ManufacturerProductNumber"));
    let sku = texte(variation.and_then(|value| value.get("DigiKeyProductNumber")));
    let label = if mpn.is_empty() { sku } else { mpn };

    let description = description(product);

    let mut package = find_parameter(
        product,
        &["package / case", "case", "boîtier", "package", "mounting type"],
    )
    .unwrap_or_default();
    if package.is_empty() {
        package = texte(variation.and_then(|value| value.pointer("/PackageType/Name")));
    }

    let pins_raw = find_parameter(product, &["number of pins", "pin count", "pins", "broches"])
        .unwrap_or_default();
    let pins = premier_entier(&pins_raw);

    let mut skus = Vec::new();
    if let Some(variants) = product.get("ProductVariations").and_then(|value| value.as_array()) {
        for variant in variants {
            let reference = texte(variant.get("DigiKeyProductNumber"));
            if !reference.is_empty() && !skus.contains(&reference) {
                skus.push(reference);
            }
        }
    }

    Part {
        mpn: label.clone(),
        name: label,
        description,
        manufacturer: texte(product.pointer("/Manufacturer/Name")),
        category: texte(product.pointer("/Category/Name")),
        package: package.clone(),
        pins,
        smd: super::detect_smd(&package),
        datasheet: texte(product.get("DatasheetUrl")),
        image_url: texte(product.get("PhotoUrl")),
        best_offer: find_best_offer(product),
        skus,
        ..Part::default()
    }
}

/// Extrait une description lisible d'un produit DigiKey.
fn description(product: &Value) -> String {
    let block = product.get("Description");

    let mut value = texte(block.and_then(|value| value.get("DetailedDescription")));
    if value.is_empty() {
        value = texte(block.and_then(|value| value.get("ProductDescription")));
    }

    value
}

/// Recherche une valeur de paramètre produit par libellé (insensible à la casse).
fn find_parameter(product: &Value, labels: &[&str]) -> Option<String> {
    let parameters = product.get("Parameters")?.as_array()?;

    for parameter in parameters {
        let label = texte(parameter.get("ParameterText")).to_lowercase();
        if label.is_empty() {
            continue;
        }

        for candidate in labels {
            if label.contains(&candidate.to_lowercase()) {
                let value = texte(parameter.get("ValueText"));
                if !value.is_empty() {
                    return Some(value);
                }
            }
        }
    }

    None
}

/// Détermine la meilleure offre : premier palier de prix de la première variation.
fn find_best_offer(product: &Value) -> Option<Offer> {
    let variation = product.get("ProductVariations").and_then(|value| value.get(0));

    let mut price: Option<f64> = None;
    let mut quantity: i64 = 1;

    if let Some(tiers) = variation
        .and_then(|value| value.get("StandardPricing"))
        .and_then(|value| value.as_array())
    {
        for tier in tiers {
            let Some(unit_price) = tier.get("UnitPrice").and_then(super::as_number) else {
                continue;
            };

            let break_quantity = tier
                .get("BreakQuantity")
                .and_then(super::as_number)
                .map(|value| value as i64)
                .unwrap_or(1)
                .max(1);

            if price.is_none() || break_quantity < quantity {
                price = Some(unit_price);
                quantity = break_quantity;
            }
        }
    }

    Some(Offer {
        price: price?,
        currency: "EUR".to_string(),
        quantity,
        supplier_name: "DigiKey".to_string(),
        supplier_ref: texte(variation.and_then(|value| value.get("DigiKeyProductNumber"))),
        inventory: product
            .get("QuantityAvailable")
            .and_then(super::as_number)
            .map(|value| value as i64)
            .unwrap_or(0),
        click_url: texte(product.get("ProductUrl")),
        tax: "excl".to_string(),
    })
}

/// Score de pertinence (0 = meilleur) : MPN exact, puis référence DigiKey
/// exacte, puis correspondances partielles.
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

/// Lit une valeur JSON comme texte, ou renvoie une chaîne vide.
fn texte(value: Option<&Value>) -> String {
    match value {
        Some(Value::String(text)) => text.trim().to_string(),
        Some(Value::Number(number)) => number.to_string(),
        _ => String::new(),
    }
}

/// Extrait le premier entier d'une chaîne (« 16 pins » → 16).
fn premier_entier(value: &str) -> Option<i64> {
    let mut digits = String::new();
    for character in value.chars() {
        if character.is_ascii_digit() {
            digits.push(character);
        } else if !digits.is_empty() {
            break;
        }
    }
    digits.parse::<i64>().ok()
}
