<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

function automationEventResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit();
}

if (!n45FeatureEnabled('automation')) {
    automationEventResponse(503, [
        'success' => 'False',
        'message' => 'Automation ingestion is disabled by deployment feature flag.',
    ]);
}

try {
    $receipt = automationEventQueue(is_array($_POST) ? $_POST : []);

    if ($receipt['duplicate']) {
        $status = strtolower((string) $receipt['status']);
        if (in_array($status, ['pending', 'failed'], true)) {
            automationQueueEventProcessor();
        }

        automationEventResponse(200, [
            'success' => 'True',
            'message' => 'Duplicate delivery acknowledged.',
            'data' => [[
                'action' => 'duplicate',
                'previous_action' => $receipt['action'],
                'event_status' => $receipt['status'],
                'event_id' => $receipt['event_id'],
                'ticket_id' => $receipt['ticket_id'],
                'delivery_count' => $receipt['delivery_count'],
            ]],
        ]);
    }

    $result = automationProcessStoredEvent($receipt['event_id']);
    $status = strtolower((string) ($result['status'] ?? ''));

    if ($status === 'processed') {
        automationEventResponse(200, [
            'success' => 'True',
            'message' => 'Automation event processed.',
            'data' => [[
                'action' => $result['action'],
                'event_id' => $result['event_id'],
                'ticket_id' => intval($result['ticket_id'] ?? 0),
                'mapping' => $result['mapping'] ?? null,
            ]],
        ]);
    }

    if ($status === 'dead') {
        $error_type = (string) ($result['error_type'] ?? 'transient');
        $http_status = $error_type === 'conflict' ? 409 : ($error_type === 'invalid' ? 422 : 500);
        automationEventResponse($http_status, [
            'success' => 'False',
            'message' => $error_type === 'transient'
                ? 'The automation event reached the retry limit and requires review.'
                : (string) ($result['error'] ?? 'The automation event requires review.'),
            'data' => [[
                'action' => 'dead_lettered',
                'event_id' => $result['event_id'],
            ]],
        ]);
    }

    automationQueueEventProcessor();
    automationEventResponse(202, [
        'success' => 'True',
        'message' => 'Automation event accepted and queued for retry.',
        'data' => [[
            'action' => 'retry_queued',
            'event_id' => $result['event_id'],
            'event_status' => $result['status'],
        ]],
    ]);
} catch (AutomationConflictException $e) {
    automationEventResponse(409, ['success' => 'False', 'message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    automationEventResponse(422, ['success' => 'False', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Automation event API failed: ' . $e->getMessage());
    automationEventResponse(500, ['success' => 'False', 'message' => 'The automation event could not be accepted.']);
}
