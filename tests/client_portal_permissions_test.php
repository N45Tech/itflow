<?php

require_once __DIR__ . '/../client/functions.php';

$failures = [];

$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};

// Standard portal user: personal support and inventory only.
$session_contact_primary = 0;
$session_contact_is_billing_contact = false;
$session_contact_is_technical_contact = false;
$session_contact_ticket_scope = 'own';
$session_contact_asset_scope = 'assigned';
$session_contact_can_manage_contacts = false;
$session_contact_can_review_service_reviews = false;

$assertSame(false, contactCan('tickets_all'), 'Portal user received organization-wide ticket access');
$assertSame(false, contactCan('assets_all'), 'Portal user received organization-wide asset access');
$assertSame(true, contactCan('assets'), 'Portal user lost access to assigned assets');
$assertSame(false, contactCan('contacts'), 'Portal user received contact management access');
$assertSame(false, contactCan('accounting'), 'Portal user received billing access');
$assertSame(false, contactCan('itdoc'), 'Portal user received technical-record access');
$assertSame(false, contactCan('service_reviews'), 'Portal user received business-review access');

// Portal manager: organization-wide tickets and assets, but no implicit extras.
$session_contact_ticket_scope = 'client';
$session_contact_asset_scope = 'client';
$assertSame(true, contactCan('tickets_all'), 'Portal manager cannot view organization tickets');
$assertSame(true, contactCan('assets_all'), 'Portal manager cannot view organization assets');
$assertSame(false, contactCan('contacts'), 'Portal manager implicitly received contact management access');
$assertSame(false, contactCan('accounting'), 'Portal manager implicitly received billing access');
$assertSame(false, contactCan('itdoc'), 'Portal manager implicitly received technical-record access');
$assertSame(false, contactCan('service_reviews'), 'Portal manager implicitly received business-review access');

// Independent permissions can be combined without changing the manager scope.
$session_contact_is_billing_contact = true;
$session_contact_is_technical_contact = true;
$session_contact_can_manage_contacts = true;
$session_contact_can_review_service_reviews = true;
$assertSame(true, contactCan('accounting'), 'Billing permission was not honored');
$assertSame(true, contactCan('itdoc'), 'Technical-record permission was not honored');
$assertSame(true, contactCan('contacts'), 'Contact management permission was not honored');
$assertSame(true, contactCan('service_reviews'), 'Business-review permission was not honored');

// Primary contacts remain full-access and migration-safe.
$session_contact_primary = 1;
$session_contact_is_billing_contact = false;
$session_contact_is_technical_contact = false;
$session_contact_ticket_scope = 'own';
$session_contact_asset_scope = 'assigned';
$session_contact_can_manage_contacts = false;
$session_contact_can_review_service_reviews = false;
foreach (['tickets_all', 'assets_all', 'assets', 'contacts', 'accounting', 'itdoc', 'service_reviews'] as $capability) {
    $assertSame(true, contactCan($capability), "Primary contact lost $capability access");
}

$assertSame(
    ['ticket_scope' => 'own', 'asset_scope' => 'assigned'],
    portalAccessScopesFromRole('user'),
    'Portal user role mapped to the wrong scopes'
);
$assertSame(
    ['ticket_scope' => 'client', 'asset_scope' => 'client'],
    portalAccessScopesFromRole('manager'),
    'Portal manager role mapped to the wrong scopes'
);
$assertSame(
    ['ticket_scope' => 'own', 'asset_scope' => 'assigned'],
    portalAccessScopesFromRole('unexpected'),
    'Unknown portal role did not fail closed'
);
$assertSame('manager', portalAccessRoleFromScopes('client', 'client'), 'Manager scopes were not recognized');
$assertSame('user', portalAccessRoleFromScopes('client', 'assigned'), 'Mixed scopes did not fail closed to the user role');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Client portal permission tests passed.\n";
