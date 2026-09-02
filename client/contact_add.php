<?php
/*
 * Client Portal
 * Contact management for PTC / technical contacts
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

enforceContactCan('contacts');

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="contacts.php">Contacts</a>
        </li>
        <li class="breadcrumb-item active">Add Contact</li>
    </ol>

    <div class="n45-form-surface">
        <div class="n45-form-intro">
            <h1>Add a contact</h1>
            <p>Create a portal contact and choose which client responsibilities they can manage.</p>
        </div>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="mb-3">
                <label for="contactName">Name <strong class="text-danger">*</strong></label>
                <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    <input type="text" class="form-control" id="contactName" name="contact_name" placeholder="Full name" required maxlength="200">
                </div>
            </div>

            <div class="mb-3">
                <label for="contactEmail">Email <strong class="text-danger">*</strong></label>
                <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                    <input type="email" class="form-control" id="contactEmail" name="contact_email" placeholder="name@company.com" required maxlength="200">
                </div>
            </div>

            <fieldset class="n45-permission-group">
                <legend>Portal access</legend>
                <p class="n45-field-help">Choose what support and inventory information this person can see.</p>
                <div class="n45-choice-row">
                    <label class="n45-choice-option" for="portalRoleUser">
                        <input type="radio" id="portalRoleUser" name="contact_portal_role" value="user" checked>
                        <span><strong>Portal user</strong><small>Only their tickets and assigned assets</small></span>
                    </label>
                    <label class="n45-choice-option" for="portalRoleManager">
                        <input type="radio" id="portalRoleManager" name="contact_portal_role" value="manager">
                        <span><strong>Portal manager</strong><small>All organization tickets and assets</small></span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="n45-permission-group">
                <legend>Additional permissions</legend>
                <p class="n45-field-help">These permissions are independent of the portal access role.</p>
                <div class="n45-permission-grid">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactBillingCheckbox" name="contact_billing" value="1">
                        <label class="custom-control-label" for="contactBillingCheckbox"><span><strong>Billing</strong><small>Invoices, quotes, and payment methods</small></span></label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactTechnicalCheckbox" name="contact_technical" value="1">
                        <label class="custom-control-label" for="contactTechnicalCheckbox"><span><strong>Technical contact</strong><small>Technical records and service notifications</small></span></label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="contactManageContactsCheckbox" name="contact_portal_manage_contacts" value="1">
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
                        <option value="local">Local (Email and password)</option>
                        <?php if (!empty($config_azure_client_id)) { ?>
                            <option value="azure">Azure (Microsoft 365)</option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="n45-form-actions">
                <button class="btn btn-primary" name="add_contact"><i class="fas fa-user-plus" aria-hidden="true"></i>Add contact</button>
                <a class="btn btn-secondary" href="contacts.php">Cancel</a>
            </div>
        </form>
    </div>


<?php
require_once "includes/footer.php";
