<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';
require_once __DIR__ . '/../functions/level.php';

$failures = [];

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$facts_a = [
    'hostname' => 'workstation-01',
    'api_key' => 'must-not-be-stored',
    'network' => [
        'refreshToken' => 'must-not-be-stored',
        'mac' => '00:11:22:33:44:55',
    ],
    'last_logged_in_user' => 'private-user',
    'online' => true,
];
$facts_b = [
    'online' => true,
    'network' => [
        'mac' => '00:11:22:33:44:55',
        'refreshToken' => 'different-secret',
    ],
    'hostname' => 'workstation-01',
];

$document_a = integrationIdentitySnapshotDocument($facts_a);
$document_b = integrationIdentitySnapshotDocument($facts_b);
$assertSame($document_a['hash'], $document_b['hash'], 'Secret values or object key order changed the snapshot hash');
$assertSame(false, str_contains($document_a['payload'], 'must-not-be-stored'), 'API credentials remained in the normalized snapshot');
$assertSame(false, str_contains($document_a['payload'], 'private-user'), 'Last logged-in user remained in the normalized snapshot');
$assertTrue(str_contains($document_a['payload'], '00:11:22:33:44:55'), 'Allowed device facts were removed from the snapshot');

$merge = integrationIdentityMergeBindings(
    ['client_id' => 11, 'location_id' => 0, 'asset_id' => 22, 'domain_id' => 0],
    ['client_id' => 99, 'location_id' => 33, 'asset_id' => 88, 'domain_id' => 0]
);
$assertSame(11, $merge['bindings']['client_id'], 'An automated mapping moved the identity to another client');
$assertSame(22, $merge['bindings']['asset_id'], 'An automated mapping moved the identity to another asset');
$assertSame(33, $merge['bindings']['location_id'], 'An empty object binding was not filled');
$assertSame(['client_id', 'asset_id'], array_keys($merge['conflicts']), 'Binding conflicts were not reported');

$assertSame(100.0, integrationIdentityConfidence(150), 'Identity confidence was not capped');
$assertSame(0.0, integrationIdentityConfidence(-10), 'Negative identity confidence was not rejected');
$assertSame('conflicting', integrationIdentityState('Conflicting'), 'Identity state was not normalized');
$assertSame('ignored', integrationIdentityState('Ignored'), 'Manual ignore state was not accepted');
$assertSame('remap', integrationIdentityReviewAction('REMAP'), 'Review action was not normalized');
$assertSame(24, integrationIdentityStaleThresholdHours('level'), 'Level freshness threshold changed');
$assertSame(48, integrationIdentityStaleThresholdHours('intune'), 'Intune freshness threshold changed');

$before_mapping = [
    'automation_mapping_id' => 7,
    'automation_mapping_source' => 'intune',
    'automation_mapping_entity_type' => 'device',
    'automation_mapping_external_id' => 'managed-device-1',
    'automation_mapping_client_id' => 4,
    'automation_mapping_asset_id' => 9,
    'automation_mapping_state' => 'suggested',
    'automation_mapping_confidence' => 80,
];
$after_mapping = array_merge($before_mapping, [
    'automation_mapping_state' => 'confirmed',
    'automation_mapping_confidence' => 100,
]);
$assertSame(
    'confirmed',
    integrationIdentityMappingDecisionAction($before_mapping, $after_mapping),
    'Confirmed mapping transition was not classified for the append-only audit'
);

$level_snapshot = levelIdentitySnapshot([
    'id' => 'level-device-1',
    'group_id' => 'level-group-1',
    'hostname' => 'workstation-01',
    'api_key' => 'must-not-be-stored',
    'last_logged_in_user' => 'private-user',
    'serial_number' => 'SERIAL-123',
    'online' => true,
    'network_interfaces' => [],
]);
$level_payload = json_encode($level_snapshot);
$assertSame(false, str_contains($level_payload, 'must-not-be-stored'), 'Level API key entered the allowlisted device snapshot');
$assertSame(false, str_contains($level_payload, 'private-user'), 'Level last logged-in user entered the allowlisted device snapshot');
$assertSame('SERIAL-123', $level_snapshot['serial_number'], 'Level hardware identity was not preserved');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Integration identity normalization tests passed.\n";
