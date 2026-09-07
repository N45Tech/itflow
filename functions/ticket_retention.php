<?php

/*
 * Client-scoped ticket retention and deliberate hard-deletion helpers.
 *
 * A ticket can participate in several append-only operational records. The
 * client policy decides whether a level-3 support user may override retention;
 * the caller still has to collect a reason and write the surviving audit log.
 */

function ticketDeletionPolicies(): array
{
    return ['override', 'strict'];
}

function ticketDeletionRestoreWindowDays(int $client_id = 0): int
{
    global $mysqli;

    if ($client_id < 1) {
        return 30;
    }
    $client = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT client_ticket_retention_days
        FROM clients WHERE client_id = " . intval($client_id) . " LIMIT 1",
        'Could not read the client ticket retention period'));
    if (!$client) {
        throw new RuntimeException('The ticket client no longer exists');
    }
    return ticketDeletionRetentionDays($client['client_ticket_retention_days']);
}

function ticketDeletionRetentionDays($value): int
{
    $days = filter_var($value, FILTER_VALIDATE_INT);
    if ($days === false || $days < 1 || $days > 3650) {
        throw new DomainException('Choose a ticket retention period between 1 and 3650 days.');
    }
    return $days;
}

function ticketDeletionNormalizePolicy($policy): string
{
    $policy = strtolower(trim((string) $policy));
    return in_array($policy, ticketDeletionPolicies(), true) ? $policy : 'strict';
}

function ticketDeletionPolicyLabel(string $policy): string
{
    return ticketDeletionNormalizePolicy($policy) === 'override'
        ? 'Retain with administrator override'
        : 'Strict retention';
}

function ticketDeletionPolicyForClient(int $client_id): string
{
    global $mysqli;

    // Internal tickets have no client record on which to store a policy. Keep
    // the same retain-by-default behavior, with a deliberate admin override.
    if ($client_id < 1) {
        return 'override';
    }

    $result = mysqli_query($mysqli, "SELECT client_ticket_retention_policy
        FROM clients WHERE client_id = $client_id LIMIT 1");
    if ($result === false) {
        throw new RuntimeException('Could not read the client ticket retention policy');
    }
    $client = mysqli_fetch_assoc($result);
    if (!$client) {
        throw new RuntimeException('The ticket client no longer exists');
    }

    return ticketDeletionNormalizePolicy($client['client_ticket_retention_policy'] ?? 'strict');
}

function ticketDeletionDbQuery(string $query, string $message)
{
    global $mysqli;

    $result = mysqli_query($mysqli, $query);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

/** Lock the client first, then the ticket, including archived clients/tickets. */
function ticketDeletionLockTicket(int $ticket_id, ?int $expected_client_id = null): array
{
    $ticket_id = intval($ticket_id);
    $prelock = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT ticket_client_id
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not locate the ticket for deletion'));
    if (!$prelock) {
        throw new RuntimeException('The ticket no longer exists');
    }
    $client_id = intval($prelock['ticket_client_id']);
    if ($expected_client_id !== null && $client_id !== intval($expected_client_id)) {
        throw new RuntimeException('The ticket client changed');
    }
    if ($client_id) {
        documentationLockClient($client_id, true);
    }
    $ticket = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT * FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE",
        'Could not lock the ticket for deletion'));
    if (!$ticket || intval($ticket['ticket_client_id']) !== $client_id) {
        throw new RuntimeException('The ticket client changed before it was locked');
    }
    return $ticket;
}

/**
 * Return the protected evidence categories currently tied to a ticket.
 * Query failures fail closed through the strict helpers used below.
 */
function ticketDeletionEvidenceSummary(int $ticket_id, int $client_id = 0): array
{
    $ticket_id = intval($ticket_id);
    $client_id = max(0, intval($client_id));
    if ($ticket_id < 1) {
        return [];
    }

    $workflow = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT
        EXISTS (SELECT 1 FROM runbook_executions
            WHERE runbook_execution_ticket_id = $ticket_id) AS has_runbook,
        EXISTS (SELECT 1 FROM task_state_events
            INNER JOIN tasks ON task_id = task_state_event_task_id
            WHERE task_ticket_id = $ticket_id) AS has_task_state,
        EXISTS (SELECT 1 FROM task_evidence
            INNER JOIN tasks ON task_id = task_evidence_task_id
            WHERE task_ticket_id = $ticket_id) AS has_task_evidence,
        EXISTS (SELECT 1 FROM client_documentation_obligations
            WHERE documentation_obligation_verification_ticket_id = $ticket_id)
            AS has_documentation_verification",
        'Could not inspect ticket workflow evidence'));

    $portal = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT
        EXISTS (SELECT 1 FROM portal_request_submissions
            WHERE portal_request_submission_ticket_id = $ticket_id) AS has_submission,
        EXISTS (SELECT 1 FROM portal_request_dispatch_outbox
            WHERE portal_request_dispatch_ticket_id = $ticket_id) AS has_dispatch",
        'Could not inspect ticket portal-request evidence'));

    $integration = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT
        EXISTS (SELECT 1 FROM automation_incidents
            WHERE automation_incident_ticket_id = $ticket_id) AS has_incident,
        EXISTS (SELECT 1 FROM automation_events
            WHERE automation_event_ticket_id = $ticket_id) AS has_event",
        'Could not inspect ticket integration evidence'));

    $operations = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT
        EXISTS (SELECT 1 FROM ticket_work_notes
            WHERE ticket_work_note_ticket_id = $ticket_id) AS has_work_notes,
        EXISTS (SELECT 1 FROM ticket_handoffs
            WHERE ticket_handoff_ticket_id = $ticket_id) AS has_handoffs,
        EXISTS (SELECT 1 FROM ticket_customer_promise_events
            WHERE ticket_customer_promise_event_ticket_id = $ticket_id) AS has_promises,
        EXISTS (SELECT 1 FROM ticket_resolution_events
            WHERE ticket_resolution_event_ticket_id = $ticket_id) AS has_resolutions",
        'Could not inspect ticket operational evidence'));

    $summary = [
        'workflow' => intval($workflow['has_runbook'] ?? 0) > 0
            || intval($workflow['has_task_state'] ?? 0) > 0
            || intval($workflow['has_task_evidence'] ?? 0) > 0,
        'approval' => ticketApprovalTicketHasAuditHistory($ticket_id),
        'documentation' => intval($workflow['has_documentation_verification'] ?? 0) > 0
            || documentationTicketHasAuditRecords($ticket_id)
            || documentationEvidenceReferenceInUse('ticket', $ticket_id, $client_id),
        'agreement' => agreementTicketHasAuditHistory($ticket_id, $client_id),
        'portal_request' => intval($portal['has_submission'] ?? 0) > 0
            || intval($portal['has_dispatch'] ?? 0) > 0,
        'integration' => intval($integration['has_incident'] ?? 0) > 0
            || intval($integration['has_event'] ?? 0) > 0,
        'operations' => intval($operations['has_work_notes'] ?? 0) > 0
            || intval($operations['has_handoffs'] ?? 0) > 0
            || intval($operations['has_promises'] ?? 0) > 0
            || intval($operations['has_resolutions'] ?? 0) > 0
            || (function_exists('fieldServiceHasHistory') && fieldServiceHasHistory($ticket_id))
            || assistanceHasHistory($ticket_id),
    ];

    return array_filter($summary);
}

function ticketDeletionEvidenceLabels(array $summary): array
{
    $labels = [
        'workflow' => 'workflow and task history',
        'approval' => 'approval decisions',
        'documentation' => 'documentation evidence',
        'agreement' => 'agreement and SLA decisions',
        'portal_request' => 'portal request history',
        'integration' => 'integration incident history',
        'operations' => 'work notes, handoffs, promises, and resolution history',
    ];

    $result = [];
    foreach (array_keys($summary) as $key) {
        if (isset($labels[$key])) {
            $result[] = $labels[$key];
        }
    }
    return $result;
}

function ticketDeletionOverrideReason($reason): string
{
    $reason = trim((string) $reason);
    if (strlen($reason) < 10 || strlen($reason) > 500) {
        throw new DomainException('Provide an override reason between 10 and 500 characters.');
    }
    return $reason;
}

function ticketDeletionReason($reason, string $label = 'deletion'): string
{
    $reason = trim((string) $reason);
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        throw new DomainException("Provide a $label reason between 3 and 500 characters.");
    }
    return $reason;
}

function ticketDeletionRecordEvent(
    array $ticket,
    string $action,
    int $actor_id,
    string $reason,
    string $policy,
    ?string $restore_until = null
): int {
    global $mysqli;

    if (!in_array($action, ['deleted', 'restored', 'purged'], true)) {
        throw new InvalidArgumentException('Unsupported ticket deletion event');
    }
    $ticket_id = intval($ticket['ticket_id'] ?? 0);
    if ($ticket_id < 1) {
        throw new InvalidArgumentException('A ticket is required for deletion history');
    }
    $client_id = max(0, intval($ticket['ticket_client_id'] ?? 0));
    $reference = mb_substr((string) ($ticket['ticket_prefix'] ?? '')
        . intval($ticket['ticket_number'] ?? 0), 0, 255);
    $subject = mb_substr(trim((string) ($ticket['ticket_subject'] ?? '')), 0, 500);
    $reason = ticketDeletionReason($reason, $action);
    $policy = ticketDeletionNormalizePolicy($policy);
    $context = [
        'ticket_id' => $ticket_id,
        'client_id' => $client_id,
        'reference' => $reference,
        'subject' => $subject,
        'action' => $action,
        'actor_id' => max(0, $actor_id),
        'reason' => $reason,
        'policy' => $policy,
        'restore_until' => $restore_until,
    ];
    $context_json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($context_json === false) {
        throw new RuntimeException('Could not serialize ticket deletion history');
    }
    $reference_sql = mysqli_real_escape_string($mysqli, $reference);
    $subject_sql = mysqli_real_escape_string($mysqli, $subject);
    $action_sql = mysqli_real_escape_string($mysqli, $action);
    $reason_sql = mysqli_real_escape_string($mysqli, $reason);
    $policy_sql = mysqli_real_escape_string($mysqli, $policy);
    $restore_until_sql = $restore_until === null
        ? 'NULL'
        : "'" . mysqli_real_escape_string($mysqli, $restore_until) . "'";
    $hash_sql = hash('sha256', $context_json);

    ticketDeletionDbQuery("INSERT INTO ticket_deletion_events SET
        ticket_deletion_event_ticket_id = $ticket_id,
        ticket_deletion_event_client_id = $client_id,
        ticket_deletion_event_ticket_reference = '$reference_sql',
        ticket_deletion_event_ticket_subject = '$subject_sql',
        ticket_deletion_event_action = '$action_sql',
        ticket_deletion_event_actor_id = " . max(0, $actor_id) . ",
        ticket_deletion_event_reason = '$reason_sql',
        ticket_deletion_event_policy = '$policy_sql',
        ticket_deletion_event_restore_until = $restore_until_sql,
        ticket_deletion_event_context_hash = '$hash_sql'",
        'Could not record ticket deletion history');
    return intval(mysqli_insert_id($mysqli));
}

/**
 * Mark a locked ticket deleted without destroying any child record or file.
 * The caller owns the transaction and client-before-ticket lock order.
 */
function ticketDeletionSoftDelete(int $ticket_id, int $actor_id, string $reason): array
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $actor_id = max(0, intval($actor_id));
    $reason = ticketDeletionReason($reason);
    $ticket = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_prefix, ticket_number, ticket_subject, ticket_archived_at
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE",
        'Could not lock the ticket for recoverable deletion'));
    if (!$ticket) {
        throw new RuntimeException('The ticket no longer exists');
    }
    if (!empty($ticket['ticket_archived_at'])) {
        throw new DomainException('This ticket is already in Deleted tickets.');
    }
    if (function_exists('fieldServicePendingWork') && fieldServicePendingWork($ticket_id)) {
        throw new DomainException('Finish the field visit and review its time before deleting this ticket.');
    }

    $restore_until = date('Y-m-d H:i:s', time() + ticketDeletionRestoreWindowDays(intval($ticket['ticket_client_id'])) * 86400);
    $reason_sql = mysqli_real_escape_string($mysqli, $reason);
    $restore_until_sql = mysqli_real_escape_string($mysqli, $restore_until);
    ticketDeletionDbQuery("UPDATE tickets SET ticket_archived_at = NOW(),
        ticket_deleted_by = $actor_id, ticket_delete_reason = '$reason_sql',
        ticket_restore_until = '$restore_until_sql'
        WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1",
        'Could not move the ticket to Deleted tickets');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The ticket changed before it could be deleted');
    }

    $policy = ticketDeletionPolicyForClient(intval($ticket['ticket_client_id']));
    ticketDeletionRecordEvent($ticket, 'deleted', $actor_id, $reason, $policy, $restore_until);
    $ticket['ticket_restore_until'] = $restore_until;
    return $ticket;
}

function ticketDeletionRestore(int $ticket_id, int $actor_id, string $reason): array
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $actor_id = max(0, intval($actor_id));
    $reason = ticketDeletionReason($reason, 'restore');
    $ticket = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_prefix, ticket_number, ticket_subject, ticket_archived_at,
        ticket_restore_until FROM tickets WHERE ticket_id = $ticket_id
        LIMIT 1 FOR UPDATE", 'Could not lock the ticket for restoration'));
    if (!$ticket || empty($ticket['ticket_archived_at'])) {
        throw new DomainException('This ticket is not deleted.');
    }
    // The retention deadline prevents early purge; it never prevents recovery.

    $archived_at_sql = mysqli_real_escape_string($mysqli, (string) $ticket['ticket_archived_at']);
    ticketDeletionDbQuery("UPDATE tickets SET ticket_archived_at = NULL,
        ticket_deleted_by = 0, ticket_delete_reason = NULL, ticket_restore_until = NULL
        WHERE ticket_id = $ticket_id AND ticket_archived_at = '$archived_at_sql' LIMIT 1",
        'Could not restore the ticket');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The deleted ticket changed before it could be restored');
    }

    $policy = ticketDeletionPolicyForClient(intval($ticket['ticket_client_id']));
    ticketDeletionRecordEvent($ticket, 'restored', $actor_id, $reason, $policy, null);
    return $ticket;
}

function ticketDeletionRequirePurgeEligible(array $ticket): void
{
    if (empty($ticket['ticket_archived_at'])) {
        throw new DomainException('Delete the ticket first. Permanent deletion is available only after the restore window.');
    }
    if (empty($ticket['ticket_restore_until'])
        || strtotime((string) $ticket['ticket_restore_until']) >= time()) {
        throw new DomainException('The minimum retention period has not ended. Restore the ticket or wait until permanent deletion is available.');
    }
}

/**
 * Remove ticket-owned records after the caller has locked the client/ticket,
 * checked policy, and written the surviving audit entry in its transaction.
 */
function ticketDeletionPurge(int $ticket_id): void
{
    global $mysqli, $session_user_id;

    $ticket_id = intval($ticket_id);
    if ($ticket_id < 1) {
        throw new InvalidArgumentException('A ticket is required for deletion');
    }

    $ticket = mysqli_fetch_assoc(ticketDeletionDbQuery("SELECT * FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE", 'Could not verify the purge target'));
    if (!$ticket) {
        throw new DomainException('The deleted ticket is unavailable.');
    }
    ticketDeletionRequirePurgeEligible($ticket);
    $client_id = intval($ticket['ticket_client_id']);
    if (ticketDeletionPolicyForClient($client_id) !== 'override'
        && ticketDeletionEvidenceSummary($ticket_id, $client_id)) {
        throw new DomainException('This client uses strict retention. Protected ticket evidence cannot be permanently deleted.');
    }

    // Cross-domain records keep their own audit value but no longer point at a
    // ticket that is intentionally being removed.
    ticketDeletionDbQuery("UPDATE asset_change_events SET asset_change_event_ticket_id = 0
        WHERE asset_change_event_ticket_id = $ticket_id", 'Could not unlink asset change history');
    ticketDeletionDbQuery("UPDATE portal_request_submissions SET portal_request_submission_ticket_id = NULL
        WHERE portal_request_submission_ticket_id = $ticket_id", 'Could not unlink portal request history');
    ticketDeletionDbQuery("UPDATE portal_request_dispatch_outbox SET portal_request_dispatch_ticket_id = 0
        WHERE portal_request_dispatch_ticket_id = $ticket_id", 'Could not unlink portal request delivery history');

    // Integration incidents and events are ticket-owned in the Operations UI.
    automationDeleteTicketOperations($ticket_id);
    assistancePurgeTicket($ticket_id);
    if (function_exists('fieldPurgeTicket')) {
        fieldPurgeTicket($ticket_id);
    }

    // If this ticket supplied a client's current documentation verification,
    // invalidate that projection before removing its Evidence Locker row. The
    // immutable obligation event survives and points to the deletion audit.
    $verification_rows = ticketDeletionDbQuery("SELECT *
        FROM client_documentation_obligations
        WHERE documentation_obligation_verification_ticket_id = $ticket_id
        ORDER BY documentation_obligation_id FOR UPDATE",
        'Could not lock documentation verifications supplied by the ticket');
    while ($obligation = mysqli_fetch_assoc($verification_rows)) {
        $obligation_id = intval($obligation['documentation_obligation_id']);
        $revision = intval($obligation['documentation_obligation_revision']);
        $old_base = (string) $obligation['documentation_obligation_base_status'];
        $old_effective = documentationObligationEffectiveStatus($obligation);
        $new_base = empty($obligation['documentation_obligation_applicable'])
            ? 'Not Applicable'
            : 'Draft';
        $new_base_sql = mysqli_real_escape_string($mysqli, $new_base);
        ticketDeletionDbQuery("UPDATE client_documentation_obligations SET
            documentation_obligation_base_status = '$new_base_sql',
            documentation_obligation_last_verified_at = NULL,
            documentation_obligation_next_review_at = NULL,
            documentation_obligation_stale_at = NULL,
            documentation_obligation_verification_source = NULL,
            documentation_obligation_verification_evidence_id = 0,
            documentation_obligation_verification_document_version_id = 0,
            documentation_obligation_verification_document_hash = NULL,
            documentation_obligation_verification_ticket_id = 0,
            documentation_obligation_evaluation_reason_code = 'ticket_evidence_deleted',
            documentation_obligation_evaluated_at = NOW(),
            documentation_obligation_revision = documentation_obligation_revision + 1
            WHERE documentation_obligation_id = $obligation_id
            AND documentation_obligation_revision = $revision",
            'Could not invalidate a documentation verification supplied by the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('A documentation verification changed before ticket deletion');
        }
        $obligation['documentation_obligation_base_status'] = $new_base;
        $obligation['documentation_obligation_last_verified_at'] = null;
        $obligation['documentation_obligation_next_review_at'] = null;
        $obligation['documentation_obligation_stale_at'] = null;
        $obligation['documentation_obligation_verification_evidence_id'] = 0;
        $obligation['documentation_obligation_verification_ticket_id'] = 0;
        $actor_id = intval($session_user_id ?? 0);
        documentationRecordObligationEvent(
            $obligation,
            'verification_invalidated',
            $old_base,
            $new_base,
            $old_effective,
            documentationObligationEffectiveStatus($obligation),
            $actor_id ? 'agent' : 'system',
            $actor_id,
            'ticket_evidence_deleted',
            'ticket',
            $ticket_id,
            ['ticket_id' => $ticket_id, 'deletion_override' => true]
        );
    }

    // Whole-ticket approvals.
    ticketDeletionDbQuery("DELETE FROM ticket_approval_events
        WHERE ticket_approval_event_ticket_id = $ticket_id", 'Could not delete ticket approval events');
    ticketDeletionDbQuery("DELETE FROM ticket_approvals
        WHERE ticket_approval_ticket_id = $ticket_id", 'Could not delete ticket approvals');

    // Operational-discipline projections and audit records belong to the
    // ticket. The dedicated deletion event remains client-scoped and survives.
    ticketDeletionDbQuery("DELETE FROM ticket_customer_promise_events
        WHERE ticket_customer_promise_event_ticket_id = $ticket_id",
        'Could not delete ticket customer-promise events');
    ticketDeletionDbQuery("DELETE FROM ticket_customer_promises
        WHERE ticket_customer_promise_ticket_id = $ticket_id",
        'Could not delete ticket customer promises');
    ticketDeletionDbQuery("DELETE FROM ticket_resolution_events
        WHERE ticket_resolution_event_ticket_id = $ticket_id",
        'Could not delete ticket resolution events');
    ticketDeletionDbQuery("DELETE FROM ticket_work_notes
        WHERE ticket_work_note_ticket_id = $ticket_id",
        'Could not delete ticket work notes');
    ticketDeletionDbQuery("DELETE FROM ticket_handoffs
        WHERE ticket_handoff_ticket_id = $ticket_id",
        'Could not delete ticket handoffs');
    ticketDeletionDbQuery("DELETE FROM ticket_relationships
        WHERE ticket_relationship_from_ticket_id = $ticket_id
        OR ticket_relationship_to_ticket_id = $ticket_id",
        'Could not delete ticket relationships');

    // Task-owned evidence and projections must be removed before their tasks.
    ticketDeletionDbQuery("DELETE task_approval_events FROM task_approval_events
        INNER JOIN tasks ON task_id = task_approval_event_task_id
        WHERE task_ticket_id = $ticket_id", 'Could not delete task approval events');
    ticketDeletionDbQuery("DELETE task_approvals FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        WHERE task_ticket_id = $ticket_id", 'Could not delete task approvals');
    ticketDeletionDbQuery("DELETE task_state_events FROM task_state_events
        INNER JOIN tasks ON task_id = task_state_event_task_id
        WHERE task_ticket_id = $ticket_id", 'Could not delete task state history');
    ticketDeletionDbQuery("DELETE task_evidence FROM task_evidence
        INNER JOIN tasks ON task_id = task_evidence_task_id
        WHERE task_ticket_id = $ticket_id", 'Could not delete task evidence');
    ticketDeletionDbQuery("DELETE task_dependencies FROM task_dependencies
        INNER JOIN tasks ON tasks.task_id = task_dependencies.task_id
            OR tasks.task_id = task_dependencies.depends_on_task_id
        WHERE tasks.task_ticket_id = $ticket_id", 'Could not delete task dependencies');

    // Documentation rows whose identity is the ticket.
    ticketDeletionDbQuery("DELETE ticket_documentation_waiver_events
        FROM ticket_documentation_waiver_events
        INNER JOIN ticket_documentation_obligations
            ON ticket_documentation_obligation_id = ticket_documentation_waiver_event_link_id
        WHERE ticket_documentation_obligation_ticket_id = $ticket_id",
        'Could not delete ticket documentation waiver events');
    ticketDeletionDbQuery("DELETE ticket_documentation_waivers
        FROM ticket_documentation_waivers
        INNER JOIN ticket_documentation_obligations
            ON ticket_documentation_obligation_id = ticket_documentation_waiver_link_id
        WHERE ticket_documentation_obligation_ticket_id = $ticket_id",
        'Could not delete ticket documentation waivers');
    ticketDeletionDbQuery("DELETE documentation_change_passport_obligations
        FROM documentation_change_passport_obligations
        INNER JOIN documentation_change_passports
            ON documentation_change_passport_id = documentation_change_passport_obligation_passport_id
        WHERE documentation_change_passport_ticket_id = $ticket_id",
        'Could not delete ticket Change Passport details');
    ticketDeletionDbQuery("DELETE documentation_promise_events FROM documentation_promise_events
        LEFT JOIN documentation_promise_ledger
            ON documentation_promise_id = documentation_promise_event_promise_id
        WHERE documentation_promise_event_ticket_id = $ticket_id
            OR documentation_promise_ticket_id = $ticket_id",
        'Could not delete ticket documentation promise events');
    ticketDeletionDbQuery("DELETE FROM documentation_promise_ledger
        WHERE documentation_promise_ticket_id = $ticket_id",
        'Could not delete ticket documentation promises');
    ticketDeletionDbQuery("DELETE FROM documentation_evidence_locker
        WHERE documentation_evidence_source_ticket_id = $ticket_id
            OR (documentation_evidence_reference_type = 'ticket'
                AND documentation_evidence_reference_id = $ticket_id)",
        'Could not delete ticket documentation evidence');
    ticketDeletionDbQuery("DELETE FROM documentation_change_passports
        WHERE documentation_change_passport_ticket_id = $ticket_id",
        'Could not delete ticket Change Passports');
    ticketDeletionDbQuery("DELETE FROM ticket_documentation_obligations
        WHERE ticket_documentation_obligation_ticket_id = $ticket_id",
        'Could not delete ticket documentation links');

    // Agreement and SLA snapshots owned by the ticket.
    ticketDeletionDbQuery("DELETE FROM ticket_agreement_decisions
        WHERE ticket_agreement_decision_ticket_id = $ticket_id",
        'Could not delete ticket agreement decisions');
    ticketDeletionDbQuery("DELETE FROM sla_history WHERE sla_history_ticket_id = $ticket_id",
        'Could not delete ticket SLA history');
    ticketDeletionDbQuery("DELETE FROM runbook_executions
        WHERE runbook_execution_ticket_id = $ticket_id", 'Could not delete ticket runbook execution');

    // Native ticket children.
    ticketDeletionDbQuery("DELETE FROM tasks WHERE task_ticket_id = $ticket_id", 'Could not delete ticket tasks');
    ticketDeletionDbQuery("DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id",
        'Could not delete ticket replies');
    ticketDeletionDbQuery("DELETE FROM ticket_views WHERE view_ticket_id = $ticket_id",
        'Could not delete ticket views');
    ticketDeletionDbQuery("DELETE FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id",
        'Could not delete ticket watchers');
    ticketDeletionDbQuery("DELETE FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id",
        'Could not delete ticket attachments');
    ticketDeletionDbQuery("DELETE FROM ticket_history WHERE ticket_history_ticket_id = $ticket_id",
        'Could not delete ticket history');
    ticketDeletionDbQuery("DELETE FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not delete the ticket');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The ticket changed before deletion');
    }
}
