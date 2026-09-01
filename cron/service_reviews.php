<?php

/*
 * Build due service-review drafts. Publishing is intentionally human-gated.
 */

chdir(dirname(__FILE__));
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$cron_lock_script = __FILE__;
require_once 'includes/cron_lock.php';
require_once '../config.php';
require_once '../includes/inc_set_timezone.php';
require_once '../functions.php';

$settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron
    FROM settings WHERE company_id = 1 LIMIT 1"));
if (intval($settings['config_enable_cron'] ?? 0) === 0) {
    logApp('Cron-Service-Reviews', 'error', 'Service reviews unable to run - cron not enabled in admin settings.');
    cronJobStop("Cron: is not enabled -- Quitting..\n");
}

try {
    $review_ids = agreementGenerateDueServiceReviews(25);
    logApp('Cron-Service-Reviews', 'info', 'Generated ' . count($review_ids) . ' due service-review draft(s).');
    echo 'Generated ' . count($review_ids) . " service-review draft(s).\n";
} catch (Throwable $e) {
    logApp('Cron-Service-Reviews', 'error', 'Service-review generation failed: ' . $e->getMessage());
    throw $e;
}
