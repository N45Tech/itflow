<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

try {
    if (!array_key_exists('facts', $_POST)
        || !is_array($_POST['facts'])
        || ($_POST['facts'] !== [] && array_is_list($_POST['facts']))) {
        throw new InvalidArgumentException('Endpoint facts must be a JSON object');
    }
    if (!array_key_exists('network_interfaces', $_POST)
        || !is_array($_POST['network_interfaces'])
        || !array_is_list($_POST['network_interfaces'])) {
        throw new InvalidArgumentException('Endpoint network_interfaces must be a JSON array');
    }
    $source = endpointSource($_POST['source'] ?? '');
    if ($source === 'itflow') {
        throw new InvalidArgumentException('The ITFlow endpoint source is reserved for internal records');
    }
    $asset_id = endpointPositiveInt($_POST['asset_id'] ?? 0);
    $client_id = endpointPositiveInt($_POST['client_id'] ?? 0);
    if ($asset_id < 1 || $client_id < 1) {
        throw new InvalidArgumentException('Endpoint asset_id and client_id must be positive integers');
    }
    $facts = $_POST['facts'];
    $network_interfaces = $_POST['network_interfaces'];
    $result = endpointReconcileAssetSource([
        'asset_id' => $asset_id,
        'client_id' => $client_id,
        'source' => $source,
        'external_id' => $_POST['external_id'] ?? '',
        'status' => $_POST['status'] ?? 'active',
        'observed_at' => $_POST['observed_at'] ?? null,
        'facts' => $facts,
        'network_interfaces' => $network_interfaces,
    ]);

    logAudit(
        'Endpoint',
        'Reconcile',
        'Reconciled the canonical endpoint and network record',
        $client_id,
        $asset_id
    );
    echo json_encode([
        'success' => 'True',
        'message' => 'Endpoint record reconciled.',
        'data' => [[
            'asset_id' => $asset_id,
            'source' => $source,
            'state_changed' => !empty($result['state']['changed']),
            'stale_delivery' => !empty($result['state']['stale']) || !empty($result['network']['stale']),
            'network' => $result['network'],
        ]],
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('HTTP/1.1 422 Unprocessable Entity');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (EndpointConflictException $e) {
    header('HTTP/1.1 409 Conflict');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $message = strtolower($e->getMessage());
    $is_conflict = str_contains($message, 'different')
        || str_contains($message, 'already bound')
        || str_contains($message, 'belongs to')
        || str_contains($message, 'disagrees')
        || str_contains($message, 'cannot publish');
    header($is_conflict ? 'HTTP/1.1 409 Conflict' : 'HTTP/1.1 500 Internal Server Error');
    if (!$is_conflict) {
        error_log('Endpoint reconciliation API failed: ' . $e->getMessage());
    }
    echo json_encode([
        'success' => 'False',
        'message' => $is_conflict
            ? $e->getMessage()
            : 'The endpoint record could not be reconciled.',
    ]);
} catch (Throwable $e) {
    error_log('Endpoint reconciliation API failed: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'The endpoint record could not be reconciled.']);
}
