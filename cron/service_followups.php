<?php

chdir(dirname(__FILE__));
if (PHP_SAPI !== 'cli') { die("This script must run from the command line.\n"); }
$cron_lock_script = __FILE__;
require_once 'includes/cron_lock.php';
require_once '../config.php';
require_once '../includes/inc_set_timezone.php';
require_once '../functions.php';
$followup_settings = mysqli_fetch_assoc(fieldDb('SELECT config_enable_cron FROM settings WHERE company_id = 1'));
if (empty($followup_settings['config_enable_cron'])) { cronJobStop("Cron is not enabled.\n"); }
$followup_result = followupSendDueNotices();
if ($followup_result['limited']) { throw new RuntimeException('Follow-up capacity exceeded 5000 source items; review the oldest queue before continuing.'); }
echo 'Queued ' . $followup_result['sent'] . " follow-up notification(s).\n";
