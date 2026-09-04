<?php

/*
 * N45 migration n45-0011-documentation-readiness (legacy marker 2.7.8)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$documentation_column_exists = static function (string $table_name, string $column_name) use ($mysqli): bool {
    $table_name_sql = mysqli_real_escape_string($mysqli, $table_name);
    $column_name_sql = mysqli_real_escape_string($mysqli, $column_name);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
        AND table_name = '$table_name_sql'
        AND column_name = '$column_name_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect documentation migration columns: ' . mysqli_error($mysqli));
    }
    $row = mysqli_fetch_row($result);
    return intval($row[0] ?? 0) > 0;
};

// Existing tickets predate the documentation-impact contract. They are
// explicitly exempt instead of being silently treated as assessed. New
// tickets inherit Unassessed after this migration completes.
$documentation_impact_was_missing = !$documentation_column_exists('tickets', 'ticket_documentation_impact');

if (!mysqli_query($mysqli, "ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_configuration_change` tinyint(1) NOT NULL DEFAULT 0 AFTER `ticket_onsite`,
    ADD COLUMN IF NOT EXISTS `ticket_documentation_impact` varchar(20) NOT NULL DEFAULT 'Legacy Exempt' AFTER `ticket_configuration_change`,
    ADD COLUMN IF NOT EXISTS `ticket_documentation_assessed_by` int(11) NOT NULL DEFAULT 0 AFTER `ticket_documentation_impact`,
    ADD COLUMN IF NOT EXISTS `ticket_documentation_assessed_at` datetime DEFAULT NULL AFTER `ticket_documentation_assessed_by`")) {
    throw new RuntimeException('Could not add ticket documentation impact fields: ' . mysqli_error($mysqli));
}

if ($documentation_impact_was_missing
    && !mysqli_query($mysqli, "UPDATE `tickets` SET ticket_documentation_impact = 'Legacy Exempt'")) {
    throw new RuntimeException('Could not explicitly exempt legacy tickets from documentation assessment: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `tickets`
    MODIFY COLUMN `ticket_documentation_impact` varchar(20) NOT NULL DEFAULT 'Unassessed'")) {
    throw new RuntimeException('Could not set the new-ticket documentation assessment default: ' . mysqli_error($mysqli));
}

$documentation_tables = [
    'documentation_requirements' => "CREATE TABLE IF NOT EXISTS `documentation_requirements` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_requirement_versions' => "CREATE TABLE IF NOT EXISTS `documentation_requirement_versions` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_requirement_version_selectors' => "CREATE TABLE IF NOT EXISTS `documentation_requirement_version_selectors` (
        `documentation_selector_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `documentation_selector_requirement_version_id` bigint(20) NOT NULL,
        `documentation_selector_dimension` varchar(40) NOT NULL,
        `documentation_selector_value` varchar(100) NOT NULL,
        `documentation_selector_order` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`documentation_selector_id`),
        UNIQUE KEY `documentation_selector_identity` (`documentation_selector_requirement_version_id`,`documentation_selector_dimension`,`documentation_selector_value`),
        KEY `documentation_selector_lookup` (`documentation_selector_dimension`,`documentation_selector_value`,`documentation_selector_requirement_version_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'client_documentation_obligations' => "CREATE TABLE IF NOT EXISTS `client_documentation_obligations` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_obligation_events' => "CREATE TABLE IF NOT EXISTS `documentation_obligation_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_obligation_exceptions' => "CREATE TABLE IF NOT EXISTS `documentation_obligation_exceptions` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_obligation_exception_events' => "CREATE TABLE IF NOT EXISTS `documentation_obligation_exception_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_evidence_locker' => "CREATE TABLE IF NOT EXISTS `documentation_evidence_locker` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_change_passports' => "CREATE TABLE IF NOT EXISTS `documentation_change_passports` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_change_passport_obligations' => "CREATE TABLE IF NOT EXISTS `documentation_change_passport_obligations` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_promise_ledger' => "CREATE TABLE IF NOT EXISTS `documentation_promise_ledger` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'documentation_promise_events' => "CREATE TABLE IF NOT EXISTS `documentation_promise_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'ticket_documentation_obligations' => "CREATE TABLE IF NOT EXISTS `ticket_documentation_obligations` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'ticket_documentation_waivers' => "CREATE TABLE IF NOT EXISTS `ticket_documentation_waivers` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'ticket_documentation_waiver_events' => "CREATE TABLE IF NOT EXISTS `ticket_documentation_waiver_events` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
];

foreach ($documentation_tables as $documentation_table_name => $documentation_table_sql) {
    if (!mysqli_query($mysqli, $documentation_table_sql)) {
        throw new RuntimeException("Could not create $documentation_table_name: " . mysqli_error($mysqli));
    }
}
