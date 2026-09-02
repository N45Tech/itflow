<?php

// Variable assignment from POST (or: blank/from DB is updating)

if (isset($_POST['ticket_contact_id'])) {
    $contact = intval($_POST['ticket_contact_id']);
} elseif ($ticket_row) {
    $contact = $ticket_row['ticket_contact_id'];
} else {
    $contact = '0';
}

if (isset($_POST['ticket_asset_id'])) {
    $asset = intval($_POST['ticket_asset_id']);
} elseif ($ticket_row) {
    $asset = $ticket_row['ticket_asset_id'];
} else {
    $asset = '0';
}

if (isset($_POST['ticket_subject'])) {
    $subject = escapeSql($_POST['ticket_subject']);
} elseif ($ticket_row) {
    $subject = mysqli_real_escape_string($mysqli, $ticket_row['ticket_subject']);
} else {
    $subject = '';
}


$ticket_operational_valid = true;
try {
    $legacy_priority = $_POST['ticket_priority'] ?? null;
    if ($legacy_priority !== null && !array_key_exists((string) $legacy_priority, ticketPriorityDefinitions())) {
        throw new InvalidArgumentException('ticket_priority must be Low, Medium, High, or Urgent');
    }
    $has_impact = array_key_exists('ticket_impact', $_POST);
    $has_urgency = array_key_exists('ticket_urgency', $_POST);
    if ($has_impact !== $has_urgency) {
        throw new InvalidArgumentException('ticket_impact and ticket_urgency must be supplied together');
    }
    if ($legacy_priority !== null && (!$has_impact || !$has_urgency)) {
        throw new InvalidArgumentException('ticket_priority cannot be supplied without ticket_impact and ticket_urgency');
    }
    $api_impact = $has_impact ? $_POST['ticket_impact'] : ($ticket_row['ticket_impact'] ?? 'low');
    $api_urgency = $has_urgency ? $_POST['ticket_urgency'] : ($ticket_row['ticket_urgency'] ?? 'low');
    $operational = ticketOperationalInput([
        'work_type' => $_POST['ticket_work_type'] ?? ($ticket_row['ticket_work_type'] ?? 'request'),
        'impact' => $api_impact,
        'urgency' => $api_urgency,
        'next_action' => $_POST['ticket_next_action'] ?? ($ticket_row['ticket_next_action'] ?? 'Review and triage this API ticket.'),
        'next_action_due_at' => $_POST['ticket_next_action_due_at'] ?? ($ticket_row['ticket_next_action_due_at'] ?? null),
        'waiting_on' => $_POST['ticket_waiting_on'] ?? ($ticket_row['ticket_waiting_on'] ?? 'none'),
        'waiting_on_detail' => $_POST['ticket_waiting_on_detail'] ?? ($ticket_row['ticket_waiting_on_detail'] ?? ''),
    ], $ticket_row ?: null);
    if ($legacy_priority !== null && $operational['priority'] !== $legacy_priority) {
        throw new InvalidArgumentException('ticket_priority conflicts with ticket_impact and ticket_urgency');
    }
    $priority = mysqli_real_escape_string($mysqli, $operational['priority']);
    $work_type = mysqli_real_escape_string($mysqli, $operational['work_type']);
    $impact = mysqli_real_escape_string($mysqli, $operational['impact']);
    $urgency = mysqli_real_escape_string($mysqli, $operational['urgency']);
    $next_action = mysqli_real_escape_string($mysqli, $operational['next_action']);
    $next_action_due_at = $operational['next_action_due_at'] === null
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $operational['next_action_due_at']) . "'";
    $waiting_on = mysqli_real_escape_string($mysqli, $operational['waiting_on']);
    $waiting_on_detail = $operational['waiting_on_detail'] === ''
        ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $operational['waiting_on_detail']) . "'";
} catch (InvalidArgumentException $exception) {
    $ticket_operational_valid = false;
    error_log('Ticket API operational validation rejected a request: ' . $exception->getMessage());
}


if (isset($_POST['ticket_details'])) {
    $details = mysqli_real_escape_string($mysqli, $_POST['ticket_details'] . "<br>");
} elseif ($ticket_row) {
    $details = mysqli_real_escape_string($mysqli, $ticket_row['ticket_details']);
} else {
    $details = '< blank ><br>';
}

if (isset($_POST['ticket_vendor_id'])) {
    $vendor_id = intval($_POST['ticket_vendor_id']);
} elseif ($ticket_row) {
    $vendor_id = $ticket_row['ticket_vendor_id'];
} else {
    $vendor_id = '0';
}

if (isset($_POST['ticket_vendor_ticket_id'])) {
    $vendor_ticket_number = intval($_POST['ticket_vendor_ticket_id']);
} elseif ($ticket_row) {
    $vendor_ticket_number = $ticket_row['ticket_vendor_ticket_id'];
} else {
    $vendor_ticket_number = '0';
}

if (isset($_POST['ticket_assigned_to'])) {
    $assigned_to = intval($_POST['ticket_assigned_to']);
} elseif ($ticket_row) {
    $assigned_to = $ticket_row['ticket_assigned_to'];
} else {
    $assigned_to = '0';
}

if (isset($_POST['ticket_billable'])) {
    $billable = intval($_POST['ticket_billable']);
} elseif ($ticket_row) {
    $billable = $ticket_row['ticket_billable'];
} else {
    $billable = '0';
}
