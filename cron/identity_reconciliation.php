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

try {
    $orphans = integrationIdentityReconcileOrphans();
    $staleness = integrationIdentityReconcileStaleness();
    $health = integrationIdentityHealthSummary();
    $coverage_rows = endpointIntegrationCoverageRows();
    $missing_level = 0;
    $missing_intune = 0;
    $missing_sentinelone = 0;
    foreach ($coverage_rows as $coverage) {
        $missing_level += intval($coverage['missing_level_devices'] ?? 0);
        $missing_intune += intval($coverage['missing_intune_devices'] ?? 0);
        $missing_sentinelone += intval($coverage['managed_windows_missing_sentinelone'] ?? 0);
    }

    $details = "orphan checks {$orphans['checked']}; quarantined {$orphans['quarantined']}; "
        . "freshness checks {$staleness['checked']}; marked stale {$staleness['marked_stale']}; "
        . "review {$health['review']}; conflicts {$health['conflicts']}; stale {$health['stale']}; "
        . "missing Level $missing_level; missing Intune $missing_intune; "
        . "managed Windows missing SentinelOne $missing_sentinelone";
    $attention = $health['review'] + $health['stale'] + $missing_level + $missing_intune + $missing_sentinelone;
    if ($attention > 0) {
        $recent = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM notifications
            WHERE notification_type = 'Endpoint Identity'
            AND notification_timestamp >= NOW() - INTERVAL 24 HOUR"));
        if (intval($recent[0] ?? 0) === 0) {
            appNotify(
                'Endpoint Identity',
                "Endpoint identity reconciliation needs review: $details",
                '/agent/operations.php#identity-review'
            );
        }
        logApp('Endpoint Identity', 'warning', "Reconciliation needs review. $details");
    } else {
        logApp('Endpoint Identity', 'info', "Reconciliation completed. $details");
    }
    echo "Endpoint identity reconciliation: $details.\n";
} catch (Throwable $e) {
    $recent_failure = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM notifications
        WHERE notification_type = 'Endpoint Identity Failure'
        AND notification_timestamp >= NOW() - INTERVAL 1 HOUR"));
    if (intval($recent_failure[0] ?? 0) === 0) {
        appNotify(
            'Endpoint Identity Failure',
            'Endpoint identity reconciliation failed; review the Cron job error before trusting source coverage.',
            '/admin/cron.php'
        );
    }
    logApp('Endpoint Identity', 'error', 'Reconciliation failed: ' . $e->getMessage());
    throw $e;
}
