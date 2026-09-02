<?php

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

$reconcile = retentionReconcileDeletionLedger(500);
$summary = retentionRunScheduledMaintenance();
$details = "reconciled {$reconcile['repaired']} (blocked {$reconcile['blocked']}); "
    . "quarantine recovered {$summary['quarantine']['quarantined']} (failed {$summary['quarantine']['failed']}); "
    . "restore recovered {$summary['restores']['restored']} (failed {$summary['restores']['failed']}); "
    . "event payloads {$summary['redacted']['automation_payloads']}; "
    . "normalized payloads {$summary['redacted']['normalized_payloads']}; "
    . "quarantine cleaned {$summary['cleanup']['cleaned']}; cleanup failed {$summary['cleanup']['failed']}; "
    . "preview batch {$summary['preview']['batch_id']}";

if ($summary['cleanup']['failed'] > 0) {
    logApp('Retention', 'warning', "Retention maintenance: $details");
}

echo "Retention maintenance: $details.\n";
