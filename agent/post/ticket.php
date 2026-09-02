<?php

/*
 * ITFlow - GET/POST request handler for client tickets
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// A soft-deleted ticket is read-only outside the administrator retention
// workflow. Cover canonical, bulk, merge-target, reply, and legacy GET shapes
// so a forged agent request cannot mutate a hidden ticket through a side path.
$retention_mutation_ticket_ids = [];
foreach (['ticket_id', 'merge_into_ticket_id'] as $retention_ticket_key) {
    if (isset($_POST[$retention_ticket_key])) {
        $retention_mutation_ticket_ids[] = intval($_POST[$retention_ticket_key]);
    }
    if (isset($_GET[$retention_ticket_key])) {
        $retention_mutation_ticket_ids[] = intval($_GET[$retention_ticket_key]);
    }
}
foreach (['resolve_ticket', 'close_ticket', 'reopen_ticket', 'cancel_ticket_schedule', 'delete_ticket'] as $retention_ticket_key) {
    if (isset($_GET[$retention_ticket_key])) {
        $retention_mutation_ticket_ids[] = intval($_GET[$retention_ticket_key]);
    }
}
foreach ((array) ($_POST['ticket_ids'] ?? []) as $retention_ticket_id) {
    $retention_mutation_ticket_ids[] = intval($retention_ticket_id);
}
$retention_reply_id = intval($_POST['ticket_reply_id'] ?? $_GET['archive_ticket_reply'] ?? 0);
if ($retention_reply_id > 0) {
    $retention_reply_row = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_reply_ticket_id
        FROM ticket_replies WHERE ticket_reply_id = $retention_reply_id LIMIT 1",
        'Could not resolve the retained reply ticket'));
    $retention_mutation_ticket_ids[] = intval($retention_reply_row['ticket_reply_ticket_id'] ?? 0);
}
$retention_watcher_id = intval($_GET['delete_ticket_watcher'] ?? 0);
if ($retention_watcher_id > 0) {
    $retention_watcher_row = mysqli_fetch_assoc(retentionDbQuery("SELECT watcher_ticket_id
        FROM ticket_watchers WHERE watcher_id = $retention_watcher_id LIMIT 1",
        'Could not resolve the retained watcher ticket'));
    $retention_mutation_ticket_ids[] = intval($retention_watcher_row['watcher_ticket_id'] ?? 0);
}
$retention_mutation_ticket_ids = array_values(array_unique(array_filter($retention_mutation_ticket_ids)));
if ($retention_mutation_ticket_ids) {
    $retention_mutation_ticket_id_sql = implode(',', $retention_mutation_ticket_ids);
    if (retentionCount("SELECT COUNT(*) FROM tickets WHERE ticket_id IN ($retention_mutation_ticket_id_sql)
        AND ticket_deleted_at IS NOT NULL", 'Could not inspect retained ticket mutations') > 0) {
        flashAlert('Deleted tickets are immutable outside Administration > Retention.', 'error');
        redirect('/admin/retention.php');
    }
}

if (isset($_POST['add_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    $assigned_to = intval($_POST['assigned_to'] ?? 0);
    if ($assigned_to == 0) {
        $ticket_status = 1;
    } else {
        $ticket_status = 2;
    }
    $contact_id = intval($_POST['contact_id'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $priority = escapeSql($_POST['priority'] ?? 'Low');
    $vendor_ticket_number = escapeSql($_POST['vendor_ticket_number'] ?? '');
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $asset_id = intval($_POST['asset_id'] ?? 0);
    $location_id = intval($_POST['location_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    $use_primary_contact = intval($_POST['use_primary_contact'] ?? 0);
    $ticket_template_id = intval($_POST['ticket_template_id'] ?? 0);
    $pinned_runbook_version_id = 0;
    $subject_raw = $_POST['subject'] ?? '';
    $details_raw = $_POST['details'] ?? '';
    $configuration_change = intval($_POST['configuration_change'] ?? 0) === 1 ? 1 : 0;
    $documentation_impact = (string) ($_POST['documentation_impact'] ?? 'Unassessed');
    if (!in_array($documentation_impact, ['None', 'Required'], true)) {
        flashAlert('Select whether this ticket affects required client documentation', 'error');
        redirect();
    }
    if ($configuration_change && $documentation_impact !== 'Required') {
        flashAlert('A configuration-changing ticket must identify its documentation impact', 'error');
        redirect();
    }
    $documentation_impact_sql = escapeSql($documentation_impact);

    $client = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT client_id FROM clients
        WHERE client_id = $client_id AND client_lead = 0 AND client_archived_at IS NULL LIMIT 1",
        'Could not validate the selected client'));
    if (!$client) {
        flashAlert('The selected client is unavailable or archived', 'error');
        redirect();
    }
    enforceClientAccess($client_id);

    if ($ticket_template_id) {
        // Normal ticket creation always starts the template's current published
        // version. Historical versions are immutable audit records, not a
        // client-selectable input. Project stages have their own admin-pinned
        // version flow.
        $template = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_template_subject,
            ticket_template_details, ticket_template_published_version_id,
            runbook_version_id, runbook_version_subject, runbook_version_details,
            (SELECT COUNT(*) FROM runbook_versions history
                WHERE history.runbook_version_ticket_template_id = ticket_template_id) AS runbook_version_count
            FROM ticket_templates
            LEFT JOIN runbook_versions
                ON runbook_version_id = ticket_template_published_version_id
                AND runbook_version_ticket_template_id = ticket_template_id
            WHERE ticket_template_id = $ticket_template_id
            AND ticket_template_archived_at IS NULL LIMIT 1", 'Could not validate the selected ticket template'));
        if (!$template) {
            flashAlert('The selected ticket template is unavailable or archived', 'error');
            redirect();
        }
        // The published pointer is the release decision. Never guess another
        // historical version when that pointer is missing or corrupt.
        $pinned_runbook_version_id = intval($template['ticket_template_published_version_id']);
        if ($pinned_runbook_version_id) {
            if (intval($template['runbook_version_id']) !== $pinned_runbook_version_id) {
                flashAlert('The selected runbook version is no longer available for that template', 'error');
                redirect();
            }
            // The picker previews this immutable payload. Re-read it here so a
            // stale browser or edited request cannot execute different content.
            $subject_raw = $template['runbook_version_subject'];
            $details_raw = $template['runbook_version_details'];
        } elseif (intval($template['runbook_version_count']) > 0) {
            flashAlert('This template has runbook history but no valid published release. An administrator must republish it before use.', 'error');
            redirect();
        } elseif (trim((string) $subject_raw) === '') {
            $subject_raw = $template['ticket_template_subject'];
        }
    }

    if (trim((string) $subject_raw) === '') {
        flashAlert('A ticket subject is required', 'error');
        redirect();
    }

    $subject = escapeSql($subject_raw);
    $details = mysqli_real_escape_string($mysqli, $details_raw);
    $billable = intval($_POST['billable'] ?? 0);
    // Validate/clean due field
    $dueInput = $_POST['due'] ?? null;
    if ($dueInput === null || trim($dueInput) === '') {
        $due = 'NULL'; // prepare as SQL-safe string
    } else {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $dueInput); // for <input type="datetime-local">
        if ($d !== false) {
            $due = "'" . $d->format('Y-m-d H:i:s') . "'"; // wrap in quotes for SQL
        } else {
            $due = 'NULL'; // fallback if invalid
        }
    }

    // Add the primary contact as the ticket contact if "Use primary contact" is checked
    if ($use_primary_contact == 1) {
        $sql = ticketCreationDbQuery("SELECT contact_id FROM contacts
            WHERE contact_client_id = $client_id AND contact_primary = 1
            AND contact_archived_at IS NULL ORDER BY contact_id LIMIT 1", 'Could not find the primary contact');
        $row = mysqli_fetch_assoc($sql);
        $contact_id = intval($row['contact_id'] ?? 0);
        if (!$contact_id) {
            flashAlert('This client does not have an active primary contact', 'error');
            redirect();
        }
    } elseif ($contact_id) {
        $contact = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id FROM contacts
            WHERE contact_id = $contact_id AND contact_client_id = $client_id
            AND contact_archived_at IS NULL LIMIT 1", 'Could not validate the selected contact'));
        if (!$contact) {
            flashAlert('The selected contact is unavailable for this client', 'error');
            redirect();
        }
    }

    if ($assigned_to) {
        $assignee = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT user_id FROM users
            WHERE user_id = $assigned_to AND user_type = 1 AND user_status = 1
            AND user_archived_at IS NULL LIMIT 1", 'Could not validate the selected assignee'));
        if (!$assignee) {
            flashAlert('The selected assignee is unavailable', 'error');
            redirect();
        }
    }

    if ($category_id) {
        $category = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT category_id FROM categories
            WHERE category_id = $category_id AND category_type = 'Ticket'
            AND category_archived_at IS NULL LIMIT 1", 'Could not validate the selected category'));
        if (!$category) {
            flashAlert('The selected ticket category is unavailable', 'error');
            redirect();
        }
    }

    $related_records = [
        [$asset_id, "SELECT asset_id FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id AND asset_archived_at IS NULL", 'asset'],
        [$location_id, "SELECT location_id FROM locations WHERE location_id = $location_id AND location_client_id = $client_id AND location_archived_at IS NULL", 'location'],
        [$project_id, "SELECT project_id FROM projects WHERE project_id = $project_id AND project_client_id = $client_id AND project_archived_at IS NULL AND project_completed_at IS NULL", 'project'],
        [$vendor_id, "SELECT vendor_id FROM vendors WHERE vendor_id = $vendor_id AND vendor_client_id = $client_id AND vendor_archived_at IS NULL", 'vendor'],
    ];
    foreach ($related_records as [$related_id, $related_query, $related_label]) {
        if ($related_id && !mysqli_fetch_assoc(ticketCreationDbQuery($related_query . ' LIMIT 1', "Could not validate the selected $related_label"))) {
            flashAlert("The selected $related_label is unavailable for this client", 'error');
            redirect();
        }
    }

    $additional_asset_ids = [];
    foreach (array_unique(array_map('intval', (array) ($_POST['additional_assets'] ?? []))) as $additional_asset_id) {
        if (!$additional_asset_id) {
            continue;
        }
        $additional_asset = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id FROM assets
            WHERE asset_id = $additional_asset_id AND asset_client_id = $client_id
            AND asset_archived_at IS NULL LIMIT 1", 'Could not validate an additional asset'));
        if (!$additional_asset) {
            flashAlert('One or more additional assets are unavailable for this client', 'error');
            redirect();
        }
        $additional_asset_ids[] = $additional_asset_id;
    }

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_ticket_prefix = escapeSql($config_ticket_prefix);
    $config_ticket_from_name = escapeSql($config_ticket_from_name);
    $config_ticket_from_email = escapeSql($config_ticket_from_email);
    $config_base_url = escapeSql($config_base_url);

    //Generate a unique URL key for clients to access
    $url_key = randomString(32);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket creation transaction');
        }
        $transaction_started = true;

        if ($project_id) {
            $locked_project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_client_id,
                project_completed_at, project_archived_at FROM projects
                WHERE project_id = $project_id FOR UPDATE", 'Could not lock the ticket project'));
            if (!$locked_project || intval($locked_project['project_client_id']) !== $client_id
                || !empty($locked_project['project_completed_at']) || !empty($locked_project['project_archived_at'])) {
                throw new RuntimeException('The selected project is no longer active for this client');
            }
        }

        ticketCreationDbQuery("
            UPDATE settings
            SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
            WHERE company_id = 1
        ", 'Could not allocate a ticket number');
        $ticket_number = intval(mysqli_insert_id($mysqli));
        if (!$ticket_number) {
            throw new RuntimeException('The ticket number allocation returned no number');
        }

        ticketCreationDbQuery("INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'Agent', ticket_category = $category_id, ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = '$billable', ticket_status = '$ticket_status', ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_vendor_id = $vendor_id, ticket_location_id = $location_id, ticket_asset_id = $asset_id, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact_id, ticket_url_key = '$url_key', ticket_due_at = $due, ticket_client_id = $client_id, ticket_invoice_id = 0, ticket_project_id = $project_id, ticket_configuration_change = $configuration_change, ticket_documentation_impact = '$documentation_impact_sql', ticket_documentation_assessed_by = $session_user_id, ticket_documentation_assessed_at = NOW()", 'Could not create the ticket');

        $ticket_id = intval(mysqli_insert_id($mysqli));
        if (!$ticket_id) {
            throw new RuntimeException('The new ticket did not receive an ID');
        }

        // Entitlement matching includes every linked device. Create and lock
        // those links before the SLA decision so its immutable evidence cannot
        // omit assets that were submitted with the ticket.
        foreach ($additional_asset_ids as $additional_asset_id) {
            $locked_additional_asset = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id
                FROM assets WHERE asset_id = $additional_asset_id
                AND asset_client_id = $client_id AND asset_archived_at IS NULL
                LIMIT 1 FOR UPDATE", 'Could not lock an additional ticket asset'));
            if (!$locked_additional_asset) {
                throw new RuntimeException('An additional ticket asset is no longer available for this client');
            }
            ticketCreationDbQuery("INSERT INTO ticket_assets SET
                ticket_id = $ticket_id, asset_id = $additional_asset_id",
                'Could not link an additional ticket asset');
        }
        applyTicketSla($ticket_id, null, null, true);

        // A published runbook is immutable and must be instantiated server-side;
        // never trust editable task arrays to reproduce its workflow controls.
        // Draft-only legacy templates keep the existing editable snapshot behavior.
        if ($pinned_runbook_version_id) {
            addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $pinned_runbook_version_id, true);
        } elseif (isset($_POST['tasks_submitted'])) {
            foreach (parseSubmittedTasks() as $task) {
                ticketCreationDbQuery("INSERT INTO tasks SET task_name = '{$task['name']}', task_order = {$task['order']}, task_completion_estimate = {$task['estimate']}, task_ticket_id = $ticket_id", 'Could not create a submitted ticket task');
            }
        } else {
            addTasksFromTicketTemplate($ticket_id, $ticket_template_id, 0, true);
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket and its workflow');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log('Ticket creation failed before notification: ' . $exception->getMessage());
        flashAlert('The ticket was not created because its required workflow could not be created safely', 'error');
        redirect();
    }

    // Store any attached files against the ticket itself
    $emailable_attachments = filterEmailableAttachments(saveTicketAttachments($ticket_id, null));

    // Add Watchers
    if (isset($_POST['watchers'])) {
        foreach ($_POST['watchers'] as $watcher) {
            $watcher_email = escapeSql($watcher);
            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$watcher_email', watcher_ticket_id = $ticket_id");
        }
    }

    // E-mail client
    if ((!empty($config_smtp_provider) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get contact/ticket details
        $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_category, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_created_by, ticket_assigned_to, ticket_client_id FROM tickets
              LEFT JOIN contacts ON ticket_contact_id = contact_id
              WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
        $row = mysqli_fetch_assoc($sql);

        $contact_name = escapeSql($row['contact_name']);
        $contact_email = escapeSql($row['contact_email']);
        $ticket_prefix = escapeSql($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_category = escapeSql($row['ticket_category']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
        $ticket_priority = escapeSql($row['ticket_priority']);
        $ticket_status = escapeSql($row['ticket_status']);
        $ticket_status_name = escapeSql(getTicketStatusName($row['ticket_status']));
        $client_id = intval($row['ticket_client_id']);
        $ticket_created_by = intval($row['ticket_created_by']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);

        // Get Company Phone Number
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAILING

        $ticket_email_context = [
            'company_name' => $company_name,
            'contact_name' => $contact_name,
            'ticket_number' => $ticket_prefix . $ticket_number,
            'ticket_subject' => $ticket_subject,
            'ticket_status' => 'Open',
            'message_html' => $ticket_details,
            'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
            'footer_email' => $config_ticket_from_email,
            'footer_phone' => $company_phone,
        ];
        $ticket_email = renderN45Email('ticket.created', $ticket_email_context);
        $data = [];

        // Verify contact email is valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {


            // Email Ticket Contact
            // Queue Mail
            $data[] = array_merge([
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'attachments' => $emailable_attachments['send']
            ], n45EmailQueueFields($ticket_email));
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
            INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
            WHERE tw.watcher_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = escapeSql($row['watcher_email']);
            $watcher_email_context = $ticket_email_context;
            $watcher_email_context['contact_name'] = '';
            $watcher_email_context['recipient_role'] = 'collaborator';
            $watcher_message = renderN45Email('ticket.created', $watcher_email_context);

            // Queue Mail
            $data[] = array_merge([
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'attachments' => $emailable_attachments['send']
            ], n45EmailQueueFields($watcher_message));
        }
        addToMailQueue($data);

        // END EMAILING

    }

    // Custom action/notif handler
    triggerCustomAction('ticket_create', $ticket_id);

    logAudit("Ticket", "Create", "$session_name created ticket $config_ticket_prefix$ticket_number - $ticket_subject", $client_id, $ticket_id);

    flashAlert("Ticket <strong>$config_ticket_prefix$ticket_number</strong> created");

    // Tell the agent about anything too large for the mail queue to carry
    if (!empty($emailable_attachments['skipped'])) {
        $skipped_names = [];
        foreach ($emailable_attachments['skipped'] as $skipped_attachment) {
            $skipped_names[] = escapeHtml($skipped_attachment['name']);
        }
        flashAlert("Stored on the ticket but too large to email: <strong>" . implode(', ', $skipped_names) . "</strong>", 'error');
    }

    redirect("ticket.php?client_id=$client_id&ticket_id=$ticket_id");

}

if (isset($_POST['edit_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $contact_id = intval($_POST['contact_id']);
    $assigned_to = intval($_POST['assigned_to']);
    $notify = intval($_POST['contact_notify'] ?? 0);
    $category_id = intval($_POST['category_id']);
    $ticket_subject = escapeSql($_POST['subject']);
    $billable = intval($_POST['billable'] ?? 0);
    $ticket_priority = escapeSql($_POST['priority']);
    $details = mysqli_real_escape_string($mysqli, $_POST['details']);
    $vendor_ticket_number = escapeSql($_POST['vendor_ticket_number']);
    $vendor_id = intval($_POST['vendor_id']);
    $asset_id = intval($_POST['asset_id']);
    $location_id = intval($_POST['location_id']);
    $project_id = intval($_POST['project_id']);
    // Validate/clean due field
    $dueInput = $_POST['due'] ?? null;
    if ($dueInput === null || trim($dueInput) === '') {
        $due = 'NULL'; // prepare as SQL-safe string
    } else {
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $dueInput); // for <input type="datetime-local">
        if ($d !== false) {
            $due = "'" . $d->format('Y-m-d H:i:s') . "'"; // wrap in quotes for SQL
        } else {
            $due = 'NULL'; // fallback if invalid
        }
    }

    $ticket_row = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_client_id,
        ticket_project_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1",
        'Could not load the ticket for editing'));
    if (!$ticket_row) {
        flashAlert('The ticket is unavailable', 'error');
        redirect();
    }
    $client_id = intval($ticket_row['ticket_client_id']);
    $current_project_id = intval($ticket_row['ticket_project_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $edit_additional_asset_ids = [];
    foreach (array_unique(array_map('intval', (array) ($_POST['additional_assets'] ?? []))) as $additional_asset_id) {
        if (!$additional_asset_id) {
            continue;
        }
        $additional_asset = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id FROM assets
            WHERE asset_id = $additional_asset_id AND asset_client_id = $client_id
            AND asset_archived_at IS NULL LIMIT 1", 'Could not validate an additional ticket asset'));
        if (!$additional_asset) {
            flashAlert('One or more additional assets are unavailable for this client', 'error');
            redirect();
        }
        $edit_additional_asset_ids[] = $additional_asset_id;
    }

    if ($category_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT category_id FROM categories
        WHERE category_id = $category_id AND category_type = 'Ticket'
        AND category_archived_at IS NULL LIMIT 1", 'Could not validate the ticket category'))) {
        flashAlert('The selected ticket category is unavailable', 'error');
        redirect();
    }
    $edit_related_records = [
        [$contact_id, "SELECT contact_id FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id AND contact_archived_at IS NULL", 'contact'],
        [$asset_id, "SELECT asset_id FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id AND asset_archived_at IS NULL", 'asset'],
        [$location_id, "SELECT location_id FROM locations WHERE location_id = $location_id AND location_client_id = $client_id AND location_archived_at IS NULL", 'location'],
        [$vendor_id, "SELECT vendor_id FROM vendors WHERE vendor_id = $vendor_id AND vendor_client_id = $client_id AND vendor_archived_at IS NULL", 'vendor'],
    ];
    foreach ($edit_related_records as [$related_id, $related_query, $related_label]) {
        if ($related_id && !mysqli_fetch_assoc(ticketCreationDbQuery(
            $related_query . ' LIMIT 1',
            "Could not validate the ticket $related_label"
        ))) {
            flashAlert("The selected $related_label is unavailable for this client", 'error');
            redirect();
        }
    }

    if ($project_id !== $current_project_id) {
        try {
            ticketAssignProjectSafely($ticket_id, $project_id);
        } catch (Throwable $exception) {
            error_log("Ticket $ticket_id project edit failed safely: " . $exception->getMessage());
            flashAlert(escapeHtml($exception->getMessage()), 'error');
            redirect();
        }
    }

    /*
     * The edit modal can change priority and assignment, which the dedicated
     * priority and assign handlers record - so read the originals first and
     * record the same changes when they come through here instead
     */
    $original_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_priority,
        ticket_assigned_to, ticket_category FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"));
    $original_priority = escapeSql($original_row['ticket_priority']);
    $original_assigned_to = intval($original_row['ticket_assigned_to']);
    $request_key_reset = intval($original_row['ticket_category']) !== $category_id
        ? ", ticket_request_type_key = '*'" : '';

    mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id, ticket_subject = '$ticket_subject', ticket_priority = '$ticket_priority', ticket_billable = $billable, ticket_details = '$details', ticket_due_at = $due, ticket_vendor_ticket_number = '$vendor_ticket_number', ticket_contact_id = $contact_id, ticket_assigned_to = $assigned_to, ticket_vendor_id = $vendor_id, ticket_location_id = $location_id, ticket_asset_id = $asset_id $request_key_reset WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    if ($original_priority !== $ticket_priority) {
        logTicketHistory($ticket_id, "$session_name changed priority from $original_priority to $ticket_priority");
    }

    if ($original_assigned_to !== $assigned_to) {
        if ($assigned_to) {
            $new_agent_name = escapeSql(getFieldById('users', $assigned_to, 'user_name'));
            logTicketHistory($ticket_id, "$session_name assigned the ticket to $new_agent_name");
        } else {
            logTicketHistory($ticket_id, "$session_name unassigned the ticket");
        }
    }

    // Add Additional Assets
    if ($edit_additional_asset_ids) {
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
        foreach ($edit_additional_asset_ids as $additional_asset_id) {
            mysqli_query($mysqli, "INSERT INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $additional_asset_id");
        }
    } else {
        // If no additional assets are provided, delete them all
        // This handles cases where the assets input might be cleared or not set at all.
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
    }
    applyTicketSla($ticket_id);

    // Get contact/ticket details after update for logging / email purposes
    $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_category, ticket_details, ticket_status_name, ticket_created_by, ticket_assigned_to, ticket_url_key, ticket_client_id FROM tickets
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id
        AND ticket_deleted_at IS NULL
        AND ticket_closed_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $contact_name = escapeSql($row['contact_name']);
    $contact_email = escapeSql($row['contact_email']);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = escapeSql($row['ticket_category']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_status = escapeSql($row['ticket_status_name']);
    $ticket_created_by = intval($row['ticket_created_by']);
    $ticket_assigned_to = intval($row['ticket_assigned_to']);
    $url_key = escapeSql($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);

    // Notify new contact if selected
    if ($notify && (!empty($config_smtp_provider) || !empty($config_smtp_provider))) {

        // Get Company Name Phone Number and Sanitize for Email Sending
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // Email content
        $data = []; // Queue array

        $ticket_email = renderN45Email('ticket.created', [
            'company_name' => $company_name,
            'contact_name' => $contact_name,
            'ticket_number' => $ticket_prefix . $ticket_number,
            'ticket_subject' => $ticket_subject,
            'ticket_status' => $ticket_status,
            'message_html' => $ticket_details,
            'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
            'footer_email' => $config_ticket_from_email,
            'footer_phone' => $company_phone,
        ]);


        // Only add contact to email queue if email is valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $data[] = array_merge([
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
            ], n45EmailQueueFields($ticket_email));
        }

        addToMailQueue($data);
    }

    // Custom action/notif handler
    triggerCustomAction('ticket_update', $ticket_id);

    logAudit("Ticket", "Edit", "$session_name edited ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Ticket <strong>$ticket_prefix$ticket_number</strong> updated");

    redirect();

}

if (isset($_POST['edit_ticket_priority'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $priority = escapeSql($_POST['priority']);

    // Get ticket details before updating
    $sql = mysqli_query($mysqli, "SELECT
        ticket_prefix, ticket_number, ticket_priority, ticket_status_name, ticket_client_id
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $original_priority = escapeSql($row['ticket_priority']);
    $ticket_status = escapeSql($row['ticket_status_name']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$priority' WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    applyTicketSla($ticket_id);

    // Update Ticket History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status', ticket_history_description = '$session_name changed priority from $original_priority to $priority', ticket_history_ticket_id = $ticket_id");

    logAudit("Ticket", "Edit", "$session_name changed priority from $original_priority to $priority for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    triggerCustomAction('ticket_update', $ticket_id);

    flashAlert("Priority updated from <strong>$original_priority</strong> to <strong>$priority</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_sla'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $sla_id = intval($_POST['sla_id']);

    // Get ticket details before updating
    $sql = mysqli_query($mysqli, "SELECT
        ticket_prefix, ticket_number, ticket_sla_id, ticket_status_name, ticket_client_id
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $original_sla_id = intval($row['ticket_sla_id']);
    $ticket_status = escapeSql($row['ticket_status_name']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    // Look up SLA names for the history/audit trail (0 = None)
    $original_sla_name = "None";
    if ($original_sla_id) {
        $sla_sql = mysqli_query($mysqli, "SELECT sla_name FROM slas WHERE sla_id = $original_sla_id");
        if ($sla_sql && mysqli_num_rows($sla_sql)) {
            $original_sla_name = escapeSql(mysqli_fetch_assoc($sla_sql)['sla_name']);
        }
    }
    $sla_name = "None";
    if ($sla_id) {
        $sla_sql = mysqli_query($mysqli, "SELECT sla_name FROM slas
            WHERE sla_id = $sla_id AND sla_archived_at IS NULL LIMIT 1");
        if ($sla_sql && mysqli_num_rows($sla_sql)) {
            $sla_name = escapeSql(mysqli_fetch_assoc($sla_sql)['sla_name']);
        } else {
            flashAlert('The selected SLA is archived or unavailable', 'error');
            redirect();
        }
    }

    // Pin the ticket to the chosen SLA and recompute its targets
    try {
        applyTicketSla($ticket_id, $sla_id, "Manual SLA override by $session_name");
    } catch (Throwable $e) {
        error_log("Manual SLA override failed for ticket $ticket_id: " . $e->getMessage());
        flashAlert('The SLA was not changed because its locked target snapshot could not be applied', 'error');
        redirect();
    }

    // Update Ticket History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status', ticket_history_description = '$session_name changed SLA from $original_sla_name to $sla_name', ticket_history_ticket_id = $ticket_id");

    logAudit("Ticket", "Edit", "$session_name changed SLA from $original_sla_name to $sla_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    triggerCustomAction('ticket_update', $ticket_id);

    flashAlert("SLA updated from <strong>$original_sla_name</strong> to <strong>$sla_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_contact'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $contact_id = intval($_POST['contact']);
    $notify = intval($_POST['contact_notify']) ?? 0;

    // Get Original contact, and ticket details
    $sql = mysqli_query($mysqli, "SELECT
        contact_name, ticket_prefix, ticket_number, ticket_status_name, ticket_subject, ticket_details, ticket_url_key, ticket_client_id
        FROM tickets
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );
    $row = mysqli_fetch_assoc($sql);

    // Original contact
    $original_contact_name = !empty($row['contact_name']) ? escapeSql($row['contact_name']) : 'No one';

    // Ticket details
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status = escapeSql($row['ticket_status_name']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $url_key = escapeSql($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    if ($contact_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id FROM contacts
        WHERE contact_id = $contact_id AND contact_client_id = $client_id
        AND contact_archived_at IS NULL LIMIT 1", 'Could not validate the ticket contact'))) {
        flashAlert('The selected contact is unavailable for this client', 'error');
        redirect();
    }

    // Update the contact
    mysqli_query($mysqli, "UPDATE tickets SET ticket_contact_id = $contact_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    applyTicketSla($ticket_id);

    // Get New contact details
    $row = ['contact_name' => '', 'contact_email' => ''];
    if ($contact_id) {
        $sql = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts
            WHERE contact_id = $contact_id AND contact_client_id = $client_id");
        $row = mysqli_fetch_assoc($sql) ?: $row;
    }

    $contact_name = !empty($row['contact_name']) ? escapeSql($row['contact_name']) : 'No one';
    $contact_email = escapeSql($row['contact_email']);

    // Notify new contact (if selected, valid & configured)
    if ($notify && filter_var($contact_email, FILTER_VALIDATE_EMAIL) && (!empty($config_smtp_provider) || !empty($config_smtp_provider))) {

        // Get Company Phone Number
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        $config_ticket_from_email = escapeSql($config_ticket_from_email);
        $config_ticket_from_name = escapeSql($config_ticket_from_name);

        // Email content
        $data = []; // Queue array

        $ticket_email = renderN45Email('ticket.created', [
            'company_name' => $company_name,
            'contact_name' => $contact_name,
            'ticket_number' => $ticket_prefix . $ticket_number,
            'ticket_subject' => $ticket_subject,
            'ticket_status' => $ticket_status,
            'message_html' => $ticket_details,
            'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
            'footer_email' => $config_ticket_from_email,
            'footer_phone' => $company_phone,
        ]);

        $data[] = array_merge([
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
        ], n45EmailQueueFields($ticket_email));

        addToMailQueue($data);
    }

    // Custom action/notif handler
    triggerCustomAction('ticket_update', $ticket_id);

    // Update Ticket History
    mysqli_query($mysqli, "INSERT INTO ticket_history SET ticket_history_status = '$ticket_status', ticket_history_description = '$session_name changed the contact from $original_contact_name to $contact_name', ticket_history_ticket_id = $ticket_id");

    logAudit("Ticket", "Edit", "$session_name changed the contact from $original_contact_name to $contact_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Contact changed from <strong>$original_contact_name</strong> to <strong>$contact_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $project_id = intval($_POST['project']);

    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket) {
        flashAlert('Ticket not found', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    try {
        $assignment = ticketAssignProjectSafely($ticket_id, $project_id);
    } catch (Throwable $exception) {
        error_log("Ticket $ticket_id project assignment failed safely: " . $exception->getMessage());
        flashAlert(escapeHtml($exception->getMessage()), 'error');
        redirect();
    }

    $project_name = escapeHtml($assignment['project_name']);
    $ticket_prefix = escapeHtml($assignment['ticket_prefix']);
    $ticket_number = intval($assignment['ticket_number']);

    logAudit("Ticket", "Edit", "$session_name set ticket $ticket_prefix$ticket_number project to $project_name", $client_id, $ticket_id);

    flashAlert("Project changed to <strong>$project_name</strong> for Ticket <strong>$ticket_prefix$ticket_number</strong>");

    redirect();

}

if (isset($_POST['add_ticket_watcher'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $watcher_emails = preg_split("/,| |;/", $_POST['watcher_email']); // Split on comma, semicolon or space, we sanitize later
    $notify = intval($_POST['watcher_notify'] ?? 0);

    // Get contact/ticket details
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_category, ticket_subject, ticket_details, ticket_priority, ticket_status_name, ticket_url_key, ticket_created_by, ticket_assigned_to, ticket_client_id FROM tickets
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_id = $ticket_id
    AND ticket_deleted_at IS NULL
    AND ticket_closed_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = escapeSql($row['ticket_category']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_priority = escapeSql($row['ticket_priority']);
    $ticket_status = escapeSql($row['ticket_status_name']);
    $url_key = escapeSql($row['ticket_url_key']);
    $client_id = intval($row['ticket_client_id']);
    $ticket_created_by = intval($row['ticket_created_by']);
    $ticket_assigned_to = intval($row['ticket_assigned_to']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Get Company Phone Number
    $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_name = escapeSql($row['company_name']);
    $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

    // Process each watcher in list
    foreach ($watcher_emails as $watcher_email) {

        if (filter_var($watcher_email, FILTER_VALIDATE_EMAIL)) {

            $watcher_email = escapeSql($watcher_email);

            $watcher_inserted = mysqli_query($mysqli, "INSERT INTO ticket_watchers
                (watcher_email, watcher_ticket_id)
                SELECT '$watcher_email', ticket_id FROM tickets
                WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
                AND ticket_closed_at IS NULL LIMIT 1");
            if (!$watcher_inserted || mysqli_affected_rows($mysqli) !== 1) {
                continue;
            }

            // Notify watcher
            if ($notify && (!empty($config_smtp_provider))) {



                // Email content
                $data = []; // Queue array

                $ticket_email = renderN45Email('ticket.created', [
                    'company_name' => $company_name,
                    'contact_name' => '',
                    'recipient_role' => 'collaborator',
                    'ticket_number' => $ticket_prefix . $ticket_number,
                    'ticket_subject' => $ticket_subject,
                    'ticket_status' => $ticket_status,
                    'message_html' => $ticket_details,
                    'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
                    'footer_email' => $config_ticket_from_email,
                    'footer_phone' => $company_phone,
                ]);

                $data[] = array_merge([
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $watcher_email,
                    'recipient_name' => $watcher_email,
                ], n45EmailQueueFields($ticket_email));

                addToMailQueue($data);
            }

            logAudit("Ticket", "Edit", "$session_name added $watcher_email as a watcher for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);
        }

    }

    flashAlert("Added watcher(s)");

    redirect();

}

if (isset($_GET['delete_ticket_watcher'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $watcher_id = intval($_GET['delete_ticket_watcher']);

    // Get ticket / watcher details for logging
    $sql = mysqli_query($mysqli, "SELECT watcher_email, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id, ticket_id FROM ticket_watchers
        INNER JOIN tickets ON watcher_ticket_id = ticket_id AND ticket_deleted_at IS NULL
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE watcher_id = $watcher_id"
    );
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket watcher is unavailable', 'error');
        redirect();
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = escapeSql($row['ticket_status_name']);
    $watcher_email = escapeSql($row['watcher_email']);
    $client_id = intval($row['ticket_client_id']);
    $ticket_id = intval($row['ticket_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "DELETE ticket_watchers FROM ticket_watchers
        INNER JOIN tickets ON ticket_id = watcher_ticket_id AND ticket_deleted_at IS NULL
        WHERE watcher_id = $watcher_id");

    // History
    logTicketHistory($ticket_id, "$session_name removed ticket $watcher_email as a watcher");

    logAudit("Ticket", "Edit", "$session_name removed $watcher_email as a watcher for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Removed ticket watcher <strong>$watcher_email</strong>", 'error');

    redirect();

}

if (isset($_GET['delete_ticket_additional_asset'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $asset_id = intval($_GET['delete_ticket_additional_asset']);
    $ticket_id = intval($_GET['ticket_id']);

    // Get ticket / asset details for logging
    $sql = mysqli_query($mysqli, "SELECT asset_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM assets
        JOIN tickets ON ticket_id = $ticket_id AND ticket_deleted_at IS NULL
        JOIN ticket_assets ON ticket_assets.ticket_id = tickets.ticket_id
            AND ticket_assets.asset_id = assets.asset_id
        JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE assets.asset_id = $asset_id AND asset_client_id = ticket_client_id"
    );
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The additional ticket asset is unavailable', 'error');
        redirect();
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = escapeSql($row['ticket_status_name']);
    $asset_name = escapeSql($row['asset_name']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    mysqli_query($mysqli, "DELETE ticket_assets FROM ticket_assets
        INNER JOIN tickets ON tickets.ticket_id = ticket_assets.ticket_id
            AND tickets.ticket_deleted_at IS NULL
        WHERE ticket_assets.ticket_id = $ticket_id AND ticket_assets.asset_id = $asset_id");
    applyTicketSla($ticket_id);

    // History
    logTicketHistory($ticket_id, "$session_name removed additional asset $asset_name");

    logAudit("Ticket", "Edit", "$session_name removed asset $asset_name from ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Removed asset <strong>$asset_name</strong> from ticket.", 'error');

    redirect();

}

if (isset($_POST['edit_ticket_asset'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $asset_id = intval($_POST['asset']);

    $ticket_access = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket_access) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket_access['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    if ($asset_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id FROM assets
        WHERE asset_id = $asset_id AND asset_client_id = $client_id
        AND asset_archived_at IS NULL LIMIT 1", 'Could not validate the primary ticket asset'))) {
        flashAlert('The selected asset is unavailable for this client', 'error');
        redirect();
    }
    $ticket_asset_ids = [];
    foreach (array_unique(array_map('intval', (array) ($_POST['additional_assets'] ?? []))) as $additional_asset_id) {
        if (!$additional_asset_id) {
            continue;
        }
        if (!mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id FROM assets
            WHERE asset_id = $additional_asset_id AND asset_client_id = $client_id
            AND asset_archived_at IS NULL LIMIT 1", 'Could not validate an additional ticket asset'))) {
            flashAlert('One or more additional assets are unavailable for this client', 'error');
            redirect();
        }
        $ticket_asset_ids[] = $additional_asset_id;
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_asset_id = $asset_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    // Add Additional Assets
    if ($ticket_asset_ids) {
        mysqli_query($mysqli, "DELETE ticket_assets FROM ticket_assets
            INNER JOIN tickets ON tickets.ticket_id = ticket_assets.ticket_id
                AND tickets.ticket_deleted_at IS NULL
            WHERE ticket_assets.ticket_id = $ticket_id");
        foreach ($ticket_asset_ids as $additional_asset_id) {
            mysqli_query($mysqli, "INSERT INTO ticket_assets (ticket_id, asset_id)
                SELECT ticket_id, $additional_asset_id FROM tickets
                WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");
        }
    } else {
        // If no additional assets are provided, delete them all
        // This handles cases where the assets input might be cleared or not set at all.
        mysqli_query($mysqli, "DELETE ticket_assets FROM ticket_assets
            INNER JOIN tickets ON tickets.ticket_id = ticket_assets.ticket_id
                AND tickets.ticket_deleted_at IS NULL
            WHERE ticket_assets.ticket_id = $ticket_id");
    }
    applyTicketSla($ticket_id);

    // Get ticket / asset details for logging
    $sql = mysqli_query($mysqli, "SELECT asset_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM assets
        LEFT JOIN tickets ON ticket_asset_id = asset_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = escapeSql($row['ticket_status_name']);
    $asset_name = escapeSql($row['asset_name']);
    $client_id = intval($row['ticket_client_id']);

    logAudit("Ticket", "Edit", "$session_name changed asset to $asset_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Ticket <strong>$ticket_prefix$ticket_number</strong> asset updated to <strong>$asset_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_vendor'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $vendor_id = intval($_POST['vendor']);

    $ticket_access = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket_access) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket_access['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_vendor_id = $vendor_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    // Get ticket / vendor details for logging
    $sql = mysqli_query($mysqli, "SELECT vendor_name, ticket_prefix, ticket_number, ticket_status_name, ticket_client_id FROM vendors
        LEFT JOIN tickets ON ticket_vendor_id = $vendor_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_status_name = escapeSql($row['ticket_status_name']);
    $vendor_name = escapeSql($row['vendor_name']);
    $client_id = intval($row['ticket_client_id']);

    logAudit("Ticket", "Edit", "$session_name set vendor to $vendor_name for ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

    flashAlert("Set vendor to <strong>$vendor_name</strong> for ticket <strong>$ticket_prefix$ticket_number</strong>");

    redirect();

}

if (isset($_POST['assign_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $ticket_id = intval($_POST['ticket_id']);
    $assigned_to = intval($_POST['assigned_to']);

    // Allow for un-assigning tickets
    if ($assigned_to == 0) {
        $ticket_reply = "Ticket unassigned.";
        $agent_name = "No One";
    } else {
        // Get & verify assigned agent details
        $agent_details_sql = mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE users.user_id = $assigned_to");
        $agent_details = mysqli_fetch_assoc($agent_details_sql);

        $agent_name = escapeSql($agent_details['user_name']);
        $agent_email = escapeSql($agent_details['user_email']);
        $ticket_reply = "Ticket re-assigned to $agent_name.";

        if (!$agent_name) {
            flashAlert("Invalid agent!", 'error');
            redirect();
        }
    }

    // Get & verify ticket details
    $ticket_details_sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name FROM tickets LEFT JOIN clients ON ticket_client_id = client_id WHERE ticket_id = '$ticket_id' AND ticket_deleted_at IS NULL AND ticket_status != 5");
    $ticket_details = mysqli_fetch_assoc($ticket_details_sql);

    $ticket_prefix = escapeSql($ticket_details['ticket_prefix']);
    $ticket_number = intval($ticket_details['ticket_number']);
    $ticket_subject = escapeSql($ticket_details['ticket_subject']);
    $client_id = intval($ticket_details['ticket_client_id']);
    $client_name = escapeSql($ticket_details['client_name']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    if (!$ticket_subject) {
        flashAlert("Invalid ticket!", 'error');
        redirect();
    }

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    // Assignment may promote New to Open, but the browser never controls the
    // lifecycle state. Lock and derive it from the current row so assignment
    // cannot bypass runbook resolve/close/reopen gates.
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket assignment transaction');
        }
        $transaction_started = true;
        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The ticket client changed before assignment');
        }
        $locked_status = intval($locked_ticket['ticket_status']);
        $ticket_status = $locked_status === 1 && $assigned_to !== 0 ? 2 : $locked_status;
        $assignment_changed = intval($locked_ticket['ticket_assigned_to']) !== $assigned_to
            || $ticket_status !== $locked_status;
        if ($assignment_changed) {
            ticketCreationDbQuery("UPDATE tickets SET ticket_assigned_to = $assigned_to,
                ticket_status = $ticket_status WHERE ticket_id = $ticket_id
                AND ticket_deleted_at IS NULL
                AND ticket_assigned_to = " . intval($locked_ticket['ticket_assigned_to']) . "
                AND ticket_status = $locked_status AND ticket_closed_at IS NULL",
                'Could not assign the ticket');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket assignment changed before it could be committed');
            }
        }
        ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply',
            ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
            'Could not record the ticket assignment');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket assignment');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id assignment failed safely: " . $exception->getMessage());
        flashAlert('The ticket could not be assigned because its state changed', 'error');
        redirect();
    }
    syncTicketSlaClock($ticket_id);

    logTicketHistory($ticket_id, "$session_name assigned the ticket to $agent_name");

    logAudit("Ticket", "Edit", "$session_name reassigned $ticket_prefix$ticket_number to $agent_name", $client_id, $ticket_id);

    // Notification
    if ($session_user_id != $assigned_to && $assigned_to != 0) {

        // App Notification
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = 'Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject has been assigned to you by $session_name', notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = $client_id, notification_user_id = $assigned_to");

        // Email Notification
        if (!empty($config_smtp_provider)) {

            // Sanitize Config vars from get_settings.php
            $config_ticket_from_name = escapeSql($config_ticket_from_name);
            $config_ticket_from_email = escapeSql($config_ticket_from_email);
            $company_name = escapeSql($session_company_name);

            $subject = "$config_app_name - Ticket $ticket_prefix$ticket_number assigned to you - $ticket_subject";
            $body = "Hi $agent_name, <br><br>A ticket has been assigned to you!<br><br>Client: $client_name<br>Ticket Number: $ticket_prefix$ticket_number<br> Subject: $ticket_subject<br><br>https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri <br><br>Thanks, <br>$session_name<br>$company_name";

            // Email Ticket Agent
            // Queue Mail
            $data = [
                [
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $agent_email,
                    'recipient_name' => $agent_name,
                    'subject' => $subject,
                    'body' => $body,
                ]
            ];
            addToMailQueue($data);
        }
    }

    triggerCustomAction('ticket_assign', $ticket_id);

    flashAlert("Ticket <strong>$ticket_prefix$ticket_number</strong> assigned to <strong>$agent_name</strong>");

    redirect();

}

if (isset($_GET['delete_ticket'])) {

    validateCSRFToken();
    enforceAdminPermission();
    $ticket_id = intval($_GET['delete_ticket']);
    // Legacy GET deletion is intentionally non-mutating. The retention center
    // requires an explicit reason and records a recoverable lifecycle event.
    redirect("/admin/retention.php?record_type=ticket&record_id=$ticket_id");
}

if (isset($_POST['bulk_delete_tickets'])) {

    validateCSRFToken();
    enforceAdminPermission();
    flashAlert('Bulk ticket deletion is disabled. Move one ticket at a time through Administration > Retention so each record has an owner reason and restore window.', 'info');
    redirect('/admin/retention.php');

}

if (isset($_POST['bulk_assign_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $assign_to = intval($_POST['assign_to']);

    // Get a Ticket Count
    $ticket_count = count($_POST['ticket_ids']);
    $assigned_ticket_count = 0;
    $tickets_assigned_body = '';

    // Assign Tech to Selected Tickets
    if (!empty($_POST['ticket_ids'])) {
        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT * FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = escapeSql($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_status = intval($row['ticket_status']);
            $ticket_subject = escapeSql($row['ticket_subject']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess($client_id);
            }

            // Allow for un-assigning tickets
            if ($assign_to == 0) {
                $ticket_reply = "Ticket unassigned, pending re-assignment.";
                $agent_name = "No One";
            } else {
                // Get & verify assigned agent details
                $agent_details_sql = mysqli_query($mysqli, "SELECT user_name, user_email FROM users LEFT JOIN user_settings ON users.user_id = user_settings.user_id WHERE users.user_id = $assign_to");
                $agent_details = mysqli_fetch_assoc($agent_details_sql);

                $agent_name = escapeSql($agent_details['user_name']);
                $agent_email = escapeSql($agent_details['user_email']);
                $ticket_reply = "Ticket re-assigned to $agent_name.";

                if (!$agent_name) {
                    flashAlert("Invalid agent!", 'error');
                    redirect();
                }
            }

            // Derive status from the locked row. Bulk assignment may promote New
            // to Open, but it must never restore a stale posted/read status or
            // reopen/resolve/close a ticket as a side effect.
            $transaction_started = false;
            try {
                if (!mysqli_begin_transaction($mysqli)) {
                    throw new RuntimeException('Could not begin a bulk ticket assignment');
                }
                $transaction_started = true;
                $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
                if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
                    throw new RuntimeException('The ticket client changed before bulk assignment');
                }
                $locked_status = intval($locked_ticket['ticket_status']);
                $ticket_status = $locked_status === 1 && $assign_to !== 0 ? 2 : $locked_status;
                $assignment_changed = intval($locked_ticket['ticket_assigned_to']) !== $assign_to
                    || $ticket_status !== $locked_status;
                if ($assignment_changed) {
                    ticketCreationDbQuery("UPDATE tickets SET ticket_assigned_to = $assign_to,
                        ticket_status = $ticket_status WHERE ticket_id = $ticket_id
                        AND ticket_deleted_at IS NULL
                        AND ticket_assigned_to = " . intval($locked_ticket['ticket_assigned_to']) . "
                        AND ticket_status = $locked_status AND ticket_closed_at IS NULL",
                        'Could not assign a bulk ticket');
                    if (mysqli_affected_rows($mysqli) !== 1) {
                        throw new RuntimeException('The bulk ticket assignment changed before it could be committed');
                    }
                }
                ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply',
                    ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
                    ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
                    'Could not record a bulk ticket assignment');
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not commit a bulk ticket assignment');
                }
                $transaction_started = false;
            } catch (Throwable $exception) {
                if ($transaction_started) {
                    mysqli_rollback($mysqli);
                }
                error_log("Ticket $ticket_id bulk assignment failed safely: " . $exception->getMessage());
                continue;
            }
            $assigned_ticket_count++;
            syncTicketSlaClock($ticket_id);

            logTicketHistory($ticket_id, "$session_name assigned the ticket to $agent_name");

            logAudit("Ticket", "Edit", "$session_name reassigned ticket $ticket_prefix$ticket_number to $agent_name", $client_id, $ticket_id);

            triggerCustomAction('ticket_assign', $ticket_id);

            $tickets_assigned_body .= "$ticket_prefix$ticket_number - $ticket_subject<br>";
        } // End For Each Ticket ID Loop

        $ticket_count = $assigned_ticket_count;

        // Notification
        if ($ticket_count > 0 && $session_user_id != $assign_to && $assign_to != 0) {

            // App Notification
            mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '$ticket_count Tickets have been assigned to you by $session_name', notification_action = 'tickets.php?status=Open&assigned=$assign_to', notification_client_id = $client_id, notification_user_id = $assign_to");

            // Agent Email Notification
            if (!empty($config_smtp_provider)) {

                // Sanitize Config vars from get_settings.php
                $config_ticket_from_name = escapeSql($config_ticket_from_name);
                $config_ticket_from_email = escapeSql($config_ticket_from_email);
                $company_name = escapeSql($session_company_name);

                $subject = "$config_app_name - $ticket_count tickets have been assigned to you";
                $body = "Hi $agent_name, <br><br>$session_name assigned $ticket_count tickets to you!<br><br>$tickets_assigned_body<br>Thanks, <br>$session_name<br>$company_name";

                // Email Ticket Agent
                // Queue Mail
                $data = [
                    [
                        'from' => $config_ticket_from_email,
                        'from_name' => $config_ticket_from_name,
                        'recipient' => $agent_email,
                        'recipient_name' => $agent_name,
                        'subject' => $subject,
                        'body' => $body,
                    ]
                ];
                addToMailQueue($data);
            }
        }
    }

    flashAlert("You assigned <b>$ticket_count</b> Tickets to <b>$agent_name</b>");

    redirect();

}

if (isset($_POST['bulk_edit_ticket_priority'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $priority = escapeSql($_POST['bulk_priority']);

    // Assign Tech to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        $ticket_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_number, ticket_prefix, ticket_priority, ticket_subject FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = escapeSql($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeSql($row['ticket_subject']);
            $original_ticket_priority = escapeSql($row['ticket_priority']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess($client_id);
            }

            // Update ticket & insert reply
            mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$priority' WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            applyTicketSla($ticket_id);

            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$session_name updated the priority from $original_ticket_priority to $priority', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");

            logAudit("Ticket", "Edit", "$session_name updated the priority on ticket $ticket_prefix$ticket_number - $ticket_subject from $original_ticket_priority to $priority", $client_id, $ticket_id);

            triggerCustomAction('ticket_update', $ticket_id);
            $ticket_count++;
        } // End For Each Ticket ID Loop

        logAudit("Ticket", " Bulk Edit", "$session_name updated the priority on $ticket_count");

        flashAlert("You updated the priority for <strong>$ticket_count</strong> Tickets to <strong>$priority</strong>");
    }

    redirect();

}

if (isset($_POST['bulk_edit_ticket_category'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $category_id = intval($_POST['bulk_category']);
    $category_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT category_name FROM categories
        WHERE category_id = $category_id AND category_type = 'Ticket'
        AND category_archived_at IS NULL LIMIT 1"));
    if (!$category_row) {
        flashAlert('The selected ticket category is unavailable', 'error');
        redirect();
    }
    $category_name = escapeSql($category_row['category_name']);

    // Assign Tech to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Count only replies that commit successfully. Individual tickets can
        // now fail closed when their lifecycle state changes under the bulk
        // request, so the submitted selection is not a reliable success count.
        $ticket_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, category_name, ticket_client_id FROM tickets LEFT JOIN categories ON ticket_category = category_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            $row = mysqli_fetch_assoc($sql);

            $ticket_prefix = escapeSql($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeSql($row['ticket_subject']);
            $previous_ticket_category_name = escapeSql($row['category_name']);
            $client_id = intval($row['ticket_client_id']);

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess($client_id);
            }

            // Update ticket
            mysqli_query($mysqli, "UPDATE tickets SET ticket_category = '$category_id',
                ticket_request_type_key = '*' WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            applyTicketSla($ticket_id);
            $ticket_count++;

            logAudit("Ticket", "Edit", "$session_name updated the category on ticket $ticket_prefix$ticket_number - $ticket_subject from $previous_ticket_category_name to $category_name", $client_id, $ticket_id);

            triggerCustomAction('ticket_update', $ticket_id);
        } // End For Each Ticket ID Loop

        logAudit("Ticket", " Bulk Edit", "$session_name updated the category to $category_name on $ticket_count");

        flashAlert("Category set to $category_name for <strong>$ticket_count</strong> Tickets");
    }

    redirect();

}

if (isset($_POST['bulk_merge_tickets'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $merge_into_ticket_id = intval($_POST['merge_into_ticket_id']); // Parent ticket id
    $merge_comment = escapeSql($_POST['merge_comment']); // Merge comment
    $ticket_reply_type = 'Internal'; // Default all replies to internal

    // NEW PARENT ticket details
    // Get merge into ticket id (as it may differ from the number)
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $merge_into_ticket_id AND ticket_deleted_at IS NULL");
    if (mysqli_num_rows($sql) == 0) {
        flashAlert("Cannot merge into that ticket.", 'error');
        redirect();
    }
    $merge_row = mysqli_fetch_assoc($sql);
    $merge_into_ticket_number = intval($merge_row['ticket_number']); // Parent ticket Number
    $merge_into_client_id = intval($merge_row['ticket_client_id']);
    if ($merge_into_client_id) {
        $client_id = $merge_into_client_id;
        enforceClientAccess();
    }

    // Update & Close the selected tickets
    if (isset($_POST['ticket_ids'])) {

        $ticket_count = 0;
        $skipped_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            if ($ticket_id !== $merge_into_ticket_id) {

                $sql = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_details, ticket_first_response_at, ticket_number, ticket_prefix,
                    ticket_priority, ticket_subject FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
                $row = mysqli_fetch_assoc($sql);

                $ticket_prefix = escapeSql($row['ticket_prefix']);
                $ticket_number = intval($row['ticket_number']);
                $ticket_subject = escapeSql($row['ticket_subject']);
                $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
                $current_ticket_priority = escapeSql($row['ticket_priority']);
                $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
                $client_id = intval($row['ticket_client_id']);

                // Don't Enforce Client Access if Ticket doesn't have an assigned client
                if ($client_id) {
                    enforceClientAccess();
                }

                if ($client_id !== $merge_into_client_id) {
                    $skipped_count++;
                    continue;
                }

                $transaction_started = false;
                try {
                    if (!mysqli_begin_transaction($mysqli)) {
                        throw new RuntimeException('Could not begin the bulk merge transaction');
                    }
                    $transaction_started = true;
                    documentationLockClientTicket($ticket_id, $client_id);
                    $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
                    if (intval($locked_ticket['ticket_client_id']) !== $merge_into_client_id) {
                        throw new RuntimeException('The merge source client changed');
                    }
                    [$can_merge] = runbookTicketCanResolve($ticket_id);
                    if (!$can_merge) {
                        throw new RuntimeException('The merge source workflow gate is not satisfied');
                    }
                    if (empty($ticket_first_response_at)) {
                        setTicketFirstResponse($ticket_id);
                    }
                    ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number bulk merged into <a href=\"ticket.php?ticket_id=$merge_into_ticket_id\">$ticket_prefix$merge_into_ticket_number</a>. Comment: $merge_comment', ticket_reply_time_worked = '00:00:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record the bulk merge source note');
                    ticketCreationDbQuery("UPDATE tickets SET ticket_status = 5,
                        ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()),
                        ticket_closed_at = NOW(), ticket_closed_by = $session_user_id
                        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
                        AND ticket_closed_at IS NULL",
                        'Could not close the bulk merge source');
                    if (mysqli_affected_rows($mysqli) !== 1) {
                        throw new RuntimeException('The bulk merge source changed before commit');
                    }
                    documentationRecordChangePassport($ticket_id, 5, $session_user_id, true);
                    syncTicketSlaClock($ticket_id);
                    setTicketResolutionSlaMet($ticket_id);
                    ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number was bulk merged into this ticket with comment: $merge_comment.<br><br><b>$ticket_subject</b><br>$ticket_details', ticket_reply_time_worked = '00:00:00', ticket_reply_type = 'Internal', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $merge_into_ticket_id", 'Could not record the bulk merge target note');
                    if (!mysqli_commit($mysqli)) {
                        throw new RuntimeException('Could not commit the bulk merge');
                    }
                    $transaction_started = false;
                } catch (Throwable $exception) {
                    if ($transaction_started) {
                        mysqli_rollback($mysqli);
                    }
                    $skipped_count++;
                    error_log("Bulk merge skipped ticket $ticket_id: " . $exception->getMessage());
                    continue;
                }
                $ticket_count++;

                logTicketHistory($ticket_id, "$session_name merged this ticket into $ticket_prefix$merge_into_ticket_number and closed it");

    logAudit("Ticket", "Merged", "$session_name Merged ticket $ticket_prefix$ticket_number into $ticket_prefix$merge_into_ticket_number", $client_id, $ticket_id);

                // Custom action/notif handler
                triggerCustomAction('ticket_merge', $ticket_id);

            }
        } // End For Each Ticket ID Loop

        mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $merge_into_ticket_id AND ticket_deleted_at IS NULL");

        flashAlert("<strong>$ticket_count</strong> tickets merged into <strong>$ticket_prefix$merge_into_ticket_number</strong>");
        if ($skipped_count) {
            flashAlert("<strong>$skipped_count</strong> ticket(s) were not merged because the client differed or runbook work remained gated", 'info');
        }

    }

    redirect();

}

if (isset($_POST['bulk_resolve_tickets'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $details = mysqli_escape_string($mysqli, $_POST['bulk_details']);
    $ticket_reply_time_worked = escapeSql($_POST['time']);
    $private_note = intval($_POST['bulk_private_note']);
    if ($private_note == 1) {
        $ticket_reply_type = 'Internal';
    } else {
        $ticket_reply_type = 'Public';
    }

    // Resolve Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        // Intitialze the counts before the loop
        $ticket_count = 0;
        $skipped_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_first_response_at,
                ticket_number, ticket_prefix, ticket_priority, ticket_subject, ticket_url_key
                FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            $row = mysqli_fetch_assoc($sql);
            if (!$row) {
                $skipped_count++;
                continue;
            }

            $ticket_prefix = escapeSql($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeSql($row['ticket_subject']);
            $current_ticket_priority = escapeSql($row['ticket_priority']);
            $url_key = escapeSql($row['ticket_url_key']);
            $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
            $client_id = intval($row['ticket_client_id']);
            if ($client_id) {
                enforceClientAccess($client_id);
            }

            $transaction_started = false;
            try {
                if (!mysqli_begin_transaction($mysqli)) {
                    throw new RuntimeException('Could not begin a bulk ticket resolution transaction');
                }
                $transaction_started = true;
                documentationLockClientTicket($ticket_id, $client_id);
                runbookLockOpenTicket($ticket_id);
                [$can_resolve] = runbookTicketCanResolve($ticket_id);
                if (!$can_resolve) {
                    throw new RuntimeException('The ticket resolution gate is not satisfied');
                }
                if (empty($ticket_first_response_at)) {
                    setTicketFirstResponse($ticket_id);
                }
                ticketCreationDbQuery("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW()
                    WHERE ticket_id = $ticket_id AND ticket_status NOT IN (4, 5)
                    AND ticket_deleted_at IS NULL
                    AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL", 'Could not resolve a bulk ticket');
                if (mysqli_affected_rows($mysqli) !== 1) {
                    throw new RuntimeException('The bulk ticket was no longer open at commit');
                }
                documentationRecordChangePassport($ticket_id, 4, $session_user_id, true);
                syncTicketSlaClock($ticket_id);
                setTicketResolutionSlaMet($ticket_id);
                ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$details',
                    ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '$ticket_reply_time_worked',
                    ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
                    'Could not add the bulk resolution reply');
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not commit the bulk ticket resolution');
                }
                $transaction_started = false;
            } catch (Throwable $exception) {
                if ($transaction_started) {
                    mysqli_rollback($mysqli);
                }
                $skipped_count++;
                error_log("Bulk resolution skipped ticket $ticket_id: " . $exception->getMessage());
                continue;
            }

            $ticket_count++;

                logTicketHistory($ticket_id, "$session_name resolved the ticket");

                logAudit("Ticket", "Resolve", "$session_name resolved $ticket_prefix$ticket_number - $ticket_subject", $client_id, $ticket_id);

                triggerCustomAction('ticket_resolve', $ticket_id);

                // Client notification email
                if ((!empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1 && $private_note == 0) {

                    // Get Contact details
                    $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM tickets
                        LEFT JOIN contacts ON ticket_contact_id = contact_id
                        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
                    ");
                    $row = mysqli_fetch_assoc($ticket_sql);

                    $contact_name = escapeSql($row['contact_name']);
                    $contact_email = escapeSql($row['contact_email']);

                    // Sanitize Config vars from get_settings.php
                    $from_name = escapeSql($config_ticket_from_name);
                    $from_email = escapeSql($config_ticket_from_email);
                    $base_url = escapeSql($config_base_url);

                    // Get Company Info
                    $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
                    $row = mysqli_fetch_assoc($sql);
                    $company_name = escapeSql($row['company_name']);
                    $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

                    // EMAIL
                    $ticket_email_context = [
                        'company_name' => $company_name,
                        'contact_name' => $contact_name,
                        'ticket_number' => $ticket_prefix . $ticket_number,
                        'ticket_subject' => $ticket_subject,
                        'ticket_status' => 'Resolved',
                        'message_html' => $details,
                        'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
                        'footer_email' => $config_ticket_from_email,
                        'footer_phone' => $company_phone,
                    ];
                    $ticket_email = renderN45Email('ticket.resolved', $ticket_email_context);
                    $data = [];

                    // Check email valid
                    if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                        // Email Ticket Contact
                        // Queue Mail

                        $data[] = array_merge([
                            'from' => $from_email,
                            'from_name' => $from_name,
                            'recipient' => $contact_email,
                            'recipient_name' => $contact_name,
                        ], n45EmailQueueFields($ticket_email));
                    }

                    // Also Email all the watchers
                    $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
                        INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
                        WHERE tw.watcher_ticket_id = $ticket_id");
                    while ($row = mysqli_fetch_assoc($sql_watchers)) {
                        $watcher_email = escapeSql($row['watcher_email']);
                        $watcher_email_context = $ticket_email_context;
                        $watcher_email_context['contact_name'] = '';
                        $watcher_email_context['recipient_role'] = 'collaborator';
                        $watcher_message = renderN45Email('ticket.resolved', $watcher_email_context);

                        // Queue Mail
                        $data[] = array_merge([
                            'from' => $from_email,
                            'from_name' => $from_name,
                            'recipient' => $watcher_email,
                            'recipient_name' => $watcher_email,
                        ], n45EmailQueueFields($watcher_message));
                    }
                    addToMailQueue($data);
                } // End Mail IF
        } // End Loop
    } // End Array Empty Check

    flashAlert("Resolved <strong>$ticket_count</strong> Tickets");

    if ($skipped_count > 0) {
        flashAlert("Resolved <strong>$ticket_count</strong> Tickets <strong>$skipped_count</strong> ticket(s) could not be resolved because they have open tasks.", 'info');
    }

    redirect();

}

if (isset($_POST['bulk_ticket_reply'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $ticket_reply = mysqli_escape_string($mysqli, $_POST['bulk_reply_details']);
    $ticket_status = intval($_POST['bulk_status']);
    $ticket_reply_time_worked = escapeSql($_POST['time']);
    $private_note = intval($_POST['bulk_private_reply']);
    if ($private_note == 1) {
        $ticket_reply_type = 'Internal';
    } else {
        $ticket_reply_type = 'Public';
    }

    $resolution_blocked_count = 0;

    // Loop Through Tickets and Add Reply along with Email notifications
    if (isset($_POST['ticket_ids'])) {

        // Count only replies that commit successfully. Individual tickets can
        // fail closed when their lifecycle state changes under the bulk request,
        // so the submitted selection is not a reliable success count.
        $ticket_count = 0;

        foreach ($_POST['ticket_ids'] as $ticket_id) {
            $ticket_id = intval($ticket_id);

            $sql = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_project_id, ticket_status, ticket_first_response_at, ticket_number, ticket_prefix, ticket_priority,
                ticket_subject, ticket_url_key FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
            $row = mysqli_fetch_assoc($sql);
            if (!$row) {
                continue;
            }

            $ticket_prefix = escapeSql($row['ticket_prefix']);
            $ticket_number = intval($row['ticket_number']);
            $ticket_subject = escapeSql($row['ticket_subject']);
            $current_ticket_priority = escapeSql($row['ticket_priority']);
            $url_key = escapeSql($row['ticket_url_key']);
            $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
            $client_id = intval($row['ticket_client_id']);
            $ticket_project_id = intval($row['ticket_project_id']);
            $original_ticket_status = intval($row['ticket_status']);
            $effective_ticket_status = $ticket_status;

            // Don't Enforce Client Access if Ticket doesn't have an assigned client
            if ($client_id) {
                enforceClientAccess($client_id);
            }

            if ($client_id) {
                $client_uri = "&client_id=$client_id";
            } else {
                $client_uri = '';
            }

            $transaction_started = false;
            try {
                if (!mysqli_begin_transaction($mysqli)) {
                    throw new RuntimeException('Could not begin the bulk reply transaction');
                }
                $transaction_started = true;

                if ($original_ticket_status === 5) {
                    throw new RuntimeException('Closed tickets cannot receive replies');
                }
                if ($effective_ticket_status === 5 && $original_ticket_status !== 4) {
                    throw new RuntimeException('Only a resolved ticket can transition to closed');
                }

                $terminal_transition = in_array($effective_ticket_status, [4, 5], true)
                    && $effective_ticket_status !== $original_ticket_status;
                $reopen_transition = $original_ticket_status === 4
                    && !in_array($effective_ticket_status, [4, 5], true);
                $locked_ticket = null;
                if ($terminal_transition) {
                    documentationLockClientTicket($ticket_id, $client_id);
                    if ($effective_ticket_status === 4) {
                        $locked_ticket = runbookLockOpenTicket($ticket_id);
                    } else {
                        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
                    }
                    [$can_resolve] = runbookTicketCanResolve($ticket_id);
                    if (!$can_resolve) {
                        $effective_ticket_status = $original_ticket_status;
                        $terminal_transition = false;
                        $resolution_blocked_count++;
                    }
                } elseif ($reopen_transition) {
                    if ($ticket_project_id) {
                        $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_completed_at,
                            project_archived_at FROM projects WHERE project_id = $ticket_project_id FOR UPDATE",
                            'Could not lock the ticket project for bulk reopen'));
                        if (!$project || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
                            throw new RuntimeException('A completed or archived project ticket cannot be reopened');
                        }
                    }
                    $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
                    $locked_project_id = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT ticket_project_id
                        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL", 'Could not verify the bulk reply project'))[0] ?? 0);
                    if ($locked_project_id !== $ticket_project_id) {
                        throw new RuntimeException('The ticket project changed during the bulk reply');
                    }
                } else {
                    $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
                }
                if (!$locked_ticket || intval($locked_ticket['ticket_status']) !== $original_ticket_status) {
                    throw new RuntimeException('The ticket status changed before the bulk reply was locked');
                }

                // Mark FR time if required - internal notes don't count as a response.
                if (empty($ticket_first_response_at) && $ticket_reply_type == 'Public') {
                    setTicketFirstResponse($ticket_id);
                }

                ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply',
                    ticket_reply_time_worked = '$ticket_reply_time_worked', ticket_reply_type = '$ticket_reply_type',
                    ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
                    'Could not add a bulk ticket reply');
                $ticket_reply_id = intval(mysqli_insert_id($mysqli));

                if ($effective_ticket_status !== $original_ticket_status) {
                    if ($effective_ticket_status === 4) {
                        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW()
                            WHERE ticket_id = $ticket_id AND ticket_status = $original_ticket_status
                            AND ticket_deleted_at IS NULL
                            AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL",
                            'Could not resolve the ticket from a bulk reply');
                    } elseif ($effective_ticket_status === 5) {
                        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 5,
                            ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()),
                            ticket_closed_at = NOW(), ticket_closed_by = $session_user_id
                            WHERE ticket_id = $ticket_id AND ticket_status = $original_ticket_status
                            AND ticket_deleted_at IS NULL
                            AND ticket_closed_at IS NULL", 'Could not close the ticket from a bulk reply');
                    } else {
                        $clear_resolution = $original_ticket_status === 4 ? ', ticket_resolved_at = NULL' : '';
                        ticketCreationDbQuery("UPDATE tickets SET ticket_status = $effective_ticket_status
                            $clear_resolution WHERE ticket_id = $ticket_id
                            AND ticket_deleted_at IS NULL
                            AND ticket_status = $original_ticket_status AND ticket_closed_at IS NULL",
                            'Could not update ticket status from a bulk reply');
                    }
                    if (mysqli_affected_rows($mysqli) !== 1) {
                        throw new RuntimeException('The ticket status changed before the bulk reply committed');
                    }
                    if (in_array($effective_ticket_status, [4, 5], true)) {
                        documentationRecordChangePassport(
                            $ticket_id,
                            $effective_ticket_status,
                            $session_user_id,
                            true
                        );
                    }
                    syncTicketSlaClock($ticket_id);
                    if (in_array($effective_ticket_status, [4, 5], true)) {
                        setTicketResolutionSlaMet($ticket_id);
                    } elseif ($original_ticket_status === 4) {
                        resetTicketResolutionSla($ticket_id);
                    }
                }

                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not commit the bulk ticket reply');
                }
                $transaction_started = false;
            } catch (Throwable $exception) {
                if ($transaction_started) {
                    mysqli_rollback($mysqli);
                }
                error_log("Bulk reply skipped ticket $ticket_id: " . $exception->getMessage());
                continue;
            }
            $ticket_count++;
            $ticket_status_name = escapeSql(getTicketStatusName($effective_ticket_status));

            // Only record a status change when the status actually changed - Resolved
            // is left out because the resolve block below logs it
            if ($effective_ticket_status !== $original_ticket_status && $effective_ticket_status != 4) {
                $new_status_name = escapeSql(getTicketStatusName($effective_ticket_status));
                logTicketHistory($ticket_id, "$session_name set the status to $new_status_name");
            }

            logAudit("Ticket", "Reply", "$session_name replied to ticket $ticket_prefix$ticket_number - $ticket_subject and was a $ticket_reply_type reply", $client_id, $ticket_id);

            // Custom action/notif handler
            if ($ticket_reply_type == 'Internal') {
                triggerCustomAction('ticket_reply_agent_internal', $ticket_id);
            } else {
                triggerCustomAction('reply_reply_agent_public', $ticket_id);
            }

            // Resolve the ticket, if it is actually moving into Resolved - a bulk reply
            // on an already-resolved ticket must not restamp resolved_at
            if ($effective_ticket_status == 4 && $original_ticket_status != 4) {
                // Logging
                logTicketHistory($ticket_id, "$session_name resolved the ticket");

                logAudit("Ticket", "Resolved", "$session_name resolved Ticket $ticket_prefix$ticket_number", $client_id, $ticket_id);

                triggerCustomAction('ticket_resolve', $ticket_id);
            }

            // Get Contact Details
            $sql = mysqli_query(
                $mysqli,
                "SELECT contact_name, contact_email, ticket_created_by, ticket_assigned_to
                FROM tickets
                LEFT JOIN contacts ON ticket_contact_id = contact_id
                WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
            );

            $row = mysqli_fetch_assoc($sql);

            $contact_name = escapeSql($row['contact_name']);
            $contact_email = escapeSql($row['contact_email']);
            $ticket_created_by = intval($row['ticket_created_by']);
            $ticket_assigned_to = intval($row['ticket_assigned_to']);

            // Sanitize Config vars from get_settings.php
            $from_name = escapeSql($config_ticket_from_name);
            $from_email = escapeSql($config_ticket_from_email);
            $base_url = escapeSql($config_base_url);

            $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
            $row = mysqli_fetch_assoc($sql);
            $company_name = escapeSql($row['company_name']);
            $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

            // Send e-mail to client if public update & email is set up
            if ($private_note == 0 && (!empty($config_smtp_provider))) {

                $ticket_email_context = [
                    'company_name' => $company_name,
                    'contact_name' => $contact_name,
                    'ticket_number' => $ticket_prefix . $ticket_number,
                    'ticket_subject' => $ticket_subject,
                    'ticket_status' => $ticket_status_name,
                    'message_html' => $ticket_reply,
                    'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
                    'footer_email' => $from_email,
                    'footer_phone' => $company_phone,
                ];
                $ticket_email = renderN45Email('ticket.updated', $ticket_email_context);
                $data = [];

                if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                    // Email Ticket Contact
                    // Queue Mail
                    $data[] = array_merge([
                        'from' => $from_email,
                        'from_name' => $from_name,
                        'recipient' => $contact_email,
                        'recipient_name' => $contact_name,
                    ], n45EmailQueueFields($ticket_email));

                }

                // Also Email all the watchers
                $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
                    INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
                    WHERE tw.watcher_ticket_id = $ticket_id");
                while ($row = mysqli_fetch_assoc($sql_watchers)) {
                    $watcher_email = escapeSql($row['watcher_email']);
                    $watcher_email_context = $ticket_email_context;
                    $watcher_email_context['contact_name'] = '';
                    $watcher_email_context['recipient_role'] = 'collaborator';
                    $watcher_message = renderN45Email('ticket.updated', $watcher_email_context);

                    // Queue Mail
                    $data[] = array_merge([
                        'from' => $from_email,
                        'from_name' => $from_name,
                        'recipient' => $watcher_email,
                        'recipient_name' => $watcher_email,
                    ], n45EmailQueueFields($watcher_message));
                }
                addToMailQueue($data);
            } //End Mail IF

            // Notification for assigned ticket user
            if ($session_user_id != $ticket_assigned_to && $ticket_assigned_to != 0) {

                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that is assigned to you', notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = $client_id, notification_user_id = $ticket_assigned_to");
            }

            // Notification for user that opened the ticket
            if ($session_user_id != $ticket_created_by && $ticket_created_by != 0) {

                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that you opened', notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = $client_id, notification_user_id = $ticket_created_by");
            }
        } // End Ticket Lopp

    }

    flashAlert("Updated <strong>$ticket_count</strong> tickets");
    if ($resolution_blocked_count) {
        flashAlert("<strong>$resolution_blocked_count</strong> ticket(s) received the reply but remained open because runbook tasks are gated", 'info');
    }

    redirect();

}


// Currently not UI Frontend for this
if (isset($_POST['bulk_add_ticket_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // POST variables
    $project_id = intval($_POST['project_id']);

    // Get Project Name
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects
        WHERE project_id = $project_id AND project_completed_at IS NULL
        AND project_archived_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The project is unavailable, archived, or complete', 'error');
        redirect();
    }
    $project_name = escapeSql($row['project_name']);
    $project_client_id = intval($row['project_client_id']);
    if ($project_client_id) {
        enforceClientAccess($project_client_id);
    }

    // Assign Project to Selected Tickets
    if (isset($_POST['ticket_ids'])) {

        $ticket_count = 0;
        $skipped_count = 0;
        $ticket_ids = array_values(array_unique(array_filter(array_map('intval', (array) $_POST['ticket_ids']))));
        sort($ticket_ids, SORT_NUMERIC);
        foreach ($ticket_ids as $ticket_id) {
            try {
                $assignment = ticketAssignProjectSafely($ticket_id, $project_id);
                if (!$assignment['changed']) {
                    continue;
                }
                $ticket_prefix = escapeSql($assignment['ticket_prefix']);
                $ticket_number = intval($assignment['ticket_number']);
                $ticket_subject = escapeSql($assignment['ticket_subject']);
                $client_id = intval($assignment['client_id']);
                $ticket_count++;
                logAudit("Ticket", "Reply", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $ticket_id);
            } catch (Throwable $exception) {
                $skipped_count++;
                error_log("Bulk project $project_id skipped ticket $ticket_id: " . $exception->getMessage());
            }
        }

        flashAlert("<strong>$ticket_count</strong> Tickets added to Project <strong>$project_name</strong>");
        if ($skipped_count) {
            flashAlert("<strong>$skipped_count</strong> ticket(s) were not linked because their project or immutable runbook context prevents reassignment", 'info');
        }

    }

    redirect();

}

if (isset($_POST['bulk_add_asset_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $assigned_to = intval($_POST['bulk_assigned_to'] ?? 0);
    if ($assigned_to == 0) {
        $ticket_status = 1;
    } else {
        $ticket_status = 2;
    }
    $subject_raw = trim((string) ($_POST['bulk_subject'] ?? ''));
    $priority = escapeSql($_POST['bulk_priority'] ?? 'Low');
    $category_id = intval($_POST['bulk_category'] ?? 0);
    $details = mysqli_real_escape_string($mysqli, $_POST['bulk_details'] ?? '');
    $project_id = intval($_POST['bulk_project'] ?? 0);
    $use_primary_contact = intval($_POST['use_primary_contact'] ?? 0);
    $ticket_template_id = intval($_POST['bulk_ticket_template_id'] ?? 0);
    $billable = intval($_POST['bulk_billable'] ?? 0);
    $runbook_version_id = 0;
    if (!$ticket_template_id && $subject_raw === '') {
        flashAlert('A subject is required when no ticket template is selected', 'error');
        redirect();
    }
    $subject = escapeSql($subject_raw);

    if ($assigned_to && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT user_id FROM users
        WHERE user_id = $assigned_to AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL LIMIT 1", 'Could not validate the bulk asset ticket assignee'))) {
        flashAlert('The selected assignee is unavailable', 'error');
        redirect();
    }
    if ($category_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT category_id FROM categories
        WHERE category_id = $category_id AND category_type = 'Ticket'
        AND category_archived_at IS NULL LIMIT 1", 'Could not validate the bulk asset ticket category'))) {
        flashAlert('The selected ticket category is unavailable', 'error');
        redirect();
    }

    $project_client_id = 0;
    if ($project_id) {
        $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_client_id FROM projects
            WHERE project_id = $project_id AND project_archived_at IS NULL
            AND project_completed_at IS NULL LIMIT 1", 'Could not validate the bulk asset ticket project'));
        if (!$project) {
            flashAlert('The selected project is unavailable or closed', 'error');
            redirect();
        }
        $project_client_id = intval($project['project_client_id']);
    }

    // Check to see if adding a ticket by template
    if ($ticket_template_id) {
        $sql = ticketCreationDbQuery("SELECT
            ticket_template_details, ticket_template_subject,
            ticket_template_published_version_id, runbook_version_id,
            runbook_version_details, runbook_version_subject,
            (SELECT COUNT(*) FROM runbook_versions history
                WHERE history.runbook_version_ticket_template_id = ticket_template_id) AS runbook_version_count
            FROM ticket_templates
            LEFT JOIN runbook_versions
                ON runbook_version_id = ticket_template_published_version_id
                AND runbook_version_ticket_template_id = ticket_template_id
            WHERE ticket_template_id = $ticket_template_id
            AND ticket_template_archived_at IS NULL LIMIT 1", 'Could not validate the bulk asset ticket template');
        $row = mysqli_fetch_assoc($sql);
        if (!$row) {
            flashAlert('The selected ticket template is unavailable or archived', 'error');
            redirect();
        }
        $runbook_version_id = intval($row['ticket_template_published_version_id']);
        if ($runbook_version_id) {
            if (intval($row['runbook_version_id']) !== $runbook_version_id) {
                flashAlert('The published runbook for this template is unavailable', 'error');
                redirect();
            }
            $subject = escapeSql($row['runbook_version_subject']);
            $details = mysqli_real_escape_string($mysqli, $row['runbook_version_details']);
        } elseif (intval($row['runbook_version_count']) > 0) {
            flashAlert('This template has runbook history but no valid published release. Republish it before bulk use.', 'error');
            redirect();
        } else {
            if (empty($subject)) {
                $subject = escapeSql($row['ticket_template_subject']);
            }
            $details = mysqli_real_escape_string($mysqli, $row['ticket_template_details']);
        }
    }

    if (trim((string) $subject) === '') {
        flashAlert('The selected ticket template does not provide a ticket subject', 'error');
        redirect();
    }

    $requested_asset_ids = array_values(array_unique(array_map('intval', (array) ($_POST['asset_ids'] ?? []))));
    $requested_count = count($requested_asset_ids);
    $created_count = 0;
    $skipped_count = 0;
    $failed_count = 0;
    $eligible_assets = [];

    // Resolve every asset/client and permission before creating the first
    // ticket, preventing a later denial from leaving a partial batch.
    foreach ($requested_asset_ids as $requested_asset_id) {
        if (!$requested_asset_id) {
            $skipped_count++;
            continue;
        }
        $asset_row = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_id, asset_name,
            asset_client_id FROM assets
            INNER JOIN clients ON client_id = asset_client_id
            WHERE asset_id = $requested_asset_id AND asset_archived_at IS NULL
            AND client_lead = 0 AND client_archived_at IS NULL LIMIT 1", 'Could not validate a bulk ticket asset'));
        if (!$asset_row) {
            $skipped_count++;
            continue;
        }
        $client_id = intval($asset_row['asset_client_id']);
        if (!$client_id) {
            $skipped_count++;
            continue;
        }
        enforceClientAccess($client_id);
        if ($project_id && $project_client_id !== $client_id) {
            $skipped_count++;
            continue;
        }
        $contact_id = 0;
        if ($use_primary_contact) {
            $primary = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id FROM contacts
                WHERE contact_client_id = $client_id AND contact_primary = 1
                AND contact_archived_at IS NULL ORDER BY contact_id LIMIT 1", 'Could not validate a primary contact'));
            $contact_id = intval($primary['contact_id'] ?? 0);
            if (!$contact_id) {
                $skipped_count++;
                continue;
            }
        }
        $asset_row['contact_id'] = $contact_id;
        $eligible_assets[] = $asset_row;
    }

    $config_ticket_prefix = escapeSql($config_ticket_prefix);

    foreach ($eligible_assets as $asset_row) {
        $asset_id = intval($asset_row['asset_id']);
        $client_id = intval($asset_row['asset_client_id']);
        $contact_id = intval($asset_row['contact_id']);
        $url_key = randomString(32);
        $transaction_started = false;

        try {
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin a bulk asset ticket transaction');
            }
            $transaction_started = true;

            if ($project_id) {
                $locked_project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_client_id,
                    project_completed_at, project_archived_at FROM projects
                    WHERE project_id = $project_id FOR UPDATE", 'Could not lock the bulk asset ticket project'));
                if (!$locked_project || intval($locked_project['project_client_id']) !== $client_id
                    || !empty($locked_project['project_completed_at']) || !empty($locked_project['project_archived_at'])) {
                    throw new RuntimeException('The selected project is no longer active for the asset client');
                }
            }

            // Revalidate the preflighted relationships under locks so an asset
            // cannot be archived/transferred, or its client/contact retired,
            // between batch selection and this ticket insert.
            $locked_client = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT client_id FROM clients
                WHERE client_id = $client_id AND client_lead = 0
                AND client_archived_at IS NULL " . clientScopeSql('clients.client_id') . "
                LIMIT 1 FOR UPDATE", 'Could not lock the bulk asset ticket client'));
            if (!$locked_client) {
                throw new RuntimeException('The asset client is no longer active or accessible');
            }
            $locked_asset = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT asset_name, asset_client_id
                FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id
                AND asset_archived_at IS NULL LIMIT 1 FOR UPDATE",
                'Could not lock the bulk ticket asset'));
            if (!$locked_asset || intval($locked_asset['asset_client_id']) !== $client_id) {
                throw new RuntimeException('The asset is no longer active for the selected client');
            }
            if ($contact_id) {
                $locked_contact = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id FROM contacts
                    WHERE contact_id = $contact_id AND contact_client_id = $client_id
                    AND contact_primary = 1 AND contact_archived_at IS NULL
                    LIMIT 1 FOR UPDATE", 'Could not lock the bulk asset ticket contact'));
                if (!$locked_contact) {
                    throw new RuntimeException('The selected primary contact is no longer active for the asset client');
                }
            }
            $asset_name = escapeSql($locked_asset['asset_name']);
            $subject_asset_prepended = "$asset_name - $subject";

            ticketCreationDbQuery("
                UPDATE settings
                SET
                    config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                    config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1
            ", 'Could not allocate a bulk asset ticket number');
            $ticket_number = intval(mysqli_insert_id($mysqli));
            if (!$ticket_number) {
                throw new RuntimeException('The bulk asset ticket number allocation returned no number');
            }

            ticketCreationDbQuery("INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'Agent Bulk', ticket_category = $category_id, ticket_subject = '$subject_asset_prepended', ticket_details = '$details', ticket_priority = '$priority', ticket_billable = $billable, ticket_status = $ticket_status, ticket_asset_id = $asset_id, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned_to, ticket_contact_id = $contact_id, ticket_url_key = '$url_key', ticket_client_id = $client_id, ticket_project_id = $project_id", 'Could not create a bulk asset ticket');
            $ticket_id = intval(mysqli_insert_id($mysqli));
            if (!$ticket_id) {
                throw new RuntimeException('The bulk asset ticket did not receive an ID');
            }
            applyTicketSla($ticket_id, null, null, true);

            if (!$runbook_version_id && !empty($_POST['tasks']) && is_array($_POST['tasks'])) {
                foreach ($_POST['tasks'] as $task) {
                    $task_name = escapeSql($task);
                    if (!empty($task_name)) {
                        ticketCreationDbQuery("INSERT INTO tasks SET task_name = '$task_name', task_ticket_id = $ticket_id", 'Could not create a submitted bulk asset task');
                    }
                }
            }
            addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id, true);

            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit a bulk asset ticket and its workflow');
            }
            $transaction_started = false;
            $created_count++;
            triggerCustomAction('ticket_create', $ticket_id);
        } catch (Throwable $exception) {
            if ($transaction_started) {
                mysqli_rollback($mysqli);
            }
            $failed_count++;
            error_log("Bulk asset ticket creation failed for asset $asset_id: " . $exception->getMessage());
        }
    }

    logAudit("Ticket", "Bulk Create", "$session_name created $created_count of $requested_count requested asset ticket(s); $skipped_count skipped and $failed_count failed");
    flashAlert("Created <strong>$created_count</strong> of <strong>$requested_count</strong> requested asset ticket(s)");
    if ($skipped_count || $failed_count) {
        flashAlert("Skipped <strong>$skipped_count</strong> invalid, archived, inaccessible-project, or no-contact selection(s); <strong>$failed_count</strong> ticket(s) failed safe workflow creation", 'info');
    }

    redirect();

}

if (isset($_POST['add_ticket_reply'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $ticket_reply = $_POST['ticket_reply']; // Reply is SQL escaped below
    $ticket_status = intval($_POST['status']);
    
    // Read the ticket as it stands before the reply changes anything
    $original_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id,
        ticket_project_id, ticket_status FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"));
    if (!$original_row) {
        flashAlert('The ticket is unavailable', 'error');
        redirect();
    }
    $client_id = intval($original_row['ticket_client_id'] ?? 0);
    $ticket_project_id = intval($original_row['ticket_project_id'] ?? 0);
    $original_ticket_status = intval($original_row['ticket_status'] ?? 0);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    if ($original_ticket_status === 5) {
        flashAlert('Closed tickets cannot receive replies', 'error');
        redirect();
    }
    if ($ticket_status === 5 && $original_ticket_status !== 4) {
        flashAlert('Only a resolved ticket can transition to closed', 'error');
        redirect();
    }

    // Time tracking, inputs & combine into string
    $hours = intval($_POST['hours']);
    $minutes = intval($_POST['minutes']);
    $seconds = intval($_POST['seconds']);
    $ticket_reply_time_worked = escapeSql(sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds));

    // Defaults
    $send_email = 0;
    $ticket_reply_id = 0;
    if ($_POST['public_reply_type'] == 1 ){
        $ticket_reply_type = 'Public';
    } elseif ($_POST['public_reply_type'] == 2 ) {
        $ticket_reply_type = 'Public';
        $send_email = 1;
    } else {
        $ticket_reply_type = 'Internal';
    }
    // Add Signature to the end of the ticket reply if not Internal and if there is reply
    if ($ticket_reply !== '' && $ticket_reply_type !== 'Internal' && $send_email == 1) {
        $ticket_reply .= getFieldById('user_settings',$session_user_id,'user_config_signature');
    }

    $ticket_reply = mysqli_escape_string($mysqli, $ticket_reply); // SQL Escape Ticket Reply

    $resolution_blocked_message = '';
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket reply transaction');
        }
        $transaction_started = true;

        $terminal_transition = in_array($ticket_status, [4, 5], true)
            && $ticket_status !== $original_ticket_status;
        $reopen_transition = $original_ticket_status === 4
            && !in_array($ticket_status, [4, 5], true);
        $locked_ticket = null;
        if ($terminal_transition) {
            documentationLockClientTicket($ticket_id, $client_id);
            if ($ticket_status === 4) {
                $locked_ticket = runbookLockOpenTicket($ticket_id);
            } else {
                $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
            }
            [$can_resolve, $resolve_error] = runbookTicketCanResolve($ticket_id);
            if (!$can_resolve) {
                $resolution_blocked_message = $resolve_error;
                $ticket_status = $original_ticket_status;
                $terminal_transition = false;
            }
        } elseif ($reopen_transition) {
            if ($ticket_project_id) {
                $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_completed_at,
                    project_archived_at FROM projects WHERE project_id = $ticket_project_id FOR UPDATE",
                    'Could not lock the ticket project for reopen by reply'));
                if (!$project || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
                    throw new RuntimeException('A completed or archived project ticket cannot be reopened');
                }
            }
            $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
            $locked_project_id = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT ticket_project_id
                FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL", 'Could not verify the reply project'))[0] ?? 0);
            if ($locked_project_id !== $ticket_project_id) {
                throw new RuntimeException('The ticket project changed during the reply');
            }
        } else {
            $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        }
        if (!$locked_ticket || intval($locked_ticket['ticket_status']) !== $original_ticket_status) {
            throw new RuntimeException('The ticket status changed before the reply was locked');
        }

        if ($ticket_status !== $original_ticket_status) {
            if ($ticket_status === 4) {
                ticketCreationDbQuery("UPDATE tickets SET ticket_status = 4,
                    ticket_resolved_at = NOW(), ticket_updated_at = NOW()
                    WHERE ticket_id = $ticket_id AND ticket_status = $original_ticket_status
                    AND ticket_deleted_at IS NULL
                    AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL",
                    'Could not resolve the ticket from the reply');
            } elseif ($ticket_status === 5) {
                ticketCreationDbQuery("UPDATE tickets SET ticket_status = 5,
                    ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()),
                    ticket_closed_at = NOW(), ticket_closed_by = $session_user_id,
                    ticket_updated_at = NOW() WHERE ticket_id = $ticket_id
                    AND ticket_deleted_at IS NULL
                    AND ticket_status = $original_ticket_status AND ticket_closed_at IS NULL",
                    'Could not close the ticket from the reply');
            } else {
                $clear_resolution = $original_ticket_status === 4 ? ', ticket_resolved_at = NULL' : '';
                ticketCreationDbQuery("UPDATE tickets SET ticket_status = $ticket_status,
                    ticket_updated_at = NOW() $clear_resolution
                    WHERE ticket_id = $ticket_id AND ticket_status = $original_ticket_status
                    AND ticket_deleted_at IS NULL
                    AND ticket_closed_at IS NULL", 'Could not update the ticket status from the reply');
            }
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket status changed before the reply committed');
            }
            if (in_array($ticket_status, [4, 5], true)) {
                documentationRecordChangePassport($ticket_id, $ticket_status, $session_user_id, true);
            }
            syncTicketSlaClock($ticket_id);
            if (in_array($ticket_status, [4, 5], true)) {
                setTicketResolutionSlaMet($ticket_id);
            } elseif ($original_ticket_status === 4) {
                resetTicketResolutionSla($ticket_id);
            }
        } else {
            ticketCreationDbQuery("UPDATE tickets SET ticket_updated_at = NOW()
                WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL", 'Could not touch the ticket for the reply');
        }

        if (!empty($ticket_reply)) {
            ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$ticket_reply',
                ticket_reply_time_worked = '$ticket_reply_time_worked', ticket_reply_type = '$ticket_reply_type',
                ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
                'Could not create the ticket reply');
            $ticket_reply_id = intval(mysqli_insert_id($mysqli));
            if ($ticket_reply_type === 'Public') {
                setTicketFirstResponse($ticket_id);
            }
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket reply');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id reply failed safely: " . $exception->getMessage());
        flashAlert('The reply was not saved because the ticket changed while it was being processed', 'error');
        redirect();
    }

    if ($resolution_blocked_message !== '') {
        flashAlert('Reply saved, but the ticket remained open: ' . escapeHtml($resolution_blocked_message), 'info');
    }

    // Resolve the ticket, if it is actually moving into Resolved - replying on an
    // already-resolved ticket must not restamp resolved_at or re-log the resolve
    if ($ticket_status == 4 && $original_ticket_status != 4) {
        logTicketHistory($ticket_id, "$session_name resolved the ticket");

        logAudit("Ticket", "Resolved", "$session_name resolved Ticket ticket ID $ticket_id", $client_id, $ticket_id);
    }

    // Process reply actions, if we have a reply to work with (e.g. we're not just editing the status)
    if (!empty($ticket_reply)) {
        // Store any attached files against this reply before the email is built, so
        // a public reply can carry them
        $reply_attachments = saveTicketAttachments($ticket_id, $ticket_reply_id);
        $emailable_attachments = filterEmailableAttachments($reply_attachments);

        // Get Ticket Details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_status_name, ticket_url_key, ticket_first_response_at, ticket_created_by, ticket_assigned_to, ticket_client_id
        FROM tickets
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
        ");

        $row = mysqli_fetch_assoc($ticket_sql);

        $contact_name = escapeSql($row['contact_name']);
        $contact_email = escapeSql($row['contact_email']);
        $ticket_prefix = escapeSql($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $ticket_status = intval($row['ticket_status']);
        $ticket_status_name = escapeSql($row['ticket_status_name']);
        $url_key = escapeSql($row['ticket_url_key']);
        $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
        $ticket_created_by = intval($row['ticket_created_by']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);
        $client_id = intval($row['ticket_client_id']);

        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = escapeSql($config_ticket_from_name);
        $config_ticket_from_email = escapeSql($config_ticket_from_email);
        $config_base_url = escapeSql($config_base_url);

        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // Send e-mail to client if public update & email is set up
        if ($ticket_reply_type == 'Public' && $send_email == 1 && (!empty($config_smtp_provider))) {

            // Slightly different email subject/text depending on if this update set auto-close

            if ($ticket_status == 4) {
                // Resolved
                $email_template_key = 'ticket.resolved';
            } else {
                // Anything else
                $email_template_key = 'ticket.updated';
            }

            $ticket_email_context = [
                'company_name' => $company_name,
                'contact_name' => $contact_name,
                'ticket_number' => $ticket_prefix . $ticket_number,
                'ticket_subject' => $ticket_subject,
                'ticket_status' => $ticket_status_name,
                'message_html' => $ticket_reply,
                'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
                'footer_email' => $config_ticket_from_email,
                'footer_phone' => $company_phone,
            ];
            $ticket_email = renderN45Email($email_template_key, $ticket_email_context);
            $data = [];

            if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                // Email Ticket Contact
                // Queue Mail
                $data[] = array_merge([
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $contact_email,
                    'recipient_name' => $contact_name,
                    'attachments' => $emailable_attachments['send']
                ], n45EmailQueueFields($ticket_email));
            }

            // Also Email all the watchers
            $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
                INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
                WHERE tw.watcher_ticket_id = $ticket_id");
            while ($row = mysqli_fetch_assoc($sql_watchers)) {
                $watcher_email = escapeSql($row['watcher_email']);
                $watcher_email_context = $ticket_email_context;
                $watcher_email_context['contact_name'] = '';
                $watcher_email_context['recipient_role'] = 'collaborator';
                $watcher_message = renderN45Email($email_template_key, $watcher_email_context);

                // Queue Mail
                $data[] = array_merge([
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $watcher_email,
                    'recipient_name' => $watcher_email,
                    'attachments' => $emailable_attachments['send']
                ], n45EmailQueueFields($watcher_message));
            }
            addToMailQueue($data);

        }
        //End Mail IF

        // Notification for assigned ticket user
        if ($session_user_id != $ticket_assigned_to && $ticket_assigned_to != 0) {
            mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that is assigned to you', notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = $client_id, notification_user_id = $ticket_assigned_to");
        }

        // Notification for user that opened the ticket
        if ($session_user_id != $ticket_created_by && $ticket_created_by != 0) {
            mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '$session_name updated Ticket $ticket_prefix$ticket_number - Subject: $ticket_subject that you opened', notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = $client_id, notification_user_id = $ticket_created_by");
        }

        // Handle first response
        if (empty($ticket_first_response_at) && $ticket_reply_type == 'Public') {
            setTicketFirstResponse($ticket_id);
        }

        // Custom action/notif handler
        if ($ticket_reply_type == 'Internal') {
            triggerCustomAction('ticket_reply_agent_internal', $ticket_id);
        } else {
            triggerCustomAction('reply_reply_agent_public', $ticket_id);
        }

        flashAlert("Ticket <strong>$ticket_prefix$ticket_number</strong> has been updated with your reply and was <strong>$ticket_reply_type</strong>");

    } else {
        flashAlert("Ticket updated");
    }

    // A file uploaded without any accompanying text has no reply to hang off, so it
    // attaches to the ticket itself. With reply text the files were already stored
    // above, before the email was composed.
    if (empty($ticket_reply_id)) {
        $emailable_attachments = filterEmailableAttachments(saveTicketAttachments($ticket_id, null));
    }

    // Tell the agent about anything too large for the mail queue to carry, rather
    // than letting them assume the recipient got it
    if (!empty($emailable_attachments['skipped'])) {
        $skipped_names = [];
        foreach ($emailable_attachments['skipped'] as $skipped_attachment) {
            $skipped_names[] = escapeHtml($skipped_attachment['name']);
        }
        flashAlert("Stored on the ticket but too large to email: <strong>" . implode(', ', $skipped_names) . "</strong>", 'error');
    }

    /*
     * The reply form preselects the ticket's current status, so most replies post
     * it straight back - only record a status change when it actually changed.
     * Resolved is left out because the resolve block above already logged it
     */
    if ($ticket_status !== $original_ticket_status && $ticket_status != 4) {
        $new_status_name = escapeSql(getTicketStatusName($ticket_status));
        logTicketHistory($ticket_id, "$session_name set the status to $new_status_name");
    }

    logAudit("Ticket", "Reply", "$session_name replied to ticket $ticket_prefix$ticket_number - $ticket_subject and was a $ticket_reply_type reply", $client_id, $ticket_id);

    redirect();

}

if (isset($_GET['delete_ticket_attachment'])) {

    validateCSRFToken();
    enforceAdminPermission();
    $attachment_id = intval($_GET['delete_ticket_attachment']);
    redirect("/admin/retention.php?record_type=attachment&record_id=$attachment_id");

}

if (isset($_POST['edit_ticket_reply'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_POST['ticket_reply_id']);
    $ticket_reply = mysqli_real_escape_string($mysqli, $_POST['ticket_reply']);
    $ticket_reply_type = escapeSql($_POST['ticket_reply_type']);
    $ticket_reply_time_worked = escapeSql($_POST['time']);

    $sql = mysqli_query($mysqli, "SELECT ticket_client_id FROM ticket_replies
        INNER JOIN tickets ON ticket_id = ticket_reply_ticket_id AND ticket_deleted_at IS NULL
        WHERE ticket_reply_id = $ticket_reply_id
        LIMIT 1"
    );

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket reply is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies tr
        INNER JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
            AND t.ticket_deleted_at IS NULL
        SET tr.ticket_reply = '$ticket_reply', tr.ticket_reply_type = '$ticket_reply_type',
            tr.ticket_reply_time_worked = '$ticket_reply_time_worked'
        WHERE tr.ticket_reply_id = $ticket_reply_id AND tr.ticket_reply_type != 'Client'") or die(mysqli_error($mysqli));

    logAudit("Ticket", "Reply", "$session_name edited ticket_reply", $client_id, $ticket_reply_id);

    flashAlert("Ticket reply updated");

    redirect();

}

if (isset($_POST['redact_ticket_reply'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_POST['ticket_reply_id']);
    $ticket_reply = mysqli_real_escape_string($mysqli, $_POST['ticket_reply']);

    $sql = mysqli_query($mysqli, "SELECT ticket_client_id FROM ticket_replies
        INNER JOIN tickets ON ticket_id = ticket_reply_ticket_id AND ticket_deleted_at IS NULL
        WHERE ticket_reply_id = $ticket_reply_id
        LIMIT 1"
    );

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket reply is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies tr
        INNER JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
            AND t.ticket_deleted_at IS NULL
        SET tr.ticket_reply = '$ticket_reply'
        WHERE tr.ticket_reply_id = $ticket_reply_id");

    logAudit("Ticket", "Reply", "$session_name redacted ticket_reply", $client_id, $ticket_reply_id);

    flashAlert("Ticket reply redacted");

    redirect();

}

if (isset($_GET['archive_ticket_reply'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_reply_id = intval($_GET['archive_ticket_reply']);

    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id
        FROM ticket_replies
        INNER JOIN tickets ON ticket_id = ticket_reply_ticket_id AND ticket_deleted_at IS NULL
        WHERE ticket_reply_id = $ticket_reply_id LIMIT 1"));
    if (!$reply) {
        flashAlert('The ticket reply is unavailable.', 'error');
        redirect();
    }
    $ticket_id = intval($reply['ticket_id']);
    $client_id = intval($reply['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE ticket_replies tr
        INNER JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
            AND t.ticket_deleted_at IS NULL
        SET tr.ticket_reply_archived_at = NOW()
        WHERE tr.ticket_reply_id = $ticket_reply_id");

    logAudit("Ticket Reply", "Archive", "$session_name archived ticket_reply", $client_id, $ticket_reply_id);

    flashAlert("Ticket reply archived", 'error');

    redirect();

}

if (isset($_POST['merge_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']); // Child ticket ID to be closed
    $merge_into_ticket_id = intval($_POST['merge_into_ticket_id']); // Parent ticket id
    $merge_comment = escapeSql($_POST['merge_comment']); // Merge comment
    $move_replies = intval($_POST['merge_move_replies']); // Whether to move replies to the new parent ticket
    $ticket_reply_type = 'Internal'; // Default all replies to internal

    // Get current ticket details
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject,
        ticket_details, ticket_first_response_at, ticket_client_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    if (mysqli_num_rows($sql) == 0) {
        flashAlert("No ticket with that ID found.", 'error');
        redirect();
    }
    // CURRENT ticket details
    $row = mysqli_fetch_assoc($sql);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $ticket_details = mysqli_escape_string($mysqli, $row['ticket_details']);
    $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
    $source_client_id = intval($row['ticket_client_id']);

    // NEW PARENT ticket details
    // Get merge into ticket id (as it may differ from the number)
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $merge_into_ticket_id AND ticket_deleted_at IS NULL");
    if (mysqli_num_rows($sql) == 0) {
        flashAlert("Cannot merge into that ticket.", 'error');
        redirect();
    }
    $merge_row = mysqli_fetch_assoc($sql);
    $client_id = intval($merge_row['ticket_client_id']);
    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }
    $merge_into_ticket_number = intval($merge_row['ticket_number']);
    if ($client_id) {
        $has_client = "&client_id=$client_id";
    } else {
        $has_client = "";
    }
    // Sanity check
    if ($ticket_id == $merge_into_ticket_id) {
        flashAlert("Cannot merge into the same ticket.", 'error');
        redirect();
    }
    if ($source_client_id !== $client_id) {
        flashAlert('Tickets from different clients cannot be merged', 'error');
        redirect();
    }
    $transaction_started = false;
    $merge_error_message = 'The ticket could not be merged because it changed while the request was being processed';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket merge transaction');
        }
        $transaction_started = true;
        documentationLockClientTicket($ticket_id, $client_id);
        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
            throw new RuntimeException('The merge source client changed');
        }
        [$can_merge, $merge_error] = runbookTicketCanResolve($ticket_id);
        if (!$can_merge) {
            $merge_error_message = 'Ticket cannot be merged while its runbook is gated: ' . $merge_error;
            throw new RuntimeException('The merge source workflow gate is not satisfied');
        }

        if ($move_replies) {
            ticketCreationDbQuery("UPDATE ticket_replies SET ticket_reply_ticket_id = $merge_into_ticket_id
                WHERE ticket_reply_ticket_id = $ticket_id", 'Could not move the merged ticket replies');
        }
        if (empty($ticket_first_response_at)) {
            setTicketFirstResponse($ticket_id);
        }
        ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number merged into <a href=\"ticket.php?ticket_id=$merge_into_ticket_id\">$ticket_prefix$merge_into_ticket_number</a>. Comment: $merge_comment', ticket_reply_time_worked = '00:00:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record the merge source note');
        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 5,
            ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()),
            ticket_closed_at = NOW(), ticket_closed_by = $session_user_id
            WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
            AND ticket_closed_at IS NULL",
            'Could not close the merge source');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The merge source changed before commit');
        }
        documentationRecordChangePassport($ticket_id, 5, $session_user_id, true);
        syncTicketSlaClock($ticket_id);
        setTicketResolutionSlaMet($ticket_id);
        ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Ticket $ticket_prefix$ticket_number was merged into this ticket with comment: $merge_comment.<br><br><b>$ticket_subject</b><br>$ticket_details', ticket_reply_time_worked = '00:00:00', ticket_reply_type = '$ticket_reply_type', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $merge_into_ticket_id", 'Could not record the merge target note');
        ticketCreationDbQuery("UPDATE tickets SET ticket_updated_at = NOW()
            WHERE ticket_id = $merge_into_ticket_id AND ticket_deleted_at IS NULL", 'Could not touch the merge target');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket merge');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id merge failed safely: " . $exception->getMessage());
        flashAlert(escapeHtml($merge_error_message), 'error');
        redirect();
    }

    logTicketHistory($ticket_id, "$session_name merged this ticket into $ticket_prefix$merge_into_ticket_number and closed it");

    logAudit("Ticket", "Merged", "$session_name Merged ticket $ticket_prefix$ticket_number into $ticket_prefix$merge_into_ticket_number");

    triggerCustomAction('ticket_merge', $ticket_id);

    flashAlert("Ticket merged into $ticket_prefix$merge_into_ticket_number");

    redirect("ticket.php?ticket_id=$merge_into_ticket_id$has_client");

}

if (isset($_POST['change_client_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $client_id = intval($_POST['new_client_id']);
    $contact_id = intval($_POST['new_contact_id']);

    $ticket = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_client_id, ticket_prefix,
        ticket_number FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1", 'Could not load the ticket client'));
    if (!$ticket) {
        flashAlert('The ticket is unavailable', 'error');
        redirect();
    }
    $source_client_id = intval($ticket['ticket_client_id']);
    if ($source_client_id) {
        enforceClientAccess($source_client_id);
    }

    $target_client = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT client_id FROM clients
        WHERE client_id = $client_id AND client_lead = 0
        AND client_archived_at IS NULL LIMIT 1", 'Could not validate the target ticket client'));
    if (!$target_client) {
        flashAlert('The target client is unavailable or archived', 'error');
        redirect();
    }
    enforceClientAccess($client_id);

    if ($contact_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id FROM contacts
        WHERE contact_id = $contact_id AND contact_client_id = $client_id
        AND contact_archived_at IS NULL LIMIT 1", 'Could not validate the target ticket contact'))) {
        flashAlert('The selected contact is unavailable for the target client', 'error');
        redirect();
    }

    $transaction_started = false;
    $client_change_error = 'The ticket client was not changed';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket client change transaction');
        }
        $transaction_started = true;

        $locked_ticket = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_id,
            ticket_client_id, ticket_prefix, ticket_number FROM tickets
            WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the ticket client context'));
        if (!$locked_ticket || intval($locked_ticket['ticket_client_id']) !== $source_client_id) {
            throw new RuntimeException('The ticket client changed before the transfer lock was acquired');
        }

        $locked_target_client = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT client_id
            FROM clients WHERE client_id = $client_id AND client_lead = 0
            AND client_archived_at IS NULL LIMIT 1",
            'Could not revalidate the target ticket client'));
        if (!$locked_target_client) {
            $client_change_error = 'The target client is no longer available';
            throw new RuntimeException('The target client became unavailable');
        }
        if ($contact_id && !mysqli_fetch_assoc(ticketCreationDbQuery("SELECT contact_id
            FROM contacts WHERE contact_id = $contact_id AND contact_client_id = $client_id
            AND contact_archived_at IS NULL LIMIT 1",
            'Could not revalidate the target ticket contact'))) {
            $client_change_error = 'The selected contact is no longer available for the target client';
            throw new RuntimeException('The target contact became unavailable');
        }

        if ($source_client_id !== $client_id) {
            [$documentation_transfer_allowed, $documentation_transfer_error] = documentationTicketCanTransfer($ticket_id, $client_id);
            if (!$documentation_transfer_allowed) {
                $client_change_error = $documentation_transfer_error;
                throw new RuntimeException('The locked ticket has client-bound documentation history');
            }
            $workflow_artifacts = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT
                EXISTS (SELECT 1 FROM runbook_executions
                    WHERE runbook_execution_ticket_id = $ticket_id) AS has_execution,
                EXISTS (SELECT 1 FROM task_approvals
                    INNER JOIN tasks ON task_id = approval_task_id
                    WHERE task_ticket_id = $ticket_id) AS has_approval,
                EXISTS (SELECT 1 FROM task_evidence
                    INNER JOIN tasks ON task_id = task_evidence_task_id
                    WHERE task_ticket_id = $ticket_id) AS has_evidence",
                'Could not validate the locked ticket workflow context'));
            if (intval($workflow_artifacts['has_execution'] ?? 0)
                || intval($workflow_artifacts['has_approval'] ?? 0)
                || intval($workflow_artifacts['has_evidence'] ?? 0)) {
                $client_change_error = 'Tickets with a runbook execution, task approval, or task evidence cannot be transferred because their audit context belongs to the original client';
                throw new RuntimeException('The locked ticket has client-bound workflow artifacts');
            }
        }

        ticketCreationDbQuery("UPDATE ticket_replies SET ticket_reply_type = 'Internal'
            WHERE ticket_reply_ticket_id = $ticket_id", 'Could not protect existing ticket replies');

        $clear_old_relationships = $source_client_id === $client_id
            ? ''
            : ', ticket_vendor_id = 0, ticket_location_id = 0, ticket_asset_id = 0, ticket_project_id = 0';
        ticketCreationDbQuery("UPDATE tickets SET ticket_client_id = $client_id,
            ticket_contact_id = $contact_id $clear_old_relationships
            WHERE ticket_id = $ticket_id AND ticket_client_id = $source_client_id
            AND ticket_deleted_at IS NULL LIMIT 1", 'Could not update the ticket client');
        if ($source_client_id !== $client_id && mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket client changed before it could be updated');
        }
        if ($source_client_id !== $client_id) {
            ticketCreationDbQuery("DELETE FROM ticket_assets WHERE ticket_id = $ticket_id",
                'Could not clear old-client ticket assets');
        }
        applyTicketSla($ticket_id, null, null, true);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket client change');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id client change failed: " . $exception->getMessage());
        flashAlert($client_change_error, 'error');
        redirect();
    }

    logAudit("Ticket", "Change", "$session_name changed ticket client from $source_client_id to $client_id", $client_id, $ticket_id);

    triggerCustomAction('ticket_update', $ticket_id);

    flashAlert("Ticket client updated");

    redirect();

}

if (isset($_GET['resolve_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['resolve_ticket']);

    $sql = mysqli_query($mysqli, "SELECT ticket_client_id, ticket_first_response_at, ticket_number, ticket_prefix FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_first_response_at = escapeSql($row['ticket_first_response_at']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    $transaction_started = false;
    $resolution_error_message = 'The ticket could not be resolved because it changed while the request was being processed';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket resolution transaction');
        }
        $transaction_started = true;

        // Every task/approval mutation takes this same parent lock first, so
        // the gate cannot pass concurrently with a new/reopened task.
        documentationLockClientTicket($ticket_id, $client_id);
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        [$can_resolve, $resolve_error] = runbookTicketCanResolve($ticket_id);
        if (!$can_resolve) {
            $resolution_error_message = $resolve_error;
            throw new RuntimeException('The ticket resolution gate is not satisfied');
        }

        if (empty($ticket_first_response_at)) {
            setTicketFirstResponse($ticket_id);
        }

        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW()
            WHERE ticket_id = $ticket_id AND ticket_status NOT IN (4, 5)
            AND ticket_deleted_at IS NULL
            AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL", 'Could not resolve the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket was no longer open when resolution was committed');
        }
        documentationRecordChangePassport($ticket_id, 4, $session_user_id, true);
        syncTicketSlaClock($ticket_id);
        setTicketResolutionSlaMet($ticket_id);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket resolution');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id resolution failed safely: " . $exception->getMessage());
        flashAlert(escapeHtml($resolution_error_message), 'error');
        redirect();
    }

    logTicketHistory($ticket_id, "$session_name resolved the ticket");

    logAudit("Ticket", "Resolved", "$session_name resolved ticket $ticket_prefix$ticket_number (ID: $ticket_id)", $client_id, $ticket_id);

    triggerCustomAction('ticket_resolve', $ticket_id);

    // Client notification email
    if ((!empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, ticket_assigned_to, ticket_url_key FROM tickets
            LEFT JOIN contacts ON ticket_contact_id = contact_id
            LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
            WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
        ");
        $row = mysqli_fetch_assoc($ticket_sql);
        if (!$row) {
            flashAlert('Ticket resolved; notification skipped because the ticket is no longer available.', 'error');
            redirect();
        }

        $contact_name = escapeSql($row['contact_name']);
        $contact_email = escapeSql($row['contact_email']);
        $ticket_prefix = escapeSql($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $ticket_assigned_to = intval($row['ticket_assigned_to']);
        $ticket_status = escapeSql($row['ticket_status_name']);
        $url_key = escapeSql($row['ticket_url_key']);

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = escapeSql($config_ticket_from_name);
        $config_ticket_from_email = escapeSql($config_ticket_from_email);
        $config_base_url = escapeSql($config_base_url);

        // Get Company Info
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAIL
        $ticket_email_context = [
            'company_name' => $company_name,
            'contact_name' => $contact_name,
            'ticket_number' => $ticket_prefix . $ticket_number,
            'ticket_subject' => $ticket_subject,
            'ticket_status' => $ticket_status,
            'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key",
            'footer_email' => $config_ticket_from_email,
            'footer_phone' => $company_phone,
        ];
        $ticket_email = renderN45Email('ticket.resolved', $ticket_email_context);
        $data = [];

        // Check email valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            // Email Ticket Contact
            // Queue Mail

            $data[] = array_merge([
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
            ], n45EmailQueueFields($ticket_email));
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
            INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
            WHERE tw.watcher_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = escapeSql($row['watcher_email']);
            $watcher_email_context = $ticket_email_context;
            $watcher_email_context['contact_name'] = '';
            $watcher_email_context['recipient_role'] = 'collaborator';
            $watcher_message = renderN45Email('ticket.resolved', $watcher_email_context);

            // Queue Mail
            $data[] = array_merge([
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
            ], n45EmailQueueFields($watcher_message));
        }
        addToMailQueue($data);
    }
    //End Mail IF

    flashAlert("Ticket resolved");

    redirect();

}

if (isset($_GET['close_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['close_ticket']);
    $ticket_access = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket_access) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket_access['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    $transaction_started = false;
    $close_error_message = 'The ticket could not be closed because it changed while the request was being processed';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket close transaction');
        }
        $transaction_started = true;

        // Close is a Resolved -> Closed transition, so lock the resolved row
        // directly (the open-ticket helper deliberately rejects status 4).
        documentationLockClientTicket($ticket_id, $client_id);
        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        if (!$locked_ticket || intval($locked_ticket['ticket_status']) !== 4 || !empty($locked_ticket['ticket_closed_at'])) {
            throw new RuntimeException('The ticket is not in the resolved state required for close');
        }

        [$can_close, $close_error] = runbookTicketCanResolve($ticket_id);
        if (!$can_close) {
            $close_error_message = $close_error;
            throw new RuntimeException('The ticket close gate is not satisfied');
        }

        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 5, ticket_closed_at = NOW(),
            ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id
            AND ticket_deleted_at IS NULL
            AND ticket_status = 4 AND ticket_closed_at IS NULL", 'Could not close the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket was no longer resolved when close was committed');
        }
        documentationRecordChangePassport($ticket_id, 5, $session_user_id, true);
        syncTicketSlaClock($ticket_id);
        setTicketResolutionSlaMet($ticket_id);

        ticketCreationDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.',
            ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id",
            'Could not record the ticket close note');

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket close');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id close failed safely: " . $exception->getMessage());
        flashAlert(escapeHtml($close_error_message), 'error');
        redirect();
    }

    logTicketHistory($ticket_id, "$session_name closed the ticket");

    logAudit("Ticket", "Closed", "$session_name closed ticket ID $ticket_id", $client_id, $ticket_id);

    triggerCustomAction('ticket_close', $ticket_id);

    // Client notification email
    if ((!empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        // Get details
        $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_url_key FROM tickets
            LEFT JOIN contacts ON ticket_contact_id = contact_id
            WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
        ");
        $row = mysqli_fetch_assoc($ticket_sql);
        if (!$row) {
            flashAlert('Ticket closed; notification skipped because the ticket is no longer available.', 'error');
            redirect();
        }

        $contact_name = escapeSql($row['contact_name']);
        $contact_email = escapeSql($row['contact_email']);
        $ticket_prefix = escapeSql($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $url_key = escapeSql($row['ticket_url_key']);

        // Sanitize Config vars from get_settings.php
        $config_ticket_from_name = escapeSql($config_ticket_from_name);
        $config_ticket_from_email = escapeSql($config_ticket_from_email);
        $config_base_url = escapeSql($config_base_url);

        // Get Company Info
        $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql);
        $company_name = escapeSql($row['company_name']);
        $company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

        // EMAIL
        $subject = "Ticket closed - [$ticket_prefix$ticket_number] - $ticket_subject | (do not reply)";
        $body = "Hello $contact_name,<br><br>Your ticket regarding \"$ticket_subject\" has been closed. <br><br> We hope the request/issue was resolved to your satisfaction, please provide your feedback <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>here</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        // Check email valid
        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $data = [];

            // Email Ticket Contact
            // Queue Mail

            $data[] = [
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $subject,
                'body' => $body
            ];
        }

        // Also Email all the watchers
        $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
            INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
            WHERE tw.watcher_ticket_id = $ticket_id");
        $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = escapeSql($row['watcher_email']);

            // Queue Mail
            $data[] = [
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => $subject,
                'body' => $body
            ];
        }
        addToMailQueue($data);
    }
    //End Mail IF

    flashAlert("Ticket Closed, this cannot not be reopened but you may start another one");

    redirect();

}

if (isset($_GET['reopen_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['reopen_ticket']);

    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_project_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket) {
        flashAlert('Ticket not found', 'error');
        redirect();
    }
    $client_id = intval($ticket['ticket_client_id']);
    $project_id = intval($ticket['ticket_project_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket reopen transaction');
        }
        $transaction_started = true;

        // Project close takes locks in project -> ticket order. Reopen follows
        // the same order and cannot leave a completed project with an open child.
        if ($project_id) {
            $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_completed_at,
                project_archived_at FROM projects WHERE project_id = $project_id FOR UPDATE",
                'Could not lock the ticket project for reopen'));
            if (!$project || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
                throw new RuntimeException('Tickets in a completed or archived project cannot be reopened');
            }
        }
        $locked_ticket = runbookLockTicketForTransition($ticket_id, true);
        $locked_project_id = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT ticket_project_id
            FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL", 'Could not verify the ticket project'))[0] ?? 0);
        if ($locked_project_id !== $project_id || intval($locked_ticket['ticket_status']) !== 4) {
            throw new RuntimeException('The ticket or its project changed before reopen');
        }

        ticketCreationDbQuery("UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL
            WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
            AND ticket_status = 4 AND ticket_closed_at IS NULL",
            'Could not reopen the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket was no longer resolved at reopen commit');
        }
        syncTicketSlaClock($ticket_id);
        resetTicketResolutionSla($ticket_id);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket reopen');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Ticket $ticket_id reopen failed safely: " . $exception->getMessage());
        flashAlert('The ticket could not be reopened. Completed or archived project tickets must stay terminal.', 'error');
        redirect();
    }

    logTicketHistory($ticket_id, "$session_name reopened the ticket");

    logAudit("Ticket", "Reopened", "$session_name reopened ticket ID $ticket_id", $client_id, $ticket_id);

    triggerCustomAction('ticket_update', $ticket_id);

    flashAlert("Ticket re-opened");

    redirect();

}

if (isset($_POST['add_invoice_from_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    $invoice_id = intval($_POST['invoice_id']);
    $ticket_id = intval($_POST['ticket_id']);
    $date = escapeSql($_POST['date']);
    $category = intval($_POST['category']);
    $scope = escapeSql($_POST['scope']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT asset_id, client_id, client_net_terms, contact_email, contact_id, contact_name,
            location_name, ticket_category, ticket_closed_at, ticket_created_at, ticket_number,
            ticket_prefix, ticket_subject, ticket_updated_at FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN assets ON ticket_asset_id = asset_id
        LEFT JOIN locations ON ticket_location_id = location_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($row['client_id']);
    $client_net_terms = intval($row['client_net_terms']);
    if ($client_net_terms == 0) {
        $client_net_terms = $config_default_net_terms;
    }

    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_category = escapeSql($row['ticket_category']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $ticket_created_at = escapeSql($row['ticket_created_at']);
    $ticket_updated_at = escapeSql($row['ticket_updated_at']);
    $ticket_closed_at = escapeSql($row['ticket_closed_at']);

    $contact_id = intval($row['contact_id']);
    $contact_name = escapeSql($row['contact_name']);
    $contact_email = escapeSql($row['contact_email']);

    $asset_id = intval($row['asset_id']);

    $location_name = escapeSql($row['location_name']);

    enforceClientAccess();

    if ($invoice_id == 0) {

        $invoice_prefix = escapeSql($config_invoice_prefix);

        // Atomically increment and get the new invoice number
        mysqli_query($mysqli, "
            UPDATE settings
            SET
                config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
                config_invoice_next_number = config_invoice_next_number + 1
            WHERE company_id = 1
        ");

        $invoice_number = mysqli_insert_id($mysqli);

        //Generate a unique URL key for clients to access
        $url_key = randomString(32);

        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = '$config_invoice_prefix', invoice_number = $invoice_number, invoice_scope = '$scope', invoice_date = '$date', invoice_due = DATE_ADD('$date', INTERVAL $client_net_terms day), invoice_currency_code = '$session_company_currency', invoice_category_id = $category, invoice_status = 'Draft', invoice_url_key = '$url_key', invoice_client_id = $client_id");
        $invoice_id = mysqli_insert_id($mysqli);
    } else {
        $sql_invoice = mysqli_query($mysqli, "SELECT invoice_prefix, invoice_number FROM invoices WHERE invoice_id = $invoice_id");
        $row = mysqli_fetch_assoc($sql_invoice);
        $invoice_prefix = escapeSql($row['invoice_prefix']);
        $invoice_number = intval($row['invoice_number']);
    }

    //Add Item
    $item_name = escapeSql($_POST['item_name']);
    $item_description = escapeSql($_POST['item_description']);
    $qty = floatval($_POST['qty']);
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);

    $subtotal = $price * $qty;

    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    } else {
        $tax_amount = 0;
    }

    $total = $subtotal + $tax_amount;

    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '$item_name', item_description = '$item_description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_order = 1, item_tax_id = $tax_id, item_invoice_id = $invoice_id");

    //Update Invoice Balances

    $sql = mysqli_query($mysqli, "SELECT invoice_amount FROM invoices WHERE invoice_id = $invoice_id");
    $row = mysqli_fetch_assoc($sql);

    $new_invoice_amount = floatval($row['invoice_amount']) + $total;

    mysqli_query($mysqli, "UPDATE invoices SET invoice_amount = $new_invoice_amount WHERE invoice_id = $invoice_id");

    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = 'Invoice created from Ticket $ticket_prefix$ticket_number', history_invoice_id = $invoice_id");

    // Add internal note to ticket, and link to invoice in database
    mysqli_query($mysqli, "INSERT INTO ticket_replies
        (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_by, ticket_reply_ticket_id)
        SELECT 'Created invoice <a href=\"invoice.php?invoice_id=$invoice_id\">$config_invoice_prefix$invoice_number</a> for this ticket.',
            'Internal', '00:00:00', $session_user_id, ticket_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");

    mysqli_query($mysqli, "UPDATE tickets SET ticket_invoice_id = $invoice_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    logAudit("Invoice", "Create", "$session_name created invoice $invoice_prefix$invoice_number from Ticket $ticket_prefix$ticket_number", $client_id, $invoice_id);

    flashAlert("Invoice $invoice_prefix$invoice_number created from ticket");

    redirect("invoice.php?invoice_id=$invoice_id");

}

if (isset($_POST['add_quote_from_ticket'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    require_once 'quote_model.php';

    $ticket_id = intval($_POST['ticket_id']);
    $item_name = escapeSql($_POST['item_name']);
    $item_description = escapeSql($_POST['item_description']);
    $qty = floatval($_POST['qty']);
    $price = floatval($_POST['price']);
    $tax_id = intval($_POST['tax_id']);

    // Totals
    $subtotal = $price * $qty;
    $tax_amount = 0;
    if ($tax_id > 0) {
        $sql = mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = $tax_id");
        $row = mysqli_fetch_assoc($sql);
        $tax_percent = floatval($row['tax_percent']);
        $tax_amount = $subtotal * $tax_percent / 100;
    }
    $total = floatval($subtotal + $tax_amount);

    // Ticket info
    $sql = mysqli_query(
        $mysqli,
        "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"
    );
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $client_id = intval($row['ticket_client_id']);

    enforceClientAccess();

    // Atomically increment and get the new quote number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_quote_next_number = LAST_INSERT_ID(config_quote_next_number),
            config_quote_next_number = config_quote_next_number + 1
        WHERE company_id = 1
    ");

    $quote_number = mysqli_insert_id($mysqli);

    //Generate a unique URL key for clients to access
    $quote_url_key = randomString(32);

    mysqli_query($mysqli,"INSERT INTO quotes SET quote_prefix = '$config_quote_prefix', quote_number = $quote_number, quote_scope = '$scope', quote_date = '$date', quote_expire = '$expire', quote_amount = $total, quote_currency_code = '$session_company_currency', quote_category_id = $category, quote_status = 'Draft', quote_url_key = '$quote_url_key', quote_client_id = $client_id");

    $quote_id = mysqli_insert_id($mysqli);

    // Add line item
    mysqli_query($mysqli, "INSERT INTO quote_items SET item_name = '$item_name', item_description = '$item_description', item_quantity = $qty, item_price = $price, item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total, item_order = 1, item_tax_id = $tax_id, item_quote_id = $quote_id");

    // Add internal note to ticket, and link to invoice in database
    mysqli_query($mysqli, "INSERT INTO ticket_replies
        (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_by, ticket_reply_ticket_id)
        SELECT 'Created quote <a href=\"quote.php?quote_id=$quote_id\">$config_quote_prefix$quote_number</a> for this ticket.',
            'Internal', '00:00:00', $session_user_id, ticket_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");
    mysqli_query($mysqli, "UPDATE tickets SET ticket_quote_id = $quote_id WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");

    // Logging + redirects
    mysqli_query($mysqli,"INSERT INTO history SET history_status = 'Draft', history_description = 'Quote created from Ticket $ticket_prefix$ticket_number!', history_quote_id = $quote_id");
    logAudit("Quote", "Create", "$session_name created quote $config_quote_prefix$quote_number from ticket $ticket_prefix$ticket_number", $client_id, $quote_id);

    triggerCustomAction('quote_create', $quote_id);

    flashAlert("Quote <strong>$config_quote_prefix$quote_number</strong> created");
    redirect("quote.php?quote_id=$quote_id");

}

if (isExportRequest('export_tickets')) {

    validateCSRFToken();

    // Exports are reads - see CONTRIBUTING.md
    enforceUserPermission('module_support');

    $format = resolveExportFormat($_POST['export_tickets']);

    // Filters inherited from the tickets page - mirrors agent/tickets.php
    $filter_summary = [];

    if (!empty($_POST['client_id'])) {
        $client_id = intval($_POST['client_id']);
        $client_query = "AND ticket_client_id = $client_id";
        $client_name = getFieldById('clients', $client_id, 'client_name');
        $file_name_prepend = "$client_name-";
        $filter_summary['Client'] = $client_name;

        enforceClientAccess();
    } else {
        $client_query = '';
        $client_id = 0; // for Logging
        $file_name_prepend = "$session_company_name-";

        // Client Filter - the global ticket list can be narrowed to one client
        if (!empty($_POST['client'])) {
            $filter_client_id = intval($_POST['client']);
            $client_query = "AND ticket_client_id = $filter_client_id";
            $filter_summary['Client'] = getFieldById('clients', $filter_client_id, 'client_name');
        }
    }

    // Status Filter - a set of status IDs, or the Open / Closed shorthand
    if (isset($_POST['status']) && is_array($_POST['status']) && !empty($_POST['status'])) {
        $status_ids = implode(",", array_map('intval', $_POST['status']));
        $ticket_status_snippet = "ticket_status IN ($status_ids)";

        $status_names = [];
        $sql_statuses = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id IN ($status_ids) ORDER BY ticket_status_name ASC");
        while ($status_row = mysqli_fetch_assoc($sql_statuses)) {
            $status_names[] = $status_row['ticket_status_name'];
        }
        $filter_summary['Status'] = implode(', ', $status_names);
    } elseif (!empty($_POST['resolution']) && $_POST['resolution'] == 'Closed') {
        $ticket_status_snippet = "ticket_resolved_at IS NOT NULL";
        $filter_summary['Status'] = 'Closed';
    } elseif (!empty($_POST['resolution']) && $_POST['resolution'] == 'All') {
        $ticket_status_snippet = "1 = 1";
        $filter_summary['Status'] = 'Open and closed';
    } else {
        // Default - open tickets
        $ticket_status_snippet = "ticket_resolved_at IS NULL";
        $filter_summary['Status'] = 'Open';
    }

    // Billing Filter - same values the tickets page offers
    $ticket_billable_snippet = '';
    if (!empty($_POST['billing'])) {
        if ($_POST['billing'] == 'unbilled') {
            $ticket_billable_snippet = "AND ticket_billable = 1 AND ticket_invoice_id = 0";
            $filter_summary['Billing'] = 'Billable, not invoiced';
        } elseif ($_POST['billing'] == 'invoiced') {
            $ticket_billable_snippet = "AND ticket_invoice_id > 0";
            $filter_summary['Billing'] = 'Invoiced';
        } elseif ($_POST['billing'] == 'nonbillable') {
            $ticket_billable_snippet = "AND ticket_billable = 0";
            $filter_summary['Billing'] = 'Not billable';
        }
    }

    // Category Filter
    if (!empty($_POST['category'])) {
        $filter_category_id = intval($_POST['category']);
        $category_query = "AND (ticket_category = $filter_category_id)";
        $filter_summary['Category'] = getFieldById('categories', $filter_category_id, 'category_name');
    } else {
        // Default - any
        $category_query = '';
    }

    // Assignment Filter
    if (!empty($_POST['assigned'])) {
        if ($_POST['assigned'] == 'unassigned') {
            $ticket_assigned_query = 'AND ticket_assigned_to = 0';
            $filter_summary['Assigned'] = 'Unassigned';
        } else {
            $filter_user_id = intval($_POST['assigned']);
            $ticket_assigned_query = "AND ticket_assigned_to = $filter_user_id";
            $filter_summary['Assigned'] = getFieldById('users', $filter_user_id, 'user_name');
        }
    } else {
        // Default - any
        $ticket_assigned_query = '';
    }

    // SLA State Filter
    $ticket_sla_query = '';
    if (!empty($_POST['sla'])) {
        $sla_filter = $_POST['sla'];
        if ($sla_filter == 'breached') {
            $ticket_sla_query = 'AND ticket_sla_id > 0 AND (ticket_response_sla_alert_stage = 2 OR ticket_resolution_sla_alert_stage = 2 OR ticket_response_sla_met = 0 OR ticket_resolution_sla_met = 0)';
            $filter_summary['SLA'] = 'SLA breached';
        } elseif ($sla_filter == 'at_risk') {
            $ticket_sla_query = 'AND ticket_sla_id > 0 AND COALESCE(ticket_status_pauses_sla, 0) = 0 AND (ticket_response_sla_alert_stage = 1 OR ticket_resolution_sla_alert_stage = 1)';
            $filter_summary['SLA'] = 'SLA at risk';
        } elseif ($sla_filter == 'paused') {
            $ticket_sla_query = 'AND ticket_sla_id > 0 AND ticket_status_pauses_sla = 1';
            $filter_summary['SLA'] = 'SLA paused';
        } elseif ($sla_filter == 'met') {
            $ticket_sla_query = 'AND ticket_sla_id > 0 AND ticket_response_sla_met = 1 AND (ticket_resolution_sla_met = 1 OR ticket_resolution_due_at IS NULL)';
            $filter_summary['SLA'] = 'SLA met';
        } elseif ($sla_filter == 'none') {
            $ticket_sla_query = 'AND ticket_sla_id = 0';
            $filter_summary['SLA'] = 'No SLA';
        }
    }

    // Project Filter
    if (!empty($_POST['project']) && $_POST['project'] > '0') {
        $filter_project_id = intval($_POST['project']);
        $ticket_project_snippet = "AND ticket_project_id = $filter_project_id";
        $filter_summary['Project'] = getFieldById('projects', $filter_project_id, 'project_name');
    } else {
        // Default - any, including tickets without a project
        $ticket_project_snippet = '';
    }

    // Tickets with no client stay visible to restricted agents - clientScopeSql() includes 0
    $access_permission_query_overide = clientScopeSql('ticket_client_id');

    // Date Filter
    $dtf = escapeSql(!empty($_POST['dtf']) ? $_POST['dtf'] : '1970-01-01');
    $dtt = escapeSql(!empty($_POST['dtt']) ? $_POST['dtt'] : '2099-12-31');
    $date_range = formatExportDateRange($dtf, $dtt);
    if ($date_range) {
        $filter_summary['Opened'] = $date_range;
    }

    // Search Filter
    $q = escapeSql($_POST['q'] ?? '');
    if (!empty($q)) {
        $filter_summary['Search'] = $_POST['q'];
    }

    // Get records from database - same shape as the tickets page list query
    $sql = mysqli_query(
        $mysqli,
        "SELECT category_name, client_name, contact_name, ticket_billable, ticket_closed_at,
        ticket_created_at, ticket_number, ticket_prefix, ticket_priority, ticket_resolved_at,
        ticket_status_name, ticket_subject, user_name FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        LEFT JOIN assets ON ticket_asset_id = asset_id
        LEFT JOIN locations ON ticket_location_id = location_id
        LEFT JOIN vendors ON ticket_vendor_id = vendor_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN categories ON ticket_category = category_id
        WHERE ticket_deleted_at IS NULL
        AND $ticket_status_snippet
        $ticket_assigned_query
        $category_query
        AND DATE(ticket_created_at) BETWEEN '$dtf' AND '$dtt'
        AND (CONCAT(ticket_prefix,ticket_number) LIKE '%$q%' OR client_name LIKE '%$q%' OR ticket_subject LIKE '%$q%' OR ticket_status_name LIKE '%$q%' OR ticket_priority LIKE '%$q%' OR user_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR asset_name LIKE '%$q%' OR vendor_name LIKE '%$q%')
        $ticket_sla_query
        $ticket_billable_snippet
        $ticket_project_snippet
        $access_permission_query_overide
        $client_query
        ORDER BY ticket_number ASC"
    );

    $num_rows = mysqli_num_rows($sql);

    if ($num_rows > 0) {

        guardExportPdfRowCount($format, $num_rows);

        $export = beginExport('tickets', $format, $file_name_prepend . 'Tickets', 'Tickets', summarizeExportFilters($filter_summary));

        while ($row = mysqli_fetch_assoc($sql)) {
            // Per-ticket prefix where the row carries one, config default otherwise
            $row['ticket_number_display'] = ($row['ticket_prefix'] ?: $config_ticket_prefix) . $row['ticket_number'];
            $row['ticket_category_name'] = $row['category_name'];
            $row['ticket_assigned_to'] = $row['user_name'];
            $row['ticket_billable'] = $row['ticket_billable'] ? 'Yes' : 'No';
            addExportRow($export, $row);
        }

        finishExport($export);
    }

    logAudit("Ticket", "Export", "$session_name exported $num_rows ticket(s) to a " . strtoupper($format) . " file", $client_id);

    exit;

}

if (isset($_POST['edit_ticket_billable_status'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);
    enforceUserPermission('module_sales', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $billable_status = intval($_POST['billable_status']);
    if ($billable_status == 0 ) {
        $billable_wording = "Not";
    }

    // Get ticket details for logging
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $client_id = intval($row['ticket_client_id']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli,"UPDATE tickets SET ticket_billable = $billable_status WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    logAudit("Ticket", "Edit", "$session_name marked ticket $ticket_prefix$ticket_number as $billable_wording Billable", $client_id, $ticket_id);

    flashAlert("Ticket marked <strong>$billable_wording Billable</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_schedule'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $onsite = intval($_POST['onsite']);
    $schedule = escapeSql($_POST['scheduled_date_time']);
    $ticket_link = "client/ticket.php?id=$ticket_id";
    $full_ticket_url = "https://$config_base_url/client/ticket.php?id=$ticket_id";
    $ticket_link_html = "<a href=\"$full_ticket_url\">$ticket_link</a>";

    $ticket_access = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1"));
    if (!$ticket_access) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($ticket_access['ticket_client_id']);
    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli,"UPDATE tickets
        SET ticket_schedule = '$schedule', ticket_onsite = $onsite
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL"
    );

    // Check for other conflicting scheduled items based on 2 hr window
    //TODO make this configurable
    $start = date('Y-m-d H:i:s', strtotime($schedule) - 7200);
    $end = date('Y-m-d H:i:s', strtotime($schedule) + 7200);
    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_schedule, ticket_subject FROM tickets WHERE ticket_deleted_at IS NULL AND ticket_schedule BETWEEN '$start' AND '$end' AND ticket_id != $ticket_id");
    if (mysqli_num_rows($sql) > 0) {
        $conflicting_tickets = [];
        while ($row = mysqli_fetch_assoc($sql)) {
            $conflicting_tickets[] = $row['ticket_id'] . " - " . $row['ticket_subject'] . " @ " . $row['ticket_schedule'];
        }
    }
    $sql = mysqli_query($mysqli, "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations on contact_location_id = location_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
    ");

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $client_name = escapeSql($row['client_name']);
    $ticket_details = escapeSql($row['ticket_details']);
    $contact_name = escapeSql($row['contact_name']);
    $contact_email = escapeSql($row['contact_email']);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $user_name = escapeSql($row['user_name']);
    $user_email = escapeSql($row['user_email']);
    $cal_subject = $ticket_number . ": " . $client_name . " - " . $ticket_subject;
    $ticket_details_truncated = substr($ticket_details, 0, 100);
    $cal_description = $ticket_details_truncated . " - " . $full_ticket_url;
    $cal_location = escapeSql($row["location_address"]);
    $email_datetime = date('l, F j, Y \a\t g:ia', strtotime($schedule));

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    // Sanitize Config Vars
    $config_ticket_from_email = escapeSql($config_ticket_from_email);
    $config_ticket_from_name = escapeSql($config_ticket_from_name);
    $session_company_name = escapeSql($session_company_name);


    /// Create iCal event
    $cal_str = createiCalStr($schedule, $cal_subject, $cal_description, $cal_location, getTicketCalendarUid($ticket_id));

    // Notify the agent of the scheduled work
    $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $user_email,
            'recipient_name' => $user_name,
            'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => "Hello, " . $user_name . "<br><br>The ticket regarding $ticket_subject has been scheduled for $email_datetime.<br><br>--------------------------------<br><a href=\"https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri\">$ticket_link</a><br>--------------------------------<br><br>Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri<br><br>~<br>$session_company_name<br>Support Department<br>$config_ticket_from_email",
            'cal_str' => $cal_str
        ];

    if ($config_ticket_client_general_notifications) {
        // Notify the ticket contact of the scheduled work
        $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => mysqli_escape_string($mysqli, "<div class='header'>
                                Hello, $contact_name
                            </div>
                            Your ticket regarding $ticket_subject has been scheduled for $email_datetime.
                            <br><br>
                            <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>Access your ticket here</a>
                            <br><br>
                            Please do not reply to this email.
                            <br><br>
                            <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
                            <strong>Subject:</strong> $ticket_subject<br>
                            <br><br>
                            <div class='footer'>
                                ~<br>
                                $session_company_name<br>
                                Support Department<br>
                                $config_ticket_from_email<br>
                            </div>
                            <div class='no-reply'>
                                This is an automated message. Please do not reply directly to this email.
                            </div>"),
            'cal_str' => $cal_str
        ];

        // Notify the watchers of the scheduled work
        $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
            INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
            WHERE tw.watcher_ticket_id = $ticket_id");

        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = escapeSql($row['watcher_email']);
            $data[] = [
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => "Ticket Scheduled - [$ticket_prefix$ticket_number] - $ticket_subject",
                'body' => mysqli_escape_string($mysqli, escapeHtml("<div class='header'>
            Hello,
        </div>
        The ticket regarding $ticket_subject has been scheduled for $email_datetime.
        <br><br>
        <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>$ticket_link</a>
        <br><br>
        Please do not reply to this email.
        <br><br>
        <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
        <strong>Subject:</strong> $ticket_subject<br>
        <strong>Portal:</strong> <a href='https://$config_base_url/client/ticket.php?id=$ticket_id'>Access the ticket here</a>
        <br><br>
        <div class='footer'>
            ~<br>
            $session_company_name<br>
            Support Department<br>
            $config_ticket_from_email<br>
        </div>
        <div class='no-reply'>
            This is an automated message. Please do not reply directly to this email.
        </div>")),
                'cal_str' => $cal_str
            ];
        }
    }

    // Send
    $response = addToMailQueue($data);

    // Update ticket reply
    $ticket_reply_note = "Ticket scheduled for $email_datetime " . (boolval($onsite) ? '(onsite).' : '(remote).');
    mysqli_query($mysqli, "INSERT INTO ticket_replies
        (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_by, ticket_reply_ticket_id)
        SELECT '$ticket_reply_note', 'Internal', '00:00:00', $session_user_id, ticket_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");

    logAudit("Ticket", "Edit", "$session_name edited ticket schedule", $client_id, $ticket_id);

    triggerCustomAction('ticket_schedule', $ticket_id);

    if (empty($conflicting_tickets)) {
        flashAlert("Ticket scheduled for $email_datetime");
        redirect();
    } else {
        $_SESSION['alert_type'] = "error";
        flashAlert("Ticket scheduled for $email_datetime. Yet there are conflicting tickets scheduled for the same time: <br>" . implode(", <br>", $conflicting_tickets), 'error');
        redirect("calendar.php");
    }

}

if (isset($_GET['cancel_ticket_schedule'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['cancel_ticket_schedule']);

    $sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $client_id = intval($row['ticket_client_id']);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $ticket_schedule = escapeSql($row['ticket_schedule']);

    // Don't Enforce Client Access if Ticket doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    if ($client_id) {
        $client_uri = "&client_id=$client_id";
    } else {
        $client_uri = '';
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = NULL WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL");

    // Sanitize Config Vars
    $config_ticket_from_email = escapeSql($config_ticket_from_email);
    $config_ticket_from_name = escapeSql($config_ticket_from_name);
    $session_company_name = escapeSql($session_company_name);

    //Send emails

    $sql = mysqli_query($mysqli, "SELECT client_name, contact_email, contact_name, ticket_client_id, ticket_details, ticket_number,
        ticket_prefix, ticket_subject, user_email, user_name FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations on contact_location_id = location_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
    ");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The ticket is unavailable.', 'error');
        redirect();
    }

    $client_id = intval($row['ticket_client_id']);
    $client_name = escapeSql($row['client_name']);
    $ticket_details = escapeSql($row['ticket_details']);
    $contact_name = escapeSql($row['contact_name']);
    $contact_email = escapeSql($row['contact_email']);
    $ticket_prefix = escapeSql($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = escapeSql($row['ticket_subject']);
    $user_name = escapeSql($row['user_name']);
    $user_email = escapeSql($row['user_email']);

    //Create the iCal cancellation - same UID and subject as the original invite
    $cal_subject = $ticket_number . ": " . $client_name . " - " . $ticket_subject;
    $cal_str = createiCalStrCancel($ticket_schedule, $cal_subject, getTicketCalendarUid($ticket_id));

    // Notify the agent of the cancellation
    $data[] = [
            // User Email
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $user_email,
            'recipient_name' => $user_name,
            'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => "Hello, " . $user_name . "<br><br>Scheduled work for the ticket regarding $ticket_subject has been cancelled.<br><br>--------------------------------<br><a href=\"https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri\">$ticket_link</a><br>--------------------------------<br><br>Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/agent/ticket.php?id=$ticket_id&client_id=$client_id<br><br>~<br>$session_company_name<br>Support Department<br>$config_ticket_from_email",
            'cal_str' => $cal_str
        ];

    if ($config_ticket_client_general_notifications) {
        // Notify the ticket contact of the cancellation
        $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
            'body' => mysqli_escape_string($mysqli, "<div class='header'>
                                Hello, $contact_name
                            </div>
                            Scheduled work for your ticket regarding $ticket_subject has been cancelled.
                            <br><br>
                            <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>Access your ticket here</a>
                            <br><br>
                            Please do not reply to this email.
                            <br><br>
                            <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
                            <strong>Subject:</strong> $ticket_subject<br>
                            <br><br>
                            <div class='footer'>
                                ~<br>
                                $session_company_name<br>
                                Support Department<br>
                                $config_ticket_from_email<br>
                            </div>
                            <div class='no-reply'>
                                This is an automated message. Please do not reply directly to this email.
                            </div>"),
            'cal_str' => $cal_str
        ];

        // Notify the watchers of the cancellation
        $sql_watchers = mysqli_query($mysqli, "SELECT tw.watcher_email FROM ticket_watchers tw
            INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL
            WHERE tw.watcher_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql_watchers)) {
            $watcher_email = escapeSql($row['watcher_email']);
            $data[] = [
                'from' => $config_ticket_from_email,
                'from_name' => $config_ticket_from_name,
                'recipient' => $watcher_email,
                'recipient_name' => $watcher_email,
                'subject' => "Ticket Schedule Cancelled - [$ticket_prefix$ticket_number] - $ticket_subject",
                'body' => mysqli_escape_string($mysqli, escapeHtml("<div class='header'>
            Hello,
        </div>
        Scheduled work for the ticket regarding $ticket_subject has been cancelled.
        <br><br>
        <a href='https://$config_base_url/client/ticket.php?id=$ticket_id' class='link-button'>$ticket_link</a>
        <br><br>
        Please do not reply to this email.
        <br><br>
        <strong>Ticket:</strong> $ticket_prefix$ticket_number<br>
        <strong>Subject:</strong> $ticket_subject<br>
        <strong>Portal:</strong> <a href='https://$config_base_url/client/ticket.php?id=$ticket_id'>Access the ticket here</a>
        <br><br>
        <div class='footer'>
            ~<br>
            $session_company_name<br>
            Support Department<br>
            $config_ticket_from_email<br>
        </div>
        <div class='no-reply'>
            This is an automated message. Please do not reply directly to this email.
        </div>")),
                'cal_str' => $cal_str
            ];
        }
    }

    // Send email(s)
    addToMailQueue($data);

    // Update ticket reply
    $ticket_reply_note = "Ticket schedule cancelled.";
    mysqli_query($mysqli, "INSERT INTO ticket_replies
        (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_by, ticket_reply_ticket_id)
        SELECT '$ticket_reply_note', 'Internal', '00:00:00', $session_user_id, ticket_id
        FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");

    logAudit("Ticket", "Edit", "$session_name cancelled ticket schedule", $client_id, $ticket_id);

    triggerCustomAction('ticket_unschedule', $ticket_id);

    flashAlert("Ticket schedule cancelled", 'error');

    redirect();

}
