<?php

/*
 * N45 migration n45-0001-entra-agent-sso (legacy marker 2.6.8)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `settings`
    ADD COLUMN IF NOT EXISTS `config_azure_tenant_id` varchar(36) DEFAULT NULL AFTER `config_azure_client_secret`,
    ADD COLUMN IF NOT EXISTS `config_azure_agent_sso_enable` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_azure_tenant_id`")) {
    throw new RuntimeException('Could not add N45 Entra settings: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `user_azure_oid` varchar(36) DEFAULT NULL AFTER `user_auth_method`,
    ADD COLUMN IF NOT EXISTS `user_azure_tenant_id` varchar(36) DEFAULT NULL AFTER `user_azure_oid`")) {
    throw new RuntimeException('Could not add durable N45 Entra identities: ' . mysqli_error($mysqli));
}

// The durable Entra identity pair can belong to only one ITFlow technician.
$index_result = mysqli_query($mysqli, "SELECT COUNT(*) AS index_count
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'user_azure_identity'");
$index_exists = $index_result ? mysqli_fetch_assoc($index_result) : false;
if ($index_exists === false) {
    throw new RuntimeException('Could not inspect N45 Entra identity indexes: ' . mysqli_error($mysqli));
}

if (intval($index_exists['index_count'] ?? 0) === 0) {
    if (!mysqli_query($mysqli, "ALTER TABLE `users`
        ADD UNIQUE KEY `user_azure_identity` (`user_azure_tenant_id`, `user_azure_oid`)")) {
        throw new RuntimeException('Could not enforce unique N45 Entra identities: ' . mysqli_error($mysqli));
    }
}
