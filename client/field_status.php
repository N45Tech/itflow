<?php

header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/includes/load_global_settings.php';
require_once dirname(__DIR__) . '/includes/session_init.php';
if (empty($_SESSION['client_logged_in'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Sign in again to view your appointment.']));
}
require_once __DIR__ . '/includes/check_login.php';
require_once __DIR__ . '/functions.php';
try {
    $id = (int) ($_GET['ticket_id'] ?? 0);
    $own = contactCan('tickets_all') ? '' : " AND ticket_contact_id = $session_contact_id";
    $ticket = fieldRows("SELECT ticket_id FROM tickets WHERE ticket_id = $id
        AND ticket_client_id = $session_client_id AND ticket_archived_at IS NULL $own");
    if (!$ticket) {
        http_response_code(404);
        exit(json_encode(['error' => 'This appointment is unavailable.']));
    }
    // Only the original appointment ticket receives the shared visit summary.
    // Other work performed during the same visit is never projected here.
    $visit = mysqli_fetch_assoc(fieldDb("SELECT v.*, u.user_name FROM field_visits v
        JOIN users u ON u.user_id = v.visit_user_id WHERE visit_ticket_id = $id
        AND visit_client_id = $session_client_id ORDER BY visit_id DESC LIMIT 1"));
    $result = null;
    if ($visit) {
        $location = null;
        if ($visit['visit_active_user_id'] && $visit['visit_share_location'] && $visit['visit_location_at']
            && $visit['visit_latitude'] !== null && $visit['visit_longitude'] !== null
            && strtotime($visit['visit_location_at'] . ' UTC') >= time() - 3600) {
            $location = ['latitude' => (float) $visit['visit_latitude'], 'longitude' => (float) $visit['visit_longitude'],
                'accuracy' => (int) $visit['visit_accuracy_meters'], 'updated_at' => fieldUtc($visit['visit_location_at'])];
        }
        $status = in_array($visit['visit_status'], ['waiting', 'break'], true)
            ? ($visit['visit_arrived_at'] ? 'onsite' : 'travel') : $visit['visit_status'];
        $result = ['technician' => $visit['user_name'], 'status' => $status,
            'arrived_at' => fieldUtc($visit['visit_arrived_at']), 'finished_at' => fieldUtc($visit['visit_finished_at']),
            'eta_at' => fieldUtc($visit['visit_eta_at']), 'location' => $location,
            'summary' => $visit['visit_finished_at'] ? $visit['visit_customer_summary'] : null];
    }
    echo json_encode(['data' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Field appointment status: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Appointment updates are temporarily unavailable.']);
}
