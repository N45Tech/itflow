<?php
require_once "includes/inc_all_admin.php";

$entra_agent_callback_uri = "https://" . rtrim($config_base_url, '/') . "/agent/login_microsoft.php";
$entra_client_callback_uri = "https://" . rtrim($config_base_url, '/') . "/client/login_microsoft.php";
$current_user_identity = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_auth_method, user_azure_oid FROM users WHERE user_id = $session_user_id LIMIT 1"));
$current_user_entra_enabled = ($current_user_identity['user_auth_method'] ?? '') === 'azure';
$current_user_entra_linked = !empty($current_user_identity['user_azure_oid']);
 ?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-fingerprint mr-2"></i>Identity Providers</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <h4>Microsoft Entra ID</h4>

            <div class="alert alert-info">
                Register these as <strong>Web</strong> redirect URIs in the Entra app. Technician access is restricted to the configured tenant:
                <div class="mt-2"><code><?= escapeHtml($entra_agent_callback_uri) ?></code></div>
                <div><code><?= escapeHtml($entra_client_callback_uri) ?></code></div>
            </div>

            <div class="form-group">
                <label>Directory (tenant) ID</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                    </div>
                    <input type="text" class="form-control" name="azure_tenant_id" placeholder="00000000-0000-0000-0000-000000000000" maxlength="36" value="<?= escapeHtml($config_azure_tenant_id) ?>">
                </div>
                <small class="form-text text-muted">Use the immutable tenant GUID. Technician SSO never uses the common or organizations endpoint.</small>
            </div>

            <div class="form-group">
                <label>Application (client) ID</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <input type="text" class="form-control" name="azure_client_id" placeholder="e721e3b6-01d6-50e8-7f22-c84d951a52e7" maxlength="200" value="<?= escapeHtml($config_azure_client_id) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Client secret</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="password" class="form-control" name="azure_client_secret" placeholder="<?= empty($config_azure_client_secret) ? 'Client secret value' : 'Leave blank to keep the saved secret' ?>" maxlength="200" autocomplete="new-password">
                </div>
            </div>

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input class="custom-control-input" type="checkbox" id="azureAgentSsoEnable" name="azure_agent_sso_enable" value="1" <?php if ($config_azure_agent_sso_enable) { echo 'checked'; } ?>>
                    <label for="azureAgentSsoEnable" class="custom-control-label">Enable Microsoft Entra SSO for technicians</label>
                </div>
                <small class="form-text text-muted">Enable Entra on each technician account under Admin &gt; Users. Their local password remains the vault-unlock and emergency-login credential.</small>
                <small class="form-text text-muted">Apply technician MFA and device/access requirements with Entra Conditional Access. ITFlow MFA continues to protect local sign-in.</small>
            </div>

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input class="custom-control-input" type="checkbox" id="azureCurrentUserEnable" name="azure_current_user_enable" value="1" <?php if ($current_user_entra_enabled) { echo 'checked'; } ?>>
                    <label for="azureCurrentUserEnable" class="custom-control-label">Allow Entra SSO for my technician account</label>
                </div>
                <small class="form-text <?= $current_user_entra_linked ? 'text-success' : 'text-muted' ?>">
                    <?= $current_user_entra_linked ? '<i class="fas fa-link mr-1"></i>Your account is linked to an Entra identity.' : 'Your account will link on its first successful email match.' ?>
                </small>
            </div>

            <p class="text-muted mb-0">Client portal contacts configured with the Entra authentication method continue to use the same app registration.</p>

            <hr>

            <button type="submit" name="edit_identity_provider" class="btn btn-primary text-bold"><i class="fa fa-check mr-2"></i>Save</button>

        </form>
    </div>
</div>

<?php require_once "../includes/footer.php";
