<?php

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/functions/endpoint.php');
$asset = file_get_contents($root . '/agent/asset.php');
$asset_modal = file_get_contents($root . '/agent/modals/asset/asset.php');

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

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Asset interface view tests passed.\n";
