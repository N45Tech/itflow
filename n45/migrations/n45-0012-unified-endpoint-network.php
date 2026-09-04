<?php

/*
 * N45 migration n45-0012-unified-endpoint-network (legacy marker 2.7.9)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$endpoint_tables = [
    'asset_endpoint_states' => "CREATE TABLE IF NOT EXISTS `asset_endpoint_states` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'asset_network_observations' => "CREATE TABLE IF NOT EXISTS `asset_network_observations` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'asset_change_events' => "CREATE TABLE IF NOT EXISTS `asset_change_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'automation_mapping_decisions' => "CREATE TABLE IF NOT EXISTS `automation_mapping_decisions` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($endpoint_tables as $table => $sql) {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException("Could not create $table: " . mysqli_error($mysqli));
    }
}

// Earlier experimental 2.7.9 builds could have committed the initial tables
// before equal-second canonical delivery and immutable reference-label fields
// were finalized. Repair each delta independently so a missing ledger write or
// interrupted DDL run is safe to retry.
$endpoint_repairs = [
    'asset_endpoint_states' => "ALTER TABLE `asset_endpoint_states`
        ADD COLUMN IF NOT EXISTS `endpoint_state_network_hash` char(64) NOT NULL DEFAULT '' AFTER `endpoint_state_payload`,
        ADD COLUMN IF NOT EXISTS `endpoint_state_network_observed_at` datetime DEFAULT NULL AFTER `endpoint_state_network_hash`,
        ADD COLUMN IF NOT EXISTS `endpoint_state_delivery_key` char(64) NOT NULL DEFAULT '' AFTER `endpoint_state_network_observed_at`,
        ADD COLUMN IF NOT EXISTS `endpoint_state_delivery_baseline` longtext DEFAULT NULL AFTER `endpoint_state_delivery_key`",
    'asset_network_observations' => "ALTER TABLE `asset_network_observations`
        ADD COLUMN IF NOT EXISTS `network_observation_created_delivery_key` char(64) NOT NULL DEFAULT '' AFTER `network_observation_payload`,
        ADD COLUMN IF NOT EXISTS `network_observation_closed_delivery_key` char(64) DEFAULT NULL AFTER `network_observation_created_delivery_key`,
        ADD COLUMN IF NOT EXISTS `network_observation_last_seen_delivery_key` char(64) DEFAULT NULL AFTER `network_observation_closed_delivery_key`,
        ADD COLUMN IF NOT EXISTS `network_observation_previous_last_seen_at` datetime DEFAULT NULL AFTER `network_observation_last_seen_delivery_key`,
        ADD COLUMN IF NOT EXISTS `network_observation_canonical` tinyint(1) NOT NULL DEFAULT 1 AFTER `network_observation_previous_last_seen_at`,
        ADD COLUMN IF NOT EXISTS `network_observation_superseded_at` datetime DEFAULT NULL AFTER `network_observation_canonical`",
    'asset_change_events' => "ALTER TABLE `asset_change_events`
        ADD COLUMN IF NOT EXISTS `asset_change_event_delivery_key` char(64) NOT NULL DEFAULT '' AFTER `asset_change_event_fingerprint`,
        ADD COLUMN IF NOT EXISTS `asset_change_event_canonical` tinyint(1) NOT NULL DEFAULT 1 AFTER `asset_change_event_delivery_key`,
        ADD COLUMN IF NOT EXISTS `asset_change_event_superseded_at` datetime DEFAULT NULL AFTER `asset_change_event_canonical`,
        ADD COLUMN IF NOT EXISTS `asset_change_event_ticket_label` varchar(500) DEFAULT NULL AFTER `asset_change_event_ticket_id`,
        ADD COLUMN IF NOT EXISTS `asset_change_event_document_label` varchar(500) DEFAULT NULL AFTER `asset_change_event_document_id`,
        ADD COLUMN IF NOT EXISTS `asset_change_event_evidence_label` varchar(500) DEFAULT NULL AFTER `asset_change_event_evidence_id`",
];

foreach ($endpoint_repairs as $table => $sql) {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException("Could not repair experimental endpoint table $table: " . mysqli_error($mysqli));
    }
}

// A normalized payload can legitimately recur after a technician remaps the
// durable identity. Keep evidence for both bindings instead of reusing the old
// tenant/asset row through the payload-only uniqueness key. Validate index
// uniqueness, ordered columns, and absence of prefix lengths before deciding
// that a retry is already complete.
$snapshot_index_sql = mysqli_query($mysqli, "SELECT COLUMN_NAME, NON_UNIQUE,
    SEQ_IN_INDEX, SUB_PART FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'automation_entity_snapshots'
    AND INDEX_NAME = 'automation_snapshot_source_entity_hash'
    ORDER BY SEQ_IN_INDEX");
if (!$snapshot_index_sql) {
    throw new RuntimeException('Could not inspect identity snapshot uniqueness: ' . mysqli_error($mysqli));
}
$snapshot_index_rows = [];
while ($snapshot_index_row = mysqli_fetch_assoc($snapshot_index_sql)) {
    $snapshot_index_rows[] = $snapshot_index_row;
}
$historical_snapshot_columns = [
    // Released by n45-0008 before endpoint bindings became part of replay identity.
    'automation_snapshot_source',
    'automation_snapshot_entity_type',
    'automation_snapshot_external_id',
    'automation_snapshot_payload_hash',
];
$final_snapshot_columns = [
    'automation_snapshot_source',
    'automation_snapshot_entity_type',
    'automation_snapshot_external_id',
    'automation_snapshot_client_id',
    'automation_snapshot_asset_id',
    'automation_snapshot_payload_hash',
];
$snapshot_index_matches = static function (array $rows, array $expected_columns): bool {
    if (count($rows) !== count($expected_columns)) {
        return false;
    }

    foreach ($rows as $position => $row) {
        if (($row['COLUMN_NAME'] ?? null) !== ($expected_columns[$position] ?? null)
            || intval($row['NON_UNIQUE'] ?? 1) !== 0
            || intval($row['SEQ_IN_INDEX'] ?? 0) !== $position + 1
            || ($row['SUB_PART'] ?? null) !== null) {
            return false;
        }
    }

    return true;
};
$snapshot_index_is_absent = $snapshot_index_rows === [];
$snapshot_index_is_historical = $snapshot_index_matches($snapshot_index_rows, $historical_snapshot_columns);
$snapshot_index_is_final = $snapshot_index_matches($snapshot_index_rows, $final_snapshot_columns);
if (!$snapshot_index_is_absent && !$snapshot_index_is_historical && !$snapshot_index_is_final) {
    throw new RuntimeException(
        'Unexpected identity snapshot uniqueness shape; refusing destructive repair'
    );
}
if ($snapshot_index_is_absent || $snapshot_index_is_historical) {
    $drop_snapshot_index = $snapshot_index_is_historical
        ? 'DROP INDEX `automation_snapshot_source_entity_hash`, ' : '';
    if (!mysqli_query($mysqli, "ALTER TABLE `automation_entity_snapshots`
        $drop_snapshot_index
        ADD UNIQUE KEY `automation_snapshot_source_entity_hash`
            (`automation_snapshot_source`,`automation_snapshot_entity_type`,
             `automation_snapshot_external_id`,`automation_snapshot_client_id`,
             `automation_snapshot_asset_id`,`automation_snapshot_payload_hash`)")) {
        throw new RuntimeException('Could not make identity snapshots binding-safe: ' . mysqli_error($mysqli));
    }
}
