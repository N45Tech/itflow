<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 3);

$approval_id = intval($_GET['id']);
$approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT approval_id, approval_scope,
    approval_type, approval_required_user_id, approval_created_by, approval_status,
    task_name, task_state, ticket_id, ticket_client_id, ticket_contact_id,
    ticket_assigned_to, ticket_project_id, ticket_created_at
    FROM task_approvals
    INNER JOIN tasks ON task_id = approval_task_id
    INNER JOIN tickets ON ticket_id = task_ticket_id
    WHERE approval_id = $approval_id AND approval_status IN ('pending','declined')
    AND task_state NOT IN ('Completed','Skipped') LIMIT 1"));

if (!$approval) {
    http_response_code(404);
    echo json_encode(['error' => 'Unresolved approval not found']);
    exit;
}

$client_id = intval($approval['ticket_client_id']);
if ($client_id) {
    enforceClientAccess();
}

[$route_available, $route_error] = runbookApprovalRouteAvailability(
    $approval['approval_scope'],
    $approval['approval_type'],
    intval($approval['approval_required_user_id']),
    $approval,
    intval($approval['approval_created_by'])
);
$current_route = $approval['approval_scope'] . ':' . $approval['approval_type'];
$current_user_id = intval($approval['approval_required_user_id']);
$task_name = escapeHtml($approval['task_name']);

$active_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
    WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
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

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-route mr-2"></i>Manage approval</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
</div>
<div class="modal-body">
    <p><strong><?= $task_name ?></strong></p>
    <p class="text-muted mb-2">
        Current route: <?= escapeHtml($current_route) ?> · Status: <?= escapeHtml($approval['approval_status']) ?>
    </p>
    <?php if (!$route_available) { ?>
        <div class="alert alert-warning py-2">
            <strong>This route cannot currently be fulfilled.</strong><br>
            <?= escapeHtml($route_error) ?>
        </div>
    <?php } ?>

    <form action="post.php" method="post" class="mb-4">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="approval_id" value="<?= $approval_id ?>">
        <div class="form-group">
            <label for="approval_route_<?= $approval_id ?>">New approval route</label>
            <select class="form-control" name="approval_route" id="approval_route_<?= $approval_id ?>" required>
                <option value="internal:specific" <?= $current_route === 'internal:specific' ? 'selected' : '' ?>>Specific internal approver</option>
                <option value="internal:any" <?= $current_route === 'internal:any' ? 'selected' : '' ?>>Any other internal user</option>
                <option value="client:any" <?= $current_route === 'client:any' ? 'selected' : '' ?>>Ticket contact</option>
                <option value="client:technical" <?= $current_route === 'client:technical' ? 'selected' : '' ?>>Technical client contact</option>
                <option value="client:billing" <?= $current_route === 'client:billing' ? 'selected' : '' ?>>Billing client contact</option>
            </select>
        </div>
        <div class="form-group" id="approval_user_wrapper_<?= $approval_id ?>">
            <label for="approval_user_<?= $approval_id ?>">Specific internal approver</label>
            <select class="form-control select2" name="approval_required_user_id" id="approval_user_<?= $approval_id ?>">
                <option value="0">Select an active user</option>
                <?php while ($user = mysqli_fetch_assoc($active_users)) { ?>
                    <option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $current_user_id ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label for="approval_reroute_reason_<?= $approval_id ?>">Reroute reason</label>
            <input type="text" class="form-control" name="approval_reason" id="approval_reroute_reason_<?= $approval_id ?>" maxlength="255" required
                   placeholder="Approver left, contact unavailable, role changed…">
        </div>
        <button type="submit" name="reroute_ticket_task_approval" class="btn btn-primary btn-block">
            <i class="fas fa-route mr-2"></i>Reroute and Re-request
        </button>
    </form>

    <?php if ($route_available) { ?>
        <hr>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="approval_id" value="<?= $approval_id ?>">
            <div class="form-group">
                <label for="approval_retry_reason_<?= $approval_id ?>">Re-request reason</label>
                <input type="text" class="form-control" name="approval_reason" id="approval_retry_reason_<?= $approval_id ?>" maxlength="255" required
                       placeholder="Why a new decision is needed">
            </div>
            <button type="submit" name="retry_ticket_task_approval" class="btn btn-outline-secondary btn-block">
                <i class="fas fa-redo mr-2"></i>Re-request Current Route
            </button>
        </form>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>

<script>
(function () {
    const route = document.getElementById('approval_route_<?= $approval_id ?>');
    const wrapper = document.getElementById('approval_user_wrapper_<?= $approval_id ?>');
    if (!route || !wrapper) {
        return;
    }
    const syncSpecificUser = function () {
        wrapper.hidden = route.value !== 'internal:specific';
    };
    route.addEventListener('change', syncSpecificUser);
    syncSpecificUser();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
