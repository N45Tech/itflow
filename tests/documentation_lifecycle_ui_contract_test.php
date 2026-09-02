<?php

/*
 * Static contract coverage for Goal 4's lifecycle/UI wiring. Runtime evaluator
 * and schema semantics are covered by the core documentation tests.
 */

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
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) === false) {
        $failures[] = $message;
    }
};
$assertNotContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) !== false) {
        $failures[] = $message;
    }
};

$runbooks = $read('functions/runbooks.php');
$lifecycle = $read('functions/documentation_lifecycle.php');
$ticket_page = $read('agent/ticket.php');
$ticket_post = $read('agent/post/ticket.php');
$documentation_post = $read('agent/post/documentation.php');
$document_post = $read('agent/post/document.php');
$client_post = $read('agent/post/client.php');
$retention = $read('functions/retention.php');
$queue = $read('agent/documentation.php');
$operations = $read('agent/operations.php');
$obligation_modal = $read('agent/modals/documentation/obligation.php');
$ticket_modal = $read('agent/modals/ticket/ticket_documentation.php');
$ticket_add = $read('agent/modals/ticket/ticket_add.php');
$admin_requirements = $read('admin/documentation_requirements.php');
$admin_requirement_post = $read('admin/post/documentation_requirement.php');
$admin_requirement_modal = $read('admin/modals/documentation_requirement/documentation_requirement.php');
$core_documentation_path = $root . '/functions/documentation.php';
$core_documentation = is_file($core_documentation_path) ? file_get_contents($core_documentation_path) : '';
$documentation_migration_path = $root . '/n45/migrations/n45-0011-documentation-readiness.php';
$documentation_migration = is_file($documentation_migration_path) ? file_get_contents($documentation_migration_path) : '';

$assertContains('function runbookOnlyTicketCanResolve(', $runbooks, 'The runbook-only gate was not preserved');
$assertContains('function ticketLifecycleCanResolve(', $runbooks, 'The composite lifecycle gate is missing');
$assertContains('documentationTicketCanResolve(', $runbooks, 'The composite lifecycle gate does not call the documentation gate');
$assertContains('return ticketLifecycleCanResolve($ticket_id, false);', $runbooks, 'Legacy gate callers do not reach the composite lifecycle gate');
$assertContains("require_once __DIR__ . '/documentation_lifecycle.php';", $runbooks, 'Documentation lifecycle helpers are not loaded');

$assertContains('name="configuration_change"', $ticket_add, 'New agent tickets do not explicitly assess configuration change');
$assertContains('documentation_impact', $ticket_add, 'New agent tickets do not explicitly assess documentation impact');
$assertContains('ticket_documentation_impact =', $ticket_post, 'Agent ticket creation does not persist documentation assessment');
$assertContains('ticket_documentation_assessed_at = NOW()', $ticket_post, 'Agent ticket creation does not timestamp documentation assessment');
foreach (['Unassessed', 'None', 'Required', 'Legacy Exempt'] as $impact_value) {
    $assertContains("'$impact_value'", $lifecycle, "Ticket documentation impact contract is missing $impact_value");
    if ($core_documentation !== '') {
        $assertContains("'$impact_value'", $core_documentation, "Core documentation gate does not recognize $impact_value");
    }
}
if ($documentation_migration !== '') {
    $assertContains("DEFAULT 'Unassessed'", $documentation_migration, 'n45-0011 does not default new tickets to Unassessed');
    $assertContains("'Legacy Exempt'", $documentation_migration, 'n45-0011 does not explicitly exempt pre-existing tickets');
}

$assertContains('documentationAssessTicket(', $documentation_post, 'Technicians cannot reassess ticket documentation impact');
$assertContains('documentationLinkTicketObligation(', $documentation_post, 'Technicians cannot link affected documentation obligations');
$assertContains('documentationRequestTicketWaiver(', $documentation_post, 'Technicians cannot request a ticket documentation waiver');
$assertContains('documentationDecideTicketWaiver(', $documentation_post, 'Authorized technicians cannot decide a ticket documentation waiver');
$assertContains("if (isset(\$_POST['create_documentation_promise']))", $documentation_post, 'Technicians have no explicit structured Promise Ledger action');
$assertContains('documentationCreatePromise(', $documentation_post, 'The structured follow-up action does not use the immutable Promise Ledger API');
$assertContains('documentationCompletePromise(', $documentation_post, 'Technicians cannot explicitly fulfill or cancel a Promise Ledger commitment');
$assertContains('name="reason_code"', $ticket_modal, 'The ticket follow-up action does not require a structured reason code');
$assertContains('name="due_at"', $ticket_modal, 'The ticket follow-up action does not require an explicit due date');
$assertNotContains('documentationCreatePromise($ticket_reply', $documentation_post, 'Ticket prose is inferred into the Promise Ledger');
$assertContains("enforceUserPermission('module_support', 3)", $documentation_post, 'Exception and waiver decisions are not restricted to support level 3');
$assertNotContains('ticket_documentation_waiver_reason =', $documentation_post, 'Raw waiver reasons are persisted by the UI handler');

$assertContains('documentationObligationValiditySql(', $queue, 'The documentation queue omits canonical validity joins');
$assertContains('documentationProjectObligationValidity(', $queue, 'The documentation queue trusts stale stored status');
$assertContains("clientScopeSql('o.documentation_obligation_client_id')", $queue, 'The documentation queue is not client-scoped');
$assertContains('documentationReadinessForClient(', $queue, 'The client matrix is not wired to the canonical readiness reducer');
$assertContains('documentation-attention', $operations, 'Operations does not surface documentation exceptions');
$assertContains('documentationProjectObligationValidity(', $operations, 'Operations does not calculate fail-closed gate-time freshness');

$assertContains("enforceUserPermission('module_support')", $obligation_modal, 'Obligation detail is not permission gated');
$assertContains('enforceClientAccess($client_id)', $obligation_modal, 'Obligation detail is not client scoped');
$assertContains("enforceUserPermission('module_support')", $ticket_modal, 'Ticket documentation detail is not permission gated');
$assertContains('enforceClientAccess($client_id)', $ticket_modal, 'Ticket documentation detail is not client scoped');
$assertContains('ticketLifecycleCanResolve($ticket_id, true)', $ticket_page, 'Authorized ticket UI does not show composite gate detail');

$assertContains('documentationDocumentHasObligations($document_id)', $document_post, 'Canonical documents can be archived or deleted without a guard');
$assertContains('documentation_evidence_locker', $retention, 'Tickets with documentation evidence can be permanently purged');
$assertContains('documentation_change_passports', $retention, 'Tickets with Change Passports can be permanently purged');
$assertContains('documentation_promise_ledger', $retention, 'Tickets with Promise Ledger history can be permanently purged');
$assertContains('documentationTicketCanTransfer($ticket_id, $client_id)', $ticket_post, 'Ticket transfer does not preserve client-bound documentation history');
$assertContains('Permanent client deletion is disabled by retention policy', $client_post, 'Clients with documentation history can be permanently deleted');
$assertNotContains('DELETE FROM clients WHERE client_id = $client_id', $client_post, 'Client teardown can erase documentation history');
$assertContains('runbookLockOpenTicket($ticket_id)', $lifecycle, 'Documentation lifecycle mutation helpers do not lock their ticket context');

$assertContains('documentationSaveRequirementDraft(', $admin_requirement_post, 'Administrators cannot save a requirement draft through the immutable core API');
$assertContains('documentationPublishRequirement(', $admin_requirement_post, 'Administrators cannot publish an immutable requirement version');
$assertContains('documentationArchiveRequirement(', $admin_requirement_post, 'Administrators cannot archive a requirement safely');
$assertContains('documentationRestoreRequirement(', $admin_requirement_post, 'Administrators cannot restore a requirement safely');
$assertContains('expected_revision', $admin_requirement_post, 'Requirement lifecycle mutations do not use optimistic concurrency');
$assertContains('enforceAdminPermission()', $admin_requirement_modal, 'Requirement authoring is not restricted to administrators');
$assertContains('documentation_requirement_published_version_id', $admin_requirements, 'Requirement list does not distinguish drafts from published versions');

if ($failures) {
    fwrite(STDERR, "Documentation lifecycle/UI contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation lifecycle/UI contract test passed\n";
