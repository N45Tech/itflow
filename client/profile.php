<?php
/*
 * Client Portal
 * User profile
 */

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

?>

<header class="n45-page-header">
    <div>
        <h1>Your account</h1>
        <p>Review your portal identity, client responsibilities, and sign-in method.</p>
    </div>
</header>

<section class="n45-account-panel" aria-labelledby="account-details-heading">
    <div class="n45-form-intro">
        <h2 id="account-details-heading">Account details</h2>
        <p>This information is managed as part of your N45 client record.</p>
    </div>

    <dl class="n45-account-grid">
        <div class="n45-account-field"><dt>Name</dt><dd><?= stripslashes(escapeHtml($session_contact_name)) ?></dd></div>
        <div class="n45-account-field"><dt>Email</dt><dd><?= escapeHtml($session_contact_email) ?></dd></div>
        <div class="n45-account-field"><dt>Client</dt><dd><?= escapeHtml($session_client_name) ?></dd></div>
        <div class="n45-account-field"><dt>Support PIN</dt><dd><?= escapeHtml($session_contact_pin) ?></dd></div>
        <div class="n45-account-field"><dt>Primary contact</dt><dd><?= $session_contact_primary == 1 ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Technical contact</dt><dd><?= $session_contact_is_technical_contact ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Billing contact</dt><dd><?= $session_contact_is_billing_contact == $session_contact_id ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Sign-in method</dt><dd><?= escapeHtml(ucfirst($_SESSION['login_method'])) ?></dd></div>
    </dl>

    <?php if ($_SESSION['login_method'] == 'local'): ?>
        <div class="n45-account-security">
            <h2>Password</h2>
            <p class="text-muted">Use at least eight characters and avoid reusing a password from another service.</p>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label for="currentPassword">Current password</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-key" aria-hidden="true"></i></span>
                        </div>
                        <input type="password" class="form-control" id="currentPassword" required name="current_password" autocomplete="current-password">
                    </div>
                </div>
                <div class="form-group">
                    <label for="newPassword">New password</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-lock" aria-hidden="true"></i></span>
                        </div>
                        <input type="password" class="form-control" id="newPassword" minlength="8" required data-toggle="password" name="new_password" placeholder="Enter a new password" autocomplete="new-password">
                    </div>
                </div>
                <div class="n45-form-actions">
                    <button type="submit" name="edit_profile" class="btn btn-primary"><i class="fas fa-check" aria-hidden="true"></i>Update password</button>
                </div>
            </form>
        </div>
    <?php endif ?>
</section>

<?php
require_once 'includes/footer.php';
