<?php

require_once __DIR__ . '/documentation_lifecycle.php';

/*
 * Versioned runbook authoring and execution.
 *
 * ticket_templates/task_templates remain the editable draft for backwards
 * compatibility. Publishing copies that draft into immutable runbook version
 * rows. A ticket execution is pinned to one version, so later edits never
 * rewrite work already in progress or its audit evidence.
 */

function runbookConditionTypes() {
    return [
        'always' => 'Always include',
        'client_has_service' => 'Client has service containing',
        'client_has_asset_type' => 'Client has asset type',
        'client_has_active_contract' => 'Client has an active agreement',
        'client_has_backup' => 'Client has backup in scope',
        'manual_confirm' => 'Wait for manual applicability decision',
    ];
}

function runbookOwnerTypes() {
    return [
        'unassigned' => 'Unassigned',
        'ticket_assignee' => 'Ticket assignee',
        'project_manager' => 'Project manager',
        'specific_user' => 'Specific agent',
    ];
}

function runbookEvidenceTypes() {
    return [
        'none' => 'No evidence required',
        'note' => 'Evidence note',
        'url' => 'Evidence URL',
        'file' => 'Evidence file',
        'any' => 'Any evidence',
    ];
}

function runbookInitialStates() {
    return [
        'Ready' => 'Ready when dependencies pass',
        'Waiting' => 'Waiting for an external event',
    ];
}

function runbookNormalizeKey($value, $fallback = 'task') {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    if ($value === '') {
        $value = $fallback;
    }
    return substr($value, 0, 100);
}

function runbookNormalizeChoice($value, $choices, $fallback) {
    return array_key_exists($value, $choices) ? $value : $fallback;
}

function runbookDbQuery($sql, $context) {
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

/**
 * Serialize workflow mutations against resolve/close transitions.
 * Callers must begin a transaction before calling this helper and retain the
 * lock until their compare-and-swap write and related audit rows are committed.
 */
function runbookLockTicketForTransition($ticket_id, $allow_resolved = false) {
    $ticket_id = intval($ticket_id);
    if (!$ticket_id) {
        throw new RuntimeException('A ticket is required for this workflow mutation');
    }

    $prelock = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1",
        'Could not locate the workflow ticket client'));
    if (!$prelock) {
        throw new RuntimeException('The workflow ticket no longer exists');
    }
    $client_id = intval($prelock['ticket_client_id']);
    documentationLockClient($client_id);
    $ticket = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_contact_id, ticket_project_id, ticket_assigned_to, ticket_status,
        ticket_created_at, ticket_prefix, ticket_number, ticket_subject,
        ticket_configuration_change, ticket_documentation_impact,
        ticket_documentation_assessed_by, ticket_documentation_assessed_at,
        ticket_resolved_at, ticket_closed_at, ticket_deleted_at
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE", 'Could not lock the workflow ticket'));
    if (!$ticket) {
        throw new RuntimeException('The workflow ticket no longer exists');
    }
    if (intval($ticket['ticket_client_id']) !== $client_id) {
        throw new RuntimeException('The workflow ticket changed client scope; refresh and try again');
    }
    if (!empty($ticket['ticket_deleted_at'])) {
        throw new RuntimeException('Deleted tickets cannot be changed outside the retention workflow');
    }
    if (intval($ticket['ticket_status']) === 5 || !empty($ticket['ticket_closed_at'])) {
        throw new RuntimeException('Closed tickets cannot be changed');
    }
    if (!$allow_resolved
        && (intval($ticket['ticket_status']) === 4 || !empty($ticket['ticket_resolved_at']))) {
        throw new RuntimeException('Resolved tickets cannot be changed');
    }
    return $ticket;
}

function runbookLockOpenTicket($ticket_id) {
    return runbookLockTicketForTransition($ticket_id, false);
}

/**
 * Reopen paths share project-close's project -> ticket lock order. The initial
 * ticket read is intentionally revalidated after both locks, so a concurrent
 * project relink fails closed instead of changing the lock order.
 */
function runbookLockTicketForReopen($ticket_id) {
    $ticket_id = intval($ticket_id);
    $prelock_ticket = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_project_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1", 'Could not locate the ticket project'));
    if (!$prelock_ticket) {
        throw new RuntimeException('The workflow ticket no longer exists');
    }
    $project_id = intval($prelock_ticket['ticket_project_id']);
    if ($project_id) {
        $project = mysqli_fetch_assoc(runbookDbQuery("SELECT project_completed_at,
            project_archived_at FROM projects WHERE project_id = $project_id
            LIMIT 1 FOR UPDATE", 'Could not lock the ticket project'));
        if (!$project || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
            throw new RuntimeException('Tickets in a completed or archived project cannot be reopened');
        }
    }
    $ticket = runbookLockTicketForTransition($ticket_id, true);
    if (intval($ticket['ticket_project_id']) !== $project_id) {
        throw new RuntimeException('The ticket project changed; refresh and try again');
    }
    return $ticket;
}

function runbookLockOpenTicketForTask($task_id) {
    $task_id = intval($task_id);
    $task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_ticket_id FROM tasks
        WHERE task_id = $task_id LIMIT 1", 'Could not locate the workflow task'));
    if (!$task || !intval($task['task_ticket_id'])) {
        throw new RuntimeException('The workflow task no longer exists');
    }
    return runbookLockOpenTicket(intval($task['task_ticket_id']));
}

/**
 * Revalidate the authorization client from the ticket row held FOR UPDATE.
 * A pre-transaction client check is only advisory because a ticket may be
 * transferred before the mutation obtains its serialization lock.
 */
function runbookRequireLockedTicketClient($ticket, $expected_client_id) {
    if (!$ticket || intval($ticket['ticket_client_id'] ?? 0) !== intval($expected_client_id)) {
        throw new RuntimeException('The ticket client changed; refresh and try again');
    }
    return $ticket;
}

function runbookRecordTaskStateEvent($task_id, $from_state, $to_state, $reason = '', $actor_id = 0, $actor_type = 'agent') {
    global $mysqli;

    $task_id = intval($task_id);
    if (!$task_id || !in_array($to_state, ['Ready', 'Blocked', 'Waiting', 'Completed', 'Skipped'], true)) {
        throw new RuntimeException('Invalid task state event');
    }
    $from_state_sql = $from_state === null || $from_state === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, (string) $from_state) . "'";
    $to_state_sql = mysqli_real_escape_string($mysqli, (string) $to_state);
    $reason_sql = trim((string) $reason) === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, substr(trim((string) $reason), 0, 255)) . "'";
    $actor_type_sql = mysqli_real_escape_string($mysqli, substr((string) $actor_type, 0, 20));
    $actor_id = max(0, intval($actor_id));

    runbookDbQuery("INSERT INTO task_state_events SET
        task_state_event_task_id = $task_id,
        task_state_event_from_state = $from_state_sql,
        task_state_event_to_state = '$to_state_sql',
        task_state_event_reason = $reason_sql,
        task_state_event_actor_type = '$actor_type_sql',
        task_state_event_actor_id = $actor_id", 'Could not append the task state event');
}

/**
 * Append a structured approval-history event. Bearer credentials are never
 * accepted by this interface, so they cannot leak into the audit ledger.
 * Callers append the event in the same transaction as the approval mutation.
 */
function runbookRecordApprovalEvent(
    $approval_id,
    $task_id,
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
    $task_id = intval($task_id);
    $actions = ['baseline', 'created', 're_requested', 'rerouted', 'approved', 'declined', 'waived'];
    $statuses = ['pending', 'approved', 'declined', 'waived'];
    $scopes = ['internal', 'client'];
    $types = ['any', 'technical', 'billing', 'specific'];
    $actor_types = ['agent', 'contact', 'guest', 'system'];
    if (!$approval_id || !$task_id || !in_array($action, $actions, true)) {
        throw new RuntimeException('Invalid approval history event');
    }
    if (!in_array($actor_type, $actor_types, true)) {
        $actor_type = 'system';
        $actor_id = 0;
    }

    $event_value = static function ($value, $allowed) use ($mysqli) {
        $value = (string) ($value ?? '');
        if ($value === '' || !in_array($value, $allowed, true)) {
            return 'NULL';
        }
        return "'" . mysqli_real_escape_string($mysqli, $value) . "'";
    };
    $nullable_text = static function ($value) use ($mysqli) {
        $value = trim((string) ($value ?? ''));
        return $value === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, substr($value, 0, 255)) . "'";
    };
    $nullable_datetime = static function ($value) use ($mysqli) {
        $value = trim((string) ($value ?? ''));
        return $value === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, $value) . "'";
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

    runbookDbQuery("INSERT INTO task_approval_events SET
        task_approval_event_approval_id = $approval_id,
        task_approval_event_task_id = $task_id,
        task_approval_event_action = '$action_sql',
        task_approval_event_from_status = $from_status_sql,
        task_approval_event_to_status = $to_status_sql,
        task_approval_event_from_scope = $from_scope_sql,
        task_approval_event_to_scope = $to_scope_sql,
        task_approval_event_from_type = $from_type_sql,
        task_approval_event_to_type = $to_type_sql,
        task_approval_event_from_required_user_id = $from_user_id,
        task_approval_event_to_required_user_id = $to_user_id,
        task_approval_event_actor_type = '$actor_type_sql',
        task_approval_event_actor_id = $actor_id,
        task_approval_event_actor_label = $actor_label_sql,
        task_approval_event_reason = $reason_sql,
        task_approval_event_request_expires_at = $request_expires_at_sql",
        'Could not append the task approval event');
}

function runbookApprovalTokenHash($token) {
    return 'sha256:' . hash('sha256', (string) $token);
}

function runbookApprovalTokenMatches($stored_token, $presented_token) {
    $stored_token = (string) $stored_token;
    $presented_token = (string) $presented_token;
    if (str_starts_with($stored_token, 'sha256:')) {
        return hash_equals(substr($stored_token, 7), hash('sha256', $presented_token));
    }
    // Compatibility for approval links created before token hashing shipped.
    return $stored_token !== '' && hash_equals($stored_token, $presented_token);
}

function runbookApprovalTokenExpiry($hours = 168) {
    return date('Y-m-d H:i:s', time() + (max(1, intval($hours)) * 3600));
}

function runbookCloseoutEvidenceQualifies($evidence, $required_type) {
    $evidence = is_array($evidence) ? $evidence : [];
    $type = (string) ($evidence['task_evidence_type'] ?? $evidence['type'] ?? '');
    $has_value = intval($evidence['task_evidence_has_value'] ?? $evidence['has_value'] ?? 0) === 1;
    $has_attachment = intval(
        $evidence['task_evidence_attachment_present'] ?? $evidence['has_attachment'] ?? 0
    ) === 1;
    return match ((string) $required_type) {
        'note' => $type === 'note' && $has_value,
        'url' => $type === 'url' && $has_value,
        'file' => $type === 'file' && $has_attachment,
        'any' => ($type === 'note' && $has_value)
            || ($type === 'url' && $has_value)
            || ($type === 'file' && $has_attachment),
        'none', '' => true,
        default => false,
    };
}

function runbookCloseoutApprovalRouteValid($scope, $type, $required_user_id = 0) {
    $scope = (string) ($scope ?? '');
    $type = (string) ($type ?? '');
    $required_user_id = max(0, intval($required_user_id));
    if ($scope === 'internal') {
        return ($type === 'any' && $required_user_id === 0)
            || ($type === 'specific' && $required_user_id > 0);
    }
    if ($scope === 'client') {
        return in_array($type, ['any', 'technical', 'billing'], true)
            && $required_user_id === 0;
    }
    return false;
}

/**
 * Reconstruct an approval projection from its append-only event chain.
 */
function runbookCloseoutApprovalHistoryConsistent($approval, $events, $task_state) {
    $approval = is_array($approval) ? $approval : [];
    $events = is_array($events) ? $events : [];
    if (!$events) {
        return false;
    }
    $actions = ['baseline', 'created', 're_requested', 'rerouted', 'approved', 'declined', 'waived'];
    $statuses = ['', 'pending', 'approved', 'declined', 'waived'];
    $actor_types = ['agent', 'contact', 'guest', 'system'];
    $expected_status = [
        'created' => 'pending',
        're_requested' => 'pending',
        'rerouted' => 'pending',
        'approved' => 'approved',
        'declined' => 'declined',
        'waived' => 'waived',
    ];
    $previous = null;
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            return false;
        }
        $action = (string) ($event['task_approval_event_action'] ?? $event['action'] ?? '');
        $from_status = (string) ($event['task_approval_event_from_status'] ?? $event['from_status'] ?? '');
        $to_status = (string) ($event['task_approval_event_to_status'] ?? $event['to_status'] ?? '');
        $from_scope = (string) ($event['task_approval_event_from_scope'] ?? $event['from_scope'] ?? '');
        $to_scope = (string) ($event['task_approval_event_to_scope'] ?? $event['to_scope'] ?? '');
        $from_type = (string) ($event['task_approval_event_from_type'] ?? $event['from_type'] ?? '');
        $to_type = (string) ($event['task_approval_event_to_type'] ?? $event['to_type'] ?? '');
        $from_user = max(0, intval(
            $event['task_approval_event_from_required_user_id'] ?? $event['from_required_user_id'] ?? 0
        ));
        $to_user = max(0, intval(
            $event['task_approval_event_to_required_user_id'] ?? $event['to_required_user_id'] ?? 0
        ));
        $actor_type = (string) (
            $event['task_approval_event_actor_type'] ?? $event['actor_type'] ?? ''
        );
        if (!in_array($action, $actions, true)
            || !in_array($from_status, $statuses, true)
            || !in_array($to_status, $statuses, true)
            || !in_array($actor_type, $actor_types, true)
            || $to_status === ''
            || !runbookCloseoutApprovalRouteValid($to_scope, $to_type, $to_user)
            || ($from_status === '' && ($from_scope !== '' || $from_type !== '' || $from_user !== 0))
            || ($from_status !== ''
                && !runbookCloseoutApprovalRouteValid($from_scope, $from_type, $from_user))) {
            return false;
        }
        if (($index === 0 && !in_array($action, ['baseline', 'created'], true))
            || ($index > 0 && in_array($action, ['baseline', 'created'], true))
            || (isset($expected_status[$action]) && $to_status !== $expected_status[$action])) {
            return false;
        }
        if ($previous !== null && [
            $from_status, $from_scope, $from_type, $from_user,
        ] !== [
            $previous['status'], $previous['scope'], $previous['type'], $previous['required_user_id'],
        ]) {
            return false;
        }
        $previous = [
            'status' => $to_status,
            'scope' => $to_scope,
            'type' => $to_type,
            'required_user_id' => $to_user,
            'action' => $action,
        ];
    }
    if ($previous['scope'] !== (string) ($approval['approval_scope'] ?? '')
        || $previous['type'] !== (string) ($approval['approval_type'] ?? '')
        || $previous['required_user_id'] !== intval($approval['approval_route_user_key'] ?? 0)) {
        return false;
    }
    $projection_status = (string) ($approval['approval_status'] ?? '');
    if ($previous['action'] === 'waived') {
        return $task_state === 'Skipped'
            && $previous['status'] === 'waived'
            && in_array($projection_status, ['pending', 'declined'], true);
    }
    return $previous['status'] === $projection_status;
}

/**
 * Validate the complete, append-only state path rather than checking only the
 * final row. This makes a client closeout prove how a task reached its terminal
 * state and rejects gaps and reordered history. A completed task may have been
 * explicitly reopened before the execution reached its final closeout.
 */
function runbookCloseoutStateHistoryConsistent($events, $task_state) {
    $events = is_array($events) ? $events : [];
    if (!$events) {
        return false;
    }
    $states = ['Ready', 'Blocked', 'Waiting', 'Completed', 'Skipped'];
    $actors = ['agent', 'system', 'client', 'guest'];
    $previous = null;
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            return false;
        }
        $from = (string) ($event['task_state_event_from_state'] ?? $event['from_state'] ?? '');
        $to = (string) ($event['task_state_event_to_state'] ?? $event['to_state'] ?? '');
        $actor = (string) ($event['task_state_event_actor_type'] ?? $event['actor_type'] ?? 'system');
        if (!in_array($to, $states, true)
            || ($from !== '' && !in_array($from, $states, true))
            || !in_array($actor, $actors, true)
            || ($index === 0 && $from !== '')
            || ($index > 0 && $from !== $previous)) {
            return false;
        }
        $previous = $to;
    }
    return $previous === (string) $task_state;
}

/**
 * Side-effect-free closeout verifier used by the authenticated export and by
 * deterministic onboarding/offboarding acceptance fixtures.
 */
function runbookCloseoutIntegrityErrors($fixture) {
    $fixture = is_array($fixture) ? $fixture : [];
    $execution = is_array($fixture['execution'] ?? null) ? $fixture['execution'] : [];
    $tasks = is_array($fixture['tasks'] ?? null) ? $fixture['tasks'] : [];
    $evidence_by_key = is_array($fixture['evidence_by_key'] ?? null)
        ? $fixture['evidence_by_key'] : [];
    $approvals_by_key = is_array($fixture['approvals_by_key'] ?? null)
        ? $fixture['approvals_by_key'] : [];
    $approval_events_by_projection = is_array($fixture['approval_events_by_projection'] ?? null)
        ? $fixture['approval_events_by_projection'] : [];
    $state_events_by_key = is_array($fixture['state_events_by_key'] ?? null)
        ? $fixture['state_events_by_key'] : [];
    $errors = [];
    $add = static function ($code, $task_key = '') use (&$errors): void {
        $error = ['code' => (string) $code];
        if ((string) $task_key !== '') {
            $error['task_key'] = (string) $task_key;
        }
        $errors[] = $error;
    };

    $execution_status = (string) (
        $execution['runbook_execution_status'] ?? $execution['status'] ?? ''
    );
    $completed_at = (string) (
        $execution['runbook_execution_completed_at'] ?? $execution['completed_at'] ?? ''
    );
    if ($execution_status !== 'Completed' || $completed_at === '') {
        $add('execution_not_completed');
    }
    $snapshot = $execution['runbook_execution_snapshot'] ?? $execution['snapshot'] ?? null;
    if (is_string($snapshot)) {
        $snapshot = json_decode($snapshot, true);
    }
    $published_definition = $execution['published_definition'] ?? null;
    if (!is_array($snapshot) || !is_array($published_definition)) {
        $add('definition_snapshot_invalid');
        return $errors;
    }
    $computed_hash = runbookDefinitionHash($snapshot);
    $stored_hash = (string) (
        $execution['runbook_execution_snapshot_hash'] ?? $execution['snapshot_hash'] ?? ''
    );
    $published_hash = (string) (
        $execution['runbook_version_definition_hash'] ?? $execution['published_hash'] ?? ''
    );
    if ($stored_hash === '' || !hash_equals($stored_hash, $computed_hash)) {
        $add('execution_snapshot_hash_mismatch');
    }
    if ($published_hash === '' || !hash_equals($published_hash, $computed_hash)
        || !hash_equals($computed_hash, runbookDefinitionHash($published_definition))) {
        $add('published_definition_hash_mismatch');
    }
    if (runbookValidateDefinition($snapshot)) {
        $add('execution_definition_invalid');
    }

    $definition = runbookCanonicalDefinition($snapshot);
    $definition_tasks = array_column($definition['tasks'], null, 'key');
    $source_count = intval($fixture['source_task_count'] ?? count($definition_tasks));
    if ($source_count < 1 || $source_count !== count($definition_tasks)
        || count($tasks) !== count($definition_tasks)) {
        $add('task_mapping_count_mismatch');
    }
    $runtime_tasks = [];
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            $add('runtime_task_invalid');
            continue;
        }
        $task_key = (string) ($task['runbook_version_task_key'] ?? $task['task_key'] ?? '');
        if ($task_key === '' || isset($runtime_tasks[$task_key])) {
            $add($task_key === '' ? 'runtime_task_key_missing' : 'runtime_task_key_duplicate', $task_key);
            continue;
        }
        $runtime_tasks[$task_key] = $task;
    }
    if (array_keys($definition_tasks) !== array_keys($runtime_tasks)) {
        $definition_keys = array_keys($definition_tasks);
        $runtime_keys = array_keys($runtime_tasks);
        sort($definition_keys, SORT_STRING);
        sort($runtime_keys, SORT_STRING);
        if ($definition_keys !== $runtime_keys) {
            $add('task_mapping_keys_mismatch');
        }
    }

    foreach ($definition_tasks as $task_key => $definition_task) {
        $task = $runtime_tasks[$task_key] ?? null;
        if (!$task) {
            continue;
        }
        $task_state = (string) ($task['task_state'] ?? '');
        if (!in_array($task_state, ['Completed', 'Skipped'], true)
            || trim((string) ($task['task_completed_at'] ?? '')) === '') {
            $add('task_not_terminal', $task_key);
        }
        if (!runbookCloseoutStateHistoryConsistent(
            $state_events_by_key[$task_key] ?? [],
            $task_state
        )) {
            $add('task_state_history_inconsistent', $task_key);
        }

        $task_approvals = is_array($approvals_by_key[$task_key] ?? null)
            ? $approvals_by_key[$task_key] : [];
        foreach ($task_approvals as $approval) {
            if (!is_array($approval)) {
                $add('approval_projection_invalid', $task_key);
                continue;
            }
            $projection_key = intval(
                $approval['approval_projection_key'] ?? $approval['approval_id'] ?? 0
            );
            $history = $approval_events_by_projection[$projection_key] ?? [];
            foreach ((array) $history as $event) {
                $event_task_key = (string) ($event['runbook_version_task_key'] ?? $task_key);
                if ($event_task_key !== $task_key) {
                    $add('approval_history_task_scope_mismatch', $task_key);
                    break;
                }
            }
            $first_approval_event = is_array($history[0] ?? null) ? $history[0] : [];
            if ((string) ($first_approval_event['task_approval_event_action'] ?? '') === 'created'
                && ((string) ($first_approval_event['task_approval_event_to_scope'] ?? '')
                        !== (string) ($definition_task['approval_scope'] ?? '')
                    || (string) ($first_approval_event['task_approval_event_to_type'] ?? '')
                        !== (string) ($definition_task['approval_type'] ?? '')
                    || ((string) ($definition_task['approval_type'] ?? '') === 'specific'
                        && intval($first_approval_event['task_approval_event_to_required_user_id'] ?? 0)
                            !== intval($definition_task['approval_user_id'] ?? 0)))) {
                $add('approval_route_definition_mismatch', $task_key);
            }
            if (!runbookCloseoutApprovalHistoryConsistent($approval, $history, $task_state)) {
                $add('approval_history_inconsistent', $task_key);
            }
        }

        if ($task_state !== 'Completed') {
            continue;
        }
        $required_evidence = (string) ($definition_task['evidence_type'] ?? 'none');
        if ($required_evidence !== 'none' && $required_evidence !== '') {
            $satisfied = false;
            foreach ((array) ($evidence_by_key[$task_key] ?? []) as $evidence) {
                if (runbookCloseoutEvidenceQualifies($evidence, $required_evidence)) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                $add('required_evidence_missing', $task_key);
            }
        }

        $approval_required = trim((string) ($definition_task['approval_scope'] ?? '')) !== '';
        if (($approval_required && count($task_approvals) !== 1)
            || (!$approval_required && count($task_approvals) !== 0)) {
            $add('approval_projection_count_mismatch', $task_key);
            continue;
        }
        foreach ($task_approvals as $approval) {
            $actor_type = (string) ($approval['approval_decision_actor_type'] ?? '');
            $requester_id = max(0, intval($approval['approval_created_by'] ?? 0));
            $decision_actor_id = max(0, intval($approval['approval_decision_actor_id'] ?? 0));
            if ((string) ($approval['approval_status'] ?? '') !== 'approved'
                || intval($approval['approval_has_decision_actor'] ?? 0) !== 1
                || trim((string) ($approval['approval_decided_at'] ?? '')) === '') {
                $add('approval_decision_incomplete', $task_key);
            }
            if ($actor_type === 'agent' && $requester_id > 0
                && $decision_actor_id > 0 && $requester_id === $decision_actor_id) {
                $add('approval_self_decision', $task_key);
            }
        }
    }

    usort($errors, static fn ($left, $right) => strcmp(
        json_encode($left, JSON_UNESCAPED_SLASHES),
        json_encode($right, JSON_UNESCAPED_SLASHES)
    ));
    return $errors;
}

function runbookDraftDefinition($ticket_template_id) {
    global $mysqli;

    $ticket_template_id = intval($ticket_template_id);
    if (!$ticket_template_id) {
        return null;
    }

    $template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        ticket_template_id, ticket_template_name, ticket_template_description,
        ticket_template_subject, ticket_template_details, ticket_template_runbook_key,
        ticket_template_runbook_type
        FROM ticket_templates
        WHERE ticket_template_id = $ticket_template_id LIMIT 1"));

    if (!$template) {
        return null;
    }

    $definition = [
        'key' => runbookNormalizeKey($template['ticket_template_runbook_key'] ?: $template['ticket_template_name'], 'runbook'),
        'type' => in_array($template['ticket_template_runbook_type'], ['standard', 'onboarding', 'offboarding'], true)
            ? $template['ticket_template_runbook_type'] : 'standard',
        'name' => (string) $template['ticket_template_name'],
        'description' => (string) $template['ticket_template_description'],
        'subject' => (string) $template['ticket_template_subject'],
        'details' => (string) $template['ticket_template_details'],
        'tasks' => [],
    ];

    $task_ids = [];
    $used_keys = [];
    $sql_tasks = mysqli_query($mysqli, "SELECT * FROM task_templates
        WHERE task_template_ticket_template_id = $ticket_template_id
        ORDER BY task_template_order ASC, task_template_id ASC");

    while ($task = mysqli_fetch_assoc($sql_tasks)) {
        $task_id = intval($task['task_template_id']);
        $task_key = runbookNormalizeKey($task['task_template_key'] ?: $task['task_template_name'], 'task-' . $task_id);
        if (isset($used_keys[$task_key])) {
            $task_key = substr($task_key, 0, 88) . '-' . $task_id;
        }
        $used_keys[$task_key] = true;
        $task_ids[$task_id] = $task_key;

        $condition_type = runbookNormalizeChoice(
            $task['task_template_condition_type'],
            runbookConditionTypes(),
            'always'
        );
        $owner_type = runbookNormalizeChoice(
            $task['task_template_owner_type'],
            runbookOwnerTypes(),
            'unassigned'
        );
        $evidence_type = runbookNormalizeChoice(
            $task['task_template_evidence_type'],
            runbookEvidenceTypes(),
            'none'
        );
        $initial_state = runbookNormalizeChoice(
            $task['task_template_initial_state'],
            runbookInitialStates(),
            'Ready'
        );

        $definition['tasks'][$task_id] = [
            'source_id' => $task_id,
            'key' => $task_key,
            'name' => (string) $task['task_template_name'],
            'instructions' => (string) $task['task_template_instructions'],
            'order' => intval($task['task_template_order']),
            'estimate' => max(0, intval($task['task_template_completion_estimate'])),
            'condition_type' => $condition_type,
            'condition_value' => (string) $task['task_template_condition_value'],
            'owner_type' => $owner_type,
            'owner_user_id' => max(0, intval($task['task_template_owner_user_id'])),
            'due_offset_minutes' => max(0, intval($task['task_template_due_offset_minutes'])),
            'initial_state' => $initial_state,
            'approval_scope' => in_array($task['task_template_approval_scope'], ['internal', 'client'], true)
                ? $task['task_template_approval_scope'] : '',
            'approval_type' => in_array($task['task_template_approval_type'], ['any', 'technical', 'billing', 'specific'], true)
                ? $task['task_template_approval_type'] : '',
            'approval_user_id' => max(0, intval($task['task_template_approval_user_id'])),
            'evidence_type' => $evidence_type,
            'evidence_prompt' => (string) $task['task_template_evidence_prompt'],
            'depends_on' => [],
        ];
    }

    if ($task_ids) {
        $id_list = implode(',', array_map('intval', array_keys($task_ids)));
        $sql_dependencies = mysqli_query($mysqli, "SELECT task_template_id, depends_on_task_template_id
            FROM task_template_dependencies
            WHERE task_template_id IN ($id_list)");
        while ($dependency = mysqli_fetch_assoc($sql_dependencies)) {
            $task_id = intval($dependency['task_template_id']);
            $depends_on_id = intval($dependency['depends_on_task_template_id']);
            if (isset($definition['tasks'][$task_id], $task_ids[$depends_on_id]) && $task_id !== $depends_on_id) {
                $definition['tasks'][$task_id]['depends_on'][] = $task_ids[$depends_on_id];
            }
        }
    }

    foreach ($definition['tasks'] as &$task) {
        sort($task['depends_on']);
    }
    unset($task);

    $definition['tasks'] = array_values($definition['tasks']);
    return $definition;
}

function runbookCanonicalDefinition($definition) {
    $canonical = [
        'key' => (string) ($definition['key'] ?? ''),
        'type' => (string) ($definition['type'] ?? 'standard'),
        'name' => (string) ($definition['name'] ?? ''),
        'description' => (string) ($definition['description'] ?? ''),
        'subject' => (string) ($definition['subject'] ?? ''),
        'details' => (string) ($definition['details'] ?? ''),
        'tasks' => [],
    ];

    foreach ($definition['tasks'] ?? [] as $task) {
        $dependencies = array_values(array_unique(array_map('strval', $task['depends_on'] ?? [])));
        sort($dependencies, SORT_STRING);
        $canonical['tasks'][] = [
            // Source database IDs are transport details and deliberately absent.
            'key' => (string) ($task['key'] ?? ''),
            'name' => (string) ($task['name'] ?? ''),
            'instructions' => (string) ($task['instructions'] ?? ''),
            'order' => intval($task['order'] ?? 0),
            'estimate' => max(0, intval($task['estimate'] ?? 0)),
            'condition_type' => (string) ($task['condition_type'] ?? 'always'),
            'condition_value' => (string) ($task['condition_value'] ?? ''),
            'owner_type' => (string) ($task['owner_type'] ?? 'unassigned'),
            'owner_user_id' => max(0, intval($task['owner_user_id'] ?? 0)),
            'due_offset_minutes' => max(0, intval($task['due_offset_minutes'] ?? 0)),
            'initial_state' => (string) ($task['initial_state'] ?? 'Ready'),
            'approval_scope' => (string) ($task['approval_scope'] ?? ''),
            'approval_type' => (string) ($task['approval_type'] ?? ''),
            'approval_user_id' => max(0, intval($task['approval_user_id'] ?? 0)),
            'evidence_type' => (string) ($task['evidence_type'] ?? 'none'),
            'evidence_prompt' => (string) ($task['evidence_prompt'] ?? ''),
            'depends_on' => $dependencies,
        ];
    }

    usort($canonical['tasks'], static function ($left, $right) {
        return [$left['order'], $left['key']] <=> [$right['order'], $right['key']];
    });
    return $canonical;
}

function runbookDefinitionHash($definition) {
    $canonical = runbookCanonicalDefinition($definition);
    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function runbookValidateDefinition($definition) {
    $errors = [];
    $runbook_key = (string) ($definition['key'] ?? '');
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $runbook_key)) {
        $errors[] = 'The runbook requires a normalized stable key.';
    }
    if (!in_array((string) ($definition['type'] ?? ''), ['standard', 'onboarding', 'offboarding'], true)) {
        $errors[] = 'The runbook type is unsupported.';
    }
    if (trim((string) ($definition['name'] ?? '')) === '') {
        $errors[] = 'The runbook requires a name.';
    }
    if (trim((string) ($definition['subject'] ?? '')) === '') {
        $errors[] = 'The runbook requires a ticket subject.';
    }
    $tasks = $definition['tasks'] ?? [];
    if (!$tasks) {
        return ['A runbook must contain at least one task.'];
    }

    $by_key = [];
    foreach ($tasks as $task) {
        $key = (string) ($task['key'] ?? '');
        if ($key === '' || isset($by_key[$key])) {
            $errors[] = $key === '' ? 'Every task requires a stable key.' : "Duplicate task key: $key";
            continue;
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $key)) {
            $errors[] = "Task key $key must contain only lowercase letters, numbers, and hyphens.";
        }
        if (trim((string) ($task['name'] ?? '')) === '') {
            $errors[] = "Task $key requires a name.";
        }
        $condition_type = (string) ($task['condition_type'] ?? '');
        if (!array_key_exists($condition_type, runbookConditionTypes())) {
            $errors[] = "Task $key has an unsupported condition.";
        }
        if (in_array($condition_type, ['client_has_service', 'client_has_asset_type'], true)
            && trim((string) ($task['condition_value'] ?? '')) === '') {
            $errors[] = "Task $key requires a condition value.";
        }
        if (!array_key_exists((string) ($task['owner_type'] ?? ''), runbookOwnerTypes())) {
            $errors[] = "Task $key has an unsupported owner rule.";
        }
        if (($task['owner_type'] ?? '') === 'specific_user' && intval($task['owner_user_id'] ?? 0) < 1) {
            $errors[] = "Task $key requires a specific owner.";
        }
        if (!array_key_exists((string) ($task['initial_state'] ?? ''), runbookInitialStates())) {
            $errors[] = "Task $key has an unsupported initial state.";
        }
        if (!array_key_exists((string) ($task['evidence_type'] ?? ''), runbookEvidenceTypes())) {
            $errors[] = "Task $key has an unsupported evidence rule.";
        }
        $approval_scope = (string) ($task['approval_scope'] ?? '');
        $approval_type = (string) ($task['approval_type'] ?? '');
        if (($approval_scope === '') !== ($approval_type === '')
            || ($approval_scope !== '' && !in_array($approval_scope, ['internal', 'client'], true))
            || ($approval_type !== '' && !in_array($approval_type, ['any', 'technical', 'billing', 'specific'], true))
            || ($approval_scope === 'client' && $approval_type === 'specific')
            || ($approval_scope === 'internal' && in_array($approval_type, ['technical', 'billing'], true))) {
            $errors[] = "Task $key has an unsupported approval rule.";
        }
        if ($approval_type === 'specific' && intval($task['approval_user_id'] ?? 0) < 1) {
            $errors[] = "Task $key requires a specific internal approver.";
        }
        $by_key[$key] = $task;
    }

    foreach ($by_key as $key => $task) {
        foreach ($task['depends_on'] ?? [] as $dependency_key) {
            if ($dependency_key === $key) {
                $errors[] = "Task $key cannot depend on itself.";
            } elseif (!isset($by_key[$dependency_key])) {
                $errors[] = "Task $key depends on missing task $dependency_key.";
            }
        }
    }

    $visiting = [];
    $visited = [];
    $visit = function ($key) use (&$visit, &$visiting, &$visited, &$errors, $by_key) {
        if (isset($visited[$key])) {
            return;
        }
        if (isset($visiting[$key])) {
            $errors[] = "Dependency cycle detected at task $key.";
            return;
        }
        $visiting[$key] = true;
        foreach ($by_key[$key]['depends_on'] ?? [] as $dependency_key) {
            if (isset($by_key[$dependency_key])) {
                $visit($dependency_key);
            }
        }
        unset($visiting[$key]);
        $visited[$key] = true;
    };
    foreach (array_keys($by_key) as $key) {
        $visit($key);
    }

    return array_values(array_unique($errors));
}

function runbookLatestPublishedVersionId($ticket_template_id) {
    global $mysqli;

    $ticket_template_id = intval($ticket_template_id);
    if (!$ticket_template_id) {
        return 0;
    }

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_template_published_version_id
        FROM ticket_templates WHERE ticket_template_id = $ticket_template_id LIMIT 1"));
    $version_id = intval($row['ticket_template_published_version_id'] ?? 0);
    if ($version_id) {
        $exists = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM runbook_versions
            WHERE runbook_version_id = $version_id
            AND runbook_version_ticket_template_id = $ticket_template_id"));
        if (intval($exists[0] ?? 0) === 1) {
            return $version_id;
        }
    }

    $latest = mysqli_fetch_row(mysqli_query($mysqli, "SELECT runbook_version_id FROM runbook_versions
        WHERE runbook_version_ticket_template_id = $ticket_template_id
        ORDER BY runbook_version_number DESC LIMIT 1"));
    return intval($latest[0] ?? 0);
}

function runbookVersionDefinition($runbook_version_id) {
    global $mysqli;

    $runbook_version_id = intval($runbook_version_id);
    $version = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM runbook_versions
        WHERE runbook_version_id = $runbook_version_id LIMIT 1"));
    if (!$version) {
        return null;
    }

    $definition = [
        'key' => (string) $version['runbook_version_key'],
        'type' => (string) $version['runbook_version_type'],
        'name' => (string) $version['runbook_version_name'],
        'description' => (string) $version['runbook_version_description'],
        'subject' => (string) $version['runbook_version_subject'],
        'details' => (string) $version['runbook_version_details'],
        'tasks' => [],
    ];

    $task_keys = [];
    $tasks = mysqli_query($mysqli, "SELECT * FROM runbook_version_tasks
        WHERE runbook_version_task_runbook_version_id = $runbook_version_id
        ORDER BY runbook_version_task_order ASC, runbook_version_task_id ASC");
    while ($task = mysqli_fetch_assoc($tasks)) {
        $task_id = intval($task['runbook_version_task_id']);
        $task_keys[$task_id] = $task['runbook_version_task_key'];
        $definition['tasks'][$task_id] = [
            'key' => (string) $task['runbook_version_task_key'],
            'name' => (string) $task['runbook_version_task_name'],
            'instructions' => (string) $task['runbook_version_task_instructions'],
            'order' => intval($task['runbook_version_task_order']),
            'estimate' => intval($task['runbook_version_task_completion_estimate']),
            'condition_type' => (string) $task['runbook_version_task_condition_type'],
            'condition_value' => (string) $task['runbook_version_task_condition_value'],
            'owner_type' => (string) $task['runbook_version_task_owner_type'],
            'owner_user_id' => intval($task['runbook_version_task_owner_user_id']),
            'due_offset_minutes' => intval($task['runbook_version_task_due_offset_minutes']),
            'initial_state' => (string) $task['runbook_version_task_initial_state'],
            'approval_scope' => (string) $task['runbook_version_task_approval_scope'],
            'approval_type' => (string) $task['runbook_version_task_approval_type'],
            'approval_user_id' => intval($task['runbook_version_task_approval_user_id']),
            'evidence_type' => (string) $task['runbook_version_task_evidence_type'],
            'evidence_prompt' => (string) $task['runbook_version_task_evidence_prompt'],
            'depends_on' => [],
        ];
    }

    if ($task_keys) {
        $dependencies = mysqli_query($mysqli, "SELECT d.* FROM runbook_version_task_dependencies d
            INNER JOIN runbook_version_tasks t ON t.runbook_version_task_id = d.runbook_version_task_id
            WHERE t.runbook_version_task_runbook_version_id = $runbook_version_id");
        while ($dependency = mysqli_fetch_assoc($dependencies)) {
            $task_id = intval($dependency['runbook_version_task_id']);
            $depends_on_id = intval($dependency['depends_on_runbook_version_task_id']);
            if (isset($definition['tasks'][$task_id], $task_keys[$depends_on_id])) {
                $definition['tasks'][$task_id]['depends_on'][] = $task_keys[$depends_on_id];
            }
        }
    }

    foreach ($definition['tasks'] as &$task) {
        sort($task['depends_on']);
    }
    unset($task);
    $definition['tasks'] = array_values($definition['tasks']);

    return $definition;
}

function runbookAssertVersionDefinitionHash($runbook_version_id, $expected_hash) {
    $runbook_version_id = intval($runbook_version_id);
    $expected_hash = strtolower(trim((string) $expected_hash));
    $definition = runbookVersionDefinition($runbook_version_id);
    if (!$definition || runbookValidateDefinition($definition)) {
        throw new RuntimeException('The published runbook definition is incomplete or invalid');
    }
    $actual_hash = runbookDefinitionHash($definition);
    if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)
        || !hash_equals($expected_hash, $actual_hash)) {
        throw new RuntimeException('The published runbook definition does not match its immutable hash');
    }
    return $definition;
}

function publishRunbookVersion($ticket_template_id, $created_by = 0, $notes = '') {
    global $mysqli;

    $ticket_template_id = intval($ticket_template_id);
    $created_by = intval($created_by);
    // Publication, archival and deletion all serialize on the template row.
    // Without this lock, a concurrent delete could commit after the draft was
    // read and leave a newly published version without its owning template.
    $template = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_template_id,
        ticket_template_archived_at FROM ticket_templates
        WHERE ticket_template_id = $ticket_template_id LIMIT 1 FOR UPDATE",
        'Could not lock the runbook template for publication'));
    if (!$template || !empty($template['ticket_template_archived_at'])) {
        return 0;
    }
    $definition = runbookDraftDefinition($ticket_template_id);
    if (!$definition || runbookValidateDefinition($definition)) {
        return 0;
    }

    $definition_hash = runbookDefinitionHash($definition);
    $definition_hash_sql = mysqli_real_escape_string($mysqli, $definition_hash);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_version_id
        FROM runbook_versions
        WHERE runbook_version_ticket_template_id = $ticket_template_id
        AND runbook_version_definition_hash = '$definition_hash_sql' LIMIT 1"));
    if ($existing) {
        $version_id = intval($existing['runbook_version_id']);
        runbookAssertVersionDefinitionHash($version_id, $definition_hash);
        runbookDbQuery("UPDATE ticket_templates
            SET ticket_template_published_version_id = $version_id
            WHERE ticket_template_id = $ticket_template_id", 'Could not select the existing published version');
        $published_pointer = mysqli_fetch_row(runbookDbQuery("SELECT ticket_template_published_version_id
            FROM ticket_templates WHERE ticket_template_id = $ticket_template_id LIMIT 1",
            'Could not verify the selected published version'));
        if (intval($published_pointer[0] ?? 0) !== $version_id) {
            throw new RuntimeException('The published runbook pointer was not saved');
        }
        runbookDbQuery("UPDATE project_template_ticket_templates
            SET ticket_template_runbook_version_id = $version_id
            WHERE ticket_template_id = $ticket_template_id
            AND ticket_template_runbook_version_id = 0", 'Could not pin project stages to the published version');
        return $version_id;
    }

    $next = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(runbook_version_number), 0) + 1
        FROM runbook_versions WHERE runbook_version_ticket_template_id = $ticket_template_id"));
    $version_number = max(1, intval($next[0] ?? 1));

    $name = mysqli_real_escape_string($mysqli, $definition['name']);
    $runbook_key = mysqli_real_escape_string($mysqli, $definition['key']);
    $description = mysqli_real_escape_string($mysqli, $definition['description']);
    $subject = mysqli_real_escape_string($mysqli, $definition['subject']);
    $details = mysqli_real_escape_string($mysqli, $definition['details']);
    $type = mysqli_real_escape_string($mysqli, $definition['type']);
    $notes = mysqli_real_escape_string($mysqli, trim((string) $notes));

    runbookDbQuery("INSERT INTO runbook_versions SET
        runbook_version_ticket_template_id = $ticket_template_id,
        runbook_version_number = $version_number,
        runbook_version_definition_hash = '$definition_hash_sql',
        runbook_version_key = '$runbook_key',
        runbook_version_name = '$name',
        runbook_version_description = '$description',
        runbook_version_subject = '$subject',
        runbook_version_details = '$details',
        runbook_version_type = '$type',
        runbook_version_notes = '$notes',
        runbook_version_created_by = $created_by", 'Could not create the runbook version');
    $version_id = intval(mysqli_insert_id($mysqli));
    if (!$version_id) {
        throw new RuntimeException('The published runbook version did not receive an ID');
    }

    $version_task_ids = [];
    foreach ($definition['tasks'] as $task) {
        $source_id = intval($task['source_id'] ?? 0);
        $key = mysqli_real_escape_string($mysqli, $task['key']);
        $task_name = mysqli_real_escape_string($mysqli, $task['name']);
        $instructions = mysqli_real_escape_string($mysqli, $task['instructions']);
        $condition_type = mysqli_real_escape_string($mysqli, $task['condition_type']);
        $condition_value = mysqli_real_escape_string($mysqli, $task['condition_value']);
        $owner_type = mysqli_real_escape_string($mysqli, $task['owner_type']);
        $initial_state = mysqli_real_escape_string($mysqli, $task['initial_state']);
        $approval_scope = $task['approval_scope'] === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $task['approval_scope']) . "'";
        $approval_type = $task['approval_type'] === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $task['approval_type']) . "'";
        $evidence_type = mysqli_real_escape_string($mysqli, $task['evidence_type']);
        $evidence_prompt = mysqli_real_escape_string($mysqli, $task['evidence_prompt']);

        runbookDbQuery("INSERT INTO runbook_version_tasks SET
            runbook_version_task_runbook_version_id = $version_id,
            runbook_version_task_source_id = $source_id,
            runbook_version_task_key = '$key',
            runbook_version_task_name = '$task_name',
            runbook_version_task_instructions = '$instructions',
            runbook_version_task_order = " . intval($task['order']) . ",
            runbook_version_task_completion_estimate = " . intval($task['estimate']) . ",
            runbook_version_task_condition_type = '$condition_type',
            runbook_version_task_condition_value = '$condition_value',
            runbook_version_task_owner_type = '$owner_type',
            runbook_version_task_owner_user_id = " . intval($task['owner_user_id']) . ",
            runbook_version_task_due_offset_minutes = " . intval($task['due_offset_minutes']) . ",
            runbook_version_task_initial_state = '$initial_state',
            runbook_version_task_approval_scope = $approval_scope,
            runbook_version_task_approval_type = $approval_type,
            runbook_version_task_approval_user_id = " . intval($task['approval_user_id']) . ",
            runbook_version_task_evidence_type = '$evidence_type',
            runbook_version_task_evidence_prompt = '$evidence_prompt'", 'Could not create a published runbook task');
        $version_task_ids[$task['key']] = intval(mysqli_insert_id($mysqli));
        if (!$version_task_ids[$task['key']]) {
            throw new RuntimeException('A published runbook task did not receive an ID');
        }
        if ($source_id) {
            runbookDbQuery("UPDATE task_templates SET task_template_key = '$key'
                WHERE task_template_id = $source_id
                AND task_template_ticket_template_id = $ticket_template_id", 'Could not persist the stable draft task key');
        }
    }

    foreach ($definition['tasks'] as $task) {
        $task_id = intval($version_task_ids[$task['key']] ?? 0);
        foreach ($task['depends_on'] as $dependency_key) {
            $depends_on_id = intval($version_task_ids[$dependency_key] ?? 0);
            if ($task_id && $depends_on_id && $task_id !== $depends_on_id) {
                runbookDbQuery("INSERT IGNORE INTO runbook_version_task_dependencies SET
                    runbook_version_task_id = $task_id,
                    depends_on_runbook_version_task_id = $depends_on_id", 'Could not create a published runbook dependency');
            }
        }
    }

    // Reconstruct from the rows just written and validate before making this
    // version visible through any published pointer.
    runbookAssertVersionDefinitionHash($version_id, $definition_hash);

    runbookDbQuery("UPDATE ticket_templates SET
        ticket_template_runbook_key = '$runbook_key',
        ticket_template_published_version_id = $version_id
        WHERE ticket_template_id = $ticket_template_id", 'Could not update the published runbook pointer');
    $published_pointer = mysqli_fetch_row(runbookDbQuery("SELECT ticket_template_published_version_id
        FROM ticket_templates WHERE ticket_template_id = $ticket_template_id LIMIT 1",
        'Could not verify the published runbook pointer'));
    if (intval($published_pointer[0] ?? 0) !== $version_id) {
        throw new RuntimeException('The published runbook pointer was not saved');
    }
    runbookDbQuery("UPDATE project_template_ticket_templates
        SET ticket_template_runbook_version_id = $version_id
        WHERE ticket_template_id = $ticket_template_id
        AND ticket_template_runbook_version_id = 0", 'Could not pin project stages to the published version');

    return $version_id;
}

function restoreRunbookVersionToDraft($ticket_template_id, $runbook_version_id) {
    global $mysqli;

    $ticket_template_id = intval($ticket_template_id);
    $runbook_version_id = intval($runbook_version_id);
    $version = mysqli_fetch_assoc(runbookDbQuery("SELECT * FROM runbook_versions
        WHERE runbook_version_id = $runbook_version_id
        AND runbook_version_ticket_template_id = $ticket_template_id LIMIT 1", 'Could not load the published runbook version'));
    if (!$version) {
        return false;
    }

    $name = mysqli_real_escape_string($mysqli, $version['runbook_version_name']);
    $description = mysqli_real_escape_string($mysqli, $version['runbook_version_description']);
    $subject = mysqli_real_escape_string($mysqli, $version['runbook_version_subject']);
    $details = mysqli_real_escape_string($mysqli, $version['runbook_version_details']);
    $type = mysqli_real_escape_string($mysqli, $version['runbook_version_type']);
    $runbook_key = mysqli_real_escape_string($mysqli, $version['runbook_version_key']);

    runbookDbQuery("UPDATE ticket_templates SET
        ticket_template_name = '$name',
        ticket_template_description = '$description',
        ticket_template_subject = '$subject',
        ticket_template_details = '$details',
        ticket_template_runbook_key = '$runbook_key',
        ticket_template_runbook_type = '$type'
        WHERE ticket_template_id = $ticket_template_id", 'Could not restore the runbook draft metadata');

    $old_ids = [];
    $old = runbookDbQuery("SELECT task_template_id FROM task_templates
        WHERE task_template_ticket_template_id = $ticket_template_id", 'Could not load the existing draft tasks');
    while ($row = mysqli_fetch_assoc($old)) {
        $old_ids[] = intval($row['task_template_id']);
    }
    if ($old_ids) {
        $id_list = implode(',', $old_ids);
        runbookDbQuery("DELETE FROM task_template_dependencies
            WHERE task_template_id IN ($id_list) OR depends_on_task_template_id IN ($id_list)", 'Could not clear the existing draft dependencies');
    }
    runbookDbQuery("DELETE FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id", 'Could not clear the existing draft tasks');

    $new_ids = [];
    $source_task_count = 0;
    $tasks = runbookDbQuery("SELECT * FROM runbook_version_tasks
        WHERE runbook_version_task_runbook_version_id = $runbook_version_id
        ORDER BY runbook_version_task_order, runbook_version_task_id", 'Could not load the published runbook tasks');
    while ($task = mysqli_fetch_assoc($tasks)) {
        $source_task_count++;
        $key = mysqli_real_escape_string($mysqli, $task['runbook_version_task_key']);
        $task_name = mysqli_real_escape_string($mysqli, $task['runbook_version_task_name']);
        $instructions = mysqli_real_escape_string($mysqli, $task['runbook_version_task_instructions']);
        $condition_type = mysqli_real_escape_string($mysqli, $task['runbook_version_task_condition_type']);
        $condition_value = mysqli_real_escape_string($mysqli, $task['runbook_version_task_condition_value']);
        $owner_type = mysqli_real_escape_string($mysqli, $task['runbook_version_task_owner_type']);
        $initial_state = mysqli_real_escape_string($mysqli, $task['runbook_version_task_initial_state']);
        $approval_scope = empty($task['runbook_version_task_approval_scope']) ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $task['runbook_version_task_approval_scope']) . "'";
        $approval_type = empty($task['runbook_version_task_approval_type']) ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $task['runbook_version_task_approval_type']) . "'";
        $evidence_type = mysqli_real_escape_string($mysqli, $task['runbook_version_task_evidence_type']);
        $evidence_prompt = mysqli_real_escape_string($mysqli, $task['runbook_version_task_evidence_prompt']);

        runbookDbQuery("INSERT INTO task_templates SET
            task_template_name = '$task_name',
            task_template_key = '$key',
            task_template_instructions = '$instructions',
            task_template_order = " . intval($task['runbook_version_task_order']) . ",
            task_template_completion_estimate = " . intval($task['runbook_version_task_completion_estimate']) . ",
            task_template_condition_type = '$condition_type',
            task_template_condition_value = '$condition_value',
            task_template_owner_type = '$owner_type',
            task_template_owner_user_id = " . intval($task['runbook_version_task_owner_user_id']) . ",
            task_template_due_offset_minutes = " . intval($task['runbook_version_task_due_offset_minutes']) . ",
            task_template_initial_state = '$initial_state',
            task_template_approval_scope = $approval_scope,
            task_template_approval_type = $approval_type,
            task_template_approval_user_id = " . intval($task['runbook_version_task_approval_user_id']) . ",
            task_template_evidence_type = '$evidence_type',
            task_template_evidence_prompt = '$evidence_prompt',
            task_template_ticket_template_id = $ticket_template_id", 'Could not restore a published runbook task to the draft');
        $new_id = intval(mysqli_insert_id($mysqli));
        if (!$new_id) {
            throw new RuntimeException('A restored runbook draft task did not receive an ID');
        }
        $new_ids[intval($task['runbook_version_task_id'])] = $new_id;
    }
    if ($source_task_count === 0 || count($new_ids) !== $source_task_count) {
        throw new RuntimeException('The published runbook did not restore all required draft tasks');
    }

    $dependencies = runbookDbQuery("SELECT d.* FROM runbook_version_task_dependencies d
        INNER JOIN runbook_version_tasks t ON t.runbook_version_task_id = d.runbook_version_task_id
        WHERE t.runbook_version_task_runbook_version_id = $runbook_version_id", 'Could not load the published runbook dependencies');
    while ($dependency = mysqli_fetch_assoc($dependencies)) {
        $task_id = intval($new_ids[intval($dependency['runbook_version_task_id'])] ?? 0);
        $depends_on_id = intval($new_ids[intval($dependency['depends_on_runbook_version_task_id'])] ?? 0);
        if (!$task_id || !$depends_on_id) {
            throw new RuntimeException('A published runbook dependency references a missing restored draft task');
        }
        runbookDbQuery("INSERT INTO task_template_dependencies SET
            task_template_id = $task_id, depends_on_task_template_id = $depends_on_id", 'Could not restore a published runbook dependency');
    }

    return true;
}

function runbookEvaluateCondition($condition_type, $condition_value, $client_id) {
    global $mysqli;

    $condition_type = runbookNormalizeChoice($condition_type, runbookConditionTypes(), 'manual_confirm');
    $condition_value = trim((string) $condition_value);
    $client_id = intval($client_id);

    if ($condition_type === 'always') {
        return 'Matched';
    }
    if ($condition_type === 'manual_confirm' || !$client_id) {
        return 'Manual';
    }

    $value = mysqli_real_escape_string($mysqli, $condition_value);
    $count = 0;
    if ($condition_type === 'client_has_service' && $value !== '') {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM services
            WHERE service_client_id = $client_id
            AND (LOCATE(LOWER('$value'), LOWER(COALESCE(service_name, ''))) > 0
                OR LOCATE(LOWER('$value'), LOWER(COALESCE(service_category, ''))) > 0)"));
        $count = intval($row[0] ?? 0);
    } elseif ($condition_type === 'client_has_asset_type' && $value !== '') {
        $asset_filter = strcasecmp($condition_value, 'Workstation') === 0
            ? "LOWER(asset_type) IN ('workstation','laptop','desktop')"
            : "LOCATE(LOWER('$value'), LOWER(COALESCE(asset_type, ''))) > 0";
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM assets
            WHERE asset_client_id = $client_id AND asset_archived_at IS NULL
            AND $asset_filter"));
        $count = intval($row[0] ?? 0);
    } elseif ($condition_type === 'client_has_active_contract') {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM contracts
            WHERE contract_client_id = $client_id AND contract_archived_at IS NULL
            AND LOWER(TRIM(contract_status)) IN
                ('active','accepted','signed','executed','in force','in-force')
            AND (contract_start_date IS NULL OR contract_start_date <= CURRENT_DATE())
            AND (contract_end_date IS NULL OR contract_end_date >= CURRENT_DATE())"));
        $count = intval($row[0] ?? 0);
    } elseif ($condition_type === 'client_has_backup') {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM services
            WHERE service_client_id = $client_id
            AND (LOCATE('backup', LOWER(COALESCE(service_name, ''))) > 0
                OR LOCATE('backup', LOWER(COALESCE(service_category, ''))) > 0
                OR (service_backup IS NOT NULL AND service_backup <> ''))"));
        $count = intval($row[0] ?? 0);
    }

    return $count > 0 ? 'Matched' : 'Skipped';
}

function runbookResolveOwner($owner_type, $owner_user_id, $ticket) {
    global $mysqli;

    $resolved_user_id = 0;
    if ($owner_type === 'specific_user') {
        $resolved_user_id = max(0, intval($owner_user_id));
    } elseif ($owner_type === 'ticket_assignee') {
        $resolved_user_id = max(0, intval($ticket['ticket_assigned_to'] ?? 0));
    } elseif ($owner_type === 'project_manager') {
        $project_id = intval($ticket['ticket_project_id'] ?? 0);
        if ($project_id) {
            $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT project_manager FROM projects
                WHERE project_id = $project_id LIMIT 1"));
            $resolved_user_id = max(0, intval($row[0] ?? 0));
        }
    }
    if (!$resolved_user_id) {
        return 0;
    }
    $active = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
        WHERE user_id = $resolved_user_id AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL"));
    return intval($active[0] ?? 0) === 1 ? $resolved_user_id : 0;
}

/**
 * Confirm that an approval route has at least one active decision maker.
 *
 * Email delivery is part of client-route availability because the guest link
 * is the fallback when a matching contact cannot see the ticket in the portal.
 * Internal routes also honor support permissions and client access scope.
 */
function runbookApprovalRouteAvailability($scope, $type, $required_user_id, $ticket, $created_by = 0) {
    global $mysqli, $config_smtp_provider, $config_smtp_host;

    $scope = (string) $scope;
    $type = (string) $type;
    $required_user_id = intval($required_user_id);
    $created_by = intval($created_by);
    $client_id = intval($ticket['ticket_client_id'] ?? 0);
    $contact_id = intval($ticket['ticket_contact_id'] ?? 0);

    if ($scope === 'internal' && $type === 'specific') {
        if (!$required_user_id) {
            return [false, 'No specific internal approver is assigned.'];
        }
        if ($created_by && $required_user_id === $created_by) {
            return [false, 'The requester cannot be the specific internal approver.'];
        }
        $active = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
            WHERE user_id = $required_user_id AND user_type = 1 AND user_status = 1
            AND user_archived_at IS NULL AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR EXISTS (SELECT 1 FROM user_role_permissions p
                    INNER JOIN modules m ON m.module_id = p.module_id
                    WHERE p.user_role_id = users.user_role_id
                    AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2)
            ) AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR ($client_id > 0
                    AND NOT EXISTS (SELECT 1 FROM user_client_permissions d
                        WHERE d.user_id = users.user_id AND d.client_id = $client_id AND d.permission_type = 'deny')
                    AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.permission_type = 'allow')
                        OR EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.client_id = $client_id AND a.permission_type = 'allow')))
            )"));
        return intval($active[0] ?? 0) === 1
            ? [true, '']
            : [false, 'The specific internal approver is missing, inactive, or lacks support/client access.'];
    }

    if ($scope === 'internal' && $type === 'any') {
        $exclude = $created_by ? "AND user_id <> $created_by" : '';
        $active = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
            WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL $exclude
            AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR EXISTS (SELECT 1 FROM user_role_permissions p
                    INNER JOIN modules m ON m.module_id = p.module_id
                    WHERE p.user_role_id = users.user_role_id
                    AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2)
            ) AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR ($client_id > 0
                    AND NOT EXISTS (SELECT 1 FROM user_client_permissions d
                        WHERE d.user_id = users.user_id AND d.client_id = $client_id AND d.permission_type = 'deny')
                    AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.permission_type = 'allow')
                        OR EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.client_id = $client_id AND a.permission_type = 'allow')))
            )"));
        return intval($active[0] ?? 0) > 0
            ? [true, '']
            : [false, 'No other active internal user can decide this approval.'];
    }

    if ($scope !== 'client' || !$client_id || !in_array($type, ['any', 'technical', 'billing'], true)) {
        return [false, 'The approval route is invalid.'];
    }

    $role_filter = '1 = 1';
    if ($type === 'technical') {
        $role_filter = 'contact_technical = 1';
    } elseif ($type === 'billing') {
        $role_filter = 'contact_billing = 1';
    }
    $portal_eligible = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM contacts
        INNER JOIN users ON user_id = contact_user_id
        WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL
        AND $role_filter
        AND (contact_id = $contact_id OR contact_portal_ticket_scope = 'client')"));
    if (intval($portal_eligible[0] ?? 0) > 0) {
        return [true, ''];
    }

    if (empty($config_smtp_provider) && empty($config_smtp_host)) {
        return [false, 'No matching portal contact is available and outbound email is not configured.'];
    }

    if ($type === 'any') {
        $contact_filter = $contact_id
            ? "contact_id = $contact_id"
            : 'contact_primary = 1';
    } elseif ($type === 'technical') {
        $contact_filter = 'contact_technical = 1';
    } else {
        $contact_filter = 'contact_billing = 1';
    }
    $eligible = mysqli_query($mysqli, "SELECT contact_email FROM contacts
        WHERE contact_client_id = $client_id AND $contact_filter
        AND contact_archived_at IS NULL AND COALESCE(contact_email, '') <> ''");
    while ($contact = mysqli_fetch_assoc($eligible)) {
        if (filter_var(trim((string) $contact['contact_email']), FILTER_VALIDATE_EMAIL)) {
            return [true, ''];
        }
    }

    return [false, 'No portal-authorized contact or valid deliverable email recipient is available.'];
}

function runbookQueueApprovalNotification($approval_id, $ticket, $task, $url_key, $created_by) {
    global $mysqli, $config_smtp_provider, $config_smtp_host, $config_ticket_from_email,
        $config_ticket_from_name, $config_base_url;

    $approval_id = intval($approval_id);
    $created_by = intval($created_by);
    $scope = (string) $task['runbook_version_task_approval_scope'];
    $type = (string) $task['runbook_version_task_approval_type'];
    $required_user_id = intval($task['runbook_version_task_approval_user_id']);
    $ticket_id = intval($ticket['ticket_id']);
    $client_id = intval($ticket['ticket_client_id']);
    $task_name = (string) $task['runbook_version_task_name'];

    [$route_available] = runbookApprovalRouteAvailability(
        $scope,
        $type,
        $required_user_id,
        $ticket,
        $created_by
    );

    if ($route_available && $scope === 'internal' && $type === 'specific' && $required_user_id) {
        $notification = escapeSql("Runbook task $task_name requires your approval");
        runbookDbQuery("INSERT INTO notifications SET notification_type = 'Ticket',
            notification = '$notification', notification_action = 'ticket.php?ticket_id=$ticket_id',
            notification_client_id = $client_id, notification_user_id = $required_user_id", 'Could not notify the specific runbook approver');
    }

    $recipients = [];
    if ($route_available && $scope === 'internal' && $type === 'specific' && $required_user_id) {
        $recipient = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name AS recipient_name,
            user_email AS recipient_email FROM users WHERE user_id = $required_user_id
            AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1"));
        if ($recipient) {
            $recipients[] = $recipient;
        }
    } elseif ($route_available && $scope === 'internal' && $type === 'any') {
        $exclude = $created_by ? "AND user_id <> $created_by" : '';
        $users = mysqli_query($mysqli, "SELECT user_id, user_name AS recipient_name,
            user_email AS recipient_email FROM users WHERE user_type = 1 AND user_status = 1
            AND user_archived_at IS NULL $exclude AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR EXISTS (SELECT 1 FROM user_role_permissions p
                    INNER JOIN modules m ON m.module_id = p.module_id
                    WHERE p.user_role_id = users.user_role_id
                    AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2)
            ) AND (
                EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
                OR ($client_id > 0
                    AND NOT EXISTS (SELECT 1 FROM user_client_permissions d
                        WHERE d.user_id = users.user_id AND d.client_id = $client_id AND d.permission_type = 'deny')
                    AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.permission_type = 'allow')
                        OR EXISTS (SELECT 1 FROM user_client_permissions a
                            WHERE a.user_id = users.user_id AND a.client_id = $client_id AND a.permission_type = 'allow')))
            ) ORDER BY user_id");
        while ($recipient = mysqli_fetch_assoc($users)) {
            $recipient_user_id = intval($recipient['user_id']);
            $notification = escapeSql("Runbook task $task_name requires an internal approval");
            runbookDbQuery("INSERT INTO notifications SET notification_type = 'Ticket',
                notification = '$notification', notification_action = 'ticket.php?ticket_id=$ticket_id',
                notification_client_id = $client_id, notification_user_id = $recipient_user_id", 'Could not notify an eligible runbook approver');
            $recipients[] = $recipient;
        }
    } elseif ($scope === 'client') {
        $where = '';
        if ($type === 'any') {
            $contact_id = intval($ticket['ticket_contact_id']);
            $where = $contact_id
                ? "contact_id = $contact_id AND contact_client_id = $client_id"
                : "contact_client_id = $client_id AND contact_primary = 1";
        } elseif ($type === 'technical') {
            $where = "contact_client_id = $client_id AND contact_technical = 1";
        } elseif ($type === 'billing') {
            $where = "contact_client_id = $client_id AND contact_billing = 1";
        }
        if ($where) {
            $contacts = mysqli_query($mysqli, "SELECT contact_name AS recipient_name,
                contact_email AS recipient_email FROM contacts
                WHERE $where AND contact_archived_at IS NULL AND COALESCE(contact_email, '') <> ''");
            while ($contact = mysqli_fetch_assoc($contacts)) {
                $recipients[] = $contact;
            }
        }
    }

    if (!$route_available) {
        if ($created_by) {
            $notification = escapeSql("No eligible recipient was found for runbook approval: $task_name");
            runbookDbQuery("INSERT INTO notifications SET notification_type = 'Ticket',
                notification = '$notification', notification_action = 'ticket.php?ticket_id=$ticket_id',
                notification_client_id = $client_id, notification_user_id = $created_by", 'Could not notify the approval requester that no route is available');
        }
        return false;
    }

    if (empty($config_smtp_provider) && empty($config_smtp_host)) {
        return true;
    }

    // A portal-authorized contact or internal user can act without email.
    if (!$recipients) {
        return true;
    }

    $base_url = rtrim((string) $config_base_url, '/');
    if (!preg_match('#^https?://#i', $base_url)) {
        $base_url = 'https://' . $base_url;
    }
    if ($scope === 'client') {
        $approval_url = $base_url . '/guest/guest_approve_ticket_task.php?task_approval_id=' . $approval_id
            . '&url_key=' . rawurlencode($url_key);
    } else {
        $approval_url = $base_url . '/agent/ticket.php?ticket_id=' . $ticket_id;
    }
    $ticket_label = (string) $ticket['ticket_prefix'] . intval($ticket['ticket_number']);
    $ticket_subject = (string) $ticket['ticket_subject'];
    $subject = escapeSql("Approval required - [$ticket_label] - $ticket_subject");
    $safe_task_name = escapeHtml($task_name);
    $safe_ticket_subject = escapeHtml($ticket_subject);
    $safe_approval_url = escapeHtml($approval_url);
    $credential_warning = $scope === 'client'
        ? '<br><br>Do not forward this approval link; it acts as the approval credential.'
        : '';
    $body = escapeSql("Hello,<br><br>The ticket <strong>$safe_ticket_subject</strong> has a runbook task requiring your decision:<br><br><strong>$safe_task_name</strong><br><br><a href=\"$safe_approval_url\">Review and decide this approval</a>.$credential_warning");
    $from = escapeSql((string) $config_ticket_from_email);
    $from_name = escapeSql((string) $config_ticket_from_name);
    $mail = [];
    $seen = [];
    foreach ($recipients as $recipient) {
        $email_raw = trim((string) $recipient['recipient_email']);
        if (!filter_var($email_raw, FILTER_VALIDATE_EMAIL) || isset($seen[strtolower($email_raw)])) {
            continue;
        }
        $seen[strtolower($email_raw)] = true;
        $mail[] = [
            'from' => $from,
            'from_name' => $from_name,
            'recipient' => escapeSql($email_raw),
            'recipient_name' => escapeSql((string) $recipient['recipient_name']),
            'subject' => $subject,
            'body' => $body,
        ];
    }
    foreach ($mail as $email) {
        addToMailQueue([$email]);
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('Could not queue a runbook approval email');
        }
    }
    return true;
}

function instantiateRunbookForTicket($ticket_id, $ticket_template_id, $context = []) {
    global $mysqli, $session_user_id;

    $ticket_id = intval($ticket_id);
    $ticket_template_id = intval($ticket_template_id);
    $caller_transaction = !empty($context['caller_transaction']);
    $version_id = intval($context['version_id'] ?? 0);
    if (!$version_id) {
        $version_id = runbookLatestPublishedVersionId($ticket_template_id);
    }
    if (!$ticket_id || !$version_id) {
        if ($caller_transaction) {
            throw new RuntimeException('A ticket and published runbook version are required');
        }
        return 0;
    }

    $version_result = mysqli_query($mysqli, "SELECT runbook_version_definition_hash
        FROM runbook_versions WHERE runbook_version_id = $version_id
        AND runbook_version_ticket_template_id = $ticket_template_id LIMIT 1");
    $version = $version_result ? mysqli_fetch_assoc($version_result) : null;
    if (!$version) {
        if ($caller_transaction) {
            throw new RuntimeException('The requested runbook version does not belong to the ticket template');
        }
        error_log("Runbook version $version_id is unavailable for ticket template $ticket_template_id");
        return 0;
    }

    $existing = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM runbook_executions
        WHERE runbook_execution_ticket_id = $ticket_id"));
    if (intval($existing[0] ?? 0) > 0) {
        if ($caller_transaction) {
            throw new RuntimeException('The ticket already has a runbook execution');
        }
        return 0;
    }

    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id,
        ticket_contact_id, ticket_assigned_to, ticket_project_id, ticket_created_at,
        ticket_prefix, ticket_number, ticket_subject
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket) {
        if ($caller_transaction) {
            throw new RuntimeException('The runbook ticket could not be loaded');
        }
        return 0;
    }

    $started_by = intval($context['started_by'] ?? ($session_user_id ?? 0));
    $safe_context = [
        'client_id' => intval($ticket['ticket_client_id']),
        'project_id' => intval($ticket['ticket_project_id']),
        'ticket_template_id' => $ticket_template_id,
        'runbook_version_id' => $version_id,
    ];
    $context_json = mysqli_real_escape_string($mysqli, json_encode($safe_context, JSON_UNESCAPED_SLASHES));
    $snapshot = runbookVersionDefinition($version_id);
    if (!$snapshot) {
        if ($caller_transaction) {
            throw new RuntimeException('The published runbook snapshot could not be loaded');
        }
        return 0;
    }
    $snapshot_json_raw = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $snapshot_hash = runbookDefinitionHash($snapshot);
    $stored_definition_hash = strtolower(trim((string) $version['runbook_version_definition_hash']));
    if (!preg_match('/^[a-f0-9]{64}$/', $stored_definition_hash)
        || !hash_equals($stored_definition_hash, $snapshot_hash)) {
        if ($caller_transaction) {
            throw new RuntimeException('The published runbook definition failed its integrity check');
        }
        error_log("Runbook version $version_id failed its definition integrity check");
        return 0;
    }
    $snapshot_json = mysqli_real_escape_string($mysqli, $snapshot_json_raw);
    $snapshot_hash_sql = mysqli_real_escape_string($mysqli, $snapshot_hash);
    if (!$caller_transaction && !mysqli_begin_transaction($mysqli)) {
        error_log('Could not begin runbook transaction for ticket ' . $ticket_id . ': ' . mysqli_error($mysqli));
        return 0;
    }
    try {
    runbookDbQuery("INSERT INTO runbook_executions SET
        runbook_execution_version_id = $version_id,
        runbook_execution_ticket_id = $ticket_id,
        runbook_execution_context = '$context_json',
        runbook_execution_snapshot = '$snapshot_json',
        runbook_execution_snapshot_hash = '$snapshot_hash_sql',
        runbook_execution_started_by = $started_by", 'Could not start the runbook execution');
    $execution_id = intval(mysqli_insert_id($mysqli));
    if (!$execution_id) {
        throw new RuntimeException('The runbook execution did not receive an ID');
    }

    $created_at = strtotime($ticket['ticket_created_at'] ?: 'now');
    $runtime_ids = [];
    $tasks_added = 0;
    $tasks = runbookDbQuery("SELECT * FROM runbook_version_tasks
        WHERE runbook_version_task_runbook_version_id = $version_id
        ORDER BY runbook_version_task_order ASC, runbook_version_task_id ASC", 'Could not load published runbook tasks');
    while ($task = mysqli_fetch_assoc($tasks)) {
        $version_task_id = intval($task['runbook_version_task_id']);
        $condition_result = runbookEvaluateCondition(
            $task['runbook_version_task_condition_type'],
            $task['runbook_version_task_condition_value'],
            intval($ticket['ticket_client_id'])
        );
        $initial_state = $task['runbook_version_task_initial_state'] === 'Waiting' ? 'Waiting' : 'Ready';
        $state = $condition_result === 'Skipped' ? 'Skipped' : $initial_state;
        if ($condition_result === 'Manual') {
            $state = 'Waiting';
        }

        $owner_id = runbookResolveOwner(
            $task['runbook_version_task_owner_type'],
            $task['runbook_version_task_owner_user_id'],
            $ticket
        );
        $owner_required = $task['runbook_version_task_owner_type'] !== 'unassigned';
        if ($owner_required && !$owner_id && $state !== 'Skipped') {
            $state = 'Waiting';
        }
        $due_offset = max(0, intval($task['runbook_version_task_due_offset_minutes']));
        $due_at_sql = 'NULL';
        if ($due_offset) {
            $due_at = date('Y-m-d H:i:s', $created_at + ($due_offset * 60));
            $due_at_sql = "'" . mysqli_real_escape_string($mysqli, $due_at) . "'";
        }

        $approval_scope = $task['runbook_version_task_approval_scope'];
        $approval_type = $task['runbook_version_task_approval_type'];
        $approval_user = $approval_type === 'specific'
            ? intval($task['runbook_version_task_approval_user_id']) : 0;
        $approval_rule_valid = in_array($approval_scope, ['internal', 'client'], true)
            && in_array($approval_type, ['any', 'technical', 'billing', 'specific'], true);
        $approval_route_error = '';
        if ($approval_rule_valid && $state !== 'Skipped') {
            [$route_available, $approval_route_error] = runbookApprovalRouteAvailability(
                $approval_scope,
                $approval_type,
                $approval_user,
                $ticket,
                $started_by
            );
            if (!$route_available) {
                $state = 'Waiting';
            }
        }

        $name = mysqli_real_escape_string($mysqli, $task['runbook_version_task_name']);
        $instructions = mysqli_real_escape_string($mysqli, $task['runbook_version_task_instructions']);
        $state_sql = mysqli_real_escape_string($mysqli, $state);
        $condition_result_sql = mysqli_real_escape_string($mysqli, $condition_result);
        $evidence_type = mysqli_real_escape_string($mysqli, $task['runbook_version_task_evidence_type']);
        $evidence_prompt = mysqli_real_escape_string($mysqli, $task['runbook_version_task_evidence_prompt']);
        $completed_sql = $state === 'Skipped' ? 'NOW()' : 'NULL';
        $waiting_reason_text = '';
        if ($condition_result === 'Manual') {
            $waiting_reason_text = 'Applicability must be confirmed before work starts';
        }
        if ($owner_required && !$owner_id) {
            $waiting_reason_text .= ($waiting_reason_text ? '; ' : '') . 'The configured task owner could not be resolved';
        }
        if ($approval_route_error !== '') {
            $waiting_reason_text .= ($waiting_reason_text ? '; ' : '')
                . $approval_route_error . ' An administrator must reroute the approval.';
        }
        $waiting_reason = $waiting_reason_text === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, $waiting_reason_text) . "'";

        runbookDbQuery("INSERT INTO tasks SET
            task_name = '$name',
            task_instructions = '$instructions',
            task_state = '$state_sql',
            task_order = " . intval($task['runbook_version_task_order']) . ",
            task_completion_estimate = " . intval($task['runbook_version_task_completion_estimate']) . ",
            task_assigned_to = $owner_id,
            task_due_at = $due_at_sql,
            task_waiting_reason = $waiting_reason,
            task_condition_result = '$condition_result_sql',
            task_evidence_required = '$evidence_type',
            task_evidence_prompt = '$evidence_prompt',
            task_runbook_version_task_id = $version_task_id,
            task_completed_at = $completed_sql,
            task_ticket_id = $ticket_id", 'Could not create a runbook task');
        $task_id = intval(mysqli_insert_id($mysqli));
        if (!$task_id) {
            throw new RuntimeException('A runbook task did not receive an ID');
        }
        runbookRecordTaskStateEvent(
            $task_id,
            null,
            $state,
            $waiting_reason_text === '' ? 'Runbook execution instantiated' : $waiting_reason_text,
            $started_by,
            $started_by ? 'agent' : 'system'
        );
        $runtime_ids[$version_task_id] = $task_id;
        $tasks_added++;

        if ($task_id && $approval_rule_valid && $state !== 'Skipped') {
            $scope_sql = mysqli_real_escape_string($mysqli, $approval_scope);
            $type_sql = mysqli_real_escape_string($mysqli, $approval_type);
            $approval_user_sql = $approval_user ? (string) $approval_user : 'NULL';
            $url_key_raw = function_exists('randomString') ? randomString(32) : bin2hex(random_bytes(16));
            $url_key = mysqli_real_escape_string($mysqli, runbookApprovalTokenHash($url_key_raw));
            $url_expires_at_value = runbookApprovalTokenExpiry();
            $url_expires_at = mysqli_real_escape_string($mysqli, $url_expires_at_value);
            runbookDbQuery("INSERT INTO task_approvals SET
                approval_scope = '$scope_sql', approval_type = '$type_sql',
                approval_required_user_id = $approval_user_sql,
                approval_status = 'pending', approval_created_by = $started_by,
                approval_url_key = '$url_key', approval_url_expires_at = '$url_expires_at',
                approval_task_id = $task_id", 'Could not create a runbook approval');
            $approval_id = intval(mysqli_insert_id($mysqli));
            if (!$approval_id) {
                throw new RuntimeException('A runbook task approval did not receive an ID');
            }
            runbookRecordApprovalEvent(
                $approval_id,
                $task_id,
                'created',
                [],
                [
                    'status' => 'pending',
                    'scope' => $approval_scope,
                    'type' => $approval_type,
                    'required_user_id' => $approval_user,
                ],
                $started_by ? 'agent' : 'system',
                $started_by,
                '',
                'Runbook execution instantiated',
                $url_expires_at_value
            );
            runbookQueueApprovalNotification($approval_id, $ticket, $task, $url_key_raw, $started_by);
        }
    }

    if ($tasks_added === 0) {
        throw new RuntimeException('The published runbook contains no executable tasks');
    }

    if ($runtime_ids) {
        $dependencies = runbookDbQuery("SELECT d.* FROM runbook_version_task_dependencies d
            INNER JOIN runbook_version_tasks t ON t.runbook_version_task_id = d.runbook_version_task_id
            WHERE t.runbook_version_task_runbook_version_id = $version_id", 'Could not load runbook dependencies');
        while ($dependency = mysqli_fetch_assoc($dependencies)) {
            $task_id = intval($runtime_ids[intval($dependency['runbook_version_task_id'])] ?? 0);
            $depends_on_id = intval($runtime_ids[intval($dependency['depends_on_runbook_version_task_id'])] ?? 0);
            if (!$task_id || !$depends_on_id) {
                throw new RuntimeException('A published dependency references a missing runtime task');
            }
            runbookDbQuery("INSERT INTO task_dependencies SET
                task_id = $task_id, depends_on_task_id = $depends_on_id", 'Could not create a runbook dependency');
        }
    }

    refreshRunbookTaskStates($ticket_id);
    if (!$caller_transaction && !mysqli_commit($mysqli)) {
        throw new RuntimeException('Could not commit the runbook execution');
    }
    return $tasks_added;
    } catch (Throwable $exception) {
        if ($caller_transaction) {
            throw $exception;
        }
        mysqli_rollback($mysqli);
        error_log('Runbook instantiation failed for ticket ' . $ticket_id . ': ' . $exception->getMessage());
        return 0;
    }
}

function refreshRunbookTaskStates($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    if (!$ticket_id) {
        return;
    }

    $sql = runbookDbQuery("SELECT t.task_id, t.task_state,
        SUM(CASE WHEN dependency.task_id IS NOT NULL
            AND dependency.task_state NOT IN ('Completed','Skipped') THEN 1 ELSE 0 END) AS pending_dependencies
        FROM tasks t
        LEFT JOIN task_dependencies d ON d.task_id = t.task_id
        LEFT JOIN tasks dependency ON dependency.task_id = d.depends_on_task_id
        WHERE t.task_ticket_id = $ticket_id
        AND t.task_runbook_version_task_id > 0
        GROUP BY t.task_id, t.task_state", 'Could not load runbook task dependency states');

    while ($task = mysqli_fetch_assoc($sql)) {
        $task_id = intval($task['task_id']);
        $state = $task['task_state'];
        if (!in_array($state, ['Ready', 'Blocked'], true)) {
            continue;
        }
        $next_state = intval($task['pending_dependencies']) > 0 ? 'Blocked' : 'Ready';
        if ($next_state !== $state) {
            $state_sql = mysqli_real_escape_string($mysqli, $state);
            runbookDbQuery("UPDATE tasks SET task_state = '$next_state'
                WHERE task_id = $task_id AND task_state = '$state_sql'", 'Could not refresh a runbook task state');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('A runbook task changed while dependency state was refreshing');
            }
            runbookRecordTaskStateEvent(
                $task_id,
                $state,
                $next_state,
                $next_state === 'Blocked' ? 'Waiting for prerequisite tasks' : 'Prerequisite tasks satisfied',
                0,
                'system'
            );
        }
    }

    $remaining = mysqli_fetch_row(runbookDbQuery("SELECT COUNT(*) FROM tasks
        WHERE task_ticket_id = $ticket_id
        AND task_runbook_version_task_id > 0
        AND task_state NOT IN ('Completed','Skipped')", 'Could not count remaining runbook tasks'));
    if (intval($remaining[0] ?? 0) === 0) {
        runbookDbQuery("UPDATE runbook_executions SET
            runbook_execution_status = 'Completed',
            runbook_execution_completed_at = COALESCE(runbook_execution_completed_at, NOW())
            WHERE runbook_execution_ticket_id = $ticket_id", 'Could not mark the runbook execution complete');
    } else {
        runbookDbQuery("UPDATE runbook_executions SET
            runbook_execution_status = 'Active', runbook_execution_completed_at = NULL
            WHERE runbook_execution_ticket_id = $ticket_id", 'Could not mark the runbook execution active');
    }
}

function reopenRunbookTaskAndDependents($task_id, $actor_id = 0, $reason = 'Task reopened') {
    global $mysqli;

    $task_id = intval($task_id);
    $ticket_id = intval(getFieldById('tasks', $task_id, 'task_ticket_id'));
    if (!$task_id || !$ticket_id) {
        return 0;
    }

    $reopen_ids = [$task_id => $task_id];
    $frontier = [$task_id];
    while ($frontier) {
        $id_list = implode(',', array_map('intval', $frontier));
        $frontier = [];
        $dependents = runbookDbQuery("SELECT d.task_id FROM task_dependencies d
            INNER JOIN tasks child ON child.task_id = d.task_id
            WHERE d.depends_on_task_id IN ($id_list)
            AND child.task_ticket_id = $ticket_id", 'Could not load dependent runbook tasks for reopening');
        while ($dependent = mysqli_fetch_assoc($dependents)) {
            $dependent_id = intval($dependent['task_id']);
            if ($dependent_id && !isset($reopen_ids[$dependent_id])) {
                $reopen_ids[$dependent_id] = $dependent_id;
                $frontier[] = $dependent_id;
            }
        }
    }

    $id_list = implode(',', $reopen_ids);
    $completed_ids = [];
    $completed = runbookDbQuery("SELECT task_id FROM tasks WHERE task_id IN ($id_list)
        AND task_ticket_id = $ticket_id AND task_state = 'Completed' FOR UPDATE", 'Could not lock completed runbook tasks for reopening');
    while ($completed_task = mysqli_fetch_assoc($completed)) {
        $completed_ids[] = intval($completed_task['task_id']);
    }
    if (!$completed_ids) {
        return 0;
    }
    $completed_id_list = implode(',', $completed_ids);
    runbookDbQuery("UPDATE tasks SET task_state = 'Ready', task_completed_at = NULL,
        task_completed_by = NULL WHERE task_id IN ($completed_id_list)
        AND task_ticket_id = $ticket_id AND task_state = 'Completed'", 'Could not reopen the runbook task chain');
    $reopened = mysqli_affected_rows($mysqli);
    if ($reopened !== count($completed_ids)) {
        throw new RuntimeException('A completed task changed while the runbook chain was reopening');
    }
    foreach ($completed_ids as $completed_id) {
        runbookRecordTaskStateEvent($completed_id, 'Completed', 'Ready', $reason, $actor_id, $actor_id ? 'agent' : 'system');
    }
    refreshRunbookTaskStates($ticket_id);
    return max(0, intval($reopened));
}

function runbookTaskEvidenceSatisfied($task_id, $required_type) {
    global $mysqli;

    $task_id = intval($task_id);
    if ($required_type === 'none' || $required_type === '') {
        return true;
    }

    $where = '';
    if ($required_type === 'note') {
        $where = "AND e.task_evidence_type = 'note' AND COALESCE(e.task_evidence_note, '') <> ''";
    } elseif ($required_type === 'url') {
        $where = "AND e.task_evidence_type = 'url' AND COALESCE(e.task_evidence_url, '') <> ''";
    } elseif ($required_type === 'file') {
        $where = "AND e.task_evidence_type = 'file' AND EXISTS (
            SELECT 1 FROM ticket_attachments a
            WHERE a.ticket_attachment_id = e.task_evidence_attachment_id
            AND a.ticket_attachment_ticket_id = t.task_ticket_id
        )";
    } elseif ($required_type === 'any') {
        $where = "AND (
            (e.task_evidence_type = 'note' AND COALESCE(e.task_evidence_note, '') <> '')
            OR (e.task_evidence_type = 'url' AND COALESCE(e.task_evidence_url, '') <> '')
            OR (e.task_evidence_type = 'file' AND EXISTS (
                SELECT 1 FROM ticket_attachments a
                WHERE a.ticket_attachment_id = e.task_evidence_attachment_id
                AND a.ticket_attachment_ticket_id = t.task_ticket_id
            ))
        )";
    } else {
        return false;
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_evidence e
        INNER JOIN tasks t ON t.task_id = e.task_evidence_task_id
        WHERE e.task_evidence_task_id = $task_id $where"));
    return intval($row[0] ?? 0) > 0;
}

function runbookTaskCanComplete($task_id) {
    global $mysqli;

    $task_id = intval($task_id);
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_state, task_evidence_required
        FROM tasks WHERE task_id = $task_id LIMIT 1"));
    if (!$task) {
        return [false, 'Task not found.'];
    }

    if ($task['task_state'] !== 'Ready') {
        return [false, 'Only a ready task can be completed. This task is ' . strtolower($task['task_state']) . '.'];
    }

    $approvals = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_approvals
        WHERE approval_task_id = $task_id AND approval_status <> 'approved'"));
    if (intval($approvals[0] ?? 0) > 0) {
        return [false, 'All required approvals must be approved first.'];
    }

    $evidence_type = $task['task_evidence_required'] ?: 'none';
    if (!runbookTaskEvidenceSatisfied($task_id, $evidence_type)) {
        return [false, 'Required ' . $evidence_type . ' evidence must be added first.'];
    }

    return [true, ''];
}

/**
 * Evaluate only the immutable runbook/task portion of ticket resolution.
 *
 * Keep this separate from the composite lifecycle gate so documentation and
 * future operational controls can be composed without duplicating this logic
 * across every resolve/close entry point.
 */
function runbookOnlyTicketCanResolve($ticket_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $remaining = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tasks
        WHERE task_ticket_id = $ticket_id
        AND ((task_runbook_version_task_id > 0 AND task_state NOT IN ('Completed','Skipped'))
            OR (task_runbook_version_task_id = 0 AND task_completed_at IS NULL))"));
    if (intval($remaining[0] ?? 0) > 0) {
        return [false, intval($remaining[0]) . ' task(s) are not complete or intentionally skipped.'];
    }

    $pending_approvals = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        WHERE task_ticket_id = $ticket_id AND task_state <> 'Skipped'
        AND approval_status <> 'approved'"));
    if (intval($pending_approvals[0] ?? 0) > 0) {
        return [false, intval($pending_approvals[0]) . ' task approval(s) are still unresolved.'];
    }

    return [true, ''];
}

/**
 * Canonical ticket lifecycle gate.
 *
 * Existing callers intentionally continue to invoke runbookTicketCanResolve().
 * That compatibility wrapper now reaches this composite gate, which means the
 * agent, API, portal, guest, automation, project and cron paths already wired
 * for runbook safety also receive documentation safety.
 *
 * Detailed documentation failures are reserved for an authorized technician
 * view. External lifecycle surfaces receive a stable generic message so
 * requirement names, exception reasons and client context cannot leak.
 */
function ticketLifecycleCanResolve($ticket_id, $include_documentation_detail = false) {
    [$runbook_allowed, $runbook_error] = runbookOnlyTicketCanResolve($ticket_id);
    if (!$runbook_allowed) {
        return [false, $runbook_error];
    }

    if (function_exists('documentationTicketCanResolve')) {
        $documentation_result = documentationTicketCanResolve(intval($ticket_id));
        $documentation_allowed = boolval($documentation_result[0] ?? false);
        if (!$documentation_allowed) {
            $generic_error = 'Required documentation must be assessed or updated before this ticket can be resolved.';
            $detail = trim((string) ($documentation_result[1] ?? ''));
            return [false, $include_documentation_detail && $detail !== '' ? $detail : $generic_error];
        }
    }

    if (function_exists('ticketOperationalCanResolve')) {
        [$operational_allowed, $operational_error] = ticketOperationalCanResolve(
            intval($ticket_id),
            boolval($include_documentation_detail)
        );
        if (!$operational_allowed) {
            return [false, $operational_error];
        }
    }

    return [true, ''];
}

/**
 * Backwards-compatible public gate used by all existing terminal transitions.
 */
function runbookTicketCanResolve($ticket_id) {
    return ticketLifecycleCanResolve($ticket_id, false);
}

function runbookTaskStateBadge($state) {
    $classes = [
        'Ready' => 'primary',
        'Blocked' => 'secondary',
        'Waiting' => 'warning',
        'Completed' => 'success',
        'Skipped' => 'light',
    ];
    return $classes[$state] ?? 'secondary';
}
