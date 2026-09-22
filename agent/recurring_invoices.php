<?php

// Default Column Sortby/Order Filter
$sort = "recurring_invoice_next_date";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND recurring_invoice_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_sales');

// Status Filter
if (isset($_GET['status']) && $_GET['status'] == "inactive") {
    $status_filter = "inactive";
    $status_query = "AND recurring_invoice_status = 0";
} else {
    $status_filter = "active";
    $status_query = "AND recurring_invoice_status = 1";
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS category_id, category_name, client_currency_code, client_id, client_name,
        recurring_invoice_amount, recurring_invoice_created_at, recurring_invoice_currency_code,
        recurring_invoice_discount_amount, recurring_invoice_frequency, recurring_invoice_id,
        recurring_invoice_last_sent, recurring_invoice_next_date, recurring_invoice_number,
        recurring_invoice_prefix, recurring_invoice_scope, recurring_invoice_status,
        recurring_payment_id, recurring_payment_recurring_invoice_id,
        recurring_payment_saved_payment_id FROM recurring_invoices
    LEFT JOIN clients ON recurring_invoice_client_id = client_id
    LEFT JOIN categories ON recurring_invoice_category_id = category_id
    LEFT JOIN recurring_payments ON recurring_payment_recurring_invoice_id = recurring_invoice_id
    WHERE (CONCAT(recurring_invoice_prefix,recurring_invoice_number) LIKE '%$q%' OR recurring_invoice_frequency LIKE '%$q%' OR recurring_invoice_scope LIKE '%$q%' OR client_name LIKE '%$q%' OR category_name LIKE '%$q%')
    AND DATE(recurring_invoice_created_at) BETWEEN '$dtf' AND '$dtt'
    $status_query
    $client_query
    " . clientScopeSql('recurring_invoice_client_id') . "

    ORDER BY $sort $order LIMIT $record_from, $record_to");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$recurring_invoice_rows = mysqli_fetch_all($sql, MYSQLI_ASSOC);
$saved_payments_by_client = array();
$recurring_invoice_client_ids = array();
foreach ($recurring_invoice_rows as $recurring_invoice_row) {
    $row_client_id = intval($recurring_invoice_row['client_id']);
    if ($row_client_id > 0) {
        $recurring_invoice_client_ids[$row_client_id] = $row_client_id;
    }
}

if ($recurring_invoice_client_ids) {
    $saved_payment_client_id_list = implode(',', array_values($recurring_invoice_client_ids));
    $sql_saved_payments = mysqli_query(
        $mysqli,
        "SELECT saved_payment_client_id, saved_payment_description, saved_payment_id
        FROM client_saved_payment_methods
        WHERE saved_payment_client_id IN ($saved_payment_client_id_list)
        ORDER BY saved_payment_description ASC"
    );
    if ($sql_saved_payments) {
        while ($saved_payment = mysqli_fetch_assoc($sql_saved_payments)) {
            $saved_payment_client_id = intval($saved_payment['saved_payment_client_id']);
            $saved_payments_by_client[$saved_payment_client_id][] = array(
                'id' => intval($saved_payment['saved_payment_id']),
                'description' => escapeHtml($saved_payment['saved_payment_description']),
            );
        }
    }
}

$recurring_invoice_page_actions = array();
$new_recurring_invoice_action = null;
if (lookupUserPermission("module_sales") >= 2) {
    $new_recurring_invoice_action = array(
        'type' => 'button',
        'label' => 'New Recurring Invoice',
        'icon' => 'fa-plus',
        'variant' => 'primary',
        'class' => 'ajax-modal',
        'attributes' => array(
            'data-modal-url' => 'modals/recurring_invoice/recurring_invoice_add.php?' . $client_url,
        ),
    );
    if ($num_rows[0] > 0) {
        $recurring_invoice_page_actions[] = array(
            'type' => 'split-menu',
            'button' => $new_recurring_invoice_action,
            'items' => array(
                array(
                    'label' => 'Export',
                    'icon' => 'fa-download',
                    'class' => 'ajax-modal',
                    'attributes' => array(
                        'data-modal-url' => buildExportModalUrl('modals/recurring_invoice/recurring_invoice_export.php', array('client_id', 'status', 'q'), array('dtf' => $dtf, 'dtt' => $dtt)),
                    ),
                ),
            ),
            'menu_label' => 'More recurring invoice actions',
            'align_end' => true,
        );
    } else {
        $recurring_invoice_page_actions[] = $new_recurring_invoice_action;
    }
}

$recurring_invoice_tabs = array(
    array(
        'label' => 'Active',
        'icon' => 'fa-check',
        'href' => '?' . $client_url . 'status=active',
        'active' => $status_filter === 'active',
    ),
    array(
        'label' => 'Inactive',
        'icon' => 'fa-ban',
        'href' => '?' . $client_url . 'status=inactive',
        'active' => $status_filter === 'inactive',
    ),
);

$recurring_invoice_has_date_filters = !empty($_GET['canned_date'])
    || (isset($_GET['dtf']) && $_GET['dtf'] !== '1970-01-01')
    || (isset($_GET['dtt']) && $_GET['dtt'] !== '2999-12-31');
$recurring_invoice_has_filters = $q !== '' || $recurring_invoice_has_date_filters;
$recurring_invoice_clear_href = 'recurring_invoices.php?' . $client_url . 'status=' . $status_filter;
$recurring_invoice_empty_action = null;
if ($recurring_invoice_has_filters) {
    $recurring_invoice_empty_action = array(
        'label' => 'Clear filters',
        'icon' => 'fa-times',
        'variant' => 'secondary',
        'href' => $recurring_invoice_clear_href,
    );
} elseif ($status_filter === 'inactive') {
    $recurring_invoice_empty_action = array(
        'label' => 'View active invoices',
        'icon' => 'fa-check',
        'variant' => 'secondary',
        'href' => '?' . $client_url . 'status=active',
    );
} elseif ($new_recurring_invoice_action) {
    $recurring_invoice_empty_action = $new_recurring_invoice_action;
}

?>

<section class="card n45-workspace" aria-labelledby="recurring-invoices-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Recurring Invoices',
        'title_id' => 'recurring-invoices-page-title',
        'icon' => 'fa-redo-alt',
        'context' => $client_url ? array(
            'label' => $tab_title,
            'href' => 'client_overview.php?client_id=' . $client_id,
        ) : array(),
        'tabs' => $recurring_invoice_tabs,
        'tabs_label' => 'Recurring invoice status',
        'actions' => $recurring_invoice_page_actions,
    ));
    ?>

    <div class="card-header n45-filter-bar">
        <form autocomplete="off">
            <?php if ($client_url) { ?>
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <?php } ?>
            <input type="hidden" name="status" value="<?= $status_filter ?>">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search recurring invoices" aria-label="Search recurring invoices">
                        <button class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter" aria-controls="advancedFilter" aria-expanded="<?= $recurring_invoice_has_date_filters ? 'true' : 'false' ?>" aria-label="Show recurring invoice date filters"><i class="fas fa-filter" aria-hidden="true"></i></button>
                        <button class="btn btn-primary" aria-label="Search recurring invoices"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
            <div class="collapse mt-3 <?= $recurring_invoice_has_date_filters ? 'show' : '' ?>" id="advancedFilter">
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
        if ($recurring_invoice_has_filters) {
            $recurring_invoice_empty_title = 'No recurring invoices match these filters';
            $recurring_invoice_empty_description = 'Clear the current search or date range to return to the recurring invoice list.';
        } elseif ($status_filter === 'inactive') {
            $recurring_invoice_empty_title = 'No inactive recurring invoices';
            $recurring_invoice_empty_description = 'There are no inactive billing schedules for this view.';
        } else {
            $recurring_invoice_empty_title = 'No recurring invoices yet';
            $recurring_invoice_empty_description = 'Create a billing schedule for work that should invoice automatically.';
        }
        n45RenderEmptyState(array(
            'title' => $recurring_invoice_empty_title,
            'description' => $recurring_invoice_empty_description,
            'icon' => 'fa-redo-alt',
            'action' => $recurring_invoice_empty_action,
        ));
        ?>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
            <thead class="text-nowrap">
            <tr>
                <th class="ps-3">
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_number&order=<?= $disp ?>">
                        Number <?php if ($sort == 'recurring_invoice_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_next_date&order=<?= $disp ?>">
                        Next Date <?php if ($sort == 'recurring_invoice_next_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_scope&order=<?= $disp ?>">
                        Scope <?php if ($sort == 'recurring_invoice_scope') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php if (!$client_url) { ?>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=client_name&order=<?= $disp ?>">
                        Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php } ?>
                <th class="text-end">
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_amount&order=<?= $disp ?>">
                        Amount <?php if ($sort == 'recurring_invoice_amount') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_frequency&order=<?= $disp ?>">
                        Frequency <?php if ($sort == 'recurring_invoice_frequency') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_last_sent&order=<?= $disp ?>">
                        Last Sent <?php if ($sort == 'recurring_invoice_last_sent') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=category_name&order=<?= $disp ?>">
                        Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_payment_recurring_invoice_id&order=<?= $disp ?>">
                        Auto Pay <?php if ($sort == 'recurring_payment_recurring_invoice_id') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_invoice_status&order=<?= $disp ?>">
                        Status <?php if ($sort == 'recurring_invoice_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            foreach ($recurring_invoice_rows as $row) {
                $recurring_invoice_id = intval($row['recurring_invoice_id']);
                $recurring_invoice_prefix = escapeHtml($row['recurring_invoice_prefix']);
                $recurring_invoice_number = intval($row['recurring_invoice_number']);
                $recurring_invoice_scope = escapeHtml($row['recurring_invoice_scope']);
                $recurring_invoice_scope_display = $recurring_invoice_scope !== '' ? $recurring_invoice_scope : '-';
                $recurring_invoice_frequency = escapeHtml($row['recurring_invoice_frequency']);
                $recurring_invoice_status = escapeHtml($row['recurring_invoice_status']);
                $recurring_invoice_discount = floatval($row['recurring_invoice_discount_amount']);
                $recurring_invoice_last_sent = $row['recurring_invoice_last_sent'];
                if ($recurring_invoice_last_sent == 0) {
                    $recurring_invoice_last_sent = "-";
                }
                $recurring_invoice_next_date = escapeHtml($row['recurring_invoice_next_date']);
                $recurring_invoice_amount = floatval($row['recurring_invoice_amount']);
                $recurring_invoice_currency_code = escapeHtml($row['recurring_invoice_currency_code']);
                $recurring_invoice_created_at = escapeHtml($row['recurring_invoice_created_at']);
                $client_id = intval($row['client_id']);
                $client_name = escapeHtml($row['client_name']);
                $client_currency_code = escapeHtml($row['client_currency_code']);
                $category_id = intval($row['category_id']);
                $category_name = escapeHtml($row['category_name']);
                if ($recurring_invoice_status == 1) {
                    $status = "Active";
                    $status_badge_color = "success";
                } else {
                    $status = "Inactive";
                    $status_badge_color = "secondary";
                }
                $recurring_payment_id = intval($row['recurring_payment_id']);
                $recurring_payment_recurring_invoice_id = intval($row['recurring_payment_recurring_invoice_id']);
                $recurring_payment_saved_payment_id = intval($row['recurring_payment_saved_payment_id']);
                $saved_payments = $saved_payments_by_client[$client_id] ?? array();

                ?>

                <tr>
                    <td class="text-bold ps-3">
                        <a href="recurring_invoice.php?client_id=<?= $client_id ?>&recurring_invoice_id=<?= $recurring_invoice_id ?>">
                            <?= "$recurring_invoice_prefix$recurring_invoice_number" ?>
                        </a>
                    </td>
                    <td class="text-bold"><?= $recurring_invoice_next_date ?></td>
                    <td><?= $recurring_invoice_scope_display ?></td>
                    <?php if (!$client_url) { ?>
                    <td class="text-bold"><a href="recurring_invoices.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                    <?php } ?>
                    <td class="text-end font-monospace"><?= numfmt_format_currency($currency_format, $recurring_invoice_amount, $recurring_invoice_currency_code) ?></td>
                    <td><?= ucwords($recurring_invoice_frequency) ?>ly</td>
                    <td><?= $recurring_invoice_last_sent ?></td>
                    <td><?= $category_name ?></td>
                    <td>
                        <?php if ($saved_payments) { ?>
                            <form class="form" action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="set_recurring_payment" value="1">
                                <input type="hidden" name="recurring_invoice_id" value="<?= $recurring_invoice_id ?>">
                                <select class="form-select select2" name="saved_payment_id" onchange="this.form.submit()" aria-label="Automatic payment method for recurring invoice <?= "$recurring_invoice_prefix$recurring_invoice_number" ?>">
                                    <option value="0">Disabled</option>
                                    <?php foreach ($saved_payments as $saved_payment) { ?>
                                        <option <?php if ($recurring_payment_saved_payment_id == $saved_payment['id']) { echo "selected"; } ?> value="<?= $saved_payment['id'] ?>"><?= $saved_payment['description'] ?></option>
                                    <?php } ?>
                                </select>
                            </form>
                        <?php } else { ?>
                            <span class="text-secondary">No cards on file</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php n45RenderStatusBadge($status, $status_badge_color); ?>
                    </td>
                    <td>
                        <div class="dropdown dropstart text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions for recurring invoice <?= "$recurring_invoice_prefix$recurring_invoice_number" ?>">
                                <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_invoice/recurring_invoice_edit.php?id=<?= $recurring_invoice_id ?>">
                                    <i class="fas fa-fw fa-edit me-2"></i>Edit
                                </a>
                                <?php if ($status !== 'Active') { ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_recurring_invoice=<?= $recurring_invoice_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </td>
                </tr>

                <?php
                }
                ?>

            </tbody>
        </table>
    </div>
    <?php } ?>
    <?php require_once "../includes/filter_footer.php";
 ?>
</section>

<?php
require_once "../includes/footer.php";
