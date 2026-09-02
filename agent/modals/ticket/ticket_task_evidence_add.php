<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$task_id = intval($_GET['id']);
$task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, task_evidence_required,
    task_evidence_prompt, task_ticket_id, ticket_client_id,
    ticket_resolved_at, ticket_closed_at
    FROM tasks INNER JOIN tickets ON ticket_id = task_ticket_id AND ticket_deleted_at IS NULL
    WHERE task_id = $task_id LIMIT 1"));

if (!$task) {
    http_response_code(404);
    echo json_encode(['error' => 'Task not found']);
    exit;
}

$client_id = intval($task['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

$task_name = escapeHtml($task['task_name']);
$evidence_required = escapeHtml($task['task_evidence_required'] ?: 'none');
$evidence_prompt = escapeHtml($task['task_evidence_prompt']);
$evidence = mysqli_query($mysqli, "SELECT task_evidence.*, ticket_attachment_name, user_name
    FROM task_evidence
    LEFT JOIN ticket_attachments ON ticket_attachment_id = task_evidence_attachment_id
        AND ticket_attachment_ticket_id = " . intval($task['task_ticket_id']) . "
    LEFT JOIN users ON user_id = task_evidence_submitted_by
    WHERE task_evidence_task_id = $task_id
    ORDER BY task_evidence_created_at, task_evidence_id");
$ticket_id = intval($task['task_ticket_id']);
$linked_documentation = mysqli_query($mysqli, "SELECT
    link.ticket_documentation_obligation_id,
    obligation.documentation_obligation_id,
    version.documentation_requirement_version_name
    FROM ticket_documentation_obligations link
    INNER JOIN client_documentation_obligations obligation
        ON obligation.documentation_obligation_id = link.ticket_documentation_obligation_obligation_id
    INNER JOIN documentation_requirement_versions version
        ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
    WHERE link.ticket_documentation_obligation_ticket_id = $ticket_id
    AND link.ticket_documentation_obligation_task_id = $task_id
    AND link.ticket_documentation_obligation_client_id = $client_id
    ORDER BY version.documentation_requirement_version_name");
$available_documentation = mysqli_query($mysqli, "SELECT
    obligation.documentation_obligation_id,
    version.documentation_requirement_version_name,
    link.ticket_documentation_obligation_task_id
    FROM client_documentation_obligations obligation
    INNER JOIN documentation_requirement_versions version
        ON version.documentation_requirement_version_id = obligation.documentation_obligation_requirement_version_id
    LEFT JOIN ticket_documentation_obligations link
        ON link.ticket_documentation_obligation_ticket_id = $ticket_id
        AND link.ticket_documentation_obligation_obligation_id = obligation.documentation_obligation_id
    WHERE obligation.documentation_obligation_client_id = $client_id
    AND obligation.documentation_obligation_applicable = 1
    AND (link.ticket_documentation_obligation_id IS NULL
        OR link.ticket_documentation_obligation_task_id IN (0, $task_id))
    ORDER BY version.documentation_requirement_version_name");
$can_link_documentation = empty($task['ticket_resolved_at']) && empty($task['ticket_closed_at']);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-paperclip mr-2"></i>Evidence · <?= $task_name ?></h5>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body">
    <div class="alert alert-light border py-2">
        Required: <strong><?= ucfirst($evidence_required) ?></strong>
        <?php if ($evidence_prompt) { ?><div class="small mt-1"><?= $evidence_prompt ?></div><?php } ?>
    </div>

    <?php if (mysqli_num_rows($linked_documentation)) { ?>
        <div class="mb-3"><div class="small text-muted text-uppercase mb-1">Documentation obligations</div><?php while ($linked = mysqli_fetch_assoc($linked_documentation)) { ?><span class="badge badge-info mr-1"><?= escapeHtml($linked['documentation_requirement_version_name']) ?></span><?php } ?></div>
    <?php } ?>

    <?php if ($can_link_documentation) { ?>
        <form action="post.php" method="post" class="card bg-light mb-3">
            <div class="card-body py-2">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                <input type="hidden" name="task_id" value="<?= $task_id ?>">
                <div class="form-row align-items-end">
                    <div class="form-group col-md-9 mb-md-0"><label>Documentation affected by this task</label><select class="form-control select2" name="obligation_id" required><option value="">Select an applicable obligation</option><?php while ($available = mysqli_fetch_assoc($available_documentation)) { ?><option value="<?= intval($available['documentation_obligation_id']) ?>"><?= escapeHtml($available['documentation_requirement_version_name']) ?><?= intval($available['ticket_documentation_obligation_task_id']) === $task_id ? ' (linked)' : '' ?></option><?php } ?></select></div>
                    <div class="form-group col-md-3 mb-0"><button class="btn btn-outline-info btn-block" name="link_task_documentation_obligation"><i class="fas fa-link mr-1"></i>Link</button></div>
                </div>
                <small class="text-muted">The task link becomes part of the ticket documentation gate and Change Passport.</small>
            </div>
        </form>
    <?php } ?>

    <?php if (mysqli_num_rows($evidence)) { ?>
        <div class="list-group mb-3">
            <?php while ($item = mysqli_fetch_assoc($evidence)) {
                $evidence_id = intval($item['task_evidence_id']);
                $type = $item['task_evidence_type'];
                ?>
                <div class="list-group-item py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <span class="badge badge-secondary mr-1"><?= escapeHtml(ucfirst($type)) ?></span>
                            <?php if ($type === 'note') { ?><?= nl2br(escapeHtml($item['task_evidence_note'])) ?><?php } ?>
                            <?php if ($type === 'url') { ?><a href="<?= escapeHtml($item['task_evidence_url']) ?>" target="_blank" rel="noopener noreferrer"><?= escapeHtml($item['task_evidence_url']) ?></a><?php } ?>
                            <?php if ($type === 'file') { ?><a href="ticket_attachment.php?attachment_id=<?= intval($item['task_evidence_attachment_id']) ?>" target="_blank"><?= escapeHtml($item['ticket_attachment_name']) ?></a><?php } ?>
                            <div class="small text-muted mt-1"><?= escapeHtml($item['task_evidence_created_at']) ?><?= $item['user_name'] ? ' · ' . escapeHtml($item['user_name']) : '' ?></div>
                        </div>
                        <?php if (lookupUserPermission('module_support') >= 3) { ?>
                            <form action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="task_evidence_id" value="<?= $evidence_id ?>">
                                <button type="submit" name="delete_task_evidence" class="btn btn-sm btn-link text-danger" title="Remove evidence reference"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="task_id" value="<?= $task_id ?>">

        <div class="form-group">
            <label>Evidence Note</label>
            <textarea class="form-control" name="evidence_note" rows="3" maxlength="4000" placeholder="Record what was checked, the result, and any exception."></textarea>
        </div>
        <div class="form-group">
            <label>Evidence URL</label>
            <input type="url" class="form-control" name="evidence_url" maxlength="1000" placeholder="https://">
        </div>
        <div class="form-group">
            <label>Evidence File</label>
            <input type="file" class="form-control-file" name="evidence_files[]" multiple>
            <small class="form-text text-muted">Files are stored as protected ticket attachments and referenced by this task.</small>
        </div>

        <div class="text-right">
            <button type="submit" name="add_task_evidence" class="btn btn-primary"><i class="fas fa-check mr-2"></i>Add Evidence</button>
            <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
        </div>
    </form>
</div>

<?php

require_once '../../../includes/modal_footer.php';
