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
    'agent/vendors.php' => 'vendors-page-title',
    'agent/products.php' => 'products-page-title',
    'agent/services.php' => 'services-page-title',
    'agent/software.php' => 'software-page-title',
    'agent/domains.php' => 'domains-page-title',
    'agent/certificates.php' => 'certificates-page-title',
    'agent/notifications.php' => 'notifications-page-title',
    'agent/locations.php' => 'locations-page-title',
    'agent/quotes.php' => 'quotes-page-title',
    'agent/recurring_tickets.php' => 'recurring-tickets-page-title',
    'agent/recurring_invoices.php' => 'recurring-invoices-page-title',
    'agent/recurring_expenses.php' => 'recurring-expenses-page-title',
    'admin/users.php' => 'users-page-title',
);
foreach ($workspace_pages as $path => $heading_id) {
    $page = file_get_contents($root . '/' . $path);
    $assertContains('n45RenderPageHeader(array(', $page, "$path does not use the shared page header");
    $assertContains('class="card n45-workspace"', $page, "$path does not use the shared workspace shell");
    $assertContains("'title_id' => '$heading_id'", $page, "$path does not expose its page heading");
    $assertNotContains('class="card-header bg-dark py-2"', $page, "$path still carries its legacy one-off header");
}

foreach (array(
    'agent/assets.php',
    'agent/contacts.php',
    'agent/credentials.php',
    'agent/projects.php',
    'agent/networks.php',
    'agent/vendors.php',
    'agent/products.php',
    'agent/services.php',
    'agent/software.php',
    'agent/domains.php',
    'agent/certificates.php',
    'agent/notifications.php',
    'agent/locations.php',
    'agent/quotes.php',
    'agent/recurring_tickets.php',
    'agent/recurring_invoices.php',
    'agent/recurring_expenses.php',
    'admin/users.php',
) as $path) {
    $page = file_get_contents($root . '/' . $path);
    $assertContains('class="card-header n45-filter-bar"', $page, "$path does not use the shared filter band");
    $assertContains('n45-data-table', $page, "$path does not identify its primary data table");
}

foreach (array(
    'agent/vendors.php',
    'agent/products.php',
    'agent/services.php',
    'agent/software.php',
    'agent/domains.php',
    'agent/certificates.php',
    'agent/locations.php',
    'agent/quotes.php',
    'agent/recurring_tickets.php',
    'agent/recurring_invoices.php',
    'agent/recurring_expenses.php',
) as $path) {
    $page = file_get_contents($root . '/' . $path);
    $assertContains('n45RenderEmptyState(array(', $page, "$path does not distinguish its zero-result state");
    $assertContains("'label' => 'Clear filters'", $page, "$path does not provide zero-result filter recovery");
}

$products = file_get_contents($root . '/agent/products.php');
$assertContains("'tabs_label' => 'Catalog type'", $products, 'Products and services do not expose their catalog switcher in the shared header');
$assertNotContains('<div class="btn-group me-2">', $products, 'Products retained the duplicate catalog switcher in the filter band');

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

$notifications = file_get_contents($root . '/agent/notifications.php');
$assertContains('n45RenderEmptyState(array(', $notifications, 'Notifications do not expose an explicit zero-result state');
$assertContains('aria-label="Search notifications"', $notifications, 'Notification search is missing an accessible name');
$assertContains('aria-label="Show notification date filters"', $notifications, 'Notification filters are missing an accessible name');
$assertContains('for="notification-date-from"', $notifications, 'Notification start date is not explicitly labelled');
$assertContains('for="notification-date-to"', $notifications, 'Notification end date is not explicitly labelled');
$assertContains('aria-label="Dismiss notification"', $notifications, 'Notification row actions are missing an accessible name');

$locations = file_get_contents($root . '/agent/locations.php');
$assertContains('aria-label="Search locations"', $locations, 'Location search is missing an accessible name');
$assertContains('aria-label="Filter locations by tags"', $locations, 'Location tag filtering is missing an accessible name');
$assertContains('aria-label="Filter locations by client"', $locations, 'Location client filtering is missing an accessible name');
$assertContains('aria-label="Select all displayed locations"', $locations, 'Location bulk selection is missing an accessible name');
$assertContains('aria-label="Actions for <?= $location_name ?>"', $locations, 'Location row actions are missing a contextual accessible name');
$assertNotContains('$client_url tags[]=', $locations, 'Location tag links retain the malformed query-string separator');

$quotes = file_get_contents($root . '/agent/quotes.php');
$assertContains('aria-label="Search quotes"', $quotes, 'Quote search is missing an accessible name');
$assertContains('aria-label="Show quote date filters"', $quotes, 'Quote date filtering is missing an accessible name');
$assertContains('for="dateFilter"', $quotes, 'Quote date-range input is not explicitly labelled');
$assertContains('aria-label="Actions for quote <?= "$quote_prefix$quote_number" ?>"', $quotes, 'Quote row actions are missing a contextual accessible name');
$assertContains("if (\$sort == 'quote_date')", $quotes, 'Quote date sorting does not render its active indicator');
$assertContains("if (\$sort == 'quote_expire')", $quotes, 'Quote expiry sorting does not render its active indicator');
$assertNotContains("Date <?php if (\$sort == 'quote_number')", $quotes, 'Quote date sorting still depends on the quote-number state');
$assertNotContains("Expire <?php if (\$sort == 'quote_number')", $quotes, 'Quote expiry sorting still depends on the quote-number state');

$recurring_tickets = file_get_contents($root . '/agent/recurring_tickets.php');
$assertContains('aria-label="Search recurring tickets"', $recurring_tickets, 'Recurring ticket search is missing an accessible name');
$assertContains('aria-label="Filter recurring tickets by category"', $recurring_tickets, 'Recurring ticket category filtering is missing an accessible name');
$assertContains('aria-label="Filter recurring tickets by assigned agent"', $recurring_tickets, 'Recurring ticket agent filtering is missing an accessible name');
$assertContains('aria-label="Filter recurring tickets by billable status"', $recurring_tickets, 'Recurring ticket billing filtering is missing an accessible name');
$assertContains('aria-label="Select all displayed recurring tickets"', $recurring_tickets, 'Recurring ticket bulk selection is missing an accessible name');
$assertContains('aria-label="Actions for recurring ticket <?= $recurring_ticket_subject ?>"', $recurring_tickets, 'Recurring ticket row actions are missing a contextual accessible name');
$assertContains('aria-label="Template: <?= $recurring_ticket_template_name ?>"', $recurring_tickets, 'Recurring ticket template metadata remains malformed or unnamed');
$assertContains('class="dropdown-item text-danger text-bold confirm-link"', $recurring_tickets, 'Recurring ticket bulk deletion is missing confirmation handling');
$assertNotContains('<th><a href="recurring_tickets.php?client_id=', $recurring_tickets, 'Recurring ticket rows use a header cell for client data');

$recurring_invoices = file_get_contents($root . '/agent/recurring_invoices.php');
$assertContains("'tabs_label' => 'Recurring invoice status'", $recurring_invoices, 'Recurring invoice status navigation is not in the shared workspace header');
$assertContains('aria-label="Search recurring invoices"', $recurring_invoices, 'Recurring invoice search is missing an accessible name');
$assertContains('aria-label="Show recurring invoice date filters"', $recurring_invoices, 'Recurring invoice date filtering is missing an accessible name');
$assertContains('for="dateFilter"', $recurring_invoices, 'Recurring invoice date range is not explicitly labelled');
$assertContains('aria-label="Automatic payment method for recurring invoice <?= "$recurring_invoice_prefix$recurring_invoice_number" ?>"', $recurring_invoices, 'Recurring invoice automatic-payment controls are missing contextual accessible names');
$assertContains('aria-label="Actions for recurring invoice <?= "$recurring_invoice_prefix$recurring_invoice_number" ?>"', $recurring_invoices, 'Recurring invoice row actions are missing contextual accessible names');
$assertContains('saved_payment_client_id IN ($saved_payment_client_id_list)', $recurring_invoices, 'Recurring invoice payment methods are not loaded in a bounded page query');
$assertNotContains('WHERE saved_payment_client_id = $client_id', $recurring_invoices, 'Recurring invoices still query saved payment methods once per displayed row');

$recurring_expenses = file_get_contents($root . '/agent/recurring_expenses.php');
$assertContains('aria-label="Search recurring expenses"', $recurring_expenses, 'Recurring expense search is missing an accessible name');
$assertContains('aria-label="Show recurring expense date filters"', $recurring_expenses, 'Recurring expense date filtering is missing an accessible name');
$assertContains('for="dateFilter"', $recurring_expenses, 'Recurring expense date range is not explicitly labelled');
$assertContains('aria-label="Actions for recurring expense <?= $recurring_expense_description ?>"', $recurring_expenses, 'Recurring expense row actions are missing contextual accessible names');
$assertContains("lookupUserPermission('module_financial') >= 2", $recurring_expenses, 'Recurring expense mutation controls are not permission-aware');

$recurring_expense_add = file_get_contents($root . '/agent/modals/recurring_expense/recurring_expense_add.php');
$recurring_expense_edit = file_get_contents($root . '/agent/modals/recurring_expense/recurring_expense_edit.php');
foreach (array(
    'agent/modals/recurring_expense/recurring_expense_add.php' => $recurring_expense_add,
    'agent/modals/recurring_expense/recurring_expense_edit.php' => $recurring_expense_edit,
) as $path => $modal) {
    $assertContains("enforceUserPermission('module_financial', 2)", $modal, "$path does not enforce recurring-expense mutation permission");
    $assertContains('n45RenderModalHeader(', $modal, "$path does not use the shared modal header");
    $assertContains('COALESCE(payment_totals.total_payments, 0)', $modal, "$path does not aggregate payment balances in its account query");
    $assertContains('COALESCE(revenue_totals.total_revenues, 0)', $modal, "$path does not aggregate revenue balances in its account query");
    $assertContains('COALESCE(expense_totals.total_expenses, 0)', $modal, "$path does not aggregate expense balances in its account query");
    $assertNotContains('WHERE payment_account_id = $account_id', $modal, "$path still queries payment balances once per account");
    $assertNotContains('WHERE revenue_account_id = $account_id', $modal, "$path still queries revenue balances once per account");
    $assertNotContains('WHERE expense_account_id = $account_id', $modal, "$path still queries expense balances once per account");
}
$assertContains('for="recurring-expense-frequency"', $recurring_expense_add, 'Recurring expense create frequency is not explicitly labelled');
$assertContains('for="edit-recurring-expense-frequency"', $recurring_expense_edit, 'Recurring expense edit frequency is not explicitly labelled');
$assertContains('<input type="hidden" name="client_id" value="<?= $client_id ?>">', $recurring_expense_edit, 'Recurring expense edit does not preserve its scoped client identifier');
$assertNotContains('<option <?php if ($config_default_expense_account == $account_id) { echo "selected"; } ?> value="<?= $account_id ?>"><div', $recurring_expense_add, 'Recurring expense account options contain invalid nested markup');

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

$workspace_header_start = strpos($theme, '.n45-workspace-header {');
$workspace_header_end = $workspace_header_start === false ? false : strpos($theme, '}', $workspace_header_start);
$workspace_header = ($workspace_header_start === false || $workspace_header_end === false)
    ? ''
    : substr($theme, $workspace_header_start, $workspace_header_end - $workspace_header_start + 1);
$assertContains('background: var(--n45-surface);', $workspace_header, 'The shared workspace header does not use the Vendors-style light surface');
$assertContains('border-bottom: 1px solid var(--n45-border);', $workspace_header, 'The shared workspace header does not retain its quiet structural divider');
$assertContains('color: var(--n45-ink);', $workspace_header, 'The shared workspace header does not use light-surface text');
$assertNotContains('background: var(--n45-mountain);', $workspace_header, 'The shared workspace header still uses the retired dark treatment');

$page_actions_start = strpos($theme, '.n45-page-actions {');
$page_actions_end = $page_actions_start === false ? false : strpos($theme, '}', $page_actions_start);
$page_actions = ($page_actions_start === false || $page_actions_end === false)
    ? ''
    : substr($theme, $page_actions_start, $page_actions_end - $page_actions_start + 1);
$assertContains('margin-left: auto;', $page_actions, 'Shared workspace actions are not pinned to the right edge');

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
