<?php

/*
 * Source contract for atomic automation incident ticket creation. The release
 * database harness exercises the underlying transaction and SLA writes; this
 * test protects their ordering and the durable-event retry seam.
 */

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
$position = static function (string $contents, string $needle, string $message) use (&$failures) {
    $at = strpos($contents, $needle);
    if ($at === false) {
        $failures[] = $message;
    }
    return $at;
};

$automation = $read('functions/automation.php');
$events = $read('functions/automation_events.php');
$create = $section(
    $automation,
    'function automationCreateIncidentTicket(',
    'function automationAddIncidentReply(',
    'automation incident ticket creation'
);
$process = $section(
    $events,
    'function automationProcessStoredEvent(',
    'function automationProcessEventQueue(',
    'stored automation event processing'
);

$begin = $position(
    $create,
    'if (!mysqli_begin_transaction($mysqli))',
    'Automation incident creation does not check that its transaction began'
);
$client_lock = $position(
    $create,
    'if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id))',
    'Automation incident creation does not lock its client before ticket creation'
);
$number_allocation = $position(
    $create,
    'automationDbQuery("UPDATE settings SET',
    'Automation incident creation no longer allocates its number after the client lock'
);
$insert = $position(
    $create,
    'automationDbQuery("INSERT INTO tickets SET',
    'Automation incident creation no longer inserts its ticket inside the protected section'
);
$ticket_id = $position(
    $create,
    '$ticket_id = intval(mysqli_insert_id($mysqli));',
    'Automation incident creation does not capture its new ticket before SLA selection'
);
$ticket_id_check = $position(
    $create,
    'if ($ticket_id < 1)',
    'Automation incident creation does not reject a missing ticket ID before SLA selection'
);
$sla = $position(
    $create,
    'applyTicketSla($ticket_id, null, null, true);',
    'Automation incident creation does not join SLA selection to its caller-owned transaction'
);
$commit = $position(
    $create,
    'if (!mysqli_commit($mysqli))',
    'Automation incident creation does not check its commit'
);
$catch = $position(
    $create,
    'catch (Throwable $e)',
    'Automation incident creation does not catch transaction failures'
);
$rollback = $position(
    $create,
    'mysqli_rollback($mysqli);',
    'Automation incident creation cannot roll back a ticket or SLA failure'
);
$rethrow = $position(
    $create,
    'throw $e;',
    'Automation incident creation swallows failures instead of exposing the retry seam'
);
$history = $position(
    $create,
    'logTicketHistory(',
    'Automation incident creation no longer records ticket history after commit'
);
$audit = $position(
    $create,
    "logAudit('Automation', 'Create'",
    'Automation incident creation no longer records its audit event after commit'
);
$notify = $position(
    $create,
    "appNotify('Automation Event'",
    'Automation incident creation no longer notifies after commit'
);

$ordered = [
    $begin,
    $client_lock,
    $number_allocation,
    $insert,
    $ticket_id,
    $ticket_id_check,
    $sla,
    $commit,
    $catch,
    $rollback,
    $rethrow,
    $history,
    $audit,
    $notify,
];
if (!in_array(false, $ordered, true)) {
    for ($index = 1; $index < count($ordered); $index++) {
        if ($ordered[$index - 1] >= $ordered[$index]) {
            $failures[] = 'Ticket insert, SLA stamp, commit, rollback path, and post-commit side effects are not safely ordered';
            break;
        }
    }
}
if (strpos($create, 'applyTicketSla($ticket_id);') !== false) {
    $failures[] = 'Automation incident SLA selection still owns a separate transaction';
}

// The incident advisory lock must continue to span ticket creation and event
// linkage. The event link is written only after the atomic helper returns, so
// an SLA rollback leaves no durable ticket ID for a retry to duplicate.
$lock = $position(
    $process,
    'SELECT GET_LOCK(\'$lock_name\', 10)',
    'Stored event processing no longer acquires the incident advisory lock'
);
$create_call = $position(
    $process,
    '$ticket = automationCreateIncidentTicket($event, $resolved);',
    'Stored event processing no longer uses the atomic incident ticket helper'
);
$returned_ticket = $position(
    $process,
    '$ticket_id = intval($ticket[\'ticket_id\']);',
    'Stored event processing does not consume the committed ticket ID'
);
$event_link = $position(
    $process,
    'UPDATE automation_events SET automation_event_ticket_id = $ticket_id',
    'Stored event processing no longer links a successful ticket to its durable event'
);
$incident_save = $position(
    $process,
    'automationEventSaveIncident($event, $resolved, $incident, $status, $action,',
    'Stored event processing no longer saves the incident after its ticket link'
);
$release = $position(
    $process,
    'SELECT RELEASE_LOCK(\'$lock_name\')',
    'Stored event processing no longer releases the incident advisory lock'
);
$retry_order = [$lock, $create_call, $returned_ticket, $event_link, $incident_save, $release];
if (!in_array(false, $retry_order, true)) {
    for ($index = 1; $index < count($retry_order); $index++) {
        if ($retry_order[$index - 1] >= $retry_order[$index]) {
            $failures[] = 'Incident locking, atomic ticket creation, event linking, and incident persistence no longer protect retries from duplication';
            break;
        }
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Automation incident atomicity contract passed.\n";
