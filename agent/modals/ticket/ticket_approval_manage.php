<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$target = ($_GET['target'] ?? 'ticket') === 'task' ? 'task' : 'ticket';
$approval_id = intval($_GET['id'] ?? 0);

if ($target === 'task') {
    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_id,
        approval_scope, approval_type, approval_required_user_id,
        approval_required_contact_id,
        approval_created_by, approval_status, task_id, task_name, task_state,
        ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id,
        ticket_contact_id, ticket_assigned_to, ticket_project_id, ticket_created_at,
        ticket_resolved_at, ticket_closed_at, user_name AS required_user_name,
        required_contact.contact_name AS required_contact_name
        FROM task_approvals
        INNER JOIN tasks ON task_id = approval_task_id
        INNER JOIN tickets ON ticket_id = task_ticket_id
        LEFT JOIN users ON user_id = approval_required_user_id
        LEFT JOIN contacts required_contact
            ON required_contact.contact_id = approval_required_contact_id
            AND required_contact.contact_client_id = ticket_client_id
        WHERE approval_id = $approval_id
        AND approval_status IN ('pending','declined')
        AND task_state NOT IN ('Completed','Skipped') LIMIT 1"));
    $scope = (string) ($approval['approval_scope'] ?? '');
    $type = (string) ($approval['approval_type'] ?? '');
    $required_user_id = intval($approval['approval_required_user_id'] ?? 0);
    $required_contact_id = intval($approval['approval_required_contact_id'] ?? 0);
    $created_by = intval($approval['approval_created_by'] ?? 0);
    $status = (string) ($approval['approval_status'] ?? '');
    $target_name = escapeHtml($approval['task_name'] ?? '');
} else {
    $approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_approval_id,
        ticket_approval_scope, ticket_approval_type,
        ticket_approval_required_user_id, ticket_approval_required_contact_id,
        ticket_approval_created_by,
        ticket_approval_status, ticket_id, ticket_prefix, ticket_number,
        ticket_subject, ticket_client_id, ticket_contact_id, ticket_assigned_to,
        ticket_project_id, ticket_created_at, ticket_resolved_at, ticket_closed_at,
        user_name AS required_user_name,
        required_contact.contact_name AS required_contact_name
        FROM ticket_approvals
        INNER JOIN tickets ON ticket_id = ticket_approval_ticket_id
        LEFT JOIN users ON user_id = ticket_approval_required_user_id
        LEFT JOIN contacts required_contact
            ON required_contact.contact_id = ticket_approval_required_contact_id
            AND required_contact.contact_client_id = ticket_client_id
        WHERE ticket_approval_id = $approval_id
        AND ticket_approval_status IN ('pending','declined') LIMIT 1"));
    $scope = (string) ($approval['ticket_approval_scope'] ?? '');
    $type = (string) ($approval['ticket_approval_type'] ?? '');
    $required_user_id = intval($approval['ticket_approval_required_user_id'] ?? 0);
    $required_contact_id = intval($approval['ticket_approval_required_contact_id'] ?? 0);
    $created_by = intval($approval['ticket_approval_created_by'] ?? 0);
    $status = (string) ($approval['ticket_approval_status'] ?? '');
    $target_name = escapeHtml($approval['ticket_subject'] ?? '');
}

if (!$approval || !empty($approval['ticket_resolved_at']) || !empty($approval['ticket_closed_at'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Unresolved approval not found']);
    exit;
}

$client_id = intval($approval['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

[$route_available, $route_error] = runbookApprovalRouteAvailability(
    $scope,
    $type,
    $required_user_id,
    $approval,
    $created_by,
    $required_contact_id
);
$current_route = $scope . ':' . $type;
$current_route_label = approvalRouteLabel(
    $scope,
    $type,
    $approval['required_user_name'] ?? '',
    $approval['required_contact_name'] ?? ''
);
$ticket_reference = escapeHtml((string) $approval['ticket_prefix']) . intval($approval['ticket_number']);
$active_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
    WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
    AND user_id <> $created_by
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
    <h5 class="modal-title"><i class="fas fa-fw fa-user-check me-2"></i>Manage approval</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approval_id" value="<?= $approval_id ?>">

    <div class="modal-body">
        <div class="approval-target-summary mb-3">
            <span class="approval-target-icon" aria-hidden="true"><i class="fas <?= $target === 'task' ? 'fa-tasks' : 'fa-ticket-alt' ?>"></i></span>
            <div>
                <strong><?= $target_name ?></strong>
                <div class="text-muted small"><?= $target === 'task' ? "Task on ticket $ticket_reference" : "Entire ticket $ticket_reference" ?></div>
            </div>
            <span class="badge <?= $status === 'declined' ? 'bg-danger' : 'bg-warning text-dark' ?> ms-auto align-self-start"><?= ucfirst(escapeHtml($status)) ?></span>
        </div>

        <dl class="approval-current-route mb-4">
            <dt>Currently sent to</dt>
            <dd><?= escapeHtml($current_route_label) ?></dd>
        </dl>

        <?php if (!$route_available) { ?>
            <div class="alert alert-warning py-2">
                <strong>No eligible approver is available on this route.</strong>
                <div><?= escapeHtml($route_error) ?></div>
            </div>
        <?php } elseif ($status === 'declined') { ?>
            <div class="alert alert-danger py-2 mb-3">
                This request was declined. Choose who should review it next, add a reason, and send it again.
            </div>
        <?php } ?>

        <div class="mb-3">
            <label class="form-label" for="approval_route_<?= $target ?>_<?= $approval_id ?>">Send the next request to</label>
            <select class="form-select" name="approval_route" id="approval_route_<?= $target ?>_<?= $approval_id ?>" required>
                <?php if ($client_id) { ?>
                    <optgroup label="Client contacts">
                        <option value="client:any" <?= $current_route === 'client:any' ? 'selected' : '' ?>>The contact on this ticket</option>
                        <option value="client:specific" <?= $current_route === 'client:specific' ? 'selected' : '' ?>>A specific client contact</option>
                        <option value="client:manager" <?= $current_route === 'client:manager' ? 'selected' : '' ?>>Any portal manager except the ticket contact</option>
                        <option value="client:technical" <?= $current_route === 'client:technical' ? 'selected' : '' ?>>Any technical contact</option>
                        <option value="client:billing" <?= $current_route === 'client:billing' ? 'selected' : '' ?>>Any billing contact</option>
                    </optgroup>
                <?php } ?>
                <optgroup label="Internal team">
                    <option value="internal:specific" <?= $current_route === 'internal:specific' ? 'selected' : '' ?>>A specific internal user</option>
                    <option value="internal:any" <?= $current_route === 'internal:any' ? 'selected' : '' ?>>Any other internal user</option>
                </optgroup>
            </select>
        </div>

        <div class="mb-3" id="approval_user_wrapper_<?= $target ?>_<?= $approval_id ?>" hidden>
            <label class="form-label" for="approval_user_<?= $target ?>_<?= $approval_id ?>">Internal approver</label>
            <select class="form-select" name="approval_required_user_id" id="approval_user_<?= $target ?>_<?= $approval_id ?>" disabled>
                <option value="">Choose a user...</option>
                <?php while ($user = mysqli_fetch_assoc($active_users)) { ?>
                    <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $required_user_id ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <?php if ($client_id) { ?>
            <div class="mb-3" id="approval_contact_wrapper_<?= $target ?>_<?= $approval_id ?>" hidden>
                <label class="form-label" for="approval_contact_<?= $target ?>_<?= $approval_id ?>">Client approver</label>
                <select class="form-select" name="approval_required_contact_id" id="approval_contact_<?= $target ?>_<?= $approval_id ?>" disabled>
                    <option value="">Choose a client contact...</option>
                    <?php while ($contact = mysqli_fetch_assoc($active_contacts)) {
                        $contact_label = (string) $contact['contact_name'];
                        if (trim((string) $contact['contact_email']) !== '') {
                            $contact_label .= ' — ' . $contact['contact_email'];
                        }
                        ?>
                        <option value="<?= intval($contact['contact_id']) ?>" <?= intval($contact['contact_id']) === $required_contact_id ? 'selected' : '' ?>><?= escapeHtml($contact_label) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Only contacts from this ticket's client are available.</div>
            </div>
        <?php } ?>

        <div class="mb-0">
            <label class="form-label" for="approval_reason_<?= $target ?>_<?= $approval_id ?>">Why are you sending it again?</label>
            <input type="text" class="form-control" name="approval_reason" id="approval_reason_<?= $target ?>_<?= $approval_id ?>" maxlength="255" required
                   placeholder="For example: corrected approver or additional review needed">
            <div class="form-text">This reason is kept in the approval history.</div>
        </div>
    </div>

    <div class="modal-footer approval-manage-actions">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <?php if ($route_available) { ?>
            <button type="submit" name="<?= $target === 'task' ? 'retry_ticket_task_approval' : 'retry_ticket_approval' ?>" class="btn btn-outline-secondary">
                Send again unchanged
            </button>
        <?php } ?>
        <button type="submit" name="<?= $target === 'task' ? 'reroute_ticket_task_approval' : 'reroute_ticket_approval' ?>" class="btn btn-primary">
            <i class="fas fa-paper-plane me-2"></i>Save and send again
        </button>
    </div>
</form>

<script>
(function () {
    const route = document.getElementById('approval_route_<?= $target ?>_<?= $approval_id ?>');
    const wrapper = document.getElementById('approval_user_wrapper_<?= $target ?>_<?= $approval_id ?>');
    const user = document.getElementById('approval_user_<?= $target ?>_<?= $approval_id ?>');
    const contactWrapper = document.getElementById('approval_contact_wrapper_<?= $target ?>_<?= $approval_id ?>');
    const contact = document.getElementById('approval_contact_<?= $target ?>_<?= $approval_id ?>');
    if (!route || !wrapper || !user) {
        return;
    }
    const syncSpecificUser = function () {
        const needsUser = route.value === 'internal:specific';
        wrapper.hidden = !needsUser;
        user.disabled = !needsUser;
        user.required = needsUser;
        const needsContact = route.value === 'client:specific';
        if (contactWrapper && contact) {
            contactWrapper.hidden = !needsContact;
            contact.disabled = !needsContact;
            contact.required = needsContact;
        }
    };
    route.addEventListener('change', syncSpecificUser);
    syncSpecificUser();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
