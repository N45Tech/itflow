<?php

// Source contracts only: no application bootstrap or database writes occur.
$root = dirname(__DIR__);
$reconcile = file_get_contents($root . '/deploy/psa/reconcile_ticket_operations.php');
$sla = file_get_contents($root . '/functions/sla.php');
$failures = [];

if ($reconcile === false || $sla === false) {
    fwrite(STDERR, "Could not read ticket reconciliation transaction sources\n");
    exit(1);
}

$assertOrdered = static function (string $source, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($source, $needle, $position + 1);
        if ($next === false) {
            $failures[] = "$message (missing or out of order '$needle')";
            return;
        }
        $position = $next;
    }
};

$caller_owned_call = "applyTicketSla(intval(\$ticket['ticket_id']), null, null, true);";
$assertOrdered($reconcile, [
    "require \$app_root . '/includes/db.php';",
    "require \$app_root . '/includes/inc_set_timezone.php';",
    'mysqli_begin_transaction($mysqli)',
    $caller_owned_call,
    'if ($dry_run)',
    'mysqli_rollback($mysqli)',
    'mysqli_commit($mysqli)',
    'catch (Throwable $exception)',
    'mysqli_rollback($mysqli)',
], 'Timezone setup, SLA restamping, dry-run rollback, apply commit, or failure rollback is unsafe');

preg_match_all('/\bapplyTicketSla\s*\(/', $reconcile, $calls);
if (count($calls[0]) !== 1 || substr_count($reconcile, $caller_owned_call) !== 1) {
    $failures[] = 'Every maintenance SLA restamp must use the caller-owned transaction';
}
if (substr_count($reconcile, 'mysqli_begin_transaction($mysqli)') !== 1) {
    $failures[] = 'Reconciliation must have one outer transaction, not nested transaction starts';
}

$assertOrdered($sla, [
    'function applyTicketSla(',
    'bool $caller_transaction = false',
    '$owns_transaction = !$caller_transaction;',
    'if ($owns_transaction && !mysqli_begin_transaction($mysqli))',
    'syncTicketSlaClock($ticket_id, true);',
    'if ($owns_transaction && !mysqli_commit($mysqli))',
    'catch (Throwable $e)',
    'if ($owns_transaction)',
    'mysqli_rollback($mysqli)',
], 'The SLA helper no longer preserves caller transaction ownership through nested clock updates');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket operations dry-run atomicity contracts passed.\n";
