<?php

/*
 * Technician mutations for documentation obligations and ticket gates.
 */

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

$documentation_load_obligation = static function ($obligation_id, $for_update = false) use ($mysqli) {
    $obligation_id = intval($obligation_id);
    $lock = $for_update ? ' FOR UPDATE' : '';
    return mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT o.*,
        v.documentation_requirement_version_name,
        v.documentation_requirement_version_evidence_policy
        FROM client_documentation_obligations o
        INNER JOIN documentation_requirement_versions v
            ON v.documentation_requirement_version_id = o.documentation_obligation_requirement_version_id
        WHERE o.documentation_obligation_id = $obligation_id LIMIT 1$lock",
        'Could not load the documentation obligation'));
};

$documentation_load_ticket = static function ($ticket_id) use ($mysqli) {
    $ticket_id = intval($ticket_id);
    return mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT ticket_id, ticket_client_id,
        ticket_prefix, ticket_number, ticket_documentation_impact,
        ticket_configuration_change, ticket_resolved_at, ticket_closed_at
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1",
        'Could not load the ticket documentation context'));
};

$documentation_assignment_user_allowed = static function ($user_id, $client_id) use ($mysqli) {
    $user_id = intval($user_id);
    $client_id = intval($client_id);
    if (!$user_id) {
        return true;
    }
    $row = mysqli_fetch_row(documentationLifecycleDbQuery("SELECT COUNT(*) FROM users
        WHERE user_id = $user_id AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL
        AND (
            EXISTS (SELECT 1 FROM user_roles r
                WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
            OR EXISTS (SELECT 1 FROM user_role_permissions p
                INNER JOIN modules m ON m.module_id = p.module_id
                WHERE p.user_role_id = users.user_role_id
                AND m.module_name = 'module_support' AND p.user_role_permission_level >= 1)
        )
        AND (
            EXISTS (SELECT 1 FROM user_roles r
                WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
            OR ($client_id > 0
                AND NOT EXISTS (SELECT 1 FROM user_client_permissions d
                    WHERE d.user_id = users.user_id AND d.client_id = $client_id
                    AND d.permission_type = 'deny')
                AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                        WHERE a.user_id = users.user_id AND a.permission_type = 'allow')
                    OR EXISTS (SELECT 1 FROM user_client_permissions a
                        WHERE a.user_id = users.user_id AND a.client_id = $client_id
                        AND a.permission_type = 'allow')))
        )", 'Could not validate the documentation assignment user'));
    return intval($row[0] ?? 0) === 1;
};

if (isset($_POST['reconcile_documentation_client'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    if (!$client_id) {
        flashAlert('A client is required for documentation reconciliation.', 'error');
        redirect('documentation.php?owner=all&status=attention');
    }
    enforceClientAccess($client_id);
    try {
        $summary = documentationEvaluateClient($client_id, $session_user_id);
        $changed = intval($summary['created'] ?? 0) + intval($summary['changed'] ?? 0);
        logAudit('Documentation Readiness', 'Reconcile', "$session_name reconciled client documentation ($changed changed)", $client_id);
        flashAlert($changed
            ? "Documentation reconciled: $changed obligation" . ($changed === 1 ? '' : 's') . ' updated.'
            : 'Documentation projection is already current.');
    } catch (Throwable $e) {
        error_log("Client $client_id documentation reconciliation failed: " . $e->getMessage());
        flashAlert('Documentation could not be reconciled. Refresh and try again.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all&status=attention");
}

if (isset($_POST['assess_ticket_documentation'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    $configuration_change = intval($_POST['configuration_change'] ?? 0);
    $impact = (string) ($_POST['documentation_impact'] ?? '');
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || intval($ticket['ticket_client_id']) !== $client_id || !$client_id) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    enforceClientAccess($client_id);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket documentation assessment transaction');
        }
        $transaction_started = true;
        documentationAssessTicket($ticket_id, $client_id, $configuration_change, $impact, $session_user_id);
        logTicketHistory($ticket_id, "$session_name assessed documentation impact as $impact" . ($configuration_change ? ' for a configuration change' : ''));
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket documentation assessment');
        }
        $transaction_started = false;
        logAudit('Ticket Documentation', 'Assess', "$session_name assessed documentation impact for {$ticket['ticket_prefix']}{$ticket['ticket_number']} as $impact", $client_id, $ticket_id);
        flashAlert('Ticket documentation impact assessed.');
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id documentation assessment failed: " . $e->getMessage());
        flashAlert('The ticket documentation assessment could not be saved. Refresh and try again.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}
if (isset($_POST['link_documentation_obligation_document'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $document_id = intval($_POST['document_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $obligation = $documentation_load_obligation($obligation_id);
    if (!$obligation) {
        flashAlert('The documentation obligation is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($obligation['documentation_obligation_client_id']);
    enforceClientAccess($client_id);

    try {
        documentationLinkObligationDocument($obligation_id, $document_id, $expected_revision, $session_user_id);
        logAudit('Documentation Obligation', 'Link', "$session_name linked a canonical document to {$obligation['documentation_requirement_version_name']}", $client_id, $obligation_id);
        flashAlert('Canonical documentation linked.');
    } catch (Throwable $e) {
        error_log("Documentation obligation $obligation_id document link failed: " . $e->getMessage());
        flashAlert('The document could not be linked. Refresh and try again.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all");
}

if (isset($_POST['assign_documentation_obligation'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $owner_role = (string) ($_POST['owner_role'] ?? 'documentation_owner');
    $owner_user_id = intval($_POST['owner_user_id'] ?? 0);
    $reviewer_role = (string) ($_POST['reviewer_role'] ?? 'support_lead');
    $reviewer_user_id = intval($_POST['reviewer_user_id'] ?? 0);
    $obligation = $documentation_load_obligation($obligation_id);
    if (!$obligation) {
        flashAlert('The documentation obligation is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($obligation['documentation_obligation_client_id']);
    enforceClientAccess($client_id);
    if (!$documentation_assignment_user_allowed($owner_user_id, $client_id)
        || !$documentation_assignment_user_allowed($reviewer_user_id, $client_id)) {
        flashAlert('Documentation owners and reviewers must be active support users with access to this client.', 'error');
        redirect("documentation.php?client_id=$client_id&owner=all");
    }

    try {
        documentationAssignObligationOwners(
            $obligation_id,
            $owner_role,
            $owner_user_id,
            $reviewer_role,
            $reviewer_user_id,
            $expected_revision,
            $session_user_id
        );
        logAudit('Documentation Obligation', 'Assign', "$session_name updated documentation ownership", $client_id, $obligation_id);
        flashAlert('Documentation ownership updated.');
    } catch (Throwable $e) {
        error_log("Documentation obligation $obligation_id ownership update failed: " . $e->getMessage());
        flashAlert('Documentation ownership could not be updated. Refresh and verify the selected users.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all");
}

if (isset($_POST['verify_documentation_obligation'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $obligation = $documentation_load_obligation($obligation_id);
    if (!$obligation) {
        flashAlert('The documentation obligation is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($obligation['documentation_obligation_client_id']);
    $document_id = intval($_POST['document_id'] ?? $obligation['documentation_obligation_document_id']);
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    enforceClientAccess($client_id);
    if ($ticket_id) {
        $verification_ticket = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT ticket.ticket_id
            FROM ticket_documentation_obligations link
            INNER JOIN tickets ticket ON ticket.ticket_id = link.ticket_documentation_obligation_ticket_id
                AND ticket.ticket_deleted_at IS NULL
            WHERE link.ticket_documentation_obligation_ticket_id = $ticket_id
            AND link.ticket_documentation_obligation_obligation_id = $obligation_id
            AND link.ticket_documentation_obligation_client_id = $client_id
            AND ticket.ticket_client_id = $client_id LIMIT 1",
            'Could not validate the verification ticket context'));
        if (!$verification_ticket) {
            flashAlert('The verification ticket is not linked to this obligation.', 'error');
            redirect("documentation.php?client_id=$client_id&owner=all");
        }
    }
    $document = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT document_id,
        document_client_id, document_content, document_archived_at FROM documents
        WHERE document_id = $document_id LIMIT 1", 'Could not load verification evidence'));
    if (!$document || intval($document['document_client_id']) !== $client_id || !empty($document['document_archived_at'])) {
        flashAlert('Link an active client document before verifying this obligation.', 'error');
        redirect("documentation.php?client_id=$client_id&owner=all");
    }
    $evidence_policy = (string) $obligation['documentation_requirement_version_evidence_policy'];
    if (!in_array($evidence_policy, ['none', 'note', 'file', 'reference'], true)) {
        flashAlert('This evidence policy is not available for manual verification.', 'error');
        redirect("documentation.php?client_id=$client_id&owner=all");
    }
    if ($evidence_policy === 'note') {
        $evidence_note = trim((string) ($_POST['evidence_note'] ?? ''));
        if ($evidence_note === '') {
            flashAlert('A verification evidence note is required.', 'error');
            redirect("documentation.php?client_id=$client_id&owner=all");
        }
        $evidence = ['type' => 'note', 'reference_type' => 'note', 'reference_id' => 0, 'locator' => $evidence_note];
    } elseif ($evidence_policy === 'file') {
        $evidence_file_id = intval($_POST['evidence_file_id'] ?? 0);
        $evidence_file = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT file_id FROM files
            WHERE file_id = $evidence_file_id AND file_client_id = $client_id
            AND file_archived_at IS NULL AND file_deleted_at IS NULL LIMIT 1",
            'Could not validate documentation file evidence'));
        if (!$evidence_file) {
            flashAlert('Select active file evidence belonging to this client.', 'error');
            redirect("documentation.php?client_id=$client_id&owner=all");
        }
        $evidence = ['type' => 'file', 'reference_type' => 'file', 'reference_id' => $evidence_file_id, 'locator' => ''];
    } elseif ($evidence_policy === 'none') {
        $evidence = ['type' => 'none', 'reference_type' => 'policy', 'reference_id' => 0, 'locator' => ''];
    } else {
        $content_hash = hash('sha256', (string) $document['document_content']);
        $evidence = [
            'type' => 'document_revision',
            'reference_type' => 'document',
            'reference_id' => $document_id,
            'locator' => "document:$document_id:sha256:$content_hash",
        ];
    }

    try {
        documentationVerifyObligation(
            $obligation_id,
            $document_id,
            $evidence,
            $expected_revision,
            $session_user_id,
            $ticket_id,
            'agent'
        );
        logAudit('Documentation Obligation', 'Verify', "$session_name verified {$obligation['documentation_requirement_version_name']}", $client_id, $obligation_id);
        flashAlert('Documentation verification recorded.');
    } catch (Throwable $e) {
        error_log("Documentation obligation $obligation_id verification failed: " . $e->getMessage());
        flashAlert('The verification could not be recorded. Refresh and try again.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all");
}

if (isset($_POST['request_documentation_exception'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $expires_at = (string) ($_POST['expires_at'] ?? '');
    $obligation = $documentation_load_obligation($obligation_id);
    if (!$obligation) {
        flashAlert('The documentation obligation is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($obligation['documentation_obligation_client_id']);
    enforceClientAccess($client_id);
    try {
        documentationRequestObligationException($obligation_id, $reason, $expires_at, $expected_revision, $session_user_id);
        flashAlert('Documentation exception submitted for approval.', 'info');
    } catch (Throwable $e) {
        error_log("Documentation obligation $obligation_id exception request failed: " . $e->getMessage());
        flashAlert('The exception request could not be saved. Check its reason and expiry.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all");
}

if (isset($_POST['approve_documentation_exception'])
    || isset($_POST['reject_documentation_exception'])
    || isset($_POST['revoke_documentation_exception'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $decision = isset($_POST['approve_documentation_exception'])
        ? 'Approved'
        : (isset($_POST['revoke_documentation_exception']) ? 'Revoked' : 'Rejected');
    $obligation = $documentation_load_obligation($obligation_id);
    if (!$obligation) {
        flashAlert('The documentation obligation is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($obligation['documentation_obligation_client_id']);
    enforceClientAccess($client_id);
    try {
        documentationDecideObligationException($obligation_id, $decision, $expected_revision, $session_user_id);
        flashAlert("Documentation exception $decision.", $decision === 'Approved' ? 'info' : 'error');
    } catch (Throwable $e) {
        error_log("Documentation obligation $obligation_id exception decision failed: " . $e->getMessage());
        flashAlert('The exception decision could not be recorded. Self-approval and stale requests are rejected.', 'error');
    }
    redirect("documentation.php?client_id=$client_id&owner=all");
}

if (isset($_POST['link_ticket_documentation_obligation'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || !intval($ticket['ticket_client_id'])
        || !empty($ticket['ticket_resolved_at']) || !empty($ticket['ticket_closed_at'])) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    enforceClientAccess($client_id);
    try {
        documentationLinkTicketObligation($ticket_id, $obligation_id, $session_user_id, true);
        logTicketHistory($ticket_id, "$session_name linked a required documentation obligation");
        flashAlert('Required documentation linked to the ticket.');
    } catch (Throwable $e) {
        error_log("Ticket $ticket_id documentation link failed: " . $e->getMessage());
        flashAlert('The documentation obligation could not be linked. Refresh and try again.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['link_task_documentation_obligation'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $task_id = intval($_POST['task_id'] ?? 0);
    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $task = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT task.task_id,
        task.task_ticket_id, ticket.ticket_client_id
        FROM tasks task INNER JOIN tickets ticket ON ticket.ticket_id = task.task_ticket_id
            AND ticket.ticket_deleted_at IS NULL
        WHERE task.task_id = $task_id AND task.task_ticket_id = $ticket_id LIMIT 1",
        'Could not validate the task documentation context'));
    if (!$task || !intval($task['ticket_client_id'])) {
        flashAlert('The task documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($task['ticket_client_id']);
    enforceClientAccess($client_id);

    try {
        documentationLinkTaskObligation(
            $ticket_id,
            $task_id,
            $obligation_id,
            $session_user_id,
            true
        );
        logTicketHistory($ticket_id, "$session_name linked a documentation obligation to task $task_id");
        logAudit('Task Documentation', 'Link', "$session_name linked task $task_id to a documentation obligation", $client_id, $task_id);
        flashAlert('Documentation obligation linked to the task.');
    } catch (Throwable $e) {
        error_log("Task $task_id documentation link failed: " . $e->getMessage());
        flashAlert('The documentation obligation could not be linked to this task. Refresh and try again.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['create_documentation_promise'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $obligation_id = intval($_POST['obligation_id'] ?? 0);
    $reason_code = (string) ($_POST['reason_code'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $due_at = (string) ($_POST['due_at'] ?? '');
    $allowed_reason_codes = [
        'client-input',
        'evidence-follow-up',
        'technical-validation',
        'documentation-refresh',
    ];
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || !intval($ticket['ticket_client_id'])
        || !empty($ticket['ticket_resolved_at']) || !empty($ticket['ticket_closed_at'])) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    enforceClientAccess($client_id);
    $link_context = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
        ticket_documentation_obligation_id
        FROM ticket_documentation_obligations
        WHERE ticket_documentation_obligation_ticket_id = $ticket_id
        AND ticket_documentation_obligation_obligation_id = $obligation_id
        AND ticket_documentation_obligation_client_id = $client_id LIMIT 1",
        'Could not validate the documentation promise context'));
    if (!$link_context || !in_array($reason_code, $allowed_reason_codes, true)) {
        flashAlert('Select a linked obligation and a valid follow-up type.', 'error');
        redirect("ticket.php?ticket_id=$ticket_id");
    }

    try {
        documentationCreatePromise(
            $obligation_id,
            $ticket_id,
            $reason_code,
            $reason,
            $due_at,
            $session_user_id
        );
        logTicketHistory($ticket_id, "$session_name made an explicit documentation follow-up promise");
        logAudit('Documentation Promise', 'Create', "$session_name scheduled $reason_code for a ticket documentation obligation", $client_id, $ticket_id);
        flashAlert('Documentation follow-up promise recorded.', 'info');
    } catch (Throwable $e) {
        error_log("Ticket $ticket_id documentation promise failed: " . $e->getMessage());
        flashAlert('The documentation follow-up could not be recorded. Check its details and due date.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['fulfill_documentation_promise']) || isset($_POST['cancel_documentation_promise'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $promise_id = intval($_POST['promise_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $outcome = isset($_POST['fulfill_documentation_promise']) ? 'Fulfilled' : 'Cancelled';
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || !intval($ticket['ticket_client_id'])) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    enforceClientAccess($client_id);
    $promise_context = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
        promise.documentation_promise_obligation_id
        FROM documentation_promise_ledger promise
        INNER JOIN ticket_documentation_obligations link
            ON link.ticket_documentation_obligation_ticket_id = promise.documentation_promise_ticket_id
            AND link.ticket_documentation_obligation_obligation_id = promise.documentation_promise_obligation_id
            AND link.ticket_documentation_obligation_client_id = promise.documentation_promise_client_id
        WHERE promise.documentation_promise_id = $promise_id
        AND promise.documentation_promise_ticket_id = $ticket_id
        AND promise.documentation_promise_client_id = $client_id
        AND promise.documentation_promise_status = 'Open' LIMIT 1",
        'Could not validate the documentation promise completion context'));
    if (!$promise_context) {
        flashAlert('The documentation follow-up is unavailable.', 'error');
        redirect("ticket.php?ticket_id=$ticket_id");
    }

    try {
        documentationCompletePromise($promise_id, $outcome, $expected_revision, $session_user_id);
        logTicketHistory($ticket_id, "$session_name marked a documentation follow-up promise $outcome");
        logAudit('Documentation Promise', $outcome, "$session_name marked a ticket documentation promise $outcome", $client_id, $ticket_id);
        flashAlert("Documentation follow-up marked $outcome.", $outcome === 'Fulfilled' ? 'success' : 'info');
    } catch (Throwable $e) {
        error_log("Ticket $ticket_id documentation promise completion failed: " . $e->getMessage());
        flashAlert('The documentation follow-up could not be updated. Refresh and try again.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['request_ticket_documentation_waiver'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $link_id = intval($_POST['link_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $expires_at = (string) ($_POST['expires_at'] ?? '');
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || !intval($ticket['ticket_client_id'])) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    enforceClientAccess($client_id);
    $link_context = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
        ticket_documentation_obligation_ticket_id, ticket_documentation_obligation_client_id
        FROM ticket_documentation_obligations WHERE ticket_documentation_obligation_id = $link_id LIMIT 1",
        'Could not validate the ticket documentation waiver context'));
    if (!$link_context || intval($link_context['ticket_documentation_obligation_ticket_id']) !== $ticket_id
        || intval($link_context['ticket_documentation_obligation_client_id']) !== $client_id) {
        flashAlert('The ticket documentation link is unavailable.', 'error');
        redirect("ticket.php?ticket_id=$ticket_id");
    }
    try {
        documentationRequestTicketWaiver($link_id, $reason, $expires_at, $session_user_id);
        logTicketHistory($ticket_id, "$session_name requested a documentation resolution waiver");
        flashAlert('Documentation waiver submitted for approval.', 'info');
    } catch (Throwable $e) {
        error_log("Ticket $ticket_id documentation waiver request failed: " . $e->getMessage());
        flashAlert('The waiver request could not be saved. Check its reason and expiry.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}

if (isset($_POST['approve_ticket_documentation_waiver'])
    || isset($_POST['reject_ticket_documentation_waiver'])
    || isset($_POST['revoke_ticket_documentation_waiver'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $waiver_id = intval($_POST['waiver_id'] ?? 0);
    $expected_revision = intval($_POST['expected_revision'] ?? 0);
    $decision = isset($_POST['approve_ticket_documentation_waiver'])
        ? 'Approved'
        : (isset($_POST['revoke_ticket_documentation_waiver']) ? 'Revoked' : 'Rejected');
    $ticket = $documentation_load_ticket($ticket_id);
    if (!$ticket || !intval($ticket['ticket_client_id'])) {
        flashAlert('The ticket documentation context is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    enforceClientAccess($client_id);
    $waiver_context = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
        link.ticket_documentation_obligation_ticket_id,
        link.ticket_documentation_obligation_client_id
        FROM ticket_documentation_waivers waiver
        INNER JOIN ticket_documentation_obligations link
            ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
        WHERE waiver.ticket_documentation_waiver_id = $waiver_id LIMIT 1",
        'Could not validate the ticket documentation waiver decision context'));
    if (!$waiver_context || intval($waiver_context['ticket_documentation_obligation_ticket_id']) !== $ticket_id
        || intval($waiver_context['ticket_documentation_obligation_client_id']) !== $client_id) {
        flashAlert('The ticket documentation waiver is unavailable.', 'error');
        redirect("ticket.php?ticket_id=$ticket_id");
    }
    try {
        documentationDecideTicketWaiver($waiver_id, $decision, $expected_revision, $session_user_id);
        logTicketHistory($ticket_id, "$session_name $decision a documentation resolution waiver");
        flashAlert("Documentation waiver $decision.", $decision === 'Approved' ? 'info' : 'error');
    } catch (Throwable $e) {
        error_log("Ticket $ticket_id documentation waiver decision failed: " . $e->getMessage());
        flashAlert('The waiver decision could not be recorded. Self-approval and stale requests are rejected.', 'error');
    }
    redirect("ticket.php?ticket_id=$ticket_id");
}
