<?php

function n45AssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function n45AssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$asset = file_get_contents($root . '/agent/asset.php');
$contact = file_get_contents($root . '/agent/contact.php');
$contacts = file_get_contents($root . '/agent/contacts.php');
$locations = file_get_contents($root . '/agent/locations.php');
$ajax = file_get_contents($root . '/agent/ajax.php');
$security = file_get_contents($root . '/functions/security.php');
$approver = file_get_contents($root . '/agent/modals/ticket/ticket_task_approver_add.php');
$contact_api = file_get_contents($root . '/api/v1/contacts/read.php');
$api_outputs = implode("\n", array_map('file_get_contents', [
    $root . '/api/v1/create_output.php',
    $root . '/api/v1/delete_output.php',
    $root . '/api/v1/read_output.php',
    $root . '/api/v1/update_output.php',
]));

n45AssertContains("enforceUserPermission('module_support')", $asset,
    'Asset detail must enforce support permission');
n45AssertContains("clientScopeSql('assets.asset_client_id')", $asset,
    'Asset detail must apply client scoping');
n45AssertContains("enforceUserPermission('module_client')", $contact,
    'Contact detail must enforce client permission');
n45AssertContains("clientScopeSql('contacts.contact_client_id')", $contact,
    'Contact detail must apply client scoping in its query');
n45AssertContains('enforceClientAccess($client_id)', $contact,
    'Contact detail must enforce the resolved client');
n45AssertContains("enforceUserPermission('module_client')", $contacts,
    'Contact list must enforce client permission');
n45AssertContains("enforceUserPermission('module_client')", $locations,
    'Location list must enforce client permission');

n45AssertContains('generateReadablePassword()', $ajax,
    'Readable password endpoint must use the cryptographically secure generator');
n45AssertContains('random_int(', $security,
    'Readable passwords must use a cryptographically secure random source');
n45AssertContains('max(2, $word_count)', $security,
    'Readable password word count must have a hard floor');

n45AssertContains('new Option(', $approver,
    'Approver names must be inserted through a text-safe option constructor');
n45AssertNotContains('`${u.user_name}</option>`', $approver,
    'Approver names must not be interpolated into HTML');
n45AssertContains("WHERE (contact_mobile = '\$phone_or_mob' OR contact_phone = '\$phone_or_mob') AND 1=1", $contact_api,
    'Phone alternatives must remain inside the API client-scope predicate');
n45AssertNotContains("WHERE contact_mobile = '\$phone_or_mob' OR contact_phone = '\$phone_or_mob' AND 1=1", $contact_api,
    'Mobile contact lookup must not bypass API client scoping through SQL precedence');
n45AssertContains("logApp('API', 'Error'", $api_outputs,
    'Authenticated API query failures must be retained in application logs');
n45AssertContains("parse_url(\$_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)", $api_outputs,
    'API failure logs must retain only the request path, not its query string');

echo "Upstream security backport contracts passed.\n";
