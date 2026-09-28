<?php

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';

$document_id = intval($_GET['document_id'] ?? 0);
$filename = (string) ($_GET['file'] ?? '');

enforceUserPermission('module_support');
$sql = mysqli_query($mysqli, "SELECT document_client_id FROM documents WHERE document_id = $document_id LIMIT 1");
$document = $sql ? mysqli_fetch_assoc($sql) : null;
if (!$document) {
    http_response_code(404);
    exit('Image not found');
}
$client_id = intval($document['document_client_id']);
enforceClientAccess();

streamDocumentImage($document_id, $filename);
