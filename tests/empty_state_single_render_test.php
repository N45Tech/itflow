<?php

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

require_once dirname(__DIR__) . '/functions/ui.php';

ob_start();
n45RenderEmptyState(array('title' => 'No users match this search'));
$num_rows = array(0);
require dirname(__DIR__) . '/includes/inc_empty_state.php';
$explicit_output = ob_get_clean();

unset($GLOBALS['n45_explicit_empty_state']);
$_GET = array();
$_SERVER['REQUEST_URI'] = '/admin/users.php';
$page_title = 'Users';
ob_start();
require dirname(__DIR__) . '/includes/inc_empty_state.php';
$generic_output = ob_get_clean();

if (substr_count($explicit_output, 'class="n45-empty-state') !== 1
    || !str_contains($explicit_output, 'No users match this search')
    || substr_count($generic_output, 'class="n45-empty-state') !== 1
    || !str_contains($generic_output, 'No users yet.')) {
    fwrite(STDERR, "Empty-state single-render contract failed\n");
    exit(1);
}

echo "Empty-state single-render contract passed\n";
