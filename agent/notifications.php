<?php

// Default Column Sortby Filter
$sort = "notification_timestamp";
$order = "DESC";

require_once "includes/inc_all.php";

// Dismissed Filter
if (isset($_GET['dismissed'])) {
    $dismissed_query = 'AND notification_dismissed_at IS NOT NULL';
    $dismissed_filter = 1;
} else {
    // Default - any
    $dismissed_query = 'AND notification_dismissed_at IS NULL';
    $dismissed_filter = 0;
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS client_id, client_name, notification, notification_dismissed_at, notification_id,
        notification_timestamp, notification_type FROM notifications
    LEFT JOIN clients ON notification_client_id = client_id
    WHERE (notification_type LIKE '%$q%' OR notification LIKE '%$q%')
    AND DATE(notification_timestamp) BETWEEN '$dtf' AND '$dtt'
    AND notification_user_id = $session_user_id
    $dismissed_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$notification_title = $dismissed_filter ? 'Dismissed Notifications' : 'Notifications';
$notification_has_filters = $q !== '' || !empty($_GET['dtf']) || !empty($_GET['dtt']);
$notification_clear_href = 'notifications.php' . ($dismissed_filter ? '?dismissed=1' : '');
$notification_actions = array();
if (!$dismissed_filter && $num_rows[0] > 0) {
    $notification_actions[] = array(
        'label' => 'Dismiss all',
        'icon' => 'fa-check-double',
        'variant' => 'outline-secondary',
        'class' => 'confirm-link',
        'href' => 'post.php?dismiss_all_notifications&csrf_token=' . $_SESSION['csrf_token'],
    );
}
$notification_actions[] = array(
    'label' => $dismissed_filter ? 'Active notifications' : 'Dismissed',
    'icon' => $dismissed_filter ? 'fa-bell' : 'fa-history',
    'variant' => $dismissed_filter ? 'primary' : 'secondary',
    'href' => $dismissed_filter ? 'notifications.php' : 'notifications.php?dismissed=1',
);

if ($notification_has_filters) {
    $notification_empty_action = array(
        'label' => 'Clear filters',
        'icon' => 'fa-times',
        'variant' => 'secondary',
        'href' => $notification_clear_href,
    );
} elseif ($dismissed_filter) {
    $notification_empty_action = array(
        'label' => 'View active notifications',
        'icon' => 'fa-bell',
        'variant' => 'secondary',
        'href' => 'notifications.php',
    );
} else {
    $notification_empty_action = null;
}

?>

<section class="card n45-workspace" aria-labelledby="notifications-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => $notification_title,
        'title_id' => 'notifications-page-title',
        'icon' => 'fa-bell',
        'actions' => $notification_actions,
    ));
    ?>

    <div class="card-header n45-filter-bar">
        <form autocomplete="off">
            <?php if ($dismissed_filter) { ?>
                <input type="hidden" name="dismissed" value="1">
            <?php } ?>
            <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(escapeHtml($q)); } ?>" placeholder="Search <?php if($dismissed_filter) { echo "Dismissed "; } ?>Notifications">
                        <button class="btn btn-dark" aria-label="Search notifications"><i class="fa fa-search" aria-hidden="true"></i></button>
                        <button class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter" aria-controls="advancedFilter" aria-expanded="<?php if (!empty($_GET['dtf']) || !empty($_GET['dtt'])) { echo 'true'; } else { echo 'false'; } ?>" aria-label="Show notification date filters"><i class="fas fa-filter" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
            <div class="collapse mt-3 <?php if (!empty($_GET['dtf']) || !empty($_GET['dtt'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="notification-date-from">Date from</label>
                            <input type="date" class="form-control" id="notification-date-from" name="dtf" max="2999-12-31" value="<?= escapeHtml($dtf) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="notification-date-to">Date to</label>
                            <input type="date" class="form-control" id="notification-date-to" name="dtt" max="2999-12-31" value="<?= escapeHtml($dtt) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if ($num_rows[0] == 0) { ?>
        <?php
        n45RenderEmptyState(array(
            'title' => $notification_has_filters
                ? 'No notifications match these filters'
                : ($dismissed_filter ? 'No dismissed notifications' : 'You are all caught up'),
            'description' => $notification_has_filters
                ? 'Clear the current search or date range to return to the notification list.'
                : ($dismissed_filter ? 'Notifications you dismiss will appear here.' : 'New operational notifications will appear here when they need your attention.'),
            'icon' => $dismissed_filter ? 'fa-history' : 'fa-check-circle',
            'action' => $notification_empty_action,
        ));
        ?>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-striped table-borderless table-hover mb-0 n45-data-table">
            <thead class="text-dark text-nowrap">
            <tr>
                <th class="ps-3">
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=notification_timestamp&order=<?= $disp ?>">
                        Timestamp <?php if ($sort == 'notification_timestamp') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=notification_type&order=<?= $disp ?>">
                        Type <?php if ($sort == 'notification_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=notification&order=<?= $disp ?>">
                        Notification <?php if ($sort == 'notification') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php if($dismissed_filter) { ?>
                <th>
                    <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=notification_dismissed_at&order=<?= $disp ?>">
                        Dismissed At <?php if ($sort == 'notification_dismissed_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php } ?>
                <?php if(!$dismissed_filter) { ?>
                <th class="text-center">Action</th>
                <?php } ?>
            </tr>
            </thead>
            <tbody>
            <?php

            while ($row = mysqli_fetch_assoc($sql)) {
            $notification_id = intval($row['notification_id']);
            $notification_timestamp = escapeHtml($row['notification_timestamp']);
            $notification_type = escapeHtml($row['notification_type']);
            $notification = escapeHtml($row['notification']);
            $notification_dismissed_at = escapeHtml($row['notification_dismissed_at']);
            $client_name = escapeHtml($row['client_name']);
            $client_id = intval($row['client_id']);

            ?>
            <tr>
                <td class="font-monospace ps-3"><?= $notification_timestamp ?></td>
                <td><?= $notification_type ?></td>
                <td><?= $notification ?></td>
                <?php if($dismissed_filter) { ?>
                <td class="font-monospace"><?= $notification_dismissed_at ?></td>
                <?php } ?>
                <?php if(!$dismissed_filter) { ?>
                <td class="text-center"><a class="btn btn-secondary btn-sm" href="post.php?dismiss_notification=<?= $notification_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" aria-label="Dismiss notification"><i class="fas fa-check" aria-hidden="true"></i></a></td>
                <?php } ?>
            </tr>

            <?php } ?>

            </tbody>
        </table>
    </div>
    <?php } ?>
    <?php require_once "../includes/filter_footer.php"; ?>
</section>

<?php

require_once "../includes/footer.php";
