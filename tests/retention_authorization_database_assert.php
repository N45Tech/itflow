<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions.php';

$fail = static function (string $message): void {
    fwrite(STDERR, "Retention authorization database test failed: $message\n");
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
$expectAuthorizationFailure = static function (int $actor_id, string $label) use ($fail): void {
    global $mysqli;
    mysqli_begin_transaction($mysqli);
    try {
        retentionLockAdministratorActor($actor_id);
        mysqli_rollback($mysqli);
        $fail("$label retained administrator authorization");
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
    }
};

$other = $connect();
$suffix = bin2hex(random_bytes(8));
$role_name = mysqli_real_escape_string($mysqli, "Retention DB test $suffix");
$email = mysqli_real_escape_string($mysqli, "retention-$suffix@example.invalid");
$actor_id = 0;
$role_id = 0;

try {
    $query($mysqli, "INSERT INTO user_roles SET role_name = '$role_name', role_description = 'test',
        role_type = 1, role_is_admin = 1", 'could not create the administrator role');
    $role_id = intval(mysqli_insert_id($mysqli));
    $query($mysqli, "INSERT INTO users SET user_name = 'Retention DB test', user_email = '$email',
        user_password = 'disabled-test-credential', user_type = 1, user_status = 1,
        user_role_id = $role_id", 'could not create the administrator actor');
    $actor_id = intval(mysqli_insert_id($mysqli));
    $query($other, 'SET SESSION innodb_lock_wait_timeout = 1', 'could not set the competing lock timeout');

    mysqli_begin_transaction($mysqli);
    retentionLockAdministratorActor($actor_id);
    $expectLockTimeout($other, "UPDATE users SET user_status = 0 WHERE user_id = $actor_id LIMIT 1",
        'concurrent user disable');
    mysqli_rollback($mysqli);

    $query($other, "UPDATE users SET user_status = 0 WHERE user_id = $actor_id LIMIT 1",
        'could not disable the actor after lock release');
    $expectAuthorizationFailure($actor_id, 'disabled actor');
    $query($other, "UPDATE users SET user_status = 1 WHERE user_id = $actor_id LIMIT 1",
        'could not reactivate the actor');

    mysqli_begin_transaction($mysqli);
    retentionLockAdministratorActor($actor_id);
    $expectLockTimeout($other, "UPDATE user_roles SET role_is_admin = 0 WHERE role_id = $role_id LIMIT 1",
        'concurrent administrator demotion');
    mysqli_rollback($mysqli);

    $query($other, "UPDATE user_roles SET role_is_admin = 0 WHERE role_id = $role_id LIMIT 1",
        'could not demote the role after lock release');
    $expectAuthorizationFailure($actor_id, 'demoted actor');
} finally {
    @mysqli_rollback($mysqli);
    @mysqli_rollback($other);
    if ($actor_id > 0) {
        @mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $actor_id LIMIT 1");
    }
    if ($role_id > 0) {
        @mysqli_query($mysqli, "DELETE FROM user_roles WHERE role_id = $role_id LIMIT 1");
    }
    mysqli_close($other);
}

echo "Retention authorization database locking passed.\n";

