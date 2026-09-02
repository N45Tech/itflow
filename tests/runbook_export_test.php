<?php

$failures = [];
$root = dirname(__DIR__);
$export_path = $root . '/agent/runbook_export.php';
$ticket_path = $root . '/agent/ticket.php';

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$export = @file_get_contents($export_path);
$ticket = @file_get_contents($ticket_path);
if ($export === false) {
    $failures[] = 'The authenticated runbook export endpoint does not exist';
    $export = '';
}
if ($ticket === false) {
    $failures[] = 'Could not read agent/ticket.php';
    $ticket = '';
}

// Load only side-effect-free formatter functions from the endpoint. Tokenizing
// avoids requiring the endpoint and therefore avoids config, auth and a DB.
$extractFunction = function (string $name) use ($export, &$failures): string {
    $tokens = token_get_all($export);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

        $function_name = '';
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                $function_name = $tokens[$cursor][1];
                break;
            }
            if ($tokens[$cursor] === '(') {
                break;
            }
        }
        if ($function_name !== $name) {
            continue;
        }

        $definition = '';
        $body_started = false;
        $depth = 0;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $piece = is_array($token) ? $token[1] : $token;
            $definition .= $piece;
            if (!is_array($token) && $token === '{') {
                $body_started = true;
                $depth++;
            } elseif (!is_array($token) && $token === '}' && $body_started) {
                $depth--;
                if ($depth === 0) {
                    return $definition;
                }
            }
        }
    }

    $failures[] = "Could not isolate pure export helper $name";
    return '';
};

$pure_helpers = [
    'runbookExportRedactSecrets',
    'runbookExportText',
    'runbookExportCode',
    'runbookExportUrlReference',
    'runbookExportEvidenceReference',
    'runbookExportEvidenceQualifies',
    'runbookExportApprovalRecipient',
    'runbookExportDecisionActor',
    'runbookExportApprovalEventAction',
    'runbookExportApprovalStatus',
    'runbookExportApprovalRoute',
    'runbookExportApprovalEventActorType',
    'runbookExportApprovalEventActorLabel',
    'runbookExportApprovalHistoryConsistent',
    'runbookExportStateActor',
];
foreach ($pure_helpers as $helper) {
    $definition = $extractFunction($helper);
    if ($definition !== '' && !function_exists($helper)) {
        eval($definition);
    }
}

if (function_exists('runbookExportUrlReference')) {
    $safe_url = runbookExportUrlReference(
        'https://user:password@Example.COM:8443/private/path?access_token=secret-value#credential'
    );
    $assertSame(
        'External URL retained in ITFlow; URL details omitted',
        $safe_url,
        'URL evidence retained client or credential-bearing URL details'
    );
    foreach (['example.com', 'user', 'password', 'private', 'access_token', 'secret-value', 'credential'] as $secret) {
        $assertNotContains($secret, $safe_url, "URL evidence exposed $secret");
    }
    $assertSame(
        'External URL retained in ITFlow; URL details omitted',
        runbookExportUrlReference('javascript:alert(1)'),
        'A non-HTTP evidence URL was rendered'
    );
    $assertSame(
        'External URL retained in ITFlow; URL details omitted',
        runbookExportUrlReference('not a URL'),
        'A malformed evidence URL was rendered'
    );
}

if (function_exists('runbookExportEvidenceReference')) {
    $file_reference = runbookExportEvidenceReference([
        'task_evidence_type' => 'file',
        'ticket_attachment_name' => '../../private/client-proof.pdf',
    ]);
    $assertNotContains('client-proof.pdf', $file_reference, 'Attachment evidence exposed a potentially sensitive filename');
    $assertNotContains('../', $file_reference, 'Attachment evidence retained a filesystem path');
    $assertContains('filename', $file_reference, 'Attachment evidence does not explain filename omission');
    $assertContains('file contents omitted', $file_reference, 'Attachment evidence does not explain content omission');
    $assertSame(
        'Evidence note retained in ITFlow; note body redacted',
        runbookExportEvidenceReference(['task_evidence_type' => 'note', 'task_evidence_note' => 'password=secret']),
        'Evidence note bodies can enter an export'
    );
}

if (function_exists('runbookExportEvidenceQualifies')) {
    $assertTrue(
        runbookExportEvidenceQualifies([
            'task_evidence_type' => 'note',
            'task_evidence_has_value' => 1,
        ], 'note'),
        'A retained non-empty note does not satisfy note evidence integrity'
    );
    $assertTrue(
        !runbookExportEvidenceQualifies([
            'task_evidence_type' => 'note',
            'task_evidence_has_value' => 0,
        ], 'note'),
        'An empty note satisfies note evidence integrity'
    );
    $assertTrue(
        runbookExportEvidenceQualifies([
            'task_evidence_type' => 'file',
            'task_evidence_attachment_present' => 1,
        ], 'any'),
        'A retained same-ticket attachment does not satisfy any-evidence integrity'
    );
    $assertTrue(
        !runbookExportEvidenceQualifies([
            'task_evidence_type' => 'approval_audit',
            'task_evidence_has_value' => 1,
        ], 'any'),
        'An approval audit record incorrectly satisfies a task evidence rule'
    );
}

if (function_exists('runbookExportText')) {
    $redacted = runbookExportText('token=never-export-this <script>');
    $assertNotContains('never-export-this', $redacted, 'Generic export text leaked a token value');
    $assertContains('REDACTED', $redacted, 'Generic export text did not mark a redacted secret');
    $assertNotContains('<script>', $redacted, 'Generic export text can inject HTML');

    $secret_cases = [
        ['password is Correct Horse Battery Staple', ['Correct', 'Horse', 'Battery', 'Staple']],
        ['api_key: first second third', ['first', 'second', 'third']],
        ['Authorization: Basic dXNlcjpwYXNzd29yZA==', ['dXNlcjpwYXNzd29yZA==']],
        ['private key = short secret phrase', ['short', 'secret phrase']],
    ];
    foreach ($secret_cases as [$input, $secret_parts]) {
        $screened = runbookExportText($input);
        $assertContains('REDACTED', $screened, 'A common credential form was not marked as redacted');
        foreach ($secret_parts as $secret_part) {
            $assertNotContains($secret_part, $screened, "A common credential form exposed $secret_part");
        }
    }
}

if (function_exists('runbookExportDecisionActor')) {
    $assertSame(
        'Client decision via guest approval link (identity not asserted)',
        runbookExportDecisionActor(['approval_decision_actor_type' => 'guest']),
        'Guest decisions overstate the identity of the actor'
    );
    $assertSame(
        'Internal agent',
        runbookExportDecisionActor([
            'approval_decision_actor_type' => 'agent',
        ]),
        'Internal approval actors are not rendered as a safe generic identity'
    );
}

if (function_exists('runbookExportApprovalHistoryConsistent')) {
    $created = [
        'task_approval_event_action' => 'created',
        'task_approval_event_from_status' => null,
        'task_approval_event_to_status' => 'pending',
        'task_approval_event_from_scope' => null,
        'task_approval_event_to_scope' => 'internal',
        'task_approval_event_from_type' => null,
        'task_approval_event_to_type' => 'specific',
        'task_approval_event_from_required_user_id' => 0,
        'task_approval_event_to_required_user_id' => 42,
        'task_approval_event_actor_type' => 'agent',
    ];
    $approved = [
        'task_approval_event_action' => 'approved',
        'task_approval_event_from_status' => 'pending',
        'task_approval_event_to_status' => 'approved',
        'task_approval_event_from_scope' => 'internal',
        'task_approval_event_to_scope' => 'internal',
        'task_approval_event_from_type' => 'specific',
        'task_approval_event_to_type' => 'specific',
        'task_approval_event_from_required_user_id' => 42,
        'task_approval_event_to_required_user_id' => 42,
        'task_approval_event_actor_type' => 'agent',
    ];
    $projection = [
        'approval_status' => 'approved',
        'approval_scope' => 'internal',
        'approval_type' => 'specific',
        'approval_route_user_key' => 42,
    ];
    $assertTrue(
        runbookExportApprovalHistoryConsistent($projection, [$created, $approved], 'Completed'),
        'A valid created-to-approved event chain does not match its current projection'
    );
    $broken = $approved;
    $broken['task_approval_event_from_status'] = 'declined';
    $assertTrue(
        !runbookExportApprovalHistoryConsistent($projection, [$created, $broken], 'Completed'),
        'A discontinuous approval event chain passes projection integrity'
    );
    $waived = $created;
    $waived['task_approval_event_action'] = 'waived';
    $waived['task_approval_event_from_status'] = 'pending';
    $waived['task_approval_event_to_status'] = 'waived';
    $waived['task_approval_event_from_scope'] = 'internal';
    $waived['task_approval_event_from_type'] = 'specific';
    $waived['task_approval_event_from_required_user_id'] = 42;
    $assertTrue(
        runbookExportApprovalHistoryConsistent(
            array_merge($projection, ['approval_status' => 'pending']),
            [$created, $waived],
            'Skipped'
        ),
        'A skipped task waiver cannot reconcile the intentionally unchanged current approval projection'
    );
    $assertSame('Internal / specific approver', runbookExportApprovalRoute('internal', 'specific', 42), 'Specific routes expose or lose their generic identity');
    $assertSame('Authorized client contact', runbookExportApprovalEventActorLabel(['task_approval_event_actor_type' => 'contact']), 'Contact event actors are not safely labeled');
}

if (function_exists('runbookExportStateActor')) {
    $assertSame(
        'Named Agent',
        runbookExportStateActor([
            'task_state_event_actor_type' => 'agent',
            'state_actor_name' => 'Named Agent',
        ]),
        'Task transition actors are not stably rendered'
    );
    $assertSame(
        'System',
        runbookExportStateActor(['task_state_event_actor_type' => 'system']),
        'System task transitions are mislabeled'
    );
}

// Endpoint existence, authentication, tenant boundary and completed-only gate.
$assertContains("require_once '../includes/check_login.php';", $export, 'Runbook export bypasses authenticated agent login');
$assertContains("enforceUserPermission('module_support')", $export, 'Runbook export lacks support-module authorization');
$assertContains('enforceClientAccess($client_id)', $export, 'Runbook export can cross the agent client boundary');
$assertContains("runbook_execution_status'] !== 'Completed'", $export, 'An active runbook can be exported');
$assertContains("empty(\$execution['runbook_execution_completed_at'])", $export, 'A runbook without a completion timestamp can be exported');
$assertContains('http_response_code(409)', $export, 'Incomplete or inconsistent exports do not return a conflict');
$assertContains("\$runbook_execution['runbook_execution_status'] === 'Completed'", $ticket, 'The export link is shown before completion');
$assertContains('runbook_export.php?ticket_id=', $ticket, 'Completed executions have no export link');

// Hold the workflow parent lock from the completed-only check through every
// integrity/detail read, then release it before audit logging and output.
$assertSame(1, substr_count($export, 'mysqli_begin_transaction($mysqli)'), 'Export opens more than one read snapshot transaction');
$assertSame(1, substr_count($export, 'mysqli_commit($mysqli)'), 'Export does not commit exactly one read snapshot transaction');
$assertContains('FROM tickets WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE', $export, 'Export does not lock the workflow parent ticket');
$begin_position = strpos($export, 'mysqli_begin_transaction($mysqli)');
$lock_position = strpos($export, 'LIMIT 1 FOR UPDATE', $begin_position === false ? 0 : $begin_position);
$completed_position = strpos($export, "runbook_execution_status'] !== 'Completed'", $lock_position === false ? 0 : $lock_position);
$approval_history_position = strpos($export, 'FROM task_approval_events', $completed_position === false ? 0 : $completed_position);
$history_position = strpos($export, 'FROM task_state_events', $completed_position === false ? 0 : $completed_position);
$commit_position = strpos($export, 'mysqli_commit($mysqli)', $history_position === false ? 0 : $history_position);
$audit_position = strpos($export, 'logAudit(', $commit_position === false ? 0 : $commit_position);
$assertTrue(
    $begin_position !== false && $lock_position !== false && $completed_position !== false
        && $approval_history_position !== false
        && $history_position !== false && $commit_position !== false && $audit_position !== false
        && $begin_position < $lock_position && $lock_position < $completed_position
        && $completed_position < $approval_history_position
        && $approval_history_position < $history_position && $history_position < $commit_position
        && $commit_position < $audit_position,
    'The parent lock does not span all completed/integrity/history reads or is retained through audit output'
);

// Snapshot integrity and the one-to-one immutable/runtime task mapping must be
// checked before any client-facing document is emitted.
$assertContains('runbookDefinitionHash($snapshot)', $export, 'Export does not recompute the immutable snapshot hash');
$assertContains('hash_equals($stored_hash, $computed_hash)', $export, 'Export does not verify the stored execution hash');
$assertContains('hash_equals($published_hash, $computed_hash)', $export, 'Export does not verify the published definition hash');
$assertContains('runbookVersionDefinition($version_id)', $export, 'Export does not rebuild the live published definition');
$assertContains('runbookDefinitionHash($published_definition)', $export, 'Export does not compare the live published definition with the execution snapshot');
$assertContains("COUNT(DISTINCT runbook_version_task_id) AS mapped_count", $export, 'Export does not verify one runtime task per published task');
$assertContains("in_array(\$task['task_state'], ['Completed', 'Skipped'], true)", $export, 'Export accepts a non-terminal task');
$assertContains("empty(\$task['task_completed_at'])", $export, 'Export accepts a terminal task without an audit timestamp');
$assertContains('runbookExportEvidenceQualifies($item, $required_evidence)', $export, 'Export does not verify required evidence remains satisfied');
$assertContains("approval['approval_status'] !== 'approved'", $export, 'Export does not verify required approvals remain approved');
$assertContains("final_state_event['task_state_event_to_state']", $export, 'Export does not reconcile final transition history with runtime state');
$assertContains('runbookCloseoutIntegrityErrors([', $export, 'Export bypasses the shared deterministic closeout verifier');
$assertContains('approval_created_by', $export, 'Export cannot detect an internal requester self-approving at closeout');
$assertContains('approval_decision_actor_id', $export, 'Export cannot compare the internal decision actor with its requester');

// Stable source keys, definition dependencies and named actors—not runtime IDs—
// form the closeout contract.
$assertContains('runbook_version_task_key', $export, 'Export lacks stable published task keys');
$assertContains('ORDER BY runbook_version_task_order, runbook_version_task_key', $export, 'Export task order is not stable');
$assertContains('FROM runbook_version_task_dependencies', $export, 'Export reconstructs dependencies from mutable runtime edges');
$assertContains('dependency.runbook_version_task_key AS dependency_key', $export, 'Export dependencies lack stable published keys');
$assertContains('owner.user_name AS owner_name', $export, 'Export does not identify the runtime owner safely');
$assertContains('completer.user_name AS completer_name', $export, 'Export does not identify the completion actor safely');
$assertContains('submitter.user_name AS submitter_name', $export, 'Export does not identify the evidence actor safely');
$assertContains('ticket_attachment_ticket_id = task_ticket_id', $export, 'Export can label an attachment from another ticket as task evidence');
$assertNotContains('decision_user_name', $export, 'Export retrieves a potentially sensitive internal approval actor label');
$assertNotContains('required_user_name', $export, 'Export retrieves a potentially sensitive required-user label');
$assertContains('FROM task_approval_events', $export, 'Export omits append-only approval history');
$assertContains('current_approval.approval_task_id = task_approval_event_task_id', $export, 'Approval history is not bound to its current task projection');
$assertContains('runbook_version_task_runbook_version_id = $version_id', $export, 'Approval history is not scoped to the selected published version');
$assertSame(1, substr_count($export, 'FROM task_approval_events'), 'Export queries approval history more than once');
$assertNotContains('task_approval_event_actor_label', $export, 'Export retrieves a potentially sensitive stored approval actor label');
$assertNotContains('task_approval_event_reason', $export, 'Export retrieves free-form approval history reasons');
$assertNotContains('task_approval_event_actor_id', $export, 'Export retrieves approval actor identifiers it must not disclose');
$assertContains('runbookExportApprovalHistoryConsistent(', $export, 'Export does not reconcile approval history with its current projection');
$assertContains('FROM task_state_events', $export, 'Export omits append-only task transition history');
$assertSame(1, substr_count($export, 'FROM task_state_events'), 'Export queries task transition history more than once');
$assertContains('state_actor.user_name AS state_actor_name', $export, 'Export does not identify transition actors safely');
$assertContains('Published dependencies:', $export, 'Export omits stable dependency fields');
$assertContains('Completed/skipped by:', $export, 'Export omits the task outcome actor');
$assertContains('submitted by:', $export, 'Export omits the evidence actor');
$assertContains('decision actor:', $export, 'Export omits the approval actor');
$assertContains('Approval history ', $export, 'Export omits structured approval history entries');
$assertContains('Waived by audited task skip (original status:', $export, 'Skipped tasks do not explain unresolved approvals as waived');
$assertContains('State transition ', $export, 'Export omits task transition entries');

$render_start = strpos($export, '$lines = [');
$render_end = $render_start === false ? false : strpos($export, '$filename_base =', $render_start);
$render = ($render_start !== false && $render_end !== false)
    ? substr($export, $render_start, $render_end - $render_start) : '';
$assertTrue($render !== '', 'Could not isolate the client-presentable export renderer');
$assertNotContains('approval_url_key', $render, 'The export renderer exposes an approval bearer key');
$assertNotContains('task_evidence_note', $render, 'The export renderer exposes an evidence note body');
$assertNotContains("['ticket_subject']", $render, 'The export renderer exposes a free-form ticket subject');
$assertNotContains("['task_waiting_reason']", $render, 'The export renderer exposes a free-form waiting or skip reason');
$assertNotContains("['task_state_event_reason']", $render, 'The export renderer exposes a free-form transition reason');
$assertNotContains("['ticket_attachment_name']", $render, 'The export renderer exposes an attachment filename');
$assertNotContains("['task_evidence_url']", $render, 'The export renderer exposes an evidence URL');
$assertNotContains('approval_projection_key', $render, 'The export renderer exposes an approval identifier');
$assertNotContains('approval_route_user_key', $render, 'The export renderer exposes a required-user identifier');
$assertNotContains('task_approval_event_', $render, 'The export renderer consumes raw approval event fields');
$assertNotContains('ticket_subject', $export, 'The export endpoint retrieves a free-form ticket subject it must not disclose');
$assertNotContains('ticket_attachment_name', $export, 'The export endpoint retrieves an attachment filename it must not disclose');
$assertNotContains("['task_id']", $render, 'The export renderer exposes a runtime task ID');
$assertNotContains("['approval_id']", $render, 'The export renderer exposes an approval ID');
$assertNotContains("['task_evidence_id']", $render, 'The export renderer exposes an evidence ID');
$assertSame(1, substr_count($render, "'- State transition '"), 'Export renders task transition history more than once');
$assertSame(1, substr_count($render, "'  - Approval history '"), 'Export renders approval history more than once');
$assertContains('request expires:', $render, 'Export omits approval request expiry metadata');
$assertContains('actor type:', $render, 'Export omits safe approval history actor types');
$assertContains('Approval routes and actors use generic identity classes', $render, 'Export does not disclose its generic approval identity policy');
$assertContains('subject retained in ITFlow', $render, 'Export does not explain ticket-subject omission');
$assertContains('detail omitted from client export', $render, 'Export does not explain operational-reason omission');
$assertContains('attachment filenames', $render, 'Export confidentiality notice omits filename redaction');
$assertContains("runbookNormalizeKey(", $export, 'Export filenames are not reduced to safe stable keys');
$assertContains("header('Cache-Control: no-store", $export, 'Sensitive closeouts can be cached');
$assertContains("header('Referrer-Policy: no-referrer')", $export, 'Export requests can leak URL context through a referrer');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Runbook completed-export contracts passed.\n";
