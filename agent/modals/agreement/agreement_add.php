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
    <h5 class="modal-title">New agreement</h5>
    <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close dialog"><span aria-hidden="true">&times;</span></button>
</div>
<div class="modal-body">
    <p>Agreement setup now brings coverage, SLA targets, support hours and business reviews together on one guided page.</p>
    <p class="mb-0">Save a complete draft, then review it before activation.</p>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Cancel</button>
    <a class="btn btn-primary" href="agreement_create.php<?= $client_id > 0 ? '?client_id=' . $client_id : '' ?>">Continue to agreement setup</a>
</div>
<?php require_once '../../../includes/modal_footer.php'; ?>
