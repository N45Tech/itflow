<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$failures = [];
$assert = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};
$scalar = static function (mysqli $mysqli, string $sql): string {
    $result = mysqli_query($mysqli, $sql);
    if (!$result) {
        throw new RuntimeException(mysqli_error($mysqli));
    }
    return (string) (mysqli_fetch_row($result)[0] ?? '');
};

foreach (['N45_CI_DB_HOST', 'N45_CI_DB_USER', 'N45_CI_DB_PASSWORD', 'N45_CI_DB_NAME'] as $name) {
    $assert((string) getenv($name) !== '', "Missing $name");
}
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

$mysqli = mysqli_connect(
    (string) getenv('N45_CI_DB_HOST'),
    (string) getenv('N45_CI_DB_USER'),
    (string) getenv('N45_CI_DB_PASSWORD'),
    (string) getenv('N45_CI_DB_NAME'),
    intval(getenv('N45_CI_DB_PORT') ?: 3306)
);
if (!$mysqli) {
    fwrite(STDERR, mysqli_connect_error() . "\n");
    exit(1);
}

require_once dirname(__DIR__) . '/functions/ticket_operations.php';

try {
    mysqli_query($mysqli, "SET SESSION sql_mode = CONCAT_WS(',', @@sql_mode, 'STRICT_TRANS_TABLES')");
    $columns = [
        'tickets' => [
            'ticket_work_type', 'ticket_impact', 'ticket_urgency', 'ticket_next_action',
            'ticket_next_action_due_at', 'ticket_waiting_on', 'ticket_waiting_on_detail',
            'ticket_resolution_code', 'ticket_resolution_summary', 'ticket_root_cause',
            'ticket_operational_updated_by', 'ticket_operational_updated_at',
        ],
        'ticket_operational_events' => [
            'ticket_operational_event_id', 'ticket_operational_event_ticket_id',
            'ticket_operational_event_client_id', 'ticket_operational_event_action',
            'ticket_operational_event_actor_type', 'ticket_operational_event_actor_id',
            'ticket_operational_event_payload', 'ticket_operational_event_payload_hash',
            'ticket_operational_event_created_at',
        ],
        'ticket_relationships' => [
            'ticket_relationship_id', 'ticket_relationship_client_id', 'ticket_relationship_type',
            'ticket_relationship_source_ticket_id', 'ticket_relationship_target_ticket_id',
            'ticket_relationship_created_by', 'ticket_relationship_created_at',
        ],
        'ticket_customer_promises' => [
            'ticket_customer_promise_id', 'ticket_customer_promise_ticket_id',
            'ticket_customer_promise_client_id', 'ticket_customer_promise_type',
            'ticket_customer_promise_summary', 'ticket_customer_promise_due_at',
            'ticket_customer_promise_status', 'ticket_customer_promise_promised_by',
            'ticket_customer_promise_promised_at', 'ticket_customer_promise_source_type',
            'ticket_customer_promise_source_id', 'ticket_customer_promise_fulfilled_by',
            'ticket_customer_promise_fulfilled_at', 'ticket_customer_promise_breached_at',
            'ticket_customer_promise_cancelled_by', 'ticket_customer_promise_cancelled_at',
        ],
        'ticket_customer_promise_events' => [
            'ticket_customer_promise_event_id', 'ticket_customer_promise_event_promise_id',
            'ticket_customer_promise_event_ticket_id', 'ticket_customer_promise_event_client_id',
            'ticket_customer_promise_event_action', 'ticket_customer_promise_event_from_status',
            'ticket_customer_promise_event_to_status', 'ticket_customer_promise_event_actor_type',
            'ticket_customer_promise_event_actor_id', 'ticket_customer_promise_event_source_type',
            'ticket_customer_promise_event_source_id', 'ticket_customer_promise_event_context_hash',
            'ticket_customer_promise_event_created_at',
        ],
        'ticket_email_ingress' => [
            'ticket_email_ingress_id', 'ticket_email_ingress_message_hash',
            'ticket_email_ingress_claim_token', 'ticket_email_ingress_sender_hash',
            'ticket_email_ingress_domain_hash', 'ticket_email_ingress_subject_hash',
            'ticket_email_ingress_status', 'ticket_email_ingress_attempts',
            'ticket_email_ingress_ticket_id', 'ticket_email_ingress_reply_id',
            'ticket_email_ingress_client_id', 'ticket_email_ingress_reason_code',
            'ticket_email_ingress_received_at', 'ticket_email_ingress_processing_at',
            'ticket_email_ingress_completed_at',
        ],
    ];
    foreach ($columns as $table => $expected) {
        $result = mysqli_query($mysqli, "SELECT column_name FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = '$table' ORDER BY ordinal_position");
        if (!$result) {
            throw new RuntimeException(mysqli_error($mysqli));
        }
        $actual = [];
        while ($row = mysqli_fetch_row($result)) {
            $actual[] = (string) $row[0];
        }
        if ($table === 'tickets') {
            foreach ($expected as $column) {
                $assert(in_array($column, $actual, true), "0016 tickets.$column is missing");
            }
        } else {
            $assert($actual === $expected, "0016 $table column contract is not exhaustive/exact");
        }
    }

    $indexes = [
        'tickets' => ['ticket_work_type_status', 'ticket_waiting_queue', 'ticket_operational_priority'],
        'ticket_operational_events' => ['PRIMARY', 'ticket_operational_event_ticket', 'ticket_operational_event_client'],
        'ticket_relationships' => ['PRIMARY', 'ticket_relationship_pair', 'ticket_relationship_source', 'ticket_relationship_target', 'ticket_relationship_client'],
        'ticket_customer_promises' => ['PRIMARY', 'ticket_customer_promise_queue', 'ticket_customer_promise_ticket', 'ticket_customer_promise_client'],
        'ticket_customer_promise_events' => ['PRIMARY', 'ticket_customer_promise_event_promise', 'ticket_customer_promise_event_ticket'],
        'ticket_email_ingress' => ['PRIMARY', 'ticket_email_ingress_message', 'ticket_email_ingress_status',
            'ticket_email_ingress_sender_window', 'ticket_email_ingress_domain_window',
            'ticket_email_ingress_client_window', 'ticket_email_ingress_ticket'],
    ];
    foreach ($indexes as $table => $expected) {
        $result = mysqli_query($mysqli, "SELECT DISTINCT index_name FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = '$table'");
        if (!$result) {
            throw new RuntimeException(mysqli_error($mysqli));
        }
        $actual = [];
        while ($row = mysqli_fetch_row($result)) {
            $actual[] = (string) $row[0];
        }
        foreach ($expected as $index) {
            $assert(in_array($index, $actual, true), "0016 $table.$index is missing");
        }
        if ($table !== 'tickets') {
            sort($actual);
            sort($expected);
            $assert($actual === $expected, "0016 $table index contract is not exhaustive/exact");
        }
    }

    $expected_triggers = [
        'ticket_operational_events_bu_immutable' => [
            'table' => 'ticket_operational_events',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only'",
        ],
        'ticket_operational_events_bd_immutable' => [
            'table' => 'ticket_operational_events',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket operational events are append-only'",
        ],
        'ticket_customer_promise_events_bu_immutable' => [
            'table' => 'ticket_customer_promise_events',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only'",
        ],
        'ticket_customer_promise_events_bd_immutable' => [
            'table' => 'ticket_customer_promise_events',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket customer promise events are append-only'",
        ],
    ];
    $trigger_result = mysqli_query($mysqli, "SELECT trigger_name, event_object_table, action_timing,
        event_manipulation, action_statement FROM information_schema.triggers
        WHERE trigger_schema = DATABASE() AND trigger_name IN (
            'ticket_operational_events_bu_immutable',
            'ticket_operational_events_bd_immutable',
            'ticket_customer_promise_events_bu_immutable',
            'ticket_customer_promise_events_bd_immutable'
        )");
    if (!$trigger_result) {
        throw new RuntimeException(mysqli_error($mysqli));
    }
    $actual_triggers = [];
    while ($trigger = mysqli_fetch_assoc($trigger_result)) {
        $actual_triggers[(string) $trigger['trigger_name']] = $trigger;
    }
    $normalize_trigger_statement = static fn (string $statement): string =>
        strtolower((string) preg_replace('/\s+/', '', $statement));
    foreach ($expected_triggers as $trigger_name => $expected) {
        $actual = $actual_triggers[$trigger_name] ?? null;
        $assert($actual !== null, "0016 immutable trigger $trigger_name is missing");
        if ($actual === null) {
            continue;
        }
        $assert((string) $actual['event_object_table'] === $expected['table']
            && (string) $actual['action_timing'] === 'BEFORE'
            && (string) $actual['event_manipulation'] === $expected['event']
            && $normalize_trigger_statement((string) $actual['action_statement'])
                === $normalize_trigger_statement($expected['statement']),
            "0016 immutable trigger $trigger_name drifted");
    }
    $assert(count($actual_triggers) === count($expected_triggers),
        '0016 immutable trigger contract is not exhaustive/exact');

    $priority_case = "CASE ticket_impact
        WHEN 'low' THEN CASE ticket_urgency WHEN 'low' THEN 'Low' WHEN 'medium' THEN 'Low' WHEN 'high' THEN 'Medium' ELSE 'High' END
        WHEN 'medium' THEN CASE ticket_urgency WHEN 'low' THEN 'Low' WHEN 'medium' THEN 'Medium' WHEN 'high' THEN 'High' ELSE 'High' END
        WHEN 'high' THEN CASE ticket_urgency WHEN 'low' THEN 'Medium' WHEN 'medium' THEN 'High' WHEN 'high' THEN 'High' ELSE 'Urgent' END
        ELSE CASE ticket_urgency WHEN 'low' THEN 'High' WHEN 'medium' THEN 'High' ELSE 'Urgent' END END";
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM tickets WHERE NOT (ticket_priority <=> $priority_case)") === '0',
        'Ticket priority is not derived from impact and urgency');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_statuses WHERE ticket_status_id IN (1,2,3,4,5)
        AND ticket_status_pauses_sla <> CASE WHEN ticket_status_id IN (3,4,5) THEN 1 ELSE 0 END") === '0',
        'Built-in SLA pause seeds are incorrect');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_operational_events e
        LEFT JOIN tickets t ON t.ticket_id = e.ticket_operational_event_ticket_id
        WHERE t.ticket_id IS NULL OR t.ticket_client_id <> e.ticket_operational_event_client_id
        OR e.ticket_operational_event_payload_hash <> SHA2(e.ticket_operational_event_payload, 256)") === '0',
        'Operational event ownership or payload hash is invalid');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_relationships r
        LEFT JOIN tickets s ON s.ticket_id = r.ticket_relationship_source_ticket_id
        LEFT JOIN tickets t ON t.ticket_id = r.ticket_relationship_target_ticket_id
        WHERE r.ticket_relationship_type NOT IN ('parent_child','duplicate','related')
        OR s.ticket_id IS NULL OR t.ticket_id IS NULL OR s.ticket_client_id <> t.ticket_client_id
        OR r.ticket_relationship_client_id <> s.ticket_client_id
        OR r.ticket_relationship_source_ticket_id = r.ticket_relationship_target_ticket_id") === '0',
        'Relationship domain or ownership is invalid');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_customer_promises p
        LEFT JOIN tickets t ON t.ticket_id = p.ticket_customer_promise_ticket_id
        WHERE t.ticket_id IS NULL OR t.ticket_client_id <> p.ticket_customer_promise_client_id
        OR p.ticket_customer_promise_type NOT IN ('customer_update','target_completion')
        OR p.ticket_customer_promise_status NOT IN ('Open','Breached','Fulfilled','Cancelled')
        OR p.ticket_customer_promise_source_type NOT IN ('agent','api','automation','email','runbook','system')") === '0',
        'Promise domain or ownership is invalid');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_customer_promise_events e
        LEFT JOIN ticket_customer_promises p ON p.ticket_customer_promise_id = e.ticket_customer_promise_event_promise_id
        WHERE p.ticket_customer_promise_id IS NULL
        OR p.ticket_customer_promise_ticket_id <> e.ticket_customer_promise_event_ticket_id
        OR p.ticket_customer_promise_client_id <> e.ticket_customer_promise_event_client_id") === '0',
        'Promise-event ownership is invalid');
    $assert($scalar($mysqli, "SELECT COUNT(*) FROM ticket_email_ingress i
        LEFT JOIN tickets t ON t.ticket_id = i.ticket_email_ingress_ticket_id
        WHERE BINARY i.ticket_email_ingress_message_hash NOT REGEXP '^[0-9a-f]{64}$'
        OR BINARY i.ticket_email_ingress_claim_token NOT REGEXP '^[0-9a-f]{64}$'
        OR BINARY i.ticket_email_ingress_sender_hash NOT REGEXP '^[0-9a-f]{64}$'
        OR BINARY i.ticket_email_ingress_domain_hash NOT REGEXP '^[0-9a-f]{64}$'
        OR BINARY i.ticket_email_ingress_subject_hash NOT REGEXP '^[0-9a-f]{64}$'
        OR (i.ticket_email_ingress_ticket_id > 0 AND t.ticket_id IS NULL)") === '0',
        'Email ingress hash or ticket reference is invalid');

    $events = mysqli_query($mysqli, 'SELECT * FROM ticket_customer_promise_events');
    if (!$events) {
        throw new RuntimeException(mysqli_error($mysqli));
    }
    while ($event = mysqli_fetch_assoc($events)) {
        $context = ticketOperationalCanonicalJson([
            'promise_id' => intval($event['ticket_customer_promise_event_promise_id']),
            'ticket_id' => intval($event['ticket_customer_promise_event_ticket_id']),
            'action' => (string) $event['ticket_customer_promise_event_action'],
            'from_status' => $event['ticket_customer_promise_event_from_status'],
            'to_status' => (string) $event['ticket_customer_promise_event_to_status'],
            'source_type' => $event['ticket_customer_promise_event_source_type'],
            'source_id' => intval($event['ticket_customer_promise_event_source_id']),
        ]);
        $assert(hash_equals(hash('sha256', $context), (string) $event['ticket_customer_promise_event_context_hash']),
            'Promise-event canonical context hash is invalid');
    }

    mysqli_begin_transaction($mysqli);
    $message_hash = hash('sha256', random_bytes(32));
    $first = ticketEmailIngressClaim($message_hash, 'strict@example.test', 'Strict claim test');
    $assert($first['claimed'] === true && preg_match('/^[0-9a-f]{64}$/', $first['token']) === 1,
        'Strict-schema ingress claim did not issue an ownership token');
    $id = intval($first['id']);
    mysqli_query($mysqli, "UPDATE ticket_email_ingress SET ticket_email_ingress_status = 'Failed'
        WHERE ticket_email_ingress_id = $id");
    $second = ticketEmailIngressClaim($message_hash, 'strict@example.test', 'Strict claim test');
    $assert($second['claimed'] === true && $second['token'] !== $first['token'],
        'Stale/failed ingress reclaim did not rotate ownership');
    try {
        ticketEmailIngressComplete($id, (string) $first['token'], 'Processed');
        $assert(false, 'A stale ingress worker completed after ownership rotated');
    } catch (RuntimeException $expected) {
    }
    ticketEmailIngressComplete($id, (string) $second['token'], 'Rejected', 0, 0, 'contract_test');
    $assert($scalar($mysqli, "SELECT ticket_email_ingress_status FROM ticket_email_ingress
        WHERE ticket_email_ingress_id = $id") === 'Rejected', 'Current ingress owner could not finalize');
    mysqli_rollback($mysqli);
} catch (Throwable $e) {
    mysqli_rollback($mysqli);
    $failures[] = $e->getMessage();
}

mysqli_close($mysqli);
if ($failures) {
    fwrite(STDERR, "Ticket operational database assertions failed:\n- "
        . implode("\n- ", array_unique($failures)) . "\n");
    exit(1);
}

echo "Ticket operational database assertions passed.\n";
