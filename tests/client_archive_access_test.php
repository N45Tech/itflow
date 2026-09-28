<?php

$client_post = file_get_contents(dirname(__DIR__) . '/agent/post/client.php');

$archive_start = strpos($client_post, "if (isset(\$_GET['archive_client']))");
$archive_end = strpos($client_post, "if (isset(\$_GET['restore_client']))", $archive_start);

if ($archive_start === false || $archive_end === false) {
    fwrite(STDERR, "Unable to locate the client archive handler.\n");
    exit(1);
}

$archive_handler = substr($client_post, $archive_start, $archive_end - $archive_start);
$client_id_position = strpos($archive_handler, "\$client_id = intval(\$_GET['archive_client']);");
$access_check_position = strpos($archive_handler, 'enforceClientAccess($client_id);');
$archive_query_position = strpos($archive_handler, 'UPDATE clients SET client_archived_at = NOW()');
$invoice_query_position = strpos($archive_handler, 'SELECT recurring_invoice_id FROM recurring_invoices');

if (
    $client_id_position === false
    || $access_check_position === false
    || $archive_query_position === false
    || $invoice_query_position === false
    || $access_check_position < $client_id_position
    || $access_check_position > $archive_query_position
    || $access_check_position > $invoice_query_position
) {
    fwrite(STDERR, "Client access must be enforced before client archive mutations.\n");
    exit(1);
}

echo "Client archive access test passed.\n";
