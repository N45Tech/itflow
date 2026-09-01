<?php

// Approval URLs are bearer credentials. Keep them out of intermediary caches and
// referrer headers while the guest reviews the request.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

require_once "includes/inc_all_guest.php";

//Initialize the HTML Purifier to prevent XSS
require_once "../libs/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

if (!isset($_GET['task_approval_id'], $_GET['url_key']) || !is_string($_GET['url_key'])) {
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

// Company info
$company_sql_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT
        company_phone,
        company_phone_country_code,
        company_website
    FROM
        companies,
        settings
    WHERE
        companies.company_id = settings.company_id
        AND companies.company_id = 1"
));

$company_phone_country_code = escapeHtml($company_sql_row['company_phone_country_code']);
$company_phone = escapeHtml(formatPhoneNumber($company_sql_row['company_phone'], $company_phone_country_code));
$company_website = escapeHtml($company_sql_row['company_website']);

$approval_id = intval($_GET['task_approval_id']);
$url_key = $_GET['url_key'];

if ($approval_id < 1 || $url_key === '' || strlen($url_key) > 200) {
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$task_row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT approval_scope, approval_status, approval_type, approval_url_key,
        approval_url_expires_at, task_id, task_state FROM task_approvals
        INNER JOIN tasks ON approval_task_id = task_id
    WHERE approval_id = $approval_id AND approval_scope = 'client'
    LIMIT 1"
));

if (!$task_row || ($task_row['approval_status'] === 'pending'
        && !runbookApprovalTokenMatches($task_row['approval_url_key'], $url_key))) {
    // Invalid approval ID/key pair or an internal-only approval.
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$task_id = intval($task_row['task_id']);
$approval_expired = !empty($task_row['approval_url_expires_at'])
    && strtotime($task_row['approval_url_expires_at']) <= time();
$approval_actionable = $task_row['approval_status'] === 'pending'
    && !$approval_expired
    && !in_array($task_row['task_state'], ['Completed', 'Skipped'], true);

if (!$approval_actionable) {
    ?>
    <div class="card mt-3">
        <div class="card-header bg-dark text-center"><h4 class="mt-1">Task Approval</h4></div>
        <div class="card-body">
            <p class="mb-0">This approval request is no longer actionable. It may have been decided, expired, rerouted, or its task may be complete.</p>
        </div>
    </div>
    <div class="card-footer mt-3">
        <?= "<i class='fas fa-phone fa-fw mr-2'></i>$company_phone | <i class='fas fa-globe fa-fw mr-2 ml-2'></i>$company_website" ?>
    </div>
    <?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

$ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_name, ticket_details,
    ticket_number, ticket_prefix, ticket_priority, ticket_status_name, ticket_subject
    FROM tasks
    INNER JOIN tickets ON task_ticket_id = ticket_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE task_id = $task_id LIMIT 1"));
if (!$ticket_row) {
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}
$task_row = array_merge($task_row, $ticket_row);
$task_name = escapeHtml($task_row['task_name']);
$approval_scope = escapeHtml($task_row['approval_scope']);
$approval_type = escapeHtml($task_row['approval_type']);
$approval_status = escapeHtml($task_row['approval_status']);
$ticket_prefix = escapeHtml($task_row['ticket_prefix']);
$ticket_number = intval($task_row['ticket_number']);
$ticket_status = escapeHtml($task_row['ticket_status_name']);
$ticket_priority = escapeHtml($task_row['ticket_priority']);
$ticket_subject = escapeHtml($task_row['ticket_subject']);
$ticket_details = $purifier->purify($task_row['ticket_details']);

?>

    <div class="card mt-3">
        <div class="card-header bg-dark text-center">
            <h4 class="mt-1">
                Task Approval for Ticket <?= $ticket_prefix, $ticket_number ?>
            </h4>
        </div>

        <div class="card-body prettyContent">
            <h5><strong>Subject:</strong> <?= $ticket_subject ?></h5>
            <p>
                <strong>State:</strong> <?= $ticket_status ?>
                <br>
                <strong>Priority:</strong> <?= $ticket_priority ?>
                <br>
            </p>
            <?= $ticket_details ?>
            <hr>
            <h5>Task Approval</h5>
            <p>
                <strong>Task Name: </strong><?= ucfirst($task_name); ?>
                <br>
                <strong>Scope/Type:</strong> <?= ucfirst($approval_scope) . " - " . ucfirst($approval_type)?>
                <br>
                <strong>Status:</strong> <?= ucfirst($approval_status)?>
                <br>
                <?php
                if ($approval_actionable) { ?>
                    <strong>Action:</strong>
                    <span class="text-muted">Choose a decision below. Your decision is final.</span>
                <?php } ?>
            </p>

            <?php if ($approval_actionable) { ?>
                <form action="guest_post.php" method="post" autocomplete="off">
                    <input type="hidden" name="decide_ticket_task_approval" value="1">
                    <input type="hidden" name="task_approval_id" value="<?= $approval_id ?>">
                    <input type="hidden" name="approval_url_key" value="<?= escapeHtml($url_key) ?>">
                    <button type="submit" name="decision" value="approved" class="btn btn-success mr-2">
                        <i class="fas fa-check mr-1"></i>Approve Task
                    </button>
                    <button type="submit" name="decision" value="declined" class="btn btn-danger">
                        <i class="fas fa-times mr-1"></i>Decline Task
                    </button>
                </form>
            <?php } else { ?>
                <p class="text-muted mb-0">This approval request has already been decided or its task is no longer actionable.</p>
            <?php } ?>

        </div>
    </div>

    <hr>

    <div class="card-footer">
        <?= "<i class='fas fa-phone fa-fw mr-2'></i>$company_phone | <i class='fas fa-globe fa-fw mr-2 ml-2'></i>$company_website" ?>
    </div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
