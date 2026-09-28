<?php

$source = file_get_contents(dirname(__DIR__) . '/agent/post/product.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($source, '$max_file_size = 1024 * 1024;'), 'Product imports must have an endpoint-specific byte limit');
$assert(str_contains($source, '$max_product_rows = 1000;'), 'Product imports must have an explicit row limit');
$assert(str_contains($source, 'count($column) !== 3'), 'Every nonblank product row must have exactly three columns');
$assert(str_contains($source, 'mysqli_begin_transaction($mysqli)'), 'Product imports must be transactional');
$assert(str_contains($source, 'mysqli_rollback($mysqli)'), 'Failed product imports must be rolled back');
$assert(strpos($source, 'while (($column = fgetcsv') < strpos($source, 'mysqli_begin_transaction($mysqli)'),
    'The complete CSV must be validated before the import transaction starts');

if ($failures) {
    fwrite(STDERR, "Product import security checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Product import security checks passed.\n";
