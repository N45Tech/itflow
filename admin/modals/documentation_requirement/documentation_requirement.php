<?php

require_once '../../../includes/modal_header.php';
enforceAdminPermission();

$requirement_id = intval($_GET['id'] ?? 0);
$requirement = null;
$definition = [];
$selectors_text = '';
if ($requirement_id) {
    $requirement = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM documentation_requirements
        WHERE documentation_requirement_id = $requirement_id LIMIT 1"));
    if (!$requirement) {
        exit('<div class="modal-body"><div class="alert alert-danger mb-0">The documentation requirement is unavailable.</div></div>');
    }
    $definition = json_decode((string) $requirement['documentation_requirement_draft_definition'], true) ?: [];
    $selector_lines = [];
    foreach ((array) ($definition['selectors'] ?? []) as $selector) {
        if (is_array($selector) && isset($selector['dimension'], $selector['value'])) {
            $selector_lines[] = $selector['dimension'] . ':' . $selector['value'];
        }
    }
    $selectors_text = implode("\n", $selector_lines);
}

$value = static function ($key, $fallback = '') use ($definition) {
    return escapeHtml($definition[$key] ?? $fallback);
};
$checked = static function ($key, $fallback = false) use ($definition) {
    return array_key_exists($key, $definition) ? !empty($definition[$key]) : $fallback;
};

?>

<div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-book-medical mr-2"></i><?= $requirement_id ? 'Edit Requirement Draft' : 'New Documentation Requirement' ?></h5><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="requirement_id" value="<?= $requirement_id ?>">
    <input type="hidden" name="expected_revision" value="<?= intval($requirement['documentation_requirement_revision'] ?? 0) ?>">
    <div class="modal-body">
        <div class="form-row">
            <div class="form-group col-md-5"><label>Stable key</label><input class="form-control" name="key" value="<?= escapeHtml($requirement['documentation_requirement_key'] ?? '') ?>" pattern="[a-z0-9][a-z0-9_-]{1,99}" maxlength="100" required <?= $requirement_id ? 'readonly' : '' ?>><small class="form-text text-muted">Immutable machine identity, for example <code>network-core</code>.</small></div>
            <div class="form-group col-md-7"><label>Name</label><input class="form-control" name="name" value="<?= $value('name') ?>" maxlength="200" required></div>
        </div>
        <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="2" maxlength="2000"><?= $value('description') ?></textarea></div>
        <div class="form-row">
            <div class="form-group col-md-4"><label>Record type</label><select class="form-control" name="record_type"><?php foreach (['identity', 'network', 'backup', 'security', 'endpoint', 'vendor', 'agreement', 'portal', 'recovery', 'general'] as $record_type) { ?><option value="<?= $record_type ?>" <?= $value('record_type', 'general') === $record_type ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $record_type))) ?></option><?php } ?></select></div>
            <div class="form-group col-md-4"><label>Review cadence (days)</label><input class="form-control" type="number" name="review_cadence_days" min="1" max="3650" value="<?= intval($definition['review_cadence_days'] ?? 365) ?>" required></div>
            <div class="form-group col-md-4"><label>Warning window (days)</label><input class="form-control" type="number" name="warning_window_days" min="0" max="365" value="<?= intval($definition['warning_window_days'] ?? 30) ?>" required></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>Default owner role</label><select class="form-control" name="default_owner_role"><?php foreach (['documentation_owner', 'service_owner', 'account_manager', 'security_lead', 'support_lead', 'unassigned'] as $owner_role) { ?><option value="<?= $owner_role ?>" <?= $value('default_owner_role', 'documentation_owner') === $owner_role ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $owner_role))) ?></option><?php } ?></select></div>
            <div class="form-group col-md-6"><label>Default reviewer role</label><select class="form-control" name="default_reviewer_role"><?php foreach (['documentation_owner', 'service_owner', 'account_manager', 'security_lead', 'support_lead', 'unassigned'] as $reviewer_role) { ?><option value="<?= $reviewer_role ?>" <?= $value('default_reviewer_role', 'support_lead') === $reviewer_role ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $reviewer_role))) ?></option><?php } ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6"><label>Evidence policy</label><select class="form-control" name="evidence_policy"><?php foreach (['none', 'note', 'file', 'reference'] as $evidence_policy) { ?><option value="<?= $evidence_policy ?>" <?= $value('evidence_policy', 'reference') === $evidence_policy ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $evidence_policy))) ?></option><?php } ?></select></div>
            <div class="form-group col-md-6"><label>Exception approval</label><select class="form-control" name="exception_approval_policy"><option value="support3" <?= $value('exception_approval_policy', 'support3') === 'support3' ? 'selected' : '' ?>>Support level 3</option><option value="administrator" <?= $value('exception_approval_policy') === 'administrator' ? 'selected' : '' ?>>Administrator</option></select></div>
        </div>
        <div class="form-group"><label>Applicability selectors</label><textarea class="form-control text-monospace" name="selectors" rows="5" placeholder="always:any&#10;service:managed-backup"><?= escapeHtml($selectors_text) ?></textarea><small class="form-text text-muted">One <code>dimension:value</code> selector per line. Supported dimensions: always, active_contract, plan, service, service_category, asset_class, integration, and client_type.</small></div>
        <div class="form-group"><label>Selector mode</label><select class="form-control" name="applicability_mode"><option value="any" <?= $value('applicability_mode', 'any') === 'any' ? 'selected' : '' ?>>Any selector matches</option><option value="all" <?= $value('applicability_mode') === 'all' ? 'selected' : '' ?>>All selectors match</option></select></div>
        <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" name="blocks_readiness" value="1" id="documentationBlocksReadiness" <?= $checked('blocks_readiness', true) ? 'checked' : '' ?>><label class="custom-control-label" for="documentationBlocksReadiness">Blocks Readiness Index</label></div>
        <div class="custom-control custom-checkbox mt-2"><input class="custom-control-input" type="checkbox" name="blocks_ticket_resolution" value="1" id="documentationBlocksTicket" <?= $checked('blocks_ticket_resolution', true) ? 'checked' : '' ?>><label class="custom-control-label" for="documentationBlocksTicket">Blocks affected ticket resolution</label></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary" name="save_documentation_requirement"><i class="fas fa-save mr-1"></i>Save Draft</button><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button></div>
</form>
