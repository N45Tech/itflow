<?php
$commercial_page = basename($_SERVER['PHP_SELF']);
$commercial_tabs = [];
if (lookupUserPermission('module_sales') >= 1) {
    $commercial_tabs['billing_review.php'] = ['Billing review', 'fa-clipboard-check'];
    $commercial_tabs['subscriptions.php'] = ['Subscriptions', 'fa-sync-alt'];
    $commercial_tabs['purchasing.php'] = ['Purchasing', 'fa-truck-loading'];
}
if (lookupUserPermission('module_financial') >= 1) {
    $commercial_tabs['profitability.php'] = ['Profitability', 'fa-chart-line'];
}
?>
<link rel="stylesheet" href="css/commercial.css?v=<?= filemtime(__DIR__ . '/../css/commercial.css') ?>">
<nav class="n45-commercial-tabs d-print-none" aria-label="Commercial operations">
    <?php foreach ($commercial_tabs as $path => [$label, $icon]) { ?>
        <a href="<?= $path ?>" <?= $commercial_page === $path ? 'aria-current="page"' : '' ?>>
            <i class="fas fa-fw <?= $icon ?> me-1" aria-hidden="true"></i><?= $label ?>
        </a>
    <?php } ?>
</nav>
