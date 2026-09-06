<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_map('intval', $_GET['ticket_ids'] ?? []);

$count = count($ticket_ids);

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fa fa-fw fa-thermometer-half me-2"></i>Assess <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>

    <div class="modal-body">

        <p class="text-muted">The same impact and urgency assessment will be applied to all selected tickets.</p>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="bulk-ticket-impact">Impact</label>
                <select class="form-select bulk-ticket-assessment" id="bulk-ticket-impact" name="bulk_impact" required>
                    <?php foreach (ticketImpactDefinitions() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $key === 'medium' ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key) . ' — ' . $label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="bulk-ticket-urgency">Urgency</label>
                <select class="form-select bulk-ticket-assessment" id="bulk-ticket-urgency" name="bulk_urgency" required>
                    <?php foreach (ticketUrgencyDefinitions() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $key === 'medium' ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key) . ' — ' . $label) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="alert alert-light border mb-0">Derived priority: <strong id="bulk-ticket-derived-priority">Medium</strong></div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_edit_ticket_priority" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Apply assessment</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
(() => {
    const impact = document.getElementById('bulk-ticket-impact');
    const urgency = document.getElementById('bulk-ticket-urgency');
    const output = document.getElementById('bulk-ticket-derived-priority');
    const score = {low: 1, medium: 2, high: 3};
    const refresh = () => {
        const total = score[impact.value] + score[urgency.value];
        output.textContent = total === 6 ? 'Urgent' : total === 5 ? 'High' : total >= 3 ? 'Medium' : 'Low';
    };
    impact.addEventListener('change', refresh);
    urgency.addEventListener('change', refresh);
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
