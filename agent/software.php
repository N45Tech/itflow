<?php

// Default Column Sortby Filter
$sort = "software_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND software_client_id = $client_id";
    $client_url = "client_id=$client_id&";
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "software_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "software_archived_at IS NULL";
    }
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
    $client_url = '';
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "(client_archived_at IS NOT NULL OR software_archived_at IS NOT NULL)";
    } else {
        $archived = 0;
        $archive_query = "(client_archived_at IS NULL AND software_archived_at IS NULL)";
    }
}

// Perms
enforceUserPermission('module_support');

// Expiring In Filter
if (isset($_GET['expire_days']) && !empty($_GET['expire_days'])) {
    if ($_GET['expire_days'] == "expired") {
        $expire_days = "expired";
        $expire_query = "AND (software_expire IS NOT NULL AND software_expire != '0000-00-00' AND software_expire < CURDATE())";
    } else {
        $expire_days = intval($_GET['expire_days']);
        $expire_query = "AND (software_expire IS NOT NULL AND software_expire != '0000-00-00' AND software_expire BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $expire_days DAY))";
    }
} else {
    // Default - any
    $expire_days = '';
    $expire_query = '';
}

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) & !empty($_GET['client'])) {
        $client_query = 'AND (software_client_id = ' . intval($_GET['client']) . ')';
        $client = intval($_GET['client']);
    } else {
        // Default - any
        $client_query = '';
        $client = '';
    }
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS client_id, client_name, software_created_at, software_description, software_expire,
        software_id, software_license_type, software_name, software_seats, software_type,
        (SELECT COUNT(*) FROM software_assets
            WHERE software_assets.software_id = software.software_id)
        + (SELECT COUNT(*) FROM software_contacts
            WHERE software_contacts.software_id = software.software_id) AS software_assigned_seats,
        software_version, vendor_id, vendor_name FROM software
    LEFT JOIN clients ON client_id = software_client_id
    LEFT JOIN vendors ON vendor_id = software_vendor_id
    WHERE (software_name LIKE '%$q%' OR software_type LIKE '%$q%' OR software_key LIKE '%$q%' OR client_name LIKE '%$q%')
    AND $archive_query
    " . clientScopeSql('software_client_id') . "
    $client_query
    $expire_query
    ORDER BY $sort $order LIMIT $record_from, $record_to");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$software_action_items = array(
    array(
        'label' => 'Create from Template',
        'icon' => 'fa-puzzle-piece',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => 'modals/software/software_add_from_template.php?' . $client_url),
    ),
);
if ($num_rows[0] > 0) {
    $software_action_items[] = array('type' => 'separator');
    $software_action_items[] = array(
        'label' => 'Export',
        'icon' => 'fa-download',
        'class' => 'ajax-modal',
        'attributes' => array(
            'data-modal-url' => buildExportModalUrl('modals/software/software_export.php', array('client_id', 'client', 'expire_days', 'archived', 'q')),
        ),
    );
}

$software_has_filters = $q !== '' || $expire_days !== '' || $archived == 1 || (!$client_url && $client !== '');
$software_empty_action = $software_has_filters
    ? array('label' => 'Clear filters', 'icon' => 'fa-times', 'variant' => 'secondary', 'href' => 'software.php?' . $client_url)
    : array(
        'type' => 'button',
        'label' => 'New License',
        'icon' => 'fa-plus',
        'variant' => 'primary',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => 'modals/software/software_add.php?' . $client_url),
    );

?>

    <section class="card n45-workspace" aria-labelledby="software-page-title">
        <?php
        n45RenderPageHeader(array(
            'variant' => 'workspace',
            'title' => 'Software & Licenses',
            'title_id' => 'software-page-title',
            'icon' => 'fa-cube',
            'context' => $client_url ? array(
                'label' => $tab_title,
                'href' => 'client_overview.php?client_id=' . $client_id,
            ) : array(),
            'actions' => array(
                array(
                    'type' => 'split-menu',
                    'button' => array(
                        'type' => 'button',
                        'label' => 'New License',
                        'icon' => 'fa-plus',
                        'variant' => 'primary',
                        'class' => 'ajax-modal',
                        'attributes' => array('data-modal-url' => 'modals/software/software_add.php?' . $client_url),
                    ),
                    'items' => $software_action_items,
                    'menu_label' => 'More software actions',
                    'align_end' => true,
                ),
            ),
        ));
        ?>
        <div class="card-header n45-filter-bar">
            <form autocomplete="off">
                <?php if($client_url) { ?>
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <?php } ?>
                <input type="hidden" name="archived" value="<?= $archived ?>">
                <div class="row g-2 align-items-center">

                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search software and licenses">
                                <button class="btn btn-dark" aria-label="Search software and licenses"><i class="fa fa-search" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <?php if (!$client_url) { ?>
                    <div class="col-md-2">
                        <div class="input-group">
                            <select class="form-select select2" name="client" onchange="this.form.submit()">
                                <option value="" <?php if ($client == "") { echo "selected"; } ?>>- All Clients -</option>

                                <?php
                                    $sql_clients_filter = mysqli_query($mysqli, "
                                    SELECT DISTINCT client_id, client_name
                                    FROM clients
                                    JOIN software ON software_client_id = client_id
                                    WHERE $archive_query
                                    " . clientScopeSql('clients.client_id') . "
                                    ORDER BY client_name ASC
                                ");
                                while ($row = mysqli_fetch_assoc($sql_clients_filter)) {
                                    $client_id = intval($row['client_id']);
                                    $client_name = escapeHtml($row['client_name']);
                                ?>
                                    <option <?php if ($client == $client_id) { echo "selected"; } ?> value="<?= $client_id ?>"><?= $client_name ?></option>
                                <?php
                                }
                                ?>

                            </select>
                        </div>
                    </div>
                    <?php } ?>

                    <div class="col-md-2">
                        <div class="input-group">
                            <select class="form-select select2" name="expire_days" onchange="this.form.submit()">
                                <option value="" <?php if ($expire_days == "") { echo "selected"; } ?>>- Expiring In -</option>
                                <option value="expired" <?php if ($expire_days === "expired") { echo "selected"; } ?>>Expired</option>
                                <option value="7" <?php if ($expire_days === 7) { echo "selected"; } ?>>7 Days</option>
                                <option value="30" <?php if ($expire_days === 30) { echo "selected"; } ?>>30 Days</option>
                                <option value="45" <?php if ($expire_days === 45) { echo "selected"; } ?>>45 Days</option>
                                <option value="60" <?php if ($expire_days === 60) { echo "selected"; } ?>>60 Days</option>
                                <option value="90" <?php if ($expire_days === 90) { echo "selected"; } ?>>90 Days</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($client_url) { // filler ?>
                    <div class="col-md-2"></div>
                    <?php } ?>

                    <div class="col-md-4">
                        <div class="float-end">
                            <a href="?<?= $client_url ?>archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                                class="btn btn-<?php if($archived == 1){ echo"primary"; } else { echo "default"; } ?>">
                                <i class="fa fa-fw fa-archive me-2"></i>Archived
                            </a>
                        </div>
                    </div>

                </div>
            </form>
        </div>
        <?php if ($num_rows[0] == 0) { ?>
            <?php
            n45RenderEmptyState(array(
                'title' => $software_has_filters ? 'No licenses match these filters' : 'No software licenses yet',
                'description' => $software_has_filters ? 'Clear the current filters to return to the license list.' : 'Track software ownership, seats, renewals, and assignments.',
                'icon' => 'fa-cube',
                'action' => $software_empty_action,
            ));
            ?>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-borderless table-hover mb-0 n45-data-table">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                <tr>
                    <th class="ps-3">
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=software_name&order=<?= $disp ?>">
                            Software <?php if ($sort == 'software_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=software_type&order=<?= $disp ?>">
                            Type <?php if ($sort == 'software_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=software_license_type&order=<?= $disp ?>">
                            License Type <?php if ($sort == 'software_license_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=software_seats&order=<?= $disp ?>">
                            Seats <?php if ($sort == 'software_seats') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=software_expire&order=<?= $disp ?>">
                            Expire <?php if ($sort == 'software_expire') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=vendor_name&order=<?= $disp ?>">
                            Vendor <?php if ($sort == 'vendor_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php if (!$client_url) { ?>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=client_name&order=<?= $disp ?>">
                            Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php } ?>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php

                while ($row = mysqli_fetch_assoc($sql)) {
                    $client_id = intval($row['client_id']);
                    $client_name = escapeHtml($row['client_name']);
                    $software_id = intval($row['software_id']);
                    $software_name = escapeHtml($row['software_name']);
                    $software_description = escapeHtml($row['software_description']);
                    $software_version = escapeHtml($row['software_version']);
                    $software_type = escapeHtml($row['software_type']);
                    $software_license_type = escapeHtml($row['software_license_type']) ?: '-';
                    $software_seats = escapeHtml($row['software_seats']);
                    $software_expire = escapeHtml($row['software_expire']);
                    $vendor_name = escapeHtml($row['vendor_name']);
                    $vendor_id = intval($row['vendor_id']);
                    if ($vendor_name) {
                        $vendor_display = "<a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor.php?id=$vendor_id'>$vendor_name</a>";
                    } else {
                        $vendor_display = "<span class='text-muted'>N/A</span>";
                    }
                    if ($software_expire) {
                        $software_expire_ago = timeAgo($software_expire);
                        $software_expire_display = "<div>$software_expire</div><div><small>$software_expire_ago</small></div>";

                        // Convert the expiry date to a timestamp
                        $software_expire_timestamp = strtotime($row['software_expire']);
                        $current_timestamp = time(); // Get current timestamp

                        // Calculate the difference in days
                        $days_until_expiry = ($software_expire_timestamp - $current_timestamp) / (60 * 60 * 24);

                        // Determine the class based on the number of days until expiry
                        if ($days_until_expiry <= 0) {
                            $tr_class = "table-secondary";
                        } elseif ($days_until_expiry <= 7) {
                            $tr_class = "table-danger";
                        } elseif ($days_until_expiry <= 45) {
                            $tr_class = "table-warning";
                        } else {
                            $tr_class = '';
                        }

                    } else {
                        $software_expire_display = "<span class='text-muted'>N/A</span>";
                        $tr_class = '';
                    }

                    $software_created_at = escapeHtml($row['software_created_at']);

                    $seat_count = intval($row['software_assigned_seats']);

                    ?>
                    <tr class="<?= $tr_class ?>">
                        <td class="ps-3">
                            <a class="text-dark ajax-modal" href="#" data-modal-url="modals/software/software_edit.php?id=<?= $software_id ?>">
                                <div class="d-flex">
                                    <i class="fa fa-fw fa-2x fa-cube me-3"></i>
                                    <div class="flex-grow-1">
                                        <div><?= "$software_name <span>$software_version</span>" ?></div>
                                        <div><small class="text-secondary"><?= $software_description ?></small></div>
                                    </div>
                                </div>
                            </a>
                        </td>
                        <td><?= $software_type ?></td>
                        <td><?= $software_license_type ?></td>
                        <td><?= "$seat_count / $software_seats" ?></td>
                        <td><?= $software_expire_display ?></td>
                        <td><?= $vendor_display ?></td>
                        <?php if (!$client_url) { ?>
                        <td><a href="software.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                        <?php } ?>
                        <td>
                            <div class="dropdown dropstart text-center">
                                <button class="btn btn-secondary btn-sm" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/software/software_edit.php?id=<?= $software_id ?>"
                                        >
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?archive_software=<?= $software_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-archive me-2"></i>Archive and<br><small>Remove Licenses</small></a>
                                    <?php if ($session_user_role == 3) { ?>
                                        <?php if ($config_destructive_deletes_enable) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_software=<?= $software_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete and<br><small>Remove Licenses</small></a>
                                        <?php } ?>
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
