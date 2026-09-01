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

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron
    FROM settings WHERE company_id = 1 LIMIT 1"));

if (empty($row['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}

$evaluation = documentationEvaluateDueClients(100);
$expired_promises = documentationExpirePromises(100);
$expired_waivers = documentationExpireTicketWaivers(100);

$details = "clients {$evaluation['clients']}; created {$evaluation['created']}; "
    . "changed {$evaluation['changed']}; unchanged {$evaluation['unchanged']}; "
    . "exceptions {$evaluation['expired_exceptions']}; promises $expired_promises; "
    . "waivers $expired_waivers; failed {$evaluation['failed']}";

if ($evaluation['failed'] > 0) {
    logApp('Documentation', 'warning', "Documentation evaluator run: $details");
}

echo "Documentation evaluator: $details.\n";
