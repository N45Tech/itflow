<?php

// Default Column Sortby Filter
$sort = "domain_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND domain_client_id = $client_id";
    $client_url = "client_id=$client_id&";
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "domain_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "domain_archived_at IS NULL";
    }
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
    $client_url = '';
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "(client_archived_at IS NOT NULL OR domain_archived_at IS NOT NULL)";
    } else {
        $archived = 0;
        $archive_query = "(client_archived_at IS NULL AND domain_archived_at IS NULL)";
    }
}

// Perms
enforceUserPermission('module_support');

// Expiring In Filter
if (isset($_GET['expire_days']) && !empty($_GET['expire_days'])) {
    if ($_GET['expire_days'] == "expired") {
        $expire_days = "expired";
        $expire_query = "AND (domain_expire IS NOT NULL AND domain_expire != '0000-00-00' AND domain_expire < CURDATE())";
    } else {
        $expire_days = intval($_GET['expire_days']);
        $expire_query = "AND (domain_expire IS NOT NULL AND domain_expire != '0000-00-00' AND domain_expire BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $expire_days DAY))";
    }
} else {
    // Default - any
    $expire_days = '';
    $expire_query = '';
}

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) & !empty($_GET['client'])) {
        $client_query = 'AND (domain_client_id = ' . intval($_GET['client']) . ')';
        $client = intval($_GET['client']);
    } else {
        // Default - any
        $client_query = '';
        $client = '';
    }
}

$sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS domains.*, clients.*,
    registrar.vendor_id AS registrar_id,
    registrar.vendor_name AS registrar_name,
    dnshost.vendor_id AS dnshost_id,
    dnshost.vendor_name AS dnshost_name,
    mailhost.vendor_id AS mailhost_id,
    mailhost.vendor_name AS mailhost_name,
    webhost.vendor_id AS webhost_id,
    webhost.vendor_name AS webhost_name
    FROM domains
    LEFT JOIN clients ON client_id = domain_client_id
    LEFT JOIN vendors AS registrar ON domains.domain_registrar = registrar.vendor_id
    LEFT JOIN vendors AS dnshost ON domains.domain_dnshost = dnshost.vendor_id
    LEFT JOIN vendors AS mailhost ON domains.domain_mailhost = mailhost.vendor_id
    LEFT JOIN vendors AS webhost ON domains.domain_webhost = webhost.vendor_id
    WHERE (domains.domain_name LIKE '%$q%' OR domains.domain_description LIKE '%$q%' OR registrar.vendor_name LIKE '%$q%' OR dnshost.vendor_name LIKE '%$q%' OR mailhost.vendor_name LIKE '%$q%' OR webhost.vendor_name LIKE '%$q%' OR client_name LIKE '%$q%')
    AND $archive_query
    " . clientScopeSql('domain_client_id') . "
    $client_query
    $expire_query
    ORDER BY $sort $order LIMIT $record_from, $record_to");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$domain_page_actions = array();
$domain_primary_action = array(
    'type' => 'button',
    'label' => 'New Domain',
    'icon' => 'fa-plus',
    'variant' => 'primary',
    'class' => 'ajax-modal',
    'attributes' => array('data-modal-url' => 'modals/domain/domain_add.php?' . $client_url),
);
if ($num_rows[0] > 0) {
    $domain_page_actions[] = array(
        'type' => 'split-menu',
        'button' => $domain_primary_action,
        'items' => array(
            array(
                'label' => 'Export',
                'icon' => 'fa-download',
                'class' => 'ajax-modal',
                'attributes' => array(
                    'data-modal-url' => buildExportModalUrl('modals/domain/domain_export.php', array('client_id', 'client', 'expire_days', 'archived', 'q')),
                ),
            ),
        ),
        'menu_label' => 'More domain actions',
        'align_end' => true,
    );
} else {
    $domain_page_actions[] = $domain_primary_action;
}

$domain_has_filters = $q !== '' || $expire_days !== '' || $archived == 1 || (!$client_url && $client !== '');
$domain_empty_action = $domain_has_filters
    ? array('label' => 'Clear filters', 'icon' => 'fa-times', 'variant' => 'secondary', 'href' => 'domains.php?' . $client_url)
    : $domain_primary_action;

?>

<section class="card n45-workspace" aria-labelledby="domains-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Domains',
        'title_id' => 'domains-page-title',
        'icon' => 'fa-globe',
        'context' => $client_url ? array(
            'label' => $tab_title,
            'href' => 'client_overview.php?client_id=' . $client_id,
        ) : array(),
        'actions' => $domain_page_actions,
    ));
    ?>

    <div class="card-header n45-filter-bar">
        <form autocomplete="off">
            <?php if ($client_url) { ?>
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
            <?php } ?>
            <input type="hidden" name="archived" value="<?= $archived ?>">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search domains">
                            <button class="btn btn-dark" aria-label="Search domains"><i class="fa fa-search" aria-hidden="true"></i></button>
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
                                JOIN domains ON domain_client_id = client_id
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
                    <div class="btn-group float-end">
                        <a href="?<?= $client_url ?>archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo"primary"; } else { echo "default"; } ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($archived) { ?>
                                <button class="dropdown-item text-info"
                                    type="submit" form="bulkActions" name="bulk_restore_domains">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold"
                                    type="submit" form="bulkActions" name="bulk_delete_domains">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                                <?php } else { ?>
                                <button class="dropdown-item"
                                    type="submit" form="bulkActions" name="bulk_refresh_domains">
                                    <i class="fas fa-fw fa-sync-alt me-2"></i>Refresh Records
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger confirm-link"
                                    type="submit" form="bulkActions" name="bulk_archive_domains">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>
    <form id="bulkActions" action="post.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($client_url) { ?>
        <input type="hidden" name="client_id" value="<?= $client_id ?>">
        <?php } ?>
        <?php if ($num_rows[0] == 0) { ?>
            <?php
            n45RenderEmptyState(array(
                'title' => $domain_has_filters ? 'No domains match these filters' : 'No domains yet',
                'description' => $domain_has_filters ? 'Clear the current filters to return to the domain list.' : 'Add client domains to track ownership, hosting, and renewal dates.',
                'icon' => 'fa-globe',
                'action' => $domain_empty_action,
            ));
            ?>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                <tr>
                    <td class="checkbox-column border-end">
                        <div class="form-check">
                            <input class="form-check-input" id="selectAllCheckbox" type="checkbox" onclick="checkAll(this)">
                        </div>
                    </td>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=domain_name&order=<?= $disp ?>">
                            Domain <?php if ($sort == 'domain_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=registrar_name&order=<?= $disp ?>">
                            Registrar <?php if ($sort == 'registrar_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=webhost_name&order=<?= $disp ?>">
                            Web Host <?php if ($sort == 'webhost_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=dnshost_name&order=<?= $disp ?>">
                            DNS Host <?php if ($sort == 'dnshost_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=mailhost_name&order=<?= $disp ?>">
                            Mail Host <?php if ($sort == 'mailhost_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=domain_expire&order=<?= $disp ?>">
                            Expires <?php if ($sort == 'domain_expire') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php if (!$client_url) { ?>
                    <th>
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=client_name&order=<?= $disp ?>">
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
                    $domain_id = intval($row['domain_id']);
                    $domain_name = escapeHtml($row['domain_name']);
                    $domain_description = escapeHtml($row['domain_description']);
                    $domain_expire = escapeHtml($row['domain_expire']);
                    $domain_expire_ago = timeAgo($domain_expire);
                    // Convert the expiry date to a timestamp
                    $domain_expire_timestamp = strtotime($row['domain_expire'] ?? '');
                    $current_timestamp = time(); // Get current timestamp

                    // Calculate the difference in days
                    $days_until_expiry = ($domain_expire_timestamp - $current_timestamp) / (60 * 60 * 24);

                    // Determine the class based on the number of days until expiry
                    if ($days_until_expiry <= 0) {
                        $tr_class = "table-secondary";
                    } elseif ($days_until_expiry <= 14) {
                        $tr_class = "table-danger";
                    } elseif ($days_until_expiry <= 90) {
                        $tr_class = "table-warning";
                    } else {
                        $tr_class = '';
                    }
                    $domain_registrar_id = intval($row['registrar_id']);
                    $domain_webhost_id = intval($row['webhost_id']);
                    $domain_dnshost_id = intval($row['dnshost_id']);
                    $domain_mailhost_id = intval($row['mailhost_id']);
                    $domain_registrar_name = escapeHtml($row['registrar_name']);
                    $domain_webhost_name = escapeHtml($row['webhost_name']);
                    $domain_dnshost_name = escapeHtml($row['dnshost_name']);
                    $domain_mailhost_name = escapeHtml($row['mailhost_name']);
                    $domain_created_at = escapeHtml($row['domain_created_at']);
                    $domain_archived_at = escapeHtml($row['domain_archived_at']);
                    $client_id = intval($row['domain_client_id']);
                    $client_name = escapeHtml($row['client_name']);
                    // Add - if empty on the table
                    $domain_registrar_name_display = $domain_registrar_name ? "
                        <a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor.php?id=$domain_registrar_id'>
                            $domain_registrar_name
                        </a>" : "-";
                    $domain_webhost_name_display = $domain_webhost_name ? "
                        <a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor.php?id=$domain_webhost_id'>
                            $domain_webhost_name
                        </a>" : "-";
                    $domain_dnshost_name_display = $domain_dnshost_name ? "
                        <a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor.php?id=$domain_dnshost_id'>
                            $domain_dnshost_name
                        </a>" : "-";
                    $domain_mailhost_name_display = $domain_mailhost_name ? "
                        <a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor.php?id=$domain_mailhost_id'>
                            $domain_mailhost_name
                        </a>" : "-";

                    ?>
                    <tr class="<?= $tr_class ?>">
                        <td class="checkbox-column bg-light border-end">
                            <div class="form-check">
                                <input class="form-check-input bulk-select" type="checkbox" name="domain_ids[]" value="<?= $domain_id ?>">
                            </div>
                        </td>
                        <td>
                            <a class="text-dark ajax-modal" href="#"
                                data-modal-size="lg"
                                data-modal-url="modals/domain/domain_edit.php?<?= $client_url ?>&id=<?= $domain_id ?>">
                                <div class="d-flex">
                                    <i class="fa fa-fw fa-2x fa-globe me-3"></i>
                                    <div class="flex-grow-1">
                                        <div><?= $domain_name ?></div>
                                        <div><small class="text-secondary"><?= $domain_description ?></small></div>
                                    </div>
                                </div>
                            </a>
                        </td>
                        <td><?= $domain_registrar_name_display ?></td>
                        <td><?= $domain_webhost_name_display ?></td>
                        <td><?= $domain_dnshost_name_display ?></td>
                        <td><?= $domain_mailhost_name_display ?></td>
                        <td>
                            <div><?= $domain_expire ?: '-' ?></div>
                            <?php if (!empty($domain_expire)) { ?>
                                <div><small><?= $domain_expire_ago ?></small></div>
                            <?php } ?>
                        </td>
                        <?php if (!$client_url) { ?>
                        <td><a href="domains.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                        <?php } ?>
                        <td>
                            <div class="dropdown dropstart text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#"
                                        data-modal-size="lg"
                                        data-modal-url="modals/domain/domain_edit.php?<?= $client_url ?>&id=<?= $domain_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="post.php?refresh_domain=<?= $domain_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-sync-alt me-2"></i>Refresh Records
                                    </a>
                                    <?php if ($session_user_role == 3) { ?>
                                        <?php if ($domain_archived_at) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-info confirm-link" href="post.php?restore_domain=<?= $domain_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-redo me-2"></i>Restore
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_domain=<?= $domain_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                        <?php } else { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_domain=<?= $domain_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-archive me-2"></i>Archive
                                        </a>
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
    </form>
    <?php require_once "../includes/filter_footer.php"; ?>
</section>

<script src="../js/bulk_actions.js"></script>

<?php require_once "../includes/footer.php";
