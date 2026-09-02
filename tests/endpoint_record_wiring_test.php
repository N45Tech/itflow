<?php

$failures = [];
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    return $content === false ? '' : $content;
};
$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};
$assertNotContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message . " (unexpected '$needle')";
    }
};
$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};
$assertOrder = function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $first_position = strpos($haystack, $first);
    $second_position = strpos($haystack, $second);
    if ($first_position === false || $second_position === false || $first_position >= $second_position) {
        $failures[] = $message;
    }
};

$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0012-unified-endpoint-network.php');
$manifest = require $root . '/n45/manifest.php';
$functions = $read('functions/endpoint.php');
$device_source = $read('functions/device_source.php');
$loader = $read('functions.php');
$level = $read('functions/level.php');
$asset = $read('agent/asset.php');
$endpoint_ui = $read('agent/includes/inc_asset_endpoint_record.php');
$operations = $read('agent/operations.php');
$api = $read('api/v1/integrations/endpoint/update.php');
$identity_api = $read('api/v1/integrations/endpoint/create.php');
$rbac = $read('api/v1/enforce_api_rbac.php');
$reconciler = $read('deploy/psa/reconcile_endpoint_records.php');
$identity = $read('functions/integration_identity.php');
$validator = $read('api/v1/validate_api_key.php');
$operations_post = $read('agent/post/operations.php');
$cron_registry = $read('includes/cron_jobs.php');
$identity_cron = $read('cron/identity_reconciliation.php');
$docs = $read('docs/unified-endpoint-network-record.md');

$tables = [
    'asset_endpoint_states' => [
        'endpoint_state_asset_id', 'endpoint_state_client_id', 'endpoint_state_source',
        'endpoint_state_external_id', 'endpoint_state_payload_hash', 'endpoint_state_observed_at',
        'endpoint_state_network_hash', 'endpoint_state_network_observed_at',
        'endpoint_state_delivery_key', 'endpoint_state_delivery_baseline',
    ],
    'asset_network_observations' => [
        'network_observation_asset_id', 'network_observation_client_id',
        'network_observation_identity_hash', 'network_observation_state_hash',
        'network_observation_active', 'network_observation_ended_at',
        'network_observation_created_delivery_key', 'network_observation_canonical',
    ],
    'asset_change_events' => [
        'asset_change_event_asset_id', 'asset_change_event_client_id',
        'asset_change_event_fingerprint', 'asset_change_event_before',
        'asset_change_event_after', 'asset_change_event_occurred_at',
        'asset_change_event_ticket_label', 'asset_change_event_document_label',
        'asset_change_event_evidence_label',
        'asset_change_event_delivery_key', 'asset_change_event_canonical',
    ],
];
foreach ($tables as $table => $columns) {
    $assertContains("CREATE TABLE `$table`", $schema, "Baseline schema does not create $table");
    $assertContains("CREATE TABLE IF NOT EXISTS `$table`", $migration, "n45-0012 migration does not create $table");
    foreach ($columns as $column) {
        $assertContains("`$column`", $schema, "Baseline $table is missing $column");
        $assertContains("`$column`", $migration, "Migration $table is missing $column");
    }
}
$assertContains('CREATE TABLE `automation_mapping_decisions`', $schema, 'Baseline schema omits the mapping decision ledger');
$assertContains('CREATE TABLE IF NOT EXISTS `automation_mapping_decisions`', $migration, 'Feature migration omits the mapping decision ledger');
$assertContains('`automation_snapshot_external_id`,`automation_snapshot_client_id`,`automation_snapshot_asset_id`,`automation_snapshot_payload_hash`', $schema, 'Identity snapshot replay uniqueness can cross a remapped tenant or asset binding');
$assertContains('Could not make identity snapshots binding-safe', $migration, 'Feature migration does not repair snapshot uniqueness across remaps');
$assertContains("defined('FROM_N45_DB_UPDATER')", $migration, 'Endpoint migration bypasses the N45 runner guard');
$assertContains('ADD COLUMN IF NOT EXISTS `endpoint_state_delivery_key`', $migration, 'Endpoint migration cannot repair an interrupted experimental posture schema');
$assertContains('ADD COLUMN IF NOT EXISTS `network_observation_canonical`', $migration, 'Endpoint migration cannot repair an interrupted experimental topology schema');
$assertContains('ADD COLUMN IF NOT EXISTS `asset_change_event_ticket_label`', $migration, 'Endpoint migration cannot repair experimental immutable timeline references');
$assertContains('COLUMN_NAME, NON_UNIQUE,', $migration, 'Endpoint migration does not inspect snapshot-index uniqueness on retry');
$assertContains('SEQ_IN_INDEX, SUB_PART', $migration, 'Endpoint migration does not inspect snapshot-index order and prefix lengths on retry');
$historical_snapshot_contract = <<<'PHP'
$historical_snapshot_columns = [
    // Released by n45-0008 before endpoint bindings became part of replay identity.
    'automation_snapshot_source',
    'automation_snapshot_entity_type',
    'automation_snapshot_external_id',
    'automation_snapshot_payload_hash',
];
PHP;
$assertContains($historical_snapshot_contract, $migration, 'Endpoint migration does not pin the exact released n45-0008 snapshot index shape');
$assertContains('Unexpected identity snapshot uniqueness shape; refusing destructive repair', $migration, 'Endpoint migration can destructively replace an unrecognized snapshot index');
$assertContains('$snapshot_index_is_absent || $snapshot_index_is_historical', $migration, 'Endpoint migration rewrites snapshot uniqueness outside the absent or historical compatibility cases');
$assertTrue(!file_exists($root . '/admin/database_updates/2.7.9.php'), 'Goal 5 must release through n45-0012, not the former numeric migration namespace');
$assertTrue(!file_exists($root . '/admin/database_updates/2.7.10.php'), 'Goal 5 must not allocate the shared 2.7.10 migration namespace');

foreach ([
    'endpointRecordSourceStateUnlocked',
    'endpointReconcileNetworkObservationsUnlocked',
    'endpointReconcileAssetSourceUnlocked',
    'endpointDeliveryTuple',
    'endpointCompareDeliveryTuples',
    'endpointRecordChangeEventUnlocked',
    'endpointRetireSourceStateUnlocked',
    'endpointLoadUnifiedRecord',
    'endpointUnifiedRecordContractViolations',
    'unifiedDeviceServiceReviewSnapshot',
] as $function) {
    $assertContains("function $function", $functions, "Endpoint service is missing $function");
}
$assertContains("n45RequireModule('endpoint');", $loader, 'Endpoint service is not loaded through the N45 module boundary');
$assertNotContains("require_once __DIR__ . '/functions/endpoint.php';", $loader, 'Endpoint service bypasses the N45 module boundary');
$assertNotContains("require_once __DIR__ . '/functions/device_source.php';", $loader, 'Device-source adapters bypass the N45 module boundary');
$assertTrue(
    ($manifest['modules']['endpoint']['runtime_files'] ?? []) === [
        'functions/endpoint.php',
        'functions/device_source.php',
    ],
    'Endpoint module runtime files must load the canonical service before source adapters'
);
$assertContains('endpointReconcileAssetSourceUnlocked([', $level, 'Level sync does not atomically update posture and topology');
$assertNotContains('endpointRecordSourceStateUnlocked([', $level, 'Level bypasses the atomic posture/topology delivery boundary');
$assertNotContains('endpointReconcileNetworkObservationsUnlocked([', $level, 'Level independently chooses a topology winner');
$assertContains('function levelLockDeviceBindingUnlocked', $level, 'Level sync has no locked binding resolver');
$assertContains("FROM level_asset_links\n        WHERE level_device_id = '\$device_id_sql' LIMIT 1 FOR UPDATE", $level, 'Level asset link is not re-read under lock');
$assertContains("FROM automation_entity_mappings\n        WHERE automation_mapping_source = 'level'", $level, 'Level identity mapping is not re-read under lock');
$assertContains('Level identity did not resolve to the exact trusted asset binding', $level, 'Level writes do not require an exact trusted binding');
$assertContains('ambiguous_tenant_serial', $level, 'Ambiguous Level serials do not enter the conflict queue');
$assertContains("'candidate_asset_ids' => \$serial_candidates", $level, 'Ambiguous Level candidates are not retained for review');
$assertContains("'reason' => 'duplicate_source_identity'", $level, 'Duplicate Level identities roll back instead of entering the review queue');
$level_sync_start = strpos($level, 'function levelSyncDevice(');
$level_sync_end = $level_sync_start === false ? false : strpos($level, 'function levelFetchAndSyncDevice(', $level_sync_start);
$level_sync = $level_sync_start === false || $level_sync_end === false
    ? ''
    : substr($level, $level_sync_start, $level_sync_end - $level_sync_start);
$assertOrder(
    'mysqli_begin_transaction($mysqli);',
    'levelResolveClientForGroupLocked($group_id)',
    $level_sync,
    'Level resolves client routing before its group ancestry is locked'
);
$assertOrder(
    'levelLockDeviceBindingUnlocked(',
    'levelRecordDeviceIdentitySnapshot(',
    $level_sync,
    'Level writes an identity snapshot before locking and comparing its live binding'
);
$assertOrder(
    'levelUpsertDeviceIdentityMapping(',
    'levelReconcileAssetInterfaces(',
    $level_sync,
    'Level writes interface facts before establishing its exact identity binding'
);
$assertContains('integrationIdentityRetireMapping(', $level, 'Level deletion does not retire the device identity');
$assertContains('level_client_mismatch_quarantine', $level, 'Level client mismatch is not quarantined');
$assertContains('endpointRetireIdentityBindingUnlocked([', $level, 'Level conflicts do not retire endpoint topology');
$assertContains('levelMarkDeviceDeleted($missing_device_id, $device_sync_started)', $level, 'Full sync does not use a locked cutoff re-check');
$retirement_start = strpos($level, 'function levelMarkDeviceDeleted(');
$retirement_end = $retirement_start === false ? false : strpos($level, 'function levelAlertPriority(', $retirement_start);
$retirement_block = $retirement_start === false || $retirement_end === false
    ? ''
    : substr($level, $retirement_start, $retirement_end - $retirement_start);
$assertContains('identity mapping diverges from the locked asset link', $retirement_block, 'Full-sync retirement does not fail closed on binding divergence');
$assertContains('endpoint source binding diverges from the locked asset link', $retirement_block, 'Full-sync retirement ignores a divergent endpoint cascade target');
$assertOrder(
    "FROM automation_entity_mappings",
    'UPDATE level_asset_links SET',
    $retirement_block,
    'Full-sync retirement mutates the link before verifying the locked cascade target'
);
$quarantine_start = strpos($level, "if (\$old_client_id !== \$client_id)");
$quarantine_end = $quarantine_start === false ? false : strpos($level, 'mysqli_commit($mysqli);', $quarantine_start);
$quarantine_block = $quarantine_start === false || $quarantine_end === false
    ? ''
    : substr($level, $quarantine_start, $quarantine_end - $quarantine_start);
$assertContains('level_client_mismatch_quarantine', $quarantine_block, 'Client mismatch does not enter the early quarantine block');
$assertContains('endpointRetireIdentityBindingUnlocked([', $quarantine_block, 'Client mismatch leaves trusted endpoint state active');
$assertNotContains('UPDATE assets SET', $quarantine_block, 'Client mismatch mutates the old tenant asset');
$assertNotContains('levelReconcileAssetInterfaces(', $quarantine_block, 'Client mismatch mutates old tenant interfaces');
$assertNotContains('levelIdentitySnapshot(', $quarantine_block, 'Client mismatch persists the new tenant snapshot');
$unmapped_start = strpos($level, 'if ($client_id === 0)');
$unmapped_end = $unmapped_start === false ? false : strpos($level, 'mysqli_commit($mysqli);', $unmapped_start);
$unmapped_block = $unmapped_start === false || $unmapped_end === false
    ? ''
    : substr($level, $unmapped_start, $unmapped_end - $unmapped_start);
$assertContains('level_group_unmapped', $unmapped_block, 'Unmapped Level devices do not enter identity quarantine');
$assertContains('endpointRetireIdentityBindingUnlocked([', $unmapped_block, 'Unmapped Level devices leave endpoint state active');
$assertNotContains('level_device_snapshot', $unmapped_block, 'An unmapped Level group overwrites the old tenant snapshot');
$assertContains('endpointLoadUnifiedRecord($asset_id, $client_id)', $asset, 'Asset view does not load the unified record');
$assertContains("inc_asset_endpoint_record.php", $asset, 'Asset view does not render the unified record');
$assertContains("clientScopeSql('assets.asset_client_id')", $asset, 'Asset route does not enforce server-side tenant scope');
$assertContains('enforceClientAccess($client_id)', $asset, 'Asset route does not re-check the resolved client');
$assertContains("'/agent/asset.php?client_id='", $operations, 'Operations asset links omit their client-id convenience parameter');
$assertContains('Unified Endpoint &amp; Network Record', $endpoint_ui, 'Unified endpoint UI heading is missing');
$assertContains('Related Evidence', $endpoint_ui, 'Unified endpoint UI does not show related evidence');
$assertContains("'interfaces' => \$interfaces", $functions, 'Unified endpoint record omits editable interfaces');
$assertContains("'related_tickets' => \$related_tickets", $functions, 'Unified endpoint record omits related tickets');
$assertContains("'related_documentation' => \$related_documentation", $functions, 'Unified endpoint record omits related documentation');
$assertContains('endpointUnifiedRecordContractViolations($record)', $functions, 'Unified endpoint record is returned without a shape contract');
$assertContains('endpointReconcileAssetSource([', $api, 'Endpoint ingestion API does not use the transactional reconciler');
$assertContains("array_is_list(\$_POST['network_interfaces'])", $api, 'Endpoint ingestion does not require a JSON interface array');
$assertContains("\$source === 'itflow'", $api, 'Endpoint ingestion does not reserve the internal ITFlow source');
$assertContains('endpointPositiveInt', $api, 'Endpoint ingestion does not validate relationship ids strictly');
$assertContains('catch (EndpointConflictException $e)', $api, 'Typed endpoint identity conflicts are not returned explicitly');
$assertContains("HTTP/1.1 409 Conflict", $api, 'Typed endpoint conflicts do not return HTTP 409');
$assertContains("str_contains(\$message, 'disagrees')", $api, 'Network assignment conflicts are not returned as 4xx');
$assertContains('API_ENDPOINT_MAX_JSON_BODY_BYTES = 2 * 1024 * 1024', $validator, 'Endpoint input does not use the smaller route-specific body cap');
$assertContains('API_MAX_JSON_DEPTH', $validator, 'API JSON depth is not bounded');
$assertContains('API_ENDPOINT_MAX_JSON_NODES', $validator, 'Endpoint JSON breadth is not bounded');
$assertContains('API_ENDPOINT_MAX_CONTAINER_ITEMS', $validator, 'Endpoint JSON container breadth is not bounded');
$assertContains("#/integrations/endpoint/(?:create|update)(?:\\.php)?/?\$#", $validator, 'The endpoint-specific request envelope is not route scoped');
$assertContains('JSON_THROW_ON_ERROR', $validator, 'Malformed API JSON is not rejected');
$assertContains('if (!$api_body_decoded)', $validator, 'Authenticated header/query requests do not defer body decode until after key validation');
$assertOrder(
    "mysqli_num_rows(\$sql) !== 1",
    'if (!$api_body_decoded)',
    $validator,
    'Header/query API credentials are not authenticated before body decode'
);
$assertContains("HTTP_USER_AGENT'] ?? ''", $validator, 'Missing API user-agent handling is unsafe');
$assertContains("'endpoint'      => 'module_support'", $rbac, 'Endpoint ingestion API is not mapped to support RBAC');
$assertContains('integrationIdentityUpsertMapping([', $identity_api, 'Identity adapter API bypasses durable mappings');
$assertContains("'authorized_client_id' => \$client_id", $identity_api, 'Identity adapter API does not enforce the caller tenant inside the mapping lock');
$assertContains('integrationIdentityRecordSnapshot([', $identity_api, 'Identity adapter API does not retain normalized source evidence');
$assertContains("['entra', 'intune', 'sentinelone']", $identity_api, 'Identity adapter API admits unsupported/internal sources');
$assertContains("['--dry-run', '--apply']", $reconciler, 'Endpoint reconciler does not require an explicit mode');
$assertContains('GET_LOCK(', $reconciler, 'Endpoint reconciler does not take an advisory lock');
$assertContains('mysqli_begin_transaction($mysqli)', $reconciler, 'Endpoint reconciler is not transactional');
$assertContains('LIMIT 1 FOR UPDATE', $reconciler, 'Endpoint reconciler does not re-read candidates under lock');
$assertContains("automation_mapping_source IN ('entra', 'intune', 'sentinelone')", $reconciler, 'Endpoint reconciler does not backfill directory and security sources');
$assertContains('identity mapping diverges from the locked asset link', $reconciler, 'Level backfill does not require the exact locked identity binding');

foreach ([
    'endpoint_state_asset_source',
    'endpoint_state_source_external',
    'network_observation_asset_current',
    'network_observation_identity',
    'asset_change_event_fingerprint',
] as $index) {
    $assertContains("`$index`", $schema, "Baseline schema is missing $index");
    $assertContains("`$index`", $migration, "Migration is missing $index");
}

$assertContains('asset_client_id = $client_id', $functions, 'Endpoint asset reads are not client scoped');
$assertContains('automation_mapping_client_id', $functions, 'Endpoint source writes do not verify identity tenant bindings');
$assertContains('FOR UPDATE', $functions, 'Endpoint reconciliation does not lock canonical rows');
$assertContains('mysqli_begin_transaction($mysqli)', $functions, 'Endpoint reconciler is not transactional');
$assertContains('mysqli_rollback($mysqli)', $functions, 'Endpoint reconciler does not roll back failures');
$assertContains('endpointDeliveryTuple(', $functions, 'Posture and topology do not share one deterministic delivery tuple');
$assertContains('$effective_network_hash', $functions, 'The delivery tuple omits effective topology');
$assertContains('$external_id', $functions, 'The delivery tuple omits source identity');
$combined_start = strpos($functions, 'function endpointReconcileAssetSourceUnlocked(');
$combined_end = $combined_start === false ? false : strpos($functions, 'function endpointReconcileAssetSource(', $combined_start);
$combined_block = $combined_start === false || $combined_end === false
    ? ''
    : substr($functions, $combined_start, $combined_end - $combined_start);
$assertOrder(
    'endpointRecordSourceStateUnlocked(',
    'endpointReconcileNetworkObservationsUnlocked(',
    $combined_block,
    'Canonical topology is persisted before the total delivery tuple is accepted'
);
$assertContains("if (empty(\$state['delivery_won']))", $combined_block, 'A losing delivery can reach canonical topology persistence');
$network_reconcile_start = strpos($functions, 'function endpointReconcileNetworkObservationsUnlocked(');
$network_reconcile_end = $network_reconcile_start === false ? false : strpos($functions, 'function endpointReconcileAssetSourceUnlocked(', $network_reconcile_start);
$network_reconcile_block = $network_reconcile_start === false || $network_reconcile_end === false
    ? ''
    : substr($functions, $network_reconcile_start, $network_reconcile_end - $network_reconcile_start);
$assertContains("delivery_accepted", $network_reconcile_block, 'Topology persistence can bypass an accepted posture delivery');
$assertContains('Endpoint topology requires an accepted canonical posture row', $network_reconcile_block, 'Topology persistence does not require its canonical posture owner');
$assertContains('Accepted endpoint topology does not match its delivery tuple', $network_reconcile_block, 'Topology persistence does not verify the winning tuple hash');
$assertNotContains('tie_lost', $network_reconcile_block, 'Topology still selects an independent equal-second winner');
$loser_start = strpos($functions, 'if ($watermark_comparison > 0 || $tie_lost)');
$loser_end = $loser_start === false ? false : strpos($functions, "\n        }\n    }", $loser_start);
$loser_block = $loser_start === false || $loser_end === false
    ? ''
    : substr($functions, $loser_start, $loser_end - $loser_start);
$assertContains('endpoint_state_last_seen_at = CASE', $loser_block, 'A losing delivery does not monotonically advance last-seen');
$assertContains("'delivery_won' => false", $loser_block, 'A losing delivery is not stopped before canonical events');
$assertNotContains('endpointRecordChangeEventUnlocked', $loser_block, 'A losing delivery creates a canonical event');
$assertContains('Endpoint source identity rebind requires a retired prior identity', $functions, 'Retired source identities cannot be safely rebound');
$assertContains('throw new EndpointConflictException', $functions, 'Source identity rebind conflicts are not typed');
$assertContains('endpointSummaryFieldOwners', $functions, 'Unified summary fields have no trusted ownership policy');
$fingerprint_start = strpos($functions, "\$fingerprint = hash('sha256'");
$fingerprint_end = $fingerprint_start === false ? false : strpos($functions, ']));', $fingerprint_start);
$fingerprint_block = $fingerprint_start === false || $fingerprint_end === false
    ? ''
    : substr($functions, $fingerprint_start, $fingerprint_end - $fingerprint_start);
$assertContains('$ticket_id,', $fingerprint_block, 'Change fingerprints omit ticket references');
$assertContains('$document_id,', $fingerprint_block, 'Change fingerprints omit document references');
$assertContains('$evidence_id,', $fingerprint_block, 'Change fingerprints omit evidence references');
$assertContains('function n45DatabaseTransactionActive(): bool', $identity, 'N45 has no portable transaction-state guard');
$assertContains('SELECT @@SESSION.in_transaction', $identity, 'N45 transaction-state guard does not use the database session state');
$assertContains('n45DatabaseTransactionActive()', $functions, 'Event references are not guarded by a transaction');
$transaction_services = $identity . $functions . $device_source . $level;
$assertNotContains('MYSQLI_SERVER_STATUS_IN_TRANS', $transaction_services, 'N45 uses a non-portable mysqli server-status constant');
$assertNotContains('->server_status', $transaction_services, 'N45 uses a non-portable mysqli server-status property');
$assertContains('asset_change_event_ticket_label', $functions, 'Ticket evidence labels are not durably snapshotted');
$assertContains('asset_change_event_document_label', $functions, 'Document evidence labels are not durably snapshotted');
$assertContains('asset_change_event_evidence_label', $functions, 'Runbook evidence labels are not durably snapshotted');
$assertContains('Network observation disagrees with the interface network assignment', $functions, 'Interface/network agreement is not validated');
$assertContains('Network observation VLAN disagrees with the ITFlow network assignment', $functions, 'VLAN agreement is not validated');
$relationship_start = strpos($functions, 'function endpointValidateNetworkRelationships(');
$relationship_end = $relationship_start === false ? false : strpos($functions, 'function endpointPrepareNetworkDeliveryUnlocked(', $relationship_start);
$relationship_block = $relationship_start === false || $relationship_end === false
    ? ''
    : substr($functions, $relationship_start, $relationship_end - $relationship_start);
$assertContains('LIMIT 1 FOR UPDATE', $relationship_block, 'Topology references are not locked against concurrent edits or deletion');
$assertContains('endpointRetireIdentityBindingUnlocked([', $identity, 'Identity retirement does not cascade to endpoint state');
$assertContains('Identity retirement stopped: endpoint source binding diverges', $identity, 'Identity retirement ignores a divergent endpoint cascade target');
$endpoint_retire_start = strpos($functions, 'function endpointRetireIdentityBindingUnlocked(');
$endpoint_retire_end = $endpoint_retire_start === false ? false : strpos($functions, 'function endpointWarrantyState(', $endpoint_retire_start);
$endpoint_retire_block = $endpoint_retire_start === false || $endpoint_retire_end === false
    ? ''
    : substr($functions, $endpoint_retire_start, $endpoint_retire_end - $endpoint_retire_start);
$assertContains('endpoint_state_external_id', $endpoint_retire_block, 'Endpoint retirement does not lock its exact source identity');
$assertContains('endpoint_state_asset_id', $endpoint_retire_block, 'Endpoint retirement does not verify its exact cascade asset');
$assertContains('document.php?client_id=', $endpoint_ui, 'Timeline document references are not rendered');
$assertContains('evidence_ticket_id', $endpoint_ui, 'Timeline evidence references are not rendered');
$assertContains("\$source_key . \"\\0\" . \$external_key", $endpoint_ui, 'Endpoint UI source state is not keyed by source and external id');
$assertContains('Deleted document #', $endpoint_ui, 'Deleted document evidence loses its immutable label fallback');
$assertContains('Deleted evidence #', $endpoint_ui, 'Deleted runbook evidence loses its immutable label fallback');
$assertContains('2 MiB', $docs, 'Endpoint documentation omits the route-specific API request limit');
$assertContains('function integrationIdentityReviewMapping', $identity, 'Identity review actions are not implemented');
$assertContains('integrationIdentityRecordDecisionUnlocked', $identity, 'Mapping decisions are not appended to the audit ledger');
$assertContains('Identity conflict quarantine stopped because endpoint state diverged', $identity, 'Mapping conflicts leave formerly trusted endpoint posture active');
$assertContains('retired_identity_reappeared', $identity, 'Repeated polling can silently resurrect a retired durable identity');
$assertContains('Replaying the last old sighting is not source recovery', $identity, 'An equal-watermark replay can falsely clear identity staleness');
$assertContains('safer review state regardless of delivery order', $identity, 'Equal-watermark mapping assessments are delivery-order dependent');
$assertContains('function integrationIdentityReconcileStaleness', $identity, 'Scheduled identity staleness reconciliation is not implemented');
$assertContains('integrationIdentityReconcileOrphans()', $identity_cron, 'Identity cron does not quarantine orphaned or cross-tenant bindings');
$assertContains("name=\"mapping_ids[]\"", $operations, 'Operations does not render a bulk identity review queue');
$assertContains("\$bound_identity_scope = \$session_is_admin ? '' : 'AND automation_mapping_client_id > 0'", $operations, 'Restricted technicians can see unbound identities in the review ledger');
$assertContains('review_identity_mappings', $operations_post, 'Operations identity decisions have no POST handler');
$assertContains("enforceUserPermission('module_support', 3)", $operations_post, 'Identity remap does not require Full Support permission');
$assertContains("'identity_reconciliation'", $cron_registry, 'Identity reconciliation is not registered with the cron dispatcher');
$assertContains('integrationIdentityReconcileStaleness()', $identity_cron, 'Identity cron does not reconcile freshness');
$assertContains("'Endpoint Identity Failure'", $identity_cron, 'Identity cron failures do not notify technicians');
$assertContains('endpointIntegrationCoverageRows', $operations, 'Operations does not display tenant-scoped endpoint source coverage');
$assertContains('managed_windows_missing_sentinelone', $operations, 'Operations does not surface managed Windows devices missing SentinelOne');
$assertContains('ITFlow deliberately does **not** store Microsoft Graph/CIPP or SentinelOne polling credentials', $docs, 'Deployment-only source polling dependency is undocumented');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Unified endpoint record wiring tests passed.\n";
