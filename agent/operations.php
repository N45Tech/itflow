<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');
$can_review_identities = lookupUserPermission('module_support') >= 2;

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
$bound_identity_scope = $session_is_admin ? '' : 'AND automation_mapping_client_id > 0';
$ticket_scope = clientScopeSql('ticket_client_id');
$level_asset_scope = clientScopeSql('assets.asset_client_id');

$source_label = static function ($source) {
    $labels = [
        'uptime_kuma' => 'Uptime Kuma',
        'netbox' => 'NetBox',
        'n8n' => 'n8n',
        'backup' => 'Backups',
        'checkmk' => 'Checkmk',
        'cipp' => 'CIPP',
        'entra' => 'Microsoft Entra',
        'infrastructure' => 'Infrastructure',
        'intune' => 'Microsoft Intune',
        'level' => 'Level.io',
        'level_io' => 'Level.io',
        'sentinelone' => 'SentinelOne',
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
        'checkmk' => 'fa-heartbeat',
        'cipp', 'entra', 'intune' => 'fa-cloud',
        'infrastructure' => 'fa-server',
        'level', 'level_io' => 'fa-satellite',
        'sentinelone' => 'fa-shield-alt',
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
        'unchanged', 'duplicate', 'stale' => 'secondary',
        'maintenance_suppressed', 'threshold_waiting', 'source_disabled' => 'info',
        'processing_failed' => 'danger',
        'recovery_without_open_incident' => 'info',
        default => 'light',
    };
};

$identity_state_badge = static function ($state) {
    return match (strtolower((string) $state)) {
        'confirmed' => 'success',
        'automatic' => 'primary',
        'suggested', 'unresolved' => 'warning',
        'conflicting' => 'danger',
        'stale', 'retired' => 'secondary',
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
    SUM(GREATEST(automation_event_delivery_count - 1, 0)) AS duplicate_deliveries,
    SUM(automation_event_suppressed_reason IS NOT NULL
        AND automation_event_received_at >= NOW() - INTERVAL 24 HOUR) AS suppressed_24h
    FROM automation_events
    INNER JOIN automation_incidents ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    WHERE 1 = 1 $incident_scope $source_filter_event"));

$event_queue_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    SUM(automation_event_status = 'Pending') AS pending_events,
    SUM(automation_event_status = 'Processing') AS processing_events,
    SUM(automation_event_status = 'Failed') AS failed_events,
    SUM(automation_event_status = 'Dead') AS dead_events
    FROM automation_events
    LEFT JOIN automation_incidents ON automation_incident_source = automation_event_source
        AND automation_incident_key = automation_event_incident_key
    WHERE 1 = 1 $incident_scope $source_filter_event"));

$active_maintenance_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*)
    FROM automation_maintenance_windows
    WHERE automation_maintenance_deleted_at IS NULL
    AND automation_maintenance_starts_at <= NOW()
    AND automation_maintenance_ends_at >= NOW()"))[0] ?? 0);

$mapping_stats = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    COUNT(*) AS mapping_count,
    SUM(automation_mapping_state = 'stale') AS stale_mappings,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state IN ('unresolved', 'suggested')) AS unresolved_devices,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state = 'conflicting') AS conflicting_devices,
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
    WHERE ticket_deleted_at IS NULL AND ticket_archived_at IS NULL AND ticket_resolved_at IS NULL $ticket_scope"));

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
$identity_cron = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT cron_job_last_error,
    cron_job_last_run_at, cron_job_last_status, cron_job_run_now
    FROM cron_jobs WHERE cron_job_name = 'identity_reconciliation' LIMIT 1")) ?: [];
$identity_job_attention = ($identity_cron['cron_job_last_status'] ?? '') === 'Failed'
    || intval($identity_cron['cron_job_run_now'] ?? 0) === 1;

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
    MAX(automation_event_last_received_at) AS last_received_at
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
    COUNT(*) AS mapping_count,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state IN ('automatic', 'confirmed')) AS healthy_mapping_count,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state IN ('unresolved', 'suggested', 'conflicting')) AS review_mapping_count,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state = 'conflicting') AS conflict_mapping_count,
    SUM(automation_mapping_entity_type = 'device'
        AND automation_mapping_state = 'stale') AS stale_mapping_count,
    MAX(automation_mapping_last_seen_at) AS last_mapping_at
    FROM automation_entity_mappings
    WHERE automation_mapping_deleted_at IS NULL $mapping_scope
    GROUP BY automation_mapping_source");
while ($row = mysqli_fetch_assoc($sql_source_mappings)) {
    if (!isset($source_health[$row['source']])) {
        $source_health[$row['source']] = ['source' => $row['source'], 'incident_count' => 0, 'open_count' => 0, 'last_event_at' => null];
    }
    $source_health[$row['source']]['mapping_count'] = $row['mapping_count'];
    $source_health[$row['source']]['healthy_mapping_count'] = $row['healthy_mapping_count'];
    $source_health[$row['source']]['review_mapping_count'] = $row['review_mapping_count'];
    $source_health[$row['source']]['conflict_mapping_count'] = $row['conflict_mapping_count'];
    $source_health[$row['source']]['stale_mapping_count'] = $row['stale_mapping_count'];
    $source_health[$row['source']]['last_mapping_at'] = $row['last_mapping_at'];
}

foreach (['level', 'sentinelone', 'checkmk', 'cipp', 'entra', 'intune', 'backup', 'infrastructure', 'uptime_kuma', 'netbox', 'n8n'] as $known_source) {
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

$source_order = [
    'level' => 10, 'sentinelone' => 20, 'checkmk' => 30, 'cipp' => 40,
    'entra' => 50, 'intune' => 60, 'backup' => 70, 'infrastructure' => 80,
    'uptime_kuma' => 90, 'netbox' => 100, 'n8n' => 110,
];
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
$identity_unresolved_devices = intval($mapping_stats['unresolved_devices'] ?? 0);
$identity_conflicting_devices = intval($mapping_stats['conflicting_devices'] ?? 0);
$identity_stale_devices = intval($mapping_stats['stale_mappings'] ?? 0);
$identity_review_devices = $identity_unresolved_devices + $identity_conflicting_devices;
$identity_health_attention = $identity_stale_devices > 0 || $identity_review_devices > 0;
$device_identity_conflicts = max($level_conflicts, $identity_conflicting_devices);
$failed_events = intval($event_queue_stats['failed_events'] ?? 0);
$dead_events = intval($event_queue_stats['dead_events'] ?? 0);

$documentation_scope = clientScopeSql('o.documentation_obligation_client_id');
$documentation_validity = documentationObligationValiditySql('o');
$documentation_attention_rows = [];
$sql_documentation_rows = documentationDbQuery("SELECT o.*, c.client_name, d.document_name,
    {$documentation_validity['select']}
    FROM client_documentation_obligations o
    INNER JOIN clients c ON c.client_id = o.documentation_obligation_client_id
    LEFT JOIN documents d ON d.document_id = o.documentation_obligation_document_id
    {$documentation_validity['joins']}
    WHERE c.client_archived_at IS NULL $documentation_scope
    ORDER BY o.documentation_obligation_client_id, o.documentation_obligation_id", 'Could not project Operations documentation attention');
while ($documentation_row = mysqli_fetch_assoc($sql_documentation_rows)) {
    $documentation_row = documentationApplyCurrentRequirementMetadata($documentation_row);
    $documentation_projection = documentationProjectObligationValidity($documentation_row);
    $documentation_row['effective_status'] = $documentation_projection['effective_status'];
    if (in_array($documentation_row['effective_status'], ['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'], true)) {
        $documentation_attention_rows[] = $documentation_row;
    }
}

$documentation_projection_clients = [];
$documentation_client_scope = clientScopeSql('client_id');
$sql_documentation_clients = documentationDbQuery("SELECT client_id, client_name, client_type, client_archived_at
    FROM clients WHERE client_archived_at IS NULL $documentation_client_scope
    ORDER BY client_id", 'Could not load Operations clients for pending documentation projections');
while ($documentation_client = mysqli_fetch_assoc($sql_documentation_clients)) {
    $documentation_projection_clients[] = $documentation_client;
}
foreach (documentationPendingObligationRowsForClients($documentation_projection_clients, 0) as $documentation_row) {
    $documentation_projection = documentationProjectObligationValidity($documentation_row);
    $documentation_row['effective_status'] = $documentation_projection['effective_status'];
    $documentation_attention_rows[] = $documentation_row;
}
usort($documentation_attention_rows, static function ($left, $right) {
    $priority = ['Missing' => 10, 'Draft' => 20, 'Stale' => 30, 'Due Soon' => 40, 'Exception' => 50];
    return ($priority[$left['effective_status']] ?? 100) <=> ($priority[$right['effective_status']] ?? 100)
        ?: strcmp((string) ($left['client_name'] ?? ''), (string) ($right['client_name'] ?? ''))
        ?: strcmp((string) ($left['documentation_requirement_version_name'] ?? ''), (string) ($right['documentation_requirement_version_name'] ?? ''));
});
$documentation_attention = count($documentation_attention_rows);
$documentation_attention_shown = array_slice($documentation_attention_rows, 0, 25);

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
if ($device_identity_conflicts) {
    $attention_items[] = ['danger', 'fa-random', "$device_identity_conflicts conflicting device identit" . ($device_identity_conflicts === 1 ? 'y' : 'ies'), '#entity-mappings'];
}
if ($identity_unresolved_devices) {
    $attention_items[] = ['warning', 'fa-unlink', "$identity_unresolved_devices unresolved device identit" . ($identity_unresolved_devices === 1 ? 'y' : 'ies'), '#entity-mappings'];
}
if ($level_failed_events) {
    $attention_items[] = ['warning', 'fa-inbox', "$level_failed_events queued or failed Level.io event" . ($level_failed_events === 1 ? '' : 's'), $session_is_admin ? '/admin/integration_level.php' : '#integration-health'];
}
if ($level_job_attention) {
    $attention_items[] = ['warning', 'fa-sync-alt', 'Level.io background job needs review', $session_is_admin ? '/admin/integration_level.php' : '#integration-health'];
}
if ($identity_job_attention) {
    $attention_items[] = ['danger', 'fa-project-diagram', 'Endpoint identity reconciliation job failed', $session_is_admin ? '/admin/cron.php' : '#integration-health'];
}
if ($dead_events) {
    $attention_items[] = ['danger', 'fa-exclamation-circle', "$dead_events dead-letter event" . ($dead_events === 1 ? '' : 's'), $session_is_admin ? '/admin/integration_automation.php' : '#recent-activity'];
} elseif ($failed_events) {
    $attention_items[] = ['warning', 'fa-redo', "$failed_events operational event" . ($failed_events === 1 ? '' : 's') . ' waiting to retry', $session_is_admin ? '/admin/integration_automation.php' : '#recent-activity'];
}
if ($documentation_attention) {
    $attention_items[] = ['warning', 'fa-book-medical', "$documentation_attention documentation obligation" . ($documentation_attention === 1 ? '' : 's') . ' need attention', '#documentation-attention'];
}

$sql_open_incidents = mysqli_query($mysqli, "SELECT automation_incidents.*,
    client_name, location_name, asset_name, service_name, ticket_prefix, ticket_number
    FROM automation_incidents
    LEFT JOIN clients ON automation_incident_client_id = client_id
    LEFT JOIN locations ON automation_incident_location_id = location_id
    LEFT JOIN assets ON automation_incident_asset_id = asset_id
    LEFT JOIN services ON automation_incident_service_id = service_id
    LEFT JOIN tickets ON automation_incident_ticket_id = ticket_id AND ticket_deleted_at IS NULL
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
    ORDER BY automation_event_last_received_at DESC LIMIT 20");

$sql_recent_mappings = mysqli_query($mysqli, "SELECT automation_entity_mappings.*,
    client_name, location_name, asset_name, domain_name
    FROM automation_entity_mappings
    LEFT JOIN clients ON automation_mapping_client_id = client_id
    LEFT JOIN locations ON automation_mapping_location_id = location_id
    LEFT JOIN assets ON automation_mapping_asset_id = asset_id
    LEFT JOIN domains ON automation_mapping_domain_id = domain_id
    WHERE automation_mapping_deleted_at IS NULL $mapping_scope $source_filter_mapping
    ORDER BY automation_mapping_last_seen_at DESC, automation_mapping_id DESC LIMIT 20");

$sql_identity_review = mysqli_query($mysqli, "SELECT automation_entity_mappings.*,
    client_name, asset_name
    FROM automation_entity_mappings
    LEFT JOIN clients ON automation_mapping_client_id = client_id
    LEFT JOIN assets ON automation_mapping_asset_id = asset_id
    WHERE automation_mapping_entity_type = 'device'
    AND automation_mapping_deleted_at IS NULL
    AND automation_mapping_state IN ('unresolved', 'suggested', 'conflicting', 'stale')
    $mapping_scope $bound_identity_scope $source_filter_mapping
    ORDER BY FIELD(automation_mapping_state, 'conflicting', 'unresolved', 'suggested', 'stale'),
        automation_mapping_last_seen_at DESC, automation_mapping_id DESC LIMIT 100");

$sql_mapping_decisions = mysqli_query($mysqli, "SELECT automation_mapping_decisions.*,
    automation_mapping_client_id, automation_mapping_external_name, client_name, user_name
    FROM automation_mapping_decisions
    INNER JOIN automation_entity_mappings
        ON automation_mapping_id = automation_mapping_decision_mapping_id
    LEFT JOIN clients ON automation_mapping_client_id = client_id
    LEFT JOIN users ON automation_mapping_decision_actor_user_id = user_id
    WHERE 1 = 1 $mapping_scope $bound_identity_scope $source_filter_mapping
    ORDER BY automation_mapping_decision_occurred_at DESC,
        automation_mapping_decision_id DESC LIMIT 20");

$coverage_rows = endpointIntegrationCoverageRows(
    $session_is_admin ? [] : ($client_access_array ?? []),
    $session_is_admin ? [] : ($client_deny_array ?? [])
);
$coverage_totals = [
    'active_devices' => 0,
    'level_devices' => 0,
    'intune_devices' => 0,
    'entra_devices' => 0,
    'sentinelone_devices' => 0,
    'missing_level_devices' => 0,
    'missing_intune_devices' => 0,
    'managed_windows_missing_sentinelone' => 0,
];
foreach ($coverage_rows as $coverage_row) {
    foreach ($coverage_totals as $coverage_key => $_) {
        $coverage_totals[$coverage_key] += intval($coverage_row[$coverage_key] ?? 0);
    }
}

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
            <small><?= intval($event_stats['duplicate_deliveries'] ?? 0) ?> known duplicate deliveries · <?= intval($event_stats['suppressed_24h'] ?? 0) ?> suppressed today</small>
        </a>
        <a href="#entity-mappings">
            <span>Known identities</span>
            <strong><?= intval($mapping_stats['mapping_count'] ?? 0) ?></strong>
            <small><?= $identity_unresolved_devices + $identity_conflicting_devices ?> need review</small>
        </a>
        <a href="#documentation-attention">
            <span>Documentation</span>
            <strong><?= $documentation_attention ?></strong>
            <small>freshness exceptions</small>
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
                    <?php if ($session_is_admin) { ?><a href="/admin/integration_automation.php">Manage events <i class="fas fa-cog ml-1"></i></a><?php } ?>
                </div>
                <div class="n45-health-list" tabindex="0" aria-label="Integration health details; scroll for additional sources">
                    <div class="n45-health-row">
                        <span class="n45-system-icon"><i class="fas fa-stream"></i></span>
                        <div>
                            <strong>Operational event pipeline</strong>
                            <span><?= intval($event_queue_stats['pending_events'] ?? 0) + intval($event_queue_stats['processing_events'] ?? 0) ?> queued · <?= $failed_events ?> retrying · <?= $active_maintenance_count ?> maintenance window<?= $active_maintenance_count === 1 ? '' : 's' ?></span>
                        </div>
                        <span class="badge badge-<?= $dead_events ? 'danger' : ($failed_events ? 'warning' : 'success') ?>"><?= $dead_events ? "$dead_events dead" : ($failed_events ? 'Retrying' : 'Healthy') ?></span>
                    </div>
                    <div class="n45-health-row">
                        <span class="n45-system-icon"><i class="fas fa-satellite"></i></span>
                        <div>
                            <strong>Level.io</strong>
                            <span><?= intval($level_stats['online_assets'] ?? 0) ?>/<?= intval($level_stats['managed_assets'] ?? 0) ?> devices online · <?= $level_open_alerts ?> active alerts<?= $level_job_last_run ? ' · synced ' . escapeHtml(timeAgo($level_job_last_run)) : '' ?></span>
                        </div>
                        <span class="badge badge-<?= !$config_level_enable ? 'secondary' : ($level_conflicts || $level_failed_events || $level_job_attention ? 'warning' : 'success') ?>"><?= !$config_level_enable ? 'Disabled' : ($level_conflicts || $level_failed_events || $level_job_attention ? 'Attention' : 'Healthy') ?></span>
                    </div>
                    <div class="n45-health-row">
                        <span class="n45-system-icon"><i class="fas fa-project-diagram"></i></span>
                        <div>
                            <strong>Endpoint identity reconciliation</strong>
                            <span><?= $identity_stale_devices ?> stale · <?= $identity_review_devices ?> awaiting review<?= !empty($identity_cron['cron_job_last_run_at']) ? ' · ran ' . escapeHtml(timeAgo($identity_cron['cron_job_last_run_at'])) : ' · awaiting first scheduled run' ?></span>
                        </div>
                        <span class="badge badge-<?= $identity_job_attention ? 'danger' : ($identity_health_attention ? 'warning' : 'success') ?>"><?= $identity_job_attention ? 'Failed' : ($identity_health_attention ? 'Attention' : 'Healthy') ?></span>
                    </div>
                    <?php foreach ($source_health as $source => $health) {
                        if (in_array($source, ['level', 'level_io'], true)) {
                            continue;
                        }
                        $open_count = intval($health['open_count'] ?? 0);
                        $last_seen = $health['last_received_at'] ?? $health['last_event_at'] ?? $health['last_mapping_at'] ?? '';
                        $mapping_count = intval($health['mapping_count'] ?? 0);
                        $review_mapping_count = intval($health['review_mapping_count'] ?? 0);
                        $conflict_mapping_count = intval($health['conflict_mapping_count'] ?? 0);
                        $stale_mapping_count = intval($health['stale_mapping_count'] ?? 0);
                        $events_24h = intval($health['events_24h'] ?? 0);
                        ?>
                        <div class="n45-health-row">
                            <span class="n45-system-icon"><i class="fas <?= $source_icon($source) ?>"></i></span>
                            <div>
                                <strong><?= escapeHtml($source_label($source)) ?></strong>
                                <span><?= $events_24h ?> events today · <?= $mapping_count ?> mappings<?= $review_mapping_count ? ' · ' . $review_mapping_count . ' review' : '' ?><?= $stale_mapping_count ? ' · ' . $stale_mapping_count . ' stale' : '' ?><?= $last_seen ? ' · seen ' . escapeHtml(timeAgo($last_seen)) : ' · ready for first signal' ?></span>
                            </div>
                            <span class="badge badge-<?= $conflict_mapping_count ? 'danger' : ($open_count || $review_mapping_count || $stale_mapping_count ? 'warning' : ($last_seen ? 'success' : 'info')) ?>"><?= $conflict_mapping_count ? "$conflict_mapping_count conflicts" : ($open_count ? "$open_count open" : ($review_mapping_count || $stale_mapping_count ? 'Attention' : ($last_seen ? 'Connected' : 'Ready'))) ?></span>
                        </div>
                    <?php } ?>
                </div>
            </section>
        </div>
    </div>

    <section class="n45-panel" id="documentation-attention" aria-labelledby="documentation-attention-heading">
        <div class="n45-panel-heading">
            <div>
                <h2 id="documentation-attention-heading">Documentation exceptions</h2>
                <p>Missing, stale, due-soon, and expiring-exception records that need an owner decision.</p>
            </div>
            <div class="text-right"><span class="n45-panel-count d-block mb-1"><?= count($documentation_attention_shown) ?> of <?= $documentation_attention ?> shown</span><a href="/agent/documentation.php?owner=all&status=attention">Open complete documentation queue <i class="fas fa-arrow-right ml-1"></i></a></div>
        </div>
        <?php if ($documentation_attention_shown) { ?>
            <div class="table-responsive">
                <table class="table table-hover n45-ops-table mb-0">
                    <thead><tr><th>Requirement</th><th>Client</th><th>Status</th><th>Review</th><th class="text-right">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($documentation_attention_shown as $documentation_row) {
                        $documentation_status = $documentation_row['effective_status'];
                        $documentation_client_id = intval($documentation_row['documentation_obligation_client_id']);
                        ?>
                        <tr>
                            <td><strong><?= escapeHtml($documentation_row['documentation_requirement_version_name']) ?></strong><div class="small text-muted"><code><?= escapeHtml($documentation_row['documentation_requirement_version_key']) ?></code><?php if (!empty($documentation_row['current_document_exists']) && !empty($documentation_row['document_name'])) { ?> · <?= escapeHtml($documentation_row['document_name']) ?><?php } elseif (!empty($documentation_row['documentation_obligation_projection_pending'])) { ?> · projection pending<?php } ?></div></td>
                            <td><a href="/agent/client_overview.php?client_id=<?= $documentation_client_id ?>"><?= escapeHtml($documentation_row['client_name']) ?></a></td>
                            <td><span class="badge badge-<?= documentationLifecycleStatusBadge($documentation_status) ?>"><?= escapeHtml($documentation_status) ?></span></td>
                            <td><?= $documentation_row['documentation_obligation_next_review_at'] ? escapeHtml(date('Y-m-d', strtotime($documentation_row['documentation_obligation_next_review_at']))) : '—' ?></td>
                            <td class="text-right"><a class="btn btn-sm btn-outline-primary" href="/agent/documentation.php?client_id=<?= $documentation_client_id ?>&owner=all&status=<?= urlencode($documentation_status) ?>">Review</a></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="n45-empty-state"><i class="fas fa-book-medical"></i><strong>No documentation exceptions</strong><span>Required client records are current and active exceptions are outside their warning window.</span></div>
        <?php } ?>
    </section>

    <section class="n45-panel" id="endpoint-coverage" aria-labelledby="coverage-heading">
        <div class="n45-panel-heading">
            <div>
                <h2 id="coverage-heading">Endpoint source coverage</h2>
                <p>Active endpoint-class assets by client. Missing values stay visible; stale source rows count as covered but are flagged above.</p>
            </div>
            <span class="n45-panel-count"><?= $coverage_totals['active_devices'] ?> devices</span>
        </div>
        <?php if ($coverage_rows) { ?>
            <div class="table-responsive">
                <table class="table table-hover n45-ops-table mb-0">
                    <thead><tr><th>Client</th><th>Active devices</th><th>Level.io</th><th>Intune</th><th>Entra</th><th>SentinelOne</th><th>Explicit gaps</th></tr></thead>
                    <tbody>
                    <?php foreach ($coverage_rows as $coverage) {
                        $active_devices = intval($coverage['active_devices']);
                        $coverage_percent = static function ($covered) use ($active_devices) {
                            return $active_devices > 0 ? round((intval($covered) / $active_devices) * 100) : 0;
                        };
                        ?>
                        <tr>
                            <td><a href="/agent/client_overview.php?client_id=<?= intval($coverage['client_id']) ?>"><?= escapeHtml($coverage['client_name']) ?></a></td>
                            <td><?= $active_devices ?></td>
                            <td><?= intval($coverage['level_devices']) ?>/<?= $active_devices ?> <small class="text-muted"><?= $coverage_percent($coverage['level_devices']) ?>%</small></td>
                            <td><?= intval($coverage['intune_devices']) ?>/<?= $active_devices ?> <small class="text-muted"><?= $coverage_percent($coverage['intune_devices']) ?>%</small></td>
                            <td><?= intval($coverage['entra_devices']) ?>/<?= $active_devices ?> <small class="text-muted"><?= $coverage_percent($coverage['entra_devices']) ?>%</small></td>
                            <td><?= intval($coverage['sentinelone_devices']) ?>/<?= $active_devices ?> <small class="text-muted"><?= $coverage_percent($coverage['sentinelone_devices']) ?>%</small></td>
                            <td>
                                <?php if (intval($coverage['missing_level_devices'])) { ?><span class="badge badge-warning mr-1"><?= intval($coverage['missing_level_devices']) ?> no Level</span><?php } ?>
                                <?php if (intval($coverage['missing_intune_devices'])) { ?><span class="badge badge-warning mr-1"><?= intval($coverage['missing_intune_devices']) ?> no Intune</span><?php } ?>
                                <?php if (intval($coverage['managed_windows_missing_sentinelone'])) { ?><span class="badge badge-danger"><?= intval($coverage['managed_windows_missing_sentinelone']) ?> managed Windows no S1</span><?php } ?>
                                <?php if (!intval($coverage['missing_level_devices']) && !intval($coverage['missing_intune_devices']) && !intval($coverage['managed_windows_missing_sentinelone'])) { ?><span class="badge badge-success">Complete</span><?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="n45-empty-state"><i class="fas fa-laptop"></i><strong>No active endpoint-class assets</strong><span>Coverage begins when an active workstation, server, mobile device, or virtual machine is recorded.</span></div>
        <?php } ?>
    </section>

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
                                <?php if (!empty($incident['service_name'])) { ?><div class="small text-muted"><i class="fas fa-cube mr-1"></i><?= escapeHtml($incident['service_name']) ?></div><?php } ?>
                            </td>
                            <td><span class="badge badge-<?= $severity_badge($incident['automation_incident_severity']) ?>"><?= escapeHtml(ucfirst($incident['automation_incident_severity'])) ?></span><div class="small text-muted mt-1"><?= intval($incident['automation_incident_event_count']) ?> event<?= intval($incident['automation_incident_event_count']) === 1 ? '' : 's' ?><?= intval($incident['automation_incident_repeat_count'] ?? 0) ? ' · ' . intval($incident['automation_incident_repeat_count']) . ' repeats' : '' ?><?= intval($incident['automation_incident_suppressed_count'] ?? 0) ? ' · ' . intval($incident['automation_incident_suppressed_count']) . ' suppressed' : '' ?></div></td>
                            <td><span title="<?= escapeHtml($incident['automation_incident_last_event_at']) ?>"><?= $incident['automation_incident_last_event_at'] ? escapeHtml(timeAgo($incident['automation_incident_last_event_at'])) : '—' ?></span></td>
                            <td><?php if ($incident_ticket_id) { ?><a class="btn btn-sm btn-outline-primary" href="/agent/ticket.php?ticket_id=<?= $incident_ticket_id ?>"><?= escapeHtml($incident['ticket_prefix']) . intval($incident['ticket_number']) ?></a><?php } else { ?><span class="text-muted">No ticket</span><?php } ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="n45-empty-state"><i class="fas fa-shield-alt"></i><strong>No open incidents</strong><span>New alerts from Level.io, SentinelOne, Checkmk, CIPP, backup, infrastructure, and other sources will appear here.</span></div>
        <?php } ?>
    </section>

    <section class="n45-panel" id="identity-review" aria-labelledby="identity-review-heading">
        <div class="n45-panel-heading">
            <div>
                <h2 id="identity-review-heading">Endpoint identity review queue</h2>
                <p>Ambiguous, conflicting, stale, or reappeared durable device identities require an explicit, audited decision.</p>
            </div>
            <span class="n45-panel-count"><?= mysqli_num_rows($sql_identity_review) ?> shown</span>
        </div>
        <?php if (mysqli_num_rows($sql_identity_review)) { ?>
            <form method="post" action="/agent/post.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="table-responsive">
                    <table class="table table-hover n45-ops-table mb-0">
                        <thead><tr><th aria-label="Select"></th><th>Identity</th><th>Client / asset</th><th>State</th><th>Last observed</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php while ($mapping = mysqli_fetch_assoc($sql_identity_review)) {
                            $mapping_id = intval($mapping['automation_mapping_id']);
                            $mapping_client_id = intval($mapping['automation_mapping_client_id']);
                            $mapping_asset_id = intval($mapping['automation_mapping_asset_id']);
                            $can_select_mapping = $can_review_identities && ($mapping_client_id > 0 || $session_is_admin);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="mapping_ids[]" value="<?= $mapping_id ?>" <?= $can_select_mapping ? '' : 'disabled' ?> aria-label="Select mapping <?= $mapping_id ?>"></td>
                                <td>
                                    <strong><?= escapeHtml($mapping['automation_mapping_external_name'] ?: $mapping['automation_mapping_external_id']) ?></strong>
                                    <div class="small text-muted"><?= escapeHtml($source_label($mapping['automation_mapping_source'])) ?> · <code><?= escapeHtml($mapping['automation_mapping_external_id']) ?></code></div>
                                </td>
                                <td>
                                    <?= $mapping_client_id ? escapeHtml($mapping['client_name']) : '<span class="text-warning">No client</span>' ?>
                                    <div class="small"><?= $mapping_asset_id ? '<a href="/agent/asset.php?client_id=' . $mapping_client_id . '&amp;asset_id=' . $mapping_asset_id . '">' . escapeHtml($mapping['asset_name'] ?: "Asset #$mapping_asset_id") . '</a>' : '<span class="text-muted">No asset</span>' ?></div>
                                </td>
                                <td><span class="badge badge-<?= $identity_state_badge($mapping['automation_mapping_state']) ?>"><?= escapeHtml(ucfirst($mapping['automation_mapping_state'])) ?></span></td>
                                <td title="<?= escapeHtml($mapping['automation_mapping_last_seen_at'] ?? '') ?>"><?= !empty($mapping['automation_mapping_last_seen_at']) ? escapeHtml(timeAgo($mapping['automation_mapping_last_seen_at'])) : 'Never' ?></td>
                                <td><?= !empty($mapping['automation_mapping_last_error']) ? escapeHtml($mapping['automation_mapping_last_error']) : '<span class="text-muted">No detail supplied</span>' ?><?= !$can_select_mapping && $mapping_client_id === 0 ? '<div class="small text-muted">Administrator review required</div>' : '' ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($can_review_identities) { ?>
                    <div class="border-top p-3">
                        <div class="form-row align-items-end">
                            <div class="form-group col-lg-2">
                                <label for="identity_action">Decision</label>
                                <select class="form-control" id="identity_action" name="identity_action" required>
                                    <option value="confirm">Confirm</option>
                                    <option value="ignore">Ignore</option>
                                    <option value="retire">Retire</option>
                                    <?php if (lookupUserPermission('module_support') >= 3) { ?><option value="remap">Remap one</option><?php } ?>
                                </select>
                            </div>
                            <div class="form-group col-lg-4">
                                <label for="identity_reason">Reason</label>
                                <input class="form-control" id="identity_reason" name="identity_reason" maxlength="1000" required placeholder="Why this decision is correct">
                            </div>
                            <div class="form-group col-lg-2">
                                <label for="target_client_id">Remap client ID</label>
                                <input class="form-control" id="target_client_id" name="target_client_id" type="number" min="1" placeholder="Remap only">
                            </div>
                            <div class="form-group col-lg-2">
                                <label for="target_asset_id">Remap asset ID</label>
                                <input class="form-control" id="target_asset_id" name="target_asset_id" type="number" min="1" placeholder="Remap only">
                            </div>
                            <div class="form-group col-lg-2">
                                <button class="btn btn-primary btn-block" type="submit" name="review_identity_mappings"><i class="fas fa-check mr-1"></i>Apply</button>
                            </div>
                        </div>
                        <small class="text-muted">Confirm, ignore, and retire support up to 100 selected rows. Remap requires Full Support permission, exactly one row, and an accessible target asset in the named client.</small>
                    </div>
                <?php } ?>
            </form>
        <?php } else { ?>
            <div class="n45-calm-state"><i class="fas fa-check-circle"></i><div><strong>No identity decisions waiting</strong><span>Deterministic mappings, source freshness, and tenant ownership are currently clear.</span></div></div>
        <?php } ?>
    </section>

    <section class="n45-panel" id="identity-decisions" aria-labelledby="identity-decisions-heading">
        <div class="n45-panel-heading"><div><h2 id="identity-decisions-heading">Recent mapping decisions</h2><p>Append-only automated and technician mapping-decision audit.</p></div></div>
        <?php if (mysqli_num_rows($sql_mapping_decisions)) { ?>
            <div class="table-responsive">
                <table class="table table-hover n45-ops-table mb-0">
                    <thead><tr><th>Time</th><th>Identity</th><th>Decision</th><th>Client</th><th>Actor</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php while ($decision = mysqli_fetch_assoc($sql_mapping_decisions)) { ?>
                        <tr>
                            <td title="<?= escapeHtml($decision['automation_mapping_decision_occurred_at']) ?>"><?= escapeHtml(timeAgo($decision['automation_mapping_decision_occurred_at'])) ?></td>
                            <td><?= escapeHtml($decision['automation_mapping_external_name'] ?: $decision['automation_mapping_decision_external_id']) ?><div class="small text-muted"><?= escapeHtml($source_label($decision['automation_mapping_decision_source'])) ?></div></td>
                            <td><span class="badge badge-light"><?= escapeHtml(ucwords(str_replace('_', ' ', $decision['automation_mapping_decision_action']))) ?></span></td>
                            <td><?= !empty($decision['client_name']) ? escapeHtml($decision['client_name']) : '—' ?></td>
                            <td><?= intval($decision['automation_mapping_decision_actor_user_id']) ? escapeHtml($decision['user_name'] ?: 'Deleted user') : 'Automation' ?></td>
                            <td><?= !empty($decision['automation_mapping_decision_reason']) ? escapeHtml($decision['automation_mapping_decision_reason']) : '—' ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <div class="n45-empty-state"><i class="fas fa-history"></i><strong>No mapping decisions recorded</strong><span>The ledger begins with the next automated discovery or technician review.</span></div>
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
                                <span class="n45-state-icon n45-state-<?= strtolower($event['automation_event_status'] ?? 'processed') === 'dead' ? 'danger' : (strtolower($event['automation_event_status'] ?? 'processed') === 'failed' ? 'warning' : (strtolower($event['automation_event_state']) === 'resolved' ? 'success' : (strtolower($event['automation_event_state']) === 'open' ? 'danger' : 'warning'))) ?>"><i class="fas <?= $source_icon($event['automation_event_source']) ?>"></i></span>
                                <div class="n45-activity-copy">
                                    <strong><?= escapeHtml($source_label($event['automation_event_source'])) ?> · <?= escapeHtml($event['automation_event_incident_key']) ?></strong>
                                    <span><?= $event['client_name'] ? escapeHtml($event['client_name']) . ' · ' : '' ?><?= escapeHtml(ucwords(str_replace('_', ' ', $event['automation_event_action']))) ?><?= intval($event['automation_event_delivery_count'] ?? 1) > 1 ? ' · ' . intval($event['automation_event_delivery_count']) . ' deliveries' : '' ?><?= !empty($event['automation_event_suppressed_reason']) ? ' · ' . escapeHtml(str_replace('_', ' ', $event['automation_event_suppressed_reason'])) : '' ?></span>
                                </div>
                                <div class="text-right"><span class="badge badge-<?= $action_badge($event['automation_event_action']) ?>"><?= escapeHtml(($event['automation_event_status'] ?? 'Processed') === 'Processed' ? ucfirst($event['automation_event_state']) : $event['automation_event_status']) ?></span><small title="<?= escapeHtml($event['automation_event_last_received_at'] ?? $event['automation_event_received_at']) ?>"><?= escapeHtml(timeAgo($event['automation_event_last_received_at'] ?? $event['automation_event_received_at'])) ?></small></div>
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
                                $object_url = '/agent/asset.php?client_id=' . intval($mapping['automation_mapping_client_id'])
                                    . '&asset_id=' . intval($mapping['automation_mapping_asset_id']);
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
                                    <span><?= escapeHtml($source_label($mapping['automation_mapping_source'])) ?> · <?= escapeHtml($mapping['automation_mapping_entity_type']) ?> · <?= escapeHtml(str_replace('_', ' ', $mapping['automation_mapping_strategy'])) ?><?= floatval($mapping['automation_mapping_confidence'] ?? 0) > 0 ? ' · ' . escapeHtml(rtrim(rtrim(number_format(floatval($mapping['automation_mapping_confidence']), 2), '0'), '.')) . '%' : '' ?></span>
                                </div>
                                <span class="badge badge-<?= $identity_state_badge($mapping['automation_mapping_state'] ?? 'unresolved') ?> mr-2"><?= escapeHtml(ucfirst($mapping['automation_mapping_state'] ?? 'unresolved')) ?></span>
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
