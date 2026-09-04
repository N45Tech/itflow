<?php
/*
* Client Portal
* Contact management for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

if (!$config_module_enable_itdoc) {
    redirect('index.php');
}

$asset_contact_scope = contactCan('assets_all') ? '' : "AND asset_contact_id = $session_contact_id";
$assets_sql = mysqli_query($mysqli, "SELECT asset_description, asset_id, asset_make, asset_model, asset_name, asset_purchase_date,
    asset_serial, asset_status, asset_type, asset_uri_client, asset_warranty_expire,
    contact_name FROM assets LEFT JOIN contacts ON asset_contact_id = contact_id WHERE asset_client_id = $session_client_id $asset_contact_scope AND asset_archived_at IS NULL ORDER BY asset_type ASC, asset_name ASC");
?>

    <header class="n45-page-header">
        <div>
            <h1>Technology assets</h1>
            <p><?= contactCan('assets_all') ? 'Review the devices and systems N45 has documented for your organization.' : 'Review the devices and systems assigned to you.' ?></p>
        </div>
    </header>

    <div class="row">

        <div class="col-md-12">

            <?php if (mysqli_num_rows($assets_sql) == 0) { ?>
                <?= portalEmptyState('There are no assets on this account yet.') ?>
            <?php } else { ?>
            <table class="table table-bordered border border-dark">
                <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Model</th>
                    <th>Serial</th>
                    <th>Assigned</th>
                    <th>Purchase</th>
                    <th>Warranty</th>
                    <th>Status</th>
                    <th>URI</th>
                </tr>
                </thead>
                <tbody>

                <?php
                while ($row = mysqli_fetch_assoc($assets_sql)) {
                    $asset_id = intval($row['asset_id']);
                    $asset_name = escapeHtml($row['asset_name']);
                    $asset_description = escapeHtml($row['asset_description']);
                    $asset_type = escapeHtml($row['asset_type']);
                    $asset_make = escapeHtml($row['asset_make']);
                    $asset_model = escapeHtml($row['asset_model']);
                    $asset_serial = escapeHtml($row['asset_serial']);
                    $asset_purchase_date = escapeHtml($row['asset_purchase_date'] ?? "-");
                    $asset_warranty_expire = escapeHtml($row['asset_warranty_expire'] ?? "-");
                    $assigned_to = escapeHtml($row['contact_name'] ?? "-");
                    $asset_status = escapeHtml($row['asset_status']);
                    $asset_uri_client = escapeUrl($row['asset_uri_client']);

                    ?>

                    <tr>
                        <td>
                            <strong><?= $asset_name ?></strong>
                            <br>
                            <small class="text-secondary"><?= $asset_description ?></small>
                        </td>
                        <td><?= $asset_type ?></td>
                        <td><?= "$asset_make<br><span class='text-secondary'>$asset_model</span>" ?></td>
                        <td><?= $asset_serial ?></td>
                        <td><?= $assigned_to ?></td>
                        <td><?= $asset_purchase_date ?></td>
                        <td><?= $asset_warranty_expire ?></td>
                        <td><?= $asset_status ?></td>
                        <td>
                            <?php if ($asset_uri_client) { ?>
                            <i class="fa fa-fw fa-link text-secondary me-1"></i><a href="<?= $asset_uri_client ?>" target="_blank" title="<?= $asset_uri_client ?>"><?= truncate($asset_uri_client, 40) ?></a>
                            <?php } else { ?>
                            -
                        <?php } ?>
                        </td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>
            <?php } ?>

        </div>

    </div>

<?php
require_once "includes/footer.php";
