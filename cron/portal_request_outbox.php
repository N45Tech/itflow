<?php

// Deliver one durable portal-request custom action per run. The dispatcher
// calls this every minute; stale Processing leases are reclaimed after ten
// minutes, so a process crash cannot permanently lose a committed ticket event.
chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$cron_lock_script = __FILE__;
require_once 'includes/cron_lock.php';
require_once '../config.php';
require_once '../includes/inc_set_timezone.php';
require_once '../functions.php';

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron
    FROM settings WHERE company_id = 1 LIMIT 1"));
if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}

$result = portalRequestProcessCustomActionOutbox();
$status = (string) ($result['status'] ?? 'failed');
$dispatch_id = intval($result['dispatch_id'] ?? 0);
if ($status === 'failed') {
    logApp('Portal Request', 'warning', "Custom-action outbox dispatch $dispatch_id failed and was scheduled for retry");
}

echo "Portal request custom-action outbox: $status"
    . ($dispatch_id ? " dispatch $dispatch_id" : '') . ".\n";
