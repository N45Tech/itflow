<?php
// Resolution capture has been retired. Existing client documents and audit records remain.
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';
enforceUserPermission('module_support');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
redirect('/agent/tickets.php');
