<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support');

$ticket_id = intval($_GET['ticket_id'] ?? 0);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_client_id,
    ticket_configuration_change, ticket_documentation_impact,
    ticket_documentation_assessed_by, ticket_documentation_assessed_at,
    ticket_resolved_at, ticket_closed_at
    FROM tickets WHERE ticket_id = $ticket_id " . clientScopeSql('ticket_client_id') . " LIMIT 1"));
if (!$ticket) {
    exit('<div class="modal-body"><div class="alert alert-danger mb-0">The ticket is unavailable.</div></div>');
}

$client_id = intval($ticket['ticket_client_id']);
if ($client_id) {
    enforceClientAccess($client_id);
}
$can_edit = lookupUserPermission('module_support') >= 2
    && empty($ticket['ticket_resolved_at']) && empty($ticket['ticket_closed_at']);
$can_manage_promises = lookupUserPermission('module_support') >= 2;
$can_decide_waiver = lookupUserPermission('module_support') >= 3;
$has_documentation_history = documentationTicketHasAuditRecords($ticket_id);
$impact_downgrade_locked = $has_documentation_history
    && $ticket['ticket_documentation_impact'] === 'Required';
$configuration_downgrade_locked = $has_documentation_history
    && intval($ticket['ticket_configuration_change']) === 1;
$documentation_validity = documentationObligationValiditySql('o');

$links = [];
$sql_links = mysqli_query($mysqli, "SELECT l.*, o.*,
    d.document_name, w.ticket_documentation_waiver_id,
    w.ticket_documentation_waiver_status, w.ticket_documentation_waiver_reason_redacted,
    w.ticket_documentation_waiver_reason_hash,
    w.ticket_documentation_waiver_requested_by, w.ticket_documentation_waiver_expires_at,
    w.ticket_documentation_waiver_revision,
    (SELECT CASE WHEN COUNT(*) = 1
        THEN MIN(requested.ticket_documentation_waiver_event_context_hash) ELSE '' END
        FROM ticket_documentation_waiver_events requested
        WHERE requested.ticket_documentation_waiver_event_waiver_id = w.ticket_documentation_waiver_id
        AND requested.ticket_documentation_waiver_event_action = 'requested')
        AS ticket_documentation_waiver_request_context_hash,
    {$documentation_validity['select']}
    FROM ticket_documentation_obligations l
    INNER JOIN client_documentation_obligations o
        ON o.documentation_obligation_id = l.ticket_documentation_obligation_obligation_id
        AND o.documentation_obligation_client_id = l.ticket_documentation_obligation_client_id
    LEFT JOIN documents d ON d.document_id = o.documentation_obligation_document_id
    LEFT JOIN ticket_documentation_waivers w
        ON w.ticket_documentation_waiver_id = (
            SELECT latest.ticket_documentation_waiver_id
            FROM ticket_documentation_waivers latest
            WHERE latest.ticket_documentation_waiver_link_id = l.ticket_documentation_obligation_id
            ORDER BY latest.ticket_documentation_waiver_id DESC LIMIT 1
        )
    {$documentation_validity['joins']}
    WHERE l.ticket_documentation_obligation_ticket_id = $ticket_id
    AND l.ticket_documentation_obligation_client_id = $client_id
    ORDER BY documentation_current_requirement_version_name");
while ($row = mysqli_fetch_assoc($sql_links)) {
    $row = documentationApplyCurrentRequirementMetadata($row);
    $projection = documentationProjectObligationValidity($row);
    $row['effective_status'] = $projection['effective_status'];
    $row['documentation_projection_mutable'] = $projection['requirement_active'] && $projection['requirement_current'];
    $links[] = $row;
}

$promises_by_obligation = [];
$sql_promises = mysqli_query($mysqli, "SELECT documentation_promise_id,
    documentation_promise_obligation_id, documentation_promise_status,
    documentation_promise_reason_code, documentation_promise_due_at,
    documentation_promise_promised_at, documentation_promise_revision
    FROM documentation_promise_ledger
    WHERE documentation_promise_ticket_id = $ticket_id
    AND documentation_promise_client_id = $client_id
    AND documentation_promise_status = 'Open'
    ORDER BY documentation_promise_due_at, documentation_promise_id");
while ($promise = mysqli_fetch_assoc($sql_promises)) {
    $promise_obligation_id = intval($promise['documentation_promise_obligation_id']);
    $promises_by_obligation[$promise_obligation_id][] = $promise;
}

$available_obligations = [];
$sql_available_obligations = mysqli_query($mysqli, "SELECT o.*,
    {$documentation_validity['select']}
    FROM client_documentation_obligations o
    {$documentation_validity['joins']}
    WHERE o.documentation_obligation_client_id = $client_id
    AND o.documentation_obligation_applicable = 1
    AND NOT EXISTS (
        SELECT 1 FROM ticket_documentation_obligations l
        WHERE l.ticket_documentation_obligation_ticket_id = $ticket_id
        AND l.ticket_documentation_obligation_obligation_id = o.documentation_obligation_id
    )
    ORDER BY documentation_current_requirement_version_name");
while ($available = mysqli_fetch_assoc($sql_available_obligations)) {
    $available = documentationApplyCurrentRequirementMetadata($available);
    $available_projection = documentationProjectObligationValidity($available);
    if (!$available_projection['requirement_active'] || !$available_projection['requirement_current']) {
        continue;
    }
    $available['effective_status'] = $available_projection['effective_status'];
    $available_obligations[] = $available;
}

[$gate_allowed, $gate_error] = ticketLifecycleCanResolve($ticket_id, true);

ob_start();

?>

<div class="modal-header bg-dark">
    <div>
        <h5 class="modal-title"><i class="fas fa-book-medical mr-2"></i>Ticket documentation impact</h5>
        <div class="small text-light">Assessment, affected records, and resolution waivers</div>
    </div>
    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body">
    <div class="alert alert-<?= $gate_allowed ? 'success' : 'warning' ?>">
        <i class="fas fa-<?= $gate_allowed ? 'check-circle' : 'lock' ?> mr-2"></i>
        <strong><?= $gate_allowed ? 'Lifecycle gates satisfied' : 'Resolution is blocked' ?></strong>
        <?php if (!$gate_allowed) { ?><div class="small mt-1"><?= escapeHtml($gate_error) ?></div><?php } ?>
    </div>

    <div class="d-flex align-items-center mb-3">
        <span class="badge badge-<?= documentationTicketImpactBadge($ticket['ticket_documentation_impact']) ?> p-2 mr-2"><?= escapeHtml($ticket['ticket_documentation_impact']) ?></span>
        <?php if (intval($ticket['ticket_configuration_change'])) { ?><span class="badge badge-dark p-2"><i class="fas fa-cogs mr-1"></i>Configuration change</span><?php } ?>
        <?php if ($ticket['ticket_documentation_assessed_at']) { ?><span class="small text-muted ml-auto">Assessed <?= escapeHtml($ticket['ticket_documentation_assessed_at']) ?></span><?php } ?>
    </div>

    <?php if ($can_edit) { ?>
        <form action="post.php" method="post" class="card bg-light mb-3">
            <div class="card-body py-3">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <div class="form-row align-items-end">
                    <div class="form-group col-md-6 mb-md-0">
                        <label>Impact assessment</label>
                        <select class="form-control" name="documentation_impact" required>
                            <?php if (!in_array($ticket['ticket_documentation_impact'], ['None', 'Required'], true)) { ?><option value="" selected disabled>Unassessed — select an impact</option><?php } ?>
                            <?php if (!$impact_downgrade_locked) { ?><option value="None" <?= $ticket['ticket_documentation_impact'] === 'None' ? 'selected' : '' ?>>No required documentation affected</option><?php } ?>
                            <option value="Required" <?= $ticket['ticket_documentation_impact'] === 'Required' ? 'selected' : '' ?>>Required documentation affected</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3 mb-md-0"><?php if ($configuration_downgrade_locked) { ?><input type="hidden" name="configuration_change" value="1"><?php } ?><div class="custom-control custom-switch mb-2"><input class="custom-control-input" type="checkbox" name="configuration_change" value="1" id="documentationConfigurationChange" <?= intval($ticket['ticket_configuration_change']) ? 'checked' : '' ?> <?= $configuration_downgrade_locked ? 'disabled' : '' ?>><label class="custom-control-label" for="documentationConfigurationChange">Config change</label></div></div>
                    <div class="form-group col-md-3 mb-0"><button class="btn btn-primary btn-block" name="assess_ticket_documentation">Save</button></div>
                </div>
                <?php if ($impact_downgrade_locked || $configuration_downgrade_locked) { ?><div class="small text-muted mt-2"><i class="fas fa-lock mr-1"></i>Audit history exists. Required impact and configuration-change flags cannot be downgraded without a future audited reversal workflow.</div><?php } ?>
            </div>
        </form>
    <?php } ?>

    <h6>Affected obligations</h6>
    <?php foreach ($links as $link) {
        $link_id = intval($link['ticket_documentation_obligation_id']);
        $obligation_id = intval($link['documentation_obligation_id']);
        $open_promises = $promises_by_obligation[$obligation_id] ?? [];
        $status = $link['effective_status'];
        $projection_mutable = !empty($link['documentation_projection_mutable']);
        $current_document_name = !empty($link['current_document_exists']) ? $link['document_name'] : '';
        $stored_waiver_status = (string) ($link['ticket_documentation_waiver_status'] ?? '');
        $waiver_current = documentationTicketWaiverPinsObligationVersion($link, $link);
        $waiver_status = $waiver_current ? $stored_waiver_status : '';
        $waiver_active = $projection_mutable
            && documentationTicketWaiverIsActiveForObligation($link, $link);
        ?>
        <div class="card mb-2">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between">
                    <div><strong><?= escapeHtml($link['documentation_requirement_version_name']) ?></strong><div class="small text-muted"><code><?= escapeHtml($link['documentation_requirement_version_key']) ?></code> · <?= escapeHtml($current_document_name ?: 'No current canonical document linked') ?></div><?php if ($can_edit && $projection_mutable) { ?><a href="#" class="small ajax-modal" data-modal-size="lg" data-modal-url="modals/documentation/obligation.php?id=<?= $obligation_id ?>&ticket_id=<?= $ticket_id ?>"><i class="fas fa-check-double mr-1"></i>Review or verify</a><?php } elseif (!$projection_mutable) { ?><span class="small text-warning"><i class="fas fa-sync-alt mr-1"></i>Reconcile before changes</span><?php } ?></div>
                    <div class="text-right"><span class="badge badge-<?= documentationLifecycleStatusBadge($status) ?>"><?= escapeHtml($status) ?></span><?php if ($waiver_active) { ?><div><span class="badge badge-primary mt-1">Waived until <?= escapeHtml(date('Y-m-d', strtotime($link['ticket_documentation_waiver_expires_at']))) ?></span></div><?php } ?></div>
                </div>

                <?php if ($stored_waiver_status !== '' && !$waiver_current) { ?>
                    <div class="alert alert-secondary py-2 mt-2 mb-0 small">
                        <i class="fas fa-history mr-1"></i>The latest waiver targets a superseded requirement version and does not bypass this obligation.
                    </div>
                <?php } ?>

                <?php if ($can_edit && $projection_mutable && !$waiver_active && $waiver_status !== 'Pending' && intval($link['ticket_documentation_obligation_blocks_resolution']) && in_array($status, ['Missing', 'Draft', 'Stale'], true)) { ?>
                    <form action="post.php" method="post" class="form-row align-items-end mt-2 pt-2 border-top">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <input type="hidden" name="link_id" value="<?= $link_id ?>">
                        <div class="form-group col-md-7 mb-md-0"><label class="small">Waiver reason</label><input class="form-control form-control-sm" name="reason" maxlength="255" required autocomplete="off"></div>
                        <div class="form-group col-md-3 mb-md-0"><label class="small">Expires</label><input class="form-control form-control-sm" type="datetime-local" name="expires_at" required></div>
                        <div class="form-group col-md-2 mb-0"><button class="btn btn-sm btn-outline-warning btn-block" name="request_ticket_documentation_waiver">Request</button></div>
                    </form>
                <?php } elseif ($waiver_status === 'Pending') { ?>
                    <div class="alert alert-warning py-2 mt-2 mb-0">
                        <strong>Waiver pending:</strong> <?= escapeHtml($link['ticket_documentation_waiver_reason_redacted']) ?>
                        <?php if ($can_decide_waiver && $projection_mutable && intval($link['ticket_documentation_waiver_requested_by']) !== intval($session_user_id)) { ?>
                            <form action="post.php" method="post" class="d-inline float-right">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                                <input type="hidden" name="waiver_id" value="<?= intval($link['ticket_documentation_waiver_id']) ?>">
                                <input type="hidden" name="expected_revision" value="<?= intval($link['ticket_documentation_waiver_revision']) ?>">
                                <button class="btn btn-xs btn-success" name="approve_ticket_documentation_waiver">Approve</button>
                                <button class="btn btn-xs btn-outline-danger" name="reject_ticket_documentation_waiver">Reject</button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if ($waiver_active && $can_decide_waiver && $projection_mutable && intval($link['ticket_documentation_waiver_requested_by']) !== intval($session_user_id)) { ?>
                    <form action="post.php" method="post" class="text-right mt-2">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <input type="hidden" name="waiver_id" value="<?= intval($link['ticket_documentation_waiver_id']) ?>">
                        <input type="hidden" name="expected_revision" value="<?= intval($link['ticket_documentation_waiver_revision']) ?>">
                        <button class="btn btn-xs btn-outline-danger" name="revoke_ticket_documentation_waiver"><i class="fas fa-ban mr-1"></i>Revoke waiver</button>
                    </form>
                <?php } ?>

                <?php foreach ($open_promises as $promise) { ?>
                    <div class="alert alert-info py-2 mt-2 mb-0 small">
                        <i class="fas fa-calendar-check mr-1"></i><strong>Follow-up promised:</strong>
                        <?= escapeHtml(ucwords(str_replace('-', ' ', $promise['documentation_promise_reason_code']))) ?>
                        by <?= escapeHtml(date('Y-m-d H:i', strtotime($promise['documentation_promise_due_at']))) ?>
                        <?php if ($can_manage_promises && $projection_mutable) { ?>
                            <form action="post.php" method="post" class="d-inline float-right ml-2">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                                <input type="hidden" name="promise_id" value="<?= intval($promise['documentation_promise_id']) ?>">
                                <input type="hidden" name="expected_revision" value="<?= intval($promise['documentation_promise_revision']) ?>">
                                <button class="btn btn-xs btn-success" name="fulfill_documentation_promise">Fulfilled</button>
                                <button class="btn btn-xs btn-outline-secondary" name="cancel_documentation_promise">Cancel</button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if ($can_edit && $projection_mutable && !$open_promises) { ?>
                    <form action="post.php" method="post" class="form-row align-items-end mt-2 pt-2 border-top">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <input type="hidden" name="obligation_id" value="<?= $obligation_id ?>">
                        <div class="form-group col-md-3 mb-md-0">
                            <label class="small">Follow-up type</label>
                            <select class="form-control form-control-sm" name="reason_code" required>
                                <option value="">Select</option>
                                <option value="client-input">Client input</option>
                                <option value="evidence-follow-up">Evidence follow-up</option>
                                <option value="technical-validation">Technical validation</option>
                                <option value="documentation-refresh">Documentation refresh</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-md-0"><label class="small">Structured commitment</label><input class="form-control form-control-sm" name="reason" maxlength="255" required autocomplete="off" placeholder="What will be completed"></div>
                        <div class="form-group col-md-3 mb-md-0"><label class="small">Due</label><input class="form-control form-control-sm" type="datetime-local" name="due_at" required></div>
                        <div class="form-group col-md-2 mb-0"><button class="btn btn-sm btn-outline-info btn-block" name="create_documentation_promise">Promise</button></div>
                        <div class="col-12 small text-muted mt-1">This records an explicit follow-up commitment. It does not waive the resolution gate.</div>
                    </form>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
    <?php if (!$links) { ?><div class="text-muted small mb-3">No documentation obligations linked to this ticket.</div><?php } ?>

    <?php if ($can_edit && $ticket['ticket_documentation_impact'] === 'Required') { ?>
        <form action="post.php" method="post" class="mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
            <div class="form-row align-items-end">
                <div class="form-group col-md-9 mb-md-0"><label>Link affected required record</label><select class="form-control select2" name="obligation_id" required><option value="">Select an applicable obligation</option><?php foreach ($available_obligations as $available) { ?><option value="<?= intval($available['documentation_obligation_id']) ?>"><?= escapeHtml($available['documentation_requirement_version_name']) ?> — <?= escapeHtml($available['effective_status']) ?></option><?php } ?></select></div>
                <div class="form-group col-md-3 mb-0"><button class="btn btn-primary btn-block" name="link_ticket_documentation_obligation"><i class="fas fa-link mr-1"></i>Link</button></div>
            </div>
        </form>
    <?php } ?>
</div>

<div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Close</button></div>

<?php require_once '../../../includes/modal_footer.php'; ?>
