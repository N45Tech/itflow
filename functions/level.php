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
        'enabled' => n45FeatureEnabled('level') && intval($row['config_level_enable'] ?? 0),
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
    if (!n45FeatureEnabled('level')) {
        return ['ok' => false, 'status' => 0, 'data' => null,
            'error' => 'Level integration is disabled by deployment feature flag'];
    }

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

/**
 * Resolve inherited Level routing while holding every ancestry row and the
 * selected client until the caller's device transaction commits. Group
 * discovery and mapping edits use INSERT/UPDATE on these same rows, so they
 * cannot change a route underneath asset or endpoint persistence.
 */
function levelResolveClientForGroupLocked($group_id): int
{
    global $mysqli;

    if (!n45DatabaseTransactionActive()) {
        throw new LogicException('Locked Level group resolution requires a transaction');
    }

    $current = levelLimitText($group_id, 255);
    $visited = [];
    for ($depth = 0; $depth < 100 && $current !== ''; $depth++) {
        if (isset($visited[$current])) {
            return 0;
        }
        $visited[$current] = true;

        $current_sql = levelDbEscape($current);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_group_client_id,
            level_parent_group_id FROM level_group_mappings
            WHERE level_group_id = '$current_sql' LIMIT 1 FOR UPDATE"));
        if (!$row) {
            return 0;
        }

        $client_id = intval($row['level_group_client_id']);
        if ($client_id > 0) {
            $client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id, client_archived_at
                FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE"));
            return $client && empty($client['client_archived_at']) ? $client_id : 0;
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

function levelNormalizeMacAddress($value): string
{
    $compact = strtolower((string) preg_replace('/[^0-9a-f]/i', '', trim((string) $value)));
    if (strlen($compact) !== 12
        || $compact === str_repeat('0', 12)
        || $compact === str_repeat('f', 12)) {
        return '';
    }

    return implode(':', str_split($compact, 2));
}

function levelNormalizeIpAddress($value): string
{
    if (!is_string($value)) {
        return '';
    }

    $address = trim($value);
    if ($address === '') {
        return '';
    }

    // Level normally returns bare addresses, but tolerate scope identifiers and
    // CIDR suffixes so a platform-specific response cannot create duplicates.
    $address = trim($address, '[]');
    if (str_contains($address, '/')) {
        $address = explode('/', $address, 2)[0];
    }
    if (str_contains($address, '%')) {
        $address = explode('%', $address, 2)[0];
    }

    $packed = @inet_pton($address);
    if ($packed === false) {
        return '';
    }

    return strtolower((string) inet_ntop($packed));
}

function levelIpAddressIsUsable(string $address): bool
{
    $packed = @inet_pton($address);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        $first = ord($packed[0]);
        return $address !== '0.0.0.0' && $first !== 127;
    }

    return $packed !== str_repeat("\0", 16)
        && $packed !== (str_repeat("\0", 15) . "\1");
}

function levelIpAddressIsLinkLocal(string $address): bool
{
    $packed = @inet_pton($address);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 4) {
        return ord($packed[0]) === 169 && ord($packed[1]) === 254;
    }

    return ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80;
}

function levelNetworkInterfaceType(array $interface): string
{
    $text = strtolower(trim((string) (($interface['label'] ?? '') . ' ' . ($interface['description'] ?? ''))));

    if (str_contains($text, 'loopback')) {
        return 'Loopback';
    }
    if (preg_match('/\b(virtual|vpn|tunnel|wireguard|tailscale|zerotier|tap|tun|hyper-v|vmware|virtualbox)\b/', $text)) {
        return 'Virtual';
    }
    if (preg_match('/\b(wi-?fi|wireless|wlan|802\.11|airport)\b/', $text)) {
        return 'Wireless';
    }
    if (preg_match('/\b(ethernet|gigabit|gbe|802\.3|eth\d*|eno\d*|enp\w*)\b/', $text)) {
        return 'Ethernet';
    }

    return '';
}

function levelNetworkInterfaceKey(array $interface, int $position): string
{
    $level_interface = strtolower(levelLimitText($interface['interface'] ?? '', 220));
    if ($level_interface !== '') {
        return 'interface:' . $level_interface;
    }

    $mac = levelNormalizeMacAddress($interface['mac_address'] ?? '');
    if ($mac !== '') {
        return 'mac:' . $mac;
    }

    $identity = [
        levelLimitText($interface['label'] ?? '', 200),
        levelLimitText($interface['description'] ?? '', 200),
        $position,
    ];

    return 'snapshot:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES));
}

function levelNormalizeNetworkInterfaces(array $device): array
{
    $raw_interfaces = $device['network_interfaces'] ?? [];
    if (!is_array($raw_interfaces)) {
        return [];
    }

    $interfaces = [];
    $key_counts = [];

    foreach ($raw_interfaces as $position => $raw_interface) {
        if (!is_array($raw_interface)) {
            continue;
        }

        $mac = levelNormalizeMacAddress($raw_interface['mac_address'] ?? '');
        $ipv4_addresses = [];
        $ipv6_addresses = [];
        $raw_addresses = $raw_interface['ip_addresses'] ?? [];
        if (!is_array($raw_addresses)) {
            $raw_addresses = [];
        }
        foreach ($raw_addresses as $raw_address) {
            $address = levelNormalizeIpAddress($raw_address);
            if ($address === '' || !levelIpAddressIsUsable($address)) {
                continue;
            }

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipv4_addresses[$address] = true;
            } else {
                $ipv6_addresses[$address] = true;
            }
        }

        $ipv4_addresses = array_keys($ipv4_addresses);
        $ipv6_addresses = array_keys($ipv6_addresses);

        // Empty and loopback-only pseudo-adapters are not useful ITFlow assets.
        if ($mac === '' && !$ipv4_addresses && !$ipv6_addresses) {
            continue;
        }

        usort($ipv4_addresses, fn ($a, $b) => intval(levelIpAddressIsLinkLocal($a)) <=> intval(levelIpAddressIsLinkLocal($b)));
        usort($ipv6_addresses, fn ($a, $b) => intval(levelIpAddressIsLinkLocal($a)) <=> intval(levelIpAddressIsLinkLocal($b)));

        $ipv4 = (string) ($ipv4_addresses[0] ?? '');
        $ipv6 = (string) ($ipv6_addresses[0] ?? '');
        $additional_addresses = array_merge(array_slice($ipv4_addresses, 1), array_slice($ipv6_addresses, 1));

        $label = levelLimitText($raw_interface['label'] ?? '', 200);
        $level_interface = levelLimitText($raw_interface['interface'] ?? '', 200);
        $description = levelLimitText($raw_interface['description'] ?? '', 200);
        $name = $label ?: ($level_interface ?: ($description ?: 'Interface ' . (intval($position) + 1)));
        $type = levelNetworkInterfaceType($raw_interface);

        $gateway = levelNormalizeIpAddress($raw_interface['gateway'] ?? '');
        if ($gateway !== '' && !levelIpAddressIsUsable($gateway)) {
            $gateway = '';
        }
        $dhcp_server = levelNormalizeIpAddress($raw_interface['dhcp_server'] ?? '');
        if ($dhcp_server !== '' && !levelIpAddressIsUsable($dhcp_server)) {
            $dhcp_server = '';
        }
        $dns_servers = $raw_interface['dns_servers'] ?? '';
        if (is_array($dns_servers)) {
            $dns_servers = implode(', ', array_filter(array_map('strval', $dns_servers)));
        }
        $dns_servers = levelLimitText($dns_servers, 500);
        $domain = levelLimitText($raw_interface['domain'] ?? '', 255);
        $vlan_id = is_numeric($raw_interface['vlan_id'] ?? null)
            ? intval($raw_interface['vlan_id'])
            : null;
        if ($vlan_id !== null && ($vlan_id < 1 || $vlan_id > 4094)) {
            $vlan_id = null;
        }
        $neighbor_protocol = strtolower(levelLimitText(
            $raw_interface['neighbor_protocol']
                ?? $raw_interface['discovery_protocol']
                ?? $raw_interface['protocol']
                ?? '',
            20
        ));
        if (!in_array($neighbor_protocol, ['lldp', 'cdp'], true)) {
            $neighbor_protocol = 'rmm';
        }

        $notes = ['Managed by Level.io.'];
        if ($level_interface !== '' && $level_interface !== $name) {
            $notes[] = 'Level interface: ' . $level_interface;
        }
        if ($gateway !== '') {
            $notes[] = 'Gateway: ' . $gateway;
        }
        if ($dhcp_server !== '') {
            $notes[] = 'DHCP server: ' . $dhcp_server;
        }
        if ($dns_servers !== '') {
            $notes[] = 'DNS servers: ' . $dns_servers;
        }
        if ($domain !== '') {
            $notes[] = 'Domain: ' . $domain;
        }
        if ($additional_addresses) {
            $notes[] = 'Additional addresses: ' . implode(', ', $additional_addresses);
        }

        $key = levelNetworkInterfaceKey($raw_interface, intval($position));
        $key_counts[$key] = ($key_counts[$key] ?? 0) + 1;
        if ($key_counts[$key] > 1) {
            $key = levelLimitText($key, 235) . '#' . $key_counts[$key];
        }

        $primary_score = 0;
        if ($ipv4 !== '') {
            $primary_score += levelIpAddressIsLinkLocal($ipv4) ? 80 : 120;
        }
        if ($ipv6 !== '') {
            $primary_score += levelIpAddressIsLinkLocal($ipv6) ? 10 : 30;
        }
        if ($gateway !== '') {
            $primary_score += 60;
        }
        if ($type === 'Virtual') {
            $primary_score -= 40;
        }

        $interfaces[] = [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'type' => $type,
            'mac' => $mac,
            'mac_address' => $mac,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'ip_addresses' => array_merge($ipv4_addresses, $ipv6_addresses),
            'vlan_id' => $vlan_id,
            'vlan_name' => levelLimitText($raw_interface['vlan_name'] ?? '', 100),
            'neighbor_protocol' => $neighbor_protocol,
            'neighbor_name' => levelLimitText(
                $raw_interface['neighbor_name'] ?? $raw_interface['switch_name'] ?? '',
                255
            ),
            'neighbor_chassis_id' => levelLimitText(
                $raw_interface['neighbor_chassis_id'] ?? $raw_interface['chassis_id'] ?? '',
                255
            ),
            'neighbor_port' => levelLimitText(
                $raw_interface['neighbor_port'] ?? $raw_interface['switch_port'] ?? $raw_interface['port_id'] ?? '',
                255
            ),
            'notes' => implode("\n", $notes),
            'primary_score' => $primary_score,
            'primary' => false,
        ];
    }

    if ($interfaces) {
        $primary_index = 0;
        foreach ($interfaces as $index => $interface) {
            if ($interface['primary_score'] > $interfaces[$primary_index]['primary_score']) {
                $primary_index = $index;
            }
        }
        $interfaces[$primary_index]['primary'] = true;
    }

    return $interfaces;
}

function levelFindBlankAssetInterface(int $asset_id): int
{
    global $mysqli;

    $sql = mysqli_query($mysqli, "SELECT ai.interface_id FROM asset_interfaces ai
        LEFT JOIN level_interface_links lil ON lil.level_asset_interface_id = ai.interface_id
        LEFT JOIN asset_interface_links ail
            ON ail.interface_a_id = ai.interface_id OR ail.interface_b_id = ai.interface_id
        WHERE ai.interface_asset_id = $asset_id
        AND ai.interface_archived_at IS NULL
        AND ai.interface_name IN ('1', '01')
        AND COALESCE(ai.interface_description, '') = ''
        AND COALESCE(ai.interface_type, '') = ''
        AND COALESCE(ai.interface_mac, '') = ''
        AND COALESCE(ai.interface_ip, '') = ''
        AND COALESCE(ai.interface_nat_ip, '') = ''
        AND COALESCE(ai.interface_ipv6, '') = ''
        AND COALESCE(ai.interface_notes, '') = ''
        AND COALESCE(ai.interface_network_id, 0) = 0
        AND lil.level_interface_link_id IS NULL
        AND ail.interface_link_id IS NULL
        LIMIT 2 FOR UPDATE");
    if (!$sql) {
        throw new RuntimeException('Could not inspect the ITFlow asset interface placeholder');
    }

    if (mysqli_num_rows($sql) !== 1) {
        return 0;
    }

    return intval(mysqli_fetch_assoc($sql)['interface_id']);
}

function levelReconcileAssetInterfaces(
    int $asset_id,
    string $device_id,
    array $device
): array
{
    global $mysqli;

    $interfaces = levelNormalizeNetworkInterfaces($device);
    $device_id_sql = levelDbEscape(levelLimitText($device_id, 255));
    $existing = [];

    $mapping_sql = mysqli_query($mysqli, "SELECT lil.level_interface_key, lil.level_asset_interface_id,
        ai.interface_asset_id, ai.interface_network_id FROM level_interface_links lil
        INNER JOIN asset_interfaces ai ON ai.interface_id = lil.level_asset_interface_id
        WHERE lil.level_device_id = '$device_id_sql' FOR UPDATE");
    if (!$mapping_sql) {
        throw new RuntimeException('Could not read Level-managed ITFlow interfaces');
    }
    while ($mapping = mysqli_fetch_assoc($mapping_sql)) {
        $existing[(string) $mapping['level_interface_key']] = $mapping;
    }

    $placeholder_id = $interfaces ? levelFindBlankAssetInterface($asset_id) : 0;
    $manual_primary_sql = mysqli_query($mysqli, "SELECT ai.interface_id FROM asset_interfaces ai
        LEFT JOIN level_interface_links lil ON lil.level_asset_interface_id = ai.interface_id
        WHERE ai.interface_asset_id = $asset_id
        AND ai.interface_archived_at IS NULL
        AND ai.interface_primary = 1
        AND lil.level_interface_link_id IS NULL
        " . ($placeholder_id > 0 ? "AND ai.interface_id <> $placeholder_id" : '') . "
        LIMIT 1 FOR UPDATE");
    if (!$manual_primary_sql) {
        throw new RuntimeException('Could not determine the ITFlow primary interface');
    }
    $manual_primary_exists = mysqli_num_rows($manual_primary_sql) > 0;

    $seen_keys = [];
    $summary = ['total' => count($interfaces), 'created' => 0, 'updated' => 0, 'archived' => 0];
    $observed_interfaces = [];

    foreach ($interfaces as $interface) {
        $key = (string) $interface['key'];
        $key_sql = levelDbEscape($key);
        $mapping = $existing[$key] ?? null;
        $interface_id = intval($mapping['level_asset_interface_id'] ?? 0);

        if ($mapping && intval($mapping['interface_asset_id']) !== $asset_id) {
            throw new RuntimeException('A Level interface mapping points to a different ITFlow asset');
        }

        if ($interface_id === 0) {
            if ($placeholder_id > 0) {
                $interface_id = $placeholder_id;
                $placeholder_id = 0;
            } else {
                $name_sql = levelDbEscape($interface['name']);
                if (!mysqli_query($mysqli, "INSERT INTO asset_interfaces SET
                    interface_name = '$name_sql', interface_asset_id = $asset_id")) {
                    throw new RuntimeException('Could not create the ITFlow asset interface');
                }
                $interface_id = mysqli_insert_id($mysqli);
            }

            if (!mysqli_query($mysqli, "INSERT INTO level_interface_links SET
                level_device_id = '$device_id_sql',
                level_interface_key = '$key_sql',
                level_asset_interface_id = $interface_id,
                level_interface_last_seen_at = NOW(),
                level_interface_deleted_at = NULL")) {
                throw new RuntimeException('Could not create the Level interface mapping');
            }
            $summary['created']++;
        } else {
            $summary['updated']++;
        }

        $name_sql = levelDbEscape($interface['name']);
        $description_sql = levelNullableSql($interface['description']);
        $type_sql = levelNullableSql($interface['type']);
        $mac_sql = levelNullableSql($interface['mac']);
        $ipv4_sql = levelNullableSql($interface['ipv4']);
        $ipv6_sql = levelNullableSql($interface['ipv6']);
        $notes_sql = levelNullableSql($interface['notes']);
        $primary = !$manual_primary_exists && !empty($interface['primary']) ? 1 : 0;

        if (!mysqli_query($mysqli, "UPDATE asset_interfaces SET
            interface_name = '$name_sql',
            interface_description = $description_sql,
            interface_type = $type_sql,
            interface_mac = $mac_sql,
            interface_ip = $ipv4_sql,
            interface_ipv6 = $ipv6_sql,
            interface_notes = $notes_sql,
            interface_primary = $primary,
            interface_archived_at = NULL
            WHERE interface_id = $interface_id AND interface_asset_id = $asset_id")) {
            throw new RuntimeException('Could not update the ITFlow asset interface');
        }

        if (!mysqli_query($mysqli, "UPDATE level_interface_links SET
            level_interface_last_seen_at = NOW(), level_interface_deleted_at = NULL
            WHERE level_device_id = '$device_id_sql' AND level_interface_key = '$key_sql'")) {
            throw new RuntimeException('Could not update the Level interface mapping');
        }

        $observation = $interface;
        $observation['interface_id'] = $interface_id;
        $observation['network_id'] = intval($mapping['interface_network_id'] ?? 0);
        $observed_interfaces[] = $observation;
        $seen_keys[$key] = true;
    }

    foreach ($existing as $key => $mapping) {
        if (isset($seen_keys[$key])) {
            continue;
        }

        $interface_id = intval($mapping['level_asset_interface_id']);
        $key_sql = levelDbEscape($key);
        if (!mysqli_query($mysqli, "UPDATE asset_interfaces SET
            interface_primary = 0,
            interface_archived_at = COALESCE(interface_archived_at, NOW())
            WHERE interface_id = $interface_id AND interface_asset_id = $asset_id")) {
            throw new RuntimeException('Could not archive the stale ITFlow asset interface');
        }
        if (!mysqli_query($mysqli, "UPDATE level_interface_links SET
            level_interface_deleted_at = COALESCE(level_interface_deleted_at, NOW())
            WHERE level_device_id = '$device_id_sql' AND level_interface_key = '$key_sql'")) {
            throw new RuntimeException('Could not archive the stale Level interface mapping');
        }
        $summary['archived']++;
    }

    $summary['_endpoint_observations'] = $observed_interfaces;

    return $summary;
}

function levelArchiveDeviceInterfaces(string $device_id): void
{
    global $mysqli;

    $device_id_sql = levelDbEscape(levelLimitText($device_id, 255));
    if ($device_id_sql === '') {
        return;
    }

    if (!mysqli_query($mysqli, "UPDATE asset_interfaces ai
        INNER JOIN level_interface_links lil ON lil.level_asset_interface_id = ai.interface_id
        SET ai.interface_primary = 0,
            ai.interface_archived_at = COALESCE(ai.interface_archived_at, NOW()),
            lil.level_interface_deleted_at = COALESCE(lil.level_interface_deleted_at, NOW())
        WHERE lil.level_device_id = '$device_id_sql'")) {
        throw new RuntimeException('Could not archive Level-managed ITFlow interfaces');
    }
}

/**
 * Select only reconciliation facts that are safe to persist. Level responses
 * may grow new fields over time, so an allowlist prevents credentials or the
 * last logged-in user from silently entering ITFlow snapshots.
 */
function levelIdentitySnapshot(array $device): array
{
    $security_score = $device['security_score'] ?? ($device['security']['score'] ?? null);
    $network_interfaces = levelNormalizeNetworkInterfaces($device);
    usort($network_interfaces, static fn ($a, $b) => strcmp((string) $a['key'], (string) $b['key']));

    return [
        'device_id' => levelLimitText($device['id'] ?? '', 255),
        'group_id' => levelLimitText($device['group_id'] ?? '', 255),
        'hostname' => levelLimitText($device['hostname'] ?? '', 255),
        'nickname' => levelLimitText($device['nickname'] ?? '', 255),
        'role' => levelLimitText($device['role'] ?? '', 40),
        'manufacturer' => levelLimitText($device['manufacturer'] ?? '', 200),
        'model' => levelLimitText($device['model'] ?? '', 200),
        'serial_number' => levelLimitText($device['serial_number'] ?? '', 200),
        'platform' => levelLimitText($device['platform'] ?? '', 200),
        'operating_system' => levelDeviceOperatingSystem($device),
        'online' => !empty($device['online']),
        'last_seen_at' => levelDateTimeValue($device['last_seen_at'] ?? null),
        'total_memory' => max(0, intval($device['total_memory'] ?? 0)),
        'cpu_cores' => max(0, intval($device['cpu_cores'] ?? 0)),
        'security_score' => is_numeric($security_score) ? intval($security_score) : null,
        'network_interfaces' => $network_interfaces,
    ];
}

function levelEndpointFacts(array $device): array
{
    $operating_system = is_array($device['operating_system'] ?? null)
        ? $device['operating_system']
        : [];
    $security_score = $device['security_score'] ?? ($device['security']['score'] ?? null);

    return [
        'health_state' => !empty($device['online']) ? 'healthy' : 'offline',
        'online' => !empty($device['online']),
        'operating_system' => levelDeviceOperatingSystem($device),
        'os_version' => $operating_system['version'] ?? $device['os_version'] ?? '',
        'os_build' => $operating_system['build'] ?? $device['os_build'] ?? '',
        'agent_version' => $device['agent_version'] ?? '',
        'lifecycle_state' => 'active',
        'last_seen_at' => $device['last_seen_at'] ?? null,
        'platform' => $device['platform'] ?? '',
        'role' => $device['role'] ?? '',
        'security_score' => $security_score,
    ];
}

function levelUpsertDeviceIdentityMapping(array $device, int $client_id, int $asset_id,
    string $state, string $strategy, float $confidence, ?string $last_error = null): array
{
    $device_id = levelLimitText($device['id'] ?? '', 255);
    $group_id = levelLimitText($device['group_id'] ?? '', 255);
    $hostname = levelLimitText($device['hostname'] ?? '', 255);
    // Mapping freshness means the source inventory observed the durable id,
    // not that the agent was recently active. Agent activity remains in the
    // normalized snapshot and endpoint facts.
    $last_seen_at = date('Y-m-d H:i:s');
    $facts = levelIdentitySnapshot($device);

    return integrationIdentityUpsertMapping([
        'source' => 'level',
        'entity_type' => 'device',
        'external_id' => $device_id,
        'external_parent_id' => $group_id,
        'external_name' => $hostname,
        'client_id' => $client_id,
        'asset_id' => $asset_id,
        'state' => $state,
        'strategy' => $strategy,
        'confidence' => $confidence,
        'last_seen_at' => $last_seen_at,
        'last_error' => $last_error,
        'metadata' => [
            'online' => $facts['online'],
            'group_id' => $group_id,
        ],
    ]);
}

function levelRecordDeviceIdentitySnapshot(array $device, array $mapping): array
{
    $device_id = levelLimitText($device['id'] ?? '', 255);
    $facts = levelIdentitySnapshot($device);
    return integrationIdentityRecordSnapshot([
        'source' => 'level',
        'entity_type' => 'device',
        'external_id' => $device_id,
        'client_id' => intval($mapping['automation_mapping_client_id'] ?? 0),
        'asset_id' => intval($mapping['automation_mapping_asset_id'] ?? 0),
        'facts' => $facts,
    ]);
}

function levelLockDeviceBindingUnlocked(
    string $device_id,
    int $preview_link_asset_id,
    int $preview_mapping_asset_id
): array {
    global $mysqli;

    $asset_ids = array_values(array_unique(array_filter([
        $preview_link_asset_id,
        $preview_mapping_asset_id,
    ], static fn ($asset_id) => $asset_id > 0)));
    sort($asset_ids, SORT_NUMERIC);
    $locked_assets = [];
    foreach ($asset_ids as $asset_id) {
        $locked_assets[$asset_id] = endpointAssetTenantRow($asset_id, 0, true);
    }

    $device_id_sql = levelDbEscape($device_id);
    $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM level_asset_links
        WHERE level_device_id = '$device_id_sql' LIMIT 1 FOR UPDATE"));
    $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
        WHERE automation_mapping_source = 'level'
        AND automation_mapping_entity_type = 'device'
        AND automation_mapping_external_id = '$device_id_sql' LIMIT 1 FOR UPDATE"));
    $link_asset_id = intval($link['level_asset_id'] ?? 0);
    $mapping_asset_id = intval($mapping['automation_mapping_asset_id'] ?? 0);
    if ($link_asset_id !== $preview_link_asset_id || $mapping_asset_id !== $preview_mapping_asset_id) {
        throw new RuntimeException('Level device binding changed during locked resolution; retry synchronization');
    }

    $conflict = null;
    if ($link_asset_id > 0 && $mapping_asset_id > 0 && $link_asset_id !== $mapping_asset_id) {
        $conflict = 'Level asset link and identity mapping resolve to different ITFlow assets';
    }
    $asset_id = $link_asset_id ?: $mapping_asset_id;
    $asset_client_id = intval($locked_assets[$asset_id]['asset_client_id'] ?? 0);
    if ($asset_id > 0 && !empty($locked_assets[$asset_id]['asset_archived_at'])) {
        $conflict = 'Level identity resolves to an archived ITFlow asset';
    }
    if ($mapping && $asset_id > 0
        && (intval($mapping['automation_mapping_asset_id']) !== $asset_id
            || intval($mapping['automation_mapping_client_id']) !== $asset_client_id)) {
        $conflict = 'Level identity mapping does not exactly match the locked asset tenant binding';
    }

    return [
        'link' => $link ?: null,
        'mapping' => $mapping ?: null,
        'asset_id' => $asset_id,
        'asset_client_id' => $asset_client_id,
        'conflict' => $conflict,
    ];
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

    if (!levelAcquireDeviceLock($device_id)) {
        throw new RuntimeException('Could not obtain the Level device synchronization lock');
    }

    try {
        mysqli_begin_transaction($mysqli);
        try {
            $client_id = levelResolveClientForGroupLocked($group_id);
            $device_id_sql = levelDbEscape($device_id);
            $link_preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id, level_device_sync_status
                FROM level_asset_links WHERE level_device_id = '$device_id_sql' LIMIT 1"));
            $identity_mapping_preview = integrationIdentityFindMapping('level', 'device', $device_id);
            $preview_link_asset_id = intval($link_preview['level_asset_id'] ?? 0);
            $preview_mapping_asset_id = intval($identity_mapping_preview['automation_mapping_asset_id'] ?? 0);

            if ($client_id === 0) {
                // Quarantine an unmapped group without projecting its payload onto
                // the previously linked tenant's asset, interfaces, or snapshots.
                $binding = levelLockDeviceBindingUnlocked(
                    $device_id,
                    $preview_link_asset_id,
                    $preview_mapping_asset_id
                );
                if ($binding['conflict'] !== null) {
                    throw new RuntimeException((string) $binding['conflict']);
                }
                $link = $binding['link'];
                $identity_asset_id = intval($binding['asset_id']);
                if ($link) {
                    if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                        level_device_sync_status = 'Unmapped',
                        level_device_sync_message = 'The Level group has no ITFlow client mapping',
                        level_device_last_synced_at = NOW()
                        WHERE level_device_id = '$device_id_sql'")) {
                        throw new RuntimeException('Could not mark the Level device group as unmapped');
                    }
                }

                $unmapped_asset_id = intval($link['level_asset_id'] ?? 0) ?: $identity_asset_id;
                $mapping = integrationIdentityUpsertMapping([
                    'source' => 'level',
                    'entity_type' => 'device',
                    'external_id' => $device_id,
                    'external_parent_id' => $group_id,
                    'external_name' => $hostname,
                    'client_id' => 0,
                    'asset_id' => $unmapped_asset_id,
                    'state' => $unmapped_asset_id > 0 ? 'conflicting' : 'unresolved',
                    'strategy' => 'level_group_unmapped',
                    'confidence' => 0,
                    'last_seen_at' => date('Y-m-d H:i:s'),
                    'last_error' => 'The Level group has no ITFlow client mapping',
                    'metadata' => [
                        'reported_group_id' => $group_id,
                        'quarantined' => true,
                    ],
                ]);
                $identity_asset_id = intval($mapping['automation_mapping_asset_id'] ?? 0);
                $identity_client_id = intval($mapping['automation_mapping_client_id'] ?? 0);
                if ($identity_asset_id > 0 && $identity_client_id > 0) {
                    if (!endpointRetireIdentityBindingUnlocked([
                        'asset_id' => $identity_asset_id,
                        'client_id' => $identity_client_id,
                        'source' => 'level',
                        'external_id' => $device_id,
                        'occurred_at' => date('Y-m-d H:i:s'),
                        'reason' => 'The Level group has no ITFlow client mapping',
                    ])) {
                        throw new RuntimeException('Level quarantine stopped: endpoint source binding diverges');
                    }
                }
                mysqli_commit($mysqli);

                return [
                    'result' => 'skipped',
                    'reason' => 'unmapped_group',
                    'device_id' => $device_id,
                    'asset_id' => $unmapped_asset_id,
                ];
            }

        $name = levelLimitText(($device['nickname'] ?? '') ?: $hostname, 200);
        $type = levelDeviceAssetType($device);
        $make = levelLimitText($device['manufacturer'] ?? '', 200);
        $model = levelLimitText($device['model'] ?? '', 200);
        $serial = levelLimitText($device['serial_number'] ?? '', 200);
        $os = levelDeviceOperatingSystem($device);

        $asset_id = $preview_link_asset_id ?: $preview_mapping_asset_id;
        $restored_from_identity = !$link_preview && $preview_mapping_asset_id > 0;
        $result = $link_preview ? 'updated' : ($restored_from_identity ? 'linked' : 'created');
        $sync_status = 'Synced';
        $sync_message = null;

            $binding = levelLockDeviceBindingUnlocked(
                $device_id,
                $preview_link_asset_id,
                $preview_mapping_asset_id
            );
            if ($binding['conflict'] !== null) {
                throw new RuntimeException((string) $binding['conflict']);
            }
            $link = $binding['link'];
            $identity_mapping = $binding['mapping'];
            $asset_id = intval($binding['asset_id']);
            $restored_from_identity = !$link && $identity_mapping && $asset_id > 0;
            $result = $link ? 'updated' : ($restored_from_identity ? 'linked' : 'created');

            if ($identity_mapping
                && !in_array((string) $identity_mapping['automation_mapping_state'], ['automatic', 'confirmed'], true)) {
                $binding_error = 'Level identity binding is not trusted for automatic fact persistence';
                if ($asset_id > 0 && intval($binding['asset_client_id']) > 0) {
                    if (!endpointRetireIdentityBindingUnlocked([
                        'asset_id' => $asset_id,
                        'client_id' => intval($binding['asset_client_id']),
                        'source' => 'level',
                        'external_id' => $device_id,
                        'occurred_at' => date('Y-m-d H:i:s'),
                        'reason' => $binding_error,
                    ])) {
                        throw new RuntimeException('Level quarantine stopped: endpoint source binding diverges');
                    }
                }
                if ($link) {
                    $binding_error_sql = levelDbEscape($binding_error);
                    if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                        level_device_sync_status = 'Conflict',
                        level_device_sync_message = '$binding_error_sql',
                        level_device_last_synced_at = NOW()
                        WHERE level_device_id = '$device_id_sql'")) {
                        throw new RuntimeException('Could not quarantine the untrusted Level identity binding');
                    }
                }
                mysqli_commit($mysqli);
                return [
                    'result' => 'conflict',
                    'asset_id' => $asset_id,
                    'device_id' => $device_id,
                    'interfaces' => ['total' => 0, 'created' => 0, 'updated' => 0, 'archived' => 0],
                ];
            }

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

                $serial_candidates = [];
                while ($serial_candidate = mysqli_fetch_assoc($match_sql)) {
                    $serial_candidates[] = intval($serial_candidate['asset_id']);
                }
                if (count($serial_candidates) === 1) {
                    $asset_id = $serial_candidates[0];
                    $result = 'linked';
                } elseif (count($serial_candidates) > 1) {
                    $ambiguity = 'Multiple active ITFlow assets have this Level serial; technician review is required';
                    $mapping = integrationIdentityUpsertMapping([
                        'source' => 'level',
                        'entity_type' => 'device',
                        'external_id' => $device_id,
                        'external_parent_id' => $group_id,
                        'external_name' => $hostname,
                        'client_id' => $client_id,
                        'asset_id' => 0,
                        'state' => 'conflicting',
                        'strategy' => 'ambiguous_tenant_serial',
                        'confidence' => 0,
                        'last_seen_at' => date('Y-m-d H:i:s'),
                        'last_error' => $ambiguity,
                        'metadata' => [
                            'serial' => $serial,
                            'candidate_asset_ids' => $serial_candidates,
                        ],
                    ]);
                    levelRecordDeviceIdentitySnapshot($device, $mapping);
                    mysqli_commit($mysqli);
                    return [
                        'result' => 'conflict',
                        'reason' => 'ambiguous_serial',
                        'asset_id' => 0,
                        'device_id' => $device_id,
                        'interfaces' => ['total' => 0, 'created' => 0, 'updated' => 0, 'archived' => 0],
                    ];
                }
            }

            $name_sql = levelDbEscape($name);
            $type_sql = levelDbEscape($type);
            $make_sql = levelDbEscape($make);
            $model_sql = levelDbEscape($model);
            $serial_sql = levelDbEscape($serial);
            $os_sql = levelDbEscape($os);
            $created_asset = false;

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
                $created_asset = true;
            } else {
                $current_asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_client_id, asset_status
                    FROM assets WHERE asset_id = $asset_id FOR UPDATE"));
                if (!$current_asset) {
                    throw new RuntimeException('The ITFlow asset linked to this Level device no longer exists');
                }

                $old_client_id = intval($current_asset['asset_client_id']);
                $client_assignment_sql = ", asset_client_id = $client_id";
                if ($old_client_id !== $client_id) {
                    // Quarantine before any asset, interface, or snapshot writer. A
                    // group/client disagreement must never project the new tenant's
                    // payload onto the asset that is still owned by the old tenant.
                    $sync_message = 'Level group maps to a different ITFlow client; transfer the asset manually';
                    integrationIdentityUpsertMapping([
                        'source' => 'level',
                        'entity_type' => 'device',
                        'external_id' => $device_id,
                        'external_parent_id' => $group_id,
                        'external_name' => $hostname,
                        'client_id' => $old_client_id,
                        'asset_id' => $asset_id,
                        'state' => 'conflicting',
                        'strategy' => 'level_client_mismatch_quarantine',
                        'confidence' => 0,
                        'last_seen_at' => date('Y-m-d H:i:s'),
                        'last_error' => $sync_message,
                        'metadata' => [
                            'reported_client_id' => $client_id,
                            'reported_group_id' => $group_id,
                            'quarantined' => true,
                        ],
                    ]);
                    if (!endpointRetireIdentityBindingUnlocked([
                        'asset_id' => $asset_id,
                        'client_id' => $old_client_id,
                        'source' => 'level',
                        'external_id' => $device_id,
                        'occurred_at' => date('Y-m-d H:i:s'),
                        'reason' => $sync_message,
                    ])) {
                        throw new RuntimeException('Level quarantine stopped: endpoint source binding diverges');
                    }

                    if ($link) {
                        $sync_message_sql = levelDbEscape($sync_message);
                        if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                            level_device_sync_status = 'Conflict',
                            level_device_sync_message = '$sync_message_sql',
                            level_device_last_synced_at = NOW()
                            WHERE level_device_id = '$device_id_sql'")) {
                            throw new RuntimeException('Could not quarantine the conflicting Level device link');
                        }
                    }

                    mysqli_commit($mysqli);
                    return [
                        'result' => 'conflict',
                        'asset_id' => $asset_id,
                        'device_id' => $device_id,
                        'interfaces' => ['total' => 0, 'created' => 0, 'updated' => 0, 'archived' => 0],
                    ];
                }

            }

            $identity_strategy = match ($result) {
                'created' => 'level_created_asset',
                'linked' => $restored_from_identity ? 'existing_identity_mapping' : 'tenant_serial_match',
                default => 'existing_level_link',
            };
            $identity_confidence = $result === 'linked' && !$restored_from_identity ? 95 : 100;
            $mapping = levelUpsertDeviceIdentityMapping(
                $device,
                $client_id,
                $asset_id,
                'automatic',
                $identity_strategy,
                $identity_confidence
            );
            $mapping_binding_is_exact = intval($mapping['automation_mapping_asset_id'] ?? 0) === $asset_id
                && intval($mapping['automation_mapping_client_id'] ?? 0) === $client_id;
            $mapping_state = (string) ($mapping['automation_mapping_state'] ?? '');
            if ($mapping_binding_is_exact && $mapping_state === 'conflicting') {
                // A second Level durable id may race or predate the serial
                // adoption path without having a level_asset_links row. Keep
                // the conflict mapping, snapshot, and decision audit instead
                // of throwing and rolling all three back out of the queue.
                levelRecordDeviceIdentitySnapshot($device, $mapping);
                if ($link) {
                    $mapping_error_sql = levelDbEscape(
                        $mapping['automation_mapping_last_error']
                            ?? 'Another active Level identity already maps to this asset'
                    );
                    if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                        level_device_sync_status = 'Conflict',
                        level_device_sync_message = '$mapping_error_sql',
                        level_device_last_synced_at = NOW()
                        WHERE level_device_id = '$device_id_sql'")) {
                        throw new RuntimeException('Could not quarantine the duplicate Level identity link');
                    }
                }
                mysqli_commit($mysqli);
                return [
                    'result' => 'conflict',
                    'reason' => 'duplicate_source_identity',
                    'asset_id' => $asset_id,
                    'device_id' => $device_id,
                    'interfaces' => ['total' => 0, 'created' => 0, 'updated' => 0, 'archived' => 0],
                ];
            }
            if (!$mapping_binding_is_exact
                || !in_array($mapping_state, ['automatic', 'confirmed'], true)) {
                throw new RuntimeException('Level identity did not resolve to the exact trusted asset binding');
            }

            if (!$created_asset && !mysqli_query($mysqli, "UPDATE assets SET
                asset_name = '$name_sql',
                asset_type = '$type_sql',
                asset_make = '$make_sql',
                asset_model = '$model_sql',
                asset_serial = '$serial_sql',
                asset_os = '$os_sql'
                $client_assignment_sql
                WHERE asset_id = $asset_id AND asset_client_id = $client_id")) {
                throw new RuntimeException('Could not update the ITFlow asset');
            }
            if ($created_asset) {
                mysqli_query($mysqli, "INSERT INTO asset_history SET asset_history_status = 'Deployed',
                    asset_history_description = 'Level.io created $name_sql', asset_history_asset_id = $asset_id");
            }
            levelRecordDeviceIdentitySnapshot($device, $mapping);

            $group_sql = levelNullableSql($group_id === '' ? null : $group_id);
            $hostname_sql = levelDbEscape($hostname);
            $online = !empty($device['online']) ? 1 : 0;
            $last_seen_sql = levelNullableSql(levelDateTimeValue($device['last_seen_at'] ?? null));
            $security_score_value = $device['security_score'] ?? ($device['security']['score'] ?? null);
            $security_score_sql = is_numeric($security_score_value) ? intval($security_score_value) : 'NULL';
            $snapshot = json_encode(levelIdentitySnapshot($device), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $snapshot_sql = levelDbEscape($snapshot === false ? '{}' : $snapshot);

            $interface_summary = levelReconcileAssetInterfaces(
                $asset_id,
                $device_id,
                $device
            );
            $endpoint_observations = $interface_summary['_endpoint_observations'] ?? [];
            unset($interface_summary['_endpoint_observations']);
            $endpoint_delivery = endpointReconcileAssetSourceUnlocked([
                'asset_id' => $asset_id,
                'client_id' => $client_id,
                'source' => 'level',
                'external_id' => $device_id,
                'status' => 'active',
                'observed_at' => date('Y-m-d H:i:s'),
                'facts' => levelEndpointFacts($device),
                'network_interfaces' => $endpoint_observations,
            ]);
            $interface_summary['observations'] = $endpoint_delivery['network'];
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

        return [
            'result' => $result,
            'asset_id' => $asset_id,
            'device_id' => $device_id,
            'interfaces' => $interface_summary,
        ];
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

function levelMarkDeviceDeleted($device_id, ?string $last_synced_before = null): bool
{
    global $mysqli;

    $device_id = levelLimitText($device_id, 255);
    if ($device_id === '') {
        return false;
    }

    if (!levelAcquireDeviceLock($device_id)) {
        throw new RuntimeException('Could not obtain the Level device deletion lock');
    }

    try {
        $device_id_sql = levelDbEscape($device_id);
        $cutoff_sql = $last_synced_before === null
            ? ''
            : " AND level_device_last_synced_at < '"
                . levelDbEscape(levelDateTimeValue($last_synced_before) ?? $last_synced_before) . "'";
        mysqli_begin_transaction($mysqli);
        try {
            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_link_id, level_asset_id,
                level_device_hostname, level_device_deleted_at, asset_client_id
                FROM level_asset_links
                INNER JOIN assets ON asset_id = level_asset_id
                WHERE level_device_id = '$device_id_sql'$cutoff_sql LIMIT 1 FOR UPDATE"));
            if (!$row) {
                mysqli_commit($mysqli);
                return false;
            }

            $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_id,
                automation_mapping_asset_id, automation_mapping_client_id
                FROM automation_entity_mappings
                WHERE automation_mapping_source = 'level'
                AND automation_mapping_entity_type = 'device'
                AND automation_mapping_external_id = '$device_id_sql'
                LIMIT 1 FOR UPDATE"));
            if ($mapping
                && (intval($mapping['automation_mapping_asset_id']) !== intval($row['level_asset_id'])
                    || intval($mapping['automation_mapping_client_id']) !== intval($row['asset_client_id']))) {
                throw new RuntimeException(
                    'Level retirement stopped: identity mapping diverges from the locked asset link'
                );
            }

            if (!mysqli_query($mysqli, "UPDATE level_asset_links SET level_device_online = 0,
                level_device_deleted_at = COALESCE(level_device_deleted_at, NOW()),
                level_device_last_synced_at = NOW()
                WHERE level_asset_link_id = " . intval($row['level_asset_link_id']))) {
                throw new RuntimeException('Could not retire the Level device link');
            }
            levelArchiveDeviceInterfaces($device_id);

            $asset_id = intval($row['level_asset_id']);
            $client_id = intval($row['asset_client_id']);
            $newly_deleted = empty($row['level_device_deleted_at']);
            if ($newly_deleted) {
                $hostname = levelDbEscape(levelLimitText($row['level_device_hostname'], 150));
                if (!mysqli_query($mysqli, "INSERT INTO asset_history
                    SELECT NULL, asset_status, 'Level.io removed $hostname from management', NOW(), asset_id
                    FROM assets WHERE asset_id = $asset_id")) {
                    throw new RuntimeException('Could not record the Level device lifecycle change');
                }
            }

            $mapping_retired = integrationIdentityRetireMapping(
                'level',
                'device',
                $device_id,
                'Level.io removed the device from management'
            );
            if (!$mapping_retired) {
                $endpoint_retired = endpointRetireIdentityBindingUnlocked([
                    'asset_id' => $asset_id,
                    'client_id' => $client_id,
                    'source' => 'level',
                    'external_id' => $device_id,
                    'occurred_at' => date('Y-m-d H:i:s'),
                    'reason' => 'Level.io removed the device from management',
                ]);
                if (!$endpoint_retired) {
                    throw new RuntimeException(
                        'Level retirement stopped: endpoint source binding diverges from the locked asset link'
                    );
                }
            }
            mysqli_commit($mysqli);
            return $newly_deleted;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    } finally {
        levelReleaseDeviceLock($device_id);
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

function levelRecordOperationalAlert(array $alert, string $state, array $result,
    ?string $event_time = null, ?string $source_event_id = null): void
{
    global $mysqli;

    try {
        $alert_id = levelLimitText($alert['id'] ?? '', 255);
        if ($alert_id === '') {
            return;
        }

        $state = strtolower($state) === 'resolved' ? 'resolved' : 'open';
        $alert_id_sql = levelDbEscape($alert_id);
        $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id, level_ticket_id
            FROM level_alert_links WHERE level_alert_id = '$alert_id_sql' LIMIT 1"));
        $asset_id = intval($link['level_asset_id'] ?? 0);
        $ticket_id = intval($result['ticket_id'] ?? ($link['level_ticket_id'] ?? 0));
        $client_id = 0;
        $location_id = 0;
        $device_id = levelLimitText($alert['device_id'] ?? '', 255);
        if ($asset_id === 0 && $device_id !== '') {
            $device_id_sql = levelDbEscape($device_id);
            $asset_link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id
                FROM level_asset_links WHERE level_device_id = '$device_id_sql'
                AND level_device_deleted_at IS NULL LIMIT 1"));
            $asset_id = intval($asset_link['level_asset_id'] ?? 0);
        }
        if ($asset_id > 0) {
            $asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_client_id, asset_location_id
                FROM assets WHERE asset_id = $asset_id LIMIT 1"));
            $client_id = intval($asset['asset_client_id'] ?? 0);
            $location_id = intval($asset['asset_location_id'] ?? 0);
        } elseif ($ticket_id > 0) {
            $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_asset_id, ticket_client_id,
                ticket_location_id FROM tickets WHERE ticket_id = $ticket_id
                AND ticket_deleted_at IS NULL LIMIT 1"));
            $asset_id = intval($ticket['ticket_asset_id'] ?? 0);
            $client_id = intval($ticket['ticket_client_id'] ?? 0);
            $location_id = intval($ticket['ticket_location_id'] ?? 0);
        }

        $reason = (string) ($result['reason'] ?? '');
        $action = match ((string) ($result['result'] ?? '')) {
            'created' => 'created',
            'existing' => 'unchanged',
            'stale' => 'stale',
            'updated' => $state === 'resolved' ? 'recovery_recorded' : 'updated',
            'recorded' => $state === 'resolved' ? 'recovery_without_open_incident' : 'recorded_no_ticket',
            'skipped' => match ($reason) {
                'maintenance_window' => 'maintenance_suppressed',
                'ticket_threshold' => 'threshold_waiting',
                'event_policy_disabled', 'ticket_creation_disabled' => 'source_disabled',
                default => 'mapping_unresolved',
            },
            default => 'recorded',
        };
        $incident_status = $state === 'resolved' ? 'Resolved' : match ($action) {
            'maintenance_suppressed', 'source_disabled' => 'Suppressed',
            'threshold_waiting' => 'Pending',
            default => 'Open',
        };

        $marker = trim((string) $source_event_id);
        if ($marker === '') {
            $reconcile_phase = match ($action) {
                'created', 'unchanged', 'updated' => 'ticketed',
                'resolved', 'recovery_recorded', 'recovery_without_open_incident' => 'resolved',
                default => $action,
            };
            $reconcile_document = integrationIdentityNormalizeSnapshot([
                'state' => $state,
                'phase' => $reconcile_phase,
                'occurred_at' => $event_time
                    ?? ($state === 'resolved' ? ($alert['resolved_at'] ?? null) : ($alert['started_at'] ?? null)),
                'alert' => [
                    'id' => $alert_id,
                    'device_id' => $alert['device_id'] ?? '',
                    'device_hostname' => $alert['device_hostname'] ?? '',
                    'name' => $alert['name'] ?? '',
                    'severity' => $alert['severity'] ?? '',
                    'description' => $alert['description'] ?? '',
                    'payload' => $alert['payload'] ?? '',
                    'started_at' => $alert['started_at'] ?? null,
                    'resolved_at' => $alert['resolved_at'] ?? null,
                    'is_resolved' => !empty($alert['is_resolved']),
                ],
            ]);
            $reconcile_payload = json_encode(
                $reconcile_document,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $marker = 'reconcile-' . substr(hash('sha256', $reconcile_payload ?: $alert_id), 0, 32);
        }
        $external_event_id = levelLimitText('level-alert-' . $marker, 255);

        $severity = strtolower(levelLimitText($alert['severity'] ?? 'low', 20));
        $severity = match ($severity) {
            'information' => 'low',
            'warning' => 'medium',
            default => $severity,
        };
        $alert_name = levelLimitText($alert['name'] ?? 'Level.io alert', 255);
        $hostname = levelLimitText($alert['device_hostname'] ?? '', 255);

        automationMirrorProcessedEvent([
            'source' => 'level',
            'event_id' => $external_event_id,
            'incident_key' => 'alert:' . $alert_id,
            'state' => $state,
            'title' => $alert_name . ($hostname !== '' ? ' - ' . $hostname : ''),
            'severity' => $severity,
            'description' => levelAlertString($alert['description'] ?? ''),
            'occurred_at' => $event_time
                ?? ($state === 'resolved' ? ($alert['resolved_at'] ?? null) : ($alert['started_at'] ?? null)),
            'identity' => [
                'source' => 'level',
                'entity_type' => 'device',
                'external_id' => $device_id !== '' ? $device_id : $alert_id,
                'external_name' => $hostname !== '' ? $hostname : $alert_name,
            ],
            'metadata' => [
                'level_alert_id' => $alert_id,
                'level_device_id' => $device_id,
            ],
        ], [
            'strategy' => 'level_alert_link',
            'client_id' => $client_id,
            'location_id' => $location_id,
            'asset_id' => $asset_id,
            'service_id' => 0,
            'ticket_id' => $ticket_id,
            'maintenance_window_id' => intval($result['maintenance_window_id'] ?? 0),
        ], $action, $incident_status);
    } catch (Throwable $e) {
        logApp('Level.io', 'error', 'Could not mirror Level alert into Operations: ' . $e->getMessage());
        throw $e;
    }
}

function levelHandleAlertActive(array $alert, ?string $event_time = null,
    bool $device_already_synced = false, ?string $automation_event_id = null): array
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

    $event_policy = automationEventPolicy('level');
    if (!$event_policy['enabled'] || !$event_policy['ticket_enabled']) {
        levelUpsertAlertLink($alert, null, $asset_id, $event_time);
        return ['result' => 'skipped', 'reason' => 'event_policy_disabled'];
    }

    $maintenance = automationEventActiveMaintenanceWindow(
        'level',
        $client_id,
        $asset_id,
        0,
        $event_time ?? ($alert['started_at'] ?? null)
    );
    if ($maintenance) {
        levelUpsertAlertLink($alert, null, $asset_id, $event_time);
        return [
            'result' => 'skipped',
            'reason' => 'maintenance_window',
            'maintenance_window_id' => intval($maintenance['automation_maintenance_id']),
        ];
    }

    $threshold_count = intval($event_policy['threshold_count']);
    if ($threshold_count > 1) {
        $incident_key = 'alert:' . $alert_id;
        $occurrences = automationEventThresholdOccurrences(
            'level',
            $incident_key,
            intval($event_policy['threshold_window_minutes'])
        );
        if ($automation_event_id !== null && $automation_event_id !== '') {
            $occurrences++;
        } elseif ($occurrences === 0) {
            $occurrences = 1;
        }
        if ($occurrences < $threshold_count) {
            levelUpsertAlertLink($alert, null, $asset_id, $event_time);
            return ['result' => 'skipped', 'reason' => 'ticket_threshold'];
        }
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
        [$impact, $urgency] = ticketOperationalLegacyDimensionsForPriority($priority);
        $impact_sql = levelDbEscape($impact);
        $urgency_sql = levelDbEscape($urgency);
        $prefix_sql = levelDbEscape(levelLimitText($settings['ticket_prefix'], 200));
        $assigned_to = intval($settings['alert_assigned_to']);
        $billable = intval($settings['ticket_default_billable']);
        $location_id = intval($asset['asset_location_id'] ?? 0);

        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the Level alert ticket transaction');
        }
        try {
            if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id)) {
                throw new RuntimeException('The Level alert client is no longer available');
            }
            $number_update = mysqli_query($mysqli, "UPDATE settings SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1");
            if (!$number_update || mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('Could not allocate a ticket number for the Level alert');
            }
            $ticket_number = intval(mysqli_insert_id($mysqli));
            if (!$ticket_number) {
                throw new RuntimeException('The Level alert ticket number allocation returned no number');
            }
            $url_key = levelDbEscape(randomString(32));

            $insert = mysqli_query($mysqli, "INSERT INTO tickets SET
                ticket_prefix = '$prefix_sql',
                ticket_number = $ticket_number,
                ticket_source = 'Level.io',
                ticket_subject = '$subject_sql',
                ticket_details = '$details_sql',
                ticket_priority = '$priority_sql',
                ticket_work_type = 'incident',
                ticket_impact = '$impact_sql',
                ticket_urgency = '$urgency_sql',
                ticket_next_action = 'Investigate the Level.io alert and confirm service health.',
                ticket_waiting_on = 'none',
                ticket_operational_updated_by = 0,
                ticket_operational_updated_at = NOW(),
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
            $ticket_id = intval(mysqli_insert_id($mysqli));
            if (!$ticket_id) {
                throw new RuntimeException('The Level alert ticket did not receive an ID');
            }

            if (!levelUpsertAlertLink($alert, $ticket_id, $asset_id, $event_time)) {
                throw new RuntimeException('Could not save the Level alert-to-ticket link');
            }
            applyTicketSla($ticket_id, null, null, true);

            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the Level alert ticket and SLA decision');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }

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

    $result = match ($definition['resource']) {
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
                : levelHandleAlertActive($data, $event_time, false, (string) ($event['event_id'] ?? '')),
        default => throw new InvalidArgumentException('Unsupported Level webhook resource'),
    };

    if ($definition['resource'] === 'alert') {
        levelRecordOperationalAlert(
            $data,
            ($definition['action'] === 'resolved' || !empty($data['is_resolved']) || !empty($data['resolved_at']))
                ? 'resolved' : 'open',
            $result,
            $event_time,
            (string) ($event['event_id'] ?? '')
        );
    }

    return $result;
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

    if (!n45FeatureEnabled('level')) {
        throw new RuntimeException('Level integration is disabled by deployment feature flag');
    }

    $summary = [
        'groups' => levelDiscoverGroups(),
        'devices_created' => 0,
        'devices_linked' => 0,
        'devices_updated' => 0,
        'devices_skipped' => 0,
        'devices_retired' => 0,
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
    $missing_sql = mysqli_query($mysqli, "SELECT level_device_id FROM level_asset_links
        WHERE level_device_last_synced_at < '$device_sync_started_sql'
        AND level_device_deleted_at IS NULL");
    if (!$missing_sql) {
        throw new RuntimeException('Could not identify Level devices missing from reconciliation');
    }
    $missing_device_ids = [];
    while ($missing = mysqli_fetch_assoc($missing_sql)) {
        $missing_device_ids[] = (string) $missing['level_device_id'];
    }
    foreach ($missing_device_ids as $missing_device_id) {
        if (levelMarkDeviceDeleted($missing_device_id, $device_sync_started)) {
            $summary['devices_retired']++;
        }
    }

    $settings = levelGetSettings();
    if ($settings['alert_ticket_enabled'] && $settings['ticketing_enabled']) {
        $alerts = levelListAll('/v2/alerts', ['status' => 'active']);
        $active_alert_ids = [];
        foreach ($alerts as $alert) {
            if (!empty($alert['id'])) {
                $active_alert_ids[(string) $alert['id']] = true;
            }
            $result = levelHandleAlertActive($alert, null, true);
            levelRecordOperationalAlert($alert, 'open', $result, null, null);
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
                    $resolved_result = levelHandleAlertResolved($response['data'], $response['data']['resolved_at'] ?? null);
                    levelRecordOperationalAlert(
                        $response['data'],
                        'resolved',
                        $resolved_result,
                        $response['data']['resolved_at'] ?? null,
                        null
                    );
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

    if (!n45FeatureEnabled('level')) {
        return false;
    }

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
