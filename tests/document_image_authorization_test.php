<?php

$root = dirname(__DIR__);
$files = [
    'agent/document_image.php' => ['enforceUserPermission', 'enforceClientAccess'],
    'client/document_image.php' => ['contactCan', 'document_client_visible = 1', 'document_archived_at IS NULL'],
    'guest/document_image.php' => ['item_key', 'item_expire_at > NOW()', 'item_view_limit'],
];

foreach ($files as $file => $controls) {
    $source = file_get_contents($root . '/' . $file);
    foreach ($controls as $control) {
        if (strpos($source, $control) === false) {
            fwrite(STDERR, "$file is missing authorization control: $control\n");
            exit(1);
        }
    }
    if (strpos($source, 'streamDocumentImage(') === false) {
        fwrite(STDERR, "$file does not use the guarded image streamer\n");
        exit(1);
    }
}

$accessPolicy = file_get_contents($root . '/uploads/documents/.htaccess');
if (strpos($accessPolicy, 'Require all denied') === false
    || strpos($accessPolicy, '-Indexes') === false) {
    fwrite(STDERR, "Document image storage permits direct access or indexing\n");
    exit(1);
}

require_once $root . '/functions/files.php';
$html = '<p><img src="/uploads/documents/42/img_0123456789abcdef0123456789abcdef.png"></p>';
$protected = protectDocumentImageUrls($html, 'document_image.php', ['id' => 7, 'key' => 'a&b']);
$expected = 'document_image.php?id=7&amp;key=a%26b&amp;document_id=42&amp;file=img_0123456789abcdef0123456789abcdef.png';
if (strpos($protected, $expected) === false || strpos($protected, '/uploads/documents/') !== false) {
    fwrite(STDERR, "Document image URL was not safely rewritten\n");
    exit(1);
}

echo "Document image authorization checks passed\n";
