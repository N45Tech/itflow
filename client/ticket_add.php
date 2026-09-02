<?php
/*
 * Client Portal
 * New ticket form
 */

require_once 'includes/inc_all.php';

// Portal users may select assigned assets; portal managers may select any client asset.
$asset_contact_scope = contactCan('assets_all') ? '' : "AND asset_contact_id = $session_contact_id";
$sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_client_id = $session_client_id $asset_contact_scope AND asset_archived_at IS NULL ORDER BY asset_name ASC");

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="tickets.php">Tickets</a>
        </li>
        <li class="breadcrumb-item active">New Ticket</li>
    </ol>

    <div class="n45-form-surface">
        <div class="n45-form-intro">
            <h1>How can we help?</h1>
            <p>Tell us what is happening and include the device or service involved. Your request will go directly to the N45 service desk.</p>
        </div>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label for="ticketSubject">Subject <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                    </div>
                    <input type="text" class="form-control" id="ticketSubject" name="subject" placeholder="Briefly describe the issue" required>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="form-group"><label for="ticketWorkType">What do you need?</label><select class="form-control" id="ticketWorkType" name="work_type" required><option value="request">A service or access request</option><option value="incident">Something is broken or unavailable</option><option value="onboarding">Onboarding or offboarding</option><option value="change">A planned change</option></select></div>
                </div>

                <div class="col">
                    <div class="form-group">
                    <label for="ticketCategory">Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <select class="form-control select2" id="ticketCategory" name="category">
                            <option value="0">- No Category -</option>
                            <?php
                            $sql_categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
                            while ($row = mysqli_fetch_assoc($sql_categories)) {
                                $category_id = intval($row['category_id']);
                                $category_name = escapeHtml($row['category_name']);

                                ?>
                                <option value="<?= $category_id ?>"><?= $category_name ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>
                </div>
            </div>

            <div class="row">
                <div class="col"><div class="form-group"><label for="ticketImpact">How broad is the impact?</label><select class="form-control" id="ticketImpact" name="impact" required><option value="low">One person or minor inconvenience</option><option value="medium" selected>Several people or important function</option><option value="high">Most users or major function</option><option value="critical">Whole business or safety/security risk</option></select></div></div>
                <div class="col"><div class="form-group"><label for="ticketUrgency">How quickly is help needed?</label><select class="form-control" id="ticketUrgency" name="urgency" required><option value="low">Can be scheduled</option><option value="medium" selected>Normal business priority</option><option value="high">Work is significantly blocked</option><option value="critical">Immediate response required</option></select></div></div>
            </div>

            <?php if (mysqli_num_rows($sql_assets) > 0) { ?>
                <div class="form-group">
                    <label for="ticketAsset">Affected device</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                        </div>
                        <select class="form-control select2" id="ticketAsset" name="asset">
                            <option value="0">- None -</option>
                            <?php

                            while ($row = mysqli_fetch_assoc($sql_assets)) {
                                $asset_id = intval($row['asset_id']);
                                $asset_name = escapeSql($row['asset_name']);
                                $asset_type = escapeSql($row['asset_type']);
                                ?>
                                <option value="<?= $asset_id ?>"><?= "$asset_name ($asset_type)" ?></option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>
            <?php } ?>


            <div class="form-group">
                <label for="ticketDetails">Details <strong class="text-danger">*</strong></label>
                <textarea class="form-control tinymce" id="ticketDetails" name="details"></textarea>
            </div>

            <div class="n45-form-actions">
                <button class="btn btn-primary" name="add_ticket"><i class="far fa-paper-plane" aria-hidden="true"></i>Send request</button>
                <a class="btn btn-secondary" href="tickets.php">Cancel</a>
            </div>

        </form>
    </div>

<?php
require_once 'includes/footer.php';
