#!/usr/bin/env php
<?php

/*
 * Release-test helper: exercise n45-0012's retry path against a disposable
 * database without changing the durable migration ledger.
 */

if (PHP_SAPI !== 'cli') {
    exit("This fixture can only run from the command line.\n");
}

$root = dirname(__DIR__, 2);
require $root . '/config.php';
define('FROM_N45_DB_UPDATER', true);

try {
    require $root . '/n45/migrations/n45-0012-unified-endpoint-network.php';
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "n45-0012 retry fixture completed.\n";
