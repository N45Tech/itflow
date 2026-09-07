<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_sales', 2);
enforceUserPermission('module_support', 2);

$quote_id = intval($_GET['quote_id'] ?? 0);
$quote = mysqli_fetch_assoc(commercialDbQuery("SELECT q.*, c.client_name FROM quotes q INNER JOIN clients c ON c.client_id = q.quote_client_id
    WHERE q.quote_id = $quote_id " . clientScopeSql('q.quote_client_id') . " LIMIT 1", 'Could not load quote delivery'));
if (!$quote || !in_array($quote['quote_status'], ['Accepted', 'Invoiced'], true)) {
    echo "<div class='alert alert-warning'>Only an accepted quote can be prepared for delivery.</div>";
    require_once '../includes/footer.php';
    exit;
}
enforceClientAccess(intval($quote['quote_client_id']));
$plan = commercialPrepareQuoteDelivery($quote_id, $session_user_id);
$items_sql = commercialDbQuery("SELECT qdi.*, p.product_type, cp.commercial_recurring,
    v.vendor_id AS active_vendor_id, v.vendor_name,
    COALESCE((SELECT SUM(stock_qty) FROM product_stock WHERE stock_product_id = qdi.delivery_item_product_id), 0) AS on_hand
    FROM quote_delivery_items qdi LEFT JOIN products p ON p.product_id = qdi.delivery_item_product_id
    LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = qdi.delivery_item_product_id
    LEFT JOIN vendors v ON v.vendor_id = cp.commercial_preferred_vendor_id AND v.vendor_archived_at IS NULL
    WHERE qdi.delivery_item_quote_id = $quote_id ORDER BY qdi.delivery_item_id", 'Could not load delivery scope');
$items = [];
$recurring_count = 0;
$purchase_count = 0;
$missing_vendor = 0;
while ($item = mysqli_fetch_assoc($items_sql)) {
    $items[] = $item;
    if (intval($item['commercial_recurring'])) {
        $recurring_count++;
    }
    if ($item['product_type'] === 'product' && floatval($item['delivery_item_quantity']) > floatval($item['on_hand'])) {
        $purchase_count++;
        if (!intval($item['active_vendor_id'])) {
            $missing_vendor++;
        }
    }
}
$templates = mysqli_query($mysqli, "SELECT project_template_id, project_template_name FROM project_templates WHERE project_template_archived_at IS NULL ORDER BY project_template_name");
$users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
$completed = $plan && $plan['delivery_status'] === 'completed';
$linked_invoice_id = intval($plan['delivery_invoice_id'] ?? 0);
?>
<!--
THESIS: An accepted quote becomes one delivery package instead of five disconnected administrative tasks.
OWN-WORLD: N45 spruce, paper and teal organize immutable scope, ownership and downstream outputs.
STORY: Confirm the owner, template and due date, then create the project, billing, recurring service and purchasing records together.
FIRST VIEWPORT: Quote context and output counts lead directly into one delivery plan with a single primary action.
FORM: Existing quote, project, ticket, invoice, recurring invoice, vendor and stock records remain authoritative; seed key established-n45-commercial-operations.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
-->
<link rel="stylesheet" href="css/commercial.css?v=<?= filemtime(__DIR__ . '/css/commercial.css') ?>">
<ol class="breadcrumb d-print-none"><li class="breadcrumb-item"><a href="quotes.php">Quotes</a></li><li class="breadcrumb-item"><a href="quote.php?quote_id=<?= $quote_id ?>"><?= escapeHtml($quote['quote_prefix']) . intval($quote['quote_number']) ?></a></li><li class="breadcrumb-item active">Delivery</li></ol>

<header class="n45-commercial-heading">
    <div><h1>Turn approval into delivery</h1><p><?= escapeHtml($quote['client_name']) ?> approved <?= escapeHtml($quote['quote_prefix']) . intval($quote['quote_number']) ?>. Confirm the ownership and outputs once; the approved scope stays attached throughout delivery.</p></div>
    <?php if ($completed) { ?><a class="btn btn-primary" href="project.php?project_id=<?= intval($plan['delivery_project_id']) ?>"><i class="fas fa-project-diagram me-2"></i>Open project</a><?php } ?>
</header>

<section class="n45-commercial-summary" aria-label="Delivery summary">
    <div><small>Approved value</small><strong><?= numfmt_format_currency($currency_format, floatval($quote['quote_amount']), $quote['quote_currency_code']) ?></strong></div>
    <div><small>Scope lines</small><strong><?= count($items) ?></strong></div>
    <div><small>Recurring services</small><strong><?= $recurring_count ?></strong></div>
    <div><small>Needs purchasing</small><strong><?= $purchase_count ?></strong></div>
</section>

<?php if ($completed) { ?>
<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Delivery package created</h2><span class="badge bg-success">Complete</span></div>
    <div class="p-3 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="project.php?project_id=<?= intval($plan['delivery_project_id']) ?>">Project</a>
        <?php if ($plan['delivery_invoice_id']) { ?><a class="btn btn-outline-primary" href="invoice.php?invoice_id=<?= intval($plan['delivery_invoice_id']) ?>">Draft invoice</a><?php } ?>
        <?php if ($plan['delivery_recurring_invoice_id']) { ?><a class="btn btn-outline-primary" href="recurring_invoice.php?recurring_invoice_id=<?= intval($plan['delivery_recurring_invoice_id']) ?>">Recurring services</a><?php } ?>
        <a class="btn btn-outline-primary" href="purchasing.php">Purchasing</a>
    </div>
</section>
<?php } else { ?>
<form class="n45-commercial-panel" action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="quote_id" value="<?= $quote_id ?>">
    <div class="n45-commercial-panel-header"><h2>Delivery plan</h2><span class="small text-muted">One transaction creates every selected output</span></div>
    <div class="n45-commercial-form-grid">
        <div class="wide"><label class="form-label">Project template</label><select class="form-select select2" name="project_template_id"><option value="0">Blank project</option><?php while ($template = mysqli_fetch_assoc($templates)) { ?><option value="<?= intval($template['project_template_id']) ?>"><?= escapeHtml($template['project_template_name']) ?></option><?php } ?></select><div class="form-text">Published ticket/runbook versions are pinned into the new project.</div></div>
        <div><label class="form-label">Owner</label><select class="form-select select2" name="project_manager_id" required><option value="">Choose owner</option><?php while ($user = mysqli_fetch_assoc($users)) { ?><option value="<?= intval($user['user_id']) ?>" <?= intval($user['user_id']) === $session_user_id ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option><?php } ?></select></div>
        <div><label class="form-label">Target completion</label><input class="form-control" type="date" name="due_date" value="<?= escapeHtml(date('Y-m-d', strtotime('+30 days'))) ?>" required></div>
        <div class="wide"><label class="form-label">Approved scope</label><textarea class="form-control" rows="4" readonly><?= escapeHtml($plan['delivery_scope_snapshot']) ?></textarea><div class="form-text">This snapshot cannot be changed by later quote edits.</div></div>
        <div class="wide">
            <?php if ($linked_invoice_id) { ?>
                <div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-check-circle text-success" aria-hidden="true"></i><span>Use <a href="invoice.php?invoice_id=<?= $linked_invoice_id ?>">the invoice already created from this quote</a></span></div>
            <?php } else { ?>
                <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="create_invoice" value="1" <?= $quote['quote_status'] === 'Accepted' ? 'checked' : '' ?>><span class="form-check-label">Create a draft invoice from the approved quote</span></label>
                <?php if ($quote['quote_status'] === 'Invoiced') { ?><div class="form-text mb-2">This older quote is already marked invoiced, but no invoice link is available. Leave this off unless a replacement invoice is needed.</div><?php } ?>
            <?php } ?>
            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="create_recurring" value="1" <?= $recurring_count ? 'checked' : 'disabled' ?>><span class="form-check-label">Create recurring invoice lines for <?= $recurring_count ?> profiled service<?= $recurring_count === 1 ? '' : 's' ?></span></label>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="create_purchasing" value="1" <?= $purchase_count && !$missing_vendor ? 'checked' : ($purchase_count ? '' : 'disabled') ?>><span class="form-check-label">Create purchase orders for <?= $purchase_count ?> stock shortage<?= $purchase_count === 1 ? '' : 's' ?></span></label>
            <?php if ($missing_vendor) { ?><div class="alert alert-warning mt-2 mb-0"><i class="fas fa-exclamation-triangle me-2"></i><?= $missing_vendor ?> product<?= $missing_vendor === 1 ? '' : 's' ?> need a preferred vendor before purchasing can be automated. Configure them under Subscriptions.</div><?php } ?>
        </div>
    </div>
    <div class="n45-commercial-actions"><button class="btn btn-primary confirm-link" name="complete_quote_delivery" value="1" data-confirm-title="Create this delivery package?" data-confirm-text="The project, selected billing records, and purchase orders will be created together." data-confirm-button="Create delivery"><i class="fas fa-play me-2"></i>Create delivery package</button></div>
</form>
<?php } ?>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Approved line-item snapshot</h2><span class="small text-muted">Product links drive recurring services and purchasing</span></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Use</th><th class="text-end">Quantity</th><th class="text-end">Price</th><th class="text-end">Known cost</th></tr></thead><tbody>
    <?php foreach ($items as $item) { ?>
        <tr><td><strong><?= escapeHtml($item['delivery_item_name']) ?></strong><div class="small text-muted"><?= escapeHtml($item['delivery_item_description']) ?></div></td><td><?php if (intval($item['commercial_recurring'])) { ?><span class="badge bg-info">Recurring</span><?php } elseif ($item['product_type'] === 'product') { ?><span class="badge bg-secondary">Stock<?= floatval($item['delivery_item_quantity']) > floatval($item['on_hand']) ? ' · order' : '' ?></span><?php } else { ?><span class="text-muted">Project scope</span><?php } ?></td><td class="text-end n45-commercial-qty"><?= number_format(floatval($item['delivery_item_quantity']), 2) ?></td><td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($item['delivery_item_unit_price']), $quote['quote_currency_code']) ?></td><td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($item['delivery_item_unit_cost']), $quote['quote_currency_code']) ?></td></tr>
    <?php } ?>
    </tbody></table></div>
</section>

<?php require_once '../includes/footer.php'; ?>
