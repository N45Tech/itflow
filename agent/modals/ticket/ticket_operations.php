<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id,
    ticket_prefix, ticket_number, ticket_subject, ticket_status,
    ticket_work_type, ticket_impact, ticket_urgency, ticket_priority,
    ticket_waiting_on, ticket_next_action, ticket_next_action_due_at
    FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));
if (!$ticket || intval($ticket['ticket_status']) === 5) {
    http_response_code(409);
    echo json_encode(['error' => 'This ticket no longer accepts operational changes']);
    exit;
}
$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}

$reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
$due_value = !empty($ticket['ticket_next_action_due_at'])
    ? date('Y-m-d\TH:i', strtotime($ticket['ticket_next_action_due_at']))
    : '';
ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fas fa-fw fa-clipboard-check me-2"></i>Ticket operations</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" autocomplete="off" id="ticket-operations-form">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <p class="mb-3"><strong><?= $reference ?> — <?= escapeHtml($ticket['ticket_subject']) ?></strong></p>

        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label" for="ticket_work_type">Work type</label>
                <select class="form-select" id="ticket_work_type" name="work_type" required>
                    <?php foreach (ticketWorkTypeDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $ticket['ticket_work_type'] === $value ? 'selected' : '' ?>>
                            <?= escapeHtml($label) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="ticket_impact">Impact</label>
                <select class="form-select" id="ticket_impact" name="impact" required>
                    <?php foreach (ticketImpactDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $ticket['ticket_impact'] === $value ? 'selected' : '' ?>>
                            <?= escapeHtml(ucfirst($value) . ' — ' . $label) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="ticket_urgency">Urgency</label>
                <select class="form-select" id="ticket_urgency" name="urgency" required>
                    <?php foreach (ticketUrgencyDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $ticket['ticket_urgency'] === $value ? 'selected' : '' ?>>
                            <?= escapeHtml(ucfirst($value) . ' — ' . $label) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="alert alert-light border mt-3 mb-3" aria-live="polite">
            Derived priority: <strong id="derived-ticket-priority"><?= escapeHtml($ticket['ticket_priority']) ?></strong>
            <div class="small text-secondary">Priority is calculated consistently from impact and urgency.</div>
        </div>

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="ticket_waiting_on">Waiting on</label>
                <select class="form-select" id="ticket_waiting_on" name="waiting_on" required>
                    <?php foreach (ticketWaitingOnDefinitions() as $value => $label) { ?>
                        <option value="<?= escapeHtml($value) ?>" <?= $ticket['ticket_waiting_on'] === $value ? 'selected' : '' ?>>
                            <?= escapeHtml($label) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="ticket_next_action_due_at">Next action due</label>
                <input class="form-control" type="datetime-local" id="ticket_next_action_due_at"
                       name="next_action_due_at" value="<?= escapeHtml($due_value) ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="ticket_next_action">Next action</label>
                <textarea class="form-control" id="ticket_next_action" name="next_action"
                          rows="2" maxlength="500"><?= escapeHtml($ticket['ticket_next_action']) ?></textarea>
                <div class="form-text">Required with a dependency so the queue always shows what happens next.</div>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="edit_ticket_operations" value="1" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Save operations
        </button>
    </div>
</form>

<script>
(function () {
    const impact = document.getElementById('ticket_impact');
    const urgency = document.getElementById('ticket_urgency');
    const output = document.getElementById('derived-ticket-priority');
    const waiting = document.getElementById('ticket_waiting_on');
    const nextAction = document.getElementById('ticket_next_action');
    const due = document.getElementById('ticket_next_action_due_at');
    const priority = () => {
        const score = {low: 1, medium: 2, high: 3}[impact.value]
            + {low: 1, medium: 2, high: 3}[urgency.value];
        output.textContent = score === 6 ? 'Urgent' : (score === 5 ? 'High' : (score >= 3 ? 'Medium' : 'Low'));
    };
    const requirements = () => {
        const blocked = waiting.value !== 'none';
        nextAction.required = blocked;
        due.required = blocked;
    };
    impact.addEventListener('change', priority);
    urgency.addEventListener('change', priority);
    waiting.addEventListener('change', requirements);
    priority();
    requirements();
}());
</script>

<?php

require_once '../../../includes/modal_footer.php';
