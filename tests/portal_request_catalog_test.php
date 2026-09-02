<?php

$failures = [];
$root = dirname(__DIR__);

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
        $failures[] = $message . " (missing '$needle')";
    }
};
$assertNotContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};
$assertOrdered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $position + 1);
        if ($position === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
    }
};
$section = static function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0013-portal-request-catalog.php');
$migration_docs = $read('docs/n45/migrations.md');
$service = $read('functions/portal_requests.php');
$starter_content = $read('admin/post/starter_content_model.php');
$loader = $read('functions.php');
$admin_handler = $read('admin/post/portal_request_catalog.php');
$admin_item = $read('admin/portal_request_catalog_item.php');
$category_handler = $read('admin/post/category.php');
$client_handler = $read('client/post/portal_request.php');
$agent_handler = $read('agent/post/portal_request.php');
$client_request = $read('client/request.php');
$client_requests = $read('client/requests.php');
$client_status = $read('client/request_status.php');
$agent_queue = $read('agent/portal_requests.php');
$contact_handler = $read('agent/post/contact.php');
$client_model_handler = $read('agent/post/client.php');
$api_contact_delete = $read('api/v1/contacts/delete.php');
$cron_registry = $read('includes/cron_jobs.php');
$outbox_cron = $read('cron/portal_request_outbox.php');
$logging = $read('functions/logging.php');
$starter_reconciler = $read('deploy/psa/reconcile_portal_requests.php');
$deployment_docs = $read('deploy/psa/README.md');

$tables = [
    'portal_request_catalog_items',
    'portal_request_catalog_fields',
    'portal_request_catalog_versions',
    'portal_request_catalog_version_fields',
    'portal_request_submissions',
    'portal_request_dispatch_outbox',
    'portal_request_submission_events',
];
foreach ($tables as $table) {
    $assertContains("CREATE TABLE `$table`", $schema, "Baseline schema does not create $table");
    $assertContains("CREATE TABLE IF NOT EXISTS `$table`", $migration, "Portal request N45 migration does not create $table idempotently");
}
$assertContains("defined('FROM_N45_DB_UPDATER')", $migration, 'Portal request migration bypasses the N45 runner guard');
$assertNotContains("defined('FROM_DB_UPDATER')", $migration, 'Portal request migration still accepts the upstream database runner guard');
if (is_file($root . '/admin/database_updates/2.8.0.php')) {
    $failures[] = 'Reserved portal request migration 2.8.0.php remains in the upstream namespace';
}

$invariants = [
    'UNIQUE KEY `portal_request_catalog_item_key` (`portal_request_catalog_item_key`)' => 'Catalog stable keys are not unique',
    'UNIQUE KEY `portal_request_catalog_version_number` (`portal_request_catalog_version_item_id`,`portal_request_catalog_version_number`)' => 'Catalog version numbers are not unique per item',
    'UNIQUE KEY `portal_request_catalog_version_hash` (`portal_request_catalog_version_item_id`,`portal_request_catalog_version_definition_hash`)' => 'Catalog publication is not idempotent by definition hash',
    'KEY `portal_request_catalog_version_category` (`portal_request_catalog_version_category_id`)' => 'Published request category references are not indexed',
    'UNIQUE KEY `portal_request_catalog_version_field_key` (`portal_request_catalog_version_field_version_id`,`portal_request_catalog_version_field_key`)' => 'Published field keys are not unique within a version',
    'UNIQUE KEY `portal_request_submission_idempotency` (`portal_request_submission_idempotency_hash`)' => 'Submission idempotency hashes are not unique',
    'UNIQUE KEY `portal_request_submission_ticket` (`portal_request_submission_ticket_id`)' => 'A ticket may be linked to multiple catalog submissions',
    'KEY `portal_request_submission_client_status` (`portal_request_submission_client_id`,`portal_request_submission_status`,`portal_request_submission_submitted_at`)' => 'Tenant/status submission lookups are not indexed',
    'UNIQUE KEY `portal_request_dispatch_event_key` (`portal_request_dispatch_event_key`)' => 'Custom-action events are not uniquely keyed',
    'UNIQUE KEY `portal_request_dispatch_submission_trigger` (`portal_request_dispatch_submission_id`,`portal_request_dispatch_trigger`)' => 'A submission can enqueue the same trigger more than once',
    'KEY `portal_request_dispatch_status_available` (`portal_request_dispatch_status`,`portal_request_dispatch_available_at`)' => 'Retryable custom-action claims are not indexed',
];
foreach ($invariants as $contract => $message) {
    $assertContains($contract, $schema, "$message in the baseline schema");
    $assertContains($contract, $migration, "$message in the migration");
}
$assertContains('`portal_request_submission_request_hash` char(64) NOT NULL', $schema, 'Baseline submissions do not retain the version/content idempotency fingerprint');
$assertContains('`portal_request_submission_request_hash` char(64) NOT NULL', $migration, 'Portal request N45 submissions do not retain the version/content idempotency fingerprint');
$assertOrdered($migration, [
    "str_repeat('0', 64)",
    'ADD COLUMN IF NOT EXISTS `portal_request_submission_request_hash` char(64) DEFAULT NULL',
    'BINARY `portal_request_submission_request_hash` NOT REGEXP',
    'MODIFY `portal_request_submission_request_hash` char(64) NOT NULL',
], 'A restarted/partially applied migration cannot reconcile request fingerprints safely');
$assertOrdered($migration, [
    'FROM information_schema.statistics',
    "index_name = 'portal_request_catalog_version_category'",
    'ADD INDEX `portal_request_catalog_version_category`',
], 'A restarted/partially applied migration cannot reconcile the published-category index');
$assertContains('schema shape created by an earlier unreleased', $migration, 'The migration does not identify its experimental-schema compatibility repair');
$assertContains('N45 runner records its ledger entry only after this file', $migration, 'The migration does not document ledger-finalization ordering');
$assertContains('pending and safe to retry', $migration, 'The migration does not document interrupted N45 retry semantics');
$assertNotContains('database version marker already advanced', $migration, 'The N45 migration still claims an upstream numeric marker controls execution');
$assertContains('experimentally ran an earlier local `2.8.0.php`', $migration_docs, 'Operators are not warned to restore or reconcile an experimental portal request schema');
$assertContains('unrecorded `n45-0013` remains pending', $migration_docs, 'Operator guidance does not distinguish stable-ledger retry semantics from the legacy numeric marker');

$runbooks_loader = "n45RequireModule('runbooks');";
$portal_loader = "n45RequireModule('portal_requests');";
$app_loader = "require_once __DIR__ . '/functions/app.php';";
$assertOrdered($loader, [$runbooks_loader, $portal_loader, $app_loader], 'Portal requests do not load through the N45 module boundary after runbook primitives and before the shared app helpers');

$publish = $section($service, 'function portalRequestPublish(', 'function portalRequestContactContext(', 'catalog publication');
$assertOrdered($publish, [
    'mysqli_begin_transaction($mysqli)',
    'LIMIT 1 FOR UPDATE',
    'Could not lock the request runbook template',
    'runbookAssertVersionDefinitionHash(',
    'INSERT INTO portal_request_catalog_versions SET',
    'portalRequestAssertVersion($version_id);',
    'portal_request_catalog_item_published_version_id = $version_id',
    'mysqli_commit($mysqli)',
], 'Catalog publication does not lock, pin, validate, select and commit the immutable release in order');
// Use precise contracts not affected by the table name appearing in diagnostics.
$assertContains('portal_request_catalog_version_runbook_version_id = $runbook_version_id', $publish, 'Catalog releases do not pin a runbook version');
$assertContains("category_type = 'Ticket'", $publish, 'Catalog publication does not revalidate its live ticket category');
$assertContains('AND category_archived_at IS NULL LIMIT 1 FOR UPDATE', $publish, 'Catalog publication does not lock its active ticket category against archival');
$assertContains('A primary-only request cannot require another primary contact', $service, 'Catalog publication permits the impossible primary-requester/primary-approver route');
$assertContains('portalRequestAssertVersion($version_id);', $publish, 'Catalog publication does not reconstruct and hash-check the immutable release');
$assertContains('portal_request_catalog_item_published_version_id = $version_id', $publish, 'Catalog publication does not update the release pointer');
$assertContains('mysqli_commit($mysqli)', $publish, 'Catalog publication is not transactional');
$assertNotContains('UPDATE portal_request_catalog_versions', $service . $admin_handler, 'Published catalog rows can be rewritten');
$assertNotContains('DELETE FROM portal_request_catalog_versions', $service . $admin_handler, 'Published catalog rows can be deleted');
$assertNotContains('UPDATE portal_request_catalog_version_fields', $service . $admin_handler, 'Published field rows can be rewritten');
$assertNotContains('DELETE FROM portal_request_catalog_version_fields', $service . $admin_handler, 'Published field rows can be deleted');

$category_edit = $section($category_handler, "if (isset(\$_POST['edit_category']))", "if (isset(\$_GET['archive_category']))", 'category edit');
$category_archive = $section($category_handler, "if (isset(\$_GET['archive_category']))", "if (isset(\$_GET['restore_category']))", 'category archival');
$category_delete = $section($category_handler, "if (isset(\$_GET['delete_category']))", '// End category mutation handlers.', 'category deletion');
$assertOrdered($category_edit, [
    'mysqli_begin_transaction($mysqli)',
    'FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE',
    'FROM portal_request_catalog_versions',
    '(string) $current[\'category_type\'] !== $requested_type',
    'UPDATE categories SET category_name',
    'mysqli_commit($mysqli)',
], 'Category type changes are not serialized and blocked when an immutable request version pins the category');
$assertContains('Name, description, and color may still be edited', $category_edit, 'Pinned categories cannot retain cosmetic edits');
$assertOrdered($category_archive, [
    'mysqli_begin_transaction($mysqli)',
    'FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE',
    'FROM portal_request_catalog_versions',
    'mysqli_rollback($mysqli)',
    'cannot be archived',
], 'Category archival is not serialized and rejected for immutable request references');
$assertContains('UPDATE categories SET category_archived_at = NOW()', $category_archive, 'Unpinned category archival is not performed with a checked mutation');
$assertContains('mysqli_commit($mysqli)', $category_archive, 'Unpinned category archival is not committed transactionally');
$assertOrdered($category_delete, [
    'mysqli_begin_transaction($mysqli)',
    'FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE',
    'FROM portal_request_catalog_versions',
    'mysqli_rollback($mysqli)',
    'cannot be permanently deleted',
], 'Category deletion is not serialized and rejected for immutable request references');
$assertContains('DELETE FROM categories WHERE category_id = $category_id LIMIT 1', $category_delete, 'Unpinned category deletion is not performed with a checked mutation');
$assertContains('mysqli_commit($mysqli)', $category_delete, 'Unpinned category deletion is not committed transactionally');
$assertNotContains('mysqli_query(', $category_handler, 'Category lifecycle mutations contain unchecked database queries');

$submit = $section($service, 'function portalRequestSubmit(', 'function portalRequestAgentCanApprove(', 'portal submission');
$assertOrdered($submit, [
    'mysqli_begin_transaction($mysqli)',
    'portalRequestContactContext(',
    'portal_request_submission_idempotency_hash',
    'Could not lock the portal request release',
    'portalRequestAssertVersion($version_id)',
    'portalRequestContactCanUse(',
    'portalRequestValidateResponses(',
    'portalRequestApprovalRouteAvailable(',
    'portalRequestRecordEvent(',
    'portalRequestInitiateLockedSubmission(',
    'mysqli_commit($mysqli)',
], 'Portal submission does not lock, authorize, validate, deduplicate, audit, initiate and commit in order');
$assertContains('hash(\'sha256\', "$client_id:$contact_id:$idempotency_key")', $submit, 'Idempotency credentials are not tenant/contact bound and hashed');
$assertContains('portalRequestSubmissionRequestHash($version_id', $submit, 'Idempotency is not bound to the published version and canonical request body');
$assertContains('portal_request_submission_request_hash', $submit, 'The canonical idempotency request fingerprint is not persisted');
$assertContains('hash_equals($stored_request_hash, $request_hash)', $submit, 'Changed-content idempotency reuse is not rejected');
$assertContains('portalRequestApprovalRouteAvailable($definition, $client_id, $contact_id, true)', $submit, 'Approval route availability is not revalidated and locked immediately before PendingApproval');
$assertNotContains('portal_request_submission_idempotency_key', $schema . $migration . $service, 'A raw idempotency credential is persisted');

$initiate = $section($service, 'function portalRequestInitiateLockedSubmission(', 'function portalRequestSubmit(', 'ticket/runbook initiation');
$assertOrdered($initiate, [
    'portalRequestResponsePayload($submission)',
    'portalRequestContactContext(',
    'portalRequestContactCanUse(',
    'asset_client_id = $client_id',
    'contact_client_id = $client_id',
    'UPDATE settings SET',
    'Could not lock the portal request ticket category',
    'INSERT INTO tickets SET',
    'Could not reserve the portal request ticket link',
    'applyTicketSla($ticket_id, null, null, true)',
    'instantiateRunbookForTicket(',
    "portal_request_submission_status = 'Initiated'",
    'portalRequestRecordEvent(',
    'portalRequestEnqueueCustomAction(',
], 'Ticket and pinned runbook initiation are not one ordered transaction-owned mutation');
$assertContains("ticket_source = 'Portal Catalog'", $initiate, 'Catalog-created tickets cannot be identified by source');
$assertContains('intval($definition[\'runbook_version_id\'])', $initiate, 'Ticket creation does not use the catalog-pinned runbook version');
$assertContains("'caller_transaction' => true", $initiate, 'Runbook instantiation does not participate in the ticket transaction');
$assertContains('AND portal_request_submission_ticket_id IS NULL LIMIT 1', $initiate, 'Ticket link reservation lacks a compare-and-swap guard');
$assertContains('AND portal_request_submission_ticket_id = $ticket_id LIMIT 1', $initiate, 'Ticket initiation lacks a reserved-link compare-and-swap guard');
$assertContains('function requestCatalogAgreementKeyForTicket(array $ticket): string', $service, 'Agreement/SLA selection has no stable request-catalog adapter');
$agreement_adapter = $section($service, 'function requestCatalogAgreementKeyForTicket(', 'function portalRequestOptions(', 'agreement adapter');
$assertContains('$result === false', $agreement_adapter, 'Agreement/SLA catalog adapter is not query-failure safe');
$assertOrdered($agreement_adapter, ['try {', '$result = mysqli_query(', 'catch (Throwable $exception)'], 'Agreement/SLA catalog adapter executes a throwable database query outside its fail-safe boundary');

$decision = $section($service, 'function portalRequestDecide(', 'function portalRequestStarterDefinitions(', 'request approval');
$assertContains('$actor_id === intval($submission[\'portal_request_submission_contact_id\'])', $decision, 'Client requesters can approve their own catalog request');
$assertContains('portalRequestAgentCanApprove(', $decision, 'Internal approval does not revalidate agent support and tenant access');
$assertContains('portalRequestAgentCanApprove($actor_id, $client_id, true)', $decision, 'Internal approval eligibility is not revalidated under a row lock');
$assertContains('portalRequestContactContext($actor_id, $client_id, true)', $decision, 'Contact approval eligibility is not revalidated under a row lock');
$assertContains("portal_request_submission_status = 'PendingApproval' LIMIT 1", $decision, 'Approval decision lacks a pending-state compare-and-swap');
$assertOrdered($decision, ['LIMIT 1 FOR UPDATE', 'portalRequestRecordEvent(', 'portalRequestInitiateLockedSubmission(', 'mysqli_commit($mysqli)'], 'Approval does not audit and initiate before committing');

foreach (['text', 'textarea', 'email', 'phone', 'integer', 'date', 'datetime', 'select', 'checkbox', 'asset', 'contact'] as $field_type) {
    $assertContains("'$field_type'", $service, "Typed validator/catalog does not support $field_type fields");
}
$assertContains('asset_client_id = $client_id', $service, 'Asset answers are not tenant scoped');
$assertContains('contact_client_id = $client_id', $service, 'Contact answers are not tenant scoped');
$assertContains('portalRequestContactMatchesRule(', $client_status, 'Portal approval page does not apply the published contact rule');
$assertContains('intval($submission[\'portal_request_submission_contact_id\']) !== $session_contact_id', $client_status, 'Portal UI offers self-approval');
$assertContains('portalRequestStatusLabel(', $client_requests . $client_status . $agent_queue, 'Request state is still exposed as an internal machine token');
$assertContains('portalRequestClientEventActorLabel($event)', $client_status, 'Client audit history does not use minimized actor classes');
$assertNotContains('portal_request_submission_event_actor_id', $client_status, 'Client audit history retrieves or exposes durable actor IDs');
$assertContains('AS session_contact_was_decider', $client_status, 'Client request access does not minimize the retained approver identity to a session-scoped decision');
$assertNotContains("s.portal_request_submission_decided_by_type, s.portal_request_submission_decided_by_id", $client_status, 'Client request detail retrieves durable approver identity columns');
$assertNotContains('SELECT s.*', $client_requests, 'The client request list retrieves response and idempotency secrets it does not render');
$assertContains("s.portal_request_submission_decided_by_id = \$session_contact_id", $client_requests, 'A client approver loses the request from recent history immediately after deciding it');
$assertNotContains('SELECT s.*', $agent_queue, 'The agent request queue retrieves idempotency secrets it does not need');
$assertOrdered($client_request, ["if (in_array('contact', \$field_types, true))", 'SELECT contact_id, contact_name, contact_email FROM contacts'], 'Client forms load contact details when no contact field is rendered');
$assertOrdered($client_request, ["if (in_array('asset', \$field_types, true))", 'SELECT asset_id, asset_name, asset_type FROM assets'], 'Client forms load device details when no asset field is rendered');

$assertContains('validateCSRFToken();', $admin_handler, 'Catalog administration lacks CSRF enforcement');
$assertContains('validateCSRFToken();', $client_handler, 'Portal submission/approval lacks CSRF enforcement');
$assertContains('validateCSRFToken();', $agent_handler, 'Internal approval lacks CSRF enforcement');
$assertContains("enforceUserPermission('module_support', 2)", $agent_handler, 'Internal approval lacks support-write RBAC');
$assertContains('enforceClientAccess($client_id)', $agent_handler, 'Internal approval lacks tenant enforcement');
$assertContains("clientScopeSql('s.portal_request_submission_client_id')", $agent_queue, 'Agent request queue is not tenant scoped');
$draft_update = $section($admin_handler, "if (isset(\$_POST['update_portal_request_catalog_item']))", "if (isset(\$_POST['save_portal_request_catalog_field']))", 'catalog draft update');
$assertOrdered($draft_update, [
    'mysqli_begin_transaction($mysqli)',
    'portal_request_catalog_item_archived_at IS NULL LIMIT 1 FOR UPDATE',
    'Could not lock the request runbook template',
    'Could not lock the request ticket category',
    'UPDATE portal_request_catalog_items SET',
    'mysqli_commit($mysqli)',
], 'Catalog draft edits are not serialized with publication and archive state');
$assertContains("if (\$type !== 'integer')", $admin_handler, 'Non-integer fields retain meaningless numeric bounds');
$assertContains("if (\$type !== 'select')", $admin_handler, 'Non-select fields retain stale choice options');
$assertContains("\$is_archived ? 'disabled' : ''", $admin_item, 'Archived catalog drafts still present actionable edit controls');
$assertContains('created request catalog draft $name_sql', $admin_handler, 'Raw catalog names are interpolated into audit SQL');
$assertContains('updated request catalog draft $name_sql', $admin_handler, 'Raw updated catalog names are interpolated into audit SQL');

$outbox = $section($service, 'function portalRequestEnqueueCustomAction(', 'function portalRequestSubject(', 'custom-action outbox');
$assertOrdered($outbox, [
    'portal_request_dispatch_event_key',
    'mysqli_begin_transaction($mysqli)',
    'LIMIT 1 FOR UPDATE',
    "portal_request_dispatch_status = 'Processing'",
    'mysqli_commit($mysqli)',
    'triggerCustomAction($trigger, $ticket_id, $event_key)',
    "portal_request_dispatch_status = 'Delivered'",
    "portal_request_dispatch_status = 'Failed'",
], 'Custom-action outbox is not uniquely enqueued, leased, delivered and retryable in order');
$assertContains('DATE_SUB(NOW(), INTERVAL 10 MINUTE)', $outbox, 'Crashed custom-action leases cannot be reclaimed');
$assertContains('t.ticket_client_id = s.portal_request_submission_client_id', $outbox, 'Custom-action delivery does not revalidate the submission/ticket tenant link');
$assertOrdered($outbox, [
    '$expected_event_key = hash(\'sha256\', "portal-request:$submission_id:$trigger")',
    'hash_equals($expected_event_key, $event_key)',
    'triggerCustomAction($trigger, $ticket_id, $event_key)',
], 'Custom-action dispatch does not recompute and verify its canonical event key before delivery');
$assertContains('portalRequestDispatchAfterCommit($submission_id)', $client_handler, 'Portal web submission does not dispatch its committed outbox event');
$assertContains('portalRequestDispatchAfterCommit($submission_id)', $agent_handler, 'Internal approval does not dispatch its committed outbox event');
$assertNotContains("triggerCustomAction('ticket_create'", $client_handler . $agent_handler, 'Portal handlers bypass the durable custom-action outbox');
$assertContains("'name' => 'portal_request_outbox'", $cron_registry, 'The custom-action retry worker is not registered');
$assertContains('portalRequestProcessCustomActionOutbox()', $outbox_cron, 'The registered custom-action worker does not process the durable outbox');
$assertContains('$custom_action_idempotency_key', $logging, 'Custom-action handlers are not given a stable downstream idempotency key');
$assertContains('get_included_files()', $logging, 'The durable dispatcher cannot detect an include_once no-op in a shared cron process');
$assertContains('triggerCustomAction($trigger, $ticket_id, $event_key) === false', $outbox, 'A skipped include_once hook can be acknowledged as delivered');

$assertContains('portalRequestContactHasAuditHistory($contact_id, $client_id)', $contact_handler, 'Contact hard deletion/anonymization does not preserve portal request audit rows');
$client_retention_lock = $section($service, 'function portalRequestLockClientForAuditRetention(', 'function portalRequestLockContactForAuditRetention(', 'client retention lock');
$contact_retention_lock = $section($service, 'function portalRequestLockContactForAuditRetention(', 'function portalRequestContactHasAuditHistory(', 'contact retention lock');
$assertContains('LIMIT 1 FOR UPDATE', $client_retention_lock, 'Client retention target is not row locked');
$assertOrdered($contact_retention_lock, ['portalRequestLockClientForAuditRetention($client_id)', 'LIMIT 1 FOR UPDATE'], 'Contact retention does not lock client then contact in submission-compatible order');
$contact_context = $section($service, 'function portalRequestContactContext(', 'function portalRequestContactMatchesRule(', 'portal requester lock');
$assertOrdered($contact_context, ['SELECT client_id FROM clients', 'LIMIT 1 FOR UPDATE', 'SELECT contact_id, contact_client_id'], 'Portal submission does not lock client before contact to serialize with retention');
$client_delete = $section($client_model_handler, "if (isset(\$_GET['delete_client']))", "if (isExportRequest('export_clients'))", 'client hard deletion');
$assertOrdered($client_delete, ['enforceAdminPermission()', 'enforceClientAccess($client_id)', 'Permanent client deletion is disabled by retention policy'], 'Client deletion does not fail closed before retained portal request history can be destroyed');
$assertNotContains('DELETE FROM contacts', $client_delete, 'Disabled client deletion still destroys portal requester identities');
$assertNotContains('DELETE FROM clients', $client_delete, 'Disabled client deletion still destroys the client audit boundary');
$contact_bulk_delete = $section($contact_handler, "if (isset(\$_POST['bulk_delete_contacts']))", "if (isset(\$_GET['anonymize_contact']))", 'bulk contact deletion');
$assertOrdered($contact_bulk_delete, ['portalRequestContactHasAuditHistory($contact_id, $client_id)', 'DELETE FROM users', 'DELETE FROM contacts'], 'Bulk contact audit retention is checked after destructive deletion starts');
$assertOrdered($contact_bulk_delete, ['mysqli_begin_transaction($mysqli)', 'portalRequestLockContactForAuditRetention($contact_id, $client_id)', 'portalRequestContactHasAuditHistory($contact_id, $client_id)', 'DELETE FROM contacts', 'mysqli_commit($mysqli)'], 'Bulk contact hard deletion does not hold its retention lock through a transactional delete');
$contact_anonymize = $section($contact_handler, "if (isset(\$_GET['anonymize_contact']))", "if (isset(\$_GET['archive_contact']))", 'contact anonymization');
$assertOrdered($contact_anonymize, ['portalRequestContactHasAuditHistory($contact_id, $client_id)', "contact_name = '*****'"], 'Contact audit retention is checked after anonymization starts');
$assertOrdered($contact_anonymize, ['mysqli_begin_transaction($mysqli)', 'portalRequestLockContactForAuditRetention($contact_id, $client_id)', 'portalRequestContactHasAuditHistory($contact_id, $client_id)', "contact_name = '*****'", 'mysqli_commit($mysqli)'], 'Contact anonymization does not hold its retention lock through commit');
$assertNotContains('mysqli_query(', $contact_anonymize, 'Guarded contact anonymization contains unchecked SELECT or UPDATE queries');
$assertContains('WHERE log_id = $log_id AND log_client_id = $client_id LIMIT 1', $contact_anonymize, 'Contact anonymization does not tenant-scope audit log redaction');
$assertContains('WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id', $contact_anonymize, 'Contact anonymization does not tenant-scope ticket redaction');
$assertContains('AND t.ticket_client_id = $client_id AND t.ticket_contact_id = $contact_id', $contact_anonymize, 'Contact anonymization does not tenant-scope ticket reply redaction');
$assertContains('WHERE contact_id = $contact_id AND contact_client_id = $client_id LIMIT 1', $contact_anonymize, 'Contact anonymization does not tenant-scope its final contact mutation');
$assertContains('FOR UPDATE', $contact_anonymize, 'Contact anonymization does not lock selected audit and ticket rows through commit');
if (substr_count($contact_anonymize, 'UPDATE contacts SET') !== 1) {
    $failures[] = 'Contact anonymization does not use one checked, atomic contact mutation';
}
$contact_delete = $section($contact_handler, "if (isset(\$_GET['delete_contact']))", "if (isset(\$_POST['link_contact_to_asset']))", 'contact hard deletion');
$assertOrdered($contact_delete, ['portalRequestContactHasAuditHistory($contact_id, $client_id)', 'DELETE FROM users', 'DELETE FROM contacts'], 'Contact audit retention is checked after destructive deletion starts');
$assertOrdered($contact_delete, ['mysqli_begin_transaction($mysqli)', 'portalRequestLockContactForAuditRetention($contact_id, $client_id)', 'portalRequestContactHasAuditHistory($contact_id, $client_id)', 'DELETE FROM contacts', 'mysqli_commit($mysqli)'], 'Contact hard deletion does not hold its retention lock through a transactional delete');
$assertOrdered($api_contact_delete, ['mysqli_begin_transaction($mysqli)', 'portalRequestLockContactForAuditRetention($contact_id, $client_id)', 'portalRequestContactHasAuditHistory($contact_id, $client_id)', 'DELETE FROM contacts', 'mysqli_commit($mysqli)'], 'API contact deletion can race portal request submission or bypass audit retention');
$assertContains("escapeSql((string) \$row['contact_name'])", $api_contact_delete, 'API contact deletion interpolates a raw retained contact name into audit SQL');
$assertContains('portal_request_submission_decided_by_type', $section($service, 'function portalRequestContactHasAuditHistory(', 'function portalRequestValidateResponses(', 'contact audit retention'), 'Contact audit retention ignores request approvers');
$assertContains('portal_request_submission_event_actor_type', $service, 'Contact audit retention ignores request event actors');
$contact_retention = $section($service, 'function portalRequestContactHasAuditHistory(', 'function portalRequestValidateResponses(', 'contact audit retention');
$assertContains("\$field['type'] !== 'contact'", $contact_retention, 'Contacts selected in typed request responses can be deleted from immutable audit evidence');
$assertContains('portalRequestResponsePayload($submission)', $contact_retention, 'Contact response retention trusts an unchecked response snapshot');
$assertContains('LEFT JOIN contacts c ON c.contact_id = s.portal_request_submission_contact_id', $client_status, 'Portal request detail disappears when a requester row is unavailable');
$assertContains("COALESCE(c.contact_name, 'Former contact')", $client_status, 'Portal request detail has no minimized requester fallback');
$assertContains('LEFT JOIN contacts requester ON requester.contact_id = s.portal_request_submission_contact_id', $client_requests, 'Portal approvals disappear when a requester row is unavailable');
$assertContains("COALESCE(requester.contact_name, 'Former contact')", $client_requests, 'Portal approvals have no minimized requester fallback');
$assertNotContains("CONCAT('Contact #'", $client_requests . $client_status, 'Client request pages expose durable requester IDs as fallback labels');
$assertContains('LEFT JOIN clients c ON c.client_id = s.portal_request_submission_client_id', $agent_queue, 'Agent request history disappears when a client row is unavailable');
$assertContains('LEFT JOIN contacts requester ON requester.contact_id = s.portal_request_submission_contact_id', $agent_queue, 'Agent request history disappears when a requester row is unavailable');
$assertContains("CONCAT('Client #'", $agent_queue, 'Agent request history has no safe client fallback');
$assertContains("CONCAT('Contact #'", $agent_queue, 'Agent request history has no safe requester fallback');

$assertContains('portalRequestParseInteger($raw)', $service, 'Integer responses do not use the bounded canonical parser');
$assertContains('portalRequestParseEntityId($raw)', $service, 'Entity responses do not use the canonical positive-ID parser');
$assertContains("'max_range' => 2147483647", $service, 'Integer/entity values are not explicitly bounded');
$assertNotContains('flashAlert(escapeHtml($exception->getMessage())', $client_handler, 'Portal users receive internal exception details');
$assertContains("error_log('Portal catalog request validation failed:", $client_handler, 'Generic portal validation errors are not logged server-side');

foreach (['New user', 'Employee termination', 'New device', 'Access change', 'Report an incident', 'Schedule work'] as $starter) {
    $assertContains("'$starter'", $service, "Starter catalog does not include $starter");
}
foreach (['user-onboarding', 'user-offboarding', 'device-deployment', 'access-change',
    'incident-response', 'scheduled-work'] as $runbook_key) {
    $assertContains("'runbook_key' => '$runbook_key'", $starter_content,
        "Starter content does not provide the compatible $runbook_key runbook");
}
$starter_install_at = strpos($service, 'function portalRequestInstallStarters(');
$starter_install_end = strpos($service, 'function portalRequestStarterDefinitionMap(', $starter_install_at ?: 0);
if ($starter_install_at === false || $starter_install_end === false) {
    $failures[] = 'Could not isolate the starter request installer';
} else {
    $starter_install = substr($service, $starter_install_at, $starter_install_end - $starter_install_at);
    $assertNotContains('portal_request_catalog_item_ticket_template_id =', $starter_install,
        'Draft installation bypasses compatibility reconciliation by binding a runbook');
    $assertNotContains('portal_request_catalog_item_published_version_id =', $starter_install,
        'Draft installation bypasses compatibility reconciliation by publishing a catalog release');
    $assertNotContains('portalRequestPublish(', $starter_install,
        'Starter request installation invokes catalog publication automatically');
}
$starter_reconcile_at = strpos($service, 'function portalRequestReconcileStarters(');
$starter_reconcile = $starter_reconcile_at === false ? '' : substr($service, $starter_reconcile_at);
if ($starter_reconcile === '') {
    $failures[] = 'Could not isolate starter request reconciliation';
}
$assertContains('portalRequestStarterRunbookBindings()', $starter_reconcile,
    'Starter reconciliation does not use the stable request/runbook binding registry');
$assertContains('portalRequestResolveStarterRunbook($binding)', $starter_reconcile,
    'Starter reconciliation does not prove the canonical published runbook');
$assertContains('runbookValidateDefinition($definition)', $service,
    'Starter reconciliation does not apply full runbook semantic validation');
$assertContains("(\$task['initial_state'] ?? '') === 'Waiting'", $service,
    'Starter reconciliation accepts an approval declaration that is not an explicit waiting gate');
$assertContains('portalRequestStarterDraftContractErrors(', $starter_reconcile,
    'Starter reconciliation can silently publish a drifted permission, approval, applicability or field contract');
$assertContains('SAVEPOINT portal_request_starter_item', $starter_reconcile,
    'One incompatible starter can leave a partial catalog version in the reconciliation transaction');
$assertContains('ROLLBACK TO SAVEPOINT portal_request_starter_item', $starter_reconcile,
    'Failed starter publication is not rolled back before fail-closed draft state');
$assertContains('portal_request_catalog_item_published_version_id = 0', $starter_reconcile,
    'An incompatible starter remains visible through a stale published pointer');
$assertContains('portalRequestPublish(', $starter_reconcile,
    'Compatible canonical starter drafts are not published');
$assertContains('if ($dry_run)', $starter_reconcile,
    'Starter reconciliation does not exercise rollback-only dry-run mode');
$assertNotContains('runbookLatestPublishedVersionId(', $starter_reconcile,
    'Starter reconciliation guesses a historical runbook version');
$assertNotContains('ticket_template_id = 1', $starter_reconcile,
    'Starter reconciliation contains a brittle runbook template ID');
$assertContains("\$allowed_modes = ['--dry-run', '--apply'];", $starter_reconciler,
    'The portal request reconciler lacks explicit dry-run/apply modes');
$assertContains("GET_LOCK('", $starter_reconciler,
    'Concurrent portal request reconciliation is not serialized');
$assertContains("RELEASE_LOCK('", $starter_reconciler,
    'The portal request reconciliation advisory lock is not released');
$assertContains('reconcile_portal_requests.php --dry-run', $deployment_docs,
    'Deployment docs omit the starter request preview');
$assertContains('two active portal users', $deployment_docs,
    'Deployment docs omit the two-contact Scheduled Work canary prerequisite');
$assertContains('must fail', $deployment_docs,
    'Deployment docs omit the pre-close workflow gate canary');
$assertContains('Select a published runbook', $admin_item,
    'Catalog activation does not require an operator to select a published runbook');
$assertContains('name="ticket_template_id" required', $admin_item,
    'Catalog activation does not require an operator-reviewed runbook binding');
$assertContains('INNER JOIN runbook_versions ON runbook_version_id = ticket_template_published_version_id', $admin_item,
    'Catalog binding can select an unpublished ticket-template draft');

require_once $root . '/n45/bootstrap.php';
n45RequireModule('runbooks');
n45RequireModule('portal_requests');
$starter_definitions = portalRequestStarterDefinitions();
if (count($starter_definitions) !== 6) {
    $failures[] = 'The starter installer does not expose exactly the six required request families';
}
$starter_keys = array_column($starter_definitions, 0);
if (count($starter_keys) !== count(array_unique($starter_keys))) {
    $failures[] = 'Starter request stable keys are not unique';
}
$starter_bindings = portalRequestStarterRunbookBindings();
if (array_keys($starter_bindings) !== $starter_keys || count($starter_bindings) !== 6) {
    $failures[] = 'The six starter request keys do not map one-to-one to canonical runbook identities';
}
foreach ($starter_bindings as $request_key => $binding) {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', (string) ($binding['runbook_key'] ?? ''))
        || !in_array($binding['runbook_type'] ?? '', ['standard', 'onboarding', 'offboarding'], true)) {
        $failures[] = "Starter request $request_key has an invalid stable runbook contract";
    }
}
$scheduled_index = array_search('scheduled-work', $starter_keys, true);
$scheduled_definition = $scheduled_index === false ? [] : $starter_definitions[$scheduled_index];
if (($scheduled_definition[6] ?? '') !== 'technical') {
    $failures[] = 'Scheduled Work does not require a distinct technical contact decision before ticket creation';
}
if (portalRequestDefinitionHash(['z' => 1, 'a' => ['y' => 2, 'b' => 3]])
    !== portalRequestDefinitionHash(['a' => ['b' => 3, 'y' => 2], 'z' => 1])) {
    $failures[] = 'Definition hashes are not deterministic across associative key order';
}
if (portalRequestOptions('["One","One","","Two"]') !== ['One', 'Two']) {
    $failures[] = 'Published choice normalization is not deterministic and duplicate-safe';
}
if (portalRequestParseInteger('01') !== null || portalRequestParseInteger('-0') !== null
    || portalRequestParseInteger('2147483648') !== null || portalRequestParseInteger('-42') !== -42) {
    $failures[] = 'Canonical bounded integer parsing accepts ambiguous/out-of-range values or rejects a valid value';
}
if (portalRequestParseEntityId('01') !== 0 || portalRequestParseEntityId('2147483648') !== 0
    || portalRequestParseEntityId('42') !== 42) {
    $failures[] = 'Canonical entity ID parsing accepts ambiguous/out-of-range IDs or rejects a valid ID';
}
if (portalRequestParseClientIdList('1, 2, 2') !== [1, 2]
    || portalRequestParseClientIdList('01,2') !== null
    || portalRequestParseClientIdList('2147483648') !== null) {
    $failures[] = 'Client applicability lists accept ambiguous/out-of-range IDs or do not normalize duplicates';
}
if (portalRequestStatusLabel('PendingApproval') !== 'Waiting for approval'
    || portalRequestStatusLabel('Initiated') !== 'Ticket created'
    || portalRequestStatusLabel('not-a-state') !== 'Unknown') {
    $failures[] = 'Portal request statuses are not rendered through safe user-facing labels';
}
if (portalRequestClientEventActorLabel([
        'portal_request_submission_event_actor_type' => 'agent',
        'portal_request_submission_event_action' => 'approved',
    ]) !== 'Internal support'
    || portalRequestClientEventActorLabel([
        'portal_request_submission_event_actor_type' => 'contact',
        'portal_request_submission_event_action' => 'approved',
    ]) !== 'Authorized client contact') {
    $failures[] = 'Client audit actor labels expose or misclassify internal identities';
}
$response_definition = ['fields' => [[
    'key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true,
    'options' => [], 'max_length' => 40, 'min_value' => null, 'max_value' => null,
], [
    'key' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true,
    'options' => [], 'max_length' => 1, 'min_value' => null, 'max_value' => null,
]]];
[, $invalid_phone_errors] = portalRequestValidateResponses(
    $response_definition,
    ['phone' => '.......', 'date' => '2026-09-01'],
    1,
    ['contact_id' => 1]
);
[, $valid_response_errors] = portalRequestValidateResponses(
    $response_definition,
    ['phone' => '555-555-0123', 'date' => '2026-09-01'],
    1,
    ['contact_id' => 1]
);
if (!isset($invalid_phone_errors['phone']) || isset($invalid_phone_errors['date'])
    || $valid_response_errors) {
    $failures[] = 'Typed response validation accepts digit-free phone values or applies text limits to typed dates';
}
$impossible_approval = [
    'key' => 'primary-only', 'type' => 'other', 'name' => 'Primary only',
    'icon' => 'fas fa-lock', 'permission_rule' => 'primary',
    'applicability_rule' => 'all', 'applicability_value' => '',
    'approval_rule' => 'primary', 'ticket_template_id' => 1, 'runbook_version_id' => 1,
    'fields' => [[
        'key' => 'summary', 'label' => 'Summary', 'type' => 'text',
        'options' => [], 'max_length' => 100, 'min_value' => null, 'max_value' => null,
    ]],
];
if (!in_array('A primary-only request cannot require another primary contact to approve it.',
    portalRequestValidateDefinition($impossible_approval), true)) {
    $failures[] = 'Publication validation accepts an impossible primary/primary approval route';
}
$fingerprint_definition = ['fields' => [
    ['key' => 'email', 'type' => 'email'],
    ['key' => 'asset', 'type' => 'asset'],
]];
$fingerprint = portalRequestSubmissionRequestHash(7, $fingerprint_definition, ['asset' => '42', 'email' => ' User@Example.COM ']);
if ($fingerprint !== portalRequestSubmissionRequestHash(7, $fingerprint_definition, ['email' => 'user@example.com', 'asset' => '42'])
    || $fingerprint === portalRequestSubmissionRequestHash(8, $fingerprint_definition, ['email' => 'user@example.com', 'asset' => '42'])
    || $fingerprint === portalRequestSubmissionRequestHash(7, $fingerprint_definition, ['email' => 'changed@example.com', 'asset' => '42'])) {
    $failures[] = 'Idempotency fingerprints are not canonical or are not bound to version and content';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Portal request catalog checks passed" . PHP_EOL;
