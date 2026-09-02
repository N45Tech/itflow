<?php
/*
 * API - Ticket Replies - Create
 * POST /api/v1/ticket_replies/create.php
 *
 * Adds a reply to an existing ticket. This is the endpoint an RMM, monitoring
 * system or chat bridge uses to append to a ticket it didn't open.
 *
 * Parameters (POST, JSON body):
 *   api_key                  required - Your API key
 *   client_id                required - Must match the ticket's client (restricted
 *                                       keys only; unrestricted/admin keys may omit)
 *   ticket_id                required - Ticket to reply to
 *   ticket_reply             required - Reply body (HTML allowed, same as the UI)
 *   ticket_reply_type        optional - 'Internal' (default) or 'Public'.
 *                                       Public emails the contact and any watchers
 *                                       and counts as the ticket's first response.
 *   ticket_reply_time_worked optional - HH:MM:SS, default 00:00:00
 *   ticket_status            optional - Also set an existing active ticket status.
 *                                       Status 0 leaves state unchanged; status 4
 *                                       resolves the ticket (sets resolved_at + SLA met).
 *
 * Security:
 *   - The parent ticket is loaded through apiClientScopeSql(), so a restricted key
 *     can't reply to another client's ticket even with a valid ticket_id.
 *   - The client_id supplied for the write must match the ticket's own client.
 *   - The reply is attributed to the user the API key runs as ($session_user_id,
 *     set by enforce_api_rbac.php), so ticket history stays honest.
 *
 * Note: 'ticket_replies' must be present in $resource_module in enforce_api_rbac.php
 * (mapped to module_support) or the enforcer will fail closed on this endpoint.
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Ticket/mail settings for public reply notifications
require_once "../../../includes/load_global_settings.php";

// Parse Info
$ticket_id = intval($_POST['ticket_id'] ?? 0);
$ticket_reply_row = false; // Creation, not an update
require_once 'ticket_reply_model.php';
$reply_ticket_status_supplied = array_key_exists('ticket_status', $_POST);
$reply_ticket_status_input_valid = true;
if ($reply_ticket_status_supplied) {
    $validated_ticket_status = (is_int($_POST['ticket_status']) || is_string($_POST['ticket_status']))
        ? filter_var($_POST['ticket_status'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
        : false;
    if ($validated_ticket_status === false) {
        $reply_ticket_status_input_valid = false;
        $reply_ticket_status = 0;
    } else {
        $reply_ticket_status = intval($validated_ticket_status);
    }
}

// Default
$insert_id = false;

if (!empty($ticket_id) && !empty($reply)) {

    // Load the parent ticket, scoped to the key user's client access
    $ticket_sql = mysqli_query(
        $mysqli,
        "SELECT * FROM tickets
         WHERE ticket_id = $ticket_id
           AND 1=1 " . apiClientScopeSql('ticket_client_id') . "
         LIMIT 1"
    );
    $ticket_row = $ticket_sql ? mysqli_fetch_assoc($ticket_sql) : null;

    // The client named on the write must be the ticket's own client
    if ($ticket_row && $client_id != 0 && intval($ticket_row['ticket_client_id']) !== $client_id) {
        $ticket_row = null;
    }

    if ($ticket_row) {

        $ticket_prefix = escapeSql($ticket_row['ticket_prefix']);
        $ticket_number = intval($ticket_row['ticket_number']);
        $ticket_subject = escapeSql($ticket_row['ticket_subject']);
        $ticket_url_key = escapeSql($ticket_row['ticket_url_key']);
        $ticket_first_response_at = escapeSql($ticket_row['ticket_first_response_at']);
        $client_id = intval($ticket_row['ticket_client_id']);
        $original_ticket_status = intval($ticket_row['ticket_status']);

        $insert_sql = false;
        $status_changed = false;
        $resolved_now = false;
        $reopened_now = false;

        try {
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin the ticket reply transaction');
            }

            // Replies also serialize on the parent so they cannot land after a
            // concurrent close. A requested nonterminal state can reopen a
            // resolved ticket, so it additionally follows the project -> ticket
            // lock order and rejects children of completed projects.
            $requested_nonterminal_status = !empty($reply_ticket_status)
                && !in_array($reply_ticket_status, [4, 5], true);
            // The advisory status read can race a reopen. Lock client -> ticket
            // for every terminal request before recomputing the locked state.
            if (in_array($reply_ticket_status, [4, 5], true)) {
                documentationLockClientTicket($ticket_id, $client_id);
            }
            $locked_ticket = $requested_nonterminal_status
                ? runbookLockTicketForReopen($ticket_id)
                : runbookLockTicketForTransition($ticket_id, true);
            if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
                throw new RuntimeException('The ticket moved outside this API key client scope');
            }
            $original_ticket_status = intval($locked_ticket['ticket_status']);

            if (!$reply_ticket_status_input_valid) {
                throw new RuntimeException('The requested ticket status is invalid');
            }
            if ($reply_ticket_status_supplied && $reply_ticket_status !== 0) {
                $active_status = mysqli_fetch_assoc(runbookDbQuery("SELECT ticket_status_id
                    FROM ticket_statuses WHERE ticket_status_id = $reply_ticket_status
                    AND ticket_status_active = 1 LIMIT 1 FOR UPDATE",
                    'Could not validate the requested ticket status'));
                if (!$active_status) {
                    throw new RuntimeException('The requested ticket status is unavailable or inactive');
                }
            }

            // Normalize a no-op against the locked state, not the earlier
            // authorization read, so concurrent lifecycle changes cannot turn
            // it into an unintended transition.
            if ($reply_ticket_status === $original_ticket_status) {
                $reply_ticket_status = 0;
            }

            if (!empty($reply_ticket_status)) {

                // Closing is a resolved-to-closed transition. The permissive
                // lock must not turn a direct open-to-closed request into a new
                // capability for this endpoint.
                if ($reply_ticket_status === 5 && $original_ticket_status !== 4) {
                    $reply_ticket_status = $original_ticket_status;
                }

                if (in_array($reply_ticket_status, [4, 5], true)) {
                    if ($reply_ticket_status !== $original_ticket_status) {
                        // Open-to-terminal transitions also pass the strict open
                        // lock. A resolved-to-closed transition is already covered
                        // by the transition lock above.
                        if ($original_ticket_status !== 4) {
                            $locked_ticket = runbookLockOpenTicket($ticket_id);
                        }
                        [$can_resolve] = runbookTicketCanResolve($ticket_id);
                        if (!$can_resolve) {
                            $reply_ticket_status = $original_ticket_status;
                        }
                    }
                }
            }

            // The reply and requested state change commit atomically. Notification,
            // SLA and custom-action effects run after this transaction.
            $insert_sql = mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$reply', ticket_reply_type = '$reply_type', ticket_reply_time_worked = '$reply_time_worked', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket_id");
            if (!$insert_sql) {
                throw new RuntimeException('Could not add the ticket reply');
            }
            $insert_id = mysqli_insert_id($mysqli);

            if (!empty($reply_ticket_status) && $reply_ticket_status !== $original_ticket_status) {
                $locked_status = intval($locked_ticket['ticket_status']);
                $resolved_at_predicate = empty($locked_ticket['ticket_resolved_at'])
                    ? 'ticket_resolved_at IS NULL'
                    : "ticket_resolved_at = '" . escapeSql($locked_ticket['ticket_resolved_at']) . "'";
                $closed_at_predicate = empty($locked_ticket['ticket_closed_at'])
                    ? 'ticket_closed_at IS NULL'
                    : "ticket_closed_at = '" . escapeSql($locked_ticket['ticket_closed_at']) . "'";

                if ($reply_ticket_status === 4) {
                    $status_set = 'ticket_status = 4, ticket_resolved_at = NOW()';
                    $resolved_now = true;
                } elseif ($reply_ticket_status === 5) {
                    $status_set = "ticket_status = 5, ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id";
                } else {
                    $status_set = "ticket_status = $reply_ticket_status, ticket_resolved_at = NULL";
                    $reopened_now = $original_ticket_status === 4;
                }

                $status_sql = mysqli_query($mysqli, "UPDATE tickets SET $status_set
                    WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
                    AND ticket_status = $locked_status AND $resolved_at_predicate
                    AND $closed_at_predicate LIMIT 1");
                if (!$status_sql || mysqli_affected_rows($mysqli) !== 1) {
                    throw new RuntimeException('The ticket status changed before the reply could be committed');
                }
                if (in_array($reply_ticket_status, [4, 5], true)) {
                    documentationRecordChangePassport($ticket_id, $reply_ticket_status, $session_user_id, true);
                }
                $status_changed = true;
            }

            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the ticket reply');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            $insert_sql = false;
            $insert_id = false;
            error_log('Ticket reply API failed closed for ticket ' . $ticket_id . ': ' . $e->getMessage());
        }

        // Check insert & get insert ID
        if ($insert_sql) {
            // Mark first response only after the public reply is durable.
            if (empty($ticket_first_response_at) && $reply_type == 'Public') {
                setTicketFirstResponse($ticket_id);
            }

            // Optional status change alongside the reply
            if ($status_changed) {
                // Only record a status change when the status actually changed -
                // Resolved is left out because the resolve block below logs it
                if ($reply_ticket_status !== $original_ticket_status && $reply_ticket_status != 4) {
                    $new_status_name = escapeSql(getTicketStatusName($reply_ticket_status));
                    logTicketHistory($ticket_id, "Status set to $new_status_name via the API ($api_key_name)");
                }

                // Resolve the ticket, if it is actually moving into Resolved
                if ($resolved_now) {
                    setTicketResolutionSlaMet($ticket_id);

                    logTicketHistory($ticket_id, "Resolved via the API ($api_key_name)");

                    logAudit("Ticket", "Resolved", "Resolved ticket $ticket_prefix$ticket_number via API ($api_key_name)", $client_id, $ticket_id);

                    triggerCustomAction('ticket_resolve', $ticket_id);
                }
                if ($reopened_now) {
                    resetTicketResolutionSla($ticket_id);
                }
                syncTicketSlaClock($ticket_id);
            }

            // Logging
            logAudit("Ticket", "Reply", "Added a $reply_type reply to ticket $ticket_prefix$ticket_number - $ticket_subject via API ($api_key_name)", $client_id, $ticket_id);
            logAudit("API", "Success", "Added a $reply_type reply to ticket $ticket_prefix$ticket_number via API ($api_key_name)", $client_id);

            // Custom action/notif handler
            if ($reply_type == 'Internal') {
                triggerCustomAction('ticket_reply_agent_internal', $ticket_id);
            } else {
                triggerCustomAction('reply_reply_agent_public', $ticket_id);
            }

            // Email the contact & watchers on a public reply (mirrors the agent reply handler)
            if ($reply_type == 'Public' && !empty($config_smtp_provider)) {

                $notify_sql = mysqli_query(
                    $mysqli,
                    "SELECT contact_name, contact_email, ticket_status_name
                     FROM tickets
                     LEFT JOIN contacts ON ticket_contact_id = contact_id
                     LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
                     WHERE ticket_id = $ticket_id"
                );
                $notify_row = mysqli_fetch_assoc($notify_sql);

                $contact_name = escapeSql($notify_row['contact_name']);
                $contact_email = escapeSql($notify_row['contact_email']);
                $ticket_status_name = escapeSql($notify_row['ticket_status_name']);

                // Sanitize config vars from load_global_settings.php
                $from_name = escapeSql($config_ticket_from_name);
                $from_email = escapeSql($config_ticket_from_email);

                $company_sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
                $company_row = mysqli_fetch_assoc($company_sql);
                $company_name = escapeSql($company_row['company_name']);
                $company_phone = escapeSql(formatPhoneNumber($company_row['company_phone'], $company_row['company_phone_country_code']));

                $ticket_email_context = [
                    'company_name' => $company_name,
                    'contact_name' => $contact_name,
                    'ticket_number' => $ticket_prefix . $ticket_number,
                    'ticket_subject' => $ticket_subject,
                    'ticket_status' => $ticket_status_name,
                    'message_html' => $reply,
                    'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$ticket_url_key",
                    'footer_email' => $from_email,
                    'footer_phone' => $company_phone,
                ];
                $ticket_template_key = $ticket_status_name === 'Resolved' ? 'ticket.resolved' : 'ticket.updated';
                $ticket_email = renderN45Email($ticket_template_key, $ticket_email_context);

                $data = [];

                // Email ticket contact
                if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
                    $data[] = array_merge([
                        'from' => $from_email,
                        'from_name' => $from_name,
                        'recipient' => $contact_email,
                        'recipient_name' => $contact_name,
                    ], n45EmailQueueFields($ticket_email));
                }

                // Also email all the watchers
                $sql_watchers = mysqli_query($mysqli, "SELECT watcher_name, watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
                while ($watcher_row = mysqli_fetch_assoc($sql_watchers)) {
                    $watcher_name = escapeSql($watcher_row['watcher_name']);
                    $watcher_email = escapeSql($watcher_row['watcher_email']);
                    $watcher_email_context = $ticket_email_context;
                    $watcher_email_context['contact_name'] = $watcher_name;
                    $watcher_email_context['recipient_role'] = 'collaborator';
                    $watcher_message = renderN45Email($ticket_template_key, $watcher_email_context);

                    if (filter_var($watcher_email, FILTER_VALIDATE_EMAIL)) {
                        $data[] = array_merge([
                            'from' => $from_email,
                            'from_name' => $from_name,
                            'recipient' => $watcher_email,
                            'recipient_name' => $watcher_name,
                        ], n45EmailQueueFields($watcher_message));
                    }
                }

                if (!empty($data)) {
                    addToMailQueue($data);
                }

            }

        }

    }

}

// Output
require_once '../create_output.php';
