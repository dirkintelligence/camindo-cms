-- camindo 9.0.1 - Struktur. Generiert von `camindo.php schema export`.

/*M!999999\- enable the sandbox mode */ 

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
DROP TABLE IF EXISTS `admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin` (
  `admin_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint unsigned DEFAULT NULL,
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `password_changed_at` date DEFAULT NULL,
  `password_change` tinyint unsigned NOT NULL DEFAULT '0',
  `uid` varchar(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `uid_date` datetime NOT NULL,
  `code` char(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `second_factor` varchar(8) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `totp_secret` varbinary(128) NOT NULL DEFAULT '',
  `totp_step` bigint unsigned NOT NULL DEFAULT '0',
  `totp_enabled_at` datetime DEFAULT NULL,
  `email` varbinary(256) NOT NULL,
  `mobile` varbinary(256) NOT NULL,
  `sortname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `primename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `displayname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone` varbinary(256) NOT NULL,
  `ft` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `settings` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `login_date` date DEFAULT NULL,
  `status_id` int unsigned NOT NULL DEFAULT '0',
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `deleted` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `idx_name` (`name`) USING BTREE,
  KEY `idx_group` (`group_id`),
  FULLTEXT KEY `ft` (`ft`),
  CONSTRAINT `fk_admin_group` FOREIGN KEY (`group_id`) REFERENCES `group` (`group_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_login_attempt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_login_attempt` (
  `login_attempt_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `attempt_date` datetime NOT NULL,
  PRIMARY KEY (`login_attempt_id`),
  KEY `idx_name_date` (`name`,`attempt_date`),
  KEY `idx_date` (`attempt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_passkey`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_passkey` (
  `passkey_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL,
  `credential_id` varbinary(1023) NOT NULL,
  `credential_hash` binary(32) NOT NULL,
  `public_key` varbinary(1024) NOT NULL,
  `alg` smallint NOT NULL,
  `sign_count` int unsigned NOT NULL DEFAULT '0',
  `transports` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `aaguid` binary(16) NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`passkey_id`),
  UNIQUE KEY `uq_credential` (`credential_hash`),
  KEY `idx_admin` (`admin_id`),
  CONSTRAINT `fk_admin_passkey_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_recovery_code`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_recovery_code` (
  `recovery_code_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL,
  `code_hash` binary(32) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`recovery_code_id`),
  UNIQUE KEY `uq_admin_code` (`admin_id`,`code_hash`),
  CONSTRAINT `fk_admin_recovery_code_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_session`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_session` (
  `session_id` char(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `admin_id` bigint unsigned NOT NULL,
  `interface` varchar(16) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT 'backend',
  `device_id` bigint unsigned DEFAULT NULL,
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  `access_date` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `details` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `expanded_instance_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `expanded_folder_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_device` (`device_id`),
  KEY `idx_admin` (`admin_id`),
  CONSTRAINT `fk_admin_session_device` FOREIGN KEY (`device_id`) REFERENCES `login_device` (`device_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_session_record`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_session_record` (
  `session_id` char(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`session_id`,`record_id`),
  KEY `idx_record` (`record_id`),
  CONSTRAINT `fk_admin_session_record_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `area`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `area` (
  `area_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` int unsigned NOT NULL DEFAULT '1',
  `type` enum('record','template','folder','profile','ugc') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `name` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `icon` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `dependent` bit(1) NOT NULL DEFAULT b'0',
  `position` bigint NOT NULL DEFAULT '1',
  `depth` tinyint unsigned NOT NULL DEFAULT '0',
  `parent_id` bigint unsigned DEFAULT NULL,
  `parent_ids` json NOT NULL,
  `lft` bigint unsigned NOT NULL DEFAULT '0',
  `rgt` bigint unsigned NOT NULL DEFAULT '0',
  `note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `default_field_id` bigint unsigned DEFAULT NULL,
  `sort_field_id` bigint DEFAULT NULL,
  `sort_order` enum('ASC','DESC','','') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'DESC',
  `login_name_field_id` bigint unsigned DEFAULT NULL,
  `login_password_field_id` bigint unsigned DEFAULT NULL,
  `default_template_id` bigint unsigned DEFAULT NULL,
  `list_template_id` bigint unsigned DEFAULT NULL,
  `overview_template_id` bigint unsigned DEFAULT NULL,
  `privacy_policy_id` int unsigned DEFAULT NULL,
  `fulltext_stale_at` datetime DEFAULT NULL COMMENT 'Volltext veraltet seit (UTC), NULL = aktuell',
  `deleted` bit(1) NOT NULL DEFAULT b'0',
  `changed_at` datetime(3) NOT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`area_id`),
  KEY `idx_lft_rgt` (`lft`,`rgt`),
  KEY `idx_parent_pos` (`parent_id`,`position`),
  KEY `fk_area_default_field` (`default_field_id`),
  KEY `fk_area_default_template` (`default_template_id`),
  KEY `fk_area_instance_id` (`instance_id`),
  KEY `idx_name` (`name`),
  KEY `fk_area_login_name_field` (`login_name_field_id`),
  KEY `fk_area_login_password_field` (`login_password_field_id`),
  KEY `fk_area_list_template` (`list_template_id`),
  KEY `fk_area_overview_template` (`overview_template_id`),
  KEY `fk_area_privacy_policy` (`privacy_policy_id`),
  CONSTRAINT `fk_area_default_field` FOREIGN KEY (`default_field_id`) REFERENCES `field` (`field_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_default_template` FOREIGN KEY (`default_template_id`) REFERENCES `template` (`template_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_list_template` FOREIGN KEY (`list_template_id`) REFERENCES `template` (`template_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_login_name_field` FOREIGN KEY (`login_name_field_id`) REFERENCES `field` (`field_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_login_password_field` FOREIGN KEY (`login_password_field_id`) REFERENCES `field` (`field_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_overview_template` FOREIGN KEY (`overview_template_id`) REFERENCES `template` (`template_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_area_parent` FOREIGN KEY (`parent_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_area_privacy_policy` FOREIGN KEY (`privacy_policy_id`) REFERENCES `privacy_policy` (`privacy_policy_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `audit_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime(3) NOT NULL,
  `actor_type` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'system',
  `admin_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `actor_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `origin` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `action` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `object_type` varchar(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `object_id` bigint unsigned NOT NULL DEFAULT '0',
  `area_id` bigint unsigned DEFAULT NULL,
  `instance_id` int unsigned DEFAULT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `fields` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `page` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`audit_id`),
  KEY `idx_object` (`object_type`,`object_id`,`audit_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_admin` (`admin_id`,`audit_id`),
  KEY `idx_action` (`action`,`created_at`),
  KEY `idx_area` (`area_id`,`audit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bgproc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bgproc` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `admin_id` bigint unsigned NOT NULL DEFAULT '0',
  `pid` int unsigned NOT NULL DEFAULT '0',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `progress` tinyint unsigned NOT NULL DEFAULT '0',
  `message` varchar(4096) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `pid_status` (`pid`,`status`),
  KEY `admin_id` (`admin_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `component`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `component` (
  `component_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `component_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `label` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `icon` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `core` bit(1) NOT NULL,
  `version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `update_version` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `tables` json DEFAULT NULL,
  `installed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`component_id`),
  UNIQUE KEY `idx_unique` (`name`,`component_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `converter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `converter` (
  `converter_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processor` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'other',
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `media_types_incl` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `media_types_excl` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `extension` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`converter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_bit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_bit` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` tinyint unsigned NOT NULL DEFAULT '0',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_bit_field` (`field_id`),
  KEY `fk_data_bit_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_bit_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_bit_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_chr`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_chr` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_chr_field` (`field_id`),
  KEY `fk_data_chr_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`(32)) USING BTREE,
  CONSTRAINT `fk_data_chr_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_chr_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_cur`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_cur` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` bigint NOT NULL DEFAULT '0',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_cur_field` (`field_id`),
  KEY `fk_data_cur_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_cur_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_cur_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_dat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_dat` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_dat_field` (`field_id`),
  KEY `fk_data_dat_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_dat_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_dat_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_dec`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_dec` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` decimal(20,10) NOT NULL DEFAULT '0.0000000000',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_dec_field` (`field_id`),
  KEY `fk_data_dec_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_dec_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_dec_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_dft`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_dft` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `data` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`language_id`,`record_id`),
  KEY `idx_data` (`data`(32)),
  KEY `fk_data_dft_record` (`record_id`),
  CONSTRAINT `fk_data_dft_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_enc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_enc` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` bigint unsigned NOT NULL,
  `data` mediumblob NOT NULL,
  `hash` varbinary(32) NOT NULL,
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_enc_field` (`field_id`),
  KEY `fk_data_enc_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`hash`),
  CONSTRAINT `fk_data_enc_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_enc_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_fil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_fil` (
  `language_id` int unsigned NOT NULL,
  `file_language_id` int unsigned DEFAULT NULL,
  `record_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `extension` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `size` int unsigned NOT NULL DEFAULT '0',
  `width` int unsigned NOT NULL DEFAULT '0',
  `height` int unsigned NOT NULL DEFAULT '0',
  `token` varchar(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `version` mediumint unsigned NOT NULL DEFAULT '0',
  `data` json NOT NULL,
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_fil_field` (`field_id`),
  KEY `fk_data_fil_record` (`record_id`),
  KEY `idx_filter_filename` (`language_id`,`fieldname_id`,`filename`),
  CONSTRAINT `fk_data_fil_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_fil_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_flt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_flt` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` double NOT NULL DEFAULT '0',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_flt_field` (`field_id`),
  KEY `fk_data_flt_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_flt_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_flt_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_ftx`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_ftx` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned DEFAULT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci NOT NULL,
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_ftx_field` (`field_id`),
  KEY `fk_data_ftx_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`),
  FULLTEXT KEY `idx_data_fulltext` (`data`),
  CONSTRAINT `fk_data_ftx_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_ftx_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_int`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_int` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` bigint DEFAULT '0',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_int_field` (`field_id`),
  KEY `fk_data_int_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_int_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_int_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_jsn`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_jsn` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_jsn_field` (`field_id`),
  KEY `fk_data_jsn_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`),
  KEY `idx_json_array` ((cast(`data` as char(32) array))),
  CONSTRAINT `fk_data_jsn_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_jsn_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_pwd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_pwd` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` varchar(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_pwd_field` (`field_id`),
  KEY `fk_data_pwd_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_pwd_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_pwd_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_rel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_rel` (
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `src_id` bigint unsigned NOT NULL,
  `dest_id` bigint unsigned NOT NULL,
  `position` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`src_id`,`fieldname_id`,`dest_id`),
  KEY `fk_data_rel_field` (`field_id`),
  KEY `idx_dest_src_fieldname` (`dest_id`,`src_id`,`fieldname_id`),
  CONSTRAINT `fk_data_rel_dest` FOREIGN KEY (`dest_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_rel_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_rel_src` FOREIGN KEY (`src_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_rel_checkout`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_rel_checkout` (
  `tmp_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `src_id` bigint unsigned NOT NULL,
  `dest_id` bigint unsigned NOT NULL,
  `position` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`tmp_id`,`src_id`,`fieldname_id`,`dest_id`),
  CONSTRAINT `fk_data_rel_checkout_tmp` FOREIGN KEY (`tmp_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_rid`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_rid` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_rid_field` (`field_id`),
  KEY `fk_data_rid_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  KEY `fk_data_rid_data_record` (`data`),
  CONSTRAINT `fk_data_rid_data_record` FOREIGN KEY (`data`) REFERENCES `record` (`record_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_data_rid_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_rid_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_tim`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_tim` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` time NOT NULL DEFAULT '00:00:00',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_tim_field` (`field_id`),
  KEY `fk_data_tim_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_tim_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_tim_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_tsl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_tsl` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` datetime(3) NOT NULL DEFAULT '0000-00-00 00:00:00.000',
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_tsl_field` (`field_id`),
  KEY `fk_data_tsl_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`),
  CONSTRAINT `fk_data_tsl_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_data_tsl_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_txt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_txt` (
  `language_id` int unsigned NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `field_id` bigint unsigned NOT NULL,
  `fieldname_id` int unsigned NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `empty` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`language_id`,`record_id`,`fieldname_id`),
  KEY `fk_data_txt_field` (`field_id`),
  KEY `fk_data_txt_record` (`record_id`),
  KEY `idx_filter` (`language_id`,`fieldname_id`,`data`(32)),
  CONSTRAINT `fk_data_txt_field` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_data_txt_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `doc_keyword`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `doc_keyword` (
  `keyword_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `term` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `section_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`keyword_id`),
  KEY `idx_lang_term` (`lang`,`term`),
  KEY `idx_section` (`section_id`),
  CONSTRAINT `fk_doc_keyword_section` FOREIGN KEY (`section_id`) REFERENCES `doc_section` (`section_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `doc_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `doc_section` (
  `section_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(10) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `page` varchar(190) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `book` varchar(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `position` int unsigned NOT NULL DEFAULT '0',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `heading` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `level` tinyint unsigned NOT NULL DEFAULT '0',
  `content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`section_id`),
  KEY `idx_lang_page` (`lang`,`page`,`position`),
  FULLTEXT KEY `ft` (`heading`,`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `field`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `field` (
  `field_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `area_id` bigint unsigned NOT NULL DEFAULT '0',
  `type` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `display_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `note` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `tooltip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `height` int DEFAULT '0',
  `min` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `min_op` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `max` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `max_op` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `pattern` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `prefix` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `suffix` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '',
  `position` bigint unsigned NOT NULL DEFAULT '0',
  `rel_field_id` bigint unsigned DEFAULT NULL,
  `rel_area_id` bigint unsigned DEFAULT NULL,
  `custom_order` bit(1) NOT NULL DEFAULT b'0',
  `flags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `parent_field_id` bigint unsigned DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `default` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `visibility_input` bit(1) NOT NULL DEFAULT b'0',
  `visibility_search` bit(1) NOT NULL DEFAULT b'0',
  `visibility_list` bit(1) NOT NULL DEFAULT b'0',
  `default_field` bit(1) NOT NULL DEFAULT b'0',
  `meta` bit(1) NOT NULL DEFAULT b'0',
  `multilingual` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`field_id`),
  KEY `idx_area_id` (`area_id`),
  KEY `idx_name` (`name`),
  KEY `idx_type` (`type`),
  KEY `idx_ref_field_id` (`rel_field_id`),
  KEY `fk_field_rel_area` (`rel_area_id`),
  KEY `fk_field_parent_field` (`parent_field_id`),
  CONSTRAINT `fk_field_area` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_field_parent_field` FOREIGN KEY (`parent_field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_field_rel_area` FOREIGN KEY (`rel_area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_field_rel_field` FOREIGN KEY (`rel_field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fieldname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fieldname` (
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `fieldname_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`fieldname_id`),
  UNIQUE KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `group` (
  `group_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `history` (
  `history_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL,
  `record_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `changed_at` datetime(3) NOT NULL,
  PRIMARY KEY (`history_id`),
  UNIQUE KEY `idx_admin_record` (`admin_id`,`record_id`),
  UNIQUE KEY `idx_admin_template` (`admin_id`,`template_id`),
  KEY `idx_admin_changed_at` (`admin_id`,`changed_at`),
  KEY `fk_history_record_id` (`record_id`),
  KEY `fk_history_template_id` (`template_id`),
  CONSTRAINT `fk_history_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_record_id` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_history_template_id` FOREIGN KEY (`template_id`) REFERENCES `template` (`template_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `instance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `instance` (
  `instance_id` int unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `position` int unsigned NOT NULL,
  `primary_language_id` int unsigned NOT NULL,
  `primary_domain` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `protocol` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `canonical` bit(1) NOT NULL DEFAULT b'1',
  `record_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `details` json DEFAULT NULL,
  PRIMARY KEY (`instance_id`),
  KEY `idx_sort` (`position`) USING BTREE,
  KEY `fk_instance_record_id` (`record_id`),
  KEY `fk_instance_template_Id` (`template_id`),
  CONSTRAINT `fk_instance_record_id` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_instance_template_Id` FOREIGN KEY (`template_id`) REFERENCES `template` (`template_id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `language`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `language` (
  `language_id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `backend_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `endonym` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `timezone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `position` int unsigned NOT NULL,
  PRIMARY KEY (`language_id`),
  KEY `idx_sort` (`position`) USING BTREE,
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_device`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_device` (
  `device_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned DEFAULT NULL,
  `record_id` bigint unsigned DEFAULT NULL,
  `token_hash` binary(32) NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `uq_admin_token` (`admin_id`,`token_hash`),
  UNIQUE KEY `uq_record_token` (`record_id`,`token_hash`),
  KEY `idx_last_seen` (`last_seen_at`),
  CONSTRAINT `fk_login_device_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_login_device_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message` (
  `message_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_admin_id` bigint unsigned NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `body` mediumblob NOT NULL,
  `html` mediumblob NOT NULL,
  `record_id` bigint unsigned DEFAULT NULL,
  `is_read` bit(1) NOT NULL DEFAULT b'0',
  `is_important` bit(1) NOT NULL DEFAULT b'0',
  `recipient_deleted` bit(1) NOT NULL DEFAULT b'0',
  `sender_deleted` bit(1) NOT NULL DEFAULT b'0',
  `created_by` bigint unsigned DEFAULT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_to_admin_read` (`to_admin_id`,`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_message_record_id` (`record_id`),
  KEY `fk_message_created_by` (`created_by`),
  KEY `fk_message_changed_by` (`changed_by`),
  CONSTRAINT `fk_message_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `admin` (`admin_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_message_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_message_record_id` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_message_to_admin_id` FOREIGN KEY (`to_admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `privacy_job`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privacy_job` (
  `job_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `record_id` bigint unsigned NOT NULL,
  `area_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `privacy_policy_id` int unsigned DEFAULT NULL,
  `source` varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `action` varchar(24) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `fields` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `due_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `last_error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`job_id`),
  KEY `idx_due` (`done_at`,`due_at`),
  KEY `idx_record` (`record_id`,`done_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `privacy_policy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privacy_policy` (
  `privacy_policy_id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` int NOT NULL DEFAULT '-1',
  `label` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `standard` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `blocks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`privacy_policy_id`),
  KEY `idx_instance` (`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `privacy_template`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privacy_template` (
  `privacy_template_id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'user',
  `instance_id` int unsigned NOT NULL,
  `scope_id` int unsigned NOT NULL DEFAULT '0',
  `language_id` int unsigned NOT NULL,
  `content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`privacy_template_id`),
  UNIQUE KEY `uniq_kind_instance_scope_language` (`kind`,`instance_id`,`scope_id`,`language_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limit` (
  `rate_limit_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_hash` binary(32) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`rate_limit_id`),
  KEY `idx_bucket_key` (`bucket`,`key_hash`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `record`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `record` (
  `record_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `area_id` bigint unsigned NOT NULL DEFAULT '0',
  `instance_id` int unsigned NOT NULL DEFAULT '1',
  `position` bigint NOT NULL DEFAULT '1',
  `depth` tinyint unsigned NOT NULL DEFAULT '0',
  `parent_id` bigint unsigned DEFAULT NULL,
  `parent_ids` json NOT NULL,
  `lft` bigint unsigned NOT NULL DEFAULT '0',
  `rgt` bigint unsigned NOT NULL DEFAULT '0',
  `template_id` bigint unsigned DEFAULT NULL,
  `online_at` datetime NOT NULL,
  `offline_at` datetime NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `changed_by` bigint unsigned DEFAULT NULL,
  `owner_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `changed_at` datetime(3) NOT NULL,
  `status_id` tinyint unsigned NOT NULL DEFAULT '0',
  `needs_review` bit(1) NOT NULL DEFAULT b'0',
  `tmp` bit(1) NOT NULL DEFAULT b'0',
  `origin_id` bigint DEFAULT NULL,
  `locked` bit(1) NOT NULL,
  `deleted` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`record_id`),
  KEY `idx_lft_rgt` (`lft`,`rgt`),
  KEY `idx_area_pos` (`area_id`,`position`) USING BTREE,
  KEY `idx_changed_at` (`changed_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_parent_lft` (`parent_id`,`lft`) USING BTREE,
  KEY `fk_record_changed_by` (`changed_by`),
  KEY `fk_record_created_by` (`created_by`),
  KEY `fk_record_instance_id` (`instance_id`),
  KEY `fk_record_user_id` (`user_id`),
  KEY `fk_record_owner_id` (`owner_id`),
  CONSTRAINT `fk_record_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `admin` (`admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_record_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin` (`admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_record_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_record_owner_id` FOREIGN KEY (`owner_id`) REFERENCES `admin` (`admin_id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_record_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_record_user_id` FOREIGN KEY (`user_id`) REFERENCES `record` (`record_id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `record_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `record_cache` (
  `record_id` bigint unsigned NOT NULL,
  `language_id` int unsigned NOT NULL,
  `cache` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`record_id`,`language_id`),
  CONSTRAINT `fk_record_cache_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_area_template`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_area_template` (
  `area_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `selectable` bit(1) NOT NULL DEFAULT b'0',
  `override` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`area_id`,`template_id`),
  KEY `idx_template_id` (`template_id`),
  CONSTRAINT `fk_rat_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rat_template_id` FOREIGN KEY (`template_id`) REFERENCES `template` (`template_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_area_trigger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_area_trigger` (
  `area_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`area_id`,`template_id`),
  KEY `idx_template_id` (`template_id`),
  CONSTRAINT `fk_rat_trigger_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rat_trigger_template_id` FOREIGN KEY (`template_id`) REFERENCES `template` (`template_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_field_area`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_field_area` (
  `field_id` bigint unsigned NOT NULL,
  `area_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`field_id`,`area_id`),
  KEY `fk_rfa_area_id` (`area_id`),
  CONSTRAINT `fk_rfa_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rfa_field_id` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_field_converter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_field_converter` (
  `field_id` bigint unsigned NOT NULL,
  `converter_id` bigint unsigned NOT NULL,
  `position` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`converter_id`,`field_id`),
  KEY `fk_rfc_field_id` (`field_id`),
  CONSTRAINT `fk_rfc_converter_id` FOREIGN KEY (`converter_id`) REFERENCES `converter` (`converter_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rfc_field_id` FOREIGN KEY (`field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_fulltext_text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_fulltext_text` (
  `fulltext_field_id` bigint unsigned NOT NULL,
  `rel_field_id` bigint unsigned DEFAULT NULL,
  `text_field_id` bigint unsigned NOT NULL,
  KEY `fk_rt_field_id` (`fulltext_field_id`),
  KEY `fk_rt_rel_field_id` (`rel_field_id`),
  KEY `fk_rf_text_field_id` (`text_field_id`),
  CONSTRAINT `fk_rf_text_field_id` FOREIGN KEY (`text_field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rt_field_id` FOREIGN KEY (`fulltext_field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rt_rel_field_id` FOREIGN KEY (`rel_field_id`) REFERENCES `field` (`field_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_group_area`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_group_area` (
  `group_id` bigint unsigned NOT NULL,
  `area_id` bigint unsigned NOT NULL,
  `group_write` bit(1) NOT NULL DEFAULT b'1',
  `group_delete` bit(1) NOT NULL DEFAULT b'1',
  `other_write` bit(1) NOT NULL DEFAULT b'1',
  `other_delete` bit(1) NOT NULL DEFAULT b'1',
  `set_owner` bit(1) NOT NULL DEFAULT b'0',
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`group_id`,`area_id`),
  KEY `fk_rga_area_id` (`area_id`),
  CONSTRAINT `fk_rga_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rga_group_id` FOREIGN KEY (`group_id`) REFERENCES `group` (`group_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_group_instance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_group_instance` (
  `group_id` bigint unsigned NOT NULL,
  `instance_id` int unsigned NOT NULL,
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`group_id`,`instance_id`),
  KEY `fk_rgi_instance_id` (`instance_id`),
  CONSTRAINT `fk_rgi_group_id` FOREIGN KEY (`group_id`) REFERENCES `group` (`group_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rgi_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_group_language`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_group_language` (
  `group_id` bigint unsigned NOT NULL,
  `language_id` int unsigned NOT NULL,
  `can_write` bit(1) NOT NULL DEFAULT b'0',
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`group_id`,`language_id`),
  KEY `fk_rgl_language_id` (`language_id`),
  CONSTRAINT `fk_rgl_group_id` FOREIGN KEY (`group_id`) REFERENCES `group` (`group_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rgl_language_id` FOREIGN KEY (`language_id`) REFERENCES `language` (`language_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_group_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_group_status` (
  `group_id` bigint unsigned NOT NULL,
  `status_id` int unsigned NOT NULL,
  `sig` char(43) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL DEFAULT '',
  PRIMARY KEY (`group_id`,`status_id`),
  KEY `fk_rgs_status_id` (`status_id`),
  CONSTRAINT `fk_rgs_group_id` FOREIGN KEY (`group_id`) REFERENCES `group` (`group_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rgs_status_id` FOREIGN KEY (`status_id`) REFERENCES `status` (`status_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_instance_domain`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_instance_domain` (
  `instance_id` int unsigned NOT NULL,
  `domain` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `regex` bit(1) NOT NULL DEFAULT b'0',
  `position` int unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`domain`),
  CONSTRAINT `fk_rid_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_instance_instance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_instance_instance` (
  `instance_id` int unsigned NOT NULL,
  `readable_id` int unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`readable_id`),
  KEY `fk_rii_readable_instance_instance_id` (`readable_id`),
  CONSTRAINT `fk_rii_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_rii_readable_instance_instance_id` FOREIGN KEY (`readable_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rel_instance_language`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rel_instance_language` (
  `instance_id` int unsigned NOT NULL,
  `language_id` int unsigned NOT NULL,
  `position` int unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`language_id`),
  KEY `idx_position` (`position`),
  KEY `fk_ril_language_id` (`language_id`),
  CONSTRAINT `fk_ril_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_ril_language_id` FOREIGN KEY (`language_id`) REFERENCES `language` (`language_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduler`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduler` (
  `scheduler_id` int unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `admin_id` bigint unsigned DEFAULT NULL,
  `enabled` bit(1) NOT NULL DEFAULT b'1',
  `max_minutes` int unsigned NOT NULL DEFAULT '10',
  `log` bit(1) NOT NULL DEFAULT b'0',
  `cron_minute` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `cron_hour` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `cron_day` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `cron_month` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `cron_year` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `cron_weekday` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '*',
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `next_run` datetime DEFAULT NULL,
  `last_run` datetime DEFAULT NULL,
  `last_status` tinyint unsigned NOT NULL DEFAULT '0',
  `last_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`scheduler_id`),
  KEY `idx_next_run` (`enabled`,`next_run`),
  KEY `idx_admin` (`admin_id`),
  CONSTRAINT `fk_scheduler_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `status` (
  `status_id` int unsigned NOT NULL AUTO_INCREMENT,
  `icon` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `color_class` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `position` int unsigned NOT NULL,
  PRIMARY KEY (`status_id`),
  KEY `idx_sort` (`position`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system` (
  `key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `template`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `template` (
  `template_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `area_id` bigint unsigned NOT NULL DEFAULT '0',
  `instance_id` int unsigned NOT NULL DEFAULT '1',
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `code` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `styled_code` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `error_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `type` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT '0',
  `changed_by` bigint unsigned DEFAULT '0',
  `created_at` datetime(3) NOT NULL,
  `changed_at` datetime(3) NOT NULL,
  `standalone` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`template_id`),
  KEY `fk_template_created_by` (`created_by`),
  KEY `fk_template_changed_by` (`changed_by`),
  KEY `fk_template_area_id` (`area_id`),
  KEY `fk_template_instance_id` (`instance_id`),
  CONSTRAINT `fk_template_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_template_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_template_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin` (`admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_template_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `instance` (`instance_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_basket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_basket` (
  `basket_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` char(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `category` int unsigned NOT NULL DEFAULT '0',
  `record_id` bigint unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `addon` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`basket_id`),
  UNIQUE KEY `idx_session_item` (`session_id`,`category`,`record_id`),
  KEY `idx_changed_at` (`changed_at`),
  KEY `fk_user_basket_record` (`record_id`),
  CONSTRAINT `fk_user_basket_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_basket_session` FOREIGN KEY (`session_id`) REFERENCES `user_session` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_login_attempt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_login_attempt` (
  `login_attempt_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT '',
  `attempt_date` datetime NOT NULL,
  PRIMARY KEY (`login_attempt_id`),
  KEY `idx_name_date` (`name`,`attempt_date`),
  KEY `idx_date` (`attempt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_recovery_code`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_recovery_code` (
  `recovery_code_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `record_id` bigint unsigned NOT NULL,
  `code_hash` binary(32) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`recovery_code_id`),
  UNIQUE KEY `uq_record_code` (`record_id`,`code_hash`),
  CONSTRAINT `fk_user_recovery_code_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_second_factor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_second_factor` (
  `record_id` bigint unsigned NOT NULL,
  `method` enum('totp','email') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `totp_secret` varbinary(128) NOT NULL DEFAULT '',
  `totp_step` bigint NOT NULL DEFAULT '0',
  `enabled_at` datetime NOT NULL,
  PRIMARY KEY (`record_id`),
  CONSTRAINT `fk_user_second_factor_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_session`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_session` (
  `session_id` char(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `record_id` bigint unsigned DEFAULT NULL,
  `area_id` bigint unsigned NOT NULL DEFAULT '0',
  `instance_id` int unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `access_date` datetime NOT NULL,
  `details` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_record_id` (`record_id`),
  KEY `idx_access_date` (`access_date`),
  CONSTRAINT `fk_user_session_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_token`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_token` (
  `token` char(43) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `purpose` enum('register','password') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_record_purpose` (`record_id`,`purpose`),
  CONSTRAINT `fk_user_token_record` FOREIGN KEY (`record_id`) REFERENCES `record` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

