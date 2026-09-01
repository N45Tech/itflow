<?php

require_once 'includes/inc_all.php';

$version_id = intval($_GET['version_id'] ?? 0);
$release = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT i.portal_request_catalog_item_published_version_id,
    i.portal_request_catalog_item_archived_at
    FROM portal_request_catalog_versions v
    INNER JOIN portal_request_catalog_items i
        ON i.portal_request_catalog_item_id = v.portal_request_catalog_version_item_id
    WHERE v.portal_request_catalog_version_id = $version_id LIMIT 1"));
try {
    $definition = portalRequestAssertVersion($version_id);
} catch (Throwable $exception) {
    error_log("Portal request catalog version $version_id could not be displayed: " . $exception->getMessage());
    $definition = null;
}
$contact_context = portalRequestContactContext($session_contact_id, $session_client_id);
if (!$release || !$definition || !empty($release['portal_request_catalog_item_archived_at'])
    || intval($release['portal_request_catalog_item_published_version_id']) !== $version_id
    || !portalRequestContactCanUse($definition, $contact_context, $session_client_id)) {
    flashAlert('That guided request is no longer available', 'warning');
    redirect('requests.php');
}
$idempotency_key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$can_select_contacts = intval($contact_context['contact_primary']) === 1
    || intval($contact_context['contact_portal_manage_contacts']) === 1
    || $contact_context['contact_portal_ticket_scope'] === 'client';
$contact_filter = $can_select_contacts ? '' : "AND contact_id = $session_contact_id";
$asset_filter = intval($contact_context['contact_primary']) === 1
    || $contact_context['contact_portal_asset_scope'] === 'client'
    ? '' : "AND asset_contact_id = $session_contact_id";
$field_types = array_column($definition['fields'], 'type');
$contact_options = [];
if (in_array('contact', $field_types, true)) {
    $contacts = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email FROM contacts
        WHERE contact_client_id = $session_client_id AND contact_archived_at IS NULL $contact_filter
        ORDER BY contact_name ASC");
    $contact_options = $contacts ? mysqli_fetch_all($contacts, MYSQLI_ASSOC) : [];
}
$asset_options = [];
if (in_array('asset', $field_types, true)) {
    $assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type FROM assets
        WHERE asset_client_id = $session_client_id AND asset_archived_at IS NULL $asset_filter
        ORDER BY asset_name ASC");
    $asset_options = $assets ? mysqli_fetch_all($assets, MYSQLI_ASSOC) : [];
}

?>

<ol class="breadcrumb"><li class="breadcrumb-item"><a href="requests.php">Request help</a></li><li class="breadcrumb-item active"><?= escapeHtml($definition['name']) ?></li></ol>
<div class="n45-form-surface">
    <div class="n45-form-intro">
        <i class="<?= escapeHtml($definition['icon']) ?> fa-2x text-primary mb-2" aria-hidden="true"></i>
        <h1><?= escapeHtml($definition['name']) ?></h1>
        <p><?= escapeHtml($definition['description']) ?></p>
        <?php if ($definition['instructions']) { ?><div class="alert alert-light"><?= nl2br(escapeHtml($definition['instructions'])) ?></div><?php } ?>
        <?php if ($definition['approval_rule'] !== 'none') { ?><p class="small text-muted"><i class="fas fa-user-check mr-1"></i>This request needs <?= escapeHtml(strtolower(portalRequestApprovalRules()[$definition['approval_rule']])) ?> before its ticket and workflow are created.</p><?php } ?>
    </div>
    <form action="post.php" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="version_id" value="<?= $version_id ?>">
        <input type="hidden" name="idempotency_key" value="<?= escapeHtml($idempotency_key) ?>">
        <?php foreach ($definition['fields'] as $field) {
            $id = 'requestField' . preg_replace('/[^a-zA-Z0-9]/', '', $field['key']);
            $name = 'field[' . $field['key'] . ']';
            $required = $field['required'] ? 'required' : '';
            ?>
            <div class="form-group">
                <?php if ($field['type'] === 'checkbox') { ?>
                    <div class="custom-control custom-checkbox">
                        <input class="custom-control-input" id="<?= escapeHtml($id) ?>" type="checkbox" name="<?= escapeHtml($name) ?>" value="1" <?= $required ?>>
                        <label class="custom-control-label" for="<?= escapeHtml($id) ?>"><?= escapeHtml($field['label']) ?><?= $field['required'] ? ' *' : '' ?></label>
                    </div>
                <?php } else { ?>
                    <label for="<?= escapeHtml($id) ?>"><?= escapeHtml($field['label']) ?><?= $field['required'] ? ' <strong class="text-danger">*</strong>' : '' ?></label>
                    <?php if ($field['type'] === 'textarea') { ?>
                        <textarea class="form-control" id="<?= escapeHtml($id) ?>" name="<?= escapeHtml($name) ?>" rows="4" maxlength="<?= intval($field['max_length']) ?>" <?= $required ?>></textarea>
                    <?php } elseif ($field['type'] === 'select') { ?>
                        <select class="form-control" id="<?= escapeHtml($id) ?>" name="<?= escapeHtml($name) ?>" <?= $required ?>><option value="">Choose…</option><?php foreach ($field['options'] as $option) { ?><option value="<?= escapeHtml($option) ?>"><?= escapeHtml($option) ?></option><?php } ?></select>
                    <?php } elseif ($field['type'] === 'asset') { ?>
                        <select class="form-control" id="<?= escapeHtml($id) ?>" name="<?= escapeHtml($name) ?>" <?= $required ?>><option value="">Choose a device…</option><?php foreach ($asset_options as $asset) { ?><option value="<?= intval($asset['asset_id']) ?>"><?= escapeHtml($asset['asset_name'] . ' (' . $asset['asset_type'] . ')') ?></option><?php } ?></select>
                    <?php } elseif ($field['type'] === 'contact') { ?>
                        <select class="form-control" id="<?= escapeHtml($id) ?>" name="<?= escapeHtml($name) ?>" <?= $required ?>><option value="">Choose a person…</option><?php foreach ($contact_options as $contact) { ?><option value="<?= intval($contact['contact_id']) ?>"><?= escapeHtml($contact['contact_name'] . ($contact['contact_email'] ? ' (' . $contact['contact_email'] . ')' : '')) ?></option><?php } ?></select>
                    <?php } else {
                        $input_type = ['email' => 'email', 'phone' => 'tel', 'integer' => 'number', 'date' => 'date', 'datetime' => 'datetime-local'][$field['type']] ?? 'text';
                        ?>
                        <input class="form-control" id="<?= escapeHtml($id) ?>" type="<?= escapeHtml($input_type) ?>" name="<?= escapeHtml($name) ?>" maxlength="<?= intval($field['max_length']) ?>" <?= $field['min_value'] !== null ? 'min="' . intval($field['min_value']) . '"' : '' ?> <?= $field['max_value'] !== null ? 'max="' . intval($field['max_value']) . '"' : '' ?> <?= $required ?>>
                    <?php } ?>
                <?php } ?>
                <?php if ($field['help']) { ?><small class="form-text text-muted"><?= escapeHtml($field['help']) ?></small><?php } ?>
            </div>
        <?php } ?>
        <div class="n45-form-actions"><button class="btn btn-primary" name="submit_portal_request"><i class="far fa-paper-plane mr-1"></i>Submit request</button><a class="btn btn-secondary" href="requests.php">Cancel</a></div>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
