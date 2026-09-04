<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_identity_provider'])) {

    validateCSRFToken();

    $azure_client_id_raw = trim($_POST['azure_client_id'] ?? '');
    $azure_tenant_id_raw = trim($_POST['azure_tenant_id'] ?? '');
    $azure_client_secret_raw = trim($_POST['azure_client_secret'] ?? '');
    $azure_agent_sso_enable = intval($_POST['azure_agent_sso_enable'] ?? 0);
    $azure_current_user_enable = intval($_POST['azure_current_user_enable'] ?? 0);

    if ($azure_client_id_raw !== '' && !entraGuidIsValid($azure_client_id_raw)) {
        flashAlert('The Entra application (client) ID must be a valid GUID.', 'error');
        redirect();
    }

    if ($azure_tenant_id_raw !== '' && !entraGuidIsValid($azure_tenant_id_raw)) {
        flashAlert('The Entra directory (tenant) ID must be a valid GUID.', 'error');
        redirect();
    }

    if ($azure_agent_sso_enable && (
        $azure_client_id_raw === ''
        || $azure_tenant_id_raw === ''
        || ($azure_client_secret_raw === '' && empty($config_azure_client_secret))
    )) {
        flashAlert('Client ID, tenant ID, and client secret are required before technician SSO can be enabled.', 'error');
        redirect();
    }

    $azure_client_id = escapeSql(strtolower($azure_client_id_raw));
    $azure_tenant_id = escapeSql(strtolower($azure_tenant_id_raw));
    $azure_tenant_changed = strtolower(trim($config_azure_tenant_id ?? '')) !== strtolower($azure_tenant_id_raw);

    $update_secret_sql = '';
    if ($azure_client_secret_raw !== '') {
        $azure_client_secret = escapeSql($azure_client_secret_raw);
        $update_secret_sql = ", config_azure_client_secret = '$azure_client_secret'";
    }

    mysqli_query($mysqli,"UPDATE settings SET
        config_azure_client_id = '$azure_client_id',
        config_azure_tenant_id = '$azure_tenant_id',
        config_azure_agent_sso_enable = $azure_agent_sso_enable
        $update_secret_sql
        WHERE company_id = 1");

    if ($azure_tenant_changed) {
        // Object IDs are tenant-local identities. Never carry a binding into a
        // different directory, even if a human-readable email still matches.
        mysqli_query($mysqli, "UPDATE users SET user_azure_oid = NULL, user_azure_tenant_id = NULL WHERE user_type = 1");
    }

    if ($azure_current_user_enable === 1) {
        mysqli_query($mysqli, "UPDATE users SET user_auth_method = 'azure' WHERE user_id = $session_user_id AND user_type = 1");
    } else {
        mysqli_query($mysqli, "UPDATE users SET user_auth_method = 'local', user_azure_oid = NULL, user_azure_tenant_id = NULL WHERE user_id = $session_user_id AND user_type = 1");
    }

    logAudit("Settings", "Edit", "$session_name edited identity provider settings");

    flashAlert("Identity Provider Settings updated");

    redirect();

}
