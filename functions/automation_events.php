<?php

// Durable, source-neutral operational event ingestion and retry processing.

function automationEventDateTime($value, bool $default_now = true): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return $default_now ? date('Y-m-d H:i:s') : null;
    }

    try {
        $date = new DateTimeImmutable((string) $value);
        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable $e) {
        throw new InvalidArgumentException('occurred_at is invalid');
    }

    return $date->format('Y-m-d H:i:s');
}

function automationEventSensitiveKey($key): bool
{
    if (integrationIdentitySensitiveKey($key)) {
        return true;
    }

    $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string) $key);
    $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
    $key = trim($key, '_');

    return in_array($key, [
        'auth', 'authentication', 'authorization_header', 'bearer',
        'proxy_authorization', 'secret_key', 'session', 'session_id',
        'set_cookie', 'signature', 'signing_key', 'webhook_secret', 'x_api_key',
    ], true);
}

/**
 * Preserve the shape of the source payload while replacing secret-bearing
 * values. The redacted document is the only copy retained or replayed.
 */
function automationEventRedact($value)
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && automationEventSensitiveKey($key)) {
                $redacted[$key] = '[REDACTED]';
            } else {
                $redacted[$key] = automationEventRedact($item);
            }
        }
        return $redacted;
    }

    if (is_resource($value) || (is_float($value) && !is_finite($value))) {
        return null;
    }

    return $value;
}

function automationEventCanonicalize($value)
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = automationEventCanonicalize($item);
        }
        if (!integrationIdentityArrayIsList($normalized)) {
            ksort($normalized, SORT_STRING);
        }
        return $normalized;
    }

    if (is_resource($value) || (is_float($value) && !is_finite($value))) {
        return null;
    }

    return $value;
}

function automationEventDocument(array $event): array
{
    $redacted = automationEventRedact($event);
    $normalized_payload = automationEventCanonicalize($redacted);
    $payload = json_encode(
        $normalized_payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($payload === false) {
        throw new RuntimeException('Could not encode the redacted automation event');
    }

    // Delivery identifiers and timestamps do not change the underlying signal.
    // Excluding them gives semantically identical source events one fingerprint
    // even when a broker assigns a new delivery id.
    $semantic = $normalized_payload;
    foreach (['api_key', 'event_id', 'occurred_at', 'received_at'] as $volatile_key) {
        unset($semantic[$volatile_key]);
    }
    $semantic = automationEventCanonicalize($semantic);
    $fingerprint_payload = json_encode(
        $semantic,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($fingerprint_payload === false) {
        throw new RuntimeException('Could not fingerprint the automation event');
    }

    return [
        'event' => $normalized_payload,
        'payload' => $payload,
        'payload_hash' => hash('sha256', $payload),
        'fingerprint' => hash('sha256', $fingerprint_payload),
    ];
}

function automationEventEnvelope(array $input): array
{
    $source = automationSource($input['source'] ?? '');
    $event_id = automationLimitText($input['event_id'] ?? '', 255);
    $incident_key = automationLimitText($input['incident_key'] ?? '', 255);
    $state = strtolower(automationLimitText($input['state'] ?? 'open', 20));
    if ($event_id === '' || $incident_key === '') {
        throw new InvalidArgumentException('event_id and incident_key are required');
    }
    if (!in_array($state, ['open', 'update', 'resolved'], true)) {
        throw new InvalidArgumentException('state must be open, update, or resolved');
    }

    $severity = strtolower(automationLimitText($input['severity'] ?? 'low', 20));
    if (!in_array($severity, ['information', 'low', 'warning', 'medium', 'high', 'critical', 'emergency'], true)) {
        $severity = 'low';
    }

    $event = $input;
    $event['source'] = $source;
    $event['event_id'] = $event_id;
    $event['incident_key'] = $incident_key;
    $event['state'] = $state;
    $event['title'] = automationLimitText($input['title'] ?? '', 500);
    if ($event['title'] === '') {
        $event['title'] = 'Automation event';
    }
    $event['severity'] = $severity;
    $event['description'] = automationLimitText($input['description'] ?? '', 8000);
    $event['occurred_at'] = automationEventDateTime($input['occurred_at'] ?? null);
    $event['service_id'] = max(0, intval($input['service_id'] ?? 0));
    $event['identity'] = is_array($input['identity'] ?? null) ? $input['identity'] : [];
    $event['metadata'] = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
    $event['auto_resolve'] = automationBool($input['auto_resolve'] ?? null, true);

    $event['identity']['source'] = $source;
    if (empty($event['identity']['entity_type'])) {
        $event['identity']['entity_type'] = automationEntityType($input['entity_type'] ?? 'incident');
    }
    if (empty($event['identity']['external_id'])) {
        $event['identity']['external_id'] = $incident_key;
    }
    if (empty($event['identity']['external_name'])) {
        $event['identity']['external_name'] = automationLimitText($event['title'], 255);
    }
    $authorized_client_id = max(0, intval($input['client_id'] ?? 0));
    if ($authorized_client_id > 0) {
        $identity_client = is_array($event['identity']['client'] ?? null)
            ? $event['identity']['client'] : [];
        if (intval($identity_client['id'] ?? 0) === 0) {
            $identity_client['id'] = $authorized_client_id;
            $event['identity']['client'] = $identity_client;
        }
    }

    return $event;
}

function automationEventPolicyDefaults(string $source): array
{
    return [
        'source' => automationSource($source),
        'enabled' => true,
        'ticket_enabled' => true,
        'auto_resolve' => true,
        'threshold_count' => 1,
        'threshold_window_minutes' => 0,
        'max_attempts' => 5,
        'retry_delay_seconds' => 60,
        'payload_retention_days' => 30,
    ];
}

function automationEventPolicy(string $source): array
{
    global $mysqli;

    $policy = automationEventPolicyDefaults($source);
    $source_sql = automationDbEscape($policy['source']);
    mysqli_query($mysqli, "INSERT IGNORE INTO automation_event_policies
        (automation_policy_source) VALUES ('$source_sql')");
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_event_policies
        WHERE automation_policy_source = '$source_sql' LIMIT 1"));
    if (!$row) {
        return $policy;
    }

    return [
        'source' => $policy['source'],
        'enabled' => !empty($row['automation_policy_enabled']),
        'ticket_enabled' => !empty($row['automation_policy_ticket_enabled']),
        'auto_resolve' => !empty($row['automation_policy_auto_resolve']),
        'threshold_count' => min(1000, max(1, intval($row['automation_policy_threshold_count']))),
        'threshold_window_minutes' => min(43200, max(0, intval($row['automation_policy_threshold_window_minutes']))),
        'max_attempts' => min(25, max(1, intval($row['automation_policy_max_attempts']))),
        'retry_delay_seconds' => min(86400, max(15, intval($row['automation_policy_retry_delay_seconds']))),
        'payload_retention_days' => min(3650, max(1, intval($row['automation_policy_payload_retention_days']))),
    ];
}

function automationEventResolveService(int $service_id, array $resolved): array
{
    global $mysqli;

    if ($service_id < 1) {
        $resolved['service_id'] = 0;
        $resolved['service_name'] = '';
        return $resolved;
    }

    $service = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT service_id, service_name, service_client_id
        FROM services WHERE service_id = $service_id LIMIT 1"));
    if (!$service) {
        throw new AutomationConflictException('The supplied service does not exist');
    }

    $service_client_id = intval($service['service_client_id']);
    $client_id = intval($resolved['client_id'] ?? 0);
    if ($client_id > 0 && $service_client_id !== $client_id) {
        throw new AutomationConflictException('The supplied service belongs to a different client');
    }
    if ($client_id === 0) {
        $resolved['client_id'] = $service_client_id;
        $resolved['client_name'] = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_name
            FROM clients WHERE client_id = $service_client_id LIMIT 1"))[0] ?? '');
    }

    $resolved['service_id'] = intval($service['service_id']);
    $resolved['service_name'] = (string) $service['service_name'];
    return $resolved;
}

function automationEventActiveMaintenanceWindow(string $source, int $client_id = 0,
    int $asset_id = 0, int $service_id = 0, ?string $occurred_at = null): ?array
{
    global $mysqli;

    $source_sql = automationDbEscape(automationSource($source));
    $at = automationEventDateTime($occurred_at);
    $at_sql = automationDbEscape($at);
    $client_id = max(0, $client_id);
    $asset_id = max(0, $asset_id);
    $service_id = max(0, $service_id);

    $sql = mysqli_query($mysqli, "SELECT * FROM automation_maintenance_windows
        WHERE automation_maintenance_deleted_at IS NULL
        AND automation_maintenance_starts_at <= '$at_sql'
        AND automation_maintenance_ends_at >= '$at_sql'
        AND (automation_maintenance_source = '' OR automation_maintenance_source = '$source_sql')
        AND (automation_maintenance_client_id = 0 OR automation_maintenance_client_id = $client_id)
        AND (automation_maintenance_asset_id = 0 OR automation_maintenance_asset_id = $asset_id)
        AND (automation_maintenance_service_id = 0 OR automation_maintenance_service_id = $service_id)
        ORDER BY
            (automation_maintenance_asset_id > 0) DESC,
            (automation_maintenance_service_id > 0) DESC,
            (automation_maintenance_client_id > 0) DESC,
            (automation_maintenance_source <> '') DESC,
            automation_maintenance_starts_at DESC
        LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationEventThresholdOccurrences(string $source, string $incident_key, int $window_minutes): int
{
    global $mysqli;

    $source_sql = automationDbEscape($source);
    $incident_key_sql = automationDbEscape($incident_key);
    $window_clause = $window_minutes > 0
        ? 'AND event_row.automation_event_received_at >= DATE_SUB(NOW(), INTERVAL ' . intval($window_minutes) . ' MINUTE)'
        : '';
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM automation_events event_row
        WHERE event_row.automation_event_source = '$source_sql'
        AND event_row.automation_event_incident_key = '$incident_key_sql'
        AND event_row.automation_event_state IN ('open', 'update')
        AND event_row.automation_event_status <> 'Dead'
        AND event_row.automation_event_id > COALESCE((
            SELECT MAX(recovery_row.automation_event_id) FROM automation_events recovery_row
            WHERE recovery_row.automation_event_source = '$source_sql'
            AND recovery_row.automation_event_incident_key = '$incident_key_sql'
            AND recovery_row.automation_event_state = 'resolved'
            AND recovery_row.automation_event_action <> 'stale'
            AND recovery_row.automation_event_status = 'Processed'
        ), 0)
        $window_clause"));
    return intval($row[0] ?? 0);
}

function automationEventQueue(array $input): array
{
    global $mysqli, $api_key_id, $api_key_user_id, $client_id;

    if (!n45FeatureEnabled('automation')) {
        throw new RuntimeException('Automation ingestion is disabled by deployment feature flag');
    }

    $event = automationEventEnvelope($input);
    $document = automationEventDocument($event);
    $policy = automationEventPolicy($event['source']);

    $source_sql = automationDbEscape($event['source']);
    $event_id_sql = automationDbEscape($event['event_id']);
    $incident_key_sql = automationDbEscape($event['incident_key']);
    $fingerprint_sql = automationDbEscape($document['fingerprint']);
    $state_sql = automationDbEscape($event['state']);
    $payload_hash_sql = automationDbEscape($document['payload_hash']);
    $payload_sql = automationDbEscape($document['payload']);
    $occurred_at_sql = automationDbEscape($event['occurred_at']);
    $max_attempts = intval($policy['max_attempts']);
    $origin_api_key_id = max(0, intval($api_key_id ?? 0));
    $origin_api_user_id = max(0, intval($api_key_user_id ?? 0));
    $authorized_client_id = max(0, intval($client_id ?? 0));
    if ($origin_api_key_id < 1 || $origin_api_user_id < 1) {
        throw new RuntimeException('Automation event authority provenance is unavailable');
    }

    automationDbQuery("INSERT INTO automation_events SET
        automation_event_source = '$source_sql',
        automation_event_external_id = '$event_id_sql',
        automation_event_incident_key = '$incident_key_sql',
        automation_event_fingerprint = '$fingerprint_sql',
        automation_event_state = '$state_sql',
        automation_event_action = 'queued',
        automation_event_status = 'Pending',
        automation_event_process_attempts = 0,
        automation_event_max_attempts = $max_attempts,
        automation_event_api_key_id = $origin_api_key_id,
        automation_event_api_user_id = $origin_api_user_id,
        automation_event_authorized_client_id = $authorized_client_id,
        automation_event_payload_hash = '$payload_hash_sql',
        automation_event_payload = '$payload_sql',
        automation_event_occurred_at = '$occurred_at_sql'
        ON DUPLICATE KEY UPDATE
        automation_event_delivery_count = automation_event_delivery_count + 1,
        automation_event_last_received_at = NOW()",
        'Could not queue the automation event');

    $duplicate = mysqli_affected_rows($mysqli) !== 1;
    $row = mysqli_fetch_assoc(automationDbQuery("SELECT * FROM automation_events
        WHERE automation_event_source = '$source_sql'
        AND automation_event_external_id = '$event_id_sql' LIMIT 1",
        'Could not read the queued automation event'));
    if (!$row) {
        throw new RuntimeException('The queued automation event could not be found');
    }
    if ($duplicate && !empty($row['automation_event_occurred_at'])
        && !hash_equals((string) $row['automation_event_payload_hash'], $document['payload_hash'])) {
        throw new AutomationConflictException('event_id was already used for a different payload');
    }

    return [
        'event_id' => intval($row['automation_event_id']),
        'duplicate' => $duplicate,
        'status' => (string) $row['automation_event_status'],
        'action' => (string) $row['automation_event_action'],
        'ticket_id' => intval($row['automation_event_ticket_id']),
        'delivery_count' => intval($row['automation_event_delivery_count']),
    ];
}

/**
 * Rehydrate and lock the authorization context captured at ingestion. A queued
 * event never inherits the cron worker's ambient privileges.
 */
function automationEventLockAuthority(array $row, N45LockOrder $lock_order): void
{
    global $mysqli, $api_key_id, $api_key_user_id, $api_key_name;
    global $session_user_id, $session_user_role, $session_is_admin, $session_name;
    global $session_ip, $session_user_agent, $client_access_array, $client_deny_array;

    $stored_key_id = max(0, intval($row['automation_event_api_key_id'] ?? 0));
    $stored_user_id = max(0, intval($row['automation_event_api_user_id'] ?? 0));
    if ($stored_key_id < 1 || $stored_user_id < 1) {
        throw new AutomationConflictException('The queued event has no verifiable API authority provenance');
    }

    $preview = mysqli_fetch_assoc(automationDbQuery("SELECT user_role_id FROM users
        WHERE user_id = $stored_user_id LIMIT 1", 'Could not inspect the automation API principal'));
    $role_id = intval($preview['user_role_id'] ?? 0);
    if ($role_id < 1) {
        throw new AutomationConflictException('The automation API principal no longer has a role');
    }

    $lock_order->observe('authorization');
    $role = mysqli_fetch_assoc(automationDbQuery("SELECT role_id, role_is_admin, role_archived_at
        FROM user_roles WHERE role_id = $role_id LIMIT 1 FOR UPDATE",
        'Could not lock the automation API role'));
    $user = mysqli_fetch_assoc(automationDbQuery("SELECT user_id, user_name, user_type, user_status,
        user_archived_at, user_role_id FROM users WHERE user_id = $stored_user_id
        LIMIT 1 FOR UPDATE", 'Could not lock the automation API principal'));
    if (!$role || !$user || intval($user['user_role_id']) !== $role_id
        || intval($user['user_type']) !== 1 || intval($user['user_status']) !== 1
        || $user['user_archived_at'] !== null || $role['role_archived_at'] !== null) {
        throw new AutomationConflictException('The API principal authorization changed before event processing');
    }

    $is_admin = intval($role['role_is_admin']) === 1;
    if (!$is_admin) {
        $permission_rows = automationDbQuery("SELECT module_name, user_role_permission_level
            FROM user_role_permissions INNER JOIN modules ON module_id = user_role_permissions.module_id
            WHERE user_role_id = $role_id ORDER BY module_id FOR UPDATE",
            'Could not lock the automation role permissions');
        $permission_levels = [];
        while ($permission = mysqli_fetch_row($permission_rows)) {
            $module = (string) ($permission[0] ?? '');
            $permission_levels[$module] = max(
                intval($permission_levels[$module] ?? 0),
                intval($permission[1] ?? 0)
            );
        }
        if (intval($permission_levels['module_support'] ?? 0) < 2) {
            throw new AutomationConflictException('The API principal no longer has automation write permission');
        }
    }

    $client_access_array = [];
    $client_deny_array = [];
    $permissions = automationDbQuery("SELECT client_id, permission_type FROM user_client_permissions
        WHERE user_id = $stored_user_id ORDER BY client_id FOR UPDATE",
        'Could not lock the automation client scope');
    while ($permission = mysqli_fetch_assoc($permissions)) {
        if ($permission['permission_type'] === 'deny') {
            $client_deny_array[] = intval($permission['client_id']);
        } else {
            $client_access_array[] = intval($permission['client_id']);
        }
    }

    $lock_order->observe('api_key', $stored_key_id);
    $key = mysqli_fetch_assoc(automationDbQuery("SELECT api_key_id, api_key_name, api_key_user_id
        FROM api_keys WHERE api_key_id = $stored_key_id
        AND api_key_expire > CURRENT_DATE() LIMIT 1 FOR UPDATE",
        'Could not lock the automation API key'));
    if (!$key || intval($key['api_key_user_id']) !== $stored_user_id) {
        throw new AutomationConflictException('The originating API key is revoked, expired, or reassigned');
    }

    $api_key_id = $stored_key_id;
    $api_key_user_id = $stored_user_id;
    $api_key_name = automationDbEscape($key['api_key_name']);
    $session_user_id = $stored_user_id;
    $session_user_role = $role_id;
    $session_is_admin = $is_admin;
    $session_name = automationDbEscape($user['user_name']);
    $session_ip = automationDbEscape($session_ip ?? 'automation-worker');
    $session_user_agent = automationDbEscape($session_user_agent ?? 'automation-worker');
}

/** Resolve the client before any identity writer runs so client is the first
 * tenant row locked by the processing transaction. */
function automationEventCandidateClientId(array $event): int
{
    $identity = is_array($event['identity'] ?? null) ? $event['identity'] : [];
    $client = is_array($identity['client'] ?? null) ? $identity['client'] : [];
    $location = is_array($identity['location'] ?? null) ? $identity['location'] : [];
    $options = is_array($identity['options'] ?? null) ? $identity['options'] : [];
    $source = automationSource($identity['source'] ?? $event['source'] ?? '');
    $entity_type = automationEntityType($identity['entity_type'] ?? 'incident');
    $external_id = automationLimitText($identity['external_id'] ?? '', 255);
    $candidates = [];

    foreach ([
        $external_id === '' ? null : automationMapping($source, $entity_type, $external_id),
        empty($client['external_id']) ? null : automationMapping(
            $source,
            automationEntityType($client['entity_type'] ?? 'client'),
            automationLimitText($client['external_id'], 255)
        ),
        empty($location['external_id']) ? null : automationMapping(
            $source,
            automationEntityType($location['entity_type'] ?? 'location'),
            automationLimitText($location['external_id'], 255)
        ),
    ] as $mapping) {
        $mapped_id = intval($mapping['automation_mapping_client_id'] ?? 0);
        if ($mapped_id > 0) {
            $candidates[] = $mapped_id;
        }
    }
    if (intval($client['id'] ?? 0) > 0) {
        $candidates[] = intval($client['id']);
    }
    if (trim((string) ($client['name'] ?? '')) !== '') {
        $matches = automationFindClientByName((string) $client['name']);
        if (count($matches) > 1) {
            throw new AutomationConflictException('The client name matched more than one ITFlow client');
        }
        if (count($matches) === 1) {
            $candidates[] = intval($matches[0]['client_id']);
        }
    }
    $service_id = max(0, intval($event['service_id'] ?? 0));
    if ($service_id > 0) {
        $service = mysqli_fetch_row(automationDbQuery("SELECT service_client_id FROM services
            WHERE service_id = $service_id LIMIT 1", 'Could not inspect the automation service client'));
        if (!$service) {
            throw new AutomationConflictException('The supplied service does not exist');
        }
        $candidates[] = intval($service[0]);
    }

    $candidates = array_values(array_unique(array_filter(array_map('intval', $candidates))));
    if (count($candidates) > 1) {
        throw new AutomationConflictException('The event identity disagrees about the ITFlow client');
    }
    $client_id = intval($candidates[0] ?? 0);
    if ($client_id === 0 && automationBool($options['create_client'] ?? null, false)) {
        throw new AutomationConflictException('Operational events may not create an unmapped client');
    }
    return $client_id;
}

function automationEventLockClient(int $client_id, int $authorized_client_id,
    N45LockOrder $lock_order): ?array
{
    if ($authorized_client_id > 0 && $client_id !== $authorized_client_id) {
        throw new AutomationConflictException('The event identity does not match its authorized client');
    }
    if ($client_id < 1) {
        return null;
    }
    if (!automationUserCanAccessClient($client_id)) {
        throw new AutomationConflictException('The API principal no longer has access to the event client');
    }
    $lock_order->observe('client', $client_id);
    $client = mysqli_fetch_assoc(automationDbQuery("SELECT client_id, client_name, client_archived_at
        FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE",
        'Could not lock the automation event client'));
    if (!$client || $client['client_archived_at'] !== null) {
        throw new AutomationConflictException('The automation event client is archived or unavailable');
    }
    return $client;
}

function automationEventQueueAudit(string $action, string $description,
    int $client_id = 0, int $entity_id = 0): void
{
    global $automation_event_audit_buffer;
    if (is_array($automation_event_audit_buffer ?? null)) {
        $automation_event_audit_buffer[] = [$action, $description, $client_id, $entity_id];
        return;
    }
    if (!logAudit('Automation', $action, $description, $client_id, $entity_id)) {
        throw new RuntimeException('Could not append the automation audit record');
    }
}

function automationEventFlushAudits(N45LockOrder $lock_order): void
{
    global $automation_event_audit_buffer;
    $lock_order->observe('audit');
    foreach ((array) ($automation_event_audit_buffer ?? []) as $audit) {
        if (!logAudit('Automation', $audit[0], $audit[1], intval($audit[2]), intval($audit[3]))) {
            throw new RuntimeException('Could not append the automation audit record');
        }
    }
    $automation_event_audit_buffer = [];
}

function automationEventMergeIncidentBindings(array $incident, array $resolved): array
{
    $bindings = [];
    foreach (['client_id', 'location_id', 'asset_id', 'service_id'] as $binding) {
        $column = 'automation_incident_' . $binding;
        $existing = max(0, intval($incident[$column] ?? 0));
        $incoming = max(0, intval($resolved[$binding] ?? 0));
        if ($existing > 0 && $incoming > 0 && $existing !== $incoming) {
            throw new AutomationConflictException('Incident ' . str_replace('_id', '', $binding)
                . " $existing cannot be remapped to $incoming");
        }
        $bindings[$binding] = $existing ?: $incoming;
    }
    return $bindings;
}

function automationEventSaveIncident(array $event, array $resolved, ?array $incident,
    string $status, string $action, int $ticket_id, string $fingerprint,
    int $suppressed_increment = 0): void
{
    global $mysqli;

    $bindings = automationEventMergeIncidentBindings($incident ?? [], $resolved);
    $source_sql = automationDbEscape($event['source']);
    $incident_key_sql = automationDbEscape($event['incident_key']);
    $title_sql = automationDbEscape($event['title']);
    $status_sql = automationDbEscape($status);
    $severity_sql = automationDbEscape($event['severity']);
    $action_sql = automationDbEscape($action);
    $fingerprint_sql = automationDbEscape($fingerprint);
    $metadata = integrationIdentityNormalizeSnapshot($event['metadata']);
    $metadata_json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $metadata_sql = automationDbEscape($metadata_json === false ? '{}' : $metadata_json);
    $event_at_sql = automationDbEscape($event['occurred_at']);
    $client_id = intval($bindings['client_id']);
    $location_id = intval($bindings['location_id']);
    $asset_id = intval($bindings['asset_id']);
    $service_id = intval($bindings['service_id']);
    $repeat_increment = ($incident && hash_equals((string) ($incident['automation_incident_last_event_hash'] ?? ''), $fingerprint)) ? 1 : 0;
    $resolved_at_sql = $status === 'Resolved' ? "'$event_at_sql'" : 'NULL';
    $opened_at_sql = $status === 'Open' ? "'$event_at_sql'" : 'NULL';

    if (!$incident) {
        automationDbQuery("INSERT INTO automation_incidents SET
            automation_incident_source = '$source_sql',
            automation_incident_key = '$incident_key_sql',
            automation_incident_title = '$title_sql',
            automation_incident_status = '$status_sql',
            automation_incident_severity = '$severity_sql',
            automation_incident_ticket_id = $ticket_id,
            automation_incident_client_id = $client_id,
            automation_incident_location_id = $location_id,
            automation_incident_asset_id = $asset_id,
            automation_incident_service_id = $service_id,
            automation_incident_event_count = 1,
            automation_incident_repeat_count = $repeat_increment,
            automation_incident_suppressed_count = $suppressed_increment,
            automation_incident_last_event_hash = '$fingerprint_sql',
            automation_incident_last_action = '$action_sql',
            automation_incident_metadata = '$metadata_sql',
            automation_incident_first_event_at = '$event_at_sql',
            automation_incident_opened_at = $opened_at_sql,
            automation_incident_last_event_at = '$event_at_sql',
            automation_incident_resolved_at = $resolved_at_sql",
            'Could not create the automation incident');
        return;
    }

    $incident_id = intval($incident['automation_incident_id']);
    $existing_last_event = (string) ($incident['automation_incident_last_event_at'] ?? '');
    $last_event_at = $existing_last_event !== '' && $existing_last_event > $event['occurred_at']
        ? $existing_last_event : $event['occurred_at'];
    $last_event_at_sql = automationDbEscape($last_event_at);
    $first_event_at = (string) ($incident['automation_incident_first_event_at'] ?? '');
    if ($first_event_at === '' || $event['occurred_at'] < $first_event_at) {
        $first_event_at = $event['occurred_at'];
    }
    $first_event_at_sql = automationDbEscape($first_event_at);
    $existing_status = (string) ($incident['automation_incident_status'] ?? '');
    if ($status === 'Open' && $existing_status !== 'Open') {
        $opened_at_sql = "'$event_at_sql'";
    } elseif (!empty($incident['automation_incident_opened_at'])) {
        $opened_at_sql = "'" . automationDbEscape($incident['automation_incident_opened_at']) . "'";
    }

    automationDbQuery("UPDATE automation_incidents SET
        automation_incident_title = '$title_sql',
        automation_incident_status = '$status_sql',
        automation_incident_severity = '$severity_sql',
        automation_incident_ticket_id = $ticket_id,
        automation_incident_client_id = $client_id,
        automation_incident_location_id = $location_id,
        automation_incident_asset_id = $asset_id,
        automation_incident_service_id = $service_id,
        automation_incident_event_count = automation_incident_event_count + 1,
        automation_incident_repeat_count = automation_incident_repeat_count + $repeat_increment,
        automation_incident_suppressed_count = automation_incident_suppressed_count + $suppressed_increment,
        automation_incident_last_event_hash = '$fingerprint_sql',
        automation_incident_last_action = '$action_sql',
        automation_incident_metadata = '$metadata_sql',
        automation_incident_first_event_at = '$first_event_at_sql',
        automation_incident_opened_at = $opened_at_sql,
        automation_incident_last_event_at = '$last_event_at_sql',
        automation_incident_resolved_at = $resolved_at_sql
        WHERE automation_incident_id = $incident_id LIMIT 1",
        'Could not update the automation incident');
}

function automationEventRecordStaleIncident(array $incident, array $event, string $fingerprint): void
{
    $incident_id = intval($incident['automation_incident_id']);
    $repeat_increment = hash_equals(
        (string) ($incident['automation_incident_last_event_hash'] ?? ''),
        $fingerprint
    ) ? 1 : 0;
    $first_event_at = (string) ($incident['automation_incident_first_event_at'] ?? '');
    if ($first_event_at === '' || $event['occurred_at'] < $first_event_at) {
        $first_event_at = $event['occurred_at'];
    }
    $first_event_at_sql = automationDbEscape($first_event_at);

    automationDbQuery("UPDATE automation_incidents SET
        automation_incident_event_count = automation_incident_event_count + 1,
        automation_incident_repeat_count = automation_incident_repeat_count + $repeat_increment,
        automation_incident_last_action = 'stale',
        automation_incident_first_event_at = '$first_event_at_sql'
        WHERE automation_incident_id = $incident_id LIMIT 1",
        'Could not record the stale automation event');
}

function automationEventComplete(int $event_id, string $action, int $ticket_id = 0,
    ?string $suppressed_reason = null, int $maintenance_window_id = 0,
    ?string $lease_token = null, ?N45LockOrder $lock_order = null): void
{
    global $mysqli;
    $event_id = max(0, $event_id);
    $action_sql = automationDbEscape(automationLimitText($action, 40));
    $suppressed_sql = $suppressed_reason === null || $suppressed_reason === ''
        ? 'NULL' : "'" . automationDbEscape(automationLimitText($suppressed_reason, 80)) . "'";
    $lease_predicate = '';
    if ($lease_token !== null) {
        $lease_sql = automationDbEscape($lease_token);
        $lease_predicate = " AND automation_event_status = 'Processing'
            AND automation_event_lease_token = '$lease_sql'";
    }
    if ($lock_order) {
        $lock_order->observe('automation_event', $event_id);
    }
    automationDbQuery("UPDATE automation_events SET
        automation_event_action = '$action_sql',
        automation_event_status = 'Processed',
        automation_event_ticket_id = " . max(0, $ticket_id) . ",
        automation_event_suppressed_reason = $suppressed_sql,
        automation_event_maintenance_window_id = " . max(0, $maintenance_window_id) . ",
        automation_event_processing_at = NULL,
        automation_event_lease_token = NULL,
        automation_event_next_attempt_at = NULL,
        automation_event_last_error = NULL,
        automation_event_processed_at = NOW()
        WHERE automation_event_id = $event_id $lease_predicate LIMIT 1",
        'Could not complete the automation event');
    if ($lease_token !== null && mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The automation event processing lease was lost before completion');
    }
}

function automationEventFail(int $event_id, Throwable $error, array $policy,
    string $lease_token, int $attempts): array
{
    global $mysqli;

    $event_id = max(0, $event_id);
    $max_attempts = intval($policy['max_attempts'] ?? 5);
    $terminal = $error instanceof InvalidArgumentException
        || $error instanceof AutomationConflictException
        || $attempts >= $max_attempts;
    $status = $terminal ? 'Dead' : 'Failed';
    $status_sql = automationDbEscape($status);
    $error_text = automationLimitText($error->getMessage(), 4000);
    $error_sql = automationDbEscape($error_text);
    $lease_sql = automationDbEscape($lease_token);
    $retry_delay = intval($policy['retry_delay_seconds'] ?? 60);
    $next_attempt_sql = $terminal ? 'NULL' : "DATE_ADD(NOW(), INTERVAL $retry_delay SECOND)";

    automationDbQuery("UPDATE automation_events SET
        automation_event_status = '$status_sql',
        automation_event_action = 'processing_failed',
        automation_event_last_error = '$error_sql',
        automation_event_processing_at = NULL,
        automation_event_lease_token = NULL,
        automation_event_next_attempt_at = $next_attempt_sql
        WHERE automation_event_id = $event_id
        AND automation_event_status = 'Processing'
        AND automation_event_lease_token = '$lease_sql' LIMIT 1",
        'Could not record the automation event failure');

    if (mysqli_affected_rows($mysqli) !== 1) {
        $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_event_status,
            automation_event_action, automation_event_ticket_id, automation_event_last_error
            FROM automation_events WHERE automation_event_id = $event_id LIMIT 1"));
        return [
            'event_id' => $event_id,
            'status' => (string) ($current['automation_event_status'] ?? 'Unavailable'),
            'action' => (string) ($current['automation_event_action'] ?? 'lease_lost'),
            'ticket_id' => intval($current['automation_event_ticket_id'] ?? 0),
            'error' => (string) ($current['automation_event_last_error'] ?? $error_text),
            'lease_lost' => true,
        ];
    }

    return [
        'event_id' => $event_id,
        'status' => $status,
        'action' => 'processing_failed',
        'ticket_id' => 0,
        'error' => $error_text,
        'error_type' => $error instanceof AutomationConflictException ? 'conflict'
            : ($error instanceof InvalidArgumentException ? 'invalid' : 'transient'),
    ];
}

function automationProcessStoredEvent(int $event_id): array
{
    global $mysqli, $automation_event_audit_buffer;

    if (!n45FeatureEnabled('automation')) {
        throw new RuntimeException('Automation processing is disabled by deployment feature flag');
    }

    $event_id = max(0, $event_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_events
        WHERE automation_event_id = $event_id LIMIT 1"));
    if (!$row) {
        throw new InvalidArgumentException('Automation event not found');
    }
    if (in_array($row['automation_event_status'], ['Processed', 'Dead'], true)) {
        return [
            'event_id' => $event_id,
            'status' => (string) $row['automation_event_status'],
            'action' => (string) $row['automation_event_action'],
            'ticket_id' => intval($row['automation_event_ticket_id']),
            'error' => (string) ($row['automation_event_last_error'] ?? ''),
        ];
    }

    $lease_token = hash('sha256', random_bytes(32));
    $lease_sql = automationDbEscape($lease_token);
    $claimed = mysqli_query($mysqli, "UPDATE automation_events SET
        automation_event_status = 'Processing',
        automation_event_process_attempts = automation_event_process_attempts + 1,
        automation_event_processing_at = NOW(),
        automation_event_lease_token = '$lease_sql',
        automation_event_next_attempt_at = NULL
        WHERE automation_event_id = $event_id
        AND automation_event_process_attempts < automation_event_max_attempts
        AND (
            automation_event_status = 'Pending'
            OR (automation_event_status = 'Failed'
                AND (automation_event_next_attempt_at IS NULL OR automation_event_next_attempt_at <= NOW()))
            OR (automation_event_status = 'Processing'
                AND automation_event_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        )");
    if (!$claimed || mysqli_affected_rows($mysqli) !== 1) {
        $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_event_action,
            automation_event_status, automation_event_ticket_id, automation_event_last_error
            FROM automation_events WHERE automation_event_id = $event_id LIMIT 1"));
        return [
            'event_id' => $event_id,
            'status' => (string) ($current['automation_event_status'] ?? 'Unavailable'),
            'action' => (string) ($current['automation_event_action'] ?? 'not_claimed'),
            'ticket_id' => intval($current['automation_event_ticket_id'] ?? 0),
            'error' => (string) ($current['automation_event_last_error'] ?? ''),
        ];
    }

    $claimed_row_sql = mysqli_query($mysqli, "SELECT * FROM automation_events
        WHERE automation_event_id = $event_id AND automation_event_lease_token = '$lease_sql'
        LIMIT 1");
    $row = $claimed_row_sql ? mysqli_fetch_assoc($claimed_row_sql) : null;
    if (!$row) {
        $read_error = new RuntimeException('The automation event lease could not be read after it was claimed');
        return automationEventFail(
            $event_id,
            $read_error,
            automationEventPolicyDefaults((string) ($row['automation_event_source'] ?? 'unknown')),
            $lease_token,
            intval($row['automation_event_process_attempts'] ?? 1)
        );
    }
    $policy = automationEventPolicyDefaults((string) $row['automation_event_source']);
    $attempts = intval($row['automation_event_process_attempts']);
    $acquired_locks = [];

    try {
        $policy = automationEventPolicy((string) $row['automation_event_source']);
        if (empty($row['automation_event_payload'])) {
            throw new InvalidArgumentException('The retained event payload is unavailable for replay');
        }
        $event = json_decode($row['automation_event_payload'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new InvalidArgumentException('The retained event payload is invalid');
        }
        $event = automationEventEnvelope($event);
        $identity = $event['identity'];
        $lock_names = automationIdentityLockNames($identity);
        $lock_names[] = 'itflow_automation_' . sha1($event['source'] . ':' . $event['incident_key']);
        $acquired_locks = automationAcquireNamedLocks($lock_names);

        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the lease-owned automation transaction');
        }

        $post_commit_action = null;
        $notification = null;
        $automation_event_audit_buffer = [];
        try {
            $lock_order = new N45LockOrder('automation event processing');
            automationEventLockAuthority($row, $lock_order);
            $candidate_client_id = automationEventCandidateClientId($event);
            $authorized_client_id = max(0, intval($row['automation_event_authorized_client_id'] ?? 0));
            automationEventLockClient($candidate_client_id, $authorized_client_id, $lock_order);

            $lock_order->observe('settings', 1);
            $locked_settings = mysqli_fetch_assoc(automationDbQuery("SELECT config_module_enable_ticketing,
                config_ticket_default_billable, config_ticket_prefix FROM settings
                WHERE company_id = 1 LIMIT 1 FOR UPDATE", 'Could not lock automation ticket settings'));
            if (!$locked_settings) {
                throw new RuntimeException('Automation ticket settings are unavailable');
            }

            $resolved = [];
            $ticket_id = intval($row['automation_event_ticket_id'] ?? 0);
            $action = 'recorded';
            $status = 'Open';
            $suppressed_reason = null;
            $suppressed_increment = 0;
            $maintenance_id = 0;

            if (!$policy['enabled'] && $ticket_id < 1) {
                $action = 'source_disabled';
                $status = 'Suppressed';
                $suppressed_reason = 'source_disabled';
            } else {
                $lock_order->observe('asset');
                $lock_order->observe('identity');
                $resolved = automationResolveIdentityUnlocked($identity);
                $resolved = automationEventResolveService(intval($event['service_id']), $resolved);
                $resolved_client_id = intval($resolved['client_id'] ?? 0);
                if ($resolved_client_id !== $candidate_client_id
                    || ($authorized_client_id > 0 && $resolved_client_id !== $authorized_client_id)
                    || ($resolved_client_id > 0 && !automationUserCanAccessClient($resolved_client_id))) {
                    throw new AutomationConflictException('The resolved client no longer matches the locked event authority');
                }

                $source_sql = automationDbEscape($event['source']);
                $incident_key_sql = automationDbEscape($event['incident_key']);
                $lock_order->observe('automation_incident');
                $incident = mysqli_fetch_assoc(automationDbQuery("SELECT * FROM automation_incidents
                    WHERE automation_incident_source = '$source_sql'
                    AND automation_incident_key = '$incident_key_sql' LIMIT 1 FOR UPDATE",
                    'Could not lock the automation incident')) ?: null;
                $ticket_id = $ticket_id > 0
                    ? $ticket_id : intval($incident['automation_incident_ticket_id'] ?? 0);
                $incident_status = (string) ($incident['automation_incident_status'] ?? '');
                $fingerprint = (string) ($row['automation_event_fingerprint'] ?: $row['automation_event_payload_hash']);

                if ($incident && !empty($incident['automation_incident_last_event_at'])
                    && $event['occurred_at'] < $incident['automation_incident_last_event_at']) {
                    automationEventRecordStaleIncident($incident, $event, $fingerprint);
                    $action = 'stale';
                    $status = $incident_status;
                    $suppressed_reason = 'out_of_order';
                } elseif ($event['state'] === 'resolved') {
                    if ($ticket_id > 0 && $incident_status === 'Open') {
                        $reply = automationLimitText(
                            $event['description'] ?: ($event['title'] . ' recovered.'), 8000
                        );
                        $auto_resolve = $policy['auto_resolve'] && $event['auto_resolve'];
                        $reply_result = automationAddIncidentReply(
                            $ticket_id,
                            intval($incident['automation_incident_client_id']),
                            $reply,
                            $auto_resolve,
                            true,
                            $lock_order
                        );
                        $post_commit_action = $reply_result['post_commit_action'];
                        if ($auto_resolve && $reply_result['resolved']) {
                            $action = 'resolved';
                            $status = 'Resolved';
                        } elseif ($auto_resolve && $reply_result['blocked']) {
                            $action = 'recovery_recorded';
                            $status = 'Open';
                        } else {
                            $action = 'recovery_recorded';
                            $status = 'Resolved';
                        }
                    } else {
                        $action = 'recovery_without_open_incident';
                        $status = 'Resolved';
                    }
                    automationEventSaveIncident($event, $resolved, $incident, $status, $action,
                        $ticket_id, $fingerprint, 0);
                } else {
                    if ($incident_status === 'Resolved' && $ticket_id > 0) {
                        $lock_order->observe('ticket', $ticket_id);
                        $open_ticket = mysqli_fetch_assoc(automationDbQuery("SELECT ticket_id FROM tickets
                            WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL
                            AND ticket_resolved_at IS NULL AND ticket_status <> 4 LIMIT 1 FOR UPDATE",
                            'Could not inspect the prior automation ticket'));
                        if (!$open_ticket) {
                            $ticket_id = 0;
                        }
                    }

                    $maintenance = automationEventActiveMaintenanceWindow(
                        $event['source'], $resolved_client_id, intval($resolved['asset_id']),
                        intval($resolved['service_id']), $event['occurred_at']
                    );
                    $occurrences = automationEventThresholdOccurrences(
                        $event['source'], $event['incident_key'], intval($policy['threshold_window_minutes'])
                    );
                    if ($maintenance) {
                        $action = 'maintenance_suppressed';
                        $status = $incident_status === 'Open' ? 'Open' : 'Suppressed';
                        $suppressed_reason = 'maintenance_window';
                        $suppressed_increment = 1;
                        $maintenance_id = intval($maintenance['automation_maintenance_id']);
                    } elseif ($ticket_id < 1 && $occurrences < intval($policy['threshold_count'])) {
                        $action = 'threshold_waiting';
                        $status = $incident_status === 'Open' ? 'Open' : 'Pending';
                        $suppressed_reason = 'ticket_threshold';
                        $suppressed_increment = 1;
                    } elseif (!$policy['ticket_enabled'] && $ticket_id < 1) {
                        $action = 'recorded_no_ticket';
                        $status = 'Open';
                    } elseif ($ticket_id < 1) {
                        $ticket = automationCreateIncidentTicket(
                            $event, $resolved, true, $locked_settings, $lock_order
                        );
                        $ticket_id = intval($ticket['ticket_id']);
                        $notification = $ticket['post_commit_notification'];
                        $action = 'created';
                        $status = 'Open';
                    } elseif ($incident && hash_equals(
                        (string) ($incident['automation_incident_last_event_hash'] ?? ''), $fingerprint
                    )) {
                        $action = 'unchanged';
                        $status = 'Open';
                    } else {
                        $reply = automationLimitText(
                            $event['description'] ?: ($event['title'] . ' remains active.'), 8000
                        );
                        automationAddIncidentReply(
                            $ticket_id,
                            intval($incident['automation_incident_client_id'] ?? $resolved_client_id),
                            $reply,
                            false,
                            true,
                            $lock_order
                        );
                        $action = 'updated';
                        $status = 'Open';
                    }
                    automationEventSaveIncident($event, $resolved, $incident, $status, $action,
                        $ticket_id, $fingerprint, $suppressed_increment);
                }
            }

            automationEventComplete(
                $event_id, $action, $ticket_id, $suppressed_reason, $maintenance_id,
                $lease_token, $lock_order
            );
            if ($notification) {
                appNotify($notification['type'], $notification['details'], $notification['action'],
                    $notification['client_id'], $notification['entity_id']);
            }
            automationEventFlushAudits($lock_order);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the lease-owned automation transaction');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            $automation_event_audit_buffer = null;
            throw $e;
        }

        $automation_event_audit_buffer = null;
        if ($post_commit_action) {
            triggerCustomAction(
                $post_commit_action['trigger'],
                $post_commit_action['entity_id'],
                'automation-event-' . $event_id
            );
        }
        return [
            'event_id' => $event_id,
            'status' => 'Processed',
            'action' => $action,
            'ticket_id' => $ticket_id,
            'mapping' => $resolved ?: null,
        ];
    } catch (Throwable $e) {
        $automation_event_audit_buffer = null;
        return automationEventFail($event_id, $e, $policy, $lease_token, $attempts);
    } finally {
        automationReleaseNamedLocks($acquired_locks);
    }
}

function automationProcessEventQueue(int $limit = 100): array
{
    global $mysqli;

    if (!n45FeatureEnabled('automation')) {
        throw new RuntimeException('Automation processing is disabled by deployment feature flag');
    }

    $limit = min(500, max(1, $limit));
    $summary = ['processed' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0];
    mysqli_query($mysqli, "UPDATE automation_events SET
        automation_event_status = 'Dead',
        automation_event_action = 'processing_failed',
        automation_event_processing_at = NULL,
        automation_event_lease_token = NULL,
        automation_event_last_error = 'Processing lease expired after the retry limit was reached'
        WHERE automation_event_status = 'Processing'
        AND automation_event_process_attempts >= automation_event_max_attempts
        AND automation_event_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $sql = mysqli_query($mysqli, "SELECT automation_event_id FROM automation_events
        WHERE automation_event_process_attempts < automation_event_max_attempts
        AND (
            automation_event_status = 'Pending'
            OR (automation_event_status = 'Failed'
                AND (automation_event_next_attempt_at IS NULL OR automation_event_next_attempt_at <= NOW()))
            OR (automation_event_status = 'Processing'
                AND automation_event_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        )
        ORDER BY automation_event_received_at ASC
        LIMIT $limit");

    while ($row = mysqli_fetch_assoc($sql)) {
        $result = automationProcessStoredEvent(intval($row['automation_event_id']));
        $status = strtolower((string) ($result['status'] ?? ''));
        if ($status === 'processed') {
            $summary['processed']++;
        } elseif ($status === 'dead') {
            $summary['dead']++;
        } elseif ($status === 'failed') {
            $summary['failed']++;
        } else {
            $summary['skipped']++;
        }
    }

    // Retain event metadata indefinitely but remove old redacted payload bodies
    // according to each source policy. Dead letters keep their payload until an
    // administrator resolves or replays them.
    mysqli_query($mysqli, "UPDATE automation_events SET automation_event_payload = NULL
        WHERE automation_event_status = 'Processed'
        AND automation_event_payload IS NOT NULL
        AND TIMESTAMPDIFF(DAY, automation_event_last_received_at, NOW()) >=
            COALESCE((SELECT automation_policy_payload_retention_days
                FROM automation_event_policies
                WHERE automation_policy_source = automation_event_source LIMIT 1), 30)
        LIMIT 1000");

    return $summary;
}

function automationReplayEvent(int $event_id): bool
{
    global $mysqli;

    if (!n45FeatureEnabled('automation')) {
        return false;
    }

    $event_id = max(0, $event_id);
    $event = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_event_source
        FROM automation_events WHERE automation_event_id = $event_id
        AND automation_event_status IN ('Failed', 'Dead')
        AND automation_event_payload IS NOT NULL LIMIT 1"));
    if (!$event) {
        return false;
    }
    $policy = automationEventPolicy((string) $event['automation_event_source']);
    $max_attempts = intval($policy['max_attempts']);
    mysqli_query($mysqli, "UPDATE automation_events SET
        automation_event_status = 'Pending',
        automation_event_action = 'replay_queued',
        automation_event_process_attempts = 0,
        automation_event_max_attempts = $max_attempts,
        automation_event_processing_at = NULL,
        automation_event_lease_token = NULL,
        automation_event_next_attempt_at = NULL,
        automation_event_last_error = NULL,
        automation_event_replay_count = automation_event_replay_count + 1,
        automation_event_replayed_at = NOW()
        WHERE automation_event_id = $event_id
        AND automation_event_status IN ('Failed', 'Dead')
        AND automation_event_payload IS NOT NULL");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return false;
    }

    automationQueueEventProcessor();
    return true;
}

function automationQueueEventProcessor(): void
{
    global $mysqli;
    if (!n45FeatureEnabled('automation')) {
        return;
    }
    mysqli_query($mysqli, "INSERT IGNORE INTO cron_jobs SET
        cron_job_name = 'automation_event_processor', cron_job_enabled = 1,
        cron_job_schedule = 'Interval', cron_job_interval_minutes = 1");
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1
        WHERE cron_job_name = 'automation_event_processor'");
}

/**
 * Record an event already processed by a first-party adapter, such as Level.
 * The adapter remains responsible for its source-specific side effects while
 * Operations receives the same redacted event and incident history.
 */
function automationMirrorProcessedEvent(array $input, array $resolved,
    string $action, string $incident_status): array
{
    global $mysqli;

    // First-party adapters may remain enabled independently. The Operations
    // kill switch must therefore stop their shared mirror before any DB write.
    if (!n45FeatureEnabled('automation')) {
        return [
            'skipped' => true,
            'reason' => 'automation_disabled',
            'duplicate' => false,
            'action' => 'skipped',
            'ticket_id' => max(0, intval($resolved['ticket_id'] ?? 0)),
        ];
    }

    $event = automationEventEnvelope($input);
    $document = automationEventDocument($event);
    $policy = automationEventPolicy($event['source']);
    $source_sql = automationDbEscape($event['source']);
    $event_external_sql = automationDbEscape($event['event_id']);
    $incident_key_sql = automationDbEscape($event['incident_key']);
    $fingerprint_sql = automationDbEscape($document['fingerprint']);
    $state_sql = automationDbEscape($event['state']);
    $action_sql = automationDbEscape(automationLimitText($action, 40));
    $payload_hash_sql = automationDbEscape($document['payload_hash']);
    $payload_sql = automationDbEscape($document['payload']);
    $occurred_at_sql = automationDbEscape($event['occurred_at']);
    $ticket_id = max(0, intval($resolved['ticket_id'] ?? 0));
    $max_attempts = intval($policy['max_attempts']);

    $lock_name = automationDbEscape('itflow_automation_' . sha1($event['source'] . ':' . $event['incident_key']));
    $lock_acquired = false;
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not start the mirrored event transaction');
    }

    try {
        automationDbQuery("INSERT INTO automation_events SET
            automation_event_source = '$source_sql',
            automation_event_external_id = '$event_external_sql',
            automation_event_incident_key = '$incident_key_sql',
            automation_event_fingerprint = '$fingerprint_sql',
            automation_event_state = '$state_sql',
            automation_event_action = '$action_sql',
            automation_event_status = 'Processed',
            automation_event_process_attempts = 1,
            automation_event_max_attempts = $max_attempts,
            automation_event_ticket_id = $ticket_id,
            automation_event_payload_hash = '$payload_hash_sql',
            automation_event_payload = '$payload_sql',
            automation_event_occurred_at = '$occurred_at_sql',
            automation_event_processed_at = NOW()
            ON DUPLICATE KEY UPDATE
            automation_event_delivery_count = automation_event_delivery_count + 1,
            automation_event_last_received_at = NOW()",
            'Could not mirror the processed automation event');

        if (mysqli_affected_rows($mysqli) !== 1) {
            $existing = mysqli_fetch_assoc(automationDbQuery("SELECT automation_event_occurred_at,
                automation_event_payload_hash FROM automation_events
                WHERE automation_event_source = '$source_sql'
                AND automation_event_external_id = '$event_external_sql' LIMIT 1",
                'Could not verify the mirrored event duplicate'));
            if (!empty($existing['automation_event_occurred_at'])
                && !hash_equals((string) $existing['automation_event_payload_hash'], $document['payload_hash'])) {
                throw new AutomationConflictException('event_id was already used for a different payload');
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the mirrored event duplicate');
            }
            return ['duplicate' => true, 'action' => 'duplicate', 'ticket_id' => $ticket_id];
        }
        $event_db_id = mysqli_insert_id($mysqli);

        $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name', 10)"));
        if (intval($lock_row[0] ?? 0) !== 1) {
            throw new RuntimeException('Could not obtain the mirrored incident lock');
        }
        $lock_acquired = true;

        $incident = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_incidents
            WHERE automation_incident_source = '$source_sql'
            AND automation_incident_key = '$incident_key_sql' LIMIT 1")) ?: null;
        if ($incident && !empty($incident['automation_incident_last_event_at'])
            && $event['occurred_at'] < $incident['automation_incident_last_event_at']) {
            $action = 'stale';
            $incident_status = (string) $incident['automation_incident_status'];
            automationEventRecordStaleIncident($incident, $event, $document['fingerprint']);
        } else {
            automationEventSaveIncident(
                $event,
                $resolved,
                $incident,
                $incident_status,
                $action,
                $ticket_id,
                $document['fingerprint'],
                in_array($action, ['maintenance_suppressed', 'threshold_waiting', 'source_disabled'], true) ? 1 : 0
            );
        }
        $suppressed_reason = match ($action) {
            'maintenance_suppressed' => 'maintenance_window',
            'threshold_waiting' => 'ticket_threshold',
            'source_disabled' => 'source_disabled',
            default => null,
        };
        automationEventComplete(
            $event_db_id,
            $action,
            $ticket_id,
            $suppressed_reason,
            intval($resolved['maintenance_window_id'] ?? 0)
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the mirrored automation event');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    } finally {
        if ($lock_acquired) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name')");
        }
    }

    return ['duplicate' => false, 'action' => $action, 'ticket_id' => $ticket_id];
}
