<?php

/*
 * Client-presentable Markdown closeout for a completed, versioned runbook.
 *
 * The export deliberately uses the immutable published definition for task
 * identity and workflow rules, then overlays runtime completion, evidence,
 * and approval records. Client-facing output is deliberately allow-listed:
 * bearer links, database identifiers, evidence bodies, and free-form
 * operational details are retained in ITFlow rather than copied here.
 */

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';

enforceUserPermission('module_support');

function runbookExportServerError() {
    global $mysqli;

    mysqli_rollback($mysqli);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    exit('The runbook closeout could not be generated.');
}

function runbookExportQuery($sql, $context) {
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        error_log($context . ': ' . mysqli_error($mysqli));
        runbookExportServerError();
    }
    return $result;
}

function runbookExportConflict($message) {
    global $mysqli;

    mysqli_rollback($mysqli);
    http_response_code(409);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    exit($message);
}

function runbookExportRedactSecrets($value) {
    $value = (string) $value;
    $replacements = [
        '/-----BEGIN[^-]*PRIVATE KEY-----[\s\S]*?-----END[^-]*PRIVATE KEY-----/i' => '[REDACTED PRIVATE KEY]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i' => 'Bearer [REDACTED]',
        '/\b(?:Basic|Digest)\s+[A-Za-z0-9._~+\/=:-]+/i' => '[REDACTED AUTHORIZATION]',
        '/\b(password|passwd|secret|token|api[ _-]?key|private[ _-]?key)\b(\s*(?:[:=_-]|\bis\b)\s*)([^,;\r\n]+)/i' => '$1$2[REDACTED]',
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => '[REDACTED TOKEN]',
        '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})\b/' => '[REDACTED CREDENTIAL]',
        '/\b(?=[A-Za-z0-9+\/_-]{24,}={0,2}\b)(?=[A-Za-z0-9+\/_-]*[A-Za-z])(?=[A-Za-z0-9+\/_-]*[0-9])[A-Za-z0-9+\/_-]{24,}={0,2}\b/' => '[REDACTED HIGH-ENTROPY VALUE]',
    ];
    return preg_replace(array_keys($replacements), array_values($replacements), $value);
}

function runbookExportText($value, $fallback = 'None') {
    $value = runbookExportRedactSecrets($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    if ($value === '') {
        $value = $fallback;
    }

    // Prevent dynamic values from becoming Markdown/HTML structure.
    $value = str_replace('\\', '\\\\', $value);
    return str_replace(
        ['`', '*', '_', '{', '}', '[', ']', '<', '>', '#', '|'],
        ['\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\<', '\\>', '\\#', '\\|'],
        $value
    );
}

function runbookExportCode($value, $fallback = 'unavailable') {
    $value = preg_replace('/[^A-Za-z0-9._:-]+/', '-', trim((string) $value));
    $value = trim($value, '-');
    return '`' . ($value === '' ? $fallback : $value) . '`';
}

function runbookExportUrlReference($value) {
    // Hosts and subdomains can themselves contain tenant names or credentials.
    // The underlying record remains available through ITFlow authorization.
    return 'External URL retained in ITFlow; URL details omitted';
}

function runbookExportEvidenceReference($evidence) {
    $type = (string) ($evidence['task_evidence_type'] ?? '');
    if ($type === 'url') {
        return runbookExportUrlReference('');
    }
    if ($type === 'file') {
        return 'Attachment retained in ITFlow; filename and file contents omitted';
    }
    if ($type === 'note') {
        return 'Evidence note retained in ITFlow; note body redacted';
    }
    if ($type === 'approval_audit') {
        return 'Approval audit event retained in ITFlow; event detail redacted';
    }
    return 'Evidence record retained in ITFlow; record content redacted';
}

function runbookExportEvidenceQualifies($evidence, $required_type) {
    $type = (string) ($evidence['task_evidence_type'] ?? '');
    $has_value = intval($evidence['task_evidence_has_value'] ?? 0) === 1;
    $has_attachment = intval($evidence['task_evidence_attachment_present'] ?? 0) === 1;

    if ($required_type === 'note') {
        return $type === 'note' && $has_value;
    }
    if ($required_type === 'url') {
        return $type === 'url' && $has_value;
    }
    if ($required_type === 'file') {
        return $type === 'file' && $has_attachment;
    }
    if ($required_type === 'any') {
        return ($type === 'note' && $has_value)
            || ($type === 'url' && $has_value)
            || ($type === 'file' && $has_attachment);
    }
    return $required_type === 'none' || $required_type === '';
}

function runbookExportApprovalRecipient($approval) {
    $scope = (string) ($approval['approval_scope'] ?? '');
    $type = (string) ($approval['approval_type'] ?? '');

    if ($scope === 'internal' && $type === 'specific') {
        return 'Specific internal approver';
    }
    if ($scope === 'internal') {
        return 'Any eligible internal approver (requester excluded)';
    }
    if ($scope === 'client' && $type === 'technical') {
        return 'Authorized technical client contacts';
    }
    if ($scope === 'client' && $type === 'billing') {
        return 'Authorized billing client contacts';
    }
    if ($scope === 'client') {
        return 'Any authorized client contact';
    }
    return 'Configured approver route';
}

function runbookExportDecisionActor($approval) {
    $actor_type = (string) ($approval['approval_decision_actor_type'] ?? '');
    if ($actor_type === '') {
        return 'Not decided';
    }
    if ($actor_type === 'agent') {
        return 'Internal agent';
    }
    if ($actor_type === 'guest') {
        return 'Client decision via guest approval link (identity not asserted)';
    }
    if ($actor_type === 'contact') {
        return 'Authorized client contact';
    }
    return 'Recorded approval actor (identity details omitted)';
}

function runbookExportApprovalEventAction($event) {
    $labels = [
        'baseline' => 'Baseline',
        'created' => 'Created',
        're_requested' => 'Re-requested',
        'rerouted' => 'Rerouted',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'waived' => 'Waived',
    ];
    return $labels[(string) ($event['task_approval_event_action'] ?? '')] ?? 'Unavailable';
}

function runbookExportApprovalStatus($status) {
    $labels = [
        '' => 'Not set',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'waived' => 'Waived',
    ];
    return $labels[(string) ($status ?? '')] ?? 'Unavailable';
}

function runbookExportApprovalRoute($scope, $type, $required_user_id = 0) {
    $scope = (string) ($scope ?? '');
    $type = (string) ($type ?? '');
    $required_user_id = intval($required_user_id);
    if ($scope === '' && $type === '' && $required_user_id === 0) {
        return 'Not configured';
    }
    if ($scope === 'internal' && $type === 'specific' && $required_user_id > 0) {
        return 'Internal / specific approver';
    }
    if ($scope === 'internal' && $type === 'any' && $required_user_id === 0) {
        return 'Internal / any eligible approver';
    }
    if ($scope === 'client' && $type === 'technical' && $required_user_id === 0) {
        return 'Client / technical contacts';
    }
    if ($scope === 'client' && $type === 'billing' && $required_user_id === 0) {
        return 'Client / billing contacts';
    }
    if ($scope === 'client' && $type === 'any' && $required_user_id === 0) {
        return 'Client / any authorized contact';
    }
    return 'Unavailable';
}

function runbookExportApprovalEventActorType($event) {
    $labels = [
        'agent' => 'Agent',
        'contact' => 'Contact',
        'guest' => 'Guest',
        'system' => 'System',
    ];
    return $labels[(string) ($event['task_approval_event_actor_type'] ?? '')] ?? 'Unavailable';
}

function runbookExportApprovalEventActorLabel($event) {
    $labels = [
        'agent' => 'Internal agent',
        'contact' => 'Authorized client contact',
        'guest' => 'Guest approval link (identity not asserted)',
        'system' => 'System',
    ];
    return $labels[(string) ($event['task_approval_event_actor_type'] ?? '')]
        ?? 'Recorded approval actor (identity details omitted)';
}

function runbookExportApprovalHistoryConsistent($approval, $events, $task_state) {
    if (!$events) {
        return false;
    }

    $actions = ['baseline', 'created', 're_requested', 'rerouted', 'approved', 'declined', 'waived'];
    $statuses = ['', 'pending', 'approved', 'declined', 'waived'];
    $scopes = ['', 'internal', 'client'];
    $types = ['', 'any', 'technical', 'billing', 'specific'];
    $actor_types = ['agent', 'contact', 'guest', 'system'];
    $expected_status_by_action = [
        'created' => 'pending',
        're_requested' => 'pending',
        'rerouted' => 'pending',
        'approved' => 'approved',
        'declined' => 'declined',
        'waived' => 'waived',
    ];
    $previous = null;

    foreach ($events as $index => $event) {
        $action = (string) ($event['task_approval_event_action'] ?? '');
        $from_status = (string) ($event['task_approval_event_from_status'] ?? '');
        $to_status = (string) ($event['task_approval_event_to_status'] ?? '');
        $from_scope = (string) ($event['task_approval_event_from_scope'] ?? '');
        $to_scope = (string) ($event['task_approval_event_to_scope'] ?? '');
        $from_type = (string) ($event['task_approval_event_from_type'] ?? '');
        $to_type = (string) ($event['task_approval_event_to_type'] ?? '');
        $from_user = max(0, intval($event['task_approval_event_from_required_user_id'] ?? 0));
        $to_user = max(0, intval($event['task_approval_event_to_required_user_id'] ?? 0));
        $actor_type = (string) ($event['task_approval_event_actor_type'] ?? '');

        if (!in_array($action, $actions, true)
            || !in_array($from_status, $statuses, true)
            || !in_array($to_status, $statuses, true)
            || !in_array($from_scope, $scopes, true)
            || !in_array($to_scope, $scopes, true)
            || !in_array($from_type, $types, true)
            || !in_array($to_type, $types, true)
            || !in_array($actor_type, $actor_types, true)
            || $to_status === ''
            || $to_scope === ''
            || $to_type === ''
            || runbookExportApprovalRoute($to_scope, $to_type, $to_user) === 'Unavailable'
            || ($from_status === '' && ($from_scope !== '' || $from_type !== '' || $from_user !== 0))
            || ($from_status !== ''
                && runbookExportApprovalRoute($from_scope, $from_type, $from_user) === 'Unavailable')) {
            return false;
        }
        if ($index === 0 && !in_array($action, ['baseline', 'created'], true)) {
            return false;
        }
        if ($index > 0 && in_array($action, ['baseline', 'created'], true)) {
            return false;
        }
        if (isset($expected_status_by_action[$action])
            && $to_status !== $expected_status_by_action[$action]) {
            return false;
        }
        if ($previous !== null
            && ($from_status !== $previous['status']
                || $from_scope !== $previous['scope']
                || $from_type !== $previous['type']
                || $from_user !== $previous['required_user_id'])) {
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

    $current_status = (string) ($approval['approval_status'] ?? '');
    if ($previous['action'] === 'waived') {
        return $task_state === 'Skipped'
            && $previous['status'] === 'waived'
            && in_array($current_status, ['pending', 'declined'], true);
    }
    return $previous['status'] === $current_status;
}

function runbookExportStateActor($event) {
    $actor_type = strtolower(trim((string) ($event['task_state_event_actor_type'] ?? 'system')));
    if ($actor_type === 'agent') {
        return !empty($event['state_actor_name'])
            ? runbookExportText($event['state_actor_name'])
            : 'Internal agent (name unavailable)';
    }
    if ($actor_type === 'system') {
        return 'System';
    }
    if ($actor_type === 'client') {
        return 'Authorized client user';
    }
    if ($actor_type === 'guest') {
        return 'Guest approval link (identity not asserted)';
    }
    return 'Recorded workflow actor (details redacted)';
}

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$scope_result = runbookExportQuery("SELECT ticket_id, ticket_client_id
    FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1", 'Could not scope runbook closeout');
$scope_ticket = mysqli_fetch_assoc($scope_result);
if (!$scope_ticket) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    exit('Runbook execution not found.');
}

$client_id = intval($scope_ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

if (!mysqli_begin_transaction($mysqli)) {
    error_log('Could not begin runbook closeout snapshot: ' . mysqli_error($mysqli));
    runbookExportServerError();
}

// Every workflow mutation locks the ticket first. Retaining that parent lock
// makes all definition/runtime reads below one coherent completed snapshot.
$locked_ticket_result = runbookExportQuery("SELECT ticket_id, ticket_client_id
    FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE", 'Could not lock runbook closeout');
$locked_ticket = mysqli_fetch_assoc($locked_ticket_result);
if (!$locked_ticket || intval($locked_ticket['ticket_client_id']) !== $client_id) {
    runbookExportConflict('The ticket changed while its closeout was being prepared. Try the export again.');
}

$execution_result = runbookExportQuery("SELECT
    tickets.ticket_id, ticket_prefix, ticket_number,
    ticket_client_id, client_name, runbook_execution_status,
    runbook_execution_started_at, runbook_execution_completed_at,
    runbook_execution_snapshot, runbook_execution_snapshot_hash,
    runbook_version_id, runbook_version_number, runbook_version_definition_hash,
    runbook_version_key, runbook_version_name, runbook_version_type
    FROM runbook_executions
    INNER JOIN tickets ON ticket_id = runbook_execution_ticket_id AND ticket_deleted_at IS NULL
    INNER JOIN runbook_versions ON runbook_version_id = runbook_execution_version_id
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE runbook_execution_ticket_id = $ticket_id LIMIT 1", 'Could not load runbook closeout');
$execution = mysqli_fetch_assoc($execution_result);

if (!$execution) {
    mysqli_rollback($mysqli);
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    exit('Runbook execution not found.');
}

if ($execution['runbook_execution_status'] !== 'Completed'
    || empty($execution['runbook_execution_completed_at'])) {
    runbookExportConflict('This closeout is available only after the runbook execution is Completed.');
}

$snapshot = json_decode((string) $execution['runbook_execution_snapshot'], true);
$stored_hash = (string) $execution['runbook_execution_snapshot_hash'];
$published_hash = (string) $execution['runbook_version_definition_hash'];
if (!is_array($snapshot)) {
    runbookExportConflict('The completed runbook could not be exported because its immutable execution data failed integrity validation. Contact an administrator.');
}
$computed_hash = runbookDefinitionHash($snapshot);
if (!hash_equals($stored_hash, $computed_hash) || !hash_equals($published_hash, $computed_hash)) {
    runbookExportConflict('The completed runbook could not be exported because its immutable execution data failed integrity validation. Contact an administrator.');
}

$version_id = intval($execution['runbook_version_id']);
$published_definition = runbookVersionDefinition($version_id);
if (!is_array($published_definition)
    || !hash_equals($computed_hash, runbookDefinitionHash($published_definition))) {
    runbookExportConflict('The completed runbook could not be exported because its published source no longer matches the immutable execution snapshot. Contact an administrator.');
}
$source_count = intval(mysqli_fetch_row(runbookExportQuery("SELECT COUNT(*)
    FROM runbook_version_tasks
    WHERE runbook_version_task_runbook_version_id = $version_id", 'Could not count published runbook tasks'))[0] ?? 0);
$runtime_counts = mysqli_fetch_assoc(runbookExportQuery("SELECT COUNT(*) AS runtime_count,
    SUM(CASE WHEN runbook_version_task_id IS NOT NULL THEN 1 ELSE 0 END) AS matching_count,
    COUNT(DISTINCT runbook_version_task_id) AS mapped_count
    FROM tasks
    LEFT JOIN runbook_version_tasks
        ON runbook_version_task_id = task_runbook_version_task_id
        AND runbook_version_task_runbook_version_id = $version_id
    WHERE task_ticket_id = $ticket_id
    AND task_runbook_version_task_id > 0", 'Could not verify runtime runbook tasks'));

if ($source_count < 1
    || intval($runtime_counts['runtime_count'] ?? 0) !== $source_count
    || intval($runtime_counts['matching_count'] ?? 0) !== $source_count
    || intval($runtime_counts['mapped_count'] ?? 0) !== $source_count) {
    runbookExportConflict('The completed runbook could not be exported because its task snapshot is incomplete or inconsistent. Contact an administrator.');
}

$task_rows = [];
$tasks = runbookExportQuery("SELECT
    tasks.task_id, task_state, task_assigned_to, task_due_at,
    CASE WHEN COALESCE(task_waiting_reason, '') <> '' THEN 1 ELSE 0 END AS task_has_waiting_reason,
    task_condition_result, task_completed_at, task_completed_by,
    owner.user_name AS owner_name, completer.user_name AS completer_name,
    runbook_version_task_id, runbook_version_task_key, runbook_version_task_name,
    runbook_version_task_order, runbook_version_task_condition_type,
    CASE WHEN COALESCE(runbook_version_task_condition_value, '') <> '' THEN 1 ELSE 0 END AS runbook_version_task_has_condition_value,
    runbook_version_task_owner_type,
    runbook_version_task_due_offset_minutes, runbook_version_task_approval_scope,
    runbook_version_task_approval_type, runbook_version_task_evidence_type,
    CASE WHEN COALESCE(runbook_version_task_evidence_prompt, '') <> '' THEN 1 ELSE 0 END AS runbook_version_task_has_evidence_prompt
    FROM runbook_version_tasks
    INNER JOIN tasks
        ON task_runbook_version_task_id = runbook_version_task_id
        AND task_ticket_id = $ticket_id
    LEFT JOIN users owner ON owner.user_id = task_assigned_to
    LEFT JOIN users completer ON completer.user_id = task_completed_by
    WHERE runbook_version_task_runbook_version_id = $version_id
    ORDER BY runbook_version_task_order, runbook_version_task_key", 'Could not load runbook closeout tasks');
while ($task = mysqli_fetch_assoc($tasks)) {
    if (!in_array($task['task_state'], ['Completed', 'Skipped'], true)
        || empty($task['task_completed_at'])) {
        runbookExportConflict('The completed runbook could not be exported because one or more tasks are not in a terminal state. Contact an administrator.');
    }
    $task_rows[] = $task;
}

$dependencies_by_key = [];
$dependencies = runbookExportQuery("SELECT child.runbook_version_task_key AS child_key,
    dependency.runbook_version_task_key AS dependency_key
    FROM runbook_version_task_dependencies
    INNER JOIN runbook_version_tasks child
        ON child.runbook_version_task_id = runbook_version_task_dependencies.runbook_version_task_id
    INNER JOIN runbook_version_tasks dependency
        ON dependency.runbook_version_task_id = runbook_version_task_dependencies.depends_on_runbook_version_task_id
    WHERE child.runbook_version_task_runbook_version_id = $version_id
    AND dependency.runbook_version_task_runbook_version_id = $version_id
    ORDER BY child.runbook_version_task_key, dependency.runbook_version_task_key", 'Could not load published runbook dependencies');
while ($dependency = mysqli_fetch_assoc($dependencies)) {
    $dependencies_by_key[$dependency['child_key']][] = $dependency['dependency_key'];
}

$evidence_by_key = [];
$evidence = runbookExportQuery("SELECT runbook_version_task_key, task_evidence_type,
    CASE
        WHEN task_evidence_type = 'note' AND COALESCE(task_evidence_note, '') <> '' THEN 1
        WHEN task_evidence_type = 'url' AND COALESCE(task_evidence_url, '') <> '' THEN 1
        ELSE 0
    END AS task_evidence_has_value,
    CASE WHEN ticket_attachment_id IS NOT NULL THEN 1 ELSE 0 END AS task_evidence_attachment_present,
    task_evidence_created_at,
    task_evidence_submitted_by, submitter.user_name AS submitter_name
    FROM task_evidence
    INNER JOIN tasks ON task_id = task_evidence_task_id
    INNER JOIN runbook_version_tasks ON runbook_version_task_id = task_runbook_version_task_id
    LEFT JOIN ticket_attachments
        ON ticket_attachment_id = task_evidence_attachment_id
        AND ticket_attachment_ticket_id = task_ticket_id
    LEFT JOIN users submitter ON submitter.user_id = task_evidence_submitted_by
    WHERE task_ticket_id = $ticket_id
    AND runbook_version_task_runbook_version_id = $version_id
    ORDER BY runbook_version_task_order, runbook_version_task_key,
        task_evidence_created_at, task_evidence_type, task_evidence_id", 'Could not load runbook evidence');
while ($item = mysqli_fetch_assoc($evidence)) {
    $evidence_by_key[$item['runbook_version_task_key']][] = $item;
}

$approvals_by_key = [];
$approvals = runbookExportQuery("SELECT runbook_version_task_key,
    approval_id AS approval_projection_key, approval_scope, approval_type,
    COALESCE(approval_required_user_id, 0) AS approval_route_user_key,
    approval_status, approval_created_by,
    CASE WHEN COALESCE(approval_approved_by, '') <> '' THEN 1 ELSE 0 END AS approval_has_decision_actor,
    CASE
        WHEN approval_approved_by REGEXP '^[0-9]+$' THEN 'agent'
        WHEN LOWER(COALESCE(approval_approved_by, '')) IN ('guest link', 'unverified guest bearer') THEN 'guest'
        WHEN COALESCE(approval_approved_by, '') <> '' THEN 'contact'
        ELSE ''
    END AS approval_decision_actor_type,
    CASE
        WHEN approval_approved_by REGEXP '^[0-9]+$' THEN CAST(approval_approved_by AS UNSIGNED)
        ELSE 0
    END AS approval_decision_actor_id,
    approval_created_at, approval_decided_at
    FROM task_approvals
    INNER JOIN tasks ON task_id = approval_task_id
    INNER JOIN runbook_version_tasks ON runbook_version_task_id = task_runbook_version_task_id
    WHERE task_ticket_id = $ticket_id
    AND runbook_version_task_runbook_version_id = $version_id
    ORDER BY runbook_version_task_order, runbook_version_task_key,
        approval_created_at, approval_scope, approval_type, approval_id", 'Could not load runbook approvals');
while ($approval = mysqli_fetch_assoc($approvals)) {
    $approvals_by_key[$approval['runbook_version_task_key']][] = $approval;
}

$approval_events_by_projection = [];
$approval_events = runbookExportQuery("SELECT
    task_approval_event_approval_id AS approval_projection_key,
    runbook_version_task_key,
    task_approval_event_action,
    task_approval_event_from_status, task_approval_event_to_status,
    task_approval_event_from_scope, task_approval_event_to_scope,
    task_approval_event_from_type, task_approval_event_to_type,
    task_approval_event_from_required_user_id,
    task_approval_event_to_required_user_id,
    task_approval_event_actor_type,
    task_approval_event_request_expires_at,
    task_approval_event_created_at
    FROM task_approval_events
    INNER JOIN task_approvals current_approval
        ON current_approval.approval_id = task_approval_event_approval_id
        AND current_approval.approval_task_id = task_approval_event_task_id
    INNER JOIN tasks ON task_id = task_approval_event_task_id
    INNER JOIN runbook_version_tasks ON runbook_version_task_id = task_runbook_version_task_id
    WHERE task_ticket_id = $ticket_id
    AND runbook_version_task_runbook_version_id = $version_id
    ORDER BY runbook_version_task_order, runbook_version_task_key,
        task_approval_event_created_at, task_approval_event_id", 'Could not load runbook approval history');
while ($approval_event = mysqli_fetch_assoc($approval_events)) {
    $projection_key = intval($approval_event['approval_projection_key']);
    unset($approval_event['approval_projection_key']);
    $approval_events_by_projection[$projection_key][] = $approval_event;
}

$state_events_by_key = [];
$state_events = runbookExportQuery("SELECT runbook_version_task_key,
    task_state_event_from_state, task_state_event_to_state,
    CASE WHEN COALESCE(task_state_event_reason, '') <> '' THEN 1 ELSE 0 END AS task_state_event_has_reason,
    task_state_event_actor_type,
    task_state_event_actor_id, task_state_event_created_at,
    state_actor.user_name AS state_actor_name
    FROM task_state_events
    INNER JOIN tasks ON task_id = task_state_event_task_id
    INNER JOIN runbook_version_tasks ON runbook_version_task_id = task_runbook_version_task_id
    LEFT JOIN users state_actor
        ON task_state_event_actor_type = 'agent'
        AND state_actor.user_id = task_state_event_actor_id
    WHERE task_ticket_id = $ticket_id
    AND runbook_version_task_runbook_version_id = $version_id
    ORDER BY runbook_version_task_order, runbook_version_task_key,
        task_state_event_created_at, task_state_event_id", 'Could not load runbook state history');
while ($event = mysqli_fetch_assoc($state_events)) {
    $state_events_by_key[$event['runbook_version_task_key']][] = $event;
}

$closeout_integrity_errors = runbookCloseoutIntegrityErrors([
    'execution' => $execution + ['published_definition' => $published_definition],
    'source_task_count' => $source_count,
    'tasks' => $task_rows,
    'evidence_by_key' => $evidence_by_key,
    'approvals_by_key' => $approvals_by_key,
    'approval_events_by_projection' => $approval_events_by_projection,
    'state_events_by_key' => $state_events_by_key,
]);
if ($closeout_integrity_errors) {
    error_log('Runbook closeout integrity rejected: ' . json_encode(array_values(array_unique(
        array_column($closeout_integrity_errors, 'code')
    ))));
    runbookExportConflict('The completed runbook could not be exported because its integrity record is incomplete or inconsistent. Contact an administrator.');
}

foreach ($task_rows as $task) {
    $task_key = (string) $task['runbook_version_task_key'];
    $task_state_events = $state_events_by_key[$task_key] ?? [];
    $final_state_event = $task_state_events
        ? $task_state_events[count($task_state_events) - 1]
        : null;
    if (!$final_state_event
        || (string) $final_state_event['task_state_event_to_state'] !== (string) $task['task_state']) {
        runbookExportConflict('The completed runbook could not be exported because its task state history is incomplete or inconsistent. Contact an administrator.');
    }

    $task_approvals = $approvals_by_key[$task_key] ?? [];
    foreach ($task_approvals as $approval_index => $approval) {
        $projection_key = intval($approval['approval_projection_key']);
        $approval_history = $approval_events_by_projection[$projection_key] ?? [];
        foreach ($approval_history as &$approval_event) {
            if ((string) ($approval_event['runbook_version_task_key'] ?? '') !== $task_key) {
                unset($approval_event);
                runbookExportConflict('The completed runbook could not be exported because an approval history event is outside its task scope. Contact an administrator.');
            }
            unset($approval_event['runbook_version_task_key']);
        }
        unset($approval_event);
        if (!runbookExportApprovalHistoryConsistent($approval, $approval_history, $task['task_state'])) {
            runbookExportConflict('The completed runbook could not be exported because its approval history is incomplete or inconsistent. Contact an administrator.');
        }
        $safe_approval_history = [];
        foreach ($approval_history as $approval_event) {
            $safe_approval_history[] = [
                'action' => runbookExportApprovalEventAction($approval_event),
                'from_status' => runbookExportApprovalStatus($approval_event['task_approval_event_from_status']),
                'to_status' => runbookExportApprovalStatus($approval_event['task_approval_event_to_status']),
                'from_route' => runbookExportApprovalRoute(
                    $approval_event['task_approval_event_from_scope'],
                    $approval_event['task_approval_event_from_type'],
                    $approval_event['task_approval_event_from_required_user_id']
                ),
                'to_route' => runbookExportApprovalRoute(
                    $approval_event['task_approval_event_to_scope'],
                    $approval_event['task_approval_event_to_type'],
                    $approval_event['task_approval_event_to_required_user_id']
                ),
                'actor_type' => runbookExportApprovalEventActorType($approval_event),
                'actor_label' => runbookExportApprovalEventActorLabel($approval_event),
                'request_expires_at' => (string) ($approval_event['task_approval_event_request_expires_at'] ?? ''),
                'created_at' => (string) $approval_event['task_approval_event_created_at'],
            ];
        }
        $approval['approval_history'] = $safe_approval_history;
        unset(
            $approval['approval_projection_key'],
            $approval['approval_route_user_key'],
            $approval['approval_created_by'],
            $approval['approval_decision_actor_id']
        );
        $task_approvals[$approval_index] = $approval;
    }
    $approvals_by_key[$task_key] = $task_approvals;

    if ($task['task_state'] === 'Completed') {
        $task_evidence = $evidence_by_key[$task_key] ?? [];
        $required_evidence = (string) $task['runbook_version_task_evidence_type'];
        if ($required_evidence !== 'none' && $required_evidence !== '') {
            $evidence_satisfied = false;
            foreach ($task_evidence as $item) {
                if (runbookExportEvidenceQualifies($item, $required_evidence)) {
                    $evidence_satisfied = true;
                    break;
                }
            }
            if (!$evidence_satisfied) {
                runbookExportConflict('The completed runbook could not be exported because required task evidence is incomplete. Contact an administrator.');
            }
        }

        $approval_required = trim((string) $task['runbook_version_task_approval_scope']) !== '';
        if (($approval_required && count($task_approvals) !== 1)
            || (!$approval_required && count($task_approvals) !== 0)) {
            runbookExportConflict('The completed runbook could not be exported because its approval record set is incomplete or inconsistent. Contact an administrator.');
        }
        foreach ($task_approvals as $approval) {
            if ($approval['approval_status'] !== 'approved'
                || intval($approval['approval_has_decision_actor']) !== 1
                || empty($approval['approval_decided_at'])) {
                runbookExportConflict('The completed runbook could not be exported because a required approval lacks a complete decision record. Contact an administrator.');
            }
        }
    }
}

if (!mysqli_commit($mysqli)) {
    error_log('Could not commit runbook closeout snapshot: ' . mysqli_error($mysqli));
    runbookExportServerError();
}

$ticket_label = trim((string) $execution['ticket_prefix'] . (string) $execution['ticket_number']);
$completed_count = count(array_filter($task_rows, static function ($task) {
    return $task['task_state'] === 'Completed';
}));
$skipped_count = count($task_rows) - $completed_count;
$timezone = date_default_timezone_get();

$lines = [
    '# Runbook Closeout — ' . runbookExportText($execution['runbook_version_name']),
    '',
    '## Closeout Summary',
    '',
    '- Client: ' . runbookExportText($execution['client_name'] ?: 'Unassigned'),
    '- Ticket: ' . runbookExportText($ticket_label) . ' (subject retained in ITFlow)',
    '- Runbook key: ' . runbookExportCode($execution['runbook_version_key']),
    '- Published version: v' . intval($execution['runbook_version_number']),
    '- Runbook type: ' . runbookExportText($execution['runbook_version_type']),
    '- Execution status: Completed',
    '- Started: ' . runbookExportText($execution['runbook_execution_started_at']),
    '- Completed: ' . runbookExportText($execution['runbook_execution_completed_at']),
    '- Timestamp timezone: ' . runbookExportText($timezone),
    '- Task outcome: ' . count($task_rows) . ' total; ' . $completed_count
        . ' completed; ' . $skipped_count . ' skipped',
    '- Integrity verification: Passed — definition, task mapping, terminal states, required evidence, approval projections/history, and final transition records validated',
    '',
    '> Confidentiality and redaction: This closeout intentionally omits internal database identifiers, hashes and bearer credentials, ticket subjects, condition values, operational and approval reason text, stored actor labels, URL details, evidence note bodies, attachment filenames, and file contents. Approval routes and actors use generic identity classes. Remaining client-facing labels are defensively screened. Evidence references identify records retained in ITFlow; underlying material should be retrieved only through authorized access.',
    '',
    '## Task Outcomes',
    '',
];

$condition_labels = runbookConditionTypes();
$owner_labels = runbookOwnerTypes();
$evidence_labels = runbookEvidenceTypes();

foreach ($task_rows as $task) {
    $task_key = (string) $task['runbook_version_task_key'];
    $dependency_keys = $dependencies_by_key[$task_key] ?? [];
    $dependency_text = $dependency_keys
        ? implode(', ', array_map('runbookExportCode', $dependency_keys))
        : 'None';
    $condition_type = (string) $task['runbook_version_task_condition_type'];
    $condition_text = $condition_labels[$condition_type] ?? $condition_type;
    $owner_type = (string) $task['runbook_version_task_owner_type'];
    $owner_rule = $owner_labels[$owner_type] ?? $owner_type;
    $runtime_owner = !empty($task['owner_name'])
        ? runbookExportText($task['owner_name'])
        : (intval($task['task_assigned_to']) > 0 ? 'Assigned agent (name unavailable)' : 'Unassigned');
    if (!empty($task['completer_name'])) {
        $completion_actor = runbookExportText($task['completer_name']);
    } elseif (intval($task['task_completed_by']) > 0) {
        $completion_actor = 'Internal agent (name unavailable)';
    } elseif ($task['task_state'] === 'Skipped') {
        $completion_actor = 'Automated applicability evaluation';
    } else {
        $completion_actor = 'System (actor unavailable)';
    }
    $published_approval = !empty($task['runbook_version_task_approval_scope'])
        ? runbookExportText($task['runbook_version_task_approval_scope'] . ' / '
            . $task['runbook_version_task_approval_type'])
        : 'None';
    $evidence_type = (string) $task['runbook_version_task_evidence_type'];

    $lines[] = '### ' . runbookExportCode($task_key) . ' — '
        . runbookExportText($task['runbook_version_task_name']);
    $lines[] = '';
    $lines[] = '- Published dependencies: ' . $dependency_text;
    $lines[] = '- Published condition: ' . runbookExportText($condition_text);
    if (intval($task['runbook_version_task_has_condition_value']) === 1) {
        $lines[] = '- Published condition parameter: Configured; detail retained in the immutable definition';
    }
    $lines[] = '- Runtime applicability: ' . runbookExportText($task['task_condition_result']);
    $lines[] = '- Runtime state: ' . runbookExportText($task['task_state']);
    $lines[] = '- Waiting/skip reason: ' . (intval($task['task_has_waiting_reason']) === 1
        ? 'Recorded in ITFlow; detail omitted from client export' : 'None');
    $lines[] = '- Published owner rule: ' . runbookExportText($owner_rule);
    $lines[] = '- Runtime owner: ' . $runtime_owner;
    $lines[] = '- Due: ' . runbookExportText($task['task_due_at']);
    $lines[] = '- Completed/skipped by: ' . $completion_actor;
    $lines[] = '- Completed/skipped at: ' . runbookExportText($task['task_completed_at']);
    $lines[] = '- Published approval rule: ' . $published_approval;
    $lines[] = '- Published evidence rule: '
        . runbookExportText($evidence_labels[$evidence_type] ?? $evidence_type);
    if (intval($task['runbook_version_task_has_evidence_prompt']) === 1) {
        $lines[] = '- Published evidence prompt: Configured; detail retained in the immutable definition';
    }

    $task_evidence = $evidence_by_key[$task_key] ?? [];
    if (!$task_evidence) {
        $lines[] = '- Evidence records: None';
    } else {
        foreach ($task_evidence as $index => $item) {
            $submitter = !empty($item['submitter_name'])
                ? runbookExportText($item['submitter_name'])
                : (intval($item['task_evidence_submitted_by']) > 0
                    ? 'Internal agent (name unavailable)' : 'System or external workflow');
            $lines[] = '- Evidence ' . ($index + 1)
                . ' — type: ' . runbookExportText($item['task_evidence_type'])
                . '; reference: ' . runbookExportEvidenceReference($item)
                . '; submitted by: ' . $submitter
                . '; submitted at: ' . runbookExportText($item['task_evidence_created_at']);
        }
    }

    $task_approvals = $approvals_by_key[$task_key] ?? [];
    if (!$task_approvals) {
        $lines[] = '- Approval decisions: None recorded';
    } else {
        foreach ($task_approvals as $index => $approval) {
            $approval_decision = runbookExportText($approval['approval_status']);
            $approval_actor = runbookExportDecisionActor($approval);
            $approval_decided_at = runbookExportText($approval['approval_decided_at']);
            if ($task['task_state'] === 'Skipped'
                && $approval['approval_status'] !== 'approved') {
                $approval_decision = 'Waived by audited task skip (original status: '
                    . runbookExportText($approval['approval_status']) . ')';
                $approval_actor = $completion_actor . ' (task skip actor)';
                $approval_decided_at = runbookExportText($task['task_completed_at'])
                    . ' (waiver timestamp)';
            }
            $lines[] = '- Approval ' . ($index + 1)
                . ' — scope: ' . runbookExportText($approval['approval_scope'])
                . '; type: ' . runbookExportText($approval['approval_type'])
                . '; recipient: ' . runbookExportApprovalRecipient($approval)
                . '; decision: ' . $approval_decision
                . '; decision actor: ' . $approval_actor
                . '; requested at: ' . runbookExportText($approval['approval_created_at'])
                . '; decided at: ' . $approval_decided_at;
            foreach ($approval['approval_history'] as $history_index => $history_event) {
                $history_line = '  - Approval history ' . ($history_index + 1)
                    . ' — action: ' . $history_event['action']
                    . '; status: ' . $history_event['from_status'] . ' → ' . $history_event['to_status']
                    . '; route: ' . $history_event['from_route'] . ' → ' . $history_event['to_route']
                    . '; actor type: ' . $history_event['actor_type']
                    . '; actor: ' . $history_event['actor_label']
                    . '; at: ' . runbookExportText($history_event['created_at']);
                if ($history_event['request_expires_at'] !== '') {
                    $history_line .= '; request expires: '
                        . runbookExportText($history_event['request_expires_at']);
                }
                $lines[] = $history_line;
            }
        }
    }

    $task_state_events = $state_events_by_key[$task_key] ?? [];
    if (!$task_state_events) {
        $lines[] = '- State history: No transition events recorded';
    } else {
        foreach ($task_state_events as $index => $event) {
            $from_state = trim((string) $event['task_state_event_from_state']) === ''
                ? 'Created' : runbookExportText($event['task_state_event_from_state']);
            $lines[] = '- State transition ' . ($index + 1)
                . ' — ' . $from_state . ' → ' . runbookExportText($event['task_state_event_to_state'])
                . '; actor: ' . runbookExportStateActor($event)
                . '; at: ' . runbookExportText($event['task_state_event_created_at'])
                . '; reason: ' . (intval($event['task_state_event_has_reason']) === 1
                    ? 'Recorded in ITFlow; detail omitted from client export' : 'None');
        }
    }
    $lines[] = '';
}

$filename_base = runbookNormalizeKey(runbookExportRedactSecrets(
    ($execution['client_name'] ?: 'client') . '-' . $execution['runbook_version_key']
), 'runbook-closeout');
$ticket_filename = runbookNormalizeKey(runbookExportRedactSecrets($ticket_label), 'ticket');
$filename = $filename_base . '-v' . intval($execution['runbook_version_number'])
    . '-' . $ticket_filename . '-closeout.md';

logAudit(
    'Runbook',
    'Export',
    escapeSql($session_name . ' exported completed runbook closeout for '
        . runbookExportRedactSecrets($ticket_label)),
    $client_id,
    $ticket_id
);

header('Content-Type: text/markdown; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
echo implode("\n", $lines) . "\n";
