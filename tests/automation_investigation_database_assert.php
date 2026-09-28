<?php

// This fixture is destructive only inside the explicitly named disposable CI database.
if (PHP_SAPI !== 'cli' || getenv('N45_CI_DB_NAME') !== 'n45_ci_investigation') {
    fwrite(STDERR, "Disposable n45_ci_investigation database required.\n"); exit(1);
}
require_once __DIR__ . '/fixtures/n45_release_config.php';
require_once dirname(__DIR__) . '/functions.php';
mysqli_set_charset($mysqli, 'utf8mb4');
mysqli_query($mysqli, "SET time_zone = '-04:00'"); // Exercise UTC accounting under the production-local timezone.
$count=0;
$assert=static function ($ok, string $message) use (&$count): void {
    $count++; if (!$ok) { throw new RuntimeException($message); }
};
$q=static fn (string $sql) => investigationQuery($sql);
$scalar=static fn (string $sql) => mysqli_fetch_row(investigationQuery($sql))[0] ?? null;
$id=static fn () => intval(mysqli_insert_id($GLOBALS['mysqli']));
$q("INSERT INTO settings (company_id, config_current_database_version, config_enable_cron) VALUES (1,'2.7.8',1) ON DUPLICATE KEY UPDATE config_enable_cron=1");
$q("INSERT INTO user_roles SET role_name='Investigation CI', role_is_admin=1"); $role=$id();
$q("INSERT INTO users SET user_name='Automation CI', user_email='ci@example.invalid', user_password='fixture', user_role_id=$role"); $user=$id();
$q("INSERT INTO api_keys SET api_key_name='Investigation CI', api_key_secret='investigation-ci-only', api_key_decrypt_hash='fixture', api_key_expire=DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR), api_key_user_id=$user"); $api=$id();
$q("INSERT INTO clients SET client_name='Approved CI', client_currency_code='USD', client_net_terms=30"); $client=$id();
$q("INSERT INTO clients SET client_name='Foreign CI', client_currency_code='USD', client_net_terms=30"); $foreign=$id();
$q("INSERT INTO ai_providers SET ai_provider_name='Fixture only', ai_provider_api_url='https://fixture.invalid/chat/completions', ai_provider_api_key='fixture-not-a-real-key'"); $provider=$id();
$q("INSERT INTO ai_models SET ai_model_name='fixture', ai_model_use_case='General', ai_model_ai_provider_id=$provider"); $model=$id();
putenv('N45_FEATURE_AUTOMATION=1'); putenv('N45_FEATURE_AUTOMATION_INVESTIGATION=1');
putenv('N45_AI_INVESTIGATION_CLIENT_IDS='.$client); putenv('N45_AI_INVESTIGATION_PROVIDER_HOST=fixture.invalid');
putenv('N45_AI_INVESTIGATION_DAILY_LIMIT=20');
$valid=['summary'=>'Monitoring reported an unavailable endpoint.', 'likely_cause'=>'Hypothesis: connectivity failure.', 'confidence'=>'low',
    'uncertainties'=>['No live diagnostic check was performed.'], 'recommended_checks'=>['Review current monitoring results.']];
$calls=0;
$transport=static function ($model,$evidence,$config) use (&$calls,$assert,$valid) {
    $calls++;
    $assert(!str_contains(json_encode($evidence), 'private-fixture'), 'Secret was sent to transport');
    $assert(!isset($evidence['metadata']), 'Raw metadata was sent to transport');
    return $valid;
};
$assert(investigationRun($transport)['status']==='configuration_required' && $calls===0, 'General model became an implicit opt-in');
$q("UPDATE ai_models SET ai_model_use_case='Automation Investigation' WHERE ai_model_id=$model");
$assert(investigationModel(investigationConfig())!==null,'Explicit provider/model configuration rejected');
$q("INSERT INTO ai_models SET ai_model_name='ambiguous', ai_model_use_case='Automation Investigation', ai_model_ai_provider_id=$provider"); $ambiguous=$id();
$assert(investigationModel(investigationConfig())===null,'Ambiguous models did not fail closed');
$q("DELETE FROM ai_models WHERE ai_model_id=$ambiguous");
foreach (['http://fixture.invalid/chat','https://other.invalid/chat','https://fixture.invalid/chat?token=x','https://user@fixture.invalid/chat'] as $url) {
    $safe=investigationSql($url); $q("UPDATE ai_providers SET ai_provider_api_url='$safe' WHERE ai_provider_id=$provider");
    $assert(investigationModel(investigationConfig())===null,'Unsafe/unapproved provider accepted');
}
$q("UPDATE ai_providers SET ai_provider_api_url='https://fixture.invalid/chat/completions' WHERE ai_provider_id=$provider");
$make=static function (int $tenant,string $tag,string $source='Automation', string $state='Open') use ($q,$id,$api,$user) {
    $tag=investigationSql($tag); $source=investigationSql($source); $state=investigationSql($state);
    $q("INSERT INTO tickets SET ticket_prefix='AI-', ticket_number=1, ticket_subject='$tag', ticket_details='Fixture', ticket_status=1, ticket_created_by=0, ticket_source='$source', ticket_client_id=$tenant"); $ticket=$id();
    $hash=hash('sha256',$tag);
    $q("INSERT INTO automation_incidents SET automation_incident_source='hetrix', automation_incident_key='$tag', automation_incident_title='$tag', automation_incident_status='$state', automation_incident_severity='high', automation_incident_ticket_id=$ticket, automation_incident_client_id=$tenant, automation_incident_last_event_hash='$hash', automation_incident_opened_at=NOW()"); $incident=$id();
    $payload=json_encode(['title'=>$tag,'description'=>'Monitor unavailable; password=private-fixture-secret', 'metadata'=>['raw'=>'private-fixture-metadata']]);
    $payload_hash=hash('sha256',$payload); $payload=investigationSql($payload);
    $q("INSERT INTO automation_events SET automation_event_source='hetrix', automation_event_external_id='$tag', automation_event_incident_key='$tag', automation_event_fingerprint='$hash', automation_event_state='open', automation_event_action='created', automation_event_status='Processed', automation_event_api_key_id=$api, automation_event_api_user_id=$user, automation_event_authorized_client_id=$tenant, automation_event_ticket_id=$ticket, automation_event_payload_hash='$payload_hash', automation_event_payload='$payload', automation_event_occurred_at=NOW()");
    return [$incident,$ticket,$id()];
};
$ready=static function () use ($q) {
    $q("UPDATE automation_investigation_rate SET last_started_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 61 SECOND), daily_calls=0, call_day=UTC_DATE() WHERE rate_id=1");
};
[$inc,$ticket,$event]=$make($client,'first');
$make($foreign,'foreign'); $make($client,'human','Client Portal'); $make($client,'resolved','Automation','Resolved');
$snapshot=static function () use ($q) {
    $result=[];
    foreach (['tickets','ticket_replies','automation_events','automation_incidents','api_keys','users','clients'] as $table) {
        $rows=mysqli_fetch_all($q("SELECT * FROM $table"),MYSQLI_ASSOC); $result[$table]=hash('sha256',json_encode($rows));
    }
    return $result;
};
$before=$snapshot();
$out=investigationRun($transport);
$assert($out['status']==='complete' && $calls===1,'Happy path did not produce a single accepted advisory');
$assert($snapshot()===$before,'Worker changed operational data');
$assert(intval($scalar('SELECT COUNT(*) FROM automation_investigations'))===1,'Foreign, human or resolved target was queued');
$assert(intval($scalar('SELECT COUNT(*) FROM automation_investigation_audit'))===3,'Queue/claim/completion receipts missing');
$job=mysqli_fetch_assoc($q('SELECT * FROM automation_investigations LIMIT 1'));
$assert($job['status']==='Complete' && $job['lease_token']===null,'Lease not finalized');
$assert(!str_contains($job['evidence_json'],'private-fixture'),'Evidence retained a secret');
$assert(abs(intval($scalar("SELECT TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP()) FROM automation_investigations LIMIT 1")))<10,'Created timestamp used the local session instead of UTC');
$assert(investigationRun($transport)['status']==='idle' && $calls===1,'Duplicate was billed again');
$q("UPDATE automation_incidents SET automation_incident_repeat_count=90 WHERE automation_incident_id=$inc");
$assert(investigationRun($transport)['status']==='idle' && $calls===1,'Repeat-count change was billed');
[$inc2,$ticket2]=$make($client,'second');
$assert(investigationRun($transport)['status']==='rate_limited' && $calls===1,'Minute rate cap bypassed');
$ready(); $q('UPDATE automation_investigation_rate SET daily_calls=20 WHERE rate_id=1');
$assert(investigationRun($transport)['status']==='rate_limited' && $calls===1,'Daily rate cap bypassed');
$ready(); $q('UPDATE settings SET config_enable_cron=0 WHERE company_id=1');
$assert(investigationRun($transport)['status']==='disabled' && $calls===1,'Master cron disable bypassed');
$q('UPDATE settings SET config_enable_cron=1 WHERE company_id=1');
// Simulated provider response races with a source recovery. Accepted output must be discarded.
$out=investigationRun(static function ($m,$e,$c) use ($q,$inc2,$transport) {
    $q("UPDATE automation_incidents SET automation_incident_status='Resolved' WHERE automation_incident_id=$inc2");
    return $transport($m,$e,$c);
});
$assert($out['status']==='superseded','Recovered incident received a current advisory');
$assert($scalar("SELECT result_json FROM automation_investigations WHERE incident_id=$inc2")===null,'Stale model result was stored');
$ready(); [$inc3,$ticket3]=$make($client,'provider-fails');
$out=investigationRun(static function () { throw new RuntimeException('private-fixture-provider-message'); });
$assert($out['status']==='failed','Provider failure not recorded');
$assert(!str_contains(json_encode(mysqli_fetch_all($q('SELECT * FROM automation_investigations'),MYSQLI_ASSOC)),'private-fixture-provider-message'),'Raw exception leaked');
$ready(); $assert(investigationRun($transport)['status']==='idle','Failed provider request retried blindly');
// Revoked principal: do not inherit the privileged cron connection.
[$inc4]=$make($client,'revoked-authority');
$q("UPDATE api_keys SET api_key_expire=CURRENT_DATE() WHERE api_key_id=$api"); $saved=$calls;
$assert(investigationRun($transport)['status']==='idle' && $calls===$saved,'Revoked origin was allowed to send data');
$assert($scalar("SELECT error_code FROM automation_investigations WHERE incident_id=$inc4")==='source_authority_changed','Authority failure lacks receipt');
$q("UPDATE api_keys SET api_key_expire=DATE_ADD(CURRENT_DATE(),INTERVAL 1 YEAR) WHERE api_key_id=$api");
// Force a pending row into an expired lease and prove stale owner cannot finalize it.
[$inc5]=$make($client,'interrupted');
$q('UPDATE automation_investigation_rate SET last_started_at=UTC_TIMESTAMP() WHERE rate_id=1');
$assert(investigationRun($transport)['status']==='rate_limited','Could not queue interruption fixture');
$q("UPDATE automation_investigations SET status='Processing', lease_token=REPEAT('a',64), lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE incident_id=$inc5");
$old=mysqli_fetch_assoc($q("SELECT * FROM automation_investigations WHERE incident_id=$inc5"));
$assert(investigationFinish($old,'Complete',$valid,'completed')==='LeaseLost','Expired lease committed output');
$ready(); $assert(investigationRun($transport)['status']==='idle','Interrupted generation was retried');
$assert($scalar("SELECT error_code FROM automation_investigations WHERE incident_id=$inc5")==='worker_interrupted','Interrupted receipt missing');
// Reassignment across tenants while pending must not emit evidence.
[$inc6,$ticket6]=$make($client,'reassigned');
$q('UPDATE automation_investigation_rate SET last_started_at=UTC_TIMESTAMP() WHERE rate_id=1');
investigationRun($transport); $q("UPDATE tickets SET ticket_client_id=$foreign WHERE ticket_id=$ticket6");
$ready(); $saved=$calls;
$assert(investigationRun($transport)['status']==='idle' && $calls===$saved,'Reassigned ticket emitted evidence');
$assert($scalar("SELECT status FROM automation_investigations WHERE incident_id=$inc6")==='Superseded','Cross-tenant reassignment was not fenced');
// A discovery result captured before deletion must not resurrect retained evidence.
[$deleted_inc,$deleted_ticket,$deleted_event]=$make($client,'deleted-before-enqueue');
$discovered=mysqli_fetch_assoc($q("SELECT i.*, e.automation_event_id, e.automation_event_payload, e.automation_event_occurred_at
    FROM automation_incidents i INNER JOIN automation_events e ON e.automation_event_id=$deleted_event
    WHERE i.automation_incident_id=$deleted_inc"));
$q("DELETE FROM tickets WHERE ticket_id=$deleted_ticket");
$assert(investigationEnqueue($discovered,investigationConfig())===false,'Stale discovery recreated evidence after deletion');
$assert(intval($scalar("SELECT COUNT(*) FROM automation_investigations WHERE ticket_id=$deleted_ticket"))===0,'Deleted ticket gained an orphan investigation');
// Authorized technician-only UI projection, no cross-tenant result.
$session_is_admin=true; $session_user_id=$user; $session_user_role=$role;
$assert(investigationTicketAdvisory($ticket,$client)!==null,'Authorized agent cannot read advisory');
$assert(investigationTicketAdvisory($ticket,$foreign)===null,'Cross-tenant advisory read leaked');
$q("UPDATE automation_incidents SET automation_incident_status='Resolved' WHERE automation_incident_id=$inc");
$assert(!investigationTicketAdvisory($ticket,$client)['current_signal'],'Historical result appears current');
$q("UPDATE automation_investigations SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY)");
putenv('N45_FEATURE_AUTOMATION_INVESTIGATION=0'); investigationRetention();
$assert(intval($scalar('SELECT COUNT(*) FROM automation_investigations WHERE evidence_json IS NOT NULL OR result_json IS NOT NULL'))===0,'Disabled feature prevented retention cleanup');
$assert(intval($scalar('SELECT COUNT(*) FROM automation_investigations'))>0,'Retention destroyed dedupe tombstones');
$old_job=intval($job['investigation_id']); investigationDeleteTicket($ticket);
$assert(intval($scalar("SELECT COUNT(*) FROM automation_investigations WHERE ticket_id=$ticket"))===0,'Permanent deletion left evidence');
$assert(intval($scalar("SELECT COUNT(*) FROM automation_investigation_audit WHERE investigation_id=$old_job"))===0,'Permanent deletion left orphan audit data');
echo "Investigation database: $count assertions passed; provider transport was mocked, no external calls.\n";
