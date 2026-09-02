<?php
/*
 * Client Portal
 * Contact management for PTC / technical contacts
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

enforceContactCan('contacts');

// Check for a contact ID
if (!isset($_GET['id']) && !intval($_GET['id'])) {
    header("Location: contacts.php");
    exit();
}

$contact_id = intval($_GET['id']);

$sql_contact = mysqli_query(
    $mysqli, "SELECT contact_id, contact_name, contact_email, contact_primary, contact_technical, contact_billing,
        contact_portal_ticket_scope, contact_portal_asset_scope, contact_portal_manage_contacts, user_auth_method
    FROM contacts
    LEFT JOIN users ON user_id = contact_user_id
    WHERE contact_id = $contact_id AND contact_client_id = $session_client_id AND contacts.contact_archived_at IS NULL LIMIT 1"
);

$row = mysqli_fetch_assoc($sql_contact);

if ($row) {
    $contact_id = intval($row['contact_id']);
    $contact_name = escapeHtml($row['contact_name']);
    $contact_email = escapeHtml($row['contact_email']);
    $contact_primary = intval($row['contact_primary']);
    $contact_technical = intval($row['contact_technical']);
    $contact_billing = intval($row['contact_billing']);
    $contact_portal_role = portalAccessRoleFromScopes($row['contact_portal_ticket_scope'], $row['contact_portal_asset_scope']);
    $contact_portal_manage_contacts = intval($row['contact_portal_manage_contacts']);
    $contact_auth_method = escapeHtml($row['user_auth_method']);
} else {
    header("Location: post.php?logout");
    exit();
}

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="contacts.php">Contacts</a>
        </li>
        <li class="breadcrumb-item active">Edit Contact</li>
    </ol>

    <div class="n45-form-surface">
        <div class="n45-form-intro">
            <h1>Edit contact</h1>
            <p>Update contact details, client responsibilities, and portal access.</p>
        </div>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="contact_id" value="<?= $contact_id ?>">

            <div class="mb-3">
                <label for="contactName">Name <strong class="text-danger">*</strong></label>
                <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    <input type="text" class="form-control" id="contactName" name="contact_name" value="<?= escapeHtml($contact_name) ?>" required maxlength="200">
                </div>
            </div>

            <div class="mb-3">
                <label for="contactEmail">Email <strong class="text-danger">*</strong></label>
                <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                    <input type="email" class="form-control" id="contactEmail" name="contact_email" value="<?= escapeHtml($contact_email) ?>" required maxlength="200">
                </div>
            </div>

            <fieldset class="n45-permission-group">
                <legend>Portal access</legend>
                <p class="n45-field-help">Choose what support and inventory information this person can see.</p>
                <div class="n45-choice-row">
                    <label class="n45-choice-option" for="portalRoleUser">
                        <input type="radio" id="portalRoleUser" name="contact_portal_role" value="user" <?= $contact_portal_role === 'user' ? 'checked' : '' ?>>
                        <span><strong>Portal user</strong><small>Only their tickets and assigned assets</small></span>
                    </label>
                    <label class="n45-choice-option" for="portalRoleManager">
                        <input type="radio" id="portalRoleManager" name="contact_portal_role" value="manager" <?= $contact_portal_role === 'manager' ? 'checked' : '' ?>>
                        <span><strong>Portal manager</strong><small>All organization tickets and assets</small></span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="n45-permission-group">
                <legend>Additional permissions</legend>
                <p class="n45-field-help">These permissions are independent of the portal access role.</p>
                <div class="n45-permission-grid">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactBillingCheckbox" name="contact_billing" value="1" <?php if ($contact_billing == 1) { echo "checked"; } ?>>
                        <label class="custom-control-label" for="contactBillingCheckbox"><span><strong>Billing</strong><small>Invoices, quotes, and payment methods</small></span></label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactTechnicalCheckbox" name="contact_technical" value="1" <?php if ($contact_technical == 1) { echo "checked"; } ?>>
                        <label class="custom-control-label" for="contactTechnicalCheckbox"><span><strong>Technical contact</strong><small>Technical records and service notifications</small></span></label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactManageContactsCheckbox" name="contact_portal_manage_contacts" value="1" <?php if ($contact_portal_manage_contacts == 1) { echo "checked"; } ?>>
                        <label class="custom-control-label" for="contactManageContactsCheckbox"><span><strong>Manage contacts</strong><small>Create contacts and assign portal permissions</small></span></label>
                    </div>
                </div>
            </fieldset>

            <div class="mb-3">
                <label for="contactAuthMethod">Portal authentication</label>
                <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-user-circle"></i></span>
                    <select class="form-select select2 authMethod" id="contactAuthMethod" name="contact_auth_method">
                        <option value="">- No portal access -</option>
                        <option value="local" <?php if ($contact_auth_method == "local") { echo "selected"; } ?>>Local (Email and password)</option>
                        <?php if (!empty($config_azure_client_id)) { ?>
                            <option value="azure" <?php if ($contact_auth_method == "azure") { echo "selected"; } ?>>Azure (Microsoft 365)</option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="n45-form-actions">
                <?php if ($contact_primary || $contact_id == $_SESSION['contact_id']) { ?>
                    <span class="text-muted"><i class="fas fa-lock me-2" aria-hidden="true"></i>This protected contact cannot be changed here.</span>
                <?php } else { ?>
                    <button class="btn btn-primary" name="edit_contact"><i class="fas fa-check" aria-hidden="true"></i>Save changes</button>
                <?php } ?>
                <a class="btn btn-secondary" href="contacts.php">Back to contacts</a>
            </div>
        </form>
    </div>


<?php
require_once "includes/footer.php";
