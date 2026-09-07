<?php

/*
 * N45 commercial operations.
 *
 * These helpers deliberately extend ITFlow's native quotes, invoices,
 * recurring invoices, products, stock, vendors, expenses, projects and ticket
 * time. The custom tables hold review decisions and cross-module provenance;
 * they do not replace the native ledgers.
 */

function commercialDbQuery(string $sql, string $message = 'Commercial operation failed')
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function commercialSettings(): array
{
    $row = mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM commercial_settings WHERE commercial_settings_id = 1", 'Could not load commercial settings'));
    return $row ?: [
        'commercial_labor_cost_rate' => 0,
        'commercial_billing_increment_minutes' => 15,
    ];
}

function commercialRoundHours(float $hours, int $minutes): float
{
    if ($hours <= 0) {
        return 0.0;
    }
    $minutes = in_array($minutes, [1, 6, 10, 15, 30, 60], true) ? $minutes : 15;
    return ceil(($hours * 60) / $minutes) * $minutes / 60;
}

function commercialQuoteHash(array $quote, array $items): string
{
    $payload = [
        'quote_id' => intval($quote['quote_id'] ?? 0),
        'client_id' => intval($quote['quote_client_id'] ?? 0),
        'scope' => (string) ($quote['quote_scope'] ?? ''),
        'amount' => number_format(floatval($quote['quote_amount'] ?? 0), 2, '.', ''),
        'currency' => (string) ($quote['quote_currency_code'] ?? ''),
        'items' => [],
    ];
    foreach ($items as $item) {
        $payload['items'][] = [
            intval($item['item_id'] ?? 0),
            intval($item['item_product_id'] ?? 0),
            (string) ($item['item_name'] ?? ''),
            number_format(floatval($item['item_quantity'] ?? 0), 2, '.', ''),
            number_format(floatval($item['item_price'] ?? 0), 2, '.', ''),
        ];
    }
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Create the immutable handoff snapshot the first time an accepted quote is seen.
 * Replayed acceptance requests are harmless and cannot silently change scope.
 */
function commercialPrepareQuoteDelivery(int $quote_id, int $actor_id = 0): ?array
{
    global $mysqli;
    $quote = mysqli_fetch_assoc(commercialDbQuery("SELECT quote_id, quote_client_id, quote_scope, quote_amount,
        quote_currency_code, quote_status FROM quotes WHERE quote_id = $quote_id LIMIT 1", 'Could not load accepted quote'));
    if (!$quote || !in_array($quote['quote_status'], ['Accepted', 'Invoiced'], true)) {
        return null;
    }

    $items = [];
    $result = commercialDbQuery("SELECT qi.item_id, qi.item_product_id, qi.item_name, qi.item_description,
        qi.item_quantity, qi.item_price, COALESCE(cp.commercial_unit_cost, 0) AS unit_cost
        FROM quote_items qi
        LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = qi.item_product_id
        WHERE qi.item_quote_id = $quote_id AND qi.item_archived_at IS NULL
        ORDER BY qi.item_order, qi.item_id", 'Could not load quote delivery items');
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    $hash = commercialQuoteHash($quote, $items);
    $scope = mysqli_real_escape_string($mysqli, (string) $quote['quote_scope']);
    $hash_sql = mysqli_real_escape_string($mysqli, $hash);
    $client_id = intval($quote['quote_client_id']);

    $existing = mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM quote_delivery_plans
        WHERE delivery_quote_id = $quote_id", 'Could not inspect quote delivery')) ?: null;

    commercialDbQuery("INSERT IGNORE INTO quote_delivery_plans SET delivery_quote_id = $quote_id,
        delivery_client_id = $client_id, delivery_scope_snapshot = '$scope', delivery_quote_hash = '$hash_sql',
        delivery_created_by = $actor_id", 'Could not prepare quote delivery');

    // Retry an interrupted initial snapshot only while the approved quote still
    // has the exact same content hash. Later edits never enter delivery.
    if (!$existing || hash_equals((string) $existing['delivery_quote_hash'], $hash)) {
        foreach ($items as $item) {
            $item_id = intval($item['item_id']);
            $product_id = intval($item['item_product_id']);
            $name = mysqli_real_escape_string($mysqli, (string) $item['item_name']);
            $description = mysqli_real_escape_string($mysqli, (string) $item['item_description']);
            $quantity = floatval($item['item_quantity']);
            $price = floatval($item['item_price']);
            $cost = floatval($item['unit_cost']);
            commercialDbQuery("INSERT IGNORE INTO quote_delivery_items SET delivery_item_quote_id = $quote_id,
                delivery_item_quote_item_id = $item_id, delivery_item_product_id = $product_id,
                delivery_item_name = '$name', delivery_item_description = '$description',
                delivery_item_quantity = $quantity, delivery_item_unit_price = $price,
                delivery_item_unit_cost = $cost", 'Could not snapshot a quote delivery item');
        }
    }

    return mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM quote_delivery_plans WHERE delivery_quote_id = $quote_id", 'Could not reload quote delivery')) ?: null;
}

function commercialCreateProjectFromQuote(array $quote, int $template_id, int $manager_id, string $due_date, int $actor_id): int
{
    global $mysqli;
    $client_id = intval($quote['quote_client_id']);
    $quote_id = intval($quote['quote_id']);
    $scope = trim((string) $quote['quote_scope']);
    $quote_label = (string) $quote['quote_prefix'] . intval($quote['quote_number']);
    $name = mysqli_real_escape_string($mysqli, $scope !== '' ? $scope : "Delivery for $quote_label");
    $description = mysqli_real_escape_string($mysqli, "Approved scope from $quote_label\n\n" . $scope);
    $due_sql = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date) ? "'" . mysqli_real_escape_string($mysqli, $due_date) . "'" : 'NULL';

    if ($manager_id <= 0) {
        throw new RuntimeException('Choose a project owner');
    }
    $manager = mysqli_fetch_assoc(commercialDbQuery("SELECT user_id FROM users WHERE user_id = $manager_id
        AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL", 'Could not validate project owner'));
    if (!$manager) {
        throw new RuntimeException('Choose an active project owner');
    }

    $stages = [];
    if ($template_id > 0) {
        $template = mysqli_fetch_assoc(commercialDbQuery("SELECT project_template_id FROM project_templates
            WHERE project_template_id = $template_id AND project_template_archived_at IS NULL", 'Could not validate project template'));
        if (!$template) {
            throw new RuntimeException('Choose an active project template');
        }
        $stage_rows = commercialDbQuery("SELECT ptt.ticket_template_id, ptt.ticket_template_order,
            ptt.ticket_template_runbook_version_id, tt.ticket_template_subject, tt.ticket_template_details,
            tt.ticket_template_published_version_id, tt.ticket_template_archived_at,
            rv.runbook_version_id, rv.runbook_version_subject, rv.runbook_version_details,
            (SELECT COUNT(*) FROM runbook_versions h WHERE h.runbook_version_ticket_template_id = ptt.ticket_template_id) AS version_count
            FROM project_template_ticket_templates ptt
            LEFT JOIN ticket_templates tt ON tt.ticket_template_id = ptt.ticket_template_id
            LEFT JOIN runbook_versions rv ON rv.runbook_version_id = ptt.ticket_template_runbook_version_id
                AND rv.runbook_version_ticket_template_id = ptt.ticket_template_id
            WHERE ptt.project_template_id = $template_id ORDER BY ptt.ticket_template_order, ptt.ticket_template_id", 'Could not load project template stages');
        while ($stage = mysqli_fetch_assoc($stage_rows)) {
            $pinned = intval($stage['ticket_template_runbook_version_id']);
            if (!intval($stage['ticket_template_id']) || $stage['ticket_template_archived_at'] !== null
                || ($pinned && intval($stage['runbook_version_id']) !== $pinned)
                || (!$pinned && (intval($stage['ticket_template_published_version_id']) || intval($stage['version_count'])))) {
                throw new RuntimeException('The selected project template contains an unavailable or unpinned stage');
            }
            $stage['subject'] = $pinned ? $stage['runbook_version_subject'] : $stage['ticket_template_subject'];
            $stage['details'] = $pinned ? $stage['runbook_version_details'] : $stage['ticket_template_details'];
            if (trim((string) $stage['subject']) === '') {
                throw new RuntimeException('The selected project template contains a stage without a subject');
            }
            $stages[] = $stage;
        }
    }

    $settings = mysqli_fetch_assoc(commercialDbQuery("SELECT config_project_prefix, config_ticket_prefix FROM settings WHERE company_id = 1 FOR UPDATE", 'Could not load numbering settings'));
    $project_prefix = mysqli_real_escape_string($mysqli, (string) $settings['config_project_prefix']);
    commercialDbQuery("UPDATE settings SET config_project_next_number = LAST_INSERT_ID(config_project_next_number),
        config_project_next_number = config_project_next_number + 1 WHERE company_id = 1", 'Could not allocate a project number');
    $project_number = intval(mysqli_insert_id($mysqli));
    commercialDbQuery("INSERT INTO projects SET project_prefix = '$project_prefix', project_number = $project_number,
        project_name = '$name', project_description = '$description', project_due = $due_sql,
        project_manager = $manager_id, project_client_id = $client_id", 'Could not create delivery project');
    $project_id = intval(mysqli_insert_id($mysqli));

    $ticket_prefix = mysqli_real_escape_string($mysqli, (string) $settings['config_ticket_prefix']);
    foreach ($stages as $stage) {
        commercialDbQuery("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1", 'Could not allocate a delivery ticket number');
        $ticket_number = intval(mysqli_insert_id($mysqli));
        $subject = mysqli_real_escape_string($mysqli, (string) $stage['subject']);
        $details = mysqli_real_escape_string($mysqli, (string) $stage['details'] . "\n\nApproved quote: $quote_label\nApproved scope: $scope");
        $order = intval($stage['ticket_template_order']);
        commercialDbQuery("INSERT INTO tickets SET ticket_prefix = '$ticket_prefix', ticket_number = $ticket_number,
            ticket_source = 'Approved Quote', ticket_subject = '$subject', ticket_details = '$details',
            ticket_work_type = 'project_task', ticket_priority = 'Low', ticket_impact = 'low', ticket_urgency = 'low',
            ticket_status = 1, ticket_created_by = $actor_id, ticket_assigned_to = $manager_id,
            ticket_client_id = $client_id, ticket_quote_id = $quote_id, ticket_project_id = $project_id,
            ticket_order = $order", 'Could not create delivery project stage');
        $ticket_id = intval(mysqli_insert_id($mysqli));
        applyTicketSla($ticket_id, null, null, true);
        addTasksFromTicketTemplate($ticket_id, intval($stage['ticket_template_id']), intval($stage['ticket_template_runbook_version_id']), true);
    }
    return $project_id;
}

function commercialCreateInvoice(int $client_id, string $scope, array $items, int $category_id = 0): int
{
    global $mysqli, $session_company_currency;
    $client = mysqli_fetch_assoc(commercialDbQuery("SELECT client_net_terms FROM clients WHERE client_id = $client_id
        AND client_archived_at IS NULL FOR UPDATE", 'Could not validate invoice client'));
    if (!$client) {
        throw new RuntimeException('The invoice client is unavailable');
    }
    if ($category_id <= 0) {
        $category = mysqli_fetch_assoc(commercialDbQuery("SELECT category_id FROM categories WHERE category_type = 'Income'
            AND category_archived_at IS NULL ORDER BY category_id LIMIT 1", 'Could not choose an invoice category'));
        $category_id = intval($category['category_id'] ?? 0);
    }
    commercialDbQuery("UPDATE settings SET config_invoice_next_number = LAST_INSERT_ID(config_invoice_next_number),
        config_invoice_next_number = config_invoice_next_number + 1 WHERE company_id = 1", 'Could not allocate an invoice number');
    $number = intval(mysqli_insert_id($mysqli));
    $settings = mysqli_fetch_assoc(commercialDbQuery("SELECT config_invoice_prefix, config_default_net_terms FROM settings WHERE company_id = 1", 'Could not load invoice settings'));
    $prefix = mysqli_real_escape_string($mysqli, (string) $settings['config_invoice_prefix']);
    $terms = intval($client['client_net_terms']) ?: intval($settings['config_default_net_terms']);
    $scope_sql = mysqli_real_escape_string($mysqli, $scope);
    $currency = mysqli_real_escape_string($mysqli, (string) $session_company_currency);
    $url_key = mysqli_real_escape_string($mysqli, randomString(32));
    $total = array_sum(array_map(static fn ($item) => floatval($item['amount']), $items));
    commercialDbQuery("INSERT INTO invoices SET invoice_prefix = '$prefix', invoice_number = $number,
        invoice_scope = '$scope_sql', invoice_date = CURRENT_DATE, invoice_due = DATE_ADD(CURRENT_DATE, INTERVAL $terms DAY),
        invoice_category_id = $category_id, invoice_status = 'Draft', invoice_amount = $total,
        invoice_currency_code = '$currency', invoice_url_key = '$url_key', invoice_client_id = $client_id", 'Could not create draft invoice');
    $invoice_id = intval(mysqli_insert_id($mysqli));
    foreach ($items as $order => $item) {
        $name = mysqli_real_escape_string($mysqli, substr((string) $item['name'], 0, 200));
        $description = mysqli_real_escape_string($mysqli, (string) ($item['description'] ?? ''));
        $quantity = floatval($item['quantity']);
        $price = floatval($item['unit_price']);
        $amount = floatval($item['amount']);
        $product_id = intval($item['product_id'] ?? 0);
        commercialDbQuery("INSERT INTO invoice_items SET item_name = '$name', item_description = '$description',
            item_quantity = $quantity, item_price = $price, item_subtotal = $amount, item_total = $amount,
            item_order = $order, item_product_id = $product_id, item_invoice_id = $invoice_id", 'Could not add a reviewed invoice item');
    }
    commercialDbQuery("INSERT INTO history SET history_status = 'Draft', history_description = 'Draft invoice created from approved billing review', history_invoice_id = $invoice_id", 'Could not record invoice history');
    return $invoice_id;
}

function commercialCreateRecurringInvoiceFromQuote(array $quote): int
{
    global $mysqli, $session_company_currency;
    $quote_id = intval($quote['quote_id']);
    $client_id = intval($quote['quote_client_id']);
    $rows = commercialDbQuery("SELECT qdi.*, qi.item_tax_id FROM quote_delivery_items qdi
        LEFT JOIN quote_items qi ON qi.item_id = qdi.delivery_item_quote_item_id
        INNER JOIN commercial_product_profiles cp ON cp.commercial_product_id = qdi.delivery_item_product_id
            AND cp.commercial_recurring = 1
        WHERE qdi.delivery_item_quote_id = $quote_id ORDER BY qdi.delivery_item_id", 'Could not load recurring quote items');
    $items = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $items[] = $row;
    }
    if (!$items) {
        return 0;
    }
    commercialDbQuery("UPDATE settings SET config_recurring_invoice_next_number = LAST_INSERT_ID(config_recurring_invoice_next_number),
        config_recurring_invoice_next_number = config_recurring_invoice_next_number + 1 WHERE company_id = 1", 'Could not allocate a recurring invoice number');
    $number = intval(mysqli_insert_id($mysqli));
    $settings = mysqli_fetch_assoc(commercialDbQuery("SELECT config_recurring_invoice_prefix FROM settings WHERE company_id = 1", 'Could not load recurring invoice settings'));
    $prefix = mysqli_real_escape_string($mysqli, (string) $settings['config_recurring_invoice_prefix']);
    $scope = mysqli_real_escape_string($mysqli, (string) $quote['quote_scope']);
    $currency = mysqli_real_escape_string($mysqli, (string) $session_company_currency);
    $category_id = intval($quote['quote_category_id']);
    $total = 0.0;
    foreach ($items as $item) {
        $total += floatval($item['delivery_item_quantity']) * floatval($item['delivery_item_unit_price']);
    }
    commercialDbQuery("INSERT INTO recurring_invoices SET recurring_invoice_prefix = '$prefix', recurring_invoice_number = $number,
        recurring_invoice_scope = '$scope', recurring_invoice_frequency = 'month',
        recurring_invoice_next_date = DATE_FORMAT(DATE_ADD(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m-01'),
        recurring_invoice_status = 1, recurring_invoice_amount = $total, recurring_invoice_currency_code = '$currency',
        recurring_invoice_category_id = $category_id, recurring_invoice_client_id = $client_id", 'Could not create recurring service billing');
    $recurring_id = intval(mysqli_insert_id($mysqli));
    foreach ($items as $order => $item) {
        $name = mysqli_real_escape_string($mysqli, (string) $item['delivery_item_name']);
        $description = mysqli_real_escape_string($mysqli, (string) $item['delivery_item_description']);
        $quantity = floatval($item['delivery_item_quantity']);
        $price = floatval($item['delivery_item_unit_price']);
        $subtotal = $quantity * $price;
        $product_id = intval($item['delivery_item_product_id']);
        $tax_id = intval($item['item_tax_id']);
        commercialDbQuery("INSERT INTO recurring_invoice_items SET item_name = '$name', item_description = '$description',
            item_quantity = $quantity, item_price = $price, item_subtotal = $subtotal, item_total = $subtotal,
            item_order = $order, item_tax_id = $tax_id, item_product_id = $product_id,
            item_recurring_invoice_id = $recurring_id", 'Could not add recurring service item');
    }
    return $recurring_id;
}

function commercialCreatePurchaseOrdersForQuote(int $quote_id, int $client_id, int $project_id, int $actor_id): array
{
    global $mysqli;
    $orders = [];
    $rows = commercialDbQuery("SELECT qdi.*, v.vendor_id AS active_vendor_id, cp.commercial_vendor_sku,
        COALESCE((SELECT SUM(stock_qty) FROM product_stock WHERE stock_product_id = qdi.delivery_item_product_id), 0) AS on_hand
        FROM quote_delivery_items qdi
        INNER JOIN products p ON p.product_id = qdi.delivery_item_product_id AND p.product_type = 'product'
        LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = qdi.delivery_item_product_id
        LEFT JOIN vendors v ON v.vendor_id = cp.commercial_preferred_vendor_id AND v.vendor_archived_at IS NULL
        WHERE qdi.delivery_item_quote_id = $quote_id ORDER BY v.vendor_id, qdi.delivery_item_id", 'Could not load purchasing requirements');
    while ($item = mysqli_fetch_assoc($rows)) {
        $needed = ceil(max(0, floatval($item['delivery_item_quantity']) - floatval($item['on_hand'])));
        $vendor_id = intval($item['active_vendor_id']);
        if ($needed <= 0 || $vendor_id <= 0) {
            continue;
        }
        if (!isset($orders[$vendor_id])) {
            $number = mysqli_real_escape_string($mysqli, 'PO-' . $quote_id . '-' . $vendor_id);
            commercialDbQuery("INSERT IGNORE INTO purchase_orders SET purchase_order_number = '$number',
                purchase_order_vendor_id = $vendor_id, purchase_order_client_id = $client_id,
                purchase_order_project_id = $project_id, purchase_order_quote_id = $quote_id,
                purchase_order_created_by = $actor_id", 'Could not create purchase order');
            $order = mysqli_fetch_assoc(commercialDbQuery("SELECT purchase_order_id FROM purchase_orders
                WHERE purchase_order_number = '$number'", 'Could not reload purchase order'));
            $orders[$vendor_id] = intval($order['purchase_order_id']);
        }
        $order_id = $orders[$vendor_id];
        $product_id = intval($item['delivery_item_product_id']);
        $quote_item_id = intval($item['delivery_item_quote_item_id']);
        $description = mysqli_real_escape_string($mysqli, (string) $item['delivery_item_name']);
        $sku = mysqli_real_escape_string($mysqli, (string) $item['commercial_vendor_sku']);
        $cost = floatval($item['delivery_item_unit_cost']);
        $price = floatval($item['delivery_item_unit_price']);
        commercialDbQuery("INSERT IGNORE INTO purchase_order_items SET purchase_item_order_id = $order_id,
            purchase_item_product_id = $product_id, purchase_item_quote_item_id = $quote_item_id,
            purchase_item_description = '$description', purchase_item_vendor_sku = '$sku',
            purchase_item_quantity_ordered = $needed, purchase_item_unit_cost = $cost,
            purchase_item_unit_price = $price", 'Could not add purchase order item');
    }
    return array_values($orders);
}

function commercialCompleteQuoteDelivery(int $quote_id, int $template_id, int $manager_id, string $due_date,
    bool $create_invoice, bool $create_recurring, bool $create_purchasing, int $actor_id): array
{
    global $mysqli;
    if (!commercialPrepareQuoteDelivery($quote_id, $actor_id)) {
        throw new RuntimeException('The quote is not accepted');
    }
    $plan = mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM quote_delivery_plans
        WHERE delivery_quote_id = $quote_id FOR UPDATE", 'Could not lock quote delivery')) ?: null;
    if (!$plan || $plan['delivery_status'] === 'completed') {
        throw new RuntimeException($plan ? 'Delivery has already been created for this quote' : 'The quote is not accepted');
    }
    $quote = mysqli_fetch_assoc(commercialDbQuery("SELECT * FROM quotes WHERE quote_id = $quote_id FOR UPDATE", 'Could not lock the quote'));
    if (!$quote || !in_array($quote['quote_status'], ['Accepted', 'Invoiced'], true)) {
        throw new RuntimeException('Only an accepted quote can enter delivery');
    }
    $client_id = intval($quote['quote_client_id']);
    $project_id = commercialCreateProjectFromQuote($quote, $template_id, $manager_id, $due_date, $actor_id);
    $invoice_id = intval($plan['delivery_invoice_id']);
    if ($create_invoice && !$invoice_id) {
        $invoice_items = [];
        $result = commercialDbQuery("SELECT * FROM quote_delivery_items WHERE delivery_item_quote_id = $quote_id ORDER BY delivery_item_id", 'Could not load invoice scope');
        while ($item = mysqli_fetch_assoc($result)) {
            $invoice_items[] = [
                'name' => $item['delivery_item_name'], 'description' => $item['delivery_item_description'],
                'quantity' => floatval($item['delivery_item_quantity']), 'unit_price' => floatval($item['delivery_item_unit_price']),
                'amount' => floatval($item['delivery_item_quantity']) * floatval($item['delivery_item_unit_price']),
                'product_id' => intval($item['delivery_item_product_id']),
            ];
        }
        $invoice_id = commercialCreateInvoice($client_id, (string) $quote['quote_scope'], $invoice_items, intval($quote['quote_category_id']));
        commercialDbQuery("UPDATE quotes SET quote_status = 'Invoiced' WHERE quote_id = $quote_id", 'Could not link invoice status');
    }
    $recurring_id = $create_recurring ? commercialCreateRecurringInvoiceFromQuote($quote) : 0;
    if ($create_purchasing) {
        $missing = mysqli_fetch_assoc(commercialDbQuery("SELECT COUNT(*) AS missing_count FROM quote_delivery_items qdi
            INNER JOIN products p ON p.product_id = qdi.delivery_item_product_id AND p.product_type = 'product'
            LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = qdi.delivery_item_product_id
            LEFT JOIN vendors v ON v.vendor_id = cp.commercial_preferred_vendor_id AND v.vendor_archived_at IS NULL
            WHERE qdi.delivery_item_quote_id = $quote_id
            AND qdi.delivery_item_quantity > COALESCE((SELECT SUM(stock_qty) FROM product_stock
                WHERE stock_product_id = qdi.delivery_item_product_id), 0)
            AND v.vendor_id IS NULL", 'Could not validate purchasing profiles'));
        if (intval($missing['missing_count'] ?? 0) > 0) {
            throw new RuntimeException('Every stock shortage needs a preferred vendor before purchase orders can be created');
        }
    }
    $purchase_orders = $create_purchasing ? commercialCreatePurchaseOrdersForQuote($quote_id, $client_id, $project_id, $actor_id) : [];
    $due_sql = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date) ? "'" . mysqli_real_escape_string($mysqli, $due_date) . "'" : 'NULL';
    commercialDbQuery("UPDATE quote_delivery_plans SET delivery_status = 'completed',
        delivery_project_template_id = $template_id, delivery_project_manager_id = $manager_id,
        delivery_project_id = $project_id, delivery_invoice_id = $invoice_id,
        delivery_recurring_invoice_id = $recurring_id, delivery_due_date = $due_sql,
        delivery_completed_by = $actor_id, delivery_completed_at = NOW()
        WHERE delivery_quote_id = $quote_id AND delivery_status = 'ready'", 'Could not finish quote delivery');
    return compact('project_id', 'invoice_id', 'recurring_id', 'purchase_orders');
}

function commercialManagedQuantity(int $client_id, string $basis, ?float $manual): float
{
    if ($basis === 'users') {
        $row = mysqli_fetch_assoc(commercialDbQuery("SELECT COUNT(*) AS qty FROM contacts WHERE contact_client_id = $client_id
            AND contact_archived_at IS NULL", 'Could not count managed users'));
        return floatval($row['qty'] ?? 0);
    }
    if ($basis === 'devices') {
        $row = mysqli_fetch_assoc(commercialDbQuery("SELECT COUNT(*) AS qty FROM assets WHERE asset_client_id = $client_id
            AND asset_archived_at IS NULL", 'Could not count managed devices'));
        return floatval($row['qty'] ?? 0);
    }
    return floatval($manual ?? 0);
}

function commercialSubscriptionRows(int $client_id = 0): array
{
    $scope = $client_id > 0 ? "AND sr.subscription_client_id = $client_id" : '';
    $rows = commercialDbQuery("SELECT sr.*, c.client_name, p.product_name, v.vendor_name, ct.contract_name,
        COALESCE(cp.commercial_quantity_basis, 'manual') AS quantity_basis,
        COALESCE((SELECT SUM(rii.item_quantity) FROM recurring_invoice_items rii
            INNER JOIN recurring_invoices ri ON ri.recurring_invoice_id = rii.item_recurring_invoice_id
            WHERE rii.item_product_id = sr.subscription_product_id
            AND ri.recurring_invoice_client_id = sr.subscription_client_id
            AND ri.recurring_invoice_status = 1 AND rii.item_archived_at IS NULL), 0) AS billed_quantity
        FROM subscription_records sr
        INNER JOIN clients c ON c.client_id = sr.subscription_client_id
        INNER JOIN products p ON p.product_id = sr.subscription_product_id
        INNER JOIN vendors v ON v.vendor_id = sr.subscription_vendor_id AND v.vendor_archived_at IS NULL
        LEFT JOIN contracts ct ON ct.contract_id = sr.subscription_contract_id
        LEFT JOIN commercial_product_profiles cp ON cp.commercial_product_id = sr.subscription_product_id
        WHERE sr.subscription_status = 'active' $scope
        ORDER BY c.client_name, p.product_name, v.vendor_name", 'Could not load subscription reconciliation');
    $result = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $row['effective_managed_quantity'] = commercialManagedQuantity(
            intval($row['subscription_client_id']), (string) $row['quantity_basis'],
            is_null($row['subscription_managed_quantity']) ? null : floatval($row['subscription_managed_quantity'])
        );
        $row['quantity_variance'] = floatval($row['subscription_purchased_quantity']) - floatval($row['billed_quantity']);
        $row['managed_variance'] = floatval($row['effective_managed_quantity']) - floatval($row['billed_quantity']);
        $result[] = $row;
    }
    return $result;
}

function commercialProfitabilityRows(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $from = $month . '-01';
    $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $labor_rate = floatval(commercialSettings()['commercial_labor_cost_rate']);
    $scope = clientScopeSql('c.client_id');
    $clients = [];
    $client_rows = commercialDbQuery("SELECT c.client_id, c.client_name FROM clients c
        WHERE c.client_archived_at IS NULL AND c.client_lead = 0 $scope", 'Could not load profitability clients');
    while ($client = mysqli_fetch_assoc($client_rows)) {
        $clients[intval($client['client_id'])] = $client['client_name'];
    }

    $rows = [];
    $ensure = static function (int $client_id, int $contract_id, string $contract_name = '') use (&$rows, &$clients): string {
        $key = $client_id . ':' . $contract_id;
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'client_id' => $client_id,
                'client_name' => $clients[$client_id] ?? 'Unknown client',
                'contract_id' => $contract_id,
                'contract_name' => $contract_id > 0 ? ($contract_name ?: 'Agreement #' . $contract_id) : 'Outside agreement / unallocated',
                'revenue' => 0.0,
                'expenses' => 0.0,
                'subscription_cost' => 0.0,
                'labor_hours' => 0.0,
            ];
        } elseif ($contract_name !== '') {
            $rows[$key]['contract_name'] = $contract_name;
        }
        return $key;
    };

    $active = commercialDbQuery("SELECT ct.contract_id, ct.contract_client_id, ct.contract_name FROM contracts ct
        INNER JOIN clients c ON c.client_id = ct.contract_client_id
        WHERE ct.contract_archived_at IS NULL AND ct.contract_status = 'Active'
        AND (ct.contract_start_date IS NULL OR ct.contract_start_date < '$to')
        AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= '$from') $scope", 'Could not load active profitability agreements');
    while ($contract = mysqli_fetch_assoc($active)) {
        $ensure(intval($contract['contract_client_id']), intval($contract['contract_id']), $contract['contract_name']);
    }

    $client_revenue = [];
    $revenue = commercialDbQuery("SELECT i.invoice_client_id AS client_id, SUM(i.invoice_amount) AS amount
        FROM invoices i INNER JOIN clients c ON c.client_id = i.invoice_client_id
        WHERE i.invoice_date >= '$from' AND i.invoice_date < '$to'
        AND i.invoice_status NOT IN ('Draft','Cancelled','Non-Billable') $scope GROUP BY i.invoice_client_id", 'Could not calculate issued revenue');
    while ($item = mysqli_fetch_assoc($revenue)) {
        $client_revenue[intval($item['client_id'])] = floatval($item['amount']);
    }

    $attributed_revenue = [];
    $reviewed = commercialDbQuery("SELECT bri.billing_client_id AS client_id, bri.billing_contract_id AS contract_id,
        COALESCE(ct.contract_name, '') AS contract_name, SUM(bri.billing_revenue_amount) AS amount
        FROM billing_review_items bri INNER JOIN invoices i ON i.invoice_id = bri.billing_invoice_id
        INNER JOIN clients c ON c.client_id = bri.billing_client_id
        LEFT JOIN contracts ct ON ct.contract_id = bri.billing_contract_id
        WHERE bri.billing_decision = 'invoiced' AND i.invoice_date >= '$from' AND i.invoice_date < '$to'
        AND i.invoice_status NOT IN ('Draft','Cancelled','Non-Billable') $scope
        GROUP BY bri.billing_client_id, bri.billing_contract_id, ct.contract_name", 'Could not allocate reviewed revenue');
    while ($item = mysqli_fetch_assoc($reviewed)) {
        $client_id = intval($item['client_id']);
        $contract_id = intval($item['contract_id']);
        $amount = floatval($item['amount']);
        $rows[$ensure($client_id, $contract_id, $item['contract_name'])]['revenue'] += $amount;
        $attributed_revenue[$client_id] = ($attributed_revenue[$client_id] ?? 0) + $amount;
    }

    $subscriptions = commercialDbQuery("SELECT sr.subscription_client_id AS client_id, sr.subscription_contract_id AS contract_id,
        COALESCE(ct.contract_name, '') AS contract_name, SUM(sr.subscription_purchased_quantity * sr.subscription_unit_cost) AS amount
        FROM subscription_records sr INNER JOIN clients c ON c.client_id = sr.subscription_client_id
        LEFT JOIN contracts ct ON ct.contract_id = sr.subscription_contract_id
        WHERE sr.subscription_status = 'active' AND sr.subscription_observed_at < '$to' $scope
        GROUP BY sr.subscription_client_id, sr.subscription_contract_id, ct.contract_name", 'Could not allocate subscription cost');
    while ($item = mysqli_fetch_assoc($subscriptions)) {
        $rows[$ensure(intval($item['client_id']), intval($item['contract_id']), $item['contract_name'])]['subscription_cost'] += floatval($item['amount']);
    }

    $expenses = commercialDbQuery("SELECT e.expense_client_id AS client_id, SUM(e.expense_amount) AS amount
        FROM expenses e INNER JOIN clients c ON c.client_id = e.expense_client_id
        WHERE e.expense_date >= '$from' AND e.expense_date < '$to' AND e.expense_archived_at IS NULL $scope
        GROUP BY e.expense_client_id", 'Could not calculate unallocated client expense');
    while ($item = mysqli_fetch_assoc($expenses)) {
        $rows[$ensure(intval($item['client_id']), 0)]['expenses'] += floatval($item['amount']);
    }

    $labor = commercialDbQuery("SELECT t.ticket_client_id AS client_id,
        COALESCE((SELECT d.ticket_agreement_decision_contract_id FROM ticket_agreement_decisions d
            WHERE d.ticket_agreement_decision_ticket_id = t.ticket_id ORDER BY d.ticket_agreement_decision_id DESC LIMIT 1), 0) AS contract_id,
        SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) / 3600 AS hours
        FROM ticket_replies tr INNER JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id
        INNER JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE tr.ticket_reply_archived_at IS NULL AND tr.ticket_reply_created_at >= '$from' AND tr.ticket_reply_created_at < '$to' $scope
        GROUP BY t.ticket_client_id, contract_id", 'Could not allocate agreement labor');
    while ($item = mysqli_fetch_assoc($labor)) {
        $contract_id = intval($item['contract_id']);
        $contract_name = $contract_id ? (string) getFieldById('contracts', $contract_id, 'contract_name') : '';
        $rows[$ensure(intval($item['client_id']), $contract_id, $contract_name)]['labor_hours'] += floatval($item['hours']);
    }

    foreach ($client_revenue as $client_id => $amount) {
        $unallocated = $amount - ($attributed_revenue[$client_id] ?? 0);
        if (abs($unallocated) > .005) {
            $rows[$ensure($client_id, 0)]['revenue'] += $unallocated;
        }
    }

    foreach ($rows as $key => $row) {
        $row['labor_cost'] = floatval($row['labor_hours']) * $labor_rate;
        $row['total_cost'] = floatval($row['expenses']) + floatval($row['subscription_cost']) + floatval($row['labor_cost']);
        $row['margin'] = floatval($row['revenue']) - floatval($row['total_cost']);
        $row['margin_percent'] = floatval($row['revenue']) > 0 ? ($row['margin'] / floatval($row['revenue']) * 100) : null;
        $rows[$key] = $row;
    }
    usort($rows, static fn (array $a, array $b): int => [$a['client_name'], $a['contract_id'] === 0, $a['contract_name']] <=> [$b['client_name'], $b['contract_id'] === 0, $b['contract_name']]);
    return array_values($rows);
}

function commercialBillingCandidates(string $from, string $to, int $client_id = 0): array
{
    global $config_default_hourly_rate;
    $client_scope = $client_id > 0 ? "AND t.ticket_client_id = $client_id" : clientScopeSql('t.ticket_client_id');
    $settings = commercialSettings();
    $increment = intval($settings['commercial_billing_increment_minutes']);
    $labor_cost = floatval($settings['commercial_labor_cost_rate']);
    $candidates = [];
    $tickets = commercialDbQuery("SELECT t.ticket_id, t.ticket_client_id, c.client_name, t.ticket_prefix, t.ticket_number,
        t.ticket_subject, COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at) AS service_date,
        COALESCE((SELECT SUM(TIME_TO_SEC(tr.ticket_reply_time_worked)) FROM ticket_replies tr
            WHERE tr.ticket_reply_ticket_id = t.ticket_id AND tr.ticket_reply_archived_at IS NULL), 0) / 3600 AS hours,
        COALESCE((SELECT d.ticket_agreement_decision_contract_id FROM ticket_agreement_decisions d
            WHERE d.ticket_agreement_decision_ticket_id = t.ticket_id ORDER BY d.ticket_agreement_decision_id DESC LIMIT 1),
            (SELECT ct.contract_id FROM contracts ct WHERE ct.contract_client_id = t.ticket_client_id
                AND ct.contract_status = 'Active' AND ct.contract_archived_at IS NULL
                AND (ct.contract_start_date IS NULL OR ct.contract_start_date <= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                ORDER BY ct.contract_id DESC LIMIT 1), 0) AS contract_id,
        COALESCE((SELECT ct.contract_name FROM ticket_agreement_decisions d LEFT JOIN contracts ct
            ON ct.contract_id = d.ticket_agreement_decision_contract_id WHERE d.ticket_agreement_decision_ticket_id = t.ticket_id
            ORDER BY d.ticket_agreement_decision_id DESC LIMIT 1),
            (SELECT ct.contract_name FROM contracts ct WHERE ct.contract_client_id = t.ticket_client_id
                AND ct.contract_status = 'Active' AND ct.contract_archived_at IS NULL
                AND (ct.contract_start_date IS NULL OR ct.contract_start_date <= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                ORDER BY ct.contract_id DESC LIMIT 1), '') AS contract_name,
        COALESCE((SELECT d.ticket_agreement_decision_classification FROM ticket_agreement_decisions d
            WHERE d.ticket_agreement_decision_ticket_id = t.ticket_id ORDER BY d.ticket_agreement_decision_id DESC LIMIT 1), '') AS agreement_classification,
        COALESCE((SELECT ct.contract_rate_standard FROM ticket_agreement_decisions d INNER JOIN contracts ct
            ON ct.contract_id = d.ticket_agreement_decision_contract_id WHERE d.ticket_agreement_decision_ticket_id = t.ticket_id
            ORDER BY d.ticket_agreement_decision_id DESC LIMIT 1),
            (SELECT ct.contract_rate_standard FROM contracts ct WHERE ct.contract_client_id = t.ticket_client_id
                AND ct.contract_status = 'Active' AND ct.contract_archived_at IS NULL
                AND (ct.contract_start_date IS NULL OR ct.contract_start_date <= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= DATE(COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at)))
                ORDER BY ct.contract_id DESC LIMIT 1), $config_default_hourly_rate) AS rate
        FROM tickets t INNER JOIN clients c ON c.client_id = t.ticket_client_id
        LEFT JOIN billing_review_items bri ON bri.billing_source_type = 'ticket' AND bri.billing_source_id = t.ticket_id
            AND bri.billing_decision IN ('approved','excluded','invoiced')
        WHERE t.ticket_billable = 1 AND t.ticket_invoice_id = 0 AND t.ticket_archived_at IS NULL
        AND (t.ticket_closed_at IS NOT NULL OR t.ticket_resolved_at IS NOT NULL)
        AND COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at) >= '$from'
        AND COALESCE(t.ticket_closed_at, t.ticket_resolved_at, t.ticket_updated_at, t.ticket_created_at) < DATE_ADD('$to', INTERVAL 1 DAY)
        AND bri.billing_review_id IS NULL $client_scope ORDER BY c.client_name, service_date", 'Could not load unbilled ticket candidates');
    while ($row = mysqli_fetch_assoc($tickets)) {
        $qty = commercialRoundHours(floatval($row['hours']), $increment);
        $rate = floatval($row['rate']);
        $candidates[] = [
            'source_type' => 'ticket', 'source_id' => intval($row['ticket_id']), 'client_id' => intval($row['ticket_client_id']),
            'client_name' => $row['client_name'], 'contract_id' => intval($row['contract_id']), 'category_id' => 0,
            'contract_name' => $row['contract_name'], 'agreement_classification' => $row['agreement_classification'],
            'description' => $row['ticket_prefix'] . intval($row['ticket_number']) . ' — ' . $row['ticket_subject'],
            'service_date' => substr((string) $row['service_date'], 0, 10), 'quantity' => $qty,
            'unit_price' => $rate, 'revenue' => round($qty * $rate, 2), 'cost' => round(floatval($row['hours']) * $labor_cost, 2),
        ];
    }

    $expense_scope = $client_id > 0 ? "AND e.expense_client_id = $client_id" : clientScopeSql('e.expense_client_id');
    $expenses = commercialDbQuery("SELECT e.expense_id, e.expense_client_id, e.expense_category_id, e.expense_description,
        e.expense_date, e.expense_amount, c.client_name FROM expenses e
        INNER JOIN clients c ON c.client_id = e.expense_client_id
        LEFT JOIN billing_review_items bri ON bri.billing_source_type = 'expense' AND bri.billing_source_id = e.expense_id
            AND bri.billing_decision IN ('approved','excluded','invoiced')
        WHERE e.expense_client_id > 0 AND e.expense_archived_at IS NULL AND e.expense_date >= '$from' AND e.expense_date <= '$to'
        AND bri.billing_review_id IS NULL $expense_scope ORDER BY c.client_name, e.expense_date", 'Could not load client expense candidates');
    while ($row = mysqli_fetch_assoc($expenses)) {
        $amount = floatval($row['expense_amount']);
        $candidates[] = [
            'source_type' => 'expense', 'source_id' => intval($row['expense_id']), 'client_id' => intval($row['expense_client_id']),
            'client_name' => $row['client_name'], 'contract_id' => 0, 'category_id' => 0,
            'contract_name' => '', 'agreement_classification' => '',
            'description' => 'Pass-through expense — ' . $row['expense_description'], 'service_date' => $row['expense_date'],
            'quantity' => 1, 'unit_price' => $amount, 'revenue' => $amount, 'cost' => $amount,
        ];
    }

    $purchase_scope = $client_id > 0 ? "AND po.purchase_order_client_id = $client_id" : clientScopeSql('po.purchase_order_client_id');
    $purchases = commercialDbQuery("SELECT poi.purchase_item_id, poi.purchase_item_description,
        poi.purchase_item_product_id, poi.purchase_item_quantity_received, poi.purchase_item_unit_cost,
        poi.purchase_item_unit_price, po.purchase_order_client_id, po.purchase_order_number,
        DATE(poi.purchase_item_updated_at) AS service_date, c.client_name, p.product_name,
        COALESCE((SELECT ct.contract_id FROM contracts ct WHERE ct.contract_client_id = po.purchase_order_client_id
            AND ct.contract_status = 'Active' AND ct.contract_archived_at IS NULL
            AND (ct.contract_start_date IS NULL OR ct.contract_start_date <= DATE(poi.purchase_item_updated_at))
            AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= DATE(poi.purchase_item_updated_at))
            ORDER BY ct.contract_id DESC LIMIT 1), 0) AS contract_id,
        COALESCE((SELECT ct.contract_name FROM contracts ct WHERE ct.contract_client_id = po.purchase_order_client_id
            AND ct.contract_status = 'Active' AND ct.contract_archived_at IS NULL
            AND (ct.contract_start_date IS NULL OR ct.contract_start_date <= DATE(poi.purchase_item_updated_at))
            AND (ct.contract_end_date IS NULL OR ct.contract_end_date >= DATE(poi.purchase_item_updated_at))
            ORDER BY ct.contract_id DESC LIMIT 1), '') AS contract_name
        FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.purchase_order_id = poi.purchase_item_order_id
        INNER JOIN clients c ON c.client_id = po.purchase_order_client_id
        INNER JOIN products p ON p.product_id = poi.purchase_item_product_id
        LEFT JOIN billing_review_items bri ON bri.billing_source_type = 'purchase_item' AND bri.billing_source_id = poi.purchase_item_id
            AND bri.billing_decision IN ('approved','excluded','invoiced')
        WHERE po.purchase_order_client_id > 0 AND poi.purchase_item_invoice_id = 0
        AND poi.purchase_item_quantity_received >= poi.purchase_item_quantity_ordered
        AND poi.purchase_item_quantity_received > 0 AND DATE(poi.purchase_item_updated_at) >= '$from'
        AND DATE(poi.purchase_item_updated_at) <= '$to' AND bri.billing_review_id IS NULL $purchase_scope
        ORDER BY c.client_name, poi.purchase_item_updated_at", 'Could not load fulfilled purchase candidates');
    while ($row = mysqli_fetch_assoc($purchases)) {
        $quantity = floatval($row['purchase_item_quantity_received']);
        $unit_price = floatval($row['purchase_item_unit_price']);
        $unit_cost = floatval($row['purchase_item_unit_cost']);
        $candidates[] = [
            'source_type' => 'purchase_item', 'source_id' => intval($row['purchase_item_id']),
            'client_id' => intval($row['purchase_order_client_id']), 'client_name' => $row['client_name'],
            'contract_id' => intval($row['contract_id']), 'category_id' => 0,
            'contract_name' => $row['contract_name'], 'agreement_classification' => 'fulfilled_product',
            'description' => $row['purchase_order_number'] . ' — ' . $row['product_name'],
            'service_date' => $row['service_date'], 'quantity' => $quantity, 'unit_price' => $unit_price,
            'revenue' => round($quantity * $unit_price, 2), 'cost' => round($quantity * $unit_cost, 2),
        ];
    }
    return $candidates;
}

function commercialBillingCandidate(string $type, int $id): ?array
{
    $from = '2000-01-01';
    $to = '2999-12-31';
    foreach (commercialBillingCandidates($from, $to) as $candidate) {
        if ($candidate['source_type'] === $type && intval($candidate['source_id']) === $id) {
            return $candidate;
        }
    }
    return null;
}
