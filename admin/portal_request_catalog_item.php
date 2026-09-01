<?php

require_once 'includes/inc_all_admin.php';

$item_id = intval($_GET['item_id'] ?? 0);
$item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT i.*,
    v.portal_request_catalog_version_number,
    v.portal_request_catalog_version_definition_hash,
    v.portal_request_catalog_version_created_at
    FROM portal_request_catalog_items i
    LEFT JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = i.portal_request_catalog_item_published_version_id
        AND v.portal_request_catalog_version_item_id = i.portal_request_catalog_item_id
    WHERE i.portal_request_catalog_item_id = $item_id LIMIT 1"));
if (!$item) {
    echo "<div class='alert alert-warning'>Request catalog item not found.</div>";
    require_once '../includes/footer.php';
    exit;
}

$draft = portalRequestDraftDefinition($item_id);
$draft_errors = portalRequestValidateDefinition($draft);
$draft_hash = $draft ? portalRequestDefinitionHash($draft) : '';
$published_hash = (string) ($item['portal_request_catalog_version_definition_hash'] ?? '');
$has_changes = !$published_hash || !hash_equals($published_hash, $draft_hash);
$is_archived = !empty($item['portal_request_catalog_item_archived_at']);
$fields = mysqli_query($mysqli, "SELECT * FROM portal_request_catalog_fields
    WHERE portal_request_catalog_field_item_id = $item_id
    ORDER BY portal_request_catalog_field_order ASC, portal_request_catalog_field_id ASC");
$templates = mysqli_query($mysqli, "SELECT ticket_template_id, ticket_template_name,
    ticket_template_published_version_id, runbook_version_number
    FROM ticket_templates
    INNER JOIN runbook_versions ON runbook_version_id = ticket_template_published_version_id
        AND runbook_version_ticket_template_id = ticket_template_id
    WHERE ticket_template_archived_at IS NULL
    ORDER BY ticket_template_name ASC");
$categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories
    WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name ASC");
$versions = mysqli_query($mysqli, "SELECT v.*, u.user_name,
    (SELECT COUNT(*) FROM portal_request_submissions s
        WHERE s.portal_request_submission_version_id = v.portal_request_catalog_version_id) AS use_count
    FROM portal_request_catalog_versions v
    LEFT JOIN users u ON u.user_id = v.portal_request_catalog_version_created_by
    WHERE v.portal_request_catalog_version_item_id = $item_id
    ORDER BY v.portal_request_catalog_version_number DESC");

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="portal_request_catalog.php">Portal Request Catalog</a></li>
    <li class="breadcrumb-item active"><?= escapeHtml($item['portal_request_catalog_item_name']) ?></li>
</ol>

<?php if (!empty($item['portal_request_catalog_item_archived_at'])) { ?>
    <div class="alert alert-warning">This request is archived and cannot be published or used in the portal.</div>
<?php } ?>

<div class="row">
    <div class="col-xl-7">
        <div class="card card-outline <?= $has_changes ? 'card-warning' : 'card-success' ?>">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-code-branch mr-2"></i><?= intval($item['portal_request_catalog_version_number']) ? 'Published v' . intval($item['portal_request_catalog_version_number']) : 'Unpublished draft' ?></h3>
                <div class="card-tools"><code><?= escapeHtml($item['portal_request_catalog_item_key']) ?></code></div>
            </div>
            <div class="card-body">
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <div class="row">
                        <div class="form-group col-md-8"><label>Name</label><input class="form-control" name="name" maxlength="200" required value="<?= escapeHtml($item['portal_request_catalog_item_name']) ?>"></div>
                        <div class="form-group col-md-4"><label>Type</label><select class="form-control" name="type"><?php foreach (portalRequestTypes() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>" <?= $item['portal_request_catalog_item_type'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
                    </div>
                    <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="2" maxlength="5000"><?= escapeHtml($item['portal_request_catalog_item_description']) ?></textarea></div>
                    <div class="form-group"><label>Requester instructions</label><textarea class="form-control" name="instructions" rows="2" maxlength="5000"><?= escapeHtml($item['portal_request_catalog_item_instructions']) ?></textarea></div>
                    <div class="row">
                        <div class="form-group col-md-6"><label>Published runbook</label><select class="form-control" name="ticket_template_id" required><option value="0">Select a published runbook</option><?php while ($template = mysqli_fetch_assoc($templates)) { ?><option value="<?= intval($template['ticket_template_id']) ?>" <?= intval($item['portal_request_catalog_item_ticket_template_id']) === intval($template['ticket_template_id']) ? 'selected' : '' ?>><?= escapeHtml($template['ticket_template_name']) ?> (v<?= intval($template['runbook_version_number']) ?>)</option><?php } ?></select></div>
                        <div class="form-group col-md-6"><label>Ticket category</label><select class="form-control" name="category_id"><option value="0">No category</option><?php while ($category = mysqli_fetch_assoc($categories)) { ?><option value="<?= intval($category['category_id']) ?>" <?= intval($item['portal_request_catalog_item_category_id']) === intval($category['category_id']) ? 'selected' : '' ?>><?= escapeHtml($category['category_name']) ?></option><?php } ?></select></div>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-4"><label>Portal permission</label><select class="form-control" name="permission_rule"><?php foreach (portalRequestPermissionRules() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>" <?= $item['portal_request_catalog_item_permission_rule'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-4"><label>Applicability</label><select class="form-control" name="applicability_rule"><?php foreach (portalRequestApplicabilityRules() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>" <?= $item['portal_request_catalog_item_applicability_rule'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-4"><label>Pre-approval</label><select class="form-control" name="approval_rule"><?php foreach (portalRequestApprovalRules() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>" <?= $item['portal_request_catalog_item_approval_rule'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-6"><label>Applicability value</label><input class="form-control" name="applicability_value" maxlength="255" value="<?= escapeHtml($item['portal_request_catalog_item_applicability_value']) ?>"><small class="form-text text-muted">Exact service/category or comma-separated client IDs.</small></div>
                        <div class="form-group col-md-3"><label>Icon classes</label><input class="form-control" name="icon" maxlength="60" value="<?= escapeHtml($item['portal_request_catalog_item_icon']) ?>"></div>
                        <div class="form-group col-md-3"><label>Sort order</label><input class="form-control" type="number" name="sort_order" value="<?= intval($item['portal_request_catalog_item_order']) ?>"></div>
                    </div>
                    <button class="btn btn-primary" name="update_portal_request_catalog_item" <?= $is_archived ? 'disabled' : '' ?>><i class="fas fa-save mr-1"></i>Save draft</button>
                </form>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-list mr-2"></i>Typed fields</h3></div>
            <div class="card-body">
                <?php while ($field = mysqli_fetch_assoc($fields)) {
                    $field_id = intval($field['portal_request_catalog_field_id']);
                    $options = implode("\n", portalRequestOptions($field['portal_request_catalog_field_options']));
                    ?>
                    <details class="border rounded p-2 mb-2">
                        <summary><strong><?= escapeHtml($field['portal_request_catalog_field_label']) ?></strong> <code><?= escapeHtml($field['portal_request_catalog_field_key']) ?></code> <span class="badge badge-secondary"><?= escapeHtml($field['portal_request_catalog_field_type']) ?></span></summary>
                        <form action="post.php" method="post" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="item_id" value="<?= $item_id ?>"><input type="hidden" name="field_id" value="<?= $field_id ?>">
                            <div class="row">
                                <div class="form-group col-md-4"><label>Key</label><input class="form-control" name="field_key" required value="<?= escapeHtml($field['portal_request_catalog_field_key']) ?>"></div>
                                <div class="form-group col-md-5"><label>Label</label><input class="form-control" name="label" required value="<?= escapeHtml($field['portal_request_catalog_field_label']) ?>"></div>
                                <div class="form-group col-md-3"><label>Type</label><select class="form-control" name="field_type"><?php foreach (portalRequestFieldTypes() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>" <?= $field['portal_request_catalog_field_type'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
                            </div>
                            <div class="form-group"><label>Help text</label><input class="form-control" name="help" maxlength="500" value="<?= escapeHtml($field['portal_request_catalog_field_help']) ?>"></div>
                            <div class="row">
                                <div class="form-group col-md-5"><label>Choice options (one per line)</label><textarea class="form-control" name="options" rows="2"><?= escapeHtml($options) ?></textarea></div>
                                <div class="form-group col-md-2"><label>Max length</label><input class="form-control" type="number" name="max_length" min="1" max="10000" value="<?= intval($field['portal_request_catalog_field_max_length']) ?>"></div>
                                <div class="form-group col-md-2"><label>Min number</label><input class="form-control" type="number" name="min_value" value="<?= $field['portal_request_catalog_field_min_value'] === null ? '' : intval($field['portal_request_catalog_field_min_value']) ?>"></div>
                                <div class="form-group col-md-2"><label>Max number</label><input class="form-control" type="number" name="max_value" value="<?= $field['portal_request_catalog_field_max_value'] === null ? '' : intval($field['portal_request_catalog_field_max_value']) ?>"></div>
                                <div class="form-group col-md-1"><label>Order</label><input class="form-control" type="number" name="field_order" value="<?= intval($field['portal_request_catalog_field_order']) ?>"></div>
                            </div>
                            <div class="custom-control custom-checkbox mb-3"><input class="custom-control-input" id="required<?= $field_id ?>" type="checkbox" name="required" <?= intval($field['portal_request_catalog_field_required']) ? 'checked' : '' ?>><label class="custom-control-label" for="required<?= $field_id ?>">Required</label></div>
                            <button class="btn btn-sm btn-primary" name="save_portal_request_catalog_field" <?= $is_archived ? 'disabled' : '' ?>>Save field</button>
                            <button class="btn btn-sm btn-outline-danger float-right confirm-link" name="delete_portal_request_catalog_field" <?= $is_archived ? 'disabled' : '' ?>>Delete field</button>
                        </form>
                    </details>
                <?php } ?>
                <hr>
                <h5>Add field</h5>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <div class="row">
                        <div class="form-group col-md-3"><input class="form-control" name="field_key" required placeholder="stable_key"></div>
                        <div class="form-group col-md-4"><input class="form-control" name="label" required placeholder="Field label"></div>
                        <div class="form-group col-md-3"><select class="form-control" name="field_type"><?php foreach (portalRequestFieldTypes() as $value => $label) { ?><option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-2"><input class="form-control" type="number" name="field_order" value="10" aria-label="Order"></div>
                    </div>
                    <div class="row"><div class="form-group col-md-7"><input class="form-control" name="help" maxlength="500" placeholder="Help text"></div><div class="form-group col-md-3"><input class="form-control" type="number" name="max_length" min="1" max="10000" value="255"></div><div class="form-group col-md-2 pt-2"><label><input type="checkbox" name="required"> Required</label></div></div>
                    <div class="form-group"><textarea class="form-control" name="options" rows="2" placeholder="Choice options, one per line (select fields only)"></textarea></div>
                    <button class="btn btn-outline-primary" name="save_portal_request_catalog_field" <?= $is_archived ? 'disabled' : '' ?>><i class="fas fa-plus mr-1"></i>Add field</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card card-outline card-primary">
            <div class="card-header"><h3 class="card-title">Publish release</h3></div>
            <div class="card-body">
                <?php if ($draft_errors) { ?><div class="alert alert-warning"><strong>Not publishable:</strong><ul class="mb-0"><?php foreach ($draft_errors as $error) { ?><li><?= escapeHtml($error) ?></li><?php } ?></ul></div><?php } ?>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <div class="form-group"><label>Release notes</label><input class="form-control" name="version_notes" maxlength="255"></div>
                    <button class="btn btn-primary" name="publish_portal_request_catalog_item" <?= ($draft_errors || !$has_changes || !empty($item['portal_request_catalog_item_archived_at'])) ? 'disabled' : '' ?>><i class="fas fa-upload mr-1"></i>Publish immutable version</button>
                </form>
            </div>
        </div>
        <div class="card card-secondary">
            <div class="card-header"><h3 class="card-title">Version history</h3></div>
            <div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
            <?php while ($version = mysqli_fetch_assoc($versions)) { ?>
                <tr><td class="pl-3"><strong>v<?= intval($version['portal_request_catalog_version_number']) ?></strong><?php if (intval($version['portal_request_catalog_version_id']) === intval($item['portal_request_catalog_item_published_version_id'])) { ?> <span class="badge badge-success">Current</span><?php } ?><div class="small text-muted"><?= escapeHtml($version['portal_request_catalog_version_created_at']) ?> · <?= intval($version['use_count']) ?> uses</div><?php if ($version['portal_request_catalog_version_notes']) { ?><div class="small"><?= escapeHtml($version['portal_request_catalog_version_notes']) ?></div><?php } ?></td><td class="text-right pr-3"><code><?= escapeHtml(substr($version['portal_request_catalog_version_definition_hash'], 0, 12)) ?></code></td></tr>
            <?php } ?>
            </tbody></table></div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
