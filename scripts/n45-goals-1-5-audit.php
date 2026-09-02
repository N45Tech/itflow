#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from the command line.\n");
    exit(2);
}

$root = dirname(__DIR__);
$manifest_path = $root . '/docs/n45/goals-1-5-acceptance.json';
$validate_only = false;
$pretty = false;
$output_path = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--validate-only') {
        $validate_only = true;
    } elseif ($argument === '--pretty') {
        $pretty = true;
    } elseif (str_starts_with($argument, '--output=')) {
        $output_path = substr($argument, strlen('--output='));
    } elseif (str_starts_with($argument, '--manifest=')) {
        $manifest_path = substr($argument, strlen('--manifest='));
    } else {
        fwrite(STDERR, "Usage: php scripts/n45-goals-1-5-audit.php [--validate-only] [--pretty] [--manifest=PATH] [--output=PATH]\n");
        exit(2);
    }
}

$manifest_json = @file_get_contents($manifest_path);
if ($manifest_json === false) {
    fwrite(STDERR, "Could not read acceptance manifest: $manifest_path\n");
    exit(2);
}
try {
    $manifest = json_decode($manifest_json, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'Acceptance manifest is invalid JSON: ' . $e->getMessage() . "\n");
    exit(2);
}

$validation_errors = [];
$checks = is_array($manifest['checks'] ?? null) ? $manifest['checks'] : [];
$requirements = is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [];
if (($manifest['schema_version'] ?? null) !== 1) {
    $validation_errors[] = 'schema_version must be 1';
}
if (trim((string) ($manifest['source_document'] ?? '')) === ''
    || !preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['source_sha256'] ?? ''))) {
    $validation_errors[] = 'source_document and source_sha256 must identify the audited goal-list snapshot';
}
if (($manifest['goals'] ?? null) !== [1, 2, 3, 4, 5]) {
    $validation_errors[] = 'goals must enumerate 1 through 5 exactly';
}
if (!$checks) {
    $validation_errors[] = 'checks cannot be empty';
}
if (!$requirements) {
    $validation_errors[] = 'requirements cannot be empty';
}

foreach ($checks as $check_id => $check) {
    if (!preg_match('/^g[1-5]\.[a-z0-9][a-z0-9._-]*$/', (string) $check_id)) {
        $validation_errors[] = "invalid check id: $check_id";
    }
    $command = $check['command'] ?? null;
    if (!is_array($command) || count($command) !== 2 || $command[0] !== 'php'
        || !preg_match('#^tests/[a-z0-9_-]+_test\.php$#', (string) ($command[1] ?? ''))) {
        $validation_errors[] = "check $check_id must run one repository PHP test without a shell";
        continue;
    }
    if (!is_file($root . '/' . $command[1])) {
        $validation_errors[] = "check $check_id references a missing test: {$command[1]}";
    }
    if (!in_array($check['evidence_kind'] ?? '', ['synthetic', 'contract', 'integration'], true)) {
        $validation_errors[] = "check $check_id has an invalid evidence_kind";
    }
}

$requirement_ids = [];
$goal_counts = array_fill_keys([1, 2, 3, 4, 5], 0);
$production_statuses = ['not_required', 'pending', 'externally_recorded'];
foreach ($requirements as $index => $requirement) {
    $id = (string) ($requirement['id'] ?? '');
    $goal = intval($requirement['goal'] ?? 0);
    if ($id === '' || isset($requirement_ids[$id])) {
        $validation_errors[] = $id === ''
            ? "requirement at index $index has no id"
            : "duplicate requirement id: $id";
    }
    $requirement_ids[$id] = true;
    if (!isset($goal_counts[$goal])) {
        $validation_errors[] = "requirement $id has an invalid goal";
    } else {
        $goal_counts[$goal]++;
    }
    if (trim((string) ($requirement['text'] ?? '')) === '') {
        $validation_errors[] = "requirement $id has no text";
    }
    $requirement_checks = $requirement['checks'] ?? null;
    if (!is_array($requirement_checks) || !$requirement_checks) {
        $validation_errors[] = "requirement $id has no local evidence checks";
    } else {
        foreach ($requirement_checks as $check_id) {
            if (!array_key_exists((string) $check_id, $checks)) {
                $validation_errors[] = "requirement $id references unknown check $check_id";
            }
        }
    }
    $production = is_array($requirement['production_evidence'] ?? null)
        ? $requirement['production_evidence'] : [];
    $production_status = (string) ($production['status'] ?? '');
    if (!in_array($production_status, $production_statuses, true)) {
        $validation_errors[] = "requirement $id has an invalid production evidence status";
    }
    if ($production_status === 'pending'
        && trim((string) ($production['needed'] ?? '')) === '') {
        $validation_errors[] = "requirement $id has pending production evidence without a prerequisite";
    }
}
foreach ($goal_counts as $goal => $count) {
    if ($count < 1) {
        $validation_errors[] = "goal $goal has no mapped requirements";
    }
}

$run = static function (array $command, string $cwd): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Could not start process'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit_code' => proc_close($process),
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
};

$check_results = [];
if (!$validate_only && !$validation_errors) {
    foreach ($checks as $check_id => $check) {
        $command = $check['command'];
        // The manifest uses a symbolic PHP executable so the same artifact can
        // run under the CI-selected interpreter without a shell.
        $command[0] = PHP_BINARY;
        $result = $run($command, $root);
        $check_results[$check_id] = [
            'status' => $result['exit_code'] === 0 ? 'passed' : 'failed',
            'exit_code' => $result['exit_code'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
        ];
    }
}

$head_result = $run(['git', 'rev-parse', 'HEAD'], $root);
$head = $head_result['exit_code'] === 0 ? $head_result['stdout'] : 'unavailable';
$pending_production = [];
$external_production = [];
$requirement_results = [];
foreach ($requirements as $requirement) {
    $local_status = 'not_run';
    if (!$validate_only && !$validation_errors) {
        $local_status = 'passed';
        foreach ($requirement['checks'] as $check_id) {
            if (($check_results[$check_id]['status'] ?? 'failed') !== 'passed') {
                $local_status = 'failed';
                break;
            }
        }
    }
    $production = $requirement['production_evidence'];
    if ($production['status'] === 'pending') {
        $pending_production[] = [
            'requirement_id' => $requirement['id'],
            'goal' => $requirement['goal'],
            'needed' => $production['needed'],
        ];
    } elseif ($production['status'] === 'externally_recorded') {
        $external_production[] = [
            'requirement_id' => $requirement['id'],
            'goal' => $requirement['goal'],
            'reference' => $production['reference'] ?? '',
            'note' => 'Recorded outside this local verifier; not re-certified by this run.',
        ];
    }
    $requirement_results[] = [
        'id' => $requirement['id'],
        'goal' => $requirement['goal'],
        'section' => $requirement['section'],
        'local_status' => $local_status,
        'production_evidence_status' => $production['status'],
        'checks' => $requirement['checks'],
    ];
}

$local_failed = count(array_filter(
    $check_results,
    static fn ($result) => $result['status'] !== 'passed'
));
$report = [
    'report_version' => 1,
    'scope' => 'N45 ITFlow Goals 1-5 acceptance',
    'repository_head' => $head,
    'manifest_sha256' => hash('sha256', $manifest_json),
    'source_document' => (string) ($manifest['source_document'] ?? ''),
    'source_sha256' => (string) ($manifest['source_sha256'] ?? ''),
    'mode' => $validate_only ? 'validate_only' : 'local_acceptance',
    'production_evidence_policy' => 'Local and synthetic results never promote production evidence.',
    'summary' => [
        'manifest_valid' => !$validation_errors,
        'requirements_mapped' => count($requirements),
        'checks_defined' => count($checks),
        'checks_run' => count($check_results),
        'checks_failed' => $local_failed,
        'local_acceptance_passed' => !$validate_only && !$validation_errors && $local_failed === 0,
        'production_evidence_pending' => count($pending_production),
        'production_evidence_externally_recorded_not_revalidated' => count($external_production),
    ],
    'validation_errors' => $validation_errors,
    'checks' => $check_results,
    'requirements' => $requirement_results,
    'production_evidence_pending' => $pending_production,
    'production_evidence_externally_recorded' => $external_production,
];

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if ($pretty) {
    $flags |= JSON_PRETTY_PRINT;
}
$encoded = json_encode($report, $flags) . "\n";
if ($output_path !== '') {
    if (!str_starts_with($output_path, '/')) {
        $output_path = $root . '/' . $output_path;
    }
    if (@file_put_contents($output_path, $encoded) === false) {
        fwrite(STDERR, "Could not write acceptance report: $output_path\n");
        exit(2);
    }
} else {
    echo $encoded;
}

exit($validation_errors || (!$validate_only && $local_failed > 0) ? 1 : 0);
