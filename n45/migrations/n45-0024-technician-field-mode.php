<?php

// N45 field visits use UTC timestamps. Existing ticket scheduling keeps its
// configured local-time semantics and is converted at the API boundary.
defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$statements = [
    "CREATE TABLE IF NOT EXISTS `field_site_pins` (
  `pin_location_id` int(11) NOT NULL,
  `pin_client_id` int(11) NOT NULL,
  `pin_latitude` decimal(10,7) NOT NULL,
  `pin_longitude` decimal(10,7) NOT NULL,
  `pin_radius_meters` int(11) NOT NULL DEFAULT 150,
  `pin_address_hash` char(64) NOT NULL,
  `pin_verified_by` int(11) NOT NULL,
  `pin_verified_at` datetime NOT NULL,
  PRIMARY KEY (`pin_location_id`),
  KEY `pin_client` (`pin_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_visits` (
  `visit_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `visit_ticket_id` int(11) NOT NULL,
  `visit_client_id` int(11) NOT NULL,
  `visit_location_id` int(11) NOT NULL DEFAULT 0,
  `visit_user_id` int(11) NOT NULL,
  `visit_active_user_id` int(11) DEFAULT NULL,
  `visit_status` varchar(20) NOT NULL,
  `visit_started_at` datetime NOT NULL,
  `visit_arrived_at` datetime DEFAULT NULL,
  `visit_finished_at` datetime DEFAULT NULL,
  `visit_eta_at` datetime DEFAULT NULL,
  `visit_customer_summary` text DEFAULT NULL,
  `visit_acknowledged_name` varchar(200) DEFAULT NULL,
  `visit_acknowledged_at` datetime DEFAULT NULL,
  `visit_signature_attachment_id` int(11) NOT NULL DEFAULT 0,
  `visit_share_location` tinyint(1) NOT NULL DEFAULT 0,
  `visit_latitude` decimal(10,7) DEFAULT NULL,
  `visit_longitude` decimal(10,7) DEFAULT NULL,
  `visit_accuracy_meters` int(11) DEFAULT NULL,
  `visit_location_at` datetime DEFAULT NULL,
  PRIMARY KEY (`visit_id`),
  UNIQUE KEY `visit_active_user` (`visit_active_user_id`),
  KEY `visit_ticket` (`visit_ticket_id`,`visit_started_at`),
  KEY `visit_user` (`visit_user_id`,`visit_started_at`),
  KEY `visit_client` (`visit_client_id`,`visit_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_time_segments` (
  `segment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `segment_visit_id` bigint(20) NOT NULL,
  `segment_active_visit_id` bigint(20) DEFAULT NULL,
  `segment_ticket_id` int(11) NOT NULL,
  `segment_user_id` int(11) NOT NULL,
  `segment_kind` varchar(20) NOT NULL,
  `segment_started_at` datetime NOT NULL,
  `segment_ended_at` datetime DEFAULT NULL,
  `segment_recorded_seconds` int(11) DEFAULT NULL,
  `segment_review_reason` varchar(500) DEFAULT NULL,
  `segment_reply_id` int(11) DEFAULT NULL,
  `segment_submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`segment_id`),
  UNIQUE KEY `segment_active_visit` (`segment_active_visit_id`),
  UNIQUE KEY `segment_reply` (`segment_reply_id`),
  KEY `segment_visit` (`segment_visit_id`,`segment_started_at`),
  KEY `segment_ticket` (`segment_ticket_id`,`segment_started_at`),
  KEY `segment_user` (`segment_user_id`,`segment_submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_visit_events` (
  `visit_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `visit_event_visit_id` bigint(20) NOT NULL,
  `visit_event_ticket_id` int(11) NOT NULL,
  `visit_event_actor_id` int(11) NOT NULL,
  `visit_event_action` varchar(40) NOT NULL,
  `visit_event_note` varchar(1000) NOT NULL,
  `visit_event_created_at` datetime NOT NULL,
  PRIMARY KEY (`visit_event_id`),
  KEY `visit_event_visit` (`visit_event_visit_id`,`visit_event_created_at`),
  KEY `visit_event_ticket` (`visit_event_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_blockers` (
  `blocker_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `blocker_ticket_id` int(11) NOT NULL,
  `blocker_client_id` int(11) NOT NULL,
  `blocker_project_id` int(11) NOT NULL DEFAULT 0,
  `blocker_task_id` int(11) NOT NULL DEFAULT 0,
  `blocker_document_id` int(11) NOT NULL DEFAULT 0,
  `blocker_attachment_id` int(11) NOT NULL DEFAULT 0,
  `blocker_kind` varchar(20) NOT NULL,
  `blocker_title` varchar(200) NOT NULL,
  `blocker_details` text NOT NULL,
  `blocker_impact` varchar(500) NOT NULL,
  `blocker_owner_id` int(11) NOT NULL,
  `blocker_status` varchar(20) NOT NULL DEFAULT 'open',
  `blocker_due_at` datetime NOT NULL,
  `blocker_resolution` text DEFAULT NULL,
  `blocker_created_by` int(11) NOT NULL,
  `blocker_created_at` datetime NOT NULL,
  `blocker_updated_at` datetime NOT NULL,
  PRIMARY KEY (`blocker_id`),
  KEY `blocker_ticket` (`blocker_ticket_id`,`blocker_status`),
  KEY `blocker_owner` (`blocker_owner_id`,`blocker_status`,`blocker_due_at`),
  KEY `blocker_task` (`blocker_task_id`,`blocker_status`),
  KEY `blocker_project` (`blocker_project_id`,`blocker_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_blocker_events` (
  `blocker_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `blocker_event_blocker_id` bigint(20) NOT NULL,
  `blocker_event_ticket_id` int(11) NOT NULL,
  `blocker_event_actor_id` int(11) NOT NULL,
  `blocker_event_action` varchar(20) NOT NULL,
  `blocker_event_note` text NOT NULL,
  `blocker_event_created_at` datetime NOT NULL,
  PRIMARY KEY (`blocker_event_id`),
  KEY `blocker_event_history` (`blocker_event_blocker_id`,`blocker_event_created_at`),
  KEY `blocker_event_ticket` (`blocker_event_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `field_requests` (
  `request_user_id` int(11) NOT NULL,
  `request_key` char(36) NOT NULL,
  `request_action` varchar(40) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `request_response` text NOT NULL,
  `request_created_at` datetime NOT NULL,
  PRIMARY KEY (`request_user_id`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];
foreach ($statements as $statement) {
    if (!mysqli_query($mysqli, $statement)) {
        throw new RuntimeException('Could not create technician field records: ' . mysqli_error($mysqli));
    }
}
