<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_retention_policy'])) {
    validateCSRFToken();
    enforceAdminPermission();
    try {
        retentionUpdatePolicy(
            (string) ($_POST['policy_key'] ?? ''),
            intval($_POST['retention_days'] ?? 0),
            intval($_POST['restore_window_days'] ?? 0),
            (string) ($_POST['purge_mode'] ?? 'disabled'),
            (string) ($_POST['owner_note'] ?? ''),
            intval($session_user_id),
            !empty($_POST['confirm_automatic'])
        );
        flashAlert('Retention policy and owner decision saved.');
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}

if (isset($_POST['soft_delete_retained_record'])) {
    validateCSRFToken();
    enforceAdminPermission();
    $record_type = (string) ($_POST['record_type'] ?? '');
    $record_id = intval($_POST['record_id'] ?? 0);
    $reason = (string) ($_POST['reason'] ?? '');
    try {
        if ($record_type === 'ticket') {
            $result = retentionSoftDeleteTicket($record_id, intval($session_user_id), $reason);
            triggerCustomAction('ticket_soft_delete', $record_id,
                'retention:ticket:' . $record_id . ':' . intval($result['generation']));
            logAudit('Retention', 'Soft Delete', "$session_name moved ticket $record_id to recoverable deletion",
                intval($result['client_id']), $record_id);
        } elseif ($record_type === 'file') {
            $row = mysqli_fetch_assoc(retentionDbQuery("SELECT file_client_id FROM files
                WHERE file_id = $record_id AND file_deleted_at IS NULL LIMIT 1",
                'Could not locate the file for recoverable deletion'));
            if (!$row) {
                throw new RuntimeException('The file is unavailable');
            }
            $result = retentionSoftDeleteFile($record_id, intval($row['file_client_id']), intval($session_user_id), $reason);
            if (($result['retention_quarantine_status'] ?? '') !== 'quarantined') {
                throw new RuntimeException('The file is safely hidden and journaled, but byte quarantine is pending recovery; run the reconciler.');
            }
            logAudit('Retention', 'Soft Delete', "$session_name moved file $record_id to recoverable deletion",
                intval($row['file_client_id']), $record_id);
        } elseif ($record_type === 'attachment') {
            $row = mysqli_fetch_assoc(retentionDbQuery("SELECT t.ticket_client_id FROM ticket_attachments a
                INNER JOIN tickets t ON t.ticket_id = a.ticket_attachment_ticket_id
                WHERE a.ticket_attachment_id = $record_id
                AND a.ticket_attachment_deleted_at IS NULL LIMIT 1",
                'Could not locate the attachment for recoverable deletion'));
            if (!$row) {
                throw new RuntimeException('The attachment is unavailable');
            }
            $result = retentionSoftDeleteAttachment($record_id, intval($session_user_id), $reason);
            if (($result['retention_quarantine_status'] ?? '') !== 'quarantined') {
                throw new RuntimeException('The attachment is safely hidden and journaled, but byte quarantine is pending recovery; run the reconciler.');
            }
            logAudit('Retention', 'Soft Delete', "$session_name quarantined attachment $record_id",
                intval($row['ticket_client_id']), $record_id);
        } else {
            throw new InvalidArgumentException('Select a supported record type');
        }
        flashAlert('Record moved to recoverable deletion. No operational history was removed.', 'info');
    } catch (Throwable $e) {
        error_log("Retention soft deletion failed for $record_type $record_id: " . $e->getMessage());
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}

if (isset($_POST['restore_retained_record'])) {
    validateCSRFToken();
    enforceAdminPermission();
    $record_type = (string) ($_POST['record_type'] ?? '');
    $record_id = intval($_POST['record_id'] ?? 0);
    try {
        retentionRestoreRecord($record_type, $record_id, intval($session_user_id),
            (string) ($_POST['reason'] ?? ''));
        logAudit('Retention', 'Restore', "$session_name restored $record_type $record_id", 0, $record_id);
        flashAlert('Record restored.');
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}

if (isset($_POST['place_retention_hold'])) {
    validateCSRFToken();
    enforceAdminPermission();
    try {
        $hold_id = retentionPlaceHold(
            intval($_POST['client_id'] ?? 0),
            (string) ($_POST['record_type'] ?? '*'),
            intval($_POST['record_id'] ?? 0),
            (string) ($_POST['reason'] ?? ''),
            intval($session_user_id)
        );
        logAudit('Retention', 'Hold', "$session_name placed retention hold $hold_id");
        flashAlert("Retention hold <strong>$hold_id</strong> placed.");
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}

if (isset($_POST['release_retention_hold'])) {
    validateCSRFToken();
    enforceAdminPermission();
    $hold_id = intval($_POST['hold_id'] ?? 0);
    try {
        retentionReleaseHold($hold_id, (string) ($_POST['reason'] ?? ''), intval($session_user_id));
        logAudit('Retention', 'Hold Release', "$session_name released retention hold $hold_id");
        flashAlert("Retention hold <strong>$hold_id</strong> released.");
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}

if (isset($_POST['preview_retention_purge'])) {
    validateCSRFToken();
    enforceAdminPermission();
    try {
        $preview = retentionPreviewPurge(intval($session_user_id));
        flashAlert('Purge dry-run captured. Review every eligible and blocked item before approval.', 'info');
        redirect('retention.php?batch_id=' . intval($preview['batch_id']));
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
        redirect('retention.php');
    }
}

if (isset($_POST['execute_retention_purge'])) {
    validateCSRFToken();
    enforceAdminPermission();
    $batch_id = intval($_POST['batch_id'] ?? 0);
    try {
        $result = retentionApproveAndExecuteBatch($batch_id, intval($session_user_id),
            (string) ($_POST['confirmation'] ?? ''));
        logAudit('Retention', 'Permanent Purge', "$session_name executed purge batch $batch_id: "
            . "{$result['purged']} purged, {$result['blocked']} blocked, {$result['failed']} failed");
        flashAlert("Purge completed: <strong>{$result['purged']}</strong> purged, "
            . "<strong>{$result['blocked']}</strong> blocked, <strong>{$result['failed']}</strong> failed.",
            $result['failed'] ? 'error' : 'info');
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php?batch_id=' . $batch_id);
}

if (isset($_POST['resume_retention_purge'])) {
    validateCSRFToken();
    enforceAdminPermission();
    $batch_id = intval($_POST['batch_id'] ?? 0);
    try {
        $result = retentionResumeBatch($batch_id, intval($session_user_id),
            (string) ($_POST['confirmation'] ?? ''));
        logAudit('Retention', 'Resume Purge', "$session_name resumed interrupted purge batch $batch_id: "
            . "{$result['purged']} purged, {$result['blocked']} blocked, {$result['failed']} failed");
        flashAlert("Resumed purge completed: <strong>{$result['purged']}</strong> purged, "
            . "<strong>{$result['blocked']}</strong> blocked, <strong>{$result['failed']}</strong> failed.",
            $result['failed'] ? 'error' : 'info');
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php?batch_id=' . $batch_id);
}

if (isset($_POST['reconcile_retention_ledger'])) {
    validateCSRFToken();
    enforceAdminPermission();
    try {
        $result = retentionReconcileDeletionLedger(500);
        flashAlert("Retention ledger reconciliation repaired <strong>{$result['repaired']}</strong> row(s); "
            . "<strong>{$result['blocked']}</strong> require byte recovery; "
            . "<strong>{$result['quarantine']['quarantined']}</strong> pending moves completed.",
            $result['blocked'] ? 'error' : 'info');
    } catch (Throwable $e) {
        flashAlert($e->getMessage(), 'error');
    }
    redirect('retention.php');
}
