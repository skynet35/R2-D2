pub mod api_settings;
pub mod backup;
pub mod categories;
pub mod component_form;
pub mod components;
pub mod enrich;
pub mod images;
pub mod locations;
pub mod manufacturers;
pub mod marking;
pub mod project_detail;
pub mod projects;
pub mod sheet;
pub mod suppliers;
pub mod transfer;

use axum::extract::State;
use axum::http::StatusCode;
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::Form;
use serde::Deserialize;
use serde_json::{json, Map, Value};
use tower_sessions::Session;

use crate::config::{DatabaseConfig, SiteConfig};
use crate::db;
use crate::error::{AppError, AppResult};
use crate::session::{CurrentUser, SessionUser, SESSION_KEY};
use crate::site;
use crate::state::{self, AppState};
use crate::templates;

/// Contexte de base des pages protégées (identité, bandeau, état de la base).
pub async fn page_context(
    state: &AppState,
    user: &CurrentUser,
    active_nav: &str,
    title: &str,
    subtitle: &str,
) -> Map<String, Value> {
    let mut context = user.default_context(active_nav);
    context.insert("page_title".to_string(), json!(title));
    context.insert("page_subtitle".to_string(), json!(subtitle));
    context.insert("connected".to_string(), json!(state.pool().await.is_some()));
    context.insert("db_label".to_string(), json!(state.database_label().await));
    context
}

pub async fn index(State(state): State<AppState>) -> Redirect {
    if state.pool().await.is_some() {
        Redirect::to("/components")
    } else {
        Redirect::to("/setup")
    }
}

// ------------------------------------------------------------------ Connexion

/// Écran de connexion commun aux deux modes. Le champ « mode » désigne le
/// formulaire envoyé : « sql » pour la base MySQL, « site » pour le site en
/// ligne. Les autres champs sont donc facultatifs.
#[derive(Deserialize)]
pub struct SetupForm {
    #[serde(default)]
    pub mode: String,
    #[serde(default)]
    pub host: String,
    #[serde(default)]
    pub port: String,
    #[serde(default)]
    pub user: String,
    #[serde(default)]
    pub password: String,
    #[serde(default)]
    pub database: String,
    #[serde(default)]
    pub site_url: String,
    #[serde(default)]
    pub site_login_path: String,
    #[serde(default)]
    pub site_username: String,
    #[serde(default)]
    pub site_password: String,
}

pub async fn setup_form(State(state): State<AppState>) -> AppResult<Html<String>> {
    let database = state.database_config().await;
    let site = state.site_config().await;
    render_setup(&state, &database, &site, "", None).await
}

pub async fn setup_submit(
    State(state): State<AppState>,
    Form(form): Form<SetupForm>,
) -> AppResult<Response> {
    if form.mode.trim() == "site" {
        setup_site(&state, &form).await
    } else {
        setup_sql(&state, &form).await
    }
}

/// Mode « base de données MySQL » : le comportement historique.
async fn setup_sql(state: &AppState, form: &SetupForm) -> AppResult<Response> {
    let database = DatabaseConfig {
        host: form.host.trim().to_string(),
        port: form.port.trim().parse::<u16>().unwrap_or(0),
        user: form.user.trim().to_string(),
        password: form.password.clone(),
        database: form.database.trim().to_string(),
    };

    if database.host.is_empty() || database.database.is_empty() || database.user.is_empty() {
        return failure(state, &database, "Hôte, utilisateur et nom de base sont obligatoires.").await;
    }
    if database.port == 0 {
        return failure(state, &database, "Le port doit être un nombre compris entre 1 et 65535.").await;
    }

    match db::connect(&database).await {
        Ok(pool) => {
            tracing::info!("Base pointée sur {}", database.label());
            let was_site = state.site_mode().await;
            state.apply_database(database, pool).await?;
            if was_site {
                state.disable_site().await?;
                return restart_required(
                    state,
                    "Mode « base de données MySQL » enregistré.",
                )
                .await;
            }
            Ok(Redirect::to("/components").into_response())
        }
        Err(error) => failure(state, &database, &format!("Connexion impossible : {error}")).await,
    }
}

/// Mode « site en ligne » : l'adresse du site est mise à l'épreuve tout de
/// suite, avec les identifiants fournis s'il y en a.
async fn setup_site(state: &AppState, form: &SetupForm) -> AppResult<Response> {
    let mut site = SiteConfig {
        enabled: true,
        url: form.site_url.trim().trim_end_matches('/').to_string(),
        login_path: form
            .site_login_path
            .trim()
            .trim_start_matches('/')
            .to_string(),
        username: form.site_username.trim().to_string(),
        password: form.site_password.clone(),
    };

    let client = match site::SiteClient::build(&site) {
        Ok(client) => client,
        Err(message) => return site_failure(state, &site, &message).await,
    };

    match client.login().await {
        Ok(site::Login::Ok(path)) => site.login_path = path,
        Ok(site::Login::Skipped) => {}
        Ok(site::Login::Refused) => {
            return site_failure(
                state,
                &site,
                "Le site a refusé cet identifiant ou ce mot de passe.",
            )
            .await;
        }
        Err(message) => return site_failure(state, &site, &message).await,
    }

    let was_site = state.site_mode().await;
    tracing::info!("Mode site pointé sur {}", site.label());
    state.enable_site(site, client).await?;

    if was_site {
        Ok(Redirect::to("/").into_response())
    } else {
        restart_required(state, "Mode « site en ligne » enregistré.").await
    }
}

async fn failure(state: &AppState, database: &DatabaseConfig, message: &str) -> AppResult<Response> {
    let site = state.site_config().await;
    Ok(
        render_setup(state, database, &site, "sql", Some(message.to_string()))
            .await?
            .into_response(),
    )
}

async fn site_failure(state: &AppState, site: &SiteConfig, message: &str) -> AppResult<Response> {
    let database = state.database_config().await;
    Ok(
        render_setup(state, &database, site, "site", Some(message.to_string()))
            .await?
            .into_response(),
    )
}

/// `submitted` designe l'onglet d'ou viennent les valeurs, pour que le retour
/// d'erreur ramene au bon formulaire ; vide, c'est le mode actif qui decide.
async fn render_setup(
    state: &AppState,
    database: &DatabaseConfig,
    site: &SiteConfig,
    submitted: &str,
    error: Option<String>,
) -> AppResult<Html<String>> {
    let site_mode = state.site_mode().await;
    let active_mode = match submitted {
        "sql" => "sql",
        "site" => "site",
        _ if site_mode => "site",
        _ => "sql",
    };
    let context = json!({
        "page_title": "Base de données",
        "db": database,
        "db_label": database.label(),
        "connected": state.pool().await.is_some(),
        "site": site,
        "site_label": site.label(),
        "site_mode": site_mode,
        "active_mode": active_mode,
        "config_path": state.config_path.display().to_string(),
        "error": error,
    });
    templates::render(&state.templates, "setup.html", context)
}

/// Le mode de connexion est fixé au démarrage : changer de mode arrête donc
/// l'application, à charge pour l'utilisateur de la relancer.
async fn restart_required(state: &AppState, message: &str) -> AppResult<Response> {
    state.request_shutdown();
    let context = json!({
        "page_title": "Application arrêtée",
        "reason": format!(
            "{message} L'application se ferme : relancez CompoZIT.exe pour travailler dans ce mode."
        ),
    });
    Ok(templates::render(&state.templates, "quit.html", context)?.into_response())
}

// ------------------------------------------------------------------------ Session

#[derive(Deserialize)]
pub struct LoginForm {
    pub email: String,
    pub password: String,
}

pub async fn login_form(State(state): State<AppState>, session: Session) -> AppResult<Response> {
    if state.pool().await.is_none() {
        return Ok(Redirect::to("/setup").into_response());
    }
    if session
        .get::<SessionUser>(SESSION_KEY)
        .await
        .ok()
        .flatten()
        .is_some()
    {
        return Ok(Redirect::to("/components").into_response());
    }
    Ok(render_login(&state, "", None).await?.into_response())
}

pub async fn login_submit(
    State(state): State<AppState>,
    session: Session,
    Form(form): Form<LoginForm>,
) -> AppResult<Response> {
    let pool = state.require_pool().await?;
    let email = form.email.trim();

    // Les mots de passe sont ceux de l'application PHP : des empreintes bcrypt
    // $2y$ vérifiables telles quelles.
    let account = sqlx::query_as::<_, (i64, String, String, String, i8)>(
        "SELECT id, email, password, role, active FROM users WHERE email = ?",
    )
    .bind(email)
    .fetch_optional(&pool)
    .await?;

    let Some((id, stored_email, hash, role, active)) = account else {
        return invalid_credentials(&state, email).await;
    };

    if active == 0 || !bcrypt::verify(&form.password, &hash).unwrap_or(false) {
        return invalid_credentials(&state, email).await;
    }

    let user = SessionUser {
        id,
        email: stored_email,
        role,
    };
    session
        .insert(SESSION_KEY, &user)
        .await
        .map_err(|error| AppError::Message(format!("Ouverture de session impossible : {error}")))?;

    tracing::info!("Connexion de {} (id {})", user.email, user.id);
    Ok(Redirect::to("/components").into_response())
}

async fn invalid_credentials(state: &AppState, email: &str) -> AppResult<Response> {
    tracing::warn!("Échec de connexion pour {email}");
    Ok(render_login(state, email, Some("Adresse e-mail ou mot de passe incorrect.".to_string()))
        .await?
        .into_response())
}

pub async fn logout(session: Session) -> Redirect {
    let _ = session.flush().await;
    Redirect::to("/login")
}

/// Arrêt de l'application demandé depuis l'interface : la réponse part avant
/// que le serveur ne se ferme, pour que le navigateur affiche la confirmation.
pub async fn quit(State(state): State<AppState>, _user: CurrentUser) -> AppResult<Html<String>> {
    tracing::info!("Arrêt demandé depuis l'interface.");
    state.request_shutdown();
    let context = json!({ "page_title": "Application arrêtée", "reason": null });
    templates::render(&state.templates, "quit.html", context)
}

/// Même arrêt, mais depuis le mode « site en ligne » : il n'y a alors aucune
/// session locale, et cette page n'est atteignable que depuis cette machine.
pub async fn quit_local(State(state): State<AppState>) -> AppResult<Html<String>> {
    tracing::info!("Arrêt demandé depuis le mode site.");
    state.request_shutdown();
    let context = json!({ "page_title": "Application arrêtée", "reason": null });
    templates::render(&state.templates, "quit.html", context)
}

/// Signe de vie des pages affichées : tant qu'une page répond, l'application
/// reste ouverte. Aucune authentification n'est requise, ces appels ne
/// transportent aucune donnée.
pub async fn alive() -> StatusCode {
    state::browser_alive();
    StatusCode::NO_CONTENT
}

/// Une page vient de disparaître (onglet ou navigateur fermé) : l'application
/// laisse un court délai à une éventuelle navigation, puis s'arrête si plus
/// aucune page ne se signale.
pub async fn closing() -> StatusCode {
    state::browser_page_closed();
    StatusCode::NO_CONTENT
}

/// État de l'instance, interrogé au démarrage par une éventuelle seconde
/// instance : « browser » quand des pages sont affichées, « idle » quand plus
/// aucune ne l'est. Toute autre réponse signifie qu'un autre programme occupe
/// le port.
pub async fn status() -> &'static str {
    if state::browser_attached() {
        "browser"
    } else {
        "idle"
    }
}

/// Arrêt immédiat demandé par une nouvelle instance : celle-ci reprend le port
/// laissé par une instance héritée, qui n'a plus de navigateur depuis peu.
pub async fn stop(State(state): State<AppState>) -> StatusCode {
    tracing::info!("Arrêt demandé par une nouvelle instance : le port est repris.");
    state.request_shutdown();
    StatusCode::NO_CONTENT
}

async fn render_login(state: &AppState, email: &str, error: Option<String>) -> AppResult<Html<String>> {
    let context = json!({
        "page_title": "Connexion",
        "email": email,
        "error": error,
        "db_label": state.database_label().await,
    });
    templates::render(&state.templates, "login.html", context)
}
