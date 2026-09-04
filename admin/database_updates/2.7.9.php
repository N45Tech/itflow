<?php

/*
 * ITFlow - Database update to version 2.7.9 (from 2.7.8)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

$review_access_column = mysqli_query($mysqli, "SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'contacts'
    AND COLUMN_NAME = 'contact_portal_review_access'
    LIMIT 1");

if (!$review_access_column) {
    throw new RuntimeException('Could not inspect the portal review permission: ' . mysqli_error($mysqli));
}

if (mysqli_num_rows($review_access_column) === 0
    && !mysqli_query($mysqli, "ALTER TABLE `contacts`
        ADD COLUMN `contact_portal_review_access` tinyint(1) NOT NULL DEFAULT 0
        AFTER `contact_portal_manage_contacts`")) {
    throw new RuntimeException('Could not add the portal review permission: ' . mysqli_error($mysqli));
}

unset($review_access_column);
