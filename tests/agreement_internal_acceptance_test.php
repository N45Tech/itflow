<?php

require_once __DIR__ . '/../functions/agreements.php';

$failures = [];
$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) === false) {
        $failures[] = $message;
    }
};
$assertRejects = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (RuntimeException $e) {
        // Expected fail-closed result.
    }
};

// Phase 1: reconcile/inspect the exact non-commercial agreement model. The
// canary has an exact key/priority selector and SLA None; every ordinary ticket remains
// unmatched so applyTicketSla() can continue through legacy assignments.
$specification = agreementInternalBaselineSpecification();
$definition = agreementInternalBaselineExpectedDefinition(501, 1);
$definition_hash = agreementDefinitionHash($definition);
$rules = [[
    'agreement_sla_rule_id' => 601,
    'agreement_sla_rule_request_type_key' => $specification['sla_rule']['request_type_key'],
    'agreement_sla_rule_priority' => $specification['sla_rule']['priority'],
    'agreement_sla_rule_sla_id' => 0,
    'agreement_sla_rule_order' => 0,
]];
$assertSame('N45 Internal', $specification['client_name'], 'The baseline is not pinned to the internal client identity');
$assertSame(0, $specification['sla_rule']['sla_id'], 'The canary rule unexpectedly carries an SLA promise');
$assertTrue(
    agreementSelectSlaRule($rules, 'n45-internal-agreement-canary', 'Low') !== null,
    'The exact internal canary does not select its inert rule'
);
$assertSame(
    null,
    agreementSelectSlaRule($rules, 'n45-internal-agreement-canary', 'Medium'),
    'A non-canary priority unexpectedly matches the internal rule'
);
$assertSame(
    null,
    agreementSelectSlaRule($rules, 'ordinary-support-request', 'Low'),
    'An ordinary ticket no longer preserves unmatched-ticket fallback'
);
$canary_entitlement = agreementResolveEntitlementApplicability(
    [$specification['entitlement']],
    ['services' => [
        'ids' => [],
        'keys' => ['n45-internal-agreement-canary'],
        'population' => 0,
    ]],
    'included'
);
$ordinary_entitlement = agreementResolveEntitlementApplicability(
    [$specification['entitlement']],
    ['services' => [
        'ids' => [],
        'keys' => ['ordinary-support-request'],
        'population' => 0,
    ]],
    'included'
);
$assertSame(
    'included',
    $canary_entitlement['resolved_classification'],
    'The internal canary is not included by its one narrow entitlement'
);
$assertSame(
    'excluded',
    $ordinary_entitlement['resolved_classification'],
    'The internal entitlement unexpectedly covers an ordinary request'
);
$assertSame(
    'Reset for [redacted email] token=[redacted]',
    agreementReviewRedactText('Reset for owner@n45.example token=super-secret-value', 'test subject'),
    'Service-review free-text minimization did not redact an email and credential token'
);
$basic_redaction = agreementReviewRedactText(
    'Authorization: Basic dXNlcjpwYXNzd29yZA==',
    'test authorization'
);
$assertSame(
    'Authorization=[redacted]',
    $basic_redaction,
    'Service-review free-text minimization left part of a Basic credential visible'
);
$assertTrue(
    strpos($basic_redaction, 'dXNlcjpwYXNzd29yZA') === false,
    'Service-review Basic credential redaction retained its encoded payload'
);
$assertSame(
    'AWS_SECRET_ACCESS_KEY=[redacted]',
    agreementReviewRedactText(
        'AWS_SECRET_ACCESS_KEY=example-secret-material',
        'test environment secret'
    ),
    'Service-review free-text minimization did not redact an underscore-style secret key'
);

// Phase 2: generate the first synthetic N45 Internal review from a complete,
// tenant-bound source snapshot and inspect the exact bytes/hash before approval.
$snapshot = [
    'schema_version' => 1,
    'client' => ['id' => 45, 'name' => 'N45 Internal'],
    'period' => ['start' => '2026-06-01', 'end' => '2026-08-31'],
    'tickets' => [
        'total' => 1,
        'resolved' => 1,
        'open' => 0,
        'recurring' => 0,
        'response_met' => 0,
        'response_missed' => 0,
        'response_compliance_percent' => null,
        'resolution_met' => 0,
        'resolution_missed' => 0,
        'resolution_compliance_percent' => null,
        'recurring_issue_groups' => 0,
        'recurring_issues' => [],
    ],
    'coverage' => [
        'available' => true,
        'active_devices' => 0,
        'endpoint_managed_devices' => 0,
        'endpoint_coverage_percent' => null,
        'security_mapped_devices' => 0,
        'security_coverage_percent' => null,
        'source' => 'synthetic tenant-scoped acceptance fixture',
    ],
    'backup' => [
        'available' => false,
        'in_scope' => false,
        'services_in_scope' => 0,
        'incidents' => 0,
        'open_incidents' => 0,
        'repeat_events' => 0,
        'last_signal_at' => null,
        'source' => 'synthetic source-neutral acceptance fixture',
    ],
    'documentation' => [
        'available' => false,
        'source' => 'synthetic basic-document-inventory',
        'document_count' => 0,
        'recently_updated' => 0,
        'latest_at' => null,
        'note' => 'Synthetic readiness provider intentionally unavailable.',
    ],
    'renewals' => [
        'next_365_days' => 0,
        'within_notice_window' => 0,
        'items' => [],
    ],
    'recommendations' => [
        'Maintain the internal canary and re-evaluate after the production acceptance window.',
    ],
    'agreement' => [
        'contract_id' => 501,
        'version_id' => 502,
        'version_number' => 1,
        'name' => $specification['contract']['name'],
        'definition_hash' => $definition_hash,
        'published_at' => '2026-09-01 12:00:00',
        'superseded_at' => null,
        'resolution_as_of' => '2026-08-31 23:59:59',
    ],
    'summary' => '1 tickets; response SLA not judged; resolution SLA not judged; 0 recurring issue group(s).',
];
$snapshot_json = agreementCanonicalJson($snapshot);
$snapshot_hash = hash('sha256', $snapshot_json);
$review = [
    'service_review_id' => 701,
    'service_review_client_id' => 45,
    'service_review_contract_id' => 501,
    'service_review_agreement_version_id' => 502,
    'service_review_period_start' => '2026-06-01',
    'service_review_period_end' => '2026-08-31',
    'service_review_status' => 'Draft',
    'service_review_source_snapshot' => $snapshot_json,
    'service_review_summary' => $snapshot['summary'],
    'service_review_recommendations' => implode("\n", $snapshot['recommendations']),
    'service_review_snapshot_hash' => $snapshot_hash,
    'service_review_generated_by' => 7,
    'service_review_generated_at' => '2026-09-01 12:05:00',
    'service_review_published_by' => 0,
    'service_review_published_at' => null,
];
$events = [[
    'service_review_event_id' => 801,
    'service_review_event_review_id' => 701,
    'service_review_event_client_id' => 45,
    'service_review_event_action' => 'Generated',
    'service_review_event_actor_id' => 7,
    'service_review_event_reason' => 'Generated from a consistent source snapshot',
    'service_review_event_snapshot_hash' => $snapshot_hash,
    'service_review_event_created_at' => '2026-09-01 12:05:00',
    'user_name' => 'N45 Acceptance Owner',
]];
$decoded = agreementValidateServiceReviewSnapshot($review);
$assertSame(45, intval($decoded['client']['id']), 'Generated review lost its tenant binding');
$assertSame($definition_hash, $decoded['agreement']['definition_hash'], 'Generated review lost its agreement hash binding');
$assertSame(null, agreementValidateServiceReviewApproval($review, $events), 'A draft review unexpectedly has publication approval');
$review['_service_review_events'] = $events;
$draft_markdown = agreementServiceReviewMarkdown($review);
$assertContains($snapshot_hash, $draft_markdown, 'Draft export omits its immutable snapshot hash');
$assertContains($definition_hash, $draft_markdown, 'Draft export omits its agreement definition hash');

// Phase 3: approve/publish, export the immutable result, and verify actor,
// reason, time, tenant, review, and hash bindings all survive presentation.
$approval_reason = 'Approve first immutable N45 Internal service-review acceptance snapshot';
$published_at = '2026-09-01 12:10:00';
$review['service_review_status'] = 'Published';
$review['service_review_published_by'] = 7;
$review['service_review_published_at'] = $published_at;
$events[] = [
    'service_review_event_id' => 802,
    'service_review_event_review_id' => 701,
    'service_review_event_client_id' => 45,
    'service_review_event_action' => 'Published',
    'service_review_event_actor_id' => 7,
    'service_review_event_reason' => $approval_reason,
    'service_review_event_snapshot_hash' => $snapshot_hash,
    'service_review_event_created_at' => $published_at,
    'user_name' => 'N45 Acceptance Owner',
];
$review['_service_review_events'] = $events;
$approval = agreementValidateServiceReviewApproval($review, $events);
$assertSame($approval_reason, $approval['service_review_event_reason'] ?? null, 'Published review lost its approval reason');
$published_markdown = agreementServiceReviewMarkdown($review);
$assertContains('Published approval: N45 Acceptance Owner', $published_markdown, 'Published export omits the approval actor');
$assertContains($approval_reason, $published_markdown, 'Published export omits the approval reason');
$assertContains($snapshot_hash, $published_markdown, 'Published export omits the snapshot hash');

// Phase 4: all attempted mutations or evidence rebinding fail closed.
$assertRejects(
    static fn () => agreementAssertServiceReviewDraft($review),
    'A published service review was accepted as a mutable draft'
);
$tampered_bytes = $review;
$tampered_bytes['service_review_source_snapshot'] .= ' ';
$assertRejects(
    static fn () => agreementValidateServiceReviewSnapshot($tampered_bytes),
    'Changed snapshot bytes were accepted under the published hash'
);
$tampered_presentation = $review;
$tampered_presentation['service_review_summary'] = 'Changed after publication';
$assertRejects(
    static fn () => agreementValidateServiceReviewSnapshot($tampered_presentation),
    'Changed presentation fields were accepted outside the hashed snapshot'
);
$tampered_events = $events;
$tampered_events[1]['service_review_event_client_id'] = 46;
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($review, $tampered_events),
    'Cross-tenant approval evidence was accepted'
);
$duplicate_approval = $events;
$duplicate_approval[] = $events[1] + ['service_review_event_id' => 803];
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($review, $duplicate_approval),
    'Ambiguous duplicate publication evidence was accepted'
);
$duplicate_generation = $events;
$duplicate_generation[] = $events[0] + ['service_review_event_id' => 804];
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($review, $duplicate_generation),
    'Ambiguous duplicate generation evidence was accepted'
);
$wrong_generation_time = $events;
$wrong_generation_time[0]['service_review_event_created_at'] = '2026-09-01 12:05:01';
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($review, $wrong_generation_time),
    'Generation evidence with a different timestamp was accepted'
);
$unsupported_event = $events;
$unsupported_event[] = array_replace($events[0], [
    'service_review_event_id' => 805,
    'service_review_event_action' => 'Changed',
]);
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($review, $unsupported_event),
    'An unsupported lifecycle event was accepted into immutable review evidence'
);
$backdated_review = $review;
$backdated_events = $events;
$backdated_review['service_review_published_at'] = '2026-09-01 12:04:59';
$backdated_events[1]['service_review_event_created_at'] = '2026-09-01 12:04:59';
$assertRejects(
    static fn () => agreementValidateServiceReviewApproval($backdated_review, $backdated_events),
    'Publication evidence predating review generation was accepted'
);
$inconsistent_metrics = $review;
$inconsistent_snapshot = $snapshot;
$inconsistent_snapshot['tickets']['open'] = 1;
$inconsistent_metrics['service_review_source_snapshot'] = agreementCanonicalJson($inconsistent_snapshot);
$inconsistent_metrics['service_review_snapshot_hash'] = hash(
    'sha256',
    $inconsistent_metrics['service_review_source_snapshot']
);
$assertRejects(
    static fn () => agreementValidateServiceReviewSnapshot($inconsistent_metrics),
    'Internally inconsistent ticket metrics were accepted under a valid snapshot hash'
);

$root = dirname(__DIR__);
$reconciler = file_get_contents($root . '/deploy/psa/reconcile_internal_agreement.php');
$deployment = file_get_contents($root . '/deploy/psa/AGREEMENT_ENTITLEMENTS.md');
$helpers = file_get_contents($root . '/functions/agreements.php');
$agreement_page = file_get_contents($root . '/agent/agreement.php');
$review_page = file_get_contents($root . '/agent/service_review.php');
$review_report = file_get_contents($root . '/agent/reports/service_reviews.php');
if ($reconciler === false || $deployment === false || $helpers === false
    || $agreement_page === false || $review_page === false || $review_report === false) {
    $failures[] = 'Could not read internal agreement deployment artifacts';
} else {
    foreach ([
        "\$allowed_modes = ['--dry-run', '--apply'];",
        "--client-id=ID --actor-id=ID",
        "client_name'] !== \$specification['client_name']",
        'GET_LOCK(',
        'mysqli_begin_transaction($mysqli)',
        'agreementPublishVersion(',
        'ordinary-support-request',
        'An unpublished internal baseline must be a clean Draft contract',
        'already has version history; owner review is required',
        'mysqli_rollback($mysqli)',
        'mysqli_commit($mysqli)',
        'RELEASE_LOCK(',
    ] as $required) {
        $assertContains($required, $reconciler, "Internal agreement reconciler is missing safety contract: $required");
    }
    $assertContains('reconcile_internal_agreement.php --dry-run', $deployment, 'Deployment guide omits the required baseline dry run');
    $assertContains('reconcile_internal_agreement.php --apply', $deployment, 'Deployment guide omits the explicit baseline apply command');
    $assertContains('ordinary unmatched tickets', $deployment, 'Deployment guide does not require fallback verification');
    $assertContains('synthetic acceptance', $deployment, 'Deployment guide does not distinguish local evidence from production proof');
    $assertContains('agreementPublishingActor($actor_id, true, $client_id)', $helpers, 'Publication does not revalidate the approving technician against the review tenant');
    $assertContains('$client_denied || ($any_allow && !$client_allowed)', $helpers, 'Publication actor validation does not enforce deny-wins and restricted allow-list semantics');
    $assertContains('Could not lock the publication approver client scope', $helpers, 'Publication actor validation does not lock the complete client-permission set');
    $assertContains('Publication actor locking requires an active database transaction', $helpers, 'Publication actor validation can claim locking outside a transaction');
    $assertContains('Caller-owned agreement publication requires an active database transaction', $helpers, 'Maintenance publication can bypass its required outer transaction');
    $assertContains('failed its publication-event binding check', $helpers, 'Published agreement integrity does not bind the version row to its immutable event');
    $assertContains('agreementReviewAssertRedactedText(', $helpers, 'Service-review validation does not enforce the free-text redaction contract');
    $assertContains('agreementAssertServiceReviewDraft($review)', $helpers, 'Service-review publication lacks an explicit immutable-state guard');
    $assertContains('agreementValidateServiceReviewSnapshot($existing_review)', $helpers, 'Idempotent review generation can reuse corrupted existing evidence');
    $assertContains('agreement_version_event_contract_id = $agreement_id', $agreement_page, 'Agreement lifecycle evidence is not scoped to the opened tenant-owned contract');
    $assertContains('agreement_version_event_version_id = $version_id', $agreement_page, 'Agreement lifecycle evidence is not scoped to the selected immutable version');
    $assertContains("escapeHtml(\$event['agreement_version_event_reason']", $agreement_page, 'Agreement publication reasons are not safely rendered');
    $assertContains('agreementValidateServiceReviewSnapshot($review)', $agreement_page, 'Agreement detail lists unverified review projections');
    $assertContains('agreementValidateServiceReviewAgreementEvidence($review, $verified_snapshot)', $agreement_page, 'Agreement detail does not bind reviews to immutable agreement evidence');
    $assertContains('agreementValidateServiceReviewAgreementEvidence($review, $snapshot)', $review_page, 'Service-review display/export does not bind the snapshot to its agreement lifecycle');
    $assertContains('agreementValidateServiceReviewSnapshot($review)', $review_report, 'Service-review report lists unverified presentation fields');
    $assertContains('agreementValidateServiceReviewAgreementEvidence($review, $verified_snapshot)', $review_report, 'Service-review report does not bind its projection to immutable agreement evidence');
    $assertContains('agreementValidateServiceReviewApproval(', $review_report, 'Service-review report does not verify publication evidence');
    $assertContains('Snapshot or approval evidence failed validation.', $review_report, 'Service-review report does not fail closed on corrupted evidence');
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure\n");
    }
    exit(1);
}

echo "N45 Internal agreement and immutable service-review acceptance checks passed.\n";
