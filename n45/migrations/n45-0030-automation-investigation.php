<?php

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_investigations` (
  `investigation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `generation_key` char(64) NOT NULL,
  `incident_id` bigint(20) NOT NULL,
  `client_id` int(11) NOT NULL,
  `ticket_id` int(11) NOT NULL,
  `event_id` bigint(20) NOT NULL,
  `opened_at` datetime DEFAULT NULL,
  `signal_hash` char(64) NOT NULL,
  `evidence_hash` char(64) NOT NULL,
  `evidence_json` text DEFAULT NULL,
  `result_json` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `error_code` varchar(80) DEFAULT NULL,
  `model_id` int(11) NOT NULL DEFAULT 0,
  `provider_id` int(11) NOT NULL DEFAULT 0,
  `model_name` varchar(200) DEFAULT NULL,
  `lease_token` char(64) DEFAULT NULL,
  `lease_until` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`investigation_id`),
  UNIQUE KEY `investigation_generation` (`generation_key`),
  KEY `investigation_queue` (`status`, `investigation_id`),
  KEY `investigation_ticket` (`ticket_id`, `client_id`, `investigation_id`),
  KEY `investigation_signal` (`incident_id`, `client_id`, `ticket_id`, `opened_at`, `signal_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;")) {
    throw new RuntimeException("Could not create automation_investigations");
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_investigation_audit` (
  `audit_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `investigation_id` bigint(20) NOT NULL,
  `event_code` varchar(80) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `investigation_audit_history` (`investigation_id`, `audit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;")) {
    throw new RuntimeException("Could not create automation_investigation_audit");
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_investigation_rate` (
  `rate_id` tinyint(4) NOT NULL,
  `last_started_at` datetime DEFAULT NULL,
  `call_day` date DEFAULT NULL,
  `daily_calls` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;")) {
    throw new RuntimeException("Could not create automation_investigation_rate");
}
