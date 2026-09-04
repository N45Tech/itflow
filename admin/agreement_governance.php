<?php

require_once 'includes/inc_all_admin.php';

$counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    (SELECT COUNT(*) FROM contracts WHERE contract_archived_at IS NULL) AS agreements,
    (SELECT COUNT(*) FROM agreement_versions WHERE agreement_version_status = 'Published') AS published_versions,
    (SELECT COUNT(*) FROM agreement_versions WHERE agreement_version_status = 'Draft') AS drafts,
    (SELECT COUNT(*) FROM contracts WHERE contract_status = 'Active'
        AND contract_next_review_at IS NOT NULL AND contract_next_review_at <= CURDATE()) AS reviews_due,
    (SELECT COUNT(*) FROM service_reviews WHERE service_review_status = 'Draft') AS review_drafts,
    (SELECT COUNT(*) FROM (
        SELECT contract_client_id FROM contracts
        JOIN agreement_versions
            ON agreement_version_id = contract_published_version_id
            AND agreement_version_contract_id = contract_id
        JOIN clients ON client_id = contract_client_id AND client_archived_at IS NULL
        WHERE contract_status = 'Active' AND contract_archived_at IS NULL
        AND agreement_version_status = 'Published' AND contract_client_id > 0
        AND (agreement_version_effective_from IS NULL OR agreement_version_effective_from <= CURDATE())
        AND (agreement_version_effective_until IS NULL OR agreement_version_effective_until >= CURDATE())
        GROUP BY contract_client_id HAVING COUNT(*) > 1
    ) overlapping_clients) AS overlapping_clients,
    (SELECT COUNT(*) FROM agreement_sla_rules
        JOIN agreement_versions ON agreement_version_id = agreement_sla_rule_version_id
        LEFT JOIN slas ON sla_id = agreement_sla_rule_sla_id
        WHERE agreement_version_status IN ('Draft', 'Published')
        AND agreement_sla_rule_sla_id > 0
        AND (sla_id IS NULL OR sla_archived_at IS NOT NULL)) AS unavailable_sla_rules"));

$due = mysqli_query($mysqli, "SELECT contract_id, contract_name, contract_next_review_at,
    contract_end_date, client_id, client_name
    FROM contracts JOIN clients ON client_id = contract_client_id
    WHERE contract_archived_at IS NULL AND contract_status = 'Active'
    AND (contract_next_review_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        OR contract_end_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY))
    ORDER BY LEAST(COALESCE(contract_next_review_at, '9999-12-31'),
        COALESCE(contract_end_date, '9999-12-31')), contract_id");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-contract mr-2"></i>Agreement Governance</h3>
        <div class="card-tools"><a class="btn btn-primary" href="/agent/agreements.php"><i class="fas fa-arrow-right mr-2"></i>Manage Agreements</a></div>
    </div>
    <div class="card-body">
        <p class="text-muted">Published definitions and published service reviews are immutable snapshots. Commercial scope changes are made in a new version; ticket SLA decisions retain the exact version, rule, and explanation used.</p>
        <div class="row">
            <div class="col-md-2"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Agreements</span><span class="info-box-number"><?= intval($counts['agreements']) ?></span></div></div></div>
            <div class="col-md-2"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Published</span><span class="info-box-number"><?= intval($counts['published_versions']) ?></span></div></div></div>
            <div class="col-md-2"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Definition Drafts</span><span class="info-box-number"><?= intval($counts['drafts']) ?></span></div></div></div>
            <div class="col-md-2"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Reviews Due</span><span class="info-box-number"><?= intval($counts['reviews_due']) ?></span></div></div></div>
            <div class="col-md-2"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Review Drafts</span><span class="info-box-number"><?= intval($counts['review_drafts']) ?></span></div></div></div>
            <div class="col-md-2"><div class="info-box <?= intval($counts['unavailable_sla_rules']) ? 'bg-warning' : '' ?>"><div class="info-box-content"><span class="info-box-text">Invalid SLA Rules</span><span class="info-box-number"><?= intval($counts['unavailable_sla_rules']) ?></span></div></div></div>
        </div>

        <?php if (intval($counts['overlapping_clients']) > 0) { ?>
            <div class="alert alert-warning">
                <strong><?= intval($counts['overlapping_clients']) ?> client(s) have overlapping active published agreements.</strong>
                Ticket selection remains deterministic, but review whether latest effective date/version/contract ordering reflects the intended commercial scope.
            </div>
        <?php } ?>

        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Upcoming Reviews and Renewals</h3></div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead><tr><th>Client</th><th>Agreement</th><th>Next review</th><th>End / renewal</th><th></th></tr></thead>
                    <tbody>
                    <?php $due_count = 0; while ($row = mysqli_fetch_assoc($due)) { $due_count++; ?>
                        <tr>
                            <td><?= escapeHtml($row['client_name']) ?></td>
                            <td><?= escapeHtml($row['contract_name']) ?></td>
                            <td><?= escapeHtml($row['contract_next_review_at'] ?: 'Not scheduled') ?></td>
                            <td><?= escapeHtml($row['contract_end_date'] ?: 'Evergreen') ?></td>
                            <td class="text-right"><a class="btn btn-sm btn-secondary" href="/agent/agreement.php?agreement_id=<?= intval($row['contract_id']) ?>">Open</a></td>
                        </tr>
                    <?php } if (!$due_count) { ?><tr><td colspan="5" class="text-muted">No agreement reviews or renewals are due in the governance window.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="alert alert-secondary mb-0">
            <strong>Integration seams:</strong> request catalog keys are consumed through <code>requestCatalogAgreementKeyForTicket()</code>; documentation readiness through <code>documentationServiceReviewReadiness()</code>; unified endpoint metrics through <code>unifiedDeviceServiceReviewSnapshot()</code>. Each adapter is optional and its absence is disclosed in generated reviews.
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
