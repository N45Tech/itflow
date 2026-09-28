<?php

/* Credential reads must authenticate the independent vault password first. */

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/api/v1/credentials/read.php');
$model = file_get_contents($root . '/api/v1/credentials/credential_model.php');

if ($endpoint === false || $model === false) {
    fwrite(STDERR, "Could not read the credential API files.\n");
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

if (substr_count($model, 'apiEncryptCredentialEntry(') !== 2
    || substr_count($model, "'message' => 'Invalid credential decryption password.'") !== 2) {
    fwrite(STDERR, "Credential writes do not reject failed vault-key recovery.\n");
    exit(1);
}

require_once $root . '/functions/security.php';

$vault_password = 'correct vault password';
$master_key = randomString();
$wrapped_master_key = setupFirstUserSpecificKey($vault_password, $master_key);

if (apiEncryptCredentialEntry('replacement', $wrapped_master_key, 'incorrect vault password') !== false) {
    fwrite(STDERR, "Credential encryption accepted an invalid vault password.\n");
    exit(1);
}

$ciphertext = apiEncryptCredentialEntry('preserved', $wrapped_master_key, $vault_password);
if (!is_string($ciphertext)
    || apiDecryptCredentialEntry($ciphertext, $wrapped_master_key, $vault_password) !== 'preserved') {
    fwrite(STDERR, "Credential encryption no longer works with a valid vault password.\n");
    exit(1);
}

echo "Credential API decryption contract passed.\n";
