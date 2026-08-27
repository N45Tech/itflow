<?php

/*
 * ITFlow - Database update to version 2.7.1 (from 2.7.0)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `email_queue`
    ADD COLUMN `email_content_plain` longtext DEFAULT NULL AFTER `email_content`,
    ADD COLUMN `email_template_key` varchar(100) DEFAULT NULL AFTER `email_content_plain`")) {
    throw new RuntimeException('Could not add branded email metadata to the mail queue: ' . mysqli_error($mysqli));
}
