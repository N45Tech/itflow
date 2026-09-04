<?php

$failures = [];
$entrypoint = file_get_contents(dirname(__DIR__) . '/deploy/psa/container-entrypoint.sh');

if ($entrypoint === false) {
    fwrite(STDERR, "Could not read the production container entrypoint\n");
    exit(1);
}

if (!str_contains($entrypoint, 'install -d -m 0750 /var/lib/itflow/uploads')) {
    $failures[] = 'Upload directory creation still requires an ownership change';
}

if (!preg_match('/if \[ "\$\(id -u\)" -eq 0 \]; then\s+chown -R www-data:www-data \/var\/lib\/itflow\s+fi/s', $entrypoint)) {
    $failures[] = 'Recursive application-data ownership is not restricted to root startup';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Container entrypoint privilege contracts passed." . PHP_EOL;
