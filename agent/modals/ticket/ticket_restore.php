<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix,
    ticket_number, ticket_subject, ticket_client_id, ticket_archived_at,
    ticket_restore_until, client_name FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id AND ticket_archived_at IS NOT NULL LIMIT 1"));
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'This deleted ticket is no longer available']);
    exit;
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}
$reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-undo me-2"></i>Restore ticket</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <p class="mb-1"><strong><?= $reference ?> — <?= escapeHtml($ticket['ticket_subject']) ?></strong></p>
        <p class="text-secondary mb-3">
            <?= $client_id ? escapeHtml($ticket['client_name']) : 'Internal / no client' ?>
        </p>
        <p>Restoring returns this ticket to its previous lifecycle state and appropriate queues. Its history remains intact.</p>
        <div class="mb-0">
            <label class="form-label" for="restore_reason_<?= $ticket_id ?>">
                Why is this ticket being restored? <strong class="text-danger">*</strong>
            </label>
            <textarea class="form-control" id="restore_reason_<?= $ticket_id ?>" name="restore_reason"
                      rows="3" minlength="3" maxlength="500" required></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep deleted</button>
        <button type="submit" name="restore_ticket" value="1" class="btn btn-primary text-bold">
            <i class="fas fa-undo me-2"></i>Restore ticket
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
