<?php

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
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
$section = static function (string $contents, string $start, string $end) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate section beginning $start";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$agent_ticket = $read('agent/ticket.php');
$ticket_post = $read('agent/post/ticket.php');
$approval_post = $read('agent/post/approval.php');
$task_post = $read('agent/post/task.php');
$client_post = $read('client/post.php');
$guest_post = $read('guest/guest_post.php');
$client_ticket = $read('client/ticket.php');
$request_modal = $read('agent/modals/ticket/ticket_approval_request.php');
$manage_modal = $read('agent/modals/ticket/ticket_approval_manage.php');
$status_modal = $read('agent/modals/ticket/ticket_status.php');
$task_edit_modal = $read('agent/modals/ticket/ticket_task_edit.php');
$ticket_css = $read('agent/css/ticket.css');
$runbooks = $read('functions/runbooks.php');
$approval_helpers = $read('functions/ticket_approvals.php');
$migration = $read('n45/migrations/n45-0019-ticket-approval-gates.php');
$manifest = $read('n45/manifest.php');
$schema = $read('db.sql');

// Status is an inline-editable ticket property and resolving still follows the
// canonical terminal path rather than a second, weaker status update.
$assertContains('data-modal-url="modals/ticket/ticket_status.php?id=<?= $ticket_id ?>"', $agent_ticket,
    'The ticket status badge is not wired to its editor');
$assertContains('name="edit_ticket_status"', $status_modal,
    'The status modal does not submit the protected status mutation');
$status_handler = $section($ticket_post, "if (isset(\$_POST['edit_ticket_status']))", "if (isset(\$_POST['edit_ticket_sla']))");
$assertContains('validateCSRFToken();', $status_handler, 'Status edits lack CSRF protection');
$assertContains('mysqli_begin_transaction($mysqli)', $status_handler, 'Status edits are not transactional');
$assertContains('runbookLockTicketForReopen($ticket_id)', $status_handler, 'Resolved tickets are not safely reopened by the status editor');
$assertContains('redirect("post.php?resolve_ticket=$ticket_id&csrf_token="', $status_handler,
    'The status editor bypasses the canonical resolution workflow');

// Ticket and task requests share one plain-language modal.
$assertContains('Who should approve?', $request_modal, 'The approval request does not plainly ask for the approver');
$assertContains('Any portal manager except the ticket contact', $request_modal,
    'The employee-to-manager ticket route is not explained');
$assertContains("name=\"<?= \$target === 'task' ? 'add_ticket_task_approver' : 'add_ticket_approval' ?>\"", $request_modal,
    'The shared request modal does not preserve both ticket and task targets');
$assertNotContains('Approval scope', $request_modal . $manage_modal . $task_edit_modal,
    'The ambiguous Approval scope wording remains in the ticket modals');
$assertContains('Currently sent to', $manage_modal, 'Approval management does not summarize the current recipient');
$assertContains('Why are you sending it again?', $manage_modal, 'Approval re-requests do not collect one clear audit reason');
$assertContains("enforceUserPermission('module_support', 3)", $manage_modal,
    'Approval rerouting is not restricted to administrators');

// Task actions belong to the task overflow menu, and Popper escapes the task
// rail's former clipping layer.
$task_menu = $agent_ticket;
$assertContains('Manage task', $task_menu, 'Manage task is not in the task overflow menu');
$assertContains('Manage approval', $task_menu, 'Approval management is not in the task overflow menu');
$assertContains('Request approval', $task_menu, 'Approval assignment is not in the task overflow menu');
$assertNotContains('ticket_task_approver_add.php', $agent_ticket, 'The retired separate task approval button remains on the ticket');
$assertContains('data-bs-boundary="viewport"', $task_menu, 'The task menu is not constrained to the viewport');
$assertContains('data-bs-popper-config=\'{"strategy":"fixed"}\'', $task_menu,
    'The task menu does not use a clipping-safe Popper strategy');
$assertContains('.ticket-task-workspace .dropdown-menu', $ticket_css, 'Task menus have no explicit foreground layer');
$assertContains('overflow: visible;', $ticket_css, 'Task cards still clip their overflow menus');

// Whole-ticket approvals are independent of tasks and are enforced by every
// existing terminal transition through the canonical lifecycle gate.
$create_ticket_approval = $section($approval_post, "if (isset(\$_POST['add_ticket_approval']))", "if (isset(\$_POST['decide_ticket_approval']))");
$assertNotContains('FROM tasks', $create_ticket_approval, 'Whole-ticket approval creation depends on a synthetic task');
$assertContains('INSERT INTO ticket_approvals', $create_ticket_approval, 'Whole-ticket approval creation is missing');
$assertContains('ticketApprovalRecordEvent(', $create_ticket_approval, 'Ticket approval creation omits append-only history');
$assertContains('ticketApprovalQueueNotification(', $create_ticket_approval, 'Ticket approval creation omits delivery');
$assertContains('FROM ticket_approvals WHERE ticket_approval_ticket_id = $ticket_id', $runbooks,
    'Ticket approvals do not gate resolution');
$assertContains('ticket_approval_request.php?ticket_id=<?= $ticket_id ?>', $agent_ticket,
    'Taskless tickets have no whole-ticket approval action');
$assertContains('Entire ticket', $client_ticket, 'Portal approvers cannot distinguish a whole-ticket decision');

foreach ([
    'agent' => $approval_post,
    'client' => $client_post,
    'guest' => $guest_post,
] as $surface => $contents) {
    $assertContains('ticketApprovalRecordEvent(', $contents,
        ucfirst($surface) . ' ticket approval decisions omit append-only history');
    $assertContains('ticket_approval_status =', $contents,
        ucfirst($surface) . ' ticket approval decisions do not update the projection');
}
$assertContains('validateCSRFToken();', $approval_post, 'Agent ticket approval mutations lack CSRF protection');
$assertContains('validateCSRFToken();', $client_post, 'Portal ticket approval decisions lack CSRF protection');
$assertContains('runbookApprovalTokenMatches(', $guest_post, 'Guest ticket approvals do not verify their bearer credential');
$assertContains("'manager'", $task_post, 'Manual task approvals do not accept portal-manager routing');

// Schema changes remain in the fork namespace and retain approval evidence.
$assertContains('CREATE TABLE IF NOT EXISTS `ticket_approvals`', $migration, 'The N45 ticket approval projection migration is missing');
$assertContains('CREATE TABLE IF NOT EXISTS `ticket_approval_events`', $migration, 'The N45 ticket approval event migration is missing');
$assertContains("'n45-0019-ticket-approval-gates'", $manifest, 'The ticket approval migration is absent from the N45 manifest');
$assertContains('CREATE TABLE `ticket_approvals`', $schema, 'Fresh installs omit whole-ticket approvals');
$assertContains('function ticketApprovalTicketHasAuditHistory(', $approval_helpers,
    'Ticket deletion does not have an approval-retention guard');
$assertContains('ticketApprovalTicketHasAuditHistory($ticket_id)', $ticket_post,
    'Ticket hard deletion can orphan approval evidence');

require_once $root . '/functions/ticket_approvals.php';
$assertTrue(approvalRouteParts('client:manager') === ['client', 'manager'],
    'Portal-manager routes do not parse');
$assertTrue(approvalRouteParts('internal:manager') === ['', ''],
    'Portal-manager routing can be assigned to an internal scope');
$assertTrue(approvalRouteLabel('client', 'manager') === 'Any portal manager except the ticket contact',
    'Portal-manager routing does not have the intended plain-language label');
$assertTrue(ticketApprovalContactCanDecide('manager', 10, 20, true, false, false) === true,
    'A different portal manager cannot decide the ticket approval');
$assertTrue(ticketApprovalContactCanDecide('manager', 10, 10, true, false, false) === false,
    'The ticket contact can approve their own manager-routed request');
$assertTrue(ticketApprovalContactCanDecide('any', 10, 20, true, false, false) === false,
    'A broad portal contact can take over a ticket-contact approval');
$assertTrue(ticketApprovalUserCanDecide([
    'ticket_approval_scope' => 'internal',
    'ticket_approval_status' => 'pending',
    'ticket_approval_created_by' => 7,
    'ticket_approval_type' => 'any',
    'ticket_approval_required_user_id' => 0,
], 7) === false, 'An internal approval requester can approve their own request');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket approval and status UX contracts passed" . PHP_EOL;
