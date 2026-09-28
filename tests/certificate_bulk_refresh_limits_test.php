<?php

/* Bulk certificate refreshes must remain bounded because each TLS lookup may block. */

$handler = file_get_contents(dirname(__DIR__) . '/agent/post/certificate.php');

if ($handler === false) {
    fwrite(STDERR, "Could not read the certificate post handler.\n");
    exit(1);
}

$required_controls = [
    "is_array(\$_POST['certificate_ids'])",
    "array_unique(",
    '$maximum_certificate_refreshes',
    '$certificate_refresh_time_limit',
    'microtime(true) - $refresh_started_at',
];

foreach ($required_controls as $control) {
    if (!str_contains($handler, $control)) {
        fwrite(STDERR, "Missing bulk certificate refresh control: $control\n");
        exit(1);
    }
}

if (str_contains($handler, 'set_time_limit(0)')) {
    fwrite(STDERR, "Bulk certificate refreshes disable PHP's execution timeout.\n");
    exit(1);
}

echo "Certificate bulk refresh limits contract passed.\n";
