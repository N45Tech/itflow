<?php

// Inbound mail owns one transaction through ticket/reply, files, notifications
// and action outbox. IMAP acknowledgement is deliberately outside that commit.

function ticketEmailIdentity(string $mailbox, string $raw): array {
    if (trim($mailbox) === '' || $raw === '') {
        throw new InvalidArgumentException('Inbound mail requires a mailbox and original message');
    }
    $headers = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
    $headers = preg_replace('/\r?\n[\t ]+/', ' ', $headers);
    preg_match_all('/^Message-ID:[\t ]*(.+)$/mi', $headers, $matches);
    $message_id = count($matches[1]) === 1 ? trim($matches[1][0]) : '';
    $content_hash = hash('sha256', $raw);
    // Missing/malformed identifiers use the complete raw message fingerprint.
    // Case in an RFC message identifier is significant; never lowercase it.
    $identity = preg_match('/^<[^<>\s]+@[^<>\s]+>$/D', $message_id)
        ? 'message-id:' . $message_id : 'raw:' . $content_hash;
    return [
        'mailbox_hash' => hash('sha256', trim($mailbox)),
        'message_hash' => hash('sha256', $identity),
        'content_hash' => $content_hash,
    ];
}

function ticketEmailDb(string $sql) {
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException('Could not persist inbound mail');
    }
    return $result;
}

/** Checked, flushed, verified write followed by a rename on the same volume. */
function ticketEmailWriteFile(string $path, string $contents): bool {
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create inbound mail storage');
    }
    $expected = hash('sha256', $contents);
    if (is_link($path)) {
        throw new RuntimeException('Inbound mail storage cannot use symbolic links');
    }
    if (is_file($path)) {
        if (filesize($path) !== strlen($contents) || !hash_equals($expected, hash_file('sha256', $path))) {
            throw new RuntimeException('An inbound mail file failed integrity verification');
        }
        return false;
    }
    $temporary = $path . '.' . bin2hex(random_bytes(16)) . '.tmp';
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Could not stage inbound mail');
    }
    try {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the complete inbound mail file');
            }
            $offset += $written;
        }
        if (!fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Could not flush inbound mail storage');
        }
        fclose($handle);
        $handle = null;
        if (filesize($temporary) !== $length || !hash_equals($expected, hash_file('sha256', $temporary))) {
            throw new RuntimeException('Staged inbound mail failed integrity verification');
        }
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Could not finalize inbound mail storage');
        }
        return true;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function ticketEmailRequireTransaction(): array {
    global $ticket_email_context;
    if (empty($ticket_email_context['id'])
        || intval(mysqli_fetch_row(ticketEmailDb('SELECT @@in_transaction'))[0]) !== 1) {
        throw new RuntimeException('Inbound mail must run through its receipt transaction');
    }
    return $ticket_email_context;
}

function ticketEmailResult(string $kind, int $ticket_id = 0, int $reply_id = 0, int $client_id = 0): void {
    global $ticket_email_context;
    ticketEmailRequireTransaction();
    if (!in_array($kind, ['ticket', 'reply', 'closed_reply', 'rejected', 'ignored'], true)) {
        throw new InvalidArgumentException('Invalid inbound mail result');
    }
    $ticket_email_context['result'] = [$kind, $ticket_id, $reply_id, $client_id];
}

/** Persist only files whose bytes have been verified in their final location. */
function ticketEmailStoreFiles(int $ticket_id, int $reply_id, array $attachments, array $allowed_extensions): void {
    global $mysqli, $ticket_email_context;
    $context = ticketEmailRequireTransaction();
    $raw = file_get_contents($context['source_path']);
    if ($raw === false || !hash_equals($context['content_hash'], hash('sha256', $raw))) {
        throw new RuntimeException('The original inbound message is missing or changed');
    }
    $files = [['name' => 'Original-parsed-email.eml', 'content' => $raw, 'extension' => 'eml']];
    foreach ($attachments as $attachment) {
        $name = (string) $attachment['name'];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions, true)) {
            continue; // The complete original email remains available to staff.
        }
        $files[] = ['name' => $name, 'content' => (string) $attachment['content'], 'extension' => $extension];
    }
    foreach ($files as $position => $file) {
        $reference = hash('sha256', 'mail:' . $context['id'] . ':' . $position . ':' . $context['content_hash']) . '.' . $file['extension'];
        $path = dirname(__DIR__) . '/uploads/tickets/' . $ticket_id . '/' . $reference;
        if (ticketEmailWriteFile($path, $file['content'])) {
            $ticket_email_context['created_files'][] = $path;
        }
        $name_sql = mysqli_real_escape_string($mysqli, mb_substr($file['name'], 0, 200));
        ticketEmailDb("INSERT INTO ticket_attachments SET ticket_attachment_name = '$name_sql',
            ticket_attachment_reference_name = '$reference', ticket_attachment_ticket_id = $ticket_id,
            ticket_attachment_reply_id = $reply_id");
    }
}

/**
 * Replays return the committed result without invoking the processor. A reused
 * Message-ID with different bytes is quarantined instead of silently discarded.
 * The receipt row serializes concurrent workers on different application hosts.
 */
function ticketEmailProcess(string $mailbox, string $raw, callable $processor): array {
    global $mysqli, $ticket_email_context;
    if (!empty($ticket_email_context)
        || intval(mysqli_fetch_row(ticketEmailDb('SELECT @@in_transaction'))[0]) !== 0) {
        throw new RuntimeException('Inbound mail cannot nest transactions');
    }
    $identity = ticketEmailIdentity($mailbox, $raw);
    ['mailbox_hash' => $mailbox_hash, 'message_hash' => $message_hash, 'content_hash' => $content_hash] = $identity;
    $driver = new mysqli_driver();
    $report_mode = $driver->report_mode;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $id = 0;
    $commit_attempted = false;
    $transaction_started = false;
    try {
        ticketEmailDb("INSERT INTO email_ingestion_receipts SET receipt_mailbox_hash = '$mailbox_hash',
            receipt_message_hash = '$message_hash', receipt_content_hash = '$content_hash'
            ON DUPLICATE KEY UPDATE receipt_id = LAST_INSERT_ID(receipt_id)");
        $id = intval(mysqli_insert_id($mysqli));
        if (!$id || !mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin inbound mail processing');
        }
        $transaction_started = true;
        $receipt = mysqli_fetch_assoc(ticketEmailDb("SELECT * FROM email_ingestion_receipts WHERE receipt_id = $id FOR UPDATE"));
        if (!hash_equals($receipt['receipt_content_hash'], $content_hash)) {
            throw new RuntimeException('Message identity was reused with different content');
        }
        if ($receipt['receipt_status'] === 'Completed') {
            mysqli_commit($mysqli);
            $transaction_started = false;
            return ['processed' => true, 'replayed' => true, 'receipt_id' => $id,
                'kind' => $receipt['receipt_result'], 'ticket_id' => intval($receipt['receipt_ticket_id']),
                'reply_id' => intval($receipt['receipt_reply_id'])];
        }
        // This directory is denied over HTTP, even if a failed source needs to
        // remain available through more than one cron run or a process crash.
        $source_directory = dirname(__DIR__) . '/uploads/.mail-ingestion';
        ticketEmailWriteFile($source_directory . '/.htaccess', "Require all denied\n");
        $source_path = $source_directory . '/' . $mailbox_hash . '-' . $message_hash . '.eml';
        ticketEmailWriteFile($source_path, $raw);
        $ticket_email_context = $identity + ['id' => $id, 'source_path' => $source_path,
            'created_files' => [], 'result' => ['ignored', 0, 0, 0]];
        if ($processor() !== true) {
            throw new RuntimeException('Inbound mail needs manual review');
        }
        [$kind, $ticket_id, $reply_id, $client_id] = $ticket_email_context['result'];
        ticketEmailDb("UPDATE email_ingestion_receipts SET receipt_status = 'Completed',
            receipt_result = '$kind', receipt_ticket_id = $ticket_id, receipt_reply_id = $reply_id,
            receipt_client_id = $client_id, receipt_attempts = receipt_attempts + 1,
            receipt_last_error = NULL, receipt_completed_at = NOW() WHERE receipt_id = $id");
        $commit_attempted = true;
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit inbound mail processing');
        }
        $transaction_started = false;
        if ($kind !== 'rejected') {
            @unlink($source_path); // A committed ticket retains its verified original.
        }
        return ['processed' => true, 'replayed' => false, 'receipt_id' => $id,
            'kind' => $kind, 'ticket_id' => $ticket_id, 'reply_id' => $reply_id];
    } catch (Throwable $exception) {
        if ($transaction_started) {
            mysqli_rollback($mysqli);
        }
        // Never delete files after an uncertain COMMIT acknowledgement. The
        // next receipt lookup resolves whether that commit reached the server.
        if (!$commit_attempted) {
            foreach ($ticket_email_context['created_files'] ?? [] as $path) {
                @unlink($path);
            }
        }
        if ($id) {
            ticketEmailDb("UPDATE email_ingestion_receipts SET receipt_status = 'Failed',
                receipt_attempts = receipt_attempts + 1, receipt_last_error = 'Processing failed; original remains in the mailbox for retry.'
                WHERE receipt_id = $id AND receipt_status <> 'Completed'");
        }
        throw $exception;
    } finally {
        $ticket_email_context = null;
        mysqli_report($report_mode);
    }
}

function ticketEmailEnqueueAction(string $trigger, int $ticket_id, int $client_id): void {
    $context = ticketEmailRequireTransaction();
    if (!in_array($trigger, ['ticket_create', 'ticket_reply_client'], true)) {
        throw new InvalidArgumentException('Invalid inbound mail action');
    }
    $receipt_id = $context['id'];
    $event_key = hash('sha256', 'inbound-mail:' . $receipt_id . ':' . $trigger);
    ticketEmailDb("INSERT INTO email_ingestion_actions SET action_receipt_id = $receipt_id,
        action_event_key = '$event_key', action_trigger = '$trigger',
        action_ticket_id = $ticket_id, action_client_id = $client_id");
    ticketEmailDb("INSERT IGNORE INTO cron_jobs SET cron_job_name = 'ticket_email_actions',
        cron_job_enabled = 1, cron_job_schedule = 'Interval', cron_job_interval_minutes = 1");
    ticketEmailDb("UPDATE cron_jobs SET cron_job_run_now = 1 WHERE cron_job_name = 'ticket_email_actions'");
}

// One hook per fresh worker preserves the existing custom-handler include_once
// contract. A stable key makes at-least-once delivery deduplicable downstream.
function ticketEmailProcessAction(): array {
    global $mysqli;
    mysqli_begin_transaction($mysqli);
    try {
        $row = mysqli_fetch_assoc(ticketEmailDb("SELECT * FROM email_ingestion_actions
            WHERE (action_status IN ('Pending','Failed') AND action_available_at <= NOW())
            OR (action_status = 'Processing' AND action_processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
            ORDER BY action_receipt_id LIMIT 1 FOR UPDATE"));
        if (!$row) {
            mysqli_commit($mysqli);
            return ['status' => 'skipped'];
        }
        $id = intval($row['action_receipt_id']);
        $lease = bin2hex(random_bytes(32));
        ticketEmailDb("UPDATE email_ingestion_actions SET action_status = 'Processing',
            action_processing_at = NOW(), action_lease_token = '$lease', action_attempts = action_attempts + 1
            WHERE action_receipt_id = $id");
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit inbound mail action claim');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($mysqli);
        throw $exception;
    }
    try {
        $ticket_id = intval($row['action_ticket_id']);
        $client_id = intval($row['action_client_id']);
        $trigger = $row['action_trigger'];
        $valid = mysqli_fetch_row(ticketEmailDb("SELECT COUNT(*) FROM email_ingestion_receipts r
            JOIN tickets t ON t.ticket_id = r.receipt_ticket_id AND t.ticket_client_id = r.receipt_client_id
            WHERE r.receipt_id = $id AND r.receipt_status = 'Completed'
            AND t.ticket_id = $ticket_id AND t.ticket_client_id = $client_id AND t.ticket_archived_at IS NULL"));
        if (intval($valid[0]) !== 1 || !in_array($trigger, ['ticket_create','ticket_reply_client'], true)
            || !hash_equals(hash('sha256', 'inbound-mail:' . $id . ':' . $trigger), $row['action_event_key'])) {
            throw new RuntimeException('Inbound mail action target changed');
        }
        if (triggerCustomAction($trigger, $ticket_id, $row['action_event_key']) === false) {
            throw new RuntimeException('Inbound mail action requires a fresh worker');
        }
        ticketEmailDb("UPDATE email_ingestion_actions SET action_status = 'Delivered',
            action_processing_at = NULL, action_lease_token = NULL, action_last_error = NULL
            WHERE action_receipt_id = $id AND action_status = 'Processing' AND action_lease_token = '$lease'");
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('Inbound mail action lease changed');
        }
        return ['status' => 'delivered', 'receipt_id' => $id];
    } catch (Throwable $exception) {
        ticketEmailDb("UPDATE email_ingestion_actions SET action_status = 'Failed',
            action_available_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE), action_processing_at = NULL,
            action_lease_token = NULL, action_last_error = 'Action failed; retained for retry.'
            WHERE action_receipt_id = $id AND action_status = 'Processing' AND action_lease_token = '$lease'");
        return ['status' => 'failed', 'receipt_id' => $id];
    }
}
