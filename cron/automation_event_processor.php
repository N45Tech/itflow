<?php

// Set working directory to the directory this script lives at.
chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

if (!n45FeatureEnabled('automation')) {
    cronJobStop('Automation ingestion is disabled by deployment feature flag');
}

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron
    FROM settings WHERE company_id = 1 LIMIT 1"));

if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}

$summary = automationProcessEventQueue(100);
$details = "processed {$summary['processed']}; failed {$summary['failed']}; "
    . "dead {$summary['dead']}; skipped {$summary['skipped']}; "
    . "actions delivered {$summary['actions_delivered']}; "
    . "actions failed {$summary['actions_failed']}; "
    . "actions skipped {$summary['actions_skipped']}";

if ($summary['failed'] > 0 || $summary['actions_failed'] > 0) {
    logApp('Automation', 'warning', "Operational event retry run: $details");
}

echo "Operational event queue: $details.\n";
