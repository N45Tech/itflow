<?php

// Guards the Operations layout regression observed after the production release.
$root = dirname(__DIR__);
$operations = file_get_contents($root . '/agent/operations.php');
$ticket = file_get_contents($root . '/agent/ticket.php');
$css = file_get_contents($root . '/css/itflow_custom.css');

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertContains(
    'grid-template-columns: repeat(5, minmax(0, 1fr));',
    $css,
    'The five Operations KPI cards do not share one desktop row'
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
$assertContains(
    '.n45-health-row > .n45-system-icon > i,',
    $css,
    'Integration health icons do not share the normalized system icon size'
);
$assertContains(
    'font-size: 1rem;',
    $css,
    'Integration health icons remain visually smaller than adjacent system icons'
);
$assertNotContains(
    'n45-source-filter',
    $operations,
    'Operations still renders source-specific service tabs'
);
$assertNotContains(
    '?source=',
    $operations,
    'Operations still links to source-specific drill-down views'
);
$assertNotContains(
    'operations.php?source=',
    $ticket,
    'Ticket detail still links to a source-specific Operations view'
);
$assertNotContains(
    "\$_GET['source']",
    $operations,
    'Operations still accepts a source-specific dashboard filter'
);
$assertNotContains(
    '.n45-source-filter',
    $css,
    'The removed Operations service-tab styles remain'
);
$assertContains(
    '$show_diagnostics = isset($_GET[\'view\']) && $_GET[\'view\'] === \'diagnostics\';',
    $operations,
    'Operations has no explicit overview-versus-diagnostics boundary'
);
$assertContains(
    '<?php if ($show_diagnostics) { ?>' . PHP_EOL . '    <section class="n45-panel" id="endpoint-coverage"',
    $operations,
    'Endpoint coverage still renders on the default Operations overview'
);
$assertContains(
    '<?php if ($show_diagnostics) { ?>' . PHP_EOL . '    <section class="n45-panel" id="identity-review"',
    $operations,
    'Identity review and mapping history still render on the default Operations overview'
);
$assertContains(
    "'Not configured'",
    $operations,
    'Integration health cannot represent a source that is not configured'
);
$assertContains(
    "'Awaiting signal'",
    $operations,
    'Integration health cannot represent a configured source awaiting its first signal'
);
$assertContains(
    "'Stale'",
    $operations,
    'Integration health cannot represent an overdue source signal'
);
$assertContains(
    "'Failed'",
    $operations,
    'Integration health cannot represent a failed source'
);
$assertContains(
    "'Healthy'",
    $operations,
    'Integration health cannot represent a healthy source'
);
$assertContains(
    "'Updated ' . escapeHtml(timeAgo(\$latest_operational_update))",
    $operations,
    'The Operations header does not disclose the age of its latest evidence'
);
$assertNotContains(
    'ready for first signal',
    $operations,
    'An unobserved integration is still optimistically described as ready'
);
$assertNotContains(
    "(\$last_seen ? 'Connected' : 'Ready')",
    $operations,
    'Integration health still treats historical evidence or no evidence as a connection check'
);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Operations layout tests passed.\n";
