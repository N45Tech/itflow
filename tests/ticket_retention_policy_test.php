<?php

$failures = [];
$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $contents = @file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};
$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertOrdered = static function (string $haystack, array $needles, string $message) use (&$failures): void {
    $offset = 0;
    foreach ($needles as $needle) {
        $position = strpos($haystack, $needle, $offset);
        if ($position === false) {
            $failures[] = $message . " (missing or out of order: $needle)";
            return;
        }
        $offset = $position + strlen($needle);
    }
};

$retention = $read('functions/ticket_retention.php');
$ticket_post = $read('agent/post/ticket.php');
$ticket_page = $read('agent/ticket.php');
$ticket_list = $read('agent/ticket_list.php');
$delete_modal = $read('agent/modals/ticket/ticket_delete.php');
$bulk_delete_modal = $read('agent/modals/ticket/ticket_bulk_delete.php');
$client_modal = $read('agent/modals/client/client_edit.php');
$client_post = $read('agent/post/client.php');
$automation = $read('functions/automation.php');
$automation_events = $read('functions/automation_events.php');
$agent_tickets = $read('agent/tickets.php');
$agent_dashboard = $read('agent/dashboard.php');
$agent_operations = $read('agent/operations.php');
$migration = $read('n45/migrations/n45-0021-client-ticket-retention.php');
$manifest = $read('n45/manifest.php');
$schema = $read('db.sql');

require_once $root . '/functions/ticket_retention.php';
$assertTrue(ticketDeletionPolicies() === ['override', 'strict'], 'Client retention policies changed unexpectedly');
$assertTrue(ticketDeletionNormalizePolicy('override') === 'override', 'Override policy does not normalize');
$assertTrue(ticketDeletionNormalizePolicy('unknown') === 'strict', 'Unknown retention policies do not fail closed');
$assertTrue(ticketDeletionPolicyLabel('override') === 'Retain with administrator override', 'Override policy label is unclear');
try {
    ticketDeletionOverrideReason('short');
    $failures[] = 'A meaningless retention override reason is accepted';
} catch (DomainException $exception) {
    // Expected.
}
$assertTrue(ticketDeletionOverrideReason('Duplicate alert created during connector repair.') === 'Duplicate alert created during connector repair.', 'A valid retention override reason is rejected');

$assertContains('client_ticket_retention_policy', $migration, 'The client retention migration is missing its policy column');
$assertContains("DEFAULT 'override'", $migration, 'Existing clients do not receive retain-with-override behavior');
$assertContains("automation_incident_last_action = 'ticket_closed_reconciled'", $migration,
    'Existing closed tickets do not reconcile their linked Operations incidents');
$assertContains('`client_ticket_retention_policy` varchar(20) NOT NULL DEFAULT \'override\'', $schema, 'Fresh installs omit client ticket retention policy');
$assertContains("'n45-0021-client-ticket-retention'", $manifest, 'The client retention migration is absent from the manifest');
$assertContains("'data_change' => true", $manifest, 'The incident reconciliation is not classified as a data change');

$assertContains('Ticket audit retention', $client_modal, 'Client settings do not expose ticket retention');
$assertContains('Strict retention — no deletion override', $client_modal, 'Client settings do not offer strict retention');
$assertContains("lookupUserPermission('module_client') >= 3", $client_modal, 'Non-admin client editors can change retention policy');
$assertContains("in_array(\$ticket_retention_policy, ticketDeletionPolicies(), true)", $client_post, 'Client retention input is not allowlisted server-side');
$assertContains('enforceClientAccess($client_id)', $client_post, 'Client retention can be changed outside the user client scope');
$assertContains("logAudit('Client', 'Ticket Retention Policy'", $client_post, 'Client retention policy changes are not audited');

$assertContains('name="deletion_override_reason"', $delete_modal, 'Single-ticket override does not collect a reason');
$assertContains('name="confirm_ticket_deletion"', $delete_modal, 'Single-ticket permanent deletion lacks explicit acknowledgment');
$assertContains('Delete ticket and evidence', $delete_modal, 'The destructive override action is ambiguously labelled');
$assertContains('Retained by strict client policy', $bulk_delete_modal, 'Bulk deletion does not explain strict-policy retention');
$assertContains('name="override_retention"', $bulk_delete_modal, 'Bulk deletion cannot request an eligible override');
$assertContains('name="deletion_override_reason"', $bulk_delete_modal, 'Bulk override does not collect a surviving audit reason');
$assertContains('ticket_bulk_delete.php', $ticket_list, 'Bulk ticket deletion bypasses the retention preview');
$assertContains('ticket_delete.php', $ticket_page, 'Single-ticket deletion bypasses the retention preview');
$assertNotContains("post.php?delete_ticket=", $ticket_page, 'Ticket deletion still mutates through a GET link');

$assertOrdered($ticket_post, [
    "if (isset(\$_POST['delete_ticket']))",
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClientTicket($ticket_id, $client_id, true)',
    'ticketDeletionEvidenceSummary($ticket_id, $client_id)',
    'ticketDeletionPolicyForClient($client_id)',
    "logAudit('Ticket', \$audit_action",
    'ticketDeletionPurge($ticket_id)',
    'mysqli_commit($mysqli)',
    'removeDirectory("../uploads/tickets/$ticket_id")',
], 'Single-ticket override is not locked, audited, purged, and committed in the required order');
$assertContains("if (isset(\$_POST['bulk_delete_tickets']))", $ticket_post, 'Bulk deletion handler is missing');
$assertContains("\$retained_count++", $ticket_post, 'Bulk deletion does not retain strict or unconfirmed evidence tickets individually');
$assertContains("\$failed_count++", $ticket_post, 'Bulk deletion does not isolate runtime failures');

$assertOrdered($retention, [
    'function ticketDeletionPurge(',
    'UPDATE portal_request_submissions SET portal_request_submission_ticket_id = NULL',
    'automationDeleteTicketOperations($ticket_id)',
    'documentation_obligation_verification_ticket_id = $ticket_id',
    "'verification_invalidated'",
    'DELETE FROM ticket_approval_events',
    'DELETE task_approval_events',
    'DELETE ticket_documentation_waiver_events',
    'DELETE documentation_change_passport_obligations',
    'DELETE FROM ticket_agreement_decisions',
    'DELETE FROM runbook_executions',
    'DELETE FROM tasks',
    'DELETE FROM ticket_history',
    'DELETE FROM tickets WHERE ticket_id = $ticket_id LIMIT 1',
], 'A retention override can leave orphaned ticket-owned evidence');

$assertContains('function automationResolveTicketIncidents(', $automation, 'Closed tickets cannot reconcile their linked Operations incident');
foreach ([
    'agent/post/ticket.php' => $ticket_post,
    'client/post.php' => $read('client/post.php'),
    'guest/guest_post.php' => $read('guest/guest_post.php'),
    'api/v1/tickets/close.php' => $read('api/v1/tickets/close.php'),
    'cron/nightly_tasks.php' => $read('cron/nightly_tasks.php'),
] as $surface => $source) {
    $assertContains('automationResolveTicketIncidents($ticket_id', $source, "$surface leaves a linked incident open after ticket closure");
}
$assertContains('AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL', $agent_tickets,
    'The Open ticket list includes terminal tickets');
$assertContains('(ticket_resolved_at IS NOT NULL OR ticket_closed_at IS NOT NULL)', $agent_tickets,
    'The Closed ticket list omits cancelled or otherwise terminal tickets');
$assertContains('AND ticket_closed_at IS NULL', $agent_dashboard,
    'Dashboard ticket counts include terminal tickets');
$assertContains('AND ticket_closed_at IS NULL', $agent_operations,
    'Operations ticket counts include terminal tickets');
$assertContains('AND ticket_closed_at IS NULL', $automation_events,
    'A new integration event can reuse a closed ticket');
$assertContains('Created <?= $ticket_created_at_ago ?>', $ticket_page,
    'Ticket creation metadata still labels a terminal ticket as Opened');

if ($failures) {
    fwrite(STDERR, "Ticket retention policy test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ticket retention policy test passed\n";
