<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['id'] ?? 0);
$access_scope = clientScopeSql('ticket_client_id');
$active_ticket_scope = ticketOperationalActiveTicketSql('tickets');
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id,
    ticket_prefix, ticket_number, ticket_work_type, ticket_impact, ticket_urgency,
    ticket_priority, ticket_next_action, ticket_next_action_due_at, ticket_waiting_on,
    ticket_waiting_on_detail, ticket_closed_at FROM tickets
    WHERE ticket_id = $ticket_id AND tickets.ticket_deleted_at IS NULL
    $access_scope $active_ticket_scope LIMIT 1"));
if (!$ticket) {
    exit;
}
$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}
$related_candidates = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number,
    ticket_subject FROM tickets WHERE ticket_client_id = $client_id
    AND ticket_id <> $ticket_id AND ticket_closed_at IS NULL
    AND tickets.ticket_deleted_at IS NULL $active_ticket_scope
    ORDER BY ticket_updated_at DESC, ticket_id DESC LIMIT 250");
$relationships = ticketOperationalRelationships($ticket_id);
$promises = ticketOperationalPromises($ticket_id);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-compass mr-2"></i>Operational plan: <strong><?= escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']) ?></strong></h5>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
    <form action="post.php" method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
        <div class="row">
            <div class="col-md-4 form-group">
                <label>Work type</label>
                <select class="form-control" name="work_type" required>
                    <?php foreach (ticketOperationalWorkTypes() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket['ticket_work_type'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-4 form-group">
                <label>Impact</label>
                <select class="form-control" name="impact" required>
                    <?php foreach (ticketOperationalLevels() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket['ticket_impact'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-4 form-group">
                <label>Urgency</label>
                <select class="form-control" name="urgency" required>
                    <?php foreach (ticketOperationalLevels() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket['ticket_urgency'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
                <small class="text-muted">Priority is calculated; currently <?= escapeHtml($ticket['ticket_priority']) ?>.</small>
            </div>
        </div>
        <div class="form-group">
            <label>Next action</label>
            <input class="form-control" name="next_action" maxlength="500" value="<?= escapeHtml($ticket['ticket_next_action']) ?>" required>
        </div>
        <div class="row">
            <div class="col-md-6 form-group">
                <label>Next-action due</label>
                <input type="datetime-local" class="form-control" name="next_action_due_at" value="<?= $ticket['ticket_next_action_due_at'] ? escapeHtml(date('Y-m-d\TH:i', strtotime($ticket['ticket_next_action_due_at']))) : '' ?>">
            </div>
            <div class="col-md-6 form-group">
                <label>Waiting on</label>
                <select class="form-control" name="waiting_on" required>
                    <?php foreach (ticketOperationalWaitingOnDefinitions() as $key => $label) { ?>
                        <option value="<?= escapeHtml($key) ?>" <?= $ticket['ticket_waiting_on'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Waiting detail</label>
            <input class="form-control" name="waiting_on_detail" maxlength="255" value="<?= escapeHtml($ticket['ticket_waiting_on_detail']) ?>" placeholder="Required when waiting on someone">
        </div>
        <button class="btn btn-primary" name="edit_ticket_operations"><i class="fas fa-save mr-2"></i>Save operational plan</button>
    </form>

    <hr>
    <h6>Ticket relationships</h6>
    <?php if (mysqli_num_rows($relationships)) { ?>
        <ul class="list-group mb-3">
        <?php while ($relationship = mysqli_fetch_assoc($relationships)) {
            $is_source = intval($relationship['ticket_relationship_source_ticket_id']) === $ticket_id;
            $other_id = $is_source ? intval($relationship['ticket_relationship_target_ticket_id']) : intval($relationship['ticket_relationship_source_ticket_id']);
            $other_ref = $is_source
                ? escapeHtml($relationship['target_prefix']) . intval($relationship['target_number'])
                : escapeHtml($relationship['source_prefix']) . intval($relationship['source_number']);
            $other_subject = $is_source ? $relationship['target_subject'] : $relationship['source_subject'];
            $other_available = boolval($is_source ? $relationship['target_available'] : $relationship['source_available']); ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><?= escapeHtml(ticketOperationalRelationshipTypes()[$relationship['ticket_relationship_type']] ?? $relationship['ticket_relationship_type']) ?>:
                    <?php if ($other_available) { ?><a href="ticket.php?ticket_id=<?= $other_id ?>&client_id=<?= $client_id ?>"><?= $other_ref ?> — <?= escapeHtml($other_subject) ?></a>
                    <?php } else { ?><span class="text-muted"><?= escapeHtml($other_subject) ?></span><?php } ?>
                </span>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                    <input type="hidden" name="relationship_id" value="<?= intval($relationship['ticket_relationship_id']) ?>">
                    <button class="btn btn-sm btn-light text-danger" name="remove_ticket_relationship" aria-label="Remove relationship"><i class="fas fa-unlink"></i></button>
                </form>
            </li>
        <?php } ?>
        </ul>
    <?php } else { ?><p class="text-muted">No related tickets.</p><?php } ?>
    <form action="post.php" method="post" class="form-row align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
        <div class="col-md-3 form-group">
            <label>Relationship</label>
            <select class="form-control" name="relationship_type" required>
                <?php foreach (ticketOperationalRelationshipTypes() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>"><?= escapeHtml($label) ?></option><?php } ?>
            </select>
        </div>
        <div class="col-md-7 form-group">
            <label>Other ticket (same client)</label>
            <select class="form-control select2" name="target_ticket_id" required>
                <option value="">Select a ticket</option>
                <?php while ($candidate = mysqli_fetch_assoc($related_candidates)) { ?><option value="<?= intval($candidate['ticket_id']) ?>"><?= escapeHtml($candidate['ticket_prefix']) . intval($candidate['ticket_number']) ?> — <?= escapeHtml($candidate['ticket_subject']) ?></option><?php } ?>
            </select>
        </div>
        <div class="col-md-2 form-group"><button class="btn btn-secondary btn-block" name="add_ticket_relationship">Link</button></div>
    </form>

    <hr>
    <h6>Customer promises</h6>
    <?php if (mysqli_num_rows($promises)) { ?>
        <ul class="list-group mb-3">
        <?php while ($promise = mysqli_fetch_assoc($promises)) { ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><span class="badge badge-<?= $promise['ticket_customer_promise_status'] === 'Breached' ? 'danger' : ($promise['ticket_customer_promise_status'] === 'Open' ? 'warning' : 'secondary') ?> mr-2"><?= escapeHtml($promise['ticket_customer_promise_status']) ?></span><?= escapeHtml($promise['ticket_customer_promise_summary']) ?><br><small class="text-muted"><?= escapeHtml(ticketOperationalPromiseTypes()[$promise['ticket_customer_promise_type']] ?? $promise['ticket_customer_promise_type']) ?> due <?= escapeHtml(date('M j, Y g:i A', strtotime($promise['ticket_customer_promise_due_at']))) ?></small></span>
                <?php if (in_array($promise['ticket_customer_promise_status'], ['Open', 'Breached'], true)) { ?><form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                    <input type="hidden" name="promise_id" value="<?= intval($promise['ticket_customer_promise_id']) ?>">
                    <button class="btn btn-sm btn-light" name="cancel_ticket_promise">Cancel</button>
                </form><?php } ?>
            </li>
        <?php } ?>
        </ul>
    <?php } else { ?><p class="text-muted">No customer promises have been recorded.</p><?php } ?>
    <form action="post.php" method="post" class="form-row align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
        <div class="col-md-3 form-group"><label>Promise type</label><select class="form-control" name="promise_type"><?php foreach (ticketOperationalPromiseTypes() as $key => $label) { ?><option value="<?= escapeHtml($key) ?>"><?= escapeHtml($label) ?></option><?php } ?></select></div>
        <div class="col-md-5 form-group"><label>Promise</label><input class="form-control" name="promise_summary" maxlength="500" required></div>
        <div class="col-md-3 form-group"><label>Due</label><input type="datetime-local" class="form-control" name="promise_due_at" required></div>
        <div class="col-md-1 form-group"><button class="btn btn-secondary btn-block" name="add_ticket_promise"><i class="fas fa-plus"></i></button></div>
    </form>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Close</button></div>
<?php require_once '../../../includes/modal_footer.php';
