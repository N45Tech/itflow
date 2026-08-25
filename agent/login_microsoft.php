<?php

/*
 * Technician sign-in via Microsoft Entra ID.
 *
 * Entra authenticates the technician. ITFlow then asks for the existing local
 * password only to unwrap the site's credential-vault key. The password is
 * never retained in the session.
 */

header("Content-Security-Policy: default-src 'self'");
header("Cache-Control: no-store");
header("Referrer-Policy: no-referrer");
header("X-Content-Type-Options: nosniff");

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/session_init.php';
require_once __DIR__ . '/../includes/inc_set_timezone.php';

$session_ip = escapeSql(getIP());
$session_user_agent = escapeSql($_SERVER['HTTP_USER_AGENT'] ?? '');
$session_user_id = intval($_SESSION['user_id'] ?? 0);

$settings_result = mysqli_query($mysqli, "SELECT
    companies.company_logo,
    companies.company_name,
    settings.config_azure_agent_sso_enable,
    settings.config_azure_client_id,
    settings.config_azure_client_secret,
    settings.config_azure_tenant_id,
    settings.config_login_key_required,
    settings.config_login_key_secret,
    settings.config_start_page,
    settings.config_whitelabel_enabled
    FROM settings
    LEFT JOIN companies ON settings.company_id = companies.company_id
    WHERE settings.company_id = 1");
$entra_settings = mysqli_fetch_assoc($settings_result);

$company_name = $entra_settings['company_name'] ?? 'ITFlow';
$company_logo = $entra_settings['company_logo'] ?? '';
$azure_agent_sso_enable = intval($entra_settings['config_azure_agent_sso_enable'] ?? 0);
$azure_client_id = strtolower(trim($entra_settings['config_azure_client_id'] ?? ''));
$azure_client_secret = $entra_settings['config_azure_client_secret'] ?? '';
$azure_tenant_id = strtolower(trim($entra_settings['config_azure_tenant_id'] ?? ''));
$config_login_key_required = intval($entra_settings['config_login_key_required'] ?? 0);
$config_login_key_secret = $entra_settings['config_login_key_secret'] ?? '';
$config_start_page = $entra_settings['config_start_page'] ?? 'clients.php';
$config_whitelabel_enabled = intval($entra_settings['config_whitelabel_enabled'] ?? 0);

$redirect_uri = 'https://' . rtrim($config_base_url, '/') . '/agent/login_microsoft.php';
$login_url = '/login.php';
if ($config_login_key_required === 1) {
    $login_url .= '?key=' . rawurlencode($config_login_key_secret);
}

function entraAgentFail($message, $log_description = '') {
    global $login_url, $session_user_id;

    if ($log_description !== '') {
        logAudit('Login', 'Entra Failed', $log_description, 0, $session_user_id);
    }

    unset($_SESSION['entra_agent_oauth'], $_SESSION['pending_entra_agent_login']);
    $_SESSION['login_message'] = $message;
    header('Location: ' . $login_url);
    exit();
}

function entraPendingExpired($pending, $ttl_seconds = 600) {
    return !is_array($pending)
        || empty($pending['created'])
        || time() - intval($pending['created']) > $ttl_seconds;
}

if (
    $azure_agent_sso_enable !== 1
    || !entraGuidIsValid($azure_client_id)
    || !entraGuidIsValid($azure_tenant_id)
    || empty($azure_client_secret)
) {
    entraAgentFail('Technician Microsoft sign-in is not configured. Use local sign-in or contact an administrator.');
}

if (
    $config_https_only
    && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')
    && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')
) {
    http_response_code(400);
    exit('Technician Microsoft sign-in requires HTTPS.');
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['entra_agent_oauth'], $_SESSION['pending_entra_agent_login']);
    header('Location: ' . $login_url);
    exit();
}

$unlock_response = '';

// The local password is used only after Entra has established the identity.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_vault'])) {
    $pending = $_SESSION['pending_entra_agent_login'] ?? null;
    $posted_token = is_string($_POST['pending_token'] ?? null) ? $_POST['pending_token'] : '';

    if (
        entraPendingExpired($pending)
        || empty($posted_token)
        || empty($pending['token'])
        || !hash_equals($pending['token'], $posted_token)
    ) {
        entraAgentFail('Your Microsoft sign-in expired. Please sign in again.');
    }

    $user_id = intval($pending['user_id'] ?? 0);
    $entra_oid = escapeSql($pending['oid'] ?? '');
    $entra_tenant_id = escapeSql($pending['tenant_id'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    // Serialize the check and failure write per account so parallel attempts
    // cannot all pass the same threshold before any of them are recorded.
    $unlock_lock_name = 'itflow_entra_vault_' . $user_id;
    $unlock_lock = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$unlock_lock_name', 10)"));
    if (empty($unlock_lock[0])) {
        error_log("ITFlow: timed out waiting on the Entra vault unlock lock for user $user_id");
    }

    $failed_unlocks = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(log_id) AS failure_count
        FROM logs
        WHERE log_user_id = $user_id
          AND log_type = 'Login'
          AND log_action = 'Vault Unlock Failed'
          AND log_created_at > (NOW() - INTERVAL 10 MINUTE)"));

    if (intval($failed_unlocks['failure_count'] ?? 0) >= 5) {
        if (!empty($unlock_lock[0])) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$unlock_lock_name')");
        }
        unset($_SESSION['pending_entra_agent_login']);
        http_response_code(429);
        entraAgentFail('Too many incorrect vault passwords. Wait 10 minutes and sign in again.');
    }

    $user_result = mysqli_query($mysqli, "SELECT
        users.user_email,
        users.user_name,
        users.user_password,
        users.user_specific_encryption_ciphertext
        FROM users
        WHERE user_id = $user_id
          AND user_type = 1
          AND user_status = 1
          AND user_archived_at IS NULL
          AND user_auth_method = 'azure'
          AND user_azure_oid = '$entra_oid'
          AND user_azure_tenant_id = '$entra_tenant_id'
        LIMIT 1");
    $user = mysqli_fetch_assoc($user_result);

    $site_encryption_master_key = false;
    if ($user && $password !== '' && password_verify($password, $user['user_password'])) {
        $site_encryption_master_key = decryptUserSpecificKey(
            $user['user_specific_encryption_ciphertext'],
            $password
        );
    }

    if (empty($site_encryption_master_key)) {
        $session_user_id = $user_id;
        logAudit('Login', 'Vault Unlock Failed', 'Entra-authenticated technician failed to unlock the credential vault', 0, $user_id);
        if (!empty($unlock_lock[0])) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$unlock_lock_name')");
        }
        $unlock_response = 'The vault password is incorrect.';
    } else {
        if (!empty($unlock_lock[0])) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$unlock_lock_name')");
        }

        $user_name = escapeSql($user['user_name']);
        $session_user_id = $user_id;
        logAudit('Login', 'Success', "$user_name successfully logged in via Microsoft Entra ID", 0, $user_id);

        $last_visited = $pending['last_visited'] ?? '';
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user_id;
        $_SESSION['csrf_token'] = randomString(32);
        $_SESSION['logged'] = true;
        $_SESSION['login_method'] = 'azure';
        unset($_SESSION['pending_entra_agent_login'], $_SESSION['entra_agent_oauth']);

        generateUserSessionKey($site_encryption_master_key);

        if (
            is_string($last_visited)
            && (str_starts_with($last_visited, '/agent') || str_starts_with($last_visited, '/admin'))
        ) {
            header('Location: ' . $last_visited);
        } else {
            header('Location: /agent/' . ltrim($config_start_page, '/'));
        }
        exit();
    }
}

// Microsoft redirected back with an authorization code (or an OAuth error).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['code']) || isset($_GET['error']))) {
    $oauth = $_SESSION['entra_agent_oauth'] ?? null;
    unset($_SESSION['entra_agent_oauth']);

    $returned_state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
    if (
        !empty($_GET['error'])
        || entraPendingExpired($oauth)
        || empty($returned_state)
        || empty($oauth['state'])
        || empty($oauth['code_verifier'])
        || !hash_equals($oauth['state'], $returned_state)
    ) {
        entraAgentFail('Microsoft sign-in could not be verified. Please try again.', 'Microsoft Entra returned an invalid or expired authorization response');
    }

    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    $token_response = entraRequestJson(
        "https://login.microsoftonline.com/$azure_tenant_id/oauth2/v2.0/token",
        'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $azure_client_id,
            'client_secret' => $azure_client_secret,
            'code' => $code,
            'code_verifier' => $oauth['code_verifier'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri,
            'scope' => 'https://graph.microsoft.com/User.Read',
        ])
    );

    $access_token = $token_response['data']['access_token'] ?? '';
    if ($token_response['error'] !== null || !is_string($access_token) || $access_token === '') {
        error_log('ITFlow: Microsoft Entra technician token exchange failed with HTTP ' . intval($token_response['status']));
        entraAgentFail('Microsoft sign-in could not be completed. Please try again.', 'Microsoft Entra token exchange failed');
    }

    $graph_response = entraRequestJson(
        'https://graph.microsoft.com/v1.0/me?$select=id,mail,userPrincipalName',
        'GET',
        [
            'Authorization: Bearer ' . $access_token,
            'Accept: application/json',
        ]
    );
    unset($access_token);

    $profile = is_array($graph_response['data']) ? $graph_response['data'] : [];
    $entra_oid_raw = strtolower(trim($profile['id'] ?? ''));
    if ($graph_response['error'] !== null || !entraGuidIsValid($entra_oid_raw)) {
        error_log('ITFlow: Microsoft Graph technician profile request failed with HTTP ' . intval($graph_response['status']));
        entraAgentFail('Microsoft sign-in could not read your profile. Please try again.', 'Microsoft Graph profile lookup failed');
    }

    $entra_oid = escapeSql($entra_oid_raw);
    $entra_tenant_sql = escapeSql($azure_tenant_id);

    // Durable identity lookup always wins. Email is only a one-time bootstrap
    // for a technician that an administrator explicitly enabled for Entra.
    $linked_result = mysqli_query($mysqli, "SELECT user_id, user_email
        FROM users
        WHERE user_azure_oid = '$entra_oid'
          AND user_azure_tenant_id = '$entra_tenant_sql'
          AND user_type = 1
          AND user_status = 1
          AND user_archived_at IS NULL
          AND user_auth_method = 'azure'");

    $matched_user = null;
    if (mysqli_num_rows($linked_result) === 1) {
        $matched_user = mysqli_fetch_assoc($linked_result);
    } elseif (mysqli_num_rows($linked_result) > 1) {
        entraAgentFail('Your Microsoft identity is linked more than once. Contact an administrator.', 'Duplicate Microsoft Entra technician identity binding detected');
    }

    if ($matched_user === null) {
        $candidate_emails = [];
        foreach (['mail', 'userPrincipalName'] as $email_field) {
            $candidate = strtolower(trim($profile[$email_field] ?? ''));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) && !in_array($candidate, $candidate_emails, true)) {
                $candidate_emails[] = $candidate;
            }
        }

        if (empty($candidate_emails)) {
            entraAgentFail('Your Microsoft account does not provide an email address that can be linked. Contact an administrator.', 'Microsoft Entra technician profile had no usable email address');
        }

        $email_conditions = [];
        foreach ($candidate_emails as $candidate_email) {
            $email_conditions[] = "LOWER(user_email) = '" . escapeSql($candidate_email) . "'";
        }

        $candidate_result = mysqli_query($mysqli, "SELECT user_id, user_email
            FROM users
            WHERE (" . implode(' OR ', $email_conditions) . ")
              AND user_type = 1
              AND user_status = 1
              AND user_archived_at IS NULL
              AND user_auth_method = 'azure'
              AND (user_azure_oid IS NULL OR user_azure_oid = '')");

        if (mysqli_num_rows($candidate_result) !== 1) {
            entraAgentFail('Your Microsoft account is not enabled for technician access. Contact an administrator.', 'Microsoft Entra technician profile did not match exactly one enabled user');
        }

        $matched_user = mysqli_fetch_assoc($candidate_result);
        $matched_user_id = intval($matched_user['user_id']);

        // The unique index is the final guard; this named lock also makes the
        // first-link behavior deterministic when two callbacks race.
        $identity_lock_name = 'itflow_entra_identity_' . md5($azure_tenant_id . ':' . $entra_oid_raw);
        $identity_lock = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$identity_lock_name', 10)"));
        if (empty($identity_lock[0])) {
            entraAgentFail('Your Microsoft identity could not be linked. Please try again.', 'Timed out while linking a Microsoft Entra technician identity');
        }

        $already_linked = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id FROM users
            WHERE user_azure_oid = '$entra_oid'
              AND user_azure_tenant_id = '$entra_tenant_sql'
            LIMIT 1"));

        if ($already_linked && intval($already_linked['user_id']) !== $matched_user_id) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$identity_lock_name')");
            entraAgentFail('Your Microsoft identity is already linked to another technician. Contact an administrator.', 'Microsoft Entra technician identity was already linked to another user');
        }

        mysqli_query($mysqli, "UPDATE users SET
            user_azure_oid = '$entra_oid',
            user_azure_tenant_id = '$entra_tenant_sql'
            WHERE user_id = $matched_user_id
              AND user_auth_method = 'azure'
              AND (user_azure_oid IS NULL OR user_azure_oid = '')");

        $link_succeeded = mysqli_affected_rows($mysqli) === 1 || ($already_linked && intval($already_linked['user_id']) === $matched_user_id);
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('$identity_lock_name')");

        if (!$link_succeeded) {
            entraAgentFail('Your Microsoft identity could not be linked. Contact an administrator.', 'Microsoft Entra technician identity link update failed');
        }

        $session_user_id = $matched_user_id;
        logAudit('User', 'Entra Link', 'Technician account linked to a Microsoft Entra identity', 0, $matched_user_id);
    }

    $matched_user_id = intval($matched_user['user_id']);
    $_SESSION['pending_entra_agent_login'] = [
        'user_id' => $matched_user_id,
        'oid' => $entra_oid_raw,
        'tenant_id' => $azure_tenant_id,
        'email' => $matched_user['user_email'],
        'last_visited' => $oauth['last_visited'] ?? '',
        'token' => bin2hex(random_bytes(32)),
        'created' => time(),
    ];

    header('Location: /agent/login_microsoft.php?unlock=1', true, 303);
    exit();
}

// Begin a new tenant-restricted authorization-code flow with PKCE.
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && !isset($_GET['unlock'])
    && !isset($_GET['code'])
    && !isset($_GET['error'])
) {
    if ($config_login_key_required === 1) {
        $provided_key = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';
        if ($provided_key === '' || !hash_equals($config_login_key_secret, $provided_key)) {
            header('Location: /login.php');
            exit();
        }
    }

    $last_visited = '';
    $last_visited_encoded = is_string($_GET['last_visited'] ?? null) ? $_GET['last_visited'] : '';
    $last_visited_decoded = base64_decode($last_visited_encoded, true);
    if (
        is_string($last_visited_decoded)
        && (str_starts_with($last_visited_decoded, '/agent') || str_starts_with($last_visited_decoded, '/admin'))
    ) {
        $last_visited = $last_visited_decoded;
    }

    $state = bin2hex(random_bytes(32));
    $code_verifier = entraBase64UrlEncode(random_bytes(64));
    $code_challenge = entraBase64UrlEncode(hash('sha256', $code_verifier, true));

    unset($_SESSION['pending_entra_agent_login']);
    $_SESSION['entra_agent_oauth'] = [
        'state' => $state,
        'code_verifier' => $code_verifier,
        'last_visited' => $last_visited,
        'created' => time(),
    ];

    $authorize_url = "https://login.microsoftonline.com/$azure_tenant_id/oauth2/v2.0/authorize?" . http_build_query([
        'client_id' => $azure_client_id,
        'response_type' => 'code',
        'redirect_uri' => $redirect_uri,
        'response_mode' => 'query',
        'scope' => 'https://graph.microsoft.com/User.Read',
        'state' => $state,
        'code_challenge' => $code_challenge,
        'code_challenge_method' => 'S256',
    ]);

    header('Location: ' . $authorize_url);
    exit();
}

$pending = $_SESSION['pending_entra_agent_login'] ?? null;
if (entraPendingExpired($pending)) {
    entraAgentFail('Your Microsoft sign-in expired. Please sign in again.');
}

$pending_email = escapeHtml($pending['email'] ?? 'your technician account');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= escapeHtml($company_name) ?> | Unlock Vault</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="../libs/fontawesome-free/css/all.min.css">
    <?php if (file_exists('../uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="/uploads/favicon.ico">
    <?php } ?>
    <link rel="stylesheet" href="../libs/adminlte/css/adminlte.min.css">
</head>
<body class="hold-transition login-page">
<div class="login-box">
    <div class="login-logo">
        <?php if (!empty($company_logo)) { ?>
            <img alt="<?= escapeHtml($company_name) ?> logo" height="110" width="380" class="img-fluid" src="<?= '/uploads/settings/' . escapeHtml($company_logo) ?>">
        <?php } else { ?>
            <span class="text-primary text-bold"><i class="fas fa-paper-plane mr-2"></i>IT</span>Flow
        <?php } ?>
    </div>
    <div class="card">
        <div class="card-body login-card-body">
            <p class="login-box-msg">
                <i class="fab fa-microsoft text-primary mr-1"></i>
                Microsoft verified <strong><?= $pending_email ?></strong>.
                Enter your local password to unlock the ITFlow credential vault.
            </p>

            <?php if ($unlock_response !== '') { ?>
                <div class="alert alert-danger"><?= escapeHtml($unlock_response) ?></div>
            <?php } ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="pending_token" value="<?= escapeHtml($pending['token']) ?>">
                <div class="input-group mb-3">
                    <input type="password" class="form-control" name="password" placeholder="Vault password" autocomplete="current-password" required autofocus>
                    <div class="input-group-append">
                        <div class="input-group-text"><span class="fas fa-key"></span></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" name="unlock_vault">Unlock &amp; Continue</button>
            </form>

            <div class="text-center mt-3">
                <a href="/agent/login_microsoft.php?cancel=1">Cancel</a>
            </div>
        </div>
    </div>
</div>

<?php if (!$config_whitelabel_enabled) { ?>
    <small class="text-muted">Powered by ITFlow</small>
<?php } ?>

<script src="../libs/jquery/jquery.min.js"></script>
<script src="../libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../libs/adminlte/js/adminlte.min.js"></script>
</body>
</html>
