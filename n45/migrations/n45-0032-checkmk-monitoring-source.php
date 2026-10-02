<?php

/* N45 migration n45-0032-checkmk-monitoring-source */
defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

// Existing operator policy choices are authoritative, including disabled sources.
if (!mysqli_query($mysqli, "INSERT IGNORE INTO automation_event_policies
    (automation_policy_source) VALUES ('checkmk')")) {
    throw new RuntimeException('Could not seed the Checkmk event policy: ' . mysqli_error($mysqli));
}
