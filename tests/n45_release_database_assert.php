<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
$expected_source_mode = $argv[1] ?? 'runner';
if (!in_array($expected_source_mode, ['runner', 'legacy'], true)) {
    fwrite(STDERR, "Usage: php tests/n45_release_database_assert.php [runner|legacy]\n");
    exit(2);
}

$failures = [];
$assert = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};

foreach (['N45_CI_DB_HOST', 'N45_CI_DB_USER', 'N45_CI_DB_PASSWORD', 'N45_CI_DB_NAME'] as $environment_name) {
    $assert(getenv($environment_name) !== false && getenv($environment_name) !== '', "Missing $environment_name");
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

$mysqli = mysqli_connect(
    (string) getenv('N45_CI_DB_HOST'),
    (string) getenv('N45_CI_DB_USER'),
    (string) getenv('N45_CI_DB_PASSWORD'),
    (string) getenv('N45_CI_DB_NAME'),
    intval(getenv('N45_CI_DB_PORT') ?: 3306)
);
if (!$mysqli) {
    fwrite(STDERR, 'Could not connect to the release-test database: ' . mysqli_connect_error() . "\n");
    exit(1);
}

require_once $root . '/n45/bootstrap.php';
n45RequireModule('schema');

try {
    $manifest = n45ForkManifest();
    $definitions = n45MigrationDefinitions();
    $expected_ids = array_keys($definitions);
    $status = n45MigrationStatus($mysqli);
    $ledger = n45ReadMigrationLedger($mysqli);

    $assert(($status['state'] ?? '') === 'current', 'N45 migration status is not current');
    $assert(($status['errors'] ?? []) === [], 'N45 migration status contains integrity errors: ' . implode('; ', $status['errors'] ?? []));
    $assert(($status['pending'] ?? []) === [], 'N45 migration status still contains pending migrations');
    $assert(($status['applied'] ?? []) === $expected_ids, 'N45 applied migrations do not match manifest order');
    $assert(array_keys($ledger) === $expected_ids, 'The N45 ledger is not contiguous in manifest order');

    $upstream_marker_base = (string) ($manifest['maintenance']['upstream_marker_base'] ?? '');
    $assert($upstream_marker_base === '2.6.7', 'The manifest no longer pins the reviewed upstream 2.6.7 marker');
    $current_upstream_marker = n45LatestUpstreamDatabaseVersion();
    $assert(($status['upstream_marker_latest'] ?? '') === $current_upstream_marker, 'N45 status does not report the current upstream marker');
    $assert(($status['upstream_marker'] ?? '') === $current_upstream_marker, 'The upstream runner did not advance the durable database marker to the current version');

    $manifest_files = [];
    foreach ($definitions as $id => $definition) {
        $manifest_files[] = (string) ($definition['file'] ?? '');
        $expected_file = "n45/migrations/$id.php";
        $assert(($definition['file'] ?? '') === $expected_file, "$id does not use its stable migration file name");
        $expected_checksum = hash_file('sha256', $root . '/' . $expected_file);
        $assert(is_string($expected_checksum), "Could not checksum $expected_file");
        $assert(hash_equals((string) $expected_checksum, (string) ($ledger[$id]['migration_checksum'] ?? '')), "$id ledger checksum does not match its manifest file");
        $assert((string) ($ledger[$id]['migration_legacy_version'] ?? '') === (string) ($definition['legacy_version'] ?? ''), "$id ledger legacy marker does not match the manifest");
    }

    $disk_files = array_map(
        static fn (string $file): string => 'n45/migrations/' . basename($file),
        glob($root . '/n45/migrations/*.php') ?: []
    );
    sort($manifest_files);
    sort($disk_files);
    $assert($disk_files === $manifest_files, 'The manifest and migration directory inventories differ');

    foreach ($expected_ids as $position => $id) {
        $matches = [];
        $assert(preg_match('/^n45-(\d{4})-[a-z0-9-]+$/', $id, $matches) === 1, "$id is not a stable N45 migration ID");
        if ($matches) {
            $assert(intval($matches[1]) === $position, "$id leaves a gap in the ordered N45 migration stream");
        }
    }

    $latest_legacy_version = null;
    foreach ($definitions as $definition) {
        $legacy_version = $definition['legacy_version'] ?? null;
        if (is_string($legacy_version) && $legacy_version !== ''
            && ($latest_legacy_version === null || version_compare($legacy_version, $latest_legacy_version, '>'))) {
            $latest_legacy_version = $legacy_version;
        }
    }
    foreach ($definitions as $id => $definition) {
        $expected_source = 'runner';
        if ($expected_source_mode === 'legacy') {
            $legacy_version = $definition['legacy_version'] ?? null;
            if ($id === n45NamespaceFoundationMigrationId()
                || (is_string($legacy_version) && $legacy_version !== '' && $latest_legacy_version !== null
                    && version_compare($legacy_version, $latest_legacy_version, '<='))) {
                $expected_source = 'bridge-complete';
            }
        }
        $assert(($ledger[$id]['migration_applied_by'] ?? '') === $expected_source, "$id has the wrong ledger source for the $expected_source_mode path");
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
}

mysqli_close($mysqli);

if ($failures) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

echo 'N45 release database assertions passed for ' . getenv('N45_CI_DB_NAME') . " ($expected_source_mode).\n";
