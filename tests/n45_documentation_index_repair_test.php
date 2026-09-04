<?php

$failures = [];
$root = dirname(__DIR__);

$assertTrue = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (RuntimeException $e) {
        // Expected: an unrecognized index shape must fail before DDL.
    }
};

require_once $root . '/n45/bootstrap.php';
n45RequireModule('schema');

$columns = [
    'documentation_evidence_obligation_id',
    'documentation_evidence_requirement_version_id',
    'documentation_evidence_reference_type',
    'documentation_evidence_reference_id',
    'documentation_evidence_reference_hash',
];
$rowsFor = static function ($non_unique) use ($columns): array {
    $rows = [];
    foreach ($columns as $position => $column) {
        $rows[] = [
            'NON_UNIQUE' => $non_unique,
            'SEQ_IN_INDEX' => (string) ($position + 1),
            'COLUMN_NAME' => $column,
            'COLLATION' => 'A',
            'SUB_PART' => null,
            'INDEX_TYPE' => 'BTREE',
        ];
    }
    return $rows;
};

$assertTrue(
    n45DocumentationEvidenceReferenceIndexShape([]) === 'absent',
    'An absent evidence-reference index was not recognized as repairable'
);
$historical_rows = $rowsFor('0');
$assertTrue(
    n45DocumentationEvidenceReferenceIndexShape($historical_rows) === 'historical_unique',
    'The exact historical unique evidence-reference index was not recognized'
);
$final_rows = $rowsFor('1');
$assertTrue(
    n45DocumentationEvidenceReferenceIndexShape($final_rows) === 'final_nonunique',
    'The exact final non-unique evidence-reference index was not recognized'
);

$wrong_count = $final_rows;
array_pop($wrong_count);
$assertThrows(
    static function () use ($wrong_count): void {
        n45DocumentationEvidenceReferenceIndexShape($wrong_count);
    },
    'A partial evidence-reference index was accepted'
);
$keyed_rows = $final_rows;
$keyed_rows['unexpected'] = array_pop($keyed_rows);
$assertThrows(
    static function () use ($keyed_rows): void {
        n45DocumentationEvidenceReferenceIndexShape($keyed_rows);
    },
    'Non-sequential evidence-reference metadata was accepted'
);
$wrong_order = $final_rows;
[$wrong_order[0]['COLUMN_NAME'], $wrong_order[1]['COLUMN_NAME']] = [
    $wrong_order[1]['COLUMN_NAME'],
    $wrong_order[0]['COLUMN_NAME'],
];
$assertThrows(
    static function () use ($wrong_order): void {
        n45DocumentationEvidenceReferenceIndexShape($wrong_order);
    },
    'An evidence-reference index with reordered columns was accepted'
);
$wrong_sequence = $final_rows;
$wrong_sequence[2]['SEQ_IN_INDEX'] = '3extra';
$assertThrows(
    static function () use ($wrong_sequence): void {
        n45DocumentationEvidenceReferenceIndexShape($wrong_sequence);
    },
    'Malformed evidence-reference index sequence metadata was accepted'
);
$prefix_index = $final_rows;
$prefix_index[4]['SUB_PART'] = '32';
$assertThrows(
    static function () use ($prefix_index): void {
        n45DocumentationEvidenceReferenceIndexShape($prefix_index);
    },
    'A prefixed evidence-reference index was accepted'
);
$descending_index = $final_rows;
$descending_index[3]['COLLATION'] = 'D';
$assertThrows(
    static function () use ($descending_index): void {
        n45DocumentationEvidenceReferenceIndexShape($descending_index);
    },
    'A descending evidence-reference index column was accepted'
);
$wrong_type = $final_rows;
$wrong_type[0]['INDEX_TYPE'] = 'HASH';
$assertThrows(
    static function () use ($wrong_type): void {
        n45DocumentationEvidenceReferenceIndexShape($wrong_type);
    },
    'A non-BTREE evidence-reference index was accepted'
);
$mixed_uniqueness = $final_rows;
$mixed_uniqueness[4]['NON_UNIQUE'] = '0';
$assertThrows(
    static function () use ($mixed_uniqueness): void {
        n45DocumentationEvidenceReferenceIndexShape($mixed_uniqueness);
    },
    'Inconsistent evidence-reference uniqueness metadata was accepted'
);
$unknown_uniqueness = $final_rows;
$unknown_uniqueness[0]['NON_UNIQUE'] = '2';
$assertThrows(
    static function () use ($unknown_uniqueness): void {
        n45DocumentationEvidenceReferenceIndexShape($unknown_uniqueness);
    },
    'Unknown evidence-reference uniqueness metadata was accepted'
);
$incomplete_metadata = $final_rows;
unset($incomplete_metadata[0]['SUB_PART']);
$assertThrows(
    static function () use ($incomplete_metadata): void {
        n45DocumentationEvidenceReferenceIndexShape($incomplete_metadata);
    },
    'Incomplete evidence-reference metadata was accepted'
);

$migration_file = $root . '/n45/migrations/n45-0015-documentation-evidence-reference-index.php';
$migration_source = @file_get_contents($migration_file);
$assertTrue(is_string($migration_source), 'The evidence-reference repair migration is missing');
$migration_source = is_string($migration_source) ? $migration_source : '';
$assertTrue(
    str_contains($migration_source, "defined('FROM_N45_DB_UPDATER')"),
    'The evidence-reference repair migration bypasses the N45 runner guard'
);
$assertTrue(
    !str_contains($migration_source, 'config_current_database_version'),
    'The evidence-reference repair migration incorrectly claims an upstream marker'
);
$assertTrue(
    str_contains($migration_source, 'DROP INDEX `documentation_evidence_reference`')
        && str_contains($migration_source, 'ADD KEY `documentation_evidence_reference`'),
    'The evidence-reference repair does not atomically normalize the historical index'
);
$assertTrue(
    substr_count($migration_source, 'n45DocumentationEvidenceReferenceIndexShape(') === 2,
    'The evidence-reference repair does not inspect both pre-DDL and final index shapes'
);

$manifest = n45ForkManifest();
$definition = $manifest['migrations']['n45-0015-documentation-evidence-reference-index'] ?? [];
$reservation = $manifest['maintenance']['post_integration_migration_reservations']['n45-0015-documentation-evidence-reference-index'] ?? [];
$assertTrue(
    array_key_exists('legacy_version', $definition) && $definition['legacy_version'] === null,
    'The evidence-reference repair manifest definition has a legacy marker'
);
$assertTrue(
    ($definition['data_change'] ?? null) === false,
    'The evidence-reference index-only repair is misclassified as a data rewrite'
);
$assertTrue(
    n45MigrationReservationDefinitionMatches($definition, '', $reservation),
    'The evidence-reference repair does not match its reserved manifest contract'
);
$assertTrue(
    n45ValidateFingerprintDefinition('n45-0015-documentation-evidence-reference-index', $definition) === [],
    'The evidence-reference repair has invalid fingerprint metadata'
);
$assertTrue(
    in_array('n45-0015-documentation-evidence-reference-index', $manifest['modules']['documentation']['migrations'] ?? [], true),
    'The evidence-reference repair is not owned by the documentation module'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "N45 documentation evidence-index repair tests passed" . PHP_EOL;
