<?php

require_once '../../../includes/modal_header.php';
enforceUserPermission('module_support', 2);
$ticket_id = intval($_GET['id'] ?? 0);
$scope = clientScopeSql('ticket_client_id');
$active_ticket_scope = ticketOperationalActiveTicketSql('tickets');
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id,
    ticket_prefix, ticket_number, ticket_work_type, ticket_resolution_code,
    ticket_resolution_summary, ticket_root_cause FROM tickets
    WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
    AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL $scope $active_ticket_scope LIMIT 1"));
if (!$ticket) { exit; }
$client_id = intval($ticket['ticket_client_id']);
if ($client_id) { enforceClientAccess($client_id); }
ob_start();
?>
<div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-check mr-2"></i>Resolve <?= escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']) ?></h5><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
<form action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <div class="form-group"><label>Resolution code</label><select class="form-control" name="resolution_code" required><option value="">Select a resolution</option><?php foreach (ticketOperationalResolutionCodes() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>" <?= $ticket['ticket_resolution_code'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div>
        <div class="form-group"><label>Resolution summary</label><textarea class="form-control" name="resolution_summary" rows="4" required><?= escapeHtml($ticket['ticket_resolution_summary']) ?></textarea></div>
        <div class="form-group"><label>Root cause<?= $ticket['ticket_work_type'] === 'problem' ? ' (required for problem tickets)' : '' ?></label><textarea class="form-control" name="root_cause" rows="3" <?= $ticket['ticket_work_type'] === 'problem' ? 'required' : '' ?>><?= escapeHtml($ticket['ticket_root_cause']) ?></textarea></div>
        <p class="small text-muted mb-0">Open tasks, approvals, required documentation/evidence, waiting state, and duplicate linkage are checked again under lock.</p>
    </div>
    <div class="modal-footer"><button class="btn btn-dark" name="resolve_ticket"><i class="fas fa-check mr-2"></i>Resolve ticket</button><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button></div>
</form>
<?php require_once '../../../includes/modal_footer.php';
