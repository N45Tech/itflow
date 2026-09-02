<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$task_id = intval($_GET['id']);
$task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_state,
    task_waiting_reason, task_condition_result, ticket_client_id
    FROM tasks INNER JOIN tickets ON ticket_id = task_ticket_id AND ticket_deleted_at IS NULL
    WHERE task_id = $task_id AND task_runbook_version_task_id > 0 LIMIT 1"));

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Runbook task not found']);
    exit;
}

$client_id = intval($task['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-hourglass-half mr-2"></i><?= escapeHtml($task['task_name']) ?></h5>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
    <p class="text-muted">Current state: <span class="badge badge-<?= runbookTaskStateBadge($task['task_state']) ?>"><?= escapeHtml($task['task_state']) ?></span></p>

    <?php if ($task['task_state'] === 'Waiting') { ?>
        <form action="post.php" method="post" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="task_id" value="<?= $task_id ?>">
            <button type="submit" name="resume_task" class="btn btn-primary btn-block"><i class="fas fa-play mr-2"></i>Applicable / Resume Work</button>
        </form>
    <?php } else { ?>
        <form action="post.php" method="post" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="task_id" value="<?= $task_id ?>">
            <div class="form-group">
                <label>Waiting Reason <strong class="text-danger">*</strong></label>
                <input type="text" class="form-control" name="waiting_reason" maxlength="255" required placeholder="Vendor action, maintenance window, client decision…">
            </div>
            <button type="submit" name="set_task_waiting" class="btn btn-warning btn-block"><i class="fas fa-pause mr-2"></i>Set Waiting</button>
        </form>
    <?php } ?>

    <?php if (lookupUserPermission('module_support') >= 3) { ?>
        <hr>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="task_id" value="<?= $task_id ?>">
            <div class="form-group">
                <label>Not Applicable Reason <strong class="text-danger">*</strong></label>
                <input type="text" class="form-control" name="skip_reason" maxlength="255" required placeholder="Why this step does not apply">
            </div>
            <button type="submit" name="skip_runbook_task" class="btn btn-outline-secondary btn-block"><i class="fas fa-minus-circle mr-2"></i>Skip With Audit Reason</button>
        </form>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
</div>

<?php

require_once '../../../includes/modal_footer.php';
