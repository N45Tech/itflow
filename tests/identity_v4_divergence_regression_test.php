<?php

$failures = [];
$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    return $contents === false ? '' : $contents;
};

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertContainsAtLeast = function (string $needle, string $haystack, int $minimum, string $message) use (&$failures): void {
    $count = substr_count($haystack, $needle);
    if ($count < $minimum) {
        $failures[] = $message . " (found $count, expected at least $minimum)";
    }
};

$identity = $read('functions/integration_identity.php');
$endpoint = $read('functions/endpoint.php');

$upsertStart = strpos($identity, 'function integrationIdentityUpsertMapping');
$upsertEnd = $upsertStart === false ? false : strpos($identity, 'function integrationIdentityRecordSnapshot', $upsertStart);
$upsertBlock = $upsertStart === false || $upsertEnd === false
    ? ''
    : substr($identity, $upsertStart, $upsertEnd - $upsertStart);

$reviewStart = strpos($identity, 'function integrationIdentityReviewMapping');
$reviewEnd = $reviewStart === false ? false : strpos($identity, 'function integrationIdentityReviewMappings', $reviewStart);
$reviewBlock = $reviewStart === false || $reviewEnd === false
    ? ''
    : substr($identity, $reviewStart, $reviewEnd - $reviewStart);

$retireStart = strpos($identity, 'function integrationIdentityRetireMapping');
$retireEnd = $retireStart === false ? false : strpos($identity, 'function integrationIdentityRetireMissing', $retireStart);
$retireBlock = $retireStart === false || $retireEnd === false
    ? ''
    : substr($identity, $retireStart, $retireEnd - $retireStart);

$quarantineStart = strpos($identity, 'function integrationIdentityQuarantineOrphanMapping');
$quarantineEnd = $quarantineStart === false ? false : strpos($identity, 'function integrationIdentityReconcileOrphans', $quarantineStart);
$quarantineBlock = $quarantineStart === false || $quarantineEnd === false
    ? ''
    : substr($identity, $quarantineStart, $quarantineEnd - $quarantineStart);

$retireIdentityStart = strpos($endpoint, 'function endpointRetireIdentityBindingUnlocked');
$retireIdentityEnd = $retireIdentityStart === false ? false : strpos($endpoint, 'function endpointWarrantyState', $retireIdentityStart);
$retireIdentityBlock = $retireIdentityStart === false || $retireIdentityEnd === false
    ? ''
    : substr($endpoint, $retireIdentityStart, $retireIdentityEnd - $retireIdentityStart);

$assertContains(
    "'allow_state_divergence' => true",
    $upsertBlock,
    'Synthetic duplicate-device conflict quarantine no longer keeps retiring the old identity state during upsert'
);
$assertContainsAtLeast(
    "'allow_state_divergence' => true",
    $reviewBlock,
    2,
    'Identity review no longer tolerates endpoint-state divergence in both remap and ignore/retire paths'
);
$assertContains(
    "'allow_state_divergence' => true",
    $retireBlock,
    'Automatic identity retirement no longer blocks when endpoint identity already diverged'
);
$assertContains(
    "'allow_state_divergence' => true",
    $quarantineBlock,
    'Orphan quarantine no longer fails when the source identity moved its endpoint state'
);
$assertContains(
    '$allow_state_divergence = filter_var(',
    $retireIdentityBlock,
    'Endpoint identity retirement helper is not using the divergence gate'
);
$assertContains(
    'return $allow_state_divergence;',
    $retireIdentityBlock,
    'Endpoint identity retirement helper must return divergence-safe behavior instead of hard-failing'
);
$assertContainsAtLeast(
    'return $allow_state_divergence;',
    $retireIdentityBlock,
    2,
    'Endpoint identity retirement helper must tolerate both tenant+asset and source binding divergence'
);
$assertContains(
    'Identity review stopped because endpoint state diverged from the mapping',
    $identity,
    'Identity review no longer preserves the divergence-safe stop condition'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Synthetic V4 identity divergence regression tests passed.\n";
