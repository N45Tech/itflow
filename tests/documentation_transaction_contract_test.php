<?php

$root = dirname(__DIR__);
$functions = file_get_contents($root . '/functions/documentation.php');
$loader = file_get_contents($root . '/functions.php');
$failures = [];

if ($functions === false || $loader === false) {
    fwrite(STDERR, "Could not read documentation transaction sources\n");
    exit(1);
}

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
$section = function (string $start, string $end) use ($functions, &$failures): string {
    $start_at = strpos($functions, $start);
    $end_at = $start_at === false ? false : strpos($functions, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false) {
        $failures[] = "Could not isolate $start";
        return '';
    }
    return substr($functions, $start_at, $end_at - $start_at);
};

$assertContains("n45RequireModule('documentation');", $loader, 'The documentation domain is not centrally loaded through the N45 boundary');
$assertContains('LIMIT 1 FOR UPDATE', $functions, 'Documentation mutations do not use row locks');
$assertContains('documentationLockClient(', $functions, 'Documentation mutations do not serialize on the client row');
$assertContains('documentation_obligation_revision = documentation_obligation_revision + 1', $functions, 'Obligation mutations do not advance a revision');
$assertContains('AND documentation_obligation_revision = $revision', $functions, 'Obligation writes do not use compare-and-swap');
$assertContains('documentation_obligation_event_context_hash', $functions, 'Obligation events can retain raw context');
$assertNotContains('UPDATE documentation_obligation_events', $functions, 'Append-only obligation events are mutable');
$assertNotContains('DELETE FROM documentation_obligation_events', $functions, 'Append-only obligation events can be deleted');
$assertNotContains('UPDATE documentation_promise_events', $functions, 'Append-only Promise Ledger history is mutable');
$assertNotContains('UPDATE ticket_documentation_waiver_events', $functions, 'Append-only waiver history is mutable');
$assertNotContains('UPDATE documentation_obligation_exception_events', $functions, 'Append-only exception history is mutable');
$assertNotContains('documentation_evidence_locator', $functions, 'Evidence Locker stores a recoverable locator');

$publish = $section('function documentationPublishRequirement(', 'function documentationSetRequirementLifecycle(');
$assertContains('documentation_requirement_version_definition_hash', $publish, 'Publishing does not deduplicate immutable definitions');
$assertContains('INSERT INTO documentation_requirement_versions', $publish, 'Publishing does not append a version');
$assertNotContains('UPDATE documentation_requirement_versions', $publish, 'Publishing rewrites an immutable version');

$verify = $section('function documentationVerifyObligation(', 'function documentationRequestObligationException(');
$client_lock = strpos($verify, 'documentationLoadObligationForMutation(');
$document_lock = strpos($verify, 'documentationDocumentState($document_id, $client_id, true)');
if ($client_lock === false || $document_lock === false || $client_lock >= $document_lock) {
    $failures[] = 'Verification does not lock client/obligation before revalidating the document FOR UPDATE';
}
$assertContains('documentation_obligation_verification_document_hash', $verify, 'Verification does not snapshot document content integrity');
$assertContains('documentationRecordEvidenceLocked(', $verify, 'Verification does not transactionally update the Evidence Locker');

$exception = $section('function documentationRequestObligationException(', 'function documentationLockTicket(');
$assertNotContains('documentation_obligation_base_status =', $exception, 'Exception state overwrites the underlying base status');
$assertContains('documentationRequireSupportLevel($actor_id, 3)', $exception, 'Exception decisions do not require support level 3');
$assertContains('documentationAssertDistinctDecisionActor(', $exception, 'Exception self-approval is possible');
$assertContains("'exception'", $exception, 'Exception decisions do not use the distinct-actor policy');
$assertContains('INSERT INTO documentation_obligation_exceptions', $exception, 'Exception requests overwrite the obligation projection without a durable record');
$assertContains('documentationRecordObligationExceptionEvent(', $exception, 'Exception decisions have no append-only domain history');

$graph = $section('function documentationLockTicketDocumentationGraph(', 'function documentationTicketCanResolve(');
$assertContains('ORDER BY documentation_requirement_id FOR UPDATE', $graph, 'Ticket gates do not lock active requirement pointers');
$assertContains('ORDER BY ticket_documentation_obligation_id FOR UPDATE', $graph, 'Ticket gates do not lock their link set');
$assertContains('ORDER BY obligation.documentation_obligation_id FOR UPDATE', $graph, 'Ticket gates do not lock affected obligations');
$assertContains('ORDER BY ticket_documentation_waiver_id FOR UPDATE', $graph, 'Ticket gates do not lock exact waiver decisions');
$assertContains('ORDER BY document_id FOR UPDATE', $graph, 'Ticket gates do not lock verified documents');
$assertContains('documentation_verification_context_valid', $graph, 'Ticket gates do not pin evidence to the active requirement version');
$assertContains('documentationTicketWaiverIsActiveForObligation(', $graph, 'Ticket gates do not validate the waiver requirement-version pin');

$gate = $section('function documentationTicketCanResolve(', 'function documentationRecordChangePassport(');
$assertContains('ticket_documentation_impact', $gate, 'Ticket gates do not require an assessment');
$assertContains('active_requirement_version_id', $gate, 'Ticket gates trust a superseded requirement projection');
$assertContains('ticket_documentation_obligation_client_id', $gate, 'Ticket gates do not validate client scope');
$assertContains("['Missing', 'Draft', 'Stale']", $gate, 'Ticket gates do not block unresolved documentation');
$assertContains('documentationObligationEffectiveStatus($link, $now)', $gate, 'Ticket gates do not calculate freshness at gate time');

$assertContains('documentation_change_passport_resolution_sequence', $functions, 'Change Passports cannot distinguish re-resolution');
$assertContains('INSERT INTO documentation_change_passport_obligations', $functions, 'Change Passports do not retain their obligation snapshots');
$assertContains('documentation_change_passport_obligation_exception_id', $functions, 'Change Passports omit the exact exception record');
$assertContains('documentation_change_passport_obligation_waiver_id', $functions, 'Change Passports omit the exact waiver record');
$assertContains('INSERT INTO documentation_promise_events', $functions, 'Promise Ledger changes have no append-only history');
$assertContains('documentationPromiseReasonCodes()', $functions, 'Promise Ledger commitments do not enforce structured reason codes');
$assertContains('A ticket documentation promise requires a linked obligation', $functions, 'Ticket promises can target an unlinked documentation obligation');
$assertContains('INSERT INTO ticket_documentation_waiver_events', $functions, 'Ticket waiver changes have no append-only history');
$assertContains('documentationRequireSupportLevel($actor_id, 3)', $functions, 'Waiver decisions do not require support level 3');
$assertContains('documentationAssertDistinctDecisionActor(', $functions, 'Ticket waiver self-approval is possible');
$assertContains("'waiver'", $functions, 'Ticket waivers do not use the distinct-actor policy');
$assertContains('This ticket documentation waiver requires an administrator decision', $functions, 'Administrator waiver policy is not enforced');
$assertContains('documentationTicketWaiverPinsObligationVersion(', $functions, 'Ticket waivers are not pinned to the requested requirement version');
$assertContains("'requirement_version_id' => \$requirement_version_id", $functions, 'Ticket waiver request history omits its requirement version pin');
$assertContains('function documentationEvidenceReferenceInUse(', $functions, 'Evidence-backed entities have no common deletion guard');
$assertContains('Unsupported documentation evidence reference type', $functions, 'Unknown evidence references do not fail closed');
$assertContains('function documentationInvalidateDocumentLocked(', $functions, 'Document edits cannot transactionally invalidate verification');
$assertContains('Ticket-scoped verification requires a linked documentation obligation', $functions, 'Verification tickets need not link the obligation');
$assertContains('ticket_documentation_obligation_task_id', $functions, 'Ticket documentation links omit task provenance');
$assertContains("'ticket_link_updated'", $functions, 'Blocking-flag changes do not append an obligation event');
$assertContains('documentationLockClientForExpiry(', $functions, 'Expiry workers cannot process archived-client ledgers');

foreach ([
    ['function documentationExpireObligationExceptions(', 'function documentationExpirePromises(', 'exception'],
    ['function documentationExpirePromises(', 'function documentationExpireTicketWaivers(', 'promise'],
    ['function documentationExpireTicketWaivers(', null, 'waiver'],
] as [$start, $end, $label]) {
    $start_at = strpos($functions, $start);
    $end_at = $start_at === false || $end === null ? false : strpos($functions, $end, $start_at);
    $expiry = $start_at === false ? '' : ($end_at === false
        ? substr($functions, $start_at)
        : substr($functions, $start_at, $end_at - $start_at));
    $assertContains('if (!mysqli_begin_transaction($mysqli))', $expiry, "The $label expiry worker ignores transaction-start failure");
    $assertContains('if (!mysqli_commit($mysqli))', $expiry, "The $label expiry worker ignores commit failure");
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation transaction contracts passed.\n";
