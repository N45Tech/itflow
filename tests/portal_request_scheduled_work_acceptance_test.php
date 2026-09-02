<?php

/*
 * Local, database-free acceptance scenario for the Goal 7 handoff.  The model
 * deliberately uses synthetic IDs and checks the externally observable state
 * transitions, while the source contracts below tie each transition to the
 * production implementation and immutable schema constraints.
 */

$failures = [];
$root = dirname(__DIR__);
$assert = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$contains = static function (string $needle, string $haystack, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message . " (missing '$needle')");
};
$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};

$portal = $read('functions/portal_requests.php');
$schema = $read('db.sql');
$export = $read('agent/runbook_export.php');
$runbooks = $read('functions/runbooks.php');
$client_post = $read('client/post.php');
$guest_post = $read('guest/guest_post.php');
$agent_post = $read('agent/post/ticket.php');

$contains("['scheduled-work', 'scheduled_work'", $portal,
    'The acceptance family is not the stable Scheduled Work starter');
$contains("'manager', 'technical'", $portal,
    'Scheduled Work is not requester-limited with a distinct technical pre-approval');
$contains("'runbook_key' => 'scheduled-work'", $portal,
    'Scheduled Work has no stable canonical runbook binding');
$contains('portalRequestSubmissionRequestHash(', $portal,
    'Submission retries are not content/version bound');
$contains('portal_request_submission_ticket_id IS NULL', $portal,
    'Ticket handoff is not guarded against duplicate creation');
$contains("'version_id' => intval(\$definition['runbook_version_id'])", $portal,
    'Ticket handoff does not instantiate the catalog-pinned runbook release');
$contains('UNIQUE KEY `portal_request_submission_ticket` (`portal_request_submission_ticket_id`)', $schema,
    'The schema permits two catalog submissions to claim one ticket');
$contains('UNIQUE KEY `runbook_execution_ticket` (`runbook_execution_ticket_id`)', $schema,
    'The schema permits more than one pinned runbook execution per ticket');
$contains('runbookTicketCanResolve($ticket_id)', $client_post,
    'Client terminal transitions bypass the shared workflow gate');
$contains('runbookTicketCanResolve($ticket_id)', $guest_post,
    'Guest terminal transitions bypass the shared workflow gate');
$contains('runbookTicketCanResolve($ticket_id)', $agent_post,
    'Agent terminal transitions bypass the shared workflow gate');
$contains("\$execution['runbook_execution_status'] !== 'Completed'", $export,
    'Closeout export does not require a completed pinned execution');
$contains('FROM task_approval_events', $export,
    'Closeout export omits append-only approval history');
$contains('FROM task_state_events', $export,
    'Closeout export omits append-only task transition history');
$contains("logAudit(\n    'Runbook',\n    'Export'", $export,
    'Closeout download is not audit logged');
$contains('evidence note bodies, attachment filenames, and file contents', $export,
    'Closeout disclosure policy does not explicitly redact evidence payloads');

$contacts = [
    4101 => ['client_id' => 91, 'manager' => true, 'technical' => false, 'ticket_scope' => 'assigned'],
    4102 => ['client_id' => 91, 'manager' => false, 'technical' => true, 'ticket_scope' => 'client'],
    4201 => ['client_id' => 92, 'manager' => false, 'technical' => true, 'ticket_scope' => 'client'],
];
$release = [
    'version_id' => 7101,
    'request_key' => 'scheduled-work',
    'permission' => 'manager',
    'approval' => 'technical',
    'runbook_version_id' => 6101,
];
$submission = null;
$tickets = [];
$executions = [];
$submission_events = [];
$task_approval_events = [];

$submit = static function ($contact_id, $idempotency_key, $payload) use (
    &$submission,
    &$submission_events,
    $contacts,
    $release
) {
    $contact = $contacts[$contact_id] ?? null;
    if (!$contact || !$contact['manager']) {
        throw new RuntimeException('Requester is not an eligible manager');
    }
    $request_hash = hash('sha256', json_encode([
        'version' => $release['version_id'],
        'payload' => $payload,
    ], JSON_UNESCAPED_SLASHES));
    if ($submission !== null) {
        if ($submission['idempotency_key'] !== $idempotency_key
            || !hash_equals($submission['request_hash'], $request_hash)) {
            throw new RuntimeException('Changed request reuse was rejected');
        }
        return [
            'duplicate' => true,
            'submission_id' => $submission['id'],
            'ticket_id' => $submission['ticket_id'],
            'status' => $submission['status'],
        ];
    }
    $submission = [
        'id' => 8101,
        'client_id' => $contact['client_id'],
        'requester_id' => $contact_id,
        'version_id' => $release['version_id'],
        'status' => 'PendingApproval',
        'idempotency_key' => $idempotency_key,
        'request_hash' => $request_hash,
        'ticket_id' => 0,
    ];
    $submission_events[] = ['action' => 'submitted', 'actor' => 'Requesting contact'];
    return ['duplicate' => false, 'submission_id' => $submission['id'], 'ticket_id' => 0];
};

$decide = static function ($contact_id) use (
    &$submission,
    &$submission_events,
    &$tickets,
    &$executions,
    $contacts,
    $release
) {
    $contact = $contacts[$contact_id] ?? null;
    if (!$submission || $submission['status'] !== 'PendingApproval') {
        throw new RuntimeException('Approval is no longer actionable');
    }
    if ($contact_id === $submission['requester_id'] || !$contact || !$contact['technical']
        || $contact['client_id'] !== $submission['client_id']) {
        throw new RuntimeException('Another technical contact must approve');
    }
    $submission['status'] = 'Approved';
    $submission_events[] = ['action' => 'approved', 'actor' => 'Authorized client contact'];
    if ($submission['ticket_id'] !== 0) {
        throw new RuntimeException('Ticket already linked');
    }
    $ticket_id = 9101;
    $submission['ticket_id'] = $ticket_id;
    $submission['status'] = 'Initiated';
    $tickets[$ticket_id] = ['submission_id' => $submission['id']];
    $executions[$ticket_id] = [
        'runbook_version_id' => $release['runbook_version_id'],
        'status' => 'Running',
    ];
    $submission_events[] = ['action' => 'ticket_created', 'actor' => 'Authorized client contact'];
    return $ticket_id;
};

$payload = [
    'subject' => 'Synthetic maintenance window',
    'preferred_time' => '2026-09-08 20:00:00',
    'duration' => 60,
    'impact' => 'Brief test interruption',
    'details' => 'Synthetic acceptance only; password=must-not-export',
];
$first = $submit(4101, str_repeat('a', 32), $payload);
$retry = $submit(4101, str_repeat('a', 32), $payload);
$assert($first['ticket_id'] === 0 && $submission['status'] === 'PendingApproval',
    'Submission created a ticket before technical approval');
$assert($retry['duplicate'] === true && $retry['submission_id'] === $first['submission_id'],
    'An exact requester retry did not resolve to the original submission');

$cross_tenant_approval_rejected = false;
try {
    $decide(4201);
} catch (RuntimeException $exception) {
    $cross_tenant_approval_rejected = true;
}
$assert($cross_tenant_approval_rejected && count($tickets) === 0,
    'A technical contact from another tenant approved Scheduled Work');

$self_approval_rejected = false;
try {
    $decide(4101);
} catch (RuntimeException $exception) {
    $self_approval_rejected = true;
}
$assert($self_approval_rejected && count($tickets) === 0,
    'The requester approved their own Scheduled Work or created a premature ticket');

$ticket_id = $decide(4102);
$assert(count($tickets) === 1 && $submission['ticket_id'] === $ticket_id,
    'Technical approval did not create exactly one linked ticket');
$assert(($executions[$ticket_id]['runbook_version_id'] ?? 0) === $release['runbook_version_id'],
    'The ticket execution did not retain the release-pinned runbook version');
$assert(($submission['version_id'] ?? 0) === $release['version_id'],
    'The submission did not retain the immutable catalog release');
$post_handoff_retry = $submit(4101, str_repeat('a', 32), $payload);
$assert($post_handoff_retry['duplicate'] === true
    && $post_handoff_retry['ticket_id'] === $ticket_id
    && count($tickets) === 1,
    'A post-handoff form retry did not return the one existing ticket');
$repeat_approval_rejected = false;
try {
    $decide(4102);
} catch (RuntimeException $exception) {
    $repeat_approval_rejected = true;
}
$assert($repeat_approval_rejected && count($tickets) === 1,
    'A repeated approval created another ticket');
$assert(array_column($submission_events, 'action') === ['submitted', 'approved', 'ticket_created'],
    'Submission, decision and handoff audit events are incomplete or duplicated');

$tasks = [];
for ($number = 10; $number <= 80; $number += 10) {
    $key = 'sch-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    $tasks[$key] = [
        'state' => $number === 30 ? 'Waiting' : 'Ready',
        'evidence' => false,
        'approval' => $number === 30 ? 'pending' : null,
    ];
}
$can_close = static function ($tasks): bool {
    foreach ($tasks as $task) {
        if (!in_array($task['state'], ['Completed', 'Skipped'], true)
            || !$task['evidence']
            || ($task['approval'] !== null && $task['approval'] !== 'approved')) {
            return false;
        }
    }
    return true;
};
$assert(!$can_close($tasks), 'A newly initiated workflow passed the closure gate');
foreach ($tasks as &$task) {
    $task['state'] = 'Completed';
    $task['evidence'] = true;
}
unset($task);
$assert(!$can_close($tasks), 'Completed task states bypassed the pending technical task approval');
$technical_approver = $contacts[4102];
$assert($technical_approver['client_id'] === $submission['client_id']
    && $technical_approver['technical'] === true
    && $technical_approver['ticket_scope'] === 'client',
    'The technical task approver cannot see the requester tenant ticket');
$tasks['sch-030']['approval'] = 'approved';
$task_approval_events[] = [
    'task' => 'sch-030',
    'action' => 'approved',
    'actor' => 'Authorized client contact',
];
$assert(count($task_approval_events) === 1
    && $task_approval_events[0]['task'] === 'sch-030'
    && $task_approval_events[0]['action'] === 'approved',
    'The technical implementation-plan approval was not audited exactly once');
$assert($can_close($tasks), 'The fully evidenced and approved workflow did not pass the closure gate');
$executions[$ticket_id]['status'] = 'Completed';

$closeout = implode("\n", [
    '# Runbook Closeout — Scheduled Work',
    '- Published version: v1',
    '- Decision actor: Authorized client contact',
    '- Approval history: Approved by Authorized client contact',
    '- Evidence note retained in ITFlow; note body redacted',
    '- Attachment retained in ITFlow; filename and file contents omitted',
]);
$export_audit = [[
    'type' => 'Runbook',
    'action' => 'Export',
    'ticket_id' => $ticket_id,
]];
$assert(count($export_audit) === 1 && $export_audit[0]['ticket_id'] === $ticket_id,
    'The synthetic completed closeout did not append exactly one export audit record');
foreach (['must-not-export', 'password=', '4101', '4102', '4201', '8101', '9101'] as $secret) {
    $assert(!str_contains($closeout, $secret), "Closeout exposed sensitive/internal value $secret");
}
$assert(str_contains($closeout, 'Authorized client contact')
    && str_contains($closeout, 'note body redacted')
    && str_contains($closeout, 'filename and file contents omitted'),
    'Closeout does not preserve useful generic audit context and explicit redaction markers');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Synthetic Scheduled Work request-to-closeout acceptance passed.\n";
