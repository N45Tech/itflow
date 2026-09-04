<?php

$root = dirname(__DIR__);
$ticket = file_get_contents($root . '/agent/ticket.php');
$ticketPost = file_get_contents($root . '/agent/post/ticket.php');
$taskPost = file_get_contents($root . '/agent/post/task.php');
$approvalModal = file_get_contents($root . '/agent/modals/ticket/ticket_task_approver_add.php');
$terminalModal = file_get_contents($root . '/agent/modals/ticket/ticket_terminal.php');
$ticketCss = file_get_contents($root . '/agent/css/ticket.css');
$ticketJs = file_get_contents($root . '/agent/js/ticket_workspace.js');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains('class="card card-dark mb-3" id="ticket-request"', $ticket, 'Original request is not expanded by default');
$assertNotContains('collapsed-card" id="ticket-request"', $ticket, 'Original request still starts collapsed');
$assertContains('aria-expanded="true" aria-label="Collapse original request"', $ticket, 'Original request disclosure state is inaccessible');
$assertContains('aria-expanded="false" aria-label="Show history"', $ticket, 'History does not advertise its collapsed state');
$assertContains('.ticket-disclosure-toggle[aria-expanded="true"] i', $ticketCss, 'Disclosure arrows do not track expanded state');
$assertContains('syncDisclosure(toggle)', $ticketJs, 'Disclosure state is not synchronized after toggling');

$assertContains('ticket_terminal.php?ticket_id=<?= $ticket_id ?>&action=close', $ticket, 'Technicians have no direct close action');
$assertContains('ticket_terminal.php?ticket_id=<?= $ticket_id ?>&action=cancel', $ticket, 'Technicians have no cancel action');
$assertContains("in_array(\$terminal_action, ['close', 'cancel'], true)", $ticketPost, 'Terminal actions are not allowlisted server-side');
$assertContains('runbookTicketCanResolve($ticket_id)', $ticketPost, 'Direct close does not enforce task and approval gates');
$assertContains("\$terminal_reason === ''", $ticketPost, 'Terminal actions do not require an audit reason');
$assertContains('name="terminal_reason"', $terminalModal, 'Close and cancel modal does not collect an audit reason');

$assertContains('title="Add approval"', $ticket, 'Post-creation approval control is not visible on unfinished tasks');
$assertNotContains("intval(\$row['task_runbook_version_task_id']) > 0", $approvalModal, 'Published task modal still rejects additive approval gates');
$assertNotContains('Published runbook approval gates come only from the pinned version', $taskPost, 'Approval handler still rejects additive gates on published tasks');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Ticket lifecycle control tests passed.\n";
