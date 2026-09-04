<?php

// Close endpoint for tickets
// Just send a POST here with a ticket id and client id, and we do the rest

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse Info
$ticket_id = intval($_POST['ticket_id']);

// Default
$update_count = false;

if (!empty($ticket_id)) {
    $transaction_started = false;
    $ticket_prefix = '';
    $ticket_number = 0;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket close transaction');
        }
        $transaction_started = true;

        documentationLockClientTicket($ticket_id, $client_id);
        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket is outside this API key client scope');
        }
        [$can_close] = runbookTicketCanResolve($ticket_id);
        if (!$can_close) {
            throw new RuntimeException('The ticket close gate is not satisfied');
        }

        $locked_status = intval($locked_ticket['ticket_status']);
        $resolved_at_predicate = empty($locked_ticket['ticket_resolved_at'])
            ? 'ticket_resolved_at IS NULL'
            : "ticket_resolved_at = '" . escapeSql($locked_ticket['ticket_resolved_at']) . "'";
        $update_sql = mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5,
            ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()), ticket_closed_at = NOW(),
            ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id
            AND ticket_client_id = $client_id AND ticket_status = $locked_status
            AND $resolved_at_predicate AND ticket_closed_at IS NULL LIMIT 1");
        if (!$update_sql || mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket changed before it could be closed');
        }
        documentationRecordChangePassport($ticket_id, 5, $session_user_id, true);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket closure');
        }
        $transaction_started = false;
        $update_count = 1;
        $ticket_prefix = escapeSql($locked_ticket['ticket_prefix']);
        $ticket_number = intval($locked_ticket['ticket_number']);
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log('Ticket close API failed closed for ticket ' . $ticket_id . ': ' . $e->getMessage());
    }

    if ($update_count === 1) {
        // SLA and side effects run only after the terminal transition and its
        // Change Passport are durable.
        syncTicketSlaClock($ticket_id);
        setTicketResolutionSlaMet($ticket_id);

        logTicketHistory($ticket_id, "Closed via the API ($api_key_name)");
        logAudit("Ticket", "Closed", "$ticket_prefix$ticket_number ticket via API ($api_key_name)", $client_id, $ticket_id);
        logAudit("API", "Success", "Closed ticket $ticket_prefix$ticket_number via API ($api_key_name)", $client_id);

        triggerCustomAction('ticket_close', $ticket_id);
    }
}

// Output
require_once '../update_output.php';
