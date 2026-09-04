<?php
/*
 * Client Portal
 * Password reset page
 */

header("Content-Security-Policy: default-src 'self'");

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/load_global_settings.php';


if (empty($config_smtp_host)) {
    header("Location: /login.php");
    exit();
}

// Check to see if client portal is enabled
if($config_client_portal_enable == 0) {
    echo "Client Portal is Disabled";
    exit();
}

require_once __DIR__ . "/../includes/session_init.php";

// Set Timezone after session
require_once "../includes/inc_set_timezone.php";

$ip = escapeSql(getIP());
$user_agent = escapeSql($_SERVER['HTTP_USER_AGENT']);

// Get Company Info
$company_sql = mysqli_query($mysqli, "SELECT company_name, company_phone FROM companies WHERE company_id = 1");
$company_results = mysqli_fetch_assoc($company_sql);
$company_name = escapeSql($company_results['company_name']);
$company_phone = escapeSql(formatPhoneNumber($company_results['company_phone']));
$company_name_display = $company_results['company_name'];

// Get settings from load_global_settings.php and sanitize them
$config_ticket_from_name = escapeSql($config_ticket_from_name);
$config_ticket_from_email = escapeSql($config_ticket_from_email);
$config_mail_from_name = escapeSql($config_mail_from_name);
$config_mail_from_email = escapeSql($config_mail_from_email);
$config_base_url = escapeSql($config_base_url);

DEFINE("WORDING_ERROR", "Something went wrong! Your link may have expired. Please request a new password reset e-mail.");

if ($_SERVER['REQUEST_METHOD'] == "POST") {

    /*
     * Send password reset email
     */
    if (isset($_POST['password_reset_email_request'])) {

        $email = escapeSql($_POST['email']);

        $sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, user_email, contact_client_id, user_id FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_auth_method = 'local' AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1");
        $row = mysqli_fetch_assoc($sql);

        if ($row['user_email'] == $email) {
            $id = intval($row['contact_id']);
            $user_id = intval($row['user_id']);
            $name = escapeSql($row['contact_name']);
            $client = intval($row['contact_client_id']);

            $token = randomString(32);
            $url = "https://$config_base_url/client/login_reset.php?email=$email&token=$token&client=$client";
            mysqli_query($mysqli, "UPDATE users SET user_password_reset_token = '$token' WHERE user_id = $user_id LIMIT 1");
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Contact', log_action = 'Modify', log_description = 'Sent a portal password reset e-mail for $email.', log_ip = '$ip', log_user_agent = '$user_agent', log_client_id = $client");

            // Send reset email
            $reset_email = renderN45Email('portal.password_reset', [
                'company_name' => $company_name,
                'contact_name' => $name,
                'action_url' => $url,
                'footer_email' => $config_ticket_from_email,
                'footer_phone' => $company_phone,
            ]);

            $data = [
                array_merge([
                    'from' => $config_mail_from_email,
                    'from_name' => $config_mail_from_name,
                    'recipient' => $email,
                    'recipient_name' => $name,
                ], n45EmailQueueFields($reset_email))
            ];
            $mail = addToMailQueue($data);

            // Error handling
            if ($mail !== true) {
                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Mail', notification = 'Failed to send email to $email'");
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Mail', log_action = 'Error', log_description = 'Failed to send email to $email regarding {$reset_email['subject']}. $mail'");
            }
            //End Mail IF
        }

        $_SESSION['login_message'] = "If your account exists, a reset link is on its way. Please allow a few minutes for it to reach you.";

    /*
     * Link is being used - Perform password reset
     */
    } elseif (isset($_POST['password_reset_set_password'])) {

        if (!isset($_POST['new_password']) || !isset($_POST['email']) || !isset($_POST['token']) || !isset($_POST['client'])) {
            $_SESSION['login_message'] = WORDING_ERROR;
        }

        $token = escapeSql($_POST['token']);
        $email = escapeSql($_POST['email']);
        $client = intval($_POST['client']);

        // Query user
        $sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, user_id, user_password_reset_token FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_password_reset_token = '$token' AND contact_client_id = $client AND user_auth_method = 'local' AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1");
        $user_row = mysqli_fetch_assoc($sql);
        $contact_id = intval($user_row['contact_id']);
        $user_id = intval($user_row['user_id']);
        $name = escapeSql($user_row['contact_name']);

        // Ensure the token is correct
        if (sha1($user_row['user_password_reset_token']) == sha1($token)) {

            // Set password, invalidate token, logging
            $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            mysqli_query($mysqli, "UPDATE users SET user_password = '$password', user_password_reset_token = NULL WHERE user_id = $user_id LIMIT 1");
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Contact User', log_action = 'Modify', log_description = 'Reset portal password for $email.', log_ip = '$ip', log_user_agent = '$user_agent', log_client_id = $client, log_user_id = $user_id");

            // Send confirmation email
            $reset_confirmation_email = renderN45Email('portal.password_reset_confirmation', [
                'company_name' => $company_name,
                'contact_name' => $name,
                'action_url' => "https://$config_base_url/login.php",
                'footer_email' => $config_ticket_from_email,
                'footer_phone' => $company_phone,
            ]);


            $data = [
                array_merge([
                    'from' => $config_mail_from_email,
                    'from_name' => $config_mail_from_name,
                    'recipient' => $email,
                    'recipient_name' => $name,
                ], n45EmailQueueFields($reset_confirmation_email))
            ];

            $mail = addToMailQueue($data);

            // Error handling
            if ($mail !== true) {
                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Mail', notification = 'Failed to send email to $email'");
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Mail', log_action = 'Error', log_description = 'Failed to send email to $email regarding {$reset_confirmation_email['subject']}. $mail'");
            }

            // Redirect to login page
            $_SESSION['login_message'] = "Password reset successfully!";
            header("Location: /login.php");
            exit();

        } else {
            $_SESSION['login_message'] = WORDING_ERROR;
        }

    }

}

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light" data-lte-color-mode="off">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= escapeHtml($company_name_display) ?> | Password Reset</title>

    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="../libs/fontawesome-free/css/all.min.css">

    <!--
    Favicon
    If Fav Icon exists else use the default one
    -->
    <?php if(file_exists('../uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="../uploads/favicon.ico">
    <?php } ?>

    <!-- Theme style -->
    <link rel="stylesheet" href="../libs/adminlte/css/adminlte.min.css">
    <link rel="stylesheet" href="../css/itflow_custom.css">

</head>

<body class="hold-transition login-page n45-auth-page">
<main class="login-box n45-auth-shell">
    <div class="login-logo n45-auth-brand">
        <span class="n45-auth-mark" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
        <span><?= escapeHtml($company_name_display) ?></span>
    </div>
    <div class="card">
        <div class="card-body login-card-body">

            <div class="n45-auth-heading">
                <h1>Reset your password</h1>
                <p>Use the email address connected to your client portal account.</p>
            </div>

            <form method="post">

                <?php
                /*
                 * Password reset form
                 */
                if (isset($_GET['token']) && isset($_GET['email']) && isset($_GET['client'])) {

                    $token = escapeSql($_GET['token']);
                    $email = escapeSql($_GET['email']);
                    $client = intval($_GET['client']);

                    $sql = mysqli_query($mysqli, "SELECT user_password_reset_token FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_password_reset_token = '$token' AND contact_client_id = $client LIMIT 1");
                    $user_row = mysqli_fetch_assoc($sql);

                    // Sanity check
                    if (sha1($user_row['user_password_reset_token']) == sha1($token)) { ?>

                        <div class="input-group mb-3">
                            <label class="sr-only" for="new-password">New password</label>
                            <input type="password" class="form-control" id="new-password" placeholder="New password" name="new_password" autocomplete="new-password" required minlength="8">
                            <div class="input-group-text">
                                <span class="fas fa-lock"></span>
                            </div>
                        </div>

                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="email" value="<?= $email ?>">
                        <input type="hidden" name="client" value="<?= $client ?>">

                        <button type="submit" class="btn btn-success w-100 mb-3" name="password_reset_set_password">Reset password</button>


                    <?php } else {

                        $_SESSION['login_message'] = WORDING_ERROR;

                    }


                    /*
                     * Else: Just show the form to request a reset token email
                     */
                } else { ?>

                    <div class="input-group mb-3">
                        <label class="sr-only" for="reset-email">Registered client email</label>
                        <input type="email" class="form-control" id="reset-email" placeholder="Registered client email" name="email" autocomplete="email" required autofocus>
                        <div class="input-group-text">
                            <span class="fas fa-envelope"></span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100 mb-3" name="password_reset_email_request">Reset my password</button>

                <?php }
                ?>

            </form>

            <p class="login-box-msg text-danger">
                <?php
                // Show feedback from session
                if (!empty($_SESSION['login_message'])) {
                    echo escapeHtml($_SESSION['login_message']);
                    unset($_SESSION['login_message']);
                }
                ?>
            </p>

            <a href="/login.php">Back to login</a>


        </div>
        <!-- /.login-card-body -->

    </div>
    <!-- /.div.card -->

</main>
<!-- /.login-box -->

<!-- jQuery -->

<!-- Bootstrap 4 -->
<script src="../libs/bootstrap/js/bootstrap.bundle.min.js"></script>

<!-- AdminLTE App -->
<script src="../libs/adminlte/js/adminlte.min.js"></script>

<!-- Prevents resubmit on refresh or back -->
<script src="../js/login_prevent_resubmit.js"></script>

</body>
</html>
