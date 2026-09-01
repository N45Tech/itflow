<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';
require_once __DIR__ . '/../functions/endpoint.php';
require_once __DIR__ . '/../functions/device_source.php';

$failures = [];
$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertThrows = function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (InvalidArgumentException $e) {
        // Expected.
    }
};

$assertSame('intune', deviceSourceName('INTUNE'), 'Intune source was not normalized');
$assertSame('entra', deviceSourceName('entra'), 'Entra source was not accepted');
$assertSame('sentinelone', deviceSourceName('sentinelone'), 'SentinelOne source was not accepted');
$assertThrows(fn() => deviceSourceName('level'), 'An unsupported device source was accepted');
$assertThrows(fn() => deviceSourceScopeId("tenant\nother"), 'A control character was accepted in a scope id');
$assertThrows(fn() => deviceSourceCycleId('../unsafe'), 'An unsafe cycle id was accepted');

$redacted = deviceSourceRedactError(
    'Bearer abc.def ApiToken top-secret https://source.test/?access_token=secret&code=secret '
    . '{"client_secret":"secret","password":"secret"}'
);
foreach (['abc.def', 'top-secret', 'access_token=secret', 'code=secret', '"secret"'] as $secret) {
    $assertSame(false, str_contains($redacted, $secret), "Redaction retained $secret");
}

$asset = deviceSourceAssetInput([
    'name' => 'workstation-01',
    'serial' => 'SERIAL-1',
    'api_key' => 'must-not-pass',
    'last_logged_in_user' => 'private-user',
]);
$assertSame('workstation-01', $asset['name'], 'Asset name was removed');
$assertSame(false, array_key_exists('api_key', $asset), 'Asset API key passed the allowlist');
$assertSame(false, array_key_exists('last_logged_in_user', $asset), 'Private source user passed the asset allowlist');

$snapshot = deviceSourceSnapshotFacts('intune', 'device-1', [
    'assigned_user' => ['id' => 'user-1', 'name' => 'Example User', 'email' => 'User@Example.test'],
    'compliance_state' => 'nonCompliant',
    'is_encrypted' => true,
    'secure_boot_enabled' => true,
    'operating_system' => 'Windows 11',
    'api_key' => 'must-not-pass',
    'last_logged_in_user' => 'private-user',
], [[
    'key' => 'wifi',
    'name' => 'Wi-Fi',
    'type' => 'Wireless',
    'mac' => '00-11-22-33-44-55',
    'ip_addresses' => ['192.0.2.10', 'fe80::1'],
]]);
$assertSame('noncompliant', $snapshot['compliance_state'], 'Compliance was not normalized');
$assertSame('encrypted', $snapshot['encryption_state'], 'Encryption was not normalized');
$assertSame('enabled', $snapshot['secure_boot_state'], 'Secure Boot was not normalized');
$assertSame('user@example.test', $snapshot['assigned_user_email'], 'Assigned-user email was not normalized');
$assertSame('00:11:22:33:44:55', $snapshot['network_interfaces'][0]['mac'], 'Interface MAC was not normalized');
$assertSame(['192.0.2.10'], $snapshot['network_interfaces'][0]['ipv4'], 'IPv4 address was not normalized');
$assertSame(['fe80::1'], $snapshot['network_interfaces'][0]['ipv6'], 'IPv6 address was not normalized');
$snapshot_json = json_encode($snapshot);
$assertSame(false, str_contains($snapshot_json, 'must-not-pass'), 'A source secret entered the snapshot');
$assertSame(false, str_contains($snapshot_json, 'private-user'), 'Unapproved source PII entered the snapshot');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Device source normalization tests passed.\n";
