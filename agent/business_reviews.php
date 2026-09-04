<?php

require_once 'includes/inc_all_client.php';

enforceUserPermission('module_support');

$year = intval($_GET['year'] ?? date('Y'));
$years = mysqli_query($mysqli, "SELECT DISTINCT YEAR(service_review_period_end) AS review_year
    FROM service_reviews
    WHERE service_review_client_id = $client_id
    ORDER BY review_year DESC");
$reviews_sql = mysqli_query($mysqli, "SELECT service_review_id, service_review_period_start,
    service_review_period_end, service_review_status, service_review_summary,
    service_review_snapshot_hash, agreement_version_name
    FROM service_reviews
    LEFT JOIN agreement_versions ON agreement_version_id = service_review_agreement_version_id
        AND agreement_version_contract_id = service_review_contract_id
    WHERE service_review_client_id = $client_id
        AND YEAR(service_review_period_end) = $year
    ORDER BY service_review_period_end DESC, service_review_id DESC");

$reviews = [];
$completed_reviews = 0;
$draft_reviews = 0;
while ($review = mysqli_fetch_assoc($reviews_sql)) {
    $reviews[] = $review;
    if ($review['service_review_status'] === 'Published') {
        $completed_reviews++;
    } else {
        $draft_reviews++;
    }
}

?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h3 mb-1"><i class="fas fa-fw fa-chart-line mr-2"></i>Business Reviews</h1>
        <p class="text-muted mb-0">Prepare the conversation, record decisions, and leave follow-up work with a clear owner.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a class="btn btn-light" href="documentation.php?client_id=<?= $client_id ?>"><i class="fas fa-folder-open mr-2"></i>Client documents</a>
        <a class="btn btn-light" href="agreements.php?client_id=<?= $client_id ?>"><i class="fas fa-file-contract mr-2"></i>Agreement</a>
    </div>
</div>

<div class="card border-left border-success mb-3">
    <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <strong>Reviews follow the active agreement schedule.</strong>
            <div class="small text-muted">Use the generated review as the meeting agenda. Capture one outcome, then create tickets only for work that needs an owner.</div>
        </div>
        <div class="d-flex mt-3 mt-lg-0">
            <div class="text-center px-3"><strong class="d-block h4 mb-0"><?= $draft_reviews ?></strong><span class="small text-muted">Ready</span></div>
            <div class="text-center px-3 border-left"><strong class="d-block h4 mb-0"><?= $completed_reviews ?></strong><span class="small text-muted">Completed</span></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
        <h2 class="card-title h5 mb-0">Review history</h2>
        <form method="get" class="mt-2 mt-sm-0">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <label class="sr-only" for="businessReviewYear">Review year</label>
            <select class="form-control" id="businessReviewYear" name="year" onchange="this.form.submit()">
                <?php if (!mysqli_num_rows($years)) { ?><option><?= $year ?></option><?php } ?>
                <?php while ($row = mysqli_fetch_assoc($years)) { $review_year = intval($row['review_year']); ?>
                    <option value="<?= $review_year ?>" <?= $year === $review_year ? 'selected' : '' ?>><?= $review_year ?></option>
                <?php } ?>
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (!$reviews) { ?>
            <div class="text-center text-muted py-5 px-3">
                <i class="far fa-calendar-check fa-2x d-block mb-2"></i>
                <strong class="d-block text-body">No reviews for <?= $year ?></strong>
                <span>The first review will appear here when it is due under the active agreement.</span>
            </div>
        <?php } else { ?>
            <div class="list-group list-group-flush">
                <?php foreach ($reviews as $review) { $complete = $review['service_review_status'] === 'Published'; ?>
                    <article class="list-group-item p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start">
                            <div class="pr-md-4">
                                <div class="d-flex align-items-center mb-1">
                                    <strong><?= escapeHtml($review['service_review_period_start']) ?> through <?= escapeHtml($review['service_review_period_end']) ?></strong>
                                    <span class="badge badge-<?= $complete ? 'success' : 'warning' ?> ml-2"><?= $complete ? 'Completed' : 'Ready' ?></span>
                                </div>
                                <p class="mb-1"><?= escapeHtml($review['service_review_summary']) ?></p>
                                <details class="small text-muted">
                                    <summary style="cursor: pointer;">Technical source</summary>
                                    <div class="mt-1">Agreement: <?= escapeHtml($review['agreement_version_name'] ?: 'Unavailable') ?> · Snapshot: <code title="<?= escapeHtml($review['service_review_snapshot_hash']) ?>"><?= escapeHtml(substr($review['service_review_snapshot_hash'], 0, 12)) ?>&hellip;</code></div>
                                </details>
                            </div>
                            <a class="btn btn-sm btn-primary text-nowrap mt-3 mt-md-0" href="service_review.php?review_id=<?= intval($review['service_review_id']) ?>">Open review</a>
                        </div>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
