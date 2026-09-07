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
// Exact asset/service matches must be candidates even when they share no search terms.
foreach (['Resolver appliance','Service peer'] as $name) { fieldDb('INSERT INTO assets SET asset_name = '.fieldSql($name).", asset_type = 'Network', asset_make = 'Fixture', asset_client_id = $client"); $assets[]=$id(); }
[$asset,$peer_asset]=$assets;
fieldDb("UPDATE tickets SET ticket_asset_id = $asset WHERE ticket_id IN ($ticket,$solved)");
fieldDb("UPDATE tickets SET ticket_asset_id = $peer_asset WHERE ticket_id = $service_ticket");
fieldDb("INSERT INTO services SET service_name = 'Branch DNS', service_description = '', service_category = 'Network', service_importance = 'High', service_notes = '', service_client_id = $client"); $service=$id();
fieldDb("INSERT INTO service_assets (service_id,asset_id) VALUES ($service,$asset),($service,$peer_asset)");
foreach ([$client,$foreign] as $doc_client) { fieldDb("INSERT INTO documents SET document_name = 'DNS resolver runbook', document_content = '<p>Verify resolver queries</p>', document_content_raw = 'Verify DNS resolver queries', document_client_id = $doc_client"); $docs[]=$id(); }
$s = knowledgeSuggestions($ticket); $keys=array_map(static fn($i)=>$i['kind'].':'.$i['id'],$s['items']);
$assert(in_array('ticket:'.$solved,$keys,true) && in_array('ticket:'.$service_ticket,$keys,true), 'Asset or service suggestion missing');
$assert(!in_array('ticket:'.$unrelated,$keys,true) && !in_array('ticket:'.$foreign_ticket,$keys,true) && !in_array('document:'.$docs[1],$keys,true), 'Unrelated or foreign record suggested');
$as($no_docs);$assert(!in_array('document',array_column(knowledgeSuggestions($ticket)['items'],'kind'),true),'Missing documentation permission disclosed documents');$as($author);
$reject(fn () => $call('knowledge_capture',['ticket_id'=>$ticket]), 'Unresolved source captured');
$capture=['ticket_id'=>$solved,'request_key'=>assistanceUuid()]; $knowledge=$call('knowledge_capture',$capture)['knowledge_id'];
$assert($call('knowledge_capture',$capture)['knowledge_id']===$knowledge, 'Capture receipt replay returned another article');
$assert($call('knowledge_capture',['ticket_id'=>$solved])['knowledge_id']===$knowledge, 'Separate capture duplicated the source article');
$as($reader);$reject(fn () => $call('knowledge_capture',$capture),'Capture permission downgrade replayed a write');$as($author);
$client_deny_array=[$client];
$reject(fn () => knowledgeLoad($knowledge),'Denied article was readable');
$reject(fn () => $call('knowledge_capture',$capture),'Denied client replayed capture');
$assert(knowledgeQueue(['state'=>'draft','client_id'=>$client])['items']===[], 'Denied draft appeared in queue');
$client_deny_array=[];
$k=knowledgeLoad($knowledge);
$draft=['ticket_id'=>$solved,'knowledge_id'=>$knowledge,'expected_revision'=>$k['knowledge_revision'],'operation'=>'submit',
    'title'=>'DNS resolver recovery','problem'=>'Queries time out on the branch DNS appliance','solution'=>'Restore the resolver settings and verify queries','cautions'=>''];
$reject(fn () => $call('knowledge_save',$draft), 'Review submission omitted validation/cautions');
$draft['cautions']='Confirm the approved settings and test a lookup from the branch';
$call('knowledge_save',$draft); $k=knowledgeLoad($knowledge);
$review=['ticket_id'=>$solved,'knowledge_id'=>$knowledge,'expected_revision'=>$k['knowledge_revision'],'operation'=>'publish','note'=>'Verified the source, scope and validation steps','request_key'=>assistanceUuid()];
$reject(fn () => $call('knowledge_save',$review),'Author published own draft');
$assert((int)$scalar("SELECT COUNT(*) FROM notifications WHERE notification_client_id = $client AND notification_user_id IN ({$no_docs[0]},{$denied[0]})")===0,'Review request notified an ineligible recipient');
$as($reviewer);$call('knowledge_save',$review);$call('knowledge_save',$review);
$k=knowledgeLoad($knowledge);$doc=(int)$k['knowledge_document_id'];
$assert($k['knowledge_state']==='published' && $doc>0 && (int)$scalar("SELECT document_client_visible FROM documents WHERE document_id = $doc")===0,'Reviewed publication was not internal');
$assert(documentationDocumentHasObligations($doc), 'Published knowledge document and version history were not protected from deletion');
$assert((int)$scalar("SELECT COUNT(*) FROM asset_documents WHERE document_id = $doc AND asset_id = $asset")===1,'Published article omitted source asset');
$assert(in_array('Reviewed knowledge',array_column(knowledgeSuggestions($ticket)['items'],'label'),true),'Published article was not suggested');
fieldDb("UPDATE documents SET document_content = '<p>Externally revised resolver instructions</p>' WHERE document_id = $doc");
$assert(!in_array('Reviewed knowledge',array_column(knowledgeSuggestions($ticket)['items'],'label'),true),'Unreviewed document edit retained reviewed status');
$as($author);$k=knowledgeLoad($knowledge);
$call('knowledge_save',['ticket_id'=>$solved,'knowledge_id'=>$knowledge,'expected_revision'=>$k['knowledge_revision'],'expected_document_hash'=>$k['document_hash'],'operation'=>'revise','note'=>'Include the updated resolver instructions']);
$k=knowledgeLoad($knowledge);$draft=array_replace($draft,['expected_revision'=>$k['knowledge_revision'],'expected_document_hash'=>$k['document_hash']]);
$reject(fn () => $call('knowledge_save',$draft),'External document changes were not acknowledged');
$draft['confirm_document']=1;$call('knowledge_save',$draft);
$k=knowledgeLoad($knowledge);$as($reviewer);$review['expected_revision']=$k['knowledge_revision'];$review['request_key']=assistanceUuid();
fieldDb("UPDATE documents SET document_content = '<p>A newer concurrent resolver edit</p>' WHERE document_id = $doc");
$reject(fn () => $call('knowledge_save',$review),'Review overwrote a concurrent document edit');
$assert((int)$scalar("SELECT COUNT(*) FROM document_versions WHERE document_version_document_id = $doc")===0,'Rejected publication left a version snapshot');
$call('knowledge_save',array_replace($review,['operation'=>'return','request_key'=>assistanceUuid()]));
$as($author);$k=knowledgeLoad($knowledge);$draft['expected_revision']=$k['knowledge_revision'];$draft['expected_document_hash']=$k['document_hash'];
$call('knowledge_save',$draft);$as($reviewer);$review['expected_revision']=knowledgeLoad($knowledge)['knowledge_revision'];$review['request_key']=assistanceUuid();
$call('knowledge_save',$review);$k=knowledgeLoad($knowledge);
$assert((int)$k['knowledge_document_id']===$doc && (int)$scalar("SELECT COUNT(*) FROM document_versions WHERE document_version_document_id = $doc")===1,'Revision duplicated the document or lost its previous version');
fieldDb("UPDATE tickets SET ticket_resolution_summary = 'A different recorded resolution' WHERE ticket_id = $solved");
$assert(!knowledgeLoad($knowledge)['source_current'],'Source change was not detected');
$assert(!in_array('Reviewed knowledge',array_column(knowledgeSuggestions($ticket)['items'],'label'),true),'Changed source was still suggested as reviewed knowledge');
$assert(assistanceClientHasHistory($client) && ticketDisciplineCanTransfer($solved)[0]===false,'Client/ticket history not protected');
$assert(!empty(ticketDeletionEvidenceSummary($solved,$client)['operations']),'Knowledge omitted from ticket retention evidence');
fieldDb('START TRANSACTION');ticketDeletionLockTicket($solved);ticketDeletionSoftDelete($solved,$author[0],'Verify reversible knowledge retention');mysqli_commit($mysqli);
$reject(fn () => knowledgeLoad($knowledge),'Deleted source article remained visible');
fieldDb('START TRANSACTION');ticketDeletionLockTicket($solved);ticketDeletionRestore($solved,$author[0],'Restore the knowledge fixture');mysqli_commit($mysqli);
$assert(knowledgeLoad($knowledge)['knowledge_id']==$knowledge,'Restoration lost the article');
// The explicit purge helper removes assistance metadata and preserves the independent client document.
fieldDb('START TRANSACTION');assistancePurgeTicket($solved);mysqli_commit($mysqli);
$assert(!assistanceHasHistory($solved) && (int)$scalar("SELECT COUNT(*) FROM documents WHERE document_id = $doc")===1,'Purge left metadata or destroyed the published document');
echo "Service assistance: five queue sources, UTC dates, permissions, receipts, concurrent reminders, lifecycle, suggestions, peer review, revision conflicts and retention passed.\n";
