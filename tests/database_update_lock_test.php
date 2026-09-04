<?php

$failures = [];
$root = dirname(__DIR__);
$runner = file_get_contents($root . '/admin/database_updates.php');
if ($runner === false) {
    fwrite(STDERR, "Could not read admin/database_updates.php\n");
    exit(1);
}

$assertContains = function (string $needle, string $message) use ($runner, &$failures): void {
    if (!str_contains($runner, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertContains("GET_LOCK('\$database_update_lock_name_sql', 0)", 'Database updates do not acquire a fail-fast advisory lock');
$assertContains('$database_update_lock_acquired = true;', 'The runner does not track advisory-lock ownership');
$assertContains("FROM settings WHERE company_id = 1 LIMIT 1", 'The durable version is not refreshed after locking');
$assertContains('finally {', 'Database update cleanup is not guaranteed');
$assertContains("RELEASE_LOCK('\$database_update_lock_name_sql')", 'The advisory lock is not explicitly released');
$assertContains('The database update lock release could not be confirmed', 'Lock release failure does not fail closed');

$lock = strpos($runner, 'GET_LOCK(');
$refresh = strpos($runner, 'FROM settings WHERE company_id = 1 LIMIT 1');
$loop = strpos($runner, 'foreach ($database_update_files as $version => $file)');
$require = strpos($runner, 'require $file;', $loop === false ? 0 : $loop);
$marker = strpos($runner, 'SET `config_current_database_version`', $require === false ? 0 : $require);
$applied = strpos($runner, '$database_updates_applied[] = $version;', $marker === false ? 0 : $marker);
$finally = strpos($runner, 'finally {', $applied === false ? 0 : $applied);
$release = strpos($runner, 'RELEASE_LOCK(', $finally === false ? 0 : $finally);

if ($lock === false || $refresh === false || $loop === false || $require === false
    || $marker === false || $applied === false || $finally === false || $release === false
    || !($lock < $refresh && $refresh < $loop && $loop < $require
        && $require < $marker && $marker < $applied && $applied < $finally && $finally < $release)) {
    $failures[] = 'Lock, durable-version refresh, migration, marker, and guaranteed release are not ordered safely';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Database update advisory lock checks passed." . PHP_EOL;
