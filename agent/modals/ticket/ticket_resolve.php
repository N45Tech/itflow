<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id,
    ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_work_type
    FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));
if (!$ticket || in_array(intval($ticket['ticket_status']), [4, 5], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'This ticket is no longer open']);
    exit;
}
$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

[$prerequisites_allow_resolution, $prerequisite_error] = ticketLifecyclePrerequisitesCanResolve($ticket_id);
$open_promises = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*)
    FROM ticket_customer_promises WHERE ticket_customer_promise_ticket_id = $ticket_id
    AND ticket_customer_promise_status = 'open'"))[0] ?? 0);
$can_submit = $prerequisites_allow_resolution && $open_promises === 0;
$reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-check me-2"></i>Resolve ticket</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <p class="mb-3"><strong><?= $reference ?> — <?= escapeHtml($ticket['ticket_subject']) ?></strong></p>

        <?php if (!$can_submit) { ?>
            <div class="alert alert-warning" role="alert">
                <strong>This ticket is not ready to resolve.</strong>
                <?= escapeHtml($prerequisites_allow_resolution
                    ? "$open_promises customer promise(s) must be fulfilled or cancelled first."
                    : $prerequisite_error) ?>
            </div>
        <?php } ?>

        <div class="mb-3">
            <label class="form-label" for="resolution_code_<?= $ticket_id ?>">Resolution</label>
            <select class="form-select" id="resolution_code_<?= $ticket_id ?>" name="resolution_code" required>
                <option value="">Choose the outcome</option>
                <?php foreach (ticketResolutionCodeDefinitions() as $value => $label) { ?>
                    <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="resolution_summary_<?= $ticket_id ?>">Resolution summary</label>
            <textarea class="form-control" id="resolution_summary_<?= $ticket_id ?>"
                      name="resolution_summary" rows="4" minlength="5" maxlength="2000"
                      placeholder="What was done, what changed, and how the outcome was verified" required></textarea>
        </div>
        <div class="mb-0">
            <label class="form-label" for="root_cause_<?= $ticket_id ?>">
                Root cause<?= $ticket['ticket_work_type'] === 'problem' ? ' *' : ' (optional)' ?>
            </label>
            <textarea class="form-control" id="root_cause_<?= $ticket_id ?>" name="root_cause"
                      rows="3" maxlength="2000" <?= $ticket['ticket_work_type'] === 'problem' ? 'required minlength="5"' : '' ?>></textarea>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep open</button>
        <button type="submit" name="resolve_ticket" value="1" class="btn btn-primary text-bold"
                <?= $can_submit ? '' : 'disabled' ?>>
            <i class="fas fa-check me-2"></i>Resolve ticket
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
