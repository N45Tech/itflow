<?php

/* Source contract for disabled client teardown and immutable audit retention. */

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
    'validateCSRFToken()',
    'enforceAdminPermission()',
    '$client_id = intval',
    'enforceClientAccess($client_id)',
    "logAudit('Retention', 'Blocked Delete'",
    'Permanent client deletion is disabled by retention policy',
    'redirect("client_overview.php?client_id=$client_id")',
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

foreach (['DELETE FROM ', 'removeDirectory(', 'mysqli_begin_transaction('] as $destructive_primitive) {
    if (strpos($delete, $destructive_primitive) !== false) {
        $failures[] = "Disabled client teardown still contains $destructive_primitive";
    }
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
