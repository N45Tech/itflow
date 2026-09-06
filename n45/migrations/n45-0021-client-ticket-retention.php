<?php

/*
 * N45 migration n45-0021-client-ticket-retention
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `clients`
    ADD COLUMN IF NOT EXISTS `client_ticket_retention_policy` varchar(20) NOT NULL DEFAULT 'override'
        AFTER `client_notes`")) {
    throw new RuntimeException('Could not add client ticket retention policy: '
        . mysqli_error($mysqli));
}

// Reconcile the pre-policy ticket lifecycle projection. Older close paths could
// leave a linked Operations incident open even though its ticket was terminal.
if (!mysqli_query($mysqli, "UPDATE automation_incidents
    INNER JOIN tickets ON ticket_id = automation_incident_ticket_id
    SET automation_incident_status = 'Resolved',
        automation_incident_resolved_at = COALESCE(
            automation_incident_resolved_at, ticket_closed_at, NOW()
        ),
        automation_incident_last_action = 'ticket_closed_reconciled'
    WHERE ticket_closed_at IS NOT NULL
    AND automation_incident_status <> 'Resolved'")) {
    throw new RuntimeException('Could not reconcile closed ticket incident state: '
        . mysqli_error($mysqli));
}
