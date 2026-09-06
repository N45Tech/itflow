<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

// Tickets with no client stay visible to restricted agents - clientScopeSql() includes 0
$access_permission_query_overide = clientScopeSql('ticket_client_id');

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT client_id, client_name, ticket_asset_id, ticket_assigned_to, ticket_billable,
    ticket_category, ticket_contact_id, ticket_created_at, ticket_details, ticket_due_at,
    ticket_location_id, ticket_number, ticket_prefix, ticket_priority, ticket_project_id,
    ticket_work_type, ticket_impact, ticket_urgency, ticket_subject, ticket_vendor_id,
    ticket_vendor_ticket_number FROM tickets LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL $access_permission_query_overide LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_id = intval($row['client_id']);
$client_name = escapeHtml($row['client_name']);
$ticket_prefix = escapeHtml($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_category = intval($row['ticket_category']);
$ticket_subject = escapeHtml($row['ticket_subject']);
$ticket_details = escapeHtml($row['ticket_details']);
$ticket_priority = escapeHtml($row['ticket_priority']);
$ticket_work_type = (string) $row['ticket_work_type'];
$ticket_impact = (string) $row['ticket_impact'];
$ticket_urgency = (string) $row['ticket_urgency'];
$ticket_billable = intval($row['ticket_billable']);
$ticket_vendor_ticket_number = escapeHtml($row['ticket_vendor_ticket_number']);
$ticket_created_at = escapeHtml($row['ticket_created_at']);
$ticket_due_at = escapeHtml($row['ticket_due_at']);
$ticket_assigned_to = intval($row['ticket_assigned_to']);
$contact_id = intval($row['ticket_contact_id']);
$asset_id = intval($row['ticket_asset_id']);
$location_id = intval($row['ticket_location_id']);
$vendor_id = intval($row['ticket_vendor_id']);
$project_id = intval($row['ticket_project_id']);

if ($client_id) {
    enforceClientAccess();
}

// Additional Assets Selected
$additional_assets_array = array();
$sql_additional_assets = mysqli_query($mysqli, "SELECT asset_id FROM ticket_assets WHERE ticket_id = $ticket_id");
while ($row = mysqli_fetch_assoc($sql_additional_assets)) {
    $additional_asset_id = intval($row['asset_id']);
    $additional_assets_array[] = $additional_asset_id;
}

ob_start();

?>

<div class="modal-header bg-dark text-light">
    <h5 class="modal-title"><i class="fa fa-fw fa-life-ring me-2"></i>Ticket: <strong><?= "$ticket_prefix$ticket_number" ?></strong> - <?= $client_name ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">
        <?php if ($client_id) { ?>
        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-details"><i class="fa fa-fw fa-life-ring me-2"></i>Details</a>
            </li>

            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-contacts"><i class="fa fa-fw fa-users me-2"></i>Contact</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-assignment"><i class="fa fa-fw fa-desktop me-2"></i>Assignment</a>
            </li>

        </ul>
        <hr>
        <?php } ?>

        <div class="tab-content" <?php if (lookupUserPermission('module_support') <= 1) { echo 'inert'; } ?>>

            <div class="tab-pane fade show active" id="pills-details">

                <div class="mb-3">
                    <label>Subject <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                        <input type="text" class="form-control" name="subject" maxlength="500" value="<?= $ticket_subject ?>" placeholder="Subject" required>
                    </div>
                </div>

                <div class="mb-3">
                    <textarea class="form-control tinymceTicket" rows="8" name="details"><?= $ticket_details ?></textarea>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="ticket-edit-work-type">Work type</label>
                            <select class="form-select" id="ticket-edit-work-type" name="work_type" required>
                                <?php foreach (ticketWorkTypeDefinitions() as $key => $label) { ?>
                                    <option value="<?= escapeHtml($key) ?>" <?= $ticket_work_type === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="ticket-edit-impact">Impact</label>
                            <select class="form-select ticket-assessment-input" id="ticket-edit-impact" name="impact" required>
                                <?php foreach (ticketImpactDefinitions() as $key => $label) { ?>
                                    <option value="<?= escapeHtml($key) ?>" <?= $ticket_impact === $key ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key)) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="ticket-edit-urgency">Urgency</label>
                            <select class="form-select ticket-assessment-input" id="ticket-edit-urgency" name="urgency" required>
                                <?php foreach (ticketUrgencyDefinitions() as $key => $label) { ?>
                                    <option value="<?= escapeHtml($key) ?>" <?= $ticket_urgency === $key ? 'selected' : '' ?>><?= escapeHtml(ucfirst($key)) ?></option>
                                    <?php } ?>
                                </select>
                            <small class="form-text text-muted">Priority: <strong id="ticket-edit-derived-priority"><?= $ticket_priority ?></strong></small>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <div class="mb-3">
                            <label>Category</label>
                            <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                                <select class="form-select select2" name="category_id">
                                    <option value="0">- Uncategorized -</option>
                                    <?php
                                    $sql_categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_categories)) {
                                        $category_id = intval($row['category_id']);
                                        $category_name = escapeHtml($row['category_name']);

                                        ?>
                                        <option <?php if ($ticket_category == $category_id) {echo "selected";} ?> value="<?= $category_id ?>"><?= $category_name ?></option>
                                    <?php } ?>

                                </select>
                                    <button class="btn btn-secondary ajax-modal" type="button"
                                        data-modal-url="../admin/modals/category/category_add.php?category=Ticket">
                                        <i class="fas fa-fw fa-plus"></i>
                                    </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <div class="mb-3">
                            <label>Assign to</label>
                            <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                                <select class="form-select select2" name="assigned_to" id="ticket-edit-assigned-to" data-current-assignee="<?= $ticket_assigned_to ?>">
                                    <option value="0">Not Assigned</option>
                                    <?php

                                    $sql = mysqli_query(
                                        $mysqli,
                                        "SELECT user_id, user_name FROM users
                                        WHERE user_role_id > 1
                                        AND user_type = 1
                                        AND user_status = 1
                                        AND user_archived_at IS NULL
                                        ORDER BY user_name ASC"
                                    );
                                    while ($row = mysqli_fetch_assoc($sql)) {
                                        $user_id = intval($row['user_id']);
                                        $user_name = escapeHtml($row['user_name']); ?>
                                        <option <?php if ($ticket_assigned_to === $user_id) { echo "selected"; } ?> value="<?= $user_id ?>"><?= $user_name ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="mb-3">
                            <label>Due</label>
                            <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                                <input type="datetime-local" class="form-control" name="due" value="<?= $ticket_due_at ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 d-none" id="ticket-edit-handoff">
                    <p class="mb-3"><strong>Ownership handoff</strong><br><span class="text-muted">Required when the assignee changes so the next technician receives usable context.</span></p>
                    <div class="mb-3">
                        <label for="ticket-edit-handoff-reason">Why is ownership changing?</label>
                        <input class="form-control" id="ticket-edit-handoff-reason" name="handoff_reason" maxlength="500">
                    </div>
                    <div class="mb-3">
                        <label for="ticket-edit-handoff-state">Current state</label>
                        <textarea class="form-control" id="ticket-edit-handoff-state" name="handoff_current_state" rows="2" maxlength="500"></textarea>
                    </div>
                    <div>
                        <label for="ticket-edit-handoff-next">Next action</label>
                        <textarea class="form-control" id="ticket-edit-handoff-next" name="handoff_next_action" rows="2" maxlength="500"></textarea>
                    </div>
                </div>

                <?php if ($config_module_enable_accounting && lookupUserPermission("module_sales") >= 2) { ?>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="billable" <?php if ($ticket_billable == 1) { echo "checked"; } ?> value="1" id="billableSwitch<?= $ticket_id ?>">
                        <label class="form-check-label" for="billableSwitch<?= $ticket_id ?>">Mark Billable</label>
                    </div>
                </div>
                <?php } ?>

            </div>

            <?php if ($client_id) { ?>

            <div class="tab-pane fade" id="pills-contacts">

                <div class="mb-3">
                    <label>Contact</label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                        <select class="form-select select2" name="contact_id">
                            <option value="0">No One</option>
                            <?php
                            $sql_client_contacts_select = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_title, contact_primary, contact_technical FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_primary DESC, contact_technical DESC, contact_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_client_contacts_select)) {
                                $contact_id_select = intval($row['contact_id']);
                                $contact_name_select = escapeHtml($row['contact_name']);
                                $contact_primary_select = intval($row['contact_primary']);
                                if($contact_primary_select == 1) {
                                    $contact_primary_display_select = " (Primary)";
                                } else {
                                    $contact_primary_display_select = "";
                                }
                                $contact_technical_select = intval($row['contact_technical']);
                                if($contact_technical_select == 1) {
                                    $contact_technical_display_select = " (Technical)";
                                } else {
                                    $contact_technical_display_select = "";
                                }
                                $contact_title_select = escapeHtml($row['contact_title']);
                                if(!empty($contact_title_select)) {
                                    $contact_title_display_select = " - $contact_title_select";
                                } else {
                                    $contact_title_display_select = "";
                                }

                                ?>
                                <option value="<?= $contact_id_select ?>" <?php if ($contact_id_select  == $contact_id) { echo "selected"; } ?>><?= "$contact_name_select$contact_title_display_select$contact_primary_display_select$contact_technical_display_select" ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <?php if (!empty($config_smtp_host)) { ?>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="contact_notify" value="1" id="checkNotifyContact">
                            <label class="form-check-label" for="checkNotifyContact">
                                Send email notification
                            </label>
                        </div>
                    </div>
                <?php } ?>

            </div>

            <div class="tab-pane fade" id="pills-assignment">

                <div class="mb-3">
                    <label>Asset</label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                        <select class="form-select select2" name="asset_id">
                            <option value="0">- None -</option>
                            <?php

                            $sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, contact_name FROM assets LEFT JOIN contacts ON contact_id = asset_contact_id WHERE asset_client_id = $client_id AND asset_archived_at IS NULL ORDER BY asset_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_assets)) {
                                $asset_id_select = intval($row['asset_id']);
                                $asset_name_select = escapeHtml($row['asset_name']);
                                $asset_contact_name_select = escapeHtml($row['contact_name']);
                                ?>
                                <option <?php if ($asset_id == $asset_id_select) { echo "selected"; } ?> value="<?= $asset_id_select ?>"><?= "$asset_name_select - $asset_contact_name_select" ?></option>

                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label>Additional Assets</label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                        <select class="form-select select2" name="additional_assets[]" data-tags="true" data-placeholder="- Select Additional Assets -" multiple>
                            <option value=""></option>
                            <?php

                            $sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, contact_name FROM assets LEFT JOIN contacts ON contact_id = asset_contact_id WHERE asset_client_id = $client_id AND asset_id != $asset_id AND asset_archived_at IS NULL ORDER BY asset_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_assets)) {
                                $asset_id_select = intval($row['asset_id']);
                                $asset_name_select = escapeHtml($row['asset_name']);
                                $asset_contact_name_select = escapeHtml($row['contact_name']);
                            ?>
                                <option value="<?= $asset_id_select ?>"
                                    <?php if (in_array($asset_id_select, $additional_assets_array)) { echo "selected"; } ?>
                                    ><?= "$asset_name_select - $asset_contact_name_select" ?></option>

                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label>Location</label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                        <select class="form-select select2" name="location_id">
                            <option value="0">- None -</option>
                            <?php

                            $sql_locations = mysqli_query($mysqli, "SELECT location_id, location_name FROM locations WHERE location_client_id = $client_id AND location_archived_at IS NULL ORDER BY location_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_locations)) {
                                $location_id_select = intval($row['location_id']);
                                $location_name_select = escapeHtml($row['location_name']);
                                ?>
                                <option <?php if ($location_id == $location_id_select) { echo "selected"; } ?> value="<?= $location_id_select ?>"><?= $location_name_select ?></option>

                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="row">

                    <div class="col">

                        <div class="mb-3">
                            <label>Vendor</label>
                            <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                                <select class="form-select select2" name="vendor_id">
                                    <option value="0">- None -</option>
                                    <?php

                                    $sql_vendors = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_client_id = $client_id AND vendor_archived_at IS NULL ORDER BY vendor_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_vendors)) {
                                        $vendor_id_select = intval($row['vendor_id']);
                                        $vendor_name_select = escapeHtml($row['vendor_name']);
                                        ?>
                                        <option <?php if ($vendor_id == $vendor_id_select) { echo "selected"; } ?> value="<?= $vendor_id_select ?>"><?= $vendor_name_select ?></option>

                                        <?php
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                    </div>

                    <div class="col">

                        <div class="mb-3">
                            <label>Vendor Ticket Number</label>
                            <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                                <input type="text" class="form-control" name="vendor_ticket_number" placeholder="Vendor ticket number" maxlength="255" value="<?= $ticket_vendor_ticket_number ?>">
                            </div>
                        </div>

                    </div>

                </div>

                <div class="mb-3">
                    <label>Project</label>
                    <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-project-diagram"></i></span>
                        <select class="form-select select2" name="project_id">
                            <option value="0">- None -</option>
                            <?php

                            $sql_projects = mysqli_query($mysqli, "SELECT project_id, project_name FROM projects WHERE (project_client_id = $client_id OR project_client_id = 0) AND project_completed_at IS NULL AND project_archived_at IS NULL ORDER BY project_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_projects)) {
                                $project_id_select = intval($row['project_id']);
                                $project_name_select = escapeHtml($row['project_name']); ?>
                                <option <?php if ($project_id == $project_id_select) { echo "selected"; } ?> value="<?= $project_id_select ?>"><?= $project_name_select ?></option>

                            <?php } ?>
                        </select>
                    </div>
                </div>

            </div>
            <?php } // End client_id check ?>

        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<script>
(() => {
    const impact = document.getElementById('ticket-edit-impact');
    const urgency = document.getElementById('ticket-edit-urgency');
    const priority = document.getElementById('ticket-edit-derived-priority');
    const assignee = document.getElementById('ticket-edit-assigned-to');
    const handoff = document.getElementById('ticket-edit-handoff');
    const handoffFields = handoff ? handoff.querySelectorAll('input, textarea') : [];
    const score = {low: 1, medium: 2, high: 3};
    const refreshPriority = () => {
        const total = score[impact.value] + score[urgency.value];
        priority.textContent = total === 6 ? 'Urgent' : total === 5 ? 'High' : total >= 3 ? 'Medium' : 'Low';
    };
    const refreshHandoff = () => {
        const changed = assignee.value !== assignee.dataset.currentAssignee;
        handoff.classList.toggle('d-none', !changed);
        handoffFields.forEach((field) => field.required = changed);
    };
    impact.addEventListener('change', refreshPriority);
    urgency.addEventListener('change', refreshPriority);
    assignee.addEventListener('change', refreshHandoff);
    refreshPriority();
    refreshHandoff();
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
