<?php

/*
 * N45 migration n45-0031-strict-ticket-retention-default
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

if (!mysqli_query($mysqli, "ALTER TABLE `clients`
    MODIFY COLUMN `client_ticket_retention_policy` varchar(20) NOT NULL DEFAULT 'strict'")) {
    throw new RuntimeException('Could not set strict client ticket retention default: '
        . mysqli_error($mysqli));
}
