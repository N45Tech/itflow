<?php

/* The time-detail report includes complete ticket reply bodies, so its query
 * must apply the signed-in user's client allow and deny lists server-side. */

$root = dirname(__DIR__);
$report = file_get_contents($root . '/agent/reports/client_ticket_time_detail.php');

$failures = [];

if (!str_contains($report, "clientScopeSql('t.ticket_client_id')")) {
    $failures[] = 'The client ticket time-detail query is not client-scoped';
}

if (!str_contains($report, "enforceUserPermission('module_sales')")) {
    $failures[] = 'The client ticket time-detail endpoint does not enforce sales access';
}

if (!str_contains($report, "enforceUserPermission('module_support')")) {
    $failures[] = 'The client ticket time-detail endpoint does not enforce support access';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Client ticket time-detail security contract passed.\n";
