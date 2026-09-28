<?php

$expense_post = file_get_contents(dirname(__DIR__) . '/agent/post/expense.php');

$add_start = strpos($expense_post, "if (isset(\$_POST['add_expense']))");
$edit_start = strpos($expense_post, "if (isset(\$_POST['edit_expense']))");
$delete_start = strpos($expense_post, "if (isset(\$_GET['delete_expense']))");

if ($add_start === false || $edit_start === false || $delete_start === false) {
    fwrite(STDERR, "Unable to locate the expense handlers.\n");
    exit(1);
}

$add_handler = substr($expense_post, $add_start, $edit_start - $add_start);
$add_access = strpos($add_handler, 'enforceClientAccess();');
$add_insert = strpos($add_handler, 'INSERT INTO expenses');

if ($add_access === false || $add_insert === false || $add_access > $add_insert) {
    fwrite(STDERR, "Client access must be enforced before creating an expense.\n");
    exit(1);
}

$edit_handler = substr($expense_post, $edit_start, $delete_start - $edit_start);
$existing_client_lookup = strpos($edit_handler, "getFieldById('expenses', \$expense_id, 'expense_client_id')");
$existing_client_access = strpos($edit_handler, 'enforceClientAccess($existing_client_id);');
$destination_client_access = strpos($edit_handler, 'enforceClientAccess($client_id);');
$receipt_lookup = strpos($edit_handler, "getFieldById('expenses', \$expense_id, 'expense_receipt')");
$expense_update = strpos($edit_handler, 'UPDATE expenses SET expense_date');

if (
    $existing_client_lookup === false
    || $existing_client_access === false
    || $destination_client_access === false
    || $receipt_lookup === false
    || $expense_update === false
    || $existing_client_lookup > $existing_client_access
    || $existing_client_access > $receipt_lookup
    || $destination_client_access > $receipt_lookup
    || $existing_client_access > $expense_update
    || $destination_client_access > $expense_update
) {
    fwrite(STDERR, "Existing and destination client access must be enforced before editing an expense.\n");
    exit(1);
}

echo "Expense client access test passed.\n";
