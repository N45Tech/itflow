<?php

require_once '../validate_api_key.php';
require_once '../require_post_method.php';

// Parse ID
$document_id = intval($_POST['document_id'] ?? 0);

// Default
$update_count = false;

if (!empty($document_id)) {
    $staging_batch = fileStagingBatchToken();
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the API document edit transaction');
        }
        if (!documentationLockClient($client_id)) {
            throw new RuntimeException('The API document client is unavailable');
        }
        $sql_original_document = mysqli_query(
            $mysqli,
            "SELECT * FROM documents WHERE document_client_id = $client_id
             AND document_id = $document_id LIMIT 1 FOR UPDATE"
        );
        if (!$sql_original_document || mysqli_num_rows($sql_original_document) !== 1) {
            throw new AutomationConflictException('Document not found in the authorized client');
        }
        $document_row = mysqli_fetch_assoc($sql_original_document);
        $original_document_name = escapeSql($document_row['document_name']);
        $original_document_description = escapeSql($document_row['document_description']);
        $original_document_content = mysqli_real_escape_string($mysqli, $document_row['document_content']);
        $original_document_created_by = intval($document_row['document_created_by']);
        $original_document_updated_by = intval($document_row['document_updated_by']);
        $original_document_created_at = escapeSql($document_row['document_created_at']);
        $original_document_updated_at = escapeSql($document_row['document_updated_at']);
        $document_version_created_at = $original_document_updated_at ?: $original_document_created_at;
        $document_version_created_by = $original_document_updated_by ?: $original_document_created_by;

        require_once 'document_model.php';
        $processed_html = saveBase64Images(
            $content,
            $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/",
            "uploads/documents/",
            $document_id,
            $staging_batch,
            'document'
        );
        $content_db = mysqli_real_escape_string($mysqli, $processed_html);
        $content_raw = escapeSql($name . " " . str_replace("<", " <", $processed_html));
        $content_raw = mysqli_real_escape_string($mysqli, $content_raw);
        $name_db = mysqli_real_escape_string($mysqli, $name);
        $description_db = mysqli_real_escape_string($mysqli, $description);
        $folder_id = intval($folder);

        if (!mysqli_query($mysqli, "INSERT INTO document_versions SET
            document_version_name = '$original_document_name',
            document_version_description = '$original_document_description',
            document_version_content = '$original_document_content',
            document_version_created_by = $document_version_created_by,
            document_version_created_at = '$document_version_created_at',
            document_version_document_id = $document_id")) {
            throw new RuntimeException('Could not preserve the prior API document version');
        }
        $document_version_id = intval(mysqli_insert_id($mysqli));
        if (!mysqli_query($mysqli, "UPDATE documents SET
            document_name = '$name_db', document_description = '$description_db',
            document_content = '$content_db', document_content_raw = '$content_raw',
            document_folder_id = $folder_id, document_updated_by = 0
            WHERE document_id = $document_id AND document_client_id = $client_id LIMIT 1")) {
            throw new RuntimeException('Could not update the API document');
        }
        if (!logAudit("Document", "Edit", "$name_db via API ($api_key_name), previous version kept", $client_id, $document_version_id)
            || !logAudit("API", "Success", "Edited document $name_db via API ($api_key_name)", $client_id)) {
            throw new RuntimeException('Could not audit the API document edit');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the API document edit');
        }
        $update_count = 1;
        fileStagingFinalizeBatch($staging_batch);
        cleanupUnusedImages(
            $processed_html,
            $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/" . $document_id,
            "/uploads/documents/" . $document_id
        );
    } catch (Throwable $error) {
        mysqli_rollback($mysqli);
        $update_count = false;
        logApp('API', 'error', 'Document update failed safely: ' . $error->getMessage());
        logAudit("API", "Error", "Document update failed (not found or unauthorized) via API ($api_key_name)", $client_id);
    }
}

// Output
require_once '../update_output.php';
