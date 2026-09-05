<?php

// Approval URLs are bearer credentials. Keep them out of caches and referrers.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

require_once 'includes/inc_all_guest.php';
require_once '../libs/htmlpurifier/HTMLPurifier.standalone.php';

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null);
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

$approval_id = intval($_GET['ticket_approval_id'] ?? 0);
$url_key = isset($_GET['url_key']) && is_string($_GET['url_key']) ? $_GET['url_key'] : '';
if ($approval_id < 1 || $url_key === '' || strlen($url_key) > 200) {
    echo '<br><h2>This approval link is invalid or no longer available.</h2>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_phone,
    company_phone_country_code, company_website FROM companies, settings
    WHERE companies.company_id = settings.company_id AND companies.company_id = 1"));
$company_phone_country_code = escapeHtml($company['company_phone_country_code']);
$company_phone = escapeHtml(formatPhoneNumber($company['company_phone'], $company_phone_country_code));
$company_website = escapeHtml($company['company_website']);

$approval = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_approvals.*,
    ticket_id, ticket_details, ticket_number, ticket_prefix, ticket_priority,
    ticket_status_name, ticket_subject, ticket_resolved_at, ticket_closed_at
    FROM ticket_approvals
    INNER JOIN tickets ON ticket_id = ticket_approval_ticket_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_approval_id = $approval_id
    AND ticket_approval_scope = 'client' LIMIT 1"));

$receipt = $_SESSION['guest_ticket_approval_receipt'] ?? null;
$receipt_valid = is_array($receipt)
    && intval($receipt['approval_id'] ?? 0) === $approval_id
    && is_string($receipt['decision'] ?? null)
    && isset($approval['ticket_approval_status'])
    && hash_equals((string) $approval['ticket_approval_status'], $receipt['decision'])
    && intval($receipt['expires_at'] ?? 0) >= time();
$token_valid = isset($approval['ticket_approval_status'])
    && $approval['ticket_approval_status'] === 'pending'
    && runbookApprovalTokenMatches($approval['ticket_approval_url_key'], $url_key);

if (!$approval || (!$token_valid && !$receipt_valid)) {
    echo '<br><h2>This approval link is invalid or no longer available.</h2>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}
if ($receipt_valid) {
    unset($_SESSION['guest_ticket_approval_receipt']);
}

$approval_expired = !empty($approval['ticket_approval_url_expires_at'])
    && strtotime($approval['ticket_approval_url_expires_at']) <= time();
$approval_actionable = $approval['ticket_approval_status'] === 'pending'
    && !$approval_expired
    && empty($approval['ticket_resolved_at'])
    && empty($approval['ticket_closed_at']);
$approval_status = escapeHtml($approval['ticket_approval_status']);
$ticket_prefix = escapeHtml($approval['ticket_prefix']);
$ticket_number = intval($approval['ticket_number']);
$ticket_status = escapeHtml($approval['ticket_status_name']);
$ticket_priority = escapeHtml($approval['ticket_priority']);
$ticket_subject = escapeHtml($approval['ticket_subject']);
$ticket_details = $purifier->purify($approval['ticket_details']);

if ($ticket_priority === 'Urgent') {
    $ticket_priority_color = 'dark';
} elseif ($ticket_priority === 'High') {
    $ticket_priority_color = 'danger';
} elseif ($ticket_priority === 'Medium') {
    $ticket_priority_color = 'warning';
} else {
    $ticket_priority_color = 'info';
}

?>

<div class="card mt-3 mb-3">
    <div class="card-header bg-dark text-center py-3">
        <h4 class="mb-0"><i class="fas fa-fw fa-clipboard-check me-2"></i>Ticket approval</h4>
    </div>
    <div class="card-body text-center py-4">
        <?php if ($approval_actionable) { ?>
            <p class="text-muted mb-2">You have been asked to approve this request</p>
        <?php } ?>
        <h4 class="mb-2"><?= $ticket_subject ?></h4>
        <p class="text-muted mb-4">Ticket <strong><?= $ticket_prefix . $ticket_number ?></strong></p>

        <?php if ($approval_actionable) { ?>
            <form action="guest_post.php" method="post" autocomplete="off">
                <input type="hidden" name="decide_ticket_approval" value="1">
                <input type="hidden" name="ticket_approval_id" value="<?= $approval_id ?>">
                <input type="hidden" name="approval_url_key" value="<?= escapeHtml($url_key) ?>">
                <div class="d-grid gap-2 d-sm-block">
                    <button type="submit" name="decision" value="approved" class="btn btn-success btn-lg">
                        <i class="fas fa-fw fa-check me-2"></i>Approve ticket
                    </button>
                    <button type="submit" name="decision" value="declined" class="btn btn-danger btn-lg">
                        <i class="fas fa-fw fa-times me-2"></i>Decline ticket
                    </button>
                </div>
            </form>
            <small class="text-muted d-block mt-3">Not expecting this? Contact the service team using the details below.</small>
        <?php } elseif ($approval_status === 'approved') { ?>
            <div class="alert alert-success d-inline-block mb-0">
                <i class="fas fa-fw fa-check-circle me-2"></i><strong>Approved</strong> — nothing further is needed.
            </div>
        <?php } elseif ($approval_status === 'declined') { ?>
            <div class="alert alert-danger d-inline-block mb-0">
                <i class="fas fa-fw fa-times-circle me-2"></i><strong>Declined</strong> — this ticket was not approved.
            </div>
        <?php } else { ?>
            <div class="alert alert-warning d-inline-block mb-0">
                This request is no longer actionable. It may have expired or been sent to someone else.
            </div>
        <?php } ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mt-2">Request details</h5>
        <div class="card-tools">
            <span class="p-2 badge rounded-pill text-bg-secondary"><?= $ticket_status ?></span>
            <span class="p-2 badge rounded-pill text-bg-<?= $ticket_priority_color ?>"><?= $ticket_priority ?></span>
        </div>
    </div>
    <div class="card-body prettyContent"><?= $ticket_details ?></div>
</div>

<p class="text-center text-muted my-3">
    <i class="fas fa-phone fa-fw me-2"></i><?= $company_phone ?>
    <span class="mx-2">|</span>
    <i class="fas fa-globe fa-fw me-2"></i><?= $company_website ?>
</p>

<script src="/js/pretty_content.js"></script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
