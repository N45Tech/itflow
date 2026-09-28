<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_financial', 2);

$client_id = intval($_GET['client_id'] ?? 0);

ob_start();

?>
<?php n45RenderModalHeader('Create Recurring Expense', 'fa-clock'); ?>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="row g-2">

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-frequency">Frequency <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-sync-alt" aria-hidden="true"></i></span>
                    <select class="form-select select2" id="recurring-expense-frequency" name="frequency" required>
                        <option value="1">Monthly</option>
                        <option value="2">Annually</option>
                    </select>
                </div>
            </div>

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-month">Month <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar" aria-hidden="true"></i></span>
                    <select class="form-select select2" id="recurring-expense-month" name="month" required>
                        <option value="">- Select a Month -</option>
                        <option value="1">01 - January</option>
                        <option value="2">02 - February</option>
                        <option value="3">03 - March</option>
                        <option value="4">04 - April</option>
                        <option value="5">05 - May</option>
                        <option value="6">06 - June</option>
                        <option value="7">07 - July</option>
                        <option value="8">08 - August</option>
                        <option value="9">09 - September</option>
                        <option value="10">10 - October</option>
                        <option value="11">11 - November</option>
                        <option value="12">12 - December</option>
                    </select>
                </div>
            </div>

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-day">Day <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="recurring-expense-day" inputmode="numeric" pattern="(1[0-9]|2[0-8]|[1-9])" name="day" placeholder="Enter a day (1-28)" required>
                </div>
            </div>

        </div>

        <div class="row g-2">
            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-amount">Amount <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="recurring-expense-amount" inputmode="decimal" pattern="-?[0-9]*\.?[0-9]{0,2}" name="amount" placeholder="0.00" required>
                </div>
            </div>
        </div>

        <div class="row g-2">

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-account">Account <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-piggy-bank" aria-hidden="true"></i></span>
                    <select class="form-select select2" id="recurring-expense-account" name="account" required>
                        <option value="">- Account -</option>
                        <?php

                        $sql_accounts = mysqli_query(
                            $mysqli,
                            "SELECT accounts.account_id, accounts.account_name, accounts.opening_balance,
                                COALESCE(payment_totals.total_payments, 0) AS total_payments,
                                COALESCE(revenue_totals.total_revenues, 0) AS total_revenues,
                                COALESCE(expense_totals.total_expenses, 0) AS total_expenses
                            FROM accounts
                            LEFT JOIN (
                                SELECT payment_account_id, SUM(payment_amount) AS total_payments
                                FROM payments GROUP BY payment_account_id
                            ) payment_totals ON payment_totals.payment_account_id = accounts.account_id
                            LEFT JOIN (
                                SELECT revenue_account_id, SUM(revenue_amount) AS total_revenues
                                FROM revenues GROUP BY revenue_account_id
                            ) revenue_totals ON revenue_totals.revenue_account_id = accounts.account_id
                            LEFT JOIN (
                                SELECT expense_account_id, SUM(expense_amount) AS total_expenses
                                FROM expenses GROUP BY expense_account_id
                            ) expense_totals ON expense_totals.expense_account_id = accounts.account_id
                            WHERE accounts.account_archived_at IS NULL
                            ORDER BY accounts.account_name ASC"
                        );
                        while ($row = mysqli_fetch_assoc($sql_accounts)) {
                            $account_id = intval($row['account_id']);
                            $account_name = escapeHtml($row['account_name']);
                            $opening_balance = floatval($row['opening_balance']);
                            $total_payments = floatval($row['total_payments']);
                            $total_revenues = floatval($row['total_revenues']);
                            $total_expenses = floatval($row['total_expenses']);

                            $balance = $opening_balance + $total_payments + $total_revenues - $total_expenses;

                            ?>
                            <option <?php if ($config_default_expense_account == $account_id) { echo "selected"; } ?> value="<?= $account_id ?>"><?= $account_name ?> [$<?= number_format($balance, 2) ?>]</option>

                            <?php
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-vendor">Vendor <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-building" aria-hidden="true"></i></span>
                    <select class="form-select select2" id="recurring-expense-vendor" name="vendor" required>
                        <option value="">- Vendor -</option>
                        <?php

                        $sql = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_name ASC");
                        while ($row = mysqli_fetch_assoc($sql)) {
                            $vendor_id = intval($row['vendor_id']);
                            $vendor_name = escapeHtml($row['vendor_name']);
                            ?>
                            <option value="<?= $vendor_id ?>"><?= $vendor_name ?></option>

                            <?php
                        }
                        ?>
                    </select>
                    <a class="btn btn-secondary" href="vendors.php" target="_blank" rel="noopener" aria-label="Open vendors in a new tab"><i class="fas fa-fw fa-plus" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="recurring-expense-description">Description <strong class="text-danger">*</strong></label>
            <textarea class="form-control" id="recurring-expense-description" rows="6" name="description" placeholder="Enter a description" required></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label" for="recurring-expense-reference">Reference</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-fw fa-file-alt" aria-hidden="true"></i></span>
                <input type="text" class="form-control" id="recurring-expense-reference" name="reference" placeholder="Enter a reference" maxlength="200">
            </div>
        </div>

        <div class="row g-2">

            <div class="mb-3 col-md">
                <label class="form-label" for="recurring-expense-category">Category <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-list" aria-hidden="true"></i></span>
                    <select class="form-select select2" id="recurring-expense-category" name="category" required>
                        <option value="">- Category -</option>
                        <?php

                        $sql = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Expense' AND category_archived_at IS NULL ORDER BY category_name ASC");
                        while ($row = mysqli_fetch_assoc($sql)) {
                            $category_id = intval($row['category_id']);
                            $category_name = escapeHtml($row['category_name']);
                            ?>
                            <option value="<?= $category_id ?>"><?= $category_name ?></option>

                            <?php
                        }
                        ?>
                    </select>
                        <button class="btn btn-secondary ajax-modal" type="button"
                            aria-label="Add expense category"
                            data-modal-url="../admin/modals/category/category_add.php?category=Expense">
                            <i class="fas fa-plus" aria-hidden="true"></i>
                        </button>
                </div>


            </div>

            <?php if ($client_id) { ?>
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <?php } else { ?>

                <div class="mb-3 col-md">
                    <label class="form-label" for="recurring-expense-client">Client</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-user" aria-hidden="true"></i></span>
                        <select class="form-select select2" id="recurring-expense-client" name="client_id">
                            <option value="0">- Client (Optional) -</option>
                            <?php

                            $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL " . clientScopeSql('clients.client_id') . " ORDER BY client_name ASC");
                            while ($row = mysqli_fetch_assoc($sql)) {
                                $client_id_select = intval($row['client_id']);
                                $client_name = escapeHtml($row['client_name']);
                                ?>
                                <option value="<?= $client_id_select ?>"><?= $client_name ?></option>

                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

            <?php } ?>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="create_recurring_expense" class="btn btn-primary text-bold"><i class="fa fa-fw fa-check me-2" aria-hidden="true"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2" aria-hidden="true"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
