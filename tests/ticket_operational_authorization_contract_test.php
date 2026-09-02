<?php

$root = dirname(__DIR__);
$operations = file_get_contents($root . '/functions/ticket_operations.php');
$ticket_post = file_get_contents($root . '/agent/post/ticket.php');
$failures = [];

$section = static function (string $source, string $start, ?string $end, string $label) use (&$failures): string {
    $start_at = strpos($source, $start);
    $end_at = $start_at === false || $end === null ? false : strpos($source, $end, $start_at + strlen($start));
    if ($start_at === false || ($end !== null && $end_at === false)) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return $end_at === false ? substr($source, $start_at) : substr($source, $start_at, $end_at - $start_at);
};
$assertOrdered = static function (string $source, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle, $position + 1);
        if ($position === false) {
            $failures[] = "$message (missing or out of order '$needle')";
            return;
        }
    }
};
$assertContains = static function (string $needle, string $source, string $message) use (&$failures): void {
    if (!str_contains($source, $needle)) {
        $failures[] = "$message (missing '$needle')";
    }
};

$assertContains("require_once __DIR__ . '/authorization_transactions.php'", $operations,
    'Ticket operations do not load the shared transaction authorization primitive');
$actor_lock = $section($operations, 'function ticketOperationalLockMutationActor(',
    "/**\n * Goal 10 adds ticket_deleted_at", 'ticket mutation actor lock');
$assertOrdered($actor_lock, [
    "\$actor_type !== 'agent'",
    'authorizationLockSupportAgentsAndPortalUsers(',
    "'minimum_level' => 2",
    "'client_ids' => \$client_ids",
], 'Ticket mutation actor lock does not enforce active scoped support authorization');

foreach ([
    ['function ticketOperationalUpdateTicket(', 'function ticketOperationalBatchUpdatePriority(',
        'ticket operational update', 'ticketOperationalLockMutationActor($actor_id, $actor_type, [$client_id])',
        'documentationLockClient($client_id)'],
    ['function ticketOperationalAddRelationship(', 'function ticketOperationalRemoveRelationship(',
        'relationship creation', "ticketOperationalLockMutationActor(\$actor_id, 'agent', [\$advisory_client_id])",
        'documentationLockClient($advisory_client_id)'],
    ['function ticketOperationalRemoveRelationship(', 'function ticketOperationalTicketHasRelationships(',
        'relationship removal', "ticketOperationalLockMutationActor(\$actor_id, 'agent', [\$advisory_client_id])",
        'documentationLockClient($advisory_client_id)'],
    ['function ticketOperationalCreatePromise(', 'function ticketOperationalFulfillPromisesLocked(',
        'promise creation', 'ticketOperationalLockMutationActor($actor_id, $source_type, [$advisory_client_id])',
        'documentationLockClient($advisory_client_id)'],
    ['function ticketOperationalCancelPromise(', 'function ticketOperationalReconcilePromises(',
        'promise cancellation', "ticketOperationalLockMutationActor(\$actor_id, 'agent', [\$advisory_client_id])",
        'documentationLockClient($advisory_client_id)'],
] as [$start, $end, $label, $actor_call, $client_call]) {
    $mutation = $section($operations, $start, $end, $label);
    $assertOrdered($mutation, [
        '!$caller_transaction && !mysqli_begin_transaction($mysqli)',
        '$transaction_open = !$caller_transaction',
        $actor_call,
        $client_call,
        'FOR UPDATE',
    ], ucfirst($label) . ' does not lock actor, active client, and business rows in order');
    $assertContains('!$caller_transaction && !mysqli_commit($mysqli)', $mutation,
        ucfirst($label) . ' cannot participate safely in a caller-owned transaction');
}

$batch = $section($operations, 'function ticketOperationalBatchUpdatePriority(',
    'function ticketOperationalSetResolution(', 'bulk priority mutation');
$assertOrdered($batch, [
    'sort($ticket_ids, SORT_NUMERIC)',
    'mysqli_begin_transaction($mysqli)',
    "ticketOperationalLockMutationActor(\$actor_id, 'agent', \$client_ids)",
    'foreach ($client_ids as $client_id)',
    'documentationLockClient($client_id)',
    'ORDER BY ticket_id FOR UPDATE',
    'array_keys($locked) !== $ticket_ids',
    "ticketOperationalUpdateTicket(\$ticket_id, [",
    "\$actor_id, 'agent', true",
    'mysqli_commit($mysqli)',
], 'Bulk priority does not freeze its exact actor/client/ticket set in canonical order');

$fulfill = $section($operations, 'function ticketOperationalFulfillPromises(',
    'function ticketOperationalCancelPromise(', 'public promise fulfillment');
$assertOrdered($fulfill, [
    "\$actor_type !== 'agent'",
    'mysqli_begin_transaction($mysqli)',
    'ticketOperationalLockMutationActor($actor_id, $actor_type, [$client_id])',
    'documentationLockClient($client_id)',
    'FOR UPDATE',
], 'Public promise fulfillment does not restrict and reauthorize its interactive actor before business rows');

$bulk_post = $section($ticket_post, "if (isset(\$_POST['bulk_edit_ticket_priority']))", 
    "if (isset(\$_POST['bulk_edit_ticket_category']))", 'bulk priority handler');
$assertContains('ticketOperationalBatchUpdatePriority(', $bulk_post,
    'Bulk priority bypasses the atomic transaction-bound service');
$assertContains('catch (Throwable $exception)', $bulk_post,
    'Bulk priority does not fail the selected set closed');

if ($failures) {
    fwrite(STDERR, "Ticket operational authorization contract test failed:\n- "
        . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ticket operational authorization contract test passed\n";
