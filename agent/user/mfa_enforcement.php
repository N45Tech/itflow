<?php
require_once "../../config.php";
require_once "../../functions.php";
require_once "../../includes/check_login.php";
require_once '../../libs/totp/totp.php'; //TOTP MFA Lib

// Get Company Logo
$sql = mysqli_query($mysqli, "SELECT company_logo FROM companies");
$row = mysqli_fetch_assoc($sql);
$company_logo = escapeHtml($row['company_logo']);


// Only generate the token once and store it in session:
if (empty($_SESSION['mfa_token'])) {
    $token = generateTotpSecret();
    $_SESSION['mfa_token'] = $token;
}
$token = $_SESSION['mfa_token'];

// Generate QR Code
$data = "otpauth://totp/ITFlow:$session_email?secret=$token";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="robots" content="noindex">

    <title>MFA Enforcement | <?= $session_company_name ?></title>

    <!--
    Favicon
    If Fav Icon exists else use the default one
    -->
    <?php if(file_exists('../../uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="../../uploads/favicon.ico">
    <?php } ?>

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="../../libs/fontawesome-free/css/all.min.css">

    <!-- Theme style -->
    <link rel="stylesheet" href="../../libs/adminlte/css/adminlte.min.css">
    <link rel="stylesheet" href="../../css/itflow_custom.css">

    <!-- jQuery -->

</head>
<body class="hold-transition login-page n45-auth-page">
    <?php require_once "../../includes/inc_alert_feedback.php"; ?>
    <main class="login-box n45-auth-shell">
        <div class="login-logo n45-auth-brand">
            <?php if (!empty($company_logo)) { ?>
                <img alt="<?= escapeHtml($session_company_name)?> logo" height="110" width="380" class="img-fluid" src="<?= "../../uploads/settings/$company_logo" ?>">
            <?php } else { ?>
                <span class="n45-auth-mark" aria-hidden="true"><i class="fas fa-layer-group"></i></span>
                <span><?= escapeHtml($session_company_name) ?></span>
            <?php } ?>
        </div>

        <!-- /.login-logo -->
        <div class="card">
            <div class="card-body login-card-body text-center">

                <div class="n45-auth-heading">
                    <h1>Secure your account</h1>
                    <p>Scan the QR code, then enter the six-digit code to finish setup.</p>
                </div>

                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <img class="n45-auth-qr" alt="Authenticator setup QR code" src='../../libs/barcode/barcode.php?f=png&s=qr&d=<?= $data ?>' data-bs-toggle="tooltip" title="Scan QR code into your MFA App">

                    <p>
                        <small data-bs-toggle="tooltip" title="Can't Scan? Copy and paste this code into your app"><?= $token ?></small>
                        <button type="button" class='btn btn-sm clipboardjs' aria-label="Copy setup code" data-clipboard-text='<?= $token ?>'><i class='far fa-copy text-secondary' aria-hidden="true"></i></button>
                    </p>

                    <div class="input-group mb-3">
                        <label class="sr-only" for="verify-code">Six-digit verification code</label>
                        <input type="text" class="form-control" id="verify-code" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" name="verify_code" placeholder="Six-digit verification code" autocomplete="one-time-code" required>
                        <div class="input-group-text">
                            <span class="fas fa-lock"></span>
                        </div>
                    </div>

                    <button type="submit" name="enable_mfa" class="btn btn-primary w-100 mb-3"><i class="fa fa-check me-2"></i>Enable MFA</button>
                </form>

            </div>
            <!-- /.login-card-body -->
        </div>
    </main>
    <!-- /.login-box -->

    <!-- REQUIRED SCRIPTS -->

    <!-- Bootstrap 4 -->
    <script src="/js/http.js"></script>
    <script src="../../libs/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Custom js-->
    <script src="../../libs/clipboardjs/clipboard.min.js"></script>

    <script>

    // Fade the alert out after 5s, then collapse it
    (function () {
        const alertEl = document.getElementById('alert');
        if (!alertEl) {
            return;
        }
        setTimeout(function () {
            alertEl.style.transition = 'opacity .5s linear';
            alertEl.style.opacity = '0';
            setTimeout(function () {
                alertEl.style.display = 'none';
            }, 500);
        }, 5000);
    })();

    // ClipboardJS

    // Tooltip - manual trigger only, so a copy flash can never stay stranded
    // on screen. This page is standalone and does not load js/app.js, so it
    // carries its own copy of the helper.
    function flashTooltip(button, message) {
        const el = button instanceof Element ? button : document.querySelector(button);
        if (!el) {
            return;
        }
        bootstrap.Tooltip.getInstance(el)?.dispose();
        const tip = new bootstrap.Tooltip(el, {
            trigger: 'manual',
            placement: 'bottom',
            title: message
        });
        tip.show();

        setTimeout(function () {
            tip.dispose();
        }, 1000);
    }

    // Clipboard

    var clipboard = new ClipboardJS('.clipboardjs');

    clipboard.on('success', function(e) {
        flashTooltip(e.trigger, 'Copied!');
    });

    clipboard.on('error', function(e) {
        flashTooltip(e.trigger, 'Failed!');
    });

    // Enable Popovers
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        bootstrap.Popover.getOrCreateInstance(el);
    });

    </script>

</body>

</html>
