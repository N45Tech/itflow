<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';
require_once __DIR__ . '/../functions/endpoint.php';

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

$summary = endpointBuildUnifiedSummary([
    'asset_os' => 'Windows 11',
    'asset_status' => 'Deployed',
    'asset_warranty_expire' => '2027-09-02',
    'contact_name' => 'Fallback User',
    'contact_email' => 'fallback@example.test',
], [[
    'endpoint_state_source' => 'intune',
    'endpoint_state_status' => 'active',
    'endpoint_state_assigned_user_name' => 'Managed User',
    'endpoint_state_assigned_user_email' => 'managed@example.test',
    'endpoint_state_entra_device_id' => 'entra-device-101',
    'endpoint_state_intune_device_id' => 'intune-device-101',
    'endpoint_state_compliance' => 'compliant',
    'endpoint_state_encryption' => 'encrypted',
    'endpoint_state_secure_boot' => 'enabled',
    'endpoint_state_os_name' => 'Windows 11 Pro',
    'endpoint_state_os_version' => '24H2',
    'endpoint_state_os_build' => '26100',
], [
    'endpoint_state_source' => 'level',
    'endpoint_state_status' => 'active',
    'endpoint_state_health' => 'healthy',
], [
    'endpoint_state_source' => 'sentinelone',
    'endpoint_state_status' => 'active',
    'endpoint_state_health' => 'healthy',
]]);

$current_network = endpointNormalizeNetworkObservation([
    'key' => 'ethernet-1',
    'name' => 'Ethernet 1',
    'type' => 'Ethernet',
    'virtual' => false,
    'mac' => '00:11:22:33:44:55',
    'ip_addresses' => ['192.0.2.10', '2001:db8::10'],
    'network_id' => 50,
    'vlan_id' => 20,
    'vlan_name' => 'Workstations',
    'discovery_protocol' => 'lldp',
    'switch_asset_id' => 500,
    'switch_interface_id' => 501,
    'switch_name' => 'switch-01',
    'switch_port' => 'Gi1/0/10',
]);
$historical_network = endpointNormalizeNetworkObservation([
    'key' => 'vpn-old',
    'name' => 'Old VPN Adapter',
    'type' => 'Virtual',
    'virtual' => true,
    'mac' => '00:aa:bb:cc:dd:ee',
    'ip_addresses' => ['198.51.100.10'],
    'discovery_protocol' => 'rmm',
]);

$sources = [];
$identities = [];
foreach ([
    'level' => 'level-device-101',
    'intune' => 'intune-device-101',
    'entra' => 'entra-device-101',
    'sentinelone' => 's1-agent-101',
] as $source => $external_id) {
    $sources[] = [
        'endpoint_state_source' => $source,
        'endpoint_state_external_id' => $external_id,
        'endpoint_state_status' => 'active',
        'endpoint_state_observed_at' => '2026-09-02 12:00:00',
    ];
    $identities[] = [
        'automation_mapping_source' => $source,
        'automation_mapping_external_id' => $external_id,
        'automation_mapping_state' => 'confirmed',
    ];
}

$record = [
    'summary' => $summary,
    'sources' => $sources,
    'identities' => $identities,
    'interfaces' => [[
        'interface_id' => 10,
        'interface_name' => 'Ethernet 1',
        'interface_type' => 'Ethernet',
        'interface_mac' => '00:11:22:33:44:55',
        'interface_ip' => '192.0.2.10',
        'connections' => [[
            'connected_interface_id' => 501,
            'connected_asset_id' => 500,
            'connected_asset_name' => 'switch-01',
        ]],
    ], [
        'interface_id' => 11,
        'interface_name' => 'VPN Adapter',
        'interface_type' => 'Virtual',
        'interface_mac' => '00:aa:bb:cc:dd:ee',
        'interface_ip' => '198.51.100.20',
        'connections' => [],
    ]],
    'network_current' => [[
        'network_observation_active' => 1,
        'network_observation_state' => $current_network['state'],
    ]],
    'network_history' => [[
        'network_observation_active' => 0,
        'network_observation_state' => $historical_network['state'],
    ]],
    'timeline' => [[
        'source' => 'intune',
        'type' => 'posture',
        'summary' => 'Secure Boot changed to enabled',
        'occurred_at' => '2026-09-02 12:00:00',
        'ticket_id' => 700,
        'document_id' => 800,
        'evidence_id' => 900,
    ]],
    'evidence' => [[
        'task_evidence_id' => 900,
        'task_evidence_type' => 'note',
        'task_name' => 'Verify endpoint posture',
        'ticket_id' => 700,
    ]],
    'related_tickets' => [[
        'ticket_id' => 700,
        'ticket_prefix' => 'N45-',
        'ticket_number' => 700,
    ]],
    'related_documentation' => [[
        'document_id' => 800,
        'document_name' => 'Endpoint baseline',
    ]],
];

$assertSame(
    [],
    endpointUnifiedRecordContractViolations($record, true),
    'A complete endpoint, posture, interface, topology and work-link record broke its contract'
);
$assertSame('Managed User', $record['summary']['assigned_user_name'], 'Assigned-user ownership was lost');
$assertSame('enabled', $record['summary']['secure_boot_state'], 'Secure Boot posture was lost');
$assertSame('healthy', $record['summary']['level_health'], 'Level health was lost');
$assertSame('healthy', $record['summary']['sentinelone_health'], 'SentinelOne health was lost');
$assertSame(false, $record['network_current'][0]['network_observation_state']['virtual'], 'Physical interface classification was lost');
$assertSame(true, $record['network_history'][0]['network_observation_state']['virtual'], 'Virtual interface history was lost');
$assertSame(20, $record['network_current'][0]['network_observation_state']['vlan_id'], 'VLAN relationship was lost');
$assertSame('lldp', $record['network_current'][0]['network_observation_state']['neighbor_protocol'], 'LLDP relationship was lost');
$assertSame('Gi1/0/10', $record['network_current'][0]['network_observation_state']['neighbor_port'], 'Switch port was lost');

$missing_posture = $record;
unset($missing_posture['summary']['secure_boot_state']);
$assertTrue(
    in_array('summary_field_missing:secure_boot_state', endpointUnifiedRecordContractViolations($missing_posture, true), true),
    'A missing endpoint posture field passed the unified record contract'
);

$missing_topology = $record;
unset($missing_topology['network_current'][0]['network_observation_state']['neighbor_port']);
$assertTrue(
    in_array('network_field_missing:neighbor_port', endpointUnifiedRecordContractViolations($missing_topology, true), true),
    'A topology observation without its switch-port contract passed'
);

$duplicate_interface = $record;
$duplicate_interface['interfaces'][] = $duplicate_interface['interfaces'][0];
$assertTrue(
    in_array('interface_duplicate', endpointUnifiedRecordContractViolations($duplicate_interface, true), true),
    'Duplicate editable interface rows passed the unified record contract'
);

$source_observation_view = file_get_contents(
    __DIR__ . '/../agent/includes/inc_asset_network_observations.php'
);
$assertTrue(
    is_string($source_observation_view)
        && str_contains($source_observation_view, 'Read-only discovery evidence; edit interfaces above.')
        && str_contains($source_observation_view, 'class="collapse"'),
    'Source observations are not clearly read-only and collapsed beneath editable interfaces'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Goal 5 unified endpoint and network record contract acceptance passed.\n";
