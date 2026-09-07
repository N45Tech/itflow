<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final' || !in_array($argv[1] ?? '', ['notices','notices_wait'], true)) { exit(1); }
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/functions.php';
date_default_timezone_set('America/New_York');
$session_is_admin = true; $client_access_array = $client_deny_array = [];
if ($argv[1] === 'notices_wait') { echo "ready\n"; fflush(STDOUT); fgets(STDIN); }
followupSendDueNotices();
