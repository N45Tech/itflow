<?php

/*
 * N45 migration n45-0022-recoverable-ticket-deletion
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `clients`
    ADD COLUMN IF NOT EXISTS `client_ticket_retention_days` int(11) NOT NULL DEFAULT 30
        AFTER `client_ticket_retention_policy`")) {
    throw new RuntimeException('Could not add the client ticket retention period: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `tickets`
    ADD COLUMN IF NOT EXISTS `ticket_deleted_by` int(11) NOT NULL DEFAULT 0
        AFTER `ticket_archived_at`,
    ADD COLUMN IF NOT EXISTS `ticket_delete_reason` varchar(500) DEFAULT NULL
        AFTER `ticket_deleted_by`,
    ADD COLUMN IF NOT EXISTS `ticket_restore_until` datetime DEFAULT NULL
        AFTER `ticket_delete_reason`,
    ADD INDEX IF NOT EXISTS `ticket_restore_queue`
        (`ticket_archived_at`, `ticket_restore_until`, `ticket_client_id`)")
) {
    throw new RuntimeException('Could not add recoverable ticket-deletion fields: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_deletion_events` (
    `ticket_deletion_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_deletion_event_ticket_id` int(11) NOT NULL,
    `ticket_deletion_event_client_id` int(11) NOT NULL DEFAULT 0,
    `ticket_deletion_event_ticket_reference` varchar(255) NOT NULL,
    `ticket_deletion_event_ticket_subject` varchar(500) NOT NULL,
    `ticket_deletion_event_action` varchar(20) NOT NULL,
    `ticket_deletion_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `ticket_deletion_event_reason` varchar(500) NOT NULL,
    `ticket_deletion_event_policy` varchar(20) NOT NULL,
    `ticket_deletion_event_restore_until` datetime DEFAULT NULL,
    `ticket_deletion_event_context_hash` char(64) NOT NULL,
    `ticket_deletion_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_deletion_event_id`),
    KEY `ticket_deletion_event_ticket` (`ticket_deletion_event_ticket_id`, `ticket_deletion_event_created_at`),
    KEY `ticket_deletion_event_client` (`ticket_deletion_event_client_id`, `ticket_deletion_event_created_at`),
    KEY `ticket_deletion_event_action` (`ticket_deletion_event_action`, `ticket_deletion_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")
) {
    throw new RuntimeException('Could not create ticket deletion events: '
        . mysqli_error($mysqli));
}

// ticket_archived_at existed upstream but had no restore semantics. Preserve
// any pre-existing archived ticket and give it the same deterministic window.
if (!mysqli_query($mysqli, "UPDATE tickets SET
    ticket_restore_until = DATE_ADD(ticket_archived_at, INTERVAL 30 DAY),
    ticket_delete_reason = COALESCE(NULLIF(ticket_delete_reason, ''),
        'Archived before recoverable deletion was enabled')
    WHERE ticket_archived_at IS NOT NULL AND ticket_restore_until IS NULL")
) {
    throw new RuntimeException('Could not reconcile previously archived tickets: '
        . mysqli_error($mysqli));
}

