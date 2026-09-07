<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_sales');

$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id) {
    enforceClientAccess($client_id);
}
$rows = commercialSubscriptionRows($client_id);
$exceptions = array_filter($rows, static fn ($row) => abs(floatval($row['quantity_variance'])) > .001 || abs(floatval($row['managed_variance'])) > .001);
$monthly_cost = array_sum(array_map(static fn ($row) => floatval($row['subscription_purchased_quantity']) * floatval($row['subscription_unit_cost']), $rows));
$monthly_revenue = array_sum(array_map(static fn ($row) => floatval($row['billed_quantity']) * floatval($row['subscription_unit_price']), $rows));
$clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_lead = 0 " . clientScopeSql('client_id') . " ORDER BY client_name");
$products = mysqli_query($mysqli, "SELECT p.product_id, p.product_name, p.product_type,
    COALESCE(cp.commercial_unit_cost, 0) AS unit_cost, COALESCE(cp.commercial_quantity_basis, 'manual') AS quantity_basis
    FROM products p LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = p.product_id
    WHERE p.product_archived_at IS NULL ORDER BY p.product_name");
$vendors = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL ORDER BY vendor_name");
$contracts = mysqli_query($mysqli, "SELECT ct.contract_id, ct.contract_name, c.client_name FROM contracts ct
    INNER JOIN clients c ON c.client_id = ct.contract_client_id WHERE ct.contract_archived_at IS NULL
    AND ct.contract_status = 'Active' " . clientScopeSql('ct.contract_client_id') . " ORDER BY c.client_name, ct.contract_name");
?>
<!--
THESIS: Subscription billing is managed as quantity exceptions with visible source evidence.
OWN-WORLD: Calm N45 operational tables, tabular quantities and amber only where action is required.
STORY: Compare purchased, managed and billed counts, then deliberately synchronize the recurring line.
FIRST VIEWPORT: Exception count and monthly economics precede the compact reconciliation ledger.
FORM: Existing recurring invoice, vendor and product records remain authoritative; seed key established-n45-commercial-operations.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
-->
<?php require 'includes/commercial_tabs.php'; ?>

<header class="n45-commercial-heading">
    <div><h1>Subscription reconciliation</h1><p>Compare vendor quantities, managed users or devices, and the quantities already on recurring invoices.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#subscription-add" aria-expanded="false"><i class="fas fa-plus me-2"></i>Add vendor record</button>
</header>

<section class="n45-commercial-summary" aria-label="Subscription summary">
    <div><small>Active records</small><strong><?= count($rows) ?></strong></div>
    <div><small>Needs review</small><strong><?= count($exceptions) ?></strong></div>
    <div><small>Monthly vendor cost</small><strong><?= numfmt_format_currency($currency_format, $monthly_cost, $session_company_currency) ?></strong></div>
    <div><small>Monthly billed revenue</small><strong><?= numfmt_format_currency($currency_format, $monthly_revenue, $session_company_currency) ?></strong></div>
</section>

<form id="subscription-add" class="collapse n45-commercial-panel" action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="n45-commercial-panel-header"><h2>Add or update a subscription source</h2><span class="small text-muted">The native vendor and external ID form the stable identity</span></div>
    <div class="n45-commercial-form-grid">
        <div><label class="form-label">Vendor</label><select class="form-select select2" name="vendor_id" required><option value="">Choose vendor</option><?php while ($vendor = mysqli_fetch_assoc($vendors)) { ?><option value="<?= intval($vendor['vendor_id']) ?>"><?= escapeHtml($vendor['vendor_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">External ID</label><input class="form-control" name="external_id" maxlength="255" required></div>
        <div><label class="form-label">Client</label><select class="form-select select2" name="client_id" required><option value="">Choose client</option><?php while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Agreement</label><select class="form-select select2" name="contract_id"><option value="0">Outside agreement / unallocated</option><?php while ($contract = mysqli_fetch_assoc($contracts)) { ?><option value="<?= intval($contract['contract_id']) ?>"><?= escapeHtml($contract['client_name'] . ' — ' . $contract['contract_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Product</label><select class="form-select select2" name="product_id" required><option value="">Choose product</option><?php while ($product = mysqli_fetch_assoc($products)) { ?><option value="<?= intval($product['product_id']) ?>"><?= escapeHtml($product['product_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Purchased quantity</label><input class="form-control" name="purchased_quantity" inputmode="decimal" value="1" required></div>
        <div><label class="form-label">Managed quantity <small class="text-muted">manual basis only</small></label><input class="form-control" name="managed_quantity" inputmode="decimal"></div>
        <div><label class="form-label">Unit cost</label><input class="form-control" name="unit_cost" inputmode="decimal" value="0.00" required></div>
        <div><label class="form-label">Unit price</label><input class="form-control" name="unit_price" inputmode="decimal" value="0.00" required></div>
    </div>
    <div class="n45-commercial-actions"><button class="btn btn-primary" name="save_subscription_record" value="1"><i class="fas fa-check me-2"></i>Save record</button></div>
</form>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Quantity comparison</h2><form method="get"><select class="form-select form-select-sm" name="client_id" onchange="this.form.submit()"><option value="0">All permitted clients</option><?php mysqli_data_seek($clients, 0); while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>" <?= $client_id === intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></form></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Client / product</th><th>Provider</th><th class="text-end">Purchased</th><th class="text-end">Managed</th><th class="text-end">Billed</th><th class="text-end">Monthly cost</th><th class="text-end">Monthly revenue</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($rows as $row) {
        $is_exception = abs(floatval($row['quantity_variance'])) > .001 || abs(floatval($row['managed_variance'])) > .001;
    ?>
        <tr class="<?= $is_exception ? 'n45-commercial-exception' : '' ?>">
            <td><strong><?= escapeHtml($row['client_name']) ?></strong><div class="small text-muted"><?= escapeHtml($row['product_name']) ?> · <?= escapeHtml($row['quantity_basis']) ?> basis</div></td>
            <td><strong><?= escapeHtml($row['vendor_name']) ?></strong><div class="small text-muted"><?= escapeHtml($row['subscription_external_id']) ?> · <?= escapeHtml($row['contract_name'] ?: 'Outside agreement') ?></div></td>
            <td class="text-end n45-commercial-qty"><?= number_format(floatval($row['subscription_purchased_quantity']), 2) ?></td>
            <td class="text-end n45-commercial-qty"><?= number_format(floatval($row['effective_managed_quantity']), 2) ?></td>
            <td class="text-end n45-commercial-qty"><?= number_format(floatval($row['billed_quantity']), 2) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['subscription_purchased_quantity']) * floatval($row['subscription_unit_cost']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['billed_quantity']) * floatval($row['subscription_unit_price']), $session_company_currency) ?></td>
            <td><?php if ($is_exception) { ?><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="subscription_id" value="<?= intval($row['subscription_id']) ?>"><button class="btn btn-sm btn-primary confirm-link" name="apply_subscription_quantity" value="1" data-confirm-title="Update recurring billing?" data-confirm-text="The matching recurring invoice line will use the purchased quantity and saved unit price." data-confirm-button="Update quantity">Use <?= number_format(floatval($row['subscription_purchased_quantity']), 2) ?></button></form><?php } else { ?><span class="n45-commercial-ok"><i class="fas fa-check me-1"></i>Aligned</span><?php } ?></td>
        </tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="8" class="n45-commercial-empty">Add a vendor record to begin reconciling subscription quantities.</td></tr><?php } ?>
    </tbody></table></div>
</section>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Product commercial profile</h2><span class="small text-muted">One profile powers reconciliation, quote delivery, purchasing, and margin</span></div>
    <form action="post.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="n45-commercial-form-grid">
            <div class="wide"><label class="form-label">Product</label><select class="form-select select2" name="product_id" required><option value="">Choose product</option><?php mysqli_data_seek($products, 0); while ($product = mysqli_fetch_assoc($products)) { ?><option value="<?= intval($product['product_id']) ?>"><?= escapeHtml($product['product_name']) ?></option><?php } ?></select></div>
            <div><label class="form-label">Quantity basis</label><select class="form-select" name="quantity_basis"><option value="manual">Vendor/manual</option><option value="users">Active contacts</option><option value="devices">Active assets</option></select></div>
            <div><label class="form-label">Unit cost</label><input class="form-control" name="unit_cost" inputmode="decimal" value="0.00"></div>
            <div><label class="form-label">Preferred vendor</label><select class="form-select select2" name="vendor_id"><option value="0">None</option><?php mysqli_data_seek($vendors, 0); while ($vendor = mysqli_fetch_assoc($vendors)) { ?><option value="<?= intval($vendor['vendor_id']) ?>"><?= escapeHtml($vendor['vendor_name']) ?></option><?php } ?></select></div>
            <div><label class="form-label">Vendor SKU</label><input class="form-control" name="vendor_sku" maxlength="200"></div>
            <div><label class="form-label">Reorder level</label><input class="form-control" name="reorder_level" inputmode="decimal" value="0"></div>
            <div class="d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="recurring" value="1"><span class="form-check-label">Recurring service</span></label></div>
        </div>
        <div class="n45-commercial-actions"><button class="btn btn-primary" name="save_commercial_product_profile" value="1"><i class="fas fa-save me-2"></i>Save profile</button></div>
    </form>
</section>

<?php require_once '../includes/footer.php'; ?>
