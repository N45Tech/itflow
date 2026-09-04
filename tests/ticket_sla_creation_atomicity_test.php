<?php

/*
 * Source contract for every non-agent ticket creation path that must publish
 * its immutable SLA/agreement decision in the same caller-owned transaction.
 * Database release tests exercise the underlying writes; this test protects
 * the lock order, rollback seams, and post-commit side-effect boundaries.
 */

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

$section = static function (
    string $contents,
    string $start,
    string $end,
    string $label
) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$assertContains = static function (
    string $contents,
    string $needle,
    string $message
) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = "$message (missing '$needle')";
    }
};

$assertNotContains = static function (
    string $contents,
    string $needle,
    string $message
) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = "$message (found '$needle')";
    }
};

$assertOrdered = static function (
    string $contents,
    array $needles,
    string $message
) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $position + 1);
        if ($next === false) {
            $failures[] = "$message (missing or out of order '$needle')";
            return;
        }
        $position = $next;
    }
};

$client_portal = $section(
    $read('client/post.php'),
    "if (isset(\$_POST['add_ticket']))",
    "if (isset(\$_POST['add_ticket_comment']))",
    'client portal ticket creation'
);
$api = $read('api/v1/tickets/create.php');
$level = $section(
    $read('functions/level.php'),
    'function levelHandleAlertActive(',
    'function levelHandleAlertResolved(',
    'Level alert ticket creation'
);
$recurring_source = $read('agent/post/recurring_ticket.php');
$recurring_bulk = $section(
    $recurring_source,
    "if (isset(\$_POST['bulk_force_recurring_tickets']))",
    "if (isset(\$_GET['force_recurring_ticket']))",
    'bulk forced recurring ticket creation'
);
$recurring_single = $section(
    $recurring_source,
    "if (isset(\$_GET['force_recurring_ticket']))",
    "if (isset(\$_GET['delete_recurring_ticket']))",
    'single forced recurring ticket creation'
);
$nightly = $section(
    $read('cron/nightly_tasks.php'),
    '// Recurring tickets',
    '// Flag any active recurring "next run" dates that are in the past',
    'nightly recurring ticket creation'
);
$email_parser = $section(
    $read('cron/ticket_email_parser.php'),
    'function addTicket(',
    'function addReply(',
    'parsed-email ticket creation'
);

$paths = [
    [
        'label' => 'Client portal',
        'source' => $client_portal,
        'client_lock' => 'agreementLockClientForAuditRetention($session_client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($ticket_id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => 'addToMailQueue($data);',
        'failure' => "flashAlert('The ticket was not created because its SLA decision could not be recorded safely', 'error')",
    ],
    [
        'label' => 'Ticket API',
        'source' => $api,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($insert_id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => 'logAudit("Ticket", "Create"',
        'failure' => '$insert_id = false;',
    ],
    [
        'label' => 'Level alert',
        'source' => $level,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => '$insert = mysqli_query($mysqli, "INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($ticket_id, null, null, true);',
        'catch' => 'catch (Throwable $e)',
        'side_effect' => 'logTicketHistory($ticket_id',
        'failure' => 'throw $e;',
    ],
    [
        'label' => 'Bulk forced recurring',
        'source' => $recurring_bulk,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => "triggerCustomAction('ticket_create', \$id);",
        'failure' => '$failure_count++;',
    ],
    [
        'label' => 'Single forced recurring',
        'source' => $recurring_single,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => "triggerCustomAction('ticket_create', \$id);",
        'failure' => "flashAlert('The recurring ticket was not forced because its SLA decision could not be recorded safely', 'error')",
    ],
    [
        'label' => 'Nightly recurring',
        'source' => $nightly,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => 'logAudit("Ticket", "Create"',
        'failure' => "logApp('Cron', 'error'",
    ],
    [
        'label' => 'Parsed-email',
        'source' => $email_parser,
        'client_lock' => 'agreementLockClientForAuditRetention($client_id)',
        'insert' => 'ticketCreationDbQuery("INSERT INTO tickets SET',
        'sla' => 'applyTicketSla($id, null, null, true);',
        'catch' => 'catch (Throwable $exception)',
        'side_effect' => 'mkdirMissing(\'../uploads/tickets/\');',
        'failure' => 'throw $exception;',
    ],
];

foreach ($paths as $path) {
    $label = $path['label'];
    $source = $path['source'];
    $assertOrdered($source, [
        'if (!mysqli_begin_transaction($mysqli))',
        $path['client_lock'],
        'UPDATE settings',
        $path['insert'],
        $path['sla'],
        'if (!mysqli_commit($mysqli))',
        $path['catch'],
        'mysqli_rollback($mysqli);',
        $path['failure'],
        $path['side_effect'],
    ], "$label ticket insert, client lock, SLA decision, rollback, and post-commit effects are not safely ordered");
    $assertNotContains(
        $source,
        preg_replace('/, null, null, true/', '', $path['sla']),
        "$label ticket creation still invokes an independently committed SLA transaction"
    );
}

// Clientless parsed emails remain supported while every positive tenant ID is
// locked before the ticket insert and before applyTicketSla locks the ticket.
$assertContains(
    $email_parser,
    'if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id))',
    'Parsed-email ticket creation no longer preserves the clientless guest path'
);

// Recurring entitlement evidence must include the copied, tenant-scoped device
// set inside the same transaction, before the immutable SLA decision is made.
foreach ([$recurring_bulk, $recurring_single, $nightly] as $index => $source) {
    $label = ['Bulk forced recurring', 'Single forced recurring', 'Nightly recurring'][$index];
    $assertOrdered($source, [
        'INSERT INTO tickets SET',
        'INSERT INTO ticket_assets (ticket_id, asset_id)',
        'AND asset_client_id = $client_id AND asset_archived_at IS NULL',
        'applyTicketSla($id, null, null, true);',
        'if (!mysqli_commit($mysqli))',
    ], "$label ticket assets are not complete and tenant-scoped before SLA selection");
}

// The advisory lock is the Level ingress idempotency boundary and must continue
// to cover both the atomic ticket creation and the alert link write.
$assertOrdered($level, [
    "SELECT GET_LOCK('\$lock_name', 10)",
    'if (!levelUpsertAlertLink($alert, $ticket_id, $asset_id, $event_time))',
    'applyTicketSla($ticket_id, null, null, true);',
    'if (!mysqli_commit($mysqli))',
    "SELECT RELEASE_LOCK('\$lock_name')",
], 'Level alert locking no longer prevents retry races around ticket/SLA publication');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket SLA creation atomicity contracts passed.\n";
