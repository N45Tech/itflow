<?php

$sort = 'portal_request_catalog_item_order';
$order = 'ASC';
require_once 'includes/inc_all_admin.php';

$show_archived = isset($_GET['archived']);
$archive_filter = $show_archived
    ? 'portal_request_catalog_item_archived_at IS NOT NULL'
    : 'portal_request_catalog_item_archived_at IS NULL';
$items = mysqli_query($mysqli, "SELECT i.*,
    v.portal_request_catalog_version_number,
    (SELECT COUNT(*) FROM portal_request_catalog_fields f
        WHERE f.portal_request_catalog_field_item_id = i.portal_request_catalog_item_id) AS field_count,
    (SELECT COUNT(*) FROM portal_request_submissions s
        WHERE s.portal_request_submission_item_id = i.portal_request_catalog_item_id) AS submission_count
    FROM portal_request_catalog_items i
    LEFT JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = i.portal_request_catalog_item_published_version_id
        AND v.portal_request_catalog_version_item_id = i.portal_request_catalog_item_id
    WHERE $archive_filter
    ORDER BY portal_request_catalog_item_order ASC, portal_request_catalog_item_name ASC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clipboard-list mr-2"></i>Portal Request Catalog</h3>
        <div class="card-tools">
            <a class="btn btn-outline-light mr-1" href="?<?= $show_archived ? '' : 'archived=1' ?>">
                <?= $show_archived ? 'Active requests' : 'Archived requests' ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$show_archived) { ?>
            <div class="row mb-4">
                <div class="col-lg-7">
                    <form action="post.php" method="post" class="form-inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input class="form-control mr-2 mb-2" name="name" maxlength="200" placeholder="Request name" required>
                        <input class="form-control mr-2 mb-2" name="key" maxlength="100" placeholder="stable-key">
                        <select class="form-control mr-2 mb-2" name="type">
                            <?php foreach (portalRequestTypes() as $value => $label) { ?>
                                <option value="<?= escapeHtml($value) ?>"><?= escapeHtml($label) ?></option>
                            <?php } ?>
                        </select>
                        <button class="btn btn-primary mb-2" name="add_portal_request_catalog_item">
                            <i class="fas fa-plus mr-1"></i>New draft
                        </button>
                    </form>
                </div>
                <div class="col-lg-5 text-lg-right">
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button class="btn btn-outline-secondary" name="install_portal_request_starters">
                            <i class="fas fa-sync-alt mr-1"></i>Reconcile six starter requests
                        </button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">Publishes only against an active, hash-valid canonical runbook. Missing or incompatible prerequisites leave the request safely unavailable as a draft.</p>
                </div>
            </div>
        <?php } ?>

        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead>
                <tr><th>Request</th><th>Release</th><th>Portal rule</th><th>Fields</th><th>Uses</th><th class="text-right">Action</th></tr>
                </thead>
                <tbody>
                <?php while ($item = mysqli_fetch_assoc($items)) {
                    $item_id = intval($item['portal_request_catalog_item_id']);
                    $version = intval($item['portal_request_catalog_version_number']);
                    ?>
                    <tr>
                        <td>
                            <a href="portal_request_catalog_item.php?item_id=<?= $item_id ?>">
                                <i class="<?= escapeHtml($item['portal_request_catalog_item_icon']) ?> fa-fw mr-1"></i>
                                <strong><?= escapeHtml($item['portal_request_catalog_item_name']) ?></strong>
                            </a>
                            <div class="small text-muted"><code><?= escapeHtml($item['portal_request_catalog_item_key']) ?></code> · <?= escapeHtml(portalRequestTypes()[$item['portal_request_catalog_item_type']] ?? 'Other') ?></div>
                        </td>
                        <td><span class="badge <?= $version ? 'badge-success' : 'badge-warning' ?>"><?= $version ? "v$version" : 'Draft only' ?></span></td>
                        <td><?= escapeHtml(portalRequestPermissionRules()[$item['portal_request_catalog_item_permission_rule']] ?? 'Invalid') ?></td>
                        <td><?= intval($item['field_count']) ?></td>
                        <td><?= intval($item['submission_count']) ?></td>
                        <td class="text-right">
                            <form action="post.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="item_id" value="<?= $item_id ?>">
                                <?php if ($show_archived) { ?>
                                    <button class="btn btn-sm btn-outline-success" name="restore_portal_request_catalog_item">Restore</button>
                                <?php } else { ?>
                                    <button class="btn btn-sm btn-outline-danger confirm-link" name="archive_portal_request_catalog_item">Archive</button>
                                <?php } ?>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
