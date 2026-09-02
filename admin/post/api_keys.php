<?php

/*
 * ITFlow - API-key administration.
 *
 * Every mutation revalidates the acting administrator while its authorization
 * rows are locked, then locks API-key rows in ascending order. The mutation and
 * its audit record commit together.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

function apiKeyDbQuery(string $query, string $message)
{
    global $mysqli;
    $result = mysqli_query($mysqli, $query);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function apiKeyLockAuthorization(array $target_user_ids, N45LockOrder $lock_order): array
{
    global $session_user_id;

    $actor_id = intval($session_user_id);
    $target_user_ids = array_values(array_unique(array_filter(array_map(
        'intval',
        $target_user_ids
    ), static fn (int $id): bool => $id > 0)));
    $user_ids = array_values(array_unique(array_merge([$actor_id], $target_user_ids)));
    sort($user_ids, SORT_NUMERIC);
    if ($actor_id < 1 || !$user_ids) {
        throw new RuntimeException('The administrator session is unavailable');
    }

    $id_sql = implode(',', $user_ids);
    $preview = apiKeyDbQuery("SELECT user_id, user_role_id FROM users
        WHERE user_id IN ($id_sql) ORDER BY user_id", 'Could not inspect API-key principals');
    $role_ids = [];
    while ($row = mysqli_fetch_assoc($preview)) {
        $role_id = intval($row['user_role_id']);
        if ($role_id > 0) {
            $role_ids[] = $role_id;
        }
    }
    $role_ids = array_values(array_unique($role_ids));
    sort($role_ids, SORT_NUMERIC);

    $lock_order->observe('authorization');
    $roles = [];
    if ($role_ids) {
        $role_id_sql = implode(',', $role_ids);
        $role_rows = apiKeyDbQuery("SELECT role_id, role_is_admin FROM user_roles
            WHERE role_id IN ($role_id_sql) ORDER BY role_id FOR UPDATE",
            'Could not lock API-key authorization roles');
        while ($row = mysqli_fetch_assoc($role_rows)) {
            $roles[intval($row['role_id'])] = intval($row['role_is_admin']);
        }
    }

    $locked_users = apiKeyDbQuery("SELECT user_id, user_role_id, user_type, user_status,
        user_archived_at FROM users WHERE user_id IN ($id_sql) ORDER BY user_id FOR UPDATE",
        'Could not lock API-key principals');
    $users = [];
    while ($row = mysqli_fetch_assoc($locked_users)) {
        $users[intval($row['user_id'])] = $row;
    }
    if (count($users) !== count($user_ids)) {
        throw new RuntimeException('An API-key principal no longer exists');
    }

    $actor = $users[$actor_id] ?? null;
    $actor_role_id = intval($actor['user_role_id'] ?? 0);
    if (!$actor
        || intval($actor['user_type']) !== 1
        || intval($actor['user_status']) !== 1
        || $actor['user_archived_at'] !== null
        || intval($roles[$actor_role_id] ?? 0) !== 1) {
        throw new RuntimeException('Administrator authorization changed before the API-key mutation');
    }

    foreach ($target_user_ids as $target_user_id) {
        $target = $users[$target_user_id] ?? null;
        if (!$target
            || intval($target['user_type']) !== 1
            || intval($target['user_status']) !== 1
            || $target['user_archived_at'] !== null
            || !array_key_exists(intval($target['user_role_id']), $roles)) {
            throw new RuntimeException('The API key must run as an active agent');
        }
    }

    return $users;
}

function apiKeyLockRow(int $api_key_id, N45LockOrder $lock_order): array
{
    $api_key_id = intval($api_key_id);
    if ($api_key_id < 1) {
        throw new InvalidArgumentException('An API key is required');
    }
    $lock_order->observe('api_key', $api_key_id);
    $row = mysqli_fetch_assoc(apiKeyDbQuery("SELECT api_key_id, api_key_name, api_key_expire,
        api_key_user_id FROM api_keys WHERE api_key_id = $api_key_id LIMIT 1 FOR UPDATE",
        'Could not lock the API key'));
    if (!$row) {
        throw new RuntimeException('The API key no longer exists');
    }
    return $row;
}

function apiKeyAudit(string $action, string $description, int $api_key_id = 0): void
{
    if (!logAudit('API Key', $action, $description, 0, $api_key_id)) {
        throw new RuntimeException('Could not append the API-key audit record');
    }
}

function apiKeyCommit(): void
{
    global $mysqli;
    if (!mysqli_commit($mysqli)) {
        throw new RuntimeException('Could not commit the API-key mutation');
    }
}

function apiKeyMutationFailed(Throwable $exception, string $message): never
{
    global $mysqli;
    mysqli_rollback($mysqli);
    logApp('API Key', 'error', $exception->getMessage());
    flashAlert($message, 'error');
    redirect();
}

if (isset($_POST['add_api_key'])) {
    validateCSRFToken();

    $name_raw = trim((string) ($_POST['name'] ?? ''));
    $expire_raw = trim((string) ($_POST['expire'] ?? ''));
    $user_id = intval($_POST['run_as_user'] ?? 0);
    $secret_raw = (string) ($_POST['key'] ?? '');
    if ($name_raw === '' || strlen($name_raw) > 255
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire_raw) !== 1
        || $user_id < 1
        || preg_match('/^[A-Za-z0-9_-]{32}$/', $secret_raw) !== 1) {
        flashAlert('The API key details are invalid. Generate a new key and try again.', 'error');
        redirect();
    }

    $name = escapeSql($name_raw);
    $expire = escapeSql($expire_raw);
    $secret = escapeSql($secret_raw);
    $ciphertext = escapeSql(encryptUserSpecificKey(trim((string) ($_POST['password'] ?? ''))));

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The API key could not be created safely.', 'error');
        redirect();
    }
    try {
        $lock_order = new N45LockOrder('API-key creation');
        apiKeyLockAuthorization([$user_id], $lock_order);
        apiKeyDbQuery("INSERT INTO api_keys SET api_key_name = '$name',
            api_key_secret = '$secret', api_key_decrypt_hash = '$ciphertext',
            api_key_expire = '$expire', api_key_user_id = $user_id",
            'Could not create a unique API key');
        $api_key_id = intval(mysqli_insert_id($mysqli));
        $lock_order->observe('api_key', $api_key_id);
        $lock_order->observe('audit');
        apiKeyAudit('Create', "$session_name created API key $name set to expire on $expire", $api_key_id);
        apiKeyCommit();
    } catch (Throwable $exception) {
        apiKeyMutationFailed($exception, 'The API key was not created. Generate a new key and try again.');
    }

    flashAlert('API Key <strong>' . escapeHtml($name_raw) . '</strong> created');
    redirect();
}

if (isset($_POST['edit_api_key'])) {
    validateCSRFToken();

    $api_key_id = intval($_POST['api_key_id'] ?? 0);
    $name_raw = trim((string) ($_POST['name'] ?? ''));
    $expire_raw = trim((string) ($_POST['expire'] ?? ''));
    $user_id = intval($_POST['run_as_user'] ?? 0);
    if ($name_raw === '' || strlen($name_raw) > 255
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire_raw) !== 1 || $user_id < 1) {
        flashAlert('The API key details are invalid.', 'error');
        redirect();
    }
    $name = escapeSql($name_raw);
    $expire = escapeSql($expire_raw);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The API key could not be updated safely.', 'error');
        redirect();
    }
    try {
        $lock_order = new N45LockOrder('API-key edit');
        apiKeyLockAuthorization([$user_id], $lock_order);
        apiKeyLockRow($api_key_id, $lock_order);
        apiKeyDbQuery("UPDATE api_keys SET api_key_name = '$name', api_key_expire = '$expire',
            api_key_user_id = $user_id WHERE api_key_id = $api_key_id LIMIT 1",
            'Could not update the API key');
        $lock_order->observe('audit');
        apiKeyAudit('Edit', "$session_name edited API key $name", $api_key_id);
        apiKeyCommit();
    } catch (Throwable $exception) {
        apiKeyMutationFailed($exception, 'The API key was not updated. Refresh and try again.');
    }

    flashAlert('API Key <strong>' . escapeHtml($name_raw) . '</strong> updated');
    redirect();
}

if (isset($_GET['revoke_api_key'])) {
    validateCSRFToken();
    $api_key_id = intval($_GET['revoke_api_key']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The API key could not be revoked safely.', 'error');
        redirect();
    }
    try {
        $lock_order = new N45LockOrder('API-key revocation');
        apiKeyLockAuthorization([], $lock_order);
        $row = apiKeyLockRow($api_key_id, $lock_order);
        $api_key_name = escapeSql($row['api_key_name']);
        apiKeyDbQuery("UPDATE api_keys SET api_key_expire = CURRENT_DATE()
            WHERE api_key_id = $api_key_id LIMIT 1", 'Could not revoke the API key');
        $lock_order->observe('audit');
        apiKeyAudit('Revoke', "$session_name revoked API key $api_key_name", $api_key_id);
        apiKeyCommit();
    } catch (Throwable $exception) {
        apiKeyMutationFailed($exception, 'The API key was not revoked. Refresh and try again.');
    }

    flashAlert('API Key <strong>' . escapeHtml($row['api_key_name']) . '</strong> revoked', 'error');
    redirect();
}

if (isset($_GET['delete_api_key'])) {
    validateCSRFToken();
    $api_key_id = intval($_GET['delete_api_key']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The API key could not be deleted safely.', 'error');
        redirect();
    }
    try {
        $lock_order = new N45LockOrder('API-key deletion');
        apiKeyLockAuthorization([], $lock_order);
        $row = apiKeyLockRow($api_key_id, $lock_order);
        $api_key_name = escapeSql($row['api_key_name']);
        apiKeyDbQuery("DELETE FROM api_keys WHERE api_key_id = $api_key_id LIMIT 1",
            'Could not delete the API key');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The API key changed before deletion');
        }
        $lock_order->observe('audit');
        apiKeyAudit('Delete', "$session_name deleted API key $api_key_name", $api_key_id);
        apiKeyCommit();
    } catch (Throwable $exception) {
        apiKeyMutationFailed($exception, 'The API key was not deleted. Refresh and try again.');
    }

    flashAlert('API Key <strong>' . escapeHtml($row['api_key_name']) . '</strong> deleted', 'error');
    redirect();
}

if (isset($_POST['bulk_delete_api_keys'])) {
    validateCSRFToken();
    $api_key_ids = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($_POST['api_key_ids'] ?? [])
    ), static fn (int $id): bool => $id > 0)));
    sort($api_key_ids, SORT_NUMERIC);
    if (!$api_key_ids) {
        flashAlert('Choose at least one API key.', 'warning');
        redirect();
    }

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The API keys could not be deleted safely.', 'error');
        redirect();
    }
    try {
        $lock_order = new N45LockOrder('bulk API-key deletion');
        apiKeyLockAuthorization([], $lock_order);
        $rows = [];
        foreach ($api_key_ids as $api_key_id) {
            $rows[$api_key_id] = apiKeyLockRow($api_key_id, $lock_order);
        }
        foreach ($api_key_ids as $api_key_id) {
            apiKeyDbQuery("DELETE FROM api_keys WHERE api_key_id = $api_key_id LIMIT 1",
                'Could not delete an API key');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('An API key changed before bulk deletion');
            }
        }
        $lock_order->observe('audit');
        foreach ($api_key_ids as $api_key_id) {
            $api_key_name = escapeSql($rows[$api_key_id]['api_key_name']);
            apiKeyAudit('Delete', "$session_name deleted API key $api_key_name", $api_key_id);
        }
        $count = count($api_key_ids);
        apiKeyAudit('Bulk Delete', "$session_name deleted $count API key(s)");
        apiKeyCommit();
    } catch (Throwable $exception) {
        apiKeyMutationFailed($exception, 'No API keys were deleted. Refresh and try again.');
    }

    flashAlert('Deleted <strong>' . count($api_key_ids) . '</strong> API key(s)', 'error');
    redirect();
}
