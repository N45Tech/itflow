<?php

// Follow-up ownership, idempotent reminders and reviewed resolution knowledge.
defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");
$statements = [
    "CREATE TABLE IF NOT EXISTS `service_followup_plans` (
  `plan_key` varchar(64) NOT NULL,
  `plan_ticket_id` int(11) NOT NULL,
  `plan_client_id` int(11) NOT NULL,
  `plan_source_hash` char(64) NOT NULL,
  `plan_owner_id` int(11) NOT NULL,
  `plan_due_at` datetime NOT NULL,
  `plan_escalate_to` int(11) NOT NULL DEFAULT 0,
  `plan_escalate_at` datetime NOT NULL,
  `plan_note` varchar(1000) NOT NULL,
  `plan_version` int(11) NOT NULL DEFAULT 1,
  `plan_updated_by` int(11) NOT NULL,
  `plan_updated_at` datetime NOT NULL,
  PRIMARY KEY (`plan_key`),
  KEY `followup_ticket` (`plan_ticket_id`),
  KEY `followup_client_due` (`plan_client_id`,`plan_due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `service_followup_notices` (
  `notice_key` char(64) NOT NULL,
  `notice_ticket_id` int(11) NOT NULL,
  `notice_client_id` int(11) NOT NULL,
  `notice_recipient_id` int(11) NOT NULL,
  `notice_stage` varchar(20) NOT NULL,
  `notice_created_at` datetime NOT NULL,
  PRIMARY KEY (`notice_key`),
  KEY `notice_ticket` (`notice_ticket_id`),
  KEY `notice_client` (`notice_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `service_assistance_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_kind` varchar(20) NOT NULL,
  `event_entity_key` varchar(64) NOT NULL,
  `event_ticket_id` int(11) NOT NULL,
  `event_client_id` int(11) NOT NULL,
  `event_actor_id` int(11) NOT NULL,
  `event_action` varchar(20) NOT NULL,
  `event_note` varchar(1000) NOT NULL,
  `event_payload` longtext NOT NULL,
  `event_created_at` datetime NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `assistance_ticket` (`event_ticket_id`,`event_kind`,`event_entity_key`),
  KEY `assistance_client` (`event_client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `service_knowledge` (
  `knowledge_id` int(11) NOT NULL AUTO_INCREMENT,
  `knowledge_ticket_id` int(11) NOT NULL,
  `knowledge_client_id` int(11) NOT NULL,
  `knowledge_title` varchar(200) NOT NULL,
  `knowledge_problem` text NOT NULL,
  `knowledge_solution` text NOT NULL,
  `knowledge_cautions` text NOT NULL,
  `knowledge_source_hash` char(64) NOT NULL,
  `knowledge_state` varchar(20) NOT NULL DEFAULT 'draft',
  `knowledge_revision` int(11) NOT NULL DEFAULT 1,
  `knowledge_created_by` int(11) NOT NULL,
  `knowledge_edited_by` int(11) NOT NULL,
  `knowledge_reviewed_by` int(11) NOT NULL DEFAULT 0,
  `knowledge_reviewed_at` datetime DEFAULT NULL,
  `knowledge_document_id` int(11) DEFAULT NULL,
  `knowledge_published_hash` char(64) DEFAULT NULL,
  `knowledge_document_base_hash` char(64) DEFAULT NULL,
  `knowledge_created_at` datetime NOT NULL,
  `knowledge_updated_at` datetime NOT NULL,
  PRIMARY KEY (`knowledge_id`),
  UNIQUE KEY `knowledge_source_ticket` (`knowledge_ticket_id`),
  UNIQUE KEY `knowledge_document` (`knowledge_document_id`),
  KEY `knowledge_client_state` (`knowledge_client_id`,`knowledge_state`,`knowledge_updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];
foreach ($statements as $statement) {
    if (!mysqli_query($mysqli, $statement)) {
        throw new RuntimeException('Could not create service assistance records');
    }
}
