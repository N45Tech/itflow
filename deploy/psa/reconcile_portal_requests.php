#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$allowed_modes = ['--dry-run', '--apply'];
if (count($arguments) !== 1 || !in_array($arguments[0], $allowed_modes, true)) {
    $script_name = basename((string) ($argv[0] ?? 'reconcile_portal_requests.php'));
    fwrite(STDERR, "Usage: php $script_name (--dry-run|--apply)\n");
    exit(2);
}
$dry_run = $arguments[0] === '--dry-run';
$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/sanitize.php';
require $app_root . '/functions/runbooks.php';
require $app_root . '/functions/portal_requests.php';

$lock_name = 'n45-itflow-reconcile-portal-requests';
$lock_acquired = false;
$exit_status = 0;

try {
    $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)"));
    if (intval($lock[0] ?? 0) !== 1) {
        throw new RuntimeException('Another portal request reconciliation is already running');
    }
    $lock_acquired = true;

    $result = portalRequestReconcileStarters(0, $dry_run);
    $mode = $dry_run ? 'DRY-RUN (rolled back)' : 'APPLIED';
    fwrite(STDOUT, "Portal request reconciliation $mode\n");
    fwrite(STDOUT, '  Installed drafts: ' . intval($result['installed']) . "\n");
    fwrite(STDOUT, '  Published releases: ' . intval($result['published']) . "\n");
    fwrite(STDOUT, '  Reused releases: ' . intval($result['reused']) . "\n");
    fwrite(STDOUT, '  Draft/fail-closed: ' . intval($result['draft']) . "\n");
    foreach ($result['items'] as $key => $item) {
        $line = '  - ' . $key . ': ' . $item['status'];
        if (!empty($item['reason'])) {
            $line .= ' (' . $item['reason'] . ')';
        }
        fwrite(STDOUT, $line . "\n");
    }
    if (intval($result['draft']) > 0) {
        fwrite(STDERR, "One or more starters remain unavailable. Reconcile canonical templates and resolve the listed compatibility issue before canary submission.\n");
        $exit_status = 3;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Portal request reconciliation failed: ' . $exception->getMessage() . "\n");
    $exit_status = 1;
} finally {
    if ($lock_acquired) {
        try {
            $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')");
        } catch (Throwable $ignored) {
            fwrite(STDERR, "Warning: could not release the portal request reconciliation lock.\n");
            $exit_status = $exit_status ?: 1;
        }
    }
}

exit($exit_status);
