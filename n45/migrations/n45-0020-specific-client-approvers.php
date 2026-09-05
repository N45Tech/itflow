<?php

/*
 * N45 migration n45-0020-specific-client-approvers
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `task_approvals`
    ADD COLUMN IF NOT EXISTS `approval_required_contact_id` int(11) DEFAULT NULL
        AFTER `approval_required_user_id`")) {
    throw new RuntimeException('Could not add the selected contact to task approvals: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `ticket_approvals`
    ADD COLUMN IF NOT EXISTS `ticket_approval_required_contact_id` int(11) DEFAULT NULL
        AFTER `ticket_approval_required_user_id`")) {
    throw new RuntimeException('Could not add the selected contact to ticket approvals: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `task_approval_events`
    ADD COLUMN IF NOT EXISTS `task_approval_event_from_required_contact_id` int(11) NOT NULL DEFAULT 0
        AFTER `task_approval_event_to_required_user_id`,
    ADD COLUMN IF NOT EXISTS `task_approval_event_to_required_contact_id` int(11) NOT NULL DEFAULT 0
        AFTER `task_approval_event_from_required_contact_id`")) {
    throw new RuntimeException('Could not add selected contacts to task approval history: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `ticket_approval_events`
    ADD COLUMN IF NOT EXISTS `ticket_approval_event_from_required_contact_id` int(11) NOT NULL DEFAULT 0
        AFTER `ticket_approval_event_to_required_user_id`,
    ADD COLUMN IF NOT EXISTS `ticket_approval_event_to_required_contact_id` int(11) NOT NULL DEFAULT 0
        AFTER `ticket_approval_event_from_required_contact_id`")) {
    throw new RuntimeException('Could not add selected contacts to ticket approval history: '
        . mysqli_error($mysqli));
}
