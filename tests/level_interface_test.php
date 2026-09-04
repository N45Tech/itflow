<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/level.php';

$failures = [];

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$device = [
    'network_interfaces' => [
        [
            'interface' => '12',
            'label' => 'Wi-Fi',
            'description' => 'Intel(R) Wi-Fi 6E AX211',
            'mac_address' => '00-11-22-33-44-55',
            'ip_addresses' => ['127.0.0.1', '192.168.1.25', '192.168.1.26', 'fe80::1234%12'],
            'gateway' => '192.168.1.1',
            'dhcp_server' => '192.168.1.1',
            'dns_servers' => '192.168.1.1, 1.1.1.1',
            'domain' => 'example.test',
            'vlan_id' => 30,
            'vlan_name' => 'Workstations',
            'neighbor_protocol' => 'lldp',
            'neighbor_name' => 'switch-01',
            'neighbor_chassis_id' => '00:aa:bb:cc:dd:ee',
            'neighbor_port' => 'Gi1/0/12',
        ],
        [
            'interface' => '20',
            'label' => 'Tailscale',
            'description' => 'Tailscale virtual tunnel',
            'mac_address' => '02:00:00:00:00:20',
            'ip_addresses' => ['100.64.0.10'],
        ],
        [
            'interface' => '1',
            'label' => 'Loopback',
            'description' => 'Software Loopback Interface',
            'mac_address' => '00:00:00:00:00:00',
            'ip_addresses' => ['127.0.0.1', '::1'],
        ],
    ],
];

$interfaces = levelNormalizeNetworkInterfaces($device);
$assertSame(2, count($interfaces), 'Loopback-only interface was not filtered');
$assertSame('interface:12', $interfaces[0]['key'], 'Level interface identity was not preserved');
$assertSame('Wi-Fi', $interfaces[0]['name'], 'Interface label was not used as its ITFlow name');
$assertSame('Wireless', $interfaces[0]['type'], 'Wireless adapter type was not inferred');
$assertSame('00:11:22:33:44:55', $interfaces[0]['mac'], 'MAC address was not normalized');
$assertSame('192.168.1.25', $interfaces[0]['ipv4'], 'Primary IPv4 address was not selected');
$assertSame('fe80::1234', $interfaces[0]['ipv6'], 'Scoped IPv6 address was not normalized');
$assertSame(true, $interfaces[0]['primary'], 'Routed physical interface was not selected as primary');
$assertSame(30, $interfaces[0]['vlan_id'], 'Level VLAN id was not retained');
$assertSame('lldp', $interfaces[0]['neighbor_protocol'], 'Level discovery protocol was not retained');
$assertSame('Gi1/0/12', $interfaces[0]['neighbor_port'], 'Level neighbor port was not retained');
$assertSame(false, $interfaces[1]['primary'], 'Virtual interface was selected as primary');
$assertContains('Gateway: 192.168.1.1', $interfaces[0]['notes'], 'Gateway was not recorded in notes');
$assertContains('Additional addresses: 192.168.1.26', $interfaces[0]['notes'], 'Additional address was not recorded in notes');

$duplicate_keys = levelNormalizeNetworkInterfaces([
    'network_interfaces' => [
        ['interface' => '7', 'label' => 'Ethernet A', 'mac_address' => '00:11:22:33:44:66', 'ip_addresses' => []],
        ['interface' => '7', 'label' => 'Ethernet B', 'mac_address' => '00:11:22:33:44:77', 'ip_addresses' => []],
    ],
]);
$assertSame('interface:7', $duplicate_keys[0]['key'], 'First repeated Level interface key changed');
$assertSame('interface:7#2', $duplicate_keys[1]['key'], 'Repeated Level interface key was not made unique');

$fallback_key = levelNormalizeNetworkInterfaces([
    'network_interfaces' => [[
        'label' => 'Ethernet',
        'mac_address' => 'AA-BB-CC-DD-EE-FF',
        'ip_addresses' => ['169.254.10.20/16'],
    ]],
]);
$assertSame('mac:aa:bb:cc:dd:ee:ff', $fallback_key[0]['key'], 'MAC fallback identity was not used');
$assertSame('169.254.10.20', $fallback_key[0]['ipv4'], 'CIDR suffix was not normalized');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Level interface normalization tests passed.\n";
