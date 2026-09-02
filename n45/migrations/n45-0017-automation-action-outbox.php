<?php

/*
 * N45 migration n45-0017-automation-action-outbox
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_event_dispatch_outbox` (
    `automation_dispatch_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `automation_dispatch_event_key` char(64) NOT NULL,
    `automation_dispatch_event_id` bigint(20) NOT NULL,
    `automation_dispatch_entity_id` int(11) NOT NULL,
    `automation_dispatch_trigger` varchar(40) NOT NULL,
    `automation_dispatch_status` varchar(20) NOT NULL DEFAULT 'Pending',
    `automation_dispatch_attempts` int(11) NOT NULL DEFAULT 0,
    `automation_dispatch_available_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_dispatch_processing_at` datetime DEFAULT NULL,
    `automation_dispatch_lease_token` char(64) DEFAULT NULL,
    `automation_dispatch_delivered_at` datetime DEFAULT NULL,
    `automation_dispatch_last_error` varchar(1000) DEFAULT NULL,
    `automation_dispatch_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_dispatch_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`automation_dispatch_id`),
    UNIQUE KEY `automation_dispatch_event_key` (`automation_dispatch_event_key`),
    UNIQUE KEY `automation_dispatch_event_trigger` (`automation_dispatch_event_id`,`automation_dispatch_trigger`),
    KEY `automation_dispatch_status_available` (`automation_dispatch_status`,`automation_dispatch_available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create the durable automation custom-action outbox: '
        . mysqli_error($mysqli));
}
