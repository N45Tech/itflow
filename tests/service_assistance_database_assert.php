<?php
// Real SQL and transaction checks; the release harness supplies a disposable database only.
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') { exit("Disposable database required.\n"); }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_set_charset($mysqli, 'utf8mb4');
date_default_timezone_set('America/New_York');
fieldDb("SET time_zone = '" . date('P') . "'");
$session_ip = '127.0.0.1'; $session_user_agent = 'Service assistance CI'; $session_name = 'Assistance CI';
$client_access_array = $client_deny_array = []; $_FILES = [];
$assert = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function ($fn, $message) { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException($message); };
$scalar = static fn ($sql) => mysqli_fetch_row(fieldDb($sql))[0] ?? null;
$id = static fn () => (int) mysqli_insert_id($mysqli);
$call = static function ($action, $data) { global $session_user_id; $data['request_key'] ??= assistanceUuid(); return assistanceWrite($action, $data, $session_user_id); };
$as = static function ($user) { global $session_user_id, $session_user_role, $session_is_admin; [$session_user_id, $session_user_role] = $user; $session_is_admin = false; };
$users = [];
foreach ([[2,2],[3,2],[1,1],[3,0],[3,2]] as $i => [$support, $client_level]) {
    fieldDb("INSERT INTO user_roles SET role_name = 'Assistance fixture $i', role_is_admin = 0"); $role = $id();
    foreach (['module_support'=>$support, 'module_client'=>$client_level] as $module=>$level) {
        $module_id = (int) $scalar('SELECT module_id FROM modules WHERE module_name = ' . fieldSql($module) . ' LIMIT 1');
        if (!$module_id) { fieldDb('INSERT INTO modules SET module_name = ' . fieldSql($module)); $module_id = $id(); }
        fieldDb("INSERT INTO user_role_permissions SET user_role_id = $role, module_id = $module_id, user_role_permission_level = $level");
    }
    fieldDb("INSERT INTO users SET user_name = 'Assistance technician $i', user_email = 'assist-$i@example.invalid', user_password = 'fixture', user_role_id = $role");
    $users[] = [$id(), $role];
}
[$author, $reviewer, $reader, $no_docs, $denied] = $users; $as($author);
foreach (['Assistance client', 'Assistance foreign client'] as $name) {
    fieldDb('INSERT INTO clients SET client_name = ' . fieldSql($name) . ", client_currency_code = 'USD', client_net_terms = 30"); $clients[] = $id();
}
[$client,$foreign] = $clients;
fieldDb("INSERT INTO user_client_permissions SET user_id = {$denied[0]}, client_id = $client, permission_type = 'deny'");
$make_ticket = static function ($subject, $client_id, $solved = false) use ($author, $id) {
    fieldDb('INSERT INTO tickets SET ticket_subject = ' . fieldSql($subject) . ", ticket_details = 'Investigate the DNS resolver timeout',
        ticket_prefix = 'SA-', ticket_number = 9000, ticket_priority = 'Medium', ticket_status = " . ($solved ? 5 : 2)
        . ", ticket_client_id = $client_id, ticket_created_by = {$author[0]}, ticket_assigned_to = {$author[0]}"
        . ($solved ? ", ticket_resolution_code = 'fixed', ticket_resolution_summary = 'Restored DNS resolution and verified queries', ticket_resolved_at = NOW(), ticket_closed_at = NOW()" : ''));
    return $id();
};
$ticket = $make_ticket('DNS resolver timeout', $client);
$solved = $make_ticket('DNS resolver recovered', $client, true);
$foreign_ticket = $make_ticket('Foreign DNS resolver', $foreign, true);
$service_ticket = $make_ticket('Unusual zyxw condition', $client, true);
fieldDb("UPDATE tickets SET ticket_resolution_summary = 'Replaced the qrst component' WHERE ticket_id = $service_ticket");
$unrelated = $make_ticket('Unrelated qqqqq', $client, true);
fieldDb("UPDATE tickets SET ticket_resolution_summary = 'Unrelated vvvvv result' WHERE ticket_id = $unrelated");
fieldDb("UPDATE tickets SET ticket_waiting_on = 'vendor', ticket_next_action = 'Obtain the vendor response', ticket_next_action_due_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE ticket_id = $ticket");
fieldDb("INSERT INTO ticket_customer_promises SET ticket_customer_promise_ticket_id = $ticket, ticket_customer_promise_client_id = $client,
    ticket_customer_promise_summary = 'Call the site manager', ticket_customer_promise_due_at = DATE_SUB(NOW(), INTERVAL 3 DAY), ticket_customer_promise_created_by = {$author[0]}"); $promise = $id();
fieldDb("INSERT INTO ticket_approvals SET ticket_approval_scope = 'client', ticket_approval_type = 'any', ticket_approval_status = 'pending',
    ticket_approval_created_by = {$author[0]}, ticket_approval_url_key = 'private-fixture-approval-token', ticket_approval_ticket_id = $ticket,
    ticket_approval_created_at = DATE_SUB(NOW(), INTERVAL 3 DAY)"); $approval = $id();
fieldDb("INSERT INTO tasks SET task_name = 'Validate the resolver', task_ticket_id = $ticket"); $task = $id();
fieldDb("INSERT INTO task_approvals SET approval_scope = 'internal', approval_type = 'specific', approval_status = 'pending',
    approval_created_by = {$author[0]}, approval_required_user_id = {$reviewer[0]}, approval_url_key = 'private-fixture-task-token', approval_task_id = $task,
    approval_created_at = DATE_SUB(NOW(), INTERVAL 3 DAY)"); $task_approval = $id();
fieldDb("INSERT INTO field_blockers SET blocker_ticket_id = $ticket, blocker_client_id = $client, blocker_kind = 'parts',
    blocker_title = 'Replacement needed', blocker_details = 'Supplier response needed', blocker_impact = 'Install is blocked',
    blocker_owner_id = {$author[0]}, blocker_created_by = {$author[0]}, blocker_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY),
    blocker_created_at = UTC_TIMESTAMP(), blocker_updated_at = UTC_TIMESTAMP()"); $blocker = $id();
$queue = followupQueue(['scope'=>'all','client_id'=>$client], $author[0]);
$assert($queue['total'] === 5, 'Queue omitted a canonical source');
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 1, 'Ticket filter duplicated a ticket with five due sources');
$assert(!str_contains(json_encode($queue), 'private-fixture'), 'Approval token leaked in queue output');
$assert(followupQueue(['client_query'=>'Assistance client','scope'=>'all','ticket_id'=>$ticket], $author[0])['total'] === 5, 'Client-name filter lost records');
foreach ($queue['items'] as $f) {
    $raw = $f['kind'] === 'blocker' ? $scalar("SELECT blocker_due_at FROM field_blockers WHERE blocker_id = $blocker")
        : ($f['kind'] === 'next_action' ? $scalar("SELECT ticket_next_action_due_at FROM tickets WHERE ticket_id = $ticket") : null);
    if ($raw) { $assert($f['source_due_at'] === ($f['kind'] === 'blocker' ? fieldUtc($raw) : fieldLocalTime($raw)), 'Local and UTC deadlines were mixed'); }
}
$key = "promise:$promise"; $f = followupDetail($ticket, $key); $original = $f['source_due_at'];
$plan = ['ticket_id'=>$ticket,'key'=>$key,'expected_version'=>$f['version'],'owner_id'=>$author[0], 'escalate_to'=>0,
    'due_at'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+3600),'escalate_at'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+7200),
    'note'=>'Contact the manager after the supplier reply','request_key'=>assistanceUuid()];
$call('followup_plan', $plan); $call('followup_plan', $plan);
$assert((int)$scalar("SELECT COUNT(*) FROM service_assistance_events WHERE event_entity_key = '$key' AND event_action = 'planned'") === 1, 'Plan retry duplicated history');
$f = followupDetail($ticket, $key);
$assert($f['planned'] && $f['escalate_to'] === 0 && !$f['escalated'], 'Explicit owner-only plan acquired an escalator');
$assert($f['source_due_at'] === $original && !$f['overdue'], 'Plan overwrote the commitment deadline');
$reject(fn () => $call('followup_plan', array_replace($plan, ['request_key'=>assistanceUuid()])), 'Stale plan was accepted');
$reject(fn () => $call('followup_plan', array_replace($plan, ['request_key'=>assistanceUuid(),'expected_version'=>$f['version'],'owner_id'=>$denied[0]])), 'Denied recipient was accepted');
$as($reader); $reject(fn () => $call('followup_plan', $plan), 'Write downgrade replayed a privileged receipt'); $as($author);
fieldDb("UPDATE ticket_customer_promises SET ticket_customer_promise_summary = 'Call with revised supplier information' WHERE ticket_customer_promise_id = $promise");
$assert(!followupDetail($ticket, $key)['planned'], 'Changed source reused an obsolete follow-up plan');
// Make the other four sources explicit owner-only, then place the plan deadlines in the past as a clock fixture.
foreach (followupQueue(['scope'=>'all','due'=>'all','ticket_id'=>$ticket], $author[0])['items'] as $item) {
    $call('followup_plan', array_replace($plan, ['request_key'=>assistanceUuid(),'key'=>$item['key'],'expected_version'=>$item['version']]));
}
fieldDb("UPDATE service_followup_plans SET plan_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY), plan_escalate_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE plan_ticket_id = $ticket");
$before = (int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client");
followupSendDueNotices(); followupSendDueNotices();
$assert((int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client") === $before + 5, 'Reminder retries duplicated or omitted notifications');
$assert((int)$scalar("SELECT COUNT(*) FROM service_followup_notices WHERE notice_client_id = $client AND notice_stage = 'escalation'") === 0, 'Owner-only plan sent an escalation');
fieldDb("UPDATE service_followup_plans SET plan_escalate_to = {$reviewer[0]}, plan_version = plan_version + 1 WHERE plan_key = '$key'");
$assert(followupQueue(['scope'=>'mine','ticket_id'=>$ticket], $reviewer[0])['total'] === 1, 'Escalated work omitted from recipient queue');
followupSendDueNotices();
$assert((int)$scalar("SELECT COUNT(*) FROM service_followup_notices WHERE notice_client_id = $client AND notice_stage = 'escalation'") === 1, 'Escalation notification missing');
// Concurrent cron workers must reserve each notification exactly once.
fieldDb("DELETE FROM service_followup_notices WHERE notice_ticket_id = $ticket");
$before = (int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client");
$workers = [];
for ($i=0;$i<2;$i++) {
    $worker = proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/fixtures/service_assistance_worker.php','notices'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $workers[] = [$worker,$pipes];
}
foreach ($workers as [$worker,$pipes]) { $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($worker)===0,'Concurrent reminder worker failed: '.$output); }
$assert((int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client") === $before + 6, 'Concurrent reminders were duplicated');
// Hold the canonical client lock while another worker starts its live-source transaction.
// Closing the sources before releasing it must suppress the now-obsolete reminders.
fieldDb("DELETE FROM service_followup_notices WHERE notice_ticket_id = $ticket");
$notice_before = (int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client");
$blocked_worker=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__DIR__.'/fixtures/service_assistance_worker.php','notices_wait'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$blocked_pipes);
stream_set_timeout($blocked_pipes[1], 15);$assert(trim(fgets($blocked_pipes[1]))==='ready','Reminder worker did not initialize');
fieldDb('START TRANSACTION'); documentationLockClient($client);
fwrite($blocked_pipes[0], "go\n");fclose($blocked_pipes[0]);
$waiting=false;
for($i=0;$i<100;$i++){
    if((int)$scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE '%FROM clients WHERE client_id = $client LIMIT 1 FOR UPDATE%'")>0){$waiting=true;break;}
    usleep(20000);
}
if (!$waiting) { stream_set_blocking($blocked_pipes[1], false); stream_set_blocking($blocked_pipes[2], false); throw new RuntimeException('Reminder worker did not wait: ' . json_encode(proc_get_status($blocked_worker)) . stream_get_contents($blocked_pipes[1]) . stream_get_contents($blocked_pipes[2]) . json_encode(fieldRows('SELECT trx_id, trx_state, trx_query FROM information_schema.INNODB_TRX')) . json_encode(fieldRows('SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID()'))); }
fieldDb("UPDATE ticket_customer_promises SET ticket_customer_promise_status = 'fulfilled' WHERE ticket_customer_promise_id = $promise");
fieldDb("UPDATE ticket_approvals SET ticket_approval_status = 'approved' WHERE ticket_approval_id = $approval");
fieldDb("UPDATE tasks SET task_completed_at = NOW() WHERE task_id = $task");
fieldDb("UPDATE field_blockers SET blocker_status = 'resolved' WHERE blocker_id = $blocker");
fieldDb("UPDATE tickets SET ticket_next_action = NULL WHERE ticket_id = $ticket");
mysqli_commit($mysqli);
$blocked_output=stream_get_contents($blocked_pipes[1]).stream_get_contents($blocked_pipes[2]);fclose($blocked_pipes[1]);fclose($blocked_pipes[2]);
$assert(proc_close($blocked_worker)===0,'Blocked reminder worker failed: '.$blocked_output);
$assert((int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client")===$notice_before,'Worker notified from a stale pre-lock snapshot');
$assert(followupQueue(['scope'=>'all','due'=>'all','ticket_id'=>$ticket], $author[0])['total'] === 0, 'Completed source remained in queue');
$before = (int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client"); followupSendDueNotices();
$assert((int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client") === $before, 'Completed source still sent reminders');
// The ticket filter runs before pagination, deduplicates multiple sources, and honors plans.
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 0, 'Completed ticket matched follow-up filter');
fieldDb("UPDATE tickets SET ticket_next_action = 'Check with the vendor', ticket_next_action_due_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE ticket_id = $ticket");
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 1, 'Due source missing from ticket filter');
$f=followupDetail($ticket, 'next_action:'.$ticket);
$call('followup_plan',array_replace($plan,['key'=>$f['key'],'expected_version'=>$f['version'],'request_key'=>assistanceUuid()]));
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 0, 'Future plan remained in due filter');
fieldDb("UPDATE tickets SET ticket_next_action = 'Check with revised vendor' WHERE ticket_id = $ticket");
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 1, 'Changed source reused a stale plan in filter');
fieldDb("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW() WHERE ticket_id = $ticket");
$assert((int)$scalar('SELECT COUNT(*) FROM tickets WHERE (' . followupTicketPredicate() . ") AND ticket_id = $ticket") === 0, 'Resolved ticket remained in due filter');
fieldDb("UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL WHERE ticket_id = $ticket");
$reject(fn () => $call('knowledge_capture',['ticket_id'=>$solved]), 'Retired knowledge creation remained writable');
// Existing knowledge history keeps its retention protection without conversion to a global response.
fieldDb("INSERT INTO service_knowledge SET knowledge_ticket_id = $solved, knowledge_client_id = $client,
    knowledge_title = 'Legacy client-specific draft', knowledge_problem = 'Legacy problem', knowledge_solution = 'Legacy solution',
    knowledge_cautions = 'Legacy cautions', knowledge_source_hash = REPEAT('a',64), knowledge_created_by = {$author[0]}, knowledge_edited_by = {$author[0]}, knowledge_created_at = UTC_TIMESTAMP(), knowledge_updated_at = UTC_TIMESTAMP()");
$assert(assistanceHasHistory($solved) && assistanceClientHasHistory($client), 'Legacy knowledge retention was removed');
$assert(ticketDisciplineCanTransfer($solved)[0]===false, 'Legacy knowledge history allowed a cross-client transfer');
fieldDb("INSERT INTO canned_responses SET canned_response_name = 'General response', canned_response_body = '<p>Thanks for the update.</p><script>alert(1)</script>', canned_response_category_id = 0");$response=$id();
$assert(in_array($response,array_map('intval',array_column(cannedResponseChoices($ticket),'canned_response_id')),true),'General response was missing');
$body=cannedResponseForTicket($ticket,$response);$assert(!str_contains($body['body'],'<script') && str_contains($body['text'],'Thanks for the update.'),'Response was unsafe or unreadable');
fieldDb("INSERT INTO categories SET category_name = 'Response category fixture', category_type = 'Ticket'");$response_category=$id();
fieldDb("INSERT INTO canned_responses SET canned_response_name = 'Category response', canned_response_body = '<p>Category-specific update.</p>', canned_response_category_id = $response_category");$specific=$id();
$assert(!in_array($specific,array_map('intval',array_column(cannedResponseChoices($ticket),'canned_response_id')),true),'Wrong-category response was listed');
$reject(fn()=>cannedResponseForTicket($ticket,$specific),'Wrong-category response was fetchable');
fieldDb("UPDATE tickets SET ticket_category = $response_category WHERE ticket_id = $ticket");
$assert(str_contains(cannedResponseForTicket($ticket,$specific)['text'],'Category-specific'),'Matching category response was missing');
fieldDb("UPDATE tickets SET ticket_category = 0 WHERE ticket_id = $ticket");
$as($reader);$reject(fn()=>cannedResponseForTicket($ticket,$response),'Read-only technician could insert a response');$as($author);
$client_deny_array=[$client];$reject(fn()=>cannedResponseChoices($ticket),'Client restriction exposed ticket responses');$client_deny_array=[];
fieldDb("UPDATE canned_responses SET canned_response_archived_at = NOW() WHERE canned_response_id = $response");
$reject(fn()=>cannedResponseForTicket($ticket,$response),'Archived response remained insertable');
echo "Service follow-ups: source/plan filters, permissions, retry, races, notifications, retained legacy history and canned response access passed.\n";
