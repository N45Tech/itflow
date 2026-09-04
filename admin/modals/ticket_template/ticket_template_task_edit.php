<?php

require_once '../../includes/modal_header.php';

enforceAdminPermission();

$task_template_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_id = $task_template_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$task_template_name = escapeHtml($row['task_template_name']);
$task_template_key = escapeHtml($row['task_template_key']);
$task_template_instructions = escapeHtml($row['task_template_instructions']);
$task_template_order = intval($row['task_template_order']);
$task_template_completion_estimate = intval($row['task_template_completion_estimate']);
$task_template_ticket_template_id = intval($row['task_template_ticket_template_id']);
$task_template_condition_type = $row['task_template_condition_type'];
$task_template_condition_value = escapeHtml($row['task_template_condition_value']);
$task_template_owner_type = $row['task_template_owner_type'];
$task_template_owner_user_id = intval($row['task_template_owner_user_id']);
$task_template_due_offset_hours = intval($row['task_template_due_offset_minutes']) / 60;
$task_template_initial_state = $row['task_template_initial_state'];
$task_template_approval_scope = $row['task_template_approval_scope'];
$task_template_approval_type = $row['task_template_approval_type'];
$task_template_approval_user_id = intval($row['task_template_approval_user_id']);
$task_template_evidence_type = $row['task_template_evidence_type'];
$task_template_evidence_prompt = escapeHtml($row['task_template_evidence_prompt']);
$task_template_key_locked = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*)
    FROM runbook_version_tasks
    INNER JOIN runbook_versions ON runbook_version_id = runbook_version_task_runbook_version_id
    WHERE runbook_version_ticket_template_id = $task_template_ticket_template_id
    AND (runbook_version_task_source_id = $task_template_id
        OR runbook_version_task_key = '" . escapeSql($row['task_template_key']) . "')"))[0] ?? 0) > 0;

$selected_dependencies = [];
$sql_dependencies = mysqli_query($mysqli, "SELECT depends_on_task_template_id FROM task_template_dependencies WHERE task_template_id = $task_template_id");
while ($dependency = mysqli_fetch_assoc($sql_dependencies)) {
    $selected_dependencies[] = intval($dependency['depends_on_task_template_id']);
}
//$task_template_description = escapeHtml($row['task_template_description']);

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tasks me-2"></i>Editing task</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="task_template_id" value="<?= $task_template_id ?>">

    <div class="modal-body">

        <div class="mb-3">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                <input type="text" class="form-control" name="name" placeholder="Name the task" maxlength="255" value="<?= $task_template_name ?>" required autofocus>
            </div>
        </div>

        <div class="row g-2">
            <div class="mb-3 col-md-6">
                <label>Stable Step Key <strong class="text-danger">*</strong></label>
                <input type="text" class="form-control" name="task_key" maxlength="100" value="<?= $task_template_key ?>" required <?= $task_template_key_locked ? 'readonly' : '' ?>>
                <?php if ($task_template_key_locked) { ?>
                    <small class="form-text text-muted">The stable step key is locked after publication.</small>
                <?php } ?>
            </div>
            <div class="mb-3 col-md-6">
                <label>Initial State</label>
                <select class="form-select" name="initial_state">
                    <?php foreach (runbookInitialStates() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $task_template_initial_state === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label>Instructions</label>
            <textarea class="form-control" name="instructions" rows="3" maxlength="4000" placeholder="Completion criteria, safe operating notes, and evidence guidance"><?= $task_template_instructions ?></textarea>
        </div>

        <div class="mb-3">
            <label>Estimated Completion Time <span class="text-secondary">(Minutes)</span></label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                <input type="number" class="form-control" name="completion_estimate" placeholder="Estimated time to complete task in mins" value="<?= $task_template_completion_estimate ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Run When</label>
                <select class="form-control" name="condition_type">
                    <?php foreach (runbookConditionTypes() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $task_template_condition_type === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Condition Value</label>
                <input type="text" class="form-control" name="condition_value" maxlength="255" value="<?= $task_template_condition_value ?>" placeholder="Service keyword or asset type">
            </div>
        </div>

        <div class="form-group">
            <label>Blocked By</label>
            <select class="form-control select2" name="depends_on[]" multiple>
                <?php
                $other_tasks = mysqli_query($mysqli, "SELECT task_template_id, task_template_name FROM task_templates
                    WHERE task_template_ticket_template_id = $task_template_ticket_template_id
                    AND task_template_id <> $task_template_id
                    ORDER BY task_template_order, task_template_id");
                while ($other_task = mysqli_fetch_assoc($other_tasks)) {
                    $other_task_id = intval($other_task['task_template_id']);
                    ?>
                    <option value="<?= $other_task_id ?>" <?= in_array($other_task_id, $selected_dependencies, true) ? 'selected' : '' ?>><?= escapeHtml($other_task['task_template_name']) ?></option>
                <?php } ?>
            </select>
            <small class="form-text text-muted">The task stays blocked until every selected predecessor is completed or skipped.</small>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Owner</label>
                <select class="form-control" name="owner_type">
                    <?php foreach (runbookOwnerTypes() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $task_template_owner_type === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Specific Agent</label>
                <select class="form-control select2" name="owner_user_id">
                    <option value="0">None</option>
                    <?php
                    $users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
                    while ($user = mysqli_fetch_assoc($users)) { ?>
                        <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $task_template_owner_user_id ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Due After Run Starts <span class="text-secondary">(Hours)</span></label>
            <input type="number" class="form-control" name="due_offset_hours" min="0" step="0.5" value="<?= $task_template_due_offset_hours ?: '' ?>" placeholder="Blank or 0 means no due date">
        </div>

        <hr>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Approval Scope</label>
                <select class="form-control" name="approval_scope">
                    <option value="">None</option>
                    <option value="internal" <?= $task_template_approval_scope === 'internal' ? 'selected' : '' ?>>Internal</option>
                    <option value="client" <?= $task_template_approval_scope === 'client' ? 'selected' : '' ?>>Client</option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label>Approval Type</label>
                <select class="form-control" name="approval_type">
                    <option value="">None</option>
                    <option value="any" <?= $task_template_approval_type === 'any' ? 'selected' : '' ?>>Any eligible approver</option>
                    <option value="technical" <?= $task_template_approval_type === 'technical' ? 'selected' : '' ?>>Technical contact</option>
                    <option value="billing" <?= $task_template_approval_type === 'billing' ? 'selected' : '' ?>>Billing contact</option>
                    <option value="specific" <?= $task_template_approval_type === 'specific' ? 'selected' : '' ?>>Specific agent</option>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label>Specific Approver</label>
                <select class="form-control select2" name="approval_user_id">
                    <option value="0">None</option>
                    <?php
                    mysqli_data_seek($users, 0);
                    while ($user = mysqli_fetch_assoc($users)) { ?>
                        <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $task_template_approval_user_id ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Required Evidence</label>
                <select class="form-control" name="evidence_type">
                    <?php foreach (runbookEvidenceTypes() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $task_template_evidence_type === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-8">
                <label>Evidence Prompt</label>
                <input type="text" class="form-control" name="evidence_prompt" maxlength="255" value="<?= $task_template_evidence_prompt ?>" placeholder="What proof should be recorded?">
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket_template_task" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';
