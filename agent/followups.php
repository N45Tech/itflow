<?php
// Keep old notification links useful; follow-ups now live on tickets.
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/check_login.php';
enforceUserPermission('module_support');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
if (!empty($_GET['ticket_id'])) { redirect('/agent/ticket.php?ticket_id=' . (int) $_GET['ticket_id'] . '#followups'); }
redirect('/agent/tickets.php?queue=followups' . (!empty($_GET['client_id']) ? '&client_id=' . (int) $_GET['client_id'] : ''));
