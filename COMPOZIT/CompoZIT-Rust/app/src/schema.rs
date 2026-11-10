//! Migrations de schéma de la base MySQL.
//!
//! Reprend fidèlement le travail de la version PHP
//! (`ensureDataMarkingColumn()`, `compozitEnsureParamTables()` et
//! `compozitSyncParamDefinitions()` de `config.php` / `component_params.php`) :
//! la colonne `marking` de la table `data`, les tables `param_definitions` et
//! `data_params`, puis le référentiel des paramètres techniques.
//!
//! Ainsi les deux applications — PHP et Rust — partagent exactement le même
//! schéma, et l'ajout d'un composant depuis l'exécutable produit les mêmes
//! enregistrements que depuis le site.

use sqlx::{MySqlPool, QueryBuilder};

/// Un paramètre technique tel qu'il est déclaré dans le référentiel PHP :
/// (clé, libellé, unité, type de valeur, mode de comparaison, ordre de tri).
type ParamDef = (
    &'static str,
    &'static str,
    Option<&'static str>,
    &'static str,
    &'static str,
    i32,
);

/// (catégorie, sous-catégories concernées, définitions à appliquer).
/// Transcription de `compozitParamSeed()`.
const SEED: &[(i32, &[i32], &[ParamDef])] = &[
    // ------------------------------------------------------------- Câbles
    (
        1,
        &[101, 102, 104, 105, 106, 107],
        &[
            ("length", "Longueur", Some("m"), "number", "=", 10),
            ("awg", "AWG", None, "number", "=", 20),
            ("conductors", "Conducteurs", None, "number", "=", 30),
            ("color", "Couleur", None, "text", "=", 40),
        ],
    ),
    (
        1,
        &[101],
        &[
            ("pitch", "Pitch", Some("mm"), "number", "=", 50),
            ("ways", "Voies", None, "number", "=", 60),
        ],
    ),
    (
        1,
        &[102],
        &[
            ("impedance", "Impédance", Some("ohm"), "number", "=", 50),
            ("attenuation", "Atténuation", Some("dB/m"), "number", "<=", 60),
            ("freq_max", "Fréq. max", Some("MHz"), "number", ">=", 70),
        ],
    ),
    (
        1,
        &[107],
        &[
            ("end_a", "Connecteur A", None, "text", "=", 50),
            ("end_b", "Connecteur B", None, "text", "=", 60),
            ("ways", "Voies", None, "number", "=", 70),
        ],
    ),
    (
        1,
        &[106],
        &[
            ("mode", "Mode", None, "text", "=", 50),
            ("connector", "Connecteur", None, "text", "=", 60),
        ],
    ),
    (
        1,
        &[104, 105],
        &[
            ("connector_a", "Connecteur A", None, "text", "=", 50),
            ("connector_b", "Connecteur B", None, "text", "=", 60),
            ("version", "Version", None, "text", "=", 70),
        ],
    ),
    // ------------------------------------------------------ Condensateurs
    (
        2,
        &[201, 202, 203, 204, 205],
        &[
            ("capacitance", "Capacité", Some("uF"), "number", "=", 10),
            ("voltage", "Tension", Some("V"), "number", ">=", 20),
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 30),
            ("pitch", "Pitch", Some("mm"), "number", "=", 40),
        ],
    ),
    (2, &[201], &[("dielectric", "Diélectrique", None, "text", "=", 50)]),
    (
        2,
        &[202],
        &[
            ("temp_max", "Temp. max", Some("°C"), "number", ">=", 50),
            ("esr", "ESR", Some("ohm"), "number", "<=", 60),
            ("ripple", "Ripple", Some("A"), "number", ">=", 70),
            ("diameter", "Diamètre", Some("mm"), "number", "=", 80),
            ("height", "Hauteur", Some("mm"), "number", "=", 90),
        ],
    ),
    (
        2,
        &[203],
        &[
            ("voltage_ac", "Tension AC", Some("V"), "number", ">=", 50),
            ("material", "Matériau", None, "text", "=", 60),
        ],
    ),
    (
        2,
        &[204],
        &[
            ("esr", "ESR", Some("ohm"), "number", "<=", 50),
            ("case_code", "Code boîtier", None, "text", "=", 60),
            ("polarized", "Polarisé", None, "text", "=", 70),
        ],
    ),
    (
        2,
        &[205],
        &[
            ("cap_min", "Capacité min", Some("uF"), "number", "=", 50),
            ("cap_max", "Capacité max", Some("uF"), "number", "=", 60),
        ],
    ),
    // -------------------------------------------------------- Connecteurs
    (
        3,
        &[301, 302, 303, 304, 305, 306],
        &[
            ("contacts", "Contacts", None, "number", "=", 10),
            ("pitch", "Pitch", Some("mm"), "number", "=", 20),
            ("gender", "Genre", None, "text", "=", 30),
            ("mount", "Montage", None, "text", "=", 40),
            ("current", "Courant", Some("A"), "number", ">=", 50),
            ("voltage", "Tension", Some("V"), "number", ">=", 60),
            ("rows", "Rangées", None, "number", "=", 70),
        ],
    ),
    (
        3,
        &[301],
        &[
            ("type", "Type", None, "text", "=", 80),
            ("stereo", "Stéréo", None, "text", "=", 90),
        ],
    ),
    (
        3,
        &[303],
        &[
            ("od", "Diamètre ext.", Some("mm"), "number", "=", 80),
            ("id", "Diamètre int.", Some("mm"), "number", "=", 90),
        ],
    ),
    (3, &[304], &[("shell_size", "Taille shell", None, "text", "=", 80)]),
    (
        3,
        &[302, 305],
        &[
            ("type", "Type", None, "text", "=", 80),
            ("impedance", "Impédance", Some("ohm"), "number", "=", 90),
            ("freq_max", "Fréq. max", Some("MHz"), "number", ">=", 100),
        ],
    ),
    (3, &[306], &[("series", "Série", None, "text", "=", 80)]),
    // ------------------------------------------------------------- Diodes
    (
        4,
        &[401],
        &[
            ("polarity", "Polarité", None, "text", "=", 10),
            ("vrrm", "VRRM", Some("V"), "number", ">=", 20),
            ("if_avg", "IF moyen", Some("A"), "number", ">=", 30),
            ("ifsm", "IFSM", Some("A"), "number", ">=", 40),
            ("vf", "VF", Some("V"), "number", "<=", 50),
            ("recovery", "Recovery", None, "text", "=", 60),
        ],
    ),
    (
        4,
        &[402],
        &[
            ("polarity", "Polarité", None, "text", "=", 10),
            ("vr", "VR", Some("V"), "number", ">=", 20),
            ("if_avg", "IF moyen", Some("A"), "number", ">=", 30),
            ("vf", "VF", Some("V"), "number", "<=", 40),
            ("ir", "IR", Some("uA"), "number", "<=", 50),
        ],
    ),
    (
        4,
        &[403],
        &[
            ("polarity", "Polarité", None, "text", "=", 10),
            ("vr", "VR", Some("V"), "number", ">=", 20),
            ("if_avg", "IF moyen", Some("A"), "number", ">=", 30),
            ("trr", "TRR", Some("ns"), "number", "<=", 40),
            ("cj", "Capacité CJ", Some("pF"), "number", "<=", 50),
        ],
    ),
    (
        4,
        &[404],
        &[
            ("polarity", "Polarité", None, "text", "=", 10),
            ("vz", "VZ", Some("V"), "number", "=", 20),
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 30),
            ("power", "Puissance", Some("W"), "number", ">=", 40),
        ],
    ),
    (
        4,
        &[406],
        &[
            ("vrrm", "VRRM", Some("V"), "number", ">=", 10),
            ("if_av", "IF(AV)", Some("A"), "number", ">=", 20),
            ("ifsm", "IFSM", Some("A"), "number", ">=", 30),
            ("phases", "Phases", None, "number", "=", 40),
        ],
    ),
    // -------------------------------------------------- Circuits intégrés
    (
        5,
        &[501, 502, 517],
        &[
            ("function", "Fonction", None, "text", "=", 10),
            ("family", "Famille", None, "text", "=", 20),
            ("vcc_min", "VCC min", Some("V"), "number", "<=", 30),
            ("vcc_max", "VCC max", Some("V"), "number", ">=", 40),
            ("gates", "Portes", None, "number", "=", 50),
        ],
    ),
    (
        5,
        &[503],
        &[
            ("core", "Core", None, "text", "=", 10),
            ("flash", "Flash", Some("KB"), "number", ">=", 20),
            ("ram", "RAM", Some("KB"), "number", ">=", 30),
            ("freq", "Fréquence", Some("MHz"), "number", ">=", 40),
            ("io", "IO", None, "number", ">=", 50),
            ("vcc_min", "VCC min", Some("V"), "number", "<=", 60),
            ("vcc_max", "VCC max", Some("V"), "number", ">=", 70),
        ],
    ),
    (
        5,
        &[504, 505],
        &[
            ("channels", "Canaux", None, "number", "=", 10),
            ("vcc_min", "VCC min", Some("V"), "number", "<=", 20),
            ("vcc_max", "VCC max", Some("V"), "number", ">=", 30),
            ("gbw", "GBW", Some("MHz"), "number", ">=", 40),
            ("slew", "Slew rate", Some("V/us"), "number", ">=", 50),
            ("offset", "Offset", Some("mV"), "number", "<=", 60),
            ("rail_to_rail", "Rail-to-rail", None, "text", "=", 70),
        ],
    ),
    (
        5,
        &[506],
        &[
            ("output", "Sortie", None, "text", "=", 10),
            ("accuracy", "Précision", Some("°C"), "number", "<=", 20),
        ],
    ),
    (
        5,
        &[507],
        &[
            ("type", "Type", None, "text", "=", 10),
            ("freq_max", "Fréq. max", Some("MHz"), "number", ">=", 20),
        ],
    ),
    (
        5,
        &[508],
        &[
            ("vout", "Vout", Some("V"), "number", "=", 10),
            ("accuracy", "Précision", Some("%"), "number", "<=", 20),
            ("drift", "Drift", Some("ppm/°C"), "number", "<=", 30),
            ("topology", "Topologie", None, "text", "=", 40),
        ],
    ),
    (
        5,
        &[509],
        &[
            ("vout", "Vout", Some("V"), "number", "=", 10),
            ("iout", "Iout", Some("A"), "number", ">=", 20),
            ("vin_max", "Vin max", Some("V"), "number", ">=", 30),
            ("dropout", "Dropout", Some("V"), "number", "<=", 40),
            ("iq", "Iq", Some("mA"), "number", "<=", 50),
            ("polarity", "Polarité", None, "text", "=", 60),
        ],
    ),
    (
        5,
        &[510],
        &[
            ("bits", "Bits", None, "number", ">=", 10),
            ("sps", "SPS", None, "number", ">=", 20),
            ("channels", "Canaux", None, "number", ">=", 30),
            ("interface", "Interface", None, "text", "=", 40),
        ],
    ),
    (
        5,
        &[511],
        &[
            ("channels", "Canaux", None, "number", ">=", 10),
            ("ron", "R_on", Some("ohm"), "number", "<=", 20),
        ],
    ),
    (
        5,
        &[512, 513],
        &[
            ("type", "Type", None, "text", "=", 10),
            ("iout", "Iout", Some("A"), "number", ">=", 20),
            ("vmax", "Vmax", Some("V"), "number", ">=", 30),
        ],
    ),
    (
        5,
        &[514],
        &[
            ("topology", "Topologie", None, "text", "=", 10),
            ("vin_min", "Vin min", Some("V"), "number", "<=", 20),
            ("vin_max", "Vin max", Some("V"), "number", ">=", 30),
            ("vout", "Vout", Some("V"), "number", "=", 40),
            ("iout", "Iout", Some("A"), "number", ">=", 50),
            ("efficiency", "Rendement", Some("%"), "number", ">=", 60),
        ],
    ),
    (
        5,
        &[515],
        &[
            ("type", "Type", None, "text", "=", 10),
            ("power", "Puissance", Some("W"), "number", ">=", 20),
            ("load", "Charge", Some("ohm"), "number", "=", 30),
        ],
    ),
    (
        5,
        &[516],
        &[
            ("mem_type", "Type mémoire", None, "text", "=", 10),
            ("capacity", "Capacité", Some("KB"), "number", ">=", 20),
            ("interface", "Interface", None, "text", "=", 30),
            ("vcc", "VCC", Some("V"), "number", "=", 40),
        ],
    ),
    // -------------------------------------------------------- Inductances
    (
        6,
        &[603],
        &[
            ("inductance", "Inductance", Some("uH"), "number", "=", 10),
            ("isat", "Isat", Some("A"), "number", ">=", 20),
            ("irms", "Irms", Some("A"), "number", ">=", 30),
            ("dcr", "DCR", Some("ohm"), "number", "<=", 40),
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 50),
            ("shielded", "Blindée", None, "text", "=", 60),
        ],
    ),
    (
        6,
        &[601],
        &[
            ("z_100mhz", "Z @100MHz", Some("ohm"), "number", "=", 10),
            ("current", "Courant", Some("A"), "number", ">=", 20),
            ("dcr", "DCR", Some("ohm"), "number", "<=", 30),
        ],
    ),
    (
        6,
        &[602],
        &[
            ("type", "Type", None, "text", "=", 10),
            ("inductance", "Inductance", Some("uH"), "number", "=", 20),
            ("current", "Courant", Some("A"), "number", ">=", 30),
        ],
    ),
    // --------------------------------------------------------- Mécanique
    (
        7,
        &[701],
        &[
            ("dim_l", "Longueur", Some("mm"), "number", "=", 10),
            ("dim_w", "Largeur", Some("mm"), "number", "=", 20),
            ("dim_h", "Hauteur", Some("mm"), "number", "=", 30),
            ("ip", "Indice IP", None, "text", "=", 40),
        ],
    ),
    (
        7,
        &[702],
        &[
            ("length", "Longueur", Some("mm"), "number", "=", 10),
            ("thread", "Filetage", None, "text", "=", 20),
            ("material", "Matériau", None, "text", "=", 30),
            ("gender", "Genre", None, "text", "=", 40),
        ],
    ),
    (
        7,
        &[703, 905],
        &[
            ("fuse_format", "Format fusible", None, "text", "=", 10),
            ("current", "Courant", Some("A"), "number", ">=", 20),
        ],
    ),
    (
        7,
        &[704],
        &[
            ("motor_type", "Type moteur", None, "text", "=", 10),
            ("voltage", "Tension", Some("V"), "number", "=", 20),
            ("current", "Courant", Some("A"), "number", ">=", 30),
            ("torque", "Couple", Some("Nm"), "number", ">=", 40),
            ("shaft", "Arbre", Some("mm"), "number", "=", 50),
        ],
    ),
    (
        7,
        &[705],
        &[
            ("coil_voltage", "Tension bobine", Some("V"), "number", "=", 10),
            ("contact_current", "Courant contact", Some("A"), "number", ">=", 20),
            ("contact_voltage", "Tension contact", Some("V"), "number", ">=", 30),
            ("config", "Configuration", None, "text", "=", 40),
        ],
    ),
    (
        7,
        &[708],
        &[
            ("pins", "Pins", None, "number", "=", 10),
            ("pitch", "Pitch", Some("mm"), "number", "=", 20),
        ],
    ),
    (
        7,
        &[709],
        &[
            ("rth", "Rth", Some("°C/W"), "number", "<=", 10),
            ("target_package", "Package cible", None, "text", "=", 20),
        ],
    ),
    (
        7,
        &[710],
        &[
            ("shaft", "Arbre", Some("mm"), "number", "=", 10),
            ("diameter", "Diamètre", Some("mm"), "number", "=", 20),
        ],
    ),
    // --------------------------------------------------------- Opto / LED
    (
        8,
        &[803, 804, 805],
        &[
            ("color", "Couleur", None, "text", "=", 10),
            ("wavelength", "Longueur d’onde", Some("nm"), "number", "=", 20),
            ("intensity", "Intensité", Some("mcd"), "number", ">=", 30),
            ("vf", "VF", Some("V"), "number", "<=", 40),
            ("if", "IF", Some("A"), "number", ">=", 50),
            ("view_angle", "Angle", Some("°"), "number", ">=", 60),
        ],
    ),
    (
        8,
        &[807],
        &[
            ("wavelength", "Longueur d’onde", Some("nm"), "number", "=", 10),
            ("if", "IF", Some("A"), "number", ">=", 20),
            ("view_angle", "Angle", Some("°"), "number", "=", 30),
        ],
    ),
    (
        8,
        &[802],
        &[
            ("wavelength", "Longueur d’onde", Some("nm"), "number", "=", 10),
            ("power", "Puissance", Some("mW"), "number", ">=", 20),
            ("voltage", "Tension", Some("V"), "number", "=", 30),
        ],
    ),
    (
        8,
        &[806],
        &[
            ("ctr", "CTR", Some("%"), "number", ">=", 10),
            ("viso", "V iso", Some("V"), "number", ">=", 20),
            ("vceo", "VCEO", Some("V"), "number", ">=", 30),
            ("output_type", "Type sortie", None, "text", "=", 40),
            ("channels", "Canaux", None, "number", "=", 50),
        ],
    ),
    (
        8,
        &[801],
        &[
            ("gap", "Gap", Some("mm"), "number", "=", 10),
            ("output_type", "Type sortie", None, "text", "=", 20),
        ],
    ),
    (
        8,
        &[808],
        &[
            ("voltage", "Tension", Some("V"), "number", "=", 10),
            ("power", "Puissance", Some("W"), "number", ">=", 20),
            ("base", "Culot", None, "text", "=", 30),
        ],
    ),
    // -------------------------------------------------------- Protections
    (
        9,
        &[901],
        &[
            ("current", "Courant", Some("A"), "number", "=", 10),
            ("voltage", "Tension", Some("V"), "number", ">=", 20),
            ("speed", "Vitesse", None, "text", "=", 30),
            ("breaking", "Pouvoir de coupure", Some("A"), "number", ">=", 40),
            ("format", "Format", None, "text", "=", 50),
        ],
    ),
    (
        9,
        &[902],
        &[
            ("v_varistor", "Tension varistor", Some("V"), "number", "=", 10),
            ("vac_max", "VAC max", Some("V"), "number", ">=", 20),
            ("energy", "Énergie", Some("J"), "number", ">=", 30),
            ("diameter", "Diamètre", Some("mm"), "number", "=", 40),
        ],
    ),
    (
        9,
        &[903],
        &[
            ("r25", "R25", Some("ohm"), "number", "=", 10),
            ("b_value", "B value", Some("K"), "number", "=", 20),
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 30),
            ("power", "Puissance", Some("W"), "number", ">=", 40),
        ],
    ),
    (
        9,
        &[904],
        &[
            ("r25", "R25", Some("ohm"), "number", "=", 10),
            ("hold_current", "Courant hold", Some("A"), "number", ">=", 20),
            ("trip_current", "Courant trip", Some("A"), "number", ">=", 30),
            ("vmax", "Vmax", Some("V"), "number", ">=", 40),
        ],
    ),
    // --------------------------------------------- Interrupteurs, boutons
    (
        10,
        &[1001, 1002, 1004, 1005, 1007],
        &[
            ("poles", "Pôles", None, "number", "=", 10),
            ("throws", "Positions", None, "number", "=", 20),
            ("current", "Courant", Some("A"), "number", ">=", 30),
            ("voltage", "Tension", Some("V"), "number", ">=", 40),
            ("mount", "Montage", None, "text", "=", 50),
            ("actuation", "Actionnement", None, "text", "=", 60),
        ],
    ),
    (10, &[1001], &[("keys", "Touches", None, "number", "=", 70)]),
    (
        10,
        &[1002, 1005],
        &[
            ("normally", "Normalement", None, "text", "=", 70),
            ("lighted", "Éclairé", None, "text", "=", 80),
        ],
    ),
    (
        10,
        &[1004],
        &[
            ("ppr", "PPR", None, "number", "=", 70),
            ("detents", "Detents", None, "number", "=", 80),
            ("has_switch", "Avec switch", None, "text", "=", 90),
        ],
    ),
    (
        10,
        &[1007],
        &[
            ("positions", "Positions", None, "number", "=", 70),
            ("pitch", "Pitch", Some("mm"), "number", "=", 80),
        ],
    ),
    // --------------------------------------------- Régulateurs / Transfo
    (
        11,
        &[1101],
        &[
            ("type", "Type", None, "text", "=", 10),
            ("power", "Puissance", Some("W"), "number", ">=", 20),
            ("vout", "Vout", Some("V"), "number", "=", 30),
            ("iout", "Iout", Some("A"), "number", ">=", 40),
        ],
    ),
    (
        11,
        &[1102],
        &[
            ("v_primary", "Primaire", Some("V"), "number", "=", 10),
            ("v_secondary", "Secondaire", Some("V"), "number", "=", 20),
            ("va", "VA", Some("VA"), "number", ">=", 30),
            ("freq", "Fréquence", Some("Hz"), "number", "=", 40),
        ],
    ),
    (
        11,
        &[1103],
        &[
            ("topology", "Topologie", None, "text", "=", 10),
            ("vin_min", "Vin min", Some("V"), "number", "<=", 20),
            ("vin_max", "Vin max", Some("V"), "number", ">=", 30),
            ("vout", "Vout", Some("V"), "number", "=", 40),
            ("iout", "Iout", Some("A"), "number", ">=", 50),
            ("efficiency", "Rendement", Some("%"), "number", ">=", 60),
        ],
    ),
    // -------------------------------------------------------- Transistors
    (
        12,
        &[1204, 1205, 1201],
        &[
            ("vceo", "VCEO", Some("V"), "number", ">=", 10),
            ("ic", "IC", Some("A"), "number", ">=", 20),
            ("ptot", "Ptot", Some("W"), "number", ">=", 30),
            ("hfe", "HFE", None, "number", ">=", 40),
            ("ft", "Ft", Some("MHz"), "number", ">=", 50),
            ("pinout", "Pinout", None, "text", "=", 60),
        ],
    ),
    (
        12,
        &[1208, 1209],
        &[
            ("vds", "VDS", Some("V"), "number", ">=", 10),
            ("id", "ID", Some("A"), "number", ">=", 20),
            ("rds_on", "RDS(on)", Some("ohm"), "number", "<=", 30),
            ("vgs_th", "VGS(th)", Some("V"), "number", "<=", 40),
            ("vgs_max", "VGS max", Some("V"), "number", ">=", 50),
            ("qg", "Qg", Some("nC"), "number", "<=", 60),
            ("logic_level", "Logic level", None, "text", "=", 70),
        ],
    ),
    (
        12,
        &[1202],
        &[
            ("channel", "Canal", None, "text", "=", 10),
            ("vds", "VDS", Some("V"), "number", ">=", 20),
            ("idss", "IDSS", Some("A"), "number", "=", 30),
            ("vp", "VP", Some("V"), "number", "=", 40),
        ],
    ),
    (
        12,
        &[1206, 1207],
        &[
            ("vdrm", "VDRM", Some("V"), "number", ">=", 10),
            ("it", "IT", Some("A"), "number", ">=", 20),
            ("igt", "IGT", Some("mA"), "number", "<=", 30),
        ],
    ),
    // -------------------------------------------------------- Résistances
    (
        13,
        &[1301, 1302, 1303, 1304, 1305, 1306, 1307, 1308, 1309, 1310, 1311, 1312, 1313, 1314, 1315],
        &[
            ("resistance", "Résistance", Some("ohm"), "number", "=", 10),
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 20),
            ("power", "Puissance", Some("W"), "number", ">=", 30),
        ],
    ),
    (
        13,
        &[1301, 1302, 1303, 1304, 1313, 1314],
        &[("tcr", "TCR", Some("ppm/°C"), "number", "<=", 40)],
    ),
    (
        13,
        &[1305, 1306, 1307],
        &[
            ("tcr", "TCR", Some("ppm/°C"), "number", "<=", 40),
            ("voltage_max", "Tension max", Some("V"), "number", ">=", 50),
        ],
    ),
    (13, &[1315], &[("tcr", "TCR", Some("ppm/°C"), "number", "<=", 40)]),
    (
        13,
        &[1310],
        &[
            ("elements", "Éléments", None, "number", "=", 40),
            ("config", "Configuration", None, "text", "=", 50),
        ],
    ),
    (
        13,
        &[1312],
        &[
            ("taper", "Taper", None, "text", "=", 40),
            ("turns", "Tours", None, "number", "=", 50),
            ("shaft", "Arbre", Some("mm"), "number", "=", 60),
        ],
    ),
    (
        13,
        &[1309],
        &[
            ("r_light", "R lumière", Some("ohm"), "number", "=", 40),
            ("r_dark", "R obscurité", Some("ohm"), "number", "=", 50),
        ],
    ),
    (
        13,
        &[1311],
        &[
            ("r25", "R25", Some("ohm"), "number", "=", 40),
            ("b_value", "B value", Some("K"), "number", "=", 50),
        ],
    ),
    (
        13,
        &[1308],
        &[
            ("tolerance", "Tolérance", Some("%"), "number", "<=", 40),
            ("power", "Puissance", Some("W"), "number", ">=", 50),
        ],
    ),
    // ------------------------------------------------------------ Écrans
    (
        14,
        &[1401, 1402, 1403, 1404],
        &[
            ("interface", "Interface", None, "text", "=", 10),
            ("resolution", "Résolution", None, "text", "=", 20),
            ("vcc", "VCC", Some("V"), "number", "=", 30),
            ("size", "Taille", Some("in"), "number", "=", 40),
        ],
    ),
    (
        14,
        &[1401],
        &[
            ("chars", "Caractères", None, "text", "=", 50),
            ("backlight", "Rétroéclairage", None, "text", "=", 60),
            ("controller", "Contrôleur", None, "text", "=", 70),
        ],
    ),
    (
        14,
        &[1403],
        &[
            ("controller", "Contrôleur", None, "text", "=", 50),
            ("touch", "Tactile", None, "text", "=", 60),
        ],
    ),
    (
        14,
        &[1402, 1404],
        &[
            ("color", "Couleur", None, "text", "=", 50),
            ("digits", "Digits", None, "number", "=", 60),
        ],
    ),
    // ---------------------------------------------------------- Capteurs
    (
        15,
        &[1501, 1502, 1503, 1504, 1505, 1506, 1507, 1508, 1509],
        &[
            ("interface", "Interface", None, "text", "=", 10),
            ("vcc", "VCC", Some("V"), "number", "=", 20),
            ("range_min", "Plage min", None, "number", "=", 30),
            ("range_max", "Plage max", None, "number", "=", 40),
            ("accuracy", "Précision", None, "number", "<=", 50),
        ],
    ),
    (
        15,
        &[1504, 1505],
        &[
            ("output", "Sortie", None, "text", "=", 60),
            ("sensitivity", "Sensibilité", None, "number", "=", 70),
        ],
    ),
    (15, &[1506], &[("gas", "Gaz", None, "text", "=", 60)]),
    (
        15,
        &[1507],
        &[
            ("axes", "Axes", None, "number", "=", 60),
            ("range_g", "Plage g", Some("g"), "number", "=", 70),
        ],
    ),
    (
        15,
        &[1508, 1509],
        &[
            ("wavelength", "Longueur d’onde", Some("nm"), "number", "=", 60),
            ("range", "Plage", None, "number", "=", 70),
        ],
    ),
    // ----------------------------------------------------------- Modules
    (
        16,
        &[1601, 1602, 1603, 1604, 1605, 1606, 1607, 1608, 1609],
        &[
            ("vcc", "VCC", Some("V"), "number", "=", 10),
            ("logic_level", "Logic level", Some("V"), "number", "=", 20),
            ("interface", "Interface", None, "text", "=", 30),
            ("pinout", "Pinout", None, "text", "=", 40),
        ],
    ),
    (16, &[1601], &[("chip", "Chip", None, "text", "=", 50)]),
    (
        16,
        &[1602],
        &[
            ("chip", "Chip", None, "text", "=", 50),
            ("bt_version", "Version BT", None, "text", "=", 60),
        ],
    ),
    (
        16,
        &[1603, 1604],
        &[
            ("chip", "Chip", None, "text", "=", 50),
            ("current", "Courant", Some("A"), "number", ">=", 60),
            ("voltage", "Tension", Some("V"), "number", ">=", 70),
        ],
    ),
    (
        16,
        &[1605, 1606, 1607, 1608, 1609],
        &[
            ("mcu", "MCU", None, "text", "=", 50),
            ("flash", "Flash", Some("KB"), "number", ">=", 60),
            ("io", "IO", None, "number", ">=", 70),
        ],
    ),
    // ------------------------------------------------------ Oscillateurs
    (
        18,
        &[1801],
        &[
            ("freq", "Fréquence", Some("MHz"), "number", "=", 10),
            ("load_cap", "Cap. de charge", Some("pF"), "number", "=", 20),
            ("tolerance_ppm", "Tolérance", Some("ppm"), "number", "<=", 30),
            ("esr", "ESR", Some("ohm"), "number", "<=", 40),
        ],
    ),
    (
        18,
        &[1802],
        &[
            ("freq", "Fréquence", Some("MHz"), "number", "=", 10),
            ("pins", "Pins", None, "number", "=", 20),
            ("internal_caps", "Caps internes", None, "text", "=", 30),
        ],
    ),
    (
        18,
        &[1803],
        &[
            ("freq", "Fréquence", Some("MHz"), "number", "=", 10),
            ("tolerance_ppm", "Tolérance", Some("ppm"), "number", "<=", 20),
            ("vcc", "VCC", Some("V"), "number", "=", 30),
            ("output", "Sortie", None, "text", "=", 40),
        ],
    ),
];

/// Applique toutes les migrations : colonne `marking`, tables de paramètres et
/// référentiel. Idempotent, comme les fonctions PHP correspondantes.
pub async fn init(pool: &MySqlPool) -> Result<(), sqlx::Error> {
    ensure_marking_column(pool).await?;
    ensure_param_tables(pool).await?;
    migrate_bridge_rectifier(pool).await?;
    sync_param_definitions(pool).await?;
    Ok(())
}

/// `ensureDataMarkingColumn()` : ajoute `data.marking` si elle manque.
async fn ensure_marking_column(pool: &MySqlPool) -> Result<(), sqlx::Error> {
    let existing: Option<(String,)> = sqlx::query_as("SHOW COLUMNS FROM `data` LIKE 'marking'")
        .fetch_optional(pool)
        .await?;
    if existing.is_none() {
        sqlx::query("ALTER TABLE `data` ADD COLUMN `marking` VARCHAR(80) DEFAULT NULL AFTER `smd`")
            .execute(pool)
            .await?;
        tracing::info!("Colonne « marking » ajoutée à la table data.");
    }
    Ok(())
}

/// `compozitEnsureParamTables()` : crée les deux tables de paramètres.
async fn ensure_param_tables(pool: &MySqlPool) -> Result<(), sqlx::Error> {
    sqlx::query(
        "CREATE TABLE IF NOT EXISTS `param_definitions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `category_id` INT NOT NULL,
            `subcategory_id` INT DEFAULT NULL,
            `param_key` VARCHAR(80) NOT NULL,
            `label` VARCHAR(120) NOT NULL,
            `unit` VARCHAR(24) DEFAULT NULL,
            `value_type` VARCHAR(16) NOT NULL DEFAULT 'number',
            `compare_mode` VARCHAR(8) NOT NULL DEFAULT '=',
            `sort_order` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_param_scope` (`category_id`, `subcategory_id`, `param_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    )
    .execute(pool)
    .await?;

    sqlx::query(
        "CREATE TABLE IF NOT EXISTS `data_params` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `data_id` INT NOT NULL,
            `param_id` INT NOT NULL,
            `value_num` DECIMAL(18,6) DEFAULT NULL,
            `value_txt` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_data_param` (`data_id`, `param_id`),
            KEY `idx_data_id` (`data_id`),
            KEY `idx_param_id` (`param_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    )
    .execute(pool)
    .await?;

    Ok(())
}

/// `compozitMigrateBridgeRectifierParams()` : corrige le paramètre hérité
/// `io` du pont redresseur (sous-catégorie 406) et retire `polarity`.
async fn migrate_bridge_rectifier(pool: &MySqlPool) -> Result<(), sqlx::Error> {
    let count = |key: &'static str| async move {
        let row: (i64,) = sqlx::query_as(
            "SELECT COUNT(*) FROM `param_definitions` \
             WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = ?",
        )
        .bind(key)
        .fetch_one(pool)
        .await?;
        Ok::<i64, sqlx::Error>(row.0)
    };

    let has_if_av = count("if_av").await?;
    let has_io = count("io").await?;

    if has_if_av == 0 && has_io > 0 {
        sqlx::query(
            "UPDATE `param_definitions` \
             SET `param_key` = 'if_av', `label` = 'IF(AV)', `unit` = 'A', `value_type` = 'number', \
                 `compare_mode` = '>=', `sort_order` = 20 \
             WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'io'",
        )
        .execute(pool)
        .await?;
    } else if has_if_av > 0 {
        sqlx::query(
            "UPDATE `param_definitions` \
             SET `label` = 'IF(AV)', `unit` = 'A', `value_type` = 'number', \
                 `compare_mode` = '>=', `sort_order` = 20 \
             WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'if_av'",
        )
        .execute(pool)
        .await?;
        if has_io > 0 {
            delete_definition(pool, 4, 406, "io").await?;
        }
    }

    delete_definition(pool, 4, 406, "polarity").await?;
    Ok(())
}

/// Supprime une définition et les valeurs associées (sous-catégorie visée).
async fn delete_definition(
    pool: &MySqlPool,
    category_id: i32,
    subcategory_id: i32,
    param_key: &str,
) -> Result<(), sqlx::Error> {
    sqlx::query(
        "DELETE dp FROM `data_params` dp \
         INNER JOIN `param_definitions` pd ON pd.id = dp.param_id \
         WHERE pd.`category_id` = ? AND pd.`subcategory_id` = ? AND pd.`param_key` = ?",
    )
    .bind(category_id)
    .bind(subcategory_id)
    .bind(param_key)
    .execute(pool)
    .await?;

    sqlx::query(
        "DELETE FROM `param_definitions` \
         WHERE `category_id` = ? AND `subcategory_id` = ? AND `param_key` = ?",
    )
    .bind(category_id)
    .bind(subcategory_id)
    .bind(param_key)
    .execute(pool)
    .await?;

    Ok(())
}

/// `compozitSyncParamDefinitions()` : insère ou met à jour le référentiel.
async fn sync_param_definitions(pool: &MySqlPool) -> Result<(), sqlx::Error> {
    let mut rows: Vec<(i32, i32, &str, &str, Option<&str>, &str, &str, i32)> = Vec::new();
    for (category, subcategories, definitions) in SEED {
        for subcategory in *subcategories {
            for (key, label, unit, value_type, compare_mode, sort_order) in *definitions {
                rows.push((
                    *category,
                    *subcategory,
                    *key,
                    *label,
                    *unit,
                    *value_type,
                    *compare_mode,
                    *sort_order,
                ));
            }
        }
    }

    let mut builder = QueryBuilder::new(
        "INSERT INTO `param_definitions` \
         (`category_id`, `subcategory_id`, `param_key`, `label`, `unit`, `value_type`, `compare_mode`, `sort_order`) ",
    );
    builder.push_values(&rows, |mut row, value| {
        row.push_bind(value.0)
            .push_bind(value.1)
            .push_bind(value.2)
            .push_bind(value.3)
            .push_bind(value.4)
            .push_bind(value.5)
            .push_bind(value.6)
            .push_bind(value.7);
    });
    builder.push(
        " ON DUPLICATE KEY UPDATE \
         `label` = VALUES(`label`), `unit` = VALUES(`unit`), `value_type` = VALUES(`value_type`), \
         `compare_mode` = VALUES(`compare_mode`), `sort_order` = VALUES(`sort_order`)",
    );
    builder.build().execute(pool).await?;

    tracing::info!("Référentiel des paramètres techniques synchronisé ({} lignes).", rows.len());
    Ok(())
}
