<?php

require_once 'includes/inc_all.php';
enforceUserPermission('module_financial');

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$rows = commercialProfitabilityRows($month);
$settings = commercialSettings();
$totals = ['revenue' => 0, 'cost' => 0, 'margin' => 0, 'hours' => 0];
foreach ($rows as $row) {
    $totals['revenue'] += floatval($row['revenue']);
    $totals['cost'] += floatval($row['total_cost']);
    $totals['margin'] += floatval($row['margin']);
    $totals['hours'] += floatval($row['labor_hours']);
}
$margin_percent = $totals['revenue'] > 0 ? ($totals['margin'] / $totals['revenue'] * 100) : null;
?>
<!--
THESIS: Every agreement earns an honest operating margin while unallocated client activity stays visible.
OWN-WORLD: A dark summary rail and a single calm ledger keep financial comparison direct.
STORY: Set the internal labor cost once, choose a month, then identify agreements needing pricing or service review.
FIRST VIEWPORT: Revenue, cost, margin and hours establish the month before the client-and-agreement ledger begins.
FORM: Derived reporting over native invoices, expenses, agreement decisions and vendor-linked subscription snapshots; seed key established-n45-commercial-operations.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
-->
<?php require 'includes/commercial_tabs.php'; ?>

<header class="n45-commercial-heading">
    <div><h1>Client &amp; agreement profitability</h1><p>See billed revenue beside agreement-attributed labor and vendor costs, with anything unallocated called out explicitly.</p></div>
    <form method="get"><label class="visually-hidden" for="profit-month">Reporting month</label><input id="profit-month" class="form-control" type="month" name="month" value="<?= escapeHtml($month) ?>" onchange="this.form.submit()"></form>
</header>

<section class="n45-commercial-summary" aria-label="Profitability summary">
    <div><small>Recognized revenue</small><strong><?= numfmt_format_currency($currency_format, $totals['revenue'], $session_company_currency) ?></strong></div>
    <div><small>Known delivery cost</small><strong><?= numfmt_format_currency($currency_format, $totals['cost'], $session_company_currency) ?></strong></div>
    <div><small>Operating margin</small><strong><?= numfmt_format_currency($currency_format, $totals['margin'], $session_company_currency) ?><?= $margin_percent !== null ? ' · ' . number_format($margin_percent, 1) . '%' : '' ?></strong></div>
    <div><small>Technician hours</small><strong><?= number_format($totals['hours'], 1) ?></strong></div>
</section>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2><?= escapeHtml(date('F Y', strtotime($month . '-01'))) ?> by client and agreement</h2><span class="small text-muted">Issued invoices use accrual timing; drafts are excluded</span></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Client / agreement</th><th class="text-end">Revenue</th><th class="text-end">Labor</th><th class="text-end">Subscriptions</th><th class="text-end">Other expenses</th><th class="text-end">Margin</th></tr></thead><tbody>
    <?php foreach ($rows as $row) { ?>
        <tr>
            <td><strong><?= escapeHtml($row['client_name']) ?></strong><div class="small text-muted"><?= escapeHtml($row['contract_name']) ?> · <?= number_format(floatval($row['labor_hours']), 1) ?> hours</div></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['revenue']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['labor_cost']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['subscription_cost']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money"><?= numfmt_format_currency($currency_format, floatval($row['expenses']), $session_company_currency) ?></td>
            <td class="text-end n45-commercial-money <?= floatval($row['margin']) < 0 ? 'n45-commercial-loss' : 'n45-commercial-ok' ?>"><strong><?= numfmt_format_currency($currency_format, floatval($row['margin']), $session_company_currency) ?></strong><div class="small"><?= $row['margin_percent'] === null ? 'No issued revenue' : number_format(floatval($row['margin_percent']), 1) . '%' ?></div></td>
        </tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="6" class="n45-commercial-empty">No permitted clients are available for this report.</td></tr><?php } ?>
    </tbody></table></div>
</section>

<section class="n45-commercial-panel">
    <div class="n45-commercial-panel-header"><h2>Cost assumptions</h2><span class="small text-muted">Use a fully loaded internal cost—not the client billing rate</span></div>
    <form class="n45-commercial-form-grid" action="post.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="wide"><label class="form-label" for="labor-cost">Internal labor cost per hour</label><input id="labor-cost" class="form-control" name="labor_cost_rate" inputmode="decimal" value="<?= number_format(floatval($settings['commercial_labor_cost_rate']), 2, '.', '') ?>" required><div class="form-text">Include wages or owner time, payroll burden, and normal operating overhead.</div></div>
        <div><label class="form-label" for="billing-increment">Billing increment</label><select id="billing-increment" class="form-select" name="billing_increment_minutes"><?php foreach ([1, 6, 10, 15, 30, 60] as $minutes) { ?><option value="<?= $minutes ?>" <?= intval($settings['commercial_billing_increment_minutes']) === $minutes ? 'selected' : '' ?>><?= $minutes ?> minutes</option><?php } ?></select></div>
        <div class="d-flex align-items-end"><button class="btn btn-primary w-100" name="save_commercial_settings" value="1"><i class="fas fa-save me-2"></i>Save assumptions</button></div>
    </form>
</section>

<div class="alert alert-light border"><i class="fas fa-info-circle me-2 text-secondary"></i>Revenue created through billing review follows its agreement. Labor follows the ticket's immutable agreement decision, and subscription cost follows the agreement selected on its vendor record. Legacy invoice revenue and client expenses remain in an explicit unallocated row. Do not also enter a vendor expense when an active subscription snapshot already represents that cost.</div>

<?php require_once '../includes/footer.php'; ?>
