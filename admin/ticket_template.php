<?php

require_once "includes/inc_all_admin.php";


//Initialize the HTML Purifier to prevent XSS
require "../libs/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

if (isset($_GET['ticket_template_id'])) {
    $ticket_template_id = intval($_GET['ticket_template_id']);
}

$sql_ticket_template = mysqli_query($mysqli, "SELECT ticket_template_created_at, ticket_template_description, ticket_template_details,
    ticket_template_name, ticket_template_subject, ticket_template_updated_at,
    ticket_template_runbook_key, ticket_template_runbook_type, ticket_template_published_version_id
    FROM ticket_templates WHERE ticket_template_id = $ticket_template_id LIMIT 1");

if (mysqli_num_rows($sql_ticket_template) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit();
}

$row = mysqli_fetch_assoc($sql_ticket_template);

$ticket_template_name = escapeHtml($row['ticket_template_name']);
$ticket_template_description = escapeHtml($row['ticket_template_description']);
$ticket_template_subject = escapeHtml($row['ticket_template_subject']);
$ticket_template_details = $purifier->purify($row['ticket_template_details']);
$ticket_template_created_at = escapeHtml($row['ticket_template_created_at']);
$ticket_template_updated_at = escapeHtml($row['ticket_template_updated_at']);
$ticket_template_runbook_key = escapeHtml($row['ticket_template_runbook_key'] ?: runbookNormalizeKey($row['ticket_template_name'], 'runbook'));
$ticket_template_runbook_type = in_array($row['ticket_template_runbook_type'], ['standard', 'onboarding', 'offboarding'], true)
    ? $row['ticket_template_runbook_type'] : 'standard';
$ticket_template_published_version_id = intval($row['ticket_template_published_version_id']);

$published_version = null;
if ($ticket_template_published_version_id) {
    $published_version = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_version_id,
        runbook_version_number, runbook_version_definition_hash, runbook_version_created_at,
        runbook_version_notes, user_name
        FROM runbook_versions
        LEFT JOIN users ON user_id = runbook_version_created_by
        WHERE runbook_version_id = $ticket_template_published_version_id
        AND runbook_version_ticket_template_id = $ticket_template_id LIMIT 1"));
}
$draft_definition = runbookDraftDefinition($ticket_template_id);
$draft_hash = $draft_definition ? runbookDefinitionHash($draft_definition) : '';
$draft_errors = $draft_definition ? runbookValidateDefinition($draft_definition) : ['Template not found.'];
$has_unpublished_changes = !$published_version
    || !hash_equals($published_version['runbook_version_definition_hash'], $draft_hash);
$sql_version_history = mysqli_query($mysqli, "SELECT runbook_version_id, runbook_version_number,
    runbook_version_definition_hash, runbook_version_created_at, runbook_version_notes, user_name,
    (SELECT COUNT(*) FROM runbook_executions WHERE runbook_execution_version_id = runbook_version_id) AS execution_count
    FROM runbook_versions
    LEFT JOIN users ON user_id = runbook_version_created_by
    WHERE runbook_version_ticket_template_id = $ticket_template_id
    ORDER BY runbook_version_number DESC");

// Get Task Templates
$sql_task_templates = mysqli_query($mysqli, "SELECT task_templates.*,
    user_name,
    (SELECT COUNT(*) FROM task_template_dependencies WHERE task_template_id = task_templates.task_template_id) AS dependency_count
    FROM task_templates
    LEFT JOIN users ON user_id = task_template_owner_user_id
    WHERE task_template_ticket_template_id = $ticket_template_id
    ORDER BY task_template_order ASC, task_template_id ASC");

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item">
        <a href="../index.php">Home</a>
    </li>
    <li class="breadcrumb-item">
        <a href="users.php">Admin</a>
    </li>
    <li class="breadcrumb-item">
        <a href="ticket_templates.php">Ticket Templates</a>
    </li>
    <li class="breadcrumb-item active"><i class="fas fa-life-ring me-2"></i><?= $ticket_template_name ?></li>
</ol>

<div class="row">
    <div class="col-md-8">

        <div class="card card-outline <?= $has_unpublished_changes ? 'card-warning' : 'card-success' ?>">
            <div class="card-header">
                <h3 class="card-title mt-1">
                    <i class="fas fa-code-branch mr-2"></i>
                    <?= $published_version ? 'Published v' . intval($published_version['runbook_version_number']) : 'Unpublished runbook' ?>
                </h3>
                <div class="card-tools">
                    <span class="badge badge-light mr-1"><?= escapeHtml(ucfirst($ticket_template_runbook_type)) ?></span>
                    <span class="badge <?= $has_unpublished_changes ? 'badge-warning' : 'badge-success' ?>">
                        <?= $has_unpublished_changes ? 'Draft changes' : 'Draft matches published' ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-5">
                        <div class="small text-muted">Stable key</div>
                        <code><?= $ticket_template_runbook_key ?></code>
                        <?php if ($published_version) { ?>
                            <div class="small text-muted mt-2">Published <?= escapeHtml($published_version['runbook_version_created_at']) ?><?= $published_version['user_name'] ? ' by ' . escapeHtml($published_version['user_name']) : '' ?></div>
                            <div class="small text-muted">Definition <?= escapeHtml(substr($published_version['runbook_version_definition_hash'], 0, 12)) ?></div>
                        <?php } ?>
                    </div>
                    <div class="col-lg-7">
                        <form action="post.php" method="post" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="ticket_template_id" value="<?= $ticket_template_id ?>">
                            <label class="sr-only" for="versionNotes">Version notes</label>
                            <div class="input-group">
                                <input id="versionNotes" type="text" class="form-control" name="version_notes" maxlength="255" placeholder="What changed in this version?">
                                <div class="input-group-append">
                                    <button type="submit" name="publish_ticket_template" class="btn btn-primary" <?= ($draft_errors || !$has_unpublished_changes) ? 'disabled' : '' ?>>
                                        <i class="fas fa-upload mr-1"></i>Publish
                                    </button>
                                </div>
                            </div>
                            <?php if ($draft_errors) { ?>
                                <small class="form-text text-danger"><?= escapeHtml($draft_errors[0]) ?></small>
                            <?php } else { ?>
                                <small class="form-text text-muted">Publishing freezes the current details, ordered tasks and workflow controls for new executions.</small>
                            <?php } ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header">
                <h3 class="card-title mt-1"><?= $ticket_template_name ?></h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool btn-sm" data-bs-toggle="modal" data-bs-target="#editTicketTemplateModal">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
            </div>
            <div class="card-body prettyContent">
                <?= $ticket_template_details ?>
            </div>
        </div>

        <?php if (mysqli_num_rows($sql_version_history)) { ?>
            <div class="card card-outline card-secondary">
                <div class="card-header">
                    <h5 class="card-title"><i class="fas fa-history mr-2"></i>Version History</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php while ($version = mysqli_fetch_assoc($sql_version_history)) {
                            $version_id = intval($version['runbook_version_id']);
                            $version_number = intval($version['runbook_version_number']);
                            ?>
                            <tr>
                                <td class="pl-3">
                                    <strong>v<?= $version_number ?></strong>
                                    <?php if ($version_id === $ticket_template_published_version_id) { ?><span class="badge badge-success ml-1">Current</span><?php } ?>
                                    <div class="small text-muted"><?= escapeHtml($version['runbook_version_created_at']) ?> · <?= intval($version['execution_count']) ?> run<?= intval($version['execution_count']) === 1 ? '' : 's' ?></div>
                                    <?php if ($version['runbook_version_notes']) { ?><div class="small"><?= escapeHtml($version['runbook_version_notes']) ?></div><?php } ?>
                                </td>
                                <td class="text-right pr-3 align-middle">
                                    <form action="post.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="ticket_template_id" value="<?= $ticket_template_id ?>">
                                        <input type="hidden" name="runbook_version_id" value="<?= $version_id ?>">
                                        <button type="submit" name="restore_ticket_template_version" class="btn btn-sm btn-outline-secondary" title="Restore v<?= $version_number ?> into the editable draft">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php } ?>

    </div>

    <div class="col-md-4">

        <div class="card card-dark">
            <div class="card-header">
                <h5 class="card-title"><i class="fa fa-fw fa-tasks me-2"></i>Tasks</h5>
            </div>
            <div class="card-body">
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="ticket_template_id" value="<?= $ticket_template_id ?>">
                    <div class="mb-3">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="task_name" placeholder="Create a task" required maxlength="200">
                                <button type="submit" name="add_ticket_template_task" class="btn btn-primary"><i class="fas fa-fw fa-check"></i></button>
                        </div>
                    </div>
                </form>
                <table class="table table-sm" id="tasks">
                    <?php
                    while($row = mysqli_fetch_assoc($sql_task_templates)){
                        $task_id = intval($row['task_template_id']);
                        $task_name = escapeHtml($row['task_template_name']);
                        $task_completion_estimate = intval($row['task_template_completion_estimate']);
                        $task_key = escapeHtml($row['task_template_key']);
                        $condition_type = $row['task_template_condition_type'];
                        $condition_value = escapeHtml($row['task_template_condition_value']);
                        $owner_type = $row['task_template_owner_type'];
                        $owner_name = escapeHtml($row['user_name']);
                        $due_offset_minutes = intval($row['task_template_due_offset_minutes']);
                        $initial_state = $row['task_template_initial_state'];
                        $approval_scope = $row['task_template_approval_scope'];
                        $evidence_type = $row['task_template_evidence_type'];
                        $dependency_count = intval($row['dependency_count']);
                        ?>
                        <tr data-task-id="<?= $task_id ?>">
                            <td>
                                <a href="#" class="drag-handle"><i class="fas fa-bars text-muted me-2"></i></a>
                                <span class="text-dark"><?= $task_name ?></span>
                                <div class="mt-1 ml-4 small">
                                    <code><?= $task_key ?></code>
                                    <?php if ($dependency_count) { ?><span class="badge badge-secondary ml-1"><i class="fas fa-lock mr-1"></i><?= $dependency_count ?> prerequisite<?= $dependency_count === 1 ? '' : 's' ?></span><?php } ?>
                                    <?php if ($condition_type !== 'always') { ?><span class="badge badge-info ml-1"><?= escapeHtml(runbookConditionTypes()[$condition_type] ?? $condition_type) ?><?= $condition_value ? ': ' . $condition_value : '' ?></span><?php } ?>
                                    <?php if ($owner_type !== 'unassigned') { ?><span class="badge badge-primary ml-1"><i class="fas fa-user mr-1"></i><?= $owner_type === 'specific_user' ? ($owner_name ?: 'Specific agent') : escapeHtml(runbookOwnerTypes()[$owner_type] ?? $owner_type) ?></span><?php } ?>
                                    <?php if ($due_offset_minutes) { ?><span class="badge badge-light ml-1"><i class="far fa-clock mr-1"></i><?= round($due_offset_minutes / 60, 1) ?>h</span><?php } ?>
                                    <?php if ($initial_state === 'Waiting') { ?><span class="badge badge-warning ml-1">Starts waiting</span><?php } ?>
                                    <?php if ($approval_scope) { ?><span class="badge badge-warning ml-1"><i class="fas fa-shield-alt mr-1"></i><?= escapeHtml(ucfirst($approval_scope)) ?> approval</span><?php } ?>
                                    <?php if ($evidence_type !== 'none') { ?><span class="badge badge-success ml-1"><i class="fas fa-paperclip mr-1"></i><?= escapeHtml(ucfirst($evidence_type)) ?> evidence</span><?php } ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="float-end">
                                    <div class="dropdown dropstart text-center">
                                        <button class="btn btn-light text-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-url="modals/ticket_template/ticket_template_task_edit.php?id=<?= $task_id ?>">
                                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?delete_task_template=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </table>
            </div>
        </div>

    </div>

</div>

<script src="../js/pretty_content.js"></script>

<script src="../libs/SortableJS/Sortable.min.js"></script>
<script>
const taskTableBody = document.querySelector('table#tasks tbody');
if (taskTableBody) {
new Sortable(taskTableBody, {
    handle: '.drag-handle',
    animation: 150,
    onEnd: function (evt) {
        const rows = document.querySelectorAll('table#tasks tbody tr');
        const positions = Array.from(rows).map((row, index) => ({
            id: row.dataset.taskId,
            order: index
        }));

        itflowPostForm('/agent/ajax.php', {
            update_task_templates_order: true,
            csrf_token: '<?= $_SESSION['csrf_token'] ?>',
            ticket_template_id: <?= $ticket_template_id ?>,
            positions: positions
        });
    }
});
}
</script>

<?php

require_once "modals/ticket_template/ticket_template_edit.php";
require_once "../includes/footer.php";
