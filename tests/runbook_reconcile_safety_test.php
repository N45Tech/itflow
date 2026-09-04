<?php

$failures = [];
$root = dirname(__DIR__);

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$reconcile = file_get_contents($root . '/deploy/psa/reconcile_templates.php');
$readme = file_get_contents($root . '/deploy/psa/README.md');
if ($reconcile === false || $readme === false) {
    fwrite(STDERR, "Could not read reconcile deployment files\n");
    exit(1);
}

$assertContains("\$allowed_modes = ['--dry-run', '--apply'];", $reconcile, 'Reconciliation does not declare explicit execution modes');
$assertContains('count($arguments) !== 1', $reconcile, 'Reconciliation does not reject missing or conflicting modes');
$assertContains('!in_array($arguments[0], $allowed_modes, true)', $reconcile, 'Reconciliation accepts unknown modes');
$assertContains("\$dry_run = \$arguments[0] === '--dry-run';", $reconcile, 'Dry-run mode is not selected from the validated argument');
$assertContains("GET_LOCK('", $reconcile, 'Concurrent reconciliation is not serialized');
$assertContains("RELEASE_LOCK('", $reconcile, 'The reconciliation advisory lock is not released');
$assertContains('$resolved_ticket_template_ids[$template[\'name\']] = $template_id;', $reconcile, 'Resolved ticket template identities are not retained');
$assertContains('$resolved_ticket_template_ids[$ticket_template_name]', $reconcile, 'Project stages re-resolve ticket templates ambiguously');
$assertContains('Duplicate $table name', $reconcile, 'Duplicate template names do not fail closed');
$assertContains('ticket_template_published_version_id', $reconcile, 'Project stage pinning does not read the authoritative pointer');
$assertContains('runbook_version_count', $reconcile, 'Missing published pointers are not distinguished from legacy templates');
$assertContains('$published_pointer !== $verified_version_id', $reconcile, 'Published version ownership is not verified');
$assertNotContains('runbookLatestPublishedVersionId(', $reconcile, 'Project stage pinning still guesses a historical version');
$assertNotContains('starterTicketTemplateId(', $reconcile, 'Project stages still use an ambiguous name lookup');
$assertContains('reconcile_templates.php --apply', $readme, 'Deployment instructions do not require explicit apply mode');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook reconcile safety checks passed." . PHP_EOL;
