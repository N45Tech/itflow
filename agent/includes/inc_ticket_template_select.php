<?php

/*
 * ITFlow - Ticket template picker
 *
 * Shared by the ticket add modal and the recurring ticket add/edit modals. Each
 * option carries the template's subject, details and task list as data
 * attributes, which agent/js/ticket_tasks_modal.js applies to the form when the
 * template is picked.
 *
 * Set $selected_ticket_template_id before including to pre-select a template (an
 * archived one stays listed while it is the one selected, so an existing link
 * remains visible). Defaults to none.
 */

$selected_ticket_template_id = intval($selected_ticket_template_id ?? 0);
$enable_runbook_workflow = !empty($enable_runbook_workflow);
$published_runbook_filter = $enable_runbook_workflow
    ? ''
    : "AND (ticket_template_published_version_id = 0 OR ticket_template_id = $selected_ticket_template_id)";

// Every template's tasks in one pass, rather than a query per option
$ticket_template_tasks = [];

$sql_ticket_template_tasks = mysqli_query(
    $mysqli,
    "SELECT task_template_ticket_template_id, task_template_name, task_template_completion_estimate
    FROM task_templates
    ORDER BY task_template_order ASC, task_template_id ASC"
);

while ($row = mysqli_fetch_assoc($sql_ticket_template_tasks)) {
    $ticket_template_tasks[intval($row['task_template_ticket_template_id'])][] = [
        'name' => $row['task_template_name'],
        'estimate' => intval($row['task_template_completion_estimate'])
    ];
}

// Published task snapshots replace mutable draft rows in the preview. Normal
// ticket creation will instantiate the same pinned version server-side.
$published_template_seen = [];
$sql_published_tasks = mysqli_query(
    $mysqli,
    "SELECT runbook_version_ticket_template_id, runbook_version_task_name,
        runbook_version_task_completion_estimate
    FROM runbook_versions
    INNER JOIN ticket_templates
        ON ticket_template_published_version_id = runbook_version_id
        AND ticket_template_id = runbook_version_ticket_template_id
    INNER JOIN runbook_version_tasks
        ON runbook_version_task_runbook_version_id = runbook_version_id
    ORDER BY runbook_version_ticket_template_id,
        runbook_version_task_order, runbook_version_task_id"
);
while ($row = mysqli_fetch_assoc($sql_published_tasks)) {
    $template_id = intval($row['runbook_version_ticket_template_id']);
    if (!isset($published_template_seen[$template_id])) {
        $ticket_template_tasks[$template_id] = [];
        $published_template_seen[$template_id] = true;
    }
    $ticket_template_tasks[$template_id][] = [
        'name' => $row['runbook_version_task_name'],
        'estimate' => intval($row['runbook_version_task_completion_estimate'])
    ];
}

?>

<div class="mb-3">
    <label>Template</label>
    <div class="input-group">
            <span class="input-group-text"><i class="fa fa-fw fa-cube"></i></span>
        <select class="form-select select2" id="ticket_template_select" name="ticket_template_id">
            <option value="0">- No Template -</option>
            <?php
            $sql_ticket_templates = mysqli_query(
                $mysqli,
                "SELECT ticket_template_id, ticket_template_name,
                    COALESCE(runbook_version_subject, ticket_template_subject) AS ticket_template_subject,
                    COALESCE(runbook_version_details, ticket_template_details) AS ticket_template_details,
                    runbook_version_id, runbook_version_number
                FROM ticket_templates
                LEFT JOIN runbook_versions
                    ON runbook_version_id = ticket_template_published_version_id
                    AND runbook_version_ticket_template_id = ticket_template_id
                WHERE (ticket_template_archived_at IS NULL OR ticket_template_id = $selected_ticket_template_id)
                $published_runbook_filter
                ORDER BY ticket_template_name ASC"
            );

            while ($row = mysqli_fetch_assoc($sql_ticket_templates)) {
                $ticket_template_id_select = intval($row['ticket_template_id']);
                $ticket_template_name_select = escapeHtml($row['ticket_template_name']);
                $ticket_template_subject_select = escapeHtml($row['ticket_template_subject']);
                $ticket_template_details_select = escapeHtml($row['ticket_template_details']);
                $ticket_template_task_list = $ticket_template_tasks[$ticket_template_id_select] ?? [];
                $task_count = count($ticket_template_task_list);
                $runbook_version_id = intval($row['runbook_version_id']);
                $runbook_version_number = intval($row['runbook_version_number']);
                ?>
                <option value="<?= $ticket_template_id_select ?>"
                        data-subject="<?= $ticket_template_subject_select ?>"
                        data-details="<?= $ticket_template_details_select ?>"
                        data-tasks="<?= escapeHtml(json_encode($ticket_template_task_list)) ?>"
                        data-runbook-version="<?= $runbook_version_id ?>"
                        <?php if ($selected_ticket_template_id == $ticket_template_id_select) { echo "selected"; } ?>>
                    <?= $ticket_template_name_select ?><?= $runbook_version_number ? ' · v' . $runbook_version_number : '' ?> (<?= $task_count ?> tasks)
                </option>
            <?php } ?>
        </select>
    </div>
    <small class="form-text text-muted">
        <?php if ($enable_runbook_workflow) { ?>
            Picking a template fills in the subject, details and tasks below. Published runbooks use their locked version; draft-only checklists remain editable.
        <?php } else { ?>
            Recurring schedules use editable checklist snapshots; published conditional runbooks are started from tickets or projects.
        <?php } ?>
    </small>
    <?php if ($enable_runbook_workflow) { ?>
        <input type="hidden" id="selectedRunbookVersion" name="runbook_version_id" value="0">
        <div class="alert alert-info py-2 mt-2 mb-0 d-none" id="runbookWorkflowLock">
            This is a published workflow. Its tasks, dependencies and controls will be instantiated from the displayed version.
        </div>
    <?php } ?>
</div>
