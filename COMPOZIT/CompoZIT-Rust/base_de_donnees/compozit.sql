-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: compozit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `compozit`
--
-- `-- CREATE DATABASE /*!32312 IF NOT EXISTS*/ `compozit` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;`
-- USE `compozit`;
--
-- Table structure for table `category_head`
--

DROP TABLE IF EXISTS `category_head`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `category_head` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `category_head`
--

LOCK TABLES `category_head` WRITE;
/*!40000 ALTER TABLE `category_head` DISABLE KEYS */;
INSERT INTO `category_head` VALUES (1,'Cables'),(2,'Condensateurs'),(3,'Connecteurs'),(4,'Diode'),(5,'CI'),(6,'Inductances'),(7,'Mecanique'),(8,'Opto/LED'),(9,'Protections'),(10,'Interrupteur/poussoirs'),(11,'Régulateurs/Transfo'),(12,'Transistors'),(13,'Resistances'),(14,'Ecrans'),(15,'Capteurs'),(16,'Modules'),(17,'Autres'),(18,'Oscillateurs');
/*!40000 ALTER TABLE `category_head` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `category_sub`
--

DROP TABLE IF EXISTS `category_sub`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `category_sub` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `category_head_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_head_id` (`category_head_id`),
  CONSTRAINT `category_sub_ibfk_1` FOREIGN KEY (`category_head_id`) REFERENCES `category_head` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1901 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `category_sub`
--

LOCK TABLES `category_sub` WRITE;
/*!40000 ALTER TABLE `category_sub` DISABLE KEYS */;
INSERT INTO `category_sub` VALUES (101,'Ruban',1),(102,'Coaxial',1),(103,'Rouleau',1),(104,'Cable PC',1),(105,'Signal/Data',1),(106,'Fibre optique',1),(107,'Dupont',1),(199,'Divers',1),(201,'Ceramique',2),(202,'Electrolytique',2),(203,'Polyester',2),(204,'Tantal',2),(205,'Variable',2),(206,'Supercondensateurs',2),(299,'Divers',2),(301,'Audio',3),(302,'Coaxial',3),(303,'DC-alim',3),(304,'D-Sub',3),(305,'HF',3),(306,'PCB',3),(307,'Cable PC',3),(308,'Data',3),(399,'Divers',3),(401,'Redresseur',4),(402,'Schottky',4),(403,'Petits signaux',4),(404,'Zener',4),(405,'TVS/Transil',4),(406,'Pont de diode',4),(499,'Divers',4),(501,'4xxx',5),(502,'74xx',5),(503,'Microcontroller',5),(504,'Comparateur',5),(505,'AOP',5),(506,'Temperature',5),(507,'Timer & Osc.',5),(508,'Référence de tension',5),(509,'Régulateur de tension',5),(510,'Convertisseur Data',5),(511,'A/D Multiplexeur',5),(512,'Driver',5),(513,'Opto Driver',5),(514,'Convertisseur DC/DC',5),(515,'Audio/Video',5),(516,'Memoires',5),(517,'Logic',5),(599,'Divers',5),(601,'Ferrite',6),(602,'Filtre',6),(603,'Inducteur',6),(699,'Divers',6),(701,'Box',7),(702,'Distance',7),(703,'Supports Fusibles',7),(704,'Moteurs',7),(705,'Relais',7),(708,'IC Socket',7),(709,'Radiateur',7),(710,'Bouton potar',7),(711,'Metre',7),(799,'Divers',7),(801,'Barrières photo-electrique',8),(802,'Laser',8),(803,'LED',8),(804,'LED 3mm',8),(805,'LED 5mm',8),(806,'Optocoupleur',8),(807,'IR LED',8),(808,'Ampoules',8),(899,'Divers',8),(901,'Fusibles',9),(902,'Varistances',9),(903,'Thermistances CTN',9),(904,'Thermistances CTP',9),(905,'Support Fusibles',9),(999,'Divers',9),(1001,'Clavier',10),(1002,'Momentanné',10),(1003,'Monté sur PCB',10),(1004,'Encodeur rotatif',10),(1005,'Maintient',10),(1007,'DIP',10),(1099,'Divers',10),(1101,'Alimentation',11),(1102,'Transformateur',11),(1103,'Convertisseur DC/DC',11),(1104,'Régulateur Linéaire',11),(1105,'Régulateur Ajustable',11),(1106,'Convertisseur AC/DC',11),(1199,'Divers',11),(1201,'JBT',12),(1202,'JFET',12),(1204,'NPN',12),(1205,'PNP',12),(1206,'Triac',12),(1207,'Thyristor',12),(1208,'MOSFET-N',12),(1209,'MOSFET-P',12),(1299,'Divers',12),(1301,'1/4W Carbon',13),(1302,'1/4W Metal',13),(1303,'1/6W Carbon',13),(1304,'1/6W Metal',13),(1305,'CMS-0603',13),(1306,'CMS-0805',13),(1307,'CMS-1206',13),(1308,'Effect',13),(1309,'Photo',13),(1310,'Réseaux',13),(1311,'Temperature',13),(1312,'Potentiometre',13),(1313,'1/3W Carbon',13),(1314,'1/3W Metal',13),(1315,'Precision',13),(1399,'Divers',13),(1401,'LCD',14),(1402,'VFD',14),(1403,'TFT',14),(1404,'LED',14),(1499,'Divers',14),(1501,'Humidité',15),(1502,'Temperature',15),(1503,'Pression',15),(1504,'Magnetique',15),(1505,'Effet hall',15),(1506,'Gaz',15),(1507,'Accelerometre',15),(1508,'Lumières',15),(1509,'Proximité',15),(1599,'Divers',15),(1601,'Ethernet',16),(1602,'Wifi/Bluetooth2',16),(1603,'Moteurs',16),(1604,'Drivers/Controleurs',16),(1605,'Arduino Mini/Micro',16),(1606,'Arduino UNO',16),(1607,'Arduino MEGA',16),(1608,'Raspberry Pi',16),(1609,'ESP32/8266',16),(1699,'Divers',16),(1701,'Outils',17),(1702,'Accessoires',17),(1703,'Kits',17),(1798,'Consommables',17),(1799,'Divers',17),(1801,'Crystal',18),(1802,'Resonateur',18),(1803,'RC',18),(1899,'Divers',18);
/*!40000 ALTER TABLE `category_sub` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_type` enum('particulier','entreprise') NOT NULL DEFAULT 'particulier',
  `company_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'France',
  `siret` varchar(30) DEFAULT NULL,
  `vat_number` varchar(30) DEFAULT NULL,
  `website` varchar(500) DEFAULT NULL,
  `logo_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_last_name` (`last_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `data`
--

DROP TABLE IF EXISTS `data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `datasheet_file` varchar(500) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `category` int(11) DEFAULT NULL,
  `public` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_reference` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `owner` (`owner`),
  KEY `category` (`category`),
  KEY `location_id` (`location_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `data_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`),
  CONSTRAINT `data_ibfk_2` FOREIGN KEY (`category`) REFERENCES `category_sub` (`id`),
  CONSTRAINT `data_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `location` (`id`),
  CONSTRAINT `data_ibfk_4` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `data`
--

LOCK TABLES `data` WRITE;
/*!40000 ALTER TABLE `data` DISABLE KEYS */;
INSERT INTO `data` VALUES (24,1,'condensateur 1000uf/100v','Nichicon','Radial',2,'No',5,23,0,0.00,'',NULL,'',202,'No','','img/RADIAL.jpg','2025-07-14 10:40:44',5,''),(25,1,'condensateur 1000uf/63v','Nichicon','Radial',2,'No',4,22,0,0.00,'',NULL,'',202,'No','','img/RADIAL.jpg','2025-07-14 10:40:44',3,''),(26,1,'condensateur 100uf/50v','Nichicon','Radial',2,'No',10,4,0,0.00,'',NULL,'',202,'No','','img/RADIAL.jpg','2025-07-14 10:40:44',3,'592-356'),(28,1,'condensateur 150uf/50v','Nichicon','Radial',2,'No',3,21,0,0.00,'',NULL,'',202,'No','','img/RADIAL.jpg','2025-07-14 10:40:44',4,''),(29,1,'IRFB7430PBF','Infineon','TO-220',3,'No',3,3,0,2.12,'https://www.mouser.fr/datasheet/2/196/Infineon_IRFB7430_DataSheet_v01_01_EN-1732586.pdf',NULL,'MOSFET, Canal N, 195 A, 40 V',1208,'No',NULL,'img/TO-220.jpg','2025-07-14 10:40:44',4,'776-9172'),(30,1,'L293D','STMicroelectronics','DIP-16',16,'No',6,25,0,1.20,'',NULL,'',512,'No','','img/DIP-16.jpg','2025-07-14 10:40:44',3,''),(31,1,'LNK305PN','POWER INTEGRATIONS','DIP-8',8,'No',15,24,0,0.00,'http://www.farnell.com/datasheets/96901.pdf',NULL,NULL,512,'No',NULL,'img/DIP-8.jpg','2025-07-14 10:40:44',3,'1448223'),(33,1,'Fusible réarmable 18A-30V','TE  CONNECTIVITY','RADIAL',2,'No',3,214,0,0.82,'http://fr.rs-online.com/web/p/fusibles-a-terminaison-a-fil-rearmables/5176893/?searchTerm=5176893&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31',NULL,'Fusible réarmable,18A - 9A, 30 V c.c.',901,'No',NULL,'img/component_33_1752489770.jpg','2025-07-14 10:40:44',NULL,'fuse'),(35,1,'test rouiu','Murata','DIP-40',40,'No',6,1,0,2.10,NULL,NULL,NULL,1803,'No',NULL,'img/DIP-40.jpg','2025-07-19 10:23:28',1,NULL),(42,1,'3.9V','PANASONIC','Mini-2 F-3B',2,'Yes',8,NULL,0,0.31,'http://fr.rs-online.com/web/p/diodes-zener/7602965/?searchTerm=7602965&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D313426736E3D5',NULL,'RS 760-2965\nDZ2W03900L',404,'Yes','','img/Mini2-F3-B.png','2025-07-20 11:17:12',NULL,''),(43,1,'STD12NF06L','ST MICRO','IPAK',3,'No',5,215,0,1.18,'http://www.farnell.com/datasheets/2124337.pdf?_ga=1.161348413.1030846565.1451571627',NULL,'Transistor MOSFET, Canal N, 12 A, 60 V, 10 V, 2 V\nFA:2629746 ',1208,'Yes','','img/IPAK.jpg','2025-07-20 11:17:12',NULL,''),(44,1,'MCR100-8G','ON SEMICONDUCTOR','TO-92',3,'Yes',4,216,0,1.79,'http://www.farnell.com/datasheets/675327.pdf?_ga=1.19863608.1030846565.1451571627',NULL,'FA:9557288 \nThyristor, 600 V, 200 µA, 800 mA',1207,'Yes','','img/TO-92.jpg','2025-07-20 11:17:12',NULL,''),(45,1,'NVRAM DS1220AD-200','MAXIM','TRAVERSANTE',24,'No',1,237,0,12.94,'http://fr.rs-online.com/web/p/memoires-nvram/0132659/?searchTerm=132659&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D313426736E3D',NULL,'RS 132-659\r\n16kbit, 4,5 -> 5,5 V, DIP 24 broches',516,'1',NULL,'img/NVRAM DS1220AD-200.jpg','2025-07-20 11:17:12',NULL,NULL),(46,1,'BT137S 600V','NXP','TO-263',3,'Yes',1,238,0,0.83,'http://www.farnell.com/datasheets/1758082.pdf',NULL,'FA:1757883 ',1206,'Yes','','img/TO-263 (D2PAK).jpg','2025-07-20 11:17:12',NULL,''),(47,1,'TC7660CPA','MICROSHIP','DIP-8',8,'No',1,239,0,0.83,'http://fr.rs-online.com/web/p/convertisseurs-dc-dc/2070297/?searchTerm=2070297&relevancy-data=636F3D3126696E3D4931384E525353746F636B4E756D6265724D504E266C753D656E266D6D3D6D61746368616C6C26706D3D5E5C647B367D247C5E5C647B377D247C5E5C647B31307D2426706F3D31342',NULL,'RS 207-0297\r\nEntrée 1.5V->10V; Sortie -1.5 -> -10V',1103,'1',NULL,'img/DIP-8.jpg','2025-07-20 11:17:12',NULL,NULL),(48,1,'BZX585','NXP','SOD-523',2,'Yes',24,242,0,0.09,'http://fr.rs-online.com/web/p/diodes-zener/7920992/',NULL,'RS 792-0992\nDiode Zener NXP 1, Simple, 3.3V ',404,'Yes','','img/SOD-523.jpg','2025-07-20 11:17:12',NULL,''),(49,1,'MC3403L','TEXAS INSTRUMENT','DIP-14',14,'No',2,243,0,0.65,'https://www.onsemi.com/pub/Collateral/MC3403-D.PDF',NULL,' RS 732-0718\n1MHz, 6 -> 28 V PDIP',505,'Yes','','img/DIP-14.jpg','2025-07-20 11:17:12',NULL,''),(50,1,'74HC02','TEXAS INSTRUMENT','DIP-14',14,'No',1,241,0,0.00,'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=8&cad=rja&uact=8&sqi=2&ved=0ahUKEwiB0LyngLXQAhULWBoKHebRC-AQFggxMAc&url=http%3A%2F%2Fwww.alliedelec.com%2Fm%2Fd%2Fcd9aeaca44b93193b61e9073c0a369b4.pdf&usg=AFQjCNGSPcwMIUyMcbZ6LWdUcdOi9ikOgA&sig2',NULL,'RS 442-909\nPorte Logique Quadruple, 2 -> 6 V',502,'Yes','','img/DIP-14.jpg','2025-07-20 11:17:12',NULL,''),(51,1,'AS6C62256-55SIN','ALLIANCE MEMORY','SOIC-28',28,'Yes',1,244,0,1.66,'http://www.mouser.com/ds/2/12/AS6C62256-8819.pdf',NULL,'MO:913-AS6C62256-55SIN \nSRAM 256K, 2.7-5.5V, 55ns 32K x 8 Asynch SRAM',516,'Yes','','img/SOIC-28.jpg','2025-07-20 11:17:12',NULL,''),(52,1,'CTN 100K','AVX','RADIAL',2,'No',10,NULL,0,1.63,'http://www.farnell.com/datasheets/86141.pdf',NULL,'FA:1672407\r\nRS:746-8204',1502,'1',NULL,'img/RADIAL.jpg','2025-07-20 11:19:46',NULL,NULL),(53,1,'CTN 4.7K','VISHAY','RADIAL',2,'No',1,NULL,0,1.63,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187027 \n',1502,'Yes','','img/RADIAL.jpg','2025-07-20 11:19:46',NULL,''),(54,1,'NTE2379','NTE','TO-220',3,'No',2,NULL,0,6.49,'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI',NULL,'MOSFET, 600 V; 6.2 A; 125 W;',1208,'1',NULL,'img/TO-220.jpg','2025-07-20 11:19:46',NULL,NULL),(55,1,'CTN 2.2K','VISHAY','RADIAL',2,'No',3,NULL,0,0.40,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187025 \n',1502,'Yes','','img/RADIAL.jpg','2025-07-20 11:19:46',NULL,''),(56,1,'FQP3N60','FAIRCHILD','TO-220',3,'No',1,NULL,0,0.72,'http://www.promelec.ru/pdf/FQP3N60.pdf',NULL,'FA:1848713',1208,'1',NULL,'img/TO-220.jpg','2025-07-20 11:19:46',NULL,NULL),(57,1,'Porte fusible vertical','MULTICOMP',NULL,2,'No',3,NULL,0,2.60,'http://www.farnell.com/datasheets/1661947.pdf',NULL,'FA:149187',703,'1',NULL,'img/Porte_fusible_vertical.webp','2025-07-20 11:19:46',NULL,NULL),(58,1,'AD595AQ','ANALOG DEVI','DIP-14',14,'No',0,NULL,0,16.52,'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf',NULL,'Amplificateur à thermocouple, ±15V, 5 V 15kHz',506,'1',NULL,'img/DIP-14.jpg','2025-07-20 11:19:46',NULL,NULL),(62,1,'CTN 200K','AVX','RADIAL',2,'No',10,NULL,0,1.63,'http://www.farnell.com/datasheets/86141.pdf',NULL,'FA:1672407\nRS:746-8204',1502,'Yes','','img/RADIAL.jpg','2025-07-20 13:40:42',NULL,''),(63,1,'CTN 6,3K','VISHAY','RADIAL',2,'No',100,NULL,0,1.63,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187027',1502,'1',NULL,'img/RADIAL.jpg','2025-07-20 13:40:42',NULL,NULL),(64,1,'NTE2380','NTE','TO-220',3,'No',2,NULL,0,6.49,'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI',NULL,'MOSFET, 600 V; 6.2 A; 125 W;',1208,'Yes','','img/TO-220.jpg','2025-07-20 13:40:42',NULL,''),(65,1,'CTN 1,5K','VISHAY','RADIAL',2,'No',3,NULL,0,0.40,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187025 \n',1502,'Yes','','img/RADIAL.jpg','2025-07-20 13:40:42',NULL,''),(66,1,'Porte fusible','MULTICOMP','',2,'1',3,NULL,0,2.60,'http://www.farnell.com/datasheets/1661947.pdf',NULL,'FA:149187 ',703,'Yes','','img/5x20.jpg','2025-07-20 13:40:42',NULL,''),(67,1,'ULN2801','ANALOG DEVI','DIP-14',14,'No',1,NULL,0,16.52,'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf',NULL,'Amplificateur à thermocouple, ±15V, 5 V 15kHz',506,'No',NULL,'img/DIP-14.jpg','2025-07-20 13:40:42',NULL,NULL),(68,1,'CTN 300K','AVX','SOT-23',2,'Yes',11,NULL,0,1.63,'http://www.farnell.com/datasheets/86141.pdf',NULL,'FA:1672407\r\nRS:746-8204',1502,'No',NULL,'img/SOT-23.jpg','2025-07-20 13:47:46',3,'1672407'),(69,1,'CTN 1K','VISHAY','TO-3P',2,'No',100,NULL,0,1.63,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187027',1502,'No',NULL,'img/TO-3P.jpg','2025-07-20 13:47:46',NULL,NULL),(70,1,'NTE240','NTE','TO-220',3,'No',2,NULL,0,6.49,'https://www.google.fr/url?sa=t&rct=j&q=&esrc=s&source=web&cd=1&cad=rja&uact=8&ved=0ahUKEwjFjtvc-bTQAhXEvxQKHf-8Cc0QFgggMAA&url=http%3A%2F%2Fwww.nteinc.com%2Fspecs%2F2300to2399%2Fpdf%2Fnte2379.pdf&usg=AFQjCNEc3YC__Am3l8tO0JgSGO4OTvrrkQ&sig2=14bNqDJLZjN0NmI',NULL,'MOSFET, 600 V; 6.2 A; 125 W;',1208,'1',NULL,'img/TO-220.jpg','2025-07-20 13:47:46',NULL,NULL),(71,1,'74LS245','VISHAY','DIL-16',2,'No',3,NULL,0,0.40,'http://www.farnell.com/datasheets/1724877.pdf',NULL,'FA:1187025',1502,'1',NULL,'img/DIP-16.jpg','2025-07-20 13:47:46',NULL,NULL),(72,1,'FQP3N80','FAIRCHILD','TO-220AC',5,'1',11,9,0,0.72,'http://www.promelec.ru/pdf/FQP3N60.pdf',NULL,'FA:1848713',1208,'1',NULL,'img/TO-220AC.jpg','2025-07-20 13:47:46',NULL,NULL),(73,1,'ULN2001','Analog Devices','DIL-14',14,'No',5,32,0,16.52,'https://docs-emea.rs-online.com/webdocs/14f4/0900766b814f496a.pdf',NULL,'Amplificateur à thermocouple, ±15V, 5 V 15kHz',506,'1',NULL,'img/DIP-14.jpg','2025-07-20 13:47:46',NULL,NULL),(1002,1,'ABCD2155','Infineon','SOT-23-5',5,'No',5,29,0,NULL,NULL,NULL,NULL,512,'No',NULL,'img/SOT-23-5.png','2026-08-23 12:38:11',5,NULL),(1003,1,'0.1UF 50V','NICHICON','RADIAL',2,'No',55,628,0,0.00,NULL,NULL,NULL,202,'Yes',NULL,'img/RADIAL.jpg','2026-08-25 09:42:16',NULL,NULL),(1004,1,'0.22UF 50V','NICHICON','RADIAL',2,'No',30,609,0,0.00,NULL,NULL,NULL,202,'Yes',NULL,'img/RADIAL.jpg','2026-08-25 09:42:16',NULL,NULL),(1005,1,'0.47UF 50V','NICHICON','RADIAL',2,'No',30,610,0,0.00,NULL,NULL,NULL,202,'Yes',NULL,'img/RADIAL.jpg','2026-08-25 09:42:16',NULL,NULL),(1006,1,'100UF 100V','NICHICON','RADIAL',2,'No',6,75,0,0.00,NULL,NULL,NULL,202,'Yes',NULL,'img/RADIAL.jpg','2026-08-25 09:42:16',NULL,NULL),(1007,1,'RESISTANCE 1.5KR','','TRAVERSANTE',2,'No',19,624,0,0.00,'',NULL,'',1301,'Yes','','img/RESISTANCE_1_4W.jpg','2026-08-25 09:42:16',NULL,''),(1008,1,'RESISTANCE 1.5MR',NULL,'res',2,'No',20,625,0,0.00,NULL,NULL,NULL,1301,'Yes',NULL,'img/RESISTANCE_1-4W.jpg','2026-08-25 09:42:16',NULL,NULL),(1009,1,'RESISTANCE 100KR','','TRAVERSANTE',2,'No',33,626,0,0.00,'',NULL,'',1301,'Yes','',NULL,'2026-08-25 09:42:16',NULL,''),(1010,1,'RESISTANCE 100R','','TRAVERSANTE',2,'No',7,627,0,0.00,'',NULL,'',1301,'Yes','',NULL,'2026-08-25 09:42:16',NULL,''),(1103,1,'74HCT244PW,118','NEXPERIA','TSSOP-20',20,'Yes',4,2,0,0.46,'https://4donline.ihs.com/images/VipMasterIC/IC/PHGL/PHGL-S-A0001573452/PHGL-S-A0001573452-1.pdf?hkey=6D3A4C79FDBF58556ACFDE234799DDF0',NULL,'NEXPERIA - 74HCT244PW,118 - Buffer / Driver de ligne, 3 états, Non-Inverseur, 4.5 V à 5.5 V, TSSOP-20',502,'No',NULL,'img/TSSOP-20.jpg','2026-10-16 21:46:54',3,'2463769'),(1104,1,'1N4007','ST(Semtech)','DO-41',2,'No',1,608,0,0.03,'https://www.lcsc.com/datasheet/lcsc_datasheet_1811151642_ST-Semtech-1N4007_C106903.pdf',NULL,'-55℃~+125℃ 1 Independent 1.1V@1A 1A 1kV 30A 5uA@1kV DO-41 Diodes - General Purpose ROHS',401,'No',NULL,'img/DO-41.jpg','2026-10-16 22:19:08',NULL,'C106903'),(1105,1,'LM217MDT-TR','STMicroelectronics','TO-252 (D-PAK)',3,'Yes',1,NULL,0,0.47,'http://www.farnell.com/datasheets/1997803.pdf',NULL,'STMICROELECTRONICS - LM217MDT-TR - Régulateur de tension linéaire, Ajustable, Entrée primaire 40V, 1 sortie 1.2V à 37V, 0.5A, TO-252-3',1104,'No',NULL,'img/TO-252 (D-PAK).jpg','2026-10-16 22:21:09',3,'1095399RL'),(1106,1,'SKKD 100/16 07897171','SEMIKRON DANFOSS','SEMIPACK1',7,'No',1,26,0,38.40,NULL,NULL,'Module: diode; double series; 1.6kV; If: 100A; SEMIPACK1; A10; screw',406,'No',NULL,'img/SEMIPACK1.jpg','2026-10-17 09:44:18',NULL,'SKKD100/16'),(1107,1,'TPS61165DBVR','Texas Instruments','SOT23-6',6,'Yes',3,NULL,0,1.48,NULL,NULL,'PMIC; DC/DC converter; Uin: 3÷18VDC; Uout: 3÷38VDC; 1.2A; SOT23-6',1103,'No',NULL,'img/TPS61165DBVR.jpg','2026-10-17 10:12:14',NULL,'TPS61165DBVR'),(1108,1,'TLC5928PWPR','Texas Instruments','HTSSOP-24',24,'Yes',3,NULL,0,1.60,'https://www.alldatasheet.com/view.jsp?Searchword=TLC5928PWPR',NULL,'TEXAS INSTRUMENTS - TLC5928PWPR - Driver de LED, 16 Sorties, Courant const. Entrée 3V-5.5V, Découp. 35MHz, Sortie 17V/35mA, HTSSOP-24',512,'No',NULL,'img/TLC5928PWPR.jpg','2026-10-17 10:19:50',3,'3119188'),(1109,1,'LM219DT','STMicroelectronics','SO14',14,'Yes',1,NULL,0,1.00,'https://www.tme.eu/Document/e4291f0cdcfa4810b5c1276d106b1338/LMx19.pdf',NULL,'IC: comparator; fast; Cmp: 2; 5÷15V; SMT; SO14; reel,tape; 100nA',504,'No',NULL,'img/LM219DT.jpg','2026-10-17 13:36:47',NULL,'LM219DT'),(1113,1,'J111','ONSEMI','TO-92',3,'No',4,27,0,0.37,'https://4donline.ihs.com/images/VipMasterIC/IC/ONSM/ONSM-S-A0013932363/ONSM-S-A0013775431-1.pdf?hkey=6D3A4C79FDBF58556ACFDE234799DDF0',NULL,'ONSEMI - J111 - Transistor JFET, 35 V, 20 mA, TO-92, 3 broches, 150 °C',1202,'No',NULL,'img/J111.jpg','2026-10-19 17:37:00',3,'4319690'),(1119,1,'BD681','STMicroelectronics','SOT-32',3,'No',1,5,0,0.78,'http://www.farnell.com/datasheets/2307370.pdf',NULL,'STMICROELECTRONICS - BD681 - Transistor Darlington, NPN, 100 V, 40 W, 4 A, SOT-32, 3 Broche(s)',1204,'No',NULL,'img/SOT-32.jpg','2026-10-20 16:10:20',3,'1574365'),(1125,1,'BTN06G103J3HH1T0A','Bourns','0603',2,'No',4,6,0,0.05,'https://www.mouser.com/catalog/specsheets/Bourns_08-20-2026_BTN06G.pdf',NULL,'Thermistances CTN NTC SMD 0603 10kO 5% B25/50 3380K',903,'No',NULL,'img/0603.png','2026-10-20 21:06:24',1,'652-BTN06103J3HH1T0A'),(1126,1,'MOV-20D152KKC','Bourns','VARISTANCE',2,'No',3,7,0,0.96,'https://www.mouser.com/catalog/specsheets/Bourns_08-19-2026_MOV-20DxxxKC.pdf',NULL,'Varistances 20mm Radial Disc-Kinked Leads,Bulk',902,'No',NULL,'img/VARISTANCE.jpg','2026-10-20 21:13:23',1,'652-MOV-20D152KKC'),(1127,1,'MOV-20D201KCTR','Bourns','DIP-16',NULL,'No',1,NULL,0,0.22,'https://www.mouser.com/catalog/specsheets/Bourns_08-19-2026_MOV-20DxxxKC.pdf',NULL,'Varistances 20mm Radial Disc-Straight Leads,T&R',NULL,'No',NULL,'img/DIP-40.jpg','2026-10-20 21:18:22',1,'652-MOV-20D201KCTR'),(1131,1,'PST065-201M1-N104','Bourns','POTENTIOMETRE 6MM',3,'No',5,8,0,0.51,'https://www.mouser.com/catalog/specsheets/Bourns_02-09-2026_PST065_Datasheet.pdf',NULL,'Potentiomètres 6 mm Incremental Potentiometer',1312,'No',NULL,'img/POTENTIOMETRE_6MM_HORIZONTAL.png','2026-10-21 07:29:22',1,'652-PST065-201M1-104'),(1133,1,'1SS123','NEC Electronics','SOT-23',3,'Yes',1,NULL,0,NULL,'https://www.alldatasheet.com/view.jsp?Searchword=1SS123',NULL,NULL,499,'No',NULL,'img/SOT-23.jpg','2026-10-21 14:27:36',NULL,NULL);
/*!40000 ALTER TABLE `data` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `location`
--

DROP TABLE IF EXISTS `location`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner` int(11) NOT NULL,
  `casier` varchar(255) DEFAULT NULL,
  `tiroir` varchar(255) DEFAULT NULL,
  `compartiment` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `storage_type` varchar(30) NOT NULL DEFAULT 'casier',
  `logo_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_location` (`owner`,`casier`,`tiroir`,`compartiment`),
  CONSTRAINT `location_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=702 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `location`
--

LOCK TABLES `location` WRITE;
/*!40000 ALTER TABLE `location` DISABLE KEYS */;
INSERT INTO `location` VALUES (1,1,'A','1','1','','casier',NULL,'2025-07-05 22:05:48'),(2,1,'A','1','2',NULL,'casier',NULL,'2025-07-05 22:05:48'),(3,1,'A','1','3','','casier',NULL,'2025-07-05 22:05:48'),(4,1,'A','1','4','','casier',NULL,'2025-07-05 22:05:48'),(5,1,'A','2','1','','casier',NULL,'2025-07-05 22:05:48'),(6,1,'A','2','2','','casier',NULL,'2025-07-05 22:05:48'),(7,1,'A','2','3','','casier',NULL,'2025-07-05 22:05:48'),(8,1,'A','2','4','','casier',NULL,'2025-07-05 22:05:48'),(9,1,'A','3','1','','casier',NULL,'2025-07-05 22:05:48'),(10,1,'A','3','2','','casier',NULL,'2025-07-05 22:05:48'),(11,1,'A','3','3','','casier',NULL,'2025-07-05 22:05:48'),(12,1,'A','3','4','','casier',NULL,'2025-07-05 22:05:48'),(13,1,'A','4','1','','casier',NULL,'2025-07-05 22:05:48'),(14,1,'A','4','2','','casier',NULL,'2025-07-05 22:05:48'),(15,1,'A','4','3','','casier',NULL,'2025-07-05 22:05:48'),(16,1,'A','4','4','','casier',NULL,'2025-07-05 22:05:48'),(17,1,'A','5','1','','casier',NULL,'2025-07-05 22:05:48'),(18,1,'A','5','2','','casier',NULL,'2025-07-05 22:05:48'),(19,1,'A','5','3','','casier',NULL,'2025-07-05 22:05:48'),(20,1,'A','5','4','','casier',NULL,'2025-07-05 22:05:48'),(21,1,'A','11','1','','casier',NULL,'2025-07-05 22:05:48'),(22,1,'A','11','2','','casier',NULL,'2025-07-05 22:05:48'),(23,1,'A','11','3','','casier',NULL,'2025-07-05 22:05:48'),(24,1,'A','11','4','','casier',NULL,'2025-07-05 22:05:48'),(25,1,'A','12','1','','casier',NULL,'2025-07-05 22:05:48'),(26,1,'A','12','2','','casier',NULL,'2025-07-05 22:05:48'),(27,1,'A','12','3','','casier',NULL,'2025-07-05 22:05:48'),(28,1,'A','12','4','','casier',NULL,'2025-07-05 22:05:48'),(29,1,'A','13','1','','casier',NULL,'2025-07-05 22:05:48'),(30,1,'A','13','2','','casier',NULL,'2025-07-05 22:05:48'),(31,1,'A','13','3','','casier',NULL,'2025-07-05 22:05:48'),(32,1,'A','13','4','','casier',NULL,'2025-07-05 22:05:48'),(33,1,'A','14','1','','casier',NULL,'2025-07-05 22:05:48'),(34,1,'A','14','2','','casier',NULL,'2025-07-05 22:05:48'),(35,1,'A','14','3','','casier',NULL,'2025-07-05 22:05:48'),(36,1,'A','14','4','','casier',NULL,'2025-07-05 22:05:48'),(37,1,'A','15','1','','casier',NULL,'2025-07-05 22:05:48'),(38,1,'A','15','2','','casier',NULL,'2025-07-05 22:05:48'),(39,1,'A','15','3','','casier',NULL,'2025-07-05 22:05:48'),(40,1,'A','15','4','','casier',NULL,'2025-07-05 22:05:48'),(41,1,'A','21','1','','casier',NULL,'2025-07-05 22:05:48'),(42,1,'A','21','2','','casier',NULL,'2025-07-05 22:05:48'),(43,1,'A','21','3','','casier',NULL,'2025-07-05 22:05:48'),(44,1,'A','21','4','','casier',NULL,'2025-07-05 22:05:48'),(45,1,'A','22','1','','casier',NULL,'2025-07-05 22:05:48'),(46,1,'A','22','2','','casier',NULL,'2025-07-05 22:05:48'),(47,1,'A','22','3','','casier',NULL,'2025-07-05 22:05:48'),(48,1,'A','22','4','','casier',NULL,'2025-07-05 22:05:48'),(49,1,'A','23','1','','casier',NULL,'2025-07-05 22:05:48'),(50,1,'A','23','2','','casier',NULL,'2025-07-05 22:05:48'),(51,1,'A','23','3','','casier',NULL,'2025-07-05 22:05:48'),(52,1,'A','23','4','','casier',NULL,'2025-07-05 22:05:48'),(53,1,'A','24','1','','casier',NULL,'2025-07-05 22:05:48'),(54,1,'A','24','2','','casier',NULL,'2025-07-05 22:05:48'),(55,1,'A','24','3','','casier',NULL,'2025-07-05 22:05:48'),(56,1,'A','24','4','','casier',NULL,'2025-07-05 22:05:48'),(57,1,'A','25','1','','casier',NULL,'2025-07-05 22:05:48'),(58,1,'A','25','2','','casier',NULL,'2025-07-05 22:05:48'),(59,1,'A','25','3','','casier',NULL,'2025-07-05 22:05:48'),(60,1,'A','25','4','','casier',NULL,'2025-07-05 22:05:48'),(61,1,'A','31','1','','casier',NULL,'2025-07-05 22:05:48'),(62,1,'A','31','2','','casier',NULL,'2025-07-05 22:05:48'),(63,1,'A','31','3','','casier',NULL,'2025-07-05 22:05:48'),(64,1,'A','31','4','','casier',NULL,'2025-07-05 22:05:48'),(65,1,'A','32','1','','casier',NULL,'2025-07-05 22:05:48'),(66,1,'A','32','2','','casier',NULL,'2025-07-05 22:05:48'),(67,1,'A','32','3','','casier',NULL,'2025-07-05 22:05:48'),(68,1,'A','32','4','','casier',NULL,'2025-07-05 22:05:48'),(69,1,'A','33','1','','casier',NULL,'2025-07-05 22:05:48'),(70,1,'A','33','2','','casier',NULL,'2025-07-05 22:05:48'),(71,1,'A','33','3','','casier',NULL,'2025-07-05 22:05:48'),(72,1,'A','33','4','','casier',NULL,'2025-07-05 22:05:48'),(73,1,'A','34','1','','casier',NULL,'2025-07-05 22:05:48'),(74,1,'A','34','2','','casier',NULL,'2025-07-05 22:05:48'),(75,1,'A','34','3','','casier',NULL,'2025-07-05 22:05:48'),(76,1,'A','34','4','','casier',NULL,'2025-07-05 22:05:48'),(77,1,'A','35','1','','casier',NULL,'2025-07-05 22:05:48'),(78,1,'A','35','2','','casier',NULL,'2025-07-05 22:05:48'),(79,1,'A','35','3','','casier',NULL,'2025-07-05 22:05:48'),(80,1,'A','35','4','','casier',NULL,'2025-07-05 22:05:48'),(81,1,'A','41','1','','casier',NULL,'2025-07-05 22:05:48'),(82,1,'A','41','2','','casier',NULL,'2025-07-05 22:05:48'),(83,1,'A','41','3','','casier',NULL,'2025-07-05 22:05:48'),(84,1,'A','41','4','','casier',NULL,'2025-07-05 22:05:48'),(85,1,'A','42','1','','casier',NULL,'2025-07-05 22:05:48'),(86,1,'A','42','2','','casier',NULL,'2025-07-05 22:05:48'),(87,1,'A','42','3','','casier',NULL,'2025-07-05 22:05:48'),(88,1,'A','42','4','','casier',NULL,'2025-07-05 22:05:48'),(89,1,'A','43','1','','casier',NULL,'2025-07-05 22:05:48'),(90,1,'A','43','2','','casier',NULL,'2025-07-05 22:05:48'),(91,1,'A','43','3','','casier',NULL,'2025-07-05 22:05:48'),(92,1,'A','43','4','','casier',NULL,'2025-07-05 22:05:48'),(93,1,'A','44','1','','casier',NULL,'2025-07-05 22:05:48'),(94,1,'A','44','2','','casier',NULL,'2025-07-05 22:05:48'),(95,1,'A','44','3','','casier',NULL,'2025-07-05 22:05:48'),(96,1,'A','44','4','','casier',NULL,'2025-07-05 22:05:48'),(97,1,'A','45','1','','casier',NULL,'2025-07-05 22:05:48'),(98,1,'A','45','2','','casier',NULL,'2025-07-05 22:05:48'),(99,1,'A','45','3','','casier',NULL,'2025-07-05 22:05:48'),(100,1,'A','45','4','','casier',NULL,'2025-07-05 22:05:48'),(101,1,'B','10','1','','casier',NULL,'2025-07-14 10:33:19'),(102,1,'B','10','2','','casier',NULL,'2025-07-14 10:33:19'),(103,1,'B','10','3','','casier',NULL,'2025-07-14 10:33:19'),(104,1,'B','10','4','','casier',NULL,'2025-07-14 10:33:19'),(105,1,'B','11','1','','casier',NULL,'2025-07-14 10:33:19'),(106,1,'B','11','2','','casier',NULL,'2025-07-14 10:33:19'),(107,1,'B','11','3','','casier',NULL,'2025-07-14 10:33:19'),(108,1,'B','11','4','','casier',NULL,'2025-07-14 10:33:19'),(109,1,'B','12','1','','casier',NULL,'2025-07-14 10:33:19'),(110,1,'B','12','2','','casier',NULL,'2025-07-14 10:33:19'),(111,1,'B','12','3','','casier',NULL,'2025-07-14 10:33:19'),(112,1,'B','12','4','','casier',NULL,'2025-07-14 10:33:19'),(113,1,'B','13','1','','casier',NULL,'2025-07-14 10:33:19'),(114,1,'B','13','2','','casier',NULL,'2025-07-14 10:33:19'),(115,1,'B','13','3','','casier',NULL,'2025-07-14 10:33:19'),(116,1,'B','13','4','','casier',NULL,'2025-07-14 10:33:19'),(117,1,'B','14','1','','casier',NULL,'2025-07-14 10:33:19'),(118,1,'B','14','2','','casier',NULL,'2025-07-14 10:33:19'),(119,1,'B','14','3','','casier',NULL,'2025-07-14 10:33:19'),(120,1,'B','14','4','','casier',NULL,'2025-07-14 10:33:19'),(121,1,'B','20','1','','casier',NULL,'2025-07-14 10:33:19'),(122,1,'B','20','2','','casier',NULL,'2025-07-14 10:33:19'),(123,1,'B','20','3','','casier',NULL,'2025-07-14 10:33:19'),(124,1,'B','20','4','','casier',NULL,'2025-07-14 10:33:19'),(125,1,'B','21','1','','casier',NULL,'2025-07-14 10:33:19'),(126,1,'B','21','2','','casier',NULL,'2025-07-14 10:33:19'),(127,1,'B','21','3','','casier',NULL,'2025-07-14 10:33:19'),(128,1,'B','21','4','','casier',NULL,'2025-07-14 10:33:19'),(129,1,'B','22','1','','casier',NULL,'2025-07-14 10:33:19'),(130,1,'B','22','2','','casier',NULL,'2025-07-14 10:33:19'),(131,1,'B','22','3','','casier',NULL,'2025-07-14 10:33:19'),(132,1,'B','22','4','','casier',NULL,'2025-07-14 10:33:19'),(133,1,'B','23','1','','casier',NULL,'2025-07-14 10:33:19'),(134,1,'B','23','2','','casier',NULL,'2025-07-14 10:33:19'),(135,1,'B','23','3','','casier',NULL,'2025-07-14 10:33:19'),(136,1,'B','23','4','','casier',NULL,'2025-07-14 10:33:19'),(137,1,'B','24','1','','casier',NULL,'2025-07-14 10:33:19'),(138,1,'B','24','2','','casier',NULL,'2025-07-14 10:33:19'),(139,1,'B','24','3','','casier',NULL,'2025-07-14 10:33:19'),(140,1,'B','24','4','','casier',NULL,'2025-07-14 10:33:19'),(141,1,'B','30','1','','casier',NULL,'2025-07-14 10:33:19'),(142,1,'B','30','2','','casier',NULL,'2025-07-14 10:33:19'),(143,1,'B','30','3','','casier',NULL,'2025-07-14 10:33:19'),(144,1,'B','30','4','','casier',NULL,'2025-07-14 10:33:19'),(145,1,'B','31','1','','casier',NULL,'2025-07-14 10:33:19'),(146,1,'B','31','2','','casier',NULL,'2025-07-14 10:33:19'),(147,1,'B','31','3','','casier',NULL,'2025-07-14 10:33:19'),(148,1,'B','31','4','','casier',NULL,'2025-07-14 10:33:19'),(149,1,'B','32','1','','casier',NULL,'2025-07-14 10:33:19'),(150,1,'B','32','2','','casier',NULL,'2025-07-14 10:33:19'),(151,1,'B','32','3','','casier',NULL,'2025-07-14 10:33:19'),(152,1,'B','32','4','','casier',NULL,'2025-07-14 10:33:19'),(153,1,'B','33','1','','casier',NULL,'2025-07-14 10:33:19'),(154,1,'B','33','2','','casier',NULL,'2025-07-14 10:33:19'),(155,1,'B','33','3','','casier',NULL,'2025-07-14 10:33:19'),(156,1,'B','33','4','','casier',NULL,'2025-07-14 10:33:19'),(157,1,'B','34','1','','casier',NULL,'2025-07-14 10:33:19'),(158,1,'B','34','2','','casier',NULL,'2025-07-14 10:33:19'),(159,1,'B','34','3','','casier',NULL,'2025-07-14 10:33:19'),(160,1,'B','34','4','','casier',NULL,'2025-07-14 10:33:19'),(161,1,'B','40','1','','casier',NULL,'2025-07-14 10:33:19'),(162,1,'B','40','2','','casier',NULL,'2025-07-14 10:33:19'),(163,1,'B','40','3','','casier',NULL,'2025-07-14 10:33:19'),(164,1,'B','40','4','','casier',NULL,'2025-07-14 10:33:19'),(165,1,'B','41','1','','casier',NULL,'2025-07-14 10:33:19'),(166,1,'B','41','2','','casier',NULL,'2025-07-14 10:33:19'),(167,1,'B','41','3','','casier',NULL,'2025-07-14 10:33:19'),(168,1,'B','41','4','','casier',NULL,'2025-07-14 10:33:19'),(169,1,'B','42','1','','casier',NULL,'2025-07-14 10:33:19'),(170,1,'B','42','2','','casier',NULL,'2025-07-14 10:33:19'),(171,1,'B','42','3','','casier',NULL,'2025-07-14 10:33:19'),(172,1,'B','42','4','','casier',NULL,'2025-07-14 10:33:19'),(173,1,'B','43','1','','casier',NULL,'2025-07-14 10:33:19'),(174,1,'B','43','2','','casier',NULL,'2025-07-14 10:33:19'),(175,1,'B','43','3','','casier',NULL,'2025-07-14 10:33:19'),(176,1,'B','43','4','','casier',NULL,'2025-07-14 10:33:19'),(177,1,'B','44','1','','casier',NULL,'2025-07-14 10:33:19'),(178,1,'B','44','2','','casier',NULL,'2025-07-14 10:33:19'),(179,1,'B','44','3','','casier',NULL,'2025-07-14 10:33:19'),(180,1,'B','44','4','','casier',NULL,'2025-07-14 10:33:19'),(181,1,'B','50','1','','casier',NULL,'2025-07-14 10:33:19'),(182,1,'B','50','2','','casier',NULL,'2025-07-14 10:33:19'),(183,1,'B','50','3','','casier',NULL,'2025-07-14 10:33:19'),(184,1,'B','50','4','','casier',NULL,'2025-07-14 10:33:19'),(185,1,'B','51','1','','casier',NULL,'2025-07-14 10:33:19'),(186,1,'B','51','2','','casier',NULL,'2025-07-14 10:33:19'),(187,1,'B','51','3','','casier',NULL,'2025-07-14 10:33:19'),(188,1,'B','51','4','','casier',NULL,'2025-07-14 10:33:19'),(189,1,'B','52','1','','casier',NULL,'2025-07-14 10:33:19'),(190,1,'B','52','2','','casier',NULL,'2025-07-14 10:33:19'),(191,1,'B','52','3','','casier',NULL,'2025-07-14 10:33:19'),(192,1,'B','52','4','','casier',NULL,'2025-07-14 10:33:19'),(193,1,'B','53','1','','casier',NULL,'2025-07-14 10:33:19'),(194,1,'B','53','2','','casier',NULL,'2025-07-14 10:33:19'),(195,1,'B','53','3','','casier',NULL,'2025-07-14 10:33:19'),(196,1,'B','53','4','','casier',NULL,'2025-07-14 10:33:19'),(197,1,'B','54','1','','casier',NULL,'2025-07-14 10:33:19'),(198,1,'B','54','2','','casier',NULL,'2025-07-14 10:33:19'),(199,1,'B','54','3','','casier',NULL,'2025-07-14 10:33:19'),(200,1,'B','54','4','','casier',NULL,'2025-07-14 10:33:19'),(201,1,'B','60','1','','casier',NULL,'2025-07-14 10:33:19'),(202,1,'B','60','2','','casier',NULL,'2025-07-14 10:33:19'),(203,1,'B','60','3','','casier',NULL,'2025-07-14 10:33:19'),(204,1,'B','60','4','','casier',NULL,'2025-07-14 10:33:19'),(205,1,'B','61','1','','casier',NULL,'2025-07-14 10:33:19'),(206,1,'B','61','2','','casier',NULL,'2025-07-14 10:33:19'),(207,1,'B','61','3','','casier',NULL,'2025-07-14 10:33:19'),(208,1,'B','61','4','','casier',NULL,'2025-07-14 10:33:19'),(209,1,'B','62','1','','casier',NULL,'2025-07-14 10:33:19'),(210,1,'B','62','2','','casier',NULL,'2025-07-14 10:33:19'),(211,1,'B','62','3','','casier',NULL,'2025-07-14 10:33:19'),(212,1,'B','62','4','','casier',NULL,'2025-07-14 10:33:19'),(213,1,'B','63','1','','casier',NULL,'2025-07-14 10:33:19'),(214,1,'B','63','2','','casier',NULL,'2025-07-14 10:33:19'),(215,1,'B','63','3','','casier',NULL,'2025-07-14 10:33:19'),(216,1,'B','63','4','','casier',NULL,'2025-07-14 10:33:19'),(217,1,'B','64','1','','casier',NULL,'2025-07-14 10:33:19'),(218,1,'B','64','2','','casier',NULL,'2025-07-14 10:33:19'),(219,1,'B','64','3','','casier',NULL,'2025-07-14 10:33:19'),(220,1,'B','64','4','','casier',NULL,'2025-07-14 10:33:19'),(221,1,'B','70','1','','casier',NULL,'2025-07-14 10:33:19'),(222,1,'B','70','2','','casier',NULL,'2025-07-14 10:33:19'),(223,1,'B','70','3','','casier',NULL,'2025-07-14 10:33:19'),(224,1,'B','70','4','','casier',NULL,'2025-07-14 10:33:19'),(225,1,'B','71','1','','casier',NULL,'2025-07-14 10:33:19'),(226,1,'B','71','2','','casier',NULL,'2025-07-14 10:33:19'),(227,1,'B','71','3','','casier',NULL,'2025-07-14 10:33:19'),(228,1,'B','71','4','','casier',NULL,'2025-07-14 10:33:19'),(229,1,'B','72','1','','casier',NULL,'2025-07-14 10:33:19'),(230,1,'B','72','2','','casier',NULL,'2025-07-14 10:33:19'),(231,1,'B','72','3','','casier',NULL,'2025-07-14 10:33:19'),(232,1,'B','72','4','','casier',NULL,'2025-07-14 10:33:19'),(233,1,'B','73','1','','casier',NULL,'2025-07-14 10:33:19'),(234,1,'B','73','2','','casier',NULL,'2025-07-14 10:33:19'),(235,1,'B','73','3','','casier',NULL,'2025-07-14 10:33:19'),(236,1,'B','73','4','','casier',NULL,'2025-07-14 10:33:19'),(237,1,'B','74','1','','casier',NULL,'2025-07-14 10:33:19'),(238,1,'B','74','2','','casier',NULL,'2025-07-14 10:33:19'),(239,1,'B','74','3','','casier',NULL,'2025-07-14 10:33:19'),(240,1,'B','74','4','','casier',NULL,'2025-07-14 10:33:19'),(241,1,'B','80','1','','casier',NULL,'2025-07-14 10:33:19'),(242,1,'B','80','2','','casier',NULL,'2025-07-14 10:33:19'),(243,1,'B','80','3','','casier',NULL,'2025-07-14 10:33:19'),(244,1,'B','80','4','','casier',NULL,'2025-07-14 10:33:19'),(245,1,'B','81','1','','casier',NULL,'2025-07-14 10:33:19'),(246,1,'B','81','2','','casier',NULL,'2025-07-14 10:33:19'),(247,1,'B','81','3','','casier',NULL,'2025-07-14 10:33:19'),(248,1,'B','81','4','','casier',NULL,'2025-07-14 10:33:19'),(249,1,'B','82','1','','casier',NULL,'2025-07-14 10:33:19'),(250,1,'B','82','2','','casier',NULL,'2025-07-14 10:33:19'),(251,1,'B','82','3','','casier',NULL,'2025-07-14 10:33:19'),(252,1,'B','82','4','','casier',NULL,'2025-07-14 10:33:19'),(253,1,'B','83','1','','casier',NULL,'2025-07-14 10:33:19'),(254,1,'B','83','2','','casier',NULL,'2025-07-14 10:33:19'),(255,1,'B','83','3','','casier',NULL,'2025-07-14 10:33:19'),(256,1,'B','83','4','','casier',NULL,'2025-07-14 10:33:19'),(257,1,'B','84','1','','casier',NULL,'2025-07-14 10:33:19'),(258,1,'B','84','2','','casier',NULL,'2025-07-14 10:33:19'),(259,1,'B','84','3','','casier',NULL,'2025-07-14 10:33:19'),(260,1,'B','84','4','','casier',NULL,'2025-07-14 10:33:19'),(261,1,'B','90','1','','casier',NULL,'2025-07-14 10:33:19'),(262,1,'B','90','2','','casier',NULL,'2025-07-14 10:33:19'),(263,1,'B','90','3','','casier',NULL,'2025-07-14 10:33:19'),(264,1,'B','90','4','','casier',NULL,'2025-07-14 10:33:19'),(265,1,'B','91','1','','casier',NULL,'2025-07-14 10:33:19'),(266,1,'B','91','2','','casier',NULL,'2025-07-14 10:33:19'),(267,1,'B','91','3','','casier',NULL,'2025-07-14 10:33:19'),(268,1,'B','91','4','','casier',NULL,'2025-07-14 10:33:19'),(269,1,'B','92','1','','casier',NULL,'2025-07-14 10:33:19'),(270,1,'B','92','2','','casier',NULL,'2025-07-14 10:33:19'),(271,1,'B','92','3','','casier',NULL,'2025-07-14 10:33:19'),(272,1,'B','92','4','','casier',NULL,'2025-07-14 10:33:19'),(273,1,'B','93','1','','casier',NULL,'2025-07-14 10:33:19'),(274,1,'B','93','2','','casier',NULL,'2025-07-14 10:33:19'),(275,1,'B','93','3','','casier',NULL,'2025-07-14 10:33:19'),(276,1,'B','93','4','','casier',NULL,'2025-07-14 10:33:19'),(277,1,'B','94','1','','casier',NULL,'2025-07-14 10:33:19'),(278,1,'B','94','2','','casier',NULL,'2025-07-14 10:33:19'),(279,1,'B','94','3','','casier',NULL,'2025-07-14 10:33:19'),(280,1,'B','94','4','','casier',NULL,'2025-07-14 10:33:19'),(281,1,'B','100','1','','casier',NULL,'2025-07-14 10:33:19'),(282,1,'B','100','2','','casier',NULL,'2025-07-14 10:33:19'),(283,1,'B','100','3','','casier',NULL,'2025-07-14 10:33:19'),(284,1,'B','100','4','','casier',NULL,'2025-07-14 10:33:19'),(285,1,'B','101','1','','casier',NULL,'2025-07-14 10:33:19'),(286,1,'B','101','2','','casier',NULL,'2025-07-14 10:33:19'),(287,1,'B','101','3','','casier',NULL,'2025-07-14 10:33:19'),(288,1,'B','101','4','','casier',NULL,'2025-07-14 10:33:19'),(289,1,'B','102','1','','casier',NULL,'2025-07-14 10:33:19'),(290,1,'B','102','2','','casier',NULL,'2025-07-14 10:33:19'),(291,1,'B','102','3','','casier',NULL,'2025-07-14 10:33:19'),(292,1,'B','102','4','','casier',NULL,'2025-07-14 10:33:19'),(293,1,'B','103','1','','casier',NULL,'2025-07-14 10:33:19'),(294,1,'B','103','2','','casier',NULL,'2025-07-14 10:33:19'),(295,1,'B','103','3','','casier',NULL,'2025-07-14 10:33:19'),(296,1,'B','103','4','','casier',NULL,'2025-07-14 10:33:19'),(297,1,'B','104','1','','casier',NULL,'2025-07-14 10:33:19'),(298,1,'B','104','2','','casier',NULL,'2025-07-14 10:33:19'),(299,1,'B','104','3','','casier',NULL,'2025-07-14 10:33:19'),(300,1,'B','104','4','','casier',NULL,'2025-07-14 10:33:19'),(503,1,'A','46','1',NULL,'casier',NULL,'2025-07-26 17:50:35'),(504,1,'A','46','2',NULL,'casier',NULL,'2025-07-26 17:50:35'),(505,1,'A','46','3',NULL,'casier',NULL,'2025-07-26 17:50:35'),(506,1,'A','46','4',NULL,'casier',NULL,'2025-07-26 17:50:35'),(507,1,'A','47','1',NULL,'casier',NULL,'2025-07-26 17:50:53'),(608,1,'A','10','1','','casier',NULL,'2026-08-25 09:33:48'),(609,1,'A','10','2','','casier',NULL,'2026-08-25 09:33:48'),(610,1,'A','10','3','','casier',NULL,'2026-08-25 09:33:48'),(612,1,'A','20','1','','casier',NULL,'2026-08-25 09:33:48'),(613,1,'A','20','2','','casier',NULL,'2026-08-25 09:33:48'),(614,1,'A','20','3','','casier',NULL,'2026-08-25 09:33:48'),(615,1,'A','20','4','','casier',NULL,'2026-08-25 09:33:48'),(616,1,'A','30','1','','casier',NULL,'2026-08-25 09:33:48'),(617,1,'A','30','2','','casier',NULL,'2026-08-25 09:33:48'),(618,1,'A','30','3','','casier',NULL,'2026-08-25 09:33:48'),(619,1,'A','30','4','','casier',NULL,'2026-08-25 09:33:48'),(620,1,'A','40','1','','casier',NULL,'2026-08-25 09:33:48'),(621,1,'A','40','2','','casier',NULL,'2026-08-25 09:33:48'),(622,1,'A','40','3','','casier',NULL,'2026-08-25 09:33:48'),(623,1,'A','40','4','','casier',NULL,'2026-08-25 09:33:48'),(624,1,'CL','','32',NULL,'classeur',NULL,'2026-08-25 09:42:16'),(625,1,'CL','','70',NULL,'classeur',NULL,'2026-08-25 09:42:16'),(626,1,'CL','','56',NULL,'classeur',NULL,'2026-08-25 09:42:16'),(627,1,'CL','','18',NULL,'classeur',NULL,'2026-08-25 09:42:16'),(628,1,'H','10','1','','casier',NULL,'2026-08-26 12:44:04'),(629,1,'H','10','2','','casier',NULL,'2026-08-26 12:44:04'),(630,1,'H','10','3','','casier',NULL,'2026-08-26 12:44:04'),(631,1,'H','10','4','','casier',NULL,'2026-08-26 12:44:04'),(632,1,'H','11','1','','casier',NULL,'2026-08-26 12:44:04'),(633,1,'H','11','2','','casier',NULL,'2026-08-26 12:44:04'),(634,1,'H','11','3','','casier',NULL,'2026-08-26 12:44:04'),(635,1,'H','11','4','','casier',NULL,'2026-08-26 12:44:04'),(636,1,'H','12','1','','casier',NULL,'2026-08-26 12:44:04'),(637,1,'H','12','2','','casier',NULL,'2026-08-26 12:44:04'),(638,1,'H','12','3','','casier',NULL,'2026-08-26 12:44:04'),(639,1,'H','12','4','','casier',NULL,'2026-08-26 12:44:04'),(640,1,'H','13','1','','casier',NULL,'2026-08-26 12:44:04'),(641,1,'H','13','2','','casier',NULL,'2026-08-26 12:44:04'),(642,1,'H','13','3','','casier',NULL,'2026-08-26 12:44:04'),(643,1,'H','13','4','','casier',NULL,'2026-08-26 12:44:04'),(644,1,'H','14','1','','casier',NULL,'2026-08-26 12:44:04'),(645,1,'H','14','2','','casier',NULL,'2026-08-26 12:44:04'),(646,1,'H','14','3','','casier',NULL,'2026-08-26 12:44:04'),(647,1,'H','14','4','','casier',NULL,'2026-08-26 12:44:04'),(648,1,'H','15','1','','casier',NULL,'2026-08-26 12:44:04'),(649,1,'H','15','2','','casier',NULL,'2026-08-26 12:44:04'),(650,1,'H','15','3','','casier',NULL,'2026-08-26 12:44:04'),(651,1,'H','15','4','','casier',NULL,'2026-08-26 12:44:04'),(652,1,'H','20','1','','casier',NULL,'2026-08-26 12:44:04'),(653,1,'H','20','2','','casier',NULL,'2026-08-26 12:44:04'),(654,1,'H','20','3','','casier',NULL,'2026-08-26 12:44:04'),(655,1,'H','20','4','','casier',NULL,'2026-08-26 12:44:04'),(656,1,'H','21','1','','casier',NULL,'2026-08-26 12:44:04'),(657,1,'H','21','2','','casier',NULL,'2026-08-26 12:44:04'),(658,1,'H','21','3','','casier',NULL,'2026-08-26 12:44:04'),(659,1,'H','21','4','','casier',NULL,'2026-08-26 12:44:04'),(660,1,'H','22','1','','casier',NULL,'2026-08-26 12:44:04'),(661,1,'H','22','2','','casier',NULL,'2026-08-26 12:44:04'),(662,1,'H','22','3','','casier',NULL,'2026-08-26 12:44:04'),(663,1,'H','22','4','','casier',NULL,'2026-08-26 12:44:04'),(664,1,'H','23','1','','casier',NULL,'2026-08-26 12:44:04'),(665,1,'H','23','2','','casier',NULL,'2026-08-26 12:44:04'),(666,1,'H','23','3','','casier',NULL,'2026-08-26 12:44:04'),(667,1,'H','23','4','','casier',NULL,'2026-08-26 12:44:04'),(668,1,'H','24','1','','casier',NULL,'2026-08-26 12:44:04'),(669,1,'H','24','2','','casier',NULL,'2026-08-26 12:44:04'),(670,1,'H','24','3','','casier',NULL,'2026-08-26 12:44:04'),(671,1,'H','24','4','','casier',NULL,'2026-08-26 12:44:04'),(672,1,'H','25','1','','casier',NULL,'2026-08-26 12:44:04'),(673,1,'H','25','2','','casier',NULL,'2026-08-26 12:44:04'),(674,1,'H','25','3','','casier',NULL,'2026-08-26 12:44:04'),(675,1,'H','25','4','','casier',NULL,'2026-08-26 12:44:04'),(676,1,'H','30','1','','casier',NULL,'2026-08-26 12:44:04'),(677,1,'H','30','2','','casier',NULL,'2026-08-26 12:44:04'),(678,1,'H','30','3','','casier',NULL,'2026-08-26 12:44:04'),(679,1,'H','30','4','','casier',NULL,'2026-08-26 12:44:04'),(680,1,'H','31','1','','casier',NULL,'2026-08-26 12:44:04'),(681,1,'H','31','2','','casier',NULL,'2026-08-26 12:44:04'),(682,1,'H','31','3','','casier',NULL,'2026-08-26 12:44:04'),(683,1,'H','31','4','','casier',NULL,'2026-08-26 12:44:04'),(684,1,'H','32','1','','casier',NULL,'2026-08-26 12:44:04'),(685,1,'H','32','2','','casier',NULL,'2026-08-26 12:44:04'),(686,1,'H','32','3','','casier',NULL,'2026-08-26 12:44:04'),(687,1,'H','32','4','','casier',NULL,'2026-08-26 12:44:04'),(688,1,'H','33','1','','casier',NULL,'2026-08-26 12:44:04'),(689,1,'H','33','2','','casier',NULL,'2026-08-26 12:44:04'),(690,1,'H','33','3','','casier',NULL,'2026-08-26 12:44:04'),(691,1,'H','33','4','','casier',NULL,'2026-08-26 12:44:04'),(692,1,'H','34','1','','casier',NULL,'2026-08-26 12:44:04'),(693,1,'H','34','2','','casier',NULL,'2026-08-26 12:44:04'),(694,1,'H','34','3','','casier',NULL,'2026-08-26 12:44:04'),(695,1,'H','34','4','','casier',NULL,'2026-08-26 12:44:04'),(696,1,'H','35','1','','casier',NULL,'2026-08-26 12:44:04'),(697,1,'H','35','2','','casier',NULL,'2026-08-26 12:44:04'),(698,1,'H','35','3','','casier',NULL,'2026-08-26 12:44:04'),(699,1,'H','35','4','','casier',NULL,'2026-08-26 12:44:04');
/*!40000 ALTER TABLE `location` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manufacturers`
--

DROP TABLE IF EXISTS `manufacturers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manufacturers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `owner` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_manufacturer_owner` (`name`,`owner`),
  KEY `owner` (`owner`),
  CONSTRAINT `manufacturers_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manufacturers`
--

LOCK TABLES `manufacturers` WRITE;
/*!40000 ALTER TABLE `manufacturers` DISABLE KEYS */;
INSERT INTO `manufacturers` VALUES (1,'Nichicon',1,'2025-07-05 23:14:24'),(2,'Murata',1,'2025-07-05 23:14:24'),(4,'STMicroelectronics',1,'2025-07-05 23:14:24'),(5,'Infineon',1,'2025-07-06 08:04:47'),(6,'POWER INTEGRATIONS',1,'2025-07-07 08:57:36'),(7,'Texas Instruments',1,'2025-07-22 08:54:27'),(8,'Microchip',1,'2025-07-22 08:54:27'),(9,'NXP',1,'2025-07-22 08:54:27'),(10,'Analog Devices',1,'2025-07-22 08:54:27'),(11,'Maxim Integrated',1,'2025-07-22 08:54:27'),(12,'Vishay',1,'2025-07-22 08:54:27'),(13,'Samsung',1,'2025-07-22 08:54:27'),(21,'NEXPERIA',1,'2026-10-16 21:46:54'),(22,'ST(Semtech)',1,'2026-10-16 22:19:08'),(23,'SEMIKRON DANFOSS',1,'2026-10-17 09:44:18'),(24,'ONSEMI',1,'2026-10-19 17:37:00'),(27,'Bourns',1,'2026-10-20 21:04:12'),(28,'NEC Electronics',1,'2026-10-21 14:27:36');
/*!40000 ALTER TABLE `manufacturers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `packages`
--

DROP TABLE IF EXISTS `packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_owner` (`owner`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=146 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `packages`
--

LOCK TABLES `packages` WRITE;
/*!40000 ALTER TABLE `packages` DISABLE KEYS */;
INSERT INTO `packages` VALUES (3,'SOT-23','Small Outline Transistor 3 broches',3,'SOT',0.95,'2.9x1.3x1.1mm','Surface-mount','Package très populaire pour transistors et régulateurs','/img/SOT-23.jpg',1,'2025-07-06 22:26:43','2025-07-25 21:45:27'),(4,'TO-220','Transistor Outline package de puissance',3,'TO',2.54,'10.4x4.6x9.9mm','Through-hole','Package standard pour composants de puissance avec dissipateur','/img/TO-220.jpg',1,'2025-07-06 22:26:43','2025-07-25 21:45:27'),(6,'DIP-18','Package DIP 18 broches',18,'DIP',2.54,'22.9x6.4mm','Through-hole','Package pour microcontrôleurs moyens','/img/DIP-18.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(7,'DIP-20','Package DIP 20 broches',20,'DIP',2.54,'25.4x6.4mm','Through-hole','Package pour microcontrôleurs et circuits complexes','/img/DIP-20.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(8,'DIP-24','Package DIP 24 broches',24,'DIP',2.54,'30.5x15.2mm','Through-hole','Package large pour circuits complexes','/img/DIP-24.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(10,'DIP-40','Package DIP 40 broches',40,'DIP',2.54,'50.8x15.2mm','Through-hole','Package pour microprocesseurs et circuits très complexes','/img/DIP-40.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(11,'SOT-23-5','SOT-23 5 broches',5,'SOT',0.95,'2.9x1.6x1.1mm','Surface-mount','Version 5 broches du SOT-23','/img/SOT-23-5.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(12,'SOT-23-6','SOT-23 6 broches',6,'SOT',0.95,'2.9x1.6x1.1mm','Surface-mount','Version 6 broches du SOT-23','/img/SOT-23-6.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(13,'SOT-89','SOT-89 package de puissance',3,'SOT',1.50,'4.5x2.5x1.5mm','Surface-mount','Package de puissance moyenne','/img/SOT-89.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(14,'SOT-223','SOT-223 package de puissance',4,'SOT',2.30,'6.5x3.5x1.6mm','Surface-mount','Package de puissance avec dissipateur thermique','/img/SOT-223.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(15,'TO-92','Transistor Outline 92',3,'TO',2.54,'5.2mmx4.2mmx4.6mm','Through-hole','Package standard pour transistors de signal','/img/TO-92.jpg',1,'2025-07-06 22:43:58','2025-07-25 22:38:54'),(16,'TO-220AB','TO-220 avec isolation',3,'TO',2.54,'10.4x4.6x9.9mm','Through-hole','Version isolée du TO-220','/img/TO-220AB.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(17,'TO-247','TO-247 haute puissance',3,'TO',5.45,'20.8x6.6x15.9mm','Through-hole','Package haute puissance pour MOSFET et IGBT','/img/TO-247.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(19,'SOIC-8','Small Outline IC 8 broches',8,'SOIC',1.27,'4.9x3.9mm','Surface-mount','Package CMS standard','/img/SOIC-8.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(20,'SOIC-14','Small Outline IC 14 broches',14,'SOIC',1.27,'8.7x3.9mm','Surface-mount','Package CMS pour circuits logiques','/img/SOIC-14.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(21,'SOIC-16','Small Outline IC 16 broches',16,'SOIC',1.27,'9.9x3.9mm','Surface-mount','Package CMS populaire','/img/SOIC-16.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(22,'SOIC-20','Small Outline IC 20 broches',20,'SOIC',1.27,'12.8x7.5mm','Surface-mount','Package CMS large','/img/SOIC-20.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(23,'SOIC-28','Small Outline IC 28 broches',28,'SOIC',1.27,'17.9x7.5mm','Surface-mount','Package CMS pour circuits complexes','/img/SOIC-28.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(24,'TSSOP-8','Thin Shrink Small Outline Package 8 broches',8,'TSSOP',0.65,'3.0x3.0mm','Surface-mount','Package très compact','/img/TSSOP-8.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(25,'TSSOP-14','TSSOP 14 broches',14,'TSSOP',0.65,'5.0x4.4mm','Surface-mount','Package compact pour circuits intégrés','/img/TSSOP-14.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(26,'TSSOP-16','TSSOP 16 broches',16,'TSSOP',0.65,'5.0x4.4mm','Surface-mount','Package compact populaire','/img/TSSOP-16.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(27,'TSSOP-20','TSSOP 20 broches',20,'TSSOP',0.65,'6.5x4.4mm','Surface-mount','Package compact pour microcontrôleurs','/img/TSSOP-20.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(28,'TSSOP-28','TSSOP 28 broches',28,'TSSOP',0.65,'9.7x4.4mm','Surface-mount','Package compact pour circuits avancés','/img/TSSOP-28.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(33,'TQFP-44','Thin Quad Flat Package 44 broches',44,'QFP',0.80,'10x10mm','Surface-mount','Package fin pour microcontrôleurs','/img/TQFP-44.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(34,'QFN-16','Quad Flat No-leads 16 broches',16,'QFN',0.50,'3x3mm','Surface-mount','Package très compact sans pattes','/img/QFN-16.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(35,'QFN-20','QFN 20 broches',20,'QFN',0.50,'4x4mm','Surface-mount','Package compact pour circuits intégrés','/img/QFN-20.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(36,'QFN-24','QFN 24 broches',24,'QFN',0.50,'4x4mm','Surface-mount','Package haute densité','/img/QFN-24.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(37,'QFN-32','QFN 32 broches',32,'QFN',0.50,'5x5mm','Surface-mount','Package pour microcontrôleurs compacts','/img/QFN-32.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(38,'QFN-48','QFN 48 broches',48,'QFN',0.40,'6x6mm','Surface-mount','Package très haute densité','/img/QFN-48.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(39,'BGA-64','Ball Grid Array 64 broches',64,'BGA',0.80,'8x8mm','Surface-mount','Package à billes pour haute densité','/img/BGA-64.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(40,'BGA-100','BGA 100 broches',100,'BGA',0.80,'10x10mm','Surface-mount','Package BGA pour processeurs','/img/BGA-100.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(41,'BGA-144','BGA 144 broches',144,'BGA',0.80,'12x12mm','Surface-mount','Package BGA haute performance','/img/BGA-144.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(42,'BGA-256','BGA 256 broches',256,'BGA',0.80,'17x17mm','Surface-mount','Package BGA pour processeurs complexes','/img/BGA-256.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(43,'MSOP-8','Mini Small Outline Package 8 broches',8,'MSOP',0.65,'3x3mm','Surface-mount','Package miniature','/img/MSOP-8.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(44,'MSOP-10','MSOP 10 broches',10,'MSOP',0.50,'3x3mm','Surface-mount','Package miniature haute densité','/img/MSOP-10.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(45,'DFN-6','Dual Flat No-leads 6 broches',6,'DFN',0.65,'2x2mm','Surface-mount','Package ultra-compact','/img/DFN-6.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(46,'DFN-8','DFN 8 broches',8,'DFN',0.50,'2x3mm','Surface-mount','Package très compact sans pattes','/img/DFN-8.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(47,'PLCC-20','Plastic Leaded Chip Carrier 20 broches',20,'PLCC',1.27,'9x9mm','Both','Package carré avec pattes en J','/img/PLCC-20.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(48,'PLCC-28','PLCC 28 broches',28,'PLCC',1.27,'11.5x11.5mm','Both','Package pour microcontrôleurs','/img/PLCC-28.png',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(49,'PLCC-44','PLCC 44 broches',44,'PLCC',1.27,'16.6x16.6mm','Both','Package pour circuits complexes','/img/PLCC-44.jpg',1,'2025-07-06 22:43:58','2025-07-25 21:45:27'),(51,'DIP-16','Package DIP 16 broches standard',16,'DIP',2.54,'19.3x6.4mm','Through-hole','Package standard pour microcontrôleurs et circuits logiques','/img/DIP-16.jpg',1,'2025-07-06 22:51:05','2025-07-25 21:45:27'),(52,'DIP-8','Package DIP 8 broches standard',8,'DIP',2.54,'9.9x6.4mm','Through-hole','Package très commun pour les circuits intégrés','/img/DIP-8.jpg',1,'2025-07-06 22:51:16','2025-07-25 21:45:27'),(53,'DIP-14','Package DIP 14 broches',14,'DIP',2.54,'17.8x6.4mm','Through-hole','Package standard pour circuits logiques','/img/DIP-14.jpg',1,'2025-07-06 22:51:25','2025-07-25 21:45:27'),(54,'DIP-28','Package DIP 28 broches',28,'DIP',2.54,'35.6x15.2mm','Through-hole','Package pour microcontrôleurs avancés','/img/DIP-28.jpg',1,'2025-07-06 22:51:31','2025-07-25 21:45:27'),(55,'RADIAL',NULL,2,'Other',NULL,NULL,'Through-hole',NULL,'/img/RADIAL.jpg',1,'2025-07-06 22:54:14','2025-07-25 21:45:27'),(72,'10x38',NULL,2,'Other',38.00,'10x38mm','Through-hole',NULL,'/img/10x38.jpg',1,'2025-07-19 17:56:01','2025-07-25 21:45:27'),(73,'5x20',NULL,2,'Other',20.00,'5x20','Through-hole',NULL,'/img/5x20.jpg',1,'2025-07-19 17:56:21','2025-07-25 21:45:27'),(74,'6x32',NULL,2,'Other',32.00,'6x32','Through-hole',NULL,'/img/6x32.jpg',1,'2025-07-19 17:56:41','2025-07-25 21:45:27'),(75,'DO-4',NULL,2,'DO',5.00,NULL,'Through-hole',NULL,'/img/DO-4.jpg',1,'2025-07-19 18:00:07','2025-07-25 21:45:27'),(77,'DO-15',NULL,2,'DO',2.54,NULL,'Through-hole',NULL,'/img/DO-15.jpg',1,'2025-07-19 19:08:43','2025-07-25 21:45:27'),(78,'DO-35',NULL,2,'DO',NULL,NULL,'Through-hole',NULL,'/img/DO-35.jpg',1,'2025-07-19 19:09:06','2025-07-25 21:45:27'),(79,'DO-41',NULL,2,'DO',NULL,NULL,'Through-hole',NULL,'/img/DO-41.jpg',1,'2025-07-19 19:09:15','2025-07-25 21:45:27'),(80,'DO-201',NULL,2,'DO',NULL,NULL,'Through-hole',NULL,'/img/DO-201.jpg',1,'2025-07-19 19:09:35','2025-07-25 21:45:27'),(81,'DO-204',NULL,2,'DO',NULL,NULL,'Through-hole',NULL,'/img/DO-204.jpg',1,'2025-07-19 19:09:43','2025-07-25 21:45:27'),(82,'DO-204-AA',NULL,2,'DO',NULL,NULL,'Through-hole',NULL,NULL,1,'2025-07-19 19:09:59','2026-10-21 07:43:22'),(83,'DO-214AA (SMB)',NULL,2,'DO',2.75,'5.10x3.30mm','Surface-mount',NULL,'/img/DO-214AA (SMB).jpg',1,'2025-07-19 21:24:07','2025-07-25 21:45:27'),(84,'DO-214AC (SMA)',NULL,2,'DO',2.40,'4.80x2.25','Surface-mount',NULL,'/img/DO-214AC (SMA).jpg',1,'2025-07-19 21:24:54','2025-07-25 21:45:27'),(85,'TO-252 (D-PAK)',NULL,3,'TO',2.54,'6.58x6.10mm','Surface-mount',NULL,'/img/TO-252 (D-PAK).jpg',1,'2025-07-19 21:29:04','2025-07-25 21:45:27'),(86,'TO-263 (D2PAK)',NULL,3,'TO',NULL,'9.65mmx8.63mm','Through-hole',NULL,'/img/TO-263 (D2PAK).jpg',1,'2025-07-19 21:31:53','2025-07-25 22:16:34'),(90,'QFP-32','Quad Flat Package 32 pins',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/QFP-32.jpg',0,'2025-07-22 08:52:44','2025-07-26 07:24:19'),(91,'QFP-44','Quad Flat Package 44 pins',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/QFP-44.png',0,'2025-07-22 08:52:44','2025-07-26 07:24:19'),(92,'QFP-64','Quad Flat Package 64 pins',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/QFP-64.svg',0,'2025-07-22 08:52:44','2025-07-25 22:08:05'),(93,'0603','Résistance/Condensateur 0603',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/0603.png',0,'2025-07-22 08:52:44','2025-07-25 22:23:01'),(94,'0805','Résistance/Condensateur 0805',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/0805.png',0,'2025-07-22 08:52:44','2025-07-31 20:42:12'),(95,'1206','Résistance/Condensateur 1206',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/1206.png',0,'2025-07-22 08:52:44','2025-07-31 20:42:18'),(96,'1210','Résistance/Condensateur 1210',NULL,'Other',NULL,NULL,'Through-hole',NULL,'/img/1210.png',0,'2025-07-22 08:52:44','2025-07-31 20:42:25'),(131,'Mini2-F3-B',NULL,2,'SOIC',2.60,'3.5x1.6mm','Surface-mount',NULL,NULL,1,'2026-08-27 08:20:31','2026-08-27 08:20:31'),(132,'SEMIPACK1','Boîtier ajouté automatiquement depuis le composant « SKKD 100/16 07897171 »',7,'Other',NULL,NULL,'Through-hole',NULL,'img/SEMIPACK1.jpg',1,'2026-10-17 09:57:09','2026-10-17 14:18:15'),(133,'SOT23-6','Boîtier ajouté automatiquement depuis le composant « TPS61165DBVR »',NULL,'SOT',NULL,NULL,'Surface-mount',NULL,'img/SOT23-6.jpg',1,'2026-10-17 10:12:14','2026-10-17 10:12:14'),(134,'HTSSOP-24','Boîtier ajouté automatiquement depuis le composant « TLC5928PWPR »',24,'TSSOP',0.65,NULL,'Surface-mount',NULL,NULL,1,'2026-10-17 10:19:50','2026-10-17 10:22:11'),(140,'SOT-32','Boîtier ajouté automatiquement depuis le composant « BD681 »',3,'SOT',0.95,NULL,'Through-hole',NULL,'img/SOT-32.jpg',1,'2026-10-20 16:10:20','2026-10-20 16:10:20'),(141,'DISQUE 20MM','Boîtier ajouté automatiquement depuis le composant « MOV-20D152KKC »',2,'Other',NULL,NULL,'Through-hole',NULL,NULL,1,'2026-10-20 21:13:23','2026-10-20 21:26:14'),(142,'POTENTIOMETRE 6MM','Boîtier ajouté automatiquement depuis le composant « PST065-201M1-N104 »',3,'Other',NULL,NULL,'Through-hole',NULL,'img/POTENTIOMETRE_6MM.webp',1,'2026-10-21 07:29:22','2026-10-21 07:29:22'),(143,'VARISTANCE','Boîtier ajouté automatiquement depuis le composant « MOV-20D152KKC »',2,'Other',NULL,NULL,'Through-hole',NULL,'img/VARISTANCE.jpg',1,'2026-10-21 08:47:46','2026-10-21 08:47:46'),(144,'RES','Boîtier ajouté automatiquement depuis le composant « RESISTANCE 1.5MR »',2,'Other',NULL,NULL,'Through-hole',NULL,'img/RES.jpg',1,'2026-10-21 14:29:21','2026-10-21 14:29:21'),(145,'TI','Boîtier ajouté automatiquement depuis le composant « Composant \"avec »',NULL,'Other',NULL,NULL,'Through-hole',NULL,NULL,41,'2026-10-21 19:12:55','2026-10-21 19:12:55');
/*!40000 ALTER TABLE `packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_components`
--

DROP TABLE IF EXISTS `project_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `component_id` int(11) NOT NULL,
  `quantity_needed` int(11) DEFAULT 1,
  `quantity_used` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project_component` (`project_id`,`component_id`),
  KEY `component_id` (`component_id`),
  CONSTRAINT `project_components_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_components_ibfk_2` FOREIGN KEY (`component_id`) REFERENCES `data` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_components`
--

LOCK TABLES `project_components` WRITE;
/*!40000 ALTER TABLE `project_components` DISABLE KEYS */;
INSERT INTO `project_components` VALUES (5,1,33,2,0,'','2025-07-17 17:22:59'),(7,1,24,2,0,'','2025-07-19 09:14:57'),(8,1,26,3,0,'','2025-07-19 09:15:32'),(10,1,35,3,1,'','2025-07-19 10:31:41'),(11,1,29,4,3,NULL,'2025-07-28 13:31:18'),(12,1,57,1,0,NULL,'2025-11-29 09:59:47'),(13,1,47,1,0,NULL,'2025-11-29 10:00:05'),(14,4,71,2,0,'','2025-12-13 21:55:12'),(15,4,24,7,0,'','2025-12-13 21:55:23'),(16,1,1002,4,0,'','2026-08-23 12:48:42'),(18,4,42,2,0,'','2026-08-23 12:50:24'),(21,4,1002,2,0,'','2026-08-23 21:14:40'),(23,4,65,1,0,'','2026-08-23 21:15:23'),(24,4,1105,1,0,'','2026-10-17 10:59:06');
/*!40000 ALTER TABLE `project_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_files`
--

DROP TABLE IF EXISTS `project_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_category` enum('document','photo','datasheet','programme','autre','schema') DEFAULT 'autre',
  `description` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_files_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_files`
--

LOCK TABLES `project_files` WRITE;
/*!40000 ALTER TABLE `project_files` DISABLE KEYS */;
INSERT INTO `project_files` VALUES (48,1,'gamege10_1787567850.jpg','gamege10.jpg','PCB','projets/Test/gamege10_1787567850.jpg','image/jpeg',782518,'photo','','2026-08-24 10:37:30'),(52,4,'142126_1787847038.jpg','142126.jpg','142126.jpg','projets/test22/142126_1787847038.jpg','image/jpeg',279794,'photo','','2026-08-27 16:10:38'),(53,1,'Datasheet_1787848827.pdf','Datasheet.pdf','Datasheet.pdf','projets/Test/Datasheet_1787848827.pdf','application/pdf',195923,'datasheet','','2026-08-27 16:40:27');
/*!40000 ALTER TABLE `project_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_items`
--

DROP TABLE IF EXISTS `project_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_items_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_items`
--

LOCK TABLES `project_items` WRITE;
/*!40000 ALTER TABLE `project_items` DISABLE KEYS */;
INSERT INTO `project_items` VALUES (1,1,'matériel','PCB','',1.00,0.00,'pièce',5.00,5.00,'En attente','2025-07-12 14:27:36','2025-07-29 20:40:04'),(2,1,'service','main d\'oeuvre','',1.25,0.00,'h',90.00,112.50,'En attente','2025-07-13 08:26:15','2026-08-26 12:18:44'),(3,1,'travail','test','',1.00,0.00,'h',5.00,5.00,'En attente','2025-07-13 08:36:43','2026-08-24 09:19:05'),(6,1,'travail','poncage','',2.00,0.50,'h',40.00,80.00,'En cours','2025-07-28 20:51:00','2026-08-24 10:36:11'),(7,4,'travail','poncage',NULL,3.00,1.50,'h',40.00,120.00,'En cours','2025-12-13 21:55:48','2026-10-20 16:41:51'),(8,1,'matériel','placo','',10.00,0.00,'m2',15.00,150.00,'En attente','2026-08-26 12:18:21','2026-08-26 12:18:31'),(15,4,'matériel','plaque',NULL,1.00,0.00,'unité',10.00,10.00,'En attente','2026-10-20 16:34:10','2026-10-20 16:34:30');
/*!40000 ALTER TABLE `project_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `status` enum('En cours','Terminé','En pause','Annulé') DEFAULT 'En cours',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `owner` (`owner`),
  CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES (1,1,'Test','Fabrication d\'un pcb gestion lumieres','projets/Test/project_image_1787568748_4973.png','En cours','2025-07-12 09:13:39','2026-08-27 16:53:54'),(4,1,'test22','','projets/test22/project_image_1787565343_3824.jpg','En cours','2025-12-13 21:55:01','2026-08-27 16:11:41');
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `owner` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner`),
  CONSTRAINT `suppliers_ibfk_1` FOREIGN KEY (`owner`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'Mouser Electronics','Support','support@mouser.com','+1-817-804-3800','https://www.mouser.com','Texas, USA','USA','img/MOUSER.png',NULL,NULL,'2025-07-06 09:18:24','2025-07-25 14:51:26',1),(2,'Digi-Key Electronics','Support','support@digikey.com','+1-800-344-4539','https://www.digikey.com','Minnesota, USA','USA','img/DIGIKEY.jpg',NULL,NULL,'2025-07-06 09:18:24','2025-07-25 14:32:12',1),(3,'Farnell','Support','support@farnell.com','+44-113-263-6311','https://www.farnell.com','Leeds, UK','UK','img/FARNELL.png',NULL,NULL,'2025-07-06 09:18:24','2025-07-25 14:32:12',1),(4,'RS Components','Support','support@rs-online.com','+44-1536-444105','https://www.rs-online.com','Corby, UK','UK','img/RS.png',NULL,NULL,'2025-07-06 09:18:24','2025-07-25 14:32:12',1),(5,'Conrad Electronic','Support','support@conrad.fr','01-56-69-50-00','https://www.conrad.fr','France','France','img/CONRAD.png',NULL,NULL,'2025-07-06 09:18:24','2025-07-25 21:30:15',1),(6,'aliexpress',NULL,'ali@orange.fr','01-02-03-04-05','https://www.aliexpress.com','test,USA','chine','img/supplier_aliexpress_1787563058.png',NULL,NULL,'2025-07-25 14:52:07','2026-08-24 09:17:38',1),(13,'E44',NULL,NULL,'02.51.80.73.73','https://www.e44.com/','15 boulevard Président René Coty\r\n44188 Nantes',NULL,'img/supplier_e44_1787765632.png',NULL,NULL,'2026-08-26 17:33:52','2026-08-26 17:33:52',1);
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` enum('utilisateur','administrateur') NOT NULL DEFAULT 'utilisateur',
  `owner_user_id` int(11) DEFAULT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin@compozit.fr','$2y$10$hyMay.1ph57C.i8hwhoG0u7gueSGW.sgPCsxCfLgqYq83xGmygMVe','2025-07-05 21:32:59','administrateur',NULL,NULL,1);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-22 14:38:07
