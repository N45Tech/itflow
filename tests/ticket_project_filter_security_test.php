<?php

/* The ticket project's filter exposes project metadata, so its query must
 * apply the signed-in user's client allow and deny lists server-side. */

$root = dirname(__DIR__);
$tickets = file_get_contents($root . '/agent/tickets.php');

if (!str_contains($tickets, "clientScopeSql('project_client_id')")) {
    fwrite(STDERR, "The ticket project filter is not client-scoped.\n");
    exit(1);
}

echo "Ticket project filter security contract passed.\n";
