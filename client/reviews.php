<?php

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

enforceContactCan('service_reviews');

$reviews_sql = mysqli_query($mysqli, "SELECT service_review_id, service_review_period_start,
    service_review_period_end, service_review_summary, service_review_published_at
    FROM service_reviews
    WHERE service_review_client_id = $session_client_id
    AND service_review_status = 'Published'
    ORDER BY service_review_period_end DESC, service_review_id DESC");
?>

<header class="n45-page-header">
    <div>
        <h1>Business reviews</h1>
        <p>Review completed meetings, decisions, and follow-up items with your N45 team.</p>
    </div>
</header>

<section class="n45-portal-panel n45-review-index" aria-labelledby="review-history-title">
    <div class="n45-portal-section-heading">
        <div>
            <h2 id="review-history-title">Review history</h2>
            <p>Each review remains available before and after the meeting for discussion.</p>
        </div>
    </div>

    <?php if (!$reviews_sql || mysqli_num_rows($reviews_sql) === 0) { ?>
        <div class="n45-portal-empty-state">
            <span class="n45-portal-empty-icon"><i class="far fa-calendar-check" aria-hidden="true"></i></span>
            <div>
                <h3>No completed reviews yet.</h3>
                <p>Your first business review will appear here when it is ready.</p>
            </div>
        </div>
    <?php } else { ?>
        <div class="n45-review-list" role="list">
            <?php while ($review = mysqli_fetch_assoc($reviews_sql)) {
                $review_id = intval($review['service_review_id']);
                $period_start = date('M j, Y', strtotime($review['service_review_period_start']));
                $period_end = date('M j, Y', strtotime($review['service_review_period_end']));
                ?>
                <a class="n45-review-row" href="review.php?id=<?= $review_id ?>" role="listitem">
                    <span class="n45-review-icon"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
                    <span class="n45-review-copy">
                        <strong><?= escapeHtml($period_start) ?> through <?= escapeHtml($period_end) ?></strong>
                        <span><?= escapeHtml($review['service_review_summary']) ?></span>
                    </span>
                    <span class="n45-review-meta">
                        <span class="n45-review-status">Completed</span>
                        <small><?= portalDateTime($review['service_review_published_at']) ?></small>
                    </span>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            <?php } ?>
        </div>
    <?php } ?>
</section>

<?php require_once 'includes/footer.php'; ?>
