<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse ID
$ticket_id = intval($_POST['ticket_id']);

// Default
$update_count = false;

if (!empty($ticket_id)) {
    $ticket_transaction_started = false;

    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the API ticket update transaction');
        }
        $ticket_transaction_started = true;

        $ticket_sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE");
        if (!$ticket_sql || !($ticket_row = mysqli_fetch_assoc($ticket_sql))) {
            throw new RuntimeException('The API ticket is not available for this client');
        }

        // Assign model values from POST, falling back to the current ticket values.
        require_once 'ticket_model.php';

        // Lock and validate every client-owned relationship before persisting it. A
        // zero ID means that the optional relationship is being cleared.
        $client_relationships = [
            ['contacts', 'contact_id', 'contact_client_id', $contact],
            ['assets', 'asset_id', 'asset_client_id', $asset],
            ['vendors', 'vendor_id', 'vendor_client_id', $vendor_id],
        ];
        foreach ($client_relationships as [$table, $id_column, $client_column, $relationship_id]) {
            $relationship_id = intval($relationship_id);
            if ($relationship_id > 0) {
                $relationship_sql = mysqli_query($mysqli, "SELECT $id_column FROM $table WHERE $id_column = $relationship_id AND $client_column = $client_id LIMIT 1 FOR UPDATE");
                if (!$relationship_sql || !mysqli_fetch_assoc($relationship_sql)) {
                    throw new RuntimeException("The selected ticket $table relationship is not available for this client");
                }
            }
        }

        $ticket_id = intval($ticket_row['ticket_id']);
        $ticket_prefix = escapeSql($ticket_row['ticket_prefix']);
        $ticket_number = intval($ticket_row['ticket_number']);

        $update_sql = mysqli_query($mysqli, "UPDATE tickets SET ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = $billable, ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_vendor_id = $vendor_id, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact, ticket_asset_id = $asset WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1");
        if (!$update_sql) {
            throw new RuntimeException('Could not update the API ticket');
        }
        $update_count = mysqli_affected_rows($mysqli);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the API ticket update');
        }
        $ticket_transaction_started = false;

        logTicketHistory($ticket_id, "Edited via the API ($api_key_name)");
        logAudit("Ticket", "Edit", "$ticket_prefix$ticket_number ticket via API ($api_key_name)", $client_id, $ticket_id);
        logAudit("API", "Success", "Edited ticket $ticket_prefix$ticket_number via API ($api_key_name)", $client_id, $ticket_id);
    } catch (Throwable $exception) {
        if ($ticket_transaction_started) {
            mysqli_rollback($mysqli);
        }
        $update_count = false;
        error_log('API ticket update failed before publication: ' . $exception->getMessage());
    }
}

// Output
require_once '../update_output.php';
