<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_automation_policy'])) {
    validateCSRFToken();

    try {
        $source = automationSource($_POST['automation_policy_source'] ?? '');
    } catch (Throwable $e) {
        flashAlert('The event source is invalid.', 'error');
        redirect('integration_automation.php');
    }

    $source_sql = automationDbEscape($source);
    $enabled = isset($_POST['automation_policy_enabled']) ? 1 : 0;
    $ticket_enabled = isset($_POST['automation_policy_ticket_enabled']) ? 1 : 0;
    $auto_resolve = isset($_POST['automation_policy_auto_resolve']) ? 1 : 0;
    $threshold_count = min(1000, max(1, intval($_POST['automation_policy_threshold_count'] ?? 1)));
    $threshold_window = min(43200, max(0, intval($_POST['automation_policy_threshold_window_minutes'] ?? 0)));
    $max_attempts = min(25, max(1, intval($_POST['automation_policy_max_attempts'] ?? 5)));
    $retry_delay = min(86400, max(15, intval($_POST['automation_policy_retry_delay_seconds'] ?? 60)));
    $retention_days = min(3650, max(1, intval($_POST['automation_policy_payload_retention_days'] ?? 30)));

    $saved = mysqli_query($mysqli, "INSERT INTO automation_event_policies SET
        automation_policy_source = '$source_sql',
        automation_policy_enabled = $enabled,
        automation_policy_ticket_enabled = $ticket_enabled,
        automation_policy_auto_resolve = $auto_resolve,
        automation_policy_threshold_count = $threshold_count,
        automation_policy_threshold_window_minutes = $threshold_window,
        automation_policy_max_attempts = $max_attempts,
        automation_policy_retry_delay_seconds = $retry_delay,
        automation_policy_payload_retention_days = $retention_days
        ON DUPLICATE KEY UPDATE
        automation_policy_enabled = VALUES(automation_policy_enabled),
        automation_policy_ticket_enabled = VALUES(automation_policy_ticket_enabled),
        automation_policy_auto_resolve = VALUES(automation_policy_auto_resolve),
        automation_policy_threshold_count = VALUES(automation_policy_threshold_count),
        automation_policy_threshold_window_minutes = VALUES(automation_policy_threshold_window_minutes),
        automation_policy_max_attempts = VALUES(automation_policy_max_attempts),
        automation_policy_retry_delay_seconds = VALUES(automation_policy_retry_delay_seconds),
        automation_policy_payload_retention_days = VALUES(automation_policy_payload_retention_days)");
    if (!$saved) {
        logApp('Automation', 'error', "Could not save the $source event policy: " . mysqli_error($mysqli));
        flashAlert('The operational event policy could not be saved.', 'error');
        redirect('integration_automation.php');
    }

    logAudit('Automation', 'Edit', "$session_name updated the $source event policy");
    flashAlert('Operational event policy saved.');
    redirect('integration_automation.php');
}

if (isset($_POST['add_automation_maintenance'])) {
    validateCSRFToken();

    $name = automationLimitText($_POST['automation_maintenance_name'] ?? '', 255);
    $source_raw = trim((string) ($_POST['automation_maintenance_source'] ?? ''));
    try {
        $source = $source_raw === '' ? '' : automationSource($source_raw);
        $starts_at = automationEventDateTime($_POST['automation_maintenance_starts_at'] ?? '', false);
        $ends_at = automationEventDateTime($_POST['automation_maintenance_ends_at'] ?? '', false);
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
        redirect('integration_automation.php');
    }
    if ($name === '' || $starts_at === null || $ends_at === null || $ends_at <= $starts_at) {
        flashAlert('Enter a name and an end time after the start time.', 'error');
        redirect('integration_automation.php');
    }

    $client_id = max(0, intval($_POST['automation_maintenance_client_id'] ?? 0));
    $asset_id = max(0, intval($_POST['automation_maintenance_asset_id'] ?? 0));
    $service_id = max(0, intval($_POST['automation_maintenance_service_id'] ?? 0));
    $scope_clients = [];
    if ($client_id > 0) {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM clients WHERE client_id = $client_id"));
        if (intval($row[0] ?? 0) !== 1) {
            flashAlert('The selected client is unavailable.', 'error');
            redirect('integration_automation.php');
        }
        $scope_clients[] = $client_id;
    }
    if ($asset_id > 0) {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT asset_client_id FROM assets
            WHERE asset_id = $asset_id AND asset_archived_at IS NULL LIMIT 1"));
        if (!$row) {
            flashAlert('The selected asset is unavailable.', 'error');
            redirect('integration_automation.php');
        }
        $scope_clients[] = intval($row[0]);
    }
    if ($service_id > 0) {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT service_client_id FROM services
            WHERE service_id = $service_id LIMIT 1"));
        if (!$row) {
            flashAlert('The selected service is unavailable.', 'error');
            redirect('integration_automation.php');
        }
        $scope_clients[] = intval($row[0]);
    }
    $scope_clients = array_values(array_unique(array_filter($scope_clients)));
    if (count($scope_clients) > 1) {
        flashAlert('The selected client, asset, and service must belong to the same client.', 'error');
        redirect('integration_automation.php');
    }
    if ($client_id === 0 && $scope_clients) {
        $client_id = intval($scope_clients[0]);
    }

    $name_sql = automationDbEscape($name);
    $source_sql = automationDbEscape($source);
    $starts_at_sql = automationDbEscape($starts_at);
    $ends_at_sql = automationDbEscape($ends_at);
    $reason_sql = automationDbEscape(automationLimitText($_POST['automation_maintenance_reason'] ?? '', 2000));
    $user_id = intval($session_user_id);

    $created = mysqli_query($mysqli, "INSERT INTO automation_maintenance_windows SET
        automation_maintenance_name = '$name_sql',
        automation_maintenance_source = '$source_sql',
        automation_maintenance_client_id = $client_id,
        automation_maintenance_asset_id = $asset_id,
        automation_maintenance_service_id = $service_id,
        automation_maintenance_starts_at = '$starts_at_sql',
        automation_maintenance_ends_at = '$ends_at_sql',
        automation_maintenance_reason = '$reason_sql',
        automation_maintenance_created_by = $user_id");
    if (!$created) {
        logApp('Automation', 'error', 'Could not add an automation maintenance window: ' . mysqli_error($mysqli));
        flashAlert('The maintenance window could not be added.', 'error');
        redirect('integration_automation.php');
    }

    logAudit('Automation', 'Create', "$session_name created maintenance window $name", $client_id);
    flashAlert('Maintenance window added.');
    redirect('integration_automation.php');
}

if (isset($_GET['delete_automation_maintenance'])) {
    validateCSRFToken();
    $maintenance_id = max(0, intval($_GET['delete_automation_maintenance']));
    $deleted = mysqli_query($mysqli, "UPDATE automation_maintenance_windows SET
        automation_maintenance_deleted_at = NOW()
        WHERE automation_maintenance_id = $maintenance_id
        AND automation_maintenance_deleted_at IS NULL LIMIT 1");
    if (!$deleted || mysqli_affected_rows($mysqli) !== 1) {
        flashAlert('That maintenance window is unavailable.', 'error');
        redirect('integration_automation.php');
    }
    logAudit('Automation', 'Delete', "$session_name removed automation maintenance window $maintenance_id");
    flashAlert('Maintenance window removed.');
    redirect('integration_automation.php');
}

if (isset($_POST['replay_automation_event'])) {
    validateCSRFToken();
    $event_id = max(0, intval($_POST['automation_event_id'] ?? 0));
    if (automationReplayEvent($event_id)) {
        logAudit('Automation', 'Replay', "$session_name queued automation event $event_id for replay");
        flashAlert('Event queued for replay.');
    } else {
        flashAlert('That event cannot be replayed or its retained payload has expired.', 'error');
    }
    redirect('integration_automation.php');
}
