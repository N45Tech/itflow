#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

define('FROM_STARTER_CONTENT', true);

$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/sanitize.php';
require $app_root . '/functions/sla.php';
require $app_root . '/functions/ticket_operations.php';
require $app_root . '/admin/post/starter_content_model.php';

$dry_run = in_array('--dry-run', $argv, true);
$counts = [
    'statuses' => 0,
    'slas' => 0,
    'assignments' => 0,
    'tags' => 0,
    'archived_unused_tags' => 0,
    'restamped_tickets' => 0,
    'normalized_tickets' => 0,
];

function operationsFindId($mysqli, $table, $id_column, $where)
{
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT $id_column FROM $table WHERE $where LIMIT 1"));
    return intval($row[$id_column] ?? 0);
}

function operationsTagLinkDetails($tag_type)
{
    $details = [
        1 => ['client_tags', 'client_id', 'tag_id'],
        2 => ['location_tags', 'location_id', 'tag_id'],
        3 => ['contact_tags', 'contact_id', 'tag_id'],
        4 => ['credential_tags', 'credential_id', 'tag_id'],
        5 => ['asset_tags', 'asset_tag_asset_id', 'asset_tag_tag_id'],
    ];

    $tag_type = intval($tag_type);
    return $details[$tag_type] ?? null;
}

function operationsMergeTag($mysqli, $tag_type, $old_name, $new_name)
{
    $tag_type = intval($tag_type);
    $old_name_sql = escapeSql($old_name);
    $new_name_sql = escapeSql($new_name);
    $old_id = operationsFindId($mysqli, 'tags', 'tag_id', "tag_type = $tag_type AND tag_name = '$old_name_sql'");
    if (!$old_id) {
        return;
    }

    $new_id = operationsFindId($mysqli, 'tags', 'tag_id', "tag_type = $tag_type AND tag_name = '$new_name_sql'");
    if (!$new_id) {
        mysqli_query($mysqli, "UPDATE tags SET tag_name = '$new_name_sql', tag_updated_at = NOW(), tag_archived_at = NULL WHERE tag_id = $old_id");
        return;
    }

    $link = operationsTagLinkDetails($tag_type);
    if ($link) {
        [$table, $entity_column, $tag_column] = $link;
        mysqli_query($mysqli, "INSERT IGNORE INTO $table ($entity_column, $tag_column) SELECT $entity_column, $new_id FROM $table WHERE $tag_column = $old_id");
        mysqli_query($mysqli, "DELETE FROM $table WHERE $tag_column = $old_id");
    }
    mysqli_query($mysqli, "UPDATE tags SET tag_archived_at = COALESCE(tag_archived_at, NOW()) WHERE tag_id = $old_id");
}

try {
    mysqli_begin_transaction($mysqli);

    // IDs 1, 4 and 5 are application-level lifecycle constants. ID 3 remains
    // the built-in pausable status, but receives a precise operational name.
    $statuses = [
        ['id' => 1, 'name' => 'New', 'color' => '#dc3545', 'pause' => 0, 'order' => 10],
        ['id' => 2, 'name' => 'Open', 'color' => '#007bff', 'pause' => 0, 'order' => 20],
        ['name' => 'In Progress', 'color' => '#17a2b8', 'pause' => 0, 'order' => 30],
        ['name' => 'Scheduled', 'color' => '#fd7e14', 'pause' => 0, 'order' => 40],
        ['id' => 3, 'name' => 'Waiting on Client', 'color' => '#6c757d', 'pause' => 1, 'order' => 50],
        ['name' => 'Waiting on Vendor', 'color' => '#6f42c1', 'pause' => 1, 'order' => 60],
        ['id' => 4, 'name' => 'Resolved', 'color' => '#28a745', 'pause' => 0, 'order' => 70],
        ['id' => 5, 'name' => 'Closed', 'color' => '#343a40', 'pause' => 0, 'order' => 80],
    ];

    foreach ($statuses as $status) {
        $name = escapeSql($status['name']);
        $color = escapeSql($status['color']);
        $pause = intval($status['pause']);
        $order = intval($status['order']);
        $status_id = intval($status['id'] ?? 0);

        if ($status_id) {
            // Fold a same-named custom status into the protected core status.
            $duplicates = mysqli_query($mysqli, "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = '$name' AND ticket_status_id <> $status_id");
            while ($duplicate = mysqli_fetch_assoc($duplicates)) {
                $duplicate_id = intval($duplicate['ticket_status_id']);
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $status_id WHERE ticket_status = $duplicate_id");
                mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_name = CONCAT(ticket_status_name, ' (Legacy ', ticket_status_id, ')'), ticket_status_active = 0 WHERE ticket_status_id = $duplicate_id");
            }
            mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_name = '$name', ticket_status_color = '$color', ticket_status_active = 1, ticket_status_pauses_sla = $pause, ticket_status_order = $order WHERE ticket_status_id = $status_id");
        } else {
            $status_id = operationsFindId($mysqli, 'ticket_statuses', 'ticket_status_id', "ticket_status_name = '$name'");
            if ($status_id) {
                mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '$color', ticket_status_active = 1, ticket_status_pauses_sla = $pause, ticket_status_order = $order WHERE ticket_status_id = $status_id");
            } else {
                mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = '$name', ticket_status_color = '$color', ticket_status_active = 1, ticket_status_pauses_sla = $pause, ticket_status_order = $order");
            }
        }
        $counts['statuses']++;
    }

    $slas = [
        'Low' => ['Managed Care - Low', 'Routine request or planned work with no active disruption.', 480, 4320],
        'Medium' => ['Managed Care - Medium', 'Limited user impact or degraded service with a practical workaround.', 240, 1440],
        'High' => ['Managed Care - High', 'Critical service unavailable, broad impact or no practical workaround.', 60, 480],
        'Urgent' => ['Managed Care - Urgent', 'Business-wide outage, active security incident or immediate material data-loss threat.', 30, 240],
    ];

    foreach ($slas as $priority => $sla) {
        [$name, $description, $response_minutes, $resolution_minutes] = $sla;
        $name_sql = escapeSql($name);
        $description_sql = escapeSql($description);
        $sla_id = operationsFindId($mysqli, 'slas', 'sla_id', "sla_name = '$name_sql'");
        if ($sla_id) {
            mysqli_query($mysqli, "UPDATE slas SET sla_description = '$description_sql', sla_response_minutes = $response_minutes, sla_resolution_minutes = $resolution_minutes, sla_archived_at = NULL WHERE sla_id = $sla_id");
        } else {
            mysqli_query($mysqli, "INSERT INTO slas SET sla_name = '$name_sql', sla_description = '$description_sql', sla_response_minutes = $response_minutes, sla_resolution_minutes = $resolution_minutes");
            $sla_id = intval(mysqli_insert_id($mysqli));
        }

        $priority_sql = escapeSql($priority);
        mysqli_query($mysqli, "INSERT INTO sla_assignments (sla_assignment_client_id, sla_assignment_priority, sla_assignment_sla_id) VALUES (0, '$priority_sql', $sla_id) ON DUPLICATE KEY UPDATE sla_assignment_sla_id = VALUES(sla_assignment_sla_id)");
        $counts['slas']++;
        $counts['assignments']++;
    }

    mysqli_query($mysqli, "UPDATE settings SET config_business_days = '1,2,3,4,5', config_business_hours_start = '08:00:00', config_business_hours_end = '17:00:00', config_sla_warning_percent = 75 WHERE company_id = 1");

    // Preserve tag IDs and assignments while replacing ambiguous legacy names.
    $tag_aliases = [
        1 => [
            'Managed' => 'Managed Care',
            'Co-Managed' => 'Co-Managed IT',
            'Break Fix' => 'Hourly Support',
            'Multi Site' => 'Multi-Site',
            'After Hours Support' => 'After-Hours Authorized',
            'Compliance' => 'Compliance Scope',
        ],
        2 => [
            'Head Office' => 'Headquarters',
        ],
        3 => [
            'Primary' => 'Primary Contact',
            'Technical' => 'Technical Contact',
            'Billing' => 'Billing Contact',
            'Executive' => 'Executive Sponsor',
            'Emergency' => 'Emergency Contact',
            'After Hours' => 'After-Hours Contact',
            'Onsite Point of Contact' => 'Onsite Contact',
            'Departed' => 'Former Contact',
        ],
        4 => [
            'Domain Admin' => 'Privileged Admin',
            'Local Admin' => 'Privileged Admin',
            'Switch' => 'Network',
            'Wireless' => 'Network',
            'Registrar and DNS' => 'Registrar / DNS',
            'Rotate Quarterly' => 'Rotation Required',
        ],
        5 => [
            'Patch Excluded' => 'Patch Exception',
        ],
    ];
    foreach ($tag_aliases as $tag_type => $aliases) {
        foreach ($aliases as $old_name => $new_name) {
            operationsMergeTag($mysqli, $tag_type, $old_name, $new_name);
        }
    }

    $target_tag_ids = [];
    foreach (starterContentTags() as $tag) {
        [$tag_type, $tag_name, $tag_color, $tag_icon] = $tag;
        $tag_type = intval($tag_type);
        $tag_name_sql = escapeSql($tag_name);
        $tag_color_sql = escapeSql($tag_color);
        $tag_icon_sql = escapeSql($tag_icon);
        $tag_id = operationsFindId($mysqli, 'tags', 'tag_id', "tag_type = $tag_type AND tag_name = '$tag_name_sql'");
        if ($tag_id) {
            mysqli_query($mysqli, "UPDATE tags SET tag_color = '$tag_color_sql', tag_icon = '$tag_icon_sql', tag_updated_at = NOW(), tag_archived_at = NULL WHERE tag_id = $tag_id");
        } else {
            mysqli_query($mysqli, "INSERT INTO tags SET tag_type = $tag_type, tag_name = '$tag_name_sql', tag_color = '$tag_color_sql', tag_icon = '$tag_icon_sql'");
            $tag_id = intval(mysqli_insert_id($mysqli));
        }
        $target_tag_ids[$tag_id] = true;
        $counts['tags']++;
    }

    // Archive only unused extras. A locally-created tag with live assignments is
    // preserved even when it is outside the recommended taxonomy.
    $extra_tags = mysqli_query($mysqli, "SELECT tag_id, tag_type FROM tags WHERE tag_archived_at IS NULL");
    while ($extra_tag = mysqli_fetch_assoc($extra_tags)) {
        $tag_id = intval($extra_tag['tag_id']);
        if (isset($target_tag_ids[$tag_id])) {
            continue;
        }
        $link = operationsTagLinkDetails($extra_tag['tag_type']);
        if (!$link) {
            continue;
        }
        [$table, $entity_column, $tag_column] = $link;
        $linked = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS linked_count FROM $table WHERE $tag_column = $tag_id"));
        if (intval($linked['linked_count']) === 0) {
            mysqli_query($mysqli, "UPDATE tags SET tag_archived_at = NOW() WHERE tag_id = $tag_id");
            $counts['archived_unused_tags']++;
        }
    }

    // Re-evaluate current open tickets against the new defaults. This is a no-op
    // on a clean installation and preserves completed/archived history.
    $open_tickets = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_closed_at IS NULL AND ticket_archived_at IS NULL");
    while ($ticket = mysqli_fetch_assoc($open_tickets)) {
        $ticket_id = intval($ticket['ticket_id']);
        ticketOperationalNormalizeLegacyTicket($ticket_id);
        applyTicketSla($ticket_id, null, null, true);
        $counts['normalized_tickets']++;
        $counts['restamped_tickets']++;
    }

    if ($dry_run) {
        mysqli_rollback($mysqli);
    } else {
        mysqli_commit($mysqli);
    }

    $verb = $dry_run ? 'Would configure' : 'Configured';
    echo "$verb {$counts['statuses']} statuses, {$counts['slas']} SLA targets, {$counts['assignments']} default assignments and {$counts['tags']} active tags.\n";
    echo ($dry_run ? 'Would archive' : 'Archived') . " {$counts['archived_unused_tags']} unused legacy tags and " . ($dry_run ? 'would re-stamp' : 're-stamped') . " {$counts['restamped_tickets']} open tickets.\n";
    echo ($dry_run ? 'Would normalize' : 'Normalized') . " {$counts['normalized_tickets']} open ticket operational records.\n";
} catch (Throwable $exception) {
    mysqli_rollback($mysqli);
    fwrite(STDERR, "Ticket operations reconciliation failed: " . $exception->getMessage() . "\n");
    exit(1);
}
