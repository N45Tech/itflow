<?php

require_once '../../validate_api_key.php';
require_once '../../require_post_method.php';

class AutomationDuplicateEventException extends RuntimeException
{
    public function __construct(public string $previous_action, public int $ticket_id)
    {
        parent::__construct('Duplicate event acknowledged.');
    }
}

function automationEventResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit();
}

try {
    global $mysqli;

    $source = automationSource($_POST['source'] ?? '');
    $event_id = automationLimitText($_POST['event_id'] ?? '', 255);
    $incident_key = automationLimitText($_POST['incident_key'] ?? '', 255);
    $state = strtolower(automationLimitText($_POST['state'] ?? 'open', 20));
    if ($event_id === '' || $incident_key === '') {
        throw new InvalidArgumentException('event_id and incident_key are required');
    }
    if (!in_array($state, ['open', 'update', 'resolved'], true)) {
        throw new InvalidArgumentException('state must be open, update, or resolved');
    }

    $source_sql = automationDbEscape($source);
    $event_id_sql = automationDbEscape($event_id);
    $existing_event = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_event_action,
        automation_event_ticket_id FROM automation_events WHERE automation_event_source = '$source_sql'
        AND automation_event_external_id = '$event_id_sql' LIMIT 1"));
    if ($existing_event) {
        automationEventResponse(200, [
            'success' => 'True',
            'message' => 'Duplicate event acknowledged.',
            'data' => [[
                'action' => 'duplicate',
                'previous_action' => $existing_event['automation_event_action'],
                'ticket_id' => intval($existing_event['automation_event_ticket_id']),
            ]],
        ]);
    }

    $identity = is_array($_POST['identity'] ?? null) ? $_POST['identity'] : [];
    $identity['source'] = $source;
    if (empty($identity['entity_type'])) {
        $identity['entity_type'] = automationEntityType($_POST['entity_type'] ?? 'incident');
    }
    if (empty($identity['external_id'])) {
        $identity['external_id'] = $incident_key;
    }
    if (empty($identity['external_name'])) {
        $identity['external_name'] = automationLimitText($_POST['title'] ?? $incident_key, 255);
    }
    $resolved = automationResolveIdentity($identity);
    $client_id = intval($resolved['client_id']);
    $location_id = intval($resolved['location_id']);
    $asset_id = intval($resolved['asset_id']);

    $lock_name = automationDbEscape('itflow_automation_' . sha1($source . ':' . $incident_key));
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name', 10)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Could not obtain the automation incident lock');
    }

    $action = 'recorded';
    $ticket_id = 0;
    try {
        // A second delivery can pass the fast duplicate check while the first is
        // still resolving identity. Recheck after acquiring the incident lock.
        $existing_event = mysqli_fetch_assoc(automationDbQuery("SELECT automation_event_action,
            automation_event_ticket_id FROM automation_events WHERE automation_event_source = '$source_sql'
            AND automation_event_external_id = '$event_id_sql' LIMIT 1",
            'Could not check event idempotency'));
        if ($existing_event) {
            throw new AutomationDuplicateEventException(
                (string) $existing_event['automation_event_action'],
                intval($existing_event['automation_event_ticket_id'])
            );
        }

        $incident_key_sql = automationDbEscape($incident_key);
        $incident = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM automation_incidents
            WHERE automation_incident_source = '$source_sql'
            AND automation_incident_key = '$incident_key_sql' LIMIT 1"));
        $ticket_id = intval($incident['automation_incident_ticket_id'] ?? 0);
        $title = automationLimitText($_POST['title'] ?? 'Automation event', 500);
        $title_sql = automationDbEscape($title);
        $severity = strtolower(automationLimitText($_POST['severity'] ?? 'low', 20));
        $severity_sql = automationDbEscape($severity);
        $payload_json = json_encode($_POST, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload_hash = hash('sha256', $payload_json === false ? '' : $payload_json);
        $payload_hash_sql = automationDbEscape($payload_hash);
        $metadata = is_array($_POST['metadata'] ?? null) ? $_POST['metadata'] : [];
        $metadata_json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $metadata_sql = automationDbEscape($metadata_json === false ? '{}' : $metadata_json);
        $auto_resolve = automationBool($_POST['auto_resolve'] ?? null, true);

        if ($state === 'resolved') {
            if ($ticket_id > 0 && ($incident['automation_incident_status'] ?? '') === 'Open') {
                $reply = automationLimitText(($_POST['description'] ?? '') ?: "$title recovered.", 8000);
                automationAddIncidentReply($ticket_id, intval($incident['automation_incident_client_id']), $reply, $auto_resolve);
                $action = $auto_resolve ? 'resolved' : 'recovery_recorded';
            } else {
                $action = 'recovery_without_open_incident';
            }
            automationDbQuery("INSERT INTO automation_incidents SET
                automation_incident_source = '$source_sql', automation_incident_key = '$incident_key_sql',
                automation_incident_title = '$title_sql', automation_incident_status = 'Resolved',
                automation_incident_severity = '$severity_sql', automation_incident_ticket_id = $ticket_id,
                automation_incident_client_id = $client_id, automation_incident_location_id = $location_id,
                automation_incident_asset_id = $asset_id, automation_incident_event_count = 1,
                automation_incident_last_event_hash = '$payload_hash_sql',
                automation_incident_metadata = '$metadata_sql', automation_incident_last_event_at = NOW(),
                automation_incident_resolved_at = NOW()
                ON DUPLICATE KEY UPDATE automation_incident_title = VALUES(automation_incident_title),
                automation_incident_status = 'Resolved', automation_incident_severity = VALUES(automation_incident_severity),
                automation_incident_event_count = automation_incident_event_count + 1,
                automation_incident_last_event_hash = VALUES(automation_incident_last_event_hash),
                automation_incident_metadata = VALUES(automation_incident_metadata),
                automation_incident_last_event_at = NOW(), automation_incident_resolved_at = NOW()",
                'Could not save the resolved automation incident');
        } else {
            if ($ticket_id < 1 || ($incident['automation_incident_status'] ?? '') === 'Resolved') {
                $ticket = automationCreateIncidentTicket($_POST, $resolved);
                $ticket_id = intval($ticket['ticket_id']);
                $action = 'created';
            } elseif (($incident['automation_incident_last_event_hash'] ?? '') !== $payload_hash) {
                $reply = automationLimitText(($_POST['description'] ?? '') ?: "$title remains active.", 8000);
                automationAddIncidentReply($ticket_id, intval($incident['automation_incident_client_id']), $reply, false);
                $action = 'updated';
            } else {
                $action = 'unchanged';
            }
            automationDbQuery("INSERT INTO automation_incidents SET
                automation_incident_source = '$source_sql', automation_incident_key = '$incident_key_sql',
                automation_incident_title = '$title_sql', automation_incident_status = 'Open',
                automation_incident_severity = '$severity_sql', automation_incident_ticket_id = $ticket_id,
                automation_incident_client_id = $client_id, automation_incident_location_id = $location_id,
                automation_incident_asset_id = $asset_id, automation_incident_event_count = 1,
                automation_incident_last_event_hash = '$payload_hash_sql',
                automation_incident_metadata = '$metadata_sql', automation_incident_opened_at = NOW(),
                automation_incident_last_event_at = NOW(), automation_incident_resolved_at = NULL
                ON DUPLICATE KEY UPDATE automation_incident_title = VALUES(automation_incident_title),
                automation_incident_status = 'Open', automation_incident_severity = VALUES(automation_incident_severity),
                automation_incident_ticket_id = VALUES(automation_incident_ticket_id),
                automation_incident_client_id = VALUES(automation_incident_client_id),
                automation_incident_location_id = VALUES(automation_incident_location_id),
                automation_incident_asset_id = VALUES(automation_incident_asset_id),
                automation_incident_event_count = automation_incident_event_count + 1,
                automation_incident_last_event_hash = VALUES(automation_incident_last_event_hash),
                automation_incident_metadata = VALUES(automation_incident_metadata),
                automation_incident_last_event_at = NOW(), automation_incident_resolved_at = NULL",
                'Could not save the open automation incident');
        }

        $action_sql = automationDbEscape($action);
        $payload_sql = automationDbEscape($payload_json === false ? '{}' : $payload_json);
        automationDbQuery("INSERT INTO automation_events SET automation_event_source = '$source_sql',
            automation_event_external_id = '$event_id_sql', automation_event_incident_key = '$incident_key_sql',
            automation_event_state = '" . automationDbEscape($state) . "', automation_event_action = '$action_sql',
            automation_event_ticket_id = $ticket_id, automation_event_payload_hash = '$payload_hash_sql',
            automation_event_payload = '$payload_sql', automation_event_processed_at = NOW()",
            'Could not save automation event idempotency');
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name')");
    }

    automationEventResponse(200, [
        'success' => 'True',
        'message' => 'Automation event processed.',
        'data' => [[
            'action' => $action,
            'ticket_id' => $ticket_id,
            'mapping' => $resolved,
        ]],
    ]);
} catch (AutomationDuplicateEventException $e) {
    automationEventResponse(200, [
        'success' => 'True',
        'message' => $e->getMessage(),
        'data' => [[
            'action' => 'duplicate',
            'previous_action' => $e->previous_action,
            'ticket_id' => $e->ticket_id,
        ]],
    ]);
} catch (AutomationConflictException $e) {
    automationEventResponse(409, ['success' => 'False', 'message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    automationEventResponse(422, ['success' => 'False', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Automation event API failed: ' . $e->getMessage());
    automationEventResponse(500, ['success' => 'False', 'message' => 'The automation event could not be processed.']);
}
