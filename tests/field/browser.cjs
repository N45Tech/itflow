// Synthetic browser fixtures only; no live PSA credentials or customer data.
const {chromium}=require('playwright');
const http=require('node:http');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const screenshots=process.env.N45_FIELD_SCREENSHOTS||path.join(root,'.impeccable/review');
fs.mkdirSync(screenshots,{recursive:true});
const now=new Date().toISOString();
const job={ticket_id:101,reference:'N45-1042',subject:'Replace the branch firewall',client_id:12,client_name:'Example Client',location_id:3,location_name:'North branch',address:'10 Example Way, Test City',scheduled_at:now,priority:'High',work_type:'project',status:'In Progress',project_id:9,project_name:'Branch network refresh',assigned_name:'Alex Morgan',assigned_to:7,terminal:false,pin_valid:true,pin_latitude:45.5,pin_longitude:-73.5,pin_radius_meters:150,address_hash:'testhash',details:'Replace the firewall and verify connectivity before handing the site back to the branch manager.',next_action:'Confirm the backup, then connect the replacement appliance.',site_notes:'Call the branch manager at the front entrance. The network cabinet is in the staff room.',site_hours:'Monday–Friday, 8 am–5 pm',contact:{name:'Jordan Lee',phone:'5550100100',email:'jordan@example.invalid'},promises:[],tasks:[{task_id:21,task_name:'Verify the configuration backup',task_instructions:'Check the backup timestamp and confirm the restore procedure.',task_state:'Ready',task_assigned_to:7,assigned_name:'Alex Morgan',task_due_at:null,task_waiting_reason:null,task_evidence_required:'note',task_evidence_prompt:'Record the backup timestamp and validation result.',task_completed_at:null,can_complete:false,completion_error:'Required note evidence must be added first.',dependencies:[]}],documents:[{document_id:4,document_name:'North branch network and site access',document_description:'Access instructions, network diagram, and escalation contacts',last_verified_at:now,asset_linked:1}],assets:[{asset_id:2,asset_name:'Branch firewall',asset_type:'Firewall',asset_make:'Example',asset_model:'FW-40',asset_serial:'EX-1042'}],owners:[{user_id:7,user_name:'Alex Morgan'}],blockers:[],notes:[],attachments:[]};
const data={user:{id:7,name:'Alex Morgan',write:true,admin:true,verify_site:true,documents:true},csrf_token:'fixture-csrf',theme:'light',jobs:[job],projects:[{project_id:9,project_name:job.project_name,client_name:job.client_name,manager_name:'Alex Morgan',project_due:'2026-09-18',ticket_count:3,completed_count:1}],visits:[],issues:[],server_time:now};
const writes=[];let dropNextNote=false;let matchCalls=0;let noMatches=false;
const server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/agent/field/api.php'){
    res.setHeader('content-type','application/json');res.setHeader('cache-control','no-store');
    let input=null;if(req.method==='POST'){let body='';for await(const chunk of req)body+=chunk;input=JSON.parse(body);}
    const action=input?.action||url.searchParams.get('action');let result;
    if(action==='boot')result=data;
    else if(action==='ticket')result=url.searchParams.get('ticket_id')==='102'?{...job,ticket_id:102,reference:'N45-1043',subject:'Validate the guest network'}:job;
    else if(action==='match'){matchCalls++;result={candidates:noMatches?[]:[{ticket_id:101,location_id:3,distance_meters:5}]};}
    else if(action==='project')result={...data.projects[0],project_description:'Upgrade the branch network with validated handover.',jobs:[job,{...job,ticket_id:102,reference:'N45-1043',subject:'Validate the guest network',assigned_to:8,tasks:[{...job.tasks[0],task_id:22,task_assigned_to:8,assigned_name:'Taylor Jones',task_name:'Test guest isolation'}]}],blockers:job.blockers};
    else if(action==='document')result={id:4,name:job.documents[0].document_name,content:'Site access\nCall the branch manager.\n<script>alert("xss")</script>'};
    else if(action==='documents')result=job.documents;
    else if(action==='assets')result=job.assets;
    else if(input){writes.push(input);assert.equal(input.csrf_token,'fixture-csrf');assert.match(input.request_key,/^[0-9a-f-]{36}$/);
      if(action==='visit'){
        assert.equal(Number(input.confirm_onsite),1);data.visits=[{visit_id:1,visit_ticket_id:101,visit_client_id:12,visit_location_id:3,visit_user_id:7,visit_active_user_id:7,visit_status:'onsite',visit_started_at:now,visit_arrived_at:now,visit_finished_at:null,visit_share_location:0,client_name:job.client_name,ticket_prefix:'N45-',ticket_number:1042,ticket_subject:job.subject,segments:[{segment_id:1,segment_ticket_id:101,segment_active_visit_id:1,segment_kind:'onsite',segment_started_at:now,segment_ended_at:null,segment_submitted_at:null,ticket_subject:job.subject}]}];result={message:'Onsite recorded.',visit_id:1};
      }else if(action==='note'){
        assert.ok(input.work_action&&input.work_result&&input.work_next_step);assert.equal(input.task_id,0);
        job.notes=[{ticket_reply_id:1,ticket_reply:input.work_action,ticket_reply_type:'Internal',ticket_reply_created_at:now,user_name:'Alex Morgan'}];result={message:'Work note saved.',reply_id:1};
        if(dropNextNote){dropNextNote=false;res.statusCode=500;res.end(JSON.stringify({error:'The commit response was lost. Retry this form.'}));return;}
      }else if(action==='issue_update'){assert.ok(input.note,'Issue response must reach the server note field');result={message:'Issue resolved.'};}
      else result={message:'Saved.'};
    }
    else {res.statusCode=404;res.end(JSON.stringify({error:'Missing fixture'}));return;}
    res.end(JSON.stringify({data:result}));return;
  }
  const target=url.pathname==='/agent/field/'?'/agent/field/shell.html':url.pathname;
  const file=path.join(root,target);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.statusCode=404;res.end();return;}
  const types={'.html':'text/html','.mjs':'text/javascript','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.webmanifest':'application/manifest+json'};
  res.setHeader('content-type',types[path.extname(file)]||'application/octet-stream');res.end(fs.readFileSync(file));
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
  const context=await browser.newContext({viewport:{width:393,height:852},isMobile:true,hasTouch:true,geolocation:{latitude:45.5,longitude:-73.5,accuracy:12},permissions:['geolocation']});
  const page=await context.newPage();const errors=[];page.on('pageerror',err=>errors.push(err.message));
  await page.goto(base+'/agent/field/');await page.waitForURL('**/#job/101/overview');await page.getByRole('heading',{name:'At the right stop?'}).waitFor();
  assert.equal(writes.length,0,'Opening a matched job must not mark onsite');
  await page.screenshot({path:path.join(screenshots,'mobile.png'),fullPage:false});
  const desktop=await context.newPage();await desktop.setViewportSize({width:1440,height:1000});await desktop.goto(base+'/agent/field/#project/9');await desktop.getByRole('button',{name:'Entire project'}).click();await desktop.screenshot({path:path.join(screenshots,'desktop.png'),fullPage:false});await page.bringToFront();
  await page.getByRole('button',{name:'Mark onsite',exact:true}).click();assert.equal(writes.length,0,'Opening arrival confirmation must not write');
  await page.getByRole('button',{name:'Confirm onsite',exact:true}).click();await page.getByRole('heading',{name:'Onsite',exact:true}).waitFor();assert.equal(writes.filter(w=>w.action==='visit').length,1);
  await page.getByRole('link',{name:'Notes',exact:true}).click();await page.getByRole('heading',{name:'Guided work note'}).waitFor();
  await page.locator('[name=work_action]').fill('Filled note before recovery');await page.locator('[name=work_result]').fill('A meaningful result');await page.locator('[name=work_next_step]').fill('A meaningful next step');
  const notesBeforeRecovery=writes.filter(w=>w.action==='note').length;
  await page.getByRole('button',{name:'Enable encrypted recovery'}).click();assert.equal(writes.filter(w=>w.action==='note').length,notesBeforeRecovery,'Recovery action submitted a note');await page.getByLabel('Recovery passphrase',{exact:true}).fill('fixture passphrase 45');await page.getByLabel('Confirm passphrase').fill('fixture passphrase 45');await page.getByRole('button',{name:'Enable recovery',exact:true}).click();await page.waitForFunction(()=>!document.querySelector('#sheet').open);
  await page.locator('[name=work_action]').fill('Secret fixture note: verified backup');await page.locator('[name=work_result]').fill('Backup restored successfully');await page.locator('[name=work_next_step]').fill('Proceed with the firewall replacement');
  await page.waitForFunction(()=>document.querySelector('#note-save-state')?.textContent.includes('Encrypted draft saved'));
  const stored=await page.evaluate(()=>JSON.stringify(localStorage));assert.ok(!stored.includes('Secret fixture'),'Draft plaintext entered persistent storage');
  await page.reload();await page.getByRole('heading',{name:'Guided work note'}).waitFor();assert.equal(await page.locator('[name=work_action]').inputValue(),'','Reload exposed a locked draft');
  await page.getByRole('link',{name:'Drafts',exact:true}).click();await page.getByRole('button',{name:'Unlock note recovery'}).click();await page.getByLabel('Recovery passphrase').fill('wrong passphrase');await page.getByRole('button',{name:'Unlock notes',exact:true}).click();await page.getByText('The recovery passphrase did not unlock these notes.',{exact:true}).waitFor();
  await page.getByLabel('Recovery passphrase').fill('fixture passphrase 45');await page.getByRole('button',{name:'Unlock notes',exact:true}).click();await page.getByRole('button',{name:'Open draft',exact:true}).waitFor();
  await context.setOffline(true);await page.reload();await page.getByRole('button',{name:'Unlock note recovery'}).waitFor();await page.getByRole('button',{name:'Unlock note recovery'}).click();await page.getByLabel('Recovery passphrase').fill('fixture passphrase 45');await page.getByRole('button',{name:'Unlock notes',exact:true}).click();await page.getByRole('button',{name:'Open draft'}).click();await page.getByRole('heading',{name:'Guided work note'}).waitFor();assert.equal(await page.locator('[name=work_action]').inputValue(),'Secret fixture note: verified backup');
  await page.locator('[name=work_result]').fill('Offline recovery verified');await page.waitForFunction(()=>document.querySelector('#note-save-state')?.textContent.includes('Encrypted draft saved'));
  await context.setOffline(false);await page.reload();await page.getByRole('button',{name:'Unlock note recovery'}).click();await page.getByLabel('Recovery passphrase').fill('fixture passphrase 45');await page.getByRole('button',{name:'Unlock notes',exact:true}).click();await page.getByRole('button',{name:'Open draft'}).click();await page.getByRole('heading',{name:'Guided work note'}).waitFor();
  dropNextNote=true;await page.getByRole('button',{name:'Save work note',exact:true}).click();await page.locator('#note-form .error-text').waitFor();
  await page.getByRole('button',{name:'Save work note',exact:true}).click(); // Retry the exact payload after a lost response.
  await page.waitForFunction(()=>document.querySelector('#notice')?.textContent==='Work note saved.');
  const notes=writes.filter(w=>w.action==='note');assert.equal(notes.length,2);assert.deepEqual(notes[0],notes[1],'A dropped response changed the retry payload');
  await page.getByRole('link',{name:'Documentation',exact:true}).click();await page.getByRole('button',{name:job.documents[0].document_name}).click();assert.equal(await page.locator('#doc-content script').count(),0,'Document content was executable');
  await page.getByRole('link',{name:'Projects',exact:true}).click();await page.getByRole('link',{name:job.project_name,exact:true}).click();await page.getByRole('button',{name:'Entire project'}).click();await page.getByText('Test guest isolation',{exact:true}).waitFor();await page.getByRole('button',{name:'My tasks',exact:true}).click();assert.equal(await page.getByText('Test guest isolation',{exact:true}).count(),0);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile layout overflows');
  const cached=await page.evaluate(async()=>{const urls=[];for(const key of await caches.keys())for(const req of await(await caches.open(key)).keys())urls.push(req.url);return urls;});assert.ok(cached.length);assert.ok(cached.every(url=>!url.includes('api.php')&&!url.includes('/uploads/')&&!url.includes('/client/')),'Private data entered the service worker cache');
  await page.getByRole('button',{name:'Cover screen'}).click();await page.locator('#privacy').waitFor({state:'visible'});assert.equal(await page.locator('#work').evaluate(el=>el.inert),true);
  await page.getByRole('button',{name:'Reopen Field Mode'}).click();await page.getByRole('button',{name:'My tasks'}).waitFor();
  assert.equal(await desktop.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Desktop layout overflows');
  const second=await browser.newContext({viewport:{width:393,height:852},geolocation:{latitude:45.5,longitude:-73.5,accuracy:12},permissions:['geolocation']});
  const temp=await second.newPage();await temp.goto(base+'/agent/field/#job/101/notes');await temp.locator('[name=work_action]').fill('Keep this unsaved note in this tab');
  await temp.evaluate(()=>location.hash='#job/102/notes');await temp.getByRole('heading',{name:'Validate the guest network',exact:true}).waitFor();await temp.locator('[name=work_action]').fill('Separate second job note');
  await temp.evaluate(()=>location.hash='#job/101/notes');await temp.getByRole('heading',{name:'Replace the branch firewall',exact:true}).waitFor();assert.equal(await temp.locator('[name=work_action]').inputValue(),'Keep this unsaved note in this tab','Switching tickets lost a note without an unlocked vault');
  await temp.getByRole('link',{name:'Issues',exact:true}).first().click();await temp.getByRole('button',{name:'Report issue',exact:true}).click();await temp.locator('#issue-form [name=title]').fill('Keep this open issue form');
  await temp.evaluate(()=>{window.originalDateNow=Date.now;const later=Date.now()+61000;Date.now=()=>later;});await temp.locator('#privacy').waitFor({state:'visible'});assert.equal(await temp.locator('#sheet').evaluate(el=>el.open),false,'Privacy cover left the modal document inert');
  await temp.evaluate(()=>Date.now=window.originalDateNow);const beforeResume=matchCalls;await temp.getByRole('button',{name:'Reopen Field Mode'}).click();await temp.waitForFunction(()=>document.querySelector('#sheet').open);assert.equal(await temp.locator('#issue-form [name=title]').inputValue(),'Keep this open issue form','Privacy cover discarded the form');assert.ok(matchCalls>beforeResume,'Resume skipped foreground arrival matching');
  await temp.getByRole('button',{name:'Close',exact:true}).click();data.visits=[];
  await temp.evaluate(()=>location.hash='#job/101/overview');await temp.getByRole('heading',{name:'Replace the branch firewall',exact:true}).waitFor();
  await temp.getByRole('button',{name:'Cover screen'}).click();await temp.getByRole('button',{name:'Reopen Field Mode'}).click();await temp.getByRole('heading',{name:'At the right stop?',exact:true}).waitFor();
  await temp.evaluate(()=>{window.originalGetPosition=navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);navigator.geolocation.getCurrentPosition=(success,error)=>error({code:1});});
  await temp.getByRole('button',{name:'Cover screen'}).click();await temp.getByRole('button',{name:'Reopen Field Mode'}).click();await temp.getByRole('heading',{name:'Start this visit',exact:true}).waitFor();assert.equal(await temp.getByRole('heading',{name:'At the right stop?',exact:true}).count(),0,'Denied location retained a stale arrival match');
  await temp.evaluate(()=>navigator.geolocation.getCurrentPosition=window.originalGetPosition);noMatches=true;
  await temp.getByRole('button',{name:'Cover screen'}).click();await temp.getByRole('button',{name:'Reopen Field Mode'}).click();await temp.waitForFunction(()=>document.querySelector('#notice').textContent.startsWith('No verified scheduled stop'));assert.equal(await temp.getByRole('heading',{name:'At the right stop?',exact:true}).count(),0,'An empty match result retained the old job cue');
  await second.close();
  assert.deepEqual(errors,[],'Browser JavaScript errors');
  console.log('Field browser checks passed: confirmed arrival, encrypted offline recovery, exact retry, project filtering, safe document rendering, private-cache exclusion, and responsive layouts.');
 } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(err=>{console.error(err);server.close();process.exitCode=1;});
