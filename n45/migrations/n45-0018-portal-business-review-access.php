<?php

/*
 * N45 migration n45-0018-portal-business-review-access
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `contacts`
    ADD COLUMN IF NOT EXISTS `contact_portal_review_access` tinyint(1) NOT NULL DEFAULT 0
    AFTER `contact_portal_manage_contacts`")) {
    throw new RuntimeException('Could not add the portal business-review permission: '
        . mysqli_error($mysqli));
}
