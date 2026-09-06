<?php

// New receipt and action tables only; no existing customer records are changed.
defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");
$statements = [
    "CREATE TABLE IF NOT EXISTS `email_ingestion_receipts` (
  `receipt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `receipt_mailbox_hash` char(64) NOT NULL,
  `receipt_message_hash` char(64) NOT NULL,
  `receipt_content_hash` char(64) NOT NULL,
  `receipt_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `receipt_result` varchar(20) DEFAULT NULL,
  `receipt_ticket_id` int(11) NOT NULL DEFAULT 0,
  `receipt_reply_id` int(11) NOT NULL DEFAULT 0,
  `receipt_client_id` int(11) NOT NULL DEFAULT 0,
  `receipt_attempts` int(11) NOT NULL DEFAULT 0,
  `receipt_last_error` varchar(200) DEFAULT NULL,
  `receipt_created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `receipt_completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`receipt_id`),
  UNIQUE KEY `receipt_mailbox_message` (`receipt_mailbox_hash`,`receipt_message_hash`),
  KEY `receipt_status` (`receipt_status`,`receipt_created_at`),
  KEY `receipt_ticket` (`receipt_ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS `email_ingestion_actions` (
  `action_receipt_id` bigint(20) NOT NULL,
  `action_event_key` char(64) NOT NULL,
  `action_trigger` varchar(40) NOT NULL,
  `action_ticket_id` int(11) NOT NULL,
  `action_client_id` int(11) NOT NULL,
  `action_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `action_attempts` int(11) NOT NULL DEFAULT 0,
  `action_available_at` datetime NOT NULL DEFAULT current_timestamp(),
  `action_processing_at` datetime DEFAULT NULL,
  `action_lease_token` char(64) DEFAULT NULL,
  `action_last_error` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`action_receipt_id`),
  UNIQUE KEY `action_event` (`action_event_key`),
  KEY `action_retry` (`action_status`,`action_available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];
foreach ($statements as $statement) {
    if (!mysqli_query($mysqli, $statement)) {
        throw new RuntimeException('Could not create inbound mail receipts and action outbox');
    }
}
