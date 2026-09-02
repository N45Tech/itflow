<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Parse info
require_once 'document_model.php';

// Default
$insert_id = false;

if (!empty($name) && !(empty($content))) {
    $staging_batch = fileStagingBatchToken();
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the API document transaction');
        }
        if (!documentationLockClient($client_id)) {
            throw new RuntimeException('The API document client is unavailable');
        }
        if (!mysqli_query($mysqli,"INSERT INTO documents SET document_name = '$name', document_description = '$description', document_content = '', document_content_raw = '$content_raw', document_folder_id = $folder, document_created_by = 0, document_client_id = $client_id")) {
            throw new RuntimeException('Could not create the API document');
        }
        $insert_id = intval(mysqli_insert_id($mysqli));
        $processed_content = mysqli_escape_string(
            $mysqli,
            saveBase64Images(
                $content,
                $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/",
                "uploads/documents/",
                $insert_id,
                $staging_batch,
                'document'
            )
        );
        if (!mysqli_query($mysqli,"UPDATE documents SET document_content = '$processed_content' WHERE document_id = $insert_id")) {
            throw new RuntimeException('Could not save API document content');
        }
        if (!logAudit("Document", "Create", "$name via API ($api_key_name)", $client_id, $insert_id)
            || !logAudit("API", "Success", "Created document $name via API ($api_key_name)", $client_id)) {
            throw new RuntimeException('Could not audit API document creation');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit API document creation');
        }
        fileStagingFinalizeBatch($staging_batch);
    } catch (Throwable $error) {
        mysqli_rollback($mysqli);
        $insert_id = false;
        logApp('API', 'error', 'Document creation failed safely: ' . $error->getMessage());
    }
}

// Output
require_once '../create_output.php';
