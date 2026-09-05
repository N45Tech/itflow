<?php

// Scoped full-sync ingestion for external device inventory publishers.

function deviceSourceName($value): string
{
    $source = integrationIdentitySource($value);
    if (!in_array($source, ['intune', 'entra', 'sentinelone'], true)) {
        throw new InvalidArgumentException('Device source must be Intune, Entra, or SentinelOne');
    }
    return $source;
}

function deviceSourceScopeId($value): string
{
    if (is_array($value) || is_object($value) || is_resource($value)) {
        throw new InvalidArgumentException('Device source scope is invalid');
    }
    $scope_id = integrationIdentityLimitText($value, 255);
    if ($scope_id === '' || preg_match('/[\x00-\x1f\x7f]/', $scope_id)) {
        throw new InvalidArgumentException('Device source scope is required');
    }
    return $scope_id;
}

function deviceSourceCycleId($value): string
{
    if (is_array($value) || is_object($value) || is_resource($value)) {
        throw new InvalidArgumentException('Device source cycle id is invalid');
    }
    $cycle_id = integrationIdentityLimitText($value, 255);
    if ($cycle_id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,254}$/', $cycle_id)) {
        throw new InvalidArgumentException('Device source cycle id is invalid');
    }
    return $cycle_id;
}

function deviceSourceCycleStartedAt($value): string
{
    $started_at = integrationIdentityDateTime($value);
    $timestamp = strtotime($started_at);
    if ($timestamp > time() + 300) {
        throw new InvalidArgumentException('Device source cycle timestamp is in the future');
    }
    if ($timestamp < time() - (48 * 60 * 60)) {
        throw new InvalidArgumentException('Device source cycle is older than 48 hours');
    }
    return $started_at;
}

function deviceSourceRedactError($value): string
{
    if (is_array($value) || is_object($value) || is_resource($value)) {
        $value = 'The source adapter returned a non-text error.';
    }
    $message = (string) $value;
    $patterns = [
        '/\b(Bearer|Basic|ApiToken)\s+[^\s,;]+/i' => '$1 [redacted]',
        '/([?&](?:access_token|api[_-]?key|authorization|client_secret|code|password|refresh_token|secret|token)=)[^&\s]*/i'
            => '$1[redacted]',
        '/(["\'](?:access_token|api[_-]?key|authorization|client_secret|password|refresh_token|secret|token)["\']\s*:\s*["\'])[^"\']*/i'
            => '$1[redacted]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $message = (string) preg_replace($pattern, $replacement, $message);
    }
    return integrationIdentityLimitText($message, 2000);
}

function deviceSourcePositiveInt($value, string $label): int
{
    $number = endpointPositiveInt($value);
    if ($number < 1) {
        throw new InvalidArgumentException("Device source $label must be a positive integer");
    }
    return $number;
}

function deviceSourceAssertClientAccess(int $client_id): void
{
    if (function_exists('apiUserCanAccessClient') && !apiUserCanAccessClient($client_id)) {
        throw new RuntimeException('The API user cannot access the mapped device-source client');
    }
}

function deviceSourceAssetInput($value): array
{
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        throw new InvalidArgumentException('Device source asset must be a JSON object');
    }
    $allowed = [
        'id', 'name', 'description', 'type', 'make', 'model', 'serial', 'os',
        'uri', 'status', 'notes', 'ip', 'mac',
    ];
    return array_intersect_key($value, array_flip($allowed));
}

function deviceSourceMappingMetadata(array $input, string $cycle_id, string $scope_id): array
{
    $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
    $allowed = [
        'scope_kind', 'tenant_id', 'tenant_name', 'site_id', 'site_name',
        'source_status', 'management_state',
    ];
    $metadata = array_intersect_key($metadata, array_flip($allowed));
    $metadata['scope_id'] = $scope_id;
    $metadata['cycle_id'] = $cycle_id;
    return integrationIdentityNormalizeSnapshot($metadata);
}

function deviceSourceSnapshotFacts(string $source, string $external_id, array $facts, array $interfaces): array
{
    endpointValidateNetworkObservationBounds($interfaces);
    $snapshot = endpointSourceStateDocument($source, $external_id, $facts)['facts'];
    $snapshot['network_interfaces'] = [];
    foreach ($interfaces as $interface) {
        if (!is_array($interface) || ($interface !== [] && array_is_list($interface))) {
            throw new InvalidArgumentException('Every device source network interface must be a JSON object');
        }
        $snapshot['network_interfaces'][] = endpointNormalizeNetworkObservation($interface)['state'];
    }
    return $snapshot;
}

/**
 * Enabling create_asset is a scoped post-burn-in decision, not permission to
 * guess. Existing deterministic matches remain usable; only a genuinely new
 * asset must pass this gate.
 */
function deviceSourceAssertAutoCreateSafety(string $source, string $scope_id,
    int $client_id, int $location_id, string $external_id, string $external_name,
    string $source_status, array $asset): void
{
    global $mysqli;

    $identity = integrationIdentityFindMapping($source, 'device', $external_id);
    if ($identity && intval($identity['automation_mapping_asset_id'] ?? 0) > 0) {
        if (intval($identity['automation_mapping_client_id'] ?? 0) === $client_id
            && empty($identity['automation_mapping_deleted_at'])
            && in_array((string) ($identity['automation_mapping_external_parent_id'] ?? ''),
                ['', $scope_id], true)
            && in_array((string) ($identity['automation_mapping_state'] ?? ''),
                ['automatic', 'confirmed', 'stale'], true)) {
            return;
        }
        throw new AutomationConflictException('The existing source identity requires technician review');
    }
    $matches = automationFindAsset($asset, $client_id, $location_id);
    if ($matches) {
        // The resolver will bind one exact match or reject an ambiguity. This
        // gate is only for the path that would INSERT a brand-new asset.
        return;
    }

    $scope = integrationIdentityFindMapping($source, 'sync_scope', $scope_id);
    if (!$scope
        || intval($scope['automation_mapping_client_id'] ?? 0) !== $client_id
        || !empty($scope['automation_mapping_deleted_at'])
        || (string) ($scope['automation_mapping_state'] ?? '') !== 'automatic'
        || empty($scope['automation_mapping_last_success_at'])
        || trim((string) ($scope['automation_mapping_last_error'] ?? '')) !== '') {
        throw new AutomationConflictException(
            'Automatic asset creation requires a clean completed burn-in cycle for this exact source scope and client'
        );
    }
    $scope_metadata = deviceSourceScopeMetadata($scope);
    if (empty($scope_metadata['last_completed_cycle_id'])
        || empty($scope_metadata['last_completed_cycle_started_at'])) {
        throw new AutomationConflictException('Automatic asset creation requires durable burn-in evidence');
    }
    if ($source_status !== 'active'
        || intval($asset['id'] ?? 0) > 0
        || automationNormalizeName($asset['name'] ?? '') === ''
        || automationNormalizeName($asset['name'] ?? '') !== automationNormalizeName($external_name)) {
        throw new AutomationConflictException('A new source asset requires one stable, matching device name');
    }
    if ($location_id > 0 && !automationLocationRow($location_id, $client_id)) {
        throw new AutomationConflictException('The automatic-creation location is outside the mapped client');
    }
    if ($identity && (intval($identity['automation_mapping_client_id'] ?? 0) !== $client_id
        || !in_array((string) ($identity['automation_mapping_external_parent_id'] ?? ''), ['', $scope_id], true)
        || in_array((string) ($identity['automation_mapping_state'] ?? ''),
            ['conflicting', 'ignored', 'retired'], true))) {
        throw new AutomationConflictException('The existing source identity requires technician review');
    }

    $source_sql = integrationIdentityDbEscape($source);
    $external_sql = integrationIdentityDbEscape($external_id);
    $foreign_sql = mysqli_query($mysqli, "SELECT COUNT(*) FROM asset_endpoint_states
        WHERE endpoint_state_source = '$source_sql'
        AND endpoint_state_external_id = '$external_sql'
        AND endpoint_state_client_id <> $client_id");
    if (!$foreign_sql) {
        throw new RuntimeException('Could not verify source-device tenant uniqueness');
    }
    $foreign = mysqli_fetch_row($foreign_sql);
    if (intval($foreign[0] ?? 0) > 0) {
        throw new AutomationConflictException('The source device is already present under another client');
    }
}

/**
 * Resolve a source identity, preserve a redacted normalized snapshot, and publish
 * canonical endpoint posture. An unresolved identity remains visible for review
 * without blocking the rest of a tenant/site full sync.
 */
function deviceSourcePublish(array $input): array
{
    global $mysqli;

    $source = deviceSourceName($input['source'] ?? '');
    $scope_id = deviceSourceScopeId($input['scope_id'] ?? '');
    $cycle_id = deviceSourceCycleId($input['cycle_id'] ?? '');
    $cycle_started_at = deviceSourceCycleStartedAt($input['cycle_started_at'] ?? null);
    $client_id = deviceSourcePositiveInt($input['client_id'] ?? 0, 'client id');
    deviceSourceAssertClientAccess($client_id);
    $location_id = endpointPositiveInt($input['location_id'] ?? 0);
    $external_id = endpointExternalId($input['external_id'] ?? '');
    $external_name = endpointLimitText($input['external_name'] ?? '', 255);
    $observed_at = endpointObservationDateTime($input['observed_at'] ?? null);
    $status = endpointNormalizeSourceStatus($input['status'] ?? 'active');
    $facts = is_array($input['facts'] ?? null) ? $input['facts'] : [];
    if ($facts !== [] && array_is_list($facts)) {
        throw new InvalidArgumentException('Device source facts must be a JSON object');
    }
    $interfaces = is_array($input['network_interfaces'] ?? null) ? $input['network_interfaces'] : [];
    if (!array_is_list($interfaces)) {
        throw new InvalidArgumentException('Device source network_interfaces must be a JSON array');
    }
    $asset = deviceSourceAssetInput($input['asset'] ?? []);
    $create_asset = filter_var($input['create_asset'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $metadata = deviceSourceMappingMetadata($input, $cycle_id, $scope_id);
    $snapshot_facts = deviceSourceSnapshotFacts($source, $external_id, $facts, $interfaces);
    $mapping_last_seen_at = $snapshot_facts['last_seen_at'] ?? $observed_at;

    $scope_lock_held = false;
    if ($create_asset) {
        if (!integrationIdentityAcquireLock($source, 'sync_scope', $scope_id)) {
            throw new RuntimeException('Could not obtain the automatic-creation source-scope lock');
        }
        $scope_lock_held = true;
    }
    try {
        if ($create_asset) {
            deviceSourceAssertAutoCreateSafety(
                $source, $scope_id, $client_id, $location_id,
                $external_id, $external_name, $status, $asset
            );
        }

        // The resolver owns its identity locks and transaction. If the subsequent
        // endpoint transaction fails, the durable mapping is safe to replay.
        $resolved = automationResolveIdentity([
            'source' => $source,
            'entity_type' => 'device',
            'external_id' => $external_id,
            'external_parent_id' => $scope_id,
            'external_name' => $external_name,
            'last_seen_at' => $mapping_last_seen_at,
            'client' => ['id' => $client_id],
            'location' => $location_id > 0 ? ['id' => $location_id] : [],
            'asset' => $asset,
            'options' => [
                'create_client' => false,
                'create_location' => false,
                'create_asset' => $create_asset,
            ],
            'metadata' => $metadata,
        ]);
        $resolved_client_id = intval($resolved['client_id'] ?? 0);
        $asset_id = intval($resolved['asset_id'] ?? 0);
        if ($resolved_client_id !== $client_id) {
            throw new RuntimeException('The source identity resolved to a different client');
        }

        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the device-source publication transaction');
        }
        try {
            $identity_state = $asset_id > 0 ? 'automatic' : 'unresolved';
            $last_error = $asset_id > 0 ? '' : 'The source device is not mapped to an ITFlow asset.';
            $mapping = integrationIdentityUpsertMapping([
                'source' => $source,
                'entity_type' => 'device',
                'external_id' => $external_id,
                'external_parent_id' => $scope_id,
                'external_name' => $external_name,
                'client_id' => $client_id,
                'location_id' => $location_id,
                'asset_id' => $asset_id,
                'state' => $status === 'retired' ? 'retired' : $identity_state,
                'strategy' => (string) ($resolved['strategy'] ?? 'unresolved'),
                'confidence' => $asset_id > 0 ? 100 : 0,
                'last_seen_at' => $mapping_last_seen_at,
                'last_error' => $last_error,
                'metadata' => $metadata,
            ]);
            $mapping_state = (string) ($mapping['automation_mapping_state'] ?? 'unresolved');
            $mapping_trusted = empty($mapping['automation_mapping_deleted_at'])
                && in_array($mapping_state, ['automatic', 'confirmed'], true);
            $mapped_asset_id = intval($mapping['automation_mapping_asset_id'] ?? 0);
            if (intval($mapping['automation_mapping_client_id'] ?? 0) !== $client_id
                || ($asset_id > 0 && (!$mapping_trusted || $mapped_asset_id !== $asset_id))
                || ($asset_id === 0 && $mapping_trusted && $mapped_asset_id > 0)) {
                throw new RuntimeException('The source identity binding changed during publication');
            }

            $snapshot = integrationIdentityRecordSnapshot([
                'source' => $source,
                'entity_type' => 'device',
                'external_id' => $external_id,
                'client_id' => $client_id,
                'asset_id' => $asset_id,
                'observed_at' => $observed_at,
                'facts' => $snapshot_facts,
            ]);

            $delivery = null;
            if ($status === 'retired') {
                integrationIdentityRetireMapping(
                    $source,
                    'device',
                    $external_id,
                    ucfirst($source) . ' reported the device as retired.'
                );
            } elseif ($asset_id > 0) {
                $delivery = endpointReconcileAssetSourceUnlocked([
                    'asset_id' => $asset_id,
                    'client_id' => $client_id,
                    'source' => $source,
                    'external_id' => $external_id,
                    'status' => $status,
                    'observed_at' => $observed_at,
                    'facts' => $facts,
                    'network_interfaces' => $interfaces,
                ]);
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit device-source publication');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }

        return [
            'action' => 'publish',
            'source' => $source,
            'scope_id' => $scope_id,
            'cycle_id' => $cycle_id,
            'cycle_started_at' => $cycle_started_at,
            'client_id' => $client_id,
            'asset_id' => $asset_id,
            'external_id' => $external_id,
            'status' => $status,
            'mapped' => $asset_id > 0,
            'snapshot_changed' => !empty($snapshot['changed']),
            'state_changed' => !empty($delivery['state']['changed']),
            'stale_delivery' => !empty($delivery['state']['stale']) || !empty($delivery['network']['stale']),
        ];
    } finally {
        if ($scope_lock_held) {
            integrationIdentityReleaseLock($source, 'sync_scope', $scope_id);
        }
    }
}

function deviceSourceScopeMetadata(?array $mapping): array
{
    if (!$mapping || empty($mapping['automation_mapping_metadata'])) {
        return [];
    }
    $metadata = json_decode((string) $mapping['automation_mapping_metadata'], true);
    return is_array($metadata) ? integrationIdentityNormalizeSnapshot($metadata) : [];
}

function deviceSourceScopeCounts(string $source, string $scope_id, int $client_id, string $cutoff): array
{
    global $mysqli;
    $source_sql = integrationIdentityDbEscape($source);
    $scope_sql = integrationIdentityDbEscape($scope_id);
    $cutoff_sql = integrationIdentityDbEscape($cutoff);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        COUNT(*) AS total_count,
        SUM(automation_mapping_last_synced_at >= '$cutoff_sql') AS seen_count,
        SUM(automation_mapping_last_synced_at >= '$cutoff_sql'
            AND automation_mapping_asset_id > 0) AS mapped_count
        FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = 'device'
        AND automation_mapping_external_parent_id = '$scope_sql'
        AND automation_mapping_client_id = $client_id
        AND automation_mapping_deleted_at IS NULL"));
    if (!$row) {
        throw new RuntimeException('Could not calculate device source coverage');
    }
    return [
        'total' => intval($row['total_count'] ?? 0),
        'seen' => intval($row['seen_count'] ?? 0),
        'mapped' => intval($row['mapped_count'] ?? 0),
    ];
}

/**
 * Finish one full source scope. Only rows in the exact tenant/site and client
 * whose sync watermark predates this cycle can be retired.
 */
function deviceSourceComplete(array $input): array
{
    global $mysqli;

    $source = deviceSourceName($input['source'] ?? '');
    $scope_id = deviceSourceScopeId($input['scope_id'] ?? '');
    $cycle_id = deviceSourceCycleId($input['cycle_id'] ?? '');
    $cycle_started_at = deviceSourceCycleStartedAt($input['cycle_started_at'] ?? null);
    $client_id = deviceSourcePositiveInt($input['client_id'] ?? 0, 'client id');
    deviceSourceAssertClientAccess($client_id);
    $reported_count = endpointPositiveInt($input['reported_count'] ?? 0);
    if ((string) ($input['reported_count'] ?? '0') !== (string) $reported_count
        && !is_int($input['reported_count'] ?? null)) {
        throw new InvalidArgumentException('Device source reported_count must be a non-negative integer');
    }
    $guard_percent = max(0, min(100, intval($input['retirement_guard_percent'] ?? 50)));
    $allow_empty = filter_var($input['allow_empty'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!integrationIdentityAcquireLock($source, 'sync_scope', $scope_id)) {
        throw new RuntimeException('Could not obtain the device source scope lock');
    }
    try {
        mysqli_begin_transaction($mysqli);
        $scope_mapping = integrationIdentityFindMapping($source, 'sync_scope', $scope_id);
        if ($scope_mapping && intval($scope_mapping['automation_mapping_client_id'] ?? 0) !== $client_id) {
            throw new RuntimeException('The device source scope is already bound to a different client');
        }
        $previous_metadata = deviceSourceScopeMetadata($scope_mapping);
        $previous_cycle = (string) ($previous_metadata['last_completed_cycle_started_at'] ?? '');
        if ($previous_cycle !== '' && strcmp($cycle_started_at, $previous_cycle) <= 0) {
            mysqli_commit($mysqli);
            return [
                'action' => 'complete',
                'source' => $source,
                'scope_id' => $scope_id,
                'cycle_id' => $cycle_id,
                'client_id' => $client_id,
                'stale_cycle' => true,
                'retired_count' => 0,
            ];
        }

        $before = deviceSourceScopeCounts($source, $scope_id, $client_id, $cycle_started_at);
        if ($before['seen'] !== $reported_count) {
            throw new RuntimeException(
                "Device source completion counted {$before['seen']} scoped records but the source reported "
                . "$reported_count; refusing to publish incomplete coverage"
            );
        }
        $minimum_safe_count = $before['total'] > 0
            ? (int) floor($before['total'] * ($guard_percent / 100))
            : 0;
        if ((!$allow_empty && $before['total'] > 0 && $reported_count === 0)
            || ($guard_percent > 0 && $reported_count < $minimum_safe_count)) {
            throw new RuntimeException(
                "Device source retirement guard blocked $reported_count reported records against "
                . "{$before['total']} current records"
            );
        }

        $source_sql = integrationIdentityDbEscape($source);
        $scope_sql = integrationIdentityDbEscape($scope_id);
        $cutoff_sql = integrationIdentityDbEscape($cycle_started_at);
        $candidates = mysqli_query($mysqli, "SELECT automation_mapping_external_id
            FROM automation_entity_mappings
            WHERE automation_mapping_source = '$source_sql'
            AND automation_mapping_entity_type = 'device'
            AND automation_mapping_external_parent_id = '$scope_sql'
            AND automation_mapping_client_id = $client_id
            AND automation_mapping_deleted_at IS NULL
            AND automation_mapping_last_synced_at < '$cutoff_sql'
            ORDER BY automation_mapping_asset_id, automation_mapping_external_id");
        if (!$candidates) {
            throw new RuntimeException('Could not identify missing scoped device identities');
        }
        $external_ids = [];
        while ($candidate = mysqli_fetch_assoc($candidates)) {
            $external_ids[] = (string) $candidate['automation_mapping_external_id'];
        }

        $retired_count = 0;
        foreach ($external_ids as $external_id) {
            $fresh = integrationIdentityFindMapping($source, 'device', $external_id);
            if (!$fresh
                || !empty($fresh['automation_mapping_deleted_at'])
                || intval($fresh['automation_mapping_client_id'] ?? 0) !== $client_id
                || (string) ($fresh['automation_mapping_external_parent_id'] ?? '') !== $scope_id
                || strcmp((string) ($fresh['automation_mapping_last_synced_at'] ?? ''), $cycle_started_at) >= 0) {
                continue;
            }
            $retired_count += integrationIdentityRetireMapping(
                $source,
                'device',
                $external_id,
                ucfirst($source) . " device was absent from full sync $cycle_id."
            ) ? 1 : 0;
        }

        $after = deviceSourceScopeCounts($source, $scope_id, $client_id, $cycle_started_at);
        $coverage = $after['seen'] > 0
            ? round(($after['mapped'] / $after['seen']) * 100, 2)
            : 100.0;
        $metadata = [
            'scope_id' => $scope_id,
            'last_completed_cycle_id' => $cycle_id,
            'last_completed_cycle_started_at' => $cycle_started_at,
            'reported_count' => $reported_count,
            'seen_count' => $after['seen'],
            'mapped_count' => $after['mapped'],
            'unmapped_count' => max(0, $after['seen'] - $after['mapped']),
            'retired_count' => $retired_count,
            'coverage_percent' => $coverage,
            'retirement_guard_percent' => $guard_percent,
        ];
        integrationIdentityUpsertMapping([
            'source' => $source,
            'entity_type' => 'sync_scope',
            'external_id' => $scope_id,
            'external_parent_id' => $scope_id,
            'external_name' => endpointLimitText($input['scope_name'] ?? $scope_id, 255),
            'client_id' => $client_id,
            'state' => 'automatic',
            'strategy' => 'source_scope_full_sync',
            'confidence' => $coverage,
            'last_seen_at' => date('Y-m-d H:i:s'),
            'last_error' => '',
            'metadata' => $metadata,
        ]);
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        if (n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, 'sync_scope', $scope_id);
    }

    return [
        'action' => 'complete',
        'source' => $source,
        'scope_id' => $scope_id,
        'cycle_id' => $cycle_id,
        'client_id' => $client_id,
        'stale_cycle' => false,
        'reported_count' => $reported_count,
        'mapped_count' => $after['mapped'],
        'unmapped_count' => max(0, $after['seen'] - $after['mapped']),
        'retired_count' => $retired_count,
        'coverage_percent' => $coverage,
    ];
}

function deviceSourceRecordFailure(array $input): array
{
    global $mysqli;

    $source = deviceSourceName($input['source'] ?? '');
    $scope_id = deviceSourceScopeId($input['scope_id'] ?? '');
    $cycle_id = deviceSourceCycleId($input['cycle_id'] ?? '');
    $cycle_started_at = deviceSourceCycleStartedAt($input['cycle_started_at'] ?? null);
    $client_id = deviceSourcePositiveInt($input['client_id'] ?? 0, 'client id');
    deviceSourceAssertClientAccess($client_id);
    $error = deviceSourceRedactError($input['error'] ?? 'The source adapter failed.');

    if (!integrationIdentityAcquireLock($source, 'sync_scope', $scope_id)) {
        throw new RuntimeException('Could not obtain the device source scope lock');
    }
    try {
        mysqli_begin_transaction($mysqli);
        $mapping = integrationIdentityFindMapping($source, 'sync_scope', $scope_id);
        if ($mapping && intval($mapping['automation_mapping_client_id'] ?? 0) !== $client_id) {
            throw new RuntimeException('The device source scope is already bound to a different client');
        }
        $metadata = deviceSourceScopeMetadata($mapping);
        $successful_cycle = (string) ($metadata['last_completed_cycle_started_at'] ?? '');
        if ($successful_cycle !== '' && strcmp($cycle_started_at, $successful_cycle) <= 0) {
            mysqli_commit($mysqli);
            return [
                'action' => 'failure', 'source' => $source, 'scope_id' => $scope_id,
                'client_id' => $client_id, 'stale_cycle' => true,
            ];
        }
        $metadata['scope_id'] = $scope_id;
        $metadata['last_failed_cycle_id'] = $cycle_id;
        $metadata['last_failed_cycle_started_at'] = $cycle_started_at;
        $metadata['last_failure_at'] = date('Y-m-d H:i:s');
        integrationIdentityUpsertMapping([
            'source' => $source,
            'entity_type' => 'sync_scope',
            'external_id' => $scope_id,
            'external_parent_id' => $scope_id,
            'external_name' => endpointLimitText($input['scope_name'] ?? $scope_id, 255),
            'client_id' => $client_id,
            'state' => 'stale',
            'strategy' => 'source_scope_full_sync',
            'confidence' => (float) ($mapping['automation_mapping_confidence'] ?? 0),
            'last_seen_at' => date('Y-m-d H:i:s'),
            'last_error' => $error,
            'metadata' => $metadata,
        ]);
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        if (n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, 'sync_scope', $scope_id);
    }

    return [
        'action' => 'failure',
        'source' => $source,
        'scope_id' => $scope_id,
        'client_id' => $client_id,
        'stale_cycle' => false,
        'error' => $error,
    ];
}

function deviceSourceHealthRows(int $client_id = 0, string $source = ''): array
{
    global $mysqli;
    if ($client_id > 0) {
        deviceSourceAssertClientAccess($client_id);
    }
    $scope_sql = function_exists('apiClientScopeSql')
        ? apiClientScopeSql('automation_mapping_client_id')
        : '';
    $client_sql = $client_id > 0 ? "AND automation_mapping_client_id = $client_id" : '';
    $source_sql = '';
    if ($source !== '') {
        $source_sql = "AND automation_mapping_source = '"
            . integrationIdentityDbEscape(deviceSourceName($source)) . "'";
    }
    $query = mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
        WHERE automation_mapping_entity_type = 'sync_scope'
        $client_sql $source_sql $scope_sql
        ORDER BY automation_mapping_client_id, automation_mapping_source,
            automation_mapping_external_id");
    if (!$query) {
        throw new RuntimeException('Could not load device source health');
    }
    $rows = [];
    while ($mapping = mysqli_fetch_assoc($query)) {
        $metadata = deviceSourceScopeMetadata($mapping);
        $rows[] = [
            'source' => (string) $mapping['automation_mapping_source'],
            'scope_id' => (string) $mapping['automation_mapping_external_id'],
            'scope_name' => (string) ($mapping['automation_mapping_external_name'] ?? ''),
            'client_id' => intval($mapping['automation_mapping_client_id']),
            'state' => (string) $mapping['automation_mapping_state'],
            'coverage_percent' => (float) ($metadata['coverage_percent']
                ?? $mapping['automation_mapping_confidence'] ?? 0),
            'reported_count' => intval($metadata['reported_count'] ?? 0),
            'mapped_count' => intval($metadata['mapped_count'] ?? 0),
            'unmapped_count' => intval($metadata['unmapped_count'] ?? 0),
            'retired_count' => intval($metadata['retired_count'] ?? 0),
            'last_cycle_id' => (string) ($metadata['last_completed_cycle_id'] ?? ''),
            'last_cycle_started_at' => (string) ($metadata['last_completed_cycle_started_at'] ?? ''),
            'last_success_at' => (string) ($mapping['automation_mapping_last_success_at'] ?? ''),
            'last_failure_at' => (string) ($metadata['last_failure_at'] ?? ''),
            'last_error' => deviceSourceRedactError($mapping['automation_mapping_last_error'] ?? ''),
        ];
    }
    return $rows;
}
