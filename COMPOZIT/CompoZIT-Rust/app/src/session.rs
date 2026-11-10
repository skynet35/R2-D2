use axum::extract::FromRequestParts;
use axum::http::request::Parts;
use axum::response::Redirect;
use serde::{Deserialize, Serialize};
use serde_json::{json, Map, Value};
use tower_sessions::Session;

/// Clé sous laquelle l'utilisateur connecté est rangé dans la session.
pub const SESSION_KEY: &str = "compozit_user";

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SessionUser {
    pub id: i64,
    pub email: String,
    pub role: String,
}

/// Extracteur : garantit qu'une session valide existe, sinon redirige vers la
/// page de connexion.
pub struct CurrentUser(pub SessionUser);

impl std::ops::Deref for CurrentUser {
    type Target = SessionUser;

    fn deref(&self) -> &Self::Target {
        &self.0
    }
}

impl<S> FromRequestParts<S> for CurrentUser
where
    S: Send + Sync,
{
    type Rejection = Redirect;

    async fn from_request_parts(parts: &mut Parts, state: &S) -> Result<Self, Self::Rejection> {
        let session = Session::from_request_parts(parts, state)
            .await
            .map_err(|_| Redirect::to("/login"))?;

        match session.get::<SessionUser>(SESSION_KEY).await {
            Ok(Some(user)) => Ok(CurrentUser(user)),
            _ => Err(Redirect::to("/login")),
        }
    }
}

impl CurrentUser {
    /// Champs communs à toutes les pages authentifiées.
    pub fn default_context(&self, active_nav: &str) -> Map<String, Value> {
        let mut context = Map::new();
        context.insert("user_id".to_string(), json!(self.0.id));
        context.insert("user_email".to_string(), json!(self.0.email));
        context.insert("user_initial".to_string(), json!(initial(&self.0.email)));
        context.insert("user_role_label".to_string(), json!(role_label(&self.0.role)));
        context.insert("active_nav".to_string(), json!(active_nav));
        context
    }
}

fn initial(email: &str) -> String {
    email
        .chars()
        .next()
        .map(|character| character.to_uppercase().to_string())
        .unwrap_or_else(|| "?".to_string())
}

fn role_label(role: &str) -> &'static str {
    match role {
        "administrateur" => "Administrateur",
        _ => "Utilisateur",
    }
}
