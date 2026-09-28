<?php

/* API ticket updates must not attach records owned by another client. */

$endpoint = @file_get_contents(dirname(__DIR__) . '/api/v1/tickets/update.php');
if ($endpoint === false) {
    fwrite(STDERR, "Could not read the ticket update endpoint.\n");
    exit(1);
}

$failures = [];
$required = [
    'mysqli_begin_transaction($mysqli)' => 'Ticket updates are not transactional',
    'ticket_client_id = $client_id LIMIT 1 FOR UPDATE' => 'The ticket is not locked within its client scope',
    "['contacts', 'contact_id', 'contact_client_id', \$contact]" => 'Contact ownership is not validated',
    "['assets', 'asset_id', 'asset_client_id', \$asset]" => 'Asset ownership is not validated',
    "['vendors', 'vendor_id', 'vendor_client_id', \$vendor_id]" => 'Vendor ownership is not validated',
    '$client_column = $client_id LIMIT 1 FOR UPDATE' => 'Related records are not locked and scoped to the ticket client',
    'mysqli_rollback($mysqli)' => 'Failed validation does not roll back the ticket update',
];

foreach ($required as $needle => $message) {
    if (!str_contains($endpoint, $needle)) {
        $failures[] = $message;
    }
}

$validation_position = strpos($endpoint, 'foreach ($client_relationships');
$update_position = strpos($endpoint, 'UPDATE tickets SET');
if ($validation_position === false || $update_position === false || $validation_position >= $update_position) {
    $failures[] = 'Related-record ownership is not checked before the ticket update';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ticket API relationship scope contract passed.\n";
