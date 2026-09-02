<?php

/*
 * ITFlow - GET/POST request handler for tasks
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_name = escapeSql($_POST['name'] ?? '');
    $project_description = escapeSql($_POST['description'] ?? '');
    $due_date = escapeSql($_POST['due_date'] ?? '');
    $project_manager = intval($_POST['project_manager'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    $project_template_id = intval($_POST['project_template_id'] ?? 0);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        $client = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT client_id FROM clients
            WHERE client_id = $client_id AND client_lead = 0
            AND client_archived_at IS NULL LIMIT 1", 'Could not validate the project client'));
        if (!$client) {
            flashAlert('The selected client is unavailable or archived', 'error');
            redirect();
        }
        enforceClientAccess($client_id);
    }

    if ($project_manager) {
        $manager = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT user_id FROM users
            WHERE user_id = $project_manager AND user_type = 1 AND user_status = 1
            AND user_archived_at IS NULL LIMIT 1", 'Could not validate the project manager'));
        if (!$manager) {
            flashAlert('The selected project manager is unavailable', 'error');
            redirect();
        }
    }

    $project_stages = [];
    if ($project_template_id) {
        $project_template = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_template_id
            FROM project_templates WHERE project_template_id = $project_template_id
            AND project_template_archived_at IS NULL LIMIT 1", 'Could not validate the project template'));
        if (!$project_template) {
            flashAlert('The selected project template is unavailable or archived', 'error');
            redirect();
        }

        // Join the explicitly pinned version only. Falling back to a template's
        // latest version would silently change a released project definition.
        $sql_ticket_templates = ticketCreationDbQuery("SELECT
            ptt.ticket_template_id AS association_ticket_template_id,
            ptt.ticket_template_order,
            ptt.ticket_template_runbook_version_id,
            tt.ticket_template_id,
            tt.ticket_template_subject,
            tt.ticket_template_details,
            tt.ticket_template_published_version_id,
            tt.ticket_template_archived_at,
            rv.runbook_version_id,
            rv.runbook_version_subject,
            rv.runbook_version_details,
            (SELECT COUNT(*) FROM runbook_versions history
                WHERE history.runbook_version_ticket_template_id = ptt.ticket_template_id) AS runbook_version_count
            FROM project_template_ticket_templates ptt
            LEFT JOIN ticket_templates tt
                ON tt.ticket_template_id = ptt.ticket_template_id
            LEFT JOIN runbook_versions rv
                ON rv.runbook_version_id = ptt.ticket_template_runbook_version_id
                AND rv.runbook_version_ticket_template_id = ptt.ticket_template_id
            WHERE ptt.project_template_id = $project_template_id
            ORDER BY ptt.ticket_template_order ASC, ptt.ticket_template_id ASC", 'Could not load the project template stages');

        while ($row = mysqli_fetch_assoc($sql_ticket_templates)) {
            $ticket_template_id = intval($row['ticket_template_id']);
            $pinned_version_id = intval($row['ticket_template_runbook_version_id']);
            $current_published_version_id = intval($row['ticket_template_published_version_id']);
            $runbook_version_count = intval($row['runbook_version_count']);
            if (!$ticket_template_id || !empty($row['ticket_template_archived_at'])) {
                error_log("Project template $project_template_id contains a missing or archived ticket template stage");
                flashAlert('The selected project template contains a missing or archived ticket template', 'error');
                redirect();
            }
            if ($pinned_version_id && intval($row['runbook_version_id']) !== $pinned_version_id) {
                error_log("Project template $project_template_id stage $ticket_template_id has invalid pinned runbook version $pinned_version_id");
                flashAlert('The selected project template contains an unavailable pinned runbook version', 'error');
                redirect();
            }
            if (!$pinned_version_id && ($current_published_version_id || $runbook_version_count)) {
                error_log("Project template $project_template_id stage $ticket_template_id is versioned but unpinned");
                flashAlert('The selected project template contains a versioned stage without an explicit pinned release', 'error');
                redirect();
            }
            if ($pinned_version_id && !$client_id) {
                flashAlert('A project with versioned runbook stages requires an active client', 'error');
                redirect();
            }

            $stage_subject = $pinned_version_id
                ? (string) $row['runbook_version_subject']
                : (string) $row['ticket_template_subject'];
            if (trim($stage_subject) === '') {
                error_log("Project template $project_template_id stage $ticket_template_id has no ticket subject");
                flashAlert('The selected project template contains a stage without a ticket subject', 'error');
                redirect();
            }

            $project_stages[] = [
                'ticket_template_id' => $ticket_template_id,
                'ticket_template_order' => intval($row['ticket_template_order']),
                'runbook_version_id' => $pinned_version_id,
                'subject' => $stage_subject,
                'details' => $pinned_version_id ? $row['runbook_version_details'] : $row['ticket_template_details'],
            ];
        }
    }

    // Sanitize prefixes before they are used in the transaction.
    $config_project_prefix = escapeSql($config_project_prefix);
    $config_ticket_prefix = escapeSql($config_ticket_prefix);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the project creation transaction');
        }
        $transaction_started = true;

        ticketCreationDbQuery("
            UPDATE settings
            SET
                config_project_next_number = LAST_INSERT_ID(config_project_next_number),
                config_project_next_number = config_project_next_number + 1
            WHERE company_id = 1
        ", 'Could not allocate a project number');
        $project_number = intval(mysqli_insert_id($mysqli));
        if (!$project_number) {
            throw new RuntimeException('The project number allocation returned no number');
        }

        ticketCreationDbQuery("INSERT INTO projects SET project_prefix = '$config_project_prefix', project_number = $project_number, project_name = '$project_name', project_description = '$project_description', project_due = '$due_date', project_manager = $project_manager, project_client_id = $client_id", 'Could not create the project');
        $project_id = intval(mysqli_insert_id($mysqli));
        if (!$project_id) {
            throw new RuntimeException('The new project did not receive an ID');
        }

        foreach ($project_stages as $stage) {
            $ticket_template_id = intval($stage['ticket_template_id']);
            $ticket_template_order = intval($stage['ticket_template_order']);
            $runbook_version_id = intval($stage['runbook_version_id']);
            $ticket_template_subject = escapeSql($stage['subject']);
            $ticket_template_details = mysqli_real_escape_string($mysqli, $stage['details']);

            ticketCreationDbQuery("
                UPDATE settings
                SET
                    config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                    config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1
            ", 'Could not allocate a project ticket number');
            $ticket_number = intval(mysqli_insert_id($mysqli));
            if (!$ticket_number) {
                throw new RuntimeException('A project ticket number allocation returned no number');
            }

            ticketCreationDbQuery("INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'Project Template', ticket_subject = '$ticket_template_subject', ticket_details = '$ticket_template_details', ticket_priority = 'Low', ticket_status = 1, ticket_created_by = $session_user_id, ticket_client_id = $client_id, ticket_project_id = $project_id, ticket_order = $ticket_template_order", 'Could not create a project ticket');
            $ticket_id = intval(mysqli_insert_id($mysqli));
            if (!$ticket_id) {
                throw new RuntimeException('A project ticket did not receive an ID');
            }
            applyTicketSla($ticket_id, null, null, true);

            addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id, true);
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the project and all required workflows');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log('Project creation failed before publication: ' . $exception->getMessage());
        flashAlert('The project was not created because one or more required template workflows were unavailable or could not be created safely', 'error');
        redirect();
    }

    logAudit("Project", "Create", "$session_name created project $project_name", $client_id, $project_id);

    flashAlert("You created Project <strong>$project_name</strong>");

    redirect();

}

if (isset($_POST['edit_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);
    $project_name = escapeSql($_POST['name']);
    $project_description = escapeSql($_POST['description']);
    $due_date = escapeSql($_POST['due_date']);
    $project_manager = intval($_POST['project_manager']);
    
    $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_name = '$project_name', project_description = '$project_description', project_due = '$due_date', project_manager = $project_manager WHERE project_id = $project_id");

    logAudit("Project", "Edit", "$session_name edited project $project_name", $client_id, $project_id);

    flashAlert("Project <strong>$project_name</strong> edited");

    redirect();

}

if (isset($_GET['close_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['close_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = escapeSql($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    $transaction_started = false;
    $project_close_error = 'The project could not be closed because it changed while the request was being processed';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the project close transaction');
        }
        $transaction_started = true;
        if ($client_id) {
            documentationLockClient($client_id);
        }

        $locked_project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_id,
            project_completed_at, project_archived_at FROM projects
            WHERE project_id = $project_id FOR UPDATE", 'Could not lock the project for close'));
        if (!$locked_project || !empty($locked_project['project_completed_at']) || !empty($locked_project['project_archived_at'])) {
            throw new RuntimeException('The project is missing, archived, or already complete');
        }

        // Lock child ticket parents in one deterministic order. Task/approval
        // mutations and ticket reopen/close updates serialize on these rows.
        $project_tickets = ticketCreationDbQuery("SELECT ticket_id, ticket_status,
            ticket_client_id FROM tickets
            WHERE ticket_project_id = $project_id AND ticket_archived_at IS NULL
            ORDER BY ticket_id ASC FOR UPDATE", 'Could not lock project tickets for close');
        $open_ticket_count = 0;
        $locked_tickets = [];
        while ($project_ticket = mysqli_fetch_assoc($project_tickets)) {
            if (intval($project_ticket['ticket_client_id']) !== $client_id) {
                throw new RuntimeException('A project ticket crossed client scope');
            }
            $locked_tickets[] = $project_ticket;
            if (!in_array(intval($project_ticket['ticket_status']), [4, 5], true)) {
                $open_ticket_count++;
            }
        }
        if ($open_ticket_count) {
            $project_close_error = "Project cannot be closed while $open_ticket_count ticket(s) remain open";
            throw new RuntimeException('The project has open tickets');
        }

        foreach ($locked_tickets as $project_ticket) {
            [$can_resolve, $resolve_error] = runbookTicketCanResolve(intval($project_ticket['ticket_id']));
            if (!$can_resolve) {
                $project_close_error = 'Project cannot be closed: ' . $resolve_error;
                throw new RuntimeException('A project ticket workflow gate is not satisfied');
            }
        }

        ticketCreationDbQuery("UPDATE projects SET project_completed_at = NOW()
            WHERE project_id = $project_id AND project_completed_at IS NULL
            AND project_archived_at IS NULL", 'Could not close the project');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The project was no longer open when close was committed');
        }

        // Project completion is an authoritative terminal checkpoint for every
        // child ticket. Append any configuration-change passports atomically
        // with the project close, even though the tickets are already terminal.
        foreach ($locked_tickets as $project_ticket) {
            documentationRecordChangePassport(
                intval($project_ticket['ticket_id']),
                intval($project_ticket['ticket_status']),
                $session_user_id,
                true
            );
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the project close');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Project $project_id close failed safely: " . $exception->getMessage());
        flashAlert(escapeHtml($project_close_error), 'error');
        redirect();
    }

    logAudit("Project", "Close", "$session_name closed project $project_name", $client_id, $project_id);

    flashAlert("Project <strong>$project_name</strong> closed");

    redirect();

}

if (isset($_GET['archive_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['archive_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = escapeSql($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_archived_at = NOW() WHERE project_id = $project_id");

    logAudit("Project", "Archive", "$session_name archived project $project_name", $client_id, $project_id);

    flashAlert("Project <strong>$project_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_GET['restore_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = escapeSql($row['project_name']);
    $client_id = escapeSql($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    mysqli_query($mysqli, "UPDATE projects SET project_archived_at = NULL WHERE project_id = $project_id");

    logAudit("Project", "Restore", "$session_name restored project $project_name", $client_id, $project_id);

    flashAlert("Project <strong>$project_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 3);

    $project_id = intval($_GET['delete_project']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_name, project_client_id FROM projects WHERE project_id = $project_id");
    $row = mysqli_fetch_assoc($sql);
    $project_name = escapeSql($row['project_name']);
    $client_id = intval($row['project_client_id']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    $child_ticket_count = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT COUNT(*) FROM tickets
        WHERE ticket_project_id = $project_id", 'Could not check project ticket retention'))[0] ?? 0);
    $execution_context_pattern = mysqli_real_escape_string($mysqli, '%"project_id":' . $project_id . ',%');
    $runbook_context_count = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT COUNT(*)
        FROM runbook_executions re
        LEFT JOIN tickets t ON t.ticket_id = re.runbook_execution_ticket_id
        WHERE t.ticket_project_id = $project_id
        OR re.runbook_execution_context LIKE '$execution_context_pattern'",
        'Could not check project runbook retention'))[0] ?? 0);
    if ($child_ticket_count || $runbook_context_count) {
        logAudit('Project', 'Delete Blocked', "$session_name attempted to delete project $project_name with $child_ticket_count child ticket(s) and $runbook_context_count runbook execution context(s)", $client_id, $project_id);
        flashAlert('Projects with ticket or published runbook history cannot be permanently deleted. Archive the project to preserve its audit trail.', 'error');
        redirect();
    }

    mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project_id");

    logAudit("Project", "Delete", "$session_name deleted project $project_name", $client_id, $project_id);

    flashAlert("Project <strong>$project_name</strong> Deleted", 'error');

    redirect();

}

if (isset($_POST['link_ticket_to_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_client_id, project_name FROM projects
        WHERE project_id = $project_id AND project_completed_at IS NULL
        AND project_archived_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The project is unavailable, archived, or already complete', 'error');
        redirect();
    }
    $client_id = intval($row['project_client_id']);
    $project_name = escapeSql($row['project_name']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    // Add Tickets
    if (isset($_POST['tickets'])) {

        $count = 0;
        $skipped_count = 0;
        $ticket_ids = array_values(array_unique(array_filter(array_map('intval', (array) $_POST['tickets']))));
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
                $count++;
                logAudit("Project", "Edit", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $project_id);
            } catch (Throwable $exception) {
                $skipped_count++;
                error_log("Project $project_id skipped ticket $ticket_id during linking: " . $exception->getMessage());
            }
        }

        logAudit("Project", "Bulk Edit", "$session_name added $count ticket(s) to project $project_name", $client_id, $project_id);

        flashAlert("<strong>$count</strong> Ticket(s) added to <strong>$project_name</strong>");
        if ($skipped_count) {
            flashAlert("<strong>$skipped_count</strong> ticket(s) were not linked because their project or immutable runbook context prevents reassignment", 'info');
        }
    }

    redirect();

}

if (isset($_POST['link_closed_ticket_to_project'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $project_id = intval($_POST['project_id']);
    $ticket_number = intval($_POST['ticket_number']);

    // Get Project Name and Client ID for logging
    $sql = mysqli_query($mysqli, "SELECT project_client_id, project_name FROM projects
        WHERE project_id = $project_id AND project_completed_at IS NULL
        AND project_archived_at IS NULL");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The project is unavailable, archived, or already complete', 'error');
        redirect();
    }
    $client_id = intval($row['project_client_id']);
    $project_name = escapeSql($row['project_name']);

    // Don't Enforce Client Access if Project doesn't have an assigned client
    if ($client_id) {
        enforceClientAccess();
    }

    try {
        $row = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_id, ticket_prefix,
            ticket_number, ticket_subject FROM tickets
            WHERE ticket_number = $ticket_number AND ticket_client_id = $client_id LIMIT 1",
            'Could not locate the closed ticket for project linking'));
        if (!$row) {
            throw new RuntimeException('The selected ticket is unavailable for this client');
        }
        $ticket_id = intval($row['ticket_id']);
        $assignment = ticketAssignProjectSafely($ticket_id, $project_id, true);
        $ticket_prefix = escapeSql($assignment['ticket_prefix']);
        $ticket_number = intval($assignment['ticket_number']);
        $ticket_subject = escapeSql($assignment['ticket_subject']);
    } catch (Throwable $exception) {
        error_log("Project $project_id closed ticket link failed safely: " . $exception->getMessage());
        flashAlert('The ticket was not linked because the project changed or the ticket was unavailable', 'error');
        redirect();
    }

    logAudit("Project", "Edit", "$session_name added ticket $ticket_prefix$ticket_number - $ticket_subject to project $project_name", $client_id, $project_id);

    flashAlert("Ticket added to <strong>$project_name</strong>");

    redirect();

}
