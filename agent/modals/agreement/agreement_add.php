<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);
$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id > 0) {
    enforceClientAccess($client_id);
}

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-file-contract mr-2"></i>New Agreement</h5>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <?php if ($client_id > 0) { ?>
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
        <?php } else { ?>
            <div class="form-group">
                <label>Client <strong class="text-danger">*</strong></label>
                <select class="form-control select2" name="client_id" required>
                    <option value="">- Select Client -</option>
                    <?php
                    $clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients
                        WHERE client_archived_at IS NULL " . clientScopeSql('clients.client_id') . "
                        ORDER BY client_name");
                    while ($client = mysqli_fetch_assoc($clients)) { ?>
                        <option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        <?php } ?>

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="255" required
                placeholder="Managed Services Agreement">
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Type</label>
                <select class="form-control" name="type">
                    <option>Fully Managed</option>
                    <option>Partially Managed</option>
                    <option>Project</option>
                    <option>Break/Fix</option>
                    <option>Other</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Support hours</label>
                <input type="text" class="form-control" name="support_hours" maxlength="100"
                    placeholder="Mon-Fri 08:00-17:00">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Effective from</label>
                <input type="date" class="form-control" name="effective_from">
            </div>
            <div class="form-group col-md-6">
                <label>Effective until</label>
                <input type="date" class="form-control" name="effective_until">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Review cadence</label>
                <div class="input-group">
                    <input type="number" class="form-control" name="review_cadence_months" value="3" min="1" max="24" required>
                    <div class="input-group-append"><span class="input-group-text">months</span></div>
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Renewal notice</label>
                <div class="input-group">
                    <input type="number" class="form-control" name="renewal_notice_days" value="90" min="0" max="365" required>
                    <div class="input-group-append"><span class="input-group-text">days</span></div>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Operational details</label>
            <textarea class="form-control" name="details" rows="4" placeholder="Scope assumptions, escalation terms, or review notes"></textarea>
        </div>
        <small class="text-muted">This creates an editable draft. Add entitlements and SLA rules, then publish the version to make it active and immutable.</small>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_agreement" class="btn btn-primary text-bold"><i class="fas fa-check mr-2"></i>Create Draft</button>
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
    </div>
</form>

<?php require_once '../../../includes/modal_footer.php'; ?>
