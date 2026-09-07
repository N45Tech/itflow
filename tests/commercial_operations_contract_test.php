<?php

$failures = [];
$root = dirname(__DIR__);

$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$contains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};
$notContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};

$service = $read('functions/commercial_operations.php');
$handler = $read('agent/post/commercial_operations.php');
$quoteHandler = $read('agent/post/quote.php');
$recurringHandler = $read('agent/post/recurring_invoice.php');
$migration = $read('n45/migrations/n45-0027-commercial-operations.php');
$baseline = $read('db.sql');

foreach (['quotes', 'quote_items', 'invoices', 'invoice_items', 'recurring_invoices', 'recurring_invoice_items',
          'products', 'product_stock', 'vendors', 'projects', 'tickets', 'expenses', 'contracts'] as $nativeTable) {
    $contains($nativeTable, $service . $handler, "Commercial operations no longer integrate with native $nativeTable records");
}

$contains('commercialPrepareQuoteDelivery($quote_id', $quoteHandler, 'Quote acceptance and conversion do not prepare the delivery handoff');
$contains('delivery_invoice_id = $new_invoice_id', $quoteHandler, 'Existing quote-to-invoice conversion is not reused by delivery');
$contains('item_product_id = $product_id', $quoteHandler, 'Quote product identity is not carried into invoices');
$contains('item_product_id = $product_id', $recurringHandler, 'Recurring invoice product identity is not preserved');
$contains('delivery_scope_snapshot', $service, 'Approved scope is not captured for delivery');
$contains('delivery_quote_hash', $service, 'Approved quote snapshots are not integrity-addressed');
$contains('commercialCreateProjectFromQuote', $service, 'Quote delivery does not create projects');
$contains('addTasksFromTicketTemplate', $service, 'Quote delivery does not apply pinned project templates');
$contains('commercialCreatePurchaseOrdersForQuote', $service, 'Quote delivery does not create purchase orders from stock shortages');
$contains('INSERT INTO product_stock', $handler, 'Receiving does not post to the existing stock ledger');
$notContains('CREATE TABLE IF NOT EXISTS `commercial_products`', $migration, 'Commercial operations introduced a parallel product catalog');
$notContains('CREATE TABLE IF NOT EXISTS `commercial_vendors`', $migration, 'Commercial operations introduced a parallel vendor catalog');

foreach (['commercial_settings', 'commercial_product_profiles', 'subscription_records', 'billing_review_items',
          'quote_delivery_plans', 'quote_delivery_items', 'purchase_orders', 'purchase_order_items'] as $table) {
    $contains("CREATE TABLE IF NOT EXISTS `$table`", $migration, "Upgrade migration is missing $table");
    $contains("CREATE TABLE `$table`", $baseline, "Fresh-install schema is missing $table");
}

$contains("billing_source_type = 'ticket'", $service, 'Billing review does not include ticket work');
$contains("billing_source_type = 'expense'", $service, 'Billing review does not include client expenses');
$contains("billing_source_type = 'purchase_item'", $service . $handler, 'Fulfilled purchases do not flow through billing review');
$contains('purchase_item_invoice_id = $invoice_id', $handler, 'Billed purchase items are not linked to the invoice');
$contains('contract_rate_standard', $service, 'Billing review is not agreement-rate aware');
$contains('subscription_vendor_id', $service . $handler . $migration, 'Subscription sources do not use native vendors');
$contains('subscription_contract_id', $service . $handler . $migration, 'Subscription cost cannot be allocated to agreements');
$contains('subscription_purchased_quantity', $service, 'Subscription reconciliation does not compare purchased quantity');
$contains('billed_quantity', $service, 'Subscription reconciliation does not compare recurring billing');
$contains('commercialProfitabilityRows', $service, 'Client and agreement profitability reporting is missing');
$contains("'Outside agreement / unallocated'", $service, 'Profitability does not expose unallocated client activity');
$contains('mysqli_begin_transaction', $handler, 'Commercial multi-record writes are not transactional');
$contains('mysqli_rollback', $handler, 'Commercial transactional failures are not rolled back');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Commercial operations contract checks passed." . PHP_EOL;
