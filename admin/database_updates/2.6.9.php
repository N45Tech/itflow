<?php

/*
 * ITFlow - Database update to version 2.6.9 (from 2.6.8)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

mysqli_query($mysqli, "ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `config_level_enable` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_azure_agent_sso_enable`,
    ADD COLUMN IF NOT EXISTS `config_level_api_key` varchar(255) DEFAULT NULL AFTER `config_level_enable`,
    ADD COLUMN IF NOT EXISTS `config_level_webhook_secret` varchar(255) DEFAULT NULL AFTER `config_level_api_key`,
    ADD COLUMN IF NOT EXISTS `config_level_alert_ticket_enable` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_level_webhook_secret`,
    ADD COLUMN IF NOT EXISTS `config_level_alert_assigned_to` int(11) NOT NULL DEFAULT 0 AFTER `config_level_alert_ticket_enable`");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `level_group_mappings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `level_asset_links` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Also repairs a partially applied migration where the table was created but
// the updater stopped before the newest synchronization-state fields existed.
mysqli_query($mysqli, "ALTER TABLE `level_asset_links`
    ADD COLUMN IF NOT EXISTS `level_device_sync_status` varchar(20) NOT NULL DEFAULT 'Synced' AFTER `level_device_snapshot`,
    ADD COLUMN IF NOT EXISTS `level_device_sync_message` varchar(255) DEFAULT NULL AFTER `level_device_sync_status`");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `level_alert_links` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `level_webhook_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "ALTER TABLE `level_webhook_events`
    ADD COLUMN IF NOT EXISTS `level_webhook_processing_at` datetime DEFAULT NULL AFTER `level_webhook_last_received_at`");
