<?php

$failures = [];
$root = dirname(__DIR__);

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

$assertContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$assertOrdered = function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $position + 1);
        if ($next === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
        $position = $next;
    }
};

$api_reply = $read('api/v1/ticket_replies/create.php');
$assertOrdered(
    $api_reply,
    [
        "array_key_exists('ticket_status', \$_POST)",
        'FILTER_VALIDATE_INT',
        'mysqli_begin_transaction($mysqli)',
        '$locked_ticket = $requested_nonterminal_status',
        'if (!$reply_ticket_status_input_valid)',
        'FROM ticket_statuses WHERE ticket_status_id = $reply_ticket_status',
        'AND ticket_status_active = 1 LIMIT 1 FOR UPDATE',
        'UPDATE tickets SET $status_set',
        'mysqli_commit($mysqli)',
    ],
    'Ticket reply API does not validate and lock an active status before its conditional status write'
);
$assertContains("['options' => ['min_range' => 0]]", $api_reply, 'Ticket reply API accepts negative or non-integer status input');
$assertContains('$reply_ticket_status_supplied && $reply_ticket_status !== 0', $api_reply, 'Ticket reply API does not reserve status 0 for no change');
$assertContains("throw new RuntimeException('The requested ticket status is unavailable or inactive')", $api_reply, 'Ticket reply API does not fail closed for an unknown or inactive status');
$assertOrdered(
    $api_reply,
    ['catch (Throwable $e)', 'mysqli_rollback($mysqli)', '$insert_sql = false;', '$insert_id = false;'],
    'Ticket reply API can report success after status validation fails'
);

$ticket_post = $read('agent/post/ticket.php');
$single_assignment = $section(
    $ticket_post,
    "if (isset(\$_POST['assign_ticket']))",
    "if (isset(\$_GET['delete_ticket']))",
    'single ticket assignment handler'
);
$bulk_assignment = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_assign_ticket']))",
    "if (isset(\$_POST['bulk_edit_ticket_priority']))",
    'bulk ticket assignment handler'
);

foreach ([$single_assignment, $bulk_assignment] as $index => $assignment) {
    $label = $index === 0 ? 'Single ticket assignment' : 'Bulk ticket assignment';
    $assertNotContains("\$_POST['ticket_status']", $assignment, "$label still consumes browser-controlled lifecycle state");
    $assertOrdered(
        $assignment,
        [
            'mysqli_begin_transaction($mysqli)',
            'runbookLockTicketForTransition($ticket_id, true)',
            "\$locked_status = intval(\$locked_ticket['ticket_status'])",
            'UPDATE tickets SET ticket_assigned_to =',
            'AND ticket_status = $locked_status AND ticket_closed_at IS NULL',
            'mysqli_affected_rows($mysqli)',
            'INSERT INTO ticket_replies SET',
            'mysqli_commit($mysqli)',
        ],
        "$label does not derive and CAS state under the ticket lock"
    );
    $assertContains('mysqli_rollback($mysqli)', $assignment, "$label cannot roll back a failed assignment");
}

$runbooks = $read('functions/runbooks.php');
$assertContains('ticket_contact_id, ticket_project_id, ticket_assigned_to, ticket_status', $runbooks, 'The shared ticket lock does not return the assignment value used by CAS');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket state input contracts passed.\n";
