<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix,
    ticket_number, ticket_subject, ticket_client_id, client_name
    FROM tickets LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'This ticket is no longer available']);
    exit;
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

$retention_days = ticketDeletionRestoreWindowDays($client_id);
$ticket_reference = escapeHtml((string) $ticket['ticket_prefix']) . intval($ticket['ticket_number']);
$ticket_subject = escapeHtml($ticket['ticket_subject']);
$client_name = $client_id ? escapeHtml($ticket['client_name']) : 'Internal / no client';

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-trash-alt me-2"></i>Move ticket to Deleted</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">
        <p class="mb-1"><strong><?= $ticket_reference ?> — <?= $ticket_subject ?></strong></p>
        <p class="text-secondary mb-3"><?= $client_name ?></p>

        <div class="alert alert-info" role="status">
            <strong>This is recoverable.</strong>
            The ticket and its history will leave active queues and remain restorable until deliberately purged.
            Permanent deletion is blocked for at least <?= $retention_days ?> days by the client retention period.
        </div>

        <div class="mb-3">
            <label class="form-label" for="deletion_reason_<?= $ticket_id ?>">
                Reason for deletion <strong class="text-danger">*</strong>
            </label>
            <textarea class="form-control" id="deletion_reason_<?= $ticket_id ?>"
                      name="deletion_reason" rows="3" minlength="3" maxlength="500"
                      aria-describedby="deletion_reason_help_<?= $ticket_id ?>" required></textarea>
            <div class="form-text" id="deletion_reason_help_<?= $ticket_id ?>">
                The reason is retained in the deletion history even if the ticket is later purged.
            </div>
        </div>

        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="confirm_ticket_deletion" value="1"
                   id="confirm_ticket_deletion_<?= $ticket_id ?>" required>
            <label class="form-check-label" for="confirm_ticket_deletion_<?= $ticket_id ?>">
                Move this ticket to Deleted and retain its history.
            </label>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep ticket</button>
        <button type="submit" name="delete_ticket" value="1" class="btn btn-danger text-bold">
            <i class="fas fa-trash-alt me-2"></i>Move to Deleted
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
