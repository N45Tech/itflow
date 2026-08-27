<?php

// Level.io RMM API, synchronization, webhook, and alert-ticket helpers.

function levelWebhookEventDefinitions(): array
{
    return [
        'alert_active' => ['resource' => 'alert', 'action' => 'active'],
        'alert_resolved' => ['resource' => 'alert', 'action' => 'resolved'],
        'device_created' => ['resource' => 'device', 'action' => 'created'],
        'device_updated' => ['resource' => 'device', 'action' => 'updated'],
        'device_deleted' => ['resource' => 'device', 'action' => 'deleted'],
        'group_created' => ['resource' => 'group', 'action' => 'created'],
        'group_updated' => ['resource' => 'group', 'action' => 'updated'],
        'group_deleted' => ['resource' => 'group', 'action' => 'deleted'],
    ];
}

function levelAllowedWebhookEvents(): array
{
    return array_keys(levelWebhookEventDefinitions());
}

function levelWebhookEventDefinition(string $event_type): ?array
{
    $definitions = levelWebhookEventDefinitions();

    return $definitions[$event_type] ?? null;
}

/*
 * Validate the envelope and the minimum identity fields required to route the
 * event. Create/update payloads are deliberately not trusted as a complete
 * resource snapshot: the processor re-fetches devices and groups by this id.
 */
function levelWebhookValidationError(array $event): ?string
{
    if (!is_string($event['event_type'] ?? null)) {
        return 'Unsupported Level event type';
    }
    $event_type = $event['event_type'];
    $definition = levelWebhookEventDefinition($event_type);
    if ($definition === null) {
        return 'Unsupported Level event type';
    }

    if (!is_string($event['event_id'] ?? null)) {
        return 'Invalid Level event id';
    }
    $event_id = $event['event_id'];
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $event_id)) {
        return 'Invalid Level event id';
    }

    if (levelDateTimeValue($event['occurred_at'] ?? null) === null) {
        return 'Invalid Level event timestamp';
    }

    $data = $event['data'] ?? null;
    if (!is_array($data)) {
        return 'Invalid Level event data';
    }

    if (!is_string($data['id'] ?? null) || levelLimitText($data['id'], 255) === '') {
        return 'Level event is missing its resource id';
    }

    if ($definition['resource'] === 'alert'
        && (!is_string($data['device_id'] ?? null) || levelLimitText($data['device_id'], 255) === '')) {
        return 'Level alert event is missing its device id';
    }

    return null;
}

function levelWebhookSignatureIsValid(string $raw_body, string $signature, string $secret): bool
{
    $signature = trim($signature);
    if ($secret === '' || !preg_match('/^sha256=[0-9a-f]{64}$/i', $signature)) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);

    return hash_equals(strtolower($expected), strtolower($signature));
}

function levelDbEscape($value): string
{
    global $mysqli;

    return mysqli_real_escape_string($mysqli, (string) $value);
}

function levelLimitText($value, int $length): string
{
    return mb_substr(trim((string) $value), 0, $length);
}

function levelDateTimeValue($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $date->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function levelNullableSql($value): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    return "'" . levelDbEscape($value) . "'";
}

function levelGetSettings(bool $refresh = false): array
{
    global $mysqli;
    static $settings = null;

    if ($settings !== null && !$refresh) {
        return $settings;
    }

    $sql = mysqli_query($mysqli, "SELECT config_level_alert_assigned_to,
        config_level_alert_ticket_enable, config_level_api_key, config_level_enable,
        config_level_webhook_secret, config_module_enable_ticketing,
        config_ticket_default_billable, config_ticket_prefix
        FROM settings WHERE company_id = 1 LIMIT 1");

    $row = $sql ? mysqli_fetch_assoc($sql) : [];
    $settings = [
        'enabled' => intval($row['config_level_enable'] ?? 0),
        'api_key' => (string) ($row['config_level_api_key'] ?? ''),
        'webhook_secret' => (string) ($row['config_level_webhook_secret'] ?? ''),
        'alert_ticket_enabled' => intval($row['config_level_alert_ticket_enable'] ?? 0),
        'alert_assigned_to' => intval($row['config_level_alert_assigned_to'] ?? 0),
        'ticketing_enabled' => intval($row['config_module_enable_ticketing'] ?? 0),
        'ticket_default_billable' => intval($row['config_ticket_default_billable'] ?? 0),
        'ticket_prefix' => (string) ($row['config_ticket_prefix'] ?? 'TCK-'),
    ];

    return $settings;
}

/*
 * Make a JSON request to Level's fixed API origin. API keys are sent only to
 * api.level.io and response bodies are never copied into error messages.
 */
function levelRequest(string $method, string $path, array $query = [], ?array $body = null): array
{
    $settings = levelGetSettings();
    $api_key = trim($settings['api_key']);

    if ($api_key === '' || preg_match('/[\r\n]/', $api_key)) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Level API key is not configured'];
    }

    if (!str_starts_with($path, '/v2/')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Invalid Level API path'];
    }

    $url = 'https://api.level.io' . $path;
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = [
        'Accept: application/json',
        'Authorization: ' . $api_key,
        'User-Agent: ITFlow-Level-Integration/1.0',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($body !== null) {
        $encoded_body = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($encoded_body === false) {
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Could not encode Level request'];
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_body);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_error !== '') {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'Level API request failed'];
    }

    $data = $response === '' ? [] : json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'Level returned an invalid JSON response'];
    }

    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => "Level returned HTTP $status"];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
}

function levelListAll(string $path, array $query = [], int $maximum_pages = 1000): array
{
    $items = [];
    $query['limit'] = 100;

    for ($page = 0; $page < $maximum_pages; $page++) {
        $response = levelRequest('GET', $path, $query);
        if (!$response['ok']) {
            throw new RuntimeException($response['error']);
        }

        $page_items = $response['data']['data'] ?? null;
        if (!is_array($page_items)) {
            throw new RuntimeException('Level list response did not contain a data array');
        }

        foreach ($page_items as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        if (empty($response['data']['has_more'])) {
            return $items;
        }

        $last = end($page_items);
        $cursor = is_array($last) ? (string) ($last['id'] ?? '') : '';
        if ($cursor === '') {
            throw new RuntimeException('Level pagination did not return a cursor');
        }
        $query['starting_after'] = $cursor;
    }

    throw new RuntimeException('Level pagination exceeded its safety limit');
}

function levelTestConnection(): array
{
    $response = levelRequest('GET', '/v2/groups', ['limit' => 1]);

    if (!$response['ok']) {
        return ['ok' => false, 'message' => $response['error']];
    }

    return ['ok' => true, 'message' => 'Connected to Level.io successfully.'];
}

function levelStoreGroup(array $group): bool
{
    global $mysqli;

    $id = levelLimitText($group['id'] ?? '', 255);
    $name = levelLimitText($group['name'] ?? '', 255);
    if ($id === '' || $name === '') {
        return false;
    }

    $id_sql = levelDbEscape($id);
    $name_sql = levelDbEscape($name);
    $parent_id = levelLimitText($group['parent_id'] ?? '', 255);
    $parent_sql = levelNullableSql($parent_id === '' ? null : $parent_id);
    $device_count = max(0, intval($group['device_count'] ?? 0));
    $descendent_count = max(0, intval($group['descendent_device_count'] ?? ($group['descendant_device_count'] ?? 0)));

    return (bool) mysqli_query($mysqli, "INSERT INTO level_group_mappings SET
        level_group_id = '$id_sql',
        level_group_name = '$name_sql',
        level_parent_group_id = $parent_sql,
        level_group_device_count = $device_count,
        level_group_descendent_device_count = $descendent_count,
        level_group_last_seen_at = NOW(),
        level_group_deleted_at = NULL
        ON DUPLICATE KEY UPDATE
        level_group_name = VALUES(level_group_name),
        level_parent_group_id = VALUES(level_parent_group_id),
        level_group_device_count = VALUES(level_group_device_count),
        level_group_descendent_device_count = VALUES(level_group_descendent_device_count),
        level_group_last_seen_at = NOW(),
        level_group_deleted_at = NULL");
}

function levelMarkGroupDeleted($group_id): void
{
    global $mysqli;

    $group_id = levelDbEscape(levelLimitText($group_id, 255));
    if ($group_id !== '') {
        mysqli_query($mysqli, "UPDATE level_group_mappings SET level_group_deleted_at = NOW() WHERE level_group_id = '$group_id'");
    }
}

function levelFetchAndStoreGroup(string $group_id): array
{
    $group_id = levelLimitText($group_id, 255);
    if ($group_id === '') {
        throw new InvalidArgumentException('Level group id is required');
    }

    $response = levelRequest('GET', '/v2/groups/' . rawurlencode($group_id));
    if (!$response['ok']) {
        // Webhooks can be delayed or retried out of order. A 404 is the current
        // authoritative state even if this delivery was originally "created" or
        // "updated", so do not resurrect a group from the stale webhook body.
        if ($response['status'] === 404) {
            levelMarkGroupDeleted($group_id);
            levelQueueCronJob('level_sync');
            return ['result' => 'deleted', 'group_id' => $group_id];
        }

        throw new RuntimeException($response['error']);
    }

    $group = $response['data'];
    if (!is_array($group) || levelLimitText($group['id'] ?? '', 255) !== $group_id) {
        throw new RuntimeException('Level returned a different group than the webhook resource');
    }

    if (!levelStoreGroup($group)) {
        throw new RuntimeException('Could not update the Level group mapping');
    }

    // A parent or hierarchy change can alter inherited client routing for every
    // device below this group. One coalesced reconciliation updates those assets.
    levelQueueCronJob('level_sync');

    return ['result' => 'updated', 'group_id' => $group_id];
}

function levelDiscoverGroups(): int
{
    global $mysqli;

    $started_at = date('Y-m-d H:i:s');
    $groups = levelListAll('/v2/groups');

    $count = 0;
    foreach ($groups as $group) {
        if (levelStoreGroup($group)) {
            $count++;
        }
    }

    $started_at_sql = levelDbEscape($started_at);
    mysqli_query($mysqli, "UPDATE level_group_mappings SET level_group_deleted_at = NOW()
        WHERE level_group_deleted_at IS NULL
        AND (level_group_last_seen_at IS NULL OR level_group_last_seen_at < '$started_at_sql')");

    return $count;
}

/*
 * Resolve a device's ITFlow client by walking its Level group ancestry. The
 * nearest mapped group wins, so a client mapping on a parent naturally applies
 * to all child groups unless a child overrides it.
 */
function levelResolveClientForGroup($group_id): int
{
    global $mysqli;

    $current = levelLimitText($group_id, 255);
    $visited = [];

    for ($depth = 0; $depth < 100 && $current !== ''; $depth++) {
        if (isset($visited[$current])) {
            break;
        }
        $visited[$current] = true;

        $current_sql = levelDbEscape($current);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_group_client_id, level_parent_group_id,
            EXISTS(
                SELECT 1 FROM clients
                WHERE client_id = level_group_client_id AND client_archived_at IS NULL
            ) AS mapped_client_active
            FROM level_group_mappings WHERE level_group_id = '$current_sql' LIMIT 1"));

        if (!$row) {
            break;
        }

        $client_id = intval($row['level_group_client_id']);
        if ($client_id > 0) {
            // An explicit mapping to an archived/deleted client is a stopped
            // route, not permission to fall through to a different ancestor.
            return !empty($row['mapped_client_active']) ? $client_id : 0;
        }

        $current = (string) ($row['level_parent_group_id'] ?? '');
    }

    return 0;
}

function levelAcquireDeviceLock(string $device_id): bool
{
    global $mysqli;

    $name = levelDbEscape('itflow_level_device_' . sha1($device_id));
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$name', 10)"));

    return intval($row[0] ?? 0) === 1;
}

function levelReleaseDeviceLock(string $device_id): void
{
    global $mysqli;

    $name = levelDbEscape('itflow_level_device_' . sha1($device_id));
    mysqli_query($mysqli, "SELECT RELEASE_LOCK('$name')");
}

function levelDeviceAssetType(array $device): string
{
    return in_array(($device['role'] ?? ''), ['server', 'domain_controller'], true) ? 'Server' : 'Desktop';
}

function levelDeviceOperatingSystem(array $device): string
{
    $operating_system = $device['operating_system'] ?? null;
    if (is_array($operating_system) && !empty($operating_system['full_operating_system'])) {
        return levelLimitText($operating_system['full_operating_system'], 200);
    }

    return levelLimitText($device['platform'] ?? '', 200);
}

function levelSyncDevice(array $device): array
{
    global $mysqli;

    $device_id = levelLimitText($device['id'] ?? '', 255);
    $hostname = levelLimitText($device['hostname'] ?? '', 255);
    if ($device_id === '' || $hostname === '') {
        throw new InvalidArgumentException('Level device payload is missing an id or hostname');
    }

    $group_id = levelLimitText($device['group_id'] ?? '', 255);
    $client_id = levelResolveClientForGroup($group_id);

    if (!levelAcquireDeviceLock($device_id)) {
        throw new RuntimeException('Could not obtain the Level device synchronization lock');
    }

    try {
        $device_id_sql = levelDbEscape($device_id);
        $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id, level_device_sync_status
            FROM level_asset_links WHERE level_device_id = '$device_id_sql' LIMIT 1"));

        if ($client_id === 0) {
            // Keep live Level context current for an already-linked asset, but do
            // not move or rewrite the ITFlow asset once its new group is unmapped.
            if ($link) {
                $group_sql = levelNullableSql($group_id === '' ? null : $group_id);
                $hostname_sql = levelDbEscape($hostname);
                $online = !empty($device['online']) ? 1 : 0;
                $last_seen_sql = levelNullableSql(levelDateTimeValue($device['last_seen_at'] ?? null));
                $security_score_value = $device['security_score'] ?? ($device['security']['score'] ?? null);
                $security_score_sql = is_numeric($security_score_value) ? intval($security_score_value) : 'NULL';
                $snapshot = json_encode($device, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $snapshot_sql = levelDbEscape($snapshot === false ? '{}' : $snapshot);

                mysqli_query($mysqli, "UPDATE level_asset_links SET
                    level_group_id = $group_sql,
                    level_device_hostname = '$hostname_sql',
                    level_device_online = $online,
                    level_device_last_seen_at = $last_seen_sql,
                    level_device_security_score = $security_score_sql,
                    level_device_snapshot = '$snapshot_sql',
                    level_device_sync_status = 'Unmapped',
                    level_device_sync_message = 'The Level group has no ITFlow client mapping',
                    level_device_last_synced_at = NOW(),
                    level_device_deleted_at = NULL
                    WHERE level_device_id = '$device_id_sql'");
            }

            return [
                'result' => 'skipped',
                'reason' => 'unmapped_group',
                'device_id' => $device_id,
                'asset_id' => intval($link['level_asset_id'] ?? 0),
            ];
        }

        $name = levelLimitText(($device['nickname'] ?? '') ?: $hostname, 200);
        $type = levelDeviceAssetType($device);
        $make = levelLimitText($device['manufacturer'] ?? '', 200);
        $model = levelLimitText($device['model'] ?? '', 200);
        $serial = levelLimitText($device['serial_number'] ?? '', 200);
        $os = levelDeviceOperatingSystem($device);

        $asset_id = intval($link['level_asset_id'] ?? 0);
        $result = $asset_id ? 'updated' : 'created';
        $sync_status = 'Synced';
        $sync_message = null;

        mysqli_begin_transaction($mysqli);
        try {
            if ($asset_id === 0 && $serial !== '') {
                // Exact serial plus client is the only automatic adoption path.
                // Hostnames are intentionally never used to attach an existing asset.
                $serial_sql = levelDbEscape($serial);
                $match_sql = mysqli_query($mysqli, "SELECT assets.asset_id FROM assets
                    LEFT JOIN level_asset_links ON level_asset_id = assets.asset_id
                    WHERE asset_client_id = $client_id
                    AND asset_serial = '$serial_sql'
                    AND asset_archived_at IS NULL
                    AND level_asset_link_id IS NULL
                    LIMIT 2 FOR UPDATE");

                if (mysqli_num_rows($match_sql) === 1) {
                    $asset_id = intval(mysqli_fetch_assoc($match_sql)['asset_id']);
                    $result = 'linked';
                }
            }

            $name_sql = levelDbEscape($name);
            $type_sql = levelDbEscape($type);
            $make_sql = levelDbEscape($make);
            $model_sql = levelDbEscape($model);
            $serial_sql = levelDbEscape($serial);
            $os_sql = levelDbEscape($os);

            if ($asset_id === 0) {
                if (!mysqli_query($mysqli, "INSERT INTO assets SET
                    asset_name = '$name_sql',
                    asset_description = 'Managed by Level.io',
                    asset_type = '$type_sql',
                    asset_make = '$make_sql',
                    asset_model = '$model_sql',
                    asset_serial = '$serial_sql',
                    asset_os = '$os_sql',
                    asset_status = 'Deployed',
                    asset_client_id = $client_id")) {
                    throw new RuntimeException('Could not create the ITFlow asset');
                }

                $asset_id = mysqli_insert_id($mysqli);
                mysqli_query($mysqli, "INSERT INTO asset_interfaces SET interface_name = '01', interface_primary = 1, interface_asset_id = $asset_id");
                mysqli_query($mysqli, "INSERT INTO asset_history SET asset_history_status = 'Deployed',
                    asset_history_description = 'Level.io created $name_sql', asset_history_asset_id = $asset_id");
            } else {
                $current_asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_client_id, asset_status
                    FROM assets WHERE asset_id = $asset_id FOR UPDATE"));
                if (!$current_asset) {
                    throw new RuntimeException('The ITFlow asset linked to this Level device no longer exists');
                }

                $old_client_id = intval($current_asset['asset_client_id']);
                $client_assignment_sql = ", asset_client_id = $client_id";
                if ($old_client_id !== $client_id) {
                    // Never silently move an asset across clients. Client-bound
                    // credentials, documents, and other relationships must be moved by
                    // ITFlow's explicit transfer workflow to avoid cross-client leakage.
                    $client_assignment_sql = '';
                    $sync_status = 'Conflict';
                    $sync_message = 'Level group maps to a different ITFlow client; transfer the asset manually';
                    $result = 'conflict';

                    if (($link['level_device_sync_status'] ?? '') !== 'Conflict') {
                        $history_status = levelDbEscape($current_asset['asset_status']);
                        mysqli_query($mysqli, "INSERT INTO asset_history SET asset_history_status = '$history_status',
                            asset_history_description = 'Level.io client mapping conflict requires review', asset_history_asset_id = $asset_id");
                    }
                }

                if (!mysqli_query($mysqli, "UPDATE assets SET
                    asset_name = '$name_sql',
                    asset_type = '$type_sql',
                    asset_make = '$make_sql',
                    asset_model = '$model_sql',
                    asset_serial = '$serial_sql',
                    asset_os = '$os_sql'
                    $client_assignment_sql
                    WHERE asset_id = $asset_id")) {
                    throw new RuntimeException('Could not update the ITFlow asset');
                }
            }

            $group_sql = levelNullableSql($group_id === '' ? null : $group_id);
            $hostname_sql = levelDbEscape($hostname);
            $online = !empty($device['online']) ? 1 : 0;
            $last_seen_sql = levelNullableSql(levelDateTimeValue($device['last_seen_at'] ?? null));
            $security_score_value = $device['security_score'] ?? ($device['security']['score'] ?? null);
            $security_score_sql = is_numeric($security_score_value) ? intval($security_score_value) : 'NULL';
            $snapshot = json_encode($device, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $snapshot_sql = levelDbEscape($snapshot === false ? '{}' : $snapshot);
            $sync_status_sql = levelDbEscape($sync_status);
            $sync_message_sql = levelNullableSql($sync_message);

            if ($link) {
                $link_sql = mysqli_query($mysqli, "UPDATE level_asset_links SET
                    level_asset_id = $asset_id,
                    level_group_id = $group_sql,
                    level_device_hostname = '$hostname_sql',
                    level_device_online = $online,
                    level_device_last_seen_at = $last_seen_sql,
                    level_device_security_score = $security_score_sql,
                    level_device_snapshot = '$snapshot_sql',
                    level_device_sync_status = '$sync_status_sql',
                    level_device_sync_message = $sync_message_sql,
                    level_device_last_synced_at = NOW(),
                    level_device_deleted_at = NULL
                    WHERE level_device_id = '$device_id_sql'");
            } else {
                $link_sql = mysqli_query($mysqli, "INSERT INTO level_asset_links SET
                    level_device_id = '$device_id_sql',
                    level_asset_id = $asset_id,
                    level_group_id = $group_sql,
                    level_device_hostname = '$hostname_sql',
                    level_device_online = $online,
                    level_device_last_seen_at = $last_seen_sql,
                    level_device_security_score = $security_score_sql,
                    level_device_snapshot = '$snapshot_sql',
                    level_device_sync_status = '$sync_status_sql',
                    level_device_sync_message = $sync_message_sql,
                    level_device_last_synced_at = NOW(),
                    level_device_deleted_at = NULL");
            }

            if (!$link_sql) {
                throw new RuntimeException('Could not save the Level device link');
            }

            mysqli_commit($mysqli);
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }

        if ($result === 'created') {
            logApp('Level.io', 'info', "Created ITFlow asset $asset_id for Level device $hostname");
        }

        return ['result' => $result, 'asset_id' => $asset_id, 'device_id' => $device_id];
    } finally {
        levelReleaseDeviceLock($device_id);
    }
}

function levelFetchAndSyncDevice(string $device_id): array
{
    $device_id = levelLimitText($device_id, 255);
    if ($device_id === '') {
        throw new InvalidArgumentException('Level device id is required');
    }

    $response = levelRequest('GET', '/v2/devices/' . rawurlencode($device_id), [
        'include_operating_system' => 'true',
        'include_network_interfaces' => 'true',
        'include_security' => 'true',
    ]);
    if (!$response['ok']) {
        // Reconcile against current Level state instead of applying a potentially
        // stale event action. This also makes delayed create/update deliveries
        // harmless after the device has already been deleted.
        if ($response['status'] === 404) {
            levelMarkDeviceDeleted($device_id);
            return ['result' => 'deleted', 'device_id' => $device_id];
        }

        throw new RuntimeException($response['error']);
    }

    if (levelLimitText($response['data']['id'] ?? '', 255) !== $device_id) {
        throw new RuntimeException('Level returned a different device than the webhook resource');
    }

    return levelSyncDevice($response['data']);
}

function levelMarkDeviceDeleted($device_id): void
{
    global $mysqli;

    $device_id = levelLimitText($device_id, 255);
    if ($device_id === '') {
        return;
    }

    $device_id_sql = levelDbEscape($device_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id, level_device_hostname
        FROM level_asset_links WHERE level_device_id = '$device_id_sql' LIMIT 1"));

    mysqli_query($mysqli, "UPDATE level_asset_links SET level_device_online = 0,
        level_device_deleted_at = NOW(), level_device_last_synced_at = NOW()
        WHERE level_device_id = '$device_id_sql'");

    if ($row) {
        $asset_id = intval($row['level_asset_id']);
        $hostname = levelDbEscape(levelLimitText($row['level_device_hostname'], 150));
        mysqli_query($mysqli, "INSERT INTO asset_history
            SELECT NULL, asset_status, 'Level.io removed $hostname from management', NOW(), asset_id
            FROM assets WHERE asset_id = $asset_id");
    }
}

function levelAlertPriority(string $severity): string
{
    return match (strtolower($severity)) {
        'emergency' => 'Urgent',
        'critical' => 'High',
        'warning' => 'Medium',
        default => 'Low',
    };
}

function levelAlertString($value): string
{
    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '' : $encoded;
    }

    return (string) $value;
}

function levelUpsertAlertLink(array $alert, ?int $ticket_id, ?int $asset_id, ?string $event_time = null): bool
{
    global $mysqli;

    $alert_id = levelDbEscape(levelLimitText($alert['id'] ?? '', 255));
    $device_id = levelDbEscape(levelLimitText($alert['device_id'] ?? '', 255));
    $name = levelDbEscape(levelLimitText($alert['name'] ?? 'Level.io alert', 255));
    $severity = strtolower(levelLimitText($alert['severity'] ?? 'information', 20));
    if (!in_array($severity, ['information', 'warning', 'critical', 'emergency'], true)) {
        $severity = 'information';
    }
    $severity_sql = levelDbEscape($severity);
    $started_sql = levelNullableSql(levelDateTimeValue($alert['started_at'] ?? null));
    $resolved_value = !empty($alert['is_resolved']) || !empty($alert['resolved_at'])
        ? levelDateTimeValue($alert['resolved_at'] ?? $event_time ?? 'now')
        : null;
    $resolved_sql = levelNullableSql($resolved_value);
    $event_sql = levelNullableSql(levelDateTimeValue($event_time) ?? levelDateTimeValue($alert['started_at'] ?? null));
    $ticket_sql = $ticket_id ? intval($ticket_id) : 'NULL';
    $asset_sql = $asset_id ? intval($asset_id) : 'NULL';

    return (bool) mysqli_query($mysqli, "INSERT INTO level_alert_links SET
        level_alert_id = '$alert_id',
        level_device_id = '$device_id',
        level_ticket_id = $ticket_sql,
        level_asset_id = $asset_sql,
        level_alert_name = '$name',
        level_alert_severity = '$severity_sql',
        level_alert_started_at = $started_sql,
        level_alert_resolved_at = $resolved_sql,
        level_alert_last_event_at = $event_sql
        ON DUPLICATE KEY UPDATE
        level_device_id = VALUES(level_device_id),
        level_ticket_id = COALESCE(VALUES(level_ticket_id), level_ticket_id),
        level_asset_id = COALESCE(VALUES(level_asset_id), level_asset_id),
        level_alert_name = VALUES(level_alert_name),
        level_alert_severity = VALUES(level_alert_severity),
        level_alert_started_at = COALESCE(VALUES(level_alert_started_at), level_alert_started_at),
        level_alert_resolved_at = VALUES(level_alert_resolved_at),
        level_alert_last_event_at = COALESCE(VALUES(level_alert_last_event_at), level_alert_last_event_at)");
}

function levelWebhookEventIsOlder(?string $incoming_time, ?string $stored_time): bool
{
    $incoming = levelDateTimeValue($incoming_time);
    $stored = levelDateTimeValue($stored_time);

    if ($incoming === null || $stored === null) {
        return false;
    }

    return strtotime($incoming) < strtotime($stored);
}

function levelHandleAlertActive(array $alert, ?string $event_time = null, bool $device_already_synced = false): array
{
    global $mysqli;

    $alert_id = levelLimitText($alert['id'] ?? '', 255);
    $device_id = levelLimitText($alert['device_id'] ?? '', 255);
    if ($alert_id === '' || $device_id === '') {
        throw new InvalidArgumentException('Level alert payload is missing an id or device id');
    }

    $settings = levelGetSettings();
    $alert_id_sql = levelDbEscape($alert_id);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_alert_last_event_at,
        level_alert_resolved_at, level_ticket_id
        FROM level_alert_links WHERE level_alert_id = '$alert_id_sql' LIMIT 1"));

    if ($existing && levelWebhookEventIsOlder($event_time, $existing['level_alert_last_event_at'] ?? null)) {
        return ['result' => 'stale', 'ticket_id' => intval($existing['level_ticket_id'] ?? 0)];
    }

    if (!$settings['alert_ticket_enabled'] || !$settings['ticketing_enabled']) {
        levelUpsertAlertLink($alert, null, null, $event_time);
        return ['result' => 'skipped', 'reason' => 'ticket_creation_disabled'];
    }

    $ticket_id = intval($existing['level_ticket_id'] ?? 0);
    if ($ticket_id > 0) {
        levelUpsertAlertLink($alert, $ticket_id, null, $event_time);
        if (!empty($existing['level_alert_resolved_at'])) {
            logTicketHistory($ticket_id, escapeSql('Level.io alert became active again; ticket left in its current workflow state.'));
        }
        return ['result' => 'existing', 'ticket_id' => $ticket_id];
    }

    if ($device_already_synced) {
        $device_id_sql = levelDbEscape($device_id);
        $asset_link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id, level_device_sync_status
            FROM level_asset_links WHERE level_device_id = '$device_id_sql'
            AND level_device_deleted_at IS NULL LIMIT 1"));
        $asset_id = intval($asset_link['level_asset_id'] ?? 0);
        if (!$asset_link || $asset_link['level_device_sync_status'] !== 'Synced') {
            levelUpsertAlertLink($alert, null, $asset_id ?: null, $event_time);
            return ['result' => 'skipped', 'reason' => 'client_mapping_conflict'];
        }
    } else {
        // Resolve the device against its current group before routing a new ticket.
        // This prevents an alert from crossing clients when a recent Level group move
        // has not reached ITFlow through the device webhook yet.
        $sync = levelFetchAndSyncDevice($device_id);
        if (!in_array(($sync['result'] ?? ''), ['created', 'linked', 'updated'], true)) {
            levelUpsertAlertLink($alert, null, intval($sync['asset_id'] ?? 0) ?: null, $event_time);
            return ['result' => 'skipped', 'reason' => ($sync['reason'] ?? 'client_mapping_conflict')];
        }
        $asset_id = intval($sync['asset_id'] ?? 0);
    }

    $asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_client_id, asset_location_id, asset_name
        FROM assets WHERE asset_id = $asset_id LIMIT 1"));
    $client_id = intval($asset['asset_client_id'] ?? 0);
    if ($client_id === 0) {
        levelUpsertAlertLink($alert, null, $asset_id ?: null, $event_time);
        return ['result' => 'skipped', 'reason' => 'asset_has_no_client'];
    }

    $lock_name = levelDbEscape('itflow_level_alert_' . sha1($alert_id));
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name', 10)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Could not obtain the Level alert ticket lock');
    }

    try {
        // Re-check after taking the lock so a webhook and reconciliation run
        // cannot both create a ticket for the same alert.
        $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_ticket_id
            FROM level_alert_links WHERE level_alert_id = '$alert_id_sql' LIMIT 1"));
        $ticket_id = intval($existing['level_ticket_id'] ?? 0);
        if ($ticket_id > 0) {
            levelUpsertAlertLink($alert, $ticket_id, $asset_id, $event_time);
            return ['result' => 'existing', 'ticket_id' => $ticket_id];
        }

        $contact_id = 0;
        $contact_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts
            WHERE contact_client_id = $client_id AND contact_primary = 1 AND contact_archived_at IS NULL LIMIT 1"));
        if ($contact_row) {
            $contact_id = intval($contact_row['contact_id']);
        }

        $severity = strtolower(levelLimitText($alert['severity'] ?? 'information', 20));
        if (!in_array($severity, ['information', 'warning', 'critical', 'emergency'], true)) {
            $severity = 'information';
        }
        $priority = levelAlertPriority($severity);
        $hostname = levelLimitText(($alert['device_hostname'] ?? '') ?: ($asset['asset_name'] ?? ''), 200);
        $alert_name = levelLimitText($alert['name'] ?? 'Level.io alert', 255);
        $subject = levelLimitText("[Level " . ucfirst($severity) . "] $alert_name - $hostname", 500);

        $description = levelAlertString($alert['description'] ?? '');
        $payload = levelAlertString($alert['payload'] ?? '');
        $started_at = levelDateTimeValue($alert['started_at'] ?? null);
        $details = '<p><strong>Level.io alert</strong></p>'
            . '<p><strong>Device:</strong> ' . escapeHtml($hostname) . '<br>'
            . '<strong>Severity:</strong> ' . escapeHtml(ucfirst($severity)) . '<br>'
            . '<strong>Started:</strong> ' . escapeHtml($started_at ?? 'Unknown') . '<br>'
            . '<strong>Alert ID:</strong> <code>' . escapeHtml($alert_id) . '</code></p>'
            . '<p>' . nl2br(escapeHtml($description)) . '</p>';
        if ($payload !== '') {
            $details .= '<pre>' . escapeHtml($payload) . '</pre>';
        }

        $subject_sql = levelDbEscape($subject);
        $details_sql = levelDbEscape($details);
        $priority_sql = levelDbEscape($priority);
        $prefix_sql = levelDbEscape(levelLimitText($settings['ticket_prefix'], 200));
        $assigned_to = intval($settings['alert_assigned_to']);
        $billable = intval($settings['ticket_default_billable']);
        $location_id = intval($asset['asset_location_id'] ?? 0);

        mysqli_begin_transaction($mysqli);
        try {
            mysqli_query($mysqli, "UPDATE settings SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1");
            $ticket_number = mysqli_insert_id($mysqli);
            $url_key = levelDbEscape(randomString(32));

            $insert = mysqli_query($mysqli, "INSERT INTO tickets SET
                ticket_prefix = '$prefix_sql',
                ticket_number = $ticket_number,
                ticket_source = 'Level.io',
                ticket_subject = '$subject_sql',
                ticket_details = '$details_sql',
                ticket_priority = '$priority_sql',
                ticket_status = 1,
                ticket_billable = $billable,
                ticket_url_key = '$url_key',
                ticket_created_by = 0,
                ticket_assigned_to = $assigned_to,
                ticket_client_id = $client_id,
                ticket_contact_id = $contact_id,
                ticket_location_id = $location_id,
                ticket_asset_id = $asset_id");

            if (!$insert) {
                throw new RuntimeException('Could not create the ITFlow ticket for the Level alert');
            }
            $ticket_id = mysqli_insert_id($mysqli);

            if (!levelUpsertAlertLink($alert, $ticket_id, $asset_id, $event_time)) {
                throw new RuntimeException('Could not save the Level alert-to-ticket link');
            }
            mysqli_commit($mysqli);
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }

        applyTicketSla($ticket_id);
        logTicketHistory($ticket_id, escapeSql("Level.io alert $alert_id created this ticket."));
        appNotify('Level.io Alert', "Level $severity alert opened ticket $prefix_sql$ticket_number", "ticket.php?ticket_id=$ticket_id", $client_id, $ticket_id);
        logApp('Level.io', 'info', "Created ticket $prefix_sql$ticket_number for Level alert $alert_id");

        return ['result' => 'created', 'ticket_id' => $ticket_id];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name')");
    }
}

function levelHandleAlertResolved(array $alert, ?string $event_time = null): array
{
    global $mysqli;

    $alert_id = levelLimitText($alert['id'] ?? '', 255);
    $device_id = levelLimitText($alert['device_id'] ?? '', 255);
    if ($alert_id === '') {
        throw new InvalidArgumentException('Level resolved alert payload is missing an id');
    }

    $alert['is_resolved'] = true;
    if (empty($alert['resolved_at'])) {
        $alert['resolved_at'] = $event_time ?? date(DATE_ATOM);
    }

    $alert_id_sql = levelDbEscape($alert_id);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_alert_last_event_at,
        level_alert_resolved_at, level_asset_id, level_ticket_id
        FROM level_alert_links WHERE level_alert_id = '$alert_id_sql' LIMIT 1"));
    $ticket_id = intval($existing['level_ticket_id'] ?? 0);
    $asset_id = intval($existing['level_asset_id'] ?? 0);

    if ($existing && levelWebhookEventIsOlder($event_time, $existing['level_alert_last_event_at'] ?? null)) {
        return ['result' => 'stale', 'ticket_id' => $ticket_id];
    }

    if ($asset_id === 0 && $device_id !== '') {
        $device_id_sql = levelDbEscape($device_id);
        $asset_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id FROM level_asset_links
            WHERE level_device_id = '$device_id_sql' LIMIT 1"));
        $asset_id = intval($asset_row['level_asset_id'] ?? 0);
    }

    levelUpsertAlertLink($alert, $ticket_id ?: null, $asset_id ?: null, $event_time);

    if ($ticket_id > 0 && empty($existing['level_alert_resolved_at'])) {
        logTicketHistory($ticket_id, escapeSql('Level.io reported the alert resolved; ticket left open for technician review.'));
    }

    return ['result' => $ticket_id ? 'updated' : 'recorded', 'ticket_id' => $ticket_id];
}

function levelProcessWebhookEvent(array $event): array
{
    $type = (string) ($event['event_type'] ?? '');
    $data = $event['data'] ?? [];
    $event_time = (string) ($event['occurred_at'] ?? '');
    $validation_error = levelWebhookValidationError($event);

    if ($validation_error !== null) {
        throw new InvalidArgumentException($validation_error);
    }

    $definition = levelWebhookEventDefinition($type);
    $resource_id = (string) $data['id'];

    return match ($definition['resource']) {
        // Devices and groups are reconciled by resource id for every action. If
        // the object no longer exists, the fetch helper applies the deletion;
        // otherwise it updates the exact corresponding ITFlow resource with the
        // latest Level snapshot. This is safe when deliveries arrive out of order.
        'device' => levelFetchAndSyncDevice($resource_id),
        'group' => levelFetchAndStoreGroup($resource_id),
        'alert' => ($definition['action'] === 'resolved'
            || !empty($data['is_resolved'])
            || !empty($data['resolved_at']))
                ? levelHandleAlertResolved($data, $event_time)
                : levelHandleAlertActive($data, $event_time),
        default => throw new InvalidArgumentException('Unsupported Level webhook resource'),
    };
}

function levelProcessWebhookQueue(int $limit = 50): array
{
    global $mysqli;

    $limit = min(200, max(1, $limit));
    $summary = ['processed' => 0, 'failed' => 0];
    $sql = mysqli_query($mysqli, "SELECT level_webhook_event_id, level_webhook_payload
        FROM level_webhook_events
        WHERE level_webhook_process_attempts < 10
        AND (
            level_webhook_status IN ('Pending', 'Failed')
            OR (level_webhook_status = 'Processing' AND level_webhook_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        )
        ORDER BY level_webhook_received_at ASC
        LIMIT $limit");

    while ($row = mysqli_fetch_assoc($sql)) {
        $event_id = (string) $row['level_webhook_event_id'];
        $event_id_sql = levelDbEscape($event_id);

        mysqli_query($mysqli, "UPDATE level_webhook_events SET
            level_webhook_status = 'Processing',
            level_webhook_processing_at = NOW(),
            level_webhook_process_attempts = level_webhook_process_attempts + 1
            WHERE level_webhook_event_id = '$event_id_sql'
            AND level_webhook_process_attempts < 10
            AND (
                level_webhook_status IN ('Pending', 'Failed')
                OR (level_webhook_status = 'Processing' AND level_webhook_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            )");

        if (mysqli_affected_rows($mysqli) !== 1) {
            continue;
        }

        try {
            $event = json_decode($row['level_webhook_payload'], true, 64, JSON_THROW_ON_ERROR);
            levelProcessWebhookEvent($event);
            mysqli_query($mysqli, "UPDATE level_webhook_events SET
                level_webhook_status = 'Processed',
                level_webhook_processed_at = NOW(),
                level_webhook_last_error = NULL
                WHERE level_webhook_event_id = '$event_id_sql'");
            $summary['processed']++;
        } catch (Throwable $e) {
            $error = levelDbEscape(levelLimitText($e->getMessage(), 1000));
            mysqli_query($mysqli, "UPDATE level_webhook_events SET
                level_webhook_status = 'Failed',
                level_webhook_last_error = '$error'
                WHERE level_webhook_event_id = '$event_id_sql'");
            logApp('Level.io', 'error', "Webhook event $event_id failed: " . $e->getMessage());
            $summary['failed']++;
        }
    }

    // Payloads can contain device and alert details. Keep enough history for
    // troubleshooting retries without turning the delivery queue into a second,
    // indefinite telemetry archive.
    mysqli_query($mysqli, "DELETE FROM level_webhook_events
        WHERE level_webhook_status = 'Processed'
        AND level_webhook_processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 1000");

    return $summary;
}

function levelRunFullSync(): array
{
    global $mysqli;

    $summary = [
        'groups' => levelDiscoverGroups(),
        'devices_created' => 0,
        'devices_linked' => 0,
        'devices_updated' => 0,
        'devices_skipped' => 0,
        'alert_tickets_created' => 0,
        'alerts_existing' => 0,
        'alerts_skipped' => 0,
    ];

    $device_sync_started = date('Y-m-d H:i:s');
    $devices = levelListAll('/v2/devices', [
        'include_operating_system' => 'true',
        'include_network_interfaces' => 'true',
        'include_security' => 'true',
    ]);
    foreach ($devices as $device) {
        $result = levelSyncDevice($device);
        $key = match ($result['result'] ?? '') {
            'created' => 'devices_created',
            'linked' => 'devices_linked',
            'updated' => 'devices_updated',
            default => 'devices_skipped',
        };
        $summary[$key]++;
    }

    // A webhook may be missed during downtime. A complete API list is also the
    // reconciliation authority for devices no longer present in Level.
    $device_sync_started_sql = levelDbEscape($device_sync_started);
    mysqli_query($mysqli, "UPDATE level_asset_links SET
        level_device_online = 0,
        level_device_deleted_at = COALESCE(level_device_deleted_at, NOW())
        WHERE level_device_last_synced_at < '$device_sync_started_sql'");

    $settings = levelGetSettings();
    if ($settings['alert_ticket_enabled'] && $settings['ticketing_enabled']) {
        $alerts = levelListAll('/v2/alerts', ['status' => 'active']);
        $active_alert_ids = [];
        foreach ($alerts as $alert) {
            if (!empty($alert['id'])) {
                $active_alert_ids[(string) $alert['id']] = true;
            }
            $result = levelHandleAlertActive($alert, null, true);
            $key = match ($result['result'] ?? '') {
                'created' => 'alert_tickets_created',
                'existing' => 'alerts_existing',
                default => 'alerts_skipped',
            };
            $summary[$key]++;
        }

        // Reconcile resolutions even when their webhook was missed, without
        // downloading Level's entire historical resolved-alert collection.
        $local_active_sql = mysqli_query($mysqli, "SELECT level_alert_id FROM level_alert_links
            WHERE level_alert_resolved_at IS NULL");
        while ($local_alert = mysqli_fetch_assoc($local_active_sql)) {
            $alert_id = (string) $local_alert['level_alert_id'];
            if (isset($active_alert_ids[$alert_id])) {
                continue;
            }

            $response = levelRequest('GET', '/v2/alerts/' . rawurlencode($alert_id));
            if ($response['ok']) {
                if (!empty($response['data']['is_resolved'])) {
                    levelHandleAlertResolved($response['data'], $response['data']['resolved_at'] ?? null);
                }
            } elseif ($response['status'] !== 404) {
                throw new RuntimeException($response['error']);
            }
        }
    }

    return $summary;
}

function levelQueueCronJob(string $job_name): bool
{
    global $mysqli;

    $jobs = [
        'level_sync' => 15,
        'level_webhook_processor' => 1,
    ];
    if (!isset($jobs[$job_name])) {
        return false;
    }

    $job_name_sql = levelDbEscape($job_name);
    $interval = $jobs[$job_name];
    mysqli_query($mysqli, "INSERT IGNORE INTO cron_jobs SET
        cron_job_name = '$job_name_sql',
        cron_job_enabled = 1,
        cron_job_schedule = 'Interval',
        cron_job_interval_minutes = $interval");
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1 WHERE cron_job_name = '$job_name_sql'");

    return mysqli_affected_rows($mysqli) >= 0;
}

function levelBuildGroupDisplayRows(array $groups): array
{
    $by_id = [];
    $children = [];

    foreach ($groups as $group) {
        $id = (string) ($group['level_group_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $by_id[$id] = $group;
        $parent = (string) ($group['level_parent_group_id'] ?? '');
        $children[$parent][] = $id;
    }

    foreach ($children as &$ids) {
        usort($ids, function ($a, $b) use ($by_id) {
            return strcasecmp((string) ($by_id[$a]['level_group_name'] ?? ''), (string) ($by_id[$b]['level_group_name'] ?? ''));
        });
    }
    unset($ids);

    $result = [];
    $visited = [];
    $walk = function ($id, $depth) use (&$walk, &$result, &$visited, $by_id, $children) {
        if (isset($visited[$id]) || !isset($by_id[$id])) {
            return;
        }
        $visited[$id] = true;
        $row = $by_id[$id];
        $row['level_group_depth'] = $depth;
        $result[] = $row;

        foreach ($children[$id] ?? [] as $child_id) {
            $walk($child_id, $depth + 1);
        }
    };

    foreach ($by_id as $id => $group) {
        $parent = (string) ($group['level_parent_group_id'] ?? '');
        if ($parent === '' || !isset($by_id[$parent])) {
            $walk($id, 0);
        }
    }

    // Corrupt or cyclic external hierarchies still remain visible to admins.
    foreach (array_keys($by_id) as $id) {
        $walk($id, 0);
    }

    return $result;
}
