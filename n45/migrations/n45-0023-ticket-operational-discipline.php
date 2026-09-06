<?php

/*
 * N45 migration n45-0023-ticket-operational-discipline
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_work_type` varchar(20) NOT NULL DEFAULT 'incident'
        AFTER `ticket_request_type_key`,
    ADD COLUMN IF NOT EXISTS `ticket_impact` varchar(10) DEFAULT NULL
        AFTER `ticket_priority`,
    ADD COLUMN IF NOT EXISTS `ticket_urgency` varchar(10) DEFAULT NULL
        AFTER `ticket_impact`,
    ADD COLUMN IF NOT EXISTS `ticket_waiting_on` varchar(20) NOT NULL DEFAULT 'none'
        AFTER `ticket_urgency`,
    ADD COLUMN IF NOT EXISTS `ticket_next_action` varchar(500) DEFAULT NULL
        AFTER `ticket_waiting_on`,
    ADD COLUMN IF NOT EXISTS `ticket_next_action_due_at` datetime DEFAULT NULL
        AFTER `ticket_next_action`,
    ADD COLUMN IF NOT EXISTS `ticket_resolution_code` varchar(40) DEFAULT NULL
        AFTER `ticket_next_action_due_at`,
    ADD COLUMN IF NOT EXISTS `ticket_resolution_summary` text DEFAULT NULL
        AFTER `ticket_resolution_code`,
    ADD COLUMN IF NOT EXISTS `ticket_root_cause` text DEFAULT NULL
        AFTER `ticket_resolution_summary`,
    ADD COLUMN IF NOT EXISTS `ticket_closure_code` varchar(40) DEFAULT NULL
        AFTER `ticket_root_cause`,
    ADD INDEX IF NOT EXISTS `ticket_operations_queue`
        (`ticket_archived_at`, `ticket_closed_at`, `ticket_waiting_on`, `ticket_next_action_due_at`)")
) {
    throw new RuntimeException('Could not add ticket operational fields: '
        . mysqli_error($mysqli));
}

$tables = [
    "CREATE TABLE IF NOT EXISTS `ticket_work_notes` (
        `ticket_work_note_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_work_note_ticket_id` int(11) NOT NULL,
        `ticket_work_note_reply_id` int(11) NOT NULL DEFAULT 0,
        `ticket_work_note_client_id` int(11) NOT NULL DEFAULT 0,
        `ticket_work_note_action` varchar(500) NOT NULL,
        `ticket_work_note_result` varchar(500) NOT NULL,
        `ticket_work_note_next_step` varchar(500) NOT NULL,
        `ticket_work_note_blocking_dependency` varchar(500) DEFAULT NULL,
        `ticket_work_note_waiting_on` varchar(20) NOT NULL DEFAULT 'none',
        `ticket_work_note_next_action_due_at` datetime DEFAULT NULL,
        `ticket_work_note_actor_id` int(11) NOT NULL DEFAULT 0,
        `ticket_work_note_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`ticket_work_note_id`),
        KEY `ticket_work_note_ticket` (`ticket_work_note_ticket_id`, `ticket_work_note_created_at`),
        KEY `ticket_work_note_reply` (`ticket_work_note_reply_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `ticket_handoffs` (
        `ticket_handoff_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_handoff_ticket_id` int(11) NOT NULL,
        `ticket_handoff_client_id` int(11) NOT NULL DEFAULT 0,
        `ticket_handoff_from_user_id` int(11) NOT NULL DEFAULT 0,
        `ticket_handoff_to_user_id` int(11) NOT NULL DEFAULT 0,
        `ticket_handoff_reason` varchar(500) NOT NULL,
        `ticket_handoff_current_state` varchar(500) NOT NULL,
        `ticket_handoff_next_action` varchar(500) NOT NULL,
        `ticket_handoff_actor_id` int(11) NOT NULL DEFAULT 0,
        `ticket_handoff_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`ticket_handoff_id`),
        KEY `ticket_handoff_ticket` (`ticket_handoff_ticket_id`, `ticket_handoff_created_at`),
        KEY `ticket_handoff_receiver` (`ticket_handoff_to_user_id`, `ticket_handoff_created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `ticket_relationships` (
        `ticket_relationship_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_relationship_from_ticket_id` int(11) NOT NULL,
        `ticket_relationship_to_ticket_id` int(11) NOT NULL,
        `ticket_relationship_type` varchar(20) NOT NULL,
        `ticket_relationship_created_by` int(11) NOT NULL DEFAULT 0,
        `ticket_relationship_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `ticket_relationship_archived_by` int(11) NOT NULL DEFAULT 0,
        `ticket_relationship_archived_at` datetime DEFAULT NULL,
        PRIMARY KEY (`ticket_relationship_id`),
        KEY `ticket_relationship_from` (`ticket_relationship_from_ticket_id`, `ticket_relationship_archived_at`),
        KEY `ticket_relationship_to` (`ticket_relationship_to_ticket_id`, `ticket_relationship_archived_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `ticket_customer_promises` (
        `ticket_customer_promise_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_customer_promise_ticket_id` int(11) NOT NULL,
        `ticket_customer_promise_client_id` int(11) NOT NULL DEFAULT 0,
        `ticket_customer_promise_summary` varchar(500) NOT NULL,
        `ticket_customer_promise_due_at` datetime NOT NULL,
        `ticket_customer_promise_status` varchar(20) NOT NULL DEFAULT 'open',
        `ticket_customer_promise_created_by` int(11) NOT NULL DEFAULT 0,
        `ticket_customer_promise_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `ticket_customer_promise_completed_by` int(11) NOT NULL DEFAULT 0,
        `ticket_customer_promise_completed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`ticket_customer_promise_id`),
        KEY `ticket_customer_promise_ticket` (`ticket_customer_promise_ticket_id`, `ticket_customer_promise_status`, `ticket_customer_promise_due_at`),
        KEY `ticket_customer_promise_queue` (`ticket_customer_promise_status`, `ticket_customer_promise_due_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `ticket_customer_promise_events` (
        `ticket_customer_promise_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_customer_promise_event_promise_id` bigint(20) NOT NULL,
        `ticket_customer_promise_event_ticket_id` int(11) NOT NULL,
        `ticket_customer_promise_event_action` varchar(20) NOT NULL,
        `ticket_customer_promise_event_from_status` varchar(20) DEFAULT NULL,
        `ticket_customer_promise_event_to_status` varchar(20) NOT NULL,
        `ticket_customer_promise_event_actor_id` int(11) NOT NULL DEFAULT 0,
        `ticket_customer_promise_event_reason` varchar(500) NOT NULL,
        `ticket_customer_promise_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`ticket_customer_promise_event_id`),
        KEY `ticket_customer_promise_event_history` (`ticket_customer_promise_event_promise_id`, `ticket_customer_promise_event_created_at`),
        KEY `ticket_customer_promise_event_ticket` (`ticket_customer_promise_event_ticket_id`, `ticket_customer_promise_event_created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `ticket_resolution_events` (
        `ticket_resolution_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `ticket_resolution_event_ticket_id` int(11) NOT NULL,
        `ticket_resolution_event_client_id` int(11) NOT NULL DEFAULT 0,
        `ticket_resolution_event_action` varchar(20) NOT NULL,
        `ticket_resolution_event_resolution_code` varchar(40) DEFAULT NULL,
        `ticket_resolution_event_resolution_summary` text DEFAULT NULL,
        `ticket_resolution_event_root_cause` text DEFAULT NULL,
        `ticket_resolution_event_closure_code` varchar(40) DEFAULT NULL,
        `ticket_resolution_event_actor_type` varchar(20) NOT NULL DEFAULT 'agent',
        `ticket_resolution_event_actor_id` int(11) NOT NULL DEFAULT 0,
        `ticket_resolution_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`ticket_resolution_event_id`),
        KEY `ticket_resolution_event_ticket` (`ticket_resolution_event_ticket_id`, `ticket_resolution_event_created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];

foreach ($tables as $sql) {
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException('Could not create ticket operational-discipline tables: '
            . mysqli_error($mysqli));
    }
}

// Preserve the existing priority while giving every historical ticket a
// deterministic impact/urgency assessment for reporting and editing.
if (!mysqli_query($mysqli, "UPDATE tickets SET
    ticket_impact = COALESCE(ticket_impact, CASE ticket_priority
        WHEN 'Urgent' THEN 'high'
        WHEN 'High' THEN 'high'
        WHEN 'Low' THEN 'low'
        ELSE 'medium' END),
    ticket_urgency = COALESCE(ticket_urgency, CASE ticket_priority
        WHEN 'Urgent' THEN 'high'
        WHEN 'High' THEN 'medium'
        WHEN 'Low' THEN 'low'
        ELSE 'medium' END)
    WHERE ticket_impact IS NULL OR ticket_urgency IS NULL")
) {
    throw new RuntimeException('Could not backfill ticket impact and urgency: '
        . mysqli_error($mysqli));
}

// Replays only fill unset values, preserving assessments already made by staff.
if (!mysqli_query($mysqli, "ALTER TABLE tickets
    MODIFY COLUMN ticket_impact varchar(10) NOT NULL DEFAULT 'medium',
    MODIFY COLUMN ticket_urgency varchar(10) NOT NULL DEFAULT 'medium'")) {
    throw new RuntimeException('Could not finalize ticket assessment defaults: '
        . mysqli_error($mysqli));
}

// Terminal tickets created before structured resolution tracking remain
// readable and pass the new gate without inventing a detailed root cause.
if (!mysqli_query($mysqli, "UPDATE tickets SET
    ticket_resolution_code = COALESCE(ticket_resolution_code, 'legacy_completed'),
    ticket_resolution_summary = COALESCE(NULLIF(ticket_resolution_summary, ''),
        'Completed before structured resolution tracking was enabled')
    WHERE (ticket_resolved_at IS NOT NULL OR ticket_closed_at IS NOT NULL)
    AND ticket_resolution_code IS NULL")
) {
    throw new RuntimeException('Could not reconcile historical ticket resolutions: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE tickets SET
    ticket_closure_code = COALESCE(ticket_closure_code, 'legacy_closed')
    WHERE ticket_closed_at IS NOT NULL AND ticket_closure_code IS NULL")
) {
    throw new RuntimeException('Could not reconcile historical ticket closures: '
        . mysqli_error($mysqli));
}

