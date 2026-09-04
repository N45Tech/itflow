<?php

// Doc Templates

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_document_template'])) {

    validateCSRFToken();

    $name = escapeSql($_POST['name']);
    $description = escapeSql($_POST['description']);
    $staging_batch = fileStagingBatchToken();
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document-template transaction');
        }
        if (!mysqli_query($mysqli,"INSERT INTO document_templates SET document_template_name = '$name', document_template_description = '$description', document_template_content = '', document_template_created_by = $session_user_id")) {
            throw new RuntimeException('Could not create the document template');
        }
        $document_template_id = intval(mysqli_insert_id($mysqli));
        $processed_content = mysqli_escape_string(
            $mysqli,
            saveBase64Images(
                $_POST['content'],
                $_SERVER['DOCUMENT_ROOT'] . "/uploads/document_templates/",
                "uploads/document_templates/",
                $document_template_id,
                $staging_batch,
                'document_template'
            )
        );
        if (!mysqli_query($mysqli,"UPDATE document_templates SET document_template_content = '$processed_content' WHERE document_template_id = $document_template_id")) {
            throw new RuntimeException('Could not save document-template content');
        }
        if (!logAudit("Document Template", "Create", "$session_name created document template $name", 0, $document_template_id)) {
            throw new RuntimeException('Could not audit document-template creation');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit document-template creation');
        }
    } catch (Throwable $error) {
        mysqli_rollback($mysqli);
        fileStagingDiscardBatch($staging_batch);
        logApp('Document Template', 'error', $error->getMessage());
        flashAlert('The document template could not be created.', 'error');
        redirect();
    }
    fileStagingFinalizeCommittedBatch($staging_batch, "Document template $document_template_id creation");

    flashAlert("Document template <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_document_template'])) {

    validateCSRFToken();

    $document_template_id = intval($_POST['document_template_id']);
    $name = escapeSql($_POST['name']);
    $description = escapeSql($_POST['description']);
    $staging_batch = fileStagingBatchToken();
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document-template edit transaction');
        }
        $locked_template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_template_id
            FROM document_templates WHERE document_template_id = $document_template_id
            LIMIT 1 FOR UPDATE"));
        if (!$locked_template) {
            throw new RuntimeException('The document template no longer exists');
        }
        $processed_content = saveBase64Images(
            $_POST['content'],
            $_SERVER['DOCUMENT_ROOT'] . "/uploads/document_templates/",
            "uploads/document_templates/",
            $document_template_id,
            $staging_batch,
            'document_template'
        );
        $processed_content_escaped = mysqli_escape_string($mysqli, $processed_content);
        if (!mysqli_query($mysqli,"UPDATE document_templates SET document_template_name = '$name', document_template_description = '$description', document_template_content = '$processed_content_escaped', document_template_updated_by = $session_user_id WHERE document_template_id = $document_template_id")) {
            throw new RuntimeException('Could not update the document template');
        }
        if (!logAudit("Document Template", "Edit", "$session_name edited document template $name", 0, $document_template_id)) {
            throw new RuntimeException('Could not audit the document-template edit');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the document-template edit');
        }
    } catch (Throwable $error) {
        mysqli_rollback($mysqli);
        fileStagingDiscardBatch($staging_batch);
        logApp('Document Template', 'error', $error->getMessage());
        flashAlert('The document template could not be edited.', 'error');
        redirect();
    }
    if (fileStagingFinalizeCommittedBatch($staging_batch, "Document template $document_template_id update")) {
        cleanupUnusedImages(
            $processed_content,
            $_SERVER['DOCUMENT_ROOT'] . "/uploads/document_templates/" . $document_template_id,
            "/uploads/document_templates/" . $document_template_id
        );
    }

    flashAlert("Document Template <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['delete_document_template'])) {

    validateCSRFToken();

    $document_template_id = intval($_GET['delete_document_template']);

    $document_template_name = escapeSql(getFieldById('document_templates', $document_template_id, 'document_template_name'));

    mysqli_query($mysqli,"DELETE FROM document_templates WHERE document_template_id = $document_template_id");

    // Delete uploads/document_templates/$document_template_id if exists
    removeDirectory($_SERVER['DOCUMENT_ROOT'] . "/uploads/document_templates/" . $document_template_id);

    logAudit("Document Template", "Delete", "$session_name deleted document template $document_template_name");

    flashAlert("Document Template <strong>$document_template_name</strong> deleted", 'error');

    redirect();

}
