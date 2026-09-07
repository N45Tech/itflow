<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

function commercialReturnToBillingReview(): void
{
    $query = http_build_query([
        'from' => $_POST['return_from'] ?? date('Y-m-01'),
        'to' => $_POST['return_to'] ?? date('Y-m-t'),
        'client_id' => intval($_POST['return_client_id'] ?? 0),
    ]);
    redirect("billing_review.php?$query");
}

if (isset($_POST['review_billing_candidates'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $decision = (string) $_POST['review_billing_candidates'];
    if (!in_array($decision, ['approved', 'hold', 'excluded'], true)) {
        flashAlert('Choose a valid billing decision', 'error');
        commercialReturnToBillingReview();
    }
    $keys = array_values(array_unique(array_filter((array) ($_POST['source_keys'] ?? []), 'is_string')));
    if (!$keys) {
        flashAlert('Select at least one billing item', 'error');
        commercialReturnToBillingReview();
    }
    $note = mysqli_real_escape_string($mysqli, substr(trim((string) ($_POST['billing_note'] ?? '')), 0, 1000));
    $reviewed = 0;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin billing review');
        }
        foreach ($keys as $key) {
            if (!preg_match('/^(ticket|expense|purchase_item):(\d+)$/', $key, $matches)) {
                throw new RuntimeException('A selected billing source is invalid');
            }
            $candidate = commercialBillingCandidate($matches[1], intval($matches[2]));
            if (!$candidate) {
                throw new RuntimeException('A selected billing source changed or was already reviewed');
            }
            enforceClientAccess(intval($candidate['client_id']));
            $quantity = max(0, floatval($_POST['quantity'][$key] ?? $candidate['quantity']));
            $unit_price = max(0, floatval($_POST['unit_price'][$key] ?? $candidate['unit_price']));
            $cost = max(0, floatval($_POST['cost'][$key] ?? $candidate['cost']));
            $revenue = round($quantity * $unit_price, 2);
            $source_type = mysqli_real_escape_string($mysqli, $candidate['source_type']);
            $source_id = intval($candidate['source_id']);
            $client_id = intval($candidate['client_id']);
            $contract_id = intval($candidate['contract_id']);
            $category_id = intval($candidate['category_id']);
            $description = mysqli_real_escape_string($mysqli, substr($candidate['description'], 0, 500));
            $service_date = mysqli_real_escape_string($mysqli, $candidate['service_date']);
            commercialDbQuery("INSERT INTO billing_review_items SET billing_source_type = '$source_type',
                billing_source_id = $source_id, billing_client_id = $client_id, billing_contract_id = $contract_id,
                billing_category_id = $category_id, billing_description = '$description', billing_service_date = '$service_date',
                billing_quantity = $quantity, billing_unit_price = $unit_price, billing_revenue_amount = $revenue,
                billing_cost_amount = $cost, billing_decision = '$decision', billing_note = '$note',
                billing_reviewed_by = $session_user_id, billing_reviewed_at = NOW()
                ON DUPLICATE KEY UPDATE billing_contract_id = VALUES(billing_contract_id),
                billing_category_id = VALUES(billing_category_id), billing_description = VALUES(billing_description),
                billing_service_date = VALUES(billing_service_date), billing_quantity = VALUES(billing_quantity),
                billing_unit_price = VALUES(billing_unit_price), billing_revenue_amount = VALUES(billing_revenue_amount),
                billing_cost_amount = VALUES(billing_cost_amount), billing_decision = VALUES(billing_decision),
                billing_note = VALUES(billing_note), billing_reviewed_by = VALUES(billing_reviewed_by),
                billing_reviewed_at = VALUES(billing_reviewed_at)", 'Could not record billing decision');
            $reviewed++;
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit billing review');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log('Billing review failed: ' . $exception->getMessage());
        flashAlert('The billing review was not saved because one or more sources changed. Refresh and try again.', 'error');
        commercialReturnToBillingReview();
    }
    logAudit('Billing Review', 'Edit', "$session_name marked $reviewed item(s) $decision");
    flashAlert("$reviewed billing item(s) marked <strong>$decision</strong>");
    commercialReturnToBillingReview();
}

if (isset($_POST['create_review_invoice'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $client_id = intval($_POST['client_id'] ?? 0);
    enforceClientAccess($client_id);
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin invoice creation');
        }
        $result = commercialDbQuery("SELECT * FROM billing_review_items WHERE billing_client_id = $client_id
            AND billing_decision = 'approved' AND billing_invoice_id = 0 ORDER BY billing_service_date, billing_review_id FOR UPDATE", 'Could not lock approved billing items');
        $review_ids = [];
        $invoice_items = [];
        $category_id = 0;
        while ($item = mysqli_fetch_assoc($result)) {
            $review_ids[] = intval($item['billing_review_id']);
            $category_id = $category_id ?: intval($item['billing_category_id']);
            $product_id = 0;
            if ($item['billing_source_type'] === 'purchase_item') {
                $product_id = intval(getFieldById('purchase_order_items', intval($item['billing_source_id']), 'purchase_item_product_id'));
            }
            $invoice_items[] = [
                'name' => $item['billing_description'],
                'description' => $item['billing_note'],
                'quantity' => floatval($item['billing_quantity']),
                'unit_price' => floatval($item['billing_unit_price']),
                'amount' => floatval($item['billing_revenue_amount']),
                'product_id' => $product_id,
            ];
        }
        if (!$invoice_items) {
            throw new RuntimeException('There are no approved items waiting for this client');
        }
        $client_name = (string) getFieldById('clients', $client_id, 'client_name');
        $invoice_id = commercialCreateInvoice($client_id, "Reviewed service and expenses — $client_name", $invoice_items, $category_id);
        $id_list = implode(',', $review_ids);
        commercialDbQuery("UPDATE billing_review_items SET billing_invoice_id = $invoice_id, billing_decision = 'invoiced'
            WHERE billing_review_id IN ($id_list)", 'Could not link billing review to invoice');
        commercialDbQuery("UPDATE tickets t INNER JOIN billing_review_items bri ON bri.billing_source_type = 'ticket'
            AND bri.billing_source_id = t.ticket_id SET t.ticket_invoice_id = $invoice_id
            WHERE bri.billing_review_id IN ($id_list) AND t.ticket_invoice_id = 0", 'Could not link billed tickets');
        commercialDbQuery("UPDATE purchase_order_items poi INNER JOIN billing_review_items bri ON bri.billing_source_type = 'purchase_item'
            AND bri.billing_source_id = poi.purchase_item_id SET poi.purchase_item_invoice_id = $invoice_id
            WHERE bri.billing_review_id IN ($id_list) AND poi.purchase_item_invoice_id = 0", 'Could not link billed purchase items');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit reviewed invoice');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log('Reviewed invoice failed: ' . $exception->getMessage());
        flashAlert('The draft invoice was not created. Refresh the review and try again.', 'error');
        redirect('billing_review.php');
    }
    logAudit('Invoice', 'Create', "$session_name created reviewed draft invoice", $client_id, $invoice_id);
    triggerCustomAction('invoice_create', $invoice_id);
    flashAlert('Draft invoice created from approved billing items');
    redirect("invoice.php?invoice_id=$invoice_id");
}

if (isset($_POST['save_commercial_settings'])) {
    validateCSRFToken();
    enforceUserPermission('module_financial', 2);
    $labor_cost = max(0, floatval($_POST['labor_cost_rate'] ?? 0));
    $increment = intval($_POST['billing_increment_minutes'] ?? 15);
    if (!in_array($increment, [1, 6, 10, 15, 30, 60], true)) {
        $increment = 15;
    }
    commercialDbQuery("UPDATE commercial_settings SET commercial_labor_cost_rate = $labor_cost,
        commercial_billing_increment_minutes = $increment, commercial_updated_by = $session_user_id
        WHERE commercial_settings_id = 1", 'Could not save cost assumptions');
    logAudit('Commercial Settings', 'Edit', "$session_name updated labor cost and billing increment");
    flashAlert('Commercial cost assumptions updated');
    redirect('profitability.php');
}

if (isset($_POST['save_commercial_product_profile'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $product_id = intval($_POST['product_id'] ?? 0);
    $product = mysqli_fetch_assoc(commercialDbQuery("SELECT product_id FROM products WHERE product_id = $product_id AND product_archived_at IS NULL", 'Could not validate product'));
    if (!$product) {
        flashAlert('Choose an active product', 'error');
        redirect('subscriptions.php');
    }
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    if ($vendor_id && !mysqli_fetch_assoc(commercialDbQuery("SELECT vendor_id FROM vendors WHERE vendor_id = $vendor_id AND vendor_archived_at IS NULL", 'Could not validate vendor'))) {
        flashAlert('Choose an active vendor', 'error');
        redirect('subscriptions.php');
    }
    $unit_cost = max(0, floatval($_POST['unit_cost'] ?? 0));
    $reorder = max(0, floatval($_POST['reorder_level'] ?? 0));
    $recurring = isset($_POST['recurring']) ? 1 : 0;
    $basis = (string) ($_POST['quantity_basis'] ?? 'manual');
    if (!in_array($basis, ['manual', 'users', 'devices'], true)) {
        $basis = 'manual';
    }
    $basis_sql = mysqli_real_escape_string($mysqli, $basis);
    $sku = mysqli_real_escape_string($mysqli, substr(trim((string) ($_POST['vendor_sku'] ?? '')), 0, 200));
    commercialDbQuery("INSERT INTO commercial_product_profiles SET commercial_product_id = $product_id,
        commercial_unit_cost = $unit_cost, commercial_preferred_vendor_id = $vendor_id,
        commercial_vendor_sku = '$sku', commercial_reorder_level = $reorder,
        commercial_recurring = $recurring, commercial_quantity_basis = '$basis_sql', commercial_updated_by = $session_user_id
        ON DUPLICATE KEY UPDATE commercial_unit_cost = VALUES(commercial_unit_cost),
        commercial_preferred_vendor_id = VALUES(commercial_preferred_vendor_id), commercial_vendor_sku = VALUES(commercial_vendor_sku),
        commercial_reorder_level = VALUES(commercial_reorder_level), commercial_recurring = VALUES(commercial_recurring),
        commercial_quantity_basis = VALUES(commercial_quantity_basis), commercial_updated_by = VALUES(commercial_updated_by)", 'Could not save product profile');
    logAudit('Product', 'Edit', "$session_name updated product $product_id commercial profile", 0, $product_id);
    flashAlert('Product commercial profile updated');
    redirect('subscriptions.php');
}

if (isset($_POST['save_subscription_record'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $client_id = intval($_POST['client_id'] ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $contract_id = intval($_POST['contract_id'] ?? 0);
    enforceClientAccess($client_id);
    $external_id = mysqli_real_escape_string($mysqli, substr(trim((string) ($_POST['external_id'] ?? '')), 0, 255));
    if ($vendor_id <= 0 || $external_id === '' || $product_id <= 0) {
        flashAlert('Vendor, external ID, client, and product are required', 'error');
        redirect('subscriptions.php');
    }
    $product = mysqli_fetch_assoc(commercialDbQuery("SELECT product_id FROM products
        WHERE product_id = $product_id AND product_archived_at IS NULL", 'Could not validate subscription product'));
    if (!$product) {
        flashAlert('Choose an active product', 'error');
        redirect('subscriptions.php');
    }
    $vendor = mysqli_fetch_assoc(commercialDbQuery("SELECT vendor_id, vendor_name FROM vendors
        WHERE vendor_id = $vendor_id AND vendor_archived_at IS NULL", 'Could not validate subscription vendor'));
    if (!$vendor) {
        flashAlert('Choose an active vendor', 'error');
        redirect('subscriptions.php');
    }
    if ($contract_id) {
        $contract = mysqli_fetch_assoc(commercialDbQuery("SELECT contract_id FROM contracts WHERE contract_id = $contract_id
            AND contract_client_id = $client_id AND contract_archived_at IS NULL", 'Could not validate subscription agreement'));
        if (!$contract) {
            flashAlert('Choose an agreement belonging to this client', 'error');
            redirect('subscriptions.php');
        }
    }
    $purchased = max(0, floatval($_POST['purchased_quantity'] ?? 0));
    $managed = trim((string) ($_POST['managed_quantity'] ?? ''));
    $managed_sql = $managed === '' ? 'NULL' : (string) max(0, floatval($managed));
    $cost = max(0, floatval($_POST['unit_cost'] ?? 0));
    $price = max(0, floatval($_POST['unit_price'] ?? 0));
    commercialDbQuery("INSERT INTO subscription_records SET subscription_vendor_id = $vendor_id,
        subscription_external_id = '$external_id', subscription_client_id = $client_id, subscription_contract_id = $contract_id,
        subscription_product_id = $product_id, subscription_purchased_quantity = $purchased,
        subscription_managed_quantity = $managed_sql, subscription_unit_cost = $cost,
        subscription_unit_price = $price, subscription_observed_at = NOW(), subscription_updated_by = $session_user_id
        ON DUPLICATE KEY UPDATE subscription_client_id = VALUES(subscription_client_id), subscription_contract_id = VALUES(subscription_contract_id),
        subscription_product_id = VALUES(subscription_product_id), subscription_purchased_quantity = VALUES(subscription_purchased_quantity),
        subscription_managed_quantity = VALUES(subscription_managed_quantity), subscription_unit_cost = VALUES(subscription_unit_cost),
        subscription_unit_price = VALUES(subscription_unit_price), subscription_status = 'active',
        subscription_observed_at = VALUES(subscription_observed_at), subscription_updated_by = VALUES(subscription_updated_by)", 'Could not save subscription record');
    logAudit('Subscription', 'Edit', "$session_name reconciled {$vendor['vendor_name']} subscription $external_id", $client_id);
    flashAlert('Subscription source updated');
    redirect("subscriptions.php?client_id=$client_id");
}

if (isset($_POST['apply_subscription_quantity'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $subscription_id = intval($_POST['subscription_id'] ?? 0);
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin subscription update');
        }
        $subscription = mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM subscription_records WHERE subscription_id = $subscription_id AND subscription_status = 'active' FOR UPDATE", 'Could not lock subscription'));
        if (!$subscription) {
            throw new RuntimeException('The subscription is unavailable');
        }
        $client_id = intval($subscription['subscription_client_id']);
        enforceClientAccess($client_id);
        $product_id = intval($subscription['subscription_product_id']);
        $lines = commercialDbQuery("SELECT rii.item_id, rii.item_recurring_invoice_id, rii.item_tax_id, rii.item_price
            FROM recurring_invoice_items rii INNER JOIN recurring_invoices ri ON ri.recurring_invoice_id = rii.item_recurring_invoice_id
            WHERE rii.item_product_id = $product_id AND ri.recurring_invoice_client_id = $client_id
            AND ri.recurring_invoice_status = 1 AND rii.item_archived_at IS NULL FOR UPDATE", 'Could not locate recurring invoice line');
        if (mysqli_num_rows($lines) !== 1) {
            throw new RuntimeException('Exactly one active recurring invoice line must be linked to this product');
        }
        $line = mysqli_fetch_assoc($lines);
        $item_id = intval($line['item_id']);
        $recurring_id = intval($line['item_recurring_invoice_id']);
        $quantity = floatval($subscription['subscription_purchased_quantity']);
        $price = floatval($subscription['subscription_unit_price']);
        $subtotal = round($quantity * $price, 2);
        $tax_percent = 0.0;
        if (intval($line['item_tax_id'])) {
            $tax = mysqli_fetch_assoc(commercialDbQuery("SELECT tax_percent FROM taxes WHERE tax_id = " . intval($line['item_tax_id']), 'Could not load subscription tax'));
            $tax_percent = floatval($tax['tax_percent'] ?? 0);
        }
        $tax_amount = round($subtotal * $tax_percent / 100, 2);
        $total = $subtotal + $tax_amount;
        commercialDbQuery("UPDATE recurring_invoice_items SET item_quantity = $quantity, item_price = $price,
            item_subtotal = $subtotal, item_tax = $tax_amount, item_total = $total WHERE item_id = $item_id", 'Could not update recurring invoice line');
        commercialDbQuery("UPDATE recurring_invoices SET recurring_invoice_amount =
            (SELECT COALESCE(SUM(item_total), 0) FROM recurring_invoice_items WHERE item_recurring_invoice_id = $recurring_id AND item_archived_at IS NULL)
            - recurring_invoice_discount_amount WHERE recurring_invoice_id = $recurring_id", 'Could not recalculate recurring invoice');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit subscription reconciliation');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log('Subscription reconciliation failed: ' . $exception->getMessage());
        flashAlert('The recurring quantity was not changed. Link exactly one active recurring invoice line to this product, then retry.', 'error');
        redirect('subscriptions.php');
    }
    logAudit('Recurring Invoice', 'Edit', "$session_name synchronized subscription $subscription_id", $client_id, $recurring_id);
    flashAlert('Recurring invoice quantity synchronized');
    redirect("recurring_invoice.php?recurring_invoice_id=$recurring_id");
}

if (isset($_POST['create_purchase_order'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    $product_id = intval($_POST['product_id'] ?? 0);
    if ($client_id) {
        enforceClientAccess($client_id);
    }
    $quantity = max(0, floatval($_POST['quantity'] ?? 0));
    if ($vendor_id <= 0 || $product_id <= 0 || $quantity <= 0 || floor($quantity) != $quantity) {
        flashAlert('Vendor, product, and a positive whole-unit quantity are required', 'error');
        redirect('purchasing.php');
    }
    $cost = max(0, floatval($_POST['unit_cost'] ?? 0));
    $price = max(0, floatval($_POST['unit_price'] ?? 0));
    $expected = (string) ($_POST['expected_at'] ?? '');
    $expected_sql = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) ? "'" . mysqli_real_escape_string($mysqli, $expected) . "'" : 'NULL';
    $note = mysqli_real_escape_string($mysqli, substr(trim((string) ($_POST['note'] ?? '')), 0, 1000));
    $product = mysqli_fetch_assoc(commercialDbQuery("SELECT product_name, product_code FROM products WHERE product_id = $product_id AND product_type = 'product' AND product_archived_at IS NULL", 'Could not load product'));
    if (!$product) {
        flashAlert('Choose an active stock product', 'error');
        redirect('purchasing.php');
    }
    $vendor = mysqli_fetch_assoc(commercialDbQuery("SELECT vendor_id FROM vendors
        WHERE vendor_id = $vendor_id AND vendor_archived_at IS NULL", 'Could not validate purchase vendor'));
    if (!$vendor) {
        flashAlert('Choose an active vendor', 'error');
        redirect('purchasing.php');
    }
    $number = mysqli_real_escape_string($mysqli, 'PO-' . date('Ymd-His') . '-' . random_int(100, 999));
    commercialDbQuery("INSERT INTO purchase_orders SET purchase_order_number = '$number',
        purchase_order_vendor_id = $vendor_id, purchase_order_client_id = $client_id,
        purchase_order_expected_at = $expected_sql, purchase_order_note = '$note', purchase_order_created_by = $session_user_id", 'Could not create purchase order');
    $order_id = intval(mysqli_insert_id($mysqli));
    $description = mysqli_real_escape_string($mysqli, (string) $product['product_name']);
    $sku = mysqli_real_escape_string($mysqli, (string) $product['product_code']);
    commercialDbQuery("INSERT INTO purchase_order_items SET purchase_item_order_id = $order_id,
        purchase_item_product_id = $product_id, purchase_item_description = '$description',
        purchase_item_vendor_sku = '$sku', purchase_item_quantity_ordered = $quantity,
        purchase_item_unit_cost = $cost, purchase_item_unit_price = $price", 'Could not add purchase order item');
    logAudit('Purchase Order', 'Create', "$session_name created $number", $client_id, $order_id);
    flashAlert("Purchase order <strong>$number</strong> created");
    redirect('purchasing.php');
}

if (isset($_POST['submit_purchase_order'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $order_id = intval($_POST['purchase_order_id'] ?? 0);
    $order = mysqli_fetch_assoc(commercialDbQuery("SELECT purchase_order_client_id, purchase_order_number FROM purchase_orders WHERE purchase_order_id = $order_id", 'Could not load purchase order'));
    if (!$order) {
        flashAlert('Purchase order not found', 'error');
        redirect('purchasing.php');
    }
    if (intval($order['purchase_order_client_id'])) {
        enforceClientAccess(intval($order['purchase_order_client_id']));
    }
    commercialDbQuery("UPDATE purchase_orders SET purchase_order_status = 'ordered', purchase_order_submitted_at = NOW()
        WHERE purchase_order_id = $order_id AND purchase_order_status = 'draft'", 'Could not mark purchase order ordered');
    logAudit('Purchase Order', 'Edit', "$session_name marked {$order['purchase_order_number']} ordered", intval($order['purchase_order_client_id']), $order_id);
    flashAlert('Purchase order marked ordered');
    redirect('purchasing.php');
}

if (isset($_POST['receive_purchase_item'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    $item_id = intval($_POST['purchase_item_id'] ?? 0);
    $quantity = floatval($_POST['receive_quantity'] ?? 0);
    if ($quantity <= 0 || floor($quantity) != $quantity) {
        flashAlert('Stock must be received in positive whole units', 'error');
        redirect('purchasing.php');
    }
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin receiving');
        }
        $item = mysqli_fetch_assoc(commercialDbQuery("SELECT poi.*, po.purchase_order_status, po.purchase_order_client_id,
            po.purchase_order_number FROM purchase_order_items poi INNER JOIN purchase_orders po
            ON po.purchase_order_id = poi.purchase_item_order_id WHERE poi.purchase_item_id = $item_id FOR UPDATE", 'Could not lock purchase item'));
        if (!$item || !in_array($item['purchase_order_status'], ['ordered', 'partially_received'], true)) {
            throw new RuntimeException('The purchase order is not ready to receive');
        }
        $client_id = intval($item['purchase_order_client_id']);
        if ($client_id) {
            enforceClientAccess($client_id);
        }
        $remaining = floatval($item['purchase_item_quantity_ordered']) - floatval($item['purchase_item_quantity_received']);
        if ($quantity > $remaining) {
            throw new RuntimeException('Received quantity exceeds the open quantity');
        }
        $product_id = intval($item['purchase_item_product_id']);
        $order_id = intval($item['purchase_item_order_id']);
        $quantity_int = intval($quantity);
        $note = mysqli_real_escape_string($mysqli, "Received from {$item['purchase_order_number']}");
        commercialDbQuery("UPDATE purchase_order_items SET purchase_item_quantity_received = purchase_item_quantity_received + $quantity
            WHERE purchase_item_id = $item_id", 'Could not update received quantity');
        commercialDbQuery("INSERT INTO product_stock SET stock_qty = $quantity_int, stock_note = '$note',
            stock_product_id = $product_id", 'Could not add received stock');
        $remaining_row = mysqli_fetch_assoc(commercialDbQuery("SELECT SUM(purchase_item_quantity_received < purchase_item_quantity_ordered) AS open_lines,
            SUM(purchase_item_quantity_received > 0) AS received_lines FROM purchase_order_items WHERE purchase_item_order_id = $order_id", 'Could not calculate fulfillment state'));
        $new_status = intval($remaining_row['open_lines']) === 0 ? 'received' : (intval($remaining_row['received_lines']) > 0 ? 'partially_received' : 'ordered');
        $completed = $new_status === 'received' ? ', purchase_order_completed_at = NOW()' : '';
        commercialDbQuery("UPDATE purchase_orders SET purchase_order_status = '$new_status' $completed WHERE purchase_order_id = $order_id", 'Could not update purchase order state');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit receiving');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log('Purchase receiving failed: ' . $exception->getMessage());
        flashAlert('Nothing was received. Refresh the purchase order and enter no more than its remaining quantity.', 'error');
        redirect('purchasing.php');
    }
    logAudit('Purchase Order', 'Edit', "$session_name received $quantity unit(s) on {$item['purchase_order_number']}", $client_id, $order_id);
    flashAlert('Stock received and fulfillment status updated');
    redirect('purchasing.php');
}

if (isset($_POST['complete_quote_delivery'])) {
    validateCSRFToken();
    enforceUserPermission('module_sales', 2);
    enforceUserPermission('module_support', 2);
    $quote_id = intval($_POST['quote_id'] ?? 0);
    $client_id = intval(getFieldById('quotes', $quote_id, 'quote_client_id'));
    enforceClientAccess($client_id);
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin quote delivery');
        }
        $result = commercialCompleteQuoteDelivery(
            $quote_id,
            intval($_POST['project_template_id'] ?? 0),
            intval($_POST['project_manager_id'] ?? 0),
            (string) ($_POST['due_date'] ?? ''),
            isset($_POST['create_invoice']),
            isset($_POST['create_recurring']),
            isset($_POST['create_purchasing']),
            $session_user_id
        );
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit quote delivery');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Quote $quote_id delivery failed: " . $exception->getMessage());
        flashAlert('The delivery package was not created. Check the project template, owner, product profiles, and try again.', 'error');
        redirect("quote_delivery.php?quote_id=$quote_id");
    }
    logAudit('Quote Delivery', 'Create', "$session_name created delivery for quote $quote_id", $client_id, $quote_id);
    if ($result['invoice_id']) {
        triggerCustomAction('invoice_create', intval($result['invoice_id']));
    }
    flashAlert('Delivery project, billing, and fulfillment records created');
    redirect("quote_delivery.php?quote_id=$quote_id");
}
