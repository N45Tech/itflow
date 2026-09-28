<?php

$client_post = file_get_contents(dirname(__DIR__) . '/agent/post/client.php');

$handler_start = strpos($client_post, "if (isset(\$_POST['bulk_edit_client_net_terms']))");
$handler_end = strpos($client_post, "if (isset(\$_POST['bulk_assign_client_tags']))", $handler_start);

if ($handler_start === false || $handler_end === false) {
    fwrite(STDERR, "Unable to locate the bulk client net-terms handler.\n");
    exit(1);
}

$handler = substr($client_post, $handler_start, $handler_end - $handler_start);
$normalization_position = strpos($handler, "\$client_ids = array_map('intval', \$_POST['client_ids']);");
$preflight_position = strpos($handler, 'foreach ($client_ids as $client_id)');
$access_position = strpos($handler, 'enforceClientAccess($client_id);');
$update_loop_position = strpos($handler, 'foreach($client_ids as $client_id)');
$update_position = strpos($handler, 'UPDATE clients SET client_net_terms');

if (
    $normalization_position === false
    || $preflight_position === false
    || $access_position === false
    || $update_loop_position === false
    || $update_position === false
    || $normalization_position > $preflight_position
    || $preflight_position > $access_position
    || $access_position > $update_loop_position
    || $update_loop_position > $update_position
) {
    fwrite(STDERR, "Every bulk net-terms client must be authorized before any update.\n");
    exit(1);
}

echo "Bulk client net-terms access test passed.\n";
