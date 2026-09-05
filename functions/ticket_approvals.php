<?php

/*
 * Ticket-level approval gates.
 *
 * Task approvals remain attached to their individual task projection. Ticket
 * approvals live here so a ticket with no tasks can still require a decision
 * without manufacturing a hidden task or weakening the existing runbook gate.
 */

function approvalRouteParts($route) {
    $parts = explode(':', (string) $route, 2);
    $scope = $parts[0] ?? '';
    $type = $parts[1] ?? '';
    $valid = ($scope === 'internal' && in_array($type, ['any', 'specific'], true))
        || ($scope === 'client' && in_array($type, ['any', 'manager', 'technical', 'billing'], true));

    return $valid ? [$scope, $type] : ['', ''];
}

function approvalRouteLabel($scope, $type, $specific_user_name = '') {
    $scope = (string) $scope;
    $type = (string) $type;
    $specific_user_name = trim((string) $specific_user_name);

    if ($scope === 'internal' && $type === 'specific') {
        return $specific_user_name !== '' ? $specific_user_name : 'Specific internal user';
    }
    if ($scope === 'internal' && $type === 'any') {
        return 'Any other internal user';
    }
    if ($scope === 'client' && $type === 'manager') {
        return 'Any portal manager except the ticket contact';
    }
    if ($scope === 'client' && $type === 'technical') {
        return 'Any technical client contact';
    }
    if ($scope === 'client' && $type === 'billing') {
        return 'Any billing client contact';
    }
    if ($scope === 'client' && $type === 'any') {
        return 'The contact on this ticket';
    }

    return 'Unavailable approval route';
}

function ticketApprovalContactCanDecide($type, $ticket_contact_id, $contact_id, $is_manager, $is_technical, $is_billing) {
    $type = (string) $type;
    $ticket_contact_id = intval($ticket_contact_id);
    $contact_id = intval($contact_id);
    if (!$contact_id) {
        return false;
    }

    if ($type === 'any') {
        return $ticket_contact_id > 0 && $contact_id === $ticket_contact_id;
    }
    if ($type === 'manager') {
        return $contact_id !== $ticket_contact_id && (bool) $is_manager;
    }
    if ($type === 'technical') {
        return (bool) $is_technical;
    }
    if ($type === 'billing') {
        return (bool) $is_billing;
    }

    return false;
}

function ticketApprovalUserCanDecide($approval, $user_id) {
    $user_id = intval($user_id);
    if (!$approval || $approval['ticket_approval_scope'] !== 'internal'
        || $approval['ticket_approval_status'] !== 'pending'
        || intval($approval['ticket_approval_created_by']) === $user_id) {
        return false;
    }

    $required_user_id = intval($approval['ticket_approval_required_user_id']);
    return $approval['ticket_approval_type'] === 'any'
        ? $user_id > 0
        : ($approval['ticket_approval_type'] === 'specific' && $required_user_id === $user_id);
}

function ticketApprovalRecordEvent(
    $approval_id,
    $ticket_id,
    $action,
    $before = [],
    $after = [],
    $actor_type = 'system',
    $actor_id = 0,
    $actor_label = '',
    $reason = '',
    $request_expires_at = null
) {
    global $mysqli;

    $approval_id = intval($approval_id);
    $ticket_id = intval($ticket_id);
    $actions = ['created', 're_requested', 'rerouted', 'approved', 'declined'];
    $statuses = ['pending', 'approved', 'declined'];
    $scopes = ['internal', 'client'];
    $types = ['any', 'manager', 'technical', 'billing', 'specific'];
    $actor_types = ['agent', 'contact', 'guest', 'system'];
    if (!$approval_id || !$ticket_id || !in_array($action, $actions, true)) {
        throw new RuntimeException('Invalid ticket approval history event');
    }
    if (!in_array($actor_type, $actor_types, true)) {
        $actor_type = 'system';
        $actor_id = 0;
    }

    $event_value = static function ($value, $allowed) use ($mysqli) {
        $value = (string) ($value ?? '');
        return $value !== '' && in_array($value, $allowed, true)
            ? "'" . mysqli_real_escape_string($mysqli, $value) . "'"
            : 'NULL';
    };
    $nullable_text = static function ($value) use ($mysqli) {
        $value = trim((string) ($value ?? ''));
        return $value === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, substr($value, 0, 255)) . "'";
    };
    $nullable_datetime = static function ($value) use ($mysqli) {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $value) . "'";
    };

    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $from_status_sql = $event_value($before['status'] ?? null, $statuses);
    $to_status_sql = $event_value($after['status'] ?? null, $statuses);
    $from_scope_sql = $event_value($before['scope'] ?? null, $scopes);
    $to_scope_sql = $event_value($after['scope'] ?? null, $scopes);
    $from_type_sql = $event_value($before['type'] ?? null, $types);
    $to_type_sql = $event_value($after['type'] ?? null, $types);
    $from_user_id = max(0, intval($before['required_user_id'] ?? 0));
    $to_user_id = max(0, intval($after['required_user_id'] ?? 0));
    $actor_type_sql = mysqli_real_escape_string($mysqli, $actor_type);
    $actor_id = max(0, intval($actor_id));
    $actor_label_sql = $nullable_text($actor_label);
    $reason_sql = $nullable_text($reason);
    $request_expires_at_sql = $nullable_datetime($request_expires_at);

    runbookDbQuery("INSERT INTO ticket_approval_events SET
        ticket_approval_event_approval_id = $approval_id,
        ticket_approval_event_ticket_id = $ticket_id,
        ticket_approval_event_action = '$action_sql',
        ticket_approval_event_from_status = $from_status_sql,
        ticket_approval_event_to_status = $to_status_sql,
        ticket_approval_event_from_scope = $from_scope_sql,
        ticket_approval_event_to_scope = $to_scope_sql,
        ticket_approval_event_from_type = $from_type_sql,
        ticket_approval_event_to_type = $to_type_sql,
        ticket_approval_event_from_required_user_id = $from_user_id,
        ticket_approval_event_to_required_user_id = $to_user_id,
        ticket_approval_event_actor_type = '$actor_type_sql',
        ticket_approval_event_actor_id = $actor_id,
        ticket_approval_event_actor_label = $actor_label_sql,
        ticket_approval_event_reason = $reason_sql,
        ticket_approval_event_request_expires_at = $request_expires_at_sql",
        'Could not append the ticket approval event');
}

function ticketApprovalQueueNotification($approval_id, $ticket, $scope, $type, $required_user_id, $url_key, $created_by) {
    return runbookQueueApprovalNotification(
        $approval_id,
        $ticket,
        [
            'runbook_version_task_name' => 'Entire ticket',
            'runbook_version_task_approval_scope' => $scope,
            'runbook_version_task_approval_type' => $type,
            'runbook_version_task_approval_user_id' => intval($required_user_id),
        ],
        $url_key,
        $created_by,
        ['target_type' => 'ticket']
    );
}

/**
 * Approval projections and their events are retained as audit evidence. Ticket
 * and client hard-delete paths call these helpers while holding their existing
 * lifecycle locks, so a concurrent approval writer cannot race the check.
 */
function ticketApprovalTicketHasAuditHistory($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    if (!$ticket_id) {
        return false;
    }
    $result = mysqli_query($mysqli, "SELECT
        EXISTS (SELECT 1 FROM ticket_approvals
            WHERE ticket_approval_ticket_id = $ticket_id) AS has_ticket_approval,
        EXISTS (SELECT 1 FROM task_approvals
            INNER JOIN tasks ON task_id = approval_task_id
            WHERE task_ticket_id = $ticket_id) AS has_task_approval");
    if ($result === false) {
        error_log("Could not verify ticket approval retention for ticket $ticket_id: " . mysqli_error($mysqli));
        return true;
    }
    $row = mysqli_fetch_assoc($result);
    return intval($row['has_ticket_approval'] ?? 0) > 0
        || intval($row['has_task_approval'] ?? 0) > 0;
}

function ticketApprovalClientHasAuditHistory($client_id) {
    global $mysqli;

    $client_id = intval($client_id);
    if (!$client_id) {
        return false;
    }
    $result = mysqli_query($mysqli, "SELECT
        EXISTS (SELECT 1 FROM ticket_approvals
            INNER JOIN tickets ON ticket_id = ticket_approval_ticket_id
            WHERE ticket_client_id = $client_id) AS has_ticket_approval,
        EXISTS (SELECT 1 FROM task_approvals
            INNER JOIN tasks ON task_id = approval_task_id
            INNER JOIN tickets ON ticket_id = task_ticket_id
            WHERE ticket_client_id = $client_id) AS has_task_approval");
    if ($result === false) {
        error_log("Could not verify ticket approval retention for client $client_id: " . mysqli_error($mysqli));
        return true;
    }
    $row = mysqli_fetch_assoc($result);
    return intval($row['has_ticket_approval'] ?? 0) > 0
        || intval($row['has_task_approval'] ?? 0) > 0;
}
