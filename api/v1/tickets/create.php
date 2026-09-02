<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Ticket-related settings
require_once "../../../includes/load_global_settings.php";

$sql = mysqli_query($mysqli, "SELECT company_name, company_phone FROM companies WHERE company_id = 1");
$row = mysqli_fetch_assoc($sql);
$company_name = $row['company_name'];
$company_phone = formatPhoneNumber($row['company_phone']);

// Parse Info
$ticket_row = false; // Creation, not an update
require_once 'ticket_model.php';

// Default
$insert_id = false;

if (!empty($subject)) {

    if (!is_int($client_id)) {
        $client_id = 0;
    }

    // If no contact is selected automatically choose the primary contact for the client (if client set)
    if ($contact == 0 && $client_id != 0) {
        $sql = mysqli_query($mysqli,"SELECT contact_id FROM contacts WHERE contact_client_id = $client_id AND contact_primary = 1");
        $row = mysqli_fetch_assoc($sql);
        $contact = intval($row['contact_id']);
    }

    $ticket_transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the API ticket transaction');
        }
        $ticket_transaction_started = true;
        if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The API ticket client is no longer available');
        }

        ticketCreationDbQuery("
            UPDATE settings
            SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
            WHERE company_id = 1
        ", 'Could not allocate an API ticket number');
        $ticket_number = intval(mysqli_insert_id($mysqli));
        if (!$ticket_number) {
            throw new RuntimeException('The API ticket number allocation returned no number');
        }

        // Insert ticket
        $url_key = randomString(32);
        $insert_sql = ticketCreationDbQuery("INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'API', ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_status = 1, ticket_billable = $billable, ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_vendor_id = $vendor_id, ticket_created_by = 0, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact, ticket_asset_id = $asset, ticket_url_key = '$url_key', ticket_client_id = $client_id", 'Could not create the API ticket');
        $insert_id = intval(mysqli_insert_id($mysqli));
        if (!$insert_id) {
            throw new RuntimeException('The API ticket did not receive an ID');
        }
        applyTicketSla($insert_id, null, null, true);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the API ticket and SLA decision');
        }
        $ticket_transaction_started = false;
    } catch (Throwable $exception) {
        if ($ticket_transaction_started) {
            mysqli_rollback($mysqli);
        }
        $insert_id = false;
        error_log('API ticket creation failed before publication: ' . $exception->getMessage());
    }

    if ($insert_id !== false) {
        // Logging only sees a ticket whose SLA decision committed.
        logAudit("Ticket", "Create", "Created ticket $config_ticket_prefix$ticket_number $subject via API ($api_key_name)", $client_id, $insert_id);
        logAudit("API", "Success", "Created ticket $config_ticket_prefix$ticket_number $subject via API ($api_key_name)", $client_id);
    }

}

// Output
require_once '../create_output.php';
