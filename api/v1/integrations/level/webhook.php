<?php

/*
 * Public Level.io webhook endpoint.
 *
 * This endpoint intentionally does not use ITFlow API-key authentication. Its
 * authentication is Level's HMAC signature over the exact raw request body.
 * Valid events are persisted idempotently and processed by cron so Level gets a
 * response quickly even when a device lookup or ticket creation takes time.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function levelWebhookRespond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    levelWebhookRespond(405, ['accepted' => false, 'error' => 'Method not allowed']);
}

$content_length = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > 1024 * 1024) {
    levelWebhookRespond(413, ['accepted' => false, 'error' => 'Payload too large']);
}

$raw_body = file_get_contents('php://input', false, null, 0, (1024 * 1024) + 1);
if (!is_string($raw_body) || $raw_body === '' || strlen($raw_body) > 1024 * 1024) {
    levelWebhookRespond(400, ['accepted' => false, 'error' => 'A JSON request body is required']);
}

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../functions.php';

if (!n45FeatureEnabled('level')) {
    levelWebhookRespond(503, ['accepted' => false, 'error' => 'Level integration is disabled']);
}

$settings_sql = mysqli_query($mysqli, "SELECT config_level_enable, config_level_webhook_secret, config_timezone
    FROM settings WHERE company_id = 1 LIMIT 1");
$settings = $settings_sql ? mysqli_fetch_assoc($settings_sql) : [];

if (empty($settings['config_level_enable']) || empty($settings['config_level_webhook_secret'])) {
    levelWebhookRespond(503, ['accepted' => false, 'error' => 'Level integration is not available']);
}

if (!empty($settings['config_timezone'])) {
    date_default_timezone_set($settings['config_timezone']);
    $level_webhook_now = new DateTimeImmutable('now', new DateTimeZone($settings['config_timezone']));
    $level_webhook_offset = levelDbEscape($level_webhook_now->format('P'));
    mysqli_query($mysqli, "SET time_zone = '$level_webhook_offset'");
}

$signature = trim((string) ($_SERVER['HTTP_X_LEVEL_SIGNATURE'] ?? ''));
if (!levelWebhookSignatureIsValid($raw_body, $signature, $settings['config_level_webhook_secret'])) {
    levelWebhookRespond(401, ['accepted' => false, 'error' => 'Invalid signature']);
}

try {
    $event = json_decode($raw_body, true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    levelWebhookRespond(400, ['accepted' => false, 'error' => 'Invalid JSON']);
}

$validation_error = is_array($event)
    ? levelWebhookValidationError($event)
    : 'Invalid Level event envelope';
if ($validation_error !== null) {
    levelWebhookRespond(422, ['accepted' => false, 'error' => $validation_error]);
}

$event_type = (string) $event['event_type'];
$event_id = (string) $event['event_id'];
$occurred_at = levelDateTimeValue($event['occurred_at']);

$event_id_sql = levelDbEscape($event_id);
$event_type_sql = levelDbEscape($event_type);
$occurred_at_sql = levelNullableSql($occurred_at);
$redacted_event = automationEventRedact($event);
$redacted_payload = json_encode(
    $redacted_event,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($redacted_payload === false) {
    levelWebhookRespond(500, ['accepted' => false, 'error' => 'Could not normalize event']);
}
$payload_sql = levelDbEscape($redacted_payload);

$insert = mysqli_query($mysqli, "INSERT INTO level_webhook_events SET
    level_webhook_event_id = '$event_id_sql',
    level_webhook_event_type = '$event_type_sql',
    level_webhook_occurred_at = $occurred_at_sql,
    level_webhook_payload = '$payload_sql'
    ON DUPLICATE KEY UPDATE
    level_webhook_delivery_count = level_webhook_delivery_count + 1,
    level_webhook_last_received_at = NOW()");

if (!$insert) {
    levelWebhookRespond(500, ['accepted' => false, 'error' => 'Could not queue event']);
}

$duplicate = mysqli_affected_rows($mysqli) !== 1;
levelWebhookRespond(202, ['accepted' => true, 'duplicate' => $duplicate, 'event_id' => $event_id]);
