<?php

require_once "includes/inc_all_admin.php";

$webhook_url = 'https://' . rtrim((string) $config_base_url, '/') . '/api/v1/integrations/automation/event.php';

$policies = [];
$sql_policies = mysqli_query($mysqli, "SELECT * FROM automation_event_policies
    ORDER BY automation_policy_source ASC");
while ($policy = mysqli_fetch_assoc($sql_policies)) {
    $policies[] = $policy;
}

$clients = [];
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients
    WHERE client_archived_at IS NULL ORDER BY client_name ASC");
while ($client = mysqli_fetch_assoc($sql_clients)) {
    $clients[intval($client['client_id'])] = $client['client_name'];
}

$assets = [];
$sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_client_id, client_name
    FROM assets LEFT JOIN clients ON asset_client_id = client_id
    WHERE asset_archived_at IS NULL ORDER BY client_name, asset_name");
while ($asset = mysqli_fetch_assoc($sql_assets)) {
    $assets[intval($asset['asset_id'])] = $asset;
}

$services = [];
$sql_services = mysqli_query($mysqli, "SELECT service_id, service_name, service_client_id, client_name
    FROM services LEFT JOIN clients ON service_client_id = client_id
    ORDER BY client_name, service_name");
while ($service = mysqli_fetch_assoc($sql_services)) {
    $services[intval($service['service_id'])] = $service;
}

$maintenance_windows = [];
$sql_maintenance = mysqli_query($mysqli, "SELECT automation_maintenance_windows.*,
    clients.client_name, assets.asset_name, services.service_name
    FROM automation_maintenance_windows
    LEFT JOIN clients ON automation_maintenance_client_id = clients.client_id
    LEFT JOIN assets ON automation_maintenance_asset_id = assets.asset_id
    LEFT JOIN services ON automation_maintenance_service_id = services.service_id
    WHERE automation_maintenance_deleted_at IS NULL
    ORDER BY automation_maintenance_ends_at >= NOW() DESC,
        automation_maintenance_starts_at DESC LIMIT 50");
while ($window = mysqli_fetch_assoc($sql_maintenance)) {
    $maintenance_windows[] = $window;
}

$queue_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    SUM(automation_event_status = 'Pending') AS pending_count,
    SUM(automation_event_status = 'Processing') AS processing_count,
    SUM(automation_event_status = 'Failed') AS failed_count,
    SUM(automation_event_status = 'Dead') AS dead_count,
    SUM(automation_event_status = 'Processed'
        AND automation_event_processed_at >= NOW() - INTERVAL 24 HOUR) AS processed_24h,
    SUM(automation_event_suppressed_reason IS NOT NULL
        AND automation_event_received_at >= NOW() - INTERVAL 24 HOUR) AS suppressed_24h
    FROM automation_events"));

$failed_events = mysqli_query($mysqli, "SELECT automation_event_id, automation_event_source,
    automation_event_external_id, automation_event_incident_key, automation_event_status,
    automation_event_process_attempts, automation_event_max_attempts,
    automation_event_last_error, automation_event_last_received_at,
    automation_event_payload IS NOT NULL AS payload_available
    FROM automation_events
    WHERE automation_event_status IN ('Failed', 'Dead')
    ORDER BY automation_event_last_received_at DESC LIMIT 50");

?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-stream mr-2"></i>Operational Event Ingestion</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">Source-neutral alert correlation for Level.io, SentinelOne, Checkmk, CIPP, backups, infrastructure, and n8n workflows.</p>

        <label>Authenticated event endpoint</label>
        <div class="input-group mb-2">
            <input class="form-control text-monospace" id="automationEventUrl" readonly value="<?= escapeHtml($webhook_url) ?>">
            <div class="input-group-append">
                <button class="btn btn-light clipboardjs" type="button" data-clipboard-target="#automationEventUrl"><i class="far fa-copy"></i></button>
            </div>
        </div>
        <small class="form-text text-muted">Send JSON with a bearer API key. Required fields are <code>source</code>, <code>event_id</code>, and <code>incident_key</code>. State is <code>open</code>, <code>update</code>, or <code>resolved</code>.</small>
    </div>
</div>

<div class="row">
    <div class="col-lg-3 col-6">
        <div class="small-box bg-info"><div class="inner"><h3><?= intval($queue_stats['processed_24h'] ?? 0) ?></h3><p>Processed in 24h</p></div><div class="icon"><i class="fas fa-check"></i></div></div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="small-box bg-secondary"><div class="inner"><h3><?= intval($queue_stats['pending_count'] ?? 0) + intval($queue_stats['processing_count'] ?? 0) ?></h3><p>Queued / processing</p></div><div class="icon"><i class="fas fa-hourglass-half"></i></div></div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="small-box bg-warning"><div class="inner"><h3><?= intval($queue_stats['failed_count'] ?? 0) ?></h3><p>Waiting to retry</p></div><div class="icon"><i class="fas fa-redo"></i></div></div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="small-box bg-danger"><div class="inner"><h3><?= intval($queue_stats['dead_count'] ?? 0) ?></h3><p>Dead letters</p></div><div class="icon"><i class="fas fa-exclamation-triangle"></i></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-sliders-h mr-2"></i>Source Policies</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Source</th><th>Behavior</th><th>Ticket threshold</th><th>Retry</th><th>Retention</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($policies as $policy) {
                    $policy_form_id = 'automation-policy-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($policy['automation_policy_source']));
                    ?>
                    <tr>
                        <td><strong><?= escapeHtml(ucwords(str_replace(['_', '-'], ' ', $policy['automation_policy_source']))) ?></strong></td>
                        <td style="min-width:190px">
                            <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="enabled-<?= escapeHtml($policy['automation_policy_source']) ?>" name="automation_policy_enabled" value="1" form="<?= escapeHtml($policy_form_id) ?>" <?= $policy['automation_policy_enabled'] ? 'checked' : '' ?>><label class="custom-control-label" for="enabled-<?= escapeHtml($policy['automation_policy_source']) ?>">Ingest</label></div>
                            <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="ticket-<?= escapeHtml($policy['automation_policy_source']) ?>" name="automation_policy_ticket_enabled" value="1" form="<?= escapeHtml($policy_form_id) ?>" <?= $policy['automation_policy_ticket_enabled'] ? 'checked' : '' ?>><label class="custom-control-label" for="ticket-<?= escapeHtml($policy['automation_policy_source']) ?>">Create tickets</label></div>
                            <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="resolve-<?= escapeHtml($policy['automation_policy_source']) ?>" name="automation_policy_auto_resolve" value="1" form="<?= escapeHtml($policy_form_id) ?>" <?= $policy['automation_policy_auto_resolve'] ? 'checked' : '' ?>><label class="custom-control-label" for="resolve-<?= escapeHtml($policy['automation_policy_source']) ?>">Auto-resolve</label></div>
                        </td>
                        <td style="min-width:190px">
                            <div class="input-group input-group-sm"><input class="form-control" type="number" min="1" max="1000" name="automation_policy_threshold_count" form="<?= escapeHtml($policy_form_id) ?>" value="<?= intval($policy['automation_policy_threshold_count']) ?>"><div class="input-group-append"><span class="input-group-text">events</span></div></div>
                            <div class="input-group input-group-sm mt-1"><input class="form-control" type="number" min="0" max="43200" name="automation_policy_threshold_window_minutes" form="<?= escapeHtml($policy_form_id) ?>" value="<?= intval($policy['automation_policy_threshold_window_minutes']) ?>"><div class="input-group-append"><span class="input-group-text">minutes</span></div></div>
                        </td>
                        <td style="min-width:170px">
                            <div class="input-group input-group-sm"><input class="form-control" type="number" min="1" max="25" name="automation_policy_max_attempts" form="<?= escapeHtml($policy_form_id) ?>" value="<?= intval($policy['automation_policy_max_attempts']) ?>"><div class="input-group-append"><span class="input-group-text">attempts</span></div></div>
                            <div class="input-group input-group-sm mt-1"><input class="form-control" type="number" min="15" max="86400" name="automation_policy_retry_delay_seconds" form="<?= escapeHtml($policy_form_id) ?>" value="<?= intval($policy['automation_policy_retry_delay_seconds']) ?>"><div class="input-group-append"><span class="input-group-text">seconds</span></div></div>
                        </td>
                        <td style="min-width:120px"><div class="input-group input-group-sm"><input class="form-control" type="number" min="1" max="3650" name="automation_policy_payload_retention_days" form="<?= escapeHtml($policy_form_id) ?>" value="<?= intval($policy['automation_policy_payload_retention_days']) ?>"><div class="input-group-append"><span class="input-group-text">days</span></div></div></td>
                        <td>
                            <form id="<?= escapeHtml($policy_form_id) ?>" action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="automation_policy_source" value="<?= escapeHtml($policy['automation_policy_source']) ?>">
                                <button class="btn btn-sm btn-primary" type="submit" name="save_automation_policy" title="Save <?= escapeHtml($policy['automation_policy_source']) ?> policy"><i class="fas fa-save"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-tools mr-2"></i>Add Maintenance Window</h5></div>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="card-body">
                    <div class="form-group"><label>Name</label><input class="form-control" name="automation_maintenance_name" maxlength="255" required placeholder="Client patching window"></div>
                    <div class="form-group"><label>Source</label><select class="form-control select2" name="automation_maintenance_source"><option value="">All sources</option><?php foreach ($policies as $policy) { ?><option value="<?= escapeHtml($policy['automation_policy_source']) ?>"><?= escapeHtml(ucwords(str_replace(['_', '-'], ' ', $policy['automation_policy_source']))) ?></option><?php } ?></select></div>
                    <div class="form-group"><label>Client</label><select class="form-control select2" name="automation_maintenance_client_id"><option value="0">All clients</option><?php foreach ($clients as $client_id => $client_name) { ?><option value="<?= $client_id ?>"><?= escapeHtml($client_name) ?></option><?php } ?></select></div>
                    <div class="form-group"><label>Asset</label><select class="form-control select2" name="automation_maintenance_asset_id"><option value="0">All assets in scope</option><?php foreach ($assets as $asset_id => $asset) { ?><option value="<?= $asset_id ?>"><?= escapeHtml(($asset['client_name'] ? $asset['client_name'] . ' · ' : '') . $asset['asset_name']) ?></option><?php } ?></select></div>
                    <div class="form-group"><label>Service</label><select class="form-control select2" name="automation_maintenance_service_id"><option value="0">All services in scope</option><?php foreach ($services as $service_id => $service) { ?><option value="<?= $service_id ?>"><?= escapeHtml(($service['client_name'] ? $service['client_name'] . ' · ' : '') . $service['service_name']) ?></option><?php } ?></select></div>
                    <div class="form-row"><div class="form-group col-md-6"><label>Starts</label><input class="form-control" type="datetime-local" name="automation_maintenance_starts_at" required></div><div class="form-group col-md-6"><label>Ends</label><input class="form-control" type="datetime-local" name="automation_maintenance_ends_at" required></div></div>
                    <div class="form-group mb-0"><label>Reason</label><textarea class="form-control" name="automation_maintenance_reason" rows="2" maxlength="2000"></textarea></div>
                </div>
                <div class="card-footer"><button class="btn btn-primary" type="submit" name="add_automation_maintenance"><i class="fas fa-plus mr-2"></i>Add Window</button></div>
            </form>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-calendar-alt mr-2"></i>Maintenance Windows</h5></div>
            <div class="card-body p-0">
                <?php if ($maintenance_windows) { ?>
                    <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Window</th><th>Scope</th><th>Time</th><th></th></tr></thead><tbody>
                    <?php foreach ($maintenance_windows as $window) { $active = $window['automation_maintenance_starts_at'] <= date('Y-m-d H:i:s') && $window['automation_maintenance_ends_at'] >= date('Y-m-d H:i:s'); ?>
                        <tr><td><strong><?= escapeHtml($window['automation_maintenance_name']) ?></strong><br><span class="badge badge-<?= $active ? 'warning' : ($window['automation_maintenance_ends_at'] < date('Y-m-d H:i:s') ? 'secondary' : 'info') ?>"><?= $active ? 'Active' : ($window['automation_maintenance_ends_at'] < date('Y-m-d H:i:s') ? 'Ended' : 'Scheduled') ?></span></td><td><?= escapeHtml($window['automation_maintenance_source'] ?: 'All sources') ?><br><small class="text-muted"><?= escapeHtml($window['client_name'] ?: 'All clients') ?><?= $window['asset_name'] ? ' · ' . escapeHtml($window['asset_name']) : '' ?><?= $window['service_name'] ? ' · ' . escapeHtml($window['service_name']) : '' ?></small></td><td><small><?= escapeHtml($window['automation_maintenance_starts_at']) ?><br><?= escapeHtml($window['automation_maintenance_ends_at']) ?></small></td><td><a class="btn btn-sm btn-outline-danger" href="post.php?delete_automation_maintenance=<?= intval($window['automation_maintenance_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" onclick="return confirm('Remove this maintenance window?')"><i class="fas fa-trash"></i></a></td></tr>
                    <?php } ?>
                    </tbody></table></div>
                <?php } else { ?><div class="text-center text-muted p-4">No maintenance windows configured.</div><?php } ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-exclamation-circle mr-2"></i>Retry and Dead-Letter Queue</h5></div>
    <div class="card-body p-0">
        <?php if (mysqli_num_rows($failed_events)) { ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last error</th><th>Received</th><th></th></tr></thead><tbody>
            <?php while ($event = mysqli_fetch_assoc($failed_events)) { ?>
                <tr><td><strong><?= escapeHtml($event['automation_event_source']) ?> · <?= escapeHtml($event['automation_event_incident_key']) ?></strong><br><small class="text-muted"><?= escapeHtml($event['automation_event_external_id']) ?></small></td><td><span class="badge badge-<?= $event['automation_event_status'] === 'Dead' ? 'danger' : 'warning' ?>"><?= escapeHtml($event['automation_event_status']) ?></span></td><td><?= intval($event['automation_event_process_attempts']) ?>/<?= intval($event['automation_event_max_attempts']) ?></td><td><small><?= escapeHtml($event['automation_event_last_error']) ?></small></td><td><small><?= escapeHtml($event['automation_event_last_received_at']) ?></small></td><td><?php if ($event['payload_available']) { ?><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="automation_event_id" value="<?= intval($event['automation_event_id']) ?>"><button class="btn btn-sm btn-outline-primary" type="submit" name="replay_automation_event"><i class="fas fa-redo mr-1"></i>Replay</button></form><?php } else { ?><span class="text-muted">Payload expired</span><?php } ?></td></tr>
            <?php } ?>
            </tbody></table></div>
        <?php } else { ?><div class="text-center text-muted p-4"><i class="fas fa-check-circle mr-2 text-success"></i>No failed or dead-letter events.</div><?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
