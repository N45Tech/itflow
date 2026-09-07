<?php
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_final') { exit("Disposable database required.\n"); }
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/functions.php';
date_default_timezone_set('America/New_York');
fieldDb("SET time_zone = '" . date('P') . "'");
$assert = static function ($ok,$message) { if (!$ok) { throw new RuntimeException($message); } };
$id = static fn () => (int) mysqli_insert_id($mysqli);
fieldDb("INSERT INTO companies SET company_id = 1, company_name = 'N45 · Local test', company_country = 'United States', company_locale = 'en_US', company_currency = 'USD' ON DUPLICATE KEY UPDATE company_locale = 'en_US'");
fieldDb("UPDATE settings SET config_timezone = 'America/New_York' WHERE company_id = 1");
fieldDb("INSERT INTO user_roles SET role_name = 'Assistance HTTP fixture', role_is_admin = 1"); $role=$id();
fieldDb("INSERT INTO user_roles SET role_name = 'Canned response technician', role_is_admin = 0");$technician_role=$id();
foreach (['module_support','module_client'] as $module) {
    $module_id=(int)mysqli_fetch_row(fieldDb('SELECT module_id FROM modules WHERE module_name = '.fieldSql($module)))[0];
    fieldDb("INSERT INTO user_role_permissions SET user_role_id = $technician_role, module_id = $module_id, user_role_permission_level = 2");
}
$sessions=[];$users=[];$session_dir=sys_get_temp_dir().'/assistance-sessions-'.bin2hex(random_bytes(8));mkdir($session_dir,0700);
ini_set('session.save_path',$session_dir);
foreach (['Morgan Chen','Alex Rivera'] as $name) {
    fieldDb('INSERT INTO users SET user_name = '.fieldSql($name).", user_email = 'fixture@example.invalid', user_password = 'fixture', user_role_id = $role");$user=$id();$users[]=$user;
    if(count($users)===2){fieldDb("UPDATE users SET user_role_id = $technician_role WHERE user_id = $user");}
    fieldDb("INSERT INTO user_settings SET user_id = $user, user_config_theme_dark = " . (count($users) === 2 ? 1 : 0));
    session_id('assistance-'.bin2hex(random_bytes(16)));session_start();$_SESSION=['logged'=>true,'user_id'=>$user,'csrf_token'=>'assistance-fixture-csrf'];$sessions[]=session_id();session_write_close();
}
fieldDb("INSERT INTO clients SET client_name = 'Example Branch Services', client_currency_code = 'USD', client_net_terms = 30");$client=$id();
fieldDb("INSERT INTO tickets SET ticket_prefix = 'N45-', ticket_number = 1042, ticket_subject = 'Restore branch DNS resolution',
    ticket_details = 'DNS queries time out at the branch. Check the resolver settings and validate the service from a workstation.',
    ticket_priority = 'High', ticket_status = 2, ticket_client_id = $client, ticket_created_by = {$users[0]}, ticket_assigned_to = {$users[0]},
    ticket_waiting_on = 'vendor', ticket_next_action = 'Confirm the resolver update with the vendor', ticket_next_action_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY)");$ticket=$id();
fieldDb("INSERT INTO ticket_customer_promises SET ticket_customer_promise_ticket_id = $ticket, ticket_customer_promise_client_id = $client,
    ticket_customer_promise_summary = 'Send the branch manager a service update', ticket_customer_promise_due_at = DATE_SUB(NOW(), INTERVAL 2 HOUR), ticket_customer_promise_created_by = {$users[0]}");
fieldDb("INSERT INTO tickets SET ticket_prefix = 'N45-', ticket_number = 1028, ticket_subject = 'Recover branch DNS after a settings change',
    ticket_details = 'Workstations could not resolve internal names after an appliance update.', ticket_priority = 'Medium', ticket_status = 5,
    ticket_client_id = $client, ticket_created_by = {$users[0]}, ticket_assigned_to = {$users[0]}, ticket_resolution_code = 'fixed',
    ticket_resolution_summary = 'Restore the approved resolver configuration, flush the workstation DNS cache, then verify internal and external lookups.',
    ticket_resolved_at = NOW(), ticket_closed_at = NOW()");$solved=$id();
fieldDb("INSERT INTO documents SET document_name = 'Branch DNS validation checklist', document_content = '<p>Verify internal names, external names and the application connection.</p>',
    document_content_raw = 'Branch DNS validation checklist. Verify internal names, external names and the application connection.', document_client_id = $client");$doc=$id();
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($listener,false);fclose($listener);
$log=tempnam(sys_get_temp_dir(),'assistance-http-');
$process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-d','session.save_path='.$session_dir,'-d','display_errors=0','-S',$address,'-t',dirname(__DIR__)],
    [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
try {
    for($i=0;$i<50;$i++){ $socket=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(100000); }
    $request=static function($path,$input=null,$actor=0,$method=null,$form=false)use($address,$sessions){
        $opts=['method'=>$method??($input===null?'GET':'POST'),'header'=>'Cookie: PHPSESSID='.$sessions[$actor]."\r\nUser-Agent: N45 local regression\r\nContent-Type: ".($form?'application/x-www-form-urlencoded':'application/json')."\r\n",'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15];
        if($input!==null){$opts['content']=$form?http_build_query($input):json_encode($input);}
        $body=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>$opts]));
        preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$match);
        return ['status'=>(int)$match[1],'body'=>$body,'data'=>json_decode($body,true),'headers'=>$http_response_header];
    };
    $base=['ticket_id'=>$solved,'action'=>'knowledge_capture','request_key'=>assistanceUuid(),'csrf_token'=>'assistance-fixture-csrf'];
    $assert($request('/agent/field/api.php',$base)['status']===422,'Retired knowledge creation accepted');
    $assert($request('/agent/field/api.php',array_replace($base,['csrf_token'=>'wrong']))['status']===403,'Invalid CSRF accepted');
    $assert($request('/agent/field/api.php?action=knowledge_queue')['status']===404,'Retired knowledge queue remained available');
    $assert($request('/agent/field/api.php',null,0,'PUT')['status']===405,'Unsupported method accepted');
    $assert($request('/agent/knowledge.php')['status']===302,'Legacy knowledge link did not redirect');
    $assert($request('/agent/followups.php')['status']===302,'Legacy follow-up link did not redirect');
    $assert($request('/agent/tickets.php?queue=followups&client_id='.$client)['status']===200,'Tickets follow-up filter failed');
    $assert($request('/agent/ticket.php?ticket_id='.$ticket)['status']===200,'Ticket page failed');
    $q=$request('/agent/field/api.php?action=followups&scope=all&client_id='.$client);
    $assert(count($q['data']['data']['items'])===2,'HTTP queue lost canonical sources');
    $assert(str_contains(implode(' ',$q['headers']),'no-store'),'Queue data was cacheable');
    $canned=['add_canned_response'=>'1','name'=>'Vendor status update','body'=>'<p>We are checking with the <strong>vendor</strong> and will update you shortly.</p>','category'=>0,'csrf_token'=>'assistance-fixture-csrf'];
    $assert($request('/admin/post.php',$canned,1,null,true)['status']===403,'Technician created an admin canned response');
    $assert(str_contains($request('/admin/canned_responses.php',null,1)['body'],'does not have admin access'),'Technician accessed canned response administration');
    $before=(int)mysqli_fetch_row(fieldDb('SELECT COUNT(*) FROM canned_responses'))[0];
    $request('/admin/post.php',array_replace($canned,['csrf_token'=>'wrong']),0,null,true);
    $assert((int)mysqli_fetch_row(fieldDb('SELECT COUNT(*) FROM canned_responses'))[0]===$before,'Bad CSRF created an admin response');
    $assert($request('/admin/post.php',$canned,0,null,true)['status']===302,'Admin response creation failed');
    $response_id=(int)mysqli_fetch_row(fieldDb("SELECT canned_response_id FROM canned_responses WHERE canned_response_name = 'Vendor status update' ORDER BY canned_response_id DESC LIMIT 1"))[0];
    $response=$request('/agent/field/api.php?action=canned_response&ticket_id='.$ticket.'&response_id='.$response_id,null,1);
    $assert($response['status']===200&&str_contains($response['data']['data']['text'],'vendor'),'Technician could not insert an admin response');
    $assert($request('/agent/ajax.php?get_canned_response='.$response_id.'&ticket_id='.$ticket,null,1)['status']===200,'Desktop response insertion failed');
    $assert(str_contains(implode(' ',$response['headers']),'no-store'),'Response body was cacheable');
    // Retained legacy knowledge must survive removal of the authoring workflow.
    fieldDb("INSERT INTO service_knowledge SET knowledge_ticket_id = $solved, knowledge_client_id = $client,
        knowledge_title = 'Legacy draft', knowledge_problem = 'Legacy problem', knowledge_solution = 'Legacy solution', knowledge_cautions = 'Legacy checks',
        knowledge_source_hash = REPEAT('a',64), knowledge_created_by = {$users[0]}, knowledge_edited_by = {$users[0]}, knowledge_created_at = UTC_TIMESTAMP(), knowledge_updated_at = UTC_TIMESTAMP()");
    // The legacy API must also preserve assistance history on archived clients.
    fieldDb("UPDATE clients SET client_archived_at = NOW() WHERE client_id = $client");
    fieldDb("INSERT INTO api_keys SET api_key_name = 'Assistance HTTP fixture', api_key_secret = 'assistance-local-fixture-key', api_key_decrypt_hash = 'fixture', api_key_user_id = {$users[0]}, api_key_expire = DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)");$api_key=$id();
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Authorization: Bearer assistance-local-fixture-key\r\nContent-Type: application/json\r\n",'content'=>json_encode(['client_id'=>$client]),'ignore_errors'=>true,'timeout'=>10]]);
    $body=file_get_contents('http://'.$address.'/api/v1/clients/delete.php',false,$ctx);
    $assert(str_contains($http_response_header[0],'409')&&str_contains($body,'retained'),'Client API deletion bypassed knowledge retention: '.$body);
    fieldDb("UPDATE clients SET client_archived_at = NULL WHERE client_id = $client");fieldDb("DELETE FROM api_keys WHERE api_key_id = $api_key");
    if (getenv('N45_ASSISTANCE_BROWSER') === '1') {
        $fixture=tempnam(sys_get_temp_dir(),'assistance-browser-');
        file_put_contents($fixture,json_encode(['base'=>'http://'.$address,'sessions'=>$sessions,'ticket'=>$ticket,'solved'=>$solved,'response'=>$response_id,'client'=>$client,'doc'=>$doc]));
        $command=['node',__DIR__.'/field/assistance.cjs',$fixture];$browser=proc_open($command,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$browser_pipes,dirname(__DIR__));fclose($browser_pipes[0]);
        $assert(proc_close($browser)===0,'Service assistance browser checks failed');unlink($fixture);
    }
    $logtext=file_get_contents($log);$assert(!preg_match('/PHP (Warning|Fatal error):/',$logtext),'HTTP emitted a PHP warning: '.$logtext);
    echo "Ticket workflow HTTP: admin-only response creation, technician insertion, retired knowledge gates, follow-up filter, CSRF, no-store and legacy retention passed.\n";
} finally {
    proc_terminate($process);fclose($pipes[0]);proc_close($process);
    foreach(glob($session_dir.'/*')as$file){unlink($file);}rmdir($session_dir);@unlink($log);
}
