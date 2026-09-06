<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_map('intval', $_GET['ticket_ids'] ?? []);

$count = count($ticket_ids);

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fa fa-fw fa-user-check me-2"></i>Assign Agent to <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>

    <div class="modal-body">

        <div class="mb-3">
            <label>Assign to</label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                <select class="form-select select2" name="assign_to">
                    <option value="0">Not Assigned</option>
                    <?php
                    $sql_users_select = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
                        WHERE user_type = 1
                        AND user_status = 1
                        AND user_archived_at IS NULL
                        ORDER BY user_name ASC"
                    );
                    while ($row = mysqli_fetch_assoc($sql_users_select)) {
                        $user_id_select = intval($row['user_id']);
                        $user_name_select = escapeHtml($row['user_name']);

                        ?>
                        <option value="<?= $user_id_select ?>"><?= $user_name_select ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="border-top pt-3">
            <p class="small text-secondary">These handoff details are applied to any selected ticket that already has a different owner.</p>
            <div class="mb-3">
                <label class="form-label" for="bulk_handoff_reason">Reason for reassignment</label>
                <textarea class="form-control" id="bulk_handoff_reason" name="handoff_reason"
                          rows="2" minlength="3" maxlength="500" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="bulk_handoff_current_state">Current state</label>
                <textarea class="form-control" id="bulk_handoff_current_state" name="handoff_current_state"
                          rows="2" minlength="3" maxlength="500" required></textarea>
            </div>
            <div class="mb-0">
                <label class="form-label" for="bulk_handoff_next_action">Next action</label>
                <textarea class="form-control" id="bulk_handoff_next_action" name="handoff_next_action"
                          rows="2" minlength="3" maxlength="500" required></textarea>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_assign_ticket" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Assign</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
