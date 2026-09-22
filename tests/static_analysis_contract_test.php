<?php

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/n45-static-analysis.yml');
$configuration = file_get_contents($root . '/phpstan.neon.dist');
$documentation = file_get_contents($root . '/docs/n45/static-analysis.md');
$failures = [];

$assertContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};

$assertContains('pull_request:', $workflow, 'Static analysis is not enforced on pull requests');
$assertContains('branches:', $workflow, 'Static analysis does not declare its integration branch');
$assertContains('- next', $workflow, 'Static analysis is not enforced before integration into next');
$assertContains('tools: phpstan:2.2.14', $workflow, 'The PHPStan toolchain is not version-pinned');
$assertContains('fail-fast: true', $workflow, 'A failed PHPStan installation can pass silently');
$assertContains('phpstan analyse --configuration=phpstan.neon.dist', $workflow,
    'CI bypasses the committed PHPStan configuration');

$assertContains('level: 5', $configuration, 'The governed core is not held at PHPStan level 5');
foreach ([
    'n45/bootstrap.php',
    'functions/login_surface.php',
    'functions/mail_templates.php',
    'functions/ui.php',
] as $path) {
    $assertContains('- ' . $path, $configuration, "PHPStan does not govern $path");
}
$assertNotContains('ignoreErrors:', $configuration, 'The initial PHPStan gate hides findings inline');
$assertNotContains('phpstan-baseline', $configuration, 'The clean initial PHPStan scope uses a baseline');
$assertContains('scanFiles:', $configuration, 'PHPStan cannot discover legacy shared helper symbols');
$assertContains('- functions/sanitize.php', $configuration,
    'PHPStan cannot resolve the UI escaping boundary');
$assertContains('P2-04 remains in progress', $documentation,
    'Static-analysis documentation overstates the initial governed scope');
$assertContains('it is not yet part of the analysed level-5 scope', $documentation,
    'Static-analysis documentation overstates symbol-discovery coverage');
$assertContains('do not regenerate an existing baseline', $documentation,
    'Static-analysis expansion lacks an anti-baseline-growth rule');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Static-analysis adoption contracts passed.\n";
