<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$promise_id = intval($_GET['promise_id'] ?? 0);
$promise = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT promise.*,
    tickets.ticket_prefix, tickets.ticket_number, tickets.ticket_subject
    FROM ticket_customer_promises promise
    INNER JOIN tickets ON ticket_id = ticket_customer_promise_ticket_id
    WHERE ticket_customer_promise_id = $promise_id
    AND ticket_customer_promise_status = 'open'
    AND ticket_archived_at IS NULL LIMIT 1"));
if (!$promise) {
    http_response_code(404);
    echo json_encode(['error' => 'This customer promise is no longer open']);
    exit;
}
$client_id = intval($promise['ticket_customer_promise_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}
$reference = escapeHtml($promise['ticket_prefix']) . intval($promise['ticket_number']);
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-handshake me-2"></i>Complete customer promise</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="promise_id" value="<?= $promise_id ?>">
    <div class="modal-body">
        <p class="mb-1"><strong><?= $reference ?> — <?= escapeHtml($promise['ticket_subject']) ?></strong></p>
        <p class="mb-3"><?= escapeHtml($promise['ticket_customer_promise_summary']) ?></p>
        <div class="mb-3">
            <label class="form-label" for="promise_action_<?= $promise_id ?>">Outcome</label>
            <select class="form-select" id="promise_action_<?= $promise_id ?>" name="promise_action" required>
                <option value="fulfilled">Fulfilled</option>
                <option value="cancelled">Cancelled with explanation</option>
            </select>
        </div>
        <div class="mb-0">
            <label class="form-label" for="promise_reason_<?= $promise_id ?>">Completion note</label>
            <textarea class="form-control" id="promise_reason_<?= $promise_id ?>" name="promise_reason"
                      rows="3" minlength="3" maxlength="500" required></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep open</button>
        <button type="submit" name="complete_ticket_promise" value="1" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Save outcome
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
