<?php

/* Transaction-authorization and lock-order contracts for Goal 10. */

$root = dirname(__DIR__);
$failures = [];
$source = @file_get_contents($root . '/functions/retention.php');
if ($source === false) {
    fwrite(STDERR, "Could not read functions/retention.php\n");
    exit(1);
}

$section = static function (string $start, string $end, string $label) use ($source, &$failures): string {
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from + strlen($start));
    if ($from === false || $to === false || $to <= $from) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($source, $from, $to - $from);
};
$ordered = static function (string $body, array $needles, string $message) use (&$failures): void {
    $offset = 0;
    foreach ($needles as $needle) {
        $at = strpos($body, $needle, $offset);
        if ($at === false) {
            $failures[] = "$message (missing or out of order: $needle)";
            return;
        }
        $offset = $at + strlen($needle);
    }
};
$contains = static function (string $body, string $needle, string $message) use (&$failures): void {
    if (!str_contains($body, $needle)) {
        $failures[] = "$message (missing: $needle)";
    }
};

foreach ([
    ['function retentionUpdatePolicy(', 'function retentionCanonicalize(', 'policy update'],
    ['function retentionSoftDeleteTicket(', 'function retentionFilePaths(', 'ticket delete'],
    ['function retentionSoftDeleteFile(', 'function retentionSoftDeleteAttachment(', 'file delete'],
    ['function retentionSoftDeleteAttachment(', 'function retentionDeletionForUpdate(', 'attachment delete'],
    ['function retentionPrepareDurableRestore(', 'function retentionClaimDurableRestore(', 'durable restore preparation'],
    ['function retentionRestoreRecord(', 'function retentionPlaceHold(', 'ticket restore'],
    ['function retentionPlaceHold(', 'function retentionReleaseHold(', 'hold placement'],
    ['function retentionReleaseHold(', 'function retentionActiveHolds(', 'hold release'],
    ['function retentionApproveAndExecuteBatch(', 'function retentionResumeBatch(', 'purge approval'],
    ['function retentionResumeBatch(', 'function retentionRemoveQuarantineDirectory(', 'purge resume'],
] as [$start, $end, $label]) {
    $body = $section($start, $end, $label);
    $ordered($body, ['mysqli_begin_transaction($mysqli)', 'retentionLockAdministratorActor($actor_id)'],
        ucfirst($label) . ' does not reauthorize the administrator inside its transaction');
}

foreach ([
    ['function retentionSoftDeleteTicket(', 'function retentionFilePaths(', 'tickets',
        'documentationLockClientTicket($ticket_id, $client_id)'],
    ['function retentionSoftDeleteFile(', 'function retentionSoftDeleteAttachment(', 'files',
        'documentationLockClient($client_id)'],
    ['function retentionSoftDeleteAttachment(', 'function retentionDeletionForUpdate(', 'attachments',
        'documentationLockClient($client_id)'],
] as [$start, $end, $policy_key, $target_lock]) {
    $body = $section($start, $end, "$policy_key policy lock");
    $ordered($body, [
        'mysqli_begin_transaction($mysqli)',
        'retentionLockAdministratorActor($actor_id)',
        "retentionPolicy('$policy_key', true)",
        $target_lock,
    ], ucfirst($policy_key) . ' deletion does not freeze its policy before business rows');
}

$preview = $section('function retentionPreviewPurge(', 'function retentionPurgeRunToken(', 'purge preview');
$contains($preview, 'if ($actor_id > 0)', 'Scheduled preview exception is not explicit');
$ordered($preview, [
    'mysqli_begin_transaction($mysqli)',
    'if ($actor_id > 0)',
    'retentionLockAdministratorActor($actor_id)',
], 'Manual purge preview does not reauthorize its administrator transactionally');
$contains($preview, 'LIMIT $limit"', 'Purge preview does not use a nonlocking candidate snapshot');
if (str_contains($preview, 'LIMIT $limit FOR UPDATE')) {
    $failures[] = 'Purge preview takes broad deletion-range locks';
}
$contains($preview, 'retentionProtectionSummary($deletion, false)',
    'Purge preview locks policy or hold rows instead of taking an advisory snapshot');

$purge = $section('function retentionPurgeRecord(', 'function retentionFailClaimedPurgeItem(', 'purge execution');
$contains($purge, 'retentionProtectionSummary($deletion, true)',
    'Permanent purge does not authoritatively relock policy and holds');

$hold = $section('function retentionPlaceHold(', 'function retentionReleaseHold(', 'hold placement');
$ordered($hold, [
    'retentionResolveRecordClient($record_type, $record_id, false)',
    'mysqli_begin_transaction($mysqli)',
    'retentionLockAdministratorActor($actor_id)',
    'documentationLockClientForExpiry($client_id)',
    'retentionResolveRecordClient($record_type, $record_id, true)',
], 'Hold placement does not use actor -> client -> parent/record lock order with exact recheck');

$resolver = $section('function retentionResolveRecordClient(', 'function retentionRestorePathsForTarget(',
    'held-record resolver');
$ordered($resolver, [
    'FROM tickets WHERE ticket_id = $ticket_id LIMIT 1 FOR UPDATE',
    'FROM ticket_attachments',
    'LIMIT 1 FOR UPDATE',
], 'Attachment holds do not lock their parent ticket before the attachment');
$contains($resolver, 'e.automation_event_client_id AS event_client_id',
    'Automation-event retention does not use the durable event tenant');
$contains($resolver, 'i.automation_incident_client_id = e.automation_event_client_id',
    'Automation-event retention joins incidents without tenant scope');
$contains($resolver, '$client_id < 1',
    'Legacy tenant-zero automation events do not fail closed');
$contains($resolver, '$event_ticket_id !== $incident_ticket_id',
    'Conflicting event and incident tickets do not fail closed');

$active_holds = $section('function retentionActiveHolds(', 'function retentionCount(', 'active holds');
$contains($active_holds, 'e.automation_event_client_id = $client_id',
    'Automation-event inherited holds are not restricted to the canonical tenant');
$contains($active_holds, 'i.automation_incident_client_id = e.automation_event_client_id',
    'Automation-event incident holds can cross tenant boundaries');

$quarantine_target = $section('function retentionLockQuarantineLifecycleTarget(',
    'function retentionClaimQuarantineMove(', 'quarantine lifecycle target');
$contains($quarantine_target, 'documentationLockClientForExpiry($client_id)',
    'Durable quarantine recovery stops when a client is archived');
if (str_contains($quarantine_target, 'documentationLockClient($client_id)')) {
    $failures[] = 'Durable quarantine recovery still requires an active client';
}

$payload_client = $section('function retentionResolvedPayloadClient(',
    'function retentionRedactPayloads(', 'payload client resolver');
$contains($payload_client, "array_key_exists('event_client_id', \$row)",
    'Payload retention does not distinguish canonical event tenants');
$contains($payload_client, '$client_id < 1',
    'Payload retention accepts legacy tenant-zero events');
$contains($payload_client, '$event_ticket_id !== $incident_ticket_id',
    'Payload retention accepts conflicting ticket ownership');
$redaction = $section('function retentionRedactPayloads(',
    'function retentionRunScheduledMaintenance(', 'payload redaction');
$contains($redaction, 'e.automation_event_client_id AS event_client_id',
    'Payload candidates omit the durable event tenant');
$contains($redaction, 'i.automation_incident_client_id = e.automation_event_client_id',
    'Payload candidates can join an incident from another tenant');
$contains($redaction, 'documentationLockClientForExpiry($candidate_client_id)',
    'Payload minimization stops when its client is archived');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Retention controls contract passed.\n";
