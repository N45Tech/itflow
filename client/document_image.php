<?php

require_once '../config.php';
require_once '../includes/load_global_settings.php';
require_once '../functions.php';
require_once 'includes/check_login.php';
require_once 'functions.php';

if (!contactCan('itdoc')) {
    http_response_code(404);
    exit('Image not found');
}

$document_id = intval($_GET['document_id'] ?? 0);
$filename = (string) ($_GET['file'] ?? '');
$sql = mysqli_query($mysqli, "SELECT document_id FROM documents
    WHERE document_id = $document_id AND document_client_id = $session_client_id
    AND document_client_visible = 1 AND document_archived_at IS NULL LIMIT 1");
if (!$sql || mysqli_num_rows($sql) !== 1) {
    http_response_code(404);
    exit('Image not found');
}

streamDocumentImage($document_id, $filename);
