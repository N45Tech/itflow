<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') {
    exit("This test requires the disposable n45_ci_final database.\n");
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_set_charset($mysqli, 'utf8mb4');
$session_is_admin = true;
$client_access_array = $client_deny_array = [];
$session_ip = '127.0.0.1';
$session_user_agent = 'Field CI';
$session_name = 'Field CI';
$_FILES = [];
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $fn, string $message): void {
    try { $fn(); } catch (DomainException $e) { return; }
    throw new RuntimeException($message);
};
$id = static fn() => (int) mysqli_insert_id($mysqli);
$scalar = static fn(string $sql) => mysqli_fetch_row(fieldDb($sql))[0];
$uuid = static function (): string { $hex = bin2hex(random_bytes(16)); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20); };
$call = static function (string $action, string $fn, array $data, ?int $user = null) use ($uuid): array {
    global $session_user_id;
    $user ??= $session_user_id;
    $data['request_key'] ??= $uuid();
    return fieldRequest($action,$data,$user,static fn (&$batch) => $fn($data,$user,$batch));
};
try {
    fieldDb("INSERT INTO user_roles SET role_name = 'Field CI admin', role_is_admin = 1"); $role = $id();
    fieldDb("INSERT INTO users SET user_name = 'Field CI tech', user_email = 'field-ci@example.invalid', user_password = 'unused', user_role_id = $role"); $session_user_id = $id();
    fieldDb("INSERT INTO clients SET client_name = 'Field CI site', client_currency_code = 'USD', client_net_terms = 30, client_ticket_retention_policy = 'override'"); $client = $id();
    fieldDb("INSERT INTO locations SET location_name = 'Test site', location_address = '10 Test Way', location_client_id = $client, location_primary = 1"); $site = $id();
    fieldDb("INSERT INTO projects SET project_name = 'Field CI project', project_client_id = $client, project_manager = $session_user_id"); $project = $id();
    $tickets = [];
    for ($i=0;$i<3;$i++) {
        fieldDb("INSERT INTO tickets SET ticket_prefix = 'FCI', ticket_number = ".($i+1).", ticket_subject = 'Test field job',
            ticket_details = 'Test field instructions', ticket_priority = 'High', ticket_status = 2,
            ticket_created_by = $session_user_id, ticket_assigned_to = $session_user_id, ticket_client_id = $client,
            ticket_project_id = $project, ticket_location_id = $site, ticket_schedule = NOW()"); $tickets[] = $id();
    }
    [$ticket,$other,$third] = $tickets;
    fieldDb("INSERT INTO tasks SET task_name = 'Verify service', task_ticket_id = $ticket, task_assigned_to = $session_user_id, task_evidence_required = 'note'");$task=$id();
    $assert(count(fieldToday($session_user_id)) >= 3,'Assigned schedule failed');
    $assert(count(fieldProjects($session_user_id)) >= 1,'Project listing failed');
    $assert(count(fieldProjectDetail($project)['jobs']) === 3,'Project work plan failed');
    $assert(fieldTicketDetail($ticket,$session_user_id)['tasks'][0]['can_complete'] === false,'Required evidence gate missing');
    $position = ['latitude'=>45.5,'longitude'=>-73.5,'accuracy'=>12,'observed_at'=>round(microtime(true)*1000)];
    $call('pin','fieldSavePin',['ticket_id'=>$ticket,'confirm_site'=>1,'address_hash'=>fieldJob(fieldTicket($ticket))['address_hash']]+$position);
    $assert(fieldJob(fieldTicket($ticket))['pin_valid'] === true,'Verified pin not recognized');
    fieldDb("UPDATE locations SET location_address = '12 Test Way' WHERE location_id = $site");
    $assert(fieldJob(fieldTicket($ticket))['pin_valid'] === false,'Changed address retained a valid pin');
    $reject(fn()=>$call('visit','fieldVisitTransition',['ticket_id'=>$ticket,'kind'=>'onsite']), 'Arrival did not require confirmation');
    $start=['ticket_id'=>$ticket,'kind'=>'onsite','confirm_onsite'=>1,'request_key'=>$uuid(),'share_location'=>1]+$position;
    $visit=$call('visit','fieldVisitTransition',$start)['visit_id'];
    $assert($call('visit','fieldVisitTransition',$start)['visit_id']===$visit,'Retried arrival duplicated a visit');
    $assert((int)$scalar("SELECT COUNT(*) FROM field_visits WHERE visit_user_id = $session_user_id")===1,'Retry created another visit');
    $reject(fn()=>$call('visit','fieldVisitTransition',array_replace($start,['kind'=>'travel'])),'A reused key accepted a different payload');
    $reject(fn()=>$call('visit','fieldVisitTransition',['ticket_id'=>$other,'kind'=>'onsite','confirm_onsite'=>1]),'Stale visit state replaced an active visit');
    $assert(fieldServicePendingWork($ticket),'Active visit was not protected');
    $assert(!ticketDisciplineCanResolve($ticket)[0],'Ticket resolved with active field time');
    $assert(!ticketDisciplineCanTransfer($ticket)[0],'Visit history allowed a client transfer');
    mysqli_begin_transaction($mysqli);
    try {$reject(fn()=>ticketDeletionSoftDelete($ticket,$session_user_id,'Test deletion'),'Active visit could be deleted');} finally {mysqli_rollback($mysqli);}
    fieldDb("UPDATE field_time_segments SET segment_started_at = UTC_TIMESTAMP() - INTERVAL 120 SECOND WHERE segment_visit_id = $visit");
    $call('visit','fieldVisitTransition',['ticket_id'=>$other,'visit_id'=>$visit,'kind'=>'onsite','confirm_onsite'=>1,'share_location'=>1]+$position);
    $assert((int)$scalar("SELECT COUNT(*) FROM field_time_segments WHERE segment_active_visit_id = $visit")===1,'Activity change left overlapping segments');
    $assert((int)$scalar("SELECT COUNT(*) FROM field_time_segments WHERE segment_visit_id = $visit")===2,'Same-site work was not split by ticket');
    $call('visit','fieldVisitTransition',['ticket_id'=>$other,'visit_id'=>$visit,'kind'=>'break']);
    $assert($scalar("SELECT visit_latitude FROM field_visits WHERE visit_id = $visit")===null,'Turning off sharing retained the position');
    $note=['ticket_id'=>$ticket,'task_id'=>$task,'work_action'=>'Tested the service','work_result'=>'Service passed verification','work_next_step'=>'Review with the customer','request_key'=>$uuid()];
    $reply=$call('note','fieldAddNote',$note)['reply_id'];
    $assert($call('note','fieldAddNote',$note)['reply_id']===$reply,'Retried note duplicated evidence');
    $assert(runbookTaskCanComplete($task)[0],'Required note evidence was not accepted');
    $blocker=$call('issue','fieldAddBlocker',['ticket_id'=>$ticket,'task_id'=>$task,'kind'=>'blocker','title'=>'Missing access',
        'details'=>'Test response required','impact'=>'Cannot proceed','owner_id'=>$session_user_id,'due_at'=>date('Y-m-d H:i:s',time()+3600)])['blocker_id'];
    $assert(!runbookTaskCanComplete($task)[0],'An open field blocker did not block task completion');
    $call('issue_update','fieldUpdateBlocker',['ticket_id'=>$ticket,'blocker_id'=>$blocker,'status'=>'acknowledged','note'=>'Contacting the site manager']);
    $assert(!runbookTaskCanComplete($task)[0],'Acknowledgment silently resolved a blocker');
    $call('issue_update','fieldUpdateBlocker',['ticket_id'=>$ticket,'blocker_id'=>$blocker,'status'=>'resolved','note'=>'Access restored and verified']);
    $call('task','fieldCompleteTask',['ticket_id'=>$ticket,'task_id'=>$task]);
    $assert($scalar("SELECT task_state FROM tasks WHERE task_id = $task")==='Completed','Task completion did not use canonical state');
    fieldDb("INSERT INTO ticket_attachments SET ticket_attachment_ticket_id = $ticket, ticket_attachment_name = 'CI acknowledgment.png', ticket_attachment_reference_name = 'ci-not-a-file.png'");$attachment=$id();
    fieldDb("UPDATE field_visits SET visit_signature_attachment_id = $attachment, visit_acknowledged_name = 'CI customer', visit_acknowledged_at = UTC_TIMESTAMP() WHERE visit_id = $visit");
    $call('visit','fieldVisitTransition',['ticket_id'=>$ticket,'visit_id'=>$visit,'kind'=>'finished','customer_summary'=>'Visit complete; review next steps.']);
    $assert($scalar("SELECT visit_acknowledged_name FROM field_visits WHERE visit_id = $visit")==='CI customer','Checkout erased a signed acknowledgment');
    $assert(!fieldActiveVisit($session_user_id),'Finished visit kept an active timer');
    $assert(fieldServicePendingWork($other),'Unreviewed split-ticket time was not protected');
    $reviews=[];
    foreach(fieldRows("SELECT * FROM field_time_segments WHERE segment_visit_id = $visit") as $segment) {
        $reviews[$segment['segment_id']]=['seconds'=>$segment['segment_kind']==='break'?0:strtotime($segment['segment_ended_at'].' UTC')-strtotime($segment['segment_started_at'].' UTC')];
    }
    $time=['ticket_id'=>$ticket,'visit_id'=>$visit,'reviews'=>$reviews,'request_key'=>$uuid()];
    $reject(fn()=>$call('time','fieldSubmitTime',$time,$session_user_id+10000),'Another technician submitted the visit time');
    $call('time','fieldSubmitTime',$time);$call('time','fieldSubmitTime',$time);
    $assert(!fieldServicePendingWork($ticket)&&!fieldServicePendingWork($other),'Reviewed time remains pending');
    $assert((int)$scalar("SELECT COUNT(*) FROM field_time_segments WHERE segment_visit_id = $visit AND segment_reply_id IS NOT NULL")===3,'Reviewed time duplicated or dropped entries');
    $session_is_admin=false;$client_access_array=[];$client_deny_array=[$client];$session_user_role=$role;
    $reject(fn()=>fieldTicket($ticket),'Denied client was readable');
    $reject(fn()=>$call('note','fieldAddNote',$note),'A revoked client permission replayed a receipt');
    $assert(fieldMyVisits($session_user_id)===[],'Denied client visits leaked');
    $session_is_admin=true;$client_deny_array=[];
    mysqli_begin_transaction($mysqli);
    try {fieldPurgeTicket($ticket);mysqli_commit($mysqli);} catch(Throwable $e){mysqli_rollback($mysqli);throw $e;}
    $assert((int)$scalar("SELECT visit_ticket_id FROM field_visits WHERE visit_id = $visit")===$other,'Purge discarded a visit shared with a surviving ticket');
    $assert((int)$scalar("SELECT COUNT(*) FROM field_time_segments WHERE segment_ticket_id = $other")===2,'Purge removed another ticket’s time');
    $assert($scalar("SELECT visit_customer_summary FROM field_visits WHERE visit_id = $visit")===null,'Deleted ticket summary was exposed through the retained visit');
    echo "Field visits, retry safety, reviewed time, evidence, blockers, scope, and retention passed.\n";
} finally {
    // The release harness owns this disposable database; remove fixture clients
    // only after all assertions. Other fixture records are removed by its reset.
    mysqli_rollback($mysqli);
}
