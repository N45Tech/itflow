<?php

$root = dirname(__DIR__);
$operations = file_get_contents($root . '/agent/operations.php');
$css = file_get_contents($root . '/css/itflow_custom.css');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains(
    'grid-template-columns: repeat(6, minmax(0, 1fr));',
    $css,
    'The six Operations KPI cards do not share one desktop row'
);
$assertContains(
    '.n45-health-list {',
    $css,
    'Integration health has no bounded list container'
);
$assertContains(
    'max-height: 18rem;',
    $css,
    'Integration health can grow without pushing down operational queues'
);
$assertContains(
    'overflow-y: auto;',
    $css,
    'Overflowing integration health sources are not scrollable'
);
$assertContains(
    'tabindex="0" aria-label="Integration health details; scroll for additional sources"',
    $operations,
    'The bounded integration list is not keyboard accessible'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Operations layout tests passed.\n";
