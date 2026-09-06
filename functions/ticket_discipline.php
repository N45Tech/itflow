<?php

/*
 * Structured ticket operations for N45.
 *
 * These helpers keep operational fields, work notes, handoffs, promises,
 * relationships, and terminal evidence consistent across the agent, portal,
 * API, automation, and cron paths.
 */

function ticketWorkTypeDefinitions(): array
{
    return [
        'incident' => 'Incident',
        'request' => 'Service request',
        'problem' => 'Problem investigation',
        'change' => 'Change',
        'onboarding' => 'Onboarding / offboarding',
        'project_task' => 'Project task',
    ];
}

function ticketImpactDefinitions(): array
{
    return [
        'low' => 'One person or a noncritical function',
        'medium' => 'Several people or a degraded important service',
        'high' => 'Most users, a critical service, security, or material data risk',
    ];
}

function ticketUrgencyDefinitions(): array
{
    return [
        'low' => 'Can be planned',
        'medium' => 'Needs attention during normal operations',
        'high' => 'Work is stopped or immediate action is required',
    ];
}

function ticketWaitingOnDefinitions(): array
{
    return [
        'none' => 'No external blocker',
        'client' => 'Client',
        'vendor' => 'Vendor',
        'internal' => 'N45 / another technician',
        'scheduled' => 'Scheduled date or change window',
    ];
}

function ticketDisciplineStatusForWaitingOn(string $waiting_on): ?int
{
    global $mysqli;

    $status_names = [
        'client' => 'Waiting on Client',
        'vendor' => 'Waiting on Vendor',
        'scheduled' => 'Scheduled',
    ];
    if (!isset($status_names[$waiting_on])) {
        return null;
    }
    $status_name_sql = mysqli_real_escape_string($mysqli, $status_names[$waiting_on]);
    $row = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_status_id
        FROM ticket_statuses WHERE ticket_status_name = '$status_name_sql'
        AND ticket_status_active = 1 LIMIT 1",
        'Could not resolve the waiting ticket status'));
    if (!$row) {
        throw new DomainException('The matching waiting status is not configured.');
    }
    return intval($row['ticket_status_id']);
}

function ticketResolutionCodeDefinitions(bool $include_system = false): array
{
    $definitions = [
        'fixed' => 'Fixed',
        'workaround' => 'Workaround provided',
        'configuration_changed' => 'Configuration changed',
        'access_restored' => 'Access restored',
        'request_fulfilled' => 'Request fulfilled',
        'no_issue_found' => 'No issue found',
        'duplicate' => 'Duplicate',
        'client_confirmed' => 'Client confirmed completion',
        'cancelled' => 'Cancelled',
    ];
    if ($include_system) {
        $definitions += [
            'monitor_recovered' => 'Monitoring confirmed recovery',
            'project_completed' => 'Project completed',
            'legacy_completed' => 'Completed before structured tracking',
        ];
    }
    return $definitions;
}

function ticketClosureCodeDefinitions(bool $include_system = false): array
{
    $definitions = [
        'completed' => 'Completed and documented',
        'validated' => 'Validated by technician',
        'client_confirmed' => 'Confirmed by client',
    ];
    if ($include_system) {
        $definitions += [
            'auto_closed' => 'Automatically closed after resolution window',
            'api_closed' => 'Closed through API',
            'merged' => 'Merged into another ticket',
            'cancelled' => 'Cancelled',
            'legacy_closed' => 'Closed before structured tracking',
        ];
    }
    return $definitions;
}

function ticketRelationshipDefinitions(): array
{
    return [
        'related' => 'Related ticket',
        'parent' => 'Selected ticket is the parent',
        'child' => 'Selected ticket is a child',
        'duplicate' => 'This ticket duplicates the selected ticket',
    ];
}

function ticketDisciplineDbQuery(string $query, string $message)
{
    global $mysqli;

    $result = mysqli_query($mysqli, $query);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function ticketDisciplineText($value, string $label, int $minimum, int $maximum, bool $required = true): string
{
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
    $length = mb_strlen($value);
    if (($required && $length < $minimum) || $length > $maximum) {
        $requirement = $required
            ? "between $minimum and $maximum characters"
            : "no more than $maximum characters";
        throw new DomainException("$label must be $requirement.");
    }
    return $value;
}

function ticketDisciplineDateTime($value, bool $required = false): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        if ($required) {
            throw new DomainException('Choose a due date and time.');
        }
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value)
        ?: DateTime::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
        throw new DomainException('Choose a valid due date and time.');
    }
    return $date->format('Y-m-d H:i:s');
}

function ticketDisciplineFutureDateTime($value, bool $required = false): ?string
{
    $date = ticketDisciplineDateTime($value, $required);
    if ($date !== null && strtotime($date) <= time()) {
        throw new DomainException('Choose a future due date and time.');
    }
    return $date;
}

function ticketPriorityFromImpactUrgency(string $impact, string $urgency): string
{
    if (!isset(ticketImpactDefinitions()[$impact]) || !isset(ticketUrgencyDefinitions()[$urgency])) {
        throw new DomainException('Choose a valid impact and urgency.');
    }
    $scores = ['low' => 1, 'medium' => 2, 'high' => 3];
    $score = $scores[$impact] + $scores[$urgency];
    if ($score === 6) {
        return 'Urgent';
    }
    if ($score === 5) {
        return 'High';
    }
    if ($score >= 3) {
        return 'Medium';
    }
    return 'Low';
}

function ticketDisciplineLegacyAssessment(string $priority, string $work_type = 'incident'): array
{
    $priority = ucfirst(strtolower(trim($priority)));
    $mapping = [
        'Low' => ['low', 'low'],
        'Medium' => ['medium', 'medium'],
        'High' => ['high', 'medium'],
        'Urgent' => ['high', 'high'],
    ];
    if (!isset($mapping[$priority])) {
        $priority = 'Medium';
    }
    if (!isset(ticketWorkTypeDefinitions()[$work_type])) {
        $work_type = 'incident';
    }
    [$impact, $urgency] = $mapping[$priority];
    return [
        'work_type' => $work_type,
        'impact' => $impact,
        'urgency' => $urgency,
        'priority' => $priority,
        'waiting_on' => 'none',
        'next_action' => '',
        'next_action_due_at' => null,
    ];
}

function ticketDisciplineAssessmentInput(array $input): array
{
    $work_type = strtolower(trim((string) ($input['work_type'] ?? '')));
    $impact = strtolower(trim((string) ($input['impact'] ?? '')));
    $urgency = strtolower(trim((string) ($input['urgency'] ?? '')));
    $waiting_on = strtolower(trim((string) ($input['waiting_on'] ?? 'none')));
    if (!isset(ticketWorkTypeDefinitions()[$work_type])) {
        throw new DomainException('Choose a valid work type.');
    }
    if (!isset(ticketImpactDefinitions()[$impact]) || !isset(ticketUrgencyDefinitions()[$urgency])) {
        throw new DomainException('Choose a valid impact and urgency.');
    }
    if (!isset(ticketWaitingOnDefinitions()[$waiting_on])) {
        throw new DomainException('Choose who or what the ticket is waiting on.');
    }
    $next_action = ticketDisciplineText(
        $input['next_action'] ?? '',
        'Next action',
        2,
        500,
        $waiting_on !== 'none'
    );
    $next_action_due_at = ticketDisciplineFutureDateTime(
        $input['next_action_due_at'] ?? '',
        $waiting_on !== 'none'
    );

    return [
        'work_type' => $work_type,
        'impact' => $impact,
        'urgency' => $urgency,
        'priority' => ticketPriorityFromImpactUrgency($impact, $urgency),
        'waiting_on' => $waiting_on,
        'next_action' => $next_action,
        'next_action_due_at' => $next_action_due_at,
    ];
}

function ticketDisciplineUpdatePlan(int $ticket_id, array $input, int $actor_id): array
{
    global $mysqli;

    $values = ticketDisciplineAssessmentInput($input);
    $ticket_id = intval($ticket_id);
    $actor_id = max(0, intval($actor_id));
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_client_id,
        ticket_status, ticket_archived_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not load the ticket operations plan'));
    if (!$ticket || !empty($ticket['ticket_archived_at']) || intval($ticket['ticket_status']) === 5) {
        throw new DomainException('This ticket no longer accepts operational changes.');
    }
    $client_id = intval($ticket['ticket_client_id']);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket operations transaction');
        }
        $transaction_started = true;
        documentationLockClientTicket($ticket_id, $client_id);
        $locked = runbookLockTicketForTransition($ticket_id, true);
        if (intval($locked['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket client changed while its operations plan was saved');
        }

        $work_type_sql = mysqli_real_escape_string($mysqli, $values['work_type']);
        $impact_sql = mysqli_real_escape_string($mysqli, $values['impact']);
        $urgency_sql = mysqli_real_escape_string($mysqli, $values['urgency']);
        $priority_sql = mysqli_real_escape_string($mysqli, $values['priority']);
        $waiting_on_sql = mysqli_real_escape_string($mysqli, $values['waiting_on']);
        $next_action_sql = $values['next_action'] === ''
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, $values['next_action']) . "'";
        $due_sql = $values['next_action_due_at'] === null
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, $values['next_action_due_at']) . "'";
        ticketDisciplineDbQuery("UPDATE tickets SET
            ticket_work_type = '$work_type_sql', ticket_impact = '$impact_sql',
            ticket_urgency = '$urgency_sql', ticket_priority = '$priority_sql',
            ticket_waiting_on = '$waiting_on_sql', ticket_next_action = $next_action_sql,
            ticket_next_action_due_at = $due_sql, ticket_updated_at = NOW()
            WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL",
            'Could not save the ticket operations plan');
        $waiting_status = ticketDisciplineStatusForWaitingOn($values['waiting_on']);
        if ($waiting_status !== null && intval($locked['ticket_status']) !== 4) {
            ticketDisciplineDbQuery("UPDATE tickets SET ticket_status = $waiting_status
                WHERE ticket_id = $ticket_id", 'Could not update the waiting status');
        }
        applyTicketSla($ticket_id, null, null, true);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket operations plan');
        }
        $transaction_started = false;
        return $values;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $exception;
    }
}

// Check under the ticket transition lock so a concurrent writer cannot add
// client-bound history between this decision and the client update.
function ticketDisciplineCanTransfer(int $ticket_id): array
{
    $history = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT
        EXISTS (SELECT 1 FROM ticket_work_notes
            WHERE ticket_work_note_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_handoffs
            WHERE ticket_handoff_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_relationships
            WHERE ticket_relationship_from_ticket_id = $ticket_id
                OR ticket_relationship_to_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_customer_promises
            WHERE ticket_customer_promise_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_customer_promise_events
            WHERE ticket_customer_promise_event_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_resolution_events
            WHERE ticket_resolution_event_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_deletion_events
            WHERE ticket_deletion_event_ticket_id = $ticket_id) AS has_history",
        'Could not validate the ticket operational history'));
    if (intval($history['has_history'] ?? 0)) {
        return [false, 'This ticket has operational or deletion history tied to its current client and cannot be transferred.'];
    }
    return [true, ''];
}

function ticketDisciplineResolutionInput($code, $summary, $root_cause, string $work_type = 'incident', bool $allow_system = false): array
{
    $code = strtolower(trim((string) $code));
    if (!isset(ticketResolutionCodeDefinitions($allow_system)[$code])) {
        throw new DomainException('Choose a valid resolution code.');
    }
    $summary = ticketDisciplineText($summary, 'Resolution summary', 5, 2000);
    $root_cause = ticketDisciplineText($root_cause, 'Root cause', 5, 2000, false);
    if ($work_type === 'problem' && $root_cause === '') {
        throw new DomainException('Problem investigations require a recorded root cause.');
    }
    return ['code' => $code, 'summary' => $summary, 'root_cause' => $root_cause];
}

/** Caller owns the ticket lock and transaction. */
function ticketDisciplineStoreResolution(int $ticket_id, $code, $summary, $root_cause = '', bool $allow_system = false): array
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_work_type, ticket_archived_at
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not load the ticket work type for resolution'));
    if (!$ticket || !empty($ticket['ticket_archived_at'])) {
        throw new RuntimeException('The ticket is unavailable');
    }
    $values = ticketDisciplineResolutionInput(
        $code,
        $summary,
        $root_cause,
        (string) $ticket['ticket_work_type'],
        $allow_system
    );
    $code_sql = mysqli_real_escape_string($mysqli, $values['code']);
    $summary_sql = mysqli_real_escape_string($mysqli, $values['summary']);
    $root_sql = $values['root_cause'] === ''
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $values['root_cause']) . "'";
    ticketDisciplineDbQuery("UPDATE tickets SET
        ticket_resolution_code = '$code_sql',
        ticket_resolution_summary = '$summary_sql',
        ticket_root_cause = $root_sql,
        ticket_waiting_on = 'none', ticket_next_action = NULL,
        ticket_next_action_due_at = NULL
        WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL",
        'Could not record the ticket resolution');
    return $values;
}

function ticketDisciplineClosureCode($code, bool $allow_system = false): string
{
    $code = strtolower(trim((string) $code));
    $definitions = ticketClosureCodeDefinitions($allow_system);
    if (!isset($definitions[$code])) {
        throw new DomainException('Choose a valid closure code.');
    }
    return $code;
}

/** Caller owns the ticket lock and transaction. */
function ticketDisciplineStoreClosure(int $ticket_id, $closure_code, bool $allow_system = false): string
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $closure_code = ticketDisciplineClosureCode($closure_code, $allow_system);
    $closure_sql = mysqli_real_escape_string($mysqli, $closure_code);
    ticketDisciplineDbQuery("UPDATE tickets SET ticket_closure_code = '$closure_sql'
        WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL",
        'Could not record the ticket closure code');
    return $closure_code;
}

function ticketDisciplineRecordResolutionEvent(
    int $ticket_id,
    string $action,
    string $actor_type,
    int $actor_id
): int {
    global $mysqli;

    if (!in_array($action, ['resolved', 'closed', 'cancelled', 'reopened'], true)) {
        throw new InvalidArgumentException('Unsupported ticket resolution event');
    }
    if (!in_array($actor_type, ['agent', 'contact', 'guest', 'api', 'system'], true)) {
        throw new InvalidArgumentException('Unsupported ticket resolution actor');
    }
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_client_id,
        ticket_resolution_code, ticket_resolution_summary, ticket_root_cause,
        ticket_closure_code FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not load ticket resolution evidence'));
    if (!$ticket) {
        throw new RuntimeException('The ticket no longer exists');
    }
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $actor_type_sql = mysqli_real_escape_string($mysqli, $actor_type);
    $code_sql = empty($ticket['ticket_resolution_code'])
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $ticket['ticket_resolution_code']) . "'";
    $summary_sql = empty($ticket['ticket_resolution_summary'])
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $ticket['ticket_resolution_summary']) . "'";
    $root_sql = empty($ticket['ticket_root_cause'])
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $ticket['ticket_root_cause']) . "'";
    $closure_sql = empty($ticket['ticket_closure_code'])
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $ticket['ticket_closure_code']) . "'";
    ticketDisciplineDbQuery("INSERT INTO ticket_resolution_events SET
        ticket_resolution_event_ticket_id = $ticket_id,
        ticket_resolution_event_client_id = " . intval($ticket['ticket_client_id']) . ",
        ticket_resolution_event_action = '$action_sql',
        ticket_resolution_event_resolution_code = $code_sql,
        ticket_resolution_event_resolution_summary = $summary_sql,
        ticket_resolution_event_root_cause = $root_sql,
        ticket_resolution_event_closure_code = $closure_sql,
        ticket_resolution_event_actor_type = '$actor_type_sql',
        ticket_resolution_event_actor_id = " . max(0, intval($actor_id)),
        'Could not record ticket resolution history');
    return intval(mysqli_insert_id($mysqli));
}

/** Caller owns the ticket lock and transaction. */
function ticketDisciplineClearResolutionForReopen(int $ticket_id, string $actor_type, int $actor_id): void
{
    global $mysqli;

    ticketDisciplineRecordResolutionEvent($ticket_id, 'reopened', $actor_type, $actor_id);
    ticketDisciplineDbQuery("UPDATE tickets SET ticket_resolution_code = NULL,
        ticket_resolution_summary = NULL, ticket_root_cause = NULL,
        ticket_closure_code = NULL WHERE ticket_id = " . intval($ticket_id),
        'Could not clear the current resolution after reopen');
}

function ticketDisciplineCanResolve(int $ticket_id, bool $include_detail = false): array
{
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_work_type,
        ticket_resolution_code, ticket_resolution_summary, ticket_root_cause,
        ticket_archived_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not inspect the ticket resolution record'));
    if (!$ticket || !empty($ticket['ticket_archived_at'])) {
        return [false, 'The ticket is unavailable.'];
    }
    try {
        ticketDisciplineResolutionInput(
            $ticket['ticket_resolution_code'] ?? '',
            $ticket['ticket_resolution_summary'] ?? '',
            $ticket['ticket_root_cause'] ?? '',
            (string) ($ticket['ticket_work_type'] ?? 'incident'),
            true
        );
    } catch (DomainException $exception) {
        return [false, $include_detail
            ? $exception->getMessage()
            : 'A technician must record the resolution before this ticket can be completed.'];
    }

    $open_promises = intval(mysqli_fetch_row(ticketDisciplineDbQuery("SELECT COUNT(*)
        FROM ticket_customer_promises WHERE ticket_customer_promise_ticket_id = $ticket_id
        AND ticket_customer_promise_status = 'open'",
        'Could not inspect ticket customer promises'))[0] ?? 0);
    if ($open_promises) {
        return [false, $include_detail
            ? "$open_promises customer promise(s) must be fulfilled or cancelled first."
            : 'A technician must complete the outstanding customer commitment before this ticket can be completed.'];
    }
    return [true, ''];
}

function ticketDisciplineWorkNoteInput(array $input): array
{
    $waiting_on = strtolower(trim((string) ($input['work_waiting_on'] ?? 'none')));
    if (!isset(ticketWaitingOnDefinitions()[$waiting_on])) {
        throw new DomainException('Choose a valid work-note dependency.');
    }
    $next_step = ticketDisciplineText($input['work_next_step'] ?? '', 'Next step', 2, 500);
    $due_at = ticketDisciplineFutureDateTime(
        $input['work_next_action_due_at'] ?? '',
        $waiting_on !== 'none'
    );
    $promise_summary = ticketDisciplineText(
        $input['customer_promise_summary'] ?? '',
        'Customer promise',
        5,
        500,
        false
    );
    $promise_due_at = ticketDisciplineFutureDateTime(
        $input['customer_promise_due_at'] ?? '',
        $promise_summary !== ''
    );
    if ($promise_due_at !== null && $promise_summary === '') {
        throw new DomainException('Describe the customer promise that has a due date.');
    }

    return [
        'action' => ticketDisciplineText($input['work_action'] ?? '', 'Action taken', 2, 500),
        'result' => ticketDisciplineText($input['work_result'] ?? '', 'Result', 2, 500),
        'next_step' => $next_step,
        'blocking_dependency' => ticketDisciplineText(
            $input['work_blocking_dependency'] ?? '',
            'Blocking dependency',
            2,
            500,
            false
        ),
        'waiting_on' => $waiting_on,
        'next_action_due_at' => $due_at,
        'customer_promise_summary' => $promise_summary,
        'customer_promise_due_at' => $promise_due_at,
    ];
}

function ticketDisciplineWorkNoteHtml(array $note): string
{
    $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div class="ticket-structured-note">'
        . '<strong>Action:</strong> ' . $escape($note['action']) . '<br>'
        . '<strong>Result:</strong> ' . $escape($note['result']) . '<br>'
        . '<strong>Next step:</strong> ' . $escape($note['next_step']);
    if ($note['blocking_dependency'] !== '') {
        $html .= '<br><strong>Blocking dependency:</strong> ' . $escape($note['blocking_dependency']);
    }
    if ($note['waiting_on'] !== 'none') {
        $html .= '<br><strong>Waiting on:</strong> '
            . $escape(ticketWaitingOnDefinitions()[$note['waiting_on']]);
    }
    if ($note['next_action_due_at'] !== null) {
        $html .= '<br><strong>Next action due:</strong> ' . $escape($note['next_action_due_at']);
    }
    if ($note['customer_promise_summary'] !== '') {
        $html .= '<br><strong>Customer promise:</strong> '
            . $escape($note['customer_promise_summary']) . ' by '
            . $escape($note['customer_promise_due_at']);
    }
    return $html . '</div>';
}

function ticketDisciplineRecordPromiseEvent(
    int $promise_id,
    int $ticket_id,
    string $action,
    ?string $from_status,
    string $to_status,
    int $actor_id,
    string $reason
): void {
    global $mysqli;

    if (!in_array($action, ['created', 'fulfilled', 'cancelled'], true)
        || !in_array($to_status, ['open', 'fulfilled', 'cancelled'], true)) {
        throw new InvalidArgumentException('Unsupported customer-promise event');
    }
    $reason = ticketDisciplineText($reason, 'Promise event reason', 2, 500);
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $from_sql = $from_status === null
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $from_status) . "'";
    $to_sql = mysqli_real_escape_string($mysqli, $to_status);
    $reason_sql = mysqli_real_escape_string($mysqli, $reason);
    ticketDisciplineDbQuery("INSERT INTO ticket_customer_promise_events SET
        ticket_customer_promise_event_promise_id = " . intval($promise_id) . ",
        ticket_customer_promise_event_ticket_id = " . intval($ticket_id) . ",
        ticket_customer_promise_event_action = '$action_sql',
        ticket_customer_promise_event_from_status = $from_sql,
        ticket_customer_promise_event_to_status = '$to_sql',
        ticket_customer_promise_event_actor_id = " . max(0, intval($actor_id)) . ",
        ticket_customer_promise_event_reason = '$reason_sql'",
        'Could not record customer-promise history');
}

/** Caller owns the ticket lock and transaction. */
function ticketDisciplineSaveWorkNote(
    int $ticket_id,
    int $reply_id,
    array $note,
    int $actor_id
): int {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $reply_id = max(0, intval($reply_id));
    $actor_id = max(0, intval($actor_id));
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_client_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1",
        'Could not load the work-note ticket'));
    if (!$ticket) {
        throw new RuntimeException('The work-note ticket is unavailable');
    }
    $values = ticketDisciplineWorkNoteInput($note);
    $client_id = intval($ticket['ticket_client_id']);
    $sql_value = static function ($value) use ($mysqli): string {
        return $value === '' || $value === null
            ? 'NULL'
            : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $waiting_on_sql = mysqli_real_escape_string($mysqli, $values['waiting_on']);
    ticketDisciplineDbQuery("INSERT INTO ticket_work_notes SET
        ticket_work_note_ticket_id = $ticket_id,
        ticket_work_note_reply_id = $reply_id,
        ticket_work_note_client_id = $client_id,
        ticket_work_note_action = " . $sql_value($values['action']) . ",
        ticket_work_note_result = " . $sql_value($values['result']) . ",
        ticket_work_note_next_step = " . $sql_value($values['next_step']) . ",
        ticket_work_note_blocking_dependency = " . $sql_value($values['blocking_dependency']) . ",
        ticket_work_note_waiting_on = '$waiting_on_sql',
        ticket_work_note_next_action_due_at = " . $sql_value($values['next_action_due_at']) . ",
        ticket_work_note_actor_id = $actor_id",
        'Could not save the structured work note');
    $work_note_id = intval(mysqli_insert_id($mysqli));

    ticketDisciplineDbQuery("UPDATE tickets SET
        ticket_waiting_on = '$waiting_on_sql',
        ticket_next_action = " . $sql_value($values['next_step']) . ",
        ticket_next_action_due_at = " . $sql_value($values['next_action_due_at']) . ",
        ticket_updated_at = NOW() WHERE ticket_id = $ticket_id",
        'Could not update the ticket next action from its work note');

    if ($values['customer_promise_summary'] !== '') {
        ticketDisciplineDbQuery("INSERT INTO ticket_customer_promises SET
            ticket_customer_promise_ticket_id = $ticket_id,
            ticket_customer_promise_client_id = $client_id,
            ticket_customer_promise_summary = " . $sql_value($values['customer_promise_summary']) . ",
            ticket_customer_promise_due_at = " . $sql_value($values['customer_promise_due_at']) . ",
            ticket_customer_promise_status = 'open',
            ticket_customer_promise_created_by = $actor_id",
            'Could not record the customer promise');
        $promise_id = intval(mysqli_insert_id($mysqli));
        ticketDisciplineRecordPromiseEvent(
            $promise_id,
            $ticket_id,
            'created',
            null,
            'open',
            $actor_id,
            $values['customer_promise_summary']
        );
    }
    return $work_note_id;
}

function ticketDisciplineCompletePromise(
    int $promise_id,
    string $action,
    int $actor_id,
    string $reason
): array {
    global $mysqli;

    if (!in_array($action, ['fulfilled', 'cancelled'], true)) {
        throw new DomainException('Choose whether the customer promise was fulfilled or cancelled.');
    }
    $reason = ticketDisciplineText($reason, 'Promise completion note', 3, 500);
    $promise_id = intval($promise_id);
    $prelock = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT
        ticket_customer_promise_ticket_id, ticket_customer_promise_client_id
        FROM ticket_customer_promises WHERE ticket_customer_promise_id = $promise_id LIMIT 1",
        'Could not locate the customer promise'));
    if (!$prelock) {
        throw new DomainException('The customer promise is unavailable.');
    }
    $ticket_id = intval($prelock['ticket_customer_promise_ticket_id']);
    $client_id = intval($prelock['ticket_customer_promise_client_id']);
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the customer-promise transaction');
        }
        $transaction_started = true;
        documentationLockClientTicket($ticket_id, $client_id);
        $promise = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT *
            FROM ticket_customer_promises WHERE ticket_customer_promise_id = $promise_id
            AND ticket_customer_promise_ticket_id = $ticket_id FOR UPDATE",
            'Could not lock the customer promise'));
        if (!$promise || $promise['ticket_customer_promise_status'] !== 'open') {
            throw new DomainException('The customer promise is no longer open.');
        }
        $action_sql = mysqli_real_escape_string($mysqli, $action);
        ticketDisciplineDbQuery("UPDATE ticket_customer_promises SET
            ticket_customer_promise_status = '$action_sql',
            ticket_customer_promise_completed_by = " . max(0, intval($actor_id)) . ",
            ticket_customer_promise_completed_at = NOW()
            WHERE ticket_customer_promise_id = $promise_id
            AND ticket_customer_promise_status = 'open'",
            'Could not complete the customer promise');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The customer promise changed before completion');
        }
        ticketDisciplineRecordPromiseEvent(
            $promise_id,
            $ticket_id,
            $action,
            'open',
            $action,
            $actor_id,
            $reason
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the customer-promise change');
        }
        $transaction_started = false;
        return ['ticket_id' => $ticket_id, 'client_id' => $client_id, 'action' => $action];
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $exception;
    }
}

/** Caller owns the ticket lock and transaction. */
function ticketDisciplineRecordHandoff(
    int $ticket_id,
    int $from_user_id,
    int $to_user_id,
    string $reason,
    string $current_state,
    string $next_action,
    int $actor_id
): int {
    global $mysqli;

    $reason = ticketDisciplineText($reason, 'Handoff reason', 3, 500);
    $current_state = ticketDisciplineText($current_state, 'Current state', 3, 500);
    $next_action = ticketDisciplineText($next_action, 'Next action', 3, 500);
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT ticket_client_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1",
        'Could not load the handoff ticket'));
    if (!$ticket) {
        throw new RuntimeException('The handoff ticket is unavailable');
    }
    $reason_sql = mysqli_real_escape_string($mysqli, $reason);
    $state_sql = mysqli_real_escape_string($mysqli, $current_state);
    $next_sql = mysqli_real_escape_string($mysqli, $next_action);
    ticketDisciplineDbQuery("INSERT INTO ticket_handoffs SET
        ticket_handoff_ticket_id = $ticket_id,
        ticket_handoff_client_id = " . intval($ticket['ticket_client_id']) . ",
        ticket_handoff_from_user_id = " . max(0, intval($from_user_id)) . ",
        ticket_handoff_to_user_id = " . max(0, intval($to_user_id)) . ",
        ticket_handoff_reason = '$reason_sql',
        ticket_handoff_current_state = '$state_sql',
        ticket_handoff_next_action = '$next_sql',
        ticket_handoff_actor_id = " . max(0, intval($actor_id)),
        'Could not record the ticket handoff');
    ticketDisciplineDbQuery("UPDATE tickets SET ticket_next_action = '$next_sql',
        ticket_waiting_on = 'internal'
        WHERE ticket_id = $ticket_id", 'Could not update the ticket after handoff');
    return intval(mysqli_insert_id($mysqli));
}

function ticketDisciplineAddRelationship(
    int $ticket_id,
    int $selected_ticket_id,
    string $requested_type,
    int $actor_id
): int {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $selected_ticket_id = intval($selected_ticket_id);
    $requested_type = strtolower(trim($requested_type));
    if (!$ticket_id || !$selected_ticket_id || $ticket_id === $selected_ticket_id) {
        throw new DomainException('Choose another ticket to link.');
    }
    if (!isset(ticketRelationshipDefinitions()[$requested_type])) {
        throw new DomainException('Choose a valid ticket relationship.');
    }

    $from_id = $ticket_id;
    $to_id = $selected_ticket_id;
    $stored_type = $requested_type;
    if ($requested_type === 'related') {
        $from_id = min($ticket_id, $selected_ticket_id);
        $to_id = max($ticket_id, $selected_ticket_id);
    } elseif ($requested_type === 'parent') {
        $from_id = $selected_ticket_id;
        $to_id = $ticket_id;
        $stored_type = 'parent';
    } elseif ($requested_type === 'child') {
        $stored_type = 'parent';
    }

    $rows = ticketDisciplineDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_archived_at FROM tickets WHERE ticket_id IN ($from_id, $to_id)
        ORDER BY ticket_id", 'Could not locate the related tickets');
    $tickets = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $tickets[intval($row['ticket_id'])] = $row;
    }
    if (count($tickets) !== 2
        || intval($tickets[$from_id]['ticket_client_id']) !== intval($tickets[$to_id]['ticket_client_id'])
        || !empty($tickets[$from_id]['ticket_archived_at'])
        || !empty($tickets[$to_id]['ticket_archived_at'])) {
        throw new DomainException('Related tickets must be active and belong to the same client.');
    }
    $client_id = intval($tickets[$from_id]['ticket_client_id']);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket relationship transaction');
        }
        $transaction_started = true;
        if ($client_id) {
            documentationLockClient($client_id, true);
        }
        $locked = ticketDisciplineDbQuery("SELECT ticket_id, ticket_client_id,
            ticket_archived_at FROM tickets WHERE ticket_id IN ($from_id, $to_id)
            ORDER BY ticket_id FOR UPDATE", 'Could not lock the related tickets');
        $locked_count = 0;
        while ($row = mysqli_fetch_assoc($locked)) {
            if (intval($row['ticket_client_id']) !== $client_id || !empty($row['ticket_archived_at'])) {
                throw new RuntimeException('A related ticket changed before it was linked');
            }
            $locked_count++;
        }
        if ($locked_count !== 2) {
            throw new RuntimeException('A related ticket is no longer available');
        }
        $type_sql = mysqli_real_escape_string($mysqli, $stored_type);
        $duplicate = intval(mysqli_fetch_row(ticketDisciplineDbQuery("SELECT COUNT(*)
            FROM ticket_relationships WHERE ticket_relationship_from_ticket_id = $from_id
            AND ticket_relationship_to_ticket_id = $to_id
            AND ticket_relationship_type = '$type_sql'
            AND ticket_relationship_archived_at IS NULL",
            'Could not inspect existing ticket relationships'))[0] ?? 0);
        if ($duplicate) {
            throw new DomainException('That ticket relationship already exists.');
        }
        if ($stored_type === 'parent') {
            $cycle = mysqli_fetch_row(ticketDisciplineDbQuery("WITH RECURSIVE descendants AS (
                SELECT $to_id AS ticket_id
                UNION DISTINCT
                SELECT relationship.ticket_relationship_to_ticket_id
                FROM ticket_relationships relationship
                INNER JOIN descendants ON descendants.ticket_id = relationship.ticket_relationship_from_ticket_id
                WHERE relationship.ticket_relationship_type = 'parent'
                AND relationship.ticket_relationship_archived_at IS NULL
            ) SELECT COUNT(*) FROM descendants WHERE ticket_id = $from_id",
                'Could not inspect the ticket hierarchy'));
            if (intval($cycle[0] ?? 0) > 0) {
                throw new DomainException('This relationship would create a circular parent/child hierarchy.');
            }
        }
        ticketDisciplineDbQuery("INSERT INTO ticket_relationships SET
            ticket_relationship_from_ticket_id = $from_id,
            ticket_relationship_to_ticket_id = $to_id,
            ticket_relationship_type = '$type_sql',
            ticket_relationship_created_by = " . max(0, intval($actor_id)),
            'Could not link the tickets');
        $relationship_id = intval(mysqli_insert_id($mysqli));
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket relationship');
        }
        $transaction_started = false;
        return $relationship_id;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $exception;
    }
}

function ticketDisciplineRemoveRelationship(int $relationship_id, int $actor_id): array
{
    global $mysqli;

    $relationship_id = intval($relationship_id);
    $relationship = mysqli_fetch_assoc(ticketDisciplineDbQuery("SELECT relationship.*,
        source.ticket_client_id AS source_client_id,
        target.ticket_client_id AS target_client_id,
        source.ticket_archived_at AS source_archived_at,
        target.ticket_archived_at AS target_archived_at
        FROM ticket_relationships relationship
        INNER JOIN tickets source ON source.ticket_id = ticket_relationship_from_ticket_id
        INNER JOIN tickets target ON target.ticket_id = ticket_relationship_to_ticket_id
        WHERE ticket_relationship_id = $relationship_id
        AND ticket_relationship_archived_at IS NULL LIMIT 1",
        'Could not locate the ticket relationship'));
    if (!$relationship
        || intval($relationship['source_client_id']) !== intval($relationship['target_client_id'])
        || !empty($relationship['source_archived_at'])
        || !empty($relationship['target_archived_at'])) {
        throw new DomainException('The ticket relationship is unavailable.');
    }
    $client_id = intval($relationship['source_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    $from_id = intval($relationship['ticket_relationship_from_ticket_id']);
    $to_id = intval($relationship['ticket_relationship_to_ticket_id']);
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the relationship removal');
        }
        $transaction_started = true;
        if ($client_id) {
            documentationLockClient($client_id, true);
        }
        $locked = ticketDisciplineDbQuery("SELECT ticket_id, ticket_client_id, ticket_archived_at
            FROM tickets WHERE ticket_id IN ($from_id, $to_id) ORDER BY ticket_id FOR UPDATE",
            'Could not lock the related tickets');
        $count = 0;
        while ($ticket = mysqli_fetch_assoc($locked)) {
            if (intval($ticket['ticket_client_id']) !== $client_id || !empty($ticket['ticket_archived_at'])) {
                throw new DomainException('A related ticket changed. Refresh and try again.');
            }
            $count++;
        }
        if ($count !== 2) {
            throw new DomainException('A related ticket is no longer available.');
        }
        ticketDisciplineDbQuery("UPDATE ticket_relationships SET
            ticket_relationship_archived_at = NOW(),
            ticket_relationship_archived_by = " . max(0, intval($actor_id)) . "
            WHERE ticket_relationship_id = $relationship_id
            AND ticket_relationship_from_ticket_id = $from_id
            AND ticket_relationship_to_ticket_id = $to_id
            AND ticket_relationship_archived_at IS NULL",
            'Could not remove the ticket relationship');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket relationship changed before removal');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the relationship removal');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $exception;
    }
    return $relationship;
}

function ticketDisciplineRelationships(int $ticket_id): array
{
    $ticket_id = intval($ticket_id);
    $rows = ticketDisciplineDbQuery("SELECT relationship.*,
        source.ticket_prefix AS source_prefix, source.ticket_number AS source_number,
        source.ticket_subject AS source_subject, source.ticket_archived_at AS source_deleted_at,
        target.ticket_prefix AS target_prefix, target.ticket_number AS target_number,
        target.ticket_subject AS target_subject, target.ticket_archived_at AS target_deleted_at
        FROM ticket_relationships relationship
        INNER JOIN tickets source ON source.ticket_id = ticket_relationship_from_ticket_id
        INNER JOIN tickets target ON target.ticket_id = ticket_relationship_to_ticket_id
        WHERE (ticket_relationship_from_ticket_id = $ticket_id
            OR ticket_relationship_to_ticket_id = $ticket_id)
        AND ticket_relationship_archived_at IS NULL
        ORDER BY ticket_relationship_created_at, ticket_relationship_id",
        'Could not load ticket relationships');
    $relationships = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $relationships[] = $row;
    }
    return $relationships;
}

function ticketDisciplineCompleteness(array $ticket): array
{
    $issues = [];
    if (trim((string) ($ticket['ticket_subject'] ?? '')) === '') {
        $issues[] = 'Add a meaningful subject';
    }
    if (intval($ticket['ticket_client_id'] ?? 0) > 0
        && intval($ticket['ticket_contact_id'] ?? 0) < 1) {
        $issues[] = 'Identify the requester/contact';
    }
    if (intval($ticket['ticket_category'] ?? 0) < 1) {
        $issues[] = 'Choose a category';
    }
    if (!isset(ticketWorkTypeDefinitions()[(string) ($ticket['ticket_work_type'] ?? '')])) {
        $issues[] = 'Choose a work type';
    }
    if (!isset(ticketImpactDefinitions()[(string) ($ticket['ticket_impact'] ?? '')])
        || !isset(ticketUrgencyDefinitions()[(string) ($ticket['ticket_urgency'] ?? '')])) {
        $issues[] = 'Assess impact and urgency';
    }
    return $issues;
}
