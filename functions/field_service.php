<?php

// Field work is a projection of the existing client/ticket/project records.
// All new visit/event timestamps are UTC; ticket schedules retain local time.

function fieldDb(string $sql)
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException('Field record query failed: ' . mysqli_error($mysqli));
    }
    return $result;
}

function fieldSql($value): string
{
    global $mysqli;
    return $value === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
}

function fieldRows(string $sql): array
{
    return mysqli_fetch_all(fieldDb($sql), MYSQLI_ASSOC);
}

function fieldUtc(?string $value): ?string
{
    return $value ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null;
}

function fieldLocalTime(?string $value): ?string
{
    return $value ? (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null;
}

function fieldFutureUtc($value, bool $required = false): ?string
{
    $value = fieldText($value, 'a due date and time', 40, $required);
    if ($value === '') {
        return null;
    }
    if (str_ends_with($value, 'Z')) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp() <= time()) {
            throw new DomainException('Choose a valid future date and time.');
        }
        return $date->format('Y-m-d H:i:s');
    }
    // Legacy callers use the PSA's configured timezone; the mobile UI sends UTC.
    $local = ticketDisciplineFutureDateTime($value, $required);
    return $local ? (new DateTimeImmutable($local))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
}

function fieldText($value, string $label, int $maximum, bool $required = true): string
{
    if (!is_scalar($value) && $value !== null) {
        throw new DomainException("Enter a valid $label.");
    }
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text) > $maximum) {
        throw new DomainException("Enter $label" . ($required ? '' : ' if needed') . " using at most $maximum characters.");
    }
    return $text;
}

function fieldPosition(array $input, ?int $now = null): ?array
{
    if (!isset($input['latitude'], $input['longitude'], $input['accuracy'], $input['observed_at'])) {
        return null;
    }
    foreach (['latitude', 'longitude', 'accuracy', 'observed_at'] as $key) {
        if (!is_numeric($input[$key]) || !is_finite((float) $input[$key])) {
            throw new DomainException('The location reading is invalid. Use manual check-in.');
        }
    }
    $lat = (float) $input['latitude'];
    $lon = (float) $input['longitude'];
    $accuracy = (float) $input['accuracy'];
    $observed = (int) floor((float) $input['observed_at'] / 1000);
    $age = ($now ?? time()) - $observed;
    if (abs($lat) > 90 || abs($lon) > 180 || $accuracy < 0 || $accuracy > 10000 || $age < -30 || $age > 120) {
        throw new DomainException('Get a fresh location reading or use manual check-in.');
    }
    return ['latitude' => $lat, 'longitude' => $lon, 'accuracy' => $accuracy,
        'observed_at' => gmdate('Y-m-d H:i:s', $observed)];
}

function fieldDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
    return 6371000 * 2 * atan2(sqrt(min(1, $a)), sqrt(max(0, 1 - $a)));
}

function fieldAddressHash(array $row): string
{
    $parts = [];
    foreach (['location_address', 'location_city', 'location_state', 'location_zip', 'location_country'] as $key) {
        $parts[] = mb_strtolower(trim((string) ($row[$key] ?? '')));
    }
    return hash('sha256', implode('|', $parts));
}

function fieldArrivalCandidates(array $jobs, ?array $position, int $now): array
{
    if (!$position || $position['accuracy'] > 100) {
        return [];
    }
    $candidates = [];
    foreach ($jobs as $job) {
        if (!$job['scheduled_at'] || empty($job['pin_valid'])) {
            continue;
        }
        $offset = abs(strtotime($job['scheduled_at']) - $now);
        if ($offset > 4 * 3600) {
            continue;
        }
        $distance = fieldDistance($position['latitude'], $position['longitude'],
            (float) $job['pin_latitude'], (float) $job['pin_longitude']);
        if ($distance > (int) $job['pin_radius_meters']) {
            continue;
        }
        $candidates[] = ['ticket_id' => (int) $job['ticket_id'], 'location_id' => (int) $job['location_id'],
            'distance_meters' => (int) round($distance), 'schedule_distance' => $offset];
    }
    usort($candidates, static fn ($a, $b) => [$a['schedule_distance'], $a['distance_meters'], $a['ticket_id']]
        <=> [$b['schedule_distance'], $b['distance_meters'], $b['ticket_id']]);
    return $candidates;
}

function fieldTicket(int $id, bool $allow_terminal = true): array
{
    $scope = clientScopeSql('t.ticket_client_id');
    $row = mysqli_fetch_assoc(fieldDb("SELECT t.*, c.client_name,
        l.location_id, l.location_name, l.location_address, l.location_city,
        l.location_state, l.location_zip, l.location_country, l.location_notes,
        l.location_hours, p.project_name, p.project_manager,
        u.user_name AS assigned_name, ts.ticket_status_name,
        ct.contact_name, ct.contact_email, ct.contact_phone, ct.contact_mobile
        FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
        LEFT JOIN locations l ON l.location_id = COALESCE(NULLIF(t.ticket_location_id, 0),
            (SELECT location_id FROM locations WHERE location_client_id = t.ticket_client_id
                AND location_primary = 1 AND location_archived_at IS NULL ORDER BY location_id LIMIT 1))
            AND l.location_client_id = t.ticket_client_id AND l.location_archived_at IS NULL
        LEFT JOIN projects p ON p.project_id = t.ticket_project_id AND p.project_client_id = t.ticket_client_id
        LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
        LEFT JOIN ticket_statuses ts ON ts.ticket_status_id = t.ticket_status
        LEFT JOIN contacts ct ON ct.contact_id = t.ticket_contact_id AND ct.contact_client_id = t.ticket_client_id
            AND ct.contact_archived_at IS NULL
        WHERE t.ticket_id = $id AND t.ticket_archived_at IS NULL
            AND c.client_archived_at IS NULL $scope LIMIT 1"));
    if (!$row || !hasClientAccess((int) $row['ticket_client_id'])) {
        throw new DomainException('This ticket is unavailable or outside your client access.');
    }
    if (!$allow_terminal && ($row['ticket_closed_at'] || $row['ticket_resolved_at'] || in_array((int) $row['ticket_status'], [4, 5], true))) {
        throw new DomainException('Reopen this ticket before adding field work.');
    }
    return $row;
}

function fieldJob(array $ticket): array
{
    $location_id = (int) ($ticket['location_id'] ?? 0);
    $pin = $location_id ? mysqli_fetch_assoc(fieldDb("SELECT * FROM field_site_pins WHERE pin_location_id = $location_id")) : null;
    $address = implode(', ', array_filter(array_map('trim', [
        $ticket['location_address'] ?? '', $ticket['location_city'] ?? '',
        $ticket['location_state'] ?? '', $ticket['location_zip'] ?? '',
    ])));
    return [
        'ticket_id' => (int) $ticket['ticket_id'], 'reference' => $ticket['ticket_prefix'] . $ticket['ticket_number'],
        'subject' => $ticket['ticket_subject'], 'client_id' => (int) $ticket['ticket_client_id'],
        'client_name' => $ticket['client_name'], 'location_id' => $location_id,
        'location_name' => $ticket['location_name'] ?? 'Site not set', 'address' => $address,
        'scheduled_at' => fieldLocalTime($ticket['ticket_schedule']), 'priority' => $ticket['ticket_priority'],
        'work_type' => $ticket['ticket_work_type'], 'status' => $ticket['ticket_status_name'],
        'project_id' => (int) $ticket['ticket_project_id'], 'project_name' => $ticket['project_name'],
        'assigned_name' => $ticket['assigned_name'], 'assigned_to' => (int) $ticket['ticket_assigned_to'],
        'terminal' => !empty($ticket['ticket_resolved_at']) || !empty($ticket['ticket_closed_at']) || in_array((int) $ticket['ticket_status'], [4, 5], true),
        'pin_valid' => $pin && (int) $pin['pin_client_id'] === (int) $ticket['ticket_client_id']
            && hash_equals($pin['pin_address_hash'], fieldAddressHash($ticket)),
        'pin_latitude' => $pin['pin_latitude'] ?? null, 'pin_longitude' => $pin['pin_longitude'] ?? null,
        'pin_radius_meters' => $pin['pin_radius_meters'] ?? 150,
        'address_hash' => fieldAddressHash($ticket),
    ];
}

function fieldToday(int $user_id): array
{
    $scope = clientScopeSql('ticket_client_id');
    $ids = fieldRows("SELECT ticket_id FROM tickets WHERE ticket_archived_at IS NULL
        AND ticket_closed_at IS NULL AND ticket_resolved_at IS NULL AND ticket_status NOT IN (4,5)
        AND (ticket_assigned_to = $user_id OR EXISTS (SELECT 1 FROM tasks
            WHERE task_ticket_id = ticket_id AND task_assigned_to = $user_id AND task_completed_at IS NULL))
        $scope ORDER BY ticket_schedule IS NULL, ABS(TIMESTAMPDIFF(MINUTE, ticket_schedule, " . fieldSql(date('Y-m-d H:i:s')) . ")),
        ticket_id DESC LIMIT 100");
    $jobs = [];
    foreach ($ids as $row) {
        try {
            $jobs[] = fieldJob(fieldTicket((int) $row['ticket_id']));
        } catch (DomainException $exception) {
            // A client or ticket can disappear from scope between the two reads.
        }
    }
    return $jobs;
}

function fieldVisitView(array $visit): array
{
    foreach (['visit_started_at', 'visit_arrived_at', 'visit_finished_at', 'visit_eta_at', 'visit_acknowledged_at', 'visit_location_at'] as $key) {
        $visit[$key] = fieldUtc($visit[$key] ?? null);
    }
    $visit['segments'] = fieldRows('SELECT s.*, t.ticket_prefix, t.ticket_number, t.ticket_subject
        FROM field_time_segments s JOIN tickets t ON t.ticket_id = s.segment_ticket_id
        WHERE segment_visit_id = ' . (int) $visit['visit_id'] . ' ORDER BY segment_id');
    foreach ($visit['segments'] as &$segment) {
        $segment['segment_started_at'] = fieldUtc($segment['segment_started_at']);
        $segment['segment_ended_at'] = fieldUtc($segment['segment_ended_at']);
        $segment['segment_submitted_at'] = fieldUtc($segment['segment_submitted_at']);
    }
    unset($segment);
    return $visit;
}

function fieldMyVisits(int $user_id): array
{
    $scope = clientScopeSql('visit_client_id');
    $rows = fieldRows("SELECT v.*, t.ticket_prefix, t.ticket_number, t.ticket_subject, c.client_name
        FROM field_visits v JOIN tickets t ON t.ticket_id = v.visit_ticket_id AND t.ticket_client_id = v.visit_client_id
        JOIN clients c ON c.client_id = v.visit_client_id
        WHERE visit_user_id = $user_id AND t.ticket_archived_at IS NULL AND c.client_archived_at IS NULL $scope
        ORDER BY visit_active_user_id IS NULL, visit_id DESC LIMIT 30");
    return array_map('fieldVisitView', $rows);
}

function fieldBlockers(string $where): array
{
    $scope = clientScopeSql('b.blocker_client_id');
    $rows = fieldRows("SELECT b.*, u.user_name AS owner_name, t.ticket_subject,
        (SELECT blocker_event_note FROM field_blocker_events WHERE blocker_event_blocker_id = b.blocker_id
            AND blocker_event_action IN ('acknowledged','resolved') ORDER BY blocker_event_id DESC LIMIT 1) AS blocker_response,
        t.ticket_prefix, t.ticket_number FROM field_blockers b
        JOIN tickets t ON t.ticket_id = b.blocker_ticket_id AND t.ticket_client_id = b.blocker_client_id
        JOIN clients c ON c.client_id = b.blocker_client_id AND c.client_archived_at IS NULL
        LEFT JOIN users u ON u.user_id = b.blocker_owner_id
        WHERE t.ticket_archived_at IS NULL $scope AND ($where) ORDER BY b.blocker_status = 'resolved', b.blocker_due_at LIMIT 100");
    foreach ($rows as &$row) {
        foreach (['blocker_due_at', 'blocker_created_at', 'blocker_updated_at'] as $key) {
            $row[$key] = fieldUtc($row[$key]);
        }
    }
    unset($row);
    return $rows;
}

function fieldTaskRows(int $ticket_id): array
{
    $tasks = fieldRows("SELECT task_id, task_name, task_instructions, task_state, task_assigned_to,
        task_due_at, task_waiting_reason, task_evidence_required, task_evidence_prompt, task_completed_at,
        u.user_name AS assigned_name FROM tasks LEFT JOIN users u ON u.user_id = task_assigned_to
        WHERE task_ticket_id = $ticket_id ORDER BY task_order, task_id");
    foreach ($tasks as &$task) {
        $task['task_instructions'] = fieldPlainText($task['task_instructions']);
        [$task['can_complete'], $task['completion_error']] = runbookTaskCanComplete((int) $task['task_id']);
        $task['task_due_at'] = fieldLocalTime($task['task_due_at']);
        $task['dependencies'] = fieldRows('SELECT t.task_id, t.task_name, t.task_state FROM task_dependencies d
            JOIN tasks t ON t.task_id = d.depends_on_task_id WHERE d.task_id = ' . (int) $task['task_id'] . " AND t.task_ticket_id = $ticket_id");
    }
    unset($task);
    return $tasks;
}

function fieldDocumentation(int $client_id, int $asset_id = 0, string $search = ''): array
{
    if (lookupUserPermission('module_client') < 1 || !hasClientAccess($client_id)) {
        return [];
    }
    $search_sql = fieldSql('%' . $search . '%');
    return fieldRows("SELECT d.document_id, d.document_name, d.document_description,
        d.document_updated_at, d.document_created_at,
        EXISTS (SELECT 1 FROM asset_documents ad WHERE ad.document_id = d.document_id AND ad.asset_id = $asset_id) AS asset_linked,
        (SELECT MAX(documentation_obligation_last_verified_at) FROM client_documentation_obligations
            WHERE documentation_obligation_document_id = d.document_id
                AND documentation_obligation_client_id = d.document_client_id) AS last_verified_at
        FROM documents d WHERE d.document_client_id = $client_id AND d.document_archived_at IS NULL
        AND (d.document_name LIKE $search_sql OR d.document_description LIKE $search_sql OR d.document_content_raw LIKE $search_sql)
        ORDER BY asset_linked DESC, d.document_favorite DESC, d.document_name LIMIT 100");
}

function fieldOwners(int $client_id): array
{
    return fieldRows("SELECT u.user_id, u.user_name FROM users u
        JOIN user_roles r ON r.role_id = u.user_role_id
        WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL
        AND (r.role_is_admin = 1 OR (
            EXISTS (SELECT 1 FROM user_role_permissions rp JOIN modules m ON m.module_id = rp.module_id
                WHERE rp.user_role_id = u.user_role_id AND m.module_name = 'module_support' AND rp.user_role_permission_level >= 2)
            AND NOT EXISTS (SELECT 1 FROM user_client_permissions d WHERE d.user_id = u.user_id
                AND d.permission_type = 'deny' AND d.client_id = $client_id)
            AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a WHERE a.user_id = u.user_id AND a.permission_type = 'allow')
                OR EXISTS (SELECT 1 FROM user_client_permissions a WHERE a.user_id = u.user_id
                    AND a.permission_type = 'allow' AND a.client_id = $client_id)))) ORDER BY u.user_name");
}

function fieldTicketDetail(int $ticket_id, int $user_id): array
{
    $t = fieldTicket($ticket_id);
    $client_id = (int) $t['ticket_client_id'];
    $detail = fieldJob($t);
    $detail['details'] = fieldPlainText($t['ticket_details']);
    $detail['next_action'] = $t['ticket_next_action'];
    $detail['waiting_on'] = $t['ticket_waiting_on'];
    $detail['site_notes'] = lookupUserPermission('module_client') >= 1 ? fieldPlainText($t['location_notes'] ?? '') : '';
    $detail['site_hours'] = $t['location_hours'];
    $detail['contact'] = ['name' => $t['contact_name'], 'phone' => $t['contact_mobile'] ?: $t['contact_phone'], 'email' => $t['contact_email']];
    $detail['tasks'] = fieldTaskRows($ticket_id);
    $detail['documents'] = fieldDocumentation($client_id, (int) $t['ticket_asset_id']);
    $detail['owners'] = fieldOwners($client_id);
    $detail['blockers'] = fieldBlockers("b.blocker_ticket_id = $ticket_id");
    $detail['notes'] = fieldRows("SELECT ticket_reply_id, ticket_reply, ticket_reply_type,
        ticket_reply_created_at, ticket_reply_time_worked, user_name FROM ticket_replies
        LEFT JOIN users ON user_id = ticket_reply_by
        WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_archived_at IS NULL
        ORDER BY ticket_reply_id DESC LIMIT 30");
    foreach ($detail['notes'] as &$note) {
        $note['ticket_reply'] = fieldPlainText($note['ticket_reply']);
        $note['ticket_reply_created_at'] = fieldLocalTime($note['ticket_reply_created_at']);
    }
    unset($note);
    $detail['attachments'] = fieldRows("SELECT ticket_attachment_id, ticket_attachment_name,
        ticket_attachment_created_at FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id
        ORDER BY ticket_attachment_id DESC LIMIT 50");
    $detail['assets'] = lookupUserPermission('module_client') < 1 ? [] : fieldRows("SELECT asset_id, asset_name, asset_type, asset_make, asset_model, asset_serial FROM assets WHERE asset_client_id = $client_id AND asset_archived_at IS NULL
        AND (asset_id = " . (int) $t['ticket_asset_id'] . " OR asset_id IN (SELECT asset_id FROM ticket_assets WHERE ticket_id = $ticket_id)
            OR asset_location_id = " . (int) ($t['location_id'] ?? 0) . ') ORDER BY asset_name LIMIT 100');
    $detail['promises'] = fieldRows("SELECT ticket_customer_promise_id, ticket_customer_promise_summary,
        ticket_customer_promise_due_at, ticket_customer_promise_status FROM ticket_customer_promises
        WHERE ticket_customer_promise_ticket_id = $ticket_id AND ticket_customer_promise_status = 'open'
        ORDER BY ticket_customer_promise_due_at");
    return $detail;
}

function fieldPlainText(?string $html): string
{
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html);
    return trim(html_entity_decode(strip_tags(preg_replace('#<(br\s*/?|/p|/div|/li|/h[1-6])>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function fieldProjects(int $user_id): array
{
    $scope = clientScopeSql('p.project_client_id');
    return fieldRows("SELECT p.project_id, p.project_name, p.project_due, p.project_manager,
        c.client_name, u.user_name AS manager_name,
        (SELECT COUNT(*) FROM tickets t WHERE t.ticket_project_id = p.project_id AND t.ticket_archived_at IS NULL) AS ticket_count,
        (SELECT COUNT(*) FROM tickets t WHERE t.ticket_project_id = p.project_id AND t.ticket_archived_at IS NULL
            AND (t.ticket_resolved_at IS NOT NULL OR t.ticket_closed_at IS NOT NULL)) AS completed_count
        FROM projects p JOIN clients c ON c.client_id = p.project_client_id
        LEFT JOIN users u ON u.user_id = p.project_manager
        WHERE p.project_archived_at IS NULL AND p.project_completed_at IS NULL AND c.client_archived_at IS NULL
        $scope AND (p.project_manager = $user_id OR EXISTS (SELECT 1 FROM tickets t WHERE t.ticket_project_id = p.project_id
            AND t.ticket_archived_at IS NULL AND (t.ticket_assigned_to = $user_id OR EXISTS
                (SELECT 1 FROM tasks WHERE task_ticket_id = t.ticket_id AND task_assigned_to = $user_id))))
        ORDER BY p.project_due IS NULL, p.project_due, p.project_name LIMIT 100");
}

function fieldProjectDetail(int $id): array
{
    $scope = clientScopeSql('p.project_client_id');
    $project = mysqli_fetch_assoc(fieldDb("SELECT p.*, c.client_name, u.user_name AS manager_name
        FROM projects p JOIN clients c ON c.client_id = p.project_client_id
        LEFT JOIN users u ON u.user_id = p.project_manager WHERE p.project_id = $id
        AND p.project_archived_at IS NULL AND c.client_archived_at IS NULL $scope LIMIT 1"));
    if (!$project || !hasClientAccess((int) $project['project_client_id'])) {
        throw new DomainException('This project is unavailable.');
    }
    $project['project_description'] = fieldPlainText($project['project_description']);
    $ids = fieldRows("SELECT ticket_id FROM tickets WHERE ticket_project_id = $id
        AND ticket_client_id = " . (int) $project['project_client_id'] . ' AND ticket_archived_at IS NULL ORDER BY ticket_order, ticket_id');
    $project['jobs'] = [];
    foreach ($ids as $row) {
        $job = fieldJob(fieldTicket((int) $row['ticket_id']));
        $job['tasks'] = fieldTaskRows((int) $row['ticket_id']);
        $project['jobs'][] = $job;
    }
    $project['blockers'] = fieldBlockers("b.blocker_project_id = $id");
    return $project;
}

function fieldServicePendingWork(int $ticket_id): bool
{
    $row = mysqli_fetch_row(fieldDb("SELECT EXISTS (SELECT 1 FROM field_visits
        WHERE visit_ticket_id = $ticket_id AND visit_active_user_id IS NOT NULL)
        OR EXISTS (SELECT 1 FROM field_time_segments WHERE segment_ticket_id = $ticket_id AND segment_submitted_at IS NULL)"));
    return (bool) ($row[0] ?? false);
}

function fieldServiceCanResolve(int $ticket_id): array
{
    if (fieldServicePendingWork($ticket_id)) {
        return [false, 'Finish the field visit and review its time before resolving this ticket.'];
    }
    if (fieldRows("SELECT blocker_id FROM field_blockers WHERE blocker_ticket_id = $ticket_id AND blocker_status <> 'resolved' LIMIT 1")) {
        return [false, 'Resolve the open field issues before resolving this ticket.'];
    }
    return [true, ''];
}

function fieldServiceHasHistory(int $ticket_id): bool
{
    return (bool) mysqli_fetch_row(fieldDb("SELECT EXISTS (SELECT 1 FROM field_visits WHERE visit_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM field_time_segments WHERE segment_ticket_id = $ticket_id)
        OR EXISTS (SELECT 1 FROM field_blockers WHERE blocker_ticket_id = $ticket_id)"))[0];
}

function fieldTaskHasOpenBlocker(int $task_id): bool
{
    return (bool) fieldRows("SELECT blocker_id FROM field_blockers
        WHERE blocker_task_id = $task_id AND blocker_status <> 'resolved' LIMIT 1");
}

// Called within the canonical ticket purge transaction after retention approval.
// A shared visit remains attached to a surviving ticket; only this ticket's
// records are removed. Its customer summary and acknowledgment are not reused.
function fieldPurgeTicket(int $ticket_id): void
{
    if (fieldServicePendingWork($ticket_id)) {
        throw new DomainException('Finish the field visit and review its time before permanent deletion.');
    }
    $visits = fieldRows("SELECT visit_id FROM field_visits WHERE visit_ticket_id = $ticket_id ORDER BY visit_id FOR UPDATE");
    fieldDb("DELETE FROM field_time_segments WHERE segment_ticket_id = $ticket_id");
    fieldDb("DELETE FROM field_visit_events WHERE visit_event_ticket_id = $ticket_id");
    fieldDb("DELETE FROM field_blocker_events WHERE blocker_event_ticket_id = $ticket_id");
    fieldDb("DELETE FROM field_blockers WHERE blocker_ticket_id = $ticket_id");
    foreach ($visits as $visit) {
        $id = (int) $visit['visit_id'];
        $next = (int) (mysqli_fetch_row(fieldDb("SELECT MIN(segment_ticket_id) FROM field_time_segments WHERE segment_visit_id = $id"))[0] ?? 0);
        if ($next) {
            fieldDb("UPDATE field_visits SET visit_ticket_id = $next, visit_customer_summary = NULL,
                visit_acknowledged_name = NULL, visit_acknowledged_at = NULL, visit_signature_attachment_id = 0 WHERE visit_id = $id");
            fieldVisitEvent($id, $next, 0, 'retained', 'Shared visit retained after permanent deletion of its original ticket.');
        } else {
            fieldDb("DELETE FROM field_visit_events WHERE visit_event_visit_id = $id");
            fieldDb("DELETE FROM field_visits WHERE visit_id = $id");
        }
    }
}
