<?php

require_once __DIR__ . '/../functions/production_readiness.php';

$root = dirname(__DIR__);
$health_endpoint = file_get_contents($root . '/healthz.php');
$compose = file_get_contents($root . '/deploy/psa/compose.yml');
$dockerfile = file_get_contents($root . '/deploy/psa/Dockerfile');
$cron_entrypoint = file_get_contents($root . '/deploy/psa/cron-entrypoint.sh');
$failures = [];

$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};
$assertContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2026-09-22 12:00:00', $utc);
$assertTrue(n45ReadinessHeartbeatFresh('2026-09-22 11:56:00', 'UTC', $now),
    'A four-minute-old dispatcher heartbeat was rejected');
$assertTrue(!n45ReadinessHeartbeatFresh('2026-09-22 11:54:59', 'UTC', $now),
    'A stale dispatcher heartbeat was accepted');
$assertTrue(n45ReadinessHeartbeatFresh('2026-09-22 07:56:00', 'America/New_York', $now),
    'Application-local dispatcher time was not converted to an absolute timestamp');
$assertTrue(!n45ReadinessHeartbeatFresh('2026-09-22 12:02:00', 'UTC', $now),
    'A materially future dispatcher heartbeat was accepted');
$assertTrue(!n45ReadinessHeartbeatFresh('not-a-time', 'UTC', $now),
    'An invalid dispatcher heartbeat was accepted');
$assertTrue(!n45ReadinessHeartbeatFresh('2026-09-22 11:56:00', 'Invalid/Timezone', $now),
    'An invalid application timezone was accepted');

$assertContains('http_response_code(503);', $health_endpoint,
    'The readiness endpoint does not fail closed before application bootstrap');
$assertContains('register_shutdown_function', $health_endpoint,
    'The readiness endpoint cannot preserve a 503 when legacy config terminates');
$assertContains('require $config_path;', $health_endpoint,
    'The readiness endpoint does not load the deployed application configuration');
$assertContains('n45ProductionReadiness($mysqli ?? null, __DIR__)', $health_endpoint,
    'The readiness endpoint bypasses the shared production dependency probe');
$assertContains('{"status":"unavailable"}', $health_endpoint,
    'The readiness endpoint does not expose a stable unavailable response');
$assertNotContains("['failed']", $health_endpoint,
    'The public readiness endpoint exposes failed component details');
$assertNotContains('mysqli_error', $health_endpoint,
    'The public readiness endpoint can expose a database error');

$assertTrue(substr_count($compose, '/healthz.php') === 2,
    'Web and cron services do not share the application-aware readiness endpoint');
$assertContains('command: ["/usr/local/bin/itflow-cron"]', $compose,
    'The cron service does not use the non-blocking minute scheduler');
$assertContains('COPY deploy/psa/cron-entrypoint.sh /usr/local/bin/itflow-cron', $dockerfile,
    'The production image omits the cron scheduler');
$assertContains('php /var/www/html/cron/cron.php &', $cron_entrypoint,
    'The cron scheduler blocks the next minute behind the current dispatcher');
$assertContains('trap stop_children TERM INT', $cron_entrypoint,
    'The cron scheduler does not handle container termination');
$assertNotContains('while true; do php /var/www/html/cron/cron.php; sleep 60; done', $compose,
    'The blocking cron loop remains in the production Compose definition');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Production readiness contracts passed.\n";
