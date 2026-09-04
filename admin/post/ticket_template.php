<?php

// Ticket Templates

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Import shared code from user-side tickets/tasks as we reuse functions
require_once '../agent/post/ticket.php';
require_once '../agent/post/task.php';

if (isset($_POST['add_ticket_template'])) {

    validateCSRFToken();

    $name_raw = trim($_POST['name'] ?? '');
    $name = escapeSql($name_raw);
    $description = escapeSql($_POST['description']);
    $subject = escapeSql($_POST['subject']);
    $details = mysqli_real_escape_string($mysqli, $_POST['details']);
    $project_template_id = intval($_POST['project_template']);
    $runbook_key_input = trim($_POST['runbook_key'] ?? '') ?: $name_raw;
    $runbook_key = escapeSql(runbookNormalizeKey($runbook_key_input, 'runbook'));
    $runbook_type = $_POST['runbook_type'] ?? 'standard';
    if (!in_array($runbook_type, ['standard', 'onboarding', 'offboarding'], true)) {
        $runbook_type = 'standard';
    }
    $runbook_type = escapeSql($runbook_type);
    $duplicate_key = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_templates
        WHERE ticket_template_runbook_key = '$runbook_key'"))[0] ?? 0);
    if ($duplicate_key) {
        flashAlert("Runbook key <strong>$runbook_key</strong> is already in use", 'error');
        redirect();
    }

    mysqli_query($mysqli, "INSERT INTO ticket_templates SET ticket_template_name = '$name', ticket_template_description = '$description', ticket_template_subject = '$subject', ticket_template_details = '$details', ticket_template_runbook_key = '$runbook_key', ticket_template_runbook_type = '$runbook_type'");

    $ticket_template_id = mysqli_insert_id($mysqli);

    if($project_template_id) {
        mysqli_query($mysqli, "INSERT INTO project_template_ticket_templates SET project_template_id = $project_template_id, ticket_template_id = $ticket_template_id");
    }

    logAudit("Ticket Template", "Create", "$session_name created ticket template $name", 0, $ticket_template_id);

    flashAlert("Ticket Template <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_ticket_template'])) {

    validateCSRFToken();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $name_raw = trim($_POST['name'] ?? '');
    $name = escapeSql($name_raw);
    $description = escapeSql($_POST['description']);
    $subject = escapeSql($_POST['subject']);
    $details = mysqli_real_escape_string($mysqli, $_POST['details']);
    $runbook_key_input = trim($_POST['runbook_key'] ?? '') ?: $name_raw;
    $runbook_key = escapeSql(runbookNormalizeKey($runbook_key_input, 'runbook'));
    $published_identity = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_version_key
        FROM runbook_versions WHERE runbook_version_ticket_template_id = $ticket_template_id
        ORDER BY runbook_version_number ASC LIMIT 1"));
    if ($published_identity) {
        // A published stable identity can only change through a future explicit
        // fork/mapping workflow, never an ordinary metadata edit.
        $runbook_key = escapeSql($published_identity['runbook_version_key']);
    }
    $runbook_type = $_POST['runbook_type'] ?? 'standard';
    if (!in_array($runbook_type, ['standard', 'onboarding', 'offboarding'], true)) {
        $runbook_type = 'standard';
    }
    $runbook_type = escapeSql($runbook_type);
    $duplicate_key = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_templates
        WHERE ticket_template_runbook_key = '$runbook_key'
        AND ticket_template_id <> $ticket_template_id"))[0] ?? 0);
    if ($duplicate_key) {
        flashAlert("Runbook key <strong>$runbook_key</strong> is already in use", 'error');
        redirect();
    }

    mysqli_query($mysqli, "UPDATE ticket_templates SET ticket_template_name = '$name', ticket_template_description = '$description', ticket_template_subject = '$subject', ticket_template_details = '$details', ticket_template_runbook_key = '$runbook_key', ticket_template_runbook_type = '$runbook_type' WHERE ticket_template_id = $ticket_template_id");

    logAudit("Ticket Template", "Edit", "$session_name edited ticket template $name", 0, $ticket_template_id);

    flashAlert("Ticket Template <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['delete_ticket_template'])) {

    validateCSRFToken();
    enforceAdminPermission();

    $ticket_template_id = intval($_GET['delete_ticket_template']);
    $transaction_started = false;
    $published_version_count = 0;
    $ticket_template_name = '';
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the ticket template retirement transaction');
        }
        $transaction_started = true;

        // Publication takes this same lock before it snapshots the draft. The
        // count below therefore cannot race a new immutable version.
        $template = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_template_name
            FROM ticket_templates WHERE ticket_template_id = $ticket_template_id
            LIMIT 1 FOR UPDATE", 'Could not lock the ticket template for retirement'));
        if (!$template) {
            throw new RuntimeException('Ticket template not found');
        }
        $ticket_template_name = (string) $template['ticket_template_name'];
        $published_version_count = intval(mysqli_fetch_row(runbookDbQuery("SELECT COUNT(*)
            FROM runbook_versions WHERE runbook_version_ticket_template_id = $ticket_template_id",
            'Could not inspect ticket template version history'))[0] ?? 0);

        // Published definitions and execution evidence are audit history. A
        // published template can be retired, never hard-deleted.
        if ($published_version_count) {
            runbookDbQuery("UPDATE ticket_templates SET ticket_template_archived_at = NOW()
                WHERE ticket_template_id = $ticket_template_id", 'Could not archive the published ticket template');
        } else {
            $task_template_ids = [];
            $task_template_rows = runbookDbQuery("SELECT task_template_id FROM task_templates
                WHERE task_template_ticket_template_id = $ticket_template_id",
                'Could not load ticket template tasks for deletion');
            while ($task_template_row = mysqli_fetch_assoc($task_template_rows)) {
                $task_template_ids[] = intval($task_template_row['task_template_id']);
            }
            if ($task_template_ids) {
                $task_template_id_list = implode(',', $task_template_ids);
                runbookDbQuery("DELETE FROM task_template_dependencies
                    WHERE task_template_id IN ($task_template_id_list)
                    OR depends_on_task_template_id IN ($task_template_id_list)",
                    'Could not delete ticket template dependencies');
            }
            runbookDbQuery("DELETE FROM task_templates
                WHERE task_template_ticket_template_id = $ticket_template_id",
                'Could not delete ticket template tasks');
            runbookDbQuery("DELETE FROM project_template_ticket_templates
                WHERE ticket_template_id = $ticket_template_id",
                'Could not delete ticket template project links');

            // Unlink from recurring tickets rather than deleting them - the schedule is still
            // wanted, it just stops contributing tasks to the tickets it raises.
            runbookDbQuery("UPDATE recurring_tickets SET recurring_ticket_ticket_template_id = 0
                WHERE recurring_ticket_ticket_template_id = $ticket_template_id",
                'Could not unlink recurring tickets from the deleted template');

            runbookDbQuery("DELETE FROM ticket_templates
                WHERE ticket_template_id = $ticket_template_id",
                'Could not delete the ticket template');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The ticket template changed before deletion');
            }
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket template retirement');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log('Ticket template retirement rollback failed');
        }
        error_log("Ticket template $ticket_template_id retirement failed: " . $exception->getMessage());
        flashAlert('The ticket template could not be retired safely', 'error');
        redirect();
    }

    $ticket_template_name_html = escapeHtml($ticket_template_name);
    $ticket_template_name_sql = escapeSql($ticket_template_name);
    if ($published_version_count) {
        logAudit("Ticket Template", "Archive", "$session_name archived published ticket template $ticket_template_name_sql", 0, $ticket_template_id);
        flashAlert("Published Ticket Template <strong>$ticket_template_name_html</strong> archived; version history was retained", 'error');
    } else {
        logAudit("Ticket Template", "Delete", "$session_name deleted ticket template $ticket_template_name_sql");
        flashAlert("Ticket Template <strong>$ticket_template_name_html</strong> and its associated tasks deleted", 'error');
    }

    redirect();

}

if (isset($_POST['add_ticket_template_task'])) {

    validateCSRFToken();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $task_name = escapeSql($_POST['task_name']);
    $base_key = runbookNormalizeKey($_POST['task_key'] ?? $_POST['task_name'], 'task');
    $task_key = $base_key;
    $suffix = 2;
    while (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT
        (EXISTS(SELECT 1 FROM task_templates
            WHERE task_template_ticket_template_id = $ticket_template_id
            AND task_template_key = '" . escapeSql($task_key) . "')
        OR EXISTS(SELECT 1 FROM runbook_version_tasks
            INNER JOIN runbook_versions ON runbook_version_id = runbook_version_task_runbook_version_id
            WHERE runbook_version_ticket_template_id = $ticket_template_id
            AND runbook_version_task_key = '" . escapeSql($task_key) . "'))"))[0] ?? 0) > 0) {
        $task_key = substr($base_key, 0, 92) . '-' . $suffix;
        $suffix++;
    }
    $task_key = escapeSql($task_key);

    mysqli_query($mysqli, "INSERT INTO task_templates SET task_template_name = '$task_name', task_template_key = '$task_key', task_template_ticket_template_id = $ticket_template_id");

    $task_template_id = mysqli_insert_id($mysqli);

    logAudit("Ticket Template", "Create", "$session_name created task $task_name for ticket template", 0, $ticket_template_id);

    flashAlert("Added Task <strong>$task_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_template_task'])) {

    validateCSRFToken();
    enforceAdminPermission();

    $task_template_id = intval($_POST['task_template_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_template_ticket_template_id,
        task_template_key
        FROM task_templates WHERE task_template_id = $task_template_id LIMIT 1"));
    $ticket_template_id = intval($row['task_template_ticket_template_id'] ?? 0);
    if (!$ticket_template_id) {
        flashAlert('Task template not found', 'error');
        redirect();
    }

    $task_name_raw = trim($_POST['name'] ?? '');
    $task_name = escapeSql($task_name_raw);
    $task_key_raw = runbookNormalizeKey($_POST['task_key'] ?? $task_name_raw, 'task-' . $task_template_id);
    $published_task_identity = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_version_task_key
        FROM runbook_version_tasks
        INNER JOIN runbook_versions ON runbook_version_id = runbook_version_task_runbook_version_id
        WHERE runbook_version_ticket_template_id = $ticket_template_id
        AND (runbook_version_task_source_id = $task_template_id
            OR runbook_version_task_key = '" . escapeSql($row['task_template_key']) . "')
        ORDER BY runbook_version_number ASC, runbook_version_task_id ASC LIMIT 1"));
    if ($published_task_identity) {
        $task_key_raw = (string) $published_task_identity['runbook_version_task_key'];
    }
    $task_key = escapeSql($task_key_raw);
    $instructions = escapeSql($_POST['instructions'] ?? '');
    $task_completion_estimate = max(0, intval($_POST['completion_estimate'] ?? 0));
    $condition_type = runbookNormalizeChoice($_POST['condition_type'] ?? 'always', runbookConditionTypes(), 'always');
    $condition_value = escapeSql($_POST['condition_value'] ?? '');
    $owner_type = runbookNormalizeChoice($_POST['owner_type'] ?? 'unassigned', runbookOwnerTypes(), 'unassigned');
    $owner_user_id = max(0, intval($_POST['owner_user_id'] ?? 0));
    if ($owner_type === 'specific_user') {
        $valid_owner = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
            WHERE user_id = $owner_user_id AND user_type = 1 AND user_status = 1
            AND user_archived_at IS NULL"))[0] ?? 0);
        if (!$valid_owner) {
            flashAlert('Select an active owner for the specific-agent rule', 'error');
            redirect();
        }
    } else {
        $owner_user_id = 0;
    }
    $due_offset_minutes = max(0, intval(round(floatval($_POST['due_offset_hours'] ?? 0) * 60)));
    $initial_state = runbookNormalizeChoice($_POST['initial_state'] ?? 'Ready', runbookInitialStates(), 'Ready');
    $evidence_type = runbookNormalizeChoice($_POST['evidence_type'] ?? 'none', runbookEvidenceTypes(), 'none');
    $evidence_prompt = escapeSql($_POST['evidence_prompt'] ?? '');
    $approval_scope = $_POST['approval_scope'] ?? '';
    $approval_type = $_POST['approval_type'] ?? '';
    if (!in_array($approval_scope, ['internal', 'client'], true)
        || !in_array($approval_type, ['any', 'technical', 'billing', 'specific'], true)) {
        $approval_scope = '';
        $approval_type = '';
    }
    if (($approval_scope === 'client' && $approval_type === 'specific')
        || ($approval_scope === 'internal' && in_array($approval_type, ['technical', 'billing'], true))) {
        $approval_scope = '';
        $approval_type = '';
    }
    $approval_user_id = $approval_type === 'specific' ? max(0, intval($_POST['approval_user_id'] ?? 0)) : 0;
    if ($approval_user_id) {
        $valid_user = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users
            WHERE user_id = $approval_user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"))[0] ?? 0);
        if (!$valid_user) {
            $approval_user_id = 0;
        }
    }
    $approval_scope_sql = $approval_scope ? "'" . escapeSql($approval_scope) . "'" : 'NULL';
    $approval_type_sql = $approval_type ? "'" . escapeSql($approval_type) . "'" : 'NULL';
    $condition_type = escapeSql($condition_type);
    $owner_type = escapeSql($owner_type);
    $initial_state = escapeSql($initial_state);
    $evidence_type = escapeSql($evidence_type);

    $duplicate = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_templates
        WHERE task_template_ticket_template_id = $ticket_template_id
        AND task_template_key = '$task_key' AND task_template_id <> $task_template_id"))[0] ?? 0);
    $historical_reuse = !$published_task_identity
        ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM runbook_version_tasks
            INNER JOIN runbook_versions ON runbook_version_id = runbook_version_task_runbook_version_id
            WHERE runbook_version_ticket_template_id = $ticket_template_id
            AND runbook_version_task_key = '$task_key'"))[0] ?? 0)
        : 0;
    if ($duplicate || $historical_reuse) {
        flashAlert("Task key <strong>$task_key</strong> is already in use", 'error');
        redirect();
    }

    $dependencies = [];
    foreach (($_POST['depends_on'] ?? []) as $dependency_id) {
        $dependency_id = intval($dependency_id);
        if ($dependency_id && $dependency_id !== $task_template_id) {
            $dependencies[$dependency_id] = $dependency_id;
        }
    }
    if ($dependencies) {
        $dependency_list = implode(',', $dependencies);
        $valid_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_templates
            WHERE task_template_ticket_template_id = $ticket_template_id
            AND task_template_id IN ($dependency_list)"))[0] ?? 0);
        if ($valid_count !== count($dependencies)) {
            flashAlert('Every dependency must belong to the same template', 'error');
            redirect();
        }
    }

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the runbook task edit transaction');
        }
        $transaction_started = true;
        runbookDbQuery("UPDATE task_templates SET
            task_template_name = '$task_name', task_template_key = '$task_key',
            task_template_instructions = '$instructions',
            task_template_completion_estimate = $task_completion_estimate,
            task_template_condition_type = '$condition_type', task_template_condition_value = '$condition_value',
            task_template_owner_type = '$owner_type', task_template_owner_user_id = $owner_user_id,
            task_template_due_offset_minutes = $due_offset_minutes,
            task_template_initial_state = '$initial_state',
            task_template_approval_scope = $approval_scope_sql,
            task_template_approval_type = $approval_type_sql,
            task_template_approval_user_id = $approval_user_id,
            task_template_evidence_type = '$evidence_type',
            task_template_evidence_prompt = '$evidence_prompt'
            WHERE task_template_id = $task_template_id
            AND task_template_ticket_template_id = $ticket_template_id", 'Could not update the draft runbook task');
        runbookDbQuery("DELETE FROM task_template_dependencies WHERE task_template_id = $task_template_id", 'Could not reset draft task dependencies');
        foreach ($dependencies as $dependency_id) {
            runbookDbQuery("INSERT INTO task_template_dependencies SET
                task_template_id = $task_template_id,
                depends_on_task_template_id = $dependency_id", 'Could not save a draft task dependency');
        }

        $definition_errors = runbookValidateDefinition(runbookDraftDefinition($ticket_template_id));
        if ($definition_errors) {
            throw new InvalidArgumentException($definition_errors[0]);
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the runbook task edit transaction');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log('Runbook task edit rollback failed');
        }
        error_log('Runbook task edit failed: ' . $exception->getMessage());
        flashAlert(escapeHtml($exception instanceof InvalidArgumentException
            ? $exception->getMessage() : 'The runbook task could not be saved'), 'error');
        redirect();
    }

    logAudit("Task", "Edit", "$session_name edited runbook task $task_name", 0, $task_template_id);
    flashAlert("Runbook task <strong>$task_name</strong> edited");
    redirect();
}

if (isset($_GET['delete_task_template'])) {

    validateCSRFToken();

    $task_template_id = intval($_GET['delete_task_template']);

    $task_template_name = escapeSql(getFieldById('task_templates', $task_template_id, 'task_template_name'));

    mysqli_query($mysqli, "DELETE FROM task_template_dependencies WHERE task_template_id = $task_template_id OR depends_on_task_template_id = $task_template_id");
    mysqli_query($mysqli, "DELETE FROM task_templates WHERE task_template_id = $task_template_id");

    logAudit("Ticket Template", "Edit", "$session_name deleted task $task_template_name from ticket template");

    flashAlert("Task <strong>$task_template_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['publish_ticket_template'])) {

    validateCSRFToken();
    enforceAdminPermission();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $notes = trim($_POST['version_notes'] ?? '');
    $definition = runbookDraftDefinition($ticket_template_id);
    $errors = $definition ? runbookValidateDefinition($definition) : ['Template not found.'];
    if ($errors) {
        flashAlert(escapeHtml($errors[0]), 'error');
        redirect();
    }

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the runbook publication transaction');
        }
        $transaction_started = true;
        $version_id = publishRunbookVersion($ticket_template_id, $session_user_id, $notes);
        if (!$version_id) {
            throw new RuntimeException('The runbook version could not be published');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the runbook publication transaction');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log('Runbook publication rollback failed');
        }
        error_log('Runbook publication failed: ' . $exception->getMessage());
        flashAlert('The runbook version could not be published. No version changes were saved.', 'error');
        redirect();
    }

    $version_number = intval(getFieldById('runbook_versions', $version_id, 'runbook_version_number'));
    logAudit("Ticket Template", "Publish", "$session_name published runbook version $version_number", 0, $ticket_template_id);
    flashAlert("Published runbook <strong>v$version_number</strong>");
    redirect();
}

if (isset($_POST['restore_ticket_template_version'])) {

    validateCSRFToken();
    enforceAdminPermission();

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $runbook_version_id = intval($_POST['runbook_version_id']);
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the runbook draft restore transaction');
        }
        $transaction_started = true;
        if (!restoreRunbookVersionToDraft($ticket_template_id, $runbook_version_id)) {
            throw new RuntimeException('Runbook version not found');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the runbook draft restore transaction');
        }
        $transaction_started = false;
    } catch (Throwable $exception) {
        if ($transaction_started && !mysqli_rollback($mysqli)) {
            error_log('Runbook draft restore rollback failed');
        }
        error_log('Runbook draft restore failed: ' . $exception->getMessage());
        flashAlert('The runbook version could not be restored. The draft was not changed.', 'error');
        redirect();
    }

    logAudit("Ticket Template", "Restore", "$session_name restored a published runbook into the draft", 0, $ticket_template_id);
    flashAlert('Published runbook restored into the editable draft. Publish again when ready.');
    redirect();
}
