<?php

$failures = [];
$source = file_get_contents(dirname(__DIR__) . '/agent/post/network_ip.php');

if ($source === false) {
    $failures[] = 'Unable to read the network IP post handler';
} else {
    $required = [
        '$max_file_size = 5 * 1024 * 1024;' => 'The import has no endpoint-specific byte limit',
        'filesize($file_name) > $max_file_size' => 'The import does not verify the actual uploaded file size',
        '$max_data_rows = 10000;' => 'The import has no endpoint-specific row limit',
        '$data_rows > $max_data_rows' => 'The import does not reject files over the row limit',
    ];

    foreach ($required as $needle => $message) {
        if (!str_contains($source, $needle)) {
            $failures[] = $message;
        }
    }

    $row_limit_check = strpos($source, '$data_rows > $max_data_rows');
    $import_loop = strpos($source, 'while (($column = fgetcsv');
    if ($row_limit_check === false || $import_loop === false || $row_limit_check >= $import_loop) {
        $failures[] = 'The row limit is not enforced before database-backed row processing';
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Network IP import limit checks passed" . PHP_EOL;
