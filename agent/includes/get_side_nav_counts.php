<?php
// Get Main Side Bar Badge Counts

// Active Clients Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('client_id') AS num FROM clients WHERE client_archived_at IS NULL " . clientScopeSql('clients.client_id') . ""));
$num_active_clients = $row['num'];

// Active Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('ticket_id') AS num FROM tickets WHERE ticket_archived_at IS NULL AND ticket_closed_at IS NULL AND ticket_status != 4 " . clientScopeSql('ticket_client_id') . ""));
$num_active_tickets = $row['num'];

// Operational exceptions: open automation incidents plus Level.io mappings that need review.
$operations_incident_scope = clientScopeSql('automation_incident_client_id');
$operations_level_scope = clientScopeSql('assets.asset_client_id');
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    (SELECT COUNT(*) FROM automation_incidents
        WHERE automation_incident_status = 'Open' $operations_incident_scope)
    +
    (SELECT COUNT(*) FROM level_asset_links
        INNER JOIN assets ON level_asset_id = asset_id
        WHERE level_device_deleted_at IS NULL
        AND level_device_sync_status = 'Conflict' $operations_level_scope) AS num"));
$num_operations_attention = intval($row['num'] ?? 0);

// Recurring Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_ticket_id') AS num FROM recurring_tickets WHERE 1 = 1 " . clientScopeSql('recurring_ticket_client_id') . ""));
$num_recurring_tickets = $row['num'];

// Active Project Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('project_id') AS num FROM projects WHERE project_archived_at IS NULL AND project_completed_at IS NULL " . clientScopeSql('project_client_id') . ""));
$num_active_projects = $row['num'];

// Open Invoices Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('invoice_id') AS num FROM invoices WHERE (invoice_status = 'Sent' OR invoice_status = 'Viewed' OR invoice_status = 'Partial' OR invoice_status = 'Draft') AND invoice_archived_at IS NULL " . clientScopeSql('invoice_client_id') . ""));
$num_open_invoices = $row['num'];

// Recurring Invoice Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_invoice_id') AS num FROM recurring_invoices WHERE recurring_invoice_archived_at IS NULL " . clientScopeSql('recurring_invoice_client_id') . ""));
$num_recurring_invoices = $row['num'];

// Open Quotes Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('quote_id') AS num FROM quotes WHERE (quote_status = 'Sent' OR quote_status = 'Viewed') AND quote_archived_at IS NULL " . clientScopeSql('quote_client_id') . ""));
$num_open_quotes = $row['num'];

// Recurring Expenses Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_expense_id') AS num FROM recurring_expenses WHERE recurring_expense_archived_at IS NULL"));
$num_recurring_expenses = $row['num'];
