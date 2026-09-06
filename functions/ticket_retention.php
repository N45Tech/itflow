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
