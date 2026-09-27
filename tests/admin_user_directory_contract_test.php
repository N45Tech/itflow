<?php

$page = file_get_contents(dirname(__DIR__) . '/admin/users.php');
$failures = array();

if (str_contains($page, 'SQL_CALC_FOUND_ROWS') || str_contains($page, 'FOUND_ROWS()')) {
    $failures[] = 'User pagination must use an explicit count independent of the page query';
}
if (!str_contains($page, 'SELECT COUNT(*) FROM users WHERE $user_where')) {
    $failures[] = 'The page count must use the same user filters as the visible rows';
}
if (!str_contains($page, 'WHERE log_user_id IN ($user_ids) AND log_type = \'Login\'')) {
    $failures[] = 'Last login lookup must be bounded to the current page';
}
if (!str_contains($page, 'WHERE remember_token_user_id IN ($user_ids)')) {
    $failures[] = 'Remember token count must be bounded to the current page';
}
if (preg_match('/foreach\s*\(\$users as \$row\).*?mysqli_query\s*\(/s', $page)) {
    $failures[] = 'The user rendering loop must not issue a database query per row';
}
if (str_contains($page, 'user_client_permissions')) {
    $failures[] = 'Do not restore the unused client permissions lookup';
}

if ($failures) {
    fwrite(STDERR, "Admin user directory contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Admin user directory contract passed\n";
