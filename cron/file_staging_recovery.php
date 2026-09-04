<?php

// Finalize committed upload journal entries left by interrupted requests.
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

$summary = fileStagingRecover(100);
$details = "batches {$summary['batches']}; finalized {$summary['finalized']}; "
    . "failed {$summary['failed']}; orphans removed {$summary['orphans_removed']}";
if ($summary['failed'] > 0) {
    logApp('File Staging', 'warning', "Embedded-image recovery run: $details");
}

echo "File staging recovery: $details.\n";
