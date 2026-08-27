<?php
/*
 * Client Portal
 * Overview
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

$portal_can_accounting = contactCan('accounting') && $config_module_enable_accounting == 1;
$portal_can_itdoc = contactCan('itdoc') && $config_module_enable_itdoc;
$portal_can_view_all_tickets = $session_contact_primary == 1 || $session_contact_is_technical_contact;
$ticket_contact_scope = $portal_can_view_all_tickets ? '' : "AND ticket_contact_id = $session_contact_id";

$sql_active_tickets = mysqli_query(
    $mysqli,
    "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name,
        ticket_created_at, ticket_updated_at
    FROM tickets
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_client_id = $session_client_id
        $ticket_contact_scope
        AND ticket_archived_at IS NULL
        AND ticket_closed_at IS NULL
    ORDER BY COALESCE(ticket_updated_at, ticket_created_at) DESC, ticket_id DESC
    LIMIT 5"
);

$active_tickets = [];
$waiting_ticket = null;
while ($row = mysqli_fetch_assoc($sql_active_tickets)) {
    $active_tickets[] = $row;
    if ($waiting_ticket === null && strtolower((string) $row['ticket_status_name']) === 'waiting on client') {
        $waiting_ticket = $row;
    }
}

$sql_active_ticket_count = mysqli_query(
    $mysqli,
    "SELECT COUNT(ticket_id) AS active_ticket_count
    FROM tickets
    WHERE ticket_client_id = $session_client_id
        $ticket_contact_scope
        AND ticket_archived_at IS NULL
        AND ticket_closed_at IS NULL"
);
$active_ticket_count_row = mysqli_fetch_assoc($sql_active_ticket_count);
$active_ticket_count = intval($active_ticket_count_row['active_ticket_count'] ?? 0);

$attention_items = [];

if ($waiting_ticket !== null) {
    $ticket_reference = escapeHtml($waiting_ticket['ticket_prefix']) . intval($waiting_ticket['ticket_number']);
    $attention_items[] = [
        'icon' => 'fa-comment-dots',
        'title' => "We need your reply on $ticket_reference",
        'detail' => escapeHtml($waiting_ticket['ticket_subject']),
        'timing' => 'Waiting on you',
        'urgency' => 'soon',
        'sort_order' => -1000,
        'href' => 'ticket.php?id=' . intval($waiting_ticket['ticket_id']),
        'action' => 'Open ticket',
    ];
}

$days_until = static function ($date) {
    $target_timestamp = strtotime((string) $date);
    $today_timestamp = strtotime('today');
    if ($target_timestamp === false || $today_timestamp === false) {
        return null;
    }
    return intval(floor(($target_timestamp - $today_timestamp) / 86400));
};

$timing_label = static function ($days, $past_label = 'Overdue') {
    if ($days < -1) {
        return $past_label . ' by ' . abs($days) . ' days';
    }
    if ($days === -1) {
        return $past_label . ' by 1 day';
    }
    if ($days === 0) {
        return 'Due today';
    }
    if ($days === 1) {
        return 'Due tomorrow';
    }
    return 'In ' . $days . ' days';
};

$balance = 0.0;
$recurring_monthly = 0.0;
if ($portal_can_accounting) {
    $sql_invoice_amounts = mysqli_query(
        $mysqli,
        "SELECT SUM(invoice_amount) AS invoice_amounts
        FROM invoices
        WHERE invoice_client_id = $session_client_id
            AND invoice_status NOT IN ('Draft', 'Cancelled', 'Non-Billable')"
    );
    $invoice_amounts_row = mysqli_fetch_assoc($sql_invoice_amounts);
    $invoice_amounts = floatval($invoice_amounts_row['invoice_amounts'] ?? 0);

    $sql_amount_paid = mysqli_query(
        $mysqli,
        "SELECT SUM(payment_amount) AS amount_paid
        FROM payments
        INNER JOIN invoices ON payment_invoice_id = invoice_id
        WHERE invoice_client_id = $session_client_id"
    );
    $amount_paid_row = mysqli_fetch_assoc($sql_amount_paid);
    $amount_paid = floatval($amount_paid_row['amount_paid'] ?? 0);
    $balance = max(0, $invoice_amounts - $amount_paid);

    $sql_recurring_totals = mysqli_query(
        $mysqli,
        "SELECT
            SUM(CASE WHEN recurring_invoice_frequency = 'month' THEN recurring_invoice_amount ELSE 0 END) AS monthly_total,
            SUM(CASE WHEN recurring_invoice_frequency = 'year' THEN recurring_invoice_amount ELSE 0 END) AS yearly_total
        FROM recurring_invoices
        WHERE recurring_invoice_status = 1
            AND recurring_invoice_client_id = $session_client_id"
    );
    $recurring_totals_row = mysqli_fetch_assoc($sql_recurring_totals);
    $recurring_monthly = floatval($recurring_totals_row['monthly_total'] ?? 0)
        + (floatval($recurring_totals_row['yearly_total'] ?? 0) / 12);

    $sql_attention_invoices = mysqli_query(
        $mysqli,
        "SELECT invoice_due, invoice_id, invoice_number, invoice_prefix
        FROM invoices
        WHERE invoice_client_id = $session_client_id
            AND invoice_status IN ('Sent', 'Viewed', 'Partial')
            AND invoice_due IS NOT NULL
            AND invoice_due <= CURRENT_DATE + INTERVAL 45 DAY
        ORDER BY invoice_due ASC
        LIMIT 2"
    );

    while ($row = mysqli_fetch_assoc($sql_attention_invoices)) {
        $days = $days_until($row['invoice_due']);
        if ($days === null) {
            continue;
        }
        $invoice_reference = escapeHtml($row['invoice_prefix']) . intval($row['invoice_number']);
        $attention_items[] = [
            'icon' => 'fa-file-invoice-dollar',
            'title' => "Invoice $invoice_reference is due " . date('F j', strtotime($row['invoice_due'])),
            'detail' => 'Review the invoice and available payment options.',
            'timing' => $timing_label($days),
            'urgency' => $days <= 0 ? 'overdue' : ($days <= 14 ? 'soon' : 'normal'),
            'sort_order' => $days,
            'href' => 'invoices.php',
            'action' => 'Review invoice',
        ];
    }
}

$technology_counts = [
    'assets' => 0,
    'documents' => 0,
    'domains' => 0,
    'certificates' => 0,
];

if ($portal_can_itdoc) {
    $technology_count_queries = [
        'assets' => "SELECT COUNT(asset_id) AS item_count FROM assets WHERE asset_client_id = $session_client_id AND asset_archived_at IS NULL",
        'documents' => "SELECT COUNT(document_id) AS item_count FROM documents WHERE document_client_id = $session_client_id AND document_client_visible = 1 AND document_archived_at IS NULL",
        'domains' => "SELECT COUNT(domain_id) AS item_count FROM domains WHERE domain_client_id = $session_client_id AND domain_archived_at IS NULL",
        'certificates' => "SELECT COUNT(certificate_id) AS item_count FROM certificates WHERE certificate_client_id = $session_client_id AND certificate_archived_at IS NULL",
    ];

    foreach ($technology_count_queries as $key => $query) {
        $count_result = mysqli_query($mysqli, $query);
        $count_row = mysqli_fetch_assoc($count_result);
        $technology_counts[$key] = intval($count_row['item_count'] ?? 0);
    }

    $sql_attention_domains = mysqli_query(
        $mysqli,
        "SELECT domain_expire, domain_name
        FROM domains
        WHERE domain_client_id = $session_client_id
            AND domain_expire IS NOT NULL
            AND domain_archived_at IS NULL
            AND domain_expire <= CURRENT_DATE + INTERVAL 45 DAY
        ORDER BY domain_expire ASC
        LIMIT 2"
    );
    while ($row = mysqli_fetch_assoc($sql_attention_domains)) {
        $days = $days_until($row['domain_expire']);
        if ($days === null) {
            continue;
        }
        $domain_name = escapeHtml($row['domain_name']);
        $attention_items[] = [
            'icon' => 'fa-globe',
            'title' => "$domain_name renews " . date('F j', strtotime($row['domain_expire'])),
            'detail' => 'Confirm the registration details before renewal.',
            'timing' => $timing_label($days, 'Expired'),
            'urgency' => $days <= 0 ? 'overdue' : ($days <= 14 ? 'soon' : 'normal'),
            'sort_order' => $days,
            'href' => 'domains.php',
            'action' => 'Review domain',
        ];
    }

    $sql_attention_certificates = mysqli_query(
        $mysqli,
        "SELECT certificate_expire, certificate_name
        FROM certificates
        WHERE certificate_client_id = $session_client_id
            AND certificate_expire IS NOT NULL
            AND certificate_archived_at IS NULL
            AND certificate_expire <= CURRENT_DATE + INTERVAL 45 DAY
        ORDER BY certificate_expire ASC
        LIMIT 2"
    );
    while ($row = mysqli_fetch_assoc($sql_attention_certificates)) {
        $days = $days_until($row['certificate_expire']);
        if ($days === null) {
            continue;
        }
        $certificate_name = escapeHtml($row['certificate_name']);
        $attention_items[] = [
            'icon' => 'fa-shield-alt',
            'title' => "$certificate_name expires " . date('F j', strtotime($row['certificate_expire'])),
            'detail' => 'Review the certificate record and renewal date.',
            'timing' => $timing_label($days, 'Expired'),
            'urgency' => $days <= 0 ? 'overdue' : ($days <= 14 ? 'soon' : 'normal'),
            'sort_order' => $days,
            'href' => 'certificates.php',
            'action' => 'Review certificate',
        ];
    }
}

usort($attention_items, static function ($first, $second) {
    return $first['sort_order'] <=> $second['sort_order'];
});
$attention_items = array_slice($attention_items, 0, 4);

$hour = intval(date('G'));
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$contact_name_parts = preg_split('/\s+/', trim(stripslashes($session_contact_name)));
$contact_first_name = escapeHtml($contact_name_parts[0] ?? $session_contact_name);
$show_summary_rail = $portal_can_accounting || $portal_can_itdoc;

$status_class = static function ($status) {
    switch (strtolower((string) $status)) {
        case 'waiting on client':
            return 'waiting';
        case 'resolved':
            return 'resolved';
        case 'in progress':
        case 'open':
            return 'progress';
        case 'new':
            return 'new';
        default:
            return 'neutral';
    }
};
?>

<section class="n45-portal-home-header" aria-labelledby="portal-home-title">
    <div>
        <span class="n45-portal-eyebrow">Client portal</span>
        <h1 id="portal-home-title"><?= $greeting ?>, <?= $contact_first_name ?>.</h1>
        <p>Here’s what needs your attention across your N45 services.</p>
    </div>
    <a href="ticket_add.php" class="btn n45-portal-primary-action">
        <i class="fas fa-headset" aria-hidden="true"></i>
        Request support
    </a>
</section>

<div class="n45-portal-dashboard-grid <?= $show_summary_rail ? '' : 'n45-portal-dashboard-grid--single' ?>">
    <div class="n45-portal-dashboard-main">
        <section class="n45-portal-panel n45-attention-panel" aria-labelledby="attention-title">
            <div class="n45-portal-section-heading">
                <div>
                    <span class="n45-portal-eyebrow">Next actions</span>
                    <h2 id="attention-title">Needs your attention</h2>
                </div>
                <?php if (count($attention_items) > 0) { ?>
                    <span class="n45-portal-count" aria-label="<?= count($attention_items) ?> items"><?= count($attention_items) ?></span>
                <?php } ?>
            </div>

            <?php if (count($attention_items) === 0) { ?>
                <div class="n45-portal-empty-state">
                    <span class="n45-portal-empty-icon"><i class="fas fa-check" aria-hidden="true"></i></span>
                    <div>
                        <h3>You’re all caught up.</h3>
                        <p>There are no replies, upcoming renewals, or billing items requiring your attention.</p>
                    </div>
                </div>
            <?php } else { ?>
                <div class="n45-attention-list">
                    <?php foreach ($attention_items as $item) { ?>
                        <article class="n45-attention-item n45-attention-item--<?= escapeHtml($item['urgency']) ?>">
                            <span class="n45-attention-icon"><i class="fas <?= escapeHtml($item['icon']) ?>" aria-hidden="true"></i></span>
                            <div class="n45-attention-copy">
                                <h3><?= $item['title'] ?></h3>
                                <p><?= $item['detail'] ?></p>
                            </div>
                            <span class="n45-attention-timing"><?= escapeHtml($item['timing']) ?></span>
                            <a href="<?= escapeUrl($item['href']) ?>" class="n45-portal-text-action">
                                <?= escapeHtml($item['action']) ?>
                                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            </a>
                        </article>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

        <section class="n45-portal-panel n45-support-panel" aria-labelledby="support-title">
            <div class="n45-portal-section-heading">
                <div>
                    <span class="n45-portal-eyebrow">Current work</span>
                    <h2 id="support-title">Active support</h2>
                </div>
                <a href="tickets.php" class="n45-portal-text-action">
                    View all tickets
                    <?php if ($active_ticket_count > 0) { ?><span class="sr-only">, <?= $active_ticket_count ?> active</span><?php } ?>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>

            <?php if (count($active_tickets) === 0) { ?>
                <div class="n45-portal-empty-state">
                    <span class="n45-portal-empty-icon"><i class="far fa-comment" aria-hidden="true"></i></span>
                    <div>
                        <h3>No active support requests.</h3>
                        <p>When you need us, start a request and follow every update here.</p>
                        <a href="ticket_add.php" class="n45-portal-inline-link">Request support</a>
                    </div>
                </div>
            <?php } else { ?>
                <div class="n45-ticket-list" role="list">
                    <div class="n45-ticket-list-header" aria-hidden="true">
                        <span>Ticket</span>
                        <span>Title</span>
                        <span>Status</span>
                        <span>Updated</span>
                    </div>
                    <?php foreach ($active_tickets as $ticket) {
                        $ticket_id = intval($ticket['ticket_id']);
                        $ticket_reference = escapeHtml($ticket['ticket_prefix']) . intval($ticket['ticket_number']);
                        $ticket_status = (string) $ticket['ticket_status_name'];
                        $ticket_status_display = strtolower($ticket_status) === 'waiting on client' ? 'Waiting on you' : $ticket_status;
                        $ticket_updated_at = $ticket['ticket_updated_at'] ?: $ticket['ticket_created_at'];
                        ?>
                        <a class="n45-ticket-row" href="ticket.php?id=<?= $ticket_id ?>" role="listitem" aria-label="<?= $ticket_reference ?>, <?= escapeHtml($ticket['ticket_subject']) ?>, <?= escapeHtml($ticket_status_display) ?>">
                            <strong class="n45-ticket-reference"><?= $ticket_reference ?></strong>
                            <span class="n45-ticket-title"><?= escapeHtml($ticket['ticket_subject']) ?></span>
                            <span><span class="n45-ticket-status n45-ticket-status--<?= $status_class($ticket_status) ?>"><?= escapeHtml($ticket_status_display) ?></span></span>
                            <span class="n45-ticket-updated"><?= escapeHtml(timeAgo($ticket_updated_at)) ?></span>
                        </a>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
    </div>

    <?php if ($show_summary_rail) { ?>
        <aside class="n45-portal-summary-rail" aria-label="Account summaries">
            <?php if ($portal_can_itdoc) { ?>
                <section class="n45-portal-panel n45-summary-panel" aria-labelledby="technology-title">
                    <div class="n45-portal-section-heading">
                        <div>
                            <span class="n45-portal-eyebrow">Inventory</span>
                            <h2 id="technology-title">Your technology</h2>
                        </div>
                    </div>
                    <dl class="n45-technology-list">
                        <div>
                            <dt><span class="n45-summary-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>Managed devices</dt>
                            <dd><?= $technology_counts['assets'] ?></dd>
                        </div>
                        <div>
                            <dt><span class="n45-summary-icon"><i class="far fa-file-alt" aria-hidden="true"></i></span>Documents</dt>
                            <dd><?= $technology_counts['documents'] ?></dd>
                        </div>
                        <div>
                            <dt><span class="n45-summary-icon"><i class="fas fa-globe" aria-hidden="true"></i></span>Domains</dt>
                            <dd><?= $technology_counts['domains'] ?></dd>
                        </div>
                        <div>
                            <dt><span class="n45-summary-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>Certificates</dt>
                            <dd><?= $technology_counts['certificates'] ?></dd>
                        </div>
                    </dl>
                    <a href="assets.php" class="n45-portal-panel-action">
                        View technology
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </section>
            <?php } ?>

            <?php if ($portal_can_accounting) { ?>
                <section class="n45-portal-panel n45-summary-panel" aria-labelledby="billing-title">
                    <div class="n45-portal-section-heading">
                        <div>
                            <span class="n45-portal-eyebrow">Account</span>
                            <h2 id="billing-title">Billing</h2>
                        </div>
                    </div>
                    <dl class="n45-billing-list">
                        <div>
                            <dt>Balance due</dt>
                            <dd><?= numfmt_format_currency($currency_format, $balance, $session_company_currency) ?></dd>
                        </div>
                        <div>
                            <dt>Monthly services</dt>
                            <dd><?= numfmt_format_currency($currency_format, $recurring_monthly, $session_company_currency) ?></dd>
                        </div>
                    </dl>
                    <a href="invoices.php" class="n45-portal-panel-action">
                        Manage billing
                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </a>
                </section>
            <?php } ?>
        </aside>
    <?php } ?>
</div>

<section class="n45-service-desk-band" aria-label="N45 service desk">
    <span class="n45-service-desk-icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
    <div>
        <strong>N45 service desk</strong>
        <p>Submit a request any time. We’ll keep every update organized here and in your inbox.</p>
    </div>
    <a href="ticket_add.php" class="n45-portal-text-action">Get help <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
</section>

<?php require_once "includes/footer.php"; ?>
