<?php

/*
 * ITFlow - GET/POST request handler for categories ('category')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_category'])) {

    validateCSRFToken();

    require_once 'category_model.php';

    try {
        portalRequestDbQuery("INSERT INTO categories SET category_name = '$name',
            category_description = '$description', category_type = '$type', category_color = '$color'",
            'Could not create the category');
        $category_id = intval(mysqli_insert_id($mysqli));
        if (!$category_id) {
            throw new RuntimeException('The category creation did not receive an ID');
        }
    } catch (Throwable $exception) {
        error_log('Category creation failed: ' . $exception->getMessage());
        flashAlert('The category could not be created.', 'error');
        redirect();
    }

    logAudit("Category", "Create", "$session_name created category $type $name", 0, $category_id);

    flashAlert("Category $type <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_category'])) {

    validateCSRFToken();

    require_once 'category_model.php';

    $category_id = intval($_POST['category_id']);
    $requested_type = (string) ($_POST['type'] ?? '');

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('The category update could not start. No changes were saved.', 'error');
        redirect();
    }
    try {
        $current = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_id, category_type
            FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE",
            'Could not lock the category for editing'));
        if (!$current) {
            throw new RuntimeException('The category no longer exists');
        }
        $published_reference = portalRequestDbQuery("SELECT portal_request_catalog_version_id
            FROM portal_request_catalog_versions
            WHERE portal_request_catalog_version_category_id = $category_id LIMIT 1",
            'Could not validate immutable request catalog category references');
        if (mysqli_num_rows($published_reference) > 0
            && (string) $current['category_type'] !== $requested_type) {
            mysqli_rollback($mysqli);
            flashAlert('This category is pinned by a published portal request and its type cannot be changed. Name, description, and color may still be edited.', 'error');
            redirect();
        }
        portalRequestDbQuery("UPDATE categories SET category_name = '$name',
            category_description = '$description', category_type = '$type', category_color = '$color'
            WHERE category_id = $category_id LIMIT 1",
            'Could not update the category');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('The category update did not commit');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Category $category_id update failed: " . $exception->getMessage());
        flashAlert('The category could not be updated. No changes were saved.', 'error');
        redirect();
    }

    logAudit("Category", "Edit", "$session_name edited category $type $name", 0, $category_id);

    flashAlert("Category $type <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_category'])) {

    validateCSRFToken();

    $category_id = intval($_GET['archive_category']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('Category archival could not start. No changes were saved.', 'error');
        redirect();
    }
    try {
        $row = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_name, category_type
            FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE",
            'Could not lock the category for archival'));
        if (!$row) {
            throw new RuntimeException('The category no longer exists');
        }
        $published_reference = portalRequestDbQuery("SELECT portal_request_catalog_version_id
            FROM portal_request_catalog_versions
            WHERE portal_request_catalog_version_category_id = $category_id LIMIT 1",
            'Could not validate immutable request catalog category references');
        if (mysqli_num_rows($published_reference) > 0) {
            mysqli_rollback($mysqli);
            flashAlert('This category is pinned by a published portal request and cannot be archived.', 'error');
            redirect();
        }
        $category_name = escapeSql($row['category_name']);
        $category_type = escapeSql($row['category_type']);
        portalRequestDbQuery("UPDATE categories SET category_archived_at = NOW()
            WHERE category_id = $category_id LIMIT 1",
            'Could not archive the category');
        if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
            throw new RuntimeException('Category archival did not commit');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Category $category_id archival failed: " . $exception->getMessage());
        flashAlert('The category could not be archived. No changes were saved.', 'error');
        redirect();
    }

    logAudit("Category", "Archive", "$session_name archived category $category_type $category_name", 0, $category_id);

    flashAlert("Category $category_type <strong>$category_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_category'])) {

    validateCSRFToken();

    $category_id = intval($_GET['restore_category']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('Category restoration could not start. No changes were saved.', 'error');
        redirect();
    }
    try {
        $row = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_name, category_type
            FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE",
            'Could not lock the category for restoration'));
        if (!$row) {
            throw new RuntimeException('The category no longer exists');
        }
        $category_name = escapeSql($row['category_name']);
        $category_type = escapeSql($row['category_type']);
        portalRequestDbQuery("UPDATE categories SET category_archived_at = NULL
            WHERE category_id = $category_id LIMIT 1",
            'Could not restore the category');
        if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
            throw new RuntimeException('Category restoration did not commit');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Category $category_id restoration failed: " . $exception->getMessage());
        flashAlert('The category could not be restored. No changes were saved.', 'error');
        redirect();
    }

    logAudit("Category", "Restore", "$session_name restored category $category_type $category_name", 0, $category_id);

    flashAlert("Category $category_type <strong>$category_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_category'])) {

    validateCSRFToken();

    $category_id = intval($_GET['delete_category']);

    if (!mysqli_begin_transaction($mysqli)) {
        flashAlert('Category deletion could not start. Nothing was deleted.', 'error');
        redirect();
    }
    try {
        $row = mysqli_fetch_assoc(portalRequestDbQuery("SELECT category_name, category_type
            FROM categories WHERE category_id = $category_id LIMIT 1 FOR UPDATE",
            'Could not lock the category for deletion'));
        if (!$row) {
            throw new RuntimeException('The category no longer exists');
        }
        $published_reference = portalRequestDbQuery("SELECT portal_request_catalog_version_id
            FROM portal_request_catalog_versions
            WHERE portal_request_catalog_version_category_id = $category_id LIMIT 1",
            'Could not validate immutable request catalog category references');
        if (mysqli_num_rows($published_reference) > 0) {
            mysqli_rollback($mysqli);
            flashAlert('This category is pinned by a published portal request and cannot be permanently deleted.', 'error');
            redirect();
        }
        $category_name = escapeSql($row['category_name']);
        $category_type = escapeSql($row['category_type']);
        portalRequestDbQuery("DELETE FROM categories WHERE category_id = $category_id LIMIT 1",
            'Could not permanently delete the category');
        if (mysqli_affected_rows($mysqli) !== 1 || !mysqli_commit($mysqli)) {
            throw new RuntimeException('Category deletion did not commit');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        error_log("Category $category_id deletion failed: " . $exception->getMessage());
        flashAlert('The category could not be permanently deleted. Nothing was deleted.', 'error');
        redirect();
    }

    logAudit("Category", "Delete", "$session_name deleted category $category_type $category_name");

    flashAlert("Category $category_type <strong>$category_name</strong> deleted", 'error');

    redirect();

}

// End category mutation handlers.
