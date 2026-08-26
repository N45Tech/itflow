<?php

/*
 * ITFlow - Database update to version 2.7.0 (from 2.6.9)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

$automation_migration_queries = [
"CREATE TABLE IF NOT EXISTS `automation_entity_mappings` (
    `automation_mapping_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `automation_mapping_source` varchar(40) NOT NULL,
    `automation_mapping_entity_type` varchar(40) NOT NULL,
    `automation_mapping_external_id` varchar(255) NOT NULL,
    `automation_mapping_external_name` varchar(255) DEFAULT NULL,
    `automation_mapping_client_id` int(11) NOT NULL DEFAULT 0,
    `automation_mapping_location_id` int(11) NOT NULL DEFAULT 0,
    `automation_mapping_asset_id` int(11) NOT NULL DEFAULT 0,
    `automation_mapping_domain_id` int(11) NOT NULL DEFAULT 0,
    `automation_mapping_strategy` varchar(40) NOT NULL DEFAULT 'unresolved',
    `automation_mapping_metadata` longtext DEFAULT NULL,
    `automation_mapping_last_seen_at` datetime DEFAULT NULL,
    `automation_mapping_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_mapping_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    `automation_mapping_deleted_at` datetime DEFAULT NULL,
    PRIMARY KEY (`automation_mapping_id`),
    UNIQUE KEY `automation_mapping_source_entity_external` (`automation_mapping_source`,`automation_mapping_entity_type`,`automation_mapping_external_id`),
    KEY `automation_mapping_client` (`automation_mapping_client_id`),
    KEY `automation_mapping_location` (`automation_mapping_location_id`),
    KEY `automation_mapping_asset` (`automation_mapping_asset_id`),
    KEY `automation_mapping_domain` (`automation_mapping_domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

"CREATE TABLE IF NOT EXISTS `automation_incidents` (
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
    `automation_incident_event_count` int(11) NOT NULL DEFAULT 0,
    `automation_incident_last_event_hash` char(64) DEFAULT NULL,
    `automation_incident_metadata` longtext DEFAULT NULL,
    `automation_incident_opened_at` datetime DEFAULT NULL,
    `automation_incident_last_event_at` datetime DEFAULT NULL,
    `automation_incident_resolved_at` datetime DEFAULT NULL,
    `automation_incident_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_incident_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`automation_incident_id`),
    UNIQUE KEY `automation_incident_source_key` (`automation_incident_source`,`automation_incident_key`),
    KEY `automation_incident_ticket` (`automation_incident_ticket_id`),
    KEY `automation_incident_status_last_event` (`automation_incident_status`,`automation_incident_last_event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

"CREATE TABLE IF NOT EXISTS `automation_events` (
    `automation_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `automation_event_source` varchar(40) NOT NULL,
    `automation_event_external_id` varchar(255) NOT NULL,
    `automation_event_incident_key` varchar(255) NOT NULL,
    `automation_event_state` varchar(20) NOT NULL,
    `automation_event_action` varchar(40) NOT NULL,
    `automation_event_ticket_id` int(11) NOT NULL DEFAULT 0,
    `automation_event_payload_hash` char(64) NOT NULL,
    `automation_event_payload` longtext DEFAULT NULL,
    `automation_event_received_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_event_processed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`automation_event_id`),
    UNIQUE KEY `automation_event_source_external` (`automation_event_source`,`automation_event_external_id`),
    KEY `automation_event_incident` (`automation_event_source`,`automation_event_incident_key`),
    KEY `automation_event_ticket` (`automation_event_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($automation_migration_queries as $automation_migration_query) {
    if (!mysqli_query($mysqli, $automation_migration_query)) {
        throw new RuntimeException('Could not create the n8n automation tables: ' . mysqli_error($mysqli));
    }
}
