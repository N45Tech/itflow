<?php

/* Thumbnail audit suppression must only apply to safe inline images, not to
 * arbitrary files whose request includes the caller-controlled thumb flag. */

$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/agent/file.php');
$failures = [];

$mime_assignment = strpos($endpoint, '$file_mime_type =');
$thumbnail_validation = strpos($endpoint, '$thumb = $thumbnail_requested');

if ($mime_assignment === false || $thumbnail_validation === false || $thumbnail_validation < $mime_assignment) {
    $failures[] = 'Thumbnail mode is not validated after loading the stored MIME type';
}

if (!str_contains($endpoint, 'strpos($file_mime_type, "image/") === 0')) {
    $failures[] = 'Thumbnail audit suppression is not restricted to image MIME types';
}

if (!str_contains($endpoint, 'in_array($file_mime_type, $inline_allowed_mime_types, true)')) {
    $failures[] = 'Thumbnail audit suppression is not restricted to safe inline MIME types';
}

if (!str_contains($endpoint, 'if (!$thumb) {')
    || !str_contains($endpoint, 'logAudit("File", "Download"')) {
    $failures[] = 'File downloads are not audited outside validated thumbnail mode';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "File thumbnail audit security contract passed.\n";
