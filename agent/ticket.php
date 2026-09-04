<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
}

// Tickets with no client stay visible to restricted agents - clientScopeSql() includes 0
$access_permission_query_overide = clientScopeSql('ticket_client_id');

// Perms
enforceUserPermission('module_support');

// Initialize the HTML Purifier to prevent XSS
require_once "../libs/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

if (isset($_GET['ticket_id'])) {
    $ticket_id = intval($_GET['ticket_id']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        LEFT JOIN locations ON ticket_location_id = location_id
        LEFT JOIN assets ON ticket_asset_id = asset_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        LEFT JOIN vendors ON ticket_vendor_id = vendor_id
        LEFT JOIN projects ON ticket_project_id = project_id
        LEFT JOIN quotes ON ticket_quote_id = quote_id
        LEFT JOIN invoices ON ticket_invoice_id = invoice_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN categories ON ticket_category = category_id
        WHERE ticket_id = $ticket_id
        $access_permission_query_overide
        LIMIT 1"
    );

    if (mysqli_num_rows($sql) == 0) {
        echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='tickets.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";

        require_once "../includes/footer.php";
    } else {

        $ticket = mysqli_fetch_assoc($sql);

        $client_id = intval($ticket['client_id']);
        $client_name = escapeHtml($ticket['client_name']);

        $client_net_terms = intval($ticket['client_net_terms']);
        if ($client_net_terms == 0) {
            $client_net_terms = $config_default_net_terms;
        }

        $ticket_prefix = escapeHtml($ticket['ticket_prefix']);
        $ticket_number = intval($ticket['ticket_number']);
        $ticket_reference = "$ticket_prefix$ticket_number";
        $ticket_source = escapeHtml($ticket['ticket_source']);
        $ticket_category = intval($ticket['ticket_category']);
        $ticket_category_display = escapeHtml($ticket['category_name']);
        $ticket_subject = escapeHtml($ticket['ticket_subject']);
        $ticket_details = $purifier->purify($ticket['ticket_details']);
        $ticket_priority = escapeHtml($ticket['ticket_priority']);
        $ticket_billable = intval($ticket['ticket_billable']);
        $ticket_scheduled_for = escapeHtml($ticket['ticket_schedule']);
        $ticket_onsite = intval($ticket['ticket_onsite']);
        $ticket_configuration_change = intval($ticket['ticket_configuration_change']);
        $ticket_documentation_impact = escapeHtml($ticket['ticket_documentation_impact']);
        $ticket_documentation_assessed_at = escapeHtml($ticket['ticket_documentation_assessed_at']);
        if ($ticket_scheduled_for) {
            $ticket_scheduled_wording = date('M d, Y • g:i A', strtotime($ticket_scheduled_for));
        } else {
            $ticket_scheduled_wording = "Not scheduled";
        }

        // Set Ticket Badge Color based of priority
        if ($ticket_priority == "Urgent") {
            $ticket_priority_color = "dark";
        } elseif ($ticket_priority == "High") {
            $ticket_priority_color = "danger";
        } elseif ($ticket_priority == "Medium") {
            $ticket_priority_color = "warning";
        } else {
            $ticket_priority_color = "info";
        }
        $ticket_feedback = escapeHtml($ticket['ticket_feedback']);

        $ticket_status = intval($ticket['ticket_status_id']);
        $ticket_status_id = intval($ticket['ticket_status_id']);
        $ticket_status_name = escapeHtml($ticket['ticket_status_name']);
        $ticket_status_color = escapeHtml($ticket['ticket_status_color']);

        $ticket_vendor_ticket_number = escapeHtml($ticket['ticket_vendor_ticket_number']);
        $ticket_created_at = escapeHtml($ticket['ticket_created_at']);
        $ticket_created_at_ago = timeAgo($ticket['ticket_created_at']);
        $ticket_created_by = intval($ticket['ticket_created_by']);
        $ticket_date = date('Y-m-d', strtotime($ticket_created_at));
        $ticket_updated_at = escapeHtml($ticket['ticket_updated_at']);
        $ticket_updated_at_ago = timeAgo($ticket['ticket_updated_at']);
        $ticket_first_response_at = escapeHtml($ticket['ticket_first_response_at']);
        $ticket_sla_id = intval($ticket['ticket_sla_id']);
        $ticket_response_due_at = escapeHtml($ticket['ticket_response_due_at']);
        $ticket_resolution_due_at = escapeHtml($ticket['ticket_resolution_due_at']);
        $ticket_response_sla_met = $ticket['ticket_response_sla_met'];
        $ticket_resolution_sla_met = $ticket['ticket_resolution_sla_met'];
        $ticket_sla_paused = intval($ticket['ticket_status_pauses_sla']);
        $ticket_sla_name = "None";
        if ($ticket_sla_id) {
            $sla_name_sql = mysqli_query($mysqli, "SELECT sla_name FROM slas WHERE sla_id = $ticket_sla_id");
            if (mysqli_num_rows($sla_name_sql)) {
                $ticket_sla_name = escapeHtml(mysqli_fetch_assoc($sla_name_sql)['sla_name']);
            }
        }
        $ticket_agreement_decision_sql = mysqli_query($mysqli, "SELECT
            ticket_agreement_decisions.*, agreement_version_number, agreement_version_name
            FROM ticket_agreement_decisions
            LEFT JOIN agreement_versions
                ON agreement_version_id = ticket_agreement_decision_version_id
                AND agreement_version_contract_id = ticket_agreement_decision_contract_id
            WHERE ticket_agreement_decision_ticket_id = $ticket_id
            AND ticket_agreement_decision_client_id = $client_id
            ORDER BY ticket_agreement_decision_id DESC LIMIT 1");
        $ticket_agreement_decision = $ticket_agreement_decision_sql
            ? mysqli_fetch_assoc($ticket_agreement_decision_sql) : null;
        $ticket_agreement_decision_integrity_failed = false;
        $ticket_entitlement_evidence = null;
        if ($ticket_agreement_decision && !agreementVerifyTicketDecision($ticket_agreement_decision)) {
            $ticket_agreement_decision_integrity_failed = true;
            error_log("Ticket $ticket_id SLA decision hash failed verification");
            $ticket_agreement_decision = null;
        } elseif ($ticket_agreement_decision) {
            try {
                $ticket_entitlement_evidence = json_decode(
                    (string) $ticket_agreement_decision['ticket_agreement_decision_entitlement_snapshot'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable $e) {
                $ticket_agreement_decision_integrity_failed = true;
                $ticket_agreement_decision = null;
                error_log("Ticket $ticket_id SLA entitlement evidence could not be decoded");
            }
        }
        $ticket_resolved_at = escapeHtml($ticket['ticket_resolved_at']);
        $ticket_resolved_at_ago = timeAgo($ticket['ticket_resolved_at']);
        $ticket_closed_at = escapeHtml($ticket['ticket_closed_at']);
        $ticket_closed_at_ago = timeAgo($ticket['ticket_closed_at']);
        $ticket_closed_by = intval($ticket['ticket_closed_by']);

        // The three states the page keys its controls off
        $ticket_is_closed = !empty($ticket_closed_at);
        $ticket_is_resolved = !empty($ticket_resolved_at);
        $ticket_is_open = !$ticket_is_resolved && !$ticket_is_closed;
        $can_edit_ticket = lookupUserPermission("module_support") >= 2;

        $ticket_assigned_to = intval($ticket['ticket_assigned_to']);
        if (empty($ticket_assigned_to)) {
            $ticket_assigned_to_display = "<span class='text-danger'><i class='fas fa-fw fa-user-slash me-1'></i>Unassigned</span>";
        } else {
            $ticket_assigned_to_display = escapeHtml($ticket['user_name']);
        }

        // Tab Title // No Sanitizing needed
        $page_title = $ticket['ticket_subject'];
        $tab_title = "{$ticket['ticket_prefix']}{$ticket['ticket_number']}";

        $contact_id = intval($ticket['contact_id']);
        $contact_name = escapeHtml($ticket['contact_name']);
        $contact_title = escapeHtml($ticket['contact_title']);
        $contact_email = escapeHtml($ticket['contact_email']);
        $contact_phone_country_code = escapeHtml($ticket['contact_phone_country_code']);
        $contact_phone = escapeHtml(formatPhoneNumber($ticket['contact_phone'], $contact_phone_country_code));
        $contact_extension = escapeHtml($ticket['contact_extension']);
        $contact_mobile_country_code = escapeHtml($ticket['contact_mobile_country_code']);
        $contact_mobile = escapeHtml(formatPhoneNumber($ticket['contact_mobile'], $contact_mobile_country_code));

        $asset_id = intval($ticket['asset_id']);
        $asset_name = escapeHtml($ticket['asset_name']);
        $asset_type = escapeHtml($ticket['asset_type']);
        $asset_icon = getAssetIcon($asset_type);

        $vendor_id = intval($ticket['ticket_vendor_id']);
        $vendor_name = escapeHtml($ticket['vendor_name']);
        $vendor_contact_name = escapeHtml($ticket['vendor_contact_name']);
        $vendor_phone_country_code = escapeHtml($ticket['vendor_phone_country_code']);
        $vendor_phone = escapeHtml(formatPhoneNumber($ticket['vendor_phone'], $vendor_phone_country_code));
        $vendor_email = escapeHtml($ticket['vendor_email']);
        $vendor_website = escapeHtml($ticket['vendor_website']);

        $location_id = intval($ticket['location_id']);
        $location_name = escapeHtml($ticket['location_name']);
        $location_phone = formatPhoneNumber($ticket['location_phone']);

        $quote_id = intval($ticket['ticket_quote_id']);
        $quote_prefix = escapeHtml($ticket['quote_prefix']);
        $quote_number = intval($ticket['quote_number']);
        // The quote row may have been deleted out from under the ticket
        $quote_reference = $quote_number ? "$quote_prefix$quote_number" : "#$quote_id";

        $invoice_id = intval($ticket['ticket_invoice_id']);
        $invoice_prefix = escapeHtml($ticket['invoice_prefix']);
        $invoice_number = intval($ticket['invoice_number']);
        // Same for the invoice - fall back to the id rather than rendering a bare 0
        $invoice_reference = $invoice_number ? "$invoice_prefix$invoice_number" : "#$invoice_id";

        $project_id = intval($ticket['project_id']);
        $project_name = escapeHtml($ticket['project_name']);
        $project_manager = intval($ticket['project_manager']);
        $project_manager_name = '';
        if ($project_manager) {
            $project_manager_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $project_manager"));
            $project_manager_name = escapeHtml($project_manager_row['user_name']);
        }

        $ticket_created_by_display = '';
        if ($ticket_created_by) {
            $created_by_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $ticket_created_by"));
            $ticket_created_by_display = escapeHtml($created_by_row['user_name']);
        }

        $ticket_closed_by_display = '';
        if ($ticket_is_closed) {
            $ticket_closed_by_display = 'User';
            if (!empty($ticket_closed_by)) {
                $closed_by_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $ticket_closed_by"));
                $ticket_closed_by_display = escapeHtml($closed_by_row['user_name']);
            }
        }

        /*
         * How much else this contact has open. The old queries compared the
         * integer ticket_status column against the string 'Closed', which MySQL
         * casts to 0 - so "open" matched every ticket and "closed" matched none.
         * Resolution state lives in ticket_resolved_at, same as the ticket list.
         */
        $contact_open_tickets = 0;
        if ($contact_id) {
            $contact_open_tickets = intval(mysqli_fetch_row(mysqli_query(
                $mysqli,
                "SELECT COUNT(ticket_id) FROM tickets
                WHERE ticket_contact_id = $contact_id
                AND ticket_id != $ticket_id
                AND ticket_resolved_at IS NULL"
            ))[0]);
        }

        // Reply counts, one pass - these drive the conversation filter
        $reply_counts = array('total' => 0, 'public' => 0, 'internal' => 0);
        $sql_reply_counts = mysqli_query(
            $mysqli,
            "SELECT ticket_reply_type, COUNT(ticket_reply_id) AS reply_count
            FROM ticket_replies
            WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id
            GROUP BY ticket_reply_type"
        );
        while ($reply_count_row = mysqli_fetch_assoc($sql_reply_counts)) {
            $reply_count = intval($reply_count_row['reply_count']);
            $reply_counts['total'] += $reply_count;
            if ($reply_count_row['ticket_reply_type'] == 'Internal') {
                $reply_counts['internal'] += $reply_count;
            } else {
                $reply_counts['public'] += $reply_count;
            }
        }

        // Get Total Ticket Time
        $ticket_total_reply_time = escapeHtml(mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) AS ticket_total_reply_time
            FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id"
        ))['ticket_total_reply_time']);

        /*
         * Agents who have worked this ticket. The old query joined every reply's
         * ticket_reply_by against users, but a Client reply stores a CONTACT id
         * there - so any contact whose id collided with a user id showed up as a
         * collaborator. That is the bug the old commented-out block ran into.
         */
        $ticket_collaborators = escapeHtml(mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT GROUP_CONCAT(DISTINCT user_name ORDER BY user_name SEPARATOR ', ') AS user_names
            FROM ticket_replies
            INNER JOIN users ON ticket_reply_by = user_id
            WHERE ticket_reply_archived_at IS NULL
            AND ticket_reply_ticket_id = $ticket_id
            AND ticket_reply_type != 'Client'"
        ))['user_names']);

        // Get ticket replies
        $sql_ticket_replies = mysqli_query($mysqli, "SELECT contact_name, contact_photo, ticket_reply, ticket_reply_created_at, ticket_reply_id,
            ticket_reply_time_worked, ticket_reply_type, ticket_reply_updated_at, user_avatar, user_id,
            user_name FROM ticket_replies
            LEFT JOIN users ON ticket_reply_by = user_id
            LEFT JOIN contacts ON ticket_reply_by = contact_id
            WHERE ticket_reply_ticket_id = $ticket_id
            AND ticket_reply_archived_at IS NULL
            ORDER BY ticket_reply_id DESC"
        );

        /*
         * Every attachment on the ticket in one query, split by reply. The page
         * used to fire one attachment query per reply as it rendered.
         */
        $ticket_attachments = array();       // attachments on the ticket itself
        $reply_attachments = array();        // attachments keyed by reply id
        $sql_all_attachments = mysqli_query(
            $mysqli,
            "SELECT ticket_attachment_id, ticket_attachment_name, ticket_attachment_reply_id
            FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id"
        );
        while ($attachment_row = mysqli_fetch_assoc($sql_all_attachments)) {
            $attachment_reply_id = intval($attachment_row['ticket_attachment_reply_id']);
            if ($attachment_reply_id) {
                $reply_attachments[$attachment_reply_id][] = $attachment_row;
            } else {
                $ticket_attachments[] = $attachment_row;
            }
        }

        // Get Watchers
        $sql_ticket_watchers = mysqli_query($mysqli, "SELECT watcher_email, watcher_id FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id ORDER BY watcher_email DESC");
        $watcher_count = mysqli_num_rows($sql_ticket_watchers);

        // Get Additional Assets
        $sql_additional_assets = mysqli_query($mysqli, "SELECT assets.asset_id, asset_name, asset_type FROM assets, ticket_assets
            WHERE assets.asset_id = ticket_assets.asset_id
            AND ticket_id = $ticket_id
            AND assets.asset_id != $asset_id"
        );

        // Keep long template runbooks usable without turning the ticket into an
        // unbounded page. The next actionable task is the default; the complete
        // runbook remains available as a horizontal overview.
        $task_counts = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT COUNT(task_id) AS count,
                SUM(CASE WHEN task_completed_at IS NULL
                    AND COALESCE(task_state, 'Ready') NOT IN ('Completed', 'Skipped')
                    THEN 1 ELSE 0 END) AS active_count
            FROM tasks WHERE task_ticket_id = $ticket_id"
        ));
        $task_total_count = $task_counts ? intval($task_counts['count']) : 0;
        $task_active_count = $task_counts ? intval($task_counts['active_count']) : 0;
        $task_requested_view = $_GET['ticket_task_view'] ?? '';
        $task_view = in_array($task_requested_view, ['active', 'all'], true)
            ? $task_requested_view
            : ((!$ticket_is_resolved && $task_active_count) ? 'active' : 'all');
        $task_view_count = $task_view === 'active' ? $task_active_count : $task_total_count;
        $task_page_size = $task_view === 'all' ? max(1, $task_total_count) : 1;
        $task_page_start = 0;
        $task_view_sql = $task_view === 'active'
            ? " AND task_completed_at IS NULL AND COALESCE(task_state, 'Ready') NOT IN ('Completed', 'Skipped')"
            : '';
        $task_view_url = static function (string $view) use ($ticket_id, $client_id): string {
            $params = ['ticket_id' => $ticket_id, 'ticket_task_view' => $view];
            if ($client_id) {
                $params['client_id'] = $client_id;
            }
            return 'ticket.php?' . http_build_query($params) . '#tasks';
        };
        $sql_tasks = mysqli_query($mysqli, "SELECT tasks.*, user_name
            FROM tasks
            LEFT JOIN users ON user_id = task_assigned_to
            WHERE task_ticket_id = $ticket_id" . $task_view_sql . "
            ORDER BY task_order ASC, task_id ASC
            LIMIT $task_page_size OFFSET $task_page_start");
        $task_count = mysqli_num_rows($sql_tasks);

        $runbook_execution = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT runbook_execution_id,
            runbook_execution_status, runbook_execution_started_at,
            runbook_execution_snapshot_hash, runbook_version_id, runbook_version_number,
            runbook_version_name, runbook_version_type
            FROM runbook_executions
            INNER JOIN runbook_versions ON runbook_version_id = runbook_execution_version_id
            WHERE runbook_execution_ticket_id = $ticket_id LIMIT 1"));

        $task_evidence = [];
        $sql_task_evidence = mysqli_query($mysqli, "SELECT task_evidence.*, ticket_attachment_name, user_name
            FROM task_evidence
            INNER JOIN tasks ON task_id = task_evidence_task_id
            LEFT JOIN ticket_attachments ON ticket_attachment_id = task_evidence_attachment_id
            LEFT JOIN users ON user_id = task_evidence_submitted_by
            WHERE task_ticket_id = $ticket_id ORDER BY task_evidence_created_at, task_evidence_id");
        while ($evidence_row = mysqli_fetch_assoc($sql_task_evidence)) {
            $task_evidence[intval($evidence_row['task_evidence_task_id'])][] = $evidence_row;
        }

        $task_dependencies = [];
        $sql_task_dependencies = mysqli_query($mysqli, "SELECT d.task_id, dependency.task_name,
            dependency.task_state, dependency.task_completed_at
            FROM task_dependencies d
            INNER JOIN tasks dependency ON dependency.task_id = d.depends_on_task_id
            INNER JOIN tasks child ON child.task_id = d.task_id
            WHERE child.task_ticket_id = $ticket_id
            ORDER BY dependency.task_order, dependency.task_id");
        while ($dependency_row = mysqli_fetch_assoc($sql_task_dependencies)) {
            $task_dependencies[intval($dependency_row['task_id'])][] = $dependency_row;
        }

        $completed_task_count = intval(mysqli_fetch_row(mysqli_query(
            $mysqli,
            "SELECT COUNT(task_id) FROM tasks WHERE task_ticket_id = $ticket_id AND task_completed_at IS NOT NULL"
        ))[0]);

        // Tasks Completed Percent
        $tasks_completed_percent = 0;
        if ($task_total_count) {
            $tasks_completed_percent = round(($completed_task_count / $task_total_count) * 100);
        }

        // Tasks still open block resolving the ticket - the page says so rather than just hiding the button
        [$ticket_tasks_allow_resolve, $ticket_resolution_gate_error] = ticketLifecycleCanResolve($ticket_id, true);
        $tasks_block_resolve = !$ticket_tasks_allow_resolve;

        $ticket_documentation_link_count = intval(mysqli_fetch_row(mysqli_query($mysqli,
            "SELECT COUNT(*) FROM ticket_documentation_obligations
            WHERE ticket_documentation_obligation_ticket_id = $ticket_id"
        ))[0] ?? 0);

        /*
         * All approvals for this ticket's tasks in one query. The task loop used
         * to run two approval queries per row.
         */
        $task_approvals = array();
        if ($task_count) {
            $sql_task_approvals = mysqli_query(
                $mysqli,
                "SELECT approval_id, approval_task_id, approval_scope, approval_type,
                    approval_required_user_id, approval_created_by, approval_status
                FROM task_approvals
                INNER JOIN tasks ON approval_task_id = task_id
                WHERE task_ticket_id = $ticket_id"
            );
            while ($approval_row = mysqli_fetch_assoc($sql_task_approvals)) {
                $task_approvals[intval($approval_row['approval_task_id'])][] = $approval_row;
            }
        }

        /*
         * Ticket history. agent/post/ticket.php has been writing priority, SLA,
         * contact, watcher and asset changes here all along - the page fetched
         * the rows and then never rendered them.
         */
        $sql_ticket_history = mysqli_query($mysqli, "SELECT ticket_history_description, ticket_history_created_at
            FROM ticket_history
            WHERE ticket_history_ticket_id = $ticket_id
            ORDER BY ticket_history_id DESC"
        );
        $ticket_history_count = mysqli_num_rows($sql_ticket_history);

        // Level.io alert context for tickets opened by the RMM integration.
        $level_alert_link = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT level_alert_id,
            level_alert_last_event_at, level_alert_name, level_alert_resolved_at,
            level_alert_severity, level_alert_started_at, level_asset_id, level_device_id
            FROM level_alert_links WHERE level_ticket_id = $ticket_id LIMIT 1"));

        // Automation broker context: keep the correlated incident and its latest signals beside the ticket.
        $automation_incident = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT automation_incidents.*,
            services.service_name
            FROM automation_incidents
            LEFT JOIN services ON automation_incident_service_id = service_id
            WHERE automation_incident_ticket_id = $ticket_id LIMIT 1"));
        $automation_incident_events = [];
        if ($automation_incident) {
            $automation_source_sql = escapeSql($automation_incident['automation_incident_source']);
            $automation_key_sql = escapeSql($automation_incident['automation_incident_key']);
            $sql_automation_incident_events = mysqli_query($mysqli, "SELECT automation_event_action,
                automation_event_delivery_count, automation_event_last_received_at,
                automation_event_received_at, automation_event_state, automation_event_status,
                automation_event_suppressed_reason
                FROM automation_events
                WHERE automation_event_source = '$automation_source_sql'
                AND automation_event_incident_key = '$automation_key_sql'
                ORDER BY automation_event_last_received_at DESC LIMIT 5");
            while ($automation_event = mysqli_fetch_assoc($sql_automation_incident_events)) {
                $automation_incident_events[] = $automation_event;
            }
        }

        /*
         * The single most useful thing on the page: which clock is running and
         * how long is left. Everything else about the SLA is detail.
         */
        $sla_state = '';        // breached | at_risk | paused | met | running
        $sla_label = '';
        $sla_class = '';
        $sla_icon = 'fa-stopwatch';

        /*
         * Only surface the SLA field when SLAs are actually in play - either this
         * ticket has one, or plans exist to assign. Mirrors $sla_filter_in_use on
         * agent/tickets.php so an install that does not use SLAs never sees them.
         * The count is only queried when the ticket has no SLA of its own.
         */
        $sla_in_use = $ticket_sla_id > 0 || !is_null($ticket_agreement_decision);
        if (!$sla_in_use && $can_edit_ticket) {
            $sla_in_use = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(sla_id) FROM slas WHERE sla_archived_at IS NULL"))[0] > 0;
        }
        if ($ticket_sla_id) {
            $sla_next_due = empty($ticket_first_response_at) ? $ticket_response_due_at : $ticket_resolution_due_at;
            $sla_next_label = empty($ticket_first_response_at) ? 'First response due' : 'Resolution due';
            $ticket_sla_alert_stage = max(intval($ticket['ticket_response_sla_alert_stage']), intval($ticket['ticket_resolution_sla_alert_stage']));
            $sla_breached = (!is_null($ticket_response_sla_met) && !$ticket_response_sla_met)
                || (!is_null($ticket_resolution_sla_met) && !$ticket_resolution_sla_met)
                || $ticket_sla_alert_stage == 2;

            if ($sla_breached) {
                $sla_state = 'breached';
                $sla_label = 'SLA breached';
                $sla_class = 'bg-danger';
                $sla_icon = 'fa-exclamation-triangle';
            } elseif (!$ticket_is_open) {
                $sla_state = 'met';
                $sla_label = 'SLA met';
                $sla_class = 'bg-success';
                $sla_icon = 'fa-check';
            } elseif ($ticket_sla_paused) {
                $sla_state = 'paused';
                $sla_label = 'SLA paused';
                $sla_class = 'bg-secondary';
                $sla_icon = 'fa-pause';
            } elseif (!empty($sla_next_due)) {
                $sla_overdue = strtotime($sla_next_due) < time();
                $sla_state = $sla_overdue ? 'breached' : 'running';
                $sla_label = $sla_next_label . ' ' . timeAgo($sla_next_due);
                $sla_class = $sla_overdue ? 'bg-danger' : 'bg-light border';
            }
        }

        ?>

        <link rel="stylesheet" href="css/ticket.css">

        <!-- Collision detection reads this - keep it outside the reply form so it exists on resolved tickets too -->
        <input type="hidden" id="ticket_id" value="<?= $ticket_id ?>">

        <!--
            Ticket header - everything you need to identify the ticket and know
            what state it is in, without scrolling or hunting across cards.
        -->
        <div class="card mb-3">
            <div class="card-body pb-2">

                <div class="d-flex flex-wrap justify-content-between">

                    <div class="me-3" style="min-width: 0;">

                        <!--
                            The breadcrumb band above the card is gone, so these lines
                            can each have room rather than being crammed onto one.
                        -->
                        <!-- The ticket's name is the page heading, so it comes first -->
                        <h4 class="ticket-subject mb-1">
                            <?= $ticket_subject ?>
                            <?php if ($can_edit_ticket && !$ticket_is_closed) { ?>
                                <a href="#" class="btn btn-tool ajax-modal" data-modal-url="modals/ticket/ticket_edit.php?id=<?= $ticket_id ?>" data-modal-size="lg" title="Edit subject and details">
                                    <i class="fas fa-edit"></i>
                                </a>
                            <?php } ?>
                        </h4>

                        <!-- Who it belongs to, where it came from, and anyone else in here right now -->
                        <div class="ticket-meta">
                            <span class="badge rounded-pill bg-dark me-2"><?= $ticket_reference ?></span>

                            <?php if ($client_id) { ?>
                                <a href="client_overview.php?client_id=<?= $client_id ?>"><?= $client_name ?></a>
                            <?php } else { ?>
                                <span class="text-muted">No client</span>
                            <?php } ?>

                            <?php if ($contact_id) { ?>
                                <i class="fas fa-fw fa-angle-right"></i>
                                <a href="#" class="ajax-modal" data-modal-size="lg" data-modal-url="modals/contact/contact.php?id=<?= $contact_id ?>"><?= $contact_name ?></a>
                            <?php } ?>

                            <?php if ($ticket_category) { ?><span class="mx-1">&middot;</span><?= $ticket_category_display ?><?php } ?>
                            <span class="mx-1">&middot;</span>Opened <?= $ticket_created_at_ago ?>
                            <?php if ($ticket_created_by_display) { echo " by " . $ticket_created_by_display; } ?>
                            <?php if ($ticket_source) { ?><span class="mx-1">&middot;</span>via <?= $ticket_source ?><?php } ?>
                            <?php if ($ticket_updated_at) { ?>
                                <span class="mx-1">&middot;</span><span title="<?= $ticket_updated_at ?>">Updated <?= $ticket_updated_at_ago ?></span>
                            <?php } ?>
                            <span class="text-info" id="ticket_collision_viewing"></span>
                        </div>

                    </div>

                    <!-- Actions -->
                    <?php if ($can_edit_ticket) {
                        // Whether the joined button group has anything in it - an empty
                        // btn-group would still contribute its separator margin
                        $show_quote_button = $config_module_enable_accounting && $ticket_billable == 1 && empty($quote_id) && empty($invoice_id) && lookupUserPermission("module_sales") >= 2;
                        $show_invoice_button = $config_module_enable_accounting && $ticket_billable == 1 && empty($invoice_id) && lookupUserPermission("module_sales") >= 2;
                        $has_action_buttons = $show_quote_button || $show_invoice_button || !$ticket_is_closed;
                        ?>
                        <div class="d-print-none text-end">
                        <div class="d-flex flex-wrap justify-content-end align-items-start">
                            <?php if ($has_action_buttons) { ?>
                            <div class="btn-group me-3 mb-1">

                                <?php if ($show_quote_button) { ?>
                                    <a href="#" class="btn btn-light ajax-modal" data-modal-url="modals/ticket/ticket_quote_add.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                                        <i class="fas fa-fw fa-comment-dollar me-2"></i>Quote
                                    </a>
                                <?php } ?>

                                <?php if ($show_invoice_button) { ?>
                                    <a href="#" class="btn btn-light ajax-modal" data-modal-url="modals/ticket/ticket_invoice_add.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                                        <i class="fas fa-fw fa-file-invoice me-2"></i>Invoice
                                    </a>
                                <?php } ?>

                                <?php if (!$ticket_is_closed) { ?>

                                    <?php if ($ticket_is_resolved) { ?>
                                        <a href="post.php?reopen_ticket=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-light">
                                            <i class="fas fa-fw fa-redo me-2"></i>Reopen
                                        </a>
                                    <?php } ?>

                                    <?php if (!$ticket_is_resolved) { ?>
                                        <a href="post.php?resolve_ticket=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                            class="btn btn-dark confirm-link <?php if ($tasks_block_resolve) { echo "disabled"; } ?>"
                                            id="ticket_close"
                                            <?php if ($tasks_block_resolve) { ?>
                                                title="<?= escapeHtml($ticket_resolution_gate_error) ?>"
                                                onclick="return false;"
                                            <?php } ?>
                                        >
                                            <i class="fas fa-fw fa-check me-2"></i>Resolve
                                        </a>
                                    <?php } else { ?>
                                        <a href="#" class="btn btn-dark ajax-modal" id="ticket_close"
                                           data-modal-url="modals/ticket/ticket_terminal.php?ticket_id=<?= $ticket_id ?>&action=close">
                                            <i class="fas fa-fw fa-gavel me-2"></i>Close
                                        </a>
                                    <?php } ?>

                                <?php } ?>

                            </div>
                            <?php } ?>

                            <div class="dropdown dropstart mb-1">
                                    <button class="btn btn-secondary" type="button" data-bs-toggle="dropdown" title="More actions" aria-label="More ticket actions">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_summary.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                                            <i class="fas fa-fw fa-lightbulb me-2"></i>Summarize
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_merge.php?ticket_id=<?= $ticket_id ?>">
                                            <i class="fas fa-fw fa-clone me-2"></i>Merge Ticket
                                        </a>
                                        <!-- Attaching things to the ticket lives on the Linked card, next to what it attaches -->
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#" id="clientChangeTicketModalLoad" data-modal-url="modals/ticket/ticket_change_client.php?ticket_id=<?= $ticket_id ?>">
                                            <i class="fas fa-fw fa-people-carry me-2"></i>Change Client
                                        </a>
                                        <?php if (!$ticket_is_closed && !$ticket_is_resolved) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_terminal.php?ticket_id=<?= $ticket_id ?>&action=close">
                                                <i class="fas fa-fw fa-gavel me-2"></i>Close ticket
                                            </a>
                                            <a class="dropdown-item ajax-modal text-danger" href="#" data-modal-url="modals/ticket/ticket_terminal.php?ticket_id=<?= $ticket_id ?>&action=cancel">
                                                <i class="fas fa-fw fa-ban me-2"></i>Cancel ticket
                                            </a>
                                        <?php } ?>
                                        <?php if (lookupUserPermission("module_support") == 3 && empty($ticket_closed_at)) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_ticket=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                            </a>
                                        <?php } ?>
                                    </div>
                            </div>
                        </div>
                        </div>
                    <?php } ?>

                </div>

                <!--
                    Every stateful value is a labelled field here rather than a floating
                    badge cluster up by the title. Labels make each one scannable, and
                    fields in a gutter cannot collide with each other the way loose
                    inline badges did.
                -->
                <div class="ticket-fields border-top mt-2 pt-1">

                    <div class="ticket-field">
                        <div class="ticket-field-label">Status</div>
                        <div class="ticket-field-value">
                            <span class="badge rounded-pill p-2 text-light" style="background-color: <?= $ticket_status_color ?>"><?= $ticket_status_name ?></span>
                        </div>
                    </div>

                    <div class="ticket-field">
                        <div class="ticket-field-label">Priority</div>
                        <div class="ticket-field-value">
                            <a href="#" title="Change priority"
                                class="text-decoration-none<?php if ($can_edit_ticket && !$ticket_is_closed) { echo " ajax-modal"; } ?>"
                                <?php if ($can_edit_ticket && !$ticket_is_closed) { ?>
                                    data-modal-url="modals/ticket/ticket_priority.php?id=<?= $ticket_id ?>"
                                <?php } ?>
                            >
                                <span class="badge rounded-pill text-bg-<?= $ticket_priority_color ?> p-2"><?= $ticket_priority ?></span>
                            </a>
                        </div>
                    </div>

                    <?php if ($sla_in_use) { ?>
                        <div class="ticket-field">
                            <div class="ticket-field-label">SLA</div>
                            <div class="ticket-field-value">
                                <a href="#" title="SLA plan: <?= $ticket_sla_name ?><?= $ticket_agreement_decision ? ' — ' . escapeHtml($ticket_agreement_decision['ticket_agreement_decision_reason']) : '' ?>"
                                    class="text-decoration-none<?php if ($can_edit_ticket && !$ticket_is_closed) { echo " ajax-modal"; } ?>"
                                    <?php if ($can_edit_ticket && !$ticket_is_closed) { ?>
                                        data-modal-url="modals/ticket/ticket_sla.php?id=<?= $ticket_id ?>"
                                    <?php } ?>
                                >
                                    <?php if ($sla_label) { ?>
                                        <span class="badge rounded-pill <?= $sla_class ?> p-2"><i class="fas fa-fw <?= $sla_icon ?> me-1"></i><?= $sla_label ?></span>
                                    <?php } elseif ($ticket_sla_id) { ?>
                                        <?= $ticket_sla_name ?>
                                    <?php } else { ?>
                                        <span class="text-muted">None</span>
                                    <?php } ?>
                                </a>
                            </div>
                        </div>
                    <?php } ?>

                    <div class="ticket-field">
                        <div class="ticket-field-label">Assigned to</div>
                        <div class="ticket-field-value">
                            <?php if ($can_edit_ticket && !$ticket_is_closed) { ?>
                                <a class="ajax-modal" href="#" data-modal-url="modals/ticket/ticket_assign.php?id=<?= $ticket_id ?>" title="Change assignment"><?= $ticket_assigned_to_display ?></a>
                            <?php } else { ?>
                                <?= $ticket_assigned_to_display ?>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="ticket-field">
                        <div class="ticket-field-label">Scheduled</div>
                        <div class="ticket-field-value">
                            <?php if ($can_edit_ticket && !$ticket_is_closed) { ?>
                                <a class="ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_schedule.php?ticket_id=<?= $ticket_id ?>" title="Change schedule">
                                    <?php if ($ticket_scheduled_for) { ?><?= $ticket_scheduled_wording ?><?php } else { ?><span class="text-muted">Not scheduled</span><?php } ?>
                                </a>
                            <?php } else { ?>
                                <?= $ticket_scheduled_for ? $ticket_scheduled_wording : '<span class="text-muted">Not scheduled</span>' ?>
                            <?php } ?>
                        </div>
                    </div>

                    <?php if ($config_module_enable_accounting && lookupUserPermission("module_sales") >= 1) { ?>
                        <div class="ticket-field">
                            <div class="ticket-field-label">Billing</div>
                            <div class="ticket-field-value">
                                <?php if ($invoice_id) { ?>
                                    <a href="invoice.php?invoice_id=<?= $invoice_id ?>"><i class="fa fa-fw fa-file-invoice text-secondary me-1"></i><?= $invoice_reference ?></a>
                                <?php } elseif ($quote_id) { ?>
                                    <a href="quote.php?quote_id=<?= $quote_id ?>"><i class="fa fa-fw fa-comment-dollar text-secondary me-1"></i><?= $quote_reference ?></a>
                                <?php } elseif ($can_edit_ticket) { ?>
                                    <a class="ajax-modal" href="#" data-modal-url="modals/ticket/ticket_billable.php?id=<?= $ticket_id ?>" title="Change billable">
                                        <?= $ticket_billable ? "<span class='text-bold text-dark'>Billable</span>" : "<span class='text-muted'>Not billable</span>" ?>
                                    </a>
                                <?php } else { ?>
                                    <?= $ticket_billable ? 'Billable' : '<span class="text-muted">Not billable</span>' ?>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>

                    <?php if ($ticket_total_reply_time) { ?>
                        <div class="ticket-field">
                            <div class="ticket-field-label">Time logged</div>
                            <div class="ticket-field-value"><?= formatDuration($ticket_total_reply_time) ?></div>
                        </div>
                    <?php } ?>

                    <?php if ($task_total_count) { ?>
                        <div class="ticket-field">
                            <div class="ticket-field-label">Tasks</div>
                            <div class="ticket-field-value d-flex align-items-center">
                                <div class="progress ticket-task-progress me-2" role="progressbar" aria-valuenow="<?= $tasks_completed_percent ?>" aria-valuemin="0" aria-valuemax="100" title="<?= $tasks_completed_percent ?>% complete">
                                    <div class="progress-bar <?= $tasks_block_resolve ? 'bg-secondary' : 'bg-success' ?>" style="width: <?= $tasks_completed_percent ?>%;"></div>
                                </div>
                                <?= "$completed_task_count of $task_total_count" ?>
                            </div>
                        </div>
                    <?php } ?>

                </div>

                <?php
                $ticket_lifecycle_stage = $ticket_is_closed ? 3 : ($ticket_is_resolved ? 2 : ($ticket_status === 1 ? 0 : 1));
                $ticket_lifecycle_steps = ['Request', 'Work', 'Resolve', 'Close'];
                if ($ticket_is_closed) {
                    $ticket_next_action = 'Closed';
                    $ticket_next_action_icon = 'fa-check-circle';
                    $ticket_next_action_href = '#ticket-activity';
                } elseif ($ticket_is_resolved) {
                    $ticket_next_action = 'Review outcome and close';
                    $ticket_next_action_icon = 'fa-gavel';
                    $ticket_next_action_href = '#ticket-activity';
                } elseif (!$ticket_assigned_to) {
                    $ticket_next_action = 'Assign an owner';
                    $ticket_next_action_icon = 'fa-user-plus';
                    $ticket_next_action_href = '#ticket-context';
                } elseif ($tasks_block_resolve) {
                    $ticket_next_action = 'Complete the current task';
                    $ticket_next_action_icon = 'fa-tasks';
                    $ticket_next_action_href = '#ticket-work';
                } else {
                    $ticket_next_action = 'Post an update or resolve';
                    $ticket_next_action_icon = 'fa-reply';
                    $ticket_next_action_href = '#ticket-update';
                }
                ?>
                <div class="ticket-lifecycle border-top mt-3 pt-3">
                    <ol class="ticket-lifecycle-steps" aria-label="Ticket lifecycle">
                        <?php foreach ($ticket_lifecycle_steps as $index => $step) {
                            $step_state = $index < $ticket_lifecycle_stage ? 'complete' : ($index === $ticket_lifecycle_stage ? 'current' : 'upcoming');
                            ?>
                            <li class="ticket-lifecycle-step is-<?= $step_state ?>"<?= $step_state === 'current' ? ' aria-current="step"' : '' ?>>
                                <span class="ticket-lifecycle-dot"><?= $index < $ticket_lifecycle_stage ? '<i class="fas fa-check"></i>' : $index + 1 ?></span>
                                <span><?= $step ?></span>
                            </li>
                        <?php } ?>
                    </ol>
                    <a class="ticket-next-action" href="<?= $ticket_next_action_href ?>">
                        <span class="small text-muted">Next action</span>
                        <strong><i class="fas <?= $ticket_next_action_icon ?> me-1"></i><?= $ticket_next_action ?></strong>
                    </a>
                </div>

                <nav class="ticket-workspace-nav mt-3" aria-label="Ticket workspace">
                    <?php if (!$ticket_is_resolved || $task_total_count) { ?><a href="#ticket-work"><i class="fas fa-tasks"></i>Work</a><?php } ?>
                    <?php if ($can_edit_ticket && $ticket_is_open) { ?><a href="#ticket-update"><i class="fas fa-reply"></i>Update</a><?php } ?>
                    <a href="#ticket-activity"><i class="fas fa-comments"></i>Activity</a>
                    <a href="#ticket-request"><i class="fas fa-align-left"></i>Request</a>
                    <a href="#ticket-context"><i class="fas fa-link"></i>Context</a>
                </nav>

            </div>
        </div>

                <!-- Tasks -->
                <?php if (!$ticket_is_resolved || $task_total_count) { ?>
                    <div class="card mb-3 ticket-task-workspace" id="ticket-work">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1">
                                <i class="fas fa-fw fa-tasks me-2"></i><?= $task_view === 'all' ? 'Runbook' : 'Current task' ?>
                            </h5>
                            <div class="card-tools">
                                <?php if (!$ticket_is_resolved && $can_edit_ticket && $task_total_count) { ?>
                                    <div class="dropdown dropstart d-inline-block">
                                        <button class="btn btn-tool" type="button" data-bs-toggle="dropdown" aria-label="Task actions">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item text-success" href="post.php?complete_all_tasks=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-check-double me-2"></i>Mark All Complete
                                            </a>
                                            <a class="dropdown-item" href="post.php?undo_complete_all_tasks=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="far fa-fw fa-square me-2"></i>Mark All Incomplete
                                            </a>
                                        </div>
                                    </div>
                                <?php } ?>
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse" aria-label="Collapse tasks">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">

                            <?php if ($runbook_execution) { ?>
                                <div class="px-3 py-2 border-bottom bg-light small">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span>
                                            <i class="fas fa-code-branch mr-1"></i>
                                            <?= escapeHtml($runbook_execution['runbook_version_name']) ?>
                                            <strong>v<?= intval($runbook_execution['runbook_version_number']) ?></strong>
                                            <span class="badge badge-<?= $runbook_execution['runbook_execution_status'] === 'Completed' ? 'success' : 'primary' ?> ml-1"><?= escapeHtml($runbook_execution['runbook_execution_status']) ?></span>
                                        </span>
                                        <?php if ($runbook_execution['runbook_execution_status'] === 'Completed') { ?>
                                            <a href="runbook_export.php?ticket_id=<?= $ticket_id ?>" title="Download completed runbook closeout">
                                                <i class="fas fa-download mr-1"></i>Export closeout
                                            </a>
                                        <?php } else { ?>
                                            <span class="text-muted" title="Closeout export becomes available after every runbook task reaches a terminal state">
                                                <i class="fas fa-lock mr-1"></i>Closeout pending
                                            </span>
                                        <?php } ?>
                                    </div>
                                    <div class="text-muted">Snapshot <?= escapeHtml(substr($runbook_execution['runbook_execution_snapshot_hash'], 0, 12)) ?></div>
                                </div>
                            <?php } ?>

                            <?php if (!$ticket_is_resolved && $can_edit_ticket) { ?>
                                <div class="px-3 pt-3">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ticketTaskAddForm" aria-expanded="false" aria-controls="ticketTaskAddForm">
                                        <i class="fas fa-plus me-1"></i>Add a task
                                    </button>
                                </div>
                                <div class="collapse" id="ticketTaskAddForm">
                                    <form action="post.php" method="post" autocomplete="off">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                                        <div class="mb-3 px-3 pt-2 mb-2">
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" name="name" placeholder="Add a task" required maxlength="255">
                                                <button type="submit" name="add_task" class="btn btn-outline-primary">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                        </div>
                                        </div>
                                    </form>
                                </div>
                            <?php } ?>

                            <?php if ($task_total_count) { ?>
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-bottom bg-light">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Task view">
                                        <a class="btn btn-<?= $task_view === 'active' ? 'primary' : 'outline-secondary' ?>" href="<?= escapeHtml($task_view_url('active')) ?>">
                                            Current task
                                        </a>
                                        <a class="btn btn-<?= $task_view === 'all' ? 'primary' : 'outline-secondary' ?>" href="<?= escapeHtml($task_view_url('all')) ?>">
                                            All steps <span class="badge badge-light ml-1"><?= $task_total_count ?></span>
                                        </a>
                                    </div>
                                    <span class="small text-muted"><?= $task_view === 'active' ? 'The next unfinished step is shown automatically' : 'Scroll sideways to review every step' ?></span>
                                </div>
                            <?php } ?>

                            <?php if (!$task_count) { ?>
                                <div class="px-3 py-3 text-muted small">
                                    <?= $task_view === 'active' && $task_total_count ? 'No current task. Open the Runbook to review completed work.' : 'No tasks on this ticket.' ?>
                                </div>
                            <?php } ?>

                            <div class="<?= $task_view === 'all' ? 'ticket-task-horizontal-scroll' : 'ticket-task-current' ?>"<?= $task_view === 'all' ? ' role="region" aria-label="All ticket tasks" tabindex="0"' : ' aria-label="Current ticket task"' ?>>
                            <table class="table table-sm mb-0" id="tasks">
                                <tbody>
                                <?php
                                while ($task_row = mysqli_fetch_assoc($sql_tasks)) {
                                    $task_id = intval($task_row['task_id']);
                                    $task_name = escapeHtml($task_row['task_name']);
                                    $task_completion_estimate = intval($task_row['task_completion_estimate']);
                                    $task_completed_at = escapeHtml($task_row['task_completed_at']);
                                    $task_state = $task_row['task_state'] ?: ($task_completed_at ? 'Completed' : 'Ready');
                                    $task_instructions = escapeHtml($task_row['task_instructions']);
                                    $task_assigned_name = escapeHtml($task_row['user_name']);
                                    $task_due_at = escapeHtml($task_row['task_due_at']);
                                    $task_waiting_reason = escapeHtml($task_row['task_waiting_reason']);
                                    $task_condition_result = $task_row['task_condition_result'];
                                    $task_evidence_required = $task_row['task_evidence_required'] ?: 'none';
                                    $task_evidence_prompt = escapeHtml($task_row['task_evidence_prompt']);
                                    $task_evidence_items = $task_evidence[$task_id] ?? [];
                                    $task_dependencies_list = $task_dependencies[$task_id] ?? [];
                                    $task_evidence_satisfied = runbookTaskEvidenceSatisfied($task_id, $task_evidence_required);

                                    // Approvals came from the single batched query above
                                    $task_has_approvals = isset($task_approvals[$task_id]);
                                    $task_needs_approval = false;
                                    $approval_id = 0;
                                    $unresolved_approval_id = 0;
                                    $declined_approval_id = 0;
                                    $approval_approved_count = 0;
                                    $approval_pending_count = 0;
                                    $approval_declined_count = 0;
                                    $user_can_approve = false;

                                    if ($task_has_approvals) {
                                        foreach ($task_approvals[$task_id] as $approval) {
                                            if ($approval['approval_status'] === 'approved') {
                                                $approval_approved_count++;
                                                continue;
                                            }
                                            $task_needs_approval = true;
                                            if (!$unresolved_approval_id) {
                                                $unresolved_approval_id = intval($approval['approval_id']);
                                            }
                                            if ($approval['approval_status'] === 'declined') {
                                                $declined_approval_id = intval($approval['approval_id']);
                                                $approval_declined_count++;
                                            }
                                            if ($approval['approval_status'] !== 'pending') {
                                                continue;
                                            }
                                            $approval_pending_count++;

                                            $scope = escapeHtml($approval['approval_scope']);
                                            $type = escapeHtml($approval['approval_type']);
                                            $required_user = intval($approval['approval_required_user_id']);
                                            $created_by = intval($approval['approval_created_by']);

                                            // Named, specific user?
                                            if ($scope == 'internal' && $type == 'specific' && $required_user == $session_user_id) {
                                                $user_can_approve = true;
                                                $approval_id = intval($approval['approval_id']);
                                                continue;
                                            }

                                            // Any internal user, but the one who created the task
                                            if ($scope == 'internal' && $type == 'any' && $created_by !== $session_user_id) {
                                                $user_can_approve = true;
                                                $approval_id = intval($approval['approval_id']);
                                                continue;
                                            }
                                        }
                                    }

                                    ?>
                                    <tr data-task-id="<?= $task_id ?>">
                                        <td class="px-3">
                                            <?php if ($task_state === 'Skipped') { ?>
                                                <i class="fas fa-minus-circle text-muted" title="Skipped: <?= $task_waiting_reason ?>"></i>
                                            <?php } elseif ($task_state === 'Completed' || $task_completed_at) { ?>
                                                <i class="far fa-check-square text-success" title="Completed <?= $task_completed_at ?>"></i>
                                            <?php } elseif ($can_edit_ticket) { ?>

                                                <?php if ($task_state === 'Blocked') { ?>
                                                    <i class="fas fa-lock text-secondary" title="Blocked by incomplete prerequisites"></i>
                                                <?php } elseif ($task_needs_approval && $user_can_approve) { ?>
                                                    <i class="fas fa-shield-alt text-warning" title="Approval required"></i>

                                                    <form action="post.php" method="post" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="task_id" value="<?= $task_id ?>">
                                                        <input type="hidden" name="approval_id" value="<?= $approval_id ?>">
                                                        <button type="submit" name="decide_ticket_task_approval" value="1" class="btn btn-link btn-sm p-0" title="Approve" onclick="this.form.decision.value='approved'">
                                                            <i class="fas fa-thumbs-up text-success"></i>
                                                        </button>
                                                        <button type="submit" name="decide_ticket_task_approval" value="1" class="btn btn-link btn-sm p-0 ms-1" title="Decline" onclick="this.form.decision.value='declined'">
                                                            <i class="fas fa-thumbs-down text-danger"></i>
                                                        </button>
                                                        <input type="hidden" name="decision" value="approved">
                                                    </form>
                                                <?php } elseif ($task_state === 'Waiting') { ?>
                                                    <i class="fas fa-pause-circle text-warning" title="Waiting: <?= $task_waiting_reason ?>"></i>
                                                <?php } elseif ($task_needs_approval) { ?>
                                                    <i class="fas fa-shield-alt text-warning" title="Approval required"></i>

                                                    <?php if ($declined_approval_id && lookupUserPermission('module_support') >= 3) { ?>
                                                        <a class="ajax-modal text-danger" href="#"
                                                           data-modal-url="modals/ticket/ticket_task_approval_reroute.php?id=<?= $declined_approval_id ?>"
                                                           title="Reroute or re-request this approval">
                                                            <i class="fas fa-redo me-1"></i>Manage approval
                                                        </a>
                                                    <?php } ?>

                                                <?php } elseif (!$task_evidence_satisfied) { ?>
                                                    <i class="fas fa-paperclip text-danger" title="Required <?= escapeHtml($task_evidence_required) ?> evidence is missing"></i>
                                                <?php } else { ?>
                                                    <?php if ($task_view === 'active') { ?>
                                                        <a class="btn btn-sm btn-primary" href="post.php?complete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                            <i class="fas fa-check me-1"></i>Complete
                                                        </a>
                                                    <?php } else { ?>
                                                        <a href="post.php?complete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" title="Mark complete">
                                                            <i class="far fa-square text-dark"></i>
                                                        </a>
                                                    <?php } ?>
                                                <?php } ?>

                                            <?php } ?>
                                            <span class="ms-2 <?= in_array($task_state, ['Completed','Skipped'], true) ? 'text-muted' : 'text-dark' ?>"><?= $task_name ?></span>
                                            <div class="ms-4 mt-1 small">
                                                <span class="badge badge-<?= runbookTaskStateBadge($task_state) ?>"><?= escapeHtml($task_state) ?></span>
                                                <?php if ($task_condition_result !== 'Matched') { ?><span class="badge text-bg-light ms-1"><?= escapeHtml($task_condition_result) ?></span><?php } ?>
                                                <?php if ($task_assigned_name) { ?><span class="text-muted ms-1"><i class="fas fa-user me-1"></i><?= $task_assigned_name ?></span><?php } ?>
                                                <?php if ($task_due_at) { ?><span class="<?= strtotime($task_due_at) < time() && !in_array($task_state, ['Completed','Skipped'], true) ? 'text-danger' : 'text-muted' ?> ms-1"><i class="far fa-calendar me-1"></i><?= $task_due_at ?></span><?php } ?>
                                                <?php if ($task_evidence_required !== 'none') { ?><span class="<?= $task_evidence_satisfied ? 'text-success' : 'text-danger' ?> ms-1"><i class="fas fa-paperclip me-1"></i><?= count($task_evidence_items) ?>/<?= escapeHtml($task_evidence_required) ?></span><?php } ?>
                                                <?php if ($task_has_approvals) { ?>
                                                    <span class="<?= $task_needs_approval ? 'text-warning' : 'text-success' ?> ms-1" title="<?= $approval_approved_count ?> approved, <?= $approval_pending_count ?> pending, <?= $approval_declined_count ?> declined">
                                                        <i class="fas fa-shield-alt me-1"></i><?= $task_needs_approval ? ($approval_pending_count . ' pending' . ($approval_declined_count ? ', ' . $approval_declined_count . ' declined' : '')) : 'approved' ?>
                                                    </span>
                                                    <?php if ($unresolved_approval_id && lookupUserPermission('module_support') >= 3 && !in_array($task_state, ['Completed','Skipped'], true)) { ?>
                                                        <a class="ajax-modal ms-1" href="#" data-modal-url="modals/ticket/ticket_task_approval_reroute.php?id=<?= $unresolved_approval_id ?>">Manage</a>
                                                    <?php } ?>
                                                <?php } ?>
                                                <?php if ($task_completion_estimate) { ?><span class="text-muted ms-1"><?= $task_completion_estimate ?>m</span><?php } ?>
                                            </div>
                                            <?php if ($task_waiting_reason && in_array($task_state, ['Waiting','Skipped'], true)) { ?><div class="ms-4 small text-warning mt-1"><?= $task_waiting_reason ?></div><?php } ?>
                                            <?php if ($task_state === 'Blocked' && $task_dependencies_list) { ?>
                                                <div class="ms-4 small text-muted mt-1">Blocked by:
                                                    <?= escapeHtml(implode(', ', array_map(static fn($dependency) => $dependency['task_name'], array_filter($task_dependencies_list, static fn($dependency) => empty($dependency['task_completed_at']) && $dependency['task_state'] !== 'Skipped')))) ?>
                                                </div>
                                            <?php } ?>
                                            <?php if ($task_view === 'active' && ($task_instructions || ($task_evidence_prompt && !$task_evidence_satisfied))) { ?>
                                                <div class="ticket-task-guidance ms-4 mt-2 small">
                                                    <?php if ($task_instructions) { ?><div><?= $task_instructions ?></div><?php } ?>
                                                    <?php if ($task_evidence_prompt && !$task_evidence_satisfied) { ?><div class="text-danger mt-2"><i class="fas fa-paperclip me-1"></i><?= $task_evidence_prompt ?></div><?php } ?>
                                                </div>
                                            <?php } elseif ($task_instructions || ($task_evidence_prompt && !$task_evidence_satisfied)) { ?>
                                                <details class="ticket-task-details ms-4 mt-1 small">
                                                    <summary class="text-primary"><?= $task_evidence_prompt && !$task_evidence_satisfied ? 'Instructions and evidence' : 'Instructions' ?></summary>
                                                    <?php if ($task_instructions) { ?><div class="text-muted mt-2"><?= $task_instructions ?></div><?php } ?>
                                                    <?php if ($task_evidence_prompt && !$task_evidence_satisfied) { ?><div class="text-danger mt-2"><?= $task_evidence_prompt ?></div><?php } ?>
                                                </details>
                                            <?php } ?>
                                        </td>
                                        <td class="px-2 text-end text-nowrap">
                                            <div class="btn-group">
                                                <?php if (intval($task_row['task_runbook_version_task_id']) === 0 && $task_view === 'all') { ?>
                                                    <button class="btn btn-sm btn-link drag-handle" title="Drag to reorder"><i class="fas fa-bars text-muted"></i></button>
                                                <?php } ?>

                                                <?php if (!$ticket_is_resolved && $can_edit_ticket) { ?>
                                                    <?php if (!in_array($task_state, ['Completed','Skipped'], true)) { ?>
                                                        <a class="btn btn-light text-secondary btn-sm ajax-modal" href="#"
                                                           data-modal-url="modals/ticket/ticket_task_approver_add.php?id=<?= $task_id ?>"
                                                           title="Add approval" aria-label="Add approval to <?= $task_name ?>">
                                                            <i class="fas fa-shield-alt"></i>
                                                        </a>
                                                    <?php } ?>
                                                    <div class="dropdown dropstart text-center">
                                                        <button class="btn btn-light text-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <div class="dropdown-menu">
                                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_edit.php?id=<?= $task_id ?>">
                                                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                                                            </a>
                                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_evidence_add.php?id=<?= $task_id ?>">
                                                                <i class="fas fa-fw fa-paperclip mr-2"></i>Evidence
                                                            </a>
                                                            <?php if ($task_row['task_runbook_version_task_id'] > 0 && !in_array($task_state, ['Completed','Skipped'], true)) { ?>
                                                                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_state.php?id=<?= $task_id ?>">
                                                                    <i class="fas fa-fw fa-hourglass-half mr-2"></i>Waiting / applicability
                                                                </a>
                                                            <?php } ?>
                                                            <?php if ($task_state === 'Completed') { ?>
                                                                <a class="dropdown-item" href="post.php?undo_complete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                                    <i class="fas fa-fw fa-arrow-circle-left me-2"></i>Mark incomplete
                                                                </a>
                                                            <?php } ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger confirm-link" href="post.php?delete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                                <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                                                            </a>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                </tbody>
                            </table>
                            </div>
                            <?php if ($task_view_count) { ?>
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top ticket-task-overflow">
                                    <span class="small text-muted">
                                        <?= $task_view === 'active' ? 'Showing the current actionable task' : 'Showing all ' . $task_view_count . ' tasks in runbook order' ?>
                                    </span>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>


        <div class="row">

            <div class="col-lg-9">

                <!-- Reply composer - only while the ticket is still being worked -->
                <?php if ($can_edit_ticket && $ticket_is_open) { ?>

                    <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off" id="ticket-update">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

                        <div class="card mb-3 ticket-update-card">
                            <div class="card-header px-3 py-2">
                                <h5 class="card-title mt-1"><i class="fas fa-fw fa-reply me-2"></i>Update ticket</h5>
                                <span class="small text-muted float-end">Choose who should see the update</span>
                            </div>
                            <div class="card-body p-3 d-print-none">

                                <!--
                                 * Picking a reply type is what opens the composer below. Nothing is
                                 * preselected, so a public reply is always a deliberate choice, and the
                                 * conversation sits right under this strip until an agent wants to write.
                                -->
                                <div class="btn-group w-100" role="group" id="replyTypePicker">
                                    <input class="btn-check" id="public_reply_type_opt0" type="radio" name="public_reply_type" value="0">
                                    <label class="btn btn-outline-dark" for="public_reply_type_opt0"><i class="fas fa-fw fa-lock me-1"></i>Internal note</label>
                                    <input class="btn-check" id="public_reply_type_opt1" type="radio" name="public_reply_type" value="1">
                                    <label class="btn btn-outline-info" for="public_reply_type_opt1"><i class="fas fa-fw fa-comment me-1"></i>Public reply</label>
                                    <?php if ($contact_email) { ?>
                                        <input class="btn-check" id="public_reply_type_opt2" type="radio" name="public_reply_type" value="2">
                                        <label class="btn btn-outline-info" for="public_reply_type_opt2"><i class="fas fa-fw fa-paper-plane me-1"></i>Public + email</label>
                                    <?php } ?>
                                </div>

                                <div class="collapse" id="replyComposer">
                                    <div class="pt-3">

                                        <?php

                                        /*
                                         * Canned responses offered on this ticket: the ones tied to its
                                         * category, plus the general ones that are offered everywhere.
                                         * Names only - the body is fetched when one is picked, so a
                                         * shelf of long responses does not ride along with every ticket.
                                         */
                                        $sql_canned_responses = mysqli_query($mysqli, "SELECT canned_response_id, canned_response_name, canned_response_category_id
                                            FROM canned_responses
                                            WHERE canned_response_archived_at IS NULL
                                            AND (canned_response_category_id = 0 OR canned_response_category_id = $ticket_category)
                                            ORDER BY canned_response_name ASC");

                                        $canned_responses_for_category = [];
                                        $canned_responses_general = [];

                                        while ($canned_row = mysqli_fetch_assoc($sql_canned_responses)) {
                                            if (intval($canned_row['canned_response_category_id']) === 0) {
                                                $canned_responses_general[] = $canned_row;
                                            } else {
                                                $canned_responses_for_category[] = $canned_row;
                                            }
                                        }

                                        if ($canned_responses_for_category || $canned_responses_general) { ?>

                                            <div class="mb-3">
                                                <select class="form-select" id="canned_response_picker">
                                                    <option value="">- Insert a canned response -</option>
                                                    <?php if ($canned_responses_for_category) { ?>
                                                        <optgroup label="<?= $ticket_category_display ?>">
                                                            <?php foreach ($canned_responses_for_category as $canned_row) { ?>
                                                                <option value="<?= intval($canned_row['canned_response_id']) ?>"><?= escapeHtml($canned_row['canned_response_name']) ?></option>
                                                            <?php } ?>
                                                        </optgroup>
                                                    <?php } ?>
                                                    <?php if ($canned_responses_general) { ?>
                                                        <optgroup label="All categories">
                                                            <?php foreach ($canned_responses_general as $canned_row) { ?>
                                                                <option value="<?= intval($canned_row['canned_response_id']) ?>"><?= escapeHtml($canned_row['canned_response_name']) ?></option>
                                                            <?php } ?>
                                                        </optgroup>
                                                    <?php } ?>
                                                </select>
                                            </div>

                                        <?php } ?>

                                        <div class="mb-3">
                                            <textarea class="form-control tinymceTicket" id="ticket_reply" name="ticket_reply" placeholder="Type a response"></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <input type="file" class="form-control" name="attachments[]" multiple accept=".jpg, .jpeg, .gif, .png, .webp, .pdf, .txt, .md, .doc, .docx, .odt, .csv, .xls, .xlsx, .ods, .pptx, .odp, .zip, .tar, .gz, .xml, .msg, .json, .wav, .mp3, .ogg, .mov, .mp4, .av1, .ovpn">
                                        </div>

                                        <div class="row g-2 align-items-center">
                                            <div class="col-md-4">
                                                <div class="mb-3 mb-md-0">
                                                    <label class="text-secondary small mb-1">Set status to</label>
                                                    <select class="form-select select2" name="status" required>
                                                        <!-- Show all active ticket statuses, apart from new or closed as these are system-managed -->
                                                        <?php
                                                        $status_snippet = '';
                                                        if ($tasks_block_resolve) {
                                                            $status_snippet = "AND ticket_status_id != 4";
                                                        }
                                                        $sql_ticket_status = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_id != 1 AND ticket_status_id != 5 AND ticket_status_active = 1 $status_snippet ORDER BY ticket_status_order");
                                                        while ($status_row = mysqli_fetch_assoc($sql_ticket_status)) {
                                                            $ticket_status_id_select = intval($status_row['ticket_status_id']);
                                                            $ticket_status_name_select = escapeHtml($status_row['ticket_status_name']);
                                                            ?>
                                                            <option value="<?= $ticket_status_id_select ?>" <?php if ($ticket_status == $ticket_status_id_select) { echo 'selected'; } ?>><?= $ticket_status_name_select ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <!-- Time Tracking -->
                                            <div class="col-md-5">
                                                <div class="mb-3 mb-md-0">
                                                    <label class="text-secondary small mb-1">Time worked</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" inputmode="numeric" id="hours" name="hours" placeholder="Hrs" min="0" max="23" pattern="0?[0-9]|1[0-9]|2[0-3]">
                                                        <input type="text" class="form-control" inputmode="numeric" id="minutes" name="minutes" placeholder="Mins" min="0" max="59" pattern="[0-5]?[0-9]">
                                                        <input type="text" class="form-control" inputmode="numeric" id="seconds" name="seconds" placeholder="Secs" min="0" max="59" pattern="[0-5]?[0-9]">
                                                            <button type="button" class="btn btn-light" id="startStopTimer" title="Start / stop timer"><i class="fas fa-play"></i></button>
                                                            <button type="button" class="btn btn-light" id="resetTimer" title="Reset timer"><i class="fas fa-redo-alt"></i></button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="d-flex gap-2 mt-3 mt-md-4">
                                                    <button type="submit" id="ticket_add_reply" name="add_ticket_reply" class="btn btn-success flex-grow-1">
                                                        <i class="fas fa-fw fa-paper-plane me-2"></i>Send
                                                    </button>
                                                    <button type="button" id="cancelReply" class="btn btn-light" title="Close without replying">
                                                        <i class="fas fa-fw fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>

                            </div>
                        </div>
                    </form>

                <?php } ?>

                <!-- The original request stays available without interrupting the work path -->
                <div class="card card-dark mb-3" id="ticket-request">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1"><i class="fas fa-fw fa-align-left me-2"></i>Original request</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool ticket-disclosure-toggle" data-lte-toggle="card-collapse"
                                    aria-expanded="true" aria-label="Collapse original request" data-expanded-label="Collapse original request" data-collapsed-label="Show original request">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-3 prettyContent" id="ticketDetails">
                        <?= $ticket_details ?>

                        <?php if ($ticket_attachments) { ?>
                            <div class="mt-3 pt-2 border-top">
                                <?php foreach ($ticket_attachments as $ticket_attachment) {
                                    $ticket_attachment_id = intval($ticket_attachment['ticket_attachment_id']);
                                    $ticket_attachment_name = escapeHtml($ticket_attachment['ticket_attachment_name']);
                                    ?>
                                    <div class="mt-1">
                                        <i class="fas fa-fw fa-paperclip text-secondary me-1"></i><?= $ticket_attachment_name ?>
                                        <a target="_blank" class="ms-2 small" href="ticket_attachment.php?attachment_id=<?= $ticket_attachment_id ?>&action=view">View</a>
                                        <a class="ms-2 small" href="ticket_attachment.php?attachment_id=<?= $ticket_attachment_id ?>">Download</a>
                                        <?php if (lookupUserPermission("module_support") >= 3) { ?>
                                            <a class="confirm-link ms-2 small text-danger" href="post.php?delete_ticket_attachment=<?= $ticket_attachment_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Delete</a>
                                        <?php } ?>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Conversation -->
                <section id="ticket-activity" aria-labelledby="ticket-activity-heading">
                <?php if ($reply_counts['total']) { ?>
                    <div class="d-flex flex-wrap align-items-center mb-2 d-print-none">
                        <h6 class="mb-0 me-3" id="ticket-activity-heading"><i class="fas fa-fw fa-comments me-2"></i>Recent activity</h6>
                        <div class="btn-group btn-group-sm" id="replyFilter">
                            <button type="button" class="btn btn-dark active" data-reply-filter="all">All <?= $reply_counts['total'] ?></button>
                            <button type="button" class="btn btn-outline-dark" data-reply-filter="public">Public <?= $reply_counts['public'] ?></button>
                            <button type="button" class="btn btn-outline-dark" data-reply-filter="internal">Internal <?= $reply_counts['internal'] ?></button>
                        </div>
                    </div>
                <?php } ?>

                <?php

                $ticket_reply_render_index = 0;
                while ($reply_row = mysqli_fetch_assoc($sql_ticket_replies)) {
                    $ticket_reply_id = intval($reply_row['ticket_reply_id']);
                    $ticket_reply = $purifier->purify($reply_row['ticket_reply']);
                    $ticket_reply_type = escapeHtml($reply_row['ticket_reply_type']);
                    $ticket_reply_created_at = escapeHtml($reply_row['ticket_reply_created_at']);
                    $ticket_reply_created_at_ago = timeAgo($reply_row['ticket_reply_created_at']);
                    $ticket_reply_updated_at = escapeHtml($reply_row['ticket_reply_updated_at']);
                    $ticket_reply_time_worked = '';

                    if ($ticket_reply_type == "Client") {
                        $ticket_reply_by_display = escapeHtml($reply_row['contact_name']);
                        $user_initials = initials($reply_row['contact_name']);
                        $user_avatar = escapeHtml($reply_row['contact_photo']);
                        $avatar_link = "../uploads/clients/$client_id/$user_avatar";
                    } else {
                        $ticket_reply_by_display = escapeHtml($reply_row['user_name']);
                        $user_id = intval($reply_row['user_id']);
                        $user_avatar = escapeHtml($reply_row['user_avatar']);
                        $user_initials = initials($reply_row['user_name']);
                        $avatar_link = "../uploads/users/$user_id/$user_avatar";
                        $ticket_reply_time_worked = $reply_row['ticket_reply_time_worked'];
                    }

                    // Internal notes are the one thing that must never be mistaken for
                    // something the client saw, so they get a label, not just a colour
                    if ($ticket_reply_type == 'Internal') {
                        $reply_border = 'dark';
                        $reply_badge = "<span class='badge bg-dark'><i class='fas fa-fw fa-lock me-1'></i>Internal note</span>";
                        $reply_group = 'internal';
                    } elseif ($ticket_reply_type == 'Client') {
                        $reply_border = 'warning';
                        $reply_badge = "<span class='badge bg-warning text-dark'><i class='fas fa-fw fa-reply me-1'></i>From client</span>";
                        $reply_group = 'public';
                    } else {
                        $reply_border = 'info';
                        $reply_badge = "<span class='badge bg-info text-dark'><i class='fas fa-fw fa-comment me-1'></i>Public reply</span>";
                        $reply_group = 'public';
                    }

                    ?>

                    <!-- Begin ticket reply card -->
                    <div class="card ticket-reply border-start border-<?= $reply_border ?> mb-3<?= $ticket_reply_render_index >= 4 ? ' ticket-reply-older' : '' ?>" style="border-start-width: 6px !important;" data-reply-group="<?= $reply_group ?>"<?= $ticket_reply_render_index >= 4 ? ' hidden' : '' ?>>
                        <div class="card-header px-3 py-2">
                            <div class="d-flex justify-content-between align-items-start w-100">

                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user_avatar)) { ?>
                                        <img src="<?= $avatar_link ?>" alt="" class="img-size-32 me-2 rounded-circle">
                                    <?php } else { ?>
                                        <span class="fa-stack me-2">
                                            <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                            <span class="fa fa-stack-1x text-white"><?= $user_initials ?></span>
                                        </span>
                                    <?php } ?>

                                    <div>
                                        <strong><?= $ticket_reply_by_display ?></strong>
                                        <?= $reply_badge ?>
                                        <div class="small text-muted" title="Created: <?= $ticket_reply_created_at ?><?php if ($ticket_reply_updated_at) { echo '. Edited: ' . $ticket_reply_updated_at; } ?>">
                                            <?= $ticket_reply_created_at_ago ?><?php if ($ticket_reply_updated_at) { echo ' <em>(edited)</em>'; } ?>
                                            <?php if ($ticket_reply_type !== "Client" && $ticket_reply_time_worked && $ticket_reply_time_worked !== "00:00:00") { ?>
                                                <span class="mx-1">&middot;</span><i class="far fa-fw fa-clock"></i> <?= formatDuration($ticket_reply_time_worked) ?>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($can_edit_ticket) { ?>
                                    <div class="card-tools d-print-none">
                                        <div class="dropdown dropstart">
                                            <button class="btn btn-sm btn-tool" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-fw fa-ellipsis-v"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <a href="#" class="dropdown-item ajax-modal" data-modal-size="lg"
                                                    data-modal-url="modals/ticket/ticket_reply_redact.php?id=<?= $ticket_reply_id ?>">
                                                    <i class="fas fa-fw fa-pen text-danger me-2"></i>Redact
                                                </a>
                                                <?php if ($ticket_reply_type !== "Client" && !$ticket_is_closed) { ?>
                                                    <div class="dropdown-divider"></div>
                                                    <a href="#" class="dropdown-item ajax-modal" data-modal-size="lg"
                                                        data-modal-url="modals/ticket/ticket_reply_edit.php?id=<?= $ticket_reply_id ?>">
                                                        <i class="fas fa-fw fa-edit text-secondary me-2"></i>Edit
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <a class="dropdown-item text-danger confirm-link" href="post.php?archive_ticket_reply=<?= $ticket_reply_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-archive me-2"></i>Archive
                                                    </a>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php } ?>

                            </div>
                        </div>

                        <div class="card-body p-3 prettyContent">
                            <?= $ticket_reply ?>

                            <?php if (isset($reply_attachments[$ticket_reply_id])) { ?>
                                <div class="mt-3 pt-2 border-top">
                                    <?php foreach ($reply_attachments[$ticket_reply_id] as $ticket_attachment) {
                                        $ticket_attachment_id = intval($ticket_attachment['ticket_attachment_id']);
                                        $ticket_attachment_name = escapeHtml($ticket_attachment['ticket_attachment_name']);
                                        ?>
                                        <div class="mt-1">
                                            <i class="fas fa-fw fa-paperclip text-secondary me-1"></i><?= $ticket_attachment_name ?>
                                            <a target="_blank" class="ms-2 small" href="ticket_attachment.php?attachment_id=<?= $ticket_attachment_id ?>&action=view">View</a>
                                            <a class="ms-2 small" href="ticket_attachment.php?attachment_id=<?= $ticket_attachment_id ?>">Download</a>
                                            <?php if (lookupUserPermission("module_support") >= 3) { ?>
                                                <a class="confirm-link ms-2 small text-danger" href="post.php?delete_ticket_attachment=<?= $ticket_attachment_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Delete</a>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <!-- End ticket reply card -->

                    <?php

                    $ticket_reply_render_index++;

                }

                ?>

                <?php if ($reply_counts['total'] > 4) { ?>
                    <button type="button" class="btn btn-light btn-sm mb-3" id="ticketActivityToggle" aria-expanded="false">
                        <i class="fas fa-history me-1"></i>Show <?= $reply_counts['total'] - 4 ?> earlier update<?= $reply_counts['total'] - 4 === 1 ? '' : 's' ?>
                    </button>
                <?php } ?>
                </section>

            </div>

            <div class="col-lg-3 ticket-context-column">
                <div class="ticket-context-rail" id="ticket-context" role="region" aria-label="Ticket context" tabindex="0">

                <?php if ($level_alert_link) {
                    $level_alert_id = escapeHtml($level_alert_link['level_alert_id']);
                    $level_alert_name = escapeHtml($level_alert_link['level_alert_name']);
                    $level_alert_severity = escapeHtml(ucfirst($level_alert_link['level_alert_severity']));
                    $level_alert_started_at = escapeHtml($level_alert_link['level_alert_started_at']);
                    $level_alert_resolved_at = escapeHtml($level_alert_link['level_alert_resolved_at']);
                    $level_alert_asset_id = intval($level_alert_link['level_asset_id']);
                    $level_severity_badge = match (strtolower($level_alert_link['level_alert_severity'])) {
                        'emergency' => 'dark',
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'info',
                    };
                    ?>
                    <div class="card">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-satellite mr-2"></i>Level.io Alert</h5>
                            <div class="card-tools">
                                <span class="badge badge-<?= $level_alert_resolved_at ? 'success' : $level_severity_badge ?>"><?= $level_alert_resolved_at ? 'Resolved' : $level_alert_severity ?></span>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <strong><?= $level_alert_name ?></strong>
                            <?php if ($level_alert_started_at) { ?>
                                <div class="mt-2" title="<?= $level_alert_started_at ?>"><i class="fas fa-fw fa-clock text-secondary mr-2"></i>Started <?= escapeHtml(timeAgo($level_alert_started_at)) ?></div>
                            <?php } ?>
                            <?php if ($level_alert_resolved_at) { ?>
                                <div class="mt-2" title="<?= $level_alert_resolved_at ?>"><i class="fas fa-fw fa-check text-success mr-2"></i>Resolved <?= escapeHtml(timeAgo($level_alert_resolved_at)) ?></div>
                            <?php } ?>
                            <?php if ($level_alert_asset_id) { ?>
                                <div class="mt-2"><i class="fas fa-fw fa-desktop text-secondary mr-2"></i><a href="asset.php?asset_id=<?= $level_alert_asset_id ?>">Open managed asset</a></div>
                            <?php } ?>
                            <div class="mt-2 text-muted small text-truncate" title="<?= $level_alert_id ?>">Alert ID: <?= $level_alert_id ?></div>
                            <div class="mt-3 pt-2 border-top">
                                <a href="https://app.level.io/devices" target="_blank" rel="noopener noreferrer">Open Level devices <i class="fas fa-external-link-alt ml-1"></i></a>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <?php if ($automation_incident) {
                    $automation_source_raw = strtolower($automation_incident['automation_incident_source']);
                    $automation_source_name = match ($automation_source_raw) {
                        'uptime_kuma' => 'Uptime Kuma',
                        'netbox' => 'NetBox',
                        'n8n' => 'n8n',
                        'backup' => 'Backups',
                        'checkmk' => 'Checkmk',
                        'cipp' => 'CIPP',
                        'entra' => 'Microsoft Entra',
                        'infrastructure' => 'Infrastructure',
                        'intune' => 'Microsoft Intune',
                        'level', 'level_io' => 'Level.io',
                        'sentinelone' => 'SentinelOne',
                        default => ucwords(str_replace(['_', '-'], ' ', $automation_source_raw)),
                    };
                    $automation_source_icon = match ($automation_source_raw) {
                        'uptime_kuma' => 'fa-heartbeat',
                        'netbox' => 'fa-project-diagram',
                        'n8n' => 'fa-random',
                        'backup' => 'fa-database',
                        'checkmk' => 'fa-heartbeat',
                        'cipp', 'entra', 'intune' => 'fa-cloud',
                        'infrastructure' => 'fa-server',
                        'level', 'level_io' => 'fa-satellite',
                        'sentinelone' => 'fa-shield-alt',
                        default => 'fa-bolt',
                    };
                    $automation_incident_severity = strtolower($automation_incident['automation_incident_severity']);
                    $automation_incident_open = strtolower($automation_incident['automation_incident_status']) === 'open';
                    $automation_incident_badge = !$automation_incident_open ? 'success' : match ($automation_incident_severity) {
                        'emergency', 'critical' => 'danger',
                        'high', 'medium' => 'warning',
                        default => 'info',
                    };
                    ?>
                    <div class="card n45-automation-ticket-card">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw <?= $automation_source_icon ?> mr-2"></i>Automation incident</h5>
                            <div class="card-tools">
                                <span class="badge badge-<?= $automation_incident_badge ?>"><?= $automation_incident_open ? escapeHtml(ucfirst($automation_incident_severity)) . ' · Open' : 'Recovered' ?></span>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <strong><?= escapeHtml($automation_incident['automation_incident_title']) ?></strong>
                            <div class="text-muted small mt-1"><?= escapeHtml($automation_source_name) ?> · <?= intval($automation_incident['automation_incident_event_count']) ?> correlated event<?= intval($automation_incident['automation_incident_event_count']) === 1 ? '' : 's' ?><?= intval($automation_incident['automation_incident_repeat_count'] ?? 0) ? ' · ' . intval($automation_incident['automation_incident_repeat_count']) . ' repeats' : '' ?><?= intval($automation_incident['automation_incident_suppressed_count'] ?? 0) ? ' · ' . intval($automation_incident['automation_incident_suppressed_count']) . ' suppressed' : '' ?></div>
                            <?php if (!empty($automation_incident['service_name'])) { ?><div class="small mt-2"><i class="fas fa-fw fa-cube text-secondary mr-1"></i><?= escapeHtml($automation_incident['service_name']) ?></div><?php } ?>

                            <div class="n45-incident-facts mt-3">
                                <div>
                                    <span>Opened</span>
                                    <strong title="<?= escapeHtml($automation_incident['automation_incident_opened_at']) ?>"><?= $automation_incident['automation_incident_opened_at'] ? escapeHtml(timeAgo($automation_incident['automation_incident_opened_at'])) : 'Unknown' ?></strong>
                                </div>
                                <div>
                                    <span>Last signal</span>
                                    <strong title="<?= escapeHtml($automation_incident['automation_incident_last_event_at']) ?>"><?= $automation_incident['automation_incident_last_event_at'] ? escapeHtml(timeAgo($automation_incident['automation_incident_last_event_at'])) : 'Unknown' ?></strong>
                                </div>
                            </div>

                            <?php if ($automation_incident_events) { ?>
                                <div class="n45-incident-events">
                                    <span class="mb-1">Latest signals</span>
                                    <?php foreach ($automation_incident_events as $automation_event) {
                                        $event_state = strtolower($automation_event['automation_event_state']);
                                        $event_status = strtolower($automation_event['automation_event_status'] ?? 'processed');
                                        $event_badge = $event_status === 'dead' ? 'danger' : ($event_status === 'failed' ? 'warning' : ($event_state === 'resolved' ? 'success' : ($event_state === 'open' ? 'danger' : 'secondary')));
                                        $event_time = $automation_event['automation_event_last_received_at'] ?: $automation_event['automation_event_received_at'];
                                        ?>
                                        <div class="n45-incident-event">
                                            <div><span class="badge badge-<?= $event_badge ?> mr-1"><?= escapeHtml($event_status === 'processed' ? ucfirst($event_state) : ucfirst($event_status)) ?></span><?= escapeHtml(ucwords(str_replace('_', ' ', $automation_event['automation_event_action']))) ?><?= intval($automation_event['automation_event_delivery_count'] ?? 1) > 1 ? ' · ' . intval($automation_event['automation_event_delivery_count']) . ' deliveries' : '' ?><?= !empty($automation_event['automation_event_suppressed_reason']) ? ' · ' . escapeHtml(str_replace('_', ' ', $automation_event['automation_event_suppressed_reason'])) : '' ?></div>
                                            <small class="text-muted" title="<?= escapeHtml($event_time) ?>"><?= escapeHtml(timeAgo($event_time)) ?></small>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } ?>

                            <div class="mt-3 pt-2 border-top">
                                <a href="operations.php?source=<?= urlencode($automation_source_raw) ?><?= $automation_incident_open ? '#incident-' . intval($automation_incident['automation_incident_id']) : '#recent-activity' ?>">Open in Operations <i class="fas fa-arrow-right ml-1"></i></a>
                            </div>
                        </div>
                    </div>
                <?php } ?>

                <!-- Contact -->
                <?php
                // Rendered even with no contact set, since the Linked card's add menu
                // is for links - setting the ticket's contact belongs here
                $can_set_contact = $can_edit_ticket && !$ticket_is_resolved && $client_id;
                if ($contact_id || $can_set_contact) { ?>
                    <div class="card mb-3">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-user-check me-2"></i>Contact</h5>
                            <?php if ($can_set_contact) { ?>
                                <div class="card-tools">
                                    <a class="btn btn-tool ajax-modal" href="#" data-modal-url="modals/ticket/ticket_contact.php?id=<?= $ticket_id ?>" title="<?= $contact_id ? 'Change contact' : 'Set contact' ?>">
                                        <i class="fas fa-<?= $contact_id ? 'edit' : 'plus' ?>"></i>
                                    </a>
                                </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-3">

                            <?php if (!$contact_id) { ?>
                                <div class="text-muted small">No contact set on this ticket.</div>
                            <?php } else { ?>

                            <div>
                                <i class="fa fa-fw fa-user text-secondary me-2"></i>
                                <a href="#" class="ajax-modal" data-modal-size="lg" data-modal-url="modals/contact/contact.php?id=<?= $contact_id ?>"><strong><?= $contact_name ?></strong></a>
                                <?php if ($contact_title) { ?><div class="text-muted small ms-4"><?= $contact_title ?></div><?php } ?>
                            </div>

                            <?php if (!empty($location_name)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-map-marker-alt text-secondary me-2"></i><?= $location_name ?>
                                </div>
                            <?php } ?>

                            <?php if (!empty($contact_email)) { ?>
                                <div class="mt-2 text-truncate">
                                    <i class="fa fa-fw fa-envelope text-secondary me-2"></i><a href="mailto:<?= $contact_email ?>"><?= $contact_email ?></a>
                                </div>
                            <?php } ?>

                            <?php if (!empty($contact_phone)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-phone text-secondary me-2"></i><a href="tel:<?= $contact_phone ?>"><?= $contact_phone ?></a>
                                    <?php if ($contact_extension) { ?><span class="text-muted small">ext. <?= $contact_extension ?></span><?php } ?>
                                </div>
                            <?php } ?>

                            <?php if (!empty($contact_mobile)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-mobile-alt text-secondary me-2"></i><a href="tel:<?= $contact_mobile ?>"><?= $contact_mobile ?></a>
                                </div>
                            <?php } ?>

                            <?php if ($contact_open_tickets) { ?>
                                <div class="mt-2 pt-2 border-top">
                                    <i class="fa fa-fw fa-life-ring text-secondary me-2"></i>
                                    <a href="tickets.php?client_id=<?= $client_id ?>&state=open">
                                        <?= $contact_open_tickets ?> other open ticket<?= $contact_open_tickets == 1 ? '' : 's' ?>
                                    </a>
                                </div>
                            <?php } ?>

                            <?php } ?>

                        </div>
                    </div>
                <?php } ?>

                <!--
                    Everything else this ticket is attached to, in one card instead of
                    four stacked ones that were mostly empty on a typical ticket.
                -->
                <?php
                $can_link = $can_edit_ticket && !$ticket_is_closed;
                $has_links = $asset_id || $vendor_id || $project_id || $watcher_count;
                if ($has_links || $can_link) { ?>
                    <div class="card mb-3">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-link me-2"></i>Linked</h5>
                            <div class="card-tools">
                                <?php if ($can_link) { ?>
                                    <!-- Attaching things to the ticket belongs next to the things already attached -->
                                    <div class="dropdown dropstart d-inline-block">
                                        <button class="btn btn-tool" type="button" data-bs-toggle="dropdown" title="Link something to this ticket">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php if ($client_id) { ?>
                                                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_asset.php?id=<?= $ticket_id ?>">
                                                    <i class="fas fa-fw fa-desktop me-2"></i><?= $asset_id ? 'Change Assets' : 'Add Asset' ?>
                                                </a>
                                                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_vendor.php?ticket_id=<?= $ticket_id ?>">
                                                    <i class="fas fa-fw fa-building me-2"></i><?= $vendor_id ? 'Change Vendor' : 'Add Vendor' ?>
                                                </a>
                                                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_project.php?id=<?= $ticket_id ?>">
                                                    <i class="fas fa-fw fa-project-diagram me-2"></i><?= $project_id ? 'Change Project' : 'Add to Project' ?>
                                                </a>
                                                <div class="dropdown-divider"></div>
                                            <?php } ?>
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add_watcher.php?ticket_id=<?= $ticket_id ?>">
                                                <i class="fas fa-fw fa-users me-2"></i>Add Watcher
                                            </a>
                                        </div>
                                    </div>
                                <?php } ?>
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-3">

                            <?php if (!$has_links) { ?>
                                <div class="text-muted small">Nothing linked yet. Use <i class="fas fa-fw fa-plus"></i> to attach an asset, vendor, project or watcher.</div>
                            <?php } ?>

                            <?php if ($asset_id) { ?>
                                <div class="text-secondary small text-uppercase">Assets</div>
                                <div class="mt-1">
                                    <a class="ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/asset/asset.php?<?= $client_url ?>&id=<?= $asset_id ?>">
                                        <i class="fa fa-fw fa-<?= $asset_icon ?> text-secondary me-2"></i><strong><?= $asset_name ?></strong>
                                    </a>
                                </div>
                                <?php while ($additional_asset_row = mysqli_fetch_assoc($sql_additional_assets)) {
                                    $additional_asset_id = intval($additional_asset_row['asset_id']);
                                    $additional_asset_name = escapeHtml($additional_asset_row['asset_name']);
                                    $additional_asset_icon = getAssetIcon(escapeHtml($additional_asset_row['asset_type']));
                                    ?>
                                    <div class="mt-1">
                                        <a class="ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/asset/asset.php?<?= $client_url ?>&id=<?= $additional_asset_id ?>">
                                            <i class="fa fa-fw fa-<?= $additional_asset_icon ?> text-secondary me-2"></i><?= $additional_asset_name ?>
                                        </a>
                                        <?php if (!$ticket_is_closed) { ?>
                                            <a class="confirm-link float-end" href="post.php?delete_ticket_additional_asset=<?= $additional_asset_id ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" title="Remove asset from ticket">
                                                <i class="fas fa-fw fa-times text-secondary"></i>
                                            </a>
                                        <?php } ?>
                                    </div>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($vendor_id) { ?>
                                <div class="text-secondary small text-uppercase <?= $asset_id ? 'mt-3 pt-2 border-top' : '' ?>">Vendor</div>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-building text-secondary me-2"></i><strong><?= $vendor_name ?></strong>
                                </div>
                                <?php if ($ticket_vendor_ticket_number) { ?>
                                    <div class="mt-1"><i class="fa fa-fw fa-tag text-secondary me-2"></i><?= $ticket_vendor_ticket_number ?></div>
                                <?php } ?>
                                <?php if ($vendor_contact_name) { ?>
                                    <div class="mt-1"><i class="fa fa-fw fa-user text-secondary me-2"></i><?= $vendor_contact_name ?></div>
                                <?php } ?>
                                <?php if ($vendor_email) { ?>
                                    <div class="mt-1 text-truncate"><i class="fa fa-fw fa-envelope text-secondary me-2"></i><a href="mailto:<?= $vendor_email ?>"><?= $vendor_email ?></a></div>
                                <?php } ?>
                                <?php if ($vendor_phone) { ?>
                                    <div class="mt-1"><i class="fa fa-fw fa-phone text-secondary me-2"></i><a href="tel:<?= $vendor_phone ?>"><?= $vendor_phone ?></a></div>
                                <?php } ?>
                                <?php if ($vendor_website) { ?>
                                    <div class="mt-1 text-truncate"><i class="fa fa-fw fa-globe text-secondary me-2"></i><?= $vendor_website ?></div>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($project_id) { ?>
                                <div class="text-secondary small text-uppercase <?= ($asset_id || $vendor_id) ? 'mt-3 pt-2 border-top' : '' ?>">Project</div>
                                <div class="mt-1">
                                    <a href="project.php?project_id=<?= $project_id ?>"><i class="fa fa-fw fa-project-diagram text-secondary me-2"></i><strong><?= $project_name ?></strong></a>
                                </div>
                                <?php if ($project_manager_name) { ?>
                                    <div class="mt-1"><i class="fa fa-fw fa-user-tie text-secondary me-2"></i><?= $project_manager_name ?></div>
                                <?php } ?>
                            <?php } ?>

                            <?php if ($watcher_count) { ?>
                                <div class="text-secondary small text-uppercase <?= ($asset_id || $vendor_id || $project_id) ? 'mt-3 pt-2 border-top' : '' ?>">Watchers</div>
                                <?php while ($watcher_row = mysqli_fetch_assoc($sql_ticket_watchers)) {
                                    $watcher_id = intval($watcher_row['watcher_id']);
                                    $ticket_watcher_email = escapeHtml($watcher_row['watcher_email']);
                                    ?>
                                    <div class="mt-1 text-truncate">
                                        <i class="fa fa-fw fa-envelope text-secondary me-2"></i><?= $ticket_watcher_email ?>
                                        <?php if (!$ticket_is_closed) { ?>
                                            <a class="confirm-link float-end" href="post.php?delete_ticket_watcher=<?= $watcher_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-times text-secondary"></i>
                                            </a>
                                        <?php } ?>
                                    </div>
                                <?php } ?>
                            <?php } ?>

                        </div>
                    </div>
                <?php } ?>

                <!-- History - the audit trail, plus the SLA and closure detail -->
                <div class="card collapsed-card">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1"><i class="fas fa-fw fa-history me-2"></i>History</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool ticket-disclosure-toggle" data-lte-toggle="card-collapse"
                                    aria-expanded="false" aria-label="Show history" data-expanded-label="Collapse history" data-collapsed-label="Show history">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-3" style="display: none;">

                        <div>
                            <i class="fas fa-fw fa-calendar-alt text-secondary me-1"></i><strong class="me-1">Created:</strong><?= date('M d, Y • g:i A', strtotime($ticket_created_at)) ?>
                            <span class="text-muted small">(<?= $ticket_created_at_ago ?>)</span>
                        </div>

                        <?php if ($ticket_first_response_at) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-reply-all text-secondary me-1"></i><strong class="me-1">1st response:</strong><?= date('M d • g:i A', strtotime($ticket_first_response_at)) ?>
                            </div>
                        <?php } ?>

                        <?php if ($ticket_sla_id && $ticket_response_due_at) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-stopwatch text-secondary me-1"></i><strong class="me-1">Respond by:</strong><?= date('M d • g:i A', strtotime($ticket_response_due_at)) ?>
                                <?php if (!is_null($ticket_response_sla_met)) { echo $ticket_response_sla_met ? "<i class='fas fa-fw fa-check text-success ms-1' title='Response SLA met'></i>" : "<i class='fas fa-fw fa-exclamation-triangle text-danger ms-1' title='Response SLA missed'></i>"; } ?>
                            </div>
                        <?php } ?>

                        <?php if ($ticket_sla_id && $ticket_resolution_due_at) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-flag-checkered text-secondary me-1"></i><strong class="me-1">Resolve by:</strong>
                                <?php if ($ticket_sla_paused && $ticket_is_open) { ?>
                                    <span class="text-warning"><i class="fas fa-fw fa-pause-circle me-1"></i>Paused</span>
                                <?php } else { ?>
                                    <?= date('M d • g:i A', strtotime($ticket_resolution_due_at)) ?>
                                <?php } ?>
                                <?php if (!is_null($ticket_resolution_sla_met)) { echo $ticket_resolution_sla_met ? "<i class='fas fa-fw fa-check text-success ms-1' title='Resolution SLA met'></i>" : "<i class='fas fa-fw fa-exclamation-triangle text-danger ms-1' title='Resolution SLA missed'></i>"; } ?>
                            </div>
                        <?php } ?>

                        <?php if ($ticket_agreement_decision) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-file-contract text-secondary mr-1"></i><strong class="mr-1">SLA decision:</strong>
                                <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_reason']) ?>
                                <?php if (intval($ticket_agreement_decision['ticket_agreement_decision_contract_id']) > 0) { ?>
                                    <a class="ml-1" href="agreement.php?agreement_id=<?= intval($ticket_agreement_decision['ticket_agreement_decision_contract_id']) ?>&version_id=<?= intval($ticket_agreement_decision['ticket_agreement_decision_version_id']) ?>">
                                        <?= escapeHtml($ticket_agreement_decision['agreement_version_name'] ?: 'Agreement') ?> v<?= intval($ticket_agreement_decision['agreement_version_number']) ?>
                                    </a>
                                <?php } ?>
                                <div class="text-muted small ml-4">
                                    Request <code><?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_request_type_key']) ?></code>,
                                    priority <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_priority']) ?>,
                                    SLA <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_sla_name']) ?>
                                    (<?= is_null($ticket_agreement_decision['ticket_agreement_decision_response_minutes']) ? 'no' : intval($ticket_agreement_decision['ticket_agreement_decision_response_minutes']) . 'm' ?> response,
                                    <?= is_null($ticket_agreement_decision['ticket_agreement_decision_resolution_minutes']) ? 'no' : intval($ticket_agreement_decision['ticket_agreement_decision_resolution_minutes']) . 'm' ?> resolution,
                                    <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_calendar_mode']) ?>),
                                    source <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_source']) ?>
                                    <?php if (!empty($ticket_agreement_decision['ticket_agreement_decision_classification'])) { ?>,
                                        <?= escapeHtml($ticket_agreement_decision['ticket_agreement_decision_classification']) ?>;
                                    <?php } ?>
                                    <?php if (intval($ticket_agreement_decision['ticket_agreement_decision_schema_version']) === 1) { ?>
                                        SLA eligible <?= intval($ticket_agreement_decision['ticket_agreement_decision_sla_eligible']) ? 'yes' : 'no' ?>,
                                        onsite <?= intval($ticket_agreement_decision['ticket_agreement_decision_ticket_onsite']) ? 'yes' : 'no' ?>,
                                        billable <?= intval($ticket_agreement_decision['ticket_agreement_decision_ticket_billable']) ? 'yes' : 'no' ?>
                                    <?php } ?>
                                </div>
                                <?php foreach (($ticket_entitlement_evidence['resolution']['scopes'] ?? []) as $scope => $scope_evidence) { ?>
                                    <div class="text-muted small ml-4">
                                        <?= escapeHtml(ucfirst($scope)) ?>:
                                        <?= escapeHtml($scope_evidence['classification'] ?? 'unknown') ?> via
                                        <?= escapeHtml($scope_evidence['basis'] ?? 'unknown') ?>
                                        (measured <?= floatval($scope_evidence['measured_quantity'] ?? 0) ?><?php if (!is_null($scope_evidence['quantity_limit'] ?? null)) { ?>,
                                            limit <?= floatval($scope_evidence['quantity_limit']) ?><?php } ?>)
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } elseif ($ticket_agreement_decision_integrity_failed) { ?>
                            <div class="alert alert-danger mt-2 mb-0">
                                <i class="fas fa-fw fa-exclamation-triangle mr-1"></i>
                                The latest SLA decision failed its ticket-bound integrity check and was not displayed.
                            </div>
                        <?php } ?>

                        <?php if ($ticket_collaborators) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-users text-secondary me-1"></i><strong class="me-1">Worked by:</strong><?= $ticket_collaborators ?>
                            </div>
                        <?php } ?>

                        <?php if ($ticket_resolved_at) { ?>
                            <div class="mt-2" title="<?= $ticket_resolved_at ?>">
                                <i class="fas fa-fw fa-check text-secondary me-1"></i><strong class="me-1">Resolved:</strong><?= date('M d, Y • g:i A', strtotime($ticket_resolved_at)) ?>
                                <span class="text-muted small">(<?= $ticket_resolved_at_ago ?>)</span>
                            </div>
                        <?php } ?>

                        <?php if ($ticket_is_closed) { ?>
                            <div class="mt-2" title="<?= $ticket_closed_at ?>">
                                <i class="fas fa-fw fa-gavel text-secondary me-1"></i><strong class="me-1">Closed:</strong><?= date('M d, Y • g:i A', strtotime($ticket_closed_at)) ?>
                                <span class="text-muted small">(<?= $ticket_closed_at_ago ?>)</span>
                            </div>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-user text-secondary me-1"></i><strong class="me-1">Closed by:</strong><?= ucwords($ticket_closed_by_display) ?>
                            </div>
                            <?php if ($ticket_feedback) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-comment-dots text-secondary me-1"></i><strong class="me-1">Feedback:</strong><?= $ticket_feedback ?>
                                </div>
                            <?php } ?>
                        <?php } ?>

                        <?php if ($ticket_history_count) { ?>
                            <div class="mt-3 pt-2 border-top">
                                <div class="text-secondary small text-uppercase mb-2">Changes</div>
                                <?php while ($history_row = mysqli_fetch_assoc($sql_ticket_history)) {
                                    $ticket_history_description = escapeHtml($history_row['ticket_history_description']);
                                    $ticket_history_created_at = escapeHtml($history_row['ticket_history_created_at']);
                                    ?>
                                    <div class="mb-2 small">
                                        <i class="fas fa-fw fa-angle-right text-secondary me-1"></i><?= $ticket_history_description ?>
                                        <div class="text-muted ms-4" title="<?= $ticket_history_created_at ?>"><?= timeAgo($ticket_history_created_at) ?></div>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>

                    </div>
                </div>

                </div>

            </div> <!-- End col -->

        </div> <!-- End row -->

    <?php
    }
}

require_once "../includes/footer.php";

?>

<script src="/js/show_modals.js"></script>

<?php if (empty($ticket_closed_at)) { ?>
    <!-- create js variable related to ticket timer setting -->
    <script type="text/javascript">
        var ticketAutoStart = <?= json_encode($config_ticket_timer_autostart) ?>;
    </script>

    <!-- Ticket Time Tracking JS -->
    <script src="js/ticket_time_tracking.js"></script>

    <!-- Ticket collision detect JS (jQuery is called in footer, so collision detection script MUST be below it) -->
    <script src="js/ticket_collision_detection.js"></script>
<?php } ?>

<script src="/js/pretty_content.js"></script>

<script src="js/ticket_workspace.js?v=<?= filemtime(__DIR__ . '/js/ticket_workspace.js') ?>"></script>

<script src="/libs/SortableJS/Sortable.min.js"></script>
<script>
(function () {
    // Task reordering
    const taskTable = document.querySelector('table#tasks tbody');
    if (taskTable && <?= $runbook_execution ? 'false' : 'true' ?>) {
        new Sortable(taskTable, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: function () {
                const rows = document.querySelectorAll('table#tasks tbody tr');
                const positions = Array.from(rows).map((row, index) => ({
                    id: row.dataset.taskId,
                    order: index
                }));

                itflowPostForm('ajax.php', {
                    update_ticket_tasks_order: true,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>',
                    ticket_id: <?= $ticket_id ?>,
                    positions: positions
                });
            }
        });
    }

    // Canned responses - insert at the cursor rather than replacing, so picking one into a
    // half-written reply adds to it. The body is fetched on demand; TinyMCE owns the field
    // by now, so setting the textarea value directly would do nothing visible.
    const cannedPicker = document.getElementById('canned_response_picker');
    if (cannedPicker) {
        cannedPicker.addEventListener('change', function () {
            const cannedId = cannedPicker.value;
            if (!cannedId) return;

            cannedPicker.disabled = true;

            fetch('ajax.php?get_canned_response=' + encodeURIComponent(cannedId))
                .then(response => response.json())
                .then(data => {
                    const editor = window.tinymce ? tinymce.get('ticket_reply') : null;

                    if (editor) {
                        editor.insertContent(data.body);
                        editor.focus();
                    } else {
                        // TinyMCE failed to load - fall back to the plain textarea
                        const textarea = document.getElementById('ticket_reply');
                        if (textarea) {
                            textarea.value += data.body;
                        }
                    }
                })
                .catch(() => {
                    alert('Could not load that canned response.');
                })
                .finally(() => {
                    cannedPicker.disabled = false;
                    cannedPicker.value = '';
            });
        });
    }

    // Reply composer - the type buttons above are the entry point. The composer stays
    // collapsed until one is picked, so the conversation is not pushed down the page and
    // a public reply is never the default.
    const replyComposer = document.getElementById('replyComposer');
    if (replyComposer) {
        const replyComposerCollapse = bootstrap.Collapse.getOrCreateInstance(replyComposer, { toggle: false });
        const replyTypes = document.querySelectorAll('input[name="public_reply_type"]');

        // Only ever opens. Switching Internal -> Public mid-draft must not throw the draft away.
        replyTypes.forEach(radio => radio.addEventListener('change', () => replyComposerCollapse.show()));

        replyComposer.addEventListener('shown.bs.collapse', function () {
            // TinyMCE's autoresize plugin measured the editor while its container was
            // display:none, so it sized to nothing. Re-measure now the box is real.
            const editor = window.tinymce ? tinymce.get('ticket_reply') : null;

            if (editor) {
                editor.execCommand('mceAutoResize');
                editor.focus();
            } else {
                const textarea = document.getElementById('ticket_reply');
                if (textarea) textarea.focus();
            }
        });

        const cancelReply = document.getElementById('cancelReply');
        if (cancelReply) {
            cancelReply.addEventListener('click', function () {
                replyComposerCollapse.hide();
                replyTypes.forEach(radio => { radio.checked = false; });
            });
        }

        // The status select is required and lives inside the collapse. A required control in a
        // display:none container blocks submission with nothing on screen to explain it, and the
        // form can still be submitted with Enter from a focused reply-type button, so refuse
        // outright while the composer is shut.
        const replyForm = replyComposer.closest('form');
        if (replyForm) {
            replyForm.addEventListener('submit', function (e) {
                if (!replyComposer.classList.contains('show')) {
                    e.preventDefault();
                }
            });
        }
    }

    // Conversation filter - show everything, only what the client can see, or only internal notes
    const replyFilter = document.getElementById('replyFilter');
    if (replyFilter) {
        replyFilter.addEventListener('click', function (e) {
            const button = e.target.closest('[data-reply-filter]');
            if (!button) return;

            const wanted = button.dataset.replyFilter;

            replyFilter.querySelectorAll('[data-reply-filter]').forEach(b => {
                b.classList.toggle('btn-dark', b === button);
                b.classList.toggle('active', b === button);
                b.classList.toggle('btn-outline-dark', b !== button);
            });

            document.querySelectorAll('.ticket-reply').forEach(card => {
                card.hidden = wanted !== 'all' && card.dataset.replyGroup !== wanted;
            });
        });
    }
})();
</script>
