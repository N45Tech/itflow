<?php

$root = dirname(__DIR__);
$theme = file_get_contents($root . '/css/n45_theme.css');
$tickets = file_get_contents($root . '/agent/tickets.php');
$clients = file_get_contents($root . '/agent/clients.php');
$dashboard = file_get_contents($root . '/agent/dashboard.php');
$operations = file_get_contents($root . '/agent/operations.php');
$portal_tickets = file_get_contents($root . '/client/tickets.php');
$header = file_get_contents($root . '/includes/header.php');
$top_nav = file_get_contents($root . '/includes/top_nav.php');
$density_script = file_get_contents($root . '/js/n45_sidebar_density.js');

$failures = [];
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
$relativeLuminance = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    $channels = [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
    $channels = array_map(
        static fn (float $channel): float => $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4,
        $channels
    );
    return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
};
$contrastRatio = static function (string $foreground, string $background) use ($relativeLuminance): float {
    $foreground_luminance = $relativeLuminance($foreground);
    $background_luminance = $relativeLuminance($background);
    $lighter = max($foreground_luminance, $background_luminance);
    $darker = min($foreground_luminance, $background_luminance);
    return ($lighter + 0.05) / ($darker + 0.05);
};

foreach ([
    'includes/header.php',
    'client/includes/header.php',
    'guest/includes/guest_header.php',
    'login.php',
    'setup/index.php',
    'agent/login_microsoft.php',
    'agent/user/mfa_enforcement.php',
    'client/login_reset.php',
] as $surface) {
    $markup = file_get_contents($root . '/' . $surface);
    $assertOrder(
        'itflow_custom.css',
        'n45_theme.css',
        $markup,
        "$surface does not load the isolated N45 theme after ITFlow compatibility styles"
    );
}

foreach (['.app-sidebar {', '.app-header.navbar {', '.app-main {'] as $selector) {
    $assertContains($selector, $theme, "The N45 theme is missing the active AdminLTE 4 selector $selector");
}
$assertNotContains('.main-sidebar', $theme, 'The N45 theme targets the retired AdminLTE 3 sidebar');
$assertNotContains('.content-wrapper', $theme, 'The N45 theme targets the retired AdminLTE 3 content wrapper');
$assertContains('--n45-accent: var(--n45-action);', $theme, 'Legacy N45 components are not connected to the new action token');
$assertContains('body.dark-mode {', $theme, 'The N45 theme has no dark-mode token set');
$assertContains('@media (prefers-reduced-motion: reduce)', $theme, 'The N45 theme ignores reduced-motion preferences');
$assertContains('.n45-client-portal {', $theme, 'The customer portal is not connected to the shared theme');
$assertContains('.dropdown-item:not(.text-danger)', $theme, 'Dropdown styling can erase destructive action colors');
$assertContains(".card-title {\n    color: inherit;", $theme, 'Card titles can lose contrast on contextual headers');
$assertContains('color: var(--n45-on-action) !important;', $theme, 'Active sidebar navigation does not use the contrasting action foreground');
$assertContains('body.dark-mode .nav-pills .nav-link.active,', $theme, 'Legacy dark-mode active states do not use the contrasting action foreground');
$assertContains('min-height: 2.75rem;', $theme, 'Mobile ticket-state controls do not meet the 44px touch-target baseline');
$assertContains('.n45-workspace .btn,', $theme, 'Button alignment is not scoped to N45-owned surfaces');
$assertContains('.mr-1 { margin-right: .25rem !important; }', $theme, 'Legacy right-side icon spacing is not bridged for Bootstrap 5');
$assertContains('.ml-1 { margin-left: .25rem !important; }', $theme, 'Legacy left-side icon spacing is not bridged for Bootstrap 5');
$assertContains('--n45-sidebar-link-min-height: 2.1rem;', $theme, 'Compact sidebar density is not the default');
$assertContains('html[data-n45-sidebar-density="comfortable"] {', $theme, 'Comfortable sidebar density is not available');
$assertContains('@media (hover: none), (pointer: coarse)', $theme, 'Sidebar density does not preserve coarse-pointer touch targets');
$assertContains('n45_sidebar_density.js', $header, 'Sidebar density is not applied before the theme loads');
$assertOrder('n45_sidebar_density.js', 'n45_theme.css', $header, 'Sidebar density must be applied before the N45 stylesheet parses');
$assertContains('data-n45-sidebar-density-option="compact"', $top_nav, 'The account menu is missing the compact density option');
$assertContains('data-n45-sidebar-density-option="comfortable"', $top_nav, 'The account menu is missing the comfortable density option');
$assertContains('data-bs-auto-close="outside"', $top_nav, 'The account menu closes before density feedback can be reviewed');
$assertContains("var STORAGE_KEY = 'n45-sidebar-density';", $density_script, 'Sidebar density does not use a stable browser preference key');
$assertContains("var DEFAULT_DENSITY = 'compact';", $density_script, 'Compact sidebar density is not the scripted default');
$assertContains("document.documentElement.setAttribute('data-n45-sidebar-density', density);", $density_script, 'Sidebar density is not applied to the document root');
if ($contrastRatio('#49c8b1', '#0a2423') < 4.5) {
    $failures[] = 'The dark-mode active navigation color pair does not meet WCAG AA contrast';
}
if ($contrastRatio('#ffffff', '#167f70') < 4.5) {
    $failures[] = 'The light-mode primary action color pair does not meet WCAG AA contrast';
}

$assertContains('class="card n45-workspace mb-3"', $tickets, 'Tickets do not use the shared dense workspace pattern');
$assertContains('class="n45-status-tabs" aria-label="Ticket state"', $tickets, 'Ticket state navigation is not semantic');
$assertContains('aria-current="page"', $tickets, 'Ticket state navigation does not expose its active state');
$assertContains('id="tickets-page-title"', $tickets, 'Tickets do not expose a page-level heading');
$assertContains('class="card n45-workspace"', $clients, 'Clients do not use the shared dense workspace pattern');
$assertContains('id="clients-page-title"', $clients, 'Clients do not expose a page-level heading');
$assertContains('class="n45-page-lead"', $dashboard, 'The dashboard does not use the shared page lead pattern');
$assertContains('fa-wave-square me-2', $dashboard, 'The Operations pulse heading icon touches its label');
$assertContains('fa-plus me-2', $operations, 'The Operations create action icon touches its label');
$assertContains('fa-plus me-2', $portal_tickets, 'The portal create action icon touches its label');

if ($failures) {
    fwrite(STDERR, "N45 theme system test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "N45 theme system test passed\n";
