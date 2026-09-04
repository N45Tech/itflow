<?php

/*
 * N45 migration n45-0004-mail-template-metadata (legacy marker 2.7.1)
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `email_queue`
    ADD COLUMN IF NOT EXISTS `email_content_plain` longtext DEFAULT NULL AFTER `email_content`,
    ADD COLUMN IF NOT EXISTS `email_template_key` varchar(100) DEFAULT NULL AFTER `email_content_plain`")) {
    throw new RuntimeException('Could not add branded email metadata to the mail queue: ' . mysqli_error($mysqli));
}
