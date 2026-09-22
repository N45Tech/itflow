<?php

// Default Column Sortby Filter
$sort = "recurring_ticket_next_run";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND recurring_ticket_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

// Category Filter
if (isset($_GET['category']) && !empty($_GET['category'])) {
    $category_query = 'AND (category_id = ' . intval($_GET['category']) . ')';
    $category_filter = intval($_GET['category']);
} else {
    // Default - any
    $category_query = '';
    $category_filter = '';
}

// Assigned Agent Filter
if (isset($_GET['assigned_agent']) && !empty($_GET['assigned_agent'])) {
    $assigned_agent_query = 'AND (user_id = ' . intval($_GET['assigned_agent']) . ')';
    $assigned_agent_filter = intval($_GET['assigned_agent']);
} else {
    // Default - any
    $assigned_agent_query = '';
    $assigned_agent_filter = '';
}

// Billable Filter
if (isset($_GET['billable']) && $_GET['billable'] == 1) {
    $billable_query = 'AND (recurring_ticket_billable = 1)';
    $billable_filter = 1;
} elseif (isset($_GET['billable']) && $_GET['billable'] == 0) {
    $billable_query = 'AND (recurring_ticket_billable = 0)';
    $billable_filter = 0;
} else {
    // Default - any
    $billable_query = '';
    $billable_filter = '';
}

// SQL
$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS *,
        (SELECT COUNT(recurring_ticket_task_id) FROM recurring_ticket_tasks WHERE recurring_ticket_task_recurring_ticket_id = recurring_ticket_id) AS recurring_ticket_task_count
    FROM recurring_tickets
    LEFT JOIN clients ON recurring_ticket_client_id = client_id
    LEFT JOIN categories ON category_id = recurring_ticket_category
    LEFT JOIN users ON user_id = recurring_ticket_assigned_to
    LEFT JOIN ticket_templates ON ticket_template_id = recurring_ticket_ticket_template_id
    WHERE (recurring_tickets.recurring_ticket_subject LIKE '%$q%' OR category_name LIKE '%$q%')
    " . clientScopeSql('recurring_ticket_client_id') . "
    $category_query
    $assigned_agent_query
    $billable_query
    $client_query
    ORDER BY
        CASE
            WHEN '$sort' = 'recurring_ticket_priority' THEN
                CASE recurring_ticket_priority
                    WHEN 'Urgent' THEN 0
                    WHEN 'High' THEN 1
                    WHEN 'Medium' THEN 2
                    WHEN 'Low' THEN 3
                    ELSE 4  -- Optional: for unexpected priority values
                END
            ELSE NULL
        END $order,
        $sort $order  -- Apply normal sorting by $sort and $order
    LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$recurring_ticket_page_actions = array();
$new_recurring_ticket_action = null;
if (lookupUserPermission("module_support") >= 2) {
    $new_recurring_ticket_action = array(
        'type' => 'button',
        'label' => 'New Recurring Ticket',
        'icon' => 'fa-plus',
        'variant' => 'primary',
        'class' => 'ajax-modal',
        'attributes' => array(
            'data-modal-url' => 'modals/recurring_ticket/recurring_ticket_add.php?' . $client_url,
            'data-modal-size' => 'lg',
        ),
    );
    $recurring_ticket_page_actions[] = $new_recurring_ticket_action;
}

$recurring_ticket_has_filters = $q !== ''
    || $category_filter !== ''
    || $assigned_agent_filter !== ''
    || $billable_filter !== '';
$recurring_ticket_clear_href = $client_url
    ? 'recurring_tickets.php?client_id=' . $client_id
    : 'recurring_tickets.php';
$recurring_ticket_empty_action = null;
if ($recurring_ticket_has_filters) {
    $recurring_ticket_empty_action = array(
        'label' => 'Clear filters',
        'icon' => 'fa-times',
        'variant' => 'secondary',
        'href' => $recurring_ticket_clear_href,
    );
} elseif ($new_recurring_ticket_action) {
    $recurring_ticket_empty_action = $new_recurring_ticket_action;
}

?>

<section class="card n45-workspace" aria-labelledby="recurring-tickets-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Recurring Tickets',
        'title_id' => 'recurring-tickets-page-title',
        'icon' => 'fa-redo-alt',
        'context' => $client_url ? array(
            'label' => $tab_title,
            'href' => 'client_overview.php?client_id=' . $client_id,
        ) : array(),
        'actions' => $recurring_ticket_page_actions,
    ));
    ?>

    <div class="card-header n45-filter-bar">

        <form autocomplete="off">
            <?php if ($client_url) { ?>
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <?php } ?>
            <div class="row g-2 align-items-center">

                <div class="col-md-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search recurring tickets">
                        <button class="btn btn-dark" aria-label="Search recurring tickets"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="col-sm-2">
                    <div>
                        <select class="form-select select2" name="category" onchange="this.form.submit()" aria-label="Filter recurring tickets by category">
                            <option value="">- All Categories -</option>

                            <?php
                            $sql_categories_filter = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND EXISTS (SELECT 1 FROM recurring_tickets WHERE recurring_ticket_category = category_id $client_query) ORDER BY category_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_categories_filter)) {
                                $category_id = intval($row['category_id']);
                                $category_name = escapeHtml($row['category_name']);
                            ?>
                                <option <?php if ($category_filter == $category_id) { echo "selected"; } ?> value="<?= $category_id ?>"><?= $category_name ?></option>
                            <?php
                            }
                            ?>

                        </select>
                    </div>
                </div>
                <div class="col-sm-2">
                    <div>
                        <select class="form-select select2" name="assigned_agent" onchange="this.form.submit()" aria-label="Filter recurring tickets by assigned agent">
                            <option value="">- All Agents -</option>

                            <?php
                            $sql_assigned_agents_filter = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND EXISTS (SELECT 1 FROM recurring_tickets WHERE recurring_ticket_assigned_to = user_id $client_query) ORDER BY user_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_assigned_agents_filter)) {
                                $user_id = intval($row['user_id']);
                                $user_name = escapeHtml($row['user_name']);
                            ?>
                                <option <?php if ($assigned_agent_filter == $user_id) { echo "selected"; } ?> value="<?= $user_id ?>"><?= $user_name ?></option>
                            <?php
                            }
                            ?>

                        </select>
                    </div>
                </div>
                <div class="col-sm-2">
                    <div>
                        <select class="form-select select2" name="billable" onchange="this.form.submit()" aria-label="Filter recurring tickets by billable status">
                            <option value="">- Billable Status -</option>
                            <option <?php if ($billable_filter == 1) { echo "selected"; } ?> value="1">Billable</option>
                            <option <?php if ($billable_filter == 0) { echo "selected"; } ?> value="0">Non-Billable</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">

                    <?php if (lookupUserPermission("module_support") >= 2) { ?>
                        <div class="dropdown float-end" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <button class="dropdown-item confirm-link" type="submit" form="bulkActions" name="bulk_force_recurring_tickets">
                                    <i class="fas fa-fw fa-paper-plane me-2"></i>Force Reoccur
                                </button>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_bulk_agent_edit.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-user-check me-2"></i>Assign Agent
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_bulk_category_edit.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-layer-group me-2"></i>Set Category
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_bulk_priority_edit.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-thermometer-half me-2"></i>Set Priority
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_bulk_billable_edit.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-dollar-sign me-2"></i>Set Billable
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_bulk_next_run_edit.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-calendar-day me-2"></i>Set Next Run Date
                                </a>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold confirm-link" type="submit" form="bulkActions" name="bulk_delete_recurring_tickets">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                            </div>
                        </div>
                    <?php } ?>

                </div>
            </div>
        </form>
    </div>

    <form id="bulkActions" action="post.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <?php if ($num_rows[0] == 0) { ?>
            <?php
            n45RenderEmptyState(array(
                'title' => $recurring_ticket_has_filters ? 'No recurring tickets match these filters' : 'No recurring tickets yet',
                'description' => $recurring_ticket_has_filters ? 'Clear the current filters to return to the recurring ticket list.' : 'Create a schedule for support work that should generate automatically.',
                'icon' => 'fa-redo-alt',
                'action' => $recurring_ticket_empty_action,
            ));
            ?>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
                <thead class="text-nowrap">
                    <tr>
                        <td class="checkbox-column border-end">
                            <div class="form-check">
                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox" onclick="checkAll(this)" aria-label="Select all displayed recurring tickets">
                            </div>
                        </td>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_ticket_next_run&order=<?= $disp ?>">
                                Next Run Date <?php if ($sort == 'recurring_ticket_next_run') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_ticket_subject&order=<?= $disp ?>">
                                Subject <?php if ($sort == 'recurring_ticket_subject') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=category_name&order=<?= $disp ?>">
                                Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_ticket_priority&order=<?= $disp ?>">
                                Priority <?php if ($sort == 'recurring_ticket_priority') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_ticket_frequency&order=<?= $disp ?>">
                                Frequency <?php if ($sort == 'recurring_ticket_frequency') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th class="text-center">
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=recurring_ticket_billable&order=<?= $disp ?>">
                                Billable <?php if ($sort == 'recurring_ticket_billable') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=user_name&order=<?= $disp ?>">
                                Agent <?php if ($sort == 'user_name') { echo $order_icon; } ?>
                            </a>
                        </th>

                        <?php if (!$client_url) { ?>
                        <th>
                            <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=client_name&order=<?= $disp ?>">
                                Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <?php } ?>
                        <?php if (lookupUserPermission("module_support") >= 2) { ?>
                            <th class="text-center">Action</th>
                        <?php } ?>
                    </tr>
                </thead>

                <tbody>

                    <?php

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $recurring_ticket_id = intval($row['recurring_ticket_id']);
                        $recurring_ticket_client_id = intval($row['client_id']);
                        $recurring_ticket_subject = escapeHtml($row['recurring_ticket_subject']);
                        $recurring_ticket_priority = escapeHtml($row['recurring_ticket_priority']);
                        $recurring_ticket_frequency = escapeHtml($row['recurring_ticket_frequency']);
                        $recurring_ticket_next_run = escapeHtml($row['recurring_ticket_next_run']);
                        $recurring_ticket_billable = intval($row['recurring_ticket_billable']);
                        if ($recurring_ticket_billable) {
                            $recurring_ticket_billable_display = "<span class='text-success'><i class='fas fa-fw fa-check' aria-hidden='true'></i><span class='visually-hidden'>Yes</span></span>";
                        } else {
                            $recurring_ticket_billable_display = "<span class='text-secondary'>No</span>";
                        }
                        $recurring_ticket_category = escapeHtml($row['category_name']) ?: '-';
                        $recurring_ticket_client_name = escapeHtml($row['client_name']);
                        $assigned_to = escapeHtml($row['user_name']) ?: '-';
                        $recurring_ticket_template_name = escapeHtml($row['ticket_template_name']);
                        $recurring_ticket_task_count = intval($row['recurring_ticket_task_count']);
                    ?>

                        <tr>
                            <td class="checkbox-column bg-light border-end">
                                <div class="form-check">
                                    <input class="form-check-input bulk-select" type="checkbox" name="recurring_ticket_ids[]" value="<?= $recurring_ticket_id ?>" aria-label="Select recurring ticket <?= $recurring_ticket_subject ?>">
                                </div>
                            </td>
                            <td class="text-bold"><?= $recurring_ticket_next_run ?></td>
                            <td>
                                <a class="ajax-modal" href="#"
                                    data-modal-size="lg"
                                    data-modal-url="modals/recurring_ticket/recurring_ticket_edit.php?id=<?= $recurring_ticket_id ?>">
                                    <?= $recurring_ticket_subject ?>
                                </a>
                                <?php if ($recurring_ticket_template_name) { ?>
                                    <span title="Template: <?= $recurring_ticket_template_name ?>" aria-label="Template: <?= $recurring_ticket_template_name ?>">
                                        <i class="fas fa-puzzle-piece text-secondary ms-1" aria-hidden="true"></i>
                                    </span>
                                <?php } ?>
                                <?php if ($recurring_ticket_task_count) { ?>
                                    <span title="Adds <?= $recurring_ticket_task_count ?> Tasks">
                                        <i class="fas fa-fw fa-tasks me-1" aria-hidden="true"></i><?= $recurring_ticket_task_count ?>
                                    </span>
                                <?php } ?>
                            </td>
                            <td><?= $recurring_ticket_category ?></td>
                            <td><?= $recurring_ticket_priority ?></td>
                            <td><?= $recurring_ticket_frequency ?></td>
                            <td class="text-center"><?= $recurring_ticket_billable_display ?></td>
                            <td><?= $assigned_to ?></td>
                            <?php if (!$client_url) { ?>
                            <td><a href="recurring_tickets.php?client_id=<?= $recurring_ticket_client_id ?>"><?= $recurring_ticket_client_name ?></a></td>
                            <?php } ?>

                            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                <td>
                                    <div class="dropdown dropstart text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions for recurring ticket <?= $recurring_ticket_subject ?>">
                                            <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-size="lg"
                                                data-modal-url="modals/recurring_ticket/recurring_ticket_edit.php?id=<?= $recurring_ticket_id ?>">
                                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item" href="post.php?force_recurring_ticket=<?= $recurring_ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fa fa-fw fa-paper-plane text-secondary me-2"></i>Force Reoccur
                                            </a>
                                            <?php if (lookupUserPermission("module_support") == 3) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_recurring_ticket=<?= $recurring_ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                                </a>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </td>
                            <?php } ?>

                        </tr>

                    <?php } ?>

                </tbody>

            </table>
        </div>
        <?php } ?>
    </form>

    <?php require_once '../includes/filter_footer.php';
        ?>

</section>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
