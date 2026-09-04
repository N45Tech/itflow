<?php

require_once 'includes/inc_all_reports.php';
enforceUserPermission('module_support');

$year = intval($_GET['year'] ?? date('Y'));
$client_filter = intval($_GET['client_id'] ?? 0);
$client_where = $client_filter > 0 ? "AND service_review_client_id = $client_filter" : '';
$years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(service_review_period_end) AS review_year
    FROM service_reviews WHERE 1 = 1 " . clientScopeSql('service_review_client_id') . "
    ORDER BY review_year DESC");
$clients = mysqli_query($mysqli, "SELECT DISTINCT client_id, client_name FROM service_reviews
    JOIN clients ON client_id = service_review_client_id
    WHERE client_archived_at IS NULL " . clientScopeSql('service_review_client_id') . "
    ORDER BY client_name");
$reviews = mysqli_query($mysqli, "SELECT service_review_id, service_review_period_start,
    service_review_period_end, service_review_status, service_review_summary,
    service_review_snapshot_hash, service_review_generated_at, client_id, client_name,
    agreement_version_name
    FROM service_reviews JOIN clients ON client_id = service_review_client_id
    LEFT JOIN agreement_versions ON agreement_version_id = service_review_agreement_version_id
        AND agreement_version_contract_id = service_review_contract_id
    WHERE YEAR(service_review_period_end) = $year
    " . clientScopeSql('service_review_client_id') . " $client_where
    ORDER BY service_review_period_end DESC, client_name, service_review_id DESC");

?>

<div class="card card-dark">
    <div class="card-header py-3"><h3 class="h5 mb-1"><i class="fas fa-fw fa-chart-line mr-2"></i>Business Reviews</h3><p class="small text-muted mb-0">Meeting outcomes, client documents, and owned follow-up work in one place.</p></div>
    <div class="card-body">
        <form class="form-row mb-3">
            <div class="col-md-3"><select class="form-control" name="year" onchange="this.form.submit()">
                <?php if (!mysqli_num_rows($years)) { ?><option><?= $year ?></option><?php } ?>
                <?php while ($row = mysqli_fetch_assoc($years)) { $review_year = intval($row['review_year']); ?><option value="<?= $review_year ?>" <?= $year === $review_year ? 'selected' : '' ?>><?= $review_year ?></option><?php } ?>
            </select></div>
            <div class="col-md-5"><select class="form-control select2" name="client_id" onchange="this.form.submit()"><option value="0">All clients</option><?php while ($row = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($row['client_id']) ?>" <?= $client_filter === intval($row['client_id']) ? 'selected' : '' ?>><?= escapeHtml($row['client_name']) ?></option><?php } ?></select></div>
        </form>
        <div class="table-responsive">
            <table class="table table-striped table-borderless">
                <thead><tr><th>Client</th><th>Period</th><th>Status</th><th>Review</th></tr></thead>
                <tbody>
                <?php $count = 0; while ($review = mysqli_fetch_assoc($reviews)) { $count++; ?>
                    <tr>
                        <td><?= escapeHtml($review['client_name']) ?></td>
                        <td><?= escapeHtml($review['service_review_period_start']) ?> through <?= escapeHtml($review['service_review_period_end']) ?></td>
                        <td><span class="badge badge-<?= $review['service_review_status'] === 'Published' ? 'success' : 'warning' ?>"><?= escapeHtml($review['service_review_status'] === 'Published' ? 'Completed' : $review['service_review_status']) ?></span></td>
                        <td><div class="d-flex justify-content-between align-items-start gap-3"><div><?= escapeHtml($review['service_review_summary']) ?><details class="small text-muted mt-2"><summary style="cursor: pointer;">Evidence details</summary><div class="mt-1">Agreement: <?= escapeHtml($review['agreement_version_name'] ?: 'Unavailable') ?><br>Snapshot: <code title="<?= escapeHtml($review['service_review_snapshot_hash']) ?>"><?= escapeHtml(substr($review['service_review_snapshot_hash'], 0, 12)) ?>&hellip;</code></div></details></div><a class="btn btn-sm btn-primary text-nowrap" href="/agent/service_review.php?review_id=<?= intval($review['service_review_id']) ?>">Open review</a></div></td>
                    </tr>
                <?php } if (!$count) { ?><tr><td colspan="4" class="text-center text-muted py-5"><i class="fas fa-calendar-check fa-2x d-block mb-2"></i>No service reviews match this period.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
