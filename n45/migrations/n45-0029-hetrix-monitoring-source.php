<?php

/*
 * N45 migration n45-0029-hetrix-monitoring-source
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

// HetrixTools replaces Uptime Kuma as the outside-in availability source.
// Preserve all historical Uptime Kuma events and incidents, but disable its
// policy so an old sender cannot create new ticket effects during transition.
if (!mysqli_query($mysqli, "INSERT IGNORE INTO automation_event_policies
    (automation_policy_source) VALUES ('hetrix')")) {
    throw new RuntimeException('Could not seed the HetrixTools event policy: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE automation_event_policies SET
        automation_policy_enabled = 0,
        automation_policy_ticket_enabled = 0,
        automation_policy_auto_resolve = 0
    WHERE automation_policy_source = 'uptime_kuma'")) {
    throw new RuntimeException('Could not retire the Uptime Kuma event policy: ' . mysqli_error($mysqli));
}
