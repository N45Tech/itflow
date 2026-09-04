<?php

$root = dirname(__DIR__);
$ticket = file_get_contents($root . '/agent/ticket.php');
$ticket_css = file_get_contents($root . '/agent/css/ticket.css');
$ticket_workspace = file_get_contents($root . '/agent/js/ticket_workspace.js');
$service_review = file_get_contents($root . '/agent/service_review.php');
$documentation = file_get_contents($root . '/agent/documentation.php');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertOrder = static function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $first_position = strpos($haystack, $first);
    $second_position = strpos($haystack, $second);
    if ($first_position === false || $second_position === false || $first_position >= $second_position) {
        $failures[] = $message;
    }
};

$assertContains('aria-label="Ticket lifecycle"', $ticket, 'Ticket detail does not expose a concise lifecycle');
$assertContains('Next action', $ticket, 'Ticket detail does not identify the next action');
$assertOrder('id="ticket-update"', 'id="ticket-request"', $ticket, 'The update action is not ahead of the original request');
$assertContains('$ticket_reply_render_index >= 4', $ticket, 'Long ticket conversations are not bounded');
$assertContains('id="ticketActivityToggle"', $ticket, 'Earlier ticket activity cannot be expanded');
$assertContains('grid-auto-flow: column', $ticket_css, 'Ticket context does not become a horizontal rail on smaller screens');
$assertContains("update.hidden = !expanded", $ticket_workspace, 'The activity disclosure does not reveal earlier updates');
$assertContains('Client review checklist', $service_review, 'Service reviews do not use the simplified checklist');
$assertContains('Technical record', $service_review, 'Service-review evidence is not retained behind a disclosure');
$assertContains('Documents are supporting material, not a separate daily queue.', $documentation, 'Documentation is still framed as a daily audit queue');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket lifecycle and client-review UX checks passed" . PHP_EOL;
