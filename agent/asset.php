<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND assets.asset_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
    $client_url = '';
}
$asset_scope_query = clientScopeSql('assets.asset_client_id');

if (isset($_GET['asset_id'])) {
    $asset_id = intval($_GET['asset_id']);

    $sql = mysqli_query($mysqli, "SELECT asset_contact_id, asset_created_at, asset_description, asset_favorite, asset_id,
        asset_install_date, asset_location_id, asset_make, asset_model, asset_name, asset_notes,
        asset_os, asset_photo, asset_physical_location, asset_purchase_date,
        asset_purchase_reference, asset_serial, asset_status, asset_type, asset_uri, asset_uri_2,
        asset_uri_client, asset_vendor_id, asset_warranty_expire, client_id, client_name,
        contact_archived_at, contact_email, contact_extension, contact_mobile,
        contact_mobile_country_code, contact_name, contact_phone, contact_phone_country_code,
        interface_ip, interface_ipv6, interface_mac, interface_nat_ip, interface_network_id,
        location_archived_at, location_name FROM assets
        LEFT JOIN clients ON client_id = asset_client_id
        LEFT JOIN contacts ON asset_contact_id = contact_id
        LEFT JOIN locations ON asset_location_id = location_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE assets.asset_id = $asset_id
        $client_query
        $asset_scope_query
        LIMIT 1
    ");

    if (mysqli_num_rows($sql) == 0) {
        echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";

    } else {

        $row = mysqli_fetch_assoc($sql);
        $client_id = intval($row['client_id']);
        enforceClientAccess($client_id);
        $client_name = escapeHtml($row['client_name']);
        $asset_id = intval($row['asset_id']);
        $asset_type = escapeHtml($row['asset_type']);
        $asset_name = escapeHtml($row['asset_name']);
        $asset_description = escapeHtml($row['asset_description']);
        $asset_make = escapeHtml($row['asset_make']);
        $asset_model = escapeHtml($row['asset_model']);
        $asset_serial = escapeHtml($row['asset_serial']);
        $asset_os = escapeHtml($row['asset_os']);
        $asset_uri = escapeUrl($row['asset_uri']);
        $asset_uri_2 = escapeUrl($row['asset_uri_2']);
        $asset_uri_client = escapeUrl($row['asset_uri_client']);
        $asset_status = escapeHtml($row['asset_status']);
        $asset_purchase_reference = escapeHtml($row['asset_purchase_reference']);
        $asset_purchase_date = escapeHtml($row['asset_purchase_date']);
        $asset_warranty_expire = escapeHtml($row['asset_warranty_expire']);
        $asset_install_date = escapeHtml($row['asset_install_date']);
        $asset_photo = escapeHtml($row['asset_photo']);
        $asset_physical_location = escapeHtml($row['asset_physical_location']);
        $asset_notes = escapeHtml($row['asset_notes']);
        $asset_favorite = intval($row['asset_favorite']);
        $asset_created_at = escapeHtml($row['asset_created_at']);
        $asset_vendor_id = intval($row['asset_vendor_id']);
        $asset_location_id = intval($row['asset_location_id']);
        $asset_contact_id = intval($row['asset_contact_id']);

        $asset_ip = escapeHtml($row['interface_ip']);
        $asset_ipv6 = escapeHtml($row['interface_ipv6']);
        $asset_nat_ip = escapeHtml($row['interface_nat_ip']);
        $asset_mac = escapeHtml($row['interface_mac']);
        $asset_network_id = intval($row['interface_network_id']);

        $device_icon = getAssetIcon($asset_type);

        $contact_name = escapeHtml($row['contact_name']);
        $contact_email = escapeHtml($row['contact_email']);
        $contact_phone_country_code = escapeHtml($row['contact_phone_country_code']);
        $contact_phone = escapeHtml(formatPhoneNumber($row['contact_phone'], $contact_phone_country_code));
        $contact_extension = escapeHtml($row['contact_extension']);
        $contact_mobile_country_code = escapeHtml($row['contact_mobile_country_code']);
        $contact_mobile = escapeHtml(formatPhoneNumber($row['contact_mobile'], $contact_mobile_country_code));
        $contact_archived_at = escapeHtml($row['contact_archived_at']);
        if ($contact_archived_at) {
            $contact_name_display = "<span class='text-danger' title='Archived'><s>$contact_name</s></span>";
        } else {
            $contact_name_display = $contact_name;
        }
        $location_name = escapeHtml($row['location_name']);
        if (empty($location_name)) {
            $location_name = "-";
        }
        $location_archived_at = escapeHtml($row['location_archived_at']);
        if ($location_archived_at) {
            $location_name_display = "<span class='text-danger' title='Archived'><s>$location_name</s></span>";
        } else {
            $location_name_display = $location_name;
        }

        // Override Tab Title // No Sanitizing needed as this var will opnly be used in the tab title
        $page_title = $row['asset_name'];

        $sql_related_tickets = mysqli_query($mysqli, "
            SELECT tickets.*, users.*, ticket_statuses.*
            FROM tickets
            LEFT JOIN users ON ticket_assigned_to = user_id
            LEFT JOIN ticket_statuses ON ticket_status_id = ticket_status
            LEFT JOIN ticket_assets ON tickets.ticket_id = ticket_assets.ticket_id
            WHERE tickets.ticket_deleted_at IS NULL
                AND (ticket_asset_id = $asset_id OR ticket_assets.asset_id = $asset_id)
            GROUP BY tickets.ticket_id
            ORDER BY ticket_number DESC
        ");
        $ticket_count = mysqli_num_rows($sql_related_tickets);

        // Related Recurring Tickets Query
        $sql_related_recurring_tickets = mysqli_query($mysqli, "SELECT recurring_tickets.* FROM recurring_tickets
            LEFT JOIN recurring_ticket_assets ON recurring_tickets.recurring_ticket_id = recurring_ticket_assets.recurring_ticket_id
            WHERE recurring_ticket_asset_id = $asset_id OR recurring_ticket_assets.asset_id = $asset_id
            GROUP BY recurring_tickets.recurring_ticket_id
            ORDER BY recurring_ticket_next_run DESC"
        );
        $recurring_ticket_count = mysqli_num_rows($sql_related_recurring_tickets);

        // Related Documents
        $sql_related_documents = mysqli_query($mysqli, "SELECT 1 FROM asset_documents
            LEFT JOIN documents ON asset_documents.document_id = documents.document_id
            WHERE asset_documents.asset_id = $asset_id
            AND document_archived_at IS NULL
            ORDER BY document_name DESC"
        );
        $document_count = mysqli_num_rows($sql_related_documents);

        // Tags - many to many relationship
        $asset_tag_name_display_array = array();
        $asset_tag_id_array = array();
        $sql_asset_tags = mysqli_query($mysqli, "SELECT tag_color, tag_icon, tag_id, tag_name FROM asset_tags LEFT JOIN tags ON asset_tag_tag_id = tag_id WHERE asset_tag_asset_id = $asset_id ORDER BY tag_name ASC");
        while ($row = mysqli_fetch_assoc($sql_asset_tags)) {

            $asset_tag_id = intval($row['tag_id']);
            $asset_tag_name = escapeHtml($row['tag_name']);
            $asset_tag_color = escapeHtml($row['tag_color']);
            if (empty($asset_tag_color)) {
                $asset_tag_color = "dark";
            }
            $asset_tag_icon = escapeHtml($row['tag_icon']);
            if (empty($asset_tag_icon)) {
                $asset_tag_icon = "tag";
            }

            $asset_tag_id_array[] = $asset_tag_id;
            $asset_tag_name_display_array[] = "<a href='client_assets.php?client_id=$client_id&q=$asset_tag_name'><span class='badge text-light p-1 mr-1' style='background-color: $asset_tag_color;'><i class='fa fa-fw fa-$asset_tag_icon mr-2'></i>$asset_tag_name</span></a>";
        }
        $asset_tags_display = implode('', $asset_tag_name_display_array);

        // Network Interfaces
        $related_interfaces = endpointAssetInterfaceRows($asset_id);
        $interface_count = count($related_interfaces);

        // Related Files
        $sql_related_files = mysqli_query($mysqli, "SELECT file_created_at, file_description, file_ext, files.file_id, file_name FROM asset_files
            LEFT JOIN files ON asset_files.file_id = files.file_id
            WHERE asset_files.asset_id = $asset_id
            AND file_archived_at IS NULL
            AND files.file_deleted_at IS NULL
            ORDER BY file_name DESC"
        );
        $files_count = mysqli_num_rows($sql_related_files);
        // View Mode -- 0 List, 1 Thumbnail
        if (!empty($_GET['view'])) {
            $view = intval($_GET['view']);
        } else {
            $view = 0;
        }
        if ($view == 1) {
            $query_images = "AND (file_ext LIKE 'JPG' OR file_ext LIKE 'jpg' OR file_ext LIKE 'JPEG' OR file_ext LIKE 'jpeg' OR file_ext LIKE 'png' OR file_ext LIKE 'PNG' OR file_ext LIKE 'webp' OR file_ext LIKE 'WEBP')";
        } else {
            $query_images = '';
        }

        // Related Documents
        $sql_related_documents = mysqli_query($mysqli, "SELECT document_created_at, document_description, documents.document_id, document_name,
            document_updated_at, user_name FROM asset_documents, documents
            LEFT JOIN users ON document_created_by = user_id
            WHERE asset_documents.asset_id = $asset_id
            AND asset_documents.document_id = documents.document_id
            AND document_archived_at IS NULL
            ORDER BY document_name ASC"
        );
        $document_count = mysqli_num_rows($sql_related_documents);


        // Related Credentials Query
        $sql_related_credentials = mysqli_query($mysqli, "
            SELECT
                credentials.credential_id AS credential_id,
                credentials.credential_name,
                credentials.credential_description,
                credentials.credential_uri,
                credentials.credential_username,
                credentials.credential_password,
                credentials.credential_otp_secret,
                credentials.credential_note,
                credentials.credential_favorite,
                credentials.credential_contact_id,
                credentials.credential_asset_id
            FROM credentials
            LEFT JOIN credential_tags ON credential_tags.credential_id = credentials.credential_id
            LEFT JOIN tags ON tags.tag_id = credential_tags.tag_id
            WHERE credential_asset_id = $asset_id
              AND credential_archived_at IS NULL
            GROUP BY credentials.credential_id
            ORDER BY credential_name DESC
        ");
        $credential_count = mysqli_num_rows($sql_related_credentials);

        // Related Software Query
        $sql_related_software = mysqli_query(
            $mysqli,
            "SELECT software_expire, software_assets.software_id, software_key, software_license_type,
                software_name, software_notes, software_purchase, software_seats, software_type,
                software_version FROM software_assets
            LEFT JOIN software ON software_assets.software_id = software.software_id
            WHERE software_assets.asset_id = $asset_id
            AND software_archived_at IS NULL
            ORDER BY software_name DESC"
        );

        $software_count = mysqli_num_rows($sql_related_software);

        // Linked Services
        $sql_linked_services = mysqli_query($mysqli, "SELECT service_category, service_description, service_assets.service_id, service_importance,
            service_name FROM service_assets, services
            WHERE service_assets.asset_id = $asset_id
            AND service_assets.service_id = services.service_id
            ORDER BY service_name ASC"
        );
        $service_count = mysqli_num_rows($sql_linked_services);

        $linked_services = array();

        // Notes - 1 to many relationship
        $sql_related_notes = mysqli_query($mysqli, "SELECT asset_note, asset_note_created_at, asset_note_id, asset_note_type, user_name FROM asset_notes
            LEFT JOIN users ON asset_note_created_by = user_id
            WHERE asset_note_asset_id = $asset_id
            AND asset_note_archived_at IS NULL
            ORDER BY asset_note_created_at DESC"
        );
        $note_count = mysqli_num_rows($sql_related_notes);

        // Level.io device context, when this asset is managed by the native RMM integration.
        $level_asset_link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_asset_links.*,
            level_group_name FROM level_asset_links
            LEFT JOIN level_group_mappings USING (level_group_id)
            WHERE level_asset_id = $asset_id LIMIT 1"));
        $level_device_snapshot = [];
        if ($level_asset_link && !empty($level_asset_link['level_device_snapshot'])) {
            $decoded_level_snapshot = json_decode($level_asset_link['level_device_snapshot'], true);
            if (is_array($decoded_level_snapshot)) {
                $level_device_snapshot = $decoded_level_snapshot;
            }
        }
        $endpoint_record = endpointLoadUnifiedRecord($asset_id, $client_id);

        // Note type icons, read from the categories list so the seeded icons
        // stay the single source of truth
        $note_type_icons = array();
        $sql_note_type_icons = mysqli_query($mysqli, "SELECT category_name, category_icon FROM categories WHERE category_type = 'asset_note_type'");
        while ($row = mysqli_fetch_assoc($sql_note_type_icons)) {
            $note_type_icons[escapeHtml($row['category_name'])] = escapeHtml($row['category_icon']);
        }

        ?>

        <div class="row">

            <div class="col-md-3">

                <div class="card">
                    <div class="card-header">
                        <button type="button" class="btn btn-light float-right ajax-modal"
                            data-modal-url="modals/asset/asset_edit.php?id=<?= $asset_id ?>">
                            <i class="fas fa-fw fa-edit"></i>
                        </button>
                        <h4 class="text-bold"><i class="fa fa-fw text-secondary fa-<?= $device_icon; ?> mr-2"></i><?= $asset_name; ?>
                            <?php if ($asset_favorite) { ?><i class="fas fa-fw text-warning fa-star" title="Favorite"></i><?php } ?>
                        </h4>
                        <?php if ($asset_photo) { ?>
                            <img class="img-fluid img-circle p-3" alt="asset_photo" src="<?= "../uploads/clients/$client_id/$asset_photo"; ?>">
                        <?php } ?>
                        <?php if ($asset_description) { ?>
                            <div class="text-secondary"><?= $asset_description; ?></div>
                        <?php } ?>
                    </div>
                    <div class="card-body">
                        <?php if ($asset_tags_display) { ?>
                            <div>
                                <?= $asset_tags_display ?>
                            </div>
                        <?php } ?>
                        <?php if ($asset_type) { ?>
                            <div class="mt-1"><i class="fa fa-fw fa-tag text-secondary mr-2"></i><?= $asset_type; ?></div>
                        <?php }
                        if ($asset_make) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-circle text-secondary mr-2"></i><?= "$asset_make $asset_model"; ?></div>
                        <?php }
                        if ($asset_os) { ?>
                            <div class="mt-2"><i class="fab fa-fw fa-windows text-secondary mr-2"></i><?= "$asset_os"; ?></div>
                        <?php }
                        if ($asset_serial) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-barcode text-secondary mr-1"></i><span class="badge"><?= $asset_serial; ?></span></div>
                        <?php }
                        if ($asset_purchase_date) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-shopping-cart text-secondary mr-2"></i><?= date('Y-m-d', strtotime($asset_purchase_date)); ?></div>
                        <?php }
                        if ($asset_install_date) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-calendar-check text-secondary mr-2"></i><?= date('Y-m-d', strtotime($asset_install_date)); ?></div>
                        <?php }
                        if ($asset_warranty_expire) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-exclamation-triangle text-secondary mr-2"></i><?= date('Y-m-d', strtotime($asset_warranty_expire)); ?></div>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($level_asset_link) {
                    $level_device_online = intval($level_asset_link['level_device_online']);
                    $level_device_deleted = !empty($level_asset_link['level_device_deleted_at']);
                    $level_device_hostname = escapeHtml($level_asset_link['level_device_hostname']);
                    $level_group_name = escapeHtml($level_asset_link['level_group_name']);
                    $level_device_last_seen = escapeHtml($level_asset_link['level_device_last_seen_at']);
                    $level_device_last_synced = escapeHtml($level_asset_link['level_device_last_synced_at']);
                    $level_device_security_score = $level_asset_link['level_device_security_score'];
                    $level_device_sync_status = escapeHtml($level_asset_link['level_device_sync_status']);
                    $level_device_sync_message = escapeHtml($level_asset_link['level_device_sync_message']);
                    $level_device_role = escapeHtml(str_replace('_', ' ', $level_device_snapshot['role'] ?? ''));
                    $level_last_logged_in_user = escapeHtml($level_device_snapshot['last_logged_in_user'] ?? '');
                    $level_total_memory = intval($level_device_snapshot['total_memory'] ?? 0);
                    $level_cpu_cores = intval($level_device_snapshot['cpu_cores'] ?? 0);
                    $level_network_addresses = [];
                    $level_network_mac = '';
                    foreach (($level_device_snapshot['network_interfaces'] ?? []) as $level_network_interface) {
                        if (!is_array($level_network_interface)) {
                            continue;
                        }
                        foreach (($level_network_interface['ip_addresses'] ?? []) as $level_network_address) {
                            if (is_string($level_network_address) && !in_array($level_network_address, ['127.0.0.1', '::1'], true)) {
                                $level_network_addresses[$level_network_address] = true;
                            }
                        }
                        if ($level_network_mac === '' && !empty($level_network_interface['mac_address'])) {
                            $level_network_mac = escapeHtml($level_network_interface['mac_address']);
                        }
                    }
                    $level_network_addresses = array_slice(array_keys($level_network_addresses), 0, 3);
                    ?>
                    <div class="card card-dark">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-fw fa-satellite mr-2"></i>Level.io</h5>
                            <div class="card-tools">
                                <?php if ($level_device_sync_status === 'Conflict') { ?>
                                    <span class="badge badge-warning">Conflict</span>
                                <?php } elseif ($level_device_deleted) { ?>
                                    <span class="badge badge-secondary">Removed</span>
                                <?php } elseif ($level_device_online) { ?>
                                    <span class="badge badge-success">Online</span>
                                <?php } else { ?>
                                    <span class="badge badge-danger">Offline</span>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($level_device_sync_status !== 'Synced' && $level_device_sync_message) { ?>
                                <div class="alert alert-warning py-2 small"><?= $level_device_sync_message ?></div>
                            <?php } ?>
                            <div><i class="fas fa-fw fa-desktop text-secondary mr-2"></i><?= $level_device_hostname ?></div>
                            <?php if ($level_group_name) { ?><div class="mt-2"><i class="fas fa-fw fa-sitemap text-secondary mr-2"></i><?= $level_group_name ?></div><?php } ?>
                            <?php if ($level_device_role) { ?><div class="mt-2 text-capitalize"><i class="fas fa-fw fa-server text-secondary mr-2"></i><?= $level_device_role ?></div><?php } ?>
                            <?php if ($level_last_logged_in_user) { ?><div class="mt-2"><i class="fas fa-fw fa-user-clock text-secondary mr-2"></i><?= $level_last_logged_in_user ?></div><?php } ?>
                            <?php if ($level_cpu_cores || $level_total_memory) { ?>
                                <div class="mt-2"><i class="fas fa-fw fa-microchip text-secondary mr-2"></i><?= $level_cpu_cores ? "$level_cpu_cores cores" : '' ?><?= $level_cpu_cores && $level_total_memory ? ' / ' : '' ?><?= $level_total_memory ? escapeHtml(formatBytes($level_total_memory, 1)) . ' RAM' : '' ?></div>
                            <?php } ?>
                            <?php if ($level_network_addresses) { ?><div class="mt-2"><i class="fas fa-fw fa-network-wired text-secondary mr-2"></i><?= escapeHtml(implode(', ', $level_network_addresses)) ?></div><?php } ?>
                            <?php if ($level_network_mac) { ?><div class="mt-2"><i class="fas fa-fw fa-ethernet text-secondary mr-2"></i><span class="text-monospace"><?= $level_network_mac ?></span></div><?php } ?>
                            <?php if ($level_device_security_score !== null) { ?>
                                <div class="mt-2"><i class="fas fa-fw fa-shield-alt text-secondary mr-2"></i>Security score <?= intval($level_device_security_score) ?></div>
                            <?php } ?>
                            <?php if ($level_device_last_seen) { ?>
                                <div class="mt-2" title="<?= $level_device_last_seen ?>"><i class="fas fa-fw fa-eye text-secondary mr-2"></i>Seen <?= escapeHtml(timeAgo($level_device_last_seen)) ?></div>
                            <?php } ?>
                            <div class="mt-2" title="<?= $level_device_last_synced ?>"><i class="fas fa-fw fa-sync-alt text-secondary mr-2"></i>Synced <?= escapeHtml(timeAgo($level_device_last_synced)) ?></div>
                            <div class="mt-3 pt-2 border-top">
                                <a href="https://app.level.io/devices" target="_blank" rel="noopener noreferrer">Open Level devices <i class="fas fa-external-link-alt ml-1"></i></a>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <div class="card card-dark">
                    <div class="card-header">
                        <h5 class="card-title">Primary Network Interface</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($asset_ip) { ?>
                            <div><i class="fa fa-fw fa-globe text-secondary mr-2"></i><span class="text-monospace"><?= $asset_ip; ?></span></div>
                        <?php } ?>
                        <?php if ($asset_nat_ip) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-random text-secondary mr-2"></i><span class="text-monospace"><?= $asset_nat_ip; ?></span></div>
                        <?php }
                        if ($asset_mac) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-ethernet text-secondary mr-2"></i><span class="text-monospace"><?= $asset_mac; ?></span></div>
                        <?php }
                        if ($asset_uri) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-link text-secondary mr-2"></i><a href="<?= $asset_uri; ?>" target="_blank" title="<?= $asset_uri; ?>"><?= truncate($asset_uri, 40); ?></a></div>
                        <?php }
                        if ($asset_uri_2) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-link text-secondary mr-2"></i><a href="<?= $asset_uri_2; ?>" target="_blank" title="<?= $asset_uri_2; ?>"><?= truncate($asset_uri_2, 40); ?></a></div>
                        <?php } ?>
                        <?php
                        if ($asset_uri_client) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-link text-secondary mr-2"></i>Client URI: <a href="<?= $asset_uri_client; ?>" target="_blank" title="<?= $asset_uri_client ?>"><?= truncate($asset_uri_client, 40); ?></a></div>
                        <?php } ?>
                    </div>
                </div>


                <div class="card card-dark">
                    <div class="card-header">
                        <h5 class="card-title">Assignment</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($location_name) { ?>
                            <div><i class="fa fa-fw fa-map-marker-alt text-secondary mr-2"></i><?= $location_name_display; ?></div>
                        <?php }
                        if ($contact_name) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-user text-secondary mr-2"></i><?= $contact_name_display; ?></div>
                        <?php }
                        if ($contact_email) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-envelope text-secondary mr-2"></i><a href='mailto:<?= $contact_email; ?>'><?= $contact_email; ?></a><button class='btn btn-sm clipboardjs' data-clipboard-text='<?= $contact_email; ?>'><i class='far fa-copy text-secondary'></i></button></div>
                        <?php }
                        if ($contact_phone) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-phone text-secondary mr-2"></i><?= $contact_phone ?></div>
                        <?php }
                        if ($contact_extension) { ?>
                            <div class="mt-1"><i class="fa fa-fw text-secondary mr-2"></i><?= "ext. $contact_extension" ?></div>
                        <?php }
                        if ($contact_mobile) { ?>
                            <div class="mt-2"><i class="fa fa-fw fa-mobile-alt text-secondary mr-2"></i><?= $contact_mobile ?></div>
                        <?php } ?>

                    </div>
                </div>

                <div class="card card-dark mb-3">
                    <div class="card-header">
                        <h5 class="card-title">Additional Notes</h5>
                    </div>
                    <textarea class="form-control" rows=6 id="assetNotes" placeholder="Enter quick notes here" onblur="updateAssetNotes(<?= $asset_id ?>)"><?= $asset_notes ?></textarea>
                </div>

            </div>

            <div class="col-md-9">

                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a href="clients.php">Clients</a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="client_overview.php?client_id=<?= $client_id; ?>"><?= $client_name; ?></a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="assets.php?client_id=<?= $client_id; ?>">Assets</a>
                    </li>
                    <li class="breadcrumb-item active"><?= $asset_name; ?></li>
                </ol>

                <div class="btn-group mb-3">
                    <div class="dropdown dropleft mr-2">
                        <button type="button" class="btn btn-primary" data-toggle="dropdown"><i class="fas fa-plus mr-2"></i>New</button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add.php?<?= $client_url ?>&asset_id=<?= $asset_id ?>" data-modal-size="lg">
                                <i class="fa fa-fw fa-life-ring mr-2"></i>New Ticket
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/recurring_ticket/recurring_ticket_add.php?<?= $client_url ?>&asset_id=<?= $asset_id ?>" data-modal-size="lg">
                                <i class="fa fa-fw fa-recycle mr-2"></i>New Recurring Ticket
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/credential/credential_add.php?<?= $client_url ?>asset_id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-key mr-2"></i>New Credential
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/document/document_add.php?<?= $client_url ?>&asset_id=<?= $asset_id ?>" data-modal-size="lg">
                                <i class="fa fa-fw fa-file-alt mr-2"></i>New Document
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/file/file_upload.php?<?= $client_url ?>&asset_id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-upload mr-2"></i>Upload file(s)
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_note_add.php?id=<?= $asset_id ?>">
                                <i class="fas fa-fw fa-sticky-note mr-2"></i>New Note
                            </a>
                        </div>
                    </div>

                    <div class="dropdown dropleft">
                        <button type="button" class="btn btn-outline-primary" data-toggle="dropdown"><i class="fas fa-link mr-2"></i>Link</button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_link_software.php?id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-cube mr-2"></i>License
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_link_credential.php?id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-key mr-2"></i>Credential
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_link_service.php?id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-stream mr-2"></i>Service
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_link_document.php?id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-folder mr-2"></i>Document
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/asset/asset_link_file.php?id=<?= $asset_id ?>">
                                <i class="fa fa-fw fa-paperclip mr-2"></i>File
                            </a>
                        </div>
                    </div>
                </div>

                <?php require __DIR__ . '/includes/inc_asset_endpoint_record.php'; ?>

                <div class="card card-dark">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-1"><i class="fa fa-fw fa-ethernet mr-2"></i>Network Interfaces</h3>
                        <div class="card-tools">
                            <div class="btn-group">
                                <button type="button" class="btn btn-tool ajax-modal" data-modal-url="modals/asset/asset_interface_add.php?&asset_id=<?= $asset_id ?>">
                                    <i class="fas fa-plus mr-2"></i>New Interface
                                </button>
                                <button type="button" class="btn btn-tool dropdown-toggle dropdown-toggle-split" data-toggle="dropdown"></button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item text-dark" href="#" data-toggle="modal" data-target="#addMultipleAssetInterfacesModal">
                                        <i class="fa fa-fw fa-check-double mr-2"></i>Add Multiple
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-dark" href="#" data-toggle="modal" data-target="#importAssetInterfaceModal">
                                        <i class="fa fa-fw fa-upload mr-2"></i>Import
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-dark" href="#" data-toggle="modal" data-target="#exportAssetInterfaceModal">
                                        <i class="fa fa-fw fa-download mr-2"></i>Export
                                    </a>
                                </div>

                                <div class="dropdown ml-2" id="bulkActionButton" hidden>
                                    <button class="btn btn-tool dropdown-toggle" type="button" data-toggle="dropdown">
                                        <i class="fas fa-fw fa-layer-group mr-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item text-dark ajax-modal" href="#"
                                            data-modal-url="modals/asset/asset_interface_bulk_edit_network.php?client_id=<?= $client_id ?>"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-network-wired mr-2"></i>Assign Network
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-dark ajax-modal" href="#"
                                            data-modal-url="modals/asset/asset_interface_bulk_edit_type.php?client_id=<?= $client_id ?>"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-ethernet mr-2"></i>Set Type
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <button class="dropdown-item text-dark" type="submit" form="bulkActions" name="bulk_edit_asset_interface_ip_dhcp">
                                            <i class="fas fa-fw fa-list-ul mr-2"></i>Set to DHCP
                                        </button>
                                        <?php if (lookupUserPermission("module_support") === 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <button class="dropdown-item text-danger text-bold confirm-link" type="submit" form="bulkActions" name="bulk_delete_asset_interfaces">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                        </button>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <form id="bulkActions" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover table-sm">
                                <thead class="<?php if ($interface_count == 0) { echo "d-none"; } ?>">
                                    <tr>
                                        <td class="bg-light checkbox-column">
                                            <div class="form-check">
                                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox" onclick="checkAll(this)" onkeydown="checkAll(this)">
                                            </div>
                                        </td>
                                        <th>Name / Port</th>
                                        <th>Type</th>
                                        <th>Network</th>
                                        <th>IP</th>
                                        <th>MAC</th>
                                        <th>Connected To</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($related_interfaces as $row) { ?>
                                    <?php
                                        $interface_id       = intval($row['interface_id']);
                                        $interface_name     = escapeHtml($row['interface_name']);
                                        $interface_description = escapeHtml($row['interface_description']);
                                        $interface_type     = escapeHtml($row['interface_type']);
                                        $interface_mac      = escapeHtml($row['interface_mac']);
                                        $interface_ip       = escapeHtml($row['interface_ip']);
                                        $interface_nat_ip   = escapeHtml($row['interface_nat_ip']);
                                        $interface_ipv6     = escapeHtml($row['interface_ipv6']);
                                        $interface_primary  = intval($row['interface_primary']);
                                        $network_id         = intval($row['network_id']);
                                        $network_name       = escapeHtml($row['network_name']);
                                        $interface_notes    = escapeHtml($row['interface_notes']);

                                        // Prepare display text
                                        $interface_mac_display = $interface_mac ?: '-';
                                        $interface_ip_display  = $interface_ip ?: '-';
                                        $interface_type_display = $interface_type ?: '-';
                                        $network_name_display  = $network_name
                                            ? "<i class='fas fa-fw fa-network-wired mr-1'></i>$network_name"
                                            : '-';

                                        $connected_to_links = [];
                                        foreach ($row['connections'] ?? [] as $connection) {
                                            $connected_asset_id = intval($connection['connected_asset_id']);
                                            $connected_asset_name = escapeHtml($connection['connected_asset_name']);
                                            $connected_asset_type = escapeHtml($connection['connected_asset_type']);
                                            $connected_asset_icon = getAssetIcon($connected_asset_type);
                                            $connected_interface_name = escapeHtml($connection['connected_interface_name']);
                                            if ($connected_asset_name) {
                                                $connected_to_links[] = "<a class='ajax-modal d-block' href='#'
                                                    data-modal-size='lg'
                                                    data-modal-url='modals/asset/asset.php?id=$connected_asset_id'>
                                                    <strong><i class='fa fa-fw text-dark fa-$connected_asset_icon mr-1'></i>$connected_asset_name</strong> - $connected_interface_name
                                                </a>";
                                            }
                                        }
                                        $connected_to_display = $connected_to_links ? implode('', $connected_to_links) : '-';
                                    ?>
                                    <tr>
                                        <td class="bg-light checkbox-column">
                                            <div class="form-check">
                                                <input class="form-check-input bulk-select" type="checkbox" name="interface_ids[]" value="<?= $interface_id ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fa fa-fw fa-ethernet text-secondary mr-1"></i>
                                            <a class="text-dark ajax-modal" href="#"
                                                data-modal-url="modals/asset/asset_interface_edit.php?id=<?= $interface_id ?>">
                                                <?= $interface_name ?> <?php if($interface_primary) { echo "<small class='text-primary'>(Primary)</small>"; } ?>
                                            </a>
                                        </td>
                                        <td><?= $interface_type_display; ?></td>
                                        <td><?= $network_name_display; ?></td>
                                        <td class="text-monospace">
                                            <?= $interface_ip_display; ?>
                                            <div><small class="text-secondary"><?= $interface_ipv6 ?></small></div>
                                        </td>
                                        <td class="text-monospace"><?= $interface_mac_display; ?></td>
                                        <td><?= $connected_to_display; ?></td>
                                        <td>
                                            <div class="dropdown dropleft text-center">
                                                <button class="btn btn-tool btn-sm" type="button" data-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a class="dropdown-item ajax-modal" href="#"
                                                        data-modal-url="modals/asset/asset_interface_edit.php?id=<?= $interface_id ?>">
                                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                                    </a>
                                                    <?php if ($session_user_role == 3 && $interface_primary == 0): ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger text-bold" href="post.php?delete_asset_interface=<?= $interface_id; ?>&csrf_token=<?= $_SESSION['csrf_token']; ?>">
                                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                    <?php require __DIR__ . '/includes/inc_asset_network_observations.php'; ?>
                </div>

                <?php if (lookupUserPermission('module_credential')) { // Begin Credential Enforcement ?>

                <div class="card card-dark <?php if ($credential_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-fw fa-key mr-2"></i>Credentials</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>OTP</th>
                                    <th>URI</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_credentials)) {
                                    $credential_id = intval($row['credential_id']);
                                    $credential_name = escapeHtml($row['credential_name']);
                                    $credential_description = escapeHtml($row['credential_description']);
                                    $credential_uri = escapeHtml($row['credential_uri']);
                                    if (empty($credential_uri)) {
                                        $credential_uri_display = "-";
                                    } else {
                                        $credential_uri_display = "$credential_uri<button class='btn btn-sm clipboardjs' data-clipboard-text='$credential_uri'><i class='far fa-copy text-secondary'></i></button><a href='$credential_uri' target='_blank'><i class='fa fa-external-link-alt text-secondary'></i></a>";
                                    }
                                    $credential_username = escapeHtml(decryptCredentialEntry($row['credential_username']));
                                    if (empty($credential_username)) {
                                        $credential_username_display = "-";
                                    } else {
                                        $credential_username_display = "$credential_username<button class='btn btn-sm clipboardjs' data-clipboard-text='$credential_username'><i class='far fa-copy text-secondary'></i></button>";
                                    }
                                    $credential_otp_secret = escapeHtml($row['credential_otp_secret']);
                                    if (empty($credential_otp_secret)) {
                                        $otp_display = "-";
                                    } else {
                                        $otp_display = "<span onmouseenter='showOTPViaCredentialID($credential_id)'><i class='far fa-clock'></i> <span id='otp_$credential_id'><i>Hover..</i></span></span>";
                                    }
                                    $credential_note = escapeHtml($row['credential_note']);
                                    $credential_favorite = intval($row['credential_favorite']);
                                    $credential_contact_id = intval($row['credential_contact_id']);
                                    $credential_asset_id = intval($row['credential_asset_id']);

                                    // Tags
                                    $credential_tag_name_display_array = array();
                                    $credential_tag_id_array = array();
                                    $sql_credential_tags = mysqli_query($mysqli, "SELECT tag_color, tag_icon, credential_tags.tag_id, tag_name FROM credential_tags LEFT JOIN tags ON credential_tags.tag_id = tags.tag_id WHERE credential_id = $credential_id ORDER BY tag_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_credential_tags)) {

                                        $credential_tag_id = intval($row['tag_id']);
                                        $credential_tag_name = escapeHtml($row['tag_name']);
                                        $credential_tag_color = escapeHtml($row['tag_color']);
                                        if (empty($credential_tag_color)) {
                                            $credential_tag_color = "dark";
                                        }
                                        $credential_tag_icon = escapeHtml($row['tag_icon']);
                                        if (empty($credential_tag_icon)) {
                                            $credential_tag_icon = "tag";
                                        }

                                        $credential_tag_id_array[] = $credential_tag_id;
                                        $credential_tag_name_display_array[] = "<a href='credentials.php?client_id=$client_id&tags[]=$credential_tag_id'><span class='badge text-light p-1 mr-1' style='background-color: $credential_tag_color;'><i class='fa fa-fw fa-$credential_tag_icon mr-2'></i>$credential_tag_name</span></a>";
                                    }
                                    $credential_tags_display = implode('', $credential_tag_name_display_array);

                                    ?>
                                    <tr>
                                        <td>
                                            <i class="fa fa-fw fa-key text-secondary"></i>
                                            <a class="text-dark ajax-modal" href="#"
                                                data-modal-url="modals/credential/credential_edit.php?id=<?= $credential_id ?>">
                                                <?= $credential_name ?>
                                            </a>
                                        </td>
                                        <td><?= $credential_description; ?></td>
                                        <td><?= $credential_username_display; ?></td>
                                        <td>
                                            <button class="btn p-0" type="button" onclick="showPasswordViaCredentialID(this, <?= $credential_id ?>)"><i class="fas fa-2x fa-ellipsis-h text-secondary"></i><i class="fas fa-2x fa-ellipsis-h text-secondary"></i></button><button class="btn btn-sm" type="button" onclick="copyPasswordViaCredentialID(this, <?= $credential_id ?>)"><i class="far fa-copy text-secondary"></i></button>
                                        </td>
                                        <td><?= $otp_display; ?></td>
                                        <td><?= $credential_uri_display; ?></td>
                                        <td>
                                            <div class="dropdown dropleft text-center">
                                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-h"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a class="dropdown-item ajax-modal" href="#"
                                                        data-modal-url="modals/credential/credential_edit.php?id=<?= $credential_id ?>">
                                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                                    </a>
                                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#shareModal" onclick="populateShareModal(<?= "$client_id, 'Credential', $credential_id"; ?>)">
                                                        <i class="fas fa-fw fa-share-alt mr-2"></i>Share
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item" href="post.php?unlink_credential_from_asset&asset_id=<?= $asset_id; ?>&credential_id=<?= $credential_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-unlink mr-2"></i>Unlink
                                                    </a>
                                                    <?php if ($session_user_role == 3) { ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger text-bold" href="post.php?delete_credential=<?= $credential_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
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

                    </div>
                </div>

                <?php } // End Credential Enforcement ?>

                <div class="card card-dark <?php if ($software_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-2"><i class="fa fa-fw fa-cube mr-2"></i>Licenses</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/asset/asset_link_software.php?id=<?= $asset_id ?>">
                                <i class="fas fa-link mr-2"></i>Link Software
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead class="text-dark">
                                <tr>
                                    <th>Software</th>
                                    <th>Type</th>
                                    <th>License Type</th>
                                    <th>Seats</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_software)) {
                                    $software_id = intval($row['software_id']);
                                    $software_name = escapeHtml($row['software_name']);
                                    $software_version = escapeHtml($row['software_version']);
                                    $software_type = escapeHtml($row['software_type']);
                                    $software_license_type = escapeHtml($row['software_license_type']);
                                    $software_key = escapeHtml($row['software_key']);
                                    $software_seats = escapeHtml($row['software_seats']);
                                    $software_purchase = escapeHtml($row['software_purchase']);
                                    $software_expire = escapeHtml($row['software_expire']);
                                    $software_notes = escapeHtml($row['software_notes']);

                                    $seat_count = 0;

                                    // Asset Licenses
                                    $asset_licenses_sql = mysqli_query($mysqli, "SELECT asset_id FROM software_assets WHERE software_id = $software_id");
                                    $asset_licenses_array = array();
                                    while ($row = mysqli_fetch_assoc($asset_licenses_sql)) {
                                        $asset_licenses_array[] = intval($row['asset_id']);
                                        $seat_count = $seat_count + 1;
                                    }
                                    $asset_licenses = implode(',', $asset_licenses_array);

                                    // Contact Licenses
                                    $contact_licenses_sql = mysqli_query($mysqli, "SELECT contact_id FROM software_contacts WHERE software_id = $software_id");
                                    $contact_licenses_array = array();
                                    while ($row = mysqli_fetch_assoc($contact_licenses_sql)) {
                                        $contact_licenses_array[] = intval($row['contact_id']);
                                        $seat_count = $seat_count + 1;
                                    }
                                    $contact_licenses = implode(',', $contact_licenses_array);

                                    $linked_software[] = $software_id;

                                    ?>
                                    <tr>
                                        <td>
                                            <a class="text-dark ajax-modal" href="#"
                                                data-modal-url="modals/software/software_edit.php?id=<?= $software_id ?>">
                                                <?= "$software_name<br><span class='text-secondary'>$software_version</span>"; ?>
                                            </a>
                                        </td>
                                        <td><?= $software_type; ?></td>
                                        <td><?= $software_license_type; ?></td>
                                        <td><?= "$seat_count / $software_seats"; ?></td>
                                        <td class="text-center">
                                            <a href="post.php?unlink_software_from_asset&asset_id=<?= $asset_id; ?>&software_id=<?= $software_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary btn-sm" title="Unlink"><i class="fas fa-fw fa-unlink"></i></a>
                                        </td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($document_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-2"><i class="fa fa-fw fa-folder mr-2"></i>Documents</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/asset/asset_link_document.php?id=<?= $asset_id ?>">
                                <i class="fas fa-link mr-2"></i>Link Document
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead class="text-dark">
                                <tr>
                                    <th>Document Title</th>
                                    <th>By</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_documents)) {
                                    $document_id = intval($row['document_id']);
                                    $document_name = escapeHtml($row['document_name']);
                                    $document_description = escapeHtml($row['document_description']);
                                    $document_created_by = escapeHtml($row['user_name']);
                                    $document_created_at = escapeHtml($row['document_created_at']);
                                    $document_updated_at = escapeHtml($row['document_updated_at']);

                                    $linked_documents[] = $document_id;

                                    ?>

                                    <tr>
                                        <td>
                                            <div><a href="document.php?client_id=<?= $client_id; ?>&document_id=<?= $document_id; ?>"><?= $document_name; ?></a></div>
                                            <div class="text-secondary"><?= $document_description; ?></div>
                                        </td>
                                        <td><?= $document_created_by ?></td>
                                        <td><?= $document_created_at ?></td>
                                        <td><?= $document_updated_at ?></td>
                                        <td class="text-center">
                                            <a href="#" class="btn btn-dark btn-sm ajax-modal"
                                                data-modal-size="lg"
                                                data-modal-url="modals/document/document_view.php?id=<?= $document_id ?>">
                                                <i class="fas fa-fw fa-eye"></i>
                                            </a>
                                            <a href="post.php?unlink_asset_from_document&asset_id=<?= $asset_id; ?>&document_id=<?= $document_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary btn-sm" title="Unlink"><i class="fas fa-fw fa-unlink"></i></a>
                                        </td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($files_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-2"><i class="fa fa-fw fa-cube mr-2"></i>Files</h3>
                        <div class="card-tools">
                            <div class="btn-group">
                                <?php
                                if ($view == 0) {
                                ?>
                                <a href="?client_id=<?=$client_id?>&asset_id=<?=$asset_id?>&view=0" class="btn btn-primary"><i class="fas fa-list-ul"></i></a>
                                <a href="?client_id=<?=$client_id?>&asset_id=<?=$asset_id?>&view=1" class="btn btn-outline-secondary"><i class="fas fa-th-large"></i></a>
                                <?php
                                    } else {
                                ?>
                                <a href="?client_id=<?=$client_id?>&asset_id=<?=$asset_id?>&view=0" class="btn btn-outline-secondary"><i class="fas fa-list-ul"></i></a>
                                <a href="?client_id=<?=$client_id?>&asset_id=<?=$asset_id?>&view=1" class="btn btn-primary"><i class="fas fa-th-large"></i></a>
                                <?php
                                    }
                                ?>
                            </div>
                            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/asset/asset_link_file.php?id=<?= $asset_id ?>">
                                <i class="fas fa-link mr-2"></i>Link File
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead class="text-dark">
                                <tr>
                                    <th>Name</th>
                                    <th>Uploaded</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_files)) {
                                    $file_id = intval($row['file_id']);
                                    $file_name = escapeHtml($row['file_name']);
                                    $file_description = escapeHtml($row['file_description']);
                                    $file_ext = escapeHtml($row['file_ext']);
                                    if ($file_ext == 'pdf') {
                                        $file_icon = "file-pdf";
                                    } elseif ($file_ext == 'gz' || $file_ext == 'tar' || $file_ext == 'zip' || $file_ext == '7z' || $file_ext == 'rar') {
                                        $file_icon = "file-archive";
                                    } elseif ($file_ext == 'txt' || $file_ext == 'md') {
                                        $file_icon = "file-alt";
                                    } elseif ($file_ext == 'msg') {
                                        $file_icon = "envelope";
                                    } elseif ($file_ext == 'doc' || $file_ext == 'docx' || $file_ext == 'odt') {
                                        $file_icon = "file-word";
                                    } elseif ($file_ext == 'xls' || $file_ext == 'xlsx' || $file_ext == 'ods') {
                                        $file_icon = "file-excel";
                                    } elseif ($file_ext == 'pptx' || $file_ext == 'odp') {
                                        $file_icon = "file-powerpoint";
                                    } elseif ($file_ext == 'mp3' || $file_ext == 'wav' || $file_ext == 'ogg') {
                                        $file_icon = "file-audio";
                                    } elseif ($file_ext == 'mov' || $file_ext == 'mp4' || $file_ext == 'av1') {
                                        $file_icon = "file-video";
                                    } elseif ($file_ext == 'jpg' || $file_ext == 'jpeg' || $file_ext == 'png' || $file_ext == 'gif' || $file_ext == 'webp' || $file_ext == 'bmp' || $file_ext == 'tif') {
                                        $file_icon = "file-image";
                                    } else {
                                        $file_icon = "file";
                                    }
                                    $file_created_at = escapeHtml($row['file_created_at']);

                                    $linked_files[] = $file_id;

                                    ?>
                                    <tr>
                                        <td><a class="text-dark" href="file.php?file_id=<?= $file_id; ?>&action=view" target="_blank" ><?= "$file_name<br><span class='text-secondary'>$file_description</span>"; ?></a></td>
                                        <td><?= $file_created_at; ?></td>
                                        <td class="text-center">
                                            <a href="post.php?unlink_asset_from_file&asset_id=<?= $asset_id; ?>&file_id=<?= $file_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary btn-sm" title="Unlink"><i class="fas fa-fw fa-unlink"></i></a>
                                        </td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($recurring_ticket_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-fw fa-recycle mr-2"></i>Recurring Tickets</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead class="text-dark">
                                <tr>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th>Frequency</th>
                                    <th>Next Run</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_recurring_tickets)) {
                                    $recurring_ticket_id = intval($row['recurring_ticket_id']);
                                    $recurring_ticket_subject = escapeHtml($row['recurring_ticket_subject']);
                                    $recurring_ticket_priority = escapeHtml($row['recurring_ticket_priority']);
                                    $recurring_ticket_frequency = escapeHtml($row['recurring_ticket_frequency']);
                                    $recurring_ticket_next_run = escapeHtml($row['recurring_ticket_next_run']);
                                ?>

                                    <tr>
                                        <td class="text-bold">
                                            <a class="ajax-modal" href="#"
                                                data-modal-size="lg"
                                                data-modal-url="modals/recurring_ticket/recurring_ticket_edit.php?id=<?= $recurring_ticket_id; ?>">
                                                <?= $recurring_ticket_subject ?>
                                            </a>
                                        </td>

                                        <td><?= $recurring_ticket_priority ?></td>

                                        <td><?= $recurring_ticket_frequency ?></td>

                                        <td><?= $recurring_ticket_next_run ?></td>

                                        <td>
                                            <div class="dropdown dropleft text-center">
                                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-h"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a class="dropdown-item ajax-modal" href="#"
                                                        data-modal-size="lg"
                                                        data-modal-url="modals/recurring_ticket/recurring_ticket_edit.php?id=<?= $recurring_ticket_id; ?>">
                                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item" href="post.php?force_recurring_ticket=<?= $recurring_ticket_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fa fa-fw fa-paper-plane text-secondary mr-2"></i>Force Reoccur
                                                    </a>
                                                    <?php
                                                    if ($session_user_role == 3) { ?>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_recurring_ticket=<?= $recurring_ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                    </a>
                                                </div>
                                                <?php } ?>
                                            </div>
                                        </td>
                                    </tr>

                                <?php } ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($ticket_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fa fa-fw fa-life-ring mr-2"></i>Tickets</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover">
                                <thead class="text-dark">
                                <tr>
                                    <th>Number</th>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Assigned</th>
                                    <th>Last Response</th>
                                    <th>Created</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_tickets)) {
                                    $ticket_id = intval($row['ticket_id']);
                                    $ticket_prefix = escapeHtml($row['ticket_prefix']);
                                    $ticket_number = intval($row['ticket_number']);
                                    $ticket_subject = escapeHtml($row['ticket_subject']);
                                    $ticket_priority = escapeHtml($row['ticket_priority']);
                                    $ticket_status_id = intval($row['ticket_status_id']);
                                    $ticket_status_name = escapeHtml($row['ticket_status_name']);
                                    $ticket_status_color = escapeHtml($row['ticket_status_color']);
                                    $ticket_created_at = escapeHtml($row['ticket_created_at']);
                                    $ticket_updated_at = escapeHtml($row['ticket_updated_at']);
                                    if (empty($ticket_updated_at)) {
                                        if ($ticket_status_name == "Closed") {
                                            $ticket_updated_at_display = "<p>Never</p>";
                                        } else {
                                            $ticket_updated_at_display = "<p class='text-danger'>Never</p>";
                                        }
                                    } else {
                                        $ticket_updated_at_display = $ticket_updated_at;
                                    }
                                    $ticket_closed_at = escapeHtml($row['ticket_closed_at']);

                                    if ($ticket_priority == "Urgent") {
                                        $ticket_priority_display = "<span class='p-2 badge badge-dark'>$ticket_priority</span>";
                                    } elseif ($ticket_priority == "High") {
                                        $ticket_priority_display = "<span class='p-2 badge badge-danger'>$ticket_priority</span>";
                                    } elseif ($ticket_priority == "Medium") {
                                        $ticket_priority_display = "<span class='p-2 badge badge-warning'>$ticket_priority</span>";
                                    } elseif ($ticket_priority == "Low") {
                                        $ticket_priority_display = "<span class='p-2 badge badge-info'>$ticket_priority</span>";
                                    } else {
                                        $ticket_priority_display = "-";
                                    }
                                    $ticket_assigned_to = intval($row['ticket_assigned_to']);
                                    if (empty($ticket_assigned_to)) {
                                        if ($ticket_status_id == 5) {
                                            $ticket_assigned_to_display = "<p>Not Assigned</p>";
                                        } else {
                                            $ticket_assigned_to_display = "<p class='text-danger'>Not Assigned</p>";
                                        }
                                    } else {
                                        $ticket_assigned_to_display = escapeHtml($row['user_name']);
                                    }

                                    ?>

                                    <tr>
                                        <td><a href="ticket.php?client_id=<?= $client_id; ?>&ticket_id=<?= $ticket_id; ?>"><span class="badge badge-pill badge-secondary p-3"><?= "$ticket_prefix$ticket_number"; ?></span></a></td>
                                        <td><a href="ticket.php?client_id=<?= $client_id; ?>&ticket_id=<?= $ticket_id; ?>"><?= $ticket_subject; ?></a></td>
                                        <td><?= $ticket_priority_display; ?></td>
                                        <td>
                                            <span class='badge badge-pill text-light p-2' style="background-color: <?= $ticket_status_color; ?>"><?= $ticket_status_name; ?></span>
                                        </td>
                                        <td><?= $ticket_assigned_to_display; ?></td>
                                        <td><?= $ticket_updated_at_display; ?></td>
                                        <td><?= $ticket_created_at; ?></td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($service_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-2"><i class="fa fa-fw fa-stream mr-2"></i>Linked Services</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/asset/asset_link_service.php?id=<?= $asset_id ?>">
                                <i class="fas fa-link mr-2"></i>Link Service
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover dataTables" style="width:100%">
                                <thead class="text-dark">
                                <tr>
                                    <th>Service</th>
                                    <th>Category</th>
                                    <th>Importance</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_linked_services)) {
                                    $service_id = intval($row['service_id']);
                                    $service_name = escapeHtml($row['service_name']);
                                    $service_description = escapeHtml($row['service_description']);
                                    $service_category = escapeHtml($row['service_category']);
                                    $service_importance = escapeHtml($row['service_importance']);

                                    $linked_services[] = $service_id;

                                    ?>

                                    <tr>
                                        <td>
                                            <div><?= $service_name; ?></div>
                                            <div class="text-secondary"><?= $service_description; ?></div>
                                        </td>
                                        <td><?= $service_category; ?></td>
                                        <td><?= $service_importance; ?></td>
                                        <td class="text-center">
                                            <a href="post.php?unlink_service_from_asset&asset_id=<?= $asset_id; ?>&service_id=<?= $service_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary btn-sm" title="Unlink"><i class="fas fa-fw fa-unlink"></i></a>
                                        </td>
                                    </tr>

                                    <?php

                                }

                                ?>

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-dark <?php if ($note_count == 0) { echo "d-none"; } ?>">
                    <div class="card-header py-2">
                        <h3 class="card-title mt-2"><i class="fa fa-fw fa-sticky-note mr-2"></i>Notes</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-primary ajax-modal"
                                data-modal-url="modals/asset/asset_note_add.php?id=<?= $asset_id ?>">
                                <i class="fas fa-plus mr-2"></i>New Note
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive-sm">
                            <table class="table table-striped table-borderless table-hover dataTables" style="width:100%">
                                <thead class="text-dark">
                                <tr>
                                    <th>Type</th>
                                    <th>Note</th>
                                    <th>By</th>
                                    <th>Created</th>
                                    <th class="text-center">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_related_notes)) {
                                    $asset_note_id = intval($row['asset_note_id']);
                                    $asset_note_type = escapeHtml($row['asset_note_type']);
                                    $asset_note = nl2br(escapeHtml($row['asset_note']));
                                    $note_by = escapeHtml($row['user_name']);
                                    $asset_note_created_at = escapeHtml($row['asset_note_created_at']);

                                    // Get the corresponding icon for the note type
                                    $note_type_icon = !empty($note_type_icons[$asset_note_type]) ? $note_type_icons[$asset_note_type] : 'fa-sticky-note';

                                    ?>

                                    <tr>
                                        <td><i class="fa fa-fw <?= $note_type_icon ?> mr-2"></i><?= $asset_note_type ?></td>
                                        <td><?= $asset_note ?></td>
                                        <td><?= $note_by ?></td>
                                        <td><?= $asset_note_created_at ?></td>
                                        <td>
                                            <div class="dropdown dropleft text-center">
                                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-h"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a class="dropdown-item text-danger" href="post.php?archive_asset_note=<?= $asset_note_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                                    </a>
                                                    <?php if (lookupUserPermission("module_support") >= 3) { ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger text-bold" href="post.php?delete_asset_note=<?= $asset_note_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
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
                    </div>
                </div>

            </div>

        </div>

        <?php

        require_once "modals/share_modal.php";

        }

        ?>

    <script>
        function updateAssetNotes(asset_id) {
            var notes = document.getElementById("assetNotes").value;

            // Send a POST request to ajax.php as ajax.php with data contact_set_notes=true, contact_id=NUM, notes=NOTES
            jQuery.post(
                "ajax.php",
                {
                    asset_set_notes: 'TRUE',
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>',
                    asset_id: asset_id,
                    notes: notes
                }
            )
        }
    </script>

    <!-- JavaScript to Show/Hide Password Form Group -->
    <script>
        $(document).ready(function() {
            $('.authMethod').on('change', function() {
                var $form = $(this).closest('.authForm');
                if ($(this).val() === 'local') {
                    $form.find('.passwordGroup').show();
                } else {
                    $form.find('.passwordGroup').hide();
                }
            });
            $('.authMethod').trigger('change');
        });
    </script>

    <!-- Include scripts to fetch TOTP codes and passwords via the credential ID -->
    <script src="js/credential_show_otp_via_id.js"></script>
    <script src="js/credential_show_password_via_id.js"></script>

    <script src="../js/bulk_actions.js"></script>

    <?php
    require_once "modals/asset/asset_interface_multiple_add.php";
    require_once "modals/asset/asset_interface_import.php";
    require_once "modals/asset/asset_interface_export.php";

}

require_once "../includes/footer.php";
