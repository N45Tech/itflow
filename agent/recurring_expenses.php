<?php

// Default Column Sortby/Order Filter
$sort = "recurring_expense_next_date";
$order = "ASC";

require_once "includes/inc_all.php";

// Perms
enforceUserPermission('module_financial');
$can_manage_recurring_expenses = lookupUserPermission('module_financial') >= 2;

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS account_name, category_name, client_name, recurring_expense_amount,
        recurring_expense_currency_code, recurring_expense_description, recurring_expense_frequency,
        recurring_expense_id, recurring_expense_last_sent, recurring_expense_next_date, vendor_name
        FROM recurring_expenses
    LEFT JOIN categories ON recurring_expense_category_id = category_id
    LEFT JOIN vendors ON recurring_expense_vendor_id = vendor_id
    LEFT JOIN accounts ON recurring_expense_account_id = account_id
    LEFT JOIN clients ON recurring_expense_client_id = client_id
    WHERE DATE(recurring_expense_created_at) BETWEEN '$dtf' AND '$dtt'
    AND (vendor_name LIKE '%$q%' OR client_name LIKE '%$q%' OR category_name LIKE '%$q%' OR account_name LIKE '%$q%' OR recurring_expense_description LIKE '%$q%' OR recurring_expense_amount LIKE '%$q%')
    " . clientScopeSql('recurring_expense_client_id') . "
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$new_recurring_expense_action = null;
$recurring_expense_page_actions = array();
if ($can_manage_recurring_expenses) {
    $new_recurring_expense_action = array(
        'type' => 'button',
        'label' => 'New Recurring Expense',
        'icon' => 'fa-plus',
        'variant' => 'primary',
        'class' => 'ajax-modal',
        'attributes' => array(
            'data-modal-url' => 'modals/recurring_expense/recurring_expense_add.php',
            'data-modal-size' => 'lg',
        ),
    );
    $recurring_expense_page_actions[] = $new_recurring_expense_action;
}

$recurring_expense_has_date_filters = !empty($_GET['canned_date'])
    || (isset($_GET['dtf']) && $_GET['dtf'] !== '1970-01-01')
    || (isset($_GET['dtt']) && $_GET['dtt'] !== '2999-12-31');
$recurring_expense_has_filters = $q !== '' || $recurring_expense_has_date_filters;
$recurring_expense_empty_action = $recurring_expense_has_filters
    ? array(
        'label' => 'Clear filters',
        'icon' => 'fa-times',
        'variant' => 'secondary',
        'href' => 'recurring_expenses.php',
    )
    : $new_recurring_expense_action;

?>

<section class="card n45-workspace" aria-labelledby="recurring-expenses-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Recurring Expenses',
        'title_id' => 'recurring-expenses-page-title',
        'icon' => 'fa-redo-alt',
        'actions' => $recurring_expense_page_actions,
    ));
    ?>

    <div class="card-header n45-filter-bar">
        <form autocomplete="off">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search recurring expenses" aria-label="Search recurring expenses">
                        <button class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter" aria-controls="advancedFilter" aria-expanded="<?= $recurring_expense_has_date_filters ? 'true' : 'false' ?>" aria-label="Show recurring expense date filters"><i class="fas fa-filter" aria-hidden="true"></i></button>
                        <button class="btn btn-primary" aria-label="Search recurring expenses"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
            <div class="collapse mt-3 <?= $recurring_expense_has_date_filters ? 'show' : '' ?>" id="advancedFilter">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div>
                            <label class="form-label" for="dateFilter">Date range</label>
                            <input type="text" id="dateFilter" class="form-control" autocomplete="off">
                            <input type="hidden" name="canned_date" id="canned_date" value="<?= escapeHtml($_GET['canned_date'] ?? '') ?>">
                            <input type="hidden" name="dtf" id="dtf" value="<?= escapeHtml($dtf ?? '') ?>">
                            <input type="hidden" name="dtt" id="dtt" value="<?= escapeHtml($dtt ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if ($num_rows[0] == 0) { ?>
        <?php
        n45RenderEmptyState(array(
            'title' => $recurring_expense_has_filters ? 'No recurring expenses match these filters' : 'No recurring expenses yet',
            'description' => $recurring_expense_has_filters
                ? 'Clear the current search or date range to return to the recurring expense list.'
                : 'Create a billing schedule for expenses that should be recorded automatically.',
            'icon' => 'fa-redo-alt',
            'action' => $recurring_expense_empty_action,
        ));
        ?>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
                <thead class="text-nowrap">
                <tr>
                    <th class="ps-3">
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_expense_next_date&order=<?= $disp ?>">
                            Next Date <?php if ($sort == 'recurring_expense_next_date') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=category_name&order=<?= $disp ?>">
                            Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                        </a>
                        /
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_expense_description&order=<?= $disp ?>">
                            Description <?php if ($sort == 'recurring_expense_description') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=vendor_name&order=<?= $disp ?>">
                            Vendor <?php if ($sort == 'vendor_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-end">
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_expense_amount&order=<?= $disp ?>">
                            Amount <?php if ($sort == 'recurring_expense_amount') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_expense_frequency&order=<?= $disp ?>">
                            Frequency <?php if ($sort == 'recurring_expense_frequency') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_expense_last_sent&order=<?= $disp ?>">
                            Last Billed <?php if ($sort == 'recurring_expense_last_sent') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=account_name&order=<?= $disp ?>">
                            Account  <?php if ($sort == 'account_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=client_name&order=<?= $disp ?>">
                            Client  <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php if ($can_manage_recurring_expenses) { ?>
                        <th class="text-center">Actions</th>
                    <?php } ?>
                </tr>
                </thead>
                <tbody>
                <?php

                while ($row = mysqli_fetch_assoc($sql)) {
                    $recurring_expense_id = intval($row['recurring_expense_id']);
                    $recurring_expense_frequency = intval($row['recurring_expense_frequency']);
                    if($recurring_expense_frequency == 1) {
                        $recurring_expense_frequency_display = "Monthly";
                    } else {
                        $recurring_expense_frequency_display = "Annually";
                    }
                    $recurring_expense_last_sent = escapeHtml($row['recurring_expense_last_sent']);
                    if(empty($recurring_expense_last_sent)) {
                        $recurring_expense_last_sent_display = "-";
                    } else {
                        $recurring_expense_last_sent_display = $recurring_expense_last_sent;
                    }
                    $recurring_expense_next_date = escapeHtml($row['recurring_expense_next_date']);
                    $recurring_expense_description = escapeHtml($row['recurring_expense_description']);
                    $recurring_expense_amount = floatval($row['recurring_expense_amount']);
                    $recurring_expense_currency_code = escapeHtml($row['recurring_expense_currency_code']);
                    $vendor_name = escapeHtml($row['vendor_name']);
                    $category_name = escapeHtml($row['category_name']);
                    $account_name = escapeHtml($row['account_name']);
                    $client_name = escapeHtml($row['client_name']);
                    if(empty($client_name)) {
                        $client_name_display = "-";
                    } else {
                        $client_name_display = $client_name;
                    }
                    ?>

                    <tr>
                        <td class="ps-3">
                            <?php if ($can_manage_recurring_expenses) { ?>
                                <a class="text-secondary ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/recurring_expense/recurring_expense_edit.php?id=<?= $recurring_expense_id ?>"><?= $recurring_expense_next_date ?></a>
                            <?php } else { ?>
                                <?= $recurring_expense_next_date ?>
                            <?php } ?>
                        </td>
                        <td>
                            <?= $category_name ?>
                            <div class="text-secondary"><small><?= truncate($recurring_expense_description, 60) ?></small></div>
                        </td>
                        <td><?= $vendor_name ?></td>
                        <td class="text-end font-monospace"><?= numfmt_format_currency($currency_format, $recurring_expense_amount, $recurring_expense_currency_code) ?></td>
                        <td><?= $recurring_expense_frequency_display ?></td>
                        <td><?= $recurring_expense_last_sent_display ?></td>
                        <td><?= $account_name ?></td>
                        <td><?= $client_name_display ?></td>
                        <?php if ($can_manage_recurring_expenses) { ?>
                        <td class="text-center">
                            <div class="dropdown dropstart text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions for recurring expense <?= $recurring_expense_description ?>">
                                    <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#"
                                        data-modal-size="lg"
                                        data-modal-url="modals/recurring_expense/recurring_expense_edit.php?id=<?= $recurring_expense_id ?>">
                                        <i class="fas fa-fw fa-edit me-2" aria-hidden="true"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_recurring_expense=<?= $recurring_expense_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2" aria-hidden="true"></i>Delete
                                    </a>
                                </div>
                            </div>
                        </td>
                        <?php } ?>
                    </tr>

                    <?php

                }

                ?>

                </tbody>
            </table>
        </div>
    <?php } ?>
    <?php require_once "../includes/filter_footer.php"; ?>
</section>

<?php
require_once "../includes/footer.php";
