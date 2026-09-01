<?php

/*
 * N45 migration n45-0015-documentation-evidence-reference-index
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

$documentation_evidence_table_result = mysqli_query($mysqli, "SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
    AND table_name = 'documentation_evidence_locker'
    AND table_type = 'BASE TABLE'");
if (!$documentation_evidence_table_result) {
    throw new RuntimeException('Could not inspect the documentation evidence table: ' . mysqli_error($mysqli));
}
$documentation_evidence_table_row = mysqli_fetch_row($documentation_evidence_table_result);
if (intval($documentation_evidence_table_row[0] ?? 0) !== 1) {
    throw new RuntimeException('The documentation evidence table is missing');
}

$documentation_evidence_reference_rows = static function () use ($mysqli): array {
    $result = mysqli_query($mysqli, "SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
        AND table_name = 'documentation_evidence_locker'
        AND index_name = 'documentation_evidence_reference'
        ORDER BY SEQ_IN_INDEX");
    if (!$result) {
        throw new RuntimeException('Could not inspect the documentation evidence-reference index: ' . mysqli_error($mysqli));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
};

$documentation_evidence_reference_shape = n45DocumentationEvidenceReferenceIndexShape(
    $documentation_evidence_reference_rows()
);
$documentation_evidence_reference_columns = "`documentation_evidence_obligation_id`,
    `documentation_evidence_requirement_version_id`,
    `documentation_evidence_reference_type`,
    `documentation_evidence_reference_id`,
    `documentation_evidence_reference_hash`";

if ($documentation_evidence_reference_shape === 'absent') {
    $documentation_evidence_reference_query = "ALTER TABLE `documentation_evidence_locker`
        ADD KEY `documentation_evidence_reference` ($documentation_evidence_reference_columns)";
} elseif ($documentation_evidence_reference_shape === 'historical_unique') {
    $documentation_evidence_reference_query = "ALTER TABLE `documentation_evidence_locker`
        DROP INDEX `documentation_evidence_reference`,
        ADD KEY `documentation_evidence_reference` ($documentation_evidence_reference_columns)";
} else {
    $documentation_evidence_reference_query = null;
}

if ($documentation_evidence_reference_query !== null
    && !mysqli_query($mysqli, $documentation_evidence_reference_query)) {
    throw new RuntimeException('Could not normalize the documentation evidence-reference index: ' . mysqli_error($mysqli));
}

if (n45DocumentationEvidenceReferenceIndexShape($documentation_evidence_reference_rows()) !== 'final_nonunique') {
    throw new RuntimeException('The documentation evidence-reference index did not reach its final non-unique shape');
}
