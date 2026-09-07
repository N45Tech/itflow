<?php

// Shared authorization and audit boundary for follow-ups and reviewed knowledge.
// Mutation helpers run inside fieldRequest's transaction and durable receipt.
function assistanceRequire(int $client_id, int $support = 1, int $client = 0): void
{
    if ($client_id < 1 || !hasClientAccess($client_id)
        || lookupUserPermission('module_support') < $support
        || ($client && lookupUserPermission('module_client') < $client)) {
        throw new DomainException('You do not have access to this service record.');
    }
}

function assistanceTicket(int $ticket_id, bool $lock = false): array
{
    $ticket = mysqli_fetch_assoc(fieldDb("SELECT t.*, c.client_name FROM tickets t
        JOIN clients c ON c.client_id = t.ticket_client_id AND c.client_archived_at IS NULL
        WHERE t.ticket_id = $ticket_id AND t.ticket_archived_at IS NULL"));
    if (!$ticket) { throw new DomainException('This ticket is unavailable.'); }
    assistanceRequire((int) $ticket['ticket_client_id']);
    if ($lock) {
        documentationLockClientTicket($ticket_id, (int) $ticket['ticket_client_id']);
        $locked = mysqli_fetch_assoc(fieldDb("SELECT * FROM tickets WHERE ticket_id = $ticket_id FOR UPDATE"));
        if (!$locked || $locked['ticket_archived_at']
            || (int) $locked['ticket_client_id'] !== (int) $ticket['ticket_client_id']) {
            throw new DomainException('The ticket changed. Reload before continuing.');
        }
        $ticket = array_merge($ticket, $locked);
    }
    return $ticket;
}

function assistanceEvent(string $kind, string $key, int $ticket_id, int $client_id,
    int $actor, string $action, string $note, array $payload = []): void
{
    fieldDb('INSERT INTO service_assistance_events SET event_kind = ' . fieldSql($kind)
        . ', event_entity_key = ' . fieldSql($key) . ", event_ticket_id = $ticket_id,
        event_client_id = $client_id, event_actor_id = $actor, event_action = " . fieldSql($action)
        . ', event_note = ' . fieldSql($note) . ', event_payload = '
        . fieldSql(json_encode($payload, JSON_THROW_ON_ERROR)) . ', event_created_at = UTC_TIMESTAMP()');
}

function assistanceHistory(int $ticket_id, string $kind, string $key): array
{
    assistanceTicket($ticket_id);
    return fieldRows("SELECT e.event_action, e.event_note, e.event_created_at, u.user_name
        FROM service_assistance_events e LEFT JOIN users u ON u.user_id = e.event_actor_id
        WHERE e.event_ticket_id = $ticket_id AND e.event_kind = " . fieldSql($kind)
        . ' AND e.event_entity_key = ' . fieldSql($key) . ' ORDER BY e.event_id DESC LIMIT 20');
}

function assistanceNotify(int $user_id, int $client_id, int $ticket_id, string $text, string $url): void
{
    // Re-evaluate current recipient permissions, including role and explicit denies.
    $owners = fieldOwners($client_id);
    if (!in_array($user_id, array_map('intval', array_column($owners, 'user_id')), true)) {
        throw new DomainException('The selected technician no longer has access to this client.');
    }
    fieldDb('INSERT INTO notifications SET notification_type = \'Ticket\', notification = '
        . fieldSql($text) . ', notification_action = ' . fieldSql($url)
        . ", notification_client_id = $client_id, notification_entity_id = $ticket_id,
        notification_user_id = $user_id");
}

function assistanceUuid(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function assistanceHasHistory(int $ticket_id): bool
{
    return (bool) mysqli_fetch_row(fieldDb("SELECT EXISTS(SELECT 1 FROM service_assistance_events
        WHERE event_ticket_id = $ticket_id) OR EXISTS(SELECT 1 FROM service_knowledge
        WHERE knowledge_ticket_id = $ticket_id)"))[0];
}

function assistanceWrite(string $action, array $input, int $actor): array
{
    $ticket = assistanceTicket((int) ($input['ticket_id'] ?? 0));
    $knowledge = str_starts_with($action, 'knowledge_');
    $level = $knowledge && in_array($input['operation'] ?? '', ['publish','return'], true) ? 3 : 2;
    assistanceRequire((int) $ticket['ticket_client_id'], $level, $knowledge ? 2 : 0);
    $handlers = ['followup_plan' => 'followupSave', 'knowledge_capture' => 'knowledgeCapture', 'knowledge_save' => 'knowledgeSave'];
    if (!isset($handlers[$action])) { throw new DomainException('Choose a valid service action.'); }
    return fieldRequest($action, $input, $actor, static fn (&$batch) => $handlers[$action]($input, $actor), true);
}

function assistancePurgeTicket(int $ticket_id): void
{
    // Published client documents remain independently owned by the client.
    foreach (['service_followup_plans' => 'plan_ticket_id', 'service_followup_notices' => 'notice_ticket_id',
        'service_assistance_events' => 'event_ticket_id', 'service_knowledge' => 'knowledge_ticket_id'] as $table => $column) {
        fieldDb("DELETE FROM $table WHERE $column = $ticket_id");
    }
}

function assistanceClientHasHistory(int $client_id): bool
{
    return (bool) mysqli_fetch_row(fieldDb("SELECT EXISTS(SELECT 1 FROM service_assistance_events
        WHERE event_client_id = $client_id) OR EXISTS(SELECT 1 FROM service_knowledge WHERE knowledge_client_id = $client_id)"))[0];
}
