<?php

$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id) {
    require_once 'includes/inc_all_client.php';
} else {
    require_once 'includes/inc_all.php';
}

enforceUserPermission('module_support');

$allowed_statuses = ['Missing', 'Draft', 'Current', 'Due Soon', 'Stale', 'Exception', 'Not Applicable'];
$selected_status = (string) ($_GET['status'] ?? 'attention');
$selected_owner = (string) ($_GET['owner'] ?? ($client_id ? 'all' : 'mine'));
if (!in_array($selected_status, array_merge(['all', 'attention'], $allowed_statuses), true)) {
    $selected_status = 'attention';
}
if (!in_array($selected_owner, ['mine', 'unassigned', 'all'], true)) {
    $selected_owner = 'mine';
}

$scope_sql = clientScopeSql('o.documentation_obligation_client_id');
$client_sql = $client_id ? "AND o.documentation_obligation_client_id = $client_id" : '';
$owner_sql = $selected_owner === 'mine'
    ? "AND o.documentation_obligation_owner_user_id = $session_user_id"
    : ($selected_owner === 'unassigned' ? 'AND o.documentation_obligation_owner_user_id = 0' : '');
$documentation_validity = documentationObligationValiditySql('o');

$sql_obligations = mysqli_query($mysqli, "SELECT o.*,
    c.client_name, d.document_name, d.document_archived_at,
    owner.user_name AS owner_name, reviewer.user_name AS reviewer_name,
    {$documentation_validity['select']}
    FROM client_documentation_obligations o
    INNER JOIN clients c ON c.client_id = o.documentation_obligation_client_id
    LEFT JOIN documents d ON d.document_id = o.documentation_obligation_document_id
    LEFT JOIN users owner ON owner.user_id = o.documentation_obligation_owner_user_id
    LEFT JOIN users reviewer ON reviewer.user_id = o.documentation_obligation_reviewer_user_id
    {$documentation_validity['joins']}
    WHERE c.client_archived_at IS NULL $client_sql $owner_sql $scope_sql
    ORDER BY
        FIELD(o.documentation_obligation_base_status, 'Missing', 'Stale', 'Due Soon', 'Draft', 'Current', 'Not Applicable'),
        o.documentation_obligation_stale_at,
        documentation_current_requirement_version_name");

$obligations = [];
$status_counts = array_fill_keys($allowed_statuses, 0);
while ($row = mysqli_fetch_assoc($sql_obligations)) {
    $row = documentationApplyCurrentRequirementMetadata($row);
    $projection = documentationProjectObligationValidity($row);
    $row['projected_base_status'] = $projection['base_status'];
    $row['effective_status'] = $projection['effective_status'];
    $status_counts[$row['effective_status']] = intval($status_counts[$row['effective_status']] ?? 0) + 1;
    if ($selected_status === 'attention' && !in_array($row['effective_status'], ['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'], true)) {
        continue;
    }
    if ($selected_status !== 'all' && $selected_status !== 'attention' && $row['effective_status'] !== $selected_status) {
        continue;
    }
    $obligations[] = $row;
}

// A newly published applicable requirement has no durable obligation until the
// evaluator runs. Surface a bounded read-only Missing projection immediately.
$projection_clients = [];
$projection_client_scope = clientScopeSql('client_id');
$projection_client_filter = $client_id ? "AND client_id = $client_id" : '';
$sql_projection_clients = documentationDbQuery("SELECT client_id, client_name, client_type, client_archived_at
    FROM clients WHERE client_archived_at IS NULL $projection_client_filter $projection_client_scope
    ORDER BY client_id", 'Could not load clients for pending documentation projections');
while ($projection_client = mysqli_fetch_assoc($sql_projection_clients)) {
    $projection_clients[] = $projection_client;
}
foreach (documentationPendingObligationRowsForClients($projection_clients, 0) as $row) {
    $owner_matches = $selected_owner === 'all'
        || ($selected_owner === 'mine' && intval($row['documentation_obligation_owner_user_id']) === intval($session_user_id))
        || ($selected_owner === 'unassigned' && intval($row['documentation_obligation_owner_user_id']) === 0);
    if (!$owner_matches) {
        continue;
    }
    $projection = documentationProjectObligationValidity($row);
    $row['projected_base_status'] = $projection['base_status'];
    $row['effective_status'] = $projection['effective_status'];
    $status_counts[$row['effective_status']] = intval($status_counts[$row['effective_status']] ?? 0) + 1;
    if ($selected_status === 'attention' && !in_array($row['effective_status'], ['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'], true)) {
        continue;
    }
    if ($selected_status !== 'all' && $selected_status !== 'attention' && $row['effective_status'] !== $selected_status) {
        continue;
    }
    $obligations[] = $row;
}

$documentation_status_order = array_flip(['Missing', 'Draft', 'Stale', 'Due Soon', 'Exception', 'Current', 'Not Applicable']);
usort($obligations, static function ($left, $right) use ($documentation_status_order) {
    return ($documentation_status_order[$left['effective_status']] ?? 100)
            <=> ($documentation_status_order[$right['effective_status']] ?? 100)
        ?: strcmp((string) ($left['client_name'] ?? ''), (string) ($right['client_name'] ?? ''))
        ?: strcmp((string) ($left['documentation_requirement_version_name'] ?? ''), (string) ($right['documentation_requirement_version_name'] ?? ''))
        ?: intval($left['documentation_obligation_id'] ?? 0) <=> intval($right['documentation_obligation_id'] ?? 0);
});
$documentation_page_size = 100;
$documentation_total_rows = count($obligations);
$documentation_total_pages = max(1, (int) ceil($documentation_total_rows / $documentation_page_size));
$documentation_page = max(1, min($documentation_total_pages, intval($_GET['queue_page'] ?? 1)));
$documentation_page_start = ($documentation_page - 1) * $documentation_page_size;
$obligations = array_slice($obligations, $documentation_page_start, $documentation_page_size);
$documentation_page_url = static function ($target_page) use ($client_id, $selected_owner, $selected_status) {
    $parameters = [
        'owner' => $selected_owner,
        'status' => $selected_status,
        'queue_page' => max(1, intval($target_page)),
    ];
    if ($client_id) {
        $parameters['client_id'] = $client_id;
    }
    return 'documentation.php?' . http_build_query($parameters);
};

$readiness = null;
if ($client_id && function_exists('documentationReadinessForClient')) {
    $readiness = documentationReadinessForClient($client_id);
}
$readiness_denominator = is_array($readiness) ? intval($readiness['denominator'] ?? 0) : 0;
$readiness_score = is_array($readiness) && $readiness_denominator > 0
    ? intval($readiness['score_percent'])
    : null;
$documentation_attention_count = array_sum(array_intersect_key(
    $status_counts,
    array_flip(['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'])
));
$documentation_current_count = intval($status_counts['Current'] ?? 0);

?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h3 mb-1"><i class="fas fa-folder-open text-primary mr-2"></i>Client documents</h1>
        <p class="text-muted mb-0">Attach the records needed to deliver service. Review the set during onboarding and each client service review.</p>
    </div>
</div>

<?php if ($client_id) { require 'includes/inc_client_document_library.php'; } else { ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <strong>Review at the right moment</strong>
            <div class="small text-muted">Documents are supporting material, not a separate daily queue.</div>
        </div>
        <div class="d-flex gap-4 text-center">
            <div><strong class="d-block h4 mb-0 text-warning"><?= $documentation_attention_count ?></strong><span class="small text-muted">Review</span></div>
            <div><strong class="d-block h4 mb-0 text-success"><?= $documentation_current_count ?></strong><span class="small text-muted">Current</span></div>
            <div><strong class="d-block h4 mb-0"><?= array_sum($status_counts) ?></strong><span class="small text-muted">Tracked requirements</span></div>
        </div>
    </div>
</div>

<?php } ?>
<details class="card card-dark n45-document-maintenance">
    <summary class="card-header py-3">
        <span class="n45-document-maintenance-chevron" aria-hidden="true"></span>
        <span class="font-weight-bold">Document maintenance</span>
        <span class="small text-muted ml-2">Optional document-level review controls</span>
    </summary>
    <div class="card-body">
        <form method="get" class="form-row align-items-end mb-3">
            <?php if ($client_id) { ?><input type="hidden" name="client_id" value="<?= $client_id ?>"><?php } ?>
            <div class="form-group col-md-4">
                <label for="documentationStatus">Status</label>
                <select class="form-control" id="documentationStatus" name="status">
                    <option value="attention" <?= $selected_status === 'attention' ? 'selected' : '' ?>>Needs attention</option>
                    <option value="all" <?= $selected_status === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <?php foreach ($allowed_statuses as $status) { ?>
                        <option value="<?= escapeHtml($status) ?>" <?= $selected_status === $status ? 'selected' : '' ?>><?= escapeHtml($status) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label for="documentationOwner">Owner</label>
                <select class="form-control" id="documentationOwner" name="owner">
                    <option value="mine" <?= $selected_owner === 'mine' ? 'selected' : '' ?>>My review items</option>
                    <option value="unassigned" <?= $selected_owner === 'unassigned' ? 'selected' : '' ?>>Needs an owner</option>
                    <option value="all" <?= $selected_owner === 'all' ? 'selected' : '' ?>>Everyone</option>
                </select>
            </div>
            <div class="form-group col-md-2"><button class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Apply</button></div>
        </form>

        <div class="card border-0 bg-light mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="h6 mb-1">Review the document set during onboarding and recurring service reviews</h2>
                    <p class="small text-muted mb-0">Attach the documents needed to deliver service, then use the QBR or service review to address anything missing or outdated.</p>
                </div>
                <div class="d-flex gap-3 text-center">
                    <div><strong class="d-block h5 mb-0 text-warning"><?= $documentation_attention_count ?></strong><span class="small text-muted">Needs attention</span></div>
                    <div><strong class="d-block h5 mb-0 text-success"><?= $documentation_current_count ?></strong><span class="small text-muted">Current</span></div>
                    <div><strong class="d-block h5 mb-0"><?= array_sum($status_counts) ?></strong><span class="small text-muted">Total</span></div>
                </div>
            </div>
        </div>

        <section class="mb-0">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span><i class="fas fa-list-check mr-2 text-muted"></i>Document-level details</span>
                <span class="small text-muted">Open only when a specific record needs attention</span>
            </div>
            <div>
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead><tr><th>Requirement</th><?php if (!$client_id) { ?><th>Client</th><?php } ?><th>Status</th><th>Owner / reviewer</th><th>Review</th><th>Record</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                <?php foreach ($obligations as $obligation) {
                    $obligation_id = intval($obligation['documentation_obligation_id']);
                    $obligation_client_id = intval($obligation['documentation_obligation_client_id']);
                    $document_id = intval($obligation['documentation_obligation_document_id']);
                    $status = $obligation['effective_status'];
                    ?>
                    <tr>
                        <td><strong><?= escapeHtml($obligation['documentation_requirement_version_name']) ?></strong><div class="small text-muted"><code><?= escapeHtml($obligation['documentation_requirement_version_key']) ?></code> · <?= escapeHtml($obligation['documentation_requirement_version_record_type']) ?></div><?php if (!empty($obligation['documentation_obligation_projection_pending'])) { ?><div class="small text-warning"><i class="fas fa-sync-alt mr-1"></i>Projection pending reconciliation</div><?php } ?></td>
                        <?php if (!$client_id) { ?><td><a href="documentation.php?client_id=<?= $obligation_client_id ?>&owner=all"><?= escapeHtml($obligation['client_name']) ?></a></td><?php } ?>
                        <td><span class="badge badge-<?= documentationLifecycleStatusBadge($status) ?>"><?= escapeHtml($status) ?></span><?php if (intval($obligation['documentation_requirement_version_blocks_ticket_resolution'])) { ?><div class="small text-danger mt-1"><i class="fas fa-lock mr-1"></i>Ticket gate</div><?php } ?></td>
                        <td><?= escapeHtml($obligation['owner_name'] ?: $obligation['documentation_obligation_owner_role'] ?: 'Unassigned') ?><div class="small text-muted">Review: <?= escapeHtml($obligation['reviewer_name'] ?: $obligation['documentation_obligation_reviewer_role'] ?: 'Unassigned') ?></div></td>
                        <td><?= $obligation['documentation_obligation_next_review_at'] ? escapeHtml(date('Y-m-d', strtotime($obligation['documentation_obligation_next_review_at']))) : '—' ?><div class="small text-muted"><?= escapeHtml($obligation['documentation_obligation_evaluation_reason_code']) ?></div></td>
                        <td><?php if ($document_id && !empty($obligation['current_document_exists'])) { ?><a href="document.php?client_id=<?= $obligation_client_id ?>&document_id=<?= $document_id ?>"><?= escapeHtml($obligation['document_name']) ?></a><?php } else { ?><span class="text-muted">Not linked</span><?php } ?></td>
                        <td class="text-right"><?php if ($obligation_id) { ?><a href="#" class="btn btn-sm btn-outline-primary ajax-modal" data-modal-size="lg" data-modal-url="modals/documentation/obligation.php?id=<?= $obligation_id ?>"><i class="fas fa-clipboard-check mr-1"></i>Review</a><?php } else { ?><form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="client_id" value="<?= $obligation_client_id ?>"><button class="btn btn-sm btn-outline-warning" name="reconcile_documentation_client"><i class="fas fa-sync-alt mr-1"></i>Reconcile pending</button></form><?php } ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$obligations) { ?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-check-circle fa-2x d-block mb-2"></i>No document details match this view.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($documentation_total_rows) { ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                <span class="small text-muted">Showing <?= $documentation_page_start + 1 ?>–<?= min($documentation_page_start + $documentation_page_size, $documentation_total_rows) ?> of <?= $documentation_total_rows ?> matching obligations.</span>
                <?php if ($documentation_total_pages > 1) { ?>
                    <nav aria-label="Documentation queue pages"><ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $documentation_page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= escapeHtml($documentation_page_url($documentation_page - 1)) ?>">Previous</a></li>
                        <li class="page-item disabled"><span class="page-link">Page <?= $documentation_page ?> of <?= $documentation_total_pages ?></span></li>
                        <li class="page-item <?= $documentation_page >= $documentation_total_pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= escapeHtml($documentation_page_url($documentation_page + 1)) ?>">Next</a></li>
                    </ul></nav>
                <?php } ?>
            </div>
        <?php } ?>
            </div>
        </section>
    </div>
</details>

<?php require_once '../includes/footer.php'; ?>
