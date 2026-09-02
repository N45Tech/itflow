<?php

$failures = [];
$root = dirname(__DIR__);

$assert = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};
$throws = static function (callable $callable, string $message) use (&$failures): void {
    try {
        $callable();
        $failures[] = $message;
    } catch (InvalidArgumentException | DomainException $expected) {
    }
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Could not read $path");
    }
    return $contents;
};

require_once $root . '/functions/ticket_operations.php';

$expected_matrix = [
    'low' => ['low' => 'Low', 'medium' => 'Low', 'high' => 'Medium', 'critical' => 'High'],
    'medium' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'High'],
    'high' => ['low' => 'Medium', 'medium' => 'High', 'high' => 'High', 'critical' => 'Urgent'],
    'critical' => ['low' => 'High', 'medium' => 'High', 'high' => 'Urgent', 'critical' => 'Urgent'],
];
foreach ($expected_matrix as $impact => $row) {
    foreach ($row as $urgency => $priority) {
        $assert(ticketOperationalPriorityFromImpactUrgency($impact, $urgency) === $priority,
            "Priority matrix mismatch for $impact/$urgency");
    }
}
$assert(array_keys(ticketOperationalWorkTypes()) === [
    'incident', 'request', 'problem', 'change', 'onboarding', 'project_task',
], 'Typed-work taxonomy changed');
$assert(array_keys(ticketOperationalWaitingOnDefinitions()) === [
    'none', 'customer', 'vendor', 'internal', 'third_party',
], 'Waiting-on taxonomy changed');
$assert(array_keys(ticketOperationalRelationshipTypes()) === ['parent_child', 'duplicate', 'related'],
    'Relationship taxonomy changed');
$assert(array_keys(ticketOperationalPromiseTypes()) === ['customer_update', 'target_completion'],
    'Promise taxonomy changed');
$assert(array_keys(ticketOperationalResolutionCodes()) === [
    'fixed', 'fulfilled', 'workaround', 'monitor_recovered', 'customer_confirmed',
    'duplicate', 'cancelled', 'no_fault_found', 'transferred',
], 'Resolution taxonomy changed');

$valid = ticketOperationalInput([
    'work_type' => 'incident', 'impact' => 'critical', 'urgency' => 'high',
    'next_action' => 'Restore the affected service.', 'waiting_on' => 'vendor',
    'waiting_on_detail' => 'Carrier escalation 123',
]);
$assert($valid['priority'] === 'Urgent' && $valid['waiting_on'] === 'vendor',
    'Operational input did not derive canonical state');
$throws(static fn () => ticketOperationalInput([
    'impact' => 'low', 'urgency' => 'low', 'next_action' => '', 'waiting_on' => 'none',
]), 'Blank next action was accepted');
$throws(static fn () => ticketOperationalInput([
    'impact' => 'low', 'urgency' => 'low', 'next_action' => 'Follow up', 'waiting_on' => 'customer',
]), 'Waiting state without detail was accepted');
$throws(static fn () => ticketOperationalInput([
    'impact' => 'extreme', 'urgency' => 'low', 'next_action' => 'Follow up', 'waiting_on' => 'none',
]), 'Unknown impact was accepted');

$assert(ticketOperationalTicketIsImmutable(['ticket_status' => 4]), 'Resolved ticket is mutable');
$assert(ticketOperationalTicketIsImmutable(['ticket_status' => 2, 'ticket_closed_at' => '2026-01-01']),
    'Closed ticket is mutable');
$assert(ticketOperationalTicketIsImmutable(['ticket_status' => 2, 'ticket_operational_deleted_at' => '2026-01-01']),
    'Soft-deleted ticket is mutable');
$assert(!ticketOperationalTicketIsImmutable(['ticket_status' => 2]), 'Open ticket is immutable');

$trusted_google = "Authentication-Results: mx.google.com; dkim=pass header.d=example.com; "
    . "spf=pass smtp.mailfrom=sender@example.com; dmarc=pass header.from=example.com\r\n"
    . "From: Sender <sender@example.com>\r\n\r\nBody";
$assert(ticketEmailTrustedAuthentication($trusted_google, 'google_oauth', 'imap.gmail.com', 'example.com'),
    'Trusted aligned Google result was rejected');
$forged_first = "Authentication-Results: attacker.invalid; dkim=pass header.d=example.com; "
    . "dmarc=pass header.from=example.com\r\n" . $trusted_google;
$assert(!ticketEmailTrustedAuthentication($forged_first, 'google_oauth', 'imap.gmail.com', 'example.com'),
    'A later sender-injected Authentication-Results field was trusted');
$assert(!ticketEmailTrustedAuthentication($trusted_google, 'standard_imap', 'mail.example.net', 'example.com'),
    'An unconfigured custom gateway was trusted');
$assert(!ticketEmailTrustedAuthentication(str_replace('header.from=example.com', 'header.from=evil.test', $trusted_google),
    'google_oauth', 'imap.gmail.com', 'example.com'), 'DMARC header.from misalignment was accepted');

$hash_a = ticketEmailIngressFingerprint('<same@example>', 'a@example.com', 'Subject', '2026-01-01', str_repeat('a', 64));
$hash_b = ticketEmailIngressFingerprint('<same@example>', 'b@example.com', 'Subject', '2026-01-01', str_repeat('a', 64));
$hash_c = ticketEmailIngressFingerprint('<same@example>', 'a@example.com', 'Subject', '2026-01-01', str_repeat('b', 64));
$assert($hash_a !== $hash_b && $hash_a !== $hash_c, 'Message-ID can poison dedupe across sender or content');
$dsn = "Final-Recipient: rfc822; failed@example.com\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; user unknown\r\n";
$assert(ticketEmailStructuredDsn($dsn)['status'] === '5.1.1', 'Structured DSN was not parsed');
$assert(ticketEmailStructuredDsn(str_replace('Action: failed', 'Action: delayed', $dsn)) === null,
    'Non-failure delivery report was accepted as an NDR');
$assert(ticketEmailAttachmentAllowed('proof.pdf', 'application/pdf', 4, '%PDF'),
    'Safe attachment was rejected');
$assert(!ticketEmailAttachmentAllowed('../proof.pdf', 'application/pdf', 4, '%PDF'),
    'Traversal attachment name was accepted');
$assert(!ticketEmailAttachmentAllowed('proof.exe', 'application/octet-stream', 4, 'MZ00'),
    'Executable attachment was accepted');
$assert(!ticketEmailAttachmentAllowed('proof.pdf', 'application/pdf', 15728641),
    'Oversized attachment was accepted');

$model = $read('api/v1/tickets/ticket_model.php');
$api_create = $read('api/v1/tickets/create.php');
$api_reply = $read('api/v1/ticket_replies/create.php');
$api_resolve = $read('api/v1/tickets/resolve.php');
$operations = $read('functions/ticket_operations.php');
$parser = $read('cron/ticket_email_parser.php');
$runbooks = $read('functions/runbooks.php');
$sla_cron = $read('cron/ticket_sla.php');
$migration = $read('n45/migrations/n45-0016-ticket-operational-discipline.php');
$schema = $read('db.sql');
$manifest = $read('n45/manifest.php');
$database_assert = $read('tests/ticket_operational_database_assert.php');

$assert(!str_contains($model, 'ticketOperationalLegacyDimensionsForPriority($legacy_priority)'),
    'API still back-maps direct priority into dimensions');
$assert(str_contains($model, 'ticket_priority cannot be supplied without ticket_impact and ticket_urgency')
    && str_contains($model, 'ticket_priority conflicts with ticket_impact and ticket_urgency'),
    'API does not reject priority-only or conflicting priority');
$assert(str_contains($api_create, 'contact_client_id = $client_id')
    && str_contains($api_create, 'asset_client_id = $client_id')
    && str_contains($api_create, 'vendor_client_id = $client_id')
    && str_contains($api_create, 'documentationAgentHasSupportLevel($assigned_to, 1)'),
    'API create lacks tenant-safe child references or active support assignee validation');
foreach ([$api_create, $api_reply, $api_resolve] as $api_source) {
    $assert(str_contains($api_source, 'validate_api_key.php'),
        'Ticket API endpoint lacks API-key bootstrap');
}
$assert(str_contains($api_reply, 'apiClientScopeSql') || str_contains($api_reply, 'ticket_client_id = $client_id'),
    'Reply API lacks client scope');
$assert(str_contains($api_resolve, 'apiClientScopeSql') || str_contains($api_resolve, 'ticket_client_id = $client_id'),
    'Resolve API lacks client scope');

$assert(str_contains($operations, "ticket['ticket_work_type'] === 'problem'")
    && str_contains($operations, "ticket['ticket_resolution_code'] === 'duplicate'")
    && str_contains($operations, "ticket['ticket_waiting_on'] !== 'none'"),
    'Composite resolution/root-cause/duplicate/waiting gates are incomplete');
$assert(str_contains($runbooks, 'ticketOperationalCanResolve(')
    && str_contains($runbooks, 'boolval($include_documentation_detail)'),
    'Operational evidence is not composed into the closure gate');
$assert(str_contains($operations, 'Tickets from different clients cannot be related')
    && str_contains($operations, 'Two different tickets are required')
    && str_contains($operations, 'ticketOperationalWouldCreateParentCycle'),
    'Relationship client/self/cycle guards are incomplete');
$assert(str_contains($operations, 'ticketOperationalTicketHasImmutableHistory')
    && str_contains($operations, 'ticket_email_ingress_ticket_id = $ticket_id'),
    'Deletion/transfer immutable-history guard omits operational evidence');
$assert(str_contains($operations, '$source_identity = $source_id > 0')
    && str_contains($operations, "ticket_customer_promise_status = 'Breached'")
    && str_contains($operations, "INNER JOIN tickets ON ticket_id = ticket_customer_promise_ticket_id")
    && str_contains($operations, "ticketOperationalActiveTicketSql('tickets')"),
    'Promise idempotency, escalation, or active-ticket reconciliation is incomplete');
$assert(str_contains($operations, 'function ticketOperationalFulfillPromisesLocked(')
    && str_contains($operations, 'Promises can only be fulfilled on active tickets'),
    'Public promise fulfillment lacks an active-ticket guard');
$assert(str_contains($operations, "'[Deleted ticket unavailable]'")
    && str_contains($operations, 'source.ticket_deleted_at IS NULL')
    && str_contains($operations, 'AS source_available'),
    'Relationship rendering can leak a soft-deleted subject');

$assert(str_contains($parser, 'ticketEmailTrustedAuthentication(')
    && !str_contains($parser, "header('Authentication-Results')"),
    'Parser trusts an unbound Authentication-Results value');
$assert(!preg_match('/addReply\([^;]+true\s*\)/s', $parser)
    && str_contains($parser, 'no ticket was changed'),
    'NDR processing can still create a privileged system reply');
$assert(str_contains($parser, 'ticketEmailIngressComplete($ingress_id, $ingress_token')
    && strpos($parser, 'ticketEmailIngressComplete($ingress_id, $ingress_token')
        < strpos($parser, 'mysqli_commit($mysqli)'),
    'Ingress finalization is not atomic with ticket creation');
$assert(str_contains($operations, "ticket_email_ingress_claim_token = '\$claim_token_sql'")
    && str_contains($operations, "ticket_email_ingress_claim_token = '\$prior_token_sql'")
    && str_contains($operations, "AND ticket_email_ingress_claim_token = '\$claim_token_sql'"),
    'Ingress ownership is not inserted, rotated, and compare-and-set on completion');
$assert(str_contains($operations, 'global_rate_limit') && str_contains($operations, 'client_rate_limit')
    && str_contains($operations, 'sender_rate_limit') && str_contains($operations, 'domain_rate_limit')
    && str_contains($operations, 'ticket_email_ingress_client_id IN (0, $client_id)'),
    'Inbound sliding-window rate limits are incomplete');
$assert(!str_contains($parser, "uploads/tmp/") && str_contains($parser, 'Persist the original only after a ticket exists'),
    'Parser still leaks unauthenticated .eml files to temporary storage');
$assert(str_contains($parser, "['Processed', 'Rejected']")
    && str_contains($parser, 'if (in_array((string) $claim[\'status\']'),
    'In-progress duplicates are marked or moved before safe retry');
$assert(!str_contains($parser, 'ticketOperationalFulfillPromisesLocked('),
    'An inbound customer email incorrectly fulfills the provider customer-update promise');
$remove_relationship_offset = strpos($operations, 'function ticketOperationalRemoveRelationship(');
$remove_relationship_source = $remove_relationship_offset === false
    ? '' : substr($operations, $remove_relationship_offset, 9000);
$remove_lock_order_offset = strpos($remove_relationship_source, '// Match retention/purge ordering');
$remove_lock_order_source = $remove_lock_order_offset === false
    ? '' : substr($remove_relationship_source, $remove_lock_order_offset);
$assert($remove_lock_order_source !== ''
    && strpos($remove_lock_order_source, 'FROM tickets')
        < strpos($remove_lock_order_source, 'FROM ticket_relationships'),
    'Relationship removal does not lock client and tickets before the dependent relationship row');

$assert(str_contains($sla_cron, 'COALESCE(ticket_status_pauses_sla, 0) = 1')
    && str_contains($sla_cron, 'sla_history_ended_at IS NULL')
    && str_contains($sla_cron, 'syncTicketSlaClock'),
    'Paused/resolved/closed SLA intervals are not reconciled');

foreach ([
    'ticket_operational_events_bu_immutable',
    'ticket_operational_events_bd_immutable',
    'ticket_customer_promise_events_bu_immutable',
    'ticket_customer_promise_events_bd_immutable',
] as $trigger_name) {
    $assert(str_contains($migration, $trigger_name)
        && str_contains($schema, $trigger_name)
        && str_contains($manifest, $trigger_name)
        && str_contains($database_assert, $trigger_name),
        "Immutable ledger trigger $trigger_name lacks migration, baseline, drift, or release coverage");
}
$assert(str_contains($migration, "SIGNAL SQLSTATE '45000'")
    && str_contains($database_assert, 'action_statement'),
    'Append-only ledger enforcement lacks exact database assertion coverage');

if ($failures) {
    fwrite(STDERR, "Ticket operational discipline test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ticket operational discipline test passed.\n";
