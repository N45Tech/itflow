<?php

/**
 * Contract: soft-deleted tickets are visible only to the retention workflow.
 *
 * This deliberately inventories SQL string literals across every ordinary
 * application surface. A newly added ticket query fails this test unless its
 * own statement carries the active-record predicate or it is one of the
 * narrow immutable-history checks documented below.
 */

$root = dirname(__DIR__);
$failures = [];
$exception_hits = [];

$assertContains = static function (string $needle, string $contents, string $message) use (&$failures): void {
    if (strpos($contents, $needle) === false) {
        $failures[] = $message . " (missing: $needle)";
    }
};

$assertBlockContains = static function (
    string $contents,
    string $start,
    string $end,
    string $needle,
    string $message
) use (&$failures): void {
    $start_offset = strpos($contents, $start);
    $end_offset = $start_offset === false ? false
        : ($end === '' ? strlen($contents) : strpos($contents, $end, $start_offset + strlen($start)));
    if ($start_offset === false || $end_offset === false
        || strpos(substr($contents, $start_offset, $end_offset - $start_offset), $needle) === false) {
        $failures[] = $message . " (block missing: $needle)";
    }
};

$read = static function (string $path) use ($root, &$failures): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        $failures[] = "Could not read $path";
        return '';
    }
    return $contents;
};

/** Reconstruct PHP string literals, including interpolated SQL strings. */
$phpStrings = static function (string $source): array {
    $tokens = token_get_all($source);
    $strings = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = substr($token[1], 1, -1);
            continue;
        }
        if ($token === '"') {
            $buffer = '';
            for ($i++; $i < $count && $tokens[$i] !== '"'; $i++) {
                $buffer .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }
            $strings[] = $buffer;
            continue;
        }
        if (is_array($token) && $token[0] === T_START_HEREDOC) {
            $buffer = '';
            for ($i++; $i < $count; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_END_HEREDOC) {
                    break;
                }
                $buffer .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }
            $strings[] = $buffer;
        }
    }
    return $strings;
};

/**
 * These statements must see deleted tickets because they prevent destruction
 * of immutable history. They are not application display or mutation paths.
 */
$historicalException = static function (string $relative, string $sql): ?string {
    if (($relative === 'agent/ticket_list.php' || $relative === 'agent/ticket_kanban.php')
        && strpos($sql, '$ticket_where') !== false) {
        return 'shared_ticket_where';
    }
    if ($relative === 'agent/post/project.php'
        && strpos($sql, 'ticket_project_id = $project_id') !== false
        && (strpos($sql, 'SELECT COUNT(*) FROM tickets') !== false
            || strpos($sql, 'FROM runbook_executions re') !== false)) {
        return 'project_delete_history_blocker';
    }
    if ($relative === 'functions/agreements.php'
        && strpos($sql, 'ticket_sla_response_minutes_snapshot') !== false
        && (strpos($sql, 'ticket_agreement_decision_ticket_id = $ticket_id') !== false
            || strpos($sql, 'ticket_agreement_decision_client_id = $client_id') !== false)) {
        return 'agreement_sla_immutable_history';
    }
    if ($relative === 'functions/ticket_operations.php'
        && strpos($sql, 'FROM ticket_relationships relationships') !== false
        && strpos($sql, "'[Deleted ticket unavailable]'") !== false
        && strpos($sql, '$source_available') !== false
        && strpos($sql, '$target_available') !== false) {
        return 'masked_relationship_history';
    }
    return null;
};

$directories = ['agent', 'client', 'api', 'guest', 'cron', 'functions'];
$inventory_count = 0;
foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($relative === 'functions/retention.php') {
            // The administrator-only retention service must inspect both active
            // and deleted rows in order to preview, restore, and purge them.
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            $failures[] = "Could not inventory $relative";
            continue;
        }
        foreach ($phpStrings($source) as $sql) {
            if (!preg_match_all('/\b(FROM|JOIN|UPDATE|DELETE\s+FROM)\s+`?tickets`?\b/i', $sql, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $match_count = count($matches[0]);
            for ($index = 0; $index < $match_count; $index++) {
                $inventory_count++;
                $start = $matches[0][$index][1];
                $end = $index + 1 < $match_count ? $matches[0][$index + 1][1] : strlen($sql);
                $statement_segment = substr($sql, $start, $end - $start);
                if (preg_match('/^DELETE\s+FROM/i', trim($statement_segment))) {
                    $failures[] = "$relative contains a direct ticket hard-delete outside retention";
                    continue;
                }
                if (stripos($statement_segment, 'ticket_deleted_at') !== false) {
                    continue;
                }
                $exception = $historicalException($relative, $sql);
                if ($exception !== null) {
                    $exception_hits[$exception] = ($exception_hits[$exception] ?? 0) + 1;
                    continue;
                }
                $snippet = preg_replace('/\s+/', ' ', trim($statement_segment));
                $failures[] = "$relative has an unfiltered ticket query: " . substr($snippet, 0, 180);
            }
        }
    }
}

if ($inventory_count < 250) {
    $failures[] = "Ticket SQL inventory unexpectedly covered only $inventory_count statements";
}
foreach (['shared_ticket_where', 'project_delete_history_blocker', 'agreement_sla_immutable_history', 'masked_relationship_history'] as $expected) {
    if (empty($exception_hits[$expected])) {
        $failures[] = "The documented $expected exception was not exercised";
    }
}

// The two dynamic list renderers are safe only because tickets.php owns one
// active-record WHERE fragment used by both views.
$tickets_page = $read('agent/tickets.php');
$assertContains('AND ticket_deleted_at IS NULL', $tickets_page,
    'The shared ticket-list predicate no longer excludes deleted tickets');

// High-risk access, transition, and task paths get explicit regression checks
// in addition to the global statement inventory.
$runbooks = $read('functions/runbooks.php');
$assertContains('WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE', $runbooks,
    'The central workflow ticket lock can acquire a deleted ticket');

$runbook_export = $read('agent/runbook_export.php');
$assertContains('FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1', $runbook_export,
    'Runbook closeout initial authorization exposes deleted tickets');
$assertContains('FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE', $runbook_export,
    'Runbook closeout lock recheck accepts deleted tickets');
$assertContains('INNER JOIN tickets ON ticket_id = runbook_execution_ticket_id AND ticket_deleted_at IS NULL', $runbook_export,
    'Runbook closeout execution load exposes deleted tickets');

$logging = $read('functions/logging.php');
$assertContains('WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL', $logging,
    'Ticket status logging can read a deleted ticket as an active mutation target');
$assertContains('if (!$sql || !mysqli_num_rows($sql))', $logging,
    'Ticket history still inserts an orphan when the active parent is unavailable');
$assertContains('return false;', $logging,
    'Ticket history does not fail closed when the active parent is unavailable');

$ticket_post = $read('agent/post/ticket.php');
$assertContains('WHERE ticket_deleted_at IS NULL', $ticket_post,
    'Ticket export does not directly exclude deleted records');
$assertContains('AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE', $ticket_post,
    'Ticket client-transfer lock does not recheck deletion state');
$assertBlockContains($ticket_post, "if (isset(\$_POST['add_invoice_from_ticket']))",
    "if (isset(\$_POST['add_quote_from_ticket']))",
    'FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1',
    'Invoice creation can append a reply to a concurrently deleted ticket');
$assertBlockContains($ticket_post, "if (isset(\$_POST['add_quote_from_ticket']))",
    "if (isExportRequest('export_tickets'))",
    'FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1',
    'Quote creation can append a reply to a concurrently deleted ticket');
$assertBlockContains($ticket_post, "if (isset(\$_POST['edit_ticket_schedule']))",
    "if (isset(\$_GET['cancel_ticket_schedule']))",
    'FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1',
    'Scheduling can append a reply to a concurrently deleted ticket');
$assertBlockContains($ticket_post, "if (isset(\$_GET['cancel_ticket_schedule']))",
    '',
    'FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1',
    'Schedule cancellation can append a reply to a concurrently deleted ticket');
$assertContains('INNER JOIN tickets t ON t.ticket_id = tw.watcher_ticket_id AND t.ticket_deleted_at IS NULL',
    $ticket_post, 'Watcher notification reads can traverse a deleted ticket');

$task_post = $read('agent/post/task.php');
$assertContains('WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1', $task_post,
    'Task mutations authorize through deleted tickets');
$assertContains('INNER JOIN tickets ON ticket_id = task_ticket_id AND ticket_deleted_at IS NULL', $task_post,
    'Task approval mutations can traverse a deleted ticket');

$ajax = $read('agent/ajax.php');
$assertContains('WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1 FOR UPDATE', $ajax,
    'Task ordering does not lock and recheck the active ticket');
$assertContains("WHERE ticket_id = \$ticket_id\n            AND ticket_deleted_at IS NULL", $ajax,
    'The AI summary can read a deleted ticket');
$assertContains('SELECT ticket_id, $session_user_id, NOW() FROM tickets', $ajax,
    'Ticket view tracking can create an orphan without selecting its parent');
$assertContains("WHERE ticket_id = \$ticket_id AND ticket_deleted_at IS NULL \"\n        . clientScopeSql('ticket_client_id')", $ajax,
    'Ticket view tracking is not active-record and client scoped');
$assertContains('INNER JOIN tickets ON tickets.ticket_id = view_ticket_id', $ajax,
    'Ticket viewer lookup does not require a live parent');
$assertContains('AND tickets.ticket_deleted_at IS NULL', $ajax,
    'Ticket viewer lookup exposes deleted ticket activity');
$assertContains("clientScopeSql('tickets.ticket_client_id')", $ajax,
    'Ticket viewer lookup is not client scoped');

$files = $read('functions/files.php');
$assertContains('FROM tickets WHERE ticket_id = $ticket_id AND ticket_deleted_at IS NULL LIMIT 1', $files,
    'Attachment metadata can be created for a deleted ticket');

$technician_time = $read('api/v1/technicians/time.php');
$assertContains('INNER JOIN tickets t ON t.ticket_id = tr.ticket_reply_ticket_id AND t.ticket_deleted_at IS NULL', $technician_time,
    'Technician time API includes deleted tickets');

$calendar = $read('agent/calendar.php');
$assertContains('LEFT JOIN tickets ON client_id = ticket_client_id AND ticket_deleted_at IS NULL', $calendar,
    'Calendar events can expose deleted tickets');

$portal_index = $read('client/index.php');
$assertContains('AND ticket_deleted_at IS NULL', $portal_index,
    'Client dashboard list or count includes deleted tickets');

$ticket_view = $read('agent/ticket.php');
$assertContains('if (!empty($session_is_admin) && $ticket_is_closed)', $ticket_view,
    'Retention action is not limited to administrators viewing a closed active ticket');

if ($failures) {
    fwrite(STDERR, "Deleted-ticket visibility contract failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Deleted-ticket visibility contract passed ($inventory_count ticket SQL statements inventoried).\n";
