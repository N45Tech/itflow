<?php

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/functions/endpoint.php');
$asset = file_get_contents($root . '/agent/asset.php');
$asset_modal = file_get_contents($root . '/agent/modals/asset/asset.php');
$endpoint_record = file_get_contents($root . '/agent/includes/inc_asset_endpoint_record.php');
$network_observations = file_get_contents($root . '/agent/includes/inc_asset_network_observations.php');

$failures = [];
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
$assertOrder = static function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $first_position = strpos($haystack, $first);
    $second_position = strpos($haystack, $second);
    if ($first_position === false || $second_position === false || $first_position >= $second_position) {
        $failures[] = $message;
    }
};

foreach ([$asset, $asset_modal] as $view) {
    $assertContains(
        'endpointAssetInterfaceRows($asset_id)',
        $view,
        'An asset interface view bypasses the deduplicated loader'
    );
    $assertContains(
        "foreach (\$related_interfaces as \$row)",
        $view,
        'An asset interface view does not render the canonical interface rows'
    );
    $assertNotContains(
        'LEFT JOIN asset_interface_links AS ail',
        $view,
        'An asset interface view still fans out base rows through connection joins'
    );
}

$assertContains(
    'function endpointGroupAssetInterfaceRows(',
    $endpoint,
    'The interface grouping boundary is missing'
);
$assertContains(
    'function endpointAssetInterfaceRows(',
    $endpoint,
    'The shared asset interface loader is missing'
);
$assertContains(
    'UNION ALL',
    $endpoint,
    'Bidirectional interface connections are not loaded independently'
);
$assertContains(
    "isset(\$connection_ids[\$interface_id][\$connected_interface_id])",
    $endpoint,
    'Duplicate connection edges are not collapsed'
);

$assertContains(
    'Network Interfaces',
    $asset,
    'The canonical interface inventory is not clearly labeled'
);
$assertContains(
    "inc_asset_network_observations.php",
    $asset,
    'The asset view does not keep source observations with the canonical interface inventory'
);
$assertOrder(
    'Network Interfaces',
    "inc_asset_network_observations.php",
    $asset,
    'Source observations are not presented beneath the editable interface inventory'
);
$assertNotContains(
    'Discovered Network State',
    $endpoint_record,
    'Source observations still render as a competing top-level network section'
);
$assertContains(
    'Source observations',
    $network_observations,
    'The consolidated source-evidence disclosure is missing'
);
$assertContains(
    'Read-only discovery evidence; edit interfaces above.',
    $network_observations,
    'The source-evidence boundary is not explained to technicians'
);
$assertContains(
    'class="collapse"',
    $network_observations,
    'Source observations are not collapsed by default'
);
$assertContains(
    'aria-expanded="false"',
    $network_observations,
    'The source-observation disclosure does not expose its initial state accessibly'
);
$assertContains(
    'foreach ($endpoint_network_current as $network_row)',
    $network_observations,
    'Current source observations are no longer rendered'
);
$assertContains(
    'foreach (array_slice($endpoint_network_history, 0, 50) as $history_row)',
    $network_observations,
    'Historical source observations are no longer retained in the asset view'
);
$assertNotContains(
    '<form',
    $network_observations,
    'Read-only discovery evidence unexpectedly gained mutation controls'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Asset interface view tests passed.\n";
