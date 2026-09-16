<?php

/*
 * N45 migration n45-0028-ticket-delete-operations-alignment
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

// Recover incidents left open by the historical soft-delete path. Future
// deletions resolve the linked Operations projection in the same transaction.
if (!mysqli_query($mysqli, "UPDATE automation_incidents
    INNER JOIN tickets ON ticket_id = automation_incident_ticket_id
    SET automation_incident_status = 'Resolved',
        automation_incident_resolved_at = COALESCE(automation_incident_resolved_at, NOW()),
        automation_incident_last_action = 'ticket_deleted'
    WHERE ticket_archived_at IS NOT NULL
    AND automation_incident_status <> 'Resolved'")) {
    throw new RuntimeException(
        'Could not reconcile Operations incidents linked to deleted tickets: ' . mysqli_error($mysqli)
    );
}
