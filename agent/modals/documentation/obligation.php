<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support');

$obligation_id = intval($_GET['id'] ?? 0);
$documentation_validity = documentationObligationValiditySql('o');
$obligation = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT o.*,
    c.client_name, d.document_name,
    owner.user_name AS owner_name, reviewer.user_name AS reviewer_name,
    {$documentation_validity['select']}
    FROM client_documentation_obligations o
    INNER JOIN clients c ON c.client_id = o.documentation_obligation_client_id
    LEFT JOIN documents d ON d.document_id = o.documentation_obligation_document_id
    LEFT JOIN users owner ON owner.user_id = o.documentation_obligation_owner_user_id
    LEFT JOIN users reviewer ON reviewer.user_id = o.documentation_obligation_reviewer_user_id
    {$documentation_validity['joins']}
    WHERE o.documentation_obligation_id = $obligation_id LIMIT 1"));

if (!$obligation) {
    exit('<div class="modal-body"><div class="alert alert-danger mb-0">The documentation obligation is unavailable.</div></div>');
}

$obligation = documentationApplyCurrentRequirementMetadata($obligation);
$projection = documentationProjectObligationValidity($obligation);
$client_id = intval($obligation['documentation_obligation_client_id']);
enforceClientAccess($client_id);
$document_id = intval($obligation['documentation_obligation_document_id']);
$revision = intval($obligation['documentation_obligation_revision']);
$status = $projection['effective_status'];
$projection_mutable = $projection['requirement_active'] && $projection['requirement_current'];
$can_edit = lookupUserPermission('module_support') >= 2 && $projection_mutable;
$can_approve = lookupUserPermission('module_support') >= 3 && $projection_mutable;
$owner_roles = function_exists('documentationOwnerRoles')
    ? documentationOwnerRoles()
    : ['documentation_owner', 'service_owner', 'account_manager', 'security_lead', 'support_lead', 'unassigned'];
$assignable_users = [];
$sql_assignable_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
    WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
    AND (
        EXISTS (SELECT 1 FROM user_roles r WHERE r.role_id = users.user_role_id AND r.role_is_admin = 1)
        OR EXISTS (SELECT 1 FROM user_role_permissions p
            INNER JOIN modules m ON m.module_id = p.module_id
            WHERE p.user_role_id = users.user_role_id
            AND m.module_name = 'module_support' AND p.user_role_permission_level >= 1)
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
while ($user = mysqli_fetch_assoc($sql_assignable_users)) {
    $assignable_users[] = $user;
}

$verification_ticket_id = intval($_GET['ticket_id'] ?? 0);
$verification_tickets = [];
$sql_verification_tickets = mysqli_query($mysqli, "SELECT ticket.ticket_id,
    ticket.ticket_prefix, ticket.ticket_number, ticket.ticket_subject
    FROM ticket_documentation_obligations link
    INNER JOIN tickets ticket ON ticket.ticket_id = link.ticket_documentation_obligation_ticket_id
        AND ticket.ticket_deleted_at IS NULL
    WHERE link.ticket_documentation_obligation_obligation_id = $obligation_id
    AND link.ticket_documentation_obligation_client_id = $client_id
    AND ticket.ticket_client_id = $client_id
    ORDER BY ticket.ticket_id DESC LIMIT 50");
while ($linked_ticket = mysqli_fetch_assoc($sql_verification_tickets)) {
    $verification_tickets[] = $linked_ticket;
}
if ($verification_ticket_id
    && !in_array($verification_ticket_id, array_map(static fn ($ticket) => intval($ticket['ticket_id']), $verification_tickets), true)) {
    exit('<div class="modal-body"><div class="alert alert-danger mb-0">The verification ticket is not linked to this obligation.</div></div>');
}

$documents = mysqli_query($mysqli, "SELECT document_id, document_name FROM documents
    WHERE document_client_id = $client_id AND document_archived_at IS NULL
    ORDER BY document_name");
$evidence_files = mysqli_query($mysqli, "SELECT file_id, file_name FROM files
    WHERE file_client_id = $client_id AND file_archived_at IS NULL
    AND file_deleted_at IS NULL ORDER BY file_name");
$events = mysqli_query($mysqli, "SELECT e.*, u.user_name
    FROM documentation_obligation_events e
    LEFT JOIN users u ON u.user_id = e.documentation_obligation_event_actor_id
    WHERE e.documentation_obligation_event_obligation_id = $obligation_id
    ORDER BY e.documentation_obligation_event_id DESC LIMIT 20");

?>

<div class="modal-header bg-dark">
    <div>
        <h5 class="modal-title"><i class="fas fa-book-medical mr-2"></i><?= escapeHtml($obligation['documentation_requirement_version_name']) ?></h5>
        <div class="small text-light"><code class="text-light"><?= escapeHtml($obligation['documentation_requirement_version_key']) ?></code> · <?= escapeHtml($obligation['client_name']) ?></div>
    </div>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body">
    <?php if (!$projection_mutable) { ?>
        <div class="alert alert-warning"><i class="fas fa-sync-alt mr-2"></i><strong>Reconciliation required.</strong> The published requirement changed or was archived. This historical projection is read-only until the client evaluator pins the active version.</div>
    <?php } ?>
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <span class="badge badge-<?= documentationLifecycleStatusBadge($status) ?> p-2"><?= escapeHtml($status) ?></span>
            <span class="badge badge-light p-2 ml-1"><?= escapeHtml($obligation['documentation_requirement_version_record_type']) ?></span>
        </div>
        <a href="/agent/documentation.php?client_id=<?= $client_id ?>&owner=all" target="_top">Open client matrix</a>
    </div>
    <?php if (!empty($obligation['documentation_requirement_version_description'])) { ?><p><?= nl2br(escapeHtml($obligation['documentation_requirement_version_description'])) ?></p><?php } ?>

    <dl class="row small">
        <dt class="col-sm-4">Owner</dt><dd class="col-sm-8"><?= escapeHtml($obligation['owner_name'] ?: $obligation['documentation_obligation_owner_role'] ?: 'Unassigned') ?></dd>
        <dt class="col-sm-4">Reviewer</dt><dd class="col-sm-8"><?= escapeHtml($obligation['reviewer_name'] ?: $obligation['documentation_obligation_reviewer_role'] ?: 'Unassigned') ?></dd>
        <dt class="col-sm-4">Last verified</dt><dd class="col-sm-8"><?= escapeHtml($obligation['documentation_obligation_last_verified_at'] ?: 'Never') ?></dd>
        <dt class="col-sm-4">Next review</dt><dd class="col-sm-8"><?= escapeHtml($obligation['documentation_obligation_next_review_at'] ?: 'Not scheduled') ?></dd>
        <dt class="col-sm-4">Evaluation</dt><dd class="col-sm-8"><?= escapeHtml($obligation['documentation_obligation_evaluation_reason_code']) ?></dd>
    </dl>

    <?php if ($can_edit) { ?>
        <div class="card bg-light">
            <div class="card-body py-3">
                <form action="post.php" method="post" class="mb-3 pb-3 border-bottom">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                    <input type="hidden" name="expected_revision" value="<?= $revision ?>">
                    <div class="form-row">
                        <div class="form-group col-md-3"><label>Owner role</label><select class="form-control form-control-sm" name="owner_role" required><?php foreach ($owner_roles as $role) { ?><option value="<?= escapeHtml($role) ?>" <?= $obligation['documentation_obligation_owner_role'] === $role ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $role))) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-3"><label>Owner</label><select class="form-control form-control-sm select2" name="owner_user_id"><option value="0">Role queue / unassigned</option><?php foreach ($assignable_users as $user) { ?><option value="<?= intval($user['user_id']) ?>" <?= intval($obligation['documentation_obligation_owner_user_id']) === intval($user['user_id']) ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-3"><label>Reviewer role</label><select class="form-control form-control-sm" name="reviewer_role" required><?php foreach ($owner_roles as $role) { ?><option value="<?= escapeHtml($role) ?>" <?= $obligation['documentation_obligation_reviewer_role'] === $role ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('_', ' ', $role))) ?></option><?php } ?></select></div>
                        <div class="form-group col-md-3"><label>Reviewer</label><select class="form-control form-control-sm select2" name="reviewer_user_id"><option value="0">Role queue / unassigned</option><?php foreach ($assignable_users as $user) { ?><option value="<?= intval($user['user_id']) ?>" <?= intval($obligation['documentation_obligation_reviewer_user_id']) === intval($user['user_id']) ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option><?php } ?></select></div>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" name="assign_documentation_obligation"><i class="fas fa-user-tag mr-1"></i>Save ownership</button>
                </form>

                <form action="post.php" method="post" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                    <input type="hidden" name="expected_revision" value="<?= $revision ?>">
                    <div class="form-group">
                        <label>Canonical document</label>
                        <select class="form-control select2" name="document_id" required>
                            <option value="">Select a client document</option>
                            <?php while ($document = mysqli_fetch_assoc($documents)) { ?>
                                <option value="<?= intval($document['document_id']) ?>" <?= intval($document['document_id']) === $document_id ? 'selected' : '' ?>><?= escapeHtml($document['document_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <?php if ($obligation['documentation_requirement_version_evidence_policy'] === 'note') { ?>
                        <div class="form-group"><label>Verification evidence note</label><textarea class="form-control" name="evidence_note" rows="2" maxlength="2000" placeholder="Record what was checked and where the supporting proof can be found"></textarea><small class="form-text text-muted">The audit ledger stores a one-way digest, not this raw note.</small></div>
                    <?php } elseif ($obligation['documentation_requirement_version_evidence_policy'] === 'file') { ?>
                        <div class="form-group"><label>Verification evidence file</label><select class="form-control select2" name="evidence_file_id"><option value="">Select an active client file</option><?php while ($evidence_file = mysqli_fetch_assoc($evidence_files)) { ?><option value="<?= intval($evidence_file['file_id']) ?>"><?= escapeHtml($evidence_file['file_name']) ?></option><?php } ?></select></div>
                    <?php } ?>
                    <?php if ($verification_tickets) { ?>
                        <div class="form-group"><label>Verification ticket</label><select class="form-control" name="ticket_id"><option value="0">No ticket attribution</option><?php foreach ($verification_tickets as $linked_ticket) { $linked_ticket_id = intval($linked_ticket['ticket_id']); ?><option value="<?= $linked_ticket_id ?>" <?= $verification_ticket_id === $linked_ticket_id ? 'selected' : '' ?>><?= escapeHtml($linked_ticket['ticket_prefix'] . $linked_ticket['ticket_number'] . ' — ' . $linked_ticket['ticket_subject']) ?></option><?php } ?></select><small class="form-text text-muted">Only tickets explicitly linked to this obligation can be recorded as the verification source.</small></div>
                    <?php } ?>
                    <button class="btn btn-outline-primary btn-sm" name="link_documentation_obligation_document"><i class="fas fa-link mr-1"></i>Link document</button>
                    <?php if (in_array($obligation['documentation_requirement_version_evidence_policy'], ['none', 'note', 'file', 'reference'], true)) { ?>
                        <button class="btn btn-success btn-sm" name="verify_documentation_obligation"><i class="fas fa-check-double mr-1"></i>Verify current revision</button>
                    <?php } ?>
                </form>

                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                    <input type="hidden" name="expected_revision" value="<?= $revision ?>">
                    <div class="form-row">
                        <div class="form-group col-md-7"><label>Exception reason</label><input class="form-control" name="reason" required maxlength="255" autocomplete="off"></div>
                        <div class="form-group col-md-5"><label>Expires</label><input class="form-control" type="datetime-local" name="expires_at" required></div>
                    </div>
                    <button class="btn btn-outline-warning btn-sm" name="request_documentation_exception"><i class="fas fa-clock mr-1"></i>Request exception</button>
                </form>
            </div>
        </div>
    <?php } ?>

    <?php if ($projection['exception_current'] && $obligation['documentation_obligation_exception_status'] === 'Pending') { ?>
        <div class="alert alert-warning">
            <strong>Exception approval pending.</strong>
            <div class="small mt-1"><?= escapeHtml($obligation['documentation_obligation_exception_reason_redacted']) ?> · expires <?= escapeHtml($obligation['documentation_obligation_exception_expires_at']) ?></div>
            <?php if ($can_approve && intval($obligation['documentation_obligation_exception_requested_by']) !== intval($session_user_id)) { ?>
                <form action="post.php" method="post" class="mt-2 d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                    <input type="hidden" name="expected_revision" value="<?= $revision ?>">
                    <button class="btn btn-success btn-sm" name="approve_documentation_exception">Approve</button>
                    <button class="btn btn-outline-danger btn-sm" name="reject_documentation_exception">Reject</button>
                </form>
            <?php } ?>
        </div>
    <?php } elseif ($projection['exception_current'] && $obligation['documentation_obligation_exception_status'] === 'Approved') { ?>
        <div class="alert alert-primary">
            <strong>Approved exception.</strong>
            <div class="small mt-1">Expires <?= escapeHtml($obligation['documentation_obligation_exception_expires_at']) ?></div>
            <?php if ($can_approve && intval($obligation['documentation_obligation_exception_requested_by']) !== intval($session_user_id)) { ?>
                <form action="post.php" method="post" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                    <input type="hidden" name="expected_revision" value="<?= $revision ?>">
                    <button class="btn btn-outline-danger btn-sm" name="revoke_documentation_exception"><i class="fas fa-ban mr-1"></i>Revoke exception</button>
                </form>
            <?php } ?>
        </div>
    <?php } ?>

    <h6 class="mt-4">Audit history</h6>
    <div class="list-group list-group-flush">
        <?php while ($event = mysqli_fetch_assoc($events)) { ?>
            <div class="list-group-item px-0 py-2">
                <strong><?= escapeHtml(ucwords(str_replace('_', ' ', $event['documentation_obligation_event_action']))) ?></strong>
                <span class="text-muted small">by <?= escapeHtml($event['user_name'] ?: $event['documentation_obligation_event_actor_type']) ?> · <?= escapeHtml($event['documentation_obligation_event_created_at']) ?></span>
                <div class="small text-muted"><?= escapeHtml($event['documentation_obligation_event_reason_code']) ?></div>
            </div>
        <?php } ?>
        <?php if (!mysqli_num_rows($events)) { ?><div class="text-muted small">No events recorded yet.</div><?php } ?>
    </div>
</div>

<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Close</button></div>
