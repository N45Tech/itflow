<?php

require_once 'includes/inc_all.php';

$contact_context = portalRequestContactContext($session_contact_id, $session_client_id);
$catalog_rows = mysqli_query($mysqli, "SELECT i.portal_request_catalog_item_id,
    i.portal_request_catalog_item_published_version_id
    FROM portal_request_catalog_items i
    INNER JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = i.portal_request_catalog_item_published_version_id
        AND v.portal_request_catalog_version_item_id = i.portal_request_catalog_item_id
    WHERE i.portal_request_catalog_item_archived_at IS NULL
    ORDER BY v.portal_request_catalog_version_order ASC,
        v.portal_request_catalog_version_name ASC");
$available = [];
while ($catalog = mysqli_fetch_assoc($catalog_rows)) {
    try {
        $definition = portalRequestAssertVersion(intval($catalog['portal_request_catalog_item_published_version_id']));
        if (portalRequestContactCanUse($definition, $contact_context, $session_client_id)) {
            $available[] = [
                'version_id' => intval($catalog['portal_request_catalog_item_published_version_id']),
                'definition' => $definition,
            ];
        }
    } catch (Throwable $exception) {
        error_log('Portal request catalog release hidden: ' . $exception->getMessage());
    }
}

$history_scope = contactCan('tickets_all') ? '' : "AND (
    s.portal_request_submission_contact_id = $session_contact_id
    OR (s.portal_request_submission_decided_by_type = 'contact'
        AND s.portal_request_submission_decided_by_id = $session_contact_id)
)";
$submissions = mysqli_query($mysqli, "SELECT s.portal_request_submission_id,
    s.portal_request_submission_ticket_id, s.portal_request_submission_status,
    s.portal_request_submission_submitted_at, v.portal_request_catalog_version_name,
    v.portal_request_catalog_version_icon
    FROM portal_request_submissions s
    INNER JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = s.portal_request_submission_version_id
    WHERE s.portal_request_submission_client_id = $session_client_id $history_scope
    ORDER BY s.portal_request_submission_submitted_at DESC LIMIT 20");

$approval_rows = mysqli_query($mysqli, "SELECT s.portal_request_submission_id,
    s.portal_request_submission_contact_id, s.portal_request_submission_version_id,
    s.portal_request_submission_submitted_at, v.portal_request_catalog_version_name,
    COALESCE(requester.contact_name, 'Former contact') AS requester_name
    FROM portal_request_submissions s
    INNER JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = s.portal_request_submission_version_id
    LEFT JOIN contacts requester ON requester.contact_id = s.portal_request_submission_contact_id
        AND requester.contact_client_id = s.portal_request_submission_client_id
    WHERE s.portal_request_submission_client_id = $session_client_id
    AND s.portal_request_submission_status = 'PendingApproval'
    AND s.portal_request_submission_contact_id <> $session_contact_id
    ORDER BY s.portal_request_submission_submitted_at ASC");
$pending_approvals = [];
while ($approval = mysqli_fetch_assoc($approval_rows)) {
    try {
        $approval_definition = portalRequestAssertVersion(intval($approval['portal_request_submission_version_id']));
        if ($approval_definition['approval_rule'] !== 'internal'
            && $approval_definition['approval_rule'] !== 'none'
            && portalRequestContactMatchesRule($contact_context, $approval_definition['approval_rule'])) {
            $pending_approvals[] = $approval;
        }
    } catch (Throwable $exception) {
        error_log('Pending portal request approval hidden: ' . $exception->getMessage());
    }
}

?>

<div class="n45-form-intro mb-4">
    <h1>Request help</h1>
    <p>Choose a guided request so we collect the right information and start the correct service workflow.</p>
</div>

<?php if ($available) { ?>
    <div class="row">
        <?php foreach ($available as $entry) { $definition = $entry['definition']; ?>
            <div class="col-md-6 col-xl-4 mb-3">
                <a class="card h-100 text-dark text-decoration-none" href="request.php?version_id=<?= intval($entry['version_id']) ?>">
                    <div class="card-body">
                        <i class="<?= escapeHtml($definition['icon']) ?> fa-2x text-primary mb-3" aria-hidden="true"></i>
                        <h2 class="h5"><?= escapeHtml($definition['name']) ?></h2>
                        <p class="text-muted mb-0"><?= escapeHtml($definition['description']) ?></p>
                    </div>
                </a>
            </div>
        <?php } ?>
    </div>
<?php } else { ?>
    <div class="alert alert-info">No guided requests are currently available for your portal role and services.</div>
<?php } ?>

<p><a href="ticket_add.php"><i class="far fa-comment mr-1"></i>Something else? Send a general support request.</a></p>

<?php if ($pending_approvals) { ?>
    <div class="card card-outline card-warning mt-4">
        <div class="card-header"><h2 class="h5 mb-0"><i class="fas fa-user-check mr-2"></i>Waiting for your approval</h2></div>
        <div class="list-group list-group-flush">
            <?php foreach ($pending_approvals as $approval) { ?>
                <a class="list-group-item list-group-item-action" href="request_status.php?id=<?= intval($approval['portal_request_submission_id']) ?>"><strong><?= escapeHtml($approval['portal_request_catalog_version_name']) ?></strong><span class="text-muted ml-2">requested by <?= escapeHtml($approval['requester_name']) ?> · <?= escapeHtml($approval['portal_request_submission_submitted_at']) ?></span></a>
            <?php } ?>
        </div>
    </div>
<?php } ?>

<div class="card mt-4">
    <div class="card-header"><h2 class="h5 mb-0">Recent requests</h2></div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Request</th><th>Status</th><th>Submitted</th><th>Ticket</th></tr></thead>
            <tbody>
            <?php while ($submission = mysqli_fetch_assoc($submissions)) { ?>
                <tr>
                    <td><a href="request_status.php?id=<?= intval($submission['portal_request_submission_id']) ?>"><i class="<?= escapeHtml($submission['portal_request_catalog_version_icon']) ?> fa-fw mr-1"></i><?= escapeHtml($submission['portal_request_catalog_version_name']) ?></a></td>
                    <td><span class="badge <?= portalRequestStatusBadgeClass($submission['portal_request_submission_status']) ?>"><?= escapeHtml(portalRequestStatusLabel($submission['portal_request_submission_status'])) ?></span></td>
                    <td><?= escapeHtml($submission['portal_request_submission_submitted_at']) ?></td>
                    <td><?php if (intval($submission['portal_request_submission_ticket_id'])) { ?><a href="ticket.php?id=<?= intval($submission['portal_request_submission_ticket_id']) ?>">View ticket</a><?php } else { ?>—<?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
