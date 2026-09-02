<?php

/*
 * N45 migration n45-0018-retention-controls
 *
 * Adds the recoverable-deletion ledger, immutable lifecycle events, legal
 * holds, purge previews, and the row-level lifecycle fields used by tickets,
 * client files, ticket attachments, and retained automation payloads.
 *
 * Included by the N45 migration runner - do not access directly.
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$retention_query = static function (string $sql, string $message) use ($mysqli): void {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
};

$retention_query("ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_deleted_at` datetime DEFAULT NULL AFTER `ticket_archived_at`,
    ADD COLUMN IF NOT EXISTS `ticket_deleted_by` int(11) NOT NULL DEFAULT 0 AFTER `ticket_deleted_at`,
    ADD COLUMN IF NOT EXISTS `ticket_delete_reason` varchar(500) DEFAULT NULL AFTER `ticket_deleted_by`,
    ADD COLUMN IF NOT EXISTS `ticket_restore_until` datetime DEFAULT NULL AFTER `ticket_delete_reason`,
    ADD COLUMN IF NOT EXISTS `ticket_purge_eligible_at` datetime DEFAULT NULL AFTER `ticket_restore_until`",
    'Could not add the ticket retention lifecycle');

$retention_query("ALTER TABLE `files`
    ADD COLUMN IF NOT EXISTS `file_deleted_at` datetime DEFAULT NULL AFTER `file_archived_at`,
    ADD COLUMN IF NOT EXISTS `file_deleted_by` int(11) NOT NULL DEFAULT 0 AFTER `file_deleted_at`,
    ADD COLUMN IF NOT EXISTS `file_delete_reason` varchar(500) DEFAULT NULL AFTER `file_deleted_by`,
    ADD COLUMN IF NOT EXISTS `file_restore_until` datetime DEFAULT NULL AFTER `file_delete_reason`,
    ADD COLUMN IF NOT EXISTS `file_purge_eligible_at` datetime DEFAULT NULL AFTER `file_restore_until`",
    'Could not add the file retention lifecycle');

$retention_query("ALTER TABLE `ticket_attachments`
    ADD COLUMN IF NOT EXISTS `ticket_attachment_deleted_at` datetime DEFAULT NULL AFTER `ticket_attachment_created_at`,
    ADD COLUMN IF NOT EXISTS `ticket_attachment_deleted_by` int(11) NOT NULL DEFAULT 0 AFTER `ticket_attachment_deleted_at`,
    ADD COLUMN IF NOT EXISTS `ticket_attachment_delete_reason` varchar(500) DEFAULT NULL AFTER `ticket_attachment_deleted_by`,
    ADD COLUMN IF NOT EXISTS `ticket_attachment_restore_until` datetime DEFAULT NULL AFTER `ticket_attachment_delete_reason`,
    ADD COLUMN IF NOT EXISTS `ticket_attachment_purge_eligible_at` datetime DEFAULT NULL AFTER `ticket_attachment_restore_until`",
    'Could not add the attachment retention lifecycle');

$retention_query("ALTER TABLE `automation_events`
    ADD COLUMN IF NOT EXISTS `automation_event_payload_redacted_at` datetime DEFAULT NULL AFTER `automation_event_payload`",
    'Could not add automation-event payload retention state');

$retention_query("ALTER TABLE `automation_entity_snapshots`
    ADD COLUMN IF NOT EXISTS `automation_snapshot_payload_redacted_at` datetime DEFAULT NULL AFTER `automation_snapshot_payload`",
    'Could not add normalized-payload retention state');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_policies` (
    `retention_policy_key` varchar(40) NOT NULL,
    `retention_policy_label` varchar(100) NOT NULL,
    `retention_policy_retention_days` int(11) NOT NULL,
    `retention_policy_restore_window_days` int(11) NOT NULL DEFAULT 0,
    `retention_policy_purge_mode` varchar(20) NOT NULL DEFAULT 'disabled',
    `retention_policy_owner_note` varchar(500) NOT NULL DEFAULT '',
    `retention_policy_updated_by` int(11) NOT NULL DEFAULT 0,
    `retention_policy_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `retention_policy_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`retention_policy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create retention policies');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_holds` (
    `retention_hold_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `retention_hold_client_id` int(11) NOT NULL DEFAULT 0,
    `retention_hold_record_type` varchar(40) NOT NULL DEFAULT '*',
    `retention_hold_record_id` bigint(20) NOT NULL DEFAULT 0,
    `retention_hold_reason` varchar(500) NOT NULL,
    `retention_hold_placed_by` int(11) NOT NULL DEFAULT 0,
    `retention_hold_placed_at` datetime NOT NULL DEFAULT current_timestamp(),
    `retention_hold_released_by` int(11) NOT NULL DEFAULT 0,
    `retention_hold_release_reason` varchar(500) DEFAULT NULL,
    `retention_hold_released_at` datetime DEFAULT NULL,
    PRIMARY KEY (`retention_hold_id`),
    KEY `retention_hold_active_record` (`retention_hold_record_type`,`retention_hold_record_id`,`retention_hold_released_at`),
    KEY `retention_hold_active_client` (`retention_hold_client_id`,`retention_hold_released_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create retention holds');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_deletions` (
    `retention_deletion_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `retention_deletion_record_type` varchar(40) NOT NULL,
    `retention_deletion_record_id` bigint(20) NOT NULL,
    `retention_deletion_client_id` int(11) NOT NULL DEFAULT 0,
    `retention_deletion_generation` int(11) NOT NULL DEFAULT 1,
    `retention_deletion_label` varchar(500) NOT NULL,
    `retention_deletion_deleted_by` int(11) NOT NULL,
    `retention_deletion_reason` varchar(500) NOT NULL,
    `retention_deletion_deleted_at` datetime NOT NULL,
    `retention_deletion_restore_until` datetime DEFAULT NULL,
    `retention_deletion_purge_eligible_at` datetime NOT NULL,
    `retention_deletion_restored_by` int(11) NOT NULL DEFAULT 0,
    `retention_deletion_restored_at` datetime DEFAULT NULL,
    `retention_deletion_purged_by` int(11) NOT NULL DEFAULT 0,
    `retention_deletion_purged_at` datetime DEFAULT NULL,
    `retention_deletion_quarantine_path` varchar(1000) DEFAULT NULL,
    `retention_deletion_quarantine_status` varchar(20) NOT NULL DEFAULT 'none',
    `retention_deletion_quarantine_manifest` longtext DEFAULT NULL,
    `retention_deletion_quarantine_manifest_hash` char(64) DEFAULT NULL,
    `retention_deletion_quarantine_prepared_at` datetime DEFAULT NULL,
    `retention_deletion_quarantine_claim_token` char(64) DEFAULT NULL,
    `retention_deletion_quarantine_attempted_at` datetime DEFAULT NULL,
    `retention_deletion_quarantine_completed_at` datetime DEFAULT NULL,
    `retention_deletion_restore_pending_by` int(11) NOT NULL DEFAULT 0,
    `retention_deletion_restore_pending_reason` varchar(500) DEFAULT NULL,
    `retention_deletion_restore_prepared_at` datetime DEFAULT NULL,
    `retention_deletion_last_error` varchar(1000) DEFAULT NULL,
    PRIMARY KEY (`retention_deletion_id`),
    UNIQUE KEY `retention_deletion_record` (`retention_deletion_record_type`,`retention_deletion_record_id`),
    KEY `retention_deletion_active` (`retention_deletion_restored_at`,`retention_deletion_purged_at`,`retention_deletion_purge_eligible_at`),
    KEY `retention_deletion_client` (`retention_deletion_client_id`,`retention_deletion_deleted_at`),
    KEY `retention_deletion_quarantine_queue` (`retention_deletion_quarantine_status`,`retention_deletion_quarantine_attempted_at`,`retention_deletion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create the recoverable-deletion ledger');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_events` (
    `retention_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `retention_event_record_type` varchar(40) NOT NULL,
    `retention_event_record_id` bigint(20) NOT NULL DEFAULT 0,
    `retention_event_client_id` int(11) NOT NULL DEFAULT 0,
    `retention_event_generation` int(11) NOT NULL DEFAULT 0,
    `retention_event_action` varchar(40) NOT NULL,
    `retention_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `retention_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `retention_event_reason` varchar(500) DEFAULT NULL,
    `retention_event_metadata` longtext DEFAULT NULL,
    `retention_event_metadata_hash` char(64) NOT NULL,
    `retention_event_batch_id` bigint(20) NOT NULL DEFAULT 0,
    `retention_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`retention_event_id`),
    KEY `retention_event_record` (`retention_event_record_type`,`retention_event_record_id`,`retention_event_id`),
    KEY `retention_event_client` (`retention_event_client_id`,`retention_event_created_at`),
    KEY `retention_event_batch` (`retention_event_batch_id`,`retention_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create immutable retention events');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_purge_batches` (
    `retention_purge_batch_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `retention_purge_batch_idempotency_key` varchar(191) NOT NULL,
    `retention_purge_batch_mode` varchar(20) NOT NULL DEFAULT 'preview',
    `retention_purge_batch_status` varchar(20) NOT NULL DEFAULT 'Previewed',
    `retention_purge_batch_requested_by` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_requested_at` datetime NOT NULL DEFAULT current_timestamp(),
    `retention_purge_batch_approved_by` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_approved_at` datetime DEFAULT NULL,
    `retention_purge_batch_run_token` char(64) DEFAULT NULL,
    `retention_purge_batch_lease_until` datetime DEFAULT NULL,
    `retention_purge_batch_resume_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_candidate_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_eligible_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_purged_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_blocked_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_failed_count` int(11) NOT NULL DEFAULT 0,
    `retention_purge_batch_completed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`retention_purge_batch_id`),
    UNIQUE KEY `retention_purge_batch_idempotency` (`retention_purge_batch_idempotency_key`),
    KEY `retention_purge_batch_status` (`retention_purge_batch_status`,`retention_purge_batch_requested_at`),
    KEY `retention_purge_batch_lease` (`retention_purge_batch_status`,`retention_purge_batch_lease_until`,`retention_purge_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create retention purge batches');

$retention_query("CREATE TABLE IF NOT EXISTS `retention_purge_items` (
    `retention_purge_item_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `retention_purge_item_batch_id` bigint(20) NOT NULL,
    `retention_purge_item_record_type` varchar(40) NOT NULL,
    `retention_purge_item_record_id` bigint(20) NOT NULL,
    `retention_purge_item_client_id` int(11) NOT NULL DEFAULT 0,
    `retention_purge_item_generation` int(11) NOT NULL,
    `retention_purge_item_policy_key` varchar(40) NOT NULL,
    `retention_purge_item_outcome` varchar(20) NOT NULL,
    `retention_purge_item_reason` varchar(1000) NOT NULL,
    `retention_purge_item_dependency_summary` longtext NOT NULL,
    `retention_purge_item_dependency_hash` char(64) NOT NULL,
    `retention_purge_item_claim_token` char(64) DEFAULT NULL,
    `retention_purge_item_claimed_at` datetime DEFAULT NULL,
    `retention_purge_item_processed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`retention_purge_item_id`),
    UNIQUE KEY `retention_purge_item_record` (`retention_purge_item_batch_id`,`retention_purge_item_record_type`,`retention_purge_item_record_id`,`retention_purge_item_generation`),
    KEY `retention_purge_item_queue` (`retention_purge_item_batch_id`,`retention_purge_item_outcome`,`retention_purge_item_id`),
    KEY `retention_purge_item_claim` (`retention_purge_item_batch_id`,`retention_purge_item_outcome`,`retention_purge_item_claimed_at`,`retention_purge_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create retention purge items');

// Retry/repair compatibility for development snapshots that created an early
// 0018 draft before resumable purge leases were finalized.
$retention_query("ALTER TABLE `retention_purge_batches`
    ADD COLUMN IF NOT EXISTS `retention_purge_batch_run_token` char(64) DEFAULT NULL AFTER `retention_purge_batch_approved_at`,
    ADD COLUMN IF NOT EXISTS `retention_purge_batch_lease_until` datetime DEFAULT NULL AFTER `retention_purge_batch_run_token`,
    ADD COLUMN IF NOT EXISTS `retention_purge_batch_resume_count` int(11) NOT NULL DEFAULT 0 AFTER `retention_purge_batch_lease_until`",
    'Could not repair purge batch lease state');
$retention_query("ALTER TABLE `retention_purge_items`
    ADD COLUMN IF NOT EXISTS `retention_purge_item_claim_token` char(64) DEFAULT NULL AFTER `retention_purge_item_dependency_hash`,
    ADD COLUMN IF NOT EXISTS `retention_purge_item_claimed_at` datetime DEFAULT NULL AFTER `retention_purge_item_claim_token`",
    'Could not repair purge item claim state');
$retention_query("ALTER TABLE `retention_deletions`
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_manifest` longtext DEFAULT NULL AFTER `retention_deletion_quarantine_status`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_manifest_hash` char(64) DEFAULT NULL AFTER `retention_deletion_quarantine_manifest`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_prepared_at` datetime DEFAULT NULL AFTER `retention_deletion_quarantine_manifest_hash`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_claim_token` char(64) DEFAULT NULL AFTER `retention_deletion_quarantine_prepared_at`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_attempted_at` datetime DEFAULT NULL AFTER `retention_deletion_quarantine_claim_token`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_quarantine_completed_at` datetime DEFAULT NULL AFTER `retention_deletion_quarantine_attempted_at`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_restore_pending_by` int(11) NOT NULL DEFAULT 0 AFTER `retention_deletion_quarantine_completed_at`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_restore_pending_reason` varchar(500) DEFAULT NULL AFTER `retention_deletion_restore_pending_by`,
    ADD COLUMN IF NOT EXISTS `retention_deletion_restore_prepared_at` datetime DEFAULT NULL AFTER `retention_deletion_restore_pending_reason`",
    'Could not repair durable quarantine journal state');
$retention_query("UPDATE `retention_deletions` SET
    `retention_deletion_quarantine_status` = 'unknown',
    `retention_deletion_quarantine_claim_token` = NULL,
    `retention_deletion_last_error` = 'Pre-manifest quarantine state requires manual byte verification'
    WHERE `retention_deletion_record_type` IN ('file','attachment')
    AND (`retention_deletion_quarantine_manifest` IS NULL
        OR `retention_deletion_quarantine_manifest_hash` IS NULL)",
    'Could not quarantine pre-manifest draft lifecycle state');

$retention_index_exists = static function (string $table, string $index) use ($mysqli): bool {
    $table_sql = mysqli_real_escape_string($mysqli, $table);
    $index_sql = mysqli_real_escape_string($mysqli, $index);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = '$table_sql' AND index_name = '$index_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect retention indexes: ' . mysqli_error($mysqli));
    }
    return intval(mysqli_fetch_row($result)[0] ?? 0) > 0;
};

foreach ([
    ['tickets', 'ticket_retention_queue', '`ticket_deleted_at`,`ticket_purge_eligible_at`'],
    ['files', 'file_retention_queue', '`file_deleted_at`,`file_purge_eligible_at`'],
    ['ticket_attachments', 'ticket_attachment_retention_queue', '`ticket_attachment_deleted_at`,`ticket_attachment_purge_eligible_at`'],
    ['automation_events', 'automation_event_payload_retention', '`automation_event_payload_redacted_at`,`automation_event_last_received_at`'],
    ['automation_entity_snapshots', 'automation_snapshot_payload_retention', '`automation_snapshot_payload_redacted_at`,`automation_snapshot_last_seen_at`'],
    ['retention_purge_batches', 'retention_purge_batch_lease', '`retention_purge_batch_status`,`retention_purge_batch_lease_until`,`retention_purge_batch_id`'],
    ['retention_purge_items', 'retention_purge_item_claim', '`retention_purge_item_batch_id`,`retention_purge_item_outcome`,`retention_purge_item_claimed_at`,`retention_purge_item_id`'],
    ['retention_deletions', 'retention_deletion_quarantine_queue', '`retention_deletion_quarantine_status`,`retention_deletion_quarantine_attempted_at`,`retention_deletion_id`'],
] as [$table, $index, $columns]) {
    if (!$retention_index_exists($table, $index)) {
        $retention_query("ALTER TABLE `$table` ADD KEY `$index` ($columns)", "Could not add $index");
    }
}

// Hashes make event payload tampering detectable; these triggers also make
// UPDATE/DELETE impossible through the application database account so rows
// cannot be silently rewritten or removed.
$retention_query("CREATE TRIGGER IF NOT EXISTS `retention_events_no_update`
    BEFORE UPDATE ON `retention_events` FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'retention_events is append-only'",
    'Could not protect retention events from updates');
$retention_query("CREATE TRIGGER IF NOT EXISTS `retention_events_no_delete`
    BEFORE DELETE ON `retention_events` FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'retention_events is append-only'",
    'Could not protect retention events from deletion');
$retention_trigger_result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema = DATABASE() AND event_object_table = 'retention_events'
    AND action_timing = 'BEFORE'
    AND LOWER(TRIM(action_statement)) = LOWER(
        'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''retention_events is append-only''')
    AND ((trigger_name = 'retention_events_no_update' AND event_manipulation = 'UPDATE')
        OR (trigger_name = 'retention_events_no_delete' AND event_manipulation = 'DELETE'))");
if (!$retention_trigger_result || intval(mysqli_fetch_row($retention_trigger_result)[0] ?? 0) !== 2) {
    throw new RuntimeException('Unexpected retention event trigger drift; refusing to replace it');
}

// Safest useful defaults: operational records require an explicit approved
// batch, evidence cannot purge, and already-redacted integration payloads keep
// their existing automatic minimization behavior.
$retention_query("INSERT IGNORE INTO `retention_policies`
    (`retention_policy_key`,`retention_policy_label`,`retention_policy_retention_days`,
     `retention_policy_restore_window_days`,`retention_policy_purge_mode`,`retention_policy_owner_note`) VALUES
    ('tickets','Tickets',2555,30,'manual','Seven-year operational record baseline; explicit purge batch required.'),
    ('files','Client files',2555,30,'manual','Evidence references remain an unconditional deletion lock.'),
    ('attachments','Ticket attachments',2555,30,'manual','Runbook evidence references remain an unconditional deletion lock.'),
    ('automation_payloads','Redacted event payloads',30,0,'automatic','Payload bodies are minimized; hashes and event metadata remain.'),
    ('normalized_payloads','Normalized integration payloads',90,0,'automatic','Normalized bodies are minimized; identity and hashes remain.'),
    ('evidence','Evidence and approvals',2555,0,'disabled','Immutable evidence is locked from purge by default.')",
    'Could not seed safe retention policies');
