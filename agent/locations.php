<?php

// Default Column Sortby Filter
$sort = "location_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND location_client_id = $client_id";
    $client_url = "client_id=$client_id&";
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "location_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "location_archived_at IS NULL";
    }
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
    $client_url = '';
}

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) & !empty($_GET['client'])) {
        $client_query = 'AND (location_client_id = ' . intval($_GET['client']) . ')';
        $client = intval($_GET['client']);
    } else {
        // Default - any
        $client_query = '';
        $client = '';
    }
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "(client_archived_at IS NOT NULL OR location_archived_at IS NOT NULL)";
    } else {
        $archived = 0;
        $archive_query = "(client_archived_at IS NULL AND location_archived_at IS NULL)";
    }
}

// Perms
enforceUserPermission('module_client');

// Tags Filter
if (isset($_GET['tags']) && is_array($_GET['tags']) && !empty($_GET['tags'])) {
    // Sanitize each element of the tags array
    $sanitizedTags = array_map('intval', $_GET['tags']);
    // Convert the sanitized tags into a comma-separated string
    $tag_filter = implode(",", $sanitizedTags);
    $tag_query = "AND tags.tag_id IN ($tag_filter)";
} else {
    $tag_filter = 0;
    $tag_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS locations.*, clients.*, GROUP_CONCAT(tag_name) FROM locations
    LEFT JOIN clients ON client_id = location_client_id
    LEFT JOIN location_tags ON location_tags.location_id = locations.location_id
    LEFT JOIN tags ON tags.tag_id = location_tags.tag_id
    WHERE $archive_query
    $tag_query
    AND (location_name LIKE '%$q%' OR location_description LIKE '%$q%' OR location_address LIKE '%$q%' OR location_city LIKE '%$q%' OR location_state LIKE '%$q%' OR location_zip LIKE '%$q%' OR location_country LIKE '%$q%' OR location_phone LIKE '%$phone_query%' OR tag_name LIKE '%$q%' OR client_name LIKE '%$q%')
    " . clientScopeSql('location_client_id') . "
    $client_query
    GROUP BY location_id
    ORDER BY location_primary DESC, $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$location_action_items = array(
    array(
        'label' => 'Import',
        'icon' => 'fa-upload',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => 'modals/location/location_import.php?' . $client_url),
    ),
);
if ($num_rows[0] > 0) {
    $location_action_items[] = array('type' => 'separator');
    $location_action_items[] = array(
        'label' => 'Export',
        'icon' => 'fa-download',
        'class' => 'ajax-modal',
        'attributes' => array(
            'data-modal-url' => buildExportModalUrl('modals/location/location_export.php', array('client_id', 'client', 'tags', 'archived', 'q')),
        ),
    );
}

$location_has_filters = $q !== ''
    || $archived == 1
    || $tag_filter !== 0
    || (!$client_url && $client !== '');
$location_clear_href = $client_url
    ? 'locations.php?client_id=' . $client_id
    : 'locations.php';
$location_empty_action = $location_has_filters
    ? array('label' => 'Clear filters', 'icon' => 'fa-times', 'variant' => 'secondary', 'href' => $location_clear_href)
    : array(
        'type' => 'button',
        'label' => 'New Location',
        'icon' => 'fa-plus',
        'variant' => 'primary',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => 'modals/location/location_add.php?' . $client_url),
    );

?>

<section class="card n45-workspace" aria-labelledby="locations-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Locations',
        'title_id' => 'locations-page-title',
        'icon' => 'fa-map-marker-alt',
        'context' => $client_url ? array(
            'label' => $tab_title,
            'href' => 'client_overview.php?client_id=' . $client_id,
        ) : array(),
        'actions' => array(
            array(
                'type' => 'split-menu',
                'button' => array(
                    'type' => 'button',
                    'label' => 'New Location',
                    'icon' => 'fa-plus',
                    'variant' => 'primary',
                    'class' => 'ajax-modal',
                    'attributes' => array('data-modal-url' => 'modals/location/location_add.php?' . $client_url),
                ),
                'items' => $location_action_items,
                'menu_label' => 'More location actions',
                'align_end' => true,
            ),
        ),
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
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search locations">
                        <button class="btn btn-dark" aria-label="Search locations"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="input-group">
                        <select onchange="this.form.submit()" class="form-select select2" name="tags[]" data-placeholder="- Select Tags -" multiple aria-label="Filter locations by tags">
                            <?php
                            $sql_tags_filter = mysqli_query($mysqli, "
                                SELECT tags.tag_id, tags.tag_name, tag_type
                                FROM tags
                                LEFT JOIN location_tags ON location_tags.tag_id = tags.tag_id
                                LEFT JOIN locations ON location_tags.location_id = locations.location_id
                                WHERE tag_type = 2
                                $client_query OR tags.tag_id IN ($tag_filter)
                                GROUP BY tags.tag_id
                                HAVING COUNT(location_tags.location_id) > 0 OR tags.tag_id IN ($tag_filter)
                            ");
                            while ($row = mysqli_fetch_assoc($sql_tags_filter)) {
                                $tag_id = intval($row['tag_id']);
                                $tag_name = escapeHtml($row['tag_name']); ?>

                                <option value="<?= $tag_id ?>" <?php if (isset($_GET['tags']) && is_array($_GET['tags']) && in_array($tag_id, $_GET['tags'])) { echo 'selected'; } ?>> <?= $tag_name ?> </option>

                            <?php } ?>
                        </select>
                    </div>
                </div>
                <?php if ($client_url) { ?>
                <div class="col-md-2"></div>
                <?php } else { ?>
                <div class="col-md-2">
                    <div class="input-group">
                        <select class="form-select select2" name="client" onchange="this.form.submit()" aria-label="Filter locations by client">
                            <option value="" <?php if ($client == "") { echo "selected"; } ?>>- All Clients -</option>

                            <?php
                            $sql_clients_filter = mysqli_query($mysqli, "
                                SELECT DISTINCT client_id, client_name
                                FROM clients
                                JOIN locations ON location_client_id = client_id
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

                <div class="col-md-3">
                    <div class="btn-group float-end">
                        <a href="?<?= $client_url ?>archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo"primary"; } else { echo "default"; } ?>"
                            aria-label="<?= $archived == 1 ? 'Show active locations' : 'Show archived locations' ?>"
                            <?php if ($archived == 1) { ?>aria-current="page"<?php } ?>>
                            <i class="fa fa-fw fa-archive me-2" aria-hidden="true"></i>Archived
                        </a>
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/location/location_bulk_assign_tags.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-tags me-2"></i>Assign Tags
                                </a>
                                <?php if ($archived) { ?>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-info"
                                    type="submit" form="bulkActions" name="bulk_restore_locations">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold"
                                    type="submit" form="bulkActions" name="bulk_delete_locations">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                                <?php } else { ?>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger confirm-link"
                                    type="submit" form="bulkActions" name="bulk_archive_locations">
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
    <form id="bulkActions" action="post.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <?php if ($num_rows[0] == 0) { ?>
            <?php
            n45RenderEmptyState(array(
                'title' => $location_has_filters ? 'No locations match these filters' : 'No locations yet',
                'description' => $location_has_filters ? 'Clear the current filters to return to the location list.' : 'Add the offices, sites, and service addresses associated with this workspace.',
                'icon' => 'fa-map-marker-alt',
                'action' => $location_empty_action,
            ));
            ?>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
                <thead class="text-nowrap">
                <tr>
                    <td class="checkbox-column border-end">
                        <div class="form-check">
                            <input class="form-check-input" id="selectAllCheckbox" type="checkbox" onclick="checkAll(this)" aria-label="Select all displayed locations">
                        </div>
                    </td>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=location_name&order=<?= $disp ?>">
                            Name <?php if ($sort == 'location_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=location_address&order=<?= $disp ?>">
                            Address <?php if ($sort == 'location_address') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=location_phone&order=<?= $disp ?>">
                            Phone <?php if ($sort == 'location_phone') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=location_hours&order=<?= $disp ?>">
                            Hours <?php if ($sort == 'location_hours') { echo $order_icon; } ?>
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
                    $location_id = intval($row['location_id']);
                    $location_name = escapeHtml($row['location_name']);
                    $location_description = escapeHtml($row['location_description']);
                    $location_address = escapeHtml($row['location_address']);
                    $location_city = escapeHtml($row['location_city']);
                    $location_state = escapeHtml($row['location_state']);
                    $location_zip = escapeHtml($row['location_zip']);
                    $location_country = escapeHtml($row['location_country']);
                    $full_address = formatAddress($location_address, $location_city, $location_state, $location_zip, $location_country, '<br>') ?: '-';
                    $location_phone_country_code = escapeHtml($row['location_phone_country_code']);
                    $location_phone = escapeHtml(formatPhoneNumber($row['location_phone'], $location_phone_country_code));
                    if (empty($location_phone)) {
                        $location_phone_display = "-";
                    } else {
                        $location_phone_display = $location_phone;
                    }
                    $location_fax_country_code = escapeHtml($row['location_fax_country_code']);
                    $location_fax = escapeHtml(formatPhoneNumber($row['location_fax'], $location_fax_country_code));
                    if ($location_fax) {
                        $location_fax_display = "<div class='text-secondary'>Fax: $location_fax</div>";
                    } else {
                        $location_fax_display = '';
                    }
                    $location_hours = escapeHtml($row['location_hours']);
                    if (empty($location_hours)) {
                        $location_hours_display = "-";
                    } else {
                        $location_hours_display = $location_hours;
                    }
                    $location_photo = escapeHtml($row['location_photo']);
                    $location_notes = escapeHtml($row['location_notes']);
                    $location_created_at = escapeHtml($row['location_created_at']);
                    $location_archived_at = escapeHtml($row['location_archived_at']);
                    $location_contact_id = intval($row['location_contact_id']);
                    $location_primary = intval($row['location_primary']);
                    if ( $location_primary == 1 ) {
                        $location_primary_display = "<small class='text-success'><i class='fa fa-fw fa-check'></i> Primary</small>";
                    } else {
                        $location_primary_display = "";
                    }

                    // Tags

                    $location_tag_name_display_array = array();
                    $location_tag_id_array = array();
                    $sql_location_tags = mysqli_query($mysqli, "SELECT tag_color, tag_icon, location_tags.tag_id, tag_name FROM location_tags LEFT JOIN tags ON location_tags.tag_id = tags.tag_id WHERE location_tags.location_id = $location_id ORDER BY tag_name ASC");
                    while ($row = mysqli_fetch_assoc($sql_location_tags)) {

                        $location_tag_id = intval($row['tag_id']);
                        $location_tag_name = escapeHtml($row['tag_name']);
                        $location_tag_color = escapeHtml($row['tag_color']);
                        if (empty($location_tag_color)) {
                            $location_tag_color = "dark";
                        }
                        $location_tag_icon = escapeHtml($row['tag_icon']);
                        if (empty($location_tag_icon)) {
                            $location_tag_icon = "tag";
                        }

                        $location_tag_id_array[] = $location_tag_id;
                        $location_tag_name_display_array[] = "<a href='locations.php?{$client_url}tags[]=$location_tag_id'><span class='badge text-light p-1 me-1' style='background-color: $location_tag_color;'><i class='fa fa-fw fa-$location_tag_icon me-2'></i>$location_tag_name</span></a>";
                    }
                    $location_tags_display = implode('', $location_tag_name_display_array);

                    ?>
                    <tr>
                        <td class="checkbox-column bg-light border-end">
                            <div class="form-check">
                                <input class="form-check-input bulk-select" type="checkbox" name="location_ids[]" value="<?= $location_id ?>" aria-label="Select location <?= $location_name ?>">
                            </div>
                        </td>
                        <td>
                            <a class="text-dark ajax-modal" href="#" data-modal-url="modals/location/location_edit.php?id=<?= $location_id ?>">
                                <div class="d-flex">
                                    <i class="fa fa-fw fa-2x fa-map-marker-alt me-2"></i>
                                    <div class="flex-grow-1">
                                        <div <?php if($location_primary) { echo "class='text-bold'"; } ?>><?= $location_name ?></div>
                                        <div><small class="text-secondary"><?= $location_description ?></small></div>
                                        <div><?= $location_primary_display ?></div>
                                         <?php
                                        if (!empty($location_tags_display)) { ?>
                                            <div class="mt-1">
                                                <?= $location_tags_display ?>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            </a>
                        </td>
                        <td>
                            <a href="//maps.<?= $session_map_source ?>.com?q=<?= "$location_address $location_zip" ?>"
                                target="_blank" rel="noopener"><?= $full_address ?>
                            </a>
                        </td>
                        <td>
                            <?= $location_phone_display ?>
                            <?= $location_fax_display ?>
                        </td>
                        <td><?= $location_hours_display ?></td>
                        <?php if (!$client_url) { ?>
                        <td><a href="locations.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                        <?php } ?>
                        <td>
                            <div class="dropdown dropstart text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions for <?= $location_name ?>">
                                    <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/location/location_edit.php?id=<?= $location_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <?php if ($session_user_role == 3 && $location_primary == 0) { ?>
                                        <?php if ($location_archived_at) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-info confirm-link" href="post.php?restore_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-redo me-2"></i>Restore
                                        </a>
                                        <?php if ($config_destructive_deletes_enable) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                        <?php } ?>
                                        <?php } else { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-archive me-2"></i>Archive
                                        </a>
                                        <?php } ?>

                                    <?php } ?>
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>
        </div>
        <?php } ?>
    </form>
    <?php require_once "../includes/filter_footer.php"; ?>
</section>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
