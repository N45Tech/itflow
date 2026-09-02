<?php

/*
 * Recoverable deletion, retention locks, immutable lifecycle events, payload
 * minimization, and previewed permanent purge.
 *
 * Security invariants:
 *  - web/API callers must pass the canonical administrator gate before any
 *    delete, restore, hold, policy, approval, or purge mutation;
 *  - a soft delete changes visibility only and never removes child history;
 *  - every lifecycle transition and blocked purge is append-only audited;
 *  - permanent purge is possible only from a captured preview batch;
 *  - legal/client/record holds and immutable workflow evidence fail closed;
 *  - files are confined to uploads/.retention-quarantine before metadata can
 *    become purgeable, and cleanup remains retryable from the durable ledger.
 */

function retentionDbQuery(string $sql, string $context)
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

function retentionDbEscape(string $value): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, $value);
}

function retentionRequireAdministratorActor(int $actor_id): void
{
    if ($actor_id < 1) {
        throw new DomainException('An authenticated administrator is required');
    }
    $count = retentionCount("SELECT COUNT(*) FROM users
        INNER JOIN user_roles ON role_id = user_role_id
        WHERE user_id = $actor_id AND user_type = 1 AND user_status = 1
        AND user_archived_at IS NULL AND role_is_admin = 1",
        'Could not verify the retention administrator');
    if ($count !== 1) {
        throw new DomainException('Retention lifecycle mutations are administrator-only');
    }
}

function retentionRecordPolicyKey(string $record_type): string
{
    $map = [
        'ticket' => 'tickets',
        'file' => 'files',
        'attachment' => 'attachments',
        'automation-event' => 'automation_payloads',
        'normalized-payload' => 'normalized_payloads',
        'task-evidence' => 'evidence',
        'documentation-evidence' => 'evidence',
    ];
    if (!isset($map[$record_type])) {
        throw new InvalidArgumentException('Unsupported retained record type');
    }
    return $map[$record_type];
}

function retentionPolicy(string $policy_key, bool $for_update = false): array
{
    $policy_key_sql = retentionDbEscape($policy_key);
    $lock_sql = $for_update ? ' FOR UPDATE' : '';
    $row = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_policies
        WHERE retention_policy_key = '$policy_key_sql' LIMIT 1$lock_sql", 'Could not load the retention policy'));
    if (!$row) {
        throw new RuntimeException("Retention policy '$policy_key' is unavailable");
    }
    $mode = strtolower((string) $row['retention_policy_purge_mode']);
    if (!in_array($mode, ['disabled', 'manual', 'automatic'], true)) {
        throw new RuntimeException("Retention policy '$policy_key' has an invalid purge mode");
    }
    $retention_days = intval($row['retention_policy_retention_days']);
    $restore_window_days = intval($row['retention_policy_restore_window_days']);
    if ($retention_days < 1 || $restore_window_days < 0) {
        throw new RuntimeException("Retention policy '$policy_key' has an unsafe retention window");
    }
    return [
        'key' => (string) $row['retention_policy_key'],
        'label' => (string) $row['retention_policy_label'],
        'retention_days' => $retention_days,
        'restore_window_days' => $restore_window_days,
        'purge_mode' => $mode,
        'owner_note' => (string) $row['retention_policy_owner_note'],
        'updated_by' => intval($row['retention_policy_updated_by']),
        'updated_at' => $row['retention_policy_updated_at'],
    ];
}

function retentionUpdatePolicy(
    string $policy_key,
    int $retention_days,
    int $restore_window_days,
    string $purge_mode,
    string $owner_note,
    int $actor_id,
    bool $automatic_confirmed = false
): void {
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    if ($retention_days < 1 || $retention_days > 36500
        || $restore_window_days < 0 || $restore_window_days > 3650) {
        throw new InvalidArgumentException('Retention or restore window is outside the supported safe range');
    }
    $purge_mode = strtolower(trim($purge_mode));
    $owner_note = trim($owner_note);
    if (!in_array($purge_mode, ['disabled', 'manual', 'automatic'], true)) {
        throw new InvalidArgumentException('Select a valid purge mode');
    }
    if ($owner_note === '') {
        throw new InvalidArgumentException('Record the owner decision behind this retention policy');
    }
    if ($purge_mode === 'automatic' && !$automatic_confirmed) {
        throw new DomainException('Automatic purge requires the explicit automatic-purge confirmation');
    }
    $key_sql = retentionDbEscape($policy_key);
    $mode_sql = retentionDbEscape($purge_mode);
    $note_sql = retentionDbEscape(substr($owner_note, 0, 500));
    $actor_id = max(0, $actor_id);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the retention policy transaction');
    }
    try {
        // Serialize owner decisions so the immutable event's "from" snapshot
        // cannot be stale or lose a concurrent administrator edit.
        $policy = retentionPolicy($policy_key, true);
        // Evidence and approvals remain an invariant lock even if an owner
        // changes their nominal review period. A policy edit cannot weaken it.
        if ($policy_key === 'evidence' && $purge_mode !== 'disabled') {
            throw new DomainException('Evidence and approval history cannot be configured for permanent purge');
        }
        if (in_array($policy_key, ['tickets', 'files', 'attachments'], true)
            && $purge_mode === 'automatic') {
            throw new DomainException('Operational records require a dry-run and typed administrator purge approval');
        }
        if (in_array($policy_key, ['automation_payloads', 'normalized_payloads'], true)
            && $purge_mode === 'manual') {
            throw new DomainException('Payload minimization must be either disabled or automatic');
        }
        retentionDbQuery("UPDATE retention_policies SET
            retention_policy_retention_days = $retention_days,
            retention_policy_restore_window_days = $restore_window_days,
            retention_policy_purge_mode = '$mode_sql',
            retention_policy_owner_note = '$note_sql',
            retention_policy_updated_by = $actor_id
            WHERE retention_policy_key = '$key_sql' LIMIT 1", 'Could not update the retention policy');
        if (mysqli_affected_rows($mysqli) > 1) {
            throw new RuntimeException('The retention policy update escaped its key scope');
        }
        retentionAppendEvent('policy', 0, 0, 0, 'policy_updated', 'admin', $actor_id, $owner_note, [
            'policy_key' => $policy_key,
            'from' => $policy,
            'to' => [
                'retention_days' => $retention_days,
                'restore_window_days' => $restore_window_days,
                'purge_mode' => $purge_mode,
            ],
        ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the retention policy update');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionCanonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = retentionCanonicalize($item);
    }
    return $value;
}

function retentionAppendEvent(
    string $record_type,
    int $record_id,
    int $client_id,
    int $generation,
    string $action,
    string $actor_type,
    int $actor_id,
    ?string $reason = null,
    array $metadata = [],
    int $batch_id = 0
): int {
    $metadata_json = json_encode(retentionCanonicalize($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metadata_json === false) {
        throw new RuntimeException('Could not serialize immutable retention event metadata');
    }
    $type_sql = retentionDbEscape(substr($record_type, 0, 40));
    $action_sql = retentionDbEscape(substr($action, 0, 40));
    $actor_type_sql = retentionDbEscape(substr($actor_type, 0, 20));
    $reason_sql = $reason === null ? 'NULL' : "'" . retentionDbEscape(substr($reason, 0, 500)) . "'";
    $metadata_sql = retentionDbEscape($metadata_json);
    $metadata_hash = hash('sha256', $metadata_json);
    $record_id = max(0, $record_id);
    $client_id = max(0, $client_id);
    $generation = max(0, $generation);
    $actor_id = max(0, $actor_id);
    $batch_id = max(0, $batch_id);
    retentionDbQuery("INSERT INTO retention_events SET
        retention_event_record_type = '$type_sql',
        retention_event_record_id = $record_id,
        retention_event_client_id = $client_id,
        retention_event_generation = $generation,
        retention_event_action = '$action_sql',
        retention_event_actor_type = '$actor_type_sql',
        retention_event_actor_id = $actor_id,
        retention_event_reason = $reason_sql,
        retention_event_metadata = '$metadata_sql',
        retention_event_metadata_hash = '$metadata_hash',
        retention_event_batch_id = $batch_id", 'Could not append the immutable retention event');
    return intval(mysqli_insert_id($GLOBALS['mysqli']));
}

function retentionDeadline(?string $anchor, int $retention_days, int $restore_window_days): array
{
    $now = new DateTimeImmutable('now');
    try {
        $anchor_at = $anchor ? new DateTimeImmutable($anchor) : $now;
    } catch (Throwable $e) {
        $anchor_at = $now;
    }
    $restore_until = $restore_window_days > 0
        ? $now->modify('+' . $restore_window_days . ' days') : null;
    $purge_eligible = $anchor_at->modify('+' . max(1, $retention_days) . ' days');
    if ($purge_eligible < $now) {
        $purge_eligible = $now;
    }
    if ($restore_until !== null && $purge_eligible < $restore_until) {
        $purge_eligible = $restore_until;
    }
    return [
        'deleted_at' => $now->format('Y-m-d H:i:s'),
        'restore_until' => $restore_until?->format('Y-m-d H:i:s'),
        'purge_eligible_at' => $purge_eligible->format('Y-m-d H:i:s'),
    ];
}

function retentionWriteDeletionLedger(
    string $record_type,
    int $record_id,
    int $client_id,
    string $label,
    int $actor_id,
    string $reason,
    array $deadline,
    ?string $quarantine_path = null,
    string $quarantine_status = 'none',
    ?string $quarantine_manifest = null
): int {
    $type_sql = retentionDbEscape($record_type);
    $label_sql = retentionDbEscape(substr($label, 0, 500));
    $reason_sql = retentionDbEscape(substr($reason, 0, 500));
    $restore_sql = empty($deadline['restore_until']) ? 'NULL' : "'" . retentionDbEscape($deadline['restore_until']) . "'";
    $deleted_sql = retentionDbEscape($deadline['deleted_at']);
    $purge_sql = retentionDbEscape($deadline['purge_eligible_at']);
    $path_sql = $quarantine_path === null ? 'NULL' : "'" . retentionDbEscape($quarantine_path) . "'";
    $status_sql = retentionDbEscape($quarantine_status);
    $manifest_sql = $quarantine_manifest === null ? 'NULL'
        : "'" . retentionDbEscape($quarantine_manifest) . "'";
    $manifest_hash_sql = $quarantine_manifest === null ? 'NULL'
        : "'" . hash('sha256', $quarantine_manifest) . "'";
    $existing = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_generation
        FROM retention_deletions WHERE retention_deletion_record_type = '$type_sql'
        AND retention_deletion_record_id = $record_id LIMIT 1 FOR UPDATE", 'Could not lock the deletion ledger'));
    $generation = intval($existing['retention_deletion_generation'] ?? 0) + 1;
    retentionDbQuery("INSERT INTO retention_deletions SET
        retention_deletion_record_type = '$type_sql',
        retention_deletion_record_id = $record_id,
        retention_deletion_client_id = $client_id,
        retention_deletion_generation = $generation,
        retention_deletion_label = '$label_sql',
        retention_deletion_deleted_by = $actor_id,
        retention_deletion_reason = '$reason_sql',
        retention_deletion_deleted_at = '$deleted_sql',
        retention_deletion_restore_until = $restore_sql,
        retention_deletion_purge_eligible_at = '$purge_sql',
        retention_deletion_quarantine_path = $path_sql,
        retention_deletion_quarantine_status = '$status_sql',
        retention_deletion_quarantine_manifest = $manifest_sql,
        retention_deletion_quarantine_manifest_hash = $manifest_hash_sql,
        retention_deletion_quarantine_prepared_at =
            CASE WHEN '$status_sql' = 'move_pending' THEN NOW() ELSE NULL END
        ON DUPLICATE KEY UPDATE
        retention_deletion_client_id = VALUES(retention_deletion_client_id),
        retention_deletion_generation = VALUES(retention_deletion_generation),
        retention_deletion_label = VALUES(retention_deletion_label),
        retention_deletion_deleted_by = VALUES(retention_deletion_deleted_by),
        retention_deletion_reason = VALUES(retention_deletion_reason),
        retention_deletion_deleted_at = VALUES(retention_deletion_deleted_at),
        retention_deletion_restore_until = VALUES(retention_deletion_restore_until),
        retention_deletion_purge_eligible_at = VALUES(retention_deletion_purge_eligible_at),
        retention_deletion_restored_by = 0,
        retention_deletion_restored_at = NULL,
        retention_deletion_purged_by = 0,
        retention_deletion_purged_at = NULL,
        retention_deletion_quarantine_path = VALUES(retention_deletion_quarantine_path),
        retention_deletion_quarantine_status = VALUES(retention_deletion_quarantine_status),
        retention_deletion_quarantine_manifest = VALUES(retention_deletion_quarantine_manifest),
        retention_deletion_quarantine_manifest_hash = VALUES(retention_deletion_quarantine_manifest_hash),
        retention_deletion_quarantine_prepared_at = VALUES(retention_deletion_quarantine_prepared_at),
        retention_deletion_quarantine_claim_token = NULL,
        retention_deletion_quarantine_attempted_at = NULL,
        retention_deletion_quarantine_completed_at = NULL,
        retention_deletion_restore_pending_by = 0,
        retention_deletion_restore_pending_reason = NULL,
        retention_deletion_restore_prepared_at = NULL,
        retention_deletion_last_error = NULL", 'Could not write the recoverable-deletion ledger');
    return $generation;
}

function retentionValidateDeleteReason(string $reason): string
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        throw new InvalidArgumentException('Provide a specific deletion reason of at least 10 characters');
    }
    return mb_substr($reason, 0, 500);
}

function retentionAdvanceTicketVersion(int $ticket_id, int $client_id): void
{
    global $mysqli;
    // Field Mode uses ticket_updated_at as one optimistic-version component.
    // GREATEST(now,+1s) prevents delete/restore ABA even when both transitions
    // happen inside the same database timestamp second.
    retentionDbQuery("UPDATE tickets SET ticket_updated_at = GREATEST(NOW(),
        DATE_ADD(COALESCE(ticket_updated_at, ticket_created_at, NOW()), INTERVAL 1 SECOND))
        WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1",
        'Could not advance the retained ticket version');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The retained ticket changed before its version advanced');
    }
}

function retentionMutateScopedFileRelation(string $target_type, int $target_id,
    int $file_id, int $expected_client_id, bool $link): array
{
    global $mysqli;
    $targets = [
        'asset' => ['assets', 'asset_id', 'asset_client_id', 'asset_archived_at', 'asset_name',
            'asset_files', 'asset_id'],
        'contact' => ['contacts', 'contact_id', 'contact_client_id', 'contact_archived_at', 'contact_name',
            'contact_files', 'contact_id'],
    ];
    if (!isset($targets[$target_type]) || $target_id < 1 || $file_id < 1 || $expected_client_id < 1) {
        throw new InvalidArgumentException('A valid client-scoped file relation is required');
    }
    [$table, $id_column, $client_column, $archived_column, $name_column,
        $relation_table, $relation_target_column] = $targets[$target_type];
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the client-scoped file relation mutation');
    }
    try {
        documentationLockClient($expected_client_id);
        $file = mysqli_fetch_assoc(retentionDbQuery("SELECT file_id, file_name, file_client_id
            FROM files WHERE file_id = $file_id AND file_client_id = $expected_client_id
            AND file_deleted_at IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the active file relation target'));
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT $id_column AS target_id,
            $name_column AS target_name, $client_column AS client_id FROM $table
            WHERE $id_column = $target_id AND $client_column = $expected_client_id
            AND $archived_column IS NULL LIMIT 1 FOR UPDATE",
            'Could not lock the active client relation target'));
        if (!$file || !$target || intval($file['file_client_id']) !== intval($target['client_id'])) {
            throw new DomainException('The active file and relation target must belong to the same authorized client');
        }
        if ($link) {
            retentionDbQuery("INSERT IGNORE INTO $relation_table ($relation_target_column, file_id)
                SELECT t.$id_column, f.file_id FROM $table t
                INNER JOIN files f ON f.file_client_id = t.$client_column
                WHERE t.$id_column = $target_id AND t.$client_column = $expected_client_id
                AND t.$archived_column IS NULL AND f.file_id = $file_id
                AND f.file_client_id = $expected_client_id AND f.file_deleted_at IS NULL",
                'Could not create the client-scoped file relation');
        } else {
            retentionDbQuery("DELETE r FROM $relation_table r
                INNER JOIN $table t ON t.$id_column = r.$relation_target_column
                INNER JOIN files f ON f.file_id = r.file_id
                    AND f.file_client_id = t.$client_column
                WHERE r.$relation_target_column = $target_id AND r.file_id = $file_id
                AND t.$client_column = $expected_client_id AND t.$archived_column IS NULL
                AND f.file_client_id = $expected_client_id AND f.file_deleted_at IS NULL",
                'Could not remove the client-scoped file relation');
        }
        $changed = mysqli_affected_rows($mysqli) === 1;
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the client-scoped file relation mutation');
        }
        return [
            'client_id' => $expected_client_id,
            'file_name' => (string) $file['file_name'],
            'target_name' => (string) $target['target_name'],
            'changed' => $changed,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionUploadsRoot(): string
{
    $root = dirname(__DIR__) . '/uploads';
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('The uploads root is unavailable');
    }
    if (is_link($root)) {
        throw new RuntimeException('The uploads root cannot be a symbolic link');
    }
    $real = realpath($root);
    if ($real === false || str_replace('\\', '/', $real) !== str_replace('\\', '/', $root)) {
        throw new RuntimeException('The uploads root did not resolve to its canonical path');
    }
    return $real;
}

function retentionPathIsWithin(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    return $path !== $root && str_starts_with($path, $root . '/');
}

function retentionAssertNoSymlinkComponents(string $path, string $root, bool $allow_missing = false): void
{
    $root_real = realpath($root);
    if ($root_real === false || is_link($root_real)) {
        throw new RuntimeException('The retention storage root is unsafe');
    }
    $path = str_replace('\\', '/', $path);
    $root_real = rtrim(str_replace('\\', '/', $root_real), '/');
    if (!retentionPathIsWithin($path, $root_real)) {
        throw new RuntimeException('Retention refused a path outside its storage root');
    }
    $relative = substr($path, strlen($root_real) + 1);
    $parts = explode('/', $relative);
    $cursor = $root_real;
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Retention refused an ambiguous storage path');
        }
        $cursor .= '/' . $part;
        $stat = @lstat($cursor);
        if ($stat === false) {
            if ($allow_missing) {
                return;
            }
            throw new RuntimeException('A retention storage path component is unavailable');
        }
        if (($stat['mode'] & 0170000) === 0120000 || is_link($cursor)) {
            throw new RuntimeException('Retention refused a symbolic-link storage path');
        }
    }
}

function retentionResolveReadableUploadFile(string $scope, string $reference): ?string
{
    $scope = str_replace('\\', '/', trim($scope, '/\\'));
    if (!preg_match('#^(clients|tickets)/[1-9][0-9]*$#D', $scope)
        || $reference === '' || str_contains($reference, "\0")
        || basename($reference) !== $reference || $reference === '.' || $reference === '..') {
        return null;
    }
    $uploads = retentionUploadsRoot();
    $scope_path = $uploads . '/' . $scope;
    $scope_stat = @lstat($scope_path);
    if ($scope_stat === false || ($scope_stat['mode'] & 0170000) !== 0040000 || is_link($scope_path)) {
        return null;
    }
    retentionAssertNoSymlinkComponents($scope_path, $uploads);
    $scope_real = realpath($scope_path);
    if ($scope_real === false || str_replace('\\', '/', $scope_real) !== $scope_path) {
        return null;
    }
    $candidate = $scope_path . '/' . $reference;
    $stat = @lstat($candidate);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || is_link($candidate)) {
        return null;
    }
    retentionAssertNoSymlinkComponents($candidate, $scope_real);
    $real = realpath($candidate);
    if ($real === false || str_replace('\\', '/', $real) !== $candidate
        || !retentionPathIsWithin($real, $scope_real) || !is_readable($real)) {
        return null;
    }
    return $real;
}

function retentionEnsureQuarantineDirectory(string $directory): void
{
    $root = retentionQuarantineRoot();
    if (!retentionPathIsWithin($directory, $root)) {
        throw new RuntimeException('Retention refused an out-of-scope quarantine directory');
    }
    $relative = substr(str_replace('\\', '/', $directory), strlen(str_replace('\\', '/', $root)) + 1);
    $cursor = $root;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Retention refused an ambiguous quarantine directory');
        }
        $cursor .= '/' . $part;
        $stat = @lstat($cursor);
        if ($stat === false) {
            if (!mkdir($cursor, 0700) && !is_dir($cursor)) {
                throw new RuntimeException('Could not prepare the retention quarantine target');
            }
            $stat = @lstat($cursor);
        }
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || is_link($cursor)) {
            throw new RuntimeException('The retention quarantine target is not a safe directory');
        }
    }
}

function retentionQuarantineRoot(): string
{
    $root = retentionUploadsRoot() . '/.retention-quarantine';
    if (@lstat($root) === false && !mkdir($root, 0700) && !is_dir($root)) {
        throw new RuntimeException('The retention quarantine is unavailable');
    }
    $stat = @lstat($root);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || is_link($root)) {
        throw new RuntimeException('The retention quarantine root is unsafe');
    }
    $real = realpath($root);
    if ($real === false || str_replace('\\', '/', $real) !== str_replace('\\', '/', $root)) {
        throw new RuntimeException('The retention quarantine root is not canonical');
    }
    @chmod($real, 0700);

    // uploads/.htaccess already denies direct access. Keep a second denial at
    // the quarantine boundary so a narrower future uploads rule cannot expose
    // retained bytes through Apache-compatible deployments.
    $deny_path = $real . '/.htaccess';
    $deny_rule = "Options -Indexes\nRequire all denied\n";
    if (is_link($deny_path)) {
        throw new RuntimeException('The quarantine web-denial file is unsafe');
    }
    if (!is_file($deny_path)) {
        if (file_put_contents($deny_path, $deny_rule, LOCK_EX) !== strlen($deny_rule)) {
            throw new RuntimeException('Could not deny direct web access to the retention quarantine');
        }
    } elseif (!str_contains((string) file_get_contents($deny_path), 'Require all denied')) {
        throw new RuntimeException('The retention quarantine web-denial rule is invalid');
    }
    @chmod($deny_path, 0600);
    return $real;
}

function retentionPrepareQuarantinePlan(string $record_type, int $record_id, array $source_paths): array
{
    $record_dir = strtolower(trim($record_type));
    if (!in_array($record_dir, ['file', 'attachment'], true) || $record_id < 1) {
        throw new InvalidArgumentException('Unsupported quarantine record target');
    }
    if (!$source_paths) {
        throw new RuntimeException('The primary upload reference is unavailable; deletion was refused');
    }
    $uploads = retentionUploadsRoot();
    $quarantine = retentionQuarantineRoot();
    $token = date('YmdHis') . '-' . bin2hex(random_bytes(32));
    $relative = "$record_dir/$record_id/$token";
    $files = [];
    $names = [];
    foreach (array_values($source_paths) as $index => $source) {
        $source = str_replace('\\', '/', (string) $source);
        if (!retentionPathIsWithin($source, $uploads)
            || retentionPathIsWithin($source, $quarantine)) {
            throw new RuntimeException('Retention refused a source outside the canonical uploads tree');
        }
        $stat = @lstat($source);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || is_link($source)) {
            $role = $index === 0 ? 'primary' : 'declared derivative';
            throw new RuntimeException("The $role upload bytes are unavailable; deletion was refused");
        }
        retentionAssertNoSymlinkComponents($source, $uploads);
        $real = realpath($source);
        if ($real === false || str_replace('\\', '/', $real) !== $source) {
            throw new RuntimeException('Retention refused a non-canonical upload source');
        }
        $name = basename($source);
        if ($name === '' || $name === '.' || $name === '..' || isset($names[$name])) {
            throw new RuntimeException('Retention refused an unsafe or duplicate upload filename');
        }
        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new RuntimeException('Could not hash the upload before preparing its recoverable deletion');
        }
        $names[$name] = true;
        $files[] = [
            'name' => $name,
            'role' => $index === 0 ? 'primary' : 'derivative',
            'source_relative' => substr($source, strlen($uploads) + 1),
            'sha256' => $hash,
            'size' => intval($stat['size']),
        ];
    }
    $manifest_data = retentionCanonicalize([
        'version' => 1,
        'record_type' => $record_dir,
        'record_id' => $record_id,
        'quarantine_relative_path' => $relative,
        'files' => $files,
    ]);
    $manifest = json_encode($manifest_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($manifest === false) {
        throw new RuntimeException('Could not serialize the exact quarantine manifest');
    }
    return [
        'relative_path' => $relative,
        'manifest' => $manifest,
        'manifest_hash' => hash('sha256', $manifest),
        'files' => $files,
    ];
}

function retentionDecodeQuarantineManifest(?string $manifest, ?string $expected_hash = null): array
{
    if ($manifest === null || $manifest === ''
        || ($expected_hash !== null && !hash_equals($expected_hash, hash('sha256', $manifest)))) {
        throw new RuntimeException('The exact quarantine manifest is unavailable or failed integrity validation');
    }
    $decoded = json_decode($manifest, true);
    if (!is_array($decoded) || intval($decoded['version'] ?? 0) !== 1
        || !in_array($decoded['record_type'] ?? '', ['file', 'attachment'], true)
        || intval($decoded['record_id'] ?? 0) < 1 || empty($decoded['files'])
        || !is_array($decoded['files'])) {
        throw new RuntimeException('The stored quarantine manifest is invalid');
    }
    $primary_count = 0;
    $seen = [];
    foreach ($decoded['files'] as $file) {
        $name = (string) ($file['name'] ?? '');
        $source_relative = str_replace('\\', '/', (string) ($file['source_relative'] ?? ''));
        $role = (string) ($file['role'] ?? '');
        if ($name === '' || basename($name) !== $name || isset($seen[$name])
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($file['sha256'] ?? ''))
            || intval($file['size'] ?? -1) < 0
            || !in_array($role, ['primary', 'derivative'], true)
            || $source_relative === '' || str_starts_with($source_relative, '/')
            || str_contains('/' . $source_relative . '/', '/../')
            || str_contains('/' . $source_relative . '/', '/./')) {
            throw new RuntimeException('The stored quarantine file manifest is unsafe');
        }
        $seen[$name] = true;
        $primary_count += $role === 'primary' ? 1 : 0;
    }
    if ($primary_count !== 1) {
        throw new RuntimeException('The quarantine manifest must identify exactly one primary upload');
    }
    return $decoded;
}

function retentionMovePreparedQuarantine(array $plan, ?callable $move_file = null): array
{
    $move_file = $move_file ?? static fn (string $source, string $target): bool => rename($source, $target);
    $manifest = retentionDecodeQuarantineManifest(
        (string) ($plan['manifest'] ?? ''),
        isset($plan['manifest_hash']) ? (string) $plan['manifest_hash'] : null
    );
    $relative = (string) ($manifest['quarantine_relative_path'] ?? '');
    if (!hash_equals((string) ($plan['relative_path'] ?? ''), $relative)) {
        throw new RuntimeException('The quarantine plan path does not match its manifest');
    }
    $target_dir = retentionQuarantineAbsolute($relative);
    if ($target_dir === null) {
        throw new RuntimeException('The prepared quarantine path is unavailable');
    }
    retentionEnsureQuarantineDirectory($target_dir);
    $uploads = retentionUploadsRoot();
    $moved = [];
    foreach ($manifest['files'] as $file) {
        $source = $uploads . '/' . $file['source_relative'];
        $target = $target_dir . '/' . $file['name'];
        if (!retentionPathIsWithin($source, $uploads)
            || retentionPathIsWithin($source, retentionQuarantineRoot())) {
            throw new RuntimeException('The prepared upload source escaped canonical storage');
        }
        $source_stat = @lstat($source);
        $target_stat = @lstat($target);
        if ($source_stat !== false && $target_stat !== false) {
            throw new RuntimeException('Both live and quarantined upload bytes exist; manual review is required');
        }
        if ($source_stat === false && $target_stat === false) {
            throw new RuntimeException('Expected upload bytes are missing from both live and quarantine storage');
        }
        if ($source_stat !== false) {
            retentionAssertNoSymlinkComponents($source, $uploads);
            if (($source_stat['mode'] & 0170000) !== 0100000 || is_link($source)
                || intval($source_stat['size']) !== intval($file['size'])
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $source))) {
                throw new RuntimeException('Live upload bytes changed after the deletion manifest was committed');
            }
            if (!$move_file($source, $target)) {
                throw new RuntimeException('Could not move an upload into retention quarantine');
            }
            $moved[$source] = $target;
            $target_stat = @lstat($target);
        }
        retentionAssertNoSymlinkComponents($target, retentionQuarantineRoot());
        if ($target_stat === false || ($target_stat['mode'] & 0170000) !== 0100000 || is_link($target)
            || intval($target_stat['size']) !== intval($file['size'])
            || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $target))) {
            throw new RuntimeException('Quarantined upload bytes failed exact manifest validation');
        }
    }
    $expected_names = array_map(static fn (array $file): string => $file['name'], $manifest['files']);
    sort($expected_names, SORT_STRING);
    $actual_names = array_values(array_filter(scandir($target_dir) ?: [],
        static fn (string $name): bool => $name !== '.' && $name !== '..'));
    sort($actual_names, SORT_STRING);
    if ($actual_names !== $expected_names) {
        throw new RuntimeException('The quarantine directory does not exactly match its committed manifest');
    }
    return $moved;
}

// Compatibility seam for focused filesystem tests and one-shot callers. The
// operational delete path commits the plan first, then calls the durable
// claim/finalize workflow below instead of relying on exception rollback.
function retentionMoveToQuarantine(string $record_type, int $record_id, array $source_paths): array
{
    $plan = retentionPrepareQuarantinePlan($record_type, $record_id, $source_paths);
    $moved = retentionMovePreparedQuarantine($plan);
    return $plan + ['status' => 'quarantined', 'moved' => $moved];
}

function retentionLockQuarantineLifecycleTarget(string $record_type, int $record_id, int $client_id): array
{
    documentationLockClient($client_id);
    if ($record_type === 'file') {
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM files
            WHERE file_id = $record_id AND file_client_id = $client_id
            AND file_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
            'Could not lock the pending file quarantine target'));
    } elseif ($record_type === 'attachment') {
        $prelock = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_attachment_ticket_id
            FROM ticket_attachments WHERE ticket_attachment_id = $record_id LIMIT 1",
            'Could not locate the pending attachment quarantine target'));
        if (!$prelock) {
            throw new RuntimeException('The pending attachment quarantine target is unavailable');
        }
        $ticket_id = intval($prelock['ticket_attachment_ticket_id']);
        $ticket = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id FROM tickets
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE",
            'Could not lock the pending attachment parent ticket'));
        if (!$ticket) {
            throw new RuntimeException('The pending attachment changed client scope');
        }
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM ticket_attachments
            WHERE ticket_attachment_id = $record_id
            AND ticket_attachment_ticket_id = $ticket_id
            AND ticket_attachment_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
            'Could not lock the pending attachment quarantine target'));
    } else {
        throw new InvalidArgumentException('Unsupported pending quarantine target');
    }
    if (!$target) {
        throw new RuntimeException('The pending quarantine target is no longer soft deleted');
    }
    return $target;
}

function retentionClaimQuarantineMove(string $record_type, int $record_id, int $generation): ?array
{
    global $mysqli;
    $type_sql = retentionDbEscape($record_type);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the quarantine recovery claim');
    }
    try {
        $advisory = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_client_id
            FROM retention_deletions WHERE retention_deletion_record_type = '$type_sql'
            AND retention_deletion_record_id = $record_id
            AND retention_deletion_generation = $generation
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL LIMIT 1",
            'Could not locate the pending quarantine ledger'));
        if (!$advisory) {
            mysqli_rollback($mysqli);
            return null;
        }
        $client_id = intval($advisory['retention_deletion_client_id']);
        retentionLockQuarantineLifecycleTarget($record_type, $record_id, $client_id);
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_generation']) !== $generation
            || intval($deletion['retention_deletion_client_id']) !== $client_id) {
            throw new DomainException('The record entered a newer quarantine lifecycle');
        }
        if ($deletion['retention_deletion_quarantine_status'] === 'quarantined') {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not finish the completed quarantine inspection');
            }
            return null;
        }
        $claimable = in_array($deletion['retention_deletion_quarantine_status'],
            ['move_pending', 'move_failed'], true)
            || ($deletion['retention_deletion_quarantine_status'] === 'move_running'
                && !empty($deletion['retention_deletion_quarantine_attempted_at'])
                && strtotime($deletion['retention_deletion_quarantine_attempted_at']) < time() - 1800);
        if (!$claimable) {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not finish the quarantine claim inspection');
            }
            return null;
        }
        // Decode while the ledger is locked so malformed/tampered move state
        // can never acquire a filesystem execution lease.
        retentionDecodeQuarantineManifest(
            $deletion['retention_deletion_quarantine_manifest'],
            $deletion['retention_deletion_quarantine_manifest_hash']
        );
        $claim_token = bin2hex(random_bytes(32));
        $claim_sql = retentionDbEscape($claim_token);
        $deletion_id = intval($deletion['retention_deletion_id']);
        retentionDbQuery("UPDATE retention_deletions SET
            retention_deletion_quarantine_status = 'move_running',
            retention_deletion_quarantine_claim_token = '$claim_sql',
            retention_deletion_quarantine_attempted_at = NOW(),
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = $deletion_id
            AND retention_deletion_generation = $generation
            AND (retention_deletion_quarantine_status IN ('move_pending','move_failed')
                OR (retention_deletion_quarantine_status = 'move_running'
                    AND retention_deletion_quarantine_attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
            LIMIT 1", 'Could not claim the pending quarantine move');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The pending quarantine move changed during claim');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the quarantine recovery claim');
        }
        $deletion['retention_deletion_quarantine_claim_token'] = $claim_token;
        return $deletion;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionFinishQuarantineClaim(array $deletion, bool $success, ?Throwable $error = null): string
{
    global $mysqli;
    $record_type = (string) $deletion['retention_deletion_record_type'];
    $record_id = intval($deletion['retention_deletion_record_id']);
    $client_id = intval($deletion['retention_deletion_client_id']);
    $generation = intval($deletion['retention_deletion_generation']);
    $claim_token = (string) $deletion['retention_deletion_quarantine_claim_token'];
    $claim_sql = retentionDbEscape($claim_token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin quarantine move finalization');
    }
    try {
        retentionLockQuarantineLifecycleTarget($record_type, $record_id, $client_id);
        $locked = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($locked['retention_deletion_generation']) !== $generation
            || !hash_equals((string) $locked['retention_deletion_quarantine_claim_token'], $claim_token)
            || $locked['retention_deletion_quarantine_status'] !== 'move_running') {
            throw new DomainException('The quarantine execution lease changed before finalization');
        }
        $deletion_id = intval($locked['retention_deletion_id']);
        if ($success) {
            retentionDbQuery("UPDATE retention_deletions SET
                retention_deletion_quarantine_status = 'quarantined',
                retention_deletion_quarantine_claim_token = NULL,
                retention_deletion_quarantine_completed_at = NOW(),
                retention_deletion_last_error = NULL
                WHERE retention_deletion_id = $deletion_id
                AND retention_deletion_generation = $generation
                AND retention_deletion_quarantine_status = 'move_running'
                AND retention_deletion_quarantine_claim_token = '$claim_sql' LIMIT 1",
                'Could not finalize the exact quarantine manifest');
            $action = 'quarantine_completed';
            $reason = 'All expected upload bytes matched the committed manifest';
            $status = 'quarantined';
        } else {
            $message = substr($error?->getMessage() ?: 'Unknown quarantine move failure', 0, 1000);
            $message_sql = retentionDbEscape($message);
            retentionDbQuery("UPDATE retention_deletions SET
                retention_deletion_quarantine_status = 'move_failed',
                retention_deletion_quarantine_claim_token = NULL,
                retention_deletion_last_error = '$message_sql'
                WHERE retention_deletion_id = $deletion_id
                AND retention_deletion_generation = $generation
                AND retention_deletion_quarantine_status = 'move_running'
                AND retention_deletion_quarantine_claim_token = '$claim_sql' LIMIT 1",
                'Could not record the quarantine move failure');
            $action = 'quarantine_failed';
            $reason = $message;
            $status = 'move_failed';
        }
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The quarantine execution lease changed before commit');
        }
        retentionAppendEvent($record_type, $record_id, $client_id, $generation,
            $action, 'system', 0, $reason, [
                'manifest_hash' => $locked['retention_deletion_quarantine_manifest_hash'],
                'quarantine_path' => $locked['retention_deletion_quarantine_path'],
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit quarantine move finalization');
        }
        return $status;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionFinalizePendingQuarantine(string $record_type, int $record_id, int $generation): string
{
    $claim = retentionClaimQuarantineMove($record_type, $record_id, $generation);
    if (!$claim) {
        return 'not_claimed';
    }
    try {
        retentionMovePreparedQuarantine([
            'relative_path' => $claim['retention_deletion_quarantine_path'],
            'manifest' => $claim['retention_deletion_quarantine_manifest'],
            'manifest_hash' => $claim['retention_deletion_quarantine_manifest_hash'],
        ]);
    } catch (Throwable $e) {
        return retentionFinishQuarantineClaim($claim, false, $e);
    }
    return retentionFinishQuarantineClaim($claim, true);
}

function retentionRollbackQuarantine(array $moved): void
{
    $uploads = retentionUploadsRoot();
    $quarantine = retentionQuarantineRoot();
    foreach (array_reverse($moved, true) as $source => $target) {
        $target_stat = @lstat($target);
        if ($target_stat === false) {
            continue;
        }
        if (!retentionPathIsWithin($source, $uploads) || retentionPathIsWithin($source, $quarantine)
            || !retentionPathIsWithin($target, $quarantine)) {
            throw new RuntimeException('Retention refused an unsafe quarantine rollback path');
        }
        retentionAssertNoSymlinkComponents($target, $quarantine);
        retentionAssertNoSymlinkComponents(dirname($source), $uploads);
        if (($target_stat['mode'] & 0170000) !== 0100000 || is_link($target)
            || @lstat($source) !== false || is_link($source) || !rename($target, $source)) {
            throw new RuntimeException('Could not roll back a retention quarantine move');
        }
    }
}

function retentionRollbackRestoredFiles(array $moved): void
{
    $uploads = retentionUploadsRoot();
    $quarantine = retentionQuarantineRoot();
    // Restore moves are recorded quarantine-source => live-target. Validate
    // that exact direction and put every already-restored regular file back
    // before returning control to a database rollback caller.
    foreach (array_reverse($moved, true) as $quarantine_source => $live_target) {
        $live_stat = @lstat($live_target);
        if ($live_stat === false) {
            continue;
        }
        if (!retentionPathIsWithin($quarantine_source, $quarantine)
            || !retentionPathIsWithin($live_target, $uploads)
            || retentionPathIsWithin($live_target, $quarantine)) {
            throw new RuntimeException('Retention refused an unsafe restore rollback path');
        }
        retentionAssertNoSymlinkComponents(dirname($quarantine_source), $quarantine);
        retentionAssertNoSymlinkComponents($live_target, $uploads);
        if (($live_stat['mode'] & 0170000) !== 0100000 || is_link($live_target)
            || @lstat($quarantine_source) !== false || is_link($quarantine_source)
            || !rename($live_target, $quarantine_source)) {
            throw new RuntimeException('Could not return a restored upload to quarantine');
        }
    }
}

function retentionQuarantineAbsolute(?string $relative): ?string
{
    if ($relative === null || $relative === '') {
        return null;
    }
    $normalized = str_replace('\\', '/', trim($relative));
    if (!preg_match('#^(file|attachment)/[1-9][0-9]*/[0-9]{14}-[a-f0-9]{16,64}$#D', $normalized)) {
        throw new RuntimeException('The stored retention quarantine path is unsafe');
    }
    $root = retentionQuarantineRoot();
    $absolute = $root . '/' . $normalized;
    retentionAssertNoSymlinkComponents($absolute, $root, true);
    return $absolute;
}

function retentionRestoreQuarantinedFiles(?string $relative, array $target_paths,
    ?string $manifest_json, ?string $manifest_hash, ?callable $move_file = null): array
{
    $move_file = $move_file ?? static fn (string $source, string $target): bool => rename($source, $target);
    $manifest = retentionDecodeQuarantineManifest($manifest_json, $manifest_hash);
    if (!hash_equals((string) ($manifest['quarantine_relative_path'] ?? ''), (string) $relative)) {
        throw new RuntimeException('The restore path does not match the committed quarantine manifest');
    }
    $dir = retentionQuarantineAbsolute($relative);
    if ($dir === null || @lstat($dir) === false) {
        throw new RuntimeException('The exact quarantined upload bytes are unavailable for restore');
    }
    retentionAssertNoSymlinkComponents($dir, retentionQuarantineRoot());
    $dir_stat = @lstat($dir);
    if ($dir_stat === false || ($dir_stat['mode'] & 0170000) !== 0040000 || is_link($dir)) {
        throw new RuntimeException('The stored retention quarantine directory is unsafe');
    }
    $targets = [];
    foreach ($target_paths as $path) {
        $path = str_replace('\\', '/', (string) $path);
        if (!retentionPathIsWithin($path, retentionUploadsRoot())
            || retentionPathIsWithin($path, retentionQuarantineRoot())) {
            throw new RuntimeException('Retention refused an unsafe restore target');
        }
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Retention refused an unsafe restore filename');
        }
        $relative_target = substr($path, strlen(retentionUploadsRoot()) + 1);
        $targets[$relative_target] = $path;
    }
    $expected_relatives = array_map(static fn (array $file): string => $file['source_relative'],
        $manifest['files']);
    $actual_relatives = array_keys($targets);
    sort($expected_relatives, SORT_STRING);
    sort($actual_relatives, SORT_STRING);
    if ($actual_relatives !== $expected_relatives) {
        throw new RuntimeException('Current record metadata does not match the exact deletion manifest');
    }
    $expected_names = array_map(static fn (array $file): string => $file['name'], $manifest['files']);
    sort($expected_names, SORT_STRING);
    $actual_names = array_values(array_filter(scandir($dir) ?: [],
        static fn (string $name): bool => $name !== '.' && $name !== '..'));
    sort($actual_names, SORT_STRING);
    if ($actual_names !== $expected_names) {
        throw new RuntimeException('The quarantined upload set is incomplete or contains unexpected bytes');
    }
    $moved = [];
    try {
        foreach ($manifest['files'] as $file) {
            $name = $file['name'];
            $source = $dir . '/' . $name;
            $source_stat = @lstat($source);
            if ($source_stat === false || ($source_stat['mode'] & 0170000) !== 0100000
                || is_link($source) || intval($source_stat['size']) !== intval($file['size'])
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $source))) {
                throw new RuntimeException('Retention refused a non-regular quarantined file');
            }
            retentionAssertNoSymlinkComponents($source, retentionQuarantineRoot());
            $target = $targets[$file['source_relative']];
            if (@lstat($target) !== false || is_link($target)) {
                throw new DomainException("Restore target already exists for $name");
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not prepare an upload restore target');
            }
            retentionAssertNoSymlinkComponents($parent, retentionUploadsRoot());
            if (!$move_file($source, $target)) {
                throw new RuntimeException('Could not restore an upload from retention quarantine');
            }
            $restored_stat = @lstat($target);
            if ($restored_stat === false || ($restored_stat['mode'] & 0170000) !== 0100000
                || is_link($target) || intval($restored_stat['size']) !== intval($file['size'])
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $target))) {
                throw new RuntimeException('The restored upload did not remain a regular file');
            }
            $moved[$source] = $target;
        }
    } catch (Throwable $e) {
        retentionRollbackRestoredFiles($moved);
        throw $e;
    }
    return $moved;
}

function retentionSoftDeleteTicket(int $ticket_id, int $actor_id, string $reason): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $reason = retentionValidateDeleteReason($reason);
    $ticket_id = max(1, $ticket_id);
    $actor_id = max(1, $actor_id);
    $policy = retentionPolicy('tickets');
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the ticket retention transaction');
    }
    try {
        $prelock = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_client_id FROM tickets
            WHERE ticket_id = $ticket_id LIMIT 1", 'Could not locate the ticket for retention'));
        if (!$prelock) {
            throw new RuntimeException('The ticket no longer exists');
        }
        $client_id = intval($prelock['ticket_client_id']);
        $ticket = documentationLockClientTicket($ticket_id, $client_id);
        $ticket = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id, ticket_client_id, ticket_prefix,
            ticket_number, ticket_subject, ticket_created_at, ticket_resolved_at, ticket_closed_at,
            ticket_deleted_at FROM tickets WHERE ticket_id = $ticket_id
            AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE", 'Could not lock the retained ticket'));
        if (!$ticket || !empty($ticket['ticket_deleted_at'])) {
            throw new DomainException('The ticket is already deleted or changed');
        }
        if (retentionActiveHolds('ticket', $ticket_id, $client_id)) {
            throw new DomainException('The ticket is protected by an active retention hold');
        }
        if (empty($ticket['ticket_closed_at'])) {
            throw new DomainException('Only closed tickets may enter the explicit retention workflow');
        }
        if (retentionCount("SELECT COUNT(*) FROM ticket_customer_promises
            WHERE ticket_customer_promise_ticket_id = $ticket_id
            AND ticket_customer_promise_status IN ('Open','Breached')",
            'Could not inspect active customer promises before retention') > 0) {
            throw new DomainException('Fulfill or explicitly cancel active customer promises before deleting this ticket');
        }
        // Closed-ticket history remains immutable: this only changes visibility
        // and retention metadata. No reply, time, evidence, or lifecycle row is
        // touched by the operation.
        $anchor = $ticket['ticket_closed_at'] ?: ($ticket['ticket_resolved_at'] ?: $ticket['ticket_created_at']);
        $deadline = retentionDeadline($anchor, $policy['retention_days'], $policy['restore_window_days']);
        $deleted_sql = retentionDbEscape($deadline['deleted_at']);
        $reason_sql = retentionDbEscape($reason);
        $restore_sql = $deadline['restore_until'] === null ? 'NULL' : "'" . retentionDbEscape($deadline['restore_until']) . "'";
        $purge_sql = retentionDbEscape($deadline['purge_eligible_at']);
        retentionDbQuery("UPDATE tickets SET
            ticket_deleted_at = '$deleted_sql', ticket_deleted_by = $actor_id,
            ticket_delete_reason = '$reason_sql', ticket_restore_until = $restore_sql,
            ticket_purge_eligible_at = '$purge_sql',
            ticket_updated_at = GREATEST(NOW(),
                DATE_ADD(COALESCE(ticket_updated_at, ticket_created_at, NOW()), INTERVAL 1 SECOND))
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
            AND ticket_deleted_at IS NULL LIMIT 1", 'Could not soft-delete the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The ticket changed before soft deletion');
        }
        $label = trim((string) $ticket['ticket_prefix']) . intval($ticket['ticket_number']) . ' - ' . $ticket['ticket_subject'];
        $generation = retentionWriteDeletionLedger('ticket', $ticket_id, $client_id, $label,
            $actor_id, $reason, $deadline);
        retentionAppendEvent('ticket', $ticket_id, $client_id, $generation, 'soft_deleted',
            'admin', $actor_id, $reason, [
                'closed_ticket' => !empty($ticket['ticket_closed_at']),
                'restore_until' => $deadline['restore_until'],
                'purge_eligible_at' => $deadline['purge_eligible_at'],
                'policy' => $policy,
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket soft deletion');
        }
        return ['record_type' => 'ticket', 'record_id' => $ticket_id, 'client_id' => $client_id,
            'generation' => $generation] + $deadline;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionFilePaths(array $file): array
{
    $client_id = intval($file['file_client_id'] ?? 0);
    $reference = (string) ($file['file_reference_name'] ?? '');
    if (!$client_id || $reference === '' || basename($reference) !== $reference) {
        return [];
    }
    $base = retentionUploadsRoot() . "/clients/$client_id";
    $paths = [$base . '/' . $reference];
    if (intval($file['file_has_thumbnail'] ?? 0) === 1) {
        $paths[] = $base . '/thumbnail_' . $reference;
    }
    if (intval($file['file_has_preview'] ?? 0) === 1) {
        $paths[] = $base . '/preview_' . $reference;
    }
    return $paths;
}

function retentionSoftDeleteFile(int $file_id, int $client_id, int $actor_id, string $reason): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $reason = retentionValidateDeleteReason($reason);
    $policy = retentionPolicy('files');
    $quarantine = null;
    $generation = 0;
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the file retention transaction');
    }
    try {
        documentationLockClient($client_id);
        $file = mysqli_fetch_assoc(retentionDbQuery("SELECT files.* FROM files
            WHERE file_id = $file_id AND file_client_id = $client_id
            LIMIT 1 FOR UPDATE", 'Could not lock the retained file'));
        if (!$file || !empty($file['file_deleted_at'])) {
            throw new DomainException('The file is already deleted or changed');
        }
        if (retentionActiveHolds('file', $file_id, $client_id)) {
            throw new DomainException('The file is protected by an active retention hold');
        }
        if (documentationEvidenceReferenceInUse('file', $file_id, $client_id)) {
            throw new DomainException('The file is retained in the Evidence Locker');
        }
        $link_checks = [
            'document' => 'document_files',
            'asset' => 'asset_files',
            'contact' => 'contact_files',
            'software' => 'software_files',
            'quote' => 'quote_files',
            'vendor' => 'vendor_files',
        ];
        foreach ($link_checks as $label => $table) {
            if (retentionCount("SELECT COUNT(*) FROM $table WHERE file_id = $file_id",
                "Could not inspect $label file links") > 0) {
                throw new DomainException("The file is still linked to a $label record");
            }
        }
        if (retentionCount("SELECT COUNT(*) FROM shared_items WHERE item_type = 'File'
            AND item_related_id = $file_id AND item_client_id = $client_id",
            'Could not inspect shared file links') > 0) {
            throw new DomainException('The file still has a shared-item accountability record');
        }
        $deadline = retentionDeadline($file['file_created_at'], $policy['retention_days'], $policy['restore_window_days']);
        // Phase 1 is database-durable before any rename. A process/host crash
        // can therefore leave only a deleted row with a replayable move plan,
        // never live metadata whose bytes disappeared without a ledger.
        $quarantine = retentionPrepareQuarantinePlan('file', $file_id, retentionFilePaths($file));
        $deleted_sql = retentionDbEscape($deadline['deleted_at']);
        $reason_sql = retentionDbEscape($reason);
        $restore_sql = $deadline['restore_until'] === null ? 'NULL' : "'" . retentionDbEscape($deadline['restore_until']) . "'";
        $purge_sql = retentionDbEscape($deadline['purge_eligible_at']);
        retentionDbQuery("UPDATE files SET file_deleted_at = '$deleted_sql', file_deleted_by = $actor_id,
            file_delete_reason = '$reason_sql', file_restore_until = $restore_sql,
            file_purge_eligible_at = '$purge_sql'
            WHERE file_id = $file_id AND file_client_id = $client_id
            AND file_deleted_at IS NULL LIMIT 1", 'Could not soft-delete the file');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The file changed before soft deletion');
        }
        $generation = retentionWriteDeletionLedger('file', $file_id, $client_id, (string) $file['file_name'],
            $actor_id, $reason, $deadline, $quarantine['relative_path'], 'move_pending',
            $quarantine['manifest']);
        retentionAppendEvent('file', $file_id, $client_id, $generation, 'soft_deleted', 'admin',
            $actor_id, $reason, [
                'quarantine_status' => 'move_pending',
                'manifest_hash' => $quarantine['manifest_hash'],
                'policy' => $policy,
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the file soft deletion');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    $file['retention_soft_deleted'] = 1;
    $file['retention_generation'] = $generation;
    $file['retention_quarantine_status'] = retentionFinalizePendingQuarantine('file', $file_id, $generation);
    return $file;
}

function retentionSoftDeleteAttachment(int $attachment_id, int $actor_id, string $reason): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $reason = retentionValidateDeleteReason($reason);
    $policy = retentionPolicy('attachments');
    $quarantine = null;
    $generation = 0;
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the attachment retention transaction');
    }
    try {
        $prelock = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_attachment_ticket_id
            FROM ticket_attachments WHERE ticket_attachment_id = $attachment_id LIMIT 1",
            'Could not locate the retained attachment'));
        if (!$prelock) {
            throw new RuntimeException('The attachment no longer exists');
        }
        $ticket_id = intval($prelock['ticket_attachment_ticket_id']);
        $ticket_prelock = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id, ticket_client_id
            FROM tickets WHERE ticket_id = $ticket_id LIMIT 1", 'Could not locate the attachment ticket'));
        if (!$ticket_prelock) {
            throw new RuntimeException('The attachment ticket no longer exists');
        }
        $client_id = intval($ticket_prelock['ticket_client_id']);
        documentationLockClient($client_id);
        $ticket = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id, ticket_client_id, ticket_deleted_at
            FROM tickets WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
            LIMIT 1 FOR UPDATE", 'Could not lock the attachment ticket'));
        if (!$ticket) {
            throw new RuntimeException('The attachment ticket client changed before deletion');
        }
        if (!empty($ticket['ticket_deleted_at'])) {
            throw new DomainException('Restore the parent ticket before changing attachment retention');
        }
        if (retentionActiveHolds('attachment', $attachment_id, $client_id)) {
            throw new DomainException('The attachment is protected by an active retention hold');
        }
        $attachment = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM ticket_attachments
            WHERE ticket_attachment_id = $attachment_id AND ticket_attachment_ticket_id = $ticket_id
            LIMIT 1 FOR UPDATE", 'Could not lock the retained attachment'));
        if (!$attachment || !empty($attachment['ticket_attachment_deleted_at'])) {
            throw new DomainException('The attachment is already deleted or changed');
        }
        $evidence = mysqli_fetch_assoc(retentionDbQuery("SELECT task_evidence_id FROM task_evidence
            WHERE task_evidence_attachment_id = $attachment_id LIMIT 1 FOR UPDATE",
            'Could not inspect attachment evidence references'));
        if ($evidence) {
            throw new DomainException('The attachment is retained as runbook evidence');
        }
        $signature = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_customer_signature_id
            FROM ticket_customer_signatures
            WHERE ticket_customer_signature_attachment_id = $attachment_id LIMIT 1 FOR UPDATE",
            'Could not inspect customer-signature evidence references'));
        if ($signature) {
            throw new DomainException('The attachment is retained as customer-signature evidence');
        }
        $reference = (string) $attachment['ticket_attachment_reference_name'];
        if ($reference !== '' && basename($reference) !== $reference) {
            throw new RuntimeException('The attachment storage reference is unsafe');
        }
        $deadline = retentionDeadline($attachment['ticket_attachment_created_at'],
            $policy['retention_days'], $policy['restore_window_days']);
        $quarantine = retentionPrepareQuarantinePlan('attachment', $attachment_id,
            [retentionUploadsRoot() . "/tickets/$ticket_id/$reference"]);
        $deleted_sql = retentionDbEscape($deadline['deleted_at']);
        $reason_sql = retentionDbEscape($reason);
        $restore_sql = $deadline['restore_until'] === null ? 'NULL' : "'" . retentionDbEscape($deadline['restore_until']) . "'";
        $purge_sql = retentionDbEscape($deadline['purge_eligible_at']);
        retentionDbQuery("UPDATE ticket_attachments SET
            ticket_attachment_deleted_at = '$deleted_sql', ticket_attachment_deleted_by = $actor_id,
            ticket_attachment_delete_reason = '$reason_sql', ticket_attachment_restore_until = $restore_sql,
            ticket_attachment_purge_eligible_at = '$purge_sql'
            WHERE ticket_attachment_id = $attachment_id
            AND ticket_attachment_ticket_id = $ticket_id
            AND ticket_attachment_deleted_at IS NULL LIMIT 1", 'Could not soft-delete the attachment');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The attachment changed before soft deletion');
        }
        retentionAdvanceTicketVersion($ticket_id, $client_id);
        $generation = retentionWriteDeletionLedger('attachment', $attachment_id, $client_id,
            (string) $attachment['ticket_attachment_name'], $actor_id, $reason, $deadline,
            $quarantine['relative_path'], 'move_pending', $quarantine['manifest']);
        retentionAppendEvent('attachment', $attachment_id, $client_id, $generation,
            'soft_deleted', 'admin', $actor_id, $reason, [
                'ticket_id' => $ticket_id,
                'quarantine_status' => 'move_pending',
                'manifest_hash' => $quarantine['manifest_hash'],
                'policy' => $policy,
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the attachment soft deletion');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    $attachment['retention_generation'] = $generation;
    $attachment['retention_quarantine_status'] = retentionFinalizePendingQuarantine(
        'attachment', $attachment_id, $generation);
    return $attachment;
}

function retentionDeletionForUpdate(string $record_type, int $record_id): array
{
    $type_sql = retentionDbEscape($record_type);
    $row = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_deletions
        WHERE retention_deletion_record_type = '$type_sql'
        AND retention_deletion_record_id = $record_id LIMIT 1 FOR UPDATE",
        'Could not lock the recoverable-deletion ledger'));
    if (!$row || !empty($row['retention_deletion_restored_at']) || !empty($row['retention_deletion_purged_at'])) {
        throw new DomainException('The deleted record is no longer restorable');
    }
    return $row;
}

function retentionResolveRecordClient(string $record_type, int $record_id): int
{
    if ($record_id < 1) {
        throw new InvalidArgumentException('A specific record ID is required');
    }
    if ($record_type === 'ticket') {
        $sql = "SELECT ticket_client_id FROM tickets WHERE ticket_id = $record_id LIMIT 1 FOR UPDATE";
        $column = 'ticket_client_id';
    } elseif ($record_type === 'file') {
        $sql = "SELECT file_client_id FROM files WHERE file_id = $record_id LIMIT 1 FOR UPDATE";
        $column = 'file_client_id';
    } elseif ($record_type === 'attachment') {
        $sql = "SELECT ticket_client_id FROM ticket_attachments
            INNER JOIN tickets ON ticket_id = ticket_attachment_ticket_id
            WHERE ticket_attachment_id = $record_id LIMIT 1 FOR UPDATE";
        $column = 'ticket_client_id';
    } elseif ($record_type === 'automation-event') {
        $sql = "SELECT e.automation_event_id, t.ticket_client_id AS ticket_client_id,
            i.automation_incident_client_id AS incident_client_id
            FROM automation_events e
            LEFT JOIN tickets t ON t.ticket_id = e.automation_event_ticket_id
            LEFT JOIN automation_incidents i ON i.automation_incident_source = e.automation_event_source
                AND i.automation_incident_key = e.automation_event_incident_key
            WHERE e.automation_event_id = $record_id LIMIT 1 FOR UPDATE";
        $column = '';
    } elseif ($record_type === 'normalized-payload') {
        $sql = "SELECT automation_snapshot_client_id FROM automation_entity_snapshots
            WHERE automation_snapshot_id = $record_id LIMIT 1 FOR UPDATE";
        $column = 'automation_snapshot_client_id';
    } else {
        throw new InvalidArgumentException('Unsupported held record type');
    }
    $row = mysqli_fetch_assoc(retentionDbQuery($sql, 'Could not resolve the held record client'));
    if (!$row) {
        throw new DomainException('The held record is unavailable');
    }
    if ($record_type === 'automation-event') {
        $clients = array_values(array_unique(array_filter([
            intval($row['ticket_client_id'] ?? 0),
            intval($row['incident_client_id'] ?? 0),
        ])));
        if (count($clients) !== 1) {
            throw new DomainException('The event client scope is missing or ambiguous; preserve it by default');
        }
        return $clients[0];
    }
    return intval($row[$column]);
}

function retentionRestorePathsForTarget(string $record_type, array $target): array
{
    if ($record_type === 'file') {
        return retentionFilePaths($target);
    }
    if ($record_type === 'attachment') {
        $reference = (string) ($target['ticket_attachment_reference_name'] ?? '');
        $ticket_id = intval($target['ticket_attachment_ticket_id'] ?? 0);
        if ($ticket_id < 1 || $reference === '' || basename($reference) !== $reference) {
            throw new RuntimeException('The attachment restore reference is unsafe');
        }
        return [retentionUploadsRoot() . "/tickets/$ticket_id/$reference"];
    }
    throw new InvalidArgumentException('Unsupported upload restore target');
}

function retentionPrepareDurableRestore(string $record_type, int $record_id,
    int $actor_id, string $reason): array
{
    global $mysqli;
    $type_sql = retentionDbEscape($record_type);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the durable restore preparation');
    }
    try {
        $advisory = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_client_id,
            retention_deletion_generation FROM retention_deletions
            WHERE retention_deletion_record_type = '$type_sql'
            AND retention_deletion_record_id = $record_id
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL LIMIT 1",
            'Could not locate the durable restore ledger'));
        if (!$advisory) {
            throw new DomainException('The deleted record is no longer restorable');
        }
        $client_id = intval($advisory['retention_deletion_client_id']);
        $generation = intval($advisory['retention_deletion_generation']);
        $target = retentionLockQuarantineLifecycleTarget($record_type, $record_id, $client_id);
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_client_id']) !== $client_id
            || intval($deletion['retention_deletion_generation']) !== $generation) {
            throw new DomainException('The record entered a newer deletion lifecycle');
        }
        if (empty($deletion['retention_deletion_restore_until'])
            || strtotime($deletion['retention_deletion_restore_until']) < time()) {
            throw new DomainException('The configured restore window is unavailable or expired');
        }
        if ($deletion['retention_deletion_quarantine_status'] !== 'quarantined'
            && $deletion['retention_deletion_quarantine_status'] !== 'restore_failed') {
            throw new DomainException('Exact primary upload bytes are not ready for a restore claim');
        }
        $manifest = retentionDecodeQuarantineManifest(
            $deletion['retention_deletion_quarantine_manifest'],
            $deletion['retention_deletion_quarantine_manifest_hash']
        );
        // Validate current metadata against the committed source mapping before
        // a durable restore intent is accepted.
        $paths = retentionRestorePathsForTarget($record_type, $target);
        $path_relatives = array_map(static fn (string $path): string =>
            substr(str_replace('\\', '/', $path), strlen(retentionUploadsRoot()) + 1), $paths);
        $manifest_relatives = array_map(static fn (array $file): string => $file['source_relative'],
            $manifest['files']);
        sort($path_relatives, SORT_STRING);
        sort($manifest_relatives, SORT_STRING);
        if ($path_relatives !== $manifest_relatives) {
            throw new RuntimeException('Current record metadata no longer matches the deletion manifest');
        }
        $reason_sql = retentionDbEscape($reason);
        $deletion_id = intval($deletion['retention_deletion_id']);
        retentionDbQuery("UPDATE retention_deletions SET
            retention_deletion_quarantine_status = 'restore_pending',
            retention_deletion_restore_pending_by = $actor_id,
            retention_deletion_restore_pending_reason = '$reason_sql',
            retention_deletion_restore_prepared_at = NOW(),
            retention_deletion_quarantine_claim_token = NULL,
            retention_deletion_quarantine_attempted_at = NULL,
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = $deletion_id
            AND retention_deletion_generation = $generation
            AND retention_deletion_quarantine_status IN ('quarantined','restore_failed') LIMIT 1",
            'Could not prepare the durable upload restore');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The recoverable upload changed during restore preparation');
        }
        retentionAppendEvent($record_type, $record_id, $client_id, $generation,
            'restore_prepared', 'admin', $actor_id, $reason, [
                'manifest_hash' => $deletion['retention_deletion_quarantine_manifest_hash'],
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the durable restore preparation');
        }
        return ['client_id' => $client_id, 'generation' => $generation];
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionClaimDurableRestore(string $record_type, int $record_id, int $generation): ?array
{
    global $mysqli;
    $type_sql = retentionDbEscape($record_type);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the durable restore claim');
    }
    try {
        $advisory = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_client_id
            FROM retention_deletions WHERE retention_deletion_record_type = '$type_sql'
            AND retention_deletion_record_id = $record_id
            AND retention_deletion_generation = $generation
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL LIMIT 1",
            'Could not locate the pending restore claim'));
        if (!$advisory) {
            mysqli_rollback($mysqli);
            return null;
        }
        $client_id = intval($advisory['retention_deletion_client_id']);
        $target = retentionLockQuarantineLifecycleTarget($record_type, $record_id, $client_id);
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_generation']) !== $generation) {
            throw new DomainException('The record entered a newer restore lifecycle');
        }
        $claimable = in_array($deletion['retention_deletion_quarantine_status'],
            ['restore_pending', 'restore_failed'], true)
            || ($deletion['retention_deletion_quarantine_status'] === 'restore_running'
                && !empty($deletion['retention_deletion_quarantine_attempted_at'])
                && strtotime($deletion['retention_deletion_quarantine_attempted_at']) < time() - 1800);
        if (!$claimable) {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not finish the restore claim inspection');
            }
            return null;
        }
        retentionDecodeQuarantineManifest(
            $deletion['retention_deletion_quarantine_manifest'],
            $deletion['retention_deletion_quarantine_manifest_hash']
        );
        $claim_token = bin2hex(random_bytes(32));
        $claim_sql = retentionDbEscape($claim_token);
        $deletion_id = intval($deletion['retention_deletion_id']);
        retentionDbQuery("UPDATE retention_deletions SET
            retention_deletion_quarantine_status = 'restore_running',
            retention_deletion_quarantine_claim_token = '$claim_sql',
            retention_deletion_quarantine_attempted_at = NOW(),
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = $deletion_id
            AND retention_deletion_generation = $generation
            AND (retention_deletion_quarantine_status IN ('restore_pending','restore_failed')
                OR (retention_deletion_quarantine_status = 'restore_running'
                    AND retention_deletion_quarantine_attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
            LIMIT 1", 'Could not claim the pending durable restore');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The pending restore changed during claim');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the durable restore claim');
        }
        $deletion['retention_deletion_quarantine_claim_token'] = $claim_token;
        $deletion['retention_restore_target'] = $target;
        return $deletion;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionMovePreparedRestore(array $claim, ?callable $move_file = null): array
{
    $move_file = $move_file ?? static fn (string $source, string $target): bool => rename($source, $target);
    $manifest = retentionDecodeQuarantineManifest(
        $claim['retention_deletion_quarantine_manifest'],
        $claim['retention_deletion_quarantine_manifest_hash']
    );
    $relative = (string) $claim['retention_deletion_quarantine_path'];
    if (!hash_equals((string) $manifest['quarantine_relative_path'], $relative)) {
        throw new RuntimeException('The pending restore path does not match its manifest');
    }
    $directory = retentionQuarantineAbsolute($relative);
    if ($directory === null || @lstat($directory) === false) {
        // A fully moved crash-recovery state may leave an empty directory that
        // was externally tidied, so validate every live target below instead.
        $directory = retentionQuarantineRoot() . '/' . $relative;
    }
    $expected_names = array_map(static fn (array $file): string => $file['name'], $manifest['files']);
    if (@lstat($directory) !== false) {
        retentionAssertNoSymlinkComponents($directory, retentionQuarantineRoot());
        $present_names = array_values(array_filter(scandir($directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..'));
        if (array_diff($present_names, $expected_names)) {
            throw new RuntimeException('The restore quarantine contains bytes outside its exact manifest');
        }
    }
    $targets = [];
    foreach (retentionRestorePathsForTarget((string) $claim['retention_deletion_record_type'],
        $claim['retention_restore_target']) as $target) {
        $targets[substr(str_replace('\\', '/', $target), strlen(retentionUploadsRoot()) + 1)] = $target;
    }
    $moved = [];
    foreach ($manifest['files'] as $file) {
        if (!isset($targets[$file['source_relative']])) {
            throw new RuntimeException('The pending restore target set changed from its manifest');
        }
        $source = $directory . '/' . $file['name'];
        $target = $targets[$file['source_relative']];
        $source_stat = @lstat($source);
        $target_stat = @lstat($target);
        if ($source_stat !== false && $target_stat !== false) {
            throw new RuntimeException('Both retained and live restore bytes exist; manual review is required');
        }
        if ($source_stat === false && $target_stat === false) {
            throw new RuntimeException('Restore bytes are missing from both quarantine and live storage');
        }
        if ($source_stat !== false) {
            retentionAssertNoSymlinkComponents($source, retentionQuarantineRoot());
            if (($source_stat['mode'] & 0170000) !== 0100000 || is_link($source)
                || intval($source_stat['size']) !== intval($file['size'])
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $source))) {
                throw new RuntimeException('Quarantined restore bytes failed exact manifest validation');
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not prepare a durable restore target');
            }
            retentionAssertNoSymlinkComponents($parent, retentionUploadsRoot());
            if (!$move_file($source, $target)) {
                throw new RuntimeException('Could not move retained bytes to the durable restore target');
            }
            $moved[$source] = $target;
            $target_stat = @lstat($target);
        }
        retentionAssertNoSymlinkComponents($target, retentionUploadsRoot());
        if ($target_stat === false || ($target_stat['mode'] & 0170000) !== 0100000 || is_link($target)
            || intval($target_stat['size']) !== intval($file['size'])
            || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $target))) {
            throw new RuntimeException('Live restored bytes failed exact manifest validation');
        }
    }
    if (@lstat($directory) !== false) {
        $remaining = array_values(array_filter(scandir($directory) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..'));
        if ($remaining || !rmdir($directory)) {
            throw new RuntimeException('The completed restore quarantine did not become exactly empty');
        }
    }
    return $moved;
}

function retentionFinishDurableRestore(array $claim, bool $success, ?Throwable $error = null): string
{
    global $mysqli;
    $record_type = (string) $claim['retention_deletion_record_type'];
    $record_id = intval($claim['retention_deletion_record_id']);
    $client_id = intval($claim['retention_deletion_client_id']);
    $generation = intval($claim['retention_deletion_generation']);
    $token = (string) $claim['retention_deletion_quarantine_claim_token'];
    $token_sql = retentionDbEscape($token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin durable restore finalization');
    }
    try {
        $target = retentionLockQuarantineLifecycleTarget($record_type, $record_id, $client_id);
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_generation']) !== $generation
            || $deletion['retention_deletion_quarantine_status'] !== 'restore_running'
            || !hash_equals((string) $deletion['retention_deletion_quarantine_claim_token'], $token)) {
            throw new DomainException('The durable restore execution lease changed before finalization');
        }
        $deletion_id = intval($deletion['retention_deletion_id']);
        if (!$success) {
            $message = substr($error?->getMessage() ?: 'Unknown durable restore failure', 0, 1000);
            $message_sql = retentionDbEscape($message);
            retentionDbQuery("UPDATE retention_deletions SET
                retention_deletion_quarantine_status = 'restore_failed',
                retention_deletion_quarantine_claim_token = NULL,
                retention_deletion_last_error = '$message_sql'
                WHERE retention_deletion_id = $deletion_id
                AND retention_deletion_generation = $generation
                AND retention_deletion_quarantine_status = 'restore_running'
                AND retention_deletion_quarantine_claim_token = '$token_sql' LIMIT 1",
                'Could not record the durable restore failure');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The failed restore claim changed before commit');
            }
            retentionAppendEvent($record_type, $record_id, $client_id, $generation,
                'restore_failed', 'system', 0, $message, [
                    'manifest_hash' => $deletion['retention_deletion_quarantine_manifest_hash'],
                ]);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the durable restore failure');
            }
            return 'restore_failed';
        }
        if ($record_type === 'file') {
            retentionDbQuery("UPDATE files SET file_deleted_at = NULL, file_deleted_by = 0,
                file_delete_reason = NULL, file_restore_until = NULL, file_purge_eligible_at = NULL
                WHERE file_id = $record_id AND file_client_id = $client_id
                AND file_deleted_at IS NOT NULL LIMIT 1", 'Could not finalize the durable file restore');
        } else {
            $ticket_id = intval($target['ticket_attachment_ticket_id']);
            retentionDbQuery("UPDATE ticket_attachments SET ticket_attachment_deleted_at = NULL,
                ticket_attachment_deleted_by = 0, ticket_attachment_delete_reason = NULL,
                ticket_attachment_restore_until = NULL, ticket_attachment_purge_eligible_at = NULL
                WHERE ticket_attachment_id = $record_id
                AND ticket_attachment_ticket_id = $ticket_id
                AND ticket_attachment_deleted_at IS NOT NULL LIMIT 1",
                'Could not finalize the durable attachment restore');
        }
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The durable restore target changed before commit');
        }
        if ($record_type === 'attachment') {
            retentionAdvanceTicketVersion(intval($target['ticket_attachment_ticket_id']), $client_id);
        }
        $actor_id = intval($deletion['retention_deletion_restore_pending_by']);
        $reason = (string) $deletion['retention_deletion_restore_pending_reason'];
        retentionDbQuery("UPDATE retention_deletions SET
            retention_deletion_restored_by = $actor_id, retention_deletion_restored_at = NOW(),
            retention_deletion_quarantine_status = 'restored',
            retention_deletion_quarantine_claim_token = NULL,
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = $deletion_id
            AND retention_deletion_generation = $generation
            AND retention_deletion_quarantine_status = 'restore_running'
            AND retention_deletion_quarantine_claim_token = '$token_sql' LIMIT 1",
            'Could not finalize the durable restore ledger');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The durable restore ledger changed before commit');
        }
        retentionAppendEvent($record_type, $record_id, $client_id, $generation,
            'restored', 'admin', $actor_id, $reason, [
                'restored_within_window' => true,
                'crash_replayable' => true,
                'manifest_hash' => $deletion['retention_deletion_quarantine_manifest_hash'],
            ]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the durable restore');
        }
        return 'restored';
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionFinalizePendingRestore(string $record_type, int $record_id, int $generation): string
{
    $claim = retentionClaimDurableRestore($record_type, $record_id, $generation);
    if (!$claim) {
        return 'not_claimed';
    }
    try {
        retentionMovePreparedRestore($claim);
    } catch (Throwable $e) {
        return retentionFinishDurableRestore($claim, false, $e);
    }
    return retentionFinishDurableRestore($claim, true);
}

function retentionRestoreRecord(string $record_type, int $record_id, int $actor_id, string $reason): void
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    if (!in_array($record_type, ['ticket', 'file', 'attachment'], true) || $record_id < 1) {
        throw new InvalidArgumentException('Select a supported deleted record');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) {
        throw new InvalidArgumentException('Provide a specific restoration reason of at least 10 characters');
    }
    $reason = mb_substr($reason, 0, 500);
    if (in_array($record_type, ['file', 'attachment'], true)) {
        $prepared = retentionPrepareDurableRestore($record_type, $record_id, $actor_id, $reason);
        $status = retentionFinalizePendingRestore($record_type, $record_id,
            intval($prepared['generation']));
        if ($status !== 'restored') {
            throw new RuntimeException('The restore is durably journaled but byte recovery remains pending');
        }
        return;
    }
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the retention restore transaction');
    }
    try {
        // Advisory only: discover the canonical client/generation without
        // taking the ledger lock. Restore and purge must both acquire
        // client -> target -> ledger to avoid a deadlock and TOCTOU window.
        $type_sql = retentionDbEscape($record_type);
        $advisory = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_client_id,
            retention_deletion_generation FROM retention_deletions
            WHERE retention_deletion_record_type = '$type_sql'
            AND retention_deletion_record_id = $record_id
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL
            LIMIT 1", 'Could not locate the recoverable-deletion ledger'));
        if (!$advisory) {
            throw new DomainException('The deleted record is no longer restorable');
        }
        $client_id = intval($advisory['retention_deletion_client_id']);
        $advisory_generation = intval($advisory['retention_deletion_generation']);
        if ($client_id < 1) {
            throw new RuntimeException('The deleted record has no canonical client scope');
        }
        documentationLockClient($client_id);
        $row = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id FROM tickets
            WHERE ticket_id = $record_id AND ticket_client_id = $client_id
            AND ticket_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
            'Could not lock the ticket restore target'));
        if (!$row) {
            throw new RuntimeException('The deleted restore target changed before it could be locked');
        }
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_client_id']) !== $client_id
            || intval($deletion['retention_deletion_generation']) !== $advisory_generation) {
            throw new DomainException('The record entered a newer deletion lifecycle');
        }
        if (empty($deletion['retention_deletion_restore_until'])) {
            throw new DomainException('This record has no configured restore window');
        }
        if (strtotime($deletion['retention_deletion_restore_until']) < time()) {
            throw new DomainException('The configured restore window has expired');
        }
        $generation = intval($deletion['retention_deletion_generation']);
        retentionDbQuery("UPDATE tickets SET ticket_deleted_at = NULL, ticket_deleted_by = 0,
            ticket_delete_reason = NULL, ticket_restore_until = NULL, ticket_purge_eligible_at = NULL,
            ticket_updated_at = GREATEST(NOW(),
                DATE_ADD(COALESCE(ticket_updated_at, ticket_created_at, NOW()), INTERVAL 1 SECOND))
            WHERE ticket_id = $record_id AND ticket_client_id = $client_id
            AND ticket_deleted_at IS NOT NULL LIMIT 1", 'Could not restore the ticket');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The deleted record changed before restore');
        }
        retentionDbQuery("UPDATE retention_deletions SET retention_deletion_restored_by = $actor_id,
            retention_deletion_restored_at = NOW(), retention_deletion_quarantine_status =
                CASE WHEN retention_deletion_quarantine_path IS NULL THEN 'none' ELSE 'restored' END,
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = " . intval($deletion['retention_deletion_id']) . "
            AND retention_deletion_generation = $generation
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL LIMIT 1",
            'Could not finalize the restore ledger');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The restore ledger changed before commit');
        }
        retentionAppendEvent($record_type, $record_id, $client_id, $generation, 'restored',
            'admin', $actor_id, $reason, ['restored_within_window' => true]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the record restore');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionPlaceHold(
    int $client_id,
    string $record_type,
    int $record_id,
    string $reason,
    int $actor_id
): int {
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $client_id = max(0, $client_id);
    $record_id = max(0, $record_id);
    $record_type = trim($record_type) ?: '*';
    $reason = retentionValidateDeleteReason($reason);
    if ($client_id === 0 && ($record_type === '*' || $record_id === 0)) {
        throw new InvalidArgumentException('A hold must target a client or a specific record');
    }
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the retention hold transaction');
    }
    try {
        if ($record_id > 0) {
            $resolved_client_id = retentionResolveRecordClient($record_type, $record_id);
            if ($client_id > 0 && $client_id !== $resolved_client_id) {
                throw new DomainException('The selected hold client does not own that record');
            }
            $client_id = $resolved_client_id;
        } elseif ($record_type !== '*') {
            throw new InvalidArgumentException('A record-specific hold requires a record ID');
        } else {
            $client = mysqli_fetch_assoc(retentionDbQuery("SELECT client_id FROM clients
                WHERE client_id = $client_id LIMIT 1 FOR UPDATE", 'Could not lock the held client'));
            if (!$client) {
                throw new DomainException('The held client is unavailable');
            }
        }
        $type_sql = retentionDbEscape(substr($record_type, 0, 40));
        $reason_sql = retentionDbEscape($reason);
        retentionDbQuery("INSERT INTO retention_holds SET retention_hold_client_id = $client_id,
            retention_hold_record_type = '$type_sql', retention_hold_record_id = $record_id,
            retention_hold_reason = '$reason_sql', retention_hold_placed_by = $actor_id",
            'Could not place the retention hold');
        $hold_id = intval(mysqli_insert_id($mysqli));
        retentionAppendEvent($record_type, $record_id, $client_id, 0, 'hold_placed',
            'admin', $actor_id, $reason, ['hold_id' => $hold_id]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the retention hold');
        }
        return $hold_id;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionReleaseHold(int $hold_id, string $reason, int $actor_id): void
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $reason = retentionValidateDeleteReason($reason);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the hold release transaction');
    }
    try {
        $hold = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_holds
            WHERE retention_hold_id = $hold_id LIMIT 1 FOR UPDATE", 'Could not lock the retention hold'));
        if (!$hold || !empty($hold['retention_hold_released_at'])) {
            throw new DomainException('The retention hold is not active');
        }
        $reason_sql = retentionDbEscape($reason);
        retentionDbQuery("UPDATE retention_holds SET retention_hold_released_by = $actor_id,
            retention_hold_release_reason = '$reason_sql', retention_hold_released_at = NOW()
            WHERE retention_hold_id = $hold_id AND retention_hold_released_at IS NULL LIMIT 1",
            'Could not release the retention hold');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The retention hold changed before release');
        }
        retentionAppendEvent((string) $hold['retention_hold_record_type'],
            intval($hold['retention_hold_record_id']), intval($hold['retention_hold_client_id']), 0,
            'hold_released', 'admin', $actor_id, $reason, ['hold_id' => $hold_id]);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the hold release');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionActiveHolds(string $record_type, int $record_id, int $client_id): array
{
    $type_sql = retentionDbEscape($record_type);
    $inheritance_sql = '';
    if ($record_type === 'attachment') {
        $inheritance_sql = " OR (retention_hold_record_type = 'ticket'
            AND retention_hold_record_id = (SELECT ticket_attachment_ticket_id
                FROM ticket_attachments WHERE ticket_attachment_id = $record_id LIMIT 1))";
    } elseif ($record_type === 'automation-event') {
        $inheritance_sql = " OR (retention_hold_record_type = 'ticket'
            AND retention_hold_record_id IN (
                SELECT e.automation_event_ticket_id FROM automation_events e
                    WHERE e.automation_event_id = $record_id AND e.automation_event_ticket_id > 0
                UNION
                SELECT i.automation_incident_ticket_id FROM automation_events e
                    INNER JOIN automation_incidents i
                        ON i.automation_incident_source = e.automation_event_source
                        AND i.automation_incident_key = e.automation_event_incident_key
                    WHERE e.automation_event_id = $record_id
                    AND i.automation_incident_ticket_id > 0
            ))";
    }
    $rows = retentionDbQuery("SELECT retention_hold_id, retention_hold_reason FROM retention_holds
        WHERE retention_hold_released_at IS NULL AND (
            (retention_hold_record_type = '$type_sql' AND retention_hold_record_id = $record_id)
            OR (retention_hold_client_id = $client_id AND retention_hold_record_type = '*'
                AND retention_hold_record_id = 0)
            $inheritance_sql
        ) ORDER BY retention_hold_id FOR UPDATE", 'Could not inspect active retention holds');
    $holds = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $holds[] = ['id' => intval($row['retention_hold_id']), 'reason' => $row['retention_hold_reason']];
    }
    return $holds;
}

function retentionCount(string $sql, string $context): int
{
    return intval(mysqli_fetch_row(retentionDbQuery($sql, $context))[0] ?? 0);
}

function retentionTicketProtectionSummary(int $ticket_id): array
{
    $checks = [
        'ticket_replies' => "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id",
        'worked_time' => "SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id
            AND ticket_reply_time_worked IS NOT NULL AND ticket_reply_time_worked <> '00:00:00'",
        'ticket_history' => "SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $ticket_id",
        'sla_time_history' => "SELECT COUNT(*) FROM sla_history WHERE sla_history_ticket_id = $ticket_id",
        'runbook_history' => "SELECT COUNT(*) FROM runbook_executions WHERE runbook_execution_ticket_id = $ticket_id",
        'tasks_or_state_history' => "SELECT COUNT(*) FROM tasks WHERE task_ticket_id = $ticket_id",
        'task_evidence' => "SELECT COUNT(*) FROM task_evidence e INNER JOIN tasks t
            ON t.task_id = e.task_evidence_task_id WHERE t.task_ticket_id = $ticket_id",
        'approvals' => "SELECT COUNT(*) FROM task_approvals a INNER JOIN tasks t
            ON t.task_id = a.approval_task_id WHERE t.task_ticket_id = $ticket_id",
        'documentation_evidence' => "SELECT COUNT(*) FROM documentation_evidence_locker
            WHERE documentation_evidence_source_ticket_id = $ticket_id
            OR (documentation_evidence_reference_type = 'ticket' AND documentation_evidence_reference_id = $ticket_id)",
        'documentation_obligations' => "SELECT COUNT(*) FROM ticket_documentation_obligations
            WHERE ticket_documentation_obligation_ticket_id = $ticket_id",
        'client_obligation_verifications' => "SELECT COUNT(*) FROM client_documentation_obligations
            WHERE documentation_obligation_verification_ticket_id = $ticket_id",
        'change_passports' => "SELECT COUNT(*) FROM documentation_change_passports
            WHERE documentation_change_passport_ticket_id = $ticket_id",
        'promise_history' => "SELECT COUNT(*) FROM documentation_promise_ledger
            WHERE documentation_promise_ticket_id = $ticket_id",
        'documentation_promise_events' => "SELECT COUNT(*) FROM documentation_promise_events
            WHERE documentation_promise_event_ticket_id = $ticket_id",
        'agreement_sla_decisions' => "SELECT COUNT(*) FROM ticket_agreement_decisions
            WHERE ticket_agreement_decision_ticket_id = $ticket_id",
        'portal_requests' => "SELECT COUNT(*) FROM portal_request_submissions
            WHERE portal_request_submission_ticket_id = $ticket_id",
        'portal_dispatch' => "SELECT COUNT(*) FROM portal_request_dispatch_outbox
            WHERE portal_request_dispatch_ticket_id = $ticket_id",
        'automation_events' => "SELECT COUNT(*) FROM automation_events WHERE automation_event_ticket_id = $ticket_id",
        'automation_incidents' => "SELECT COUNT(*) FROM automation_incidents WHERE automation_incident_ticket_id = $ticket_id",
        'level_alert_history' => "SELECT COUNT(*) FROM level_alert_links WHERE level_ticket_id = $ticket_id",
        'asset_change_history' => "SELECT COUNT(*) FROM asset_change_events WHERE asset_change_event_ticket_id = $ticket_id",
        'attachments' => "SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id",
        'asset_links' => "SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = $ticket_id",
        'operational_events' => "SELECT COUNT(*) FROM ticket_operational_events
            WHERE ticket_operational_event_ticket_id = $ticket_id",
        'relationships' => "SELECT COUNT(*) FROM ticket_relationships
            WHERE ticket_relationship_source_ticket_id = $ticket_id
            OR ticket_relationship_target_ticket_id = $ticket_id",
        'customer_promises' => "SELECT COUNT(*) FROM ticket_customer_promises
            WHERE ticket_customer_promise_ticket_id = $ticket_id",
        'customer_promise_events' => "SELECT COUNT(*) FROM ticket_customer_promise_events
            WHERE ticket_customer_promise_event_ticket_id = $ticket_id",
        'email_ingress' => "SELECT COUNT(*) FROM ticket_email_ingress
            WHERE ticket_email_ingress_ticket_id = $ticket_id",
        'field_sync_requests' => "SELECT COUNT(*) FROM field_sync_requests
            WHERE field_sync_request_ticket_id = $ticket_id",
        'customer_signatures' => "SELECT COUNT(*) FROM ticket_customer_signatures
            WHERE ticket_customer_signature_ticket_id = $ticket_id",
    ];
    $counts = [];
    foreach ($checks as $key => $sql) {
        $counts[$key] = retentionCount($sql, "Could not inspect ticket purge dependency: $key");
    }
    $ticket = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_project_id, ticket_invoice_id, ticket_quote_id
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1", 'Could not inspect ticket ownership dependencies'));
    $counts['project_link'] = intval($ticket['ticket_project_id'] ?? 0) > 0 ? 1 : 0;
    $counts['financial_link'] = (intval($ticket['ticket_invoice_id'] ?? 0) > 0
        || intval($ticket['ticket_quote_id'] ?? 0) > 0) ? 1 : 0;
    // Goal 6 exposes this consolidated check. Keep the explicit counts above
    // for operator visibility and use the helper as a fail-closed integration
    // seam if its dependency set grows in a later ticket-discipline release.
    $counts['operational_history_helper'] = function_exists('ticketOperationalTicketHasImmutableHistory')
        && ticketOperationalTicketHasImmutableHistory($ticket_id) ? 1 : 0;
    return $counts;
}

function retentionProtectionSummary(array $deletion): array
{
    $record_type = (string) $deletion['retention_deletion_record_type'];
    $record_id = intval($deletion['retention_deletion_record_id']);
    $client_id = intval($deletion['retention_deletion_client_id']);
    // Preview and execution both run inside a transaction. Lock the owner
    // policy so a concurrent disable/change cannot race this decision.
    $policy = retentionPolicy(retentionRecordPolicyKey($record_type), true);
    $blockers = [];
    $dependencies = [];
    if ($policy['purge_mode'] === 'disabled') {
        $blockers[] = 'policy_disabled';
    }
    if (in_array($record_type, ['file', 'attachment'], true)
        && $deletion['retention_deletion_quarantine_status'] !== 'quarantined') {
        $blockers[] = 'quarantine_incomplete';
    }
    if (strtotime((string) $deletion['retention_deletion_purge_eligible_at']) > time()) {
        $blockers[] = 'retention_period_active';
    }
    $holds = retentionActiveHolds($record_type, $record_id, $client_id);
    if ($holds) {
        $blockers[] = 'retention_hold';
        $dependencies['holds'] = $holds;
    }
    if ($record_type === 'ticket') {
        $dependencies += retentionTicketProtectionSummary($record_id);
        foreach ($dependencies as $key => $count) {
            if (is_int($count) && $count > 0) {
                $blockers[] = $key;
            }
        }
    } elseif ($record_type === 'file') {
        $dependencies['evidence_references'] = documentationEvidenceReferenceInUse('file', $record_id, $client_id) ? 1 : 0;
        $dependencies['document_links'] = retentionCount("SELECT COUNT(*) FROM document_files
            WHERE file_id = $record_id", 'Could not inspect document file retention');
        foreach (['asset_files', 'contact_files', 'software_files', 'quote_files', 'vendor_files'] as $table) {
            $dependencies[$table] = retentionCount("SELECT COUNT(*) FROM $table WHERE file_id = $record_id",
                "Could not inspect $table retention");
        }
        $dependencies['shared_items'] = retentionCount("SELECT COUNT(*) FROM shared_items
            WHERE item_type = 'File' AND item_related_id = $record_id AND item_client_id = $client_id",
            'Could not inspect shared-file retention');
        if ($dependencies['evidence_references']) {
            $blockers[] = 'evidence_references';
        }
        if ($dependencies['document_links']) {
            $blockers[] = 'document_links';
        }
        foreach (['asset_files', 'contact_files', 'software_files', 'quote_files', 'vendor_files', 'shared_items'] as $key) {
            if ($dependencies[$key]) {
                $blockers[] = $key;
            }
        }
    } elseif ($record_type === 'attachment') {
        $dependencies['runbook_evidence'] = retentionCount("SELECT COUNT(*) FROM task_evidence
            WHERE task_evidence_attachment_id = $record_id", 'Could not inspect attachment evidence retention');
        $dependencies['customer_signatures'] = retentionCount("SELECT COUNT(*) FROM ticket_customer_signatures
            WHERE ticket_customer_signature_attachment_id = $record_id",
            'Could not inspect attachment signature retention');
        if ($dependencies['runbook_evidence']) {
            $blockers[] = 'runbook_evidence';
        }
        if ($dependencies['customer_signatures']) {
            $blockers[] = 'customer_signatures';
        }
    }
    $blockers = array_values(array_unique($blockers));
    return [
        'eligible' => !$blockers,
        'blockers' => $blockers,
        'dependencies' => $dependencies,
        'policy' => $policy,
    ];
}

function retentionPreviewPurge(int $actor_id, ?string $idempotency_key = null, int $limit = 500): array
{
    global $mysqli;
    if ($actor_id > 0) {
        retentionRequireAdministratorActor($actor_id);
    } elseif ($idempotency_key === null || !str_starts_with($idempotency_key, 'scheduled:')) {
        throw new DomainException('System purge previews require a scheduled idempotency key');
    }
    $limit = max(1, min(2000, $limit));
    $idempotency_key = $idempotency_key ?: 'manual:' . $actor_id . ':' . date('YmdHis') . ':' . bin2hex(random_bytes(8));
    $key_sql = retentionDbEscape(substr($idempotency_key, 0, 191));
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the purge preview transaction');
    }
    try {
        retentionDbQuery("INSERT IGNORE INTO retention_purge_batches SET
            retention_purge_batch_idempotency_key = '$key_sql',
            retention_purge_batch_mode = 'preview', retention_purge_batch_status = 'Previewed',
            retention_purge_batch_requested_by = $actor_id", 'Could not create the purge preview');
        $batch = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_purge_batches
            WHERE retention_purge_batch_idempotency_key = '$key_sql' LIMIT 1 FOR UPDATE",
            'Could not lock the purge preview'));
        $batch_id = intval($batch['retention_purge_batch_id']);
        if (retentionCount("SELECT COUNT(*) FROM retention_purge_items
            WHERE retention_purge_item_batch_id = $batch_id", 'Could not inspect the purge preview') === 0) {
            $rows = retentionDbQuery("SELECT * FROM retention_deletions
                WHERE retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL
                AND retention_deletion_purge_eligible_at <= NOW()
                ORDER BY retention_deletion_purge_eligible_at, retention_deletion_id LIMIT $limit FOR UPDATE",
                'Could not load purge preview candidates');
            $candidate_count = 0;
            $eligible_count = 0;
            $blocked_count = 0;
            while ($deletion = mysqli_fetch_assoc($rows)) {
                $candidate_count++;
                $summary = retentionProtectionSummary($deletion);
                $outcome = $summary['eligible'] ? 'Eligible' : 'Blocked';
                $summary['eligible'] ? $eligible_count++ : $blocked_count++;
                $reason = $summary['eligible'] ? 'Eligible after retention and restore windows'
                    : implode(', ', $summary['blockers']);
                $dependency_json = json_encode(retentionCanonicalize($summary), JSON_UNESCAPED_SLASHES);
                if ($dependency_json === false) {
                    throw new RuntimeException('Could not serialize the purge dependency preview');
                }
                $type_sql = retentionDbEscape($deletion['retention_deletion_record_type']);
                $policy_key_sql = retentionDbEscape(retentionRecordPolicyKey($deletion['retention_deletion_record_type']));
                $outcome_sql = retentionDbEscape($outcome);
                $reason_sql = retentionDbEscape(substr($reason, 0, 1000));
                $dependency_sql = retentionDbEscape($dependency_json);
                $dependency_hash = hash('sha256', $dependency_json);
                retentionDbQuery("INSERT IGNORE INTO retention_purge_items SET
                    retention_purge_item_batch_id = $batch_id,
                    retention_purge_item_record_type = '$type_sql',
                    retention_purge_item_record_id = " . intval($deletion['retention_deletion_record_id']) . ",
                    retention_purge_item_client_id = " . intval($deletion['retention_deletion_client_id']) . ",
                    retention_purge_item_generation = " . intval($deletion['retention_deletion_generation']) . ",
                    retention_purge_item_policy_key = '$policy_key_sql',
                    retention_purge_item_outcome = '$outcome_sql',
                    retention_purge_item_reason = '$reason_sql',
                    retention_purge_item_dependency_summary = '$dependency_sql',
                    retention_purge_item_dependency_hash = '$dependency_hash'",
                    'Could not capture a purge preview item');
                retentionAppendEvent((string) $deletion['retention_deletion_record_type'],
                    intval($deletion['retention_deletion_record_id']),
                    intval($deletion['retention_deletion_client_id']),
                    intval($deletion['retention_deletion_generation']),
                    $summary['eligible'] ? 'purge_previewed' : 'purge_blocked',
                    $actor_id ? 'admin' : 'system', $actor_id, $reason, $summary, $batch_id);
            }
            retentionDbQuery("UPDATE retention_purge_batches SET
                retention_purge_batch_candidate_count = $candidate_count,
                retention_purge_batch_eligible_count = $eligible_count,
                retention_purge_batch_blocked_count = $blocked_count
                WHERE retention_purge_batch_id = $batch_id LIMIT 1", 'Could not finalize the purge preview');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the purge preview');
        }
        return ['batch_id' => $batch_id, 'idempotency_key' => $idempotency_key];
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionPurgeRunToken(): string
{
    return hash('sha256', random_bytes(32));
}

function retentionClaimPurgeItem(int $batch_id, string $run_token): ?array
{
    global $mysqli;
    $batch_id = max(1, $batch_id);
    $token_sql = retentionDbEscape($run_token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the purge item claim');
    }
    try {
        $batch = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_purge_batch_id
            FROM retention_purge_batches WHERE retention_purge_batch_id = $batch_id
            AND retention_purge_batch_status = 'Running'
            AND retention_purge_batch_run_token = '$token_sql' LIMIT 1 FOR UPDATE",
            'Could not lock the running purge batch'));
        if (!$batch) {
            throw new DomainException('The purge batch execution lease is no longer owned by this request');
        }
        retentionDbQuery("UPDATE retention_purge_batches SET
            retention_purge_batch_lease_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE retention_purge_batch_id = $batch_id
            AND retention_purge_batch_status = 'Running'
            AND retention_purge_batch_run_token = '$token_sql' LIMIT 1",
            'Could not renew the purge batch execution lease');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The purge batch lease changed during renewal');
        }
        $item = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_purge_items
            WHERE retention_purge_item_batch_id = $batch_id AND (
                retention_purge_item_outcome = 'Eligible'
                OR (retention_purge_item_outcome = 'Processing'
                    AND retention_purge_item_claimed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
            ) ORDER BY retention_purge_item_id LIMIT 1 FOR UPDATE", 'Could not claim a purge item'));
        if (!$item) {
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the empty purge claim');
            }
            return null;
        }
        $item_id = intval($item['retention_purge_item_id']);
        retentionDbQuery("UPDATE retention_purge_items SET retention_purge_item_outcome = 'Processing',
            retention_purge_item_claim_token = '$token_sql', retention_purge_item_claimed_at = NOW(),
            retention_purge_item_reason = 'Claimed for approved dependency recheck'
            WHERE retention_purge_item_id = $item_id
            AND retention_purge_item_batch_id = $batch_id AND (
                retention_purge_item_outcome = 'Eligible'
                OR (retention_purge_item_outcome = 'Processing'
                    AND retention_purge_item_claimed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))
            ) LIMIT 1", 'Could not persist the purge item claim');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The purge item changed during claim');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the purge item claim');
        }
        $item['retention_purge_item_outcome'] = 'Processing';
        $item['retention_purge_item_claim_token'] = $run_token;
        return $item;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionLockPurgeTarget(string $record_type, int $record_id, int $client_id): array
{
    if ($client_id < 1) {
        throw new RuntimeException('A retained operational record has no canonical client scope');
    }
    // All operational writers use the same client -> ticket/record lock order.
    // The ledger, holds, and dependency matrix are intentionally locked only
    // after the target so no new immutable history can race the final recheck.
    documentationLockClientForExpiry($client_id);
    if ($record_type === 'ticket') {
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id, ticket_client_id FROM tickets
            WHERE ticket_id = $record_id AND ticket_client_id = $client_id
            AND ticket_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE", 'Could not lock the ticket purge target'));
    } elseif ($record_type === 'file') {
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT file_id, file_client_id FROM files
            WHERE file_id = $record_id AND file_client_id = $client_id
            AND file_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE", 'Could not lock the file purge target'));
    } elseif ($record_type === 'attachment') {
        $prelock = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_attachment_ticket_id
            FROM ticket_attachments WHERE ticket_attachment_id = $record_id LIMIT 1",
            'Could not locate the attachment purge target'));
        if (!$prelock) {
            throw new RuntimeException('The deleted attachment no longer exists');
        }
        $ticket_id = intval($prelock['ticket_attachment_ticket_id']);
        $ticket = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id FROM tickets
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE",
            'Could not lock the attachment parent ticket'));
        if (!$ticket) {
            throw new RuntimeException('The attachment parent changed client scope');
        }
        $target = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_attachment_id,
            ticket_attachment_ticket_id FROM ticket_attachments
            WHERE ticket_attachment_id = $record_id
            AND ticket_attachment_ticket_id = $ticket_id
            AND ticket_attachment_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
            'Could not lock the attachment purge target'));
    } else {
        throw new InvalidArgumentException('Unsupported purge record type');
    }
    if (!$target) {
        throw new RuntimeException('The deleted purge target no longer exists in its retained client scope');
    }
    return $target;
}

function retentionPurgeRecord(array $item, int $actor_id, int $batch_id, string $run_token): string
{
    global $mysqli;
    $record_type = (string) $item['retention_purge_item_record_type'];
    $record_id = intval($item['retention_purge_item_record_id']);
    $generation = intval($item['retention_purge_item_generation']);
    $client_id = intval($item['retention_purge_item_client_id']);
    $item_id = intval($item['retention_purge_item_id']);
    $token_sql = retentionDbEscape($run_token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the permanent purge transaction');
    }
    try {
        retentionLockPurgeTarget($record_type, $record_id, $client_id);
        $deletion = retentionDeletionForUpdate($record_type, $record_id);
        if (intval($deletion['retention_deletion_generation']) !== $generation
            || intval($deletion['retention_deletion_client_id']) !== $client_id) {
            throw new DomainException('The record entered a newer deletion lifecycle');
        }
        $batch = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_purge_batch_id
            FROM retention_purge_batches WHERE retention_purge_batch_id = $batch_id
            AND retention_purge_batch_status = 'Running'
            AND retention_purge_batch_run_token = '$token_sql' LIMIT 1 FOR UPDATE",
            'Could not revalidate the purge batch lease'));
        $claimed_item = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_purge_item_id
            FROM retention_purge_items WHERE retention_purge_item_id = $item_id
            AND retention_purge_item_batch_id = $batch_id
            AND retention_purge_item_outcome = 'Processing'
            AND retention_purge_item_claim_token = '$token_sql' LIMIT 1 FOR UPDATE",
            'Could not revalidate the purge item claim'));
        if (!$batch || !$claimed_item) {
            throw new DomainException('The purge item claim is no longer valid');
        }
        // This is the authoritative check. It runs only after client, target,
        // ledger generation, batch, and item locks are held through commit.
        $summary = retentionProtectionSummary($deletion);
        if (!$summary['eligible']) {
            $reason = implode(', ', $summary['blockers']);
            retentionAppendEvent($record_type, $record_id,
                intval($deletion['retention_deletion_client_id']), $generation,
                'purge_blocked', 'admin', $actor_id, $reason, $summary, $batch_id);
            retentionDbQuery("UPDATE retention_purge_items SET retention_purge_item_outcome = 'Blocked',
                retention_purge_item_reason = '" . retentionDbEscape(substr($reason, 0, 1000)) . "',
                retention_purge_item_processed_at = NOW()
                WHERE retention_purge_item_id = $item_id
                AND retention_purge_item_outcome = 'Processing'
                AND retention_purge_item_claim_token = '$token_sql' LIMIT 1",
                'Could not record a newly blocked purge');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The blocked purge item changed before commit');
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the blocked purge result');
            }
            return 'blocked';
        }
        if ($record_type === 'ticket') {
            // Replies, history, time, workflow, evidence, and relationship rows
            // are unconditional blockers. Only ephemeral views/watchers can be
            // removed alongside an otherwise empty ticket.
            retentionDbQuery("DELETE FROM ticket_views WHERE view_ticket_id = $record_id", 'Could not purge ticket views');
            retentionDbQuery("DELETE FROM ticket_watchers WHERE watcher_ticket_id = $record_id", 'Could not purge ticket watchers');
            retentionDbQuery("DELETE FROM tickets WHERE ticket_id = $record_id AND ticket_client_id = $client_id
                AND ticket_deleted_at IS NOT NULL LIMIT 1", 'Could not permanently purge the ticket');
        } elseif ($record_type === 'file') {
            retentionDbQuery("DELETE FROM files WHERE file_id = $record_id AND file_client_id = $client_id
                AND file_deleted_at IS NOT NULL LIMIT 1", 'Could not permanently purge the file');
        } elseif ($record_type === 'attachment') {
            retentionDbQuery("DELETE FROM ticket_attachments WHERE ticket_attachment_id = $record_id
                AND ticket_attachment_deleted_at IS NOT NULL LIMIT 1", 'Could not permanently purge the attachment');
        } else {
            throw new InvalidArgumentException('Unsupported purge record type');
        }
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The retained record changed before permanent purge');
        }
        retentionDbQuery("UPDATE retention_deletions SET retention_deletion_purged_by = $actor_id,
            retention_deletion_purged_at = NOW(), retention_deletion_quarantine_status =
                CASE WHEN retention_deletion_quarantine_path IS NULL THEN 'none' ELSE 'purge_pending' END,
            retention_deletion_last_error = NULL
            WHERE retention_deletion_id = " . intval($deletion['retention_deletion_id']) . "
            AND retention_deletion_generation = $generation
            AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL LIMIT 1",
            'Could not finalize the permanent purge ledger');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The purge ledger changed before commit');
        }
        retentionAppendEvent($record_type, $record_id, $client_id, $generation, 'permanently_purged',
            'admin', $actor_id, 'Approved purge batch', $summary, $batch_id);
        retentionDbQuery("UPDATE retention_purge_items SET retention_purge_item_outcome = 'Purged',
            retention_purge_item_reason = 'Permanently purged after dependency recheck',
            retention_purge_item_processed_at = NOW()
            WHERE retention_purge_item_id = $item_id
            AND retention_purge_item_outcome = 'Processing'
            AND retention_purge_item_claim_token = '$token_sql' LIMIT 1",
            'Could not finalize the purge item');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The purge item claim changed before commit');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the permanent purge');
        }
        return 'purged';
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionFailClaimedPurgeItem(array $item, int $actor_id, int $batch_id,
    string $run_token, Throwable $error): bool
{
    global $mysqli;
    $item_id = intval($item['retention_purge_item_id']);
    $token_sql = retentionDbEscape($run_token);
    $message = substr($error->getMessage(), 0, 1000);
    $message_sql = retentionDbEscape($message);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the purge failure audit');
    }
    try {
        $claimed = mysqli_fetch_assoc(retentionDbQuery("SELECT * FROM retention_purge_items
            WHERE retention_purge_item_id = $item_id
            AND retention_purge_item_batch_id = $batch_id
            AND retention_purge_item_outcome = 'Processing'
            AND retention_purge_item_claim_token = '$token_sql' LIMIT 1 FOR UPDATE",
            'Could not lock the failed purge item'));
        if (!$claimed) {
            mysqli_rollback($mysqli);
            return false;
        }
        retentionDbQuery("UPDATE retention_purge_items SET retention_purge_item_outcome = 'Failed',
            retention_purge_item_reason = '$message_sql', retention_purge_item_processed_at = NOW()
            WHERE retention_purge_item_id = $item_id
            AND retention_purge_item_outcome = 'Processing'
            AND retention_purge_item_claim_token = '$token_sql' LIMIT 1",
            'Could not record a failed purge item');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The failed purge item claim changed before audit');
        }
        retentionAppendEvent((string) $claimed['retention_purge_item_record_type'],
            intval($claimed['retention_purge_item_record_id']),
            intval($claimed['retention_purge_item_client_id']),
            intval($claimed['retention_purge_item_generation']), 'purge_failed', 'admin', $actor_id,
            substr($message, 0, 500), ['purge_item_id' => $item_id], $batch_id);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the purge failure audit');
        }
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function retentionExecuteClaimedBatch(int $batch_id, int $actor_id, string $run_token): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    $purged = 0;
    $blocked = 0;
    $failed = 0;
    while ($item = retentionClaimPurgeItem($batch_id, $run_token)) {
        try {
            $result = retentionPurgeRecord($item, $actor_id, $batch_id, $run_token);
            $result === 'purged' ? $purged++ : $blocked++;
        } catch (Throwable $e) {
            if (retentionFailClaimedPurgeItem($item, $actor_id, $batch_id, $run_token, $e)) {
                $failed++;
            } else {
                throw new RuntimeException('The purge execution lease changed while recording a failure', 0, $e);
            }
        }
    }
    $token_sql = retentionDbEscape($run_token);
    retentionDbQuery("UPDATE retention_purge_batches SET retention_purge_batch_status = 'Completed',
        retention_purge_batch_purged_count = retention_purge_batch_purged_count + $purged,
        retention_purge_batch_blocked_count = retention_purge_batch_blocked_count + $blocked,
        retention_purge_batch_failed_count = retention_purge_batch_failed_count + $failed,
        retention_purge_batch_completed_at = NOW(), retention_purge_batch_lease_until = NULL
        WHERE retention_purge_batch_id = $batch_id
        AND retention_purge_batch_status = 'Running'
        AND retention_purge_batch_run_token = '$token_sql' LIMIT 1", 'Could not complete the purge batch');
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('The purge batch execution lease changed before completion');
    }
    retentionCleanupQuarantine(200);
    return ['purged' => $purged, 'blocked' => $blocked, 'failed' => $failed];
}

function retentionApproveAndExecuteBatch(int $batch_id, int $actor_id, string $confirmation): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    if (!hash_equals('PURGE ' . $batch_id, trim($confirmation))) {
        throw new DomainException("Type PURGE $batch_id to approve permanent deletion");
    }
    $run_token = retentionPurgeRunToken();
    $token_sql = retentionDbEscape($run_token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin purge batch approval');
    }
    try {
        retentionDbQuery("UPDATE retention_purge_batches SET retention_purge_batch_mode = 'execute',
            retention_purge_batch_status = 'Running', retention_purge_batch_approved_by = $actor_id,
            retention_purge_batch_approved_at = NOW(), retention_purge_batch_run_token = '$token_sql',
            retention_purge_batch_lease_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE retention_purge_batch_id = $batch_id
            AND retention_purge_batch_status = 'Previewed' LIMIT 1", 'Could not approve the purge batch');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new DomainException('The purge preview was already claimed or is not approvable');
        }
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit purge batch approval');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }

    return retentionExecuteClaimedBatch($batch_id, $actor_id, $run_token);
}

function retentionResumeBatch(int $batch_id, int $actor_id, string $confirmation): array
{
    global $mysqli;
    retentionRequireAdministratorActor($actor_id);
    if (!hash_equals('RESUME PURGE ' . $batch_id, trim($confirmation))) {
        throw new DomainException("Type RESUME PURGE $batch_id to reclaim an interrupted purge");
    }
    $run_token = retentionPurgeRunToken();
    $token_sql = retentionDbEscape($run_token);
    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin purge batch recovery');
    }
    try {
        retentionDbQuery("UPDATE retention_purge_batches SET
            retention_purge_batch_run_token = '$token_sql',
            retention_purge_batch_lease_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE),
            retention_purge_batch_resume_count = retention_purge_batch_resume_count + 1,
            retention_purge_batch_approved_by = $actor_id
            WHERE retention_purge_batch_id = $batch_id
            AND retention_purge_batch_status = 'Running'
            AND retention_purge_batch_lease_until < NOW() LIMIT 1", 'Could not reclaim the purge batch');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new DomainException('The purge batch is active, completed, or not resumable');
        }
        retentionAppendEvent('purge-batch', $batch_id, 0, 0, 'purge_resumed', 'admin', $actor_id,
            'Explicit recovery of an expired purge execution lease', [] , $batch_id);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit purge batch recovery');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    return retentionExecuteClaimedBatch($batch_id, $actor_id, $run_token);
}

function retentionRemoveQuarantineDirectory(string $relative): bool
{
    $dir = retentionQuarantineAbsolute($relative);
    if ($dir === null) {
        return true;
    }
    $dir_stat = @lstat($dir);
    if ($dir_stat === false) {
        return !is_link($dir);
    }
    $root = retentionQuarantineRoot();
    if (($dir_stat['mode'] & 0170000) !== 0040000 || is_link($dir)) {
        return false;
    }
    retentionAssertNoSymlinkComponents($dir, $root);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = str_replace('\\', '/', $entry->getPathname());
        if (!retentionPathIsWithin($path, $root)) {
            return false;
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) === 0120000 || is_link($path)) {
            return false;
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0100000) {
            if (!unlink($path)) {
                return false;
            }
        } elseif ($type === 0040000) {
            if (!rmdir($path)) {
                return false;
            }
        } else {
            return false;
        }
    }
    return rmdir($dir);
}

function retentionCleanupQuarantine(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    $rows = retentionDbQuery("SELECT retention_deletion_id, retention_deletion_quarantine_path
        FROM retention_deletions WHERE retention_deletion_purged_at IS NOT NULL
        AND retention_deletion_quarantine_status IN ('purge_pending','cleanup_failed')
        ORDER BY retention_deletion_id LIMIT $limit", 'Could not load quarantine cleanup work');
    $cleaned = 0;
    $failed = 0;
    while ($row = mysqli_fetch_assoc($rows)) {
        $id = intval($row['retention_deletion_id']);
        try {
            if (!retentionRemoveQuarantineDirectory((string) $row['retention_deletion_quarantine_path'])) {
                throw new RuntimeException('Quarantine cleanup refused or failed');
            }
            retentionDbQuery("UPDATE retention_deletions SET retention_deletion_quarantine_status = 'purged',
                retention_deletion_last_error = NULL WHERE retention_deletion_id = $id
                AND retention_deletion_quarantine_status IN ('purge_pending','cleanup_failed') LIMIT 1",
                'Could not finalize quarantine cleanup');
            $cleaned++;
        } catch (Throwable $e) {
            $message_sql = retentionDbEscape(substr($e->getMessage(), 0, 1000));
            retentionDbQuery("UPDATE retention_deletions SET retention_deletion_quarantine_status = 'cleanup_failed',
                retention_deletion_last_error = '$message_sql' WHERE retention_deletion_id = $id LIMIT 1",
                'Could not record quarantine cleanup failure');
            $failed++;
        }
    }
    return ['cleaned' => $cleaned, 'failed' => $failed];
}

function retentionResolvedPayloadClient(array $row): int
{
    $clients = array_values(array_unique(array_filter([
        intval($row['ticket_client_id'] ?? 0),
        intval($row['incident_client_id'] ?? 0),
        intval($row['snapshot_client_id'] ?? 0),
    ], static fn (int $client_id): bool => $client_id > 0)));
    // Missing ownership and conflicting ticket/incident ownership both fail
    // closed. Payload minimization may resume only after the client scope is
    // unambiguous, so a legal/client-wide hold can never be bypassed.
    return count($clients) === 1 ? $clients[0] : 0;
}

function retentionRedactPayloads(int $limit = 1000): array
{
    global $mysqli;
    $limit = max(1, min(5000, $limit));
    $event_policy = retentionPolicy('automation_payloads');
    $snapshot_policy = retentionPolicy('normalized_payloads');
    $event_days = intval($event_policy['retention_days']);
    $snapshot_days = intval($snapshot_policy['retention_days']);
    $summary = [
        'automation_payloads' => 0,
        'normalized_payloads' => 0,
        'held_or_unscoped' => 0,
    ];

    if ($event_policy['purge_mode'] !== 'automatic' && $snapshot_policy['purge_mode'] !== 'automatic') {
        return $summary;
    }

    // Dead letters remain replayable and are not minimized until resolved.
    $events = $event_policy['purge_mode'] === 'automatic' ? retentionDbQuery("SELECT e.automation_event_id,
        e.automation_event_source, e.automation_event_payload_hash,
        t.ticket_client_id, i.automation_incident_client_id AS incident_client_id
        FROM automation_events e
        LEFT JOIN tickets t ON t.ticket_id = e.automation_event_ticket_id
        LEFT JOIN automation_incidents i ON i.automation_incident_source = e.automation_event_source
            AND i.automation_incident_key = e.automation_event_incident_key
        WHERE e.automation_event_status = 'Processed' AND e.automation_event_payload IS NOT NULL
        AND e.automation_event_payload_redacted_at IS NULL
        AND TIMESTAMPDIFF(DAY, e.automation_event_last_received_at, NOW()) >= GREATEST($event_days,
            COALESCE((SELECT automation_policy_payload_retention_days FROM automation_event_policies
                WHERE automation_policy_source = e.automation_event_source LIMIT 1), $event_days))
        ORDER BY e.automation_event_id LIMIT $limit", 'Could not load event payload retention work') : false;
    while ($events && ($event = mysqli_fetch_assoc($events))) {
        $event_id = intval($event['automation_event_id']);
        $candidate_client_id = retentionResolvedPayloadClient($event);
        if ($candidate_client_id < 1) {
            $summary['held_or_unscoped']++;
            continue;
        }
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin event payload redaction');
        }
        try {
            documentationLockClient($candidate_client_id);
            $locked_event = mysqli_fetch_assoc(retentionDbQuery("SELECT e.automation_event_id,
                e.automation_event_source, e.automation_event_payload_hash,
                t.ticket_client_id, i.automation_incident_client_id AS incident_client_id
                FROM automation_events e
                LEFT JOIN tickets t ON t.ticket_id = e.automation_event_ticket_id
                LEFT JOIN automation_incidents i ON i.automation_incident_source = e.automation_event_source
                    AND i.automation_incident_key = e.automation_event_incident_key
                WHERE e.automation_event_id = $event_id
                AND e.automation_event_status = 'Processed'
                AND e.automation_event_payload IS NOT NULL
                AND e.automation_event_payload_redacted_at IS NULL
                LIMIT 1 FOR UPDATE", 'Could not lock an event payload retention candidate'));
            if (!$locked_event
                || retentionResolvedPayloadClient($locked_event) !== $candidate_client_id
                || retentionActiveHolds('automation-event', $event_id, $candidate_client_id)) {
                $summary['held_or_unscoped']++;
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not preserve a held event payload');
                }
                continue;
            }
            retentionDbQuery("UPDATE automation_events SET automation_event_payload = NULL,
                automation_event_payload_redacted_at = NOW()
                WHERE automation_event_id = $event_id AND automation_event_status = 'Processed'
                AND automation_event_payload IS NOT NULL AND automation_event_payload_redacted_at IS NULL LIMIT 1",
                'Could not redact an event payload');
            if (mysqli_affected_rows($mysqli) === 1) {
                retentionAppendEvent('automation-event', $event_id, $candidate_client_id, 0, 'payload_redacted',
                    'system', 0, 'Retention policy elapsed', [
                        'source' => $locked_event['automation_event_source'],
                        'payload_hash' => $locked_event['automation_event_payload_hash'],
                        'policy' => $event_policy,
                    ]);
                $summary['automation_payloads']++;
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit event payload redaction');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    }

    $snapshots = $snapshot_policy['purge_mode'] === 'automatic' ? retentionDbQuery("SELECT automation_snapshot_id,
        automation_snapshot_client_id AS snapshot_client_id,
        automation_snapshot_source, automation_snapshot_payload_hash FROM automation_entity_snapshots
        WHERE automation_snapshot_payload_redacted_at IS NULL
        AND automation_snapshot_payload <> '{}'
        AND TIMESTAMPDIFF(DAY, automation_snapshot_last_seen_at, NOW()) >= $snapshot_days
        ORDER BY automation_snapshot_id LIMIT $limit", 'Could not load normalized payload retention work') : false;
    while ($snapshots && ($snapshot = mysqli_fetch_assoc($snapshots))) {
        $snapshot_id = intval($snapshot['automation_snapshot_id']);
        $candidate_client_id = retentionResolvedPayloadClient($snapshot);
        if ($candidate_client_id < 1) {
            $summary['held_or_unscoped']++;
            continue;
        }
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin normalized payload redaction');
        }
        try {
            documentationLockClient($candidate_client_id);
            $locked_snapshot = mysqli_fetch_assoc(retentionDbQuery("SELECT automation_snapshot_id,
                automation_snapshot_client_id AS snapshot_client_id,
                automation_snapshot_source, automation_snapshot_payload_hash
                FROM automation_entity_snapshots WHERE automation_snapshot_id = $snapshot_id
                AND automation_snapshot_payload_redacted_at IS NULL
                AND automation_snapshot_payload <> '{}' LIMIT 1 FOR UPDATE",
                'Could not lock a normalized payload retention candidate'));
            if (!$locked_snapshot
                || retentionResolvedPayloadClient($locked_snapshot) !== $candidate_client_id
                || retentionActiveHolds('normalized-payload', $snapshot_id, $candidate_client_id)) {
                $summary['held_or_unscoped']++;
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not preserve a held normalized payload');
                }
                continue;
            }
            retentionDbQuery("UPDATE automation_entity_snapshots SET automation_snapshot_payload = '{}',
                automation_snapshot_payload_redacted_at = NOW()
                WHERE automation_snapshot_id = $snapshot_id
                AND automation_snapshot_payload_redacted_at IS NULL LIMIT 1",
                'Could not redact a normalized payload');
            if (mysqli_affected_rows($mysqli) === 1) {
                retentionAppendEvent('normalized-payload', $snapshot_id,
                    $candidate_client_id, 0, 'payload_redacted',
                    'system', 0, 'Retention policy elapsed', [
                        'source' => $locked_snapshot['automation_snapshot_source'],
                        'payload_hash' => $locked_snapshot['automation_snapshot_payload_hash'],
                        'policy' => $snapshot_policy,
                    ]);
                $summary['normalized_payloads']++;
            }
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit normalized payload redaction');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    }
    return $summary;
}

function retentionRunScheduledMaintenance(): array
{
    $quarantine = retentionRecoverPendingQuarantines(200);
    $restores = retentionRecoverPendingRestores(200);
    $redacted = retentionRedactPayloads(1000);
    $cleanup = retentionCleanupQuarantine(200);
    // The daily idempotency key makes dispatcher retries return the same
    // captured preview rather than duplicating lifecycle events or work.
    $preview = retentionPreviewPurge(0, 'scheduled:' . date('Y-m-d'), 1000);
    return ['quarantine' => $quarantine, 'restores' => $restores,
        'redacted' => $redacted, 'cleanup' => $cleanup, 'preview' => $preview];
}

function retentionRecoverPendingQuarantines(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    $rows = retentionDbQuery("SELECT retention_deletion_record_type,
        retention_deletion_record_id, retention_deletion_generation
        FROM retention_deletions
        WHERE retention_deletion_record_type IN ('file','attachment')
        AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL
        AND (retention_deletion_quarantine_status IN ('move_pending','move_failed')
            OR (retention_deletion_quarantine_status = 'move_running'
                AND retention_deletion_quarantine_attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
        ORDER BY retention_deletion_id LIMIT $limit",
        'Could not load durable quarantine recovery work');
    $summary = ['quarantined' => 0, 'failed' => 0, 'not_claimed' => 0];
    while ($row = mysqli_fetch_assoc($rows)) {
        $status = retentionFinalizePendingQuarantine(
            (string) $row['retention_deletion_record_type'],
            intval($row['retention_deletion_record_id']),
            intval($row['retention_deletion_generation'])
        );
        if ($status === 'quarantined') {
            $summary['quarantined']++;
        } elseif ($status === 'move_failed') {
            $summary['failed']++;
        } else {
            $summary['not_claimed']++;
        }
    }
    return $summary;
}

function retentionRecoverPendingRestores(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    $rows = retentionDbQuery("SELECT retention_deletion_record_type,
        retention_deletion_record_id, retention_deletion_generation
        FROM retention_deletions
        WHERE retention_deletion_record_type IN ('file','attachment')
        AND retention_deletion_restored_at IS NULL AND retention_deletion_purged_at IS NULL
        AND (retention_deletion_quarantine_status IN ('restore_pending','restore_failed')
            OR (retention_deletion_quarantine_status = 'restore_running'
                AND retention_deletion_quarantine_attempted_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)))
        ORDER BY retention_deletion_id LIMIT $limit",
        'Could not load durable restore recovery work');
    $summary = ['restored' => 0, 'failed' => 0, 'not_claimed' => 0];
    while ($row = mysqli_fetch_assoc($rows)) {
        $status = retentionFinalizePendingRestore(
            (string) $row['retention_deletion_record_type'],
            intval($row['retention_deletion_record_id']),
            intval($row['retention_deletion_generation'])
        );
        if ($status === 'restored') {
            $summary['restored']++;
        } elseif ($status === 'restore_failed') {
            $summary['failed']++;
        } else {
            $summary['not_claimed']++;
        }
    }
    return $summary;
}

function retentionReconcileDeletionLedger(int $limit = 500): array
{
    global $mysqli;
    // Repair only missing metadata for already-soft-deleted rows. Never infer a
    // deletion or mark a live row deleted. Reconciled rows remain unpurgable
    // until the full default policy elapses from the observed deletion time.
    $limit = max(1, min(500, $limit));
    $repaired = 0;
    $blocked = 0;
    foreach ([
        'ticket' => "SELECT t.ticket_id AS record_id, t.ticket_client_id AS client_id,
            t.ticket_deleted_at AS deleted_at, t.ticket_restore_until AS restore_until,
            t.ticket_purge_eligible_at AS purge_eligible_at,
            CONCAT(COALESCE(t.ticket_prefix,''), t.ticket_number, ' - ', t.ticket_subject) AS label
            FROM tickets t WHERE t.ticket_deleted_at IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM retention_deletions d WHERE d.retention_deletion_record_type = 'ticket'
                AND d.retention_deletion_record_id = t.ticket_id)
            ORDER BY t.ticket_id LIMIT $limit",
        'file' => "SELECT f.*, f.file_id AS record_id, f.file_client_id AS client_id,
            f.file_deleted_at AS deleted_at, f.file_restore_until AS restore_until,
            f.file_purge_eligible_at AS purge_eligible_at, f.file_name AS label
            FROM files f WHERE f.file_deleted_at IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM retention_deletions d WHERE d.retention_deletion_record_type = 'file'
                AND d.retention_deletion_record_id = f.file_id)
            ORDER BY f.file_id LIMIT $limit",
        'attachment' => "SELECT a.*, a.ticket_attachment_id AS record_id,
            t.ticket_client_id AS client_id, a.ticket_attachment_deleted_at AS deleted_at,
            a.ticket_attachment_restore_until AS restore_until,
            a.ticket_attachment_purge_eligible_at AS purge_eligible_at,
            a.ticket_attachment_name AS label
            FROM ticket_attachments a INNER JOIN tickets t ON t.ticket_id = a.ticket_attachment_ticket_id
            WHERE a.ticket_attachment_deleted_at IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM retention_deletions d WHERE d.retention_deletion_record_type = 'attachment'
                AND d.retention_deletion_record_id = a.ticket_attachment_id)
            ORDER BY a.ticket_attachment_id LIMIT $limit",
    ] as $type => $candidate_sql) {
        $rows = retentionDbQuery($candidate_sql, 'Could not inspect missing deletion ledger rows');
        while ($row = mysqli_fetch_assoc($rows)) {
            $finalize = null;
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin deletion-ledger reconciliation');
            }
            try {
                $record_id = intval($row['record_id']);
                $client_id = intval($row['client_id']);
                documentationLockClient($client_id);
                if ($type === 'ticket') {
                    $locked = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id AS record_id,
                        ticket_client_id AS client_id, ticket_deleted_at AS deleted_at,
                        ticket_restore_until AS restore_until,
                        ticket_purge_eligible_at AS purge_eligible_at,
                        CONCAT(COALESCE(ticket_prefix,''), ticket_number, ' - ', ticket_subject) AS label
                        FROM tickets WHERE ticket_id = $record_id AND ticket_client_id = $client_id
                        AND ticket_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
                        'Could not lock the reconciled ticket'));
                } elseif ($type === 'file') {
                    $locked = mysqli_fetch_assoc(retentionDbQuery("SELECT files.*,
                        file_id AS record_id, file_client_id AS client_id,
                        file_deleted_at AS deleted_at, file_restore_until AS restore_until,
                        file_purge_eligible_at AS purge_eligible_at, file_name AS label
                        FROM files WHERE file_id = $record_id AND file_client_id = $client_id
                        AND file_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
                        'Could not lock the reconciled file'));
                } else {
                    $ticket_id = intval($row['ticket_attachment_ticket_id']);
                    $parent = mysqli_fetch_assoc(retentionDbQuery("SELECT ticket_id FROM tickets
                        WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1 FOR UPDATE",
                        'Could not lock the reconciled attachment parent'));
                    $locked = $parent ? mysqli_fetch_assoc(retentionDbQuery("SELECT a.*,
                        a.ticket_attachment_id AS record_id, $client_id AS client_id,
                        a.ticket_attachment_deleted_at AS deleted_at,
                        a.ticket_attachment_restore_until AS restore_until,
                        a.ticket_attachment_purge_eligible_at AS purge_eligible_at,
                        a.ticket_attachment_name AS label
                        FROM ticket_attachments a WHERE a.ticket_attachment_id = $record_id
                        AND a.ticket_attachment_ticket_id = $ticket_id
                        AND a.ticket_attachment_deleted_at IS NOT NULL LIMIT 1 FOR UPDATE",
                        'Could not lock the reconciled attachment')) : null;
                }
                if (!$locked) {
                    if (!mysqli_commit($mysqli)) {
                        throw new RuntimeException('Could not commit the stale reconciliation inspection');
                    }
                    continue;
                }
                $existing = mysqli_fetch_assoc(retentionDbQuery("SELECT retention_deletion_id
                    FROM retention_deletions WHERE retention_deletion_record_type = '$type'
                    AND retention_deletion_record_id = $record_id LIMIT 1 FOR UPDATE",
                    'Could not recheck the deletion ledger'));
                if (!$existing) {
                    $policy = retentionPolicy(retentionRecordPolicyKey($type));
                    $deadline = [
                        'deleted_at' => $locked['deleted_at'],
                        'restore_until' => $locked['restore_until'],
                        'purge_eligible_at' => $locked['purge_eligible_at']
                            ?: date('Y-m-d H:i:s', strtotime($locked['deleted_at']) + ($policy['retention_days'] * 86400)),
                    ];
                    $plan = null;
                    $plan_error = null;
                    if ($type === 'file') {
                        try {
                            $plan = retentionPrepareQuarantinePlan('file', $record_id,
                                retentionFilePaths($locked));
                        } catch (Throwable $e) {
                            $plan_error = $e;
                        }
                    } elseif ($type === 'attachment') {
                        try {
                            $plan = retentionPrepareQuarantinePlan('attachment', $record_id, [
                                retentionUploadsRoot() . '/tickets/' . intval($locked['ticket_attachment_ticket_id'])
                                    . '/' . (string) $locked['ticket_attachment_reference_name'],
                            ]);
                        } catch (Throwable $e) {
                            $plan_error = $e;
                        }
                    }
                    $quarantine_status = $type === 'ticket' ? 'none' : ($plan ? 'move_pending' : 'unknown');
                    $generation = retentionWriteDeletionLedger($type, $record_id,
                        $client_id, (string) $locked['label'], 0,
                        'Reconciled missing soft-deletion ledger', $deadline,
                        $plan['relative_path'] ?? null, $quarantine_status, $plan['manifest'] ?? null);
                    if ($plan_error) {
                        $error_sql = retentionDbEscape(substr($plan_error->getMessage(), 0, 1000));
                        retentionDbQuery("UPDATE retention_deletions SET
                            retention_deletion_last_error = '$error_sql'
                            WHERE retention_deletion_record_type = '$type'
                            AND retention_deletion_record_id = $record_id LIMIT 1",
                            'Could not preserve the reconciliation quarantine error');
                        $blocked++;
                    } elseif ($plan) {
                        $finalize = [$type, $record_id, $generation];
                    }
                    retentionAppendEvent($type, $record_id, $client_id,
                        $generation, 'ledger_reconciled', 'system', 0,
                        'Recovered an existing soft-deletion marker without inferring a purge decision', [
                            'quarantine_status' => $quarantine_status,
                            'manifest_hash' => $plan['manifest_hash'] ?? null,
                            'error' => $plan_error?->getMessage(),
                        ]);
                    $repaired++;
                }
                if (!mysqli_commit($mysqli)) {
                    throw new RuntimeException('Could not commit deletion-ledger reconciliation');
                }
            } catch (Throwable $e) {
                mysqli_rollback($mysqli);
                throw $e;
            }
            if ($finalize) {
                $status = retentionFinalizePendingQuarantine(...$finalize);
                if ($status === 'move_failed') {
                    $blocked++;
                }
            }
        }
    }
    $recovery = retentionRecoverPendingQuarantines($limit);
    return ['repaired' => $repaired, 'blocked' => $blocked, 'quarantine' => $recovery];
}
