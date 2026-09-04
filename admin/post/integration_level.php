<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_level_settings'])) {

    validateCSRFToken();

    $enabled = isset($_POST['level_enable']) ? 1 : 0;
    $alert_ticket_enabled = isset($_POST['level_alert_ticket_enable']) ? 1 : 0;
    $assigned_to = intval($_POST['level_alert_assigned_to'] ?? 0);
    $api_key_raw = trim((string) ($_POST['level_api_key'] ?? ''));
    $webhook_secret_raw = trim((string) ($_POST['level_webhook_secret'] ?? ''));

    if (strlen($api_key_raw) > 255 || preg_match('/[\r\n]/', $api_key_raw)) {
        flashAlert('The Level API key is not valid.', 'error');
        redirect();
    }

    if (strlen($webhook_secret_raw) > 255 || preg_match('/[\r\n]/', $webhook_secret_raw)) {
        flashAlert('The Level webhook secret is not valid.', 'error');
        redirect();
    }

    $saved_api_key = $api_key_raw !== '' ? $api_key_raw : (string) $config_level_api_key;
    $saved_webhook_secret = $webhook_secret_raw !== '' ? $webhook_secret_raw : (string) $config_level_webhook_secret;

    if ($enabled && ($saved_api_key === '' || $saved_webhook_secret === '')) {
        flashAlert('An API key and webhook secret are required before Level.io can be enabled.', 'error');
        redirect();
    }

    if ($alert_ticket_enabled && !$config_module_enable_ticketing) {
        flashAlert('ITFlow ticketing must be enabled before Level alerts can open tickets.', 'error');
        redirect();
    }

    if ($assigned_to > 0) {
        $user = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id FROM users
            WHERE user_id = $assigned_to AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1"));
        if (!$user) {
            flashAlert('Choose an active technician for Level alert tickets.', 'error');
            redirect();
        }
    }

    $secret_updates = '';
    if ($api_key_raw !== '') {
        $api_key_sql = mysqli_real_escape_string($mysqli, $api_key_raw);
        $secret_updates .= ", config_level_api_key = '$api_key_sql'";
    }
    if ($webhook_secret_raw !== '') {
        $webhook_secret_sql = mysqli_real_escape_string($mysqli, $webhook_secret_raw);
        $secret_updates .= ", config_level_webhook_secret = '$webhook_secret_sql'";
    }

    mysqli_query($mysqli, "UPDATE settings SET
        config_level_enable = $enabled,
        config_level_alert_ticket_enable = $alert_ticket_enabled,
        config_level_alert_assigned_to = $assigned_to
        $secret_updates
        WHERE company_id = 1");

    $sync_queued = $enabled && $config_enable_cron ? levelQueueCronJob('level_sync') : false;

    logAudit('Level.io', 'Edit', "$session_name edited the Level.io integration settings");
    flashAlert('Level.io settings updated' . ($sync_queued ? '; reconciliation queued.' : '.'));
    redirect();
}

if (isset($_POST['generate_level_webhook_secret'])) {

    validateCSRFToken();

    $secret = randomString(64);
    $secret_sql = mysqli_real_escape_string($mysqli, $secret);
    mysqli_query($mysqli, "UPDATE settings SET config_level_webhook_secret = '$secret_sql' WHERE company_id = 1");

    // The secret is deliberately shown once. Later page loads only show that a
    // secret exists, matching Level's own write-only secret behavior.
    $_SESSION['level_webhook_secret_once'] = $secret;
    logAudit('Level.io', 'Edit', "$session_name rotated the Level.io webhook secret");
    redirect();
}

if (isset($_POST['test_level_connection'])) {

    validateCSRFToken();

    $result = levelTestConnection();
    if ($result['ok']) {
        logAudit('Level.io', 'Test', "$session_name successfully tested the Level.io connection");
        flashAlert($result['message']);
    } else {
        logAudit('Level.io', 'Test', "$session_name received a failed Level.io connection test");
        flashAlert($result['message'], 'error');
    }
    redirect();
}

if (isset($_POST['discover_level_groups'])) {

    validateCSRFToken();

    try {
        $count = levelDiscoverGroups();
        logAudit('Level.io', 'Sync', "$session_name discovered $count Level.io groups");
        flashAlert("Discovered $count Level.io group" . ($count === 1 ? '' : 's') . '.');
    } catch (Throwable $e) {
        logApp('Level.io', 'error', 'Group discovery failed: ' . $e->getMessage());
        flashAlert('Level group discovery failed. Check the API key and App Logs.', 'error');
    }
    redirect();
}

if (isset($_POST['save_level_group_mappings'])) {

    validateCSRFToken();

    $group_ids = $_POST['level_group_id'] ?? [];
    $client_ids = $_POST['level_group_client_id'] ?? [];
    if (!is_array($group_ids) || !is_array($client_ids) || count($group_ids) !== count($client_ids)) {
        flashAlert('The Level group mappings could not be read.', 'error');
        redirect();
    }

    $valid_clients = [0 => true];
    $client_sql = mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_archived_at IS NULL");
    while ($client = mysqli_fetch_assoc($client_sql)) {
        $valid_clients[intval($client['client_id'])] = true;
    }

    $updated = 0;
    foreach ($group_ids as $index => $group_id_raw) {
        $group_id = levelLimitText($group_id_raw, 255);
        $client_id = intval($client_ids[$index] ?? 0);
        if ($group_id === '' || !isset($valid_clients[$client_id])) {
            continue;
        }

        $group_id_sql = levelDbEscape($group_id);
        mysqli_query($mysqli, "UPDATE level_group_mappings SET level_group_client_id = $client_id
            WHERE level_group_id = '$group_id_sql' AND level_group_deleted_at IS NULL");
        $updated += mysqli_affected_rows($mysqli) > 0 ? 1 : 0;
    }

    $sync_queued = false;
    if ($config_level_enable && $config_enable_cron) {
        $sync_queued = levelQueueCronJob('level_sync');
    }

    logAudit('Level.io', 'Edit', "$session_name saved Level.io group-to-client mappings");
    flashAlert("Level group mappings saved" . ($updated ? " ($updated changed)" : '')
        . ($sync_queued ? '; reconciliation queued.' : '.'));
    redirect();
}

if (isset($_POST['queue_level_sync']) || isset($_POST['queue_level_webhooks'])) {

    validateCSRFToken();

    if (!$config_level_enable) {
        flashAlert('Enable the Level.io integration before running it.', 'error');
        redirect();
    }
    if (!$config_enable_cron) {
        flashAlert('Turn on ITFlow cron before queueing Level.io work.', 'error');
        redirect();
    }

    $job_name = isset($_POST['queue_level_sync']) ? 'level_sync' : 'level_webhook_processor';
    if ($job_name === 'level_webhook_processor') {
        // A manual retry is an explicit request to release events that reached
        // the automatic ten-attempt ceiling after their underlying issue was fixed.
        mysqli_query($mysqli, "UPDATE level_webhook_events SET
            level_webhook_status = 'Pending',
            level_webhook_process_attempts = 0,
            level_webhook_processing_at = NULL,
            level_webhook_last_error = NULL
            WHERE level_webhook_status = 'Failed'");
    }
    levelQueueCronJob($job_name);
    logAudit('Level.io', 'Run', "$session_name queued $job_name");
    flashAlert($job_name === 'level_sync'
        ? 'Level.io reconciliation is queued and will start within a minute.'
        : 'Level.io webhook processing is queued and will start within a minute.');
    redirect();
}
