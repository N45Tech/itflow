<?php

/* Credential reads must authenticate the independent vault password first. */

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/api/v1/credentials/read.php');

if ($endpoint === false) {
    fwrite(STDERR, "Could not read the credential API endpoint.\n");
    exit(1);
}

$validation = strpos($endpoint, 'decryptUserSpecificKey($api_key_decrypt_hash, $api_key_decrypt_password) === false');
$query = strpos($endpoint, 'SELECT * FROM credentials');

if ($validation === false || $query === false || $validation > $query) {
    fwrite(STDERR, "Credential rows can be selected before the vault password is validated.\n");
    exit(1);
}

if (!str_contains($endpoint, "http_response_code(401);")
    || !str_contains($endpoint, "'message' => 'Invalid credential decryption password.'")) {
    fwrite(STDERR, "An invalid vault password does not fail closed.\n");
    exit(1);
}

echo "Credential API decryption contract passed.\n";
