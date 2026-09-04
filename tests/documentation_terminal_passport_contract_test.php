<?php

/*
 * Exhaustive source-contract inventory for authoritative ticket terminal
 * transitions. A terminal compare-and-set and its Change Passport must commit
 * in one transaction. Adding a new terminal surface requires adding it here.
 */

$root = dirname(__DIR__);
$failures = [];

$assertTrue = static function ($condition, $message) use (&$failures) {
    if ($condition !== true) {
        $failures[] = $message;
    }
};
$read = static function ($path) use ($root, &$failures) {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
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
$assertAtomicPassport = static function ($contents, $label, $actor) use (&$failures) {
    $gate = strpos($contents, 'runbookTicketCanResolve(');
    $update = $gate === false ? false : strpos($contents, 'UPDATE tickets SET', $gate);
    $affected = $update === false ? false : strpos($contents, 'mysqli_affected_rows($mysqli)', $update);
    $passport = $affected === false ? false : strpos($contents, 'documentationRecordChangePassport(', $affected);
    $commit = $passport === false ? false : strpos($contents, 'mysqli_commit($mysqli)', $passport);
    if ($gate === false || $update === false || $affected === false || $passport === false || $commit === false
        || !($gate < $update && $update < $affected && $affected < $passport && $passport < $commit)) {
        $failures[] = "$label does not order gate, terminal CAS, Change Passport, and commit atomically";
        return;
    }
    $call_end = strpos($contents, ';', $passport);
    $call = $call_end === false ? '' : substr($contents, $passport, $call_end - $passport + 1);
    if (!preg_match('/,\s*true\s*\);\s*$/s', $call)) {
        $failures[] = "$label does not pass caller_transaction=true to the Change Passport API";
    }
    if ($actor === 'session' && strpos($call, '$session_user_id') === false) {
        $failures[] = "$label does not attribute its Change Passport to the authenticated internal user";
    }
    if ($actor === 'system' && !preg_match('/,\s*0\s*,\s*true\s*\);\s*$/s', $call)) {
        $failures[] = "$label does not attribute its external/system Change Passport to actor 0";
    }
    if (strpos($contents, 'mysqli_rollback($mysqli)') === false) {
        $failures[] = "$label cannot roll back a failed Change Passport";
    }
};

$ticket_post = $read('agent/post/ticket.php');
$agent_handlers = [
    ["if (isset(\$_POST['bulk_merge_tickets']))", "if (isset(\$_POST['bulk_resolve_tickets']))", 'Bulk merge'],
    ["if (isset(\$_POST['bulk_resolve_tickets']))", "if (isset(\$_POST['bulk_ticket_reply']))", 'Bulk resolve'],
    ["if (isset(\$_POST['bulk_ticket_reply']))", "if (isset(\$_POST['bulk_add_ticket_project']))", 'Bulk reply terminal transition'],
    ["if (isset(\$_POST['add_ticket_reply']))", "if (isset(\$_GET['delete_ticket_attachment']))", 'Agent reply terminal transition'],
    ["if (isset(\$_POST['merge_ticket']))", "if (isset(\$_POST['change_client_ticket']))", 'Ticket merge'],
    ["if (isset(\$_GET['resolve_ticket']))", "if (isset(\$_GET['close_ticket']))", 'Agent resolve'],
    ["if (isset(\$_GET['close_ticket']))", "if (isset(\$_GET['reopen_ticket']))", 'Agent close'],
];
foreach ($agent_handlers as [$start, $end, $label]) {
    $assertAtomicPassport($section($ticket_post, $start, $end, $label), $label, 'session');
}

$ajax = $read('agent/ajax.php');
$assertAtomicPassport($section(
    $ajax,
    "if (isset(\$_POST['update_kanban_ticket']))",
    "if (isset(\$_POST['update_ticket_tasks_order']))",
    'Kanban terminal transition'
), 'Kanban terminal transition', 'session');

$api_resolve = $read('api/v1/tickets/resolve.php');
$assertAtomicPassport($api_resolve, 'Ticket resolve API', 'session');
$api_close = $read('api/v1/tickets/close.php');
$assertAtomicPassport($api_close, 'Ticket close API', 'session');
$api_reply = $read('api/v1/ticket_replies/create.php');
$assertAtomicPassport($api_reply, 'Ticket reply API terminal transition', 'session');

$portal = $read('client/post.php');
$assertAtomicPassport($section(
    $portal,
    "if (isset(\$_GET['resolve_ticket']))",
    "if (isset(\$_GET['reopen_ticket']))",
    'Client portal resolve'
), 'Client portal resolve', 'system');
$assertAtomicPassport($section(
    $portal,
    "if (isset(\$_GET['close_ticket']))",
    "if (isset(\$_GET['logout']))",
    'Client portal close'
), 'Client portal close', 'system');

$guest = $read('guest/guest_post.php');
$assertAtomicPassport($section(
    $guest,
    "if (isset(\$_GET['close_ticket'], \$_GET['url_key']))",
    "if (isset(\$_GET['add_ticket_feedback'], \$_GET['url_key']))",
    'Guest close'
), 'Guest close', 'system');

$automation = $read('functions/automation.php');
$automation_start = strpos($automation, 'function automationAddIncidentReply(');
$assertAtomicPassport(
    $automation_start === false ? '' : substr($automation, $automation_start),
    'Automation recovery resolve',
    'system'
);

$nightly = $read('cron/nightly_tasks.php');
$assertAtomicPassport($section(
    $nightly,
    '// TICKET RESOLUTION/CLOSURE PROCESS',
    'if ($config_send_invoice_reminders == 1)',
    'Nightly automatic close'
), 'Nightly automatic close', 'system');

$project = $read('agent/post/project.php');
$project_close = $section(
    $project,
    "if (isset(\$_GET['close_project']))",
    "if (isset(\$_GET['archive_project']))",
    'Project close'
);
$project_update = strpos($project_close, 'UPDATE projects SET project_completed_at = NOW()');
$project_affected = $project_update === false ? false : strpos($project_close, 'mysqli_affected_rows($mysqli)', $project_update);
$project_passport = $project_affected === false ? false : strpos($project_close, 'documentationRecordChangePassport(', $project_affected);
$project_commit = $project_passport === false ? false : strpos($project_close, 'mysqli_commit($mysqli)', $project_passport);
$assertTrue(
    $project_update !== false && $project_affected !== false && $project_passport !== false && $project_commit !== false
        && $project_update < $project_affected && $project_affected < $project_passport
        && $project_passport < $project_commit,
    'Project close does not append child-ticket Change Passports inside its completion transaction'
);
$project_call_end = $project_passport === false ? false : strpos($project_close, ';', $project_passport);
$project_call = $project_call_end === false ? '' : substr($project_close, $project_passport, $project_call_end - $project_passport + 1);
$assertTrue(strpos($project_call, '$session_user_id') !== false, 'Project close loses authenticated Change Passport attribution');
$assertTrue(preg_match('/,\s*true\s*\);\s*$/s', $project_call) === 1, 'Project close does not use the caller transaction for Change Passports');

// File-level counts make this inventory fail when a new gate or passport site
// is added without an explicit transaction-order assertion above.
$inventory = [
    'agent/post/ticket.php' => [7, 7],
    'agent/ajax.php' => [1, 1],
    'agent/post/project.php' => [1, 1],
    'api/v1/tickets/resolve.php' => [1, 1],
    'api/v1/tickets/close.php' => [1, 1],
    'api/v1/ticket_replies/create.php' => [1, 1],
    'client/post.php' => [2, 2],
    'guest/guest_post.php' => [1, 1],
    'functions/automation.php' => [1, 1],
    'cron/nightly_tasks.php' => [1, 1],
];
$total_gates = 0;
$total_passports = 0;
foreach ($inventory as $path => [$expected_gates, $expected_passports]) {
    $contents = $read($path);
    $actual_gates = substr_count($contents, 'runbookTicketCanResolve(');
    $actual_passports = substr_count($contents, 'documentationRecordChangePassport(');
    $assertTrue($actual_gates === $expected_gates, "$path has an unaccounted lifecycle gate");
    $assertTrue($actual_passports === $expected_passports, "$path has an unaccounted or missing Change Passport site");
    $total_gates += $actual_gates;
    $total_passports += $actual_passports;
}
$assertTrue($total_gates === 17, 'The authoritative lifecycle-gate inventory is no longer exhaustive');
$assertTrue($total_passports === 17, 'The authoritative Change Passport inventory is no longer exhaustive');

// Detect a new runtime gate or direct literal/dynamic terminal writer outside
// the inventoried files. This deliberately excludes the gate definition itself.
$runtime_roots = ['agent', 'api', 'client', 'cron', 'functions', 'guest'];
$terminal_writer_files = array_fill_keys([
    'agent/post/ticket.php',
    'agent/ajax.php',
    'api/v1/tickets/resolve.php',
    'api/v1/tickets/close.php',
    'api/v1/ticket_replies/create.php',
    'client/post.php',
    'guest/guest_post.php',
    'functions/automation.php',
    'cron/nightly_tasks.php',
], true);
foreach ($runtime_roots as $runtime_root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/' . $runtime_root,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $contents = file_get_contents($file->getPathname());
        if ($relative !== 'functions/runbooks.php'
            && strpos($contents, 'runbookTicketCanResolve(') !== false
            && !isset($inventory[$relative])) {
            $failures[] = "$relative contains a lifecycle gate missing from the Change Passport inventory";
        }
        if (preg_match('/UPDATE\s+tickets\s+SET\s+(?:\$status_set|ticket_status\s*=\s*[45])/i', $contents)
            && !isset($terminal_writer_files[$relative])) {
            $failures[] = "$relative contains a terminal ticket writer missing from the Change Passport inventory";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Documentation terminal/Change Passport contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation terminal/Change Passport contract test passed\n";
