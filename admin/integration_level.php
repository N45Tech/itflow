<?php

require_once "includes/inc_all_admin.php";

$webhook_url = 'https://' . rtrim((string) $config_base_url, '/') . '/api/v1/integrations/level/webhook.php';
$generated_webhook_secret = (string) ($_SESSION['level_webhook_secret_once'] ?? '');
unset($_SESSION['level_webhook_secret_once']);

$clients = [];
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients
    WHERE client_archived_at IS NULL ORDER BY client_name ASC");
while ($client = mysqli_fetch_assoc($sql_clients)) {
    $clients[intval($client['client_id'])] = $client['client_name'];
}

$technicians = [];
$sql_technicians = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
    WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
while ($technician = mysqli_fetch_assoc($sql_technicians)) {
    $technicians[intval($technician['user_id'])] = $technician['user_name'];
}

$groups = [];
$sql_groups = mysqli_query($mysqli, "SELECT level_group_client_id, level_group_descendent_device_count,
    level_group_device_count, level_group_id, level_group_name, level_parent_group_id
    FROM level_group_mappings WHERE level_group_deleted_at IS NULL ORDER BY level_group_name ASC");
while ($group = mysqli_fetch_assoc($sql_groups)) {
    $groups[] = $group;
}
$group_rows = levelBuildGroupDisplayRows($groups);
$groups_by_id = [];
foreach ($groups as $group) {
    $groups_by_id[$group['level_group_id']] = $group;
}

$resolve_inherited_client = function ($group_id) use ($groups_by_id) {
    $visited = [];
    $current = (string) $group_id;
    while ($current !== '' && isset($groups_by_id[$current]) && !isset($visited[$current])) {
        $visited[$current] = true;
        $client_id = intval($groups_by_id[$current]['level_group_client_id']);
        if ($client_id > 0) {
            return $client_id;
        }
        $current = (string) ($groups_by_id[$current]['level_parent_group_id'] ?? '');
    }
    return 0;
};

$stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    (SELECT COUNT(*) FROM level_asset_links WHERE level_device_deleted_at IS NULL) AS managed_assets,
    (SELECT COUNT(*) FROM level_group_mappings WHERE level_group_deleted_at IS NULL AND level_group_client_id > 0) AS mapped_groups,
    (SELECT COUNT(*) FROM level_alert_links WHERE level_alert_resolved_at IS NULL AND level_ticket_id IS NOT NULL) AS active_alert_tickets,
    (SELECT COUNT(*) FROM level_asset_links WHERE level_device_sync_status = 'Conflict' AND level_device_deleted_at IS NULL) AS sync_conflicts,
    (SELECT COUNT(*) FROM level_webhook_events WHERE level_webhook_status IN ('Pending', 'Failed')) AS queued_events"));

$cron_rows = [];
$sql_cron = mysqli_query($mysqli, "SELECT cron_job_last_error, cron_job_last_run_at, cron_job_last_status,
    cron_job_name, cron_job_run_now FROM cron_jobs WHERE cron_job_name IN ('level_sync', 'level_webhook_processor')");
while ($cron = mysqli_fetch_assoc($sql_cron)) {
    $cron_rows[$cron['cron_job_name']] = $cron;
}

$sync_conflicts = [];
$sql_conflicts = mysqli_query($mysqli, "SELECT asset_id, asset_name, client_name,
    level_device_sync_message, level_group_name FROM level_asset_links
    INNER JOIN assets ON level_asset_id = asset_id
    LEFT JOIN clients ON asset_client_id = client_id
    LEFT JOIN level_group_mappings USING (level_group_id)
    WHERE level_device_sync_status = 'Conflict' AND level_device_deleted_at IS NULL
    ORDER BY level_asset_link_updated_at DESC LIMIT 20");
while ($conflict = mysqli_fetch_assoc($sql_conflicts)) {
    $sync_conflicts[] = $conflict;
}

?>

<?php if ($generated_webhook_secret !== '') { ?>
    <div class="alert alert-warning">
        <h5><i class="fas fa-fw fa-key mr-2"></i>Copy the new webhook secret now</h5>
        Level will not send it back to ITFlow, and ITFlow will not show it again.
        <div class="input-group mt-2">
            <input class="form-control text-monospace" id="generatedLevelSecret" readonly value="<?= escapeHtml($generated_webhook_secret) ?>">
            <div class="input-group-append">
                <button class="btn btn-warning clipboardjs" type="button" data-clipboard-target="#generatedLevelSecret"><i class="far fa-copy mr-2"></i>Copy</button>
            </div>
        </div>
    </div>
<?php } ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card card-dark">
            <div class="card-header py-3">
                <h3 class="card-title"><i class="fas fa-fw fa-satellite mr-2"></i>Level.io RMM</h3>
                <div class="card-tools">
                    <span class="badge badge-<?= $config_level_enable ? 'success' : 'secondary' ?> p-2"><?= $config_level_enable ? 'Enabled' : 'Disabled' ?></span>
                </div>
            </div>
            <div class="card-body">
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input class="custom-control-input" type="checkbox" id="levelEnable" name="level_enable" value="1" <?= $config_level_enable ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="levelEnable">Enable Level.io integration</label>
                        </div>
                        <small class="form-text text-muted">One-way sync: Level supplies device telemetry; ITFlow keeps client assignment, asset lifecycle, contacts, locations, and technician notes.</small>
                    </div>

                    <div class="form-group">
                        <label>Level API key</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-fw fa-key"></i></span></div>
                            <input class="form-control" type="password" name="level_api_key" maxlength="255" autocomplete="new-password"
                                placeholder="<?= $config_level_api_key ? 'Leave blank to keep the saved API key' : 'Paste a read-only Level API key' ?>">
                        </div>
                        <small class="form-text text-muted">A read-only key is sufficient. ITFlow does not change devices, groups, or alerts in Level.</small>
                    </div>

                    <div class="form-group">
                        <label>Webhook signing secret</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-fw fa-shield-alt"></i></span></div>
                            <input class="form-control" type="password" name="level_webhook_secret" maxlength="255" autocomplete="new-password"
                                placeholder="<?= $config_level_webhook_secret ? 'Leave blank to keep the saved secret' : 'Paste the same secret configured in Level' ?>">
                        </div>
                        <small class="form-text text-muted">Every webhook must carry Level's HMAC-SHA256 signature. Unsigned requests are rejected.</small>
                    </div>

                    <hr>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input class="custom-control-input" type="checkbox" id="levelAlertTickets" name="level_alert_ticket_enable" value="1" <?= $config_level_alert_ticket_enable ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="levelAlertTickets">Create ITFlow tickets for active Level alerts</label>
                        </div>
                        <small class="form-text text-muted">Resolved alerts add history to the linked ticket but do not close it; the technician retains control of the workflow.</small>
                    </div>

                    <div class="form-group">
                        <label>Default alert-ticket technician</label>
                        <select class="form-control select2" name="level_alert_assigned_to">
                            <option value="0">Unassigned</option>
                            <?php foreach ($technicians as $user_id => $user_name) { ?>
                                <option value="<?= $user_id ?>" <?= $config_level_alert_assigned_to === $user_id ? 'selected' : '' ?>><?= escapeHtml($user_name) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <button class="btn btn-primary text-bold" type="submit" name="edit_level_settings"><i class="fas fa-check mr-2"></i>Save</button>
                </form>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-1"><i class="fas fa-fw fa-sitemap mr-2"></i>Group to Client Mapping</h3>
                <div class="card-tools">
                    <form class="d-inline" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="btn btn-tool" type="submit" name="discover_level_groups"><i class="fas fa-sync-alt mr-2"></i>Discover Groups</button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!$group_rows) { ?>
                    <div class="text-center text-muted p-4">
                        <i class="fas fa-sitemap fa-2x mb-2"></i>
                        <div>No Level groups discovered yet.</div>
                    </div>
                <?php } else { ?>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive">
                            <table class="table table-striped table-borderless table-hover mb-0">
                                <thead><tr><th>Level Group</th><th>Devices</th><th>ITFlow Client</th></tr></thead>
                                <tbody>
                                <?php foreach ($group_rows as $group) {
                                    $group_id = $group['level_group_id'];
                                    $mapped_client_id = intval($group['level_group_client_id']);
                                    $effective_client_id = $resolve_inherited_client($group_id);
                                    $depth = intval($group['level_group_depth']);
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="level_group_id[]" value="<?= escapeHtml($group_id) ?>">
                                            <span style="display:inline-block;width:<?= $depth * 20 ?>px"></span>
                                            <?php if ($depth) { ?><i class="fas fa-level-up-alt fa-rotate-90 text-muted mr-2"></i><?php } ?>
                                            <strong><?= escapeHtml($group['level_group_name']) ?></strong>
                                            <?php if (!$mapped_client_id && $effective_client_id && isset($clients[$effective_client_id])) { ?>
                                                <br><small class="text-muted" style="margin-left:<?= ($depth * 20) + 24 ?>px">Inherits <?= escapeHtml($clients[$effective_client_id]) ?></small>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?= intval($group['level_group_device_count']) ?> direct
                                            <?php if (intval($group['level_group_descendent_device_count']) !== intval($group['level_group_device_count'])) { ?>
                                                <br><small class="text-muted"><?= intval($group['level_group_descendent_device_count']) ?> including children</small>
                                            <?php } ?>
                                        </td>
                                        <td style="min-width:260px">
                                            <select class="form-control select2" name="level_group_client_id[]">
                                                <option value="0">No direct mapping<?= $effective_client_id ? ' (inherit)' : '' ?></option>
                                                <?php foreach ($clients as $client_id => $client_name) { ?>
                                                    <option value="<?= $client_id ?>" <?= $mapped_client_id === $client_id ? 'selected' : '' ?>><?= escapeHtml($client_name) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="p-3 border-top">
                            <button class="btn btn-primary text-bold" type="submit" name="save_level_group_mappings"><i class="fas fa-check mr-2"></i>Save Mappings</button>
                            <small class="text-muted ml-2">Unmapped child groups inherit the nearest mapped parent. Completely unmapped devices are skipped.</small>
                        </div>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-chart-bar mr-2"></i>Integration Status</h5></div>
            <div class="card-body">
                <div class="d-flex justify-content-between"><span>Managed assets</span><strong><?= intval($stats['managed_assets'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Direct group mappings</span><strong><?= intval($stats['mapped_groups'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Active alert tickets</span><strong><?= intval($stats['active_alert_tickets'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Client mapping conflicts</span><strong class="<?= intval($stats['sync_conflicts'] ?? 0) ? 'text-danger' : '' ?>"><?= intval($stats['sync_conflicts'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between mt-2"><span>Queued/failed events</span><strong><?= intval($stats['queued_events'] ?? 0) ?></strong></div>

                <hr>

                <?php foreach (['level_sync' => 'Reconciliation', 'level_webhook_processor' => 'Webhooks'] as $job_name => $label) {
                    $job = $cron_rows[$job_name] ?? null;
                    ?>
                    <div class="mb-3">
                        <strong><?= $label ?></strong>
                        <?php if ($job && $job['cron_job_run_now']) { ?><span class="badge badge-warning float-right">Queued</span>
                        <?php } elseif ($job && $job['cron_job_last_status'] === 'Running') { ?><span class="badge badge-info float-right">Running</span>
                        <?php } elseif ($job && $job['cron_job_last_status'] === 'Completed') { ?><span class="badge badge-success float-right">Completed</span>
                        <?php } elseif ($job && $job['cron_job_last_status'] === 'Failed') { ?><span class="badge badge-danger float-right">Failed</span>
                        <?php } elseif ($job && $job['cron_job_last_status']) { ?><span class="badge badge-secondary float-right">Stopped</span>
                        <?php } else { ?><span class="badge badge-light float-right">Not run</span><?php } ?>
                        <div class="small text-muted"><?= !empty($job['cron_job_last_run_at']) ? escapeHtml(timeAgo($job['cron_job_last_run_at'])) : 'Never' ?></div>
                    </div>
                <?php } ?>

                <?php if (!$config_enable_cron) { ?>
                    <div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle mr-2"></i>Cron is off. Scheduled sync and webhook processing will not run.</div>
                <?php } ?>

                <div class="btn-group btn-block">
                    <form class="w-50" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="btn btn-outline-primary btn-block" type="submit" name="test_level_connection"><i class="fas fa-plug mr-2"></i>Test</button>
                    </form>
                    <form class="w-50" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="btn btn-primary btn-block" type="submit" name="queue_level_sync"><i class="fas fa-sync-alt mr-2"></i>Sync Now</button>
                    </form>
                </div>
                <?php if (intval($stats['queued_events'] ?? 0) > 0) { ?>
                    <form class="mt-2" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="btn btn-outline-secondary btn-block" type="submit" name="queue_level_webhooks"><i class="fas fa-inbox mr-2"></i>Process Events</button>
                    </form>
                <?php } ?>
            </div>
        </div>

        <?php if ($sync_conflicts) { ?>
            <div class="card card-outline card-warning">
                <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-exclamation-triangle mr-2"></i>Client Mapping Conflicts</h5></div>
                <div class="card-body p-0">
                    <?php foreach ($sync_conflicts as $conflict) { ?>
                        <div class="p-3 border-bottom">
                            <a href="/agent/asset.php?asset_id=<?= intval($conflict['asset_id']) ?>"><strong><?= escapeHtml($conflict['asset_name']) ?></strong></a>
                            <div class="small text-muted">Current ITFlow client: <?= escapeHtml($conflict['client_name'] ?: 'None') ?></div>
                            <div class="small text-muted">Level group: <?= escapeHtml($conflict['level_group_name'] ?: 'Unknown') ?></div>
                            <div class="small mt-1"><?= escapeHtml($conflict['level_device_sync_message']) ?></div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-bolt mr-2"></i>Webhook</h5></div>
            <div class="card-body">
                <label>Endpoint URL</label>
                <div class="input-group">
                    <input class="form-control text-monospace" id="levelWebhookUrl" readonly value="<?= escapeHtml($webhook_url) ?>">
                    <div class="input-group-append"><button class="btn btn-light clipboardjs" type="button" data-clipboard-target="#levelWebhookUrl"><i class="far fa-copy"></i></button></div>
                </div>
                <small class="form-text text-muted">In Level, open Settings &gt; Webhooks, use this HTTPS URL, paste the signing secret, and subscribe to all alert, device, and group events.</small>

                <form class="mt-3" action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button class="btn btn-outline-warning btn-block" type="submit" name="generate_level_webhook_secret"
                        onclick="return confirm('Generate a new secret? Level webhook deliveries will fail until the same secret is saved in Level.')"><i class="fas fa-key mr-2"></i>Generate New Secret</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="fas fa-fw fa-list-ol mr-2"></i>Setup Order</h5></div>
            <div class="card-body small">
                <ol class="pl-3 mb-3">
                    <li>Create a read-only API key in Level and save it here.</li>
                    <li>Test the connection, then discover Level groups.</li>
                    <li>Map parent or child groups to ITFlow clients.</li>
                    <li>Add the signed webhook in Level using the URL above.</li>
                    <li>Enable ITFlow cron and run the first reconciliation.</li>
                </ol>
                <a href="https://docs.level.io/en/articles/12152745-level-public-api" target="_blank" rel="noopener noreferrer">Level API documentation <i class="fas fa-external-link-alt ml-1"></i></a><br>
                <a href="https://docs.level.io/en/articles/13909290-webhook-settings" target="_blank" rel="noopener noreferrer">Level webhook documentation <i class="fas fa-external-link-alt ml-1"></i></a>
            </div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
