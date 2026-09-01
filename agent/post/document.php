<?php

/*
 * ITFlow - GET/POST request handler for client documents
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    require_once 'document_model.php';
    $client_id = intval($_POST['client_id']);
    $contact_id = intval($_POST['contact'] ?? 0);
    $asset_id = intval($_POST['asset'] ?? 0);

    enforceClientAccess();

    // Document add query
    mysqli_query($mysqli,"INSERT INTO documents SET document_name = '$name', document_description = '$description', document_content = '', document_content_raw = '$content_raw', document_folder_id = $folder, document_created_by = $session_user_id, document_client_id = $client_id");

    $document_id = mysqli_insert_id($mysqli);

    $processed_content = mysqli_escape_string(
        $mysqli,
        saveBase64Images(
            $_POST['content'],
            $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/",
            "uploads/documents/",
            $document_id
        )
    );

    // Document update content
    mysqli_query($mysqli,"UPDATE documents SET document_content = '$processed_content' WHERE document_id = $document_id");

    if ($contact_id) {
        mysqli_query($mysqli,"INSERT INTO contact_documents SET contact_id = $contact_id, document_id = $document_id");
    }

    if ($asset_id) {
        mysqli_query($mysqli,"INSERT INTO asset_documents SET asset_id = $asset_id, document_id = $document_id");
    }

    logAudit("Document", "Create", "$session_name created document $name", $client_id, $document_id);

    flashAlert("Document <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['add_document_from_template'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $client_id             = intval($_POST['client_id']);
    $document_name         = escapeSql($_POST['name']);
    $document_description  = escapeSql($_POST['description']);
    $document_template_id  = intval($_POST['document_template_id']);
    $folder                = intval($_POST['folder']);

    enforceClientAccess();

    // Get template
    $sql_document = mysqli_query(
        $mysqli,
        "SELECT document_template_content, document_template_name FROM document_templates
         WHERE document_template_id = $document_template_id"
    );

    $row = mysqli_fetch_assoc($sql_document);

    $document_template_name = escapeSql($row['document_template_name']);
    $template_content_html  = $row['document_template_content']; // raw HTML from template

    // 1) Create the new document with placeholder content to get an ID
    mysqli_query(
        $mysqli,
        "INSERT INTO documents SET
            document_name        = '$document_name',
            document_description = '$document_description',
            document_content     = '',
            document_content_raw = '',
            document_folder_id   = $folder,
            document_created_by  = $session_user_id,
            document_client_id   = $client_id"
    );

    $document_id = mysqli_insert_id($mysqli);

    // 2) Copy template images to the document's folder
    $templateFsPath = $_SERVER['DOCUMENT_ROOT'] . "/uploads/document_templates/" . $document_template_id;
    $documentFsPath = $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/" . $document_id;

    copyDirectory($templateFsPath, $documentFsPath);

    // 3) Rewrite image paths in the HTML
    //    /uploads/document_templates/{template_id}/ -> /uploads/documents/{document_id}/
    $oldPath = "/uploads/document_templates/" . $document_template_id . "/";
    $newPath = "/uploads/documents/" . $document_id . "/";

    $processed_html = str_replace($oldPath, $newPath, $template_content_html);

    // 4) Prepare content + content_raw
    $content = mysqli_real_escape_string($mysqli, $processed_html);

    $content_raw = escapeSql(
        $document_name . " " . str_replace("<", " <", $processed_html)
    );
    $content_raw = mysqli_real_escape_string($mysqli, $content_raw);

    // 5) Update the document with final content
    mysqli_query(
        $mysqli,
        "UPDATE documents SET
            document_content     = '$content',
            document_content_raw = '$content_raw'
         WHERE document_id = $document_id"
    );

    logAudit(
        "Document",
        "Create",
        "$session_name created document $document_name from template $document_template_name",
        $client_id,
        $document_id
    );

    flashAlert("Document <strong>$document_name</strong> created from template");

    redirect("document.php?client_id=$client_id&document_id=$document_id");
}

if (isset($_POST['edit_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    require_once 'document_model.php';

    $document_id = intval($_POST['document_id']);

    $client_id = intval(getFieldById('documents', $document_id, 'document_client_id'));

    enforceClientAccess();

    // Process the new content before taking lifecycle locks. Database state and
    // verification invalidation still commit atomically below.
    //    - convert base64 <img> tags to files under /uploads/documents/<document_id>/
    //    - rewrite <img src> to file URLs
    $raw_post_content = $_POST['content'];

    $processed_html = saveBase64Images(
        $raw_post_content,
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/",
        "uploads/documents/",
        $document_id
    );

    // Escape for DB
    $content = mysqli_real_escape_string($mysqli, $processed_html);

    // Rebuild content_raw for full-text search
    $content_raw = escapeSql(
        $name . " " . str_replace("<", " <", $processed_html)
    );
    $content_raw = mysqli_real_escape_string($mysqli, $content_raw);

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document edit transaction');
        }
        $transaction_started = true;

        // Core owns client -> obligation -> document lock ordering and does not
        // commit the caller's transaction.
        documentationInvalidateDocumentLocked(
            $document_id,
            $client_id,
            $session_user_id,
            'document_changed'
        );
        $row = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
            document_content, document_created_at, document_created_by,
            document_description, document_name, document_updated_at,
            document_updated_by, document_client_id
            FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE",
            'Could not lock the document for editing'));
        if (!$row || intval($row['document_client_id']) !== $client_id) {
            throw new RuntimeException('The document client changed before it could be edited');
        }

        $original_document_name = escapeSql($row['document_name']);
        $original_document_description = escapeSql($row['document_description']);
        $original_document_content = mysqli_real_escape_string($mysqli, $row['document_content']);
        $original_document_created_by = intval($row['document_created_by']);
        $original_document_updated_by = intval($row['document_updated_by']);
        $original_document_created_at = escapeSql($row['document_created_at']);
        $original_document_updated_at = escapeSql($row['document_updated_at']);
        $document_version_created_at = $original_document_updated_at ?: $original_document_created_at;
        $document_version_created_by = $original_document_updated_by ?: $original_document_created_by;

        documentationLifecycleDbQuery("INSERT INTO document_versions SET
            document_version_name = '$original_document_name',
            document_version_description = '$original_document_description',
            document_version_content = '$original_document_content',
            document_version_created_by = $document_version_created_by,
            document_version_created_at = '$document_version_created_at',
            document_version_document_id = $document_id",
            'Could not preserve the previous document version');
        $document_version_id = intval(mysqli_insert_id($mysqli));

        documentationLifecycleDbQuery("UPDATE documents SET
            document_name = '$name', document_description = '$description',
            document_content = '$content', document_content_raw = '$content_raw',
            document_updated_by = $session_user_id
            WHERE document_id = $document_id AND document_client_id = $client_id LIMIT 1",
            'Could not update the document');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The document changed before the edit could be committed');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the document edit');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Document $document_id edit failed safely: " . $e->getMessage());
        flashAlert('The document could not be edited. Refresh and try again.', 'error');
        redirect("document.php?client_id=$client_id&document_id=$document_id");
    }

    logAudit(
        "Document",
        "Edit",
        "$session_name edited document $name, previous version kept",
        $client_id,
        $document_version_id
    );

    flashAlert("Document <strong>$name</strong> edited, previous version kept");

    redirect("document.php?client_id=$client_id&document_id=$document_id");

}

if (isset($_POST['move_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $folder_id = intval($_POST['folder']);

    // Get Document Name Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Folder Name for logging
    $sql_folder = mysqli_query($mysqli,"SELECT folder_name FROM folders WHERE folder_id = $folder_id");
    $row = mysqli_fetch_assoc($sql_folder);
    $folder_name = escapeSql($row['folder_name']);

    // Document edit query
    mysqli_query($mysqli,"UPDATE documents SET document_folder_id = $folder_id, document_updated_at = document_updated_at WHERE document_id = $document_id");

    logAudit("Document", "Move", "$session_name moved document $document_name to folder $folder_name", $client_id, $document_id);

    flashAlert("Document <strong>$document_name</strong> moved to folder <strong>$folder_name</strong>");

    redirect();

}

if (isset($_POST['rename_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $name = escapeSql($_POST['name']);

    $client_id = intval(getFieldById('documents', $document_id, 'document_client_id'));

    enforceClientAccess();

    // Get Document Name before renaming for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $old_document_name = escapeSql($row['document_name']);

    // Document edit query
    mysqli_query($mysqli,"UPDATE documents SET document_name = '$name', document_updated_at = document_updated_at WHERE document_id = $document_id");

    logAudit("Document", "Edit", "$session_name renamed document $old_document_name to $name", $client_id, $document_id);


    flashAlert("You renamed Document from <strong>$old_document_name</strong> to <strong>$name</strong>");

    redirect();

}

if (isset($_POST['bulk_move_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $folder_id = intval($_POST['bulk_folder_id']);

    // Get folder name for logging and Notification
    $sql = mysqli_query($mysqli,"SELECT folder_name, folder_client_id FROM folders WHERE folder_id = $folder_id");
    $row = mysqli_fetch_assoc($sql);
    $folder_name = escapeSql($row['folder_name']);
    $client_id = intval($row['folder_client_id']);

    enforceClientAccess();

    // Move Documents to Folder Loop
    if (isset($_POST['document_ids'])) {

        // Get Selected Count
        $count = count($_POST['document_ids']);

        foreach($_POST['document_ids'] as $document_id) {
            $document_id = intval($document_id);
            // Get document name for logging
            $document_name = escapeSql(getFieldById('documents', $document_id, 'document_name'));

            // Document move query
            mysqli_query($mysqli,"UPDATE documents SET document_folder_id = $folder_id, document_updated_at = document_updated_at WHERE document_id = $document_id");

            logAudit("Document", "Move", "$session_name moved document $document_name to folder $folder_name", $client_id, $document_id);
        }

        logAudit("Document", "Bulk Move", "$session_name moved $count document(s) to folder $folder_name", $client_id);
    }

    flashAlert("You moved <strong>$count</strong> document(s) to the folder <strong>$folder_name</strong>");

    redirect();

}

if (isset($_POST['link_file_to_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $file_id = intval($_POST['file_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get File Name for logging
    $file_name = escapeSql(getFieldById('files', $file_id, 'file_name'));

    // Document add query
    mysqli_query($mysqli,"INSERT INTO document_files SET file_id = $file_id, document_id = $document_id");

    logAudit("Document", "Link", "$session_name linked file $file_name to document $document_name", $client_id, $document_id);

    flashAlert("File <strong>$file_name</strong> linked with Document <strong>$document_name</strong>");

    redirect();

}

if (isset($_GET['unlink_file_from_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $file_id = intval($_GET['file_id']);
    $document_id = intval($_GET['document_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get File Name for logging
    $file_name = escapeSql(getFieldById('files', $file_id, 'file_name'));

    mysqli_query($mysqli,"DELETE FROM document_files WHERE file_id = $file_id AND document_id = $document_id");

    logAudit("Document", "Unlink", "$session_name unlinked file $file_name from document $document_name", $client_id, $document_id);

    flashAlert("File <strong>$file_name</strong> unlinked from Document <strong>$document_name</strong>", 'error');

    redirect();

}

if (isset($_POST['link_vendor_to_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $vendor_id = intval($_POST['vendor_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Vendor Name for logging
    $vendor_name = escapeSql(getFieldById('vendors', $vendor_id, 'vendor_name'));

    // Document add query
    mysqli_query($mysqli,"INSERT INTO vendor_documents SET vendor_id = $vendor_id, document_id = $document_id");

    logAudit("Document", "Link", "$session_name linked vendor $vendor_name to document $document_name", $client_id, $document_id);

    flashAlert("Vendor <strong>$vendor_name</strong> linked with Document <strong>$document_name</strong>");

    redirect();

}

if (isset($_GET['unlink_vendor_from_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $vendor_id = intval($_GET['vendor_id']);
    $document_id = intval($_GET['document_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Vendor Name for logging
    $vendor_name = escapeSql(getFieldById('vendors', $vendor_id, 'vendor_name'));

    mysqli_query($mysqli,"DELETE FROM vendor_documents WHERE vendor_id = $vendor_id AND document_id = $document_id");

    logAudit("Document", "Unlink", "$session_name unlinked vendor $vendor_name from document $document_name", $client_id, $document_id);

    flashAlert("Vendor <strong>$vendor_name</strong> unlinked from Document <strong>$document_name</strong>", 'error');

    redirect();

}

if (isset($_POST['link_contact_to_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    $document_id = intval($_POST['document_id']);
    $contact_id = intval($_POST['contact_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Contact Name for logging
    $contact_name = escapeSql(getFieldById('contacts', $contact_id, 'contact_name'));

    // Contact add query
    mysqli_query($mysqli,"INSERT INTO contact_documents SET contact_id = $contact_id, document_id = $document_id");

    logAudit("Document", "Link", "$session_name linked contact $contact_name to document $document_name", $client_id, $document_id);

    flashAlert("Contact <strong>$contact_name</strong> linked with Document <strong>$document_name</strong>");

    redirect();

}

if (isset($_GET['unlink_contact_from_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $contact_id = intval($_GET['contact_id']);
    $document_id = intval($_GET['document_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Contact Name for logging
    $contact_name = escapeSql(getFieldById('contacts', $contact_id, 'contact_name'));

    mysqli_query($mysqli,"DELETE FROM contact_documents WHERE contact_id = $contact_id AND document_id = $document_id");

    logAudit("Document", "Unlink", "$session_name unlinked contact $contact_name from document $document_name", $client_id, $document_id);

    flashAlert("Contact <strong>$contact_name</strong> unlinked from Document <strong>$document_name</strong>", 'error');

    redirect();

}

if (isset($_POST['link_asset_to_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $asset_id = intval($_POST['asset_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Asset Name for logging
    $asset_name = escapeSql(getFieldById('assets', $asset_id, 'asset_name'));

    mysqli_query($mysqli,"INSERT INTO asset_documents SET asset_id = $asset_id, document_id = $document_id");

    logAudit("Document", "Link", "$session_name linked asset $asset_name to document $document_name", $client_id, $document_id);

    flashAlert("Asset <strong>$asset_name</strong> linked with Document <strong>$document_name</strong>");

    redirect();

}

if (isset($_GET['unlink_asset_from_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $asset_id = intval($_GET['asset_id']);
    $document_id = intval($_GET['document_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Asset Name for logging
    $asset_name = escapeSql(getFieldById('assets', $asset_id, 'asset_name'));

    mysqli_query($mysqli,"DELETE FROM asset_documents WHERE asset_id = $asset_id AND document_id = $document_id");

    logAudit("Document", "Unlink", "$session_name unlinked asset $asset_name from document $document_name", $client_id, $document_id);

    flashAlert("Asset <strong>$asset_name</strong> unlinked from Document <strong>$document_name</strong>", 'error');

    redirect();

}

if (isset($_POST['link_software_to_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $software_id = intval($_POST['software_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Software Name for logging
    $software_name = escapeSql(getFieldById('software', $software_id, 'software_name'));

    // Contact add query
    mysqli_query($mysqli,"INSERT INTO software_documents SET software_id = $software_id, document_id = $document_id");

    logAudit("Document", "Link", "$session_name linked software $software_name to document $document_name", $client_id, $document_id);

    flashAlert("Software <strong>$software_name</strong> linked with Document <strong>$document_name</strong>");

    redirect();

}

if (isset($_GET['unlink_software_from_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $software_id = intval($_GET['software_id']);
    $document_id = intval($_GET['document_id']);

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Get Software Name for logging
    $software_name = escapeSql(getFieldById('software', $software_id, 'software_name'));

    mysqli_query($mysqli,"DELETE FROM software_documents WHERE software_id = $software_id AND document_id = $document_id");

    logAudit("Document", "Unlink", "$session_name unlinked software $software_name from document $document_name", $client_id, $document_id);

    flashAlert("Software <strong>$software_name</strong> unlinked from Document <strong>$document_name</strong>", 'error');

    redirect();

}

if (isset($_POST['toggle_document_visibility'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_POST['document_id']);
    $document_visible = intval($_POST['document_visible']);

    if ($document_visible == 0) {
        $visable_wording = "Invisable";
    } else {
        $visable_wording = "Visable";
    }

    // Get Document Name and Client ID for logging
    $sql_document = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql_document);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE documents SET document_client_visible = $document_visible, document_updated_at = document_updated_at WHERE document_id = $document_id");

    logAudit("Document", "Edit", "$session_name changed document $document_name visibilty to $visable_wording in the client portal", $client_id, $document_id);

    flashAlert("Document <strong>$document_name</strong> changed to <strong>$visable_wording</strong> in the client portal");

    redirect();

}

if (isset($_GET['export_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_GET['export_document']);

    // Get Contact Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT document_name, document_content, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql);
    $document_name = escapeSql($row['document_name']);
    $document_content = $row['document_content'];
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    // Include the TCPDF class
    require_once('../libs/TCPDF/tcpdf.php');

    $pdf = new TCPDF();

    // Set document information
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor("$document_name");
    $pdf->SetTitle("$document_name");

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // Write HTML content to the PDF
    $pdf->writeHTML($document_content, true, false, true, false, '');

    // Output PDF to browser
    $pdf->Output("$document_name.pdf", 'I'); // 'I' for inline display, 'D' for download

    // Logging
    logAudit("Document", "Export", "$session_name exported document $document_name", $client_id, $document_id);

    flashAlert("Document <strong>$document_name</strong> exported");

    redirect();

}

if (isset($_GET['archive_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_GET['archive_document']);

    // Get Contact Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document archival transaction');
        }
        $transaction_started = true;
        documentationInvalidateDocumentLocked(
            $document_id,
            $client_id,
            $session_user_id,
            'document_archived'
        );
        $locked_document = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT document_client_id,
            document_archived_at FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE",
            'Could not lock the document for archival'));
        if (!$locked_document || intval($locked_document['document_client_id']) !== $client_id) {
            throw new RuntimeException('The document client changed before it could be archived');
        }
        if (documentationDocumentHasObligations($document_id)) {
            throw new DomainException('The document is a canonical documentation record');
        }
        if (documentationEvidenceReferenceInUse('document', $document_id, $client_id)) {
            throw new DomainException('The document is retained in the Evidence Locker');
        }
        $version_references = documentationLifecycleDbQuery("SELECT document_version_id
            FROM document_versions WHERE document_version_document_id = $document_id
            ORDER BY document_version_id FOR UPDATE", 'Could not inspect document version evidence');
        while ($version_reference = mysqli_fetch_assoc($version_references)) {
            if (documentationEvidenceReferenceInUse(
                'document-version',
                intval($version_reference['document_version_id']),
                $client_id
            )) {
                throw new DomainException('A document version is retained in the Evidence Locker');
            }
        }

        documentationLifecycleDbQuery("UPDATE documents SET document_archived_at = NOW(),
            document_updated_at = document_updated_at WHERE document_id = $document_id
            AND document_archived_at IS NULL LIMIT 1", 'Could not archive the document');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The document changed before archival');
        }
        foreach (['document_files', 'contact_documents', 'asset_documents', 'software_documents', 'vendor_documents', 'service_documents'] as $association_table) {
            documentationLifecycleDbQuery("DELETE FROM $association_table WHERE document_id = $document_id",
                'Could not remove a document association');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the document archival');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Document $document_id archival failed safely: " . $e->getMessage());
        flashAlert($e instanceof DomainException
            ? 'This document or one of its versions is retained by documentation obligations or verification evidence and cannot be archived.'
            : 'The document could not be archived. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("Document", "Archive", "$session_name archived document $document_name", $client_id, $document_id);

    flashAlert("Document <strong>$document_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 2);

    $document_id = intval($_GET['restore_document']);

    // Get Document Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql);
    $document_name = escapeSql($row['document_name']);
    $client_id = intval($row['document_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE documents SET document_archived_at = NULL, document_updated_at = document_updated_at WHERE document_id = $document_id");

    logAudit("Document", "Restore", "$session_name restored document $document_name", $client_id, $document_id);

    flashAlert("Document <strong>$document_name</strong> Restored");

    redirect();

}

if (isset($_GET['delete_document_version'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 3);

    $document_version_id = intval($_GET['delete_document_version']);

    // Get Document
    $sql = mysqli_query($mysqli,"SELECT document_version_name, document_client_id,
        document_version_document_id FROM documents, document_versions
        WHERE document_version_document_id = document_id
        AND document_version_id = $document_version_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['document_client_id']);
    $document_id = intval($row['document_version_document_id']);
    $document_version_name = escapeSql($row['document_version_name']);

    enforceClientAccess();

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document version deletion transaction');
        }
        $transaction_started = true;
        documentationLockClient($client_id);
        $locked_document = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT document_client_id
            FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE",
            'Could not lock the document for version deletion'));
        if (!$locked_document || intval($locked_document['document_client_id']) !== $client_id) {
            throw new RuntimeException('The document client changed before version deletion');
        }
        $locked_version = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT
            document_version_document_id FROM document_versions
            WHERE document_version_id = $document_version_id LIMIT 1 FOR UPDATE",
            'Could not lock the document version for deletion'));
        if (!$locked_version
            || intval($locked_version['document_version_document_id']) !== $document_id) {
            throw new RuntimeException('The document version changed before deletion');
        }
        if (documentationDocumentHasObligations($document_id)
            || documentationEvidenceReferenceInUse('document-version', $document_version_id, $client_id)) {
            throw new DomainException('The document version is retained by documentation history');
        }
        documentationLifecycleDbQuery("DELETE FROM document_versions
            WHERE document_version_id = $document_version_id LIMIT 1", 'Could not delete the document version');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The document version changed before deletion');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the document version deletion');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Document version $document_version_id deletion failed safely: " . $e->getMessage());
        flashAlert($e instanceof DomainException
            ? 'This document version is retained by documentation verification history and cannot be deleted.'
            : 'The document version could not be deleted. Refresh and try again.', 'error');
        redirect();
    }

    logAudit("Document Version", "Delete", "$session_name deleted document version $document_version_name", $client_id);

    flashAlert("Document $document_version_name version deleted", 'error');

    redirect();

}

if (isset($_GET['delete_document'])) {

    validateCSRFToken();

    enforceUserPermission('module_support', 3);

    $document_id = intval($_GET['delete_document']);

    // Get Document Name and Client ID for logging
    $sql = mysqli_query($mysqli,"SELECT document_name, document_client_id FROM documents WHERE document_id = $document_id");
    $row = mysqli_fetch_assoc($sql);
    $client_id = intval($row['document_client_id']);
    $document_name = escapeSql($row['document_name']);

    enforceClientAccess();

    $transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the document deletion transaction');
        }
        $transaction_started = true;
        documentationInvalidateDocumentLocked(
            $document_id,
            $client_id,
            $session_user_id,
            'document_deleted'
        );
        $locked_document = mysqli_fetch_assoc(documentationLifecycleDbQuery("SELECT document_client_id
            FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE",
            'Could not lock the document for deletion'));
        if (!$locked_document || intval($locked_document['document_client_id']) !== $client_id) {
            throw new RuntimeException('The document client changed before it could be deleted');
        }
        if (documentationDocumentHasObligations($document_id)) {
            throw new DomainException('The document is a canonical documentation record');
        }
        if (documentationEvidenceReferenceInUse('document', $document_id, $client_id)) {
            throw new DomainException('The document is retained in the Evidence Locker');
        }
        $version_references = documentationLifecycleDbQuery("SELECT document_version_id
            FROM document_versions WHERE document_version_document_id = $document_id
            ORDER BY document_version_id FOR UPDATE", 'Could not inspect document version evidence');
        while ($version_reference = mysqli_fetch_assoc($version_references)) {
            if (documentationEvidenceReferenceInUse(
                'document-version',
                intval($version_reference['document_version_id']),
                $client_id
            )) {
                throw new DomainException('A document version is retained in the Evidence Locker');
            }
        }
        documentationLifecycleDbQuery("DELETE FROM document_versions
            WHERE document_version_document_id = $document_id", 'Could not delete document versions');
        documentationLifecycleDbQuery("DELETE FROM documents WHERE document_id = $document_id LIMIT 1",
            'Could not delete the document');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The document changed before deletion');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the document deletion');
        }
        $transaction_started = false;
    } catch (Throwable $e) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        error_log("Document $document_id deletion failed safely: " . $e->getMessage());
        flashAlert($e instanceof DomainException
            ? 'Canonical documentation and Evidence Locker references cannot be deleted. Retain this record for audit history.'
            : 'The document could not be deleted. Refresh and try again.', 'error');
        redirect();
    }

    // Delete uploads/document/$document_id if exists
    removeDirectory($_SERVER['DOCUMENT_ROOT'] . "/uploads/documents/" . $document_id);

    logAudit("Document", "Delete", "$session_name deleted document $document_name and all versions", $client_id);

    flashAlert("Document <strong>$document_name</strong> deleted and all versions", 'error');

    // Determine redirect behavior
    // If there's a "from" parameter, we can use it to decide where to go
    if (isset($_GET['from']) && $_GET['from'] === 'document_details') {
        // User deleted from document.php
        redirect("files.php?client_id=$client_id");
    } else {
        // Default behavior — redirect back to previous page
        redirect();
    }

}
