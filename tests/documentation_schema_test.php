<?php

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/db.sql');
$migration = file_get_contents($root . '/n45/migrations/n45-0011-documentation-readiness.php');
$failures = [];

if ($schema === false || $migration === false) {
    fwrite(STDERR, "Could not read documentation schema sources\n");
    exit(1);
}

$assertContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};
$assertNotContains = function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};
$tableBody = function (string $sql, string $table, bool $migration_source) use (&$failures): string {
    $if_not_exists = $migration_source ? '(?:IF NOT EXISTS )?' : '';
    $pattern = '/CREATE TABLE ' . $if_not_exists . '`' . preg_quote($table, '/') . '` \((.*?)\n\s*\) ENGINE=/s';
    if (!preg_match($pattern, $sql, $matches)) {
        $failures[] = "Could not isolate $table in " . ($migration_source ? 'migration' : 'baseline');
        return '';
    }
    return $matches[1];
};
$contracts = function (string $body): array {
    $columns = [];
    $indexes = [];
    foreach (preg_split('/\R/', $body) as $line) {
        $line = trim($line, " \t,\r\n");
        if (preg_match('/^`([^`]+)`\s/', $line, $match)) {
            $columns[] = $match[1];
        } elseif (preg_match('/^(PRIMARY KEY|(?:UNIQUE )?KEY `[^`]+`)/', $line, $match)) {
            $indexes[] = $match[1];
        }
    }
    sort($columns);
    sort($indexes);
    return [$columns, $indexes];
};
$normalizedDefinition = function (string $body): array {
    $lines = [];
    foreach (preg_split('/\R/', $body) as $line) {
        $line = preg_replace('/\s+/', ' ', trim($line));
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
};

$tables = [
    'documentation_requirements',
    'documentation_requirement_versions',
    'documentation_requirement_version_selectors',
    'client_documentation_obligations',
    'documentation_obligation_events',
    'documentation_obligation_exceptions',
    'documentation_obligation_exception_events',
    'documentation_evidence_locker',
    'documentation_change_passports',
    'documentation_change_passport_obligations',
    'documentation_promise_ledger',
    'documentation_promise_events',
    'ticket_documentation_obligations',
    'ticket_documentation_waivers',
    'ticket_documentation_waiver_events',
];

foreach ($tables as $table) {
    $schema_body = $tableBody($schema, $table, false);
    $migration_body = $tableBody($migration, $table, true);
    if ($schema_body !== '' && $migration_body !== '') {
        [$schema_columns, $schema_indexes] = $contracts($schema_body);
        [$migration_columns, $migration_indexes] = $contracts($migration_body);
        if ($schema_columns !== $migration_columns) {
            $failures[] = "$table column parity differs between db.sql and n45-0011";
        }
        if ($schema_indexes !== $migration_indexes) {
            $failures[] = "$table index parity differs between db.sql and n45-0011";
        }
        if ($normalizedDefinition($schema_body) !== $normalizedDefinition($migration_body)) {
            $failures[] = "$table exact definition parity differs between db.sql and n45-0011";
        }
        $assertNotContains('ON DELETE CASCADE', $schema_body, "$table uses destructive cascade behavior");
        $assertNotContains('ON DELETE CASCADE', $migration_body, "$table migration uses destructive cascade behavior");
    }
}

$ticket_columns = [
    'ticket_configuration_change',
    'ticket_documentation_impact',
    'ticket_documentation_assessed_by',
    'ticket_documentation_assessed_at',
];
foreach ($ticket_columns as $column) {
    $assertContains("`$column`", $schema, "Baseline tickets are missing $column");
    $assertContains("`$column`", $migration, "n45-0011 tickets are missing $column");
}
$assertContains("DEFAULT 'Legacy Exempt'", $migration, 'Legacy tickets are not explicitly exempted during migration');
$assertContains("DEFAULT 'Unassessed'", $migration, 'New migrated tickets do not default to Unassessed');
$assertContains("DEFAULT 'Unassessed'", $schema, 'New baseline tickets do not default to Unassessed');

$required_contracts = [
    'UNIQUE KEY `documentation_requirement_key`' => 'Requirement stable keys are not unique',
    'UNIQUE KEY `documentation_requirement_version_hash`' => 'Immutable publication is not idempotent by definition hash',
    'UNIQUE KEY `documentation_obligation_client_requirement`' => 'Client/requirement obligation identity is not unique',
    'KEY `documentation_obligation_owner_queue`' => 'Owner work is not indexed',
    'KEY `documentation_obligation_reviewer_queue`' => 'Reviewer work is not indexed',
    'KEY `documentation_obligation_exception_pointer`' => 'The obligation current-exception pointer is not indexed',
    'KEY `documentation_obligation_exception_event_history`' => 'Exception decision history is not indexed',
    'UNIQUE KEY `documentation_change_passport_sequence`' => 'Re-resolution Change Passports do not receive a sequence',
    'KEY `documentation_change_passport_obligation_source`' => 'Change Passport obligation snapshots cannot be traced to their source obligation',
    'KEY `documentation_promise_event_history`' => 'Promise Ledger history is not indexed',
    'KEY `ticket_documentation_waiver_event_history`' => 'Ticket waiver history is not indexed',
    'KEY `ticket_documentation_obligation_task`' => 'Task-scoped documentation links are not indexed',
];
foreach ($required_contracts as $contract => $message) {
    $assertContains($contract, $schema, "$message in db.sql");
    $assertContains($contract, $migration, "$message in n45-0011");
}

$assertNotContains('documentation_readiness_score', $schema, 'Readiness was stored as a mutable score column');
$assertNotContains('documentation_readiness_score', $migration, 'The migration stores a mutable readiness score');
$version_body = $tableBody($schema, 'documentation_requirement_versions', false);
$assertNotContains('_updated_at', $version_body, 'Published documentation versions expose mutable update timestamps');
$assertContains('documentation_obligation_verification_document_hash', $schema, 'Verification cannot be invalidated after document changes');
$assertContains('documentation_obligation_verification_document_version_id', $schema, 'Verification does not retain a document revision reference');
$assertContains('documentation_change_passport_obligation_exception_id', $schema, 'Change Passports do not pin the exact exception record');
$assertContains('documentation_change_passport_obligation_waiver_id', $schema, 'Change Passports do not pin the exact waiver record');
$assertContains('ticket_documentation_obligation_task_id', $schema, 'Ticket documentation links cannot identify their source task');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation schema parity tests passed.\n";
