<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/integration_identity.php';
require_once __DIR__ . '/../functions/automation.php';
require_once __DIR__ . '/../functions/automation_events.php';

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

$assertThrows = function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (InvalidArgumentException $e) {
        // Expected validation failure.
    }
};

$event_a = automationEventEnvelope([
    'source' => 'SentinelOne',
    'event_id' => 'delivery-001',
    'incident_key' => 'threat:abc123',
    'state' => 'open',
    'title' => 'Threat detected',
    'severity' => 'critical',
    'description' => 'Malware was quarantined.',
    'occurred_at' => '2026-08-31T12:00:00Z',
    'assigned_to' => 7,
    'category_id' => 12,
    'contact_id' => 19,
    'contact_mode' => 'none',
    'request_type_key' => 'Security Alert',
    'identity' => [
        'entity_type' => 'device',
        'external_id' => 'endpoint-101',
        'external_name' => 'WKSTN-101',
    ],
    'metadata' => [
        'agent' => ['version' => '24.1', 'accessToken' => 'do-not-store'],
        'authorization' => 'Bearer do-not-store',
        'detection_id' => 'abc123',
    ],
]);
$event_b = automationEventEnvelope([
    'metadata' => [
        'detection_id' => 'abc123',
        'authorization' => 'Bearer a-different-secret',
        'agent' => ['accessToken' => 'another-secret', 'version' => '24.1'],
    ],
    'occurred_at' => '2026-08-31T12:01:00Z',
    'description' => 'Malware was quarantined.',
    'severity' => 'critical',
    'assigned_to' => 7,
    'category_id' => 12,
    'contact_id' => 19,
    'contact_mode' => 'none',
    'request_type_key' => 'Security Alert',
    'title' => 'Threat detected',
    'state' => 'open',
    'incident_key' => 'threat:abc123',
    'event_id' => 'delivery-002',
    'source' => 'sentinelone',
    'identity' => [
        'external_name' => 'WKSTN-101',
        'external_id' => 'endpoint-101',
        'entity_type' => 'device',
    ],
]);

$document_a = automationEventDocument($event_a);
$document_b = automationEventDocument($event_b);
$assertSame('sentinelone', $event_a['source'], 'Event source was not normalized');
$assertSame(7, $event_a['assigned_to'], 'Event assignee routing was not normalized');
$assertSame(12, $event_a['category_id'], 'Event category routing was not normalized');
$assertSame(19, $event_a['contact_id'], 'Event contact routing was not normalized');
$assertSame('none', $event_a['contact_mode'], 'Event contact mode was not normalized');
$assertSame('security-alert', $event_a['request_type_key'], 'Event request type was not normalized');
$assertSame($document_a['fingerprint'], $document_b['fingerprint'], 'Delivery ids, timestamps, key order, or secret values changed the semantic fingerprint');
$assertSame(false, str_contains($document_a['payload'], 'do-not-store'), 'A secret value remained in the retained event payload');
$assertTrue(str_contains($document_a['payload'], '[REDACTED]'), 'Secret-bearing fields were not visibly redacted');
$assertTrue(str_contains($document_a['payload'], 'detection_id'), 'Operational metadata was removed from the retained event payload');

$event_changed = $event_a;
$event_changed['description'] = 'Malware remains active.';
$assertSame(false, hash_equals($document_a['fingerprint'], automationEventDocument($event_changed)['fingerprint']), 'A material event change reused the old fingerprint');

$redacted = automationEventRedact([
    'password' => 'secret',
    'nested' => ['webhookSecret' => 'secret', 'hostname' => 'WKSTN-101'],
]);
$assertSame('[REDACTED]', $redacted['password'], 'Top-level password was not redacted');
$assertSame('[REDACTED]', $redacted['nested']['webhookSecret'], 'Nested webhook secret was not redacted');
$assertSame('WKSTN-101', $redacted['nested']['hostname'], 'Allowed event data was redacted');

$defaults = automationEventPolicyDefaults('CheckMK');
$assertSame('checkmk', $defaults['source'], 'Policy source was not normalized');
$assertSame(1, $defaults['threshold_count'], 'Default policy should ticket on the first occurrence');
$assertSame(5, $defaults['max_attempts'], 'Default retry limit changed');
$assertSame(30, $defaults['payload_retention_days'], 'Default payload retention changed');

date_default_timezone_set('America/New_York');
$assertSame('2026-08-31 08:00:00', automationEventDateTime('2026-08-31T12:00:00Z'), 'Event timestamp was not converted to the application timezone');
date_default_timezone_set('UTC');

$assertThrows(static fn () => automationEventEnvelope([
    'source' => 'checkmk',
    'event_id' => '',
    'incident_key' => 'host:123',
]), 'An event without an event id was accepted');
$assertThrows(static fn () => automationEventEnvelope([
    'source' => 'checkmk',
    'event_id' => 'event-1',
    'incident_key' => 'host:123',
    'state' => 'closed',
]), 'An unsupported event state was accepted');
$assertThrows(static fn () => automationEventEnvelope([
    'source' => 'invalid source',
    'event_id' => 'event-1',
    'incident_key' => 'host:123',
]), 'An invalid source was accepted');
$assertThrows(static fn () => automationEventEnvelope([
    'source' => 'checkmk',
    'event_id' => 'event-1',
    'incident_key' => 'host:123',
    'contact_mode' => 'guess',
]), 'An unsupported contact routing mode was accepted');

$migration = file_get_contents(__DIR__ . '/../n45/migrations/n45-0009-automation-event-lifecycle.php');
$endpoint = file_get_contents(__DIR__ . '/../api/v1/integrations/automation/event.php');
$cron = file_get_contents(__DIR__ . '/../includes/cron_jobs.php');
$level = file_get_contents(__DIR__ . '/../functions/level.php');
$level_webhook = file_get_contents(__DIR__ . '/../api/v1/integrations/level/webhook.php');
$assertTrue(str_contains($migration, 'automation_event_fingerprint'), 'Migration does not persist event fingerprints');
$assertTrue(str_contains($migration, 'automation_maintenance_windows'), 'Migration does not create maintenance windows');
$assertTrue(str_contains($migration, 'automation_event_policies'), 'Migration does not create event policies');
$assertTrue(str_contains($endpoint, 'automationEventQueue'), 'Automation API bypasses the durable queue');
$assertTrue(str_contains($cron, 'automation_event_processor'), 'Automation retry processor is not registered');
$assertTrue(str_contains($level, 'automationMirrorProcessedEvent'), 'Level alerts are not mirrored into the common event model');
$assertTrue(str_contains($level_webhook, 'automationEventRedact'), 'Level webhook payload retention is not redacted');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Automation event normalization tests passed.\n";
