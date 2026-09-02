<?php

/*
 * N45 ticket operational discipline.
 *
 * Pure taxonomy/matrix helpers are kept at the top. Database helpers below
 * enforce client ownership, deterministic locking and append-only audit state
 * for every fork-owned lifecycle.
 */

function ticketOperationalWorkTypes(): array
{
    return [
        'incident' => 'Incident',
        'request' => 'Service request',
        'problem' => 'Problem',
        'change' => 'Change',
        'onboarding' => 'Onboarding / offboarding',
        'project_task' => 'Project task',
    ];
}

function ticketOperationalLevels(): array
{
    return [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];
}

function ticketOperationalWaitingOnDefinitions(): array
{
    return [
        'none' => 'No external wait',
        'customer' => 'Customer',
        'vendor' => 'Vendor',
        'internal' => 'Internal team',
        'third_party' => 'Other third party',
    ];
}

function ticketOperationalResolutionCodes(): array
{
    return [
        'fixed' => 'Fixed',
        'fulfilled' => 'Request fulfilled',
        'workaround' => 'Workaround provided',
        'monitor_recovered' => 'Monitoring recovery confirmed',
        'customer_confirmed' => 'Customer confirmed resolved',
        'duplicate' => 'Duplicate',
        'cancelled' => 'Cancelled / no longer required',
        'no_fault_found' => 'No fault found',
        'transferred' => 'Transferred outside scope',
    ];
}

function ticketOperationalPromiseTypes(): array
{
    return [
        'customer_update' => 'Next customer update',
        'target_completion' => 'Target completion',
    ];
}

function ticketOperationalRelationshipTypes(): array
{
    return [
        'parent_child' => 'Parent / child',
        'duplicate' => 'Duplicate of',
        'related' => 'Related to',
    ];
}

function ticketOperationalNormalizeKey($value, array $definitions, string $field): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException("$field must be a string");
    }
    $value = strtolower(trim($value));
    if (!array_key_exists($value, $definitions)) {
        throw new InvalidArgumentException("Invalid $field");
    }
    return $value;
}

/**
 * Fixed 4x4 service-desk matrix. Urgent is reserved for the top three cells;
 * a caller cannot directly supply or override the resulting priority.
 */
function ticketOperationalPriorityFromImpactUrgency($impact, $urgency): string
{
    $levels = ticketOperationalLevels();
    $impact = ticketOperationalNormalizeKey($impact, $levels, 'impact');
    $urgency = ticketOperationalNormalizeKey($urgency, $levels, 'urgency');
    $matrix = [
        'low' => [
            'low' => 'Low', 'medium' => 'Low', 'high' => 'Medium', 'critical' => 'High',
        ],
        'medium' => [
            'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'High',
        ],
        'high' => [
            'low' => 'Medium', 'medium' => 'High', 'high' => 'High', 'critical' => 'Urgent',
        ],
        'critical' => [
            'low' => 'High', 'medium' => 'High', 'high' => 'Urgent', 'critical' => 'Urgent',
        ],
    ];
    return $matrix[$impact][$urgency];
}

function ticketOperationalLegacyDimensionsForPriority($priority): array
{
    $priority = is_string($priority) ? strtolower(trim($priority)) : '';
    $map = [
        'urgent' => ['critical', 'high'],
        'high' => ['high', 'medium'],
        'medium' => ['medium', 'medium'],
        'low' => ['low', 'low'],
    ];
    return $map[$priority] ?? $map['low'];
}

function ticketOperationalNormalizeDateTime($value): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', DateTimeInterface::ATOM] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, trim((string) $value));
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Invalid date and time');
}

function ticketOperationalCanonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('ticketOperationalCanonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = ticketOperationalCanonicalize($item);
    }
    return $value;
}

function ticketOperationalCanonicalJson(array $payload): string
{
    return json_encode(
        ticketOperationalCanonicalize($payload),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function ticketOperationalDbQuery(string $sql, string $message = 'Ticket operational query failed')
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

/**
 * Goal 10 adds ticket_deleted_at after this module's migration. Discovering
 * the column keeps Goal 6 independently deployable while making every Goal 6
 * mutation fail closed as soon as the retention schema is present.
 */
function ticketOperationalSoftDeleteColumn(): ?string
{
    static $resolved = false;
    static $column = null;
    if ($resolved) {
        return $column;
    }
    $resolved = true;
    $result = ticketOperationalDbQuery("SELECT column_name FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'tickets'
        AND column_name = 'ticket_deleted_at' LIMIT 1",
        'Could not inspect ticket retention compatibility');
    if (mysqli_fetch_assoc($result)) {
        $column = 'ticket_deleted_at';
    }
    return $column;
}

function ticketOperationalActiveTicketSql(string $qualifier = 'tickets'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $qualifier)) {
        throw new InvalidArgumentException('Invalid ticket table qualifier');
    }
    $scope = " AND $qualifier.ticket_archived_at IS NULL";
    if (ticketOperationalSoftDeleteColumn() === 'ticket_deleted_at') {
        $scope .= " AND $qualifier.ticket_deleted_at IS NULL";
    }
    return $scope;
}

function ticketOperationalSoftDeleteProjection(string $qualifier = 'tickets'): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $qualifier)) {
        throw new InvalidArgumentException('Invalid ticket table qualifier');
    }
    return ticketOperationalSoftDeleteColumn() === 'ticket_deleted_at'
        ? ", $qualifier.ticket_deleted_at AS ticket_operational_deleted_at"
        : ', NULL AS ticket_operational_deleted_at';
}

function ticketOperationalTicketIsImmutable(array $ticket, bool $include_resolved = true): bool
{
    $status = intval($ticket['ticket_status'] ?? 0);
    return !empty($ticket['ticket_closed_at'])
        || !empty($ticket['ticket_archived_at'])
        || !empty($ticket['ticket_operational_deleted_at'])
        || $status === 5
        || ($include_resolved && $status === 4);
}

function ticketOperationalRecordEvent(
    int $ticket_id,
    int $client_id,
    string $action,
    array $payload,
    int $actor_id = 0,
    string $actor_type = 'system'
): int {
    global $mysqli;
    $action = substr(trim($action), 0, 40);
    if ($ticket_id < 1 || $action === '') {
        throw new InvalidArgumentException('Ticket and operational action are required');
    }
    if (!in_array($actor_type, ['agent', 'contact', 'api', 'automation', 'email', 'system'], true)) {
        throw new InvalidArgumentException('Invalid operational event actor');
    }
    $payload_json = ticketOperationalCanonicalJson($payload);
    $payload_hash = hash('sha256', $payload_json);
    $payload_sql = mysqli_real_escape_string($mysqli, $payload_json);
    $hash_sql = mysqli_real_escape_string($mysqli, $payload_hash);
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $actor_type_sql = mysqli_real_escape_string($mysqli, $actor_type);
    ticketOperationalDbQuery("INSERT INTO ticket_operational_events SET
        ticket_operational_event_ticket_id = $ticket_id,
        ticket_operational_event_client_id = $client_id,
        ticket_operational_event_action = '$action_sql',
        ticket_operational_event_actor_type = '$actor_type_sql',
        ticket_operational_event_actor_id = $actor_id,
        ticket_operational_event_payload = '$payload_sql',
        ticket_operational_event_payload_hash = '$hash_sql'",
        'Could not record the ticket operational event');
    return intval(mysqli_insert_id($mysqli));
}

function ticketOperationalInferWorkType(array $ticket): string
{
    $source = strtolower((string) ($ticket['ticket_source'] ?? ''));
    $subject = strtolower(strip_tags((string) ($ticket['ticket_subject'] ?? '')));
    if (intval($ticket['ticket_project_id'] ?? 0) > 0 || $source === 'project template') {
        return 'project_task';
    }
    if (in_array($source, ['automation', 'level.io'], true)) {
        return 'incident';
    }
    if (strpos($subject, 'onboard') !== false || strpos($subject, 'offboard') !== false) {
        return 'onboarding';
    }
    return 'request';
}

/**
 * Compatibility seam for every existing ticket-creation path. applyTicketSla
 * calls this while the creation transaction is still open. Explicitly typed
 * tickets carry ticket_operational_updated_at and are never overwritten.
 */
function ticketOperationalNormalizeLegacyTicket(int $ticket_id): void
{
    global $mysqli;
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_id, ticket_source,
        ticket_subject, ticket_priority, ticket_project_id, ticket_created_by,
        ticket_created_at, ticket_operational_updated_at FROM tickets
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1", 'Could not load a ticket for operational normalization'));
    if (!$ticket) {
        return;
    }
    if (!empty($ticket['ticket_operational_updated_at'])) {
        // Once dimensions exist they are the sole priority authority. This
        // also repairs direct/custom writes that try to change only priority.
        $state = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_impact,
            ticket_urgency FROM tickets WHERE ticket_id = $ticket_id
            AND tickets.ticket_deleted_at IS NULL LIMIT 1"));
        try {
            $priority = ticketOperationalPriorityFromImpactUrgency(
                $state['ticket_impact'] ?? '',
                $state['ticket_urgency'] ?? ''
            );
        } catch (InvalidArgumentException $e) {
            [$impact, $urgency] = ticketOperationalLegacyDimensionsForPriority($ticket['ticket_priority'] ?? 'Low');
            $priority = ticketOperationalPriorityFromImpactUrgency($impact, $urgency);
            $impact_sql = mysqli_real_escape_string($mysqli, $impact);
            $urgency_sql = mysqli_real_escape_string($mysqli, $urgency);
            ticketOperationalDbQuery("UPDATE tickets SET ticket_impact = '$impact_sql',
                ticket_urgency = '$urgency_sql' WHERE ticket_id = $ticket_id
                AND tickets.ticket_deleted_at IS NULL",
                'Could not repair ticket priority dimensions');
        }
        $priority_sql = mysqli_real_escape_string($mysqli, $priority);
        ticketOperationalDbQuery("UPDATE tickets SET ticket_priority = '$priority_sql'
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            AND (ticket_priority IS NULL OR ticket_priority <> '$priority_sql')",
            'Could not enforce deterministic ticket priority');
        return;
    }
    [$impact, $urgency] = ticketOperationalLegacyDimensionsForPriority($ticket['ticket_priority'] ?? 'Low');
    $priority = ticketOperationalPriorityFromImpactUrgency($impact, $urgency);
    $work_type = ticketOperationalInferWorkType($ticket);
    $work_type_sql = mysqli_real_escape_string($mysqli, $work_type);
    $impact_sql = mysqli_real_escape_string($mysqli, $impact);
    $urgency_sql = mysqli_real_escape_string($mysqli, $urgency);
    $priority_sql = mysqli_real_escape_string($mysqli, $priority);
    $actor_id = intval($ticket['ticket_created_by'] ?? 0);
    ticketOperationalDbQuery("UPDATE tickets SET
        ticket_work_type = '$work_type_sql', ticket_impact = '$impact_sql',
        ticket_urgency = '$urgency_sql', ticket_priority = '$priority_sql',
        ticket_next_action = 'Review and triage this ticket.',
        ticket_operational_updated_by = $actor_id,
        ticket_operational_updated_at = COALESCE(ticket_created_at, NOW())
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        AND ticket_operational_updated_at IS NULL",
        'Could not normalize the new ticket operational state');
}

function ticketOperationalInput(array $input, ?array $existing = null): array
{
    $existing = $existing ?? [];
    $work_type = ticketOperationalNormalizeKey(
        $input['work_type'] ?? $existing['ticket_work_type'] ?? 'request',
        ticketOperationalWorkTypes(),
        'work type'
    );
    $impact = ticketOperationalNormalizeKey(
        $input['impact'] ?? $existing['ticket_impact'] ?? 'low',
        ticketOperationalLevels(),
        'impact'
    );
    $urgency = ticketOperationalNormalizeKey(
        $input['urgency'] ?? $existing['ticket_urgency'] ?? 'low',
        ticketOperationalLevels(),
        'urgency'
    );
    $next_action = trim((string) ($input['next_action'] ?? $existing['ticket_next_action'] ?? ''));
    if ($next_action === '' || mb_strlen($next_action) > 500) {
        throw new InvalidArgumentException('Next action is required and must be 500 characters or fewer');
    }
    $waiting_on = ticketOperationalNormalizeKey(
        $input['waiting_on'] ?? $existing['ticket_waiting_on'] ?? 'none',
        ticketOperationalWaitingOnDefinitions(),
        'waiting on'
    );
    $waiting_detail = trim((string) ($input['waiting_on_detail'] ?? $existing['ticket_waiting_on_detail'] ?? ''));
    if ($waiting_on !== 'none' && $waiting_detail === '') {
        throw new InvalidArgumentException('Describe what is required while the ticket is waiting');
    }
    if (mb_strlen($waiting_detail) > 255) {
        throw new InvalidArgumentException('Waiting detail must be 255 characters or fewer');
    }
    if ($waiting_on === 'none') {
        $waiting_detail = '';
    }
    return [
        'work_type' => $work_type,
        'impact' => $impact,
        'urgency' => $urgency,
        'priority' => ticketOperationalPriorityFromImpactUrgency($impact, $urgency),
        'next_action' => $next_action,
        'next_action_due_at' => ticketOperationalNormalizeDateTime(
            $input['next_action_due_at'] ?? $existing['ticket_next_action_due_at'] ?? null
        ),
        'waiting_on' => $waiting_on,
        'waiting_on_detail' => $waiting_detail,
    ];
}

function ticketOperationalUpdateTicket(int $ticket_id, array $input, int $actor_id, string $actor_type = 'agent'): array
{
    global $mysqli;
    if ($ticket_id < 1) {
        throw new InvalidArgumentException('Ticket ID is required');
    }
    $transaction_open = false;
    try {
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $active_scope = ticketOperationalActiveTicketSql('tickets');
        $advisory = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id FROM tickets
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            $active_scope LIMIT 1", 'Could not locate the operational ticket'));
        if (!$advisory) {
            throw new RuntimeException('Ticket not found');
        }
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket operational update');
        }
        $transaction_open = true;
        $client_id = intval($advisory['ticket_client_id']);
        if ($client_id > 0 && function_exists('agreementLockClientForAuditRetention')
            && !agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The ticket client is unavailable');
        }
        $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT tickets.* $projection FROM tickets
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            $active_scope LIMIT 1 FOR UPDATE", 'Could not lock the operational ticket'));
        if (!$ticket || intval($ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket client changed before the operational update');
        }
        if (ticketOperationalTicketIsImmutable($ticket)) {
            throw new DomainException('Resolved, closed, archived, or deleted tickets cannot be changed');
        }
        $normalized = ticketOperationalInput($input, $ticket);
        $work_type_sql = mysqli_real_escape_string($mysqli, $normalized['work_type']);
        $impact_sql = mysqli_real_escape_string($mysqli, $normalized['impact']);
        $urgency_sql = mysqli_real_escape_string($mysqli, $normalized['urgency']);
        $priority_sql = mysqli_real_escape_string($mysqli, $normalized['priority']);
        $next_action_sql = mysqli_real_escape_string($mysqli, $normalized['next_action']);
        $waiting_on_sql = mysqli_real_escape_string($mysqli, $normalized['waiting_on']);
        $waiting_detail_sql = $normalized['waiting_on_detail'] === ''
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $normalized['waiting_on_detail']) . "'";
        $next_due_sql = $normalized['next_action_due_at'] === null
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $normalized['next_action_due_at']) . "'";
        ticketOperationalDbQuery("UPDATE tickets SET ticket_work_type = '$work_type_sql',
            ticket_impact = '$impact_sql', ticket_urgency = '$urgency_sql',
            ticket_priority = '$priority_sql', ticket_next_action = '$next_action_sql',
            ticket_next_action_due_at = $next_due_sql, ticket_waiting_on = '$waiting_on_sql',
            ticket_waiting_on_detail = $waiting_detail_sql,
            ticket_operational_updated_by = $actor_id, ticket_operational_updated_at = NOW()
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            AND ticket_status NOT IN (4, 5)
            AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL",
            'Could not update ticket operational fields');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket changed before the operational update committed');
        }
        ticketOperationalRecordEvent($ticket_id, $client_id, 'OperationalUpdated', [
            'before' => [
                'work_type' => $ticket['ticket_work_type'],
                'impact' => $ticket['ticket_impact'],
                'urgency' => $ticket['ticket_urgency'],
                'priority' => $ticket['ticket_priority'],
                'next_action' => $ticket['ticket_next_action'],
                'next_action_due_at' => $ticket['ticket_next_action_due_at'],
                'waiting_on' => $ticket['ticket_waiting_on'],
                'waiting_on_detail' => $ticket['ticket_waiting_on_detail'],
            ],
            'after' => $normalized,
        ], $actor_id, $actor_type);
        if (function_exists('applyTicketSla')) {
            applyTicketSla($ticket_id, null, null, true);
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket operational update');
        }
        $transaction_open = false;
        return $normalized;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalSetResolution(
    int $ticket_id,
    $resolution_code,
    $resolution_summary,
    $root_cause,
    int $actor_id,
    string $actor_type = 'agent'
): void {
    global $mysqli;
    $code = ticketOperationalNormalizeKey($resolution_code, ticketOperationalResolutionCodes(), 'resolution code');
    $summary = trim((string) $resolution_summary);
    $root_cause = trim((string) $root_cause);
    if ($summary === '' || mb_strlen($summary) > 10000) {
        throw new InvalidArgumentException('A resolution summary is required and must be 10,000 characters or fewer');
    }
    if (mb_strlen($root_cause) > 20000) {
        throw new InvalidArgumentException('Root cause must be 20,000 characters or fewer');
    }
    $projection = ticketOperationalSoftDeleteProjection('tickets');
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
        ticket_work_type, ticket_resolution_code, ticket_resolution_summary, ticket_root_cause,
        ticket_status, ticket_closed_at, ticket_archived_at $projection
        FROM tickets WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1 FOR UPDATE",
        'Could not lock the ticket resolution'));
    if (!$ticket) {
        throw new RuntimeException('Ticket not found');
    }
    if (ticketOperationalTicketIsImmutable($ticket)) {
        throw new DomainException('Resolution evidence can only be prepared on an active ticket');
    }
    if ($ticket['ticket_work_type'] === 'problem' && $root_cause === '') {
        throw new InvalidArgumentException('Problem tickets require a root cause before resolution');
    }
    $code_sql = mysqli_real_escape_string($mysqli, $code);
    $summary_sql = mysqli_real_escape_string($mysqli, $summary);
    $root_cause_sql = $root_cause === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $root_cause) . "'";
    ticketOperationalDbQuery("UPDATE tickets SET ticket_resolution_code = '$code_sql',
        ticket_resolution_summary = '$summary_sql', ticket_root_cause = $root_cause_sql,
        ticket_operational_updated_by = $actor_id, ticket_operational_updated_at = NOW()
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        AND ticket_status NOT IN (4, 5)
        AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL",
        'Could not save ticket resolution evidence');
    ticketOperationalRecordEvent($ticket_id, intval($ticket['ticket_client_id']), 'ResolutionPrepared', [
        'resolution_code' => $code,
        'resolution_summary' => $summary,
        'root_cause' => $root_cause,
    ], $actor_id, $actor_type);
}

function ticketOperationalPrepareAutomaticResolution(
    int $ticket_id,
    string $resolution_code,
    string $summary,
    int $actor_id = 0,
    string $actor_type = 'system'
): void {
    ticketOperationalSetResolution($ticket_id, $resolution_code, $summary, '', $actor_id, $actor_type);
}

function ticketOperationalCanResolve(int $ticket_id, bool $include_detail = false): array
{
    global $mysqli;
    $projection = ticketOperationalSoftDeleteProjection('tickets');
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_work_type,
        ticket_waiting_on, ticket_resolution_code, ticket_resolution_summary, ticket_root_cause,
        ticket_status, ticket_closed_at, ticket_archived_at $projection
        FROM tickets WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1",
        'Could not evaluate the ticket operational gate'));
    if (!$ticket) {
        return [false, 'Ticket not found.'];
    }
    $fail = static function (string $detail) use ($include_detail): array {
        return [false, $include_detail ? $detail : 'Resolution evidence must be completed before this ticket can be resolved.'];
    };
    if (ticketOperationalTicketIsImmutable($ticket)) {
        return $fail('Only active tickets can be resolved.');
    }
    if (!array_key_exists((string) $ticket['ticket_resolution_code'], ticketOperationalResolutionCodes())) {
        return $fail('Select a resolution code.');
    }
    if (trim((string) $ticket['ticket_resolution_summary']) === '') {
        return $fail('Add a resolution summary.');
    }
    if ((string) $ticket['ticket_work_type'] === 'problem' && trim((string) $ticket['ticket_root_cause']) === '') {
        return $fail('Problem tickets require a root cause.');
    }
    if ((string) $ticket['ticket_waiting_on'] !== 'none') {
        return $fail('Clear the waiting-on state before resolving the ticket.');
    }
    if ((string) $ticket['ticket_resolution_code'] === 'duplicate') {
        $duplicate = mysqli_fetch_row(ticketOperationalDbQuery("SELECT COUNT(*) FROM ticket_relationships
            WHERE ticket_relationship_type = 'duplicate'
            AND (ticket_relationship_source_ticket_id = $ticket_id
                OR ticket_relationship_target_ticket_id = $ticket_id)",
            'Could not verify the duplicate relationship'));
        if (intval($duplicate[0] ?? 0) < 1) {
            return $fail('Duplicate resolution requires a linked duplicate ticket.');
        }
    }
    return [true, ''];
}

function ticketOperationalOnResolved(int $ticket_id, int $actor_id = 0, string $actor_type = 'system'): void
{
    $projection = ticketOperationalSoftDeleteProjection('tickets');
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
        ticket_resolution_code, ticket_status, ticket_archived_at $projection
        FROM tickets WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1 FOR UPDATE",
        'Could not lock the resolved ticket operational state'));
    if (!$ticket) {
        throw new RuntimeException('Ticket not found');
    }
    if (!in_array(intval($ticket['ticket_status']), [4, 5], true)) {
        throw new DomainException('Ticket resolution state was not committed');
    }
    ticketOperationalDbQuery("UPDATE tickets SET ticket_waiting_on = 'none',
        ticket_waiting_on_detail = NULL, ticket_next_action = 'No further action — ticket resolved.',
        ticket_next_action_due_at = NULL, ticket_operational_updated_by = $actor_id,
        ticket_operational_updated_at = NOW() WHERE ticket_id = $ticket_id
        AND tickets.ticket_deleted_at IS NULL
        AND ticket_status IN (4, 5) AND ticket_archived_at IS NULL",
        'Could not finalize the resolved ticket operational state');
    ticketOperationalFulfillPromisesLocked($ticket_id, 'customer_update', $actor_id, $actor_type, $ticket_id);
    ticketOperationalFulfillPromisesLocked($ticket_id, 'target_completion', $actor_id, $actor_type, $ticket_id);
    ticketOperationalRecordEvent($ticket_id, intval($ticket['ticket_client_id']), 'Resolved', [
        'resolution_code' => $ticket['ticket_resolution_code'],
    ], $actor_id, $actor_type);
}

function ticketOperationalOnReopened(int $ticket_id, int $actor_id = 0, string $actor_type = 'system'): void
{
    $projection = ticketOperationalSoftDeleteProjection('tickets');
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id, ticket_status,
        ticket_closed_at, ticket_archived_at $projection FROM tickets
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1 FOR UPDATE", 'Could not lock the reopened ticket'));
    if (!$ticket) {
        throw new RuntimeException('Ticket not found');
    }
    if (ticketOperationalTicketIsImmutable($ticket, false)) {
        throw new DomainException('Closed, archived, or deleted tickets cannot be reopened');
    }
    if (in_array(intval($ticket['ticket_status']), [4, 5], true)) {
        throw new DomainException('The ticket status was not reopened before operational reset');
    }
    ticketOperationalDbQuery("UPDATE tickets SET ticket_resolution_code = NULL,
        ticket_resolution_summary = NULL, ticket_root_cause = NULL,
        ticket_next_action = 'Review the new response and confirm the next action.',
        ticket_waiting_on = 'none', ticket_waiting_on_detail = NULL,
        ticket_operational_updated_by = $actor_id, ticket_operational_updated_at = NOW()
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL",
        'Could not reset reopened ticket resolution evidence');
    ticketOperationalRecordEvent($ticket_id, intval($ticket['ticket_client_id']), 'Reopened', [], $actor_id, $actor_type);
}

function ticketOperationalWouldCreateParentCycle(int $parent_ticket_id, int $child_ticket_id): bool
{
    $frontier = [$child_ticket_id];
    $seen = [];
    for ($depth = 0; $frontier && $depth < 1000; $depth++) {
        $current = array_shift($frontier);
        if ($current === $parent_ticket_id) {
            return true;
        }
        if (isset($seen[$current])) {
            continue;
        }
        $seen[$current] = true;
        $children = ticketOperationalDbQuery("SELECT ticket_relationship_target_ticket_id
            FROM ticket_relationships WHERE ticket_relationship_type = 'parent_child'
            AND ticket_relationship_source_ticket_id = $current",
            'Could not inspect the ticket relationship graph');
        while ($child = mysqli_fetch_assoc($children)) {
            $frontier[] = intval($child['ticket_relationship_target_ticket_id']);
        }
    }
    if ($frontier) {
        throw new RuntimeException('Ticket relationship graph exceeds the safe traversal limit');
    }
    return false;
}

function ticketOperationalAddRelationship(
    int $source_ticket_id,
    int $target_ticket_id,
    $relationship_type,
    int $actor_id,
    bool $caller_transaction = false
): int {
    global $mysqli;
    $relationship_type = ticketOperationalNormalizeKey(
        $relationship_type,
        ticketOperationalRelationshipTypes(),
        'relationship type'
    );
    if ($source_ticket_id < 1 || $target_ticket_id < 1 || $source_ticket_id === $target_ticket_id) {
        throw new InvalidArgumentException('Two different tickets are required');
    }
    if (in_array($relationship_type, ['duplicate', 'related'], true)
        && $source_ticket_id > $target_ticket_id) {
        [$source_ticket_id, $target_ticket_id] = [$target_ticket_id, $source_ticket_id];
    }
    $advisory = ticketOperationalDbQuery("SELECT ticket_id, ticket_client_id FROM tickets
        WHERE ticket_id IN ($source_ticket_id, $target_ticket_id)
        AND tickets.ticket_deleted_at IS NULL ORDER BY ticket_id",
        'Could not locate tickets for the relationship');
    $advisory_clients = [];
    while ($ticket = mysqli_fetch_assoc($advisory)) {
        $advisory_clients[intval($ticket['ticket_id'])] = intval($ticket['ticket_client_id']);
    }
    if (count($advisory_clients) !== 2
        || $advisory_clients[$source_ticket_id] !== $advisory_clients[$target_ticket_id]) {
        throw new DomainException('Tickets from different clients cannot be related');
    }
    $advisory_client_id = $advisory_clients[$source_ticket_id];
    $transaction_open = false;
    try {
        if (!$caller_transaction && !mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket relationship transaction');
        }
        $transaction_open = !$caller_transaction;
        if ($advisory_client_id > 0) {
            documentationLockClient($advisory_client_id);
        }
        $first = min($source_ticket_id, $target_ticket_id);
        $second = max($source_ticket_id, $target_ticket_id);
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $active_scope = ticketOperationalActiveTicketSql('tickets');
        $tickets = ticketOperationalDbQuery("SELECT ticket_id, ticket_client_id, ticket_status,
            ticket_closed_at, ticket_archived_at $projection FROM tickets
            WHERE ticket_id IN ($first, $second) AND tickets.ticket_deleted_at IS NULL
            $active_scope ORDER BY ticket_id FOR UPDATE",
            'Could not lock related tickets');
        $locked = [];
        while ($ticket = mysqli_fetch_assoc($tickets)) {
            if (ticketOperationalTicketIsImmutable($ticket)) {
                throw new DomainException('Resolved, closed, archived, or deleted tickets cannot gain relationships');
            }
            $locked[intval($ticket['ticket_id'])] = $ticket;
        }
        if (count($locked) !== 2) {
            throw new RuntimeException('One or both tickets are unavailable');
        }
        $client_id = intval($locked[$source_ticket_id]['ticket_client_id']);
        if ($client_id !== $advisory_client_id
            || $client_id !== intval($locked[$target_ticket_id]['ticket_client_id'])) {
            throw new DomainException('Tickets from different clients cannot be related');
        }
        if ($relationship_type === 'parent_child'
            && ticketOperationalWouldCreateParentCycle($source_ticket_id, $target_ticket_id)) {
            throw new DomainException('The parent relationship would create a cycle');
        }
        $type_sql = mysqli_real_escape_string($mysqli, $relationship_type);
        $existing = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_relationship_id
            FROM ticket_relationships WHERE ticket_relationship_type = '$type_sql'
            AND ticket_relationship_source_ticket_id = $source_ticket_id
            AND ticket_relationship_target_ticket_id = $target_ticket_id LIMIT 1 FOR UPDATE",
            'Could not inspect the existing ticket relationship'));
        if ($existing) {
            if (!$caller_transaction && !mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the ticket relationship check');
            }
            $transaction_open = false;
            return intval($existing['ticket_relationship_id']);
        }
        ticketOperationalDbQuery("INSERT INTO ticket_relationships SET
            ticket_relationship_client_id = $client_id,
            ticket_relationship_type = '$type_sql',
            ticket_relationship_source_ticket_id = $source_ticket_id,
            ticket_relationship_target_ticket_id = $target_ticket_id,
            ticket_relationship_created_by = $actor_id",
            'Could not create the ticket relationship');
        $relationship_id = intval(mysqli_insert_id($mysqli));
        ticketOperationalRecordEvent($source_ticket_id, $client_id, 'RelationshipAdded', [
            'relationship_id' => $relationship_id,
            'relationship_type' => $relationship_type,
            'other_ticket_id' => $target_ticket_id,
        ], $actor_id, 'agent');
        ticketOperationalRecordEvent($target_ticket_id, $client_id, 'RelationshipAdded', [
            'relationship_id' => $relationship_id,
            'relationship_type' => $relationship_type,
            'other_ticket_id' => $source_ticket_id,
        ], $actor_id, 'agent');
        if (!$caller_transaction && !mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket relationship');
        }
        $transaction_open = false;
        return $relationship_id;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalRemoveRelationship(int $relationship_id, int $ticket_id, int $actor_id): void
{
    global $mysqli;
    $advisory = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT * FROM ticket_relationships
        WHERE ticket_relationship_id = $relationship_id
        AND (ticket_relationship_source_ticket_id = $ticket_id
            OR ticket_relationship_target_ticket_id = $ticket_id) LIMIT 1",
        'Could not locate the ticket relationship'));
    if (!$advisory) {
        throw new RuntimeException('Ticket relationship not found');
    }
    $advisory_client_id = intval($advisory['ticket_relationship_client_id']);
    $transaction_open = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket relationship removal');
        }
        $transaction_open = true;
        if ($advisory_client_id > 0) {
            documentationLockClient($advisory_client_id);
        }
        // Match retention/purge ordering: client -> tickets -> dependent row.
        // The advisory values identify which canonical ticket rows to lock;
        // the relationship is re-read and compared only after those locks.
        $source_ticket_id = intval($advisory['ticket_relationship_source_ticket_id']);
        $target_ticket_id = intval($advisory['ticket_relationship_target_ticket_id']);
        $first = min($source_ticket_id, $target_ticket_id);
        $second = max($source_ticket_id, $target_ticket_id);
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $active_scope = ticketOperationalActiveTicketSql('tickets');
        $tickets = ticketOperationalDbQuery("SELECT ticket_id, ticket_client_id, ticket_status,
            ticket_closed_at, ticket_archived_at $projection FROM tickets
            WHERE ticket_id IN ($first, $second) AND tickets.ticket_deleted_at IS NULL
            $active_scope ORDER BY ticket_id FOR UPDATE",
            'Could not lock the relationship tickets');
        $locked = [];
        while ($ticket = mysqli_fetch_assoc($tickets)) {
            if (ticketOperationalTicketIsImmutable($ticket)) {
                throw new DomainException('Relationships on resolved, closed, archived, or deleted tickets are immutable');
            }
            $locked[intval($ticket['ticket_id'])] = intval($ticket['ticket_client_id']);
        }
        $relationship_client_id = $advisory_client_id;
        if (count($locked) !== 2 || $locked[$source_ticket_id] !== $relationship_client_id
            || $locked[$target_ticket_id] !== $relationship_client_id) {
            throw new DomainException('The relationship client boundary is invalid');
        }
        $relationship = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT * FROM ticket_relationships
            WHERE ticket_relationship_id = $relationship_id
            AND ticket_relationship_client_id = $advisory_client_id
            AND ticket_relationship_source_ticket_id = $source_ticket_id
            AND ticket_relationship_target_ticket_id = $target_ticket_id
            AND (ticket_relationship_source_ticket_id = $ticket_id
                OR ticket_relationship_target_ticket_id = $ticket_id) LIMIT 1 FOR UPDATE",
            'Could not lock the ticket relationship'));
        if (!$relationship) {
            throw new RuntimeException('Ticket relationship changed; refresh and try again');
        }
        ticketOperationalDbQuery("DELETE FROM ticket_relationships
            WHERE ticket_relationship_id = $relationship_id", 'Could not remove the ticket relationship');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket relationship changed before removal');
        }
        foreach ([$source_ticket_id, $target_ticket_id] as $related_ticket_id) {
            ticketOperationalRecordEvent($related_ticket_id, $relationship_client_id,
                'RelationshipRemoved', [
                    'relationship_id' => $relationship_id,
                    'relationship_type' => $relationship['ticket_relationship_type'],
                    'other_ticket_id' => $related_ticket_id === $source_ticket_id
                        ? $target_ticket_id : $source_ticket_id,
                ], $actor_id, 'agent');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket relationship removal');
        }
        $transaction_open = false;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalTicketHasRelationships(int $ticket_id): bool
{
    $row = mysqli_fetch_row(ticketOperationalDbQuery("SELECT COUNT(*) FROM ticket_relationships
        WHERE ticket_relationship_source_ticket_id = $ticket_id
        OR ticket_relationship_target_ticket_id = $ticket_id",
        'Could not inspect ticket relationships'));
    return intval($row[0] ?? 0) > 0;
}

/**
 * Permanent deletion/client-transfer guard and Goal 10 purge integration.
 * Operational evidence is client-bound and remains immutable even after a
 * relationship is removed or a promise reaches a terminal state.
 */
function ticketOperationalTicketHasImmutableHistory(int $ticket_id): bool
{
    if ($ticket_id < 1) {
        return false;
    }
    $artifacts = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT
        EXISTS (SELECT 1 FROM ticket_operational_events
            WHERE ticket_operational_event_ticket_id = $ticket_id) AS has_operational_event,
        EXISTS (SELECT 1 FROM ticket_relationships
            WHERE ticket_relationship_source_ticket_id = $ticket_id
            OR ticket_relationship_target_ticket_id = $ticket_id) AS has_relationship,
        EXISTS (SELECT 1 FROM ticket_customer_promises
            WHERE ticket_customer_promise_ticket_id = $ticket_id) AS has_promise,
        EXISTS (SELECT 1 FROM ticket_customer_promise_events
            WHERE ticket_customer_promise_event_ticket_id = $ticket_id) AS has_promise_event,
        EXISTS (SELECT 1 FROM ticket_email_ingress
            WHERE ticket_email_ingress_ticket_id = $ticket_id) AS has_email_ingress",
        'Could not inspect immutable ticket operational history'));
    foreach ($artifacts ?: [] as $present) {
        if (intval($present) === 1) {
            return true;
        }
    }
    return false;
}

function ticketOperationalRelationships(int $ticket_id)
{
    $has_retention = ticketOperationalSoftDeleteColumn() === 'ticket_deleted_at';
    $source_available = $has_retention ? 'source.ticket_deleted_at IS NULL' : '1';
    $target_available = $has_retention ? 'target.ticket_deleted_at IS NULL' : '1';
    return ticketOperationalDbQuery("SELECT relationships.*,
        CASE WHEN $source_available THEN source.ticket_prefix ELSE NULL END AS source_prefix,
        CASE WHEN $source_available THEN source.ticket_number ELSE NULL END AS source_number,
        CASE WHEN $source_available THEN source.ticket_subject ELSE '[Deleted ticket unavailable]' END AS source_subject,
        ($source_available) AS source_available,
        CASE WHEN $target_available THEN target.ticket_prefix ELSE NULL END AS target_prefix,
        CASE WHEN $target_available THEN target.ticket_number ELSE NULL END AS target_number,
        CASE WHEN $target_available THEN target.ticket_subject ELSE '[Deleted ticket unavailable]' END AS target_subject,
        ($target_available) AS target_available
        FROM ticket_relationships relationships
        JOIN tickets source ON source.ticket_id = ticket_relationship_source_ticket_id
        JOIN tickets target ON target.ticket_id = ticket_relationship_target_ticket_id
        WHERE ticket_relationship_source_ticket_id = $ticket_id
        OR ticket_relationship_target_ticket_id = $ticket_id
        ORDER BY ticket_relationship_type, ticket_relationship_id",
        'Could not load ticket relationships');
}

function ticketOperationalRecordPromiseEvent(
    int $promise_id,
    int $ticket_id,
    int $client_id,
    string $action,
    ?string $from_status,
    string $to_status,
    int $actor_id,
    string $actor_type,
    ?string $source_type = null,
    int $source_id = 0
): void {
    global $mysqli;
    $context = ticketOperationalCanonicalJson([
        'promise_id' => $promise_id,
        'ticket_id' => $ticket_id,
        'action' => $action,
        'from_status' => $from_status,
        'to_status' => $to_status,
        'source_type' => $source_type,
        'source_id' => $source_id,
    ]);
    $hash_sql = mysqli_real_escape_string($mysqli, hash('sha256', $context));
    $action_sql = mysqli_real_escape_string($mysqli, substr($action, 0, 30));
    $from_sql = $from_status === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $from_status) . "'";
    $to_sql = mysqli_real_escape_string($mysqli, $to_status);
    $actor_type_sql = mysqli_real_escape_string($mysqli, $actor_type);
    $source_type_sql = $source_type === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $source_type) . "'";
    ticketOperationalDbQuery("INSERT INTO ticket_customer_promise_events SET
        ticket_customer_promise_event_promise_id = $promise_id,
        ticket_customer_promise_event_ticket_id = $ticket_id,
        ticket_customer_promise_event_client_id = $client_id,
        ticket_customer_promise_event_action = '$action_sql',
        ticket_customer_promise_event_from_status = $from_sql,
        ticket_customer_promise_event_to_status = '$to_sql',
        ticket_customer_promise_event_actor_type = '$actor_type_sql',
        ticket_customer_promise_event_actor_id = $actor_id,
        ticket_customer_promise_event_source_type = $source_type_sql,
        ticket_customer_promise_event_source_id = $source_id,
        ticket_customer_promise_event_context_hash = '$hash_sql'",
        'Could not record the customer promise event');
}

function ticketOperationalCreatePromise(
    int $ticket_id,
    $promise_type,
    $summary,
    $due_at,
    int $actor_id,
    string $source_type = 'agent',
    int $source_id = 0
): int {
    global $mysqli;
    $promise_type = ticketOperationalNormalizeKey($promise_type, ticketOperationalPromiseTypes(), 'promise type');
    $summary = trim((string) $summary);
    if ($summary === '' || mb_strlen($summary) > 500) {
        throw new InvalidArgumentException('Promise summary is required and must be 500 characters or fewer');
    }
    $due_at = ticketOperationalNormalizeDateTime($due_at);
    if ($due_at === null || strtotime($due_at) <= time()) {
        throw new InvalidArgumentException('Promise due time must be in the future');
    }
    if (!in_array($source_type, ['agent', 'api', 'automation', 'email', 'runbook', 'system'], true)) {
        throw new InvalidArgumentException('Invalid customer promise source');
    }
    $type_sql = mysqli_real_escape_string($mysqli, $promise_type);
    $summary_sql = mysqli_real_escape_string($mysqli, $summary);
    $due_sql = mysqli_real_escape_string($mysqli, $due_at);
    $source_type_sql = mysqli_real_escape_string($mysqli, $source_type);
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $advisory = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1", 'Could not locate the promise ticket'));
    if (!$advisory) {
        throw new RuntimeException('Ticket not found');
    }
    $advisory_client_id = intval($advisory['ticket_client_id']);
    $transaction_open = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin customer promise creation');
        }
        $transaction_open = true;
        if ($advisory_client_id > 0) {
            documentationLockClient($advisory_client_id);
        }
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
            ticket_status, ticket_closed_at, ticket_archived_at $projection FROM tickets
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            $active_scope LIMIT 1 FOR UPDATE",
            'Could not lock the promise ticket'));
        if (!$ticket || intval($ticket['ticket_client_id']) !== $advisory_client_id
            || ticketOperationalTicketIsImmutable($ticket)) {
            throw new DomainException('Promises can only be added to active tickets');
        }
        $client_id = intval($ticket['ticket_client_id']);

        // Replayed integrations use their source identity. Manual double-clicks
        // are deduplicated by the exact still-active promise contents.
        $source_identity = $source_id > 0
            ? "ticket_customer_promise_source_type = '$source_type_sql'
                AND ticket_customer_promise_source_id = $source_id"
            : "ticket_customer_promise_summary = '$summary_sql'
                AND ticket_customer_promise_due_at = '$due_sql'
                AND ticket_customer_promise_status IN ('Open','Breached')";
        $existing = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_customer_promise_id
            FROM ticket_customer_promises WHERE ticket_customer_promise_ticket_id = $ticket_id
            AND ticket_customer_promise_type = '$type_sql' AND $source_identity
            ORDER BY ticket_customer_promise_id DESC LIMIT 1 FOR UPDATE",
            'Could not inspect an idempotent customer promise'));
        if ($existing) {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the customer promise replay');
            }
            $transaction_open = false;
            return intval($existing['ticket_customer_promise_id']);
        }

        ticketOperationalDbQuery("INSERT INTO ticket_customer_promises SET
            ticket_customer_promise_ticket_id = $ticket_id,
            ticket_customer_promise_client_id = $client_id,
            ticket_customer_promise_type = '$type_sql',
            ticket_customer_promise_summary = '$summary_sql',
            ticket_customer_promise_due_at = '$due_sql',
            ticket_customer_promise_promised_by = $actor_id,
            ticket_customer_promise_source_type = '$source_type_sql',
            ticket_customer_promise_source_id = $source_id",
            'Could not create the customer promise');
        $promise_id = intval(mysqli_insert_id($mysqli));
        $event_actor_type = in_array($source_type, ['agent', 'api', 'automation', 'email', 'system'], true)
            ? $source_type : 'system';
        ticketOperationalRecordPromiseEvent($promise_id, $ticket_id, $client_id, 'Created', null,
            'Open', $actor_id, $event_actor_type, $source_type, $source_id);
        ticketOperationalRecordEvent($ticket_id, $client_id, 'PromiseCreated', [
            'promise_id' => $promise_id,
            'promise_type' => $promise_type,
            'due_at' => $due_at,
        ], $actor_id, $event_actor_type);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit customer promise creation');
        }
        $transaction_open = false;
        return $promise_id;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalFulfillPromisesLocked(
    int $ticket_id,
    string $promise_type,
    int $actor_id,
    string $actor_type,
    int $source_id = 0
): int {
    global $mysqli;
    $promise_type = ticketOperationalNormalizeKey($promise_type, ticketOperationalPromiseTypes(), 'promise type');
    $type_sql = mysqli_real_escape_string($mysqli, $promise_type);
    $promises = ticketOperationalDbQuery("SELECT * FROM ticket_customer_promises
        WHERE ticket_customer_promise_ticket_id = $ticket_id
        AND ticket_customer_promise_type = '$type_sql'
        AND ticket_customer_promise_status IN ('Open','Breached')
        ORDER BY ticket_customer_promise_id FOR UPDATE",
        'Could not lock customer promises for fulfillment');
    $count = 0;
    $client_id = 0;
    while ($promise = mysqli_fetch_assoc($promises)) {
        $promise_id = intval($promise['ticket_customer_promise_id']);
        $from_status = (string) $promise['ticket_customer_promise_status'];
        ticketOperationalDbQuery("UPDATE ticket_customer_promises SET
            ticket_customer_promise_status = 'Fulfilled',
            ticket_customer_promise_fulfilled_by = $actor_id,
            ticket_customer_promise_fulfilled_at = NOW()
            WHERE ticket_customer_promise_id = $promise_id
            AND ticket_customer_promise_status = '" . mysqli_real_escape_string($mysqli, $from_status) . "'",
            'Could not fulfill the customer promise');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The customer promise changed before fulfillment');
        }
        ticketOperationalRecordPromiseEvent($promise_id, $ticket_id,
            intval($promise['ticket_customer_promise_client_id']), 'Fulfilled', $from_status,
            'Fulfilled', $actor_id, $actor_type, $actor_type, $source_id);
        $client_id = intval($promise['ticket_customer_promise_client_id']);
        $count++;
    }
    if ($count > 0) {
        ticketOperationalRecordEvent($ticket_id, $client_id, 'PromiseFulfilled', [
            'promise_type' => $promise_type,
            'fulfilled_count' => $count,
            'source_id' => $source_id,
        ], $actor_id, $actor_type);
    }
    return $count;
}

/**
 * Guarded public fulfillment entry point. Lifecycle code that already holds
 * the canonical client -> ticket locks uses the Locked helper so resolution
 * can fulfill promises after the terminal status write in the same transaction.
 */
function ticketOperationalFulfillPromises(
    int $ticket_id,
    string $promise_type,
    int $actor_id,
    string $actor_type,
    int $source_id = 0
): int {
    global $mysqli;
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $advisory = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1", 'Could not locate the promise ticket'));
    if (!$advisory) {
        throw new RuntimeException('Ticket not found');
    }
    $client_id = intval($advisory['ticket_client_id']);
    $transaction_open = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin customer promise fulfillment');
        }
        $transaction_open = true;
        if ($client_id > 0) {
            documentationLockClient($client_id);
        }
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
            ticket_status, ticket_closed_at, ticket_archived_at $projection FROM tickets
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            $active_scope LIMIT 1 FOR UPDATE",
            'Could not lock the promise fulfillment ticket'));
        if (!$ticket || intval($ticket['ticket_client_id']) !== $client_id
            || ticketOperationalTicketIsImmutable($ticket)) {
            throw new DomainException('Promises can only be fulfilled on active tickets');
        }
        $count = ticketOperationalFulfillPromisesLocked(
            $ticket_id, $promise_type, $actor_id, $actor_type, $source_id
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit customer promise fulfillment');
        }
        $transaction_open = false;
        return $count;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalCancelPromise(int $promise_id, int $ticket_id, int $actor_id): void
{
    global $mysqli;
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $advisory = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
        $active_scope LIMIT 1", 'Could not locate the customer promise ticket'));
    if (!$advisory) {
        throw new RuntimeException('Ticket not found');
    }
    $advisory_client_id = intval($advisory['ticket_client_id']);
    $transaction_open = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin customer promise cancellation');
        }
        $transaction_open = true;
        if ($advisory_client_id > 0) {
            documentationLockClient($advisory_client_id);
        }
        $projection = ticketOperationalSoftDeleteProjection('tickets');
        $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
            ticket_status, ticket_closed_at, ticket_archived_at $projection FROM tickets
            WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
            $active_scope LIMIT 1 FOR UPDATE",
            'Could not lock the customer promise ticket'));
        if (!$ticket || intval($ticket['ticket_client_id']) !== $advisory_client_id
            || ticketOperationalTicketIsImmutable($ticket)) {
            throw new DomainException('Promises on terminal tickets are immutable');
        }
        $promise = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT * FROM ticket_customer_promises
            WHERE ticket_customer_promise_id = $promise_id
            AND ticket_customer_promise_ticket_id = $ticket_id
            AND ticket_customer_promise_status IN ('Open','Breached') LIMIT 1 FOR UPDATE",
            'Could not lock the customer promise'));
        if (!$promise || intval($promise['ticket_customer_promise_client_id']) !== intval($ticket['ticket_client_id'])) {
            throw new RuntimeException('Open customer promise not found');
        }
        $from_status = (string) $promise['ticket_customer_promise_status'];
        ticketOperationalDbQuery("UPDATE ticket_customer_promises SET
            ticket_customer_promise_status = 'Cancelled',
            ticket_customer_promise_cancelled_by = $actor_id,
            ticket_customer_promise_cancelled_at = NOW()
            WHERE ticket_customer_promise_id = $promise_id
            AND ticket_customer_promise_status = '" . mysqli_real_escape_string($mysqli, $from_status) . "'",
            'Could not cancel the customer promise');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The customer promise changed before cancellation');
        }
        $client_id = intval($promise['ticket_customer_promise_client_id']);
        ticketOperationalRecordPromiseEvent($promise_id, $ticket_id, $client_id,
            'Cancelled', $from_status, 'Cancelled', $actor_id, 'agent');
        ticketOperationalRecordEvent($ticket_id, $client_id, 'PromiseCancelled', [
            'promise_id' => $promise_id,
            'from_status' => $from_status,
        ], $actor_id, 'agent');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit customer promise cancellation');
        }
        $transaction_open = false;
    } catch (Throwable $e) {
        if ($transaction_open) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

function ticketOperationalReconcilePromises(int $limit = 200): int
{
    global $mysqli;
    $limit = max(1, min(1000, $limit));
    $active_scope = ticketOperationalActiveTicketSql('tickets');
    $due = ticketOperationalDbQuery("SELECT ticket_customer_promise_id,
        ticket_customer_promise_ticket_id, ticket_customer_promise_client_id
        FROM ticket_customer_promises
        INNER JOIN tickets ON ticket_id = ticket_customer_promise_ticket_id
        WHERE tickets.ticket_deleted_at IS NULL
        AND ticket_customer_promise_status = 'Open'
        AND ticket_customer_promise_due_at < NOW() $active_scope
        ORDER BY ticket_customer_promise_due_at, ticket_customer_promise_id LIMIT $limit",
        'Could not select overdue customer promises');
    $count = 0;
    while ($row = mysqli_fetch_assoc($due)) {
        $promise_id = intval($row['ticket_customer_promise_id']);
        $ticket_id = intval($row['ticket_customer_promise_ticket_id']);
        $client_id = intval($row['ticket_customer_promise_client_id']);
        $transaction_open = false;
        try {
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin customer promise reconciliation');
            }
            $transaction_open = true;
            if ($client_id > 0) {
                documentationLockClient($client_id);
            }
            $projection = ticketOperationalSoftDeleteProjection('tickets');
            $ticket = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_client_id,
                ticket_status, ticket_closed_at, ticket_archived_at $projection FROM tickets
                WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
                $active_scope LIMIT 1 FOR UPDATE",
                'Could not lock an overdue promise ticket'));
            if (!$ticket || intval($ticket['ticket_client_id']) !== $client_id
                || ticketOperationalTicketIsImmutable($ticket)) {
                mysqli_rollback($mysqli);
                $transaction_open = false;
                continue;
            }
            $promise = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT * FROM ticket_customer_promises
                WHERE ticket_customer_promise_id = $promise_id
                AND ticket_customer_promise_ticket_id = $ticket_id
                AND ticket_customer_promise_client_id = $client_id FOR UPDATE",
                'Could not lock an overdue customer promise'));
            if (!$promise || $promise['ticket_customer_promise_status'] !== 'Open'
                || strtotime((string) $promise['ticket_customer_promise_due_at']) >= time()) {
                mysqli_rollback($mysqli);
                $transaction_open = false;
                continue;
            }
            ticketOperationalDbQuery("UPDATE ticket_customer_promises SET
                ticket_customer_promise_status = 'Breached',
                ticket_customer_promise_breached_at = NOW()
                WHERE ticket_customer_promise_id = $promise_id
                AND ticket_customer_promise_status = 'Open'",
                'Could not mark the customer promise breached');
            ticketOperationalRecordPromiseEvent($promise_id,
                intval($promise['ticket_customer_promise_ticket_id']),
                intval($promise['ticket_customer_promise_client_id']),
                'Breached', 'Open', 'Breached', 0, 'system');
            ticketOperationalRecordEvent(intval($promise['ticket_customer_promise_ticket_id']),
                intval($promise['ticket_customer_promise_client_id']), 'PromiseBreached', [
                    'promise_id' => $promise_id,
                    'promise_type' => $promise['ticket_customer_promise_type'],
                    'due_at' => $promise['ticket_customer_promise_due_at'],
                ], 0, 'system');
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit customer promise reconciliation');
            }
            $transaction_open = false;
            if (function_exists('appNotify')) {
                $ticket_id = intval($promise['ticket_customer_promise_ticket_id']);
                appNotify('Ticket Promise', "Customer promise #$promise_id is overdue",
                    "/agent/ticket.php?ticket_id=$ticket_id", intval($promise['ticket_customer_promise_client_id']), $ticket_id);
            }
            $count++;
        } catch (Throwable $e) {
            if ($transaction_open) {
                mysqli_rollback($mysqli);
            }
            throw $e;
        }
    }
    return $count;
}

function ticketOperationalPromises(int $ticket_id)
{
    return ticketOperationalDbQuery("SELECT * FROM ticket_customer_promises
        WHERE ticket_customer_promise_ticket_id = $ticket_id
        ORDER BY FIELD(ticket_customer_promise_status, 'Breached','Open','Fulfilled','Cancelled'),
        ticket_customer_promise_due_at DESC, ticket_customer_promise_id DESC",
        'Could not load customer promises');
}

function ticketEmailIngressFingerprint(string $message_id, string $sender, string $subject, string $date, string $body_hash): string
{
    $message_id = strtolower(trim($message_id, " \t\r\n<>"));
    return hash('sha256', "v2\n" . strtolower(trim($sender)) . "\n"
        . $message_id . "\n" . trim($date) . "\n" . trim($subject) . "\n" . strtolower(trim($body_hash)));
}

function ticketEmailIngressClaim(string $message_hash, string $sender, string $subject): array
{
    global $mysqli;
    if (!preg_match('/^[0-9a-f]{64}$/', $message_hash)) {
        throw new InvalidArgumentException('Invalid inbound message fingerprint');
    }
    $message_hash_sql = mysqli_real_escape_string($mysqli, $message_hash);
    $claim_token = hash('sha256', random_bytes(32));
    $claim_token_sql = mysqli_real_escape_string($mysqli, $claim_token);
    $sender_hash_sql = mysqli_real_escape_string($mysqli, hash('sha256', strtolower(trim($sender))));
    $sender_parts = explode('@', strtolower(trim($sender)));
    $sender_domain = count($sender_parts) === 2 ? (string) end($sender_parts) : '';
    $domain_hash_sql = mysqli_real_escape_string($mysqli, hash('sha256', $sender_domain));
    $subject_hash_sql = mysqli_real_escape_string($mysqli, hash('sha256', trim($subject)));
    ticketOperationalDbQuery("INSERT IGNORE INTO ticket_email_ingress SET
        ticket_email_ingress_message_hash = '$message_hash_sql',
        ticket_email_ingress_claim_token = '$claim_token_sql',
        ticket_email_ingress_sender_hash = '$sender_hash_sql',
        ticket_email_ingress_domain_hash = '$domain_hash_sql',
        ticket_email_ingress_subject_hash = '$subject_hash_sql',
        ticket_email_ingress_status = 'Processing',
        ticket_email_ingress_processing_at = NOW()",
        'Could not claim the inbound message');
    if (mysqli_affected_rows($mysqli) === 1) {
        return ['claimed' => true, 'id' => intval(mysqli_insert_id($mysqli)),
            'status' => 'Processing', 'token' => $claim_token];
    }
    $row = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_email_ingress_id,
        ticket_email_ingress_status, ticket_email_ingress_processing_at,
        ticket_email_ingress_claim_token
        FROM ticket_email_ingress WHERE ticket_email_ingress_message_hash = '$message_hash_sql'
        LIMIT 1", 'Could not inspect the inbound message claim'));
    if (!$row) {
        throw new RuntimeException('Inbound message claim disappeared');
    }
    $status = (string) $row['ticket_email_ingress_status'];
    $stale = $status === 'Failed'
        || ($status === 'Processing' && strtotime((string) $row['ticket_email_ingress_processing_at']) < time() - 900);
    if ($stale) {
        $ingress_id = intval($row['ticket_email_ingress_id']);
        $prior_token_sql = mysqli_real_escape_string($mysqli, (string) $row['ticket_email_ingress_claim_token']);
        ticketOperationalDbQuery("UPDATE ticket_email_ingress SET
            ticket_email_ingress_status = 'Processing',
            ticket_email_ingress_claim_token = '$claim_token_sql',
            ticket_email_ingress_attempts = ticket_email_ingress_attempts + 1,
            ticket_email_ingress_processing_at = NOW(),
            ticket_email_ingress_completed_at = NULL,
            ticket_email_ingress_reason_code = NULL
            WHERE ticket_email_ingress_id = $ingress_id
            AND ticket_email_ingress_status = '" . mysqli_real_escape_string($mysqli, $status) . "'
            AND ticket_email_ingress_claim_token = '$prior_token_sql'",
            'Could not reclaim the inbound message');
        return ['claimed' => mysqli_affected_rows($mysqli) === 1, 'id' => $ingress_id,
            'status' => 'Processing', 'token' => $claim_token];
    }
    return ['claimed' => false, 'id' => intval($row['ticket_email_ingress_id']),
        'status' => $status, 'token' => ''];
}

function ticketEmailIngressRateLimitReason(int $ingress_id, string $claim_token, int $client_id = 0): ?string
{
    global $mysqli;
    if ($ingress_id < 1 || !preg_match('/^[0-9a-f]{64}$/', $claim_token)) {
        throw new InvalidArgumentException('A valid inbound claim is required for rate limiting');
    }
    $claim_token_sql = mysqli_real_escape_string($mysqli, $claim_token);
    if ($client_id > 0) {
        // Bind the claim before counting so concurrent in-flight mail for the
        // same tenant participates in the client window, not only completed
        // ingress rows. Ownership CAS prevents another worker rebinding it.
        ticketOperationalDbQuery("UPDATE ticket_email_ingress SET
            ticket_email_ingress_client_id = $client_id
            WHERE ticket_email_ingress_id = $ingress_id
            AND ticket_email_ingress_status = 'Processing'
            AND ticket_email_ingress_claim_token = '$claim_token_sql'
            AND ticket_email_ingress_client_id IN (0, $client_id)",
            'Could not bind inbound rate-limit identity');
    }
    $row = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT ticket_email_ingress_sender_hash,
        ticket_email_ingress_domain_hash, ticket_email_ingress_client_id FROM ticket_email_ingress
        WHERE ticket_email_ingress_id = $ingress_id
        AND ticket_email_ingress_status = 'Processing'
        AND ticket_email_ingress_claim_token = '$claim_token_sql' LIMIT 1",
        'Could not inspect inbound rate-limit identity'));
    if (!$row) {
        throw new RuntimeException('Inbound message claim ownership changed before rate limiting');
    }
    if ($client_id > 0 && intval($row['ticket_email_ingress_client_id']) !== $client_id) {
        throw new RuntimeException('Inbound message client binding changed before rate limiting');
    }
    $sender_hash = mysqli_real_escape_string($mysqli, (string) $row['ticket_email_ingress_sender_hash']);
    $domain_hash = mysqli_real_escape_string($mysqli, (string) $row['ticket_email_ingress_domain_hash']);
    $counts = mysqli_fetch_assoc(ticketOperationalDbQuery("SELECT
        (SELECT COUNT(*) FROM ticket_email_ingress
            WHERE ticket_email_ingress_received_at >= NOW() - INTERVAL 10 MINUTE) AS global_window,
        (SELECT COUNT(*) FROM ticket_email_ingress
            WHERE ticket_email_ingress_sender_hash = '$sender_hash'
            AND ticket_email_ingress_received_at >= NOW() - INTERVAL 1 HOUR) AS sender_window,
        (SELECT COUNT(*) FROM ticket_email_ingress
            WHERE ticket_email_ingress_domain_hash = '$domain_hash'
            AND ticket_email_ingress_received_at >= NOW() - INTERVAL 1 HOUR) AS domain_window",
        'Could not evaluate inbound rate limits'));
    if (intval($counts['global_window'] ?? 0) > 500) {
        return 'global_rate_limit';
    }
    if (intval($counts['sender_window'] ?? 0) > 20) {
        return 'sender_rate_limit';
    }
    if (intval($counts['domain_window'] ?? 0) > 100) {
        return 'domain_rate_limit';
    }
    if ($client_id > 0) {
        $client_count = mysqli_fetch_row(ticketOperationalDbQuery("SELECT COUNT(*)
            FROM ticket_email_ingress WHERE ticket_email_ingress_client_id = $client_id
            AND ticket_email_ingress_received_at >= NOW() - INTERVAL 1 HOUR",
            'Could not evaluate the client inbound rate limit'));
        if (intval($client_count[0] ?? 0) > 200) {
            return 'client_rate_limit';
        }
    }
    return null;
}

function ticketEmailIngressComplete(
    int $ingress_id,
    string $claim_token,
    string $status,
    int $ticket_id = 0,
    int $reply_id = 0,
    ?string $reason_code = null,
    int $client_id = 0
): void {
    global $mysqli;
    if (!in_array($status, ['Processed', 'Rejected', 'Failed'], true)) {
        throw new InvalidArgumentException('Invalid inbound message terminal state');
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $claim_token)) {
        throw new InvalidArgumentException('Invalid inbound message claim token');
    }
    $status_sql = mysqli_real_escape_string($mysqli, $status);
    $claim_token_sql = mysqli_real_escape_string($mysqli, $claim_token);
    $reason_code = $reason_code === null ? null : preg_replace('/[^a-z0-9_-]/', '', strtolower($reason_code));
    $reason_sql = $reason_code === null || $reason_code === ''
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, substr($reason_code, 0, 60)) . "'";
    ticketOperationalDbQuery("UPDATE ticket_email_ingress SET
        ticket_email_ingress_status = '$status_sql',
        ticket_email_ingress_ticket_id = $ticket_id,
        ticket_email_ingress_reply_id = $reply_id,
        ticket_email_ingress_client_id = $client_id,
        ticket_email_ingress_reason_code = $reason_sql,
        ticket_email_ingress_completed_at = NOW()
        WHERE ticket_email_ingress_id = $ingress_id
        AND ticket_email_ingress_status = 'Processing'
        AND ticket_email_ingress_claim_token = '$claim_token_sql'",
        'Could not complete the inbound message claim');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('Inbound message claim ownership changed before completion');
    }
}

function ticketEmailRawHeaderValues(string $raw_message, string $header_name): array
{
    $header_name = strtolower(trim($header_name));
    if ($header_name === '' || !preg_match('/^[a-z0-9-]+$/', $header_name)) {
        throw new InvalidArgumentException('Invalid email header name');
    }
    $header_block = preg_split("/\r?\n\r?\n/", $raw_message, 2)[0] ?? '';
    $lines = preg_split('/\r?\n/', $header_block) ?: [];
    $unfolded = [];
    foreach ($lines as $line) {
        if (preg_match('/^[ \t]/', $line) && $unfolded) {
            $unfolded[count($unfolded) - 1] .= ' ' . trim($line);
        } else {
            $unfolded[] = $line;
        }
    }
    $values = [];
    foreach ($unfolded as $line) {
        if (preg_match('/^' . preg_quote($header_name, '/') . '\s*:\s*(.*)$/i', $line, $match)) {
            $values[] = trim($match[1]);
        }
    }
    return $values;
}

function ticketEmailAuthenticationIdentityDomain(string $identity): string
{
    $identity = strtolower(trim($identity, "<>. \t\r\n"));
    return str_contains($identity, '@')
        ? trim(substr($identity, strrpos($identity, '@') + 1), '.')
        : trim($identity, '.');
}

/**
 * Only the first Authentication-Results field may authorize tenant-bound
 * intake. The receiving gateway prepends that field; accepting a later field
 * would let a sender inject its own pass result. Unknown/custom gateways fail
 * closed until an administrator supplies a supported trusted ingress path.
 */
function ticketEmailTrustedAuthentication(
    string $raw_message,
    string $provider,
    string $imap_host,
    string $from_domain
): bool {
    $from_domain = strtolower(trim($from_domain, ". \t\r\n"));
    if ($from_domain === '') {
        return false;
    }
    $results = ticketEmailRawHeaderValues($raw_message, 'Authentication-Results');
    if (!$results) {
        return false;
    }
    $authentication_results = strtolower($results[0]);
    $authserv_id = strtolower(trim((string) (strtok($authentication_results, ';') ?: '')));
    $provider = strtolower(trim($provider));
    $imap_host = strtolower(trim($imap_host, '.'));
    $is_google = $provider === 'google_oauth' || $imap_host === 'imap.gmail.com';
    $is_microsoft = $provider === 'microsoft_oauth' || $imap_host === 'outlook.office365.com';
    $trusted_authserv = ($is_google && hash_equals('mx.google.com', $authserv_id))
        || ($is_microsoft && preg_match('/(?:^|\.)(?:protection\.outlook\.com|prod\.outlook\.com)$/', $authserv_id));
    if (!$trusted_authserv) {
        return false;
    }
    if (!preg_match('/\bdmarc\s*=\s*pass\b/', $authentication_results)
        || !preg_match('/\bheader\.from\s*=\s*<?([a-z0-9._-]+)/', $authentication_results, $dmarc)
        || !hash_equals($from_domain, strtolower(trim($dmarc[1], '.')))) {
        return false;
    }
    $dkim_aligned = preg_match('/\bdkim\s*=\s*pass\b[^;]*\bheader\.d\s*=\s*([a-z0-9._-]+)/',
        $authentication_results, $dkim)
        && hash_equals($from_domain, strtolower(trim($dkim[1], '.')));
    $spf_aligned = preg_match('/\bspf\s*=\s*pass\b[^;]*\bsmtp\.mailfrom\s*=\s*<?([^\s;>]+)/',
        $authentication_results, $spf)
        && hash_equals($from_domain, ticketEmailAuthenticationIdentityDomain($spf[1]));
    return (bool) ($dkim_aligned || $spf_aligned);
}

function ticketEmailStructuredDsn(string $delivery_status_body): ?array
{
    if (!preg_match('/(?:^|\r?\n)Final-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)/i',
        $delivery_status_body, $recipient)
        || !filter_var($recipient[1], FILTER_VALIDATE_EMAIL)
        || !preg_match('/(?:^|\r?\n)Action:\s*failed\s*(?:\r?\n|$)/i', $delivery_status_body)
        || !preg_match('/(?:^|\r?\n)Status:\s*(5(?:\.[0-9]{1,3}){2})\s*(?:\r?\n|$)/i',
            $delivery_status_body, $status)) {
        return null;
    }
    preg_match('/(?:^|\r?\n)Diagnostic-Code:\s*([^\r\n]{1,1000})/i',
        $delivery_status_body, $diagnostic);
    return [
        'recipient' => strtolower($recipient[1]),
        'status' => $status[1],
        'diagnostic' => trim((string) ($diagnostic[1] ?? 'No diagnostic supplied')),
    ];
}

function ticketEmailAttachmentAllowed(string $name, string $mime_type, int $size, ?string $content = null): bool
{
    if ($size < 1 || $size > 15728640 || strpos($name, "\0") !== false || basename($name) !== $name) {
        return false;
    }
    if ($content !== null && strlen($content) !== $size) {
        return false;
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime_type = strtolower(trim(explode(';', $mime_type)[0]));
    $allowed = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'md' => ['text/plain', 'text/markdown'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xlsm' => ['application/vnd.ms-excel.sheet.macroenabled.12', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'tar' => ['application/x-tar'], 'gz' => ['application/gzip', 'application/x-gzip'],
    ];
    if (!isset($allowed[$extension]) || !in_array($mime_type, $allowed[$extension], true)) {
        return false;
    }
    if ($content !== null && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? strtolower((string) finfo_buffer($finfo, $content)) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($detected !== '' && !in_array($detected, $allowed[$extension], true)) {
            return false;
        }
    }
    return true;
}

function ticketEmailSanitizeInboundHtml(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|link|meta|base|svg|math)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|link|meta|base|svg|math)\b[^>]*/?>#is', '', $html);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/\s+(?:style|srcdoc|action|formaction|xlink:href)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace_callback('/\s+(src|href)\s*=\s*(["\'])(.*?)\2/is', static function (array $match): string {
        $value = trim(html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || $value[0] === '#' || preg_match('#^(?:https?://|mailto:|cid:)#i', $value)) {
            return ' ' . strtolower($match[1]) . '=' . $match[2] . $match[3] . $match[2];
        }
        if (preg_match('#^data:image/(?:jpeg|png|gif|webp);base64,[a-z0-9+/=\s]+$#i', $value)) {
            return ' ' . strtolower($match[1]) . '=' . $match[2] . $match[3] . $match[2];
        }
        return '';
    }, $html);
    $html = preg_replace_callback('/\s+(src|href)\s*=\s*([^\s"\'>]+)/i', static function (array $match): string {
        $value = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || $value[0] === '#' || preg_match('#^(?:https?://|mailto:|cid:)#i', $value)) {
            return ' ' . strtolower($match[1]) . '=' . $match[2];
        }
        return '';
    }, $html);
    return trim((string) $html);
}
