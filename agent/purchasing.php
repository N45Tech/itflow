<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_sales');

$status = $_GET['status'] ?? 'open';
$where = $status === 'all' ? '1 = 1' : "po.purchase_order_status IN ('draft','ordered','partially_received')";
$scope = clientScopeSql('po.purchase_order_client_id');
$orders_sql = commercialDbQuery("SELECT po.*, v.vendor_name, c.client_name, p.project_prefix, p.project_number, q.quote_prefix, q.quote_number,
    COUNT(poi.purchase_item_id) AS item_count,
    COALESCE(SUM(poi.purchase_item_quantity_ordered * poi.purchase_item_unit_cost), 0) AS order_total,
    COALESCE(SUM(poi.purchase_item_quantity_ordered), 0) AS qty_ordered,
    COALESCE(SUM(poi.purchase_item_quantity_received), 0) AS qty_received
    FROM purchase_orders po INNER JOIN vendors v ON v.vendor_id = po.purchase_order_vendor_id
    LEFT JOIN clients c ON c.client_id = po.purchase_order_client_id
    LEFT JOIN projects p ON p.project_id = po.purchase_order_project_id
    LEFT JOIN quotes q ON q.quote_id = po.purchase_order_quote_id
    LEFT JOIN purchase_order_items poi ON poi.purchase_item_order_id = po.purchase_order_id
    WHERE $where $scope GROUP BY po.purchase_order_id ORDER BY po.purchase_order_created_at DESC", 'Could not load purchase orders');
$orders = [];
while ($order = mysqli_fetch_assoc($orders_sql)) {
    $orders[] = $order;
}
$open_total = array_sum(array_map(static fn ($order) => floatval($order['order_total']), $orders));
$clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_lead = 0 " . clientScopeSql('client_id') . " ORDER BY client_name");
$vendors = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL ORDER BY vendor_name");
$products = commercialDbQuery("SELECT p.product_id, p.product_name, p.product_price, COALESCE(cp.commercial_unit_cost, 0) AS unit_cost,
    COALESCE(cp.commercial_preferred_vendor_id, 0) AS vendor_id FROM products p
    LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = p.product_id
    WHERE p.product_type = 'product' AND p.product_archived_at IS NULL ORDER BY p.product_name", 'Could not load purchasable products');
?>
<!--
THESIS: Purchasing is a short fulfillment lane attached to products, quotes and projects.
OWN-WORLD: Existing N45 tables and controls with clear ordered, partially received and received states.
STORY: Create or inherit an order, send it, receive exact quantities into native stock, and expose fulfilled items to billing.
FIRST VIEWPORT: Open commitment and fulfillment totals lead into the order queue.
FORM: Native product stock remains authoritative; custom records only add vendor and fulfillment provenance; seed key established-n45-commercial-operations.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
-->
<?php require 'includes/commercial_tabs.php'; ?>

<header class="n45-commercial-heading">
    <div><h1>Purchasing &amp; fulfillment</h1><p>Track only what N45 needs between an approved quote, a vendor order, receiving stock, and customer billing.</p></div>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#purchase-add"><i class="fas fa-plus me-2"></i>New purchase order</button>
</header>

<section class="n45-commercial-summary" aria-label="Purchasing summary">
    <div><small>Orders shown</small><strong><?= count($orders) ?></strong></div>
    <div><small>Committed cost</small><strong><?= numfmt_format_currency($currency_format, $open_total, $session_company_currency) ?></strong></div>
    <div><small>Units ordered</small><strong><?= number_format(array_sum(array_column($orders, 'qty_ordered')), 0) ?></strong></div>
    <div><small>Units received</small><strong><?= number_format(array_sum(array_column($orders, 'qty_received')), 0) ?></strong></div>
</section>

<form id="purchase-add" class="collapse n45-commercial-panel" action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="n45-commercial-panel-header"><h2>Create a lightweight purchase order</h2><span class="small text-muted">Quote delivery creates these automatically when preferred vendors are configured</span></div>
    <div class="n45-commercial-form-grid">
        <div><label class="form-label">Vendor</label><select class="form-select select2" name="vendor_id" required><option value="">Choose vendor</option><?php while ($vendor = mysqli_fetch_assoc($vendors)) { ?><option value="<?= intval($vendor['vendor_id']) ?>"><?= escapeHtml($vendor['vendor_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Client</label><select class="form-select select2" name="client_id"><option value="0">Internal stock</option><?php while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></div>
        <div class="wide"><label class="form-label">Product</label><select class="form-select select2" name="product_id" required><option value="">Choose product</option><?php while ($product = mysqli_fetch_assoc($products)) { ?><option value="<?= intval($product['product_id']) ?>"><?= escapeHtml($product['product_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Quantity</label><input class="form-control" name="quantity" inputmode="decimal" value="1" required></div>
        <div><label class="form-label">Unit cost</label><input class="form-control" name="unit_cost" inputmode="decimal" value="0.00" required></div>
        <div><label class="form-label">Customer price</label><input class="form-control" name="unit_price" inputmode="decimal" value="0.00" required></div>
        <div><label class="form-label">Expected</label><input class="form-control" type="date" name="expected_at"></div>
        <div class="wide"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="1000" placeholder="Vendor confirmation, shipping method, or delivery instructions"></div>
    </div>
    <div class="n45-commercial-actions"><button class="btn btn-primary" name="create_purchase_order" value="1"><i class="fas fa-check me-2"></i>Create order</button></div>
</form>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Fulfillment queue</h2><form method="get"><select class="form-select form-select-sm" name="status" onchange="this.form.submit()"><option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open orders</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All orders</option></select></form></div>
    <?php foreach ($orders as $order) {
        $order_id = intval($order['purchase_order_id']);
        $items = commercialDbQuery("SELECT poi.*, p.product_name FROM purchase_order_items poi INNER JOIN products p ON p.product_id = poi.purchase_item_product_id WHERE poi.purchase_item_order_id = $order_id ORDER BY poi.purchase_item_id", 'Could not load purchase items');
    ?>
    <div class="border-bottom">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between p-3">
            <div><strong><?= escapeHtml($order['purchase_order_number']) ?> · <?= escapeHtml($order['vendor_name']) ?></strong><div class="small text-muted"><?= escapeHtml($order['client_name'] ?: 'Internal stock') ?><?= $order['quote_number'] ? ' · ' . escapeHtml($order['quote_prefix']) . intval($order['quote_number']) : '' ?><?= $order['project_number'] ? ' · ' . escapeHtml($order['project_prefix']) . intval($order['project_number']) : '' ?></div></div>
            <div class="d-flex gap-2 align-items-center"><span class="badge <?= $order['purchase_order_status'] === 'received' ? 'bg-success' : ($order['purchase_order_status'] === 'partially_received' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= escapeHtml(ucwords(str_replace('_', ' ', $order['purchase_order_status']))) ?></span><strong class="n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($order['order_total']), $session_company_currency) ?></strong><?php if ($order['purchase_order_status'] === 'draft') { ?><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="purchase_order_id" value="<?= $order_id ?>"><button class="btn btn-sm btn-outline-primary" name="submit_purchase_order" value="1">Mark ordered</button></form><?php } ?></div>
        </div>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Unit cost</th><th>Receive</th></tr></thead><tbody>
        <?php while ($item = mysqli_fetch_assoc($items)) { $remaining = max(0, floatval($item['purchase_item_quantity_ordered']) - floatval($item['purchase_item_quantity_received'])); ?>
            <tr><td><?= escapeHtml($item['product_name']) ?><div class="small text-muted"><?= escapeHtml($item['purchase_item_description']) ?></div></td><td class="text-end n45-commercial-qty"><?= number_format(floatval($item['purchase_item_quantity_ordered']), 2) ?></td><td class="text-end n45-commercial-qty"><?= number_format(floatval($item['purchase_item_quantity_received']), 2) ?></td><td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($item['purchase_item_unit_cost']), $session_company_currency) ?></td><td><?php if ($remaining > 0 && $order['purchase_order_status'] !== 'draft') { ?><form class="d-flex gap-2" action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="purchase_item_id" value="<?= intval($item['purchase_item_id']) ?>"><input class="form-control form-control-sm n45-commercial-row-input" name="receive_quantity" inputmode="decimal" value="<?= number_format($remaining, 2, '.', '') ?>" aria-label="Quantity received"><button class="btn btn-sm btn-primary" name="receive_purchase_item" value="1">Receive</button></form><?php } else { ?><span class="text-muted"><?= $remaining > 0 ? 'Mark ordered first' : 'Complete' ?></span><?php } ?></td></tr>
        <?php } ?>
        </tbody></table></div>
    </div>
    <?php } ?>
    <?php if (!$orders) { ?><div class="n45-commercial-empty">There are no purchase orders in this view.</div><?php } ?>
</section>

<?php require_once '../includes/footer.php'; ?>
