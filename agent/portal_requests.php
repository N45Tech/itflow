<?php

$sort = 'portal_request_submission_submitted_at';
$order = 'DESC';
require_once 'includes/inc_all.php';
enforceUserPermission('module_support');

$status = (string) ($_GET['status'] ?? '');
$allowed_statuses = ['PendingApproval', 'Initiated', 'Declined'];
$status_filter = in_array($status, $allowed_statuses, true)
    ? "AND s.portal_request_submission_status = '" . escapeSql($status) . "'" : '';
$scope = clientScopeSql('s.portal_request_submission_client_id');
$submissions = mysqli_query($mysqli, "SELECT s.portal_request_submission_id,
    s.portal_request_submission_version_id, s.portal_request_submission_client_id,
    s.portal_request_submission_contact_id, s.portal_request_submission_ticket_id,
    s.portal_request_submission_status, s.portal_request_submission_responses,
    s.portal_request_submission_response_hash, s.portal_request_submission_submitted_at,
    v.portal_request_catalog_version_name, v.portal_request_catalog_version_type,
    v.portal_request_catalog_version_approval_rule,
    c.client_id AS live_client_id,
    COALESCE(c.client_name, CONCAT('Client #', s.portal_request_submission_client_id)) AS client_name,
    COALESCE(requester.contact_name,
        CONCAT('Contact #', s.portal_request_submission_contact_id)) AS requester_name,
    t.ticket_prefix, t.ticket_number
    FROM portal_request_submissions s
    INNER JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = s.portal_request_submission_version_id
    LEFT JOIN clients c ON c.client_id = s.portal_request_submission_client_id
    LEFT JOIN contacts requester ON requester.contact_id = s.portal_request_submission_contact_id
        AND requester.contact_client_id = s.portal_request_submission_client_id
    LEFT JOIN tickets t ON t.ticket_id = s.portal_request_submission_ticket_id
        AND t.ticket_client_id = s.portal_request_submission_client_id
    WHERE 1 = 1 $scope $status_filter
    ORDER BY s.portal_request_submission_submitted_at DESC LIMIT 100");

?>

<div class="card card-dark">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Portal Requests</h3></div>
    <div class="card-body">
        <div class="btn-group mb-3"><a class="btn btn-sm <?= $status === '' ? 'btn-primary' : 'btn-outline-primary' ?>" href="portal_requests.php">All</a><?php foreach ($allowed_statuses as $filter) { ?><a class="btn btn-sm <?= $status === $filter ? 'btn-primary' : 'btn-outline-primary' ?>" href="?status=<?= escapeHtml($filter) ?>"><?= escapeHtml(portalRequestStatusLabel($filter)) ?></a><?php } ?></div>
        <div class="table-responsive">
            <table class="table table-striped table-borderless">
                <thead><tr><th>Request</th><th>Client / requester</th><th>Status</th><th>Submitted</th><th>Ticket</th><th class="text-right">Approval</th></tr></thead>
                <tbody>
                <?php while ($submission = mysqli_fetch_assoc($submissions)) {
                    $submission_id = intval($submission['portal_request_submission_id']);
                    $definition = null;
                    $responses = [];
                    try {
                        $definition = portalRequestAssertVersion(intval($submission['portal_request_submission_version_id']));
                        $responses = portalRequestResponsePayload($submission);
                    } catch (Throwable $exception) {
                        error_log("Portal request #$submission_id integrity failure: " . $exception->getMessage());
                    }
                    $can_decide = $definition
                        && $submission['portal_request_submission_status'] === 'PendingApproval'
                        && $definition['approval_rule'] === 'internal'
                        && lookupUserPermission('module_support') >= 2;
                    ?>
                    <tr>
                        <td>
                            <details><summary><strong><?= escapeHtml($submission['portal_request_catalog_version_name']) ?></strong> <span class="small text-muted">#<?= $submission_id ?></span></summary>
                                <?php if (!$definition) { ?><div class="alert alert-danger mt-2 mb-0">Snapshot integrity check failed.</div><?php } else { ?><dl class="mt-2 mb-0"><?php foreach ($definition['fields'] as $field) { ?><dt><?= escapeHtml($field['label']) ?></dt><dd><?= nl2br(escapeHtml(portalRequestResponseText($responses[$field['key']] ?? null))) ?></dd><?php } ?></dl><?php } ?>
                            </details>
                        </td>
                        <td><?php if (intval($submission['live_client_id'])) { ?><a href="client_overview.php?client_id=<?= intval($submission['portal_request_submission_client_id']) ?>"><?= escapeHtml($submission['client_name']) ?></a><?php } else { ?><?= escapeHtml($submission['client_name']) ?><?php } ?><div class="small text-muted"><?= escapeHtml($submission['requester_name']) ?></div></td>
                        <td><span class="badge <?= portalRequestStatusBadgeClass($submission['portal_request_submission_status']) ?>"><?= escapeHtml(portalRequestStatusLabel($submission['portal_request_submission_status'])) ?></span></td>
                        <td><?= escapeHtml($submission['portal_request_submission_submitted_at']) ?></td>
                        <td><?php if (intval($submission['portal_request_submission_ticket_id'])) { ?><a href="ticket.php?ticket_id=<?= intval($submission['portal_request_submission_ticket_id']) ?>"><?= escapeHtml($submission['ticket_prefix']) . intval($submission['ticket_number']) ?></a><?php } else { ?>—<?php } ?></td>
                        <td class="text-right">
                            <?php if ($can_decide) { ?>
                                <form action="post.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="submission_id" value="<?= $submission_id ?>">
                                    <button class="btn btn-sm btn-success" name="decision" value="approved">Approve</button>
                                    <button class="btn btn-sm btn-outline-danger" name="decision" value="declined">Decline</button>
                                    <input type="hidden" name="decide_internal_portal_request" value="1">
                                </form>
                            <?php } elseif ($submission['portal_request_submission_status'] === 'PendingApproval') { ?>
                                <span class="small text-muted"><?= escapeHtml(portalRequestApprovalRules()[$submission['portal_request_catalog_version_approval_rule']] ?? 'External') ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
