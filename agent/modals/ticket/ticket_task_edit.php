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

// Approvals
$sql_task_approvals = mysqli_query($mysqli, "
    SELECT user_name, approval_id, approval_scope, approval_type, approval_required_user_id, approval_status, approval_created_by, approval_approved_by FROM task_approvals
    LEFT JOIN users ON user_id = approval_required_user_id
    WHERE approval_task_id = $task_id
    ORDER BY approval_approved_by"
);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tasks mr-2"></i>Editing task</h5>
    <button type="button" class="close text-white" data-dismiss="modal">
        <span>&times;</span>
    </button>
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

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Name the task" maxlength="255" value="<?= $task_name ?>" required <?= $published_runbook_task ? 'readonly' : 'autofocus' ?>>
            </div>
        </div>

        <div class="form-group">
            <label>Estimated Completion Time <span class="text-secondary">(Minutes)</span></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                </div>
                <input type="number" class="form-control" name="completion_estimate" placeholder="Estimated time to complete task in mins" value="<?= $task_completion_estimate ?>" <?= $published_runbook_task ? 'readonly' : '' ?>>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Owner</label>
                <select class="form-control select2" name="assigned_to" <?= $terminal_published_runbook_task ? 'disabled' : '' ?>>
                    <option value="0">Unassigned</option>
                    <?php
                    $users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
                        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
                    while ($user = mysqli_fetch_assoc($users)) { ?>
                        <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $task_assigned_to ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Due</label>
                <input type="datetime-local" class="form-control" name="due_at" value="<?= escapeHtml($task_due_at) ?>" <?= $terminal_published_runbook_task ? 'disabled' : '' ?>>
            </div>
        </div>

        <?php if (mysqli_num_rows($sql_task_approvals) > 0) { ?>
            <hr>
            <div class="form-group">
                <b>Task Approvals</b>

                <table class="table table-sm table-bordered" style="margin-top:10px;">
                    <thead>
                    <tr>
                        <th>Scope</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($sql_task_approvals)) {
                        $approval_id = intval($row['approval_id']);
                        $approval_scope = escapeHtml($row['approval_scope']);
                        $approval_type = escapeHtml($row['approval_type']);
                        $approval_user_name = escapeHtml($row['user_name']);
                        $approval_status = escapeHtml($row['approval_status']);
                        $approval_created_by = intval($row['approval_created_by']);
                        $approval_approved_by = escapeHtml($row['approval_approved_by']);
                        ?>
                        <tr>
                            <td><?= ucfirst($approval_scope) ?></td>
                            <td><?= ucfirst($approval_type) ?> <?php if (!empty($approval_user_name)) { echo " - $approval_user_name"; } ?></td>
                            <td><?= ucfirst($approval_status) ?></td>
                            <td>
                                <?php if ($approval_status !== 'approved') { ?>
                                    <?php if ($published_runbook_task) { ?>
                                        <a class="ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_approval_reroute.php?id=<?= $approval_id ?>">
                                            <i class="fas fa-fw fa-route"></i>Manage
                                        </a>
                                    <?php } else { ?>
                                        <button type="submit" name="delete_ticket_task_approver" value="<?= $approval_id ?>"
                                                class="btn btn-sm btn-link text-danger p-0" formnovalidate
                                                onclick="return confirm('Delete this approval request?');">
                                            <i class="fas fa-fw fa-trash-alt"></i>Delete
                                        </button>
                                    <?php } ?>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>

            </div>
        <?php } ?>

    </div>

    <div class="modal-footer">
        <?php if (!$terminal_published_runbook_task) { ?>
            <button type="submit" name="edit_ticket_task" class="btn btn-primary text-bold"><i class="fa fa-check mr-2"></i>Save</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i class="fa fa-times mr-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';
