<?php

/*
 * N45-owned migration stream. Status checks are read-only; the ledger, bridge,
 * and runner are invoked only by setup or an explicit maintenance action.
 */

function n45MigrationDefinitions(): array
{
    $definitions = n45ForkManifest()['migrations'] ?? [];
    if (!is_array($definitions)) {
        throw new RuntimeException('The N45 migration inventory is invalid');
    }

    $declared_files = [];
    $previous_id = null;
    $previous_sequence = null;
    $previous_legacy_version = null;
    foreach ($definitions as $id => $definition) {
        if (!is_string($id)
            || preg_match('/^n45-([0-9]{4})-[a-z0-9-]+$/', $id, $id_matches) !== 1
            || !is_array($definition)) {
            throw new RuntimeException('The N45 migration inventory contains an invalid ID');
        }
        $sequence = intval($id_matches[1]);
        if ($previous_sequence !== null && $sequence <= $previous_sequence) {
            throw new RuntimeException("N45 migration $id is not ordered after $previous_id");
        }
        $expected_file = 'n45/migrations/' . $id . '.php';
        if (($definition['file'] ?? '') !== $expected_file) {
            throw new RuntimeException("N45 migration $id does not use its stable file name");
        }
        if (!array_key_exists('legacy_version', $definition)) {
            throw new RuntimeException("N45 migration $id does not declare its legacy version contract");
        }
        $legacy_version = $definition['legacy_version'];
        if ($legacy_version !== null
            && (!is_string($legacy_version) || preg_match('/^\d+(?:\.\d+)+$/', $legacy_version) !== 1)) {
            throw new RuntimeException("N45 migration $id has an invalid legacy version");
        }
        if ($legacy_version !== null
            && $previous_legacy_version !== null
            && version_compare($legacy_version, $previous_legacy_version, '<=')) {
            throw new RuntimeException("N45 migration $id has an out-of-order legacy version");
        }
        $declared_files[] = basename($expected_file);
        $previous_id = $id;
        $previous_sequence = $sequence;
        if ($legacy_version !== null) {
            $previous_legacy_version = $legacy_version;
        }
    }

    $disk_files = array_map('basename', glob(dirname(__DIR__) . '/n45/migrations/*.php') ?: []);
    sort($declared_files);
    sort($disk_files);
    if ($declared_files !== $disk_files) {
        throw new RuntimeException('The N45 migration directory and manifest do not match');
    }

    n45AssertMigrationNamespaceReservations($definitions);

    return $definitions;
}

function n45MigrationNamespaceReservations(): array
{
    $reservations = n45ForkManifest()['maintenance']['integration_migration_reservations'] ?? [];
    if (!is_array($reservations)) {
        throw new RuntimeException('The N45 integration migration reservations are invalid');
    }

    $previous_version = null;
    $previous_sequence = null;
    foreach ($reservations as $legacy_version => $reservation) {
        if (!is_string($legacy_version)
            || preg_match('/^\d+(?:\.\d+)+$/', $legacy_version) !== 1
            || !is_array($reservation)) {
            throw new RuntimeException('The N45 integration migration reservations contain invalid metadata');
        }

        $id = $reservation['id'] ?? null;
        if (!is_string($id)
            || preg_match('/^n45-(\d{4})-[a-z0-9-]+$/', $id, $id_matches) !== 1
            || !n45MigrationReservationMetadataIsValid($reservation, ['id'])) {
            throw new RuntimeException("The N45 integration migration reservation for $legacy_version is incomplete");
        }

        if ($previous_version !== null && version_compare($legacy_version, $previous_version, '<=')) {
            throw new RuntimeException('The N45 integration migration reservations are not ordered by legacy version');
        }
        $sequence = intval($id_matches[1]);
        if ($previous_sequence !== null && $sequence <= $previous_sequence) {
            throw new RuntimeException('The N45 integration migration reservations are not ordered by stable ID');
        }
        $previous_version = $legacy_version;
        $previous_sequence = $sequence;
    }

    return $reservations;
}

function n45PostIntegrationMigrationReservations(): array
{
    $reservations = n45ForkManifest()['maintenance']['post_integration_migration_reservations'] ?? [];
    if (!is_array($reservations)) {
        throw new RuntimeException('The N45 post-integration migration reservations are invalid');
    }

    $previous_sequence = null;
    foreach ($reservations as $id => $reservation) {
        if (!is_string($id)
            || preg_match('/^n45-(\d{4})-[a-z0-9-]+$/', $id, $id_matches) !== 1
            || !is_array($reservation)
            || !array_key_exists('legacy_version', $reservation)
            || $reservation['legacy_version'] !== null
            || !n45MigrationReservationMetadataIsValid($reservation, ['legacy_version'])) {
            throw new RuntimeException("The N45 post-integration migration reservation $id is incomplete");
        }
        $sequence = intval($id_matches[1]);
        if ($previous_sequence !== null && $sequence <= $previous_sequence) {
            throw new RuntimeException('The N45 post-integration migration reservations are not ordered by stable ID');
        }
        $previous_sequence = $sequence;
    }

    return $reservations;
}

function n45MigrationReservationMetadataIsValid(array $reservation, array $additional_keys = []): bool
{
    $required_keys = array_merge([
        'module',
        'data_change',
        'rollback',
        'created_tables',
        'altered_columns',
        'altered_indexes',
        'legacy_bridge_index_overrides',
    ], $additional_keys);
    $keys = array_keys($reservation);
    if (array_diff($required_keys, $keys) || array_diff($keys, $required_keys)) {
        return false;
    }
    if (!is_string($reservation['module'])
        || preg_match('/^[a-z0-9_]+$/', $reservation['module']) !== 1
        || !is_bool($reservation['data_change'])
        || !is_string($reservation['rollback'])
        || trim($reservation['rollback']) === ''
        || !n45MigrationReservationIdentifierListIsValid($reservation['created_tables'], true)
        || !n45MigrationReservationIdentifierMapIsValid($reservation['altered_columns'], false)
        || !n45MigrationReservationIndexMapIsValid($reservation['altered_indexes'])
        || !n45MigrationReservationOverrideMapIsValid($reservation['legacy_bridge_index_overrides'])) {
        return false;
    }

    return $reservation['created_tables'] || $reservation['altered_columns'] || $reservation['altered_indexes'];
}

function n45MigrationReservationIdentifierListIsValid($identifiers, bool $allow_empty): bool
{
    if (!is_array($identifiers) || !n45FingerprintIsList($identifiers) || (!$allow_empty && !$identifiers)) {
        return false;
    }
    foreach ($identifiers as $identifier) {
        if (!n45FingerprintIdentifierIsSafe($identifier)
            || count(array_keys($identifiers, $identifier, true)) !== 1) {
            return false;
        }
    }
    return true;
}

function n45MigrationReservationIdentifierMapIsValid($identifier_map, bool $allow_empty_values): bool
{
    if (!is_array($identifier_map)) {
        return false;
    }
    foreach ($identifier_map as $table => $identifiers) {
        if (!n45FingerprintIdentifierIsSafe($table)
            || !n45MigrationReservationIdentifierListIsValid($identifiers, $allow_empty_values)) {
            return false;
        }
    }
    return true;
}

function n45MigrationReservationIndexMapIsValid($index_map): bool
{
    if (!is_array($index_map)) {
        return false;
    }
    foreach ($index_map as $table => $indexes) {
        if (!n45FingerprintIdentifierIsSafe($table) || !is_array($indexes) || !$indexes) {
            return false;
        }
        foreach ($indexes as $index => $contract) {
            if (!n45FingerprintIdentifierIsSafe($index)
                || !is_array($contract)
                || array_keys($contract) !== ['unique', 'columns']
                || !is_bool($contract['unique'] ?? null)
                || !n45MigrationReservationIdentifierListIsValid($contract['columns'] ?? null, false)) {
                return false;
            }
        }
    }
    return true;
}

function n45MigrationReservationOverrideMapIsValid($override_map): bool
{
    if (!is_array($override_map)) {
        return false;
    }
    foreach ($override_map as $table => $indexes) {
        if (!n45FingerprintIdentifierIsSafe($table) || !is_array($indexes) || !$indexes) {
            return false;
        }
        foreach ($indexes as $index => $contracts) {
            if (!n45FingerprintIdentifierIsSafe($index)
                || !is_array($contracts)
                || array_keys($contracts) !== ['legacy', 'final']
                || !n45MigrationReservationIndexMapIsValid([$table => [$index => $contracts['legacy']]])
                || !n45MigrationReservationIndexMapIsValid([$table => [$index => $contracts['final']]])) {
                return false;
            }
        }
    }
    return true;
}

function n45MigrationReservationDefinitionMatches(array $definition, string $legacy_version, array $reservation): bool
{
    if (!array_key_exists('legacy_version', $definition)
        || $definition['legacy_version'] !== ($legacy_version === '' ? null : $legacy_version)
        || ($definition['module'] ?? null) !== $reservation['module']
        || ($definition['data_change'] ?? null) !== $reservation['data_change']
        || ($definition['rollback'] ?? null) !== $reservation['rollback']) {
        return false;
    }

    $fingerprint = $definition['fingerprint'] ?? null;
    if (!is_array($fingerprint) || ($fingerprint['tables'] ?? []) !== $reservation['created_tables']) {
        return false;
    }
    $fingerprint_columns = $fingerprint['columns'] ?? [];
    if (!is_array($fingerprint_columns)) {
        return false;
    }
    $expected_column_tables = array_merge($reservation['created_tables'], array_keys($reservation['altered_columns']));
    $actual_column_tables = array_keys($fingerprint_columns);
    sort($expected_column_tables);
    sort($actual_column_tables);
    if ($actual_column_tables !== $expected_column_tables) {
        return false;
    }
    foreach ($reservation['altered_columns'] as $table => $columns) {
        if (array_keys($fingerprint_columns[$table] ?? []) !== $columns) {
            return false;
        }
    }

    $fingerprint_indexes = $fingerprint['indexes'] ?? [];
    if (!is_array($fingerprint_indexes)) {
        return false;
    }
    $expected_index_tables = array_merge($reservation['created_tables'], array_keys($reservation['altered_indexes']));
    $actual_index_tables = array_keys($fingerprint_indexes);
    sort($expected_index_tables);
    sort($actual_index_tables);
    if ($actual_index_tables !== $expected_index_tables) {
        return false;
    }
    foreach ($reservation['altered_indexes'] as $table => $indexes) {
        foreach ($indexes as $index => $contract) {
            if (($fingerprint_indexes[$table][$index] ?? null) !== $contract) {
                return false;
            }
        }
    }

    $overrides = $reservation['legacy_bridge_index_overrides'];
    if (!$overrides) {
        return !array_key_exists('legacy_bridge_fingerprint', $definition);
    }
    $expected_legacy_fingerprint = $fingerprint;
    foreach ($overrides as $table => $indexes) {
        foreach ($indexes as $index => $contracts) {
            if (($fingerprint['indexes'][$table][$index] ?? null) !== $contracts['final']) {
                return false;
            }
            $expected_legacy_fingerprint['indexes'][$table][$index] = $contracts['legacy'];
        }
    }
    return ($definition['legacy_bridge_fingerprint'] ?? null) === $expected_legacy_fingerprint;
}

function n45AssertMigrationNamespaceReservations(array $definitions): void
{
    $namespace_reservations = n45MigrationNamespaceReservations();
    $reclaimed_upstream_checksums = n45ForkManifest()['maintenance']['upstream_reclaimed_migration_checksums'] ?? [];
    if (!is_array($reclaimed_upstream_checksums)
        || array_diff_key($reclaimed_upstream_checksums, $namespace_reservations)) {
        throw new RuntimeException('The reviewed upstream migration checksum inventory is invalid');
    }
    foreach ($reclaimed_upstream_checksums as $legacy_version => $checksum) {
        if (!is_string($legacy_version)
            || preg_match('/^\d+(?:\.\d+)+$/', $legacy_version) !== 1
            || !is_string($checksum)
            || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new RuntimeException('The reviewed upstream migration checksum inventory contains invalid metadata');
        }
    }

    $seen_unconsumed = false;
    $first_unconsumed_id = null;
    foreach ($namespace_reservations as $legacy_version => $reservation) {
        $id = $reservation['id'];
        $upstream_file = dirname(__DIR__) . '/admin/database_updates/' . $legacy_version . '.php';
        if (is_file($upstream_file)) {
            $expected_checksum = $reclaimed_upstream_checksums[$legacy_version] ?? null;
            $observed_checksum = hash_file('sha256', $upstream_file);
            if (!is_string($expected_checksum)
                || !is_string($observed_checksum)
                || !hash_equals($expected_checksum, $observed_checksum)) {
                throw new RuntimeException(
                    "Reserved N45 migration version $legacy_version conflicts with an unreviewed upstream migration file"
                );
            }
        } elseif (isset($reclaimed_upstream_checksums[$legacy_version])) {
            throw new RuntimeException(
                "Reviewed upstream migration $legacy_version is missing from admin/database_updates"
            );
        }

        if (!isset($definitions[$id])) {
            $seen_unconsumed = true;
            $first_unconsumed_id ??= $id;
            continue;
        }
        if ($seen_unconsumed) {
            throw new RuntimeException("N45 migration reservation $id was consumed out of order");
        }

        if (!n45MigrationReservationDefinitionMatches($definitions[$id], $legacy_version, $reservation)) {
            throw new RuntimeException("N45 migration reservation $id does not match its released manifest contract");
        }
    }

    if ($first_unconsumed_id !== null) {
        $first_unconsumed_sequence = intval(substr($first_unconsumed_id, 4, 4));
        foreach (array_keys($definitions) as $id) {
            if (intval(substr((string) $id, 4, 4)) >= $first_unconsumed_sequence) {
                throw new RuntimeException(
                    "Reserved N45 migration $first_unconsumed_id must be consumed before later stable migrations"
                );
            }
        }
    }

    foreach (n45PostIntegrationMigrationReservations() as $id => $reservation) {
        if ($seen_unconsumed && isset($definitions[$id])) {
            throw new RuntimeException("N45 post-integration migration $id was consumed before its prerequisites");
        }
        if (!$seen_unconsumed && !isset($definitions[$id])) {
            throw new RuntimeException("Required post-integration migration $id is missing");
        }
        if (isset($definitions[$id])
            && !n45MigrationReservationDefinitionMatches($definitions[$id], '', $reservation)) {
            throw new RuntimeException("N45 post-integration migration $id does not match its released manifest contract");
        }
    }
}

function n45NamespaceFoundationMigrationId(): string
{
    return 'n45-0000-namespace-foundation';
}

function n45MigrationFile(string $id, array $definition): string
{
    $relative_file = (string) ($definition['file'] ?? '');
    if ($relative_file !== 'n45/migrations/' . $id . '.php') {
        throw new RuntimeException("N45 migration $id has an unsafe file path");
    }

    $file = dirname(__DIR__) . '/' . $relative_file;
    if (!is_file($file)) {
        throw new RuntimeException("N45 migration $id is missing its migration file");
    }
    return $file;
}

function n45MigrationChecksum(string $id, array $definition): string
{
    $checksum = hash_file('sha256', n45MigrationFile($id, $definition));
    if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
        throw new RuntimeException("Could not checksum N45 migration $id");
    }
    return $checksum;
}

function n45MigrationQueryScalar($mysqli, string $query): string
{
    $result = mysqli_query($mysqli, $query);
    if (!$result) {
        throw new RuntimeException('N45 migration integrity query failed: ' . mysqli_error($mysqli));
    }
    $row = mysqli_fetch_row($result);
    if (!is_array($row) || !array_key_exists(0, $row)) {
        throw new RuntimeException('N45 migration integrity query returned no result');
    }
    return (string) $row[0];
}

function n45MigrationQueryRows($mysqli, string $query): array
{
    $result = mysqli_query($mysqli, $query);
    if (!$result) {
        throw new RuntimeException('N45 migration integrity query failed: ' . mysqli_error($mysqli));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function n45MigrationSafeIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException("Unsafe identifier in N45 migration fingerprint: $identifier");
    }
    return $identifier;
}

function n45DocumentationEvidenceReferenceIndexShape(array $rows): string
{
    if (!$rows) {
        return 'absent';
    }

    $expected_columns = [
        'documentation_evidence_obligation_id',
        'documentation_evidence_requirement_version_id',
        'documentation_evidence_reference_type',
        'documentation_evidence_reference_id',
        'documentation_evidence_reference_hash',
    ];
    if (array_values($rows) !== $rows || count($rows) !== count($expected_columns)) {
        throw new RuntimeException('The documentation evidence-reference index has an unexpected column count');
    }

    $non_unique = null;
    foreach ($rows as $position => $row) {
        if (!is_array($row)
            || !array_key_exists('NON_UNIQUE', $row)
            || !array_key_exists('SEQ_IN_INDEX', $row)
            || !array_key_exists('COLUMN_NAME', $row)
            || !array_key_exists('COLLATION', $row)
            || !array_key_exists('SUB_PART', $row)
            || !array_key_exists('INDEX_TYPE', $row)) {
            throw new RuntimeException('The documentation evidence-reference index metadata is incomplete');
        }

        $observed_non_unique = $row['NON_UNIQUE'];
        if (!in_array($observed_non_unique, [0, 1, '0', '1'], true)
            || !in_array($row['SEQ_IN_INDEX'], [$position + 1, (string) ($position + 1)], true)
            || !is_string($row['COLUMN_NAME'])
            || $row['COLUMN_NAME'] !== $expected_columns[$position]
            || $row['COLLATION'] !== 'A'
            || $row['SUB_PART'] !== null
            || !is_string($row['INDEX_TYPE'])
            || strtoupper($row['INDEX_TYPE']) !== 'BTREE') {
            throw new RuntimeException('The documentation evidence-reference index has an unexpected shape');
        }

        $observed_non_unique = intval($observed_non_unique);
        if ($non_unique !== null && $observed_non_unique !== $non_unique) {
            throw new RuntimeException('The documentation evidence-reference index has inconsistent uniqueness metadata');
        }
        $non_unique = $observed_non_unique;
    }

    return $non_unique === 0 ? 'historical_unique' : 'final_nonunique';
}

function n45FingerprintIsList(array $items): bool
{
    return array_values($items) === $items;
}

function n45FingerprintIdentifierIsSafe($identifier): bool
{
    return is_string($identifier) && preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
}

function n45FingerprintColumnEntries(array $columns): array
{
    $entries = [];
    foreach ($columns as $column => $contract) {
        if (is_int($column)) {
            $entries[] = ['name' => (string) $contract, 'contract' => null];
        } else {
            $entries[] = ['name' => (string) $column, 'contract' => $contract];
        }
    }
    return $entries;
}

function n45FingerprintIndexEntries(array $indexes): array
{
    $entries = [];
    foreach ($indexes as $index => $contract) {
        if (is_int($index)) {
            $entries[] = ['name' => (string) $contract, 'contract' => null];
        } else {
            $entries[] = ['name' => (string) $index, 'contract' => $contract];
        }
    }
    return $entries;
}

function n45FingerprintTypeIsValid($type): bool
{
    if (!is_string($type)) {
        return false;
    }
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $type)));
    return preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?$/', $normalized) === 1
        || preg_match('/^(?:decimal|numeric)\(\d+,\d+\)(?: unsigned)?$/', $normalized) === 1
        || preg_match('/^(?:char|varchar|binary|varbinary)\(\d+\)$/', $normalized) === 1
        || preg_match('/^(?:date|datetime|timestamp|time|text|tinytext|mediumtext|longtext|json|blob|tinyblob|mediumblob|longblob)$/', $normalized) === 1;
}

function n45FingerprintExtraIsValid($extra): bool
{
    if (!is_string($extra)) {
        return false;
    }
    return in_array(n45NormalizeFingerprintExtra($extra), ['', 'auto_increment', 'on update current_timestamp'], true);
}

function n45ValidateFingerprintContract(string $id, $fingerprint): array
{
    if (!is_array($fingerprint) || !$fingerprint) {
        return ["$id has an invalid fingerprint definition"];
    }

    $failures = [];
    $allowed_sections = ['tables', 'columns', 'indexes', 'failure_queries'];
    foreach (array_keys($fingerprint) as $section) {
        if (!is_string($section) || !in_array($section, $allowed_sections, true)) {
            $failures[] = "$id has an unknown fingerprint section";
        }
    }

    if (array_key_exists('tables', $fingerprint)) {
        $tables = $fingerprint['tables'];
        if (!is_array($tables) || !n45FingerprintIsList($tables) || !$tables) {
            $failures[] = "$id has an invalid table fingerprint";
        } else {
            $seen_tables = [];
            foreach ($tables as $table) {
                if (!n45FingerprintIdentifierIsSafe($table) || isset($seen_tables[$table])) {
                    $failures[] = "$id has an invalid or duplicate table fingerprint";
                    continue;
                }
                $seen_tables[$table] = true;
            }
        }
    }

    if (array_key_exists('columns', $fingerprint)) {
        $column_tables = $fingerprint['columns'];
        if (!is_array($column_tables) || !$column_tables) {
            $failures[] = "$id has an invalid column fingerprint";
        } else {
            foreach ($column_tables as $table => $columns) {
                if (!n45FingerprintIdentifierIsSafe($table) || !is_array($columns) || !$columns) {
                    $failures[] = "$id has an invalid column fingerprint";
                    continue;
                }

                $seen_columns = [];
                foreach ($columns as $column => $contract) {
                    $column_name = is_int($column) ? $contract : $column;
                    if (!n45FingerprintIdentifierIsSafe($column_name) || isset($seen_columns[$column_name])) {
                        $failures[] = "$id has an invalid or duplicate column fingerprint";
                        continue;
                    }
                    $seen_columns[$column_name] = true;

                    if (is_int($column)) {
                        if (!is_string($contract)) {
                            $failures[] = "$id has an invalid column fingerprint for $table.$column_name";
                        }
                        continue;
                    }

                    if (!is_array($contract)) {
                        $failures[] = "$id has an invalid column contract for $table.$column_name";
                        continue;
                    }
                    $required = ['type', 'nullable', 'default', 'extra'];
                    $unknown = array_diff(array_keys($contract), $required);
                    $missing = array_diff($required, array_keys($contract));
                    $default = $contract['default'] ?? null;
                    if ($unknown || $missing
                        || !n45FingerprintTypeIsValid($contract['type'] ?? null)
                        || !is_bool($contract['nullable'] ?? null)
                        || !(is_null($default) || is_string($default) || is_int($default) || is_float($default))
                        || !n45FingerprintExtraIsValid($contract['extra'] ?? null)) {
                        $failures[] = "$id has an incomplete column contract for $table.$column_name";
                    }
                }
            }
        }
    }

    if (array_key_exists('indexes', $fingerprint)) {
        $index_tables = $fingerprint['indexes'];
        if (!is_array($index_tables) || !$index_tables) {
            $failures[] = "$id has an invalid index fingerprint";
        } else {
            foreach ($index_tables as $table => $indexes) {
                if (!n45FingerprintIdentifierIsSafe($table) || !is_array($indexes) || !$indexes) {
                    $failures[] = "$id has an invalid index fingerprint";
                    continue;
                }

                $seen_indexes = [];
                foreach ($indexes as $index => $contract) {
                    $index_name = is_int($index) ? $contract : $index;
                    if (!n45FingerprintIdentifierIsSafe($index_name) || isset($seen_indexes[$index_name])) {
                        $failures[] = "$id has an invalid or duplicate index fingerprint";
                        continue;
                    }
                    $seen_indexes[$index_name] = true;

                    if (is_int($index)) {
                        if (!is_string($contract)) {
                            $failures[] = "$id has an invalid index fingerprint for $table.$index_name";
                        }
                        continue;
                    }

                    if (!is_array($contract)) {
                        $failures[] = "$id has an invalid index contract for $table.$index_name";
                        continue;
                    }
                    $required = ['unique', 'columns'];
                    $unknown = array_diff(array_keys($contract), $required);
                    $missing = array_diff($required, array_keys($contract));
                    $index_columns = $contract['columns'] ?? null;
                    if ($unknown || $missing
                        || !is_bool($contract['unique'] ?? null)
                        || !is_array($index_columns)
                        || !n45FingerprintIsList($index_columns)
                        || !$index_columns) {
                        $failures[] = "$id has an incomplete index contract for $table.$index_name";
                        continue;
                    }
                    foreach ($index_columns as $index_column) {
                        if (!n45FingerprintIdentifierIsSafe($index_column)
                            || count(array_keys($index_columns, $index_column, true)) !== 1) {
                            $failures[] = "$id has an invalid indexed column for $table.$index_name";
                        }
                    }
                }
            }
        }
    }

    if (array_key_exists('failure_queries', $fingerprint)) {
        $failure_queries = $fingerprint['failure_queries'];
        if (!is_array($failure_queries) || !n45FingerprintIsList($failure_queries) || !$failure_queries) {
            $failures[] = "$id has an invalid data fingerprint";
        } else {
            foreach ($failure_queries as $failure_query) {
                if (!is_string($failure_query)
                    || !preg_match('/^SELECT\s/i', ltrim($failure_query))
                    || str_contains($failure_query, ';')) {
                    $failures[] = "$id has an unsafe data fingerprint";
                }
            }
        }
    }

    return $failures;
}

function n45FingerprintInventory(array $fingerprint): array
{
    $inventory = [
        'tables' => $fingerprint['tables'] ?? [],
        'columns' => [],
        'indexes' => [],
        'failure_queries' => $fingerprint['failure_queries'] ?? [],
    ];
    foreach (($fingerprint['columns'] ?? []) as $table => $columns) {
        $inventory['columns'][$table] = is_array($columns) ? array_keys($columns) : null;
    }
    foreach (($fingerprint['indexes'] ?? []) as $table => $indexes) {
        $inventory['indexes'][$table] = is_array($indexes) ? array_keys($indexes) : null;
    }
    return $inventory;
}

function n45ValidateFingerprintDefinition(string $id, array $definition): array
{
    $failures = n45ValidateFingerprintContract($id, $definition['fingerprint'] ?? null);
    if (array_key_exists('runner_fingerprint', $definition)) {
        $failures = array_merge(
            $failures,
            n45ValidateFingerprintContract("$id runner", $definition['runner_fingerprint'])
        );
        if (is_array($definition['fingerprint'] ?? null)
            && is_array($definition['runner_fingerprint'])
            && n45FingerprintInventory($definition['runner_fingerprint'])
                !== n45FingerprintInventory($definition['fingerprint'])) {
            $failures[] = "$id runner fingerprint changes the final fingerprint inventory";
        }
    }
    if (array_key_exists('legacy_bridge_fingerprint', $definition)) {
        $failures = array_merge(
            $failures,
            n45ValidateFingerprintContract("$id legacy bridge", $definition['legacy_bridge_fingerprint'])
        );
    }
    if (array_key_exists('legacy_bridge_fingerprint_until', $definition)) {
        $until = $definition['legacy_bridge_fingerprint_until'];
        $legacy_version = $definition['legacy_version'] ?? null;
        if (!array_key_exists('legacy_bridge_fingerprint', $definition)
            || !is_string($until)
            || preg_match('/^\d+(?:\.\d+)+$/', $until) !== 1
            || !is_string($legacy_version)
            || version_compare($until, $legacy_version, '<')) {
            $failures[] = "$id has an invalid legacy bridge fingerprint boundary";
        }
    }
    return $failures;
}

function n45MigrationRunnerFingerprintNames(array $definition): array
{
    $fingerprints = ['fingerprint'];
    if (array_key_exists('runner_fingerprint', $definition)) {
        $fingerprints[] = 'runner_fingerprint';
    }
    return $fingerprints;
}

function n45MigrationBridgeFingerprintName(array $definition, string $legacy_marker): string
{
    if (!array_key_exists('legacy_bridge_fingerprint', $definition)) {
        return 'fingerprint';
    }

    $until = $definition['legacy_bridge_fingerprint_until'] ?? null;
    if (is_string($until) && $until !== '' && version_compare($legacy_marker, $until, '>')) {
        return 'fingerprint';
    }

    return 'legacy_bridge_fingerprint';
}

function n45NormalizeFingerprintType($type): string
{
    $normalized = strtolower(preg_replace('/\s+/', '', (string) $type));
    if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)(unsigned)?$/', $normalized, $matches)) {
        $base = $matches[1] === 'integer' ? 'int' : $matches[1];
        return $base . ($matches[2] ?? '');
    }
    return $normalized;
}

function n45NormalizeFingerprintDefault($default, bool $information_schema = false)
{
    if ($default === null) {
        return null;
    }
    $normalized = trim((string) $default);
    if ($information_schema && strcasecmp($normalized, 'NULL') === 0) {
        return null;
    }
    if (strlen($normalized) >= 2
        && (($normalized[0] === "'" && substr($normalized, -1) === "'")
            || ($normalized[0] === '"' && substr($normalized, -1) === '"'))) {
        $normalized = substr($normalized, 1, -1);
    }
    if (preg_match('/^current_timestamp(?:\(\))?$/i', $normalized)) {
        return 'current_timestamp';
    }
    return $normalized;
}

function n45NormalizeFingerprintExtra($extra): string
{
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', (string) $extra)));
    $normalized = trim(preg_replace('/\bdefault_generated\b/', '', $normalized));
    $normalized = preg_replace('/^on update current_timestamp\(\)$/', 'on update current_timestamp', $normalized);
    return trim(preg_replace('/\s+/', ' ', $normalized));
}

function n45CompareColumnFingerprint(string $id, string $table, string $column, array $expected, ?array $observed): array
{
    if ($observed === null) {
        return ["$id is missing column $table.$column"];
    }

    $failures = [];
    $observed_required = ['type', 'nullable', 'default', 'extra'];
    if (array_diff($observed_required, array_keys($observed))
        || !is_bool($observed['nullable'] ?? null)) {
        return ["$id could not verify column contract $table.$column"];
    }
    if (n45NormalizeFingerprintType($observed['type']) !== n45NormalizeFingerprintType($expected['type'])) {
        $failures[] = "$id has the wrong type for $table.$column";
    }
    if ($observed['nullable'] !== $expected['nullable']) {
        $failures[] = "$id has the wrong nullability for $table.$column";
    }
    if (n45NormalizeFingerprintDefault($observed['default'], true) !== n45NormalizeFingerprintDefault($expected['default'])) {
        $failures[] = "$id has the wrong default for $table.$column";
    }
    if (n45NormalizeFingerprintExtra($observed['extra']) !== n45NormalizeFingerprintExtra($expected['extra'])) {
        $failures[] = "$id has the wrong extra attributes for $table.$column";
    }
    return $failures;
}

function n45CompareIndexFingerprint(string $id, string $table, string $index, array $expected, ?array $observed): array
{
    if ($observed === null) {
        return ["$id is missing index $table.$index"];
    }
    if (!is_bool($observed['unique'] ?? null)
        || !is_array($observed['columns'] ?? null)
        || !is_array($observed['prefix_lengths'] ?? null)) {
        return ["$id could not verify index contract $table.$index"];
    }

    $failures = [];
    if ($observed['unique'] !== $expected['unique']) {
        $failures[] = "$id has the wrong uniqueness for $table.$index";
    }
    if ($observed['columns'] !== $expected['columns']) {
        $failures[] = "$id has the wrong indexed-column order for $table.$index";
    }
    if (array_filter($observed['prefix_lengths'], static fn ($length): bool => $length !== null)) {
        $failures[] = "$id has an unexpected prefix length for $table.$index";
    }
    return $failures;
}

function n45ValidateMigrationFingerprint($mysqli, string $id, array $definition, string $fingerprint_name = 'fingerprint'): array
{
    if (!in_array($fingerprint_name, ['fingerprint', 'runner_fingerprint', 'legacy_bridge_fingerprint'], true)) {
        return ["$id requested an unknown fingerprint contract"];
    }
    $fingerprint = $definition[$fingerprint_name] ?? null;
    $failures = n45ValidateFingerprintDefinition($id, $definition);
    if ($failures || !is_array($fingerprint)) {
        if (!is_array($fingerprint)) {
            $failures[] = "$id is missing its $fingerprint_name contract";
        }
        return $failures;
    }

    foreach (($fingerprint['tables'] ?? []) as $table) {
        $table = n45MigrationSafeIdentifier((string) $table);
        $table_sql = mysqli_real_escape_string($mysqli, $table);
        $count = intval(n45MigrationQueryScalar($mysqli, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table_sql'"));
        if ($count !== 1) {
            $failures[] = "$id is missing table $table";
        }
    }

    foreach (($fingerprint['columns'] ?? []) as $table => $columns) {
        $table = n45MigrationSafeIdentifier((string) $table);
        foreach (n45FingerprintColumnEntries($columns) as $entry) {
            $column = n45MigrationSafeIdentifier($entry['name']);
            $table_sql = mysqli_real_escape_string($mysqli, $table);
            $column_sql = mysqli_real_escape_string($mysqli, $column);
            $rows = n45MigrationQueryRows($mysqli, "SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$table_sql' AND column_name = '$column_sql'");
            if (count($rows) !== 1) {
                $failures[] = "$id is missing column $table.$column";
            } elseif (is_array($entry['contract'])) {
                $metadata_keys = ['column_type', 'is_nullable', 'column_default', 'extra'];
                $nullable_value = strtoupper((string) ($rows[0]['is_nullable'] ?? ''));
                $observed = array_diff($metadata_keys, array_keys($rows[0])) || !in_array($nullable_value, ['YES', 'NO'], true)
                    ? null
                    : [
                        'type' => $rows[0]['column_type'],
                        'nullable' => $nullable_value === 'YES',
                        'default' => $rows[0]['column_default'],
                        'extra' => $rows[0]['extra'],
                    ];
                $failures = array_merge($failures, n45CompareColumnFingerprint($id, $table, $column, $entry['contract'], $observed));
            }
        }
    }

    foreach (($fingerprint['indexes'] ?? []) as $table => $indexes) {
        $table = n45MigrationSafeIdentifier((string) $table);
        foreach (n45FingerprintIndexEntries($indexes) as $entry) {
            $index = n45MigrationSafeIdentifier($entry['name']);
            $table_sql = mysqli_real_escape_string($mysqli, $table);
            $index_sql = mysqli_real_escape_string($mysqli, $index);
            $rows = n45MigrationQueryRows($mysqli, "SELECT NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS sequence_number, COLUMN_NAME AS column_name, SUB_PART AS prefix_length FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '$table_sql' AND index_name = '$index_sql' ORDER BY SEQ_IN_INDEX");
            if (!$rows) {
                $failures[] = "$id is missing index $table.$index";
            } elseif (is_array($entry['contract'])) {
                $observed_columns = [];
                $prefix_lengths = [];
                $sequence_valid = true;
                $unique = intval($rows[0]['non_unique'] ?? -1) === 0;
                foreach ($rows as $position => $row) {
                    $metadata_keys = ['non_unique', 'sequence_number', 'column_name', 'prefix_length'];
                    if (array_diff($metadata_keys, array_keys($row))
                        || !in_array((string) ($row['non_unique'] ?? ''), ['0', '1'], true)
                        || intval($row['sequence_number'] ?? 0) !== $position + 1
                        || intval($row['non_unique'] ?? -1) !== intval($rows[0]['non_unique'] ?? -1)
                        || !is_string($row['column_name'] ?? null)) {
                        $sequence_valid = false;
                    }
                    $observed_columns[] = (string) ($row['column_name'] ?? '');
                    $prefix_length = $row['prefix_length'] ?? null;
                    $prefix_lengths[] = $prefix_length === null ? null : intval($prefix_length);
                }
                $observed = $sequence_valid ? [
                    'unique' => $unique,
                    'columns' => $observed_columns,
                    'prefix_lengths' => $prefix_lengths,
                ] : null;
                $failures = array_merge($failures, n45CompareIndexFingerprint($id, $table, $index, $entry['contract'], $observed));
            }
        }
    }

    foreach (($fingerprint['failure_queries'] ?? []) as $failure_query) {
        $failure_count = intval(n45MigrationQueryScalar($mysqli, $failure_query));
        if ($failure_count !== 0) {
            $failures[] = "$id has $failure_count row(s) that do not match its data fingerprint";
        }
    }

    return $failures;
}

function n45ValidateMigrationRunnerFingerprint($mysqli, string $id, array $definition): array
{
    $failures = [];
    foreach (n45MigrationRunnerFingerprintNames($definition) as $fingerprint_name) {
        $failures = n45ValidateMigrationFingerprint($mysqli, $id, $definition, $fingerprint_name);
        if (!$failures) {
            return [];
        }
    }
    return $failures;
}

function n45MigrationLedgerExists($mysqli): bool
{
    return intval(n45MigrationQueryScalar($mysqli, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'n45_schema_migrations'")) === 1;
}

function n45EnsureMigrationLedger($mysqli): void
{
    if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `n45_schema_migrations` (
        `migration_id` varchar(100) NOT NULL,
        `migration_checksum` char(64) NOT NULL,
        `migration_legacy_version` varchar(20) DEFAULT NULL,
        `migration_applied_by` varchar(20) NOT NULL,
        `migration_applied_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`migration_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
        throw new RuntimeException('Could not create the N45 migration ledger: ' . mysqli_error($mysqli));
    }
}

function n45ReadDatabaseMarker($mysqli): string
{
    $result = mysqli_query($mysqli, 'SELECT config_current_database_version FROM settings WHERE company_id = 1 LIMIT 1');
    $row = $result ? mysqli_fetch_assoc($result) : false;
    $marker = (string) ($row['config_current_database_version'] ?? '');
    if (!preg_match('/^\d+(\.\d+)+$/', $marker)) {
        throw new RuntimeException('The durable upstream database version marker is missing or invalid');
    }
    return $marker;
}

function n45ReadMigrationLedger($mysqli): array
{
    if (!n45MigrationLedgerExists($mysqli)) {
        return [];
    }

    $result = mysqli_query($mysqli, 'SELECT migration_id, migration_checksum, migration_legacy_version, migration_applied_by, migration_applied_at FROM n45_schema_migrations ORDER BY migration_id');
    if (!$result) {
        throw new RuntimeException('Could not read the N45 migration ledger: ' . mysqli_error($mysqli));
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[(string) $row['migration_id']] = $row;
    }
    return $rows;
}

function n45LegacyBridgeRequired(string $marker, array $definitions, array $ledger): bool
{
    if (!in_array($marker, array_column($definitions, 'legacy_version'), true)) {
        return false;
    }
    // Any normal/fresh/completed row proves this database already adopted the
    // split namespace. This prevents a future upstream release that reuses an
    // old fork version number from being mistaken for a legacy installation.
    foreach ($ledger as $row) {
        if (in_array((string) ($row['migration_applied_by'] ?? ''), ['runner', 'fresh-install', 'bridge-complete'], true)) {
            return false;
        }
    }
    foreach ($definitions as $id => $definition) {
        $legacy_version = $definition['legacy_version'] ?? null;
        if (!is_string($legacy_version) || $legacy_version === '' || version_compare($legacy_version, $marker, '>')) {
            continue;
        }
        if (!isset($ledger[$id]) || ($ledger[$id]['migration_applied_by'] ?? '') === 'legacy-bridge') {
            return true;
        }
    }
    return false;
}

function n45AssertMigrationStatusRunnable(array $status): void
{
    if (!empty($status['errors'])) {
        throw new RuntimeException(implode('; ', $status['errors']));
    }
    if (($status['state'] ?? '') === 'bridge_required') {
        throw new RuntimeException('Legacy N45 database marker detected; run the explicit N45 migration bridge first');
    }
}

function n45MigrationStatus($mysqli): array
{
    $definitions = n45MigrationDefinitions();
    $errors = [];
    $checksums = [];
    foreach ($definitions as $id => $definition) {
        try {
            $checksums[$id] = n45MigrationChecksum($id, $definition);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $marker = n45ReadDatabaseMarker($mysqli);
    $ledger_exists = n45MigrationLedgerExists($mysqli);
    $ledger = $ledger_exists ? n45ReadMigrationLedger($mysqli) : [];
    foreach ($ledger as $id => $row) {
        if (!isset($definitions[$id])) {
            $errors[] = "The N45 ledger contains unknown migration $id";
            continue;
        }
        if (!isset($checksums[$id]) || !hash_equals($checksums[$id], (string) ($row['migration_checksum'] ?? ''))) {
            $errors[] = "The checksum for applied N45 migration $id does not match";
        }
        if ((string) ($row['migration_legacy_version'] ?? '') !== (string) ($definitions[$id]['legacy_version'] ?? '')) {
            $errors[] = "The legacy version for applied N45 migration $id does not match";
        }
        if (!in_array((string) ($row['migration_applied_by'] ?? ''), ['runner', 'legacy-bridge', 'bridge-complete', 'fresh-install'], true)) {
            $errors[] = "The ledger source for applied N45 migration $id is invalid";
        }
    }

    $applied = [];
    $pending = [];
    $seen_pending = false;
    foreach (array_keys($definitions) as $id) {
        if (isset($ledger[$id])) {
            $applied[] = $id;
            if ($seen_pending) {
                $errors[] = "The N45 ledger is non-contiguous at migration $id";
            }
        } else {
            $pending[] = $id;
            $seen_pending = true;
        }
    }

    $base = (string) (n45ForkManifest()['maintenance']['upstream_marker_base'] ?? '');
    $bridge_required = n45LegacyBridgeRequired($marker, $definitions, $ledger);
    $upstream_pending = $base !== '' && version_compare($marker, $base, '<');
    $state = $errors ? 'integrity_error' : ($bridge_required ? 'bridge_required' : ($upstream_pending ? 'upstream_pending' : ($pending ? 'pending' : 'current')));

    return [
        'state' => $state,
        'upstream_marker' => $marker,
        'upstream_marker_base' => $base,
        'ledger_exists' => $ledger_exists,
        'applied' => $applied,
        'pending' => $pending,
        'errors' => $errors,
    ];
}

function n45WithMigrationLock($mysqli, callable $callback)
{
    $lock_name = (string) (n45ForkManifest()['maintenance']['migration_lock'] ?? 'itflow-database-updates');
    $lock_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock_result = mysqli_query($mysqli, "SELECT GET_LOCK('$lock_sql', 0)");
    $lock_row = $lock_result ? mysqli_fetch_row($lock_result) : false;
    if (!$lock_row || intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another database update is already running; retry after it finishes');
    }

    $caught = null;
    $result = null;
    try {
        $result = $callback();
    } catch (Throwable $e) {
        $caught = $e;
    }

    $release_result = mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_sql')");
    $release_row = $release_result ? mysqli_fetch_row($release_result) : false;
    if ((!$release_row || intval($release_row[0] ?? 0) !== 1) && $caught === null) {
        $caught = new RuntimeException('The database update lock release could not be confirmed');
    }
    if ($caught !== null) {
        throw $caught;
    }
    return $result;
}

function n45RecordMigration($mysqli, string $id, array $definition, string $applied_by): void
{
    if (!preg_match('/^(runner|legacy-bridge|fresh-install)$/', $applied_by)) {
        throw new InvalidArgumentException('Invalid N45 migration ledger source');
    }
    $id_sql = mysqli_real_escape_string($mysqli, $id);
    $checksum_sql = mysqli_real_escape_string($mysqli, n45MigrationChecksum($id, $definition));
    $legacy_version = $definition['legacy_version'] ?? null;
    $legacy_value_sql = 'NULL';
    if (is_string($legacy_version) && $legacy_version !== '') {
        $legacy_sql = mysqli_real_escape_string($mysqli, $legacy_version);
        $legacy_value_sql = "'$legacy_sql'";
    }
    $applied_by_sql = mysqli_real_escape_string($mysqli, $applied_by);
    if (!mysqli_query($mysqli, "INSERT INTO n45_schema_migrations (migration_id, migration_checksum, migration_legacy_version, migration_applied_by) VALUES ('$id_sql', '$checksum_sql', $legacy_value_sql, '$applied_by_sql')")) {
        throw new RuntimeException("Could not record N45 migration $id: " . mysqli_error($mysqli));
    }
}

/*
 * Record the no-op namespace foundation before an upstream runner can advance
 * into a version number formerly used by the fork. This is an explicit update
 * preparation step, never part of read-only status detection.
 */
function n45PrepareMigrationNamespace($mysqli): array
{
    return n45WithMigrationLock($mysqli, function () use ($mysqli): array {
        $status = n45MigrationStatus($mysqli);
        n45AssertMigrationStatusRunnable($status);

        $foundation_id = n45NamespaceFoundationMigrationId();
        if (!in_array($foundation_id, $status['pending'], true)) {
            return [];
        }

        n45EnsureMigrationLedger($mysqli);
        $definitions = n45MigrationDefinitions();
        $definition = $definitions[$foundation_id] ?? null;
        if (!is_array($definition)) {
            throw new RuntimeException('The N45 namespace foundation migration is missing');
        }

        if (!defined('FROM_N45_DB_UPDATER')) {
            define('FROM_N45_DB_UPDATER', true);
        }
        require n45MigrationFile($foundation_id, $definition);
        $fingerprint_failures = n45ValidateMigrationRunnerFingerprint($mysqli, $foundation_id, $definition);
        if ($fingerprint_failures) {
            throw new RuntimeException(implode('; ', $fingerprint_failures));
        }
        n45RecordMigration($mysqli, $foundation_id, $definition, 'runner');
        return [$foundation_id];
    });
}

function n45RunMigrations($mysqli): array
{
    return n45WithMigrationLock($mysqli, function () use ($mysqli): array {
        n45EnsureMigrationLedger($mysqli);
        $status = n45MigrationStatus($mysqli);
        n45AssertMigrationStatusRunnable($status);
        if ($status['state'] === 'upstream_pending') {
            throw new RuntimeException('Apply the upstream database migrations before the N45 migration stream');
        }

        if (!defined('FROM_N45_DB_UPDATER')) {
            define('FROM_N45_DB_UPDATER', true);
        }
        $definitions = n45MigrationDefinitions();
        $applied = [];
        foreach ($status['pending'] as $id) {
            $definition = $definitions[$id];
            require n45MigrationFile($id, $definition);
            $fingerprint_failures = n45ValidateMigrationRunnerFingerprint($mysqli, $id, $definition);
            if ($fingerprint_failures) {
                throw new RuntimeException(implode('; ', $fingerprint_failures));
            }
            n45RecordMigration($mysqli, $id, $definition, 'runner');
            $applied[] = $id;
        }
        return $applied;
    });
}

function n45BridgeLegacyMigrations($mysqli): array
{
    return n45WithMigrationLock($mysqli, function () use ($mysqli): array {
        $before = n45MigrationStatus($mysqli);
        if ($before['errors']) {
            throw new RuntimeException(implode('; ', $before['errors']));
        }
        if ($before['state'] !== 'bridge_required') {
            throw new RuntimeException('The database marker does not require the legacy N45 bridge');
        }

        n45EnsureMigrationLedger($mysqli);
        $definitions = n45MigrationDefinitions();
        $ledger = n45ReadMigrationLedger($mysqli);
        $legacy_marker = (string) $before['upstream_marker'];
        $bridged = [];
        $bridge_targets = [];
        foreach ($definitions as $id => $definition) {
            $legacy_version = $definition['legacy_version'] ?? null;
            $is_foundation = $id === n45NamespaceFoundationMigrationId();
            if (!$is_foundation && (!is_string($legacy_version) || $legacy_version === '' || version_compare($legacy_version, $legacy_marker, '>'))) {
                continue;
            }
            $bridge_fingerprint = n45MigrationBridgeFingerprintName($definition, $legacy_marker);
            $fingerprint_failures = n45ValidateMigrationFingerprint($mysqli, $id, $definition, $bridge_fingerprint);
            if ($fingerprint_failures) {
                throw new RuntimeException('Legacy bridge refused: ' . implode('; ', $fingerprint_failures));
            }
            $bridge_targets[$id] = $definition;
        }

        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not start the legacy N45 bridge transaction');
        }
        try {
            foreach ($bridge_targets as $id => $definition) {
                if (!isset($ledger[$id])) {
                    n45RecordMigration($mysqli, $id, $definition, 'legacy-bridge');
                    $bridged[] = $id;
                }
            }

            $base = (string) $before['upstream_marker_base'];
            $legacy_marker_sql = mysqli_real_escape_string($mysqli, $legacy_marker);
            $base_sql = mysqli_real_escape_string($mysqli, $base);
            if (!mysqli_query($mysqli, "UPDATE settings SET config_current_database_version = '$base_sql' WHERE company_id = 1 AND config_current_database_version = '$legacy_marker_sql'")) {
                throw new RuntimeException('Could not restore the upstream database marker base: ' . mysqli_error($mysqli));
            }
            if (n45ReadDatabaseMarker($mysqli) !== $base) {
                throw new RuntimeException('The upstream database marker changed while the legacy bridge was running');
            }

            $target_ids_sql = implode(', ', array_map(static function (string $id) use ($mysqli): string {
                return "'" . mysqli_real_escape_string($mysqli, $id) . "'";
            }, array_keys($bridge_targets)));
            if ($target_ids_sql === '' || !mysqli_query($mysqli, "UPDATE n45_schema_migrations SET migration_applied_by = 'bridge-complete' WHERE migration_applied_by = 'legacy-bridge' AND migration_id IN ($target_ids_sql)")) {
                throw new RuntimeException('Could not finalize the legacy N45 bridge ledger: ' . mysqli_error($mysqli));
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the legacy N45 bridge');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
        return $bridged;
    });
}

function n45SeedFreshInstallMigrations($mysqli): void
{
    n45EnsureMigrationLedger($mysqli);
    foreach (n45MigrationDefinitions() as $id => $definition) {
        $fingerprint_failures = n45ValidateMigrationFingerprint($mysqli, $id, $definition);
        if ($fingerprint_failures) {
            throw new RuntimeException('Fresh-install N45 schema is incomplete: ' . implode('; ', $fingerprint_failures));
        }
        n45RecordMigration($mysqli, $id, $definition, 'fresh-install');
    }
}
