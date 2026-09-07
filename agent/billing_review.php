<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_sales');

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-t');
}
$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id) {
    enforceClientAccess($client_id);
}
$candidates = commercialBillingCandidates($from, $to, $client_id);
$candidate_revenue = array_sum(array_column($candidates, 'revenue'));
$candidate_cost = array_sum(array_column($candidates, 'cost'));
$scope = $client_id ? "AND bri.billing_client_id = $client_id" : clientScopeSql('bri.billing_client_id');
$approved_rows = commercialDbQuery("SELECT bri.*, c.client_name, ct.contract_name, i.invoice_prefix, i.invoice_number
    FROM billing_review_items bri INNER JOIN clients c ON c.client_id = bri.billing_client_id
    LEFT JOIN contracts ct ON ct.contract_id = bri.billing_contract_id
    LEFT JOIN invoices i ON i.invoice_id = bri.billing_invoice_id
    WHERE 1 = 1 $scope ORDER BY bri.billing_updated_at DESC, bri.billing_review_id DESC", 'Could not load billing decisions');
$decisions = [];
$approved_unbilled = 0.0;
while ($row = mysqli_fetch_assoc($approved_rows)) {
    $decisions[] = $row;
    if ($row['billing_decision'] === 'approved' && intval($row['billing_invoice_id']) === 0) {
        $approved_unbilled += floatval($row['billing_revenue_amount']);
    }
}
$clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL
    AND client_lead = 0 " . clientScopeSql('client_id') . " ORDER BY client_name");
?>
<!--
THESIS: Financial exceptions become a short review queue, not a spreadsheet reconstruction.
OWN-WORLD: N45 spruce, paper and teal with one dense operational table and explicit state labels.
STORY: Find unbilled work, verify price and cost, approve it, then create one traceable draft invoice.
FIRST VIEWPORT: Date/client filters and the queue totals lead directly into candidate review actions.
FORM: Existing N45 operational workspace extended without a separate visual system; seed key established-n45-commercial-operations.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
-->
<?php require 'includes/commercial_tabs.php'; ?>

<header class="n45-commercial-heading">
    <div>
        <h1>Billing review</h1>
        <p>Confirm agreement treatment, rates, expenses, and fulfilled items before anything becomes an invoice.</p>
    </div>
    <button class="btn btn-primary" type="submit" form="billing-filter"><i class="fas fa-filter me-2"></i>Refresh queue</button>
</header>

<form id="billing-filter" class="n45-commercial-panel n45-commercial-form-grid" method="get">
    <div><label class="form-label" for="billing-from">From</label><input id="billing-from" class="form-control" type="date" name="from" value="<?= escapeHtml($from) ?>"></div>
    <div><label class="form-label" for="billing-to">Through</label><input id="billing-to" class="form-control" type="date" name="to" value="<?= escapeHtml($to) ?>"></div>
    <div class="wide"><label class="form-label" for="billing-client">Client</label><select id="billing-client" class="form-select select2" name="client_id"><option value="0">All permitted clients</option><?php while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>" <?= $client_id === intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></div>
</form>

<section class="n45-commercial-summary" aria-label="Billing queue summary">
    <div><small>New candidates</small><strong><?= count($candidates) ?></strong></div>
    <div><small>Candidate revenue</small><strong><?= numfmt_format_currency($currency_format, $candidate_revenue, $session_company_currency) ?></strong></div>
    <div><small>Known cost</small><strong><?= numfmt_format_currency($currency_format, $candidate_cost, $session_company_currency) ?></strong></div>
    <div><small>Approved, not invoiced</small><strong><?= numfmt_format_currency($currency_format, $approved_unbilled, $session_company_currency) ?></strong></div>
</section>

<form class="n45-commercial-panel" action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="return_from" value="<?= escapeHtml($from) ?>">
    <input type="hidden" name="return_to" value="<?= escapeHtml($to) ?>">
    <input type="hidden" name="return_client_id" value="<?= $client_id ?>">
    <div class="n45-commercial-panel-header"><h2>Ready for a decision</h2><span class="text-muted small">Rates and costs remain editable until approval</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th><span class="visually-hidden">Select</span></th><th>Client and source</th><th>Service date</th><th>Agreement</th><th>Quantity</th><th>Unit price</th><th>Cost</th><th class="text-end">Charge</th></tr></thead>
            <tbody>
            <?php foreach ($candidates as $candidate) {
                $key = $candidate['source_type'] . ':' . intval($candidate['source_id']);
            ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="source_keys[]" value="<?= escapeHtml($key) ?>" aria-label="Select <?= escapeHtml($candidate['description']) ?>"></td>
                    <td><strong><?= escapeHtml($candidate['client_name']) ?></strong><div class="small text-muted"><?= escapeHtml($candidate['description']) ?></div></td>
                    <td class="text-nowrap"><?= escapeHtml($candidate['service_date']) ?></td>
                    <td><?php if ($candidate['contract_id']) { ?><strong><?= escapeHtml($candidate['contract_name'] ?: 'Agreement applied') ?></strong><?php if ($candidate['agreement_classification']) { ?><div class="small text-muted"><?= escapeHtml(ucwords(str_replace('_', ' ', $candidate['agreement_classification']))) ?></div><?php } ?><?php } else { ?><span class="text-muted">None</span><?php } ?></td>
                    <td><input class="form-control form-control-sm n45-commercial-row-input" name="quantity[<?= escapeHtml($key) ?>]" inputmode="decimal" value="<?= number_format($candidate['quantity'], 3, '.', '') ?>" aria-label="Quantity"></td>
                    <td><input class="form-control form-control-sm n45-commercial-row-input" name="unit_price[<?= escapeHtml($key) ?>]" inputmode="decimal" value="<?= number_format($candidate['unit_price'], 2, '.', '') ?>" aria-label="Unit price"></td>
                    <td><input class="form-control form-control-sm n45-commercial-row-input" name="cost[<?= escapeHtml($key) ?>]" inputmode="decimal" value="<?= number_format($candidate['cost'], 2, '.', '') ?>" aria-label="Known cost"></td>
                    <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, $candidate['revenue'], $session_company_currency) ?></td>
                </tr>
            <?php } ?>
            <?php if (!$candidates) { ?><tr><td colspan="8" class="n45-commercial-empty"><i class="fas fa-check-circle me-2 n45-commercial-ok"></i>No new billing candidates in this period.</td></tr><?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($candidates) { ?>
    <div class="n45-commercial-actions">
        <label class="me-auto"><span class="visually-hidden">Review note</span><input class="form-control" name="billing_note" maxlength="1000" placeholder="Optional review note"></label>
        <button class="btn btn-outline-secondary" name="review_billing_candidates" value="hold"><i class="fas fa-pause me-2"></i>Hold</button>
        <button class="btn btn-outline-secondary" name="review_billing_candidates" value="excluded"><i class="fas fa-ban me-2"></i>Exclude</button>
        <button class="btn btn-primary" name="review_billing_candidates" value="approved"><i class="fas fa-check me-2"></i>Approve selected</button>
    </div>
    <?php } ?>
</form>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Reviewed work</h2><span class="text-muted small">Approved items stay editable as draft invoice lines</span></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Client</th><th>Item</th><th>Decision</th><th>Invoice</th><th class="text-end">Revenue</th><th class="text-end">Cost</th></tr></thead><tbody>
    <?php foreach ($decisions as $item) { ?>
        <tr>
            <td><?= escapeHtml($item['client_name']) ?></td>
            <td><?= escapeHtml($item['billing_description']) ?><div class="small text-muted"><?= escapeHtml($item['billing_service_date']) ?><?= $item['contract_name'] ? ' · ' . escapeHtml($item['contract_name']) : '' ?></div></td>
            <td><span class="badge <?= $item['billing_decision'] === 'approved' ? 'bg-success' : ($item['billing_decision'] === 'hold' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= escapeHtml(ucfirst($item['billing_decision'])) ?></span></td>
            <td><?php if ($item['billing_invoice_id']) { ?><a href="invoice.php?invoice_id=<?= intval($item['billing_invoice_id']) ?>"><?= escapeHtml($item['invoice_prefix']) . intval($item['invoice_number']) ?></a><?php } else { ?><span class="text-muted">Not created</span><?php } ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($item['billing_revenue_amount']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($item['billing_cost_amount']), $session_company_currency) ?></td>
        </tr>
    <?php } ?>
    <?php if (!$decisions) { ?><tr><td colspan="6" class="n45-commercial-empty">No decisions have been recorded yet.</td></tr><?php } ?>
    </tbody></table></div>
    <?php if ($approved_unbilled > 0) { ?>
    <form class="n45-commercial-actions" action="post.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <label class="me-auto">Client <select class="form-select" name="client_id" required><option value="">Choose approved client</option><?php mysqli_data_seek($clients, 0); while ($client = mysqli_fetch_assoc($clients)) { ?><option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option><?php } ?></select></label>
        <button class="btn btn-primary confirm-link" name="create_review_invoice" value="1" data-confirm-title="Create a draft invoice?" data-confirm-text="All approved, uninvoiced items for this client will be added." data-confirm-button="Create draft"><i class="fas fa-file-invoice-dollar me-2"></i>Create draft invoice</button>
    </form>
    <?php } ?>
</section>

<?php require_once '../includes/footer.php'; ?>
