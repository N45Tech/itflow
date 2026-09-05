<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$task_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT task_completed_at, task_completion_estimate, task_name, task_state,
    task_assigned_to, task_due_at, task_runbook_version_task_id, ticket_client_id
    FROM tasks LEFT JOIN tickets ON task_ticket_id = ticket_id
    WHERE task_id = $task_id
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$task_name = escapeHtml($row['task_name']);
$task_completion_estimate = intval($row['task_completion_estimate']);
$task_completed_at = escapeHtml($row['task_completed_at']);
$task_assigned_to = intval($row['task_assigned_to']);
$task_due_at = $row['task_due_at'] ? date('Y-m-d\TH:i', strtotime($row['task_due_at'])) : '';
$published_runbook_task = intval($row['task_runbook_version_task_id']) > 0;
$terminal_published_runbook_task = $published_runbook_task
    && in_array($row['task_state'], ['Completed', 'Skipped'], true);
$client_id = intval($row['ticket_client_id']);

if ($client_id) {
    enforceClientAccess();
}

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tasks me-2"></i>Manage task</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="task_id" value="<?= $task_id ?>">

    <div class="modal-body">

        <?php if ($published_runbook_task) { ?>
            <div class="alert alert-info py-2">
                <?php if ($terminal_published_runbook_task) { ?>
                    This completed execution record is immutable, including its final owner and due date.
                <?php } else { ?>
                    Published runbook content, order, and estimates are immutable. Assignment and due date remain operationally editable until the task is terminal.
                <?php } ?>
            </div>
        <?php } ?>

        <div class="mb-3">
            <label>Task name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                <input type="text" class="form-control" name="name" placeholder="Name the task" maxlength="255" value="<?= $task_name ?>" required <?= $published_runbook_task ? 'readonly' : 'autofocus' ?>>
            </div>
        </div>

        <div class="mb-3">
            <label>Estimate <span class="text-secondary">(minutes)</span></label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                <input type="number" class="form-control" name="completion_estimate" placeholder="Estimated time to complete task in mins" value="<?= $task_completion_estimate ?>" <?= $published_runbook_task ? 'readonly' : '' ?>>
            </div>
        </div>

        <div class="row g-2">
            <div class="mb-3 col-md-6">
                <label>Owner</label>
                <select class="form-select select2" name="assigned_to" <?= $terminal_published_runbook_task ? 'disabled' : '' ?>>
                    <option value="0">Unassigned</option>
                    <?php
                    $users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
                        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
                    while ($user = mysqli_fetch_assoc($users)) { ?>
                        <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $task_assigned_to ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3 col-md-6">
                <label>Due</label>
                <input type="datetime-local" class="form-control" name="due_at" value="<?= escapeHtml($task_due_at) ?>" <?= $terminal_published_runbook_task ? 'disabled' : '' ?>>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <?php if (!$terminal_published_runbook_task) { ?>
            <button type="submit" name="edit_ticket_task" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';
