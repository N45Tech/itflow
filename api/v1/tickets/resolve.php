<?php

// Resolve endpoint for tickets
// Just send a POST here with a ticket & client id, and we do the rest

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse Info
$ticket_id = intval($_POST['ticket_id']);

// Default
$update_count = false;

if (!empty($ticket_id)) {

    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_first_response_at, ticket_id, ticket_number, ticket_prefix FROM tickets WHERE ticket_id = '$ticket_id' AND ticket_deleted_at IS NULL AND ticket_resolved_at IS NULL AND ticket_client_id = $client_id LIMIT 1"));

    if ($ticket_row) {
        // Grab what we need, not using the model
        $ticket_id = intval($ticket_row['ticket_id']);
        $ticket_prefix = escapeSql($ticket_row['ticket_prefix']);
        $ticket_number = intval($ticket_row['ticket_number']);
        $ticket_first_response_at = escapeSql($ticket_row['ticket_first_response_at']);

        try {
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin the ticket resolution transaction');
            }
            documentationLockClientTicket($ticket_id, $client_id);
            $locked_ticket = runbookLockOpenTicket($ticket_id);
            if (intval($locked_ticket['ticket_client_id']) !== intval($client_id)) {
                throw new RuntimeException('The ticket is outside this API key client scope');
            }
            [$can_resolve] = runbookTicketCanResolve($ticket_id);
            if (!$can_resolve) {
                mysqli_rollback($mysqli);
                require '../update_output.php';
                exit;
            }

            $locked_status = intval($locked_ticket['ticket_status']);
            $resolved_at_predicate = empty($locked_ticket['ticket_resolved_at'])
                ? 'ticket_resolved_at IS NULL'
                : "ticket_resolved_at = '" . escapeSql($locked_ticket['ticket_resolved_at']) . "'";
            $update_sql = mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW()
                WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
                AND ticket_deleted_at IS NULL
                AND ticket_status = $locked_status AND $resolved_at_predicate
                AND ticket_closed_at IS NULL LIMIT 1");
            if (!$update_sql || mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket changed before it could be resolved');
            }
            documentationRecordChangePassport($ticket_id, 4, $session_user_id, true);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the ticket resolution');
            }
            $update_count = 1;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            error_log('Ticket resolve API failed closed for ticket ' . $ticket_id . ': ' . $e->getMessage());
        }

        if ($update_count === 1) {
            // SLA, history, audit, notifications and external actions run only
            // after the locked state transition has committed.
            if (empty($ticket_first_response_at)) {
                setTicketFirstResponse($ticket_id);
            }
            syncTicketSlaClock($ticket_id);
            setTicketResolutionSlaMet($ticket_id);

        // Logging
        logTicketHistory($ticket_id, "Resolved via the API ($api_key_name)");

        logAudit("Ticket", "Resolved", "$ticket_prefix$ticket_number ticket via API ($api_key_name)", $client_id, $ticket_id);
        logAudit("API", "Success", "Resolved ticket $ticket_prefix$ticket_number via API ($api_key_name)", $client_id);

            triggerCustomAction('ticket_resolve', $ticket_id);
        }
    }

}

// Output
require_once '../update_output.php';
