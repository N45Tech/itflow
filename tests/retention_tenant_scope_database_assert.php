<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "Retention tenant/lifecycle database test failed: $message\n");
    exit(1);
};
$query = static function (mysqli $connection, string $sql, string $message) use ($fail) {
    $result = mysqli_query($connection, $sql);
    if ($result === false) {
        $fail($message . ': ' . mysqli_error($connection));
    }
    return $result;
};
$connect = static function () use ($fail): mysqli {
    $connection = mysqli_connect(
        (string) getenv('N45_CI_DB_HOST'),
        (string) getenv('N45_CI_DB_USER'),
        (string) getenv('N45_CI_DB_PASSWORD'),
        (string) getenv('N45_CI_DB_NAME'),
        intval(getenv('N45_CI_DB_PORT') ?: 3306)
    );
    if (!$connection) {
        $fail('could not open the competing database connection');
    }
    return $connection;
};
$expectLockTimeout = static function (mysqli $connection, string $sql, string $label) use ($fail): void {
    try {
        $result = mysqli_query($connection, $sql);
        if ($result !== false) {
            $fail("$label was not blocked by the retained policy lock");
        }
        $errno = mysqli_errno($connection);
    } catch (mysqli_sql_exception $e) {
        $errno = $e->getCode();
    }
    if (!in_array(intval($errno ?? 0), [1205, 1213], true)) {
        $fail("$label failed for an unexpected reason (error " . intval($errno ?? 0) . ')');
    }
};
$expectScopeFailure = static function (int $event_id, string $label) use ($fail): void {
    try {
        retentionResolveRecordClient('automation-event', $event_id, false);
        $fail("$label resolved a client");
    } catch (DomainException $expected) {
    }
};

$other = $connect();
$suffix = bin2hex(random_bytes(8));
$source = mysqli_real_escape_string($mysqli, "retention-test-$suffix");
$incident_key = mysqli_real_escape_string($mysqli, "same-key-$suffix");
$client_ids = [];
$ticket_ids = [];
$incident_ids = [];
$event_ids = [];
$hold_id = 0;
$file_id = 0;

try {
    $column = mysqli_fetch_row($query($mysqli, "SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'automation_events'
        AND column_name = 'automation_event_client_id'", 'could not inspect automation tenant schema'));
    if (intval($column[0] ?? 0) !== 1) {
        $fail('automation_event_client_id is missing');
    }

    foreach (['A', 'B'] as $label) {
        $name = mysqli_real_escape_string($mysqli, "Retention tenant $label $suffix");
        $query($mysqli, "INSERT INTO clients SET client_name = '$name',
            client_currency_code = 'USD', client_net_terms = 30", 'could not create a tenant client');
        $client_id = intval(mysqli_insert_id($mysqli));
        $client_ids[] = $client_id;
        $number = random_int(800000000, 899999999);
        $query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'RT', ticket_number = $number,
            ticket_source = 'Contract test', ticket_subject = 'Retention tenant $label',
            ticket_details = 'Tenant-scoped event fixture', ticket_priority = 'Low',
            ticket_status = 1, ticket_created_by = 0, ticket_client_id = $client_id",
            'could not create a tenant ticket');
        $ticket_id = intval(mysqli_insert_id($mysqli));
        $ticket_ids[] = $ticket_id;
        $query($mysqli, "INSERT INTO automation_incidents SET
            automation_incident_source = '$source', automation_incident_key = '$incident_key',
            automation_incident_title = 'Tenant $label incident',
            automation_incident_ticket_id = $ticket_id,
            automation_incident_client_id = $client_id",
            'could not create a tenant incident');
        $incident_ids[] = intval(mysqli_insert_id($mysqli));
        $external_id = mysqli_real_escape_string($mysqli, "event-$label-$suffix");
        $payload_hash = hash('sha256', "event-$label-$suffix");
        $query($mysqli, "INSERT INTO automation_events SET
            automation_event_source = '$source', automation_event_client_id = $client_id,
            automation_event_external_id = '$external_id',
            automation_event_incident_key = '$incident_key', automation_event_state = 'open',
            automation_event_action = 'created', automation_event_status = 'Processed',
            automation_event_ticket_id = $ticket_id,
            automation_event_payload_hash = '$payload_hash', automation_event_payload = '{}'",
            'could not create a tenant event');
        $event_ids[] = intval(mysqli_insert_id($mysqli));
    }

    $query($mysqli, "INSERT INTO retention_holds SET retention_hold_client_id = {$client_ids[0]},
        retention_hold_record_type = 'ticket', retention_hold_record_id = {$ticket_ids[0]},
        retention_hold_reason = 'Cross-tenant inheritance contract test'",
        'could not create the ticket hold');
    $hold_id = intval(mysqli_insert_id($mysqli));

    foreach ([0, 1] as $index) {
        $resolved = retentionResolveRecordClient('automation-event', $event_ids[$index], false);
        if ($resolved !== $client_ids[$index]) {
            $fail("event $index resolved the wrong tenant");
        }
    }
    $held_a = retentionActiveHolds('automation-event', $event_ids[0], $client_ids[0], false);
    $held_b = retentionActiveHolds('automation-event', $event_ids[1], $client_ids[1], false);
    if (count($held_a) !== 1 || intval($held_a[0]['id']) !== $hold_id || $held_b !== []) {
        $fail('same-key events inherited a hold across tenant boundaries');
    }

    $query($mysqli, "UPDATE automation_events SET automation_event_ticket_id = {$ticket_ids[0]}
        WHERE automation_event_id = {$event_ids[1]}", 'could not stage cross-tenant ticket corruption');
    $expectScopeFailure($event_ids[1], 'cross-tenant event ticket');
    $query($mysqli, "UPDATE automation_events SET automation_event_ticket_id = {$ticket_ids[1]}
        WHERE automation_event_id = {$event_ids[1]}", 'could not restore the event ticket');
    $query($mysqli, "UPDATE automation_events SET automation_event_client_id = 0
        WHERE automation_event_id = {$event_ids[1]}", 'could not stage a legacy tenant-zero event');
    $expectScopeFailure($event_ids[1], 'legacy tenant-zero event');
    $query($mysqli, "UPDATE automation_events SET automation_event_client_id = {$client_ids[1]}
        WHERE automation_event_id = {$event_ids[1]}", 'could not restore the event tenant');

    $query($mysqli, "UPDATE clients SET client_archived_at = NOW()
        WHERE client_id = {$client_ids[0]}", 'could not archive the lifecycle client');
    $reference = mysqli_real_escape_string($mysqli, "archived-$suffix.txt");
    $query($mysqli, "INSERT INTO files SET file_reference_name = '$reference',
        file_name = 'Archived lifecycle fixture', file_deleted_at = NOW(),
        file_client_id = {$client_ids[0]}", 'could not create the archived-client file');
    $file_id = intval(mysqli_insert_id($mysqli));
    mysqli_begin_transaction($mysqli);
    $locked_file = retentionLockQuarantineLifecycleTarget('file', $file_id, $client_ids[0]);
    if (intval($locked_file['file_id'] ?? 0) !== $file_id) {
        $fail('archived-client quarantine target did not lock');
    }
    mysqli_rollback($mysqli);

    $query($other, 'SET SESSION innodb_lock_wait_timeout = 1',
        'could not set the policy contender timeout');
    mysqli_begin_transaction($mysqli);
    retentionPolicy('tickets', true);
    $expectLockTimeout($other, "UPDATE retention_policies
        SET retention_policy_restore_window_days = retention_policy_restore_window_days + 1
        WHERE retention_policy_key = 'tickets' LIMIT 1", 'concurrent policy edit');
    mysqli_rollback($mysqli);

    echo "Retention tenant/lifecycle database test passed\n";
} finally {
    @mysqli_rollback($mysqli);
    @mysqli_rollback($other);
    if ($hold_id > 0) {
        @mysqli_query($other, "DELETE FROM retention_holds WHERE retention_hold_id = $hold_id");
    }
    if ($file_id > 0) {
        @mysqli_query($other, "DELETE FROM files WHERE file_id = $file_id");
    }
    foreach ($event_ids as $event_id) {
        @mysqli_query($other, "DELETE FROM automation_events WHERE automation_event_id = " . intval($event_id));
    }
    foreach ($incident_ids as $incident_id) {
        @mysqli_query($other, "DELETE FROM automation_incidents WHERE automation_incident_id = " . intval($incident_id));
    }
    foreach ($ticket_ids as $ticket_id) {
        @mysqli_query($other, "DELETE FROM tickets WHERE ticket_id = " . intval($ticket_id));
    }
    foreach ($client_ids as $client_id) {
        @mysqli_query($other, "DELETE FROM clients WHERE client_id = " . intval($client_id));
    }
    mysqli_close($other);
}
