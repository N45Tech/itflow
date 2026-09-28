<?php

require_once 'includes/inc_all.php';

$submission_id = intval($_GET['id'] ?? 0);
$submission = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    s.portal_request_submission_id, s.portal_request_submission_version_id,
    s.portal_request_submission_client_id, s.portal_request_submission_contact_id,
    s.portal_request_submission_ticket_id, s.portal_request_submission_status,
    s.portal_request_submission_responses, s.portal_request_submission_response_hash,
    s.portal_request_submission_submitted_at,
    (s.portal_request_submission_decided_by_type = 'contact'
        AND s.portal_request_submission_decided_by_id = $session_contact_id)
        AS session_contact_was_decider,
    v.portal_request_catalog_version_name, v.portal_request_catalog_version_icon,
    COALESCE(c.contact_name, 'Former contact') AS requester_name
    FROM portal_request_submissions s
    INNER JOIN portal_request_catalog_versions v
        ON v.portal_request_catalog_version_id = s.portal_request_submission_version_id
    LEFT JOIN contacts c ON c.contact_id = s.portal_request_submission_contact_id
        AND c.contact_client_id = s.portal_request_submission_client_id
    WHERE s.portal_request_submission_id = $submission_id
    AND s.portal_request_submission_client_id = $session_client_id LIMIT 1"));
if (!$submission) {
    flashAlert('Request not found', 'warning');
    redirect('requests.php');
}
try {
    $definition = portalRequestAssertVersion(intval($submission['portal_request_submission_version_id']));
} catch (Throwable $exception) {
    error_log("Portal request #$submission_id definition integrity failure: " . $exception->getMessage());
    $definition = null;
}
$contact_context = portalRequestContactContext($session_contact_id, $session_client_id);
$is_prior_decider = intval($submission['session_contact_was_decider']) === 1;
$is_owner_or_manager = intval($submission['portal_request_submission_contact_id']) === $session_contact_id
    || contactCan('tickets_all') || $is_prior_decider;
$is_eligible_approver = $definition
    && $submission['portal_request_submission_status'] === 'PendingApproval'
    && $definition['approval_rule'] !== 'internal'
    && $definition['approval_rule'] !== 'none'
    && intval($submission['portal_request_submission_contact_id']) !== $session_contact_id
    && portalRequestContactMatchesRule($contact_context, $definition['approval_rule']);
if (!$is_owner_or_manager && !$is_eligible_approver) {
    flashAlert('You do not have access to that request', 'warning');
    redirect('requests.php');
}
$responses = [];
if ($definition) {
    try {
        $responses = portalRequestResponsePayload($submission);
    } catch (Throwable $exception) {
        error_log("Portal request #$submission_id response integrity failure: " . $exception->getMessage());
        $definition = null;
    }
}
$events = mysqli_query($mysqli, "SELECT portal_request_submission_event_action,
    portal_request_submission_event_actor_type, portal_request_submission_event_note,
    portal_request_submission_event_created_at FROM portal_request_submission_events
    WHERE portal_request_submission_event_submission_id = $submission_id
    ORDER BY portal_request_submission_event_id ASC");
$can_decide = $is_eligible_approver && $definition;
$can_view_ticket = intval($submission['portal_request_submission_ticket_id']) > 0
    && (intval($submission['portal_request_submission_contact_id']) === $session_contact_id
        || contactCan('tickets_all'));
$visible_ticket = null;
$ticket_progress = [];
if ($can_view_ticket) {
    $ticket_id = intval($submission['portal_request_submission_ticket_id']);
    $ticket_contact_scope = contactCan('tickets_all') ? '' : "AND t.ticket_contact_id = $session_contact_id";
    $visible_ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.ticket_id, t.ticket_created_at,
        t.ticket_updated_at, t.ticket_first_response_at, t.ticket_resolved_at, t.ticket_closed_at, ts.ticket_status_name
        FROM tickets t LEFT JOIN ticket_statuses ts ON ts.ticket_status_id = t.ticket_status
        WHERE t.ticket_id = $ticket_id AND t.ticket_client_id = $session_client_id
        AND t.ticket_archived_at IS NULL $ticket_contact_scope LIMIT 1"));
    $can_view_ticket = (bool) $visible_ticket;
    if ($visible_ticket) {
        $ticket_progress[] = ['label' => 'Ticket opened', 'at' => $visible_ticket['ticket_created_at']];
        $history = mysqli_query($mysqli, "SELECT ticket_history_status, ticket_history_created_at
            FROM ticket_history WHERE ticket_history_ticket_id = $ticket_id
            ORDER BY ticket_history_id ASC");
        $last_status = '';
        while ($history_entry = mysqli_fetch_assoc($history)) {
            $status = trim((string) $history_entry['ticket_history_status']);
            if ($status !== '' && $status !== $last_status) {
                $ticket_progress[] = ['label' => $status, 'at' => $history_entry['ticket_history_created_at']];
                $last_status = $status;
            }
        }
        $current_status = trim((string) $visible_ticket['ticket_status_name']);
        if ($current_status !== '' && $current_status !== $last_status) {
            $ticket_progress[] = ['label' => $current_status,
                'at' => $visible_ticket['ticket_closed_at'] ?: ($visible_ticket['ticket_resolved_at'] ?: ($visible_ticket['ticket_updated_at'] ?: $visible_ticket['ticket_created_at']))];
        }
        if ($visible_ticket['ticket_first_response_at']) {
            $ticket_progress[] = ['label' => 'First response', 'at' => $visible_ticket['ticket_first_response_at']];
        }
        usort($ticket_progress, static function ($a, $b) {
            return strcmp($a['at'], $b['at']);
        });
    }
}

?>

<ol class="breadcrumb"><li class="breadcrumb-item"><a href="requests.php">Request help</a></li><li class="breadcrumb-item active">Request #<?= $submission_id ?></li></ol>
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h1 class="h4 mb-0"><i class="<?= escapeHtml($submission['portal_request_catalog_version_icon']) ?> mr-2"></i><?= escapeHtml($submission['portal_request_catalog_version_name']) ?></h1></div>
            <div class="card-body">
                <p><span class="badge <?= portalRequestStatusBadgeClass($submission['portal_request_submission_status']) ?>"><?= escapeHtml(portalRequestStatusLabel($submission['portal_request_submission_status'])) ?></span> <span class="text-muted ml-2">Submitted by <?= escapeHtml($submission['requester_name']) ?> on <?= escapeHtml($submission['portal_request_submission_submitted_at']) ?></span></p>
                <?php if (!$definition) { ?><div class="alert alert-danger">This request snapshot failed its integrity check. Support has been notified in the application log.</div><?php } else { ?>
                    <dl><?php foreach ($definition['fields'] as $field) { ?><dt><?= escapeHtml($field['label']) ?></dt><dd><?= nl2br(escapeHtml(portalRequestResponseText($responses[$field['key']] ?? null))) ?></dd><?php } ?></dl>
                <?php } ?>
                <?php if ($can_view_ticket) { ?><p class="mb-3"><strong>Service ticket:</strong> <?= escapeHtml($visible_ticket['ticket_status_name'] ?: 'Open') ?></p><a class="btn btn-primary" href="ticket.php?id=<?= intval($visible_ticket['ticket_id']) ?>"><i class="fas fa-ticket-alt mr-1"></i>View service ticket</a><?php } ?>
            </div>
        </div>
        <?php if ($can_decide) { ?>
            <div class="card card-outline card-warning"><div class="card-header"><h2 class="h5 mb-0">Approval required</h2></div><div class="card-body"><p>The requester cannot approve their own request. Confirm that this work is authorized.</p><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="submission_id" value="<?= $submission_id ?>"><input type="hidden" name="decide_portal_request" value="1"><button class="btn btn-success mr-2" name="decision" value="approved"><i class="fas fa-check mr-1"></i>Approve</button><button class="btn btn-outline-danger" name="decision" value="declined"><i class="fas fa-times mr-1"></i>Decline</button></form></div></div>
        <?php } ?>
    </div>
    <div class="col-lg-4">
        <?php if ($can_view_ticket) { ?><div class="card"><div class="card-header"><h2 class="h5 mb-0">Service progress</h2></div><div class="card-body"><ol class="pl-3 mb-0"><?php foreach ($ticket_progress as $step) { ?><li class="mb-3"><strong><?= escapeHtml($step['label']) ?></strong><div class="small text-muted"><?= escapeHtml($step['at']) ?></div></li><?php } ?></ol></div></div><?php } ?>
        <div class="card"><div class="card-header"><h2 class="h5 mb-0">Audit history</h2></div><div class="card-body"><ol class="pl-3 mb-0"><?php while ($event = mysqli_fetch_assoc($events)) { ?><li class="mb-3"><strong><?= escapeHtml(ucwords(str_replace('_', ' ', $event['portal_request_submission_event_action']))) ?></strong><div class="small text-muted"><?= escapeHtml($event['portal_request_submission_event_created_at']) ?> · <?= escapeHtml(portalRequestClientEventActorLabel($event)) ?></div><?php if ($event['portal_request_submission_event_note']) { ?><div class="small"><?= escapeHtml($event['portal_request_submission_event_note']) ?></div><?php } ?></li><?php } ?></ol></div></div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
