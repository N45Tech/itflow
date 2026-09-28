<?php

$modal = file_get_contents(dirname(__DIR__) . '/agent/modals/ticket/ticket_bulk_merge.php');
$failures = [];

if (!str_contains($modal, "enforceUserPermission('module_support', 2)")) {
    $failures[] = 'The bulk merge modal does not enforce support write access';
}

if (!str_contains($modal, "clientScopeSql('ticket_client_id')")) {
    $failures[] = 'The bulk merge target query is not client-scoped';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket bulk merge security contract passed.\n";
