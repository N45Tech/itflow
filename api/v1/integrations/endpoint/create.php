<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

try {
    $source = integrationIdentitySource($_POST['source'] ?? '');
    if (!in_array($source, ['entra', 'intune', 'sentinelone'], true)) {
        throw new InvalidArgumentException('Identity adapter source must be entra, intune, or sentinelone');
    }
    $external_id = integrationIdentityExternalId($_POST['external_id'] ?? '');
    $external_parent_id = integrationIdentityExternalId($_POST['external_parent_id'] ?? '');
    $client_id = endpointPositiveInt($_POST['client_id'] ?? 0);
    $asset_id = endpointPositiveInt($_POST['asset_id'] ?? 0);
    if ($client_id < 1) {
        throw new InvalidArgumentException('Identity adapter client_id must be a positive integer');
    }
    $state = integrationIdentityState($_POST['state'] ?? 'automatic');
    if (!in_array($state, ['automatic', 'suggested', 'conflicting', 'unresolved'], true)) {
        throw new InvalidArgumentException('Identity adapters cannot assert a technician decision state');
    }
    if ($state === 'automatic' && $asset_id < 1) {
        throw new InvalidArgumentException('An automatic identity requires an exact asset_id');
    }
    if (!array_key_exists('identity_facts', $_POST)
        || !is_array($_POST['identity_facts'])
        || ($_POST['identity_facts'] !== [] && array_is_list($_POST['identity_facts']))) {
        throw new InvalidArgumentException('identity_facts must be a JSON object');
    }
    if (isset($_POST['metadata'])
        && (!is_array($_POST['metadata'])
            || ($_POST['metadata'] !== [] && array_is_list($_POST['metadata'])))) {
        throw new InvalidArgumentException('metadata must be a JSON object');
    }
    $observed_at = endpointObservationDateTime($_POST['observed_at'] ?? null);
    $strategy = integrationIdentityLimitText($_POST['strategy'] ?? 'adapter_exact_id', 40);
    if ($strategy === '') {
        $strategy = 'adapter_exact_id';
    }
    $confidence = integrationIdentityConfidence(
        $_POST['confidence'] ?? ($state === 'automatic' ? 100 : 0)
    );
    $mapping = integrationIdentityUpsertMapping([
        'source' => $source,
        'entity_type' => 'device',
        'external_id' => $external_id,
        'external_parent_id' => $external_parent_id,
        'external_name' => $_POST['external_name'] ?? '',
        'client_id' => $client_id,
        'asset_id' => $asset_id,
        'state' => $state,
        'strategy' => $strategy,
        'confidence' => $confidence,
        'last_seen_at' => $observed_at,
        'last_error' => $_POST['last_error'] ?? '',
        'metadata' => $_POST['metadata'] ?? [],
        'authorized_client_id' => $client_id,
    ]);

    if (intval($mapping['automation_mapping_client_id'] ?? 0) !== $client_id) {
        throw new EndpointConflictException('Identity adapter result belongs to a different client');
    }
    if ($asset_id > 0 && intval($mapping['automation_mapping_asset_id'] ?? 0) !== $asset_id) {
        throw new EndpointConflictException('Identity adapter target conflicts with the durable asset binding');
    }
    $snapshot = integrationIdentityRecordSnapshot([
        'source' => $source,
        'entity_type' => 'device',
        'external_id' => $external_id,
        'client_id' => intval($mapping['automation_mapping_client_id']),
        'asset_id' => intval($mapping['automation_mapping_asset_id']),
        'observed_at' => $observed_at,
        'facts' => $_POST['identity_facts'],
    ]);

    $mapping_id = intval($mapping['automation_mapping_id']);
    logAudit(
        'Endpoint Identity',
        'Ingest',
        'Registered a tenant-scoped external endpoint identity',
        $client_id,
        $mapping_id
    );
    echo json_encode([
        'success' => 'True',
        'message' => 'Endpoint identity registered.',
        'data' => [[
            'mapping_id' => $mapping_id,
            'client_id' => $client_id,
            'asset_id' => intval($mapping['automation_mapping_asset_id']),
            'source' => $source,
            'state' => (string) $mapping['automation_mapping_state'],
            'stale_delivery' => !empty($mapping['integration_identity_stale_delivery']),
            'snapshot_id' => intval($snapshot['snapshot_id']),
            'snapshot_changed' => !empty($snapshot['changed']),
        ]],
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('HTTP/1.1 422 Unprocessable Entity');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (EndpointConflictException $e) {
    header('HTTP/1.1 409 Conflict');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $is_conflict = str_contains(strtolower($e->getMessage()), 'different client')
        || str_contains(strtolower($e->getMessage()), 'already has')
        || str_contains(strtolower($e->getMessage()), 'remap blocked');
    header($is_conflict ? 'HTTP/1.1 409 Conflict' : 'HTTP/1.1 500 Internal Server Error');
    if (!$is_conflict) {
        error_log('Endpoint identity adapter API failed: ' . $e->getMessage());
    }
    echo json_encode([
        'success' => 'False',
        'message' => $is_conflict
            ? $e->getMessage()
            : 'The endpoint identity could not be registered.',
    ]);
} catch (Throwable $e) {
    error_log('Endpoint identity adapter API failed: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'The endpoint identity could not be registered.']);
}
