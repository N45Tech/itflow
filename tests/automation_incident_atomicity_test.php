<?php

/* Source contract for lease-owned, exactly-once automation processing. */

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
$section = static function (string $contents, string $start, string $end, string $label) use (&$failures): string {
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
        $failures[] = $message;
    }
};
$assertOrdered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $offset = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $offset + 1);
        if ($position === false || $position <= $offset) {
            $failures[] = "$message (missing/out of order: $needle)";
            return;
        }
        $offset = $position;
    }
};

$events = $read('functions/automation_events.php');
$automation = $read('functions/automation.php');
$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0016-release-safety-hardening.php');
$queue = $section($events, 'function automationEventQueue(', 'function automationEventLockAuthority(', 'event queue');
$process = $section($events, 'function automationProcessStoredEvent(', 'function automationProcessEventQueue(', 'event processor');
$failure = $section($events, 'function automationEventFail(', 'function automationProcessStoredEvent(', 'event failure');
$complete = $section($events, 'function automationEventComplete(', 'function automationEventFail(', 'event completion');
$create = $section($automation, 'function automationCreateIncidentTicket(', 'function automationAddIncidentReply(', 'ticket creation');
$reply_at = strpos($automation, 'function automationAddIncidentReply(');
$reply = $reply_at === false ? '' : substr($automation, $reply_at);

foreach (['automation_event_api_key_id', 'automation_event_api_user_id',
          'automation_event_authorized_client_id', 'automation_event_lease_token'] as $column) {
    $assertContains($column, $schema, "Baseline automation events omit $column");
    $assertContains($column, $migration, "Release migration omits $column");
}
$assertOrdered($queue, [
    'automation_event_api_key_id = $origin_api_key_id',
    'automation_event_api_user_id = $origin_api_user_id',
    'automation_event_authorized_client_id = $authorized_client_id',
], 'Ingestion does not persist its complete authority provenance');

$assertContains('$lease_token = hash(\'sha256\', random_bytes(32));', $process,
    'The processor does not create an unguessable per-attempt lease');
$assertOrdered($process, [
    "automation_event_status = 'Processing'",
    'automation_event_lease_token = \'$lease_sql\'',
    'automationIdentityLockNames($identity)',
    "'itflow_automation_' . sha1(",
    'automationAcquireNamedLocks($lock_names)',
    'mysqli_begin_transaction($mysqli)',
    'automationEventLockAuthority($row, $lock_order)',
    'automationEventCandidateClientId($event)',
    'automationEventLockClient($candidate_client_id, $authorized_client_id, $lock_order)',
    "'Could not lock automation ticket settings'",
    'automationResolveIdentityUnlocked($identity)',
    "'Could not lock the automation incident'",
    'automationCreateIncidentTicket(',
    'automationEventSaveIncident(',
    'automationEventComplete(',
    '$lease_token, $lock_order',
    'automationEventFlushAudits($lock_order)',
    'mysqli_commit($mysqli)',
], 'Lease, authority, identity, ticket, incident, event, audit, and commit are not one ordered transaction');
$assertContains('mysqli_rollback($mysqli)', $process, 'A processing failure cannot roll back all event side effects');
$assertContains('automationReleaseNamedLocks($acquired_locks)', $process, 'Processing does not release every advisory lock');
if (str_contains($process, 'automationResolveIdentity($event')) {
    $failures[] = 'Stored event processing still commits identity resolution in a nested transaction';
}

foreach ([$failure, $complete] as $lease_owned_update) {
    $assertContains("automation_event_status = 'Processing'", $lease_owned_update,
        'A terminal event update is not restricted to an active processing lease');
    $assertContains('automation_event_lease_token = \'$lease_sql\'', $lease_owned_update,
        'A terminal event update is not compare-and-set by lease token');
    $assertContains('automation_event_lease_token = NULL', $lease_owned_update,
        'A terminal event update does not clear its lease token');
}

$assertContains('bool $caller_transaction = false', $create,
    'Ticket creation cannot join the event transaction');
$assertContains('applyTicketSla($ticket_id, null, null, true)', $create,
    'SLA selection does not join ticket creation atomically');
$assertContains('if (!logTicketHistory(', $create,
    'Ticket history failure does not abort ticket creation');
$assertContains('automationEventQueueAudit(', $create,
    'Ticket audit is not buffered into the event transaction');
$assertContains('bool $caller_transaction = false', $reply,
    'Incident replies cannot join the event transaction');
$assertContains('runbookTicketCanResolve($ticket_id)', $reply,
    'Recovery resolution bypasses the runbook gate');
$assertContains('documentationRecordChangePassport($ticket_id, 4, 0, true)', $reply,
    'Recovery resolution does not create its passport in the caller transaction');
$assertContains('setTicketResolutionSlaMet($ticket_id, true)', $reply,
    'Recovery resolution does not fail closed on SLA evidence writes');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Automation incident atomicity contract passed.\n";
