<?php

$modal = file_get_contents(__DIR__ . '/../agent/modals/asset/asset_interface_bulk_edit_network.php');
$failures = [];

$permission_check = strpos($modal, "enforceUserPermission('module_support');");
$client_check = strpos($modal, 'enforceClientAccess($client_id);');
$network_query = strpos($modal, 'FROM networks');

if ($permission_check === false) {
    $failures[] = 'Bulk network modal does not enforce support module access';
}

if ($client_check === false) {
    $failures[] = 'Bulk network modal does not enforce access to the requested client';
}

if ($network_query === false || $permission_check > $network_query || $client_check > $network_query) {
    $failures[] = 'Bulk network modal authorization must occur before querying client networks';
}

if ($failures) {
    fwrite(STDERR, "Asset interface bulk network access failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Asset interface bulk network access contracts passed.\n";
