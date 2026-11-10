use axum::http::StatusCode;
use axum::response::{Html, IntoResponse, Redirect, Response};

#[derive(Debug)]
pub enum AppError {
    /// Aucune base configurée : on renvoie vers l'écran de configuration.
    NotConfigured,
    Db(sqlx::Error),
    Template(minijinja::Error),
    Message(String),
}

pub type AppResult<T> = Result<T, AppError>;

impl From<sqlx::Error> for AppError {
    fn from(error: sqlx::Error) -> Self {
        AppError::Db(error)
    }
}

impl From<minijinja::Error> for AppError {
    fn from(error: minijinja::Error) -> Self {
        AppError::Template(error)
    }
}

impl std::fmt::Display for AppError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            AppError::NotConfigured => write!(formatter, "aucune base de données configurée"),
            AppError::Db(error) => write!(formatter, "erreur base de données : {error}"),
            AppError::Template(error) => write!(formatter, "erreur de gabarit : {error}"),
            AppError::Message(message) => write!(formatter, "{message}"),
        }
    }
}

impl std::error::Error for AppError {}

impl IntoResponse for AppError {
    fn into_response(self) -> Response {
        if matches!(&self, AppError::NotConfigured) {
            return Redirect::to("/setup").into_response();
        }

        tracing::error!("{self}");
        let body = format!(
            "<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"utf-8\">\
             <title>Erreur — CompoZ'IT</title></head>\
             <body style=\"font-family:'Segoe UI',sans-serif;background:#0b1220;color:#e8eefb;\
             display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0\">\
             <div style=\"max-width:560px;padding:32px;border:1px solid #1f2b46;border-radius:14px;background:#0f1729\">\
             <h1 style=\"font-size:19px;margin:0 0 10px\">Une erreur est survenue</h1>\
             <p style=\"color:#93a4c0;font-size:14px\">{}</p>\
             <p><a style=\"color:#60a5fa\" href=\"/\">Retour à l'application</a></p>\
             </div></body></html>",
            escape_html(&self.to_string())
        );
        (StatusCode::INTERNAL_SERVER_ERROR, Html(body)).into_response()
    }
}

pub(crate) fn escape_html(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            other => out.push(other),
        }
    }
    out
}
