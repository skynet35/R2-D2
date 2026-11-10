-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : dim. 20 juil. 2025 à 19:03
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `toto`
--

-- --------------------------------------------------------

--
-- Structure de la table `category_head`
--

CREATE TABLE `category_head` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `category_head`
--

INSERT INTO `category_head` (`id`, `name`) VALUES
(1, 'Cables'),
(2, 'Condensateurs'),
(3, 'Connecteurs'),
(4, 'Diode'),
(5, 'CI'),
(6, 'Inductances'),
(7, 'Mecanique'),
(8, 'Opto/LED'),
(9, 'Protections'),
(10, 'Interrupteur/poussoirs'),
(11, 'Régulateurs/Transfo'),
(12, 'Transistors'),
(13, 'Resistances'),
(14, 'Ecrans'),
(15, 'Capteurs'),
(16, 'Modules'),
(17, 'Autres'),
(18, 'Oscillateurs');

-- --------------------------------------------------------

--
-- Structure de la table `category_sub`
--

CREATE TABLE `category_sub` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `category_head_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `category_sub`
--

INSERT INTO `category_sub` (`id`, `name`, `category_head_id`) VALUES
(101, 'Ruban', 1),
(102, 'Coaxial', 1),
(103, 'Rouleau', 1),
(104, 'Cable PC', 1),
(105, 'Signal/Data', 1),
(106, 'Fibre optique', 1),
(107, 'Dupont', 1),
(199, 'Divers', 1),
(201, 'Ceramique', 2),
(202, 'Electrolytique', 2),
(203, 'Polyester', 2),
(204, 'Tantal', 2),
(205, 'Variable', 2),
(299, 'Divers', 2),
(301, 'Audio', 3),
(302, 'Coaxial', 3),
(303, 'DC-alim', 3),
(304, 'D-Sub', 3),
(305, 'HF', 3),
(306, 'PCB', 3),
(307, 'Cable PC', 3),
(308, 'Data', 3),
(399, 'Divers', 3),
(401, 'Redresseur', 4),
(402, 'Schottky', 4),
(403, 'Petits signaux', 4),
(404, 'Zener', 4),
(406, 'Bridge', 4),
(499, 'Divers', 4),
(501, '4xxx', 5),
(502, '74xx', 5),
(503, 'Microcontroller', 5),
(504, 'Comparateur', 5),
(505, 'AOP', 5),
(506, 'Temperature', 5),
(507, 'Timer & Osc.', 5),
(508, 'Référence de tension', 5),
(509, 'Régulateur de tension', 5),
(510, 'Convertisseur Data', 5),
(511, 'A/D Multiplexeur', 5),
(512, 'Driver', 5),
(513, 'Opto Driver', 5),
(514, 'Convertisseur DC/DC', 5),
(515, 'Audio/Video', 5),
(516, 'Memoires', 5),
(517, 'Logic', 5),
(599, 'Divers', 5),
(601, 'Ferrite', 6),
(602, 'Filtre', 6),
(603, 'Inducteur', 6),
(699, 'Divers', 6),
(701, 'Box', 7),
(702, 'Distance', 7),
(703, 'Supports Fusibles', 7),
(704, 'Moteurs', 7),
(705, 'Relais', 7),
(708, 'IC Socket', 7),
(709, 'Radiateur', 7),
(710, 'Bouton potar', 7),
(711, 'Metre', 7),
(799, 'Divers', 7),
(801, 'Barrières photo-electrique', 8),
(802, 'Laser', 8),
(803, 'LED', 8),
(804, 'LED 3mm', 8),
(805, 'LED 5mm', 8),
(806, 'Optocoupleur', 8),
(807, 'IR LED', 8),
(808, 'Ampoules', 8),
(899, 'Divers', 8),
(901, 'Fusibles', 9),
(902, 'Varistances', 9),
(903, 'Thermistances CTN', 9),
(904, 'Thermistances CTP', 9),
(905, 'Support Fusibles', 9),
(999, 'Divers', 9),
(1001, 'Clavier', 10),
(1002, 'Momentanné', 10),
(1003, 'Monté sur PCB', 10),
(1004, 'Encodeur rotatif', 10),
(1005, 'Maintient', 10),
(1007, 'DIP', 10),
(1099, 'Divers', 10),
(1101, 'Alimentation', 11),
(1102, 'Transformateur', 11),
(1103, 'Convertisseur DC/DC', 11),
(1199, 'Divers', 11),
(1201, 'JBT', 12),
(1202, 'JFET', 12),
(1204, 'NPN', 12),
(1205, 'PNP', 12),
(1206, 'Triac', 12),
(1207, 'Thyristor', 12),
(1208, 'MOSFET-N', 12),
(1209, 'MOSFET-P', 12),
(1299, 'Divers', 12),
(1301, '1/4W Carbon', 13),
(1302, '1/4W Metal', 13),
(1303, '1/6W Carbon', 13),
(1304, '1/6W Metal', 13),
(1305, 'CMS-0603', 13),
(1306, 'CMS-0805', 13),
(1307, 'CMS-1206', 13),
(1308, 'Effect', 13),
(1309, 'Photo', 13),
(1310, 'Réseaux', 13),
(1311, 'Temperature', 13),
(1312, 'Potentiometre', 13),
(1313, '1/3W Carbon', 13),
(1314, '1/3W Metal', 13),
(1315, 'Precision', 13),
(1399, 'Divers', 13),
(1401, 'LCD', 14),
(1402, 'VFD', 14),
(1403, 'TFT', 14),
(1404, 'LED', 14),
(1499, 'Divers', 14),
(1501, 'Humidité', 15),
(1502, 'Temperature', 15),
(1503, 'Pression', 15),
(1504, 'Magnetique', 15),
(1505, 'Effet hall', 15),
(1506, 'Gaz', 15),
(1507, 'Accelerometre', 15),
(1508, 'Lumières', 15),
(1509, 'Proximité', 15),
(1599, 'Divers', 15),
(1601, 'Ethernet', 16),
(1602, 'Wifi/Bluetooth2', 16),
(1603, 'Moteurs', 16),
(1604, 'Drivers/Controleurs', 16),
(1605, 'Arduino Mini/Micro', 16),
(1606, 'Arduino UNO', 16),
(1607, 'Arduino MEGA', 16),
(1608, 'Raspberry Pi', 16),
(1609, 'ESP32/8266', 16),
(1699, 'Divers', 16),
(1701, 'Outils', 17),
(1702, 'Accessoires', 17),
(1703, 'Kits', 17),
(1798, 'Consommables', 17),
(1799, 'Divers', 17),
(1801, 'Crystal', 18),
(1802, 'Resonateur', 18),
(1803, 'RC', 18),
(1899, 'Divers', 18);

-- --------------------------------------------------------

--
-- Structure de la table `data`
--

CREATE TABLE `data` (
  `id` int(11) NOT NULL,
  `owner` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `package` varchar(255) DEFAULT NULL,
  `pins` int(11) DEFAULT NULL,
  `smd` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT 0,
  `location_id` int(11) DEFAULT NULL,
  `order_quantity` int(11) DEFAULT 0,
  `price` decimal(10,2) DEFAULT NULL,
  `datasheet` varchar(255) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `category` int(11) DEFAULT NULL,
  `public` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_reference` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `param_definitions`
--

CREATE TABLE `param_definitions` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `subcategory_id` int(11) DEFAULT NULL,
  `param_key` varchar(80) NOT NULL,
  `label` varchar(120) NOT NULL,
  `unit` varchar(24) DEFAULT NULL,
  `value_type` varchar(16) NOT NULL DEFAULT 'number',
  `compare_mode` varchar(8) NOT NULL DEFAULT '=',
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `param_definitions`
--

INSERT INTO `param_definitions` (`id`, `category_id`, `subcategory_id`, `param_key`, `label`, `unit`, `value_type`, `compare_mode`, `sort_order`) VALUES
(1, 4, 401, 'polarity', 'Polarité', NULL, 'text', '=', 10),
(2, 4, 401, 'vrrm', 'VRRM', 'V', 'number', '>=', 20),
(3, 4, 401, 'if_avg', 'IF moyen', 'A', 'number', '>=', 30),
(4, 4, 401, 'ifsm', 'IFSM', 'A', 'number', '>=', 40),
(5, 4, 401, 'vf', 'VF', 'V', 'number', '<=', 50),
(6, 4, 401, 'recovery', 'Recovery', NULL, 'text', '=', 60),
(7, 4, 402, 'polarity', 'Polarité', NULL, 'text', '=', 10),
(8, 4, 402, 'vr', 'VR', 'V', 'number', '>=', 20),
(9, 4, 402, 'if_avg', 'IF moyen', 'A', 'number', '>=', 30),
(10, 4, 402, 'vf', 'VF', 'V', 'number', '<=', 40),
(11, 4, 402, 'ir', 'IR', 'uA', 'number', '<=', 50),
(12, 4, 403, 'polarity', 'Polarité', NULL, 'text', '=', 10),
(13, 4, 403, 'vr', 'VR', 'V', 'number', '>=', 20),
(14, 4, 403, 'if_avg', 'IF moyen', 'A', 'number', '>=', 30),
(15, 4, 403, 'trr', 'TRR', 'ns', 'number', '<=', 40),
(16, 4, 403, 'cj', 'Capacité CJ', 'pF', 'number', '<=', 50),
(17, 4, 404, 'polarity', 'Polarité', NULL, 'text', '=', 10),
(18, 4, 404, 'vz', 'VZ', 'V', 'number', '=', 20),
(19, 4, 404, 'tolerance', 'Tolérance', '%', 'number', '<=', 30),
(20, 4, 404, 'power', 'Puissance', 'W', 'number', '>=', 40),
(21, 4, 406, 'polarity', 'Polarité', NULL, 'text', '=', 10),
(22, 4, 406, 'vrrm', 'VRRM', 'V', 'number', '>=', 20),
(23, 4, 406, 'io', 'IO', 'A', 'number', '>=', 30),
(24, 4, 406, 'phases', 'Phases', NULL, 'number', '=', 40);

-- --------------------------------------------------------

--
-- Structure de la table `data_params`
--

CREATE TABLE `data_params` (
  `id` int(11) NOT NULL,
  `data_id` int(11) NOT NULL,
  `param_id` int(11) NOT NULL,
  `value_num` decimal(18,6) DEFAULT NULL,
  `value_txt` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `data`
--

INSERT INTO `data` (`id`, `owner`, `name`, `manufacturer`, `package`, `pins`, `smd`, `quantity`, `location_id`, `order_quantity`, `price`, `datasheet`, `comment`, `category`, `public`, `url`, `image_path`, `created_at`, `supplier_id`, `supplier_reference`) VALUES
(24, 1, 'condensateur 1000uf/100v', 'Nichicon', 'Radial', 2, 'No', 5, 23, 0, 0.00, '', '', 202, 'No', '', 'img/RADIAL.jpg', '2025-07-14 10:40:44', 5, ''),
(25, 1, 'condensateur 1000uf/63v', 'Nichicon', 'Radial', 2, 'No', 4, 22, 0, 0.00, '', '', 202, 'No', '', 'img/RADIAL.jpg', '2025-07-14 10:40:44', 3, ''),
(26, 1, 'condensateur 100uf/50v', 'Nichicon', 'Radial', 2, 'No', 10, 4, 0, 0.00, '', '', 202, 'No', '', 'img/RADIAL.jpg', '2025-07-14 10:40:44', 3, '592-356'),
(28, 1, 'condensateur 150uf/50v', 'Nichicon', 'Radial', 2, 'No', 3, 21, 0, 0.00, '', '', 202, 'No', '', 'img/RADIAL.jpg', '2025-07-14 10:40:44', 4, ''),
(29, 1, 'IRFB7430PBF', 'Infineon', 'TO-220', 3, 'No', 3, 3, 0, 2.12, 'https://www.mouser.fr/datasheet/2/196/Infineon_IRFB7430_DataSheet_v01_01_EN-1732586.pdf', 'MOSFET, Canal N, 195 A, 40 V', 1208, 'No', NULL, 'img/TO-220.jpg', '2025-07-14 10:40:44', 4, '776-9172'),
(30, 1, 'L293D', 'STMicroelectronics', 'DIP-16', 16, 'No', 6, 25, 0, 1.20, '', '', 512, 'No', '', 'img/DIP-16.jpg', '2025-07-14 10:40:44', 3, ''),
(31, 1, 'LNK305PN', 'POWER INTEGRATIONS', 'DIP-8', 8, 'No', 15, 24, 0, 0.00, 'http://www.farnell.com/datasheets/96901.pdf', NULL, 512, 'No', NULL, 'img/DIP-8.jpg', '2025-07-14 10:40:44', 3, '1448223'),
(33, 1, 'Fusible réarmable 18A-30V', 'TE  CONNECTIVITY', 'RADIAL', 2, 'No', 3, 214, 0, 0.82, 'http://fr.rs-online.com/web/p/fusibles-a-terminaison-a-fil-rearmables/5176893/?searchTerm=5176893&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31', 'Fusible réarmable,18A - 9A, 30 V c.c.', 901, 'No', NULL, 'img/component_33_1752489770.jpg', '2025-07-14 10:40:44', NULL, 'fuse'),
(35, 1, 'test rouiu', 'Murata', 'DIP-40', 40, 'No', 6, 1, 0, 2.10, NULL, NULL, 1803, 'No', NULL, 'img/DIP-40.jpg', '2025-07-19 10:23:28', 1, NULL),
(42, 1, '3.9V', 'PANASONIC', 'Mini-2 F-3B', 2, 'Yes', 8, NULL, 0, 0.31, 'http://fr.rs-online.com/web/p/diodes-zener/7602965/?searchTerm=7602965&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D313426736E3D5', 'RS 760-2965\nDZ2W03900L', 404, 'Yes', '', '', '2025-07-20 11:17:12', NULL, ''),
(43, 1, 'STD12NF06L', 'ST MICRO', 'IPAK', 3, 'No', 5, 215, 0, 1.18, 'http://www.farnell.com/datasheets/2124337.pdf?_ga=1.161348413.1030846565.1451571627', 'Transistor MOSFET, Canal N, 12 A, 60 V, 10 V, 2 V\nFA:2629746 ', 1208, 'Yes', '', 'img/IPAK.jpg', '2025-07-20 11:17:12', NULL, ''),
(44, 1, 'MCR100-8G', 'ON SEMICONDUCTOR', 'TO-92', 3, 'Yes', 4, 216, 0, 1.79, 'http://www.farnell.com/datasheets/675327.pdf?_ga=1.19863608.1030846565.1451571627', 'FA:9557288 \nThyristor, 600 V, 200 µA, 800 mA', 1207, 'Yes', '', 'img/TO-92.jpg', '2025-07-20 11:17:12', NULL, ''),
(45, 1, 'NVRAM DS1220AD-200', 'MAXIM', 'TRAVERSANTE', 24, 'No', 1, 237, 0, 12.94, 'http://fr.rs-online.com/web/p/memoires-nvram/0132659/?searchTerm=132659&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D313426736E3D', 'RS 132-659\n16kbit, 4,5 -> 5,5 V, DIP 24 broches', 516, 'Yes', '', '', '2025-07-20 11:17:12', NULL, ''),
(46, 1, 'BT137S 600V', 'NXP', 'TO-263', 3, 'Yes', 1, 238, 0, 0.83, 'http://www.farnell.com/datasheets/1758082.pdf', 'FA:1757883 ', 1206, 'Yes', '', 'img/TO-263 (D2PAK).jpg', '2025-07-20 11:17:12', NULL, ''),
(47, 1, 'TC7660CPA', 'MICROSHIP', 'DIP-8', 8, 'No', 1, 239, 0, 0.83, 'http://fr.rs-online.com/web/p/convertisseurs-dc-dc/2070297/?searchTerm=2070297&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D31342', 'RS 207-0297\r\nEntrée 1.5V->10V; Sortie -1.5 -> -10V', 1103, '1', NULL, 'img/DIP-8.jpg', '2025-07-20 11:17:12', NULL, NULL),
(48, 1, 'BZX585', 'NXP', 'SOD-523', 2, 'Yes', 24, 242, 0, 0.09, 'http://fr.rs-online.com/web/p/diodes-zener/7920992/', 'RS 792-0992\nDiode Zener NXP 1, Simple, 3.3V ', 404, 'Yes', '', 'img/SOD-523.jpg', '2025-07-20 11:17:12', NULL, ''),
(49, 1, 'MC3403L', 'TEXAS INSTRUMENT', 'DIP-14', 14, 'No', 2, 243, 0, 0.65, 'https://www.onsemi.com/pub/Collateral/MC3403-D.PDF', ' RS 732-0718\n1MHz, 6 -> 28 V PDIP', 505, 'Yes', '', 'img/DIP-14.jpg', '2025-07-20 11:17:12', NULL, ''),
(50, 1, '74HC02', 'TEXAS INSTRUMENT', 'DIP-14', 14, 'No', 1, 241, 0, 0.00, 'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=8&cad=rja&uact=8&sqi=2&ved=0ahUKEwiB0LyngLXQAhULWBoKHebRC-AQFggxMAc&url=http%3A%2F%2Fwww.alliedelec.com%2Fm%2Fd%2Fcd9aeaca44b93193b61e9073c0a369b4.pdf&usg=AFQjCNGSPcwMIUyMcbZ6LWdUcdOi9ikOgA&sig2', 'RS 442-909\nPorte Logique Quadruple, 2 -> 6 V', 502, 'Yes', '', 'img/DIP-14.jpg', '2025-07-20 11:17:12', NULL, ''),
(51, 1, 'AS6C62256-55SIN', 'ALLIANCE MEMORY', 'SOIC-28', 28, 'Yes', 1, 244, 0, 1.66, 'http://www.mouser.com/ds/2/12/AS6C62256-8819.pdf', 'MO:913-AS6C62256-55SIN \nSRAM 256K, 2.7-5.5V, 55ns 32K x 8 Asynch SRAM', 516, 'Yes', '', 'img/SOIC-28.jpg', '2025-07-20 11:17:12', NULL, ''),
(52, 1, 'CTN 100K', 'AVX', 'RADIAL', 2, 'No', 10, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/86141.pdf', 'FA:1672407\r\nRS:746-8204', 1502, '1', NULL, 'img/RADIAL.jpg', '2025-07-20 11:19:46', NULL, NULL),
(53, 1, 'CTN 4.7K', 'VISHAY', 'RADIAL', 2, 'No', 1, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187027 \n', 1502, 'Yes', '', 'img/RADIAL.jpg', '2025-07-20 11:19:46', NULL, ''),
(54, 1, 'NTE2379', 'NTE', 'TO-220', 3, 'No', 2, NULL, 0, 6.49, 'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI', 'MOSFET, 600 V; 6.2 A; 125 W;', 1208, '1', NULL, 'img/TO-220.jpg', '2025-07-20 11:19:46', NULL, NULL),
(55, 1, 'CTN 2.2K', 'VISHAY', 'RADIAL', 2, 'No', 3, NULL, 0, 0.40, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187025 \n', 1502, 'Yes', '', 'img/RADIAL.jpg', '2025-07-20 11:19:46', NULL, ''),
(56, 1, 'FQP3N60', 'FAIRCHILD', 'TO-220', 3, 'No', 1, NULL, 0, 0.72, 'http://www.promelec.ru/pdf/FQP3N60.pdf', 'FA:1848713', 1208, '1', NULL, 'img/TO-220.jpg', '2025-07-20 11:19:46', NULL, NULL),
(57, 1, 'Porte fusible vertical', 'MULTICOMP', '', 2, 'No', 3, NULL, 0, 2.60, 'http://www.farnell.com/datasheets/1661947.pdf', 'FA:149187 ', 703, 'Yes', '', 'http://fr.farnell.com/productimages/standard/fr_FR/42416190.jpg', '2025-07-20 11:19:46', NULL, ''),
(58, 1, 'AD595AQ', 'ANALOG DEVI', 'DIP-14', 14, 'No', 1, NULL, 0, 16.52, 'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf', 'Amplificateur à thermocouple, ±15V, 5 V 15kHz', 506, '1', NULL, 'img/DIP-14.jpg', '2025-07-20 11:19:46', NULL, NULL),
(62, 1, 'CTN 200K', 'AVX', 'RADIAL', 2, 'No', 10, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/86141.pdf', 'FA:1672407\nRS:746-8204', 1502, 'Yes', '', 'img/RADIAL.jpg', '2025-07-20 13:40:42', NULL, ''),
(63, 1, 'CTN 6,3K', 'VISHAY', 'RADIAL', 2, 'No', 100, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187027', 1502, '1', NULL, 'img/RADIAL.jpg', '2025-07-20 13:40:42', NULL, NULL),
(64, 1, 'NTE2380', 'NTE', 'TO-220', 3, 'No', 2, NULL, 0, 6.49, 'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI', 'MOSFET, 600 V; 6.2 A; 125 W;', 1208, 'Yes', '', 'img/TO-220.jpg', '2025-07-20 13:40:42', NULL, ''),
(65, 1, 'CTN 1,5K', 'VISHAY', 'RADIAL', 2, 'No', 3, NULL, 0, 0.40, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187025 \n', 1502, 'Yes', '', 'img/RADIAL.jpg', '2025-07-20 13:40:42', NULL, ''),
(66, 1, 'Porte fusible ', 'MULTICOMP', '', 2, 'No', 3, NULL, 0, 2.60, 'http://www.farnell.com/datasheets/1661947.pdf', 'FA:149187 ', 703, 'Yes', '', 'http://fr.farnell.com/productimages/standard/fr_FR/42416190.jpg', '2025-07-20 13:40:42', NULL, ''),
(67, 1, 'ULN2801', 'ANALOG DEVI', 'DIP-14', 14, 'No', 1, NULL, 0, 16.52, 'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf', 'Amplificateur à thermocouple, ±15V, 5 V 15kHz', 506, 'No', NULL, 'img/DIP-14.jpg', '2025-07-20 13:40:42', NULL, NULL),
(68, 1, 'CTN 300K', 'AVX', 'SOT-23', 2, 'Yes', 10, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/86141.pdf', 'FA:1672407\r\nRS:746-8204', 1502, 'No', NULL, 'img/SOT-23.jpg', '2025-07-20 13:47:46', 3, '1672407'),
(69, 1, 'CTN 1K', 'VISHAY', 'TO-3P', 2, 'No', 100, NULL, 0, 1.63, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187027', 1502, 'No', NULL, 'img/TO-3P.jpg', '2025-07-20 13:47:46', NULL, NULL),
(70, 1, 'NTE240', 'NTE', 'TO-220', 3, 'No', 2, NULL, 0, 6.49, 'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI', 'MOSFET, 600 V; 6.2 A; 125 W;', 1208, '1', NULL, 'img/TO-220.jpg', '2025-07-20 13:47:46', NULL, NULL),
(71, 1, '	74LS245', 'VISHAY', 'DIL-16', 2, 'No', 3, NULL, 0, 0.40, 'http://www.farnell.com/datasheets/1724877.pdf', 'FA:1187025 \n', 1502, 'Yes', '', 'img/DIP-16.jpg', '2025-07-20 13:47:46', NULL, ''),
(72, 1, 'FQP3N80', 'FAIRCHILD', 'TO-220AC', 5, 'No', 10, NULL, 0, 0.72, 'http://www.promelec.ru/pdf/FQP3N60.pdf', 'FA:1848713', 1208, '1', NULL, 'img/TO-220AC.jpg', '2025-07-20 13:47:46', NULL, NULL),
(73, 1, 'ULN2001', 'ANALOG DEVI', 'DIL-14', 14, 'No', 1, NULL, 0, 16.52, 'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf', 'Amplificateur à thermocouple, ±15V, 5 V 15kHz', 506, 'Yes', '', 'img/DIP-14.jpg', '2025-07-20 13:47:46', NULL, '');

-- --------------------------------------------------------

--
-- Structure de la table `location`
--

CREATE TABLE `location` (
  `id` int(11) NOT NULL,
  `owner` int(11) NOT NULL,
  `casier` varchar(255) DEFAULT NULL,
  `tiroir` varchar(255) DEFAULT NULL,
  `compartiment` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `location`
--

INSERT INTO `location` (`id`, `owner`, `casier`, `tiroir`, `compartiment`, `description`, `created_at`) VALUES
(1, 1, 'A', '1', '1', '', '2025-07-05 22:05:48'),
(2, 1, 'A', '1', '2', '', '2025-07-05 22:05:48'),
(3, 1, 'A', '1', '3', '', '2025-07-05 22:05:48'),
(4, 1, 'A', '1', '4', '', '2025-07-05 22:05:48'),
(5, 1, 'A', '2', '1', '', '2025-07-05 22:05:48'),
(6, 1, 'A', '2', '2', '', '2025-07-05 22:05:48'),
(7, 1, 'A', '2', '3', '', '2025-07-05 22:05:48'),
(8, 1, 'A', '2', '4', '', '2025-07-05 22:05:48'),
(9, 1, 'A', '3', '1', '', '2025-07-05 22:05:48'),
(10, 1, 'A', '3', '2', '', '2025-07-05 22:05:48'),
(11, 1, 'A', '3', '3', '', '2025-07-05 22:05:48'),
(12, 1, 'A', '3', '4', '', '2025-07-05 22:05:48'),
(13, 1, 'A', '4', '1', '', '2025-07-05 22:05:48'),
(14, 1, 'A', '4', '2', '', '2025-07-05 22:05:48'),
(15, 1, 'A', '4', '3', '', '2025-07-05 22:05:48'),
(16, 1, 'A', '4', '4', '', '2025-07-05 22:05:48'),
(17, 1, 'A', '5', '1', '', '2025-07-05 22:05:48'),
(18, 1, 'A', '5', '2', '', '2025-07-05 22:05:48'),
(19, 1, 'A', '5', '3', '', '2025-07-05 22:05:48'),
(20, 1, 'A', '5', '4', '', '2025-07-05 22:05:48'),
(21, 1, 'A', '11', '1', '', '2025-07-05 22:05:48'),
(22, 1, 'A', '11', '2', '', '2025-07-05 22:05:48'),
(23, 1, 'A', '11', '3', '', '2025-07-05 22:05:48'),
(24, 1, 'A', '11', '4', '', '2025-07-05 22:05:48'),
(25, 1, 'A', '12', '1', '', '2025-07-05 22:05:48'),
(26, 1, 'A', '12', '2', '', '2025-07-05 22:05:48'),
(27, 1, 'A', '12', '3', '', '2025-07-05 22:05:48'),
(28, 1, 'A', '12', '4', '', '2025-07-05 22:05:48'),
(29, 1, 'A', '13', '1', '', '2025-07-05 22:05:48'),
(30, 1, 'A', '13', '2', '', '2025-07-05 22:05:48'),
(31, 1, 'A', '13', '3', '', '2025-07-05 22:05:48'),
(32, 1, 'A', '13', '4', '', '2025-07-05 22:05:48'),
(33, 1, 'A', '14', '1', '', '2025-07-05 22:05:48'),
(34, 1, 'A', '14', '2', '', '2025-07-05 22:05:48'),
(35, 1, 'A', '14', '3', '', '2025-07-05 22:05:48'),
(36, 1, 'A', '14', '4', '', '2025-07-05 22:05:48'),
(37, 1, 'A', '15', '1', '', '2025-07-05 22:05:48'),
(38, 1, 'A', '15', '2', '', '2025-07-05 22:05:48'),
(39, 1, 'A', '15', '3', '', '2025-07-05 22:05:48'),
(40, 1, 'A', '15', '4', '', '2025-07-05 22:05:48'),
(41, 1, 'A', '21', '1', '', '2025-07-05 22:05:48'),
(42, 1, 'A', '21', '2', '', '2025-07-05 22:05:48'),
(43, 1, 'A', '21', '3', '', '2025-07-05 22:05:48'),
(44, 1, 'A', '21', '4', '', '2025-07-05 22:05:48'),
(45, 1, 'A', '22', '1', '', '2025-07-05 22:05:48'),
(46, 1, 'A', '22', '2', '', '2025-07-05 22:05:48'),
(47, 1, 'A', '22', '3', '', '2025-07-05 22:05:48'),
(48, 1, 'A', '22', '4', '', '2025-07-05 22:05:48'),
(49, 1, 'A', '23', '1', '', '2025-07-05 22:05:48'),
(50, 1, 'A', '23', '2', '', '2025-07-05 22:05:48'),
(51, 1, 'A', '23', '3', '', '2025-07-05 22:05:48'),
(52, 1, 'A', '23', '4', '', '2025-07-05 22:05:48'),
(53, 1, 'A', '24', '1', '', '2025-07-05 22:05:48'),
(54, 1, 'A', '24', '2', '', '2025-07-05 22:05:48'),
(55, 1, 'A', '24', '3', '', '2025-07-05 22:05:48'),
(56, 1, 'A', '24', '4', '', '2025-07-05 22:05:48'),
(57, 1, 'A', '25', '1', '', '2025-07-05 22:05:48'),
(58, 1, 'A', '25', '2', '', '2025-07-05 22:05:48'),
(59, 1, 'A', '25', '3', '', '2025-07-05 22:05:48'),
(60, 1, 'A', '25', '4', '', '2025-07-05 22:05:48'),
(61, 1, 'A', '31', '1', '', '2025-07-05 22:05:48'),
(62, 1, 'A', '31', '2', '', '2025-07-05 22:05:48'),
(63, 1, 'A', '31', '3', '', '2025-07-05 22:05:48'),
(64, 1, 'A', '31', '4', '', '2025-07-05 22:05:48'),
(65, 1, 'A', '32', '1', '', '2025-07-05 22:05:48'),
(66, 1, 'A', '32', '2', '', '2025-07-05 22:05:48'),
(67, 1, 'A', '32', '3', '', '2025-07-05 22:05:48'),
(68, 1, 'A', '32', '4', '', '2025-07-05 22:05:48'),
(69, 1, 'A', '33', '1', '', '2025-07-05 22:05:48'),
(70, 1, 'A', '33', '2', '', '2025-07-05 22:05:48'),
(71, 1, 'A', '33', '3', '', '2025-07-05 22:05:48'),
(72, 1, 'A', '33', '4', '', '2025-07-05 22:05:48'),
(73, 1, 'A', '34', '1', '', '2025-07-05 22:05:48'),
(74, 1, 'A', '34', '2', '', '2025-07-05 22:05:48'),
(75, 1, 'A', '34', '3', '', '2025-07-05 22:05:48'),
(76, 1, 'A', '34', '4', '', '2025-07-05 22:05:48'),
(77, 1, 'A', '35', '1', '', '2025-07-05 22:05:48'),
(78, 1, 'A', '35', '2', '', '2025-07-05 22:05:48'),
(79, 1, 'A', '35', '3', '', '2025-07-05 22:05:48'),
(80, 1, 'A', '35', '4', '', '2025-07-05 22:05:48'),
(81, 1, 'A', '41', '1', '', '2025-07-05 22:05:48'),
(82, 1, 'A', '41', '2', '', '2025-07-05 22:05:48'),
(83, 1, 'A', '41', '3', '', '2025-07-05 22:05:48'),
(84, 1, 'A', '41', '4', '', '2025-07-05 22:05:48'),
(85, 1, 'A', '42', '1', '', '2025-07-05 22:05:48'),
(86, 1, 'A', '42', '2', '', '2025-07-05 22:05:48'),
(87, 1, 'A', '42', '3', '', '2025-07-05 22:05:48'),
(88, 1, 'A', '42', '4', '', '2025-07-05 22:05:48'),
(89, 1, 'A', '43', '1', '', '2025-07-05 22:05:48'),
(90, 1, 'A', '43', '2', '', '2025-07-05 22:05:48'),
(91, 1, 'A', '43', '3', '', '2025-07-05 22:05:48'),
(92, 1, 'A', '43', '4', '', '2025-07-05 22:05:48'),
(93, 1, 'A', '44', '1', '', '2025-07-05 22:05:48'),
(94, 1, 'A', '44', '2', '', '2025-07-05 22:05:48'),
(95, 1, 'A', '44', '3', '', '2025-07-05 22:05:48'),
(96, 1, 'A', '44', '4', '', '2025-07-05 22:05:48'),
(97, 1, 'A', '45', '1', '', '2025-07-05 22:05:48'),
(98, 1, 'A', '45', '2', '', '2025-07-05 22:05:48'),
(99, 1, 'A', '45', '3', '', '2025-07-05 22:05:48'),
(100, 1, 'A', '45', '4', '', '2025-07-05 22:05:48'),
(101, 1, 'B', '10', '1', '', '2025-07-14 10:33:19'),
(102, 1, 'B', '10', '2', '', '2025-07-14 10:33:19'),
(103, 1, 'B', '10', '3', '', '2025-07-14 10:33:19'),
(104, 1, 'B', '10', '4', '', '2025-07-14 10:33:19'),
(105, 1, 'B', '11', '1', '', '2025-07-14 10:33:19'),
(106, 1, 'B', '11', '2', '', '2025-07-14 10:33:19'),
(107, 1, 'B', '11', '3', '', '2025-07-14 10:33:19'),
(108, 1, 'B', '11', '4', '', '2025-07-14 10:33:19'),
(109, 1, 'B', '12', '1', '', '2025-07-14 10:33:19'),
(110, 1, 'B', '12', '2', '', '2025-07-14 10:33:19'),
(111, 1, 'B', '12', '3', '', '2025-07-14 10:33:19'),
(112, 1, 'B', '12', '4', '', '2025-07-14 10:33:19'),
(113, 1, 'B', '13', '1', '', '2025-07-14 10:33:19'),
(114, 1, 'B', '13', '2', '', '2025-07-14 10:33:19'),
(115, 1, 'B', '13', '3', '', '2025-07-14 10:33:19'),
(116, 1, 'B', '13', '4', '', '2025-07-14 10:33:19'),
(117, 1, 'B', '14', '1', '', '2025-07-14 10:33:19'),
(118, 1, 'B', '14', '2', '', '2025-07-14 10:33:19'),
(119, 1, 'B', '14', '3', '', '2025-07-14 10:33:19'),
(120, 1, 'B', '14', '4', '', '2025-07-14 10:33:19'),
(121, 1, 'B', '20', '1', '', '2025-07-14 10:33:19'),
(122, 1, 'B', '20', '2', '', '2025-07-14 10:33:19'),
(123, 1, 'B', '20', '3', '', '2025-07-14 10:33:19'),
(124, 1, 'B', '20', '4', '', '2025-07-14 10:33:19'),
(125, 1, 'B', '21', '1', '', '2025-07-14 10:33:19'),
(126, 1, 'B', '21', '2', '', '2025-07-14 10:33:19'),
(127, 1, 'B', '21', '3', '', '2025-07-14 10:33:19'),
(128, 1, 'B', '21', '4', '', '2025-07-14 10:33:19'),
(129, 1, 'B', '22', '1', '', '2025-07-14 10:33:19'),
(130, 1, 'B', '22', '2', '', '2025-07-14 10:33:19'),
(131, 1, 'B', '22', '3', '', '2025-07-14 10:33:19'),
(132, 1, 'B', '22', '4', '', '2025-07-14 10:33:19'),
(133, 1, 'B', '23', '1', '', '2025-07-14 10:33:19'),
(134, 1, 'B', '23', '2', '', '2025-07-14 10:33:19'),
(135, 1, 'B', '23', '3', '', '2025-07-14 10:33:19'),
(136, 1, 'B', '23', '4', '', '2025-07-14 10:33:19'),
(137, 1, 'B', '24', '1', '', '2025-07-14 10:33:19'),
(138, 1, 'B', '24', '2', '', '2025-07-14 10:33:19'),
(139, 1, 'B', '24', '3', '', '2025-07-14 10:33:19'),
(140, 1, 'B', '24', '4', '', '2025-07-14 10:33:19'),
(141, 1, 'B', '30', '1', '', '2025-07-14 10:33:19'),
(142, 1, 'B', '30', '2', '', '2025-07-14 10:33:19'),
(143, 1, 'B', '30', '3', '', '2025-07-14 10:33:19'),
(144, 1, 'B', '30', '4', '', '2025-07-14 10:33:19'),
(145, 1, 'B', '31', '1', '', '2025-07-14 10:33:19'),
(146, 1, 'B', '31', '2', '', '2025-07-14 10:33:19'),
(147, 1, 'B', '31', '3', '', '2025-07-14 10:33:19'),
(148, 1, 'B', '31', '4', '', '2025-07-14 10:33:19'),
(149, 1, 'B', '32', '1', '', '2025-07-14 10:33:19'),
(150, 1, 'B', '32', '2', '', '2025-07-14 10:33:19'),
(151, 1, 'B', '32', '3', '', '2025-07-14 10:33:19'),
(152, 1, 'B', '32', '4', '', '2025-07-14 10:33:19'),
(153, 1, 'B', '33', '1', '', '2025-07-14 10:33:19'),
(154, 1, 'B', '33', '2', '', '2025-07-14 10:33:19'),
(155, 1, 'B', '33', '3', '', '2025-07-14 10:33:19'),
(156, 1, 'B', '33', '4', '', '2025-07-14 10:33:19'),
(157, 1, 'B', '34', '1', '', '2025-07-14 10:33:19'),
(158, 1, 'B', '34', '2', '', '2025-07-14 10:33:19'),
(159, 1, 'B', '34', '3', '', '2025-07-14 10:33:19'),
(160, 1, 'B', '34', '4', '', '2025-07-14 10:33:19'),
(161, 1, 'B', '40', '1', '', '2025-07-14 10:33:19'),
(162, 1, 'B', '40', '2', '', '2025-07-14 10:33:19'),
(163, 1, 'B', '40', '3', '', '2025-07-14 10:33:19'),
(164, 1, 'B', '40', '4', '', '2025-07-14 10:33:19'),
(165, 1, 'B', '41', '1', '', '2025-07-14 10:33:19'),
(166, 1, 'B', '41', '2', '', '2025-07-14 10:33:19'),
(167, 1, 'B', '41', '3', '', '2025-07-14 10:33:19'),
(168, 1, 'B', '41', '4', '', '2025-07-14 10:33:19'),
(169, 1, 'B', '42', '1', '', '2025-07-14 10:33:19'),
(170, 1, 'B', '42', '2', '', '2025-07-14 10:33:19'),
(171, 1, 'B', '42', '3', '', '2025-07-14 10:33:19'),
(172, 1, 'B', '42', '4', '', '2025-07-14 10:33:19'),
(173, 1, 'B', '43', '1', '', '2025-07-14 10:33:19'),
(174, 1, 'B', '43', '2', '', '2025-07-14 10:33:19'),
(175, 1, 'B', '43', '3', '', '2025-07-14 10:33:19'),
(176, 1, 'B', '43', '4', '', '2025-07-14 10:33:19'),
(177, 1, 'B', '44', '1', '', '2025-07-14 10:33:19'),
(178, 1, 'B', '44', '2', '', '2025-07-14 10:33:19'),
(179, 1, 'B', '44', '3', '', '2025-07-14 10:33:19'),
(180, 1, 'B', '44', '4', '', '2025-07-14 10:33:19'),
(181, 1, 'B', '50', '1', '', '2025-07-14 10:33:19'),
(182, 1, 'B', '50', '2', '', '2025-07-14 10:33:19'),
(183, 1, 'B', '50', '3', '', '2025-07-14 10:33:19'),
(184, 1, 'B', '50', '4', '', '2025-07-14 10:33:19'),
(185, 1, 'B', '51', '1', '', '2025-07-14 10:33:19'),
(186, 1, 'B', '51', '2', '', '2025-07-14 10:33:19'),
(187, 1, 'B', '51', '3', '', '2025-07-14 10:33:19'),
(188, 1, 'B', '51', '4', '', '2025-07-14 10:33:19'),
(189, 1, 'B', '52', '1', '', '2025-07-14 10:33:19'),
(190, 1, 'B', '52', '2', '', '2025-07-14 10:33:19'),
(191, 1, 'B', '52', '3', '', '2025-07-14 10:33:19'),
(192, 1, 'B', '52', '4', '', '2025-07-14 10:33:19'),
(193, 1, 'B', '53', '1', '', '2025-07-14 10:33:19'),
(194, 1, 'B', '53', '2', '', '2025-07-14 10:33:19'),
(195, 1, 'B', '53', '3', '', '2025-07-14 10:33:19'),
(196, 1, 'B', '53', '4', '', '2025-07-14 10:33:19'),
(197, 1, 'B', '54', '1', '', '2025-07-14 10:33:19'),
(198, 1, 'B', '54', '2', '', '2025-07-14 10:33:19'),
(199, 1, 'B', '54', '3', '', '2025-07-14 10:33:19'),
(200, 1, 'B', '54', '4', '', '2025-07-14 10:33:19'),
(201, 1, 'B', '60', '1', '', '2025-07-14 10:33:19'),
(202, 1, 'B', '60', '2', '', '2025-07-14 10:33:19'),
(203, 1, 'B', '60', '3', '', '2025-07-14 10:33:19'),
(204, 1, 'B', '60', '4', '', '2025-07-14 10:33:19'),
(205, 1, 'B', '61', '1', '', '2025-07-14 10:33:19'),
(206, 1, 'B', '61', '2', '', '2025-07-14 10:33:19'),
(207, 1, 'B', '61', '3', '', '2025-07-14 10:33:19'),
(208, 1, 'B', '61', '4', '', '2025-07-14 10:33:19'),
(209, 1, 'B', '62', '1', '', '2025-07-14 10:33:19'),
(210, 1, 'B', '62', '2', '', '2025-07-14 10:33:19'),
(211, 1, 'B', '62', '3', '', '2025-07-14 10:33:19'),
(212, 1, 'B', '62', '4', '', '2025-07-14 10:33:19'),
(213, 1, 'B', '63', '1', '', '2025-07-14 10:33:19'),
(214, 1, 'B', '63', '2', '', '2025-07-14 10:33:19'),
(215, 1, 'B', '63', '3', '', '2025-07-14 10:33:19'),
(216, 1, 'B', '63', '4', '', '2025-07-14 10:33:19'),
(217, 1, 'B', '64', '1', '', '2025-07-14 10:33:19'),
(218, 1, 'B', '64', '2', '', '2025-07-14 10:33:19'),
(219, 1, 'B', '64', '3', '', '2025-07-14 10:33:19'),
(220, 1, 'B', '64', '4', '', '2025-07-14 10:33:19'),
(221, 1, 'B', '70', '1', '', '2025-07-14 10:33:19'),
(222, 1, 'B', '70', '2', '', '2025-07-14 10:33:19'),
(223, 1, 'B', '70', '3', '', '2025-07-14 10:33:19'),
(224, 1, 'B', '70', '4', '', '2025-07-14 10:33:19'),
(225, 1, 'B', '71', '1', '', '2025-07-14 10:33:19'),
(226, 1, 'B', '71', '2', '', '2025-07-14 10:33:19'),
(227, 1, 'B', '71', '3', '', '2025-07-14 10:33:19'),
(228, 1, 'B', '71', '4', '', '2025-07-14 10:33:19'),
(229, 1, 'B', '72', '1', '', '2025-07-14 10:33:19'),
(230, 1, 'B', '72', '2', '', '2025-07-14 10:33:19'),
(231, 1, 'B', '72', '3', '', '2025-07-14 10:33:19'),
(232, 1, 'B', '72', '4', '', '2025-07-14 10:33:19'),
(233, 1, 'B', '73', '1', '', '2025-07-14 10:33:19'),
(234, 1, 'B', '73', '2', '', '2025-07-14 10:33:19'),
(235, 1, 'B', '73', '3', '', '2025-07-14 10:33:19'),
(236, 1, 'B', '73', '4', '', '2025-07-14 10:33:19'),
(237, 1, 'B', '74', '1', '', '2025-07-14 10:33:19'),
(238, 1, 'B', '74', '2', '', '2025-07-14 10:33:19'),
(239, 1, 'B', '74', '3', '', '2025-07-14 10:33:19'),
(240, 1, 'B', '74', '4', '', '2025-07-14 10:33:19'),
(241, 1, 'B', '80', '1', '', '2025-07-14 10:33:19'),
(242, 1, 'B', '80', '2', '', '2025-07-14 10:33:19'),
(243, 1, 'B', '80', '3', '', '2025-07-14 10:33:19'),
(244, 1, 'B', '80', '4', '', '2025-07-14 10:33:19'),
(245, 1, 'B', '81', '1', '', '2025-07-14 10:33:19'),
(246, 1, 'B', '81', '2', '', '2025-07-14 10:33:19'),
(247, 1, 'B', '81', '3', '', '2025-07-14 10:33:19'),
(248, 1, 'B', '81', '4', '', '2025-07-14 10:33:19'),
(249, 1, 'B', '82', '1', '', '2025-07-14 10:33:19'),
(250, 1, 'B', '82', '2', '', '2025-07-14 10:33:19'),
(251, 1, 'B', '82', '3', '', '2025-07-14 10:33:19'),
(252, 1, 'B', '82', '4', '', '2025-07-14 10:33:19'),
(253, 1, 'B', '83', '1', '', '2025-07-14 10:33:19'),
(254, 1, 'B', '83', '2', '', '2025-07-14 10:33:19'),
(255, 1, 'B', '83', '3', '', '2025-07-14 10:33:19'),
(256, 1, 'B', '83', '4', '', '2025-07-14 10:33:19'),
(257, 1, 'B', '84', '1', '', '2025-07-14 10:33:19'),
(258, 1, 'B', '84', '2', '', '2025-07-14 10:33:19'),
(259, 1, 'B', '84', '3', '', '2025-07-14 10:33:19'),
(260, 1, 'B', '84', '4', '', '2025-07-14 10:33:19'),
(261, 1, 'B', '90', '1', '', '2025-07-14 10:33:19'),
(262, 1, 'B', '90', '2', '', '2025-07-14 10:33:19'),
(263, 1, 'B', '90', '3', '', '2025-07-14 10:33:19'),
(264, 1, 'B', '90', '4', '', '2025-07-14 10:33:19'),
(265, 1, 'B', '91', '1', '', '2025-07-14 10:33:19'),
(266, 1, 'B', '91', '2', '', '2025-07-14 10:33:19'),
(267, 1, 'B', '91', '3', '', '2025-07-14 10:33:19'),
(268, 1, 'B', '91', '4', '', '2025-07-14 10:33:19'),
(269, 1, 'B', '92', '1', '', '2025-07-14 10:33:19'),
(270, 1, 'B', '92', '2', '', '2025-07-14 10:33:19'),
(271, 1, 'B', '92', '3', '', '2025-07-14 10:33:19'),
(272, 1, 'B', '92', '4', '', '2025-07-14 10:33:19'),
(273, 1, 'B', '93', '1', '', '2025-07-14 10:33:19'),
(274, 1, 'B', '93', '2', '', '2025-07-14 10:33:19'),
(275, 1, 'B', '93', '3', '', '2025-07-14 10:33:19'),
(276, 1, 'B', '93', '4', '', '2025-07-14 10:33:19'),
(277, 1, 'B', '94', '1', '', '2025-07-14 10:33:19'),
(278, 1, 'B', '94', '2', '', '2025-07-14 10:33:19'),
(279, 1, 'B', '94', '3', '', '2025-07-14 10:33:19'),
(280, 1, 'B', '94', '4', '', '2025-07-14 10:33:19'),
(281, 1, 'B', '100', '1', '', '2025-07-14 10:33:19'),
(282, 1, 'B', '100', '2', '', '2025-07-14 10:33:19'),
(283, 1, 'B', '100', '3', '', '2025-07-14 10:33:19'),
(284, 1, 'B', '100', '4', '', '2025-07-14 10:33:19'),
(285, 1, 'B', '101', '1', '', '2025-07-14 10:33:19'),
(286, 1, 'B', '101', '2', '', '2025-07-14 10:33:19'),
(287, 1, 'B', '101', '3', '', '2025-07-14 10:33:19'),
(288, 1, 'B', '101', '4', '', '2025-07-14 10:33:19'),
(289, 1, 'B', '102', '1', '', '2025-07-14 10:33:19'),
(290, 1, 'B', '102', '2', '', '2025-07-14 10:33:19'),
(291, 1, 'B', '102', '3', '', '2025-07-14 10:33:19'),
(292, 1, 'B', '102', '4', '', '2025-07-14 10:33:19'),
(293, 1, 'B', '103', '1', '', '2025-07-14 10:33:19'),
(294, 1, 'B', '103', '2', '', '2025-07-14 10:33:19'),
(295, 1, 'B', '103', '3', '', '2025-07-14 10:33:19'),
(296, 1, 'B', '103', '4', '', '2025-07-14 10:33:19'),
(297, 1, 'B', '104', '1', '', '2025-07-14 10:33:19'),
(298, 1, 'B', '104', '2', '', '2025-07-14 10:33:19'),
(299, 1, 'B', '104', '3', '', '2025-07-14 10:33:19'),
(300, 1, 'B', '104', '4', '', '2025-07-14 10:33:19');

-- --------------------------------------------------------

--
-- Structure de la table `manufacturers`
--

CREATE TABLE `manufacturers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `manufacturers`
--

INSERT INTO `manufacturers` (`id`, `name`, `owner`, `created_at`) VALUES
(1, 'Nichicon', 1, '2025-07-05 23:14:24'),
(2, 'Murata', 1, '2025-07-05 23:14:24'),
(3, 'Texas instrument', 1, '2025-07-05 23:14:24'),
(4, 'STMicroelectronics', 1, '2025-07-05 23:14:24'),
(5, 'Infineon', 1, '2025-07-06 08:04:47'),
(6, 'POWER INTEGRATIONS', 1, '2025-07-07 08:57:36');

-- --------------------------------------------------------

--
-- Structure de la table `packages`
--

CREATE TABLE `packages` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `pin_count` int(11) DEFAULT NULL,
  `package_type` enum('DIP','SOIC','QFP','BGA','TO','SOT','TSSOP','MSOP','QFN','DFN','PLCC','PGA','LGA','CSP','Other','DO') DEFAULT 'Other',
  `pitch` decimal(5,2) DEFAULT NULL,
  `dimensions` varchar(255) DEFAULT NULL,
  `mounting_type` enum('Through-hole','Surface-mount','Both') DEFAULT 'Through-hole',
  `notes` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `packages`
--

INSERT INTO `packages` (`id`, `name`, `description`, `pin_count`, `package_type`, `pitch`, `dimensions`, `mounting_type`, `notes`, `image_path`, `owner`, `created_at`, `updated_at`) VALUES
(3, 'SOT-23', 'Small Outline Transistor 3 broches', 3, 'SOT', 0.95, '2.9x1.3x1.1mm', 'Surface-mount', 'Package très populaire pour transistors et régulateurs', NULL, 1, '2025-07-06 22:26:43', '2025-07-06 22:26:43'),
(4, 'TO-220', 'Transistor Outline package de puissance', 3, 'TO', 2.54, '10.4x4.6x9.9mm', 'Through-hole', 'Package standard pour composants de puissance avec dissipateur', NULL, 1, '2025-07-06 22:26:43', '2025-07-06 22:26:43'),
(6, 'DIP-18', 'Package DIP 18 broches', 18, 'DIP', 2.54, '22.9x6.4mm', 'Through-hole', 'Package pour microcontrôleurs moyens', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(7, 'DIP-20', 'Package DIP 20 broches', 20, 'DIP', 2.54, '25.4x6.4mm', 'Through-hole', 'Package pour microcontrôleurs et circuits complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(8, 'DIP-24', 'Package DIP 24 broches', 24, 'DIP', 2.54, '30.5x15.2mm', 'Through-hole', 'Package large pour circuits complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(10, 'DIP-40', 'Package DIP 40 broches', 40, 'DIP', 2.54, '50.8x15.2mm', 'Through-hole', 'Package pour microprocesseurs et circuits très complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(11, 'SOT-23-5', 'SOT-23 5 broches', 5, 'SOT', 0.95, '2.9x1.6x1.1mm', 'Surface-mount', 'Version 5 broches du SOT-23', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(12, 'SOT-23-6', 'SOT-23 6 broches', 6, 'SOT', 0.95, '2.9x1.6x1.1mm', 'Surface-mount', 'Version 6 broches du SOT-23', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(13, 'SOT-89', 'SOT-89 package de puissance', 3, 'SOT', 1.50, '4.5x2.5x1.5mm', 'Surface-mount', 'Package de puissance moyenne', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(14, 'SOT-223', 'SOT-223 package de puissance', 4, 'SOT', 2.30, '6.5x3.5x1.6mm', 'Surface-mount', 'Package de puissance avec dissipateur thermique', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(15, 'TO-92', 'Transistor Outline 92', 3, 'TO', 2.54, '5.2x4.2x4.6mm', 'Through-hole', 'Package standard pour transistors de signal', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(16, 'TO-220AB', 'TO-220 avec isolation', 3, 'TO', 2.54, '10.4x4.6x9.9mm', 'Through-hole', 'Version isolée du TO-220', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(17, 'TO-247', 'TO-247 haute puissance', 3, 'TO', 5.45, '20.8x6.6x15.9mm', 'Through-hole', 'Package haute puissance pour MOSFET et IGBT', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(19, 'SOIC-8', 'Small Outline IC 8 broches', 8, 'SOIC', 1.27, '4.9x3.9mm', 'Surface-mount', 'Package CMS standard', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(20, 'SOIC-14', 'Small Outline IC 14 broches', 14, 'SOIC', 1.27, '8.7x3.9mm', 'Surface-mount', 'Package CMS pour circuits logiques', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(21, 'SOIC-16', 'Small Outline IC 16 broches', 16, 'SOIC', 1.27, '9.9x3.9mm', 'Surface-mount', 'Package CMS populaire', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(22, 'SOIC-20', 'Small Outline IC 20 broches', 20, 'SOIC', 1.27, '12.8x7.5mm', 'Surface-mount', 'Package CMS large', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(23, 'SOIC-28', 'Small Outline IC 28 broches', 28, 'SOIC', 1.27, '17.9x7.5mm', 'Surface-mount', 'Package CMS pour circuits complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(24, 'TSSOP-8', 'Thin Shrink Small Outline Package 8 broches', 8, 'TSSOP', 0.65, '3.0x3.0mm', 'Surface-mount', 'Package très compact', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(25, 'TSSOP-14', 'TSSOP 14 broches', 14, 'TSSOP', 0.65, '5.0x4.4mm', 'Surface-mount', 'Package compact pour circuits intégrés', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(26, 'TSSOP-16', 'TSSOP 16 broches', 16, 'TSSOP', 0.65, '5.0x4.4mm', 'Surface-mount', 'Package compact populaire', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(27, 'TSSOP-20', 'TSSOP 20 broches', 20, 'TSSOP', 0.65, '6.5x4.4mm', 'Surface-mount', 'Package compact pour microcontrôleurs', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(28, 'TSSOP-28', 'TSSOP 28 broches', 28, 'TSSOP', 0.65, '9.7x4.4mm', 'Surface-mount', 'Package compact pour circuits avancés', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(33, 'TQFP-44', 'Thin Quad Flat Package 44 broches', 44, 'QFP', 0.80, '10x10mm', 'Surface-mount', 'Package fin pour microcontrôleurs', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(34, 'QFN-16', 'Quad Flat No-leads 16 broches', 16, 'QFN', 0.50, '3x3mm', 'Surface-mount', 'Package très compact sans pattes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(35, 'QFN-20', 'QFN 20 broches', 20, 'QFN', 0.50, '4x4mm', 'Surface-mount', 'Package compact pour circuits intégrés', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(36, 'QFN-24', 'QFN 24 broches', 24, 'QFN', 0.50, '4x4mm', 'Surface-mount', 'Package haute densité', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(37, 'QFN-32', 'QFN 32 broches', 32, 'QFN', 0.50, '5x5mm', 'Surface-mount', 'Package pour microcontrôleurs compacts', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(38, 'QFN-48', 'QFN 48 broches', 48, 'QFN', 0.40, '6x6mm', 'Surface-mount', 'Package très haute densité', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(39, 'BGA-64', 'Ball Grid Array 64 broches', 64, 'BGA', 0.80, '8x8mm', 'Surface-mount', 'Package à billes pour haute densité', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(40, 'BGA-100', 'BGA 100 broches', 100, 'BGA', 0.80, '10x10mm', 'Surface-mount', 'Package BGA pour processeurs', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(41, 'BGA-144', 'BGA 144 broches', 144, 'BGA', 0.80, '12x12mm', 'Surface-mount', 'Package BGA haute performance', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(42, 'BGA-256', 'BGA 256 broches', 256, 'BGA', 0.80, '17x17mm', 'Surface-mount', 'Package BGA pour processeurs complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(43, 'MSOP-8', 'Mini Small Outline Package 8 broches', 8, 'MSOP', 0.65, '3x3mm', 'Surface-mount', 'Package miniature', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(44, 'MSOP-10', 'MSOP 10 broches', 10, 'MSOP', 0.50, '3x3mm', 'Surface-mount', 'Package miniature haute densité', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(45, 'DFN-6', 'Dual Flat No-leads 6 broches', 6, 'DFN', 0.65, '2x2mm', 'Surface-mount', 'Package ultra-compact', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(46, 'DFN-8', 'DFN 8 broches', 8, 'DFN', 0.50, '2x3mm', 'Surface-mount', 'Package très compact sans pattes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(47, 'PLCC-20', 'Plastic Leaded Chip Carrier 20 broches', 20, 'PLCC', 1.27, '9x9mm', 'Both', 'Package carré avec pattes en J', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(48, 'PLCC-28', 'PLCC 28 broches', 28, 'PLCC', 1.27, '11.5x11.5mm', 'Both', 'Package pour microcontrôleurs', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(49, 'PLCC-44', 'PLCC 44 broches', 44, 'PLCC', 1.27, '16.6x16.6mm', 'Both', 'Package pour circuits complexes', NULL, 1, '2025-07-06 22:43:58', '2025-07-06 22:43:58'),
(51, 'DIP-16', 'Package DIP 16 broches standard', 16, 'DIP', 2.54, '19.3x6.4mm', 'Through-hole', 'Package standard pour microcontrôleurs et circuits logiques', NULL, 1, '2025-07-06 22:51:05', '2025-07-06 22:51:05'),
(52, 'DIP-8', 'Package DIP 8 broches standard', 8, 'DIP', 2.54, '9.9x6.4mm', 'Through-hole', 'Package très commun pour les circuits intégrés', NULL, 1, '2025-07-06 22:51:16', '2025-07-06 22:51:16'),
(53, 'DIP-14', 'Package DIP 14 broches', 14, 'DIP', 2.54, '17.8x6.4mm', 'Through-hole', 'Package standard pour circuits logiques', NULL, 1, '2025-07-06 22:51:25', '2025-07-06 22:51:25'),
(54, 'DIP-28', 'Package DIP 28 broches', 28, 'DIP', 2.54, '35.6x15.2mm', 'Through-hole', 'Package pour microcontrôleurs avancés', NULL, 1, '2025-07-06 22:51:31', '2025-07-06 22:51:31'),
(55, 'RADIAL', NULL, 2, 'Other', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-06 22:54:14', '2025-07-06 22:54:14'),
(72, '10x38', NULL, 2, 'Other', 38.00, '10x38mm', 'Through-hole', NULL, NULL, 1, '2025-07-19 17:56:01', '2025-07-19 17:56:01'),
(73, '5X20', NULL, 2, 'Other', 20.00, '5x20', 'Through-hole', NULL, NULL, 1, '2025-07-19 17:56:21', '2025-07-19 17:56:21'),
(74, '6X32', NULL, 2, 'Other', 32.00, '6x32', 'Through-hole', NULL, NULL, 1, '2025-07-19 17:56:41', '2025-07-19 17:56:41'),
(75, 'DO-4', NULL, 2, 'DO', 5.00, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 18:00:07', '2025-07-19 19:06:57'),
(77, 'DO-15', NULL, 2, 'DO', 2.54, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:08:43', '2025-07-19 19:08:43'),
(78, 'DO-35', NULL, 2, 'DO', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:09:06', '2025-07-19 19:09:06'),
(79, 'DO-41', NULL, 2, 'DO', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:09:15', '2025-07-19 19:09:15'),
(80, 'DO-201', NULL, 2, 'DO', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:09:35', '2025-07-19 19:09:35'),
(81, 'DO-204', NULL, 2, 'DO', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:09:43', '2025-07-19 19:09:43'),
(82, 'DO-204-AA', NULL, 2, 'DIP', NULL, NULL, 'Through-hole', NULL, NULL, 1, '2025-07-19 19:09:59', '2025-07-19 19:10:08'),
(83, 'DO-214AA (SMB)', NULL, 2, 'DO', 2.75, '5.10x3.30mm', 'Surface-mount', NULL, NULL, 1, '2025-07-19 21:24:07', '2025-07-19 21:24:07'),
(84, 'DO-214AC (SMA)', NULL, 2, 'DO', 2.40, '4.80x2.25', 'Surface-mount', NULL, NULL, 1, '2025-07-19 21:24:54', '2025-07-19 21:25:24'),
(85, 'TO-252 (D-PAK)', NULL, 3, 'TO', 2.54, '6.58x6.10mm', 'Surface-mount', NULL, NULL, 1, '2025-07-19 21:29:04', '2025-07-19 21:29:04'),
(86, 'TO-263 (D2PAK)', NULL, 3, 'TO', NULL, '9.65x8.63mm', 'Surface-mount', NULL, NULL, 1, '2025-07-19 21:31:53', '2025-07-19 21:31:53');

-- --------------------------------------------------------

--
-- Structure de la table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `owner` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `status` enum('En cours','Terminé','En attente','Annulé') DEFAULT 'En cours',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `projects`
--

INSERT INTO `projects` (`id`, `owner`, `name`, `description`, `image_path`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Ghostbusters', '', 'projets/Ghostbusters/project_image_1752331141.png', 'En cours', '2025-07-12 09:13:39', '2025-07-12 14:39:01');

-- --------------------------------------------------------

--
-- Structure de la table `project_components`
--

CREATE TABLE `project_components` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `component_id` int(11) NOT NULL,
  `quantity_needed` int(11) DEFAULT 1,
  `quantity_used` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `project_components`
--

INSERT INTO `project_components` (`id`, `project_id`, `component_id`, `quantity_needed`, `quantity_used`, `notes`, `added_at`) VALUES
(5, 1, 33, 2, 1, '', '2025-07-17 17:22:59'),
(7, 1, 24, 2, 2, '', '2025-07-19 09:14:57'),
(8, 1, 26, 2, 1, '', '2025-07-19 09:15:32'),
(10, 1, 35, 2, 0, '', '2025-07-19 10:31:41');

-- --------------------------------------------------------

--
-- Structure de la table `project_files`
--

CREATE TABLE `project_files` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_category` enum('document','photo','datasheet','programme','autre','schema') DEFAULT 'autre',
  `description` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `project_files`
--

INSERT INTO `project_files` (`id`, `project_id`, `file_name`, `original_name`, `display_name`, `file_path`, `file_type`, `file_size`, `file_category`, `description`, `uploaded_at`) VALUES
(1, 1, 'Infineon_IRFB7430_DataSheet_v01_01_EN-1732586-2_1752330966_0.pdf', 'Infineon_IRFB7430_DataSheet_v01_01_EN-1732586-2.pdf', 'Infineon_IRFB7430.pdf', 'projets/Ghostbusters/Infineon_IRFB7430_DataSheet_v01_01_EN-1732586-2_1752330966_0.pdf', 'application/pdf', 284865, 'datasheet', 'infineon', '2025-07-12 14:36:06'),
(27, 1, 'Gemini_Generated_Image_v1yv1av1yv1av1yv_1752915275.png', 'Gemini_Generated_Image_v1yv1av1yv1av1yv.png', 'Gemini', 'projets/Ghostbusters/Gemini_Generated_Image_v1yv1av1yv1av1yv_1752915275.png', 'image/png', 324039, 'schema', '', '2025-07-19 08:54:35'),
(28, 1, 'sscom5.13_.1__1752915433.zip', 'sscom5.13_.1_.zip', 'sscom5.13_.1_.zip', 'projets/Ghostbusters/sscom5.13_.1__1752915433.zip', 'application/x-zip-compressed', 420285, 'programme', '', '2025-07-19 08:57:13'),
(29, 1, 'export_composants_2025-07-14_19-31-09_1752915777.csv', 'export_composants_2025-07-14_19-31-09.csv', 'export_composant.csv', 'projets/Ghostbusters/export_composants_2025-07-14_19-31-09_1752915777.csv', 'application/vnd.ms-excel', 3766, 'autre', '', '2025-07-19 09:02:57'),
(30, 1, 'elecstock_1752918095.sql', 'elecstock.sql', 'elecstock.sql', 'projets/Ghostbusters/elecstock_1752918095.sql', 'application/octet-stream', 50627, 'programme', '', '2025-07-19 09:41:35');

-- --------------------------------------------------------

--
-- Structure de la table `project_items`
--

CREATE TABLE `project_items` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `type` enum('travail','matériel','service') NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `quantity_completed` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(50) DEFAULT 'unité',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_price` decimal(10,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `status` enum('En attente','En cours','Terminé') DEFAULT 'En attente',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `project_items`
--

INSERT INTO `project_items` (`id`, `project_id`, `type`, `name`, `description`, `quantity`, `quantity_completed`, `unit`, `unit_price`, `status`, `added_at`, `updated_at`) VALUES
(1, 1, 'matériel', 'PCB', '', 1.00, 0.00, 'pièce', 5.00, 'En attente', '2025-07-12 14:27:36', '2025-07-13 08:40:35'),
(2, 1, 'service', 'main d\'oeuvre', '', 1.50, 0.50, 'h', 90.00, 'En cours', '2025-07-13 08:26:15', '2025-07-13 08:44:52'),
(3, 1, 'travail', 'test', '', 1.00, 0.50, 'h', 5.00, 'En cours', '2025-07-13 08:36:43', '2025-07-19 10:15:57'),
(4, 1, 'matériel', 'pcb routage', '', 10.00, 3.00, 'pieces', 10.00, 'En cours', '2025-07-13 08:37:14', '2025-07-19 09:52:11');

-- --------------------------------------------------------

--
-- Structure de la table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `owner` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_person`, `email`, `phone`, `website`, `address`, `logo_path`, `notes`, `created_at`, `updated_at`, `owner`) VALUES
(1, 'Mouser Electronics', 'Support', 'support@mouser.com', '+1-817-804-3800', 'https://www.mouser.com', 'Texas, USA', 'img/MOUSER.png', NULL, '2025-07-06 09:18:24', '2025-07-20 14:44:22', 1),
(2, 'Digi-Key Electronics', 'Support', 'support@digikey.com', '+1-800-344-4539', 'https://www.digikey.com', 'Minnesota, USA', 'img/DIGIKEY.jpg', NULL, '2025-07-06 09:18:24', '2025-07-20 14:43:49', 1),
(3, 'Farnell', 'Support', 'support@farnell.com', '+44-113-263-6311', 'https://www.farnell.com', 'Leeds, UK', 'img/FARNELL.png', NULL, '2025-07-06 09:18:24', '2025-07-20 14:44:11', 1),
(4, 'RS Components', 'Support', 'support@rs-online.com', '+44-1536-444105', 'https://www.rs-online.com', 'Corby, UK', 'img/RS.png', NULL, '2025-07-06 09:18:24', '2025-07-20 14:44:33', 1),
(5, 'Conrad Electronic', 'Support', 'support@conrad.fr', '01-56-69-50-00', 'https://www.conrad.fr', 'France', 'img/CONRAD.png', NULL, '2025-07-06 09:18:24', '2025-07-20 14:43:39', 1);

-- --------------------------------------------------------

--
-- Structure de la table `supplier_contacts`
--

CREATE TABLE `supplier_contacts` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `created_at`) VALUES
(1, 'admin@compozit.fr', '$2y$10$ywKMBtPa3mzQ4NsbUbFUGe5rig4wKLuVmQEw/qWAecoN03njoylHK', '2025-07-05 21:32:59');


--
-- Index pour les tables déchargées
--

--
-- Index pour la table `category_head`
--
ALTER TABLE `category_head`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `category_sub`
--
ALTER TABLE `category_sub`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_head_id` (`category_head_id`);

--
-- Index pour la table `data`
--
ALTER TABLE `data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner` (`owner`),
  ADD KEY `category` (`category`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Index pour la table `data_params`
--
ALTER TABLE `data_params`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_data_param` (`data_id`,`param_id`),
  ADD KEY `idx_param_id` (`param_id`);

--
-- Index pour la table `location`
--
ALTER TABLE `location`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_location` (`owner`,`casier`,`tiroir`,`compartiment`);

--
-- Index pour la table `manufacturers`
--
ALTER TABLE `manufacturers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_manufacturer_owner` (`name`,`owner`),
  ADD KEY `owner` (`owner`);

--
-- Index pour la table `param_definitions`
--
ALTER TABLE `param_definitions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_param_scope` (`category_id`,`subcategory_id`,`param_key`);

--
-- Index pour la table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_owner` (`owner`),
  ADD KEY `idx_name` (`name`);

--
-- Index pour la table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner` (`owner`);

--
-- Index pour la table `project_components`
--
ALTER TABLE `project_components`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_project_component` (`project_id`,`component_id`),
  ADD KEY `component_id` (`component_id`);

--
-- Index pour la table `project_files`
--
ALTER TABLE `project_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Index pour la table `project_items`
--
ALTER TABLE `project_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Index pour la table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner` (`owner`);

--
-- Index pour la table `supplier_contacts`
--
ALTER TABLE `supplier_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_id` (`supplier_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `data`
--
ALTER TABLE `data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1000;

--
-- AUTO_INCREMENT pour la table `data_params`
--
ALTER TABLE `data_params`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `location`
--
ALTER TABLE `location`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=301;

--
-- AUTO_INCREMENT pour la table `manufacturers`
--
ALTER TABLE `manufacturers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT pour la table `param_definitions`
--
ALTER TABLE `param_definitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT pour la table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT pour la table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `project_components`
--
ALTER TABLE `project_components`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT pour la table `project_files`
--
ALTER TABLE `project_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT pour la table `project_items`
--
ALTER TABLE `project_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `supplier_contacts`
--
ALTER TABLE `supplier_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `category_sub`
--
ALTER TABLE `category_sub`
  ADD CONSTRAINT `category_sub_ibfk_1` FOREIGN KEY (`category_head_id`) REFERENCES `category_head` (`id`);

--
-- Contraintes pour la table `data`
--
ALTER TABLE `data`
  ADD CONSTRAINT `data_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `data_ibfk_2` FOREIGN KEY (`category`) REFERENCES `category_sub` (`id`),
  ADD CONSTRAINT `data_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `location` (`id`),
  ADD CONSTRAINT `data_ibfk_4` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Contraintes pour la table `location`
--
ALTER TABLE `location`
  ADD CONSTRAINT `location_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`);

--
-- Contraintes pour la table `manufacturers`
--
ALTER TABLE `manufacturers`
  ADD CONSTRAINT `manufacturers_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `project_components`
--
ALTER TABLE `project_components`
  ADD CONSTRAINT `project_components_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_components_ibfk_2` FOREIGN KEY (`component_id`) REFERENCES `data` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `project_files`
--
ALTER TABLE `project_files`
  ADD CONSTRAINT `project_files_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `project_items`
--
ALTER TABLE `project_items`
  ADD CONSTRAINT `project_items_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `supplier_contacts`
--
ALTER TABLE `supplier_contacts`
  ADD CONSTRAINT `supplier_contacts_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
