<?php

/* Every terminal documentation graph starts from client -> ticket locks. */

$root = dirname(__DIR__);
$failures = [];
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
$assertTerminalOrder = static function ($contents, $label) use (&$failures) {
    $begin = strpos($contents, 'mysqli_begin_transaction($mysqli)');
    $client_ticket = $begin === false ? false : strpos($contents, 'documentationLockClientTicket(', $begin);
    $runbook_lock = $client_ticket === false ? false : strpos($contents, 'runbookLock', $client_ticket);
    $gate = $runbook_lock === false ? false : strpos($contents, 'runbookTicketCanResolve(', $runbook_lock);
    $passport = $gate === false ? false : strpos($contents, 'documentationRecordChangePassport(', $gate);
    $commit = $passport === false ? false : strpos($contents, 'mysqli_commit($mysqli)', $passport);
    if ($begin === false || $client_ticket === false || $runbook_lock === false
        || $gate === false || $passport === false || $commit === false
        || !($begin < $client_ticket && $client_ticket < $runbook_lock
            && $runbook_lock < $gate && $gate < $passport && $passport < $commit)) {
        $failures[] = "$label does not establish client -> ticket before its documentation graph";
    }
};

$ticket_post = $read('agent/post/ticket.php');
foreach ([
    ["if (isset(\$_POST['bulk_merge_tickets']))", "if (isset(\$_POST['bulk_resolve_tickets']))", 'Bulk merge'],
    ["if (isset(\$_POST['bulk_resolve_tickets']))", "if (isset(\$_POST['bulk_ticket_reply']))", 'Bulk resolve'],
    ["if (isset(\$_POST['bulk_ticket_reply']))", "if (isset(\$_POST['bulk_add_ticket_project']))", 'Bulk reply'],
    ["if (isset(\$_POST['add_ticket_reply']))", "if (isset(\$_GET['delete_ticket_attachment']))", 'Agent reply'],
    ["if (isset(\$_POST['merge_ticket']))", "if (isset(\$_POST['change_client_ticket']))", 'Agent merge'],
    ["if (isset(\$_GET['resolve_ticket']))", "if (isset(\$_GET['close_ticket']))", 'Agent resolve'],
    ["if (isset(\$_GET['close_ticket']))", "if (isset(\$_GET['reopen_ticket']))", 'Agent close'],
] as [$start, $end, $label]) {
    $assertTerminalOrder($section($ticket_post, $start, $end, $label), $label);
}

foreach ([
    ['agent/ajax.php', "if (isset(\$_POST['update_kanban_ticket']))", "if (isset(\$_POST['update_ticket_tasks_order']))", 'Kanban resolve'],
    ['client/post.php', "if (isset(\$_GET['resolve_ticket']))", "if (isset(\$_GET['reopen_ticket']))", 'Portal resolve'],
    ['client/post.php', "if (isset(\$_GET['close_ticket']))", "if (isset(\$_GET['logout']))", 'Portal close'],
    ['guest/guest_post.php', "if (isset(\$_GET['close_ticket'], \$_GET['url_key']))", "if (isset(\$_GET['add_ticket_feedback'], \$_GET['url_key']))", 'Guest close'],
] as [$path, $start, $end, $label]) {
    $assertTerminalOrder($section($read($path), $start, $end, $label), $label);
}
foreach ([
    'api/v1/tickets/resolve.php' => 'API resolve',
    'api/v1/ticket_replies/create.php' => 'API reply',
] as $path => $label) {
    $assertTerminalOrder($read($path), $label);
}
$automation = $read('functions/automation.php');
$automation_at = strpos($automation, 'function automationAddIncidentReply(');
$assertTerminalOrder($automation_at === false ? '' : substr($automation, $automation_at), 'Automation resolve');
$assertTerminalOrder($section(
    $read('cron/nightly_tasks.php'),
    '// TICKET RESOLUTION/CLOSURE PROCESS',
    'if ($config_send_invoice_reminders == 1)',
    'Automatic close'
), 'Automatic close');

$project = $section(
    $read('agent/post/project.php'),
    "if (isset(\$_GET['close_project']))",
    "if (isset(\$_GET['archive_project']))",
    'Project close'
);
$project_client = strpos($project, 'documentationLockClient($client_id)');
$project_ticket = $project_client === false ? false : strpos($project, 'ORDER BY ticket_id ASC FOR UPDATE', $project_client);
$project_passport = $project_ticket === false ? false : strpos($project, 'documentationRecordChangePassport(', $project_ticket);
if ($project_client === false || $project_ticket === false || $project_passport === false
    || !($project_client < $project_ticket && $project_ticket < $project_passport)) {
    $failures[] = 'Project close does not lock its client before child tickets and documentation graphs';
}

$core = $read('functions/documentation.php');
if (strpos($core, 'function documentationLockClientTicket(') !== false
    && preg_match('/function documentationLockClientTicket\s*\(\s*\$ticket_id,\s*\$expected_client_id\s*=\s*0,\s*\$allow_archived_client\s*=\s*false\s*\)/s', $core) !== 1) {
    $failures[] = 'Core client-ticket lock signature drifted from terminal callers';
}

if ($failures) {
    fwrite(STDERR, "Documentation terminal lock-order contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation terminal lock-order contract test passed\n";
