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
    $assert(($status['upstream_marker'] ?? '') === $upstream_marker_base, 'The N45 runner changed the upstream database marker');

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

    // Goal 10 release contract: enumerate every 0018 table/column/index from
    // the manifest independently of the generic migration-status summary, and
    // verify the owner-decision seed rows that make fresh installs usable.
    $retention_fingerprint = $definitions['n45-0018-retention-controls']['fingerprint'] ?? [];
    $db_escape = static fn (string $value): string => mysqli_real_escape_string($mysqli, $value);
    foreach (($retention_fingerprint['tables'] ?? []) as $table) {
        $table_sql = $db_escape((string) $table);
        $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = '$table_sql'");
        $assert($result !== false && intval(mysqli_fetch_row($result)[0] ?? 0) === 1,
            "Retention table $table is missing from the release database");
    }
    foreach (($retention_fingerprint['columns'] ?? []) as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            $table_sql = $db_escape((string) $table);
            $column_sql = $db_escape((string) $column);
            $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = '$table_sql'
                AND column_name = '$column_sql'");
            $assert($result !== false && intval(mysqli_fetch_row($result)[0] ?? 0) === 1,
                "Retention column $table.$column is missing from the release database");
        }
    }
    foreach (($retention_fingerprint['indexes'] ?? []) as $table => $indexes) {
        foreach (array_keys($indexes) as $index) {
            $table_sql = $db_escape((string) $table);
            $index_sql = $db_escape((string) $index);
            $result = mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = '$table_sql'
                AND index_name = '$index_sql'");
            $assert($result !== false && intval(mysqli_fetch_row($result)[0] ?? 0) > 0,
                "Retention index $table.$index is missing from the release database");
        }
    }
    $trigger_rows = mysqli_query($mysqli, "SELECT trigger_name, event_manipulation,
        action_timing, action_statement FROM information_schema.triggers
        WHERE trigger_schema = DATABASE() AND event_object_table = 'retention_events'");
    $observed_triggers = [];
    if ($trigger_rows !== false) {
        while ($trigger = mysqli_fetch_assoc($trigger_rows)) {
            $observed_triggers[$trigger['trigger_name']] = [
                strtoupper((string) $trigger['event_manipulation']),
                strtoupper((string) $trigger['action_timing']),
                strtolower(trim((string) $trigger['action_statement'])),
            ];
        }
    }
    $expected_trigger_statement = strtolower(
        "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'retention_events is append-only'"
    );
    $assert(($observed_triggers['retention_events_no_update'] ?? null)
        === ['UPDATE', 'BEFORE', $expected_trigger_statement],
        'The retention event UPDATE immutability trigger is missing or drifted');
    $assert(($observed_triggers['retention_events_no_delete'] ?? null)
        === ['DELETE', 'BEFORE', $expected_trigger_statement],
        'The retention event DELETE immutability trigger is missing or drifted');
    $expected_policy_defaults = [
        'tickets' => [2555, 30, 'manual'],
        'files' => [2555, 30, 'manual'],
        'attachments' => [2555, 30, 'manual'],
        'automation_payloads' => [30, 0, 'automatic'],
        'normalized_payloads' => [90, 0, 'automatic'],
        'evidence' => [2555, 0, 'disabled'],
    ];
    $policy_rows = mysqli_query($mysqli, "SELECT retention_policy_key,
        retention_policy_retention_days, retention_policy_restore_window_days,
        retention_policy_purge_mode, retention_policy_owner_note FROM retention_policies");
    $observed_policy_defaults = [];
    if ($policy_rows !== false) {
        while ($policy = mysqli_fetch_assoc($policy_rows)) {
            if (isset($expected_policy_defaults[$policy['retention_policy_key']])) {
                $observed_policy_defaults[$policy['retention_policy_key']] = [
                    intval($policy['retention_policy_retention_days']),
                    intval($policy['retention_policy_restore_window_days']),
                    (string) $policy['retention_policy_purge_mode'],
                ];
                $assert(trim((string) $policy['retention_policy_owner_note']) !== '',
                    "Retention policy {$policy['retention_policy_key']} has no owner-decision note");
            }
        }
    }
    ksort($expected_policy_defaults);
    ksort($observed_policy_defaults);
    $assert($observed_policy_defaults === $expected_policy_defaults,
        'Retention policy defaults do not match 7y/30d manual, 30d/90d automatic, evidence-disabled owner defaults');
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
}

mysqli_close($mysqli);

if ($failures) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}

echo 'N45 release database assertions passed for ' . getenv('N45_CI_DB_NAME') . " ($expected_source_mode).\n";
