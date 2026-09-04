<?php

$root = dirname(__DIR__);
$reconcile = file_get_contents($root . '/deploy/psa/reconcile_documentation_requirements.php');
$readme = file_get_contents($root . '/deploy/psa/README.md');
$failures = [];

if ($reconcile === false || $readme === false) {
    fwrite(STDERR, "Could not read documentation reconciliation files\n");
    exit(1);
}

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};
$assertOrdered = function (string $haystack, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $position = strpos($haystack, $needle, $position + 1);
        if ($position === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
    }
};

$assertContains("\$allowed_modes = ['--dry-run', '--apply'];", $reconcile, 'Reconciliation does not declare explicit modes');
$assertContains('count($arguments) !== 1', $reconcile, 'Reconciliation accepts missing or conflicting modes');
$assertContains('!in_array($arguments[0], $allowed_modes, true)', $reconcile, 'Reconciliation accepts an unknown mode');
$assertContains("\$dry_run = \$arguments[0] === '--dry-run';", $reconcile, 'Dry-run is not selected from the validated argument');
$assertContains("GET_LOCK('\$lock_name_sql', 0)", $reconcile, 'Concurrent requirement reconciliation is not serialized');
$assertContains("RELEASE_LOCK('\$lock_name_sql')", $reconcile, 'The reconciliation advisory lock is not released');
$assertContains('mysqli_begin_transaction($mysqli)', $reconcile, 'Reconciliation is not transactional');
$assertContains('mysqli_rollback($mysqli)', $reconcile, 'Dry-run does not execute and roll back');
$assertContains('mysqli_commit($mysqli)', $reconcile, 'Apply mode does not commit');
$assertContains('documentationBaselineRequirementCatalog()', $reconcile, 'Reconciliation does not use the canonical N45 catalog');
$assertContains('documentationSaveRequirementDraft(', $reconcile, 'Reconciliation bypasses versioned authoring');
$assertContains('documentationPublishRequirement(', $reconcile, 'Reconciliation does not publish immutable versions');
$assertContains('documentationEvaluateClient(', $reconcile, 'Reconciliation does not project client obligations');
$assertOrdered($reconcile, [
    'mysqli_begin_transaction($mysqli)',
    'ORDER BY client_id FOR UPDATE',
    'documentationBaselineRequirementCatalog()',
    'documentationEvaluateClient(',
], 'Reconciliation does not preserve the client-to-requirement lock order');
$assertContains('reconcile_documentation_requirements.php --dry-run', $readme, 'Deployment instructions omit the documentation dry run');
$assertContains('reconcile_documentation_requirements.php --apply', $readme, 'Deployment instructions omit explicit documentation apply mode');
$assertContains('the second pass must report no changed drafts', $readme, 'Deployment instructions omit the idempotent reconciliation canary');
foreach (['Current', 'Stale', 'Missing', 'Exception', 'Closure'] as $canary) {
    $assertContains("| $canary |", $readme, "Deployment instructions omit the $canary documentation canary");
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation reconciliation safety checks passed.\n";
