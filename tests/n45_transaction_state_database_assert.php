<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions/integration_identity.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "N45 transaction-state database test failed: $message\n");
    exit(1);
};

if (n45DatabaseTransactionActive()) {
    $fail('a new autocommit connection was reported as transactional');
}
if (!mysqli_begin_transaction($mysqli)) {
    $fail('could not begin the test transaction');
}
if (!n45DatabaseTransactionActive()) {
    $fail('START TRANSACTION was not detected');
}
if (!mysqli_rollback($mysqli)) {
    $fail('could not roll back the test transaction');
}
if (n45DatabaseTransactionActive()) {
    $fail('the rolled-back transaction was still reported as active');
}

echo "N45 transaction-state database compatibility passed.\n";
