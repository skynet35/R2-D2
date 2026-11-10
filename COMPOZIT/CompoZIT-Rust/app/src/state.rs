use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use minijinja::Environment;
use sqlx::MySqlPool;
use tokio::sync::{Notify, RwLock};

use crate::config::{AppConfig, BackupConfig, DatabaseConfig, SiteConfig};
use crate::error::{AppError, AppResult};
use crate::site::SiteClient;

/// État partagé par toutes les requêtes.
#[derive(Clone)]
pub struct AppState {
    pub templates: Arc<Environment<'static>>,
    pub config_path: PathBuf,
    /// Racine de l'application : les images et les PDF y sont déposés, à la
    /// même place que dans la version PHP (`img/`, `datasheets/`).
    pub root: PathBuf,
    pub inner: Arc<RwLock<Runtime>>,
    /// Signal d'arrêt, déclenché par le bouton « Quitter » de l'interface.
    pub shutdown: Arc<Notify>,
}

/// Partie mutable : la base peut être (re)pointée depuis l'écran « Base de
/// données » sans redémarrer le serveur.
pub struct Runtime {
    pub config: AppConfig,
    pub configured: bool,
    pub pool: Option<MySqlPool>,
    /// Présent quand l'application travaille en mode « site en ligne » : elle
    /// relaie alors les écrans du site au lieu d'interroger la base.
    pub site: Option<Arc<SiteClient>>,
}

impl Default for Runtime {
    fn default() -> Self {
        Self {
            config: AppConfig::default(),
            configured: false,
            pool: None,
            site: None,
        }
    }
}

impl AppState {
    pub fn new(
        templates: Environment<'static>,
        config_path: PathBuf,
        root: PathBuf,
        runtime: Runtime,
    ) -> Self {
        Self {
            templates: Arc::new(templates),
            config_path,
            root,
            inner: Arc::new(RwLock::new(runtime)),
            shutdown: Arc::new(Notify::new()),
        }
    }

    /// Demande l'arrêt du serveur : la requête en cours se termine, puis le
    /// processus se ferme. Déclenché par le bouton « Quitter » de l'interface.
    pub fn request_shutdown(&self) {
        self.shutdown.notify_one();
    }

    pub async fn pool(&self) -> Option<MySqlPool> {
        self.inner.read().await.pool.clone()
    }

    /// Pool de connexions ou redirection vers l'écran de configuration.
    pub async fn require_pool(&self) -> AppResult<MySqlPool> {
        self.pool().await.ok_or(AppError::NotConfigured)
    }

    pub async fn database_config(&self) -> DatabaseConfig {
        self.inner.read().await.config.database.clone()
    }

    pub async fn database_label(&self) -> String {
        self.database_config().await.label()
    }

    pub async fn backup_config(&self) -> BackupConfig {
        self.inner.read().await.config.backup.clone()
    }

    /// Enregistre les réglages de sauvegarde et les persiste dans config.toml.
    pub async fn save_backup(&self, backup: BackupConfig) -> AppResult<()> {
        let mut runtime = self.inner.write().await;
        runtime.config.backup = backup;
        runtime.config.save(&self.config_path).map_err(|error| {
            AppError::Message(format!(
                "Écriture de {} impossible : {error}",
                self.config_path.display()
            ))
        })
    }

    /// Enregistre la nouvelle base et la persiste dans config.toml.
    pub async fn apply_database(&self, database: DatabaseConfig, pool: MySqlPool) -> AppResult<()> {
        let mut runtime = self.inner.write().await;
        runtime.config.database = database;
        runtime.pool = Some(pool);
        runtime.configured = true;
        runtime.config.save(&self.config_path).map_err(|error| {
            AppError::Message(format!(
                "Connexion réussie, mais écriture de {} impossible : {error}",
                self.config_path.display()
            ))
        })
    }

    pub async fn site_config(&self) -> SiteConfig {
        self.inner.read().await.config.site.clone()
    }

    /// Vrai quand les écrans du site en ligne sont relayés.
    pub async fn site_mode(&self) -> bool {
        self.inner.read().await.site.is_some()
    }

    pub async fn site_client(&self) -> Option<Arc<SiteClient>> {
        self.inner.read().await.site.clone()
    }

    /// Bascule en mode « site en ligne » et persiste le choix.
    pub async fn enable_site(&self, site: SiteConfig, client: Arc<SiteClient>) -> AppResult<()> {
        let mut runtime = self.inner.write().await;
        runtime.config.site = site;
        runtime.site = Some(client);
        runtime.configured = true;
        runtime.config.save(&self.config_path).map_err(|error| {
            AppError::Message(format!(
                "Connexion réussie, mais écriture de {} impossible : {error}",
                self.config_path.display()
            ))
        })
    }

    /// Revient au mode « base de données » sans toucher au reste de la
    /// configuration. Le mode n'étant choisi qu'au démarrage, l'appelant doit
    /// ensuite relancer l'application.
    pub async fn disable_site(&self) -> AppResult<()> {
        let mut runtime = self.inner.write().await;
        runtime.config.site.enabled = false;
        runtime.site = None;
        runtime.config.save(&self.config_path).map_err(|error| {
            AppError::Message(format!(
                "Écriture de {} impossible : {error}",
                self.config_path.display()
            ))
        })
    }
}

// ------------------------------------------------------- Présence du navigateur

/// Délai sans aucun signe de vie au-delà duquel l'application s'arrête. C'est
/// le garde-fou quand la page disparaît sans avoir pu prévenir (navigateur
/// tué, onglet recyclé par manque de mémoire).
const BROWSER_IDLE_LIMIT: Duration = Duration::from_secs(45);

/// Délai laissé après la fermeture d'une page pour en ouvrir une autre : une
/// navigation normale se signale avant l'échéance et annule l'arrêt.
const BROWSER_CLOSE_GRACE: Duration = Duration::from_secs(15);

/// Fenêtre pendant laquelle un dernier signe de vie vaut « page affichée ».
/// Au-delà, l'instance est considérée sans navigateur.
const BROWSER_ATTACHED_WINDOW: Duration = Duration::from_secs(20);

/// Dernier signe de vie reçu du navigateur (millisecondes depuis l'époque
/// Unix). Reste à zéro tant qu'aucune page n'a été ouverte : une application
/// que personne ne regarde n'est jamais arrêtée par ce mécanisme.
static BROWSER_LAST_SEEN: AtomicU64 = AtomicU64::new(0);

/// Échéance d'arrêt programmée par la fermeture de la dernière page.
static BROWSER_CLOSING_AT: AtomicU64 = AtomicU64::new(0);

fn now_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|since| since.as_millis() as u64)
        .unwrap_or(0)
}

/// Une page de l'application est affichée : elle se signale régulièrement.
pub fn browser_alive() {
    BROWSER_LAST_SEEN.store(now_ms(), Ordering::Relaxed);
    BROWSER_CLOSING_AT.store(0, Ordering::Relaxed);
}

/// Une page vient de se fermer. Si aucune autre ne se signale dans le délai
/// imparti, l'application s'arrête : fermer l'onglet ou le navigateur revient
/// donc à fermer CompoZ'IT.
pub fn browser_page_closed() {
    let deadline = now_ms().saturating_add(BROWSER_CLOSE_GRACE.as_millis() as u64);
    BROWSER_CLOSING_AT.store(deadline, Ordering::Relaxed);
}

fn browser_gone() -> bool {
    let now = now_ms();
    let deadline = BROWSER_CLOSING_AT.load(Ordering::Relaxed);
    if deadline != 0 {
        return now >= deadline;
    }
    let seen = BROWSER_LAST_SEEN.load(Ordering::Relaxed);
    seen != 0 && now.saturating_sub(seen) >= BROWSER_IDLE_LIMIT.as_millis() as u64
}

/// Vrai quand des pages sont affichées : une seconde instance lancée par
/// erreur se contente alors de ramener l'utilisateur sur celle qui travaille.
/// Une fermeture en cours vaut « plus personne », pour que la relance
/// immédiate reprenne la main au lieu d'attendre la fin de l'arrêt.
pub fn browser_attached() -> bool {
    if BROWSER_CLOSING_AT.load(Ordering::Relaxed) != 0 {
        return false;
    }
    let seen = BROWSER_LAST_SEEN.load(Ordering::Relaxed);
    seen != 0 && now_ms().saturating_sub(seen) < BROWSER_ATTACHED_WINDOW.as_millis() as u64
}

/// Surveille la présence du navigateur et arrête le serveur dès qu'aucune page
/// ne l'affiche plus. L'application ne reste pas ouverte en tâche de fond : le
/// port est libéré, `CompoZIT.exe` peut être relancé aussitôt.
pub fn spawn_browser_watchdog(state: AppState) {
    tokio::spawn(async move {
        let mut ticker = tokio::time::interval(Duration::from_secs(3));
        // Après une longue interruption, on repart d'un cycle neuf au lieu de
        // rattraper en rafale les cycles manqués.
        ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
        let mut previous = Instant::now();
        loop {
            ticker.tick().await;
            let now = Instant::now();
            let gap = now.duration_since(previous);
            previous = now;
            // Machine mise en veille : les deux horloges se sont arrêtées
            // ensemble, l'écart ne doit pas passer pour une fermeture.
            if gap > Duration::from_secs(30) {
                continue;
            }
            if browser_gone() {
                tracing::info!("Navigateur fermé : arrêt de CompoZ'IT.");
                state.request_shutdown();
                return;
            }
        }
    });
}
