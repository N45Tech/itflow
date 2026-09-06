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
if (!empty($ticket['ticket_restore_until']) && strtotime($ticket['ticket_restore_until']) >= time()) {
    http_response_code(409);
    echo json_encode(['error' => 'Permanent deletion is unavailable until the 30-day restore window ends']);
    exit;
}

try {
    $policy = ticketDeletionPolicyForClient($client_id);
    $evidence = ticketDeletionEvidenceSummary($ticket_id, $client_id);
} catch (Throwable $exception) {
    error_log("Ticket $ticket_id purge preview failed: " . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The retention policy could not be verified']);
    exit;
}

$evidence_labels = ticketDeletionEvidenceLabels($evidence);
$purge_blocked = !empty($evidence_labels) && $policy !== 'override';
$reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
$confirmation = 'PURGE ' . $reference;
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Permanently delete ticket</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body">
    <p class="mb-1"><strong><?= $reference ?> — <?= escapeHtml($ticket['ticket_subject']) ?></strong></p>
    <p class="text-secondary mb-3">
        <?= $client_id ? escapeHtml($ticket['client_name']) : 'Internal / no client' ?>
    </p>

    <?php if ($purge_blocked) { ?>
        <div class="alert alert-info" role="status">
            <strong>Protected by this client’s strict retention policy.</strong>
            This ticket contains <?= escapeHtml(implode(', ', $evidence_labels)) ?> and cannot be permanently deleted.
        </div>
        <p class="mb-0">Keep the recoverable record or change the client policy after reviewing the audit requirements.</p>
    <?php } else { ?>
        <div class="alert alert-danger" role="alert">
            <strong>This cannot be undone.</strong>
            The ticket, replies, attachments, tasks, and operational records will be destroyed.
            A minimal deletion event and its reason will remain in the client audit history.
        </div>
        <?php if ($evidence_labels) { ?>
            <p class="small text-secondary">
                Client policy permits an administrator override for:
                <?= escapeHtml(implode(', ', $evidence_labels)) ?>.
            </p>
        <?php } ?>
        <form action="post.php" method="post" autocomplete="off" id="purge-ticket-form-<?= $ticket_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
            <div class="mb-3">
                <label class="form-label" for="purge_reason_<?= $ticket_id ?>">
                    Permanent deletion reason <strong class="text-danger">*</strong>
                </label>
                <textarea class="form-control" id="purge_reason_<?= $ticket_id ?>" name="purge_reason"
                          rows="3" minlength="10" maxlength="500" required></textarea>
            </div>
            <div class="mb-0">
                <label class="form-label" for="purge_confirmation_<?= $ticket_id ?>">
                    Type <code><?= $confirmation ?></code> to confirm
                </label>
                <input class="form-control" id="purge_confirmation_<?= $ticket_id ?>"
                       name="purge_confirmation" autocomplete="off" required>
            </div>
        </form>
    <?php } ?>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $purge_blocked ? 'Done' : 'Keep ticket' ?></button>
    <?php if (!$purge_blocked) { ?>
        <button type="submit" name="purge_ticket" value="1" form="purge-ticket-form-<?= $ticket_id ?>"
                class="btn btn-danger text-bold">
            <i class="fas fa-trash me-2"></i>Permanently delete
        </button>
    <?php } ?>
</div>

<?php

require_once '../../../includes/modal_footer.php';
