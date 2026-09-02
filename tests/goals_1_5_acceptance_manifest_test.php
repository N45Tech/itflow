<?php

$root = dirname(__DIR__);
$manifest_path = $root . '/docs/n45/goals-1-5-acceptance.json';
$generator_path = $root . '/scripts/n45-goals-1-5-audit.php';
$failures = [];
$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertTrue = static function ($actual, string $message) use (&$failures): void {
    if ($actual !== true) {
        $failures[] = $message;
    }
};

try {
    $manifest = json_decode(
        (string) file_get_contents($manifest_path),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not decode Goals 1-5 acceptance manifest: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$assertSame(1, $manifest['schema_version'] ?? null, 'Acceptance manifest schema changed');
$assertSame(
    'N45-ITFlow-Enhancement-Goals.md',
    $manifest['source_document'] ?? null,
    'Acceptance manifest lost its audited goal-list identity'
);
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['source_sha256'] ?? '')) === 1,
    'Acceptance manifest lost its audited goal-list fingerprint'
);
$assertSame([1, 2, 3, 4, 5], $manifest['goals'] ?? null, 'Acceptance manifest does not cover Goals 1-5 exactly');
$assertSame(35, count($manifest['checks'] ?? []), 'Acceptance check inventory changed without review');
$assertSame(78, count($manifest['requirements'] ?? []), 'Required-outcome/DOD inventory changed without review');

$requirements_by_id = [];
$goal_counts = array_fill_keys([1, 2, 3, 4, 5], 0);
foreach ($manifest['requirements'] ?? [] as $requirement) {
    $id = (string) ($requirement['id'] ?? '');
    $assertTrue($id !== '' && !isset($requirements_by_id[$id]), 'A requirement id is missing or duplicated');
    $requirements_by_id[$id] = $requirement;
    $goal = intval($requirement['goal'] ?? 0);
    if (isset($goal_counts[$goal])) {
        $goal_counts[$goal]++;
    }
    $assertTrue(!empty($requirement['checks']), "$id has no local evidence mapping");
    foreach ($requirement['checks'] ?? [] as $check_id) {
        $assertTrue(isset($manifest['checks'][$check_id]), "$id references missing check $check_id");
    }
    $production_status = $requirement['production_evidence']['status'] ?? '';
    $assertTrue(
        in_array($production_status, ['not_required', 'pending', 'externally_recorded'], true),
        "$id has an unsafe production evidence state"
    );
}
$assertSame([1 => 16, 2 => 17, 3 => 17, 4 => 18, 5 => 10], $goal_counts, 'Per-goal acceptance coverage changed');

$expected_ids = [];
foreach (range(1, 7) as $number) {
    $expected_ids[] = sprintf('G1-RO-%02d', $number);
}
foreach (range(1, 9) as $number) {
    $expected_ids[] = sprintf('G1-DOD-%02d', $number);
}
foreach (range(1, 8) as $number) {
    $expected_ids[] = sprintf('G2-RC-%02d', $number);
}
foreach (range(1, 9) as $number) {
    $expected_ids[] = sprintf('G2-DOD-%02d', $number);
}
foreach (range(1, 8) as $number) {
    $expected_ids[] = sprintf('G3-RG-%02d', $number);
}
foreach (range(1, 9) as $number) {
    $expected_ids[] = sprintf('G3-DOD-%02d', $number);
}
foreach (range(1, 9) as $number) {
    $expected_ids[] = sprintf('G4-RULE-%02d', $number);
    $expected_ids[] = sprintf('G4-DOD-%02d', $number);
    $expected_ids[] = sprintf('G5-OUT-%02d', $number);
}
$expected_ids[] = 'G5-PROD-01';
sort($expected_ids, SORT_STRING);
$actual_ids = array_keys($requirements_by_id);
sort($actual_ids, SORT_STRING);
$assertSame($expected_ids, $actual_ids, 'A required outcome, runtime rule or definition-of-done item is unmapped');

$pending = array_values(array_map(
    static fn ($requirement) => $requirement['id'],
    array_filter(
        $manifest['requirements'],
        static fn ($requirement) => $requirement['production_evidence']['status'] === 'pending'
    )
));
$assertSame([
    'G1-RO-01',
    'G1-RO-02',
    'G1-DOD-01',
    'G1-DOD-02',
    'G3-DOD-09',
    'G4-DOD-09',
    'G5-PROD-01',
], $pending, 'Production-only blockers were hidden, added, or reordered');

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open([PHP_BINARY, $generator_path, '--validate-only'], $descriptors, $pipes, $root);
if (!is_resource($process)) {
    $failures[] = 'Could not start the machine-readable acceptance report validator';
} else {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    $assertSame(0, $exit_code, 'Acceptance report validation failed: ' . trim((string) $stderr));
    try {
        $report = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        $assertSame(true, $report['summary']['manifest_valid'] ?? false, 'Report generator rejected the manifest');
        $assertSame('validate_only', $report['mode'] ?? '', 'Manifest validation unexpectedly ran acceptance tests');
        $assertSame(7, $report['summary']['production_evidence_pending'] ?? null, 'Report hid a production evidence blocker');
        $assertTrue(
            str_contains(
                (string) ($report['production_evidence_policy'] ?? ''),
                'never promote production evidence'
            ),
            'Report does not explicitly separate local evidence from production evidence'
        );
    } catch (Throwable $e) {
        $failures[] = 'Acceptance report generator returned invalid JSON: ' . $e->getMessage();
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Goals 1-5 machine-readable acceptance manifest passed.\n";
