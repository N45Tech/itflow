<?php

/* Fail-closed role binding for authenticated web sessions. */

$source = @file_get_contents(dirname(__DIR__) . '/includes/load_user_session.php');
if ($source === false) {
    fwrite(STDERR, "Could not read includes/load_user_session.php\n");
    exit(1);
}

$required = [
    'role_id AS active_role_id',
    'role_type',
    'role_archived_at',
    'if (!$row)',
    "intval(\$row['active_role_id'] ?? 0) !== \$session_user_role",
    "intval(\$row['role_type'] ?? 0) !== 1",
    "\$row['role_archived_at'] !== null",
    'session_destroy()',
    'redirect("/login.php")',
];
$missing = [];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        $missing[] = $needle;
    }
}
if ($missing) {
    fwrite(STDERR, "Web session role binding is incomplete: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "Web authorization contract passed.\n";

