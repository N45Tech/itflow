<?php

// Never run against a live database. The release harness owns this disposable fixture.
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') {
    exit("This test requires the disposable n45_ci_final database.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_set_charset($mysqli, 'utf8mb4');
date_default_timezone_set('UTC');
$q = static fn (string $sql) => automationDbQuery($sql, 'Checkmk lifecycle database test');
$scalar = static fn (string $sql) => mysqli_fetch_row($q($sql))[0];
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$q("INSERT INTO clients SET client_name='Checkmk CI', client_currency_code='USD', client_net_terms=30");
$mapped_client = intval(mysqli_insert_id($mysqli));
$q("INSERT INTO user_roles SET role_name='Checkmk CI API', role_is_admin=1");
$role = intval(mysqli_insert_id($mysqli));
$q("INSERT INTO users SET user_name='Checkmk CI API', user_email='checkmk-ci@example.invalid', user_type=1, user_status=1, user_role_id=$role");
$api_key_user_id = intval(mysqli_insert_id($mysqli));
$q("INSERT INTO api_keys SET api_key_name='Checkmk CI', api_key_secret=REPEAT('c',64), api_key_expire=DATE_ADD(CURRENT_DATE(),INTERVAL 1 YEAR), api_key_user_id=$api_key_user_id");
$api_key_id = intval(mysqli_insert_id($mysqli));
$session_is_admin = true;
$client_id = $mapped_client;
$settings_before = mysqli_fetch_assoc($q("SELECT config_module_enable_ticketing, config_enable_cron FROM settings WHERE company_id=1"));
$q("UPDATE settings SET config_module_enable_ticketing=1, config_enable_cron=0 WHERE company_id=1");
$q("UPDATE automation_event_policies SET automation_policy_enabled=1, automation_policy_ticket_enabled=1,
    automation_policy_auto_resolve=1, automation_policy_threshold_count=1 WHERE automation_policy_source='checkmk'");
putenv('N45_FEATURE_AUTOMATION=1');
$base = [
    'source' => 'checkmk', 'entity_type' => 'host', 'severity' => 'critical',
    'title' => 'Checkmk CI disk', 'description' => 'Disk full', 'state' => 'open',
    'contact_mode' => 'none', 'request_type_key' => 'monitoring-alert',
    'identity' => ['entity_type' => 'host', 'external_id' => 'checkmk-ci-host', 'external_name' => 'ops-ci',
        'client' => ['id' => $mapped_client], 'options' => ['create_client' => false, 'create_asset' => false]],
];
$process = static function (string $id, string $key, string $at, array $changes = []) use ($base): array {
    $queued = automationEventQueue(array_replace($base, ['event_id' => $id, 'incident_key' => $key, 'occurred_at' => $at], $changes));
    return automationProcessStoredEvent($queued['event_id']);
};
$opened = $process('cmk-ci-open', 'cmk-ci-disk', '2026-10-02T16:00:00Z');
$ticket = intval($opened['ticket_id'] ?? 0);
$assert($opened['action'] === 'created' && $ticket > 0, 'Checkmk failure did not create a ticket: ' . json_encode($opened));
$duplicate = automationEventQueue(array_replace($base, ['event_id' => 'cmk-ci-open', 'incident_key' => 'cmk-ci-disk', 'occurred_at' => '2026-10-02T16:00:00Z']));
$assert($duplicate['duplicate'] === true && $duplicate['ticket_id'] === $ticket, 'Duplicate delivery created another ticket');
$other = $process('cmk-ci-db', 'cmk-ci-mysql', '2026-10-02T16:00:01Z', ['title' => 'Checkmk CI MySQL']);
$assert(intval($other['ticket_id']) > 0 && intval($other['ticket_id']) !== $ticket, 'Two services overwrote the same incident');
$recovered = $process('cmk-ci-recovery', 'cmk-ci-disk', '2026-10-02T16:05:00Z', ['state' => 'resolved', 'description' => 'Disk recovered']);
$assert($recovered['ticket_id'] === $ticket && $recovered['action'] === 'resolved', 'Recovery did not resolve the original ticket: ' . json_encode($recovered));
$assert(intval($scalar("SELECT ticket_status FROM tickets WHERE ticket_id=$ticket")) === 4, 'Recovery left the ticket open');
$stale = $process('cmk-ci-delayed', 'cmk-ci-disk', '2026-10-02T16:01:00Z', ['description' => 'Delayed problem']);
$assert($stale['action'] === 'stale', 'A delayed problem reopened the recovered incident');
$again = $process('cmk-ci-new-outage', 'cmk-ci-disk', '2026-10-02T16:10:00Z', ['description' => 'Disk full again']);
$assert($again['action'] === 'created' && $again['ticket_id'] !== $ticket, 'A new outage reused a resolved ticket');
$gated_ticket = intval($again['ticket_id']);
$q("INSERT INTO ticket_customer_promises SET ticket_customer_promise_ticket_id=$gated_ticket,
    ticket_customer_promise_client_id=$mapped_client, ticket_customer_promise_summary='Verify client access',
    ticket_customer_promise_due_at='2026-10-03 16:00:00'");
$blocked = $process('cmk-ci-gated-recovery', 'cmk-ci-disk', '2026-10-02T16:11:00Z', ['state' => 'resolved', 'description' => 'Recovered with unfinished customer work']);
$assert($blocked['action'] === 'recovery_recorded' && $blocked['ticket_id'] === $gated_ticket,
    'Recovery bypassed unfinished customer work');
$assert(intval($scalar("SELECT ticket_status FROM tickets WHERE ticket_id=$gated_ticket")) !== 4,
    'Recovery closed a ticket with an outstanding customer promise');
$q("INSERT INTO automation_maintenance_windows SET automation_maintenance_source='checkmk',
    automation_maintenance_client_id=$mapped_client, automation_maintenance_starts_at='2026-10-02 16:15:00',
    automation_maintenance_ends_at='2026-10-02 16:30:00', automation_maintenance_reason='Disposable maintenance'");
$during = $process('cmk-ci-maintenance', 'cmk-ci-maintenance', '2026-10-02T16:20:00Z');
$assert($during['action'] === 'maintenance_suppressed' && intval($during['ticket_id']) === 0, 'Maintenance generated a ticket');
$q("UPDATE automation_event_policies SET automation_policy_threshold_count=2 WHERE automation_policy_source='checkmk'");
$threshold = $process('cmk-ci-threshold', 'cmk-ci-threshold', '2026-10-02T16:40:00Z');
$assert($threshold['action'] === 'threshold_waiting', 'Checkmk ignored the configured threshold');
$q("UPDATE automation_event_policies SET automation_policy_enabled=0, automation_policy_threshold_count=1 WHERE automation_policy_source='checkmk'");
$disabled = $process('cmk-ci-disabled', 'cmk-ci-disabled', '2026-10-02T16:45:00Z');
$assert($disabled['action'] === 'source_disabled', 'Checkmk ignored the source kill switch');
// Replaying the seed cannot undo an operator's disabled policy choice.
define('FROM_N45_DB_UPDATER', true);
require dirname(__DIR__) . '/n45/migrations/n45-0032-checkmk-monitoring-source.php';
$assert(intval($scalar("SELECT automation_policy_enabled FROM automation_event_policies WHERE automation_policy_source='checkmk'")) === 0,
    'Migration replay re-enabled a disabled source');
$q("UPDATE automation_event_policies SET automation_policy_enabled=1 WHERE automation_policy_source='checkmk'");
$ticketing_before = intval($settings_before['config_module_enable_ticketing']);
$cron_before = intval($settings_before['config_enable_cron']);
$q("UPDATE settings SET config_module_enable_ticketing=$ticketing_before, config_enable_cron=$cron_before WHERE company_id=1");
echo "Checkmk database lifecycle, deduplication, isolation, maintenance, threshold, stale-event and policy checks passed.\n";
