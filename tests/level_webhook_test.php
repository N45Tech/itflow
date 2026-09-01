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

$definitions = [
    'alert_active' => ['resource' => 'alert', 'action' => 'active'],
    'alert_resolved' => ['resource' => 'alert', 'action' => 'resolved'],
    'device_created' => ['resource' => 'device', 'action' => 'created'],
    'device_updated' => ['resource' => 'device', 'action' => 'updated'],
    'device_deleted' => ['resource' => 'device', 'action' => 'deleted'],
    'group_created' => ['resource' => 'group', 'action' => 'created'],
    'group_updated' => ['resource' => 'group', 'action' => 'updated'],
    'group_deleted' => ['resource' => 'group', 'action' => 'deleted'],
];

$assertSame(array_keys($definitions), levelAllowedWebhookEvents(), 'Allowed event list changed');

foreach ($definitions as $event_type => $definition) {
    $assertSame($definition, levelWebhookEventDefinition($event_type), "$event_type has the wrong resource route");

    $data = ['id' => 'resource-123'];
    if ($definition['resource'] === 'alert') {
        $data['device_id'] = 'device-456';
    }

    $event = [
        'event_type' => $event_type,
        'event_id' => '550e8400-e29b-41d4-a716-446655440000',
        'occurred_at' => '2026-08-27T13:00:00.000Z',
        'data' => $data,
    ];

    $assertSame(null, levelWebhookValidationError($event), "$event_type should be accepted");
}

$invalid = [
    'event_type' => 'device_updated',
    'event_id' => '550e8400-e29b-41d4-a716-446655440000',
    'occurred_at' => '2026-08-27T13:00:00.000Z',
    'data' => ['id' => 'device-456'],
];

$case = $invalid;
$case['event_type'] = 'contact_updated';
$assertSame('Unsupported Level event type', levelWebhookValidationError($case), 'Unknown resource event was accepted');

$case = $invalid;
$case['event_id'] = 'not-a-uuid';
$assertSame('Invalid Level event id', levelWebhookValidationError($case), 'Invalid event id was accepted');

$case = $invalid;
$case['occurred_at'] = 'not-a-date';
$assertSame('Invalid Level event timestamp', levelWebhookValidationError($case), 'Invalid timestamp was accepted');

$case = $invalid;
$case['data'] = [];
$assertSame('Level event is missing its resource id', levelWebhookValidationError($case), 'Missing resource id was accepted');

$case = $invalid;
$case['event_type'] = 'alert_active';
$assertSame('Level alert event is missing its device id', levelWebhookValidationError($case), 'Alert without a device id was accepted');

$assertSame(true, levelWebhookEventIsOlder('2026-08-27T12:59:59Z', '2026-08-27 13:00:00'), 'Older alert event was not detected');
$assertSame(false, levelWebhookEventIsOlder('2026-08-27T13:00:01Z', '2026-08-27 13:00:00'), 'Newer alert event was marked stale');

$body = '{"event_type":"device_updated"}';
$secret = 'level-webhook-test-secret';
$signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
$assertSame(true, levelWebhookSignatureIsValid($body, $signature, $secret), 'Valid webhook signature was rejected');
$assertSame(false, levelWebhookSignatureIsValid($body . ' ', $signature, $secret), 'Tampered webhook body was accepted');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Level webhook routing tests passed.\n";
