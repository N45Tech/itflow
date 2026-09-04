<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

try {
    $action = strtolower(endpointLimitText($_POST['action'] ?? 'publish', 20));
    if ($action === 'publish') {
        $result = deviceSourcePublish($_POST);
        $audit_action = 'Publish';
        $audit_description = 'Published a normalized external device observation';
    } elseif ($action === 'complete') {
        $result = deviceSourceComplete($_POST);
        $audit_action = 'Complete';
        $audit_description = 'Completed a scoped external device full sync';
    } elseif ($action === 'failure') {
        $result = deviceSourceRecordFailure($_POST);
        $audit_action = 'Failure';
        $audit_description = 'Recorded a scoped external device sync failure';
    } else {
        throw new InvalidArgumentException('Device source action must be publish, complete, or failure');
    }

    logAudit(
        'Device Source',
        $audit_action,
        $audit_description . ': ' . ($result['source'] ?? 'unknown')
            . '/' . ($result['scope_id'] ?? 'unknown'),
        intval($result['client_id'] ?? 0),
        intval($result['asset_id'] ?? 0)
    );
    echo json_encode([
        'success' => 'True',
        'message' => $audit_description . '.',
        'data' => [$result],
    ], JSON_UNESCAPED_SLASHES);
} catch (AutomationConflictException|EndpointConflictException $e) {
    header('HTTP/1.1 409 Conflict');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    header('HTTP/1.1 422 Unprocessable Entity');
    echo json_encode(['success' => 'False', 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $safe_message = deviceSourceRedactError($message);
    $is_conflict = str_contains(strtolower($message), 'different')
        || str_contains(strtolower($message), 'changed')
        || str_contains(strtolower($message), 'already bound')
        || str_contains(strtolower($message), 'cannot access')
        || str_contains(strtolower($message), 'retirement guard');
    header($is_conflict ? 'HTTP/1.1 409 Conflict' : 'HTTP/1.1 500 Internal Server Error');
    if (!$is_conflict) {
        error_log('Device source API failed: ' . $safe_message);
    }
    echo json_encode([
        'success' => 'False',
        'message' => $is_conflict ? $safe_message : 'The device source update could not be completed.',
    ]);
} catch (Throwable $e) {
    error_log('Device source API failed: ' . deviceSourceRedactError($e->getMessage()));
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => 'False', 'message' => 'The device source update could not be completed.']);
}
