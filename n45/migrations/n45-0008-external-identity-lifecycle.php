<?php

/*
 * N45 migration n45-0008-external-identity-lifecycle (legacy marker 2.7.5)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `automation_entity_mappings`
    ADD COLUMN IF NOT EXISTS `automation_mapping_external_parent_id` varchar(255) DEFAULT NULL AFTER `automation_mapping_external_id`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_state` varchar(20) NOT NULL DEFAULT 'unresolved' AFTER `automation_mapping_strategy`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_confidence` decimal(5,2) NOT NULL DEFAULT 0.00 AFTER `automation_mapping_state`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_last_synced_at` datetime DEFAULT NULL AFTER `automation_mapping_last_seen_at`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_last_success_at` datetime DEFAULT NULL AFTER `automation_mapping_last_synced_at`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_last_error` text DEFAULT NULL AFTER `automation_mapping_last_success_at`,
    ADD COLUMN IF NOT EXISTS `automation_mapping_confirmed_at` datetime DEFAULT NULL AFTER `automation_mapping_last_error`")) {
    throw new RuntimeException('Could not extend external identity mappings: ' . mysqli_error($mysqli));
}

$identity_index_exists = static function (string $index_name) use ($mysqli): bool {
    $index_name_sql = mysqli_real_escape_string($mysqli, $index_name);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = 'automation_entity_mappings'
        AND index_name = '$index_name_sql'"));
    return intval($row[0] ?? 0) > 0;
};

if (!$identity_index_exists('automation_mapping_source_entity_state')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_entity_mappings`
        ADD KEY `automation_mapping_source_entity_state`
        (`automation_mapping_source`,`automation_mapping_entity_type`,`automation_mapping_state`)")) {
    throw new RuntimeException('Could not index identity source states: ' . mysqli_error($mysqli));
}

if (!$identity_index_exists('automation_mapping_state')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_entity_mappings`
        ADD KEY `automation_mapping_state` (`automation_mapping_state`)")) {
    throw new RuntimeException('Could not index identity states: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_entity_snapshots` (
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
    UNIQUE KEY `automation_snapshot_source_entity_hash`
        (`automation_snapshot_source`,`automation_snapshot_entity_type`,`automation_snapshot_external_id`,`automation_snapshot_payload_hash`),
    KEY `automation_snapshot_entity_observed`
        (`automation_snapshot_source`,`automation_snapshot_entity_type`,`automation_snapshot_external_id`,`automation_snapshot_observed_at`),
    KEY `automation_snapshot_client` (`automation_snapshot_client_id`),
    KEY `automation_snapshot_asset` (`automation_snapshot_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create external entity snapshots: ' . mysqli_error($mysqli));
}

// Existing mappings become active identities without losing their legacy
// resolution strategy. Deleted mappings remain retired and recoverable.
if (!mysqli_query($mysqli, "UPDATE `automation_entity_mappings` SET
    automation_mapping_state = CASE
        WHEN automation_mapping_deleted_at IS NOT NULL THEN 'retired'
        WHEN automation_mapping_client_id > 0 OR automation_mapping_location_id > 0
            OR automation_mapping_asset_id > 0 OR automation_mapping_domain_id > 0 THEN 'automatic'
        ELSE 'unresolved'
    END,
    automation_mapping_confidence = CASE
        WHEN automation_mapping_client_id > 0 OR automation_mapping_location_id > 0
            OR automation_mapping_asset_id > 0 OR automation_mapping_domain_id > 0 THEN 100.00
        ELSE 0.00
    END,
    automation_mapping_last_synced_at = COALESCE(
        automation_mapping_last_synced_at,
        automation_mapping_last_seen_at,
        automation_mapping_updated_at,
        automation_mapping_created_at
    ),
    automation_mapping_last_success_at = CASE
        WHEN automation_mapping_deleted_at IS NULL
            AND (automation_mapping_client_id > 0 OR automation_mapping_location_id > 0
                OR automation_mapping_asset_id > 0 OR automation_mapping_domain_id > 0)
        THEN COALESCE(
            automation_mapping_last_success_at,
            automation_mapping_last_seen_at,
            automation_mapping_updated_at,
            automation_mapping_created_at
        )
        ELSE automation_mapping_last_success_at
    END")) {
    throw new RuntimeException('Could not initialize external identity lifecycle state: ' . mysqli_error($mysqli));
}
