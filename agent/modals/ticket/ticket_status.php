<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix,
    ticket_number, ticket_subject, ticket_status, ticket_client_id,
    ticket_resolved_at, ticket_closed_at FROM tickets
    WHERE ticket_id = $ticket_id LIMIT 1"));

if (!$ticket || !empty($ticket['ticket_closed_at'])) {
    http_response_code(409);
    echo json_encode(['error' => 'This ticket no longer accepts status changes']);
    exit;
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

$current_status_id = intval($ticket['ticket_status']);
$ticket_reference = escapeHtml((string) $ticket['ticket_prefix']) . intval($ticket['ticket_number']);
$ticket_subject = escapeHtml($ticket['ticket_subject']);
[$can_resolve, $resolution_error] = ticketLifecycleCanResolve($ticket_id, true);
$statuses = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name
    FROM ticket_statuses WHERE ticket_status_id NOT IN (1, 5)
    AND (ticket_status_active = 1 OR ticket_status_id = $current_status_id)
    ORDER BY ticket_status_order, ticket_status_id");

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-exchange-alt me-2"></i>Change status</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">
        <div class="mb-3">
            <strong><?= $ticket_reference ?> — <?= $ticket_subject ?></strong>
        </div>

        <div class="mb-3">
            <label class="form-label" for="ticket_status_<?= $ticket_id ?>">Status</label>
            <select class="form-select" name="ticket_status" id="ticket_status_<?= $ticket_id ?>" required autofocus>
                <?php while ($status = mysqli_fetch_assoc($statuses)) {
                    $status_id = intval($status['ticket_status_id']);
                    $resolve_disabled = $status_id === 4 && !$can_resolve && $current_status_id !== 4;
                    ?>
                    <option value="<?= $status_id ?>"
                            <?= $status_id === $current_status_id ? 'selected' : '' ?>
                            <?= $resolve_disabled ? 'disabled' : '' ?>>
                        <?= escapeHtml($status['ticket_status_name']) ?><?= $resolve_disabled ? ' — blocked' : '' ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <?php if (!$can_resolve && $current_status_id !== 4) { ?>
            <div class="alert alert-light border mb-0">
                <strong>Resolved is not available yet.</strong>
                <div class="small text-muted mt-1"><?= escapeHtml($resolution_error) ?></div>
            </div>
        <?php } ?>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="edit_ticket_status" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Update status
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
