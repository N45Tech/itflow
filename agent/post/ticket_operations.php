<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

if (isset($_POST['edit_ticket_operations']) || isset($_POST['add_ticket_relationship'])
    || isset($_POST['remove_ticket_relationship']) || isset($_POST['add_ticket_promise'])
    || isset($_POST['cancel_ticket_promise'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $active_ticket_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_prefix,
        ticket_number FROM tickets WHERE ticket_id = $ticket_id $active_ticket_scope LIMIT 1"));
    if (!$ticket) {
        flashAlert('Ticket not found', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    try {
        if (isset($_POST['edit_ticket_operations'])) {
            $state = ticketOperationalUpdateTicket($ticket_id, $_POST, $session_user_id, 'agent');
            logTicketHistory($ticket_id, "$session_name updated the operational plan; priority is {$state['priority']}");
            logAudit('Ticket', 'Edit', "$session_name updated operational state for ticket {$ticket['ticket_prefix']}{$ticket['ticket_number']}", $client_id, $ticket_id);
            flashAlert('Operational plan updated');
        } elseif (isset($_POST['add_ticket_relationship'])) {
            ticketOperationalAddRelationship($ticket_id, intval($_POST['target_ticket_id'] ?? 0), $_POST['relationship_type'] ?? '', $session_user_id);
            logAudit('Ticket', 'Edit', "$session_name linked a related ticket to {$ticket['ticket_prefix']}{$ticket['ticket_number']}", $client_id, $ticket_id);
            flashAlert('Ticket relationship added');
        } elseif (isset($_POST['remove_ticket_relationship'])) {
            ticketOperationalRemoveRelationship(intval($_POST['relationship_id'] ?? 0), $ticket_id, $session_user_id);
            logAudit('Ticket', 'Edit', "$session_name removed a ticket relationship from {$ticket['ticket_prefix']}{$ticket['ticket_number']}", $client_id, $ticket_id);
            flashAlert('Ticket relationship removed');
        } elseif (isset($_POST['add_ticket_promise'])) {
            ticketOperationalCreatePromise($ticket_id, $_POST['promise_type'] ?? '', $_POST['promise_summary'] ?? '', $_POST['promise_due_at'] ?? '', $session_user_id);
            logAudit('Ticket', 'Edit', "$session_name recorded a customer promise on {$ticket['ticket_prefix']}{$ticket['ticket_number']}", $client_id, $ticket_id);
            flashAlert('Customer promise recorded');
        } else {
            ticketOperationalCancelPromise(intval($_POST['promise_id'] ?? 0), $ticket_id, $session_user_id);
            logAudit('Ticket', 'Edit', "$session_name cancelled a customer promise on {$ticket['ticket_prefix']}{$ticket['ticket_number']}", $client_id, $ticket_id);
            flashAlert('Customer promise cancelled');
        }
    } catch (Throwable $exception) {
        error_log("Ticket $ticket_id operational update failed: " . $exception->getMessage());
        flashAlert(escapeHtml($exception->getMessage()), 'error');
    }
    redirect();
}
