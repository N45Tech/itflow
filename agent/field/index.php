<?php
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/functions.php';
require_once dirname(__DIR__, 2) . '/includes/check_login.php';
if (lookupUserPermission('module_support') < 1) {
    http_response_code(403);
    exit('Support access is required for Field Mode.');
}
readfile(__DIR__ . '/shell.html');
