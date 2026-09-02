<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "Retention lock-order database test failed: $message\n");
    exit(1);
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
        $fail('could not open a competing database connection');
    }
    return $connection;
};
$query = static function (mysqli $connection, string $sql, string $message) use ($fail) {
    $result = mysqli_query($connection, $sql);
    if ($result === false) {
        $fail($message . ': ' . mysqli_error($connection));
    }
    return $result;
};

$hold = $connect();
$contender = $connect();
$suffix = bin2hex(random_bytes(8));
$client_id = 0;
$ticket_id = 0;
$attachment_id = 0;
$deletion_id = 0;
$batch_key = 'scheduled:retention-lock-' . $suffix;
$trigger_name = 'retention_preview_pause';
$original_policy_note = '';
$child = null;
$pipes = [];

try {
    $query($mysqli, "INSERT INTO clients SET client_name = 'Retention lock $suffix',
        client_currency_code = 'USD', client_net_terms = 30", 'could not create lock-order client');
    $client_id = intval(mysqli_insert_id($mysqli));
    $query($mysqli, "INSERT INTO tickets SET ticket_number = 990001,
        ticket_subject = 'Retention lock order', ticket_details = 'test', ticket_status = 1,
        ticket_created_by = 0, ticket_client_id = $client_id",
        'could not create lock-order ticket');
    $ticket_id = intval(mysqli_insert_id($mysqli));
    $query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = 'lock.txt',
        ticket_attachment_reference_name = 'lock.txt', ticket_attachment_ticket_id = $ticket_id",
        'could not create lock-order attachment');
    $attachment_id = intval(mysqli_insert_id($mysqli));

    // Delete owns the client serializer. A concurrent hold must stop there and
    // cannot acquire the attachment ahead of delete's parent -> child locks.
    mysqli_begin_transaction($mysqli);
    $query($mysqli, "SELECT client_id FROM clients WHERE client_id = $client_id FOR UPDATE",
        'could not lock the delete client serializer');
    mysqli_begin_transaction($hold);
    if (!mysqli_query($hold, "SELECT client_id FROM clients WHERE client_id = $client_id FOR UPDATE", MYSQLI_ASYNC)) {
        $fail('could not start the asynchronous hold client lock');
    }
    usleep(150000);
    $read = [$hold];
    $error = [$hold];
    $reject = [$hold];
    if (mysqli_poll($read, $error, $reject, 0, 0) !== 0) {
        $fail('the competing hold unexpectedly passed the locked client serializer');
    }
    $query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id FOR UPDATE",
        'delete could not lock the parent ticket while hold waited at the client');
    $query($mysqli, "SELECT ticket_attachment_id FROM ticket_attachments
        WHERE ticket_attachment_id = $attachment_id FOR UPDATE",
        'delete could not lock the attachment while hold waited at the client');
    mysqli_commit($mysqli);
    $read = [$hold];
    $error = [$hold];
    $reject = [$hold];
    if (mysqli_poll($read, $error, $reject, 5, 0) !== 1) {
        $fail('the hold did not acquire the client after delete committed');
    }
    $result = mysqli_reap_async_query($hold);
    if ($result === false) {
        $fail('the asynchronous hold client lock failed');
    }
    $query($hold, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id FOR UPDATE",
        'hold could not lock the parent ticket');
    $query($hold, "SELECT ticket_attachment_id FROM ticket_attachments
        WHERE ticket_attachment_id = $attachment_id FOR UPDATE", 'hold could not lock the attachment');
    mysqli_rollback($hold);

    $query($mysqli, "INSERT INTO retention_deletions SET
        retention_deletion_record_type = 'ticket', retention_deletion_record_id = $ticket_id,
        retention_deletion_client_id = $client_id, retention_deletion_label = 'preview fixture',
        retention_deletion_deleted_by = 0, retention_deletion_reason = 'preview fixture',
        retention_deletion_deleted_at = '2000-01-01 00:00:00',
        retention_deletion_purge_eligible_at = '2000-01-01 00:00:00'",
        'could not create purge preview fixture');
    $deletion_id = intval(mysqli_insert_id($mysqli));
    $policy = mysqli_fetch_assoc($query($mysqli, "SELECT retention_policy_owner_note
        FROM retention_policies WHERE retention_policy_key = 'tickets'", 'could not load ticket policy'));
    $original_policy_note = (string) ($policy['retention_policy_owner_note'] ?? '');

    $query($mysqli, "DROP TRIGGER IF EXISTS $trigger_name", 'could not reset preview pause trigger');
    $query($mysqli, "CREATE TRIGGER $trigger_name BEFORE INSERT ON retention_purge_items
        FOR EACH ROW DO SLEEP(3)", 'could not create preview pause trigger');
    $child_code = 'require ' . var_export($root . '/config.php', true) . ';'
        . 'require ' . var_export($root . '/functions.php', true) . ';'
        . 'retentionPreviewPurge(0,' . var_export($batch_key, true) . ',2000);';
    $child = proc_open([PHP_BINARY, '-r', $child_code], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($child)) {
        $fail('could not start the real purge preview process');
    }
    fclose($pipes[0]);
    usleep(750000);
    $query($contender, 'SET SESSION innodb_lock_wait_timeout = 1', 'could not set preview contender timeout');
    $query($contender, "UPDATE retention_deletions SET retention_deletion_label = 'concurrent update'
        WHERE retention_deletion_id = $deletion_id LIMIT 1",
        'purge preview retained a broad lock on deletion candidates');
    $note = mysqli_real_escape_string($contender, "Concurrent preview policy $suffix");
    $query($contender, "UPDATE retention_policies SET retention_policy_owner_note = '$note'
        WHERE retention_policy_key = 'tickets' LIMIT 1",
        'purge preview retained a lock on policy rows');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($child);
    $child = null;
    if ($status !== 0) {
        $fail('real purge preview failed: ' . trim($stderr . "\n" . $stdout));
    }
} finally {
    @mysqli_rollback($mysqli);
    @mysqli_rollback($hold);
    @mysqli_rollback($contender);
    if (is_resource($child)) {
        @proc_terminate($child);
    }
    @mysqli_query($mysqli, "DROP TRIGGER IF EXISTS $trigger_name");
    if ($original_policy_note !== '') {
        $note = mysqli_real_escape_string($mysqli, $original_policy_note);
        @mysqli_query($mysqli, "UPDATE retention_policies SET retention_policy_owner_note = '$note'
            WHERE retention_policy_key = 'tickets' LIMIT 1");
    }
    if ($attachment_id > 0) {
        @mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_id = $attachment_id");
    }
    if ($deletion_id > 0) {
        // Purge items may reference the fixture logically but have no FK. The
        // release database is disposable; immutable audit events are retained.
        @mysqli_query($mysqli, "DELETE FROM retention_deletions WHERE retention_deletion_id = $deletion_id");
    }
    if ($ticket_id > 0) {
        @mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $ticket_id");
    }
    if ($client_id > 0) {
        @mysqli_query($mysqli, "DELETE FROM clients WHERE client_id = $client_id");
    }
    mysqli_close($hold);
    mysqli_close($contender);
}

echo "Retention hold and purge-preview database lock ordering passed.\n";

