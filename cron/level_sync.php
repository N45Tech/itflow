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

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron, config_level_api_key,
    config_level_enable FROM settings WHERE company_id = 1 LIMIT 1"));

if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}

if (empty($row['config_level_enable'])) {
    cronJobStop('Level.io integration is disabled');
}

if (empty($row['config_level_api_key'])) {
    cronJobStop('Level.io API key is not configured');
}

$summary = levelRunFullSync();
$details = "Groups {$summary['groups']}; devices created {$summary['devices_created']}, linked {$summary['devices_linked']}, updated {$summary['devices_updated']}, skipped {$summary['devices_skipped']}; alert tickets created {$summary['alert_tickets_created']}, existing {$summary['alerts_existing']}, skipped {$summary['alerts_skipped']}";

logApp('Level.io', 'info', "Reconciliation completed. $details");
echo "Level.io reconciliation completed. $details\n";
