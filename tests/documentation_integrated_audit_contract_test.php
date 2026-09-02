<?php

/*
 * Focused source-contract coverage for the integrated Goal 4 audit fixes.
 * Database semantics and lock implementation are covered by documentation.php;
 * this inventory keeps every technician/runtime seam wired to that core.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function ($path, $optional = false) use ($root, &$failures) {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        if (!$optional) {
            $failures[] = "Could not read $path";
        }
        return '';
    }
    return $contents;
};
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) === false) {
        $failures[] = $message;
    }
};
$assertMatches = static function ($pattern, $contents, $message) use (&$failures) {
    if (preg_match($pattern, $contents) !== 1) {
        $failures[] = $message;
    }
};
$section = static function ($contents, $start, $end, $label) use (&$failures) {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};
$assertDocumentMutationOrder = static function ($contents, $reason, $write, $label) use (&$failures) {
    $begin = strpos($contents, 'mysqli_begin_transaction($mysqli)');
    $invalidate = $begin === false ? false : strpos($contents, 'documentationInvalidateDocumentLocked(', $begin);
    $reason_at = $invalidate === false ? false : strpos($contents, "'$reason'", $invalidate);
    $first_lock = strpos($contents, 'FOR UPDATE');
    $write_at = $reason_at === false ? false : strpos($contents, $write, $reason_at);
    $commit = $write_at === false ? false : strpos($contents, 'mysqli_commit($mysqli)', $write_at);
    if ($begin === false || $invalidate === false || $reason_at === false || $write_at === false || $commit === false
        || !($begin < $invalidate && $invalidate < $reason_at && $reason_at < $write_at && $write_at < $commit)) {
        $failures[] = "$label does not atomically invalidate before its document write";
    }
    if ($first_lock !== false && $invalidate !== false && $first_lock < $invalidate) {
        $failures[] = "$label locks the document before the core invalidator establishes client/obligation/document order";
    }
    if (strpos($contents, 'mysqli_rollback($mysqli)') === false) {
        $failures[] = "$label cannot roll back an invalidation or write failure";
    }
};

$lifecycle = $read('functions/documentation_lifecycle.php');
$automation = $read('functions/automation.php');
$documentation_post = $read('agent/post/documentation.php');
$document_post = $read('agent/post/document.php');
$file_post = $read('agent/post/file.php');
$ticket_post = $read('agent/post/ticket.php');
$retention = $read('functions/retention.php');
$queue = $read('agent/documentation.php');
$operations = $read('agent/operations.php');
$global_counts = $read('agent/includes/get_side_nav_counts.php');
$client_counts = $read('agent/includes/inc_all_client.php');
$obligation_modal = $read('agent/modals/documentation/obligation.php');
$ticket_modal = $read('agent/modals/ticket/ticket_documentation.php');
$ticket_add = $read('agent/modals/ticket/ticket_add.php');
$task_modal = $read('agent/modals/ticket/ticket_task_evidence_add.php');
$admin_requirements = $read('admin/documentation_requirements.php');
$core = $read('functions/documentation.php', true);
$migration = $read('n45/migrations/n45-0011-documentation-readiness.php', true);

// Required/configuration-change assessments may be upgraded, but never erased
// after any durable documentation activity without a future audited reversal API.
$assertContains('$is_downgrade', $lifecycle, 'Ticket assessment downgrade detection is missing');
$assertContains('documentationTicketHasAuditRecords($ticket_id)', $lifecycle, 'Ticket assessment downgrade does not fail closed on audit history');
foreach (['ticket_documentation_obligations', 'ticket_documentation_waivers', 'documentation_promise_ledger',
          'documentation_change_passports', 'documentation_evidence_locker'] as $history_table) {
    $assertContains($history_table, $lifecycle, "Ticket history detection omits $history_table");
}
$assertContains('$impact_downgrade_locked', $ticket_modal, 'Ticket UI does not explain the immutable Required assessment');
$assertContains('$configuration_downgrade_locked', $ticket_modal, 'Ticket UI does not preserve an audited configuration-change flag');

// Human-created tickets require an explicit choice; system incident tickets
// make a deliberate, attributable no-impact assessment.
$assertContains('<option value="" selected disabled>Unassessed — select an impact</option>', $ticket_add,
    'New agent tickets silently default their documentation assessment');
$assertContains("['None', 'Required']", $ticket_post, 'Agent ticket creation accepts a non-explicit documentation assessment');
$assertContains('Unassessed — select an impact', $ticket_modal, 'Legacy/unassessed ticket reassessment silently defaults to None');
$incident = $section($automation, 'function automationCreateIncidentTicket(', 'function automationAddIncidentReply(', 'automation incident creation');
foreach (["ticket_configuration_change = 0", "ticket_documentation_impact = 'None'",
          'ticket_documentation_assessed_by = 0', 'ticket_documentation_assessed_at = NOW()'] as $incident_contract) {
    $assertContains($incident_contract, $incident, "Automation incident tickets omit $incident_contract");
}

// Draft is actionable everywhere, while an empty readiness denominator is not
// misrepresented as a zero-percent failure.
$assertContains("['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception']", $queue, 'Draft obligations are absent from the owner queue');
$assertContains("['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception', 'Current']", $queue, 'Draft obligations are absent from queue summary cards');
$assertContains('documentationProjectObligationValidity(', $operations, 'The Operations queue trusts stored obligation status');
foreach ([$global_counts, $client_counts] as $attention_surface) {
    $assertContains('documentationObligationValiditySql(', $attention_surface, 'An attention badge omits canonical validity joins');
    $assertContains('documentationProjectObligationValidity(', $attention_surface, 'An attention badge trusts its stored status');
    $assertContains("['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception']", $attention_surface,
        'An attention badge omits Draft or another actionable projected status');
}
$assertContains('$readiness_denominator > 0', $queue, 'Readiness does not distinguish an empty denominator');
$assertContains('>N/A</span>', $queue, 'Zero-denominator readiness is not rendered as N/A');
$assertContains("=== 'Active' ? 'success'", $admin_requirements, 'Active requirements do not receive the active badge');

// Ownership is editable, client-scoped, and delegated to the revision-aware core.
foreach (['name="owner_role"', 'name="owner_user_id"', 'name="reviewer_role"', 'name="reviewer_user_id"'] as $assignment_field) {
    $assertContains($assignment_field, $obligation_modal, "Ownership UI omits $assignment_field");
}
$assertContains('$documentation_assignment_user_allowed', $documentation_post, 'Assignment POST does not validate active support/client access');
$assertContains('documentationAssignObligationOwners(', $documentation_post, 'Ownership POST bypasses the core assignment API');

// Open promises and approved exceptions/waivers remain explicitly actionable,
// including after the ticket reaches a terminal state.
$assertContains('$can_manage_promises = lookupUserPermission', $ticket_modal, 'Promise actions remain coupled to open-ticket editing');
$assertMatches('/if \(\$can_manage_promises(?:\s*&&\s*\$projection_mutable)?\).*?fulfill_documentation_promise/s', $ticket_modal,
    'Terminal tickets do not expose promise fulfillment/cancellation');
foreach (['revoke_documentation_exception', 'revoke_ticket_documentation_waiver'] as $revoke_action) {
    $assertContains($revoke_action, $documentation_post, "POST handler omits $revoke_action");
}
$assertContains("? 'Revoked' : 'Rejected'", $documentation_post, 'Revoke actions are not mapped to the core Revoked decision');
$assertContains('name="revoke_documentation_exception"', $obligation_modal, 'Approved obligation exceptions cannot be revoked');
$assertContains('name="revoke_ticket_documentation_waiver"', $ticket_modal, 'Approved ticket waivers cannot be revoked');

// Verification attribution must be an actual same-client ticket/obligation
// link, and task links must use the core task seam instead of ad-hoc writes.
$assertContains("\$ticket_id = intval(\$_POST['ticket_id'] ?? 0);", $documentation_post, 'Verification POST does not populate a ticket attribution');
$assertContains('ticket_documentation_obligation_ticket_id = $ticket_id', $documentation_post, 'Verification ticket is not validated against the obligation link');
$assertMatches('/documentationVerifyObligation\s*\(.*?\$ticket_id,\s*\'agent\'\s*\)/s', $documentation_post,
    'Verification does not pass its validated ticket and source to the core');
$assertContains('name="ticket_id"', $obligation_modal, 'Verification UI cannot select a linked ticket');
$assertContains('ticket_documentation_obligation_task_id', $task_modal, 'Task UI does not show task-bound documentation links');
$assertContains('name="link_task_documentation_obligation"', $task_modal, 'Task UI cannot link an obligation');
$assertContains('documentationLinkTaskObligation(', $documentation_post, 'Task link POST bypasses the core task seam');

// Current-document changes and all archive/delete surfaces retain evidence and
// use the core lock order before mutating the document.
$edit_document = $section($document_post, "if (isset(\$_POST['edit_document']))", "if (isset(\$_POST['move_document']))", 'document edit');
$archive_document = $section($document_post, "if (isset(\$_GET['archive_document']))", "if (isset(\$_GET['restore_document']))", 'document archive');
$delete_start = strpos($document_post, "if (isset(\$_GET['delete_document']))");
$delete_document = $delete_start === false ? '' : substr($document_post, $delete_start);
if ($delete_start === false) {
    $failures[] = 'Could not isolate document delete';
}
$assertDocumentMutationOrder($edit_document, 'document_changed', 'UPDATE documents SET', 'Document edit');
$assertDocumentMutationOrder($archive_document, 'document_archived', 'UPDATE documents SET', 'Document archive');
$assertDocumentMutationOrder($delete_document, 'document_deleted', 'DELETE FROM documents', 'Document delete');
$assertContains("'document_archived' : 'document_deleted'", $file_post, 'Bulk document mutations bypass the invalidation lock order');
foreach (['document', 'document-version'] as $reference_type) {
    $reference_pattern = '/documentationEvidenceReferenceInUse\s*\(\s*\''
        . preg_quote($reference_type, '/') . '\'/s';
    $assertMatches($reference_pattern, $document_post,
        "Document handlers do not retain $reference_type evidence");
    $assertMatches($reference_pattern, $file_post,
        "Bulk document handlers do not retain $reference_type evidence");
}
$assertContains("documentationEvidenceReferenceInUse('file'", $file_post, 'File archive/delete can discard Evidence Locker references');
$assertContains('documentation_evidence_locker', $retention, 'Ticket purge can discard Evidence Locker references');
$assertContains('documentation_change_passports', $retention, 'Ticket purge can discard Change Passport evidence');
$assertContains('documentation_promise_ledger', $retention, 'Ticket purge can discard Promise Ledger accountability');

// Assert exact core interfaces once this UI commit is composed with the core
// commit. Keeping this conditional lets either isolated worktree test itself.
if ($core !== '') {
    $assertMatches('/function documentationInvalidateDocumentLocked\s*\(\s*\$document_id,\s*\$client_id,\s*\$actor_id\s*=\s*0,\s*\$reason_code\s*=\s*\'document_changed\'\s*\)/s',
        $core, 'Core document invalidation signature drifted from its UI callers');
    $assertMatches('/function documentationEvidenceReferenceInUse\s*\(\s*\$reference_type,\s*\$reference_id,\s*\$client_id\s*=\s*0\s*\)/s',
        $core, 'Core Evidence Locker guard signature drifted from its UI callers');
    $assertContains('function documentationLinkTaskObligation(', $core, 'Core task-obligation wrapper is missing');
    $assertContains('$caller_transaction = false,', $core, 'Core ticket-obligation link lost its compatible caller-transaction argument');
    $assertContains('$task_id = 0', $core, 'Core ticket-obligation link lost its task_id tail argument');
}
if ($migration !== '') {
    $assertContains('`ticket_documentation_obligation_task_id` int(11) NOT NULL DEFAULT 0', $migration,
        'n45-0011 does not persist the task-to-obligation seam');
}

if ($failures) {
    fwrite(STDERR, "Documentation integrated-audit contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation integrated-audit contract test passed\n";
