<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

if (!n45FeatureEnabled('automation')) {
    header('HTTP/1.1 503 Service Unavailable');
    echo json_encode([
        'success' => 'False',
        'message' => 'Automation identity resolution is disabled by deployment feature flag.',
    ]);
    exit();
}

try {
    $resolved = automationResolveIdentity($_POST);
    $return_arr = [
        'success' => 'True',
        'message' => 'External identity resolved.',
        'data' => [$resolved],
    ];
    logAudit('Automation', 'Resolve', 'Resolved an external entity via the automation API', intval($resolved['client_id']), intval($resolved['asset_id']));
    echo json_encode($return_arr);
} catch (AutomationConflictException $e) {
    header('HTTP/1.1 409 Conflict');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    header('HTTP/1.1 422 Unprocessable Entity');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Automation resolve API failed: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'The external identity could not be resolved.']);
}
