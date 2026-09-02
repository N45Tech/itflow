<?php

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

$breached = ticketOperationalReconcilePromises(500);
if ($breached) {
    logApp('Ticket Operations', 'warning', "$breached customer promise(s) became overdue");
}
echo "Ticket operations: $breached promise(s) marked breached.\n";
