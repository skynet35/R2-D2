//! Clients des API fournisseurs.
//!
//! L'écran « API fournisseurs » et le formulaire de composant s'appuient sur ce
//! module pour rechercher une référence (MPN, référence distributeur ou
//! mot-clé) et pré-remplir une fiche. Chaque fournisseur possède son propre
//! client, mais tous renvoient la même structure `Part`.
//!
//! Les identifiants sont conservés dans `api.toml`, à côté de l'exécutable.

pub mod datasheets;
pub mod digikey;
pub mod farnell;
pub mod lcsc;
pub mod mouser;
pub mod nexar;
pub mod rs;
pub mod tme;

use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use serde::{Deserialize, Serialize};

/// Fournisseurs interrogeables.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Provider {
    Nexar,
    Mouser,
    Farnell,
    Digikey,
    Tme,
    Lcsc,
    Rs,
    Datasheets,
}

impl Provider {
    pub const ALL: [Provider; 8] = [
        Provider::Nexar,
        Provider::Mouser,
        Provider::Farnell,
        Provider::Digikey,
        Provider::Tme,
        Provider::Lcsc,
        Provider::Rs,
        Provider::Datasheets,
    ];

    pub fn key(self) -> &'static str {
        match self {
            Provider::Nexar => "nexar",
            Provider::Mouser => "mouser",
            Provider::Farnell => "farnell",
            Provider::Digikey => "digikey",
            Provider::Tme => "tme",
            Provider::Lcsc => "lcsc",
            Provider::Rs => "rs",
            Provider::Datasheets => "datasheets",
        }
    }

    /// Retrouve un fournisseur par sa clé d'URL (`api_source` des formulaires).
    pub fn from_key(key: &str) -> Option<Provider> {
        let key = key.trim();
        Provider::ALL
            .into_iter()
            .find(|provider| provider.key() == key)
    }

    pub fn label(self) -> &'static str {
        match self {
            Provider::Nexar => "Nexar / Octopart",
            Provider::Mouser => "Mouser",
            Provider::Farnell => "Farnell",
            Provider::Digikey => "DigiKey",
            Provider::Tme => "TME",
            Provider::Lcsc => "LCSC",
            Provider::Rs => "RS Components",
            Provider::Datasheets => "Datasheets.com",
        }
    }

    /// Aide affichée sous le titre du bloc de configuration.
    pub fn hint(self) -> &'static str {
        match self {
            Provider::Nexar => {
                "Créer une application sur identity.nexar.com (scope « supply.domain ») et reporter ici l'identifiant et le secret."
            }
            Provider::Mouser => "Clé obtenue dans l'espace client Mouser (Search API).",
            Provider::Farnell => {
                "Clé obtenue sur partner.element14.com. Le magasin choisit le catalogue et la devise."
            }
            Provider::Digikey => {
                "Créer une application OAuth sur developer.digikey.com puis reporter l'identifiant et le secret."
            }
            Provider::Tme => {
                "Jeton et secret obtenus sur developers.tme.eu. Le jeton est renouvelé automatiquement."
            }
            Provider::Lcsc => "Catalogue public JLCPCB : aucune clé n'est nécessaire.",
            Provider::Rs => "Clé API (App ID) fournie par RS Components.",
            Provider::Datasheets => {
                "Clé à générer sur datasheets.com/account/api (Product Search API). Catalogue de fiches techniques : pas de prix ni de stock."
            }
        }
    }

    /// Tutoriel d'obtention des identifiants, affiché depuis la page des API.
    pub fn help(self) -> ProviderHelp {
        match self {
            Provider::Nexar => ProviderHelp {
                mark: "NE",
                site: "https://portal.nexar.com/",
                site_label: "portal.nexar.com",
                steps: &[
                    "Créer un compte sur nexar.com, puis ouvrir le portail développeur portal.nexar.com.",
                    "Onglet « Apps » : créer une application et lui accorder la portée « supply.domain ».",
                    "Onglet « Authorization » de l'application, section « Credentials » : relever le Client ID et le Client Secret.",
                    "Coller les deux valeurs ici : le jeton d'accès est demandé automatiquement à chaque recherche.",
                ],
                note: "L'offre gratuite plafonne le nombre de recherches par mois.",
            },
            Provider::Mouser => ProviderHelp {
                mark: "MO",
                site: "https://www.mouser.com/MyMouser/MouserSearchApplication.aspx",
                site_label: "Formulaire Search API",
                steps: &[
                    "Se connecter à un compte My Mouser (ou le créer).",
                    "Ouvrir « My Mouser → My Account → APIs ».",
                    "Remplir le formulaire de demande « Search API ».",
                    "La clé est envoyée par courriel ; elle peut rester en attente d'autorisation quelques heures.",
                ],
                note: "Une clé « Cart / Order API » est refusée par la recherche : il faut bien une clé Search API.",
            },
            Provider::Farnell => ProviderHelp {
                mark: "FA",
                site: "https://partner.element14.com/member/register",
                site_label: "Partner Portal element14",
                steps: &[
                    "Créer un compte sur le Partner Portal element14 (bouton « Register »).",
                    "Demander une clé API depuis l'onglet « API Gallery » (Product Search API).",
                    "Relever la clé, puis choisir ici le magasin qui fixe le catalogue et la devise.",
                ],
                note: "",
            },
            Provider::Digikey => ProviderHelp {
                mark: "DK",
                site: "https://developer.digikey.com/",
                site_label: "developer.digikey.com",
                steps: &[
                    "Créer un compte développeur DigiKey, puis une organisation.",
                    "Créer une application de type serveur (Client Credentials) et lui associer l'API « Product Information ».",
                    "Relever le Client ID et le Client Secret de l'application.",
                ],
                note: "Un quota de production est accordé par défaut ; il se demande depuis la même page.",
            },
            Provider::Tme => ProviderHelp {
                mark: "TM",
                site: "https://developers.tme.eu/",
                site_label: "developers.tme.eu",
                steps: &[
                    "Créer un compte développeur sur developers.tme.eu.",
                    "Ajouter une application : le jeton (Token) et le secret sont générés dans la foulée.",
                    "Relever les deux valeurs dans la fiche de l'application.",
                ],
                note: "Le jeton d'accès est signé et renouvelé automatiquement à chaque recherche.",
            },
            Provider::Lcsc => ProviderHelp {
                mark: "LC",
                site: "https://www.lcsc.com/",
                site_label: "lcsc.com",
                steps: &[
                    "Aucune clé à demander : le catalogue public JLCPCB est interrogé directement.",
                ],
                note: "Source non officielle : si l'accès est interrompu, elle est simplement ignorée.",
            },
            Provider::Rs => ProviderHelp {
                mark: "RS",
                site: "https://www.rs-online.com/",
                site_label: "rs-online.com",
                steps: &[
                    "L'API RS n'est pas en libre-service : l'App ID est délivré par RS Components.",
                    "Demander l'accès à votre contact commercial RS, en précisant l'usage (recherche produit).",
                    "Renseigner ensuite l'App ID et le magasin qui fixe le catalogue.",
                ],
                note: "",
            },
            Provider::Datasheets => ProviderHelp {
                mark: "DS",
                site: "https://www.datasheets.com/account/api",
                site_label: "datasheets.com/account/api",
                steps: &[
                    "Créer un compte sur datasheets.com : l'inscription est gratuite.",
                    "Ouvrir « Account → API » et générer une clé « Product Search API ».",
                    "Coller la clé ici : elle sert de première source pour les fiches techniques.",
                ],
                note: "Le catalogue ne fournit ni prix ni stock, uniquement les fiches techniques.",
            },
        }
    }

    /// Champs de configuration : (clé, libellé, secret, aide).
    pub fn fields(self) -> &'static [FieldSpec] {
        const CLIENT_ID: FieldSpec = FieldSpec {
            name: "client_id",
            label: "Client ID",
            secret: false,
            hint: "",
        };
        const CLIENT_SECRET: FieldSpec = FieldSpec {
            name: "client_secret",
            label: "Client Secret",
            secret: true,
            hint: "",
        };
        const API_KEY: FieldSpec = FieldSpec {
            name: "api_key",
            label: "Clé API",
            secret: true,
            hint: "",
        };
        const TOKEN: FieldSpec = FieldSpec {
            name: "api_key",
            label: "Token OAuth2",
            secret: true,
            hint: "",
        };
        const SECRET: FieldSpec = FieldSpec {
            name: "client_secret",
            label: "Secret OAuth2",
            secret: true,
            hint: "",
        };
        const LIMIT: FieldSpec = FieldSpec {
            name: "search_limit",
            label: "Résultats par recherche",
            secret: false,
            hint: "",
        };
        const FARNELL_STORE: FieldSpec = FieldSpec {
            name: "store_id",
            label: "Magasin",
            secret: false,
            hint: "fr.farnell.com, uk.farnell.com, newark.com…",
        };
        const RS_STORE: FieldSpec = FieldSpec {
            name: "store_id",
            label: "Magasin",
            secret: false,
            hint: "fr.rs-online.com, uk.rs-online.com…",
        };

        match self {
            Provider::Nexar => &[CLIENT_ID, CLIENT_SECRET, LIMIT],
            Provider::Mouser => &[API_KEY, LIMIT],
            Provider::Farnell => &[API_KEY, FARNELL_STORE, LIMIT],
            Provider::Digikey => &[CLIENT_ID, CLIENT_SECRET, LIMIT],
            Provider::Tme => &[TOKEN, SECRET, LIMIT],
            Provider::Lcsc => &[LIMIT],
            Provider::Rs => &[API_KEY, RS_STORE, LIMIT],
            Provider::Datasheets => &[API_KEY, LIMIT],
        }
    }

    /// Bornes du nombre de résultats : (minimum, maximum, défaut).
    pub fn limit_bounds(self) -> (u32, u32, u32) {
        match self {
            Provider::Nexar => (1, 30, 5),
            Provider::Tme | Provider::Lcsc => (1, 100, 10),
            // L'API Datasheets.com plafonne à 10 résultats par appel.
            Provider::Datasheets => (1, 10, 10),
            _ => (1, 50, 10),
        }
    }

    /// Magasin par défaut, quand le fournisseur en utilise un.
    pub fn default_store(self) -> &'static str {
        match self {
            Provider::Farnell => "fr.farnell.com",
            Provider::Rs => "fr.rs-online.com",
            _ => "",
        }
    }

    /// Champs qui vont ensemble : les deux remplis, ou les deux vides.
    pub fn required_pairs(self) -> &'static [(&'static str, &'static str)] {
        match self {
            Provider::Nexar | Provider::Digikey => &[("client_id", "client_secret")],
            Provider::Tme => &[("api_key", "client_secret")],
            _ => &[],
        }
    }
}

#[derive(Debug, Clone, Copy)]
pub struct FieldSpec {
    pub name: &'static str,
    pub label: &'static str,
    pub secret: bool,
    pub hint: &'static str,
}

/// Marche à suivre pour obtenir les identifiants d'un fournisseur.
///
/// Aucun logo distant n'est embarqué : l'application doit rester utilisable
/// sans connexion, la pastille affiche donc le monogramme du fournisseur.
#[derive(Debug, Clone, Copy)]
pub struct ProviderHelp {
    /// Monogramme affiché dans la pastille (deux lettres).
    pub mark: &'static str,
    /// Portail ou formulaire de demande de clé.
    pub site: &'static str,
    /// Libellé du lien vers le portail.
    pub site_label: &'static str,
    /// Étapes numérotées.
    pub steps: &'static [&'static str],
    /// Remarque finale, vide s'il n'y en a pas.
    pub note: &'static str,
}

/// Identifiants d'un fournisseur. Les champs inutilisés restent vides.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Credentials {
    #[serde(default)]
    pub client_id: String,
    #[serde(default)]
    pub client_secret: String,
    #[serde(default)]
    pub api_key: String,
    #[serde(default)]
    pub store_id: String,
    #[serde(default)]
    pub search_limit: u32,
}

impl Credentials {
    /// Valeur affichée dans le formulaire de réglage.
    pub fn value(&self, field: &str) -> String {
        match field {
            "client_id" => self.client_id.clone(),
            "client_secret" => self.client_secret.clone(),
            "api_key" => self.api_key.clone(),
            "store_id" => self.store_id.clone(),
            "search_limit" => self.search_limit.to_string(),
            _ => String::new(),
        }
    }

    pub fn set(&mut self, field: &str, value: String) {
        match field {
            "client_id" => self.client_id = value,
            "client_secret" => self.client_secret = value,
            "api_key" => self.api_key = value,
            "store_id" => self.store_id = value,
            _ => {}
        }
    }

    pub fn is_blank(&self, field: &str) -> bool {
        self.value(field).trim().is_empty()
    }
}

/// Réglages de l'ensemble des fournisseurs.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ApiConfig {
    #[serde(default)]
    pub nexar: Credentials,
    #[serde(default)]
    pub mouser: Credentials,
    #[serde(default)]
    pub farnell: Credentials,
    #[serde(default)]
    pub digikey: Credentials,
    #[serde(default)]
    pub tme: Credentials,
    #[serde(default)]
    pub lcsc: Credentials,
    #[serde(default)]
    pub rs: Credentials,
    #[serde(default)]
    pub datasheets: Credentials,
}

impl ApiConfig {
    pub fn file(root: &Path) -> PathBuf {
        root.join("api.toml")
    }

    /// Lecture des réglages ; les valeurs absentes reprennent les défauts.
    pub fn load(root: &Path) -> ApiConfig {
        let mut config = match std::fs::read_to_string(Self::file(root)) {
            Ok(raw) => toml::from_str::<ApiConfig>(&raw).unwrap_or_default(),
            Err(_) => ApiConfig::default(),
        };

        for provider in Provider::ALL {
            let (_, _, default_limit) = provider.limit_bounds();
            let store = provider.default_store();
            let credentials = config.credentials_mut(provider);
            if credentials.search_limit == 0 {
                credentials.search_limit = default_limit;
            }
            if credentials.store_id.trim().is_empty() && !store.is_empty() {
                credentials.store_id = store.to_string();
            }
        }

        config
    }

    pub fn save(&self, root: &Path) -> std::io::Result<()> {
        let body = toml::to_string_pretty(self)
            .map_err(|error| std::io::Error::new(std::io::ErrorKind::Other, error.to_string()))?;
        let content = format!(
            "# Identifiants des API fournisseurs de CompoZ'IT.\n\
             # Ce fichier est écrit par l'écran « API fournisseurs ».\n\n{body}"
        );
        std::fs::write(Self::file(root), content)
    }

    pub fn credentials(&self, provider: Provider) -> &Credentials {
        match provider {
            Provider::Nexar => &self.nexar,
            Provider::Mouser => &self.mouser,
            Provider::Farnell => &self.farnell,
            Provider::Digikey => &self.digikey,
            Provider::Tme => &self.tme,
            Provider::Lcsc => &self.lcsc,
            Provider::Rs => &self.rs,
            Provider::Datasheets => &self.datasheets,
        }
    }

    pub fn credentials_mut(&mut self, provider: Provider) -> &mut Credentials {
        match provider {
            Provider::Nexar => &mut self.nexar,
            Provider::Mouser => &mut self.mouser,
            Provider::Farnell => &mut self.farnell,
            Provider::Digikey => &mut self.digikey,
            Provider::Tme => &mut self.tme,
            Provider::Lcsc => &mut self.lcsc,
            Provider::Rs => &mut self.rs,
            Provider::Datasheets => &mut self.datasheets,
        }
    }

    /// Un fournisseur est « configuré » quand ses clés obligatoires sont saisies.
    pub fn is_configured(&self, provider: Provider) -> bool {
        let credentials = self.credentials(provider);
        match provider {
            Provider::Nexar | Provider::Digikey => {
                !credentials.client_id.trim().is_empty()
                    && !credentials.client_secret.trim().is_empty()
            }
            Provider::Tme => {
                !credentials.api_key.trim().is_empty()
                    && !credentials.client_secret.trim().is_empty()
            }
            // LCSC est un catalogue public : rien à renseigner.
            Provider::Lcsc => true,
            _ => !credentials.api_key.trim().is_empty(),
        }
    }

    /// Liste des fournisseurs exploitables, dans l'ordre d'affichage.
    pub fn configured(&self) -> Vec<Provider> {
        Provider::ALL
            .into_iter()
            .filter(|provider| self.is_configured(*provider))
            .collect()
    }
}

// ------------------------------------------------------------------ Résultats

/// Meilleure offre trouvée chez un distributeur.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Offer {
    pub price: f64,
    pub currency: String,
    pub quantity: i64,
    pub supplier_name: String,
    pub supplier_ref: String,
    pub inventory: i64,
    pub click_url: String,
    pub tax: String,
}

/// Fiche normalisée, commune à tous les fournisseurs.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Part {
    pub mpn: String,
    pub name: String,
    pub description: String,
    pub manufacturer: String,
    pub category: String,
    pub package: String,
    pub pins: Option<i64>,
    /// « Yes » (CMS) ou « No » (traversant).
    pub smd: String,
    pub datasheet: String,
    pub image_url: String,
    pub best_offer: Option<Offer>,
    pub skus: Vec<String>,
    /// Fournisseur d'origine, ajouté lors de la fusion des résultats.
    #[serde(default)]
    pub source: String,
}

/// Réponse d'une recherche, au format attendu par l'interface.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SearchOutcome {
    pub ok: bool,
    #[serde(default)]
    pub message: String,
    pub hits: usize,
    pub results: Vec<Part>,
}

impl SearchOutcome {
    pub fn success(hits: usize, results: Vec<Part>) -> Self {
        Self {
            ok: true,
            message: String::new(),
            hits,
            results,
        }
    }

    pub fn failure(message: impl Into<String>) -> Self {
        Self {
            ok: false,
            message: message.into(),
            hits: 0,
            results: Vec::new(),
        }
    }
}

// ------------------------------------------------------------------ Utilitaires

/// Client HTTP partagé : délais alignés sur la version PHP.
pub fn client() -> reqwest::Client {
    reqwest::Client::builder()
        .timeout(Duration::from_secs(25))
        .connect_timeout(Duration::from_secs(8))
        .user_agent("CompoZIT/1.0")
        .build()
        .unwrap_or_default()
}

pub fn now_unix() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs() as i64)
        .unwrap_or(0)
}

fn token_dir(root: &Path) -> PathBuf {
    let directory = root.join("tmp");
    let _ = std::fs::create_dir_all(&directory);
    directory
}

fn token_file(root: &Path, name: &str) -> PathBuf {
    token_dir(root).join(format!("{name}_token.json"))
}

#[derive(Serialize, Deserialize)]
struct CachedToken {
    access_token: String,
    expires_at: i64,
}

/// Jeton encore valide, conservé entre deux recherches.
pub fn cached_token(root: &Path, name: &str, margin_seconds: i64) -> Option<String> {
    let raw = std::fs::read_to_string(token_file(root, name)).ok()?;
    let cached: CachedToken = serde_json::from_str(&raw).ok()?;
    if cached.access_token.trim().is_empty() {
        return None;
    }
    if cached.expires_at > now_unix() + margin_seconds {
        Some(cached.access_token)
    } else {
        None
    }
}

pub fn store_token(root: &Path, name: &str, token: &str, expires_in: i64) {
    let cached = CachedToken {
        access_token: token.to_string(),
        expires_at: now_unix() + expires_in.max(0),
    };
    if let Ok(raw) = serde_json::to_string(&cached) {
        let _ = std::fs::write(token_file(root, name), raw);
    }
}

/// Oublie un jeton : appelé quand les identifiants changent.
pub fn drop_token(root: &Path, name: &str) {
    let _ = std::fs::remove_file(token_file(root, name));
}

/// Retire les retours à la ligne d'une valeur collée depuis un site.
pub fn clean_secret(value: &str) -> String {
    value
        .chars()
        .filter(|character| *character != '\r' && *character != '\n')
        .collect::<String>()
        .trim()
        .to_string()
}

/// Ne conserve que les caractères d'un nom de domaine.
pub fn clean_store(value: &str) -> String {
    value
        .chars()
        .filter(|character| character.is_ascii_alphanumeric() || *character == '.' || *character == '-')
        .collect::<String>()
        .to_ascii_lowercase()
}

/// Clé de comparaison d'une référence : majuscules, sans ponctuation.
pub fn normalize_ref(value: &str) -> String {
    value
        .chars()
        .filter(|character| character.is_ascii_alphanumeric())
        .collect::<String>()
        .to_ascii_uppercase()
}

/// Deux références désignent-elles le même composant ?
pub fn refs_match(left: &str, right: &str) -> bool {
    let left = normalize_ref(left);
    let right = normalize_ref(right);
    !left.is_empty() && left == right
}

const THROUGH_HOLE: [&str; 20] = [
    "DIP", "PDIP", "DIL", "TO-92", "TO-220", "TO-247", "TO-3", "TO-18", "TO-39", "TO-126",
    "DO-41", "DO-35", "DO-15", "DO-27", "AXIAL", "THROUGH", "PLUG-IN", "THT", "SIP", "RADIAL",
];

const SURFACE_MOUNT: [&str; 36] = [
    "SOIC", "SO-", "SOP", "SSOP", "TSSOP", "MSOP", "QFN", "DFN", "LGA", "SOT", "SOD", "SC-70",
    "SC-88", "TQFP", "LQFP", "QFP", "BGA", "CSP", "MELF", "DPAK", "D2PAK", "TO-252", "TO-263",
    "TO-277", "SMA", "SMB", "SMC", "0201", "0402", "0603", "0805", "1206", "1210", "1812", "2010",
    "2512",
];

/// Déduit le type de montage d'un boîtier (« Yes » = CMS).
pub fn detect_smd(package: &str) -> String {
    let upper = package.trim().to_ascii_uppercase();
    if upper.is_empty() {
        return "No".to_string();
    }
    if THROUGH_HOLE.iter().any(|family| upper.contains(family)) {
        return "No".to_string();
    }
    if SURFACE_MOUNT.iter().any(|family| upper.contains(family)) {
        return "Yes".to_string();
    }
    "No".to_string()
}

/// Premier champ texte non vide parmi les clés proposées.
pub fn pick(source: &serde_json::Value, keys: &[&str]) -> String {
    for key in keys {
        if let Some(value) = source.get(*key) {
            match value {
                serde_json::Value::String(text) if !text.trim().is_empty() => {
                    return text.trim().to_string();
                }
                serde_json::Value::Number(number) => return number.to_string(),
                _ => {}
            }
        }
    }
    String::new()
}

/// Nombre entier lu dans une valeur JSON, si possible.
pub fn as_number(value: &serde_json::Value) -> Option<f64> {
    match value {
        serde_json::Value::Number(number) => number.as_f64(),
        serde_json::Value::String(text) => text.trim().replace(',', ".").parse::<f64>().ok(),
        _ => None,
    }
}

// -------------------------------------------------------------- Répartition

/// Interroge un fournisseur et renvoie ses résultats normalisés.
pub async fn search(
    client: &reqwest::Client,
    root: &Path,
    config: &ApiConfig,
    provider: Provider,
    query: &str,
    limit: Option<u32>,
) -> SearchOutcome {
    let query = query.trim();
    if query.chars().count() < 2 {
        return SearchOutcome::failure("Saisissez au moins 2 caractères.");
    }
    if !config.is_configured(provider) {
        return SearchOutcome::failure(format!(
            "L'API {} n'est pas configurée : renseignez-la dans « API fournisseurs ».",
            provider.label()
        ));
    }

    let credentials = config.credentials(provider);
    let (minimum, maximum, _) = provider.limit_bounds();
    let limit = limit.unwrap_or(credentials.search_limit).clamp(minimum, maximum);

    let outcome = match provider {
        Provider::Nexar => nexar::search_parts(client, root, credentials, query, limit).await,
        Provider::Mouser => mouser::search_parts(client, root, credentials, query, limit).await,
        Provider::Farnell => farnell::search_parts(client, root, credentials, query, limit).await,
        Provider::Digikey => digikey::search_parts(client, root, credentials, query, limit).await,
        Provider::Tme => tme::search_parts(client, root, credentials, query, limit).await,
        Provider::Lcsc => lcsc::search_parts(client, root, credentials, query, limit).await,
        Provider::Rs => rs::search_parts(client, root, credentials, query, limit).await,
        Provider::Datasheets => {
            datasheets::search_parts(client, root, credentials, query, limit).await
        }
    };

    match outcome {
        Ok(mut parts) => {
            for part in parts.iter_mut() {
                part.source = provider.key().to_string();
            }
            let hits = parts.len();
            SearchOutcome::success(hits, parts)
        }
        Err(message) => SearchOutcome::failure(message),
    }
}
