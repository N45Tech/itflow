<?php

defined('FROM_CLIENT_POST_HANDLER') || die('Direct file access is not allowed');

if (isset($_POST['submit_portal_request'])) {
    validateCSRFToken();
    $version_id = intval($_POST['version_id'] ?? 0);
    try {
        $result = portalRequestSubmit(
            $version_id,
            $session_client_id,
            $session_contact_id,
            $session_user_id,
            $_POST['field'] ?? [],
            $_POST['idempotency_key'] ?? ''
        );
        $submission_id = intval($result['submission_id']);
        $ticket_id = intval($result['ticket_id']);
        if (!$result['duplicate']) {
            logAudit('Portal Request', 'Create',
                "$session_contact_name submitted portal request #$submission_id",
                $session_client_id, $submission_id);
        }
        if ($ticket_id) {
            portalRequestDispatchAfterCommit($submission_id);
        }
        $status_messages = [
            'PendingApproval' => 'Request submitted and waiting for approval',
            'Initiated' => 'Request submitted and its service ticket was created',
            'Declined' => 'This request was already declined',
        ];
        flashAlert($status_messages[$result['status']] ?? 'The existing request was recovered safely');
        redirect("request_status.php?id=$submission_id");
    } catch (InvalidArgumentException $exception) {
        error_log('Portal catalog request validation failed: ' . $exception->getMessage());
        flashAlert('The request form could not be accepted. Refresh it, review the fields, and try again.', 'warning');
    } catch (Throwable $exception) {
        error_log('Portal catalog request failed: ' . $exception->getMessage());
        flashAlert('The request could not be submitted safely. Refresh the form and try again.', 'error');
    }
    redirect("request.php?version_id=$version_id");
}

if (isset($_POST['decide_portal_request'])) {
    validateCSRFToken();
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    try {
        $result = portalRequestDecide(
            $submission_id,
            $decision,
            'contact',
            $session_contact_id,
            $session_client_id
        );
        $ticket_id = intval($result['ticket_id']);
        logAudit('Portal Request', ucfirst($decision),
            "$session_contact_name $decision portal request #$submission_id",
            $session_client_id, $submission_id);
        if ($ticket_id) {
            portalRequestDispatchAfterCommit($submission_id);
        }
        flashAlert($decision === 'approved' ? 'Request approved and its ticket was created' : 'Request declined', $decision === 'approved' ? 'success' : 'warning');
    } catch (Throwable $exception) {
        error_log("Portal request #$submission_id decision failed: " . $exception->getMessage());
        flashAlert('The request could not be decided safely. Refresh the page and try again.', 'warning');
    }
    redirect("request_status.php?id=$submission_id");
}
