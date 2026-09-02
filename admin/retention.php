<?php

require_once "includes/inc_all_admin.php";
enforceAdminPermission();

$policies = [];
$policy_rows = retentionDbQuery("SELECT * FROM retention_policies ORDER BY retention_policy_label",
    'Could not load retention policies');
while ($row = mysqli_fetch_assoc($policy_rows)) {
    $policies[] = $row;
}

$prefill_type = (string) ($_GET['record_type'] ?? '');
$prefill_id = intval($_GET['record_id'] ?? 0);
$prefill = null;
if ($prefill_id > 0 && in_array($prefill_type, ['ticket', 'file', 'attachment'], true)) {
    if ($prefill_type === 'ticket') {
        $prefill = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id AS record_id,
            ticket_client_id AS client_id, CONCAT(COALESCE(ticket_prefix,''), ticket_number, ' - ', ticket_subject) AS label,
            ticket_deleted_at AS deleted_at FROM tickets WHERE ticket_id = $prefill_id LIMIT 1",
            'Could not load the ticket retention target'));
    } elseif ($prefill_type === 'file') {
        $prefill = mysqli_fetch_assoc(retentionDbQuery("SELECT file_id AS record_id, file_client_id AS client_id,
            file_name AS label, file_deleted_at AS deleted_at FROM files WHERE file_id = $prefill_id LIMIT 1",
            'Could not load the file retention target'));
    } else {
        $prefill = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_attachment_id AS record_id,
            ticket_client_id AS client_id, ticket_attachment_name AS label,
            ticket_attachment_deleted_at AS deleted_at FROM ticket_attachments
            INNER JOIN tickets ON ticket_id = ticket_attachment_ticket_id
            WHERE ticket_attachment_id = $prefill_id LIMIT 1", 'Could not load the attachment retention target'));
    }
}

$deleted_rows = retentionDbQuery("SELECT d.*, c.client_name,
    (SELECT COUNT(*) FROM retention_holds h WHERE h.retention_hold_released_at IS NULL AND (
        (h.retention_hold_record_type = d.retention_deletion_record_type
            AND h.retention_hold_record_id = d.retention_deletion_record_id)
        OR (h.retention_hold_client_id = d.retention_deletion_client_id
            AND h.retention_hold_record_type = '*' AND h.retention_hold_record_id = 0)
    )) AS active_hold_count
    FROM retention_deletions d LEFT JOIN clients c ON c.client_id = d.retention_deletion_client_id
    WHERE d.retention_deletion_restored_at IS NULL AND d.retention_deletion_purged_at IS NULL
    ORDER BY d.retention_deletion_deleted_at DESC LIMIT 100", 'Could not load recoverable deletions');

$holds = retentionDbQuery("SELECT h.*, c.client_name, u.user_name FROM retention_holds h
    LEFT JOIN clients c ON c.client_id = h.retention_hold_client_id
    LEFT JOIN users u ON u.user_id = h.retention_hold_placed_by
    WHERE h.retention_hold_released_at IS NULL ORDER BY h.retention_hold_placed_at DESC LIMIT 100",
    'Could not load active retention holds');
$clients = retentionDbQuery("SELECT client_id, client_name FROM clients ORDER BY client_name",
    'Could not load retention hold clients');

$batch_id = intval($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    $batch_id = intval(mysqli_fetch_row(retentionDbQuery("SELECT COALESCE(MAX(retention_purge_batch_id),0)
        FROM retention_purge_batches", 'Could not locate the latest purge preview'))[0] ?? 0);
}
$batch = $batch_id ? mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_purge_batches
    WHERE retention_purge_batch_id = $batch_id LIMIT 1", 'Could not load the purge preview')) : null;
$batch_items = $batch ? retentionDbQuery("SELECT i.*, d.retention_deletion_label
    FROM retention_purge_items i LEFT JOIN retention_deletions d
    ON d.retention_deletion_record_type = i.retention_purge_item_record_type
    AND d.retention_deletion_record_id = i.retention_purge_item_record_id
    WHERE i.retention_purge_item_batch_id = $batch_id ORDER BY i.retention_purge_item_id",
    'Could not load purge preview items') : false;

$events = retentionDbQuery("SELECT e.*, u.user_name FROM retention_events e
    LEFT JOIN users u ON u.user_id = e.retention_event_actor_id
    ORDER BY e.retention_event_id DESC LIMIT 50", 'Could not load retention events');

?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-trash-restore mr-2"></i>Recoverable Deletion &amp; Retention</h3>
    </div>
    <div class="card-body">
        <p class="mb-1">Deletion is administrator-only and recoverable. Permanent purge requires an elapsed policy, no hold, a captured dependency dry-run, and typed approval.</p>
        <p class="text-muted mb-0">Runbook history, worked time, evidence, approvals, documentation promises/change passports, agreement decisions, portal requests, and integration accountability fail closed as purge blockers.</p>
    </div>
</div>

<?php if ($prefill) { ?>
<div class="card card-danger">
    <div class="card-header"><h3 class="card-title">Move record to recoverable deletion</h3></div>
    <div class="card-body">
        <?php if (!empty($prefill['deleted_at'])) { ?>
            <div class="alert alert-info mb-0">This record is already in Deleted Records.</div>
        <?php } else { ?>
            <p><strong><?= escapeHtml($prefill['label']) ?></strong><br><small class="text-muted"><?= escapeHtml($prefill_type) ?> #<?= intval($prefill['record_id']) ?> · client <?= intval($prefill['client_id']) ?></small></p>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="record_type" value="<?= escapeHtml($prefill_type) ?>">
                <input type="hidden" name="record_id" value="<?= intval($prefill['record_id']) ?>">
                <div class="form-group"><label>Owner-approved reason</label><textarea class="form-control" name="reason" minlength="10" maxlength="500" required></textarea></div>
                <button class="btn btn-danger" name="soft_delete_retained_record"><i class="fas fa-trash-restore mr-2"></i>Move to Deleted Records</button>
            </form>
        <?php } ?>
    </div>
</div>
<?php } ?>

<div class="card card-light">
    <div class="card-header"><h3 class="card-title">Retention policies</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Record class</th><th>Retain</th><th>Restore</th><th>Purge</th><th>Owner decision</th><th></th></tr></thead>
        <tbody><?php foreach ($policies as $policy) {
            $policy_key = $policy['retention_policy_key'];
            $allowed_modes = $policy_key === 'evidence' ? ['disabled']
                : (in_array($policy_key, ['automation_payloads', 'normalized_payloads'], true)
                    ? ['disabled', 'automatic'] : ['disabled', 'manual']);
            $form_id = 'policy-' . preg_replace('/[^a-z0-9_-]/', '-', $policy['retention_policy_key']); ?>
            <tr>
                <td><strong><?= escapeHtml($policy['retention_policy_label']) ?></strong><br><small><?= escapeHtml($policy['retention_policy_key']) ?></small></td>
                <td><input class="form-control form-control-sm" form="<?= $form_id ?>" type="number" name="retention_days" min="1" max="36500" value="<?= intval($policy['retention_policy_retention_days']) ?>"> days</td>
                <td><input class="form-control form-control-sm" form="<?= $form_id ?>" type="number" name="restore_window_days" min="0" max="3650" value="<?= intval($policy['retention_policy_restore_window_days']) ?>"> days</td>
                <td><select class="form-control form-control-sm" form="<?= $form_id ?>" name="purge_mode">
                    <?php foreach ($allowed_modes as $mode) { ?><option value="<?= $mode ?>" <?= $policy['retention_policy_purge_mode'] === $mode ? 'selected' : '' ?>><?= ucfirst($mode) ?></option><?php } ?>
                </select><?php if (in_array('automatic', $allowed_modes, true)) { ?><label class="small mt-1"><input form="<?= $form_id ?>" type="checkbox" name="confirm_automatic" value="1"> confirm automatic minimization</label><?php } ?></td>
                <td><textarea class="form-control form-control-sm" form="<?= $form_id ?>" name="owner_note" maxlength="500" required><?= escapeHtml($policy['retention_policy_owner_note']) ?></textarea></td>
                <td><form id="<?= $form_id ?>" action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="policy_key" value="<?= escapeHtml($policy['retention_policy_key']) ?>"><button class="btn btn-sm btn-primary" name="save_retention_policy">Save</button></form></td>
            </tr>
        <?php } ?></tbody>
    </table></div></div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card card-light">
            <div class="card-header"><h3 class="card-title">Deleted Records</h3></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Record</th><th>Deleted</th><th>Restore through</th><th>Purge eligibility</th><th>Quarantine</th><th></th></tr></thead>
                <tbody><?php while ($deleted = mysqli_fetch_assoc($deleted_rows)) {
                    $restore_open = !empty($deleted['retention_deletion_restore_until']) && strtotime($deleted['retention_deletion_restore_until']) >= time();
                    if (in_array($deleted['retention_deletion_record_type'], ['file', 'attachment'], true)
                        && $deleted['retention_deletion_quarantine_status'] !== 'quarantined') {
                        $restore_open = false;
                    }
                    $purge_elapsed = strtotime($deleted['retention_deletion_purge_eligible_at']) <= time(); ?>
                    <tr>
                        <td><strong><?= escapeHtml($deleted['retention_deletion_label']) ?></strong><br><small><?= escapeHtml($deleted['retention_deletion_record_type']) ?> #<?= intval($deleted['retention_deletion_record_id']) ?> · <?= escapeHtml($deleted['client_name'] ?: 'No client') ?></small></td>
                        <td><?= escapeHtml($deleted['retention_deletion_deleted_at']) ?><br><small><?= escapeHtml($deleted['retention_deletion_reason']) ?></small></td>
                        <td><span class="badge badge-<?= $restore_open ? 'success' : 'secondary' ?>"><?= $deleted['retention_deletion_restore_until'] ? escapeHtml($deleted['retention_deletion_restore_until']) : 'No restore window' ?></span></td>
                        <td><?php if (intval($deleted['active_hold_count'])) { ?><span class="badge badge-warning">Hold</span><?php } elseif ($purge_elapsed) { ?><span class="badge badge-danger">Preview required</span><?php } else { ?><span class="badge badge-info"><?= escapeHtml($deleted['retention_deletion_purge_eligible_at']) ?></span><?php } ?></td>
                        <td><?= escapeHtml($deleted['retention_deletion_quarantine_status']) ?></td>
                        <td><?php if ($restore_open) { ?><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="record_type" value="<?= escapeHtml($deleted['retention_deletion_record_type']) ?>"><input type="hidden" name="record_id" value="<?= intval($deleted['retention_deletion_record_id']) ?>"><input class="form-control form-control-sm mb-1" name="reason" minlength="10" maxlength="500" required placeholder="Restore reason"><button class="btn btn-sm btn-outline-success" name="restore_retained_record">Restore</button></form><?php } ?></td>
                    </tr>
                <?php } ?></tbody>
            </table></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card card-warning">
            <div class="card-header"><h3 class="card-title">Place retention hold</h3></div>
            <div class="card-body"><p class="small text-muted">Client-wide holds preserve every retained class. A ticket hold also preserves its attachments and ticket-linked integration payloads.</p><form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group"><label>Client-wide hold</label><select class="form-control" name="client_id"><option value="0">Specific record below</option><?php while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></div>
                <div class="form-row"><div class="form-group col"><label>Record type</label><select class="form-control" name="record_type"><option value="*">All client records</option><option>ticket</option><option>file</option><option>attachment</option><option value="automation-event">automation-event</option><option value="normalized-payload">normalized-payload</option></select></div><div class="form-group col"><label>Record ID</label><input class="form-control" type="number" min="0" name="record_id" value="0"></div></div>
                <div class="form-group"><label>Legal/owner reason</label><textarea class="form-control" name="reason" minlength="10" maxlength="500" required></textarea></div>
                <button class="btn btn-warning" name="place_retention_hold">Place hold</button>
            </form></div>
        </div>
    </div>
</div>

<div class="card card-warning">
    <div class="card-header"><h3 class="card-title">Active retention holds</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Scope</th><th>Reason</th><th>Placed</th><th>Release</th></tr></thead><tbody>
    <?php while ($hold = mysqli_fetch_assoc($holds)) { ?><tr><td><?= intval($hold['retention_hold_id']) ?></td><td><?= escapeHtml($hold['client_name'] ?: $hold['retention_hold_record_type'] . ' #' . $hold['retention_hold_record_id']) ?></td><td><?= escapeHtml($hold['retention_hold_reason']) ?></td><td><?= escapeHtml($hold['retention_hold_placed_at']) ?> · <?= escapeHtml($hold['user_name'] ?: 'System') ?></td><td><form class="form-inline" action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="hold_id" value="<?= intval($hold['retention_hold_id']) ?>"><input class="form-control form-control-sm mr-2" name="reason" minlength="10" maxlength="500" required placeholder="Release reason"><button class="btn btn-sm btn-outline-danger" name="release_retention_hold">Release</button></form></td></tr><?php } ?>
    </tbody></table></div></div>
</div>

<div class="card card-danger">
    <div class="card-header"><h3 class="card-title">Permanent-deletion workflow</h3></div>
    <div class="card-body">
        <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><button class="btn btn-outline-danger" name="preview_retention_purge"><i class="fas fa-search mr-2"></i>Create dry-run preview</button></form>
        <form action="post.php" method="post" class="d-inline ml-2"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><button class="btn btn-outline-secondary" name="reconcile_retention_ledger">Reconcile ledger</button></form>
        <?php if ($batch) { ?>
            <hr><h5>Batch <?= intval($batch_id) ?> · <?= escapeHtml($batch['retention_purge_batch_status']) ?></h5>
            <p><?= intval($batch['retention_purge_batch_candidate_count']) ?> candidates; <?= intval($batch['retention_purge_batch_eligible_count']) ?> eligible; <?= intval($batch['retention_purge_batch_blocked_count']) ?> blocked.</p>
            <?php if ($batch['retention_purge_batch_status'] === 'Running') { ?>
                <p class="text-muted">Execution lease: <?= escapeHtml($batch['retention_purge_batch_lease_until'] ?: 'Unavailable') ?> · resumptions <?= intval($batch['retention_purge_batch_resume_count']) ?>.</p>
            <?php } ?>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Record</th><th>Outcome</th><th>Reason</th><th>Dependency hash</th></tr></thead><tbody><?php while ($item = mysqli_fetch_assoc($batch_items)) { ?><tr><td><?= escapeHtml($item['retention_deletion_label'] ?: $item['retention_purge_item_record_type'] . ' #' . $item['retention_purge_item_record_id']) ?></td><td><span class="badge badge-<?= $item['retention_purge_item_outcome'] === 'Eligible' ? 'danger' : 'secondary' ?>"><?= escapeHtml($item['retention_purge_item_outcome']) ?></span></td><td><?= escapeHtml($item['retention_purge_item_reason']) ?></td><td><code><?= escapeHtml(substr($item['retention_purge_item_dependency_hash'], 0, 12)) ?>…</code></td></tr><?php } ?></tbody></table></div>
            <?php if ($batch['retention_purge_batch_status'] === 'Previewed' && intval($batch['retention_purge_batch_eligible_count']) > 0) { ?>
                <form action="post.php" method="post" class="border border-danger rounded p-3"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="batch_id" value="<?= intval($batch_id) ?>"><label>Type <code>PURGE <?= intval($batch_id) ?></code> to permanently delete only the eligible rows after a second dependency check.</label><div class="input-group"><input class="form-control" name="confirmation" required autocomplete="off"><div class="input-group-append"><button class="btn btn-danger" name="execute_retention_purge">Approve and purge</button></div></div></form>
            <?php } elseif ($batch['retention_purge_batch_status'] === 'Running'
                && !empty($batch['retention_purge_batch_lease_until'])
                && strtotime($batch['retention_purge_batch_lease_until']) < time()) { ?>
                <form action="post.php" method="post" class="border border-warning rounded p-3"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="batch_id" value="<?= intval($batch_id) ?>"><label>This execution lease expired. Review item outcomes, then type <code>RESUME PURGE <?= intval($batch_id) ?></code> to reclaim only unfinished items.</label><div class="input-group"><input class="form-control" name="confirmation" required autocomplete="off"><div class="input-group-append"><button class="btn btn-warning" name="resume_retention_purge">Resume interrupted purge</button></div></div></form>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<div class="card card-light">
    <div class="card-header"><h3 class="card-title">Immutable retention events</h3></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Record</th><th>Action</th><th>Actor</th><th>Reason</th><th>Metadata hash</th></tr></thead><tbody><?php while ($event = mysqli_fetch_assoc($events)) { ?><tr><td><?= escapeHtml($event['retention_event_created_at']) ?></td><td><?= escapeHtml($event['retention_event_record_type']) ?> #<?= intval($event['retention_event_record_id']) ?></td><td><?= escapeHtml($event['retention_event_action']) ?></td><td><?= escapeHtml($event['user_name'] ?: $event['retention_event_actor_type']) ?></td><td><?= escapeHtml($event['retention_event_reason']) ?></td><td><code><?= escapeHtml(substr($event['retention_event_metadata_hash'], 0, 12)) ?>…</code></td></tr><?php } ?></tbody></table></div></div>
</div>

<?php require_once "../includes/footer.php"; ?>
