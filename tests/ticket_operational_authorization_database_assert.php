<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "Ticket operational authorization database test failed: $message\n");
    exit(1);
};
$query = static function (mysqli $connection, string $sql, string $message) use ($fail) {
    $result = mysqli_query($connection, $sql);
    if ($result === false) {
        $fail($message . ': ' . mysqli_error($connection));
    }
    return $result;
};
$scalar = static function (mysqli $connection, string $sql, string $message) use ($query): string {
    return (string) (mysqli_fetch_row($query($connection, $sql, $message))[0] ?? '');
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
            $fail("$label was not blocked by the transaction-bound authorization lock");
        }
        $errno = mysqli_errno($connection);
    } catch (mysqli_sql_exception $e) {
        $errno = $e->getCode();
    }
    if (!in_array(intval($errno ?? 0), [1205, 1213], true)) {
        $fail("$label failed for an unexpected reason (error " . intval($errno ?? 0) . ')');
    }
};
$expectMutationFailure = static function (callable $mutation, string $label) use ($fail): void {
    try {
        $mutation();
        $fail("$label was authorized");
    } catch (Throwable $expected) {
    }
};

$other = $connect();
$suffix = bin2hex(random_bytes(8));
$role_name = mysqli_real_escape_string($mysqli, "Ticket operations DB test $suffix");
$email = mysqli_real_escape_string($mysqli, "ticket-operations-$suffix@example.invalid");
$client_name_a = mysqli_real_escape_string($mysqli, "Ticket operations A $suffix");
$client_name_b = mysqli_real_escape_string($mysqli, "Ticket operations B $suffix");
$actor_id = 0;
$role_id = 0;
$client_id_a = 0;
$client_id_b = 0;
$ticket_id_a = 0;
$ticket_id_b = 0;

try {
    $support_module_id = intval($scalar($mysqli,
        "SELECT module_id FROM modules WHERE module_name = 'module_support' LIMIT 1",
        'could not resolve module_support'));
    if ($support_module_id <= 0) {
        $fail('module_support is unavailable');
    }
    $query($mysqli, "INSERT INTO user_roles SET role_name = '$role_name', role_description = 'test',
        role_type = 1, role_is_admin = 0", 'could not create the support role');
    $role_id = intval(mysqli_insert_id($mysqli));
    $query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = $role_id,
        module_id = $support_module_id, user_role_permission_level = 2",
        'could not grant support permission');
    $query($mysqli, "INSERT INTO users SET user_name = 'Ticket operations DB test', user_email = '$email',
        user_password = 'disabled-test-credential', user_type = 1, user_status = 1,
        user_role_id = $role_id", 'could not create the support actor');
    $actor_id = intval(mysqli_insert_id($mysqli));
    foreach ([[$client_name_a, 'a'], [$client_name_b, 'b']] as [$client_name, $slot]) {
        $query($mysqli, "INSERT INTO clients SET client_name = '$client_name',
            client_currency_code = 'USD', client_net_terms = 30", 'could not create test client');
        $client_id = intval(mysqli_insert_id($mysqli));
        if ($slot === 'a') {
            $client_id_a = $client_id;
        } else {
            $client_id_b = $client_id;
        }
    }
    foreach ([[$client_id_a, 'A'], [$client_id_b, 'B']] as [$client_id, $slot]) {
        $ticket_number = random_int(700000000, 799999999);
        $query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'TST', ticket_number = $ticket_number,
            ticket_source = 'Contract test', ticket_subject = 'Authorization $slot $suffix',
            ticket_details = 'Transaction-bound authorization fixture', ticket_priority = 'Low',
            ticket_status = 1, ticket_created_by = $actor_id, ticket_client_id = $client_id",
            'could not create test ticket');
        $ticket_id = intval(mysqli_insert_id($mysqli));
        if ($slot === 'A') {
            $ticket_id_a = $ticket_id;
        } else {
            $ticket_id_b = $ticket_id;
        }
    }
    $query($other, 'SET SESSION innodb_lock_wait_timeout = 1',
        'could not set the competing lock timeout');

    mysqli_begin_transaction($mysqli);
    ticketOperationalLockMutationActor($actor_id, 'agent', [$client_id_a]);
    $expectLockTimeout($other,
        "UPDATE user_roles SET role_archived_at = NOW() WHERE role_id = $role_id LIMIT 1",
        'concurrent role archive');
    mysqli_rollback($mysqli);

    $query($other, "UPDATE user_roles SET role_archived_at = NOW() WHERE role_id = $role_id LIMIT 1",
        'could not archive the role after lock release');
    $expectMutationFailure(static fn () => ticketOperationalUpdateTicket($ticket_id_a, [
        'impact' => 'high', 'urgency' => 'high',
    ], $actor_id, 'agent'), 'archived-role operational update');
    if ($scalar($mysqli, "SELECT ticket_priority FROM tickets WHERE ticket_id = $ticket_id_a",
        'could not verify archived-role rollback') !== 'Low') {
        $fail('archived-role update changed the ticket before failing');
    }
    $query($other, "UPDATE user_roles SET role_archived_at = NULL WHERE role_id = $role_id LIMIT 1",
        'could not restore the role');

    mysqli_begin_transaction($mysqli);
    ticketOperationalLockMutationActor($actor_id, 'agent', [$client_id_a]);
    $expectLockTimeout($other, "INSERT INTO user_client_permissions
        (user_id, client_id, permission_type) VALUES ($actor_id, $client_id_a, 'deny')",
        'concurrent client deny');
    mysqli_rollback($mysqli);

    $query($other, "INSERT INTO user_client_permissions
        (user_id, client_id, permission_type) VALUES ($actor_id, $client_id_a, 'deny')",
        'could not add the client deny after lock release');
    $expectMutationFailure(static fn () => ticketOperationalUpdateTicket($ticket_id_a, [
        'impact' => 'high', 'urgency' => 'high',
    ], $actor_id, 'agent'), 'denied-client operational update');
    if ($scalar($mysqli, "SELECT ticket_priority FROM tickets WHERE ticket_id = $ticket_id_a",
        'could not verify denied-client rollback') !== 'Low') {
        $fail('denied-client update changed the ticket before failing');
    }
    $query($other, "DELETE FROM user_client_permissions WHERE user_id = $actor_id
        AND client_id = $client_id_a", 'could not clear the first client deny');

    $query($other, "INSERT INTO user_client_permissions
        (user_id, client_id, permission_type) VALUES ($actor_id, $client_id_b, 'deny')",
        'could not add the batch client deny');
    $expectMutationFailure(static fn () => ticketOperationalBatchUpdatePriority(
        [$ticket_id_b, $ticket_id_a], 'critical', 'high', $actor_id
    ), 'partially denied bulk priority update');
    $priorities = $scalar($mysqli, "SELECT GROUP_CONCAT(ticket_priority ORDER BY ticket_id SEPARATOR ',')
        FROM tickets WHERE ticket_id IN ($ticket_id_a, $ticket_id_b)",
        'could not verify bulk priority rollback');
    if ($priorities !== 'Low,Low') {
        $fail('a partially denied bulk priority update changed one or more tickets');
    }

    echo "Ticket operational authorization database test passed\n";
} finally {
    @mysqli_rollback($mysqli);
    @mysqli_rollback($other);
    if ($actor_id > 0) {
        @mysqli_query($other, "DELETE FROM user_client_permissions WHERE user_id = $actor_id");
    }
    foreach ([$ticket_id_a, $ticket_id_b] as $ticket_id) {
        if ($ticket_id > 0) {
            @mysqli_query($other, "DELETE FROM tickets WHERE ticket_id = $ticket_id");
        }
    }
    foreach ([$client_id_a, $client_id_b] as $client_id) {
        if ($client_id > 0) {
            @mysqli_query($other, "DELETE FROM clients WHERE client_id = $client_id");
        }
    }
    if ($actor_id > 0) {
        @mysqli_query($other, "DELETE FROM users WHERE user_id = $actor_id");
    }
    if ($role_id > 0) {
        @mysqli_query($other, "DELETE FROM user_role_permissions WHERE user_role_id = $role_id");
        @mysqli_query($other, "DELETE FROM user_roles WHERE role_id = $role_id");
    }
    mysqli_close($other);
}
