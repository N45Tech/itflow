<?php

/*
 * N45 migration n45-0010-versioned-runbooks (legacy marker 2.7.7)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$runbook_index_exists = static function (string $table_name, string $index_name) use ($mysqli): bool {
    $table_name_sql = mysqli_real_escape_string($mysqli, $table_name);
    $index_name_sql = mysqli_real_escape_string($mysqli, $index_name);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = '$table_name_sql'
        AND index_name = '$index_name_sql'"));
    return intval($row[0] ?? 0) > 0;
};

if (!mysqli_query($mysqli, "ALTER TABLE `ticket_templates`
    ADD COLUMN IF NOT EXISTS `ticket_template_runbook_key` varchar(100) DEFAULT NULL AFTER `ticket_template_details`,
    ADD COLUMN IF NOT EXISTS `ticket_template_runbook_type` varchar(20) NOT NULL DEFAULT 'standard' AFTER `ticket_template_runbook_key`,
    ADD COLUMN IF NOT EXISTS `ticket_template_published_version_id` bigint(20) NOT NULL DEFAULT 0 AFTER `ticket_template_runbook_type`")) {
    throw new RuntimeException('Could not extend ticket templates for runbooks: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `project_template_ticket_templates`
    ADD COLUMN IF NOT EXISTS `ticket_template_runbook_version_id` bigint(20) NOT NULL DEFAULT 0 AFTER `ticket_template_order`")) {
    throw new RuntimeException('Could not pin project template stages to runbook versions: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `task_templates`
    ADD COLUMN IF NOT EXISTS `task_template_key` varchar(100) DEFAULT NULL AFTER `task_template_name`,
    ADD COLUMN IF NOT EXISTS `task_template_instructions` text DEFAULT NULL AFTER `task_template_key`,
    ADD COLUMN IF NOT EXISTS `task_template_condition_type` varchar(40) NOT NULL DEFAULT 'always' AFTER `task_template_completion_estimate`,
    ADD COLUMN IF NOT EXISTS `task_template_condition_value` varchar(255) DEFAULT NULL AFTER `task_template_condition_type`,
    ADD COLUMN IF NOT EXISTS `task_template_owner_type` varchar(40) NOT NULL DEFAULT 'unassigned' AFTER `task_template_condition_value`,
    ADD COLUMN IF NOT EXISTS `task_template_owner_user_id` int(11) NOT NULL DEFAULT 0 AFTER `task_template_owner_type`,
    ADD COLUMN IF NOT EXISTS `task_template_due_offset_minutes` int(11) NOT NULL DEFAULT 0 AFTER `task_template_owner_user_id`,
    ADD COLUMN IF NOT EXISTS `task_template_initial_state` varchar(20) NOT NULL DEFAULT 'Ready' AFTER `task_template_due_offset_minutes`,
    ADD COLUMN IF NOT EXISTS `task_template_approval_scope` varchar(20) DEFAULT NULL AFTER `task_template_initial_state`,
    ADD COLUMN IF NOT EXISTS `task_template_approval_type` varchar(20) DEFAULT NULL AFTER `task_template_approval_scope`,
    ADD COLUMN IF NOT EXISTS `task_template_approval_user_id` int(11) NOT NULL DEFAULT 0 AFTER `task_template_approval_type`,
    ADD COLUMN IF NOT EXISTS `task_template_evidence_type` varchar(20) NOT NULL DEFAULT 'none' AFTER `task_template_approval_user_id`,
    ADD COLUMN IF NOT EXISTS `task_template_evidence_prompt` varchar(255) DEFAULT NULL AFTER `task_template_evidence_type`")) {
    throw new RuntimeException('Could not extend task templates for runbooks: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `tasks`
    ADD COLUMN IF NOT EXISTS `task_instructions` text DEFAULT NULL AFTER `task_name`,
    ADD COLUMN IF NOT EXISTS `task_state` varchar(20) NOT NULL DEFAULT 'Ready' AFTER `task_status`,
    ADD COLUMN IF NOT EXISTS `task_assigned_to` int(11) NOT NULL DEFAULT 0 AFTER `task_completion_estimate`,
    ADD COLUMN IF NOT EXISTS `task_due_at` datetime DEFAULT NULL AFTER `task_assigned_to`,
    ADD COLUMN IF NOT EXISTS `task_waiting_reason` varchar(255) DEFAULT NULL AFTER `task_due_at`,
    ADD COLUMN IF NOT EXISTS `task_condition_result` varchar(20) NOT NULL DEFAULT 'Matched' AFTER `task_waiting_reason`,
    ADD COLUMN IF NOT EXISTS `task_evidence_required` varchar(20) NOT NULL DEFAULT 'none' AFTER `task_condition_result`,
    ADD COLUMN IF NOT EXISTS `task_evidence_prompt` varchar(255) DEFAULT NULL AFTER `task_evidence_required`,
    ADD COLUMN IF NOT EXISTS `task_runbook_version_task_id` bigint(20) NOT NULL DEFAULT 0 AFTER `task_evidence_prompt`")) {
    throw new RuntimeException('Could not extend tasks for runbook execution: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `task_approvals`
    ADD COLUMN IF NOT EXISTS `approval_created_at` datetime NOT NULL DEFAULT current_timestamp() AFTER `approval_url_key`,
    ADD COLUMN IF NOT EXISTS `approval_url_expires_at` datetime DEFAULT NULL AFTER `approval_url_key`,
    ADD COLUMN IF NOT EXISTS `approval_decided_at` datetime DEFAULT NULL AFTER `approval_created_at`")) {
    throw new RuntimeException('Could not add an approval decision audit trail: ' . mysqli_error($mysqli));
}

// Migrate legacy bearer credentials in-place. New code never stores a raw
// token, but pending approvals created before this release must remain usable
// for a bounded period. Decided approvals retain no live credential.
if (!mysqli_query($mysqli, "UPDATE `task_approvals`
    SET approval_url_key = CONCAT('sha256:', SHA2(approval_url_key, 256)),
        approval_url_expires_at = COALESCE(approval_url_expires_at, DATE_ADD(NOW(), INTERVAL 7 DAY))
    WHERE approval_status = 'pending' AND approval_url_key <> ''
    AND LEFT(approval_url_key, 7) <> 'sha256:'")) {
    throw new RuntimeException('Could not hash legacy pending approval links: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `task_approvals`
    SET approval_url_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
    WHERE approval_status = 'pending' AND approval_url_key <> ''
    AND approval_url_expires_at IS NULL")) {
    throw new RuntimeException('Could not expire legacy pending approval links: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `task_approvals`
    SET approval_url_key = '', approval_url_expires_at = NULL
    WHERE approval_status <> 'pending'
    AND (approval_url_key <> '' OR approval_url_expires_at IS NOT NULL)")) {
    throw new RuntimeException('Could not retire decided approval links: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `task_approval_events` (
    `task_approval_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `task_approval_event_approval_id` int(11) NOT NULL,
    `task_approval_event_task_id` int(11) NOT NULL,
    `task_approval_event_action` varchar(30) NOT NULL,
    `task_approval_event_from_status` varchar(20) DEFAULT NULL,
    `task_approval_event_to_status` varchar(20) DEFAULT NULL,
    `task_approval_event_from_scope` varchar(20) DEFAULT NULL,
    `task_approval_event_to_scope` varchar(20) DEFAULT NULL,
    `task_approval_event_from_type` varchar(20) DEFAULT NULL,
    `task_approval_event_to_type` varchar(20) DEFAULT NULL,
    `task_approval_event_from_required_user_id` int(11) NOT NULL DEFAULT 0,
    `task_approval_event_to_required_user_id` int(11) NOT NULL DEFAULT 0,
    `task_approval_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `task_approval_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `task_approval_event_actor_label` varchar(255) DEFAULT NULL,
    `task_approval_event_reason` varchar(255) DEFAULT NULL,
    `task_approval_event_request_expires_at` datetime DEFAULT NULL,
    `task_approval_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`task_approval_event_id`),
    KEY `task_approval_event_approval` (`task_approval_event_approval_id`,`task_approval_event_id`),
    KEY `task_approval_event_task` (`task_approval_event_task_id`,`task_approval_event_created_at`,`task_approval_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create task approval event history: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `task_template_dependencies` (
    `task_template_id` int(11) NOT NULL,
    `depends_on_task_template_id` int(11) NOT NULL,
    PRIMARY KEY (`task_template_id`,`depends_on_task_template_id`),
    KEY `depends_on_task_template_id` (`depends_on_task_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create task template dependencies: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `runbook_versions` (
    `runbook_version_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `runbook_version_ticket_template_id` int(11) NOT NULL,
    `runbook_version_number` int(11) NOT NULL,
    `runbook_version_definition_hash` char(64) NOT NULL,
    `runbook_version_key` varchar(100) NOT NULL,
    `runbook_version_name` varchar(200) NOT NULL,
    `runbook_version_description` text DEFAULT NULL,
    `runbook_version_subject` varchar(500) DEFAULT NULL,
    `runbook_version_details` longtext DEFAULT NULL,
    `runbook_version_type` varchar(20) NOT NULL DEFAULT 'standard',
    `runbook_version_notes` varchar(255) DEFAULT NULL,
    `runbook_version_created_by` int(11) NOT NULL DEFAULT 0,
    `runbook_version_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`runbook_version_id`),
    UNIQUE KEY `runbook_version_number` (`runbook_version_ticket_template_id`,`runbook_version_number`),
    UNIQUE KEY `runbook_version_hash` (`runbook_version_ticket_template_id`,`runbook_version_definition_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create runbook versions: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `runbook_version_tasks` (
    `runbook_version_task_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `runbook_version_task_runbook_version_id` bigint(20) NOT NULL,
    `runbook_version_task_source_id` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_key` varchar(100) NOT NULL,
    `runbook_version_task_name` varchar(255) NOT NULL,
    `runbook_version_task_instructions` text DEFAULT NULL,
    `runbook_version_task_order` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_completion_estimate` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_condition_type` varchar(40) NOT NULL DEFAULT 'always',
    `runbook_version_task_condition_value` varchar(255) DEFAULT NULL,
    `runbook_version_task_owner_type` varchar(40) NOT NULL DEFAULT 'unassigned',
    `runbook_version_task_owner_user_id` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_due_offset_minutes` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_initial_state` varchar(20) NOT NULL DEFAULT 'Ready',
    `runbook_version_task_approval_scope` varchar(20) DEFAULT NULL,
    `runbook_version_task_approval_type` varchar(20) DEFAULT NULL,
    `runbook_version_task_approval_user_id` int(11) NOT NULL DEFAULT 0,
    `runbook_version_task_evidence_type` varchar(20) NOT NULL DEFAULT 'none',
    `runbook_version_task_evidence_prompt` varchar(255) DEFAULT NULL,
    PRIMARY KEY (`runbook_version_task_id`),
    UNIQUE KEY `runbook_version_task_key` (`runbook_version_task_runbook_version_id`,`runbook_version_task_key`),
    KEY `runbook_version_task_order` (`runbook_version_task_runbook_version_id`,`runbook_version_task_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create runbook version tasks: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `runbook_version_task_dependencies` (
    `runbook_version_task_id` bigint(20) NOT NULL,
    `depends_on_runbook_version_task_id` bigint(20) NOT NULL,
    PRIMARY KEY (`runbook_version_task_id`,`depends_on_runbook_version_task_id`),
    KEY `depends_on_runbook_version_task_id` (`depends_on_runbook_version_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create runbook version dependencies: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `runbook_executions` (
    `runbook_execution_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `runbook_execution_version_id` bigint(20) NOT NULL,
    `runbook_execution_ticket_id` int(11) NOT NULL,
    `runbook_execution_status` varchar(20) NOT NULL DEFAULT 'Active',
    `runbook_execution_context` longtext DEFAULT NULL,
    `runbook_execution_snapshot` longtext NOT NULL,
    `runbook_execution_snapshot_hash` char(64) NOT NULL,
    `runbook_execution_started_by` int(11) NOT NULL DEFAULT 0,
    `runbook_execution_started_at` datetime NOT NULL DEFAULT current_timestamp(),
    `runbook_execution_completed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`runbook_execution_id`),
    UNIQUE KEY `runbook_execution_ticket` (`runbook_execution_ticket_id`),
    KEY `runbook_execution_version` (`runbook_execution_version_id`,`runbook_execution_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create runbook executions: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `task_dependencies` (
    `task_id` int(11) NOT NULL,
    `depends_on_task_id` int(11) NOT NULL,
    PRIMARY KEY (`task_id`,`depends_on_task_id`),
    KEY `depends_on_task_id` (`depends_on_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create task dependencies: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `task_evidence` (
    `task_evidence_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `task_evidence_task_id` int(11) NOT NULL,
    `task_evidence_type` varchar(20) NOT NULL,
    `task_evidence_note` text DEFAULT NULL,
    `task_evidence_url` varchar(1000) DEFAULT NULL,
    `task_evidence_attachment_id` int(11) NOT NULL DEFAULT 0,
    `task_evidence_submitted_by` int(11) NOT NULL DEFAULT 0,
    `task_evidence_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`task_evidence_id`),
    KEY `task_evidence_task` (`task_evidence_task_id`,`task_evidence_type`),
    KEY `task_evidence_attachment` (`task_evidence_attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create task evidence: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `task_state_events` (
    `task_state_event_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `task_state_event_task_id` int(11) NOT NULL,
    `task_state_event_from_state` varchar(20) DEFAULT NULL,
    `task_state_event_to_state` varchar(20) NOT NULL,
    `task_state_event_reason` varchar(255) DEFAULT NULL,
    `task_state_event_actor_type` varchar(20) NOT NULL DEFAULT 'system',
    `task_state_event_actor_id` int(11) NOT NULL DEFAULT 0,
    `task_state_event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`task_state_event_id`),
    KEY `task_state_event_task` (`task_state_event_task_id`,`task_state_event_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create task state event history: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('ticket_templates', 'ticket_template_published_version')
    && !mysqli_query($mysqli, "ALTER TABLE `ticket_templates`
        ADD KEY `ticket_template_published_version` (`ticket_template_published_version_id`)")) {
    throw new RuntimeException('Could not index published ticket template versions: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('ticket_templates', 'ticket_template_runbook_key_unique')
    && !mysqli_query($mysqli, "ALTER TABLE `ticket_templates`
        ADD UNIQUE KEY `ticket_template_runbook_key_unique` (`ticket_template_runbook_key`)")) {
    throw new RuntimeException('Could not enforce unique runbook keys: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('project_template_ticket_templates', 'ticket_template_runbook_version')
    && !mysqli_query($mysqli, "ALTER TABLE `project_template_ticket_templates`
        ADD KEY `ticket_template_runbook_version` (`ticket_template_runbook_version_id`)")) {
    throw new RuntimeException('Could not index project template runbook versions: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('task_approvals', 'approval_task_status')
    && !mysqli_query($mysqli, "ALTER TABLE `task_approvals`
        ADD KEY `approval_task_status` (`approval_task_id`,`approval_status`)")) {
    throw new RuntimeException('Could not index task approval gates: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('task_templates', 'task_template_key_unique')
    && !mysqli_query($mysqli, "ALTER TABLE `task_templates`
        ADD UNIQUE KEY `task_template_key_unique` (`task_template_ticket_template_id`,`task_template_key`)")) {
    throw new RuntimeException('Could not index task template keys: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('tasks', 'task_runbook_state')
    && !mysqli_query($mysqli, "ALTER TABLE `tasks`
        ADD KEY `task_runbook_state` (`task_ticket_id`,`task_state`,`task_due_at`)")) {
    throw new RuntimeException('Could not index runbook task state: ' . mysqli_error($mysqli));
}

if (!$runbook_index_exists('tasks', 'task_assigned_to')
    && !mysqli_query($mysqli, "ALTER TABLE `tasks`
        ADD KEY `task_assigned_to` (`task_assigned_to`)")) {
    throw new RuntimeException('Could not index task owners: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `tasks` SET task_state = CASE
    WHEN task_completed_at IS NULL THEN 'Ready'
    ELSE 'Completed'
END WHERE task_state IS NULL OR task_state = '' OR (task_completed_at IS NOT NULL AND task_state = 'Ready')")) {
    throw new RuntimeException('Could not initialize runbook task state: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "INSERT INTO `task_state_events` (
        task_state_event_task_id, task_state_event_from_state,
        task_state_event_to_state, task_state_event_reason,
        task_state_event_actor_type, task_state_event_actor_id
    )
    SELECT task_id, NULL, task_state, 'Upgrade baseline', 'system', 0
    FROM tasks
    WHERE NOT EXISTS (SELECT 1 FROM task_state_events
        WHERE task_state_event_task_id = task_id)")) {
    throw new RuntimeException('Could not initialize task state event history: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "INSERT INTO `task_approval_events` (
        task_approval_event_approval_id, task_approval_event_task_id,
        task_approval_event_action, task_approval_event_from_status,
        task_approval_event_to_status, task_approval_event_from_scope,
        task_approval_event_to_scope, task_approval_event_from_type,
        task_approval_event_to_type, task_approval_event_from_required_user_id,
        task_approval_event_to_required_user_id, task_approval_event_actor_type,
        task_approval_event_actor_id, task_approval_event_actor_label,
        task_approval_event_request_expires_at, task_approval_event_created_at
    )
    SELECT approval_id, approval_task_id, 'baseline', NULL, approval_status,
        NULL, approval_scope, NULL, approval_type, 0,
        COALESCE(approval_required_user_id, 0), 'system', 0,
        NULLIF(approval_approved_by, ''), approval_url_expires_at,
        COALESCE(approval_decided_at, approval_created_at, NOW())
    FROM task_approvals
    WHERE NOT EXISTS (SELECT 1 FROM task_approval_events
        WHERE task_approval_event_approval_id = approval_id)")) {
    throw new RuntimeException('Could not initialize task approval event history: ' . mysqli_error($mysqli));
}
