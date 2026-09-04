#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$allowed_modes = ['--dry-run', '--apply'];
if (count($arguments) !== 1 || !in_array($arguments[0], $allowed_modes, true)) {
    $script_name = basename((string) ($argv[0] ?? 'reconcile_documentation_requirements.php'));
    fwrite(STDERR, "Usage: php $script_name (--dry-run|--apply)\n");
    exit(2);
}
$dry_run = $arguments[0] === '--dry-run';
$app_root = dirname(__DIR__, 2);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require $app_root . '/config.php';
require $app_root . '/includes/db.php';
require $app_root . '/functions/documentation.php';

$lock_name = 'n45-itflow-reconcile-documentation-requirements';
$lock_acquired = false;
$transaction_open = false;
$exit_code = 0;
$summary = [
    'requirements_created' => 0,
    'drafts_changed' => 0,
    'versions_published' => 0,
    'requirements_unchanged' => 0,
    'clients_evaluated' => 0,
    'obligations_created' => 0,
    'obligations_changed' => 0,
];

try {
    $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock_row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)"));
    if (intval($lock_row[0] ?? 0) !== 1) {
        throw new RuntimeException('Another documentation requirement reconciliation is already running');
    }
    $lock_acquired = true;

    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not start the documentation requirement reconciliation transaction');
    }
    $transaction_open = true;

    // Match the evaluator's coarse client -> requirement lock order. Locking
    // the managed client set first prevents a concurrent evaluator from
    // holding one client while this transaction holds catalog rows.
    $client_ids = [];
    $clients = mysqli_query($mysqli, "SELECT client_id FROM clients
        WHERE client_archived_at IS NULL AND client_lead = 0
        ORDER BY client_id FOR UPDATE");
    while ($client = mysqli_fetch_assoc($clients)) {
        $client_ids[] = intval($client['client_id']);
    }

    foreach (documentationBaselineRequirementCatalog() as $definition) {
        $definition = documentationCanonicalRequirementDefinition($definition);
        $key_sql = mysqli_real_escape_string($mysqli, $definition['key']);
        $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT documentation_requirement_id,
            documentation_requirement_revision, documentation_requirement_lifecycle
            FROM documentation_requirements
            WHERE documentation_requirement_key = '$key_sql' LIMIT 1 FOR UPDATE"));
        $requirement_id = intval($existing['documentation_requirement_id'] ?? 0);
        $revision = $existing ? intval($existing['documentation_requirement_revision']) : null;
        if (!$requirement_id) {
            $summary['requirements_created']++;
        } elseif ($existing['documentation_requirement_lifecycle'] === 'Archived') {
            $restored = documentationRestoreRequirement($requirement_id, $revision, 0, true);
            $revision = intval($restored['revision']);
        }

        $saved = documentationSaveRequirementDraft($requirement_id, $definition, $revision, 0, true);
        $requirement_id = intval($saved['requirement_id']);
        $revision = intval($saved['revision']);
        if (!empty($saved['changed'])) {
            $summary['drafts_changed']++;
        }
        $published = documentationPublishRequirement($requirement_id, $revision, 0, true);
        if (!empty($published['created'])) {
            $summary['versions_published']++;
        } elseif (empty($saved['changed'])) {
            $summary['requirements_unchanged']++;
        }
    }

    foreach ($client_ids as $client_id) {
        $evaluation = documentationEvaluateClient($client_id, 0, true);
        $summary['clients_evaluated']++;
        $summary['obligations_created'] += intval($evaluation['created'] ?? 0);
        $summary['obligations_changed'] += intval($evaluation['changed'] ?? 0);
    }

    if ($dry_run) {
        if (!mysqli_rollback($mysqli)) {
            throw new RuntimeException('Could not roll back the documentation reconciliation dry run');
        }
    } elseif (!mysqli_commit($mysqli)) {
        throw new RuntimeException('Could not commit the documentation requirement reconciliation');
    }
    $transaction_open = false;
} catch (Throwable $e) {
    if ($transaction_open) {
        mysqli_rollback($mysqli);
        $transaction_open = false;
    }
    fwrite(STDERR, 'Documentation reconciliation failed: ' . $e->getMessage() . PHP_EOL);
    $exit_code = 1;
} finally {
    if ($lock_acquired) {
        $released = mysqli_fetch_row(mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')"));
        if (intval($released[0] ?? 0) !== 1) {
            fwrite(STDERR, "The documentation reconciliation advisory lock release could not be confirmed.\n");
            $exit_code = 1;
        }
    }
}

if ($exit_code === 0) {
    $mode = $dry_run ? 'DRY RUN (rolled back)' : 'APPLIED';
    echo "$mode: created {$summary['requirements_created']} requirements; changed {$summary['drafts_changed']} drafts; "
        . "published {$summary['versions_published']} versions; unchanged {$summary['requirements_unchanged']}; "
        . "evaluated {$summary['clients_evaluated']} clients; created {$summary['obligations_created']} obligations; "
        . "changed {$summary['obligations_changed']} obligations.\n";
}

exit($exit_code);
