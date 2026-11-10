//! Client LCSC Electronics (catalogue JLCPCB / LCSC).
//!
//! L'API est publique : aucune clé n'est nécessaire. En revanche, elle exige
//! des en-têtes propres au navigateur (`Origin`, `Referer`, `User-Agent`) et
//! s'interroge en POST avec un corps JSON. Les prix sont exprimés en dollars US.

use std::path::Path;

use serde_json::{json, Value};

use super::{Credentials, Offer, Part};

/// API publique du catalogue JLCPCB qui alimente la bibliothèque LCSC.
const SEARCH_URL: &str =
    "https://jlcpcb.com/api/overseas-pcb-order/v1/shoppingCart/smtGood/selectSmtComponentList";

/// Agent de navigateur exigé par l'API publique.
const USER_AGENT: &str = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36";

/// Recherche des composants chez LCSC et renvoie une liste normalisée.
pub async fn search_parts(
    client: &reqwest::Client,
    _root: &Path,
    _credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let body = json!({
        "currentPage": 1,
        "pageSize": limit,
        "keyword": query,
        "componentLibraryType": Value::Null,
        "searchSource": "search",
    });

    let response = client
        .post(SEARCH_URL)
        .header("Accept", "application/json")
        .header("Origin", "https://jlcpcb.com")
        .header("Referer", "https://jlcpcb.com/parts")
        .header("User-Agent", USER_AGENT)
        .json(&body)
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau de la recherche LCSC");
            "Erreur réseau : impossible de joindre LCSC.".to_string()
        })?;

    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();

    if !(200..300).contains(&status) {
        return Err(format!("Réponse LCSC inattendue (HTTP {status})."));
    }

    let data: Value =
        serde_json::from_str(&body).map_err(|_| "Réponse LCSC illisible.".to_string())?;

    if let Some(code) = data.get("code").and_then(super::as_number) {
        if code as i64 != 200 {
            let message = texte(data.get("message"));
            let message = if message.is_empty() {
                "requête refusée.".to_string()
            } else {
                message
            };
            return Err(format!("LCSC : {message}"));
        }
    }

    let mut parts = Vec::new();
    if let Some(list) = data
        .pointer("/data/componentPageInfo/list")
        .and_then(|value| value.as_array())
    {
        for component in list {
            if component.is_object() {
                parts.push(normalize_part(component));
            }
        }
    }

    // Tri par pertinence (tri stable : l'ordre LCSC est conservé à score égal).
    let query_normalized = super::normalize_ref(query);
    parts.sort_by(|left, right| {
        relevance_score(left, &query_normalized).cmp(&relevance_score(right, &query_normalized))
    });
    parts.truncate(limit as usize);

    Ok(parts)
}

/// Normalise un composant LCSC au même format que les autres sources.
fn normalize_part(component: &Value) -> Part {
    let mpn = texte(component.get("componentModelEn"));
    let lcsc = texte(component.get("componentCode"));
    let label = if mpn.is_empty() { lcsc.clone() } else { mpn };

    let package = texte(component.get("componentSpecificationEn"));

    let pins = find_attribute(component, &["number of pins", "pin count", "pins"])
        .as_deref()
        .and_then(premier_entier);

    let mut category = texte(component.get("componentTypeEn"));
    if category.is_empty() {
        category = texte(component.get("firstSortName"));
    }

    Part {
        mpn: label.clone(),
        name: label,
        description: texte(component.get("describe")),
        manufacturer: texte(component.get("componentBrandEn")),
        category,
        package: package.clone(),
        pins,
        smd: super::detect_smd(&package),
        datasheet: texte(component.get("dataManualUrl")),
        image_url: image_url(component),
        best_offer: find_best_offer(component),
        skus: if lcsc.is_empty() { Vec::new() } else { vec![lcsc] },
        ..Part::default()
    }
}

/// Recherche une valeur d'attribut par mot-clé (insensible à la casse).
fn find_attribute(component: &Value, keywords: &[&str]) -> Option<String> {
    let attributes = component.get("attributes")?.as_array()?;

    for attribute in attributes {
        let name = texte(attribute.get("attribute_name_en")).to_lowercase();
        if name.is_empty() {
            continue;
        }

        for keyword in keywords {
            if name.contains(&keyword.to_lowercase()) {
                let value = texte(attribute.get("attribute_value_name"));
                if !value.is_empty() {
                    return Some(value);
                }
            }
        }
    }

    None
}

/// Construit l'URL de la vignette produit.
fn image_url(component: &Value) -> String {
    let url = texte(component.get("minImage"));
    if !url.is_empty() {
        return url;
    }

    if let Some(images) = component.get("imageList").and_then(|value| value.as_array()) {
        for image in images {
            let candidate = texte(image.get("productImage"));
            if !candidate.is_empty() {
                return candidate;
            }
        }
    }

    texte(component.get("componentImageUrl"))
}

/// Détermine le premier palier de prix (petite quantité) d'un composant.
fn find_best_offer(component: &Value) -> Option<Offer> {
    let mut best: Option<(f64, i64)> = None;

    if let Some(tiers) = component.get("componentPrices").and_then(|value| value.as_array()) {
        for tier in tiers {
            let Some(price) = tier.get("productPrice").and_then(super::as_number) else {
                continue;
            };

            let quantity = tier
                .get("startNumber")
                .and_then(super::as_number)
                .map(|value| value as i64)
                .unwrap_or(1)
                .max(1);

            if best.is_none() || quantity < best.as_ref().map(|(_, q)| *q).unwrap_or(i64::MAX) {
                best = Some((price, quantity));
            }
        }
    }

    // Repli : prix de départ fourni séparément.
    if best.is_none() {
        if let Some(price) = component.get("initialPrice").and_then(super::as_number) {
            let quantity = component
                .get("minPurchaseNum")
                .and_then(super::as_number)
                .map(|value| value as i64)
                .unwrap_or(1)
                .max(1);
            best = Some((price, quantity));
        }
    }

    let (price, quantity) = best?;

    Some(Offer {
        price,
        currency: "USD".to_string(),
        quantity,
        supplier_name: "LCSC".to_string(),
        supplier_ref: texte(component.get("componentCode")),
        inventory: component
            .get("stockCount")
            .and_then(super::as_number)
            .map(|value| value as i64)
            .unwrap_or(0),
        click_url: texte(component.get("lcscGoodsUrl")),
        tax: "excl".to_string(),
    })
}

/// Score de pertinence (0 = meilleur) : MPN exact, puis référence LCSC exacte,
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
