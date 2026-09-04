<?php

/*
 * N45 migration n45-0013-portal-request-catalog (legacy marker 2.8.0)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$portal_request_tables = [
    'portal_request_catalog_items' => "CREATE TABLE IF NOT EXISTS `portal_request_catalog_items` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_catalog_fields' => "CREATE TABLE IF NOT EXISTS `portal_request_catalog_fields` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_catalog_versions' => "CREATE TABLE IF NOT EXISTS `portal_request_catalog_versions` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_catalog_version_fields' => "CREATE TABLE IF NOT EXISTS `portal_request_catalog_version_fields` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_submissions' => "CREATE TABLE IF NOT EXISTS `portal_request_submissions` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_dispatch_outbox' => "CREATE TABLE IF NOT EXISTS `portal_request_dispatch_outbox` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'portal_request_submission_events' => "CREATE TABLE IF NOT EXISTS `portal_request_submission_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($portal_request_tables as $portal_request_table => $portal_request_sql) {
    if (!mysqli_query($mysqli, $portal_request_sql)) {
        throw new RuntimeException("Could not create $portal_request_table: " . mysqli_error($mysqli));
    }
}

/*
 * CREATE TABLE IF NOT EXISTS is restartable but does not reconcile a table
 * created by an interrupted or earlier local draft of this unreleased
 * migration. Repair schema pieces added during the integrity review before
 * the version marker may advance. Historical rows cannot reconstruct the
 * original browser payload, so a zero fingerprint intentionally makes an old
 * idempotency credential non-replayable while preserving the submission.
 * This also reconciles the schema shape created by an earlier unreleased
 * experiment. The N45 runner records its ledger entry only after this file
 * completes and its fingerprint passes, so an interrupted attempt remains
 * pending and safe to retry.
 */
$portal_request_zero_hash = str_repeat('0', 64);
if (!mysqli_query($mysqli, "ALTER TABLE `portal_request_submissions`
    ADD COLUMN IF NOT EXISTS `portal_request_submission_request_hash` char(64) DEFAULT NULL
    AFTER `portal_request_submission_idempotency_hash`")) {
    throw new RuntimeException('Could not reconcile portal request fingerprints: ' . mysqli_error($mysqli));
}
if (!mysqli_query($mysqli, "UPDATE `portal_request_submissions`
    SET `portal_request_submission_request_hash` = '$portal_request_zero_hash'
    WHERE `portal_request_submission_request_hash` IS NULL
    OR BINARY `portal_request_submission_request_hash` NOT REGEXP '^[0-9a-f]{64}$'")) {
    throw new RuntimeException('Could not backfill portal request fingerprints: ' . mysqli_error($mysqli));
}
if (!mysqli_query($mysqli, "ALTER TABLE `portal_request_submissions`
    MODIFY `portal_request_submission_request_hash` char(64) NOT NULL")) {
    throw new RuntimeException('Could not finalize portal request fingerprints: ' . mysqli_error($mysqli));
}

$portal_request_category_index = mysqli_query($mysqli, "SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'portal_request_catalog_versions'
    AND index_name = 'portal_request_catalog_version_category'");
if ($portal_request_category_index === false) {
    throw new RuntimeException('Could not inspect the portal request category index: ' . mysqli_error($mysqli));
}
$portal_request_category_index_row = mysqli_fetch_row($portal_request_category_index);
if (intval($portal_request_category_index_row[0] ?? 0) === 0
    && !mysqli_query($mysqli, "ALTER TABLE `portal_request_catalog_versions`
        ADD INDEX `portal_request_catalog_version_category`
            (`portal_request_catalog_version_category_id`)")) {
    throw new RuntimeException('Could not reconcile the portal request category index: ' . mysqli_error($mysqli));
}
