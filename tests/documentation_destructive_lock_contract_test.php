<?php

/*
 * Source-contract coverage for destructive Evidence Locker races. Verification
 * and destructive writes serialize through client -> target locks, recheck the
 * immutable references while locked, and commit before filesystem cleanup.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function ($path) use ($root, &$failures) {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$section = static function ($contents, $start, $end, $label) use (&$failures) {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};
$assertOrdered = static function ($contents, $needles, $label) use (&$failures) {
    $cursor = -1;
    foreach ($needles as $needle) {
        $at = strpos($contents, $needle, $cursor + 1);
        if ($at === false) {
            $failures[] = "$label is missing $needle";
            return;
        }
        if ($at <= $cursor) {
            $failures[] = "$label has an unsafe order around $needle";
            return;
        }
        $cursor = $at;
    }
};
$assertContains = static function ($needle, $contents, $message) use (&$failures) {
    if (strpos($contents, $needle) === false) {
        $failures[] = $message;
    }
};

$file_post = $read('agent/post/file.php');
$file_mutator = $section(
    $file_post,
    '$documentation_mutate_file = static function',
    '$documentation_delete_file_uploads = static function',
    'file mutator'
);
$assertOrdered($file_mutator, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClient($client_id)',
    'FROM files WHERE file_id = $file_id LIMIT 1 FOR UPDATE',
    "documentationEvidenceReferenceInUse('file'",
    'DELETE FROM files WHERE file_id = $file_id',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
], 'File deletion');
$assertContains('UPDATE files SET file_archived_at = NOW()', $file_mutator,
    'File archival bypasses the locked mutator');
$assertContains('return $file;', $file_mutator,
    'Committed file metadata is unavailable for post-commit cleanup');
$assertContains('is_file($path) && !unlink($path)', $file_post,
    'File cleanup is not guarded after the database commit');

$single_file_delete = $section(
    $file_post,
    "if (isset(\$_POST['delete_file']))",
    "if (isset(\$_POST['bulk_archive_files']))",
    'single file delete'
);
$assertOrdered($single_file_delete, [
    '$documentation_mutate_file($file_id, $client_id, \'delete\')',
    '$documentation_delete_file_uploads($locked_file)',
], 'Single file cleanup');
$bulk_file_delete = $section(
    $file_post,
    "if (isset(\$_POST['bulk_delete_files']))",
    "if (isset(\$_POST['bulk_restore_files']))",
    'bulk file delete'
);
$assertOrdered($bulk_file_delete, [
    '$documentation_mutate_file($file_id, $client_id, \'delete\')',
    '$documentation_delete_file_uploads($locked_file)',
], 'Bulk file cleanup');

$document_post = $read('agent/post/document.php');
$archive_document = $section(
    $document_post,
    "if (isset(\$_GET['archive_document']))",
    "if (isset(\$_GET['restore_document']))",
    'document archive'
);
$assertOrdered($archive_document, [
    'mysqli_begin_transaction($mysqli)',
    'documentationInvalidateDocumentLocked(',
    'FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE',
    'ORDER BY document_version_id FOR UPDATE',
    'documentationEvidenceReferenceInUse(',
    "'document-version'",
    'UPDATE documents SET document_archived_at = NOW()',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
], 'Document archive');

$version_delete = $section(
    $document_post,
    "if (isset(\$_GET['delete_document_version']))",
    "if (isset(\$_GET['delete_document']))",
    'document version delete'
);
$assertOrdered($version_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClient($client_id)',
    'FROM documents WHERE document_id = $document_id LIMIT 1 FOR UPDATE',
    'FROM document_versions',
    'LIMIT 1 FOR UPDATE',
    "documentationEvidenceReferenceInUse('document-version'",
    'DELETE FROM document_versions',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
], 'Document version delete');

$document_delete_at = strpos($document_post, "if (isset(\$_GET['delete_document']))");
$document_delete = $document_delete_at === false ? '' : substr($document_post, $document_delete_at);
if ($document_delete_at === false) {
    $failures[] = 'Could not isolate document delete';
}
$assertOrdered($document_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationInvalidateDocumentLocked(',
    "'document_deleted'",
    'ORDER BY document_version_id FOR UPDATE',
    'documentationEvidenceReferenceInUse(',
    "'document-version'",
    'DELETE FROM documents',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
    'removeDirectory(',
], 'Document delete');

$ticket_post = $read('agent/post/ticket.php');
$single_ticket_delete = $section(
    $ticket_post,
    "if (isset(\$_GET['delete_ticket']))",
    "if (isset(\$_POST['bulk_delete_tickets']))",
    'single ticket delete'
);
$assertOrdered($single_ticket_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClientTicket($ticket_id, $client_id, true)',
    'SELECT COUNT(*) FROM runbook_executions',
    'documentationTicketHasAuditRecords($ticket_id)',
    "documentationEvidenceReferenceInUse('ticket'",
    'DELETE FROM tickets WHERE ticket_id = $ticket_id',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
    'removeDirectory(',
], 'Single ticket delete');
$bulk_ticket_delete = $section(
    $ticket_post,
    "if (isset(\$_POST['bulk_delete_tickets']))",
    "if (isset(\$_POST['bulk_assign_ticket']))",
    'bulk ticket delete'
);
$assertOrdered($bulk_ticket_delete, [
    'mysqli_begin_transaction($mysqli)',
    'documentationLockClientTicket($ticket_id, $client_id, true)',
    'SELECT COUNT(*) FROM runbook_executions',
    'documentationTicketHasAuditRecords($ticket_id)',
    "documentationEvidenceReferenceInUse('ticket'",
    'DELETE FROM tickets WHERE ticket_id = $ticket_id',
    'mysqli_affected_rows($mysqli)',
    'mysqli_commit($mysqli)',
    'removeDirectory(',
], 'Bulk ticket delete');

if ($failures) {
    fwrite(STDERR, "Documentation destructive-lock contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation destructive-lock contract test passed\n";
