<?php

require_once __DIR__ . '/../functions/documentation.php';

$failures = [];
$assertSame = function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

$catalog = documentationBaselineRequirementCatalog();
$assertSame(9, count($catalog), 'The N45 baseline documentation catalog does not cover all nine record families');
$expected_types = ['identity', 'network', 'backup', 'security', 'endpoint', 'vendor', 'agreement', 'portal', 'recovery'];
$actual_types = [];
$keys = [];
foreach ($catalog as $definition) {
    $canonical = documentationCanonicalRequirementDefinition($definition);
    $keys[] = $canonical['key'];
    $actual_types[] = $canonical['record_type'];
    $assertSame([], documentationValidateRequirementDefinition($canonical), $canonical['key'] . ' is not publishable');
    $assertTrue(preg_match('/^[a-f0-9]{64}$/', documentationRequirementDefinitionHash($canonical)) === 1, $canonical['key'] . ' has no stable definition hash');
    $assertTrue($canonical['review_cadence_days'] > $canonical['warning_window_days'], $canonical['key'] . ' has an invalid warning window');
}
sort($expected_types);
sort($actual_types);
$assertSame($expected_types, $actual_types, 'The baseline requirement record-type coverage changed');
$assertSame(count($keys), count(array_unique($keys)), 'Baseline requirement keys are not unique');

$definition = $catalog[0];
$reordered = $definition;
$reordered['selectors'] = array_reverse($definition['selectors']);
$reordered['selectors'][] = $definition['selectors'][0];
$assertSame(
    documentationRequirementDefinitionHash($definition),
    documentationRequirementDefinitionHash($reordered),
    'Selector order or duplication changed the canonical published hash'
);

$invalid = $definition;
$invalid['selectors'] = [['dimension' => 'sql', 'value' => 'SELECT *']];
$assertTrue(count(documentationValidateRequirementDefinition($invalid)) > 0, 'An unallowlisted applicability selector was accepted');

$facts = [
    'always' => ['any' => true],
    'active_contract' => ['any' => true],
    'plan' => ['managed-care' => true],
    'service' => [],
    'service_category' => ['security' => true],
    'asset_class' => ['workstation' => true],
    'integration' => ['sentinelone' => true],
    'client_type' => [],
];
$assertTrue(documentationRequirementApplies([['dimension' => 'service_category', 'value' => 'security']], 'any', $facts), 'Exact service-category applicability did not match');
$assertSame(false, documentationRequirementApplies([['dimension' => 'service_category', 'value' => 'secure']], 'any', $facts), 'Applicability used a substring instead of an exact canonical value');
$assertTrue(documentationRequirementApplies([
    ['dimension' => 'active_contract', 'value' => 'any'],
    ['dimension' => 'integration', 'value' => 'sentinelone'],
], 'all', $facts), 'All-mode applicability rejected matching facts');

$redacted = documentationRedactAuditText('password=hunter2 token=abc https://example.invalid/?secret=xyz');
$assertTrue(!str_contains($redacted, 'hunter2') && !str_contains($redacted, 'abc') && !str_contains($redacted, 'xyz'), 'Audit redaction retained a credential');
$assertTrue(!str_contains(documentationEvidenceReferenceHash('url', 0, 'sensitive-locator'), 'sensitive-locator'), 'Evidence Locker hashing exposed an opaque locator');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation definition tests passed.\n";
