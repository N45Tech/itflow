<?php

$event_post = file_get_contents(dirname(__DIR__) . '/agent/post/event.php');
$calendar_page = file_get_contents(dirname(__DIR__) . '/agent/calendar.php');

$delete_start = strpos($event_post, "if (isset(\$_POST['delete_calendar']))");
$delete_end = strpos($event_post, "if (isset(\$_POST['share_calendar']))", $delete_start);

if ($delete_start === false || $delete_end === false) {
    fwrite(STDERR, "Unable to locate the POST calendar deletion handler.\n");
    exit(1);
}

$delete_handler = substr($event_post, $delete_start, $delete_end - $delete_start);
$csrf_position = strpos($delete_handler, 'validateCSRFToken();');
$admin_position = strpos($delete_handler, 'enforceAdminPermission();');
$delete_position = strpos($delete_handler, 'DELETE FROM calendars');

if (
    $csrf_position === false
    || $admin_position === false
    || $delete_position === false
    || $admin_position > $delete_position
    || str_contains($event_post, "\$_GET['delete_calendar']")
) {
    fwrite(STDERR, "Calendar deletion must use POST and enforce administrator access before deletion.\n");
    exit(1);
}

if (
    !str_contains($calendar_page, 'method="post"')
    || !str_contains($calendar_page, 'name="delete_calendar"')
    || str_contains($calendar_page, 'post.php?delete_calendar=')
) {
    fwrite(STDERR, "The calendar deletion control must submit a POST request.\n");
    exit(1);
}

echo "Calendar deletion authorization test passed.\n";
