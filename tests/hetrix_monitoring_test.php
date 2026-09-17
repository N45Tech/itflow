<?php

$root = dirname(__DIR__);
$automation = file_get_contents($root . '/functions/automation.php');
$operations = file_get_contents($root . '/agent/operations.php');
$ticket = file_get_contents($root . '/agent/ticket.php');
$admin = file_get_contents($root . '/admin/integration_automation.php');
$builder = file_get_contents($root . '/deploy/n8n/build-workflows.mjs');
$readme = file_get_contents($root . '/deploy/n8n/README.md');
$migration = file_get_contents($root . '/n45/migrations/n45-0029-hetrix-monitoring-source.php');
$schema = file_get_contents($root . '/db.sql');

$failures = [];
$assertTrue = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assertTrue(str_contains($automation, "['netbox', 'checkmk', 'uptime_kuma']"),
    'Uptime Kuma is not retired at the event-ingestion boundary');
$assertTrue(str_contains($operations, "'hetrix' => 'HetrixTools'"),
    'Operations does not label HetrixTools incidents');
$assertTrue(str_contains($operations, "'infrastructure', 'hetrix', 'n8n'"),
    'Operations does not include HetrixTools in source health');
$assertTrue(!str_contains($ticket, "'uptime_kuma' => 'Uptime Kuma'"),
    'Ticket detail still exposes Uptime Kuma as an active source');
$assertTrue(str_contains($ticket, "'hetrix' => 'HetrixTools'"),
    'Ticket detail does not identify HetrixTools incidents');
$assertTrue(str_contains($admin, 'HetrixTools, Level.io'),
    'Event-ingestion administration does not describe HetrixTools coverage');
$assertTrue(str_contains($builder, "name: 'Hetrix Webhook'"),
    'The generated Operations broker does not have a dedicated HetrixTools webhook');
$assertTrue(str_contains($builder, "path: 'n45-hetrix-events'"),
    'The generated Operations broker has no stable HetrixTools webhook path');
$assertTrue(str_contains($builder, "body.monitor_id && body.monitor_status"),
    'The Operations broker does not recognize the HetrixTools uptime payload');
$assertTrue(!str_contains($builder, "source = 'uptime_kuma'"),
    'The n8n builder still generates an Uptime Kuma adapter');
$assertTrue(str_contains($builder, "['netbox', 'checkmk', 'uptime_kuma'].includes(source)"),
    'The n8n broker does not reject events from retired sources');
$assertTrue(str_contains($readme, 'N45 Hetrix Webhook'),
    'The deployment guide does not document the HetrixTools credential');
$assertTrue(!str_contains($readme, 'Uptime Kuma'),
    'The active deployment guide still documents Uptime Kuma');
$assertTrue(str_contains($migration, "VALUES ('hetrix')"),
    'The migration does not seed the HetrixTools source policy');
$assertTrue(str_contains($migration, "WHERE automation_policy_source = 'uptime_kuma'"),
    'The migration does not disable the Uptime Kuma source policy');
$assertTrue(str_contains($schema, "VALUES ('hetrix')"),
    'Fresh installs do not seed the HetrixTools source policy');
$assertTrue(!str_contains($schema, "VALUES ('uptime_kuma'"),
    'Fresh installs still seed a retired Uptime Kuma source policy');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "HetrixTools monitoring tests passed.\n";
