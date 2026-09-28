<?php

chdir(dirname(__FILE__));
if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}
$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1 LIMIT 1"));
if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}
if (!n45FeatureEnabled('automation_investigation') || !n45FeatureEnabled('automation')) {
    cronJobStop('Read-only investigation is disabled');
}
try {
    $summary = investigationRun();
    echo 'Read-only investigation: ' . $summary['status'] . ".\n";
} catch (Throwable $error) {
    // Do not log database exceptions, prompt content, provider responses or credentials.
    logApp('AI Investigation', 'error', 'Investigation worker failed; review schema and dedicated provider configuration. No remediation was attempted.');
    cronJobStop('Investigation worker failed', 1);
}
