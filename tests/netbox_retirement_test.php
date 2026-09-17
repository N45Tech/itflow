<?php

$root = dirname(__DIR__);
$operations = file_get_contents($root . '/agent/operations.php');
$ticket = file_get_contents($root . '/agent/ticket.php');
$admin = file_get_contents($root . '/admin/integration_automation.php');
$builder = file_get_contents($root . '/deploy/n8n/build-workflows.mjs');
$readme = file_get_contents($root . '/deploy/n8n/README.md');

$failures = [];
$assertTrue = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assertTrue(!is_file($root . '/deploy/n8n/workflows/netbox-reconciliation.json'),
    'The retired NetBox workflow is still shipped');
$assertTrue(!str_contains($builder, 'NetBox'),
    'The n8n workflow builder still generates NetBox integration content');
$assertTrue(!str_contains($readme, 'NetBox'),
    'The active n8n deployment guide still documents NetBox');
$assertTrue(!str_contains($operations, 'https://netbox.n45tech.com'),
    'Operations still links to NetBox');
$assertTrue(str_contains($operations, "automation_incident_source NOT IN ('netbox', 'uptime_kuma')"),
    'Operations does not suppress retired NetBox incidents');
$assertTrue(str_contains($operations, "automation_mapping_source NOT IN ('netbox', 'uptime_kuma')"),
    'Operations does not suppress retired NetBox identity mappings');
$assertTrue(str_contains($operations, "automation_maintenance_source NOT IN ('netbox', 'uptime_kuma')"),
    'Operations still counts retired NetBox maintenance windows');
$assertTrue(str_contains($ticket, '!automationSourceIsRetired'),
    'Ticket detail still exposes retired integration cards');
$assertTrue(str_contains($admin, "automation_policy_source NOT IN ('netbox', 'uptime_kuma')"),
    'Admin integration policies still expose NetBox');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "NetBox retirement tests passed.\n";
