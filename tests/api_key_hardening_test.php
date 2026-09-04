<?php

/* API credentials and their administration must fail closed under concurrency. */

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$contains = static function (string $contents, string $needle, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};
$ordered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $offset = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $offset + 1);
        if ($position === false || $position <= $offset) {
            $failures[] = "$message (missing/out of order: $needle)";
            return;
        }
        $offset = $position;
    }
};

$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0016-release-safety-hardening.php');
$validator = $read('api/v1/validate_api_key.php');
$rbac = $read('api/v1/enforce_api_rbac.php');
$admin = $read('admin/post/api_keys.php');

$contains($schema, 'api_key_secret', 'API secrets are absent from the baseline schema');
$contains($schema, 'varbinary(255) NOT NULL', 'API secrets are not binary in the baseline schema');
$contains($schema, 'api_key_secret_unique', 'API secrets are not unique in the baseline schema');
$ordered($validator, [
    'require_once __DIR__ . "../../../config.php";',
    "require_once dirname(__DIR__, 2) . '/includes/inc_set_timezone.php';",
    "header('Content-Type: application/json');",
    'api_key_expire > CURRENT_DATE()',
], 'API requests do not establish the configured application timezone before authentication and writes');
$ordered($migration, [
    'OCTET_LENGTH(api_key_secret) = 0',
    'GROUP BY BINARY api_key_secret HAVING duplicate_count > 1',
    'varbinary(255) NOT NULL',
    'ADD UNIQUE KEY',
], 'The migration does not reject unsafe existing secrets before adding exact uniqueness');
$ordered($validator, [
    'mysqli_prepare($mysqli, "SELECT api_key_id',
    'api_key_secret = BINARY ?',
    'LIMIT 2',
    'mysqli_stmt_bind_param($api_key_statement, \'s\', $api_key)',
    'mysqli_num_rows($sql) !== 1',
], 'Authentication is not a prepared exact-binary, exactly-one-row lookup');

$contains($admin, 'function apiKeyLockAuthorization(', 'API-key mutation authorization is not transaction-bound');
$contains($admin, 'role_is_admin, role_archived_at', 'API-key mutations do not lock role lifecycle state');
$contains($admin, "['archived_at'] !== null", 'API-key mutations do not reject archived roles');
$contains($admin, 'ORDER BY user_id FOR UPDATE', 'API-key principals are not locked deterministically');
$contains($admin, 'function apiKeyLockRow(', 'API-key mutations do not share a row-lock helper');
$contains($admin, '$lock_order->observe(\'audit\')', 'API-key audit ordering is not asserted');
$contains($admin, "if (!logAudit('API Key'", 'API-key audit failures do not abort mutations');
$contains($rbac, 'user_roles.role_id AS linked_role_id', 'API authentication does not prove the linked role exists');
$contains($rbac, 'role_is_admin, role_archived_at', 'API authentication does not load linked-role lifecycle state');
$contains($rbac, 'intval($api_user[\'linked_role_id\']) !== intval($api_user[\'user_role_id\'])',
    'API authentication does not reject a dangling role reference');
$contains($rbac, '$api_user[\'role_archived_at\'] !== null', 'API authentication does not reject archived linked roles');
$ordered($admin, [
    "if (isset(\$_POST['bulk_delete_api_keys']))",
    'sort($api_key_ids, SORT_NUMERIC)',
    '$rows[$api_key_id] = apiKeyLockRow($api_key_id, $lock_order)',
    'DELETE FROM api_keys',
    '$lock_order->observe(\'audit\')',
    "apiKeyAudit('Delete'",
    'apiKeyCommit()',
], 'Bulk deletion does not lock, mutate, audit, and commit in canonical order');
if (str_contains($admin, '$session_name revoked API key $name')
    || str_contains($admin, '$session_name deleted API key $name')) {
    $failures[] = 'Revoke/delete auditing still references an undefined key name';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "API-key hardening contract passed.\n";
