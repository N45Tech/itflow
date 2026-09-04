<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_support');

$agreement_id = intval($_GET['agreement_id'] ?? 0);
$agreement_sql = mysqli_query($mysqli, "SELECT contracts.*, client_name FROM contracts
    JOIN clients ON client_id = contract_client_id
    WHERE contract_id = $agreement_id " . clientScopeSql('contract_client_id') . " LIMIT 1");
if (!$agreement_sql || !mysqli_num_rows($agreement_sql)) {
    echo "<div class='text-center mt-5'><h1 class='text-secondary'>Agreement not found</h1><a href='agreements.php' class='btn btn-secondary mt-3'>Back to Agreements</a></div>";
    require_once '../includes/footer.php';
    exit;
}
$agreement = mysqli_fetch_assoc($agreement_sql);
$client_id = intval($agreement['contract_client_id']);
enforceClientAccess($client_id);

$versions = [];
$versions_sql = mysqli_query($mysqli, "SELECT * FROM agreement_versions
    WHERE agreement_version_contract_id = $agreement_id
    ORDER BY agreement_version_number DESC");
while ($row = mysqli_fetch_assoc($versions_sql)) {
    $versions[intval($row['agreement_version_id'])] = $row;
}

$version_id = intval($_GET['version_id'] ?? 0);
if (!$version_id || !isset($versions[$version_id])) {
    foreach ($versions as $candidate_id => $candidate) {
        if ($candidate['agreement_version_status'] === 'Draft') {
            $version_id = $candidate_id;
            break;
        }
    }
}
if (!$version_id) {
    $version_id = intval($agreement['contract_published_version_id']);
}
$version = $versions[$version_id] ?? null;
if (!$version) {
    echo "<div class='card card-dark'><div class='card-header'><h3 class='card-title'>Initialize versioned agreement</h3></div><div class='card-body'>";
    echo "<p>This legacy agreement has no normalized definition yet. Create its first draft from the existing contract details, then add entitlements and SLA rules.</p>";
    if (lookupUserPermission('module_support') >= 2) {
        echo "<form action='post.php' method='post'>";
        echo "<input type='hidden' name='csrf_token' value='" . escapeHtml($_SESSION['csrf_token']) . "'>";
        echo "<input type='hidden' name='contract_id' value='$agreement_id'>";
        echo "<button class='btn btn-primary' name='create_agreement_draft'><i class='fas fa-code-branch mr-2'></i>Create Initial Draft</button></form>";
    }
    echo "</div></div>";
    require_once '../includes/footer.php';
    exit;
}

$is_draft = $version['agreement_version_status'] === 'Draft';
$can_edit = $is_draft && lookupUserPermission('module_support') >= 2;
if (!$is_draft) {
    try {
        agreementAssertVersionIntegrity($version);
    } catch (Throwable $e) {
        http_response_code(409);
        echo "<div class='alert alert-danger'>" . escapeHtml($e->getMessage()) . "</div>";
        require_once '../includes/footer.php';
        exit;
    }
}
$entitlements = mysqli_query($mysqli, "SELECT * FROM agreement_entitlements
    WHERE agreement_entitlement_version_id = $version_id
    ORDER BY agreement_entitlement_scope_type, agreement_entitlement_scope_label,
        agreement_entitlement_classification, agreement_entitlement_id");
$sla_rules = mysqli_query($mysqli, "SELECT agreement_sla_rules.* FROM agreement_sla_rules
    WHERE agreement_sla_rule_version_id = $version_id
    ORDER BY agreement_sla_rule_order, agreement_sla_rule_id");
$slas = mysqli_query($mysqli, "SELECT sla_id, sla_name FROM slas
    WHERE sla_archived_at IS NULL ORDER BY sla_name");
$reviews = mysqli_query($mysqli, "SELECT service_review_id, service_review_period_start,
    service_review_period_end, service_review_status, service_review_generated_at,
    service_review_snapshot_hash FROM service_reviews
    WHERE service_review_contract_id = $agreement_id
    AND service_review_client_id = $client_id
    ORDER BY service_review_period_end DESC, service_review_id DESC");

$status_badges = ['Draft' => 'warning', 'Published' => 'success', 'Superseded' => 'secondary'];
$version_badge = $status_badges[$version['agreement_version_status']] ?? 'secondary';
$default_period_end = date('Y-m-d', strtotime('-1 day'));
$cadence = max(1, intval($agreement['contract_review_cadence_months'] ?: $version['agreement_version_review_cadence_months']));
$default_period_start = agreementShiftCalendarMonths(
    date('Y-m-d', strtotime('+1 day', strtotime($default_period_end))),
    -$cadence
);

?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h3 class="mb-1"><i class="fas fa-fw fa-file-contract mr-2"></i><?= escapeHtml($agreement['contract_name']) ?></h3>
        <a href="clients.php?client_id=<?= $client_id ?>"><?= escapeHtml($agreement['client_name']) ?></a>
        <span class="text-muted mx-2">&middot;</span><?= escapeHtml($agreement['contract_type']) ?>
    </div>
    <div class="d-print-none">
        <a href="agreements.php?client_id=<?= $client_id ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-2"></i>Agreements</a>
        <?php if (!$is_draft && lookupUserPermission('module_support') >= 2) { ?>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="contract_id" value="<?= $agreement_id ?>">
                <button class="btn btn-primary" name="create_agreement_draft"><i class="fas fa-code-branch mr-2"></i>New Draft</button>
            </form>
        <?php } ?>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">Agreement terms v<?= intval($version['agreement_version_number']) ?>
            <span class="badge badge-<?= $version_badge ?> ml-2"><?= escapeHtml($version['agreement_version_status']) ?></span>
        </h3>
        <div class="card-tools">
            <select class="form-control" onchange="window.location=this.value" aria-label="Agreement version">
                <?php foreach ($versions as $candidate_id => $candidate) { ?>
                    <option value="agreement.php?agreement_id=<?= $agreement_id ?>&version_id=<?= $candidate_id ?>"
                        <?= $candidate_id === $version_id ? 'selected' : '' ?>>
                        v<?= intval($candidate['agreement_version_number']) ?> — <?= escapeHtml($candidate['agreement_version_status']) ?>
                    </option>
                <?php } ?>
            </select>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$is_draft) { ?>
            <div class="alert alert-info">
                <i class="fas fa-lock mr-2"></i>This published definition is immutable.
                SHA-256 <code><?= escapeHtml($version['agreement_version_definition_hash']) ?></code>
            </div>
        <?php } else { ?>
            <div class="alert alert-warning"><i class="fas fa-pencil-alt mr-2"></i>This version is a draft and does not affect ticket entitlement or SLA decisions until published.</div>
        <?php } ?>

        <?php if ($can_edit) { ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="version_id" value="<?= $version_id ?>">
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label>Name</label>
                        <input class="form-control" name="name" maxlength="255" required value="<?= escapeHtml($version['agreement_version_name']) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Type</label>
                        <input class="form-control" name="type" maxlength="50" required value="<?= escapeHtml($version['agreement_version_type']) ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Support hours <small class="text-muted">(descriptive)</small></label>
                        <input class="form-control" name="support_hours" maxlength="100" value="<?= escapeHtml($version['agreement_version_support_hours']) ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label>Effective from</label>
                        <input type="date" class="form-control" name="effective_from" value="<?= escapeHtml($version['agreement_version_effective_from']) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Effective until</label>
                        <input type="date" class="form-control" name="effective_until" value="<?= escapeHtml($version['agreement_version_effective_until']) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Review cadence (months)</label>
                        <input type="number" class="form-control" name="review_cadence_months" min="1" max="24" required value="<?= intval($version['agreement_version_review_cadence_months']) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Renewal notice (days)</label>
                        <input type="number" class="form-control" name="renewal_notice_days" min="0" max="365" required value="<?= intval($version['agreement_version_renewal_notice_days']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Operational details</label>
                    <textarea class="form-control" name="details" rows="3"><?= escapeHtml($version['agreement_version_details']) ?></textarea>
                </div>
                <button class="btn btn-primary" name="edit_agreement_draft"><i class="fas fa-save mr-2"></i>Save Draft</button>
            </form>
        <?php } else { ?>
            <dl class="row mb-0">
                <dt class="col-sm-3">Term</dt><dd class="col-sm-9"><?= escapeHtml($version['agreement_version_effective_from'] ?: 'Open') ?> through <?= escapeHtml($version['agreement_version_effective_until'] ?: 'Evergreen') ?></dd>
                <dt class="col-sm-3">Support hours</dt><dd class="col-sm-9"><?= escapeHtml($version['agreement_version_support_hours'] ?: 'Not specified') ?></dd>
                <dt class="col-sm-3">Service review</dt><dd class="col-sm-9">Every <?= intval($version['agreement_version_review_cadence_months']) ?> month(s)</dd>
                <dt class="col-sm-3">Details</dt><dd class="col-sm-9"><?= nl2br(escapeHtml($version['agreement_version_details'] ?: '—')) ?></dd>
            </dl>
        <?php } ?>
    </div>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-tasks mr-2"></i>Coverage &amp; exclusions</h3></div>
            <div class="card-body">
                <p class="text-muted small">Coverage determines whether work is included, billable or excluded. Specific records override the baseline for their area. If any applicable area is excluded, the ticket carries no SLA; exceeding an area's quantity limit makes its coverage billable.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead><tr><th>Scope</th><th>Classification</th><th>Limit</th><th>Notes</th><?php if ($can_edit) { ?><th></th><?php } ?></tr></thead>
                        <tbody>
                        <?php $entitlement_count = 0; while ($entitlement = mysqli_fetch_assoc($entitlements)) { $entitlement_count++; ?>
                            <tr>
                                <td>
                                    <strong><?= escapeHtml(agreementScopeTypes()[$entitlement['agreement_entitlement_scope_type']] ?? $entitlement['agreement_entitlement_scope_type']) ?></strong>
                                    <div><small><?= escapeHtml($entitlement['agreement_entitlement_scope_label']) ?></small></div>
                                    <?php if ($entitlement['agreement_entitlement_scope_key'] !== '*') { ?><code><?= escapeHtml($entitlement['agreement_entitlement_scope_key']) ?></code><?php } ?>
                                </td>
                                <td><?= escapeHtml(agreementClassifications()[$entitlement['agreement_entitlement_classification']] ?? $entitlement['agreement_entitlement_classification']) ?></td>
                                <td><?= is_null($entitlement['agreement_entitlement_quantity_limit']) ? 'Unlimited / stated scope' : escapeHtml($entitlement['agreement_entitlement_quantity_limit']) ?></td>
                                <td><?= escapeHtml($entitlement['agreement_entitlement_notes']) ?></td>
                                <?php if ($can_edit) { ?><td class="text-right">
                                    <form action="post.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="entitlement_id" value="<?= intval($entitlement['agreement_entitlement_id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger confirm-link" name="delete_agreement_entitlement" title="Remove"><i class="fas fa-times"></i></button>
                                    </form>
                                </td><?php } ?>
                            </tr>
                        <?php } if (!$entitlement_count) { ?>
                            <tr><td colspan="5" class="text-muted">No entitlements or exclusions defined.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($can_edit) { ?>
                    <hr>
                    <h6>Add entitlement</h6>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="version_id" value="<?= $version_id ?>">
                        <div class="form-row">
                            <div class="form-group col-md-3"><label>Scope</label><select class="form-control" name="scope_type"><?php foreach (agreementScopeTypes() as $value => $label) { ?><option value="<?= $value ?>"><?= $label ?></option><?php } ?></select></div>
                            <div class="form-group col-md-3"><label>Classification</label><select class="form-control" name="classification"><?php foreach (agreementClassifications() as $value => $label) { ?><option value="<?= $value ?>"><?= $label ?></option><?php } ?></select></div>
                            <div class="form-group col-md-4"><label>Label <small>(required for broad scope)</small></label><input class="form-control" name="scope_label" maxlength="255" placeholder="All active users"><small class="text-muted">Specific record IDs use their canonical ITFlow name.</small></div>
                            <div class="form-group col-md-2"><label>Limit</label><input type="number" step="0.01" min="0" class="form-control" name="quantity_limit" placeholder="—"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-3"><label>Record ID <small>(optional)</small></label><input type="number" min="0" class="form-control" name="scope_id" value="0"><small class="text-muted">Must belong to this client.</small></div>
                            <div class="form-group col-md-3"><label>Semantic key <small>(services / hours)</small></label><input class="form-control" name="scope_key" maxlength="100" placeholder="business-hours"><small class="text-muted">Hours: <code>all-hours</code>, <code>24x7</code>, <code>business-hours</code>, or <code>after-hours</code>. Services may use a stable catalog/service key. Other broad scopes use <code>*</code>.</small></div>
                            <div class="form-group col-md-6"><label>Notes</label><input class="form-control" name="notes" placeholder="What is and is not covered"></div>
                        </div>
                        <button class="btn btn-secondary" name="add_agreement_entitlement"><i class="fas fa-plus mr-2"></i>Add Entitlement</button>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-stopwatch mr-2"></i>Service levels</h3></div>
            <div class="card-body">
                <p class="text-muted small">Each rule keeps the agreed targets and support calendar. These saved values govern ticket deadlines, not later changes to a shared profile. Specific request and priority rules take precedence over defaults. Changing the descriptive support-hours label above does not change these calendars.</p>
                <div class="alert alert-light small py-2">
                    <strong>Operational matrix v1:</strong>
                    included = SLA eligible / remote / non-billable;
                    excluded = no SLA / billable;
                    onsite = SLA eligible / onsite / non-billable;
                    after-hours and billable = SLA eligible / remote / billable.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead><tr><th>Request</th><th>Priority</th><th>SLA</th><th>Class</th><?php if ($can_edit) { ?><th></th><?php } ?></tr></thead>
                        <tbody>
                        <?php $rule_count = 0; while ($rule = mysqli_fetch_assoc($sla_rules)) { $rule_count++; ?>
                            <tr>
                                <td><code><?= escapeHtml($rule['agreement_sla_rule_request_type_key']) ?></code></td>
                                <td><?= escapeHtml($rule['agreement_sla_rule_priority']) ?></td>
                                <td>
                                    <?= escapeHtml($rule['agreement_sla_rule_sla_name']) ?>
                                    <?php if (intval($rule['agreement_sla_rule_sla_id'])) { ?>
                                        <div class="small text-muted">
                                            <?= intval($rule['agreement_sla_rule_response_minutes']) ?>m response /
                                            <?= is_null($rule['agreement_sla_rule_resolution_minutes']) ? 'no resolution target' : intval($rule['agreement_sla_rule_resolution_minutes']) . 'm resolution' ?>;
                                            <?= $rule['agreement_sla_rule_calendar_mode'] === 'business_hours' ? 'business hours' : escapeHtml($rule['agreement_sla_rule_calendar_mode']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?php if ($rule['agreement_sla_rule_calendar_mode'] === 'business_hours') {
                                                $day_names = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                                $rule_days = array_map(static fn($day) => $day_names[intval($day)] ?? '', explode(',', (string) $rule['agreement_sla_rule_business_days'])); ?>
                                                <?= escapeHtml(implode(', ', $rule_days)) ?>
                                                <?= escapeHtml(substr((string) $rule['agreement_sla_rule_business_hours_start'], 0, 5)) ?>-<?= escapeHtml(substr((string) $rule['agreement_sla_rule_business_hours_end'], 0, 5)) ?>;
                                            <?php } ?>
                                            <?= escapeHtml($rule['agreement_sla_rule_timezone']) ?>
                                        </div>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?= escapeHtml(agreementClassifications()[$rule['agreement_sla_rule_classification']] ?? $rule['agreement_sla_rule_classification']) ?>
                                    <div class="small text-muted">
                                        SLA <?= intval($rule['agreement_sla_rule_sla_eligible']) ? 'eligible' : 'ineligible' ?>;
                                        <?= intval($rule['agreement_sla_rule_ticket_onsite']) ? 'onsite' : 'remote' ?>;
                                        <?= intval($rule['agreement_sla_rule_ticket_billable']) ? 'billable' : 'non-billable' ?>
                                    </div>
                                </td>
                                <?php if ($can_edit) { ?><td>
                                    <form action="post.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="rule_id" value="<?= intval($rule['agreement_sla_rule_id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger confirm-link" name="delete_agreement_sla_rule"><i class="fas fa-times"></i></button>
                                    </form>
                                </td><?php } ?>
                            </tr>
                        <?php } if (!$rule_count) { ?><tr><td colspan="5" class="text-muted">No SLA rules defined.</td></tr><?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($can_edit) { ?>
                    <hr>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="version_id" value="<?= $version_id ?>">
                        <div class="form-group"><label>Request type key</label><input class="form-control" name="request_type_key" value="*" maxlength="100"><small class="text-muted">Use <code>*</code> for every request type, or a specific request-catalog key. New rules use the selected profile's targets and the current company calendar.</small></div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Priority</label><select class="form-control" name="priority"><option>*</option><?php foreach (array_keys(ticketPriorityDefinitions()) as $priority) { ?><option><?= $priority ?></option><?php } ?></select></div>
                            <div class="form-group col-md-6"><label>SLA</label><select class="form-control" name="sla_id"><option value="0">None</option><?php mysqli_data_seek($slas, 0); while ($sla = mysqli_fetch_assoc($slas)) { ?><option value="<?= intval($sla['sla_id']) ?>"><?= escapeHtml($sla['sla_name']) ?></option><?php } ?></select></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-8"><label>Classification</label><select class="form-control" name="classification"><?php foreach (agreementClassifications() as $value => $label) { ?><option value="<?= $value ?>"><?= $label ?></option><?php } ?></select></div>
                            <div class="form-group col-md-4"><label>Order</label><input type="number" class="form-control" name="rule_order" value="0"></div>
                        </div>
                        <button class="btn btn-secondary" name="add_agreement_sla_rule"><i class="fas fa-plus mr-2"></i>Add Rule</button>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php if ($can_edit) { ?>
    <div class="card border-success">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div><strong>Ready to activate?</strong><div class="text-muted">Publishing locks this definition and supersedes the previous published version.</div></div>
            <form action="post.php" method="post" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="version_id" value="<?= $version_id ?>">
                <input class="form-control mr-2" name="reason" required maxlength="255" placeholder="Publication reason">
                <button class="btn btn-success confirm-link" name="publish_agreement_version"><i class="fas fa-lock mr-2"></i>Publish v<?= intval($version['agreement_version_number']) ?></button>
            </form>
        </div>
    </div>
<?php } ?>

<div class="card card-dark">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-chart-line mr-2"></i>Service Reviews</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead><tr><th>Period</th><th>Status</th><th>Generated</th><th>Snapshot</th><th></th></tr></thead>
                <tbody>
                <?php $review_count = 0; while ($review = mysqli_fetch_assoc($reviews)) { $review_count++; ?>
                    <tr>
                        <td><?= escapeHtml($review['service_review_period_start']) ?> through <?= escapeHtml($review['service_review_period_end']) ?></td>
                        <td><span class="badge badge-<?= $review['service_review_status'] === 'Published' ? 'success' : 'warning' ?>"><?= escapeHtml($review['service_review_status']) ?></span></td>
                        <td><?= escapeHtml($review['service_review_generated_at']) ?></td>
                        <td><code title="<?= escapeHtml($review['service_review_snapshot_hash']) ?>"><?= escapeHtml(substr($review['service_review_snapshot_hash'], 0, 12)) ?>&hellip;</code></td>
                        <td class="text-right"><a class="btn btn-sm btn-secondary" href="service_review.php?review_id=<?= intval($review['service_review_id']) ?>">Open</a></td>
                    </tr>
                <?php } if (!$review_count) { ?><tr><td colspan="5" class="text-muted">No service reviews have been generated.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (lookupUserPermission('module_support') >= 2 && intval($agreement['contract_published_version_id']) > 0) { ?>
            <hr>
            <form action="post.php" method="post" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <input type="hidden" name="contract_id" value="<?= $agreement_id ?>">
                <label class="mr-2">Period</label>
                <input type="date" class="form-control mr-2" name="period_start" required value="<?= $default_period_start ?>">
                <span class="mr-2">through</span>
                <input type="date" class="form-control mr-2" name="period_end" required max="<?= date('Y-m-d') ?>" value="<?= $default_period_end ?>">
                <button class="btn btn-primary" name="generate_service_review"><i class="fas fa-sync mr-2"></i>Generate Review</button>
            </form>
            <small class="text-muted">The report stores a point-in-time source snapshot. Scheduled reviews create drafts for human approval.</small>
        <?php } elseif (intval($agreement['contract_published_version_id']) === 0) { ?>
            <p class="text-muted mb-0">Publish the initial agreement definition before generating a service review.</p>
        <?php } ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
