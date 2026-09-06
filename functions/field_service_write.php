<?php

function fieldLockTickets(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    $tickets = [];
    foreach ($ids as $id) {
        $tickets[$id] = fieldTicket($id, false);
    }
    $order = new N45LockOrder('technician field work');
    $clients = array_values(array_unique(array_map(static fn ($t) => (int) $t['ticket_client_id'], $tickets)));
    sort($clients);
    foreach ($clients as $id) {
        $order->observe('client', $id);
        if (!fieldRows("SELECT client_id FROM clients WHERE client_id = $id AND client_archived_at IS NULL FOR UPDATE")) {
            throw new DomainException('The client is no longer available.');
        }
    }
    $projects = array_values(array_unique(array_filter(array_map(static fn ($t) => (int) $t['ticket_project_id'], $tickets))));
    sort($projects);
    foreach ($projects as $id) {
        $order->observe('project', $id);
        if (!fieldRows("SELECT project_id FROM projects WHERE project_id = $id
            AND project_archived_at IS NULL AND project_completed_at IS NULL FOR UPDATE")) {
            throw new DomainException('Reopen the project before adding field work.');
        }
    }
    foreach ($tickets as $id => $before) {
        $order->observe('ticket', $id);
        $locked = runbookLockOpenTicket($id);
        if ((int) $locked['ticket_client_id'] !== (int) $before['ticket_client_id']
            || (int) $locked['ticket_project_id'] !== (int) $before['ticket_project_id']) {
            throw new DomainException('The job changed. Refresh and try again.');
        }
        $tickets[$id] = fieldTicket($id, false);
    }
    return $tickets;
}

function fieldRequest(string $action, array $input, int $user_id, callable $operation): array
{
    global $mysqli;
    $key = (string) ($input['request_key'] ?? '');
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $key)) {
        throw new DomainException('This form expired. Reload Field Mode and try again.');
    }
    $payload = $input;
    unset($payload['csrf_token']);
    foreach ($_FILES as $name => $upload) {
        if (is_string($upload['tmp_name'] ?? null) && is_uploaded_file($upload['tmp_name'])) {
            $payload['upload_' . $name] = hash_file('sha256', $upload['tmp_name']);
        }
    }
    $hash = hash('sha256', $action . json_encode($payload, JSON_THROW_ON_ERROR));
    // Scope is rechecked on every attempt, including a previously committed retry.
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    if ($ticket_id) {
        fieldTicket($ticket_id);
    }
    $key_sql = fieldSql($key);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the field update.');
    }
    $batch = null;
    try {
        $existing = mysqli_fetch_assoc(fieldDb("SELECT request_hash, request_response FROM field_requests
            WHERE request_user_id = $user_id AND request_key = $key_sql"));
        if ($existing) {
            if (!hash_equals($existing['request_hash'], $hash)) {
                throw new DomainException('This submission changed after it was sent. Refresh before sending another update.');
            }
            mysqli_commit($mysqli);
            return json_decode($existing['request_response'], true, 512, JSON_THROW_ON_ERROR);
        }
        // The unique key serializes simultaneous retries. No operation can commit
        // without its receipt, so a dropped HTTP response remains safely retryable.
        try {
            fieldDb("INSERT INTO field_requests SET request_user_id = $user_id, request_key = $key_sql,
                request_action = " . fieldSql($action) . ', request_hash = ' . fieldSql($hash) . ",
                request_response = '{}', request_created_at = UTC_TIMESTAMP()");
        } catch (Throwable $exception) {
            if ((int) $exception->getCode() !== 1062 && mysqli_errno($mysqli) !== 1062) {
                throw $exception;
            }
            mysqli_rollback($mysqli);
            $existing = mysqli_fetch_assoc(fieldDb("SELECT request_hash, request_response FROM field_requests
                WHERE request_user_id = $user_id AND request_key = $key_sql"));
            if (!$existing || !hash_equals($existing['request_hash'], $hash)) {
                throw new DomainException('This submission changed. Refresh and try again.');
            }
            return json_decode($existing['request_response'], true, 512, JSON_THROW_ON_ERROR);
        }
        $result = $operation($batch);
        fieldDb('UPDATE field_requests SET request_response = ' . fieldSql(json_encode($result, JSON_THROW_ON_ERROR))
            . " WHERE request_user_id = $user_id AND request_key = $key_sql");
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the field update.');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        if ($batch) {
            fileStagingDiscardBatch($batch);
        }
        throw $exception;
    }
    if ($batch) {
        fileStagingFinalizeCommittedBatch($batch, 'Field evidence');
    }
    return $result;
}

function fieldVisitEvent(int $visit_id, int $ticket_id, int $actor_id, string $action, string $note): void
{
    fieldDb("INSERT INTO field_visit_events SET visit_event_visit_id = $visit_id,
        visit_event_ticket_id = $ticket_id, visit_event_actor_id = $actor_id,
        visit_event_action = " . fieldSql($action) . ', visit_event_note = ' . fieldSql($note)
        . ', visit_event_created_at = UTC_TIMESTAMP()');
}

function fieldActiveVisit(int $user_id, bool $lock = false): ?array
{
    return mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_active_user_id = $user_id"
        . ($lock ? ' FOR UPDATE' : ''))) ?: null;
}

function fieldVisitTicketIds(int $user_id, int $ticket_id, int $visit_id = 0): array
{
    $ids = [$ticket_id];
    $visit = $visit_id
        ? mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_id = $visit_id AND visit_user_id = $user_id"))
        : fieldActiveVisit($user_id);
    if ($visit_id && !$visit) {
        throw new DomainException('This visit is unavailable.');
    }
    if ($visit) {
        $ids[] = (int) $visit['visit_ticket_id'];
        foreach (fieldRows('SELECT DISTINCT segment_ticket_id FROM field_time_segments WHERE segment_visit_id = ' . (int) $visit['visit_id']) as $row) {
            $ids[] = (int) $row['segment_ticket_id'];
        }
    }
    return array_values(array_unique(array_filter($ids)));
}

function fieldVisitTransition(array $input, int $user_id): array
{
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    $kind = (string) ($input['kind'] ?? '');
    if (!in_array($kind, ['travel', 'onsite', 'waiting', 'break', 'finished'], true)) {
        throw new DomainException('Choose a valid visit action.');
    }
    $tickets = fieldLockTickets(fieldVisitTicketIds($user_id, $ticket_id));
    $ticket = $tickets[$ticket_id] ?? null;
    if (!$ticket) {
        throw new DomainException('Choose a ticket for this visit.');
    }
    $visit = fieldActiveVisit($user_id, true);
    $expected = (int) ($input['visit_id'] ?? 0);
    if (($visit && (int) $visit['visit_id'] !== $expected) || (!$visit && $expected)) {
        throw new DomainException('Your active visit changed. Refresh before continuing.');
    }
    if (!$visit && !in_array($kind, ['travel', 'onsite'], true)) {
        throw new DomainException('Start the visit before changing its activity.');
    }
    if ($kind === 'onsite' && (int) ($input['confirm_onsite'] ?? 0) !== 1) {
        throw new DomainException('Confirm that you want to mark yourself onsite.');
    }
    $position = fieldPosition($input);
    $share = (int) ($input['share_location'] ?? 0) === 1;
    if (!$visit) {
        $client_id = (int) $ticket['ticket_client_id'];
        $location_id = (int) ($ticket['location_id'] ?? 0);
        fieldDb("INSERT INTO field_visits SET visit_ticket_id = $ticket_id,
            visit_client_id = $client_id, visit_location_id = $location_id, visit_user_id = $user_id,
            visit_active_user_id = $user_id, visit_status = " . fieldSql($kind) . ', visit_started_at = UTC_TIMESTAMP()');
        global $mysqli;
        $visit_id = (int) mysqli_insert_id($mysqli);
        $visit = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_id = $visit_id FOR UPDATE"));
    }
    $visit_id = (int) $visit['visit_id'];
    if (!isset($tickets[(int) $visit['visit_ticket_id']])
        || (int) $visit['visit_client_id'] !== (int) $ticket['ticket_client_id']
        || ($kind !== 'finished' && (int) $visit['visit_location_id'] !== (int) ($ticket['location_id'] ?? 0))) {
        throw new DomainException('Finish your current visit before starting work at another site.');
    }
    $active = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_time_segments WHERE segment_active_visit_id = $visit_id FOR UPDATE"));
    if ($active && !isset($tickets[(int) $active['segment_ticket_id']])) {
        throw new DomainException('Your active job changed. Refresh and try again.');
    }
    if ($active && $kind === $active['segment_kind'] && $ticket_id === (int) $active['segment_ticket_id']) {
        return ['message' => 'This activity is already running.', 'visit_id' => $visit_id];
    }
    if ($active) {
        fieldDb('UPDATE field_time_segments SET segment_ended_at = UTC_TIMESTAMP(), segment_active_visit_id = NULL
            WHERE segment_id = ' . (int) $active['segment_id']);
    }
    if ($kind === 'finished') {
        $summary = fieldText($input['customer_summary'] ?? '', 'a customer visit summary', 10000);
        $name = $visit['visit_signature_attachment_id'] ? $visit['visit_acknowledged_name']
            : fieldText($input['acknowledged_name'] ?? '', 'the acknowledging person’s name', 200, false);
        fieldDb("UPDATE field_visits SET visit_status = 'finished', visit_finished_at = UTC_TIMESTAMP(),
            visit_active_user_id = NULL, visit_customer_summary = " . fieldSql($summary) . ',
            visit_acknowledged_name = ' . fieldSql($name ?: null) . ', visit_acknowledged_at = '
            . ($name ? 'COALESCE(visit_acknowledged_at, UTC_TIMESTAMP())' : 'NULL') . ", visit_share_location = 0,
            visit_latitude = NULL, visit_longitude = NULL, visit_accuracy_meters = NULL,
            visit_location_at = NULL WHERE visit_id = $visit_id");
        $note = 'Visit finished. Time awaits review.';
    } else {
        fieldDb("INSERT INTO field_time_segments SET segment_visit_id = $visit_id,
            segment_active_visit_id = $visit_id, segment_ticket_id = $ticket_id,
            segment_user_id = $user_id, segment_kind = " . fieldSql($kind) . ', segment_started_at = UTC_TIMESTAMP()');
        $arrival = $kind === 'onsite' ? ', visit_arrived_at = COALESCE(visit_arrived_at, UTC_TIMESTAMP())' : '';
        fieldDb('UPDATE field_visits SET visit_status = ' . fieldSql($kind) . $arrival . " WHERE visit_id = $visit_id");
        $note = ucfirst($kind) . ' recorded for ' . $ticket['ticket_prefix'] . $ticket['ticket_number'];
        fieldUpdateVisitLocation($visit_id, $position, $share);
    }
    fieldVisitEvent($visit_id, $ticket_id, $user_id, $kind, $note);
    return ['message' => $note, 'visit_id' => $visit_id];
}

function fieldUpdateVisitLocation(int $visit_id, ?array $position, bool $share): void
{
    if ($share && $position) {
        fieldDb('UPDATE field_visits SET visit_share_location = 1, visit_latitude = ' . fieldSql($position['latitude'])
            . ', visit_longitude = ' . fieldSql($position['longitude']) . ', visit_accuracy_meters = ' . (int) ceil($position['accuracy'])
            . ', visit_location_at = ' . fieldSql($position['observed_at']) . " WHERE visit_id = $visit_id AND visit_active_user_id IS NOT NULL");
    } elseif (!$share) {
        fieldDb("UPDATE field_visits SET visit_share_location = 0, visit_latitude = NULL,
            visit_longitude = NULL, visit_accuracy_meters = NULL, visit_location_at = NULL WHERE visit_id = $visit_id");
    }
}

function fieldSavePin(array $input, int $user_id): array
{
    if (lookupUserPermission('module_client') < 2) {
        throw new DomainException('Client write access is required to verify a site pin.');
    }
    $tickets = fieldLockTickets([(int) ($input['ticket_id'] ?? 0)]);
    $ticket = reset($tickets);
    $location_id = (int) ($ticket['location_id'] ?? 0);
    if (!$location_id || (int) ($input['confirm_site'] ?? 0) !== 1) {
        throw new DomainException('Set the ticket site and confirm its address before saving a pin.');
    }
    $position = fieldPosition($input);
    if (!$position || $position['accuracy'] > 100) {
        throw new DomainException('A location reading accurate to 100 metres or better is needed to verify this site.');
    }
    $radius = filter_var($input['radius'] ?? 150, FILTER_VALIDATE_INT, ['options' => ['min_range' => 50, 'max_range' => 500]]);
    if ($radius === false) {
        throw new DomainException('Use a site radius between 50 and 500 metres.');
    }
    $client_id = (int) $ticket['ticket_client_id'];
    $location = mysqli_fetch_assoc(fieldDb("SELECT * FROM locations WHERE location_id = $location_id
        AND location_client_id = $client_id AND location_archived_at IS NULL FOR UPDATE"));
    if (!$location) {
        throw new DomainException('This site is no longer available.');
    }
    if (!hash_equals(fieldAddressHash($location), (string) ($input['address_hash'] ?? ''))) {
        throw new DomainException('The site address changed. Refresh and confirm the address again.');
    }
    fieldDb("INSERT INTO field_site_pins SET pin_location_id = $location_id, pin_client_id = $client_id,
        pin_latitude = " . fieldSql($position['latitude']) . ', pin_longitude = ' . fieldSql($position['longitude'])
        . ", pin_radius_meters = $radius, pin_address_hash = " . fieldSql(fieldAddressHash($location)) . ",
        pin_verified_by = $user_id, pin_verified_at = UTC_TIMESTAMP()
        ON DUPLICATE KEY UPDATE pin_client_id = VALUES(pin_client_id), pin_latitude = VALUES(pin_latitude),
        pin_longitude = VALUES(pin_longitude), pin_radius_meters = VALUES(pin_radius_meters),
        pin_address_hash = VALUES(pin_address_hash), pin_verified_by = VALUES(pin_verified_by), pin_verified_at = VALUES(pin_verified_at)");
    if (!logAudit('Location', 'Edit', 'Verified the field arrival pin', $client_id, $location_id)) {
        throw new RuntimeException('Could not record the site verification.');
    }
    return ['message' => 'Site pin verified. Future arrivals can be matched to this address.'];
}

function fieldAddNote(array $input, int $user_id): array
{
    global $mysqli;
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    fieldLockTickets([$ticket_id]);
    $note = ticketDisciplineWorkNoteInput($input);
    $extra = fieldText($input['additional_details'] ?? '', 'additional details', 10000, false);
    $html = ticketDisciplineWorkNoteHtml($note) . ($extra ? '<p>' . nl2br(escapeHtml($extra)) . '</p>' : '');
    fieldDb("INSERT INTO ticket_replies SET ticket_reply_ticket_id = $ticket_id,
        ticket_reply_by = $user_id, ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
        ticket_reply = " . fieldSql($html));
    $reply_id = (int) mysqli_insert_id($mysqli);
    ticketDisciplineSaveWorkNote($ticket_id, $reply_id, $input, $user_id);
    $task_id = (int) ($input['task_id'] ?? 0);
    if ($task_id) {
        $task = mysqli_fetch_assoc(fieldDb("SELECT task_id, task_completed_at, task_state FROM tasks
            WHERE task_id = $task_id AND task_ticket_id = $ticket_id FOR UPDATE"));
        if (!$task || $task['task_completed_at'] || in_array($task['task_state'], ['Completed', 'Skipped'], true)) {
            throw new DomainException('Choose an unfinished task on this ticket for the evidence note.');
        }
        fieldDb("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
            task_evidence_type = 'note', task_evidence_note = " . fieldSql(fieldPlainText($html)) . ", task_evidence_submitted_by = $user_id");
    }
    return ['message' => 'Work note saved to the ticket.', 'reply_id' => $reply_id];
}

function fieldCompleteTask(array $input, int $user_id): array
{
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    $task_id = (int) ($input['task_id'] ?? 0);
    fieldLockTickets([$ticket_id]);
    $task = mysqli_fetch_assoc(fieldDb("SELECT * FROM tasks WHERE task_id = $task_id AND task_ticket_id = $ticket_id FOR UPDATE"));
    if (!$task) {
        throw new DomainException('This task is unavailable.');
    }
    [$allowed, $reason] = runbookTaskCanComplete($task_id);
    if (!$allowed) {
        throw new DomainException($reason);
    }
    fieldDb("UPDATE tasks SET task_state = 'Completed', task_completed_at = NOW(), task_completed_by = $user_id
        WHERE task_id = $task_id AND task_state = 'Ready' AND task_completed_at IS NULL");
    global $mysqli;
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new DomainException('The task changed. Refresh and try again.');
    }
    runbookRecordTaskStateEvent($task_id, 'Ready', 'Completed', 'Completed in Field Mode', $user_id, 'agent');
    refreshRunbookTaskStates($ticket_id);
    fieldDb("INSERT INTO ticket_replies SET ticket_reply_ticket_id = $ticket_id, ticket_reply_by = $user_id,
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply = "
        . fieldSql('Completed task: ' . escapeHtml($task['task_name'])));
    return ['message' => 'Task completed.'];
}

function fieldSubmitTime(array $input, int $user_id): array
{
    global $mysqli;
    $visit_id = (int) ($input['visit_id'] ?? 0);
    fieldLockTickets(fieldVisitTicketIds($user_id, (int) ($input['ticket_id'] ?? 0), $visit_id));
    $visit = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_id = $visit_id AND visit_user_id = $user_id FOR UPDATE"));
    if (!$visit || !$visit['visit_finished_at']) {
        throw new DomainException('Finish the visit before submitting its time.');
    }
    $segments = fieldRows("SELECT * FROM field_time_segments WHERE segment_visit_id = $visit_id
        AND segment_submitted_at IS NULL ORDER BY segment_id FOR UPDATE");
    if (!$segments) {
        return ['message' => 'This visit’s time has already been submitted.'];
    }
    $reviews = $input['reviews'] ?? [];
    if (!is_array($reviews)) {
        throw new DomainException('Review each time entry before submitting.');
    }
    foreach ($segments as $segment) {
        $id = (int) $segment['segment_id'];
        $review = $reviews[$id] ?? null;
        $seconds = is_array($review) ? filter_var($review['seconds'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 86400]]) : false;
        if ($seconds === false || !$segment['segment_ended_at'] || ($segment['segment_kind'] === 'break' && $seconds !== 0)) {
            throw new DomainException('Review every entry. Breaks must record zero worked time.');
        }
        $reason = fieldText($review['reason'] ?? '', 'the time review reason', 500, false);
        $elapsed = strtotime($segment['segment_ended_at'] . ' UTC') - strtotime($segment['segment_started_at'] . ' UTC');
        if ($segment['segment_kind'] !== 'break' && abs($seconds - $elapsed) > 60 && mb_strlen($reason) < 10) {
            throw new DomainException('Explain time adjustments greater than one minute.');
        }
        $ticket_id = (int) $segment['segment_ticket_id'];
        $time = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        $description = 'Field visit: ' . ucfirst($segment['segment_kind']) . '. '
            . $segment['segment_started_at'] . '–' . $segment['segment_ended_at'] . ' UTC.'
            . ($reason ? ' Review: ' . $reason : '');
        fieldDb("INSERT INTO ticket_replies SET ticket_reply_ticket_id = $ticket_id,
            ticket_reply_by = $user_id, ticket_reply_type = 'Internal', ticket_reply = " . fieldSql(escapeHtml($description))
            . ', ticket_reply_time_worked = ' . fieldSql($time));
        $reply_id = (int) mysqli_insert_id($mysqli);
        fieldDb("UPDATE field_time_segments SET segment_recorded_seconds = $seconds,
            segment_review_reason = " . fieldSql($reason ?: null) . ", segment_reply_id = $reply_id,
            segment_submitted_at = UTC_TIMESTAMP() WHERE segment_id = $id AND segment_submitted_at IS NULL");
        fieldVisitEvent($visit_id, $ticket_id, $user_id, 'time_submitted', $description);
    }
    return ['message' => 'Reviewed time saved to the tickets.'];
}

function fieldBlockerEvent(int $id, int $ticket_id, int $user_id, string $action, string $note): void
{
    fieldDb("INSERT INTO field_blocker_events SET blocker_event_blocker_id = $id,
        blocker_event_ticket_id = $ticket_id, blocker_event_actor_id = $user_id,
        blocker_event_action = " . fieldSql($action) . ', blocker_event_note = ' . fieldSql($note)
        . ', blocker_event_created_at = UTC_TIMESTAMP()');
}

function fieldAddBlocker(array $input, int $user_id, ?string &$batch): array
{
    global $mysqli;
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    $tickets = fieldLockTickets([$ticket_id]);
    $ticket = $tickets[$ticket_id];
    $client_id = (int) $ticket['ticket_client_id'];
    $task_id = (int) ($input['task_id'] ?? 0);
    if ($task_id && !fieldRows("SELECT task_id FROM tasks WHERE task_id = $task_id
        AND task_ticket_id = $ticket_id AND task_completed_at IS NULL FOR UPDATE")) {
        throw new DomainException('Choose an unfinished task on this ticket.');
    }
    $document_id = (int) ($input['document_id'] ?? 0);
    if ($document_id && (lookupUserPermission('module_client') < 1 || !fieldRows("SELECT document_id FROM documents
        WHERE document_id = $document_id AND document_client_id = $client_id AND document_archived_at IS NULL"))) {
        throw new DomainException('This document is unavailable.');
    }
    $owner_id = (int) ($input['owner_id'] ?? 0);
    if (!in_array($owner_id, array_map(static fn ($owner) => (int) $owner['user_id'], fieldOwners($client_id)), true)) {
        throw new DomainException('Choose an active owner who can work with this client.');
    }
    $kind = (string) ($input['kind'] ?? 'issue');
    if (!in_array($kind, ['issue', 'blocker', 'parts', 'access', 'scope', 'documentation'], true)) {
        throw new DomainException('Choose a valid issue type.');
    }
    $title = fieldText($input['title'] ?? '', 'an issue title', 200);
    $details = fieldText($input['details'] ?? '', 'what happened and the help needed', 10000);
    $impact = fieldText($input['impact'] ?? '', 'the effect on the work', 500);
    $due_utc = fieldFutureUtc($input['due_at'] ?? '', true);
    $attachment_id = !empty($_FILES['photo']['name']) ? fieldStagePhoto($input, $ticket_id, $user_id, $batch) : 0;
    fieldDb("INSERT INTO field_blockers SET blocker_ticket_id = $ticket_id, blocker_client_id = $client_id,
        blocker_project_id = " . (int) $ticket['ticket_project_id'] . ", blocker_task_id = $task_id,
        blocker_document_id = $document_id, blocker_attachment_id = $attachment_id,
        blocker_kind = " . fieldSql($kind) . ', blocker_title = ' . fieldSql($title) . ', blocker_details = ' . fieldSql($details)
        . ', blocker_impact = ' . fieldSql($impact) . ", blocker_owner_id = $owner_id, blocker_due_at = " . fieldSql($due_utc)
        . ", blocker_created_by = $user_id, blocker_created_at = UTC_TIMESTAMP(), blocker_updated_at = UTC_TIMESTAMP()");
    $id = (int) mysqli_insert_id($mysqli);
    fieldBlockerEvent($id, $ticket_id, $user_id, 'created', $details);
    fieldDb("INSERT INTO ticket_replies SET ticket_reply_ticket_id = $ticket_id, ticket_reply_by = $user_id,
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply = "
        . fieldSql('<p>Field issue: <strong>' . escapeHtml($title) . '</strong></p><p>' . nl2br(escapeHtml($details))
            . '</p><p>Impact: ' . escapeHtml($impact) . '</p>'));
    fieldDb("INSERT INTO notifications SET notification_type = 'Ticket', notification = "
        . fieldSql('Field issue assigned: ' . $title) . ', notification_action = '
        . fieldSql('/agent/field/?ticket_id=' . $ticket_id . '&panel=issues')
        . ", notification_client_id = $client_id, notification_user_id = $owner_id, notification_entity_id = $ticket_id");
    return ['message' => 'Issue recorded and assigned to its owner.', 'blocker_id' => $id, 'attachment_id' => $attachment_id];
}

function fieldUpdateBlocker(array $input, int $user_id): array
{
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    $id = (int) ($input['blocker_id'] ?? 0);
    fieldLockTickets([$ticket_id]);
    $blocker = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_blockers WHERE blocker_id = $id
        AND blocker_ticket_id = $ticket_id FOR UPDATE"));
    if (!$blocker || ((int) $blocker['blocker_owner_id'] !== $user_id && lookupUserPermission('module_support') < 3)) {
        throw new DomainException('The assigned owner or a support administrator must update this issue.');
    }
    if ($blocker['blocker_status'] === 'resolved') {
        throw new DomainException('This issue is already resolved. Record a new issue if more work is needed.');
    }
    $status = (string) ($input['status'] ?? '');
    if (!in_array($status, ['acknowledged', 'resolved'], true)) {
        throw new DomainException('Choose acknowledge or resolve.');
    }
    $note = fieldText($input['note'] ?? '', $status === 'resolved' ? 'how the issue was resolved' : 'the response or next step', 10000);
    fieldDb('UPDATE field_blockers SET blocker_status = ' . fieldSql($status)
        . ($status === 'resolved' ? ', blocker_resolution = ' . fieldSql($note) : '')
        . ", blocker_updated_at = UTC_TIMESTAMP() WHERE blocker_id = $id");
    fieldBlockerEvent($id, $ticket_id, $user_id, $status, $note);
    fieldDb("INSERT INTO ticket_replies SET ticket_reply_ticket_id = $ticket_id, ticket_reply_by = $user_id,
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply = "
        . fieldSql('<p>Field issue ' . $status . ': <strong>' . escapeHtml($blocker['blocker_title'])
            . '</strong></p><p>' . nl2br(escapeHtml($note)) . '</p>'));
    return ['message' => $status === 'resolved' ? 'Issue resolved.' : 'Issue acknowledged.'];
}

function fieldStagePhoto(array $input, int $ticket_id, int $user_id, ?string &$batch): int
{
    global $mysqli;
    $file = $_FILES['photo'] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new DomainException('Choose a photo to upload while connected.');
    }
    $signature = (int) ($input['signature'] ?? 0) === 1;
    $maximum = ($signature ? 2 : 12) * 1024 * 1024;
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maximum || filesize($file['tmp_name']) > $maximum) {
        throw new DomainException($signature ? 'The signature must be under 2 MiB.' : 'The photo must be under 12 MiB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 20000 || $size[1] > 20000
        || ($signature && $mime !== 'image/png')) {
        throw new DomainException('Use a valid JPG, PNG, or WebP photo; signatures must be PNG.');
    }
    $name = fieldText($input['caption'] ?? '', 'a photo caption', 200, !$signature) ?: 'Visit acknowledgment';
    $task_id = (int) ($input['task_id'] ?? 0);
    if ($task_id) {
        $task = mysqli_fetch_assoc(fieldDb("SELECT task_id, task_state, task_completed_at FROM tasks
            WHERE task_id = $task_id AND task_ticket_id = $ticket_id FOR UPDATE"));
        if (!$task || $task['task_completed_at'] || in_array($task['task_state'], ['Completed', 'Skipped'], true)) {
            throw new DomainException('Choose an unfinished task on this ticket for the photo evidence.');
        }
    }
    $batch = fileStagingBatchToken();
    $root = dirname(__DIR__) . '/uploads';
    if (!is_dir($root) && !mkdir($root, 0775, true)) {
        throw new RuntimeException('Could not prepare the upload directory.');
    }
    $source = $root . '/.field-incoming/' . $batch;
    if (!mkdir($source, 0700, true)) {
        throw new RuntimeException('Could not prepare the photo upload.');
    }
    $reference = bin2hex(random_bytes(24)) . '.' . $types[$mime];
    try {
        if (!move_uploaded_file($file['tmp_name'], $source . '/' . $reference)) {
            throw new RuntimeException('Could not receive the photo.');
        }
        fileStagingStageDirectory($source, 'uploads/tickets/' . $ticket_id, $batch, 'field_evidence', $ticket_id);
    } finally {
        if (is_file($source . '/' . $reference)) {
            unlink($source . '/' . $reference);
        }
        rmdir($source);
    }
    fieldDb("INSERT INTO ticket_attachments SET ticket_attachment_ticket_id = $ticket_id,
        ticket_attachment_name = " . fieldSql($name . '.' . $types[$mime])
        . ', ticket_attachment_reference_name = ' . fieldSql($reference));
    $attachment_id = (int) mysqli_insert_id($mysqli);
    if ($task_id) {
        fieldDb("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
            task_evidence_type = 'file', task_evidence_attachment_id = $attachment_id,
            task_evidence_note = " . fieldSql($name) . ", task_evidence_submitted_by = $user_id");
    }
    return $attachment_id;
}

function fieldAddPhoto(array $input, int $user_id, ?string &$batch): array
{
    $ticket_id = (int) ($input['ticket_id'] ?? 0);
    fieldLockTickets([$ticket_id]);
    $signature = (int) ($input['signature'] ?? 0) === 1;
    $visit_id = (int) ($input['visit_id'] ?? 0);
    $visit = null;
    if ($signature) {
        $visit = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_id = $visit_id
            AND visit_ticket_id = $ticket_id AND visit_user_id = $user_id AND visit_finished_at IS NULL FOR UPDATE"));
        if (!$visit || (int) ($input['confirm_acknowledgment'] ?? 0) !== 1) {
            throw new DomainException('Confirm the acknowledgment for your active visit.');
        }
        $name = fieldText($input['acknowledged_name'] ?? '', 'the acknowledging person’s name', 200);
        if ((int) $visit['visit_signature_attachment_id'] > 0) {
            throw new DomainException('This visit already has a signed acknowledgment.');
        }
    }
    $id = fieldStagePhoto($input, $ticket_id, $user_id, $batch);
    if ($signature) {
        fieldDb("UPDATE field_visits SET visit_signature_attachment_id = $id, visit_acknowledged_name = "
            . fieldSql($name) . ", visit_acknowledged_at = UTC_TIMESTAMP() WHERE visit_id = $visit_id");
        fieldVisitEvent($visit_id, $ticket_id, $user_id, 'acknowledged', 'Visit acknowledgment by ' . $name);
    }
    return ['message' => $signature ? 'Visit acknowledgment saved.' : 'Photo saved to the ticket.', 'attachment_id' => $id];
}

function fieldUpdatePosition(array $input, int $user_id): array
{
    $visit_id = (int) ($input['visit_id'] ?? 0);
    fieldLockTickets(fieldVisitTicketIds($user_id, (int) ($input['ticket_id'] ?? 0), $visit_id));
    $visit = mysqli_fetch_assoc(fieldDb("SELECT * FROM field_visits WHERE visit_id = $visit_id
        AND visit_active_user_id = $user_id FOR UPDATE"));
    if (!$visit) {
        throw new DomainException('This visit is no longer active.');
    }
    fieldUpdateVisitLocation($visit_id, fieldPosition($input), (int) ($input['share_location'] ?? 0) === 1);
    if (array_key_exists('eta_at', $input)) {
        $eta = fieldFutureUtc($input['eta_at'], false);
        fieldDb('UPDATE field_visits SET visit_eta_at = ' . fieldSql($eta) . " WHERE visit_id = $visit_id");
    }
    return ['message' => 'Visit update saved.'];
}
