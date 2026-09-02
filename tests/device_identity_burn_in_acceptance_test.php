<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';

$failures = [];
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = static function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};
$issueCodes = static function (array $audit): array {
    return array_values(array_unique(array_column($audit['summary']['issues'] ?? [], 'code')));
};

$assets = [[
    'asset_id' => 101,
    'client_id' => 10,
    'active' => true,
    'required_sources' => ['level', 'intune', 'entra', 'sentinelone'],
    'source_scopes' => [
        'level' => 'level-group-10',
        'intune' => 'tenant-10',
        'entra' => 'tenant-10',
        'sentinelone' => 's1-site-10',
    ],
], [
    'asset_id' => 102,
    'client_id' => 10,
    'active' => true,
    // A policy-approved non-Windows appliance can explicitly narrow its set.
    'required_sources' => ['level'],
    'source_scopes' => ['level' => 'level-group-10'],
]];

$mappings = [];
$mapping_id = 1;
foreach ([
    ['level', 'level-device-101', 'level-group-10', 101],
    ['intune', 'intune-device-101', 'tenant-10', 101],
    ['entra', 'entra-device-101', 'tenant-10', 101],
    ['sentinelone', 's1-agent-101', 's1-site-10', 101],
    ['level', 'level-device-102', 'level-group-10', 102],
] as [$source, $external_id, $parent_id, $asset_id]) {
    $mappings[] = [
        'mapping_id' => $mapping_id++,
        'source' => $source,
        'external_id' => $external_id,
        'external_parent_id' => $parent_id,
        'client_id' => 10,
        'asset_id' => $asset_id,
        'state' => 'confirmed',
        'last_seen_at' => '2026-09-02 12:00:00',
        'last_synced_at' => '2026-09-02 12:01:00',
    ];
}

$healthy = integrationIdentityCoverageBurnInAudit($assets, $mappings);
$assertSame(true, $healthy['passed'], 'A complete, tenant-scoped endpoint set failed burn-in invariants');
$assertSame(2, $healthy['summary']['assets_checked'], 'The burn-in audit lost an active managed asset');
$assertSame(5, $healthy['summary']['required_links'], 'The per-asset required-source policy was not honored');
$assertSame([], $healthy['summary']['issues'], 'The healthy fixture emitted actionable queue entries');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', $healthy['evidence_hash']) === 1,
    'The burn-in result lacks a stable evidence fingerprint'
);

$replayed_mappings = array_map(static function (array $mapping): array {
    $mapping['last_seen_at'] = '2026-09-02 18:00:00';
    $mapping['last_synced_at'] = '2026-09-02 18:01:00';
    return $mapping;
}, $mappings);
$replay = integrationIdentityReplayInvariant($assets, $mappings, $assets, $replayed_mappings);
$assertSame(true, $replay['passed'], 'A timestamp-only source replay changed durable identity state');
$assertSame($replay['before_hash'], $replay['after_hash'], 'A timestamp-only replay changed its projection hash');

$duplicate_replay_assets = $assets;
$duplicate_replay_assets[] = [
    'asset_id' => 999,
    'client_id' => 10,
    'required_sources' => ['level'],
];
$bad_replay = integrationIdentityReplayInvariant(
    $assets,
    $mappings,
    $duplicate_replay_assets,
    $replayed_mappings
);
$assertSame(false, $bad_replay['passed'], 'A replay-created canonical asset was not detected');
$assertTrue(
    in_array('asset_projection_changed', array_column($bad_replay['issues'], 'code'), true),
    'The replay verifier did not identify the changed asset projection'
);

$scope_replay_assets = $assets;
$scope_replay_assets[0]['source_scopes']['intune'] = 'tenant-reassigned';
$scope_replay = integrationIdentityReplayInvariant(
    $assets,
    $mappings,
    $scope_replay_assets,
    $replayed_mappings
);
$assertSame(false, $scope_replay['passed'], 'A replay silently changed the approved tenant scope');
$assertTrue(
    in_array('asset_projection_changed', array_column($scope_replay['issues'], 'code'), true),
    'The replay verifier did not identify the changed tenant/site policy'
);

$client_conflict_mappings = $mappings;
$client_conflict_mappings[0]['client_id'] = 20;
$client_conflict = integrationIdentityCoverageBurnInAudit($assets, $client_conflict_mappings);
$assertSame(false, $client_conflict['passed'], 'A cross-client source binding passed burn-in');
$assertTrue(
    in_array('client_binding_conflict', $issueCodes($client_conflict), true),
    'The cross-client binding was not classified as a client conflict'
);

$scope_conflict_mappings = $mappings;
$scope_conflict_mappings[1]['external_parent_id'] = 'tenant-other';
$scope_conflict = integrationIdentityCoverageBurnInAudit($assets, $scope_conflict_mappings);
$assertTrue(
    in_array('source_scope_conflict', $issueCodes($scope_conflict), true),
    'A tenant/site mismatch did not enter the burn-in exception set'
);

$review_mappings = $mappings;
$review_mappings[2]['state'] = 'suggested';
$review = integrationIdentityCoverageBurnInAudit($assets, $review_mappings);
$assertTrue(
    in_array('review_queue_not_clear', $issueCodes($review), true)
        && in_array('required_source_missing', $issueCodes($review), true),
    'An unresolved review item was treated as healthy source coverage'
);

$stale_mappings = $mappings;
$stale_mappings[3]['state'] = 'stale';
$stale = integrationIdentityCoverageBurnInAudit($assets, $stale_mappings);
$assertTrue(
    in_array('review_queue_not_clear', $issueCodes($stale), true)
        && ($stale['summary']['queue_counts']['stale'] ?? 0) === 1,
    'A stale required identity did not remain an actionable burn-in exception'
);

$missing_scope_assets = $assets;
unset($missing_scope_assets[0]['source_scopes']['entra']);
$missing_scope = integrationIdentityCoverageBurnInAudit($missing_scope_assets, $mappings);
$assertTrue(
    in_array('required_source_scope_missing', $issueCodes($missing_scope), true),
    'A required source without an approved tenant/site scope passed burn-in'
);

$empty_policy_assets = $assets;
$empty_policy_assets[1]['required_sources'] = [];
$empty_policy = integrationIdentityCoverageBurnInAudit($empty_policy_assets, $mappings);
$assertTrue(
    in_array('required_source_policy_empty', $issueCodes($empty_policy), true),
    'An active managed endpoint declared no required inventory sources'
);

$duplicate_mappings = $mappings;
$duplicate_mappings[] = [
    'mapping_id' => 99,
    'source' => 'level',
    'external_id' => 'level-device-duplicate',
    'external_parent_id' => 'level-group-10',
    'client_id' => 10,
    'asset_id' => 101,
    'state' => 'automatic',
];
$duplicate = integrationIdentityCoverageBurnInAudit($assets, $duplicate_mappings);
$assertTrue(
    in_array('required_source_duplicate', $issueCodes($duplicate), true),
    'Two active source identities on one canonical asset passed burn-in'
);

$orphan_mappings = $mappings;
$orphan_mappings[] = [
    'mapping_id' => 100,
    'source' => 'level',
    'external_id' => 'level-orphan',
    'external_parent_id' => 'level-group-10',
    'client_id' => 10,
    'asset_id' => 404,
    'state' => 'automatic',
];
$orphan = integrationIdentityCoverageBurnInAudit($assets, $orphan_mappings);
$assertTrue(
    in_array('orphan_mapping', $issueCodes($orphan), true),
    'An active external identity without a canonical asset passed burn-in'
);

$dispositioned_mappings = $mappings;
$dispositioned_mappings[] = [
    'mapping_id' => 101,
    'source' => 'level',
    'external_id' => 'level-retired',
    'external_parent_id' => 'level-group-10',
    'client_id' => 10,
    'asset_id' => 404,
    'state' => 'retired',
    'deleted_at' => '2026-09-01 00:00:00',
];
$dispositioned = integrationIdentityCoverageBurnInAudit($assets, $dispositioned_mappings);
$assertSame(true, $dispositioned['passed'], 'An explicitly retired orphan remained actionable');
$assertSame(1, $dispositioned['summary']['dispositioned_mappings'], 'The disposition was not counted');

$ignored_mappings = $mappings;
$ignored_mappings[] = [
    'mapping_id' => 102,
    'source' => 'level',
    'external_id' => 'level-device-101',
    'external_parent_id' => 'level-group-10',
    'client_id' => 10,
    'asset_id' => 404,
    'state' => 'ignored',
];
$ignored = integrationIdentityCoverageBurnInAudit($assets, $ignored_mappings);
$assertSame(true, $ignored['passed'], 'An explicitly ignored duplicate identity remained actionable');
$assertSame(1, $ignored['summary']['dispositioned_mappings'], 'The ignored disposition was not counted');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Goal 1 deterministic burn-in and replay acceptance passed.\n";
