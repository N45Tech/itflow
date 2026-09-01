<?php

// App/UI helpers - icons, badges, lookups, mail queue, iCal, taxes, update check
// Split from the former monolithic functions.php

function getAssetIcon($asset_type) {
    if ($asset_type == 'Laptop') {
        $device_icon = "laptop";
    } elseif ($asset_type == 'Desktop') {
        $device_icon = "desktop";
    } elseif ($asset_type == 'Server') {
        $device_icon = "server";
    } elseif ($asset_type == 'Printer') {
        $device_icon = "print";
    } elseif ($asset_type == 'Camera') {
        $device_icon = "video";
    } elseif ($asset_type == 'Switch') {
        $device_icon = "network-wired";
    } elseif ($asset_type == 'Firewall/Router') {
        $device_icon = "fire-alt";
    } elseif ($asset_type == 'Access Point') {
        $device_icon = "wifi";
    } elseif ($asset_type == 'Phone') {
        $device_icon = "phone";
    } elseif ($asset_type == 'Mobile Phone') {
        $device_icon = "mobile-alt";
    } elseif ($asset_type == 'Tablet') {
        $device_icon = "tablet-alt";
    } elseif ($asset_type == 'Display') {
        $device_icon = "tv";
    } elseif ($asset_type == 'Virtual Machine') {
        $device_icon = "cloud";
    } else {
        $device_icon = "tag";
    }

    return $device_icon;
}

function getInvoiceBadgeColor($invoice_status) {
    if ($invoice_status == "Sent") {
        $invoice_badge_color = "warning text-white";
    } elseif ($invoice_status == "Viewed") {
        $invoice_badge_color = "info";
    } elseif ($invoice_status == "Partial") {
        $invoice_badge_color = "primary";
    } elseif ($invoice_status == "Paid") {
        $invoice_badge_color = "success";
    } elseif ($invoice_status == "Cancelled") {
        $invoice_badge_color = "danger";
    } else {
        $invoice_badge_color = "secondary";
    }

    return $invoice_badge_color;
}

/*
 * The display name for a ticket status id, RAW. Escaping is the caller's job -
 * same convention as getFieldById() above.
 */
function getTicketStatusName($ticket_status) {

    global $mysqli;

    $status_id = intval($ticket_status);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = $status_id LIMIT 1"));

    if (!$row) {
        // Default return
        return "Unknown";
    }

    return $row['ticket_status_name'];

}


/**
 * Copies a ticket template's tasks onto a ticket.
 *
 * Called anywhere a ticket is raised from a template - the ticket add modals,
 * the bulk asset add, and every recurring ticket run (cron, force, bulk force).
 * Tasks are a snapshot: editing the template later does not touch tickets that
 * have already been raised from it.
 *
 * @param int $ticket_id          The ticket to attach the tasks to.
 * @param int $ticket_template_id The template to copy tasks from. 0 = no-op.
 *
 * @param int  $runbook_version_id Optional pinned runbook version (project releases).
 * @param bool $caller_transaction When true, propagate failures so the caller can
 *                                 roll back the ticket and workflow together.
 *
 * @return int The number of tasks created.
 */
function addTasksFromTicketTemplate($ticket_id, $ticket_template_id, $runbook_version_id = 0, $caller_transaction = false) {

    global $mysqli;

    $ticket_id = intval($ticket_id);
    $ticket_template_id = intval($ticket_template_id);
    $runbook_version_id = intval($runbook_version_id);
    $caller_transaction = (bool) $caller_transaction;

    if (!$ticket_id || !$ticket_template_id) {
        return 0;
    }

    // The template's published pointer is authoritative. Never guess a
    // historical version if that pointer was cleared or corrupted: doing so
    // could execute an older release against mutable draft content.
    $published_version_id = $runbook_version_id;
    if (!$published_version_id && function_exists('instantiateRunbookForTicket')) {
        $release = mysqli_query($mysqli, "SELECT ticket_template_published_version_id,
            ticket_template_archived_at,
            (SELECT COUNT(*) FROM runbook_versions history
                WHERE history.runbook_version_ticket_template_id = ticket_template_id) AS runbook_version_count
            FROM ticket_templates WHERE ticket_template_id = $ticket_template_id LIMIT 1");
        if (!$release) {
            if ($caller_transaction) {
                throw new RuntimeException('Could not validate the ticket template release: ' . mysqli_error($mysqli));
            }
            return 0;
        }
        $release_row = mysqli_fetch_assoc($release);
        if (!$release_row || !empty($release_row['ticket_template_archived_at'])) {
            if ($caller_transaction) {
                throw new RuntimeException('The ticket template is unavailable or archived');
            }
            error_log("Ticket template $ticket_template_id is unavailable or archived");
            return 0;
        }
        $published_version_id = intval($release_row['ticket_template_published_version_id'] ?? 0);
        if (!$published_version_id && intval($release_row['runbook_version_count'] ?? 0) > 0) {
            if ($caller_transaction) {
                throw new RuntimeException('The ticket template has runbook history but no published release');
            }
            error_log("Ticket template $ticket_template_id has runbook history but no published release");
            return 0;
        }
    }

    // Published runbooks carry an immutable task snapshot plus workflow
    // metadata. The explicit version is validated again by the instantiator.
    if ($published_version_id) {
        return instantiateRunbookForTicket($ticket_id, $ticket_template_id, [
            'version_id' => $published_version_id,
            'caller_transaction' => $caller_transaction,
        ]);
    }

    $sql_task_templates = mysqli_query($mysqli, "SELECT task_template_completion_estimate, task_template_name, task_template_order FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id ORDER BY task_template_order ASC");
    if (!$sql_task_templates) {
        if ($caller_transaction) {
            throw new RuntimeException('Could not load ticket template tasks: ' . mysqli_error($mysqli));
        }
        return 0;
    }

    $tasks_added = 0;

    while ($row = mysqli_fetch_assoc($sql_task_templates)) {
        $task_order = intval($row['task_template_order']);
        $task_name = escapeSql($row['task_template_name']);
        $task_completion_estimate = intval($row['task_template_completion_estimate']);

        $inserted = mysqli_query($mysqli, "INSERT INTO tasks SET task_name = '$task_name', task_order = $task_order, task_completion_estimate = $task_completion_estimate, task_ticket_id = $ticket_id");
        if (!$inserted) {
            if ($caller_transaction) {
                throw new RuntimeException('Could not create a ticket template task: ' . mysqli_error($mysqli));
            }
            break;
        }

        $tasks_added++;
    }

    return $tasks_added;

}

/**
 * Run a write needed to create a ticket/project and fail loudly enough for an
 * outer transaction to roll the whole creation back.
 */
function ticketCreationDbQuery($query, $message) {

    global $mysqli;

    $result = mysqli_query($mysqli, $query);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }

    return $result;
}

/**
 * Change a ticket's project without racing project completion. Project
 * transitions use the same project-before-ticket lock order as project close.
 *
 * The caller must enforce access to the ticket's client before calling this.
 * Set $preserve_updated_at when attaching a closed historical ticket so the
 * relationship change does not rewrite its last operational activity time.
 */
function ticketAssignProjectSafely($ticket_id, $target_project_id, $preserve_updated_at = false) {

    global $mysqli;

    $ticket_id = intval($ticket_id);
    $target_project_id = intval($target_project_id);
    $preserve_updated_at = (bool) $preserve_updated_at;
    $ticket = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_client_id,
        ticket_project_id, ticket_prefix, ticket_number, ticket_subject FROM tickets
        WHERE ticket_id = $ticket_id LIMIT 1", 'Could not load the ticket project assignment'));
    if (!$ticket) {
        throw new RuntimeException('The ticket is unavailable');
    }

    $client_id = intval($ticket['ticket_client_id']);
    $source_project_id = intval($ticket['ticket_project_id']);
    $result = [
        'client_id' => $client_id,
        'source_project_id' => $source_project_id,
        'target_project_id' => $target_project_id,
        'project_name' => $target_project_id ? '' : 'No project',
        'ticket_prefix' => (string) $ticket['ticket_prefix'],
        'ticket_number' => intval($ticket['ticket_number']),
        'ticket_subject' => (string) $ticket['ticket_subject'],
        'changed' => false,
    ];

    if ($source_project_id === $target_project_id) {
        if ($target_project_id) {
            $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_name,
                project_client_id, project_completed_at, project_archived_at FROM projects
                WHERE project_id = $target_project_id " . clientScopeSql('project_client_id') . " LIMIT 1",
                'Could not validate the selected ticket project'));
            if (!$project || intval($project['project_client_id']) !== $client_id
                || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
                throw new RuntimeException('The selected project is unavailable, complete, archived, or belongs to another client');
            }
            $result['project_name'] = (string) $project['project_name'];
        }
        return $result;
    }

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket project transaction');
        }
        $transaction_started = true;

        $project_ids = array_values(array_unique(array_filter([$source_project_id, $target_project_id])));
        sort($project_ids, SORT_NUMERIC);
        foreach ($project_ids as $project_id) {
            $project = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT project_name,
                project_client_id, project_completed_at, project_archived_at FROM projects
                WHERE project_id = $project_id " . clientScopeSql('project_client_id') . " FOR UPDATE",
                'Could not lock a ticket project'));
            if (!$project || intval($project['project_client_id']) !== $client_id
                || !empty($project['project_completed_at']) || !empty($project['project_archived_at'])) {
                throw new RuntimeException('A ticket project is unavailable, complete, archived, or belongs to another client');
            }
            if ($project_id === $target_project_id) {
                $result['project_name'] = (string) $project['project_name'];
            }
        }

        $locked_ticket = mysqli_fetch_assoc(ticketCreationDbQuery("SELECT ticket_client_id,
            ticket_project_id, ticket_updated_at FROM tickets WHERE ticket_id = $ticket_id FOR UPDATE",
            'Could not lock the ticket project assignment'));
        if (!$locked_ticket || intval($locked_ticket['ticket_client_id']) !== $client_id
            || intval($locked_ticket['ticket_project_id']) !== $source_project_id) {
            throw new RuntimeException('The ticket project changed while it was being assigned');
        }

        $execution_count = intval(mysqli_fetch_row(ticketCreationDbQuery("SELECT COUNT(*)
            FROM runbook_executions WHERE runbook_execution_ticket_id = $ticket_id",
            'Could not verify the ticket workflow project binding'))[0] ?? 0);
        if ($execution_count) {
            throw new RuntimeException('A versioned workflow ticket cannot change projects because its execution context is immutable');
        }

        $updated_at_assignment = '';
        if ($preserve_updated_at) {
            $updated_at_assignment = empty($locked_ticket['ticket_updated_at'])
                ? ', ticket_updated_at = NULL'
                : ", ticket_updated_at = '" . mysqli_real_escape_string($mysqli, $locked_ticket['ticket_updated_at']) . "'";
        }
        ticketCreationDbQuery("UPDATE tickets SET ticket_project_id = $target_project_id
            $updated_at_assignment
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
            AND ticket_project_id = $source_project_id", 'Could not assign the ticket project');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket project assignment was not changed');
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket project assignment');
        }
        $transaction_started = false;
        $result['changed'] = true;
        return $result;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log("Ticket $ticket_id project assignment rollback failed");
        }
        throw $exception;
    }
}

/**
 * Copies a recurring ticket's task list onto a ticket it has just raised.
 *
 * Recurring tickets own their tasks (see recurring_ticket_tasks) rather than
 * reading the linked ticket template at run time, so that a schedule's task
 * list can be edited without touching the template or any other schedule.
 *
 * @param int $ticket_id           The ticket to attach the tasks to.
 * @param int $recurring_ticket_id The schedule to copy tasks from. 0 = no-op.
 *
 * @return void
 */
function addTasksFromRecurringTicket($ticket_id, $recurring_ticket_id) {

    global $mysqli;

    $ticket_id = intval($ticket_id);
    $recurring_ticket_id = intval($recurring_ticket_id);

    if (!$ticket_id || !$recurring_ticket_id) {
        return;
    }

    mysqli_query($mysqli, "INSERT INTO tasks (task_name, task_order, task_completion_estimate, task_ticket_id)
        SELECT recurring_ticket_task_name, recurring_ticket_task_order, recurring_ticket_task_completion_estimate, $ticket_id
        FROM recurring_ticket_tasks
        WHERE recurring_ticket_task_recurring_ticket_id = $recurring_ticket_id
        ORDER BY recurring_ticket_task_order ASC");

}

/**
 * Reads the editable task rows posted by the ticket and recurring ticket modals.
 *
 * The rows submit as parallel tasks[] and task_estimates[] arrays, aligned by
 * their order in the form. Rows left blank are dropped, and the order is taken
 * from the surviving rows rather than the raw array index.
 *
 * Names come back already escaped for SQL, as every caller inserts them.
 *
 * @return array List of ['name' => string, 'order' => int, 'estimate' => int]
 */
function parseSubmittedTasks() {

    $tasks = [];

    if (empty($_POST['tasks']) || !is_array($_POST['tasks'])) {
        return $tasks;
    }

    $estimates = $_POST['task_estimates'] ?? [];
    $task_order = 0;

    foreach ($_POST['tasks'] as $index => $task_name) {
        $task_name = trim($task_name);

        if ($task_name === '') {
            continue;
        }

        $tasks[] = [
            'name' => escapeSql($task_name),
            'order' => $task_order,
            'estimate' => intval($estimates[$index] ?? 0)
        ];

        $task_order++;
    }

    return $tasks;
}

/*
 * Fetches one field from one row by id, and returns it RAW.
 *
 * Escaping is the caller's job, the same as any other value read out of the
 * database - wrap the call in escapeSql() for a query or escapeHtml() for
 * output. This function used to escape for you via an $escape_method argument,
 * which meant half its callers wrapped it in escapeSql() anyway and got a
 * double-escaped value: a client named O'Brien came back as O\'Brien and the
 * backslash ended up in export filenames, flash messages and, on the user
 * restore path, written back into the database.
 *
 * Table, field and id are still validated here - that is about building a safe
 * query, not about escaping what comes out of it.
 */
function getFieldById($table, $id, $field) {
    global $mysqli;  // Use the global MySQLi connection

    // Validate table and field names to allow only letters, numbers, and underscores
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
        return null; // Invalid table or field name
    }

    // Sanitize id as an integer
    $id = (int)$id;

    /*
     * Get the list of columns from the table, to find the primary key and to
     * confirm the requested field exists.
     *
     * The catch is what makes the "table not found" case actually return null:
     * mysqli throws on an unknown table by default on PHP 8.1+, so this
     * function's own not-found branch was unreachable and a bad table name
     * took the whole page down instead.
     */
    try {
        $columns_result = mysqli_query($mysqli, "SHOW COLUMNS FROM `$table`");
    } catch (mysqli_sql_exception $e) {
        return null; // Table not found
    }

    if (!$columns_result || mysqli_num_rows($columns_result) == 0) {
        return null; // Table not found or has no columns
    }

    $columns = [];
    $id_field = null;
    while ($row = mysqli_fetch_assoc($columns_result)) {
        $columns[$row['Field']] = true;
        if (!$id_field && $row['Key'] === 'PRI') {
            $id_field = $row['Field'];
        }
    }

    // Fallback: if no primary key is found, use the first column
    if (!$id_field) {
        reset($columns);
        $id_field = key($columns);
    }

    // Ensure the requested field exists; if not, default to the id field
    if (!array_key_exists($field, $columns)) {
        $field = $id_field;
    }

    // Build and execute the query to fetch the specified field value
    $sql = mysqli_query($mysqli, "SELECT `$field` FROM `$table` WHERE `$id_field` = $id");

    if ($sql && mysqli_num_rows($sql) > 0) {
        $row = mysqli_fetch_assoc($sql);
        return $row[$field];
    }

    return null; // Return null if no record was found
}


// Recursive function to display folder options - Used in folders files and documents
function displayFolderOptions($parent_folder_id, $client_id, $indent = 0) {
    global $mysqli;

    $sql_folders = mysqli_query($mysqli, "SELECT folder_id, folder_name FROM folders WHERE parent_folder = $parent_folder_id AND folder_client_id = $client_id ORDER BY folder_name ASC");
    while ($row = mysqli_fetch_assoc($sql_folders)) {
        $folder_id = intval($row['folder_id']);
        $folder_name = escapeHtml($row['folder_name']);

        // Indentation for subfolders
        $indentation = str_repeat('&nbsp;', $indent * 4);

        // Check if this folder is selected
        $selected = '';
        if ((isset($_GET['folder_id']) && intval($_GET['folder_id']) === $folder_id) ||
            (isset($_POST['folder']) && intval($_POST['folder']) === $folder_id)) {
            $selected = 'selected';
        }

        echo "<option value=\"$folder_id\" $selected>$indentation$folder_name</option>";

        // Recursively display subfolders
        displayFolderOptions($folder_id, $client_id, $indent + 1);
    }
}

/*
 * The branch this install tracks. Both setup paths write $repo_branch into config.php, but
 * an install older than that has no value at all, and every caller puts it into a shell
 * command - so it is defaulted and escaped here rather than trusted at each call site.
 */
function getRepoBranch(): string
{
    global $repo_branch;

    $branch = trim((string) ($repo_branch ?? ''));

    return $branch === '' ? 'master' : $branch;
}

function checkForUpdates() {

    $remote_ref = escapeshellarg("origin/" . getRepoBranch());

    // Fetch the latest code changes but don't apply them. stderr is merged in because git
    // reports failures there, and it is the only thing the update page can show when this
    // breaks - it used to run a second git fetch of its own just to get the message.
    exec("git fetch 2>&1", $output, $result);
    $latest_version = exec("git rev-parse $remote_ref");
    $current_version = exec("git rev-parse HEAD");

    if ($current_version == $latest_version) {
        $update_message = "No Updates available";
    } else {
        $update_message = "New Updates are Available [$latest_version]";
    }


    $updates = new stdClass();
    $updates->output = $output;
    $updates->result = $result;
    $updates->current_version = $current_version;
    $updates->latest_version = $latest_version;
    $updates->update_message = $update_message;


    return $updates;

}

function getMonthlyTax($tax_name, $month, $year, $mysqli) {
    // SQL to calculate monthly tax
    $sql = "SELECT SUM(item_tax) AS monthly_tax FROM invoice_items
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) = $month
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['monthly_tax'] ?? 0;
}

function getQuarterlyTax($tax_name, $quarter, $year, $mysqli) {
    // Calculate start and end months for the quarter
    $start_month = ($quarter - 1) * 3 + 1;
    $end_month = $start_month + 2;

    // SQL to calculate quarterly tax
    $sql = "SELECT SUM(item_tax) AS quarterly_tax FROM invoice_items
            LEFT JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
            LEFT JOIN payments ON invoices.invoice_id = payments.payment_invoice_id
            WHERE YEAR(payments.payment_date) = $year AND MONTH(payments.payment_date) BETWEEN $start_month AND $end_month
            AND invoice_items.item_tax_id = (SELECT tax_id FROM taxes WHERE tax_name = '$tax_name')";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['quarterly_tax'] ?? 0;
}

function addToMailQueue($data) {

    global $mysqli;

    foreach ($data as $email) {
        $from = strval($email['from']);
        $from_name = strval($email['from_name']);
        $recipient = strval($email['recipient']);
        $recipient_name = strval($email['recipient_name']);
        $subject = strval($email['subject']);
        $body = strval($email['body']);
        $body_plain = strval($email['body_plain'] ?? '');
        $template_key = strval($email['template_key'] ?? 'legacy');

        $cal_str = '';
        if (isset($email['cal_str'])) {
            $cal_str = mysqli_escape_string($mysqli, $email['cal_str']);
        }

        // Attachments travel as a manifest of app-root-relative paths rather than
        // file contents, so the queue table stays small. cron/mail_queue.php
        // re-checks each path is inside uploads/ before attaching it.
        $attachments = '';
        if (!empty($email['attachments']) && is_array($email['attachments'])) {
            $attachment_manifest = [];
            foreach ($email['attachments'] as $attachment) {
                if (empty($attachment['path']) || empty($attachment['name'])) {
                    continue;
                }
                $attachment_manifest[] = [
                    'path' => strval($attachment['path']),
                    'name' => strval($attachment['name'])
                ];
            }
            if ($attachment_manifest) {
                $attachments = mysqli_escape_string($mysqli, json_encode($attachment_manifest));
            }
        }

        // Check if 'email_queued_at' is set and not empty
        if (isset($email['queued_at']) && !empty($email['queued_at'])) {
            $queued_at = "'" . escapeSql($email['queued_at']) . "'";
        } else {
            // Use the current date and time if 'email_queued_at' is not set or empty
            $queued_at = 'CURRENT_TIMESTAMP()';
        }

        mysqli_query($mysqli, "INSERT INTO email_queue SET email_recipient = '$recipient', email_recipient_name = '$recipient_name', email_from = '$from', email_from_name = '$from_name', email_subject = '$subject', email_content = '$body', email_content_plain = '$body_plain', email_template_key = '$template_key', email_queued_at = $queued_at, email_cal_str = '$cal_str', email_attachments = '$attachments'");
    }

    return true;
}

function getTicketCalendarUid($ticket_id) {
    // An invite and its later cancellation MUST carry the same UID or the
    // recipient's calendar client cannot match them up. Derive it from the
    // ticket so it is stable across both, rather than from the current time.
    $ticket_id = intval($ticket_id);
    $host = $_SERVER['SERVER_NAME'] ?? 'itflow';
    return "ticket-$ticket_id@$host";
}

function createiCalStr($datetime, $title, $description, $location, $uid = null) {
    require_once "../libs/zapcal/zapcallib.php";

    // Create the iCal object
    $cal_event = new ZCiCal();
    $event = new ZCiCalNode("VEVENT", $cal_event->curnode);


    // Set the method to REQUEST to indicate an invite
    $event->addNode(new ZCiCalDataNode("METHOD:REQUEST"));
    $event->addNode(new ZCiCalDataNode("SUMMARY:" . $title));
    $event->addNode(new ZCiCalDataNode("DTSTART:" . ZCiCal::fromSqlDateTime($datetime)));
    // Assuming the end time is the same as start time.
    // Todo: adjust this for actual duration
    $event->addNode(new ZCiCalDataNode("DTEND:" . ZCiCal::fromSqlDateTime($datetime)));
    $event->addNode(new ZCiCalDataNode("DTSTAMP:" . ZCiCal::fromSqlDateTime()));
    if (empty($uid)) {
        $uid = date('Y-m-d-H-i-s') . "@" . ($_SERVER['SERVER_NAME'] ?? 'itflow');
    }
    $event->addNode(new ZCiCalDataNode("UID:" . $uid));
    $event->addNode(new ZCiCalDataNode("SEQUENCE:0"));
    $event->addNode(new ZCiCalDataNode("LOCATION:" . $location));
    $event->addNode(new ZCiCalDataNode("DESCRIPTION:" . $description));
    // Todo: add organizer details
    // $event->addNode(new ZCiCalDataNode("ORGANIZER;CN=Organizer Name:MAILTO:organizer@example.com"));

    // Return the iCal string
    return $cal_event->export();
}

function createiCalStrCancel($datetime, $title, $uid) {
    require_once "../libs/zapcal/zapcallib.php";

    // Build the cancellation fresh. There is no stored copy of the original
    // invite to reopen - the match is made by UID, not by the body.
    $cal_event = new ZCiCal();

    // METHOD belongs on the VCALENDAR, not on the VEVENT
    $cal_event->tree->data['METHOD'] = new ZCiCalDataNode("METHOD:CANCEL");

    $event = new ZCiCalNode("VEVENT", $cal_event->curnode);
    $event->addNode(new ZCiCalDataNode("UID:" . $uid));
    $event->addNode(new ZCiCalDataNode("SUMMARY:" . $title));
    if (!empty($datetime)) {
        $event->addNode(new ZCiCalDataNode("DTSTART:" . ZCiCal::fromSqlDateTime($datetime)));
        $event->addNode(new ZCiCalDataNode("DTEND:" . ZCiCal::fromSqlDateTime($datetime)));
    }
    $event->addNode(new ZCiCalDataNode("DTSTAMP:" . ZCiCal::fromSqlDateTime()));
    // Must outrank the invite's SEQUENCE:0 or clients ignore the cancellation
    $event->addNode(new ZCiCalDataNode("SEQUENCE:1"));
    $event->addNode(new ZCiCalDataNode("STATUS:CANCELLED"));

    return $cal_event->export();
}
