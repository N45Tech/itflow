<?php

$root = dirname(__DIR__);
$ticket = file_get_contents($root . '/agent/ticket.php');
$ticketPost = file_get_contents($root . '/agent/post/ticket.php');
$vendorHandlerStart = strpos($ticketPost, "if (isset(\$_POST['edit_ticket_vendor'])) {");
$vendorHandlerEnd = $vendorHandlerStart === false
    ? false
    : strpos($ticketPost, "if (isset(\$_POST['edit_ticket_operations'])) {", $vendorHandlerStart);
$vendorHandler = $vendorHandlerStart !== false && $vendorHandlerEnd !== false
    ? substr($ticketPost, $vendorHandlerStart, $vendorHandlerEnd - $vendorHandlerStart)
    : '';

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains(
    'LEFT JOIN vendors ON ticket_vendor_id = vendor_id AND vendor_client_id = ticket_client_id',
    $ticket,
    'Ticket vendor details are not constrained to the ticket client'
);
$assertContains(
    'WHERE vendor_id = $vendor_id AND vendor_client_id = $client_id',
    $vendorHandler,
    'Ticket vendor updates do not validate the vendor client'
);
$assertContains(
    'AND vendor_archived_at IS NULL LIMIT 1',
    $vendorHandler,
    'Ticket vendor updates do not reject archived vendors'
);
$assertContains(
    "flashAlert('The selected vendor is unavailable for this client', 'error');",
    $vendorHandler,
    'Invalid ticket vendor updates do not stop with an error'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket vendor scope tests passed.\n";
