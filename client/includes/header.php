<?php
/*
 * Client Portal
 * HTML Header
 */

header("X-Frame-Options: DENY"); // Legacy

$portal_current_page = basename($_SERVER['PHP_SELF']);
$portal_overview_active = $portal_current_page === 'index.php';
$portal_get_help_active = $portal_current_page === 'ticket_add.php';
$portal_ticket_active = in_array($portal_current_page, ['tickets.php', 'ticket.php', 'ticket_view_all.php'], true);
$portal_billing_active = in_array($portal_current_page, ['invoices.php', 'unpaid_invoices.php', 'recurring_invoices.php', 'quotes.php', 'saved_payment_methods.php', 'autopay.php'], true);
$portal_technology_active = in_array($portal_current_page, ['assets.php', 'documents.php', 'document.php', 'domains.php', 'certificates.php', 'contacts.php'], true);
$portal_account_active = $portal_current_page === 'profile.php';
$portal_can_accounting = contactCan('accounting') && $config_module_enable_accounting == 1;
$portal_can_itdoc = contactCan('itdoc') && $config_module_enable_itdoc;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= escapeHtml($session_company_name) ?> | Client Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">

    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" href="/uploads/favicon.ico">
    <?php } ?>

    <link rel="stylesheet" href="/libs/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="/libs/adminlte/css/adminlte.min.css">
    <link rel="stylesheet" href="/css/itflow_custom.css">
</head>

<body class="n45-client-portal">
<a class="n45-skip-link" href="#main-content">Skip to main content</a>

<div class="n45-portal-shell">
    <aside class="n45-portal-sidebar" id="clientPortalSidebar" aria-label="Client portal navigation">
        <div class="n45-portal-sidebar-brand">
            <a href="/client/index.php" aria-label="N45 client portal home">
                <img src="/assets/branding/n45-lockup-light.svg" alt="N45 Technology Solutions">
            </a>
            <span>Client portal</span>
        </div>

        <nav class="n45-portal-sidebar-nav" aria-label="Primary">
            <a class="n45-portal-nav-item <?= $portal_overview_active ? 'active' : '' ?>" href="/client/index.php" <?= $portal_overview_active ? 'aria-current="page"' : '' ?>>
                <i class="fas fa-fw fa-home" aria-hidden="true"></i>
                <span>Overview</span>
            </a>
            <a class="n45-portal-nav-item <?= $portal_get_help_active ? 'active' : '' ?>" href="/client/ticket_add.php" <?= $portal_get_help_active ? 'aria-current="page"' : '' ?>>
                <i class="far fa-fw fa-comment" aria-hidden="true"></i>
                <span>Get help</span>
            </a>
            <a class="n45-portal-nav-item <?= $portal_ticket_active ? 'active' : '' ?>" href="/client/tickets.php" <?= $portal_ticket_active ? 'aria-current="page"' : '' ?>>
                <i class="fas fa-fw fa-ticket-alt" aria-hidden="true"></i>
                <span>Tickets &amp; approvals</span>
            </a>

            <?php if ($portal_can_accounting) { ?>
                <details class="n45-portal-nav-group" <?= $portal_billing_active ? 'open' : '' ?>>
                    <summary class="n45-portal-nav-item <?= $portal_billing_active ? 'active' : '' ?>">
                        <i class="far fa-fw fa-credit-card" aria-hidden="true"></i>
                        <span>Billing</span>
                        <i class="fas fa-chevron-down n45-portal-nav-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="n45-portal-subnav">
                        <a href="/client/invoices.php" <?= $portal_current_page === 'invoices.php' ? 'aria-current="page"' : '' ?>>Invoices</a>
                        <a href="/client/recurring_invoices.php" <?= $portal_current_page === 'recurring_invoices.php' ? 'aria-current="page"' : '' ?>>Recurring invoices</a>
                        <a href="/client/quotes.php" <?= $portal_current_page === 'quotes.php' ? 'aria-current="page"' : '' ?>>Quotes</a>
                        <a href="/client/saved_payment_methods.php" <?= $portal_current_page === 'saved_payment_methods.php' ? 'aria-current="page"' : '' ?>>Saved payment methods</a>
                    </div>
                </details>
            <?php } ?>

            <?php if ($portal_can_itdoc) { ?>
                <details class="n45-portal-nav-group" <?= $portal_technology_active ? 'open' : '' ?>>
                    <summary class="n45-portal-nav-item <?= $portal_technology_active ? 'active' : '' ?>">
                        <i class="fas fa-fw fa-desktop" aria-hidden="true"></i>
                        <span>Technology</span>
                        <i class="fas fa-chevron-down n45-portal-nav-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="n45-portal-subnav">
                        <a href="/client/assets.php" <?= $portal_current_page === 'assets.php' ? 'aria-current="page"' : '' ?>>Assets</a>
                        <a href="/client/documents.php" <?= in_array($portal_current_page, ['documents.php', 'document.php'], true) ? 'aria-current="page"' : '' ?>>Documents</a>
                        <a href="/client/domains.php" <?= $portal_current_page === 'domains.php' ? 'aria-current="page"' : '' ?>>Domains</a>
                        <a href="/client/certificates.php" <?= $portal_current_page === 'certificates.php' ? 'aria-current="page"' : '' ?>>Certificates</a>
                        <a href="/client/contacts.php" <?= $portal_current_page === 'contacts.php' ? 'aria-current="page"' : '' ?>>Contacts</a>
                    </div>
                </details>
            <?php } ?>

            <a class="n45-portal-nav-item <?= $portal_account_active ? 'active' : '' ?>" href="/client/profile.php" <?= $portal_account_active ? 'aria-current="page"' : '' ?>>
                <i class="far fa-fw fa-user" aria-hidden="true"></i>
                <span>Account</span>
            </a>

            <?php
            $sql_custom_links = mysqli_query(
                $mysqli,
                "SELECT custom_link_name, custom_link_new_tab, custom_link_uri FROM custom_links WHERE custom_link_location = 3 AND custom_link_archived_at IS NULL ORDER BY custom_link_order ASC, custom_link_name ASC"
            );
            if (mysqli_num_rows($sql_custom_links) > 0) { ?>
                <div class="n45-portal-nav-label">More</div>
                <?php while ($row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = escapeHtml($row['custom_link_name']);
                    $custom_link_uri = escapeHtml($row['custom_link_uri']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    ?>
                    <a class="n45-portal-nav-item" href="<?= $custom_link_uri ?>" <?= $custom_link_new_tab === 1 ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <i class="fas fa-fw fa-external-link-alt" aria-hidden="true"></i>
                        <span><?= $custom_link_name ?></span>
                    </a>
                <?php } ?>
            <?php } ?>
        </nav>

        <div class="n45-portal-sidebar-footer">
            <a class="n45-portal-help-link" href="/client/ticket_add.php">
                <i class="far fa-fw fa-question-circle" aria-hidden="true"></i>
                <span>Help &amp; contact</span>
            </a>
            <div class="n45-portal-user">
                <?php if (!empty($session_contact_photo)) { ?>
                    <img src="/uploads/clients/<?= $session_client_id ?>/<?= escapeUrl($session_contact_photo) ?>" alt="">
                <?php } else { ?>
                    <span class="n45-portal-user-avatar" aria-hidden="true"><?= escapeHtml($session_contact_initials) ?></span>
                <?php } ?>
                <div class="n45-portal-user-copy">
                    <strong><?= stripslashes(escapeHtml($session_contact_name)) ?></strong>
                    <span><?= escapeHtml($session_client_name) ?></span>
                </div>
                <a class="n45-portal-signout" href="/client/post.php?logout" aria-label="Sign out" title="Sign out">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </aside>

    <div class="n45-portal-stage">
        <header class="n45-portal-mobile-header">
            <a href="/client/index.php" aria-label="N45 client portal home">
                <img src="/assets/branding/n45-mark.svg" alt="">
                <span>N45 Client Portal</span>
            </a>
            <button class="n45-portal-menu-button" type="button" aria-controls="clientPortalSidebar" aria-expanded="false">
                <span class="sr-only">Open navigation</span>
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
        </header>

        <main class="n45-portal-main" id="main-content" tabindex="-1">
            <?php
            if (!empty($_SESSION['alert_message'])) {
                if (!isset($_SESSION['alert_type'])) {
                    $_SESSION['alert_type'] = 'info';
                }
                ?>
                <div class="alert alert-<?= escapeHtml($_SESSION['alert_type']) ?>" id="alert" role="status">
                    <?= escapeHtml($_SESSION['alert_message']) ?>
                    <button class="close" data-dismiss="alert" aria-label="Dismiss notification">&times;</button>
                </div>
                <?php
                unset($_SESSION['alert_type']);
                unset($_SESSION['alert_message']);
            }
            ?>
