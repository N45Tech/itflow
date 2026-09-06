<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_values(array_unique(array_filter(
    array_map('intval', $_GET['ticket_ids'] ?? []),
    static fn ($ticket_id) => $ticket_id > 0
)));

$count = count($ticket_ids);
$has_problem = false;
if ($ticket_ids) {
    $ticket_ids_sql = implode(',', $ticket_ids);
    $problem_count = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets
        WHERE ticket_id IN ($ticket_ids_sql) AND ticket_work_type = 'problem'
        AND ticket_archived_at IS NULL " . clientScopeSql('ticket_client_id')));
    $has_problem = intval($problem_count[0] ?? 0) > 0;
}

ob_start();

?>
<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-check me-2"></i>Resolve <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>
    <input type="hidden" name="bulk_private_note" value="0">

    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label" for="bulk_resolution_code">Resolution</label>
            <select class="form-select" id="bulk_resolution_code" name="resolution_code" required>
                <option value="">Choose the shared outcome</option>
                <?php foreach (ticketResolutionCodeDefinitions() as $value => $label) { ?>
                    <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label" for="bulk_resolution_summary">Resolution summary</label>
            <textarea class="form-control" id="bulk_resolution_summary" name="resolution_summary"
                      rows="3" minlength="5" maxlength="2000" required
                      placeholder="What was done and how the outcome was verified for every selected ticket"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label" for="bulk_root_cause">
                Root cause<?= $has_problem ? ' *' : ' (optional)' ?>
            </label>
            <textarea class="form-control" id="bulk_root_cause" name="root_cause" rows="2"
                      maxlength="2000" <?= $has_problem ? 'required minlength="5"' : '' ?>></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label" for="bulk_details">Contact-facing resolution note</label>
            <textarea class="form-control tinymce" id="bulk_details" rows="5" name="bulk_details"
                      placeholder="Optional message sent to ticket contacts"></textarea>
        </div>

        <div class="col-3">
            <div class="mb-3">
                <label>Time worked</label>
                <input class="form-control timepicker" id="time_worked" name="time" type="text" placeholder="HH:MM:SS" pattern="([01]?[0-9]|2[0-3]):([0-5]?[0-9]):([0-5]?[0-9])" value="00:01:00" required/>
            </div>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="bulkPrivateCheckbox" name="bulk_private_note" value="1">
                <label class="form-check-label" for="bulkPrivateCheckbox">Mark as Internal</label>
                <small class="form-text text-muted">If checked this note will only be visible to agents. The contact / watcher will not be informed this ticket was resolved.</small>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="bulk_resolve_tickets" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Resolve Tickets</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
