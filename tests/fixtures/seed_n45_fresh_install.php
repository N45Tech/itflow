#!/usr/bin/env php
<?php

/*
 * Release-test helper: reproduce the N45 ledger step performed by the web and
 * CLI installers after importing the final db.sql schema.
 */

if (PHP_SAPI !== 'cli') {
    exit("This fixture can only run from the command line.\n");
}

$root = dirname(__DIR__, 2);
require $root . '/config.php';
require_once $root . '/n45/bootstrap.php';
n45RequireModule('schema');

try {
    n45SeedFreshInstallMigrations($mysqli);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "N45 fresh-install ledger fixture completed.\n";
