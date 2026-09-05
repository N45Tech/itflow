<?php

// Compatibility endpoint for bookmarks and cached ticket markup from before
// ticket and task approval requests shared one plain-language modal.
$_GET['task_id'] = intval($_GET['task_id'] ?? $_GET['id'] ?? 0);
require __DIR__ . '/ticket_approval_request.php';
