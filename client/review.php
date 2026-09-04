<?php

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

enforceContactCan('service_reviews');

$review_id = intval($_GET['id'] ?? 0);
$review_sql = mysqli_query($mysqli, "SELECT service_reviews.*
    FROM service_reviews
    WHERE service_review_id = $review_id
    AND service_review_client_id = $session_client_id
    AND service_review_status = 'Published'
    LIMIT 1");
$review = $review_sql ? mysqli_fetch_assoc($review_sql) : null;
if (!$review) {
    http_response_code(404);
    exit('Business review not found');
}

try {
    $snapshot = agreementValidateServiceReviewSnapshot($review);
} catch (Throwable $exception) {
    error_log("Portal business review $review_id failed integrity validation: " . $exception->getMessage());
    http_response_code(409);
    exit('Business review integrity check failed');
}

$recommendations = is_array($snapshot['recommendations'] ?? null) ? $snapshot['recommendations'] : [];
$events_sql = mysqli_query($mysqli, "SELECT service_review_event_action, service_review_event_reason,
    service_review_event_created_at, user_name
    FROM service_review_events
    LEFT JOIN users ON user_id = service_review_event_actor_id
    WHERE service_review_event_review_id = $review_id
    AND service_review_event_client_id = $session_client_id
    AND service_review_event_action IN ('Published', 'ClientComment')
    ORDER BY service_review_event_id ASC");
$completion_note = '';
$comments = [];
while ($events_sql && ($event = mysqli_fetch_assoc($events_sql))) {
    if ($event['service_review_event_action'] === 'Published') {
        $completion_note = (string) ($event['service_review_event_reason'] ?? '');
    } else {
        $comments[] = $event;
    }
}

$period_start = date('M j, Y', strtotime($review['service_review_period_start']));
$period_end = date('M j, Y', strtotime($review['service_review_period_end']));
?>

<header class="n45-page-header n45-review-page-header">
    <div>
        <a class="n45-portal-back-link" href="reviews.php"><i class="fas fa-chevron-left" aria-hidden="true"></i>Business reviews</a>
        <h1><?= escapeHtml($period_start) ?> through <?= escapeHtml($period_end) ?></h1>
        <p>Completed <?= portalDateTime($review['service_review_published_at']) ?></p>
    </div>
    <span class="n45-review-status">Completed</span>
</header>

<div class="n45-review-layout">
    <main>
        <section class="n45-portal-panel" aria-labelledby="review-summary-title">
            <div class="n45-portal-section-heading">
                <div>
                    <h2 id="review-summary-title">Review summary</h2>
                    <p>The decisions and priorities captured for this review period.</p>
                </div>
            </div>
            <div class="n45-review-content">
                <p class="n45-review-lead"><?= nl2br(escapeHtml($review['service_review_summary'])) ?></p>
                <?php if ($completion_note !== '') { ?>
                    <div class="n45-review-outcome">
                        <strong>Outcome and next steps</strong>
                        <p><?= nl2br(escapeHtml($completion_note)) ?></p>
                    </div>
                <?php } ?>
                <?php if ($recommendations) { ?>
                    <h3>Recommended follow-up</h3>
                    <ul class="n45-review-actions">
                        <?php foreach ($recommendations as $recommendation) { ?>
                            <li><?= escapeHtml((string) $recommendation) ?></li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        </section>

        <section class="n45-portal-panel" id="discussion" aria-labelledby="discussion-title">
            <div class="n45-portal-section-heading">
                <div>
                    <h2 id="discussion-title">Discussion</h2>
                    <p>Add context or questions before or after the meeting.</p>
                </div>
                <?php if ($comments) { ?><span class="n45-portal-count"><?= count($comments) ?></span><?php } ?>
            </div>
            <div class="n45-review-discussion">
                <?php if (!$comments) { ?>
                    <p class="text-muted">No comments yet. Start the conversation below.</p>
                <?php } else { ?>
                    <?php foreach ($comments as $comment) { ?>
                        <article class="n45-review-comment">
                            <div>
                                <strong><?= escapeHtml($comment['user_name'] ?: 'Portal participant') ?></strong>
                                <time><?= portalDateTime($comment['service_review_event_created_at']) ?></time>
                            </div>
                            <p><?= nl2br(escapeHtml($comment['service_review_event_reason'])) ?></p>
                        </article>
                    <?php } ?>
                <?php } ?>
                <form action="post.php" method="post" class="n45-review-comment-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="review_id" value="<?= $review_id ?>">
                    <label for="reviewComment">Add a comment</label>
                    <textarea class="form-control" id="reviewComment" name="comment" rows="3" maxlength="255" required placeholder="Question, context, or follow-up for the N45 team"></textarea>
                    <div class="n45-review-comment-actions">
                        <small>Up to 255 characters</small>
                        <button class="btn btn-primary" type="submit" name="add_service_review_comment"><i class="far fa-comment" aria-hidden="true"></i>Add comment</button>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <aside>
        <section class="n45-portal-panel n45-review-reference" aria-labelledby="review-reference-title">
            <div class="n45-portal-section-heading"><div><h2 id="review-reference-title">Client records</h2></div></div>
            <p>Supporting documents remain in your client record so the meeting stays focused on decisions.</p>
            <?php if (contactCan('itdoc')) { ?>
                <a class="n45-portal-panel-action" href="documents.php">View documents <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
            <?php } else { ?>
                <p class="small text-muted mb-0">Ask an authorized technical contact or your N45 team for document access.</p>
            <?php } ?>
        </section>
    </aside>
</div>

<?php require_once 'includes/footer.php'; ?>
