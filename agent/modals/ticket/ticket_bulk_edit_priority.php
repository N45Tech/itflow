<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_map('intval', $_GET['ticket_ids'] ?? []);

$count = count($ticket_ids);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thermometer-half mr-2"></i>Set Priority for <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="close text-white" data-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>

    <div class="modal-body">

        <div class="row"><div class="col form-group"><label>Impact</label><select class="form-control" name="bulk_impact" required><?php foreach (ticketOperationalLevels() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>" <?= $key === 'medium' ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div><div class="col form-group"><label>Urgency</label><select class="form-control" name="bulk_urgency" required><?php foreach (ticketOperationalLevels() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>" <?= $key === 'medium' ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div></div>
        <p class="small text-muted mb-0">Each ticket's priority is recalculated from these dimensions.</p>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_edit_ticket_priority" class="btn btn-primary text-bold"><i class="fa fa-check mr-2"></i>Set Priority</button>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i class="fa fa-times mr-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
