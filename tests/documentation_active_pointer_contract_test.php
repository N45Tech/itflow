<?php

require_once __DIR__ . '/../functions/documentation.php';

$root = dirname(__DIR__);
$failures = [];
$read = static function ($path) use ($root, &$failures) {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$assertSame = static function ($expected, $actual, $message) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos((string) $contents, $needle) === false) {
        $failures[] = $message;
    }
};
$assertNotContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos((string) $contents, $needle) !== false) {
        $failures[] = $message;
    }
};
$section = static function ($contents, $start, $end, $label) use (&$failures) {
    $start_at = strpos((string) $contents, $start);
    $end_at = $start_at === false ? false : strpos((string) $contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr((string) $contents, $start_at, $end_at - $start_at);
};
$assertOrder = static function ($contents, array $needles, $message) use (&$failures) {
    $cursor = -1;
    foreach ($needles as $needle) {
        $position = strpos((string) $contents, $needle, $cursor + 1);
        if ($position === false || $position <= $cursor) {
            $failures[] = $message . " (missing/out of order: $needle)";
            return;
        }
        $cursor = $position;
    }
};

// Publish-vNext behavior: the old obligation pointer is never mutation-safe,
// never inherits the old display metadata, and never projects as Current.
$obligation = [
    'documentation_obligation_id' => 40,
    'documentation_obligation_requirement_id' => 7,
    'documentation_obligation_requirement_version_id' => 10,
    'documentation_obligation_applicable' => 1,
    'documentation_obligation_base_status' => 'Current',
    'documentation_obligation_last_verified_at' => '2026-08-31 12:00:00',
    'documentation_obligation_verification_document_hash' => hash('sha256', 'old'),
    'documentation_requirement_current_lifecycle' => 'Active',
    'documentation_requirement_projection_valid' => 0,
    'documentation_verification_context_valid' => 0,
    'documentation_exception_record_valid' => 0,
    'current_document_exists' => 1,
    'current_document_hash' => hash('sha256', 'old'),
    'documentation_requirement_version_name' => 'Stored v1 name',
    'documentation_current_requirement_version_key' => 'active-v2',
    'documentation_current_requirement_version_name' => 'Active v2 name',
    'documentation_current_requirement_version_review_cadence_days' => 90,
    'documentation_current_requirement_version_warning_window_days' => 14,
];
$active_v1 = [
    'documentation_requirement_id' => 7,
    'documentation_requirement_lifecycle' => 'Active',
    'documentation_requirement_archived_at' => null,
    'documentation_requirement_published_version_id' => 10,
];
$active_v2 = $active_v1;
$active_v2['documentation_requirement_published_version_id'] = 11;
$archived_v1 = $active_v1;
$archived_v1['documentation_requirement_lifecycle'] = 'Archived';
$archived_v1['documentation_requirement_archived_at'] = '2026-09-01 12:00:00';
$assertSame(true, documentationObligationRequirementIsCurrent($obligation, $active_v1),
    'The active matching pointer was rejected');
$assertSame(false, documentationObligationRequirementIsCurrent($obligation, $active_v2),
    'A superseded obligation remained mutation-safe after publishing vNext');
$assertSame(false, documentationObligationRequirementIsCurrent($obligation, $archived_v1),
    'An archived requirement remained mutation-safe');
$display = documentationApplyCurrentRequirementMetadata($obligation);
$assertSame('Active v2 name', $display['documentation_requirement_version_name'],
    'A queue/detail projection displayed the stored superseded name');
$assertSame('Draft', documentationProjectObligationValidity($display, '2026-09-01 12:00:00')['effective_status'],
    'A publish-vNext projection remained Current before reconciliation');

// A new applicable publication with no obligation is a first-class, read-only
// Missing queue item rather than a readiness-only phantom.
$pending = documentationBuildPendingObligationRow(
    ['client_id' => 22, 'client_name' => 'Example Client'],
    [
        'documentation_requirement_id' => 8,
        'documentation_requirement_version_id' => 12,
        'documentation_requirement_version_number' => 1,
        'documentation_requirement_version_key' => 'new-active-requirement',
        'documentation_requirement_version_name' => 'New active requirement',
        'documentation_requirement_version_description' => 'Required immediately after publication.',
        'documentation_requirement_version_record_type' => 'general',
        'documentation_requirement_version_default_owner_role' => 'documentation_owner',
        'documentation_requirement_version_default_owner_user_id' => 0,
        'documentation_requirement_version_default_reviewer_role' => 'support_lead',
        'documentation_requirement_version_default_reviewer_user_id' => 0,
        'documentation_requirement_version_blocks_readiness' => 1,
        'documentation_requirement_version_blocks_ticket_resolution' => 1,
        'documentation_requirement_version_review_cadence_days' => 90,
        'documentation_requirement_version_warning_window_days' => 14,
        'documentation_requirement_version_evidence_policy' => 'reference',
        'documentation_requirement_version_exception_approval_policy' => 'support3',
    ]
);
$pending_projection = documentationProjectObligationValidity($pending, '2026-09-01 12:00:00');
$pending_readiness = documentationReadinessReduce([$pending], '2026-09-01 12:00:00');
$assertSame(1, $pending['documentation_obligation_projection_pending'],
    'A missing durable obligation was not marked as projection-pending');
$assertSame('New active requirement', $pending['documentation_requirement_version_name'],
    'A pending queue row lost current published metadata');
$assertSame('Missing', $pending_projection['effective_status'],
    'A new requirement did not immediately project as Missing');
$assertSame(1, $pending_readiness['denominator'],
    'A new blocking requirement disappeared from readiness before reconciliation');
$assertSame(0, $pending_readiness['score_percent'],
    'A new blocking requirement earned readiness before reconciliation');

$core = $read('functions/documentation.php');
$loader = $section($core, 'function documentationLoadObligationForMutation(',
    'function documentationObligationClientId(', 'obligation mutation loader');
$assertOrder($loader, [
    'documentationLockClient($client_id)',
    'SELECT * FROM documentation_requirements',
    'ORDER BY documentation_requirement_id FOR UPDATE',
    'documentationObligationRequirementIsCurrent(',
    'SELECT obligation.*, version.*',
    'LIMIT 1 FOR UPDATE',
], 'The common mutation lock order is not client -> active requirement -> obligation');
$assertContains("documentation_requirement_lifecycle'] ?? '') === 'Active'", $core,
    'The mutation pointer check does not require Active lifecycle');
$assertContains('documentation_requirement_published_version_id', $core,
    'The common mutation loader does not validate the published pointer');
$assertContains('refresh and reconcile this client', $loader,
    'A stale mutation does not direct the caller to refresh/reconcile');

$mutation_sections = [
    ['function documentationAssignObligationOwners(', 'function documentationServiceReviewReadiness(', 'owner assignment'],
    ['function documentationLinkObligationDocument(', 'function documentationInvalidateDocumentLocked(', 'document link'],
    ['function documentationVerifyObligation(', 'function documentationRequestObligationException(', 'verification'],
    ['function documentationRequestObligationException(', 'function documentationDecideObligationException(', 'exception request'],
    ['function documentationDecideObligationException(', 'function documentationLockTicket(', 'exception decision'],
    ['function documentationLinkTicketObligation(', 'function documentationLinkTaskObligation(', 'ticket link'],
    ['function documentationRequestTicketWaiver(', 'function documentationDecideTicketWaiver(', 'waiver request'],
    ['function documentationDecideTicketWaiver(', 'function documentationLockTicketDocumentationGraph(', 'waiver decision'],
    ['function documentationCreatePromise(', 'function documentationCompletePromise(', 'promise creation'],
    ['function documentationCompletePromise(', 'function documentationExpirePromises(', 'promise completion'],
];
foreach ($mutation_sections as [$start, $end, $label]) {
    $mutation = $section($core, $start, $end, $label);
    $assertContains('documentationLoadObligationForMutation(', $mutation,
        ucfirst($label) . ' bypasses the active requirement pointer');
}

$current_metadata_surfaces = [
    'agent/documentation.php',
    'agent/modals/documentation/obligation.php',
    'agent/modals/ticket/ticket_documentation.php',
    'agent/document.php',
    'agent/operations.php',
];
foreach ($current_metadata_surfaces as $path) {
    $contents = $read($path);
    $assertContains('documentationObligationValiditySql(', $contents,
        "$path omits the current published requirement join");
    $assertContains('documentationApplyCurrentRequirementMetadata(', $contents,
        "$path can display stored superseded requirement metadata");
    $assertContains('documentationProjectObligationValidity(', $contents,
        "$path trusts the legacy stored lifecycle projection");
    $assertNotContains('INNER JOIN documentation_requirement_versions v', $contents,
        "$path still joins the stored superseded requirement version");
    $assertNotContains('documentationLifecycleEffectiveStatus(', $contents,
        "$path still uses the legacy lifecycle reducer");
}

$obligation_modal = $read('agent/modals/documentation/obligation.php');
$ticket_modal = $read('agent/modals/ticket/ticket_documentation.php');
$assertContains('$projection_mutable = $projection[\'requirement_active\'] && $projection[\'requirement_current\'];',
    $obligation_modal, 'The obligation modal does not disable mutations for a stale pointer');
$assertContains('Reconciliation required.', $obligation_modal,
    'The obligation modal does not explain its stale read-only state');
$assertContains('$projection_mutable', $ticket_modal,
    'The ticket documentation modal does not disable stale obligation mutations');

$pending_surfaces = [
    'agent/documentation.php',
    'agent/operations.php',
    'agent/includes/get_side_nav_counts.php',
    'agent/includes/inc_all_client.php',
];
foreach ($pending_surfaces as $path) {
    $assertContains('documentationPendingObligationRowsForClients(', $read($path),
        "$path hides new applicable requirements until reconciliation");
}
$queue = $read('agent/documentation.php');
$assertContains('Projection pending reconciliation', $queue,
    'The queue does not identify a synthetic pending requirement');
$assertContains('Reconcile pending', $queue,
    'A synthetic requirement is visible but not actionable');
$assertContains('name="reconcile_documentation_client"', $queue,
    'A synthetic requirement does not submit the real reconciliation action');
$assertContains('name="csrf_token"', $queue,
    'The pending reconciliation action omits CSRF state');
$documentation_post = $read('agent/post/documentation.php');
$reconcile_post = $section($documentation_post,
    "if (isset(\$_POST['reconcile_documentation_client']))",
    "if (isset(\$_POST['assess_ticket_documentation']))",
    'pending reconciliation POST');
foreach (['validateCSRFToken()', "enforceUserPermission('module_support', 2)",
          'enforceClientAccess($client_id)', 'documentationEvaluateClient($client_id, $session_user_id)',
          'documentation.php?client_id=$client_id&owner=all&status=attention'] as $post_contract) {
    $assertContains($post_contract, $reconcile_post,
        "Pending reconciliation POST omits $post_contract");
}
$evaluator = $section($core, 'function documentationEvaluateClient(',
    'function documentationEvaluateDueClients(', 'client evaluator');
$assertOrder($evaluator, ['documentationLockClient($client_id)', 'documentationPublishedRequirementRows(true)'],
    'Explicit reconciliation does not lock client before active requirements');
$published_rows = $section($core, 'function documentationPublishedRequirementRows(',
    'function documentationEvaluateClient(', 'published requirement loader');
$assertContains("\$lock_sql = \$for_update ? ' FOR UPDATE' : '';", $published_rows,
    'The evaluator cannot lock the active requirement catalog');
$assertContains('documentationPendingObligationRowsForClients([$client], 0)', $core,
    'Client readiness and queue pending projections do not share the same source');
$assertNotContains('LIMIT 500', $queue,
    'The durable queue silently truncates before canonical status filtering');
$assertNotContains('LIMIT 1000', $queue,
    'The pending queue silently truncates authorized clients');
$assertContains("intval(\$_GET['queue_page'] ?? 1)", $queue,
    'The complete queue has no deterministic page selector');
$assertContains('array_slice($obligations, $documentation_page_start, $documentation_page_size)', $queue,
    'The queue does not paginate after canonical projection/filtering');
$assertContains('of <?= $documentation_total_rows ?> matching obligations', $queue,
    'The queue does not disclose its complete projected result count');
$assertNotContains('array_slice($clients, 0, 1000)', $core,
    'Applicability facts silently omit clients beyond the first batch');

if ($failures) {
    fwrite(STDERR, "Documentation active-pointer contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation active-pointer contract test passed\n";
