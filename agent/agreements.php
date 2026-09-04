<?php

$sort = 'contract_name';
$order = 'ASC';

if (isset($_GET['client_id'])) {
    require_once 'includes/inc_all_client.php';
    $client_filter = 'AND contract_client_id = ' . intval($client_id);
    $client_url = 'client_id=' . intval($client_id) . '&';
} else {
    require_once 'includes/inc_client_overview_all.php';
    $client_filter = '';
    $client_url = '';
}

enforceUserPermission('module_support');

$allowed_sort = [
    'contract_name', 'client_name', 'contract_status', 'contract_end_date',
    'contract_next_review_at', 'contract_created_at',
];
if (!in_array($sort, $allowed_sort, true)) {
    $sort = 'contract_name';
}

$agreements = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS contracts.*, client_name,
    published.agreement_version_number AS published_version_number,
    published.agreement_version_definition_hash AS published_hash,
    (SELECT agreement_version_id FROM agreement_versions draft
        WHERE draft.agreement_version_contract_id = contract_id
        AND draft.agreement_version_status = 'Draft'
        ORDER BY draft.agreement_version_number DESC LIMIT 1) AS draft_version_id,
    (SELECT COUNT(*) FROM service_reviews
        WHERE service_review_contract_id = contract_id) AS review_count
    FROM contracts
    JOIN clients ON client_id = contract_client_id
    LEFT JOIN agreement_versions published ON agreement_version_id = contract_published_version_id
    WHERE contract_archived_at IS NULL AND client_archived_at IS NULL
    AND (contract_name LIKE '%$q%' OR client_name LIKE '%$q%' OR contract_type LIKE '%$q%')
    " . clientScopeSql('contract_client_id') . "
    $client_filter
    ORDER BY $sort $order LIMIT $record_from, $record_to");
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT FOUND_ROWS()'));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-contract mr-2"></i>Agreements</h3>
        <div class="card-tools">
            <?php if (lookupUserPermission('module_support') >= 2) { ?>
                <a class="btn btn-primary" href="agreement_create.php?<?= $client_url ?>"><i class="fas fa-plus mr-2" aria-hidden="true"></i>New Agreement</a>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <?php if (!empty($client_id)) { ?><input type="hidden" name="client_id" value="<?= intval($client_id) ?>"><?php } ?>
            <div class="input-group mb-3">
                <input type="search" class="form-control" name="q" value="<?= escapeHtml(stripslashes($q ?? '')) ?>"
                    placeholder="Search agreements, types, or clients">
                <div class="input-group-append"><button class="btn btn-dark"><i class="fas fa-search"></i></button></div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="<?= intval($num_rows[0] ?? 0) === 0 ? 'd-none' : '' ?>">
                <tr>
                    <th>Agreement</th>
                    <?php if (empty($client_id)) { ?><th>Client</th><?php } ?>
                    <th>Status</th>
                    <th>Current version</th>
                    <th>Term / renewal</th>
                    <th>Next review</th>
                    <th class="text-right">Reviews</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($agreement = mysqli_fetch_assoc($agreements)) {
                    $agreement_id = intval($agreement['contract_id']);
                    $agreement_client_id = intval($agreement['contract_client_id']);
                    $draft_id = intval($agreement['draft_version_id']);
                    $published_version = intval($agreement['published_version_number']);
                    $status = (string) $agreement['contract_status'];
                    $badge = $status === 'Active' ? 'success' : ($draft_id > 0 ? 'warning' : 'secondary');
                    ?>
                    <tr>
                        <td>
                            <a class="text-dark text-bold" href="agreement.php?agreement_id=<?= $agreement_id ?><?= $draft_id ? '&version_id=' . $draft_id : '' ?>">
                                <?= escapeHtml($agreement['contract_name']) ?>
                            </a>
                            <div><small class="text-muted"><?= escapeHtml($agreement['contract_type']) ?></small></div>
                        </td>
                        <?php if (empty($client_id)) { ?>
                            <td><a href="agreements.php?client_id=<?= $agreement_client_id ?>"><?= escapeHtml($agreement['client_name']) ?></a></td>
                        <?php } ?>
                        <td>
                            <span class="badge badge-<?= $badge ?>"><?= escapeHtml($status) ?></span>
                            <?php if ($draft_id > 0) { ?><span class="badge badge-warning ml-1">Draft</span><?php } ?>
                        </td>
                        <td>
                            <?= $published_version > 0 ? 'v' . $published_version : 'Not published' ?>
                            <?php if (!empty($agreement['published_hash'])) { ?>
                                <div><small class="text-muted" title="<?= escapeHtml($agreement['published_hash']) ?>"><?= escapeHtml(substr($agreement['published_hash'], 0, 12)) ?>&hellip;</small></div>
                            <?php } ?>
                        </td>
                        <td>
                            <?= empty($agreement['contract_end_date']) ? 'Evergreen' : escapeHtml($agreement['contract_end_date']) ?>
                        </td>
                        <td>
                            <?php if (empty($agreement['contract_next_review_at'])) { ?>
                                <span class="text-muted">Not scheduled</span>
                            <?php } elseif ($agreement['contract_next_review_at'] <= date('Y-m-d')) { ?>
                                <span class="text-danger text-bold"><?= escapeHtml($agreement['contract_next_review_at']) ?> due</span>
                            <?php } else { ?>
                                <?= escapeHtml($agreement['contract_next_review_at']) ?>
                            <?php } ?>
                        </td>
                        <td class="text-right"><?= intval($agreement['review_count']) ?></td>
                        <td class="text-center">
                            <?php if (!$draft_id && !$published_version && lookupUserPermission('module_support') >= 2) { ?>
                                <form action="post.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="contract_id" value="<?= $agreement_id ?>">
                                    <button class="btn btn-sm btn-primary" name="create_agreement_draft" title="Create initial versioned draft"><i class="fas fa-code-branch"></i></button>
                                </form>
                            <?php } else { ?>
                                <a class="btn btn-sm btn-secondary" href="agreement.php?agreement_id=<?= $agreement_id ?><?= $draft_id ? '&version_id=' . $draft_id : '' ?>" title="Open agreement">
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once '../includes/filter_footer.php'; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
