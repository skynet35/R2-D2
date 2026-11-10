//! Client Datasheets.com (Product Search API).
//!
//! Point d'entrée public : `GET /api/v1/search?q=&limit=&page=`, authentifié
//! par un jeton porteur. Le catalogue ne renvoie ni prix ni stock : la source
//! sert à retrouver une référence, son boîtier, son image et sa fiche PDF.
//!
//! Le quota annoncé par l'API se lit dans les en-têtes `X-RateLimit-*`
//! (500/heure, 5 000/mois) ; `limit` est plafonné à 10 résultats par appel.

use std::path::Path;

use serde_json::Value;

use super::{Credentials, Part};

/// Point d'entrée de la recherche Datasheets.com.
const SEARCH_URL: &str = "https://www.datasheets.com/api/v1/search";

/// Recherche des composants chez Datasheets.com et renvoie une liste normalisée.
pub async fn search_parts(
    client: &reqwest::Client,
    _root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let limit_text = limit.to_string();

    let response = client
        .get(SEARCH_URL)
        .query(&[
            ("q", query),
            ("limit", limit_text.as_str()),
            ("page", "1"),
        ])
        .header("Accept", "application/json")
        .header(
            "Authorization",
            format!("Bearer {}", super::clean_secret(&credentials.api_key)),
        )
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau de la recherche Datasheets.com");
            "Erreur réseau : impossible de joindre Datasheets.com.".to_string()
        })?;

    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();

    if status == 401 || status == 403 {
        tracing::warn!(status, "accès refusé par Datasheets.com");
        return Err(format!(
            "Accès refusé par Datasheets.com (HTTP {status}) : clé API invalide ou révoquée."
        ));
    }

    if status == 429 {
        return Err(
            "Quota Datasheets.com atteint (500/heure, 5 000/mois) : réessayez plus tard."
                .to_string(),
        );
    }

    if !(200..300).contains(&status) {
        return Err(format!("Réponse Datasheets.com inattendue (HTTP {status})."));
    }

    if body.trim().is_empty() {
        return Ok(Vec::new());
    }

    let data: Value =
        serde_json::from_str(&body).map_err(|_| "Réponse Datasheets.com illisible.".to_string())?;

    let Some(results) = data.get("results").and_then(Value::as_array) else {
        let message = super::pick(&data, &["message", "error"]);
        return Err(if message.is_empty() {
            "Datasheets.com : réponse inexploitable.".to_string()
        } else {
            format!("Datasheets.com : {message}")
        });
    };

    // L'API classe déjà par pertinence : l'ordre reçu est conservé.
    let mut parts: Vec<Part> = results
        .iter()
        .filter(|result| result.is_object())
        .map(normalize_part)
        .collect();
    parts.truncate(limit as usize);

    Ok(parts)
}

/// Normalise un résultat Datasheets.com au format commun aux fournisseurs.
fn normalize_part(result: &Value) -> Part {
    let mpn = super::pick(result, &["mpn", "partNumber"]);
    let title = super::pick(result, &["title"]);
    let label = if mpn.is_empty() { title.clone() } else { mpn };

    let specs = result.get("specs").and_then(Value::as_array);
    let specs = specs.map(Vec::as_slice);

    // `packageType` est un code JEDEC (« R-PDSO-G3 ») : le boîtier lisible se
    // trouve dans les caractéristiques, sous « Package/Case ».
    let package = spec_value(specs, &["Package/Case", "Case/Package", "Package"])
        .unwrap_or_else(|| super::pick(result, &["packageType"]));

    Part {
        mpn: label.clone(),
        name: label,
        description: if title.is_empty() {
            super::pick(result, &["description"])
        } else {
            title
        },
        manufacturer: super::pick(result, &["manufacturer", "manufacturerName"]),
        category: deepest_category(result),
        package: package.clone(),
        pins: None,
        smd: detect_mount(specs, &package),
        datasheet: super::pick(result, &["datasheetUrl", "datasheet"]),
        image_url: super::pick(result, &["primaryImageUrl", "imageUrl"]),
        best_offer: None,
        skus: Vec::new(),
        ..Part::default()
    }
}

/// Type de montage : la caractéristique « Mount » est plus fiable que le code
/// du boîtier, on ne s'en remet à celui-ci qu'en son absence.
fn detect_mount(specs: Option<&[Value]>, package: &str) -> String {
    let mount = spec_value(specs, &["Mount", "Mounting Type", "Termination"])
        .unwrap_or_default()
        .to_ascii_lowercase();

    if mount.contains("surface") {
        return "Yes".to_string();
    }
    if mount.contains("through") {
        return "No".to_string();
    }

    super::detect_smd(package)
}

/// Valeur d'une caractéristique, désignée par son nom : la réponse les renvoie
/// en vrac sous forme de couples `{name, value}`.
fn spec_value(specs: Option<&[Value]>, names: &[&str]) -> Option<String> {
    for spec in specs? {
        let name = super::pick(spec, &["name"]).to_ascii_lowercase();
        if !names
            .iter()
            .any(|candidate| name == candidate.to_ascii_lowercase())
        {
            continue;
        }
        let value = super::pick(spec, &["value"]);
        if !value.is_empty() {
            return Some(value);
        }
    }

    None
}

/// Catégorie la plus précise fournie (`category4` → `category1`).
fn deepest_category(result: &Value) -> String {
    for key in ["category4", "category3", "category2", "category1"] {
        let value = super::pick(result, &[key]);
        if !value.is_empty() {
            return value;
        }
    }

    String::new()
}
