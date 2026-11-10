//! Mode « site en ligne ».
//!
//! L'application ne joint plus la base MySQL directement : elle relaie les
//! requetes vers l'interface du site et garde la session ouverte de son cote.
//! Tout ce que le site sait faire reste donc accessible — creation,
//! modification, suppression, envoi de fichiers — sans reecrire un seul ecran.

use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;

use axum::body::Body;
use axum::extract::State;
use axum::http::{header, HeaderMap, HeaderValue, Request, StatusCode};
use axum::response::{Html, IntoResponse, Redirect, Response};
use tokio::sync::Mutex;

use crate::config::SiteConfig;
use crate::error::escape_html;
use crate::state::AppState;

/// Taille maximale du corps relaie : elle doit couvrir les envois de fichiers
/// (images de composants, pieces jointes de projets, dumps SQL).
const MAX_BODY: usize = 64 * 1024 * 1024;

/// Pages de connexion essayees quand le site n'en a pas encore declare une.
/// Les deux deploiements de CompoZ'IT sont couverts : le site PHP mutualise
/// (« auth.php ») et l'instance Rust en ligne (« login »).
const LOGIN_CANDIDATES: [&str; 2] = ["auth.php", "login"];

/// Resultat d'une tentative de connexion au site.
pub enum Login {
    /// Connexion acceptee par cette page.
    Ok(String),
    /// Aucun identifiant fourni : le site affichera sa propre page de connexion.
    Skipped,
    /// Le site a refuse l'identifiant ou le mot de passe.
    Refused,
}

/// Petite reserve a cookies : le site n'en pose qu'un seul (sa session), il
/// suffit donc de retenir les couples nom / valeur et de les renvoyer.
#[derive(Default)]
struct Jar {
    values: HashMap<String, String>,
}

impl Jar {
    fn header_value(&self) -> Option<String> {
        if self.values.is_empty() {
            return None;
        }
        let mut parts: Vec<String> = self
            .values
            .iter()
            .map(|(name, value)| format!("{name}={value}"))
            .collect();
        parts.sort();
        Some(parts.join("; "))
    }

    /// Retient les cookies d'une reponse ; une valeur vide ou perimee efface
    /// le cookie, comme le veut la norme.
    fn absorb(&mut self, response: &reqwest::Response) {
        for raw in response.headers().get_all(header::SET_COOKIE).iter() {
            let Ok(text) = raw.to_str() else {
                continue;
            };
            let (pair, attributes) = match text.split_once(';') {
                Some((pair, attributes)) => (pair, attributes),
                None => (text, ""),
            };
            let Some((name, value)) = pair.split_once('=') else {
                continue;
            };
            let name = name.trim().to_string();
            if name.is_empty() {
                continue;
            }
            let value = value.trim().to_string();
            let attributes = attributes.to_ascii_lowercase();
            let expired = attributes.contains("max-age=0")
                || attributes.contains("expires=thu, 01 jan 1970")
                || attributes.contains("expires=thu, 1 jan 1970");
            if value.is_empty() || expired {
                self.values.remove(&name);
            } else {
                self.values.insert(name, value);
            }
        }
    }
}

/// Client du site : adresse, session et relais des requetes.
pub struct SiteClient {
    /// Adresse du site sans slash final, chemin de base compris.
    base: String,
    /// Origine seule (« https://exemple.fr »), sans chemin : elle sert a
    /// reconnaitre les redirections internes au site.
    origin: String,
    /// Chemin de base du site, vide quand il occupe la racine du domaine.
    prefix: String,
    /// Page de connexion retenue lors de la validation.
    login_path: String,
    username: String,
    password: String,
    http: reqwest::Client,
    jar: Mutex<Jar>,
}

impl SiteClient {
    pub fn build(config: &SiteConfig) -> Result<Arc<SiteClient>, String> {
        let base = config.url.trim().trim_end_matches('/').to_string();
        if base.is_empty() {
            return Err("Indiquez l'adresse du site (par exemple https://mon-site.fr).".to_string());
        }
        let parsed = reqwest::Url::parse(&base).map_err(|_| {
            format!("Adresse invalide : « {base} ». Attendu par exemple https://mon-site.fr")
        })?;
        if !matches!(parsed.scheme(), "http" | "https") {
            return Err("L'adresse doit commencer par http:// ou https://.".to_string());
        }
        let Some(host) = parsed.host_str() else {
            return Err(format!("Adresse invalide : « {base} » ne désigne aucun serveur."));
        };

        let mut origin = format!("{}://{host}", parsed.scheme());
        if let Some(port) = parsed.port() {
            origin.push(':');
            origin.push_str(&port.to_string());
        }

        let http = reqwest::Client::builder()
            // Les redirections sont relayees au navigateur plutôt que suivies :
            // la session reste ainsi attachee à la même adresse locale.
            .redirect(reqwest::redirect::Policy::none())
            .timeout(Duration::from_secs(30))
            .build()
            .map_err(|error| format!("Client HTTP indisponible : {error}"))?;

        Ok(Arc::new(SiteClient {
            base,
            origin,
            prefix: parsed.path().trim_end_matches('/').to_string(),
            login_path: config.login_path.trim().trim_start_matches('/').to_string(),
            username: config.username.trim().to_string(),
            password: config.password.clone(),
            http,
            jar: Mutex::new(Jar::default()),
        }))
    }

    pub fn base(&self) -> &str {
        &self.base
    }

    /// Verifie que le site repond, puis s'y connecte si un identifiant est
    /// fourni. Une erreur signale un site injoignable ; « Refused » signifie
    /// que le site a repondu mais refuse les identifiants.
    pub async fn login(&self) -> Result<Login, String> {
        self.probe().await?;

        if self.username.is_empty() {
            return Ok(Login::Skipped);
        }

        if !self.login_path.is_empty() {
            let path = self.login_path.clone();
            return if self.try_login(&path).await? {
                Ok(Login::Ok(path))
            } else {
                Ok(Login::Refused)
            };
        }

        for path in LOGIN_CANDIDATES {
            if self.try_login(path).await? {
                return Ok(Login::Ok(path.to_string()));
            }
        }
        Ok(Login::Refused)
    }

    /// Relaie une requete du navigateur vers le site.
    pub async fn forward(&self, request: Request<Body>) -> Result<Response, String> {
        let (parts, body) = request.into_parts();
        let target = self.url(parts.uri.path_and_query().map(|value| value.as_str()).unwrap_or("/"));

        let payload = axum::body::to_bytes(body, MAX_BODY)
            .await
            .map_err(|error| format!("Envoi illisible : {error}"))?;

        let mut outbound = self.http.request(parts.method, target);
        outbound = outbound.headers(self.request_headers(&parts.headers).await);
        if !payload.is_empty() {
            outbound = outbound.body(payload.to_vec());
        }

        let response = outbound
            .send()
            .await
            .map_err(|error| self.unreachable(&error))?;

        // Les cookies de session sont retenus ici : le navigateur ne voit que
        // l'adresse locale et n'a jamais a connaitre ceux du site.
        self.jar.lock().await.absorb(&response);
        self.relay(response).await
    }

    fn url(&self, path: &str) -> String {
        if path.starts_with('/') {
            format!("{}{}", self.base, path)
        } else {
            format!("{}/{}", self.base, path)
        }
    }

    fn unreachable(&self, error: &reqwest::Error) -> String {
        format!("Le site {} est injoignable : {error}", self.base)
    }

    /// Un simple appel sur la racine : il distingue « site en panne » de
    /// « identifiants refuses », deux situations qui n'appellent pas la meme
    /// correction.
    async fn probe(&self) -> Result<(), String> {
        let mut request = self.http.get(self.url("/"));
        if let Some(cookie) = self.jar.lock().await.header_value() {
            if let Ok(value) = HeaderValue::try_from(cookie.as_str()) {
                request = request.header(header::COOKIE, value);
            }
        }
        let response = request
            .send()
            .await
            .map_err(|error| self.unreachable(&error))?;
        self.jar.lock().await.absorb(&response);
        Ok(())
    }

    async fn try_login(&self, path: &str) -> Result<bool, String> {
        let mut request = self
            .http
            .post(self.url(&format!("/{path}")))
            .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
            .header(
                header::ACCEPT,
                "text/html,application/json;q=0.9,*/*;q=0.8",
            );
        if let Some(cookie) = self.jar.lock().await.header_value() {
            if let Ok(value) = HeaderValue::try_from(cookie.as_str()) {
                request = request.header(header::COOKIE, value);
            }
        }

        // Les deux sites attendent « email » et « password » ; le champ
        // « action » est ignore par celui qui ne le connait pas.
        let body = format!(
            "action=login&email={}&password={}",
            encode(&self.username),
            encode(&self.password)
        );

        let response = request
            .body(body)
            .send()
            .await
            .map_err(|error| self.unreachable(&error))?;

        let redirected = response.status().is_redirection();
        let location = response
            .headers()
            .get(header::LOCATION)
            .and_then(|value| value.to_str().ok())
            .unwrap_or_default()
            .to_ascii_lowercase();
        self.jar.lock().await.absorb(&response);

        // Un site qui accepte renvoie vers l'application ; un site qui refuse
        // renvoie vers sa page de connexion avec un message d'erreur.
        Ok(redirected && !location.contains("error="))
    }

    async fn request_headers(&self, source: &HeaderMap) -> HeaderMap {
        let mut headers = HeaderMap::new();
        for (name, value) in source.iter() {
            // Ces en-tetes decrivent la connexion locale (ou la session) et
            // n'ont pas de sens pour le site distant.
            match name.as_str() {
                "host" | "connection" | "content-length" | "accept-encoding" | "cookie" | "upgrade"
                | "te" | "transfer-encoding" | "origin" | "referer" => continue,
                _ => {}
            }
            headers.insert(name.clone(), value.clone());
        }

        // Les reponses sont relayees telles quelles : autant demander au site
        // de ne pas compresser, l'application ne decompresse pas.
        headers.insert(
            header::ACCEPT_ENCODING,
            HeaderValue::from_static("identity"),
        );
        if let Ok(value) = HeaderValue::try_from(self.origin.as_str()) {
            headers.insert(header::ORIGIN, value);
        }
        if let Some(cookie) = self.jar.lock().await.header_value() {
            if let Ok(value) = HeaderValue::try_from(cookie.as_str()) {
                headers.insert(header::COOKIE, value);
            }
        }
        headers
    }

    async fn relay(&self, response: reqwest::Response) -> Result<Response, String> {
        let status = response.status();
        let content_type = response
            .headers()
            .get(header::CONTENT_TYPE)
            .and_then(|value| value.to_str().ok())
            .unwrap_or_default()
            .to_ascii_lowercase();

        let mut headers = HeaderMap::new();
        for (name, value) in response.headers().iter() {
            match name.as_str() {
                // Taille et encodage sont recalcules sur le corps relaye ; les
                // cookies restent dans la session cote application.
                "content-length" | "transfer-encoding" | "content-encoding" | "connection"
                | "set-cookie" | "upgrade" | "te" => continue,
                // En-tetes du site distant qui n'ont pas de sens en local :
                // « upgrade-insecure-requests » et « strict-transport-security »
                // forceraient le navigateur a passer en https sur 127.0.0.1 ;
                // « x-frame-options » empecherait l'affichage dans un apercu
                // encadre ; le reste provient de Cloudflare et de LiteSpeed et
                // ne concerne que la production.
                "content-security-policy" | "content-security-policy-report-only"
                | "strict-transport-security" | "x-frame-options" | "alt-svc"
                | "report-to" | "nel" | "expect-ct" | "x-turbo-charged-by"
                | "cf-cache-status" | "cf-ray" => continue,
                "location" => {
                    if let Ok(text) = value.to_str() {
                        if let Ok(rewritten) = HeaderValue::try_from(self.rewrite_location(text).as_str())
                        {
                            headers.insert(header::LOCATION, rewritten);
                        }
                    }
                    continue;
                }
                _ => {}
            }
            headers.insert(name.clone(), value.clone());
        }

        let payload = response
            .bytes()
            .await
            .map_err(|error| format!("Réponse illisible du site : {error}"))?;
        let payload = if content_type.starts_with("text/html") {
            inject_quit_button(&payload)
        } else {
            payload.to_vec()
        };

        let mut out = Response::new(Body::from(payload));
        *out.status_mut() = status;
        *out.headers_mut() = headers;
        Ok(out)
    }

    /// Ramene une redirection du site a son equivalent local, pour que le
    /// navigateur reste sur l'adresse de l'application.
    fn rewrite_location(&self, location: &str) -> String {
        if let Some(rest) = location.strip_prefix(self.origin.as_str()) {
            let rest = self.strip_site_path(rest);
            return if rest.is_empty() {
                "/".to_string()
            } else {
                rest
            };
        }
        if location.starts_with('/') {
            return self.strip_site_path(location);
        }
        location.to_string()
    }

    fn strip_site_path(&self, path: &str) -> String {
        if self.prefix.is_empty() {
            return path.to_string();
        }
        match path.strip_prefix(self.prefix.as_str()) {
            Some("") => "/".to_string(),
            Some(rest) if rest.starts_with('/') => rest.to_string(),
            _ => path.to_string(),
        }
    }
}

/// Relaie toute requete vers le site : c'est le point d'entree du mode
/// « site en ligne ». Les ecrans locaux — reglages et arret — restent
/// prioritaires, car ils sont declares avant ce repli.
pub async fn proxy(State(state): State<AppState>, request: Request<Body>) -> Response {
    let Some(client) = state.site_client().await else {
        return Redirect::to("/setup").into_response();
    };
    match client.forward(request).await {
        Ok(response) => response,
        Err(error) => {
            tracing::warn!("{error}");
            unreachable_page(&error)
        }
    }
}

/// Page affichee quand le site ne repond pas : dans ce cas elle est le seul
/// ecran accessible, elle doit donc indiquer quoi corriger.
fn unreachable_page(message: &str) -> Response {
    let body = format!(
        "<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"utf-8\">\
         <title>Site injoignable — CompoZ'IT</title></head>\
         <body style=\"font-family:'Segoe UI',sans-serif;background:#0b1220;color:#e8eefb;\
         display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0\">\
         <div style=\"max-width:620px;padding:32px;border:1px solid #1f2b46;border-radius:14px;background:#0f1729\">\
         <h1 style=\"font-size:19px;margin:0 0 10px\">Le site en ligne ne répond pas</h1>\
         <p style=\"color:#93a4c0;font-size:14px\">{}</p>\
         <p style=\"color:#93a4c0;font-size:14px\">Vérifiez la connexion internet, puis l'adresse \
         du site dans l'écran « Base de données ».</p>\
         <p><a style=\"color:#60a5fa\" href=\"/setup\">Ouvrir les réglages</a> &nbsp;&middot;&nbsp; \
         <a style=\"color:#60a5fa\" href=\"/\">Réessayer</a></p>\
         </div></body></html>",
        escape_html(message)
    );
    (StatusCode::BAD_GATEWAY, Html(body)).into_response()
}

/// Ajoute un bouton « Quitter » discret aux pages du site : en mode site,
/// l'application n'affiche aucune fenetre, il faut un moyen visible pour
/// l'arreter autrement qu'en passant par le gestionnaire de taches. Le lien
/// vers les reglages accompagne ce bouton, sans quoi le seul moyen de revenir
/// au mode « base de donnees » serait de taper l'adresse a la main.
fn inject_quit_button(body: &[u8]) -> Vec<u8> {
    const SITE_THEME: &str = r#"<style id="cz-site-theme">
html[data-theme="dark"]{color-scheme:dark}
/* Contraste eleve : texte proche du blanc sur fond sombre. Les variables du
   site sont redefinies ici, ce qui couvre d'un coup toutes les pages qui les
   utilisent (la plupart des ecrans heritent de la palette claire d'origine). */
body.cz-relay-modern{--bg-primary:#08111f!important;--bg-secondary:#0b1628!important;--bg-card:#0d182c!important;--bg-muted:#13213a!important;--bg-subtle:#101a2d!important;--text-primary:#e8eefb!important;--text-secondary:#c3cee2!important;--text-muted:#91a0bc!important;--border-color:#1b2a44!important;--border-light:#162238!important;--border-strong:#243858!important;--border-muted:#162238!important;--field-bg:#0d182c!important;--field-border:#243858!important;--accent-indigo-light:#162742!important;--shadow-sm:none!important;--shadow-md:none!important;--shadow-lg:none!important;--light-color:#101a2d!important;--dark-color:#e8eefb!important;--text-color:#e8eefb!important;--primary-color:#7f95bb!important;--secondary-color:#5d6f8e!important;--success-color:#2f8f68!important;--warning-color:#9e7b33!important;--danger-color:#a05b66!important;--accent-indigo:#7f95bb!important}
body.cz-relay-modern{margin:0!important;background:#070b14!important;color:#e8eefb!important;font:15px/1.5 "Segoe UI",system-ui,sans-serif!important}
body.cz-relay-modern>.container{max-width:none!important;margin:0!important;padding:0!important;display:flex!important;min-height:100vh!important;background:#070b14!important}
body.cz-relay-modern .cz-sidebar{width:258px;flex:none;display:flex;flex-direction:column;gap:20px;padding:22px 16px;background:#0b1220;border-right:1px solid #1f2b46}
body.cz-relay-modern .cz-brand{display:flex;align-items:center;gap:12px;padding:0 6px}
body.cz-relay-modern .cz-brand-mark{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:#5f79b8;color:#eef4ff;font-weight:700;font-size:19px;box-shadow:none;border:1px solid #7d95c8}
body.cz-relay-modern .cz-brand-text{display:flex;flex-direction:column;font-weight:700;font-size:16px;line-height:1.15}
body.cz-relay-modern .cz-brand-text em{font-style:normal;color:#cfd8ec}
body.cz-relay-modern .cz-brand-text small{font-weight:500;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#93a4c0;margin-top:3px}
body.cz-relay-modern .cz-nav{display:flex;flex-direction:column;gap:3px}
body.cz-relay-modern .cz-nav-label{margin:14px 10px 6px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#93a4c0}
body.cz-relay-modern .cz-nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:#e8eefb;text-decoration:none;font-weight:500;transition:background .12s ease,color .12s ease}
body.cz-relay-modern .cz-nav-item:hover{background:#1b2844;text-decoration:none}
body.cz-relay-modern .cz-nav-item.is-active{background:#14284b;color:#dfe8fb;font-weight:650;border:1px solid #203557}
body.cz-relay-modern .cz-nav-icon{width:18px;text-align:center;flex:none;opacity:.9}
body.cz-relay-modern .cz-sidebar-footer{margin-top:auto;padding:12px;border-radius:12px;background:#16213a;font-size:12px;color:#93a4c0}
body.cz-relay-modern .cz-status-line{display:flex;align-items:center;gap:8px;font-weight:600;color:#e8eefb}
body.cz-relay-modern .cz-dot{width:9px;height:9px;border-radius:50%;background:#4aa37f;box-shadow:0 0 0 3px rgba(74,163,127,.16);flex:none}
body.cz-relay-modern .cz-main{flex:1;min-width:0;display:flex;flex-direction:column}
body.cz-relay-modern .cz-topbar{display:flex;align-items:center;gap:14px;padding:16px 28px;background:rgba(15,23,41,.88);backdrop-filter:blur(8px);border-bottom:1px solid #1f2b46;position:sticky;top:0;z-index:20}
body.cz-relay-modern .cz-topbar h1{margin:0;font-size:19px;font-weight:650;letter-spacing:-.01em;color:#e8eefb}
body.cz-relay-modern .cz-subtitle{font-size:13px;color:#93a4c0;margin-top:2px}
body.cz-relay-modern .cz-topbar-actions{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
body.cz-relay-modern .cz-icon-btn{width:38px;height:38px;display:grid;place-items:center;border:1px solid #1f2b46;border-radius:8px;background:#0f1729;color:#e8eefb;cursor:pointer;font-size:16px}
body.cz-relay-modern .user-chip{display:flex;align-items:center;gap:9px;padding:5px 12px 5px 5px;border-radius:999px;background:#16213a!important;border:1px solid #1f2b46!important;font-size:13px;color:#e8eefb!important}
body.cz-relay-modern .user-chip a{color:#e8eefb}
body.cz-relay-modern .cz-user-avatar{width:28px;height:28px;display:grid;place-items:center;border-radius:50%;background:#6d84aa;color:#eef4ff;font-weight:700;font-size:13px;flex:none}
body.cz-relay-modern .cz-user-meta{display:flex;flex-direction:column;line-height:1.2}
body.cz-relay-modern .cz-user-role{color:#93a4c0;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
body.cz-relay-modern .logout-link,body.cz-relay-modern .cz-ghost-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border:1px solid #1f2b46!important;border-radius:8px;background:#0f1729!important;color:#e8eefb!important;font-size:14px;font-weight:600;text-decoration:none;box-shadow:none!important}
body.cz-relay-modern .logout-link:hover,body.cz-relay-modern .cz-ghost-btn:hover{background:#1b2844!important;text-decoration:none}
body.cz-relay-modern .cz-danger-btn{border-color:#7f2230!important;background:#2a1218!important;color:#ffb4be!important}
body.cz-relay-modern .cz-danger-btn:hover{background:#3a1a22!important;border-color:#9f2b3c!important}
/* Reglages de l'application : encart rose, place en bas de la page Parametres. */
body.cz-relay-modern .cz-setup-panel{background:#33121d!important;border:1px solid #7f2b45!important}
body.cz-relay-modern .cz-setup-panel h2{color:#ffc2cf!important}
body.cz-relay-modern .cz-setup-panel p{color:#e9b9c5!important}
body.cz-relay-modern .cz-setup-panel .btn{background:#9f2b45!important;color:#fff!important}
body.cz-relay-modern .cz-setup-panel .btn:hover{background:#b8334f!important}
/* Le contenu PHP d'origine est masque des le <head> : sans cela, il
   s'affiche une fraction de seconde avant que le shell moderne ne
   reconstruise le DOM (artefact d'ancienne interface). */
html.cz-prehide body>.container,html.cz-prehide body>.container-fluid{visibility:hidden!important}
body.cz-relay-modern .content,body.cz-relay-modern .content-card,body.cz-relay-modern .main-container,body.cz-relay-modern .row{padding:24px 28px 48px!important;display:flex;flex-direction:column;gap:20px;background:transparent!important;border-radius:0!important;box-shadow:none!important}
body.cz-relay-modern .content > *{max-width:100%}
body.cz-relay-modern .cz-quick-links{display:flex;gap:10px;flex-wrap:wrap;align-self:center;width:min(1120px,calc(100% - 56px));margin:18px auto 0;padding:0;box-sizing:border-box}
body.cz-relay-modern .cz-quick-links a{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:1px solid #1f2b46;border-radius:999px;background:#0f1729;color:#e8eefb;text-decoration:none;font-size:13px;font-weight:600}
body.cz-relay-modern .cz-quick-links a:hover{background:#1b2844;text-decoration:none}
body.cz-relay-modern footer{margin:0 28px 24px!important;padding:16px 0 0!important;border-top:1px solid #1f2b46!important;background:none!important;color:#93a4c0!important}
/* project_detail.php : une fois relayee dans le shell moderne, la carte
   d'entete du projet doit remonter en haut du contenu. */
body.cz-relay-modern .container>.app-header{margin:24px 28px 0!important;padding:24px 28px!important;background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:16px!important;box-shadow:none!important}
body.cz-relay-modern .header-top{display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:16px!important;margin-bottom:18px!important}
body.cz-relay-modern .header-title{display:flex!important;align-items:flex-start!important;gap:16px!important}
body.cz-relay-modern .header-icon{width:52px;height:52px;display:grid;place-items:center;border-radius:14px;background:#13213a!important;border:1px solid #243858!important;color:#c3cee2!important;font-size:24px!important;box-shadow:none!important}
body.cz-relay-modern .nav-buttons{display:flex!important;gap:10px!important;flex-wrap:wrap!important;margin:0 0 22px!important}
body.cz-relay-modern .nav-buttons.cz-quick-links{align-self:center!important;width:min(1120px,calc(100% - 56px))!important;margin:18px auto 0!important;padding:0!important;box-sizing:border-box!important}
body.cz-relay-modern .nav-buttons a{display:inline-flex!important;align-items:center!important;gap:6px!important;padding:9px 14px!important;border:1px solid #1f2b46!important;border-radius:999px!important;background:#131c30!important;color:#e8eefb!important;text-decoration:none!important;font-size:13px!important;font-weight:600!important;box-shadow:none!important}
body.cz-relay-modern .nav-buttons a:hover{background:#1b2844!important}
body.cz-relay-modern .header-content{padding:0!important;background:transparent!important;border:none!important;box-shadow:none!important}
body.cz-relay-modern .project-meta{display:flex!important;flex-wrap:wrap!important;gap:14px 18px!important}
body.cz-relay-modern .project-meta .meta-item{display:inline-flex!important;align-items:center!important;gap:8px!important;background:#131c30!important;border:1px solid #1f2b46!important;border-radius:999px!important;padding:8px 12px!important}
body.cz-relay-modern .container>.main-container{padding-top:20px!important}
body.cz-relay-modern .content>.header-content:first-child{order:-20!important;margin:0 0 4px!important;padding:22px 26px!important;background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:16px!important;box-shadow:none!important}
body.cz-relay-modern .content>.header-content:first-child .project-header{display:grid!important;grid-template-columns:minmax(140px,180px) minmax(0,1fr)!important;align-items:center!important;gap:24px!important}
body.cz-relay-modern .content>.header-content:first-child .project-image-actions{margin-top:14px!important}
body.cz-relay-modern .content>.header-content:first-child .project-info{display:flex!important;flex-direction:column!important;gap:16px!important}
body.cz-relay-modern .content>.header-content:first-child .project-info h1{margin:0!important;font-size:3rem!important;line-height:1.05!important}
body.cz-relay-modern .stats{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px!important}
body.cz-relay-modern .stat-card{background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:12px!important;box-shadow:none!important;text-align:left!important;padding:16px 18px!important}
body.cz-relay-modern .stat-number{color:#e8eefb!important;font-size:2rem!important}
body.cz-relay-modern .stat-label{color:#93a4c0!important;font-size:12px!important;text-transform:uppercase;letter-spacing:.08em}
body.cz-relay-modern .search-section,body.cz-relay-modern .filter-section,body.cz-relay-modern .content>form,body.cz-relay-modern .content>.card,body.cz-relay-modern .content>.table-container{background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:12px!important;box-shadow:none!important}
body.cz-relay-modern .content-card,body.cz-relay-modern .product-card,body.cz-relay-modern .content-container,body.cz-relay-modern .tabs-header,body.cz-relay-modern .overview-section,body.cz-relay-modern .components-section,body.cz-relay-modern .materials-section,body.cz-relay-modern .files-section,body.cz-relay-modern .create-project-section,body.cz-relay-modern .settings-grid,body.cz-relay-modern .setting-card,body.cz-relay-modern .project-card,body.cz-relay-modern .manufacturer-card,body.cz-relay-modern .upload-section{background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:12px!important;box-shadow:none!important}
body.cz-relay-modern .form-container,body.cz-relay-modern .manufacturers-list,body.cz-relay-modern .details,body.cz-relay-modern .warning,body.cz-relay-modern .quick-add-card,body.cz-relay-modern .category-card,body.cz-relay-modern .subcategory-card,body.cz-relay-modern .page-section-title,body.cz-relay-modern .stats-grid > .stat-card,body.cz-relay-modern .project-stats > *,body.cz-relay-modern .status-badges,body.cz-relay-modern .header-content,body.cz-relay-modern .product-header,body.cz-relay-modern .action-section,body.cz-relay-modern .system-info,body.cz-relay-modern .modal-content{background:#0f1729!important;border:1px solid #1f2b46!important;border-radius:12px!important;box-shadow:none!important}
/* Categories : un seul aplat pour tous les en-tetes. Les huit degrades
   d'origine (gradient-a a gradient-h) juraient avec le theme sombre. */
body.cz-relay-modern .category-card-head[class*="gradient-"]{background:#16213a!important;background-image:none!important;color:#e8eefb!important}
/* Filtres de composants : les puces heritent du gris clair du site PHP
   (#495057), trop sombre sur le fond bleu nuit du relais. */
body.cz-relay-modern .category-chip,body.cz-relay-modern .subcategory-chip{background:#13203a!important;background-image:none!important;border:2px solid #c3cee2!important;color:#e8eefb!important;box-shadow:none!important}
body.cz-relay-modern .category-chip *,body.cz-relay-modern .subcategory-chip *{color:#e8eefb!important}
body.cz-relay-modern .category-chip:hover,body.cz-relay-modern .subcategory-chip:hover{background:#1b2844!important;background-image:none!important;border-color:#e8eefb!important;color:#ffffff!important}
body.cz-relay-modern .category-chip.active,body.cz-relay-modern .subcategory-chip.active{background:#314f83!important;background-image:none!important;border-color:#6f89b7!important;color:#ffffff!important;box-shadow:none!important}
body.cz-relay-modern .category-chip.active *,body.cz-relay-modern .subcategory-chip.active *{color:#ffffff!important}
body.cz-relay-modern .category-chip.active:hover,body.cz-relay-modern .subcategory-chip.active:hover{background:#395b95!important;background-image:none!important;border-color:#88a0cb!important;color:#ffffff!important;box-shadow:none!important}
/* Emplacements : le HTML relaye fige des couleurs en dur (bouton en degrade,
   pastilles bleu/vert, chiffres colores). Elles passent toutes a un seul jeu
   de teintes du theme, version sobre. */
body.cz-relay-modern [style*="linear-gradient(135deg,#3b82f6"]{background:#16213a!important;background-image:none!important;border:1px solid #1f2b46!important;color:#e8eefb!important}
body.cz-relay-modern [style*="background:#dbeafe"],body.cz-relay-modern [style*="background:#dcfce7"]{background:#16213a!important;background-image:none!important;border-color:#1f2b46!important;color:#c3cee2!important}
body.cz-relay-modern [style*="color:#94a3b8"],body.cz-relay-modern [style*="color:#2563eb"],body.cz-relay-modern [style*="color:#d97706"],body.cz-relay-modern [style*="color:#16a34a"]{color:#c3cee2!important}
body.cz-relay-modern [style*="color:#475569"]{color:#93a4c0!important}
body.cz-relay-modern table{background:#0f1729!important;color:#e8eefb!important}
body.cz-relay-modern th{background:#16213a!important;color:#93a4c0!important;border-color:#1f2b46!important;text-transform:uppercase;letter-spacing:.06em;font-size:12px}
body.cz-relay-modern td{border-color:#1f2b46!important}
body.cz-relay-modern tr:nth-child(even){background:#0d1527!important}
body.cz-relay-modern tbody tr:hover,body.cz-relay-modern tbody tr:hover td,body.cz-relay-modern .supplier-card:hover,body.cz-relay-modern .project-card:hover,body.cz-relay-modern .manufacturer-card:hover{background:#16213a!important;color:#e8eefb!important}
body.cz-relay-modern input,body.cz-relay-modern select,body.cz-relay-modern textarea{background:#0f1729!important;border:1px solid #2c3b5c!important;color:#e8eefb!important}
body.cz-relay-modern input::placeholder,body.cz-relay-modern textarea::placeholder{color:#93a4c0!important}
body.cz-relay-modern .supplier-card,body.cz-relay-modern .project-card,body.cz-relay-modern .setting-card,body.cz-relay-modern .manufacturer-card{background:#0f1729!important;border:1px solid #1f2b46!important;box-shadow:none!important;color:#e8eefb!important}
body.cz-relay-modern .btn-primary,body.cz-relay-modern .btn-success,body.cz-relay-modern .btn-info,body.cz-relay-modern .btn-secondary{background:#6280b3!important;border:1px solid #7b95bf!important;color:#eef4ff!important}
body.cz-relay-modern .btn-danger{background:#7f1d1d!important;border:none!important;color:#fee2e2!important}
body.cz-relay-modern .btn-warning{background:#4a5c7f!important;border:1px solid #64789d!important;color:#eef4ff!important}
body.cz-relay-modern .btn-ghost,body.cz-relay-modern .btn-custom,body.cz-relay-modern .btn-back,body.cz-relay-modern .btn-edit,body.cz-relay-modern .btn-sm-outline{background:#0f1729!important;border:1px solid #2c3b5c!important;color:#e8eefb!important}
body.cz-relay-modern .success,body.cz-relay-modern .alert-success{background:rgba(22,163,74,.18)!important;border:1px solid rgba(74,222,128,.25)!important;color:#dcfce7!important}
body.cz-relay-modern .error,body.cz-relay-modern .alert-error{background:rgba(220,38,38,.18)!important;border:1px solid rgba(252,165,165,.25)!important;color:#fee2e2!important}
body.cz-relay-modern .alert-warning,body.cz-relay-modern .warning{background:rgba(217,119,6,.18)!important;border:1px solid rgba(252,211,77,.25)!important;color:#fef3c7!important}
body.cz-relay-modern h1,body.cz-relay-modern h2,body.cz-relay-modern h3,body.cz-relay-modern h4,body.cz-relay-modern h5,body.cz-relay-modern h6,body.cz-relay-modern label,body.cz-relay-modern strong,body.cz-relay-modern p,body.cz-relay-modern li,body.cz-relay-modern small,body.cz-relay-modern .page-title,body.cz-relay-modern .page-subtitle,body.cz-relay-modern .project-description,body.cz-relay-modern .project-title,body.cz-relay-modern .setting-title,body.cz-relay-modern .setting-description,body.cz-relay-modern .details,body.cz-relay-modern .hint,body.cz-relay-modern .meta-item,body.cz-relay-modern .stat-sub,body.cz-relay-modern .text-muted,body.cz-relay-modern .info-label,body.cz-relay-modern .info-value,body.cz-relay-modern .system-info,body.cz-relay-modern .product-body,body.cz-relay-modern .product-body *,body.cz-relay-modern .status-chip,body.cz-relay-modern .status-chip *,body.cz-relay-modern .subcat-id-preview,body.cz-relay-modern .project-meta,body.cz-relay-modern .header-content *{color:#e8eefb!important}
body.cz-relay-modern .hint,body.cz-relay-modern .text-muted,body.cz-relay-modern .project-meta small,body.cz-relay-modern .meta-item,body.cz-relay-modern .stat-sub,body.cz-relay-modern .page-subtitle{color:#93a4c0!important}
body.cz-relay-modern .status-chip{display:inline-flex;align-items:center;gap:8px;background:#16213a!important;border:1px solid #1f2b46!important;border-radius:999px;padding:8px 12px}
body.cz-relay-modern .status-dot.on{background:#4aa37f!important;box-shadow:0 0 0 3px rgba(74,163,127,.16)!important}
body.cz-relay-modern .status-dot.off{background:#9b6a74!important;box-shadow:0 0 0 3px rgba(155,106,116,.16)!important}
body.cz-relay-modern .product-header,body.cz-relay-modern .project-header,body.cz-relay-modern .header-content,body.cz-relay-modern .stats-overview,body.cz-relay-modern .create-project-section{color:#e8eefb!important}
body.cz-relay-modern .product-image-placeholder,body.cz-relay-modern .no-image{background:#16213a!important;color:#93a4c0!important;border:1px dashed #2c3b5c!important}
body.cz-relay-modern .project-status,body.cz-relay-modern .badge-smd{background:#162742!important;color:#dbe4f4!important;border:1px solid #2b4267!important}
body.cz-relay-modern .project-image-wrap{cursor:pointer!important}
body.cz-relay-modern .tabs-nav,body.cz-relay-modern .tabs-header{background:#0f1729!important;color:#e8eefb!important}
body.cz-relay-modern .tab-button{background:transparent!important;color:#93a4c0!important;border-color:#1f2b46!important}
body.cz-relay-modern .tab-button.active{background:#16213a!important;color:#e8eefb!important}
/* Detail projet : plusieurs blocs gardent le design clair/gradient du site PHP.
   On les harmonise avec la palette sombre portable. */
body.cz-relay-modern .main-container,body.cz-relay-modern .content-container,body.cz-relay-modern .modern-card,body.cz-relay-modern .modern-form,body.cz-relay-modern .image-modal,body.cz-relay-modern .del-modal,body.cz-relay-modern .rename-project-modal-box{background:#0f1729!important;color:#e8eefb!important;border:1px solid #1f2b46!important;box-shadow:none!important}
body.cz-relay-modern .card-header,body.cz-relay-modern .rename-project-modal-head,body.cz-relay-modern .del-modal-header{border-color:#1f2b46!important}
body.cz-relay-modern .card-title,body.cz-relay-modern .image-modal h3,body.cz-relay-modern .del-modal h3,body.cz-relay-modern .rename-project-modal-head h3,body.cz-relay-modern .project-info h1{color:#e8eefb!important}
body.cz-relay-modern .stat-card{background:#16213a!important;background-image:none!important;border:1px solid #2c3b5c!important;color:#e8eefb!important;box-shadow:none!important}
body.cz-relay-modern .stat-card:hover{background:#1b2844!important;box-shadow:none!important;transform:none!important}
body.cz-relay-modern .stat-content p,body.cz-relay-modern .rename-project-modal-head p,body.cz-relay-modern .del-modal-subtitle,body.cz-relay-modern .del-modal-warnings p{color:#93a4c0!important}
body.cz-relay-modern .progress-bar{background:#131c30!important;border:1px solid #1f2b46!important}
body.cz-relay-modern .progress-fill{background:#5f79b8!important}
body.cz-relay-modern .project-avatar{background:#131c30!important;border:1px solid #2c3b5c!important;color:#c3cee2!important;backdrop-filter:none!important}
body.cz-relay-modern .image-overlay{background:rgba(6,16,33,.78)!important;color:#ffffff!important}
body.cz-relay-modern .btn-sm-outline{background:#131c30!important;border:1px solid #2c3b5c!important;color:#e8eefb!important}
body.cz-relay-modern .btn-sm-outline:hover{background:#16213a!important}
body.cz-relay-modern .btn-sm-danger{background:#7f1d1d!important;border:1px solid #b91c1c!important;color:#fee2e2!important}
body.cz-relay-modern .btn-sm-danger:hover{background:#991b1b!important;filter:none!important}
body.cz-relay-modern .drop-zone{background:#131c30!important;border-color:#2c3b5c!important;color:#e8eefb!important}
body.cz-relay-modern .drop-zone:hover,body.cz-relay-modern .drop-zone.dragover{background:#16213a!important;border-color:#6d84aa!important}
body.cz-relay-modern .drop-zone i,body.cz-relay-modern .drop-zone p b{color:#c3cee2!important}
body.cz-relay-modern .drop-zone p,body.cz-relay-modern .drop-zone p.small{color:#93a4c0!important}
body.cz-relay-modern .modal-btn.cancel{background:#131c30!important;color:#e8eefb!important;border:1px solid #2c3b5c!important}
body.cz-relay-modern .modal-btn.cancel:hover{background:#16213a!important}
body.cz-relay-modern .modal-btn.primary{background:#6280b3!important;background-image:none!important;color:#eef4ff!important}
body.cz-relay-modern .modal-btn.primary:hover{transform:none!important;box-shadow:none!important}
body.cz-relay-modern .del-modal-icon{background:#16213a!important;color:#fca5a5!important}
body.cz-relay-modern [style*="var(--dark-color)"]{color:#e8eefb!important}
body.cz-relay-modern [style*="color:#667eea"]{color:#c3cee2!important}
body.cz-relay-modern [style*="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%)"]{background:#16213a!important;background-image:none!important;border-color:#2c3b5c!important;color:#e8eefb!important}
/* Rattrapage des couleurs figees en dur ou heritees de Bootstrap : sans cela,
   certains blocs restent blancs et le texte fonce devient illisible. */
body.cz-relay-modern .card,body.cz-relay-modern .card-body,body.cz-relay-modern .list-group-item,body.cz-relay-modern .modal-content,body.cz-relay-modern .accordion-item,body.cz-relay-modern .accordion-button,body.cz-relay-modern .dropdown-menu,body.cz-relay-modern .table{background:#0f1729!important;color:#e8eefb!important;border-color:#1f2b46!important;--bs-table-bg:transparent;--bs-table-color:#e8eefb;--bs-table-striped-bg:#131c30;--bs-table-border-color:#1f2b46;--bs-card-bg:#0f1729}
body.cz-relay-modern .accordion-button:not(.collapsed){background:#16213a!important;color:#e8eefb!important}
body.cz-relay-modern .table>thead th,body.cz-relay-modern .table>tbody td,body.cz-relay-modern .table>tbody th{background:transparent!important;color:#e8eefb!important;border-color:#1f2b46!important}
body.cz-relay-modern .form-control,body.cz-relay-modern .form-select,body.cz-relay-modern .input-group-text,body.cz-relay-modern .input-group-text *{background:#0f1729!important;color:#e8eefb!important;border-color:#2c3b5c!important}
/* Le formulaire « Ajouter un composant » utilise la classe .form-input (et non
   .form-control) : sans ce rappel, le texte saisi restait sombre dans le champ
   de recherche, donc illisible sur le fond bleu nuit. */
body.cz-relay-modern .form-input,body.cz-relay-modern .form-textarea,body.cz-relay-modern .form-select{background:#0f1729!important;color:#e8eefb!important;border-color:#2c3b5c!important}
body.cz-relay-modern #component_search{background:#0f1729!important;color:#ffffff!important;border-color:#2c3b5c!important}
body.cz-relay-modern #component_search::placeholder,body.cz-relay-modern .form-input::placeholder{color:#93a4c0!important}
body.cz-relay-modern #component_suggestions{background:#0f1729!important;border:1px solid #2c3b5c!important;color:#e8eefb!important}
body.cz-relay-modern #component_suggestions>div{background:#0f1729!important;color:#e8eefb!important;border-bottom:1px solid #1f2b46!important}
body.cz-relay-modern #component_suggestions>div:hover{background:#16213a!important}
body.cz-relay-modern #component_suggestions>div *{color:#c3cee2!important}
/* Textes figes en gris fonce dans le HTML (listes, resumes, fichiers). */
body.cz-relay-modern [style*="color: #4b5563"],body.cz-relay-modern [style*="color:#4b5563"],body.cz-relay-modern [style*="color: #374151"],body.cz-relay-modern [style*="color:#374151"],body.cz-relay-modern [style*="color: #1f2937"],body.cz-relay-modern [style*="color:#1f2937"],body.cz-relay-modern [style*="color: #6b7280"],body.cz-relay-modern [style*="color:#6b7280"],body.cz-relay-modern [style*="color: #495057"],body.cz-relay-modern [style*="color:#495057"]{color:#c3cee2!important}
body.cz-relay-modern .form-check-input{background-color:#0f1729!important;border-color:#2c3b5c!important}
body.cz-relay-modern .text-dark,body.cz-relay-modern .text-black,body.cz-relay-modern .text-body,body.cz-relay-modern .text-reset{color:#e8eefb!important}
body.cz-relay-modern .bg-white,body.cz-relay-modern .bg-light,body.cz-relay-modern .table-light,body.cz-relay-modern .table-light>th,body.cz-relay-modern .table-light>td,body.cz-relay-modern .list-group,body.cz-relay-modern .list-group-flush{background:#131c30!important;color:#e8eefb!important;border-color:#1f2b46!important}
body.cz-relay-modern .alert-danger,body.cz-relay-modern .alert-error{background:rgba(220,38,38,.18)!important;border-color:rgba(252,165,165,.28)!important;color:#fee2e2!important}
body.cz-relay-modern .alert-success,body.cz-relay-modern .success{background:rgba(22,163,74,.18)!important;border-color:rgba(74,222,128,.28)!important;color:#dcfce7!important}
body.cz-relay-modern .alert-warning,body.cz-relay-modern .alert-info,body.cz-relay-modern .warning{background:rgba(217,119,6,.18)!important;border-color:rgba(252,211,77,.28)!important;color:#fef3c7!important}
body.cz-relay-modern .badge{color:#e8eefb!important}
body.cz-relay-modern .btn-close,body.cz-relay-modern .close{color:#e8eefb!important;filter:invert(1) grayscale(1) brightness(1.7)}
body.cz-relay-modern .system-info{background:#131c30!important;color:#c3cee2!important}
body.cz-relay-modern .image-modal-content{background:#0f1729!important;color:#e8eefb!important}
body.cz-relay-modern .close-modal{color:#a3b0c9!important}
body.cz-relay-modern .close-modal:hover{color:#ffffff!important}
body.cz-relay-modern .product-image,body.cz-relay-modern .image-option,body.cz-relay-modern .image-option img{background:#131c30!important}
body.cz-relay-modern .upload-progress{border-color:#2c3b5c!important}
body.cz-relay-modern .stat-number,body.cz-relay-modern .stat-value,body.cz-relay-modern .stat-icon{color:#cfe0ff!important}
body.cz-relay-modern .cz-transfer-panel{display:flex;flex-direction:column;gap:14px}
body.cz-relay-modern .cz-transfer-panel p{margin:0;color:#c3cee2!important}
body.cz-relay-modern .cz-transfer-actions{display:flex;gap:10px;flex-wrap:wrap}
body.cz-relay-modern .cz-transfer-form{display:flex;flex-direction:column;gap:12px}
body.cz-relay-modern .cz-transfer-form input[type=file]{display:block;width:100%;padding:14px;border:1px dashed #2c3b5c;border-radius:12px;background:#131c30;color:#e8eefb}
body.cz-relay-modern .cz-transfer-alert{padding:14px 16px;border-radius:12px;border:1px solid #1f2b46;background:#131c30;color:#e8eefb}
body.cz-relay-modern .cz-transfer-alert.is-success{background:rgba(22,163,74,.18)!important;border-color:rgba(74,222,128,.28)!important;color:#dcfce7!important}
body.cz-relay-modern .cz-transfer-alert.is-error{background:rgba(220,38,38,.18)!important;border-color:rgba(252,165,165,.28)!important;color:#fee2e2!important}
body.cz-relay-modern .theme-switch{display:none!important}
@media (max-width:1100px){body.cz-relay-modern>.container{flex-direction:column!important}body.cz-relay-modern .cz-sidebar{width:100%!important;border-right:0!important;border-bottom:1px solid #1f2b46}body.cz-relay-modern .stats{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media (max-width:768px){body.cz-relay-modern .cz-topbar{flex-direction:column;align-items:flex-start}body.cz-relay-modern .content{padding:18px!important}body.cz-relay-modern footer{margin-left:18px!important;margin-right:18px!important}body.cz-relay-modern .stats{grid-template-columns:1fr!important}}
/* Page de connexion / deconnexion : elle n'est pas habillee par la coque
   moderne (sa carte vit sous <main class="page">, pas sous body>.container).
   On la repeint donc ici, dans la meme palette sobre que le reste du site. */
body>main.page{background:#070b14!important}
body>main.page>.container{position:relative!important;background:#0b1220!important;border:1px solid #1f2b46!important;box-shadow:none!important}
body>main.page>.container>.title{color:#e8eefb!important}
body>main.page>.container .form-tabs{border-bottom-color:#1f2b46!important}
body>main.page>.container .tab{color:#93a4c0!important}
body>main.page>.container .tab.active{color:#dfe8fb!important;border-bottom-color:#5f79b8!important}
body>main.page>.container label{color:#e8eefb!important}
body>main.page>.container input[type=email],body>main.page>.container input[type=password]{background:#0d182c!important;border:1px solid #243858!important;color:#e8eefb!important}
body>main.page>.container input[type=email]:focus,body>main.page>.container input[type=password]:focus{border-color:#5f79b8!important}
body>main.page>.container .btn{background:#5f79b8!important;color:#eef4ff!important;border:1px solid #7d95c8!important}
body>main.page>.container .btn:hover{transform:none!important;background:#6b84c2!important}
body>main.page>.container .error{background:rgba(160,91,102,.16)!important;color:#f6cdd4!important;border:1px solid rgba(160,91,102,.45)!important}
body>main.page>.container .success{background:rgba(47,143,104,.16)!important;color:#d3f0e2!important;border:1px solid rgba(47,143,104,.45)!important}
body>main.page>.container .db-info{background:#101a2d!important;border:1px solid #1b2a44!important;color:#c3cee2!important}
body>main.page>.container .db-info strong{color:#e8eefb!important}
body>main.page>.container .db-info a{background:#1b2a44!important;color:#e8eefb!important}
body>footer.site-footer{background:#0b1220!important;border-top:1px solid #1f2b46!important;color:#93a4c0!important}
.cz-login-setup{position:absolute;top:14px;right:14px;display:grid;place-items:center;width:34px;height:34px;border-radius:9px;border:1px solid #243858;background:#101a2d;color:#93a4c0!important;text-decoration:none!important;font-size:15px;line-height:1;z-index:5}
.cz-login-setup:hover{color:#e8eefb!important;border-color:#5f79b8}
/* Fournisseurs : une ligne compacte par fournisseur (logo, nom, site) avec un
   depliage pour le reste, et un filtrage instantane pendant la saisie. */
body.cz-relay-modern .cz-sup-search{display:flex;align-items:center;gap:12px;margin:0 0 14px}
body.cz-relay-modern .cz-sup-input{flex:1;min-width:0;padding:11px 14px;border-radius:10px;border:1px solid #243858;background:#0d182c;color:#e8eefb;font-size:14px;font-family:inherit;outline:none}
body.cz-relay-modern .cz-sup-input:focus{border-color:#5f79b8}
body.cz-relay-modern .cz-sup-input::placeholder{color:#7f8ca6}
body.cz-relay-modern .cz-sup-count{flex:none;font-size:12px;color:#93a4c0;white-space:nowrap}
body.cz-relay-modern .cz-sup-list{display:flex;flex-direction:column;gap:8px}
body.cz-relay-modern .cz-sup-row{border:1px solid #1f2b46!important;border-radius:12px;background:#0d182c!important;overflow:hidden}
body.cz-relay-modern .cz-sup-row[hidden]{display:none!important}
body.cz-relay-modern .cz-sup-head{display:flex;align-items:center;gap:14px;padding:10px 14px}
body.cz-relay-modern .cz-sup-logo{width:42px;height:42px;flex:none;display:grid;place-items:center;overflow:hidden;border-radius:10px;background:#101a2d;border:1px solid #1f2b46;font-size:19px}
body.cz-relay-modern .cz-sup-logo img{width:100%;height:100%;object-fit:contain;display:block}
body.cz-relay-modern .cz-sup-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
body.cz-relay-modern .cz-sup-name{font-size:15px;font-weight:650;color:#e8eefb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.cz-relay-modern .cz-sup-site{font-size:12.5px;color:#9db2d8!important;text-decoration:none!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.cz-relay-modern .cz-sup-site:hover{color:#e8eefb!important}
body.cz-relay-modern .cz-sup-toggle{flex:none;display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:9px;border:1px solid #243858;background:#101a2d;color:#c3cee2;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
body.cz-relay-modern .cz-sup-toggle:hover{border-color:#5f79b8;color:#e8eefb}
body.cz-relay-modern .cz-sup-caret{display:inline-block;transition:transform .15s ease}
body.cz-relay-modern .cz-sup-caret.is-open{transform:rotate(180deg)}
body.cz-relay-modern .cz-sup-details{padding:2px 16px 16px 70px;border-top:1px solid #162238;background:#0b1220}
body.cz-relay-modern .cz-sup-details[hidden]{display:none!important}
body.cz-relay-modern .cz-sup-details .supplier-info{margin:10px 0;font-size:13.5px;color:#c3cee2!important}
body.cz-relay-modern .cz-sup-details .supplier-info strong{color:#93a4c0!important}
body.cz-relay-modern .cz-sup-details a{color:#9db2d8!important}
body.cz-relay-modern .cz-sup-details a:hover{color:#e8eefb!important}
body.cz-relay-modern .cz-sup-details .contacts-list{margin-top:12px;padding-top:12px;border-top:1px solid #162238!important}
body.cz-relay-modern .cz-sup-details .contact-item{background:#101a2d!important;border-left:3px solid #4b628c!important;border-radius:8px;color:#c3cee2!important}
body.cz-relay-modern .cz-sup-actions{margin-top:14px!important;text-align:left!important}
@media (max-width:768px){body.cz-relay-modern .cz-sup-details{padding-left:16px}body.cz-relay-modern .cz-sup-head{flex-wrap:wrap}}
/* Reglages API : chaque bloc fournisseur est replie sur une seule ligne, avec
   une pastille verte (configuree) ou rouge (a configurer). */
body.cz-relay-modern .cz-api-item{margin-bottom:8px;border:1px solid #1f2b46!important;border-radius:12px;background:#0d182c!important;overflow:hidden}
body.cz-relay-modern .cz-api-head{display:flex;align-items:center;gap:12px;padding:11px 14px}
body.cz-relay-modern .cz-api-dot{width:10px;height:10px;flex:none;border-radius:50%;background:#5c6b86}
body.cz-relay-modern .cz-api-dot.is-on{background:#2f9e6b;box-shadow:0 0 0 3px rgba(47,158,107,.16)}
body.cz-relay-modern .cz-api-dot.is-off{background:#a05b66;box-shadow:0 0 0 3px rgba(160,91,102,.16)}
body.cz-relay-modern .cz-api-label{flex:1;min-width:0;font-size:14.5px;font-weight:600;color:#e8eefb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
body.cz-relay-modern .cz-api-meta{flex:none;font-size:12px;color:#93a4c0}
body.cz-relay-modern .cz-api-toggle{flex:none;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border-radius:9px;border:1px solid #243858;background:#101a2d;color:#c3cee2;cursor:pointer;font-family:inherit}
body.cz-relay-modern .cz-api-toggle:hover{border-color:#5f79b8;color:#e8eefb}
body.cz-relay-modern .cz-api-caret{display:inline-block;line-height:1;transition:transform .15s ease}
body.cz-relay-modern .cz-api-caret.is-open{transform:rotate(180deg)}
body.cz-relay-modern .cz-api-form{display:block;margin:0!important;padding:16px!important;border:0!important;border-top:1px solid #162238!important;border-radius:0!important;background:#0b1220!important;box-shadow:none!important}
body.cz-relay-modern .cz-api-form[hidden]{display:none!important}
</style>"#;
    const SITE_SHELL_SCRIPT: &str = r#"<script id="cz-site-shell">
(function(){
  if(window.__czRelayModernized){return;}
  window.__czRelayModernized=true;
  function revealPage(){ document.documentElement.classList.remove('cz-prehide'); }
  var path=(location.pathname.split('/').pop()||'index.php').toLowerCase();
  /* Page de connexion : pas de coque ni de sidebar, mais un acces direct aux
     reglages (type de connexion : base locale ou site en ligne). */
  var loginCard=document.querySelector('body > main.page > .container');
  if(loginCard && loginCard.querySelector('.form-tabs')){
    var setupGear=document.createElement('a');
    setupGear.className='cz-login-setup';
    setupGear.href='/setup';
    setupGear.innerHTML='&#9881;';
    setupGear.setAttribute('title',"Reglages de l'application");
    setupGear.setAttribute('aria-label',"Reglages de l'application");
    loginCard.appendChild(setupGear);
    revealPage();return;
  }
  if(['auth.php','login.php'].indexOf(path)>=0){revealPage();return;}
  /* La racine du site sert la page des composants : elle doit etre habillee
     comme les autres ecrans, sinon l'ancien layout reapparait sur l'accueil. */
  if(path==='index.php'){path='components.php';}
  var container=document.querySelector('body > .container, body > .container-fluid');
  if(!container){revealPage();return;}
  var header=container.querySelector(':scope > .app-header');
  var footer=container.querySelector(':scope > footer');
  function pageNodes(){
    return Array.prototype.slice.call(container.children).filter(function(node){
      return node!==header && node!==footer && node.tagName!=='SCRIPT' && node.tagName!=='STYLE';
    });
  }
  function pickMainContent(){
    var direct=pageNodes();
    for(var i=0;i<direct.length;i++){
      if(direct[i].classList && !direct[i].classList.contains('nav-buttons')){ return direct[i]; }
    }
    return null;
  }
  var nodes=pageNodes();
  var content=(header&&container.querySelector(':scope > .content')) || pickMainContent();
  if(!content && !nodes.length){revealPage();return;}
  document.body.classList.add('cz-relay-modern');
  function sectionForPath(file){
    if(['components.php','create_component.php','add_component.php','edit_component.php','component_sheet.php'].indexOf(file)>=0){return 'components.php';}
    if(['locations.php','add_location.php','add_location_batch.php','edit_location.php','delete_location.php'].indexOf(file)>=0){return 'locations.php';}
    if(['suppliers.php','add_supplier.php','edit_supplier.php','delete_supplier.php','suppliers_management.php'].indexOf(file)>=0){return 'suppliers.php';}
    if(['manufacturers.php'].indexOf(file)>=0){return 'manufacturers.php';}
    if(['projects.php','project_detail.php','add_component_to_project.php','upload_project_file.php','upload_project_image.php','delete_project_file.php','generate_project_pdf.php'].indexOf(file)>=0){return 'projects.php';}
    if(['categories_management.php'].indexOf(file)>=0){return 'categories_management.php';}
    if(['marking.php','marking_search.php'].indexOf(file)>=0){return 'marking.php';}
    if(['api_settings.php'].indexOf(file)>=0){return 'api_settings.php';}
    if(['package_images_manager.php','packages_management.php'].indexOf(file)>=0){return 'package_images_manager.php';}
    if(['import_database.php','export_database.php','import_marking_codes.php'].indexOf(file)>=0){return 'import_database.php';}
    if(['settings.php','profile.php'].indexOf(file)>=0){return 'settings.php';}
    return file;
  }
  var activePath=sectionForPath(path);
  var titleEl=header&&header.querySelector('.header-title h1');
  var subtitleEl=header&&header.querySelector('.header-title p');
  var firstH1=container.querySelector('h1');
  var userChip=(header&&header.querySelector('.user-chip')) || container.querySelector('.user-chip');
  var logoutLink=(userChip&&userChip.querySelector('.logout-link')) || container.querySelector('.logout-link');
  var rawEmail=userChip?userChip.textContent.replace(/\s+/g,' ').replace('🚪 Déconnexion','').replace(/^👤\s*/,'').trim():'Utilisateur';
  var initial=(rawEmail||'U').trim().charAt(0).toUpperCase()||'U';
  function fallbackTitle(){
    if(firstH1 && firstH1.textContent.trim()){ return firstH1.textContent.trim(); }
    var title=document.title||'CompoZ&#39;IT';
    return title.replace(/\s*-\s*CompoZ.*/i,'').replace(/\s*·\s*CompoZ.*/i,'').trim() || 'CompoZ&#39;IT';
  }
  var navGroups=[
    {label:'Inventaire',items:[['components.php','▦','Composants'],['locations.php','▤','Emplacements'],['categories_management.php','⌗','Catégories'],['marking.php','🔎','Marquage CMS']]},
    {label:'Partenaires',items:[['manufacturers.php','⛭','Fabricants'],['suppliers.php','⇄','Fournisseurs']]},
    {label:'Activité',items:[['projects.php','◫','Projets']]},
    {label:'Système',items:[['settings.php','⛁','Paramètres']]}
  ];
  var sidebar=document.createElement('aside');
  sidebar.className='cz-sidebar';
  var brand=document.createElement('div');
  brand.className='cz-brand';
  brand.innerHTML='<span class=\"cz-brand-mark\">C</span><span class=\"cz-brand-text\">CompoZ<em>&#39;IT</em><small>Gestion de composants</small></span>';
  sidebar.appendChild(brand);
  var nav=document.createElement('nav');
  nav.className='cz-nav';
  navGroups.forEach(function(group){
    var label=document.createElement('p');
    label.className='cz-nav-label';
    label.textContent=group.label;
    nav.appendChild(label);
    group.items.forEach(function(item){
      var link=document.createElement('a');
      link.className='cz-nav-item'+(activePath===item[0].toLowerCase()?' is-active':'');
      link.href=item[0];
      link.innerHTML='<span class=\"cz-nav-icon\">'+item[1]+'</span>'+item[2];
      nav.appendChild(link);
    });
  });
  sidebar.appendChild(nav);
  var footerBox=document.createElement('div');
  footerBox.className='cz-sidebar-footer';
  footerBox.innerHTML='<div class=\"cz-status-line\"><span class=\"cz-dot\"></span>Mode site en ligne</div><div style=\"margin-top:4px;line-height:1.45\">Session distante relayée par CompoZ&#39;IT</div>';
  sidebar.appendChild(footerBox);

  var main=document.createElement('div');
  main.className='cz-main';
  var topbar=document.createElement('header');
  topbar.className='cz-topbar';
  var titles=document.createElement('div');
  titles.className='cz-topbar-titles';
  titles.innerHTML='<h1>'+(titleEl&&titleEl.textContent.trim()?titleEl.textContent.trim():fallbackTitle())+'</h1>'+(subtitleEl&&subtitleEl.textContent.trim()?'<div class=\"cz-subtitle\">'+subtitleEl.textContent.trim()+'</div>':'');
  var actions=document.createElement('div');
  actions.className='cz-topbar-actions';
  var newChip=document.createElement('div');
  newChip.className='user-chip';
  newChip.innerHTML='<span class=\"cz-user-avatar\">'+initial+'</span><span class=\"cz-user-meta\"><span>'+rawEmail+'</span><span class=\"cz-user-role\">Administrateur</span></span>';
  actions.appendChild(newChip);
  if(logoutLink){
    logoutLink.textContent='Déconnexion';
    logoutLink.className='logout-link cz-ghost-btn';
    actions.appendChild(logoutLink);
  }
  var quitLink=document.createElement('a');
  quitLink.className='quit-link cz-ghost-btn cz-danger-btn';
  quitLink.href='/__quit';
  quitLink.textContent='Arr\u00eater CompoZ\u2019IT';
  quitLink.setAttribute('title','Arr\u00eater CompoZ\u2019IT');
  actions.appendChild(quitLink);
  var STORAGE_KEY='compozit-theme';
  function forceDark(){
    document.documentElement.setAttribute('data-theme','dark');
    try{ localStorage.setItem(STORAGE_KEY,'dark'); }catch(e){}
    var switches=document.querySelectorAll('.theme-switch');
    for(var i=0;i<switches.length;i++){ switches[i].remove(); }
  }
  forceDark();
  window.addEventListener('load', function(){
    forceDark();
    setTimeout(forceDark, 250);
  });
  topbar.appendChild(titles);
  topbar.appendChild(actions);
  main.appendChild(topbar);

  if(path==='project_detail.php'){
    var projectQuickLinks=(header&&header.querySelector('.nav-buttons')) || container.querySelector(':scope > .nav-buttons');
    if(projectQuickLinks){
      projectQuickLinks.remove();
    }

    var projectContent=document.createElement('main');
    projectContent.className='content';
    var projectHeaderCard=header&&header.querySelector('.header-content');
    var projectMain=container.querySelector(':scope > .main-container');
    if(projectHeaderCard){ projectContent.appendChild(projectHeaderCard); }
    if(projectMain){ projectContent.appendChild(projectMain); }
    if(footer){ main.appendChild(footer); }
    main.insertBefore(projectContent, footer || null);
    if(header){ header.remove(); }
    container.innerHTML='';
    container.appendChild(sidebar);
    container.appendChild(main);
    revealPage();
    forceDark();

    /* Suivi en direct : la barre de progression globale et les cartes de resume
       sont rendues une seule fois par le PHP. Sans ce bloc, elles restaient
       figees apres un clic sur +/- alors que les lignes, elles, se mettaient
       bien a jour en AJAX. */
    (function(){
      function czNum(v){
        var s=String(v==null?'':v).replace(/\s/g,'');
        if(s.indexOf('.')<0 && s.indexOf(',')>=0){ s=s.replace(',','.'); }
        s=s.replace(/,/g,'');
        var n=parseFloat(s);
        return isFinite(n)?n:0;
      }
      function czFindCard(needle){
        var cards=document.querySelectorAll('.modern-card');
        for(var i=0;i<cards.length;i++){
          var t=cards[i].querySelector('.card-title');
          if(t && t.textContent.replace(/\s+/g,' ').toLowerCase().indexOf(needle)>=0){ return cards[i]; }
        }
        return null;
      }
      function czValueBox(card,label){
        if(!card){ return null; }
        var divs=card.querySelectorAll('div');
        for(var i=0;i<divs.length;i++){
          if(divs[i].children.length){ continue; }
          if(divs[i].textContent.replace(/\s+/g,' ').trim().toLowerCase()===label){
            return divs[i].previousElementSibling;
          }
        }
        return null;
      }
      function czParts(){
        var used=0,needed=0,ccount=0;
        var rows=document.querySelectorAll('#components tr[data-component-row]');
        for(var i=0;i<rows.length;i++){
          var u=rows[i].querySelector('[data-qty-input]');
          var n=rows[i].querySelector('[data-needed-input]');
          if(!u||!n){ continue; }
          ccount++;
          used+=czNum(u.value);
          needed+=czNum(n.value);
        }
        var done=0,qty=0,icount=0;
        var irows=document.querySelectorAll('#materials tr[data-item-row]');
        for(var j=0;j<irows.length;j++){
          var c=irows[j].querySelector('[data-qty-completed]');
          var t=irows[j].querySelector('[data-qty-total]');
          if(!c||!t){ continue; }
          icount++;
          done+=czNum(c.textContent);
          qty+=czNum(t.textContent);
        }
        var cp=needed>0?Math.min(100,used/needed*100):0;
        var ip=qty>0?Math.min(100,done/qty*100):0;
        var total=ccount+icount;
        var gp=total>0?Math.min(100,cp*(ccount/total)+ip*(icount/total)):0;
        return {used:used,needed:needed,gp:gp};
      }
      var busy=false,timer=null;
      function czSetText(el,val){
        if(!el){ return; }
        if(String(el.textContent).trim()===val){ return; }
        busy=true; el.textContent=val; busy=false;
      }
      function czApply(){
        if(busy){ return; }
        var p=czParts();
        var gcard=czFindCard('progression du projet');
        if(gcard){
          czSetText(gcard.querySelector('.card-header span'),p.gp.toFixed(1)+'%');
          var gfill=gcard.querySelector('.progress-fill');
          if(gfill){
            var w=p.gp.toFixed(2)+'%';
            if(gfill.style.width!==w){ busy=true; gfill.style.width=w; busy=false; }
          }
          var gcap=gcard.querySelector('p');
          if(gcap && gcap.textContent.indexOf('composants utilis')>=0){
            czSetText(gcap,p.used+' composants utilisés sur '+p.needed+' nécessaires');
          }
        }
        var ccard=czFindCard('r\u00e9sum\u00e9 composants');
        if(ccard){
          var boxNeeded=czValueBox(ccard,'n\u00e9cessaires');
          var boxUsed=czValueBox(ccard,'utilis\u00e9s');
          czSetText(boxNeeded,String(Math.round(p.needed)));
          czSetText(boxUsed,String(Math.round(p.used)));
        }
      }
      function czSchedule(){
        if(timer){ return; }
        timer=setTimeout(function(){ timer=null; czApply(); },60);
      }
      /* Les boutons +/- portent une quantite cible calculee au rendu PHP et
         jamais rafraichie : deux clics de suite renvoyaient donc la meme
         valeur. On intercepte l'evenement avant le script du site (capture au
         niveau window) pour recalculer +1 / -1 a partir de l'etat courant. */
      window.addEventListener('submit',function(ev){
        var form=ev.target;
        if(!form || !form.getAttribute || form.getAttribute('data-quantity-form')===null){ return; }
        var type=form.getAttribute('data-action-type');
        if(type!=='plus' && type!=='minus'){ return; }
        var pcId=form.getAttribute('data-pc-id');
        var qtyInput=document.querySelector('#components tr[data-component-row="'+pcId+'"] [data-qty-input]');
        var neededInput=document.querySelector('#components tr[data-component-row="'+pcId+'"] [data-needed-input]');
        var hidden=form.querySelector('input[name="quantity_used"]');
        if(!qtyInput || !neededInput || !hidden){ return; }
        var used=Math.round(czNum(qtyInput.value));
        var needed=Math.round(czNum(neededInput.value));
        hidden.value=String(type==='plus'?Math.min(needed,used+1):Math.max(0,used-1));
      },true);

      var root=document.querySelector('.content')||document.body;
      try{
        var mo=new MutationObserver(function(muts){
          for(var i=0;i<muts.length;i++){
            var t=muts[i].target;
            if(!t||t.nodeType!==1||!t.closest){ continue; }
            if(t.closest('#components,#materials,#overview')){ czSchedule(); return; }
          }
        });
        mo.observe(root,{subtree:true,childList:true,characterData:true,attributes:true});
      }catch(e){}
      czApply();
      window.addEventListener('load',function(){ setTimeout(czApply,250); });
    })();

    return;
  }

  var quickLinks=(header&&header.querySelector('.nav-buttons')) || container.querySelector(':scope > .nav-buttons');
  if(quickLinks){
    quickLinks.remove();
  }
  var headerExtras=[];
  if(header){
    Array.prototype.slice.call(header.children).forEach(function(node){
      if(node.classList && (node.classList.contains('header-top') || node.classList.contains('nav-buttons'))){ return; }
      headerExtras.push(node);
    });
  }
  var contentRoot;
  if(nodes.length===1 && !headerExtras.length){
    contentRoot = nodes[0];
  } else {
    contentRoot = document.createElement('main');
    contentRoot.className = 'content';
    headerExtras.forEach(function(node){ contentRoot.appendChild(node); });
    nodes.forEach(function(node){ contentRoot.appendChild(node); });
  }
  if(header){ header.remove(); }
  if(['import_database.php','export_database.php'].indexOf(path)>=0){
    var params=new URLSearchParams(location.search||'');
    if(params.has('csv_added') || params.has('csv_error')){
      var alert=document.createElement('div');
      alert.className='cz-transfer-alert '+(params.has('csv_error')?'is-error':'is-success');
      if(params.has('csv_error')){
        alert.textContent="L'import CSV a échoué. Vérifie le fichier et réessaie.";
      } else {
        alert.textContent='Import CSV terminé : '+(params.get('csv_added')||'0')+' ajouté(s), '+(params.get('csv_updated')||'0')+' mis à jour, '+(params.get('csv_skipped')||'0')+' ignoré(s).';
      }
      if(contentRoot.firstChild){ contentRoot.insertBefore(alert, contentRoot.firstChild); }
      else { contentRoot.appendChild(alert); }
    }
    var transferPanel=document.createElement('section');
    transferPanel.className='card cz-transfer-panel';
    if(path==='import_database.php'){
      transferPanel.innerHTML="<h2>🧾 CSV composants</h2><p>Importe directement un fichier CSV de composants depuis l'interface Portable-Site, sans repasser par l'ancien écran local.</p><form class=\"cz-transfer-form\" method=\"post\" action=\"/transfer/import\" enctype=\"multipart/form-data\"><input type=\"file\" name=\"file\" accept=\".csv,.json,.txt,.xls\" required><div class=\"cz-transfer-actions\"><button class=\"btn btn-primary\" type=\"submit\">📥 Importer le CSV</button><a class=\"btn btn-success\" href=\"/transfer/export.csv\">📄 Exporter CSV</a><a class=\"btn btn-secondary\" href=\"/transfer/modele.csv\">⬇️ Modèle CSV</a></div></form>";
    } else {
      transferPanel.innerHTML="<h2>🧾 CSV composants</h2><p>Exporte l'inventaire des composants au format CSV ou télécharge un modèle prêt pour Excel.</p><div class=\"cz-transfer-actions\"><a class=\"btn btn-success\" href=\"/transfer/export.csv\">📄 Exporter CSV</a><a class=\"btn btn-secondary\" href=\"/transfer/modele.csv\">⬇️ Modèle CSV</a></div>";
    }
    if(contentRoot.firstChild){ contentRoot.insertBefore(transferPanel, contentRoot.firstChild); }
    else { contentRoot.appendChild(transferPanel); }
  }
  if(path==='settings.php'){
    Array.prototype.slice.call(contentRoot.querySelectorAll('.quick-actions')).forEach(function(el){ el.remove(); });
    var setupPanel=document.createElement('section');
    setupPanel.className='card cz-setup-panel';
    setupPanel.innerHTML="<h2>⚙️ Réglages de l'application</h2><p>Base de données locale, adresse du site en ligne et options de connexion de CompoZ'IT Portable-Site.</p><div class=\"cz-transfer-actions\"><a class=\"btn btn-primary\" href=\"/setup\">Ouvrir les réglages</a></div>";
    contentRoot.appendChild(setupPanel);
  }
  if(path==='projects.php'){
    Array.prototype.slice.call(contentRoot.querySelectorAll('.project-image-wrap')).forEach(function(wrap){
      var card=wrap.closest('.project-card');
      var detailLink=(card&&card.querySelector('.project-actions a[href*="project_detail.php?id="]')) || wrap.querySelector('a[href*="project_detail.php?id="]');
      var editLink=wrap.querySelector('.project-image-edit-btn');
      var href=detailLink && detailLink.getAttribute('href');
      if((!href || href.indexOf('project_detail.php')===-1) && editLink){
        href=(editLink.getAttribute('href')||'').replace(/#.*$/,'');
      }
      if(!href){return;}
      wrap.setAttribute('role','link');
      wrap.setAttribute('tabindex','0');
      wrap.setAttribute('aria-label','Ouvrir le projet');
      wrap.addEventListener('click', function(ev){
        if(ev.target && ev.target.closest && ev.target.closest('.project-image-edit-btn')){return;}
        window.location.href=href;
      });
      wrap.addEventListener('keydown', function(ev){
        if(ev.key==='Enter' || ev.key===' '){
          ev.preventDefault();
          window.location.href=href;
        }
      });
      if(editLink){
        editLink.addEventListener('click', function(ev){ ev.stopPropagation(); });
      }
    });
  }
  /* Fournisseurs : la grille de cartes est remplacee par une liste compacte,
     une ligne par fournisseur (logo, nom, site) avec depliage des details et
     un filtre instantane alimente par la barre de recherche. */
  if(path==='suppliers.php'){
    var supGrid=contentRoot.querySelector('.suppliers-grid');
    if(supGrid){
      var supCards=Array.prototype.slice.call(supGrid.querySelectorAll('.supplier-card'));
      var searchBox=document.createElement('div');
      searchBox.className='cz-sup-search';
      var searchInput=document.createElement('input');
      searchInput.type='search';
      searchInput.className='cz-sup-input';
      searchInput.placeholder='Rechercher un fournisseur (nom, site, email, contact...)';
      searchInput.setAttribute('autocomplete','off');
      var countEl=document.createElement('span');
      countEl.className='cz-sup-count';
      searchBox.appendChild(searchInput);
      searchBox.appendChild(countEl);
      var listBox=document.createElement('div');
      listBox.className='cz-sup-list';
      supCards.forEach(function(card){
        var cardHeader=card.querySelector('.supplier-header');
        var cardLogo=cardHeader?cardHeader.querySelector('.supplier-logo'):null;
        var cardName=cardHeader?cardHeader.querySelector('.supplier-name'):null;
        var name=cardName&&cardName.textContent.trim()?cardName.textContent.trim():'Fournisseur';
        var siteInfo=null;
        var cardActions=null;
        var restNodes=[];
        Array.prototype.slice.call(card.children).forEach(function(node){
          if(node===cardHeader){return;}
          if(!siteInfo && node.querySelector && node.querySelector('a[href^="http"]')){ siteInfo=node; return; }
          if(node.querySelector && node.querySelector('a[href*="edit_supplier.php"]')){ cardActions=node; return; }
          restNodes.push(node);
        });
        var row=document.createElement('article');
        row.className='cz-sup-row';
        var head=document.createElement('div');
        head.className='cz-sup-head';
        var logoBox=document.createElement('div');
        logoBox.className='cz-sup-logo';
        if(cardLogo && cardLogo.tagName==='IMG'){
          var logoImg=document.createElement('img');
          logoImg.src=cardLogo.getAttribute('src')||'';
          logoImg.alt=name;
          logoBox.appendChild(logoImg);
        } else {
          logoBox.textContent=(cardLogo&&cardLogo.textContent.trim())||'🏢';
        }
        var mainCol=document.createElement('div');
        mainCol.className='cz-sup-main';
        var nameDiv=document.createElement('div');
        nameDiv.className='cz-sup-name';
        nameDiv.textContent=name;
        mainCol.appendChild(nameDiv);
        if(siteInfo){
          var siteLink=siteInfo.querySelector('a[href^="http"]');
          if(siteLink){
            var site=document.createElement('a');
            site.className='cz-sup-site';
            site.href=siteLink.getAttribute('href')||'';
            site.target='_blank';
            site.rel='noopener';
            site.textContent=(siteLink.textContent||'').trim().replace(/^https?:\/\//,'').replace(/\/+$/,'');
            mainCol.appendChild(site);
          }
        }
        var toggle=document.createElement('button');
        toggle.type='button';
        toggle.className='cz-sup-toggle';
        toggle.setAttribute('aria-expanded','false');
        toggle.innerHTML='<span class="cz-sup-caret">&#9662;</span>Détails';
        var details=document.createElement('div');
        details.className='cz-sup-details';
        details.hidden=true;
        restNodes.forEach(function(node){ details.appendChild(node); });
        if(cardActions){
          cardActions.className='cz-sup-actions';
          details.appendChild(cardActions);
        }
        toggle.addEventListener('click', function(){
          var open=details.hidden;
          details.hidden=!open;
          toggle.setAttribute('aria-expanded', open?'true':'false');
          toggle.innerHTML=open?'<span class="cz-sup-caret is-open">&#9662;</span>Masquer':'<span class="cz-sup-caret">&#9662;</span>Détails';
        });
        head.appendChild(logoBox);
        head.appendChild(mainCol);
        head.appendChild(toggle);
        row.appendChild(head);
        row.appendChild(details);
        row.setAttribute('data-cz-search',(name+' '+row.textContent).toLowerCase());
        listBox.appendChild(row);
      });
      var gridParent=supGrid.parentNode;
      gridParent.insertBefore(searchBox, supGrid);
      gridParent.replaceChild(listBox, supGrid);
      var supRows=Array.prototype.slice.call(listBox.children);
      var czFilterSuppliers=function(){
        var q=(searchInput.value||'').trim().toLowerCase();
        var shown=0;
        supRows.forEach(function(r){
          var hay=r.getAttribute('data-cz-search')||'';
          var match=!q || hay.indexOf(q)>=0;
          r.hidden=!match;
          if(match){shown++;}
        });
        countEl.textContent=shown+' / '+supRows.length;
      };
      searchInput.addEventListener('input', czFilterSuppliers);
      searchInput.addEventListener('search', czFilterSuppliers);
      czFilterSuppliers();
    }
  }
  /* Reglages API : chaque bloc fournisseur est replie sur une seule ligne
     (pastille verte/rouge, nom, etat) et se deplie au clic. */
  if(path==='api_settings.php'){
    var chipState={};
    Array.prototype.slice.call(contentRoot.querySelectorAll('.status-chip')).forEach(function(chip){
      var chipDot=chip.querySelector('.status-dot');
      var chipLabel=(chip.textContent||'').replace(/\s+/g,' ').trim().toLowerCase();
      if(chipLabel){ chipState[chipLabel]=!!(chipDot&&chipDot.classList.contains('on')); }
    });
    var apiIsOn=function(label){
      var key=(label||'').toLowerCase();
      if(Object.prototype.hasOwnProperty.call(chipState,key)){ return chipState[key]; }
      for(var k in chipState){
        if(!Object.prototype.hasOwnProperty.call(chipState,k)||k.length<3){ continue; }
        if(key.indexOf(k)>=0||k.indexOf(key)>=0){ return chipState[k]; }
      }
      return null;
    };
    var apiBadges=contentRoot.querySelector('.status-badges');
    if(apiBadges){ apiBadges.style.display='none'; }
    var apiJustSaved=(new URLSearchParams(location.search||'')).get('success')||'';
    Array.prototype.slice.call(contentRoot.querySelectorAll('form.card')).forEach(function(form){
      var titleEl=form.querySelector('h2');
      var rawTitle=titleEl?titleEl.textContent.replace(/\s+/g,' ').trim():'';
      var provider=rawTitle.replace(/^[^0-9A-Za-zÀ-ÿ]+/,'').split(/[—–-]\s+/)[0].trim()||'API';
      var on=apiIsOn(provider);
      var body=document.createElement('div');
      body.className='cz-api-body';
      while(form.firstChild){ body.appendChild(form.firstChild); }
      form.appendChild(body);
      if(titleEl){ titleEl.remove(); }
      form.classList.add('cz-api-form');
      form.hidden=true;
      var sectionInput=form.querySelector('input[name="section"]');
      var section=(sectionInput&&sectionInput.value)||'';
      var item=document.createElement('section');
      item.className='cz-api-item';
      var head=document.createElement('div');
      head.className='cz-api-head';
      var dot=document.createElement('span');
      dot.className='cz-api-dot'+(on===true?' is-on':(on===false?' is-off':''));
      var providerEl=document.createElement('span');
      providerEl.className='cz-api-label';
      providerEl.textContent=provider;
      var meta=document.createElement('span');
      meta.className='cz-api-meta';
      meta.textContent=on===null?'':(on?'Configurée':'À configurer');
      var caret=document.createElement('span');
      caret.className='cz-api-caret';
      caret.innerHTML='&#9662;';
      var toggle=document.createElement('button');
      toggle.type='button';
      toggle.className='cz-api-toggle';
      toggle.setAttribute('aria-expanded','false');
      toggle.setAttribute('aria-label','Ouvrir ou replier ce bloc');
      toggle.appendChild(caret);
      var setOpen=function(open){
        form.hidden=!open;
        toggle.setAttribute('aria-expanded', open?'true':'false');
        caret.className='cz-api-caret'+(open?' is-open':'');
      };
      toggle.addEventListener('click', function(){ setOpen(form.hidden); });
      head.appendChild(dot);
      head.appendChild(providerEl);
      head.appendChild(meta);
      head.appendChild(toggle);
      item.appendChild(head);
      form.parentNode.insertBefore(item, form);
      item.appendChild(form);
      if(section && apiJustSaved && section===apiJustSaved){ setOpen(true); }
    });
    var czExpandTuto=function(){
      var h=location.hash||'';
      if(h.indexOf('#tuto-')!==0){ return; }
      var target=document.getElementById(h.slice(1));
      if(!target){ return; }
      var owner=target.closest?target.closest('form.cz-api-form'):null;
      if(owner && owner.hidden){ owner.hidden=false; }
    };
    czExpandTuto();
    window.addEventListener('load', czExpandTuto);
  }
  main.appendChild(contentRoot);
  if(footer){main.appendChild(footer);}
  container.innerHTML='';
  container.appendChild(sidebar);
  container.appendChild(main);
  revealPage();
})();
</script>"#;
    let Ok(text) = std::str::from_utf8(body) else {
        return body.to_vec();
    };
    let cleaned = normalize_cloudflare_email_html(text);
    let Some(index) = cleaned.rfind("</body>") else {
        return body.to_vec();
    };

    // Force le theme sombre au plus tot, avant le script du site lui-meme :
    // sans cela la page s'affiche brievement en clair, puis bascule.
    // La feuille du shell est elle aussi injectee dans le <head> :
    // certaines pages (notamment project_detail.php) laissaient un <div>
    // ouvert jusqu'a la fin du <body>, ce qui faisait tomber <style> dans
    // le conteneur principal. Le shell le vidait ensuite avec innerHTML='',
    // et se supprimait donc lui-meme visuellement.
    const FORCE_DARK: &str = r#"<script id="cz-force-dark">(function(){try{localStorage.setItem('compozit-theme','dark');}catch(e){}document.documentElement.setAttribute('data-theme','dark');var p=(location.pathname.split('/').pop()||'index.php').toLowerCase();if(['auth.php','login.php'].indexOf(p)<0){var r=document.documentElement;r.classList.add('cz-prehide');setTimeout(function(){r.classList.remove('cz-prehide');},3000);}})();</script>"#;

    // Presence de la page : tant qu'un ecran du site est affiche, le relais
    // reste ouvert. Onglet ou navigateur ferme, il s'arrete de lui-meme et
    // l'exe peut etre relance aussitot, sans passer par le gestionnaire de
    // taches. Un clic sur un lien ou un bouton de formulaire annonce un
    // changement de page : l'ecran suivant se signale, inutile de fermer.
    const SITE_HEARTBEAT: &str = r#"<script id="cz-heartbeat">(function(){var leaving=false;var mark=function(){leaving=true;setTimeout(function(){leaving=false;},5000);};try{document.addEventListener('click',function(e){var t=e.target;if(t&&t.closest&&t.closest('a[href],form button,form input[type=submit]')){mark();}},true);}catch(e){}var ping=function(){try{fetch('/__alive',{cache:'no-store'});}catch(e){}};ping();setInterval(ping,3000);window.addEventListener('pagehide',function(){if(leaving){return;}try{if(navigator.sendBeacon){navigator.sendBeacon('/__closing');return;}fetch('/__closing',{method:'POST',keepalive:true});}catch(e){}});})();</script>"#;

    let mut out = String::with_capacity(cleaned.len() + SITE_THEME.len() + SITE_SHELL_SCRIPT.len() + FORCE_DARK.len() + SITE_HEARTBEAT.len());
    let mut cursor = 0usize;
    if let Some(head) = cleaned.to_ascii_lowercase().find("<head") {
        if let Some(close) = cleaned[head..].find('>') {
            let at = head + close + 1;
            out.push_str(&cleaned[..at]);
            out.push_str(FORCE_DARK);
            out.push_str(SITE_HEARTBEAT);
            out.push_str(SITE_THEME);
            cursor = at;
        }
    }
    out.push_str(&cleaned[cursor..index]);
    out.push_str(SITE_SHELL_SCRIPT);
    out.push_str(&cleaned[index..]);
    out.into_bytes()
}

/// Le site distant est derriere Cloudflare, qui peut remplacer les adresses
/// e-mail par des jetons `data-cfemail` accompagnes d'un script de decode.
/// Dans le relais local, ce mecanisme ne tombe pas toujours juste ; on decode
/// donc ces adresses cote serveur pour afficher directement le vrai texte.
fn normalize_cloudflare_email_html(input: &str) -> String {
    let stripped = strip_cloudflare_email_scripts(input);
    let linked = replace_cloudflare_email_links(&stripped);
    replace_cloudflare_email_tags(&linked, "<a ", "</a>", true)
}

fn strip_cloudflare_email_scripts(input: &str) -> String {
    let mut out = String::with_capacity(input.len());
    let mut cursor = 0;

    while let Some(relative_start) = input[cursor..].find("<script") {
        let start = cursor + relative_start;
        out.push_str(&input[cursor..start]);

        let Some(relative_end) = input[start..].find("</script>") else {
            out.push_str(&input[start..]);
            return out;
        };
        let end = start + relative_end + "</script>".len();
        let script = &input[start..end];
        if !script.contains("cloudflare-static/email-decode.min.js") {
            out.push_str(script);
        }
        cursor = end;
    }

    out.push_str(&input[cursor..]);
    out
}

fn replace_cloudflare_email_links(input: &str) -> String {
    replace_cloudflare_email_tags(input, "<a href=\"/cdn-cgi/l/email-protection#", "</a>", false)
}

fn replace_cloudflare_email_tags(
    input: &str,
    start_tag: &str,
    end_tag: &str,
    standalone: bool,
) -> String {
    let mut out = String::with_capacity(input.len());
    let mut cursor = 0;

    while let Some(relative_start) = input[cursor..].find(start_tag) {
        let start = cursor + relative_start;
        out.push_str(&input[cursor..start]);

        let Some(relative_end) = input[start..].find(end_tag) else {
            out.push_str(&input[start..]);
            return out;
        };
        let end = start + relative_end + end_tag.len();
        let chunk = &input[start..end];

        if let Some(encoded) = extract_cfemail(chunk) {
            if let Some(decoded) = decode_cfemail(encoded) {
                let email = escape_html(&decoded);
                if standalone {
                    out.push_str(&format!("<a href=\"mailto:{email}\">{email}</a>"));
                } else {
                    out.push_str(&format!("<a href=\"mailto:{email}\">{email}</a>"));
                }
            } else {
                out.push_str(chunk);
            }
        } else {
            out.push_str(chunk);
        }

        cursor = end;
    }

    out.push_str(&input[cursor..]);
    out
}

fn extract_cfemail(chunk: &str) -> Option<&str> {
    let marker = "data-cfemail=\"";
    let start = chunk.find(marker)? + marker.len();
    let rest = &chunk[start..];
    let end = rest.find('"')?;
    Some(&rest[..end])
}

fn decode_cfemail(encoded: &str) -> Option<String> {
    if encoded.len() < 4 || encoded.len() % 2 != 0 {
        return None;
    }

    let key = u8::from_str_radix(&encoded[..2], 16).ok()?;
    let mut bytes = Vec::with_capacity((encoded.len() / 2).saturating_sub(1));

    let mut index = 2;
    while index + 2 <= encoded.len() {
        let value = u8::from_str_radix(&encoded[index..index + 2], 16).ok()?;
        bytes.push(value ^ key);
        index += 2;
    }

    String::from_utf8(bytes).ok()
}

/// Encodage des valeurs du formulaire de connexion (RFC 3986).
fn encode(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for byte in value.as_bytes() {
        match *byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(*byte as char)
            }
            b' ' => out.push('+'),
            other => out.push_str(&format!("%{other:02X}")),
        }
    }
    out
}
