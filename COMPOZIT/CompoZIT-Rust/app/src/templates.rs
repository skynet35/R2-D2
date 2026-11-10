use std::path::Path;

use axum::response::Html;
use minijinja::value::{Serde, Value as JinjaValue};
use minijinja::Environment;
use serde_json::Value;

use crate::error::AppResult;

/// Gabarits lus depuis le disque au premier usage (puis mis en cache).
pub fn create_env(directory: &Path) -> Environment<'static> {
    let root = directory.to_path_buf();
    let mut environment = Environment::new();
    environment.set_loader(move |name| {
        if name.contains("..") {
            return Ok(None);
        }
        match std::fs::read_to_string(root.join(name)) {
            Ok(source) => Ok(Some(source)),
            Err(_) => Ok(None),
        }
    });
    environment
}

pub fn render(environment: &Environment<'_>, template: &str, context: Value) -> AppResult<Html<String>> {
    let template = environment.get_template(template)?;
    let context = JinjaValue::from(Serde(context));
    Ok(Html(template.render(context)?))
}
