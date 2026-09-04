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

$read = function (string $relative_path) use ($root, &$failures): string {
    $path = $root . '/' . $relative_path;
    $contents = @file_get_contents($path);
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

$assertAtomicTicketGate = function (string $contents, string $label) use (&$failures): void {
    $gate = strpos($contents, 'runbookTicketCanResolve(');
    if ($gate === false) {
        $failures[] = "$label has no runbook gate";
        return;
    }

    $before_gate = substr($contents, 0, $gate);
    $begin = strrpos($before_gate, 'mysqli_begin_transaction($mysqli)');
    $lock_positions = array_values(array_filter([
        strrpos($before_gate, 'runbookLockOpenTicket('),
        strrpos($before_gate, 'runbookLockTicketForTransition('),
    ], static fn ($position) => $position !== false));
    $lock = $lock_positions ? max($lock_positions) : false;
    $update = strpos($contents, 'UPDATE tickets SET', $gate);
    $affected = $update === false ? false : strpos($contents, 'mysqli_affected_rows($mysqli)', $update);
    $commit = $affected === false ? false : strpos($contents, 'mysqli_commit($mysqli)', $affected);

    if ($begin === false || $lock === false || $update === false || $affected === false || $commit === false
        || !($begin < $lock && $lock < $gate && $gate < $update && $update < $affected && $affected < $commit)) {
        $failures[] = "$label does not order transaction, parent lock, gate, conditional update and commit safely";
        return;
    }

    $update_contract = substr($contents, $update, $affected - $update);
    if (!str_contains($update_contract, 'WHERE ticket_id =')
        || !str_contains($update_contract, 'ticket_status')
        || (!str_contains($update_contract, 'ticket_resolved_at')
            && !str_contains($update_contract, 'ticket_closed_at')
            && !str_contains($update_contract, 'resolved_at_predicate')
            && !str_contains($update_contract, 'closed_at_predicate'))) {
        $failures[] = "$label update is not conditional on the observed ticket lifecycle";
    }
    if (!str_contains($contents, 'mysqli_rollback($mysqli)')) {
        $failures[] = "$label cannot roll back a failed gate or compare-and-set";
    }
};

$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0010-versioned-runbooks.php');

$required_tables = [
    'task_template_dependencies',
    'runbook_versions',
    'runbook_version_tasks',
    'runbook_version_task_dependencies',
    'runbook_executions',
    'task_dependencies',
    'task_evidence',
    'task_state_events',
];
foreach ($required_tables as $table) {
    $assertContains("CREATE TABLE `$table`", $schema, "Baseline schema does not create $table");
    $assertContains("CREATE TABLE IF NOT EXISTS `$table`", $migration, "N45 runbook migration does not create $table");
}

$required_columns = [
    'ticket_template_runbook_key',
    'ticket_template_runbook_type',
    'ticket_template_published_version_id',
    'ticket_template_runbook_version_id',
    'task_template_key',
    'task_template_condition_type',
    'task_template_owner_type',
    'task_template_due_offset_minutes',
    'task_template_initial_state',
    'task_template_approval_scope',
    'task_template_evidence_type',
    'task_state',
    'task_assigned_to',
    'task_due_at',
    'task_condition_result',
    'task_evidence_required',
    'task_runbook_version_task_id',
    'approval_created_at',
    'approval_decided_at',
    'approval_url_expires_at',
    'task_state_event_from_state',
    'task_state_event_to_state',
    'task_state_event_reason',
    'task_state_event_actor_type',
    'task_state_event_actor_id',
    'task_state_event_created_at',
    'runbook_execution_snapshot',
    'runbook_execution_snapshot_hash',
];
foreach ($required_columns as $column) {
    $assertContains("`$column`", $schema, "Baseline schema is missing $column");
    $assertContains("`$column`", $migration, "N45 runbook migration is missing $column");
}

$schema_invariants = [
    'UNIQUE KEY `ticket_template_runbook_key_unique` (`ticket_template_runbook_key`)' => 'Runbook keys are not unique',
    'UNIQUE KEY `task_template_key_unique` (`task_template_ticket_template_id`,`task_template_key`)' => 'Task keys are not unique within a template',
    'UNIQUE KEY `runbook_version_hash` (`runbook_version_ticket_template_id`,`runbook_version_definition_hash`)' => 'Definition hashes do not make publishing idempotent',
    'UNIQUE KEY `runbook_version_task_key` (`runbook_version_task_runbook_version_id`,`runbook_version_task_key`)' => 'Published task keys are not unique within a version',
    'UNIQUE KEY `runbook_execution_ticket` (`runbook_execution_ticket_id`)' => 'A ticket can receive multiple runbook executions',
    'PRIMARY KEY (`task_id`,`depends_on_task_id`)' => 'Runtime dependency edges are not unique',
    'KEY `approval_task_status` (`approval_task_id`,`approval_status`)' => 'Approval gate lookups are not indexed',
    'KEY `task_evidence_task` (`task_evidence_task_id`,`task_evidence_type`)' => 'Task evidence lookups are not indexed',
    'KEY `task_state_event_task` (`task_state_event_task_id`,`task_state_event_created_at`)' => 'Task state audit history is not indexed',
];
foreach ($schema_invariants as $contract => $message) {
    $assertContains($contract, $schema, $message . ' in the baseline schema');
    $assertContains($contract, $migration, $message . ' in the migration');
}

$functions = $read('functions.php');
$app = $read('functions/app.php');
$runbooks_require = "n45RequireModule('runbooks');";
$app_require = "require_once __DIR__ . '/functions/app.php';";
$assertContains("require_once __DIR__ . '/n45/bootstrap.php';", $functions, 'The N45 module boundary is not loaded centrally');
$assertContains($runbooks_require, $functions, 'The runbook service is not loaded through the N45 module boundary');
$assertTrue(
    strpos($functions, $runbooks_require) < strpos($functions, $app_require),
    'The shared task helper loads before the runbook service'
);
$assertContains('function addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id = 0, $caller_transaction = false)', $app, 'The central task-instantiation helper cannot accept a pinned version and caller transaction');
$assertContains('ticket_template_published_version_id', $app, 'The central helper does not read the authoritative published runbook pointer');
$assertContains('runbook_version_count', $app, 'The central helper silently flattens a template with runbook history but no published release');
$assertTrue(!str_contains($app, 'runbookLatestPublishedVersionId($ticket_template_id)'), 'The central helper guesses a historical version instead of using the release pointer');
$assertContains('return instantiateRunbookForTicket($ticket_id, $ticket_template_id, [', $app, 'The central helper bypasses immutable runbook instantiation');
$assertContains("'caller_transaction' => \$caller_transaction", $app, 'The central helper does not propagate its caller transaction');
$assertTrue(substr_count($app, 'instantiateRunbookForTicket(') === 1, 'Published runbooks are instantiated outside the single central branch');

$ticket_post = $read('agent/post/ticket.php');
$ticket_creation = $section(
    $ticket_post,
    "if (isset(\$_POST['add_ticket']))",
    "if (isset(\$_POST['edit_ticket']))",
    'agent ticket creation handler'
);
$assertContains('addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $pinned_runbook_version_id, true)', $ticket_creation, 'Normal ticket creation does not preserve the selected published version in its outer transaction');
$assertContains('addTasksFromTicketTemplate($ticket_id, $ticket_template_id, 0, true)', $ticket_creation, 'Normal ticket creation lost the legacy central fallback in its outer transaction');

$bulk_asset_creation = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_add_asset_ticket']))",
    "if (isset(\$_POST['add_ticket_reply']))",
    'bulk asset ticket creation handler'
);
$assertContains('addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id, true)', $bulk_asset_creation, 'Bulk asset tickets bypass central runbook instantiation or its outer transaction');

$client_post = $read('agent/post/client.php');
$assertContains('addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id, true)', $client_post, 'Bulk client tickets bypass central runbook instantiation or its outer transaction');

$project_post = $read('agent/post/project.php');
$assertContains('addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id, true)', $project_post, 'Project template tickets do not preserve their pinned runbook version in the project transaction');

$agent_gate_sections = [
    ["if (isset(\$_POST['bulk_merge_tickets']))", "if (isset(\$_POST['bulk_resolve_tickets']))", 'bulk ticket merge'],
    ["if (isset(\$_POST['bulk_resolve_tickets']))", "if (isset(\$_POST['bulk_ticket_reply']))", 'bulk ticket resolve'],
    ["if (isset(\$_POST['bulk_ticket_reply']))", "if (isset(\$_POST['bulk_add_ticket_project']))", 'bulk ticket reply'],
    ["if (isset(\$_POST['add_ticket_reply']))", "if (isset(\$_GET['delete_ticket_attachment']))", 'ticket reply'],
    ["if (isset(\$_POST['merge_ticket']))", "if (isset(\$_POST['change_client_ticket']))", 'ticket merge'],
    ["if (isset(\$_GET['resolve_ticket']))", "if (isset(\$_GET['close_ticket']))", 'ticket resolve'],
    ["if (isset(\$_GET['close_ticket']))", "if (isset(\$_GET['reopen_ticket']))", 'ticket close'],
];
foreach ($agent_gate_sections as [$start, $end, $label]) {
    $gate_section = $section($ticket_post, $start, $end, $label . ' handler');
    $assertContains('runbookTicketCanResolve($ticket_id)', $gate_section, ucfirst($label) . ' can bypass runbook gates');
    $assertAtomicTicketGate($gate_section, ucfirst($label));
}
$assertTrue(substr_count($ticket_post, 'runbookTicketCanResolve($ticket_id)') === 7, 'The agent ticket mutation surface does not contain all seven runbook gates');
$assertContains('if (in_array($effective_ticket_status, [4, 5], true))', $ticket_post, 'Bulk replies do not gate close requests');
$assertContains('if (in_array($ticket_status, [4, 5], true))', $ticket_post, 'Agent replies do not gate close requests');

$api_resolve = $read('api/v1/tickets/resolve.php');
$assertContains('runbookTicketCanResolve($ticket_id)', $api_resolve, 'Ticket resolve API can bypass runbook gates');
$assertContains('if (!$can_resolve)', $api_resolve, 'Ticket resolve API ignores a failed runbook gate');
$assertAtomicTicketGate($api_resolve, 'Ticket resolve API');

$api_reply = $read('api/v1/ticket_replies/create.php');
$assertContains('if (in_array($reply_ticket_status, [4, 5], true))', $api_reply, 'Ticket reply API does not isolate resolve and close requests');
$assertContains('runbookTicketCanResolve($ticket_id)', $api_reply, 'Ticket reply API can resolve through unfinished runbook work');
$assertContains('$reply_ticket_status = $original_ticket_status;', $api_reply, 'A gated API reply does not preserve the current ticket status');
$assertAtomicTicketGate($api_reply, 'Ticket reply API terminal transition');

$portal_post = $read('client/post.php');
$portal_resolve = $section(
    $portal_post,
    "if (isset(\$_GET['resolve_ticket']))",
    "if (isset(\$_GET['reopen_ticket']))",
    'portal ticket resolve handler'
);
$portal_close = $section(
    $portal_post,
    "if (isset(\$_GET['close_ticket']))",
    "if (isset(\$_GET['logout']))",
    'portal ticket close handler'
);
$assertContains('runbookTicketCanResolve($ticket_id)', $portal_resolve, 'Portal ticket resolution can bypass runbook gates');
$assertContains('runbookTicketCanResolve($ticket_id)', $portal_close, 'Portal ticket closure can bypass runbook gates');
$assertTrue(substr_count($portal_post, 'runbookTicketCanResolve($ticket_id)') === 2, 'The portal does not contain both resolve and close gates');
$assertAtomicTicketGate($portal_resolve, 'Portal ticket resolution');
$assertAtomicTicketGate($portal_close, 'Portal ticket closure');

$guest_post = $read('guest/guest_post.php');
$guest_close = $section(
    $guest_post,
    "if (isset(\$_GET['close_ticket'], \$_GET['url_key']))",
    "if (isset(\$_GET['add_ticket_feedback'], \$_GET['url_key']))",
    'guest ticket close handler'
);
$assertContains('runbookTicketCanResolve($ticket_id)', $guest_close, 'Guest bearer-link closure can bypass runbook gates');
$assertContains('if (!$can_close)', $guest_close, 'Guest bearer-link closure ignores a failed runbook gate');
$assertAtomicTicketGate($guest_close, 'Guest bearer-link closure');

$ajax = $read('agent/ajax.php');
$kanban_status_positions = $section(
    $ajax,
    "if (isset(\$_POST['update_kanban_status_position']))",
    "if (isset(\$_POST['update_kanban_ticket']))",
    'kanban status-column order handler'
);
$assertContains('validateCSRFToken()', $kanban_status_positions, 'Kanban status-column ordering does not validate its CSRF token');
$kanban = $section(
    $ajax,
    "if (isset(\$_POST['update_kanban_ticket']))",
    "if (isset(\$_POST['update_ticket_tasks_order']))",
    'kanban ticket update handler'
);
$assertContains("\$status === \$statuses['Resolved']", $kanban, 'Kanban resolution handling is missing');
$assertContains("\$actual_old_status !== \$statuses['Resolved']", $kanban, 'Kanban resolution does not compare against the locked status');
$assertContains('runbookTicketCanResolve($ticket_id)', $kanban, 'Kanban can resolve through unfinished runbook work');
$assertContains('http_response_code(409)', $kanban, 'Kanban does not report a gated resolution as a conflict');
$assertContains('validateCSRFToken()', $kanban, 'Kanban mutation does not validate its CSRF token');
$assertAtomicTicketGate($kanban, 'Kanban terminal transition');
$kanban_page = $read('agent/ticket_kanban.php');
$kanban_js = $read('agent/js/tickets_kanban.js');
$assertContains('const CSRF_TOKEN =', $kanban_page, 'Kanban page does not expose its session CSRF token to the mutation client');
$assertTrue(
    preg_match('/update_kanban_status_position:\s*true,\s*csrf_token:\s*CSRF_TOKEN/s', $kanban_js) === 1,
    'Kanban status-column ordering does not submit the session CSRF token'
);
$assertTrue(
    preg_match('/update_kanban_ticket:\s*true,\s*csrf_token:\s*CSRF_TOKEN/s', $kanban_js) === 1,
    'Kanban ticket movement does not submit the session CSRF token'
);

$automation = $read('functions/automation.php');
$assertContains('if ($resolve && intval($ticket[\'ticket_status\']) !== 4)', $automation, 'Automation recovery resolution handling is missing');
$assertContains('runbookTicketCanResolve($ticket_id)', $automation, 'Automation recovery can resolve through unfinished runbook work');
$assertContains('automatic resolution was blocked by unfinished runbook work', $automation, 'Blocked automation recovery is not recorded in ticket history');
$automation_reply_start = strpos($automation, 'function automationAddIncidentReply(');
$automation_reply = $automation_reply_start === false ? '' : substr($automation, $automation_reply_start);
$assertAtomicTicketGate($automation_reply, 'Automation incident resolution');

$nightly = $read('cron/nightly_tasks.php');
$assertContains('runbookTicketCanResolve($ticket_id)', $nightly, 'Nightly automatic closure can bypass runbook gates');
$assertAtomicTicketGate($nightly, 'Nightly automatic ticket closure');

$email_parser = $read('cron/ticket_email_parser.php');
$parser_begin = strpos($email_parser, 'mysqli_begin_transaction($mysqli)');
$parser_lock = strpos($email_parser, 'runbookLockTicketForReopen($ticket_id)', $parser_begin ?: 0);
$parser_update = strpos($email_parser, 'UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL', $parser_lock ?: 0);
$parser_affected = strpos($email_parser, 'mysqli_affected_rows($mysqli) !== 1', $parser_update ?: 0);
$parser_commit = strpos($email_parser, 'mysqli_commit($mysqli)', $parser_affected ?: 0);
$assertTrue(
    $parser_begin !== false && $parser_lock !== false && $parser_update !== false
        && $parser_affected !== false && $parser_commit !== false
        && $parser_begin < $parser_lock && $parser_lock < $parser_update
        && $parser_update < $parser_affected && $parser_affected < $parser_commit,
    'Inbound email replies do not atomically lock and compare-and-set a ticket reopen'
);
$assertContains('mysqli_rollback($mysqli)', $email_parser, 'A failed inbound email ticket reopen cannot roll back');

$assertContains('runbookTicketCanResolve(intval($project_ticket[\'ticket_id\']))', $project_post, 'Project closure can bypass child ticket runbook gates');
$project_close = $section(
    $project_post,
    "if (isset(\$_GET['close_project']))",
    "if (isset(\$_GET['archive_project']))",
    'project close handler'
);
$project_begin = strpos($project_close, 'mysqli_begin_transaction($mysqli)');
$project_lock = strpos($project_close, 'ORDER BY ticket_id ASC FOR UPDATE', $project_begin ?: 0);
$project_gate = strpos($project_close, 'runbookTicketCanResolve(', $project_lock ?: 0);
$project_update = strpos($project_close, 'UPDATE projects SET project_completed_at = NOW()', $project_gate ?: 0);
$project_affected = strpos($project_close, 'mysqli_affected_rows($mysqli) !== 1', $project_update ?: 0);
$project_commit = strpos($project_close, 'mysqli_commit($mysqli)', $project_affected ?: 0);
$assertTrue(
    $project_begin !== false && $project_lock !== false && $project_gate !== false
        && $project_update !== false && $project_affected !== false && $project_commit !== false
        && $project_begin < $project_lock && $project_lock < $project_gate
        && $project_gate < $project_update && $project_update < $project_affected
        && $project_affected < $project_commit,
    'Project closure does not atomically lock child tickets, apply every runbook gate and compare-and-set completion'
);
$assertContains('mysqli_rollback($mysqli)', $project_close, 'A gated or concurrent project close cannot roll back');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook schema and wiring tests passed.\n";
