<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') {
    exit("Disposable database required.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__);
$source = file_get_contents($root . '/cron/ticket_email_parser.php');
$start = strpos($source, 'function addTicket(');
$end = strpos($source, '/** ------------------------------------------------------------------', $start);
eval(substr($source, $start, $end - $start));
$assert = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function ($call, $message) { try { $call(); } catch (Throwable $error) { return; } throw new RuntimeException($message); };
$scalar = static fn($sql) => mysqli_fetch_row(ticketEmailDb($sql))[0];
$id = static fn() => intval(mysqli_insert_id($mysqli));
$session_is_admin = true; $client_access_array = $client_deny_array = [];
$session_ip = '127.0.0.1'; $session_user_agent = 'Inbound mail CI'; $session_user_id = 0;
$config_app_name = 'Inbound mail fixture'; $config_base_url = 'example.invalid';
$config_ticket_prefix = 'MAIL'; $config_ticket_default_billable = 0;
$config_ticket_from_email = 'support@example.invalid'; $config_ticket_from_name = 'Fixture support';
$config_ticket_client_general_notifications = 1; $config_ticket_new_ticket_notification_email = '';
$company_name = 'Fixture company'; $company_phone = '';
$allowed_extensions = ['pdf', 'txt'];
if (($argv[1] ?? '') === '--concurrent-worker') {
    $client = intval($argv[2]); $contact = intval($argv[3]);
    $result = ticketEmailProcess("concurrency.imap.invalid\nsupport@example.invalid",
        "Message-ID: <concurrent@example.invalid>\r\n\r\nConcurrent delivery\r\n",
        static function () use ($client, $contact) {
            usleep(250000);
            return addTicket($contact, 'Mail contact', 'mail-fixture@example.invalid', $client,
                '2026-09-06 12:00:00', 'Concurrent mail fixture', 'One ticket', [], []);
        });
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}
ticketEmailDb("INSERT INTO clients SET client_name = 'Inbound mail client', client_currency_code = 'USD', client_net_terms = 30"); $client = $id();
ticketEmailDb("INSERT INTO contacts SET contact_name = 'Mail contact', contact_email = 'mail-fixture@example.invalid', contact_client_id = $client"); $contact = $id();
ticketEmailDb("INSERT INTO clients SET client_name = 'Other mail client', client_currency_code = 'USD', client_net_terms = 30"); $foreign = $id();
ticketEmailDb("INSERT INTO contacts SET contact_name = 'Other mail contact', contact_email = 'foreign-mail@example.invalid', contact_client_id = $foreign");
$mailbox = 'fixture.imap.invalid' . "\n" . 'support@example.invalid';
$raw = static fn($key) => "Message-ID: <$key@example.invalid>\r\nFrom: mail-fixture@example.invalid\r\nSubject: Fixture\r\n\r\nOriginal $key\r\n";
$attachments = [['name' => "check's result.pdf", 'content' => "%PDF-1.4\nfixture"], ['name' => 'blocked.php', 'content' => 'not executable']];
$create = static fn() => addTicket($contact, 'Mail contact', 'mail-fixture@example.invalid', $client, '2026-09-06 12:00:00', 'Mail fixture', '<p>Need assistance.</p>', $attachments, []);
$queued_before = intval($scalar('SELECT COUNT(*) FROM email_queue'));
$one = ticketEmailProcess($mailbox, $raw('receipt-one'), $create);
$ticket = $one['ticket_id'];
$assert($ticket > 0 && !$one['replayed'], 'First message did not create a ticket');
$assert(intval($scalar("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket")) === 2, 'Original/allowed file missing or blocked extension accepted');
$files = ticketEmailDb("SELECT * FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket");
while ($file = mysqli_fetch_assoc($files)) {
    $path = "$root/uploads/tickets/$ticket/" . $file['ticket_attachment_reference_name'];
    $expected = $file['ticket_attachment_name'] === 'Original-parsed-email.eml' ? $raw('receipt-one') : $attachments[0]['content'];
    $assert(is_file($path) && file_get_contents($path) === $expected, 'Attachment bytes differ or are missing');
}
$assert(intval($scalar('SELECT COUNT(*) FROM email_queue')) === $queued_before + 1, 'Receipt did not enqueue one acknowledgement');
// Simulate process termination after commit but before mailbox acknowledgement.
$two = ticketEmailProcess($mailbox, $raw('receipt-one'), static function () { throw new RuntimeException('Replay executed the processor'); });
$assert($two['replayed'] && $two['ticket_id'] === $ticket, 'Retry did not reuse the committed ticket');
$assert(intval($scalar('SELECT COUNT(*) FROM email_queue')) === $queued_before + 1, 'Retry queued another acknowledgement');
$assert(intval($scalar("SELECT COUNT(*) FROM email_ingestion_actions WHERE action_receipt_id = {$one['receipt_id']}")) === 1, 'Retry duplicated a downstream action');
$reject(fn() => ticketEmailProcess($mailbox, $raw('receipt-one') . 'changed', $create), 'Changed content reused a Message-ID');
$assert($scalar("SELECT receipt_status FROM email_ingestion_receipts WHERE receipt_id = {$one['receipt_id']}") === 'Completed', 'Conflicting delivery corrupted a completed receipt');
$other_mailbox = ticketEmailProcess('different mailbox', $raw('receipt-one'), $create);
$assert($other_mailbox['ticket_id'] !== $ticket, 'Separate mailboxes shared a receipt');
$no_id = "From: mail-fixture@example.invalid\r\n\r\nNo message identity\r\n";
$first_no_id = ticketEmailProcess($mailbox, $no_id, $create);
$assert(ticketEmailProcess($mailbox, $no_id, $create)['ticket_id'] === $first_no_id['ticket_id'], 'Missing Message-ID retry duplicated intake');

// Independent connections race the same receipt; only one executes intake.
$workers = [];
for ($worker = 0; $worker < 4; $worker++) {
    $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--concurrent-worker', (string) $client, (string) $contact],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root);
    $assert(is_resource($process), 'Could not start the concurrent mail test');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
}
$worker_tickets = []; $replayed_workers = 0;
foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0, 'Concurrent intake failed: ' . $error . $output);
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $worker_tickets[] = $result['ticket_id'];
    $replayed_workers += intval($result['replayed']);
}
$assert(count(array_unique($worker_tickets)) === 1 && $replayed_workers === 3, 'Concurrent delivery created more than one ticket');

// A final-path failure after writing the original must leave no ticket, file
// references, acknowledgements or action rows. The raw source stays recoverable.
$blocked_path = null;
$blocked_ticket = null;
$before_tickets = intval($scalar('SELECT COUNT(*) FROM tickets'));
$before_queue = intval($scalar('SELECT COUNT(*) FROM email_queue'));
$before_actions = intval($scalar('SELECT COUNT(*) FROM email_ingestion_actions'));
$fail_create = static function () use ($mysqli, $root, $create, &$blocked_path, &$blocked_ticket) {
    global $ticket_email_context;
    $blocked_ticket = intval(mysqli_fetch_row(ticketEmailDb("SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tickets'"))[0]);
    $reference = hash('sha256', 'mail:' . $ticket_email_context['id'] . ':1:' . $ticket_email_context['content_hash']) . '.pdf';
    $blocked_path = "$root/uploads/tickets/$blocked_ticket/$reference";
    mkdir($blocked_path, 0770, true);
    return $create();
};
$reject(fn() => ticketEmailProcess($mailbox, $raw('failed-create'), $fail_create), 'Failed attachment write reported success');
$assert(intval($scalar('SELECT COUNT(*) FROM tickets')) === $before_tickets, 'Failed file storage left a ticket');
$assert(intval($scalar('SELECT COUNT(*) FROM email_queue')) === $before_queue, 'Failed file storage queued an acknowledgement');
$assert(intval($scalar('SELECT COUNT(*) FROM email_ingestion_actions')) === $before_actions, 'Failed file storage queued an action');
$assert(intval($scalar("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = $blocked_ticket")) === 0, 'Failed write left attachment records');
$identity = ticketEmailIdentity($mailbox, $raw('failed-create'));
$source_path = "$root/uploads/.mail-ingestion/{$identity['mailbox_hash']}-{$identity['message_hash']}.eml";
$assert(file_get_contents($source_path) === $raw('failed-create'), 'Failed intake lost its recoverable source');
rmdir($blocked_path);
$recovered = ticketEmailProcess($mailbox, $raw('failed-create'), $create);
$assert($recovered['ticket_id'] > 0 && !is_file($source_path), 'Storage recovery did not finish intake');

// Failure after all ticket side effects still rolls the whole transaction back.
$before_tickets = intval($scalar('SELECT COUNT(*) FROM tickets'));
$before_queue = intval($scalar('SELECT COUNT(*) FROM email_queue'));
$reject(fn() => ticketEmailProcess($mailbox, $raw('pre-commit-failure'), static function () use ($create) { $create(); throw new RuntimeException('Injected interruption'); }), 'Pre-commit interruption reported success');
$assert(intval($scalar('SELECT COUNT(*) FROM tickets')) === $before_tickets, 'Pre-commit interruption kept a ticket');
$assert(intval($scalar('SELECT COUNT(*) FROM email_queue')) === $before_queue, 'Pre-commit interruption kept queued mail');
$recovered = ticketEmailProcess($mailbox, $raw('pre-commit-failure'), $create);
$assert($recovered['ticket_id'] > 0, 'Pre-commit retry did not recover');

$number = intval($scalar("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket"));
$reply = static fn() => addReply('mail-fixture@example.invalid', '2026-09-06 13:00:00', 'Reply', $number, '<p>Reply body</p>', $attachments);
ticketEmailDb("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW(), ticket_resolution_code = 'fixed', ticket_resolution_summary = 'Fixture resolved' WHERE ticket_id = $ticket");
$reply_result = ticketEmailProcess($mailbox, $raw('resolved-reply'), $reply);
$assert($reply_result['reply_id'] > 0, 'Resolved-ticket reply missing');
$assert(intval($scalar("SELECT ticket_status FROM tickets WHERE ticket_id = $ticket")) === 2 && $scalar("SELECT ticket_resolved_at FROM tickets WHERE ticket_id = $ticket") === null, 'Resolved ticket did not reopen');
$assert(ticketEmailProcess($mailbox, $raw('resolved-reply'), $reply)['reply_id'] === $reply_result['reply_id'], 'Reply retry duplicated history');
$before_replies = intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket"));
$fail_reply = static function () use ($root, $ticket, $reply, &$blocked_path) {
    global $ticket_email_context;
    $reference = hash('sha256', 'mail:' . $ticket_email_context['id'] . ':1:' . $ticket_email_context['content_hash']) . '.pdf';
    $blocked_path = "$root/uploads/tickets/$ticket/$reference";
    mkdir($blocked_path);
    return $reply();
};
$reject(fn() => ticketEmailProcess($mailbox, $raw('failed-reply'), $fail_reply), 'Reply storage failure reported success');
$assert(intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket")) === $before_replies, 'Failed reply left partial history');
rmdir($blocked_path);
$reply_recovered = ticketEmailProcess($mailbox, $raw('failed-reply'), $reply);
$assert($reply_recovered['reply_id'] > 0, 'Reply storage retry did not recover');

ticketEmailDb("UPDATE tickets SET ticket_status = 5, ticket_closed_at = NOW(), ticket_resolved_at = NOW() WHERE ticket_id = $ticket");
$closed_at = $scalar("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $ticket");
$closed = ticketEmailProcess($mailbox, $raw('closed-reply'), $reply);
$assert($closed['kind'] === 'closed_reply' && $closed['reply_id'] > 0, 'Closed reply was discarded');
$assert(intval($scalar("SELECT ticket_status FROM tickets WHERE ticket_id = $ticket")) === 5 && $scalar("SELECT ticket_closed_at FROM tickets WHERE ticket_id = $ticket") === $closed_at, 'Closed reply changed closure');
$assert(ticketEmailProcess($mailbox, $raw('closed-reply'), $reply)['reply_id'] === $closed['reply_id'], 'Closed reply replay duplicated history');
$before_replies = intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket"));
$before_queue = intval($scalar('SELECT COUNT(*) FROM email_queue'));
$foreign_reply = ticketEmailProcess($mailbox, $raw('foreign-reply'), static fn() => addReply('foreign-mail@example.invalid', '2026-09-06 14:00:00', 'Foreign', $number, 'Blocked', []));
$assert($foreign_reply['kind'] === 'rejected', 'Foreign sender accepted on a closed ticket');
$assert(intval($scalar("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket")) === $before_replies && intval($scalar('SELECT COUNT(*) FROM email_queue')) === $before_queue, 'Foreign sender wrote history or received ticket information');
ticketEmailDb("UPDATE contacts SET contact_archived_at = NOW() WHERE contact_id = $contact");
$archived = ticketEmailProcess($mailbox, $raw('archived-sender'), $reply);
$assert($archived['kind'] === 'rejected', 'Archived primary contact replied');

// A crash during custom-action delivery is reclaimable with the same key.
$action = ticketEmailProcessAction();
$assert($action['status'] === 'delivered', 'Durable custom action did not deliver');
$assert($scalar("SELECT action_status FROM email_ingestion_actions WHERE action_receipt_id = {$one['receipt_id']}") === 'Delivered', 'Action delivery was not acknowledged');
$lease_id = $other_mailbox['receipt_id'];
$key_before = $scalar("SELECT action_event_key FROM email_ingestion_actions WHERE action_receipt_id = $lease_id");
ticketEmailDb("UPDATE email_ingestion_actions SET action_status = 'Processing', action_processing_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE), action_lease_token = REPEAT('a',64) WHERE action_receipt_id = $lease_id");
$reclaimed = ticketEmailProcessAction();
$assert($reclaimed['status'] === 'delivered' && $reclaimed['receipt_id'] === $lease_id, 'Stranded action lease was not recovered');
$assert($scalar("SELECT action_event_key FROM email_ingestion_actions WHERE action_receipt_id = $lease_id") === $key_before, 'Recovery changed downstream idempotency key');
echo "Inbound mail database assertions passed: intake/reply replay, commit interruption, storage failures/recovery, original files, outbox, closed history, sender isolation.\n";
