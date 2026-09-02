<?php

class EndpointConflictException extends RuntimeException
{
}

// Canonical endpoint posture, network observations, and asset change history.

function endpointLimitText($value, int $length): string
{
    if (is_array($value) || is_object($value) || is_resource($value)) {
        return '';
    }
    return mb_substr(trim((string) $value), 0, $length);
}

function endpointPositiveInt($value): int
{
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_float($value) && is_finite($value) && floor($value) === $value) {
        return max(0, intval($value));
    }
    if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
        return max(0, intval($value));
    }
    return 0;
}

/**
 * Collapse interface and connection query results into one row per interface.
 *
 * Connection joins are intentionally kept out of the base interface query. An
 * interface can have more than one historical or topology link, and joining
 * those edges directly makes the asset view repeat the interface row.
 */
function endpointGroupAssetInterfaceRows(array $interface_rows, array $connection_rows): array
{
    $interfaces = [];
    $connection_ids = [];

    foreach ($interface_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $interface_id = endpointPositiveInt($row['interface_id'] ?? 0);
        if ($interface_id < 1 || isset($interfaces[$interface_id])) {
            continue;
        }
        $row['connections'] = [];
        $interfaces[$interface_id] = $row;
        $connection_ids[$interface_id] = [];
    }

    foreach ($connection_rows as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        $interface_id = endpointPositiveInt($connection['interface_id'] ?? 0);
        $connected_interface_id = endpointPositiveInt($connection['connected_interface_id'] ?? 0);
        if (!isset($interfaces[$interface_id]) || $connected_interface_id < 1
            || isset($connection_ids[$interface_id][$connected_interface_id])) {
            continue;
        }
        $interfaces[$interface_id]['connections'][] = $connection;
        $connection_ids[$interface_id][$connected_interface_id] = true;
    }

    return array_values($interfaces);
}

function endpointAssetInterfaceRows(int $asset_id): array
{
    global $mysqli;

    $asset_id = max(0, $asset_id);
    if ($asset_id < 1) {
        return [];
    }

    $interface_rows = [];
    $sql = mysqli_query($mysqli, "SELECT
        ai.interface_id,
        ai.interface_name,
        ai.interface_description,
        ai.interface_type,
        ai.interface_mac,
        ai.interface_ip,
        ai.interface_nat_ip,
        ai.interface_ipv6,
        ai.interface_primary,
        ai.interface_notes,
        n.network_name,
        n.network_id
        FROM asset_interfaces ai
        LEFT JOIN networks n ON n.network_id = ai.interface_network_id
        WHERE ai.interface_asset_id = $asset_id
        AND ai.interface_archived_at IS NULL
        ORDER BY ai.interface_name ASC, ai.interface_id ASC");
    if (!$sql) {
        throw new RuntimeException('Could not load asset interfaces');
    }
    while ($row = mysqli_fetch_assoc($sql)) {
        $interface_rows[] = $row;
    }
    if (!$interface_rows) {
        return [];
    }

    $interface_ids = array_values(array_unique(array_map(
        static fn ($row) => endpointPositiveInt($row['interface_id'] ?? 0),
        $interface_rows
    )));
    $interface_ids = array_values(array_filter($interface_ids, static fn ($id) => $id > 0));
    if (!$interface_ids) {
        return endpointGroupAssetInterfaceRows($interface_rows, []);
    }
    $interface_ids_sql = implode(',', $interface_ids);

    $connection_rows = [];
    $connection_sql = mysqli_query($mysqli, "SELECT connection_rows.* FROM (
        SELECT ail.interface_link_id,
            ail.interface_a_id AS interface_id,
            connected_interfaces.interface_id AS connected_interface_id,
            connected_interfaces.interface_name AS connected_interface_name,
            connected_assets.asset_name AS connected_asset_name,
            connected_assets.asset_id AS connected_asset_id,
            connected_assets.asset_type AS connected_asset_type
        FROM asset_interface_links ail
        INNER JOIN asset_interfaces connected_interfaces
            ON connected_interfaces.interface_id = ail.interface_b_id
        INNER JOIN assets connected_assets
            ON connected_assets.asset_id = connected_interfaces.interface_asset_id
        WHERE ail.interface_a_id IN ($interface_ids_sql)
        UNION ALL
        SELECT ail.interface_link_id,
            ail.interface_b_id AS interface_id,
            connected_interfaces.interface_id AS connected_interface_id,
            connected_interfaces.interface_name AS connected_interface_name,
            connected_assets.asset_name AS connected_asset_name,
            connected_assets.asset_id AS connected_asset_id,
            connected_assets.asset_type AS connected_asset_type
        FROM asset_interface_links ail
        INNER JOIN asset_interfaces connected_interfaces
            ON connected_interfaces.interface_id = ail.interface_a_id
        INNER JOIN assets connected_assets
            ON connected_assets.asset_id = connected_interfaces.interface_asset_id
        WHERE ail.interface_b_id IN ($interface_ids_sql)
    ) connection_rows
    ORDER BY connection_rows.interface_id ASC,
        connection_rows.connected_asset_name ASC,
        connection_rows.connected_interface_name ASC,
        connection_rows.interface_link_id ASC");
    if (!$connection_sql) {
        throw new RuntimeException('Could not load asset interface connections');
    }
    while ($connection = mysqli_fetch_assoc($connection_sql)) {
        $connection_rows[] = $connection;
    }

    return endpointGroupAssetInterfaceRows($interface_rows, $connection_rows);
}

function endpointSource($value): string
{
    if (is_array($value) || is_object($value) || is_resource($value)) {
        throw new InvalidArgumentException('Endpoint source is invalid');
    }
    if (function_exists('integrationIdentitySource')) {
        return integrationIdentitySource($value);
    }

    $source = strtolower(endpointLimitText($value, 40));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $source)) {
        throw new InvalidArgumentException('Endpoint source is invalid');
    }
    return $source;
}

function endpointExternalId($value): string
{
    $external_id = endpointLimitText($value, 255);
    if ($external_id === '') {
        throw new InvalidArgumentException('Endpoint external id is required');
    }
    return $external_id;
}

function endpointDateTime($value, bool $default_now = true): ?string
{
    if ($value === null) {
        return $default_now ? date('Y-m-d H:i:s') : null;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Endpoint timestamp is invalid');
    }
    if (endpointLimitText($value, 255) === '') {
        return $default_now ? date('Y-m-d H:i:s') : null;
    }

    try {
        $date = new DateTimeImmutable((string) $value);
        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Endpoint timestamp is invalid');
    }

    return $date->format('Y-m-d H:i:s');
}

function endpointObservationDateTime($value): string
{
    $observed_at = endpointDateTime($value);
    if (strtotime($observed_at) > time() + 300) {
        throw new InvalidArgumentException('Endpoint observation timestamp is in the future');
    }
    return $observed_at;
}

function endpointOptionalObservationDateTime($value): ?string
{
    $observed_at = endpointDateTime($value, false);
    if ($observed_at !== null && strtotime($observed_at) > time() + 300) {
        throw new InvalidArgumentException('Endpoint last-seen timestamp is in the future');
    }
    return $observed_at;
}

function endpointNormalizeChoice($value, array $allowed, string $default = 'unknown'): string
{
    $choice = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', endpointLimitText($value, 255)));
    $choice = trim($choice, '_');
    return in_array($choice, $allowed, true) ? $choice : $default;
}

function endpointNormalizeHealth($value, $online = null): string
{
    if ($value === null || endpointLimitText($value, 100) === '') {
        if ($online === true || $online === 1 || $online === '1') {
            return 'healthy';
        }
        if ($online === false || $online === 0 || $online === '0') {
            return 'offline';
        }
    }

    $aliases = [
        'ok' => 'healthy',
        'good' => 'healthy',
        'online' => 'healthy',
        'warning' => 'warning',
        'degraded' => 'warning',
        'at_risk' => 'warning',
        'error' => 'critical',
        'failed' => 'critical',
        'infected' => 'critical',
        'disconnected' => 'offline',
        'missing' => 'not_installed',
        'notinstalled' => 'not_installed',
    ];
    $normalized = endpointNormalizeChoice($value, [
        'healthy', 'warning', 'critical', 'offline', 'not_installed', 'unmanaged', 'unknown',
    ]);
    $raw = endpointNormalizeChoice($value, array_keys($aliases), '');
    return $raw !== '' ? $aliases[$raw] : $normalized;
}

function endpointNormalizeCompliance($value, $is_compliant = null): string
{
    if ($is_compliant === true || $is_compliant === 1 || $is_compliant === '1') {
        return 'compliant';
    }
    if ($is_compliant === false || $is_compliant === 0 || $is_compliant === '0') {
        return 'noncompliant';
    }

    $choice = endpointNormalizeChoice($value, [
        'compliant', 'noncompliant', 'non_compliant', 'grace_period', 'not_applicable', 'unknown',
    ]);
    return $choice === 'non_compliant' ? 'noncompliant' : $choice;
}

function endpointNormalizeEncryption($value, $is_encrypted = null): string
{
    if ($is_encrypted === true || $is_encrypted === 1 || $is_encrypted === '1') {
        return 'encrypted';
    }
    if ($is_encrypted === false || $is_encrypted === 0 || $is_encrypted === '0') {
        return 'unencrypted';
    }
    return endpointNormalizeChoice($value, [
        'encrypted', 'unencrypted', 'partial', 'not_applicable', 'unknown',
    ]);
}

function endpointNormalizeSecureBoot($value, $is_enabled = null): string
{
    if ($is_enabled === true || $is_enabled === 1 || $is_enabled === '1') {
        return 'enabled';
    }
    if ($is_enabled === false || $is_enabled === 0 || $is_enabled === '0') {
        return 'disabled';
    }
    return endpointNormalizeChoice($value, ['enabled', 'disabled', 'unsupported', 'unknown']);
}

function endpointNormalizeLifecycle($value): string
{
    return endpointNormalizeChoice($value, [
        'provisioning', 'active', 'deployed', 'maintenance', 'spare', 'retired',
        'lost', 'disposed', 'unknown',
    ]);
}

function endpointNormalizeSourceStatus($value): string
{
    if ($value === null || endpointLimitText($value, 100) === '') {
        return 'active';
    }
    $status = endpointNormalizeChoice($value, [
        'active', 'stale', 'conflicting', 'retired', 'unmanaged', 'unknown',
    ], '');
    if ($status === '') {
        throw new InvalidArgumentException('Endpoint source status is invalid');
    }
    return $status;
}

function endpointSourceSafetyRank(string $status): int
{
    return match ($status) {
        'retired' => 60,
        'conflicting' => 50,
        'unmanaged' => 40,
        'unknown' => 30,
        'stale' => 20,
        'active' => 10,
        default => 0,
    };
}

function endpointDeliveryTuple(
    string $status,
    string $posture_hash,
    string $network_hash,
    string $external_id
): array {
    return [
        endpointSourceSafetyRank($status),
        strtolower($posture_hash),
        strtolower($network_hash),
        $external_id,
    ];
}

function endpointCompareDeliveryTuples(array $left, array $right): int
{
    $rank_comparison = intval($left[0] ?? 0) <=> intval($right[0] ?? 0);
    if ($rank_comparison !== 0) {
        return $rank_comparison;
    }
    foreach ([1, 2, 3] as $index) {
        $comparison = strcmp((string) ($left[$index] ?? ''), (string) ($right[$index] ?? ''));
        if ($comparison !== 0) {
            return $comparison;
        }
    }
    return 0;
}

function endpointSelectDeliveryCandidate(?array $current, array $candidate): array
{
    if ($current === null) {
        return $candidate;
    }
    $watermark_comparison = strcmp(
        (string) ($candidate['observed_at'] ?? ''),
        (string) ($current['observed_at'] ?? '')
    );
    if ($watermark_comparison > 0) {
        return $candidate;
    }
    if ($watermark_comparison < 0) {
        return $current;
    }
    return endpointCompareDeliveryTuples(
        (array) ($candidate['tuple'] ?? []),
        (array) ($current['tuple'] ?? [])
    ) > 0 ? $candidate : $current;
}

function endpointDeliveryKey(string $observed_at, array $tuple): string
{
    $payload = json_encode(
        [$observed_at, array_values($tuple)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($payload === false) {
        throw new RuntimeException('Could not fingerprint the endpoint delivery');
    }
    return hash('sha256', $payload);
}

function endpointStateBaselineFromRow(?array $state): array
{
    if (!$state) {
        return [
            'exists' => false,
            'facts' => [],
            'payload_hash' => '',
            'status' => '',
            'external_id' => '',
        ];
    }
    $facts = json_decode((string) ($state['endpoint_state_payload'] ?? ''), true);
    return [
        'exists' => true,
        'facts' => is_array($facts) ? $facts : [],
        'payload_hash' => (string) ($state['endpoint_state_payload_hash'] ?? ''),
        'status' => (string) ($state['endpoint_state_status'] ?? ''),
        'external_id' => (string) ($state['endpoint_state_external_id'] ?? ''),
    ];
}

function endpointEncodeDeliveryBaseline(array $baseline): string
{
    $payload = json_encode(
        [
            'exists' => !empty($baseline['exists']),
            'facts' => is_array($baseline['facts'] ?? null) ? $baseline['facts'] : [],
            'payload_hash' => endpointLimitText($baseline['payload_hash'] ?? '', 64),
            'status' => endpointLimitText($baseline['status'] ?? '', 20),
            'external_id' => endpointLimitText($baseline['external_id'] ?? '', 255),
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($payload === false) {
        throw new RuntimeException('Could not encode the endpoint delivery baseline');
    }
    return $payload;
}

function endpointDecodeDeliveryBaseline($payload, ?array $fallback_state = null): array
{
    $decoded = json_decode((string) $payload, true);
    if (!is_array($decoded) || !array_key_exists('exists', $decoded)) {
        return endpointStateBaselineFromRow($fallback_state);
    }
    return [
        'exists' => !empty($decoded['exists']),
        'facts' => is_array($decoded['facts'] ?? null) ? $decoded['facts'] : [],
        'payload_hash' => endpointLimitText($decoded['payload_hash'] ?? '', 64),
        'status' => endpointLimitText($decoded['status'] ?? '', 20),
        'external_id' => endpointLimitText($decoded['external_id'] ?? '', 255),
    ];
}

function endpointNormalizeMac($value): string
{
    $compact = strtolower((string) preg_replace('/[^0-9a-f]/i', '', endpointLimitText($value, 100)));
    if (strlen($compact) !== 12
        || $compact === str_repeat('0', 12)
        || $compact === str_repeat('f', 12)) {
        return '';
    }
    return implode(':', str_split($compact, 2));
}

function endpointNormalizeIp($value): string
{
    if (!is_string($value)) {
        return '';
    }
    $address = trim($value, " \t\n\r\0\x0B[]");
    if (str_contains($address, '/')) {
        $address = explode('/', $address, 2)[0];
    }
    if (str_contains($address, '%')) {
        $address = explode('%', $address, 2)[0];
    }
    $packed = @inet_pton($address);
    return $packed === false ? '' : strtolower((string) inet_ntop($packed));
}

function endpointNormalizeIpList($value, int $family): array
{
    if (!is_array($value)) {
        $value = $value === null || $value === '' ? [] : [$value];
    }

    $addresses = [];
    foreach ($value as $candidate) {
        $address = endpointNormalizeIp($candidate);
        if ($address === '') {
            continue;
        }
        $is_ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (($family === 4 && !$is_ipv4) || ($family === 6 && $is_ipv4)) {
            continue;
        }
        $addresses[$address] = true;
    }
    $addresses = array_keys($addresses);
    sort($addresses, SORT_STRING);
    return $addresses;
}

function endpointNormalizeAssignedUser(array $facts): array
{
    $user = is_array($facts['assigned_user'] ?? null) ? $facts['assigned_user'] : [];
    return [
        'external_id' => endpointLimitText(
            $user['external_id'] ?? $user['id'] ?? $facts['assigned_user_external_id'] ?? '',
            255
        ),
        'name' => endpointLimitText($user['name'] ?? $facts['assigned_user_name'] ?? '', 255),
        'email' => strtolower(endpointLimitText(
            $user['email'] ?? $user['user_principal_name'] ?? $facts['assigned_user_email'] ?? '',
            320
        )),
    ];
}

/**
 * Normalize only the facts the unified endpoint record is designed to retain.
 * Unknown source fields never enter the canonical record, which prevents a
 * vendor payload expansion from persisting credentials or unrelated PII.
 */
function endpointNormalizeSourceFacts(array $facts): array
{
    $assigned_user = endpointNormalizeAssignedUser($facts);
    $online = $facts['online'] ?? null;
    $details = [];
    $nested_details = is_array($facts['details'] ?? null) ? $facts['details'] : [];
    $detail_keys = [
        'agent_version', 'antivirus_state', 'edr_state', 'firewall_state',
        'management_state', 'platform', 'role', 'security_score', 'threat_count',
    ];
    foreach ($detail_keys as $key) {
        if (array_key_exists($key, $facts)) {
            $detail_value = $facts[$key];
        } elseif (array_key_exists($key, $nested_details)) {
            $detail_value = $nested_details[$key];
        } else {
            continue;
        }
        if (is_array($detail_value) || is_object($detail_value)) {
            continue;
        }
        $details[$key] = is_bool($detail_value) || is_numeric($detail_value)
            ? $detail_value
            : endpointLimitText($detail_value, 255);
    }
    ksort($details, SORT_STRING);

    $is_compliant = $facts['is_compliant'] ?? null;
    $is_encrypted = $facts['is_encrypted'] ?? $facts['encrypted'] ?? null;
    $secure_boot_enabled = $facts['secure_boot_enabled'] ?? null;

    return [
        'assigned_user_external_id' => $assigned_user['external_id'],
        'assigned_user_name' => $assigned_user['name'],
        'assigned_user_email' => $assigned_user['email'],
        'entra_device_id' => endpointLimitText(
            $facts['entra_device_id'] ?? $facts['azure_ad_device_id'] ?? '',
            255
        ),
        'intune_device_id' => endpointLimitText($facts['intune_device_id'] ?? '', 255),
        'health_state' => endpointNormalizeHealth(
            $facts['health_state'] ?? $facts['health'] ?? null,
            $online
        ),
        'compliance_state' => endpointNormalizeCompliance(
            $facts['compliance_state'] ?? $facts['compliance'] ?? null,
            $is_compliant
        ),
        'encryption_state' => endpointNormalizeEncryption(
            $facts['encryption_state'] ?? null,
            $is_encrypted
        ),
        'secure_boot_state' => endpointNormalizeSecureBoot(
            $facts['secure_boot_state'] ?? null,
            $secure_boot_enabled
        ),
        'os_name' => endpointLimitText(
            $facts['os_name'] ?? $facts['operating_system'] ?? $facts['platform'] ?? '',
            200
        ),
        'os_version' => endpointLimitText($facts['os_version'] ?? $facts['version'] ?? '', 100),
        'os_build' => endpointLimitText($facts['os_build'] ?? $facts['build'] ?? '', 100),
        'agent_version' => endpointLimitText($facts['agent_version'] ?? '', 100),
        'lifecycle_state' => endpointNormalizeLifecycle(
            $facts['lifecycle_state'] ?? $facts['lifecycle'] ?? null
        ),
        'last_seen_at' => endpointOptionalObservationDateTime(
            $facts['last_seen_at'] ?? $facts['last_check_in_at'] ?? null
        ),
        'details' => $details,
    ];
}

function endpointSourceStateDocument(string $source, string $external_id, array $facts): array
{
    $source = endpointSource($source);
    $external_id = endpointExternalId($external_id);
    $normalized = endpointNormalizeSourceFacts($facts);
    $material = $normalized;
    unset($material['last_seen_at']);
    $document = function_exists('integrationIdentitySnapshotDocument')
        ? integrationIdentitySnapshotDocument($material)
        : null;
    if ($document === null) {
        ksort($material, SORT_STRING);
        $payload = json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Could not encode endpoint state');
        }
        $document = ['payload' => $payload, 'hash' => hash('sha256', $payload)];
    }

    return [
        'source' => $source,
        'external_id' => $external_id,
        'facts' => $normalized,
        'payload' => $document['payload'],
        'hash' => $document['hash'],
    ];
}

function endpointSourceStateChangedFields(array $before, array $after): array
{
    $labels = [
        'assigned_user_external_id' => 'assigned user',
        'assigned_user_name' => 'assigned user',
        'assigned_user_email' => 'assigned user',
        'entra_device_id' => 'Entra identity',
        'intune_device_id' => 'Intune identity',
        'health_state' => 'health',
        'compliance_state' => 'compliance',
        'encryption_state' => 'encryption',
        'secure_boot_state' => 'Secure Boot',
        'os_name' => 'operating system',
        'os_version' => 'OS version',
        'os_build' => 'OS build',
        'agent_version' => 'agent version',
        'lifecycle_state' => 'lifecycle',
        'source_status' => 'source status',
        'details' => 'security details',
    ];
    $changed = [];
    foreach ($labels as $key => $label) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            $changed[$label] = true;
        }
    }
    return array_keys($changed);
}

function endpointNormalizeNetworkObservation(array $input): array
{
    $key = endpointLimitText(
        $input['key'] ?? $input['interface_key'] ?? $input['interface_name'] ?? $input['name'] ?? '',
        255
    );
    if ($key === '') {
        throw new InvalidArgumentException('Network observation key is required');
    }

    $all_addresses = $input['ip_addresses'] ?? [];
    $ipv4 = endpointNormalizeIpList($input['ipv4_addresses'] ?? $input['ipv4'] ?? $input['ip'] ?? [], 4);
    $ipv6 = endpointNormalizeIpList($input['ipv6_addresses'] ?? $input['ipv6'] ?? [], 6);
    if (is_array($all_addresses)) {
        $ipv4 = array_values(array_unique(array_merge($ipv4, endpointNormalizeIpList($all_addresses, 4))));
        $ipv6 = array_values(array_unique(array_merge($ipv6, endpointNormalizeIpList($all_addresses, 6))));
        sort($ipv4, SORT_STRING);
        sort($ipv6, SORT_STRING);
    }

    $type = endpointLimitText($input['type'] ?? $input['interface_type'] ?? '', 50);
    $virtual = array_key_exists('virtual', $input)
        ? in_array($input['virtual'], [true, 1, '1', 'true', 'yes'], true)
        : strcasecmp($type, 'Virtual') === 0;
    $vlan_id = endpointPositiveInt($input['vlan_id'] ?? 0);
    if ($vlan_id < 1 || $vlan_id > 4094) {
        $vlan_id = null;
    }

    $protocol = endpointNormalizeChoice(
        $input['neighbor_protocol'] ?? $input['discovery_protocol'] ?? $input['protocol'] ?? null,
        ['lldp', 'cdp', 'manual', 'rmm', 'unknown']
    );

    $state = [
        'key' => $key,
        'interface_id' => endpointPositiveInt($input['interface_id'] ?? 0),
        'interface_name' => endpointLimitText($input['name'] ?? $input['interface_name'] ?? $key, 200),
        'interface_type' => $type,
        'virtual' => $virtual,
        'mac' => endpointNormalizeMac($input['mac'] ?? $input['mac_address'] ?? ''),
        'ipv4' => $ipv4,
        'ipv6' => $ipv6,
        'network_id' => endpointPositiveInt($input['network_id'] ?? 0),
        'vlan_id' => $vlan_id,
        'vlan_name' => endpointLimitText($input['vlan_name'] ?? '', 100),
        'neighbor_protocol' => $protocol,
        'neighbor_asset_id' => endpointPositiveInt(
            $input['neighbor_asset_id'] ?? $input['switch_asset_id'] ?? 0
        ),
        'neighbor_interface_id' => endpointPositiveInt(
            $input['neighbor_interface_id'] ?? $input['switch_interface_id'] ?? 0
        ),
        'neighbor_name' => endpointLimitText($input['neighbor_name'] ?? $input['switch_name'] ?? '', 255),
        'neighbor_chassis_id' => endpointLimitText(
            $input['neighbor_chassis_id'] ?? $input['chassis_id'] ?? '',
            255
        ),
        'neighbor_port' => endpointLimitText(
            $input['neighbor_port'] ?? $input['switch_port'] ?? $input['port_id'] ?? '',
            255
        ),
    ];
    $payload = json_encode(
        $state,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($payload === false) {
        throw new RuntimeException('Could not encode network observation');
    }

    $identity_hash = hash('sha256', $key);
    return [
        'state' => $state,
        'payload' => $payload,
        'identity_hash' => $identity_hash,
        'state_hash' => hash('sha256', $payload),
    ];
}

function endpointValidateNetworkObservationBounds(array $observations): void
{
    if (count($observations) > 128) {
        throw new InvalidArgumentException('Endpoint reconciliation exceeds the 128-interface limit');
    }

    $total_addresses = 0;
    foreach ($observations as $observation) {
        if (!is_array($observation)) {
            throw new InvalidArgumentException('Every endpoint network interface must be a JSON object');
        }
        $interface_addresses = 0;
        foreach (['ip_addresses', 'ipv4_addresses', 'ipv4', 'ip', 'ipv6_addresses', 'ipv6'] as $key) {
            if (!array_key_exists($key, $observation) || $observation[$key] === null || $observation[$key] === '') {
                continue;
            }
            $interface_addresses += is_array($observation[$key]) ? count($observation[$key]) : 1;
        }
        if ($interface_addresses > 64) {
            throw new InvalidArgumentException('An endpoint interface exceeds the 64-address limit');
        }
        $total_addresses += $interface_addresses;
    }
    if ($total_addresses > 2048) {
        throw new InvalidArgumentException('Endpoint reconciliation exceeds the 2048-address limit');
    }
}

function endpointNetworkSnapshotHash(array $observations): string
{
    $material = [];
    foreach ($observations as $observation) {
        $material[(string) $observation['identity_hash']] = (string) $observation['state_hash'];
    }
    ksort($material, SORT_STRING);
    $payload = json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Could not fingerprint the endpoint network snapshot');
    }
    return hash('sha256', $payload);
}

function endpointDbEscape($value): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $value);
}

function endpointNullableSql($value): string
{
    return $value === null || $value === ''
        ? 'NULL'
        : "'" . endpointDbEscape($value) . "'";
}

/**
 * Remove a provisional equal-second delivery from every visible projection.
 * Rows remain as durable supersession evidence, while the pre-timestamp
 * topology and its input-derived last-seen values are restored before the
 * higher total-order tuple is projected.
 */
function endpointSupersedeDeliveryUnlocked(
    int $asset_id,
    int $client_id,
    string $source,
    string $delivery_key,
    string $observed_at
): void {
    global $mysqli;

    if (!n45DatabaseTransactionActive()) {
        throw new LogicException('Endpoint delivery supersession requires a transaction');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $delivery_key)) {
        throw new LogicException('Endpoint delivery supersession key is invalid');
    }
    $source_sql = endpointDbEscape($source);
    $delivery_key_sql = endpointDbEscape($delivery_key);
    $observed_at_sql = endpointDbEscape($observed_at);

    if (!mysqli_query($mysqli, "UPDATE asset_change_events SET
        asset_change_event_canonical = 0,
        asset_change_event_superseded_at = COALESCE(asset_change_event_superseded_at, NOW())
        WHERE asset_change_event_asset_id = $asset_id
        AND asset_change_event_client_id = $client_id
        AND asset_change_event_source = '$source_sql'
        AND asset_change_event_delivery_key = '$delivery_key_sql'
        AND asset_change_event_canonical = 1")) {
        throw new RuntimeException('Could not supersede provisional endpoint events');
    }

    if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
        network_observation_last_seen_at = COALESCE(
            network_observation_previous_last_seen_at,
            network_observation_last_seen_at
        ),
        network_observation_last_seen_delivery_key = NULL,
        network_observation_previous_last_seen_at = NULL
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_source = '$source_sql'
        AND network_observation_last_seen_delivery_key = '$delivery_key_sql'
        AND network_observation_canonical = 1")) {
        throw new RuntimeException('Could not restore endpoint network last-seen history');
    }

    if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
        network_observation_active = 1,
        network_observation_ended_at = NULL,
        network_observation_closed_delivery_key = NULL
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_source = '$source_sql'
        AND network_observation_closed_delivery_key = '$delivery_key_sql'
        AND network_observation_canonical = 1")) {
        throw new RuntimeException('Could not restore the pre-delivery endpoint topology');
    }

    if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
        network_observation_active = 0,
        network_observation_ended_at = COALESCE(network_observation_ended_at, '$observed_at_sql'),
        network_observation_canonical = 0,
        network_observation_superseded_at = COALESCE(network_observation_superseded_at, NOW())
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_source = '$source_sql'
        AND network_observation_created_delivery_key = '$delivery_key_sql'
        AND network_observation_canonical = 1")) {
        throw new RuntimeException('Could not supersede provisional endpoint topology');
    }
}

function endpointAssetTenantRow(int $asset_id, int $client_id = 0, bool $for_update = false): array
{
    global $mysqli;

    if ($asset_id < 1) {
        throw new InvalidArgumentException('Endpoint asset is required');
    }
    $client_sql = $client_id > 0 ? " AND asset_client_id = $client_id" : '';
    $lock_sql = $for_update ? ' FOR UPDATE' : '';
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_id, asset_client_id,
        asset_contact_id, asset_os, asset_status, asset_warranty_expire, asset_archived_at
        FROM assets WHERE asset_id = $asset_id$client_sql LIMIT 1$lock_sql"));
    if (!$row) {
        throw new InvalidArgumentException('Endpoint asset does not exist for this client');
    }
    return $row;
}

function endpointValidateChangeReferences(
    int $asset_id,
    int $client_id,
    int $ticket_id,
    int $document_id,
    int $evidence_id
): array {
    global $mysqli;

    $labels = ['ticket' => '', 'document' => '', 'evidence' => ''];
    if ($ticket_id < 1 && $document_id < 1 && $evidence_id < 1) {
        return $labels;
    }
    if (!n45DatabaseTransactionActive()) {
        throw new LogicException('Endpoint event references require a transaction');
    }

    if ($ticket_id > 0) {
        $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_asset_id,
            ticket_prefix, ticket_number, ticket_subject FROM tickets
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
            LIMIT 1 FOR UPDATE"));
        if (!$ticket) {
            throw new EndpointConflictException('Endpoint change ticket is not in this client');
        }
        if (intval($ticket['ticket_asset_id']) !== $asset_id) {
            $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM ticket_assets
                WHERE ticket_id = $ticket_id AND asset_id = $asset_id LIMIT 1 FOR UPDATE"));
            if (!$link) {
                throw new EndpointConflictException('Endpoint change ticket is not linked to this asset');
            }
        }
        $labels['ticket'] = endpointLimitText(
            trim((string) $ticket['ticket_prefix']) . intval($ticket['ticket_number'])
                . ' — ' . (string) $ticket['ticket_subject'],
            500
        );
    }
    if ($document_id > 0) {
        $document = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_id, document_name
            FROM documents WHERE document_id = $document_id AND document_client_id = $client_id
            LIMIT 1 FOR UPDATE"));
        if (!$document) {
            throw new EndpointConflictException('Endpoint change document is not in this client');
        }
        $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_id FROM asset_documents
            WHERE document_id = $document_id AND asset_id = $asset_id LIMIT 1 FOR UPDATE"));
        if (!$link) {
            throw new EndpointConflictException('Endpoint change document is not linked to this asset');
        }
        $labels['document'] = endpointLimitText($document['document_name'], 500);
    }
    if ($evidence_id > 0) {
        $evidence = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_evidence_id,
            task_evidence_task_id, task_evidence_type, task_evidence_note
            FROM task_evidence WHERE task_evidence_id = $evidence_id LIMIT 1 FOR UPDATE"));
        if (!$evidence) {
            throw new EndpointConflictException('Endpoint change evidence no longer exists');
        }
        $task_id = intval($evidence['task_evidence_task_id']);
        $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_id, task_name, task_ticket_id
            FROM tasks WHERE task_id = $task_id LIMIT 1 FOR UPDATE"));
        $evidence_ticket_id = intval($task['task_ticket_id'] ?? 0);
        $ticket = $evidence_ticket_id > 0
            ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_asset_id
                FROM tickets WHERE ticket_id = $evidence_ticket_id
                AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE"))
            : null;
        if (!$task || !$ticket) {
            throw new EndpointConflictException('Endpoint change evidence is not in this client');
        }
        if (intval($ticket['ticket_asset_id']) !== $asset_id) {
            $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM ticket_assets
                WHERE ticket_id = $evidence_ticket_id AND asset_id = $asset_id
                LIMIT 1 FOR UPDATE"));
            if (!$link) {
                throw new EndpointConflictException('Endpoint change evidence is not linked to this asset');
            }
        }
        $labels['evidence'] = endpointLimitText(
            (string) $task['task_name'] . ' — ' . (string) $evidence['task_evidence_type']
                . (!empty($evidence['task_evidence_note']) ? ': ' . (string) $evidence['task_evidence_note'] : ''),
            500
        );
    }
    return $labels;
}

function endpointAssertIdentityMapping(
    int $asset_id,
    int $client_id,
    string $source,
    string $external_id
): array {
    global $mysqli;

    if ($source === 'itflow') {
        return [];
    }
    $source_sql = endpointDbEscape($source);
    $external_id_sql = endpointDbEscape($external_id);
    $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_asset_id,
        automation_mapping_client_id, automation_mapping_state
        FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = 'device'
        AND automation_mapping_external_id = '$external_id_sql'
        LIMIT 1 FOR UPDATE"));
    if (!$mapping) {
        throw new InvalidArgumentException('Endpoint source identity must be mapped before posture is recorded');
    }
    if (intval($mapping['automation_mapping_asset_id']) !== $asset_id
        || intval($mapping['automation_mapping_client_id']) !== $client_id) {
        throw new RuntimeException('Endpoint source identity belongs to a different asset or client');
    }
    return $mapping;
}

function endpointRecordChangeEventUnlocked(array $input): array
{
    global $mysqli;

    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    endpointAssetTenantRow($asset_id, $client_id, false);
    $source = endpointSource($input['source'] ?? 'itflow');
    $event_type = endpointNormalizeChoice($input['event_type'] ?? '', [
        'identity', 'posture', 'network', 'lifecycle', 'relationship', 'evidence', 'documentation',
    ], 'posture');
    $summary = endpointLimitText($input['summary'] ?? 'Endpoint record changed', 500);
    $external_key = endpointLimitText($input['external_key'] ?? '', 255);
    $occurred_at = endpointDateTime($input['occurred_at'] ?? null);
    $delivery_key = strtolower(endpointLimitText($input['delivery_key'] ?? '', 64));
    if ($delivery_key !== '' && !preg_match('/^[a-f0-9]{64}$/', $delivery_key)) {
        throw new LogicException('Endpoint event delivery key is invalid');
    }
    $before_document = integrationIdentitySnapshotDocument($input['before'] ?? []);
    $after_document = integrationIdentitySnapshotDocument($input['after'] ?? []);
    $ticket_id = endpointPositiveInt($input['ticket_id'] ?? 0);
    $document_id = endpointPositiveInt($input['document_id'] ?? 0);
    $evidence_id = endpointPositiveInt($input['evidence_id'] ?? 0);
    $reference_labels = endpointValidateChangeReferences(
        $asset_id,
        $client_id,
        $ticket_id,
        $document_id,
        $evidence_id
    );
    $fingerprint = hash('sha256', implode("\0", [
        $asset_id,
        $source,
        $event_type,
        $external_key,
        $before_document['hash'],
        $after_document['hash'],
        $occurred_at,
        $ticket_id,
        $document_id,
        $evidence_id,
    ]));

    $source_sql = endpointDbEscape($source);
    $event_type_sql = endpointDbEscape($event_type);
    $summary_sql = endpointDbEscape($summary);
    $external_key_sql = endpointNullableSql($external_key);
    $before_sql = endpointDbEscape($before_document['payload']);
    $after_sql = endpointDbEscape($after_document['payload']);
    $fingerprint_sql = endpointDbEscape($fingerprint);
    $delivery_key_sql = endpointDbEscape($delivery_key);
    $occurred_at_sql = endpointDbEscape($occurred_at);
    $ticket_label_sql = endpointNullableSql($reference_labels['ticket']);
    $document_label_sql = endpointNullableSql($reference_labels['document']);
    $evidence_label_sql = endpointNullableSql($reference_labels['evidence']);

    if (!mysqli_query($mysqli, "INSERT INTO asset_change_events SET
        asset_change_event_asset_id = $asset_id,
        asset_change_event_client_id = $client_id,
        asset_change_event_source = '$source_sql',
        asset_change_event_type = '$event_type_sql',
        asset_change_event_external_key = $external_key_sql,
        asset_change_event_summary = '$summary_sql',
        asset_change_event_before = '$before_sql',
        asset_change_event_after = '$after_sql',
        asset_change_event_fingerprint = '$fingerprint_sql',
        asset_change_event_delivery_key = '$delivery_key_sql',
        asset_change_event_canonical = 1,
        asset_change_event_superseded_at = NULL,
        asset_change_event_occurred_at = '$occurred_at_sql',
        asset_change_event_ticket_id = $ticket_id,
        asset_change_event_ticket_label = $ticket_label_sql,
        asset_change_event_document_id = $document_id,
        asset_change_event_document_label = $document_label_sql,
        asset_change_event_evidence_id = $evidence_id,
        asset_change_event_evidence_label = $evidence_label_sql
        ON DUPLICATE KEY UPDATE
        asset_change_event_id = LAST_INSERT_ID(asset_change_event_id),
        asset_change_event_delivery_key = VALUES(asset_change_event_delivery_key),
        asset_change_event_canonical = 1,
        asset_change_event_superseded_at = NULL,
        asset_change_event_ticket_label = COALESCE(
            asset_change_event_ticket_label, VALUES(asset_change_event_ticket_label)
        ),
        asset_change_event_document_label = COALESCE(
            asset_change_event_document_label, VALUES(asset_change_event_document_label)
        ),
        asset_change_event_evidence_label = COALESCE(
            asset_change_event_evidence_label, VALUES(asset_change_event_evidence_label)
        )")) {
        throw new RuntimeException('Could not record the endpoint change event: ' . mysqli_error($mysqli));
    }

    return ['event_id' => intval(mysqli_insert_id($mysqli)), 'fingerprint' => $fingerprint];
}

function endpointRecordSourceStateUnlocked(array $input): array
{
    global $mysqli;

    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    $asset = endpointAssetTenantRow($asset_id, $client_id, true);
    if (!empty($asset['asset_archived_at'])) {
        throw new EndpointConflictException('Endpoint observations cannot be published to an archived asset');
    }
    $client_id = intval($asset['asset_client_id']);
    $source = endpointSource($input['source'] ?? '');
    $external_id = endpointExternalId($input['external_id'] ?? '');
    $source_status = endpointNormalizeSourceStatus($input['status'] ?? 'active');
    $effective_network_hash = strtolower(endpointLimitText($input['effective_network_hash'] ?? '', 64));
    if (!preg_match('/^[a-f0-9]{64}$/', $effective_network_hash)) {
        throw new LogicException('Endpoint posture requires a prepared effective network hash');
    }
    $identity_mapping = endpointAssertIdentityMapping($asset_id, $client_id, $source, $external_id);
    $identity_state = (string) ($identity_mapping['automation_mapping_state'] ?? '');
    if (in_array($identity_state, ['ignored', 'retired'], true) && $source_status !== 'retired') {
        throw new RuntimeException('An ignored or retired endpoint identity cannot publish active posture');
    }
    if ($identity_state === 'conflicting' && $source_status !== 'conflicting') {
        throw new RuntimeException('A conflicting endpoint identity cannot publish active posture');
    }
    if ($identity_state === 'stale' && !in_array($source_status, ['stale', 'retired'], true)) {
        throw new RuntimeException('A stale endpoint identity cannot publish active posture');
    }
    if (in_array($identity_state, ['unresolved', 'suggested'], true)
        && !in_array($source_status, ['unmanaged', 'unknown'], true)) {
        throw new RuntimeException('An unconfirmed endpoint identity cannot publish active posture');
    }
    $raw_facts = is_array($input['facts'] ?? null) ? $input['facts'] : [];
    if ($source === 'entra' && empty($raw_facts['entra_device_id'])) {
        $raw_facts['entra_device_id'] = $external_id;
    }
    if ($source === 'intune' && empty($raw_facts['intune_device_id'])) {
        $raw_facts['intune_device_id'] = $external_id;
    }
    if ($source_status === 'retired') {
        $raw_facts['health_state'] = 'unmanaged';
        $raw_facts['lifecycle_state'] = 'retired';
    }
    $document = endpointSourceStateDocument($source, $external_id, $raw_facts);
    $facts = $document['facts'];
    $observed_at = endpointObservationDateTime($input['observed_at'] ?? null);

    $source_sql = endpointDbEscape($source);
    $external_id_sql = endpointDbEscape($external_id);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM asset_endpoint_states
        WHERE endpoint_state_asset_id = $asset_id
        AND endpoint_state_source = '$source_sql' LIMIT 1 FOR UPDATE"));
    $rebound = false;
    if ($existing && (string) $existing['endpoint_state_external_id'] !== $external_id) {
        $old_external_id = (string) $existing['endpoint_state_external_id'];
        $old_external_id_sql = endpointDbEscape($old_external_id);
        $old_mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_state
            FROM automation_entity_mappings
            WHERE automation_mapping_source = '$source_sql'
            AND automation_mapping_entity_type = 'device'
            AND automation_mapping_external_id = '$old_external_id_sql'
            LIMIT 1 FOR UPDATE"));
        if ((string) $existing['endpoint_state_status'] !== 'retired'
            || (string) ($old_mapping['automation_mapping_state'] ?? '') !== 'retired'
            || !in_array($identity_state, ['automatic', 'confirmed'], true)
            || !in_array($source_status, ['active', 'stale'], true)) {
            throw new EndpointConflictException('Endpoint source identity rebind requires a retired prior identity and a trusted replacement');
        }
        $rebound = true;
    }
    $foreign = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT endpoint_state_asset_id,
        endpoint_state_client_id FROM asset_endpoint_states
        WHERE endpoint_state_source = '$source_sql'
        AND endpoint_state_external_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
    if ($foreign && (intval($foreign['endpoint_state_asset_id']) !== $asset_id
        || intval($foreign['endpoint_state_client_id']) !== $client_id)) {
        throw new EndpointConflictException('Endpoint source state belongs to a different asset or client');
    }

    $existing_status = (string) ($existing['endpoint_state_status'] ?? '');
    $existing_network_hash = (string) ($existing['endpoint_state_network_hash'] ?? '');
    if ($existing && !in_array($existing_status, ['active', 'stale'], true)) {
        // Inactive posture never owns topology, including legacy rows that
        // predate the explicit network watermark column.
        $existing_network_hash = endpointNetworkSnapshotHash([]);
    } elseif ($existing && $existing_network_hash === '') {
        $existing_network_hash = endpointCurrentNetworkHashUnlocked($asset_id, $client_id, $source);
    }
    $incoming_tuple = endpointDeliveryTuple(
        $source_status,
        $document['hash'],
        $effective_network_hash,
        $external_id
    );
    $incoming_delivery_key = endpointDeliveryKey($observed_at, $incoming_tuple);
    $delivery_baseline = endpointStateBaselineFromRow($existing);
    $comparison_baseline = $delivery_baseline;
    $superseded_delivery_key = '';
    if ($existing && !empty($existing['endpoint_state_observed_at'])) {
        $watermark_comparison = strcmp((string) $existing['endpoint_state_observed_at'], $observed_at);
        $existing_tuple = endpointDeliveryTuple(
            (string) $existing['endpoint_state_status'],
            (string) $existing['endpoint_state_payload_hash'],
            $existing_network_hash,
            (string) $existing['endpoint_state_external_id']
        );
        $tuple_comparison = $watermark_comparison === 0
            ? endpointCompareDeliveryTuples($incoming_tuple, $existing_tuple)
            : 0;
        $tie_lost = $watermark_comparison === 0 && $tuple_comparison < 0;
        if ($watermark_comparison > 0 || $tie_lost) {
            $last_seen_sql = endpointNullableSql($facts['last_seen_at']);
            if (!mysqli_query($mysqli, "UPDATE asset_endpoint_states SET
                endpoint_state_last_seen_at = CASE
                    WHEN $last_seen_sql IS NULL THEN endpoint_state_last_seen_at
                    WHEN endpoint_state_last_seen_at IS NULL
                        OR endpoint_state_last_seen_at < $last_seen_sql
                    THEN $last_seen_sql ELSE endpoint_state_last_seen_at END
                WHERE endpoint_state_id = " . intval($existing['endpoint_state_id']))) {
                throw new RuntimeException('Could not advance the losing endpoint delivery last-seen value');
            }
            return [
                'state_id' => intval($existing['endpoint_state_id']),
                'changed' => false,
                'stale' => true,
                'tie_lost' => $tie_lost,
                'delivery_won' => false,
                'hash' => (string) $existing['endpoint_state_payload_hash'],
                'network_hash' => $existing_network_hash,
            ];
        }

        if ($watermark_comparison === 0) {
            // Replays compare against the projected winner. A strictly higher
            // equal-second tuple instead compares against the durable state
            // from before that timestamp and supersedes the lower projection.
            $delivery_baseline = endpointDecodeDeliveryBaseline(
                $existing['endpoint_state_delivery_baseline'] ?? null,
                $existing
            );
            if ($tuple_comparison > 0) {
                $comparison_baseline = $delivery_baseline;
                $prior_delivery_key = strtolower((string) ($existing['endpoint_state_delivery_key'] ?? ''));
                if ($prior_delivery_key !== ''
                    && preg_match('/^[a-f0-9]{64}$/', $prior_delivery_key)
                    && !hash_equals($prior_delivery_key, $incoming_delivery_key)) {
                    endpointSupersedeDeliveryUnlocked(
                        $asset_id,
                        $client_id,
                        $source,
                        $prior_delivery_key,
                        $observed_at
                    );
                    $superseded_delivery_key = $prior_delivery_key;
                }
            } else {
                $comparison_baseline = endpointStateBaselineFromRow($existing);
            }
        }
    }

    $comparison_exists = !empty($comparison_baseline['exists']);
    $old_payload = $comparison_exists && is_array($comparison_baseline['facts'] ?? null)
        ? $comparison_baseline['facts']
        : [];
    $comparison_status = (string) ($comparison_baseline['status'] ?? '');
    $comparison_external_id = (string) ($comparison_baseline['external_id'] ?? '');
    $comparison_hash = (string) ($comparison_baseline['payload_hash'] ?? '');
    $status_changed = $comparison_exists && $comparison_status !== $source_status;
    $changed = !$comparison_exists
        || $rebound
        || $status_changed
        || !hash_equals($comparison_hash, $document['hash']);

    $source_status_sql = endpointDbEscape($source_status);
    $health_sql = endpointDbEscape($facts['health_state']);
    $compliance_sql = endpointDbEscape($facts['compliance_state']);
    $encryption_sql = endpointDbEscape($facts['encryption_state']);
    $secure_boot_sql = endpointDbEscape($facts['secure_boot_state']);
    $user_external_sql = endpointNullableSql($facts['assigned_user_external_id']);
    $user_name_sql = endpointNullableSql($facts['assigned_user_name']);
    $user_email_sql = endpointNullableSql($facts['assigned_user_email']);
    $entra_id_sql = endpointNullableSql($facts['entra_device_id']);
    $intune_id_sql = endpointNullableSql($facts['intune_device_id']);
    $os_name_sql = endpointNullableSql($facts['os_name']);
    $os_version_sql = endpointNullableSql($facts['os_version']);
    $os_build_sql = endpointNullableSql($facts['os_build']);
    $agent_version_sql = endpointNullableSql($facts['agent_version']);
    $lifecycle_sql = endpointDbEscape($facts['lifecycle_state']);
    $payload_hash_sql = endpointDbEscape($document['hash']);
    $network_hash_sql = endpointDbEscape($effective_network_hash);
    $payload_sql = endpointDbEscape($document['payload']);
    $delivery_key_sql = endpointDbEscape($incoming_delivery_key);
    $delivery_baseline_sql = endpointDbEscape(endpointEncodeDeliveryBaseline($delivery_baseline));
    $last_seen_sql = endpointNullableSql($facts['last_seen_at']);
    $observed_at_sql = endpointDbEscape($observed_at);
    $retired_at_sql = $source_status === 'retired'
        ? "COALESCE(endpoint_state_retired_at, '$observed_at_sql')"
        : 'NULL';

    if ($existing) {
        $rebind_sql = $rebound
            ? ", endpoint_state_first_observed_at = '$observed_at_sql'"
            : '';
        $sql = "UPDATE asset_endpoint_states SET
            endpoint_state_external_id = '$external_id_sql',
            endpoint_state_status = '$source_status_sql',
            endpoint_state_health = '$health_sql',
            endpoint_state_compliance = '$compliance_sql',
            endpoint_state_encryption = '$encryption_sql',
            endpoint_state_secure_boot = '$secure_boot_sql',
            endpoint_state_assigned_user_external_id = $user_external_sql,
            endpoint_state_assigned_user_name = $user_name_sql,
            endpoint_state_assigned_user_email = $user_email_sql,
            endpoint_state_entra_device_id = $entra_id_sql,
            endpoint_state_intune_device_id = $intune_id_sql,
            endpoint_state_os_name = $os_name_sql,
            endpoint_state_os_version = $os_version_sql,
            endpoint_state_os_build = $os_build_sql,
            endpoint_state_agent_version = $agent_version_sql,
            endpoint_state_lifecycle = '$lifecycle_sql',
            endpoint_state_payload_hash = '$payload_hash_sql',
            endpoint_state_payload = '$payload_sql',
            endpoint_state_network_hash = '$network_hash_sql',
            endpoint_state_network_observed_at = '$observed_at_sql',
            endpoint_state_delivery_key = '$delivery_key_sql',
            endpoint_state_delivery_baseline = '$delivery_baseline_sql',
            endpoint_state_last_seen_at = CASE
                WHEN $last_seen_sql IS NULL THEN endpoint_state_last_seen_at
                WHEN endpoint_state_last_seen_at IS NULL
                    OR endpoint_state_last_seen_at < $last_seen_sql
                THEN $last_seen_sql
                ELSE endpoint_state_last_seen_at END,
            endpoint_state_observed_at = CASE
                WHEN endpoint_state_observed_at < '$observed_at_sql' THEN '$observed_at_sql'
                ELSE endpoint_state_observed_at END,
            endpoint_state_retired_at = $retired_at_sql
            $rebind_sql
            WHERE endpoint_state_id = " . intval($existing['endpoint_state_id']);
    } else {
        $retired_insert_sql = $source_status === 'retired' ? "'$observed_at_sql'" : 'NULL';
        $sql = "INSERT INTO asset_endpoint_states SET
            endpoint_state_asset_id = $asset_id,
            endpoint_state_client_id = $client_id,
            endpoint_state_source = '$source_sql',
            endpoint_state_external_id = '$external_id_sql',
            endpoint_state_status = '$source_status_sql',
            endpoint_state_health = '$health_sql',
            endpoint_state_compliance = '$compliance_sql',
            endpoint_state_encryption = '$encryption_sql',
            endpoint_state_secure_boot = '$secure_boot_sql',
            endpoint_state_assigned_user_external_id = $user_external_sql,
            endpoint_state_assigned_user_name = $user_name_sql,
            endpoint_state_assigned_user_email = $user_email_sql,
            endpoint_state_entra_device_id = $entra_id_sql,
            endpoint_state_intune_device_id = $intune_id_sql,
            endpoint_state_os_name = $os_name_sql,
            endpoint_state_os_version = $os_version_sql,
            endpoint_state_os_build = $os_build_sql,
            endpoint_state_agent_version = $agent_version_sql,
            endpoint_state_lifecycle = '$lifecycle_sql',
            endpoint_state_payload_hash = '$payload_hash_sql',
            endpoint_state_payload = '$payload_sql',
            endpoint_state_network_hash = '$network_hash_sql',
            endpoint_state_network_observed_at = '$observed_at_sql',
            endpoint_state_delivery_key = '$delivery_key_sql',
            endpoint_state_delivery_baseline = '$delivery_baseline_sql',
            endpoint_state_last_seen_at = $last_seen_sql,
            endpoint_state_observed_at = '$observed_at_sql',
            endpoint_state_retired_at = $retired_insert_sql";
    }
    if (!mysqli_query($mysqli, $sql)) {
        throw new RuntimeException('Could not save the canonical endpoint source state: ' . mysqli_error($mysqli));
    }
    $state_id = $existing ? intval($existing['endpoint_state_id']) : intval(mysqli_insert_id($mysqli));

    if ($changed) {
        $event_before = $old_payload;
        if ($comparison_exists) {
            $event_before['source_status'] = $comparison_status;
            $event_before['source_external_id'] = $comparison_external_id;
        }
        $event_after = $facts;
        $event_after['source_status'] = $source_status;
        $event_after['source_external_id'] = $external_id;
        $changed_fields = endpointSourceStateChangedFields($event_before, $event_after);
        $summary = $rebound
            ? ucfirst($source) . ' endpoint identity rebound from '
                . (string) $existing['endpoint_state_external_id'] . ' to ' . $external_id
            : ucfirst($source) . ($comparison_exists ? ' endpoint record changed' : ' endpoint record connected');
        if ($comparison_exists && $changed_fields) {
            $summary .= ': ' . implode(', ', $changed_fields);
        }
        endpointRecordChangeEventUnlocked([
            'asset_id' => $asset_id,
            'client_id' => $client_id,
            'source' => $source,
            'event_type' => $rebound
                ? 'identity'
                : ($status_changed ? 'lifecycle' : ($comparison_exists ? 'posture' : 'identity')),
            'external_key' => $external_id,
            'summary' => $summary,
            'before' => $event_before,
            'after' => $event_after,
            'occurred_at' => $observed_at,
            'delivery_key' => $incoming_delivery_key,
        ]);
    }

    return [
        'state_id' => $state_id,
        'changed' => $changed,
        'rebound' => $rebound,
        'delivery_won' => true,
        'delivery_tuple' => $incoming_tuple,
        'delivery_key' => $incoming_delivery_key,
        'superseded_delivery_key' => $superseded_delivery_key,
        'hash' => $document['hash'],
        'network_hash' => $effective_network_hash,
    ];
}

function endpointValidateNetworkRelationships(int $asset_id, int $client_id, array $state): void
{
    global $mysqli;

    $interface_id = intval($state['interface_id']);
    $interface_network_id = 0;
    if ($interface_id > 0) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT interface_network_id FROM asset_interfaces
            INNER JOIN assets ON asset_id = interface_asset_id
            WHERE interface_id = $interface_id
            AND interface_asset_id = $asset_id
            AND asset_client_id = $client_id LIMIT 1 FOR UPDATE"));
        if (!$row) {
            throw new RuntimeException('Network observation interface belongs to a different asset or client');
        }
        $interface_network_id = intval($row['interface_network_id'] ?? 0);
    }

    $network_id = intval($state['network_id']);
    if ($network_id > 0 && $interface_network_id > 0 && $network_id !== $interface_network_id) {
        throw new RuntimeException('Network observation disagrees with the interface network assignment');
    }
    $effective_network_id = $network_id ?: $interface_network_id;
    if ($effective_network_id > 0) {
        $network = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT network_vlan, network_name FROM networks
            WHERE network_id = $effective_network_id AND network_client_id = $client_id
            LIMIT 1 FOR UPDATE"));
        if (!$network) {
            throw new RuntimeException('Network observation VLAN belongs to a different client');
        }
        $reported_vlan = $state['vlan_id'] === null ? 0 : intval($state['vlan_id']);
        $assigned_vlan = intval($network['network_vlan'] ?? 0);
        if ($reported_vlan > 0 && $assigned_vlan > 0 && $reported_vlan !== $assigned_vlan) {
            throw new RuntimeException('Network observation VLAN disagrees with the ITFlow network assignment');
        }
    }

    $neighbor_asset_id = intval($state['neighbor_asset_id']);
    if ($neighbor_asset_id > 0) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_id FROM assets
            WHERE asset_id = $neighbor_asset_id AND asset_client_id = $client_id
            LIMIT 1 FOR UPDATE"));
        if (!$row) {
            throw new RuntimeException('Network neighbor belongs to a different client');
        }
    }

    $neighbor_interface_id = intval($state['neighbor_interface_id']);
    if ($neighbor_interface_id > 0) {
        $neighbor_sql = $neighbor_asset_id > 0 ? " AND interface_asset_id = $neighbor_asset_id" : '';
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT interface_id FROM asset_interfaces
            INNER JOIN assets ON asset_id = interface_asset_id
            WHERE interface_id = $neighbor_interface_id
            AND asset_client_id = $client_id$neighbor_sql
            LIMIT 1 FOR UPDATE"));
        if (!$row) {
            throw new RuntimeException('Network neighbor interface belongs to a different client');
        }
    }
}

function endpointPrepareNetworkDeliveryUnlocked(
    int $asset_id,
    int $client_id,
    string $source_status,
    array $raw_observations
): array {
    if (!in_array($source_status, ['active', 'stale'], true)) {
        $raw_observations = [];
    }
    endpointValidateNetworkObservationBounds($raw_observations);
    $normalized_observations = [];
    $incoming_identities = [];
    foreach ($raw_observations as $raw_observation) {
        $observation = endpointNormalizeNetworkObservation($raw_observation);
        endpointValidateNetworkRelationships($asset_id, $client_id, $observation['state']);
        if (isset($incoming_identities[$observation['identity_hash']])) {
            throw new InvalidArgumentException('Network reconciliation contains a duplicate interface key');
        }
        $incoming_identities[$observation['identity_hash']] = true;
        $normalized_observations[] = $observation;
    }
    return [
        'observations' => $normalized_observations,
        'hash' => endpointNetworkSnapshotHash($normalized_observations),
    ];
}

function endpointCurrentNetworkHashUnlocked(int $asset_id, int $client_id, string $source): string
{
    global $mysqli;

    $source_sql = endpointDbEscape($source);
    $material = [];
    $current = mysqli_query($mysqli, "SELECT network_observation_identity_hash,
        network_observation_state_hash FROM asset_network_observations
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_source = '$source_sql'
        AND network_observation_active = 1
        AND network_observation_canonical = 1 FOR UPDATE");
    if (!$current) {
        throw new RuntimeException('Could not lock current endpoint network observations');
    }
    while ($row = mysqli_fetch_assoc($current)) {
        $material[] = [
            'identity_hash' => (string) $row['network_observation_identity_hash'],
            'state_hash' => (string) $row['network_observation_state_hash'],
        ];
    }
    return endpointNetworkSnapshotHash($material);
}

function endpointReconcileNetworkObservationsUnlocked(array $input): array
{
    global $mysqli;

    if (empty($input['delivery_accepted'])) {
        throw new LogicException('Endpoint topology can only be persisted by an accepted source delivery');
    }

    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    $asset = endpointAssetTenantRow($asset_id, $client_id, true);
    $client_id = intval($asset['asset_client_id']);
    $source = endpointSource($input['source'] ?? '');
    $observed_at = endpointObservationDateTime($input['observed_at'] ?? null);
    $delivery_key = strtolower(endpointLimitText($input['delivery_key'] ?? '', 64));
    if ($delivery_key !== '' && !preg_match('/^[a-f0-9]{64}$/', $delivery_key)) {
        throw new LogicException('Endpoint topology delivery key is invalid');
    }
    $source_sql = endpointDbEscape($source);
    $source_watermark = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT endpoint_state_id,
        endpoint_state_status, endpoint_state_observed_at,
        endpoint_state_network_hash, endpoint_state_network_observed_at,
        endpoint_state_delivery_key
        FROM asset_endpoint_states WHERE endpoint_state_asset_id = $asset_id
        AND endpoint_state_client_id = $client_id
        AND endpoint_state_source = '$source_sql' LIMIT 1 FOR UPDATE"));
    if (!$source_watermark && empty($input['retirement_cleanup'])) {
        throw new LogicException('Endpoint topology requires an accepted canonical posture row');
    }
    if ($source_watermark && empty($input['retirement_cleanup'])) {
        if ($delivery_key === ''
            || !hash_equals((string) $source_watermark['endpoint_state_delivery_key'], $delivery_key)
            || strcmp((string) $source_watermark['endpoint_state_observed_at'], $observed_at) !== 0) {
            throw new LogicException('Endpoint topology delivery does not own the accepted posture row');
        }
    }
    if (array_key_exists('prepared_observations', $input)) {
        $normalized_observations = is_array($input['prepared_observations'])
            ? $input['prepared_observations']
            : [];
        $incoming_snapshot_hash = strtolower(endpointLimitText($input['effective_network_hash'] ?? '', 64));
        if (!preg_match('/^[a-f0-9]{64}$/', $incoming_snapshot_hash)) {
            throw new LogicException('Accepted endpoint topology is missing its effective network hash');
        }
    } else {
        $prepared = endpointPrepareNetworkDeliveryUnlocked(
            $asset_id,
            $client_id,
            (string) ($source_watermark['endpoint_state_status'] ?? 'retired'),
            is_array($input['observations'] ?? null) ? $input['observations'] : []
        );
        $normalized_observations = $prepared['observations'];
        $incoming_snapshot_hash = $prepared['hash'];
    }
    $calculated_snapshot_hash = endpointNetworkSnapshotHash($normalized_observations);
    if (!hash_equals($calculated_snapshot_hash, $incoming_snapshot_hash)) {
        throw new LogicException('Accepted endpoint topology does not match its delivery tuple');
    }
    if ($source_watermark) {
        $owned_snapshot_hash = (string) $source_watermark['endpoint_state_network_hash'];
        if ($owned_snapshot_hash === ''
            && !in_array((string) $source_watermark['endpoint_state_status'], ['active', 'stale'], true)) {
            $owned_snapshot_hash = endpointNetworkSnapshotHash([]);
        }
        if ($owned_snapshot_hash !== '' && !hash_equals($owned_snapshot_hash, $incoming_snapshot_hash)) {
            throw new LogicException('Endpoint topology does not match the accepted posture delivery');
        }
    }

    $existing = [];
    $existing_sql = mysqli_query($mysqli, "SELECT * FROM asset_network_observations
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_source = '$source_sql'
        AND network_observation_active = 1
        AND network_observation_canonical = 1 FOR UPDATE");
    if (!$existing_sql) {
        throw new RuntimeException('Could not lock current endpoint network observations');
    }
    while ($row = mysqli_fetch_assoc($existing_sql)) {
        $identity_hash = (string) $row['network_observation_identity_hash'];
        if (isset($existing[$identity_hash])) {
            throw new RuntimeException('Endpoint network history has duplicate current observations');
        }
        $existing[$identity_hash] = $row;
    }

    $seen = [];
    $summary = ['current' => 0, 'created' => 0, 'changed' => 0, 'ended' => 0];
    foreach ($normalized_observations as $observation) {
        $state = $observation['state'];
        $seen[$observation['identity_hash']] = true;
        $previous = $existing[$observation['identity_hash']] ?? null;
        $same = $previous && hash_equals(
            (string) $previous['network_observation_state_hash'],
            $observation['state_hash']
        );

        $identity_hash_sql = endpointDbEscape($observation['identity_hash']);
        $state_hash_sql = endpointDbEscape($observation['state_hash']);
        $key_sql = endpointDbEscape($state['key']);
        $payload_sql = endpointDbEscape($observation['payload']);
        $interface_id = intval($state['interface_id']);
        $interface_id_sql = $interface_id > 0 ? $interface_id : 'NULL';
        $observed_at_sql = endpointDbEscape($observed_at);
        $delivery_key_sql = endpointDbEscape($delivery_key);

        if ($same) {
            if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
                network_observation_interface_id = $interface_id_sql,
                network_observation_previous_last_seen_at = CASE
                    WHEN network_observation_last_seen_at < '$observed_at_sql'
                    THEN network_observation_last_seen_at
                    ELSE network_observation_previous_last_seen_at END,
                network_observation_last_seen_delivery_key = CASE
                    WHEN network_observation_last_seen_at < '$observed_at_sql'
                    THEN '$delivery_key_sql'
                    ELSE network_observation_last_seen_delivery_key END,
                network_observation_last_seen_at = CASE
                    WHEN network_observation_last_seen_at < '$observed_at_sql' THEN '$observed_at_sql'
                    ELSE network_observation_last_seen_at END,
                network_observation_active = 1,
                network_observation_ended_at = NULL
                WHERE network_observation_id = " . intval($previous['network_observation_id']))) {
                throw new RuntimeException('Could not refresh the endpoint network observation');
            }
            $summary['current']++;
            continue;
        }

        $before = [];
        if ($previous) {
            $decoded = json_decode((string) $previous['network_observation_payload'], true);
            $before = is_array($decoded) ? $decoded : [];
            if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
                network_observation_active = 0,
                network_observation_ended_at = '$observed_at_sql',
                network_observation_closed_delivery_key = '$delivery_key_sql'
                WHERE network_observation_id = " . intval($previous['network_observation_id']))) {
                throw new RuntimeException('Could not close the previous endpoint network observation');
            }
            $summary['changed']++;
        } else {
            $summary['created']++;
        }

        if (!mysqli_query($mysqli, "INSERT INTO asset_network_observations SET
            network_observation_asset_id = $asset_id,
            network_observation_client_id = $client_id,
            network_observation_interface_id = $interface_id_sql,
            network_observation_source = '$source_sql',
            network_observation_key = '$key_sql',
            network_observation_identity_hash = '$identity_hash_sql',
            network_observation_state_hash = '$state_hash_sql',
            network_observation_payload = '$payload_sql',
            network_observation_created_delivery_key = '$delivery_key_sql',
            network_observation_canonical = 1,
            network_observation_superseded_at = NULL,
            network_observation_first_seen_at = '$observed_at_sql',
            network_observation_last_seen_at = '$observed_at_sql',
            network_observation_active = 1")) {
            throw new RuntimeException('Could not save the endpoint network observation: ' . mysqli_error($mysqli));
        }

        endpointRecordChangeEventUnlocked([
            'asset_id' => $asset_id,
            'client_id' => $client_id,
            'source' => $source,
            'event_type' => 'network',
            'external_key' => $state['key'],
            'summary' => ucfirst($source) . ' network interface '
                . ($previous ? 'changed: ' : 'discovered: ') . $state['interface_name'],
            'before' => $before,
            'after' => $state,
            'occurred_at' => $observed_at,
            'delivery_key' => $delivery_key,
        ]);
    }

    foreach ($existing as $identity_hash => $previous) {
        if (isset($seen[$identity_hash])) {
            continue;
        }
        $decoded = json_decode((string) $previous['network_observation_payload'], true);
        $before = is_array($decoded) ? $decoded : [];
        $observed_at_sql = endpointDbEscape($observed_at);
        if (!mysqli_query($mysqli, "UPDATE asset_network_observations SET
            network_observation_active = 0,
            network_observation_ended_at = '$observed_at_sql',
            network_observation_closed_delivery_key = '" . endpointDbEscape($delivery_key) . "'
            WHERE network_observation_id = " . intval($previous['network_observation_id']))) {
            throw new RuntimeException('Could not retire a missing endpoint network observation');
        }
        endpointRecordChangeEventUnlocked([
            'asset_id' => $asset_id,
            'client_id' => $client_id,
            'source' => $source,
            'event_type' => 'network',
            'external_key' => $before['key'] ?? '',
            'summary' => ucfirst($source) . ' network interface no longer observed: '
                . endpointLimitText($before['interface_name'] ?? $before['key'] ?? 'unknown', 200),
            'before' => $before,
            'after' => [],
            'occurred_at' => $observed_at,
            'delivery_key' => $delivery_key,
        ]);
        $summary['ended']++;
    }

    if ($source_watermark) {
        $incoming_snapshot_hash_sql = endpointDbEscape($incoming_snapshot_hash);
        $observed_at_sql = endpointDbEscape($observed_at);
        if (!mysqli_query($mysqli, "UPDATE asset_endpoint_states SET
            endpoint_state_network_hash = '$incoming_snapshot_hash_sql',
            endpoint_state_network_observed_at = '$observed_at_sql'
            WHERE endpoint_state_id = " . intval($source_watermark['endpoint_state_id']))) {
            throw new RuntimeException('Could not advance the endpoint network watermark');
        }
    }

    return $summary;
}

function endpointReconcileAssetSourceUnlocked(array $input): array
{
    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    $asset = endpointAssetTenantRow($asset_id, $client_id, true);
    $client_id = intval($asset['asset_client_id']);
    $source_status = endpointNormalizeSourceStatus($input['status'] ?? 'active');
    $observed_at = endpointObservationDateTime($input['observed_at'] ?? null);
    $prepared_network = endpointPrepareNetworkDeliveryUnlocked(
        $asset_id,
        $client_id,
        $source_status,
        is_array($input['network_interfaces'] ?? null) ? $input['network_interfaces'] : []
    );
    $state_input = $input;
    $state_input['asset_id'] = $asset_id;
    $state_input['client_id'] = $client_id;
    $state_input['status'] = $source_status;
    $state_input['observed_at'] = $observed_at;
    $state_input['effective_network_hash'] = $prepared_network['hash'];
    $state = endpointRecordSourceStateUnlocked($state_input);
    if (empty($state['delivery_won'])) {
        return [
            'state' => $state,
            'network' => [
                'current' => 0,
                'created' => 0,
                'changed' => 0,
                'ended' => 0,
                'stale' => true,
                'tie_lost' => !empty($state['tie_lost']),
            ],
        ];
    }
    $network = endpointReconcileNetworkObservationsUnlocked([
        'asset_id' => $asset_id,
        'client_id' => $client_id,
        'source' => $input['source'] ?? '',
        'observed_at' => $observed_at,
        'prepared_observations' => $prepared_network['observations'],
        'effective_network_hash' => $prepared_network['hash'],
        'delivery_key' => $state['delivery_key'] ?? '',
        'delivery_accepted' => true,
    ]);
    return ['state' => $state, 'network' => $network];
}

function endpointReconcileAssetSource(array $input): array
{
    global $mysqli;

    mysqli_begin_transaction($mysqli);
    try {
        $result = endpointReconcileAssetSourceUnlocked($input);
        mysqli_commit($mysqli);
        return $result;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function endpointRetireSourceStateUnlocked(array $input): bool
{
    global $mysqli;

    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    $asset = endpointAssetTenantRow($asset_id, $client_id, true);
    $client_id = intval($asset['asset_client_id']);
    $source = endpointSource($input['source'] ?? '');
    $external_id = endpointExternalId($input['external_id'] ?? '');
    $source_sql = endpointDbEscape($source);
    $external_id_sql = endpointDbEscape($external_id);
    $state = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM asset_endpoint_states
        WHERE endpoint_state_asset_id = $asset_id
        AND endpoint_state_client_id = $client_id
        AND endpoint_state_source = '$source_sql'
        AND endpoint_state_external_id = '$external_id_sql'
        LIMIT 1 FOR UPDATE"));
    if (!$state) {
        return false;
    }
    if ((string) $state['endpoint_state_status'] === 'retired') {
        return true;
    }
    $before = json_decode((string) $state['endpoint_state_payload'], true);
    $before = is_array($before) ? $before : [];
    $occurred_at = endpointObservationDateTime($input['occurred_at'] ?? null);
    if (!empty($state['endpoint_state_observed_at'])
        && strcmp((string) $state['endpoint_state_observed_at'], $occurred_at) > 0) {
        return false;
    }
    $after = $before;
    $after['health_state'] = 'unmanaged';
    $after['lifecycle_state'] = 'retired';
    $document = endpointSourceStateDocument($source, $external_id, $after);
    $payload_hash_sql = endpointDbEscape($document['hash']);
    $payload_sql = endpointDbEscape($document['payload']);
    $empty_network_hash_sql = endpointDbEscape(endpointNetworkSnapshotHash([]));
    $occurred_at_sql = endpointDbEscape($occurred_at);
    if (!mysqli_query($mysqli, "UPDATE asset_endpoint_states SET
        endpoint_state_status = 'retired',
        endpoint_state_health = 'unmanaged',
        endpoint_state_lifecycle = 'retired',
        endpoint_state_payload_hash = '$payload_hash_sql',
        endpoint_state_payload = '$payload_sql',
        endpoint_state_network_hash = '$empty_network_hash_sql',
        endpoint_state_network_observed_at = '$occurred_at_sql',
        endpoint_state_observed_at = CASE
            WHEN endpoint_state_observed_at < '$occurred_at_sql' THEN '$occurred_at_sql'
            ELSE endpoint_state_observed_at END,
        endpoint_state_retired_at = COALESCE(endpoint_state_retired_at, '$occurred_at_sql')
        WHERE endpoint_state_id = " . intval($state['endpoint_state_id']))) {
        throw new RuntimeException('Could not retire the endpoint source state');
    }
    endpointRecordChangeEventUnlocked([
        'asset_id' => $asset_id,
        'client_id' => $client_id,
        'source' => $source,
        'event_type' => 'lifecycle',
        'external_key' => $external_id,
        'summary' => endpointLimitText($input['reason'] ?? ucfirst($source) . ' management retired', 500),
        'before' => array_merge($before, ['source_status' => (string) $state['endpoint_state_status']]),
        'after' => array_merge($document['facts'], ['source_status' => 'retired']),
        'occurred_at' => $occurred_at,
    ]);
    return true;
}

function endpointRetireIdentityBindingUnlocked(array $input): bool
{
    global $mysqli;

    $asset_id = endpointPositiveInt($input['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($input['client_id'] ?? 0);
    $asset = endpointAssetTenantRow($asset_id, $client_id, true);
    $client_id = intval($asset['asset_client_id']);
    $source = endpointSource($input['source'] ?? '');
    $external_id = endpointExternalId($input['external_id'] ?? '');
    $source_sql = endpointDbEscape($source);
    $external_id_sql = endpointDbEscape($external_id);
    $state = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT endpoint_state_external_id,
        endpoint_state_observed_at, endpoint_state_network_observed_at
        FROM asset_endpoint_states WHERE endpoint_state_asset_id = $asset_id
        AND endpoint_state_client_id = $client_id
        AND endpoint_state_source = '$source_sql' LIMIT 1 FOR UPDATE"));
    $external_state = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT endpoint_state_asset_id,
        endpoint_state_client_id FROM asset_endpoint_states
        WHERE endpoint_state_source = '$source_sql'
        AND endpoint_state_external_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
    if ($external_state
        && (intval($external_state['endpoint_state_asset_id']) !== $asset_id
            || intval($external_state['endpoint_state_client_id']) !== $client_id)) {
        return false;
    }
    if ($state && (string) $state['endpoint_state_external_id'] !== $external_id) {
        return false;
    }

    $occurred_at = endpointObservationDateTime($input['occurred_at'] ?? null);
    foreach (['endpoint_state_observed_at', 'endpoint_state_network_observed_at'] as $watermark) {
        if ($state && !empty($state[$watermark])
            && strcmp((string) $state[$watermark], $occurred_at) > 0) {
            $occurred_at = (string) $state[$watermark];
        }
    }
    $network_watermark = mysqli_fetch_row(mysqli_query($mysqli, "SELECT
        MAX(network_observation_last_seen_at) FROM asset_network_observations
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_canonical = 1
        AND network_observation_source = '$source_sql'
        AND network_observation_active = 1"));
    if (!empty($network_watermark[0]) && strcmp((string) $network_watermark[0], $occurred_at) > 0) {
        $occurred_at = (string) $network_watermark[0];
    }
    $retired = endpointRetireSourceStateUnlocked([
        'asset_id' => $asset_id,
        'client_id' => $client_id,
        'source' => $source,
        'external_id' => $external_id,
        'occurred_at' => $occurred_at,
        'reason' => $input['reason'] ?? ucfirst($source) . ' identity retired',
    ]);
    if ($state && !$retired) {
        return false;
    }
    endpointReconcileNetworkObservationsUnlocked([
        'asset_id' => $asset_id,
        'client_id' => $client_id,
        'source' => $source,
        'observed_at' => $occurred_at,
        'observations' => [],
        'delivery_accepted' => true,
        'retirement_cleanup' => true,
    ]);
    return true;
}

function endpointWarrantyState($expiry, ?DateTimeImmutable $now = null): string
{
    if ($expiry === null || trim((string) $expiry) === '') {
        return 'unknown';
    }
    try {
        $date = new DateTimeImmutable((string) $expiry . ' 23:59:59');
    } catch (Throwable $e) {
        return 'unknown';
    }
    $now = $now ?: new DateTimeImmutable('now');
    if ($date < $now) {
        return 'expired';
    }
    return $date <= $now->modify('+90 days') ? 'due_soon' : 'active';
}

function endpointSourcePriority(string $source): int
{
    return match ($source) {
        'intune' => 10,
        'entra' => 20,
        'sentinelone' => 30,
        'level' => 40,
        'itflow' => 50,
        default => 100,
    };
}

function endpointSummaryFieldOwners(): array
{
    return [
        'assigned_user_name' => ['intune', 'entra'],
        'assigned_user_email' => ['intune', 'entra'],
        'entra_device_id' => ['entra', 'intune'],
        'intune_device_id' => ['intune'],
        'compliance_state' => ['intune'],
        'encryption_state' => ['intune'],
        'secure_boot_state' => ['intune'],
        'os_name' => ['intune', 'level', 'sentinelone', 'entra'],
        'os_version' => ['intune', 'level', 'sentinelone', 'entra'],
        'os_build' => ['intune', 'level', 'sentinelone', 'entra'],
    ];
}

/**
 * Source coverage by client for active endpoint-class assets. Allow/deny lists
 * use the same semantics as clientScopeSql: an empty allow list means all
 * clients, while an explicit deny always wins.
 */
function endpointIntegrationCoverageRows(array $allowed_client_ids = [], array $denied_client_ids = []): array
{
    global $mysqli;

    $allowed_client_ids = array_values(array_unique(array_filter(
        array_map('intval', $allowed_client_ids),
        static fn ($client_id) => $client_id > 0
    )));
    $denied_client_ids = array_values(array_unique(array_filter(
        array_map('intval', $denied_client_ids),
        static fn ($client_id) => $client_id > 0
    )));
    $client_scope_sql = '';
    if ($allowed_client_ids) {
        $client_scope_sql .= ' AND assets.asset_client_id IN (' . implode(',', $allowed_client_ids) . ')';
    }
    if ($denied_client_ids) {
        $client_scope_sql .= ' AND assets.asset_client_id NOT IN (' . implode(',', $denied_client_ids) . ')';
    }

    $sql = mysqli_query($mysqli, "SELECT coverage.asset_client_id AS client_id,
        clients.client_name,
        COUNT(*) AS active_devices,
        SUM(coverage.has_level) AS level_devices,
        SUM(coverage.has_intune) AS intune_devices,
        SUM(coverage.has_entra) AS entra_devices,
        SUM(coverage.has_sentinelone) AS sentinelone_devices,
        SUM(coverage.has_level = 1 OR coverage.has_intune = 1) AS endpoint_managed_devices,
        SUM(coverage.has_level = 0) AS missing_level_devices,
        SUM(coverage.has_intune = 0) AS missing_intune_devices,
        SUM(coverage.is_windows = 1
            AND (coverage.has_level = 1 OR coverage.has_intune = 1)
            AND coverage.has_sentinelone = 0) AS managed_windows_missing_sentinelone
        FROM (
            SELECT assets.asset_id, assets.asset_client_id,
                (LOWER(COALESCE(assets.asset_os, '')) LIKE '%windows%'
                    OR MAX(LOWER(COALESCE(endpoint_state_os_name, '')) LIKE '%windows%')) AS is_windows,
                MAX(endpoint_state_source = 'level'
                    AND endpoint_state_status IN ('active', 'stale')) AS has_level,
                MAX(endpoint_state_source = 'intune'
                    AND endpoint_state_status IN ('active', 'stale')) AS has_intune,
                MAX(endpoint_state_source = 'entra'
                    AND endpoint_state_status IN ('active', 'stale')) AS has_entra,
                MAX(endpoint_state_source = 'sentinelone'
                    AND endpoint_state_status IN ('active', 'stale')) AS has_sentinelone
        FROM assets
        LEFT JOIN asset_endpoint_states
            ON endpoint_state_asset_id = assets.asset_id
            AND endpoint_state_client_id = assets.asset_client_id
            WHERE 1 = 1 $client_scope_sql
        AND asset_archived_at IS NULL
        AND LOWER(COALESCE(asset_status, '')) NOT IN ('retired', 'disposed')
        AND LOWER(TRIM(asset_type)) IN (
            'desktop', 'laptop', 'server', 'workstation', 'virtual machine',
            'tablet', 'phone', 'mobile phone'
        )
            GROUP BY assets.asset_id, assets.asset_client_id, assets.asset_os
        ) coverage
        INNER JOIN clients ON clients.client_id = coverage.asset_client_id
            AND client_archived_at IS NULL
        GROUP BY coverage.asset_client_id, clients.client_name
        ORDER BY clients.client_name, coverage.asset_client_id");
    if (!$sql) {
        throw new RuntimeException('Could not calculate endpoint integration coverage');
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $active = intval($row['active_devices'] ?? 0);
        foreach (['level', 'intune', 'entra', 'sentinelone'] as $source) {
            $covered = intval($row[$source . '_devices'] ?? 0);
            $row[$source . '_devices'] = $covered;
            $row[$source . '_coverage_percent'] = $active > 0
                ? round(($covered / $active) * 100, 2) : 0.0;
        }
        $row['client_id'] = intval($row['client_id']);
        $row['active_devices'] = $active;
        $row['missing_level_devices'] = intval($row['missing_level_devices'] ?? 0);
        $row['missing_intune_devices'] = intval($row['missing_intune_devices'] ?? 0);
        $row['managed_windows_missing_sentinelone'] = intval(
            $row['managed_windows_missing_sentinelone'] ?? 0
        );
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Stable service-review seam. Agreement reporting consumes this aggregate
 * without knowing the endpoint schema or any vendor-specific table.
 */
function unifiedDeviceServiceReviewSnapshot(int $client_id): array
{
    global $mysqli;

    if ($client_id < 1) {
        throw new InvalidArgumentException('Service review client is required');
    }
    $client = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM clients
        WHERE client_id = $client_id AND client_archived_at IS NULL"));
    if (intval($client[0] ?? 0) !== 1) {
        throw new InvalidArgumentException('Service review client does not exist');
    }
    $coverage_rows = endpointIntegrationCoverageRows([$client_id]);
    $row = $coverage_rows[0] ?? null;
    if (!$row) {
        $row = [
            'active_devices' => 0,
            'level_devices' => 0,
            'intune_devices' => 0,
            'entra_devices' => 0,
            'sentinelone_devices' => 0,
            'missing_level_devices' => 0,
            'missing_intune_devices' => 0,
            'managed_windows_missing_sentinelone' => 0,
        ];
    }
    if (!is_array($row)) {
        throw new RuntimeException('Could not calculate endpoint service-review coverage');
    }

    $active = intval($row['active_devices'] ?? 0);
    $level = intval($row['level_devices'] ?? 0);
    $intune = intval($row['intune_devices'] ?? 0);
    $entra = intval($row['entra_devices'] ?? 0);
    $security = intval($row['sentinelone_devices'] ?? 0);
    $managed = intval($row['endpoint_managed_devices'] ?? 0);
    return [
        'active_devices' => $active,
        'endpoint_managed_devices' => $managed,
        'endpoint_coverage_percent' => $active > 0 ? round(($managed / $active) * 100, 2) : 0.0,
        'security_mapped_devices' => $security,
        'security_coverage_percent' => $active > 0 ? round(($security / $active) * 100, 2) : 0.0,
        'level_mapped_devices' => $level,
        'level_coverage_percent' => $active > 0 ? round(($level / $active) * 100, 2) : 0.0,
        'intune_mapped_devices' => $intune,
        'intune_coverage_percent' => $active > 0 ? round(($intune / $active) * 100, 2) : 0.0,
        'entra_mapped_devices' => $entra,
        'entra_coverage_percent' => $active > 0 ? round(($entra / $active) * 100, 2) : 0.0,
        'managed_windows_missing_sentinelone' => intval(
            $row['managed_windows_missing_sentinelone'] ?? 0
        ),
        'source' => 'unified_endpoint_record',
    ];
}

function endpointBuildUnifiedSummary(array $asset, array $states): array
{
    $summary = [
        'assigned_user_name' => '',
        'assigned_user_email' => '',
        'entra_device_id' => '',
        'intune_device_id' => '',
        'compliance_state' => 'unknown',
        'encryption_state' => 'unknown',
        'secure_boot_state' => 'unknown',
        'os_name' => '',
        'os_version' => '',
        'os_build' => '',
        'level_health' => 'unmanaged',
        'sentinelone_health' => 'unmanaged',
        'lifecycle_state' => endpointNormalizeLifecycle($asset['asset_status'] ?? ''),
        'warranty_state' => endpointWarrantyState($asset['asset_warranty_expire'] ?? null),
        'warranty_expire' => $asset['asset_warranty_expire'] ?? null,
    ];

    $trusted_states = [];
    foreach ($states as $state) {
        if (!in_array((string) ($state['endpoint_state_status'] ?? ''), ['active', 'stale'], true)) {
            continue;
        }
        $source = (string) $state['endpoint_state_source'];
        if (in_array($source, ['intune', 'entra', 'sentinelone', 'level', 'itflow'], true)) {
            $trusted_states[$source] = $state;
        }
    }

    foreach (endpointSummaryFieldOwners() as $summary_field => $owners) {
        $column = 'endpoint_state_' . str_replace('_state', '', $summary_field);
        foreach ($owners as $owner) {
            $value = (string) ($trusted_states[$owner][$column] ?? '');
            if ($value !== '' && $value !== 'unknown') {
                $summary[$summary_field] = $value;
                break;
            }
        }
    }
    if (isset($trusted_states['level'])) {
        $summary['level_health'] = (string) $trusted_states['level']['endpoint_state_health'];
    }
    if (isset($trusted_states['sentinelone'])) {
        $summary['sentinelone_health'] = (string) $trusted_states['sentinelone']['endpoint_state_health'];
    }
    if ($summary['assigned_user_name'] === '') {
        $summary['assigned_user_name'] = endpointLimitText($asset['contact_name'] ?? '', 255);
    }
    if ($summary['assigned_user_email'] === '') {
        $summary['assigned_user_email'] = endpointLimitText($asset['contact_email'] ?? '', 320);
    }
    if ($summary['os_name'] === '') {
        $summary['os_name'] = endpointLimitText($asset['asset_os'] ?? '', 200);
    }
    return $summary;
}

/**
 * Validate the source-neutral record returned to endpoint consumers. The
 * optional strict mode is used by synthetic acceptance fixtures to exercise
 * every Goal 5 branch; runtime records may legitimately have empty optional
 * collections, but may never change their shape.
 */
function endpointUnifiedRecordContractViolations(array $record, bool $strict = false): array
{
    $violations = [];
    $required_collections = [
        'sources', 'identities', 'interfaces', 'network_current',
        'network_history', 'timeline', 'evidence', 'related_tickets',
        'related_documentation',
    ];
    if (!is_array($record['summary'] ?? null)) {
        $violations[] = 'summary_missing';
    } else {
        foreach ([
            'assigned_user_name', 'assigned_user_email', 'entra_device_id',
            'intune_device_id', 'compliance_state', 'encryption_state',
            'secure_boot_state', 'os_name', 'os_version', 'os_build',
            'level_health', 'sentinelone_health', 'lifecycle_state',
            'warranty_state', 'warranty_expire',
        ] as $field) {
            if (!array_key_exists($field, $record['summary'])) {
                $violations[] = 'summary_field_missing:' . $field;
            }
        }
    }
    foreach ($required_collections as $collection) {
        if (!array_key_exists($collection, $record) || !is_array($record[$collection])) {
            $violations[] = 'collection_missing:' . $collection;
        }
    }
    if ($violations || !$strict) {
        sort($violations, SORT_STRING);
        return array_values(array_unique($violations));
    }

    $identity_keys = [];
    foreach ($record['identities'] as $identity) {
        $source = (string) ($identity['automation_mapping_source'] ?? $identity['source'] ?? '');
        $external_id = (string) (
            $identity['automation_mapping_external_id'] ?? $identity['external_id'] ?? ''
        );
        $state = (string) ($identity['automation_mapping_state'] ?? $identity['state'] ?? '');
        if ($source === '' || $external_id === '' || $state === '') {
            $violations[] = 'identity_shape_invalid';
            continue;
        }
        $identity_key = $source . "\0" . $external_id;
        if (isset($identity_keys[$identity_key])) {
            $violations[] = 'identity_duplicate';
        }
        $identity_keys[$identity_key] = true;
    }
    foreach ($record['sources'] as $state) {
        if ((string) ($state['endpoint_state_source'] ?? '') === ''
            || (string) ($state['endpoint_state_external_id'] ?? '') === ''
            || (string) ($state['endpoint_state_status'] ?? '') === ''
            || (string) ($state['endpoint_state_observed_at'] ?? '') === '') {
            $violations[] = 'source_state_shape_invalid';
        }
    }

    $interface_ids = [];
    foreach ($record['interfaces'] as $interface) {
        $interface_id = endpointPositiveInt($interface['interface_id'] ?? 0);
        if ($interface_id < 1 || trim((string) ($interface['interface_name'] ?? '')) === ''
            || !is_array($interface['connections'] ?? null)) {
            $violations[] = 'interface_shape_invalid';
            continue;
        }
        if (isset($interface_ids[$interface_id])) {
            $violations[] = 'interface_duplicate';
        }
        $interface_ids[$interface_id] = true;
    }

    foreach (['network_current' => 1, 'network_history' => 0] as $collection => $active) {
        foreach ($record[$collection] as $network_row) {
            $state = $network_row['network_observation_state'] ?? null;
            if (!is_array($state)
                || intval($network_row['network_observation_active'] ?? -1) !== $active) {
                $violations[] = 'network_observation_shape_invalid:' . $collection;
                continue;
            }
            foreach ([
                'key', 'interface_name', 'interface_type', 'virtual', 'mac',
                'ipv4', 'ipv6', 'network_id', 'vlan_id', 'vlan_name',
                'neighbor_protocol', 'neighbor_asset_id',
                'neighbor_interface_id', 'neighbor_name',
                'neighbor_chassis_id', 'neighbor_port',
            ] as $field) {
                if (!array_key_exists($field, $state)) {
                    $violations[] = 'network_field_missing:' . $field;
                }
            }
            if (!is_array($state['ipv4'] ?? null) || !is_array($state['ipv6'] ?? null)) {
                $violations[] = 'network_address_history_invalid';
            }
        }
    }
    foreach ($record['timeline'] as $event) {
        if ((string) ($event['source'] ?? '') === ''
            || (string) ($event['type'] ?? '') === ''
            || (string) ($event['summary'] ?? '') === ''
            || (string) ($event['occurred_at'] ?? '') === '') {
            $violations[] = 'timeline_event_shape_invalid';
        }
    }
    foreach ($record['evidence'] as $evidence) {
        if (endpointPositiveInt($evidence['task_evidence_id'] ?? 0) < 1
            || endpointPositiveInt($evidence['ticket_id'] ?? 0) < 1
            || trim((string) ($evidence['task_evidence_type'] ?? '')) === '') {
            $violations[] = 'evidence_reference_shape_invalid';
        }
    }
    foreach ($record['related_tickets'] as $ticket) {
        if (endpointPositiveInt($ticket['ticket_id'] ?? 0) < 1) {
            $violations[] = 'related_ticket_shape_invalid';
        }
    }
    foreach ($record['related_documentation'] as $document) {
        if (endpointPositiveInt($document['document_id'] ?? 0) < 1) {
            $violations[] = 'related_documentation_shape_invalid';
        }
    }
    sort($violations, SORT_STRING);
    return array_values(array_unique($violations));
}

function endpointLoadUnifiedRecord(int $asset_id, int $client_id): array
{
    global $mysqli;

    $asset = endpointAssetTenantRow($asset_id, $client_id, false);
    $asset_sql = mysqli_query($mysqli, "SELECT assets.*, contact_name, contact_email
        FROM assets LEFT JOIN contacts ON contact_id = asset_contact_id
        WHERE asset_id = $asset_id AND asset_client_id = $client_id LIMIT 1");
    $asset = mysqli_fetch_assoc($asset_sql) ?: $asset;

    $states = [];
    $state_sql = mysqli_query($mysqli, "SELECT * FROM asset_endpoint_states
        WHERE endpoint_state_asset_id = $asset_id
        AND endpoint_state_client_id = $client_id
        ORDER BY endpoint_state_retired_at IS NOT NULL,
            endpoint_state_observed_at DESC, endpoint_state_source ASC");
    while ($state_sql && $row = mysqli_fetch_assoc($state_sql)) {
        $payload = json_decode((string) $row['endpoint_state_payload'], true);
        $row['endpoint_state_facts'] = is_array($payload) ? $payload : [];
        unset($row['endpoint_state_payload'], $row['endpoint_state_delivery_baseline']);
        $states[] = $row;
    }

    $identities = [];
    $identity_sql = mysqli_query($mysqli, "SELECT automation_mapping_source,
        automation_mapping_external_id, automation_mapping_external_name,
        automation_mapping_state, automation_mapping_confidence,
        automation_mapping_last_seen_at, automation_mapping_last_synced_at,
        automation_mapping_last_error
        FROM automation_entity_mappings
        WHERE automation_mapping_asset_id = $asset_id
        AND automation_mapping_client_id = $client_id
        AND automation_mapping_entity_type = 'device'
        ORDER BY automation_mapping_deleted_at IS NOT NULL,
            automation_mapping_source ASC");
    while ($identity_sql && $row = mysqli_fetch_assoc($identity_sql)) {
        $identities[] = $row;
    }

    $network_current = [];
    $network_history = [];
    $network_rows = [];
    $related_network_ids = [];
    $neighbor_asset_ids = [];
    $neighbor_interface_ids = [];
    $network_sql = mysqli_query($mysqli, "SELECT asset_network_observations.*,
        ai.interface_name AS current_interface_name, n.network_name
        FROM asset_network_observations
        LEFT JOIN asset_interfaces ai
            ON ai.interface_id = network_observation_interface_id
            AND ai.interface_asset_id = network_observation_asset_id
        LEFT JOIN networks n ON n.network_id = ai.interface_network_id
            AND n.network_client_id = network_observation_client_id
        WHERE network_observation_asset_id = $asset_id
        AND network_observation_client_id = $client_id
        AND network_observation_canonical = 1
        ORDER BY network_observation_active DESC,
            network_observation_last_seen_at DESC, network_observation_id DESC
        LIMIT 200");
    while ($network_sql && $row = mysqli_fetch_assoc($network_sql)) {
        $payload = json_decode((string) $row['network_observation_payload'], true);
        $row['network_observation_state'] = is_array($payload) ? $payload : [];
        unset($row['network_observation_payload']);
        $state = $row['network_observation_state'];
        $related_network_id = endpointPositiveInt($state['network_id'] ?? 0);
        $neighbor_asset_id = endpointPositiveInt($state['neighbor_asset_id'] ?? 0);
        $neighbor_interface_id = endpointPositiveInt($state['neighbor_interface_id'] ?? 0);
        if ($related_network_id > 0) {
            $related_network_ids[$related_network_id] = true;
        }
        if ($neighbor_asset_id > 0) {
            $neighbor_asset_ids[$neighbor_asset_id] = true;
        }
        if ($neighbor_interface_id > 0) {
            $neighbor_interface_ids[$neighbor_interface_id] = true;
        }
        $network_rows[] = $row;
    }

    $related_network_names = [];
    if ($related_network_ids) {
        $ids_sql = implode(',', array_map('intval', array_keys($related_network_ids)));
        $related_sql = mysqli_query($mysqli, "SELECT network_id, network_name FROM networks
            WHERE network_client_id = $client_id AND network_id IN ($ids_sql)");
        while ($related_sql && $related = mysqli_fetch_assoc($related_sql)) {
            $related_network_names[intval($related['network_id'])] = (string) $related['network_name'];
        }
    }
    $neighbor_asset_names = [];
    if ($neighbor_asset_ids) {
        $ids_sql = implode(',', array_map('intval', array_keys($neighbor_asset_ids)));
        $related_sql = mysqli_query($mysqli, "SELECT asset_id, asset_name FROM assets
            WHERE asset_client_id = $client_id AND asset_id IN ($ids_sql)");
        while ($related_sql && $related = mysqli_fetch_assoc($related_sql)) {
            $neighbor_asset_names[intval($related['asset_id'])] = (string) $related['asset_name'];
        }
    }
    $neighbor_interface_names = [];
    if ($neighbor_interface_ids) {
        $ids_sql = implode(',', array_map('intval', array_keys($neighbor_interface_ids)));
        $related_sql = mysqli_query($mysqli, "SELECT interface_id, interface_name
            FROM asset_interfaces INNER JOIN assets ON asset_id = interface_asset_id
            WHERE asset_client_id = $client_id AND interface_id IN ($ids_sql)");
        while ($related_sql && $related = mysqli_fetch_assoc($related_sql)) {
            $neighbor_interface_names[intval($related['interface_id'])] = (string) $related['interface_name'];
        }
    }

    foreach ($network_rows as $row) {
        $state = $row['network_observation_state'];
        $network_id = endpointPositiveInt($state['network_id'] ?? 0);
        $neighbor_asset_id = endpointPositiveInt($state['neighbor_asset_id'] ?? 0);
        $neighbor_interface_id = endpointPositiveInt($state['neighbor_interface_id'] ?? 0);
        if (empty($row['network_name']) && isset($related_network_names[$network_id])) {
            $row['network_name'] = $related_network_names[$network_id];
        }
        $row['neighbor_asset_name'] = $neighbor_asset_names[$neighbor_asset_id] ?? '';
        $row['neighbor_interface_name'] = $neighbor_interface_names[$neighbor_interface_id] ?? '';
        if (intval($row['network_observation_active']) === 1) {
            $network_current[] = $row;
        } else {
            $network_history[] = $row;
        }
    }

    $timeline = [];
    $change_sql = mysqli_query($mysqli, "SELECT asset_change_event_id AS id,
        asset_change_event_source AS source, asset_change_event_type AS type,
        asset_change_event_summary AS summary,
        asset_change_event_occurred_at AS occurred_at,
        asset_change_event_ticket_id AS ticket_id,
        asset_change_event_ticket_label AS ticket_label,
        asset_change_event_document_id AS document_id,
        asset_change_event_document_label AS document_label,
        asset_change_event_evidence_id AS evidence_id,
        asset_change_event_evidence_label AS evidence_label,
        event_ticket.ticket_id AS live_ticket_id,
        event_document.document_id AS live_document_id,
        evidence_ticket.ticket_id AS evidence_ticket_id
        FROM asset_change_events
        LEFT JOIN tickets event_ticket
            ON event_ticket.ticket_id = asset_change_event_ticket_id
            AND event_ticket.ticket_client_id = asset_change_event_client_id
        LEFT JOIN documents event_document
            ON event_document.document_id = asset_change_event_document_id
            AND event_document.document_client_id = asset_change_event_client_id
        LEFT JOIN task_evidence event_evidence
            ON event_evidence.task_evidence_id = asset_change_event_evidence_id
        LEFT JOIN tasks evidence_task
            ON evidence_task.task_id = event_evidence.task_evidence_task_id
        LEFT JOIN tickets evidence_ticket
            ON evidence_ticket.ticket_id = evidence_task.task_ticket_id
            AND evidence_ticket.ticket_client_id = asset_change_event_client_id
        WHERE asset_change_event_asset_id = $asset_id
        AND asset_change_event_client_id = $client_id
        AND asset_change_event_canonical = 1
        ORDER BY asset_change_event_occurred_at DESC, asset_change_event_id DESC
        LIMIT 100");
    while ($change_sql && $row = mysqli_fetch_assoc($change_sql)) {
        $timeline[] = $row;
    }
    $history_sql = mysqli_query($mysqli, "SELECT asset_history_id AS id,
        'itflow' AS source, 'lifecycle' AS type,
        asset_history_description AS summary,
        asset_history_created_at AS occurred_at,
        0 AS ticket_id, NULL AS ticket_label, 0 AS document_id, NULL AS document_label,
        0 AS evidence_id, NULL AS evidence_label, 0 AS live_ticket_id,
        0 AS live_document_id, 0 AS evidence_ticket_id
        FROM asset_history WHERE asset_history_asset_id = $asset_id
        ORDER BY asset_history_created_at DESC, asset_history_id DESC LIMIT 100");
    while ($history_sql && $row = mysqli_fetch_assoc($history_sql)) {
        $timeline[] = $row;
    }
    usort($timeline, static fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
    $timeline = array_slice($timeline, 0, 100);

    $evidence = [];
    $evidence_sql = mysqli_query($mysqli, "SELECT task_evidence_id, task_evidence_type,
        task_evidence_note, task_evidence_url, task_evidence_created_at,
        task_name, tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject
        FROM task_evidence
        INNER JOIN tasks ON task_id = task_evidence_task_id
        INNER JOIN tickets ON tickets.ticket_id = task_ticket_id
        WHERE tickets.ticket_client_id = $client_id
        AND (tickets.ticket_asset_id = $asset_id OR EXISTS (
            SELECT 1 FROM ticket_assets
            WHERE ticket_assets.ticket_id = tickets.ticket_id
            AND ticket_assets.asset_id = $asset_id
        ))
        ORDER BY task_evidence_created_at DESC, task_evidence_id DESC LIMIT 50");
    while ($evidence_sql && $row = mysqli_fetch_assoc($evidence_sql)) {
        $evidence[] = $row;
    }

    $interfaces = endpointAssetInterfaceRows($asset_id);
    $related_tickets = [];
    $ticket_sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix,
        ticket_number, ticket_subject, ticket_status, ticket_created_at,
        ticket_resolved_at
        FROM tickets
        WHERE ticket_client_id = $client_id
        AND (ticket_asset_id = $asset_id OR EXISTS (
            SELECT 1 FROM ticket_assets
            WHERE ticket_assets.ticket_id = tickets.ticket_id
            AND ticket_assets.asset_id = $asset_id
        ))
        ORDER BY ticket_number DESC, ticket_id DESC LIMIT 100");
    if (!$ticket_sql) {
        throw new RuntimeException('Could not load endpoint-related tickets');
    }
    while ($row = mysqli_fetch_assoc($ticket_sql)) {
        $related_tickets[] = $row;
    }

    $related_documentation = [];
    $document_sql = mysqli_query($mysqli, "SELECT documents.document_id,
        document_name, document_description, document_created_at,
        document_updated_at
        FROM asset_documents
        INNER JOIN documents ON documents.document_id = asset_documents.document_id
        WHERE asset_documents.asset_id = $asset_id
        AND document_client_id = $client_id
        AND document_archived_at IS NULL
        ORDER BY document_name ASC, documents.document_id ASC LIMIT 100");
    if (!$document_sql) {
        throw new RuntimeException('Could not load endpoint-related documentation');
    }
    while ($row = mysqli_fetch_assoc($document_sql)) {
        $related_documentation[] = $row;
    }

    $record = [
        'summary' => endpointBuildUnifiedSummary($asset, $states),
        'sources' => $states,
        'identities' => $identities,
        'interfaces' => $interfaces,
        'network_current' => $network_current,
        'network_history' => $network_history,
        'timeline' => $timeline,
        'evidence' => $evidence,
        'related_tickets' => $related_tickets,
        'related_documentation' => $related_documentation,
    ];
    $contract_violations = endpointUnifiedRecordContractViolations($record);
    if ($contract_violations) {
        throw new RuntimeException(
            'Unified endpoint record contract failed: ' . implode(', ', $contract_violations)
        );
    }
    return $record;
}
