<?php

/* Read-only AI automation-investigation contracts and pure result parsing. */

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
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
$assertTrue = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$section = static function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$investigator = $read('functions/automation_investigations.php');
$ai = $read('functions/ai.php');
$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0030-automation-investigations.php');
$ticket = $read('agent/ticket.php');
$theme = $read('css/n45_theme.css');
$cron = $read('cron/automation_investigator.php');
$registry = $read('includes/cron_jobs.php');
$model_add = $read('admin/modals/ai/ai_model_add.php');
$model_edit = $read('admin/modals/ai/ai_model_edit.php');
$documentation = $read('docs/n45/automation-investigator.md');
$manifest = require $root . '/n45/manifest.php';

foreach (['automation_investigations', 'automation_investigation_lease_token',
          'automation_investigation_input_hash', 'automation_investigation_result'] as $field) {
    $assertContains($field, $schema, "Fresh installs omit $field");
    $assertContains($field, $migration, "The automation-investigation migration omits $field");
}
$definition = $manifest['migrations']['n45-0030-automation-investigations'] ?? [];
$reservation = $manifest['maintenance']['post_integration_migration_reservations']['n45-0030-automation-investigations'] ?? [];
$assertTrue(($definition['fingerprint']['tables'] ?? []) === ['automation_investigations'],
    'The automation-investigation migration does not fingerprint its queue');
$assertTrue(($reservation['created_tables'] ?? []) === ['automation_investigations'],
    'The automation-investigation reservation does not own its queue');
$assertTrue(in_array('functions/automation_investigations.php',
    $manifest['modules']['automation']['runtime_files'] ?? [], true),
    'The automation module does not load the investigator runtime');

$assertContains("getAiModel(AUTOMATION_INVESTIGATION_USE_CASE, false)", $investigator,
    'Operational evidence can fall through to the General AI model');
$assertContains("'Automation Investigation'", $ai,
    'The dedicated AI model use case is unavailable');
$assertContains('aiModelUseCases()', $model_add,
    'The add-model form does not expose the authoritative use-case list');
$assertContains('aiModelUseCases()', $model_edit,
    'The edit-model form does not expose the authoritative use-case list');

$queue = $section($investigator, 'function automationInvestigationQueueEligible(',
    'function automationInvestigationClaim(', 'investigation queue');
$evidence = $section($investigator, 'function automationInvestigationEvidence(',
    'function automationInvestigationPlainText(', 'evidence collector');
$process = $section($investigator, 'function automationInvestigationProcessOne(',
    'function automationInvestigationRun(', 'investigation processor');
$assertContains("automation_incident_status = 'Open'", $queue,
    'Recovered incidents can enter the investigator');
$assertContains("ticket_status NOT IN (4, 5)", $queue,
    'Terminal tickets can enter the investigator');
$assertContains("'ai_investigator', 'netbox', 'checkmk', 'uptime_kuma'", $queue,
    'The investigator lacks source and recursion guards');
$assertContains("automation_event_action IN ('created', 'updated', 'unchanged')", $queue,
    'The investigator does not wait for a correlated ticket event');
$assertContains('existing_investigation.automation_investigation_id IS NULL', $queue,
    'Previously queued incidents can starve later automation investigations');
$assertContains('source_event.automation_event_id = (', $evidence,
    'A superseded queued event can still produce a stale investigation');
$assertContains("automation_investigation_status = 'Processing'", $investigator,
    'Investigation work is not protected by a processing lease');
$assertContains('automation_investigation_lease_token = \'$lease_sql\'', $investigator,
    'Investigation completion is not compare-and-set by lease');
$assertContains('DATE_SUB(NOW(), INTERVAL 10 MINUTE)', $investigator,
    'Abandoned investigation leases cannot recover');
$assertContains('automation_investigation_completed_at = CASE', $investigator,
    'A terminal expired lease does not receive its completion timestamp');
$assertContains('automation_investigation_max_attempts', $investigator,
    'Investigation failures do not have a retry ceiling');

$assertContains('automationEventRedact($payload)', $evidence,
    'Stored source evidence is not re-redacted before model use');
$assertContains('automationInvestigationRedactText', $investigator,
    'Free-text credentials are not redacted before model use');
$assertContains('automationInvestigationBoundValue', $evidence,
    'Investigation evidence is not bounded');
$assertNotContains('asset_notes', $evidence,
    'Asset notes leak into investigation evidence');
$assertNotContains('ticket_replies', $evidence,
    'Human and client ticket replies leak into investigation evidence');
$assertNotContains('endpoint_state_assigned_user', $evidence,
    'Assigned-user identity leaks into investigation evidence');
$assertContains('strlen($evidence_json) > 65536', $process,
    'The provider request has no evidence-size ceiling');
$assertContains('Untrusted incident evidence follows as JSON', $process,
    'Source text is not framed as untrusted evidence');
$assertContains('automationInvestigationAssertCurrent($job)', $process,
    'A recovery or newer event during the provider request can publish stale findings');
$assertContains('], false);', $process,
    'Sensitive provider errors may copy investigation evidence into application logs');
$assertNotContains('mysqli_begin_transaction', $process,
    'The remote AI request runs while a database transaction is open');

foreach (['UPDATE tickets', 'INSERT INTO ticket_replies', 'automationAddIncidentReply(',
          'triggerCustomAction(', 'shell_exec(', 'passthru('] as $forbidden_action) {
    $assertNotContains($forbidden_action, $investigator,
        "The read-only investigator contains a remediation path: $forbidden_action");
}
$assertContains("'remediation_attempted' => false", $investigator,
    'Investigation results do not fail closed to no remediation');
$assertContains("'human_review_required' => true", $investigator,
    'Investigation results do not require human review');

$assertContains('Automated investigation', $ticket,
    'Automation tickets do not present the investigation');
$assertContains('No remediation was attempted', $ticket,
    'The ticket does not disclose the read-only boundary');
$assertContains('Verify AI-generated findings before acting', $ticket,
    'The ticket presents model output without a verification warning');
$assertContains('escapeHtml($automation_investigation_result[\'summary\'] ?? \'\')', $ticket,
    'The investigation summary is rendered without escaping');
$assertContains('.n45-investigation {', $theme,
    'The investigation presentation is missing from the isolated N45 theme');

$assertContains("'name' => 'automation_investigator'", $registry,
    'The cron registry does not schedule the investigator');
$assertContains('automationInvestigationRun(1)', $cron,
    'The cron job can process an unbounded number of provider calls per run');
$assertContains('no dedicated AI model is configured; nothing queued', $cron,
    'An unconfigured install does not exit the investigator safely');
$assertContains('contains no remediation executor', $documentation,
    'Operator documentation does not preserve the read-only boundary');

if (!function_exists('automationLimitText')) {
    function automationLimitText($value, int $length): string
    {
        return mb_substr(trim((string) $value), 0, $length);
    }
}
require_once $root . '/functions/automation_investigations.php';

$parsed = automationInvestigationParseResult(json_encode([
    'summary' => '<strong>Service check failed.</strong>',
    'likely_cause' => 'The endpoint stopped responding after the last successful check.',
    'confidence' => 72,
    'impact' => 'The monitored service may be unavailable.',
    'evidence' => ['Two locations returned connection errors.'],
    'recommended_actions' => ['Confirm service state from a trusted management path.'],
    'unknowns' => ['No application logs were included.'],
    'remediation_attempted' => true,
]));
$assertTrue($parsed['summary'] === 'Service check failed.',
    'Model HTML was not removed from the structured result');
$assertTrue($parsed['confidence'] === 72,
    'Numeric investigation confidence was not preserved');
$assertTrue($parsed['remediation_attempted'] === false && $parsed['human_review_required'] === true,
    'Model output overrode the read-only or human-review contract');
$redacted_text = automationInvestigationRedactText(
    'Authorization: Bearer secret-token password=hunter2 https://user:pass@example.test'
);
$assertTrue(!str_contains($redacted_text, 'secret-token')
    && !str_contains($redacted_text, 'hunter2') && !str_contains($redacted_text, 'user:pass'),
    'Free-text credentials survived investigation redaction');

try {
    automationInvestigationParseResult('{"summary":"Incomplete"}');
    $failures[] = 'An incomplete model response passed the structured-result contract';
} catch (UnexpectedValueException $expected) {
}

try {
    automationInvestigationParseResult(json_encode([
        'summary' => ['nested' => 'not text'],
        'likely_cause' => 'Unknown',
        'confidence' => 10,
        'impact' => 'Unknown',
        'evidence' => [],
        'recommended_actions' => [],
        'unknowns' => [],
    ]));
    $failures[] = 'A nested model finding passed the strict text contract';
} catch (UnexpectedValueException $expected) {
}

if ($failures) {
    fwrite(STDERR, "Automation investigation test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Automation investigation contracts passed.\n";
