<?php

/*
 * ITFlow - Database update to version 2.6.8 (from 2.6.7)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

mysqli_query($mysqli, "ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `config_azure_tenant_id` varchar(36) DEFAULT NULL AFTER `config_azure_client_secret`,
    ADD COLUMN IF NOT EXISTS `config_azure_agent_sso_enable` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_azure_tenant_id`");

mysqli_query($mysqli, "ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `user_azure_oid` varchar(36) DEFAULT NULL AFTER `user_auth_method`,
    ADD COLUMN IF NOT EXISTS `user_azure_tenant_id` varchar(36) DEFAULT NULL AFTER `user_azure_oid`");

// The durable Entra identity pair can belong to only one ITFlow technician.
$index_exists = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS index_count
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'user_azure_identity'"));

if (intval($index_exists['index_count'] ?? 0) === 0) {
    mysqli_query($mysqli, "ALTER TABLE `users`
        ADD UNIQUE KEY `user_azure_identity` (`user_azure_tenant_id`, `user_azure_oid`)");
}
