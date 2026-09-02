<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT client_name, ticket_client_id, ticket_number, ticket_prefix,
    ticket_priority, ticket_impact, ticket_urgency FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix = escapeHtml($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_priority = escapeHtml($row['ticket_priority']);
$ticket_impact = (string) $row['ticket_impact'];
$ticket_urgency = (string) $row['ticket_urgency'];
$client_name = escapeHtml($row['client_name']);
$client_id = intval($row['ticket_client_id']);

if ($client_id) {
    enforceClientAccess();
}

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thermometer-half mr-2"></i>Editing priority: <strong><?= "$ticket_prefix$ticket_number" ?></strong></h5>
    <button type="button" class="close text-white" data-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">

        <div class="row"><div class="col form-group"><label>Impact</label><select class="form-control" name="impact" required><?php foreach (ticketOperationalLevels() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>" <?= $ticket_impact === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div><div class="col form-group"><label>Urgency</label><select class="form-control" name="urgency" required><?php foreach (ticketOperationalLevels() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>" <?= $ticket_urgency === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php } ?></select></div></div>
        <p class="small text-muted mb-0">Priority is calculated from impact and urgency; currently <?= $ticket_priority ?>.</p>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket_priority" class="btn btn-primary text-bold"><i class="fa fa-check mr-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-dismiss="modal"><i class="fa fa-times mr-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';
