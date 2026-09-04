<?php

/*
 * N45 migration n45-0014-agreement-entitlements (legacy marker 2.8.1)
 *
 * Agreement entitlement definitions are edited as drafts and become immutable
 * snapshots when published. Service reviews use the same pattern: every source
 * metric is captured in the published row so a later operational change cannot
 * rewrite the report that was presented to a client.
 *
 * Included by the N45 migration runner - do not access directly.
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$agreement_query = static function (string $sql, string $message) use ($mysqli): void {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
};

$agreement_query("ALTER TABLE `contracts`
    ADD COLUMN IF NOT EXISTS `contract_published_version_id` bigint(20) NOT NULL DEFAULT 0 AFTER `contract_renewal_frequency`,
    ADD COLUMN IF NOT EXISTS `contract_review_cadence_months` int(11) NOT NULL DEFAULT 3 AFTER `contract_published_version_id`,
    ADD COLUMN IF NOT EXISTS `contract_next_review_at` date DEFAULT NULL AFTER `contract_review_cadence_months`",
    'Could not extend contracts for versioned agreements');

$agreement_query("ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_request_type_key` varchar(100) NOT NULL DEFAULT '*' AFTER `ticket_category`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_response_minutes_snapshot` int(11) DEFAULT NULL AFTER `ticket_sla_id`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_resolution_minutes_snapshot` int(11) DEFAULT NULL AFTER `ticket_sla_response_minutes_snapshot`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_calendar_mode` varchar(20) DEFAULT NULL AFTER `ticket_sla_resolution_minutes_snapshot`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_business_days` varchar(20) DEFAULT NULL AFTER `ticket_sla_calendar_mode`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_business_hours_start` time DEFAULT NULL AFTER `ticket_sla_business_days`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_business_hours_end` time DEFAULT NULL AFTER `ticket_sla_business_hours_start`,
    ADD COLUMN IF NOT EXISTS `ticket_sla_timezone` varchar(64) DEFAULT NULL AFTER `ticket_sla_business_hours_end`,
    ADD COLUMN IF NOT EXISTS `ticket_response_due_at_utc` datetime DEFAULT NULL AFTER `ticket_response_due_at`,
    ADD COLUMN IF NOT EXISTS `ticket_resolution_due_at_utc` datetime DEFAULT NULL AFTER `ticket_resolution_due_at`",
    'Could not extend tickets with immutable SLA terms');

$agreement_index_exists = static function (string $table_name, string $index_name) use ($mysqli): bool {
    $table_name_sql = mysqli_real_escape_string($mysqli, $table_name);
    $index_name_sql = mysqli_real_escape_string($mysqli, $index_name);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = '$table_name_sql'
        AND index_name = '$index_name_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect agreement migration indexes: ' . mysqli_error($mysqli));
    }
    $row = mysqli_fetch_row($result);
    return intval($row[0] ?? 0) > 0;
};

if (!$agreement_index_exists('contracts', 'contract_published_version')) {
    $agreement_query("ALTER TABLE `contracts` ADD KEY `contract_published_version` (`contract_published_version_id`)",
        'Could not index the published agreement version pointer');
}
if (!$agreement_index_exists('contracts', 'contract_review_due')) {
    $agreement_query("ALTER TABLE `contracts` ADD KEY `contract_review_due` (`contract_status`,`contract_next_review_at`)",
        'Could not index due agreement reviews');
}

$agreement_query("CREATE TABLE IF NOT EXISTS `agreement_versions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create agreement versions');

$agreement_query("CREATE TABLE IF NOT EXISTS `agreement_entitlements` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create agreement entitlements');

$agreement_query("CREATE TABLE IF NOT EXISTS `agreement_sla_rules` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create agreement SLA rules');

$agreement_query("CREATE TABLE IF NOT EXISTS `agreement_version_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create agreement version events');

$agreement_query("CREATE TABLE IF NOT EXISTS `ticket_agreement_decisions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket agreement decisions');

$agreement_query("CREATE TABLE IF NOT EXISTS `service_reviews` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create service reviews');

$agreement_query("CREATE TABLE IF NOT EXISTS `service_review_events` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create service review events');

// Keep a retry safe if an earlier, uncommitted build of 2.8.1 created these
// tables before the immutable target/calendar and lifecycle fields were added.
$agreement_query("ALTER TABLE `agreement_versions`
    ADD COLUMN IF NOT EXISTS `agreement_version_superseded_at` datetime DEFAULT NULL AFTER `agreement_version_published_at`",
    'Could not extend agreement version lifecycle history');

// An interrupted pre-release 2.8.1 could already have superseded versions but
// no lifecycle boundary column. Reconstruct each boundary from the next actual
// publication for the same agreement. Refuse to invent a timestamp when there
// is no later published version to prove the transition.
$agreement_query("CREATE TEMPORARY TABLE IF NOT EXISTS `_agreement_supersession_boundaries` (
    `agreement_version_id` bigint(20) NOT NULL,
    `agreement_version_superseded_at` datetime NOT NULL,
    PRIMARY KEY (`agreement_version_id`)
) ENGINE=InnoDB", 'Could not prepare legacy agreement lifecycle conversion');
$agreement_query("TRUNCATE TABLE `_agreement_supersession_boundaries`",
    'Could not reset legacy agreement lifecycle conversion');
$agreement_query("INSERT INTO `_agreement_supersession_boundaries`
    (`agreement_version_id`, `agreement_version_superseded_at`)
    SELECT prior.agreement_version_id, MIN(replacement.agreement_version_published_at)
    FROM agreement_versions AS prior
    JOIN agreement_versions AS replacement
        ON replacement.agreement_version_contract_id = prior.agreement_version_contract_id
        AND replacement.agreement_version_number > prior.agreement_version_number
        AND replacement.agreement_version_published_at IS NOT NULL
    WHERE prior.agreement_version_status = 'Superseded'
    AND prior.agreement_version_superseded_at IS NULL
    GROUP BY prior.agreement_version_id",
    'Could not calculate legacy agreement lifecycle boundaries');
$agreement_query("UPDATE agreement_versions AS prior
    JOIN `_agreement_supersession_boundaries` AS boundary
        ON boundary.agreement_version_id = prior.agreement_version_id
    SET prior.agreement_version_superseded_at = boundary.agreement_version_superseded_at
    WHERE prior.agreement_version_status = 'Superseded'
    AND prior.agreement_version_superseded_at IS NULL",
    'Could not convert legacy agreement lifecycle boundaries');
$agreement_unbounded_result = mysqli_query($mysqli, "SELECT COUNT(*) FROM agreement_versions
    WHERE agreement_version_status = 'Superseded'
    AND agreement_version_superseded_at IS NULL");
if (!$agreement_unbounded_result) {
    throw new RuntimeException('Could not verify legacy agreement lifecycle conversion: ' . mysqli_error($mysqli));
}
$agreement_unbounded_row = mysqli_fetch_row($agreement_unbounded_result);
if (intval($agreement_unbounded_row[0] ?? 0) > 0) {
    throw new RuntimeException('Legacy superseded agreement versions have no provable replacement publication boundary');
}
$agreement_query("DROP TEMPORARY TABLE `_agreement_supersession_boundaries`",
    'Could not finish legacy agreement lifecycle conversion');
$agreement_query("ALTER TABLE `agreement_sla_rules`
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_sla_name` varchar(200) NOT NULL DEFAULT 'None' AFTER `agreement_sla_rule_sla_id`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_response_minutes` int(11) DEFAULT NULL AFTER `agreement_sla_rule_sla_name`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_resolution_minutes` int(11) DEFAULT NULL AFTER `agreement_sla_rule_response_minutes`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_calendar_mode` varchar(20) NOT NULL DEFAULT 'none' AFTER `agreement_sla_rule_resolution_minutes`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_business_days` varchar(20) DEFAULT NULL AFTER `agreement_sla_rule_calendar_mode`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_business_hours_start` time DEFAULT NULL AFTER `agreement_sla_rule_business_days`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_business_hours_end` time DEFAULT NULL AFTER `agreement_sla_rule_business_hours_start`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_timezone` varchar(64) NOT NULL DEFAULT 'UTC' AFTER `agreement_sla_rule_business_hours_end`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_classification_basis` varchar(30) NOT NULL DEFAULT 'explicit_rule' AFTER `agreement_sla_rule_classification`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_behavior_version` int(11) NOT NULL DEFAULT 1 AFTER `agreement_sla_rule_classification_basis`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_sla_eligible` tinyint(1) NOT NULL DEFAULT 1 AFTER `agreement_sla_rule_behavior_version`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_ticket_onsite` tinyint(1) NOT NULL DEFAULT 0 AFTER `agreement_sla_rule_sla_eligible`,
    ADD COLUMN IF NOT EXISTS `agreement_sla_rule_ticket_billable` tinyint(1) NOT NULL DEFAULT 0 AFTER `agreement_sla_rule_ticket_onsite`",
    'Could not extend agreement SLA snapshots');
$agreement_query("ALTER TABLE `ticket_agreement_decisions`
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_schema_version` int(11) NOT NULL DEFAULT 0 AFTER `ticket_agreement_decision_id`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_sla_name` varchar(200) NOT NULL DEFAULT 'None' AFTER `ticket_agreement_decision_sla_id`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_response_minutes` int(11) DEFAULT NULL AFTER `ticket_agreement_decision_sla_name`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_resolution_minutes` int(11) DEFAULT NULL AFTER `ticket_agreement_decision_response_minutes`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_calendar_mode` varchar(20) NOT NULL DEFAULT 'none' AFTER `ticket_agreement_decision_resolution_minutes`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_business_days` varchar(20) DEFAULT NULL AFTER `ticket_agreement_decision_calendar_mode`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_business_hours_start` time DEFAULT NULL AFTER `ticket_agreement_decision_business_days`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_business_hours_end` time DEFAULT NULL AFTER `ticket_agreement_decision_business_hours_start`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_timezone` varchar(64) NOT NULL DEFAULT 'UTC' AFTER `ticket_agreement_decision_business_hours_end`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_classification_basis` varchar(30) DEFAULT NULL AFTER `ticket_agreement_decision_classification`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_behavior_version` int(11) NOT NULL DEFAULT 0 AFTER `ticket_agreement_decision_classification_basis`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_sla_eligible` tinyint(1) NOT NULL DEFAULT 0 AFTER `ticket_agreement_decision_behavior_version`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_ticket_onsite` tinyint(1) NOT NULL DEFAULT 0 AFTER `ticket_agreement_decision_sla_eligible`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_ticket_billable` tinyint(1) NOT NULL DEFAULT 0 AFTER `ticket_agreement_decision_ticket_onsite`,
    ADD COLUMN IF NOT EXISTS `ticket_agreement_decision_entitlement_snapshot` longtext DEFAULT NULL AFTER `ticket_agreement_decision_ticket_billable`",
    'Could not extend ticket SLA decision snapshots');

// The pre-release publisher used NOW() independently for the row and event.
// Align that unhashed event timestamp to the authoritative published row so
// strict approval evidence remains valid across a second-boundary race.
$agreement_query("UPDATE service_review_events
    JOIN service_reviews
        ON service_review_id = service_review_event_review_id
        AND service_review_client_id = service_review_event_client_id
        AND service_review_snapshot_hash = service_review_event_snapshot_hash
    SET service_review_event_created_at = service_review_published_at
    WHERE service_review_status = 'Published'
    AND service_review_published_at IS NOT NULL
    AND service_review_event_action = 'Published'",
    'Could not align legacy service-review approval evidence');

// Rows created by an interrupted pre-release 2.8.1 deployment retain schema
// version 0 and their original decision hash. New decisions are version 1 and
// include the operational behavior plus entitlement evidence in that hash.
$agreement_query("UPDATE `agreement_sla_rules` SET
    `agreement_sla_rule_behavior_version` = 1,
    `agreement_sla_rule_sla_eligible` = (`agreement_sla_rule_classification` <> 'excluded'),
    `agreement_sla_rule_ticket_onsite` = (`agreement_sla_rule_classification` = 'onsite'),
    `agreement_sla_rule_ticket_billable` = (`agreement_sla_rule_classification` IN ('excluded', 'after_hours', 'billable'))",
    'Could not backfill agreement rule behavior semantics');
$agreement_query("UPDATE `ticket_agreement_decisions` SET
    `ticket_agreement_decision_entitlement_snapshot` = '{\"schema_version\":0,\"applicable\":false,\"basis\":\"legacy-decision\"}'
    WHERE `ticket_agreement_decision_entitlement_snapshot` IS NULL
        OR `ticket_agreement_decision_entitlement_snapshot` = ''",
    'Could not backfill legacy ticket decision evidence');
$agreement_query("ALTER TABLE `ticket_agreement_decisions`
    MODIFY COLUMN `ticket_agreement_decision_schema_version` int(11) NOT NULL DEFAULT 1,
    MODIFY COLUMN `ticket_agreement_decision_entitlement_snapshot` longtext NOT NULL",
    'Could not finalize immutable ticket decision evidence');

if (!$agreement_index_exists('tickets', 'ticket_response_due_at_utc')) {
    $agreement_query("ALTER TABLE `tickets` ADD KEY `ticket_response_due_at_utc` (`ticket_response_due_at_utc`)",
        'Could not index canonical response deadlines');
}
if (!$agreement_index_exists('tickets', 'ticket_resolution_due_at_utc')) {
    $agreement_query("ALTER TABLE `tickets` ADD KEY `ticket_resolution_due_at_utc` (`ticket_resolution_due_at_utc`)",
        'Could not index canonical resolution deadlines');
}
