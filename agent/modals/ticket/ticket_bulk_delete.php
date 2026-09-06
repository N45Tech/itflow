<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$ticket_ids = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_GET['ticket_ids'] ?? [])),
    static fn ($ticket_id) => $ticket_id > 0
)));
if (!$ticket_ids) {
    http_response_code(400);
    echo json_encode(['error' => 'Select at least one ticket']);
    exit;
}

$ticket_ids_sql = implode(',', $ticket_ids);
$access_scope = clientScopeSql('ticket_client_id');
$tickets = [];
$sql_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id
    FROM tickets WHERE ticket_id IN ($ticket_ids_sql) $access_scope");
while ($sql_tickets && $ticket = mysqli_fetch_assoc($sql_tickets)) {
    $tickets[intval($ticket['ticket_id'])] = intval($ticket['ticket_client_id']);
}
if (count($tickets) !== count($ticket_ids)) {
    http_response_code(403);
    echo json_encode(['error' => 'One or more selected tickets are unavailable']);
    exit;
}

$ready_count = 0;
$override_count = 0;
$strict_count = 0;
try {
    foreach ($tickets as $ticket_id => $client_id) {
        $has_evidence = !empty(ticketDeletionEvidenceSummary($ticket_id, $client_id));
        if (!$has_evidence) {
            $ready_count++;
        } elseif (ticketDeletionPolicyForClient($client_id) === 'override') {
            $override_count++;
        } else {
            $strict_count++;
        }
    }
} catch (Throwable $exception) {
    error_log('Bulk ticket deletion preview failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The retention check could not be completed. No tickets were changed.']);
    exit;
}

$count = count($tickets);
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-trash me-2"></i>Delete <?= $count ?> selected ticket<?= $count === 1 ? '' : 's' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off" id="bulk-ticket-delete-form">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach (array_keys($tickets) as $ticket_id) { ?>
        <input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>">
    <?php } ?>

    <div class="modal-body">
        <p>This removes each eligible ticket and all of its replies, attachments, tasks, and history.</p>

        <dl class="row mb-3">
            <dt class="col-8 fw-normal">Ready to delete</dt>
            <dd class="col-4 text-end mb-1"><strong><?= $ready_count ?></strong></dd>
            <?php if ($override_count) { ?>
                <dt class="col-8 fw-normal">Need an administrator override</dt>
                <dd class="col-4 text-end mb-1"><strong><?= $override_count ?></strong></dd>
            <?php } ?>
            <?php if ($strict_count) { ?>
                <dt class="col-8 fw-normal">Retained by strict client policy</dt>
                <dd class="col-4 text-end mb-1"><strong><?= $strict_count ?></strong></dd>
            <?php } ?>
        </dl>

        <?php if ($override_count) { ?>
            <div class="border rounded p-3 mb-3">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="override_retention" value="1"
                           id="bulk_override_retention">
                    <label class="form-check-label" for="bulk_override_retention">
                        Also delete the <?= $override_count ?> ticket<?= $override_count === 1 ? '' : 's' ?> eligible for an audit-retention override
                    </label>
                </div>
                <label class="form-label" for="bulk_deletion_override_reason">Override reason</label>
                <textarea class="form-control" id="bulk_deletion_override_reason" name="deletion_override_reason"
                          rows="3" minlength="10" maxlength="500" disabled
                          aria-describedby="bulk_deletion_override_help"></textarea>
                <div class="form-text" id="bulk_deletion_override_help">Required when overriding retention. The reason remains in each client audit log.</div>
            </div>
        <?php } ?>

        <?php if ($strict_count) { ?>
            <div class="alert alert-info py-2" role="status">
                <?= $strict_count ?> ticket<?= $strict_count === 1 ? ' is' : 's are' ?> protected by strict client policy and will remain unchanged.
            </div>
        <?php } ?>

        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="confirm_ticket_deletion" value="1"
                   id="confirm_bulk_ticket_deletion" required>
            <label class="form-check-label" for="confirm_bulk_ticket_deletion">I understand the selected deletions cannot be undone.</label>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_delete_tickets" value="1" class="btn btn-danger text-bold"
                <?= ($ready_count + $override_count) === 0 ? 'disabled' : '' ?>>
            <i class="fas fa-trash me-2"></i>Delete eligible tickets
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep tickets</button>
    </div>
</form>

<?php if ($override_count) { ?>
<script>
    (function () {
        const override = document.getElementById('bulk_override_retention');
        const reason = document.getElementById('bulk_deletion_override_reason');
        if (!override || !reason) return;
        override.addEventListener('change', function () {
            reason.disabled = !override.checked;
            reason.required = override.checked;
            if (override.checked) reason.focus();
        });
    }());
</script>
<?php } ?>

<?php

require_once '../../../includes/modal_footer.php';
