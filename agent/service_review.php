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
$percent_display = static function ($value): string {
    return is_null($value) ? 'Not judged' : number_format(floatval($value), 1) . '%';
};

?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h3 class="mb-1"><i class="fas fa-fw fa-chart-line mr-2"></i>Service Review — <?= escapeHtml($snapshot_client_name) ?></h3>
        <span><?= escapeHtml($review['service_review_period_start']) ?> through <?= escapeHtml($review['service_review_period_end']) ?></span>
        <span class="badge badge-<?= $review['service_review_status'] === 'Published' ? 'success' : 'warning' ?> ml-2"><?= escapeHtml($review['service_review_status']) ?></span>
    </div>
    <div class="d-print-none">
        <?php if (intval($review['service_review_contract_id']) > 0) { ?>
            <a class="btn btn-light" href="agreement.php?agreement_id=<?= intval($review['service_review_contract_id']) ?>&version_id=<?= intval($review['service_review_agreement_version_id']) ?>"><i class="fas fa-arrow-left mr-2"></i>Agreement version</a>
        <?php } ?>
        <a class="btn btn-secondary" href="?review_id=<?= $review_id ?>&export=markdown"><i class="fas fa-download mr-2"></i>Markdown</a>
        <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print mr-2"></i>Print</button>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-fingerprint mr-2"></i>Traceable snapshot
    <code class="ml-2"><?= escapeHtml($review['service_review_snapshot_hash']) ?></code>
    <span class="ml-3"><?= escapeHtml($snapshot_agreement['name']) ?>
        v<?= intval($snapshot_agreement['version_number']) ?>
        <code title="<?= escapeHtml($snapshot_agreement['definition_hash']) ?>"><?= escapeHtml(substr($snapshot_agreement['definition_hash'], 0, 12)) ?>&hellip;</code>
    </span>
</div>

<div class="row">
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-life-ring"></i></span><div class="info-box-content"><span class="info-box-text">Tickets</span><span class="info-box-number"><?= intval($tickets['total'] ?? 0) ?></span><small><?= intval($tickets['open'] ?? 0) ?> open</small></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-reply"></i></span><div class="info-box-content"><span class="info-box-text">Response SLA</span><span class="info-box-number"><?= $percent_display($tickets['response_compliance_percent'] ?? null) ?></span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-flag-checkered"></i></span><div class="info-box-content"><span class="info-box-text">Resolution SLA</span><span class="info-box-number"><?= $percent_display($tickets['resolution_compliance_percent'] ?? null) ?></span></div></div></div>
    <div class="col-md-3"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-redo"></i></span><div class="info-box-content"><span class="info-box-text">Recurring Groups</span><span class="info-box-number"><?= intval($tickets['recurring_issue_groups'] ?? 0) ?></span></div></div></div>
</div>

<div class="row">
    <div class="col-lg-7">
        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title">Coverage and Readiness</h3></div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead><tr><th>Area</th><th>Result</th><th>Source / explanation</th></tr></thead>
                    <tbody>
                    <tr><td>Endpoint management</td><td><?= $percent_display($coverage['endpoint_coverage_percent'] ?? null) ?> <small>(<?= intval($coverage['endpoint_managed_devices'] ?? 0) ?>/<?= intval($coverage['active_devices'] ?? 0) ?>)</small></td><td><?= escapeHtml($coverage['source'] ?? 'Unavailable') ?></td></tr>
                    <tr><td>Endpoint security</td><td><?= $percent_display($coverage['security_coverage_percent'] ?? null) ?> <small>(<?= intval($coverage['security_mapped_devices'] ?? 0) ?>/<?= intval($coverage['active_devices'] ?? 0) ?>)</small></td><td><?= escapeHtml($coverage['source'] ?? 'Unavailable') ?></td></tr>
                    <tr><td>Backup health</td><td><?= ($backup['available'] ?? false) ? intval($backup['open_incidents'] ?? 0) . ' open incident(s)' : (($backup['in_scope'] ?? false) ? 'No source signals' : 'Not in recorded scope') ?></td><td><?= escapeHtml($backup['source'] ?? 'Unavailable') ?></td></tr>
                    <tr><td>Documentation</td><td><?= ($documentation['available'] ?? false) ? $percent_display($documentation['readiness_percent'] ?? null) : 'Readiness provider unavailable' ?></td><td><?= escapeHtml($documentation['note'] ?? $documentation['source'] ?? '') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title">Recurring Issues</h3></div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0"><thead><tr><th>Issue</th><th class="text-right">Occurrences</th></tr></thead><tbody>
                <?php if (empty($tickets['recurring_issues'])) { ?><tr><td colspan="2" class="text-muted">No repeated ticket subjects in this review period.</td></tr><?php } ?>
                <?php foreach (($tickets['recurring_issues'] ?? []) as $issue) { ?><tr><td><?= escapeHtml($issue['subject']) ?></td><td class="text-right"><?= intval($issue['occurrences']) ?></td></tr><?php } ?>
                </tbody></table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title">Renewals (next 365 days)</h3></div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0"><thead><tr><th>Item</th><th>Date</th><th>Notice</th></tr></thead><tbody>
                <?php if (empty($renewals['items'])) { ?><tr><td colspan="3" class="text-muted">No recorded renewals in the next year.</td></tr><?php } ?>
                <?php foreach (($renewals['items'] ?? []) as $item) { ?><tr><td><small class="text-muted text-uppercase"><?= escapeHtml($item['type']) ?></small><br><?= escapeHtml($item['name']) ?></td><td><?= escapeHtml($item['date']) ?></td><td><?= !empty($item['within_notice_window']) ? '<span class="badge badge-warning">Action</span>' : '<span class="text-muted">Later</span>' ?></td></tr><?php } ?>
                </tbody></table>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header"><h3 class="card-title">Recommendations</h3></div>
            <div class="card-body"><ol class="pl-3 mb-0"><?php foreach (($snapshot['recommendations'] ?? []) as $recommendation) { ?><li class="mb-2"><?= escapeHtml($recommendation) ?></li><?php } ?></ol></div>
        </div>
    </div>
</div>

<div class="card card-outline card-secondary">
    <div class="card-header"><h3 class="card-title">Generation and approval evidence</h3></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Event</th><th>Technician</th><th>Time</th><th>Reason</th><th>Snapshot</th></tr></thead>
            <tbody>
            <?php foreach ($review_events as $event) { ?>
                <tr>
                    <td><?= escapeHtml($event['service_review_event_action']) ?></td>
                    <td><?= escapeHtml($event['user_name'] ?: (intval($event['service_review_event_actor_id']) ? 'User ' . intval($event['service_review_event_actor_id']) : 'Automated scheduler')) ?></td>
                    <td><?= escapeHtml($event['service_review_event_created_at']) ?></td>
                    <td><?= escapeHtml($event['service_review_event_reason'] ?? '') ?></td>
                    <td><code><?= escapeHtml(substr($event['service_review_event_snapshot_hash'], 0, 12)) ?>&hellip;</code></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($review['service_review_status'] === 'Draft' && lookupUserPermission('module_support') >= 2) { ?>
    <div class="card border-success d-print-none">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div><strong>Publish this review</strong><div class="text-muted">Publication locks the report and its source snapshot for client presentation.</div></div>
            <form action="post.php" method="post" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="review_id" value="<?= $review_id ?>">
                <input class="form-control mr-2" name="reason" maxlength="255" required placeholder="Approval note">
                <button class="btn btn-success confirm-link" name="publish_service_review"><i class="fas fa-lock mr-2"></i>Publish</button>
            </form>
        </div>
    </div>
<?php } ?>

<?php require_once '../includes/footer.php'; ?>
