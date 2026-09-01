<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';
require_once __DIR__ . '/../functions/endpoint.php';

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
$assertInvalid = function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (InvalidArgumentException $e) {
        // Expected rejection.
    }
};

$facts = endpointNormalizeSourceFacts([
    'assigned_user' => [
        'id' => 'entra-user-1',
        'name' => 'Ada Lovelace',
        'user_principal_name' => 'ADA@EXAMPLE.TEST',
    ],
    'entra_device_id' => 'entra-device-1',
    'intune_device_id' => 'intune-device-1',
    'health' => 'good',
    'compliance' => 'non compliant',
    'is_encrypted' => true,
    'secure_boot_enabled' => false,
    'operating_system' => 'Windows 11 Pro',
    'version' => '23H2',
    'build' => '22631.4037',
    'lifecycle' => 'Deployed',
    'security_score' => 97,
    'api_key' => 'must-not-be-stored',
    'refresh_token' => 'must-not-be-stored',
]);

$assertSame('Ada Lovelace', $facts['assigned_user_name'], 'Assigned user name was not normalized');
$assertSame('ada@example.test', $facts['assigned_user_email'], 'Assigned user email was not normalized');
$assertSame('noncompliant', $facts['compliance_state'], 'Compliance alias was not normalized');
$assertSame('encrypted', $facts['encryption_state'], 'Boolean encryption state was not normalized');
$assertSame('disabled', $facts['secure_boot_state'], 'Boolean Secure Boot state was not normalized');
$assertSame('deployed', $facts['lifecycle_state'], 'Lifecycle state was not normalized');
$assertSame(false, array_key_exists('api_key', $facts['details']), 'Unknown secret field entered endpoint details');
$assertSame('', endpointLimitText(['unexpected'], 20), 'Nested data was coerced into canonical text');
$assertSame(0, endpointPositiveInt(['1']), 'Nested data was coerced into a relationship id');
$assertSame(42, endpointPositiveInt('42'), 'A numeric relationship id was not accepted');
$assertSame(
    '2026-01-01 00:00:00',
    endpointDateTime('2026-01-01T02:00:00+02:00'),
    'Endpoint timestamps were not normalized to the application timezone'
);

$state_a = endpointSourceStateDocument('intune', 'device-1', array_merge($facts, [
    'last_seen_at' => '2026-01-01T00:00:00Z',
]));
$state_b = endpointSourceStateDocument('intune', 'device-1', array_merge($facts, [
    'last_seen_at' => '2026-01-02T00:00:00Z',
]));
$assertSame($state_a['hash'], $state_b['hash'], 'Heartbeat-only timestamps created a posture change');
$assertSame(false, str_contains($state_a['payload'], 'must-not-be-stored'), 'A secret entered endpoint state payload');
$assertSame(
    97,
    $state_a['facts']['details']['security_score'] ?? null,
    'Re-normalizing canonical endpoint facts dropped allowlisted security details'
);

$network = endpointNormalizeNetworkObservation([
    'key' => 'Ethernet 2',
    'name' => 'Ethernet 2',
    'type' => 'Virtual',
    'mac_address' => 'AA-BB-CC-DD-EE-FF',
    'ip_addresses' => ['10.0.0.20/24', 'fe80::20%12', '10.0.0.10'],
    'vlan_id' => 30,
    'vlan_name' => 'Workstations',
    'discovery_protocol' => 'LLDP',
    'switch_name' => 'switch-01',
    'switch_port' => 'Gi1/0/12',
]);
$assertSame('aa:bb:cc:dd:ee:ff', $network['state']['mac'], 'Network MAC was not normalized');
$assertSame(['10.0.0.10', '10.0.0.20'], $network['state']['ipv4'], 'IPv4 history was not canonicalized');
$assertSame(['fe80::20'], $network['state']['ipv6'], 'IPv6 scope was not normalized');
$assertSame(true, $network['state']['virtual'], 'Virtual interface classification was lost');
$assertSame(30, $network['state']['vlan_id'], 'VLAN id was not retained');
$assertSame('lldp', $network['state']['neighbor_protocol'], 'LLDP relationship was not normalized');
$assertSame('Gi1/0/12', $network['state']['neighbor_port'], 'Switch port was not retained');
$physical_network = endpointNormalizeNetworkObservation([
    'key' => 'ethernet:physical',
    'type' => 'Ethernet',
    'virtual' => 'false',
]);
$assertSame(false, $physical_network['state']['virtual'], 'A false virtual flag was coerced to true');
$network_a = endpointNormalizeNetworkObservation(['key' => 'a', 'ip' => '10.0.0.1']);
$network_b = endpointNormalizeNetworkObservation(['key' => 'b', 'ip' => '10.0.0.2']);
$assertSame(
    endpointNetworkSnapshotHash([$network_a, $network_b]),
    endpointNetworkSnapshotHash([$network_b, $network_a]),
    'Network snapshot fingerprint depends on payload ordering'
);
$assertInvalid(
    static fn () => endpointValidateNetworkObservationBounds(array_fill(0, 129, ['key' => 'eth0'])),
    'The endpoint interface cap was not enforced'
);
$assertInvalid(
    static fn () => endpointValidateNetworkObservationBounds([[
        'key' => 'eth0',
        'ip_addresses' => array_fill(0, 65, '10.0.0.1'),
    ]]),
    'The per-interface address cap was not enforced'
);
$assertInvalid(
    static fn () => endpointValidateNetworkObservationBounds(array_fill(0, 33, [
        'key' => 'eth0',
        'ip_addresses' => array_fill(0, 64, '10.0.0.1'),
    ])),
    'The aggregate address cap was not enforced'
);
$grouped_interfaces = endpointGroupAssetInterfaceRows([
    ['interface_id' => 10, 'interface_name' => 'Ethernet'],
    ['interface_id' => 10, 'interface_name' => 'Duplicate join row'],
    ['interface_id' => 20, 'interface_name' => 'Wi-Fi'],
], [
    ['interface_id' => 10, 'connected_interface_id' => 101, 'connected_asset_name' => 'Switch'],
    ['interface_id' => 10, 'connected_interface_id' => 102, 'connected_asset_name' => 'Firewall'],
    ['interface_id' => 10, 'connected_interface_id' => 101, 'connected_asset_name' => 'Duplicate edge'],
]);
$assertSame(2, count($grouped_interfaces), 'Connection fan-out duplicated an asset interface row');
$assertSame('Ethernet', $grouped_interfaces[0]['interface_name'], 'The first canonical interface row was not preserved');
$assertSame(2, count($grouped_interfaces[0]['connections']), 'Distinct interface connections were not grouped');
$assertSame(0, count($grouped_interfaces[1]['connections']), 'A connection leaked to another interface');
$empty_network_hash = endpointNetworkSnapshotHash([]);
$active_tuple = endpointDeliveryTuple(
    'active',
    str_repeat('f', 64),
    str_repeat('f', 64),
    'device-z'
);
$retired_tuple = endpointDeliveryTuple(
    'retired',
    str_repeat('0', 64),
    $empty_network_hash,
    'device-a'
);
$assertTrue(
    endpointCompareDeliveryTuples($retired_tuple, $active_tuple) > 0,
    'Equal-watermark retirement does not deterministically outrank active posture and topology'
);

$same_watermark = '2026-01-01 00:00:00';
$delivery_a = [
    'id' => 'A',
    'observed_at' => $same_watermark,
    'tuple' => endpointDeliveryTuple(
        'active',
        str_repeat('a', 64),
        hash('sha256', 'network-b'),
        'device-b'
    ),
];
$delivery_b = [
    'id' => 'B',
    'observed_at' => $same_watermark,
    'tuple' => endpointDeliveryTuple(
        'active',
        str_repeat('b', 64),
        hash('sha256', 'network-a'),
        'device-a'
    ),
];
$winner_ab = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $delivery_a),
    $delivery_b
);
$winner_ba = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $delivery_b),
    $delivery_a
);
$assertSame(
    $winner_ab['id'],
    $winner_ba['id'],
    'Equal-second posture and topology winners depend on A→B versus B→A delivery order'
);
$assertSame('B', $winner_ab['id'], 'The total delivery tuple did not select the expected posture winner');

$network_only_a = $delivery_a;
$network_only_b = $delivery_a;
$network_only_a['id'] = 'network-a';
$network_only_b['id'] = 'network-b';
$network_only_a['tuple'][2] = str_repeat('a', 64);
$network_only_b['tuple'][2] = str_repeat('b', 64);
$network_winner_ab = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $network_only_a),
    $network_only_b
);
$network_winner_ba = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $network_only_b),
    $network_only_a
);
$assertSame(
    $network_winner_ab['id'],
    $network_winner_ba['id'],
    'Equal-second effective topology winners depend on delivery order'
);
$assertSame('network-b', $network_winner_ab['id'], 'The effective topology hash does not participate in the total tuple');

$identity_only_a = $delivery_a;
$identity_only_b = $delivery_a;
$identity_only_a['id'] = 'identity-a';
$identity_only_b['id'] = 'identity-b';
$identity_only_a['tuple'][3] = 'device-a';
$identity_only_b['tuple'][3] = 'device-b';
$identity_winner_ab = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $identity_only_a),
    $identity_only_b
);
$identity_winner_ba = endpointSelectDeliveryCandidate(
    endpointSelectDeliveryCandidate(null, $identity_only_b),
    $identity_only_a
);
$assertSame(
    $identity_winner_ab['id'],
    $identity_winner_ba['id'],
    'Equal-second source identity winners depend on delivery order'
);
$assertSame('identity-b', $identity_winner_ab['id'], 'The source external id does not participate in the total tuple');

$delivery_key_a = endpointDeliveryKey($same_watermark, $delivery_a['tuple']);
$delivery_key_b = endpointDeliveryKey($same_watermark, $delivery_b['tuple']);
$assertSame(64, strlen($delivery_key_a), 'Endpoint delivery key is not a SHA-256 fingerprint');
$assertTrue($delivery_key_a !== $delivery_key_b, 'Distinct total delivery tuples share one delivery key');
$baseline = endpointStateBaselineFromRow([
    'endpoint_state_payload' => $state_a['payload'],
    'endpoint_state_payload_hash' => $state_a['hash'],
    'endpoint_state_status' => 'active',
    'endpoint_state_external_id' => 'device-1',
]);
$assertSame(
    $baseline,
    endpointDecodeDeliveryBaseline(endpointEncodeDeliveryBaseline($baseline)),
    'Equal-second reconciliation could not round-trip its pre-delivery posture baseline'
);

$inactive_network = endpointPrepareNetworkDeliveryUnlocked(0, 0, 'retired', [[
    'key' => 'must-be-discarded',
    'ip' => '10.0.0.99',
]]);
$assertSame([], $inactive_network['observations'], 'Inactive endpoint posture retained supplied topology');
$assertSame(
    $empty_network_hash,
    $inactive_network['hash'],
    'Inactive endpoint posture does not own the canonical empty-topology hash'
);

$changed = endpointSourceStateChangedFields(
    ['health_state' => 'healthy', 'os_build' => '100'],
    ['health_state' => 'critical', 'os_build' => '101']
);
$assertSame(['health', 'OS build'], $changed, 'Endpoint change fields were not explainable');

$clock = new DateTimeImmutable('2026-01-01 00:00:00');
$assertSame('unknown', endpointWarrantyState(null, $clock), 'Missing warranty was not unknown');
$assertSame('expired', endpointWarrantyState('2025-12-31', $clock), 'Expired warranty was not detected');
$assertSame('due_soon', endpointWarrantyState('2026-03-01', $clock), 'Due-soon warranty was not detected');
$assertSame('active', endpointWarrantyState('2027-01-01', $clock), 'Active warranty was not detected');

$summary = endpointBuildUnifiedSummary([
    'asset_os' => 'Windows',
    'asset_status' => 'Deployed',
    'asset_warranty_expire' => '2027-01-01',
    'contact_name' => 'Fallback User',
    'contact_email' => 'fallback@example.test',
], [[
    'endpoint_state_source' => 'intune',
    'endpoint_state_status' => 'active',
    'endpoint_state_assigned_user_name' => 'Managed User',
    'endpoint_state_assigned_user_email' => 'managed@example.test',
    'endpoint_state_entra_device_id' => 'entra-1',
    'endpoint_state_intune_device_id' => 'intune-1',
    'endpoint_state_compliance' => 'compliant',
    'endpoint_state_encryption' => 'encrypted',
    'endpoint_state_secure_boot' => 'enabled',
    'endpoint_state_os_name' => 'Windows 11',
    'endpoint_state_os_version' => '23H2',
    'endpoint_state_os_build' => '22631',
    'endpoint_state_health' => 'healthy',
    'endpoint_state_lifecycle' => 'active',
]]);
$assertSame('Managed User', $summary['assigned_user_name'], 'Managed assignment did not override ITFlow fallback');
$assertSame('compliant', $summary['compliance_state'], 'Canonical compliance was not selected');
$assertSame('Windows 11', $summary['os_name'], 'Managed OS did not override the ITFlow fallback');

$conflicting_summary = endpointBuildUnifiedSummary([
    'asset_os' => 'Windows 10',
    'asset_status' => 'Deployed',
    'asset_warranty_expire' => null,
    'contact_name' => 'Fallback User',
    'contact_email' => 'fallback@example.test',
], [[
    'endpoint_state_source' => 'intune',
    'endpoint_state_status' => 'conflicting',
    'endpoint_state_assigned_user_name' => 'Wrong Tenant User',
    'endpoint_state_compliance' => 'compliant',
    'endpoint_state_os_name' => 'Untrusted OS',
]]);
$assertSame('Fallback User', $conflicting_summary['assigned_user_name'], 'Conflicting identity changed the canonical assignment');
$assertSame('unknown', $conflicting_summary['compliance_state'], 'Conflicting posture changed the canonical summary');
$assertSame('Windows 10', $conflicting_summary['os_name'], 'Conflicting OS changed the canonical summary');

$ownership_summary = endpointBuildUnifiedSummary([
    'asset_os' => 'ITFlow OS',
    'asset_status' => 'Deployed',
    'asset_warranty_expire' => null,
    'contact_name' => 'ITFlow User',
    'contact_email' => 'itflow@example.test',
], [[
    'endpoint_state_source' => 'vendor-surprise',
    'endpoint_state_status' => 'active',
    'endpoint_state_assigned_user_name' => 'Untrusted User',
    'endpoint_state_compliance' => 'compliant',
    'endpoint_state_os_name' => 'Untrusted OS',
], [
    'endpoint_state_source' => 'level',
    'endpoint_state_status' => 'active',
    'endpoint_state_assigned_user_name' => 'Level User',
    'endpoint_state_compliance' => 'compliant',
    'endpoint_state_os_name' => 'Level OS',
    'endpoint_state_health' => 'healthy',
]]);
$assertSame('ITFlow User', $ownership_summary['assigned_user_name'], 'An unowned source changed assignment');
$assertSame('unknown', $ownership_summary['compliance_state'], 'Level changed Intune-owned compliance');
$assertSame('Level OS', $ownership_summary['os_name'], 'The trusted OS fallback was not used');
$assertSame('healthy', $ownership_summary['level_health'], 'Level health was not source-owned');

$invalid_status_rejected = false;
try {
    endpointNormalizeSourceStatus('silently-good');
} catch (InvalidArgumentException $e) {
    $invalid_status_rejected = true;
}
$assertTrue($invalid_status_rejected, 'Invalid source status was silently accepted');

$future_observation_rejected = false;
try {
    endpointObservationDateTime('2999-01-01T00:00:00Z');
} catch (InvalidArgumentException $e) {
    $future_observation_rejected = true;
}
$assertTrue($future_observation_rejected, 'A far-future observation watermark was accepted');

$nested_observation_rejected = false;
try {
    endpointObservationDateTime(['2026-01-01']);
} catch (InvalidArgumentException $e) {
    $nested_observation_rejected = true;
}
$assertTrue($nested_observation_rejected, 'Nested timestamp data was silently accepted');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Unified endpoint record normalization tests passed.\n";
