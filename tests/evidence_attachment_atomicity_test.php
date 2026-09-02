<?php

$failures = [];
$root = dirname(__DIR__);

$read = function (string $relative_path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $relative_path);
    if ($contents === false) {
        $failures[] = "Could not read $relative_path";
        return '';
    }
    return $contents;
};

$section = function (string $contents, string $start, string $end, string $label) use (&$failures): string {
    $start_at = strpos($contents, $start);
    $end_at = $start_at === false ? false : strpos($contents, $end, $start_at + strlen($start));
    if ($start_at === false || $end_at === false || $end_at <= $start_at) {
        $failures[] = "Could not isolate $label";
        return '';
    }
    return substr($contents, $start_at, $end_at - $start_at);
};

$assertContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (!str_contains($contents, $needle)) {
        $failures[] = $message . " (missing '$needle')";
    }
};

$assertNotContains = function (string $needle, string $contents, string $message) use (&$failures): void {
    if (str_contains($contents, $needle)) {
        $failures[] = $message . " (found '$needle')";
    }
};

$assertOrdered = function (string $contents, array $needles, string $message) use (&$failures): void {
    $position = -1;
    foreach ($needles as $needle) {
        $next = strpos($contents, $needle, $position + 1);
        if ($next === false) {
            $failures[] = $message . " (missing or out of order '$needle')";
            return;
        }
        $position = $next;
    }
};

$files = $read('functions/files.php');
$save = $section(
    $files,
    'function saveTicketAttachments(',
    'function cleanupStoredTicketAttachmentFiles(',
    'ticket attachment save helper'
);
$cleanup = $section(
    $files,
    'function cleanupStoredTicketAttachmentFiles(',
    'function filterEmailableAttachments(',
    'ticket attachment rollback cleanup helper'
);

$assertOrdered(
    $save,
    [
        'move_uploaded_file(',
        '$attachment_insert = mysqli_query(',
        'mysqli_insert_id($mysqli)',
        'mysqli_affected_rows($mysqli) !== 1',
        'unlink($destination_path)',
        "'attachment_id' => \$attachment_id",
    ],
    'Attachment uploads can be returned without durable metadata or leak a file when metadata insertion fails'
);
$assertContains('error_log(', $save, 'Attachment metadata or cleanup failures are not logged');
$assertOrdered(
    $cleanup,
    [
        "realpath(__DIR__ . '/../uploads/tickets')",
        '$uploads_prefix = rtrim(',
        "strpos(\$file_path, \$uploads_prefix) !== 0",
        'unlink($file_path)',
    ],
    'Rollback cleanup is not confined to the ticket upload tree before unlinking'
);

$task_post = $read('agent/post/task.php');
$evidence_add = $section(
    $task_post,
    "if (isset(\$_POST['add_task_evidence']))",
    "if (isset(\$_POST['delete_task_evidence']))",
    'task evidence upload handler'
);
$assertOrdered(
    $evidence_add,
    [
        '$stored_files = [];',
        'mysqli_begin_transaction($mysqli)',
        "saveTicketAttachments(\$ticket_id, null, 'evidence_files')",
        "\$stored_file['attachment_id']",
        'task_evidence_attachment_id = $attachment_id',
        'mysqli_commit($mysqli)',
        'catch (Throwable $exception)',
        'mysqli_rollback($mysqli)',
        'cleanupStoredTicketAttachmentFiles($stored_files)',
    ],
    'Evidence file metadata and physical-file cleanup do not share the caller transaction outcome'
);
$assertNotContains(
    'ticket_attachment_reference_name',
    $evidence_add,
    'Evidence upload still re-queries attachment identity by filename instead of using the returned ID'
);

$ticket_post = $read('agent/post/ticket.php');
$retention = $read('functions/retention.php');
$attachment_delete = $section(
    $ticket_post,
    "if (isset(\$_GET['delete_ticket_attachment']))",
    "if (isset(\$_POST['edit_ticket_reply']))",
    'ticket attachment deletion handler'
);
$assertOrdered($attachment_delete, [
    'enforceAdminPermission()',
    '/admin/retention.php?record_type=attachment',
], 'Legacy attachment deletion does not route through the administrator retention workflow');
$assertNotContains('DELETE FROM ticket_attachments', $attachment_delete,
    'Legacy attachment handler still removes metadata directly');

$retained_attachment_delete = $section(
    $retention,
    'function retentionSoftDeleteAttachment(',
    'function retentionDeletionForUpdate(',
    'recoverable attachment deletion'
);
$assertOrdered(
    $retained_attachment_delete,
    [
        'retentionRequireAdministratorActor($actor_id)',
        'mysqli_begin_transaction($mysqli)',
        'documentationLockClient($client_id)',
        'FROM tickets WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id',
        "LIMIT 1 FOR UPDATE",
        'FROM ticket_attachments',
        'LIMIT 1 FOR UPDATE',
        'FROM task_evidence WHERE task_evidence_attachment_id = $attachment_id',
        'LIMIT 1 FOR UPDATE',
        'ticket_customer_signature_attachment_id = $attachment_id',
        'retentionMoveToQuarantine(',
        'UPDATE ticket_attachments SET',
        'mysqli_affected_rows($mysqli) !== 1',
        "retentionWriteDeletionLedger('attachment'",
        "retentionAppendEvent('attachment'",
        'mysqli_commit($mysqli)',
    ],
    'Attachment deletion does not lock, quarantine, soft-delete, audit, and commit atomically'
);
$assertContains('mysqli_rollback($mysqli)', $retained_attachment_delete,
    'Attachment soft deletion cannot roll back on a race or query failure');
$assertContains('retentionRollbackQuarantine(', $retained_attachment_delete,
    'Attachment soft deletion cannot restore quarantined bytes after rollback');
$quarantine = $section($retention, 'function retentionMoveToQuarantine(',
    'function retentionRollbackQuarantine(', 'quarantine confinement');
$assertOrdered($quarantine, [
    'realpath($source)',
    'realpath(retentionUploadsRoot())',
    'strpos($real, rtrim($uploads',
    'rename($source, $target)',
], 'Quarantine move is not confined to the uploads root');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Evidence attachment atomicity contracts passed.\n";
