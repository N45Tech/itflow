<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
foreach (['agent/asset.php', 'agent/modals/asset/asset.php', 'agent/contact.php',
    'agent/modals/contact/contact.php', 'agent/modals/asset/asset_documents.php'] as $file) {
    $source = file_get_contents("$root/$file");
    foreach (['client_assets.php', 'client_contacts.php', 'client_documents.php'] as $retired) {
        $assert(!str_contains($source, $retired), "$file still links to $retired");
    }
}
$assert(str_contains(file_get_contents("$root/agent/global_search.php"), 'href="documentation.php?client_id='),
    'Document search must lead to the current client document workspace');
$library = file_get_contents("$root/agent/includes/inc_client_document_library.php");
foreach (['enforceClientAccess(intval($client_id))', "enforceUserPermission('module_support')",
    'document_client_id = $client_id', 'document_archived_at IS NULL', '$document_page_size = 25',
    'LIMIT $document_page_size OFFSET $document_offset', 'document_add_from_template.php',
    'modals/file/file_upload.php', 'document.php?client_id=', 'Clear document search'] as $contract) {
    $assert(str_contains($library, $contract), 'Client document library is missing: ' . $contract);
}
$workspace = file_get_contents("$root/agent/documentation.php");
$assert(strpos($workspace, "require 'includes/inc_client_document_library.php'") < strpos($workspace, 'Document maintenance'),
    'Actual client documents must appear before optional maintenance');
$assert(!str_contains($workspace, '>Attached</span>'), 'Requirement counts must not be mislabeled as attached documents');
$assert(preg_match('/<details\b[^>]*>\s*<summary\b[^>]*>(?:(?!<\/summary>).)*\bDocument maintenance\b(?:(?!<\/summary>).)*<\/summary>/s', $workspace) === 1,
    'Optional maintenance must use native details and summary elements without requiring JavaScript');
foreach (['ticket_list.php', 'ticket_kanban.php'] as $file) {
    $source = file_get_contents("$root/agent/$file");
    $assert(str_contains($source, 'Browse all tickets') && str_contains($source, '>New ticket</button>'),
        "$file needs useful empty-state recovery actions");
}
$portal = file_get_contents("$root/js/client_portal.js");
$assert(str_contains($portal, "sidebar.setAttribute('inert', '')"), 'Hidden mobile navigation must not remain keyboard-focusable');
$assert(str_contains($portal, "event.key === 'Tab'") && str_contains($portal, "event.key === 'Escape'"),
    'Mobile navigation must handle keyboard focus and dismissal');
$reviews = file_get_contents("$root/agent/business_reviews.php");
$assert(str_contains($reviews, '$review_years = [$year => $year'), 'The review filter must include the year actually being displayed');
$assert(str_contains($reviews, 'No active agreement is scheduling reviews.'), 'Review empty states must not invent an active agreement');

if ($failures) {
    fwrite(STDERR, "Product navigation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Product navigation and document-workspace contracts passed.\n";
