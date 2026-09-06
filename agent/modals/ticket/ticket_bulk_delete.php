<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$ticket_ids = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_GET['ticket_ids'] ?? [])),
    static fn ($ticket_id) => $ticket_id > 0
)));
if (!$ticket_ids) {
    http_response_code(400);
    echo json_encode(['error' => 'Select at least one active ticket']);
    exit;
}

$ticket_ids_sql = implode(',', $ticket_ids);
$access_scope = clientScopeSql('ticket_client_id');
$tickets = [];
$sql_tickets = mysqli_query($mysqli, "SELECT ticket_id FROM tickets
    WHERE ticket_id IN ($ticket_ids_sql) AND ticket_archived_at IS NULL $access_scope");
while ($sql_tickets && $ticket = mysqli_fetch_assoc($sql_tickets)) {
    $tickets[] = intval($ticket['ticket_id']);
}
if (count($tickets) !== count($ticket_ids)) {
    http_response_code(403);
    echo json_encode(['error' => 'One or more selected tickets are unavailable']);
    exit;
}

$count = count($tickets);
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-trash-alt me-2"></i>Move <?= $count ?> ticket<?= $count === 1 ? '' : 's' ?> to Deleted</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($tickets as $ticket_id) { ?>
        <input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>">
    <?php } ?>

    <div class="modal-body">
        <div class="alert alert-info" role="status">
            <strong>These tickets remain recoverable.</strong>
            They will leave active queues and retain their replies, files, tasks, and audit history until deliberately purged. Each client’s minimum retention period applies.
        </div>

        <div class="mb-3">
            <label class="form-label" for="bulk_deletion_reason">
                Shared deletion reason <strong class="text-danger">*</strong>
            </label>
            <textarea class="form-control" id="bulk_deletion_reason" name="deletion_reason"
                      rows="3" minlength="3" maxlength="500" required></textarea>
            <div class="form-text">This reason is retained separately for every selected ticket.</div>
        </div>

        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="confirm_ticket_deletion" value="1"
                   id="confirm_bulk_ticket_deletion" required>
            <label class="form-check-label" for="confirm_bulk_ticket_deletion">
                Move all <?= $count ?> selected ticket<?= $count === 1 ? '' : 's' ?> to Deleted.
            </label>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep tickets</button>
        <button type="submit" name="bulk_delete_tickets" value="1" class="btn btn-danger text-bold">
            <i class="fas fa-trash-alt me-2"></i>Move to Deleted
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
