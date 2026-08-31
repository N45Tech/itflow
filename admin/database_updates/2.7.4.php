<?php

/*
 * ITFlow - Database update to version 2.7.4 (from 2.7.3)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `level_interface_links` (
    `level_interface_link_id` int(11) NOT NULL AUTO_INCREMENT,
    `level_device_id` varchar(255) NOT NULL,
    `level_interface_key` varchar(255) NOT NULL,
    `level_asset_interface_id` int(11) NOT NULL,
    `level_interface_last_seen_at` datetime DEFAULT NULL,
    `level_interface_deleted_at` datetime DEFAULT NULL,
    `level_interface_link_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `level_interface_link_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`level_interface_link_id`),
    UNIQUE KEY `level_device_interface` (`level_device_id`,`level_interface_key`),
    UNIQUE KEY `level_asset_interface_id` (`level_asset_interface_id`),
    CONSTRAINT `level_interface_links_interface_fk` FOREIGN KEY (`level_asset_interface_id`) REFERENCES `asset_interfaces` (`interface_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
    throw new RuntimeException('Could not create Level interface mappings: ' . mysqli_error($mysqli));
}
