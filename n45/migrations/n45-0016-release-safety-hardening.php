<?php

/*
 * N45 migration n45-0016-release-safety-hardening
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$n45_index_exists = static function (string $table, string $index) use ($mysqli): bool {
    $table_sql = mysqli_real_escape_string($mysqli, $table);
    $index_sql = mysqli_real_escape_string($mysqli, $index);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = '$table_sql' AND index_name = '$index_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect the release-hardening indexes: ' . mysqli_error($mysqli));
    }
    return intval(mysqli_fetch_row($result)[0] ?? 0) > 0;
};

$empty_secret_sql = mysqli_query($mysqli, "SELECT COUNT(*) FROM api_keys
    WHERE OCTET_LENGTH(api_key_secret) = 0");
if (!$empty_secret_sql) {
    throw new RuntimeException('Could not inspect empty API-key secrets: ' . mysqli_error($mysqli));
}
$empty_secret = mysqli_fetch_row($empty_secret_sql);
if (intval($empty_secret[0] ?? 0) > 0) {
    throw new RuntimeException('Empty API-key secrets must be revoked before release hardening can continue');
}
$duplicate_secret = mysqli_query($mysqli, "SELECT HEX(api_key_secret), COUNT(*) AS duplicate_count
    FROM api_keys GROUP BY BINARY api_key_secret HAVING duplicate_count > 1 LIMIT 1");
if (!$duplicate_secret) {
    throw new RuntimeException('Could not inspect API-key secret uniqueness: ' . mysqli_error($mysqli));
}
if (mysqli_num_rows($duplicate_secret) > 0) {
    throw new RuntimeException('Duplicate API-key secrets must be rotated before release hardening can continue');
}

if (!mysqli_query($mysqli, "ALTER TABLE `api_keys`
    MODIFY COLUMN `api_key_secret` varbinary(255) NOT NULL")) {
    throw new RuntimeException('Could not enforce binary API-key secret semantics: ' . mysqli_error($mysqli));
}
if (!$n45_index_exists('api_keys', 'api_key_secret_unique')
    && !mysqli_query($mysqli, "ALTER TABLE `api_keys`
        ADD UNIQUE KEY `api_key_secret_unique` (`api_key_secret`)")) {
    throw new RuntimeException('Could not enforce unique API-key secrets: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `automation_events`
    ADD COLUMN IF NOT EXISTS `automation_event_api_key_id` int(11) NOT NULL DEFAULT 0 AFTER `automation_event_max_attempts`,
    ADD COLUMN IF NOT EXISTS `automation_event_api_user_id` int(11) NOT NULL DEFAULT 0 AFTER `automation_event_api_key_id`,
    ADD COLUMN IF NOT EXISTS `automation_event_authorized_client_id` int(11) NOT NULL DEFAULT 0 AFTER `automation_event_api_user_id`,
    ADD COLUMN IF NOT EXISTS `automation_event_lease_token` char(64) DEFAULT NULL AFTER `automation_event_processing_at`")) {
    throw new RuntimeException('Could not add lease-owned automation delivery state: ' . mysqli_error($mysqli));
}
if (!$n45_index_exists('automation_events', 'automation_event_lease')
    && !mysqli_query($mysqli, "ALTER TABLE `automation_events`
        ADD KEY `automation_event_lease`
        (`automation_event_status`,`automation_event_processing_at`,`automation_event_lease_token`)")) {
    throw new RuntimeException('Could not index automation delivery leases: ' . mysqli_error($mysqli));
}
if (!mysqli_query($mysqli, "UPDATE automation_events SET
    automation_event_status = 'Dead',
    automation_event_action = 'processing_failed',
    automation_event_processing_at = NULL,
    automation_event_lease_token = NULL,
    automation_event_next_attempt_at = NULL,
    automation_event_last_error = 'Authority provenance unavailable for a pre-hardening queued event'
    WHERE automation_event_status IN ('Pending', 'Failed', 'Processing')
    AND (automation_event_api_key_id = 0 OR automation_event_api_user_id = 0)")) {
    throw new RuntimeException('Could not quarantine pre-hardening automation work: ' . mysqli_error($mysqli));
}
if (!mysqli_query($mysqli, "UPDATE automation_events SET
    automation_event_status = 'Failed',
    automation_event_action = 'processing_failed',
    automation_event_processing_at = NULL,
    automation_event_next_attempt_at = NOW(),
    automation_event_last_error = 'Pre-hardening processing claim reset for lease-owned retry'
    WHERE automation_event_status = 'Processing'
    AND (automation_event_lease_token IS NULL OR automation_event_lease_token = '')")) {
    throw new RuntimeException('Could not reset pre-hardening automation claims: ' . mysqli_error($mysqli));
}
if (!mysqli_query($mysqli, "UPDATE automation_events SET automation_event_lease_token = NULL
    WHERE automation_event_status <> 'Processing' AND automation_event_lease_token IS NOT NULL")) {
    throw new RuntimeException('Could not normalize inactive automation leases: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `file_staging_operations` (
    `file_staging_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `file_staging_batch_token` char(64) NOT NULL,
    `file_staging_owner_type` varchar(40) NOT NULL,
    `file_staging_owner_id` bigint(20) NOT NULL DEFAULT 0,
    `file_staging_staged_path` varchar(1024) NOT NULL,
    `file_staging_final_path` varchar(1024) NOT NULL,
    `file_staging_sha256` char(64) NOT NULL,
    `file_staging_size` bigint(20) unsigned NOT NULL DEFAULT 0,
    `file_staging_status` varchar(20) NOT NULL DEFAULT 'Pending',
    `file_staging_attempts` int(11) NOT NULL DEFAULT 0,
    `file_staging_last_error` text DEFAULT NULL,
    `file_staging_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `file_staging_finalized_at` datetime DEFAULT NULL,
    PRIMARY KEY (`file_staging_id`),
    KEY `file_staging_batch` (`file_staging_batch_token`,`file_staging_status`,`file_staging_id`),
    KEY `file_staging_recovery` (`file_staging_status`,`file_staging_created_at`,`file_staging_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create the crash-recoverable file staging journal: ' . mysqli_error($mysqli));
}
