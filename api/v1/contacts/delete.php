<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';


// Parse ID
$contact_id = intval($_POST['contact_id']);

// Default
$delete_count = false;

if (!empty($contact_id)) {
    if (!mysqli_begin_transaction($mysqli)) {
        error_log("Could not begin API contact deletion transaction for contact $contact_id");
    } else {
        try {
            $row = portalRequestLockContactForAuditRetention($contact_id, $client_id);
            if (!$row) {
                throw new RuntimeException('The contact is unavailable for the requested client');
            }
            if (portalRequestContactHasAuditHistory($contact_id, $client_id)) {
                throw new RuntimeException('The contact is retained by portal request audit history');
            }
            $contact_name = escapeSql((string) $row['contact_name']);
            portalRequestDbQuery("DELETE FROM contacts
                WHERE contact_id = $contact_id AND contact_client_id = $client_id LIMIT 1",
                'Could not delete the API contact');
            $delete_count = mysqli_affected_rows($mysqli);
            if ($delete_count !== 1 || !mysqli_commit($mysqli)) {
                throw new RuntimeException('The API contact deletion did not commit');
            }

            // Logging happens only after the guarded deletion is durable.
            logAudit("Contact", "Delete", "$contact_name via API ($api_key_name)", $client_id);
        } catch (Throwable $exception) {
            mysqli_rollback($mysqli);
            $delete_count = false;
            error_log("API contact deletion blocked for contact $contact_id: " . $exception->getMessage());
        }
    }
}

// Output
require_once '../delete_output.php';
