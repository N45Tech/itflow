<?php

// Filesystem and embedded-image helpers
// Split from the former monolithic functions.php


function removeDirectory($path) {
    if (!file_exists($path)) {
        return;
    }

    $files = glob($path . '/*');
    foreach ($files as $file) {
        is_dir($file) ? removeDirectory($file) : unlink($file);
    }
    rmdir($path);
}

function copyDirectory($src, $dst) {
    if (!is_dir($src)) {
        return;
    }

    if (!is_dir($dst)) {
        mkdir($dst, 0775, true);
    }

    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;

        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

function mkdirMissing($dir) {
    if (!is_dir($dir)) {
        mkdir($dir);
    }
}

function fileStagingBatchToken(): string {
    return hash('sha256', random_bytes(32));
}

/**
 * Stage an existing uploads directory inside the caller's database transaction.
 * The returned paths are relative to the source directory and can be checked
 * against the HTML that will reference them before the transaction commits.
 */
function fileStagingStageDirectory(string $source_directory, string $final_relative_directory,
    string $batch_token, string $owner_type, int $owner_id): array {
    global $mysqli;

    if (preg_match('/^[a-f0-9]{64}$/', $batch_token) !== 1) {
        throw new InvalidArgumentException('Invalid file staging batch token');
    }
    $owner_type = substr(preg_replace('/[^a-z0-9_-]+/i', '_', $owner_type), 0, 40);
    if ($owner_type === '') {
        throw new InvalidArgumentException('A file staging owner type is required');
    }
    if (!is_dir($source_directory)) {
        return [];
    }

    $uploads_root = realpath(dirname(__DIR__) . '/uploads');
    $source_root = realpath($source_directory);
    if ($uploads_root === false || $source_root === false
        || ($source_root !== $uploads_root
            && !str_starts_with($source_root, $uploads_root . DIRECTORY_SEPARATOR))) {
        throw new InvalidArgumentException('The staged source directory is outside uploads');
    }
    $final_root = rtrim(fileStagingRelativePath($final_relative_directory), '/');
    if (str_starts_with($final_root, 'uploads/.staging')) {
        throw new InvalidArgumentException('A staging directory cannot be used as the final destination');
    }

    $batch_sql = mysqli_real_escape_string($mysqli, $batch_token);
    $owner_type_sql = mysqli_real_escape_string($mysqli, $owner_type);
    $owner_id = max(0, $owner_id);
    $staged_files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Symbolic links cannot be copied into a staged upload');
        }
        if (!$file->isFile()) {
            continue;
        }
        $source_path = $file->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($source_path, strlen($source_root))), '/');
        if ($relative === '' || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative)) {
            throw new RuntimeException('A staged upload has an invalid relative path');
        }

        $staged_relative = fileStagingRelativePath("uploads/.staging/$batch_token/$relative");
        $final_relative = fileStagingRelativePath("$final_root/$relative");
        $staged_path = fileStagingAbsolutePath($staged_relative);
        $staged_directory = dirname($staged_path);
        if (!is_dir($staged_directory) && !mkdir($staged_directory, 0775, true)
            && !is_dir($staged_directory)) {
            throw new RuntimeException('Could not create the staged upload directory');
        }

        $source_handle = fopen($source_path, 'rb');
        $staged_handle = fopen($staged_path, 'xb');
        if ($source_handle === false || $staged_handle === false) {
            if (is_resource($source_handle)) {
                fclose($source_handle);
            }
            if (is_resource($staged_handle)) {
                fclose($staged_handle);
            }
            @unlink($staged_path);
            throw new RuntimeException('Could not open a template upload for staging');
        }
        $copied = false;
        try {
            if (!flock($staged_handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock a staged template upload');
            }
            $bytes = stream_copy_to_stream($source_handle, $staged_handle);
            if ($bytes === false || !fflush($staged_handle)) {
                throw new RuntimeException('Could not copy a template upload into staging');
            }
            $copied = true;
        } finally {
            fclose($source_handle);
            fclose($staged_handle);
            if (!$copied) {
                @unlink($staged_path);
            }
        }

        $size = filesize($staged_path);
        $sha = hash_file('sha256', $staged_path);
        if ($size === false || $sha === false) {
            @unlink($staged_path);
            throw new RuntimeException('Could not verify a staged template upload');
        }
        $staged_sql = mysqli_real_escape_string($mysqli, $staged_relative);
        $final_sql = mysqli_real_escape_string($mysqli, $final_relative);
        $sha_sql = mysqli_real_escape_string($mysqli, $sha);
        if (!mysqli_query($mysqli, "INSERT INTO file_staging_operations SET
            file_staging_batch_token = '$batch_sql',
            file_staging_owner_type = '$owner_type_sql',
            file_staging_owner_id = $owner_id,
            file_staging_staged_path = '$staged_sql',
            file_staging_final_path = '$final_sql',
            file_staging_sha256 = '$sha_sql', file_staging_size = " . intval($size))) {
            @unlink($staged_path);
            throw new RuntimeException('Could not journal a staged template upload');
        }
        $staged_files[] = $relative;
    }
    sort($staged_files, SORT_STRING);
    return $staged_files;
}

function fileStagingRelativePath(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, "\0") !== false
        || !str_starts_with($path, 'uploads/')
        || preg_match('#(?:^|/)\.\.(?:/|$)#', $path)) {
        throw new InvalidArgumentException('File staging path is outside the application uploads directory');
    }
    return preg_replace('#/+#', '/', $path);
}

function fileStagingAbsolutePath(string $relative_path): string {
    return dirname(__DIR__) . '/' . fileStagingRelativePath($relative_path);
}

function fileStagingDiscardBatch(string $batch_token): void {
    if (preg_match('/^[a-f0-9]{64}$/', $batch_token) !== 1) {
        return;
    }
    $directory = dirname(__DIR__) . '/uploads/.staging/' . $batch_token;
    if (is_dir($directory)) {
        try {
            removeDirectory($directory);
        } catch (Throwable $error) {
            error_log('Could not discard rolled-back file staging batch: ' . $error->getMessage());
        }
    }
}

function fileStagingQueueRecovery(): void {
    global $mysqli;
    mysqli_query($mysqli, "INSERT IGNORE INTO cron_jobs SET
        cron_job_name = 'file_staging_recovery', cron_job_enabled = 1,
        cron_job_schedule = 'Interval', cron_job_interval_minutes = 1");
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1
        WHERE cron_job_name = 'file_staging_recovery'");
}

/**
 * Move a committed batch into place. The journal is deliberately updated only
 * after the rename; a crash in between is recovered by verifying the final
 * file's hash on the next run.
 */
function fileStagingFinalizeBatch(string $batch_token): bool {
    global $mysqli;
    if (preg_match('/^[a-f0-9]{64}$/', $batch_token) !== 1) {
        return false;
    }

    $lock_name = 'itflow_file_stage_' . substr($batch_token, 0, 44);
    $lock_name_sql = mysqli_real_escape_string($mysqli, $lock_name);
    $lock_sql = mysqli_query($mysqli, "SELECT GET_LOCK('$lock_name_sql', 0)");
    $lock = $lock_sql ? mysqli_fetch_row($lock_sql) : null;
    if (intval($lock[0] ?? 0) !== 1) {
        return false;
    }

    $batch_sql = mysqli_real_escape_string($mysqli, $batch_token);
    $success = true;
    try {
        $operations = mysqli_query($mysqli, "SELECT * FROM file_staging_operations
            WHERE file_staging_batch_token = '$batch_sql'
            AND file_staging_status IN ('Pending', 'Failed')
            ORDER BY file_staging_id ASC");
        if (!$operations) {
            return false;
        }
        while ($operation = mysqli_fetch_assoc($operations)) {
            $operation_id = intval($operation['file_staging_id']);
            try {
                $staged_relative = fileStagingRelativePath($operation['file_staging_staged_path']);
                $final_relative = fileStagingRelativePath($operation['file_staging_final_path']);
                if (!str_starts_with($staged_relative, "uploads/.staging/$batch_token/")) {
                    throw new RuntimeException('Staged file escaped its recorded batch');
                }
                $staged_path = fileStagingAbsolutePath($staged_relative);
                $final_path = fileStagingAbsolutePath($final_relative);
                $expected_hash = (string) $operation['file_staging_sha256'];
                $expected_size = intval($operation['file_staging_size']);

                if (is_file($final_path)) {
                    if (filesize($final_path) !== $expected_size
                        || !hash_equals($expected_hash, hash_file('sha256', $final_path))) {
                        throw new RuntimeException('A different file already occupies the final path');
                    }
                    if (is_file($staged_path)) {
                        @unlink($staged_path);
                    }
                } else {
                    if (!is_file($staged_path)
                        || filesize($staged_path) !== $expected_size
                        || !hash_equals($expected_hash, hash_file('sha256', $staged_path))) {
                        throw new RuntimeException('The staged file is missing or failed integrity verification');
                    }
                    $final_directory = dirname($final_path);
                    if (!is_dir($final_directory) && !mkdir($final_directory, 0775, true)
                        && !is_dir($final_directory)) {
                        throw new RuntimeException('Could not create the final upload directory');
                    }
                    if (!rename($staged_path, $final_path)) {
                        throw new RuntimeException('Could not atomically finalize the staged file');
                    }
                }

                if (!mysqli_query($mysqli, "UPDATE file_staging_operations SET
                    file_staging_status = 'Finalized', file_staging_attempts = file_staging_attempts + 1,
                    file_staging_last_error = NULL, file_staging_finalized_at = NOW()
                    WHERE file_staging_id = $operation_id
                    AND file_staging_status IN ('Pending', 'Failed') LIMIT 1")) {
                    throw new RuntimeException('Could not finalize the staging journal');
                }
            } catch (Throwable $error) {
                $success = false;
                $error_sql = mysqli_real_escape_string($mysqli, substr($error->getMessage(), 0, 4000));
                mysqli_query($mysqli, "UPDATE file_staging_operations SET
                    file_staging_status = 'Failed', file_staging_attempts = file_staging_attempts + 1,
                    file_staging_last_error = '$error_sql' WHERE file_staging_id = $operation_id LIMIT 1");
            }
        }
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('$lock_name_sql')");
    }

    $batch_directory = dirname(__DIR__) . '/uploads/.staging/' . $batch_token;
    if (is_dir($batch_directory) && count(scandir($batch_directory)) === 2) {
        @rmdir($batch_directory);
    }
    if (!$success) {
        fileStagingQueueRecovery();
    }
    return $success;
}

/**
 * Best-effort finalization for a batch whose owning database transaction has
 * already committed. This boundary must never turn a durable database success
 * into a false rollback response: failures are logged and handed to recovery.
 */
function fileStagingFinalizeCommittedBatch(string $batch_token, string $context = ''): bool {
    $failure = '';
    try {
        $finalized = fileStagingFinalizeBatch($batch_token);
    } catch (Throwable $error) {
        $finalized = false;
        $failure = $error->getMessage();
    }

    if (!$finalized) {
        try {
            fileStagingQueueRecovery();
        } catch (Throwable $queue_error) {
            $failure = trim($failure . '; recovery wake-up failed: ' . $queue_error->getMessage(), '; ');
        }
        $details = trim($context . ': committed files are queued for recovery'
            . ($failure === '' ? '' : " ($failure)"), ': ');
        try {
            logApp('File Staging', 'warning', $details);
        } catch (Throwable $log_error) {
            error_log('File staging post-commit warning: ' . $details
                . '; application log failed: ' . $log_error->getMessage());
        }
    }
    return $finalized;
}

function fileStagingRecover(int $limit = 100): array {
    global $mysqli;
    $limit = min(500, max(1, $limit));
    $summary = ['finalized' => 0, 'failed' => 0, 'batches' => 0, 'orphans_removed' => 0];
    $batches = mysqli_query($mysqli, "SELECT DISTINCT file_staging_batch_token
        FROM file_staging_operations WHERE file_staging_status IN ('Pending', 'Failed')
        ORDER BY file_staging_created_at ASC LIMIT $limit");
    while ($batches && $batch = mysqli_fetch_assoc($batches)) {
        $summary['batches']++;
        if (fileStagingFinalizeBatch((string) $batch['file_staging_batch_token'])) {
            $summary['finalized']++;
        } else {
            $summary['failed']++;
        }
    }
    mysqli_query($mysqli, "DELETE FROM file_staging_operations
        WHERE file_staging_status = 'Finalized'
        AND file_staging_finalized_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1000");

    $staging_root = dirname(__DIR__) . '/uploads/.staging';
    if (is_dir($staging_root)) {
        foreach (scandir($staging_root) as $entry) {
            if (preg_match('/^[a-f0-9]{64}$/', $entry) !== 1) {
                continue;
            }
            $directory = $staging_root . '/' . $entry;
            if (!is_dir($directory) || filemtime($directory) > time() - 86400) {
                continue;
            }
            $entry_sql = mysqli_real_escape_string($mysqli, $entry);
            $journal_sql = mysqli_query($mysqli, "SELECT COUNT(*)
                FROM file_staging_operations WHERE file_staging_batch_token = '$entry_sql'
                AND file_staging_status IN ('Pending', 'Failed')");
            if (!$journal_sql) {
                continue;
            }
            $journal = mysqli_fetch_row($journal_sql);
            if (intval($journal[0] ?? 0) === 0) {
                removeDirectory($directory);
                $summary['orphans_removed']++;
            }
        }
    }
    return $summary;
}

function saveBase64Images(string $html, string $baseFsPath, string $baseWebPath, int $ownerId,
    ?string $stagingBatchToken = null, string $ownerType = 'document'): string {
    global $mysqli;
    // Normalize paths
    $baseFsPath  = rtrim($baseFsPath, '/\\') . '/';
    $baseWebPath = rtrim(fileStagingRelativePath($baseWebPath), '/\\') . '/';

    $targetDir = $baseFsPath . $ownerId . "/";
    $staging = $stagingBatchToken !== null;
    if ($staging && preg_match('/^[a-f0-9]{64}$/', $stagingBatchToken) !== 1) {
        throw new InvalidArgumentException('Invalid file staging batch token');
    }
    $ownerType = substr(preg_replace('/[^a-z0-9_-]+/i', '_', $ownerType), 0, 40);
    if ($ownerType === '') {
        throw new InvalidArgumentException('A file staging owner type is required');
    }

    $folderCreated = false;   // <-- NEW FLAG
    $savedAny      = false;   // <-- Track if ANY images processed

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $imgs = $dom->getElementsByTagName('img');

    foreach ($imgs as $img) {
        $src = $img->getAttribute('src');

        // Match base64 images
        if (preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.*)$/s', $src, $matches)) {

            $savedAny = true;  // <-- We are actually saving at least 1 image

            // Create folder ONLY when needed
            if (!$folderCreated) {
                $write_directory = $staging
                    ? dirname(__DIR__) . '/uploads/.staging/' . $stagingBatchToken . '/'
                    : $targetDir;
                if (!is_dir($write_directory) && !mkdir($write_directory, 0775, true)
                    && !is_dir($write_directory)) {
                    throw new RuntimeException('Could not create the embedded-image directory');
                }
                $folderCreated = true;
            }

            $mimeType = strtolower($matches[1]);
            $base64   = $matches[2];

            $binary = base64_decode($base64, true);
            if ($binary === false) {
                throw new InvalidArgumentException('An embedded image is not valid base64');
            }

            // Extension mapping
            switch ($mimeType) {
                case 'jpeg':
                case 'jpg': $ext = 'jpg'; break;
                case 'png': $ext = 'png'; break;
                case 'gif': $ext = 'gif'; break;
                case 'webp': $ext = 'webp'; break;
                default: throw new InvalidArgumentException('Embedded image type is not supported');
            }
            if (strlen($binary) > 10 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
                throw new InvalidArgumentException('Embedded image failed size or content validation');
            }

            // Secure random filename
            $uid = bin2hex(random_bytes(16));
            $filename = "img_{$uid}.{$ext}";

            $finalRelative = fileStagingRelativePath($baseWebPath . $ownerId . "/" . $filename);
            $filePath = $staging
                ? dirname(__DIR__) . '/uploads/.staging/' . $stagingBatchToken . '/' . $filename
                : $targetDir . $filename;
            $written = file_put_contents($filePath, $binary, LOCK_EX);
            if ($written === false || $written !== strlen($binary)) {
                throw new RuntimeException('Could not write an embedded image');
            }
            if ($staging) {
                $stagedRelative = "uploads/.staging/$stagingBatchToken/$filename";
                $batch_sql = mysqli_real_escape_string($mysqli, $stagingBatchToken);
                $owner_type_sql = mysqli_real_escape_string($mysqli, $ownerType);
                $staged_sql = mysqli_real_escape_string($mysqli, $stagedRelative);
                $final_sql = mysqli_real_escape_string($mysqli, $finalRelative);
                $sha_sql = hash('sha256', $binary);
                $size = strlen($binary);
                if (!mysqli_query($mysqli, "INSERT INTO file_staging_operations SET
                    file_staging_batch_token = '$batch_sql',
                    file_staging_owner_type = '$owner_type_sql',
                    file_staging_owner_id = " . max(0, $ownerId) . ",
                    file_staging_staged_path = '$staged_sql',
                    file_staging_final_path = '$final_sql',
                    file_staging_sha256 = '$sha_sql', file_staging_size = $size")) {
                    @unlink($filePath);
                    throw new RuntimeException('Could not journal an embedded image');
                }
            }
            $img->setAttribute('src', '/' . $finalRelative);
        }
    }

    // If no images were processed, return original HTML immediately
    if (!$savedAny) {
        return $html;
    }

    // Extract body content only
    $body = $dom->getElementsByTagName('body')->item(0);

    if ($body) {
        $innerHTML = '';
        foreach ($body->childNodes as $child) {
            $innerHTML .= $dom->saveHTML($child);
        }
        return $innerHTML;
    }

    return $html;
}

function cleanupUnusedImages(string $html, string $folderFsPath, string $folderWebPath) {

    $folderFsPath  = rtrim($folderFsPath, '/\\') . '/';
    $folderWebPath = rtrim($folderWebPath, '/\\') . '/';

    if (!is_dir($folderFsPath)) {
        return; // no folder = nothing to delete
    }

    // 1. Get all files currently on disk
    $filesOnDisk = glob($folderFsPath . "*");

    // 2. Find all <img src="">
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);
    $htmlImagePaths = $matches[1] ?? [];

    // Normalize paths: keep only filenames belonging to this template folder
    $referencedFiles = [];

    foreach ($htmlImagePaths as $src) {
        if (strpos($src, $folderWebPath) !== false) {
            $filename = basename($src);
            $referencedFiles[] = $filename;
        }
    }

    // 3. Delete any physical file not referenced in the HTML
    foreach ($filesOnDisk as $filePath) {
        $filename = basename($filePath);

        if (!in_array($filename, $referencedFiles)) {
            unlink($filePath);
        }
    }
}

/*
 * Ticket attachment uploads
 *
 * Shared by the agent ticket page, the new ticket modal and the client portal
 * reply form, so the allowed extension list has one definition rather than one
 * per caller.
 *
 * Files land in uploads/tickets/<ticket id>/ under an unguessable reference name
 * and are only ever served back through the ticket_attachment.php endpoints,
 * which re-check permissions and force a safe Content-Type.
 *
 * Pass $reply_id to attach to a specific reply, or null to attach to the ticket
 * itself - the ticket page reads reply_id IS NULL as "belongs to the ticket".
 *
 * Returns a list of what was stored - ['attachment_id' => database id, 'name' =>
 * original name, 'path' => path relative to the app root, 'size' => bytes] - so
 * a caller can create durable references or pass the same files to the mail
 * queue. Anything the extension allow-list or checkFileUpload() rejects is
 * skipped silently, as it always has been.
 */
function saveTicketAttachments($ticket_id, $reply_id = null, $field_name = 'attachments') {

    global $mysqli;

    $ticket_id = intval($ticket_id);

    $stored_attachments = [];

    if (!$ticket_id || empty($_FILES[$field_name]) || !isset($_FILES[$field_name]['name'])) {
        return $stored_attachments;
    }

    $allowed_extensions = array(
        'jpg', 'jpeg', 'gif', 'png', 'webp', 'pdf', 'txt', 'md', 'doc', 'docx',
        'odt', 'csv', 'xls', 'xlsx', 'ods', 'pptx', 'odp', 'zip', 'tar', 'gz',
        'xml', 'msg', 'json', 'wav', 'mp3', 'ogg', 'mov', 'mp4', 'av1', 'ovpn'
    );

    // A single-file input posts scalars, a multiple one posts arrays - normalize
    $names = $_FILES[$field_name]['name'];
    if (!is_array($names)) {
        return $stored_attachments;
    }

    mkdirMissing('../uploads/tickets/');
    $upload_file_dir = "../uploads/tickets/" . $ticket_id . "/";
    mkdirMissing($upload_file_dir);

    if ($reply_id === null) {
        $reply_id_sql = 'NULL';
    } else {
        $reply_id_sql = intval($reply_id);
    }

    for ($i = 0; $i < count($names); $i++) {

        $single_file = [
            'name' => $_FILES[$field_name]['name'][$i],
            'type' => $_FILES[$field_name]['type'][$i],
            'tmp_name' => $_FILES[$field_name]['tmp_name'][$i],
            'error' => $_FILES[$field_name]['error'][$i],
            'size' => $_FILES[$field_name]['size'][$i]
        ];

        $attachment_reference_name = checkFileUpload($single_file, $allowed_extensions);

        if (!$attachment_reference_name) {
            continue;
        }

        $destination_path = $upload_file_dir . $attachment_reference_name;

        if (!move_uploaded_file($single_file['tmp_name'], $destination_path)) {
            continue;
        }

        $attachment_name = escapeSql($single_file['name']);
        $attachment_reference_name_sql = escapeSql($attachment_reference_name);

        $attachment_insert = mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$attachment_name', ticket_attachment_reference_name = '$attachment_reference_name_sql', ticket_attachment_reply_id = $reply_id_sql, ticket_attachment_ticket_id = $ticket_id");
        $attachment_id = $attachment_insert ? intval(mysqli_insert_id($mysqli)) : 0;
        if (!$attachment_insert || mysqli_affected_rows($mysqli) !== 1 || !$attachment_id) {
            error_log("Ticket $ticket_id attachment metadata insert failed: " . mysqli_error($mysqli));
            if (is_file($destination_path) && !unlink($destination_path)) {
                error_log("Ticket $ticket_id attachment file cleanup failed after metadata insert failure: $destination_path");
            }
            continue;
        }

        // Path is relative to the app root, not the caller, so the mail cron can
        // resolve it from its own directory
        $stored_attachments[] = [
            'attachment_id' => $attachment_id,
            'name' => $single_file['name'],
            'path' => "uploads/tickets/$ticket_id/$attachment_reference_name",
            'size' => (int) $single_file['size']
        ];
    }

    return $stored_attachments;
}

/*
 * Remove only the physical files from a saveTicketAttachments() result. This is
 * used when a caller-owned database transaction rolls back the corresponding
 * metadata rows. Every resolved path is confined to uploads/tickets.
 */
function cleanupStoredTicketAttachmentFiles($stored_attachments) {

    $uploads_base = realpath(__DIR__ . '/../uploads/tickets');
    if ($uploads_base === false) {
        return;
    }
    $uploads_prefix = rtrim($uploads_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    foreach ((array) $stored_attachments as $stored_attachment) {
        $relative_path = ltrim((string) ($stored_attachment['path'] ?? ''), '/\\');
        if ($relative_path === '' || strpos($relative_path, "\0") !== false) {
            continue;
        }

        $file_path = realpath(__DIR__ . '/../' . $relative_path);
        if ($file_path === false) {
            continue;
        }
        if (strpos($file_path, $uploads_prefix) !== 0) {
            error_log("Refused to clean up a ticket attachment outside uploads/tickets: $file_path");
            continue;
        }
        if (is_file($file_path) && !unlink($file_path)) {
            error_log("Ticket attachment rollback file cleanup failed: $file_path");
        }
    }
}

/*
 * Splits a stored attachment list into what the mail queue will carry and what it
 * will not, against MAX_EMAIL_ATTACHMENT_BYTES applied to the message as a whole.
 *
 * Oversized files stay on the ticket - the recipient can still download them from
 * the portal - and the caller is expected to say so rather than leaving the agent
 * to assume everything went out.
 *
 * Returns ['send' => [...], 'skipped' => [...]] in the input order.
 */
function filterEmailableAttachments($attachments) {

    $result = ['send' => [], 'skipped' => []];
    $running_total = 0;

    foreach ($attachments as $attachment) {
        $size = intval($attachment['size'] ?? 0);

        if ($size > 0 && $running_total + $size <= MAX_EMAIL_ATTACHMENT_BYTES) {
            $running_total += $size;
            $result['send'][] = $attachment;
        } else {
            $result['skipped'][] = $attachment;
        }
    }

    return $result;
}

/*
 * FontAwesome icon name for a file extension.
 *
 * Lived in client/functions.php until agent/files.php needed the same mapping
 * for its gallery view. The portal reaches this file through the root
 * functions.php it already loads, so moving it up costs the portal nothing and
 * leaves one list of extensions rather than two to drift apart.
 */
function getFileIcon($file_extension) {
    $file_extension = strtolower($file_extension);

    // Document icons
    if (in_array($file_extension, ['pdf'])) {
        return 'file-pdf';
    } elseif (in_array($file_extension, ['doc', 'docx'])) {
        return 'file-word';
    } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
        return 'file-excel';
    } elseif (in_array($file_extension, ['ppt', 'pptx'])) {
        return 'file-powerpoint';
    } elseif (in_array($file_extension, ['txt', 'md', 'rtf'])) {
        return 'file-alt';
    } elseif (in_array($file_extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
        return 'file-archive';
    } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
        return 'file-image';
    } elseif (in_array($file_extension, ['mp4', 'avi', 'mov', 'wmv', 'flv'])) {
        return 'file-video';
    } elseif (in_array($file_extension, ['mp3', 'wav', 'ogg', 'flac'])) {
        return 'file-audio';
    } elseif (in_array($file_extension, ['html', 'htm', 'css', 'js', 'php', 'py', 'java'])) {
        return 'file-code';
    } else {
        return 'file';
    }
}

/*
 * MIME types safe to render inline in a browser.
 *
 * Anything not on this list is served Content-Disposition: attachment - HTML
 * and SVG in particular are stored-XSS vectors when a browser renders them from
 * our own origin. Four file-serving entry points enforced it from four
 * identical copies of the array; the gallery preview in agent/files.php has to
 * agree with them about what it can frame, so the list is defined once here.
 *
 * application/json is on the list because a browser renders it as text, not as
 * markup, and every serving point sends X-Content-Type-Options: nosniff so it
 * cannot be re-interpreted as HTML.
 */
function getInlineViewableMimeTypes() {
    return [
        "application/pdf",
        "image/png",
        "image/jpeg",
        "image/gif",
        "image/webp",
        "text/plain",
        "application/json"
    ];
}

/*
 * Tidies raw text into something worth showing in a preview tile.
 *
 * Two callers, two different messes, one clean-up:
 *
 *   documents  - document_content_raw is TinyMCE HTML that has been through
 *                strip_tags(), which removes the TAGS and leaves the ENTITIES.
 *                A paragraph of "Hello&nbsp;world &amp; friends" arrives with
 *                those sequences intact, and escaping it for output turned them
 *                into a literal &amp;nbsp; on screen. Decode first, escape last.
 *
 *   text files - whatever bytes are on disk: a UTF-8 BOM that renders as a
 *                stray glyph, CRLF line endings, stray control characters, and
 *                the possibility that a file claiming text/plain is not text.
 *
 * Returns '' when there is nothing worth showing, so callers fall back to the
 * file-type icon rather than printing noise.
 */
function cleanTextExcerpt($text, $length = 400) {
    $text = (string) $text;

    // A BOM is invisible metadata to a text editor and a stray glyph in HTML
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

    // Entities left behind by strip_tags(), plus TinyMCE's non-breaking spaces,
    // which are U+00A0 once decoded and read as odd gaps
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);

    // Drop anything that is not valid UTF-8 rather than letting a half-decoded
    // byte render as a replacement character
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // Control characters, keeping the two that carry meaning in a preview.
    // No /u modifier on purpose: these are all single-byte ASCII, and every
    // continuation byte of a multi-byte character is >= 0x80, so a byte-wise
    // strip cannot damage one. With /u the whole call returns null the moment
    // the subject holds a stray invalid byte, blanking the excerpt instead of
    // cleaning it.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Stripped HTML leaves long runs of blank lines and indentation
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{2,}/', "\n", $text);
    $text = trim($text);

    // Cut on a character boundary - a byte-wise substr can split a multi-byte
    // character and leave a broken glyph at the end of every tile
    if (mb_strlen($text, 'UTF-8') > $length) {
        $text = mb_substr($text, 0, $length, 'UTF-8') . '...';
    }

    return (string) $text;
}

/*
 * First few hundred characters of a text file, for a grid tile preview.
 *
 * Reads a bounded chunk rather than the whole file - a 40MB log has no business
 * being loaded into a page that shows two dozen tiles - and re-applies the same
 * realpath containment check the file-serving endpoints use, because the caller
 * is building a path from a database column.
 *
 * Returns '' when the file is missing, unreadable, escapes uploads/, or does
 * not look like text at all. The last case matters: a mislabelled binary served
 * as text/plain would otherwise fill the tile with garbage.
 */
function getFileTextExcerpt($client_id, $file_reference_name, $length = 400) {
    $client_id = intval($client_id);

    $uploads_base = realpath(__DIR__ . "/../uploads");
    $file_path = realpath(__DIR__ . "/../uploads/clients/$client_id/$file_reference_name");

    if ($file_path === false || $uploads_base === false || strpos($file_path, $uploads_base) !== 0) {
        return '';
    }

    if (!is_file($file_path) || !is_readable($file_path)) {
        return '';
    }

    $handle = fopen($file_path, 'rb');
    if ($handle === false) {
        return '';
    }
    // Read well past the target so cleaning and the character-boundary cut
    // still have a full excerpt to work with
    $raw = fread($handle, $length * 4);
    fclose($handle);

    if ($raw === false || $raw === '') {
        return '';
    }

    // Does this actually look like text? Count the bytes no text file should
    // carry; a few percent is a mislabelled binary, not a stray character.
    $control_bytes = strlen(preg_replace('/[^\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw));
    if ($control_bytes > 0 && ($control_bytes / strlen($raw)) > 0.05) {
        return '';
    }

    return cleanTextExcerpt($raw, $length);
}
