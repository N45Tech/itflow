<?php

require_once __DIR__ . '/../n45/bootstrap.php';
require_once __DIR__ . '/n45_schema.php';

/**
 * The dispatcher stores application-local wall time in settings. Convert that
 * value with the configured timezone before comparing absolute timestamps.
 */
function n45ReadinessHeartbeatFresh(
    ?string $last_dispatch_at,
    string $timezone,
    DateTimeImmutable $now,
    int $maximum_age_seconds = 300
): bool {
    if ($last_dispatch_at === null || $last_dispatch_at === '' || $maximum_age_seconds < 1) {
        return false;
    }

    try {
        $application_timezone = new DateTimeZone($timezone);
    } catch (Throwable $e) {
        return false;
    }

    $last_dispatch = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $last_dispatch_at,
        $application_timezone
    );
    $parse_errors = DateTimeImmutable::getLastErrors();
    if ($last_dispatch === false
        || ($parse_errors !== false
            && (intval($parse_errors['warning_count']) > 0 || intval($parse_errors['error_count']) > 0))
        || $last_dispatch->format('Y-m-d H:i:s') !== $last_dispatch_at) {
        return false;
    }

    $age_seconds = $now->getTimestamp() - $last_dispatch->getTimestamp();

    // A small future allowance avoids false failures while hosts converge on
    // their time source, but a materially future heartbeat is not trustworthy.
    return $age_seconds >= -60 && $age_seconds <= $maximum_age_seconds;
}

/**
 * Evaluate only release-critical, non-secret dependencies. The caller may log
 * failed check names internally, but the public endpoint returns one status.
 */
function n45ProductionReadiness($mysqli, string $application_root, ?DateTimeImmutable $now = null): array
{
    $checks = [
        'bootstrap' => $mysqli instanceof mysqli,
        'database' => false,
        'migrations' => false,
        'storage' => is_dir($application_root . '/uploads')
            && is_writable($application_root . '/uploads'),
        'cron' => false,
    ];

    if ($checks['bootstrap']) {
        try {
            $database_result = mysqli_query($mysqli, 'SELECT 1 AS ready');
            $database_row = $database_result ? mysqli_fetch_assoc($database_result) : false;
            $checks['database'] = intval($database_row['ready'] ?? 0) === 1;

            $migration_status = n45MigrationStatus($mysqli);
            $checks['migrations'] = ($migration_status['state'] ?? '') === 'current';

            $settings_result = mysqli_query(
                $mysqli,
                'SELECT config_enable_cron, config_cron_last_dispatch_at, config_timezone '
                . 'FROM settings WHERE company_id = 1 LIMIT 1'
            );
            $settings = $settings_result ? mysqli_fetch_assoc($settings_result) : false;
            if (is_array($settings) && intval($settings['config_enable_cron'] ?? 0) === 1) {
                $checks['cron'] = n45ReadinessHeartbeatFresh(
                    isset($settings['config_cron_last_dispatch_at'])
                        ? (string) $settings['config_cron_last_dispatch_at']
                        : null,
                    (string) ($settings['config_timezone'] ?? ''),
                    $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
            }
        } catch (Throwable $e) {
            // A readiness probe must fail closed without exposing database or
            // filesystem details to the unauthenticated caller.
        }
    }

    $failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));

    return [
        'ready' => $failed === [],
        'checks' => $checks,
        'failed' => $failed,
    ];
}
