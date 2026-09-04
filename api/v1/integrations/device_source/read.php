<?php

require_once '../../validate_api_key.php';

try {
    $requested_client_id = endpointPositiveInt($_GET['client_id'] ?? 0);
    $source = endpointLimitText($_GET['source'] ?? '', 40);
    $rows = deviceSourceHealthRows($requested_client_id, $source);
    echo json_encode([
        'success' => 'True',
        'message' => 'Device source health loaded.',
        'count' => count($rows),
        'data' => $rows,
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('HTTP/1.1 422 Unprocessable Entity');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    error_log('Device source health API failed: ' . deviceSourceRedactError($e->getMessage()));
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'Device source health could not be loaded.']);
} catch (Throwable $e) {
    error_log('Device source health API failed: ' . deviceSourceRedactError($e->getMessage()));
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'Device source health could not be loaded.']);
}
