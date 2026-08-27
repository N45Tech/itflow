<?php

/*
 * ITFlow - Database update to version 2.7.2 (from 2.7.1)
 * Included by admin/database_updates.php - do not access directly
 */

defined('FROM_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `contacts`
    ADD COLUMN `contact_portal_ticket_scope` varchar(20) NOT NULL DEFAULT 'own' AFTER `contact_technical`,
    ADD COLUMN `contact_portal_asset_scope` varchar(20) NOT NULL DEFAULT 'assigned' AFTER `contact_portal_ticket_scope`,
    ADD COLUMN `contact_portal_manage_contacts` tinyint(1) NOT NULL DEFAULT 0 AFTER `contact_portal_asset_scope`")) {
    throw new RuntimeException('Could not add client portal access scopes: ' . mysqli_error($mysqli));
}

// Preserve the organization-wide portal access that primary and technical contacts
// had before these permissions became independent settings.
if (!mysqli_query($mysqli, "UPDATE `contacts`
    SET `contact_portal_ticket_scope` = 'client',
        `contact_portal_asset_scope` = 'client'
    WHERE `contact_primary` = 1 OR `contact_technical` = 1")) {
    throw new RuntimeException('Could not migrate client portal ticket and asset access: ' . mysqli_error($mysqli));
}

if (!mysqli_query($mysqli, "UPDATE `contacts`
    SET `contact_portal_manage_contacts` = 1
    WHERE `contact_primary` = 1 OR `contact_technical` = 1")) {
    throw new RuntimeException('Could not migrate client portal contact management access: ' . mysqli_error($mysqli));
}
