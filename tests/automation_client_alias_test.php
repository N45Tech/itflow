<?php

require_once __DIR__ . '/../functions/automation.php';

$cases = [
    'N45 Technologies' => 'N45 Technology Solutions',
    'N45 Tech Solutions' => 'N45 Technology Solutions',
    ' n45 technologies ' => 'N45 Technology Solutions',
    'Example Legal Group' => 'Example Legal Group',
];

foreach ($cases as $input => $expected) {
    $actual = automationCanonicalClientName($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected '$input' to resolve to '$expected'; got '$actual'.\n");
        exit(1);
    }
}

echo "Automation client alias tests passed.\n";
