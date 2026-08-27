<?php
/*
 * Client Portal
 * Functions
 */

/*
 * Verifies a contact has access to a particular ticket ID, and that the ticket is in the correct state (open/closed) to perform an action
 */
function verifyContactTicketAccess($requested_ticket_id, $expected_ticket_state) {

    // Access the global variables
    global $mysqli, $session_contact_id, $session_client_id;

    // Setup
    if ($expected_ticket_state == "Closed") {
        // Closed tickets
        $ticket_state_snippet = "ticket_status = 5";
    } else {
        // Open (working/hold) tickets
        $ticket_state_snippet = "ticket_status != 5";
    }

    // Verify the contact has access to the provided ticket ID
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $requested_ticket_id AND $ticket_state_snippet AND ticket_client_id = $session_client_id LIMIT 1"));
    if ($row) {
        $ticket_id = $row['ticket_id'];

        if (intval($ticket_id) && ($session_contact_id == $row['ticket_contact_id'] || contactCan('tickets_all'))) {
            // Client is the ticket owner or has organization-wide ticket access.
            return true;
        }
    }

    // Client is NOT ticket owner or primary/tech contact
    return false;

}

/*
 * Portal access control - single source of truth for what a logged-in contact can do.
 * Primary contacts have full access; all other capabilities are independent.
 * Capabilities are named by area so the rule for one can change without touching callers.
 */
function contactCan($capability) {
    global $session_contact_primary, $session_contact_is_billing_contact, $session_contact_is_technical_contact,
        $session_contact_ticket_scope, $session_contact_asset_scope, $session_contact_can_manage_contacts;

    // Primary contacts can do everything in the portal
    if ($session_contact_primary == 1) {
        return true;
    }

    switch ($capability) {
        case 'accounting':   // invoices, quotes, recurring invoices, saved payment methods
            return (bool) $session_contact_is_billing_contact;

        case 'tickets_all':  // all tickets for the client organization
            return ($session_contact_ticket_scope ?? 'own') === 'client';

        case 'assets_all':   // all assets for the client organization
            return ($session_contact_asset_scope ?? 'assigned') === 'client';

        case 'assets':       // every portal contact may view their permitted asset inventory
            return true;

        case 'itdoc':        // certificates, domains, documents, files
            return (bool) $session_contact_is_technical_contact;

        case 'contacts':     // view / manage client contacts
            return (bool) ($session_contact_can_manage_contacts ?? false);

        default:             // unknown capability -> deny (fail closed)
            return false;
    }
}

/*
 * Normalize the two simple portal roles exposed in contact forms. The database
 * stores ticket and asset scopes separately so they can evolve independently.
 */
function portalAccessScopesFromRole($role) {
    if ($role === 'manager') {
        return [
            'ticket_scope' => 'client',
            'asset_scope' => 'client',
        ];
    }

    return [
        'ticket_scope' => 'own',
        'asset_scope' => 'assigned',
    ];
}

function portalAccessRoleFromScopes($ticket_scope, $asset_scope) {
    return $ticket_scope === 'client' && $asset_scope === 'client' ? 'manager' : 'user';
}

/*
 * Enforce a capability at the top of a page or handler - bounce the contact out if they lack it.
 */
function enforceContactCan($capability) {
    if (!contactCan($capability)) {
        redirect("post.php?logout");
    }
}

/*
 * Returns appropriate FontAwesome icon for file extension
 */
function getFileIcon($file_extension) {
    $file_extension = strtolower($file_extension);

    // Document icons
    if (in_array($file_extension, ['pdf'])) {
        return 'file-pdf';
    } elseif (in_array($file_extension, ['doc', 'docx'])) {
        return 'file-word';
    } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
        return 'file-excel';
    } elseif (in_array($file_extension, ['ppt', 'pptx'])) {
        return 'file-powerpoint';
    } elseif (in_array($file_extension, ['txt', 'md', 'rtf'])) {
        return 'file-alt';
    } elseif (in_array($file_extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
        return 'file-archive';
    } elseif (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
        return 'file-image';
    } elseif (in_array($file_extension, ['mp4', 'avi', 'mov', 'wmv', 'flv'])) {
        return 'file-video';
    } elseif (in_array($file_extension, ['mp3', 'wav', 'ogg', 'flac'])) {
        return 'file-audio';
    } elseif (in_array($file_extension, ['html', 'htm', 'css', 'js', 'php', 'py', 'java'])) {
        return 'file-code';
    } else {
        return 'file';
    }
}
