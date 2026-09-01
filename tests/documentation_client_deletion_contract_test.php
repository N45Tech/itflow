<?php

/* Source contract for atomic client deletion and immutable audit retention. */

$root = dirname(__DIR__);
$client_post = file_get_contents($root . '/agent/post/client.php');
$lifecycle = file_get_contents($root . '/functions/documentation_lifecycle.php');
$failures = [];

if ($client_post === false || $lifecycle === false) {
    fwrite(STDERR, "Could not read client deletion sources\n");
    exit(1);
}

$start = strpos($client_post, "if (isset(\$_GET['delete_client']))");
$end = $start === false ? false : strpos($client_post, "if (isExportRequest('export_clients'))", $start);
if ($start === false || $end === false || $end <= $start) {
    $failures[] = 'Could not isolate the client deletion handler';
    $delete = '';
} else {
    $delete = substr($client_post, $start, $end - $start);
}

$ordered_needles = [
    'mysqli_begin_transaction($mysqli)',
    'SELECT client_name FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE',
    'SELECT ticket_id FROM tickets WHERE ticket_client_id = $client_id FOR UPDATE',
    'documentationClientHasAuditRecords($client_id)',
    'DELETE FROM certificates WHERE certificate_client_id = $client_id',
    'automationDeleteTicketOperations($ticket_id)',
    'DELETE FROM clients WHERE client_id = $client_id',
    'mysqli_commit($mysqli)',
    'removeDirectory("../uploads/clients/$client_id")',
];
$last_position = -1;
foreach ($ordered_needles as $needle) {
    $position = strpos($delete, $needle);
    if ($position === false) {
        $failures[] = "Client deletion is missing: $needle";
    } elseif ($position <= $last_position) {
        $failures[] = "Client deletion ordering is unsafe at: $needle";
    } else {
        $last_position = $position;
    }
}

if (strpos($delete, 'mysqli_query(') !== false) {
    $failures[] = 'Client deletion still contains an unchecked database query';
}
if (strpos($delete, 'mysqli_rollback($mysqli)') === false) {
    $failures[] = 'Client deletion cannot roll back a failed association delete';
}

foreach ([
    'client_documentation_obligations',
    'documentation_obligation_events',
    'documentation_obligation_exceptions',
    'documentation_obligation_exception_events',
    'documentation_evidence_locker',
    'documentation_change_passports',
    'documentation_promise_ledger',
    'documentation_promise_events',
    'ticket_documentation_obligations',
] as $audit_table) {
    if (strpos($lifecycle, "'$audit_table'") === false) {
        $failures[] = "Client audit retention omits $audit_table";
    }
}

if ($failures) {
    fwrite(STDERR, "Documentation client-deletion contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation client-deletion contract passed\n";
