#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
if (count($arguments) !== 1 || !in_array($arguments[0], ['--dry-run', '--apply'], true)) {
    $script_name = basename((string) ($argv[0] ?? 'reconcile_endpoint_records.php'));
    fwrite(STDERR, "Usage: php $script_name (--dry-run|--apply)\n");
    exit(2);
}
$dry_run = $arguments[0] === '--dry-run';
$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/integration_identity.php';
require $app_root . '/functions/endpoint.php';

function reconcileEndpointStatusForIdentity(string $identity_state): string
{
    return match ($identity_state) {
        'automatic', 'confirmed' => 'active',
        'retired' => 'retired',
        'ignored' => 'ignored',
        'conflicting' => 'conflicting',
        'stale' => 'stale',
        'unresolved', 'suggested' => 'unmanaged',
        default => 'unmanaged',
    };
}

function reconcileEndpointLevelInterfaces(
    mysqli $mysqli,
    int $asset_id,
    string $device_id,
    array $snapshot,
    bool $retired
): array {
    if ($retired) {
        return [];
    }

    $snapshot_interfaces = [];
    foreach (($snapshot['network_interfaces'] ?? []) as $interface) {
        if (is_array($interface) && !empty($interface['key'])) {
            $snapshot_interfaces[(string) $interface['key']] = $interface;
        }
    }

    $device_id_sql = mysqli_real_escape_string($mysqli, $device_id);
    $observations = [];
    $interfaces = mysqli_query($mysqli, "SELECT lil.level_interface_key,
        ai.interface_id, ai.interface_name, ai.interface_type, ai.interface_mac,
        ai.interface_ip, ai.interface_ipv6, ai.interface_network_id
        FROM level_interface_links lil
        INNER JOIN asset_interfaces ai ON ai.interface_id = lil.level_asset_interface_id
        WHERE lil.level_device_id = '$device_id_sql'
        AND ai.interface_asset_id = $asset_id
        AND lil.level_interface_deleted_at IS NULL
        AND ai.interface_archived_at IS NULL
        FOR UPDATE");
    while ($interface = mysqli_fetch_assoc($interfaces)) {
        $key = (string) $interface['level_interface_key'];
        $observation = $snapshot_interfaces[$key] ?? [];
        $observation['key'] = $key;
        $observation['interface_id'] = intval($interface['interface_id']);
        $observation['name'] = (string) $interface['interface_name'];
        $observation['type'] = (string) $interface['interface_type'];
        $observation['mac'] = (string) $interface['interface_mac'];
        $observation['ipv4'] = (string) $interface['interface_ip'];
        $observation['ipv6'] = (string) $interface['interface_ipv6'];
        $observation['network_id'] = intval($interface['interface_network_id']);
        $observations[] = $observation;
    }
    return $observations;
}

$counts = [
    'level_records' => 0,
    'external_records' => 0,
    'network_interfaces' => 0,
    'state_changes' => 0,
    'skipped_without_snapshot' => 0,
];
$lock_name = 'n45-itflow-reconcile-endpoint-records';
$lock_acquired = false;
$transaction_open = false;
$reconcile_error = null;

try {
    $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another endpoint record reconciliation is already running');
    }
    $lock_acquired = true;

    mysqli_begin_transaction($mysqli);
    $transaction_open = true;

    // Enumerate identifiers only. Each candidate is previewed, its asset is
    // locked in the same order as live ingestion, and the link is then re-read
    // FOR UPDATE so no stale snapshot can overwrite a concurrent writer.
    $level_link_ids = [];
    $level_links = mysqli_query($mysqli, "SELECT level_asset_link_id FROM level_asset_links
        ORDER BY level_asset_link_id");
    while ($candidate = mysqli_fetch_assoc($level_links)) {
        $level_link_ids[] = intval($candidate['level_asset_link_id']);
    }
    foreach ($level_link_ids as $level_link_id) {
        $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id
            FROM level_asset_links WHERE level_asset_link_id = $level_link_id LIMIT 1"));
        $preview_asset_id = intval($preview['level_asset_id'] ?? 0);
        if ($preview_asset_id < 1) {
            continue;
        }
        $preview_asset = endpointAssetTenantRow($preview_asset_id, 0, true);
        $preview_client_id = intval($preview_asset['asset_client_id']);
        $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_links.*, asset_client_id
            FROM level_asset_links
            INNER JOIN assets ON asset_id = level_asset_id
            WHERE level_asset_link_id = $level_link_id
            AND level_asset_id = $preview_asset_id
            AND asset_client_id = $preview_client_id
            AND asset_archived_at IS NULL
            LIMIT 1 FOR UPDATE"));
        if (!$link) {
            continue;
        }
        $asset_id = intval($link['level_asset_id']);
        $client_id = intval($link['asset_client_id']);
        $device_id = (string) $link['level_device_id'];
        $snapshot = json_decode((string) $link['level_device_snapshot'], true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $retired = !empty($link['level_device_deleted_at']);
        endpointAssetTenantRow($asset_id, $client_id, true);
        $mapping = integrationIdentityUpsertMapping([
            'source' => 'level',
            'entity_type' => 'device',
            'external_id' => $device_id,
            'external_parent_id' => $link['level_group_id'] ?? '',
            'external_name' => $link['level_device_hostname'] ?? '',
            'client_id' => $client_id,
            'asset_id' => $asset_id,
            'state' => $retired
                ? 'retired'
                : ((string) $link['level_device_sync_status'] === 'Conflict' ? 'conflicting' : 'automatic'),
            'strategy' => 'existing_level_link',
            'confidence' => $retired ? 0 : 100,
            'last_seen_at' => $link['level_device_last_seen_at']
                ?: $link['level_device_last_synced_at'],
            'last_error' => $link['level_device_sync_message'] ?? '',
            'metadata' => [
                'group_id' => $link['level_group_id'] ?? '',
                'online' => !empty($link['level_device_online']),
            ],
        ]);
        if (intval($mapping['automation_mapping_asset_id'] ?? 0) !== $asset_id
            || intval($mapping['automation_mapping_client_id'] ?? 0) !== $client_id) {
            throw new RuntimeException(
                'Level reconciliation stopped: identity mapping diverges from the locked asset link'
            );
        }
        $identity_state = (string) $mapping['automation_mapping_state'];
        $status = reconcileEndpointStatusForIdentity($identity_state);
        if ($status === 'ignored') {
            continue;
        }
        if (!in_array($status, ['active', 'stale'], true)) {
            $retired = endpointRetireIdentityBindingUnlocked([
                'asset_id' => $asset_id,
                'client_id' => $client_id,
                'source' => 'level',
                'external_id' => $device_id,
                'occurred_at' => $link['level_device_last_synced_at'] ?: date('Y-m-d H:i:s'),
                'reason' => 'Level identity is ' . $identity_state,
            ]);
            if (!$retired) {
                throw new RuntimeException(
                    'Level reconciliation stopped: endpoint source binding diverges from the locked asset link'
                );
            }
            $counts['level_records']++;
            $counts['state_changes'] += $retired ? 1 : 0;
            continue;
        }
        $facts = $snapshot;
        $facts['health_state'] = $retired
            ? 'unmanaged'
            : (!empty($link['level_device_online']) ? 'healthy' : 'offline');
        $facts['lifecycle_state'] = $retired ? 'retired' : 'active';
        $facts['last_seen_at'] = $link['level_device_last_seen_at'] ?? null;
        $observed_at = $link['level_device_last_synced_at'] ?: date('Y-m-d H:i:s');

        $observations = reconcileEndpointLevelInterfaces(
            $mysqli,
            $asset_id,
            $device_id,
            $snapshot,
            $retired
        );
        $delivery = endpointReconcileAssetSourceUnlocked([
            'asset_id' => $asset_id,
            'client_id' => $client_id,
            'source' => 'level',
            'external_id' => $device_id,
            'status' => $status,
            'observed_at' => $observed_at,
            'facts' => $facts,
            'network_interfaces' => $observations,
        ]);
        $state = $delivery['state'];
        $counts['level_records']++;
        $counts['state_changes'] += !empty($state['changed']) ? 1 : 0;
        $counts['network_interfaces'] += count($observations);
    }

    $external_mapping_ids = [];
    $external_mappings = mysqli_query($mysqli, "SELECT automation_mapping_id
        FROM automation_entity_mappings
        WHERE automation_mapping_entity_type = 'device'
        AND automation_mapping_source IN ('entra', 'intune', 'sentinelone')
        AND automation_mapping_asset_id > 0
        AND automation_mapping_client_id > 0
        ORDER BY automation_mapping_id");
    while ($candidate = mysqli_fetch_assoc($external_mappings)) {
        $external_mapping_ids[] = intval($candidate['automation_mapping_id']);
    }
    foreach ($external_mapping_ids as $mapping_id) {
        $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_asset_id,
            automation_mapping_client_id FROM automation_entity_mappings
            WHERE automation_mapping_id = $mapping_id LIMIT 1"));
        $preview_asset_id = intval($preview['automation_mapping_asset_id'] ?? 0);
        $preview_client_id = intval($preview['automation_mapping_client_id'] ?? 0);
        if ($preview_asset_id < 1 || $preview_client_id < 1) {
            continue;
        }
        endpointAssetTenantRow($preview_asset_id, $preview_client_id, true);
        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mappings.*
            FROM automation_entity_mappings mappings
            INNER JOIN assets ON asset_id = automation_mapping_asset_id
                AND asset_client_id = automation_mapping_client_id
            WHERE automation_mapping_id = $mapping_id
            AND automation_mapping_asset_id = $preview_asset_id
            AND automation_mapping_client_id = $preview_client_id
            AND automation_mapping_entity_type = 'device'
            AND automation_mapping_source IN ('entra', 'intune', 'sentinelone')
            AND asset_archived_at IS NULL
            LIMIT 1 FOR UPDATE"));
        if (!$mapping) {
            continue;
        }
        $status = reconcileEndpointStatusForIdentity((string) $mapping['automation_mapping_state']);
        if ($status === 'ignored') {
            continue;
        }
        if (!in_array($status, ['active', 'stale'], true)) {
            $retired = endpointRetireIdentityBindingUnlocked([
                'asset_id' => intval($mapping['automation_mapping_asset_id']),
                'client_id' => intval($mapping['automation_mapping_client_id']),
                'source' => $mapping['automation_mapping_source'],
                'external_id' => $mapping['automation_mapping_external_id'],
                'occurred_at' => $mapping['automation_mapping_last_synced_at'] ?: date('Y-m-d H:i:s'),
                'reason' => ucfirst((string) $mapping['automation_mapping_source'])
                    . ' identity is ' . (string) $mapping['automation_mapping_state'],
            ]);
            if (!$retired) {
                throw new RuntimeException('Endpoint reconciliation stopped on a divergent source binding');
            }
            $counts['external_records']++;
            $counts['state_changes'] += $retired ? 1 : 0;
            continue;
        }

        $snapshot_source_sql = mysqli_real_escape_string(
            $mysqli,
            (string) $mapping['automation_mapping_source']
        );
        $snapshot_external_id_sql = mysqli_real_escape_string(
            $mysqli,
            (string) $mapping['automation_mapping_external_id']
        );
        $snapshot = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_snapshot_payload,
            automation_snapshot_observed_at FROM automation_entity_snapshots
            WHERE automation_snapshot_source = '$snapshot_source_sql'
            AND automation_snapshot_entity_type = 'device'
            AND automation_snapshot_external_id = '$snapshot_external_id_sql'
            ORDER BY automation_snapshot_observed_at DESC, automation_snapshot_id DESC
            LIMIT 1 FOR UPDATE"));
        $observed_at = $snapshot['automation_snapshot_observed_at']
            ?? $mapping['automation_mapping_last_synced_at']
            ?? date('Y-m-d H:i:s');
        $facts = json_decode((string) ($snapshot['automation_snapshot_payload'] ?? ''), true);
        if (!is_array($facts)) {
            $counts['skipped_without_snapshot']++;
            continue;
        }
        $delivery = endpointReconcileAssetSourceUnlocked([
            'asset_id' => intval($mapping['automation_mapping_asset_id']),
            'client_id' => intval($mapping['automation_mapping_client_id']),
            'source' => $mapping['automation_mapping_source'],
            'external_id' => $mapping['automation_mapping_external_id'],
            'status' => $status,
            'observed_at' => $observed_at,
            'facts' => $facts,
            'network_interfaces' => in_array($status, ['active', 'stale'], true)
                && is_array($facts['network_interfaces'] ?? null)
                ? $facts['network_interfaces']
                : [],
        ]);
        $state = $delivery['state'];
        $counts['external_records']++;
        $counts['state_changes'] += !empty($state['changed']) ? 1 : 0;
    }

    if ($dry_run) {
        mysqli_rollback($mysqli);
    } else {
        mysqli_commit($mysqli);
    }
    $transaction_open = false;

    $verb = $dry_run ? 'Would reconcile' : 'Reconciled';
    echo "$verb {$counts['level_records']} Level records, {$counts['external_records']} external records and {$counts['network_interfaces']} Level interfaces.\n";
    echo ($dry_run ? 'Would record' : 'Recorded') . " {$counts['state_changes']} posture changes; skipped {$counts['skipped_without_snapshot']} mappings without a snapshot.\n";
} catch (Throwable $exception) {
    if ($transaction_open) {
        mysqli_rollback($mysqli);
    }
    $reconcile_error = $exception->getMessage();
} finally {
    if ($lock_acquired) {
        try {
            $released = mysqli_fetch_row(mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')"));
            if (intval($released[0] ?? 0) !== 1) {
                throw new RuntimeException('Endpoint reconciliation lock release could not be confirmed');
            }
        } catch (Throwable $release_error) {
            $reconcile_error = $reconcile_error
                ? $reconcile_error . '; ' . $release_error->getMessage()
                : $release_error->getMessage();
        }
    }
}

if ($reconcile_error !== null) {
    fwrite(STDERR, 'Endpoint record reconciliation failed: ' . $reconcile_error . "\n");
    exit(1);
}
