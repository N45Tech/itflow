<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix,
    ticket_number, ticket_subject, ticket_client_id, client_name
    FROM tickets LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id LIMIT 1"));

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'This ticket is no longer available']);
    exit;
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

try {
    $policy = ticketDeletionPolicyForClient($client_id);
    $evidence = ticketDeletionEvidenceSummary($ticket_id, $client_id);
} catch (Throwable $exception) {
    error_log("Ticket $ticket_id deletion preview failed: " . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The retention check could not be completed. The ticket was not changed.']);
    exit;
}

$ticket_reference = escapeHtml((string) $ticket['ticket_prefix']) . intval($ticket['ticket_number']);
$ticket_subject = escapeHtml($ticket['ticket_subject']);
$client_name = $client_id ? escapeHtml($ticket['client_name']) : 'Internal / no client';
$evidence_labels = ticketDeletionEvidenceLabels($evidence);
$has_evidence = !empty($evidence_labels);
$override_allowed = $has_evidence && $policy === 'override';
$strictly_retained = $has_evidence && !$override_allowed;

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-trash me-2"></i>Delete ticket</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body">
    <p class="mb-1"><strong><?= $ticket_reference ?> — <?= $ticket_subject ?></strong></p>
    <p class="text-muted mb-3"><?= $client_name ?></p>

    <?php if ($has_evidence) { ?>
        <div class="alert <?= $strictly_retained ? 'alert-info' : 'alert-warning' ?>" role="status">
            <strong><?= $strictly_retained ? 'Client policy requires retention.' : 'This ticket has protected audit evidence.' ?></strong>
            <div class="mt-1">
                <?= escapeHtml(ucfirst(implode(', ', $evidence_labels))) ?>.
            </div>
            <div class="small mt-2">
                Policy: <?= escapeHtml(ticketDeletionPolicyLabel($policy)) ?>.
            </div>
        </div>
    <?php } else { ?>
        <p>This permanently removes the ticket, its replies, attachments, tasks, and history.</p>
    <?php } ?>

    <?php if ($strictly_retained) { ?>
        <p class="mb-0">Close the ticket to keep the record, or change this client’s ticket-retention policy before trying again.</p>
    <?php } else { ?>
        <form action="post.php" method="post" autocomplete="off" id="delete-ticket-form-<?= $ticket_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
            <?php if ($override_allowed) { ?>
                <input type="hidden" name="override_retention" value="1">
                <div class="mb-3">
                    <label class="form-label" for="deletion_override_reason_<?= $ticket_id ?>">Override reason <strong class="text-danger">*</strong></label>
                    <textarea class="form-control" id="deletion_override_reason_<?= $ticket_id ?>"
                              name="deletion_override_reason" rows="3" minlength="10" maxlength="500"
                              aria-describedby="deletion_override_help_<?= $ticket_id ?>" required></textarea>
                    <div class="form-text" id="deletion_override_help_<?= $ticket_id ?>">This reason remains in the client audit log after deletion.</div>
                </div>
            <?php } ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="confirm_ticket_deletion" value="1"
                       id="confirm_ticket_deletion_<?= $ticket_id ?>" required>
                <label class="form-check-label" for="confirm_ticket_deletion_<?= $ticket_id ?>">
                    I understand this cannot be undone<?= $override_allowed ? ' and protected ticket evidence will be removed' : '' ?>.
                </label>
            </div>
        </form>
    <?php } ?>
</div>

<div class="modal-footer">
    <?php if (!$strictly_retained) { ?>
        <button type="submit" name="delete_ticket" value="1"
                form="delete-ticket-form-<?= $ticket_id ?>" class="btn btn-danger text-bold">
            <i class="fas fa-trash me-2"></i><?= $override_allowed ? 'Delete ticket and evidence' : 'Delete ticket' ?>
        </button>
    <?php } ?>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $strictly_retained ? 'Done' : 'Keep ticket' ?></button>
</div>

<?php

require_once '../../../includes/modal_footer.php';
