<?php

/*
 * N45 migration n45-0030-automation-investigations
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_investigations` (
    `automation_investigation_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `automation_investigation_incident_id` bigint(20) NOT NULL,
    `automation_investigation_event_id` bigint(20) NOT NULL,
    `automation_investigation_ticket_id` int(11) NOT NULL,
    `automation_investigation_status` varchar(20) NOT NULL DEFAULT 'Pending',
    `automation_investigation_attempts` int(11) NOT NULL DEFAULT 0,
    `automation_investigation_max_attempts` int(11) NOT NULL DEFAULT 3,
    `automation_investigation_available_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_investigation_processing_at` datetime DEFAULT NULL,
    `automation_investigation_lease_token` char(64) DEFAULT NULL,
    `automation_investigation_provider` varchar(200) DEFAULT NULL,
    `automation_investigation_model` varchar(200) DEFAULT NULL,
    `automation_investigation_prompt_version` varchar(40) NOT NULL DEFAULT 'n45-readonly-v1',
    `automation_investigation_input_hash` char(64) DEFAULT NULL,
    `automation_investigation_result` longtext DEFAULT NULL,
    `automation_investigation_last_error` varchar(1000) DEFAULT NULL,
    `automation_investigation_completed_at` datetime DEFAULT NULL,
    `automation_investigation_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `automation_investigation_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`automation_investigation_id`),
    UNIQUE KEY `automation_investigation_event` (`automation_investigation_event_id`),
    KEY `automation_investigation_queue` (`automation_investigation_status`,`automation_investigation_available_at`),
    KEY `automation_investigation_incident` (`automation_investigation_incident_id`,`automation_investigation_completed_at`),
    KEY `automation_investigation_ticket` (`automation_investigation_ticket_id`,`automation_investigation_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create the automation investigation queue: '
        . mysqli_error($mysqli));
}
