<?php

/*
 * ITFlow
 * Applies pending database migrations from admin/database_updates/
 * Used in conjunction with database_version.php
 *
 * Each file in admin/database_updates/ is named for the database version it
 * upgrades TO (e.g. 2.4.5.php contains the queries that bring 2.4.4 to 2.4.5).
 * This runner applies every file newer than the current database version, in
 * order, and bumps config_current_database_version after each one - so a
 * single run brings the database all the way to the latest version.
 *
 * To add a migration: create admin/database_updates/<new version>.php - that
 * is the whole job. The latest version is derived from the directory listing
 * (see includes/database_version.php) and this runner handles the version
 * bump, so there is no constant to update and no bump query to remember.
 */

// Check if our database versions are defined
// If undefined, the file is probably being accessed directly rather than called via post.php?update_db or update_cli.php
if (!defined("LATEST_DATABASE_VERSION") || !defined("CURRENT_DATABASE_VERSION") || !isset($mysqli)) {
    echo "Cannot access this file directly.";
    exit();
}

// Migration files include-guard against this constant
define("FROM_DB_UPDATER", true);

// Outputs for the caller (post/update.php, update_cli.php)
$database_updates_applied = [];   // Versions successfully applied this run
$database_updates_error = null;   // "version: error message" if a migration failed

// Collect and order the migration files by version (glob sorts alphabetically,
// which would put 2.4.10 before 2.4.9 - version_compare gets it right)
$database_update_files = [];
foreach (glob(__DIR__ . "/database_updates/*.php") as $file) {
    $version = basename($file, ".php");
    if (preg_match('/^\d+(\.\d+)+$/', $version)) {
        $database_update_files[$version] = $file;
    }
}
uksort($database_update_files, "version_compare");

// Only one request may inspect and advance the durable version at a time. The
// zero-second timeout fails closed instead of waiting with a stale page-load
// CURRENT_DATABASE_VERSION value.
$database_update_lock_name = 'itflow-database-updates';
$database_update_lock_name_sql = null;
$database_update_lock_acquired = false;
$database_update_active_version = null;

try {
    $database_update_lock_name_sql = mysqli_real_escape_string($mysqli, $database_update_lock_name);
    $database_update_lock_result = mysqli_query(
        $mysqli,
        "SELECT GET_LOCK('$database_update_lock_name_sql', 0)"
    );
    $database_update_lock_row = $database_update_lock_result
        ? mysqli_fetch_row($database_update_lock_result)
        : false;
    if (!$database_update_lock_row || intval($database_update_lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another database update is already running; retry after it finishes');
    }
    $database_update_lock_acquired = true;

    // CURRENT_DATABASE_VERSION is frozen at page load. Re-read the marker only
    // after obtaining the lock so a request that started during another update
    // cannot replay migrations from its stale value.
    $database_current_version_result = mysqli_query($mysqli, "SELECT config_current_database_version
        FROM settings WHERE company_id = 1 LIMIT 1");
    $database_current_version_row = $database_current_version_result
        ? mysqli_fetch_assoc($database_current_version_result)
        : false;
    $database_current_version = (string) ($database_current_version_row['config_current_database_version'] ?? '');
    if (!preg_match('/^\d+(\.\d+)+$/', $database_current_version)) {
        throw new RuntimeException('The durable database version marker is missing or invalid');
    }

    // Apply everything newer than the durable database version, in order.
    foreach ($database_update_files as $version => $file) {

        if (version_compare($version, $database_current_version, "<=")) {
            continue;
        }

        $database_update_active_version = $version;
        try {
            require $file;
        } catch (Throwable $e) {
            // Stop here - config_current_database_version still points at the last
            // completed migration, so a re-run resumes at this file
            $database_updates_error = "$version: " . $e->getMessage();
            error_log("ITFlow database update to version $version failed: " . $e->getMessage());
            break;
        }

        // Migration succeeded only when the durable version marker advances too.
        // DDL migrations are intentionally restartable, so a marker failure stops
        // here and the same migration is safely retried on the next run.
        $version_sql = escapeSql($version);
        $version_update = mysqli_query($mysqli, "UPDATE `settings`
            SET `config_current_database_version` = '$version_sql'");
        $version_marker_result = $version_update
            ? mysqli_query($mysqli, "SELECT COUNT(*) FROM `settings`
                WHERE `config_current_database_version` = '$version_sql'")
            : false;
        $version_marker = $version_marker_result ? mysqli_fetch_row($version_marker_result) : false;
        if (!$version_update || !$version_marker || intval($version_marker[0] ?? 0) < 1) {
            $database_updates_error = "$version: schema changes completed but the database version marker could not be saved";
            error_log("ITFlow database update to version $version could not save its version marker: " . mysqli_error($mysqli));
            break;
        }
        $database_current_version = $version;
        $database_updates_applied[] = $version;
        $database_update_active_version = null;
    }
} catch (Throwable $e) {
    if ($database_updates_error === null) {
        $error_prefix = $database_update_active_version === null
            ? 'migration runner'
            : $database_update_active_version;
        $database_updates_error = $error_prefix . ': ' . $e->getMessage();
        error_log('ITFlow database update failed at ' . $database_updates_error);
    }
} finally {
    if ($database_update_lock_acquired) {
        try {
            $database_update_release_result = mysqli_query(
                $mysqli,
                "SELECT RELEASE_LOCK('$database_update_lock_name_sql')"
            );
            $database_update_release_row = $database_update_release_result
                ? mysqli_fetch_row($database_update_release_result)
                : false;
            if (!$database_update_release_row || intval($database_update_release_row[0] ?? 0) !== 1) {
                throw new RuntimeException('The database update lock release could not be confirmed');
            }
        } catch (Throwable $release_error) {
            $release_message = 'migration runner: ' . $release_error->getMessage();
            if ($database_updates_error === null) {
                $database_updates_error = $release_message;
            } else {
                $database_updates_error .= '; ' . $release_message;
            }
            error_log('ITFlow ' . $release_message);
        }
    }
}
