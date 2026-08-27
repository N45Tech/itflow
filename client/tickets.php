<?php
/*
 * Client Portal
 * Landing / Home page for the client portal
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";


// Ticket status from GET
if (!isset($_GET['status']) || ($_GET['status']) == 'Open') {
    // Default to showing open
    $status = 'Open';
    $ticket_status_snippet = "ticket_closed_at IS NULL";
} elseif (isset($_GET['status']) && ($_GET['status']) == 'Closed') {
    $status = 'Closed';
    $ticket_status_snippet = "ticket_closed_at IS NOT NULL";
} else {
    $status = '%';
    $ticket_status_snippet = "ticket_status LIKE '%'";
}

$contact_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_contact_id = $session_contact_id AND ticket_client_id = $session_client_id ORDER BY ticket_id DESC");

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_closed_at IS NOT NULL AND ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_closed_at IS NULL AND ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets
$sql_total_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets FROM tickets WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets);
$total_tickets = intval($row['total_tickets']);


?>

<header class="n45-page-header">
    <div>
        <h1>Tickets &amp; approvals</h1>
        <p>Review your support history, follow active work, and respond when N45 needs your input.</p>
    </div>
    <div class="n45-page-header-actions">
        <a href="ticket_add.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i>New ticket</a>
    </div>
</header>

<div class="n45-ticket-filter-layout">
    <div>
        <table class="table">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Subject</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>

            <?php
            if (mysqli_num_rows($contact_tickets) === 0) { ?>
                <tr>
                    <td colspan="3">
                        <div class="n45-portal-empty-state">
                            <span class="n45-portal-empty-icon"><i class="far fa-check-circle" aria-hidden="true"></i></span>
                            <div>
                                <h3>No <?= strtolower(escapeHtml($status)) ?> tickets</h3>
                                <p>There is nothing to review in this view right now.</p>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php }

            while ($row = mysqli_fetch_assoc($contact_tickets)) {
                $ticket_id = intval($row['ticket_id']);
                $ticket_prefix = escapeHtml($row['ticket_prefix']);
                $ticket_number = intval($row['ticket_number']);
                $ticket_subject = escapeHtml($row['ticket_subject']);
                $ticket_status = escapeHtml($row['ticket_status_name']);
                $ticket_status_key = strtolower((string) $row['ticket_status_name']);
                $ticket_status_class = 'neutral';
                if (strpos($ticket_status_key, 'wait') !== false || strpos($ticket_status_key, 'client') !== false) {
                    $ticket_status_class = 'waiting';
                } elseif (strpos($ticket_status_key, 'progress') !== false || strpos($ticket_status_key, 'open') !== false) {
                    $ticket_status_class = 'progress';
                } elseif (strpos($ticket_status_key, 'new') !== false) {
                    $ticket_status_class = 'new';
                } elseif (strpos($ticket_status_key, 'resolved') !== false || strpos($ticket_status_key, 'closed') !== false) {
                    $ticket_status_class = 'resolved';
                }
            ?>

                <tr>
                    <td><a href="ticket.php?id=<?= $ticket_id ?>"><?= "$ticket_prefix$ticket_number" ?></a></td>
                    <td><a href="ticket.php?id=<?= $ticket_id ?>"><?= $ticket_subject ?></a></td>
                    <td><span class="n45-ticket-status n45-ticket-status--<?= $ticket_status_class ?>"><?= $ticket_status ?></span></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <aside class="n45-ticket-filters" aria-label="Ticket filters">
        <a href="?status=Open" class="n45-ticket-filter <?= $status === 'Open' ? 'active' : '' ?>" <?= $status === 'Open' ? 'aria-current="page"' : '' ?>>
            <span>Open</span><strong><?= $total_tickets_open ?></strong>
        </a>
        <a href="?status=Closed" class="n45-ticket-filter <?= $status === 'Closed' ? 'active' : '' ?>" <?= $status === 'Closed' ? 'aria-current="page"' : '' ?>>
            <span>Closed</span><strong><?= $total_tickets_closed ?></strong>
        </a>
        <a href="?status=%" class="n45-ticket-filter <?= $status === '%' ? 'active' : '' ?>" <?= $status === '%' ? 'aria-current="page"' : '' ?>>
            <span>All mine</span><strong><?= $total_tickets ?></strong>
        </a>
        <?php if (contactCan('tickets_all')) { ?>
            <a href="ticket_view_all.php" class="n45-ticket-filter">
                <span>Organization tickets</span><i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        <?php } ?>
    </aside>
</div>

<?php require_once "includes/footer.php";
 ?>
