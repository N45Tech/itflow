<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') { exit("Disposable database required.\n"); }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/functions/field_workspace.php';
$session_is_admin = true; $client_access_array = $client_deny_array = []; $_FILES = [];
$session_ip = '127.0.0.1'; $session_user_agent = 'Field workspace CI'; $session_name = 'Field workspace CI';
$config_ticket_prefix = 'FW'; $config_smtp_provider = ''; $config_ticket_client_general_notifications = 0;
$assert = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function ($fn, $message) { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException($message); };
$scalar = static fn($sql) => mysqli_fetch_row(fieldDb($sql))[0];
$id = static fn() => (int) mysqli_insert_id($mysqli);
$uuid = static fn() => substr(bin2hex(random_bytes(4)),0,8).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
$call = static function ($action,$fn,$data) use ($uuid) { global $session_user_id;
    $data['request_key'] ??= $uuid();
    return fieldRequest($action,$data,$session_user_id,static fn(&$batch) => $fn($data,$session_user_id,$batch));
};
fieldDb('UPDATE settings SET config_ticket_next_number = 5000 WHERE company_id = 1');
foreach ([1=>'New',2=>'Open',3=>'In Progress',4=>'Resolved',5=>'Closed',6=>'Waiting on Client',7=>'Waiting on Vendor',8=>'Scheduled'] as $status=>$name) {
    fieldDb("INSERT INTO ticket_statuses SET ticket_status_id = $status, ticket_status_name = " . fieldSql($name)
        . ", ticket_status_color = 'primary', ticket_status_active = 1 ON DUPLICATE KEY UPDATE ticket_status_active = 1");
}
fieldDb("INSERT INTO user_roles SET role_name = 'Workspace fixture', role_is_admin = 1"); $role=$id();
fieldDb("INSERT INTO users SET user_name = 'Mobile fixture', user_email = 'mobile@example.invalid', user_password = 'fixture', user_role_id = $role"); $session_user_id=$id(); $session_user_role=$role;
$requester=$session_user_id;
fieldDb("INSERT INTO users SET user_name = 'Approver fixture', user_email = 'approver@example.invalid', user_password = 'fixture', user_role_id = $role");$approver=$id();
fieldDb("INSERT INTO clients SET client_name = 'Mobile workspace fixture', client_currency_code = 'USD', client_net_terms = 30"); $client=$id();
fieldDb("INSERT INTO clients SET client_name = 'Denied workspace fixture', client_currency_code = 'USD', client_net_terms = 30"); $foreign=$id();
fieldDb("INSERT INTO contacts SET contact_name = 'O\'Brien', contact_email = 'fixture@example.invalid', contact_client_id = $client"); $contact=$id();
fieldDb("INSERT INTO contacts SET contact_name = 'Foreign contact', contact_client_id = $foreign"); $foreign_contact=$id();
fieldDb("INSERT INTO locations SET location_name = 'Fixture site', location_client_id = $client, location_primary = 1"); $location=$id();
$create=['client_id'=>$client,'subject'=>'Mobile fixture job','details'=>'Verify service and document the result',
    'contact_id'=>$contact,'location_id'=>$location,'work_type'=>'incident','impact'=>'medium','urgency'=>'medium','request_key'=>$uuid()];
$ticket=$call('create_job','fieldWorkspaceCreate',$create)['ticket_id'];
$assert($call('create_job','fieldWorkspaceCreate',$create)['ticket_id']===$ticket,'Create retry duplicated the job');
$assert((int)$scalar("SELECT COUNT(*) FROM tickets WHERE ticket_client_id = $client")===1,'Create retry persisted twice');
$assert(count(fieldSearchTickets(['client_id'=>$client],$session_user_id)['jobs'])===1,'Unscheduled job is not searchable');
$assert(count(fieldClientContext($client)['contacts'])===1,'Wrong contacts exposed');
$assert(count(fieldWorkspaceOptions()['statuses'])>0,'Status options unavailable');
$job=fieldWorkspaceDetail($ticket);
$plan=['ticket_id'=>$ticket,'expected_revision'=>$job['plan_version'],'status_id'=>2,'assigned_to'=>$session_user_id,
    'work_type'=>'incident','impact'=>'high','urgency'=>'high','waiting_on'=>'none','next_action'=>'Verify with the site manager',
    'contact_id'=>$contact,'location_id'=>$location,'scheduled_at'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+3600)];
$call('plan','fieldWorkspacePlan',$plan);
$assert(fieldTicket($ticket)['ticket_priority']==='Urgent','Impact and urgency did not set priority');
$reject(fn()=>$call('plan','fieldWorkspacePlan',$plan),'Stale plan overwrote current fields');
$plan['expected_revision']=fieldWorkspaceDetail($ticket)['plan_version'];$plan['contact_id']=$foreign_contact;
$reject(fn()=>$call('plan','fieldWorkspacePlan',$plan),'Foreign contact accepted');
$assert((int)fieldTicket($ticket)['ticket_contact_id']===$contact,'Rejected plan mutated contact');
$time=['ticket_id'=>$ticket,'visibility'=>'internal','message'=>'Verified the test connection','minutes'=>35,'worked_at'=>gmdate('Y-m-d\TH:i:s.000\Z',time()-3600),'request_key'=>$uuid()];
$reply=$call('reply','fieldWorkspaceReply',$time)['reply_id'];
$assert($call('reply','fieldWorkspaceReply',$time)['reply_id']===$reply,'Time retry duplicated work');
$assert($scalar("SELECT ticket_reply_time_worked FROM ticket_replies WHERE ticket_reply_id = $reply")==='00:35:00','Manual time was not recorded');
$reject(fn()=>$call('reply','fieldWorkspaceReply',array_replace($time,['request_key'=>$uuid(),'worked_at'=>gmdate('Y-m-d\TH:i:s.000\Z',time()+3600)])),'Future work accepted');
$reject(fn()=>$call('reply','fieldWorkspaceReply',['ticket_id'=>$ticket,'visibility'=>'email','message'=>'Test','recipients_hash'=>'stale']),'Changed recipients accepted');
// The disposable queue has no mail worker. Exercise escaping, audiences, and retries without delivery.
$config_smtp_provider='smtp';$config_ticket_from_email='field@example.invalid';$config_ticket_from_name="Fixture's service";$config_base_url='example.invalid';
$queued=(int)$scalar('SELECT COUNT(*) FROM email_queue');
$email=['ticket_id'=>$ticket,'visibility'=>'email','message'=>"O'Brien's connection is restored. <script>fixture</script>",
    'recipients_hash'=>hash('sha256',json_encode(fieldTicketRecipients(fieldTicket($ticket)))),'request_key'=>$uuid()];
$call('reply','fieldWorkspaceReply',$email);$call('reply','fieldWorkspaceReply',$email);
$assert((int)$scalar('SELECT COUNT(*) FROM email_queue')===$queued+1,'Customer email retry queued twice');
$mail=mysqli_fetch_assoc(fieldDb('SELECT * FROM email_queue ORDER BY email_id DESC LIMIT 1'));
$assert($mail['email_recipient_name']==="O'Brien",'Recipient quoting changed');
$assert(!str_contains($mail['email_content'],'<script>'),'Customer email rendered message markup');
$call('reply','fieldWorkspaceReply',['ticket_id'=>$ticket,'visibility'=>'portal','message'=>'Portal-only update']);
$assert((int)$scalar('SELECT COUNT(*) FROM email_queue')===$queued+1,'Portal-only reply queued an email');
$config_smtp_provider='';
// Credential plaintext is returned only to the authorized reveal path, never to receipt storage.
$fixture_key=random_bytes(16);$fixture_iv=random_bytes(16);$master=random_bytes(16);
$_COOKIE['user_encryption_session_key']=base64_encode($fixture_key);
$_SESSION['user_encryption_session_iv']=base64_encode($fixture_iv);
$_SESSION['user_encryption_session_ciphertext']=openssl_encrypt($master,'aes-128-cbc',$fixture_key,0,$fixture_iv);
fieldDb("INSERT INTO credentials SET credential_name = 'Fixture vault item', credential_client_id = $client,
    credential_username = " . fieldSql(encryptCredentialEntry('fixture-user')) . ', credential_password = '
    . fieldSql(encryptCredentialEntry('fixture-reveal-only')));$credential=$id();
$receipts=(int)$scalar('SELECT COUNT(*) FROM field_requests');
$secret=fieldRevealCredential(['ticket_id'=>$ticket,'credential_id'=>$credential],$session_user_id);
$assert($secret['password']==='fixture-reveal-only'&&$secret['username']==='fixture-user','Vault decryption failed');
$assert((int)$scalar('SELECT COUNT(*) FROM field_requests')===$receipts,'Credential reveal created a receipt');
$assert(!str_contains(json_encode(fieldWorkspaceDetail($ticket)),'fixture-reveal-only'),'Ticket detail disclosed a secret');
$reject(fn()=>fieldRevealCredential(['ticket_id'=>$ticket,'credential_id'=>$credential+99999],$session_user_id),'Unknown credential accepted');
unset($_COOKIE['user_encryption_session_key']);
$reject(fn()=>fieldRevealCredential(['ticket_id'=>$ticket,'credential_id'=>$credential],$session_user_id),'Locked vault revealed a credential');
$promise=$call('promise','fieldWorkspacePromise',['ticket_id'=>$ticket,'summary'=>'Send the documented outcome','due_at'=>date('Y-m-d H:i:s',time()+7200)]);
$promise_id=(int)$scalar("SELECT ticket_customer_promise_id FROM ticket_customer_promises WHERE ticket_customer_promise_ticket_id = $ticket");
$transition=['ticket_id'=>$ticket,'expected_status'=>2,'transition'=>'resolve','resolution_code'=>'fixed','resolution_summary'=>'Connection restored and verified with the site manager'];
$reject(fn()=>$call('transition','fieldWorkspaceTransition',$transition),'Open commitment did not prevent resolution');
$assert($scalar("SELECT ticket_resolution_code FROM tickets WHERE ticket_id = $ticket")===null,'Failed resolution left partial evidence');
$call('promise','fieldWorkspacePromise',['ticket_id'=>$ticket,'promise_id'=>$promise_id,'status'=>'fulfilled','reason'=>'Delivered the requested documentation']);
$call('documentation_action','fieldWorkspaceDocumentationAction',['ticket_id'=>$ticket,'operation'=>'assess','impact'=>'None']);
$request=['ticket_id'=>$ticket,'kind'=>'ticket','operation'=>'request','route'=>'internal:specific','required_user_id'=>$approver,'reason'=>'Review the completed service','request_key'=>$uuid()];
$approval=$call('approval','fieldWorkspaceApproval',$request)['approval_id'];
$assert($call('approval','fieldWorkspaceApproval',$request)['approval_id']===$approval,'Approval retry duplicated a request');
$decide=['ticket_id'=>$ticket,'kind'=>'ticket','approval_id'=>$approval,'operation'=>'decide','decision'=>'approved','reason'=>'Checked the service outcome'];
$reject(fn()=>$call('approval','fieldWorkspaceApproval',$decide),'Requester approved own request');
$reject(fn()=>$call('transition','fieldWorkspaceTransition',$transition),'Pending ticket approval failed to block resolution');
$assert(!fieldWorkspaceApprovals($ticket,$requester)[0]['can_decide'],'Requester saw a self-approval action');
$session_user_id=$approver;
$call('approval','fieldWorkspaceApproval',array_replace($decide,['decision'=>'declined','reason'=>'Please include the handover record']));
$session_user_id=$requester;
$call('approval','fieldWorkspaceApproval',['ticket_id'=>$ticket,'kind'=>'ticket','approval_id'=>$approval,'operation'=>'retry','reason'=>'Handover evidence is now recorded']);
$assert($scalar("SELECT ticket_approval_decided_by FROM ticket_approvals WHERE ticket_approval_id = $approval")===null,'Approval retry retained previous decision');
$session_user_id=$approver;$call('approval','fieldWorkspaceApproval',$decide);$session_user_id=$requester;
$task=$call('task_update','fieldWorkspaceTask',['ticket_id'=>$ticket,'name'=>'Confirm connectivity','instructions'=>'Perform an end-to-end check'])['task_id'];
$reject(fn()=>$call('transition','fieldWorkspaceTransition',$transition),'Incomplete task did not prevent resolution');
$task_approval=$call('approval','fieldWorkspaceApproval',array_replace($request,['kind'=>'task','task_id'=>$task,'request_key'=>$uuid()]))['approval_id'];
$reject(fn()=>$call('task','fieldCompleteTask',['ticket_id'=>$ticket,'task_id'=>$task]),'Pending task approval did not block task completion');
$session_user_id=$approver;
$call('approval','fieldWorkspaceApproval',array_replace($decide,['kind'=>'task','task_id'=>$task,'approval_id'=>$task_approval]));
$session_user_id=$requester;
$call('task','fieldCompleteTask',['ticket_id'=>$ticket,'task_id'=>$task]);
$doc=$call('document_update','fieldWorkspaceDocument',['ticket_id'=>$ticket,'name'=>'Fixture access notes','note'=>'Original document content'])['document_id'];
$call('document_update','fieldWorkspaceDocument',['ticket_id'=>$ticket,'document_id'=>$doc,'note'=>'Additional field verification']);
$assert((int)$scalar("SELECT COUNT(*) FROM document_versions WHERE document_version_document_id = $doc")===1,'Document update lost version history');
$assert((int)$scalar("SELECT document_client_visible FROM documents WHERE document_id = $doc")===0,'New internal documentation was exposed to customer');
$assert(str_contains($scalar("SELECT document_content FROM documents WHERE document_id = $doc"),'Original document content'),'Append lost original document');
$requirement=documentationSaveRequirementDraft(0,['key'=>'field-fixture-handover','name'=>'Field fixture handover',
    'record_type'=>documentationRequirementRecordTypes()[0],'blocks_readiness'=>1,'blocks_ticket_resolution'=>1,
    'evidence_policy'=>'note','selectors'=>[['dimension'=>'always','value'=>'any']]],null,$session_user_id);
documentationPublishRequirement($requirement['requirement_id'],$requirement['revision'],$session_user_id);
documentationEvaluateClient($client,$session_user_id);
$obligation=(int)$scalar("SELECT documentation_obligation_id FROM client_documentation_obligations
    WHERE documentation_obligation_client_id = $client AND documentation_obligation_requirement_id = ".(int)$requirement['requirement_id']);
$assert($obligation>0,'Fixture obligation was not projected');
$call('documentation_action','fieldWorkspaceDocumentationAction',['ticket_id'=>$ticket,'operation'=>'assess','impact'=>'Required','configuration_change'=>1]);
$verification=['ticket_id'=>$ticket,'operation'=>'verify','obligation_id'=>$obligation,'document_id'=>$doc,
    'confirm_verified'=>1,'evidence_type'=>'note','note'=>'Reviewed the cabinet location and field handover'];
$verification['revision']=(int)$scalar("SELECT documentation_obligation_revision FROM client_documentation_obligations WHERE documentation_obligation_id = $obligation");
$call('documentation_action','fieldWorkspaceDocumentationAction',$verification);
$assert(fieldWorkspaceDetail($ticket)['requirements'][0]['status']==='Current','Verified requirement does not show current status');
$call('document_update','fieldWorkspaceDocument',['ticket_id'=>$ticket,'document_id'=>$doc,'note'=>'Corrected the cabinet position after verification']);
$reject(fn()=>$call('transition','fieldWorkspaceTransition',$transition),'Changed documentation failed to block resolution');
$reject(fn()=>$call('documentation_action','fieldWorkspaceDocumentationAction',$verification),'Stale documentation verification overwrote a newer revision');
$verification['revision']=(int)$scalar("SELECT documentation_obligation_revision FROM client_documentation_obligations WHERE documentation_obligation_id = $obligation");
$call('documentation_action','fieldWorkspaceDocumentationAction',$verification);
fieldDb("INSERT INTO assets SET asset_name = 'Fixture firewall', asset_type = 'Firewall', asset_make = 'Fixture', asset_client_id = $client, asset_notes = '<p>Original asset note</p>'");$asset=$id();
$call('asset_update','fieldWorkspaceAsset',['ticket_id'=>$ticket,'asset_id'=>$asset,'operation'=>'link']);
$call('asset_update','fieldWorkspaceAsset',['ticket_id'=>$ticket,'asset_id'=>$asset,'status'=>'In service','physical_location'=>'Test closet','note'=>'Ports labeled and verified']);
$assert(str_contains(fieldAsset($ticket,$asset)['asset_notes'],'Original asset note'),'Asset update lost existing notes');
for($i=0;$i<42;$i++) { fieldDb("INSERT INTO ticket_replies SET ticket_reply = 'History $i', ticket_reply_type = 'Internal', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $ticket"); }
$first=fieldHistory($ticket);$second=fieldHistory($ticket,$first['next']);
$assert(count($first['entries'])===40&&count($second['entries'])>=3,'Full conversation cannot be paged');
$call('transition','fieldWorkspaceTransition',$transition+['request_key'=>$uuid()]);
$assert((int)fieldTicket($ticket)['ticket_status']===4,'Resolution did not persist');
$assert(fieldSearchTickets(['client_id'=>$client],$session_user_id)['jobs']===[],'Resolved ticket remained in active work');
fieldDb("INSERT INTO ticket_templates SET ticket_template_name = 'Mobile fixture template', ticket_template_subject = 'Fixture procedure',
    ticket_template_details = '<p>Preserve these published work instructions</p>'");$template=$id();
fieldDb("INSERT INTO task_templates SET task_template_name = 'Mandatory fixture check', task_template_key = 'mandatory-check',
    task_template_ticket_template_id = $template");
$version=publishRunbookVersion($template,$session_user_id,'Field workspace fixture');
$assert($version>0,'Fixture runbook did not publish');
$templated=$call('create_job','fieldWorkspaceCreate',array_replace($create,['request_key'=>$uuid(),'template_id'=>$template]))['ticket_id'];
$assert(str_contains(fieldTicket($templated)['ticket_details'],'Preserve these published work instructions'),'Creation discarded runbook instructions');
$assert((int)$scalar("SELECT COUNT(*) FROM tasks WHERE task_ticket_id = $templated AND task_name = 'Mandatory fixture check'")===1,'Creation omitted published runbook tasks');
$call('transition','fieldWorkspaceTransition',['ticket_id'=>$ticket,'expected_status'=>4,'transition'=>'reopen']);
$assert(fieldTicket($ticket)['ticket_resolution_code']===null,'Reopen retained active resolution');
$call('transition','fieldWorkspaceTransition',$transition);
$codes=ticketClosureCodeDefinitions();$closure=array_key_first($codes);
$call('transition','fieldWorkspaceTransition',['ticket_id'=>$ticket,'expected_status'=>4,'transition'=>'close','closure_code'=>$closure]);
$assert(fieldWorkspaceDetail($ticket)['closed'],'Close left ticket open');
$reject(fn()=>$call('reply','fieldWorkspaceReply',['ticket_id'=>$ticket,'message'=>'Cannot write on closed','visibility'=>'internal']),'Closed ticket accepted a new reply');
$session_is_admin=false;$client_deny_array=[$client];
$reject(fn()=>fieldWorkspaceDetail($ticket),'Denied client details leaked');
$reject(fn()=>$call('create_job','fieldWorkspaceCreate',$create),'Revoked access replayed a create receipt');
$assert(fieldSearchTickets(['scope'=>'all','state'=>'all','q'=>'Mobile fixture'],$session_user_id)['jobs']===[],'Search exposed denied client');
$session_is_admin=true;$client_deny_array=[];
echo "Field workspace: creation, scope, stale updates, time, commitments, task gates, document history, asset updates, and completion passed.\n";
