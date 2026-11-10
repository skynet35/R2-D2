//! Client TME (Transfer Multisort Elektronik) — API v2 REST + OAuth2.
//!
//! L'authentification OAuth2 « client_credentials » utilise HTTP Basic Auth :
//! le jeton (50 caractères) sert d'identifiant, le secret (20 caractères) de
//! mot de passe. La recherche se fait en GET ; les prix et le stock proviennent
//! d'un second appel (`/products/data`) et les fiches techniques d'un
//! troisième (`/products/files`).

use std::collections::HashMap;
use std::path::Path;

use serde_json::Value;

use super::{Credentials, Offer, Part};

/// Base de l'API TME.
const API_BASE: &str = "https://api.tme.eu";
/// Chemin d'obtention du jeton.
const TOKEN_PATH: &str = "/auth/token";

/// Devise et pays demandés pour les prix TME.
const CURRENCY: &str = "EUR";
const COUNTRY: &str = "FR";

const MESSAGE_JETON: &str = "Impossible d'obtenir un jeton TME : vérifiez le token et le secret.";

/// Recherche des composants chez TME et renvoie une liste normalisée.
///
/// On interroge d'abord la référence fabricant (`/products?mpns[]=…`), puis on
/// bascule sur la recherche par mot-clé (`/products/search?phrase=…`) si aucune
/// référence ne correspond.
pub async fn search_parts(
    client: &reqwest::Client,
    root: &Path,
    credentials: &Credentials,
    query: &str,
    limit: u32,
) -> Result<Vec<Part>, String> {
    let token = access_token(
        client,
        root,
        &super::clean_secret(&credentials.api_key),
        &super::clean_secret(&credentials.client_secret),
    )
    .await?;

    // 1) Recherche par référence fabricant.
    let mut params = vec![
        ("mpns[]".to_string(), query.to_string()),
        ("limit".to_string(), limit.to_string()),
        ("scope[]".to_string(), "products".to_string()),
        ("scope[]".to_string(), "parameters".to_string()),
    ];
    let mut response = api_get(client, url(API_BASE, "/products", &params)?, &token).await;
    let mut elements = match &response {
        Ok(data) => extract_elements(data),
        Err(_) => Vec::new(),
    };

    // 2) Repli sur la recherche par mot-clé (phrase : 2 à 40 caractères).
    if elements.is_empty() && query.chars().count() >= 2 {
        let phrase: String = query.chars().take(40).collect();
        params = vec![
            ("phrase".to_string(), phrase),
            ("limit".to_string(), limit.to_string()),
            ("scope[]".to_string(), "products".to_string()),
        ];
        response = api_get(client, url(API_BASE, "/products/search", &params)?, &token).await;
        match &response {
            Ok(data) => elements = extract_elements(data),
            Err(message) => return Err(message.clone()),
        }
    }

    if let Err(message) = &response {
        return Err(message.clone());
    }

    elements.truncate(limit as usize);

    let mut symbols = Vec::new();
    for element in &elements {
        let symbol = texte(element.get("symbol"));
        if !symbol.is_empty() {
            symbols.push(symbol);
        }
    }

    // 3) Prix et stock pour les symboles trouvés (un seul appel groupé).
    let mut data_by_symbol: HashMap<String, Value> = HashMap::new();
    if !symbols.is_empty() {
        let mut params = Vec::new();
        for symbol in &symbols {
            params.push(("symbols[]".to_string(), symbol.clone()));
        }
        params.push(("currency".to_string(), CURRENCY.to_string()));
        params.push(("country".to_string(), COUNTRY.to_string()));
        params.push(("scope[]".to_string(), "prices".to_string()));
        params.push(("scope[]".to_string(), "stock".to_string()));

        if let Ok(data) = api_get(client, url(API_BASE, "/products/data", &params)?, &token).await {
            if let Some(list) = data.get("elements").and_then(|value| value.as_array()) {
                for element in list {
                    if !element.is_object() {
                        continue;
                    }
                    let symbol = texte(element.get("symbol"));
                    if !symbol.is_empty() {
                        data_by_symbol.insert(symbol, element.clone());
                    }
                }
            }
        }
    }

    // 4) Fiches techniques (`/products/files`) : TME ne les renvoie pas dans la
    //    recherche, il faut un appel dédié.
    let mut datasheet_by_symbol: HashMap<String, String> = HashMap::new();
    if !symbols.is_empty() {
        let mut params = Vec::new();
        for symbol in &symbols {
            params.push(("symbols[]".to_string(), symbol.clone()));
        }
        params.push(("scope[]".to_string(), "files".to_string()));

        if let Ok(data) = api_get(client, url(API_BASE, "/products/files", &params)?, &token).await {
            if let Some(list) = data.get("elements").and_then(|value| value.as_array()) {
                for element in list {
                    if !element.is_object() {
                        continue;
                    }
                    let symbol = texte(element.get("symbol"));
                    let datasheet = find_datasheet(element);
                    if !symbol.is_empty() && !datasheet.is_empty() {
                        datasheet_by_symbol.insert(symbol, datasheet);
                    }
                }
            }
        }
    }

    let mut parts = Vec::new();
    for element in &elements {
        if !element.is_object() {
            continue;
        }
        let symbol = texte(element.get("symbol"));
        let data_element = data_by_symbol.get(&symbol);
        let datasheet = datasheet_by_symbol.get(&symbol).cloned().unwrap_or_default();
        parts.push(normalize_part(element, data_element, &datasheet));
    }

    // Tri par pertinence (tri stable : l'ordre TME est conservé à score égal).
    let query_normalized = super::normalize_ref(query);
    parts.sort_by(|left, right| {
        relevance_score(left, &query_normalized).cmp(&relevance_score(right, &query_normalized))
    });
    parts.truncate(limit as usize);

    Ok(parts)
}

/// Récupère un jeton d'accès OAuth2 (Basic Auth), avec cache local. Le jeton
/// TME v2 ne dure que 5 minutes ; on le renouvelle 30 s avant expiration.
async fn access_token(
    client: &reqwest::Client,
    root: &Path,
    token: &str,
    secret: &str,
) -> Result<String, String> {
    if let Some(cached) = super::cached_token(root, "tme", 30) {
        return Ok(cached);
    }

    let response = client
        .post(format!("{API_BASE}{TOKEN_PATH}"))
        .basic_auth(token, Some(secret))
        .header("Content-Type", "application/x-www-form-urlencoded")
        .body("grant_type=client_credentials")
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau lors de l'obtention du jeton TME");
            MESSAGE_JETON.to_string()
        })?;

    if response.status().as_u16() != 200 {
        tracing::warn!(status = response.status().as_u16(), "jeton TME refusé");
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
        .unwrap_or(300)
        .max(60);
    super::store_token(root, "tme", &token, expires);

    Ok(token)
}

/// Construit une URL de l'API TME avec ses paramètres de requête.
///
/// Les paramètres répétables (`scope[]`, `symbols[]`, `mpns[]`) sont transmis
/// avec la notation tableau.
fn url(base: &str, path: &str, params: &[(String, String)]) -> Result<reqwest::Url, String> {
    let mut destination = reqwest::Url::parse(&format!("{base}{path}"))
        .map_err(|_| "URL TME invalide.".to_string())?;
    {
        let mut query = destination.query_pairs_mut();
        for (name, value) in params {
            query.append_pair(name, value);
        }
    }
    Ok(destination)
}

/// Exécute un appel GET authentifié vers l'API TME et renvoie le bloc `data`.
async fn api_get(
    client: &reqwest::Client,
    url: reqwest::Url,
    token: &str,
) -> Result<Value, String> {
    let response = client
        .get(url)
        .header("Authorization", format!("Bearer {token}"))
        .header("Accept", "application/json")
        .send()
        .await
        .map_err(|error| {
            tracing::warn!(%error, "échec réseau de l'appel TME");
            "Erreur réseau : impossible de joindre TME.".to_string()
        })?;

    let status = response.status().as_u16();
    let body = response.text().await.unwrap_or_default();

    if status == 401 || status == 403 {
        tracing::warn!(status, "accès refusé par TME");
        return Err(format!(
            "Accès refusé par TME (HTTP {status}) : identifiants invalides ou droits insuffisants."
        ));
    }

    if !(200..300).contains(&status) {
        return Err(format!("Réponse TME inattendue (HTTP {status})."));
    }

    let payload: Value =
        serde_json::from_str(&body).map_err(|_| "Réponse TME illisible.".to_string())?;

    let statut = payload
        .get("status")
        .map(|value| texte(Some(value)))
        .unwrap_or_else(|| "OK".to_string())
        .to_ascii_uppercase();
    if statut != "OK" {
        let message = texte(payload.get("message"));
        let message = if message.is_empty() {
            "requête refusée.".to_string()
        } else {
            message
        };
        return Err(format!("TME : {message}"));
    }

    Ok(payload.get("data").cloned().unwrap_or(Value::Null))
}

/// Extrait la liste de produits d'une réponse TME, quelle que soit l'enveloppe
/// (`products.elements` pour `/products`, `elements` pour `/products/search`).
fn extract_elements(data: &Value) -> Vec<Value> {
    if let Some(list) = data
        .pointer("/products/elements")
        .and_then(|value| value.as_array())
    {
        return list.clone();
    }

    if let Some(list) = data.get("elements").and_then(|value| value.as_array()) {
        return list.clone();
    }

    Vec::new()
}

/// Extrait le boîtier depuis la description TME.
///
/// TME ne renvoie pas d'attribut « case » exploitable : le boîtier figure dans
/// la description sous forme de segment, ex. « ... ; Ch: 2; 3÷30VDC; SO8; reel ».
fn extract_package(description: &str) -> String {
    for segment in description.split(';') {
        let segment = segment.trim();
        // On ignore les segments vides ou porteurs de valeurs (« Ch: 2 »).
        if segment.is_empty() || segment.contains(':') {
            continue;
        }
        if !segment_valide(segment) {
            continue;
        }
        if contient_token_boitier(segment) || contient_puce(segment) {
            return segment.to_string();
        }
    }

    String::new()
}

/// Familles de boîtiers reconnues dans la description TME.
const TME_TOKENS: [&str; 29] = [
    "SO", "SOP", "SSOP", "TSSOP", "MSOP", "QSOP", "QFN", "DFN", "LQFP", "TQFP", "QFP", "BGA",
    "CSP", "LGA", "SOT", "SOD", "SC", "SMA", "SMB", "SMC", "MELF", "DPAK", "D2PAK", "D3PAK",
    "DIP", "PDIP", "DIL", "TO", "DO",
];

/// Tailles de boîtiers CMS (puce) courantes.
const TME_CHIPS: [&str; 9] = [
    "0201", "0402", "0603", "0805", "1206", "1210", "1812", "2010", "2512",
];

/// Un segment n'est retenu que s'il ressemble à un boîtier nu.
fn segment_valide(segment: &str) -> bool {
    let length = segment.chars().count();
    if length == 0 || length > 24 {
        return false;
    }
    segment
        .chars()
        .all(|character| character.is_ascii_alphanumeric() || matches!(character, '.' | ',' | '-' | '/'))
}

/// Le segment contient-il une famille de boîtier suivie de 1 à 3 chiffres ?
fn contient_token_boitier(segment: &str) -> bool {
    let upper = segment.to_ascii_uppercase();
    let bytes = upper.as_bytes();

    for token in TME_TOKENS {
        let needle = token.as_bytes();
        if needle.len() > bytes.len() {
            continue;
        }

        for start in 0..=(bytes.len() - needle.len()) {
            if &bytes[start..start + needle.len()] != needle {
                continue;
            }

            let mut index = start + needle.len();
            let mut digits = 0;
            while index < bytes.len() && bytes[index].is_ascii_digit() && digits < 3 {
                digits += 1;
                index += 1;
            }

            if digits >= 1 {
                return true;
            }
        }
    }

    false
}

/// Le segment contient-il une taille de puce (0402, 0603…).
fn contient_puce(segment: &str) -> bool {
    let upper = segment.to_ascii_uppercase();
    TME_CHIPS.iter().any(|chip| upper.contains(chip))
}

/// Construit l'URL de la vignette produit depuis le bloc « assets ».
fn image_url(product: &Value) -> String {
    let photo = product.get("assets").and_then(|value| value.get("primary_photo"));

    let mut url = texte(photo.and_then(|value| value.get("high_resolution")));
    if url.is_empty() {
        url = texte(photo.and_then(|value| value.get("thumbnail")));
    }

    document_url(&url)
}

/// Normalise une URL de document TME (relative au protocole « //… »).
fn document_url(url: &str) -> String {
    let url = url.trim();
    if url.starts_with("//") {
        format!("https:{url}")
    } else {
        url.to_string()
    }
}

/// Extrait l'URL de la fiche technique depuis le bloc « documents » de
/// `/products/files`. Priorité : DTE au format PDF, puis DTE, puis PDF, puis
/// premier document.
fn find_datasheet(element: &Value) -> String {
    let mut first_doc = String::new();
    let mut first_pdf = String::new();
    let mut first_dte = String::new();
    let mut dte_pdf = String::new();

    if let Some(documents) = element
        .pointer("/documents/elements")
        .and_then(|value| value.as_array())
    {
        for document in documents {
            if !document.is_object() {
                continue;
            }

            let url = document_url(&texte(document.get("url")));
            if url.is_empty() {
                continue;
            }

            let is_dte = texte(document.get("type")).to_ascii_uppercase() == "DTE";
            let is_pdf = extension_pdf(&url);

            if first_doc.is_empty() {
                first_doc = url.clone();
            }
            if is_pdf && first_pdf.is_empty() {
                first_pdf = url.clone();
            }
            if is_dte && first_dte.is_empty() {
                first_dte = url.clone();
            }
            if is_dte && is_pdf && dte_pdf.is_empty() {
                dte_pdf = url.clone();
            }
        }
    }

    if !dte_pdf.is_empty() {
        return dte_pdf;
    }
    if !first_dte.is_empty() {
        return first_dte;
    }
    if !first_pdf.is_empty() {
        return first_pdf;
    }
    first_doc
}

/// L'URL se termine-t-elle par l'extension « .pdf » ?
fn extension_pdf(url: &str) -> bool {
    let path = reqwest::Url::parse(url)
        .map(|parsed| parsed.path().to_string())
        .unwrap_or_else(|_| url.to_string());

    std::path::Path::new(&path)
        .extension()
        .and_then(|extension| extension.to_str())
        .map(|extension| extension.eq_ignore_ascii_case("pdf"))
        .unwrap_or(false)
}

/// Détermine la meilleure offre à partir de la réponse de `/products/data`.
///
/// TME renvoie des prix TTC (type « GROSS ») : on les ramène hors taxes pour
/// que toutes les sources expriment leurs prix de la même façon (HT).
fn find_best_offer(data_element: &Value, supplier_ref: &str) -> Option<Offer> {
    let prices = data_element.get("prices");
    let vat_rate = prices
        .and_then(|value| value.pointer("/tax/rate"))
        .and_then(super::as_number)
        .unwrap_or(0.0);
    let is_gross = texte(prices.and_then(|value| value.get("type"))).to_ascii_uppercase() == "GROSS";
    let divisor = if is_gross && vat_rate > 0.0 {
        1.0 + vat_rate / 100.0
    } else {
        1.0
    };

    let mut best: Option<(f64, i64)> = None;

    if let Some(tiers) = prices
        .and_then(|value| value.get("elements"))
        .and_then(|value| value.as_array())
    {
        for tier in tiers {
            let Some(price) = tier.get("price").and_then(super::as_number) else {
                continue;
            };

            let quantity = tier
                .get("amount")
                .and_then(super::as_number)
                .map(|value| value as i64)
                .unwrap_or(1)
                .max(1);

            if best.is_none() || quantity < best.as_ref().map(|(_, q)| *q).unwrap_or(i64::MAX) {
                best = Some((arrondi(price / divisor, 4), quantity));
            }
        }
    }

    let (price, quantity) = best?;

    let currency = texte(prices.and_then(|value| value.get("currency"))).to_ascii_uppercase();
    let currency = if currency.is_empty() {
        CURRENCY.to_string()
    } else {
        currency
    };

    let inventory = data_element
        .get("stock_quantity")
        .and_then(super::as_number)
        .map(|value| value as i64)
        .unwrap_or(0);

    Some(Offer {
        price,
        currency,
        quantity,
        supplier_name: "TME".to_string(),
        supplier_ref: supplier_ref.to_string(),
        inventory,
        click_url: String::new(),
        tax: "excl".to_string(),
    })
}

/// Normalise un produit TME au même format que les autres sources.
fn normalize_part(product: &Value, data_element: Option<&Value>, datasheet: &str) -> Part {
    let symbol = texte(product.get("symbol"));

    let mpn = product
        .get("manufacturer_symbols")
        .and_then(|value| value.as_array())
        .and_then(|list| list.first())
        .map(|value| texte(Some(value)))
        .unwrap_or_default();
    let label = if mpn.is_empty() { symbol.clone() } else { mpn };

    let description = texte(product.get("description"));
    let package = extract_package(&description);

    let mut skus = Vec::new();
    if !symbol.is_empty() {
        skus.push(symbol.clone());
    }
    if let Some(list) = product
        .get("manufacturer_symbols")
        .and_then(|value| value.as_array())
    {
        for reference in list {
            let reference = texte(Some(reference));
            if !reference.is_empty() && !skus.contains(&reference) {
                skus.push(reference);
            }
        }
    }

    Part {
        mpn: label.clone(),
        name: label,
        description,
        manufacturer: texte(product.pointer("/manufacturer/name")),
        category: texte(product.pointer("/category/name")),
        package: package.clone(),
        pins: None,
        smd: super::detect_smd(&package),
        datasheet: datasheet.to_string(),
        image_url: image_url(product),
        best_offer: data_element.and_then(|element| find_best_offer(element, &symbol)),
        skus,
        ..Part::default()
    }
}

/// Score de pertinence (0 = meilleur) : MPN exact, puis symbole TME exact,
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

/// Arrondit un prix à un nombre de décimales donné.
fn arrondi(value: f64, decimals: u32) -> f64 {
    let factor = 10_f64.powi(decimals as i32);
    (value * factor).round() / factor
}

/// Lit une valeur JSON comme texte, ou renvoie une chaîne vide.
fn texte(value: Option<&Value>) -> String {
    match value {
        Some(Value::String(text)) => text.trim().to_string(),
        Some(Value::Number(number)) => number.to_string(),
        _ => String::new(),
    }
}
