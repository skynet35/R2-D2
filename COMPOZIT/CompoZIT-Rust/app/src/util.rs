//! Fonctions partagées : validation d'URL, boîtiers (table `packages`) et
//! fichiers déposés par l'application (images, fiches techniques PDF).
//!
//! Chaque fonction reprend le comportement de son homologue PHP
//! (`package_utils.php`, `sanitizeExternalUrl()` de `config.php`) afin que les
//! deux applications puissent cohabiter sur la même base.

use std::io::Read;
use std::path::Path;
use std::sync::OnceLock;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use sqlx::MySqlPool;

/// Neutralise les jokers LIKE saisis par l'utilisateur.
pub fn escape_like(term: &str) -> String {
    let mut out = String::with_capacity(term.len());
    for character in term.chars() {
        if matches!(character, '%' | '_' | '\\') {
            out.push('\\');
        }
        out.push(character);
    }
    out
}

/// Calque de `sanitizeExternalUrl()` : n'accepte qu'une URL http(s) absolue,
/// sans espace ni caractère de contrôle, limitée à 1000 caractères.
pub fn sanitize_external_url(raw: &str) -> Option<String> {
    let trimmed = raw.trim();
    if trimmed.is_empty() || trimmed.chars().count() > 1000 {
        return None;
    }
    if trimmed.chars().any(|character| character.is_control() || character == ' ') {
        return None;
    }

    let candidate = match trimmed.strip_prefix("//") {
        Some(rest) => format!("https://{rest}"),
        None => trimmed.to_string(),
    };

    let lowered = candidate.to_ascii_lowercase();
    let rest = lowered
        .strip_prefix("https://")
        .or_else(|| lowered.strip_prefix("http://"))?;
    let host = rest.split(['/', '?', '#']).next().unwrap_or_default();
    if host.is_empty() {
        return None;
    }

    Some(candidate)
}

/// Chemin d'image interne au site (`img/…`) : refuse toute remontée de dossier.
pub fn is_internal_image(path: &str) -> bool {
    path.starts_with("img/") && path.len() > 4 && !path.contains("..") && !path.contains('\\')
}

/// Valeurs qui désignent un type de montage ou une absence de boîtier plutôt
/// qu'un boîtier réel : elles ne créent pas de fiche dans `packages`.
pub fn is_placeholder_package(name: &str) -> bool {
    let normalized: String = name.to_uppercase().chars().filter(|c| c.is_ascii_alphanumeric()).collect();
    if normalized.is_empty() {
        return true;
    }
    const PLACEHOLDERS: &[&str] = &[
        "TRAVERSANT", "TRAVERSANTE", "THROUGHHOLE", "THT", "PTH", "SMD", "CMS", "SMT",
        "SURFACEMOUNT", "NA", "NC", "AUCUN", "AUCUNE", "INCONNU", "UNKNOWN", "NONE", "AUTRE",
        "OTHER", "DIVERS",
    ];
    PLACEHOLDERS.contains(&normalized.as_str())
}

/// Normalise un nom de boîtier : casse homogène et alias courants
/// (« dil-14 » → « DIP-14 », « so8 » → « SOIC-8 », « to220 » → « TO-220 »).
pub fn normalize_package_name(name: &str) -> String {
    // preg_replace('/\s+/', ' ') puis trim puis majuscules.
    let mut collapsed = String::with_capacity(name.len());
    let mut previous_space = false;
    for character in name.trim().chars() {
        if character.is_whitespace() {
            if !previous_space && !collapsed.is_empty() {
                collapsed.push(' ');
            }
            previous_space = true;
        } else {
            collapsed.push(character);
            previous_space = false;
        }
    }

    let upper = collapsed.trim().to_uppercase();
    if upper.is_empty() {
        return upper;
    }

    for (prefixes, replacement) in [
        (&["PDIL", "DIL", "PDIP", "DIP"][..], "DIP"),
        (&["SO", "SOP", "SOIC"][..], "SOIC"),
        (&["PLCC", "LCC"][..], "PLCC"),
    ] {
        if let Some(digits) = alias_digits(&upper, prefixes) {
            return format!("{replacement}-{digits}");
        }
    }

    if let Some(rest) = upper.strip_prefix("TO") {
        let rest = rest.trim_start_matches([' ', '-']);
        let digits: String = rest.chars().take_while(char::is_ascii_digit).collect();
        let suffix: String = rest.chars().skip(digits.len()).collect();
        if !digits.is_empty() && suffix.chars().all(|c| c.is_ascii_alphabetic()) {
            return format!("TO-{digits}{suffix}");
        }
    }

    upper
}

fn alias_digits(name: &str, prefixes: &[&str]) -> Option<String> {
    for prefix in prefixes {
        if let Some(rest) = name.strip_prefix(prefix) {
            let digits = rest.trim_start_matches([' ', '-']);
            if !digits.is_empty() && digits.chars().all(|c| c.is_ascii_digit()) {
                return Some(digits.to_string());
            }
        }
    }
    None
}

/// Devine la famille de boîtier (`packages.package_type`).
pub fn guess_package_type(name: &str) -> &'static str {
    let normalized: String = name.to_uppercase().chars().filter(|c| c.is_ascii_alphanumeric()).collect();
    if normalized.is_empty() {
        return "Other";
    }

    // SO8 / SO-8 / SOP8 / SSOP8 → famille SOIC.
    for prefix in ["SOP", "SSOP", "SO"] {
        if let Some(rest) = normalized.strip_prefix(prefix)
            && rest.chars().next().is_some_and(|c| c.is_ascii_digit())
        {
            return "SOIC";
        }
    }

    // Du plus spécifique au plus générique : « TO » ne doit pas capter « SOT ».
    for family in [
        "TSSOP", "MSOP", "SOIC", "QFP", "QFN", "DFN", "DIP", "BGA", "LGA", "CSP", "PLCC", "PGA",
        "SOT", "TO", "DO",
    ] {
        if normalized.contains(family) {
            return family;
        }
    }

    "Other"
}

/// Déduit le pas (mm) et les dimensions d'un boîtier d'après son nom et son
/// nombre de broches.
pub fn guess_package_metrics(name: &str, pins: Option<i32>) -> (Option<String>, Option<String>) {
    let normalized: String = name
        .trim()
        .to_uppercase()
        .chars()
        .map(|character| {
            if character.is_whitespace() || character == '_' {
                '-'
            } else {
                character
            }
        })
        .collect();

    let pitch = [
        ("TSSOP", "0.65"),
        ("MSOP", "0.65"),
        ("SSOP", "0.65"),
        ("SOIC", "1.27"),
        ("SOT", "0.95"),
        ("LQFP", "0.5"),
        ("TQFP", "0.5"),
        ("QFP", "0.5"),
        ("QFN", "0.5"),
        ("DFN", "0.5"),
        ("DIP", "2.54"),
        ("TO", "2.54"),
        ("SO", "1.27"),
    ]
    .into_iter()
    .find(|(family, _)| normalized.contains(family))
    .map(|(_, value)| value.to_string());

    let count = pins.filter(|value| *value > 0);
    let dimensions = count.and_then(|count| {
        if normalized.contains("DIP") {
            let width = if count >= 24 { 15.2 } else { 6.4 };
            Some(format!("{}x{}mm", round1(count as f64 / 2.0 * 2.54), width))
        } else if normalized.contains("SOIC") {
            let width = if count >= 20 { 7.5 } else { 3.9 };
            Some(format!("{}x{}mm", round1(count as f64 / 2.0 * 1.27), width))
        } else {
            None
        }
    });

    (pitch, dimensions)
}

fn round1(value: f64) -> f64 {
    (value * 10.0).round() / 10.0
}

/// Enregistre le boîtier d'un composant dans `packages` s'il est inconnu, et
/// récupère l'image du composant sous `img/<Nom du boîtier>.<ext>`.
///
/// Ne fait jamais échouer l'enregistrement du composant : les erreurs sont
/// seulement journalisées, comme dans la version PHP.
pub async fn register_component_package(
    pool: &MySqlPool,
    root: &Path,
    package: &str,
    pins: Option<i32>,
    smd: &str,
    image_path: Option<&str>,
    owner: i64,
    component_name: &str,
) {
    let package = normalize_package_name(package);
    if package.is_empty() || is_placeholder_package(&package) {
        return;
    }

    let package_image = save_package_image(root, &package, image_path).await;

    let existing: Result<Option<(i64, Option<String>)>, _> =
        sqlx::query_as("SELECT id, image_path FROM packages WHERE name = ?")
            .bind(&package)
            .fetch_optional(pool)
            .await;

    match existing {
        Ok(Some((id, current_image))) => {
            if package_image.is_some() && current_image.is_none() {
                let _ = sqlx::query("UPDATE packages SET image_path = ? WHERE id = ?")
                    .bind(package_image)
                    .bind(id)
                    .execute(pool)
                    .await;
            }
            return;
        }
        Ok(None) => {}
        Err(error) => {
            tracing::warn!("Boîtier « {package} » illisible : {error}");
            return;
        }
    }

    let mounting_type = match smd {
        "No" => "Through-hole",
        "Yes" => "Surface-mount",
        _ => "Both",
    };
    let (pitch, dimensions) = guess_package_metrics(&package, pins);
    let description = if component_name.is_empty() {
        "Boîtier ajouté automatiquement depuis un composant".to_string()
    } else {
        format!("Boîtier ajouté automatiquement depuis le composant « {component_name} »")
    };

    let insert = sqlx::query(
        "INSERT INTO packages (name, description, pin_count, package_type, mounting_type, pitch, \
         dimensions, image_path, owner) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(&package)
    .bind(description)
    .bind(pins)
    .bind(guess_package_type(&package))
    .bind(mounting_type)
    .bind(pitch)
    .bind(dimensions)
    .bind(package_image)
    .bind(owner)
    .execute(pool)
    .await;

    if let Err(error) = insert {
        tracing::warn!("Création du boîtier « {package} » impossible : {error}");
    }
}

/// Copie l'image du composant sous `img/<Nom du boîtier>.<ext>`, en la
/// rapatriant si elle est distante. Une image déjà présente n'est jamais écrasée.
async fn save_package_image(root: &Path, package: &str, image_path: Option<&str>) -> Option<String> {
    let image_path = image_path?.trim();
    if image_path.is_empty() {
        return None;
    }

    let safe_name: String = package
        .trim()
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || matches!(character, '.' | '_' | '-') {
                character
            } else {
                '_'
            }
        })
        .collect();
    let safe_name = safe_name.trim_matches(['.', '_', '-']).to_string();
    if safe_name.is_empty() {
        return None;
    }

    const ALLOWED: &[&str] = &["svg", "png", "jpg", "jpeg", "gif", "webp"];
    let img_dir = root.join("img");

    for extension in ALLOWED {
        let candidate = img_dir.join(format!("{safe_name}.{extension}"));
        if candidate.is_file() && is_image_file(&candidate) {
            return Some(format!("img/{safe_name}.{extension}"));
        }
    }

    let data = if image_path.starts_with("http://") || image_path.starts_with("https://") {
        download(client(), image_path).await?
    } else {
        let relative = image_path.trim_start_matches(['/', '\\']);
        if !is_internal_image(relative) {
            return None;
        }
        std::fs::read(root.join(relative.replace('\\', "/"))).ok()?
    };

    // Refuse d'enregistrer autre chose qu'une image : sans ce garde-fou, la
    // page HTML renvoyée par certains CDN était écrite sous `img/<Boîtier>.svg`.
    let extension = detect_image_extension(&data)?;
    let file_name = format!("{safe_name}.{extension}");
    if std::fs::create_dir_all(&img_dir).is_err() {
        return None;
    }
    if std::fs::write(img_dir.join(&file_name), &data).is_err() {
        return None;
    }

    Some(format!("img/{file_name}"))
}

/// Extensions d'image reconnues, déduites des octets du fichier.
///
/// Renvoie `None` lorsque le contenu n'est pas une image. C'est indispensable :
/// certains CDN (Mouser, protégé par Akamai) répondent par une page HTML
/// « Access to this page has been denied » à la place de l'image demandée.
/// Sans ce contrôle, cette page était enregistrée avec l'extension de repli
/// `.svg` et l'image restait cassée dans l'application.
pub fn detect_image_extension(data: &[u8]) -> Option<&'static str> {
    if data.starts_with(&[0xFF, 0xD8, 0xFF]) {
        return Some("jpg");
    }
    if data.starts_with(&[0x89, b'P', b'N', b'G']) {
        return Some("png");
    }
    if data.starts_with(b"GIF8") {
        return Some("gif");
    }
    if data.len() > 12 && data.starts_with(b"RIFF") && &data[8..12] == b"WEBP" {
        return Some("webp");
    }
    if is_svg(data) {
        return Some("svg");
    }
    None
}

/// Un SVG est un document XML : on exige sa balise racine `<svg` en tête de
/// fichier. Un contenu qui ne la contient pas (du HTML, par exemple) n'est pas
/// considéré comme une image.
fn is_svg(data: &[u8]) -> bool {
    let head = &data[..data.len().min(2048)];
    String::from_utf8_lossy(head)
        .to_ascii_lowercase()
        .contains("<svg")
}

/// Vrai si le fichier présent sur le disque est bien une image, d'après son
/// contenu. Permet d'écarter les fichiers illisibles déposés par une version
/// antérieure (page HTML d'un CDN étiquetée `.svg`).
pub fn is_image_file(path: &Path) -> bool {
    let Ok(mut file) = std::fs::File::open(path) else {
        return false;
    };
    let mut head = [0u8; 2048];
    let read = file.read(&mut head).unwrap_or(0);
    detect_image_extension(&head[..read]).is_some()
}

/// Un PDF doit porter l'extension `pdf` **et** la signature binaire `%PDF-`.
pub fn is_pdf(file_name: &str, data: &[u8]) -> bool {
    let extension = file_name.rsplit('.').next().unwrap_or_default();
    extension.eq_ignore_ascii_case("pdf") && data.starts_with(b"%PDF-")
}

/// Identifiant unique de fichier, analogue à `uniqid()` côté PHP.
pub fn unique_suffix() -> u128 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_millis())
        .unwrap_or_default()
}

/// Nom de fichier sûr dérivé d'une désignation (convention `img/<Nom>.<ext>`).
pub fn safe_file_name(value: &str) -> String {
    let sanitized: String = value
        .trim()
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || matches!(character, '.' | '_' | '-') {
                character
            } else {
                '_'
            }
        })
        .collect();
    sanitized.trim_matches(['.', '_', '-']).to_string()
}

/// Client HTTP partagé pour rapatrier les images distantes.
pub fn client() -> &'static reqwest::Client {
    static CLIENT: OnceLock<reqwest::Client> = OnceLock::new();
    CLIENT.get_or_init(|| {
        reqwest::Client::builder()
            .user_agent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) CompoZ'IT")
            .redirect(reqwest::redirect::Policy::limited(5))
            .timeout(Duration::from_secs(20))
            .build()
            .expect("client HTTP")
    })
}

/// En-têtes d'une requête d'image telle que l'émettrait un navigateur.
///
/// La protection anti-robot du CDN de Mouser (Akamai) sert une page
/// « Access to this page has been denied » dès qu'un seul de ces en-têtes
/// manque : l'ensemble doit donc être envoyé au complet, `Referer` compris.
/// Le `Referer` reprend l'origine de l'image demandée, comme le ferait la page
/// qui l'affiche.
fn image_headers(url: &str) -> reqwest::header::HeaderMap {
    use reqwest::header::{HeaderName, HeaderValue, HeaderMap};

    let mut headers = HeaderMap::new();
    for (name, value) in [
        ("accept", "image/avif,image/webp,image/apng,image/*,*/*;q=0.8"),
        ("accept-language", "fr-FR,fr;q=0.9,en;q=0.8"),
        ("sec-fetch-dest", "image"),
        ("sec-fetch-mode", "no-cors"),
        ("sec-fetch-site", "same-site"),
    ] {
        if let (Ok(name), Ok(value)) = (
            HeaderName::from_bytes(name.as_bytes()),
            HeaderValue::from_str(value),
        ) {
            headers.insert(name, value);
        }
    }

    if let Some(origin) = url_origin(url)
        && let Ok(value) = HeaderValue::from_str(&origin)
    {
        headers.insert(reqwest::header::REFERER, value);
    }

    headers
}

/// Origine (`https://hôte/`) d'une URL absolue, utilisée comme `Referer`.
fn url_origin(url: &str) -> Option<String> {
    let (scheme, rest) = url.split_once("://")?;
    let host = rest.split(['/', '?', '#']).next()?;
    if host.is_empty() {
        return None;
    }
    Some(format!("{scheme}://{host}/"))
}

/// Récupère une ressource distante, en refusant toute réponse non 2xx.
pub async fn download(client: &reqwest::Client, url: &str) -> Option<Vec<u8>> {
    let response = client
        .get(url)
        .headers(image_headers(url))
        .send()
        .await
        .ok()?;
    if !response.status().is_success() {
        return None;
    }
    let bytes = response.bytes().await.ok()?;
    if bytes.is_empty() {
        None
    } else {
        Some(bytes.to_vec())
    }
}
