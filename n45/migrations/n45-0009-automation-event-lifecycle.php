<?php

/*
 * N45 migration n45-0009-automation-event-lifecycle (legacy marker 2.7.6)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `automation_events`
    ADD COLUMN IF NOT EXISTS `automation_event_fingerprint` char(64) DEFAULT NULL AFTER `automation_event_incident_key`,
    ADD COLUMN IF NOT EXISTS `automation_event_status` varchar(20) NOT NULL DEFAULT 'Processed' AFTER `automation_event_action`,
    ADD COLUMN IF NOT EXISTS `automation_event_delivery_count` int(11) NOT NULL DEFAULT 1 AFTER `automation_event_status`,
    ADD COLUMN IF NOT EXISTS `automation_event_process_attempts` int(11) NOT NULL DEFAULT 1 AFTER `automation_event_delivery_count`,
    ADD COLUMN IF NOT EXISTS `automation_event_max_attempts` int(11) NOT NULL DEFAULT 5 AFTER `automation_event_process_attempts`,
    ADD COLUMN IF NOT EXISTS `automation_event_processing_at` datetime DEFAULT NULL AFTER `automation_event_max_attempts`,
    ADD COLUMN IF NOT EXISTS `automation_event_next_attempt_at` datetime DEFAULT NULL AFTER `automation_event_processing_at`,
    ADD COLUMN IF NOT EXISTS `automation_event_last_error` text DEFAULT NULL AFTER `automation_event_next_attempt_at`,
    ADD COLUMN IF NOT EXISTS `automation_event_suppressed_reason` varchar(80) DEFAULT NULL AFTER `automation_event_last_error`,
    ADD COLUMN IF NOT EXISTS `automation_event_maintenance_window_id` bigint(20) NOT NULL DEFAULT 0 AFTER `automation_event_suppressed_reason`,
    ADD COLUMN IF NOT EXISTS `automation_event_occurred_at` datetime DEFAULT NULL AFTER `automation_event_payload`,
    ADD COLUMN IF NOT EXISTS `automation_event_last_received_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `automation_event_received_at`,
    ADD COLUMN IF NOT EXISTS `automation_event_replay_count` int(11) NOT NULL DEFAULT 0 AFTER `automation_event_processed_at`,
    ADD COLUMN IF NOT EXISTS `automation_event_replayed_at` datetime DEFAULT NULL AFTER `automation_event_replay_count`")) {
    throw new RuntimeException('Could not extend automation event lifecycle state: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `automation_incidents`
    ADD COLUMN IF NOT EXISTS `automation_incident_service_id` int(11) NOT NULL DEFAULT 0 AFTER `automation_incident_asset_id`,
    ADD COLUMN IF NOT EXISTS `automation_incident_repeat_count` int(11) NOT NULL DEFAULT 0 AFTER `automation_incident_event_count`,
    ADD COLUMN IF NOT EXISTS `automation_incident_suppressed_count` int(11) NOT NULL DEFAULT 0 AFTER `automation_incident_repeat_count`,
    ADD COLUMN IF NOT EXISTS `automation_incident_last_action` varchar(40) DEFAULT NULL AFTER `automation_incident_last_event_hash`,
    ADD COLUMN IF NOT EXISTS `automation_incident_first_event_at` datetime DEFAULT NULL AFTER `automation_incident_metadata`")) {
    throw new RuntimeException('Could not extend automation incident lifecycle state: ' . mysqli_error($mysqli));
}

$automation_index_exists = static function (string $table_name, string $index_name) use ($mysqli): bool {
    $table_name_sql = mysqli_real_escape_string($mysqli, $table_name);
    $index_name_sql = mysqli_real_escape_string($mysqli, $index_name);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = '$table_name_sql'
        AND index_name = '$index_name_sql'"));
    return intval($row[0] ?? 0) > 0;
};

if (!$automation_index_exists('automation_events', 'automation_event_fingerprint')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_events`
        ADD KEY `automation_event_fingerprint`
        (`automation_event_source`,`automation_event_incident_key`,`automation_event_fingerprint`)")) {
    throw new RuntimeException('Could not index automation event fingerprints: ' . mysqli_error($mysqli));
}

if (!$automation_index_exists('automation_events', 'automation_event_queue')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_events`
        ADD KEY `automation_event_queue`
        (`automation_event_status`,`automation_event_next_attempt_at`,`automation_event_received_at`)")) {
    throw new RuntimeException('Could not index the automation event queue: ' . mysqli_error($mysqli));
}

if (!$automation_index_exists('automation_incidents', 'automation_incident_service')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_incidents`
        ADD KEY `automation_incident_service` (`automation_incident_service_id`)")) {
    throw new RuntimeException('Could not index automation incident services: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_event_policies` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create automation event policies: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_maintenance_windows` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create automation maintenance windows: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `automation_events` SET
    automation_event_fingerprint = COALESCE(NULLIF(automation_event_fingerprint, ''), automation_event_payload_hash),
    automation_event_last_received_at = automation_event_received_at,
    automation_event_status = CASE
        WHEN automation_event_processed_at IS NULL THEN 'Pending'
        ELSE 'Processed'
    END,
    automation_event_process_attempts = CASE
        WHEN automation_event_processed_at IS NULL THEN 0
        ELSE GREATEST(1, automation_event_process_attempts)
    END")) {
    throw new RuntimeException('Could not initialize automation event lifecycle state: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `automation_incidents` SET
    automation_incident_first_event_at = COALESCE(
        automation_incident_first_event_at,
        automation_incident_opened_at,
        automation_incident_last_event_at,
        automation_incident_created_at
    ),
    automation_incident_last_action = COALESCE(automation_incident_last_action,
        CASE WHEN automation_incident_status = 'Resolved' THEN 'resolved' ELSE 'recorded' END)")) {
    throw new RuntimeException('Could not initialize automation incident lifecycle state: ' . mysqli_error($mysqli));
}

$automation_policy_sources = [
    'level', 'sentinelone', 'checkmk', 'cipp', 'entra', 'intune',
    'backup', 'infrastructure', 'uptime_kuma', 'netbox', 'n8n',
];
foreach ($automation_policy_sources as $automation_policy_source) {
    $automation_policy_source_sql = mysqli_real_escape_string($mysqli, $automation_policy_source);
    if (!mysqli_query($mysqli, "INSERT IGNORE INTO automation_event_policies
        (automation_policy_source) VALUES ('$automation_policy_source_sql')")) {
        throw new RuntimeException('Could not seed automation event policies: ' . mysqli_error($mysqli));
    }
}
