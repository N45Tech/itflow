<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') {
    exit("Disposable database required.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$assert = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$id = static fn() => intval(mysqli_insert_id($mysqli));
$scalar = static fn($sql) => mysqli_fetch_row(ticketEmailDb($sql))[0];
$clients = $contacts = [];
foreach (['A', 'B', 'C'] as $letter) {
    ticketEmailDb("INSERT INTO clients SET client_name = 'Contact API fixture $letter', client_currency_code = 'USD', client_net_terms = 30"); $clients[$letter] = $id();
    ticketEmailDb("INSERT INTO contacts SET contact_name = 'Contact API $letter', contact_email = 'api-$letter@example.invalid', contact_phone = 'phone-$letter', contact_mobile = 'mobile-$letter', contact_pin = 'secret-pin-$letter', contact_client_id = {$clients[$letter]}"); $contacts[$letter] = $id();
}
ticketEmailDb("INSERT INTO user_roles SET role_name = 'Contact API read-only fixture', role_is_admin = 0"); $role = $id();
$module = intval($scalar("SELECT COALESCE(MAX(module_id),0) FROM modules WHERE module_name = 'module_client'"));
if (!$module) { ticketEmailDb("INSERT INTO modules SET module_name = 'module_client'"); $module = $id(); }
ticketEmailDb("INSERT INTO user_role_permissions SET user_role_id = $role, module_id = $module, user_role_permission_level = 1");
ticketEmailDb("INSERT INTO users SET user_name = 'Contact API fixture', user_email = 'api-fixture@example.invalid', user_password = 'fixture', user_role_id = $role, user_type = 1, user_status = 1"); $user = $id();
$key = 'contact-api-ci-' . bin2hex(random_bytes(16));
ticketEmailDb("INSERT INTO api_keys SET api_key_name = 'Contact API fixture', api_key_secret = '$key', api_key_decrypt_hash = 'fixture', api_key_user_id = $user, api_key_expire = DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)");
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$assert($listener !== false, 'Cannot reserve local API test port');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$log = tempnam(sys_get_temp_dir(), 'contact-api-ci-');
$command = [PHP_BINARY, '-c', php_ini_loaded_file(), '-S', $address, '-t', dirname(__DIR__)];
$process = proc_open($command, [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, dirname(__DIR__));
$assert(is_resource($process), 'Cannot start local API endpoint');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(100000);
    }
    $assert($ready, 'Local API did not start');
    $read = static function ($query, $credential = null) use ($address, $key, $assert) {
        $context = stream_context_create(['http' => ['header' => 'Authorization: Bearer ' . ($credential ?? $key) . "\r\nUser-Agent: N45 local API regression\r\n", 'timeout' => 10, 'ignore_errors' => true]]);
        $response = file_get_contents('http://' . $address . '/api/v1/contacts/read.php?' . http_build_query($query), false, $context);
        $data = json_decode($response, true);
        $assert(is_array($data), 'API returned invalid JSON: ' . substr($response, 0, 150));
        foreach ($data['data'] ?? [] as $row) {
            $assert(!array_key_exists('contact_pin', $row), 'Contact API disclosed a verification PIN');
        }
        return $data['data'] ?? [];
    };
    $queries = static fn($letter) => [
        ['contact_id' => $contacts[$letter]],
        ['contact_email' => "api-$letter@example.invalid"],
        ['contact_phone_or_mobile' => "phone-$letter"],
        ['contact_phone_or_mobile' => "mobile-$letter"],
    ];
    $count = 0;
    // Allow-only A, then unrestricted except denied B, then allow A and deny B.
    foreach (['allow','deny','both'] as $mode) {
        ticketEmailDb("DELETE FROM user_client_permissions WHERE user_id = $user");
        if ($mode !== 'deny') { ticketEmailDb("INSERT INTO user_client_permissions SET user_id = $user, client_id = {$clients['A']}, permission_type = 'allow'"); }
        if ($mode !== 'allow') { ticketEmailDb("INSERT INTO user_client_permissions SET user_id = $user, client_id = {$clients['B']}, permission_type = 'deny'"); }
        foreach (['A','B','C'] as $letter) {
            $allowed = $letter === 'A' || ($mode === 'deny' && $letter === 'C');
            foreach ($queries($letter) as $query) {
                foreach ([null, $clients[$letter], $clients['A']] as $explicit) {
                    $parameters = $query;
                    if ($explicit !== null) { $parameters['client_id'] = $explicit; }
                    $rows = $read($parameters);
                    $expected = $allowed && ($explicit === null || $explicit === $clients[$letter]);
                    $assert(count($rows) === ($expected ? 1 : 0), "Client scope mismatch: $mode $letter " . json_encode($parameters));
                    if ($expected) { $assert(intval($rows[0]['contact_id']) === $contacts[$letter], 'API returned the wrong client contact'); }
                    $count++;
                }
            }
        }
        foreach (['A','B','C'] as $letter) {
            $rows = $read(['client_id' => $clients[$letter]]);
            $expected = $letter === 'A' || ($mode === 'deny' && $letter === 'C');
            $assert(count($rows) === ($expected ? 1 : 0), 'List endpoint escaped client scope');
            $count++;
        }
    }
    $assert($read(['contact_id' => $contacts['A']], 'invalid-key') === [], 'Invalid API key disclosed a contact');
    echo "Contact API HTTP assertions passed: $count scoped reads, both telephone branches, explicit client filters, allow/deny combinations and PIN exclusion.\n";
} finally {
    proc_terminate($process);
    fclose($pipes[0]);
    proc_close($process);
    @unlink($log);
    ticketEmailDb("DELETE FROM api_keys WHERE api_key_user_id = $user");
}
