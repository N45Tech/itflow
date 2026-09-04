<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/check_login.php';

enforceUserPermission('module_support');
$review_id = intval($_GET['review_id'] ?? 0);
$review_sql = mysqli_query($mysqli, "SELECT service_reviews.*, client_name
    FROM service_reviews
    JOIN clients ON client_id = service_review_client_id
    WHERE service_review_id = $review_id " . clientScopeSql('service_review_client_id') . " LIMIT 1");
if (!$review_sql || !mysqli_num_rows($review_sql)) {
    http_response_code(404);
    exit('Service review not found');
}
$review = mysqli_fetch_assoc($review_sql);
$client_id = intval($review['service_review_client_id']);
enforceClientAccess($client_id);

try {
    $snapshot = agreementValidateServiceReviewSnapshot($review);
    $review_events = agreementServiceReviewEvents($review_id, $client_id);
    $approval = agreementValidateServiceReviewApproval($review, $review_events);
} catch (Throwable $e) {
    error_log("Service review $review_id failed display integrity validation: " . $e->getMessage());
    http_response_code(409);
    exit('Service review snapshot integrity check failed');
}
$snapshot_agreement = $snapshot['agreement'];
$snapshot_client_name = (string) $snapshot['client']['name'];

if (isset($_GET['export']) && $_GET['export'] === 'markdown') {
    $filename_client = preg_replace('/[^A-Za-z0-9_-]+/', '-', $snapshot_client_name);
    $filename_client = trim($filename_client, '-') ?: 'client';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="service-review-' . $filename_client . '-' . $review['service_review_period_end'] . '.md"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo agreementServiceReviewMarkdown($review);
    exit;
}

require_once __DIR__ . '/../includes/page_title.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/top_nav.php';
require_once __DIR__ . '/includes/get_side_nav_counts.php';
require_once __DIR__ . '/includes/side_nav.php';
require_once __DIR__ . '/../includes/inc_wrapper.php';
require_once __DIR__ . '/../includes/inc_alert_feedback.php';

$tickets = $snapshot['tickets'] ?? [];
$coverage = $snapshot['coverage'] ?? [];
$backup = $snapshot['backup'] ?? [];
$documentation = $snapshot['documentation'] ?? [];
$renewals = $snapshot['renewals'] ?? [];
$recommendations = $snapshot['recommendations'] ?? [];
$review_complete = $review['service_review_status'] === 'Published';
$percent_display = static function ($value): string {
    return is_null($value) ? 'Not available' : number_format(floatval($value), 1) . '%';
};
$review_item_state = static function (bool $complete): string {
    return $complete
        ? '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Complete</span>'
        : '<span class="badge badge-light border">Review</span>';
};

?>

<style>
.service-review-intro {
    border-left: 4px solid var(--n45-accent, #167f70);
}
.service-review-list {
    display: grid;
    gap: .75rem;
}
.service-review-item {
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .65rem;
    background: var(--bs-body-bg, #fff);
    overflow: hidden;
}
.service-review-item summary {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .9rem 1rem;
    cursor: pointer;
    list-style: none;
}
.service-review-item summary::-webkit-details-marker {
    display: none;
}
.service-review-item summary::after {
    content: "\f078";
    margin-left: auto;
    color: var(--n45-muted, #586579);
    font-family: "Font Awesome 5 Free";
    font-size: .75rem;
    font-weight: 900;
}
.service-review-item[open] summary::after {
    transform: rotate(180deg);
}
.service-review-number {
    display: inline-grid;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    place-items: center;
    border-radius: 999px;
    background: color-mix(in srgb, var(--n45-accent, #167f70) 12%, transparent);
    color: var(--n45-accent, #167f70);
    font-weight: 700;
}
.service-review-item-body {
    padding: 0 1rem 1rem 3.75rem;
}
.service-review-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .75rem;
}
.service-review-metric {
    padding: .8rem;
    border-radius: .55rem;
    background: var(--bs-secondary-bg);
}
.service-review-metric strong {
    display: block;
    color: var(--n45-ink, #172033);
    font-size: 1.35rem;
}
@media (max-width: 575.98px) {
    .service-review-item-body {
        padding-left: 1rem;
    }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h1 class="h3 mb-1"><i class="fas fa-fw fa-chart-line mr-2"></i>Client service review</h1>
        <div class="text-muted">
            <?= escapeHtml($snapshot_client_name) ?> ·
            <?= escapeHtml($review['service_review_period_start']) ?> through <?= escapeHtml($review['service_review_period_end']) ?>
            <span class="badge badge-<?= $review_complete ? 'success' : 'warning' ?> ml-2"><?= $review_complete ? 'Complete' : 'Draft' ?></span>
        </div>
    </div>
    <div class="d-print-none">
        <?php if (intval($review['service_review_contract_id']) > 0) { ?>
            <a class="btn btn-light" href="agreement.php?agreement_id=<?= intval($review['service_review_contract_id']) ?>&version_id=<?= intval($review['service_review_agreement_version_id']) ?>"><i class="fas fa-file-contract mr-2"></i>Agreement</a>
        <?php } ?>
        <a class="btn btn-secondary" href="?review_id=<?= $review_id ?>&export=markdown"><i class="fas fa-download mr-2"></i>Download notes</a>
        <button class="btn btn-secondary" type="button" onclick="window.print()"><i class="fas fa-print mr-2"></i>Print</button>
    </div>
</div>

<div class="card service-review-intro mb-3">
    <div class="card-body py-3">
        <strong>Use this as the meeting agenda.</strong>
        <span class="text-muted">Review the five items, capture decisions in the outcome note, create any follow-up tickets, and complete the review.</span>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title h5 mb-0">Client review checklist</h2>
            </div>
            <div class="card-body">
                <div class="service-review-list">
                    <details class="service-review-item" open>
                        <summary>
                            <span class="service-review-number">1</span>
                            <span><strong>Service performance</strong><small class="d-block text-muted">Volume, responsiveness, and unresolved work</small></span>
                            <?= $review_item_state($review_complete) ?>
                        </summary>
                        <div class="service-review-item-body">
                            <div class="service-review-metrics">
                                <div class="service-review-metric"><strong><?= intval($tickets['total'] ?? 0) ?></strong><span class="small text-muted">Tickets in period</span></div>
                                <div class="service-review-metric"><strong><?= intval($tickets['open'] ?? 0) ?></strong><span class="small text-muted">Still open</span></div>
                                <div class="service-review-metric"><strong><?= $percent_display($tickets['response_compliance_percent'] ?? null) ?></strong><span class="small text-muted">Response SLA</span></div>
                                <div class="service-review-metric"><strong><?= $percent_display($tickets['resolution_compliance_percent'] ?? null) ?></strong><span class="small text-muted">Resolution SLA</span></div>
                            </div>
                        </div>
                    </details>

                    <details class="service-review-item">
                        <summary>
                            <span class="service-review-number">2</span>
                            <span><strong>Recurring issues</strong><small class="d-block text-muted">Patterns worth preventing instead of repeatedly fixing</small></span>
                            <?= $review_item_state($review_complete) ?>
                        </summary>
                        <div class="service-review-item-body">
                            <?php if (empty($tickets['recurring_issues'])) { ?>
                                <span class="text-muted">No repeated ticket subjects were identified in this period.</span>
                            <?php } else { ?>
                                <ul class="mb-0 pl-3">
                                    <?php foreach (array_slice($tickets['recurring_issues'], 0, 5) as $issue) { ?>
                                        <li class="mb-1"><?= escapeHtml($issue['subject']) ?> <span class="text-muted">(<?= intval($issue['occurrences']) ?>)</span></li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </div>
                    </details>

                    <details class="service-review-item">
                        <summary>
                            <span class="service-review-number">3</span>
                            <span><strong>Protection and resilience</strong><small class="d-block text-muted">Device management, security coverage, and backups</small></span>
                            <?= $review_item_state($review_complete) ?>
                        </summary>
                        <div class="service-review-item-body">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                    <tr><th>Managed endpoints</th><td><?= $percent_display($coverage['endpoint_coverage_percent'] ?? null) ?></td><td class="text-muted"><?= escapeHtml($coverage['source'] ?? 'No connected source') ?></td></tr>
                                    <tr><th>Endpoint security</th><td><?= $percent_display($coverage['security_coverage_percent'] ?? null) ?></td><td class="text-muted"><?= escapeHtml($coverage['source'] ?? 'No connected source') ?></td></tr>
                                    <tr><th>Backups</th><td><?= ($backup['available'] ?? false) ? intval($backup['open_incidents'] ?? 0) . ' open issue(s)' : (($backup['in_scope'] ?? false) ? 'Review source' : 'Not in scope') ?></td><td class="text-muted"><?= escapeHtml($backup['source'] ?? 'No connected source') ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>

                    <details class="service-review-item">
                        <summary>
                            <span class="service-review-number">4</span>
                            <span><strong>Documents and renewals</strong><small class="d-block text-muted">Confirm the useful records and upcoming decisions</small></span>
                            <?= $review_item_state($review_complete) ?>
                        </summary>
                        <div class="service-review-item-body">
                            <p class="mb-2">
                                <a href="documentation.php?client_id=<?= $client_id ?>"><i class="fas fa-folder-open mr-1"></i>Review client documents</a>
                                <span class="text-muted ml-2"><?= escapeHtml($documentation['note'] ?? 'Confirm contacts, network records, recovery information, and other delivery-critical documents.') ?></span>
                            </p>
                            <?php if (empty($renewals['items'])) { ?>
                                <span class="text-muted">No renewals are recorded in the next 365 days.</span>
                            <?php } else { ?>
                                <ul class="mb-0 pl-3">
                                    <?php foreach (array_slice($renewals['items'], 0, 6) as $item) { ?>
                                        <li class="mb-1"><?= escapeHtml($item['name']) ?> <span class="text-muted"><?= escapeHtml($item['date']) ?></span><?php if (!empty($item['within_notice_window'])) { ?> <span class="badge badge-warning">Decision needed</span><?php } ?></li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </div>
                    </details>

                    <details class="service-review-item">
                        <summary>
                            <span class="service-review-number">5</span>
                            <span><strong>Roadmap and next actions</strong><small class="d-block text-muted">Agree priorities, owners, budget, and follow-up work</small></span>
                            <?= $review_item_state($review_complete) ?>
                        </summary>
                        <div class="service-review-item-body">
                            <?php if (empty($recommendations)) { ?>
                                <span class="text-muted">No recommendations were generated. Record any client decisions in the outcome note.</span>
                            <?php } else { ?>
                                <ol class="mb-0 pl-3">
                                    <?php foreach ($recommendations as $recommendation) { ?>
                                        <li class="mb-2"><?= escapeHtml($recommendation) ?></li>
                                    <?php } ?>
                                </ol>
                            <?php } ?>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if (!$review_complete && lookupUserPermission('module_support') >= 2) { ?>
            <div class="card border-success">
                <div class="card-header"><h2 class="card-title h5 mb-0">Complete this review</h2></div>
                <div class="card-body">
                    <p class="text-muted">Add a short outcome covering decisions and follow-up work. The source snapshot will then be locked.</p>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="review_id" value="<?= $review_id ?>">
                        <label for="serviceReviewOutcome">Outcome and next steps</label>
                        <textarea class="form-control mb-3" id="serviceReviewOutcome" name="reason" maxlength="255" rows="4" required placeholder="Decisions, owners, and follow-up tickets"></textarea>
                        <button class="btn btn-success btn-block confirm-link" name="publish_service_review"><i class="fas fa-check mr-2"></i>Complete review</button>
                    </form>
                </div>
            </div>
        <?php } else { ?>
            <div class="card border-success">
                <div class="card-body">
                    <i class="fas fa-check-circle text-success mr-2"></i><strong>Review complete</strong>
                    <?php if (!empty($review['service_review_published_at'])) { ?><div class="small text-muted mt-1"><?= escapeHtml($review['service_review_published_at']) ?></div><?php } ?>
                </div>
            </div>
        <?php } ?>

        <div class="card">
            <div class="card-header"><h2 class="card-title h5 mb-0">Meeting focus</h2></div>
            <div class="card-body">
                <p class="mb-2"><strong>Discuss decisions, not raw counts.</strong></p>
                <p class="small text-muted mb-0">Operational data is supporting evidence. Any action that survives the meeting should become an owned ticket or project task.</p>
            </div>
        </div>
    </div>
</div>

<details class="card card-outline card-secondary">
    <summary class="card-header" style="cursor: pointer;">
        <strong><i class="fas fa-fingerprint mr-2"></i>Technical record</strong>
        <span class="small text-muted ml-2">Snapshot and publication history</span>
    </summary>
    <div class="card-body">
        <div class="small mb-3">
            Agreement: <?= escapeHtml($snapshot_agreement['name']) ?> v<?= intval($snapshot_agreement['version_number']) ?><br>
            Snapshot: <code><?= escapeHtml($review['service_review_snapshot_hash']) ?></code>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Event</th><th>Technician</th><th>Time</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($review_events as $event) { ?>
                    <tr>
                        <td><?= escapeHtml($event['service_review_event_action']) ?></td>
                        <td><?= escapeHtml($event['user_name'] ?: (intval($event['service_review_event_actor_id']) ? 'User ' . intval($event['service_review_event_actor_id']) : 'Automated scheduler')) ?></td>
                        <td><?= escapeHtml($event['service_review_event_created_at']) ?></td>
                        <td><?= escapeHtml($event['service_review_event_reason'] ?? '') ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</details>

<?php require_once '../includes/footer.php'; ?>
