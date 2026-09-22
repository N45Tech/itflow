<?php

declare(strict_types=1);

ini_set('display_errors', '0');
http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$initial_buffer_level = ob_get_level();
ob_start();
$response_sent = false;

$emit_response = static function (bool $ready) use (&$response_sent, $initial_buffer_level): void {
    if ($response_sent) {
        return;
    }
    while (ob_get_level() > $initial_buffer_level) {
        ob_end_clean();
    }
    http_response_code($ready ? 200 : 503);
    echo $ready ? '{"status":"ready"}' : '{"status":"unavailable"}';
    $response_sent = true;
};

register_shutdown_function(static function () use (&$response_sent, $emit_response): void {
    if (!$response_sent) {
        $emit_response(false);
    }
});

try {
    $config_path = __DIR__ . '/config.php';
    if (!is_file($config_path) || !is_readable($config_path)) {
        throw new RuntimeException('Application configuration is unavailable');
    }

    // Legacy generated config files terminate when connection setup fails.
    // The pre-set 503 status and shutdown guard preserve fail-closed behavior.
    mysqli_report(MYSQLI_REPORT_OFF);
    require $config_path;
    require_once __DIR__ . '/functions/production_readiness.php';

    $readiness = n45ProductionReadiness($mysqli ?? null, __DIR__);
    $emit_response($readiness['ready'] === true);
} catch (Throwable $e) {
    error_log('N45 readiness probe failed closed (' . get_class($e) . ')');
    $emit_response(false);
}
