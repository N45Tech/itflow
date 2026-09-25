<?php

// Default Column Sortby Filter
$sort = "user_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$user_where = "(user_name LIKE '%$q%' OR user_email LIKE '%$q%')
    AND user_type = 1
    AND user_$archive_query";
$sql = mysqli_query(
    $mysqli,
    "SELECT role_name, user_archived_at, user_auth_method, user_avatar, user_azure_oid, user_config_force_mfa, user_email,
        users.user_id, user_name, user_role_id, user_status, user_token FROM users
    LEFT JOIN user_roles ON user_role_id = role_id
    LEFT JOIN user_settings ON users.user_id = user_settings.user_id
    WHERE $user_where
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users WHERE $user_where"));
$users = mysqli_fetch_all($sql, MYSQLI_ASSOC);
$last_logins = array();
$remember_token_counts = array();
if ($users) {
    $user_ids = implode(',', array_map(static function (array $user): int {
        return (int) $user['user_id'];
    }, $users));

    $last_login_sql = mysqli_query($mysqli, "SELECT logs.log_user_id, logs.log_created_at, logs.log_ip, logs.log_user_agent
        FROM logs
        INNER JOIN (
            SELECT log_user_id, MAX(log_id) AS last_log_id
            FROM logs
            WHERE log_user_id IN ($user_ids) AND log_type = 'Login'
            GROUP BY log_user_id
        ) AS latest ON logs.log_id = latest.last_log_id");
    while ($login = mysqli_fetch_assoc($last_login_sql)) {
        $last_logins[(int) $login['log_user_id']] = $login;
    }

    $remember_tokens_sql = mysqli_query($mysqli, "SELECT remember_token_user_id, COUNT(*) AS token_count
        FROM remember_tokens
        WHERE remember_token_user_id IN ($user_ids)
        GROUP BY remember_token_user_id");
    while ($tokens = mysqli_fetch_assoc($remember_tokens_sql)) {
        $remember_token_counts[(int) $tokens['remember_token_user_id']] = (int) $tokens['token_count'];
    }
}

$user_actions = array(
    array(
        'label' => 'Export',
        'icon' => 'fa-download',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => buildExportModalUrl('modals/user/user_export.php', array('archived', 'q'))),
    ),
    array('type' => 'separator'),
    array(
        'label' => 'Incident response: reset passwords',
        'icon' => 'fa-user-shield',
        'class' => 'ajax-modal',
        'attributes' => array('data-modal-url' => 'modals/user/user_all_reset_password.php', 'data-modal-size' => 'lg'),
    ),
);
$user_empty_action = $q !== '' || $archived || $page > 1
    ? array('label' => 'Clear filters', 'icon' => 'fa-times', 'href' => 'users.php', 'variant' => 'secondary')
    : array('type' => 'button', 'label' => 'New User', 'icon' => 'fa-user-plus', 'variant' => 'primary',
        'class' => 'ajax-modal', 'attributes' => array('data-modal-url' => 'modals/user/user_add.php'));
$user_new_action = array('type' => 'button', 'label' => 'New User', 'icon' => 'fa-user-plus',
    'variant' => 'primary', 'class' => 'ajax-modal',
    'attributes' => array('data-modal-url' => 'modals/user/user_add.php'));
$user_page_actions = $num_rows[0] > 1
    ? array(array('type' => 'split-menu', 'button' => $user_new_action,
        'items' => $user_actions, 'menu_label' => 'More user actions', 'align_end' => true))
    : array($user_new_action);

?>

<section class="card n45-workspace" aria-labelledby="users-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Users',
        'title_id' => 'users-page-title',
        'icon' => 'fa-users',
        'actions' => $user_page_actions,
    ));
    ?>
    <div class="card-header n45-filter-bar">
        <form autocomplete="off">
            <input type="hidden" name="archived" value="<?= (int) $archived ?>">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {echo stripslashes(escapeHtml($q));} ?>" placeholder="Search users" aria-label="Search users">
                        <button class="btn btn-primary" aria-label="Search users"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-group float-end">
                        <a href="?archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo"primary"; } else { echo "default"; } ?>" <?= $archived ? 'aria-current="page"' : '' ?>>
                            <i class="fa fa-fw fa-archive me-2" aria-hidden="true"></i>Archived
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php if (!$users) { ?>
        <?php n45RenderEmptyState(array(
            'title' => $q !== '' ? 'No users match this search'
                : ($page > 1 ? 'No users on this page' : ($archived ? 'No archived users' : 'No users yet')),
            'description' => $q !== '' ? 'Clear the search to return to the user list.'
                : ($page > 1 ? 'Return to the first page of users.'
                    : ($archived ? 'Archived users will appear here.' : 'Add a user to grant access to the workspace.')),
            'icon' => 'fa-users',
            'action' => $user_empty_action,
        )); ?>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
            <thead class="text-dark text-nowrap">
            <tr>
                <th class="text-center">
                    <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=user_name&order=<?= $disp ?>">
                        Name <?php if ($sort == 'user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=user_email&order=<?= $disp ?>">
                        Email <?php if ($sort == 'user_email') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=role_name&order=<?= $disp ?>">
                        Role <?php if ($sort == 'role_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=user_status&order=<?= $disp ?>">
                        Status <?php if ($sort == 'user_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Sign-in</th>
                <th class="text-center">MFA</th>
                <th>
                    Last Login
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            foreach ($users as $row) {
                $user_id = intval($row['user_id']);
                $user_name = escapeHtml($row['user_name']);
                $user_email = escapeHtml($row['user_email']);
                $user_status = intval($row['user_status']);
                if ($user_status == 2) {
                    $user_status_display = "<span class='text-info'>Invited</span>";
                } elseif ($user_status == 1) {
                    $user_status_display = "<span class='text-success'>Active</span>";
                } else{
                    $user_status_display = "<span class='text-danger'>Disabled</span>";
                }
                $user_avatar = escapeHtml($row['user_avatar']);
                $user_token = escapeHtml($row['user_token']);
                $user_auth_method = $row['user_auth_method'];
                $user_azure_oid = $row['user_azure_oid'];
                if ($user_auth_method === 'azure') {
                    $sign_in_display = empty($user_azure_oid)
                        ? "<span class='text-warning'><i class='fab fa-microsoft me-1'></i>Entra ready</span>"
                        : "<span class='text-success'><i class='fab fa-microsoft me-1'></i>Entra linked</span>";
                } else {
                    $sign_in_display = "<span class='text-muted'><i class='fas fa-key me-1'></i>Local</span>";
                }
                if(empty($user_token)) {
                    $mfa_status_display = "<i class='fas fa-fw fa-unlock text-danger' aria-hidden='true'></i>";
                } else {
                    $mfa_status_display = "<i class='fas fa-fw fa-lock text-success' aria-hidden='true'></i>";
                }
                $user_role_display = escapeHtml($row['role_name']);
                $user_archived_at = escapeHtml($row['user_archived_at']);
                $user_initials = escapeHtml(initials($user_name));


                if (!isset($last_logins[$user_id])) {
                    $last_login = "<span class='text-bold'>Never logged in</span>";
                } else {
                    $login = $last_logins[$user_id];
                    $log_created_at = escapeHtml($login['log_created_at']);
                    $log_ip = escapeHtml($login['log_ip']);
                    $log_user_agent = (string) $login['log_user_agent'];
                    $log_user_os = escapeHtml(getOS($log_user_agent));
                    $log_user_browser = escapeHtml(getWebBrowser($log_user_agent));
                    $last_login = "$log_created_at<small class='text-secondary d-block mt-1'><span class='d-block'>$log_user_os</span><span class='d-block'>$log_user_browser</span><span class='d-block'><i class='fa fa-fw fa-globe' aria-hidden='true'></i> $log_ip</span></small>";
                }

                $remember_token_count = $remember_token_counts[$user_id] ?? 0;



                ?>
                <tr>
                    <td class="text-center">
                        <?php if ($user_id !== $session_user_id) { ?>
                        <a href="#" class="ajax-modal" title="User ID: <?= $user_id ?>" data-modal-url="modals/user/user_edit.php?id=<?= $user_id ?>">
                        <?php } else { ?><div title="Your account"><?php } ?>
                            <?php if (!empty($user_avatar)) { ?>
                                <img class="img-size-50 rounded-circle" src="<?= "../uploads/users/$user_id/$user_avatar" ?>" alt="">
                            <?php } else { ?>
                                <span class="fa-stack fa-2x">
                                    <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                    <span class="fa fa-stack-1x text-white"><?= $user_initials ?></span>
                                </span>
                                <br>
                            <?php } ?>

                            <div class="text-secondary"><?= $user_name ?></div>
                        <?php if ($user_id !== $session_user_id) { ?></a><?php } else { ?></div><?php } ?>
                    </td>
                    <td><a href="mailto:<?= $user_email ?>"><?= $user_email ?></a></td>
                    <td><?= $user_role_display ?></td>
                    <td><?= $user_status_display ?></td>
                    <td class="text-center"><?= $sign_in_display ?></td>
                    <td class="text-center"><span class="visually-hidden"><?= empty($user_token) ? 'MFA not enrolled' : 'MFA enrolled' ?></span><?= $mfa_status_display ?></td>
                    <td><?= $last_login ?></td>
                    <td>
                        <?php if ($user_id !== $session_user_id) {   // Prevent modifying self ?>
                        <div class="dropdown dropstart text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions for <?= $user_name ?>" aria-expanded="false">
                                <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/user/user_edit.php?id=<?= $user_id ?>">
                                    <i class="fas fa-fw fa-user-edit me-2"></i>Edit
                                </a>
                                <?php if ($remember_token_count > 0) { ?>
                                <a class="dropdown-item" href="post.php?revoke_remember_me=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-ban me-2"></i>Revoke <?= $remember_token_count ?> Remember Tokens
                                </a>
                                <?php } ?>
                                <?php if ($user_status == 0) { ?>
                                    <a class="dropdown-item text-success" href="post.php?activate_user=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-user-check me-2"></i>Activate
                                    </a>
                                <?php }elseif ($user_status == 1) { ?>
                                    <a class="dropdown-item text-danger" href="post.php?disable_user=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-user-slash me-2"></i>Disable
                                    </a>
                                <?php } ?>
                                <?php if ($user_archived_at) { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-info ajax-modal" href="#" data-modal-url="modals/user/user_restore.php?id=<?= $user_id ?>">
                                    <i class="fas fa-fw fa-redo-alt me-2"></i>Restore
                                </a>
                                <?php } else { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger ajax-modal" href="#" data-modal-url="modals/user/user_archive.php?id=<?= $user_id ?>">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </a>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </td>
                </tr>

                <?php
            }

            ?>

            </tbody>
        </table>
    </div>
    <?php } ?>
    <?php require_once "../includes/filter_footer.php";
 ?>
</section>

<?php
require_once "../includes/footer.php";
