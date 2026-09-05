<?php

/*
 * Whole-ticket approval mutations. Task approvals remain in task.php; both
 * surfaces use the same route vocabulary and modal controls.
 */

if (isset($_POST['add_ticket_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    [$scope, $type] = approvalRouteParts($_POST['approval_route'] ?? '');
    $required_user_id = $scope === 'internal' && $type === 'specific'
        ? intval($_POST['approval_required_user_id'] ?? 0)
        : 0;
    $required_contact_id = $scope === 'client' && $type === 'specific'
        ? intval($_POST['approval_required_contact_id'] ?? 0)
        : 0;
    if (!$ticket_id || $scope === ''
        || ($scope === 'internal' && $type === 'specific' && !$required_user_id)
        || ($scope === 'client' && $type === 'specific' && !$required_contact_id)) {
        flashAlert('Choose who should approve this ticket.', 'error');
        redirect();
    }

    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id,
        ticket_contact_id, ticket_project_id, ticket_assigned_to, ticket_status,
        ticket_created_at, ticket_prefix, ticket_number, ticket_subject,
        ticket_resolved_at, ticket_closed_at FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1"));
    if (!$ticket || !empty($ticket['ticket_resolved_at']) || !empty($ticket['ticket_closed_at'])) {
        flashAlert('This ticket no longer accepts approval requests.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    [$route_available, $route_error] = runbookApprovalRouteAvailability(
        $scope,
        $type,
        $required_user_id,
        $ticket,
        $session_user_id,
        $required_contact_id
    );
    if (!$route_available) {
        flashAlert(escapeHtml($route_error), 'error');
        redirect();
    }

    $url_key_raw = randomString(32);
    $url_key_sql = escapeSql(runbookApprovalTokenHash($url_key_raw));
    $url_expires_at_value = runbookApprovalTokenExpiry();
    $url_expires_at_sql = escapeSql($url_expires_at_value);
    $scope_sql = escapeSql($scope);
    $type_sql = escapeSql($type);
    $required_user_sql = $required_user_id ? (string) $required_user_id : 'NULL';
    $required_contact_sql = $required_contact_id ? (string) $required_contact_id : 'NULL';
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket approval transaction');
        }
        $transaction_started = true;

        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $existing = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_approval_id
            FROM ticket_approvals WHERE ticket_approval_ticket_id = $ticket_id
            AND ticket_approval_status <> 'approved' LIMIT 1 FOR UPDATE",
            'Could not check for an existing ticket approval'));
        if ($existing) {
            throw new RuntimeException('This ticket already has an unresolved approval');
        }
        [$route_available, $route_error] = runbookApprovalRouteAvailability(
            $scope,
            $type,
            $required_user_id,
            $locked_ticket,
            $session_user_id,
            $required_contact_id
        );
        if (!$route_available) {
            throw new RuntimeException($route_error);
        }

        runbookDbQuery("INSERT INTO ticket_approvals SET
            ticket_approval_scope = '$scope_sql',
            ticket_approval_type = '$type_sql',
            ticket_approval_required_user_id = $required_user_sql,
            ticket_approval_required_contact_id = $required_contact_sql,
            ticket_approval_status = 'pending',
            ticket_approval_created_by = $session_user_id,
            ticket_approval_url_key = '$url_key_sql',
            ticket_approval_url_expires_at = '$url_expires_at_sql',
            ticket_approval_ticket_id = $ticket_id", 'Could not create the ticket approval');
        $approval_id = intval(mysqli_insert_id($mysqli));
        if (!$approval_id) {
            throw new RuntimeException('The ticket approval did not receive an ID');
        }
        ticketApprovalRecordEvent(
            $approval_id,
            $ticket_id,
            'created',
            [],
            [
                'status' => 'pending',
                'scope' => $scope,
                'type' => $type,
                'required_user_id' => $required_user_id,
                'required_contact_id' => $required_contact_id,
            ],
            'agent',
            $session_user_id,
            $session_name,
            'Ticket approval request created',
            $url_expires_at_value
        );
        ticketApprovalQueueNotification(
            $approval_id,
            $locked_ticket,
            $scope,
            $type,
            $required_user_id,
            $url_key_raw,
            $session_user_id,
            $required_contact_id
        );

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket approval');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id approval creation failed safely: " . $exception->getMessage());
        flashAlert('The approval request could not be sent. The ticket may already have an unresolved approval.', 'error');
        redirect();
    }

    $required_user_name = '';
    $required_contact_name = '';
    if ($required_user_id) {
        $required_user_name = (string) (mysqli_fetch_row(mysqli_query($mysqli,
            "SELECT user_name FROM users WHERE user_id = $required_user_id LIMIT 1"))[0] ?? '');
    }
    if ($required_contact_id) {
        $required_contact_name = (string) (mysqli_fetch_row(mysqli_query($mysqli,
            "SELECT contact_name FROM contacts WHERE contact_id = $required_contact_id
            AND contact_client_id = $client_id LIMIT 1"))[0] ?? '');
    }
    $route_label = approvalRouteLabel($scope, $type, $required_user_name, $required_contact_name);
    logTicketHistory($ticket_id, escapeSql("$session_name requested ticket approval from $route_label"));
    logAudit('Ticket', 'Edit', escapeSql("$session_name requested ticket approval from $route_label"), $client_id, $ticket_id);
    triggerCustomAction('ticket_update', $ticket_id);
    flashAlert('Ticket approval request sent');
    redirect();
}

if (isset($_POST['decide_ticket_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $approval_id = intval($_POST['ticket_approval_id'] ?? 0);
    $decision = (string) $_POST['decide_ticket_approval'];
    if (!$approval_id || !in_array($decision, ['approved', 'declined'], true)) {
        flashAlert('Choose approve or decline.', 'error');
        redirect();
    }

    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_approvals.*,
        ticket_id, ticket_client_id, ticket_contact_id, ticket_project_id,
        ticket_assigned_to, ticket_status, ticket_created_at, ticket_prefix,
        ticket_number, ticket_subject, ticket_resolved_at, ticket_closed_at
        FROM ticket_approvals
        INNER JOIN tickets ON ticket_id = ticket_approval_ticket_id
        WHERE ticket_approval_id = $approval_id
        AND ticket_approval_status = 'pending' LIMIT 1"));
    if (!$approval || !empty($approval['ticket_resolved_at']) || !empty($approval['ticket_closed_at'])) {
        flashAlert('This approval is no longer pending.', 'error');
        redirect();
    }
    $ticket_id = intval($approval['ticket_id']);
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    if (!ticketApprovalUserCanDecide($approval, $session_user_id)) {
        flashAlert('This approval is assigned to someone else.', 'error');
        redirect();
    }

    $decision_sql = escapeSql($decision);
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket approval decision');
        }
        $transaction_started = true;

        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT * FROM ticket_approvals
            WHERE ticket_approval_id = $approval_id
            AND ticket_approval_ticket_id = $ticket_id LIMIT 1 FOR UPDATE",
            'Could not lock the ticket approval'));
        if (!$locked_approval || !ticketApprovalUserCanDecide($locked_approval, $session_user_id)) {
            throw new RuntimeException('The ticket approval is no longer actionable');
        }

        $required_user_id = intval($locked_approval['ticket_approval_required_user_id']);
        $required_contact_id = intval($locked_approval['ticket_approval_required_contact_id']);
        runbookDbQuery("UPDATE ticket_approvals SET
            ticket_approval_status = '$decision_sql',
            ticket_approval_decided_by = '$session_user_id',
            ticket_approval_decided_at = NOW(), ticket_approval_url_key = '',
            ticket_approval_url_expires_at = NULL
            WHERE ticket_approval_id = $approval_id
            AND ticket_approval_ticket_id = $ticket_id
            AND ticket_approval_status = 'pending'", 'Could not decide the ticket approval');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket approval was already decided');
        }
        ticketApprovalRecordEvent(
            $approval_id,
            $ticket_id,
            $decision,
            [
                'status' => 'pending',
                'scope' => $locked_approval['ticket_approval_scope'],
                'type' => $locked_approval['ticket_approval_type'],
                'required_user_id' => $required_user_id,
                'required_contact_id' => $required_contact_id,
            ],
            [
                'status' => $decision,
                'scope' => $locked_approval['ticket_approval_scope'],
                'type' => $locked_approval['ticket_approval_type'],
                'required_user_id' => $required_user_id,
                'required_contact_id' => $required_contact_id,
            ],
            'agent',
            $session_user_id,
            $session_name
        );

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket approval decision');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket approval $approval_id decision failed safely: " . $exception->getMessage());
        flashAlert('This approval was already decided. Refresh the ticket.', 'error');
        redirect();
    }

    $created_by = intval($approval['ticket_approval_created_by']);
    if ($created_by && $created_by !== $session_user_id) {
        $notification = escapeSql("$session_name $decision ticket approval for "
            . (string) $approval['ticket_prefix'] . intval($approval['ticket_number']));
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket',
            notification = '$notification', notification_action = 'ticket.php?ticket_id=$ticket_id',
            notification_client_id = $client_id, notification_user_id = $created_by");
    }
    logTicketHistory($ticket_id, escapeSql("$session_name $decision ticket approval $approval_id"));
    logAudit('Ticket', 'Edit', escapeSql("$session_name $decision ticket approval $approval_id"), $client_id, $ticket_id);
    triggerCustomAction('ticket_update', $ticket_id);
    flashAlert('Ticket approval ' . escapeHtml($decision));
    redirect();
}

if (isset($_POST['retry_ticket_approval']) || isset($_POST['reroute_ticket_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $reroute = isset($_POST['reroute_ticket_approval']);
    $approval_id = intval($_POST['approval_id'] ?? 0);
    $reason = trim((string) ($_POST['approval_reason'] ?? ''));
    if (!$approval_id || $reason === '') {
        flashAlert('Explain why the approval is being sent again.', 'error');
        redirect();
    }

    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_approvals.*,
        ticket_id, ticket_client_id, ticket_contact_id, ticket_project_id,
        ticket_assigned_to, ticket_status, ticket_created_at, ticket_prefix,
        ticket_number, ticket_subject, ticket_resolved_at, ticket_closed_at
        FROM ticket_approvals
        INNER JOIN tickets ON ticket_id = ticket_approval_ticket_id
        WHERE ticket_approval_id = $approval_id
        AND ticket_approval_status IN ('pending','declined') LIMIT 1"));
    if (!$approval || !empty($approval['ticket_resolved_at']) || !empty($approval['ticket_closed_at'])) {
        flashAlert('This approval can no longer be changed.', 'error');
        redirect();
    }
    $ticket_id = intval($approval['ticket_id']);
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    if ($reroute) {
        [$scope, $type] = approvalRouteParts($_POST['approval_route'] ?? '');
        $required_user_id = $scope === 'internal' && $type === 'specific'
            ? intval($_POST['approval_required_user_id'] ?? 0)
            : 0;
        $required_contact_id = $scope === 'client' && $type === 'specific'
            ? intval($_POST['approval_required_contact_id'] ?? 0)
            : 0;
    } else {
        $scope = (string) $approval['ticket_approval_scope'];
        $type = (string) $approval['ticket_approval_type'];
        $required_user_id = intval($approval['ticket_approval_required_user_id']);
        $required_contact_id = intval($approval['ticket_approval_required_contact_id']);
    }
    if ($scope === ''
        || ($scope === 'internal' && $type === 'specific' && !$required_user_id)
        || ($scope === 'client' && $type === 'specific' && !$required_contact_id)) {
        flashAlert('Choose who should receive the approval request.', 'error');
        redirect();
    }
    [$route_available, $route_error] = runbookApprovalRouteAvailability(
        $scope,
        $type,
        $required_user_id,
        $approval,
        intval($approval['ticket_approval_created_by']),
        $required_contact_id
    );
    if (!$route_available) {
        flashAlert(escapeHtml($route_error), 'error');
        redirect();
    }

    $url_key_raw = randomString(32);
    $url_key_sql = escapeSql(runbookApprovalTokenHash($url_key_raw));
    $url_expires_at_value = runbookApprovalTokenExpiry();
    $url_expires_at_sql = escapeSql($url_expires_at_value);
    $scope_sql = escapeSql($scope);
    $type_sql = escapeSql($type);
    $required_user_sql = $required_user_id ? (string) $required_user_id : 'NULL';
    $required_contact_sql = $required_contact_id ? (string) $required_contact_id : 'NULL';
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket approval update');
        }
        $transaction_started = true;

        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT * FROM ticket_approvals
            WHERE ticket_approval_id = $approval_id
            AND ticket_approval_ticket_id = $ticket_id LIMIT 1 FOR UPDATE",
            'Could not lock the ticket approval for update'));
        if (!$locked_approval
            || !in_array($locked_approval['ticket_approval_status'], ['pending', 'declined'], true)) {
            throw new RuntimeException('The ticket approval is no longer editable');
        }
        if (!$reroute) {
            $scope = (string) $locked_approval['ticket_approval_scope'];
            $type = (string) $locked_approval['ticket_approval_type'];
            $required_user_id = intval($locked_approval['ticket_approval_required_user_id']);
            $required_contact_id = intval($locked_approval['ticket_approval_required_contact_id']);
            $scope_sql = escapeSql($scope);
            $type_sql = escapeSql($type);
            $required_user_sql = $required_user_id ? (string) $required_user_id : 'NULL';
            $required_contact_sql = $required_contact_id ? (string) $required_contact_id : 'NULL';
        }
        [$route_available, $route_error] = runbookApprovalRouteAvailability(
            $scope,
            $type,
            $required_user_id,
            $locked_ticket,
            intval($locked_approval['ticket_approval_created_by']),
            $required_contact_id
        );
        if (!$route_available) {
            throw new RuntimeException($route_error);
        }

        runbookDbQuery("UPDATE ticket_approvals SET
            ticket_approval_scope = '$scope_sql', ticket_approval_type = '$type_sql',
            ticket_approval_required_user_id = $required_user_sql,
            ticket_approval_required_contact_id = $required_contact_sql,
            ticket_approval_status = 'pending', ticket_approval_decided_by = NULL,
            ticket_approval_decided_at = NULL, ticket_approval_url_key = '$url_key_sql',
            ticket_approval_url_expires_at = '$url_expires_at_sql'
            WHERE ticket_approval_id = $approval_id
            AND ticket_approval_ticket_id = $ticket_id
            AND ticket_approval_status IN ('pending','declined')",
            'Could not update the ticket approval request');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket approval changed before it could be sent again');
        }

        ticketApprovalRecordEvent(
            $approval_id,
            $ticket_id,
            $reroute ? 'rerouted' : 're_requested',
            [
                'status' => $locked_approval['ticket_approval_status'],
                'scope' => $locked_approval['ticket_approval_scope'],
                'type' => $locked_approval['ticket_approval_type'],
                'required_user_id' => intval($locked_approval['ticket_approval_required_user_id']),
                'required_contact_id' => intval($locked_approval['ticket_approval_required_contact_id']),
            ],
            [
                'status' => 'pending',
                'scope' => $scope,
                'type' => $type,
                'required_user_id' => $required_user_id,
                'required_contact_id' => $required_contact_id,
            ],
            'agent',
            $session_user_id,
            $session_name,
            $reason,
            $url_expires_at_value
        );
        ticketApprovalQueueNotification(
            $approval_id,
            $locked_ticket,
            $scope,
            $type,
            $required_user_id,
            $url_key_raw,
            intval($locked_approval['ticket_approval_created_by']),
            $required_contact_id
        );

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket approval update');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket approval $approval_id update failed safely: " . $exception->getMessage());
        flashAlert('The approval could not be sent again. The ticket or approval may have changed.', 'error');
        redirect();
    }

    $action_label = $reroute ? 'rerouted and re-sent' : 'sent again';
    $audit_description = escapeSql("$session_name $action_label ticket approval $approval_id: $reason");
    logTicketHistory($ticket_id, $audit_description);
    logAudit('Ticket', 'Edit', $audit_description, $client_id, $ticket_id);
    triggerCustomAction('ticket_update', $ticket_id);
    flashAlert('Ticket approval request sent again');
    redirect();
}
