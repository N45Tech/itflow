<?php

/*
 * Ticket/document lifecycle integration for documentation obligations.
 *
 * The requirement evaluator and immutable obligation mutation API live in the
 * core documentation module. This file deliberately owns only cross-module
 * lifecycle rules: ticket assessment plus delete/transfer guards.
 */

function documentationLifecycleDbQuery($sql, $context) {
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function documentationTicketImpactDefinitions() {
    return [
        'Unassessed' => 'Not assessed',
        'None' => 'No required documentation affected',
        'Required' => 'Required documentation affected',
        'Legacy Exempt' => 'Legacy ticket exempt from assessment',
    ];
}

function documentationTicketImpactBadge($impact) {
    return match ((string) $impact) {
        'None' => 'success',
        'Required' => 'warning',
        'Legacy Exempt' => 'secondary',
        default => 'danger',
    };
}

/**
 * Compute display/gate-time freshness from immutable verification dates. This
 * intentionally does not trust a nightly job to have rewritten base_status.
 */
function documentationLifecycleEffectiveStatus($obligation, $now = null) {
    $now = $now instanceof DateTimeInterface ? $now : new DateTimeImmutable('now');
    if (!intval($obligation['documentation_obligation_applicable'] ?? 0)) {
        return 'Not Applicable';
    }

    $base_status = (string) ($obligation['documentation_obligation_base_status'] ?? 'Missing');
    $exception_expiry = trim((string) ($obligation['documentation_obligation_exception_expires_at'] ?? ''));
    if (!in_array($base_status, ['Current', 'Due Soon'], true)
        && ($obligation['documentation_obligation_exception_status'] ?? '') === 'Approved'
        && $exception_expiry !== ''
        && new DateTimeImmutable($exception_expiry) > $now) {
        return 'Exception';
    }

    if (!intval($obligation['documentation_obligation_document_id'] ?? 0)) {
        return 'Missing';
    }

    if (in_array($base_status, ['Missing', 'Draft'], true)) {
        return $base_status;
    }

    $stale_at = trim((string) ($obligation['documentation_obligation_stale_at'] ?? ''));
    if ($stale_at !== '' && new DateTimeImmutable($stale_at) <= $now) {
        return 'Stale';
    }

    $next_review_at = trim((string) ($obligation['documentation_obligation_next_review_at'] ?? ''));
    $warning_days = max(0, intval($obligation['documentation_requirement_version_warning_window_days'] ?? 0));
    if ($next_review_at !== '') {
        $warning_at = (new DateTimeImmutable($next_review_at))->modify("-$warning_days days");
        if ($warning_at <= $now) {
            return 'Due Soon';
        }
    }

    return in_array($base_status, ['Current', 'Due Soon', 'Stale'], true) ? $base_status : 'Draft';
}

function documentationLifecycleStatusBadge($status) {
    return match ((string) $status) {
        'Current' => 'success',
        'Due Soon' => 'warning',
        'Missing', 'Stale' => 'danger',
        'Draft' => 'info',
        'Exception' => 'primary',
        'Not Applicable' => 'light',
        default => 'secondary',
    };
}

/**
 * Persist an explicit technician assessment while holding the ticket lock.
 * Existing links are retained when an assessment changes because they are
 * evidence of work already considered; the core gate decides whether they
 * currently block resolution.
 */
function documentationAssessTicket($ticket_id, $client_id, $configuration_change, $impact, $actor_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $client_id = intval($client_id);
    $actor_id = intval($actor_id);
    $configuration_change = intval($configuration_change) === 1 ? 1 : 0;
    $impact = (string) $impact;
    if (!$ticket_id || !$client_id || !$actor_id || !in_array($impact, ['None', 'Required'], true)) {
        throw new InvalidArgumentException('A valid ticket documentation assessment is required');
    }
    if (function_exists('documentationRequireSupportLevel')) {
        documentationRequireSupportLevel($actor_id, 2);
    }
    if ($configuration_change && $impact !== 'Required') {
        throw new InvalidArgumentException('Configuration changes must identify required documentation impact');
    }

    $locked_ticket = runbookLockOpenTicket($ticket_id);
    runbookRequireLockedTicketClient($locked_ticket, $client_id);
    $impact_sql = mysqli_real_escape_string($mysqli, $impact);
    $old_impact_sql = mysqli_real_escape_string($mysqli, (string) ($locked_ticket['ticket_documentation_impact'] ?? 'Unassessed'));
    $old_impact = (string) ($locked_ticket['ticket_documentation_impact'] ?? 'Unassessed');
    $old_configuration_change = intval($locked_ticket['ticket_configuration_change'] ?? 0);
    $is_downgrade = ($old_impact === 'Required' && $impact !== 'Required')
        || ($old_configuration_change === 1 && $configuration_change !== 1);
    if ($is_downgrade && documentationTicketHasAuditRecords($ticket_id)) {
        throw new DomainException('Documentation impact cannot be downgraded after audit history exists');
    }

    $updated = documentationLifecycleDbQuery("UPDATE tickets SET
        ticket_configuration_change = $configuration_change,
        ticket_documentation_impact = '$impact_sql',
        ticket_documentation_assessed_by = $actor_id,
        ticket_documentation_assessed_at = NOW()
        WHERE ticket_id = $ticket_id
        AND ticket_client_id = $client_id
        AND ticket_configuration_change = $old_configuration_change
        AND ticket_documentation_impact = '$old_impact_sql'
        LIMIT 1", 'Could not save the ticket documentation assessment');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The ticket documentation assessment changed; refresh and try again');
    }

    return $locked_ticket;
}

function documentationTicketHasAuditRecords($ticket_id) {
    $ticket_id = intval($ticket_id);
    if (!$ticket_id) {
        return false;
    }
    $row = mysqli_fetch_row(documentationLifecycleDbQuery("SELECT
        EXISTS (SELECT 1 FROM ticket_documentation_obligations
            WHERE ticket_documentation_obligation_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM ticket_documentation_waivers waiver
            INNER JOIN ticket_documentation_obligations link
                ON link.ticket_documentation_obligation_id = waiver.ticket_documentation_waiver_link_id
            WHERE link.ticket_documentation_obligation_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM documentation_promise_ledger
            WHERE documentation_promise_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM documentation_change_passports
            WHERE documentation_change_passport_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM documentation_evidence_locker
            WHERE documentation_evidence_source_ticket_id = $ticket_id)",
        'Could not inspect ticket documentation history'));
    return intval($row[0] ?? 0) === 1;
}

function documentationTicketCanTransfer($ticket_id, $target_client_id) {
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $target_client_id = intval($target_client_id);
    $row = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
        ticket_client_id, EXISTS (
            SELECT 1 FROM ticket_documentation_obligations
            WHERE ticket_documentation_obligation_ticket_id = $ticket_id
        ) AS has_documentation_history
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
        'Could not inspect the ticket documentation context'));
    if (!$row) {
        return [false, 'The ticket is unavailable.'];
    }
    if (intval($row['ticket_client_id']) !== $target_client_id && intval($row['has_documentation_history'])) {
        return [false, 'Tickets with documentation links or waivers cannot be transferred because their audit context belongs to the original client.'];
    }
    return [true, ''];
}

function documentationDocumentHasObligations($document_id) {
    $document_id = intval($document_id);
    if (!$document_id) {
        return false;
    }
    $row = mysqli_fetch_row(documentationLifecycleDbQuery("SELECT EXISTS (
        SELECT 1 FROM client_documentation_obligations
        WHERE documentation_obligation_document_id = $document_id
    )", 'Could not inspect document obligation links'));
    return intval($row[0] ?? 0) === 1;
}

function documentationClientHasAuditRecords($client_id) {
    $client_id = intval($client_id);
    if (!$client_id) {
        return false;
    }

    // Check every client-scoped immutable record, rather than relying only on
    // the current obligation row. This also retains audit history if a prior
    // repair left an event, evidence, passport, or promise without its parent.
    $client_scoped_records = [
        'client_documentation_obligations' => 'documentation_obligation_client_id',
        'documentation_obligation_events' => 'documentation_obligation_event_client_id',
        'documentation_obligation_exceptions' => 'documentation_obligation_exception_client_id',
        'documentation_obligation_exception_events' => 'documentation_obligation_exception_event_client_id',
        'documentation_evidence_locker' => 'documentation_evidence_client_id',
        'documentation_change_passports' => 'documentation_change_passport_client_id',
        'documentation_promise_ledger' => 'documentation_promise_client_id',
        'documentation_promise_events' => 'documentation_promise_event_client_id',
        'ticket_documentation_obligations' => 'ticket_documentation_obligation_client_id',
    ];
    foreach ($client_scoped_records as $table => $column) {
        $row = mysqli_fetch_row(documentationLifecycleDbQuery(
            "SELECT EXISTS (SELECT 1 FROM `$table` WHERE `$column` = $client_id LIMIT 1)",
            'Could not inspect client documentation history'
        ));
        if (intval($row[0] ?? 0) === 1) {
            return true;
        }
    }

    return false;
}
