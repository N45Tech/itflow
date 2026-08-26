<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

$selected_source = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) ($_GET['source'] ?? '')));
$source_filter_incident = '';
$source_filter_event = '';
$source_filter_mapping = '';
if ($selected_source !== '') {
    $selected_source_sql = escapeSql($selected_source);
    $source_filter_incident = "AND automation_incident_source = '$selected_source_sql'";
    $source_filter_event = "AND automation_event_source = '$selected_source_sql'";
    $source_filter_mapping = "AND automation_mapping_source = '$selected_source_sql'";
}

$incident_scope = clientScopeSql('automation_incident_client_id');
$mapping_scope = clientScopeSql('automation_mapping_client_id');
$ticket_scope = clientScopeSql('ticket_client_id');
$level_asset_scope = clientScopeSql('assets.asset_client_id');

$source_label = static function ($source) {
    $labels = [
        'uptime_kuma' => 'Uptime Kuma',
        'netbox' => 'NetBox',
        'n8n' => 'n8n',
        'backup' => 'Backups',
        'level' => 'Level.io',
        'level_io' => 'Level.io',
    ];
    $source = strtolower((string) $source);
    return $labels[$source] ?? ucwords(str_replace(['_', '-'], ' ', $source));
};

$source_icon = static function ($source) {
    return match (strtolower((string) $source)) {
        'uptime_kuma' => 'fa-heartbeat',
        'netbox' => 'fa-project-diagram',
        'n8n' => 'fa-random',
        'backup' => 'fa-database',
        'level', 'level_io' => 'fa-satellite',
        default => 'fa-bolt',
    };
};

$severity_badge = static function ($severity) {
    return match (strtolower((string) $severity)) {
        'emergency', 'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        default => 'secondary',
    };
};

$action_badge = static function ($action) {
    return match (strtolower((string) $action)) {
        'created' => 'danger',
        'updated' => 'warning',
        'resolved', 'recovery_recorded' => 'success',
        'unchanged' => 'secondary',
        'recovery_without_open_incident' => 'info',
        default => 'light',
    };
};

$automation_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    COUNT(*) AS incident_count,
    SUM(automation_incident_status = 'Open') AS open_incidents,
    SUM(automation_incident_status = 'Open' AND LOWER(automation_incident_severity) IN ('high', 'critical', 'emergency')) AS high_open_incidents,
    SUM(automation_incident_status = 'Resolved' AND automation_incident_resolved_at >= NOW() - INTERVAL 24 HOUR) AS recovered_24h,
    MAX(automation_incident_last_event_at) AS last_event_at
    FROM automation_incidents
    WHERE 1 = 1 $incident_scope $source_filter_incident"));

$event_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    SUM(automation_event_received_at >= NOW() - INTERVAL 24 HOUR) AS events_24h,
    SUM(automation_event_received_at >= NOW() - INTERVAL 7 DAY) AS events_7d,
    SUM(automation_event_action = 'unchanged' AND automation_event_received_at >= NOW() - INTERVAL 24 HOUR) AS suppressed_24h
    FROM automation_events
    INNER JOIN automation_incidents ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    WHERE 1 = 1 $incident_scope $source_filter_event"));

$mapping_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    COUNT(*) AS mapping_count,
    SUM(automation_mapping_last_seen_at < NOW() - INTERVAL 30 DAY) AS stale_mappings,
    MAX(automation_mapping_last_seen_at) AS last_mapping_at
    FROM automation_entity_mappings
    WHERE automation_mapping_deleted_at IS NULL $mapping_scope $source_filter_mapping"));

$ticket_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    COUNT(*) AS open_tickets,
    SUM(ticket_assigned_to = $session_user_id) AS my_tickets,
    SUM(ticket_assigned_to = 0) AS unassigned_tickets,
    SUM(ticket_sla_id > 0 AND (ticket_response_sla_alert_stage = 1 OR ticket_resolution_sla_alert_stage = 1)) AS sla_at_risk,
    SUM(ticket_sla_id > 0 AND (ticket_response_sla_alert_stage = 2 OR ticket_resolution_sla_alert_stage = 2 OR ticket_response_sla_met = 0 OR ticket_resolution_sla_met = 0)) AS sla_breached
    FROM tickets
    WHERE ticket_archived_at IS NULL AND ticket_resolved_at IS NULL $ticket_scope"));

$level_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    COUNT(*) AS managed_assets,
    SUM(level_device_online = 1) AS online_assets,
    SUM(level_device_online = 0) AS offline_assets,
    SUM(level_device_sync_status = 'Conflict') AS sync_conflicts,
    MAX(level_device_last_synced_at) AS last_sync_at
    FROM level_asset_links
    INNER JOIN assets ON level_asset_id = asset_id
    WHERE level_device_deleted_at IS NULL $level_asset_scope"));

$level_open_alerts = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*)
    FROM level_alert_links
    LEFT JOIN assets ON level_alert_links.level_asset_id = assets.asset_id
    WHERE level_alert_resolved_at IS NULL AND level_ticket_id IS NOT NULL $level_asset_scope"))[0]);

$level_failed_events = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM level_webhook_events
    WHERE level_webhook_status IN ('Pending', 'Failed')"))[0]);

$level_cron_rows = [];
$sql_level_cron = mysqli_query($mysqli, "SELECT cron_job_last_error, cron_job_last_run_at,
    cron_job_last_status, cron_job_name, cron_job_run_now
    FROM cron_jobs WHERE cron_job_name IN ('level_sync', 'level_webhook_processor')");
while ($cron_row = mysqli_fetch_assoc($sql_level_cron)) {
    $level_cron_rows[$cron_row['cron_job_name']] = $cron_row;
}
$level_job_attention = false;
$level_job_last_run = '';
foreach ($level_cron_rows as $level_cron_row) {
    if ($level_cron_row['cron_job_last_status'] === 'Failed' || intval($level_cron_row['cron_job_run_now'])) {
        $level_job_attention = true;
    }
    if ($level_cron_row['cron_job_last_run_at'] > $level_job_last_run) {
        $level_job_last_run = $level_cron_row['cron_job_last_run_at'];
    }
}

$source_health = [];
$sql_source_incidents = mysqli_query($mysqli, "SELECT automation_incident_source AS source,
    COUNT(*) AS incident_count,
    SUM(automation_incident_status = 'Open') AS open_count,
    MAX(automation_incident_last_event_at) AS last_event_at
    FROM automation_incidents WHERE 1 = 1 $incident_scope
    GROUP BY automation_incident_source");
while ($row = mysqli_fetch_assoc($sql_source_incidents)) {
    $source_health[$row['source']] = $row;
}

$sql_source_events = mysqli_query($mysqli, "SELECT automation_event_source AS source,
    SUM(automation_event_received_at >= NOW() - INTERVAL 24 HOUR) AS events_24h,
    MAX(automation_event_received_at) AS last_received_at
    FROM automation_events
    INNER JOIN automation_incidents ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    WHERE 1 = 1 $incident_scope
    GROUP BY automation_event_source");
while ($row = mysqli_fetch_assoc($sql_source_events)) {
    if (!isset($source_health[$row['source']])) {
        $source_health[$row['source']] = ['source' => $row['source'], 'incident_count' => 0, 'open_count' => 0, 'last_event_at' => null];
    }
    $source_health[$row['source']]['events_24h'] = $row['events_24h'];
    $source_health[$row['source']]['last_received_at'] = $row['last_received_at'];
}

$sql_source_mappings = mysqli_query($mysqli, "SELECT automation_mapping_source AS source,
    COUNT(*) AS mapping_count, MAX(automation_mapping_last_seen_at) AS last_mapping_at
    FROM automation_entity_mappings
    WHERE automation_mapping_deleted_at IS NULL $mapping_scope
    GROUP BY automation_mapping_source");
while ($row = mysqli_fetch_assoc($sql_source_mappings)) {
    if (!isset($source_health[$row['source']])) {
        $source_health[$row['source']] = ['source' => $row['source'], 'incident_count' => 0, 'open_count' => 0, 'last_event_at' => null];
    }
    $source_health[$row['source']]['mapping_count'] = $row['mapping_count'];
    $source_health[$row['source']]['last_mapping_at'] = $row['last_mapping_at'];
}

foreach (['uptime_kuma', 'netbox', 'n8n'] as $known_source) {
    if (!isset($source_health[$known_source])) {
        $source_health[$known_source] = [
            'source' => $known_source,
            'incident_count' => 0,
            'open_count' => 0,
            'events_24h' => 0,
            'mapping_count' => 0,
            'last_event_at' => null,
            'last_received_at' => null,
            'last_mapping_at' => null,
        ];
    }
}

$source_order = ['uptime_kuma' => 10, 'netbox' => 20, 'n8n' => 30];
uksort($source_health, static function ($a, $b) use ($source_order) {
    return ($source_order[$a] ?? 100) <=> ($source_order[$b] ?? 100) ?: strcmp($a, $b);
});

$attention_items = [];
$open_incidents = intval($automation_stats['open_incidents'] ?? 0);
$high_open_incidents = intval($automation_stats['high_open_incidents'] ?? 0);
$unassigned_tickets = intval($ticket_stats['unassigned_tickets'] ?? 0);
$sla_at_risk = intval($ticket_stats['sla_at_risk'] ?? 0);
$sla_breached = intval($ticket_stats['sla_breached'] ?? 0);
$level_conflicts = intval($level_stats['sync_conflicts'] ?? 0);

if ($high_open_incidents) {
    $attention_items[] = ['danger', 'fa-fire-alt', "$high_open_incidents high-severity automation incident" . ($high_open_incidents === 1 ? '' : 's'), '#automation-incidents'];
} elseif ($open_incidents) {
    $attention_items[] = ['warning', 'fa-bell', "$open_incidents open automation incident" . ($open_incidents === 1 ? '' : 's'), '#automation-incidents'];
}
if ($sla_breached) {
    $attention_items[] = ['danger', 'fa-stopwatch', "$sla_breached breached SLA" . ($sla_breached === 1 ? '' : 's'), '/agent/tickets.php?sla=breached'];
} elseif ($sla_at_risk) {
    $attention_items[] = ['warning', 'fa-hourglass-half', "$sla_at_risk SLA" . ($sla_at_risk === 1 ? '' : 's') . ' at risk', '/agent/tickets.php?sla=at_risk'];
}
if ($unassigned_tickets) {
    $attention_items[] = ['warning', 'fa-user-slash', "$unassigned_tickets unassigned ticket" . ($unassigned_tickets === 1 ? '' : 's'), '/agent/tickets.php?assigned=unassigned'];
}
if ($level_conflicts) {
    $attention_items[] = ['danger', 'fa-random', "$level_conflicts Level.io mapping conflict" . ($level_conflicts === 1 ? '' : 's'), $session_is_admin ? '/admin/integration_level.php' : '/agent/assets.php'];
}
if ($level_failed_events) {
    $attention_items[] = ['warning', 'fa-inbox', "$level_failed_events queued or failed Level.io event" . ($level_failed_events === 1 ? '' : 's'), $session_is_admin ? '/admin/integration_level.php' : '#integration-health'];
}
if ($level_job_attention) {
    $attention_items[] = ['warning', 'fa-sync-alt', 'Level.io background job needs review', $session_is_admin ? '/admin/integration_level.php' : '#integration-health'];
}

$sql_open_incidents = mysqli_query($mysqli, "SELECT automation_incidents.*,
    client_name, location_name, asset_name, ticket_prefix, ticket_number
    FROM automation_incidents
    LEFT JOIN clients ON automation_incident_client_id = client_id
    LEFT JOIN locations ON automation_incident_location_id = location_id
    LEFT JOIN assets ON automation_incident_asset_id = asset_id
    LEFT JOIN tickets ON automation_incident_ticket_id = ticket_id
    WHERE automation_incident_status = 'Open' $incident_scope $source_filter_incident
    ORDER BY CASE LOWER(automation_incident_severity)
        WHEN 'emergency' THEN 1 WHEN 'critical' THEN 2 WHEN 'high' THEN 3
        WHEN 'medium' THEN 4 WHEN 'low' THEN 5 ELSE 6 END,
        automation_incident_last_event_at DESC LIMIT 25");

$sql_recent_events = mysqli_query($mysqli, "SELECT automation_events.*,
    automation_incident_client_id, client_name
    FROM automation_events
    INNER JOIN automation_incidents ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    LEFT JOIN clients ON automation_incident_client_id = client_id
    WHERE 1 = 1 $incident_scope $source_filter_event
    ORDER BY automation_event_received_at DESC LIMIT 20");

$sql_recent_mappings = mysqli_query($mysqli, "SELECT automation_entity_mappings.*,
    client_name, location_name, asset_name, domain_name
    FROM automation_entity_mappings
    LEFT JOIN clients ON automation_mapping_client_id = client_id
    LEFT JOIN locations ON automation_mapping_location_id = location_id
    LEFT JOIN assets ON automation_mapping_asset_id = asset_id
    LEFT JOIN domains ON automation_mapping_domain_id = domain_id
    WHERE automation_mapping_deleted_at IS NULL $mapping_scope $source_filter_mapping
    ORDER BY automation_mapping_last_seen_at DESC, automation_mapping_id DESC LIMIT 20");

?>

<div class="n45-ops-shell">
    <header class="n45-ops-header">
        <div>
            <div class="n45-ops-title-row">
                <span class="n45-live-dot" aria-hidden="true"></span>
                <h1>Operations</h1>
            </div>
            <p>One place to triage technician work, integration health, and automatically discovered infrastructure.</p>
        </div>
        <div class="n45-ops-actions" aria-label="Operations shortcuts">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="/agent/modals/ticket/ticket_add.php" data-modal-size="lg"><i class="fas fa-plus mr-2"></i>New ticket</button>
            <a class="btn btn-outline-secondary" href="https://app.level.io/devices" target="_blank" rel="noopener noreferrer">Level.io <i class="fas fa-external-link-alt ml-2"></i></a>
            <a class="btn btn-outline-secondary" href="https://netbox.n45tech.com" target="_blank" rel="noopener noreferrer">NetBox <i class="fas fa-external-link-alt ml-2"></i></a>
            <a class="btn btn-outline-secondary" href="https://automate.n45tech.com" target="_blank" rel="noopener noreferrer">n8n <i class="fas fa-external-link-alt ml-2"></i></a>
        </div>
    </header>

    <nav class="n45-source-filter" aria-label="Filter operations by source">
        <a href="/agent/operations.php" class="<?= $selected_source === '' ? 'active' : '' ?>">All sources</a>
        <?php foreach ($source_health as $source => $health) { ?>
            <a href="/agent/operations.php?source=<?= urlencode($source) ?>" class="<?= $selected_source === $source ? 'active' : '' ?>">
                <i class="fas <?= $source_icon($source) ?> mr-1"></i><?= escapeHtml($source_label($source)) ?>
                <?php if (intval($health['open_count'] ?? 0)) { ?><span><?= intval($health['open_count']) ?></span><?php } ?>
            </a>
        <?php } ?>
    </nav>

    <div class="n45-kpi-strip" aria-label="Current operational summary">
        <a href="/agent/tickets.php?assigned=<?= $session_user_id ?>">
            <span>My queue</span>
            <strong><?= intval($ticket_stats['my_tickets'] ?? 0) ?></strong>
            <small>open tickets</small>
        </a>
        <a href="#automation-incidents">
            <span>Automation</span>
            <strong><?= $open_incidents ?></strong>
            <small>open incidents</small>
        </a>
        <a href="/agent/tickets.php?sla=<?= $sla_breached ? 'breached' : 'at_risk' ?>">
            <span>SLA pressure</span>
            <strong><?= $sla_breached + $sla_at_risk ?></strong>
            <small><?= $sla_breached ?> breached · <?= $sla_at_risk ?> at risk</small>
        </a>
        <a href="#recent-activity">
            <span>Signal volume</span>
            <strong><?= intval($event_stats['events_24h'] ?? 0) ?></strong>
            <small><?= intval($event_stats['suppressed_24h'] ?? 0) ?> duplicates suppressed</small>
        </a>
        <a href="#entity-mappings">
            <span>Known mappings</span>
            <strong><?= intval($mapping_stats['mapping_count'] ?? 0) + intval($level_stats['managed_assets'] ?? 0) ?></strong>
            <small>automation + Level.io</small>
        </a>
    </div>

    <div class="row">
        <div class="col-xl-7">
            <section class="n45-panel" aria-labelledby="attention-heading">
                <div class="n45-panel-heading">
                    <div>
                        <h2 id="attention-heading">Needs attention</h2>
                        <p>Exceptions that benefit from a technician decision.</p>
                    </div>
                    <a href="/agent/tickets.php">Open ticket queue <i class="fas fa-arrow-right ml-1"></i></a>
                </div>
                <?php if ($attention_items) { ?>
                    <div class="n45-attention-list">
                        <?php foreach ($attention_items as [$tone, $icon, $label, $url]) { ?>
                            <a href="<?= escapeHtml($url) ?>">
                                <span class="n45-state-icon n45-state-<?= $tone ?>"><i class="fas <?= $icon ?>"></i></span>
                                <strong><?= escapeHtml($label) ?></strong>
                                <i class="fas fa-chevron-right text-muted ml-auto"></i>
                            </a>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="n45-calm-state">
                        <i class="fas fa-check-circle"></i>
                        <div><strong>No operational exceptions</strong><span>Queues, automations, mappings, and SLAs are clear.</span></div>
                    </div>
                <?php } ?>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="n45-panel" id="integration-health" aria-labelledby="integration-heading">
                <div class="n45-panel-heading">
                    <div>
                        <h2 id="integration-heading">Integration health</h2>
                        <p>Live signals from systems that feed ITFlow.</p>
                    </div>
                    <?php if ($session_is_admin) { ?><a href="/admin/integration_level.php">Manage <i class="fas fa-cog ml-1"></i></a><?php } ?>
                </div>
                <div class="n45-health-list">
                    <div class="n45-health-row">
                        <span class="n45-system-icon"><i class="fas fa-satellite"></i></span>
                        <div>
                            <strong>Level.io</strong>
                            <span><?= intval($level_stats['online_assets'] ?? 0) ?>/<?= intval($level_stats['managed_assets'] ?? 0) ?> devices online · <?= $level_open_alerts ?> active alerts<?= $level_job_last_run ? ' · synced ' . escapeHtml(timeAgo($level_job_last_run)) : '' ?></span>
                        </div>
                        <span class="badge badge-<?= !$config_level_enable ? 'secondary' : ($level_conflicts || $level_failed_events || $level_job_attention ? 'warning' : 'success') ?>"><?= !$config_level_enable ? 'Disabled' : ($level_conflicts || $level_failed_events || $level_job_attention ? 'Attention' : 'Healthy') ?></span>
                    </div>
                    <?php foreach ($source_health as $source => $health) {
                        $open_count = intval($health['open_count'] ?? 0);
                        $last_seen = $health['last_received_at'] ?? $health['last_event_at'] ?? $health['last_mapping_at'] ?? '';
                        $mapping_count = intval($health['mapping_count'] ?? 0);
                        $events_24h = intval($health['events_24h'] ?? 0);
                        ?>
                        <div class="n45-health-row">
                            <span class="n45-system-icon"><i class="fas <?= $source_icon($source) ?>"></i></span>
                            <div>
                                <strong><?= escapeHtml($source_label($source)) ?></strong>
                                <span><?= $events_24h ?> events today · <?= $mapping_count ?> mappings<?= $last_seen ? ' · seen ' . escapeHtml(timeAgo($last_seen)) : ' · ready for first signal' ?></span>
                            </div>
                            <span class="badge badge-<?= $open_count ? 'warning' : ($last_seen ? 'success' : 'info') ?>"><?= $open_count ? "$open_count open" : ($last_seen ? 'Connected' : 'Ready') ?></span>
                        </div>
                    <?php } ?>
                </div>
            </section>
        </div>
    </div>

    <section class="n45-panel" id="automation-incidents" aria-labelledby="incidents-heading">
        <div class="n45-panel-heading">
            <div>
                <h2 id="incidents-heading">Open automation incidents</h2>
                <p>Correlated alerts stay on one ticket until the source reports recovery.</p>
            </div>
            <span class="n45-panel-count"><?= mysqli_num_rows($sql_open_incidents) ?> shown</span>
        </div>
        <?php if (mysqli_num_rows($sql_open_incidents)) { ?>
            <div class="table-responsive">
                <table class="table table-hover n45-ops-table mb-0">
                    <thead><tr><th>Incident</th><th>Client / object</th><th>Signal</th><th>Last event</th><th>Ticket</th></tr></thead>
                    <tbody>
                    <?php while ($incident = mysqli_fetch_assoc($sql_open_incidents)) {
                        $incident_id = intval($incident['automation_incident_id']);
                        $incident_ticket_id = intval($incident['automation_incident_ticket_id']);
                        $incident_client_id = intval($incident['automation_incident_client_id']);
                        $incident_asset_id = intval($incident['automation_incident_asset_id']);
                        ?>
                        <tr id="incident-<?= $incident_id ?>">
                            <td>
                                <div class="d-flex align-items-start">
                                    <span class="n45-state-icon n45-state-<?= in_array(strtolower($incident['automation_incident_severity']), ['emergency', 'critical', 'high']) ? 'danger' : 'warning' ?> mr-2"><i class="fas <?= $source_icon($incident['automation_incident_source']) ?>"></i></span>
                                    <div><strong><?= escapeHtml($incident['automation_incident_title']) ?></strong><div class="small text-muted"><?= escapeHtml($source_label($incident['automation_incident_source'])) ?></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($incident_client_id) { ?><a href="/agent/client_overview.php?client_id=<?= $incident_client_id ?>"><?= escapeHtml($incident['client_name']) ?></a><?php } else { ?><span class="text-muted">No client</span><?php } ?>
                                <?php if ($incident_asset_id) { ?><div class="small"><a href="/agent/asset.php?asset_id=<?= $incident_asset_id ?>"><?= escapeHtml($incident['asset_name']) ?></a></div><?php } elseif (!empty($incident['location_name'])) { ?><div class="small text-muted"><?= escapeHtml($incident['location_name']) ?></div><?php } ?>
                            </td>
                            <td><span class="badge badge-<?= $severity_badge($incident['automation_incident_severity']) ?>"><?= escapeHtml(ucfirst($incident['automation_incident_severity'])) ?></span><div class="small text-muted mt-1"><?= intval($incident['automation_incident_event_count']) ?> event<?= intval($incident['automation_incident_event_count']) === 1 ? '' : 's' ?></div></td>
                            <td><span title="<?= escapeHtml($incident['automation_incident_last_event_at']) ?>"><?= $incident['automation_incident_last_event_at'] ? escapeHtml(timeAgo($incident['automation_incident_last_event_at'])) : '—' ?></span></td>
                            <td><?php if ($incident_ticket_id) { ?><a class="btn btn-sm btn-outline-primary" href="/agent/ticket.php?ticket_id=<?= $incident_ticket_id ?>"><?= escapeHtml($incident['ticket_prefix']) . intval($incident['ticket_number']) ?></a><?php } else { ?><span class="text-muted">No ticket</span><?php } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="n45-empty-state"><i class="fas fa-shield-alt"></i><strong>No open incidents</strong><span>New alerts from Uptime Kuma, n8n, or other brokers will appear here and link to their ITFlow ticket.</span></div>
        <?php } ?>
    </section>

    <div class="row">
        <div class="col-xl-7">
            <section class="n45-panel" id="recent-activity" aria-labelledby="activity-heading">
                <div class="n45-panel-heading"><div><h2 id="activity-heading">Recent automation activity</h2><p>Processed signals, including recoveries and duplicate suppression.</p></div></div>
                <?php if (mysqli_num_rows($sql_recent_events)) { ?>
                    <div class="n45-activity-list">
                        <?php while ($event = mysqli_fetch_assoc($sql_recent_events)) { ?>
                            <div class="n45-activity-row">
                                <span class="n45-state-icon n45-state-<?= strtolower($event['automation_event_state']) === 'resolved' ? 'success' : (strtolower($event['automation_event_state']) === 'open' ? 'danger' : 'warning') ?>"><i class="fas <?= $source_icon($event['automation_event_source']) ?>"></i></span>
                                <div class="n45-activity-copy">
                                    <strong><?= escapeHtml($source_label($event['automation_event_source'])) ?> · <?= escapeHtml($event['automation_event_incident_key']) ?></strong>
                                    <span><?= $event['client_name'] ? escapeHtml($event['client_name']) . ' · ' : '' ?><?= escapeHtml(ucwords(str_replace('_', ' ', $event['automation_event_action']))) ?></span>
                                </div>
                                <div class="text-right"><span class="badge badge-<?= $action_badge($event['automation_event_action']) ?>"><?= escapeHtml(ucfirst($event['automation_event_state'])) ?></span><small title="<?= escapeHtml($event['automation_event_received_at']) ?>"><?= escapeHtml(timeAgo($event['automation_event_received_at'])) ?></small></div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="n45-empty-state"><i class="fas fa-stream"></i><strong>No activity for this source yet</strong><span>The integration is ready; processed events will build an audit trail here.</span></div>
                <?php } ?>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="n45-panel" id="entity-mappings" aria-labelledby="mappings-heading">
                <div class="n45-panel-heading"><div><h2 id="mappings-heading">Recently seen mappings</h2><p>Durable links that prevent duplicate clients, sites, and assets.</p></div></div>
                <?php if (mysqli_num_rows($sql_recent_mappings)) { ?>
                    <div class="n45-mapping-list">
                        <?php while ($mapping = mysqli_fetch_assoc($sql_recent_mappings)) {
                            $object_name = $mapping['asset_name'] ?: ($mapping['domain_name'] ?: ($mapping['location_name'] ?: $mapping['client_name']));
                            $object_url = '';
                            if (intval($mapping['automation_mapping_asset_id'])) {
                                $object_url = '/agent/asset.php?asset_id=' . intval($mapping['automation_mapping_asset_id']);
                            } elseif (intval($mapping['automation_mapping_domain_id'])) {
                                $object_url = '/agent/domains.php?domain_id=' . intval($mapping['automation_mapping_domain_id']);
                            } elseif (intval($mapping['automation_mapping_client_id'])) {
                                $object_url = '/agent/client_overview.php?client_id=' . intval($mapping['automation_mapping_client_id']);
                            }
                            ?>
                            <div class="n45-mapping-row">
                                <span class="n45-system-icon"><i class="fas <?= $source_icon($mapping['automation_mapping_source']) ?>"></i></span>
                                <div>
                                    <strong><?= escapeHtml($mapping['automation_mapping_external_name'] ?: $mapping['automation_mapping_external_id']) ?></strong>
                                    <span><?= escapeHtml($source_label($mapping['automation_mapping_source'])) ?> · <?= escapeHtml($mapping['automation_mapping_entity_type']) ?> · <?= escapeHtml(str_replace('_', ' ', $mapping['automation_mapping_strategy'])) ?></span>
                                </div>
                                <?php if ($object_url) { ?><a href="<?= escapeHtml($object_url) ?>" title="Open <?= escapeHtml($object_name) ?>"><i class="fas fa-arrow-right"></i></a><?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="n45-empty-state"><i class="fas fa-link"></i><strong>No mappings for this source</strong><span>Mappings appear automatically after the first successful reconciliation.</span></div>
                <?php } ?>
            </section>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
