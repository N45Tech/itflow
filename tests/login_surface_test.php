<?php

require_once __DIR__ . '/../functions/login_surface.php';

$failures = [];

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

$assertSame('technician', n45LoginSurfaceForHost('psa.n45tech.com'), 'PSA hostname did not select technician login');
$assertSame('technician', n45LoginSurfaceForHost('PSA.N45TECH.COM:443'), 'PSA hostname normalization failed');
$assertSame('customer', n45LoginSurfaceForHost('portal.n45tech.com'), 'Portal hostname did not select customer login');
$assertSame('customer', n45LoginSurfaceForHost('PORTAL.N45TECH.COM:443'), 'Portal hostname normalization failed');
$assertSame('unified', n45LoginSurfaceForHost('localhost:8080'), 'Localhost did not preserve unified login');
$assertSame('unified', n45LoginSurfaceForHost('[::1]:8080'), 'IPv6 localhost did not preserve unified login');

$assertSame('user_type = 1', n45LoginUserFilter('technician'), 'Technician login accepts the wrong account type');
$assertSame('user_type = 2 AND client_archived_at IS NULL', n45LoginUserFilter('customer'), 'Customer login accepts the wrong account type');
$assertSame(
    '(user_type = 1 OR (user_type = 2 AND client_archived_at IS NULL))',
    n45LoginUserFilter('unified'),
    'Unified login account filter changed'
);

$assertSame(false, n45LocalLoginAllowed('technician'), 'Technician hostname still allows local authentication');
$assertSame(false, n45LocalLoginAllowed('customer'), 'Customer hostname still allows local authentication');
$assertSame(true, n45LocalLoginAllowed('unified'), 'Unified recovery login no longer allows local authentication');

$assertSame(
    'https://portal.n45tech.com/client/login_microsoft.php',
    n45ClientEntraRedirectUri('portal.n45tech.com', 'psa.n45tech.com'),
    'Portal OAuth callback does not stay on the portal hostname'
);
$assertSame(
    'https://psa.n45tech.com/client/login_microsoft.php',
    n45ClientEntraRedirectUri('localhost:8080', 'https://psa.n45tech.com/'),
    'Unified client OAuth callback did not use the configured base URL'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Login surface tests passed.\n";
