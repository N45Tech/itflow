<?php

$failures = [];
$root = dirname(__DIR__);

$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$assertRegex = function (string $pattern, string $subject, string $message) use (&$failures): void {
    if (preg_match($pattern, $subject) !== 1) {
        $failures[] = $message;
    }
};

$assertOrdered = function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $position + 1);
        if ($next === false) {
            $failures[] = $message . " (missing '$needle')";
            return;
        }
        $position = $next;
    }
};

$read = function (string $relative_path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $relative_path);
    if ($contents === false) {
        $failures[] = "Could not read $relative_path";
        return '';
    }
    return $contents;
};

$section = function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$assertTransactionEnvelope = function (string $contents, string $label) use (&$failures): void {
    $begin = strpos($contents, 'mysqli_begin_transaction($mysqli)');
    $ticket_insert = strpos($contents, 'INSERT INTO tickets SET');
    $task_copy = strpos($contents, 'addTasksFromTicketTemplate(');
    $commit = strpos($contents, 'mysqli_commit($mysqli)');
    $rollback = strpos($contents, 'mysqli_rollback($mysqli)');

    if ($begin === false || $ticket_insert === false || $task_copy === false
        || $commit === false || $rollback === false
        || !($begin < $ticket_insert && $ticket_insert < $task_copy && $task_copy < $commit)) {
        $failures[] = "$label does not atomically create its ticket and runbook execution";
    }
    if (preg_match('/addTasksFromTicketTemplate\s*\([^;]*,\s*true\s*\);/s', $contents) !== 1) {
        $failures[] = "$label does not tell central runbook instantiation to use the outer transaction";
    }
};

// Canonical starter reconciliation must rebuild, validate and publish every
// flagged runbook idempotently, then pin project stages to those versions.
$starter = $read('admin/post/starter_content_model.php');
$reconcile = $read('deploy/psa/reconcile_templates.php');
$runbooks = $read('functions/runbooks.php');
$assertContains("'publish_runbook' => true", $starter, 'Starter content has no publishable runbook definitions');
$assertTrue(substr_count($starter, "'publish_runbook' => true") === 4, 'Only the two portal workflows plus onboarding and offboarding should be auto-published');
$assertContains('reconcileTemplateDeleteTaskDrafts($mysqli, $template_id)', $reconcile, 'Template reconciliation does not replace stale editable task drafts');
$assertContains('starterInsertTicketTemplateTasks(', $reconcile, 'Template reconciliation does not rebuild canonical task metadata and dependencies');
$assertContains("if (!empty(\$template['publish_runbook']))", $reconcile, 'Template reconciliation ignores the publication marker');
$assertContains('publishRunbookVersion(', $reconcile, 'Template reconciliation does not publish canonical runbooks');
$assertContains('runbookValidateDefinition($definition)', $reconcile, 'A failed canonical publication does not report definition validation errors');
$assertContains('$published_versions[$template_id] = $version_id;', $reconcile, 'Published canonical versions are not retained for project pinning');
$assertContains("'ticket_template_runbook_version_id' => \$runbook_version_id", $reconcile, 'Project stages are not pinned to reconciled runbook versions');
$assertContains('mysqli_begin_transaction($mysqli)', $reconcile, 'Canonical reconciliation is not transactional');
$assertContains('if ($dry_run)', $reconcile, 'Canonical reconciliation lost dry-run handling');
$assertContains('mysqli_rollback($mysqli)', $reconcile, 'Dry-run or failed reconciliation does not roll back');
$assertContains('mysqli_commit($mysqli)', $reconcile, 'Applied reconciliation does not commit');

$publish_start = strpos($runbooks, 'function publishRunbookVersion(');
$publish_end = strpos($runbooks, 'function restoreRunbookVersionToDraft(', $publish_start ?: 0);
$publish = ($publish_start !== false && $publish_end !== false)
    ? substr($runbooks, $publish_start, $publish_end - $publish_start) : '';
$assertContains('runbookDefinitionHash($definition)', $publish, 'Publishing is not keyed by a canonical definition hash');
$assertContains('AND runbook_version_definition_hash =', $publish, 'Publishing does not reuse an existing identical version');
$assertContains('return $version_id;', $publish, 'Idempotent publishing does not return the selected version');
$assertContains('ticket_template_published_version_id = $version_id', $publish, 'Publishing does not update the template pointer');
$assertContains('ticket_template_runbook_version_id = $version_id', $publish, 'Publishing does not pin unpinned project stages');

// Ordinary ticket forms may choose a template, never one of its historical
// immutable versions. The server selects and re-reads the current version.
$ticket_post = $read('agent/post/ticket.php');
$normal_create = $section(
    $ticket_post,
    "if (isset(\$_POST['add_ticket']))",
    "if (isset(\$_POST['edit_ticket']))",
    'normal ticket creation handler'
);
$assertNotContains("\$_POST['runbook_version_id']", $normal_create, 'Normal ticket creation trusts a posted historical runbook version');
$assertContains('ticket_template_published_version_id', $normal_create, 'Normal ticket creation does not select the current published version pointer');
$assertRegex('/runbook_version_ticket_template_id\s*=\s*(?:\$ticket_template_id|ticket_template_id)/', $normal_create, 'Normal ticket creation does not verify version ownership');
$assertRegex('/\$subject_raw\s*=\s*\$(?:template|published)\[\'runbook_version_subject\'\]/', $normal_create, 'Normal creation trusts posted content instead of the immutable version subject');
$assertRegex('/\$details_raw\s*=\s*\$(?:template|published)\[\'runbook_version_details\'\]/', $normal_create, 'Normal creation trusts posted content instead of the immutable version details');
$assertContains('runbook_version_count', $normal_create, 'Normal creation silently flattens a runbook whose published pointer is missing');

// The central helper propagates failures when ticket creation owns the outer
// transaction. Normal, bulk-asset, bulk-client and project creation all use it.
$app = $read('functions/app.php');
$assertContains('$caller_transaction = false', $app, 'The central task helper cannot join an outer transaction');
$assertContains("'caller_transaction' => \$caller_transaction", $app, 'The central task helper does not propagate the outer transaction');
$assertContains('if ($caller_transaction)', $app, 'Legacy task-copy errors are swallowed inside an outer transaction');
$assertContains('throw new RuntimeException(', $app, 'Legacy task-copy failures cannot roll back their ticket');
$assertContains('$caller_transaction = !empty($context[\'caller_transaction\']);', $runbooks, 'Runbook instantiation does not recognize its caller transaction');
$assertContains('if (!$caller_transaction && !mysqli_begin_transaction($mysqli))', $runbooks, 'Nested runbook instantiation starts a second transaction');
$assertContains('if (!$caller_transaction && !mysqli_commit($mysqli))', $runbooks, 'Nested runbook instantiation commits its caller transaction');
$assertContains('if ($caller_transaction) {', $runbooks, 'Runbook failures are not propagated to an outer transaction');
$assertContains('throw $exception;', $runbooks, 'Runbook instantiation swallows an outer-transaction failure');

$bulk_asset = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_add_asset_ticket']))",
    "if (isset(\$_POST['add_ticket_reply']))",
    'bulk asset ticket creation handler'
);
$client_post = $read('agent/post/client.php');
$bulk_client = $section(
    $client_post,
    "if (isset(\$_POST['bulk_add_client_ticket']))",
    "if (isset(\$_POST['bulk_edit_client_industry']))",
    'bulk client ticket creation handler'
);
$project_post = $read('agent/post/project.php');
$project_create = $section(
    $project_post,
    "if (isset(\$_POST['add_project']))",
    "if (isset(\$_POST['edit_project']))",
    'project creation handler'
);
$assertTransactionEnvelope($normal_create, 'Normal ticket creation');
$assertTransactionEnvelope($bulk_asset, 'Bulk asset ticket creation');
$assertTransactionEnvelope($bulk_client, 'Bulk client ticket creation');
$assertTransactionEnvelope($project_create, 'Project template ticket creation');

// Every post-creation project change uses one transaction boundary and the
// project -> ticket lock order. Published execution context is immutable.
$project_assignment_start = strpos($app, 'function ticketAssignProjectSafely(');
$project_assignment_end = $project_assignment_start === false
    ? false : strpos($app, "\n/**", $project_assignment_start + 1);
$project_assignment = ($project_assignment_start !== false && $project_assignment_end !== false)
    ? substr($app, $project_assignment_start, $project_assignment_end - $project_assignment_start) : '';
$assertOrdered(
    $project_assignment,
    [
        'mysqli_begin_transaction($mysqli)',
        'sort($project_ids, SORT_NUMERIC)',
        'FOR UPDATE',
        'ticket_updated_at FROM tickets WHERE ticket_id = $ticket_id FOR UPDATE',
        'FROM runbook_executions WHERE runbook_execution_ticket_id = $ticket_id',
        'UPDATE tickets SET ticket_project_id = $target_project_id',
        'mysqli_affected_rows($mysqli)',
        'mysqli_commit($mysqli)',
    ],
    'Ticket project assignment does not lock projects then ticket, protect immutable execution context, CAS and commit'
);
$assertContains('project_completed_at, project_archived_at', $project_assignment, 'Project assignment does not reject a completed or archived source/target project');
$assertContains('if ($execution_count)', $project_assignment, 'Project assignment permits a published runbook ticket to change execution context');
$assertContains('AND ticket_project_id = $source_project_id', $project_assignment, 'Project assignment CAS does not revalidate the source project');
$assertContains('mysqli_rollback($mysqli)', $project_assignment, 'Failed project assignment cannot roll back');

$project_link = $section(
    $project_post,
    "if (isset(\$_POST['link_ticket_to_project']))",
    "if (isset(\$_POST['link_closed_ticket_to_project']))",
    'project ticket link handler'
);
$closed_project_link_start = strpos($project_post, "if (isset(\$_POST['link_closed_ticket_to_project']))");
$closed_project_link = $closed_project_link_start === false ? '' : substr($project_post, $closed_project_link_start);
$bulk_project_link = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_add_ticket_project']))",
    "if (isset(\$_POST['bulk_add_asset_ticket']))",
    'bulk ticket project link handler'
);
foreach ([$project_link, $closed_project_link, $bulk_project_link] as $index => $link_handler) {
    $label = ['Project ticket link', 'Closed ticket link', 'Bulk ticket project link'][$index];
    $assertContains('ticketAssignProjectSafely(', $link_handler, "$label bypasses the safe project assignment service");
    $assertNotContains('UPDATE tickets SET ticket_project_id', $link_handler, "$label writes the project binding directly");
}
$assertContains('ticketAssignProjectSafely($ticket_id, $project_id, true)', $closed_project_link, 'Closed ticket linking no longer preserves its original update timestamp');

// Every target object must be resolved within the current client boundary.
$assertRegex('/enforceClientAccess\s*\(\s*\$client_id\s*\)/', $normal_create, 'Normal ticket creation does not enforce client access');
$assertRegex(
    '/if\s*\(\$contact_id\).*?FROM contacts.*?contact_id\s*=\s*\$contact_id.*?contact_client_id\s*=\s*\$client_id/s',
    $normal_create,
    'Normal ticket creation does not validate the selected contact against the client'
);
$assertContains('asset_client_id', $bulk_asset, 'Bulk asset creation does not resolve each asset client');
$assertContains('asset_archived_at IS NULL', $bulk_asset, 'Bulk asset creation accepts archived assets');
$assertRegex('/enforceClientAccess\s*\(\s*\$client_id\s*\)/', $bulk_asset, 'Bulk asset creation does not enforce access for each asset client');
$assertContains('client_archived_at IS NULL', $bulk_client, 'Bulk client creation accepts archived clients');
$assertRegex('/enforceClientAccess\s*\(\s*\$requested_client_id\s*\)/', $bulk_client, 'Bulk client creation does not enforce access for each selected client');
$assertRegex('/enforceClientAccess\s*\(\s*\$client_id\s*\)/', $project_create, 'Project creation does not enforce access to its client');
$assertContains('if ($project_id && $project_client_id !== $client_id)', $bulk_asset, 'Bulk asset creation can attach a cross-client project');
$assertContains('if ($project_id && $project_client_id !== $requested_client_id)', $bulk_client, 'Bulk client creation can attach a cross-client project');

// Bulk forms expose the server-supported runbook/contact choices and never
// offer one client's project for a mixed-client ticket batch.
$bulk_asset_modal = $read('agent/modals/asset/asset_bulk_add_ticket.php');
$bulk_client_modal = $read('agent/modals/client/client_bulk_add_ticket.php');
foreach ([$bulk_asset_modal, $bulk_client_modal] as $index => $bulk_modal) {
    $label = $index === 0 ? 'Bulk asset modal' : 'Bulk client modal';
    $assertContains("enforceUserPermission('module_support', 2)", $bulk_modal, "$label can be opened without ticket-write permission");
    $assertContains('name="bulk_ticket_template_id"', $bulk_modal, "$label cannot select a ticket template or published runbook");
    $assertContains('ticket_template_archived_at IS NULL', $bulk_modal, "$label offers an archived template");
    $assertContains('ticket_template_published_version_id', $bulk_modal, "$label does not distinguish a published runbook");
    $assertContains('name="use_primary_contact"', $bulk_modal, "$label cannot request per-client primary contacts");
    $assertContains('if ($single_client_id)', $bulk_modal, "$label does not restrict its project picker to a single client");
    $assertContains('project_client_id = $single_client_id', $bulk_modal, "$label project picker is not client-scoped");
    $assertContains('name="bulk_project" value="0"', $bulk_modal, "$label does not force a safe no-project value for a multi-client batch");
    $assertContains("subjectInput.required = templateSelect.value === '0'", $bulk_modal, "$label still requires an editable subject for an immutable template");
    $assertContains('$unpublished_history', $bulk_modal, "$label offers a template with runbook history but no published release");
}
$assertContains('$single_client_id = $count === 1', $bulk_client_modal, 'Bulk client modal offers a project when multiple clients are selected');
$assertContains('SELECT DISTINCT asset_client_id', $bulk_asset_modal, 'Bulk asset modal does not resolve the clients represented by selected assets');
$assertContains('count($asset_client_ids) === 1', $bulk_asset_modal, 'Bulk asset modal offers a project across multiple asset clients');
$assertContains("clientScopeSql('asset_client_id')", $bulk_asset_modal, 'Bulk asset modal resolves selected asset clients outside the user scope');
$assertContains("if (!\$ticket_template_id && \$subject_raw === '')", $bulk_asset, 'Bulk asset creation accepts a blank subject without a template');
$assertContains("if (!\$ticket_template_id && \$subject_raw === '')", $bulk_client, 'Bulk client creation accepts a blank subject without a template');
$assertContains('runbook_version_count', $bulk_asset, 'Bulk asset creation silently flattens a runbook with no published release');
$assertContains('runbook_version_count', $bulk_client, 'Bulk client creation silently flattens a runbook with no published release');

// Published runbooks cannot be flattened into recurring schedule snapshots.
$recurring_model = $read('agent/post/recurring_ticket_model.php');
$assertContains('runbookLatestPublishedVersionId($ticket_template_id)', $recurring_model, 'Recurring schedules accept published conditional runbooks');
$assertContains('$existing_ticket_template_id !== $ticket_template_id', $recurring_model, 'Legacy recurring schedules cannot retain their already-captured checklist safely');
$assertContains('Published conditional runbooks cannot be flattened into recurring schedules', $recurring_model, 'Recurring runbook rejection is not explained to the operator');
$assertContains('redirect();', $recurring_model, 'A rejected recurring runbook link continues to persist');

// Published execution content/order and its audit evidence are retained.
$task_post = $read('agent/post/task.php');
$task_edit = $section(
    $task_post,
    "if (isset(\$_POST['edit_ticket_task']))",
    "if (isset(\$_GET['delete_task']))",
    'task edit handler'
);
$assertContains('$published_runbook_task', $task_edit, 'Task editing does not distinguish immutable published tasks');
$assertContains("\$task_name = escapeSql(\$existing_task['task_name']);", $task_edit, 'Published task names can be rewritten');
$assertContains("\$task_order = intval(\$existing_task['task_order']);", $task_edit, 'Published task order can be rewritten through task edit');
$assertContains("\$task_completion_estimate = intval(\$existing_task['task_completion_estimate']);", $task_edit, 'Published task estimates can be rewritten');
$assertNotContains('task_instructions =', $task_edit, 'Published task instructions can be rewritten through the operational editor');
$assertNotContains('task_evidence_required =', $task_edit, 'Published task evidence rules can be rewritten through the operational editor');
$assertNotContains('task_evidence_prompt =', $task_edit, 'Published task evidence prompts can be rewritten through the operational editor');

$ajax = $read('agent/ajax.php');
$task_order = $section(
    $ajax,
    "if (isset(\$_POST['update_ticket_tasks_order']))",
    "if (isset(\$_POST['update_task_templates_order']))",
    'ticket task order handler'
);
$assertContains('AND task_runbook_version_task_id = 0', $task_order, 'Published execution tasks can be reordered');

$task_delete = $section(
    $task_post,
    "if (isset(\$_GET['delete_task']))",
    "if (isset(\$_GET['complete_task']))",
    'task deletion handler'
);
$assertContains('task_runbook_version_task_id', $task_delete, 'Task deletion does not load published identity');
$assertContains('Published runbook tasks cannot be deleted', $task_delete, 'Published execution tasks can be deleted');

$evidence_delete = $section(
    $task_post,
    "if (isset(\$_POST['delete_task_evidence']))",
    "if (isset(\$_POST['add_ticket_task_approver']))",
    'task evidence deletion handler'
);
$assertContains('task_completed_at', $evidence_delete, 'Evidence deletion does not load immutable completion state');
$assertContains('Evidence on a completed task is part of the', $evidence_delete, 'Completed-task evidence can be removed');
$assertContains('any ticket attachment was retained', $evidence_delete, 'Deleting an evidence reference also implies attachment deletion');

$attachment_delete = $section(
    $ticket_post,
    "if (isset(\$_GET['delete_ticket_attachment']))",
    "if (isset(\$_POST['edit_ticket_reply']))",
    'ticket attachment deletion handler'
);
$assertContains('task_evidence_attachment_id = $attachment_id', $attachment_delete, 'Evidence-bearing ticket attachments can be deleted');
$assertContains('retained as runbook evidence', $attachment_delete, 'Evidence attachment retention is not enforced');
$assertContains('a.ticket_attachment_ticket_id = t.task_ticket_id', $runbooks, 'File evidence can be satisfied by an attachment from another ticket');

$single_ticket_delete = $section(
    $ticket_post,
    "if (isset(\$_GET['delete_ticket']))",
    "if (isset(\$_POST['bulk_delete_tickets']))",
    'ticket deletion handler'
);
$bulk_ticket_delete = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_delete_tickets']))",
    "if (isset(\$_POST['bulk_assign_ticket']))",
    'bulk ticket deletion handler'
);
$assertContains('runbook_executions', $single_ticket_delete, 'A ticket with an execution can be permanently deleted');
$assertContains('cannot be permanently deleted', $single_ticket_delete, 'Single-ticket execution retention is not enforced');
$assertContains('runbook_executions', $bulk_ticket_delete, 'Bulk deletion can remove a ticket execution');
$assertContains('$skipped_count++', $bulk_ticket_delete, 'Bulk deletion does not retain execution tickets individually');

$client_delete = $section(
    $client_post,
    "if (isset(\$_GET['delete_client']))",
    "if (isset(\$_POST[\"import_clients_csv\"]))",
    'client deletion handler'
);
$assertContains('FROM runbook_executions', $client_delete, 'A client with execution records can be permanently deleted');
$assertContains('INNER JOIN tickets ON ticket_id = runbook_execution_ticket_id', $client_delete, 'Client execution retention is not linked through its tickets');
$assertContains('cannot be permanently deleted', $client_delete, 'Client execution retention is not enforced');

// Task transitions are legal, compare-and-set, auditable and refresh their
// dependency graph. Skipping is terminal and intentionally bypasses approvals.
$complete = $section(
    $task_post,
    "if (isset(\$_GET['complete_task']))",
    "if (isset(\$_GET['undo_complete_task']))",
    'task completion handler'
);
$waiting = $section(
    $task_post,
    "if (isset(\$_POST['set_task_waiting']))",
    "if (isset(\$_POST['resume_task']))",
    'task waiting handler'
);
$resume = $section(
    $task_post,
    "if (isset(\$_POST['resume_task']))",
    "if (isset(\$_POST['skip_runbook_task']))",
    'task resume handler'
);
$skip = $section(
    $task_post,
    "if (isset(\$_POST['skip_runbook_task']))",
    "if (isset(\$_POST['add_task_evidence']))",
    'task skip handler'
);
$undo_complete = $section(
    $task_post,
    "if (isset(\$_GET['undo_complete_task']))",
    "if (isset(\$_POST['set_task_waiting']))",
    'task reopen handler'
);
$complete_all = $section(
    $task_post,
    "if (isset(\$_GET['complete_all_tasks']))",
    "if (isset(\$_GET['undo_complete_all_tasks']))",
    'complete all tasks handler'
);
$undo_complete_all_start = strpos($task_post, "if (isset(\$_GET['undo_complete_all_tasks']))");
$undo_complete_all = $undo_complete_all_start === false
    ? '' : substr($task_post, $undo_complete_all_start);
$assertTrue($undo_complete_all !== '', 'Could not isolate reopen all tasks handler');
$assertContains('runbookTaskCanComplete($task_id)', $complete, 'Task completion bypasses dependency, approval or evidence gates');
$assertContains("AND task_state = 'Ready' AND task_completed_at IS NULL", $complete, 'Task completion is not an idempotent Ready-to-Completed transition');
$assertContains('mysqli_affected_rows($mysqli) !== 1', $complete, 'Concurrent task completion is not detected');
$assertContains("if (\$task['task_state'] !== 'Ready')", $waiting, 'A non-ready task can enter Waiting');
$assertContains("WHERE task_id = \$task_id AND task_state = 'Ready'", $waiting, 'Waiting transition is not compare-and-set');
$assertContains("\$task['task_state'] !== 'Waiting'", $resume, 'A non-waiting task can be resumed');
$assertContains('runbookEvaluateCondition(', $resume, 'Resume does not re-evaluate the published condition');
$assertContains('The task no longer has an active owner', $resume, 'Resume ignores an unresolved required owner');
$assertContains("WHERE task_id = \$task_id AND task_state = 'Waiting'", $resume, 'Resume transition is not compare-and-set');
$assertContains("in_array(\$task['task_state'], ['Completed', 'Skipped'], true)", $skip, 'A terminal task can be skipped again');
$assertContains('mysqli_begin_transaction($mysqli)', $skip, 'Skip state and audit evidence are not atomic');
$assertContains("task_evidence_note = 'Skipped: \$reason_sql'", $skip, 'A skip reason is not retained as evidence');
$assertContains("\$approval_rule_valid && \$state !== 'Skipped'", $runbooks, 'Condition-skipped tasks still create pending approvals');
$assertContains("WHERE task_ticket_id = \$ticket_id AND task_state <> 'Skipped'", $runbooks, 'Approvals on intentionally skipped tasks still block ticket resolution');
$assertContains("AND task_state NOT IN ('Completed','Skipped')", $task_post, 'Approval retry/reroute can mutate a terminal task');
$approval_delete = $section(
    $task_post,
    "if (isset(\$_POST['delete_ticket_task_approver']))",
    "if (isset(\$_GET['complete_all_tasks']))",
    'task approval deletion handler'
);
$assertContains('task_runbook_version_task_id', $approval_delete, 'Published approval deletion does not load immutable task identity');
$assertContains('A published runbook approval cannot be removed', $approval_delete, 'A published runbook approval can be deleted');
$assertContains('validateCSRFToken()', $approval_delete, 'Approval deletion is not a protected POST mutation');
$assertContains('runbookLockOpenTicket', $approval_delete, 'Approval deletion does not serialize against ticket closure');
$assertNotContains("\$_GET['delete_ticket_task_approver']", $task_post, 'Approval deletion still mutates through GET');
$assertContains("if (!in_array(\$state, ['Ready', 'Blocked'], true))", $runbooks, 'Dependency refresh mutates terminal or waiting task states');
$assertContains('reopenRunbookTaskAndDependents($task_id', $task_post, 'Undoing completion leaves completed dependents inconsistent');
$assertContains('runbookTaskCanComplete($task_id)', $complete_all, 'Complete-all bypasses per-task gates');

// Every runtime state mutation holds the ticket lock, uses a compare-and-set
// write and appends an immutable state event before committing.
foreach ([
    [$complete, 'Task completion'],
    [$waiting, 'Ready-to-Waiting'],
    [$resume, 'Waiting-to-Ready'],
    [$skip, 'Task skip'],
] as [$transition, $label]) {
    $assertContains('mysqli_begin_transaction($mysqli)', $transition, "$label is not transactional");
    $assertRegex('/runbookLockOpenTicket(?:ForTask)?\s*\(/', $transition, "$label does not serialize against ticket resolution");
    $assertContains('mysqli_affected_rows($mysqli)', $transition, "$label is not compare-and-set");
    $assertContains('runbookRecordTaskStateEvent(', $transition, "$label has no immutable state audit event");
    $assertContains('mysqli_commit($mysqli)', $transition, "$label does not commit its state and audit together");
    $assertContains('mysqli_rollback($mysqli)', $transition, "$label does not roll back a partial state mutation");
}
$assertOrdered(
    $complete,
    ['mysqli_begin_transaction($mysqli)', 'runbookLockOpenTicket', 'runbookTaskCanComplete($task_id)', "task_state = 'Completed'", 'runbookRecordTaskStateEvent(', 'mysqli_commit($mysqli)'],
    'Task completion does not re-check its gates under the ticket lock before its audited CAS'
);
$assertContains('mysqli_begin_transaction($mysqli)', $undo_complete, 'Single-task reopen is not transactional');
$assertRegex('/runbookLockOpenTicket(?:ForTask)?\s*\(/', $undo_complete, 'Single-task reopen does not serialize against ticket resolution');
$assertContains('reopenRunbookTaskAndDependents($task_id', $undo_complete, 'Single-task reopen bypasses dependent-task consistency');
$assertContains('mysqli_commit($mysqli)', $undo_complete, 'Single-task reopen does not commit atomically');
$assertContains('mysqli_rollback($mysqli)', $undo_complete, 'Single-task reopen does not roll back atomically');

foreach ([
    [$complete_all, 'Complete-all'],
    [$undo_complete_all, 'Reopen-all'],
] as [$transition, $label]) {
    $assertContains('mysqli_begin_transaction($mysqli)', $transition, "$label is not transactional");
    $assertContains('runbookLockOpenTicket($ticket_id)', $transition, "$label does not serialize against ticket resolution");
    $assertContains('FOR UPDATE', $transition, "$label does not lock its task set");
    $assertContains('runbookRecordTaskStateEvent(', $transition, "$label has no per-task immutable state audit event");
    $assertContains('mysqli_commit($mysqli)', $transition, "$label does not commit its state and audit together");
    $assertContains('mysqli_rollback($mysqli)', $transition, "$label does not roll back a partial state mutation");
}

$instantiate_start = strpos($runbooks, 'function instantiateRunbookForTicket(');
$instantiate_end = strpos($runbooks, 'function refreshRunbookTaskStates(', $instantiate_start ?: 0);
$instantiate = ($instantiate_start !== false && $instantiate_end !== false)
    ? substr($runbooks, $instantiate_start, $instantiate_end - $instantiate_start) : '';
$refresh_start = strpos($runbooks, 'function refreshRunbookTaskStates(');
$refresh_end = strpos($runbooks, 'function reopenRunbookTaskAndDependents(', $refresh_start ?: 0);
$refresh = ($refresh_start !== false && $refresh_end !== false)
    ? substr($runbooks, $refresh_start, $refresh_end - $refresh_start) : '';
$reopen_start = strpos($runbooks, 'function reopenRunbookTaskAndDependents(');
$reopen_end = strpos($runbooks, 'function runbookTaskEvidenceSatisfied(', $reopen_start ?: 0);
$reopen = ($reopen_start !== false && $reopen_end !== false)
    ? substr($runbooks, $reopen_start, $reopen_end - $reopen_start) : '';
$assertContains('runbookRecordTaskStateEvent(', $instantiate, 'Initial runbook task states are not audited');
$assertContains('runbookRecordTaskStateEvent(', $refresh, 'Dependency-driven Blocked/Ready transitions are not audited');
$assertContains('runbookRecordTaskStateEvent(', $reopen, 'Dependent-task reopening is not audited per task');

// Authenticated portal and bearer-link decisions may render via GET, but all
// state mutations are POST-only, one-shot and constrained to eligible contacts.
$portal_post = $read('client/post.php');
$portal_ticket = $read('client/ticket.php');
$portal_decide_marker = "if (isset(\$_POST['decide_client_ticket_task_approval']))";
$assertContains($portal_decide_marker, $portal_post, 'Authenticated portal approvals do not use POST');
$portal_decide = $section(
    $portal_post,
    $portal_decide_marker,
    "if (isset(\$_POST['add_ticket_feedback']))",
    'portal approval decision handler'
);
$assertContains('validateCSRFToken()', $portal_decide, 'Portal approval POST lacks CSRF validation');
$assertContains("in_array(\$decision, ['approved', 'declined'], true)", $portal_decide, 'Portal approval accepts an unknown decision');
$assertContains('ticket_client_id = $session_client_id', $portal_decide, 'Portal approval can cross the session client boundary');
$assertContains('approval_status = \'pending\'', $portal_decide, 'Portal approval is not one-shot');
$assertContains('mysqli_affected_rows($mysqli) !== 1', $portal_decide, 'Portal approval does not detect a repeated decision');
$assertRegex('/approval_type.*?session_contact_(?:primary|is_technical_contact|is_billing_contact)/s', $portal_decide, 'Portal approval does not validate the contact role');
$assertContains('mysqli_begin_transaction($mysqli)', $portal_decide, 'Portal approval decision is not transactional');
$assertContains('runbookLockOpenTicket($ticket_id)', $portal_decide, 'Portal approval decision does not serialize against ticket closure');
$assertContains("approval_url_key = '', approval_url_expires_at = NULL", $portal_decide, 'Portal approval does not invalidate its alternate bearer credential');
$assertContains('mysqli_commit($mysqli)', $portal_decide, 'Portal approval does not commit its one-shot decision atomically');
$assertContains('mysqli_rollback($mysqli)', $portal_decide, 'Portal approval cannot roll back a failed one-shot decision');
$assertContains('method="post"', $portal_ticket, 'Portal approval UI still mutates through a link');
$assertContains('name="decide_client_ticket_task_approval"', $portal_ticket, 'Portal approval UI does not submit the POST decision handler');
if (str_contains($portal_post, "if (isset(\$_GET['approve_ticket_task']))")) {
    $portal_get = $section(
        $portal_post,
        "if (isset(\$_GET['approve_ticket_task']))",
        $portal_decide_marker,
        'legacy portal approval GET handler'
    );
    $assertNotContains('UPDATE task_approvals', $portal_get, 'Portal approval still mutates state through GET');
}

$guest_post = $read('guest/guest_post.php');
$guest_page = $read('guest/guest_approve_ticket_task.php');
$guest_get = $section(
    $guest_post,
    "if (isset(\$_GET['approve_ticket_task']))",
    "if (isset(\$_POST['decide_ticket_task_approval']))",
    'legacy guest approval GET handler'
);
$guest_decide = $section(
    $guest_post,
    "if (isset(\$_POST['decide_ticket_task_approval']))",
    "if (isset(\$_GET['export_quote_pdf']))",
    'guest approval POST handler'
);
$assertNotContains('UPDATE task_approvals', $guest_get, 'Guest bearer-link GET still mutates approval state');
$assertContains('rawurlencode($url_key)', $guest_get, 'Legacy guest approval URLs are not safely redirected to confirmation');
$assertContains("in_array(\$decision, ['approved', 'declined'], true)", $guest_decide, 'Guest approval accepts an unknown decision');
$assertContains("approval_scope = 'client'", $guest_decide, 'Guest approval can decide an internal request');
$assertContains("approval_status = 'pending'", $guest_decide, 'Guest approval is not one-shot');
$assertContains('runbookApprovalTokenMatches(', $guest_decide, 'Guest bearer credential is not compared through the token-safe helper');
$assertContains('approval_url_expires_at', $guest_decide, 'Expired guest approval credentials remain actionable');
$assertContains('mysqli_affected_rows($mysqli) !== 1', $guest_decide, 'Guest approval does not detect a repeated decision');
$assertContains('mysqli_begin_transaction($mysqli)', $guest_decide, 'Guest approval decision is not transactional');
$assertContains('runbookLockOpenTicket($ticket_id)', $guest_decide, 'Guest approval decision does not serialize against ticket closure');
$assertContains("approval_url_key = ''", $guest_decide, 'Used guest approval credentials are retained');
$assertContains('mysqli_commit($mysqli)', $guest_decide, 'Guest approval does not commit its one-shot decision atomically');
$assertContains('mysqli_rollback($mysqli)', $guest_decide, 'Guest approval cannot roll back a failed one-shot decision');
$assertContains('method="post"', $guest_page, 'Guest approval confirmation still mutates through GET');
$assertContains('name="decide_ticket_task_approval"', $guest_page, 'Guest approval page does not submit the POST decision handler');
$assertContains('runbookApprovalTokenMatches(', $guest_page, 'Guest approval page does not validate the bearer token safely');
$assertContains('approval_url_expires_at', $guest_page, 'Guest approval page presents an expired credential as actionable');
$assertContains('Referrer-Policy: no-referrer', $guest_page, 'Guest approval bearer token can leak through a referrer');
$assertContains('Cache-Control: no-store', $guest_page, 'Guest approval bearer page can be cached');

$assertContains('runbookApprovalTokenHash($url_key_raw)', $runbooks, 'New runbook approvals store a reusable bearer credential in plaintext');
$assertContains('runbookApprovalTokenExpiry()', $runbooks, 'New runbook approvals have no credential expiry');
$assertContains('runbookApprovalTokenHash($url_key_raw)', $task_post, 'Retried or rerouted approvals store a reusable bearer credential in plaintext');
$assertContains('approval_url_expires_at', $task_post, 'Retried or rerouted approvals have no credential expiry');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook lifecycle and security contracts passed.\n";
