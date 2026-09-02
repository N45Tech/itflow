<?php

/* Embedded images must be journaled with their owning database mutation. */

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};
$assertContains = static function (string $contents, string $needle, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message;
    }
};
$assertOrdered = static function (string $contents, array $needles, string $message) use (&$failures): void {
    $offset = -1;
    foreach ($needles as $needle) {
        $position = strpos($contents, $needle, $offset + 1);
        if ($position === false || $position <= $offset) {
            $failures[] = "$message (missing/out of order: $needle)";
            return;
        }
        $offset = $position;
    }
};

$files = $read('functions/files.php');
$schema = $read('db.sql');
$migration = $read('n45/migrations/n45-0016-release-safety-hardening.php');
$registry = $read('includes/cron_jobs.php');
$cron = $read('cron/file_staging_recovery.php');

foreach (['file_staging_batch_token', 'file_staging_staged_path', 'file_staging_final_path',
          'file_staging_sha256', 'file_staging_status', 'file_staging_finalized_at'] as $column) {
    $assertContains($schema, $column, "Baseline staging journal omits $column");
    $assertContains($migration, $column, "Release migration omits $column");
}
$assertOrdered($files, [
    'file_put_contents($filePath, $binary, LOCK_EX)',
    'INSERT INTO file_staging_operations SET',
    '$img->setAttribute(\'src\', \'/\' . $finalRelative)',
], 'Embedded image bytes, journal row, and final URL are not built in safe order');
$assertOrdered($files, [
    'if (is_file($final_path))',
    "hash_file('sha256', $final_path)",
    'rename($staged_path, $final_path)',
    "file_staging_status = 'Finalized'",
], 'Recovery does not verify and atomically rename before marking a file finalized');
$assertContains($files, "file_staging_status IN ('Pending', 'Failed')",
    'Failed finalization is not retryable');
$assertContains($files, 'fileStagingQueueRecovery()', 'A failed finalization does not wake recovery');
$assertContains($files, 'function fileStagingFinalizeCommittedBatch(',
    'Post-commit finalization has no non-throwing recovery boundary');
$assertContains($files, 'function fileStagingStageDirectory(',
    'Existing document-template images have no journaled staging path');
$assertContains($registry, "'name' => 'file_staging_recovery'", 'The recovery job is absent from the cron registry');
$assertContains($cron, 'fileStagingRecover(100)', 'The recovery cron does not invoke the journal reconciler');

$callers = [
    'admin/post/document_template.php' => 2,
    'agent/post/document.php' => 2,
    'client/post.php' => 1,
    'api/v1/documents/create.php' => 1,
    'api/v1/documents/update.php' => 1,
];
foreach ($callers as $path => $expected_calls) {
    $contents = $read($path);
    if (substr_count($contents, 'saveBase64Images(') !== $expected_calls) {
        $failures[] = "$path has an unexpected embedded-image writer inventory";
    }
    if (substr_count($contents, '$staging_batch') < $expected_calls * 2) {
        $failures[] = "$path does not pass a durable batch through every embedded-image writer";
    }
    $assertContains($contents, 'mysqli_begin_transaction($mysqli)', "$path does not transactionally own image journaling");
    $assertContains($contents, 'mysqli_commit($mysqli)', "$path does not commit image metadata");
    if (substr_count($contents, 'fileStagingDiscardBatch($staging_batch)') < $expected_calls) {
        $failures[] = "$path does not discard every uncommitted staged-file batch after rollback";
    }
    $assertContains($contents, 'fileStagingFinalizeCommittedBatch($staging_batch,',
        "$path does not finalize through the post-commit recovery boundary");
}
$api_create = $read('api/v1/documents/create.php');
$api_update = $read('api/v1/documents/update.php');
$assertOrdered($api_create, [
    'mysqli_commit($mysqli)',
    '} catch (Throwable $error)',
    'fileStagingFinalizeCommittedBatch($staging_batch,',
], 'API document creation can still report a committed row as rolled back after finalization');
$assertOrdered($api_update, [
    'mysqli_commit($mysqli)',
    '} catch (Throwable $error)',
    'fileStagingFinalizeCommittedBatch($staging_batch,',
], 'API document update can still report a committed row as rolled back after finalization');
$agent_documents = $read('agent/post/document.php');
$assertContains($agent_documents, 'fileStagingStageDirectory(',
    'Template-based document creation does not stage copied images');
$assertContains($agent_documents, 'A document-template image is unavailable for durable staging',
    'Template-based document creation can commit missing referenced images');
if (str_contains($agent_documents, 'copyDirectory($templateFsPath, $documentFsPath)')) {
    $failures[] = 'Template-based document creation still performs an unjournaled direct copy';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "File staging atomicity contract passed.\n";
