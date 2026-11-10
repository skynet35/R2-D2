<?php
/* =========================================================================
   CompoZ'IT - Paramètres techniques dynamiques des composants
   -------------------------------------------------------------------------
   - param_definitions : décrit les paramètres disponibles par sous-catégorie
   - data_params       : stocke les valeurs réelles pour chaque composant
   - moteur générique  : création / édition / recherche d'équivalents
   ========================================================================= */

function compozitAppendSeedRows(array &$rows, int $categoryId, array $subcategoryIds, array $definitions): void
{
    foreach ($subcategoryIds as $subcategoryId) {
        foreach ($definitions as $definition) {
            $rows[] = [
                'category_id'    => $categoryId,
                'subcategory_id' => $subcategoryId,
                'param_key'      => $definition[0],
                'label'          => $definition[1],
                'unit'           => $definition[2],
                'value_type'     => $definition[3],
                'compare_mode'   => $definition[4],
                'sort_order'     => $definition[5],
            ];
        }
    }
}

function compozitParamSeed(): array
{
    $rows = [];

    compozitAppendSeedRows($rows, 1, [101, 102, 104, 105, 106, 107], [
        ['length',     'Longueur',     'm',  'number', '=', 10],
        ['awg',        'AWG',          null, 'number', '=', 20],
        ['conductors', 'Conducteurs',  null, 'number', '=', 30],
        ['color',      'Couleur',      null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 1, [101], [
        ['pitch', 'Pitch', 'mm',  'number', '=', 50],
        ['ways',  'Voies', null,  'number', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 1, [102], [
        ['impedance',   'Impédance',   'ohm',     'number', '=', 50],
        ['attenuation', 'Atténuation', 'dB/m',    'number', '<=', 60],
        ['freq_max',    'Fréq. max',   'MHz',     'number', '>=', 70],
    ]);
    compozitAppendSeedRows($rows, 1, [107], [
        ['end_a', 'Connecteur A', null, 'text',   '=', 50],
        ['end_b', 'Connecteur B', null, 'text',   '=', 60],
        ['ways',  'Voies',        null, 'number', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 1, [106], [
        ['mode',      'Mode',       null, 'text', '=', 50],
        ['connector', 'Connecteur', null, 'text', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 1, [104, 105], [
        ['connector_a', 'Connecteur A', null, 'text', '=', 50],
        ['connector_b', 'Connecteur B', null, 'text', '=', 60],
        ['version',     'Version',      null, 'text', '=', 70],
    ]);

    compozitAppendSeedRows($rows, 2, [201, 202, 203, 204, 205], [
        ['capacitance', 'Capacité',     'uF', 'number', '=', 10],
        ['voltage',     'Tension',      'V',  'number', '>=', 20],
        ['tolerance',   'Tolérance',    '%',  'number', '<=', 30],
        ['pitch',       'Pitch',        'mm', 'number', '=', 40],
    ]);
    compozitAppendSeedRows($rows, 2, [201], [
        ['dielectric', 'Diélectrique', null, 'text', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 2, [202], [
        ['temp_max', 'Temp. max', '°C', 'number', '>=', 50],
        ['esr',      'ESR',       'ohm','number', '<=', 60],
        ['ripple',   'Ripple',    'A',  'number', '>=', 70],
        ['diameter', 'Diamètre',  'mm', 'number', '=', 80],
        ['height',   'Hauteur',   'mm', 'number', '=', 90],
    ]);
    compozitAppendSeedRows($rows, 2, [203], [
        ['voltage_ac', 'Tension AC', 'V',  'number', '>=', 50],
        ['material',   'Matériau',   null, 'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 2, [204], [
        ['esr',       'ESR',          'ohm', 'number', '<=', 50],
        ['case_code', 'Code boîtier', null,  'text',   '=', 60],
        ['polarized', 'Polarisé',     null,  'text',   '=', 70],
    ]);
    compozitAppendSeedRows($rows, 2, [205], [
        ['cap_min', 'Capacité min', 'uF', 'number', '=', 50],
        ['cap_max', 'Capacité max', 'uF', 'number', '=', 60],
    ]);

    compozitAppendSeedRows($rows, 3, [301, 302, 303, 304, 305, 306], [
        ['contacts', 'Contacts', null, 'number', '=', 10],
        ['pitch',    'Pitch',    'mm',   'number', '=', 20],
        ['gender',   'Genre',    null,   'text',   '=', 30],
        ['mount',    'Montage',  null,   'text',   '=', 40],
        ['current',  'Courant',  'A',    'number', '>=', 50],
        ['voltage',  'Tension',  'V',    'number', '>=', 60],
        ['rows',     'Rangées',  null,   'number', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 3, [301], [
        ['type',   'Type',    null, 'text', '=', 80],
        ['stereo', 'Stéréo',  null, 'text', '=', 90],
    ]);
    compozitAppendSeedRows($rows, 3, [303], [
        ['od', 'Diamètre ext.', 'mm', 'number', '=', 80],
        ['id', 'Diamètre int.', 'mm', 'number', '=', 90],
    ]);
    compozitAppendSeedRows($rows, 3, [304], [
        ['shell_size', 'Taille shell', null, 'text', '=', 80],
    ]);
    compozitAppendSeedRows($rows, 3, [302, 305], [
        ['type',      'Type',       null, 'text',   '=', 80],
        ['impedance', 'Impédance',  'ohm','number', '=', 90],
        ['freq_max',  'Fréq. max',  'MHz','number', '>=', 100],
    ]);
    compozitAppendSeedRows($rows, 3, [306], [
        ['series', 'Série', null, 'text', '=', 80],
    ]);

    compozitAppendSeedRows($rows, 4, [401], [
        ['polarity', 'Polarité', null, 'text',   '=', 10],
        ['vrrm',     'VRRM',     'V',  'number', '>=', 20],
        ['if_avg',   'IF moyen', 'A',  'number', '>=', 30],
        ['ifsm',     'IFSM',     'A',  'number', '>=', 40],
        ['vf',       'VF',       'V',  'number', '<=', 50],
        ['recovery', 'Recovery', null, 'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 4, [402], [
        ['polarity', 'Polarité', null, 'text',   '=', 10],
        ['vr',       'VR',       'V',  'number', '>=', 20],
        ['if_avg',   'IF moyen', 'A',  'number', '>=', 30],
        ['vf',       'VF',       'V',  'number', '<=', 40],
        ['ir',       'IR',       'uA', 'number', '<=', 50],
    ]);
    compozitAppendSeedRows($rows, 4, [403], [
        ['polarity', 'Polarité',    null, 'text',   '=', 10],
        ['vr',       'VR',          'V',  'number', '>=', 20],
        ['if_avg',   'IF moyen',    'A',  'number', '>=', 30],
        ['trr',      'TRR',         'ns', 'number', '<=', 40],
        ['cj',       'Capacité CJ', 'pF', 'number', '<=', 50],
    ]);
    compozitAppendSeedRows($rows, 4, [404], [
        ['polarity',  'Polarité',  null, 'text',   '=', 10],
        ['vz',        'VZ',        'V',  'number', '=', 20],
        ['tolerance', 'Tolérance', '%',  'number', '<=', 30],
        ['power',     'Puissance', 'W',  'number', '>=', 40],
    ]);
    compozitAppendSeedRows($rows, 4, [406], [
        ['vrrm',   'VRRM',   'V',  'number', '>=', 10],
        ['if_av',  'IF(AV)', 'A',  'number', '>=', 20],
        ['ifsm',   'IFSM',   'A',  'number', '>=', 30],
        ['phases', 'Phases', null, 'number', '=', 40],
    ]);

    compozitAppendSeedRows($rows, 5, [501, 502, 517], [
        ['function', 'Fonction', null, 'text',   '=', 10],
        ['family',   'Famille',  null,   'text',   '=', 20],
        ['vcc_min',  'VCC min',  'V',    'number', '<=', 30],
        ['vcc_max',  'VCC max',  'V',    'number', '>=', 40],
        ['gates',    'Portes',   null,   'number', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 5, [503], [
        ['core',    'Core',      null, 'text',   '=', 10],
        ['flash',   'Flash',     'KB', 'number', '>=', 20],
        ['ram',     'RAM',       'KB', 'number', '>=', 30],
        ['freq',    'Fréquence', 'MHz','number', '>=', 40],
        ['io',      'IO',        null, 'number', '>=', 50],
        ['vcc_min', 'VCC min',   'V',  'number', '<=', 60],
        ['vcc_max', 'VCC max',   'V',  'number', '>=', 70],
    ]);
    compozitAppendSeedRows($rows, 5, [504, 505], [
        ['channels',      'Canaux',        null, 'number', '=', 10],
        ['vcc_min',       'VCC min',       'V',  'number', '<=', 20],
        ['vcc_max',       'VCC max',       'V',  'number', '>=', 30],
        ['gbw',           'GBW',           'MHz','number', '>=', 40],
        ['slew',          'Slew rate',     'V/us','number','>=', 50],
        ['offset',        'Offset',        'mV', 'number', '<=', 60],
        ['rail_to_rail',  'Rail-to-rail',  null, 'text',   '=', 70],
    ]);
    compozitAppendSeedRows($rows, 5, [506], [
        ['output',   'Sortie',   null, 'text',   '=', 10],
        ['accuracy', 'Précision','°C', 'number', '<=', 20],
    ]);
    compozitAppendSeedRows($rows, 5, [507], [
        ['type',     'Type',      null, 'text',   '=', 10],
        ['freq_max', 'Fréq. max', 'MHz','number', '>=', 20],
    ]);
    compozitAppendSeedRows($rows, 5, [508], [
        ['vout',     'Vout',      'V',      'number', '=', 10],
        ['accuracy', 'Précision', '%',      'number', '<=', 20],
        ['drift',    'Drift',     'ppm/°C', 'number', '<=', 30],
        ['topology', 'Topologie', null,     'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 5, [509], [
        ['vout',     'Vout',      'V',  'number', '=', 10],
        ['iout',     'Iout',      'A',  'number', '>=', 20],
        ['vin_max',  'Vin max',   'V',  'number', '>=', 30],
        ['dropout',  'Dropout',   'V',  'number', '<=', 40],
        ['iq',       'Iq',        'mA', 'number', '<=', 50],
        ['polarity', 'Polarité',  null, 'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 5, [510], [
        ['bits',      'Bits',       null, 'number', '>=', 10],
        ['sps',       'SPS',        null, 'number', '>=', 20],
        ['channels',  'Canaux',     null, 'number', '>=', 30],
        ['interface', 'Interface',  null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 5, [511], [
        ['channels', 'Canaux', null, 'number', '>=', 10],
        ['ron',      'R_on',   'ohm',  'number', '<=', 20],
    ]);
    compozitAppendSeedRows($rows, 5, [512, 513], [
        ['type', 'Type',   null, 'text',   '=', 10],
        ['iout', 'Iout',   'A',  'number', '>=', 20],
        ['vmax', 'Vmax',   'V',  'number', '>=', 30],
    ]);
    compozitAppendSeedRows($rows, 5, [514], [
        ['topology',   'Topologie',  null, 'text',   '=', 10],
        ['vin_min',    'Vin min',    'V',  'number', '<=', 20],
        ['vin_max',    'Vin max',    'V',  'number', '>=', 30],
        ['vout',       'Vout',       'V',  'number', '=', 40],
        ['iout',       'Iout',       'A',  'number', '>=', 50],
        ['efficiency', 'Rendement',  '%',  'number', '>=', 60],
    ]);
    compozitAppendSeedRows($rows, 5, [515], [
        ['type',  'Type',        null, 'text',   '=', 10],
        ['power', 'Puissance',   'W',  'number', '>=', 20],
        ['load',  'Charge',      'ohm','number', '=', 30],
    ]);
    compozitAppendSeedRows($rows, 5, [516], [
        ['mem_type',  'Type mémoire', null, 'text',   '=', 10],
        ['capacity',  'Capacité',     'KB', 'number', '>=', 20],
        ['interface', 'Interface',    null, 'text',   '=', 30],
        ['vcc',       'VCC',          'V',  'number', '=', 40],
    ]);

    compozitAppendSeedRows($rows, 6, [603], [
        ['inductance', 'Inductance', 'uH',  'number', '=', 10],
        ['isat',       'Isat',       'A',   'number', '>=', 20],
        ['irms',       'Irms',       'A',   'number', '>=', 30],
        ['dcr',        'DCR',        'ohm', 'number', '<=', 40],
        ['tolerance',  'Tolérance',  '%',   'number', '<=', 50],
        ['shielded',   'Blindée',    null,  'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 6, [601], [
        ['z_100mhz', 'Z @100MHz', 'ohm', 'number', '=', 10],
        ['current',  'Courant',   'A',   'number', '>=', 20],
        ['dcr',      'DCR',       'ohm', 'number', '<=', 30],
    ]);
    compozitAppendSeedRows($rows, 6, [602], [
        ['type',       'Type',       null, 'text',   '=', 10],
        ['inductance', 'Inductance', 'uH', 'number', '=', 20],
        ['current',    'Courant',    'A',  'number', '>=', 30],
    ]);

    compozitAppendSeedRows($rows, 7, [701], [
        ['dim_l', 'Longueur',  'mm', 'number', '=', 10],
        ['dim_w', 'Largeur',   'mm', 'number', '=', 20],
        ['dim_h', 'Hauteur',   'mm', 'number', '=', 30],
        ['ip',    'Indice IP', null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 7, [702], [
        ['length',   'Longueur', 'mm', 'number', '=', 10],
        ['thread',   'Filetage', null, 'text',   '=', 20],
        ['material', 'Matériau', null, 'text',   '=', 30],
        ['gender',   'Genre',    null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 7, [703, 905], [
        ['fuse_format', 'Format fusible', null, 'text',   '=', 10],
        ['current',     'Courant',        'A',  'number', '>=', 20],
    ]);
    compozitAppendSeedRows($rows, 7, [704], [
        ['motor_type', 'Type moteur', null, 'text',   '=', 10],
        ['voltage',    'Tension',     'V',  'number', '=', 20],
        ['current',    'Courant',     'A',  'number', '>=', 30],
        ['torque',     'Couple',      'Nm', 'number', '>=', 40],
        ['shaft',      'Arbre',       'mm', 'number', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 7, [705], [
        ['coil_voltage',    'Tension bobine',   'V',  'number', '=', 10],
        ['contact_current', 'Courant contact',  'A',  'number', '>=', 20],
        ['contact_voltage', 'Tension contact',  'V',  'number', '>=', 30],
        ['config',          'Configuration',    null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 7, [708], [
        ['pins',  'Pins',  null, 'number', '=', 10],
        ['pitch', 'Pitch', 'mm', 'number', '=', 20],
    ]);
    compozitAppendSeedRows($rows, 7, [709], [
        ['rth',            'Rth',               '°C/W', 'number', '<=', 10],
        ['target_package', 'Package cible',     null,   'text',   '=', 20],
    ]);
    compozitAppendSeedRows($rows, 7, [710], [
        ['shaft',    'Arbre',     'mm', 'number', '=', 10],
        ['diameter', 'Diamètre',  'mm', 'number', '=', 20],
    ]);

    compozitAppendSeedRows($rows, 8, [803, 804, 805], [
        ['color',      'Couleur',      null, 'text',   '=', 10],
        ['wavelength', 'Longueur d’onde', 'nm', 'number', '=', 20],
        ['intensity',  'Intensité',    'mcd', 'number', '>=', 30],
        ['vf',         'VF',           'V',   'number', '<=', 40],
        ['if',         'IF',           'A',   'number', '>=', 50],
        ['view_angle', 'Angle',        '°',   'number', '>=', 60],
    ]);
    compozitAppendSeedRows($rows, 8, [807], [
        ['wavelength', 'Longueur d’onde', 'nm', 'number', '=', 10],
        ['if',         'IF',              'A',  'number', '>=', 20],
        ['view_angle', 'Angle',           '°',  'number', '=', 30],
    ]);
    compozitAppendSeedRows($rows, 8, [802], [
        ['wavelength', 'Longueur d’onde', 'nm', 'number', '=', 10],
        ['power',      'Puissance',       'mW', 'number', '>=', 20],
        ['voltage',    'Tension',         'V',  'number', '=', 30],
    ]);
    compozitAppendSeedRows($rows, 8, [806], [
        ['ctr',         'CTR',       '%',  'number', '>=', 10],
        ['viso',        'V iso',     'V',  'number', '>=', 20],
        ['vceo',        'VCEO',      'V',  'number', '>=', 30],
        ['output_type', 'Type sortie',null,'text',   '=', 40],
        ['channels',    'Canaux',    null, 'number', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 8, [801], [
        ['gap',         'Gap',         'mm', 'number', '=', 10],
        ['output_type', 'Type sortie', null, 'text',   '=', 20],
    ]);
    compozitAppendSeedRows($rows, 8, [808], [
        ['voltage', 'Tension',   'V',  'number', '=', 10],
        ['power',   'Puissance', 'W',  'number', '>=', 20],
        ['base',    'Culot',     null, 'text',   '=', 30],
    ]);

    compozitAppendSeedRows($rows, 9, [901], [
        ['current',  'Courant',        'A',  'number', '=', 10],
        ['voltage',  'Tension',        'V',  'number', '>=', 20],
        ['speed',    'Vitesse',        null, 'text',   '=', 30],
        ['breaking', 'Pouvoir de coupure', 'A','number','>=', 40],
        ['format',   'Format',         null, 'text',   '=', 50],
    ]);
    compozitAppendSeedRows($rows, 9, [902], [
        ['v_varistor', 'Tension varistor', 'V',  'number', '=', 10],
        ['vac_max',    'VAC max',          'V',  'number', '>=', 20],
        ['energy',     'Énergie',          'J',  'number', '>=', 30],
        ['diameter',   'Diamètre',         'mm', 'number', '=', 40],
    ]);
    compozitAppendSeedRows($rows, 9, [903], [
        ['r25',       'R25',       'ohm', 'number', '=', 10],
        ['b_value',   'B value',   'K',   'number', '=', 20],
        ['tolerance', 'Tolérance', '%',   'number', '<=', 30],
        ['power',     'Puissance', 'W',   'number', '>=', 40],
    ]);
    compozitAppendSeedRows($rows, 9, [904], [
        ['r25',          'R25',           'ohm', 'number', '=', 10],
        ['hold_current', 'Courant hold',  'A',   'number', '>=', 20],
        ['trip_current', 'Courant trip',  'A',   'number', '>=', 30],
        ['vmax',         'Vmax',          'V',   'number', '>=', 40],
    ]);

    compozitAppendSeedRows($rows, 10, [1001, 1002, 1004, 1005, 1007], [
        ['poles',     'Pôles',       null, 'number', '=', 10],
        ['throws',    'Positions',   null, 'number', '=', 20],
        ['current',   'Courant',     'A',  'number', '>=', 30],
        ['voltage',   'Tension',     'V',  'number', '>=', 40],
        ['mount',     'Montage',     null, 'text',   '=', 50],
        ['actuation', 'Actionnement',null, 'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 10, [1001], [
        ['keys', 'Touches', null, 'number', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 10, [1002, 1005], [
        ['normally', 'Normalement', null, 'text', '=', 70],
        ['lighted',  'Éclairé',     null, 'text', '=', 80],
    ]);
    compozitAppendSeedRows($rows, 10, [1004], [
        ['ppr',        'PPR',        null, 'number', '=', 70],
        ['detents',    'Detents',    null, 'number', '=', 80],
        ['has_switch', 'Avec switch',null, 'text',   '=', 90],
    ]);
    compozitAppendSeedRows($rows, 10, [1007], [
        ['positions', 'Positions', null, 'number', '=', 70],
        ['pitch',     'Pitch',     'mm', 'number', '=', 80],
    ]);

    compozitAppendSeedRows($rows, 11, [1101], [
        ['type',  'Type',      null, 'text',   '=', 10],
        ['power', 'Puissance', 'W',  'number', '>=', 20],
        ['vout',  'Vout',      'V',  'number', '=', 30],
        ['iout',  'Iout',      'A',  'number', '>=', 40],
    ]);
    compozitAppendSeedRows($rows, 11, [1102], [
        ['v_primary',   'Primaire',   'V',  'number', '=', 10],
        ['v_secondary', 'Secondaire', 'V',  'number', '=', 20],
        ['va',          'VA',         'VA', 'number', '>=', 30],
        ['freq',        'Fréquence',  'Hz', 'number', '=', 40],
    ]);
    compozitAppendSeedRows($rows, 11, [1103], [
        ['topology',   'Topologie',  null, 'text',   '=', 10],
        ['vin_min',    'Vin min',    'V',  'number', '<=', 20],
        ['vin_max',    'Vin max',    'V',  'number', '>=', 30],
        ['vout',       'Vout',       'V',  'number', '=', 40],
        ['iout',       'Iout',       'A',  'number', '>=', 50],
        ['efficiency', 'Rendement',  '%',  'number', '>=', 60],
    ]);

    compozitAppendSeedRows($rows, 12, [1204, 1205, 1201], [
        ['vceo',   'VCEO',    'V',   'number', '>=', 10],
        ['ic',     'IC',      'A',   'number', '>=', 20],
        ['ptot',   'Ptot',    'W',   'number', '>=', 30],
        ['hfe',    'HFE',     null,  'number', '>=', 40],
        ['ft',     'Ft',      'MHz', 'number', '>=', 50],
        ['pinout', 'Pinout',  null,  'text',   '=', 60],
    ]);
    compozitAppendSeedRows($rows, 12, [1208, 1209], [
        ['vds',         'VDS',          'V',   'number', '>=', 10],
        ['id',          'ID',           'A',   'number', '>=', 20],
        ['rds_on',      'RDS(on)',      'ohm', 'number', '<=', 30],
        ['vgs_th',      'VGS(th)',      'V',   'number', '<=', 40],
        ['vgs_max',     'VGS max',      'V',   'number', '>=', 50],
        ['qg',          'Qg',           'nC',  'number', '<=', 60],
        ['logic_level', 'Logic level',  null,  'text',   '=', 70],
    ]);
    compozitAppendSeedRows($rows, 12, [1202], [
        ['channel', 'Canal', null, 'text',   '=', 10],
        ['vds',     'VDS',   'V',    'number', '>=', 20],
        ['idss',    'IDSS',  'A',    'number', '=', 30],
        ['vp',      'VP',    'V',    'number', '=', 40],
    ]);
    compozitAppendSeedRows($rows, 12, [1206, 1207], [
        ['vdrm', 'VDRM', 'V', 'number', '>=', 10],
        ['it',   'IT',   'A', 'number', '>=', 20],
        ['igt',  'IGT',  'mA','number', '<=', 30],
    ]);

    compozitAppendSeedRows($rows, 13, [1301, 1302, 1303, 1304, 1305, 1306, 1307, 1308, 1309, 1310, 1311, 1312, 1313, 1314, 1315], [
        ['resistance', 'Résistance', 'ohm', 'number', '=', 10],
        ['tolerance',  'Tolérance',  '%',   'number', '<=', 20],
        ['power',      'Puissance',  'W',   'number', '>=', 30],
    ]);
    compozitAppendSeedRows($rows, 13, [1301, 1302, 1303, 1304, 1313, 1314], [
        ['tcr', 'TCR', 'ppm/°C', 'number', '<=', 40],
    ]);
    compozitAppendSeedRows($rows, 13, [1305, 1306, 1307], [
        ['tcr',         'TCR',         'ppm/°C', 'number', '<=', 40],
        ['voltage_max', 'Tension max', 'V',      'number', '>=', 50],
    ]);
    compozitAppendSeedRows($rows, 13, [1315], [
        ['tcr', 'TCR', 'ppm/°C', 'number', '<=', 40],
    ]);
    compozitAppendSeedRows($rows, 13, [1310], [
        ['elements', 'Éléments',     null, 'number', '=', 40],
        ['config',   'Configuration',null, 'text',   '=', 50],
    ]);
    compozitAppendSeedRows($rows, 13, [1312], [
        ['taper', 'Taper', null, 'text',   '=', 40],
        ['turns', 'Tours', null, 'number', '=', 50],
        ['shaft', 'Arbre', 'mm', 'number', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 13, [1309], [
        ['r_light', 'R lumière', 'ohm', 'number', '=', 40],
        ['r_dark',  'R obscurité','ohm', 'number', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 13, [1311], [
        ['r25',     'R25',     'ohm', 'number', '=', 40],
        ['b_value', 'B value', 'K',   'number', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 13, [1308], [
        ['tolerance', 'Tolérance', '%', 'number', '<=', 40],
        ['power',     'Puissance', 'W', 'number', '>=', 50],
    ]);

    compozitAppendSeedRows($rows, 14, [1401, 1402, 1403, 1404], [
        ['interface',  'Interface',   null,  'text',   '=', 10],
        ['resolution', 'Résolution',  null,  'text',   '=', 20],
        ['vcc',        'VCC',         'V',   'number', '=', 30],
        ['size',       'Taille',      'in',  'number', '=', 40],
    ]);
    compozitAppendSeedRows($rows, 14, [1401], [
        ['chars',      'Caractères',  null, 'text', '=', 50],
        ['backlight',  'Rétroéclairage', null, 'text', '=', 60],
        ['controller', 'Contrôleur',  null, 'text', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 14, [1403], [
        ['controller', 'Contrôleur', null, 'text', '=', 50],
        ['touch',      'Tactile',    null, 'text', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 14, [1402, 1404], [
        ['color',  'Couleur', null, 'text',   '=', 50],
        ['digits', 'Digits',  null, 'number', '=', 60],
    ]);

    compozitAppendSeedRows($rows, 15, [1501, 1502, 1503, 1504, 1505, 1506, 1507, 1508, 1509], [
        ['interface', 'Interface', null, 'text',   '=', 10],
        ['vcc',       'VCC',       'V',  'number', '=', 20],
        ['range_min', 'Plage min', null, 'number', '=', 30],
        ['range_max', 'Plage max', null, 'number', '=', 40],
        ['accuracy',  'Précision', null, 'number', '<=', 50],
    ]);
    compozitAppendSeedRows($rows, 15, [1504, 1505], [
        ['output',      'Sortie',      null, 'text',   '=', 60],
        ['sensitivity', 'Sensibilité', null, 'number', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 15, [1506], [
        ['gas', 'Gaz', null, 'text', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 15, [1507], [
        ['axes',    'Axes',      null, 'number', '=', 60],
        ['range_g', 'Plage g',   'g',  'number', '=', 70],
    ]);
    compozitAppendSeedRows($rows, 15, [1508, 1509], [
        ['wavelength', 'Longueur d’onde', 'nm', 'number', '=', 60],
        ['range',      'Plage',           null, 'number', '=', 70],
    ]);

    compozitAppendSeedRows($rows, 16, [1601, 1602, 1603, 1604, 1605, 1606, 1607, 1608, 1609], [
        ['vcc',         'VCC',         'V',  'number', '=', 10],
        ['logic_level', 'Logic level', 'V',  'number', '=', 20],
        ['interface',   'Interface',   null, 'text',   '=', 30],
        ['pinout',      'Pinout',      null, 'text',   '=', 40],
    ]);
    compozitAppendSeedRows($rows, 16, [1601], [
        ['chip', 'Chip', null, 'text', '=', 50],
    ]);
    compozitAppendSeedRows($rows, 16, [1602], [
        ['chip',       'Chip',         null, 'text', '=', 50],
        ['bt_version', 'Version BT',   null, 'text', '=', 60],
    ]);
    compozitAppendSeedRows($rows, 16, [1603, 1604], [
        ['chip',    'Chip',    null, 'text',   '=', 50],
        ['current', 'Courant', 'A',  'number', '>=', 60],
        ['voltage', 'Tension', 'V',  'number', '>=', 70],
    ]);
    compozitAppendSeedRows($rows, 16, [1605, 1606, 1607, 1608, 1609], [
        ['mcu',   'MCU',     null, 'text',   '=', 50],
        ['flash', 'Flash',   'KB', 'number', '>=', 60],
        ['io',    'IO',      null, 'number', '>=', 70],
    ]);

    compozitAppendSeedRows($rows, 18, [1801], [
        ['freq',          'Fréquence',      'MHz',   'number', '=', 10],
        ['load_cap',      'Cap. de charge', 'pF',    'number', '=', 20],
        ['tolerance_ppm', 'Tolérance',      'ppm',   'number', '<=', 30],
        ['esr',           'ESR',            'ohm',   'number', '<=', 40],
    ]);
    compozitAppendSeedRows($rows, 18, [1802], [
        ['freq',          'Fréquence',      'MHz',   'number', '=', 10],
        ['pins',          'Pins',           null,    'number', '=', 20],
        ['internal_caps', 'Caps internes',  null,    'text',   '=', 30],
    ]);
    compozitAppendSeedRows($rows, 18, [1803], [
        ['freq',          'Fréquence',      'MHz',   'number', '=', 10],
        ['tolerance_ppm', 'Tolérance',      'ppm',   'number', '<=', 20],
        ['vcc',           'VCC',            'V',     'number', '=', 30],
        ['output',        'Sortie',         null,    'text',   '=', 40],
    ]);

    return $rows;
}

function compozitMigrateBridgeRectifierParams(PDO $pdo): void
{
    $countIfAv = (int) $pdo->query("SELECT COUNT(*) FROM `param_definitions` WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'if_av'")->fetchColumn();
    $countIo = (int) $pdo->query("SELECT COUNT(*) FROM `param_definitions` WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'io'")->fetchColumn();

    if ($countIfAv === 0 && $countIo > 0) {
        $pdo->exec("
            UPDATE `param_definitions`
            SET `param_key` = 'if_av', `label` = 'IF(AV)', `unit` = 'A', `value_type` = 'number', `compare_mode` = '>=', `sort_order` = 20
            WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'io'
        ");
    } elseif ($countIfAv > 0) {
        $pdo->exec("
            UPDATE `param_definitions`
            SET `label` = 'IF(AV)', `unit` = 'A', `value_type` = 'number', `compare_mode` = '>=', `sort_order` = 20
            WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'if_av'
        ");
        if ($countIo > 0) {
            $pdo->exec("
                DELETE dp FROM `data_params` dp
                INNER JOIN `param_definitions` pd ON pd.id = dp.param_id
                WHERE pd.`category_id` = 4 AND pd.`subcategory_id` = 406 AND pd.`param_key` = 'io'
            ");
            $pdo->exec("DELETE FROM `param_definitions` WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'io'");
        }
    }

    $bridgeRows = [
        ['param_key' => 'vrrm',  'label' => 'VRRM',   'unit' => 'V', 'value_type' => 'number', 'compare_mode' => '>=', 'sort_order' => 10],
        ['param_key' => 'if_av', 'label' => 'IF(AV)', 'unit' => 'A', 'value_type' => 'number', 'compare_mode' => '>=', 'sort_order' => 20],
        ['param_key' => 'ifsm',  'label' => 'IFSM',   'unit' => 'A', 'value_type' => 'number', 'compare_mode' => '>=', 'sort_order' => 30],
        ['param_key' => 'phases','label' => 'Phases', 'unit' => null,'value_type' => 'number', 'compare_mode' => '=',  'sort_order' => 40],
    ];

    $upsert = $pdo->prepare("
        INSERT INTO `param_definitions` (`category_id`, `subcategory_id`, `param_key`, `label`, `unit`, `value_type`, `compare_mode`, `sort_order`)
        VALUES (4, 406, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `label` = VALUES(`label`),
            `unit` = VALUES(`unit`),
            `value_type` = VALUES(`value_type`),
            `compare_mode` = VALUES(`compare_mode`),
            `sort_order` = VALUES(`sort_order`)
    ");
    foreach ($bridgeRows as $row) {
        $upsert->execute([
            $row['param_key'],
            $row['label'],
            $row['unit'],
            $row['value_type'],
            $row['compare_mode'],
            $row['sort_order'],
        ]);
    }

    $pdo->exec("
        DELETE dp FROM `data_params` dp
        INNER JOIN `param_definitions` pd ON pd.id = dp.param_id
        WHERE pd.`category_id` = 4 AND pd.`subcategory_id` = 406 AND pd.`param_key` = 'polarity'
    ");
    $pdo->exec("DELETE FROM `param_definitions` WHERE `category_id` = 4 AND `subcategory_id` = 406 AND `param_key` = 'polarity'");
}

function compozitSyncParamDefinitions(PDO $pdo): void
{
    $upsert = $pdo->prepare("
        INSERT INTO `param_definitions`
            (`category_id`, `subcategory_id`, `param_key`, `label`, `unit`, `value_type`, `compare_mode`, `sort_order`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `label` = VALUES(`label`),
            `unit` = VALUES(`unit`),
            `value_type` = VALUES(`value_type`),
            `compare_mode` = VALUES(`compare_mode`),
            `sort_order` = VALUES(`sort_order`)
    ");

    foreach (compozitParamSeed() as $row) {
        $upsert->execute([
            $row['category_id'],
            $row['subcategory_id'],
            $row['param_key'],
            $row['label'],
            $row['unit'],
            $row['value_type'],
            $row['compare_mode'],
            $row['sort_order'],
        ]);
    }
}

function compozitEnsureParamTables(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `param_definitions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `data_params` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `data_id` INT NOT NULL,
            `param_id` INT NOT NULL,
            `value_num` DECIMAL(18,6) DEFAULT NULL,
            `value_txt` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_data_param` (`data_id`, `param_id`),
            KEY `idx_data_id` (`data_id`),
            KEY `idx_param_id` (`param_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    compozitMigrateBridgeRectifierParams($pdo);
    compozitSyncParamDefinitions($pdo);
}

function compozitGetParamDefinitions(PDO $pdo, ?int $subcategoryId = null, ?int $categoryId = null): array
{
    compozitEnsureParamTables($pdo);

    if ($subcategoryId) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM `param_definitions`
            WHERE `subcategory_id` = ?
            ORDER BY `sort_order`, `id`
        ");
        $stmt->execute([$subcategoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($categoryId) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM `param_definitions`
            WHERE `category_id` = ?
            ORDER BY `subcategory_id`, `sort_order`, `id`
        ");
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->query("SELECT * FROM `param_definitions` ORDER BY `category_id`, `subcategory_id`, `sort_order`, `id`");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function compozitGetParamDefinitionsMap(PDO $pdo, int $categoryId): array
{
    $rows = compozitGetParamDefinitions($pdo, null, $categoryId);
    $map = [];
    foreach ($rows as $row) {
        $subId = (int) $row['subcategory_id'];
        if (!isset($map[$subId])) {
            $map[$subId] = [];
        }
        $map[$subId][] = $row;
    }
    return $map;
}

function compozitGetAllParamDefinitionsMap(PDO $pdo): array
{
    $rows = compozitGetParamDefinitions($pdo);
    $map = [];
    foreach ($rows as $row) {
        $subId = (int) ($row['subcategory_id'] ?? 0);
        if ($subId <= 0) {
            continue;
        }
        if (!isset($map[$subId])) {
            $map[$subId] = [];
        }
        $map[$subId][] = $row;
    }
    return $map;
}

function compozitGetComponentParamValues(PDO $pdo, int $dataId): array
{
    compozitEnsureParamTables($pdo);

    $stmt = $pdo->prepare("
        SELECT pd.param_key, pd.value_type, dp.value_num, dp.value_txt
        FROM `data_params` dp
        INNER JOIN `param_definitions` pd ON pd.id = dp.param_id
        WHERE dp.data_id = ?
    ");
    $stmt->execute([$dataId]);

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $values[$row['param_key']] = $row['value_type'] === 'number'
            ? (string) $row['value_num']
            : (string) $row['value_txt'];
    }

    return $values;
}

function compozitGetComponentParamRows(PDO $pdo, int $dataId): array
{
    compozitEnsureParamTables($pdo);

    $stmt = $pdo->prepare("
        SELECT dp.data_id, pd.param_key, pd.label, pd.unit, pd.value_type, pd.compare_mode, pd.sort_order,
               dp.value_num, dp.value_txt
        FROM `data_params` dp
        INNER JOIN `param_definitions` pd ON pd.id = dp.param_id
        WHERE dp.data_id = ?
        ORDER BY pd.sort_order, pd.id
    ");
    $stmt->execute([$dataId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function compozitGetComponentParamRowsMap(PDO $pdo, array $dataIds): array
{
    compozitEnsureParamTables($pdo);

    $dataIds = array_values(array_unique(array_filter(array_map('intval', $dataIds))));
    if (!$dataIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($dataIds), '?'));
    $stmt = $pdo->prepare("
        SELECT dp.data_id, pd.param_key, pd.label, pd.unit, pd.value_type, pd.compare_mode, pd.sort_order,
               dp.value_num, dp.value_txt
        FROM `data_params` dp
        INNER JOIN `param_definitions` pd ON pd.id = dp.param_id
        WHERE dp.data_id IN ($placeholders)
        ORDER BY dp.data_id, pd.sort_order, pd.id
    ");
    $stmt->execute($dataIds);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $dataId = (int) $row['data_id'];
        if (!isset($map[$dataId])) {
            $map[$dataId] = [];
        }
        $map[$dataId][] = $row;
    }

    return $map;
}

function compozitFormatParamValue(array $row): string
{
    if (($row['value_type'] ?? 'text') === 'number') {
        $raw = (string) ($row['value_num'] ?? '');
        if ($raw === '') {
            return '';
        }
        $normalized = rtrim(rtrim(number_format((float) $raw, 6, '.', ''), '0'), '.');
        return trim($normalized . ' ' . (string) ($row['unit'] ?? ''));
    }

    return trim((string) ($row['value_txt'] ?? ''));
}

function compozitBuildParamSummary(array $rows, int $limit = 6): array
{
    $items = [];
    foreach ($rows as $row) {
        $value = compozitFormatParamValue($row);
        if ($value === '') {
            continue;
        }
        $items[] = trim((string) ($row['label'] ?? $row['param_key'] ?? '') . ' ' . $value);
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function compozitSaveComponentParams(PDO $pdo, int $dataId, ?int $subcategoryId, array $source): void
{
    compozitEnsureParamTables($pdo);

    $pdo->prepare("DELETE FROM `data_params` WHERE `data_id` = ?")->execute([$dataId]);
    if (!$subcategoryId) {
        return;
    }

    $definitions = compozitGetParamDefinitions($pdo, $subcategoryId);
    if (!$definitions) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO `data_params` (`data_id`, `param_id`, `value_num`, `value_txt`)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($definitions as $definition) {
        $fieldName = 'param_' . $definition['param_key'];
        $rawValue = $source[$fieldName] ?? null;

        if ($definition['value_type'] === 'number') {
            $value = is_string($rawValue) ? trim(str_replace(',', '.', $rawValue)) : trim((string) $rawValue);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            $insert->execute([$dataId, (int) $definition['id'], (float) $value, null]);
            continue;
        }

        $value = trim((string) $rawValue);
        if ($value === '') {
            continue;
        }
        $insert->execute([$dataId, (int) $definition['id'], null, $value]);
    }
}

function compozitBuildEquivalentMatches(PDO $pdo, int $ownerId, int $subcategoryId, array $searchValues): array
{
    compozitEnsureParamTables($pdo);
    $definitions = compozitGetParamDefinitions($pdo, $subcategoryId);
    if (!$definitions) {
        return [];
    }

    $normalized = [];
    foreach ($definitions as $definition) {
        $key = $definition['param_key'];
        if (!array_key_exists($key, $searchValues)) {
            continue;
        }

        $raw = trim((string) $searchValues[$key]);
        if ($raw === '') {
            continue;
        }

        if ($definition['value_type'] === 'number') {
            $raw = str_replace(',', '.', $raw);
            if (!is_numeric($raw)) {
                continue;
            }
            $normalized[$key] = (float) $raw;
        } else {
            $normalized[$key] = mb_strtolower($raw);
        }
    }

    if (!$normalized) {
        return [];
    }

    $sql = "
        SELECT d.id, d.name, d.manufacturer, d.package, d.quantity, d.image_path,
               d.datasheet, d.datasheet_file, d.comment, d.supplier_reference,
               l.casier, l.tiroir, l.compartiment, l.storage_type,
               s.name AS supplier_name
        FROM `data` d
        LEFT JOIN `location` l ON l.id = d.location_id
        LEFT JOIN `suppliers` s ON s.id = d.supplier_id
        WHERE d.owner = ? AND d.category = ?
    ";
    $params = [$ownerId, $subcategoryId];

    foreach ($definitions as $definition) {
        $key = $definition['param_key'];
        if (!array_key_exists($key, $normalized)) {
            continue;
        }

        $sql .= "
            AND EXISTS (
                SELECT 1
                FROM `data_params` dp
                WHERE dp.data_id = d.id
                  AND dp.param_id = ?
        ";
        $params[] = (int) $definition['id'];

        if ($definition['value_type'] === 'number') {
            if ($definition['compare_mode'] === '>=') {
                $sql .= " AND dp.value_num >= ? ";
            } elseif ($definition['compare_mode'] === '<=') {
                $sql .= " AND dp.value_num <= ? ";
            } else {
                $sql .= " AND ABS(dp.value_num - ?) < 0.000001 ";
            }
            $params[] = $normalized[$key];
        } else {
            $sql .= " AND LOWER(COALESCE(dp.value_txt, '')) = ? ";
            $params[] = $normalized[$key];
        }

        $sql .= ")";
    }

    $sql .= " ORDER BY d.name ASC, d.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
