use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

/// Pointage vers la base MySQL / MariaDB.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DatabaseConfig {
    pub host: String,
    pub port: u16,
    pub user: String,
    #[serde(default)]
    pub password: String,
    pub database: String,
}

impl Default for DatabaseConfig {
    fn default() -> Self {
        Self {
            host: "localhost".to_string(),
            port: 3306,
            user: "root".to_string(),
            password: String::new(),
            database: "compozit".to_string(),
        }
    }
}

impl DatabaseConfig {
    pub fn label(&self) -> String {
        format!("{}:{}/{}", self.host, self.port, self.database)
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServerConfig {
    pub host: String,
    pub port: u16,
    /// Marque le cookie de session comme « Secure » : le navigateur ne
    /// l'enverra alors que sur une connexion chiffrée. Activé en ligne, où
    /// l'accès passe obligatoirement par HTTPS ; laissé à `false` en local,
    /// où l'application est jointe en `http://127.0.0.1`.
    #[serde(default)]
    pub secure_cookies: bool,
}

impl Default for ServerConfig {
    fn default() -> Self {
        Self {
            host: "127.0.0.1".to_string(),
            port: 8080,
            secure_cookies: false,
        }
    }
}

impl ServerConfig {
    /// Vrai quand le serveur n'écoute que sur cette machine (usage local).
    /// Dans ce cas seulement, une fenêtre de navigateur est ouverte au
    /// démarrage : sur un serveur en ligne, cela ne rime à rien.
    pub fn is_local(&self) -> bool {
        matches!(self.host.trim(), "127.0.0.1" | "localhost" | "::1" | "[::1]")
    }
}

/// Sauvegarde locale de la base : autonomie, récurrence et rétention.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BackupConfig {
    #[serde(default)]
    pub enabled: bool,
    /// Écart en jours entre deux sauvegardes (1 = quotidienne, 7 = hebdomadaire).
    #[serde(default = "default_interval_days")]
    pub interval_days: u32,
    /// Heure locale de déclenchement (0-23).
    #[serde(default = "default_backup_hour")]
    pub hour: u32,
    /// Nombre de fichiers conservés : au-delà, les plus anciens sont supprimés.
    #[serde(default = "default_keep")]
    pub keep: u32,
    /// Date de la dernière sauvegarde réussie (« AAAA-MM-JJ HH:MM:SS »), vide
    /// tant qu'aucune sauvegarde n'a encore été produite.
    #[serde(default)]
    pub last_run: String,
}

fn default_interval_days() -> u32 {
    1
}

fn default_backup_hour() -> u32 {
    3
}

fn default_keep() -> u32 {
    10
}

impl Default for BackupConfig {
    fn default() -> Self {
        Self {
            // La sauvegarde automatique reste à activer depuis l'écran dédié :
            // l'application n'écrit pas de fichiers sans accord explicite.
            enabled: false,
            interval_days: default_interval_days(),
            hour: default_backup_hour(),
            keep: default_keep(),
            last_run: String::new(),
        }
    }
}

/// Acces au site en ligne : la base MySQL n'est alors pas jointe directement,
/// l'application relaie les ecrans du site et garde sa session ouverte.
/// Utile quand l'hebergement refuse les connexions MySQL distantes.
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct SiteConfig {
    /// Vrai quand l'application travaille en mode « site en ligne ».
    #[serde(default)]
    pub enabled: bool,
    /// Adresse du site, sans slash final (« https://exemple.fr »).
    #[serde(default)]
    pub url: String,
    /// Page de connexion du site (« auth.php », « login »...). Renseignee a la
    /// validation ; vide, elle est recherchee automatiquement.
    #[serde(default)]
    pub login_path: String,
    /// Identifiant et mot de passe memorises pour la reconnexion au demarrage.
    #[serde(default)]
    pub username: String,
    #[serde(default)]
    pub password: String,
}

impl SiteConfig {
    /// Vrai quand le mode « site en ligne » doit etre utilise.
    pub fn is_ready(&self) -> bool {
        self.enabled && !self.url.trim().is_empty()
    }

    pub fn label(&self) -> String {
        let url = self.url.trim().trim_end_matches('/');
        if url.is_empty() {
            "site non défini".to_string()
        } else {
            url.to_string()
        }
    }
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct AppConfig {
    #[serde(default)]
    pub server: ServerConfig,
    #[serde(default)]
    pub database: DatabaseConfig,
    #[serde(default)]
    pub site: SiteConfig,
    #[serde(default)]
    pub backup: BackupConfig,
}

impl AppConfig {
    pub fn load(path: &Path) -> Option<AppConfig> {
        let raw = std::fs::read_to_string(path).ok()?;
        match toml::from_str::<AppConfig>(&raw) {
            Ok(config) => Some(config),
            Err(error) => {
                tracing::error!("Fichier de configuration illisible ({}): {error}", path.display());
                None
            }
        }
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let body = toml::to_string_pretty(self)
            .map_err(|error| std::io::Error::new(std::io::ErrorKind::Other, error.to_string()))?;
        let content = format!(
            "# Configuration de CompoZ'IT (version Rust)\n\
             # Ce fichier est écrit par l'écran « Base de données ».\n\n{body}"
        );
        std::fs::write(path, content)
    }
}

pub struct Paths {
    pub root: PathBuf,
    pub config_file: PathBuf,
    pub templates: PathBuf,
    pub static_dir: PathBuf,
}

/// Localise les ressources : à côté de l'exécutable en production, à la racine
/// du projet quand on lance `cargo run`.
pub fn resolve_paths() -> Paths {
    let exe_dir = std::env::current_exe()
        .ok()
        .and_then(|path| path.parent().map(Path::to_path_buf))
        .unwrap_or_else(|| PathBuf::from("."));

    let root = if exe_dir.join("templates").is_dir() {
        exe_dir
    } else {
        let project = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
        if project.join("templates").is_dir() {
            project
        } else {
            exe_dir
        }
    };

    Paths {
        config_file: root.join("config.toml"),
        templates: root.join("templates"),
        static_dir: root.join("static"),
        root,
    }
}
