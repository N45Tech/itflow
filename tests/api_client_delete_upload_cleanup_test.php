<?php

/* Source contract for API client upload cleanup. */

$root = dirname(__DIR__);
$delete_source = file_get_contents($root . '/api/v1/clients/delete.php');
$failures = [];

if ($delete_source === false) {
    fwrite(STDERR, "Could not read the API client deletion source\n");
    exit(1);
}

$ordered_needles = [
    'DELETE FROM clients WHERE client_id = $client_id',
    '$client_upload_directory = dirname(__DIR__, 3) . "/uploads/clients/$client_id"',
    'removeDirectory($client_upload_directory)',
    'file_exists($client_upload_directory) || is_link($client_upload_directory)',
    'mysqli_rollback($mysqli)',
    "throw new RuntimeException('Could not remove client uploads.')",
    'mysqli_commit($mysqli)',
];
$last_position = -1;
foreach ($ordered_needles as $needle) {
    $position = strpos($delete_source, $needle, $last_position + 1);
    if ($position === false) {
        $failures[] = "API client deletion is missing: $needle";
    } else {
        $last_position = $position;
    }
}

if (str_contains($delete_source, '../../uploads/clients/')) {
    $failures[] = 'API client deletion still uses a working-directory-relative upload path';
}

if ($failures) {
    fwrite(STDERR, "API client upload cleanup contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "API client upload cleanup contract passed\n";
