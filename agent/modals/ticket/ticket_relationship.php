<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id,
    ticket_prefix, ticket_number, ticket_subject FROM tickets
    WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'This ticket is unavailable']);
    exit;
}
$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}
$candidate_scope = $client_id > 0 ? "ticket_client_id = $client_id" : 'ticket_client_id = 0';
$candidate_rows = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number,
    ticket_subject, ticket_status_name FROM tickets
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE $candidate_scope AND ticket_id <> $ticket_id AND ticket_archived_at IS NULL
    ORDER BY ticket_updated_at DESC LIMIT 500");
$reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-link me-2"></i>Link another ticket</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <p class="mb-3"><strong><?= $reference ?> — <?= escapeHtml($ticket['ticket_subject']) ?></strong></p>
        <div class="mb-3">
            <label class="form-label" for="relationship_type_<?= $ticket_id ?>">Relationship</label>
            <select class="form-select" id="relationship_type_<?= $ticket_id ?>" name="relationship_type" required>
                <?php foreach (ticketRelationshipDefinitions() as $value => $label) { ?>
                    <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="mb-0">
            <label class="form-label" for="related_ticket_id_<?= $ticket_id ?>">Ticket</label>
            <select class="form-select select2" id="related_ticket_id_<?= $ticket_id ?>"
                    name="related_ticket_id" required data-placeholder="Search recent tickets">
                <option value=""></option>
                <?php while ($candidate = mysqli_fetch_assoc($candidate_rows)) { ?>
                    <option value="<?= intval($candidate['ticket_id']) ?>">
                        <?= escapeHtml($candidate['ticket_prefix']) . intval($candidate['ticket_number']) ?> —
                        <?= escapeHtml($candidate['ticket_subject']) ?> (<?= escapeHtml($candidate['ticket_status_name']) ?>)
                    </option>
                <?php } ?>
            </select>
            <div class="form-text">Only tickets for the same client are available.</div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="add_ticket_relationship" value="1" class="btn btn-primary text-bold">
            <i class="fas fa-link me-2"></i>Add relationship
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
