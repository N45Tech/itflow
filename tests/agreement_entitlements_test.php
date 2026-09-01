<?php

require_once __DIR__ . '/../n45/bootstrap.php';
n45RequireModule('agreements');
require_once __DIR__ . '/../functions/sla.php';

$original_timezone = date_default_timezone_get();
date_default_timezone_set('UTC');

$failures = [];
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = "$message (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$assertSame('new-user', agreementNormalizeRequestTypeKey(' New User! '), 'Request type keys must be stable slugs');
$assertSame('*', agreementNormalizeRequestTypeKey(''), 'An empty request type must mean the documented wildcard');

$rules = [
    ['rule_id' => 40, 'request_type_key' => '*', 'priority' => '*', 'sla_id' => 1, 'rule_order' => 0],
    ['rule_id' => 30, 'request_type_key' => '*', 'priority' => 'High', 'sla_id' => 2, 'rule_order' => 0],
    ['rule_id' => 20, 'request_type_key' => 'new-user', 'priority' => '*', 'sla_id' => 3, 'rule_order' => 0],
    ['rule_id' => 10, 'request_type_key' => 'new-user', 'priority' => 'High', 'sla_id' => 4, 'rule_order' => 99],
];
$selected = agreementSelectSlaRule($rules, 'New User', 'High');
$assertSame(4, intval($selected['sla_id'] ?? 0), 'Exact request + priority must win regardless of rule order');
$selected = agreementSelectSlaRule($rules, 'New User', 'Low');
$assertSame(3, intval($selected['sla_id'] ?? 0), 'Exact request + wildcard priority must beat the global default');
$selected = agreementSelectSlaRule($rules, 'Password Reset', 'High');
$assertSame(2, intval($selected['sla_id'] ?? 0), 'Wildcard request + exact priority must beat the global default');
$selected = agreementSelectSlaRule($rules, 'Password Reset', 'Low');
$assertSame(1, intval($selected['sla_id'] ?? 0), 'The global rule must provide a deterministic final fallback');
$selected = agreementSelectSlaRule([
    ['rule_id' => 7, 'request_type_key' => '*', 'priority' => '*', 'sla_id' => 7, 'rule_order' => 20],
    ['rule_id' => 8, 'request_type_key' => '*', 'priority' => '*', 'sla_id' => 8, 'rule_order' => 10],
], 'Anything', 'Low');
$assertSame(8, intval($selected['sla_id'] ?? 0), 'Rule order must deterministically break equal-specificity ties');
$assertSame(null, agreementSelectSlaRule([], 'Anything', 'Low'), 'An empty rule set must not invent an SLA');

$expected_behaviors = [
    'included' => [1, 0, 0],
    'excluded' => [0, 0, 1],
    'onsite' => [1, 1, 0],
    'after_hours' => [1, 0, 1],
    'billable' => [1, 0, 1],
];
foreach ($expected_behaviors as $classification => [$eligible, $onsite, $billable]) {
    $behavior = agreementClassificationBehavior($classification);
    $assertSame($eligible, $behavior['sla_eligible'], "$classification must have deterministic SLA eligibility");
    $assertSame($onsite, $behavior['ticket_onsite'], "$classification must deterministically stamp onsite");
    $assertSame($billable, $behavior['ticket_billable'], "$classification must deterministically stamp billable");
}

$entitlement_context = [
    'users' => ['ids' => [11], 'keys' => [], 'population' => 12],
    'devices' => ['ids' => [21, 22], 'keys' => [], 'population' => 20],
    'hours' => ['ids' => [], 'keys' => ['all-hours', 'after-hours'], 'population' => 1],
];
$entitlement_resolution = agreementResolveEntitlementApplicability([
    ['entitlement_id' => 1, 'scope_type' => 'users', 'scope_id' => 0, 'scope_key' => '*', 'quantity_limit' => 10, 'classification' => 'included'],
    ['entitlement_id' => 2, 'scope_type' => 'devices', 'scope_id' => 0, 'scope_key' => '*', 'quantity_limit' => null, 'classification' => 'included'],
    ['entitlement_id' => 3, 'scope_type' => 'devices', 'scope_id' => 21, 'scope_key' => '*', 'quantity_limit' => null, 'classification' => 'excluded'],
    ['entitlement_id' => 4, 'scope_type' => 'hours', 'scope_id' => 0, 'scope_key' => 'after-hours', 'quantity_limit' => null, 'classification' => 'after_hours'],
], $entitlement_context, 'included');
$assertSame('excluded', $entitlement_resolution['resolved_classification'], 'An exact excluded device must override a wildcard inclusion and all less-strict classes');
$assertSame('exact-devices-record', $entitlement_resolution['scopes']['devices']['basis'], 'Exact record clauses must be traceable in decision evidence');
$assertSame('billable', $entitlement_resolution['scopes']['users']['classification'], 'A broad population over its quantity limit must become billable');
$fail_closed = agreementResolveEntitlementApplicability([
    ['entitlement_id' => 5, 'scope_type' => 'locations', 'scope_id' => 77, 'scope_key' => '*', 'quantity_limit' => null, 'classification' => 'included'],
], ['locations' => ['ids' => [88], 'keys' => [], 'population' => 2]], 'included');
$assertSame('excluded', $fail_closed['resolved_classification'], 'A configured scope with no applicable clause must fail closed');

$definition_a = ['name' => 'MSA', 'version' => 2, 'term' => ['end' => null, 'start' => '2026-01-01']];
$definition_b = ['term' => ['start' => '2026-01-01', 'end' => null], 'version' => 2, 'name' => 'MSA'];
$assertSame(
    agreementDefinitionHash($definition_a),
    agreementDefinitionHash($definition_b),
    'Definition hashes must not depend on associative key insertion order'
);
$definition_b['version'] = 3;
$assertTrue(
    agreementDefinitionHash($definition_a) !== agreementDefinitionHash($definition_b),
    'Material definition changes must alter the immutable hash'
);

$calendar = slaNormalizeCalendarSnapshot([
    'calendar_mode' => 'business_hours',
    'business_days' => '1,2,3,4,5',
    'business_hours_start' => '08:00:00',
    'business_hours_end' => '17:00:00',
    'timezone' => 'UTC',
]);
$assertSame(
    '2026-01-05 09:00:00',
    addBusinessMinutes('2026-01-02 16:00:00', 120, $calendar),
    'A snapshotted business calendar must carry an SLA target across the weekend deterministically'
);
$new_york_calendar = $calendar;
$new_york_calendar['timezone'] = 'America/New_York';
$assertSame(
    '2026-01-05 09:00:00',
    addBusinessMinutes('2026-01-02 16:00:00', 120, $new_york_calendar),
    'Business windows must be evaluated in the snapshotted timezone, independent of the app timezone'
);
$cross_timezone_due = slaAddBusinessMinutesFromAppTimestamp(
    '2026-01-02 21:00:00',
    120,
    $new_york_calendar
);
$assertSame('2026-01-05 14:00:00', $cross_timezone_due['utc'], 'Application timestamps must be converted to the rule timezone before SLA math');
$dst_due = slaAddBusinessMinutesFromAppTimestamp(
    '2026-03-06 21:00:00',
    120,
    $new_york_calendar
);
$assertSame('2026-03-09 13:00:00', $dst_due['utc'], 'Canonical UTC deadlines must preserve the snapshotted timezone across DST');
$assertSame(
    120,
    slaBusinessMinutesBetweenAppTimestamps('2026-03-06 21:00:00', '2026-03-09 13:00:00', $new_york_calendar),
    'Consumed SLA time must use the same app-to-calendar conversion as deadline creation'
);
$assertSame('2026-02-28', agreementShiftCalendarMonths('2026-01-31', 1), 'Calendar-month schedules must clamp end-of-month anchors');
$assertSame('2026-03-31', agreementShiftCalendarMonths('2026-02-28', 1), 'Clamped end-of-month schedules must recover the next month end instead of drifting');
$assertSame('2025-10-31', agreementShiftCalendarMonths('2026-01-31', -3), 'Calendar-month schedules must remain stable when moving backward');
$assertSame(
    null,
    slaTicketTargetMinutes([
        'ticket_sla_calendar_mode' => 'business_hours',
        'ticket_sla_resolution_minutes_snapshot' => null,
        'sla_resolution_minutes' => 999,
    ], 'resolution'),
    'A snapshotted response-only plan must not inherit a later live resolution target'
);
$rule_definition = [
    'sla_id' => 4,
    'sla_name' => 'Priority response',
    'response_minutes' => 60,
    'resolution_minutes' => 480,
] + $calendar;
$changed_rule_definition = $rule_definition;
$changed_rule_definition['response_minutes'] = 61;
$assertTrue(
    agreementDefinitionHash($rule_definition) !== agreementDefinitionHash($changed_rule_definition),
    'Published agreement hashes must include immutable SLA target minutes'
);
$changed_rule_definition = $rule_definition;
$changed_rule_definition['business_hours_end'] = '18:00:00';
$assertTrue(
    agreementDefinitionHash($rule_definition) !== agreementDefinitionHash($changed_rule_definition),
    'Published agreement hashes must include immutable calendar semantics'
);
$assertSame(
    ['classification' => 'after_hours', 'classification_basis' => 'explicit_rule'],
    agreementRuleClassification(['classification' => 'after_hours', 'classification_basis' => 'explicit_rule']),
    'After-hours classification must be an explicit immutable rule, never inferred from free text'
);
try {
    agreementRuleClassification(['classification' => 'after_hours', 'classification_basis' => 'time_inferred']);
    $assertTrue(false, 'Unsupported time-inferred classification semantics must be rejected');
} catch (RuntimeException $e) {
    $assertContains('unsupported', strtolower($e->getMessage()), 'Classification rejection must be explainable');
}

$historical_version = [
    'agreement_version_status' => 'Superseded',
    'agreement_version_published_at' => '2026-01-01 09:00:00',
    'agreement_version_superseded_at' => '2026-04-01 10:00:00',
    'agreement_version_effective_from' => '2026-01-01',
    'agreement_version_effective_until' => '2026-12-31',
];
$assertTrue(
    agreementVersionAppliesAt($historical_version, '2026-03-31', '2026-03-31 23:59:59'),
    'A superseded version must remain resolvable inside its historical publication interval'
);
$assertTrue(
    !agreementVersionAppliesAt($historical_version, '2026-04-01', '2026-04-01 10:00:00'),
    'The supersession boundary must select the replacement version without overlap'
);
$inconsistent_version = $historical_version;
$inconsistent_version['agreement_version_superseded_at'] = null;
$assertTrue(
    !agreementVersionAppliesAt($inconsistent_version, '2026-03-31', '2026-03-31 23:59:59'),
    'A superseded version without an explicit supersession boundary must fail closed'
);

$snapshot = [
    'tickets' => [
        'response_compliance_percent' => 91.0,
        'resolution_compliance_percent' => 85.0,
        'recurring_issue_groups' => 2,
    ],
    'coverage' => ['endpoint_coverage_percent' => 95.0, 'security_coverage_percent' => 90.0],
    'backup' => ['open_incidents' => 1],
    'documentation' => ['available' => false],
    'renewals' => ['within_notice_window' => 2],
];
$recommendations = agreementBuildRecommendations($snapshot);
$assertSame(8, count($recommendations), 'Each disclosed risk input must produce a deterministic recommendation');
$snapshot['backup'] = ['in_scope' => true, 'available' => false, 'open_incidents' => 0];
$recommendations = agreementBuildRecommendations($snapshot);
$assertTrue(in_array('Connect or validate backup-health signals for the backup services recorded in scope.', $recommendations, true), 'Missing backup signals must be disclosed when backup services are in scope');

$review_snapshot = json_encode([
        'schema_version' => 1,
        'client' => ['id' => 9, 'name' => 'Example | Client'],
        'period' => ['start' => '2026-01-01', 'end' => '2026-03-31'],
        'agreement' => [
            'contract_id' => 12,
            'version_id' => 15,
            'name' => 'Managed Services',
            'version_number' => 3,
            'definition_hash' => str_repeat('a', 64),
        ],
        'summary' => 'Stable review',
        'tickets' => [
            'total' => 4, 'resolved' => 3, 'open' => 1, 'recurring' => 1,
            'response_met' => 4, 'response_missed' => 0,
            'response_compliance_percent' => 100,
            'resolution_met' => 3, 'resolution_missed' => 1,
            'resolution_compliance_percent' => 75,
            'recurring_issue_groups' => 1,
            'recurring_issues' => [['subject' => 'Repeated issue', 'occurrences' => 2]],
        ],
        'coverage' => [
            'available' => true, 'active_devices' => 4,
            'endpoint_managed_devices' => 4, 'endpoint_coverage_percent' => 100,
            'security_mapped_devices' => 3, 'security_coverage_percent' => 75,
            'source' => 'aggregate endpoint evidence',
        ],
        'backup' => [
            'available' => true, 'in_scope' => true, 'services_in_scope' => 1,
            'incidents' => 1, 'open_incidents' => 0, 'repeat_events' => 0,
            'last_signal_at' => '2026-03-30 12:00:00',
            'source' => 'source-neutral automation incidents',
        ],
        'documentation' => [
            'available' => false, 'document_count' => 3, 'recently_updated' => 2,
            'latest_at' => '2026-03-15 12:00:00', 'source' => 'basic-document-inventory',
            'note' => 'Readiness provider unavailable.',
        ],
        'renewals' => [
            'next_365_days' => 1, 'within_notice_window' => 1,
            'items' => [[
                'type' => 'agreement', 'name' => 'Managed Services',
                'date' => '2026-04-30', 'notice_days' => 90,
                'within_notice_window' => true,
            ]],
        ],
        'recommendations' => ['Review <unsafe> [text] with *care*'],
    ], JSON_THROW_ON_ERROR);
$review = [
    'service_review_id' => 77,
    'service_review_client_id' => 9,
    'service_review_contract_id' => 12,
    'service_review_agreement_version_id' => 15,
    'service_review_period_start' => '2026-01-01',
    'service_review_period_end' => '2026-03-31',
    'service_review_status' => 'Draft',
    'service_review_published_by' => 0,
    'service_review_published_at' => null,
    'service_review_summary' => 'Stable review',
    'service_review_recommendations' => 'Review <unsafe> [text] with *care*',
    'service_review_snapshot_hash' => hash('sha256', $review_snapshot),
    'service_review_source_snapshot' => $review_snapshot,
];
$review['_service_review_events'] = [[
    'service_review_event_review_id' => 77,
    'service_review_event_client_id' => 9,
    'service_review_event_action' => 'Generated',
    'service_review_event_actor_id' => 0,
    'service_review_event_reason' => 'Generated from a consistent source snapshot',
    'service_review_event_snapshot_hash' => $review['service_review_snapshot_hash'],
    'service_review_event_created_at' => '2026-04-01 00:05:00',
]];
$markdown = agreementServiceReviewMarkdown($review);
$assertContains('# Service Review — Example \\| Client', $markdown, 'Markdown export must escape table/control punctuation');
$assertContains('Review &lt;unsafe&gt; \\[text\\] with \\*care\\*', $markdown, 'Markdown export must neutralize raw HTML and formatting controls');
$assertContains($review['service_review_snapshot_hash'], $markdown, 'Markdown export must carry the source snapshot hash');
$assertContains('Stable review', $markdown, 'Markdown export must read its summary from the hashed snapshot');
$assertContains('Agreement: Managed Services — version 3', $markdown, 'Markdown export must identify the pinned agreement version');
$assertContains('Agreement definition SHA-256: `' . str_repeat('a', 64) . '`', $markdown, 'Markdown export must carry the pinned agreement definition hash');
$binding_tamper = $review;
$binding_tamper['service_review_client_id'] = 10;
try {
    agreementValidateServiceReviewSnapshot($binding_tamper);
    $assertTrue(false, 'Service-review snapshots must reject a client-row binding change even when snapshot bytes are intact');
} catch (RuntimeException $e) {
    $assertContains('bindings', $e->getMessage(), 'Review binding rejection must remain explainable');
}
$tampered_review = $review;
$tampered_review['service_review_summary'] = 'Changed after generation';
try {
    agreementServiceReviewMarkdown($tampered_review);
    $assertTrue(false, 'Markdown export must reject presentation fields that diverge from the hashed snapshot');
} catch (RuntimeException $e) {
    $assertContains('presentation fields', $e->getMessage(), 'Tampered review rejection must remain explainable');
}

$schema = file_get_contents(__DIR__ . '/../db.sql');
$migration_path = __DIR__ . '/../n45/migrations/n45-0014-agreement-entitlements.php';
$migration = file_get_contents($migration_path);
$required_tables = [
    'agreement_versions', 'agreement_entitlements', 'agreement_sla_rules',
    'agreement_version_events', 'ticket_agreement_decisions', 'service_reviews',
    'service_review_events',
];
foreach ($required_tables as $table) {
    $assertContains("CREATE TABLE `$table`", $schema, "$table must exist in the baseline schema");
    $assertContains("CREATE TABLE IF NOT EXISTS `$table`", $migration, "$table must exist in n45-0014");

    $table_pattern = '/CREATE TABLE(?: IF NOT EXISTS)? `' . preg_quote($table, '/') . '` \((.*?)\) ENGINE=InnoDB/s';
    preg_match($table_pattern, $schema, $schema_match);
    preg_match($table_pattern, $migration, $migration_match);
    $normalize_ddl = static fn (string $ddl): string => preg_replace('/\s+/', ' ', trim($ddl));
    $assertSame(
        $normalize_ddl($schema_match[1] ?? ''),
        $normalize_ddl($migration_match[1] ?? ''),
        "$table baseline and migration definitions must remain identical"
    );
}
$assertContains('KEY `ticket_agreement_decision_hash`', $schema, 'Repeated SLA applications must remain chronological append-only decisions');
$assertTrue(!str_contains($schema, 'ticket_agreement_decision_once'), 'Ticket decision hashes must not suppress later re-applications');
$assertContains("defined('FROM_N45_DB_UPDATER')", $migration, 'n45-0014 must be guarded by the N45 migration runner');
$assertTrue(!str_contains($migration, 'config_current_database_version'), 'n45-0014 must rely on stable manifest order instead of the numeric database marker');
$assertTrue(!is_file(__DIR__ . '/../admin/database_updates/2.8.1.php'), 'The reserved 2.8.1 migration must not remain in the upstream namespace');
$assertContains('_agreement_supersession_boundaries', $migration, 'Interrupted 2.8.1 installs must reconstruct historical agreement boundaries from replacement publications');
$assertContains('Legacy superseded agreement versions have no provable replacement publication boundary', $migration, 'Unprovable legacy lifecycle history must fail migration closed');
$assertContains('SET service_review_event_created_at = service_review_published_at', $migration, 'Legacy review approval events must bind to their authoritative published timestamp');
$manifest_source = file_get_contents(__DIR__ . '/../n45/manifest.php');
$assertContains('NOT EXISTS (SELECT 1 FROM service_review_events', $manifest_source, 'The N45 fingerprint must reject a published review without exact approval evidence');
$assertContains("service_review_event_action = 'Published' AND (service_review_id IS NULL", $manifest_source, 'The N45 fingerprint must reject malformed or mismatched publication events');
$assertContains('agreement_sla_rule_response_minutes', $schema, 'Agreement rules must snapshot response targets');
$assertContains('agreement_sla_rule_calendar_mode', $schema, 'Agreement rules must snapshot calendar semantics');
$assertContains('agreement_version_superseded_at', $schema, 'Historical agreement resolution requires an explicit supersession boundary');
$assertContains('ticket_sla_response_minutes_snapshot', $schema, 'Ticket clocks must retain immutable response targets');
$assertContains('ticket_request_type_key', $schema, 'Tickets must retain the semantic request key used for SLA selection');
$assertContains('ticket_response_due_at_utc', $schema, 'Tickets must retain canonical UTC response deadlines');
$assertContains('ticket_resolution_due_at_utc', $schema, 'Tickets must retain canonical UTC resolution deadlines');
$assertContains('ticket_agreement_decision_entitlement_snapshot', $schema, 'Ticket decisions must retain immutable entitlement evidence');
$assertContains('ticket_agreement_decision_schema_version', $schema, 'Ticket decision hashes must be explicitly versioned');
$assertContains('agreement_sla_rule_behavior_version', $schema, 'Agreement rules must snapshot their operational behavior matrix');

$decision = [
    'client_id' => 9,
    'contract_id' => 12,
    'version_id' => 15,
    'rule_id' => 18,
    'request_type_key' => 'new-user',
    'priority' => 'High',
    'sla_snapshot' => $rule_definition,
    'classification' => 'included',
    'classification_basis' => 'explicit_rule_and_entitlements',
    'behavior_version' => 1,
    'sla_eligible' => 1,
    'ticket_onsite' => 0,
    'ticket_billable' => 0,
    'entitlement_snapshot' => [
        'schema_version' => 1,
        'applicable' => true,
        'resolution' => ['resolved_classification' => 'included'],
    ],
    'source' => 'agreement_rule',
    'reason' => 'Deterministic test decision',
];
$assertTrue(
    agreementDefinitionHash(agreementTicketDecisionRecord(42, $decision))
        !== agreementDefinitionHash(agreementTicketDecisionRecord(43, $decision)),
    'Ticket decision hashes must bind the same commercial decision to its exact ticket ID'
);
$decision_record = agreementTicketDecisionRecord(42, $decision);
$decision_row = [
    'ticket_agreement_decision_schema_version' => 1,
    'ticket_agreement_decision_ticket_id' => $decision_record['ticket_id'],
    'ticket_agreement_decision_client_id' => $decision_record['client_id'],
    'ticket_agreement_decision_contract_id' => $decision_record['contract_id'],
    'ticket_agreement_decision_version_id' => $decision_record['version_id'],
    'ticket_agreement_decision_rule_id' => $decision_record['rule_id'],
    'ticket_agreement_decision_request_type_key' => $decision_record['request_type_key'],
    'ticket_agreement_decision_priority' => $decision_record['priority'],
    'ticket_agreement_decision_sla_id' => $decision_record['sla_id'],
    'ticket_agreement_decision_sla_name' => $decision_record['sla_name'],
    'ticket_agreement_decision_response_minutes' => $decision_record['response_minutes'],
    'ticket_agreement_decision_resolution_minutes' => $decision_record['resolution_minutes'],
    'ticket_agreement_decision_calendar_mode' => $decision_record['calendar_mode'],
    'ticket_agreement_decision_business_days' => $decision_record['business_days'],
    'ticket_agreement_decision_business_hours_start' => $decision_record['business_hours_start'],
    'ticket_agreement_decision_business_hours_end' => $decision_record['business_hours_end'],
    'ticket_agreement_decision_timezone' => $decision_record['timezone'],
    'ticket_agreement_decision_classification' => $decision_record['classification'],
    'ticket_agreement_decision_classification_basis' => $decision_record['classification_basis'],
    'ticket_agreement_decision_behavior_version' => $decision_record['behavior_version'],
    'ticket_agreement_decision_sla_eligible' => $decision_record['sla_eligible'],
    'ticket_agreement_decision_ticket_onsite' => $decision_record['ticket_onsite'],
    'ticket_agreement_decision_ticket_billable' => $decision_record['ticket_billable'],
    'ticket_agreement_decision_entitlement_snapshot' => agreementCanonicalJson($decision_record['entitlement_snapshot']),
    'ticket_agreement_decision_source' => $decision_record['source'],
    'ticket_agreement_decision_reason' => $decision_record['reason'],
    'ticket_agreement_decision_hash' => agreementDefinitionHash($decision_record),
];
$assertTrue(agreementVerifyTicketDecision($decision_row), 'An intact ticket-bound decision must verify');
$decision_row['ticket_agreement_decision_ticket_id'] = 43;
$assertTrue(!agreementVerifyTicketDecision($decision_row), 'Moving a decision to another ticket must fail verification');
$legacy_record = agreementTicketDecisionBaseRecord(42, $decision);
$legacy_row = $decision_row;
$legacy_row['ticket_agreement_decision_schema_version'] = 0;
$legacy_row['ticket_agreement_decision_hash'] = agreementDefinitionHash($legacy_record);
$legacy_row['ticket_agreement_decision_ticket_id'] = 42;
$assertTrue(agreementVerifyTicketDecision($legacy_row), 'Pre-release schema-0 decisions must retain their original ticket-bound hash compatibility');

$functions_loader = file_get_contents(__DIR__ . '/../functions.php');
$sla = file_get_contents(__DIR__ . '/../functions/sla.php');
$handler = file_get_contents(__DIR__ . '/../agent/post/agreement.php');
$agreement_helpers = file_get_contents(__DIR__ . '/../functions/agreements.php');
$ticket_page = file_get_contents(__DIR__ . '/../agent/ticket.php');
$ticket_handler = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$recurring_ticket_handler = file_get_contents(__DIR__ . '/../agent/post/recurring_ticket.php');
$nightly_tasks = file_get_contents(__DIR__ . '/../cron/nightly_tasks.php');
$sla_cron = file_get_contents(__DIR__ . '/../cron/ticket_sla.php');
$cron_registry = file_get_contents(__DIR__ . '/../includes/cron_jobs.php');
$assertContains("n45RequireModule('agreements');", $functions_loader, 'Agreement helpers must load through the N45 module boundary in web and cron processes');
$assertContains('agreementResolveRequestTypeKey($row)', $sla, 'Ticket SLA application must use the Goal 7-compatible request-type seam');
$assertContains('catch (Throwable $e)', $agreement_helpers, 'An optional request-catalog adapter failure must fall back without breaking ticket creation');
$assertContains('SELECT tickets.ticket_id, ticket_client_id', $sla, 'Goal 7 request keys require the immutable submission link to resolve by ticket ID');
$assertContains("'source' => \$manual ? 'manual_override' : 'forced_restamp'", $sla, 'A technician SLA override must replace stale selection rationale with a traceable decision');
$assertContains('agreementRecordTicketDecision($ticket_id', $sla, 'Ticket SLA selection must leave an append-only explanation');
$assertContains('bool $caller_transaction = false', $sla, 'Ticket creation transactions must be able to own the atomic SLA decision boundary');
$assertContains('LIMIT 1 FOR UPDATE', $sla, 'Ticket SLA selection must lock its ticket/client inputs');
$assertContains('agreementResolveTicketSlaDecision(', $sla, 'Agreement selection must share the ticket SLA transaction');
$assertContains('getTicketSlaConsumedMinutes($ticket_id, $calendar, true)', $sla, 'Clock reconciliation failures must abort an atomic SLA decision');
$assertContains('slaTicketTargetMinutes($row, \'resolution\')', $sla, 'A response-only snapshot must never fall back to mutable live resolution targets');
$assertContains("slaTicketTargetMinutes(\$ticket, 'response')", $sla_cron, 'SLA warnings must use immutable ticket response targets');
$assertContains('getTicketSlaConsumedMinutes($ticket_id, $calendar)', $sla_cron, 'SLA warnings must use the immutable ticket calendar');
$assertContains('ticket_sla_calendar_mode', $sla, 'Ticket stamping must persist the selected immutable calendar');
$assertContains('slaAddBusinessMinutesFromAppTimestamp(', $sla, 'Ticket SLA deadlines must convert app timestamps through the immutable rule timezone');
$assertContains('ticket_response_due_at_utc = $response_due_utc_sql', $sla, 'Ticket stamping must persist a canonical UTC response deadline');
$assertContains("slaTicketDueEpoch(\$ticket, 'response')", $sla_cron, 'SLA breach checks must prefer canonical UTC deadlines');
$assertContains('The forced SLA is archived or unavailable', $sla, 'Forced SLA selection must reject archived or missing targets');
$assertContains('INSERT INTO ticket_agreement_decisions', $agreement_helpers, 'Every SLA application must append a chronological decision');
$assertContains('agreementAssertVersionIntegrity($version)', $agreement_helpers, 'SLA and review resolution must verify immutable published definitions');
$assertContains("agreement_version_status = 'Superseded' AND agreement_version_superseded_at IS NOT NULL", $agreement_helpers, 'Historical reports must resolve explicit superseded lifecycle intervals');
$assertContains('$lock = $for_update ? \' FOR UPDATE\' : \'\';', $agreement_helpers, 'Agreement selection must lock the exact published version used by the ticket decision');
$assertContains('agreementVerifyTicketDecision($ticket_agreement_decision)', $ticket_page, 'A ticket decision must verify before it is displayed');
$assertContains('ticket_agreement_decision_client_id = $client_id', $ticket_page, 'Ticket decision display must remain explicitly tenant scoped');
$assertContains("ticket_agreement_decision_entitlement_snapshot", $ticket_page, 'Ticket history must expose its verified entitlement-selection evidence');
$assertContains('applyTicketSla($ticket_id, null, null, true)', $ticket_handler, 'Ticket creation must join SLA selection to the caller-owned transaction');
$assertContains('Could not link an additional ticket asset', $ticket_handler, 'Ticket creation must link additional devices before entitlement/SLA selection');
$recurring_copy = 'INSERT INTO ticket_assets (ticket_id, asset_id)';
foreach ([$recurring_ticket_handler, $nightly_tasks] as $recurring_creation_path) {
    $copy_position = strpos($recurring_creation_path, $recurring_copy);
    $sla_position = strpos($recurring_creation_path, 'applyTicketSla($id, null, null, true)', $copy_position ?: 0);
    $assertTrue($copy_position !== false && $sla_position !== false && $copy_position < $sla_position,
        'Recurring creation must link additional devices before entitlement/SLA selection');
    $assertContains('AND asset_client_id = $client_id AND asset_archived_at IS NULL',
        $recurring_creation_path,
        'Recurring creation must restrict copied devices to active records owned by the ticket client');
}
$assertSame(2, substr_count($recurring_ticket_handler, $recurring_copy),
    'Both interactive recurring creation paths must copy devices before entitlement selection');
$assertContains("['agreement_version_status'] !== 'Draft'", $handler, 'Published agreement rows must be rejected by edit handlers');
$assertContains('agreementVersionContext($version_id, true)', $handler, 'Draft child mutations must share the publication row lock');
$assertContains('Could not lock the agreement for draft mutation', $handler, 'Draft mutation and publication must use the same contract-first lock order');
$assertContains('agreementEntitlementScopeLabel($client_id', $handler, 'Specific entitlement records must snapshot their canonical tenant-owned label');
$assertContains('agreementPublishVersion(', $handler, 'Agreement publication must use the transactional publication service');
$assertContains('A future agreement version must remain a draft', $agreement_helpers, 'Future definitions must not displace the currently effective published agreement');
$assertContains('An archived agreement cannot be published', $agreement_helpers, 'Archived agreements must not be reactivated through version publication');
$assertContains('published or superseded agreement effective at the review-period boundary', $agreement_helpers, 'Every service review must pin the historically effective agreement version');
$assertContains('Unified endpoint service-review adapter failed', $agreement_helpers, 'Endpoint adapter failures must retain the disclosed fallback snapshot');
$assertContains('Documentation service-review adapter failed', $agreement_helpers, 'Documentation adapter failures must retain the disclosed fallback snapshot');
$assertContains("'name' => 'service_reviews'", $cron_registry, 'Due service-review generation must be registered with the dispatcher');
$assertContains("JOIN clients ON client_id = contract_client_id AND client_archived_at IS NULL", $agreement_helpers, 'Scheduled reviews must exclude archived clients');
$assertContains('Service-review draft failed for contract', $agreement_helpers, 'One ineligible review must not block the remaining scheduled contracts');
$assertContains('agreementNormalizeCoverageAdapter($adapter_coverage)', $agreement_helpers, 'Endpoint adapters must pass strict aggregate/count validation before entering client reports');
$assertContains('agreementNormalizeDocumentationAdapter($adapter_documentation)', $agreement_helpers, 'Documentation adapters must pass strict readiness validation before entering client reports');
$assertContains('agreementValidateServiceReviewSnapshot($review)', $agreement_helpers, 'Review publication and export must share central snapshot/binding validation');
$assertContains('agreementValidateServiceReviewApproval(', $agreement_helpers, 'Published reviews must retain actor, reason, timestamp, tenant, and snapshot approval evidence');
$assertContains('agreementShiftCalendarMonths($review_due_at, $cadence)', $agreement_helpers, 'Scheduled reviews must advance from their prior due boundary without drift');
$assertContains("contract_next_review_at = '\$review_due_sql'", $agreement_helpers, 'Review schedules must advance with an exact compare-and-set guard');
$assertContains("agreement_version_superseded_at = '\$publication_at'", $agreement_helpers, 'Agreement lifecycle intervals must share one explicit publication boundary');
$assertContains("agreement_version_published_at = '\$publication_at'", $agreement_helpers, 'Replacement agreement versions must open at the exact supersession boundary');

if ($failures) {
    date_default_timezone_set($original_timezone);
    fwrite(STDERR, "Agreement entitlement contracts failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

date_default_timezone_set($original_timezone);
echo "Agreement entitlement and service-review contracts passed.\n";
