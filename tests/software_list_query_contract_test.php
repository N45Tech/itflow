<?php

$root = dirname(__DIR__);
$software_page = file_get_contents($root . '/agent/software.php');
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

$assertContains(
    '(SELECT COUNT(*) FROM software_assets',
    $software_page,
    'The software list does not count asset assignments in its bounded page query'
);
$assertContains(
    '(SELECT COUNT(*) FROM software_contacts',
    $software_page,
    'The software list does not count contact assignments in its bounded page query'
);
$assertContains(
    'AS software_assigned_seats',
    $software_page,
    'The software list query does not expose its aggregate assigned-seat count'
);
$assertContains(
    '$seat_count = intval($row[\'software_assigned_seats\']);',
    $software_page,
    'The software list does not render the aggregate assigned-seat count'
);
$assertNotContains(
    'SELECT asset_id FROM software_assets WHERE software_id = $software_id',
    $software_page,
    'The software list still queries asset assignments once per displayed row'
);
$assertNotContains(
    'SELECT contact_id FROM software_contacts WHERE software_id = $software_id',
    $software_page,
    'The software list still queries contact assignments once per displayed row'
);

if ($failures) {
    fwrite(STDERR, "Software list query contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Software list query contract test passed\n";
