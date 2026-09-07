<?php

function followupKinds(): array
{
    return ['next_action' => 'Next action', 'promise' => 'Customer commitment',
        'ticket_approval' => 'Ticket approval', 'task_approval' => 'Task approval', 'blocker' => 'Field issue'];
}

/** Live sources, not a second completion state. Local ticket dates and UTC field dates remain explicit. */
function followupSourceSql(): string
{
    return "SELECT CONCAT('next_action:', ticket_id) source_key, 'next_action' kind, ticket_id,
        ticket_next_action summary, ticket_next_action_due_at due_raw, 0 due_utc,
        ticket_assigned_to source_owner, CONCAT(ticket_waiting_on, ':', COALESCE(ticket_next_action,''), ':',
            COALESCE(ticket_next_action_due_at,''), ':', ticket_assigned_to) source_revision
        FROM tickets WHERE ticket_next_action IS NOT NULL AND ticket_next_action <> ''
            AND ticket_next_action_due_at IS NOT NULL AND ticket_status NOT IN (4,5)
        UNION ALL SELECT CONCAT('promise:', ticket_customer_promise_id), 'promise',
        ticket_customer_promise_ticket_id, ticket_customer_promise_summary, ticket_customer_promise_due_at, 0,
        ticket_customer_promise_created_by, CONCAT(ticket_customer_promise_summary, ':', ticket_customer_promise_due_at)
        FROM ticket_customer_promises WHERE ticket_customer_promise_status = 'open'
        UNION ALL SELECT CONCAT('ticket_approval:', ticket_approval_id), 'ticket_approval',
        ticket_approval_ticket_id, CONCAT('Follow up on ', ticket_approval_scope, ' ticket approval'),
        DATE_ADD(ticket_approval_created_at, INTERVAL 24 HOUR), 0, ticket_approval_created_by,
        CONCAT(ticket_approval_url_key, ':', ticket_approval_type, ':', COALESCE(ticket_approval_required_user_id,0),
            ':', COALESCE(ticket_approval_required_contact_id,0)) FROM ticket_approvals WHERE ticket_approval_status = 'pending'
        UNION ALL SELECT CONCAT('task_approval:', a.approval_id), 'task_approval', t.task_ticket_id,
        CONCAT('Follow up on approval: ', t.task_name), DATE_ADD(a.approval_created_at, INTERVAL 24 HOUR), 0,
        a.approval_created_by, CONCAT(a.approval_url_key, ':', a.approval_type, ':', COALESCE(a.approval_required_user_id,0),
            ':', COALESCE(a.approval_required_contact_id,0)) FROM task_approvals a JOIN tasks t ON t.task_id = a.approval_task_id
        WHERE a.approval_status = 'pending' AND t.task_completed_at IS NULL
        UNION ALL SELECT CONCAT('blocker:', blocker_id), 'blocker', blocker_ticket_id,
        blocker_title, blocker_due_at, 1, blocker_owner_id,
        CONCAT(blocker_status, ':', blocker_due_at, ':', blocker_owner_id, ':', blocker_title)
        FROM field_blockers WHERE blocker_status <> 'resolved'";
}

/** One ticket row per match, before pagination/counting in either ticket view. */
function followupTicketPredicate(string $alias = 'tickets'): string
{
    if (!in_array($alias, ['tickets', 't'], true)) { throw new InvalidArgumentException('Invalid ticket alias.'); }
    $local_now = fieldSql(date('Y-m-d H:i:s'));
    return "$alias.ticket_archived_at IS NULL AND $alias.ticket_status NOT IN (4,5)
        AND $alias.ticket_resolved_at IS NULL AND $alias.ticket_closed_at IS NULL
        AND EXISTS (SELECT 1 FROM clients fc WHERE fc.client_id = $alias.ticket_client_id AND fc.client_archived_at IS NULL)
        AND EXISTS (SELECT 1 FROM (" . followupSourceSql() . ") fs
            LEFT JOIN service_followup_plans fp ON fp.plan_key = fs.source_key AND fp.plan_client_id = $alias.ticket_client_id
            WHERE fs.ticket_id = $alias.ticket_id AND IF(
                fp.plan_source_hash = SHA2(CONCAT(fs.source_key, ':', $alias.ticket_client_id, ':', fs.source_revision), 256),
                fp.plan_due_at <= UTC_TIMESTAMP(),
                IF(fs.due_utc = 1, fs.due_raw <= UTC_TIMESTAMP(), fs.due_raw <= $local_now)))";
}

function followupProjection(array $row, array $owners, ?int $now = null): array
{
    $now ??= time();
    $utc = new DateTimeZone('UTC');
    $due = new DateTimeImmutable($row['due_raw'], (int) $row['due_utc'] ? $utc : new DateTimeZone(date_default_timezone_get()));
    $source_hash = hash('sha256', $row['source_key'] . ':' . $row['ticket_client_id'] . ':' . $row['source_revision']);
    $planned = !empty($row['plan_source_hash']) && hash_equals($source_hash, $row['plan_source_hash']);
    $owner_ids = array_map('intval', array_column($owners, 'user_id'));
    $owner = $planned ? (int) $row['plan_owner_id'] : (int) $row['source_owner'];
    if (!in_array($owner, $owner_ids, true)) { $owner = (int) $row['ticket_assigned_to']; }
    if (!in_array($owner, $owner_ids, true)) { $owner = (int) $row['ticket_created_by']; }
    if (!in_array($owner, $owner_ids, true)) { $owner = 0; }
    $escalate = $planned ? (int) $row['plan_escalate_to'] : 0;
    // Escalation is an explicit plan choice; never pick an unrelated technician.
    if ($escalate && (!in_array($escalate, $owner_ids, true) || $escalate === $owner)) { $escalate = 0; }
    $next = $planned ? new DateTimeImmutable($row['plan_due_at'], $utc) : $due;
    $escalation = $planned ? new DateTimeImmutable($row['plan_escalate_at'], $utc) : $next->modify('+24 hours');
    $names = array_column($owners, 'user_name', 'user_id');
    $version = hash('sha256', $source_hash . ':' . ($row['plan_version'] ?? 0));
    return ['key' => $row['source_key'], 'kind' => $row['kind'], 'kind_label' => followupKinds()[$row['kind']],
        'ticket_id' => (int) $row['ticket_id'], 'client_id' => (int) $row['ticket_client_id'],
        'client_name' => $row['client_name'], 'subject' => $row['ticket_subject'],
        'reference' => $row['ticket_prefix'] . $row['ticket_number'], 'summary' => $row['summary'],
        'source_due_at' => $due->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        'due_at' => $next->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        'owner_id' => $owner, 'owner_name' => $names[$owner] ?? 'Needs an owner',
        'escalate_to' => $escalate, 'escalate_name' => $names[$escalate]
            ?? ($planned && (int) $row['plan_escalate_to'] ? 'Choose a new escalation recipient' : 'Owner only'),
        'escalate_at' => $escalation->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        'overdue' => $next->getTimestamp() <= $now, 'source_overdue' => $due->getTimestamp() <= $now,
        'escalated' => $escalate > 0 && $escalation->getTimestamp() <= $now, 'planned' => $planned,
        'note' => $planned ? $row['plan_note'] : '', 'source_hash' => $source_hash, 'version' => $version,
        'plan_version' => (int) ($row['plan_version'] ?? 0)];
}

function followupOwners(int $client_id): array
{
    $owners = fieldOwners($client_id);
    foreach ($owners as &$owner) {
        $id = (int) $owner['user_id'];
        $owner['can_escalate'] = (bool) mysqli_fetch_row(fieldDb("SELECT r.role_is_admin = 1 OR EXISTS (
            SELECT 1 FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id
            WHERE p.user_role_id = u.user_role_id AND m.module_name = 'module_support'
            AND p.user_role_permission_level >= 3) FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE u.user_id = $id"))[0];
    }
    return $owners;
}

function followupQueue(array $filters, int $user_id, bool $system = false): array
{
    if (!$system && lookupUserPermission('module_support') < 1) { throw new DomainException('Support access is required.'); }
    $where = !$system ? clientScopeSql('t.ticket_client_id') : '';
    if (!empty($filters['client_id'])) { $where .= ' AND t.ticket_client_id = ' . (int) $filters['client_id']; }
    if (!empty($filters['client_query'])) {
        $where .= ' AND c.client_name LIKE ' . fieldSql('%' . addcslashes(fieldText($filters['client_query'], 'client name', 200), '%_\\') . '%');
    }
    if (!empty($filters['ticket_id'])) { $where .= ' AND t.ticket_id = ' . (int) $filters['ticket_id']; }
    if (!empty($filters['key'])) { $where .= ' AND s.source_key = ' . fieldSql((string) $filters['key']); }
    if (!empty($filters['kind']) && isset(followupKinds()[$filters['kind']])) { $where .= ' AND s.kind = ' . fieldSql($filters['kind']); }
    $rows = fieldRows('SELECT s.*, t.ticket_client_id, t.ticket_subject, t.ticket_prefix, t.ticket_number,
        t.ticket_assigned_to, t.ticket_created_by, c.client_name, p.* FROM (' . followupSourceSql() . ') s
        JOIN tickets t ON t.ticket_id = s.ticket_id AND t.ticket_archived_at IS NULL AND t.ticket_closed_at IS NULL
            AND t.ticket_status NOT IN (4,5) AND t.ticket_resolved_at IS NULL
        JOIN clients c ON c.client_id = t.ticket_client_id AND c.client_archived_at IS NULL
        LEFT JOIN service_followup_plans p ON p.plan_key = s.source_key AND p.plan_client_id = t.ticket_client_id
        WHERE 1 = 1' . $where . ' ORDER BY s.due_raw, s.source_key LIMIT 5001');
    $limited = count($rows) > 5000; $rows = array_slice($rows, 0, 5000);
    $owners = []; $items = [];
    foreach ($rows as $row) {
        $client = (int) $row['ticket_client_id'];
        $owners[$client] ??= followupOwners($client);
        $item = followupProjection($row, $owners[$client]);
        if (($filters['scope'] ?? 'mine') === 'mine' && $item['owner_id'] !== $user_id
            && !($item['escalated'] && $item['escalate_to'] === $user_id)) { continue; }
        if (($filters['due'] ?? 'due') === 'due' && !$item['overdue']) { continue; }
        $items[] = $item;
    }
    usort($items, static fn ($a, $b) => [$a['due_at'], $a['key']] <=> [$b['due_at'], $b['key']]);
    $offset = max(0, min(5000, (int) ($filters['offset'] ?? 0)));
    $page = array_slice($items, $offset, $system ? 5000 : 40);
    return ['items' => $page, 'total' => count($items), 'limited' => $limited,
        'next' => count($items) > $offset + count($page) ? $offset + count($page) : null];
}

function followupDetail(int $ticket_id, string $key): array
{
    $ticket = assistanceTicket($ticket_id);
    $queue = followupQueue(['key' => $key, 'ticket_id' => $ticket_id, 'scope' => 'all', 'due' => 'all'], 0);
    if (!$queue['items']) { throw new DomainException('This follow-up is complete or has changed. Refresh the queue.'); }
    return $queue['items'][0] + ['owners' => followupOwners((int) $ticket['ticket_client_id']),
        'history' => assistanceHistory($ticket_id, 'followup', $key)];
}

function followupSave(array $input, int $actor): array
{
    $ticket = assistanceTicket((int) ($input['ticket_id'] ?? 0), true);
    $ticket_id = (int) $ticket['ticket_id']; $client = (int) $ticket['ticket_client_id'];
    assistanceRequire($client, 2);
    $key = fieldText($input['key'] ?? '', 'a follow-up', 64);
    $item = followupDetail($ticket_id, $key);
    if (!hash_equals($item['version'], (string) ($input['expected_version'] ?? ''))) {
        throw new DomainException('The follow-up changed. Reload its current plan before saving.');
    }
    $owner = (int) ($input['owner_id'] ?? 0); $escalate = (int) ($input['escalate_to'] ?? 0);
    $eligible = array_map('intval', array_column($item['owners'], 'user_id'));
    if (!in_array($owner, $eligible, true) || ($escalate && (!in_array($escalate, $eligible, true) || $escalate === $owner))) {
        throw new DomainException('Choose an eligible owner and a different escalation recipient.');
    }
    $due = fieldFutureUtc($input['due_at'] ?? '', true);
    $escalate_at = $escalate ? fieldFutureUtc($input['escalate_at'] ?? '', true)
        : (new DateTimeImmutable($due, new DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s');
    if ($escalate_at <= $due) { throw new DomainException('Escalation must be after the next follow-up.'); }
    $note = ticketDisciplineText($input['note'] ?? '', 'Follow-up plan and reason', 5, 1000);
    $version = $item['plan_version'] + 1;
    fieldDb('INSERT INTO service_followup_plans SET plan_key = ' . fieldSql($key)
        . ", plan_ticket_id = $ticket_id, plan_client_id = $client, plan_source_hash = " . fieldSql($item['source_hash'])
        . ", plan_owner_id = $owner, plan_due_at = " . fieldSql($due) . ", plan_escalate_to = $escalate,
        plan_escalate_at = " . fieldSql($escalate_at) . ', plan_note = ' . fieldSql($note)
        . ", plan_version = $version, plan_updated_by = $actor, plan_updated_at = UTC_TIMESTAMP()
        ON DUPLICATE KEY UPDATE plan_source_hash = VALUES(plan_source_hash), plan_owner_id = VALUES(plan_owner_id),
        plan_due_at = VALUES(plan_due_at), plan_escalate_to = VALUES(plan_escalate_to),
        plan_escalate_at = VALUES(plan_escalate_at), plan_note = VALUES(plan_note),
        plan_version = VALUES(plan_version), plan_updated_by = VALUES(plan_updated_by), plan_updated_at = VALUES(plan_updated_at)");
    assistanceEvent('followup', $key, $ticket_id, $client, $actor, 'planned', $note,
        ['owner_id' => $owner, 'due_at' => $due, 'escalate_to' => $escalate, 'escalate_at' => $escalate_at,
            'source_due_at' => $item['source_due_at'], 'version' => $version]);
    if ($owner !== $actor) {
        assistanceNotify($owner, $client, $ticket_id, 'Follow-up assigned: ' . $item['reference'],
            '/agent/ticket.php?ticket_id=' . $ticket_id . '#followups');
    }
    return ['message' => 'Follow-up plan saved. The original commitment and approval deadlines are unchanged.'];
}

function followupSendDueNotices(): array
{
    global $mysqli;
    $queue = followupQueue(['scope' => 'all', 'due' => 'due'], 0, true);
    $sent = 0;
    foreach ($queue['items'] as $candidate) {
        try {
            fieldDb('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            fieldDb('START TRANSACTION');
            // Client then ticket lock shares the canonical completion lock order.
            documentationLockClientTicket($candidate['ticket_id'], $candidate['client_id']);
            fieldDb('SELECT ticket_id FROM tickets WHERE ticket_id = ' . $candidate['ticket_id'] . ' FOR UPDATE');
            $live = followupQueue(['scope' => 'all', 'due' => 'due', 'ticket_id' => $candidate['ticket_id'], 'key' => $candidate['key']], 0, true);
            if (!$live['items']) { mysqli_rollback($mysqli); continue; }
            $item = $live['items'][0];
            $recipients = ['reminder' => $item['owner_id']];
            if ($item['escalated'] && $item['escalate_to']) { $recipients['escalation'] = $item['escalate_to']; }
            foreach ($recipients as $stage => $recipient) {
                if (!$recipient) { continue; }
                $key = hash('sha256', implode(':', [$item['key'], $item['version'], $recipient, $stage, gmdate('Y-m-d')]));
                fieldDb('INSERT IGNORE INTO service_followup_notices SET notice_key = ' . fieldSql($key)
                    . ', notice_ticket_id = ' . $item['ticket_id'] . ', notice_client_id = ' . $item['client_id']
                    . ', notice_recipient_id = ' . $recipient . ', notice_stage = ' . fieldSql($stage) . ', notice_created_at = UTC_TIMESTAMP()');
                if (mysqli_affected_rows($mysqli) !== 1) { continue; }
                assistanceNotify($recipient, $item['client_id'], $item['ticket_id'],
                    ($stage === 'escalation' ? 'Escalated follow-up: ' : 'Follow-up due: ') . $item['reference'],
                    '/agent/ticket.php?ticket_id=' . $item['ticket_id'] . '#followups');
                assistanceEvent('followup', $item['key'], $item['ticket_id'], $item['client_id'], 0,
                    $stage, 'In-app notification queued.', ['recipient_id' => $recipient]);
                $sent++;
            }
            if (!mysqli_commit($mysqli)) { throw new RuntimeException('Could not commit the follow-up notification.'); }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    }
    return ['sent' => $sent, 'limited' => $queue['limited']];
}
