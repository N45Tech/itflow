<?php
// Get Main Side Bar Badge Counts

// Active Clients Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('client_id') AS num FROM clients WHERE client_archived_at IS NULL " . clientScopeSql('clients.client_id') . ""));
$num_active_clients = $row['num'];

// Active Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('ticket_id') AS num FROM tickets WHERE ticket_archived_at IS NULL AND ticket_closed_at IS NULL AND ticket_status != 4 " . clientScopeSql('ticket_client_id') . ""));
$num_active_tickets = $row['num'];

// Use the same fail-closed live projection as the documentation queue and gate.
// Invalid requirement, document, evidence, or exception pointers cannot inherit
// a reassuring stored status while waiting for reconciliation.
$documentation_scope = clientScopeSql('o.documentation_obligation_client_id');
$documentation_validity = documentationObligationValiditySql('o');
$documentation_rows = documentationDbQuery("SELECT o.*, {$documentation_validity['select']}
    FROM client_documentation_obligations o
    {$documentation_validity['joins']}
    WHERE 1 = 1 $documentation_scope", 'Could not project the documentation attention count');
$num_documentation_attention = 0;
while ($documentation_row = mysqli_fetch_assoc($documentation_rows)) {
    $documentation_row = documentationApplyCurrentRequirementMetadata($documentation_row);
    $projection = documentationProjectObligationValidity($documentation_row);
    if (in_array($projection['effective_status'], ['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'], true)) {
        $num_documentation_attention++;
    }
}
$documentation_clients = [];
$documentation_client_scope = clientScopeSql('client_id');
$documentation_client_rows = documentationDbQuery("SELECT client_id, client_name, client_type, client_archived_at
    FROM clients WHERE client_archived_at IS NULL $documentation_client_scope
    ORDER BY client_id", 'Could not load clients for pending documentation attention');
while ($documentation_client = mysqli_fetch_assoc($documentation_client_rows)) {
    $documentation_clients[] = $documentation_client;
}
foreach (documentationPendingObligationRowsForClients($documentation_clients, 0) as $documentation_row) {
    $projection = documentationProjectObligationValidity($documentation_row);
    if (in_array($projection['effective_status'], ['Missing', 'Draft', 'Due Soon', 'Stale', 'Exception'], true)) {
        $num_documentation_attention++;
    }
}

// Operational exceptions: open automation incidents plus unresolved or
// conflicting device identities from any integration source.
$operations_incident_scope = clientScopeSql('automation_incident_client_id');
$operations_identity_scope = clientScopeSql('automation_mapping_client_id');
$operations_event_scope = clientScopeSql('automation_incident_client_id');
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
    (SELECT COUNT(*) FROM automation_incidents
        WHERE automation_incident_status = 'Open' $operations_incident_scope)
    +
    (SELECT COUNT(*) FROM automation_entity_mappings
        WHERE automation_mapping_deleted_at IS NULL
        AND automation_mapping_entity_type = 'device'
        AND automation_mapping_state IN ('unresolved', 'suggested', 'conflicting')
        $operations_identity_scope)
    +
    (SELECT COUNT(*) FROM automation_events
        LEFT JOIN automation_incidents
            ON automation_incident_source = automation_event_source
            AND automation_incident_key = automation_event_incident_key
        WHERE automation_event_status IN ('Failed', 'Dead')
        $operations_event_scope) AS num"));
$num_operations_attention = intval($row['num'] ?? 0);

// Recurring Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_ticket_id') AS num FROM recurring_tickets WHERE 1 = 1 " . clientScopeSql('recurring_ticket_client_id') . ""));
$num_recurring_tickets = $row['num'];

// Active Project Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('project_id') AS num FROM projects WHERE project_archived_at IS NULL AND project_completed_at IS NULL " . clientScopeSql('project_client_id') . ""));
$num_active_projects = $row['num'];

// Active and draft client agreements
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('contract_id') AS num FROM contracts
    WHERE contract_archived_at IS NULL " . clientScopeSql('contract_client_id') . ""));
$num_agreements = intval($row['num'] ?? 0);

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
