<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$terminal_action = $_GET['action'] ?? '';
if (!in_array($terminal_action, ['close', 'cancel'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown ticket action']);
    exit;
}

$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_subject, ticket_status,
    ticket_resolved_at, ticket_closed_at, ticket_client_id, ticket_work_type
    FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));
if (!$ticket || !empty($ticket['ticket_closed_at']) || intval($ticket['ticket_status']) === 5) {
    http_response_code(409);
    echo json_encode(['error' => 'This ticket is already closed']);
    exit;
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

$is_cancel = $terminal_action === 'cancel';
$needs_resolution = !$is_cancel
    && (intval($ticket['ticket_status']) !== 4 || empty($ticket['ticket_resolved_at']));
$title = $is_cancel ? 'Cancel ticket' : 'Close ticket';
$button_class = $is_cancel ? 'btn-danger' : 'btn-dark';
$button_icon = $is_cancel ? 'fa-ban' : 'fa-gavel';
$button_label = $is_cancel ? 'Cancel ticket' : 'Close ticket';

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw <?= $button_icon ?> me-2"></i><?= $title ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="terminal_action" value="<?= $terminal_action ?>">

    <div class="modal-body">
        <p class="mb-2"><strong><?= escapeHtml($ticket['ticket_subject']) ?></strong></p>
        <?php if ($is_cancel) { ?>
            <p class="text-secondary">End work as cancelled. The reason and cancellation outcome remain in the ticket history.</p>
        <?php } else { ?>
            <p class="text-secondary">Close this request as completed. Required work, approvals, and customer promises must already be complete.</p>
        <?php } ?>

        <?php if ($needs_resolution) { ?>
            <div class="mb-3">
                <label class="form-label" for="terminal_resolution_code">Resolution</label>
                <select class="form-select" id="terminal_resolution_code" name="resolution_code" required>
                    <option value="">Choose the outcome</option>
                    <?php foreach (ticketResolutionCodeDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="terminal_resolution_summary">Resolution summary</label>
                <textarea class="form-control" id="terminal_resolution_summary" name="resolution_summary"
                          rows="3" minlength="5" maxlength="2000" required
                          placeholder="What was done and how the outcome was verified"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="terminal_root_cause">
                    Root cause<?= $ticket['ticket_work_type'] === 'problem' ? ' *' : ' (optional)' ?>
                </label>
                <textarea class="form-control" id="terminal_root_cause" name="root_cause" rows="2"
                          maxlength="2000" <?= $ticket['ticket_work_type'] === 'problem' ? 'required minlength="5"' : '' ?>></textarea>
            </div>
        <?php } ?>

        <?php if (!$is_cancel) { ?>
            <div class="mb-3">
                <label class="form-label" for="terminal_closure_code">Closure check</label>
                <select class="form-select" id="terminal_closure_code" name="closure_code" required>
                    <option value="">Choose how completion was confirmed</option>
                    <?php foreach (ticketClosureCodeDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
        <?php } ?>

        <div class="mb-0">
            <label for="terminal_reason" class="form-label"><?= $is_cancel ? 'Cancellation reason' : 'Closure note' ?> <strong class="text-danger">*</strong></label>
            <textarea class="form-control" id="terminal_reason" name="terminal_reason" rows="3" maxlength="1000" required
                      placeholder="<?= $is_cancel ? 'Why work is being cancelled' : 'Summarize the completed outcome' ?>"></textarea>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" name="terminal_ticket" class="btn <?= $button_class ?> text-bold">
            <i class="fas <?= $button_icon ?> me-2"></i><?= $button_label ?>
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep open</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
