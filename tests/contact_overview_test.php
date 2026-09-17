<?php

$source_path = dirname(__DIR__) . '/agent/contacts.php';
$source = file_get_contents($source_path);
if ($source === false) {
    fwrite(STDERR, "Could not read agent/contacts.php\n");
    exit(1);
}

$initializer = strpos($source, '$client_id = 0;');
$client_branch = strpos($source, "if (isset(\$_GET['client_id']))");
$legacy_add_contact_url = strpos(
    $source,
    'modals/contact/contact_add.php?client_id=<?= $client_id ?>'
);
$shared_header_add_contact_url = strpos(
    $source,
    "'data-modal-url' => 'modals/contact/contact_add.php?client_id=' . intval(\$client_id ?? 0)"
);
$add_contact_url = $shared_header_add_contact_url !== false
    ? $shared_header_add_contact_url
    : $legacy_add_contact_url;

if ($initializer === false || $client_branch === false || $add_contact_url === false) {
    fwrite(STDERR, "Contacts overview client context contract is incomplete\n");
    exit(1);
}

if ($initializer > $client_branch || $initializer > $add_contact_url) {
    fwrite(STDERR, "Contacts overview uses client_id before its all-client default\n");
    exit(1);
}

echo "Contacts overview client context checks passed\n";
