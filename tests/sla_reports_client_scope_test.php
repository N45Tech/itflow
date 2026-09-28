<?php

// SLA reporting must honor the same per-client allow/deny boundary as ticket lists.
$by_client = file_get_contents(__DIR__ . '/../agent/reports/sla_by_client.php');
$summary = file_get_contents(__DIR__ . '/../agent/reports/sla_summary.php');

$assertContains = function ($needle, $haystack, $message) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "$message\n");
        exit(1);
    }
};

$assertContains("clientScopeSql('ticket_client_id')", $by_client, 'SLA by-client ticket queries are not client scoped');
$assertContains("clientScopeSql('client_id')", $by_client, 'SLA by-client client list is not client scoped');
$assertContains('$ticket_client_scope', $by_client, 'SLA by-client queries do not apply the client scope');

$assertContains("clientScopeSql('ticket_client_id')", $summary, 'SLA summary ticket queries are not client scoped');
$assertContains('WHERE ticket_sla_id > 0 $where $ticket_client_scope', $summary, 'SLA summary aggregates do not apply the client scope');

echo "SLA report client-scope checks passed.\n";
