<?php

/* Source contract for immutable agreement/SLA/service-review retention. */

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
$section = static function (
    string $contents,
    string $start,
    string $end,
    string $label
) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};
$assertContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = "$message (missing '$needle')";
    }
};
$assertNotContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = "$message (found '$needle')";
    }
};
$assertOrdered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $cursor = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $cursor + 1);
        if ($position === false) {
            $failures[] = "$message (missing '$needle')";
            return;
        }
        $cursor = $position;
    }
};

$agreements = $read('functions/agreements.php');
$sla = $read('functions/sla.php');
$ticket_post = $read('agent/post/ticket.php');
$client_post = $read('agent/post/client.php');
$agreement_post = $read('agent/post/agreement.php');
$agreement_setup = $read('functions/agreement_setup.php');

$client_lock = $section(
    $agreements,
    'function agreementLockClientForAuditRetention(',
    'function agreementTicketHasAuditHistory(',
    'agreement client retention lock'
);
$assertOrdered($client_lock, [
    'agreementDbQuery(',
    'FROM clients WHERE client_id = $client_id LIMIT 1 FOR UPDATE',
], 'Agreement evidence writers do not use a checked client row lock');

$ticket_history = $section(
    $agreements,
    'function agreementTicketHasAuditHistory(',
    'function agreementClientHasAuditHistory(',
    'ticket agreement retention helper'
);
foreach ([
    'ticket_agreement_decisions',
    'sla_history',
    'ticket_sla_response_minutes_snapshot',
    'ticket_sla_resolution_minutes_snapshot',
    'ticket_sla_calendar_mode',
    'ticket_response_due_at_utc',
    'ticket_resolution_due_at_utc',
] as $evidence) {
    $assertContains($evidence, $ticket_history, "Ticket retention ignores $evidence");
}
$assertContains('agreementDbQuery(', $ticket_history,
    'Ticket retention does not fail closed when its evidence query fails');

$client_history = $section(
    $agreements,
    'function agreementClientHasAuditHistory(',
    'function agreementVersionContext(',
    'client agreement retention helper'
);
foreach ([
    'contracts',
    'agreement_versions',
    'agreement_version_events',
    'ticket_agreement_decisions',
    'service_reviews',
    'service_review_events',
    'sla_history',
    'tickets',
] as $evidence) {
    $assertContains($evidence, $client_history, "Client retention ignores $evidence");
}
$assertContains('WHERE contract_client_id = $client_id LIMIT 1', $client_history,
    'Legacy or draft agreements fall through to a generic client-delete FK failure');
$assertNotContains('contract_published_version_id > 0', $client_history,
    'Client retention only recognizes agreements with a current published pointer');
$assertContains('agreementDbQuery(', $client_history,
    'Client retention does not fail closed when its evidence query fails');

$record_decision = $section(
    $agreements,
    'function agreementRecordTicketDecision(',
    'function agreementValidateEntitlementScope(',
    'ticket agreement-decision writer'
);
$assertOrdered($record_decision, [
    'agreementLockClientForAuditRetention($decision_client_id)',
    'WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE',
    'INSERT INTO ticket_agreement_decisions',
], 'Ticket agreement decisions do not lock client then ticket before insert');

$apply_sla = $section(
    $sla,
    'function applyTicketSla(',
    'function setTicketFirstResponse(',
    'ticket SLA writer'
);
$assertOrdered($apply_sla, [
    'SELECT ticket_client_id FROM tickets',
    'agreementLockClientForAuditRetention($expected_client_id)',
    'SELECT tickets.ticket_id, ticket_client_id',
    'LIMIT 1 FOR UPDATE',
    'agreementRecordTicketDecision($ticket_id, $agreement_decision)',
], 'SLA stamping does not use client -> ticket -> immutable decision order');
$assertContains('$client_id !== $expected_client_id', $apply_sla,
    'SLA stamping does not revalidate ticket ownership after taking its locks');

$publish_version = $section(
    $agreements,
    'function agreementPublishVersion(',
    'function agreementCreateDraftFromPublished(',
    'agreement publication writer'
);
$assertOrdered($publish_version, [
    'mysqli_begin_transaction($mysqli)',
    'agreementLockClientForAuditRetention($client_id)',
    'LIMIT 1 FOR UPDATE',
    'INSERT INTO agreement_version_events',
    'mysqli_commit($mysqli)',
], 'Agreement publication does not retain its client lock through immutable event commit');

$draft_version = $section(
    $agreements,
    'function agreementCreateDraftFromPublished(',
    'function agreementPercent(',
    'agreement draft-event writer'
);
$assertOrdered($draft_version, [
    'mysqli_begin_transaction($mysqli)',
    'agreementLockClientForAuditRetention($client_id)',
    'LIMIT 1 FOR UPDATE',
    'INSERT INTO agreement_version_events',
    'mysqli_commit($mysqli)',
], 'Agreement draft events do not retain their client lock through commit');

$generate_review = $section(
    $agreements,
    'function agreementGenerateServiceReview(',
    'function agreementValidateServiceReviewSnapshot(',
    'service-review generation writer'
);
$assertOrdered($generate_review, [
    'mysqli_begin_transaction($mysqli',
    'agreementLockClientForAuditRetention($client_id)',
    'INSERT INTO service_reviews',
    'INSERT INTO service_review_events',
    'mysqli_commit($mysqli)',
], 'Service-review generation can race client deletion');

$publish_review = $section(
    $agreements,
    'function agreementPublishServiceReview(',
    'function agreementGenerateDueServiceReviews(',
    'service-review publication writer'
);
$assertOrdered($publish_review, [
    'mysqli_begin_transaction($mysqli)',
    'agreementLockClientForAuditRetention($client_id)',
    'FROM service_reviews WHERE service_review_id = $review_id LIMIT 1 FOR UPDATE',
    'INSERT INTO service_review_events',
    'mysqli_commit($mysqli)',
], 'Service-review publication can race client deletion');

$draft_mutator = $section(
    $agreement_post,
    '$agreement_mutate_draft = static function',
    '$return_url =',
    'agreement draft mutator'
);
$assertOrdered($draft_mutator, [
    'mysqli_begin_transaction($mysqli)',
    'agreementLockClientForAuditRetention($client_id)',
    'WHERE contract_id = $contract_id LIMIT 1 FOR UPDATE',
    'agreementVersionContext($version_id, true)',
    'mysqli_commit($mysqli)',
], 'Agreement draft mutation does not use client -> contract -> version lock order');

$add_agreement = $section(
    $agreement_post,
    "if (\$agreement_action === 'add_agreement')",
    "if (\$agreement_action === 'edit_agreement_draft')",
    'agreement creation writer'
);
$assertOrdered($add_agreement, [
    'enforceClientAccess($client_id)',
    'agreementCreateFromSetup($_POST, $client_id, intval($session_user_id))',
], 'Agreement setup must enforce client access before invoking the retained writer');
$assertOrdered($agreement_setup, [
    'mysqli_begin_transaction($mysqli)',
    'agreementLockClientForAuditRetention($client_id)',
    "agreementSetupInsert('contracts'",
    "agreementSetupInsert('agreement_versions'",
    "agreementSetupInsert('agreement_entitlements'",
    "agreementSetupInsert('agreement_sla_rules'",
    "agreementSetupInsert('agreement_version_events'",
    'mysqli_commit($mysqli)',
], 'Agreement creation reads a client outside its retained write lock');
$assertContains('mysqli_rollback($mysqli)', $agreement_setup, 'Incomplete agreement setup must roll back all definition rows');

$single_delete = $section(
    $ticket_post,
    "if (isset(\$_GET['delete_ticket']))",
    "if (isset(\$_POST['bulk_delete_tickets']))",
    'single ticket deletion'
);
$assertOrdered($single_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClientTicket($ticket_id, $client_id, true)',
    'agreementTicketHasAuditHistory($ticket_id, $client_id)',
    'DELETE FROM tickets WHERE ticket_id = $ticket_id',
    'mysqli_commit($mysqli)',
], 'Single ticket deletion checks agreement evidence outside its retained locks');
$assertContains('Close the ticket to preserve its audit trail', $single_delete,
    'Single ticket retention does not give an actionable close alternative');

$bulk_delete = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_delete_tickets']))",
    "if (isset(\$_POST['bulk_assign_ticket']))",
    'bulk ticket deletion'
);
$assertOrdered($bulk_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClientTicket($ticket_id, $client_id, true)',
    'agreementTicketHasAuditHistory($ticket_id, $client_id)',
    'DELETE FROM tickets WHERE ticket_id = $ticket_id',
    'mysqli_commit($mysqli)',
], 'Bulk ticket deletion checks agreement evidence outside its retained locks');
$assertContains('workflow, approval, documentation, agreement, or SLA evidence', $bulk_delete,
    'Bulk ticket retention does not explain why selected tickets were kept');

$client_delete = $section(
    $client_post,
    "if (isset(\$_GET['delete_client']))",
    "if (isExportRequest('export_clients'))",
    'client deletion'
);
$assertOrdered($client_delete, [
    'mysqli_begin_transaction($mysqli)',
    'portalRequestLockClientForAuditRetention($client_id)',
    'agreementClientHasAuditHistory($client_id)',
    'DELETE FROM clients WHERE client_id = $client_id',
    'mysqli_commit($mysqli)',
], 'Client deletion checks agreement evidence outside its retained client lock');
$assertContains('Archive the client to preserve its audit trail', $client_delete,
    'Client agreement retention does not give an actionable archive alternative');
$assertContains('service-review', $client_delete,
    'Client retention message does not identify service-review evidence');

if ($failures) {
    fwrite(STDERR, "Agreement retention contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Agreement retention contract passed\n";
