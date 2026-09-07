<?php
require_once __DIR__ . '/field_workspace_approvals.php';

/* Mobile technician workspace. Mutations run inside fieldRequest's receipt transaction. */

function fieldClient(int $id, bool $lock = false): array
{
    if ($id < 1 || !hasClientAccess($id)) {
        throw new DomainException('This client is outside your access.');
    }
    $client = mysqli_fetch_assoc(fieldDb("SELECT client_id, client_name FROM clients
        WHERE client_id = $id AND client_archived_at IS NULL" . ($lock ? ' FOR UPDATE' : '')));
    if (!$client) {
        throw new DomainException('This client is unavailable.');
    }
    return $client;
}

function fieldRequireModule(string $module, int $level = 1): void
{
    if (lookupUserPermission($module) < $level) {
        throw new DomainException('Your account does not have permission for this action.');
    }
}

function fieldWorkspaceDates(array $input): array
{
    foreach (['worked_at','scheduled_at','next_action_due_at','due_at'] as $key) {
        $value = $input[$key] ?? '';
        if (is_string($value) && str_ends_with($value, 'Z')) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
                throw new DomainException('Choose a valid date and time.');
            }
            $input[$key] = $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
    }
    return $input;
}

function fieldPlanVersion(array $ticket): string
{
    $fields = ['ticket_status','ticket_assigned_to','ticket_work_type','ticket_impact','ticket_urgency','ticket_waiting_on',
        'ticket_next_action','ticket_next_action_due_at','ticket_schedule','ticket_contact_id','ticket_location_id'];
    return hash('sha256', json_encode(array_intersect_key($ticket, array_flip($fields))));
}

function fieldSearchTickets(array $input, int $user_id): array
{
    $search = fieldSql('%' . fieldText($input['q'] ?? '', 'search', 200, false) . '%');
    $cursor = max(0, (int) ($input['before'] ?? 0));
    $client = max(0, (int) ($input['client_id'] ?? 0));
    $where = clientScopeSql('t.ticket_client_id');
    if ($client) { fieldClient($client); $where .= " AND t.ticket_client_id = $client"; }
    if ($cursor) { $where .= " AND t.ticket_id < $cursor"; }
    if (($input['scope'] ?? 'mine') === 'mine') {
        $where .= " AND (t.ticket_assigned_to = $user_id OR EXISTS (SELECT 1 FROM tasks
            WHERE task_ticket_id = t.ticket_id AND task_assigned_to = $user_id))";
    }
    if (($input['state'] ?? 'open') === 'open') {
        $where .= ' AND t.ticket_status NOT IN (4,5) AND t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL';
    }
    if (($input['queue'] ?? '') === 'followups') { $where .= ' AND (' . followupTicketPredicate('t') . ')'; }
    $ids = fieldRows("SELECT t.ticket_id FROM tickets t JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE t.ticket_archived_at IS NULL AND c.client_archived_at IS NULL $where
        AND (t.ticket_subject LIKE $search OR c.client_name LIKE $search
            OR CONCAT(t.ticket_prefix,t.ticket_number) LIKE $search)
        ORDER BY t.ticket_id DESC LIMIT 41");
    $more = count($ids) > 40;
    $ids = array_slice($ids, 0, 40);
    $jobs = array_map(static fn ($row) => fieldJob(fieldTicket((int) $row['ticket_id'])), $ids);
    return ['jobs' => $jobs, 'next' => $more ? (int) end($ids)['ticket_id'] : null];
}

function fieldClientChoices(string $search): array
{
    $scope = clientScopeSql('client_id');
    $q = fieldSql('%' . fieldText($search, 'client search', 200, false) . '%');
    return fieldRows("SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL
        AND client_name LIKE $q $scope ORDER BY client_name LIMIT 50");
}

function fieldClientContext(int $client_id): array
{
    $client = fieldClient($client_id);
    return $client + [
        'contacts' => fieldRows("SELECT contact_id, contact_name, contact_email, contact_phone,
            contact_mobile, contact_title, contact_primary, contact_location_id FROM contacts
            WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_primary DESC, contact_name"),
        'locations' => fieldRows("SELECT location_id, location_name FROM locations WHERE location_client_id = $client_id
            AND location_archived_at IS NULL ORDER BY location_primary DESC, location_name"),
        'projects' => fieldRows("SELECT project_id, project_name FROM projects WHERE project_client_id = $client_id
            AND project_archived_at IS NULL AND project_completed_at IS NULL ORDER BY project_name"),
        'owners' => fieldOwners($client_id),
    ];
}

function fieldHistory(int $ticket_id, int $before = 0): array
{
    fieldTicket($ticket_id);
    $cursor = $before > 0 ? " AND ticket_reply_id < $before" : '';
    $rows = fieldRows("SELECT ticket_reply_id, ticket_reply, ticket_reply_type, ticket_reply_created_at,
        ticket_reply_time_worked, ticket_reply_by,
        CASE WHEN ticket_reply_type = 'Client' THEN COALESCE(c.contact_name,'Customer') ELSE COALESCE(u.user_name,'System') END AS author
        FROM ticket_replies LEFT JOIN users u ON u.user_id = ticket_reply_by
        LEFT JOIN contacts c ON c.contact_id = ticket_reply_by
        AND ticket_reply_type = 'Client'
        WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_archived_at IS NULL $cursor
        ORDER BY ticket_reply_id DESC LIMIT 41");
    $more = count($rows) > 40; $rows = array_slice($rows, 0, 40);
    foreach ($rows as &$row) {
        $row['ticket_reply'] = fieldPlainText($row['ticket_reply']);
        $row['ticket_reply_created_at'] = fieldLocalTime($row['ticket_reply_created_at']);
    }
    unset($row);
    return ['entries' => $rows, 'next' => $more ? (int) end($rows)['ticket_reply_id'] : null];
}

function fieldWorkspaceDetail(int $ticket_id): array
{
    $t = fieldTicket($ticket_id);
    $client_id = (int) $t['ticket_client_id'];
    $context = fieldClientContext($client_id);
    $detail = [
        'status_id' => (int) $t['ticket_status'], 'closed' => !empty($t['ticket_closed_at']) || (int) $t['ticket_status'] === 5,
        'contact_id' => (int) $t['ticket_contact_id'], 'asset_id' => (int) $t['ticket_asset_id'],
        'impact' => $t['ticket_impact'], 'urgency' => $t['ticket_urgency'],
        'next_action_due_at' => fieldLocalTime($t['ticket_next_action_due_at']),
        'resolution_code' => $t['ticket_resolution_code'], 'resolution_summary' => $t['ticket_resolution_summary'],
        'root_cause' => $t['ticket_root_cause'], 'updated_at' => $t['ticket_updated_at'],
        'plan_version' => fieldPlanVersion($t),
        'contacts' => $context['contacts'], 'locations' => $context['locations'],
        'history' => fieldHistory($ticket_id),
        'approvals' => fieldWorkspaceApprovals($ticket_id, (int) $GLOBALS['session_user_id']),
        'recipients' => fieldTicketRecipients($t),
        'networks' => [], 'credentials' => [],
        'documentation_impact' => $t['ticket_documentation_impact'], 'configuration_change' => (int) $t['ticket_configuration_change'],
    ];
    if (lookupUserPermission('module_client') >= 1) {
        $detail['client_files'] = fieldRows("SELECT file_id, file_name FROM files WHERE file_client_id = $client_id
            AND file_archived_at IS NULL ORDER BY file_name");
        $detail['requirements'] = fieldRows("SELECT o.documentation_obligation_id AS id,
            o.documentation_obligation_document_id AS document_id, o.documentation_obligation_revision AS revision,
            o.documentation_obligation_base_status AS status, v.documentation_requirement_version_name AS name,
            v.documentation_requirement_version_evidence_policy AS evidence_policy,
            EXISTS (SELECT 1 FROM ticket_documentation_obligations WHERE ticket_documentation_obligation_ticket_id = $ticket_id
                AND ticket_documentation_obligation_obligation_id = o.documentation_obligation_id) AS linked
            FROM client_documentation_obligations o JOIN documentation_requirement_versions v
            ON v.documentation_requirement_version_id = o.documentation_obligation_requirement_version_id
            WHERE o.documentation_obligation_client_id = $client_id AND o.documentation_obligation_applicable = 1
            ORDER BY linked DESC, v.documentation_requirement_version_name");
        $readiness = array_column(documentationClientReadiness($client_id)['contributions'], null, 'obligation_id');
        foreach ($detail['requirements'] as &$requirement) {
            $current = $readiness[(int) $requirement['id']] ?? null;
            $requirement['status'] = $current['status'] ?? 'Needs Review';
            $requirement['name'] = $current['requirement_name'] ?? $requirement['name'];
        }
        unset($requirement);
        $detail['requirements'] = array_values(array_filter($detail['requirements'],
            static fn($requirement) => $requirement['status'] !== 'Not Applicable'));
        $detail['networks'] = fieldRows("SELECT network_id, network_name, network, network_vlan, network_gateway,
            network_subnet, network_primary_dns, network_secondary_dns, network_dhcp_range, network_notes
            FROM networks WHERE network_client_id = $client_id AND network_archived_at IS NULL ORDER BY network_name");
        foreach ($detail['networks'] as &$network) { $network['network_notes'] = fieldPlainText($network['network_notes']); }
        unset($network);
    }
    if (lookupUserPermission('module_credential') >= 1) {
        $detail['credentials'] = fieldRows("SELECT credential_id, credential_name, credential_description,
            credential_asset_id FROM credentials WHERE credential_client_id = $client_id
            AND credential_archived_at IS NULL ORDER BY credential_favorite DESC, credential_name");
    }
    [$detail['can_resolve'], $detail['completion_error']] = ticketLifecyclePrerequisitesCanResolve($ticket_id);
    return $detail;
}

function fieldAsset(int $ticket_id, int $asset_id): array
{
    fieldRequireModule('module_client');
    $t = fieldTicket($ticket_id); $client = (int) $t['ticket_client_id'];
    $asset = mysqli_fetch_assoc(fieldDb("SELECT asset_id, asset_name, asset_type, asset_make, asset_model, asset_serial,
        asset_os, asset_status, asset_physical_location, asset_notes, asset_location_id,
        asset_install_date, asset_warranty_expire FROM assets WHERE asset_id = $asset_id
        AND asset_client_id = $client AND asset_archived_at IS NULL"));
    if (!$asset) { throw new DomainException('This asset is unavailable for the ticket client.'); }
    $asset['asset_notes'] = fieldPlainText($asset['asset_notes']);
    $asset['interfaces'] = fieldRows("SELECT interface_name, interface_mac, interface_ip, interface_ipv6,
        interface_type, interface_description FROM asset_interfaces
        WHERE interface_asset_id = $asset_id AND interface_archived_at IS NULL ORDER BY interface_primary DESC, interface_id");
    $asset['documents'] = fieldDocumentation($client, $asset_id);
    return $asset;
}

function fieldRevealCredential(array $input, int $user_id): array
{
    // Deliberately outside the receipt mechanism: secrets never enter field_requests.
    fieldRequireModule('module_credential');
    $ticket = fieldTicket((int) ($input['ticket_id'] ?? 0));
    $client = (int) $ticket['ticket_client_id']; $id = (int) ($input['credential_id'] ?? 0);
    $row = mysqli_fetch_assoc(fieldDb("SELECT * FROM credentials WHERE credential_id = $id
        AND credential_client_id = $client AND credential_archived_at IS NULL"));
    if (!$row) { throw new DomainException('This credential is unavailable.'); }
    if (empty($_SESSION['user_encryption_session_ciphertext']) || empty($_COOKIE['user_encryption_session_key'])) {
        throw new DomainException('Unlock the credential vault when signing in, then reopen Field Mode.');
    }
    $username = $row['credential_username'] ? decryptCredentialEntry($row['credential_username']) : '';
    $password = $row['credential_password'] ? decryptCredentialEntry($row['credential_password']) : '';
    if ($username === false || $password === false) { throw new DomainException('Sign in again to unlock the credential vault.'); }
    logAudit('Credential', 'View', escapeSql("Field technician $user_id viewed credential $id"), $client, $id);
    fieldDb("UPDATE credentials SET credential_accessed_at = NOW() WHERE credential_id = $id");
    $otp = null;
    if (!empty($row['credential_otp_secret'])) {
        require_once dirname(__DIR__) . '/libs/totp/totp.php';
        $otp = TokenAuth6238::getTokenCode(strtoupper($row['credential_otp_secret']));
    }
    return ['name' => $row['credential_name'], 'username' => $username, 'password' => $password,
        'uri' => $row['credential_uri'], 'note' => fieldPlainText($row['credential_note']),
        'otp' => $otp, 'otp_expires_at' => ($otp !== null ? (intdiv(time(),30)+1)*30000 : null)];
}

function fieldWorkspaceOptions(): array
{
    global $config_smtp_provider;
    return ['statuses' => fieldRows('SELECT ticket_status_id, ticket_status_name FROM ticket_statuses
        WHERE ticket_status_active = 1 AND ticket_status_id NOT IN (1,4,5) ORDER BY ticket_status_order'),
        'work_types' => ticketWorkTypeDefinitions(), 'impacts' => ticketImpactDefinitions(),
        'urgencies' => ticketUrgencyDefinitions(), 'waiting' => ticketWaitingOnDefinitions(),
        'resolution_codes' => ticketResolutionCodeDefinitions(), 'closure_codes' => ticketClosureCodeDefinitions(),
        'templates' => fieldRows('SELECT ticket_template_id, ticket_template_name FROM ticket_templates
            WHERE ticket_template_archived_at IS NULL ORDER BY ticket_template_name'),
        'email_enabled' => !empty($config_smtp_provider)];
}

function fieldWorkspaceAudit(int $ticket_id, int $user_id, string $message): void
{
    $t = fieldTicket($ticket_id);
    logTicketHistory($ticket_id, escapeSql("Field technician $user_id: $message"));
    logAudit('Ticket', 'Edit', escapeSql($message), (int) $t['ticket_client_id'], $ticket_id);
}

function fieldTicketRecipients(array $ticket): array
{
    $result = [];
    if (filter_var($ticket['contact_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $result[strtolower($ticket['contact_email'])] = ['email' => $ticket['contact_email'], 'name' => $ticket['contact_name']];
    }
    $id = (int) $ticket['ticket_id'];
    foreach (fieldRows("SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $id") as $row) {
        if (filter_var($row['watcher_email'], FILTER_VALIDATE_EMAIL)) {
            $result[strtolower($row['watcher_email'])] ??= ['email' => $row['watcher_email'], 'name' => ''];
        }
    }
    return array_values($result);
}

function fieldQueueTicketEmail(array $ticket, string $template, string $html = '', bool $required = false, ?array $recipients = null): int
{
    global $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name,
        $config_base_url, $config_ticket_client_general_notifications;
    $recipients ??= fieldTicketRecipients($ticket);
    if (empty($config_smtp_provider) || !$recipients) {
        if ($required) { throw new DomainException('Email needs a configured mail provider and a valid ticket contact or watcher. Choose a portal update or set the contact first.'); }
        return 0;
    }
    if (!$required && empty($config_ticket_client_general_notifications)) { return 0; }
    $company = mysqli_fetch_assoc(fieldDb('SELECT company_name, company_phone FROM companies WHERE company_id = 1')) ?: [];
    foreach ($recipients as $recipient) {
        $mail = renderN45Email($template, ['company_name' => $company['company_name'] ?? 'N45',
            'contact_name' => $recipient['name'], 'ticket_number' => $ticket['ticket_prefix'] . $ticket['ticket_number'],
            'ticket_subject' => $ticket['ticket_subject'], 'ticket_status' => $ticket['ticket_status_name'],
            'message_html' => $html, 'action_url' => 'https://' . $config_base_url . '/guest/guest_view_ticket.php?ticket_id='
                . (int) $ticket['ticket_id'] . '&url_key=' . rawurlencode($ticket['ticket_url_key']),
            'footer_email' => $config_ticket_from_email, 'footer_phone' => $company['company_phone'] ?? '']);
        fieldDb('INSERT INTO email_queue SET email_from = ' . fieldSql($config_ticket_from_email)
            . ', email_from_name = ' . fieldSql($config_ticket_from_name)
            . ', email_recipient = ' . fieldSql($recipient['email']) . ', email_recipient_name = ' . fieldSql($recipient['name'])
            . ', email_subject = ' . fieldSql($mail['subject']) . ', email_content = ' . fieldSql($mail['html'])
            . ', email_content_plain = ' . fieldSql($mail['text']) . ', email_template_key = ' . fieldSql($mail['template_key']));
    }
    return count($recipients);
}

function fieldWorkspaceReply(array $input, int $user_id): array
{
    $input = fieldWorkspaceDates($input);
    $id = (int) $input['ticket_id']; $ticket = fieldLockTickets([$id])[$id];
    $kind = (string) ($input['visibility'] ?? 'internal');
    if (!in_array($kind, ['internal','portal','email'], true)) { throw new DomainException('Choose who can see this update.'); }
    $text = fieldText($input['message'] ?? '', 'an update', 20000);
    $html = nl2br(escapeHtml($text));
    $minutes = filter_var($input['minutes'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
    if ($minutes === false) { throw new DomainException('Enter time between 0 and 1440 minutes.'); }
    if ($minutes && fieldActiveVisit($user_id)) { throw new DomainException('Finish and review your running visit before adding manual time.'); }
    $worked_at = ticketDisciplineDateTime($input['worked_at'] ?? '');
    if ($minutes && (!$worked_at || strtotime($worked_at) > time() + 60)) { throw new DomainException('Choose when the work occurred, in the past.'); }
    if ($kind !== 'internal' && !empty($input['minutes'])) { throw new DomainException('Record time as an internal entry.'); }
    if ($kind === 'email') {
        $expected = (string) ($input['recipients_hash'] ?? '');
        $recipients = fieldTicketRecipients($ticket);
        if (!hash_equals(hash('sha256', json_encode($recipients)), $expected)) {
            throw new DomainException('The ticket recipients changed. Refresh and review them before sending.');
        }
        fieldQueueTicketEmail($ticket, 'ticket.updated', $html, true, $recipients);
    }
    $type = $kind === 'internal' ? 'Internal' : 'Public';
    $duration = sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    fieldDb('INSERT INTO ticket_replies SET ticket_reply = ' . fieldSql($html) . ', ticket_reply_type = ' . fieldSql($type)
        . ', ticket_reply_time_worked = ' . fieldSql($duration) . ", ticket_reply_by = $user_id,
        ticket_reply_ticket_id = $id" . ($minutes ? ', ticket_reply_created_at = ' . fieldSql($worked_at) : ''));
    $reply = (int) mysqli_insert_id($GLOBALS['mysqli']);
    if ($type === 'Public' && !$ticket['ticket_first_response_at']) { setTicketFirstResponse($id); }
    fieldDb("UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $id");
    fieldWorkspaceAudit($id, $user_id, $minutes ? "Recorded $minutes minutes of work" : "Added a $type update");
    return ['message' => $kind === 'email' ? 'Reply saved and email queued.' : ($minutes ? 'Time entry saved.' : 'Update saved.'),
        'reply_id' => $reply, 'ticket_id' => $id, 'event' => $type === 'Public' ? 'reply_reply_agent_public' : 'ticket_reply_agent_internal'];
}

function fieldValidateRelation(string $kind, int $id, int $client): void
{
    if (!$id) { return; }
    $columns = ['contact' => ['contacts','contact'], 'location' => ['locations','location'], 'asset' => ['assets','asset'], 'project' => ['projects','project']];
    [$table, $prefix] = $columns[$kind];
    $extra = $kind === 'project' ? ' AND project_completed_at IS NULL' : '';
    if (!fieldRows("SELECT {$prefix}_id FROM $table WHERE {$prefix}_id = $id AND {$prefix}_client_id = $client
        AND {$prefix}_archived_at IS NULL $extra FOR UPDATE")) { throw new DomainException("The selected $kind is unavailable for this client."); }
}

function fieldWorkspacePlan(array $input, int $user_id): array
{
    $input = fieldWorkspaceDates($input);
    $id = (int) $input['ticket_id']; $t = fieldLockTickets([$id])[$id]; $client = (int) $t['ticket_client_id'];
    if (!hash_equals(fieldPlanVersion($t), (string) ($input['expected_revision'] ?? ''))) {
        throw new DomainException('The ticket changed. Refresh to review the latest details before saving.');
    }
    $values = ticketDisciplineAssessmentInput($input);
    $status = (int) ($input['status_id'] ?? 0);
    if (!fieldRows("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_id = $status
        AND ticket_status_active = 1 AND ticket_status_id NOT IN (1,4,5)")) { throw new DomainException('Choose an active work status.'); }
    $status = ticketDisciplineStatusForWaitingOn($values['waiting_on']) ?? $status;
    $assigned = max(0, (int) ($input['assigned_to'] ?? 0));
    if ($assigned && !in_array($assigned, array_map('intval', array_column(fieldOwners($client), 'user_id')), true)) {
        throw new DomainException('Choose a technician with access to this client.');
    }
    if ($assigned !== (int) $t['ticket_assigned_to'] && (int) $t['ticket_assigned_to'] > 0) {
        ticketDisciplineRecordHandoff($id, (int) $t['ticket_assigned_to'], $assigned,
            (string) ($input['handoff_reason'] ?? ''), (string) ($input['handoff_state'] ?? ''), $values['next_action'], $user_id);
    }
    $contact = max(0, (int) ($input['contact_id'] ?? 0)); $location = max(0, (int) ($input['location_id'] ?? 0));
    if ($location !== (int) $t['location_id'] && fieldServicePendingWork($id)) {
        throw new DomainException('Finish the visit and review its time before moving this job to another site.');
    }
    fieldValidateRelation('contact', $contact, $client); fieldValidateRelation('location', $location, $client);
    $schedule = ticketDisciplineDateTime($input['scheduled_at'] ?? '');
    $sets = ["ticket_status = $status", "ticket_assigned_to = $assigned", "ticket_contact_id = $contact", "ticket_location_id = $location",
        'ticket_schedule = ' . fieldSql($schedule), 'ticket_updated_at = NOW()'];
    foreach (['work_type','impact','urgency','priority','waiting_on','next_action','next_action_due_at'] as $key) {
        $sets[] = 'ticket_' . $key . ' = ' . fieldSql($values[$key]);
    }
    fieldDb('UPDATE tickets SET ' . implode(', ', $sets) . " WHERE ticket_id = $id");
    applyTicketSla($id, null, null, true); refreshRunbookTaskStates($id);
    if ($assigned && $assigned !== (int) $t['ticket_assigned_to']) {
        fieldDb("INSERT INTO notifications SET notification_type = 'Ticket', notification = " . fieldSql('Field handoff: ' . $t['ticket_subject'])
            . ', notification_action = ' . fieldSql('/agent/field/#job/' . $id . '/overview') . ", notification_client_id = $client, notification_user_id = $assigned");
    }
    fieldWorkspaceAudit($id, $user_id, 'Updated status, assignment, schedule, and follow-up');
    return ['message' => 'Job details saved.', 'ticket_id' => $id, 'event' => 'ticket_update'];
}

function fieldWorkspaceTransition(array $input, int $user_id): array
{
    global $mysqli;
    $id = (int) $input['ticket_id']; $before = fieldTicket($id); $client = (int) $before['ticket_client_id'];
    fieldClient($client, true);
    $project = (int) $before['ticket_project_id'];
    if ($project) { fieldValidateRelation('project', $project, $client); }
    $locked = runbookLockTicketForTransition($id, true);
    if ((int) $locked['ticket_client_id'] !== $client || (int) $locked['ticket_project_id'] !== $project) {
        throw new DomainException('The ticket changed. Refresh before continuing.');
    }
    $t = fieldTicket($id); $kind = (string) ($input['transition'] ?? '');
    if ((int) ($input['expected_status'] ?? -1) !== (int) $t['ticket_status']) { throw new DomainException('The ticket status changed. Refresh before continuing.'); }
    if ($kind === 'reopen') {
        if ((int) $t['ticket_status'] !== 4 || $t['ticket_closed_at']) { throw new DomainException('Only a resolved ticket can be reopened. Closed tickets remain in history; create a follow-up job.'); }
        ticketDisciplineClearResolutionForReopen($id, 'agent', $user_id);
        fieldDb("UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL WHERE ticket_id = $id");
        resetTicketResolutionSla($id);
    } elseif (in_array($kind, ['resolve','close'], true)) {
        if ((int) $t['ticket_status'] === 5 || $t['ticket_closed_at'] || ($kind === 'resolve' && $t['ticket_resolved_at'])) {
            throw new DomainException('This ticket has already been completed.');
        }
        $was_resolved = (int) $t['ticket_status'] === 4 && !empty($t['ticket_resolved_at']);
        if (!$was_resolved) {
            ticketDisciplineStoreResolution($id, $input['resolution_code'] ?? '', $input['resolution_summary'] ?? '', $input['root_cause'] ?? '');
        }
        if ($kind === 'close') { ticketDisciplineStoreClosure($id, $input['closure_code'] ?? ''); }
        [$allowed, $reason] = runbookTicketCanResolve($id, true);
        if (!$allowed) { throw new DomainException(fieldPlainText($reason)); }
        [$documented, $documentation_error] = documentationTicketCanResolve($id);
        if (!$documented) { throw new DomainException(fieldPlainText($documentation_error)); }
        if (!$t['ticket_first_response_at']) { setTicketFirstResponse($id); }
        $status = $kind === 'close' ? 5 : 4;
        $expected_status = (int) $t['ticket_status'];
        fieldDb("UPDATE tickets SET ticket_status = $status, ticket_resolved_at = COALESCE(ticket_resolved_at,NOW())"
            . ($kind === 'close' ? ", ticket_closed_at = NOW(), ticket_closed_by = $user_id" : '')
            . " WHERE ticket_id = $id AND ticket_status = $expected_status AND ticket_closed_at IS NULL");
        if (mysqli_affected_rows($mysqli) !== 1) { throw new DomainException('The ticket changed. Refresh before completing it.'); }
        if (!$was_resolved) { ticketDisciplineRecordResolutionEvent($id, 'resolved', 'agent', $user_id); }
        if ($kind === 'close') { ticketDisciplineRecordResolutionEvent($id, 'closed', 'agent', $user_id); }
        documentationRecordChangePassport($id, $status, $user_id, true); setTicketResolutionSlaMet($id);
        fieldQueueTicketEmail(fieldTicket($id), $kind === 'close' ? 'ticket.closed' : 'ticket.resolved');
    } else { throw new DomainException('Choose resolve, close, or reopen.'); }
    syncTicketSlaClock($id);
    fieldWorkspaceAudit($id, $user_id, "Ticket action: $kind");
    return ['message' => ['resolve'=>'Ticket resolved.','close'=>'Ticket closed.','reopen'=>'Ticket reopened.'][$kind],
        'ticket_id' => $id, 'event' => $kind === 'reopen' ? 'ticket_update' : ($kind === 'close' ? 'ticket_close' : 'ticket_resolve')];
}

function fieldWorkspaceCreate(array $input, int $user_id): array
{
    global $mysqli, $config_ticket_prefix;
    $input = fieldWorkspaceDates($input);
    $client = (int) ($input['client_id'] ?? 0); fieldClient($client, true);
    $subject = fieldText($input['subject'] ?? '', 'a ticket subject', 500);
    $details = fieldText($input['details'] ?? '', 'work instructions', 20000);
    $template_id = max(0, (int) ($input['template_id'] ?? 0)); $version_id = 0;
    if ($template_id) {
        $template = mysqli_fetch_assoc(fieldDb("SELECT t.*, v.runbook_version_id, v.runbook_version_subject,
            v.runbook_version_details FROM ticket_templates t LEFT JOIN runbook_versions v
            ON v.runbook_version_id = t.ticket_template_published_version_id
            AND v.runbook_version_ticket_template_id = t.ticket_template_id
            WHERE t.ticket_template_id = $template_id AND t.ticket_template_archived_at IS NULL"));
        if (!$template) { throw new DomainException('This job template is unavailable.'); }
        $version_id = (int) $template['ticket_template_published_version_id'];
        if ($version_id && (int) $template['runbook_version_id'] !== $version_id) { throw new DomainException('The published runbook is unavailable.'); }
        // Published work instructions remain authoritative; field-specific details are an addendum.
        $details = fieldPlainText($version_id ? $template['runbook_version_details'] : $template['ticket_template_details'])
            . "\n\nField job details\n" . $details;
    }
    $assessment = ticketDisciplineAssessmentInput($input + ['work_type'=>'incident','impact'=>'medium','urgency'=>'medium']);
    $contact = max(0, (int) ($input['contact_id'] ?? 0)); $location = max(0, (int) ($input['location_id'] ?? 0));
    $project = max(0, (int) ($input['project_id'] ?? 0));
    fieldValidateRelation('project', $project, $client);
    fieldValidateRelation('contact', $contact, $client); fieldValidateRelation('location', $location, $client);
    if (!in_array($user_id, array_map('intval', array_column(fieldOwners($client), 'user_id')), true)) {
        throw new DomainException('Your account cannot be assigned work for this client.');
    }
    fieldDb('UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
        config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    $number = (int) mysqli_insert_id($mysqli);
    if (!$number) { throw new RuntimeException('A ticket number could not be allocated.'); }
    $schedule = ticketDisciplineDateTime($input['scheduled_at'] ?? '');
    fieldDb('INSERT INTO tickets SET ticket_prefix = ' . fieldSql($config_ticket_prefix) . ", ticket_number = $number,
        ticket_source = 'Field Mode', ticket_status = 2, ticket_subject = " . fieldSql($subject)
        . ', ticket_details = ' . fieldSql(nl2br(escapeHtml($details))) . ', ticket_work_type = ' . fieldSql($assessment['work_type'])
        . ', ticket_priority = ' . fieldSql($assessment['priority']) . ', ticket_impact = ' . fieldSql($assessment['impact'])
        . ', ticket_urgency = ' . fieldSql($assessment['urgency']) . ', ticket_schedule = ' . fieldSql($schedule)
        . ', ticket_url_key = ' . fieldSql(randomString(32)) . ", ticket_client_id = $client,
        ticket_created_by = $user_id, ticket_assigned_to = $user_id, ticket_contact_id = $contact,
        ticket_location_id = $location, ticket_project_id = $project");
    $id = (int) mysqli_insert_id($mysqli);
    applyTicketSla($id, null, null, true);
    if ($template_id) { addTasksFromTicketTemplate($id, $template_id, $version_id, true); }
    $parent = max(0, (int) ($input['followup_ticket_id'] ?? 0));
    if ($parent) {
        $original = fieldTicket($parent);
        if ((int) $original['ticket_client_id'] !== $client) { throw new DomainException('A follow-up must belong to the original client.'); }
        // A plain history reference does not change the closed record's lifecycle.
        logTicketHistory($id, escapeSql('Follow-up to ' . $original['ticket_prefix'] . $original['ticket_number']));
    }
    fieldWorkspaceAudit($id, $user_id, 'Created a field job');
    return ['message' => 'Job created and assigned to you.', 'ticket_id' => $id, 'event' => 'ticket_create'];
}

function fieldWorkspaceTask(array $input, int $user_id): array
{
    $id = (int) $input['ticket_id']; $t = fieldLockTickets([$id])[$id];
    $task = (int) ($input['task_id'] ?? 0);
    if (($input['operation'] ?? '') === 'reopen') {
        if (!fieldRows("SELECT task_id FROM tasks WHERE task_id = $task AND task_ticket_id = $id")) {
            throw new DomainException('This task does not belong to the job.');
        }
        reopenRunbookTaskAndDependents($task, $user_id, fieldText($input['reason'] ?? '', 'a reopening reason', 255));
    } else {
        $name = fieldText($input['name'] ?? '', 'a task name', 255);
        $instructions = fieldText($input['instructions'] ?? '', 'instructions', 10000, false);
        $order = (int) mysqli_fetch_row(fieldDb("SELECT COALESCE(MAX(task_order),0)+1 FROM tasks WHERE task_ticket_id = $id"))[0];
        fieldDb('INSERT INTO tasks SET task_name = ' . fieldSql($name) . ', task_instructions = ' . fieldSql(nl2br(escapeHtml($instructions)))
            . ", task_ticket_id = $id, task_assigned_to = $user_id, task_order = $order");
        $task = (int) mysqli_insert_id($GLOBALS['mysqli']);
    }
    refreshRunbookTaskStates($id); fieldWorkspaceAudit($id, $user_id, "Updated task $task");
    return ['message' => 'Task saved.', 'task_id' => $task];
}

function fieldWorkspacePromise(array $input, int $user_id): array
{
    $input = fieldWorkspaceDates($input);
    $id = (int) $input['ticket_id']; $t = fieldLockTickets([$id])[$id]; $client = (int) $t['ticket_client_id'];
    $promise = max(0, (int) ($input['promise_id'] ?? 0));
    if (!$promise) {
        $summary = fieldText($input['summary'] ?? '', 'a commitment', 500);
        $due = ticketDisciplineFutureDateTime($input['due_at'] ?? '', true);
        fieldDb('INSERT INTO ticket_customer_promises SET ticket_customer_promise_summary = ' . fieldSql($summary)
            . ', ticket_customer_promise_due_at = ' . fieldSql($due) . ", ticket_customer_promise_ticket_id = $id,
            ticket_customer_promise_client_id = $client, ticket_customer_promise_created_by = $user_id");
        $promise = (int) mysqli_insert_id($GLOBALS['mysqli']);
        ticketDisciplineRecordPromiseEvent($promise, $id, 'created', null, 'open', $user_id, $summary);
    } else {
        $action = (string) ($input['status'] ?? '');
        if (!in_array($action, ['fulfilled','cancelled'], true)) { throw new DomainException('Choose fulfilled or cancelled.'); }
        $reason = fieldText($input['reason'] ?? '', 'a completion note', 500);
        if (!fieldRows("SELECT ticket_customer_promise_id FROM ticket_customer_promises WHERE ticket_customer_promise_id = $promise
            AND ticket_customer_promise_ticket_id = $id AND ticket_customer_promise_status = 'open' FOR UPDATE")) {
            throw new DomainException('This commitment is no longer open for this job.');
        }
        fieldDb('UPDATE ticket_customer_promises SET ticket_customer_promise_status = ' . fieldSql($action)
            . ", ticket_customer_promise_completed_by = $user_id, ticket_customer_promise_completed_at = NOW()
            WHERE ticket_customer_promise_id = $promise");
        ticketDisciplineRecordPromiseEvent($promise, $id, $action, 'open', $action, $user_id, $reason);
    }
    fieldWorkspaceAudit($id, $user_id, "Updated customer commitment $promise");
    return ['message' => 'Customer commitment saved.'];
}

function fieldWorkspaceAsset(array $input, int $user_id): array
{
    fieldRequireModule('module_client', 2);
    $id = (int) $input['ticket_id']; $t = fieldLockTickets([$id])[$id]; $client = (int) $t['ticket_client_id'];
    $asset = (int) ($input['asset_id'] ?? 0); fieldValidateRelation('asset', $asset, $client);
    if (!$asset) { throw new DomainException('Choose an asset.'); }
    if (($input['operation'] ?? '') === 'link') {
        fieldDb("UPDATE tickets SET ticket_asset_id = $asset WHERE ticket_id = $id");
        applyTicketSla($id, null, null, true);
    } else {
        $status = fieldText($input['status'] ?? '', 'asset status', 200, false);
        $physical = fieldText($input['physical_location'] ?? '', 'physical location', 200, false);
        $note = fieldText($input['note'] ?? '', 'an asset update', 10000);
        // Append a dated field update, preserving existing rich documentation.
        fieldDb('UPDATE assets SET asset_status = ' . fieldSql($status) . ', asset_physical_location = ' . fieldSql($physical)
            . ', asset_notes = CONCAT(COALESCE(asset_notes,\'\'), ' . fieldSql('<p>' . escapeHtml(date('Y-m-d H:i') . " · Technician $user_id: " . $note) . '</p>')
            . ") WHERE asset_id = $asset AND asset_client_id = $client");
    }
    logAudit('Asset', 'Edit', escapeSql("Field technician $user_id updated asset $asset"), $client, $asset);
    return ['message' => 'Asset updated.'];
}

function fieldWorkspaceAfterCommit(array $result): void
{
    if (empty($result['event']) || empty($result['ticket_id'])) { return; }
    $id = (int) $result['ticket_id'];
    if (in_array($result['event'], ['ticket_resolve','ticket_close'], true)) {
        automationResolveTicketIncidentsSafely($id, $result['event'] === 'ticket_close' ? 'ticket_closed' : 'ticket_resolved');
    }
    try { triggerCustomAction($result['event'], $id); }
    catch (Throwable $exception) { error_log('Field post-commit hook: ' . $exception->getMessage()); }
}

function fieldWorkspaceFile(array $input, int $user_id, ?string &$batch): array
{
    $ticket = (int) $input['ticket_id']; fieldLockTickets([$ticket]);
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'] ?? '')) { throw new DomainException('Choose a file to upload while connected.'); }
    $size = filesize($file['tmp_name']);
    if ($size < 1 || $size > 12 * 1024 * 1024) { throw new DomainException('Choose a file under 12 MiB.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['application/pdf'=>'pdf','text/plain'=>'txt','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($types[$mime])) { throw new DomainException('Use PDF, plain text, JPG, PNG, or WebP.'); }
    if (str_starts_with($mime, 'image/')) {
        $_FILES['photo'] = $file;
        return ['message'=>'File attached.', 'attachment_id'=>fieldStagePhoto($input, $ticket, $user_id, $batch)];
    }
    $name = fieldText($input['caption'] ?? '', 'a file description', 200);
    $task = max(0, (int) ($input['task_id'] ?? 0));
    if ($task && !fieldRows("SELECT task_id FROM tasks WHERE task_id = $task AND task_ticket_id = $ticket
        AND task_completed_at IS NULL AND task_state <> 'Skipped' FOR UPDATE")) { throw new DomainException('Choose an unfinished task on this job.'); }
    $batch = fileStagingBatchToken(); $source = dirname(__DIR__) . '/uploads/.field-incoming/' . $batch;
    if (!mkdir($source, 0700, true)) { throw new RuntimeException('Could not prepare the upload.'); }
    $reference = bin2hex(random_bytes(24)) . '.' . $types[$mime];
    try {
        if (!move_uploaded_file($file['tmp_name'], $source . '/' . $reference)) { throw new RuntimeException('Could not receive the file.'); }
        fileStagingStageDirectory($source, 'uploads/tickets/' . $ticket, $batch, 'field_evidence', $ticket);
    } finally {
        if (is_file($source . '/' . $reference)) { unlink($source . '/' . $reference); }
        rmdir($source);
    }
    fieldDb("INSERT INTO ticket_attachments SET ticket_attachment_ticket_id = $ticket, ticket_attachment_name = "
        . fieldSql($name . '.' . $types[$mime]) . ', ticket_attachment_reference_name = ' . fieldSql($reference));
    $id = (int) mysqli_insert_id($GLOBALS['mysqli']);
    if ($task) {
        fieldDb("INSERT INTO task_evidence SET task_evidence_task_id = $task, task_evidence_type = 'file',
            task_evidence_attachment_id = $id, task_evidence_note = " . fieldSql($name) . ", task_evidence_submitted_by = $user_id");
    }
    return ['message'=>'File attached.', 'attachment_id'=>$id];
}

function fieldRenderDocument(array $doc): void
{
    require_once dirname(__DIR__) . '/libs/htmlpurifier/HTMLPurifier.standalone.php';
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $config->set('URI.AllowedSchemes', ['data'=>true,'https'=>true,'http'=>true]);
    $purifier = new HTMLPurifier($config);
    $content = $purifier->purify($doc['document_content']);
    // Stored editor images are rooted at uploads; this nested route must preserve them.
    $content = preg_replace('~(src=["\'])(?:\.\./)*uploads/~i', '$1/uploads/', $content);
    $theme = ($_GET['theme'] ?? '') === 'dark' ? 'dark' : 'light';
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox allow-same-origin");
    echo '<!doctype html><html lang="en" data-theme="' . $theme . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . escapeHtml($doc['document_name']) . '</title><link rel="stylesheet" href="/agent/field/field.css">'
        . '<body class="document-frame"><h1>' . escapeHtml($doc['document_name']) . '</h1>'
        . $content . '</body></html>';
    exit;
}

function fieldWorkspaceDocument(array $input, int $user_id): array
{
    fieldRequireModule('module_client', 2);
    $ticket = (int) $input['ticket_id']; $t = fieldLockTickets([$ticket])[$ticket]; $client = (int) $t['ticket_client_id'];
    $id = max(0, (int) ($input['document_id'] ?? 0));
    $note = fieldText($input['note'] ?? '', 'documentation', 20000);
    $html = '<h2>' . escapeHtml('Field update · ' . date('Y-m-d H:i')) . '</h2><p>' . nl2br(escapeHtml($note)) . '</p>';
    if ($id) {
        if (!fieldRows("SELECT document_id FROM documents WHERE document_id = $id AND document_client_id = $client
            AND document_archived_at IS NULL")) { throw new DomainException('This document is unavailable for the client.'); }
        documentationInvalidateDocumentLocked($id, $client, $user_id);
        fieldDb("INSERT INTO document_versions (document_version_name, document_version_description,
            document_version_content, document_version_created_by, document_version_created_at, document_version_document_id)
            SELECT document_name, document_description, document_content,
                COALESCE(NULLIF(document_updated_by,0),document_created_by),
                COALESCE(document_updated_at,document_created_at), document_id FROM documents
            WHERE document_id = $id AND document_client_id = $client");
        fieldDb('UPDATE documents SET document_content = CONCAT(COALESCE(document_content,\'\'),' . fieldSql($html)
            . '), document_content_raw = CONCAT(COALESCE(document_content_raw,\'\'),' . fieldSql("\n" . $note)
            . "), document_updated_by = $user_id WHERE document_id = $id");
    } else {
        $name = fieldText($input['name'] ?? '', 'a document name', 200);
        fieldDb('INSERT INTO documents SET document_name = ' . fieldSql($name) . ', document_content = ' . fieldSql($html)
        . ', document_content_raw = ' . fieldSql($note) . ", document_client_id = $client, document_created_by = $user_id,
            document_updated_by = $user_id, document_client_visible = 0");
        $id = (int) mysqli_insert_id($GLOBALS['mysqli']);
    }
    logAudit('Document', 'Edit', escapeSql("Field technician $user_id recorded documentation on job $ticket"), $client, $id);
    return ['message'=>'Documentation saved. Linked verification must be reviewed after a change.', 'document_id'=>$id];
}

function fieldWorkspaceDocumentationAction(array $input, int $user_id): array
{
    $ticket_id = (int) $input['ticket_id'];
    $ticket = fieldLockTickets([$ticket_id])[$ticket_id]; $client = (int) $ticket['ticket_client_id'];
    if (($input['operation'] ?? '') === 'assess') {
        $impact = (string) ($input['impact'] ?? ''); $change = (int) ($input['configuration_change'] ?? 0) === 1;
        if (!in_array($impact, ['None','Required'], true) || ($change && $impact !== 'Required')) {
            throw new DomainException('Configuration changes require affected documentation. Choose the documentation impact.');
        }
        if ($impact !== $ticket['ticket_documentation_impact'] || (int) $change !== (int) $ticket['ticket_configuration_change']) {
            documentationAssessTicket($ticket_id, $client, $change, $impact, $user_id);
        }
        fieldWorkspaceAudit($ticket_id, $user_id, 'Assessed documentation impact: ' . $impact);
        return ['message'=>'Documentation impact recorded.'];
    }
    fieldRequireModule('module_client');
    $id = (int) ($input['obligation_id'] ?? 0); $document = (int) ($input['document_id'] ?? 0);
    $obligation = mysqli_fetch_assoc(fieldDb("SELECT documentation_obligation_revision FROM client_documentation_obligations
        WHERE documentation_obligation_id = $id AND documentation_obligation_client_id = $client FOR UPDATE"));
    if (!$obligation || (int) $obligation['documentation_obligation_revision'] !== (int) ($input['revision'] ?? -1)) {
        throw new DomainException('The requirement changed. Refresh before verifying it.');
    }
    if ((int) ($input['confirm_verified'] ?? 0) !== 1) { throw new DomainException('Confirm that you reviewed the document and evidence.'); }
    documentationLinkTicketObligation($ticket_id, $id, $user_id, true, true);
    $type = (string) ($input['evidence_type'] ?? 'document');
    $evidence = match ($type) {
        'none' => ['type'=>'none','reference_type'=>'policy','reference_id'=>0],
        'note' => ['type'=>'note','reference_type'=>'note','reference_id'=>0,'locator'=>fieldText($input['note'] ?? '', 'a verification note', 4000)],
        'document' => ['type'=>'reference','reference_type'=>'document','reference_id'=>$document],
        'file' => ['type'=>'reference','reference_type'=>'file','reference_id'=>(int) ($input['file_id'] ?? 0)],
        'url' => ['type'=>'reference','reference_type'=>'url','reference_id'=>0,'locator'=>fieldText($input['note'] ?? '', 'an evidence URL', 2000)],
        default => throw new DomainException('Choose a supported verification evidence type.'),
    };
    try {
        documentationVerifyObligation($id, $document, $evidence, (int) $input['revision'], $user_id, $ticket_id, 'agent', true);
    } catch (RuntimeException | InvalidArgumentException $exception) {
        throw new DomainException($exception->getMessage());
    }
    return ['message'=>'Documentation verified for this job.'];
}
