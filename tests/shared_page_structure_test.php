<?php

$root = dirname(__DIR__);
$loader = file_get_contents($root . '/functions.php');
$ui = file_get_contents($root . '/functions/ui.php');
$theme = file_get_contents($root . '/css/n45_theme.css');
$empty_state = file_get_contents($root . '/includes/inc_empty_state.php');
$filter_footer = file_get_contents($root . '/includes/filter_footer.php');

$failures = array();
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};
$assertOrder = static function (string $first, string $second, string $haystack, string $message) use (&$failures): void {
    $first_position = strpos($haystack, $first);
    $second_position = strpos($haystack, $second);
    if ($first_position === false || $second_position === false || $first_position >= $second_position) {
        $failures[] = $message;
    }
};

$assertOrder(
    "functions/sanitize.php",
    "functions/ui.php",
    $loader,
    'The UI helpers must load after HTML escaping is available'
);
$assertOrder(
    "functions/ui.php",
    "functions/format.php",
    $loader,
    'The UI helper load order changed unexpectedly'
);

foreach (array(
    'n45RenderPageHeader',
    'n45RenderPageTabs',
    'n45RenderPageActions',
    'n45RenderClientContextIndicator',
    'n45RenderStatusBadge',
    'n45RenderEmptyState',
    'n45RenderModalHeader',
) as $helper) {
    $assertContains('function ' . $helper . '(', $ui, "Missing shared UI helper $helper");
}

$assertContains("strpos(\$name, 'aria-') === 0", $ui, 'Shared actions do not allow accessible attributes');
$assertContains("strpos(\$name, 'data-') === 0", $ui, 'Shared actions do not support Bootstrap and modal data attributes');
$assertContains("preg_match('/^[A-Za-z0-9_-]+\$/', \$token)", $ui, 'Shared UI class names are not constrained');
$assertContains('escapeHtml($action[\'href\'] ?? \'#\')', $ui, 'Shared action links are not escaped');

$workspace_pages = array(
    'agent/assets.php' => 'assets-page-title',
    'agent/contacts.php' => 'contacts-page-title',
    'agent/credentials.php' => 'credentials-page-title',
    'agent/projects.php' => 'projects-page-title',
    'agent/networks.php' => 'networks-page-title',
    'agent/racks.php' => 'racks-page-title',
);
foreach ($workspace_pages as $path => $heading_id) {
    $page = file_get_contents($root . '/' . $path);
    $assertContains('n45RenderPageHeader(array(', $page, "$path does not use the shared page header");
    $assertContains('class="card n45-workspace"', $page, "$path does not use the shared workspace shell");
    $assertContains("'title_id' => '$heading_id'", $page, "$path does not expose its page heading");
    $assertNotContains('class="card-header bg-dark py-2"', $page, "$path still carries its legacy one-off header");
}

foreach (array('agent/assets.php', 'agent/contacts.php', 'agent/credentials.php', 'agent/projects.php', 'agent/networks.php') as $path) {
    $page = file_get_contents($root . '/' . $path);
    $assertContains('class="card-header n45-filter-bar"', $page, "$path does not use the shared filter band");
    $assertContains('n45-data-table', $page, "$path does not identify its primary data table");
}

$invoices = file_get_contents($root . '/agent/invoices.php');
$assertContains("'title_id' => 'invoices-page-title'", $invoices, 'Invoices do not use the page-lead structure');
$assertContains('class="card-header n45-filter-bar"', $invoices, 'Invoices do not use the shared filter band');
$assertContains('n45-data-table', $invoices, 'Invoices do not identify their primary data table');

$project = file_get_contents($root . '/agent/project.php');
$assertContains("'breadcrumbs' => array(", $project, 'Project detail does not use shared breadcrumbs');
$assertContains("'badges' => array(", $project, 'Project detail does not use shared status badges');
$assertContains("'context' => \$client_id ? array(", $project, 'Project detail does not expose client context');
$assertNotContains('project_link_closed_ticket.php?<?=', $project, 'Project detail retained the malformed closed-ticket link');

$search = file_get_contents($root . '/agent/global_search.php');
$assertContains("'title_id' => 'global-search-heading'", $search, 'Global search does not use the shared page lead');
$assertContains("'title' => 'No matching records'", $search, 'Global search has no explicit zero-result state');
$assertContains("'title' => 'Find a record'", $search, 'Global search has no initial state');

$asset_modal = file_get_contents($root . '/agent/modals/asset/asset_add.php');
$assertContains('n45RenderModalHeader(', $asset_modal, 'The representative create form does not use the shared modal header');
$assertContains('n45RenderEmptyState(array(', $empty_state, 'Listing pages do not use the shared empty-state component');
$assertContains('n45-result-summary', $filter_footer, 'The shared result footer has no live result summary');

foreach (array(
    '.n45-page-actions {',
    '.n45-client-context {',
    '.n45-status-badge {',
    '.n45-result-footer {',
    '.n45-modal-header {',
    '.n45-search-results .card {',
) as $selector) {
    $assertContains($selector, $theme, "The shared theme is missing $selector");
}

require_once $root . '/functions/sanitize.php';
require_once $root . '/functions/ui.php';
ob_start();
n45RenderPageHeader(array(
    'variant' => 'workspace',
    'title' => '<Unsafe>',
    'title_id' => 'safe-heading',
    'tabs' => array(array('label' => '<Tab>', 'href' => '?q="bad"', 'active' => true)),
    'actions' => array(array(
        'type' => 'button',
        'label' => '<Create>',
        'class' => 'ajax-modal onclick=bad',
        'attributes' => array('data-modal-url' => 'modal.php?q="bad"', 'onclick' => 'bad()'),
    )),
));
$rendered = ob_get_clean();
$assertContains('&lt;Unsafe&gt;', $rendered, 'Shared page titles are not escaped');
$assertContains('&lt;Tab&gt;', $rendered, 'Shared tab labels are not escaped');
$assertContains('aria-current="page"', $rendered, 'Shared tabs do not expose the active state');
$assertContains('data-modal-url="modal.php?q=&quot;bad&quot;"', $rendered, 'Shared data attributes are not escaped');
$assertNotContains('onclick=', $rendered, 'Shared actions allow arbitrary event attributes');
$assertNotContains('onclick=bad', $rendered, 'Shared action classes allow attribute injection');

if ($failures) {
    fwrite(STDERR, "Shared page structure test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Shared page structure test passed\n";
