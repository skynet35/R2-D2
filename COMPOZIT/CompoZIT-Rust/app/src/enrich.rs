//! Enrichissement non destructif des paramètres techniques.
//!
//! Reprend le moteur de `import_database.php` : les caractéristiques sont
//! déduites du texte du composant (commentaire, référence fournisseur, nom…),
//! puis, si nécessaire, d'une fiche fournisseur récupérée via les API. Les
//! valeurs déjà présentes ne sont jamais écrasées.
//!
//! Le moteur générique s'appuie sur `param_definitions` (libellés, unités et
//! modes de comparaison) pour extraire un nombre suivi de son unité — avec
//! conversion d'échelle (« 55 ns », « 2.7-5.5 V » → 5.5) — ou un texte. Les
//! diodes bénéficient en plus d'une lecture spécialisée (boîtier, tension,
//! courant, temps de recouvrement…).

use std::collections::{HashMap, HashSet};
use std::path::Path;

use regex::Regex;
use sqlx::{FromRow, MySqlPool, QueryBuilder};
use unicode_normalization::UnicodeNormalization;

use crate::api::{self, ApiConfig, Part, Provider};
use crate::params::{self, ParamDefinition};

/// Taille d'un lot pour la recherche globale (comme `COMPOZIT_PARAM_ENRICH_BATCH`).
pub const ENRICH_BATCH: usize = 40;

// --------------------------------------------------------------- Normalisation

/// `compozitParamNormalizeText` : minuscules, sans accents.
pub fn normalize_text(value: &str) -> String {
    let trimmed = value.trim();
    if trimmed.is_empty() {
        return String::new();
    }
    let lowered = trimmed.to_lowercase();
    lowered
        .nfd()
        .filter(|character| !unicode_normalization::char::is_combining_mark(*character))
        .collect()
}

/// `compozitParamNormalizeRef` : ne conserve que les caractères alphanumériques.
pub fn normalize_ref(value: &str) -> String {
    normalize_text(value)
        .chars()
        .filter(|character| character.is_ascii_alphanumeric())
        .collect()
}

/// `compozitParamNumber` : forme la plus courte d'un nombre (« 5.500000 » → « 5.5 »).
pub fn param_number(value: f64) -> String {
    if !value.is_finite() {
        return String::new();
    }
    let mut formatted = format!("{value:.6}");
    if formatted.contains('.') {
        formatted = formatted.trim_end_matches('0').trim_end_matches('.').to_string();
    }
    formatted
}

fn collapse_spaces(value: &str) -> String {
    value.split_whitespace().collect::<Vec<_>>().join(" ")
}

fn replace_chars_with(value: &str, chars: &[char], replacement: char) -> String {
    value
        .chars()
        .map(|character| if chars.contains(&character) { replacement } else { character })
        .collect()
}

fn remove_chars(value: &str, chars: &[char]) -> String {
    value
        .chars()
        .filter(|character| !chars.contains(character))
        .collect()
}

fn first_non_empty(values: &[String]) -> String {
    values
        .iter()
        .find(|value| !value.is_empty())
        .cloned()
        .unwrap_or_default()
}

fn re(pattern: &str) -> Regex {
    Regex::new(pattern).expect("motif régulier valide")
}

fn regex_matches(pattern: &str, text: &str) -> bool {
    re(&format!("(?i){pattern}")).is_match(text)
}

// -------------------------------------------------------------- Unités

/// `compozitImportNormalizeUnit` : « µF », « Ω », « Ohms » → « uf », « ohm »…
fn normalize_unit(unit: &str) -> String {
    let trimmed = unit.trim();
    if trimmed.is_empty() {
        return String::new();
    }
    let mut value = trimmed.to_string();
    value = value.replace('µ', "u").replace('μ', "u");
    value = value.replace('Ω', "ohm").replace('Ω', "ohm").replace('ω', "ohm");
    value = value
        .replace("°C", "C")
        .replace("ºC", "C")
        .replace("°c", "C")
        .replace("ºc", "C");
    value = value.to_lowercase();
    for character in [' ', '\t', '\r', '\n'] {
        value = value.replace(character, "");
    }
    for (from, to) in [
        ("ohms", "ohm"),
        ("ohm.", "ohm"),
        ("kohms", "kohm"),
        ("kohm.", "kohm"),
        ("mohms", "mohm"),
        ("mohm.", "mohm"),
        ("megohms", "megohm"),
    ] {
        value = value.replace(from, to);
    }
    value
}

/// Dimension et facteur d'une unité (« mv » → voltage, 1e-3).
fn unit_meta(unit: &str) -> Option<(&'static str, f64)> {
    let meta: [(&str, &str, f64); 49] = [
        ("uv", "voltage", 1e-6),
        ("mv", "voltage", 1e-3),
        ("v", "voltage", 1.0),
        ("kv", "voltage", 1e3),
        ("ua", "current", 1e-6),
        ("ma", "current", 1e-3),
        ("a", "current", 1.0),
        ("ka", "current", 1e3),
        ("uw", "power", 1e-6),
        ("mw", "power", 1e-3),
        ("w", "power", 1.0),
        ("kw", "power", 1e3),
        ("hz", "frequency", 1.0),
        ("khz", "frequency", 1e3),
        ("mhz", "frequency", 1e6),
        ("ghz", "frequency", 1e9),
        ("pf", "capacitance", 1e-12),
        ("nf", "capacitance", 1e-9),
        ("uf", "capacitance", 1e-6),
        ("mf", "capacitance", 1e-3),
        ("f", "capacitance", 1.0),
        ("nh", "inductance", 1e-9),
        ("uh", "inductance", 1e-6),
        ("mh", "inductance", 1e-3),
        ("h", "inductance", 1.0),
        ("uohm", "resistance", 1e-6),
        ("mohm", "resistance", 1e-3),
        ("ohm", "resistance", 1.0),
        ("kohm", "resistance", 1e3),
        ("megohm", "resistance", 1e6),
        ("ns", "time", 1e-9),
        ("us", "time", 1e-6),
        ("ms", "time", 1e-3),
        ("s", "time", 1.0),
        ("nm", "length", 1e-9),
        ("um", "length", 1e-6),
        ("mm", "length", 1e-3),
        ("cm", "length", 1e-2),
        ("m", "length", 1.0),
        ("in", "length", 0.0254),
        ("kb", "memory", 1024.0),
        ("mb", "memory", 1048576.0),
        ("gb", "memory", 1073741824.0),
        ("%", "percent", 1.0),
        ("ppm", "ppm", 1.0),
        ("g", "g", 1.0),
        ("lm", "lumen", 1.0),
        ("cd", "candela", 1.0),
        ("mcd", "candela", 1e-3),
    ];
    meta.iter()
        .find(|(key, _, _)| *key == unit)
        .map(|(_, dimension, factor)| (*dimension, *factor))
}

/// `compozitImportConvertUnitValue` : convertit d'une unité vers une autre.
fn convert_unit_value(value: f64, source_unit: &str, target_unit: &str) -> Option<String> {
    let source = normalize_unit(source_unit);
    let target = normalize_unit(target_unit);

    if target.is_empty() || source == target {
        return Some(param_number(value));
    }

    let (source_dimension, source_factor) = unit_meta(&source)?;
    let (target_dimension, target_factor) = unit_meta(&target)?;
    if source_dimension != target_dimension {
        return None;
    }
    Some(param_number(value * source_factor / target_factor))
}

// -------------------------------------------------------- Motifs et alias

/// Unités équivalentes à une unité cible (« v » → [uv, mv, v, kv]).
fn unit_tokens(unit: &str) -> Vec<&'static str> {
    let target = normalize_unit(unit);
    if target.is_empty() {
        return Vec::new();
    }
    let groups: [(&str, &[&str]); 12] = [
        ("v", &["uv", "mv", "v", "kv"]),
        ("a", &["ua", "ma", "a", "ka"]),
        ("w", &["uw", "mw", "w", "kw"]),
        ("hz", &["hz", "khz", "mhz", "ghz"]),
        ("f", &["pf", "nf", "uf", "mf", "f"]),
        ("h", &["nh", "uh", "mh", "h"]),
        ("ohm", &["uohm", "mohm", "ohm", "kohm", "megohm"]),
        ("s", &["ns", "us", "ms", "s"]),
        ("m", &["nm", "um", "mm", "cm", "m", "in"]),
        ("mm", &["nm", "um", "mm", "cm", "m", "in"]),
        ("nm", &["nm", "um", "mm", "cm", "m"]),
        ("kb", &["kb", "mb", "gb"]),
    ];

    for (key, tokens) in groups {
        if key == target {
            return tokens.to_vec();
        }
    }
    for (_, tokens) in groups {
        if tokens.contains(&target.as_str()) {
            return tokens.to_vec();
        }
    }
    Vec::new()
}

/// Libellés comparables d'un paramètre (libellé + variantes de la clé).
fn definition_aliases(definition: &ParamDefinition) -> Vec<String> {
    let sources = [
        definition.label.clone(),
        definition.param_key.replace('_', " "),
        definition.param_key.replace('_', ""),
    ];

    let mut list: Vec<String> = Vec::new();
    {
        let mut add = |value: String| {
            if !value.is_empty() && !list.contains(&value) {
                list.push(value);
            }
        };
        for source in sources {
            let normalized = collapse_spaces(&normalize_text(&source));
            if normalized.is_empty() {
                continue;
            }
            let variant2 = replace_chars_with(&normalized, &['(', ')', '.', ','], ' ');
            let variant3 = remove_chars(&normalized, &['(', ')', '.', ',', ' ', '-', '/']);
            let variant4 = replace_chars_with(&normalized, &['(', ')', '.', ',', '-'], ' ');
            for variant in [normalized, variant2, variant3, variant4] {
                add(collapse_spaces(&variant));
            }
        }
    }

    list.sort_by(|left, right| right.len().cmp(&left.len()));
    list
}

fn alias_pattern(definition: &ParamDefinition) -> String {
    definition_aliases(definition)
        .iter()
        .map(|alias| regex::escape(alias))
        .collect::<Vec<_>>()
        .join("|")
}

fn label_pattern(labels: &[&str]) -> String {
    labels
        .iter()
        .map(|label| regex::escape(&normalize_text(label)))
        .collect::<Vec<_>>()
        .join("|")
}

// ---------------------------------------------------- Extraction par unité

/// `compozitParamParseFirstMetric` : premier nombre capté par l'un des motifs.
fn parse_first_metric(
    text: &str,
    patterns: &[Regex],
    transform: Option<&dyn Fn(f64, &str) -> f64>,
) -> String {
    for pattern in patterns {
        let Some(captures) = pattern.captures(text) else {
            continue;
        };
        let Some(integer) = captures.get(1) else {
            continue;
        };
        let raw = integer.as_str().replace(',', ".");
        let Ok(value) = raw.parse::<f64>() else {
            continue;
        };
        let unit = captures.get(2).map(|item| item.as_str()).unwrap_or("");
        let value = match transform {
            Some(transform) => transform(value, unit),
            None => value,
        };
        if !value.is_finite() {
            continue;
        }
        return param_number(value);
    }
    String::new()
}

fn extract_voltage(text: &str, labels: &[&str]) -> String {
    let escaped = label_pattern(labels);
    first_non_empty(&[
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(?:{escaped})[^\d]{{0,12}}(\d+(?:[.,]\d+)?)\s*v\b"
            ))],
            None,
        ),
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(\d+(?:[.,]\d+)?)\s*v\b[^\n]{{0,18}}(?:{escaped})"
            ))],
            None,
        ),
    ])
}

fn extract_current_amp(text: &str, labels: &[&str]) -> String {
    let escaped = label_pattern(labels);
    let milli = |value: f64, unit: &str| if unit == "ma" { value / 1000.0 } else { value };
    first_non_empty(&[
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(?:{escaped})[^\d]{{0,14}}(\d+(?:[.,]\d+)?)\s*(ma|a)\b"
            ))],
            Some(&milli),
        ),
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(\d+(?:[.,]\d+)?)\s*(ma|a)\b[^\n]{{0,18}}(?:{escaped})"
            ))],
            Some(&milli),
        ),
    ])
}

fn extract_current_micro(text: &str, labels: &[&str]) -> String {
    let escaped = label_pattern(labels);
    let micro = |value: f64, unit: &str| if unit == "ma" { value * 1000.0 } else { value };
    first_non_empty(&[
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(?:{escaped})[^\d]{{0,14}}(\d+(?:[.,]\d+)?)\s*(ua|µa|ma)\b"
            ))],
            Some(&micro),
        ),
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(\d+(?:[.,]\d+)?)\s*(ua|µa|ma)\b[^\n]{{0,18}}(?:{escaped})"
            ))],
            Some(&micro),
        ),
    ])
}

fn extract_power_w(text: &str, labels: &[&str]) -> String {
    let escaped = label_pattern(labels);
    let milli = |value: f64, unit: &str| if unit == "mw" { value / 1000.0 } else { value };
    first_non_empty(&[
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(?:{escaped})[^\d]{{0,14}}(\d+(?:[.,]\d+)?)\s*(mw|w)\b"
            ))],
            Some(&milli),
        ),
        parse_first_metric(
            text,
            &[re(&format!(
                r"(?i)(\d+(?:[.,]\d+)?)\s*(mw|w)\b[^\n]{{0,18}}(?:{escaped})"
            ))],
            Some(&milli),
        ),
    ])
}

fn extract_tolerance(text: &str) -> String {
    first_non_empty(&[
        parse_first_metric(
            text,
            &[re(r"(?i)(?:tolerance|tol)[^\d]{0,10}(\d+(?:[.,]\d+)?)\s*%")],
            None,
        ),
        parse_first_metric(text, &[re(r"(?i)(?:\+\/-|±)\s*(\d+(?:[.,]\d+)?)\s*%")], None),
        parse_first_metric(text, &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*%")], None),
    ])
}

fn extract_vf(text: &str) -> String {
    first_non_empty(&[
        extract_voltage(
            text,
            &[
                "vf",
                "v_f",
                "forward voltage",
                "forward volt",
                "voltage drop",
                "dropout",
            ],
        ),
        parse_first_metric(
            text,
            &[re(r"(?i)\b(\d+(?:[.,]\d+)?)\s*v\b[^\n]{0,22}(?:at|@)\s*\d")],
            None,
        ),
    ])
}

fn extract_ifsm(text: &str) -> String {
    let milli = |value: f64, unit: &str| if unit == "ma" { value / 1000.0 } else { value };
    first_non_empty(&[
        extract_current_amp(
            text,
            &[
                "ifsm",
                "i_fsm",
                "forward surge current",
                "surge current",
                "non repetitive surge",
                "peak forward surge current",
            ],
        ),
        parse_first_metric(
            text,
            &[re(r"(?i)\b(\d+(?:[.,]\d+)?)\s*(ma|a)\b[^\n]{0,28}(?:ifsm|surge)")],
            Some(&milli),
        ),
    ])
}

// ------------------------------------------------ Extraction générique

/// `compozitImportExtractNumberByDefinition` : nombre associé à un paramètre.
fn extract_number_by_definition(text: &str, definition: &ParamDefinition) -> String {
    let alias = alias_pattern(definition);
    if alias.is_empty() {
        return String::new();
    }

    let target_unit = definition.unit.clone().unwrap_or_default();
    let tokens = unit_tokens(&target_unit);

    if !tokens.is_empty() {
        let unit_pattern = tokens
            .iter()
            .map(|token| regex::escape(token))
            .collect::<Vec<_>>()
            .join("|");

        let patterns = [
            re(&format!(
                r"(?i)(?:{alias})[^a-z0-9]{{0,10}}([-+]?\d+(?:[.,]\d+)?)\s*({unit_pattern})\b"
            )),
            re(&format!(
                r"(?i)([-+]?\d+(?:[.,]\d+)?)\s*({unit_pattern})\b[^\n,;|]{{0,20}}(?:{alias})\b"
            )),
        ];

        for pattern in &patterns {
            let Some(captures) = pattern.captures(text) else {
                continue;
            };
            let Some(number) = captures.get(1) else {
                continue;
            };
            let raw = number.as_str().replace(',', ".");
            let Ok(value) = raw.parse::<f64>() else {
                continue;
            };
            let unit = captures.get(2).map(|item| item.as_str()).unwrap_or("");
            if let Some(converted) = convert_unit_value(value, unit, &target_unit) {
                if !converted.is_empty() {
                    return converted;
                }
            }
        }

        // Repli « unité nue » : sans alias à proximité, on retient le premier
        // couple nombre/unité trouvé (« 2.7-5.5V » → 5.5, « 55ns » → 55).
        let bare = re(&format!(r"(?i)(\d+(?:[.,]\d+)?)\s*({unit_pattern})\b"));
        let mut candidates: Vec<(f64, String)> = Vec::new();
        for captures in bare.captures_iter(text) {
            let Some(number) = captures.get(1) else {
                continue;
            };
            let preceded = text[..number.start()].chars().next_back();
            if preceded.map(|character| character.is_ascii_alphanumeric()).unwrap_or(false) {
                continue;
            }
            let raw = number.as_str().replace(',', ".");
            let Ok(value) = raw.parse::<f64>() else {
                continue;
            };
            let unit = captures.get(2).map(|item| item.as_str()).unwrap_or("");
            if let Some(converted) = convert_unit_value(value, unit, &target_unit) {
                if !converted.is_empty() {
                    let numeric = converted.parse::<f64>().unwrap_or(0.0);
                    candidates.push((numeric, converted));
                }
            }
        }
        if !candidates.is_empty() {
            let mut best = &candidates[0];
            for candidate in &candidates[1..] {
                if candidate.0 > best.0 {
                    best = candidate;
                }
            }
            return best.1.clone();
        }
    }

    let plain = [
        re(&format!(
            r"(?i)(?:{alias})[^a-z0-9]{{0,10}}([-+]?\d+(?:[.,]\d+)?)"
        )),
        re(&format!(
            r"(?i)([-+]?\d+(?:[.,]\d+)?)[^\n,;|]{{0,20}}(?:{alias})\b"
        )),
    ];
    for pattern in &plain {
        let Some(captures) = pattern.captures(text) else {
            continue;
        };
        let Some(number) = captures.get(1) else {
            continue;
        };
        let raw = number.as_str().replace(',', ".");
        if let Ok(value) = raw.parse::<f64>() {
            return param_number(value);
        }
    }

    String::new()
}

/// `compozitImportExtractTextByDefinition` : texte associé à un paramètre.
fn extract_text_by_definition(text: &str, definition: &ParamDefinition) -> String {
    let alias = alias_pattern(definition);
    if alias.is_empty() {
        return String::new();
    }

    let patterns = [
        re(&format!(
            r"(?i)(?:{alias})\s*(?::|=|->|-)\s*([^\n,;|]{{1,40}})"
        )),
        re(&format!(
            r"(?i)(?:{alias})[^a-z0-9]{{1,6}}([a-z0-9][^\n,;|]{{1,32}})"
        )),
    ];

    for pattern in &patterns {
        let Some(captures) = pattern.captures(text) else {
            continue;
        };
        let Some(found) = captures.get(1) else {
            continue;
        };
        let value = found
            .as_str()
            .trim()
            .trim_matches(|character| character == ' ' || character == '.' || character == ':' || character == '-')
            .to_string();
        if !value.is_empty() {
            return value;
        }
    }

    String::new()
}

/// `compozitImportParseGenericParamsFromText`.
fn parse_generic_params(text: &str, definitions: &[ParamDefinition]) -> HashMap<String, String> {
    let mut values = HashMap::new();
    if text.is_empty() || definitions.is_empty() {
        return values;
    }

    for definition in definitions {
        let value = if definition.value_type == "number" {
            extract_number_by_definition(text, definition)
        } else {
            extract_text_by_definition(text, definition)
        };
        if !value.is_empty() {
            values.insert(definition.param_key.clone(), value);
        }
    }

    values
}

// --------------------------------------------------------------- Diodes

/// `compozitImportParseDiodeParamsFromText` : lecture spécialisée des diodes.
fn parse_diode_params(text: &str, subcategory_id: i64) -> HashMap<String, String> {
    let mut values: HashMap<String, String> = HashMap::new();
    if text.is_empty() {
        return values;
    }

    if subcategory_id != 406 {
        if regex_matches(r"\bsimple\b", text) {
            values.insert("polarity".to_string(), "Simple".to_string());
        } else if regex_matches(r"\b(double|dual)\b", text) {
            values.insert("polarity".to_string(), "Double".to_string());
        }
    }

    let milli_div = |value: f64, unit: &str| {
        if unit == "ma" || unit == "mw" {
            value / 1000.0
        } else {
            value
        }
    };

    let voltage = first_non_empty(&[
        extract_voltage(text, &["vrrm", "vr"]),
        parse_first_metric(text, &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*v\b")], None),
    ]);
    let current_a = first_non_empty(&[
        extract_current_amp(
            text,
            &[
                "if(av)",
                "if avg",
                "if average",
                "average forward current",
                "forward current",
                "io",
            ],
        ),
        parse_first_metric(
            text,
            &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*(ma|a)\b")],
            Some(&milli_div),
        ),
    ]);
    let power_w = first_non_empty(&[
        extract_power_w(text, &["power dissipation", "pd", "power"]),
        parse_first_metric(
            text,
            &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*(mw|w)\b")],
            Some(&milli_div),
        ),
    ]);
    let tolerance = extract_tolerance(text);
    let time_ns = first_non_empty(&[
        parse_first_metric(
            text,
            &[re(
                r"(?i)(?:trr|reverse recovery|recovery time)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*ns\b",
            )],
            None,
        ),
        parse_first_metric(text, &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*ns\b")], None),
    ]);
    let cap_pf = first_non_empty(&[
        parse_first_metric(
            text,
            &[re(
                r"(?i)(?:cj|junction capacitance|capacitance)[^\d]{0,12}(\d+(?:[.,]\d+)?)\s*pf\b",
            )],
            None,
        ),
        parse_first_metric(text, &[re(r"(?i)(\d+(?:[.,]\d+)?)\s*pf\b")], None),
    ]);
    let current_ua = extract_current_micro(text, &["ir", "reverse current", "leakage current"]);
    let vf = extract_vf(text);
    let ifsm = extract_ifsm(text);

    match subcategory_id {
        404 => {
            values.insert("vz".to_string(), voltage);
            values.insert("tolerance".to_string(), tolerance);
            values.insert("power".to_string(), power_w);
        }
        402 => {
            values.insert("vr".to_string(), voltage);
            values.insert("if_avg".to_string(), current_a);
            values.insert("vf".to_string(), vf);
            values.insert("ir".to_string(), current_ua);
        }
        403 => {
            values.insert("vr".to_string(), voltage);
            values.insert("if_avg".to_string(), current_a);
            values.insert("trr".to_string(), time_ns);
            values.insert("cj".to_string(), cap_pf);
        }
        406 => {
            values.insert("vrrm".to_string(), voltage);
            values.insert("if_av".to_string(), current_a);
            values.insert("ifsm".to_string(), ifsm);
            if regex_matches(r"3\s*-?\s*phase|triphase", text) {
                values.insert("phases".to_string(), "3".to_string());
            } else if regex_matches(r"1\s*-?\s*phase|monophase", text) {
                values.insert("phases".to_string(), "1".to_string());
            }
        }
        401 => {
            values.insert("vrrm".to_string(), voltage);
            values.insert("if_avg".to_string(), current_a);
            values.insert("ifsm".to_string(), ifsm);
            values.insert("vf".to_string(), vf);
            if regex_matches(r"ultra\s*fast", text) {
                values.insert("recovery".to_string(), "Ultra-fast".to_string());
            } else if regex_matches(r"\bfast\b", text) {
                values.insert("recovery".to_string(), "Fast".to_string());
            } else if regex_matches(r"standard", text) {
                values.insert("recovery".to_string(), "Standard".to_string());
            }
        }
        _ => {}
    }

    values.retain(|_, value| !value.trim().is_empty());
    values
}

/// `compozitImportParseParamsFromText` : diodes d'abord, valeurs génériques
/// ensuite (`$diode + $generic`).
pub fn parse_params_from_text(
    text: &str,
    subcategory_id: i64,
    definitions: &[ParamDefinition],
) -> HashMap<String, String> {
    let mut values = parse_diode_params(text, subcategory_id);
    for (key, value) in parse_generic_params(text, definitions) {
        values.entry(key).or_insert(value);
    }
    values
}

// --------------------------------------------- Recherche fournisseur

/// Références fournisseur déduites du commentaire (« FA : … », « MO : … »).
fn comment_ref_source_map() -> [(&'static str, &'static str); 18] {
    [
        ("FA", "farnell"),
        ("FARNELL", "farnell"),
        ("E14", "farnell"),
        ("NEWARK", "farnell"),
        ("MO", "mouser"),
        ("MOU", "mouser"),
        ("MOUSER", "mouser"),
        ("RS", "rs"),
        ("RSWWW", "rs"),
        ("RSONLINE", "rs"),
        ("RADIOSPARES", "rs"),
        ("TME", "tme"),
        ("LCSC", "lcsc"),
        ("DK", "digikey"),
        ("DIGIKEY", "digikey"),
        ("NX", "nexar"),
        ("NEXAR", "nexar"),
        ("OCTOPART", "nexar"),
    ]
}

fn comment_refs(comment: &str) -> Vec<(String, String)> {
    let comment = comment.trim();
    if comment.is_empty() {
        return Vec::new();
    }

    let map = comment_ref_source_map();
    let mut prefixes: Vec<&str> = map.iter().map(|(prefix, _)| *prefix).collect();
    prefixes.sort_by(|left, right| right.len().cmp(&left.len()));
    let alternation = prefixes
        .iter()
        .map(|prefix| regex::escape(prefix))
        .collect::<Vec<_>>()
        .join("|");
    let pattern = re(&format!(
        r"(?i)\b({alternation})\s*[:#=\-]?\s*([A-Za-z0-9][A-Za-z0-9\-\./]{{2,}})\b"
    ));

    let mut refs: Vec<(String, String)> = Vec::new();
    let mut seen: HashSet<String> = HashSet::new();
    for captures in pattern.captures_iter(comment) {
        let Some(prefix) = captures.get(1) else {
            continue;
        };
        let source = map
            .iter()
            .find(|(key, _)| *key == prefix.as_str().to_uppercase())
            .map(|(_, source)| *source);
        let Some(source) = source else {
            continue;
        };
        let reference = captures
            .get(2)
            .map(|item| item.as_str().trim().to_string())
            .unwrap_or_default();
        if reference.is_empty() {
            continue;
        }
        let key = format!("{source}|{}", reference.to_lowercase());
        if seen.insert(key) {
            refs.push((source.to_string(), reference));
        }
    }
    refs
}

/// Fournisseurs à interroger en priorité selon le distributeur enregistré.
fn preferred_sources(supplier_name: &str) -> Vec<Provider> {
    let name = normalize_text(supplier_name);
    if !name.is_empty() {
        if name.contains("mouser") {
            return vec![Provider::Mouser];
        }
        if name.contains("farnell") || name.contains("newark") || name.contains("element14") {
            return vec![Provider::Farnell];
        }
        if regex_matches(r"\btme\b", &name) {
            return vec![Provider::Tme];
        }
        if name.contains("lcsc") {
            return vec![Provider::Lcsc];
        }
        if regex_matches(r"\brs\b", &name) || name.contains("radios") {
            return vec![Provider::Rs];
        }
        if name.contains("digikey") {
            return vec![Provider::Digikey];
        }
        if name.contains("nexar") || name.contains("octopart") {
            return vec![Provider::Nexar];
        }
    }

    vec![
        Provider::Tme,
        Provider::Lcsc,
        Provider::Mouser,
        Provider::Farnell,
        Provider::Rs,
        Provider::Digikey,
    ]
}

/// Fiche fournisseur la plus proche de la requête (`compozitImportSelectPart`).
fn select_part(results: &[Part], query: &str) -> Option<Part> {
    let query_normalized = normalize_ref(query);
    for part in results {
        let mpn = normalize_ref(&part.mpn);
        if !query_normalized.is_empty() && !mpn.is_empty() && mpn == query_normalized {
            return Some(part.clone());
        }
    }
    results.first().cloned()
}

/// Texte d'une fiche fournisseur, prêt à être analysé.
fn part_text(part: &Part) -> String {
    let mut chunks = vec![
        part.category.clone(),
        part.description.clone(),
        part.mpn.clone(),
        part.package.clone(),
        part.datasheet.clone(),
    ];
    if let Some(offer) = &part.best_offer {
        chunks.push(offer.supplier_ref.clone());
        chunks.push(offer.supplier_name.clone());
    }
    normalize_text(
        &chunks
            .into_iter()
            .filter(|chunk| !chunk.trim().is_empty())
            .collect::<Vec<_>>()
            .join(" "),
    )
}

// ---------------------------------------------------- Enrichissement

/// Ligne d'un composant à enrichir, jointures comprises.
#[derive(Debug, FromRow)]
struct EnrichComponent {
    id: i64,
    category: i64,
    name: Option<String>,
    manufacturer: Option<String>,
    package: Option<String>,
    comment: Option<String>,
    supplier_reference: Option<String>,
    datasheet: Option<String>,
    subcategory_name: Option<String>,
    category_name: Option<String>,
    supplier_name: Option<String>,
}

/// `compozitImportComponentText` : texte agrégé décrivant le composant.
fn component_text(component: &EnrichComponent) -> String {
    let parts = [
        component.category_name.as_deref(),
        component.subcategory_name.as_deref(),
        component.name.as_deref(),
        component.manufacturer.as_deref(),
        component.package.as_deref(),
        component.comment.as_deref(),
        component.supplier_reference.as_deref(),
        component.datasheet.as_deref(),
    ];
    normalize_text(
        &parts
            .into_iter()
            .flatten()
            .filter(|value| !value.trim().is_empty())
            .collect::<Vec<_>>()
            .join(" "),
    )
}

/// `compozitImportFindPartForComponent` : fiche fournisseur par commentaire,
/// puis par recherche générique.
async fn find_part_for_component(
    client: &reqwest::Client,
    root: &Path,
    config: &ApiConfig,
    component: &EnrichComponent,
) -> Option<Part> {
    let refs = comment_refs(component.comment.as_deref().unwrap_or(""));

    let manufacturer = component.manufacturer.clone().unwrap_or_default();
    let name = component.name.clone().unwrap_or_default();
    let mut generic_queries: Vec<String> = Vec::new();
    for query in [
        component
            .supplier_reference
            .clone()
            .unwrap_or_default()
            .trim()
            .to_string(),
        name.trim().to_string(),
        format!("{} {}", manufacturer.trim(), name.trim()).trim().to_string(),
    ] {
        if query.is_empty() || query.chars().count() < 2 || generic_queries.contains(&query) {
            continue;
        }
        generic_queries.push(query);
    }

    let preferred = preferred_sources(component.supplier_name.as_deref().unwrap_or(""));

    let mut attempts: Vec<(Provider, String)> = Vec::new();
    let mut seen: HashSet<String> = HashSet::new();
    {
        let mut add = |provider: Provider, query: &str| {
            let query = query.trim();
            if query.chars().count() < 2 {
                return;
            }
            let key = format!("{}|{}", provider.key(), normalize_ref(query));
            if key == format!("{}|", provider.key()) || seen.contains(&key) {
                return;
            }
            seen.insert(key);
            attempts.push((provider, query.to_string()));
        };
        for (source, reference) in &refs {
            if let Some(provider) = Provider::from_key(source) {
                add(provider, reference);
            }
        }
        for provider in &preferred {
            for query in &generic_queries {
                add(*provider, query);
            }
        }
    }

    for (provider, query) in &attempts {
        let outcome = api::search(client, root, config, *provider, query, Some(3)).await;
        if !outcome.ok || outcome.results.is_empty() {
            continue;
        }
        if let Some(part) = select_part(&outcome.results, query) {
            return Some(part);
        }
    }

    None
}

/// `compozitImportSaveMissingParams` : insère les seules valeurs absentes.
async fn save_missing_params(
    pool: &MySqlPool,
    data_id: i64,
    definitions: &[ParamDefinition],
    values: &HashMap<String, String>,
    existing: &HashMap<String, String>,
) -> Result<usize, sqlx::Error> {
    if definitions.is_empty() || values.is_empty() {
        return Ok(0);
    }

    let by_key: HashMap<&str, &ParamDefinition> = definitions
        .iter()
        .map(|definition| (definition.param_key.as_str(), definition))
        .collect();

    let upsert = "INSERT INTO `data_params` (`data_id`, `param_id`, `value_num`, `value_txt`) \
                  VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE \
                  `value_num` = IFNULL(`data_params`.`value_num`, VALUES(`value_num`)), \
                  `value_txt` = IFNULL(`data_params`.`value_txt`, VALUES(`value_txt`))";

    let mut saved = 0usize;
    for (key, raw_value) in values {
        let Some(definition) = by_key.get(key.as_str()) else {
            continue;
        };

        let current = existing
            .get(key)
            .map(|value| value.trim().to_string())
            .unwrap_or_default();
        if !current.is_empty() {
            continue;
        }

        if definition.value_type == "number" {
            let normalized = raw_value.trim().replace(',', ".");
            let Ok(value) = normalized.parse::<f64>() else {
                continue;
            };
            sqlx::query(upsert)
                .bind(data_id)
                .bind(definition.id)
                .bind(value)
                .bind(Option::<String>::None)
                .execute(pool)
                .await?;
        } else {
            let value = raw_value.trim();
            if value.is_empty() {
                continue;
            }
            sqlx::query(upsert)
                .bind(data_id)
                .bind(definition.id)
                .bind(Option::<f64>::None)
                .bind(value)
                .execute(pool)
                .await?;
        }

        saved += 1;
    }

    Ok(saved)
}

/// Bilan d'un lot d'enrichissement.
#[derive(Debug, Default, Clone)]
pub struct EnrichStats {
    pub checked: usize,
    pub enriched: usize,
    pub params_added: usize,
    pub not_found: usize,
    pub without_definitions: usize,
    pub retry_ids: Vec<i64>,
}

/// `compozitEnrichParamsBatch` : enrichit un lot de composants sans écraser les
/// valeurs existantes.
pub async fn enrich_batch(
    pool: &MySqlPool,
    root: &Path,
    config: &ApiConfig,
    client: &reqwest::Client,
    owner: i64,
    ids: &[i64],
) -> Result<EnrichStats, sqlx::Error> {
    let mut stats = EnrichStats::default();

    let mut ids: Vec<i64> = ids.iter().copied().filter(|id| *id != 0).collect();
    ids.sort_unstable();
    ids.dedup();
    if ids.is_empty() {
        return Ok(stats);
    }

    let mut builder = QueryBuilder::new(
        "SELECT d.id, d.category, d.name, d.manufacturer, d.package, d.comment, \
         d.supplier_reference, d.datasheet, cs.name AS subcategory_name, \
         ch.name AS category_name, s.name AS supplier_name \
         FROM data d \
         LEFT JOIN category_sub cs ON cs.id = d.category \
         LEFT JOIN category_head ch ON ch.id = cs.category_head_id \
         LEFT JOIN suppliers s ON s.id = d.supplier_id \
         WHERE d.owner = ",
    );
    builder.push_bind(owner);
    builder.push(" AND d.id IN (");
    let mut separated = builder.separated(", ");
    for id in &ids {
        separated.push_bind(*id);
    }
    separated.push_unseparated(") ORDER BY d.id ASC");

    let components: Vec<EnrichComponent> = builder.build_query_as().fetch_all(pool).await?;

    for component in components {
        stats.checked += 1;

        let definitions = match params::definitions_for_subcategory(pool, component.category).await {
            Ok(definitions) => definitions,
            Err(error) => {
                tracing::warn!(
                    "Définitions indisponibles (sous-catégorie {}) : {error}",
                    component.category
                );
                Vec::new()
            }
        };
        if definitions.is_empty() {
            stats.without_definitions += 1;
            continue;
        }

        let existing = params::component_values(pool, component.id)
            .await
            .unwrap_or_default();
        let missing: HashSet<String> = definitions
            .iter()
            .filter(|definition| {
                existing
                    .get(&definition.param_key)
                    .map(|value| value.trim().is_empty())
                    .unwrap_or(true)
            })
            .map(|definition| definition.param_key.clone())
            .collect();
        if missing.is_empty() {
            continue;
        }

        let mut found: HashMap<String, String> =
            parse_params_from_text(&component_text(&component), component.category, &definitions)
                .into_iter()
                .filter(|(key, _)| missing.contains(key))
                .collect();

        if found.len() < missing.len() {
            if let Some(part) = find_part_for_component(client, root, config, &component).await {
                let api_values: HashMap<String, String> = parse_params_from_text(
                    &part_text(&part),
                    component.category,
                    &definitions,
                )
                .into_iter()
                .filter(|(key, _)| missing.contains(key))
                .collect();
                for (key, value) in api_values {
                    found.entry(key).or_insert(value);
                }
            }
        }

        let saved = save_missing_params(pool, component.id, &definitions, &found, &existing).await?;
        if saved > 0 {
            stats.enriched += 1;
            stats.params_added += saved;
        }
        if found.len() < missing.len() {
            stats.retry_ids.push(component.id);
            stats.not_found += 1;
        }
    }

    Ok(stats)
}
