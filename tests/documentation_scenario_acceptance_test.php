<?php

date_default_timezone_set('UTC');
require_once __DIR__ . '/../functions/documentation.php';

$failures = [];
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (RuntimeException $e) {
        // Expected fail-closed result.
    }
};

$now = '2026-09-02 12:00:00';
$current = documentationFreshnessProjection([
    'applicable' => true,
    'document_exists' => true,
    'last_verified_at' => '2026-08-15 12:00:00',
    'review_cadence_days' => 90,
    'warning_window_days' => 14,
    'verified_document_hash' => hash('sha256', 'current document'),
    'current_document_hash' => hash('sha256', 'current document'),
], $now);
$assertSame('Current', $current['base_status'], 'A verified in-cadence document is not current');
$assertSame('verified_current', $current['reason_code'], 'Current documentation has no explainable reason');

$stale = documentationFreshnessProjection([
    'applicable' => true,
    'document_exists' => true,
    'last_verified_at' => '2026-01-01 00:00:00',
    'review_cadence_days' => 90,
    'warning_window_days' => 14,
    'verified_document_hash' => hash('sha256', 'stale document'),
    'current_document_hash' => hash('sha256', 'stale document'),
], $now);
$assertSame('Stale', $stale['base_status'], 'An honestly overdue verification is not stale');
$assertSame('review_overdue', $stale['reason_code'], 'Stale documentation has no explainable reason');

$missing = documentationFreshnessProjection([
    'applicable' => true,
    'document_exists' => false,
    'review_cadence_days' => 90,
    'warning_window_days' => 14,
], $now);
$assertSame('Missing', $missing['base_status'], 'A required absent record was not classified missing');

$exception_obligation = [
    'documentation_obligation_applicable' => 1,
    'documentation_obligation_base_status' => 'Stale',
    'documentation_obligation_stale_at' => '2026-04-01 00:00:00',
    'documentation_obligation_exception_status' => 'Approved',
    'documentation_obligation_exception_expires_at' => '2026-09-10 00:00:00',
    'documentation_exception_record_valid' => 1,
];
$assertSame(
    'Exception',
    documentationObligationEffectiveStatus($exception_obligation, $now),
    'A valid, unexpired approved exception was not projected separately from stale'
);
$assertSame(
    'Stale',
    documentationObligationEffectiveStatus($exception_obligation, '2026-09-11 00:00:00'),
    'An expired exception did not restore the underlying stale state'
);

$ticket = [
    'ticket_client_id' => 10,
    'ticket_configuration_change' => 1,
    'ticket_documentation_impact' => 'Required',
];
$base_link = [
    'documentation_obligation_id' => 101,
    'documentation_obligation_requirement_id' => 201,
    'documentation_obligation_requirement_version_id' => 301,
    'documentation_requirement_version_requirement_id' => 201,
    'ticket_documentation_obligation_client_id' => 10,
    'documentation_obligation_client_id' => 10,
    'active_requirement_id' => 201,
    'active_requirement_lifecycle' => 'Active',
    'active_requirement_version_id' => 301,
    'documentation_task_record_valid' => 1,
    'documentation_obligation_exception_id' => 0,
    'documentation_exception_record_valid' => 1,
    'documentation_obligation_applicable' => 1,
    'ticket_documentation_obligation_blocks_resolution' => 1,
    'documentation_requirement_version_blocks_ticket_resolution' => 1,
    'documentation_requirement_version_name' => 'Network diagram',
    'documentation_requirement_version_warning_window_days' => 14,
    'has_active_waiver' => 0,
];

$missing_link = $base_link + [
    'documentation_obligation_base_status' => 'Missing',
];
[$can_close, $reason] = documentationTicketGateFromRows($ticket, [$missing_link], $now);
$assertSame(false, $can_close, 'Configuration-changing work closed with missing documentation');
$assertSame(true, str_contains($reason, 'Network diagram'), 'The closure gate did not name the blocking obligation');

$stale_link = array_merge($base_link, [
    'documentation_obligation_base_status' => 'Current',
    'documentation_obligation_stale_at' => '2026-04-01 00:00:00',
]);
$assertSame(
    false,
    documentationTicketGateFromRows($ticket, [$stale_link], $now)[0],
    'Configuration-changing work closed with stale documentation'
);

$current_link = array_merge($base_link, [
    'documentation_obligation_base_status' => 'Current',
    'documentation_obligation_stale_at' => '2026-12-01 00:00:00',
]);
$assertSame(
    true,
    documentationTicketGateFromRows($ticket, [$current_link], $now)[0],
    'Current affected documentation blocked a valid closure'
);

$exception_link = array_merge($stale_link, [
    'documentation_obligation_exception_id' => 401,
    'documentation_obligation_exception_status' => 'Approved',
    'documentation_obligation_exception_expires_at' => '2026-09-10 00:00:00',
]);
$assertSame(
    true,
    documentationTicketGateFromRows($ticket, [$exception_link], $now)[0],
    'A valid second-actor-approved exception did not satisfy the closure gate'
);
$assertSame(
    false,
    documentationTicketGateFromRows($ticket, [$exception_link], '2026-09-11 00:00:00')[0],
    'An expired exception continued to satisfy the closure gate'
);

$waived_link = array_merge($missing_link, ['has_active_waiver' => 1]);
$assertSame(
    true,
    documentationTicketGateFromRows($ticket, [$waived_link], $now)[0],
    'An active, version-pinned closure waiver did not satisfy the gate'
);

$assertSame(
    true,
    documentationAssertDistinctDecisionActor(501, 502, 'exception'),
    'A distinct exception approver was rejected'
);
$assertThrows(
    static fn () => documentationAssertDistinctDecisionActor(501, 501, 'exception'),
    'An exception requester approved their own request'
);
$assertThrows(
    static fn () => documentationAssertDistinctDecisionActor(701, 701, 'waiver'),
    'A closure-waiver requester decided their own request'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Goal 4 current, stale, missing, exception and closure-gate acceptance passed.\n";
