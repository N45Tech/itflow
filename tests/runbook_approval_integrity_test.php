<?php

$failures = [];
$root = dirname(__DIR__);

$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$assertContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};
$assertNotContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};
$assertOrdered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $position + 1);
        if ($position === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
    }
};
$section = static function (string $contents, string $start, string $end) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false) {
        $failures[] = "Could not isolate section beginning $start";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$runbooks = $read('functions/runbooks.php');
$tasks = $read('agent/post/task.php');
$tickets = $read('agent/post/ticket.php');
$client = $read('client/post.php');
$guest = $read('guest/guest_post.php');
$migration = $read('n45/migrations/n45-0010-versioned-runbooks.php');
$schema = $read('db.sql');

$event_schema = $section($schema, 'CREATE TABLE `task_approval_events`', 'Table structure for table `task_dependencies`');
$assertContains('task_approval_event_action', $event_schema, 'Approval event schema has no structured action');
$assertContains('task_approval_event_from_status', $event_schema, 'Approval event schema has no before state');
$assertContains('task_approval_event_to_status', $event_schema, 'Approval event schema has no after state');
$assertContains('task_approval_event_actor_type', $event_schema, 'Approval event schema has no actor attribution');
$assertNotContains('url_key', $event_schema, 'Approval bearer credentials entered the append-only event schema');
$assertNotContains('token', $event_schema, 'Approval bearer tokens entered the append-only event schema');
$assertContains("SELECT approval_id, approval_task_id, 'baseline'", $migration, 'Migration does not backfill baseline approval events');

$event_helper = $section($runbooks, 'function runbookRecordApprovalEvent(', 'function runbookApprovalTokenHash(');
$assertContains("'re_requested'", $event_helper, 'Approval event helper rejects re-request events');
$assertContains("'rerouted'", $event_helper, 'Approval event helper rejects reroute events');
$assertContains("'waived'", $event_helper, 'Approval event helper rejects skip waivers');
$assertNotContains('approval_url_key', $event_helper, 'Approval event recorder accepts bearer credentials');

$publish = $section($runbooks, 'function publishRunbookVersion(', 'function restoreRunbookVersionToDraft(');
if (substr_count($publish, 'runbookAssertVersionDefinitionHash($version_id, $definition_hash);') < 2) {
    $failures[] = 'Publishing does not validate both reused and newly reconstructed version graphs before pointer updates';
}
$assertOrdered(
    $publish,
    ['if ($existing)', 'runbookAssertVersionDefinitionHash($version_id, $definition_hash);', 'ticket_template_published_version_id = $version_id'],
    'Existing version reuse updates a pointer before validating its reconstructed graph'
);

if (substr_count($tasks, 'runbookRequireLockedTicketClient($locked_ticket, $client_id);') < 17) {
    $failures[] = 'Not every agent task/evidence/approval/bulk mutation revalidates the client from its locked ticket';
}
$assertContains('The requester cannot be the specific internal approver.', $runbooks, 'Specific internal routes permit requester self-approval');
$assertContains("if (intval(\$approval['approval_created_by']) === \$session_user_id)", $tasks, 'Internal decisions permit requester self-approval');

$resume = $section($tasks, "if (isset(\$_POST['resume_task']))", "if (isset(\$_POST['skip_runbook_task']))");
$assertOrdered(
    $resume,
    ['runbookLockOpenTicketForTask($task_id)', 'runbookRequireLockedTicketClient(', 'FOR UPDATE', 'runbookEvaluateCondition(', 'runbookApprovalRouteAvailability(', "UPDATE tasks SET task_state = 'Ready'"],
    'Resume does not reload and revalidate its condition, owner and approval routes after locking'
);
$skip = $section($tasks, "if (isset(\$_POST['skip_runbook_task']))", "if (isset(\$_POST['add_task_evidence']))");
$assertContains("approval_url_key = ''", $skip, 'Skipping a task leaves live approval bearers');
$assertContains("'waived'", $skip, 'Skipping a gated task does not append waiver events');

foreach (['created', 'approved', 'declined', 're_requested', 'rerouted', 'waived'] as $action) {
    $combined = $tasks . $client . $guest . $runbooks;
    $assertContains("'$action'", $combined, "Approval history does not wire the $action transition");
}
$assertContains("'contact'", $client, 'Portal approval decisions lack contact event attribution');
$assertContains("'guest'", $guest, 'Guest approval decisions lack guest event attribution');

$transfer = $section($tickets, "if (isset(\$_POST['change_client_ticket']))", "if (isset(\$_GET['resolve_ticket']))");
$assertOrdered(
    $transfer,
    ['mysqli_begin_transaction($mysqli)', 'FOR UPDATE', 'has_execution', 'has_approval', 'has_evidence', 'UPDATE tickets SET ticket_client_id'],
    'Ticket transfer does not check all client-bound workflow artifacts after locking'
);
$assertContains('AND ticket_client_id = $source_client_id', $transfer, 'Ticket transfer lacks a source-client compare-and-swap');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook approval integrity checks passed" . PHP_EOL;
