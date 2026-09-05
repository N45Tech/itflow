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
$assertOrder = function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $first_position = strpos($haystack, $first);
    $second_position = strpos($haystack, $second);
    if ($first_position === false || $second_position === false || $first_position >= $second_position) {
        $failures[] = $message;
    }
};

$service = $read('functions/device_source.php');
$identity = $read('functions/integration_identity.php');
$loader = $read('functions.php');
$manifest = require $root . '/n45/manifest.php';
$api = $read('api/v1/integrations/device_source/update.php');
$health_api = $read('api/v1/integrations/device_source/read.php');
$rbac = $read('api/v1/enforce_api_rbac.php');
$legacy_updates = implode('', array_map(
    static fn(string $path): string => (string) file_get_contents($path),
    glob($root . '/admin/database_updates/*.php') ?: []
));

foreach ([
    'deviceSourcePublish',
    'deviceSourceAssertAutoCreateSafety',
    'deviceSourceComplete',
    'deviceSourceRecordFailure',
    'deviceSourceHealthRows',
    'deviceSourceRedactError',
] as $function) {
    $assertContains("function $function", $service, "Device source service is missing $function");
}
$assertContains("['intune', 'entra', 'sentinelone']", $service, 'Device source allowlist is missing');
$assertContains("automation_mapping_external_parent_id = '\$scope_sql'", $service, 'Retirement is not source-scope constrained');
$assertContains('automation_mapping_client_id = $client_id', $service, 'Retirement is not client constrained');
$assertContains("automation_mapping_last_synced_at < '\$cutoff_sql'", $service, 'Retirement does not use the cycle cutoff');
$assertContains('integrationIdentityFindMapping($source, \'device\', $external_id)', $service, 'Retirement does not re-read candidates');
$assertContains('integrationIdentityRetireMapping(', $service, 'Missing source identities are not retired through the safe cascade');
$assertContains('retirement guard blocked', $service, 'Unexpected source shrinkage is not guarded');
$assertContains("integrationIdentityAcquireLock(\$source, 'sync_scope', \$scope_id)", $service, 'A source scope can complete concurrently');
$assertContains("'external_parent_id' => \$scope_id", $service,
    'The initial device identity write does not include its tenant/site scope');
$assertContains("'last_seen_at' => \$mapping_last_seen_at", $service,
    'The initial device identity write does not use the source observation watermark');
$assertContains("\$before['seen'] !== \$reported_count", $service,
    'Device source completion can silently publish mismatched coverage');
$assertContains('$repair_missing_parent', $identity,
    'Older unscoped device identities have no constrained upgrade repair');
$assertContains("\$existing_client_id === \$incoming_client_id", $identity,
    'The unscoped device identity repair is not constrained to the same client');
$assertContains("(string) (\$existing['automation_mapping_external_parent_id'] ?? '') === ''", $identity,
    'The unscoped device identity repair can overwrite an existing tenant/site scope');
$assertOrder('integrationIdentityUpsertMapping([', 'endpointReconcileAssetSourceUnlocked([', $service, 'Endpoint posture is published before its identity mapping');
$assertContains('integrationIdentityRecordSnapshot([', $service, 'Source snapshots are not persisted');
$assertContains("integrationIdentityFindMapping(\$source, 'sync_scope', \$scope_id)", $service,
    'Automatic creation does not require an explicitly mapped source scope');
$assertContains("automation_mapping_state'] ?? '') !== 'automatic'", $service,
    'Automatic creation does not require a clean completed burn-in scope');
$assertContains("\$source_status !== 'active'", $service,
    'Automatic creation accepts an unclean source status');
$assertContains('automationFindAsset($asset, $client_id, $location_id)', $service,
    'Automatic creation bypasses deterministic existing-asset matching');
$assertContains("endpoint_state_client_id <> \$client_id", $service,
    'Automatic creation does not reject a foreign-client source identity');
$assertContains("integrationIdentityAcquireLock(\$source, 'sync_scope', \$scope_id)", $service,
    'Automatic creation is not serialized with source-scope completion');
$assertContains("n45RequireModule('endpoint');", $loader, 'Device source service is not loaded through the N45 endpoint module');
$assertNotContains("require_once __DIR__ . '/functions/device_source.php';", $loader, 'Device source service bypasses the N45 module boundary');
if (($manifest['modules']['endpoint']['runtime_files'] ?? []) !== [
    'functions/endpoint.php',
    'functions/device_source.php',
]) {
    $failures[] = 'The endpoint module does not load the device source service after canonical endpoint primitives';
}
$assertContains("'device_source' => 'module_support'", $rbac, 'Device source API is not mapped to support RBAC');
$assertContains("\$action === 'publish'", $api, 'Publish action is not exposed');
$assertContains("\$action === 'complete'", $api, 'Completion action is not exposed');
$assertContains("\$action === 'failure'", $api, 'Failure action is not exposed');
$assertContains('deviceSourceHealthRows(', $health_api, 'Health read API is not wired');
$assertNotContains('device_source', $legacy_updates, 'Device source work added a legacy numbered migration');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Device source wiring tests passed.\n";
