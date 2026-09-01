<?php

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

if (!n45FeatureEnabled('level')) {
    cronJobStop('Level.io integration is disabled by deployment feature flag');
}

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron, config_level_enable
    FROM settings WHERE company_id = 1 LIMIT 1"));

if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}

if (empty($row['config_level_enable'])) {
    cronJobStop('Level.io integration is disabled');
}

$summary = levelProcessWebhookQueue(100);
if ($summary['failed'] > 0) {
    throw new RuntimeException("{$summary['failed']} Level.io webhook event(s) failed; {$summary['processed']} processed");
}

echo "Processed {$summary['processed']} Level.io webhook event(s).\n";
