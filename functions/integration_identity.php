<?php

// Durable, source-neutral identity mappings and normalized source snapshots.

function integrationIdentityLimitText($value, int $length): string
{
    return mb_substr(trim((string) $value), 0, $length);
}

function integrationIdentitySource($value): string
{
    $source = strtolower(integrationIdentityLimitText($value, 40));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $source)) {
        throw new InvalidArgumentException('Identity source is invalid');
    }
    return $source;
}

function integrationIdentityEntityType($value): string
{
    $entity_type = strtolower(integrationIdentityLimitText($value, 40));
    if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $entity_type)) {
        throw new InvalidArgumentException('Identity entity type is invalid');
    }
    return $entity_type;
}

function integrationIdentityExternalId($value): string
{
    $external_id = integrationIdentityLimitText($value, 255);
    if ($external_id === '') {
        throw new InvalidArgumentException('Identity external id is required');
    }
    return $external_id;
}

function integrationIdentityState($value): string
{
    $state = strtolower(integrationIdentityLimitText($value, 20));
    $allowed = ['unresolved', 'automatic', 'confirmed', 'suggested', 'conflicting', 'stale', 'ignored', 'retired'];
    if (!in_array($state, $allowed, true)) {
        throw new InvalidArgumentException('Identity state is invalid');
    }
    return $state;
}

function integrationIdentityConfidence($value): float
{
    if (!is_numeric($value)) {
        return 0.0;
    }
    return round(max(0, min(100, (float) $value)), 2);
}

function integrationIdentityDateTime($value, bool $default_now = true): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return $default_now ? date('Y-m-d H:i:s') : null;
    }

    try {
        $date = new DateTimeImmutable((string) $value);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Identity timestamp is invalid');
    }

    return $date->format('Y-m-d H:i:s');
}

function integrationIdentitySensitiveKey($key): bool
{
    $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key);
    $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
    $key = trim($key, '_');
    $exact = [
        'api_key', 'apikey', 'authorization', 'cookie', 'credentials', 'credential',
        'client_secret', 'private_key', 'access_token', 'refresh_token',
        'last_logged_in_user', 'lastloggedinuser',
    ];

    if (in_array($key, $exact, true)) {
        return true;
    }

    foreach (['_password', '_passwd', '_secret', '_token', '_api_key', '_credential'] as $suffix) {
        if (str_ends_with($key, $suffix)) {
            return true;
        }
    }

    return in_array($key, ['password', 'passwd', 'secret', 'token'], true);
}

function integrationIdentityArrayIsList(array $value): bool
{
    $position = 0;
    foreach ($value as $key => $_) {
        if ($key !== $position++) {
            return false;
        }
    }
    return true;
}

/**
 * Remove secret-bearing keys and canonicalize object key order before hashing.
 * Lists preserve their source order because order can carry source meaning.
 */
function integrationIdentityNormalizeSnapshot($value)
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && integrationIdentitySensitiveKey($key)) {
                continue;
            }
            $normalized[$key] = integrationIdentityNormalizeSnapshot($item);
        }
        if (!integrationIdentityArrayIsList($normalized)) {
            ksort($normalized, SORT_STRING);
        }
        return $normalized;
    }

    if (is_float($value) && !is_finite($value)) {
        return null;
    }

    if (is_resource($value)) {
        return null;
    }

    return $value;
}

function integrationIdentitySnapshotDocument($facts): array
{
    $normalized = integrationIdentityNormalizeSnapshot($facts);
    $payload = json_encode(
        $normalized,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($payload === false) {
        throw new RuntimeException('Could not encode the normalized identity snapshot');
    }

    return [
        'facts' => $normalized,
        'payload' => $payload,
        'hash' => hash('sha256', $payload),
    ];
}

/**
 * Preserve existing non-zero object bindings. An automated caller may fill an
 * empty binding, but it may not move an identity to another object or client.
 */
function integrationIdentityMergeBindings(array $existing, array $incoming): array
{
    $bindings = [];
    $conflicts = [];
    foreach (['client_id', 'location_id', 'asset_id', 'domain_id'] as $key) {
        $current = max(0, intval($existing[$key] ?? 0));
        $proposed = max(0, intval($incoming[$key] ?? 0));
        if ($current > 0 && $proposed > 0 && $current !== $proposed) {
            $bindings[$key] = $current;
            $conflicts[$key] = ['existing' => $current, 'proposed' => $proposed];
        } else {
            $bindings[$key] = $current ?: $proposed;
        }
    }

    return ['bindings' => $bindings, 'conflicts' => $conflicts];
}

function integrationIdentityDbEscape($value): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $value);
}

/**
 * Report whether this connection currently owns an explicit transaction.
 *
 * mysqli does not expose its internal server-status flags as a portable PHP
 * API. MariaDB and supported MySQL releases expose the same state through the
 * read-only session variable, including immediately after START TRANSACTION.
 */
function n45DatabaseTransactionActive(): bool
{
    global $mysqli;

    $result = mysqli_query($mysqli, 'SELECT @@SESSION.in_transaction');
    if (!$result) {
        throw new RuntimeException(
            'Could not inspect the database transaction state: ' . mysqli_error($mysqli)
        );
    }
    $row = mysqli_fetch_row($result);
    mysqli_free_result($result);
    if (!is_array($row) || !array_key_exists(0, $row)) {
        throw new RuntimeException('The database returned no transaction state');
    }

    return intval($row[0]) === 1;
}

function integrationIdentityNullableSql($value): string
{
    return $value === null || $value === ''
        ? 'NULL'
        : "'" . integrationIdentityDbEscape($value) . "'";
}

function integrationIdentityAcquireLock(string $source, string $entity_type, string $external_id): bool
{
    global $mysqli;
    $lock_name = integrationIdentityDbEscape('itflow_identity_' . sha1("$source\0$entity_type\0$external_id"));
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name', 10)"));
    return intval($row[0] ?? 0) === 1;
}

function integrationIdentityReleaseLock(string $source, string $entity_type, string $external_id): void
{
    global $mysqli;
    $lock_name = integrationIdentityDbEscape('itflow_identity_' . sha1("$source\0$entity_type\0$external_id"));
    mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name')");
}

function integrationIdentityFindMapping(string $source, string $entity_type, string $external_id): ?array
{
    global $mysqli;
    $source_sql = integrationIdentityDbEscape(integrationIdentitySource($source));
    $entity_type_sql = integrationIdentityDbEscape(integrationIdentityEntityType($entity_type));
    $external_id_sql = integrationIdentityDbEscape(integrationIdentityExternalId($external_id));
    $sql = mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = '$entity_type_sql'
        AND automation_mapping_external_id = '$external_id_sql' LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function integrationIdentityMappingAuditView(?array $mapping): array
{
    if (!$mapping) {
        return [];
    }
    return [
        'mapping_id' => intval($mapping['automation_mapping_id'] ?? 0),
        'source' => integrationIdentityLimitText($mapping['automation_mapping_source'] ?? '', 40),
        'entity_type' => integrationIdentityLimitText($mapping['automation_mapping_entity_type'] ?? '', 40),
        'external_id' => integrationIdentityLimitText($mapping['automation_mapping_external_id'] ?? '', 255),
        'external_parent_id' => integrationIdentityLimitText($mapping['automation_mapping_external_parent_id'] ?? '', 255),
        'external_name' => integrationIdentityLimitText($mapping['automation_mapping_external_name'] ?? '', 255),
        'client_id' => max(0, intval($mapping['automation_mapping_client_id'] ?? 0)),
        'location_id' => max(0, intval($mapping['automation_mapping_location_id'] ?? 0)),
        'asset_id' => max(0, intval($mapping['automation_mapping_asset_id'] ?? 0)),
        'domain_id' => max(0, intval($mapping['automation_mapping_domain_id'] ?? 0)),
        'strategy' => integrationIdentityLimitText($mapping['automation_mapping_strategy'] ?? '', 40),
        'state' => integrationIdentityLimitText($mapping['automation_mapping_state'] ?? '', 20),
        'confidence' => integrationIdentityConfidence($mapping['automation_mapping_confidence'] ?? 0),
        'last_error' => integrationIdentityLimitText($mapping['automation_mapping_last_error'] ?? '', 4000),
        'confirmed_at' => $mapping['automation_mapping_confirmed_at'] ?? null,
        'deleted_at' => $mapping['automation_mapping_deleted_at'] ?? null,
    ];
}

function integrationIdentityMappingDecisionAction(?array $before, array $after, array $conflicts = []): string
{
    if (!$before) {
        return $conflicts ? 'conflict_detected' : 'discovered';
    }
    if ($conflicts) {
        return 'conflict_detected';
    }
    $before_view = integrationIdentityMappingAuditView($before);
    $after_view = integrationIdentityMappingAuditView($after);
    if (($before_view['state'] ?? '') !== ($after_view['state'] ?? '')) {
        return match ($after_view['state'] ?? '') {
            'confirmed' => 'confirmed',
            'ignored' => 'ignored',
            'retired' => 'retired',
            'stale' => 'marked_stale',
            default => 'state_changed',
        };
    }
    foreach (['client_id', 'location_id', 'asset_id', 'domain_id'] as $binding) {
        if (($before_view[$binding] ?? 0) !== ($after_view[$binding] ?? 0)) {
            return 'matched';
        }
    }
    return 'identity_refreshed';
}

function integrationIdentityRecordDecisionUnlocked(
    ?array $before,
    array $after,
    string $action,
    string $reason = '',
    int $actor_user_id = 0,
    string $batch_key = ''
): int {
    global $mysqli;

    if (!n45DatabaseTransactionActive()) {
        throw new LogicException('Identity mapping decisions require a transaction');
    }
    $after_view = integrationIdentityMappingAuditView($after);
    if (empty($after_view['mapping_id']) || empty($after_view['source'])
        || empty($after_view['entity_type']) || empty($after_view['external_id'])) {
        throw new LogicException('Identity mapping decision is missing its durable identity');
    }
    $before_document = integrationIdentitySnapshotDocument(integrationIdentityMappingAuditView($before));
    $after_document = integrationIdentitySnapshotDocument($after_view);
    $action = strtolower(integrationIdentityLimitText($action, 40));
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $action)) {
        throw new InvalidArgumentException('Identity mapping decision action is invalid');
    }
    $batch_key = strtolower(integrationIdentityLimitText($batch_key, 64));
    if ($batch_key !== '' && !preg_match('/^[a-f0-9]{64}$/', $batch_key)) {
        throw new InvalidArgumentException('Identity mapping decision batch key is invalid');
    }

    $mapping_id = intval($after_view['mapping_id']);
    $source_sql = integrationIdentityDbEscape($after_view['source']);
    $entity_type_sql = integrationIdentityDbEscape($after_view['entity_type']);
    $external_id_sql = integrationIdentityDbEscape($after_view['external_id']);
    $action_sql = integrationIdentityDbEscape($action);
    $before_sql = integrationIdentityDbEscape($before_document['payload']);
    $after_sql = integrationIdentityDbEscape($after_document['payload']);
    $reason_sql = integrationIdentityNullableSql(integrationIdentityLimitText($reason, 1000));
    $actor_user_id = max(0, $actor_user_id);
    $batch_key_sql = integrationIdentityDbEscape($batch_key);
    if (!mysqli_query($mysqli, "INSERT INTO automation_mapping_decisions SET
        automation_mapping_decision_mapping_id = $mapping_id,
        automation_mapping_decision_source = '$source_sql',
        automation_mapping_decision_entity_type = '$entity_type_sql',
        automation_mapping_decision_external_id = '$external_id_sql',
        automation_mapping_decision_action = '$action_sql',
        automation_mapping_decision_before = '$before_sql',
        automation_mapping_decision_after = '$after_sql',
        automation_mapping_decision_reason = $reason_sql,
        automation_mapping_decision_actor_user_id = $actor_user_id,
        automation_mapping_decision_batch_key = '$batch_key_sql'")) {
        throw new RuntimeException('Could not append the identity mapping decision: ' . mysqli_error($mysqli));
    }
    return intval(mysqli_insert_id($mysqli));
}

/**
 * Confirm that every supplied ITFlow object exists and belongs to the same
 * client. A missing client id is safely inferred from a bound object.
 */
function integrationIdentityValidateBindings(array $input): array
{
    global $mysqli;

    $bindings = [
        'client_id' => max(0, intval($input['client_id'] ?? 0)),
        'location_id' => max(0, intval($input['location_id'] ?? 0)),
        'asset_id' => max(0, intval($input['asset_id'] ?? 0)),
        'domain_id' => max(0, intval($input['domain_id'] ?? 0)),
    ];

    $object_clients = [];
    $lookups = [
        'location_id' => ['locations', 'location_id', 'location_client_id'],
        'asset_id' => ['assets', 'asset_id', 'asset_client_id'],
        'domain_id' => ['domains', 'domain_id', 'domain_client_id'],
    ];
    foreach ($lookups as $binding => [$table, $id_column, $client_column]) {
        $object_id = $bindings[$binding];
        if ($object_id === 0) {
            continue;
        }
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT $client_column AS client_id
            FROM $table WHERE $id_column = $object_id LIMIT 1"));
        if (!$row) {
            throw new InvalidArgumentException("Identity $binding does not exist");
        }
        $object_clients[$binding] = intval($row['client_id']);
    }

    foreach ($object_clients as $binding => $object_client_id) {
        if ($object_client_id < 1) {
            continue;
        }
        if ($bindings['client_id'] === 0) {
            $bindings['client_id'] = $object_client_id;
        } elseif ($bindings['client_id'] !== $object_client_id) {
            throw new InvalidArgumentException("Identity $binding belongs to a different client");
        }
    }

    if ($bindings['client_id'] > 0) {
        $client_id = $bindings['client_id'];
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM clients
            WHERE client_id = $client_id AND client_archived_at IS NULL"));
        if (intval($row[0] ?? 0) !== 1) {
            throw new InvalidArgumentException('Identity client does not exist or is archived');
        }
    }
    if ($bindings['asset_id'] > 0) {
        $asset_id = $bindings['asset_id'];
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM assets
            WHERE asset_id = $asset_id AND asset_archived_at IS NULL"));
        if (intval($row[0] ?? 0) !== 1) {
            throw new InvalidArgumentException('Identity asset does not exist or is archived');
        }
    }

    return $bindings;
}

function integrationIdentityConflictMessage(array $conflicts): string
{
    $parts = [];
    foreach ($conflicts as $binding => $values) {
        $label = str_replace('_id', '', $binding);
        $parts[] = "$label {$values['existing']} cannot be remapped to {$values['proposed']}";
    }
    return 'Identity remap blocked: ' . implode('; ', $parts);
}

/**
 * Idempotently create or refresh an external identity mapping.
 *
 * Required keys: source, entity_type, external_id. Object bindings use the
 * *_id keys. Existing non-zero bindings are immutable through this automated
 * path; a conflict is recorded while the original relationship is preserved.
 */
function integrationIdentityUpsertMapping(array $input): array
{
    global $mysqli;

    $source = integrationIdentitySource($input['source'] ?? '');
    $entity_type = integrationIdentityEntityType($input['entity_type'] ?? '');
    $external_id = integrationIdentityExternalId($input['external_id'] ?? '');
    $external_parent_id = integrationIdentityLimitText($input['external_parent_id'] ?? '', 255);
    $external_name = integrationIdentityLimitText($input['external_name'] ?? '', 255);
    $state = integrationIdentityState($input['state'] ?? 'unresolved');
    $confidence = integrationIdentityConfidence($input['confidence'] ?? 0);
    $strategy = integrationIdentityLimitText($input['strategy'] ?? 'unresolved', 40) ?: 'unresolved';
    $last_error = integrationIdentityLimitText($input['last_error'] ?? '', 4000);
    $last_seen_at = integrationIdentityDateTime($input['last_seen_at'] ?? null);
    $bindings = integrationIdentityValidateBindings($input);
    $metadata = integrationIdentityNormalizeSnapshot(is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
    $metadata_json = json_encode(
        $metadata,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($metadata_json === false) {
        throw new RuntimeException('Could not encode identity metadata');
    }

    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        throw new RuntimeException('Could not obtain the external identity lock');
    }

    $owns_transaction = !n45DatabaseTransactionActive();
    try {
        // Lock assets before mapping rows throughout the identity/endpoint/Level
        // subsystem. The advisory identity lock makes this preview stable while
        // still allowing two different external ids aimed at one asset to
        // serialize on that asset row.
        $lock_preview = integrationIdentityFindMapping($source, $entity_type, $external_id);
        if ($owns_transaction) {
            mysqli_begin_transaction($mysqli);
        }
        $asset_lock_ids = array_values(array_unique(array_filter([
            intval($lock_preview['automation_mapping_asset_id'] ?? 0),
            intval($bindings['asset_id'] ?? 0),
        ], static fn ($asset_id) => $asset_id > 0)));
        sort($asset_lock_ids, SORT_NUMERIC);
        foreach ($asset_lock_ids as $asset_lock_id) {
            if (function_exists('endpointAssetTenantRow')) {
                $locked_asset = endpointAssetTenantRow($asset_lock_id, 0, true);
                if ($asset_lock_id === intval($bindings['asset_id'] ?? 0)
                    && (!empty($locked_asset['asset_archived_at'])
                        || (intval($bindings['client_id'] ?? 0) > 0
                            && intval($locked_asset['asset_client_id']) !== intval($bindings['client_id'])))) {
                    throw new RuntimeException('Identity target asset is not active in the proposed client');
                }
            }
        }
        $source_sql = integrationIdentityDbEscape($source);
        $entity_type_sql = integrationIdentityDbEscape($entity_type);
        $external_id_sql = integrationIdentityDbEscape($external_id);
        $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
            WHERE automation_mapping_source = '$source_sql'
            AND automation_mapping_entity_type = '$entity_type_sql'
            AND automation_mapping_external_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
        if (array_key_exists('authorized_client_id', $input)) {
            $authorized_client_id = max(0, intval($input['authorized_client_id']));
            $existing_client_id = intval($existing['automation_mapping_client_id'] ?? 0);
            if ($authorized_client_id < 1
                || ($existing_client_id > 0 && $existing_client_id !== $authorized_client_id)) {
                throw new RuntimeException('External identity belongs to a different client');
            }
        }

        if ($existing && !empty($existing['automation_mapping_last_seen_at'])
            && strcmp((string) $existing['automation_mapping_last_seen_at'], $last_seen_at) > 0) {
            if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
                automation_mapping_last_synced_at = NOW()
                WHERE automation_mapping_id = " . intval($existing['automation_mapping_id']))) {
                throw new RuntimeException('Could not acknowledge the stale identity delivery: ' . mysqli_error($mysqli));
            }
            $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
                FROM automation_entity_mappings
                WHERE automation_mapping_id = " . intval($existing['automation_mapping_id']) . " LIMIT 1"));
            if (!$mapping) {
                throw new RuntimeException('The stale identity delivery could not reload its mapping');
            }
            if ($owns_transaction) {
                mysqli_commit($mysqli);
            }
            $mapping['integration_identity_conflicts'] = [];
            $mapping['integration_identity_stale_delivery'] = true;
            return $mapping;
        }

        $existing_bindings = [
            'client_id' => intval($existing['automation_mapping_client_id'] ?? 0),
            'location_id' => intval($existing['automation_mapping_location_id'] ?? 0),
            'asset_id' => intval($existing['automation_mapping_asset_id'] ?? 0),
            'domain_id' => intval($existing['automation_mapping_domain_id'] ?? 0),
        ];
        $merge = integrationIdentityMergeBindings($existing_bindings, $bindings);
        $bindings = $merge['bindings'];
        $mapping_conflicts = $merge['conflicts'];

        $existing_parent_id = (string) ($existing['automation_mapping_external_parent_id'] ?? '');
        if ($existing && in_array($source, ['entra', 'intune', 'sentinelone'], true)
            && $existing_parent_id !== '' && $external_parent_id !== ''
            && !hash_equals($existing_parent_id, $external_parent_id)) {
            $external_parent_id = $existing_parent_id;
            // A different tenant/site cannot claim a previously unbound
            // durable id merely by being the first adapter to supply a client
            // and asset. Keep every prior binding (including zero/unbound) so
            // only an administrator can resolve the ownership conflict.
            $bindings = $existing_bindings;
            $mapping_conflicts['external_parent_id'] = [
                'existing' => $existing_parent_id,
                'proposed' => 'different source tenant/site',
            ];
            $last_error = 'Identity source tenant/site changed; manual review is required';
        }

        if ($entity_type === 'device' && intval($bindings['asset_id']) > 0) {
            $asset_id_sql = intval($bindings['asset_id']);
            $duplicate = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_id,
                automation_mapping_external_id, automation_mapping_state
                FROM automation_entity_mappings
                WHERE automation_mapping_source = '$source_sql'
                AND automation_mapping_entity_type = 'device'
                AND automation_mapping_asset_id = $asset_id_sql
                AND automation_mapping_external_id <> '$external_id_sql'
                AND automation_mapping_state NOT IN ('ignored', 'retired')
                AND automation_mapping_deleted_at IS NULL
                ORDER BY automation_mapping_state = 'confirmed' DESC,
                    automation_mapping_id ASC LIMIT 1 FOR UPDATE"));
            if ($duplicate && (string) ($existing['automation_mapping_state'] ?? '') !== 'confirmed') {
                $mapping_conflicts['asset_external_id'] = [
                    'existing' => (string) $duplicate['automation_mapping_external_id'],
                    'proposed' => $external_id,
                ];
                $last_error = 'Another active ' . $source
                    . ' device identity already maps to this asset';
            }
        }

        if ($mapping_conflicts) {
            $state = 'conflicting';
            $confidence = 0.0;
            if ($merge['conflicts']) {
                $conflict_error = integrationIdentityConflictMessage($merge['conflicts']);
                $last_error = $last_error === '' ? $conflict_error : "$conflict_error; $last_error";
            }
        } elseif (($existing['automation_mapping_state'] ?? '') === 'ignored'
            && $state !== 'retired') {
            // Ignore is a human override. Polling refreshes the last-seen
            // watermark, but cannot silently undo the decision.
            $state = 'ignored';
            $confidence = 0.0;
            $last_error = (string) ($existing['automation_mapping_last_error'] ?? 'Ignored by a technician');
        } elseif (($existing['automation_mapping_state'] ?? '') === 'retired'
            && $state !== 'retired') {
            // A durable id that reappears is safe to review, not safe to
            // resurrect automatically on its former asset.
            $state = 'suggested';
            $strategy = 'retired_identity_reappeared';
            $confidence = 0.0;
            $last_error = 'Retired identity was observed again; technician confirmation is required';
        } elseif (($existing['automation_mapping_state'] ?? '') === 'suggested'
            && (string) ($existing['automation_mapping_strategy'] ?? '') === 'retired_identity_reappeared'
            && $state !== 'retired') {
            // Keep the reappearance marker across every later poll. Without
            // this branch, the second clean delivery could silently promote
            // the retired durable id back to automatic.
            $state = 'suggested';
            $strategy = 'retired_identity_reappeared';
            $confidence = 0.0;
            $last_error = (string) ($existing['automation_mapping_last_error']
                ?? 'Retired identity requires technician review before reuse');
        } elseif (($existing['automation_mapping_state'] ?? '') === 'conflicting'
            && $state !== 'retired') {
            // A clean subsequent poll is evidence that the identity still
            // exists, not proof that the earlier tenant/asset ambiguity was
            // resolved. Only an explicit review action clears a conflict.
            $state = 'conflicting';
            $confidence = 0.0;
            $last_error = (string) ($existing['automation_mapping_last_error']
                ?? 'Identity conflict requires technician review');
        } elseif (($existing['automation_mapping_state'] ?? '') === 'stale'
            && strcmp((string) ($existing['automation_mapping_last_seen_at'] ?? ''), $last_seen_at) >= 0
            && !in_array($state, ['retired', 'conflicting'], true)) {
            // Replaying the last old sighting is not source recovery. Require
            // a strictly newer inventory watermark before stale can clear.
            $state = 'stale';
            $confidence = 0.0;
            $last_error = (string) ($existing['automation_mapping_last_error']
                ?? 'Source identity is still stale');
        } elseif ($existing
            && strcmp((string) ($existing['automation_mapping_last_seen_at'] ?? ''), $last_seen_at) === 0
            && in_array((string) ($existing['automation_mapping_state'] ?? ''), ['unresolved', 'suggested'], true)
            && in_array($state, ['automatic', 'confirmed'], true)) {
            // When two assessments share an inventory timestamp, converge on
            // the safer review state regardless of delivery order. A later
            // exact sighting can still promote the mapping automatically.
            $state = (string) $existing['automation_mapping_state'];
            $strategy = (string) ($existing['automation_mapping_strategy'] ?? $strategy);
            $confidence = (float) ($existing['automation_mapping_confidence'] ?? 0);
            $last_error = (string) ($existing['automation_mapping_last_error'] ?? '');
        } elseif (!empty($existing['automation_mapping_confirmed_at'])
            && in_array((string) ($existing['automation_mapping_state'] ?? ''), ['confirmed', 'stale'], true)
            && !in_array($state, ['retired', 'stale', 'conflicting'], true)) {
            $state = 'confirmed';
            $confidence = max($confidence, (float) ($existing['automation_mapping_confidence'] ?? 100));
        }

        $parent_sql = integrationIdentityNullableSql($external_parent_id);
        $name_sql = integrationIdentityNullableSql($external_name);
        $strategy_sql = integrationIdentityDbEscape($strategy);
        $state_sql = integrationIdentityDbEscape($state);
        $metadata_sql = integrationIdentityDbEscape($metadata_json);
        $last_seen_sql = integrationIdentityDbEscape($last_seen_at);
        $last_error_sql = integrationIdentityNullableSql($last_error);
        $confidence_sql = number_format($confidence, 2, '.', '');
        $client_id = $bindings['client_id'];
        $location_id = $bindings['location_id'];
        $asset_id = $bindings['asset_id'];
        $domain_id = $bindings['domain_id'];
        $successful = $last_error === '' && in_array($state, ['automatic', 'confirmed'], true);
        $last_success_sql = $successful ? 'NOW()' : 'NULL';
        $confirmed_sql = $state === 'confirmed' ? 'NOW()' : 'NULL';
        $deleted_sql = $state === 'retired' ? 'NOW()' : 'NULL';

        if ($existing) {
            $confirmed_update_sql = $state === 'confirmed'
                ? 'COALESCE(automation_mapping_confirmed_at, NOW())'
                : 'automation_mapping_confirmed_at';
            $last_success_update_sql = $successful
                ? 'NOW()'
                : 'automation_mapping_last_success_at';
            $sql = "UPDATE automation_entity_mappings SET
                automation_mapping_external_parent_id = $parent_sql,
                automation_mapping_external_name = $name_sql,
                automation_mapping_client_id = $client_id,
                automation_mapping_location_id = $location_id,
                automation_mapping_asset_id = $asset_id,
                automation_mapping_domain_id = $domain_id,
                automation_mapping_strategy = '$strategy_sql',
                automation_mapping_state = '$state_sql',
                automation_mapping_confidence = $confidence_sql,
                automation_mapping_metadata = '$metadata_sql',
                automation_mapping_last_seen_at = CASE
                    WHEN automation_mapping_last_seen_at IS NULL
                        OR automation_mapping_last_seen_at < '$last_seen_sql'
                    THEN '$last_seen_sql'
                    ELSE automation_mapping_last_seen_at
                END,
                automation_mapping_last_synced_at = NOW(),
                automation_mapping_last_success_at = $last_success_update_sql,
                automation_mapping_last_error = $last_error_sql,
                automation_mapping_confirmed_at = $confirmed_update_sql,
                automation_mapping_deleted_at = $deleted_sql
                WHERE automation_mapping_id = " . intval($existing['automation_mapping_id']);
        } else {
            $sql = "INSERT INTO automation_entity_mappings SET
                automation_mapping_source = '$source_sql',
                automation_mapping_entity_type = '$entity_type_sql',
                automation_mapping_external_id = '$external_id_sql',
                automation_mapping_external_parent_id = $parent_sql,
                automation_mapping_external_name = $name_sql,
                automation_mapping_client_id = $client_id,
                automation_mapping_location_id = $location_id,
                automation_mapping_asset_id = $asset_id,
                automation_mapping_domain_id = $domain_id,
                automation_mapping_strategy = '$strategy_sql',
                automation_mapping_state = '$state_sql',
                automation_mapping_confidence = $confidence_sql,
                automation_mapping_metadata = '$metadata_sql',
                automation_mapping_last_seen_at = '$last_seen_sql',
                automation_mapping_last_synced_at = NOW(),
                automation_mapping_last_success_at = $last_success_sql,
                automation_mapping_last_error = $last_error_sql,
                automation_mapping_confirmed_at = $confirmed_sql,
                automation_mapping_deleted_at = $deleted_sql";
        }

        if (!mysqli_query($mysqli, $sql)) {
            throw new RuntimeException('Could not save the external identity mapping: ' . mysqli_error($mysqli));
        }

        $mapping = integrationIdentityFindMapping($source, $entity_type, $external_id);
        if (!$mapping) {
            throw new RuntimeException('The saved external identity mapping could not be loaded');
        }
        if ($entity_type === 'device'
            && (string) $mapping['automation_mapping_state'] === 'conflicting'
            && in_array((string) ($existing['automation_mapping_state'] ?? ''), [
                'automatic', 'confirmed', 'stale',
            ], true)
            && intval($mapping['automation_mapping_asset_id']) > 0
            && intval($mapping['automation_mapping_client_id']) > 0
            && function_exists('endpointRetireIdentityBindingUnlocked')) {
            // Once the durable identity is tenant-, asset-, or duplicate-
            // conflicting, its previously trusted posture must stop
            // contributing to the unified record immediately.
            if (!endpointRetireIdentityBindingUnlocked([
                'asset_id' => intval($mapping['automation_mapping_asset_id']),
                'client_id' => intval($mapping['automation_mapping_client_id']),
                'source' => $source,
                'external_id' => $external_id,
                'occurred_at' => date('Y-m-d H:i:s'),
                'reason' => $last_error !== ''
                    ? $last_error
                    : 'External endpoint identity became conflicting',
            ])) {
                throw new RuntimeException('Identity conflict quarantine stopped because endpoint state diverged');
            }
        }
        $before_view = integrationIdentityMappingAuditView($existing ?: null);
        $after_view = integrationIdentityMappingAuditView($mapping);
        if ($before_view !== $after_view) {
            integrationIdentityRecordDecisionUnlocked(
                $existing ?: null,
                $mapping,
                integrationIdentityMappingDecisionAction($existing ?: null, $mapping, $mapping_conflicts),
                $last_error !== '' ? $last_error : $strategy,
                max(0, intval($input['actor_user_id'] ?? 0)),
                (string) ($input['batch_key'] ?? '')
            );
        }
        if ($owns_transaction) {
            mysqli_commit($mysqli);
        }
        $mapping['integration_identity_conflicts'] = $mapping_conflicts;
        return $mapping;
    } catch (Throwable $e) {
        if ($owns_transaction && n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
    }
}

/**
 * Store one row per distinct normalized payload. Repeated observations refresh
 * the same row, while a changed payload creates a new evidence point.
 */
function integrationIdentityRecordSnapshot(array $input): array
{
    global $mysqli;

    $source = integrationIdentitySource($input['source'] ?? '');
    $entity_type = integrationIdentityEntityType($input['entity_type'] ?? '');
    $external_id = integrationIdentityExternalId($input['external_id'] ?? '');
    $client_id = max(0, intval($input['client_id'] ?? 0));
    $asset_id = max(0, intval($input['asset_id'] ?? 0));
    $observed_at = integrationIdentityDateTime($input['observed_at'] ?? null);
    $document = integrationIdentitySnapshotDocument($input['facts'] ?? []);

    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        throw new RuntimeException('Could not obtain the external identity snapshot lock');
    }
    $owns_transaction = !n45DatabaseTransactionActive();
    try {
        if ($owns_transaction) {
            mysqli_begin_transaction($mysqli);
        }
        if ($asset_id > 0 && function_exists('endpointAssetTenantRow')) {
            endpointAssetTenantRow($asset_id, $client_id, true);
        }
    $source_sql = integrationIdentityDbEscape($source);
    $entity_type_sql = integrationIdentityDbEscape($entity_type);
    $external_id_sql = integrationIdentityDbEscape($external_id);
    $payload_hash_sql = integrationIdentityDbEscape($document['hash']);
    $payload_sql = integrationIdentityDbEscape($document['payload']);
    $observed_at_sql = integrationIdentityDbEscape($observed_at);
    $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_client_id,
        automation_mapping_asset_id FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = '$entity_type_sql'
        AND automation_mapping_external_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
    if (!$mapping
        || intval($mapping['automation_mapping_client_id']) !== $client_id
        || intval($mapping['automation_mapping_asset_id']) !== $asset_id) {
        throw new RuntimeException('Identity snapshot binding diverges from its durable mapping');
    }
    $latest = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_snapshot_payload_hash
        FROM automation_entity_snapshots
        WHERE automation_snapshot_source = '$source_sql'
        AND automation_snapshot_entity_type = '$entity_type_sql'
        AND automation_snapshot_external_id = '$external_id_sql'
        AND automation_snapshot_client_id = $client_id
        AND automation_snapshot_asset_id = $asset_id
        ORDER BY automation_snapshot_observed_at DESC, automation_snapshot_id DESC LIMIT 1"));
    $changed = !$latest || !hash_equals((string) $latest['automation_snapshot_payload_hash'], $document['hash']);

    if (!mysqli_query($mysqli, "INSERT INTO automation_entity_snapshots SET
        automation_snapshot_source = '$source_sql',
        automation_snapshot_entity_type = '$entity_type_sql',
        automation_snapshot_external_id = '$external_id_sql',
        automation_snapshot_client_id = $client_id,
        automation_snapshot_asset_id = $asset_id,
        automation_snapshot_payload_hash = '$payload_hash_sql',
        automation_snapshot_payload = '$payload_sql',
        automation_snapshot_observed_at = '$observed_at_sql'
        ON DUPLICATE KEY UPDATE
        automation_snapshot_id = LAST_INSERT_ID(automation_snapshot_id),
        automation_snapshot_observed_at = CASE
            WHEN automation_snapshot_observed_at < VALUES(automation_snapshot_observed_at)
            THEN VALUES(automation_snapshot_observed_at)
            ELSE automation_snapshot_observed_at END,
        automation_snapshot_last_seen_at = NOW()")) {
        throw new RuntimeException('Could not save the normalized identity snapshot: ' . mysqli_error($mysqli));
    }

        if ($owns_transaction) {
            mysqli_commit($mysqli);
        }
        return [
            'snapshot_id' => intval(mysqli_insert_id($mysqli)),
            'payload_hash' => $document['hash'],
            'changed' => $changed,
        ];
    } catch (Throwable $e) {
        if ($owns_transaction && n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
    }
}

function integrationIdentityRetireMapping(
    string $source,
    string $entity_type,
    string $external_id,
    string $reason = '',
    int $actor_user_id = 0,
    string $batch_key = ''
): bool
{
    global $mysqli;

    $source = integrationIdentitySource($source);
    $entity_type = integrationIdentityEntityType($entity_type);
    $external_id = integrationIdentityExternalId($external_id);
    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        throw new RuntimeException('Could not obtain the external identity lock');
    }

    $owns_transaction = !n45DatabaseTransactionActive();
    try {
        $source_sql = integrationIdentityDbEscape($source);
        $entity_type_sql = integrationIdentityDbEscape($entity_type);
        $external_id_sql = integrationIdentityDbEscape($external_id);
        $reason_sql = integrationIdentityNullableSql(integrationIdentityLimitText($reason, 4000));
        $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_asset_id,
            automation_mapping_client_id FROM automation_entity_mappings
            WHERE automation_mapping_source = '$source_sql'
            AND automation_mapping_entity_type = '$entity_type_sql'
            AND automation_mapping_external_id = '$external_id_sql' LIMIT 1"));
        if ($owns_transaction) {
            mysqli_begin_transaction($mysqli);
        }
        if ($entity_type === 'device' && function_exists('endpointAssetTenantRow')
            && intval($preview['automation_mapping_asset_id'] ?? 0) > 0
            && intval($preview['automation_mapping_client_id'] ?? 0) > 0) {
            endpointAssetTenantRow(
                intval($preview['automation_mapping_asset_id']),
                intval($preview['automation_mapping_client_id']),
                true
            );
        }
        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
            WHERE automation_mapping_source = '$source_sql'
            AND automation_mapping_entity_type = '$entity_type_sql'
            AND automation_mapping_external_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
        if (!$mapping) {
            if ($owns_transaction) {
                mysqli_commit($mysqli);
            }
            return false;
        }
        if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
            automation_mapping_state = 'retired',
            automation_mapping_confidence = 0.00,
            automation_mapping_last_synced_at = NOW(),
            automation_mapping_last_error = $reason_sql,
            automation_mapping_deleted_at = COALESCE(automation_mapping_deleted_at, NOW())
            WHERE automation_mapping_id = " . intval($mapping['automation_mapping_id']))) {
            throw new RuntimeException('Could not retire the external identity mapping: ' . mysqli_error($mysqli));
        }
        $retired_mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings
            WHERE automation_mapping_id = " . intval($mapping['automation_mapping_id']) . " LIMIT 1 FOR UPDATE"));
        if (!$retired_mapping) {
            throw new RuntimeException('The retired identity mapping could not be reloaded');
        }
        if (integrationIdentityMappingAuditView($mapping)
            !== integrationIdentityMappingAuditView($retired_mapping)) {
            integrationIdentityRecordDecisionUnlocked(
                $mapping,
                $retired_mapping,
                'retired',
                $reason,
                $actor_user_id,
                $batch_key
            );
        }
        if ($entity_type === 'device' && function_exists('endpointRetireIdentityBindingUnlocked')
            && intval($mapping['automation_mapping_asset_id']) > 0
            && intval($mapping['automation_mapping_client_id']) > 0) {
            $endpoint_retired = endpointRetireIdentityBindingUnlocked([
                'asset_id' => intval($mapping['automation_mapping_asset_id']),
                'client_id' => intval($mapping['automation_mapping_client_id']),
                'source' => $source,
                'external_id' => $external_id,
                'occurred_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ]);
            if (!$endpoint_retired) {
                throw new RuntimeException(
                    'Identity retirement stopped: endpoint source binding diverges from the locked mapping'
                );
            }
        }
        if ($owns_transaction) {
            mysqli_commit($mysqli);
        }
        return true;
    } catch (Throwable $e) {
        if ($owns_transaction && n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
    }
}

function integrationIdentityRetireMissing(string $source, string $entity_type, string $last_synced_before, string $reason = ''): int
{
    global $mysqli;

    $source_sql = integrationIdentityDbEscape(integrationIdentitySource($source));
    $entity_type_sql = integrationIdentityDbEscape(integrationIdentityEntityType($entity_type));
    $cutoff_sql = integrationIdentityDbEscape(integrationIdentityDateTime($last_synced_before));
    $candidates = mysqli_query($mysqli, "SELECT automation_mapping_external_id
        FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = '$entity_type_sql'
        AND automation_mapping_deleted_at IS NULL
        AND automation_mapping_last_synced_at < '$cutoff_sql'");
    if (!$candidates) {
        throw new RuntimeException('Could not identify missing external identities: ' . mysqli_error($mysqli));
    }

    $external_ids = [];
    while ($candidate = mysqli_fetch_assoc($candidates)) {
        $external_ids[] = (string) $candidate['automation_mapping_external_id'];
    }
    $retired = 0;
    foreach ($external_ids as $candidate_external_id) {
        $fresh = integrationIdentityFindMapping($source, $entity_type, $candidate_external_id);
        if (!$fresh
            || !empty($fresh['automation_mapping_deleted_at'])
            || strcmp((string) $fresh['automation_mapping_last_synced_at'], integrationIdentityDateTime($last_synced_before)) >= 0) {
            continue;
        }
        $retired += integrationIdentityRetireMapping(
            $source,
            $entity_type,
            $candidate_external_id,
            $reason
        ) ? 1 : 0;
    }
    return $retired;
}

function integrationIdentityReviewAction($value): string
{
    $action = strtolower(integrationIdentityLimitText($value, 20));
    if (!in_array($action, ['confirm', 'ignore', 'retire', 'remap'], true)) {
        throw new InvalidArgumentException('Identity review action is invalid');
    }
    return $action;
}

function integrationIdentityAssertAvailableDeviceAssetUnlocked(
    string $source,
    string $external_id,
    int $asset_id,
    int $mapping_id
): void {
    global $mysqli;

    $source_sql = integrationIdentityDbEscape($source);
    $external_id_sql = integrationIdentityDbEscape($external_id);
    $duplicate = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_mapping_id,
        automation_mapping_external_id FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = 'device'
        AND automation_mapping_asset_id = $asset_id
        AND automation_mapping_id <> $mapping_id
        AND automation_mapping_external_id <> '$external_id_sql'
        AND automation_mapping_state NOT IN ('ignored', 'retired')
        AND automation_mapping_deleted_at IS NULL
        LIMIT 1 FOR UPDATE"));
    if ($duplicate) {
        throw new RuntimeException('The target asset already has another active identity from this source');
    }
}

/**
 * Apply one explicit technician decision. Automated upserts cannot remap a
 * durable id; this is the only source-neutral path that may do so. Each action
 * owns one transaction and appends an immutable before/after decision row.
 */
function integrationIdentityReviewMapping(int $mapping_id, string $action, array $options = []): array
{
    global $mysqli;

    if ($mapping_id < 1) {
        throw new InvalidArgumentException('Identity mapping is required');
    }
    $action = integrationIdentityReviewAction($action);
    $reason = integrationIdentityLimitText($options['reason'] ?? '', 1000);
    if ($reason === '') {
        throw new InvalidArgumentException('A review reason is required');
    }
    $actor_user_id = max(0, intval($options['actor_user_id'] ?? 0));
    $batch_key = strtolower(integrationIdentityLimitText($options['batch_key'] ?? '', 64));
    if ($batch_key === '') {
        $batch_key = hash('sha256', implode("\0", [
            $actor_user_id,
            $mapping_id,
            $action,
            microtime(true),
            bin2hex(random_bytes(16)),
        ]));
    }

    $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
        FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1"));
    if (!$preview) {
        throw new InvalidArgumentException('Identity mapping no longer exists');
    }
    $source = integrationIdentitySource($preview['automation_mapping_source']);
    $entity_type = integrationIdentityEntityType($preview['automation_mapping_entity_type']);
    $external_id = integrationIdentityExternalId($preview['automation_mapping_external_id']);
    if ($entity_type !== 'device') {
        throw new InvalidArgumentException('Only device identities support this review action');
    }

    $level_lock_held = false;
    if ($source === 'level' && function_exists('levelAcquireDeviceLock')) {
        $level_lock_held = levelAcquireDeviceLock($external_id);
        if (!$level_lock_held) {
            throw new RuntimeException('Could not obtain the Level device review lock');
        }
    }
    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
        throw new RuntimeException('Could not obtain the external identity review lock');
    }

    try {
        $old_client_id = max(0, intval($preview['automation_mapping_client_id'] ?? 0));
        $old_asset_id = max(0, intval($preview['automation_mapping_asset_id'] ?? 0));
        $target_client_id = $action === 'remap'
            ? max(0, intval($options['target_client_id'] ?? 0)) : $old_client_id;
        $target_asset_id = $action === 'remap'
            ? max(0, intval($options['target_asset_id'] ?? 0)) : $old_asset_id;
        if ($action === 'remap' && ($target_client_id < 1 || $target_asset_id < 1)) {
            throw new InvalidArgumentException('Remap requires a target client and asset');
        }

        mysqli_begin_transaction($mysqli);
        $asset_lock_ids = array_values(array_unique(array_filter([
            $old_asset_id,
            $target_asset_id,
        ], static fn ($asset_id) => $asset_id > 0)));
        sort($asset_lock_ids, SORT_NUMERIC);
        $locked_assets = [];
        foreach ($asset_lock_ids as $asset_lock_id) {
            $locked_assets[$asset_lock_id] = endpointAssetTenantRow($asset_lock_id, 0, true);
        }

        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        if (!$mapping
            || (string) $mapping['automation_mapping_source'] !== $source
            || (string) $mapping['automation_mapping_entity_type'] !== $entity_type
            || (string) $mapping['automation_mapping_external_id'] !== $external_id) {
            throw new RuntimeException('Identity mapping changed during review; reload and try again');
        }
        $expected_client_id = array_key_exists('expected_client_id', $options)
            ? max(0, intval($options['expected_client_id'])) : $old_client_id;
        if (intval($mapping['automation_mapping_client_id']) !== $expected_client_id
            || intval($mapping['automation_mapping_asset_id']) !== $old_asset_id) {
            throw new RuntimeException('Identity binding changed during review; reload and try again');
        }
        $before = $mapping;

        if (in_array($action, ['confirm', 'remap'], true)) {
            if ($target_client_id < 1 || $target_asset_id < 1
                || intval($locked_assets[$target_asset_id]['asset_client_id'] ?? 0) !== $target_client_id
                || !empty($locked_assets[$target_asset_id]['asset_archived_at'])) {
                throw new InvalidArgumentException('The target asset is not active in the target client');
            }
            integrationIdentityAssertAvailableDeviceAssetUnlocked(
                $source,
                $external_id,
                $target_asset_id,
                $mapping_id
            );
        }

        if ($action === 'remap') {
            if ($old_client_id === $target_client_id && $old_asset_id === $target_asset_id) {
                throw new InvalidArgumentException('Remap target must differ from the current binding');
            }
            $source_sql = integrationIdentityDbEscape($source);
            $external_id_sql = integrationIdentityDbEscape($external_id);
            $target_state = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT endpoint_state_id
                FROM asset_endpoint_states WHERE endpoint_state_asset_id = $target_asset_id
                AND endpoint_state_source = '$source_sql' LIMIT 1 FOR UPDATE"));
            if ($target_state) {
                throw new RuntimeException('The target asset already has endpoint state from this source');
            }

            if ($old_asset_id > 0 && $old_client_id > 0
                && !endpointRetireIdentityBindingUnlocked([
                    'asset_id' => $old_asset_id,
                    'client_id' => $old_client_id,
                    'source' => $source,
                    'external_id' => $external_id,
                    'occurred_at' => date('Y-m-d H:i:s'),
                    'reason' => "Identity remapped by technician: $reason",
                ])) {
                throw new RuntimeException('Identity remap stopped because endpoint state diverged from the mapping');
            }
            if (!mysqli_query($mysqli, "UPDATE asset_endpoint_states SET
                endpoint_state_asset_id = $target_asset_id,
                endpoint_state_client_id = $target_client_id
                WHERE endpoint_state_source = '$source_sql'
                AND endpoint_state_external_id = '$external_id_sql'
                AND endpoint_state_asset_id = $old_asset_id
                AND endpoint_state_client_id = $old_client_id")) {
                throw new RuntimeException('Could not move the retired endpoint source state to the target asset');
            }

            if ($source === 'level') {
                $device_id_sql = integrationIdentityDbEscape($external_id);
                $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id
                    FROM level_asset_links WHERE level_device_id = '$device_id_sql' LIMIT 1 FOR UPDATE"));
                $target_link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_device_id
                    FROM level_asset_links WHERE level_asset_id = $target_asset_id
                    AND level_device_id <> '$device_id_sql' LIMIT 1 FOR UPDATE"));
                if ($target_link) {
                    throw new RuntimeException('The target asset already has a different Level device link');
                }
                if ($link && intval($link['level_asset_id']) !== $old_asset_id) {
                    throw new RuntimeException('The Level asset link diverged from the mapping under review');
                }
                if ($link) {
                    levelArchiveDeviceInterfaces($external_id);
                    if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                        level_asset_id = $target_asset_id,
                        level_device_sync_status = 'Pending',
                        level_device_sync_message = 'Technician remap; awaiting source reconciliation',
                        level_device_deleted_at = NULL,
                        level_device_last_synced_at = NOW()
                        WHERE level_device_id = '$device_id_sql'")) {
                        throw new RuntimeException('Could not move the Level device link to the target asset');
                    }
                }
            }

            if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
                automation_mapping_client_id = $target_client_id,
                automation_mapping_asset_id = $target_asset_id,
                automation_mapping_strategy = 'manual_remap',
                automation_mapping_state = 'confirmed',
                automation_mapping_confidence = 100.00,
                automation_mapping_last_error = NULL,
                automation_mapping_confirmed_at = NOW(),
                automation_mapping_deleted_at = NULL
                WHERE automation_mapping_id = $mapping_id")) {
                throw new RuntimeException('Could not remap the external identity');
            }
            endpointRecordChangeEventUnlocked([
                'asset_id' => $target_asset_id,
                'client_id' => $target_client_id,
                'source' => $source,
                'event_type' => 'identity',
                'external_key' => $external_id,
                'summary' => 'Endpoint identity manually remapped to this asset',
                'before' => ['client_id' => $old_client_id, 'asset_id' => $old_asset_id],
                'after' => ['client_id' => $target_client_id, 'asset_id' => $target_asset_id],
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($action === 'confirm') {
            if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
                automation_mapping_strategy = 'manual_confirm',
                automation_mapping_state = 'confirmed',
                automation_mapping_confidence = 100.00,
                automation_mapping_last_error = NULL,
                automation_mapping_confirmed_at = COALESCE(automation_mapping_confirmed_at, NOW()),
                automation_mapping_deleted_at = NULL
                WHERE automation_mapping_id = $mapping_id")) {
                throw new RuntimeException('Could not confirm the external identity');
            }
        } else {
            if ($old_asset_id > 0 && $old_client_id > 0
                && !endpointRetireIdentityBindingUnlocked([
                    'asset_id' => $old_asset_id,
                    'client_id' => $old_client_id,
                    'source' => $source,
                    'external_id' => $external_id,
                    'occurred_at' => date('Y-m-d H:i:s'),
                    'reason' => "Identity $action by technician: $reason",
                ])) {
                throw new RuntimeException('Identity review stopped because endpoint state diverged from the mapping');
            }
            if ($source === 'level') {
                $device_id_sql = integrationIdentityDbEscape($external_id);
                $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id
                    FROM level_asset_links WHERE level_device_id = '$device_id_sql' LIMIT 1 FOR UPDATE"));
                if ($link && intval($link['level_asset_id']) !== $old_asset_id) {
                    throw new RuntimeException('The Level asset link diverged from the mapping under review');
                }
                if ($link) {
                    levelArchiveDeviceInterfaces($external_id);
                    $deleted_sql = $action === 'retire'
                        ? 'COALESCE(level_device_deleted_at, NOW())' : 'level_device_deleted_at';
                    if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                        level_device_sync_status = 'Conflict',
                        level_device_sync_message = 'Technician $action; source facts quarantined',
                        level_device_deleted_at = $deleted_sql,
                        level_device_last_synced_at = NOW()
                        WHERE level_device_id = '$device_id_sql'")) {
                        throw new RuntimeException('Could not quarantine the Level device link');
                    }
                }
            }
            $state_sql = $action === 'ignore' ? 'ignored' : 'retired';
            $strategy_sql = $action === 'ignore' ? 'manual_ignore' : 'manual_retire';
            $reason_sql = integrationIdentityNullableSql($reason);
            $deleted_sql = $action === 'retire'
                ? 'COALESCE(automation_mapping_deleted_at, NOW())' : 'NULL';
            if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
                automation_mapping_strategy = '$strategy_sql',
                automation_mapping_state = '$state_sql',
                automation_mapping_confidence = 0.00,
                automation_mapping_last_error = $reason_sql,
                automation_mapping_deleted_at = $deleted_sql
                WHERE automation_mapping_id = $mapping_id")) {
                throw new RuntimeException('Could not apply the identity review decision');
            }
        }

        $after = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        if (!$after) {
            throw new RuntimeException('Reviewed identity mapping could not be reloaded');
        }
        $decision_action = match ($action) {
            'confirm' => 'confirmed',
            'ignore' => 'ignored',
            'retire' => 'retired',
            'remap' => 'remapped',
        };
        integrationIdentityRecordDecisionUnlocked(
            $before,
            $after,
            $decision_action,
            $reason,
            $actor_user_id,
            $batch_key
        );
        mysqli_commit($mysqli);
        return $after;
    } catch (Throwable $e) {
        if (n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
    }
}

function integrationIdentityReviewMappings(array $mapping_ids, string $action, array $options = []): array
{
    $mapping_ids = array_values(array_unique(array_filter(
        array_map('intval', $mapping_ids),
        static fn ($mapping_id) => $mapping_id > 0
    )));
    if (!$mapping_ids || count($mapping_ids) > 100) {
        throw new InvalidArgumentException('Select between 1 and 100 identity mappings');
    }
    $action = integrationIdentityReviewAction($action);
    if ($action === 'remap' && count($mapping_ids) !== 1) {
        throw new InvalidArgumentException('Remap exactly one identity at a time');
    }
    if (empty($options['batch_key'])) {
        $options['batch_key'] = hash('sha256', implode("\0", [
            intval($options['actor_user_id'] ?? 0),
            $action,
            microtime(true),
            bin2hex(random_bytes(16)),
        ]));
    }

    $summary = ['succeeded' => 0, 'failed' => 0, 'results' => []];
    foreach ($mapping_ids as $mapping_id) {
        $mapping_options = $options;
        if (isset($options['expected_client_ids'][$mapping_id])) {
            $mapping_options['expected_client_id'] = intval($options['expected_client_ids'][$mapping_id]);
        }
        try {
            $mapping = integrationIdentityReviewMapping($mapping_id, $action, $mapping_options);
            $summary['succeeded']++;
            $summary['results'][$mapping_id] = [
                'success' => true,
                'state' => (string) $mapping['automation_mapping_state'],
            ];
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['results'][$mapping_id] = ['success' => false, 'error' => $e->getMessage()];
        }
    }
    return $summary;
}

function integrationIdentityStaleThresholdHours(string $source): int
{
    return match (integrationIdentitySource($source)) {
        'level' => 24,
        'entra', 'intune', 'sentinelone' => 48,
        default => 168,
    };
}

function integrationIdentityQuarantineOrphanMapping(int $mapping_id): bool
{
    global $mysqli;

    $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
        FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1"));
    if (!$preview) {
        return false;
    }
    $source = integrationIdentitySource($preview['automation_mapping_source']);
    $entity_type = integrationIdentityEntityType($preview['automation_mapping_entity_type']);
    $external_id = integrationIdentityExternalId($preview['automation_mapping_external_id']);
    $level_lock_held = false;
    if ($source === 'level' && function_exists('levelAcquireDeviceLock')) {
        $level_lock_held = levelAcquireDeviceLock($external_id);
        if (!$level_lock_held) {
            throw new RuntimeException('Could not obtain the Level device orphan lock');
        }
    }
    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
        throw new RuntimeException('Could not obtain the external identity orphan lock');
    }

    try {
        // The mapping may have completed a manual remap after the unlocked
        // preview but before this identity lock was granted. Refresh it now so
        // the transaction locks and evaluates the current asset, never the
        // former tenant's asset.
        $preview = integrationIdentityFindMapping($source, $entity_type, $external_id);
        if (!$preview || intval($preview['automation_mapping_id'] ?? 0) !== $mapping_id) {
            return false;
        }
        mysqli_begin_transaction($mysqli);
        $asset_id = intval($preview['automation_mapping_asset_id'] ?? 0);
        $asset = $asset_id > 0
            ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_id, asset_client_id,
                asset_archived_at FROM assets WHERE asset_id = $asset_id LIMIT 1 FOR UPDATE"))
            : null;
        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        if (!$mapping
            || !in_array((string) $mapping['automation_mapping_state'], ['automatic', 'confirmed', 'stale'], true)
            || !empty($mapping['automation_mapping_deleted_at'])) {
            mysqli_commit($mysqli);
            return false;
        }
        $client_id = intval($mapping['automation_mapping_client_id']);
        $live_asset_id = intval($mapping['automation_mapping_asset_id']);
        $reason = '';
        if ($live_asset_id < 1 || $client_id < 1) {
            $reason = 'Trusted device identity has no complete client/asset binding';
        } elseif (!$asset || intval($asset['asset_id']) !== $live_asset_id) {
            $reason = 'Trusted device identity points to a missing asset';
        } elseif (!empty($asset['asset_archived_at'])) {
            $reason = 'Trusted device identity points to an archived asset';
        } elseif (intval($asset['asset_client_id']) !== $client_id) {
            $reason = 'Trusted device identity asset and client ownership disagree';
        }
        if ($reason === '') {
            mysqli_commit($mysqli);
            return false;
        }

        $before = $mapping;
        $reason_sql = integrationIdentityNullableSql($reason);
        if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
            automation_mapping_state = 'conflicting',
            automation_mapping_confidence = 0.00,
            automation_mapping_last_error = $reason_sql
            WHERE automation_mapping_id = $mapping_id")) {
            throw new RuntimeException('Could not quarantine the orphaned external identity');
        }
        if ($asset && intval($asset['asset_client_id']) === $client_id
            && $live_asset_id > 0 && $client_id > 0
            && !endpointRetireIdentityBindingUnlocked([
                'asset_id' => $live_asset_id,
                'client_id' => $client_id,
                'source' => $source,
                'external_id' => $external_id,
                'occurred_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
            ])) {
            throw new RuntimeException('Orphan quarantine stopped because endpoint state diverged from the mapping');
        }
        if ($source === 'level' && $live_asset_id > 0) {
            $external_id_sql = integrationIdentityDbEscape($external_id);
            $link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_id
                FROM level_asset_links WHERE level_device_id = '$external_id_sql' LIMIT 1 FOR UPDATE"));
            if ($link && intval($link['level_asset_id']) === $live_asset_id) {
                levelArchiveDeviceInterfaces($external_id);
                if (!mysqli_query($mysqli, "UPDATE level_asset_links SET
                    level_device_sync_status = 'Conflict',
                    level_device_sync_message = 'Identity asset is missing, archived, or tenant-conflicting',
                    level_device_last_synced_at = NOW()
                    WHERE level_device_id = '$external_id_sql'")) {
                    throw new RuntimeException('Could not quarantine the orphaned Level device link');
                }
            }
        }
        $after = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        integrationIdentityRecordDecisionUnlocked($before, $after, 'conflict_detected', $reason);
        mysqli_commit($mysqli);
        return true;
    } catch (Throwable $e) {
        if (n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
    }
}

function integrationIdentityReconcileOrphans(): array
{
    global $mysqli;

    $sql = mysqli_query($mysqli, "SELECT automation_mapping_id
        FROM automation_entity_mappings
        WHERE automation_mapping_entity_type = 'device'
        AND automation_mapping_state IN ('automatic', 'confirmed', 'stale')
        AND automation_mapping_deleted_at IS NULL");
    if (!$sql) {
        throw new RuntimeException('Could not enumerate endpoint identities for orphan reconciliation');
    }
    $mapping_ids = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $mapping_ids[] = intval($row['automation_mapping_id']);
    }
    $quarantined = 0;
    foreach ($mapping_ids as $candidate_mapping_id) {
        $quarantined += integrationIdentityQuarantineOrphanMapping($candidate_mapping_id) ? 1 : 0;
    }
    return ['checked' => count($mapping_ids), 'quarantined' => $quarantined];
}

function integrationIdentityMarkMappingStale(int $mapping_id, string $cutoff): bool
{
    global $mysqli;

    $cutoff = integrationIdentityDateTime($cutoff);
    $preview = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
        FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1"));
    if (!$preview) {
        return false;
    }
    $source = integrationIdentitySource($preview['automation_mapping_source']);
    $entity_type = integrationIdentityEntityType($preview['automation_mapping_entity_type']);
    $external_id = integrationIdentityExternalId($preview['automation_mapping_external_id']);
    $level_lock_held = false;
    if ($source === 'level' && function_exists('levelAcquireDeviceLock')) {
        $level_lock_held = levelAcquireDeviceLock($external_id);
        if (!$level_lock_held) {
            throw new RuntimeException('Could not obtain the Level device staleness lock');
        }
    }
    if (!integrationIdentityAcquireLock($source, $entity_type, $external_id)) {
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
        throw new RuntimeException('Could not obtain the external identity staleness lock');
    }

    try {
        // Re-read after the identity lock so a just-completed technician remap
        // cannot make this run lock or retire posture on the former asset.
        $preview = integrationIdentityFindMapping($source, $entity_type, $external_id);
        if (!$preview || intval($preview['automation_mapping_id'] ?? 0) !== $mapping_id) {
            return false;
        }
        mysqli_begin_transaction($mysqli);
        $asset_id = intval($preview['automation_mapping_asset_id'] ?? 0);
        $client_id = intval($preview['automation_mapping_client_id'] ?? 0);
        if ($asset_id > 0) {
            endpointAssetTenantRow($asset_id, 0, true);
        }
        $mapping = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        if (!$mapping
            || !in_array((string) $mapping['automation_mapping_state'], ['automatic', 'confirmed'], true)
            || empty($mapping['automation_mapping_last_seen_at'])
            || strcmp((string) $mapping['automation_mapping_last_seen_at'], $cutoff) >= 0) {
            mysqli_commit($mysqli);
            return false;
        }
        $before = $mapping;
        $reason = 'Source identity has not been observed within its freshness threshold';
        $reason_sql = integrationIdentityNullableSql($reason);
        if (!mysqli_query($mysqli, "UPDATE automation_entity_mappings SET
            automation_mapping_state = 'stale',
            automation_mapping_confidence = 0.00,
            automation_mapping_last_error = $reason_sql
            WHERE automation_mapping_id = $mapping_id")) {
            throw new RuntimeException('Could not mark the external identity stale');
        }

        if ($entity_type === 'device' && $asset_id > 0 && $client_id > 0) {
            $source_sql = integrationIdentityDbEscape($source);
            $external_id_sql = integrationIdentityDbEscape($external_id);
            $state = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM asset_endpoint_states
                WHERE endpoint_state_asset_id = $asset_id
                AND endpoint_state_client_id = $client_id
                AND endpoint_state_source = '$source_sql'
                AND endpoint_state_external_id = '$external_id_sql'
                LIMIT 1 FOR UPDATE"));
            if ($state && (string) $state['endpoint_state_status'] === 'active') {
                if (!mysqli_query($mysqli, "UPDATE asset_endpoint_states SET
                    endpoint_state_status = 'stale'
                    WHERE endpoint_state_id = " . intval($state['endpoint_state_id']))) {
                    throw new RuntimeException('Could not mark endpoint posture stale');
                }
                endpointRecordChangeEventUnlocked([
                    'asset_id' => $asset_id,
                    'client_id' => $client_id,
                    'source' => $source,
                    'event_type' => 'lifecycle',
                    'external_key' => $external_id,
                    'summary' => ucfirst($source) . ' endpoint record became stale',
                    'before' => ['source_status' => 'active'],
                    'after' => ['source_status' => 'stale'],
                    'occurred_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
        $after = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT *
            FROM automation_entity_mappings WHERE automation_mapping_id = $mapping_id LIMIT 1 FOR UPDATE"));
        integrationIdentityRecordDecisionUnlocked($before, $after, 'marked_stale', $reason);
        mysqli_commit($mysqli);
        return true;
    } catch (Throwable $e) {
        if (n45DatabaseTransactionActive()) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    } finally {
        integrationIdentityReleaseLock($source, $entity_type, $external_id);
        if ($level_lock_held) {
            levelReleaseDeviceLock($external_id);
        }
    }
}

function integrationIdentityReconcileStaleness(?DateTimeImmutable $now = null): array
{
    global $mysqli;

    $now = $now ?: new DateTimeImmutable('now');
    $candidates = mysqli_query($mysqli, "SELECT automation_mapping_id,
        automation_mapping_source FROM automation_entity_mappings
        WHERE automation_mapping_entity_type = 'device'
        AND automation_mapping_source IN ('level', 'entra', 'intune', 'sentinelone')
        AND automation_mapping_state IN ('automatic', 'confirmed')
        AND automation_mapping_deleted_at IS NULL");
    if (!$candidates) {
        throw new RuntimeException('Could not enumerate device identities for staleness reconciliation');
    }

    $rows = [];
    while ($candidate = mysqli_fetch_assoc($candidates)) {
        $rows[] = $candidate;
    }
    $summary = ['checked' => count($rows), 'marked_stale' => 0, 'by_source' => []];
    foreach ($rows as $candidate) {
        $source = (string) $candidate['automation_mapping_source'];
        $cutoff = $now->modify('-' . integrationIdentityStaleThresholdHours($source) . ' hours')
            ->format('Y-m-d H:i:s');
        if (integrationIdentityMarkMappingStale(intval($candidate['automation_mapping_id']), $cutoff)) {
            $summary['marked_stale']++;
            $summary['by_source'][$source] = intval($summary['by_source'][$source] ?? 0) + 1;
        }
    }
    return $summary;
}

function integrationIdentityHealthSummary(): array
{
    global $mysqli;

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        COUNT(*) AS total,
        SUM(automation_mapping_state IN ('unresolved', 'suggested', 'conflicting')) AS review,
        SUM(automation_mapping_state = 'conflicting') AS conflicts,
        SUM(automation_mapping_state = 'stale') AS stale,
        MAX(automation_mapping_last_seen_at) AS last_seen_at
        FROM automation_entity_mappings
        WHERE automation_mapping_entity_type = 'device'
        AND automation_mapping_deleted_at IS NULL"));
    if (!$row) {
        throw new RuntimeException('Could not calculate identity reconciliation health');
    }
    return [
        'total' => intval($row['total'] ?? 0),
        'review' => intval($row['review'] ?? 0),
        'conflicts' => intval($row['conflicts'] ?? 0),
        'stale' => intval($row['stale'] ?? 0),
        'last_seen_at' => $row['last_seen_at'] ?? null,
    ];
}
