<?php

$failures = [];
$root = dirname(__DIR__);

$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};

$assertFails = static function (array $result, string $message) use (&$failures): void {
    if (!$result) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (RuntimeException $e) {
        // Expected: invalid migration metadata must fail before database work.
    }
};

require_once $root . '/n45/bootstrap.php';
n45RequireModule('schema');

// Keep the established release prefix independent of the manifest, then allow
// later contiguous IDs to be discovered dynamically by the release harness.
$required_migration_prefix = [
    'n45-0000-namespace-foundation.php',
    'n45-0001-entra-agent-sso.php',
    'n45-0002-level-integration.php',
    'n45-0003-automation-integration.php',
    'n45-0004-mail-template-metadata.php',
    'n45-0005-portal-access-scopes.php',
    'n45-0006-operations-ticket-delete-integrity.php',
    'n45-0007-level-interface-links.php',
    'n45-0008-external-identity-lifecycle.php',
    'n45-0009-automation-event-lifecycle.php',
    'n45-0010-versioned-runbooks.php',
    'n45-0011-documentation-readiness.php',
    'n45-0012-unified-endpoint-network.php',
    'n45-0013-portal-request-catalog.php',
    'n45-0014-agreement-entitlements.php',
    'n45-0015-documentation-evidence-reference-index.php',
    'n45-0016-release-safety-hardening.php',
    'n45-0017-automation-action-outbox.php',
    'n45-0018-portal-business-review-access.php',
    'n45-0019-ticket-approval-gates.php',
    'n45-0020-specific-client-approvers.php',
];
$disk_migration_files = array_map('basename', glob($root . '/n45/migrations/*.php') ?: []);
sort($disk_migration_files);
$assertTrue(array_slice($disk_migration_files, 0, count($required_migration_prefix)) === $required_migration_prefix, 'The established migration inventory is incomplete on disk');
$assertTrue(in_array('n45-0013-portal-request-catalog.php', $disk_migration_files, true), 'The released portal request migration is missing on disk');
$released_migration_files = $disk_migration_files;

foreach ($released_migration_files as $position => $migration_file) {
    $matches = [];
    $assertTrue(preg_match('/^n45-(\d{4})-[a-z0-9-]+\.php$/', $migration_file, $matches) === 1, "$migration_file is not a stable migration filename");
    if ($matches) {
        $assertTrue(intval($matches[1]) === $position, "$migration_file leaves a gap in the ordered migration stream");
    }
}

$definitions = n45MigrationDefinitions();
$manifest_migration_files = array_map(
    static fn (array $definition): string => basename((string) ($definition['file'] ?? '')),
    array_values($definitions)
);
sort($manifest_migration_files);
$assertTrue($manifest_migration_files === $released_migration_files, 'The released migration inventory is incomplete in the manifest');

$reservations = n45MigrationNamespaceReservations();
$assertTrue(array_keys($reservations) === ['2.7.8', '2.7.9', '2.8.0', '2.8.1'], 'The final integration reservations are missing or out of order');
$post_integration_reservations = n45PostIntegrationMigrationReservations();
$assertTrue(
    array_keys($post_integration_reservations) === [
        'n45-0015-documentation-evidence-reference-index',
        'n45-0016-release-safety-hardening',
        'n45-0017-automation-action-outbox',
        'n45-0018-portal-business-review-access',
        'n45-0019-ticket-approval-gates',
        'n45-0020-specific-client-approvers',
    ],
    'The post-integration migration reservations are missing'
);
$repair_index = $post_integration_reservations['n45-0015-documentation-evidence-reference-index']['altered_indexes']['documentation_evidence_locker']['documentation_evidence_reference'] ?? [];
$assertTrue(($repair_index['unique'] ?? null) === false, 'The compatibility repair would restore the obsolete unique evidence index');
$assertTrue(
    $reservations['2.7.9']['created_tables'] === [
        'asset_endpoint_states',
        'asset_network_observations',
        'asset_change_events',
        'automation_mapping_decisions',
    ],
    'The endpoint reservation does not cover its complete migration inventory'
);
$expected_endpoint_snapshot_index = [
    'unique' => true,
    'columns' => [
        'automation_snapshot_source',
        'automation_snapshot_entity_type',
        'automation_snapshot_external_id',
        'automation_snapshot_client_id',
        'automation_snapshot_asset_id',
        'automation_snapshot_payload_hash',
    ],
];
$assertTrue(
    ($reservations['2.7.9']['altered_indexes']['automation_entity_snapshots']['automation_snapshot_source_entity_hash'] ?? [])
        === $expected_endpoint_snapshot_index,
    'The endpoint reservation does not cover binding-safe snapshot replay uniqueness'
);
$assertTrue(
    $reservations['2.8.1']['altered_columns']['tickets'] === [
        'ticket_request_type_key',
        'ticket_sla_response_minutes_snapshot',
        'ticket_sla_resolution_minutes_snapshot',
        'ticket_sla_calendar_mode',
        'ticket_sla_business_days',
        'ticket_sla_business_hours_start',
        'ticket_sla_business_hours_end',
        'ticket_sla_timezone',
        'ticket_response_due_at_utc',
        'ticket_resolution_due_at_utc',
    ],
    'The agreement reservation does not cover every altered ticket column'
);
$syntheticFingerprintForReservation = static function (array $reservation): array {
    $column_contract = ['type' => 'int(11)', 'nullable' => false, 'default' => 0, 'extra' => ''];
    $index_contract = ['unique' => true, 'columns' => ['placeholder_id']];
    $fingerprint = ['tables' => $reservation['created_tables'], 'columns' => [], 'indexes' => []];
    foreach ($reservation['created_tables'] as $table) {
        $fingerprint['columns'][$table] = ['placeholder_id' => $column_contract];
        $fingerprint['indexes'][$table] = ['PRIMARY' => $index_contract];
    }
    foreach ($reservation['altered_columns'] as $table => $columns) {
        $fingerprint['columns'][$table] = array_fill_keys($columns, $column_contract);
    }
    foreach ($reservation['altered_indexes'] as $table => $indexes) {
        $fingerprint['indexes'][$table] = array_merge($fingerprint['indexes'][$table] ?? [], $indexes);
    }
    foreach ($reservation['legacy_bridge_index_overrides'] as $table => $indexes) {
        foreach ($indexes as $index => $contracts) {
            $fingerprint['indexes'][$table][$index] = $contracts['final'];
        }
    }
    return $fingerprint;
};
$documentation_reservation = $reservations['2.7.8'];
$documentation_fingerprint = $syntheticFingerprintForReservation($documentation_reservation);
$documentation_legacy_fingerprint = $documentation_fingerprint;
foreach ($documentation_reservation['legacy_bridge_index_overrides'] as $table => $indexes) {
    foreach ($indexes as $index => $contracts) {
        $documentation_legacy_fingerprint['indexes'][$table][$index] = $contracts['legacy'];
    }
}
$synthetic_documentation = $definitions;
$synthetic_documentation[$documentation_reservation['id']] = [
    'legacy_version' => '2.7.8',
    'module' => $documentation_reservation['module'],
    'data_change' => $documentation_reservation['data_change'],
    'rollback' => $documentation_reservation['rollback'],
    'fingerprint' => $documentation_fingerprint,
    'legacy_bridge_fingerprint' => $documentation_legacy_fingerprint,
];
n45AssertMigrationNamespaceReservations($synthetic_documentation);
$assertTrue(
    n45ValidateFingerprintDefinition($documentation_reservation['id'], $synthetic_documentation[$documentation_reservation['id']]) === [],
    'The exact legacy documentation fingerprint is not valid metadata'
);
$evidence_override = $documentation_reservation['legacy_bridge_index_overrides']['documentation_evidence_locker']['documentation_evidence_reference'];
$assertTrue($evidence_override['legacy']['unique'] === true, 'The legacy documentation evidence index is no longer recognized as unique');
$assertTrue($evidence_override['final']['unique'] === false, 'The final documentation evidence index no longer permits repeated verification');
$assertTrue($evidence_override['legacy']['columns'] === $evidence_override['final']['columns'], 'The legacy bridge exception changed more than evidence-index uniqueness');
$wrong_documentation_marker = $synthetic_documentation;
$wrong_documentation_marker[$documentation_reservation['id']]['legacy_version'] = null;
$assertThrows(
    static function () use ($wrong_documentation_marker): void {
        n45AssertMigrationNamespaceReservations($wrong_documentation_marker);
    },
    'A consumed migration reservation accepted a missing legacy bridge marker'
);
$out_of_order_reservation = $definitions;
$endpoint_reservation = $reservations['2.7.9'];
$out_of_order_reservation[$endpoint_reservation['id']] = [
    'legacy_version' => '2.7.9',
    'module' => $endpoint_reservation['module'],
    'data_change' => $endpoint_reservation['data_change'],
    'rollback' => $endpoint_reservation['rollback'],
];
$assertThrows(
    static function () use ($out_of_order_reservation): void {
        n45AssertMigrationNamespaceReservations($out_of_order_reservation);
    },
    'An integration migration reservation was consumed out of order'
);
$skipped_reservation = $definitions;
unset(
    $skipped_reservation['n45-0014-agreement-entitlements'],
    $skipped_reservation['n45-0015-documentation-evidence-reference-index'],
    $skipped_reservation['n45-0016-release-safety-hardening'],
    $skipped_reservation['n45-0017-automation-action-outbox'],
    $skipped_reservation['n45-0018-portal-business-review-access'],
    $skipped_reservation['n45-0019-ticket-approval-gates'],
    $skipped_reservation['n45-0020-specific-client-approvers']
);
$skipped_reservation['n45-0021-future-feature'] = ['legacy_version' => null];
$assertThrows(
    static function () use ($skipped_reservation): void {
        n45AssertMigrationNamespaceReservations($skipped_reservation);
    },
    'A later stable migration skipped the reserved integration sequence'
);

foreach ($definitions as $id => $definition) {
    $assertTrue(n45ValidateFingerprintDefinition($id, $definition) === [], "$id has invalid fingerprint metadata");
}

$endpoint_definition = $definitions['n45-0012-unified-endpoint-network'] ?? [];
$external_identity_definition = $definitions['n45-0008-external-identity-lifecycle'] ?? [];
$historical_endpoint_snapshot_index = [
    'unique' => true,
    'columns' => [
        'automation_snapshot_source',
        'automation_snapshot_entity_type',
        'automation_snapshot_external_id',
        'automation_snapshot_payload_hash',
    ],
];
$assertTrue(
    ($external_identity_definition['fingerprint']['indexes']['automation_entity_snapshots']['automation_snapshot_source_entity_hash'] ?? [])
        === $expected_endpoint_snapshot_index,
    'The external-identity final fingerprint does not reflect binding-safe replay uniqueness'
);
$assertTrue(
    ($external_identity_definition['runner_fingerprint']['indexes']['automation_entity_snapshots']['automation_snapshot_source_entity_hash'] ?? [])
        === $historical_endpoint_snapshot_index,
    'The external-identity runner no longer validates its immediate released snapshot uniqueness shape'
);
$assertTrue(
    ($external_identity_definition['legacy_bridge_fingerprint']['indexes']['automation_entity_snapshots']['automation_snapshot_source_entity_hash'] ?? [])
        === $historical_endpoint_snapshot_index,
    'The external-identity legacy bridge no longer accepts its released snapshot uniqueness shape'
);
$assertTrue(
    n45MigrationRunnerFingerprintNames($external_identity_definition) === ['fingerprint', 'runner_fingerprint'],
    'The external-identity migration runner does not permit only its final and immediate index contracts'
);
$assertTrue(
    n45MigrationRunnerFingerprintNames($endpoint_definition) === ['fingerprint'],
    'The endpoint migration runner does not validate its final post-migration contract'
);
$assertTrue(
    n45MigrationBridgeFingerprintName($external_identity_definition, '2.7.8') === 'legacy_bridge_fingerprint',
    'Pre-endpoint legacy markers no longer validate the released external-identity index'
);
$assertTrue(
    n45MigrationBridgeFingerprintName($external_identity_definition, '2.7.9') === 'fingerprint',
    'Endpoint-era legacy markers still validate the superseded external-identity index'
);
$assertTrue(
    [
        'legacy_version' => $endpoint_definition['legacy_version'] ?? null,
        'module' => $endpoint_definition['module'] ?? null,
        'data_change' => $endpoint_definition['data_change'] ?? null,
        'rollback' => $endpoint_definition['rollback'] ?? null,
    ] === [
        'legacy_version' => '2.7.9',
        'module' => 'endpoint',
        'data_change' => false,
        'rollback' => 'Disable endpoint ingestion and restore the pre-upgrade database snapshot; reconciled posture and audit history are not down-migrated.',
    ],
    'The endpoint migration does not preserve its exact namespace reservation metadata'
);

// Every released bridge schema contract is detailed. Later reserved migrations
// must extend this independent inventory when their feature branches integrate.
foreach ($definitions as $id => $definition) {
    foreach (($definition['fingerprint']['columns'] ?? []) as $table => $columns) {
        foreach ($columns as $column => $contract) {
            $assertTrue(is_string($column) && is_array($contract), "$id has a name-only column contract for $table");
        }
    }
    foreach (($definition['fingerprint']['indexes'] ?? []) as $table => $indexes) {
        foreach ($indexes as $index => $contract) {
            $assertTrue(is_string($index) && is_array($contract), "$id has a name-only index contract for $table");
        }
    }
}

$runbook_fingerprint = $definitions['n45-0010-versioned-runbooks']['fingerprint'] ?? [];
$expected_runbook_column_tables = [
    'ticket_templates',
    'project_template_ticket_templates',
    'task_templates',
    'tasks',
    'task_approvals',
    'task_approval_events',
    'task_template_dependencies',
    'runbook_versions',
    'runbook_version_tasks',
    'runbook_version_task_dependencies',
    'runbook_executions',
    'task_dependencies',
    'task_evidence',
    'task_state_events',
];
$assertTrue(array_keys($runbook_fingerprint['columns'] ?? []) === $expected_runbook_column_tables, 'The runbook migration does not fingerprint every altered or created table');
$assertTrue(array_keys($runbook_fingerprint['indexes'] ?? []) === $expected_runbook_column_tables, 'The runbook migration does not fingerprint every altered or created table index');
$assertTrue(count($runbook_fingerprint['failure_queries'] ?? []) === 5, 'The runbook migration does not verify its security and audit backfills');

// Compare every detailed contract to the fresh-install schema. The manifest
// and db.sql are independent release artifacts and must describe one end state.
$baseline_schema = @file_get_contents($root . '/db.sql');
$assertTrue(is_string($baseline_schema), 'Could not read db.sql for exact fingerprint validation');
$baseline_schema = is_string($baseline_schema) ? $baseline_schema : '';

$baselineTableBody = static function (string $table) use ($baseline_schema): ?string {
    $table_pattern = preg_quote($table, '/');
    if (!preg_match('/CREATE TABLE `' . $table_pattern . '` \(\R(.*?)\R\) ENGINE=/s', $baseline_schema, $matches)) {
        return null;
    }
    return $matches[1];
};

$baselineColumns = static function (string $table) use ($baselineTableBody): array {
    $body = $baselineTableBody($table);
    if ($body === null) {
        return [];
    }

    $columns = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (!preg_match('/^\s*`([^`]+)`\s+([a-z]+(?:\(\d+(?:,\d+)?\))?(?:\s+unsigned)?)(.*?)(?:,)?$/i', $line, $matches)) {
            continue;
        }

        $tail = $matches[3];
        $default = null;
        if (preg_match("/\\bDEFAULT\\s+(current_timestamp\\(\\)|NULL|'(?:[^']|'')*'|[^\\s,]+)/i", $tail, $default_match)
            && strtoupper($default_match[1]) !== 'NULL') {
            $default = $default_match[1];
            if (str_starts_with($default, "'") && str_ends_with($default, "'")) {
                $default = str_replace("''", "'", substr($default, 1, -1));
            }
        }

        $extra = '';
        if (stripos($tail, 'AUTO_INCREMENT') !== false) {
            $extra = 'auto_increment';
        } elseif (preg_match('/ON UPDATE\s+current_timestamp\(\)/i', $tail)) {
            $extra = 'on update current_timestamp';
        }
        $columns[$matches[1]] = [
            'type' => $matches[2],
            'nullable' => stripos($tail, 'NOT NULL') === false,
            'default' => $default,
            'extra' => $extra,
        ];
    }
    return $columns;
};

$baselineIndexes = static function (string $table) use ($baselineTableBody): array {
    $body = $baselineTableBody($table);
    if ($body === null) {
        return [];
    }

    $indexes = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        $line = rtrim(trim($line), ',');
        if (!preg_match('/^(PRIMARY KEY|UNIQUE KEY `([^`]+)`|KEY `([^`]+)`) \((.+)\)$/', $line, $matches)) {
            continue;
        }
        preg_match_all('/`([^`]+)`(?:\((\d+)\))?/', $matches[4], $column_matches, PREG_SET_ORDER);
        $columns = [];
        $prefix_lengths = [];
        foreach ($column_matches as $column_match) {
            $columns[] = $column_match[1];
            $prefix_lengths[] = isset($column_match[2]) && $column_match[2] !== '' ? intval($column_match[2]) : null;
        }
        $index = ($matches[2] ?? '') ?: (($matches[3] ?? '') ?: 'PRIMARY');
        $indexes[$index] = [
            'unique' => $index === 'PRIMARY' || str_starts_with($matches[1], 'UNIQUE'),
            'columns' => $columns,
            'prefix_lengths' => $prefix_lengths,
        ];
    }
    return $indexes;
};

$expected_endpoint_tables = [
    'asset_endpoint_states',
    'asset_network_observations',
    'asset_change_events',
    'automation_mapping_decisions',
];
$endpoint_fingerprint = $endpoint_definition['fingerprint'] ?? [];
$assertTrue(($endpoint_fingerprint['tables'] ?? []) === $expected_endpoint_tables, 'The endpoint migration table inventory is incomplete');
$assertTrue(array_keys($endpoint_fingerprint['columns'] ?? []) === $expected_endpoint_tables, 'The endpoint migration column-table inventory is incomplete');
$assertTrue(
    array_keys($endpoint_fingerprint['indexes'] ?? []) === array_merge($expected_endpoint_tables, ['automation_entity_snapshots']),
    'The endpoint migration index-table inventory is incomplete'
);

$endpoint_column_count = 0;
$endpoint_index_count = 0;
foreach ($expected_endpoint_tables as $table) {
    $column_contracts = $endpoint_fingerprint['columns'][$table] ?? [];
    $index_contracts = $endpoint_fingerprint['indexes'][$table] ?? [];
    $baseline_columns = $baselineColumns($table);
    $baseline_indexes = $baselineIndexes($table);
    $assertTrue(array_keys($column_contracts) === array_keys($baseline_columns), "The endpoint migration has incomplete baseline column coverage for $table");
    $assertTrue(array_keys($index_contracts) === array_keys($baseline_indexes), "The endpoint migration has incomplete baseline index coverage for $table");
    $endpoint_column_count += count($column_contracts);
    $endpoint_index_count += count($index_contracts);
}
$endpoint_index_count += count($endpoint_fingerprint['indexes']['automation_entity_snapshots'] ?? []);
$assertTrue($endpoint_column_count === 84, 'The endpoint migration does not fingerprint all 84 created columns');
$assertTrue($endpoint_index_count === 21, 'The endpoint migration does not fingerprint all 21 created or altered indexes');
$assertTrue(
    ($endpoint_fingerprint['indexes']['automation_entity_snapshots']['automation_snapshot_source_entity_hash'] ?? [])
        === $expected_endpoint_snapshot_index,
    'The endpoint migration fingerprint does not preserve binding-safe snapshot replay uniqueness'
);

$expected_documentation_tables = [
    'documentation_requirements',
    'documentation_requirement_versions',
    'documentation_requirement_version_selectors',
    'client_documentation_obligations',
    'documentation_obligation_events',
    'documentation_obligation_exceptions',
    'documentation_obligation_exception_events',
    'documentation_evidence_locker',
    'documentation_change_passports',
    'documentation_change_passport_obligations',
    'documentation_promise_ledger',
    'documentation_promise_events',
    'ticket_documentation_obligations',
    'ticket_documentation_waivers',
    'ticket_documentation_waiver_events',
];
$expected_ticket_columns = [
    'ticket_configuration_change',
    'ticket_documentation_impact',
    'ticket_documentation_assessed_by',
    'ticket_documentation_assessed_at',
];
$documentation_fingerprint = $definitions['n45-0011-documentation-readiness']['fingerprint'] ?? [];
$assertTrue(($documentation_fingerprint['tables'] ?? []) === $expected_documentation_tables, 'The documentation migration table inventory is incomplete');
$assertTrue(array_keys($documentation_fingerprint['columns'] ?? []) === array_merge(['tickets'], $expected_documentation_tables), 'The documentation migration column-table inventory is incomplete');
$assertTrue(array_keys($documentation_fingerprint['indexes'] ?? []) === $expected_documentation_tables, 'The documentation migration index-table inventory is incomplete');
$assertTrue(array_keys($documentation_fingerprint['columns']['tickets'] ?? []) === $expected_ticket_columns, 'The documentation migration ticket-column inventory is incomplete');

$documentation_column_count = 0;
foreach (($documentation_fingerprint['columns'] ?? []) as $table => $contracts) {
    $baseline_columns = $baselineColumns($table);
    $expected_columns = $table === 'tickets' ? $expected_ticket_columns : array_keys($baseline_columns);
    $assertTrue(array_keys($contracts) === $expected_columns, "The documentation migration has incomplete baseline column coverage for $table");
    foreach ($contracts as $column => $contract) {
        $documentation_column_count++;
        $comparison = n45CompareColumnFingerprint('db.sql', $table, $column, $contract, $baseline_columns[$column] ?? null);
        $assertTrue($comparison === [], "The documentation column contract does not match db.sql for $table.$column: " . implode('; ', $comparison));
    }
}
$assertTrue($documentation_column_count === 206, 'The documentation migration does not fingerprint all 206 created or altered columns');

$documentation_index_count = 0;
foreach (($documentation_fingerprint['indexes'] ?? []) as $table => $contracts) {
    $baseline_indexes = $baselineIndexes($table);
    $assertTrue(array_keys($contracts) === array_keys($baseline_indexes), "The documentation migration has incomplete baseline index coverage for $table");
    foreach ($contracts as $index => $contract) {
        $documentation_index_count++;
        $comparison = n45CompareIndexFingerprint('db.sql', $table, $index, $contract, $baseline_indexes[$index] ?? null);
        $assertTrue($comparison === [], "The documentation index contract does not match db.sql for $table.$index: " . implode('; ', $comparison));
    }
}
$assertTrue($documentation_index_count === 58, 'The documentation migration does not fingerprint all 58 created indexes');

$evidence_reference_contract = $documentation_fingerprint['indexes']['documentation_evidence_locker']['documentation_evidence_reference'] ?? [];
$assertTrue(($evidence_reference_contract['unique'] ?? null) === false, 'Per-verification evidence provenance must permit repeated evidence references');
$assertTrue(($evidence_reference_contract['columns'] ?? []) === [
    'documentation_evidence_obligation_id',
    'documentation_evidence_requirement_version_id',
    'documentation_evidence_reference_type',
    'documentation_evidence_reference_id',
    'documentation_evidence_reference_hash',
], 'The evidence-reference provenance index has the wrong ordered columns');

$expected_portal_request_tables = [
    'portal_request_catalog_items',
    'portal_request_catalog_fields',
    'portal_request_catalog_versions',
    'portal_request_catalog_version_fields',
    'portal_request_submissions',
    'portal_request_dispatch_outbox',
    'portal_request_submission_events',
];
$portal_request_fingerprint = $definitions['n45-0013-portal-request-catalog']['fingerprint'] ?? [];
$assertTrue(($portal_request_fingerprint['tables'] ?? []) === $expected_portal_request_tables, 'The portal request migration table inventory is incomplete');
$assertTrue(array_keys($portal_request_fingerprint['columns'] ?? []) === $expected_portal_request_tables, 'The portal request migration column-table inventory is incomplete');
$assertTrue(array_keys($portal_request_fingerprint['indexes'] ?? []) === $expected_portal_request_tables, 'The portal request migration index-table inventory is incomplete');

$portal_request_column_count = 0;
$portal_request_index_count = 0;
foreach ($expected_portal_request_tables as $table) {
    $baseline_columns = $baselineColumns($table);
    $column_contracts = $portal_request_fingerprint['columns'][$table] ?? [];
    $assertTrue(array_keys($column_contracts) === array_keys($baseline_columns), "The portal request migration has incomplete baseline column coverage for $table");
    foreach ($column_contracts as $column => $contract) {
        $portal_request_column_count++;
        $comparison = n45CompareColumnFingerprint('db.sql', $table, $column, $contract, $baseline_columns[$column] ?? null);
        $assertTrue($comparison === [], "The portal request column contract does not match db.sql for $table.$column: " . implode('; ', $comparison));
    }

    $baseline_indexes = $baselineIndexes($table);
    $index_contracts = $portal_request_fingerprint['indexes'][$table] ?? [];
    $assertTrue(array_keys($index_contracts) === array_keys($baseline_indexes), "The portal request migration has incomplete baseline index coverage for $table");
    foreach ($index_contracts as $index => $contract) {
        $portal_request_index_count++;
        $comparison = n45CompareIndexFingerprint('db.sql', $table, $index, $contract, $baseline_indexes[$index] ?? null);
        $assertTrue($comparison === [], "The portal request index contract does not match db.sql for $table.$index: " . implode('; ', $comparison));
    }
}
$assertTrue($portal_request_column_count === 107, 'The portal request migration does not fingerprint all 107 created columns');
$assertTrue($portal_request_index_count === 29, 'The portal request migration does not fingerprint all 29 created indexes');
$assertTrue(($portal_request_fingerprint['failure_queries'] ?? []) === [
    "SELECT COUNT(*) FROM portal_request_submissions WHERE portal_request_submission_request_hash IS NULL OR BINARY portal_request_submission_request_hash NOT REGEXP '^[0-9a-f]{64}$'",
], 'The portal request migration does not verify its restart-repair fingerprint backfill');

$automation_outbox_fingerprint = $definitions['n45-0017-automation-action-outbox']['fingerprint'] ?? [];
$assertTrue(
    ($automation_outbox_fingerprint['tables'] ?? []) === ['automation_event_dispatch_outbox'],
    'The automation custom-action outbox table inventory is incomplete'
);
$automation_outbox_columns = $baselineColumns('automation_event_dispatch_outbox');
$automation_outbox_indexes = $baselineIndexes('automation_event_dispatch_outbox');
$assertTrue(
    array_keys($automation_outbox_fingerprint['columns']['automation_event_dispatch_outbox'] ?? [])
        === array_keys($automation_outbox_columns),
    'The automation custom-action outbox column fingerprint is incomplete'
);
$assertTrue(
    array_keys($automation_outbox_fingerprint['indexes']['automation_event_dispatch_outbox'] ?? [])
        === array_keys($automation_outbox_indexes),
    'The automation custom-action outbox index fingerprint is incomplete'
);
foreach (($automation_outbox_fingerprint['columns']['automation_event_dispatch_outbox'] ?? []) as $column => $contract) {
    $comparison = n45CompareColumnFingerprint(
        'db.sql', 'automation_event_dispatch_outbox', $column, $contract,
        $automation_outbox_columns[$column] ?? null
    );
    $assertTrue($comparison === [], 'The automation outbox column contract does not match db.sql for '
        . $column . ': ' . implode('; ', $comparison));
}
foreach (($automation_outbox_fingerprint['indexes']['automation_event_dispatch_outbox'] ?? []) as $index => $contract) {
    $comparison = n45CompareIndexFingerprint(
        'db.sql', 'automation_event_dispatch_outbox', $index, $contract,
        $automation_outbox_indexes[$index] ?? null
    );
    $assertTrue($comparison === [], 'The automation outbox index contract does not match db.sql for '
        . $index . ': ' . implode('; ', $comparison));
}

$portal_review_fingerprint = $definitions['n45-0018-portal-business-review-access']['fingerprint'] ?? [];
$portal_review_column = $portal_review_fingerprint['columns']['contacts']['contact_portal_review_access'] ?? [];
$comparison = n45CompareColumnFingerprint(
    'db.sql', 'contacts', 'contact_portal_review_access', $portal_review_column,
    $baselineColumns('contacts')['contact_portal_review_access'] ?? null
);
$assertTrue(
    $comparison === [],
    'The portal business-review permission contract does not match db.sql: ' . implode('; ', $comparison)
);

$expected_agreement_tables = [
    'agreement_versions',
    'agreement_entitlements',
    'agreement_sla_rules',
    'agreement_version_events',
    'ticket_agreement_decisions',
    'service_reviews',
    'service_review_events',
];
$expected_agreement_contract_columns = [
    'contract_published_version_id',
    'contract_review_cadence_months',
    'contract_next_review_at',
];
$expected_agreement_ticket_columns = [
    'ticket_request_type_key',
    'ticket_sla_response_minutes_snapshot',
    'ticket_sla_resolution_minutes_snapshot',
    'ticket_sla_calendar_mode',
    'ticket_sla_business_days',
    'ticket_sla_business_hours_start',
    'ticket_sla_business_hours_end',
    'ticket_sla_timezone',
    'ticket_response_due_at_utc',
    'ticket_resolution_due_at_utc',
];
$expected_agreement_existing_indexes = [
    'contracts' => ['contract_published_version', 'contract_review_due'],
    'tickets' => ['ticket_response_due_at_utc', 'ticket_resolution_due_at_utc'],
];
$agreement_fingerprint = $definitions['n45-0014-agreement-entitlements']['fingerprint'] ?? [];
$assertTrue(($agreement_fingerprint['tables'] ?? []) === $expected_agreement_tables, 'The agreement migration table inventory is incomplete');
$assertTrue(
    array_keys($agreement_fingerprint['columns'] ?? []) === array_merge(['contracts', 'tickets'], $expected_agreement_tables),
    'The agreement migration column-table inventory is incomplete'
);
$assertTrue(
    array_keys($agreement_fingerprint['indexes'] ?? []) === array_merge(['contracts', 'tickets'], $expected_agreement_tables),
    'The agreement migration index-table inventory is incomplete'
);
$assertTrue(
    array_keys($agreement_fingerprint['columns']['contracts'] ?? []) === $expected_agreement_contract_columns,
    'The agreement migration contract-column inventory is incomplete'
);
$assertTrue(
    array_keys($agreement_fingerprint['columns']['tickets'] ?? []) === $expected_agreement_ticket_columns,
    'The agreement migration ticket-column inventory is incomplete'
);

$agreement_column_count = 0;
foreach (($agreement_fingerprint['columns'] ?? []) as $table => $contracts) {
    $baseline_columns = $baselineColumns($table);
    if ($table === 'contracts') {
        $expected_columns = $expected_agreement_contract_columns;
    } elseif ($table === 'tickets') {
        $expected_columns = $expected_agreement_ticket_columns;
    } else {
        $expected_columns = array_keys($baseline_columns);
    }
    $assertTrue(array_keys($contracts) === $expected_columns, "The agreement migration has incomplete baseline column coverage for $table");
    foreach ($contracts as $column => $contract) {
        $agreement_column_count++;
        $comparison = n45CompareColumnFingerprint('db.sql', $table, $column, $contract, $baseline_columns[$column] ?? null);
        $assertTrue($comparison === [], "The agreement column contract does not match db.sql for $table.$column: " . implode('; ', $comparison));
    }
}
$assertTrue($agreement_column_count === 122, 'The agreement migration does not fingerprint all 122 created or altered columns');

$agreement_index_count = 0;
foreach (($agreement_fingerprint['indexes'] ?? []) as $table => $contracts) {
    $baseline_indexes = $baselineIndexes($table);
    $expected_indexes = $expected_agreement_existing_indexes[$table] ?? array_keys($baseline_indexes);
    $assertTrue(array_keys($contracts) === $expected_indexes, "The agreement migration has incomplete baseline index coverage for $table");
    foreach ($contracts as $index => $contract) {
        $agreement_index_count++;
        $comparison = n45CompareIndexFingerprint('db.sql', $table, $index, $contract, $baseline_indexes[$index] ?? null);
        $assertTrue($comparison === [], "The agreement index contract does not match db.sql for $table.$index: " . implode('; ', $comparison));
    }
}
$assertTrue($agreement_index_count === 28, 'The agreement migration does not fingerprint all 28 created or altered indexes');

foreach ($definitions as $id => $definition) {
    $fingerprint = $definition['fingerprint'];
    foreach (($fingerprint['tables'] ?? []) as $table) {
        $assertTrue($baselineTableBody($table) !== null, "$id fingerprints table $table but db.sql does not create it");
    }
    foreach (($fingerprint['columns'] ?? []) as $table => $contracts) {
        $baseline_columns = $baselineColumns($table);
        foreach ($contracts as $column => $contract) {
            if (!is_string($column) || !is_array($contract)) {
                continue;
            }
            $comparison = n45CompareColumnFingerprint($id, $table, $column, $contract, $baseline_columns[$column] ?? null);
            $assertTrue($comparison === [], "The column contract does not match db.sql for $table.$column: " . implode('; ', $comparison));
        }
    }
    foreach (($fingerprint['indexes'] ?? []) as $table => $contracts) {
        $baseline_indexes = $baselineIndexes($table);
        foreach ($contracts as $index => $contract) {
            if (!is_string($index) || !is_array($contract)) {
                continue;
            }
            $comparison = n45CompareIndexFingerprint($id, $table, $index, $contract, $baseline_indexes[$index] ?? null);
            $assertTrue($comparison === [], "The index contract does not match db.sql for $table.$index: " . implode('; ', $comparison));
        }
    }
}

$column_contract = ['type' => 'bigint(20)', 'nullable' => false, 'default' => 0, 'extra' => 'auto_increment'];
$observed_column = ['type' => 'bigint', 'nullable' => false, 'default' => '0', 'extra' => 'AUTO_INCREMENT'];
$assertTrue(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $observed_column) === [], 'Equivalent column metadata did not normalize consistently');

$nullable_contract = ['type' => 'varchar(20)', 'nullable' => true, 'default' => null, 'extra' => ''];
$mariadb_nullable_column = ['type' => 'varchar(20)', 'nullable' => true, 'default' => 'NULL', 'extra' => ''];
$assertTrue(
    n45CompareColumnFingerprint('test', 'records', 'nullable_value', $nullable_contract, $mariadb_nullable_column) === [],
    'MariaDB textual SQL NULL metadata did not normalize to a null default'
);
$literal_null_contract = ['type' => 'varchar(20)', 'nullable' => false, 'default' => 'NULL', 'extra' => ''];
$literal_null_column = ['type' => 'varchar(20)', 'nullable' => false, 'default' => "'NULL'", 'extra' => ''];
$assertTrue(
    n45CompareColumnFingerprint('test', 'records', 'literal_null', $literal_null_contract, $literal_null_column) === [],
    'A quoted literal NULL default was confused with SQL NULL metadata'
);

$wrong_type = $observed_column;
$wrong_type['type'] = 'int(11)';
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $wrong_type), 'A wrong column type passed its fingerprint');
$wrong_nullability = $observed_column;
$wrong_nullability['nullable'] = true;
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $wrong_nullability), 'Wrong column nullability passed its fingerprint');
$wrong_default = $observed_column;
$wrong_default['default'] = 1;
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $wrong_default), 'A wrong column default passed its fingerprint');
$wrong_extra = $observed_column;
$wrong_extra['extra'] = '';
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $wrong_extra), 'Missing column extra attributes passed the fingerprint');
$partial_column = $observed_column;
unset($partial_column['default']);
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, $partial_column), 'Partial observed column metadata passed the fingerprint');
$assertFails(n45CompareColumnFingerprint('test', 'records', 'record_id', $column_contract, null), 'A missing column passed the fingerprint');

$index_contract = ['unique' => true, 'columns' => ['tenant_id', 'external_id']];
$observed_index = ['unique' => true, 'columns' => ['tenant_id', 'external_id'], 'prefix_lengths' => [null, null]];
$assertTrue(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, $observed_index) === [], 'An exact index contract did not pass');
$wrong_uniqueness = $observed_index;
$wrong_uniqueness['unique'] = false;
$assertFails(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, $wrong_uniqueness), 'A non-unique index satisfied a unique fingerprint');
$wrong_order = $observed_index;
$wrong_order['columns'] = ['external_id', 'tenant_id'];
$assertFails(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, $wrong_order), 'Reversed indexed-column order passed the fingerprint');
$prefix_index = $observed_index;
$prefix_index['prefix_lengths'] = [null, 32];
$assertFails(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, $prefix_index), 'A weakened prefix index passed the fingerprint');
$partial_index = $observed_index;
unset($partial_index['columns']);
$assertFails(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, $partial_index), 'Partial observed index metadata passed the fingerprint');
$assertFails(n45CompareIndexFingerprint('test', 'records', 'record_identity', $index_contract, null), 'A missing index passed the fingerprint');

$malformed_definitions = [
    ['fingerprint' => ['unexpected' => ['records']]],
    ['fingerprint' => ['tables' => null]],
    ['fingerprint' => ['tables' => ['records', 'records']]],
    ['fingerprint' => ['columns' => ['records' => [
        'record_id' => ['type' => 'bigint(20)', 'nullable' => false, 'extra' => 'auto_increment'],
    ]]]],
    ['fingerprint' => ['columns' => ['records' => [
        'record_id' => ['type' => 'bigint(20)', 'nullable' => 'NO', 'default' => null, 'extra' => 'auto_increment'],
    ]]]],
    ['fingerprint' => ['columns' => ['records' => [
        'record_id' => ['type' => 'bigint(20', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment'],
    ]]]],
    ['fingerprint' => ['indexes' => ['records' => [
        'record_identity' => ['columns' => ['tenant_id', 'external_id']],
    ]]]],
    ['fingerprint' => ['indexes' => ['records' => [
        'record_identity' => ['unique' => true, 'columns' => ['tenant_id' => 'external_id']],
    ]]]],
    ['fingerprint' => ['failure_queries' => ['UPDATE records SET tenant_id = 0']]],
    [
        'legacy_version' => '2.7.5',
        'fingerprint' => ['tables' => ['records']],
        'legacy_bridge_fingerprint_until' => '2.7.8',
    ],
    [
        'fingerprint' => ['tables' => ['records']],
        'runner_fingerprint' => ['tables' => []],
    ],
    [
        'fingerprint' => ['tables' => ['records']],
        'runner_fingerprint' => ['tables' => ['other_records']],
    ],
    [
        'legacy_version' => '2.7.5',
        'fingerprint' => ['tables' => ['records']],
        'legacy_bridge_fingerprint' => ['tables' => ['records']],
        'legacy_bridge_fingerprint_until' => 'not-a-version',
    ],
    [
        'legacy_version' => '2.7.5',
        'fingerprint' => ['tables' => ['records']],
        'legacy_bridge_fingerprint' => ['tables' => ['records']],
        'legacy_bridge_fingerprint_until' => '2.7.4',
    ],
];
foreach ($malformed_definitions as $position => $malformed_definition) {
    $assertFails(n45ValidateFingerprintDefinition('malformed-' . $position, $malformed_definition), "Malformed fingerprint fixture $position did not fail closed");
}

// Metadata failures return before mysqli is touched, including on hosts where
// database extensions or credentials are unavailable to the static test job.
$assertFails(n45ValidateMigrationFingerprint(null, 'malformed-runtime', $malformed_definitions[2]), 'Runtime validation queried the database before rejecting malformed metadata');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "N45 schema fingerprint tests passed" . PHP_EOL;
