<?php

$root = dirname(__DIR__);
$obligation_modal = file_get_contents($root . '/agent/modals/documentation/obligation.php');
$asset_view = file_get_contents($root . '/agent/asset.php');
$network_observations = file_get_contents($root . '/agent/includes/inc_asset_network_observations.php');
$side_nav = file_get_contents($root . '/agent/includes/side_nav.php');
$custom_css = file_get_contents($root . '/css/itflow_custom.css');
$client_add_modal = file_get_contents($root . '/agent/modals/client/client_add.php');
$client_edit_modal = file_get_contents($root . '/agent/modals/client/client_edit.php');

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

$assertContains('ob_start();', $obligation_modal,
    'The documentation obligation modal does not buffer its HTML payload');
$assertContains("require_once '../../../includes/modal_footer.php';", $obligation_modal,
    'The documentation obligation modal does not emit the AJAX modal JSON envelope');
$assertOrder('ob_start();', "require_once '../../../includes/modal_footer.php';", $obligation_modal,
    'The documentation obligation modal closes its JSON envelope before buffering content');
$assertContains("json_encode(['error' => 'The documentation obligation is unavailable.'])", $obligation_modal,
    'The documentation obligation modal does not return unavailable errors as JSON');
$assertContains("json_encode(['error' => 'The verification ticket is not linked to this obligation.'])", $obligation_modal,
    'The documentation obligation modal does not return ticket-link errors as JSON');
$assertNotContains("exit('<div class=\"modal-body\"", $obligation_modal,
    'The documentation obligation modal still returns raw HTML to the JSON client');
$assertContains('data-bs-dismiss="modal"', $obligation_modal,
    'The documentation obligation modal does not use the active Bootstrap dismiss API');
$assertNotContains('data-dismiss="modal"', $obligation_modal,
    'The documentation obligation modal still uses the removed Bootstrap dismiss API');

$assertContains('data-bs-toggle="collapse"', $network_observations,
    'The source-observation disclosure does not use the active Bootstrap collapse API');
$assertContains('data-bs-target="#<?= $network_evidence_id ?>"', $network_observations,
    'The source-observation disclosure does not target its Bootstrap 5 collapse panel');
$assertNotContains('data-toggle="collapse"', $network_observations,
    'The source-observation disclosure still uses the removed Bootstrap collapse API');

$assertContains('class="n45-sidebar-lockup n45-sidebar-lockup-light-bg"', $side_nav,
    'The sidebar is missing its light-background lockup');
$assertContains('class="n45-sidebar-lockup n45-sidebar-lockup-dark-bg"', $side_nav,
    'The sidebar is missing its dark-background lockup');
$assertContains('class="n45-sidebar-mark"', $side_nav,
    'The sidebar is missing its collapsed mark');
$assertContains('.app-sidebar .n45-sidebar-lockup-dark-bg,', $custom_css,
    'The sidebar logo visibility rule does not target the AdminLTE 4 sidebar');
$assertContains('.app-sidebar[data-bs-theme="dark"] .n45-sidebar-lockup-light-bg', $custom_css,
    'The dark sidebar does not hide the dark-lettered lockup');
$assertContains('.app-sidebar[data-bs-theme="dark"] .n45-sidebar-lockup-dark-bg', $custom_css,
    'The dark sidebar does not show the light-lettered lockup');
$assertContains('.sidebar-collapse .app-sidebar:not(:hover) .n45-sidebar-mark', $custom_css,
    'The collapsed AdminLTE 4 sidebar does not show the compact mark');
$assertNotContains('.main-sidebar .n45-sidebar-lockup', $custom_css,
    'Sidebar logo rules still target the retired AdminLTE 3 sidebar class');

foreach (['add' => $client_add_modal, 'edit' => $client_edit_modal] as $client_modal_name => $client_modal) {
    $assertContains("setClientSlaPreset(this.form, 'default')", $client_modal,
        "The client $client_modal_name modal does not provide the native default-SLA preset");
    $assertContains("setClientSlaPreset(this.form, '0')", $client_modal,
        "The client $client_modal_name modal does not provide the native no-SLA preset");
    $assertContains("form.querySelectorAll('[name^=\"client_sla_\"]')", $client_modal,
        "The client $client_modal_name modal does not update SLA fields without jQuery");
    $assertNotContains("onclick=\"$(this.form)", $client_modal,
        "The client $client_modal_name SLA presets still require unavailable jQuery");
}

$auth_script_start = strpos($asset_view, '<!-- JavaScript to Show/Hide Password Form Group -->');
$auth_script_end = $auth_script_start === false ? false : strpos($asset_view, '</script>', $auth_script_start);
if ($auth_script_start === false || $auth_script_end === false) {
    $failures[] = 'Could not isolate the asset authentication-method script';
} else {
    $auth_script = substr($asset_view, $auth_script_start, $auth_script_end - $auth_script_start);
    if (substr_count($auth_script, '});') !== 4) {
        $failures[] = 'The asset authentication-method script has unbalanced callback closures';
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Browser canary regression checks passed" . PHP_EOL;
