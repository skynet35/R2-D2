use std::time::Duration;

use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions};
use sqlx::MySqlPool;

use crate::config::DatabaseConfig;

/// Ouvre (et valide) un pool de connexions vers la base indiquée.
pub async fn connect(config: &DatabaseConfig) -> Result<MySqlPool, sqlx::Error> {
    let mut options = MySqlConnectOptions::new()
        .host(&config.host)
        .port(config.port)
        .username(&config.user)
        .database(&config.database)
        .charset("utf8mb4");

    // Un mot de passe vide doit rester absent du handshake : sinon le serveur
    // reçoit un scramble non vide et répond « Access denied … using password: YES »
    // sur un compte sans mot de passe (cas par défaut de XAMPP).
    if !config.password.is_empty() {
        options = options.password(&config.password);
    }

    MySqlPoolOptions::new()
        .max_connections(10)
        .acquire_timeout(Duration::from_secs(8))
        .connect_with(options)
        .await
}
