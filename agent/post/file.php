<?php

/*
 * ITFlow - GET/POST request handler for client files/uploads
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Deleted file metadata and quarantined bytes are mutable only through the
// administrator retention workflow. Cover both single and bulk forged posts.
$retention_file_ids = [];
foreach (['file_id', 'archive_file', 'restore_file'] as $retention_file_key) {
    if (isset($_POST[$retention_file_key])) {
        $retention_file_ids[] = intval($_POST[$retention_file_key]);
    }
    if (isset($_GET[$retention_file_key])) {
        $retention_file_ids[] = intval($_GET[$retention_file_key]);
    }
}
foreach ((array) ($_POST['file_ids'] ?? []) as $retention_file_id) {
    $retention_file_ids[] = intval($retention_file_id);
}
$retention_file_ids = array_values(array_unique(array_filter($retention_file_ids)));
if ($retention_file_ids) {
    $retention_file_id_sql = implode(',', $retention_file_ids);
    $retention_deleted_count = retentionCount("SELECT COUNT(*) FROM files
        WHERE file_id IN ($retention_file_id_sql) AND file_deleted_at IS NOT NULL",
        'Could not inspect deleted file mutation targets');
    if ($retention_deleted_count > 0) {
        flashAlert('Deleted files are immutable outside Administration > Retention.', 'error');
        redirect('/admin/retention.php');
    }
}

$documentation_document_evidence_in_use = static function ($document_id, $client_id) use ($mysqli) {
    $document_id = intval($document_id);
    $client_id = intval($client_id);
    if (documentationEvidenceReferenceInUse('document', $document_id, $client_id)) {
        return true;
    }
    $versions = documentationLifecycleDbQuery("SELECT document_version_id FROM document_versions
        WHERE document_version_document_id = $document_id ORDER BY document_version_id FOR UPDATE",
        'Could not inspect document version evidence');
    while ($version = mysqli_fetch_assoc($versions)) {
        if (documentationEvidenceReferenceInUse(
            'document-version',
            intval($version['document_version_id']),
            $client_id
        )) {
            return true;
        }
    }
    return false;
};

$documentation_mutate_file = static function ($file_id, $client_id, $operation) use ($mysqli) {
    $file_id = intval($file_id);
    $client_id = intval($client_id);
    if (!$file_id || !$client_id || !in_array($operation, ['archive', 'delete'], true)) {
        throw new InvalidArgumentException('A valid file mutation is required');
    }

    if ($operation === 'delete') {
        return retentionSoftDeleteFile(
            $file_id,
            $client_id,
            intval($GLOBALS['session_user_id'] ?? 0),
            (string) ($_POST['delete_reason'] ?? '')
        );
    }

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the file mutation transaction');
        }
        $transaction_started = true;

        // Verification locks this same client before its referenced file. Holding
        // both rows makes the Evidence Locker recheck authoritative for this write.
        documentationLockClient($client_id);
        $file = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT file_client_id,
            file_name, file_reference_name,
            file_archived_at FROM files WHERE file_id = $file_id
            AND file_deleted_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the file mutation'));
        if (!$file || intval($file['file_client_id']) !== $client_id) {
            throw new RuntimeException('The file client changed before the mutation');
        }
        if (documentationEvidenceReferenceInUse('file', $file_id, $client_id)) {
            throw new DomainException('The file is retained in the Evidence Locker');
        }

        documentationLifecycleDbQuery("UPDATE files SET file_archived_at = NOW()
            WHERE file_id = $file_id AND file_client_id = $client_id
            AND file_archived_at IS NULL AND file_deleted_at IS NULL LIMIT 1", 'Could not archive the file');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The file changed before the mutation');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the file mutation');
        }
        $transaction_started = false;
        return $file;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
};

$documentation_mutate_document = static function (
    $document_id,
    $client_id,
    $actor_id,
    $operation
) use ($mysqli, $documentation_document_evidence_in_use) {
    $document_id = intval($document_id);
    $client_id = intval($client_id);
    $actor_id = intval($actor_id);
    if (!in_array($operation, ['archive', 'delete'], true)) {
        throw new InvalidArgumentException('Unsupported bulk document mutation');
    }
    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the bulk document transaction');
        }
        $transaction_started = true;
        documentationInvalidateDocumentLocked(
            $document_id,
            $client_id,
            $actor_id,
            $operation === 'archive' ? 'document_archived' : 'document_deleted'
        );
        $document = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT document_client_id,
            document_archived_at FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE",
            'Could not lock the bulk document mutation'));
        if (!$document || intval($document['document_client_id']) !== $client_id) {
            throw new RuntimeException('The document client changed before the bulk mutation');
        }
        if (documentationDocumentHasObligations($document_id)
            || $documentation_document_evidence_in_use($document_id, $client_id)) {
            throw new DomainException('The document is retained by documentation history');
        }
        if ($operation === 'archive') {
            documentationLifecycleDbQuery("UPDATE documents SET document_archived_at = NOW(),
                document_updated_at = document_updated_at WHERE document_id = $document_id
                AND document_archived_at IS NULL LIMIT 1", 'Could not archive the bulk document');
        } else {
            documentationLifecycleDbQuery("DELETE FROM document_versions
                WHERE document_version_document_id = $document_id", 'Could not delete bulk document versions');
            documentationLifecycleDbQuery("DELETE FROM documents WHERE document_id = $document_id LIMIT 1",
                'Could not delete the bulk document');
        }
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The document changed before the bulk mutation');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the bulk document mutation');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
};

if (isset($_POST['upload_files'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // Sanitize and initialize inputs
    $client_id   = intval($_POST['client_id']);
    $folder_id   = intval($_POST['folder_id']);
    $description = escapeSql($_POST['description']);
    $contact_id = intval($_POST['contact_id'] ?? 0);
    $asset_id = intval($_POST['asset_id'] ?? 0);
    $client_dir  = "../uploads/clients/$client_id";

    enforceClientAccess($client_id);

    if ($folder_id) {
        $folder = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT folder_id FROM folders
            WHERE folder_id = $folder_id AND folder_client_id = $client_id LIMIT 1"));
        if (!$folder) {
            flashAlert('The selected folder is unavailable for this client.', 'error');
            redirect();
        }
    }
    if ($contact_id) {
        $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts
            WHERE contact_id = $contact_id AND contact_client_id = $client_id
            AND contact_archived_at IS NULL LIMIT 1"));
        if (!$contact) {
            flashAlert('The selected contact is unavailable for this client.', 'error');
            redirect();
        }
    }
    if ($asset_id) {
        $asset = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_id FROM assets
            WHERE asset_id = $asset_id AND asset_client_id = $client_id
            AND asset_archived_at IS NULL LIMIT 1"));
        if (!$asset) {
            flashAlert('The selected asset is unavailable for this client.', 'error');
            redirect();
        }
    }

    // Create client directory if it doesn't exist
    if (!is_dir($client_dir)) {
        mkdir($client_dir, 0755, true);
    }

    // Allowed file extensions list
    $allowedExtensions = [
        'jpg', 'jpeg', 'gif', 'png', 'webp', 'pdf', 'txt', 'md', 'doc', 'docx',
        'odt', 'csv', 'xls', 'xlsx', 'ods', 'pptx', 'odp', 'zip', 'tar', 'gz',
        'msg', 'json', 'wav', 'mp3', 'ogg', 'mov', 'mp4', 'av1', 'ovpn',
        'cfg', 'ps1', 'vsdx', 'drawio', 'pfx', 'pages', 'numbers', 'unf', 'unifi',
        'key', 'bat', 'stk', 'swb'
    ];

    // Loop through each uploaded file
    foreach ($_FILES['file']['name'] as $index => $originalName) {

        // Build a file array for this iteration
        $single_file = [
            'name'     => $_FILES['file']['name'][$index],
            'type'     => $_FILES['file']['type'][$index],
            'tmp_name' => $_FILES['file']['tmp_name'][$index],
            'error'    => $_FILES['file']['error'][$index],
            'size'     => $_FILES['file']['size'][$index]
        ];

        // Validate and get a safe file reference name
        if ($file_reference_name = checkFileUpload($single_file, $allowedExtensions)) {

            $file_tmp_path   = $single_file['tmp_name'];
            $file_name       = escapeSql($originalName);
            $extParts        = explode('.', $file_name);
            $file_extension  = strtolower(end($extParts));
            $file_mime_type  = escapeSql($single_file['type']);
            $file_size       = intval($single_file['size']);

            // Define destination path and move the uploaded file
            $upload_file_dir = $client_dir . "/";
            $dest_path       = $upload_file_dir . $file_reference_name;

            if (!move_uploaded_file($file_tmp_path, $dest_path)) {
                flashAlert('Error moving file to upload directory. Please ensure the directory is writable.', 'error');
                continue; // Skip processing this file
            }

            // Use the file reference (without extension) as the file hash
            $file_hash = strstr($file_reference_name, '.', true) ?: $file_reference_name;

            // Insert file metadata into the database
            $query = "INSERT INTO files SET
                        file_reference_name = '$file_reference_name',
                        file_name = '$file_name',
                        file_description = '$description',
                        file_ext = '$file_extension',
                        file_mime_type = '$file_mime_type',
                        file_size = $file_size,
                        file_created_by = $session_user_id,
                        file_folder_id = $folder_id,
                        file_client_id = $client_id";
            mysqli_query($mysqli, $query);
            $file_id = mysqli_insert_id($mysqli);

            if ($contact_id) {
                $contact_linked = mysqli_query($mysqli, "INSERT INTO contact_files (contact_id, file_id)
                    SELECT c.contact_id, f.file_id FROM contacts c
                    INNER JOIN files f ON f.file_client_id = c.contact_client_id
                    WHERE c.contact_id = $contact_id AND c.contact_client_id = $client_id
                    AND c.contact_archived_at IS NULL AND f.file_id = $file_id
                    AND f.file_client_id = $client_id AND f.file_deleted_at IS NULL");
                if (!$contact_linked || mysqli_affected_rows($mysqli) !== 1) {
                    flashAlert('The file was uploaded, but the contact changed before it could be linked.', 'error');
                }
            }

            if ($asset_id) {
                $asset_linked = mysqli_query($mysqli, "INSERT INTO asset_files (asset_id, file_id)
                    SELECT a.asset_id, f.file_id FROM assets a
                    INNER JOIN files f ON f.file_client_id = a.asset_client_id
                    WHERE a.asset_id = $asset_id AND a.asset_client_id = $client_id
                    AND a.asset_archived_at IS NULL AND f.file_id = $file_id
                    AND f.file_client_id = $client_id AND f.file_deleted_at IS NULL");
                if (!$asset_linked || mysqli_affected_rows($mysqli) !== 1) {
                    flashAlert('The file was uploaded, but the asset changed before it could be linked.', 'error');
                }
            }

            logAudit("File", "Upload", "$session_name uploaded file $file_name", $client_id, $file_id);

            flashAlert("Uploaded file <strong>$file_name</strong>");
        }
    }

    redirect();

}


if (isset($_POST['rename_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_POST['file_id']);
    $file_name = escapeSql($_POST['file_name']);
    $file_description = escapeSql($_POST['file_description']);

    // Get File Details Client ID for Logging
    $sql = mysqli_query($mysqli, "SELECT file_name, file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($row['file_client_id']);

    enforceClientAccess($client_id);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the file rename transaction');
        }
        $transaction_started = true;
        documentationLockClient($client_id);
        $locked_file = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT file_name,
            file_client_id FROM files WHERE file_id = $file_id AND file_client_id = $client_id
            AND file_deleted_at IS NULL LIMIT 1 FOR UPDATE", 'Could not lock the active file for rename'));
        if (!$locked_file) {
            throw new RuntimeException('The active file changed before it could be renamed');
        }
        $old_file_name = escapeSql($locked_file['file_name']);
        documentationLifecycleDbQuery("UPDATE files SET file_name = '$file_name',
            file_description = '$file_description' WHERE file_id = $file_id
            AND file_client_id = $client_id AND file_deleted_at IS NULL LIMIT 1",
            'Could not rename the active file');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the file rename');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        flashAlert('The file could not be renamed. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("File", "Rename", "$session_name renamed file $old_file_name to $file_name", $client_id, $file_id);

    flashAlert("Renamed file <strong>$old_file_name</strong> to <strong>$file_name</strong>");

    redirect();

}

if (isset($_POST['move_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_POST['file_id']);
    $folder_id = intval($_POST['folder_id']);

    // Get File Name and  Client ID for Logging
    $sql = mysqli_query($mysqli, "SELECT file_name, file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($row['file_client_id']);

    enforceClientAccess($client_id);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the file move transaction');
        }
        $transaction_started = true;
        documentationLockClient($client_id);
        $locked_file = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT file_name,
            file_client_id FROM files WHERE file_id = $file_id AND file_client_id = $client_id
            AND file_deleted_at IS NULL LIMIT 1 FOR UPDATE", 'Could not lock the active file for move'));
        if (!$locked_file) {
            throw new RuntimeException('The active file changed before it could be moved');
        }
        $folder_name = '/';
        if ($folder_id) {
            $folder = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT folder_name FROM folders
                WHERE folder_id = $folder_id AND folder_client_id = $client_id LIMIT 1 FOR UPDATE",
                'Could not lock the destination file folder'));
            if (!$folder) {
                throw new DomainException('The destination folder is unavailable for this client');
            }
            $folder_name = escapeSql($folder['folder_name']);
        }
        $file_name = escapeSql($locked_file['file_name']);
        documentationLifecycleDbQuery("UPDATE files SET file_folder_id = $folder_id
            WHERE file_id = $file_id AND file_client_id = $client_id
            AND file_deleted_at IS NULL LIMIT 1", 'Could not move the active file');
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the file move');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        flashAlert($e instanceof DomainException
            ? $e->getMessage() : 'The file could not be moved. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("File", "Move", "$session_name moved file $file_name to $folder_name", $client_id, $file_id);

    flashAlert("File <strong>$file_name</strong> moved to <strong>$folder_name</strong>");

    redirect();

}

if (isset($_GET['archive_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_GET['archive_file']);

    // Get Contact Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli, "SELECT file_name, file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $file_name = escapeSql($row['file_name']);
    $client_id = intval($row['file_client_id']);

    enforceClientAccess();

    try {
        $locked_file = $documentation_mutate_file($file_id, $client_id, 'archive');
        $file_name = escapeSql($locked_file['file_name']);
    } catch (Throwable $e) {
        error_log("File $file_id archival failed safely: " . $e->getMessage());
        flashAlert($e instanceof DomainException
            ? 'This file is retained in the documentation Evidence Locker and cannot be archived.'
            : 'The file could not be archived. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("File", "Archive", "$session_name archived file $file_name", $client_id, $file_id);

    flashAlert("File <strong>$file_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_GET['restore_file']);

    // Get Document Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli, "SELECT file_name, file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $file_name = escapeSql($row['file_name']);
    $client_id = intval($row['file_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE files SET file_archived_at = NULL
        WHERE file_id = $file_id AND file_client_id = $client_id
        AND file_deleted_at IS NULL LIMIT 1");

    logAudit("File", "Restore", "$session_name restored file $file_name", $client_id, $file_id);

    flashAlert("File <strong>$file_name</strong> Restored");

    redirect();

}

if (isset($_POST['delete_file'])) {

    validateCSRFToken();

    enforceAdminPermission();

    $file_id = intval($_POST['file_id']);

    $sql_file = mysqli_query($mysqli,"SELECT * FROM files WHERE file_id = $file_id");
    $row = mysqli_fetch_assoc($sql_file);
    $client_id = intval($row['file_client_id']);
    $file_name = escapeSql($row['file_name']);
    enforceClientAccess();

    try {
        $locked_file = $documentation_mutate_file($file_id, $client_id, 'delete');
        $file_name = escapeSql($locked_file['file_name']);
    } catch (Throwable $e) {
        error_log("File $file_id deletion failed safely: " . $e->getMessage());
        flashAlert($e->getMessage(), 'error');
        redirect();
    }

    logAudit("Retention", "Soft Delete", "$session_name moved file $file_name to recoverable deletion", $client_id, $file_id);

    flashAlert("File <strong>$file_name</strong> moved to Deleted Records", 'info');

    redirect();

}

if (isset($_POST['bulk_archive_files'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_count = 0;
    $document_count = 0;
    $skipped_count = 0;
    $client_id = 0;

    // Archive file loop
    if (isset($_POST['file_ids'])) {

        // Get selected file Count
        foreach($_POST['file_ids'] as $file_id) {

            $file_id = intval($file_id);

            $sql_file = mysqli_query($mysqli, "SELECT file_client_id, file_name FROM files
                WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
            $row = mysqli_fetch_assoc($sql_file);
            if (!$row) {
                $skipped_count++;
                continue;
            }
            $client_id = intval($row['file_client_id']);
            $file_name = escapeSql($row['file_name']);

            enforceClientAccess();

            try {
                $locked_file = $documentation_mutate_file($file_id, $client_id, 'archive');
                $file_name = escapeSql($locked_file['file_name']);
            } catch (Throwable $e) {
                error_log("File $file_id bulk archival failed safely: " . $e->getMessage());
                $skipped_count++;
                continue;
            }
            $file_count++;

            logAudit("File", "Archive", "$session_name archived file $file_name", $client_id, $file_id);
        }

    }

    // Archive documents loop
    if (isset($_POST['document_ids'])) {

        // Get selected document count
        // Delete document loop
        foreach($_POST['document_ids'] as $document_id) {
            $document_id = intval($document_id);
            // Get document name for logging
            $sql = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
            $row = mysqli_fetch_assoc($sql);
            $document_name = escapeSql($row['document_name']);
            $client_id = intval($row['document_client_id']);

            enforceClientAccess();

            try {
                $documentation_mutate_document(
                    $document_id,
                    $client_id,
                    $session_user_id,
                    'archive'
                );
            } catch (Throwable $e) {
                error_log("Document $document_id bulk archival failed safely: " . $e->getMessage());
                $skipped_count++;
                continue;
            }
            $document_count++;

            logAudit("Document", "Archive", "$session_name archived document $document_name", $client_id, $document_id);

        }

    }

    logAudit("File", "Bulk Archive", "$session_name archived $document_count document(s) and $file_count file(s)", $client_id);

    flashAlert("Archived <strong>$document_count</strong> Documents and <strong>$file_count</strong> files", 'error');
    if ($skipped_count) {
        flashAlert("Skipped <strong>$skipped_count</strong> protected or changed documentation record(s).", 'info');
    }

    redirect();

}

if (isset($_POST['bulk_delete_files'])) {

    validateCSRFToken();

    enforceAdminPermission();

    flashAlert('Bulk deletion is disabled. Use Administration > Retention so every file has an owner reason, quarantine result, and restore window.', 'info');
    redirect('/admin/retention.php');

}

if (isset($_POST['bulk_restore_files'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    // Restore file loop
    if (isset($_POST['file_ids'])) {

        // Get selected file Count
        $file_count = count($_POST['file_ids']);

        foreach($_POST['file_ids'] as $file_id) {

            $file_id = intval($file_id);

            $sql_file = mysqli_query($mysqli, "SELECT file_client_id, file_name FROM files
                WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1");
            $row = mysqli_fetch_assoc($sql_file);
            if (!$row) {
                $file_count--;
                continue;
            }
            $client_id = intval($row['file_client_id']);
            $file_name = escapeSql($row['file_name']);

            enforceClientAccess();

            mysqli_query($mysqli, "UPDATE files SET file_archived_at = NULL
                WHERE file_id = $file_id AND file_client_id = $client_id
                AND file_deleted_at IS NULL LIMIT 1");

            logAudit("File", "Restore", "$session_name restored file $file_name", $client_id, $file_id);
        }

    }

    // Restore documents loop
    if (isset($_POST['document_ids'])) {

        // Get selected document count
        $document_count = count($_POST['document_ids']);

        // Restore document loop
        foreach($_POST['document_ids'] as $document_id) {
            $document_id = intval($document_id);
            // Get document name for logging
            $sql = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
            $row = mysqli_fetch_assoc($sql);
            $document_name = escapeSql($row['document_name']);
            $client_id = intval($row['document_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE documents SET document_archived_at = NULL, document_updated_at = document_updated_at WHERE document_id = $document_id");

            logAudit("Document", "Restore", "$session_name restored document $document_name", $client_id, $document_id);

        }

    }

    logAudit("File", "Bulk Restore", "$session_name restored $document_count document(s) and $file_count file(s)", $client_id);

    flashAlert("Restored <strong>$document_count</strong> Documents and <strong>$file_count</strong> files");

    redirect();

}

// Unified bulk move for Files + Documents
if (isset($_POST['bulk_move_files'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $folder_id = intval($_POST['bulk_folder_id']);

    // Default values (for root or missing folder)
    $folder_name    = "/";
    $log_client_id  = 0;

    // If moving into a real folder, get folder name + client for logging
    if ($folder_id > 0) {
        $sql = mysqli_query($mysqli,"SELECT folder_name, folder_client_id FROM folders WHERE folder_id = $folder_id");
        if ($row = mysqli_fetch_assoc($sql)) {
            $folder_name   = escapeSql($row['folder_name']);
            $log_client_id = intval($row['folder_client_id']);
        }
    }

    $file_count     = 0;
    $document_count = 0;

    // -------------------------
    // Move FILES (if any)
    // -------------------------
    if (!empty($_POST['file_ids']) && is_array($_POST['file_ids'])) {

        $file_ids = array_map('intval', $_POST['file_ids']);
        $file_count = count($file_ids);

        foreach ($file_ids as $file_id) {

            // Get file name for logging
            $file_name = escapeSql(getFieldById('files', $file_id, 'file_name'));
            $client_id = intval(getFieldById('files', $file_id, 'file_client_id'));

            enforceClientAccess();

            // Move file
            mysqli_query($mysqli,"UPDATE files SET file_folder_id = $folder_id WHERE file_id = $file_id");

            // Per-file log
            logAudit(
                "File",
                "Move",
                "$session_name moved file $file_name to folder $folder_name",
                $log_client_id,
                $file_id
            );
        }

        // Bulk summary log for files
        logAudit(
            "File",
            "Bulk Move",
            "$session_name moved $file_count file(s) to folder $folder_name",
            $log_client_id
        );
    }

    // -------------------------
    // Move DOCUMENTS (if any)
    // -------------------------
    if (!empty($_POST['document_ids']) && is_array($_POST['document_ids'])) {

        $document_ids = array_map('intval', $_POST['document_ids']);
        $document_count = count($document_ids);

        foreach ($document_ids as $document_id) {

            // Get document name for logging
            $document_name = escapeSql(getFieldById('documents', $document_id, 'document_name'));
            $client_id = intval(getFieldById('documents', $document_id, 'document_client_id'));

            enforceClientAccess();

            // Move document
            mysqli_query($mysqli,"UPDATE documents SET document_folder_id = $folder_id, document_updated_at = document_updated_at WHERE document_id = $document_id");

            // Per-document log
            logAudit(
                "Document",
                "Move",
                "$session_name moved document $document_name to folder $folder_name",
                $log_client_id,
                $document_id
            );
        }

        // Bulk summary log for documents
        logAudit(
            "Document",
            "Bulk Move",
            "$session_name moved $document_count document(s) to folder $folder_name",
            $log_client_id
        );
    }

    // -------------------------
    // Flash message
    // -------------------------
    if ($file_count && $document_count) {
        flashAlert("Moved <strong>$file_count</strong> file(s) and <strong>$document_count</strong> document(s) to the folder <strong>$folder_name</strong>");
    } elseif ($file_count) {
        flashAlert("Moved <strong>$file_count</strong> file(s) to the folder <strong>$folder_name</strong>");
    } elseif ($document_count) {
        flashAlert("Moved <strong>$document_count</strong> document(s) to the folder <strong>$folder_name</strong>");
    } else {
        flashAlert("No items were moved.");
    }

    redirect();
}


if (isset($_POST['link_asset_to_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_POST['file_id']);
    $asset_id = intval($_POST['asset_id']);

    $scope = mysqli_fetch_assoc(retentionDbQuery("SELECT file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1",
        'Could not locate the active file relation'));
    if (!$scope) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($scope['file_client_id']);
    enforceClientAccess($client_id);
    try {
        $relation = retentionMutateScopedFileRelation('asset', $asset_id, $file_id, $client_id, true);
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
        redirect();
    }
    $file_name = escapeSql($relation['file_name']);
    $asset_name = escapeSql($relation['target_name']);

    logAudit("File", "Link", "$session_name linked asset $asset_name to file $file_name", $client_id, $file_id);

    flashAlert("Asset <strong>$asset_name</strong> linked to File <strong>$file_name</strong>");

    redirect();

}

if (isset($_GET['unlink_asset_from_file'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $asset_id = intval($_GET['asset_id']);
    $file_id = intval($_GET['file_id']);

    $scope = mysqli_fetch_assoc(retentionDbQuery("SELECT file_client_id FROM files
        WHERE file_id = $file_id AND file_deleted_at IS NULL LIMIT 1",
        'Could not locate the active file relation'));
    if (!$scope) {
        flashAlert('The active file is unavailable.', 'error');
        redirect();
    }
    $client_id = intval($scope['file_client_id']);
    enforceClientAccess($client_id);
    try {
        $relation = retentionMutateScopedFileRelation('asset', $asset_id, $file_id, $client_id, false);
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
        redirect();
    }
    $file_name = escapeSql($relation['file_name']);
    $asset_name = escapeSql($relation['target_name']);

    logAudit("File", "Link", "$session_name unlinked asset $asset_name from file $file_name", $client_id, $file_id);

    flashAlert("Asset <strong>$asset_name</strong> unlinked from File <strong>$file_name</strong>");

    redirect();

}
