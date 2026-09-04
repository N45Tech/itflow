<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

if (isset($_POST['decide_internal_portal_request'])) {
    validateCSRFToken();
    enforceUserPermission('module_support', 2);
    $submission_id = intval($_POST['submission_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $submission = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT portal_request_submission_client_id
        FROM portal_request_submissions WHERE portal_request_submission_id = $submission_id LIMIT 1"));
    if (!$submission) {
        flashAlert('Portal request not found', 'warning');
        redirect('portal_requests.php');
    }
    $client_id = intval($submission['portal_request_submission_client_id']);
    enforceClientAccess($client_id);
    try {
        $result = portalRequestDecide($submission_id, $decision, 'agent', $session_user_id, $client_id);
        $ticket_id = intval($result['ticket_id']);
        logAudit('Portal Request', ucfirst($decision),
            "$session_name $decision internal portal request #$submission_id",
            $client_id, $submission_id);
        if ($ticket_id) {
            portalRequestDispatchAfterCommit($submission_id);
        }
        flashAlert($decision === 'approved' ? 'Request approved and its ticket was created' : 'Request declined', $decision === 'approved' ? 'success' : 'warning');
    } catch (Throwable $exception) {
        flashAlert(escapeHtml($exception->getMessage()), 'warning');
    }
    redirect('portal_requests.php');
}
