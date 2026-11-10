#![cfg_attr(all(windows, not(debug_assertions)), windows_subsystem = "windows")]

mod api;
mod backup;
mod config;
mod db;
mod enrich;
mod error;
mod params;
mod routes;
mod schema;
mod session;
mod site;
mod state;
mod templates;
mod util;

use axum::http::{header, HeaderValue};
use axum::routing::{get, post};
use axum::Router;
use tower_http::services::ServeDir;
use tower_http::set_header::SetResponseHeaderLayer;
use tower_http::trace::TraceLayer;
use tower_sessions::{MemoryStore, SessionManagerLayer};

use crate::state::{AppState, Runtime};

#[tokio::main]
async fn main() {
    // Lancé par double-clic, Windows ouvre une console dédiée : elle ne sert
    // qu'aux messages de diagnostic et masque la fenêtre principale (le
    // navigateur). On la cache ; le programme continue d'écrire dedans.
    hide_own_console();

    let filter = std::env::var("RUST_LOG").unwrap_or_else(|_| "info,tower_http=warn".to_string());
    tracing_subscriber::fmt().with_env_filter(filter).init();

    let paths = config::resolve_paths();
    tracing::info!("Dossier de travail : {}", paths.root.display());

    let mut runtime = Runtime::default();

    match config::AppConfig::load(&paths.config_file) {
        Some(app_config) => {
            tracing::info!("Configuration lue : {}", paths.config_file.display());
            // Deux modes d'accès, choisis au démarrage : soit la base MySQL est
            // jointe directement, soit les écrans du site en ligne sont relayés.
            if app_config.site.is_ready() {
                start_site(&mut runtime, &app_config.site).await;
                match db::connect(&app_config.database).await {
                    Ok(pool) => {
                        tracing::info!(
                            "Mode site — accès SQL local disponible pour l'import/export CSV ({})",
                            app_config.database.label()
                        );
                        migrate(&pool).await;
                        runtime.pool = Some(pool);
                    }
                    Err(error) => tracing::warn!(
                        "Mode site — accès SQL local indisponible pour l'import/export CSV : {error}"
                    ),
                }
            } else {
                match db::connect(&app_config.database).await {
                    Ok(pool) => {
                        tracing::info!("Connecté à MySQL — {}", app_config.database.label());
                        migrate(&pool).await;
                        runtime.pool = Some(pool);
                    }
                    Err(error) => {
                        tracing::warn!("Connexion impossible à {} : {error}", app_config.database.label());
                        tracing::warn!("L'écran de configuration de la base sera affiché.");
                    }
                }
            }
            runtime.config = app_config;
            runtime.configured = true;
        }
        None => tracing::warn!(
            "Aucune configuration dans {} : l'assistant de configuration sera affiché.",
            paths.config_file.display()
        ),
    }

    let address = format!("{}:{}", runtime.config.server.host, runtime.config.server.port);
    let port = runtime.config.server.port;
    let url = format!("http://{address}/");
    // Reglages lus avant que `runtime` ne rejoigne l'etat partage.
    let local_only = runtime.config.server.is_local();
    let secure_cookies = runtime.config.server.secure_cookies;
    let site_mode = runtime.site.is_some();

    let state = AppState::new(
        templates::create_env(&paths.templates),
        paths.config_file.clone(),
        paths.root.clone(),
        runtime,
    );

    let session_layer = SessionManagerLayer::new(MemoryStore::default()).with_secure(secure_cookies);

    let local_routes: Router<AppState> = Router::new()
        .route("/", get(routes::index))
        .route("/setup", get(routes::setup_form).post(routes::setup_submit))
        .route("/login", get(routes::login_form).post(routes::login_submit))
        .route("/logout", post(routes::logout))
        .route("/quit", post(routes::quit))
        .route("/__alive", get(routes::alive))
        .route("/__closing", get(routes::closing).post(routes::closing))
        .route("/__status", get(routes::status))
        .route("/__stop", post(routes::stop))
        .route(
            "/components",
            get(routes::components::list).post(routes::component_form::create),
        )
        .route("/components/new", get(routes::component_form::new_form))
        .route("/components/{id}/edit", get(routes::component_form::edit_form))
        .route("/components/{id}/sheet", get(routes::sheet::sheet))
        .route("/components/{id}/enrich", post(routes::enrich::single))
        .route("/components/{id}", post(routes::component_form::update))
        .route(
            "/components/{id}/delete",
            post(routes::component_form::delete_component),
        )
        .route(
            "/locations",
            get(routes::locations::list).post(routes::locations::create),
        )
        .route("/locations/new", get(routes::locations::new_form))
        .route(
            "/locations/batch",
            get(routes::locations::batch_form).post(routes::locations::batch_create),
        )
        .route(
            "/locations/casier-type",
            post(routes::locations::set_casier_type),
        )
        .route(
            "/locations/casier-delete",
            post(routes::locations::delete_empty_casier),
        )
        .route("/locations/delete", post(routes::locations::delete_location))
        .route(
            "/locations/{id}/edit",
            get(routes::locations::edit_form),
        )
        .route("/locations/{id}", post(routes::locations::update))
        .route("/categories", get(routes::categories::list))
        .route("/categories/add", post(routes::categories::add_category))
        .route(
            "/categories/add-sub",
            post(routes::categories::add_subcategory),
        )
        .route(
            "/categories/rename",
            post(routes::categories::rename_category),
        )
        .route(
            "/categories/rename-sub",
            post(routes::categories::rename_subcategory),
        )
        .route(
            "/categories/delete",
            post(routes::categories::delete_category),
        )
        .route(
            "/categories/delete-sub",
            post(routes::categories::delete_subcategory),
        )
        .route("/api/categories", get(routes::categories::refresh))
        .route("/manufacturers", get(routes::manufacturers::list))
        .route("/manufacturers/add", post(routes::manufacturers::add))
        .route(
            "/manufacturers/rename",
            post(routes::manufacturers::rename),
        )
        .route(
            "/manufacturers/delete",
            post(routes::manufacturers::delete),
        )
        .route("/api/manufacturers/list", get(routes::manufacturers::refresh))
        .route("/suppliers", get(routes::suppliers::list))
        .route("/suppliers/add", post(routes::suppliers::add))
        .route("/suppliers/update", post(routes::suppliers::update))
        .route("/suppliers/delete", post(routes::suppliers::delete))
        .route("/api/suppliers/list", get(routes::suppliers::refresh))
        .route("/projects", get(routes::projects::list))
        .route("/projects/create", post(routes::projects::create))
        .route("/projects/update", post(routes::projects::update))
        .route("/projects/delete", post(routes::projects::delete))
        .route("/api/projects", get(routes::projects::refresh))
        .route("/projects/{id}", get(routes::project_detail::detail))
        .route("/api/projects/{id}", get(routes::project_detail::refresh))
        .route(
            "/projects/{id}/components/add",
            post(routes::project_detail::add_component),
        )
        .route(
            "/projects/{id}/components/update",
            post(routes::project_detail::update_component),
        )
        .route(
            "/projects/{id}/components/delete",
            post(routes::project_detail::delete_component),
        )
        .route(
            "/projects/{id}/items/add",
            post(routes::project_detail::add_item),
        )
        .route(
            "/projects/{id}/items/update",
            post(routes::project_detail::update_item),
        )
        .route(
            "/projects/{id}/items/edit",
            post(routes::project_detail::edit_item),
        )
        .route(
            "/projects/{id}/items/delete",
            post(routes::project_detail::delete_item),
        )
        .route(
            "/projects/{id}/files/upload",
            post(routes::project_detail::upload_file),
        )
        .route(
            "/projects/{id}/files/delete",
            post(routes::project_detail::delete_file),
        )
        .route(
            "/projects/{id}/image",
            post(routes::project_detail::upload_image),
        )
        .route(
            "/projects/{id}/delete",
            post(routes::project_detail::delete_project),
        )
        .route("/api/components", get(routes::components::search_api))
        .route(
            "/api/components/quantity",
            post(routes::components::adjust_quantity),
        )
        .route(
            "/api/components/duplicates",
            get(routes::components::check_duplicate),
        )
        .route("/api/enrich-all", post(routes::enrich::all_ajax))
        .route("/api/subcategories", get(routes::components::subcategories_api))
        .route("/api/suppliers", get(routes::components::suppliers_api))
        .route("/api/manufacturers", get(routes::components::manufacturers_api))
        .route("/api/locations", get(routes::components::locations_api))
        .route("/api/packages", get(routes::components::packages_api))
        .route("/api/images", get(routes::components::images_api))
        .route("/marking", get(routes::marking::list))
        .route("/api/marking", get(routes::marking::search_api))
        .route("/images", get(routes::images::list))
        .route("/api/images/library", get(routes::images::refresh))
        .route("/api/images/references", get(routes::images::references))
        .route("/images/upload", post(routes::images::upload))
        .route("/images/rename", post(routes::images::rename))
        .route("/images/delete", post(routes::images::delete))
        .route("/api-settings", get(routes::api_settings::list))
        .route("/api-settings/save", post(routes::api_settings::save))
        .route("/transfer", get(routes::transfer::page))
        .route("/transfer/export.xls", get(routes::transfer::export_xls))
        .route("/transfer/export.csv", get(routes::transfer::export_csv))
        .route("/transfer/export.json", get(routes::transfer::export_json))
        .route("/transfer/modele.csv", get(routes::transfer::template_csv))
        .route("/transfer/import", post(routes::transfer::import))
        .route("/transfer/confirm", post(routes::transfer::confirm))
        .route("/transfer/cancel", post(routes::transfer::cancel))
        .route("/backup", get(routes::backup::page))
        .route("/backup/save", post(routes::backup::save))
        .route("/backup/run", post(routes::backup::run))
        .route("/backup/delete", post(routes::backup::delete))
        .route("/backup/download/{name}", get(routes::backup::download))
        .route("/api/suppliers/search", get(routes::api_settings::search_api))
        .route(
            "/api/suppliers/datasheet",
            get(routes::api_settings::datasheet_api),
        )
        .nest_service("/static", ServeDir::new(paths.static_dir.clone()))
        // Images des composants : le dossier `img/` de l'application PHP est
        // recopié à côté de l'exécutable, les nouveaux fichiers y sont déposés.
        .nest_service("/img", ServeDir::new(paths.root.join("img")))
        // Les PDF de fiches techniques déposés par l'application PHP restent
        // accessibles si le dossier est recopié à côté de l'exécutable.
        .nest_service("/datasheets", ServeDir::new(paths.root.join("datasheets")))
        // Dossiers des projets : images de couverture et pièces jointes.
        .nest_service("/projets", ServeDir::new(paths.root.join("projets")));

    // Mode « site en ligne » : les écrans du site sont relayés tels quels, si
    // bien que tout ce que le site sait faire reste accessible. Seuls les
    // réglages et l'arrêt restent locaux, avec les fichiers de cette interface.
    // Images, fiches techniques et pièces jointes viennent du site, jamais de la
    // copie locale, qui peut avoir vieilli.
    let site_routes: Router<AppState> = Router::new()
        .route("/setup", get(routes::setup_form).post(routes::setup_submit))
        .route("/__quit", get(routes::quit_local))
        .route("/__alive", get(routes::alive))
        .route("/__closing", get(routes::closing).post(routes::closing))
        .route("/__status", get(routes::status))
        .route("/__stop", post(routes::stop))
        .route("/transfer", get(routes::transfer::site_page))
        .route("/transfer/export.xls", get(routes::transfer::site_export_xls))
        .route("/transfer/export.csv", get(routes::transfer::site_export_csv))
        .route("/transfer/export.json", get(routes::transfer::site_export_json))
        .route("/transfer/modele.csv", get(routes::transfer::site_template_csv))
        .route("/transfer/import", post(routes::transfer::site_import))
        .nest_service("/static", ServeDir::new(paths.static_dir.clone()))
        .fallback(site::proxy);

    // Les écrans et les scripts doivent être revalidés à chaque chargement.
    // Sans cet en-tête, le navigateur applique un cache heuristique et peut
    // continuer à servir un ancien `app.js` : les pages récemment ajoutées
    // resteraient alors inertes (le formulaire partirait en GET natif).
    // La revalidation reste peu coûteuse : le serveur répond 304 quand le
    // fichier n'a pas changé.
    let app = if site_mode { site_routes } else { local_routes }
        .layer(SetResponseHeaderLayer::overriding(
            header::CACHE_CONTROL,
            HeaderValue::from_static("no-cache"),
        ))
        .layer(TraceLayer::new_for_http())
        .layer(session_layer)
        .with_state(state.clone());

    // Le port peut encore être tenu par une instance qui vient de perdre son
    // navigateur : elle s'arrête d'elle-même, mais pas instantanément. On
    // reprend donc la main au lieu d'échouer sur un port occupé.
    let listener = match bind_server(&address, port, &url, local_only).await {
        Some(listener) => listener,
        None => fatal(&format!(
            "Le port {port} est déjà utilisé par un autre programme.\n\
             Fermez-le, ou changez le port dans config.toml."
        )),
    };

    println!("CompoZ'IT (Rust) — interface disponible sur {url}");
    tracing::info!("Serveur prêt : {url}");
    if local_only {
        println!("Pour arrêter : bouton « Quitter » dans l'interface, ou fermez cette fenêtre.");
        open_browser(&url);
    } else {
        println!("Serveur en ligne : arrêt par le bouton « Quitter » ou par l'arrêt du service.");
    }

    // Surveillance de l'échéance de sauvegarde, indépendante des requêtes.
    backup::spawn(state.clone());

    // Les pages affichées se signalent régulièrement ; sans signe de vie,
    // l'application s'arrête d'elle-même. Fermer l'onglet ou le navigateur
    // revient donc à fermer CompoZ'IT, et le port est libéré aussitôt.
    if local_only {
        state::spawn_browser_watchdog(state.clone());
    }

    // Le bouton « Quitter » de l'interface demande l'arrêt du serveur ; la
    // requête en cours (la page de confirmation) se termine avant la fermeture.
    let shutdown = {
        let signal = state.shutdown.clone();
        async move { signal.notified().await }
    };

    if let Err(error) = axum::serve(listener, app)
        .with_graceful_shutdown(shutdown)
        .await
    {
        fatal(&format!("Serveur interrompu : {error}"));
    }

    println!("CompoZ'IT est arrêté.");
}

/// Prépare le mode « site en ligne » au démarrage : la session est ouverte une
/// fois pour toutes, l'adresse locale devenant ensuite le seul point d'entrée
/// pour l'utilisateur.
///
/// Ni un site injoignable ni des identifiants refusés n'empêchent le démarrage :
/// le navigateur affichera alors la page du site, ou son écran de connexion, et
/// l'adresse reste corrigible depuis l'écran « Base de données ».
async fn start_site(runtime: &mut Runtime, config: &config::SiteConfig) {
    let client = match site::SiteClient::build(config) {
        Ok(client) => client,
        Err(message) => {
            tracing::error!("Mode site indisponible : {message}");
            return;
        }
    };

    match client.login().await {
        Ok(site::Login::Ok(path)) => {
            tracing::info!("Mode site — {} (session ouverte via {path})", client.base())
        }
        Ok(site::Login::Skipped) => tracing::info!(
            "Mode site — {} (aucun identifiant enregistré : la page de connexion du site sera affichée)",
            client.base()
        ),
        Ok(site::Login::Refused) => tracing::warn!(
            "Mode site — {} a refusé les identifiants enregistrés",
            client.base()
        ),
        Err(message) => tracing::warn!("Mode site — {message}"),
    }

    runtime.site = Some(client);
}

/// Aligne le schéma de la base sur celui de la version PHP : colonne `marking`,
/// tables `param_definitions` / `data_params` et référentiel des paramètres
/// techniques. Un échec n'empêche pas le démarrage : les écrans continuent de
/// fonctionner, seul l'enregistrement des paramètres resterait indisponible.
async fn migrate(pool: &sqlx::MySqlPool) {
    if let Err(error) = schema::init(pool).await {
        tracing::warn!("Mise à jour du schéma impossible : {error}");
    }
}

/// Arrêt sur erreur fatale : impossible de démarrer le serveur, l'application
/// ne peut rien faire. Le message doit rester visible : dans la console quand
/// il y en a une, dans une boîte de dialogue sinon (fenêtre masquée).
fn fatal(message: &str) -> ! {
    tracing::error!("{message}");
    if console_hidden() {
        message_box("CompoZ'IT n'a pas pu démarrer", message);
        std::process::exit(1);
    }
    println!();
    println!("  ==============================================================");
    println!("   CompoZ'IT n'a pas pu démarrer");
    println!("  ==============================================================");
    for line in message.lines() {
        println!("   {line}");
    }
    println!();
    wait_for_key();
    std::process::exit(1);
}

/// Console masquée au démarrage (lancement par double-clic).
#[cfg(windows)]
static CONSOLE_HIDDEN: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);

/// Vrai si la fenêtre de console a été masquée au démarrage.
#[cfg(windows)]
fn console_hidden() -> bool {
    CONSOLE_HIDDEN.load(std::sync::atomic::Ordering::Relaxed)
}

#[cfg(not(windows))]
fn console_hidden() -> bool {
    false
}

/// Masque la console Windows si elle a été créée pour ce seul processus, c'est
/// à dire en lancement par double-clic. Lancé depuis une invite de commandes,
/// le programme partage la console de l'utilisateur : elle est laissée telle
/// quelle, les messages de diagnostic restent donc consultables.
fn hide_own_console() {
    #[cfg(windows)]
    {
        if attached_console_processes() > 1 {
            return;
        }
        #[link(name = "Kernel32")]
        unsafe extern "system" {
            fn GetConsoleWindow() -> isize;
        }
        #[link(name = "User32")]
        unsafe extern "system" {
            fn ShowWindow(window: isize, command: i32) -> i32;
        }
        const SW_HIDE: i32 = 0;
        let window = unsafe { GetConsoleWindow() };
        if window != 0 {
            unsafe { ShowWindow(window, SW_HIDE) };
            CONSOLE_HIDDEN.store(true, std::sync::atomic::Ordering::Relaxed);
        }
    }
}

/// Boîte de dialogue Windows, seul moyen d'avertir l'utilisateur d'une erreur
/// fatale quand la console est masquée.
#[cfg(windows)]
fn message_box(title: &str, message: &str) {
    #[link(name = "User32")]
    unsafe extern "system" {
        fn MessageBoxW(window: isize, text: *const u16, caption: *const u16, kind: u32) -> i32;
    }
    const MB_OK: u32 = 0x0000_0000;
    const MB_ICONERROR: u32 = 0x0000_0010;
    const MB_SETFOREGROUND: u32 = 0x0001_0000;

    fn wide(text: &str) -> Vec<u16> {
        text.encode_utf16().chain(std::iter::once(0)).collect()
    }

    let text = wide(message);
    let caption = wide(title);
    unsafe {
        MessageBoxW(
            0,
            text.as_ptr(),
            caption.as_ptr(),
            MB_OK | MB_ICONERROR | MB_SETFOREGROUND,
        );
    }
}

#[cfg(not(windows))]
fn message_box(_title: &str, _message: &str) {}

/// Maintient la fenêtre ouverte pour que le message d'erreur soit lisible.
///
/// Seule une console créée par le double-clic ne compte qu'un processus : dans
/// ce cas on attend une touche. Lancé depuis un terminal ou un script, le
/// programme se termine aussitôt pour ne rien bloquer.
fn wait_for_key() {
    #[cfg(windows)]
    {
        if attached_console_processes() > 1 {
            return;
        }
        print!("   Appuyez sur Entrée pour fermer cette fenêtre... ");
        use std::io::Write;
        let _ = std::io::stdout().flush();
        let mut line = String::new();
        let _ = std::io::stdin().read_line(&mut line);
    }
}

/// Nombre de processus attachés à la console courante (1 = console créée pour
/// ce seul processus, donc lancement par double-clic).
#[cfg(windows)]
fn attached_console_processes() -> u32 {
    #[link(name = "Kernel32")]
    unsafe extern "system" {
        fn GetConsoleProcessList(list: *mut u32, count: u32) -> u32;
    }
    let mut list = [0u32; 4];
    unsafe { GetConsoleProcessList(list.as_mut_ptr(), list.len() as u32) }
}

/// Ouvre le navigateur par défaut au démarrage (utile en lancement direct du
/// fichier .exe sous Windows).
fn open_browser(url: &str) {
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        const CREATE_NO_WINDOW: u32 = 0x0800_0000;
        let _ = std::process::Command::new("cmd")
            .args(["/C", "start", "", url])
            .creation_flags(CREATE_NO_WINDOW)
            .spawn();
    }
    #[cfg(not(windows))]
    {
        let _ = url;
    }
}

/// Ouvre le port d'écoute, en tenant compte d'une instance précédente.
///
/// Une instance qui travaille déjà est laissée en place : la nouvelle se
/// contente de ramener l'utilisateur sur son interface. Une instance héritée,
/// qui n'a plus de navigateur (fermeture en cours après la fermeture de
/// l'onglet), est priée de s'arrêter pour que le port soit repris : relancer
/// `CompoZIT.exe` juste après avoir fermé l'onglet fonctionne donc sans
/// attendre. `None` quand le port appartient à un autre programme.
async fn bind_server(
    address: &str,
    port: u16,
    url: &str,
    local_use: bool,
) -> Option<tokio::net::TcpListener> {
    match tokio::net::TcpListener::bind(address).await {
        Ok(listener) => Some(listener),
        Err(error) if error.kind() == std::io::ErrorKind::AddrInUse && local_use => {
            match running_instance_state(port).await {
                Some(true) => {
                    println!("CompoZ'IT travaille déjà : ouverture de {url}");
                    open_browser(url);
                    std::process::exit(0);
                }
                Some(false) => stop_running_instance(port).await,
                None => return None,
            }
            // L'instance précédente se ferme : le port se libère en général
            // dans la seconde.
            for _ in 0..24 {
                tokio::time::sleep(std::time::Duration::from_millis(500)).await;
                if let Ok(listener) = tokio::net::TcpListener::bind(address).await {
                    return Some(listener);
                }
            }
            None
        }
        Err(error) => {
            println!("Impossible d'ouvrir {address} : {error}");
            None
        }
    }
}

/// Interroge ce qui occupe le port : `Some(true)` quand une instance de
/// CompoZ'IT y affiche des pages, `Some(false)` quand elle n'a plus de
/// navigateur, `None` quand ce n'est pas elle.
async fn running_instance_state(port: u16) -> Option<bool> {
    let client = reqwest::Client::builder()
        .timeout(std::time::Duration::from_millis(1500))
        .build()
        .ok()?;
    let body = client
        .get(format!("http://127.0.0.1:{port}/__status"))
        .send()
        .await
        .ok()?
        .text()
        .await
        .ok()?;
    match body.trim() {
        "browser" => Some(true),
        "idle" => Some(false),
        _ => None,
    }
}

/// Demande à l'instance héritée de se fermer, pour reprendre son port.
async fn stop_running_instance(port: u16) {
    let Ok(client) = reqwest::Client::builder()
        .timeout(std::time::Duration::from_millis(1500))
        .build()
    else {
        return;
    };
    let _ = client
        .post(format!("http://127.0.0.1:{port}/__stop"))
        .send()
        .await;
}
