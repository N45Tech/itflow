<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$asset_ids = array_map('intval', $_GET['asset_ids'] ?? []);
$asset_ids = array_values(array_unique(array_filter($asset_ids)));

$count = count($asset_ids);
$asset_client_ids = [];
if ($asset_ids) {
    $asset_id_list = implode(',', $asset_ids);
    $sql_asset_clients = mysqli_query($mysqli, "SELECT DISTINCT asset_client_id FROM assets
        INNER JOIN clients ON client_id = asset_client_id
        WHERE asset_id IN ($asset_id_list) AND asset_archived_at IS NULL
        AND client_lead = 0 AND client_archived_at IS NULL "
        . clientScopeSql('asset_client_id'));
    while ($asset_client = mysqli_fetch_assoc($sql_asset_clients)) {
        $asset_client_id = intval($asset_client['asset_client_id']);
        if ($asset_client_id) {
            $asset_client_ids[] = $asset_client_id;
        }
    }
}
$single_client_id = count($asset_client_ids) === 1 ? intval($asset_client_ids[0]) : 0;

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-life-ring mr-2"></i>Create Tickets for <strong><?= $count ?></strong> Assets</h5>
    <button type="button" class="close text-white" data-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($asset_ids as $asset_id) { ?><input type="hidden" name="asset_ids[]" value="<?= $asset_id ?>"><?php } ?>

    <div class="modal-body">

        <div class="form-group">
            <label>Ticket Template</label>
            <select class="form-control select2" name="bulk_ticket_template_id" id="bulkAssetTicketTemplateSelect">
                <option value="0">- No Template -</option>
                <?php
                $sql_templates = mysqli_query($mysqli, "SELECT ticket_template_id,
                    ticket_template_name, ticket_template_published_version_id,
                    (SELECT COUNT(*) FROM runbook_versions history
                        WHERE history.runbook_version_ticket_template_id = ticket_template_id) AS runbook_version_count
                    FROM ticket_templates WHERE ticket_template_archived_at IS NULL
                    ORDER BY ticket_template_name ASC");
                while ($template = mysqli_fetch_assoc($sql_templates)) {
                    $template_id = intval($template['ticket_template_id']);
                    $template_name = escapeHtml($template['ticket_template_name']);
                    $published_version_id = intval($template['ticket_template_published_version_id']);
                    $unpublished_history = !$published_version_id && intval($template['runbook_version_count']) > 0;
                    $published_label = $published_version_id
                        ? ' — Published runbook'
                        : ($unpublished_history ? ' — Republish required' : ' — Legacy template');
                    ?>
                    <option value="<?= $template_id ?>" <?= $unpublished_history ? 'disabled' : '' ?>><?= $template_name . $published_label ?></option>
                <?php } ?>
            </select>
            <small class="form-text text-muted">Published runbooks use their immutable subject, details, and tasks. Legacy templates retain the entered subject and copy template details/tasks.</small>
        </div>

        <div class="form-group">
            <label>Subject <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="bulk_subject" id="bulkAssetTicketSubject" placeholder="Asset Name will be prepended to Subject" maxlength="200">
            </div>
            <small class="form-text text-muted">For a published runbook, the asset name is prepended to the immutable runbook subject.</small>
        </div>

        <div class="form-group">
            <textarea class="form-control tinymceTicket" id="textInput" name="bulk_details"></textarea>
        </div>

        <div class="row">

            <div class="col">
                <div class="form-group">
                    <label>Priority <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                        </div>
                        <select class="form-control select2" name="bulk_priority" required>
                            <option>Low</option>
                            <option>Medium</option>
                            <option>High</option>
                            <option>Urgent</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="col">
                <div class="form-group">
                    <label>Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <select class="form-control select2" name="bulk_category">
                            <option value="0">- Not Categorized -</option>
                            <?php
                            $sql_categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_categories)) {
                                $category_id = intval($row['category_id']);
                                $category_name = escapeHtml($row['category_name']);

                                ?>
                                <option value="<?= $category_id ?>"><?= $category_name ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>
            </div>

        </div>

        <div class="form-group">
            <label>Assign to</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                </div>
                <select class="form-control select2" name="bulk_assigned_to">
                    <option value="0">Not Assigned</option>
                    <?php

                    $sql = mysqli_query(
                        $mysqli,
                        "SELECT user_id, user_name FROM users
                        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC"
                    );
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $user_id = intval($row['user_id']);
                        $user_name = escapeHtml($row['user_name']); ?>
                        <option <?php if ($session_user_id == $user_id) { echo "selected"; } ?> value="<?= $user_id ?>"><?= $user_name ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Project</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-project-diagram"></i></span>
                </div>
                <?php if ($single_client_id) { ?>
                    <select class="form-control select2" name="bulk_project">
                        <option value="0">- None -</option>
                        <?php
                        $sql_projects = mysqli_query($mysqli, "SELECT project_id, project_name
                            FROM projects WHERE project_client_id = $single_client_id
                            AND project_completed_at IS NULL AND project_archived_at IS NULL "
                            . clientScopeSql('project_client_id') . " ORDER BY project_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_projects)) {
                            $project_id_select = intval($row['project_id']);
                            $project_name_select = escapeHtml($row['project_name']); ?>
                            <option value="<?= $project_id_select ?>"><?= $project_name_select ?></option>
                        <?php } ?>
                    </select>
                <?php } else { ?>
                    <input type="hidden" name="bulk_project" value="0">
                    <input type="text" class="form-control" value="Unavailable for a multi-client asset batch" disabled>
                <?php } ?>
            </div>
        </div>

        <div class="form-group">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" name="use_primary_contact" value="1" id="bulkAssetUsePrimaryContact">
                <label class="custom-control-label" for="bulkAssetUsePrimaryContact">Use each asset client's active primary contact</label>
            </div>
            <small class="form-text text-muted">An asset whose client has no active primary contact will be skipped.</small>
        </div>

        <?php if ($config_module_enable_accounting) { ?>
        <div class="form-group">
            <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" name="bulk_billable" <?php if ($config_ticket_default_billable == 1) { echo "checked"; } ?> value="1" id="billableSwitch">
                <label class="custom-control-label" for="billableSwitch">Billable</label>
            </div>
        </div>
        <?php } ?>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_add_asset_ticket" class="btn btn-primary text-bold"><i class="fas fa-check mr-2"></i>Create Tickets</button>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i class="fas fa-times mr-2"></i>Cancel</button>
    </div>
</form>

<script>
    (function () {
        const templateSelect = document.getElementById('bulkAssetTicketTemplateSelect');
        const subjectInput = document.getElementById('bulkAssetTicketSubject');
        if (!templateSelect || !subjectInput) {
            return;
        }
        const syncSubjectRequirement = function () {
            subjectInput.required = templateSelect.value === '0';
        };
        templateSelect.addEventListener('change', syncSubjectRequirement);
        syncSubjectRequirement();
    })();
</script>

<?php
require_once '../../../includes/modal_footer.php';
