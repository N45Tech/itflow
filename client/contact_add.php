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

            <div class="form-group">
                <label for="contactName">Name <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <input type="text" class="form-control" id="contactName" name="contact_name" placeholder="Full name" required maxlength="200">
                </div>
            </div>

            <div class="form-group">
                <label for="contactEmail">Email <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                    </div>
                    <input type="email" class="form-control" id="contactEmail" name="contact_email" placeholder="name@company.com" required maxlength="200">
                </div>
            </div>

            <label>Roles:</label>
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="contactBillingCheckbox" name="contact_billing" value="1">
                            <label class="custom-control-label" for="contactBillingCheckbox">Billing</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="contactTechnicalCheckbox" name="contact_technical" value="1">
                            <label class="custom-control-label" for="contactTechnicalCheckbox">Technical</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="contactAuthMethod">Portal authentication</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user-circle"></i></span>
                    </div>
                    <select class="form-control select2 authMethod" id="contactAuthMethod" name="contact_auth_method">
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
