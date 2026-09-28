<?php

require_once __DIR__ . '/../functions/backup.php';

function validateUploadEntries(array $entries, ?string &$error = null): bool
{
    $path = tempnam(sys_get_temp_dir(), 'upload_validation_');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    $zip->open($path);
    $valid = backupValidateUploadsZip($zip, $error);
    $zip->close();
    unlink($path);

    return $valid;
}

$error = null;
if (!validateUploadEntries(['documents/invoice.pdf' => '%PDF harmless'], $error)) {
    throw new RuntimeException("A harmless upload was rejected: $error");
}

foreach ([
    ['shell.php' => '<?php echo "owned";'],
    ['.htaccess' => 'SetHandler application/x-httpd-php'],
    ['avatar.jpg' => '<?php passthru($_GET["cmd"]);'],
    ['../outside.txt' => 'escaped'],
] as $entries) {
    $error = null;
    if (validateUploadEntries($entries, $error) || $error === null) {
        throw new RuntimeException('A dangerous upload was accepted');
    }
}

echo "backup upload restore security tests passed\n";
