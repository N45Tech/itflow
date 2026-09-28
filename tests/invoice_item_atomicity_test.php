<?php

/* API invoice-item creation must not leave inventory writes behind on failure. */

$source = file_get_contents(dirname(__DIR__) . '/api/v1/invoice_items/create.php');
$failures = [];

$required = [
    'is_finite($qty)' => 'Quantity is not checked for finite values',
    'is_finite($price)' => 'Price is not checked for finite values',
    'mysqli_begin_transaction($mysqli)' => 'Invoice-item writes are not transactional',
    'SELECT product_type FROM products WHERE product_id = $product_id FOR UPDATE' =>
        'Product inventory checks are not serialized',
    'if (!$stock_insert_sql)' => 'The stock deduction result is not checked',
    'mysqli_rollback($mysqli)' => 'Invoice-item failures do not roll back',
    'mysqli_commit($mysqli)' => 'Successful invoice-item writes are not committed together',
];

foreach ($required as $needle => $message) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

$stock_insert = strpos($source, '$stock_insert_sql = mysqli_query(');
$item_insert = strpos($source, '$insert_sql = mysqli_query(');
$commit = strpos($source, 'mysqli_commit($mysqli)');
if ($stock_insert === false || $item_insert === false || $commit === false
    || !($stock_insert < $item_insert && $item_insert < $commit)) {
    $failures[] = 'Stock reservation, item insertion, and commit are not ordered atomically';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Invoice-item atomicity contract passed.\n";
