<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT client_name, ticket_assigned_to, ticket_client_id,
    ticket_closed_at, ticket_number, ticket_prefix, ticket_status FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix = escapeHtml($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_assigned_to = intval($row['ticket_assigned_to']);
$ticket_status = intval($row['ticket_status']);
$ticket_closed_at = escapeHtml($row['ticket_closed_at']);
$client_name = escapeHtml($row['client_name']);
$client_id = intval($row['ticket_client_id']);

if ($client_id) {
    enforceClientAccess($client_id);
}

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class='fa fa-fw fa-user-check me-2'></i>Assigning Ticket: <strong><?= "$ticket_prefix$ticket_number" ?></strong> - <?= $client_name ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="ticket_status" value="<?= $ticket_status ?>">
    <div class="modal-body">

        <div class="mb-3">
            <label>Assign to</label>
            <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                <select class="form-select select2" name="assigned_to" id="ticket_assignment_target">
                    <option value="0">Unassigned</option>
                    <?php
                    $sql_users_select = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
                        WHERE user_type = 1 AND user_status = 1
                        AND user_archived_at IS NULL
                        ORDER BY user_name ASC"
                    );
                    while ($row = mysqli_fetch_assoc($sql_users_select)) {
                        $user_id_select = intval($row['user_id']);
                        $user_name_select = escapeHtml($row['user_name']);

                        ?>
                        <option value="<?= $user_id_select ?>" <?php if ($user_id_select  == $ticket_assigned_to) { echo "selected"; } ?>><?= $user_name_select ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <?php if ($ticket_assigned_to) { ?>
            <div id="ticket_handoff_fields" class="border-top pt-3" hidden>
                <p class="small text-secondary">A reassignment includes a concise handoff so the new owner can act without reconstructing the ticket.</p>
                <div class="mb-3">
                    <label class="form-label" for="handoff_reason">Why is ownership changing?</label>
                    <textarea class="form-control" id="handoff_reason" name="handoff_reason" rows="2"
                              minlength="3" maxlength="500"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="handoff_current_state">Current state</label>
                    <textarea class="form-control" id="handoff_current_state" name="handoff_current_state" rows="2"
                              minlength="3" maxlength="500"></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="handoff_next_action">Next action for the new owner</label>
                    <textarea class="form-control" id="handoff_next_action" name="handoff_next_action" rows="2"
                              minlength="3" maxlength="500"></textarea>
                </div>
            </div>
        <?php } ?>

    </div>

    <div class="modal-footer">
        <button type="submit" name="assign_ticket" class="btn btn-primary text-bold">
            <i class="fa fa-check me-2"></i>Assign
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>

</form>

<?php if ($ticket_assigned_to) { ?>
<script>
(function () {
    const select = document.getElementById('ticket_assignment_target');
    const fields = document.getElementById('ticket_handoff_fields');
    const inputs = fields.querySelectorAll('textarea');
    const current = <?= $ticket_assigned_to ?>;
    const sync = () => {
        const needsHandoff = Number(select.value) !== current;
        fields.hidden = !needsHandoff;
        inputs.forEach(input => { input.required = needsHandoff; });
    };
    select.addEventListener('change', sync);
    sync();
}());
</script>
<?php } ?>

<?php

require_once '../../../includes/modal_footer.php';
