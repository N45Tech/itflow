<?php

/* Bulk location assignment must not read, update, or log contacts owned by
 * a client other than the destination location's client. */

$handler = @file_get_contents(dirname(__DIR__) . '/agent/post/contact.php');
if ($handler === false) {
    fwrite(STDERR, "Could not read the contact handler.\n");
    exit(1);
}

$failures = [];
$required = [
    'SELECT contact_name FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id' => 'Contact details are not scoped to the destination client',
    'if (!$row)' => 'Missing or cross-client contacts are not rejected before logging',
    'UPDATE contacts SET contact_location_id = $location_id WHERE contact_id = $contact_id AND contact_client_id = $client_id' => 'Contact updates are not scoped to the destination client',
];

foreach ($required as $needle => $message) {
    if (!str_contains($handler, $needle)) {
        $failures[] = $message;
    }
}

$lookup_position = strpos($handler, 'SELECT contact_name FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id');
$log_position = strpos($handler, 'logAudit("Contact", "Edit", "$session_name assigned $contact_name to location $location_name"');
if ($lookup_position === false || $log_position === false || $lookup_position >= $log_position) {
    $failures[] = 'Contact ownership is not checked before its name is logged';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Contact bulk location scope contract passed.\n";
