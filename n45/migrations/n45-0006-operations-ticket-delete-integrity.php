<?php

/*
 * N45 migration n45-0006-operations-ticket-delete-integrity (legacy marker 2.7.3)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

// Remove Operations history whose ticket was already deleted before ticket
// deletion and Operations cleanup were made atomic.
if (!mysqli_query($mysqli, "DELETE automation_events FROM automation_events
    INNER JOIN automation_incidents
        ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    LEFT JOIN tickets ON ticket_id = automation_incident_ticket_id
    WHERE automation_incident_ticket_id > 0 AND ticket_id IS NULL")) {
    throw new RuntimeException('Could not remove orphaned Operations event history: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "DELETE automation_incidents FROM automation_incidents
    LEFT JOIN tickets ON ticket_id = automation_incident_ticket_id
    WHERE automation_incident_ticket_id > 0 AND ticket_id IS NULL")) {
    throw new RuntimeException('Could not remove orphaned Operations incidents: ' . mysqli_error($mysqli));
}

// Merge the automation-created N45 alias into the canonical internal client.
// The duplicate is archived rather than destroyed so its audit trail remains
// recoverable. This block is deliberately conditional and idempotent.
$canonical_client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM clients
    WHERE client_name = 'N45 Technology Solutions' AND client_archived_at IS NULL
    ORDER BY client_id LIMIT 1"));
$alias_clients = mysqli_query($mysqli, "SELECT client_id FROM clients
    WHERE client_name IN ('N45 Technologies', 'N45 Tech Solutions')
    AND client_notes = 'Created by the N45 automation resolver.'
    AND client_archived_at IS NULL ORDER BY client_id");

if ($canonical_client && $alias_clients) {
    $canonical_client_id = intval($canonical_client['client_id']);
    while ($alias_client = mysqli_fetch_assoc($alias_clients)) {
        $alias_client_id = intval($alias_client['client_id']);
        if ($alias_client_id < 1 || $alias_client_id === $canonical_client_id) {
            continue;
        }

        if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings
            SET automation_mapping_client_id = $canonical_client_id,
                automation_mapping_location_id = 0,
                automation_mapping_updated_at = NOW()
            WHERE automation_mapping_client_id = $alias_client_id")) {
            throw new RuntimeException('Could not move automation mappings to the canonical N45 client: ' . mysqli_error($mysqli));
        }
        if (!mysqli_query($mysqli, "UPDATE automation_incidents
            SET automation_incident_client_id = $canonical_client_id,
                automation_incident_location_id = 0,
                automation_incident_updated_at = NOW()
            WHERE automation_incident_client_id = $alias_client_id")) {
            throw new RuntimeException('Could not move Operations incidents to the canonical N45 client: ' . mysqli_error($mysqli));
        }
        if (!mysqli_query($mysqli, "UPDATE locations SET location_archived_at = COALESCE(location_archived_at, NOW())
            WHERE location_client_id = $alias_client_id")) {
            throw new RuntimeException('Could not archive duplicate N45 locations: ' . mysqli_error($mysqli));
        }
        if (!mysqli_query($mysqli, "UPDATE clients SET client_archived_at = COALESCE(client_archived_at, NOW())
            WHERE client_id = $alias_client_id")) {
            throw new RuntimeException('Could not archive the duplicate N45 client: ' . mysqli_error($mysqli));
        }
    }
}
