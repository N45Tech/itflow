/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.18-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: itflow_dev
-- ------------------------------------------------------
-- Server version	10.11.18-MariaDB-0+deb12u1

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
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(200) NOT NULL,
  `account_description` varchar(250) DEFAULT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `account_currency_code` varchar(200) NOT NULL,
  `account_notes` text DEFAULT NULL,
  `account_type` int(6) DEFAULT NULL,
  `account_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `account_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `account_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_models`
--

DROP TABLE IF EXISTS `ai_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_models` (
  `ai_model_id` int(11) NOT NULL AUTO_INCREMENT,
  `ai_model_name` varchar(200) NOT NULL,
  `ai_model_prompt` text DEFAULT NULL,
  `ai_model_use_case` varchar(200) DEFAULT NULL,
  `ai_model_temperature` decimal(3,2) DEFAULT NULL,
  `ai_model_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ai_model_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ai_model_ai_provider_id` int(11) NOT NULL,
  PRIMARY KEY (`ai_model_id`),
  KEY `ai_model_ai_provider_id` (`ai_model_ai_provider_id`),
  CONSTRAINT `ai_models_ibfk_1` FOREIGN KEY (`ai_model_ai_provider_id`) REFERENCES `ai_providers` (`ai_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_providers`
--

DROP TABLE IF EXISTS `ai_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_providers` (
  `ai_provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `ai_provider_name` varchar(200) NOT NULL,
  `ai_provider_api_url` varchar(200) NOT NULL,
  `ai_provider_api_key` varchar(200) DEFAULT NULL,
  `ai_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ai_provider_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`ai_provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_keys`
--

DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `api_key_id` int(11) NOT NULL AUTO_INCREMENT,
  `api_key_name` varchar(255) NOT NULL,
  `api_key_secret` varchar(255) NOT NULL,
  `api_key_decrypt_hash` varchar(200) NOT NULL,
  `api_key_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `api_key_expire` date NOT NULL,
  `api_key_user_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`api_key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `app_logs`
--

DROP TABLE IF EXISTS `app_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_logs` (
  `app_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `app_log_category` varchar(200) DEFAULT NULL,
  `app_log_type` enum('info','warning','error','debug') NOT NULL DEFAULT 'info',
  `app_log_details` varchar(1000) DEFAULT NULL,
  `app_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`app_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_credentials`
--

DROP TABLE IF EXISTS `asset_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_credentials` (
  `credential_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`credential_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `asset_credentials_ibfk_1` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_credentials_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_change_events`
--

DROP TABLE IF EXISTS `asset_change_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_change_events` (
  `asset_change_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `asset_change_event_asset_id` int(11) NOT NULL,
  `asset_change_event_client_id` int(11) NOT NULL,
  `asset_change_event_source` varchar(40) NOT NULL,
  `asset_change_event_type` varchar(40) NOT NULL,
  `asset_change_event_external_key` varchar(255) DEFAULT NULL,
  `asset_change_event_summary` varchar(500) NOT NULL,
  `asset_change_event_before` longtext NOT NULL,
  `asset_change_event_after` longtext NOT NULL,
  `asset_change_event_fingerprint` char(64) NOT NULL,
  `asset_change_event_delivery_key` char(64) NOT NULL DEFAULT '',
  `asset_change_event_canonical` tinyint(1) NOT NULL DEFAULT 1,
  `asset_change_event_superseded_at` datetime DEFAULT NULL,
  `asset_change_event_occurred_at` datetime NOT NULL,
  `asset_change_event_recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_change_event_ticket_id` int(11) NOT NULL DEFAULT 0,
  `asset_change_event_ticket_label` varchar(500) DEFAULT NULL,
  `asset_change_event_document_id` int(11) NOT NULL DEFAULT 0,
  `asset_change_event_document_label` varchar(500) DEFAULT NULL,
  `asset_change_event_evidence_id` bigint(20) NOT NULL DEFAULT 0,
  `asset_change_event_evidence_label` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`asset_change_event_id`),
  UNIQUE KEY `asset_change_event_fingerprint` (`asset_change_event_fingerprint`),
  KEY `asset_change_event_asset_time` (`asset_change_event_asset_id`,`asset_change_event_occurred_at`),
  KEY `asset_change_event_client_time` (`asset_change_event_client_id`,`asset_change_event_occurred_at`),
  KEY `asset_change_event_ticket` (`asset_change_event_ticket_id`),
  CONSTRAINT `asset_change_events_asset_fk` FOREIGN KEY (`asset_change_event_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_custom`
--

DROP TABLE IF EXISTS `asset_custom`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_custom` (
  `asset_custom_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_custom_field_value` int(11) NOT NULL,
  `asset_custom_field_id` int(11) NOT NULL,
  `asset_custom_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_custom_id`),
  KEY `asset_custom_asset_id` (`asset_custom_asset_id`),
  CONSTRAINT `asset_custom_ibfk_1` FOREIGN KEY (`asset_custom_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_documents`
--

DROP TABLE IF EXISTS `asset_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_documents` (
  `asset_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `asset_documents_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_endpoint_states`
--

DROP TABLE IF EXISTS `asset_endpoint_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_endpoint_states` (
  `endpoint_state_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `endpoint_state_asset_id` int(11) NOT NULL,
  `endpoint_state_client_id` int(11) NOT NULL,
  `endpoint_state_source` varchar(40) NOT NULL,
  `endpoint_state_external_id` varchar(255) NOT NULL,
  `endpoint_state_status` varchar(20) NOT NULL DEFAULT 'active',
  `endpoint_state_health` varchar(20) NOT NULL DEFAULT 'unknown',
  `endpoint_state_compliance` varchar(20) NOT NULL DEFAULT 'unknown',
  `endpoint_state_encryption` varchar(20) NOT NULL DEFAULT 'unknown',
  `endpoint_state_secure_boot` varchar(20) NOT NULL DEFAULT 'unknown',
  `endpoint_state_assigned_user_external_id` varchar(255) DEFAULT NULL,
  `endpoint_state_assigned_user_name` varchar(255) DEFAULT NULL,
  `endpoint_state_assigned_user_email` varchar(320) DEFAULT NULL,
  `endpoint_state_entra_device_id` varchar(255) DEFAULT NULL,
  `endpoint_state_intune_device_id` varchar(255) DEFAULT NULL,
  `endpoint_state_os_name` varchar(200) DEFAULT NULL,
  `endpoint_state_os_version` varchar(100) DEFAULT NULL,
  `endpoint_state_os_build` varchar(100) DEFAULT NULL,
  `endpoint_state_agent_version` varchar(100) DEFAULT NULL,
  `endpoint_state_lifecycle` varchar(20) NOT NULL DEFAULT 'unknown',
  `endpoint_state_payload_hash` char(64) NOT NULL,
  `endpoint_state_payload` longtext NOT NULL,
  `endpoint_state_network_hash` char(64) NOT NULL DEFAULT '',
  `endpoint_state_network_observed_at` datetime DEFAULT NULL,
  `endpoint_state_delivery_key` char(64) NOT NULL DEFAULT '',
  `endpoint_state_delivery_baseline` longtext DEFAULT NULL,
  `endpoint_state_first_observed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `endpoint_state_observed_at` datetime NOT NULL,
  `endpoint_state_last_seen_at` datetime DEFAULT NULL,
  `endpoint_state_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `endpoint_state_retired_at` datetime DEFAULT NULL,
  PRIMARY KEY (`endpoint_state_id`),
  UNIQUE KEY `endpoint_state_source_external` (`endpoint_state_source`,`endpoint_state_external_id`),
  UNIQUE KEY `endpoint_state_asset_source` (`endpoint_state_asset_id`,`endpoint_state_source`),
  KEY `endpoint_state_client_status` (`endpoint_state_client_id`,`endpoint_state_status`,`endpoint_state_health`),
  KEY `endpoint_state_observed` (`endpoint_state_observed_at`),
  CONSTRAINT `asset_endpoint_states_asset_fk` FOREIGN KEY (`endpoint_state_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_files`
--

DROP TABLE IF EXISTS `asset_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_files` (
  `asset_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `asset_files_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_history`
--

DROP TABLE IF EXISTS `asset_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_history` (
  `asset_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_history_status` varchar(200) NOT NULL,
  `asset_history_description` varchar(255) NOT NULL,
  `asset_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_history_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_history_id`),
  KEY `asset_history_asset_id` (`asset_history_asset_id`),
  CONSTRAINT `asset_history_ibfk_1` FOREIGN KEY (`asset_history_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_interface_links`
--

DROP TABLE IF EXISTS `asset_interface_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_interface_links` (
  `interface_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `interface_a_id` int(11) NOT NULL,
  `interface_b_id` int(11) NOT NULL,
  `interface_link_type` varchar(100) DEFAULT NULL,
  `interface_link_status` varchar(50) DEFAULT NULL,
  `interface_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `interface_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`interface_link_id`),
  KEY `fk_interface_a` (`interface_a_id`),
  KEY `fk_interface_b` (`interface_b_id`),
  CONSTRAINT `fk_interface_a` FOREIGN KEY (`interface_a_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_interface_b` FOREIGN KEY (`interface_b_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_interfaces`
--

DROP TABLE IF EXISTS `asset_interfaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_interfaces` (
  `interface_id` int(11) NOT NULL AUTO_INCREMENT,
  `interface_name` varchar(200) NOT NULL,
  `interface_description` varchar(200) DEFAULT NULL,
  `interface_type` varchar(50) DEFAULT NULL,
  `interface_mac` varchar(200) DEFAULT NULL,
  `interface_ip` varchar(200) DEFAULT NULL,
  `interface_nat_ip` varchar(200) DEFAULT NULL,
  `interface_ipv6` varchar(200) DEFAULT NULL,
  `interface_notes` text DEFAULT NULL,
  `interface_primary` tinyint(1) DEFAULT 0,
  `interface_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `interface_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `interface_archived_at` datetime DEFAULT NULL,
  `interface_network_id` int(11) DEFAULT NULL,
  `interface_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`interface_id`),
  KEY `interface_asset_id` (`interface_asset_id`),
  CONSTRAINT `asset_interfaces_ibfk_1` FOREIGN KEY (`interface_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_notes`
--

DROP TABLE IF EXISTS `asset_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_notes` (
  `asset_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_note_type` varchar(200) NOT NULL,
  `asset_note` text DEFAULT NULL,
  `asset_note_created_by` int(11) NOT NULL,
  `asset_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `asset_note_archived_at` datetime DEFAULT NULL,
  `asset_note_asset_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_note_id`),
  KEY `asset_note_asset_id` (`asset_note_asset_id`),
  CONSTRAINT `asset_notes_ibfk_1` FOREIGN KEY (`asset_note_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_network_observations`
--

DROP TABLE IF EXISTS `asset_network_observations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_network_observations` (
  `network_observation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `network_observation_asset_id` int(11) NOT NULL,
  `network_observation_client_id` int(11) NOT NULL,
  `network_observation_interface_id` int(11) DEFAULT NULL,
  `network_observation_source` varchar(40) NOT NULL,
  `network_observation_key` varchar(255) NOT NULL,
  `network_observation_identity_hash` char(64) NOT NULL,
  `network_observation_state_hash` char(64) NOT NULL,
  `network_observation_payload` longtext NOT NULL,
  `network_observation_created_delivery_key` char(64) NOT NULL DEFAULT '',
  `network_observation_closed_delivery_key` char(64) DEFAULT NULL,
  `network_observation_last_seen_delivery_key` char(64) DEFAULT NULL,
  `network_observation_previous_last_seen_at` datetime DEFAULT NULL,
  `network_observation_canonical` tinyint(1) NOT NULL DEFAULT 1,
  `network_observation_superseded_at` datetime DEFAULT NULL,
  `network_observation_first_seen_at` datetime NOT NULL,
  `network_observation_last_seen_at` datetime NOT NULL,
  `network_observation_active` tinyint(1) NOT NULL DEFAULT 1,
  `network_observation_ended_at` datetime DEFAULT NULL,
  `network_observation_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`network_observation_id`),
  KEY `network_observation_asset_source_state` (`network_observation_asset_id`,`network_observation_source`,`network_observation_state_hash`),
  KEY `network_observation_asset_current` (`network_observation_asset_id`,`network_observation_active`,`network_observation_last_seen_at`),
  KEY `network_observation_client_current` (`network_observation_client_id`,`network_observation_active`),
  KEY `network_observation_identity` (`network_observation_asset_id`,`network_observation_source`,`network_observation_identity_hash`),
  KEY `network_observation_interface` (`network_observation_interface_id`),
  CONSTRAINT `asset_network_observations_asset_fk` FOREIGN KEY (`network_observation_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `asset_network_observations_interface_fk` FOREIGN KEY (`network_observation_interface_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_tags`
--

DROP TABLE IF EXISTS `asset_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_tags` (
  `asset_tag_asset_id` int(11) NOT NULL,
  `asset_tag_tag_id` int(11) NOT NULL,
  PRIMARY KEY (`asset_tag_asset_id`,`asset_tag_tag_id`),
  KEY `fk_tag` (`asset_tag_tag_id`),
  CONSTRAINT `fk_asset` FOREIGN KEY (`asset_tag_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tag` FOREIGN KEY (`asset_tag_tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `asset_id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_type` varchar(200) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `asset_description` varchar(255) DEFAULT NULL,
  `asset_make` varchar(200) NOT NULL,
  `asset_model` varchar(200) DEFAULT NULL,
  `asset_serial` varchar(200) DEFAULT NULL,
  `asset_os` varchar(200) DEFAULT NULL,
  `asset_uri` varchar(500) DEFAULT NULL,
  `asset_uri_2` varchar(500) DEFAULT NULL,
  `asset_uri_client` varchar(500) DEFAULT NULL,
  `asset_status` varchar(200) DEFAULT NULL,
  `asset_purchase_reference` varchar(200) DEFAULT NULL,
  `asset_purchase_date` date DEFAULT NULL,
  `asset_warranty_expire` date DEFAULT NULL,
  `asset_install_date` date DEFAULT NULL,
  `asset_photo` varchar(200) DEFAULT NULL,
  `asset_physical_location` varchar(200) DEFAULT NULL,
  `asset_notes` text DEFAULT NULL,
  `asset_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `asset_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `asset_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `asset_archived_at` datetime DEFAULT NULL,
  `asset_accessed_at` datetime DEFAULT NULL,
  `asset_vendor_id` int(11) NOT NULL DEFAULT 0,
  `asset_location_id` int(11) NOT NULL DEFAULT 0,
  `asset_contact_id` int(11) NOT NULL DEFAULT 0,
  `asset_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `auth_logs`
--

DROP TABLE IF EXISTS `auth_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_logs` (
  `auth_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_log_status` tinyint(1) NOT NULL,
  `auth_log_details` varchar(200) DEFAULT NULL,
  `auth_log_ip` varchar(200) DEFAULT NULL,
  `auth_log_user_agent` varchar(250) DEFAULT NULL,
  `auth_log_user_id` int(11) NOT NULL DEFAULT 0,
  `auth_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`auth_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_entity_mappings`
--

DROP TABLE IF EXISTS `automation_entity_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_entity_mappings` (
  `automation_mapping_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_mapping_source` varchar(40) NOT NULL,
  `automation_mapping_entity_type` varchar(40) NOT NULL,
  `automation_mapping_external_id` varchar(255) NOT NULL,
  `automation_mapping_external_parent_id` varchar(255) DEFAULT NULL,
  `automation_mapping_external_name` varchar(255) DEFAULT NULL,
  `automation_mapping_client_id` int(11) NOT NULL DEFAULT 0,
  `automation_mapping_location_id` int(11) NOT NULL DEFAULT 0,
  `automation_mapping_asset_id` int(11) NOT NULL DEFAULT 0,
  `automation_mapping_domain_id` int(11) NOT NULL DEFAULT 0,
  `automation_mapping_strategy` varchar(40) NOT NULL DEFAULT 'unresolved',
  `automation_mapping_state` varchar(20) NOT NULL DEFAULT 'unresolved',
  `automation_mapping_confidence` decimal(5,2) NOT NULL DEFAULT 0.00,
  `automation_mapping_metadata` longtext DEFAULT NULL,
  `automation_mapping_last_seen_at` datetime DEFAULT NULL,
  `automation_mapping_last_synced_at` datetime DEFAULT NULL,
  `automation_mapping_last_success_at` datetime DEFAULT NULL,
  `automation_mapping_last_error` text DEFAULT NULL,
  `automation_mapping_confirmed_at` datetime DEFAULT NULL,
  `automation_mapping_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_mapping_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `automation_mapping_deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`automation_mapping_id`),
  UNIQUE KEY `automation_mapping_source_entity_external` (`automation_mapping_source`,`automation_mapping_entity_type`,`automation_mapping_external_id`),
  KEY `automation_mapping_source_entity_state` (`automation_mapping_source`,`automation_mapping_entity_type`,`automation_mapping_state`),
  KEY `automation_mapping_state` (`automation_mapping_state`),
  KEY `automation_mapping_client` (`automation_mapping_client_id`),
  KEY `automation_mapping_location` (`automation_mapping_location_id`),
  KEY `automation_mapping_asset` (`automation_mapping_asset_id`),
  KEY `automation_mapping_domain` (`automation_mapping_domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_mapping_decisions`
--

DROP TABLE IF EXISTS `automation_mapping_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_mapping_decisions` (
  `automation_mapping_decision_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_mapping_decision_mapping_id` bigint(20) NOT NULL DEFAULT 0,
  `automation_mapping_decision_source` varchar(40) NOT NULL,
  `automation_mapping_decision_entity_type` varchar(40) NOT NULL,
  `automation_mapping_decision_external_id` varchar(255) NOT NULL,
  `automation_mapping_decision_action` varchar(40) NOT NULL,
  `automation_mapping_decision_before` longtext NOT NULL,
  `automation_mapping_decision_after` longtext NOT NULL,
  `automation_mapping_decision_reason` varchar(1000) DEFAULT NULL,
  `automation_mapping_decision_actor_user_id` int(11) NOT NULL DEFAULT 0,
  `automation_mapping_decision_batch_key` char(64) NOT NULL DEFAULT '',
  `automation_mapping_decision_occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`automation_mapping_decision_id`),
  KEY `automation_mapping_decision_mapping_time` (`automation_mapping_decision_mapping_id`,`automation_mapping_decision_occurred_at`),
  KEY `automation_mapping_decision_source_action` (`automation_mapping_decision_source`,`automation_mapping_decision_action`),
  KEY `automation_mapping_decision_actor` (`automation_mapping_decision_actor_user_id`,`automation_mapping_decision_occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_entity_snapshots`
--

DROP TABLE IF EXISTS `automation_entity_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_entity_snapshots` (
  `automation_snapshot_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_snapshot_source` varchar(40) NOT NULL,
  `automation_snapshot_entity_type` varchar(40) NOT NULL,
  `automation_snapshot_external_id` varchar(255) NOT NULL,
  `automation_snapshot_client_id` int(11) NOT NULL DEFAULT 0,
  `automation_snapshot_asset_id` int(11) NOT NULL DEFAULT 0,
  `automation_snapshot_payload_hash` char(64) NOT NULL,
  `automation_snapshot_payload` longtext NOT NULL,
  `automation_snapshot_observed_at` datetime NOT NULL,
  `automation_snapshot_first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_snapshot_last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`automation_snapshot_id`),
  UNIQUE KEY `automation_snapshot_source_entity_hash` (`automation_snapshot_source`,`automation_snapshot_entity_type`,`automation_snapshot_external_id`,`automation_snapshot_client_id`,`automation_snapshot_asset_id`,`automation_snapshot_payload_hash`),
  KEY `automation_snapshot_entity_observed` (`automation_snapshot_source`,`automation_snapshot_entity_type`,`automation_snapshot_external_id`,`automation_snapshot_observed_at`),
  KEY `automation_snapshot_client` (`automation_snapshot_client_id`),
  KEY `automation_snapshot_asset` (`automation_snapshot_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_events`
--

DROP TABLE IF EXISTS `automation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_events` (
  `automation_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_event_source` varchar(40) NOT NULL,
  `automation_event_external_id` varchar(255) NOT NULL,
  `automation_event_incident_key` varchar(255) NOT NULL,
  `automation_event_fingerprint` char(64) DEFAULT NULL,
  `automation_event_state` varchar(20) NOT NULL,
  `automation_event_action` varchar(40) NOT NULL,
  `automation_event_status` varchar(20) NOT NULL DEFAULT 'Processed',
  `automation_event_delivery_count` int(11) NOT NULL DEFAULT 1,
  `automation_event_process_attempts` int(11) NOT NULL DEFAULT 1,
  `automation_event_max_attempts` int(11) NOT NULL DEFAULT 5,
  `automation_event_processing_at` datetime DEFAULT NULL,
  `automation_event_next_attempt_at` datetime DEFAULT NULL,
  `automation_event_last_error` text DEFAULT NULL,
  `automation_event_suppressed_reason` varchar(80) DEFAULT NULL,
  `automation_event_maintenance_window_id` bigint(20) NOT NULL DEFAULT 0,
  `automation_event_ticket_id` int(11) NOT NULL DEFAULT 0,
  `automation_event_payload_hash` char(64) NOT NULL,
  `automation_event_payload` longtext DEFAULT NULL,
  `automation_event_occurred_at` datetime DEFAULT NULL,
  `automation_event_received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_event_last_received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_event_processed_at` datetime DEFAULT NULL,
  `automation_event_replay_count` int(11) NOT NULL DEFAULT 0,
  `automation_event_replayed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`automation_event_id`),
  UNIQUE KEY `automation_event_source_external` (`automation_event_source`,`automation_event_external_id`),
  KEY `automation_event_incident` (`automation_event_source`,`automation_event_incident_key`),
  KEY `automation_event_fingerprint` (`automation_event_source`,`automation_event_incident_key`,`automation_event_fingerprint`),
  KEY `automation_event_queue` (`automation_event_status`,`automation_event_next_attempt_at`,`automation_event_received_at`),
  KEY `automation_event_ticket` (`automation_event_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_incidents`
--

DROP TABLE IF EXISTS `automation_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_incidents` (
  `automation_incident_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_incident_source` varchar(40) NOT NULL,
  `automation_incident_key` varchar(255) NOT NULL,
  `automation_incident_title` varchar(500) NOT NULL,
  `automation_incident_status` varchar(20) NOT NULL DEFAULT 'Open',
  `automation_incident_severity` varchar(20) NOT NULL DEFAULT 'low',
  `automation_incident_ticket_id` int(11) NOT NULL DEFAULT 0,
  `automation_incident_client_id` int(11) NOT NULL DEFAULT 0,
  `automation_incident_location_id` int(11) NOT NULL DEFAULT 0,
  `automation_incident_asset_id` int(11) NOT NULL DEFAULT 0,
  `automation_incident_service_id` int(11) NOT NULL DEFAULT 0,
  `automation_incident_event_count` int(11) NOT NULL DEFAULT 0,
  `automation_incident_repeat_count` int(11) NOT NULL DEFAULT 0,
  `automation_incident_suppressed_count` int(11) NOT NULL DEFAULT 0,
  `automation_incident_last_event_hash` char(64) DEFAULT NULL,
  `automation_incident_last_action` varchar(40) DEFAULT NULL,
  `automation_incident_metadata` longtext DEFAULT NULL,
  `automation_incident_first_event_at` datetime DEFAULT NULL,
  `automation_incident_opened_at` datetime DEFAULT NULL,
  `automation_incident_last_event_at` datetime DEFAULT NULL,
  `automation_incident_resolved_at` datetime DEFAULT NULL,
  `automation_incident_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_incident_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`automation_incident_id`),
  UNIQUE KEY `automation_incident_source_key` (`automation_incident_source`,`automation_incident_key`),
  KEY `automation_incident_ticket` (`automation_incident_ticket_id`),
  KEY `automation_incident_service` (`automation_incident_service_id`),
  KEY `automation_incident_status_last_event` (`automation_incident_status`,`automation_incident_last_event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_event_policies`
--

DROP TABLE IF EXISTS `automation_event_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_event_policies` (
  `automation_policy_source` varchar(40) NOT NULL,
  `automation_policy_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `automation_policy_ticket_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `automation_policy_auto_resolve` tinyint(1) NOT NULL DEFAULT 1,
  `automation_policy_threshold_count` int(11) NOT NULL DEFAULT 1,
  `automation_policy_threshold_window_minutes` int(11) NOT NULL DEFAULT 0,
  `automation_policy_max_attempts` int(11) NOT NULL DEFAULT 5,
  `automation_policy_retry_delay_seconds` int(11) NOT NULL DEFAULT 60,
  `automation_policy_payload_retention_days` int(11) NOT NULL DEFAULT 30,
  `automation_policy_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_policy_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`automation_policy_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_maintenance_windows`
--

DROP TABLE IF EXISTS `automation_maintenance_windows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_maintenance_windows` (
  `automation_maintenance_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `automation_maintenance_name` varchar(255) NOT NULL,
  `automation_maintenance_source` varchar(40) NOT NULL DEFAULT '',
  `automation_maintenance_client_id` int(11) NOT NULL DEFAULT 0,
  `automation_maintenance_asset_id` int(11) NOT NULL DEFAULT 0,
  `automation_maintenance_service_id` int(11) NOT NULL DEFAULT 0,
  `automation_maintenance_starts_at` datetime NOT NULL,
  `automation_maintenance_ends_at` datetime NOT NULL,
  `automation_maintenance_reason` text DEFAULT NULL,
  `automation_maintenance_created_by` int(11) NOT NULL DEFAULT 0,
  `automation_maintenance_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `automation_maintenance_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `automation_maintenance_deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`automation_maintenance_id`),
  KEY `automation_maintenance_active` (`automation_maintenance_starts_at`,`automation_maintenance_ends_at`,`automation_maintenance_deleted_at`),
  KEY `automation_maintenance_client` (`automation_maintenance_client_id`),
  KEY `automation_maintenance_asset` (`automation_maintenance_asset_id`),
  KEY `automation_maintenance_service` (`automation_maintenance_service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `backups`
--

DROP TABLE IF EXISTS `backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backups` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_type` varchar(20) NOT NULL DEFAULT 'full',
  `backup_file_name` varchar(255) NOT NULL,
  `backup_size` bigint(20) NOT NULL DEFAULT 0,
  `backup_sha256` varchar(64) DEFAULT NULL,
  `backup_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `backup_error` text DEFAULT NULL,
  `backup_source` varchar(20) NOT NULL DEFAULT 'Manual',
  `backup_created_by` varchar(200) DEFAULT NULL,
  `backup_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `backup_completed_at` datetime DEFAULT NULL,
  `backup_downloaded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `backup_status_created` (`backup_status`,`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `budget`
--

DROP TABLE IF EXISTS `budget`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `budget` (
  `budget_id` int(11) NOT NULL AUTO_INCREMENT,
  `budget_month` tinyint(4) NOT NULL,
  `budget_year` int(11) NOT NULL,
  `budget_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `budget_description` varchar(255) DEFAULT NULL,
  `budget_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `budget_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `budget_category_id` int(11) NOT NULL,
  PRIMARY KEY (`budget_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_event_attendees`
--

DROP TABLE IF EXISTS `calendar_event_attendees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_attendees` (
  `attendee_id` int(11) NOT NULL AUTO_INCREMENT,
  `attendee_name` varchar(200) DEFAULT NULL,
  `attendee_email` varchar(200) DEFAULT NULL,
  `attendee_invitation_status` tinyint(1) NOT NULL DEFAULT 0,
  `attendee_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `attendee_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `attendee_archived_at` datetime DEFAULT NULL,
  `attendee_contact_id` int(11) NOT NULL DEFAULT 0,
  `attendee_event_id` int(11) NOT NULL,
  PRIMARY KEY (`attendee_id`),
  KEY `attendee_event_id` (`attendee_event_id`),
  CONSTRAINT `calendar_event_attendees_ibfk_1` FOREIGN KEY (`attendee_event_id`) REFERENCES `calendar_events` (`event_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendar_events`
--

DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_title` varchar(200) NOT NULL,
  `event_location` text DEFAULT NULL,
  `event_description` longtext DEFAULT NULL,
  `event_start` datetime NOT NULL,
  `event_end` datetime DEFAULT NULL,
  `event_all_day` tinyint(1) NOT NULL DEFAULT 0,
  `event_repeat` varchar(200) DEFAULT NULL,
  `event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `event_archived_at` datetime DEFAULT NULL,
  `event_client_id` int(11) NOT NULL DEFAULT 0,
  `event_location_id` int(11) NOT NULL DEFAULT 0,
  `event_calendar_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_id`),
  KEY `event_calendar_id` (`event_calendar_id`),
  CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`event_calendar_id`) REFERENCES `calendars` (`calendar_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `calendars`
--

DROP TABLE IF EXISTS `calendars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendars` (
  `calendar_id` int(11) NOT NULL AUTO_INCREMENT,
  `calendar_name` varchar(200) NOT NULL,
  `calendar_color` varchar(200) NOT NULL,
  `calendar_feed_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `calendar_feed_busy_only` tinyint(1) NOT NULL DEFAULT 0,
  `calendar_feed_created_at` datetime DEFAULT NULL,
  `calendar_feed_accessed_at` datetime DEFAULT NULL,
  `calendar_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `calendar_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `calendar_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`calendar_id`),
  UNIQUE KEY `calendar_feed_key` (`calendar_feed_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(200) NOT NULL,
  `category_description` varchar(255) DEFAULT NULL,
  `category_type` varchar(200) NOT NULL,
  `category_color` varchar(200) DEFAULT NULL,
  `category_icon` varchar(200) DEFAULT NULL,
  `category_order` int(11) NOT NULL DEFAULT 0,
  `category_parent` int(11) DEFAULT 0,
  `category_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `category_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `category_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificate_history`
--

DROP TABLE IF EXISTS `certificate_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificate_history` (
  `certificate_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_history_column` varchar(200) NOT NULL,
  `certificate_history_old_value` text NOT NULL,
  `certificate_history_new_value` text NOT NULL,
  `certificate_history_certificate_id` int(11) NOT NULL,
  `certificate_history_modified_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`certificate_history_id`),
  KEY `certificate_history_certificate_id` (`certificate_history_certificate_id`),
  CONSTRAINT `certificate_history_ibfk_1` FOREIGN KEY (`certificate_history_certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `certificates`
--

DROP TABLE IF EXISTS `certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificates` (
  `certificate_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_name` varchar(200) NOT NULL,
  `certificate_description` mediumtext DEFAULT NULL,
  `certificate_domain` varchar(200) DEFAULT NULL,
  `certificate_issued_by` varchar(200) NOT NULL,
  `certificate_expire` date DEFAULT NULL,
  `certificate_public_key` mediumtext DEFAULT NULL,
  `certificate_notes` mediumtext DEFAULT NULL,
  `certificate_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `certificate_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `certificate_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `certificate_archived_at` datetime DEFAULT NULL,
  `certificate_accessed_at` datetime DEFAULT NULL,
  `certificate_domain_id` int(11) NOT NULL DEFAULT 0,
  `certificate_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`certificate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_notes`
--

DROP TABLE IF EXISTS `client_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_notes` (
  `client_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_note_type` varchar(200) NOT NULL,
  `client_note` text DEFAULT NULL,
  `client_note_created_by` int(11) NOT NULL,
  `client_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `client_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `client_note_archived_at` datetime DEFAULT NULL,
  `client_note_client_id` int(11) NOT NULL,
  PRIMARY KEY (`client_note_id`),
  KEY `client_note_client_id` (`client_note_client_id`),
  CONSTRAINT `client_notes_ibfk_1` FOREIGN KEY (`client_note_client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_payment_provider`
--

DROP TABLE IF EXISTS `client_payment_provider`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_payment_provider` (
  `client_id` int(11) NOT NULL,
  `payment_provider_id` int(11) NOT NULL,
  `payment_provider_client` varchar(200) NOT NULL,
  `client_payment_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`,`payment_provider_id`),
  KEY `payment_provider_id` (`payment_provider_id`),
  CONSTRAINT `client_payment_provider_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_payment_provider_ibfk_2` FOREIGN KEY (`payment_provider_id`) REFERENCES `payment_providers` (`payment_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_saved_payment_methods`
--

DROP TABLE IF EXISTS `client_saved_payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_saved_payment_methods` (
  `saved_payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `saved_payment_provider_method` varchar(200) NOT NULL,
  `saved_payment_description` varchar(200) DEFAULT NULL,
  `saved_payment_client_id` int(11) NOT NULL,
  `saved_payment_provider_id` int(11) NOT NULL,
  `saved_payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `saved_payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`saved_payment_id`),
  KEY `saved_payment_client_id` (`saved_payment_client_id`),
  KEY `saved_payment_provider_id` (`saved_payment_provider_id`),
  CONSTRAINT `client_saved_payment_methods_ibfk_1` FOREIGN KEY (`saved_payment_client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_saved_payment_methods_ibfk_2` FOREIGN KEY (`saved_payment_provider_id`) REFERENCES `payment_providers` (`payment_provider_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_tags`
--

DROP TABLE IF EXISTS `client_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_tags` (
  `client_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`client_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `client_tags_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `client_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_lead` tinyint(1) NOT NULL DEFAULT 0,
  `client_name` varchar(200) NOT NULL,
  `client_type` varchar(200) DEFAULT NULL,
  `client_website` varchar(200) DEFAULT NULL,
  `client_referral` varchar(200) DEFAULT NULL,
  `client_rate` decimal(15,2) DEFAULT NULL,
  `client_currency_code` varchar(200) NOT NULL,
  `client_net_terms` int(10) NOT NULL,
  `client_tax_id_number` varchar(255) DEFAULT NULL,
  `client_abbreviation` varchar(10) DEFAULT NULL,
  `client_notes` text DEFAULT NULL,
  `client_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `client_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `client_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `client_archived_at` datetime DEFAULT NULL,
  `client_accessed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `company_id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(200) NOT NULL,
  `company_address` varchar(200) DEFAULT NULL,
  `company_city` varchar(200) DEFAULT NULL,
  `company_state` varchar(200) DEFAULT NULL,
  `company_zip` varchar(200) DEFAULT NULL,
  `company_country` varchar(200) DEFAULT NULL,
  `company_phone_country_code` varchar(10) DEFAULT NULL,
  `company_phone` varchar(200) DEFAULT NULL,
  `company_email` varchar(200) DEFAULT NULL,
  `company_website` varchar(200) DEFAULT NULL,
  `company_logo` varchar(250) DEFAULT NULL,
  `company_locale` varchar(200) DEFAULT NULL,
  `company_currency` varchar(200) DEFAULT 'USD',
  `company_tax_id` varchar(200) DEFAULT NULL,
  `company_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `company_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_assets`
--

DROP TABLE IF EXISTS `contact_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_assets` (
  `contact_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `contact_assets_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_3` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_assets_ibfk_4` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_credentials`
--

DROP TABLE IF EXISTS `contact_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_credentials` (
  `contact_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `contact_credentials_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_documents`
--

DROP TABLE IF EXISTS `contact_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_documents` (
  `contact_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `contact_documents_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_files`
--

DROP TABLE IF EXISTS `contact_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_files` (
  `contact_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `contact_files_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_notes`
--

DROP TABLE IF EXISTS `contact_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_notes` (
  `contact_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_note_type` varchar(200) NOT NULL,
  `contact_note` text DEFAULT NULL,
  `contact_note_created_by` int(11) NOT NULL,
  `contact_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `contact_note_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contact_note_archived_at` datetime DEFAULT NULL,
  `contact_note_contact_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_note_id`),
  KEY `contact_note_contact_id` (`contact_note_contact_id`),
  CONSTRAINT `contact_notes_ibfk_1` FOREIGN KEY (`contact_note_contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_tags`
--

DROP TABLE IF EXISTS `contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tags` (
  `contact_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `contact_tags_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contacts`
--

DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `contact_id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_name` varchar(200) NOT NULL,
  `contact_title` varchar(200) DEFAULT NULL,
  `contact_email` varchar(200) DEFAULT NULL,
  `contact_phone_country_code` varchar(10) DEFAULT NULL,
  `contact_phone` varchar(200) DEFAULT NULL,
  `contact_extension` varchar(200) DEFAULT NULL,
  `contact_mobile_country_code` varchar(10) DEFAULT NULL,
  `contact_mobile` varchar(200) DEFAULT NULL,
  `contact_photo` varchar(200) DEFAULT NULL,
  `contact_pin` varchar(255) DEFAULT NULL,
  `contact_notes` text DEFAULT NULL,
  `contact_primary` tinyint(1) NOT NULL DEFAULT 0,
  `contact_important` tinyint(1) NOT NULL DEFAULT 0,
  `contact_billing` tinyint(1) DEFAULT 0,
  `contact_technical` tinyint(1) DEFAULT 0,
  `contact_portal_ticket_scope` varchar(20) NOT NULL DEFAULT 'own',
  `contact_portal_asset_scope` varchar(20) NOT NULL DEFAULT 'assigned',
  `contact_portal_manage_contacts` tinyint(1) NOT NULL DEFAULT 0,
  `contact_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `contact_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contact_archived_at` datetime DEFAULT NULL,
  `contact_accessed_at` datetime DEFAULT NULL,
  `contact_location_id` int(11) NOT NULL DEFAULT 0,
  `contact_vendor_id` int(11) NOT NULL DEFAULT 0,
  `contact_user_id` int(11) NOT NULL DEFAULT 0,
  `contact_department` varchar(200) DEFAULT NULL,
  `contact_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contract_templates`
--

DROP TABLE IF EXISTS `contract_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_templates` (
  `contract_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_template_name` varchar(255) NOT NULL,
  `contract_template_description` text DEFAULT NULL,
  `contract_template_type` varchar(50) DEFAULT NULL,
  `contract_template_sla_low_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_low_resolution_time` int(11) DEFAULT NULL,
  `contract_template_sla_medium_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_medium_resolution_time` int(11) DEFAULT NULL,
  `contract_template_sla_high_response_time` int(11) DEFAULT NULL,
  `contract_template_sla_high_resolution_time` int(11) DEFAULT NULL,
  `contract_template_rate_standard` decimal(10,2) DEFAULT NULL,
  `contract_template_rate_after_hours` decimal(10,2) DEFAULT NULL,
  `contract_template_net_terms` varchar(50) DEFAULT NULL,
  `contract_template_support_hours` varchar(100) DEFAULT NULL,
  `contract_template_renewal_frequency` varchar(50) DEFAULT NULL,
  `contract_template_details` text DEFAULT NULL,
  `contract_template_created_at` datetime DEFAULT current_timestamp(),
  `contract_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contract_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`contract_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contracts`
--

DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contracts` (
  `contract_id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_name` varchar(255) NOT NULL,
  `contract_status` varchar(50) NOT NULL,
  `contract_type` varchar(50) NOT NULL,
  `contract_sla_low_response_time` int(11) DEFAULT NULL,
  `contract_sla_low_resolution_time` int(11) DEFAULT NULL,
  `contract_sla_medium_response_time` int(11) DEFAULT NULL,
  `contract_sla_medium_resolution_time` int(11) DEFAULT NULL,
  `contract_sla_high_response_time` int(11) DEFAULT NULL,
  `contract_sla_high_resolution_time` int(11) DEFAULT NULL,
  `contract_details` text DEFAULT NULL,
  `contract_client_id` int(11) DEFAULT NULL,
  `contract_client_name` varchar(255) DEFAULT NULL,
  `contract_client_address` text DEFAULT NULL,
  `contract_client_email` varchar(255) DEFAULT NULL,
  `contract_client_phone` varchar(100) DEFAULT NULL,
  `contract_contact_name` varchar(255) DEFAULT NULL,
  `contract_contact_signature` text DEFAULT NULL,
  `contract_contact_signature_date` datetime DEFAULT NULL,
  `contract_agent_name` varchar(255) DEFAULT NULL,
  `contract_agent_signature` text DEFAULT NULL,
  `contract_agent_signature_date` datetime DEFAULT NULL,
  `contract_rate_standard` decimal(10,2) DEFAULT NULL,
  `contract_rate_after_hours` decimal(10,2) DEFAULT NULL,
  `contract_net_terms` varchar(50) DEFAULT NULL,
  `contract_support_hours` varchar(100) DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `contract_renewal_frequency` varchar(50) DEFAULT NULL,
  `contract_published_version_id` bigint(20) NOT NULL DEFAULT 0,
  `contract_review_cadence_months` int(11) NOT NULL DEFAULT 3,
  `contract_next_review_at` date DEFAULT NULL,
  `contract_created_at` datetime DEFAULT current_timestamp(),
  `contract_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `contract_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`contract_id`),
  KEY `contract_client_id` (`contract_client_id`),
  KEY `contract_published_version` (`contract_published_version_id`),
  KEY `contract_review_due` (`contract_status`,`contract_next_review_at`),
  CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`contract_client_id`) REFERENCES `clients` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agreement_versions`
--

DROP TABLE IF EXISTS `agreement_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_versions` (
  `agreement_version_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `agreement_version_contract_id` int(11) NOT NULL,
  `agreement_version_number` int(11) NOT NULL,
  `agreement_version_status` varchar(20) NOT NULL DEFAULT 'Draft',
  `agreement_version_name` varchar(255) NOT NULL,
  `agreement_version_type` varchar(50) NOT NULL,
  `agreement_version_effective_from` date DEFAULT NULL,
  `agreement_version_effective_until` date DEFAULT NULL,
  `agreement_version_support_hours` varchar(100) DEFAULT NULL,
  `agreement_version_review_cadence_months` int(11) NOT NULL DEFAULT 3,
  `agreement_version_renewal_notice_days` int(11) NOT NULL DEFAULT 90,
  `agreement_version_details` text DEFAULT NULL,
  `agreement_version_definition_hash` char(64) DEFAULT NULL,
  `agreement_version_created_by` int(11) NOT NULL DEFAULT 0,
  `agreement_version_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `agreement_version_published_by` int(11) NOT NULL DEFAULT 0,
  `agreement_version_published_at` datetime DEFAULT NULL,
  `agreement_version_superseded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`agreement_version_id`),
  UNIQUE KEY `agreement_version_number` (`agreement_version_contract_id`,`agreement_version_number`),
  KEY `agreement_version_contract_status` (`agreement_version_contract_id`,`agreement_version_status`),
  KEY `agreement_version_effective` (`agreement_version_effective_from`,`agreement_version_effective_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agreement_entitlements`
--

DROP TABLE IF EXISTS `agreement_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_entitlements` (
  `agreement_entitlement_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `agreement_entitlement_version_id` bigint(20) NOT NULL,
  `agreement_entitlement_scope_type` varchar(20) NOT NULL,
  `agreement_entitlement_scope_id` int(11) NOT NULL DEFAULT 0,
  `agreement_entitlement_scope_key` varchar(100) NOT NULL DEFAULT '*',
  `agreement_entitlement_scope_label` varchar(255) NOT NULL,
  `agreement_entitlement_quantity_limit` decimal(12,2) DEFAULT NULL,
  `agreement_entitlement_classification` varchar(20) NOT NULL,
  `agreement_entitlement_notes` text DEFAULT NULL,
  `agreement_entitlement_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`agreement_entitlement_id`),
  UNIQUE KEY `agreement_entitlement_scope` (`agreement_entitlement_version_id`,`agreement_entitlement_scope_type`,`agreement_entitlement_scope_id`,`agreement_entitlement_scope_key`,`agreement_entitlement_classification`),
  KEY `agreement_entitlement_version_class` (`agreement_entitlement_version_id`,`agreement_entitlement_classification`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agreement_sla_rules`
--

DROP TABLE IF EXISTS `agreement_sla_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_sla_rules` (
  `agreement_sla_rule_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `agreement_sla_rule_version_id` bigint(20) NOT NULL,
  `agreement_sla_rule_request_type_key` varchar(100) NOT NULL DEFAULT '*',
  `agreement_sla_rule_priority` varchar(20) NOT NULL DEFAULT '*',
  `agreement_sla_rule_sla_id` int(11) NOT NULL DEFAULT 0,
  `agreement_sla_rule_sla_name` varchar(200) NOT NULL DEFAULT 'None',
  `agreement_sla_rule_response_minutes` int(11) DEFAULT NULL,
  `agreement_sla_rule_resolution_minutes` int(11) DEFAULT NULL,
  `agreement_sla_rule_calendar_mode` varchar(20) NOT NULL DEFAULT 'none',
  `agreement_sla_rule_business_days` varchar(20) DEFAULT NULL,
  `agreement_sla_rule_business_hours_start` time DEFAULT NULL,
  `agreement_sla_rule_business_hours_end` time DEFAULT NULL,
  `agreement_sla_rule_timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `agreement_sla_rule_classification` varchar(20) NOT NULL DEFAULT 'included',
  `agreement_sla_rule_classification_basis` varchar(30) NOT NULL DEFAULT 'explicit_rule',
  `agreement_sla_rule_behavior_version` int(11) NOT NULL DEFAULT 1,
  `agreement_sla_rule_sla_eligible` tinyint(1) NOT NULL DEFAULT 1,
  `agreement_sla_rule_ticket_onsite` tinyint(1) NOT NULL DEFAULT 0,
  `agreement_sla_rule_ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `agreement_sla_rule_order` int(11) NOT NULL DEFAULT 0,
  `agreement_sla_rule_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`agreement_sla_rule_id`),
  UNIQUE KEY `agreement_sla_rule_match` (`agreement_sla_rule_version_id`,`agreement_sla_rule_request_type_key`,`agreement_sla_rule_priority`),
  KEY `agreement_sla_rule_version_order` (`agreement_sla_rule_version_id`,`agreement_sla_rule_order`,`agreement_sla_rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `agreement_version_events`
--

DROP TABLE IF EXISTS `agreement_version_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agreement_version_events` (
  `agreement_version_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `agreement_version_event_contract_id` int(11) NOT NULL,
  `agreement_version_event_version_id` bigint(20) NOT NULL,
  `agreement_version_event_action` varchar(30) NOT NULL,
  `agreement_version_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `agreement_version_event_reason` varchar(255) DEFAULT NULL,
  `agreement_version_event_definition_hash` char(64) DEFAULT NULL,
  `agreement_version_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`agreement_version_event_id`),
  KEY `agreement_version_event_version` (`agreement_version_event_version_id`,`agreement_version_event_id`),
  KEY `agreement_version_event_contract` (`agreement_version_event_contract_id`,`agreement_version_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_agreement_decisions`
--

DROP TABLE IF EXISTS `ticket_agreement_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_agreement_decisions` (
  `ticket_agreement_decision_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_agreement_decision_schema_version` int(11) NOT NULL DEFAULT 1,
  `ticket_agreement_decision_ticket_id` int(11) NOT NULL,
  `ticket_agreement_decision_client_id` int(11) NOT NULL,
  `ticket_agreement_decision_contract_id` int(11) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_version_id` bigint(20) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_rule_id` bigint(20) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_request_type_key` varchar(100) NOT NULL DEFAULT '*',
  `ticket_agreement_decision_priority` varchar(20) NOT NULL,
  `ticket_agreement_decision_sla_id` int(11) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_sla_name` varchar(200) NOT NULL DEFAULT 'None',
  `ticket_agreement_decision_response_minutes` int(11) DEFAULT NULL,
  `ticket_agreement_decision_resolution_minutes` int(11) DEFAULT NULL,
  `ticket_agreement_decision_calendar_mode` varchar(20) NOT NULL DEFAULT 'none',
  `ticket_agreement_decision_business_days` varchar(20) DEFAULT NULL,
  `ticket_agreement_decision_business_hours_start` time DEFAULT NULL,
  `ticket_agreement_decision_business_hours_end` time DEFAULT NULL,
  `ticket_agreement_decision_timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `ticket_agreement_decision_classification` varchar(20) DEFAULT NULL,
  `ticket_agreement_decision_classification_basis` varchar(30) DEFAULT NULL,
  `ticket_agreement_decision_behavior_version` int(11) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_sla_eligible` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_ticket_onsite` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_agreement_decision_entitlement_snapshot` longtext NOT NULL,
  `ticket_agreement_decision_source` varchar(30) NOT NULL,
  `ticket_agreement_decision_reason` varchar(500) NOT NULL,
  `ticket_agreement_decision_hash` char(64) NOT NULL,
  `ticket_agreement_decision_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_agreement_decision_id`),
  KEY `ticket_agreement_decision_hash` (`ticket_agreement_decision_ticket_id`,`ticket_agreement_decision_hash`),
  KEY `ticket_agreement_decision_ticket` (`ticket_agreement_decision_ticket_id`,`ticket_agreement_decision_id`),
  KEY `ticket_agreement_decision_client` (`ticket_agreement_decision_client_id`,`ticket_agreement_decision_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_reviews`
--

DROP TABLE IF EXISTS `service_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_reviews` (
  `service_review_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `service_review_client_id` int(11) NOT NULL,
  `service_review_contract_id` int(11) NOT NULL DEFAULT 0,
  `service_review_agreement_version_id` bigint(20) NOT NULL DEFAULT 0,
  `service_review_period_start` date NOT NULL,
  `service_review_period_end` date NOT NULL,
  `service_review_status` varchar(20) NOT NULL DEFAULT 'Draft',
  `service_review_source_snapshot` longtext NOT NULL,
  `service_review_summary` text NOT NULL,
  `service_review_recommendations` longtext NOT NULL,
  `service_review_snapshot_hash` char(64) NOT NULL,
  `service_review_generated_by` int(11) NOT NULL DEFAULT 0,
  `service_review_generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `service_review_published_by` int(11) NOT NULL DEFAULT 0,
  `service_review_published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`service_review_id`),
  UNIQUE KEY `service_review_snapshot_once` (`service_review_client_id`,`service_review_period_start`,`service_review_period_end`,`service_review_snapshot_hash`),
  KEY `service_review_client_period` (`service_review_client_id`,`service_review_period_end`,`service_review_id`),
  KEY `service_review_contract` (`service_review_contract_id`,`service_review_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_review_events`
--

DROP TABLE IF EXISTS `service_review_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_review_events` (
  `service_review_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `service_review_event_review_id` bigint(20) NOT NULL,
  `service_review_event_client_id` int(11) NOT NULL,
  `service_review_event_action` varchar(30) NOT NULL,
  `service_review_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `service_review_event_reason` varchar(255) DEFAULT NULL,
  `service_review_event_snapshot_hash` char(64) NOT NULL,
  `service_review_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`service_review_event_id`),
  KEY `service_review_event_review` (`service_review_event_review_id`,`service_review_event_id`),
  KEY `service_review_event_client` (`service_review_event_client_id`,`service_review_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credential_tags`
--

DROP TABLE IF EXISTS `credential_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credential_tags` (
  `credential_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`credential_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `credential_tags_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE,
  CONSTRAINT `credential_tags_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credentials`
--

DROP TABLE IF EXISTS `credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credentials` (
  `credential_id` int(11) NOT NULL AUTO_INCREMENT,
  `credential_name` varchar(200) NOT NULL,
  `credential_description` varchar(500) DEFAULT NULL,
  `credential_category` varchar(200) DEFAULT NULL,
  `credential_uri` varchar(500) DEFAULT NULL,
  `credential_uri_2` varchar(500) DEFAULT NULL,
  `credential_username` varchar(500) DEFAULT NULL,
  `credential_password` varchar(500) DEFAULT NULL,
  `credential_otp_secret` varchar(200) DEFAULT NULL,
  `credential_note` text DEFAULT NULL,
  `credential_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `credential_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `credential_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `credential_archived_at` datetime DEFAULT NULL,
  `credential_accessed_at` datetime DEFAULT NULL,
  `credential_password_changed_at` datetime DEFAULT current_timestamp(),
  `credential_folder_id` int(11) NOT NULL DEFAULT 0,
  `credential_contact_id` int(11) NOT NULL DEFAULT 0,
  `credential_asset_id` int(11) NOT NULL DEFAULT 0,
  `credential_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credits`
--

DROP TABLE IF EXISTS `credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `credits` (
  `credit_id` int(11) NOT NULL AUTO_INCREMENT,
  `credit_amount` decimal(15,2) NOT NULL,
  `credit_type` enum('prepaid','manual','refund','promotion','usage') NOT NULL DEFAULT 'manual',
  `credit_note` text DEFAULT NULL,
  `credit_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `credit_created_by` int(11) NOT NULL,
  `credit_expire_at` date DEFAULT NULL,
  `credit_invoice_id` int(11) DEFAULT NULL,
  `credit_client_id` int(11) NOT NULL,
  PRIMARY KEY (`credit_id`),
  KEY `credit_client_id` (`credit_client_id`),
  KEY `credit_invoice_id` (`credit_invoice_id`),
  KEY `credit_created_at` (`credit_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cron_jobs`
--

DROP TABLE IF EXISTS `cron_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_jobs` (
  `cron_job_id` int(11) NOT NULL AUTO_INCREMENT,
  `cron_job_name` varchar(200) NOT NULL,
  `cron_job_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `cron_job_schedule` varchar(200) NOT NULL DEFAULT 'Interval',
  `cron_job_interval_minutes` int(11) NOT NULL DEFAULT 1,
  `cron_job_daily_at` time DEFAULT NULL,
  `cron_job_run_now` tinyint(1) NOT NULL DEFAULT 0,
  `cron_job_last_run_at` datetime DEFAULT NULL,
  `cron_job_last_finished_at` datetime DEFAULT NULL,
  `cron_job_last_duration` decimal(10,2) DEFAULT NULL,
  `cron_job_last_status` varchar(200) DEFAULT NULL,
  `cron_job_last_error` text DEFAULT NULL,
  `cron_job_last_error_at` datetime DEFAULT NULL,
  PRIMARY KEY (`cron_job_id`),
  UNIQUE KEY `cron_job_name` (`cron_job_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_fields`
--

DROP TABLE IF EXISTS `custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_fields` (
  `custom_field_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_field_table` varchar(255) NOT NULL,
  `custom_field_label` varchar(255) NOT NULL,
  `custom_field_type` varchar(255) NOT NULL DEFAULT 'text',
  `custom_field_location` int(11) NOT NULL DEFAULT 0,
  `custom_field_order` int(11) NOT NULL DEFAULT 999,
  PRIMARY KEY (`custom_field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_links`
--

DROP TABLE IF EXISTS `custom_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_links` (
  `custom_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_link_name` varchar(200) NOT NULL,
  `custom_link_description` text DEFAULT NULL,
  `custom_link_uri` varchar(500) NOT NULL,
  `custom_link_new_tab` tinyint(1) NOT NULL DEFAULT 0,
  `custom_link_icon` varchar(200) DEFAULT NULL,
  `custom_link_location` int(11) NOT NULL DEFAULT 1,
  `custom_link_order` int(11) NOT NULL DEFAULT 0,
  `custom_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `custom_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `custom_link_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`custom_link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `custom_values`
--

DROP TABLE IF EXISTS `custom_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_values` (
  `custom_value_id` int(11) NOT NULL AUTO_INCREMENT,
  `custom_value_value` mediumtext NOT NULL,
  `custom_value_field` int(11) NOT NULL,
  PRIMARY KEY (`custom_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `discount_codes`
--

DROP TABLE IF EXISTS `discount_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `discount_codes` (
  `discount_code_id` int(11) NOT NULL AUTO_INCREMENT,
  `discount_code_description` varchar(250) DEFAULT NULL,
  `discount_code_amount` decimal(15,2) NOT NULL,
  `discount_code` varchar(200) NOT NULL,
  `discount_code_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discount_code_created_by` int(11) NOT NULL,
  `discount_code_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `discount_code_archived_at` datetime DEFAULT NULL,
  `discount_code_expire_at` date DEFAULT NULL,
  PRIMARY KEY (`discount_code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Documentation requirements keep a mutable draft and one authoritative
-- published pointer. Published versions and selectors are immutable.

DROP TABLE IF EXISTS `documentation_requirements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_requirements` (
  `documentation_requirement_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_requirement_key` varchar(100) NOT NULL,
  `documentation_requirement_draft_definition` longtext NOT NULL,
  `documentation_requirement_published_version_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_requirement_lifecycle` varchar(20) NOT NULL DEFAULT 'Draft',
  `documentation_requirement_revision` int(11) NOT NULL DEFAULT 1,
  `documentation_requirement_created_by` int(11) NOT NULL DEFAULT 0,
  `documentation_requirement_updated_by` int(11) NOT NULL DEFAULT 0,
  `documentation_requirement_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `documentation_requirement_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `documentation_requirement_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`documentation_requirement_id`),
  UNIQUE KEY `documentation_requirement_key` (`documentation_requirement_key`),
  KEY `documentation_requirement_published` (`documentation_requirement_published_version_id`,`documentation_requirement_lifecycle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_requirement_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_requirement_versions` (
  `documentation_requirement_version_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_requirement_version_requirement_id` bigint(20) NOT NULL,
  `documentation_requirement_version_number` int(11) NOT NULL,
  `documentation_requirement_version_definition_hash` char(64) NOT NULL,
  `documentation_requirement_version_key` varchar(100) NOT NULL,
  `documentation_requirement_version_name` varchar(200) NOT NULL,
  `documentation_requirement_version_description` text DEFAULT NULL,
  `documentation_requirement_version_record_type` varchar(40) NOT NULL,
  `documentation_requirement_version_default_owner_role` varchar(40) NOT NULL DEFAULT 'documentation_owner',
  `documentation_requirement_version_default_owner_user_id` int(11) NOT NULL DEFAULT 0,
  `documentation_requirement_version_default_reviewer_role` varchar(40) NOT NULL DEFAULT 'support_lead',
  `documentation_requirement_version_default_reviewer_user_id` int(11) NOT NULL DEFAULT 0,
  `documentation_requirement_version_review_cadence_days` int(11) NOT NULL,
  `documentation_requirement_version_warning_window_days` int(11) NOT NULL DEFAULT 30,
  `documentation_requirement_version_blocks_readiness` tinyint(1) NOT NULL DEFAULT 1,
  `documentation_requirement_version_blocks_ticket_resolution` tinyint(1) NOT NULL DEFAULT 1,
  `documentation_requirement_version_evidence_policy` varchar(40) NOT NULL DEFAULT 'reference',
  `documentation_requirement_version_exception_approval_policy` varchar(40) NOT NULL DEFAULT 'support3',
  `documentation_requirement_version_applicability_mode` varchar(10) NOT NULL DEFAULT 'any',
  `documentation_requirement_version_created_by` int(11) NOT NULL DEFAULT 0,
  `documentation_requirement_version_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_requirement_version_id`),
  UNIQUE KEY `documentation_requirement_version_number` (`documentation_requirement_version_requirement_id`,`documentation_requirement_version_number`),
  UNIQUE KEY `documentation_requirement_version_hash` (`documentation_requirement_version_requirement_id`,`documentation_requirement_version_definition_hash`),
  KEY `documentation_requirement_version_key` (`documentation_requirement_version_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_requirement_version_selectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_requirement_version_selectors` (
  `documentation_selector_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_selector_requirement_version_id` bigint(20) NOT NULL,
  `documentation_selector_dimension` varchar(40) NOT NULL,
  `documentation_selector_value` varchar(100) NOT NULL,
  `documentation_selector_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`documentation_selector_id`),
  UNIQUE KEY `documentation_selector_identity` (`documentation_selector_requirement_version_id`,`documentation_selector_dimension`,`documentation_selector_value`),
  KEY `documentation_selector_lookup` (`documentation_selector_dimension`,`documentation_selector_value`,`documentation_selector_requirement_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `client_documentation_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_documentation_obligations` (
  `documentation_obligation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_obligation_client_id` int(11) NOT NULL,
  `documentation_obligation_requirement_id` bigint(20) NOT NULL,
  `documentation_obligation_requirement_version_id` bigint(20) NOT NULL,
  `documentation_obligation_document_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_applicable` tinyint(1) NOT NULL DEFAULT 1,
  `documentation_obligation_base_status` varchar(20) NOT NULL DEFAULT 'Missing',
  `documentation_obligation_owner_role` varchar(40) NOT NULL DEFAULT 'documentation_owner',
  `documentation_obligation_owner_user_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_reviewer_role` varchar(40) NOT NULL DEFAULT 'support_lead',
  `documentation_obligation_reviewer_user_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_last_verified_at` datetime DEFAULT NULL,
  `documentation_obligation_next_review_at` datetime DEFAULT NULL,
  `documentation_obligation_stale_at` datetime DEFAULT NULL,
  `documentation_obligation_verification_source` varchar(40) DEFAULT NULL,
  `documentation_obligation_verification_evidence_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_obligation_verification_document_version_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_verification_document_hash` char(64) DEFAULT NULL,
  `documentation_obligation_verification_ticket_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_evaluation_reason_code` varchar(60) NOT NULL DEFAULT 'not_evaluated',
  `documentation_obligation_evaluated_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_obligation_exception_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_exception_reason_redacted` varchar(255) DEFAULT NULL,
  `documentation_obligation_exception_reason_hash` char(64) DEFAULT NULL,
  `documentation_obligation_exception_requested_by` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_exception_requested_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_decided_by` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_exception_decided_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_expires_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_expired_event_at` datetime DEFAULT NULL,
  `documentation_obligation_revision` int(11) NOT NULL DEFAULT 1,
  `documentation_obligation_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `documentation_obligation_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`documentation_obligation_id`),
  UNIQUE KEY `documentation_obligation_client_requirement` (`documentation_obligation_client_id`,`documentation_obligation_requirement_id`),
  KEY `documentation_obligation_client_queue` (`documentation_obligation_client_id`,`documentation_obligation_applicable`,`documentation_obligation_base_status`),
  KEY `documentation_obligation_requirement_version` (`documentation_obligation_requirement_version_id`),
  KEY `documentation_obligation_document` (`documentation_obligation_document_id`),
  KEY `documentation_obligation_owner_queue` (`documentation_obligation_owner_user_id`,`documentation_obligation_applicable`,`documentation_obligation_base_status`),
  KEY `documentation_obligation_reviewer_queue` (`documentation_obligation_reviewer_user_id`,`documentation_obligation_applicable`,`documentation_obligation_base_status`),
  KEY `documentation_obligation_review_queue` (`documentation_obligation_applicable`,`documentation_obligation_next_review_at`,`documentation_obligation_stale_at`),
  KEY `documentation_obligation_exception_queue` (`documentation_obligation_exception_status`,`documentation_obligation_exception_expires_at`),
  KEY `documentation_obligation_exception_pointer` (`documentation_obligation_exception_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_obligation_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_obligation_events` (
  `documentation_obligation_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_obligation_event_obligation_id` bigint(20) NOT NULL,
  `documentation_obligation_event_client_id` int(11) NOT NULL,
  `documentation_obligation_event_requirement_version_id` bigint(20) NOT NULL,
  `documentation_obligation_event_action` varchar(40) NOT NULL,
  `documentation_obligation_event_from_base_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_event_to_base_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_event_from_effective_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_event_to_effective_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `documentation_obligation_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_event_reason_code` varchar(60) NOT NULL,
  `documentation_obligation_event_source_type` varchar(40) DEFAULT NULL,
  `documentation_obligation_event_source_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_obligation_event_context_hash` char(64) DEFAULT NULL,
  `documentation_obligation_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_obligation_event_id`),
  KEY `documentation_obligation_event_history` (`documentation_obligation_event_obligation_id`,`documentation_obligation_event_created_at`,`documentation_obligation_event_id`),
  KEY `documentation_obligation_event_client` (`documentation_obligation_event_client_id`,`documentation_obligation_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_obligation_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_obligation_exceptions` (
  `documentation_obligation_exception_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_obligation_exception_client_id` int(11) NOT NULL,
  `documentation_obligation_exception_obligation_id` bigint(20) NOT NULL,
  `documentation_obligation_exception_requirement_version_id` bigint(20) NOT NULL,
  `documentation_obligation_exception_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `documentation_obligation_exception_reason_redacted` varchar(255) NOT NULL,
  `documentation_obligation_exception_reason_hash` char(64) NOT NULL,
  `documentation_obligation_exception_requested_by` int(11) NOT NULL,
  `documentation_obligation_exception_requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `documentation_obligation_exception_decided_by` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_exception_decided_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_expires_at` datetime NOT NULL,
  `documentation_obligation_exception_expired_at` datetime DEFAULT NULL,
  `documentation_obligation_exception_revision` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`documentation_obligation_exception_id`),
  KEY `documentation_obligation_exception_history` (`documentation_obligation_exception_obligation_id`,`documentation_obligation_exception_id`),
  KEY `documentation_obligation_exception_queue` (`documentation_obligation_exception_status`,`documentation_obligation_exception_expires_at`),
  KEY `documentation_obligation_exception_client` (`documentation_obligation_exception_client_id`,`documentation_obligation_exception_status`,`documentation_obligation_exception_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_obligation_exception_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_obligation_exception_events` (
  `documentation_obligation_exception_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_obligation_exception_event_exception_id` bigint(20) NOT NULL,
  `documentation_obligation_exception_event_obligation_id` bigint(20) NOT NULL,
  `documentation_obligation_exception_event_client_id` int(11) NOT NULL,
  `documentation_obligation_exception_event_requirement_version_id` bigint(20) NOT NULL,
  `documentation_obligation_exception_event_action` varchar(30) NOT NULL,
  `documentation_obligation_exception_event_from_status` varchar(20) DEFAULT NULL,
  `documentation_obligation_exception_event_to_status` varchar(20) NOT NULL,
  `documentation_obligation_exception_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `documentation_obligation_exception_event_reason_code` varchar(60) NOT NULL,
  `documentation_obligation_exception_event_context_hash` char(64) DEFAULT NULL,
  `documentation_obligation_exception_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_obligation_exception_event_id`),
  KEY `documentation_obligation_exception_event_history` (`documentation_obligation_exception_event_exception_id`,`documentation_obligation_exception_event_created_at`,`documentation_obligation_exception_event_id`),
  KEY `documentation_obligation_exception_event_obligation` (`documentation_obligation_exception_event_obligation_id`,`documentation_obligation_exception_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_evidence_locker`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_evidence_locker` (
  `documentation_evidence_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_evidence_client_id` int(11) NOT NULL,
  `documentation_evidence_obligation_id` bigint(20) NOT NULL,
  `documentation_evidence_requirement_version_id` bigint(20) NOT NULL,
  `documentation_evidence_type` varchar(40) NOT NULL,
  `documentation_evidence_reference_type` varchar(40) NOT NULL,
  `documentation_evidence_reference_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_evidence_reference_hash` char(64) NOT NULL,
  `documentation_evidence_policy_result` varchar(20) NOT NULL DEFAULT 'accepted',
  `documentation_evidence_source_ticket_id` int(11) NOT NULL DEFAULT 0,
  `documentation_evidence_recorded_by` int(11) NOT NULL DEFAULT 0,
  `documentation_evidence_recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_evidence_id`),
  KEY `documentation_evidence_reference` (`documentation_evidence_obligation_id`,`documentation_evidence_requirement_version_id`,`documentation_evidence_reference_type`,`documentation_evidence_reference_id`,`documentation_evidence_reference_hash`),
  KEY `documentation_evidence_client` (`documentation_evidence_client_id`,`documentation_evidence_recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_change_passports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_change_passports` (
  `documentation_change_passport_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_change_passport_client_id` int(11) NOT NULL,
  `documentation_change_passport_ticket_id` int(11) NOT NULL,
  `documentation_change_passport_resolution_sequence` int(11) NOT NULL,
  `documentation_change_passport_ticket_status` int(11) NOT NULL,
  `documentation_change_passport_change_key` char(64) NOT NULL,
  `documentation_change_passport_obligation_set_hash` char(64) NOT NULL,
  `documentation_change_passport_outcome_code` varchar(40) NOT NULL,
  `documentation_change_passport_committed_by` int(11) NOT NULL DEFAULT 0,
  `documentation_change_passport_committed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_change_passport_id`),
  UNIQUE KEY `documentation_change_passport_key` (`documentation_change_passport_change_key`),
  UNIQUE KEY `documentation_change_passport_sequence` (`documentation_change_passport_ticket_id`,`documentation_change_passport_resolution_sequence`),
  KEY `documentation_change_passport_ticket` (`documentation_change_passport_ticket_id`,`documentation_change_passport_committed_at`),
  KEY `documentation_change_passport_client` (`documentation_change_passport_client_id`,`documentation_change_passport_committed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_change_passport_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_change_passport_obligations` (
  `documentation_change_passport_obligation_passport_id` bigint(20) NOT NULL,
  `documentation_change_passport_obligation_link_id` bigint(20) NOT NULL,
  `documentation_change_passport_obligation_obligation_id` bigint(20) NOT NULL,
  `documentation_change_passport_obligation_task_id` int(11) NOT NULL DEFAULT 0,
  `documentation_change_passport_obligation_requirement_version_id` bigint(20) NOT NULL,
  `documentation_change_passport_obligation_revision` int(11) NOT NULL,
  `documentation_change_passport_obligation_base_status` varchar(20) NOT NULL,
  `documentation_change_passport_obligation_effective_status` varchar(20) NOT NULL,
  `documentation_change_passport_obligation_evidence_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_change_passport_obligation_exception_id` bigint(20) NOT NULL DEFAULT 0,
  `documentation_change_passport_obligation_waiver_id` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`documentation_change_passport_obligation_passport_id`,`documentation_change_passport_obligation_obligation_id`),
  KEY `documentation_change_passport_obligation_source` (`documentation_change_passport_obligation_obligation_id`,`documentation_change_passport_obligation_passport_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_promise_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_promise_ledger` (
  `documentation_promise_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_promise_client_id` int(11) NOT NULL,
  `documentation_promise_obligation_id` bigint(20) NOT NULL,
  `documentation_promise_ticket_id` int(11) NOT NULL DEFAULT 0,
  `documentation_promise_status` varchar(20) NOT NULL DEFAULT 'Open',
  `documentation_promise_reason_code` varchar(60) NOT NULL,
  `documentation_promise_reason_redacted` varchar(255) NOT NULL,
  `documentation_promise_reason_hash` char(64) NOT NULL,
  `documentation_promise_due_at` datetime NOT NULL,
  `documentation_promise_promised_by` int(11) NOT NULL DEFAULT 0,
  `documentation_promise_promised_at` datetime NOT NULL DEFAULT current_timestamp(),
  `documentation_promise_fulfilled_by` int(11) NOT NULL DEFAULT 0,
  `documentation_promise_fulfilled_at` datetime DEFAULT NULL,
  `documentation_promise_revision` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`documentation_promise_id`),
  KEY `documentation_promise_queue` (`documentation_promise_status`,`documentation_promise_due_at`),
  KEY `documentation_promise_obligation` (`documentation_promise_obligation_id`,`documentation_promise_status`),
  KEY `documentation_promise_ticket` (`documentation_promise_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `documentation_promise_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_promise_events` (
  `documentation_promise_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `documentation_promise_event_promise_id` bigint(20) NOT NULL,
  `documentation_promise_event_obligation_id` bigint(20) NOT NULL,
  `documentation_promise_event_client_id` int(11) NOT NULL,
  `documentation_promise_event_ticket_id` int(11) NOT NULL DEFAULT 0,
  `documentation_promise_event_action` varchar(30) NOT NULL,
  `documentation_promise_event_from_status` varchar(20) DEFAULT NULL,
  `documentation_promise_event_to_status` varchar(20) NOT NULL,
  `documentation_promise_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `documentation_promise_event_reason_code` varchar(60) NOT NULL,
  `documentation_promise_event_context_hash` char(64) DEFAULT NULL,
  `documentation_promise_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`documentation_promise_event_id`),
  KEY `documentation_promise_event_history` (`documentation_promise_event_promise_id`,`documentation_promise_event_created_at`,`documentation_promise_event_id`),
  KEY `documentation_promise_event_obligation` (`documentation_promise_event_obligation_id`,`documentation_promise_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_files`
--

DROP TABLE IF EXISTS `document_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_files` (
  `document_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`document_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `document_files_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE,
  CONSTRAINT `document_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_templates`
--

DROP TABLE IF EXISTS `document_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_templates` (
  `document_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_template_name` varchar(200) NOT NULL,
  `document_template_description` text DEFAULT NULL,
  `document_template_content` longtext NOT NULL,
  `document_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `document_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `document_template_archived_at` datetime DEFAULT NULL,
  `document_template_created_by` int(11) NOT NULL DEFAULT 0,
  `document_template_updated_by` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`document_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_versions`
--

DROP TABLE IF EXISTS `document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_versions` (
  `document_version_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_version_name` varchar(200) NOT NULL,
  `document_version_description` text DEFAULT NULL,
  `document_version_content` longtext NOT NULL,
  `document_version_created_by` int(11) DEFAULT 0,
  `document_version_created_at` datetime NOT NULL,
  `document_version_document_id` int(11) NOT NULL,
  PRIMARY KEY (`document_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `document_name` varchar(200) NOT NULL,
  `document_description` text DEFAULT NULL,
  `document_content` longtext NOT NULL,
  `document_content_raw` longtext NOT NULL,
  `document_client_visible` int(11) NOT NULL DEFAULT 1,
  `document_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `document_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `document_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `document_archived_at` datetime DEFAULT NULL,
  `document_accessed_at` datetime DEFAULT NULL,
  `document_folder_id` int(11) NOT NULL DEFAULT 0,
  `document_created_by` int(11) NOT NULL DEFAULT 0,
  `document_updated_by` int(11) NOT NULL DEFAULT 0,
  `document_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`document_id`),
  FULLTEXT KEY `document_content_raw` (`document_content_raw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domain_history`
--

DROP TABLE IF EXISTS `domain_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_history` (
  `domain_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_history_column` varchar(200) NOT NULL,
  `domain_history_old_value` text NOT NULL,
  `domain_history_new_value` text NOT NULL,
  `domain_history_domain_id` int(11) NOT NULL,
  `domain_history_modified_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`domain_history_id`),
  KEY `domain_history_domain_id` (`domain_history_domain_id`),
  CONSTRAINT `domain_history_ibfk_1` FOREIGN KEY (`domain_history_domain_id`) REFERENCES `domains` (`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `domain_id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_name` varchar(200) NOT NULL,
  `domain_description` text DEFAULT NULL,
  `domain_expire` date DEFAULT NULL,
  `domain_ip` varchar(255) DEFAULT NULL,
  `domain_name_servers` varchar(255) DEFAULT NULL,
  `domain_mail_servers` varchar(255) DEFAULT NULL,
  `domain_txt` text DEFAULT NULL,
  `domain_raw_whois` text DEFAULT NULL,
  `domain_notes` text DEFAULT NULL,
  `domain_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `domain_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `domain_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `domain_archived_at` datetime DEFAULT NULL,
  `domain_accessed_at` datetime DEFAULT NULL,
  `domain_registrar` int(11) NOT NULL DEFAULT 0,
  `domain_webhost` int(11) NOT NULL DEFAULT 0,
  `domain_dnshost` int(11) NOT NULL DEFAULT 0,
  `domain_mailhost` int(11) NOT NULL DEFAULT 0,
  `domain_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_queue`
--

DROP TABLE IF EXISTS `email_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `email_id` int(11) NOT NULL AUTO_INCREMENT,
  `email_status` tinyint(1) NOT NULL DEFAULT 0,
  `email_recipient` varchar(255) NOT NULL,
  `email_recipient_name` varchar(255) DEFAULT NULL,
  `email_from` varchar(255) NOT NULL,
  `email_from_name` varchar(255) NOT NULL,
  `email_subject` varchar(255) NOT NULL,
  `email_content` longtext NOT NULL,
  `email_content_plain` longtext DEFAULT NULL,
  `email_template_key` varchar(100) DEFAULT NULL,
  `email_cal_str` varchar(1024) DEFAULT NULL,
  `email_attachments` text DEFAULT NULL,
  `email_queued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email_failed_at` datetime DEFAULT NULL,
  `email_attempts` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_description` text DEFAULT NULL,
  `expense_amount` decimal(15,2) NOT NULL,
  `expense_currency_code` varchar(200) NOT NULL,
  `expense_date` date NOT NULL,
  `expense_reference` varchar(200) DEFAULT NULL,
  `expense_payment_method` varchar(200) DEFAULT NULL,
  `expense_receipt` varchar(200) DEFAULT NULL,
  `expense_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expense_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `expense_archived_at` datetime DEFAULT NULL,
  `expense_vendor_id` int(11) NOT NULL DEFAULT 0,
  `expense_client_id` int(11) NOT NULL DEFAULT 0,
  `expense_category_id` int(11) NOT NULL DEFAULT 0,
  `expense_account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `files` (
  `file_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_reference_name` varchar(200) DEFAULT NULL,
  `file_name` varchar(200) NOT NULL,
  `file_description` varchar(250) DEFAULT NULL,
  `file_ext` varchar(10) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `file_mime_type` varchar(100) DEFAULT NULL,
  `file_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `file_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `file_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `file_archived_at` datetime DEFAULT NULL,
  `file_accessed_at` datetime DEFAULT NULL,
  `file_created_by` int(11) NOT NULL DEFAULT 0,
  `file_folder_id` int(11) NOT NULL DEFAULT 0,
  `file_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `folders`
--

DROP TABLE IF EXISTS `folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `folders` (
  `folder_id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_name` varchar(200) NOT NULL,
  `parent_folder` int(11) NOT NULL DEFAULT 0,
  `folder_location` int(11) DEFAULT 0,
  `folder_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `history`
--

DROP TABLE IF EXISTS `history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `history_status` varchar(200) NOT NULL,
  `history_description` varchar(200) NOT NULL,
  `history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `history_invoice_id` int(11) NOT NULL DEFAULT 0,
  `history_recurring_invoice_id` int(11) NOT NULL DEFAULT 0,
  `history_quote_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_invoice_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_prefix` varchar(200) DEFAULT NULL,
  `invoice_number` int(11) NOT NULL,
  `invoice_scope` varchar(255) DEFAULT NULL,
  `invoice_status` varchar(200) NOT NULL,
  `invoice_date` date NOT NULL,
  `invoice_due` date NOT NULL,
  `invoice_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_credit_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_currency_code` varchar(200) NOT NULL,
  `invoice_note` text DEFAULT NULL,
  `invoice_url_key` varchar(200) DEFAULT NULL,
  `invoice_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `invoice_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `invoice_archived_at` datetime DEFAULT NULL,
  `invoice_category_id` int(11) NOT NULL,
  `invoice_recurring_invoice_id` int(11) NOT NULL DEFAULT 0,
  `invoice_client_id` int(11) NOT NULL,
  PRIMARY KEY (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `location_tags`
--

DROP TABLE IF EXISTS `location_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_tags` (
  `location_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`location_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `location_tags_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `location_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `location_name` varchar(200) NOT NULL,
  `location_description` text DEFAULT NULL,
  `location_country` varchar(200) DEFAULT NULL,
  `location_address` varchar(200) DEFAULT NULL,
  `location_city` varchar(200) DEFAULT NULL,
  `location_state` varchar(200) DEFAULT NULL,
  `location_zip` varchar(200) DEFAULT NULL,
  `location_phone_country_code` varchar(10) DEFAULT NULL,
  `location_phone` varchar(200) DEFAULT NULL,
  `location_phone_extension` varchar(10) DEFAULT NULL,
  `location_fax_country_code` varchar(10) DEFAULT NULL,
  `location_fax` varchar(200) DEFAULT NULL,
  `location_hours` varchar(200) DEFAULT NULL,
  `location_photo` varchar(200) DEFAULT NULL,
  `location_primary` tinyint(1) NOT NULL DEFAULT 0,
  `location_notes` text DEFAULT NULL,
  `location_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `location_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `location_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `location_archived_at` datetime DEFAULT NULL,
  `location_accessed_at` datetime DEFAULT NULL,
  `location_contact_id` int(11) NOT NULL DEFAULT 0,
  `location_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `log_type` varchar(200) NOT NULL,
  `log_action` varchar(255) NOT NULL,
  `log_description` varchar(1000) NOT NULL,
  `log_ip` varchar(200) DEFAULT NULL,
  `log_user_agent` varchar(250) DEFAULT NULL,
  `log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `log_client_id` int(11) NOT NULL DEFAULT 0,
  `log_user_id` int(11) NOT NULL DEFAULT 0,
  `log_entity_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`log_id`),
  KEY `log_created_at` (`log_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `module_id` int(11) NOT NULL AUTO_INCREMENT,
  `module_name` varchar(200) NOT NULL,
  `module_description` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `n45_schema_migrations`
--

DROP TABLE IF EXISTS `n45_schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `n45_schema_migrations` (
  `migration_id` varchar(100) NOT NULL,
  `migration_checksum` char(64) NOT NULL,
  `migration_legacy_version` varchar(20) DEFAULT NULL,
  `migration_applied_by` varchar(20) NOT NULL,
  `migration_applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `networks`
--

DROP TABLE IF EXISTS `networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `networks` (
  `network_id` int(11) NOT NULL AUTO_INCREMENT,
  `network_name` varchar(200) NOT NULL,
  `network_description` text DEFAULT NULL,
  `network_vlan` int(11) DEFAULT NULL,
  `network` varchar(200) NOT NULL,
  `network_subnet` varchar(200) DEFAULT NULL,
  `network_gateway` varchar(200) NOT NULL,
  `network_primary_dns` varchar(200) DEFAULT NULL,
  `network_secondary_dns` varchar(200) DEFAULT NULL,
  `network_dhcp_range` varchar(200) DEFAULT NULL,
  `network_notes` text DEFAULT NULL,
  `network_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `network_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `network_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `network_archived_at` datetime DEFAULT NULL,
  `network_accessed_at` datetime DEFAULT NULL,
  `network_location_id` int(11) NOT NULL DEFAULT 0,
  `network_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`network_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_type` varchar(200) NOT NULL,
  `notification` varchar(1000) NOT NULL,
  `notification_action` varchar(250) DEFAULT NULL,
  `notification_timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `notification_dismissed_at` datetime DEFAULT NULL,
  `notification_dismissed_by` int(11) DEFAULT NULL,
  `notification_client_id` int(11) NOT NULL DEFAULT 0,
  `notification_user_id` int(11) NOT NULL DEFAULT 0,
  `notification_entity_id` int(11) DEFAULT 0,
  PRIMARY KEY (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `payment_method_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_method_name` varchar(200) NOT NULL,
  `payment_method_description` varchar(250) DEFAULT NULL,
  `payment_method_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_method_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_method_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_providers`
--

DROP TABLE IF EXISTS `payment_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_providers` (
  `payment_provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_provider_name` varchar(200) NOT NULL,
  `payment_provider_description` varchar(250) DEFAULT NULL,
  `payment_provider_public_key` varchar(250) DEFAULT NULL,
  `payment_provider_private_key` varchar(250) DEFAULT NULL,
  `payment_provider_threshold` decimal(15,2) DEFAULT NULL,
  `payment_provider_active` tinyint(1) NOT NULL DEFAULT 1,
  `payment_provider_account` int(11) NOT NULL,
  `payment_provider_expense_vendor` int(11) NOT NULL DEFAULT 0,
  `payment_provider_expense_category` int(11) NOT NULL DEFAULT 0,
  `payment_provider_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_provider_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_date` date NOT NULL,
  `payment_amount` decimal(15,2) NOT NULL,
  `payment_currency_code` varchar(10) NOT NULL,
  `payment_method` varchar(200) DEFAULT NULL,
  `payment_reference` varchar(200) DEFAULT NULL,
  `payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `payment_archived_at` datetime DEFAULT NULL,
  `payment_account_id` int(11) NOT NULL,
  `payment_invoice_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_catalog_fields`
--

DROP TABLE IF EXISTS `portal_request_catalog_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_catalog_fields` (
  `portal_request_catalog_field_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_catalog_field_item_id` int(11) NOT NULL,
  `portal_request_catalog_field_key` varchar(100) NOT NULL,
  `portal_request_catalog_field_label` varchar(200) NOT NULL,
  `portal_request_catalog_field_help` varchar(500) DEFAULT NULL,
  `portal_request_catalog_field_type` varchar(30) NOT NULL,
  `portal_request_catalog_field_required` tinyint(1) NOT NULL DEFAULT 0,
  `portal_request_catalog_field_options` longtext DEFAULT NULL,
  `portal_request_catalog_field_max_length` int(11) NOT NULL DEFAULT 255,
  `portal_request_catalog_field_min_value` bigint(20) DEFAULT NULL,
  `portal_request_catalog_field_max_value` bigint(20) DEFAULT NULL,
  `portal_request_catalog_field_order` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_field_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `portal_request_catalog_field_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`portal_request_catalog_field_id`),
  UNIQUE KEY `portal_request_catalog_field_key` (`portal_request_catalog_field_item_id`,`portal_request_catalog_field_key`),
  KEY `portal_request_catalog_field_order` (`portal_request_catalog_field_item_id`,`portal_request_catalog_field_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_catalog_items`
--

DROP TABLE IF EXISTS `portal_request_catalog_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_catalog_items` (
  `portal_request_catalog_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `portal_request_catalog_item_key` varchar(100) NOT NULL,
  `portal_request_catalog_item_type` varchar(30) NOT NULL DEFAULT 'other',
  `portal_request_catalog_item_name` varchar(200) NOT NULL,
  `portal_request_catalog_item_description` text DEFAULT NULL,
  `portal_request_catalog_item_instructions` text DEFAULT NULL,
  `portal_request_catalog_item_icon` varchar(60) NOT NULL DEFAULT 'far fa-list-alt',
  `portal_request_catalog_item_category_id` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_ticket_template_id` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_published_version_id` bigint(20) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_permission_rule` varchar(30) NOT NULL DEFAULT 'any',
  `portal_request_catalog_item_applicability_rule` varchar(30) NOT NULL DEFAULT 'all',
  `portal_request_catalog_item_applicability_value` varchar(255) DEFAULT NULL,
  `portal_request_catalog_item_approval_rule` varchar(30) NOT NULL DEFAULT 'none',
  `portal_request_catalog_item_order` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_created_by` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_updated_by` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `portal_request_catalog_item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `portal_request_catalog_item_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`portal_request_catalog_item_id`),
  UNIQUE KEY `portal_request_catalog_item_key` (`portal_request_catalog_item_key`),
  KEY `portal_request_catalog_item_release` (`portal_request_catalog_item_published_version_id`),
  KEY `portal_request_catalog_item_template` (`portal_request_catalog_item_ticket_template_id`),
  KEY `portal_request_catalog_item_active` (`portal_request_catalog_item_archived_at`,`portal_request_catalog_item_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_catalog_version_fields`
--

DROP TABLE IF EXISTS `portal_request_catalog_version_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_catalog_version_fields` (
  `portal_request_catalog_version_field_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_catalog_version_field_version_id` bigint(20) NOT NULL,
  `portal_request_catalog_version_field_key` varchar(100) NOT NULL,
  `portal_request_catalog_version_field_label` varchar(200) NOT NULL,
  `portal_request_catalog_version_field_help` varchar(500) DEFAULT NULL,
  `portal_request_catalog_version_field_type` varchar(30) NOT NULL,
  `portal_request_catalog_version_field_required` tinyint(1) NOT NULL DEFAULT 0,
  `portal_request_catalog_version_field_options` longtext DEFAULT NULL,
  `portal_request_catalog_version_field_max_length` int(11) NOT NULL DEFAULT 255,
  `portal_request_catalog_version_field_min_value` bigint(20) DEFAULT NULL,
  `portal_request_catalog_version_field_max_value` bigint(20) DEFAULT NULL,
  `portal_request_catalog_version_field_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`portal_request_catalog_version_field_id`),
  UNIQUE KEY `portal_request_catalog_version_field_key` (`portal_request_catalog_version_field_version_id`,`portal_request_catalog_version_field_key`),
  KEY `portal_request_catalog_version_field_order` (`portal_request_catalog_version_field_version_id`,`portal_request_catalog_version_field_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_catalog_versions`
--

DROP TABLE IF EXISTS `portal_request_catalog_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_catalog_versions` (
  `portal_request_catalog_version_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_catalog_version_item_id` int(11) NOT NULL,
  `portal_request_catalog_version_number` int(11) NOT NULL,
  `portal_request_catalog_version_definition_hash` char(64) NOT NULL,
  `portal_request_catalog_version_key` varchar(100) NOT NULL,
  `portal_request_catalog_version_type` varchar(30) NOT NULL,
  `portal_request_catalog_version_name` varchar(200) NOT NULL,
  `portal_request_catalog_version_description` text DEFAULT NULL,
  `portal_request_catalog_version_instructions` text DEFAULT NULL,
  `portal_request_catalog_version_icon` varchar(60) NOT NULL,
  `portal_request_catalog_version_category_id` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_version_ticket_template_id` int(11) NOT NULL,
  `portal_request_catalog_version_runbook_version_id` bigint(20) NOT NULL,
  `portal_request_catalog_version_permission_rule` varchar(30) NOT NULL,
  `portal_request_catalog_version_applicability_rule` varchar(30) NOT NULL,
  `portal_request_catalog_version_applicability_value` varchar(255) DEFAULT NULL,
  `portal_request_catalog_version_approval_rule` varchar(30) NOT NULL,
  `portal_request_catalog_version_order` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_version_notes` varchar(255) DEFAULT NULL,
  `portal_request_catalog_version_created_by` int(11) NOT NULL DEFAULT 0,
  `portal_request_catalog_version_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`portal_request_catalog_version_id`),
  UNIQUE KEY `portal_request_catalog_version_number` (`portal_request_catalog_version_item_id`,`portal_request_catalog_version_number`),
  UNIQUE KEY `portal_request_catalog_version_hash` (`portal_request_catalog_version_item_id`,`portal_request_catalog_version_definition_hash`),
  KEY `portal_request_catalog_version_category` (`portal_request_catalog_version_category_id`),
  KEY `portal_request_catalog_version_template` (`portal_request_catalog_version_ticket_template_id`),
  KEY `portal_request_catalog_version_runbook` (`portal_request_catalog_version_runbook_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_dispatch_outbox`
--

DROP TABLE IF EXISTS `portal_request_dispatch_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_dispatch_outbox` (
  `portal_request_dispatch_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_dispatch_event_key` char(64) NOT NULL,
  `portal_request_dispatch_submission_id` bigint(20) NOT NULL,
  `portal_request_dispatch_ticket_id` int(11) NOT NULL,
  `portal_request_dispatch_trigger` varchar(40) NOT NULL,
  `portal_request_dispatch_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `portal_request_dispatch_attempts` int(11) NOT NULL DEFAULT 0,
  `portal_request_dispatch_available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `portal_request_dispatch_processing_at` datetime DEFAULT NULL,
  `portal_request_dispatch_lease_token` char(64) DEFAULT NULL,
  `portal_request_dispatch_delivered_at` datetime DEFAULT NULL,
  `portal_request_dispatch_last_error` varchar(1000) DEFAULT NULL,
  `portal_request_dispatch_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `portal_request_dispatch_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`portal_request_dispatch_id`),
  UNIQUE KEY `portal_request_dispatch_event_key` (`portal_request_dispatch_event_key`),
  UNIQUE KEY `portal_request_dispatch_submission_trigger` (`portal_request_dispatch_submission_id`,`portal_request_dispatch_trigger`),
  KEY `portal_request_dispatch_status_available` (`portal_request_dispatch_status`,`portal_request_dispatch_available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_submission_events`
--

DROP TABLE IF EXISTS `portal_request_submission_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_submission_events` (
  `portal_request_submission_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_submission_event_submission_id` bigint(20) NOT NULL,
  `portal_request_submission_event_action` varchar(30) NOT NULL,
  `portal_request_submission_event_from_status` varchar(30) DEFAULT NULL,
  `portal_request_submission_event_to_status` varchar(30) NOT NULL,
  `portal_request_submission_event_actor_type` varchar(20) NOT NULL,
  `portal_request_submission_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `portal_request_submission_event_note` varchar(255) DEFAULT NULL,
  `portal_request_submission_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`portal_request_submission_event_id`),
  KEY `portal_request_submission_event_submission` (`portal_request_submission_event_submission_id`,`portal_request_submission_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `portal_request_submissions`
--

DROP TABLE IF EXISTS `portal_request_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_request_submissions` (
  `portal_request_submission_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `portal_request_submission_item_id` int(11) NOT NULL,
  `portal_request_submission_version_id` bigint(20) NOT NULL,
  `portal_request_submission_client_id` int(11) NOT NULL,
  `portal_request_submission_contact_id` int(11) NOT NULL,
  `portal_request_submission_user_id` int(11) NOT NULL,
  `portal_request_submission_ticket_id` int(11) DEFAULT NULL,
  `portal_request_submission_status` varchar(30) NOT NULL,
  `portal_request_submission_idempotency_hash` char(64) NOT NULL,
  `portal_request_submission_request_hash` char(64) NOT NULL,
  `portal_request_submission_responses` longtext NOT NULL,
  `portal_request_submission_response_hash` char(64) NOT NULL,
  `portal_request_submission_submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `portal_request_submission_decided_by_type` varchar(20) DEFAULT NULL,
  `portal_request_submission_decided_by_id` int(11) NOT NULL DEFAULT 0,
  `portal_request_submission_decided_at` datetime DEFAULT NULL,
  `portal_request_submission_initiated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`portal_request_submission_id`),
  UNIQUE KEY `portal_request_submission_idempotency` (`portal_request_submission_idempotency_hash`),
  UNIQUE KEY `portal_request_submission_ticket` (`portal_request_submission_ticket_id`),
  KEY `portal_request_submission_client_status` (`portal_request_submission_client_id`,`portal_request_submission_status`,`portal_request_submission_submitted_at`),
  KEY `portal_request_submission_contact` (`portal_request_submission_contact_id`,`portal_request_submission_submitted_at`),
  KEY `portal_request_submission_version` (`portal_request_submission_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

-- Table structure for table `product_stock`
--

DROP TABLE IF EXISTS `product_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stock` (
  `stock_id` int(11) NOT NULL AUTO_INCREMENT,
  `stock_qty` int(11) NOT NULL,
  `stock_note` text DEFAULT NULL,
  `stock_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stock_expense_id` int(11) DEFAULT NULL,
  `stock_item_id` int(11) DEFAULT NULL,
  `stock_product_id` int(11) NOT NULL,
  PRIMARY KEY (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(200) NOT NULL,
  `product_type` enum('service','product') NOT NULL DEFAULT 'service',
  `product_description` text DEFAULT NULL,
  `product_code` varchar(200) DEFAULT NULL,
  `product_location` varchar(250) DEFAULT NULL,
  `product_price` decimal(15,2) NOT NULL,
  `product_currency_code` varchar(200) NOT NULL,
  `product_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `product_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `product_archived_at` datetime DEFAULT NULL,
  `product_tax_id` int(11) NOT NULL DEFAULT 0,
  `product_category_id` int(11) NOT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_template_ticket_templates`
--

DROP TABLE IF EXISTS `project_template_ticket_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_template_ticket_templates` (
  `ticket_template_id` int(11) NOT NULL,
  `project_template_id` int(11) NOT NULL,
  `ticket_template_order` int(11) NOT NULL DEFAULT 0,
  `ticket_template_runbook_version_id` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_template_id`,`project_template_id`),
  KEY `ticket_template_runbook_version` (`ticket_template_runbook_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_templates`
--

DROP TABLE IF EXISTS `project_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_templates` (
  `project_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `project_template_name` varchar(200) NOT NULL,
  `project_template_description` text DEFAULT NULL,
  `project_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `project_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `project_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`project_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `project_id` int(11) NOT NULL AUTO_INCREMENT,
  `project_prefix` varchar(200) DEFAULT NULL,
  `project_number` int(11) NOT NULL DEFAULT 1,
  `project_name` varchar(255) NOT NULL,
  `project_description` mediumtext DEFAULT NULL,
  `project_due` date DEFAULT NULL,
  `project_manager` int(11) NOT NULL DEFAULT 0,
  `project_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `project_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `project_completed_at` datetime DEFAULT NULL,
  `project_archived_at` datetime DEFAULT NULL,
  `project_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quote_files`
--

DROP TABLE IF EXISTS `quote_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_files` (
  `quote_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`quote_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `quote_files_ibfk_1` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`quote_id`) ON DELETE CASCADE,
  CONSTRAINT `quote_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quote_items`
--

DROP TABLE IF EXISTS `quote_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_quote_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quotes`
--

DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `quote_id` int(11) NOT NULL AUTO_INCREMENT,
  `quote_prefix` varchar(200) DEFAULT NULL,
  `quote_number` int(11) NOT NULL,
  `quote_scope` varchar(255) DEFAULT NULL,
  `quote_status` varchar(200) NOT NULL,
  `quote_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quote_date` date NOT NULL,
  `quote_expire` date DEFAULT NULL,
  `quote_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quote_currency_code` varchar(200) NOT NULL,
  `quote_note` text DEFAULT NULL,
  `quote_url_key` varchar(200) DEFAULT NULL,
  `quote_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `quote_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `quote_archived_at` datetime DEFAULT NULL,
  `quote_category_id` int(11) NOT NULL,
  `quote_client_id` int(11) NOT NULL,
  PRIMARY KEY (`quote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rack_units`
--

DROP TABLE IF EXISTS `rack_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rack_units` (
  `unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_start_number` int(11) NOT NULL,
  `unit_end_number` int(11) NOT NULL,
  `unit_device` varchar(200) DEFAULT NULL,
  `unit_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `unit_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `unit_archived_at` datetime DEFAULT NULL,
  `unit_asset_id` int(11) DEFAULT NULL,
  `unit_rack_id` int(11) NOT NULL,
  PRIMARY KEY (`unit_id`),
  KEY `unit_rack_id` (`unit_rack_id`),
  CONSTRAINT `rack_units_ibfk_1` FOREIGN KEY (`unit_rack_id`) REFERENCES `racks` (`rack_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `racks`
--

DROP TABLE IF EXISTS `racks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `racks` (
  `rack_id` int(11) NOT NULL AUTO_INCREMENT,
  `rack_name` varchar(200) NOT NULL,
  `rack_description` text DEFAULT NULL,
  `rack_model` varchar(200) DEFAULT NULL,
  `rack_depth` varchar(50) DEFAULT NULL,
  `rack_type` varchar(50) DEFAULT NULL,
  `rack_units` int(11) NOT NULL,
  `rack_photo` varchar(200) DEFAULT NULL,
  `rack_physical_location` varchar(200) DEFAULT NULL,
  `rack_notes` text DEFAULT NULL,
  `rack_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `rack_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rack_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `rack_archived_at` datetime DEFAULT NULL,
  `rack_location_id` int(11) DEFAULT NULL,
  `rack_client_id` int(11) NOT NULL,
  PRIMARY KEY (`rack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `records`
--

DROP TABLE IF EXISTS `records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `records` (
  `record_id` int(11) NOT NULL AUTO_INCREMENT,
  `record_type` varchar(200) NOT NULL,
  `record` varchar(200) NOT NULL,
  `record_value` varchar(200) NOT NULL,
  `record_priority` int(11) DEFAULT NULL,
  `record_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `record_updated_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp(),
  `record_archived_at` datetime DEFAULT NULL,
  `record_domain_id` int(11) NOT NULL,
  PRIMARY KEY (`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_expenses`
--

DROP TABLE IF EXISTS `recurring_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_expenses` (
  `recurring_expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_expense_frequency` tinyint(1) NOT NULL,
  `recurring_expense_day` tinyint(4) DEFAULT NULL,
  `recurring_expense_month` tinyint(4) DEFAULT NULL,
  `recurring_expense_last_sent` date DEFAULT NULL,
  `recurring_expense_next_date` date NOT NULL,
  `recurring_expense_status` tinyint(1) NOT NULL DEFAULT 1,
  `recurring_expense_description` mediumtext DEFAULT NULL,
  `recurring_expense_amount` decimal(15,2) NOT NULL,
  `recurring_expense_payment_method` varchar(200) DEFAULT NULL,
  `recurring_expense_reference` varchar(255) DEFAULT NULL,
  `recurring_expense_currency_code` varchar(200) NOT NULL,
  `recurring_expense_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_expense_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_expense_archived_at` datetime DEFAULT NULL,
  `recurring_expense_vendor_id` int(11) NOT NULL,
  `recurring_expense_client_id` int(11) NOT NULL DEFAULT 0,
  `recurring_expense_category_id` int(11) NOT NULL,
  `recurring_expense_account_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_invoice_items`
--

DROP TABLE IF EXISTS `recurring_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_order` int(11) NOT NULL DEFAULT 0,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `item_archived_at` datetime DEFAULT NULL,
  `item_tax_id` int(11) NOT NULL DEFAULT 0,
  `item_product_id` int(11) NOT NULL DEFAULT 0,
  `item_recurring_invoice_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_invoices`
--

DROP TABLE IF EXISTS `recurring_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_invoices` (
  `recurring_invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_invoice_prefix` varchar(200) DEFAULT NULL,
  `recurring_invoice_number` int(11) NOT NULL,
  `recurring_invoice_scope` varchar(255) DEFAULT NULL,
  `recurring_invoice_frequency` varchar(200) NOT NULL,
  `recurring_invoice_last_sent` date DEFAULT NULL,
  `recurring_invoice_next_date` date NOT NULL,
  `recurring_invoice_status` int(1) NOT NULL,
  `recurring_invoice_discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `recurring_invoice_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `recurring_invoice_currency_code` varchar(200) NOT NULL,
  `recurring_invoice_note` text DEFAULT NULL,
  `recurring_invoice_email_notify` tinyint(1) NOT NULL DEFAULT 1,
  `recurring_invoice_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_invoice_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_invoice_archived_at` datetime DEFAULT NULL,
  `recurring_invoice_category_id` int(11) NOT NULL,
  `recurring_invoice_client_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_payments`
--

DROP TABLE IF EXISTS `recurring_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_payments` (
  `recurring_payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_payment_currency_code` varchar(10) NOT NULL,
  `recurring_payment_method` varchar(200) NOT NULL,
  `recurring_payment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_payment_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_payment_archived_at` datetime DEFAULT NULL,
  `recurring_payment_account_id` int(11) NOT NULL,
  `recurring_payment_recurring_expense_id` int(11) NOT NULL DEFAULT 0,
  `recurring_payment_recurring_invoice_id` int(11) NOT NULL,
  `recurring_payment_saved_payment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`recurring_payment_id`),
  KEY `fk_recurring_saved_payment` (`recurring_payment_saved_payment_id`),
  CONSTRAINT `fk_recurring_saved_payment` FOREIGN KEY (`recurring_payment_saved_payment_id`) REFERENCES `client_saved_payment_methods` (`saved_payment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_ticket_assets`
--

DROP TABLE IF EXISTS `recurring_ticket_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_ticket_assets` (
  `recurring_ticket_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_ticket_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `recurring_ticket_assets_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `recurring_ticket_assets_ibfk_2` FOREIGN KEY (`recurring_ticket_id`) REFERENCES `recurring_tickets` (`recurring_ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_ticket_tasks`
--

DROP TABLE IF EXISTS `recurring_ticket_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_ticket_tasks` (
  `recurring_ticket_task_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_ticket_task_name` varchar(255) NOT NULL,
  `recurring_ticket_task_order` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_task_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_task_recurring_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`recurring_ticket_task_id`),
  KEY `recurring_ticket_task_recurring_ticket_id` (`recurring_ticket_task_recurring_ticket_id`),
  CONSTRAINT `recurring_ticket_tasks_ibfk_1` FOREIGN KEY (`recurring_ticket_task_recurring_ticket_id`) REFERENCES `recurring_tickets` (`recurring_ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `recurring_tickets`
--

DROP TABLE IF EXISTS `recurring_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recurring_tickets` (
  `recurring_ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `recurring_ticket_category` varchar(200) DEFAULT NULL,
  `recurring_ticket_subject` varchar(500) NOT NULL,
  `recurring_ticket_details` longtext NOT NULL,
  `recurring_ticket_priority` varchar(200) DEFAULT NULL,
  `recurring_ticket_frequency` varchar(10) NOT NULL,
  `recurring_ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `recurring_ticket_start_date` date NOT NULL,
  `recurring_ticket_next_run` date NOT NULL,
  `recurring_ticket_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `recurring_ticket_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `recurring_ticket_created_by` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_assigned_to` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_client_id` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_contact_id` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_asset_id` int(11) NOT NULL DEFAULT 0,
  `recurring_ticket_ticket_template_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`recurring_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `remember_tokens` (
  `remember_token_id` int(11) NOT NULL AUTO_INCREMENT,
  `remember_token_token` varchar(255) NOT NULL,
  `remember_token_user_id` int(11) NOT NULL,
  `remember_token_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`remember_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `revenues`
--

DROP TABLE IF EXISTS `revenues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenues` (
  `revenue_id` int(11) NOT NULL AUTO_INCREMENT,
  `revenue_date` date NOT NULL,
  `revenue_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `revenue_currency_code` varchar(200) NOT NULL,
  `revenue_payment_method` varchar(200) DEFAULT NULL,
  `revenue_reference` varchar(200) DEFAULT NULL,
  `revenue_description` varchar(200) DEFAULT NULL,
  `revenue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revenue_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `revenue_archived_at` datetime DEFAULT NULL,
  `revenue_category_id` int(11) NOT NULL DEFAULT 0,
  `revenue_account_id` int(11) NOT NULL,
  `revenue_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`revenue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `runbook_versions`
--

DROP TABLE IF EXISTS `runbook_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `runbook_versions` (
  `runbook_version_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `runbook_version_ticket_template_id` int(11) NOT NULL,
  `runbook_version_number` int(11) NOT NULL,
  `runbook_version_definition_hash` char(64) NOT NULL,
  `runbook_version_key` varchar(100) NOT NULL,
  `runbook_version_name` varchar(200) NOT NULL,
  `runbook_version_description` text DEFAULT NULL,
  `runbook_version_subject` varchar(500) DEFAULT NULL,
  `runbook_version_details` longtext DEFAULT NULL,
  `runbook_version_type` varchar(20) NOT NULL DEFAULT 'standard',
  `runbook_version_notes` varchar(255) DEFAULT NULL,
  `runbook_version_created_by` int(11) NOT NULL DEFAULT 0,
  `runbook_version_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`runbook_version_id`),
  UNIQUE KEY `runbook_version_number` (`runbook_version_ticket_template_id`,`runbook_version_number`),
  UNIQUE KEY `runbook_version_hash` (`runbook_version_ticket_template_id`,`runbook_version_definition_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `runbook_version_tasks`
--

DROP TABLE IF EXISTS `runbook_version_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `runbook_version_tasks` (
  `runbook_version_task_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `runbook_version_task_runbook_version_id` bigint(20) NOT NULL,
  `runbook_version_task_source_id` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_key` varchar(100) NOT NULL,
  `runbook_version_task_name` varchar(255) NOT NULL,
  `runbook_version_task_instructions` text DEFAULT NULL,
  `runbook_version_task_order` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_condition_type` varchar(40) NOT NULL DEFAULT 'always',
  `runbook_version_task_condition_value` varchar(255) DEFAULT NULL,
  `runbook_version_task_owner_type` varchar(40) NOT NULL DEFAULT 'unassigned',
  `runbook_version_task_owner_user_id` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_due_offset_minutes` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_initial_state` varchar(20) NOT NULL DEFAULT 'Ready',
  `runbook_version_task_approval_scope` varchar(20) DEFAULT NULL,
  `runbook_version_task_approval_type` varchar(20) DEFAULT NULL,
  `runbook_version_task_approval_user_id` int(11) NOT NULL DEFAULT 0,
  `runbook_version_task_evidence_type` varchar(20) NOT NULL DEFAULT 'none',
  `runbook_version_task_evidence_prompt` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`runbook_version_task_id`),
  UNIQUE KEY `runbook_version_task_key` (`runbook_version_task_runbook_version_id`,`runbook_version_task_key`),
  KEY `runbook_version_task_order` (`runbook_version_task_runbook_version_id`,`runbook_version_task_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `runbook_version_task_dependencies`
--

DROP TABLE IF EXISTS `runbook_version_task_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `runbook_version_task_dependencies` (
  `runbook_version_task_id` bigint(20) NOT NULL,
  `depends_on_runbook_version_task_id` bigint(20) NOT NULL,
  PRIMARY KEY (`runbook_version_task_id`,`depends_on_runbook_version_task_id`),
  KEY `depends_on_runbook_version_task_id` (`depends_on_runbook_version_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `runbook_executions`
--

DROP TABLE IF EXISTS `runbook_executions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `runbook_executions` (
  `runbook_execution_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `runbook_execution_version_id` bigint(20) NOT NULL,
  `runbook_execution_ticket_id` int(11) NOT NULL,
  `runbook_execution_status` varchar(20) NOT NULL DEFAULT 'Active',
  `runbook_execution_context` longtext DEFAULT NULL,
  `runbook_execution_snapshot` longtext NOT NULL,
  `runbook_execution_snapshot_hash` char(64) NOT NULL,
  `runbook_execution_started_by` int(11) NOT NULL DEFAULT 0,
  `runbook_execution_started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `runbook_execution_completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`runbook_execution_id`),
  UNIQUE KEY `runbook_execution_ticket` (`runbook_execution_ticket_id`),
  KEY `runbook_execution_version` (`runbook_execution_version_id`,`runbook_execution_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_assets`
--

DROP TABLE IF EXISTS `service_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_assets` (
  `service_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `service_assets_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_certificates`
--

DROP TABLE IF EXISTS `service_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_certificates` (
  `service_id` int(11) NOT NULL,
  `certificate_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `certificate_id` (`certificate_id`),
  CONSTRAINT `service_certificates_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_certificates_ibfk_2` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_contacts`
--

DROP TABLE IF EXISTS `service_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contacts` (
  `service_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `service_contacts_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_credentials`
--

DROP TABLE IF EXISTS `service_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_credentials` (
  `service_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `service_credentials_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_documents`
--

DROP TABLE IF EXISTS `service_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_documents` (
  `service_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `service_documents_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_domains`
--

DROP TABLE IF EXISTS `service_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_domains` (
  `service_id` int(11) NOT NULL,
  `domain_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_vendors`
--

DROP TABLE IF EXISTS `service_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_vendors` (
  `service_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  KEY `service_id` (`service_id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `service_vendors_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_vendors_ibfk_2` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `service_name` varchar(200) NOT NULL,
  `service_description` varchar(200) NOT NULL,
  `service_category` varchar(20) NOT NULL,
  `service_importance` varchar(10) NOT NULL,
  `service_backup` varchar(200) DEFAULT NULL,
  `service_notes` mediumtext NOT NULL,
  `service_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `service_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `service_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `service_accessed_at` datetime DEFAULT NULL,
  `service_review_due` date DEFAULT NULL,
  `service_client_id` int(11) NOT NULL,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `company_id` int(11) NOT NULL,
  `config_current_database_version` varchar(10) NOT NULL,
  `config_start_page` varchar(200) DEFAULT 'clients.php',
  `config_smtp_provider` varchar(200) DEFAULT NULL,
  `config_smtp_host` varchar(200) DEFAULT NULL,
  `config_smtp_port` int(5) DEFAULT NULL,
  `config_smtp_encryption` varchar(200) DEFAULT NULL,
  `config_smtp_username` varchar(200) DEFAULT NULL,
  `config_smtp_password` varchar(200) DEFAULT NULL,
  `config_mail_from_email` varchar(200) DEFAULT NULL,
  `config_mail_from_name` varchar(200) DEFAULT NULL,
  `config_imap_provider` varchar(200) DEFAULT NULL,
  `config_mail_oauth_client_id` varchar(255) DEFAULT NULL,
  `config_mail_oauth_client_secret` varchar(255) DEFAULT NULL,
  `config_mail_oauth_tenant_id` varchar(255) DEFAULT NULL,
  `config_mail_oauth_refresh_token` text DEFAULT NULL,
  `config_mail_oauth_access_token` text DEFAULT NULL,
  `config_mail_oauth_access_token_expires_at` datetime DEFAULT NULL,
  `config_imap_host` varchar(200) DEFAULT NULL,
  `config_imap_port` int(5) DEFAULT NULL,
  `config_imap_encryption` varchar(200) DEFAULT NULL,
  `config_imap_username` varchar(200) DEFAULT NULL,
  `config_imap_password` varchar(200) DEFAULT NULL,
  `config_default_transfer_from_account` int(11) DEFAULT NULL,
  `config_default_transfer_to_account` int(11) DEFAULT NULL,
  `config_default_payment_account` int(11) DEFAULT NULL,
  `config_default_expense_account` int(11) DEFAULT NULL,
  `config_default_payment_method` varchar(200) DEFAULT NULL,
  `config_default_expense_payment_method` varchar(200) DEFAULT NULL,
  `config_default_calendar` int(11) DEFAULT NULL,
  `config_default_net_terms` int(11) DEFAULT NULL,
  `config_default_hourly_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
  `config_project_prefix` varchar(200) NOT NULL DEFAULT 'PRJ-',
  `config_project_next_number` int(11) NOT NULL DEFAULT 1,
  `config_invoice_prefix` varchar(200) DEFAULT NULL,
  `config_invoice_next_number` int(11) DEFAULT NULL,
  `config_invoice_footer` text DEFAULT NULL,
  `config_invoice_from_name` varchar(200) DEFAULT NULL,
  `config_invoice_from_email` varchar(200) DEFAULT NULL,
  `config_invoice_late_fee_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_invoice_late_fee_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `config_invoice_paid_notification_email` varchar(200) DEFAULT NULL,
  `config_invoice_show_tax_id` tinyint(1) NOT NULL DEFAULT 0,
  `config_recurring_invoice_prefix` varchar(200) DEFAULT NULL,
  `config_recurring_invoice_next_number` int(11) NOT NULL DEFAULT 1,
  `config_quote_prefix` varchar(200) DEFAULT NULL,
  `config_quote_next_number` int(11) DEFAULT NULL,
  `config_quote_footer` text DEFAULT NULL,
  `config_quote_from_name` varchar(200) DEFAULT NULL,
  `config_quote_from_email` varchar(200) DEFAULT NULL,
  `config_quote_notification_email` varchar(200) DEFAULT NULL,
  `config_ticket_prefix` varchar(200) DEFAULT NULL,
  `config_ticket_next_number` int(11) DEFAULT NULL,
  `config_ticket_from_name` varchar(200) DEFAULT NULL,
  `config_ticket_from_email` varchar(200) DEFAULT NULL,
  `config_ticket_email_parse` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_email_parse_unknown_senders` int(1) NOT NULL DEFAULT 0,
  `config_ticket_client_general_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `config_ticket_autoclose_hours` int(5) NOT NULL DEFAULT 72,
  `config_ticket_new_ticket_notification_email` varchar(200) DEFAULT NULL,
  `config_ticket_default_billable` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_timer_autostart` tinyint(1) NOT NULL DEFAULT 0,
  `config_enable_cron` tinyint(1) NOT NULL DEFAULT 0,
  `config_recurring_auto_send_invoice` tinyint(1) NOT NULL DEFAULT 1,
  `config_enable_alert_domain_expire` tinyint(1) NOT NULL DEFAULT 1,
  `config_send_invoice_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `config_azure_client_id` varchar(200) DEFAULT NULL,
  `config_azure_client_secret` varchar(200) DEFAULT NULL,
  `config_azure_tenant_id` varchar(36) DEFAULT NULL,
  `config_azure_agent_sso_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_level_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_level_api_key` varchar(255) DEFAULT NULL,
  `config_level_webhook_secret` varchar(255) DEFAULT NULL,
  `config_level_alert_ticket_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_level_alert_assigned_to` int(11) NOT NULL DEFAULT 0,
  `config_module_enable_itdoc` tinyint(1) NOT NULL DEFAULT 1,
  `config_module_enable_accounting` tinyint(1) NOT NULL DEFAULT 1,
  `config_client_portal_enable` tinyint(1) NOT NULL DEFAULT 1,
  `config_login_message` text DEFAULT NULL,
  `config_login_key_required` tinyint(1) NOT NULL DEFAULT 0,
  `config_login_key_secret` varchar(255) DEFAULT NULL,
  `config_login_remember_me_expire` int(11) NOT NULL DEFAULT 3,
  `config_log_retention` int(11) NOT NULL DEFAULT 90,
  `config_module_enable_ticketing` tinyint(1) NOT NULL DEFAULT 1,
  `config_theme` varchar(200) DEFAULT 'blue',
  `config_telemetry` tinyint(1) DEFAULT 0,
  `config_timezone` varchar(200) NOT NULL DEFAULT 'America/New_York',
  `config_business_days` varchar(20) NOT NULL DEFAULT '1,2,3,4,5',
  `config_business_hours_start` time NOT NULL DEFAULT '08:00:00',
  `config_business_hours_end` time NOT NULL DEFAULT '17:00:00',
  `config_sla_warning_percent` tinyint(3) NOT NULL DEFAULT 75,
  `config_sla_notification_email` varchar(200) DEFAULT NULL,
  `config_destructive_deletes_enable` tinyint(1) NOT NULL DEFAULT 0,
  `config_whitelabel_enabled` int(11) NOT NULL DEFAULT 0,
  `config_whitelabel_key` text DEFAULT NULL,
  `config_ticket_default_view` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_ordering` tinyint(1) NOT NULL DEFAULT 0,
  `config_ticket_moving_columns` tinyint(1) NOT NULL DEFAULT 1,
  `config_cron_last_dispatch_at` datetime DEFAULT NULL,
  `config_backup_retention_days` int(11) NOT NULL DEFAULT 30,
  `config_backup_retention_count` int(11) NOT NULL DEFAULT 5,
  `config_backup_cron_type` varchar(20) NOT NULL DEFAULT 'full',
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shared_items`
--

DROP TABLE IF EXISTS `shared_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `shared_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_active` int(1) NOT NULL,
  `item_key` varchar(255) NOT NULL,
  `item_type` varchar(255) NOT NULL,
  `item_related_id` int(11) NOT NULL,
  `item_encrypted_username` varchar(255) DEFAULT NULL,
  `item_encrypted_credential` varchar(255) DEFAULT NULL,
  `item_note` varchar(255) DEFAULT NULL,
  `item_recipient` varchar(250) DEFAULT NULL,
  `item_views` int(11) NOT NULL,
  `item_view_limit` int(11) DEFAULT NULL,
  `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `item_expire_at` datetime DEFAULT NULL,
  `item_client_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_assignments`
--

DROP TABLE IF EXISTS `sla_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_assignments` (
  `sla_assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `sla_assignment_client_id` int(11) NOT NULL DEFAULT 0,
  `sla_assignment_priority` varchar(200) NOT NULL,
  `sla_assignment_sla_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`sla_assignment_id`),
  UNIQUE KEY `sla_assignment_client_priority` (`sla_assignment_client_id`,`sla_assignment_priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sla_history`
--

DROP TABLE IF EXISTS `sla_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_history` (
  `sla_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `sla_history_started_at` datetime NOT NULL,
  `sla_history_ended_at` datetime DEFAULT NULL,
  `sla_history_minutes` int(11) DEFAULT NULL,
  `sla_history_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`sla_history_id`),
  KEY `sla_history_ticket_id` (`sla_history_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `slas`
--

DROP TABLE IF EXISTS `slas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `slas` (
  `sla_id` int(11) NOT NULL AUTO_INCREMENT,
  `sla_name` varchar(200) NOT NULL,
  `sla_description` varchar(500) DEFAULT NULL,
  `sla_response_minutes` int(11) NOT NULL,
  `sla_resolution_minutes` int(11) DEFAULT NULL,
  `sla_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sla_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sla_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software`
--

DROP TABLE IF EXISTS `software`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software` (
  `software_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_name` varchar(200) NOT NULL,
  `software_description` text DEFAULT NULL,
  `software_version` varchar(200) DEFAULT NULL,
  `software_type` varchar(200) NOT NULL,
  `software_license_type` varchar(200) DEFAULT NULL,
  `software_key` varchar(200) DEFAULT NULL,
  `software_seats` int(11) DEFAULT NULL,
  `software_purchase_reference` varchar(200) DEFAULT NULL,
  `software_purchase` date DEFAULT NULL,
  `software_expire` date DEFAULT NULL,
  `software_notes` text DEFAULT NULL,
  `software_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `software_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `software_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `software_archived_at` datetime DEFAULT NULL,
  `software_accessed_at` datetime DEFAULT NULL,
  `software_vendor_id` int(11) DEFAULT 0,
  `software_client_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_assets`
--

DROP TABLE IF EXISTS `software_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_assets` (
  `software_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `software_assets_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_assets_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_contacts`
--

DROP TABLE IF EXISTS `software_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_contacts` (
  `software_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `software_contacts_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_credentials`
--

DROP TABLE IF EXISTS `software_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_credentials` (
  `software_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `software_credentials_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_documents`
--

DROP TABLE IF EXISTS `software_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_documents` (
  `software_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `software_documents_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_files`
--

DROP TABLE IF EXISTS `software_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_files` (
  `software_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`software_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `software_files_ibfk_1` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE,
  CONSTRAINT `software_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_key_asset_assignments`
--

DROP TABLE IF EXISTS `software_key_asset_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_key_asset_assignments` (
  `software_key_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `software_key_assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`software_key_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `software_key_asset_assignments_ibfk_1` FOREIGN KEY (`software_key_id`) REFERENCES `software_keys` (`software_key_id`) ON DELETE CASCADE,
  CONSTRAINT `software_key_asset_assignments_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_key_contact_assignments`
--

DROP TABLE IF EXISTS `software_key_contact_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_key_contact_assignments` (
  `software_key_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `software_key_assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`software_key_id`,`contact_id`),
  KEY `contact_id` (`contact_id`),
  CONSTRAINT `software_key_contact_assignments_ibfk_1` FOREIGN KEY (`software_key_id`) REFERENCES `software_keys` (`software_key_id`) ON DELETE CASCADE,
  CONSTRAINT `software_key_contact_assignments_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_keys`
--

DROP TABLE IF EXISTS `software_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_keys` (
  `software_key_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_key` varchar(400) NOT NULL,
  `software_key_software_id` int(11) NOT NULL,
  PRIMARY KEY (`software_key_id`),
  KEY `software_key_software_id` (`software_key_software_id`),
  CONSTRAINT `software_keys_ibfk_1` FOREIGN KEY (`software_key_software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `software_templates`
--

DROP TABLE IF EXISTS `software_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `software_templates` (
  `software_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `software_template_name` varchar(200) NOT NULL,
  `software_template_description` text DEFAULT NULL,
  `software_template_version` varchar(200) DEFAULT NULL,
  `software_template_type` varchar(200) NOT NULL,
  `software_template_license_type` varchar(200) DEFAULT NULL,
  `software_template_notes` text DEFAULT NULL,
  `software_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `software_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `software_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`software_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(200) NOT NULL,
  `tag_type` int(11) NOT NULL,
  `tag_color` varchar(200) DEFAULT NULL,
  `tag_icon` varchar(200) DEFAULT NULL,
  `tag_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tag_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tag_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_approvals`
--

DROP TABLE IF EXISTS `task_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_approvals` (
  `approval_id` int(11) NOT NULL AUTO_INCREMENT,
  `approval_scope` enum('client','internal') NOT NULL,
  `approval_type` enum('any','technical','billing','specific') NOT NULL,
  `approval_required_user_id` int(11) DEFAULT NULL,
  `approval_status` enum('pending','approved','declined') NOT NULL,
  `approval_created_by` int(11) NOT NULL,
  `approval_approved_by` varchar(255) DEFAULT NULL,
  `approval_url_key` varchar(200) NOT NULL,
  `approval_url_expires_at` datetime DEFAULT NULL,
  `approval_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approval_decided_at` datetime DEFAULT NULL,
  `approval_task_id` int(11) NOT NULL,
  PRIMARY KEY (`approval_id`),
  KEY `approval_task_status` (`approval_task_id`,`approval_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_approval_events`
--

DROP TABLE IF EXISTS `task_approval_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_approval_events` (
  `task_approval_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `task_approval_event_approval_id` int(11) NOT NULL,
  `task_approval_event_task_id` int(11) NOT NULL,
  `task_approval_event_action` varchar(30) NOT NULL,
  `task_approval_event_from_status` varchar(20) DEFAULT NULL,
  `task_approval_event_to_status` varchar(20) DEFAULT NULL,
  `task_approval_event_from_scope` varchar(20) DEFAULT NULL,
  `task_approval_event_to_scope` varchar(20) DEFAULT NULL,
  `task_approval_event_from_type` varchar(20) DEFAULT NULL,
  `task_approval_event_to_type` varchar(20) DEFAULT NULL,
  `task_approval_event_from_required_user_id` int(11) NOT NULL DEFAULT 0,
  `task_approval_event_to_required_user_id` int(11) NOT NULL DEFAULT 0,
  `task_approval_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `task_approval_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `task_approval_event_actor_label` varchar(255) DEFAULT NULL,
  `task_approval_event_reason` varchar(255) DEFAULT NULL,
  `task_approval_event_request_expires_at` datetime DEFAULT NULL,
  `task_approval_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`task_approval_event_id`),
  KEY `task_approval_event_approval` (`task_approval_event_approval_id`,`task_approval_event_id`),
  KEY `task_approval_event_task` (`task_approval_event_task_id`,`task_approval_event_created_at`,`task_approval_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_dependencies`
--

DROP TABLE IF EXISTS `task_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_dependencies` (
  `task_id` int(11) NOT NULL,
  `depends_on_task_id` int(11) NOT NULL,
  PRIMARY KEY (`task_id`,`depends_on_task_id`),
  KEY `depends_on_task_id` (`depends_on_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_evidence`
--

DROP TABLE IF EXISTS `task_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_evidence` (
  `task_evidence_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `task_evidence_task_id` int(11) NOT NULL,
  `task_evidence_type` varchar(20) NOT NULL,
  `task_evidence_note` text DEFAULT NULL,
  `task_evidence_url` varchar(1000) DEFAULT NULL,
  `task_evidence_attachment_id` int(11) NOT NULL DEFAULT 0,
  `task_evidence_submitted_by` int(11) NOT NULL DEFAULT 0,
  `task_evidence_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`task_evidence_id`),
  KEY `task_evidence_task` (`task_evidence_task_id`,`task_evidence_type`),
  KEY `task_evidence_attachment` (`task_evidence_attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_state_events`
--

DROP TABLE IF EXISTS `task_state_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_state_events` (
  `task_state_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `task_state_event_task_id` int(11) NOT NULL,
  `task_state_event_from_state` varchar(20) DEFAULT NULL,
  `task_state_event_to_state` varchar(20) NOT NULL,
  `task_state_event_reason` varchar(255) DEFAULT NULL,
  `task_state_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `task_state_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `task_state_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`task_state_event_id`),
  KEY `task_state_event_task` (`task_state_event_task_id`,`task_state_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_template_dependencies`
--

DROP TABLE IF EXISTS `task_template_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_template_dependencies` (
  `task_template_id` int(11) NOT NULL,
  `depends_on_task_template_id` int(11) NOT NULL,
  PRIMARY KEY (`task_template_id`,`depends_on_task_template_id`),
  KEY `depends_on_task_template_id` (`depends_on_task_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `task_templates`
--

DROP TABLE IF EXISTS `task_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `task_templates` (
  `task_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_template_name` varchar(200) NOT NULL,
  `task_template_key` varchar(100) DEFAULT NULL,
  `task_template_instructions` text DEFAULT NULL,
  `task_template_order` int(11) NOT NULL DEFAULT 0,
  `task_template_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `task_template_condition_type` varchar(40) NOT NULL DEFAULT 'always',
  `task_template_condition_value` varchar(255) DEFAULT NULL,
  `task_template_owner_type` varchar(40) NOT NULL DEFAULT 'unassigned',
  `task_template_owner_user_id` int(11) NOT NULL DEFAULT 0,
  `task_template_due_offset_minutes` int(11) NOT NULL DEFAULT 0,
  `task_template_initial_state` varchar(20) NOT NULL DEFAULT 'Ready',
  `task_template_approval_scope` varchar(20) DEFAULT NULL,
  `task_template_approval_type` varchar(20) DEFAULT NULL,
  `task_template_approval_user_id` int(11) NOT NULL DEFAULT 0,
  `task_template_evidence_type` varchar(20) NOT NULL DEFAULT 'none',
  `task_template_evidence_prompt` varchar(255) DEFAULT NULL,
  `task_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `task_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `task_template_archived_at` datetime DEFAULT NULL,
  `task_template_ticket_template_id` int(11) NOT NULL,
  PRIMARY KEY (`task_template_id`),
  UNIQUE KEY `task_template_key_unique` (`task_template_ticket_template_id`,`task_template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tasks`
--

DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_name` varchar(255) NOT NULL,
  `task_instructions` text DEFAULT NULL,
  `task_status` varchar(255) DEFAULT NULL,
  `task_state` varchar(20) NOT NULL DEFAULT 'Ready',
  `task_order` int(11) NOT NULL DEFAULT 0,
  `task_completion_estimate` int(11) NOT NULL DEFAULT 0,
  `task_assigned_to` int(11) NOT NULL DEFAULT 0,
  `task_due_at` datetime DEFAULT NULL,
  `task_waiting_reason` varchar(255) DEFAULT NULL,
  `task_condition_result` varchar(20) NOT NULL DEFAULT 'Matched',
  `task_evidence_required` varchar(20) NOT NULL DEFAULT 'none',
  `task_evidence_prompt` varchar(255) DEFAULT NULL,
  `task_runbook_version_task_id` bigint(20) NOT NULL DEFAULT 0,
  `task_completed_at` datetime DEFAULT NULL,
  `task_completed_by` int(11) DEFAULT NULL,
  `task_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `task_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `task_ticket_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`task_id`),
  KEY `task_runbook_state` (`task_ticket_id`,`task_state`,`task_due_at`),
  KEY `task_assigned_to` (`task_assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `taxes`
--

DROP TABLE IF EXISTS `taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taxes` (
  `tax_id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_name` varchar(200) NOT NULL,
  `tax_percent` float NOT NULL,
  `tax_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tax_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `tax_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tax_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_assets`
--

DROP TABLE IF EXISTS `ticket_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_assets` (
  `ticket_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `ticket_assets_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_assets_ibfk_2` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_attachments`
--

DROP TABLE IF EXISTS `ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_attachments` (
  `ticket_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_attachment_name` varchar(255) NOT NULL,
  `ticket_attachment_reference_name` varchar(255) NOT NULL,
  `ticket_attachment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_attachment_ticket_id` int(11) NOT NULL,
  `ticket_attachment_reply_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`ticket_attachment_id`),
  KEY `ticket_attachment_reply_id` (`ticket_attachment_reply_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_history`
--

DROP TABLE IF EXISTS `ticket_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_history` (
  `ticket_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_history_status` varchar(200) NOT NULL,
  `ticket_history_description` varchar(255) NOT NULL,
  `ticket_history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_history_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_replies`
--

DROP TABLE IF EXISTS `ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_replies` (
  `ticket_reply_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_reply` longtext NOT NULL,
  `ticket_reply_type` varchar(10) NOT NULL,
  `ticket_reply_time_worked` time DEFAULT NULL,
  `ticket_reply_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_reply_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_reply_archived_at` datetime DEFAULT NULL,
  `ticket_reply_by` int(11) NOT NULL,
  `ticket_reply_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`ticket_reply_id`),
  KEY `ticket_reply_ticket_id` (`ticket_reply_ticket_id`,`ticket_reply_archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_statuses`
--

DROP TABLE IF EXISTS `ticket_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_statuses` (
  `ticket_status_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_status_name` varchar(200) NOT NULL,
  `ticket_status_color` varchar(200) NOT NULL,
  `ticket_status_active` tinyint(1) NOT NULL DEFAULT 1,
  `ticket_status_pauses_sla` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_status_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_status_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Ticket documentation links and their append-only waiver decisions.

DROP TABLE IF EXISTS `ticket_documentation_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_documentation_obligations` (
  `ticket_documentation_obligation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_documentation_obligation_ticket_id` int(11) NOT NULL,
  `ticket_documentation_obligation_obligation_id` bigint(20) NOT NULL,
  `ticket_documentation_obligation_client_id` int(11) NOT NULL,
  `ticket_documentation_obligation_task_id` int(11) NOT NULL DEFAULT 0,
  `ticket_documentation_obligation_blocks_resolution` tinyint(1) NOT NULL DEFAULT 1,
  `ticket_documentation_obligation_linked_by` int(11) NOT NULL DEFAULT 0,
  `ticket_documentation_obligation_linked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_documentation_obligation_revision` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ticket_documentation_obligation_id`),
  UNIQUE KEY `ticket_documentation_obligation_identity` (`ticket_documentation_obligation_ticket_id`,`ticket_documentation_obligation_obligation_id`),
  KEY `ticket_documentation_obligation_gate` (`ticket_documentation_obligation_ticket_id`,`ticket_documentation_obligation_blocks_resolution`),
  KEY `ticket_documentation_obligation_client` (`ticket_documentation_obligation_client_id`,`ticket_documentation_obligation_obligation_id`),
  KEY `ticket_documentation_obligation_task` (`ticket_documentation_obligation_task_id`,`ticket_documentation_obligation_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_documentation_waivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_documentation_waivers` (
  `ticket_documentation_waiver_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_documentation_waiver_link_id` bigint(20) NOT NULL,
  `ticket_documentation_waiver_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `ticket_documentation_waiver_reason_redacted` varchar(255) NOT NULL,
  `ticket_documentation_waiver_reason_hash` char(64) NOT NULL,
  `ticket_documentation_waiver_requested_by` int(11) NOT NULL,
  `ticket_documentation_waiver_requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_documentation_waiver_decided_by` int(11) NOT NULL DEFAULT 0,
  `ticket_documentation_waiver_decided_at` datetime DEFAULT NULL,
  `ticket_documentation_waiver_expires_at` datetime NOT NULL,
  `ticket_documentation_waiver_revision` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ticket_documentation_waiver_id`),
  KEY `ticket_documentation_waiver_link` (`ticket_documentation_waiver_link_id`,`ticket_documentation_waiver_status`,`ticket_documentation_waiver_expires_at`),
  KEY `ticket_documentation_waiver_expiry` (`ticket_documentation_waiver_status`,`ticket_documentation_waiver_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ticket_documentation_waiver_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_documentation_waiver_events` (
  `ticket_documentation_waiver_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_documentation_waiver_event_waiver_id` bigint(20) NOT NULL,
  `ticket_documentation_waiver_event_link_id` bigint(20) NOT NULL,
  `ticket_documentation_waiver_event_action` varchar(30) NOT NULL,
  `ticket_documentation_waiver_event_from_status` varchar(20) DEFAULT NULL,
  `ticket_documentation_waiver_event_to_status` varchar(20) NOT NULL,
  `ticket_documentation_waiver_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `ticket_documentation_waiver_event_reason_code` varchar(60) NOT NULL,
  `ticket_documentation_waiver_event_context_hash` char(64) DEFAULT NULL,
  `ticket_documentation_waiver_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_documentation_waiver_event_id`),
  KEY `ticket_documentation_waiver_event_history` (`ticket_documentation_waiver_event_waiver_id`,`ticket_documentation_waiver_event_created_at`,`ticket_documentation_waiver_event_id`),
  KEY `ticket_documentation_waiver_event_link` (`ticket_documentation_waiver_event_link_id`,`ticket_documentation_waiver_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_templates`
--

DROP TABLE IF EXISTS `ticket_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_templates` (
  `ticket_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_template_name` varchar(200) NOT NULL,
  `ticket_template_description` text DEFAULT NULL,
  `ticket_template_subject` varchar(500) DEFAULT NULL,
  `ticket_template_details` longtext DEFAULT NULL,
  `ticket_template_runbook_key` varchar(100) DEFAULT NULL,
  `ticket_template_runbook_type` varchar(20) NOT NULL DEFAULT 'standard',
  `ticket_template_published_version_id` bigint(20) NOT NULL DEFAULT 0,
  `ticket_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_template_id`),
  UNIQUE KEY `ticket_template_runbook_key_unique` (`ticket_template_runbook_key`),
  KEY `ticket_template_published_version` (`ticket_template_published_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_views`
--

DROP TABLE IF EXISTS `ticket_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_views` (
  `view_id` int(11) NOT NULL AUTO_INCREMENT,
  `view_ticket_id` int(11) NOT NULL,
  `view_user_id` int(11) NOT NULL,
  `view_timestamp` datetime NOT NULL,
  PRIMARY KEY (`view_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_watchers`
--

DROP TABLE IF EXISTS `ticket_watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_watchers` (
  `watcher_id` int(11) NOT NULL AUTO_INCREMENT,
  `watcher_name` varchar(255) DEFAULT NULL,
  `watcher_email` varchar(255) NOT NULL,
  `watcher_ticket_id` int(11) NOT NULL,
  PRIMARY KEY (`watcher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_operational_events`
--

DROP TABLE IF EXISTS `ticket_operational_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_operational_events` (
  `ticket_operational_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_operational_event_ticket_id` int(11) NOT NULL,
  `ticket_operational_event_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_operational_event_action` varchar(40) NOT NULL,
  `ticket_operational_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `ticket_operational_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `ticket_operational_event_payload` longtext NOT NULL,
  `ticket_operational_event_payload_hash` char(64) NOT NULL,
  `ticket_operational_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_operational_event_id`),
  KEY `ticket_operational_event_ticket` (`ticket_operational_event_ticket_id`,`ticket_operational_event_id`),
  KEY `ticket_operational_event_client` (`ticket_operational_event_client_id`,`ticket_operational_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_relationships`
--

DROP TABLE IF EXISTS `ticket_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_relationships` (
  `ticket_relationship_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_relationship_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_relationship_type` varchar(20) NOT NULL,
  `ticket_relationship_source_ticket_id` int(11) NOT NULL,
  `ticket_relationship_target_ticket_id` int(11) NOT NULL,
  `ticket_relationship_created_by` int(11) NOT NULL DEFAULT 0,
  `ticket_relationship_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_relationship_id`),
  UNIQUE KEY `ticket_relationship_pair` (`ticket_relationship_type`,`ticket_relationship_source_ticket_id`,`ticket_relationship_target_ticket_id`),
  KEY `ticket_relationship_source` (`ticket_relationship_source_ticket_id`,`ticket_relationship_type`),
  KEY `ticket_relationship_target` (`ticket_relationship_target_ticket_id`,`ticket_relationship_type`),
  KEY `ticket_relationship_client` (`ticket_relationship_client_id`,`ticket_relationship_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_customer_promises`
--

DROP TABLE IF EXISTS `ticket_customer_promises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_customer_promises` (
  `ticket_customer_promise_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_customer_promise_ticket_id` int(11) NOT NULL,
  `ticket_customer_promise_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_type` varchar(30) NOT NULL DEFAULT 'customer_update',
  `ticket_customer_promise_summary` varchar(500) NOT NULL,
  `ticket_customer_promise_due_at` datetime NOT NULL,
  `ticket_customer_promise_status` varchar(20) NOT NULL DEFAULT 'Open',
  `ticket_customer_promise_promised_by` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_promised_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_customer_promise_source_type` varchar(20) NOT NULL DEFAULT 'agent',
  `ticket_customer_promise_source_id` bigint(20) NOT NULL DEFAULT 0,
  `ticket_customer_promise_fulfilled_by` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_fulfilled_at` datetime DEFAULT NULL,
  `ticket_customer_promise_breached_at` datetime DEFAULT NULL,
  `ticket_customer_promise_cancelled_by` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_customer_promise_id`),
  KEY `ticket_customer_promise_queue` (`ticket_customer_promise_status`,`ticket_customer_promise_due_at`),
  KEY `ticket_customer_promise_ticket` (`ticket_customer_promise_ticket_id`,`ticket_customer_promise_status`,`ticket_customer_promise_due_at`),
  KEY `ticket_customer_promise_client` (`ticket_customer_promise_client_id`,`ticket_customer_promise_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ticket_customer_promise_events`
--

DROP TABLE IF EXISTS `ticket_customer_promise_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_customer_promise_events` (
  `ticket_customer_promise_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_customer_promise_event_promise_id` bigint(20) NOT NULL,
  `ticket_customer_promise_event_ticket_id` int(11) NOT NULL,
  `ticket_customer_promise_event_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_event_action` varchar(30) NOT NULL,
  `ticket_customer_promise_event_from_status` varchar(20) DEFAULT NULL,
  `ticket_customer_promise_event_to_status` varchar(20) NOT NULL,
  `ticket_customer_promise_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
  `ticket_customer_promise_event_actor_id` int(11) NOT NULL DEFAULT 0,
  `ticket_customer_promise_event_source_type` varchar(20) DEFAULT NULL,
  `ticket_customer_promise_event_source_id` bigint(20) NOT NULL DEFAULT 0,
  `ticket_customer_promise_event_context_hash` char(64) NOT NULL,
  `ticket_customer_promise_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ticket_customer_promise_event_id`),
  KEY `ticket_customer_promise_event_promise` (`ticket_customer_promise_event_promise_id`,`ticket_customer_promise_event_id`),
  KEY `ticket_customer_promise_event_ticket` (`ticket_customer_promise_event_ticket_id`,`ticket_customer_promise_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

CREATE TRIGGER `ticket_operational_events_bu_immutable`
  BEFORE UPDATE ON `ticket_operational_events` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only';

CREATE TRIGGER `ticket_operational_events_bd_immutable`
  BEFORE DELETE ON `ticket_operational_events` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only';

CREATE TRIGGER `ticket_customer_promise_events_bu_immutable`
  BEFORE UPDATE ON `ticket_customer_promise_events` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only';

CREATE TRIGGER `ticket_customer_promise_events_bd_immutable`
  BEFORE DELETE ON `ticket_customer_promise_events` FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only';

--
-- Table structure for table `ticket_email_ingress`
--

DROP TABLE IF EXISTS `ticket_email_ingress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_email_ingress` (
  `ticket_email_ingress_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_email_ingress_message_hash` char(64) NOT NULL,
  `ticket_email_ingress_claim_token` char(64) NOT NULL,
  `ticket_email_ingress_sender_hash` char(64) NOT NULL,
  `ticket_email_ingress_domain_hash` char(64) NOT NULL,
  `ticket_email_ingress_subject_hash` char(64) NOT NULL,
  `ticket_email_ingress_status` varchar(20) NOT NULL DEFAULT 'Processing',
  `ticket_email_ingress_attempts` int(11) NOT NULL DEFAULT 1,
  `ticket_email_ingress_ticket_id` int(11) NOT NULL DEFAULT 0,
  `ticket_email_ingress_reply_id` int(11) NOT NULL DEFAULT 0,
  `ticket_email_ingress_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_email_ingress_reason_code` varchar(60) DEFAULT NULL,
  `ticket_email_ingress_received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_email_ingress_processing_at` datetime DEFAULT NULL,
  `ticket_email_ingress_completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ticket_email_ingress_id`),
  UNIQUE KEY `ticket_email_ingress_message` (`ticket_email_ingress_message_hash`),
  KEY `ticket_email_ingress_status` (`ticket_email_ingress_status`,`ticket_email_ingress_processing_at`),
  KEY `ticket_email_ingress_sender_window` (`ticket_email_ingress_sender_hash`,`ticket_email_ingress_received_at`),
  KEY `ticket_email_ingress_domain_window` (`ticket_email_ingress_domain_hash`,`ticket_email_ingress_received_at`),
  KEY `ticket_email_ingress_client_window` (`ticket_email_ingress_client_id`,`ticket_email_ingress_received_at`),
  KEY `ticket_email_ingress_ticket` (`ticket_email_ingress_ticket_id`,`ticket_email_ingress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_prefix` varchar(200) DEFAULT NULL,
  `ticket_number` int(11) NOT NULL,
  `ticket_source` varchar(255) DEFAULT NULL COMMENT 'Where the Ticket Came from\r\nEmail, Client Portal, In-App, Project Template',
  `ticket_category` varchar(200) DEFAULT NULL,
  `ticket_request_type_key` varchar(100) NOT NULL DEFAULT '*',
  `ticket_work_type` varchar(20) NOT NULL DEFAULT 'request',
  `ticket_impact` varchar(20) NOT NULL DEFAULT 'low',
  `ticket_urgency` varchar(20) NOT NULL DEFAULT 'low',
  `ticket_next_action` varchar(500) NOT NULL DEFAULT 'Review and triage this ticket.',
  `ticket_next_action_due_at` datetime DEFAULT NULL,
  `ticket_waiting_on` varchar(20) NOT NULL DEFAULT 'none',
  `ticket_waiting_on_detail` varchar(255) DEFAULT NULL,
  `ticket_resolution_code` varchar(30) DEFAULT NULL,
  `ticket_resolution_summary` text DEFAULT NULL,
  `ticket_root_cause` text DEFAULT NULL,
  `ticket_operational_updated_by` int(11) NOT NULL DEFAULT 0,
  `ticket_operational_updated_at` datetime DEFAULT NULL,
  `ticket_subject` varchar(500) NOT NULL,
  `ticket_details` longtext NOT NULL,
  `ticket_priority` varchar(200) DEFAULT NULL,
  `ticket_status` int(11) NOT NULL,
  `ticket_sla_id` int(11) NOT NULL DEFAULT 0,
  `ticket_sla_response_minutes_snapshot` int(11) DEFAULT NULL,
  `ticket_sla_resolution_minutes_snapshot` int(11) DEFAULT NULL,
  `ticket_sla_calendar_mode` varchar(20) DEFAULT NULL,
  `ticket_sla_business_days` varchar(20) DEFAULT NULL,
  `ticket_sla_business_hours_start` time DEFAULT NULL,
  `ticket_sla_business_hours_end` time DEFAULT NULL,
  `ticket_sla_timezone` varchar(64) DEFAULT NULL,
  `ticket_billable` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_schedule` datetime DEFAULT NULL,
  `ticket_onsite` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_configuration_change` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_documentation_impact` varchar(20) NOT NULL DEFAULT 'Unassessed',
  `ticket_documentation_assessed_by` int(11) NOT NULL DEFAULT 0,
  `ticket_documentation_assessed_at` datetime DEFAULT NULL,
  `ticket_vendor_ticket_number` varchar(255) DEFAULT NULL,
  `ticket_feedback` varchar(200) DEFAULT NULL,
  `ticket_url_key` varchar(200) DEFAULT NULL,
  `ticket_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ticket_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `ticket_due_at` datetime DEFAULT NULL,
  `ticket_resolved_at` datetime DEFAULT NULL,
  `ticket_archived_at` datetime DEFAULT NULL,
  `ticket_first_response_at` datetime DEFAULT NULL,
  `ticket_response_due_at` datetime DEFAULT NULL,
  `ticket_response_due_at_utc` datetime DEFAULT NULL,
  `ticket_resolution_due_at` datetime DEFAULT NULL,
  `ticket_resolution_due_at_utc` datetime DEFAULT NULL,
  `ticket_response_sla_met` tinyint(1) DEFAULT NULL,
  `ticket_resolution_sla_met` tinyint(1) DEFAULT NULL,
  `ticket_response_sla_alert_stage` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_resolution_sla_alert_stage` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_closed_at` datetime DEFAULT NULL,
  `ticket_created_by` int(11) NOT NULL,
  `ticket_assigned_to` int(11) NOT NULL DEFAULT 0,
  `ticket_closed_by` int(11) NOT NULL DEFAULT 0,
  `ticket_vendor_id` int(11) NOT NULL DEFAULT 0,
  `ticket_client_id` int(11) NOT NULL DEFAULT 0,
  `ticket_contact_id` int(11) NOT NULL DEFAULT 0,
  `ticket_location_id` int(11) NOT NULL DEFAULT 0,
  `ticket_asset_id` int(11) NOT NULL DEFAULT 0,
  `ticket_quote_id` int(11) NOT NULL DEFAULT 0,
  `ticket_invoice_id` int(11) NOT NULL DEFAULT 0,
  `ticket_project_id` int(11) NOT NULL DEFAULT 0,
  `ticket_recurring_ticket_id` int(11) DEFAULT 0,
  `ticket_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_id`),
  KEY `ticket_response_due_at` (`ticket_response_due_at`),
  KEY `ticket_resolution_due_at` (`ticket_resolution_due_at`),
  KEY `ticket_response_due_at_utc` (`ticket_response_due_at_utc`),
  KEY `ticket_resolution_due_at_utc` (`ticket_resolution_due_at_utc`),
  KEY `ticket_work_type_status` (`ticket_work_type`,`ticket_status`),
  KEY `ticket_waiting_queue` (`ticket_waiting_on`,`ticket_next_action_due_at`),
  KEY `ticket_operational_priority` (`ticket_impact`,`ticket_urgency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `level_group_mappings`
--

DROP TABLE IF EXISTS `level_group_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_group_mappings` (
  `level_group_mapping_id` int(11) NOT NULL AUTO_INCREMENT,
  `level_group_id` varchar(255) NOT NULL,
  `level_group_name` varchar(255) NOT NULL,
  `level_parent_group_id` varchar(255) DEFAULT NULL,
  `level_group_device_count` int(11) NOT NULL DEFAULT 0,
  `level_group_descendent_device_count` int(11) NOT NULL DEFAULT 0,
  `level_group_client_id` int(11) NOT NULL DEFAULT 0,
  `level_group_last_seen_at` datetime DEFAULT NULL,
  `level_group_deleted_at` datetime DEFAULT NULL,
  `level_group_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_group_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`level_group_mapping_id`),
  UNIQUE KEY `level_group_id` (`level_group_id`),
  KEY `level_group_client_id` (`level_group_client_id`),
  KEY `level_parent_group_id` (`level_parent_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `level_asset_links`
--

DROP TABLE IF EXISTS `level_asset_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_asset_links` (
  `level_asset_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `level_device_id` varchar(255) NOT NULL,
  `level_asset_id` int(11) NOT NULL,
  `level_group_id` varchar(255) DEFAULT NULL,
  `level_device_hostname` varchar(255) NOT NULL,
  `level_device_online` tinyint(1) NOT NULL DEFAULT 0,
  `level_device_last_seen_at` datetime DEFAULT NULL,
  `level_device_security_score` int(11) DEFAULT NULL,
  `level_device_snapshot` longtext DEFAULT NULL,
  `level_device_sync_status` varchar(20) NOT NULL DEFAULT 'Synced',
  `level_device_sync_message` varchar(255) DEFAULT NULL,
  `level_device_last_synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_device_deleted_at` datetime DEFAULT NULL,
  `level_asset_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_asset_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`level_asset_link_id`),
  UNIQUE KEY `level_device_id` (`level_device_id`),
  UNIQUE KEY `level_asset_id` (`level_asset_id`),
  KEY `level_group_id` (`level_group_id`),
  CONSTRAINT `level_asset_links_asset_fk` FOREIGN KEY (`level_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `level_interface_links`
--

DROP TABLE IF EXISTS `level_interface_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_interface_links` (
  `level_interface_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `level_device_id` varchar(255) NOT NULL,
  `level_interface_key` varchar(255) NOT NULL,
  `level_asset_interface_id` int(11) NOT NULL,
  `level_interface_last_seen_at` datetime DEFAULT NULL,
  `level_interface_deleted_at` datetime DEFAULT NULL,
  `level_interface_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_interface_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`level_interface_link_id`),
  UNIQUE KEY `level_device_interface` (`level_device_id`,`level_interface_key`),
  UNIQUE KEY `level_asset_interface_id` (`level_asset_interface_id`),
  CONSTRAINT `level_interface_links_interface_fk` FOREIGN KEY (`level_asset_interface_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `level_alert_links`
--

DROP TABLE IF EXISTS `level_alert_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_alert_links` (
  `level_alert_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `level_alert_id` varchar(255) NOT NULL,
  `level_device_id` varchar(255) NOT NULL,
  `level_ticket_id` int(11) DEFAULT NULL,
  `level_asset_id` int(11) DEFAULT NULL,
  `level_alert_name` varchar(255) NOT NULL,
  `level_alert_severity` varchar(20) NOT NULL,
  `level_alert_started_at` datetime DEFAULT NULL,
  `level_alert_resolved_at` datetime DEFAULT NULL,
  `level_alert_last_event_at` datetime DEFAULT NULL,
  `level_alert_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_alert_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`level_alert_link_id`),
  UNIQUE KEY `level_alert_id` (`level_alert_id`),
  UNIQUE KEY `level_ticket_id` (`level_ticket_id`),
  KEY `level_device_id` (`level_device_id`),
  KEY `level_asset_id` (`level_asset_id`),
  CONSTRAINT `level_alert_links_ticket_fk` FOREIGN KEY (`level_ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE SET NULL,
  CONSTRAINT `level_alert_links_asset_fk` FOREIGN KEY (`level_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `level_webhook_events`
--

DROP TABLE IF EXISTS `level_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `level_webhook_events` (
  `level_webhook_event_id` varchar(64) NOT NULL,
  `level_webhook_event_type` varchar(40) NOT NULL,
  `level_webhook_occurred_at` datetime DEFAULT NULL,
  `level_webhook_payload` longtext NOT NULL,
  `level_webhook_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `level_webhook_delivery_count` int(11) NOT NULL DEFAULT 1,
  `level_webhook_process_attempts` int(11) NOT NULL DEFAULT 0,
  `level_webhook_last_error` text DEFAULT NULL,
  `level_webhook_received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_webhook_last_received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `level_webhook_processing_at` datetime DEFAULT NULL,
  `level_webhook_processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`level_webhook_event_id`),
  KEY `level_webhook_status_received` (`level_webhook_status`,`level_webhook_received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transfers`
--

DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_method` varchar(200) DEFAULT NULL,
  `transfer_notes` text DEFAULT NULL,
  `transfer_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `transfer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `transfer_archived_at` datetime DEFAULT NULL,
  `transfer_expense_id` int(11) NOT NULL,
  `transfer_revenue_id` int(11) NOT NULL,
  PRIMARY KEY (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trips`
--

DROP TABLE IF EXISTS `trips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trips` (
  `trip_id` int(11) NOT NULL AUTO_INCREMENT,
  `trip_date` date NOT NULL,
  `trip_purpose` varchar(200) NOT NULL,
  `trip_source` varchar(200) NOT NULL,
  `trip_destination` varchar(200) NOT NULL,
  `trip_start_odometer` int(11) DEFAULT NULL,
  `trip_end_odmeter` int(11) DEFAULT NULL,
  `trip_miles` float(15,1) NOT NULL,
  `round_trip` int(1) NOT NULL,
  `trip_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `trip_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `trip_archived_at` datetime DEFAULT NULL,
  `trip_user_id` int(11) NOT NULL DEFAULT 0,
  `trip_client_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`trip_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_client_permissions`
--

DROP TABLE IF EXISTS `user_client_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_client_permissions` (
  `user_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `permission_type` enum('allow','deny') NOT NULL DEFAULT 'allow',
  PRIMARY KEY (`user_id`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_role_permissions`
--

DROP TABLE IF EXISTS `user_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_role_permissions` (
  `user_role_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `user_role_permission_level` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(200) NOT NULL,
  `role_description` varchar(200) DEFAULT NULL,
  `role_type` tinyint(1) NOT NULL DEFAULT 1,
  `role_is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `role_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `role_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `role_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_settings`
--

DROP TABLE IF EXISTS `user_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `user_config_force_mfa` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_records_per_page` int(11) NOT NULL DEFAULT 10,
  `user_config_dashboard_financial_enable` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_dashboard_technical_enable` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_calendar_first_day` tinyint(1) NOT NULL DEFAULT 0,
  `user_config_signature` text DEFAULT NULL,
  `user_config_theme_dark` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(200) NOT NULL,
  `user_email` varchar(200) NOT NULL,
  `user_password` varchar(200) NOT NULL,
  `user_auth_method` varchar(200) NOT NULL DEFAULT 'local',
  `user_azure_oid` varchar(36) DEFAULT NULL,
  `user_azure_tenant_id` varchar(36) DEFAULT NULL,
  `user_type` tinyint(1) NOT NULL DEFAULT 1,
  `user_status` tinyint(1) NOT NULL DEFAULT 1,
  `user_token` varchar(200) DEFAULT NULL,
  `user_password_reset_token` varchar(200) DEFAULT NULL,
  `user_password_reset_token_expire` datetime DEFAULT NULL,
  `user_avatar` varchar(200) DEFAULT NULL,
  `user_specific_encryption_ciphertext` varchar(200) DEFAULT NULL,
  `user_php_session` varchar(255) DEFAULT NULL,
  `user_extension_key` varchar(18) DEFAULT NULL,
  `user_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `user_archived_at` datetime DEFAULT NULL,
  `user_role_id` int(11) DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `user_azure_identity` (`user_azure_tenant_id`,`user_azure_oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_credentials`
--

DROP TABLE IF EXISTS `vendor_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_credentials` (
  `vendor_id` int(11) NOT NULL,
  `credential_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`credential_id`),
  KEY `credential_id` (`credential_id`),
  CONSTRAINT `vendor_credentials_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_credentials_ibfk_2` FOREIGN KEY (`credential_id`) REFERENCES `credentials` (`credential_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_documents`
--

DROP TABLE IF EXISTS `vendor_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_documents` (
  `vendor_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`document_id`),
  KEY `document_id` (`document_id`),
  CONSTRAINT `vendor_documents_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_files`
--

DROP TABLE IF EXISTS `vendor_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_files` (
  `vendor_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  PRIMARY KEY (`vendor_id`,`file_id`),
  KEY `file_id` (`file_id`),
  CONSTRAINT `vendor_files_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE,
  CONSTRAINT `vendor_files_ibfk_2` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendor_templates`
--

DROP TABLE IF EXISTS `vendor_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendor_templates` (
  `vendor_template_id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_template_name` varchar(200) NOT NULL,
  `vendor_template_description` varchar(200) DEFAULT NULL,
  `vendor_template_contact_name` varchar(200) DEFAULT NULL,
  `vendor_template_phone_country_code` varchar(10) DEFAULT NULL,
  `vendor_template_phone` varchar(200) DEFAULT NULL,
  `vendor_template_extension` varchar(200) DEFAULT NULL,
  `vendor_template_email` varchar(200) DEFAULT NULL,
  `vendor_template_website` varchar(200) DEFAULT NULL,
  `vendor_template_hours` varchar(200) DEFAULT NULL,
  `vendor_template_sla` varchar(200) DEFAULT NULL,
  `vendor_template_code` varchar(200) DEFAULT NULL,
  `vendor_template_account_number` varchar(200) DEFAULT NULL,
  `vendor_template_notes` text DEFAULT NULL,
  `vendor_template_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `vendor_template_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `vendor_template_archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`vendor_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vendors` (
  `vendor_id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_name` varchar(200) NOT NULL,
  `vendor_description` varchar(200) DEFAULT NULL,
  `vendor_contact_name` varchar(200) DEFAULT NULL,
  `vendor_phone_country_code` varchar(10) DEFAULT NULL,
  `vendor_phone` varchar(200) DEFAULT NULL,
  `vendor_extension` varchar(200) DEFAULT NULL,
  `vendor_email` varchar(200) DEFAULT NULL,
  `vendor_website` varchar(200) DEFAULT NULL,
  `vendor_hours` varchar(200) DEFAULT NULL,
  `vendor_sla` varchar(200) DEFAULT NULL,
  `vendor_code` varchar(200) DEFAULT NULL,
  `vendor_account_number` varchar(200) DEFAULT NULL,
  `vendor_notes` text DEFAULT NULL,
  `vendor_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `vendor_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `vendor_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `vendor_archived_at` datetime DEFAULT NULL,
  `vendor_accessed_at` datetime DEFAULT NULL,
  `vendor_client_id` int(11) NOT NULL DEFAULT 0,
  `vendor_template_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-09 13:29:14
