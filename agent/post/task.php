<?php

/*
 * ITFlow - GET/POST request handler for tasks
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_task'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $task_name = escapeSql($_POST['name']);

    // Get Client ID from tickets using the ticket_id
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for adding a task.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        runbookDbQuery("INSERT INTO tasks SET task_name = '$task_name', task_ticket_id = $ticket_id", 'Could not add the ticket task');
        $task_id = intval(mysqli_insert_id($mysqli));
        if (!$task_id) {
            throw new RuntimeException('The new task did not receive an ID');
        }
        runbookRecordTaskStateEvent($task_id, null, 'Ready', 'Task created', $session_user_id, 'agent');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the new task');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('Tasks cannot be added to a resolved or closed ticket.', 'error');
        redirect();
    }

    logAudit("Task", "Create", "$session_name created task $task_name", $client_id, $task_id);

    flashAlert("You created Task <strong>$task_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_task'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $existing_task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_order,
        task_completion_estimate, task_runbook_version_task_id, task_state,
        task_assigned_to, task_due_at, ticket_client_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id LIMIT 1"));
    if (!$existing_task) {
        flashAlert('Task not found', 'error');
        redirect();
    }
    $client_id = intval($existing_task['ticket_client_id']);
    enforceClientAccess();

    $published_runbook_task = intval($existing_task['task_runbook_version_task_id']) > 0;
    if ($published_runbook_task && in_array($existing_task['task_state'], ['Completed', 'Skipped'], true)) {
        flashAlert('Completed and skipped runbook tasks are immutable execution records.', 'error');
        redirect();
    }
    if ($published_runbook_task) {
        // Published execution content is an immutable copy of its runbook
        // version. Only operational assignment and due date may change.
        $task_name = escapeSql($existing_task['task_name']);
        $task_order = intval($existing_task['task_order']);
        $task_completion_estimate = intval($existing_task['task_completion_estimate']);
    } else {
        $task_name = escapeSql($_POST['name'] ?? $existing_task['task_name']);
        $task_order = isset($_POST['order'])
            ? intval($_POST['order'])
            : intval($existing_task['task_order']);
        $task_completion_estimate = max(0, intval($_POST['completion_estimate'] ?? 0));
    }
    $task_assigned_to = max(0, intval($_POST['assigned_to'] ?? getFieldById('tasks', $task_id, 'task_assigned_to')));
    if ($task_assigned_to) {
        $valid_assignee = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
            WHERE user_id = $task_assigned_to AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"))[0] ?? 0);
        if (!$valid_assignee) {
            $task_assigned_to = 0;
        }
    }
    $task_due_at = 'NULL';
    if (!empty($_POST['due_at'])) {
        $due = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['due_at']);
        if ($due) {
            $task_due_at = "'" . escapeSql($due->format('Y-m-d H:i:s')) . "'";
        }
    }

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for editing the task.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicketForTask($task_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_state,
            task_runbook_version_task_id FROM tasks WHERE task_id = $task_id FOR UPDATE", 'Could not lock the task for editing'));
        if (!$locked_task) {
            throw new RuntimeException('The task no longer exists');
        }
        if (intval($locked_task['task_runbook_version_task_id']) > 0
            && in_array($locked_task['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('Terminal runbook tasks are immutable');
        }
        runbookDbQuery("UPDATE tasks SET task_name = '$task_name', task_order = $task_order,
            task_completion_estimate = $task_completion_estimate, task_assigned_to = $task_assigned_to,
            task_due_at = $task_due_at WHERE task_id = $task_id", 'Could not edit the ticket task');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the task edit');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('Tasks on resolved or closed tickets cannot be edited.', 'error');
        redirect();
    }

    logAudit("Task", "Edit", "$session_name edited task $task_name", $client_id, $task_id);

    flashAlert("Task <strong>$task_name</strong> edited");

    redirect();

}

if (isset($_GET['delete_task'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 3);

    $task_id = intval($_GET['delete_task']);

    // Get Client ID, task name from tasks and tickets using the task_id
    $sql = mysqli_query($mysqli, "SELECT task_name, task_runbook_version_task_id, ticket_client_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id WHERE task_id = $task_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['ticket_client_id']);
    enforceClientAccess();
    $task_name = escapeSql($row['task_name']);

    if (intval($row['task_runbook_version_task_id']) > 0) {
        flashAlert('Published runbook tasks cannot be deleted. Skip the task with an audit reason instead.', 'error');
        redirect();
    }

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for deleting the task.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicketForTask($task_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        runbookDbQuery("DELETE FROM task_dependencies WHERE task_id = $task_id OR depends_on_task_id = $task_id", 'Could not delete task dependencies');
        runbookDbQuery("DELETE FROM task_evidence WHERE task_evidence_task_id = $task_id", 'Could not delete legacy task evidence');
        runbookDbQuery("DELETE FROM task_approvals WHERE approval_task_id = $task_id", 'Could not delete legacy task approvals');
        runbookDbQuery("DELETE FROM task_state_events WHERE task_state_event_task_id = $task_id", 'Could not delete legacy task state events');
        runbookDbQuery("DELETE FROM tasks WHERE task_id = $task_id", 'Could not delete the legacy task');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit task deletion');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('Tasks on resolved or closed tickets cannot be deleted.', 'error');
        redirect();
    }

    logAudit("Task", "Delete", "$session_name deleted task $task_name", $client_id, $task_id);

    flashAlert("Task <strong>$task_name</strong> deleted", 'error');

    redirect();

}

if (isset($_GET['complete_task'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $task_id = intval($_GET['complete_task']);

    // Get Client ID
    $sql = mysqli_query($mysqli, "SELECT task_name, task_state, task_completed_at, ticket_client_id, ticket_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id WHERE task_id = $task_id");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('Task not found', 'error');
        redirect();
    }
    $client_id = intval($row['ticket_client_id']);
    enforceClientAccess();
    $task_name = escapeSql($row['task_name']);
    $ticket_id = intval($row['ticket_id']);

    [$can_complete, $completion_error] = runbookTaskCanComplete($task_id);
    if (!$can_complete) {
        flashAlert(escapeHtml($completion_error), 'error');
        redirect();
    }

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The task could not be locked for completion. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        [$can_complete, $completion_error] = runbookTaskCanComplete($task_id);
        if (!$can_complete) {
            throw new RuntimeException($completion_error);
        }
        runbookDbQuery("UPDATE tasks SET task_state = 'Completed', task_completed_at = NOW(),
            task_completed_by = $session_user_id WHERE task_id = $task_id
            AND task_state = 'Ready' AND task_completed_at IS NULL", 'Could not complete the task');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The task state changed before it could be completed');
        }
        runbookRecordTaskStateEvent($task_id, 'Ready', 'Completed', 'Task completed', $session_user_id, 'agent');
        refreshRunbookTaskStates($ticket_id);

        // Audit trail only - task_completion_estimate is planning information, not labour.
        // Booking it as time worked double-counted against whatever the tech actually logged.
        runbookDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Completed Task - $task_name',
            ticket_reply_time_worked = '00:00:00', ticket_reply_type = 'Internal',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record task completion on the ticket');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit task completion');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The task state changed before it could be completed. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("Task", "Edit", "$session_name completed task $task_name", $client_id, $task_id);

    flashAlert("Task <strong>$task_name</strong> Completed");

    redirect();

}

if (isset($_GET['undo_complete_task'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $task_id = intval($_GET['undo_complete_task']);

    // Get Client ID
    $sql = mysqli_query($mysqli, "SELECT task_name, ticket_client_id, ticket_id FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id WHERE task_id = $task_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['ticket_client_id']);
    enforceClientAccess();
    $task_name = escapeSql($row['task_name']);
    $ticket_id = intval($row['ticket_id']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The task could not be locked for reopening. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $reopened_count = reopenRunbookTaskAndDependents($task_id,
            $session_user_id,
            'Task reopened; completed dependent tasks reopened for consistency'
        );
        if ($reopened_count < 1) {
            throw new RuntimeException('The task was not completed or was already reopened');
        }

        // Audit trail only - see complete_task
        runbookDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Undo Completed Task - $task_name',
            ticket_reply_time_worked = '00:00:00', ticket_reply_type = 'Internal',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record the reopened task on the ticket');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the reopened task chain');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The task was already reopened or could not be changed. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("Task", "Edit", "$session_name marked task $task_name as incomplete", $client_id, $task_id);

    flashAlert("Task <strong>$task_name</strong> and <strong>" . max(0, $reopened_count - 1) . "</strong> completed dependent task(s) marked incomplete", 'error');

    redirect();

}

if (isset($_POST['set_task_waiting'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $reason = trim($_POST['waiting_reason'] ?? '');
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_state, task_ticket_id, ticket_client_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id AND task_runbook_version_task_id > 0 LIMIT 1"));
    $client_id = intval($task['ticket_client_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess();
    }
    if (!$task || $reason === '') {
        flashAlert('A waiting reason is required', 'error');
        redirect();
    }
    if ($task['task_state'] !== 'Ready') {
        flashAlert('Only a ready runbook task can be placed in a waiting state', 'error');
        redirect();
    }

    $reason_sql = escapeSql($reason);
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The task could not be locked for pausing. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicketForTask($task_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        runbookDbQuery("UPDATE tasks SET task_state = 'Waiting', task_waiting_reason = '$reason_sql',
            task_completed_at = NULL, task_completed_by = NULL
            WHERE task_id = $task_id AND task_state = 'Ready'", 'Could not pause the runbook task');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The task state changed before it could be paused');
        }
        runbookRecordTaskStateEvent($task_id, 'Ready', 'Waiting', $reason, $session_user_id, 'agent');
        refreshRunbookTaskStates(intval($task['task_ticket_id']));
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the waiting task state');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The task state changed before it could be paused. Refresh and try again.', 'error');
        redirect();
    }
    logAudit('Task', 'Edit', "$session_name marked task waiting: $reason_sql", $client_id, $task_id);
    flashAlert('Task is now waiting');
    redirect();
}

if (isset($_POST['resume_task'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_id, task_state, task_assigned_to,
        task_ticket_id, ticket_id, ticket_client_id, ticket_contact_id, ticket_assigned_to,
        ticket_project_id, ticket_created_at, runbook_version_task_condition_type,
        runbook_version_task_condition_value, runbook_version_task_owner_type,
        runbook_version_task_owner_user_id
        FROM tasks
        INNER JOIN tickets ON ticket_id = task_ticket_id
        INNER JOIN runbook_version_tasks ON runbook_version_task_id = task_runbook_version_task_id
        WHERE task_id = $task_id AND task_runbook_version_task_id > 0 LIMIT 1"));
    if (!$task || $task['task_state'] !== 'Waiting') {
        flashAlert('Only a waiting runbook task can be resumed', 'error');
        redirect();
    }
    $client_id = intval($task['ticket_client_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess();
    }
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The task could not be locked for resuming. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicketForTask($task_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_state,
            task_assigned_to, task_ticket_id, task_runbook_version_task_id,
            runbook_version_task_condition_type, runbook_version_task_condition_value,
            runbook_version_task_owner_type, runbook_version_task_owner_user_id
            FROM tasks
            INNER JOIN runbook_version_tasks
                ON runbook_version_task_id = task_runbook_version_task_id
            WHERE task_id = $task_id AND task_runbook_version_task_id > 0
            LIMIT 1 FOR UPDATE", 'Could not reload the waiting runbook task'));
        if (!$locked_task || $locked_task['task_state'] !== 'Waiting'
            || intval($locked_task['task_ticket_id']) !== intval($locked_ticket['ticket_id'])) {
            throw new RuntimeException('The task is no longer a waiting runbook task');
        }

        $condition_type = (string) $locked_task['runbook_version_task_condition_type'];
        $condition_result = $condition_type === 'manual_confirm'
            ? 'Matched'
            : runbookEvaluateCondition(
                $condition_type,
                $locked_task['runbook_version_task_condition_value'],
                intval($locked_ticket['ticket_client_id'])
            );
        if ($condition_result !== 'Matched') {
            throw new RuntimeException('The published runbook condition is no longer satisfied');
        }

        $assigned_to = intval($locked_task['task_assigned_to']);
        if ($assigned_to) {
            $active_assignee = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
                WHERE user_id = $assigned_to AND user_type = 1 AND user_status = 1
                AND user_archived_at IS NULL"))[0] ?? 0);
            if (!$active_assignee) {
                $assigned_to = 0;
            }
        }
        $owner_required = $locked_task['runbook_version_task_owner_type'] !== 'unassigned';
        if (!$assigned_to && $owner_required) {
            $assigned_to = runbookResolveOwner(
                $locked_task['runbook_version_task_owner_type'],
                $locked_task['runbook_version_task_owner_user_id'],
                $locked_ticket
            );
        }
        if ($owner_required && !$assigned_to) {
            throw new RuntimeException('The task no longer has an active owner');
        }

        $locked_approvals = runbookDbQuery("SELECT approval_scope, approval_type,
            approval_required_user_id, approval_created_by
            FROM task_approvals WHERE approval_task_id = $task_id
            AND approval_status IN ('pending','declined') FOR UPDATE",
            'Could not reload approval routes for the waiting task');
        while ($locked_approval = mysqli_fetch_assoc($locked_approvals)) {
            [$route_available, $route_error] = runbookApprovalRouteAvailability(
                $locked_approval['approval_scope'],
                $locked_approval['approval_type'],
                intval($locked_approval['approval_required_user_id']),
                $locked_ticket,
                intval($locked_approval['approval_created_by'])
            );
            if (!$route_available) {
                throw new RuntimeException($route_error);
            }
        }

        runbookDbQuery("UPDATE tasks SET task_state = 'Ready', task_waiting_reason = NULL,
            task_condition_result = 'Matched', task_assigned_to = $assigned_to
            WHERE task_id = $task_id AND task_state = 'Waiting'", 'Could not resume the runbook task');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The task state changed before it could be resumed');
        }
        runbookRecordTaskStateEvent($task_id, 'Waiting', 'Ready', 'Waiting condition cleared', $session_user_id, 'agent');
        refreshRunbookTaskStates(intval($locked_ticket['ticket_id']));
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the resumed task state');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The task state changed before it could be resumed. Refresh and try again.', 'error');
        redirect();
    }
    logAudit('Task', 'Edit', "$session_name resumed a waiting task", $client_id, $task_id);
    flashAlert('Task resumed');
    redirect();
}

if (isset($_POST['skip_runbook_task'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $task_id = intval($_POST['task_id']);
    $reason = trim($_POST['skip_reason'] ?? '');
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_state, task_ticket_id, ticket_client_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id AND task_runbook_version_task_id > 0 LIMIT 1"));
    $client_id = intval($task['ticket_client_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess();
    }
    if (!$task || $reason === '') {
        flashAlert('A skip reason is required', 'error');
        redirect();
    }
    if (in_array($task['task_state'], ['Completed', 'Skipped'], true)) {
        flashAlert('Completed and already-skipped tasks cannot be converted to skipped.', 'error');
        redirect();
    }

    $reason_sql = escapeSql($reason);
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The task could not be locked for skipping. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicketForTask($task_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_state,
            task_ticket_id, task_runbook_version_task_id FROM tasks
            WHERE task_id = $task_id FOR UPDATE", 'Could not lock the task for skipping'));
        if (!$locked_task || intval($locked_task['task_runbook_version_task_id']) < 1
            || intval($locked_task['task_ticket_id']) !== intval($locked_ticket['ticket_id'])
            || in_array($locked_task['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The task no longer accepts a skip transition');
        }
        $approval_rows = [];
        $locked_approvals = runbookDbQuery("SELECT approval_id, approval_scope,
            approval_type, approval_required_user_id, approval_status
            FROM task_approvals WHERE approval_task_id = $task_id FOR UPDATE",
            'Could not lock approval bearers for the skipped task');
        while ($locked_approval = mysqli_fetch_assoc($locked_approvals)) {
            $approval_rows[] = $locked_approval;
        }
        $from_state = (string) $locked_task['task_state'];
        $from_state_sql = escapeSql($from_state);
        runbookDbQuery("UPDATE tasks SET task_state = 'Skipped', task_waiting_reason = '$reason_sql',
            task_condition_result = 'Skipped', task_completed_at = NOW(), task_completed_by = $session_user_id
            WHERE task_id = $task_id AND task_state = '$from_state_sql'", 'Could not skip the runbook task');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The task state changed before it could be skipped');
        }
        runbookRecordTaskStateEvent($task_id, $from_state, 'Skipped', $reason, $session_user_id, 'agent');
        runbookDbQuery("UPDATE task_approvals SET approval_url_key = '',
            approval_url_expires_at = NULL WHERE approval_task_id = $task_id",
            'Could not retire approval bearers for the skipped task');
        foreach ($approval_rows as $approval_row) {
            if ($approval_row['approval_status'] === 'approved') {
                continue;
            }
            runbookRecordApprovalEvent(
                intval($approval_row['approval_id']),
                $task_id,
                'waived',
                [
                    'status' => $approval_row['approval_status'],
                    'scope' => $approval_row['approval_scope'],
                    'type' => $approval_row['approval_type'],
                    'required_user_id' => intval($approval_row['approval_required_user_id']),
                ],
                [
                    'status' => 'waived',
                    'scope' => $approval_row['approval_scope'],
                    'type' => $approval_row['approval_type'],
                    'required_user_id' => intval($approval_row['approval_required_user_id']),
                ],
                'agent',
                $session_user_id,
                $session_name,
                $reason
            );
        }
        runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
            task_evidence_type = 'note', task_evidence_note = 'Skipped: $reason_sql',
            task_evidence_submitted_by = $session_user_id", 'Could not record the task skip reason');
        refreshRunbookTaskStates(intval($locked_ticket['ticket_id']));
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the skipped task record');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The task could not be skipped because its state changed. Refresh and try again.', 'error');
        redirect();
    }
    logAudit('Task', 'Edit', "$session_name skipped runbook task: $reason_sql", $client_id, $task_id);
    flashAlert('Runbook task skipped with an audit reason', 'error');
    redirect();
}

if (isset($_POST['add_task_evidence'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_ticket_id, ticket_client_id
        FROM tasks LEFT JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id LIMIT 1"));
    $ticket_id = intval($task['task_ticket_id'] ?? 0);
    $client_id = intval($task['ticket_client_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess();
    }
    if (!$task || !$ticket_id) {
        flashAlert('Task not found', 'error');
        redirect();
    }

    $stored_files = [];
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for adding evidence.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_id, task_ticket_id
            FROM tasks WHERE task_id = $task_id AND task_ticket_id = $ticket_id
            LIMIT 1 FOR UPDATE", 'Could not lock the task for evidence'));
        if (!$locked_task) {
            throw new RuntimeException('The task no longer belongs to this ticket');
        }

        $evidence_added = 0;
        $note = trim($_POST['evidence_note'] ?? '');
        if ($note !== '') {
            $note_sql = escapeSql($note);
            runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
                task_evidence_type = 'note', task_evidence_note = '$note_sql',
                task_evidence_submitted_by = $session_user_id", 'Could not add task evidence note');
            $evidence_added++;
        }

        $url = trim($_POST['evidence_url'] ?? '');
        $url_scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) && in_array($url_scheme, ['http', 'https'], true)) {
            $url_sql = escapeSql($url);
            runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
                task_evidence_type = 'url', task_evidence_url = '$url_sql',
                task_evidence_submitted_by = $session_user_id", 'Could not add task evidence URL');
            $evidence_added++;
        }

        $stored_files = saveTicketAttachments($ticket_id, null, 'evidence_files');
        foreach ($stored_files as $stored_file) {
            $attachment_id = intval($stored_file['attachment_id'] ?? 0);
            if (!$attachment_id) {
                throw new RuntimeException('A saved evidence file has no durable attachment record');
            }
            runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
                task_evidence_type = 'file', task_evidence_attachment_id = $attachment_id,
                task_evidence_submitted_by = $session_user_id", 'Could not add task evidence file');
            $evidence_added++;
        }

        if (!$evidence_added) {
            throw new InvalidArgumentException('Add a note, a valid URL, or an accepted file');
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit task evidence');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        cleanupStoredTicketAttachmentFiles($stored_files);
        flashAlert(escapeHtml($exception->getMessage()), 'error');
        redirect();
    }

    logAudit('Task', 'Edit', "$session_name added $evidence_added evidence item(s)", $client_id, $task_id);
    flashAlert("Added <strong>$evidence_added</strong> evidence item(s)");
    redirect();
}

if (isset($_POST['delete_task_evidence'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $evidence_id = intval($_POST['task_evidence_id']);
    $evidence = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_evidence_task_id,
        task_completed_at, task_runbook_version_task_id, ticket_client_id
        FROM task_evidence INNER JOIN tasks ON task_id = task_evidence_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_evidence_id = $evidence_id LIMIT 1"));
    $client_id = intval($evidence['ticket_client_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess();
    }
    if (intval($evidence['task_runbook_version_task_id'] ?? 0) > 0) {
        flashAlert('Published runbook evidence is append-only. Add a correction as new evidence instead.', 'error');
        redirect();
    }
    if (!empty($evidence['task_completed_at'])) {
        flashAlert('Evidence on a completed task is part of the task record and cannot be removed.', 'error');
        redirect();
    }
    if ($evidence) {
        if (!mysqli_begin_transaction($mysqli)) {
            flashAlert('The ticket could not be locked for removing evidence.', 'error');
            redirect();
        }
        try {
            $locked_ticket = runbookLockOpenTicketForTask(intval($evidence['task_evidence_task_id']));
            runbookRequireLockedTicketClient($locked_ticket, $client_id);
            $locked_evidence = mysqli_fetch_assoc(runbookDbQuery("SELECT task_evidence_id,
                task_completed_at, task_runbook_version_task_id
                FROM task_evidence INNER JOIN tasks ON task_id = task_evidence_task_id
                WHERE task_evidence_id = $evidence_id LIMIT 1 FOR UPDATE", 'Could not lock the task evidence'));
            if (!$locked_evidence || !empty($locked_evidence['task_completed_at'])
                || intval($locked_evidence['task_runbook_version_task_id']) > 0) {
                throw new RuntimeException('The evidence is immutable');
            }
            runbookDbQuery("DELETE FROM task_evidence WHERE task_evidence_id = $evidence_id", 'Could not remove the task evidence reference');
            if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
                throw new RuntimeException('The evidence reference changed before it could be removed');
            }
        } catch (Throwable $exception) {
            mysqli_rollback($mysqli);
            flashAlert('Evidence cannot be removed from a resolved or closed ticket.', 'error');
            redirect();
        }
        logAudit('Task', 'Delete', "$session_name removed a task evidence reference", $client_id, intval($evidence['task_evidence_task_id']));
    }
    flashAlert('Evidence reference removed; any ticket attachment was retained', 'error');
    redirect();
}

if (isset($_POST['add_ticket_task_approver'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $scope = $_POST['approval_scope'] ?? '';
    $type = $_POST['approval_type'] ?? '';
    if (!in_array($scope, ['internal', 'client'], true)
        || !in_array($type, ['any', 'technical', 'billing', 'specific'], true)
        || ($scope === 'client' && $type === 'specific')
        || ($scope === 'internal' && in_array($type, ['technical', 'billing'], true))) {
        flashAlert('Invalid approval rule', 'error');
        redirect();
    }
    $required_user_id = $type === 'specific' ? intval($_POST['approval_required_user_id'] ?? 0) : 0;
    $tt_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
        SELECT task_name, task_state, task_runbook_version_task_id, task_ticket_id,
            ticket_id, ticket_client_id, ticket_contact_id, ticket_number,
            ticket_prefix, ticket_subject, ticket_assigned_to, ticket_project_id,
            ticket_created_at FROM tasks
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id LIMIT 1
        ")
    );
    if (!$tt_row) {
        flashAlert('Task not found', 'error');
        redirect();
    }
    if (intval($tt_row['task_runbook_version_task_id']) > 0) {
        flashAlert('Published runbook approval gates come only from the pinned version and cannot be changed at runtime.', 'error');
        redirect();
    }
    if (in_array($tt_row['task_state'], ['Completed', 'Skipped'], true)) {
        flashAlert('Approvals cannot be added to a completed or skipped task.', 'error');
        redirect();
    }

    $task_name = (string) $tt_row['task_name'];
    $ticket_id = intval($tt_row['task_ticket_id']);
    $client_id = intval($tt_row['ticket_client_id']);
    enforceClientAccess();

    [$route_available, $route_error] = runbookApprovalRouteAvailability(
        $scope,
        $type,
        $required_user_id,
        $tt_row,
        $session_user_id
    );
    if (!$route_available) {
        flashAlert(escapeHtml($route_error), 'error');
        redirect();
    }

    $approval_url_key_raw = randomString(32);
    $approval_url_key = escapeSql(runbookApprovalTokenHash($approval_url_key_raw));
    $approval_url_expires_at_value = runbookApprovalTokenExpiry();
    $approval_url_expires_at = escapeSql($approval_url_expires_at_value);
    $scope_sql = escapeSql($scope);
    $type_sql = escapeSql($type);
    $required_user_sql = $required_user_id ? (string) $required_user_id : 'NULL';

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for adding the approval.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_task = mysqli_fetch_assoc(runbookDbQuery("SELECT task_name, task_state,
            task_ticket_id, task_runbook_version_task_id FROM tasks
            WHERE task_id = $task_id FOR UPDATE", 'Could not lock the approval task'));
        if (!$locked_task || intval($locked_task['task_runbook_version_task_id']) > 0
            || intval($locked_task['task_ticket_id']) !== $ticket_id
            || in_array($locked_task['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The task no longer accepts manual approvals');
        }
        $task_name = (string) $locked_task['task_name'];
        [$route_available, $route_error] = runbookApprovalRouteAvailability(
            $scope,
            $type,
            $required_user_id,
            $locked_ticket,
            $session_user_id
        );
        if (!$route_available) {
            throw new RuntimeException($route_error);
        }
        runbookDbQuery("INSERT INTO task_approvals SET approval_scope = '$scope_sql',
            approval_type = '$type_sql', approval_required_user_id = $required_user_sql,
            approval_status = 'pending', approval_created_by = $session_user_id,
            approval_url_key = '$approval_url_key', approval_url_expires_at = '$approval_url_expires_at',
            approval_task_id = $task_id", 'Could not add the task approval');
        $approval_id = intval(mysqli_insert_id($mysqli));
        if (!$approval_id) {
            throw new RuntimeException('The approval did not receive an ID');
        }
        runbookRecordApprovalEvent(
            $approval_id,
            $task_id,
            'created',
            [],
            [
                'status' => 'pending',
                'scope' => $scope,
                'type' => $type,
                'required_user_id' => $required_user_id,
            ],
            'agent',
            $session_user_id,
            $session_name,
            'Manual approval request created',
            $approval_url_expires_at_value
        );
        $notification_task = [
            'runbook_version_task_name' => $task_name,
            'runbook_version_task_approval_scope' => $scope,
            'runbook_version_task_approval_type' => $type,
            'runbook_version_task_approval_user_id' => $required_user_id,
        ];
        runbookQueueApprovalNotification(
            $approval_id,
            $locked_ticket,
            $notification_task,
            $approval_url_key_raw,
            $session_user_id
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the task approval');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The approval could not be added. The ticket may be resolved or the route unavailable.', 'error');
        redirect();
    }

    $task_name_sql = escapeSql($task_name);
    logAudit('Task', 'Edit', "$session_name added task approver for $task_name_sql", $client_id, $task_id);
    flashAlert('Added approver');
    redirect();

}

if (isset($_GET['approve_ticket_task'])) {
    flashAlert('Approval decisions must be submitted from the ticket using the protected form.', 'error');
    redirect();
}

if (isset($_POST['decide_ticket_task_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 2);

    $task_id = intval($_POST['task_id']);
    $approval_id = intval($_POST['approval_id']);
    $decision = $_POST['decision'] ?? '';
    if (!in_array($decision, ['approved', 'declined'], true)) {
        flashAlert('Invalid approval decision', 'error');
        redirect();
    }

    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_created_by,
        approval_required_user_id, approval_type, task_name, task_ticket_id, ticket_client_id
        FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE approval_id = $approval_id AND approval_task_id = $task_id
        AND approval_scope = 'internal' AND approval_status = 'pending'
        AND task_state NOT IN ('Completed','Skipped') LIMIT 1"));
    if (!$approval) {
        flashAlert('Approval request not found', 'error');
        redirect();
    }
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess();
    }
    $required_user = intval($approval['approval_required_user_id']);
    if ($required_user && $required_user !== $session_user_id) {
        flashAlert('You cannot decide that approval', 'error');
        redirect();
    }
    if (intval($approval['approval_created_by']) === $session_user_id) {
        flashAlert('You cannot approve or decline your own request', 'error');
        redirect();
    }

    $decision_sql = escapeSql($decision);
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for the approval decision.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket(intval($approval['task_ticket_id']));
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT approval_created_by,
            approval_required_user_id, approval_type, approval_scope, approval_status,
            task_state FROM task_approvals
            INNER JOIN tasks ON task_id = approval_task_id
            WHERE approval_id = $approval_id AND approval_task_id = $task_id
            LIMIT 1 FOR UPDATE", 'Could not lock the task approval'));
        if (!$locked_approval || $locked_approval['approval_scope'] !== 'internal'
            || $locked_approval['approval_status'] !== 'pending'
            || in_array($locked_approval['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The approval is no longer actionable');
        }
        $locked_required_user = intval($locked_approval['approval_required_user_id']);
        if ($locked_required_user && $locked_required_user !== $session_user_id) {
            throw new RuntimeException('The approval is assigned to another user');
        }
        if (intval($locked_approval['approval_created_by']) === $session_user_id) {
            throw new RuntimeException('The requester cannot decide this approval');
        }
        runbookDbQuery("UPDATE task_approvals SET approval_status = '$decision_sql',
            approval_approved_by = $session_user_id, approval_decided_at = NOW(),
            approval_url_key = '', approval_url_expires_at = NULL
            WHERE approval_id = $approval_id AND approval_task_id = $task_id
            AND approval_status = 'pending' AND approval_scope = 'internal'
            AND EXISTS (SELECT 1 FROM tasks WHERE task_id = $task_id
                AND task_state NOT IN ('Completed','Skipped'))", 'Could not decide the task approval');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The approval was already decided');
        }
        runbookRecordApprovalEvent(
            $approval_id,
            $task_id,
            $decision,
            [
                'status' => 'pending',
                'scope' => $locked_approval['approval_scope'],
                'type' => $locked_approval['approval_type'],
                'required_user_id' => $locked_required_user,
            ],
            [
                'status' => $decision,
                'scope' => $locked_approval['approval_scope'],
                'type' => $locked_approval['approval_type'],
                'required_user_id' => $locked_required_user,
            ],
            'agent',
            $session_user_id,
            $session_name
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the approval decision');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The approval was already decided. Refresh the ticket.', 'error');
        redirect();
    }
    $task_name = escapeSql($approval['task_name']);
    logAudit('Task', 'Edit', "$session_name $decision_sql approval $approval_id for task $task_name", $client_id, $task_id);
    flashAlert('Approval ' . escapeHtml($decision));
    redirect();
}

if (isset($_POST['retry_ticket_task_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $approval_id = intval($_POST['approval_id']);
    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_task_id,
        approval_scope, approval_type, approval_required_user_id, approval_created_by,
        task_name, task_state, ticket_id, ticket_client_id, ticket_contact_id, ticket_prefix,
        ticket_number, ticket_subject, ticket_assigned_to, ticket_project_id, ticket_created_at
        FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE approval_id = $approval_id AND approval_status IN ('pending','declined')
        AND task_state NOT IN ('Completed','Skipped') LIMIT 1"));
    if (!$approval) {
        flashAlert('Unresolved approval request not found', 'error');
        redirect();
    }
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess();
    }

    [$route_available, $route_error] = runbookApprovalRouteAvailability(
        $approval['approval_scope'],
        $approval['approval_type'],
        intval($approval['approval_required_user_id']),
        $approval,
        intval($approval['approval_created_by'])
    );
    if (!$route_available) {
        flashAlert(escapeHtml($route_error) . ' Reroute this approval before re-requesting it.', 'error');
        redirect();
    }

    $url_key_raw = randomString(32);
    $url_key = escapeSql(runbookApprovalTokenHash($url_key_raw));
    $url_expires_at_value = runbookApprovalTokenExpiry();
    $url_expires_at = escapeSql($url_expires_at_value);
    $request_reason = trim($_POST['approval_reason'] ?? 'Administrative re-request');
    if ($request_reason === '') {
        $request_reason = 'Administrative re-request';
    }
    $request_reason_sql = escapeSql($request_reason);
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The approval could not be locked for re-requesting. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket(intval($approval['ticket_id']));
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT approval_task_id,
            approval_scope, approval_type, approval_required_user_id, approval_created_by,
            approval_status, task_name, task_state, ticket_id, ticket_client_id,
            ticket_contact_id, ticket_prefix, ticket_number, ticket_subject,
            ticket_assigned_to, ticket_project_id, ticket_created_at
            FROM task_approvals
            INNER JOIN tasks ON task_id = approval_task_id
            INNER JOIN tickets ON ticket_id = task_ticket_id
            WHERE approval_id = $approval_id LIMIT 1 FOR UPDATE", 'Could not lock the approval for re-request'));
        if (!$locked_approval
            || intval($locked_approval['ticket_id']) !== intval($locked_ticket['ticket_id'])
            || !in_array($locked_approval['approval_status'], ['pending', 'declined'], true)
            || in_array($locked_approval['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The approval is no longer eligible for re-request');
        }
        $approval = array_merge($locked_approval, $locked_ticket);
        [$route_available, $route_error] = runbookApprovalRouteAvailability(
            $approval['approval_scope'],
            $approval['approval_type'],
            intval($approval['approval_required_user_id']),
            $approval,
            intval($approval['approval_created_by'])
        );
        if (!$route_available) {
            throw new RuntimeException($route_error);
        }
        $task_id = intval($approval['approval_task_id']);
        runbookDbQuery("UPDATE task_approvals SET approval_status = 'pending',
            approval_approved_by = NULL, approval_decided_at = NULL, approval_url_key = '$url_key',
            approval_url_expires_at = '$url_expires_at'
            WHERE approval_id = $approval_id AND approval_task_id = $task_id
            AND approval_status IN ('pending','declined')
            AND EXISTS (SELECT 1 FROM tasks WHERE task_id = $task_id
                AND task_state NOT IN ('Completed','Skipped'))", 'Could not re-request the task approval');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The approval changed before it could be re-requested');
        }
        runbookRecordApprovalEvent(
            $approval_id,
            $task_id,
            're_requested',
            [
                'status' => $locked_approval['approval_status'],
                'scope' => $approval['approval_scope'],
                'type' => $approval['approval_type'],
                'required_user_id' => intval($approval['approval_required_user_id']),
            ],
            [
                'status' => 'pending',
                'scope' => $approval['approval_scope'],
                'type' => $approval['approval_type'],
                'required_user_id' => intval($approval['approval_required_user_id']),
            ],
            'agent',
            $session_user_id,
            $session_name,
            $request_reason,
            $url_expires_at_value
        );

        $task = [
            'runbook_version_task_name' => $approval['task_name'],
            'runbook_version_task_approval_scope' => $approval['approval_scope'],
            'runbook_version_task_approval_type' => $approval['approval_type'],
            'runbook_version_task_approval_user_id' => intval($approval['approval_required_user_id']),
        ];
        runbookQueueApprovalNotification(
            $approval_id,
            $approval,
            $task,
            $url_key_raw,
            intval($approval['approval_created_by'])
        );
        runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
            task_evidence_type = 'approval_audit',
            task_evidence_note = 'Approval $approval_id was re-requested. Reason: $request_reason_sql',
            task_evidence_submitted_by = $session_user_id", 'Could not record the approval re-request audit');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('The approval re-request audit record could not be committed');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The approval could not be re-requested. The ticket may be resolved or closed.', 'error');
        redirect();
    }
    logAudit('Task', 'Edit', "$session_name re-requested approval $approval_id: $request_reason_sql", $client_id, $task_id);
    flashAlert('Approval request sent again');
    redirect();
}

if (isset($_POST['reroute_ticket_task_approval'])) {

    validateCSRFToken();
    enforceUserPermission('module_support', 3);

    $approval_id = intval($_POST['approval_id']);
    $route = (string) ($_POST['approval_route'] ?? '');
    $reason = trim($_POST['approval_reason'] ?? '');
    $route_parts = explode(':', $route, 2);
    $new_scope = $route_parts[0] ?? '';
    $new_type = $route_parts[1] ?? '';
    $new_user_id = $new_scope === 'internal' && $new_type === 'specific'
        ? intval($_POST['approval_required_user_id'] ?? 0)
        : 0;

    $valid_route = ($new_scope === 'internal' && in_array($new_type, ['any', 'specific'], true))
        || ($new_scope === 'client' && in_array($new_type, ['any', 'technical', 'billing'], true));
    if (!$valid_route || $reason === '') {
        flashAlert('Choose a valid approval route and provide a reroute reason.', 'error');
        redirect();
    }

    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_task_id,
        approval_scope, approval_type, approval_required_user_id, approval_created_by,
        approval_status, task_name, task_state, task_waiting_reason, ticket_id, ticket_client_id,
        ticket_contact_id, ticket_prefix, ticket_number, ticket_subject,
        ticket_assigned_to, ticket_project_id, ticket_created_at
        FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE approval_id = $approval_id AND approval_status IN ('pending','declined')
        AND task_state NOT IN ('Completed','Skipped') LIMIT 1"));
    if (!$approval) {
        flashAlert('Unresolved approval request not found', 'error');
        redirect();
    }
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess();
    }

    [$route_available, $route_error] = runbookApprovalRouteAvailability(
        $new_scope,
        $new_type,
        $new_user_id,
        $approval,
        intval($approval['approval_created_by'])
    );
    if (!$route_available) {
        flashAlert(escapeHtml($route_error), 'error');
        redirect();
    }

    $old_scope = (string) $approval['approval_scope'];
    $old_type = (string) $approval['approval_type'];
    $old_user_id = intval($approval['approval_required_user_id']);
    $old_route = $old_scope . ':' . $old_type . ($old_user_id ? ':' . $old_user_id : '');
    $new_route = $new_scope . ':' . $new_type . ($new_user_id ? ':' . $new_user_id : '');
    $scope_sql = escapeSql($new_scope);
    $type_sql = escapeSql($new_type);
    $user_sql = $new_user_id ? (string) $new_user_id : 'NULL';
    $url_key_raw = randomString(32);
    $url_key = escapeSql(runbookApprovalTokenHash($url_key_raw));
    $url_expires_at_value = runbookApprovalTokenExpiry();
    $url_expires_at = escapeSql($url_expires_at_value);
    $reason_sql = escapeSql($reason);
    $task_id = intval($approval['approval_task_id']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The approval could not be locked for rerouting. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket(intval($approval['ticket_id']));
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT approval_task_id,
            approval_scope, approval_type, approval_required_user_id, approval_created_by,
            approval_status, task_name, task_state, task_waiting_reason, ticket_id,
            ticket_client_id, ticket_contact_id, ticket_prefix, ticket_number,
            ticket_subject, ticket_assigned_to, ticket_project_id, ticket_created_at
            FROM task_approvals
            INNER JOIN tasks ON task_id = approval_task_id
            INNER JOIN tickets ON ticket_id = task_ticket_id
            WHERE approval_id = $approval_id LIMIT 1 FOR UPDATE", 'Could not lock the approval for rerouting'));
        if (!$locked_approval
            || intval($locked_approval['ticket_id']) !== intval($locked_ticket['ticket_id'])
            || !in_array($locked_approval['approval_status'], ['pending', 'declined'], true)
            || in_array($locked_approval['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The approval is no longer eligible for rerouting');
        }
        $approval = array_merge($locked_approval, $locked_ticket);
        [$route_available, $route_error] = runbookApprovalRouteAvailability(
            $new_scope,
            $new_type,
            $new_user_id,
            $approval,
            intval($approval['approval_created_by'])
        );
        if (!$route_available) {
            throw new RuntimeException($route_error);
        }
        $task_id = intval($approval['approval_task_id']);
        $old_scope = (string) $approval['approval_scope'];
        $old_type = (string) $approval['approval_type'];
        $old_user_id = intval($approval['approval_required_user_id']);
        $old_route = $old_scope . ':' . $old_type . ($old_user_id ? ':' . $old_user_id : '');
        runbookDbQuery("UPDATE task_approvals SET approval_scope = '$scope_sql',
            approval_type = '$type_sql', approval_required_user_id = $user_sql,
            approval_status = 'pending', approval_approved_by = NULL, approval_decided_at = NULL,
            approval_url_key = '$url_key', approval_url_expires_at = '$url_expires_at'
            WHERE approval_id = $approval_id AND approval_task_id = $task_id
            AND approval_status IN ('pending','declined')
            AND EXISTS (SELECT 1 FROM tasks WHERE task_id = $task_id
                AND task_state NOT IN ('Completed','Skipped'))", 'Could not reroute the task approval');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The approval changed before it could be rerouted');
        }
        runbookRecordApprovalEvent(
            $approval_id,
            $task_id,
            'rerouted',
            [
                'status' => $locked_approval['approval_status'],
                'scope' => $old_scope,
                'type' => $old_type,
                'required_user_id' => $old_user_id,
            ],
            [
                'status' => 'pending',
                'scope' => $new_scope,
                'type' => $new_type,
                'required_user_id' => $new_user_id,
            ],
            'agent',
            $session_user_id,
            $session_name,
            $reason,
            $url_expires_at_value
        );
        $audit_note = escapeSql("Approval $approval_id rerouted from $old_route to $new_route. Reason: $reason");
        runbookDbQuery("INSERT INTO task_evidence SET task_evidence_task_id = $task_id,
            task_evidence_type = 'approval_audit', task_evidence_note = '$audit_note',
            task_evidence_submitted_by = $session_user_id", 'Could not record the approval reroute audit');
        $waiting_reason = (string) ($approval['task_waiting_reason'] ?? '');
        $reroute_marker = ' An administrator must reroute the approval.';
        if ($approval['task_state'] === 'Waiting' && str_ends_with($waiting_reason, $reroute_marker)) {
            $separator = strrpos($waiting_reason, '; ');
            $remaining_reason = $separator === false ? '' : substr($waiting_reason, 0, $separator);
            $remaining_reason_sql = $remaining_reason === '' ? 'NULL' : "'" . escapeSql($remaining_reason) . "'";
            $next_state = $remaining_reason === '' ? 'Ready' : 'Waiting';
            runbookDbQuery("UPDATE tasks SET task_state = '$next_state',
                task_waiting_reason = $remaining_reason_sql WHERE task_id = $task_id
                AND task_state = 'Waiting'", 'Could not clear the unavailable approval route state');
            if ($next_state !== 'Waiting' && mysqli_affected_rows($mysqli) === 1) {
                runbookRecordTaskStateEvent($task_id, 'Waiting', $next_state, 'Approval route restored', $session_user_id, 'agent');
            }
        }
        refreshRunbookTaskStates(intval($locked_ticket['ticket_id']));

        $task = [
            'runbook_version_task_name' => $approval['task_name'],
            'runbook_version_task_approval_scope' => $new_scope,
            'runbook_version_task_approval_type' => $new_type,
            'runbook_version_task_approval_user_id' => $new_user_id,
        ];
        runbookQueueApprovalNotification(
            $approval_id,
            $approval,
            $task,
            $url_key_raw,
            intval($approval['approval_created_by'])
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the approval reroute');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The approval reroute could not be committed. The ticket may be resolved or closed.', 'error');
        redirect();
    }
    logAudit('Task', 'Edit', "$session_name rerouted approval $approval_id from $old_route to $new_route: $reason_sql", $client_id, $task_id);
    flashAlert('Approval rerouted and re-requested');
    redirect();
}

if (isset($_POST['delete_ticket_task_approver'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 3);

    $approval_id = intval($_POST['approval_id'] ?? $_POST['delete_ticket_task_approver']);

    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_task_id,
        task_completed_at, task_runbook_version_task_id, task_ticket_id, ticket_client_id
        FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE approval_id = $approval_id LIMIT 1"));
    if (!$approval) {
        flashAlert('Approval request not found', 'error');
        redirect();
    }
    $client_id = intval($approval['ticket_client_id']);
    if ($client_id) {
        enforceClientAccess();
    }
    if (!empty($approval['task_completed_at']) || intval($approval['task_runbook_version_task_id']) > 0) {
        flashAlert('A published runbook approval cannot be removed. Decide it or skip the task with an audit reason.', 'error');
        redirect();
    }

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket could not be locked for deleting the approval.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket(intval($approval['task_ticket_id']));
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $locked_approval = mysqli_fetch_assoc(runbookDbQuery("SELECT approval_task_id,
            task_completed_at, task_runbook_version_task_id, task_state
            FROM task_approvals INNER JOIN tasks ON task_id = approval_task_id
            WHERE approval_id = $approval_id LIMIT 1 FOR UPDATE", 'Could not lock the legacy approval'));
        if (!$locked_approval || !empty($locked_approval['task_completed_at'])
            || intval($locked_approval['task_runbook_version_task_id']) > 0
            || in_array($locked_approval['task_state'], ['Completed', 'Skipped'], true)) {
            throw new RuntimeException('The approval is immutable');
        }
        runbookDbQuery("DELETE FROM task_approvals WHERE approval_id = $approval_id
            AND approval_task_id = " . intval($locked_approval['approval_task_id']), 'Could not delete the legacy approval');
        if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
            throw new RuntimeException('The approval changed before it could be deleted');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('The approval cannot be deleted from a resolved or closed ticket.', 'error');
        redirect();
    }

    logAudit("Task", "Delete", "$session_name deleted task approval request ($approval_id)", $client_id, intval($approval['approval_task_id']));

    flashAlert("Approval request deleted", 'error');

    redirect();

}

if (isset($_GET['complete_all_tasks'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['complete_all_tasks']);

    // Get Client ID
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    $completed_count = 0;
    $blocked_count = 0;
    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket tasks could not be locked for completion. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $tasks = runbookDbQuery("SELECT task_id FROM tasks WHERE task_ticket_id = $ticket_id
            AND task_completed_at IS NULL ORDER BY task_order, task_id FOR UPDATE", 'Could not load ticket tasks for completion');
        while ($task = mysqli_fetch_assoc($tasks)) {
            $task_id = intval($task['task_id']);
            [$can_complete] = runbookTaskCanComplete($task_id);
            if (!$can_complete) {
                $blocked_count++;
                continue;
            }
            runbookDbQuery("UPDATE tasks SET task_state = 'Completed', task_completed_at = NOW(),
                task_completed_by = $session_user_id WHERE task_id = $task_id
                AND task_state = 'Ready' AND task_completed_at IS NULL", 'Could not complete an eligible ticket task');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('An eligible task changed before it could be completed');
            }
            runbookRecordTaskStateEvent($task_id, 'Ready', 'Completed', 'Bulk task completion', $session_user_id, 'agent');
            $completed_count++;
        }
        refreshRunbookTaskStates($ticket_id);
        runbookDbQuery("INSERT INTO ticket_replies SET
            ticket_reply = 'Completed $completed_count eligible tasks; $blocked_count remained gated',
            ticket_reply_time_worked = '00:00:00', ticket_reply_type = 'Internal',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record bulk task completion');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit bulk task completion');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('No tasks were changed because bulk completion could not be committed.', 'error');
        redirect();
    }

    logAudit("Ticket", "Edit", "$session_name marked all tasks complete for ticket", $client_id, $ticket_id);

    flashAlert("Completed <strong>$completed_count</strong> eligible task(s); <strong>$blocked_count</strong> remain gated");

    redirect();

}

if (isset($_GET['undo_complete_all_tasks'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_GET['undo_complete_all_tasks']);

    // Get Client ID
    $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
    enforceClientAccess();

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The ticket tasks could not be locked for reopening. Try again.', 'error');
        redirect();
    }
    try {
        $locked_ticket = runbookLockOpenTicket($ticket_id);
        runbookRequireLockedTicketClient($locked_ticket, $client_id);
        $completed_ids = [];
        $completed_tasks = runbookDbQuery("SELECT task_id FROM tasks
            WHERE task_ticket_id = $ticket_id AND task_state = 'Completed' FOR UPDATE", 'Could not lock completed tasks for bulk reopening');
        while ($completed_task = mysqli_fetch_assoc($completed_tasks)) {
            $completed_ids[] = intval($completed_task['task_id']);
        }
        runbookDbQuery("UPDATE tasks SET task_state = 'Ready', task_completed_at = NULL,
            task_completed_by = NULL WHERE task_ticket_id = $ticket_id
            AND task_state = 'Completed'", 'Could not reopen completed ticket tasks');
        $reopened_count = max(0, intval(mysqli_affected_rows($mysqli)));
        if ($reopened_count === 0) {
            throw new RuntimeException('No completed tasks remain to reopen');
        }
        foreach ($completed_ids as $completed_id) {
            runbookRecordTaskStateEvent($completed_id, 'Completed', 'Ready', 'Bulk task reopening', $session_user_id, 'agent');
        }
        refreshRunbookTaskStates($ticket_id);
        runbookDbQuery("INSERT INTO ticket_replies SET ticket_reply = 'Marked $reopened_count tasks incomplete',
            ticket_reply_time_worked = '00:00:00', ticket_reply_type = 'Internal',
            ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id", 'Could not record bulk task reopening');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit bulk task reopening');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        flashAlert('No tasks were changed because bulk reopening could not be committed.', 'error');
        redirect();
    }

    logAudit("Ticket", "Edit", "$session_name marked all tasks as incomplete for ticket", $client_id, $ticket_id);

    flashAlert("Marked <strong>$reopened_count</strong> task(s) incomplete", 'error');

    redirect();

}
