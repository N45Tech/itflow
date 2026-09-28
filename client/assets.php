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

<?php if (mysqli_num_rows($assets_sql) == 0) { ?>
    <?= portalEmptyState('There are no assets on this account yet.') ?>
<?php } else { ?>
    <div class="n45-portal-assets">
        <?php while ($asset = mysqli_fetch_assoc($assets_sql)) {
            $name = trim((string) $asset['asset_name']) ?: 'Unnamed asset';
            $make_model = trim(implode(' ', array_filter([$asset['asset_make'], $asset['asset_model']])));
            $uri = escapeUrl($asset['asset_uri_client']);
        ?>
            <article class="n45-portal-asset-card">
                <div class="n45-portal-asset-heading">
                    <div>
                        <span class="n45-portal-asset-type"><?= escapeHtml($asset['asset_type'] ?: 'Asset') ?></span>
                        <h2><?= escapeHtml($name) ?></h2>
                        <?php if ($asset['asset_description']) { ?><p><?= escapeHtml($asset['asset_description']) ?></p><?php } ?>
                    </div>
                    <?php if ($asset['asset_status']) { ?><span class="n45-portal-asset-status"><?= escapeHtml($asset['asset_status']) ?></span><?php } ?>
                </div>
                <dl class="n45-portal-asset-details">
                    <?php if ($make_model) { ?><div><dt>Make &amp; model</dt><dd><?= escapeHtml($make_model) ?></dd></div><?php } ?>
                    <?php if ($asset['asset_serial']) { ?><div><dt>Serial number</dt><dd><?= escapeHtml($asset['asset_serial']) ?></dd></div><?php } ?>
                    <?php if ($asset['contact_name']) { ?><div><dt>Assigned to</dt><dd><?= escapeHtml($asset['contact_name']) ?></dd></div><?php } ?>
                    <?php if ($asset['asset_purchase_date']) { ?><div><dt>Purchased</dt><dd><?= escapeHtml($asset['asset_purchase_date']) ?></dd></div><?php } ?>
                    <?php if ($asset['asset_warranty_expire']) { ?><div><dt>Warranty expires</dt><dd><?= escapeHtml($asset['asset_warranty_expire']) ?></dd></div><?php } ?>
                </dl>
                <?php if ($uri) { ?><a class="n45-portal-asset-link" href="<?= $uri ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt" aria-hidden="true"></i> Open asset link<span class="sr-only"> for <?= escapeHtml($name) ?></span></a><?php } ?>
            </article>
        <?php } ?>
    </div>
<?php } ?>

<?php
require_once "includes/footer.php";
