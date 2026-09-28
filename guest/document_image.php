<?php

require_once '../config.php';
require_once '../functions.php';

$document_id = intval($_GET['document_id'] ?? 0);
$filename = (string) ($_GET['file'] ?? '');
$item_id = intval($_GET['id'] ?? 0);
$key = mysqli_real_escape_string($mysqli, (string) ($_GET['key'] ?? ''));

$sql = mysqli_query($mysqli, "SELECT item_id FROM shared_items
    WHERE item_id = $item_id AND item_key = '$key' AND item_type = 'Document'
    AND item_related_id = $document_id AND item_active = 1 AND item_expire_at > NOW()
    AND (item_view_limit = 0 OR item_views <= item_view_limit) LIMIT 1");
if (!$sql || mysqli_num_rows($sql) !== 1) {
    http_response_code(404);
    exit('Image not found');
}

streamDocumentImage($document_id, $filename);
