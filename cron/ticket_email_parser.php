<?php
/*
 * CRON - Email Parser (DirectoryTree ImapEngine)
 * Process emails and create/update tickets using DirectoryTree\ImapEngine (no PHP IMAP extension required)
 */

// Start the timer
$script_start_time = microtime(true);

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Prevent overlapping runs of this script
$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";

// Autoload (Webklex & any composer deps)
require_once "../libs/vendor/autoload.php";

// Get ITFlow config & helper functions
require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

// Get settings for the "default" company
require_once "../includes/load_global_settings.php";

$config_ticket_prefix = escapeSql($config_ticket_prefix);
$config_ticket_from_name = escapeSql($config_ticket_from_name);
$config_ticket_email_parse_unknown_senders = intval($row['config_ticket_email_parse_unknown_senders']);

// Get company name & phone & timezone
$sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
$row = mysqli_fetch_assoc($sql);
$company_name = escapeSql($row['company_name']);
$company_phone = escapeSql(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

// Check cron is enabled
if ($config_enable_cron == 0) {
    logApp("Cron-Email-Parser", "error", "Cron Email Parser unable to run - cron not enabled in admin settings.");
    cronJobStop("Cron: is not enabled -- Quitting..");
}

// Check setting enabled
if ($config_ticket_email_parse == 0) {
    logApp("Cron-Email-Parser", "error", "Cron Email Parser unable to run - not enabled in admin settings.");
    cronJobStop("Email Parser: Feature is not enabled - check Settings > Ticketing > Email-to-ticket parsing. See https://docs.itflow.org/ticket_email_parse  -- Quitting..");
}

// Overlapping runs are prevented by cron/includes/cron_lock.php. This script used to keep a
// lock file of its own alongside that one, which needed a five minute age heuristic to
// recover from a killed run and could only end itself with exit() - fatal to a dispatched
// job. flock covers the same ground and the kernel drops it however the process ends.

// Allowed attachment extensions
$allowed_extensions = array('jpg', 'jpeg', 'gif', 'png', 'webp', 'pdf', 'txt', 'md', 'doc', 'docx', 'csv', 'xls', 'xlsx', 'xlsm', 'zip', 'tar', 'gz');

// Processing limits
$max_emails_per_run = 50;          // Cap per cron run to bound memory usage (cron catches up on the next run)
$max_attachment_bytes = 15728640;  // 15 MB - larger attachments are skipped & logged
$max_inline_embed_bytes = 2097152; // 2 MB - larger inline images are saved as regular attachments instead of base64-embedded in the ticket body
$max_raw_message_bytes = 31457280; // 30 MB - reject pathological messages before parsing/storing
$email_parser_last_ticket_id = 0;
$email_parser_last_reply_id = 0;
$email_parser_explicit_reply_rejected = false;
$email_parser_ingress_finalized = false;
$email_parser_ingress_token = '';
$email_parser_rejection_reason = null;

/** ------------------------------------------------------------------
 * Ticket / Reply helpers (unchanged)
 * ------------------------------------------------------------------ */
function addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message, $attachments, string $raw_original_message, $ccs, bool $trusted_sender, int $ingress_id, string $ingress_token) {
    global $mysqli, $config_app_name, $company_name, $company_phone, $config_ticket_prefix, $config_ticket_client_general_notifications, $config_ticket_new_ticket_notification_email, $config_base_url, $config_ticket_from_name, $config_ticket_from_email, $config_ticket_default_billable, $email_parser_last_ticket_id, $email_parser_ingress_finalized, $email_parser_explicit_reply_rejected, $email_parser_rejection_reason;
    $bad_pattern = "/do[\W_]*not[\W_]*reply|no[\W_]*reply/i"; // Email addresses to ignore

    // Clean up the message
    $message = ticketEmailSanitizeInboundHtml(trim($message));
    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);
    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);
    // Collapse excess whitespace
    $message = preg_replace('/\s+/', ' ', $message);
    // Convert newlines to <br>
    $message = nl2br($message);
    // Wrap final formatted message without trusting the MIME display name.
    $contact_name_html = htmlspecialchars((string) $contact_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $contact_email_html = htmlspecialchars((string) $contact_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $date_html = htmlspecialchars((string) $date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $message = "<i>Email from: <b>$contact_name_html</b> &lt;$contact_email_html&gt; at $date_html:-</i> <br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
    $subject_esc = mysqli_real_escape_string($mysqli, $subject);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $contact_email_esc = mysqli_real_escape_string($mysqli, $contact_email);
    $contact_name_esc = mysqli_real_escape_string($mysqli, $contact_name);
    $client_id = intval($client_id);
    $contact_id = intval($contact_id);
    $ingress_id = intval($ingress_id);
    if ($client_id > 0 && !$trusted_sender) {
        throw new DomainException('Tenant-bound email intake requires trusted, aligned authentication');
    }
    if ($ingress_id < 1) {
        throw new InvalidArgumentException('Inbound message claim is required');
    }
    if (($rate_limit = ticketEmailIngressRateLimitReason($ingress_id, $ingress_token, $client_id)) !== null) {
        $email_parser_explicit_reply_rejected = true;
        $email_parser_rejection_reason = $rate_limit;
        return false;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $ingress_token)) {
        throw new InvalidArgumentException('Inbound message ownership token is required');
    }
    $created_contact_id = 0;

    $url_key = randomString(32);

    $ticket_transaction_started = false;
    try {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the parsed-email ticket transaction');
        }
        $ticket_transaction_started = true;
        if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The parsed-email ticket client is no longer available');
        }
        if ($client_id > 0 && $contact_id < 1) {
            ticketCreationDbQuery("INSERT INTO contacts SET contact_name = '$contact_name_esc',
                contact_email = '$contact_email_esc',
                contact_notes = 'Added automatically via authenticated email parsing.',
                contact_client_id = $client_id",
                'Could not create the authenticated email contact');
            $contact_id = intval(mysqli_insert_id($mysqli));
            $created_contact_id = $contact_id;
            if ($contact_id < 1) {
                throw new RuntimeException('The authenticated email contact did not receive an ID');
            }
        }

        ticketCreationDbQuery("
            UPDATE settings
            SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
            WHERE company_id = 1
        ", 'Could not allocate a parsed-email ticket number');
        $ticket_number = intval(mysqli_insert_id($mysqli));
        if (!$ticket_number) {
            throw new RuntimeException('The parsed-email ticket number allocation returned no number');
        }

        ticketCreationDbQuery("INSERT INTO tickets SET ticket_prefix = '$ticket_prefix_esc', ticket_number = $ticket_number, ticket_source = 'Email', ticket_subject = '$subject_esc', ticket_details = '$message_esc', ticket_priority = 'Low', ticket_work_type = 'request', ticket_impact = 'low', ticket_urgency = 'low', ticket_next_action = 'Review and triage this inbound email.', ticket_waiting_on = 'none', ticket_operational_updated_by = 0, ticket_operational_updated_at = NOW(), ticket_status = 1, ticket_billable = $config_ticket_default_billable, ticket_created_by = 0, ticket_contact_id = $contact_id, ticket_url_key = '$url_key', ticket_client_id = $client_id", 'Could not create the parsed-email ticket');
        $id = intval(mysqli_insert_id($mysqli));
        if (!$id) {
            throw new RuntimeException('The parsed-email ticket did not receive an ID');
        }
        applyTicketSla($id, null, null, true);
        ticketEmailIngressComplete($ingress_id, $ingress_token, 'Processed', $id, 0, null, $client_id);

        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the parsed-email ticket and SLA decision');
        }
        $ticket_transaction_started = false;
        $email_parser_last_ticket_id = $id;
        $email_parser_ingress_finalized = true;
    } catch (Throwable $exception) {
        if ($ticket_transaction_started) {
            mysqli_rollback($mysqli);
        }
        throw $exception;
    }

    if ($created_contact_id > 0) {
        logAudit('Contact', 'Create', "Email parser created authenticated contact $contact_email_esc", $client_id, $created_contact_id);
        triggerCustomAction('contact_create', $created_contact_id);
    }

    // Logging
    logAudit("Ticket", "Create", "Email parser: Client contact $contact_email_esc created ticket $ticket_prefix_esc$ticket_number ($subject) ($id)", $client_id, $id);

    mkdirMissing('../uploads/tickets/');
    $att_dir = "../uploads/tickets/" . $id . "/";
    mkdirMissing($att_dir);

    // Persist the original only after a ticket exists. Replies, rejects, NDRs,
    // and failures never write transient .eml files to disk.
    $original_message_file = "processed-eml-" . randomString(200) . ".eml";
    if (file_put_contents("{$att_dir}/{$original_message_file}", $raw_original_message) === false) {
        throw new RuntimeException('Could not preserve the original parsed email');
    }
    $original_message_file_esc = mysqli_real_escape_string($mysqli, $original_message_file);
    mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = 'Original-parsed-email.eml', ticket_attachment_reference_name = '$original_message_file_esc', ticket_attachment_ticket_id = $id");

    // Save non-inline attachments
    foreach ($attachments as $attachment) {
        $att_name = $attachment['name'];
        $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));
        $att_mime = (string) ($attachment['mime'] ?? 'application/octet-stream');

        if (ticketEmailAttachmentAllowed($att_name, $att_mime, strlen($attachment['content']), $attachment['content'])) {
            $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
            $att_saved_path = $att_dir . $att_saved_filename;
            file_put_contents($att_saved_path, $attachment['content']);

            $ticket_attachment_name = escapeSql($att_name);
            $ticket_attachment_reference_name = escapeSql($att_saved_filename);

            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
            $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
            mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_ticket_id = $id");
        } else {
            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
            logAudit("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $contact_email_esc for ticket $ticket_prefix_esc$ticket_number", $client_id, $id);
        }
    }

    // Add unknown guests as ticket watcher
    if ($client_id == 0 && !preg_match($bad_pattern, $contact_email_esc)) {
        mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$contact_email_esc', watcher_ticket_id = $id");
    }

    // Add CCs as ticket watchers
    foreach ($ccs as $cc) {
        $cc_esc = mysqli_real_escape_string($mysqli, $cc);
        $same_client_contact = $client_id > 0 && mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contact_id FROM contacts WHERE contact_email = '$cc_esc'
            AND contact_client_id = $client_id AND contact_archived_at IS NULL LIMIT 1"));
        if ($same_client_contact && filter_var($cc, FILTER_VALIDATE_EMAIL) && !preg_match($bad_pattern, $cc)) {
            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$cc_esc', watcher_ticket_id = $id");
        }
    }

    // External email
    $data = [];
    if ($config_ticket_client_general_notifications == 1 && !preg_match($bad_pattern, $contact_email)) {
        $ticket_email = renderN45Email('ticket.created', [
            'company_name' => escapeSql($company_name),
            'contact_name' => escapeSql($contact_name),
            'ticket_number' => escapeSql($config_ticket_prefix) . $ticket_number,
            'ticket_subject' => $subject,
            'ticket_status' => 'New',
            'message_html' => $message_esc,
            'action_url' => "https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$id&url_key=$url_key",
            'footer_email' => escapeSql($config_ticket_from_email),
            'footer_phone' => escapeSql($company_phone),
        ]);
        $data[] = array_merge([
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
        ], n45EmailQueueFields($ticket_email));
    }

    // Internal email
    if ($config_ticket_new_ticket_notification_email) {
        if ($client_id == 0) {
            $client_name = "Guest";
            $client_uri = '';
        } else {
            $client_sql = mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id");
            $client_row = mysqli_fetch_assoc($client_sql);
            $client_name = escapeSql($client_row['client_name']);
            $client_uri = "&client_id=$client_id";
        }
        $email_subject = "$config_app_name - New Ticket - $client_name: $subject";
        $email_body = "Hello, <br><br>This is a notification that a new ticket has been raised in ITFlow. <br>Client: $client_name<br>Priority: Low (email parsed)<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$id$client_uri <br><br>--------------------------------<br><br><b>$subject</b><br>$message";

        $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $config_ticket_new_ticket_notification_email,
            'recipient_name' => $config_ticket_from_name,
            'subject' => $email_subject,
            'body' => mysqli_real_escape_string($mysqli, $email_body)
        ];
    }

    addToMailQueue($data);
    triggerCustomAction('ticket_create', $id);

    return true;
}

function addReply($from_email, $date, $subject, $ticket_number, $message, $attachments, bool $trusted_sender, int $ingress_id, string $ingress_token) {
    global $mysqli, $config_app_name, $company_name, $company_phone, $config_ticket_prefix, $config_base_url, $config_ticket_from_name, $config_ticket_from_email, $email_parser_last_ticket_id, $email_parser_last_reply_id, $email_parser_explicit_reply_rejected, $email_parser_ingress_finalized, $email_parser_rejection_reason;

    $ticket_reply_type = 'Client';
    $ingress_id = intval($ingress_id);
    if (!$trusted_sender || $ingress_id < 1 || !preg_match('/^[0-9a-f]{64}$/', $ingress_token)) {
        $email_parser_explicit_reply_rejected = true;
        logApp('Cron-Email-Parser', 'warning', 'Rejected an inbound ticket reply without trusted sender authentication');
        return false;
    }
    // $message contains the raw HTML body from IMAP

    // 1) Remove the reply separator and everything below it (HTML-aware)
    // This matches: <i ...>##- Please type your reply above this line -##</i> and EVERYTHING after it
    $message = preg_replace(
        '/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>.*$/is',
        '',
        $message
    );

    // 2) Clean up the remaining message

    // Branded messages include a complete HTML head before the reply marker.
    // Some mail clients retain that head when quoting the original message, so
    // remove it as a unit instead of leaving its CSS in the stored client reply.
    $message = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $message);
    $message = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $message);

    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);

    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);

    // Trim leading/trailing whitespace
    $message = ticketEmailSanitizeInboundHtml(trim($message));

    // Normalize line breaks to spaces
    $message = preg_replace('/\r\n|\r|\n/', ' ', $message);

    // Convert to <br> for HTML display
    $message = nl2br($message);

    // 3) Final wrapper
    $from_email_html = htmlspecialchars((string) $from_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $date_html = htmlspecialchars((string) $date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $message = "<i>Email from: $from_email_html at $date_html:-</i><br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_number_esc = intval($ticket_number);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);

    $active_ticket_scope = ticketOperationalActiveTicketSql('tickets');
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_subject, ticket_status, ticket_contact_id, ticket_client_id, contact_email, client_name
        FROM tickets
        LEFT JOIN contacts on tickets.ticket_contact_id = contacts.contact_id
        LEFT JOIN clients on tickets.ticket_client_id = clients.client_id
        WHERE ticket_number = $ticket_number_esc
        AND tickets.ticket_deleted_at IS NULL $active_ticket_scope LIMIT 1"));

    if ($row) {
        $ticket_id = intval($row['ticket_id']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $ticket_status = intval($row['ticket_status']);
        $ticket_reply_contact = intval($row['ticket_contact_id']);
        $ticket_contact_email = (string) $row['contact_email'];
        $client_id = intval($row['ticket_client_id']);
        if (($rate_limit = ticketEmailIngressRateLimitReason($ingress_id, $ingress_token, $client_id)) !== null) {
            $email_parser_explicit_reply_rejected = true;
            $email_parser_rejection_reason = $rate_limit;
            logApp('Cron-Email-Parser', 'warning', "Rate-limited inbound reply for client $client_id");
            return false;
        }
        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }
        $client_name = escapeSql($row['client_name']);

        if (strcasecmp((string) $ticket_contact_email, (string) $from_email) !== 0) {
            $from_email_esc2 = mysqli_real_escape_string($mysqli, strtolower($from_email));
            $row2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts
                WHERE LOWER(contact_email) = '$from_email_esc2' AND contact_client_id = $client_id
                AND contact_archived_at IS NULL LIMIT 1"));
            if ($row2) {
                $ticket_reply_contact = intval($row2['contact_id']);
            } else {
                $watcher = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT watcher_id FROM ticket_watchers
                    WHERE watcher_ticket_id = $ticket_id AND LOWER(watcher_email) = '$from_email_esc2' LIMIT 1"));
                if (!$watcher) {
                    $email_parser_explicit_reply_rejected = true;
                    appNotify('Ticket', "Email parser rejected an unauthorized reply from $from_email to $config_ticket_prefix$ticket_number", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id);
                    logApp('Cron-Email-Parser', 'warning', "Rejected unauthorized sender $from_email for ticket $ticket_id");
                    return false;
                }
                $ticket_reply_contact = 0;
            }
        }

        if ($ticket_status === 5) {
            $config_ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
            $ticket_number_esc2 = mysqli_real_escape_string($mysqli, $ticket_number);

            appNotify("Ticket", "Email parser: $from_email attempted to re-open ticket $config_ticket_prefix_esc$ticket_number_esc2 (ID $ticket_id) - check inbox manually to see email", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id);

            $email_subject = "Action required: This ticket is already closed";
            $email_body = "Hi there, <br><br>You've tried to reply to a ticket that is closed - we won't see your response. <br><br>Please raise a new ticket by sending a new e-mail to our support address below. <br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $data = [
                [
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $from_email,
                    'recipient_name' => $from_email,
                    'subject' => $email_subject,
                    'body' => mysqli_real_escape_string($mysqli, $email_body)
                ]
            ];

            addToMailQueue($data);
            return true;
        }

        try {
            if (!mysqli_begin_transaction($mysqli)) {
                throw new RuntimeException('Could not begin the inbound ticket-reply transaction');
            }
            if ($client_id > 0) {
                documentationLockClient($client_id);
            }
            // Inbound mail reopens every non-closed ticket to Open. Follow the
            // project-aware lock order so a late email cannot reopen a child of
            // a completed or archived project.
            $locked_ticket = runbookLockTicketForReopen($ticket_id);
            if (intval($locked_ticket['ticket_client_id']) !== $client_id) {
                throw new RuntimeException('The ticket client changed while the inbound reply was processed');
            }
            $ticket_status = intval($locked_ticket['ticket_status']);

            $insert_reply = mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$message_esc', ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $ticket_reply_contact, ticket_reply_ticket_id = $ticket_id");
            if (!$insert_reply) {
                throw new RuntimeException('Could not save the inbound ticket reply');
            }
            $reply_id = mysqli_insert_id($mysqli);

            $needs_reopen = $ticket_status !== 2 || !empty($locked_ticket['ticket_resolved_at']);
            if ($needs_reopen) {
                $resolved_at_predicate = empty($locked_ticket['ticket_resolved_at'])
                    ? 'ticket_resolved_at IS NULL'
                    : "ticket_resolved_at = '" . escapeSql($locked_ticket['ticket_resolved_at']) . "'";
                $reopen_sql = mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL
                    WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
                    AND ticket_deleted_at IS NULL
                    AND ticket_status = $ticket_status AND $resolved_at_predicate
                    AND ticket_closed_at IS NULL LIMIT 1");
                if (!$reopen_sql || mysqli_affected_rows($mysqli) !== 1) {
                    throw new RuntimeException('The ticket changed before the inbound reply could reopen it');
                }
                ticketOperationalOnReopened($ticket_id, intval($ticket_reply_contact), 'email');
            }
            // A customer response is inbound evidence, not fulfillment of the
            // provider's promise to send the next customer update.
            ticketEmailIngressComplete($ingress_id, $ingress_token, 'Processed', $ticket_id,
                intval($reply_id), null, $client_id);

            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the inbound ticket reply');
            }
            $email_parser_last_ticket_id = $ticket_id;
            $email_parser_last_reply_id = intval($reply_id);
            $email_parser_ingress_finalized = true;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            logApp('Cron-Email-Parser', 'warning', "Inbound reply for ticket $ticket_id failed closed: " . escapeSql($e->getMessage()));
            return false;
        }

        $ticket_dir = "../uploads/tickets/" . $ticket_id . "/";
        mkdirMissing($ticket_dir);

        foreach ($attachments as $attachment) {
            $att_name = $attachment['name'];
            $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));
            $att_mime = (string) ($attachment['mime'] ?? 'application/octet-stream');

            if (ticketEmailAttachmentAllowed($att_name, $att_mime, strlen($attachment['content']), $attachment['content'])) {
                $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
                $att_saved_path = $ticket_dir . $att_saved_filename;
                file_put_contents($att_saved_path, $attachment['content']);

                $ticket_attachment_name = escapeSql($att_name);
                $ticket_attachment_reference_name = escapeSql($att_saved_filename);

                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
                $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
                mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_reply_id = $reply_id, ticket_attachment_ticket_id = $ticket_id");
            } else {
                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
                logAudit("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $from_email_esc for ticket $config_ticket_prefix$ticket_number_esc", $client_id, $ticket_id);
            }
        }

        $ticket_assigned_to_sql = mysqli_query($mysqli, "SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1");
        if ($ticket_assigned_to_sql) {
            $row3 = mysqli_fetch_assoc($ticket_assigned_to_sql);
            $ticket_assigned_to = intval($row3['ticket_assigned_to']);

            if ($ticket_assigned_to) {
                $tech_sql = mysqli_query($mysqli, "SELECT user_email, user_name FROM users WHERE user_id = $ticket_assigned_to LIMIT 1");
                $tech_row = mysqli_fetch_assoc($tech_sql);
                $tech_email = escapeSql($tech_row['user_email']);
                $tech_name = escapeSql($tech_row['user_name']);

                $email_subject = "$config_app_name - Ticket updated - [$config_ticket_prefix$ticket_number] $ticket_subject";
                $email_body    = "Hello $tech_name,<br><br>A new reply has been added to the below ticket.<br><br>Client: $client_name<br>Ticket: $config_ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri<br><br>--------------------------------<br>$message_esc";

                $data = [
                    [
                        'from' => $config_ticket_from_email,
                        'from_name' => $config_ticket_from_name,
                        'recipient' => $tech_email,
                        'recipient_name' => $tech_name,
                        'subject' => mysqli_real_escape_string($mysqli, $email_subject),
                        'body' => mysqli_real_escape_string($mysqli, $email_body)
                    ]
                ];
                addToMailQueue($data);
            }
        }

        resetTicketResolutionSla($ticket_id);

        // Only record the reopen when the ticket was not already open
        if (intval($ticket_status) !== 2) {
            logTicketHistory($ticket_id, "$from_email_esc replied by email, reopening the ticket");
        }

        logAudit("Ticket", "Edit", "Email parser: Client contact $from_email_esc updated ticket $config_ticket_prefix$ticket_number_esc ($subject)", $client_id, $ticket_id);
        triggerCustomAction('ticket_reply_client', $ticket_id);
        return true;
    } else {
        $email_parser_explicit_reply_rejected = true;
        appNotify('Ticket', "Email parser rejected a reply to unavailable ticket $config_ticket_prefix$ticket_number", '', 0);
        logApp('Cron-Email-Parser', 'warning', "Rejected reply to unavailable ticket $config_ticket_prefix$ticket_number");
        return false;
    }
}

/** ------------------------------------------------------------------
 * OAuth helpers + provider guard
 * ------------------------------------------------------------------ */

// returns true if expires_at ('Y-m-d H:i:s') is in the past (or missing)
function tokenExpired(?string $expires_at): bool {
    if (empty($expires_at)) return true;
    $ts = strtotime($expires_at);
    if ($ts === false) return true;
    // refresh a little early (60s) to avoid race
    return ($ts - 60) <= time();
}

// very small form-encoded POST helper using curl
/**
 * Get a valid access token for Google Workspace IMAP via refresh token if needed.
 * Uses settings: config_mail_oauth_client_id / _client_secret / _refresh_token / _access_token / _access_token_expires_at
 * Updates globals if refreshed (so later logging can reflect it if you want to persist).
 */
function getGoogleAccessToken(string $username): ?string {
    // pull from global settings variables you already load
    global $mysqli,
           $config_mail_oauth_client_id,
           $config_mail_oauth_client_secret,
           $config_mail_oauth_refresh_token,
           $config_mail_oauth_access_token,
           $config_mail_oauth_access_token_expires_at;

    // If we have a not-expired token, use it
    if (!empty($config_mail_oauth_access_token) && !tokenExpired($config_mail_oauth_access_token_expires_at)) {
        return $config_mail_oauth_access_token;
    }

    // Need to refresh?
    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_refresh_token)) {
        // Nothing we can do
        return null;
    }

    $resp = httpFormPost(
        'https://oauth2.googleapis.com/token',
        [
            'client_id'     => $config_mail_oauth_client_id,
            'client_secret' => $config_mail_oauth_client_secret,
            'refresh_token' => $config_mail_oauth_refresh_token,
            'grant_type'    => 'refresh_token',
        ]
    );

    if (!$resp['ok']) return null;

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) return null;

    // Calculate new expiry
    $expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

    // Update in-memory globals (and persist to DB)
    $config_mail_oauth_access_token = $json['access_token'];
    $config_mail_oauth_access_token_expires_at = $expires_at;

    $at_esc  = mysqli_real_escape_string($mysqli, $config_mail_oauth_access_token);
    $exp_esc = mysqli_real_escape_string($mysqli, $config_mail_oauth_access_token_expires_at);
    mysqli_query($mysqli, "UPDATE settings SET
        config_mail_oauth_access_token = '{$at_esc}',
        config_mail_oauth_access_token_expires_at = '{$exp_esc}'
        WHERE company_id = 1
    ");

    return $config_mail_oauth_access_token;
}

/**
 * Get a valid access token for Microsoft 365 IMAP via refresh token if needed.
 * Uses settings: config_mail_oauth_client_id / _client_secret / _tenant_id / _refresh_token / _access_token / _access_token_expires_at
 */
function getMicrosoftAccessToken(string $username): ?string {
    global $mysqli,
           $config_mail_oauth_client_id,
           $config_mail_oauth_client_secret,
           $config_mail_oauth_tenant_id,
           $config_mail_oauth_refresh_token,
           $config_mail_oauth_access_token,
           $config_mail_oauth_access_token_expires_at;

    if (!empty($config_mail_oauth_access_token) && !tokenExpired($config_mail_oauth_access_token_expires_at)) {
        return $config_mail_oauth_access_token;
    }

    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_refresh_token) || empty($config_mail_oauth_tenant_id)) {
        return null;
    }

    $url = "https://login.microsoftonline.com/".rawurlencode($config_mail_oauth_tenant_id)."/oauth2/v2.0/token";

    $resp = httpFormPost($url, [
        'client_id'     => $config_mail_oauth_client_id,
        'client_secret' => $config_mail_oauth_client_secret,
        'refresh_token' => $config_mail_oauth_refresh_token,
        'grant_type'    => 'refresh_token',
        // IMAP/SMTP scopes typically included at initial consent; not needed for refresh
    ]);

    if (!$resp['ok']) return null;

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) return null;

    $expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

    $config_mail_oauth_access_token = $json['access_token'];
    $config_mail_oauth_access_token_expires_at = $expires_at;

    $at_esc  = mysqli_real_escape_string($mysqli, $config_mail_oauth_access_token);
    $exp_esc = mysqli_real_escape_string($mysqli, $config_mail_oauth_access_token_expires_at);
    mysqli_query($mysqli, "UPDATE settings SET
        config_mail_oauth_access_token = '{$at_esc}',
        config_mail_oauth_access_token_expires_at = '{$exp_esc}'
        WHERE company_id = 1
    ");

    return $config_mail_oauth_access_token;
}

// Provider from settings (may be NULL/empty to disable IMAP polling)
$imap_provider = $config_imap_provider ?? '';
if ($imap_provider === null) $imap_provider = '';

if ($imap_provider === '') {
    // IMAP disabled by admin: exit cleanly
    logApp("Cron-Email-Parser", "info", "IMAP polling skipped: provider not configured.");
    cronJobStop();
}

/** ------------------------------------------------------------------
 * ImapEngine setup (supports Standard / Google OAuth / Microsoft OAuth)
 * ------------------------------------------------------------------ */
use DirectoryTree\ImapEngine\Mailbox;

$validate_cert = true;

// Defaults from settings (standard IMAP)
$host = $config_imap_host;
$port = (int)$config_imap_port;
$encr = !empty($config_imap_encryption) ? $config_imap_encryption : 'notls'; // 'ssl'|'tls'|'notls'
$user = $config_imap_username;
$pass = $config_imap_password;
$auth = 'plain'; // 'oauth' for OAuth providers

if ($imap_provider === 'google_oauth') {
    $host = 'imap.gmail.com';
    $port = 993;
    $encr = 'ssl';
    $auth = 'oauth';
    $pass = getGoogleAccessToken($user);
    if (empty($pass)) {
        logApp("Cron-Email-Parser", "error", "Google OAuth: no usable access token (check refresh token/client credentials).");
        cronJobStop('', 1);
    }
} elseif ($imap_provider === 'microsoft_oauth') {
    $host = 'outlook.office365.com';
    $port = 993;
    $encr = 'ssl';
    $auth = 'oauth';
    $pass = getMicrosoftAccessToken($user);
    if (empty($pass)) {
        logApp("Cron-Email-Parser", "error", "Microsoft OAuth: no usable access token (check refresh token/client credentials/tenant).");
        cronJobStop('', 1);
    }
} else {
    // standard_imap (username/password)
    if (empty($host) || empty($port) || empty($user)) {
        logApp("Cron-Email-Parser", "error", "Standard IMAP: missing host/port/username.");
        cronJobStop('', 1);
    }
}

// Map Webklex-style encryption values to ImapEngine transports
// Webklex 'tls' = STARTTLS (port 143), ImapEngine 'tls' = implicit TLS, so translate
$encryption = match (strtolower($encr)) {
    'ssl'      => 'ssl',      // implicit TLS (993)
    'tls'      => 'starttls', // STARTTLS upgrade (143) - Webklex semantics
    'starttls' => 'starttls',
    default    => '',         // 'notls' / plain tcp
};

$mailbox = new Mailbox([
    'host'           => $host,
    'port'           => $port,
    'encryption'     => $encryption,
    'validate_cert'  => (bool)$validate_cert,
    'username'       => $user,            // full mailbox address (OAuth uses user as principal)
    'password'       => $pass,            // access token when $auth === 'oauth'
    'authentication' => $auth,            // 'oauth' or 'plain'
]);

try {
    $mailbox->connect();
} catch (\Throwable $e) {
    echo "Error connecting to IMAP server: " . $e->getMessage();
    cronJobStop('', 1);
}

$inbox = $mailbox->inbox();

// Resolve the processed-mail folder in a namespace-aware way.
// Most servers (Gmail, M365, Dovecot with an empty namespace prefix) allow
// root-level folders, so "ITFlow" is preferred. cPanel-style Dovecot uses a
// Maildir++ layout where the personal namespace prefix is "INBOX." - root
// CREATE fails there with "Client tried to access nonexistent namespace",
// and the folder must be addressed as "INBOX.ITFlow" (clients still display
// it as a top-level folder alongside Inbox).
$targetFolderName = 'ITFlow';
try {
    // INBOX always exists (RFC 3501) - use it to learn the hierarchy delimiter
    // (ImapEngine returns the literal string "NIL" for servers with a flat namespace)
    $delimiter = $mailbox->folders()->findOrFail('INBOX')->delimiter();
    if ($delimiter === '' || strcasecmp($delimiter, 'NIL') === 0) {
        $delimiter = '.';
    }

    // Candidate paths, in order of preference
    $candidates = [
        $targetFolderName,                              // Root-level (empty namespace prefix)
        'INBOX' . $delimiter . $targetFolderName,       // INBOX-prefixed namespace (e.g. cPanel Dovecot)
    ];

    // Re-use the folder if it already exists at either location
    $targetFolder = null;
    foreach ($candidates as $candidate) {
        if ($targetFolder = $mailbox->folders()->find($candidate)) {
            $targetFolderPath = $candidate;
            break;
        }
    }

    // Otherwise create it, falling back to the namespace-prefixed path if the
    // server rejects root-level creation
    if (!$targetFolder) {
        $creation_errors = [];
        foreach ($candidates as $candidate) {
            try {
                $targetFolder = $mailbox->folders()->create($candidate);
                $targetFolderPath = $candidate;
                break;
            } catch (\Throwable $e) {
                $creation_errors[] = "[$candidate]: " . $e->getMessage();
            }
        }
        if (!$targetFolder) {
            throw new \Exception("CREATE rejected for all candidate paths - " . implode(' / ', $creation_errors));
        }

        // Subscribe to the newly created folder so it's visible in mail clients
        // that only display subscribed folders (common with Dovecot/Roundcube).
        // Non-fatal - some servers ignore or reject SUBSCRIBE entirely.
        try {
            $mailbox->connection()->subscribe(
                \DirectoryTree\ImapEngine\Support\Str::toImapUtf7($targetFolderPath)
            );
        } catch (\Throwable $e) {
            logApp("Cron-Email-Parser", "warning", "Created folder [$targetFolderPath] but could not subscribe to it: " . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    logApp("Cron-Email-Parser", "error", "Unable to find/create target folder [$targetFolderName]: " . $e->getMessage());
    cronJobStop('', 1);
}

// Fetch unseen messages (headers, body & flags; BODY.PEEK so they stay unread)
// unflagged() skips messages a previous run flagged for manual attention,
// so they aren't pointlessly re-processed every run forever
$messages = $inbox->messages()
    ->withHeaders()
    ->withBody()
    ->withFlags()
    ->leaveUnread()
    ->unseen()
    ->unflagged()
    ->limit($max_emails_per_run)
    ->get();

// Counters
$processed_count = 0;
$unprocessed_count = 0;

// Process messages
foreach ($messages as $message) {
    $email_ingress_id = 0;
    $email_parser_ingress_token = '';
    try {
        $email_processed = false;
        $email_parser_last_ticket_id = 0;
        $email_parser_last_reply_id = 0;
        $email_parser_explicit_reply_rejected = false;
        $email_parser_ingress_finalized = false;
        $email_parser_rejection_reason = null;

        // From
        $from_addr  = $message->from(); // ?Address
        $from_email_raw = strtolower(trim((string) ($from_addr?->email() ?: '')));
        if (!filter_var($from_email_raw, FILTER_VALIDATE_EMAIL)) {
            $from_email_raw = 'itflow-guest@example.com';
        }
        $from_email = escapeSql($from_email_raw);
        $from_name_raw = trim((string) ($from_addr?->name() ?: 'Unknown'));
        $from_name = escapeSql($from_name_raw);

        $from_domain_parts = explode('@', $from_email_raw);
        $from_domain_raw = strtolower((string) end($from_domain_parts));
        $from_domain = escapeSql($from_domain_raw);

        // Subject
        $subject_raw = trim((string) $message->subject()) ?: 'No Subject';
        $subject = escapeSql($subject_raw);

        $dateObj = $message->date(); // ?CarbonInterface
        $date = escapeSql($dateObj ? $dateObj->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'));
        $raw_message = (string) $message;
        $message_hash = ticketEmailIngressFingerprint(
            (string) ($message->messageId() ?? ''),
            $from_email_raw,
            $subject_raw,
            $date,
            hash('sha256', $raw_message)
        );
        $claim = ticketEmailIngressClaim($message_hash, $from_email_raw, $subject_raw);
        $email_ingress_id = intval($claim['id']);
        $email_parser_ingress_token = (string) ($claim['token'] ?? '');
        if (!$claim['claimed']) {
            logApp('Cron-Email-Parser', 'info', "Skipped duplicate inbound message $message_hash ({$claim['status']})");
            if (in_array((string) $claim['status'], ['Processed', 'Rejected'], true)) {
                $processed_count++;
                $message->markSeen();
                $message->move($targetFolderPath);
            }
            continue;
        }

        if (($rate_limit = ticketEmailIngressRateLimitReason(
            $email_ingress_id,
            $email_parser_ingress_token
        )) !== null) {
            ticketEmailIngressComplete($email_ingress_id, $email_parser_ingress_token,
                'Rejected', 0, 0, $rate_limit);
            appNotify('Mail', "Email parser rate-limited inbound mail from $from_email. Subject: $subject", '', 0);
            $processed_count++;
            $message->markSeen();
            $message->move($targetFolderPath);
            continue;
        }

        if (strlen($raw_message) > $max_raw_message_bytes) {
            ticketEmailIngressComplete($email_ingress_id, $email_parser_ingress_token,
                'Rejected', 0, 0, 'message_too_large');
            appNotify('Mail', "Email parser rejected an oversized message from $from_email. Subject: $subject", '', 0);
            $processed_count++;
            $message->markSeen();
            $message->move($targetFolderPath);
            continue;
        }

        // Skip vacation/out-of-office auto-responders to prevent mail loops (RFC 3834)
        // NDRs use auto-generated and are still handled by the NDR logic below.
        $auto_submitted = strtolower((string)($message->header('Auto-Submitted')?->getValue() ?? ''));
        $precedence     = strtolower((string)($message->header('Precedence')?->getValue() ?? ''));
        $list_id = trim((string) ($message->header('List-Id')?->getValue() ?? ''));
        $auto_suppress = strtolower((string) ($message->header('X-Auto-Response-Suppress')?->getValue() ?? ''));
        $trusted_sender = ticketEmailTrustedAuthentication(
            $raw_message,
            (string) $imap_provider,
            (string) $host,
            $from_domain_raw
        );
        if (str_starts_with($auto_submitted, 'auto-replied')
            || in_array($precedence, ['auto_reply', 'bulk', 'list', 'junk'], true)
            || $list_id !== '' || str_contains($auto_suppress, 'all')) {
            logApp("Cron-Email-Parser", "info", "Email parser skipped auto-responder from $from_email ($subject)");
            appNotify(
                'Mail',
                "Email parser: Skipped auto-responder or list message from $from_email. Subject: $subject",
                '',
                0
            );
            ticketEmailIngressComplete($email_ingress_id, $email_parser_ingress_token,
                'Rejected', 0, 0, 'automated_message');
            $processed_count++;
            $message->markSeen();
            $message->move($targetFolderPath);
            continue;
        }

        // CC (deduplicated, excluding the sender)
        $ccs = array();
        foreach ($message->cc() as $cc_addr) {
            $cc_mail = strtolower($cc_addr->email());
            if ($cc_mail && $cc_mail !== $from_email_raw && !in_array($cc_mail, $ccs, true)) {
                $ccs[] = $cc_mail;
            }
        }

        // Body (prefer HTML)
        $message_body_html = $message->html();
        $message_body_text = $message->text();

        if (!empty($message_body_html)) {
            $message_body = $message_body_html;
        } elseif (!empty($message_body_text)) {
            $message_body = nl2br(htmlspecialchars($message_body_text));
        } else {
            // Final fallback - raw body
            $message_body = nl2br(htmlspecialchars($message->body()));
        }

        // Handle attachments (inline vs regular)
        $attachments = [];
        foreach ($message->attachments() as $att) {
            $dispo   = strtolower((string)$att->contentDisposition());
            $cid     = $att->contentId();               // Content-ID (without <>)
            $content = $att->contents();                // binary
            $mime    = $att->contentType();
            $name    = $att->filename() ?: 'attachment';
            $size    = strlen($content);

            // Skip oversized attachments entirely
            if ($size > $max_attachment_bytes) {
                logApp("Cron-Email-Parser", "warning", "Email parser skipped oversized attachment " . escapeSql($name) . " (" . round($size / 1048576, 1) . " MB) from $from_email ($subject)");
                continue;
            }

            // Embed small inline images as data URIs; oversized inline images fall through and are saved as regular attachments
            $is_inline = false;
            $inline_mime = strtolower(trim(explode(';', (string) $mime)[0]));
            if ($dispo === 'inline' && $cid && $content !== ''
                && $size <= $max_inline_embed_bytes
                && in_array($inline_mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                $cid_trim  = trim($cid, '<>');
                $dataUri   = "data:$mime;base64,".base64_encode($content);
                $message_body = str_replace(["cid:$cid_trim", "cid:<$cid_trim>"], $dataUri, $message_body);
                $is_inline = true;
            }

            if (!$is_inline && $content !== '') {
                $attachments[] = ['name' => basename($name), 'mime' => (string) $mime, 'content' => $content];
            }
        }
        $message_body = ticketEmailSanitizeInboundHtml((string) $message_body);
        $bad_from_pattern = "/daemon|postmaster|bounce|mta/i";
        $is_ndr_sender = preg_match($bad_from_pattern, $from_email_raw) === 1;

        // 1. Reply to existing ticket with the number in subject
        if (!$is_ndr_sender
            && preg_match("/\[" . preg_quote($config_ticket_prefix, '/') . "(\d+)\]/", $subject_raw, $ticket_number_matches)) {
            $ticket_number = intval($ticket_number_matches[1]);
            $email_processed = addReply($from_email_raw, $date, $subject_raw, $ticket_number,
                $message_body, $attachments, $trusted_sender, $email_ingress_id,
                $email_parser_ingress_token);
        }

        // 2. A known, registered contact? Subject similarity is deliberately
        // not a reply key; only an explicit ticket reference may append mail.
        if (!$email_processed && !$email_parser_explicit_reply_rejected && !$is_ndr_sender) {
            $from_email_esc = mysqli_real_escape_string($mysqli, $from_email_raw);
            $any_contact_sql = mysqli_query($mysqli, "SELECT * FROM contacts
                WHERE LOWER(contact_email) = '$from_email_esc' AND contact_archived_at IS NULL
                AND (SELECT COUNT(DISTINCT contact_client_id) FROM contacts matching_contacts
                    WHERE LOWER(matching_contacts.contact_email) = '$from_email_esc'
                    AND matching_contacts.contact_archived_at IS NULL) = 1
                ORDER BY contact_id LIMIT 1");
            $rowc = mysqli_fetch_assoc($any_contact_sql);

            if ($rowc) {
                $contact_name  = (string) $rowc['contact_name'];
                $contact_id    = intval($rowc['contact_id']);
                $contact_email = (string) $rowc['contact_email'];
                $client_id     = intval($rowc['contact_client_id']);

                if (!$trusted_sender) {
                    $email_parser_explicit_reply_rejected = true;
                    appNotify('Mail', "Email parser rejected unauthenticated tenant intake from $from_email. Subject: $subject", '', $client_id);
                    logApp('Cron-Email-Parser', 'warning', "Rejected unauthenticated tenant sender $from_email");
                } else {
                    $email_processed = addTicket($contact_id, $contact_name, $contact_email,
                        $client_id, $date, $subject_raw, $message_body, $attachments,
                        $raw_message, $ccs, true, $email_ingress_id,
                        $email_parser_ingress_token);
                }
            }
        }

        // 4. A known domain?
        if (!$email_processed && !$email_parser_explicit_reply_rejected && !$is_ndr_sender) {
            $from_domain_esc = mysqli_real_escape_string($mysqli, $from_domain_raw);
            $domain_sql = mysqli_query($mysqli, "SELECT domain_client_id, domain_name FROM domains
                INNER JOIN clients ON client_id = domain_client_id
                WHERE LOWER(domain_name) = '$from_domain_esc' AND domain_archived_at IS NULL
                AND client_archived_at IS NULL AND client_lead = 0 LIMIT 1");
            $rowd = mysqli_fetch_assoc($domain_sql);

            if ($rowd && strcasecmp($from_domain_raw, (string) $rowd['domain_name']) === 0
                && $trusted_sender) {
                $client_id = intval($rowd['domain_client_id']);

                // addTicket creates the authenticated domain contact and ticket
                // in one transaction so a failed intake cannot leave an orphan.
                $email_processed = addTicket(0, $from_name_raw, $from_email_raw, $client_id,
                    $date, $subject_raw, $message_body, $attachments, $raw_message,
                    $ccs, true, $email_ingress_id, $email_parser_ingress_token);
            } elseif ($rowd && strcasecmp($from_domain_raw, (string) $rowd['domain_name']) === 0) {
                $email_parser_explicit_reply_rejected = true;
                appNotify('Mail', "Email parser rejected unauthenticated tenant-domain intake from $from_email. Subject: $subject", '', intval($rowd['domain_client_id']));
                logApp('Cron-Email-Parser', 'warning', "Rejected unauthenticated tenant domain $from_domain");
            }
        }

        // 5. Unknown sender allowed?
        if (!$email_processed && !$email_parser_explicit_reply_rejected && $config_ticket_email_parse_unknown_senders) {

            if (!preg_match($bad_from_pattern, $from_email_raw)) {
                $email_processed = addTicket(0, $from_name_raw, $from_email_raw, 0,
                    $date, $subject_raw, $message_body, $attachments, $raw_message,
                    $ccs, $trusted_sender, $email_ingress_id, $email_parser_ingress_token);

            } else {

                // Probably an NDR message without a ticket ref in the subject

                $structured_dsn = null;
                $original_subject = null;
                $original_message_id = null;

                // Only a standards-structured delivery-status part is treated
                // as an NDR. Human-readable regexes are notification content,
                // never authority to append or reopen a ticket.
                foreach ($message->parse()->getAllParts() as $part) {
                    $ctype = strtolower((string)$part->getContentType());
                    $body  = $part->getContent() ?? '';
                    if (strpos($ctype, 'delivery-status') !== false && $structured_dsn === null) {
                        $structured_dsn = ticketEmailStructuredDsn((string) $body);
                    }
                    if (strpos($ctype, 'message/rfc822') !== false) {
                        if (preg_match('/^Subject:\s*(.+)$/mi', $body, $m)) {
                            $original_subject = trim($m[1]);
                        }
                        if (preg_match('/^Message-ID:\s*<([^>]+)>/mi', $body, $m)) {
                            $original_message_id = strtolower(trim($m[1]));
                        }
                    }
                }

                if ($structured_dsn !== null) {
                    $failed_recipient = escapeSql($structured_dsn['recipient']);
                    $status_code = escapeSql($structured_dsn['status']);
                    $diagnostic_code = escapeSql($structured_dsn['diagnostic']);
                    $original_subject_safe = escapeSql($original_subject ?: $subject_raw);
                    $original_message_id_safe = escapeSql(substr((string) preg_replace(
                        '/[^a-z0-9@._+\/-]/i', '', (string) $original_message_id
                    ), 0, 255));
                    $correlation = $original_message_id
                        ? "Outbound Message-ID $original_message_id_safe was not found in a signed outbound ledger."
                        : 'The NDR did not contain an outbound Message-ID.';
                    appNotify('Ticket',
                        "Email parser: uncorrelated structured NDR for $failed_recipient. Subject: $original_subject_safe. Diagnostics: $status_code / $diagnostic_code. $correlation Check the ITFlow mail folder manually; no ticket was changed.",
                        '', 0);
                } else {
                    appNotify('Mail',
                        "Email parser: suspected bounce from $from_email did not contain a valid structured DSN. Subject: $subject. No ticket was changed.",
                        '', 0);
                }
                $email_processed = true;
            }
        }


        // Flag/move based on processing result
        if ($email_processed) {
            if (!$email_parser_ingress_finalized) {
                // Paths without a ticket mutation (for example a closed-ticket
                // notice or uncorrelated NDR) can finish outside a transaction.
                // Ticket/reply paths finalize inside their creation transaction.
                ticketEmailIngressComplete($email_ingress_id, $email_parser_ingress_token, 'Processed');
                $email_parser_ingress_finalized = true;
            }
            $processed_count++; // increment first so a move failure doesn't hide the success
            try {
                $message->markSeen();
                // Move to the top-level "ITFlow" folder
                $message->move($targetFolderPath);
                // optional: logApp("Cron-Email-Parser", "info", "Moved message to ITFlow");
            } catch (\Throwable $e) {
                $subj = (string)$message->subject();
                $uid  = $message->uid();
                $path = $targetFolder->path();
                logApp(
                    "Cron-Email-Parser",
                    "warning",
                    "Move failed (subject=\"$subj\", uid=$uid) to [$path]: ".$e->getMessage()
                );
            }
        } else {
            ticketEmailIngressComplete(
                $email_ingress_id,
                $email_parser_ingress_token,
                'Rejected',
                intval($email_parser_last_ticket_id),
                intval($email_parser_last_reply_id),
                $email_parser_rejection_reason
                    ?? ($email_parser_explicit_reply_rejected ? 'unauthorized_ticket_reply' : 'unknown_sender')
            );
            $unprocessed_count++;
            try {
                $message->markFlagged();
                $message->unmarkSeen();
            } catch (\Throwable $e) {
                logApp("Cron-Email-Parser", "warning", "Flag update failed: ".$e->getMessage());
            }
        }

    } catch (\Throwable $e) {
        if ($email_ingress_id > 0) {
            try {
                ticketEmailIngressComplete($email_ingress_id, $email_parser_ingress_token,
                    'Failed', 0, 0, 'processing_error');
            } catch (\Throwable $ledger_exception) {
                logApp('Cron-Email-Parser', 'warning', 'Could not record inbound failure: ' . $ledger_exception->getMessage());
            }
        }
        // One bad message must not kill the whole run - flag it for manual attention and continue
        $unprocessed_count++;
        logApp("Cron-Email-Parser", "warning", "Email parser failed to process message UID " . $message->uid() . ": " . $e->getMessage());
        try {
            $message->markFlagged();
            $message->unmarkSeen();
        } catch (\Throwable $e2) {
            // ignore
        }
    }

}

// Expunge & disconnect
try {
    $inbox->expunge();
} catch (\Throwable $e) {
    // ignore
}
$mailbox->disconnect();

// Execution timing (optional)
$script_end_time = microtime(true);
$execution_time = $script_end_time - $script_start_time;
$execution_time_formatted = number_format($execution_time, 2);

$processed_info = "Processed: $processed_count email(s), Unprocessed: $unprocessed_count email(s)";
if ($processed_count || $unprocessed_count) {
    logApp("Cron-Email-Parser", "info", "Cron Email Parser executed in $execution_time_formatted seconds. $processed_info");
}

// DEBUG
echo "Processed Emails: $processed_count\n";
echo "Unprocessed Emails: $unprocessed_count\n";
