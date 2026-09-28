<?php

/*
 * API - Invoice Items - Create
 * POST /api/v1/invoice_items/create.php
 *
 * Adds a line item to an existing invoice.
 *
 * Parameters (POST, JSON body):
 *   api_key          required - Your API key
 *   client_id        required - Must match the invoice's client (restricted
 *                               keys only; unrestricted/admin keys may omit)
 *   invoice_id       required - Invoice to add the item to
 *   name             required - Item name
 *   description      optional - Item description
 *   qty              required - Quantity
 *   price            required - Unit price
 *   tax_id           optional - Tax ID (default 0)
 *   item_order       optional - Display order (default 0)
 *   product_id       optional - Product ID for inventory tracking
 *
 * Security:
 *   - The parent invoice is loaded through apiClientScopeSql(), so a restricted
 *     key cannot modify another client's invoice.
 *   - The supplied client_id must match the invoice's client.
 *   - Inventory is only adjusted for tangible products.
 *   - Invoice totals are automatically recalculated.
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse input
$invoice_id = intval($_POST['invoice_id'] ?? 0);
$name = escapeSql(substr($_POST['name'], 0, 200) ?? '');
$description = escapeSql($_POST['description'] ?? '');
$qty = floatval($_POST['qty'] ?? 0);
$price = floatval($_POST['price'] ?? 0);
$tax_id = intval($_POST['tax_id'] ?? 0);
$item_order = intval($_POST['item_order'] ?? 0);
$product_id = intval($_POST['product_id'] ?? 0);

$insert_id = false;
$transaction_started = false;

// invoice_items uses DECIMAL(15,2); reject non-numeric, infinite, and values
// which cannot be represented before any inventory or invoice write occurs.
$maximum_decimal_value = 9999999999999.99;
$numeric_input_valid = is_numeric($_POST['qty'] ?? null)
    && is_numeric($_POST['price'] ?? null)
    && is_finite($qty)
    && is_finite($price)
    && $qty > 0
    && $qty <= $maximum_decimal_value
    && abs($price) <= $maximum_decimal_value;

if (
    !empty($invoice_id)
    && !empty($name)
    && $numeric_input_valid
) {

    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the invoice item transaction');
        }
        $transaction_started = true;

        // Load and lock the invoice through the API key's client scope.
        $invoice_sql = mysqli_query(
            $mysqli,
            "SELECT *
             FROM invoices
             WHERE invoice_id = $invoice_id
             AND invoice_status != 'Paid'
               AND 1=1 " . apiClientScopeSql('invoice_client_id') . "
             LIMIT 1
             FOR UPDATE"
        );

    $invoice_row = $invoice_sql ? mysqli_fetch_assoc($invoice_sql) : null;

    // Ensure supplied client matches invoice client
    if ($invoice_row && $client_id != 0 && intval($invoice_row['invoice_client_id']) !== $client_id) {
        $invoice_row = null;
    }

    if ($invoice_row) {

        $client_id = intval($invoice_row['invoice_client_id']);
        $invoice_prefix = escapeSql($invoice_row['invoice_prefix']);
        $invoice_number = intval($invoice_row['invoice_number']);
        $invoice_discount = floatval($invoice_row['invoice_discount_amount']);

        $subtotal = $price * $qty;

        if (!is_finite($subtotal) || abs($subtotal) > $maximum_decimal_value) {
            throw new RuntimeException('Invoice item subtotal is outside the supported range');
        }

        // Tax is calculated before inventory is changed, so all derived values
        // can be validated before the first business-data write.
        if ($tax_id > 0) {

            $tax_sql = mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = $tax_id");
            if (!$tax_sql) {
                throw new RuntimeException('Could not load the invoice item tax');
            }
            $tax_row = mysqli_fetch_assoc($tax_sql);

            $tax_percent = floatval($tax_row['tax_percent'] ?? 0);
            $tax_amount = $subtotal * $tax_percent / 100;

        } else {

            $tax_amount = 0;

        }

        $total = $subtotal + $tax_amount;

        if (!is_finite($tax_amount) || !is_finite($total)
            || abs($tax_amount) > $maximum_decimal_value
            || abs($total) > $maximum_decimal_value) {
            throw new RuntimeException('Invoice item totals are outside the supported range');
        }

        // Product inventory
        if ($product_id) {

            // Lock the product row to serialize stock checks for this endpoint.
            $product_sql = mysqli_query(
                $mysqli,
                "SELECT product_type FROM products WHERE product_id = $product_id FOR UPDATE"
            );
            if (!$product_sql) {
                throw new RuntimeException('Could not lock the invoice item product');
            }
            $product_row = mysqli_fetch_assoc($product_sql);
            $product_type = $product_row['product_type'] ?? '';

            if ($product_type === 'product') {

                if (floor($qty) !== $qty || $qty > 2147483647) {
                    throw new RuntimeException('Product stock quantity is outside the supported range');
                }

                $stock_sql = mysqli_query(
                    $mysqli,
                    "SELECT COALESCE(SUM(stock_qty),0) AS available_stock
                     FROM product_stock
                     WHERE stock_product_id = $product_id"
                );

                if (!$stock_sql) {
                    throw new RuntimeException('Could not read available product stock');
                }

                $stock_row = mysqli_fetch_assoc($stock_sql);
                $available_stock = floatval($stock_row['available_stock']);

                if ($available_stock >= $qty) {

                    $stock_insert_sql = mysqli_query(
                        $mysqli,
                        "INSERT INTO product_stock
                         SET stock_qty = -$qty,
                             stock_note = 'QTY $qty - Invoice $invoice_id',
                             stock_product_id = $product_id"
                    );

                    if (!$stock_insert_sql) {
                        throw new RuntimeException('Could not reserve product stock');
                    }

                } else {

                    mysqli_rollback($mysqli);
                    $transaction_started = false;

                    logAudit(
                        "API",
                        "Failure",
                        "Failed adding item $name to invoice $invoice_prefix$invoice_number via API ($api_key_name) due to insufficient stock",
                        $client_id
                    );

                    require_once '../create_output.php';
                    exit;

                }

            }

        }

        $insert_sql = mysqli_query(
            $mysqli,
            "INSERT INTO invoice_items SET
                item_name = '$name',
                item_description = '$description',
                item_quantity = $qty,
                item_price = $price,
                item_subtotal = $subtotal,
                item_tax = $tax_amount,
                item_total = $total,
                item_order = $item_order,
                item_tax_id = $tax_id,
                item_product_id = $product_id,
                item_invoice_id = $invoice_id"
        );

        if (!$insert_sql) {
            throw new RuntimeException('Could not insert the invoice item');
        }

        $insert_id = mysqli_insert_id($mysqli);

        // Recalculate invoice total
        $items_sql = mysqli_query(
                $mysqli,
                "SELECT SUM(item_total) AS invoice_total
                 FROM invoice_items
                 WHERE item_invoice_id = $invoice_id"
            );

        if (!$items_sql) {
            throw new RuntimeException('Could not recalculate the invoice total');
        }

        $items_row = mysqli_fetch_assoc($items_sql);
        $invoice_total = floatval($items_row['invoice_total']);

        $new_invoice_amount = $invoice_total - $invoice_discount;

        $update_sql = mysqli_query(
                $mysqli,
                "UPDATE invoices
                 SET invoice_amount = $new_invoice_amount
                 WHERE invoice_id = $invoice_id
                 LIMIT 1"
            );

        if (!$update_sql) {
            throw new RuntimeException('Could not update the invoice total');
        }

        if (!logAudit(
                "Invoice",
                "Edit",
                "Added item $name to invoice $invoice_prefix$invoice_number via API ($api_key_name)",
                $client_id,
                $invoice_id
        )) {
            throw new RuntimeException('Could not audit the invoice item');
        }

        if (!logAudit(
                "API",
                "Success",
                "Added item $name to invoice $invoice_prefix$invoice_number via API ($api_key_name)",
                $client_id
        )) {
            throw new RuntimeException('Could not audit the API invoice item');
        }

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the invoice item transaction');
        }
        $transaction_started = false;

    }

        if ($transaction_started) {
            mysqli_rollback($mysqli);
            $transaction_started = false;
        }

    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        $insert_id = false;
        error_log('API invoice item creation failed: ' . $exception->getMessage());
    }

}

// Output
require_once '../create_output.php';
