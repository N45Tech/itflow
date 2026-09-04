<?php

$root = dirname(__DIR__);
$css = file_get_contents($root . '/css/itflow_custom.css');
$client_header = file_get_contents($root . '/agent/includes/inc_client_top_head.php');
$operations = file_get_contents($root . '/agent/operations.php');
$top_nav = file_get_contents($root . '/includes/top_nav.php');
$clients = file_get_contents($root . '/agent/clients.php');
$tickets = file_get_contents($root . '/agent/tickets.php');

$errors = [];
$assertContains = static function ($needle, $haystack, $message) use (&$errors) {
    if (strpos($haystack, $needle) === false) {
        $errors[] = $message;
    }
};
$assertNotContains = static function ($needle, $haystack, $message) use (&$errors) {
    if (strpos($haystack, $needle) !== false) {
        $errors[] = $message;
    }
};

$assertContains('--n45-accent: #167f70;', $css, 'The shared action color is not aligned with N45 teal');
$assertContains('grid-auto-flow: column;', $css, 'Mobile client context does not use a horizontal detail rail');
$assertContains('scroll-snap-type: x proximity;', $css, 'Scrollable context regions do not provide stable snap behavior');
$assertContains('client-context-rail', $client_header, 'Client details are not wired to the compact responsive rail');
$assertContains('aria-label="Client overview details" tabindex="0"', $client_header, 'Client detail rail is not keyboard accessible');
$assertNotContains('documentation obligations need attention', $operations, 'Retired documentation obligations remain in Operations');
$assertContains('aria-label="More client actions"', $clients, 'Client action menu is unnamed');
$assertContains('aria-label="More ticket actions"', $tickets, 'Ticket action menu is unnamed');
$assertContains('aria-label="Search tickets"', $tickets, 'Ticket search controls are unnamed');
$assertContains('class="user-image rounded-circle" alt=""', $top_nav, 'Decorative account avatar exposes an unlabelled image');

if ($errors) {
    fwrite(STDERR, "Technician UI/UX contract test failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Technician UI/UX contract test passed\n";
