<?php

$root = dirname(__DIR__);
$connect_handler = file_get_contents($root . '/admin/post/settings_mail.php');
$callback = file_get_contents($root . '/admin/oauth_microsoft_mail_callback.php');
$failures = [];

if (!is_string($connect_handler) || !is_string($callback)) {
    fwrite(STDERR, "Could not read the Microsoft mail OAuth handlers.\n");
    exit(1);
}

if (!str_contains($connect_handler, "if (\$config_imap_provider === 'microsoft_oauth')")) {
    $failures[] = 'The IMAP permission is not conditional on the saved IMAP provider.';
}
if (!str_contains($connect_handler, "if (\$config_smtp_provider === 'microsoft_oauth')")) {
    $failures[] = 'The SMTP permission is not conditional on the saved SMTP provider.';
}
if (!str_contains($connect_handler, "\$_SESSION['mail_oauth_scope'] = \$scope;")) {
    $failures[] = 'The requested permission set is not bound to the OAuth session.';
}
if (!str_contains($callback, "\$scope = \$_SESSION['mail_oauth_scope'] ?? '';")) {
    $failures[] = 'The callback does not restore the permission set from the OAuth session.';
}
if (str_contains($callback, "config_imap_provider = 'microsoft_oauth'")
    || str_contains($callback, "config_smtp_provider = 'microsoft_oauth'")) {
    $failures[] = 'The callback still overwrites an independently configured mail provider.';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Microsoft mail OAuth scope contract passed.\n";
