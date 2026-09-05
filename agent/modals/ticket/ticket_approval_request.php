<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$task_id = intval($_GET['task_id'] ?? 0);
$ticket_id = intval($_GET['ticket_id'] ?? 0);
$target = 'ticket';
$target_name = '';

if ($task_id) {
    $target = 'task';
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_id, task_name, task_state,
        task_completed_at, task_ticket_id, ticket_id, ticket_prefix, ticket_number,
        ticket_subject, ticket_client_id, ticket_resolved_at, ticket_closed_at
        FROM tasks INNER JOIN tickets ON ticket_id = task_ticket_id
        WHERE task_id = $task_id LIMIT 1"));
    $ticket_id = intval($row['ticket_id'] ?? 0);
    $target_name = escapeHtml($row['task_name'] ?? '');
    $target_actionable = $row
        && empty($row['ticket_resolved_at'])
        && empty($row['ticket_closed_at'])
        && empty($row['task_completed_at'])
        && !in_array($row['task_state'], ['Completed', 'Skipped'], true);
} else {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix,
        ticket_number, ticket_subject, ticket_client_id, ticket_resolved_at,
        ticket_closed_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $target_actionable = $row && empty($row['ticket_resolved_at']) && empty($row['ticket_closed_at']);
}

if (!$row || !$target_actionable) {
    http_response_code(409);
    echo json_encode(['error' => 'This item no longer accepts approval requests']);
    exit;
}

$client_id = intval($row['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

$ticket_reference = escapeHtml((string) $row['ticket_prefix']) . intval($row['ticket_number']);
$ticket_subject = escapeHtml($row['ticket_subject']);
$active_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
    WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
    AND user_id <> " . intval($session_user_id) . "
    AND (
        EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
        OR EXISTS (SELECT 1 FROM user_role_permissions p
            INNER JOIN modules m ON m.module_id = p.module_id
            WHERE p.user_role_id = users.user_role_id
            AND m.module_name = 'module_support' AND p.user_role_permission_level >= 2)
    )
    AND (
        EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
        OR ($client_id > 0
            AND NOT EXISTS (SELECT 1 FROM user_client_permissions d
                WHERE d.user_id = users.user_id AND d.client_id = $client_id AND d.permission_type = 'deny')
            AND (NOT EXISTS (SELECT 1 FROM user_client_permissions a
                    WHERE a.user_id = users.user_id AND a.permission_type = 'allow')
                OR EXISTS (SELECT 1 FROM user_client_permissions a
                    WHERE a.user_id = users.user_id AND a.client_id = $client_id AND a.permission_type = 'allow')))
    )
    ORDER BY user_name");
$active_contacts = $client_id > 0
    ? mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email FROM contacts
        WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        ORDER BY contact_name, contact_id")
    : false;

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-check me-2"></i>Request approval</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approval_target" value="<?= $target ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <?php if ($task_id) { ?><input type="hidden" name="task_id" value="<?= $task_id ?>"><?php } ?>

    <div class="modal-body">
        <div class="approval-target-summary mb-4">
            <span class="approval-target-icon" aria-hidden="true"><i class="fas <?= $target === 'task' ? 'fa-tasks' : 'fa-ticket-alt' ?>"></i></span>
            <div>
                <strong><?= $target === 'task' ? $target_name : $ticket_subject ?></strong>
                <div class="text-muted small">
                    <?= $target === 'task'
                        ? "Task on ticket $ticket_reference. It cannot be completed until approved."
                        : "Ticket $ticket_reference. It cannot be resolved or closed until approved." ?>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="approval_route">Who should approve?</label>
            <select class="form-select" name="approval_route" id="approval_route" aria-describedby="approval_route_help" required autofocus>
                <option value="">Choose an approver...</option>
                <?php if ($client_id) { ?>
                    <optgroup label="Client contacts">
                        <option value="client:any">The contact on this ticket</option>
                        <option value="client:specific">A specific client contact</option>
                        <option value="client:manager">Any portal manager except the ticket contact</option>
                        <option value="client:technical">Any technical contact</option>
                        <option value="client:billing">Any billing contact</option>
                    </optgroup>
                <?php } ?>
                <optgroup label="Internal team">
                    <option value="internal:specific">A specific internal user</option>
                    <option value="internal:any">Any other internal user</option>
                </optgroup>
            </select>
            <div class="form-text" id="approval_route_help">The selected person or group will receive one approval request.</div>
        </div>

        <div class="mb-3" id="approval_user_wrapper" hidden>
            <label class="form-label" for="approval_required_user_id">Internal approver</label>
            <select class="form-select" name="approval_required_user_id" id="approval_required_user_id" disabled>
                <option value="">Choose a user...</option>
                <?php while ($user = mysqli_fetch_assoc($active_users)) { ?>
                    <option value="<?= intval($user['user_id']) ?>"><?= escapeHtml($user['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <?php if ($client_id) { ?>
            <div class="mb-3" id="approval_contact_wrapper" hidden>
                <label class="form-label" for="approval_required_contact_id">Client approver</label>
                <select class="form-select" name="approval_required_contact_id" id="approval_required_contact_id" disabled>
                    <option value="">Choose a client contact...</option>
                    <?php while ($contact = mysqli_fetch_assoc($active_contacts)) {
                        $contact_label = (string) $contact['contact_name'];
                        if (trim((string) $contact['contact_email']) !== '') {
                            $contact_label .= ' — ' . $contact['contact_email'];
                        }
                        ?>
                        <option value="<?= intval($contact['contact_id']) ?>"><?= escapeHtml($contact_label) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Only contacts from this ticket's client are available.</div>
            </div>
        <?php } ?>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="<?= $target === 'task' ? 'add_ticket_task_approver' : 'add_ticket_approval' ?>" class="btn btn-primary text-bold">
            <i class="fas fa-paper-plane me-2"></i>Send approval request
        </button>
    </div>
</form>

<script>
(function () {
    const route = document.getElementById('approval_route');
    const userWrapper = document.getElementById('approval_user_wrapper');
    const user = document.getElementById('approval_required_user_id');
    const contactWrapper = document.getElementById('approval_contact_wrapper');
    const contact = document.getElementById('approval_required_contact_id');
    const help = document.getElementById('approval_route_help');
    if (!route || !userWrapper || !user || !help) {
        return;
    }

    const routeHelp = {
        'client:any': 'Sends the request to the contact named on this ticket.',
        'client:specific': 'Only the client contact you choose can approve in the portal; any email link is sent only to them.',
        'client:manager': 'Sends the request to portal managers other than the contact named on this ticket.',
        'client:technical': 'Sends the request to the client\'s technical contacts.',
        'client:billing': 'Sends the request to the client\'s billing contacts.',
        'internal:specific': 'Only the internal user you choose can decide this request.',
        'internal:any': 'Any eligible internal user other than you can decide this request.'
    };

    const syncRoute = function () {
        const needsUser = route.value === 'internal:specific';
        userWrapper.hidden = !needsUser;
        user.disabled = !needsUser;
        user.required = needsUser;
        const needsContact = route.value === 'client:specific';
        if (contactWrapper && contact) {
            contactWrapper.hidden = !needsContact;
            contact.disabled = !needsContact;
            contact.required = needsContact;
        }
        help.textContent = routeHelp[route.value] || 'The selected person or group will receive one approval request.';
    };

    route.addEventListener('change', syncRoute);
    syncRoute();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
