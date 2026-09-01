<?php

$root = dirname(__DIR__);
$registry = file_get_contents($root . '/includes/cron_jobs.php');
$job = file_get_contents($root . '/cron/documentation_evaluator.php');
$failures = [];

if ($registry === false || $job === false) {
    fwrite(STDERR, "Could not read documentation cron files\n");
    exit(1);
}

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertContains("'name' => 'documentation_evaluator'", $registry, 'Documentation evaluator is not registered');
$assertContains("'script' => 'documentation_evaluator.php'", $registry, 'Documentation evaluator registry points elsewhere');
$assertContains("'interval_minutes' => 60", $registry, 'Documentation freshness is not scheduled hourly');
$assertContains('config_enable_cron', $job, 'Documentation evaluator ignores the cron master switch');
$assertContains('documentationEvaluateDueClients(100)', $job, 'Scheduled work does not re-evaluate obligations');
$assertContains('documentationExpirePromises(100)', $job, 'Scheduled work does not expire Promise Ledger commitments');
$assertContains('documentationExpireTicketWaivers(100)', $job, 'Scheduled work does not expire ticket waivers');
$assertContains("require_once \"../functions.php\"", $job, 'Scheduled work does not load the shared documentation domain');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation cron wiring tests passed.\n";
