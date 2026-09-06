<?php

// Only the disposable database created by the PR release harness is eligible.
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') {
    exit("This test requires the disposable n45_ci_final database.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_set_charset($mysqli, 'utf8mb4');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $operation, string $message): void {
    try {
        $operation();
    } catch (DomainException $exception) {
        return;
    }
    throw new RuntimeException($message);
};
$query = static fn (string $sql) => ticketDeletionDbQuery($sql, 'Ticket recovery database check');
$scalar = static fn (string $sql) => mysqli_fetch_row($query($sql))[0];
$clients = [];
$tickets = [];
$transaction = static function (callable $operation) use ($mysqli) {
    mysqli_begin_transaction($mysqli);
    try {
        $result = $operation();
        mysqli_commit($mysqli);
        return $result;
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
};

try {
    foreach ([[7, 'strict'], [90, 'override']] as [$days, $policy]) {
        $query("INSERT INTO clients SET client_name = 'N45 ticket recovery CI',
            client_currency_code = 'USD', client_net_terms = 30,
            client_ticket_retention_days = $days, client_ticket_retention_policy = '$policy'");
        $clients[] = intval(mysqli_insert_id($mysqli));
    }
    foreach ([$clients[0], $clients[0], $clients[0], $clients[1]] as $client_id) {
        $query("INSERT INTO tickets SET ticket_prefix = 'CI', ticket_number = 1,
            ticket_subject = 'Recovery and workflow fixture', ticket_details = 'Disposable CI data',
            ticket_priority = 'High', ticket_impact = 'medium', ticket_urgency = 'high',
            ticket_status = 2, ticket_created_by = 0, ticket_client_id = $client_id");
        $tickets[] = intval(mysqli_insert_id($mysqli));
    }
    [$first, $second, $third, $other_client] = $tickets;
    $assert(ticketDisciplineCanTransfer($first)[0] === true, 'A new ticket is incorrectly blocked from transfer');
    $query("INSERT INTO ticket_replies SET ticket_reply = 'Original reply and time',
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:15:00',
        ticket_reply_by = 0, ticket_reply_ticket_id = $first");
    $reply_id = intval(mysqli_insert_id($mysqli));

    $transaction(function () use ($first, $reply_id, $clients): void {
        ticketDeletionLockTicket($first, $clients[0]);
        ticketDisciplineSaveWorkNote($first, $reply_id, [
            'work_action' => 'Checked the service', 'work_result' => 'Service recovered',
            'work_next_step' => 'Confirm access with the client',
            'customer_promise_summary' => 'Call with a progress update',
            'customer_promise_due_at' => date('Y-m-d\TH:i', time() + 86400),
        ], 0);
        ticketDisciplineStoreResolution($first, 'fixed', 'Restored the service after investigation');
        // A repeated identical resolution must not fail because no fields changed.
        ticketDisciplineStoreResolution($first, 'fixed', 'Restored the service after investigation');
    });
    $assert(ticketDisciplineCanResolve($first, true)[0] === false,
        'An outstanding customer promise did not block resolution');
    $assert(ticketDisciplineCanTransfer($first)[0] === false,
        'Client-bound operational history can be transferred to another client');
    $promise_id = intval($scalar("SELECT ticket_customer_promise_id FROM ticket_customer_promises
        WHERE ticket_customer_promise_ticket_id = $first"));
    ticketDisciplineCompletePromise($promise_id, 'fulfilled', 0, 'Called the client and confirmed access');
    $assert(ticketDisciplineCanResolve($first, true)[0] === true, 'Completed promise still blocks resolution');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_customer_promise_events
        WHERE ticket_customer_promise_event_ticket_id = $first")) === 2, 'Promise history is incomplete');

    mysqli_begin_transaction($mysqli);
    ticketDeletionLockTicket($first, $clients[0]);
    ticketDeletionSoftDelete($first, 0, 'Test rollback after an interrupted deletion');
    mysqli_rollback($mysqli);
    $assert($scalar("SELECT ticket_archived_at FROM tickets WHERE ticket_id = $first") === null,
        'Rolled-back deletion hid the ticket');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_deletion_events
        WHERE ticket_deletion_event_ticket_id = $first")) === 0, 'Rolled-back deletion left an audit event');

    foreach ([$first => 7, $other_client => 90] as $ticket_id => $expected_days) {
        $deleted = $transaction(function () use ($ticket_id) {
            ticketDeletionLockTicket($ticket_id);
            return ticketDeletionSoftDelete($ticket_id, 0, 'Move the CI ticket to Deleted');
        });
        $remaining = strtotime($deleted['ticket_restore_until']) - time();
        $assert(abs($remaining - $expected_days * 86400) < 10, 'Client retention periods were mixed');
    }
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $first")) === 1,
        'Soft deletion destroyed replies or time entries');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_work_notes WHERE ticket_work_note_ticket_id = $first")) === 1,
        'Soft deletion destroyed work evidence');
    $reject(fn () => ticketDeletionRequirePurgeEligible(mysqli_fetch_assoc($query(
        "SELECT * FROM tickets WHERE ticket_id = $first"))), 'Early purge was allowed');
    try {
        $transaction(fn () => runbookLockTicketForTransition($first, true));
        throw new LogicException('Deleted tickets still accept workflow mutations');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'Deleted tickets'), 'Unexpected archived-ticket failure');
    }

    $query("UPDATE tickets SET ticket_restore_until = DATE_SUB(NOW(), INTERVAL 1 DAY)
        WHERE ticket_id = $first");
    $transaction(function () use ($first): void {
        ticketDeletionLockTicket($first);
        ticketDeletionRestore($first, 0, 'Restore retained data after the minimum period');
    });
    $assert(intval($scalar("SELECT ticket_status FROM tickets WHERE ticket_id = $first")) === 2,
        'Restoration changed the prior lifecycle state');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_deletion_events
        WHERE ticket_deletion_event_ticket_id = $first")) === 2, 'Delete/restore history was not preserved');

    ticketDisciplineAddRelationship($first, $second, 'child', 0);
    ticketDisciplineAddRelationship($second, $third, 'child', 0);
    $assert(ticketDisciplineCanTransfer($third)[0] === false,
        'A related ticket can be transferred across client boundaries');
    $reject(fn () => ticketDisciplineAddRelationship($third, $first, 'child', 0), 'Circular ticket hierarchy accepted');
    $reject(fn () => ticketDisciplineAddRelationship($first, $first, 'related', 0), 'Self-linked ticket accepted');
    $transaction(function () use ($other_client): void {
        ticketDeletionLockTicket($other_client);
        ticketDeletionRestore($other_client, 0, 'Restore cross-client relationship fixture');
    });
    $reject(fn () => ticketDisciplineAddRelationship($first, $other_client, 'related', 0),
        'Cross-client ticket relationship accepted');

    // Replaying a completed migration must preserve a staff assessment that
    // differs from the deterministic legacy High => high/medium mapping.
    define('FROM_N45_DB_UPDATER', true);
    require dirname(__DIR__) . '/n45/migrations/n45-0023-ticket-operational-discipline.php';
    $assert($scalar("SELECT CONCAT(ticket_impact, '/', ticket_urgency) FROM tickets WHERE ticket_id = $second") === 'medium/high',
        'Migration replay overwrote an existing impact/urgency assessment');

    $transaction(function () use ($first): void {
        ticketDeletionLockTicket($first);
        ticketDeletionSoftDelete($first, 0, 'Prepare the explicit purge checks');
    });
    $query("UPDATE tickets SET ticket_restore_until = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE ticket_id = $first");
    $reject(fn () => $transaction(function () use ($first): void {
        ticketDeletionLockTicket($first);
        ticketDeletionPurge($first);
    }), 'Strict client retention allowed evidence destruction');
    $query("UPDATE clients SET client_ticket_retention_policy = 'override' WHERE client_id = {$clients[0]}");
    $transaction(function () use ($first): void {
        $ticket = ticketDeletionLockTicket($first);
        ticketDeletionRecordEvent($ticket, 'purged', 0, 'Explicit CI purge after retention', 'override');
        ticketDeletionPurge($first);
    });
    $assert(intval($scalar("SELECT COUNT(*) FROM tickets WHERE ticket_id = $first")) === 0, 'Explicit purge did not remove the ticket');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_deletion_events
        WHERE ticket_deletion_event_ticket_id = $first AND ticket_deletion_event_action = 'purged'")) === 1,
        'Permanent deletion destroyed its surviving audit record');
    $assert(intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $first")) === 0,
        'Permanent deletion left orphaned replies');

    echo "Ticket recovery, client retention, promise gates, relationships, and migration replay passed.\n";
} finally {
    mysqli_rollback($mysqli);
    if ($tickets) {
        $ids = implode(',', $tickets);
        foreach ([
            'ticket_deletion_events' => 'ticket_deletion_event_ticket_id',
            'ticket_customer_promise_events' => 'ticket_customer_promise_event_ticket_id',
            'ticket_customer_promises' => 'ticket_customer_promise_ticket_id',
            'ticket_work_notes' => 'ticket_work_note_ticket_id',
            'ticket_resolution_events' => 'ticket_resolution_event_ticket_id',
            'ticket_replies' => 'ticket_reply_ticket_id',
        ] as $table => $column) {
            $query("DELETE FROM $table WHERE $column IN ($ids)");
        }
        $query("DELETE FROM ticket_relationships WHERE ticket_relationship_from_ticket_id IN ($ids)
            OR ticket_relationship_to_ticket_id IN ($ids)");
        $query("DELETE FROM tickets WHERE ticket_id IN ($ids)");
    }
    if ($clients) {
        $query('DELETE FROM clients WHERE client_id IN (' . implode(',', $clients) . ')');
    }
}
