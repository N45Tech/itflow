<?php

// Compatibility endpoint for cached links from the former task-only approval
// manager. The shared modal preserves the task target while using the simpler
// route and re-request layout.
$_GET['target'] = 'task';
require __DIR__ . '/ticket_approval_manage.php';
