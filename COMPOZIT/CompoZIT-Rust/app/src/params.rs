//! Paramètres techniques dynamiques des composants.
//!
//! Reprend le moteur de `component_params.php` : `param_definitions` décrit les
//! paramètres disponibles par sous-catégorie, `data_params` enregistre les
//! valeurs réelles de chaque composant. Les deux applications — PHP et Rust —
//! partagent ces tables : un composant saisi d'un côté est donc relu de l'autre
//! avec les mêmes caractéristiques.
//!
//! Les valeurs numériques sont ramenées en texte (`CAST(value_num AS CHAR)`)
//! pour rester fidèles au stockage `DECIMAL(18,6)` sans dépendre d'une
//! bibliothèque de décimales.

use std::collections::HashMap;

use serde::Serialize;
use serde_json::{json, Value};
use sqlx::{FromRow, MySqlPool, QueryBuilder};

/// Une ligne de `param_definitions`.
#[derive(Debug, Clone, FromRow, Serialize)]
pub struct ParamDefinition {
    pub id: i64,
    pub category_id: i64,
    pub subcategory_id: Option<i64>,
    pub param_key: String,
    pub label: String,
    pub unit: Option<String>,
    pub value_type: String,
    pub compare_mode: String,
    pub sort_order: i32,
}

/// Une valeur enregistrée pour un composant (jointure `data_params`).
#[derive(Debug, Clone, FromRow, Serialize)]
pub struct ParamValue {
    pub param_key: String,
    pub label: String,
    pub unit: Option<String>,
    pub value_type: String,
    pub compare_mode: String,
    pub sort_order: i32,
    pub value_num: Option<String>,
    pub value_txt: Option<String>,
}

#[derive(Debug, FromRow)]
struct ParamValueRow {
    data_id: i64,
    param_key: String,
    label: String,
    unit: Option<String>,
    value_type: String,
    compare_mode: String,
    sort_order: i32,
    value_num: Option<String>,
    value_txt: Option<String>,
}

const DEFINITION_COLUMNS: &str = "id, category_id, subcategory_id, param_key, label, unit, \
     value_type, compare_mode, sort_order";

const VALUE_SELECT: &str = "SELECT dp.data_id, pd.param_key, pd.label, pd.unit, pd.value_type, \
     pd.compare_mode, pd.sort_order, CAST(dp.value_num AS CHAR) AS value_num, dp.value_txt \
     FROM data_params dp INNER JOIN param_definitions pd ON pd.id = dp.param_id";

// ------------------------------------------------------------------ Référentiel

/// `compozitGetParamDefinitions($pdo, $subcategoryId)` : les paramètres d'une
/// sous-catégorie, dans l'ordre d'affichage.
pub async fn definitions_for_subcategory(
    pool: &MySqlPool,
    subcategory_id: i64,
) -> Result<Vec<ParamDefinition>, sqlx::Error> {
    let sql = format!(
        "SELECT {DEFINITION_COLUMNS} FROM param_definitions \
         WHERE subcategory_id = ? ORDER BY sort_order, id"
    );
    sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(subcategory_id)
        .fetch_all(pool)
        .await
}

/// `compozitGetAllParamDefinitionsMap($pdo)` : toutes les définitions, groupées
/// par sous-catégorie. Alimente le formulaire en une seule lecture.
pub async fn all_definitions_map(
    pool: &MySqlPool,
) -> Result<HashMap<i64, Vec<ParamDefinition>>, sqlx::Error> {
    let sql = format!(
        "SELECT {DEFINITION_COLUMNS} FROM param_definitions \
         ORDER BY subcategory_id, sort_order, id"
    );
    let rows: Vec<ParamDefinition> = sqlx::query_as(sqlx::AssertSqlSafe(sql)).fetch_all(pool).await?;

    let mut map: HashMap<i64, Vec<ParamDefinition>> = HashMap::new();
    for row in rows {
        if let Some(sub) = row.subcategory_id {
            map.entry(sub).or_default().push(row);
        }
    }
    Ok(map)
}

/// Version JSON du référentiel, telle qu'attendue par le script du formulaire :
/// `{ "<sous-catégorie>": [ { param_key, label, unit, value_type, compare_mode } ] }`.
pub fn definitions_map_json(map: &HashMap<i64, Vec<ParamDefinition>>) -> Value {
    let mut object = serde_json::Map::new();
    for (subcategory, definitions) in map {
        let items: Vec<Value> = definitions
            .iter()
            .map(|definition| {
                json!({
                    "param_key": definition.param_key,
                    "label": definition.label,
                    "unit": definition.unit,
                    "value_type": definition.value_type,
                    "compare_mode": definition.compare_mode,
                })
            })
            .collect();
        object.insert(subcategory.to_string(), json!(items));
    }
    Value::Object(object)
}

// ---------------------------------------------------------------------- Valeurs

/// Les valeurs d'un composant, dans l'ordre d'affichage (fiche).
pub async fn rows_for_component(
    pool: &MySqlPool,
    data_id: i64,
) -> Result<Vec<ParamValue>, sqlx::Error> {
    let sql = format!("{VALUE_SELECT} WHERE dp.data_id = ? ORDER BY pd.sort_order, pd.id");
    sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .bind(data_id)
        .fetch_all(pool)
        .await
}

/// Les valeurs de plusieurs composants, groupées par identifiant (liste).
pub async fn rows_map(
    pool: &MySqlPool,
    data_ids: &[i64],
) -> Result<HashMap<i64, Vec<ParamValue>>, sqlx::Error> {
    if data_ids.is_empty() {
        return Ok(HashMap::new());
    }

    let mut builder = QueryBuilder::new(format!("{VALUE_SELECT} WHERE dp.data_id IN ("));
    let mut separated = builder.separated(", ");
    for id in data_ids {
        separated.push_bind(*id);
    }
    separated.push_unseparated(") ORDER BY dp.data_id, pd.sort_order, pd.id");
    let rows: Vec<ParamValueRow> = builder.build_query_as().fetch_all(pool).await?;

    let mut map: HashMap<i64, Vec<ParamValue>> = HashMap::new();
    for row in rows {
        map.entry(row.data_id).or_default().push(ParamValue {
            param_key: row.param_key,
            label: row.label,
            unit: row.unit,
            value_type: row.value_type,
            compare_mode: row.compare_mode,
            sort_order: row.sort_order,
            value_num: row.value_num,
            value_txt: row.value_txt,
        });
    }
    Ok(map)
}

/// `compozitGetComponentParamValues($pdo, $dataId)` : `param_key` → valeur
/// textuelle prête à réafficher dans un champ du formulaire.
pub async fn component_values(
    pool: &MySqlPool,
    data_id: i64,
) -> Result<HashMap<String, String>, sqlx::Error> {
    let rows = rows_for_component(pool, data_id).await?;
    let mut map = HashMap::new();
    for row in rows {
        let value = if row.value_type == "number" {
            normalize_number(row.value_num.as_deref().unwrap_or(""))
        } else {
            row.value_txt.clone().unwrap_or_default()
        };
        map.insert(row.param_key.clone(), value);
    }
    Ok(map)
}

/// `compozitFormatParamValue($row)` : « 5.5 V », « Rouge »…
pub fn format_value(row: &ParamValue) -> String {
    if row.value_type == "number" {
        let normalized = normalize_number(row.value_num.as_deref().unwrap_or(""));
        if normalized.is_empty() {
            return String::new();
        }
        let unit = row.unit.as_deref().unwrap_or("").trim();
        if unit.is_empty() {
            normalized
        } else {
            format!("{normalized} {unit}")
        }
    } else {
        row.value_txt.as_deref().unwrap_or("").trim().to_string()
    }
}

/// `compozitBuildParamSummary($rows, $limit)` : étiquettes courtes pour la liste.
pub fn build_summary(rows: &[ParamValue], limit: usize) -> Vec<String> {
    let mut items = Vec::new();
    for row in rows {
        let value = format_value(row);
        if value.is_empty() {
            continue;
        }
        let label = if row.label.trim().is_empty() {
            row.param_key.as_str()
        } else {
            row.label.as_str()
        };
        items.push(format!("{label} {value}").trim().to_string());
        if items.len() >= limit {
            break;
        }
    }
    items
}

/// Réduit un nombre stocké en texte à sa forme la plus courte
/// (« 5.500000 » → « 5.5 », « 100.000000 » → « 100 »).
fn normalize_number(raw: &str) -> String {
    let raw = raw.trim();
    if raw.is_empty() {
        return String::new();
    }
    let Ok(value) = raw.parse::<f64>() else {
        return raw.to_string();
    };
    let mut formatted = format!("{value:.6}");
    if formatted.contains('.') {
        formatted = formatted.trim_end_matches('0').trim_end_matches('.').to_string();
    }
    formatted
}

// ------------------------------------------------------------------ Écriture

/// `compozitSaveComponentParams($pdo, $dataId, $subcategoryId, $source)` :
/// remplace les valeurs du composant par celles du formulaire. Les champs
/// attendus portent le préfixe `param_` suivi de la clé du paramètre.
pub async fn save(
    pool: &MySqlPool,
    data_id: i64,
    subcategory_id: Option<i64>,
    source: &HashMap<String, String>,
) -> Result<(), sqlx::Error> {
    sqlx::query("DELETE FROM `data_params` WHERE `data_id` = ?")
        .bind(data_id)
        .execute(pool)
        .await?;

    let Some(subcategory_id) = subcategory_id else {
        return Ok(());
    };

    let definitions = definitions_for_subcategory(pool, subcategory_id).await?;
    for definition in definitions {
        let Some(raw) = source.get(&format!("param_{}", definition.param_key)) else {
            continue;
        };

        if definition.value_type == "number" {
            let normalized = raw.trim().replace(',', ".");
            let Ok(value) = normalized.parse::<f64>() else {
                continue;
            };
            sqlx::query(
                "INSERT INTO `data_params` (`data_id`, `param_id`, `value_num`, `value_txt`) \
                 VALUES (?, ?, ?, NULL)",
            )
            .bind(data_id)
            .bind(definition.id)
            .bind(value)
            .execute(pool)
            .await?;
        } else {
            let value = raw.trim();
            if value.is_empty() {
                continue;
            }
            sqlx::query(
                "INSERT INTO `data_params` (`data_id`, `param_id`, `value_num`, `value_txt`) \
                 VALUES (?, ?, NULL, ?)",
            )
            .bind(data_id)
            .bind(definition.id)
            .bind(value)
            .execute(pool)
            .await?;
        }
    }
    Ok(())
}
