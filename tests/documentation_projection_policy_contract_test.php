<?php

/* Fail-closed queue/count projection and supported manual-policy inventory. */

$root = dirname(__DIR__);
$failures = [];
$read = static function ($path) use ($root, &$failures) {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) === false) {
        $failures[] = $message;
    }
};
$assertNotContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) !== false) {
        $failures[] = $message;
    }
};

$projection_surfaces = [
    'agent/documentation.php',
    'agent/includes/get_side_nav_counts.php',
    'agent/includes/inc_all_client.php',
];
foreach ($projection_surfaces as $path) {
    $contents = $read($path);
    $assertContains("documentationObligationValiditySql('o')", $contents,
        "$path does not select the canonical validity context");
    $assertContains("{\$documentation_validity['select']}", $contents,
        "$path omits the validity projection fields");
    $assertContains("{\$documentation_validity['joins']}", $contents,
        "$path omits the validity joins");
    $assertContains('documentationProjectObligationValidity(', $contents,
        "$path trusts stored obligation status");
}

$queue = $read('agent/documentation.php');
$assertContains("['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception']", $queue,
    'The queue attention projection omits Draft or another actionable status');
$assertContains("!empty(\$obligation['current_document_exists'])", $queue,
    'The queue presents an invalid or archived document as current');

foreach (['agent/includes/get_side_nav_counts.php', 'agent/includes/inc_all_client.php'] as $path) {
    $contents = $read($path);
    $assertNotContains('DATE_SUB(o.documentation_obligation_next_review_at', $contents,
        "$path still carries a divergent direct freshness projection");
    $assertContains("['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception']", $contents,
        "$path omits an actionable projected status");
}

$policy_surfaces = [
    'admin/post/documentation_requirement.php',
    'admin/modals/documentation_requirement/documentation_requirement.php',
    'agent/post/documentation.php',
    'agent/modals/documentation/obligation.php',
];
foreach ($policy_surfaces as $path) {
    $contents = $read($path);
    $assertNotContains('trusted_automation', $contents,
        "$path exposes an unsupported trusted-automation policy");
}
$assertContains("['none', 'note', 'file', 'reference']", $read('admin/post/documentation_requirement.php'),
    'Requirement POST does not enforce the supported evidence policy set');
$assertContains("['none', 'note', 'file', 'reference']", $read('agent/post/documentation.php'),
    'Manual verification POST does not fail closed on unsupported policies');

$core = $read('functions/documentation.php');
if (strpos($core, 'function documentationObligationValiditySql(') !== false) {
    foreach (['current_document_exists', 'current_document_hash',
              'documentation_requirement_projection_valid',
              'documentation_verification_context_valid',
              'documentation_exception_record_valid'] as $field) {
        $assertContains($field, $core, "Core validity SQL omits $field");
    }
    $assertContains('function documentationProjectObligationValidity(', $core,
        'Core pure validity projector is missing');
}

if ($failures) {
    fwrite(STDERR, "Documentation projection/policy contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation projection/policy contract test passed\n";
