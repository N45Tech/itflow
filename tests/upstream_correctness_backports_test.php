<?php

function n45BackportRead(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Could not read $path");
    }
    return $contents;
}

function n45BackportContains(string $contents, string $needle, string $message): void
{
    if (!str_contains($contents, $needle)) {
        throw new RuntimeException($message);
    }
}

function n45BackportNotContains(string $contents, string $needle, string $message): void
{
    if (str_contains($contents, $needle)) {
        throw new RuntimeException($message);
    }
}

function n45BackportOrdered(string $contents, array $needles, string $message): void
{
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $position + 1);
        if ($next === false) {
            throw new RuntimeException($message . " (missing or out of order: $needle)");
        }
        $position = $next;
    }
}

$root = dirname(__DIR__);
$portal_functions = n45BackportRead($root, 'client/functions.php');
$portal_profile = n45BackportRead($root, 'client/profile.php');
$portal_post = n45BackportRead($root, 'client/post.php');
$asset_delete = n45BackportRead($root, 'api/v1/assets/delete.php');
$client_model = n45BackportRead($root, 'api/v1/clients/client_model.php');
$api_rbac = n45BackportRead($root, 'api/v1/enforce_api_rbac.php');
$payment_post = n45BackportRead($root, 'agent/post/payment.php');
$invoice = n45BackportRead($root, 'agent/invoice.php');
$logging = n45BackportRead($root, 'functions/logging.php');
$saved_payment = n45BackportRead($root, 'admin/post/saved_payment_method.php');
$payment_add_modal = n45BackportRead($root, 'agent/modals/payment/payment_add.php');

n45BackportContains($portal_functions, 'function portalReauthenticate($current_password)',
    'Portal account changes must share a reauthentication helper');
n45BackportContains($portal_functions, 'password_verify($current_password',
    'Portal reauthentication must verify the current password hash');
n45BackportContains($portal_profile, 'name="current_password"',
    'The portal password form must request the current password');
n45BackportOrdered($portal_post, [
    "if (!portalReauthenticate(\$_POST['current_password'] ?? ''))",
    'strlen($new_password) < 8',
    'password_hash($new_password, PASSWORD_DEFAULT)',
], 'Portal password changes must reauthenticate and validate before hashing');
n45BackportNotContains($portal_post, 'removeEmoji(',
    'Portal ticket notification must not call the removed removeEmoji helper');
n45BackportNotContains($portal_post, '$session_name',
    'Portal audit events must use the authenticated contact name');

n45BackportOrdered($asset_delete, [
    "\$asset_name = escapeSql(\$row['asset_name'] ?? '')",
    'DELETE FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id LIMIT 1',
    'if ($delete_count === 1)',
    'DELETE FROM asset_interfaces WHERE interface_asset_id = $asset_id',
], 'Asset API must not delete interfaces when the scoped parent delete fails');
n45BackportContains($logging, "foreach (['type', 'details', 'action'] as \$field)",
    'Notification logging must defend against an odd trailing escape after truncation');
n45BackportContains($logging, "foreach (['type', 'action', 'description'] as \$field)",
    'Audit logging must defend against an odd trailing escape after truncation');
n45BackportContains($saved_payment, '$payment_method_esc = escapeSql($payment_method)',
    'Saved payment identifiers must be SQL-safe before audit logging');

n45BackportContains($client_model, "elseif (isset(\$_POST['client_lead']))",
    'Client API must accept its canonical client_lead field');
n45BackportContains($client_model, "intval(\$client_row['client_lead'])",
    'Client API partial updates must retain the stored lead state');
n45BackportContains($api_rbac, '$client_id_supplied = isset($_POST[\'client_id\']) || isset($_GET[\'client_id\'])',
    'API reads must distinguish an omitted client filter from client_id zero');
n45BackportOrdered($api_rbac, [
    '$sql = clientScopeSql($column)',
    'if (!empty($client_id_supplied) && empty($is_write))',
    '$sql .= " AND $column = " . intval($client_id)',
], 'API client_id reads must narrow the user scope instead of replacing it');

$delete_payment_at = strpos($payment_post, "if (isset(\$_GET['delete_payment']))");
$delete_payment = $delete_payment_at === false ? '' : substr($payment_post, $delete_payment_at);
n45BackportContains($delete_payment, "enforceUserPermission('module_sales', 3)",
    'Deleting a payment requires Full Sales permission');
n45BackportContains($delete_payment, "enforceUserPermission('module_financial', 3)",
    'Deleting a payment requires Full Financial permission');
n45BackportContains($invoice, 'lookupUserPermission("module_sales") >= 3 && lookupUserPermission("module_financial") >= 3',
    'Payment deletion controls must only render for users with both Full permissions');
n45BackportContains($payment_add_modal, 'invoice_currency_code, invoice_id',
    'Payment entry must use the immutable invoice currency, not the mutable client default');
n45BackportContains($delete_payment, "if (\$payment_method === 'Stripe')",
    'Manual Stripe-refund warning must not be shown for non-Stripe payments');

echo "Compatible upstream correctness backport contracts passed.\n";
