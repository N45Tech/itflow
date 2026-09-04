<?php

$source = file_get_contents(dirname(__DIR__) . '/functions/documentation.php');
$failures = [];

if ($source === false) {
    fwrite(STDERR, "Could not read documentation core source\n");
    exit(1);
}

$section = static function ($start, $end, $label) use ($source, &$failures) {
    $start_at = strpos($source, $start);
    $end_at = $start_at === false ? false : strpos($source, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($source, $start_at, $end_at - $start_at);
};
$assertOrdered = static function ($contents, array $needles, $message) use (&$failures) {
    $offset = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $offset + 1);
        if ($next === false || $next <= $offset) {
            $failures[] = "$message (missing or out of order: $needle)";
            return;
        }
        $offset = $next;
    }
};
$assertNotContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) !== false) {
        $failures[] = $message;
    }
};

$client_ticket_lock = $section(
    'function documentationLockClientTicket(',
    'function documentationLinkTicketObligation(',
    'client/ticket lock helper'
);
$assertOrdered($client_ticket_lock, [
    'documentationLockClient($client_id)',
    'documentationLockTicket($ticket_id)',
    "intval(\$ticket['ticket_client_id']) !== \$client_id",
], 'The shared ticket lock does not enforce client then ticket with revalidation');

$request_waiver = $section(
    'function documentationRequestTicketWaiver(',
    'function documentationDecideTicketWaiver(',
    'ticket waiver request'
);
$assertOrdered($request_waiver, [
    "\$client_id = intval(\$prelink['ticket_documentation_obligation_client_id'])",
    'documentationLockClientTicket($ticket_id, $client_id)',
    'LIMIT 1 FOR UPDATE',
], 'Ticket waiver requests do not establish client then ticket before locking the link');

$decide_waiver = $section(
    'function documentationDecideTicketWaiver(',
    'function documentationLockTicketDocumentationGraph(',
    'ticket waiver decision'
);
$assertOrdered($decide_waiver, [
    "\$client_id = intval(\$pre['ticket_documentation_obligation_client_id'])",
    'documentationLockClientTicket($ticket_id, $client_id)',
    'LIMIT 1 FOR UPDATE',
], 'Ticket waiver decisions do not establish client then ticket before locking the waiver');

$create_promise = $section(
    'function documentationCreatePromise(',
    'function documentationCompletePromise(',
    'promise creation'
);
$assertOrdered($create_promise, [
    'documentationObligationClientId($obligation_id)',
    'documentationLockClientTicket($ticket_id, $expected_client_id)',
    'documentationLoadObligationForMutation($obligation_id)',
], 'Promise creation locks its ticket before resolving and locking the obligation client');

$complete_promise = $section(
    'function documentationCompletePromise(',
    'function documentationExpireObligationExceptions(',
    'promise completion'
);
$assertOrdered($complete_promise, [
    "\$client_id = intval(\$pre['documentation_promise_client_id'])",
    'documentationLockClientTicket($ticket_id, $client_id)',
    'LIMIT 1 FOR UPDATE',
], 'Promise completion locks its ticket before the durable promise client');

foreach ([$request_waiver, $decide_waiver, $create_promise, $complete_promise] as $mutation) {
    $assertNotContains('documentationLockTicket(', $mutation,
        'A ticket-scoped documentation mutation bypasses the client-first lock helper');
}
if (substr_count($source, 'documentationLockTicket(') !== 2) {
    $failures[] = 'A direct documentation ticket lock exists outside its definition and client-first helper';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation client/ticket lock-order contracts passed.\n";
