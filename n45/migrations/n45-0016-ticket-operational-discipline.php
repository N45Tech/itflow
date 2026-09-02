<?php

/*
 * N45 migration n45-0016-ticket-operational-discipline
 * Included by the N45 migration runner - do not access directly.
 *
 * The ticket remains the upstream-compatible work record. Fork-owned tables
 * hold relationship, promise, intake and immutable operational audit state so
 * upstream ticket changes do not have to understand those lifecycles.
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$ticket_operations_query = static function (string $sql, string $message) use ($mysqli): void {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
};

$ticket_operations_query("ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_work_type` varchar(20) NOT NULL DEFAULT 'request' AFTER `ticket_request_type_key`,
    ADD COLUMN IF NOT EXISTS `ticket_impact` varchar(20) NOT NULL DEFAULT 'low' AFTER `ticket_work_type`,
    ADD COLUMN IF NOT EXISTS `ticket_urgency` varchar(20) NOT NULL DEFAULT 'low' AFTER `ticket_impact`,
    ADD COLUMN IF NOT EXISTS `ticket_next_action` varchar(500) NOT NULL DEFAULT 'Review and triage this ticket.' AFTER `ticket_urgency`,
    ADD COLUMN IF NOT EXISTS `ticket_next_action_due_at` datetime DEFAULT NULL AFTER `ticket_next_action`,
    ADD COLUMN IF NOT EXISTS `ticket_waiting_on` varchar(20) NOT NULL DEFAULT 'none' AFTER `ticket_next_action_due_at`,
    ADD COLUMN IF NOT EXISTS `ticket_waiting_on_detail` varchar(255) DEFAULT NULL AFTER `ticket_waiting_on`,
    ADD COLUMN IF NOT EXISTS `ticket_resolution_code` varchar(30) DEFAULT NULL AFTER `ticket_waiting_on_detail`,
    ADD COLUMN IF NOT EXISTS `ticket_resolution_summary` text DEFAULT NULL AFTER `ticket_resolution_code`,
    ADD COLUMN IF NOT EXISTS `ticket_root_cause` text DEFAULT NULL AFTER `ticket_resolution_summary`,
    ADD COLUMN IF NOT EXISTS `ticket_operational_updated_by` int(11) NOT NULL DEFAULT 0 AFTER `ticket_root_cause`,
    ADD COLUMN IF NOT EXISTS `ticket_operational_updated_at` datetime DEFAULT NULL AFTER `ticket_operational_updated_by`",
    'Could not extend tickets with operational discipline fields');

$ticket_operations_query("CREATE TABLE IF NOT EXISTS `ticket_operational_events` (
    `ticket_operational_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_operational_event_ticket_id` int(11) NOT NULL,
    `ticket_operational_event_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_operational_event_action` varchar(40) NOT NULL,
    `ticket_operational_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `ticket_operational_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `ticket_operational_event_payload` longtext NOT NULL,
    `ticket_operational_event_payload_hash` char(64) NOT NULL,
    `ticket_operational_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_operational_event_id`),
    KEY `ticket_operational_event_ticket` (`ticket_operational_event_ticket_id`,`ticket_operational_event_id`),
    KEY `ticket_operational_event_client` (`ticket_operational_event_client_id`,`ticket_operational_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket operational events');

$ticket_operations_query("CREATE TABLE IF NOT EXISTS `ticket_relationships` (
    `ticket_relationship_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_relationship_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_relationship_type` varchar(20) NOT NULL,
    `ticket_relationship_source_ticket_id` int(11) NOT NULL,
    `ticket_relationship_target_ticket_id` int(11) NOT NULL,
    `ticket_relationship_created_by` int(11) NOT NULL DEFAULT 0,
    `ticket_relationship_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_relationship_id`),
    UNIQUE KEY `ticket_relationship_pair` (`ticket_relationship_type`,`ticket_relationship_source_ticket_id`,`ticket_relationship_target_ticket_id`),
    KEY `ticket_relationship_source` (`ticket_relationship_source_ticket_id`,`ticket_relationship_type`),
    KEY `ticket_relationship_target` (`ticket_relationship_target_ticket_id`,`ticket_relationship_type`),
    KEY `ticket_relationship_client` (`ticket_relationship_client_id`,`ticket_relationship_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket relationships');

$ticket_operations_query("CREATE TABLE IF NOT EXISTS `ticket_customer_promises` (
    `ticket_customer_promise_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_customer_promise_ticket_id` int(11) NOT NULL,
    `ticket_customer_promise_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_type` varchar(30) NOT NULL DEFAULT 'customer_update',
    `ticket_customer_promise_summary` varchar(500) NOT NULL,
    `ticket_customer_promise_due_at` datetime NOT NULL,
    `ticket_customer_promise_status` varchar(20) NOT NULL DEFAULT 'Open',
    `ticket_customer_promise_promised_by` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_promised_at` datetime NOT NULL DEFAULT current_timestamp(),
    `ticket_customer_promise_source_type` varchar(20) NOT NULL DEFAULT 'agent',
    `ticket_customer_promise_source_id` bigint(20) NOT NULL DEFAULT 0,
    `ticket_customer_promise_fulfilled_by` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_fulfilled_at` datetime DEFAULT NULL,
    `ticket_customer_promise_breached_at` datetime DEFAULT NULL,
    `ticket_customer_promise_cancelled_by` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_cancelled_at` datetime DEFAULT NULL,
    PRIMARY KEY (`ticket_customer_promise_id`),
    KEY `ticket_customer_promise_queue` (`ticket_customer_promise_status`,`ticket_customer_promise_due_at`),
    KEY `ticket_customer_promise_ticket` (`ticket_customer_promise_ticket_id`,`ticket_customer_promise_status`,`ticket_customer_promise_due_at`),
    KEY `ticket_customer_promise_client` (`ticket_customer_promise_client_id`,`ticket_customer_promise_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket customer promises');

$ticket_operations_query("CREATE TABLE IF NOT EXISTS `ticket_customer_promise_events` (
    `ticket_customer_promise_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_customer_promise_event_promise_id` bigint(20) NOT NULL,
    `ticket_customer_promise_event_ticket_id` int(11) NOT NULL,
    `ticket_customer_promise_event_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_event_action` varchar(30) NOT NULL,
    `ticket_customer_promise_event_from_status` varchar(20) DEFAULT NULL,
    `ticket_customer_promise_event_to_status` varchar(20) NOT NULL,
    `ticket_customer_promise_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `ticket_customer_promise_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `ticket_customer_promise_event_source_type` varchar(20) DEFAULT NULL,
    `ticket_customer_promise_event_source_id` bigint(20) NOT NULL DEFAULT 0,
    `ticket_customer_promise_event_context_hash` char(64) NOT NULL,
    `ticket_customer_promise_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_customer_promise_event_id`),
    KEY `ticket_customer_promise_event_promise` (`ticket_customer_promise_event_promise_id`,`ticket_customer_promise_event_id`),
    KEY `ticket_customer_promise_event_ticket` (`ticket_customer_promise_event_ticket_id`,`ticket_customer_promise_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket customer promise events');

$ticket_operations_trigger_exists = static function (string $trigger) use ($mysqli): bool {
    $trigger_sql = mysqli_real_escape_string($mysqli, $trigger);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema = DATABASE() AND trigger_name = '$trigger_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect ticket operational triggers: ' . mysqli_error($mysqli));
    }
    return intval(mysqli_fetch_row($result)[0] ?? 0) > 0;
};

$ticket_operations_immutable_triggers = [
    'ticket_operational_events_bu_immutable' => "CREATE TRIGGER `ticket_operational_events_bu_immutable`
        BEFORE UPDATE ON `ticket_operational_events` FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only'",
    'ticket_operational_events_bd_immutable' => "CREATE TRIGGER `ticket_operational_events_bd_immutable`
        BEFORE DELETE ON `ticket_operational_events` FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only'",
    'ticket_customer_promise_events_bu_immutable' => "CREATE TRIGGER `ticket_customer_promise_events_bu_immutable`
        BEFORE UPDATE ON `ticket_customer_promise_events` FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only'",
    'ticket_customer_promise_events_bd_immutable' => "CREATE TRIGGER `ticket_customer_promise_events_bd_immutable`
        BEFORE DELETE ON `ticket_customer_promise_events` FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only'",
];
foreach ($ticket_operations_immutable_triggers as $trigger_name => $trigger_sql) {
    if (!$ticket_operations_trigger_exists($trigger_name)) {
        $ticket_operations_query($trigger_sql, "Could not create immutable ledger trigger $trigger_name");
    }
}

$ticket_operations_query("CREATE TABLE IF NOT EXISTS `ticket_email_ingress` (
    `ticket_email_ingress_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_email_ingress_message_hash` char(64) NOT NULL,
    `ticket_email_ingress_claim_token` char(64) NOT NULL,
    `ticket_email_ingress_sender_hash` char(64) NOT NULL,
    `ticket_email_ingress_domain_hash` char(64) NOT NULL,
    `ticket_email_ingress_subject_hash` char(64) NOT NULL,
    `ticket_email_ingress_status` varchar(20) NOT NULL DEFAULT 'Processing',
    `ticket_email_ingress_attempts` int(11) NOT NULL DEFAULT 1,
    `ticket_email_ingress_ticket_id` int(11) NOT NULL DEFAULT 0,
    `ticket_email_ingress_reply_id` int(11) NOT NULL DEFAULT 0,
    `ticket_email_ingress_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_email_ingress_reason_code` varchar(60) DEFAULT NULL,
    `ticket_email_ingress_received_at` datetime NOT NULL DEFAULT current_timestamp(),
    `ticket_email_ingress_processing_at` datetime DEFAULT NULL,
    `ticket_email_ingress_completed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`ticket_email_ingress_id`),
    UNIQUE KEY `ticket_email_ingress_message` (`ticket_email_ingress_message_hash`),
    KEY `ticket_email_ingress_status` (`ticket_email_ingress_status`,`ticket_email_ingress_processing_at`),
    KEY `ticket_email_ingress_sender_window` (`ticket_email_ingress_sender_hash`,`ticket_email_ingress_received_at`),
    KEY `ticket_email_ingress_domain_window` (`ticket_email_ingress_domain_hash`,`ticket_email_ingress_received_at`),
    KEY `ticket_email_ingress_client_window` (`ticket_email_ingress_client_id`,`ticket_email_ingress_received_at`),
    KEY `ticket_email_ingress_ticket` (`ticket_email_ingress_ticket_id`,`ticket_email_ingress_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    'Could not create ticket email ingress ledger');

$ticket_operations_index_exists = static function (string $table, string $index) use ($mysqli): bool {
    $table_sql = mysqli_real_escape_string($mysqli, $table);
    $index_sql = mysqli_real_escape_string($mysqli, $index);
    $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = '$table_sql' AND index_name = '$index_sql'");
    if (!$result) {
        throw new RuntimeException('Could not inspect ticket operational indexes: ' . mysqli_error($mysqli));
    }
    return intval(mysqli_fetch_row($result)[0] ?? 0) > 0;
};

if (!$ticket_operations_index_exists('tickets', 'ticket_work_type_status')) {
    $ticket_operations_query("ALTER TABLE `tickets` ADD KEY `ticket_work_type_status` (`ticket_work_type`,`ticket_status`)",
        'Could not index ticket work type');
}
if (!$ticket_operations_index_exists('tickets', 'ticket_waiting_queue')) {
    $ticket_operations_query("ALTER TABLE `tickets` ADD KEY `ticket_waiting_queue` (`ticket_waiting_on`,`ticket_next_action_due_at`)",
        'Could not index the ticket waiting queue');
}
if (!$ticket_operations_index_exists('tickets', 'ticket_operational_priority')) {
    $ticket_operations_query("ALTER TABLE `tickets` ADD KEY `ticket_operational_priority` (`ticket_impact`,`ticket_urgency`)",
        'Could not index ticket priority dimensions');
}

// Fixed semantics for the five upstream built-in statuses. Status 3 is named
// On Hold upstream and Waiting on Client on N45 fresh installs; both pause.
$ticket_operations_query("UPDATE ticket_statuses SET ticket_status_pauses_sla = CASE
    WHEN ticket_status_id IN (3, 4, 5) THEN 1 ELSE 0 END
    WHERE ticket_status_id IN (1, 2, 3, 4, 5)",
    'Could not normalize built-in ticket status SLA behavior');

// Preserve canonical legacy priorities, normalize invalid/null legacy values
// to Low, and give each one a deterministic matrix representation. New writes
// derive priority from these dimensions instead.
$ticket_operations_query("UPDATE tickets SET
    ticket_work_type = CASE
        WHEN ticket_project_id > 0 OR ticket_source = 'Project Template' THEN 'project_task'
        WHEN ticket_source IN ('Automation', 'Level.io') THEN 'incident'
        ELSE 'request'
    END,
    ticket_impact = CASE
        WHEN ticket_priority = 'Urgent' THEN 'critical'
        WHEN ticket_priority = 'High' THEN 'high'
        WHEN ticket_priority = 'Medium' THEN 'medium'
        ELSE 'low'
    END,
    ticket_urgency = CASE
        WHEN ticket_priority = 'Urgent' THEN 'high'
        WHEN ticket_priority = 'High' THEN 'medium'
        WHEN ticket_priority = 'Medium' THEN 'medium'
        ELSE 'low'
    END,
    ticket_next_action = CASE
        WHEN ticket_resolved_at IS NOT NULL OR ticket_closed_at IS NOT NULL THEN 'No further action — terminal ticket.'
        ELSE 'Review and triage this ticket.'
    END,
    ticket_operational_updated_by = ticket_created_by,
    ticket_operational_updated_at = COALESCE(ticket_updated_at, ticket_created_at)
    WHERE ticket_operational_updated_at IS NULL",
    'Could not initialize legacy ticket operational state');

$ticket_operations_query("UPDATE tickets SET ticket_priority = CASE ticket_impact
    WHEN 'low' THEN CASE ticket_urgency WHEN 'low' THEN 'Low' WHEN 'medium' THEN 'Low' WHEN 'high' THEN 'Medium' ELSE 'High' END
    WHEN 'medium' THEN CASE ticket_urgency WHEN 'low' THEN 'Low' WHEN 'medium' THEN 'Medium' WHEN 'high' THEN 'High' ELSE 'High' END
    WHEN 'high' THEN CASE ticket_urgency WHEN 'low' THEN 'Medium' WHEN 'medium' THEN 'High' WHEN 'high' THEN 'High' ELSE 'Urgent' END
    ELSE CASE ticket_urgency WHEN 'low' THEN 'High' WHEN 'medium' THEN 'High' ELSE 'Urgent' END
END",
    'Could not normalize deterministic ticket priority');
