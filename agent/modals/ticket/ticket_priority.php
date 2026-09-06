<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT client_name, ticket_client_id, ticket_number, ticket_prefix,
    ticket_priority, ticket_impact, ticket_urgency FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id
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

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fa fa-fw fa-thermometer-half me-2"></i>Assess ticket: <strong><?= "$ticket_prefix$ticket_number" ?></strong></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">

        <p class="text-muted">Priority is calculated from business impact and urgency.</p>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="ticket-priority-impact">Impact</label>
                <select class="form-select ticket-priority-input" id="ticket-priority-impact" name="impact" required>
                    <?php foreach (ticketImpactDefinitions() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket_impact === $key ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key) . ' — ' . $label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="ticket-priority-urgency">Urgency</label>
                <select class="form-select ticket-priority-input" id="ticket-priority-urgency" name="urgency" required>
                    <?php foreach (ticketUrgencyDefinitions() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket_urgency === $key ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key) . ' — ' . $label) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="alert alert-light border mb-0">Derived priority: <strong id="ticket-priority-derived"><?= $ticket_priority ?></strong></div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket_priority" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<script>
(() => {
    const impact = document.getElementById('ticket-priority-impact');
    const urgency = document.getElementById('ticket-priority-urgency');
    const output = document.getElementById('ticket-priority-derived');
    const score = {low: 1, medium: 2, high: 3};
    const refresh = () => {
        const total = score[impact.value] + score[urgency.value];
        output.textContent = total === 6 ? 'Urgent' : total === 5 ? 'High' : total >= 3 ? 'Medium' : 'Low';
    };
    impact.addEventListener('change', refresh);
    urgency.addEventListener('change', refresh);
    refresh();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
