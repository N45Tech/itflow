<?php

require_once __DIR__ . '/../functions/documentation.php';

$failures = [];
$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$now = '2026-01-15 12:00:00';
$fresh = [
    'applicable' => true,
    'document_exists' => true,
    'last_verified_at' => '2026-01-01 12:00:00',
    'review_cadence_days' => 90,
    'warning_window_days' => 14,
    'verified_document_hash' => 'same',
    'current_document_hash' => 'same',
];
$assertSame('Current', documentationFreshnessProjection($fresh, $now)['base_status'], 'A fresh verification was not Current');
$due = $fresh;
$due['last_verified_at'] = '2025-10-25 12:00:00';
$assertSame('Due Soon', documentationFreshnessProjection($due, $now)['base_status'], 'The warning window did not derive Due Soon');
$stale = $fresh;
$stale['last_verified_at'] = '2025-10-01 12:00:00';
$assertSame('Stale', documentationFreshnessProjection($stale, $now)['base_status'], 'An overdue review was not Stale');
$changed = $fresh;
$changed['current_document_hash'] = 'changed';
$assertSame('Draft', documentationFreshnessProjection($changed, $now)['base_status'], 'A post-verification document edit did not invalidate verification');

$exception = [
    'applicable' => 1,
    'base_status' => 'Stale',
    'exception_status' => 'Approved',
    'exception_expires_at' => '2026-02-01 00:00:00',
];
$assertSame('Exception', documentationObligationEffectiveStatus($exception, $now), 'A valid approved exception was not effective');
$assertSame('Stale', $exception['base_status'], 'The effective exception rewrote its underlying base status');
$assertSame('Stale', documentationObligationEffectiveStatus($exception, '2026-02-02 00:00:00'), 'Exception expiry did not restore the underlying base status');

$rows = [
    ['obligation_id' => 1, 'applicable' => 1, 'blocks_readiness' => 1, 'base_status' => 'Current'],
    ['obligation_id' => 2, 'applicable' => 1, 'blocks_readiness' => 1, 'base_status' => 'Due Soon'],
    ['obligation_id' => 3, 'applicable' => 1, 'blocks_readiness' => 1, 'base_status' => 'Stale'],
    ['obligation_id' => 4, 'applicable' => 1, 'blocks_readiness' => 1, 'base_status' => 'Missing', 'exception_status' => 'Approved', 'exception_expires_at' => '2026-02-01'],
    ['obligation_id' => 5, 'applicable' => 0, 'blocks_readiness' => 1, 'base_status' => 'Not Applicable'],
    ['obligation_id' => 6, 'applicable' => 1, 'blocks_readiness' => 0, 'base_status' => 'Missing'],
];
$readiness = documentationReadinessReduce($rows, $now);
$assertSame(2, $readiness['numerator'], 'Readiness credit was not limited to Current and Due Soon');
$assertSame(4, $readiness['denominator'], 'Readiness denominator included excluded obligations or omitted Exception');
$assertSame(50, $readiness['score_percent'], 'The derived readiness percentage is wrong');
$assertSame(1, $readiness['counts']['Exception'], 'A valid exception was not explained in readiness counts');
$none = documentationReadinessReduce([['applicable' => 0, 'blocks_readiness' => 1, 'base_status' => 'Not Applicable']], $now);
$assertSame(null, $none['score_percent'], 'A zero denominator fabricated a 100 percent readiness score');
$assertSame(0, $none['numerator'], 'Zero-denominator readiness has a numerator');
$assertSame(0, $none['denominator'], 'Zero-denominator readiness has a denominator');

$valid_link = [
    'ticket_documentation_obligation_client_id' => 7,
    'documentation_obligation_client_id' => 7,
    'documentation_obligation_id' => 10,
    'documentation_obligation_requirement_id' => 20,
    'documentation_obligation_requirement_version_id' => 30,
    'documentation_requirement_version_requirement_id' => 20,
    'documentation_obligation_applicable' => 1,
    'documentation_obligation_base_status' => 'Current',
    'documentation_obligation_stale_at' => '2026-03-01 00:00:00',
    'documentation_requirement_version_warning_window_days' => 14,
    'ticket_documentation_obligation_blocks_resolution' => 1,
    'documentation_requirement_version_blocks_ticket_resolution' => 1,
    'documentation_requirement_version_name' => 'Identity Record',
    'active_requirement_id' => 20,
    'active_requirement_version_id' => 30,
    'active_requirement_lifecycle' => 'Active',
    'has_active_waiver' => 0,
];
$ticket = ['ticket_client_id' => 7, 'ticket_configuration_change' => 1, 'ticket_documentation_impact' => 'Required'];
$assertSame([true, ''], documentationTicketGateFromRows($ticket, [$valid_link], $now), 'A current valid affected obligation blocked resolution');
$missing = $valid_link;
$missing['documentation_obligation_base_status'] = 'Missing';
$assertSame(false, documentationTicketGateFromRows($ticket, [$missing], $now)[0], 'A missing affected obligation did not block resolution');
$missing['has_active_waiver'] = 1;
$assertSame(true, documentationTicketGateFromRows($ticket, [$missing], $now)[0], 'An active authorized waiver did not bypass its ticket gate');
$waiver = [
    'ticket_documentation_waiver_status' => 'Approved',
    'ticket_documentation_waiver_reason_hash' => hash('sha256', 'approved maintenance window'),
    'ticket_documentation_waiver_expires_at' => '2026-02-15 00:00:00',
];
$waiver['ticket_documentation_waiver_request_context_hash'] = documentationTicketWaiverRequestContextHash($waiver, 30);
$assertSame(true, documentationTicketWaiverIsActiveForObligation($waiver, $valid_link, $now), 'A current version-pinned waiver was not active');
$superseded_waiver_obligation = $valid_link;
$superseded_waiver_obligation['documentation_obligation_requirement_version_id'] = 31;
$assertSame(false, documentationTicketWaiverIsActiveForObligation($waiver, $superseded_waiver_obligation, $now), 'A waiver carried forward to a superseding requirement version');
$legacy_unpinned_waiver = $waiver;
$legacy_unpinned_waiver['ticket_documentation_waiver_request_context_hash'] = documentationAuditContextHash([
    'reason_hash' => $waiver['ticket_documentation_waiver_reason_hash'],
    'expires_at' => $waiver['ticket_documentation_waiver_expires_at'],
]);
$assertSame(false, documentationTicketWaiverIsActiveForObligation($legacy_unpinned_waiver, $valid_link, $now), 'A legacy unpinned waiver bypassed a documentation gate');
$tampered_waiver = $waiver;
$tampered_waiver['ticket_documentation_waiver_expires_at'] = '2026-02-20 00:00:00';
$assertSame(false, documentationTicketWaiverIsActiveForObligation($tampered_waiver, $valid_link, $now), 'A waiver with altered pinned fields bypassed a documentation gate');
$expired_waiver = $waiver;
$expired_waiver['ticket_documentation_waiver_expires_at'] = '2026-01-01 00:00:00';
$expired_waiver['ticket_documentation_waiver_request_context_hash'] = documentationTicketWaiverRequestContextHash($expired_waiver, 30);
$assertSame(false, documentationTicketWaiverIsActiveForObligation($expired_waiver, $valid_link, $now), 'An expired version-pinned waiver bypassed a documentation gate');
$cross_client = $valid_link;
$cross_client['documentation_obligation_client_id'] = 8;
$assertSame(false, documentationTicketGateFromRows($ticket, [$cross_client], $now)[0], 'A cross-client obligation link passed the ticket gate');
$not_applicable = $valid_link;
$not_applicable['documentation_obligation_applicable'] = 0;
$assertSame(false, documentationTicketGateFromRows($ticket, [$not_applicable], $now)[0], 'A Not Applicable link counted as affected');
$old_version = $valid_link;
$old_version['active_requirement_version_id'] = 31;
$assertSame(false, documentationTicketGateFromRows($ticket, [$old_version], $now)[0], 'A superseded requirement version passed the ticket gate');
$nonblocking = $valid_link;
$nonblocking['documentation_requirement_version_blocks_ticket_resolution'] = 0;
$assertSame(false, documentationTicketGateFromRows($ticket, [$nonblocking], $now)[0], 'A non-gating link satisfied the required affected-link count');
$stale_at_gate = $valid_link;
$stale_at_gate['documentation_obligation_stale_at'] = '2026-01-01 00:00:00';
$assertSame(false, documentationTicketGateFromRows($ticket, [$stale_at_gate], $now)[0], 'Gate-time freshness trusted a stale cron projection');
$assertSame([true, ''], documentationTicketGateFromRows([
    'ticket_client_id' => 7,
    'ticket_configuration_change' => 0,
    'ticket_documentation_impact' => 'None',
], [], $now), 'An assessed no-impact ticket did not pass');
$assertSame(false, documentationTicketGateFromRows([
    'ticket_client_id' => 7,
    'ticket_configuration_change' => 0,
    'ticket_documentation_impact' => 'Unassessed',
], [], $now)[0], 'An unassessed new ticket passed');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation readiness and ticket gate tests passed.\n";
