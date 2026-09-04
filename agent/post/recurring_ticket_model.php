<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$subject = escapeSql($_POST['subject']);
$priority = escapeSql($_POST['priority']);
$details = mysqli_real_escape_string($mysqli, $_POST['details']);
$frequency = escapeSql($_POST['frequency']);
$billable = intval($_POST['billable'] ?? 0);
$asset_id = intval($_POST['asset_id'] ?? 0);
$contact_id = intval($_POST['contact_id'] ?? 0);
$assigned_to = intval($_POST['assigned_to'] ?? 0);
$category_id = intval($_POST['category_id'] ?? 0);
$ticket_template_id = intval($_POST['ticket_template_id'] ?? 0);

// Recurring schedules intentionally own a flat, editable checklist snapshot.
// A published runbook would lose its conditions, dependencies, approvals and
// evidence gates in that model, so it cannot be newly linked here. An existing
// schedule whose legacy template was published later may retain its already-
// captured checklist until an agent deliberately unlinks or replaces it.
if ($ticket_template_id && runbookLatestPublishedVersionId($ticket_template_id)) {
    $existing_ticket_template_id = 0;
    if (isset($_POST['edit_recurring_ticket'])) {
        $recurring_ticket_id = intval($_POST['recurring_ticket_id'] ?? 0);
        $existing_ticket_template_id = intval(getFieldById(
            'recurring_tickets',
            $recurring_ticket_id,
            'recurring_ticket_ticket_template_id'
        ));
    }
    if ($existing_ticket_template_id !== $ticket_template_id) {
        flashAlert('Published conditional runbooks cannot be flattened into recurring schedules. Start them from a ticket or project instead.', 'error');
        redirect();
    }
}
