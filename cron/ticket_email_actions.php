<?php

chdir(dirname(__FILE__));
if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line.\n");
}
$cron_lock_script = __FILE__;
require_once 'includes/cron_lock.php';
require_once '../config.php';
require_once '../includes/inc_set_timezone.php';
require_once '../functions.php';

$settings = mysqli_fetch_assoc(ticketEmailDb('SELECT config_enable_cron FROM settings WHERE company_id = 1'));
if (empty($settings['config_enable_cron'])) {
    cronJobStop('Cron is not enabled');
}
$result = ticketEmailProcessAction();
echo 'Inbound mail action: ' . $result['status'] . ".\n";
