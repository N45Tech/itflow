<?php

require_once __DIR__ . '/../functions/documentation.php';

$root = dirname(__DIR__);
$source = file_get_contents($root . '/functions/documentation.php');
$schema = file_get_contents($root . '/db.sql');
$migration = file_get_contents($root . '/n45/migrations/n45-0011-documentation-readiness.php');
$failures = [];

$assertSame = static function ($expected, $actual, $message) use (&$failures) {
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
};
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos((string) $contents, $needle) === false) {
        $failures[] = $message;
    }
};
$assertNotContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos((string) $contents, $needle) !== false) {
        $failures[] = $message;
    }
};
$section = static function ($start, $end) use ($source, &$failures) {
    $start_at = strpos((string) $source, $start);
    $end_at = $start_at === false ? false : strpos((string) $source, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false) {
        $failures[] = "Could not isolate $start";
        return '';
    }
    return substr((string) $source, $start_at, $end_at - $start_at);
};

$now = '2026-09-01 12:00:00';
$published_v_next = [
    'documentation_obligation_id' => 10,
    'documentation_obligation_applicable' => 1,
    'documentation_obligation_base_status' => 'Current',
    'documentation_obligation_last_verified_at' => '2026-08-31 12:00:00',
    'documentation_obligation_verification_document_hash' => hash('sha256', 'current'),
    'documentation_requirement_version_blocks_readiness' => 1,
    'documentation_requirement_version_review_cadence_days' => 90,
    'documentation_requirement_version_warning_window_days' => 14,
    'documentation_requirement_current_lifecycle' => 'Active',
    'documentation_requirement_current_version_id' => 202,
    'documentation_requirement_projection_valid' => 0,
    'documentation_verification_context_valid' => 0,
    'documentation_exception_record_valid' => 0,
    'current_document_exists' => 1,
    'current_document_content_raw' => 'current',
];
$projection = documentationProjectObligationValidity($published_v_next, $now);
$readiness = documentationReadinessReduce([$published_v_next], $now);
$assertSame('Draft', $projection['base_status'], 'A published vNext left the prior verified projection Current before reconciliation');
$assertSame(0, $readiness['numerator'], 'A superseded verification earned readiness credit');
$assertSame(1, $readiness['denominator'], 'An active blocking vNext disappeared from the readiness denominator');
$assertSame(0, $readiness['score_percent'], 'A published vNext left readiness at 100 percent before reconciliation');

$missing_evidence = $published_v_next;
$missing_evidence['documentation_requirement_projection_valid'] = 1;
$assertSame('Draft', documentationProjectObligationValidity($missing_evidence, $now)['base_status'],
    'A missing current Evidence Locker pin did not fail closed');
$archived = $published_v_next;
$archived['documentation_requirement_current_lifecycle'] = 'Archived';
$assertSame('Not Applicable', documentationProjectObligationValidity($archived, $now)['base_status'],
    'An archived requirement retained a Current queue projection');

$validity_sql = documentationObligationValiditySql('o');
foreach (['documentation_requirements', 'documentation_requirement_versions',
          'documentation_evidence_locker', 'documentation_obligation_exceptions', 'documents'] as $table) {
    $assertContains($table, $validity_sql['joins'], "Validity SQL does not join $table");
}
foreach (['documentation_requirement_projection_valid', 'documentation_verification_context_valid',
          'documentation_exception_record_valid', 'current_document_exists', 'current_document_hash'] as $field) {
    $assertContains($field, $validity_sql['select'], "Validity SQL omits $field");
}

$verify = $section('function documentationVerifyObligation(', 'function documentationRequestObligationException(');
$record = $section('function documentationRecordEvidenceLocked(', 'function documentationVerifyObligation(');
$validate = $section('function documentationValidateEvidenceReference(', 'function documentationEvidenceReferenceInUse(');
$assertContains('documentationLockClientTicket(', $verify, 'Ticket-scoped verification does not lock client before ticket');
$assertContains('documentationLockEvidenceReference(', $record, 'Evidence insertion does not lock its referenced entity');
$assertContains('FOR UPDATE', $validate, 'Evidence reference validation cannot lock the referenced entity');
$assertContains('INSERT INTO documentation_evidence_locker', $record, 'Verification does not append immutable evidence');
$assertNotContains('INSERT IGNORE INTO documentation_evidence_locker', $record, 'Evidence verification deduplicates distinct occurrences');
$assertNotContains('Could not resolve idempotent verification evidence', $record, 'Evidence verification reuses stale provenance');
$assertSame(['none', 'note', 'file', 'reference'], documentationEvidencePolicies(),
    'An unauthenticated automation-only evidence policy remains publishable');
$assertNotContains('UNIQUE KEY `documentation_evidence_reference`', $schema,
    'Baseline schema deduplicates distinct evidence occurrences');
$assertNotContains('UNIQUE KEY `documentation_evidence_reference`', $migration,
    'N45 migration deduplicates distinct evidence occurrences');
$assertContains('KEY `documentation_evidence_reference`', $schema,
    'Baseline schema lost the evidence-reference lookup index');
$assertContains('KEY `documentation_evidence_reference`', $migration,
    'N45 migration lost the evidence-reference lookup index');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Documentation evidence/readiness re-audit contracts passed.\n";
