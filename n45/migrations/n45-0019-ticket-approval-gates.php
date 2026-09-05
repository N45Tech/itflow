<?php

/*
 * N45 migration n45-0019-ticket-approval-gates
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `task_approvals`
    MODIFY COLUMN `approval_type` varchar(20) NOT NULL")) {
    throw new RuntimeException('Could not extend manual task approval routes: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_approvals` (
    `ticket_approval_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_approval_scope` varchar(20) NOT NULL,
    `ticket_approval_type` varchar(20) NOT NULL,
    `ticket_approval_required_user_id` int(11) DEFAULT NULL,
    `ticket_approval_status` varchar(20) NOT NULL,
    `ticket_approval_created_by` int(11) NOT NULL,
    `ticket_approval_decided_by` varchar(255) DEFAULT NULL,
    `ticket_approval_url_key` varchar(200) NOT NULL,
    `ticket_approval_url_expires_at` datetime DEFAULT NULL,
    `ticket_approval_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `ticket_approval_decided_at` datetime DEFAULT NULL,
    `ticket_approval_ticket_id` int(11) NOT NULL,
    PRIMARY KEY (`ticket_approval_id`),
    KEY `ticket_approval_ticket_status` (`ticket_approval_ticket_id`,`ticket_approval_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create whole-ticket approval gates: '
        . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_approval_events` (
    `ticket_approval_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `ticket_approval_event_approval_id` int(11) NOT NULL,
    `ticket_approval_event_ticket_id` int(11) NOT NULL,
    `ticket_approval_event_action` varchar(30) NOT NULL,
    `ticket_approval_event_from_status` varchar(20) DEFAULT NULL,
    `ticket_approval_event_to_status` varchar(20) DEFAULT NULL,
    `ticket_approval_event_from_scope` varchar(20) DEFAULT NULL,
    `ticket_approval_event_to_scope` varchar(20) DEFAULT NULL,
    `ticket_approval_event_from_type` varchar(20) DEFAULT NULL,
    `ticket_approval_event_to_type` varchar(20) DEFAULT NULL,
    `ticket_approval_event_from_required_user_id` int(11) NOT NULL DEFAULT 0,
    `ticket_approval_event_to_required_user_id` int(11) NOT NULL DEFAULT 0,
    `ticket_approval_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `ticket_approval_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `ticket_approval_event_actor_label` varchar(255) DEFAULT NULL,
    `ticket_approval_event_reason` varchar(255) DEFAULT NULL,
    `ticket_approval_event_request_expires_at` datetime DEFAULT NULL,
    `ticket_approval_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_approval_event_id`),
    KEY `ticket_approval_event_approval` (`ticket_approval_event_approval_id`,`ticket_approval_event_id`),
    KEY `ticket_approval_event_ticket` (`ticket_approval_event_ticket_id`,`ticket_approval_event_created_at`,`ticket_approval_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create whole-ticket approval audit events: '
        . mysqli_error($mysqli));
}
