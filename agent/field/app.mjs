import {DraftVault, knownAccounts} from './drafts.mjs';
const $ = (selector, root = document) => root.querySelector(selector);
const e = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const n = value => Number(value) || 0;
const fmt = (value, options = {}) => value ? new Date(value).toLocaleString(undefined, {month:'short',day:'numeric',hour:'numeric',minute:'2-digit',...options}) : 'Not scheduled';
const clock = value => value ? new Date(value).toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}) : 'Any time';
const label = value => ({travel:'Traveling',onsite:'Onsite',waiting:'Waiting',break:'On break',finished:'Finished'}[value] || value);
const state = {notes:new Map(),boot:null,job:null,project:null,online:false,route:0,position:null,matches:[],locationMessage:'',vault:null,note:null,noteTicket:0,saveTimer:null,dirty:false,lastActivity:Date.now(),watch:null,lastPositionSend:0,stream:null,projectFilter:'mine',cover:false};
let noticeTimer, installPrompt;
function notice(message) { $('#notice').textContent=message; $('#notice').hidden=false; clearTimeout(noticeTimer); noticeTimer=setTimeout(()=>$('#notice').hidden=true,7000); }
function connection(online) { state.online=online; $('#connection').textContent=online?'Connected':'Offline'; }
async function api(action, payload=null, query={}) {
  const options={credentials:'same-origin',cache:'no-store',redirect:'manual'};
  let url='/agent/field/api.php?'+new URLSearchParams({action,...query});
  if (payload) {
    options.method='POST';
    if (payload instanceof FormData) { payload.set('action',action);payload.set('csrf_token',state.boot?.csrf_token||'');options.body=payload; }
    else {options.headers={'Content-Type':'application/json'};options.body=JSON.stringify({...payload,action,csrf_token:state.boot?.csrf_token||''});}
  }
  let response;
  try {response=await fetch(url,options);} catch {connection(false);throw new Error('Connection unavailable. Keep your work here, reconnect, then retry. If your session ended, sign in through the PSA.');}
  if (response.type==='opaqueredirect' || response.status===401 || !response.headers.get('content-type')?.includes('application/json')) {state.boot=null;state.job=null;state.project=null;connection(false);throw new Error('Sign in again through the PSA, then reopen Field Mode.');}
  const body=await response.json();connection(true);
  if (!response.ok || body.error) {const error=new Error(body.error||'The update could not be confirmed. Retry this form.');error.status=response.status;throw error;}
  return body.data;
}
async function boot() {
  state.boot=await api('boot');document.documentElement.dataset.theme=state.boot.theme;
  if (state.vault?.userId!==String(state.boot.user.id)) {state.vault?.lock();state.vault=new DraftVault(state.boot.user.id);state.note=null;state.notes.clear();state.dirty=false;if(state.coveredSheet){closeSheet();state.coveredSheet=false;}}
}
const activeVisit = () => state.boot?.visits.find(v=>v.visit_active_user_id!==null);
const writable = () => !!state.boot?.user.write;
const jobHref = (id,panel='overview') => `#job/${n(id)}/${panel}`;
const actionButton = (action,text,attrs='',secondary=false) => `<button type="button" data-action="${action}" ${attrs} class="${secondary?'secondary':''}">${text}</button>`;
const empty = (title,copy) => `<div class="empty"><h2>${e(title)}</h2><p class="muted">${e(copy)}</p></div>`;
function header(title,description,actions='') {return `<div class="page-head"><div><h1>${e(title)}</h1><p class="muted">${e(description)}</p></div>${actions?`<div class="heading-actions">${actions}</div>`:''}</div>`;}
function jobRow(job) {
  return `<div class="list-row"><div class="schedule-time">${e(clock(job.scheduled_at))}</div><div class="row-main"><a class="title" href="${jobHref(job.ticket_id)}">${e(job.subject)}</a><p class="muted">${e(job.client_name)} · ${e(job.location_name)}</p><div class="meta"><span>${e(job.reference)}</span><span>${e(job.scheduled_at?fmt(job.scheduled_at,{hour:undefined,minute:undefined}):'Unscheduled')}</span>${job.project_name?`<span>${e(job.project_name)}</span>`:''}</div></div><span class="tag ${['high','critical'].includes(job.priority)?'attention':''}">${e(job.status||job.priority)}</span></div>`;
}
function activeBanner() {
  const v=activeVisit();if(!v)return '';
  const segment=v.segments.find(s=>s.segment_active_visit_id!==null);
  return `<section class="current-visit" aria-label="Active visit"><div><span class="tag">${e(label(v.visit_status))}</span><h2>${e(segment?.ticket_subject||v.ticket_subject)}</h2><p class="muted">${e(v.client_name)} · <span class="time-number" data-elapsed="${e(segment?.segment_started_at||v.visit_started_at)}"></span></p></div><div class="actions"><a class="button" href="${jobHref(segment?.segment_ticket_id||v.visit_ticket_id)}">Open current job</a><a class="button secondary" href="#time">Manage visit</a></div></section>`;
}
function matchesMarkup() {
  if(!state.matches.length)return '';
  const jobs=state.matches.map(m=>state.boot.jobs.find(j=>n(j.ticket_id)===n(m.ticket_id))).filter(Boolean);
  return `<section class="match"><h2>${jobs.length===1?'This looks like your scheduled stop.':'More than one job fits this location.'}</h2><p class="muted">${jobs.length===1?'Open the job to confirm you are onsite.':'Choose the job you are here for, then confirm your arrival.'}</p><div class="match-choices">${jobs.map(j=>`<a class="button secondary" href="${jobHref(j.ticket_id)}">${e(j.reference)} · ${e(j.subject)}</a>`).join('')}</div></section>`;
}
function today() {
  const jobs=state.boot.jobs;
  const todayKey=new Date().toDateString();
  const scheduled=jobs.filter(j=>j.scheduled_at&&new Date(j.scheduled_at).toDateString()===todayKey).sort((a,b)=>new Date(a.scheduled_at)-new Date(b.scheduled_at));
  const other=jobs.filter(j=>!scheduled.includes(j));
  return header('Today’s work',new Date().toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'}),actionButton('locate','Find my stop','',true))
    +activeBanner()+matchesMarkup()+`<p class="hint" id="location-message">${e(state.locationMessage||'Your schedule and location help identify the right stop. You confirm every arrival.')}</p>`
    +`<div class="section-head"><h2>On the schedule</h2><span class="muted">${scheduled.length} jobs</span></div>`
    +(scheduled.length?`<div class="list">${scheduled.map(jobRow).join('')}</div>`:empty('No scheduled stops today','Your assigned work appears below. You can open any job and check in manually.'))
    +(other.length?`<div class="section-head"><h2>Other assigned work</h2></div><div class="list">${other.map(jobRow).join('')}</div>`:'');
}
function jobTop(j,panel) {
  return `<a class="back" href="#today">Back to today</a><div class="job-top"><div class="meta"><strong>${e(j.reference)}</strong><span>${e(j.client_name)}</span><span class="tag">${e(j.status)}</span></div><h1>${e(j.subject)}</h1><p class="muted">${e(j.location_name)}${j.address?' · '+e(j.address):''}</p><div class="meta"><span>${e(fmt(j.scheduled_at))}</span><span>${e(j.assigned_name||'Unassigned')}</span>${j.project_id?`<a href="#project/${j.project_id}">${e(j.project_name)}</a>`:''}<a href="/agent/ticket.php?ticket_id=${j.ticket_id}">Full ticket in PSA</a></div></div><nav class="job-tabs" aria-label="Job sections">${[['overview','Job'],['docs','Documentation'],['tasks','Tasks'],['notes','Notes'],['issues','Issues'],['photos','Photos']].map(([id,title])=>`<a href="${jobHref(j.ticket_id,id)}" ${panel===id?'aria-current="page"':''}>${title}</a>`).join('')}</nav>`;
}
function visitControls(j) {
  if(j.terminal)return '<p class="hint">This ticket is complete. Reopen it in the PSA before adding field work.</p>';
  if(!writable())return '<p class="hint">You have view access to this job.</p>';
  const v=activeVisit();const matching=state.matches.some(m=>n(m.ticket_id)===j.ticket_id);
  if(v && (n(v.visit_client_id)!==j.client_id || n(v.visit_location_id)!==j.location_id)) return `<p class="hint">A visit is running at another site. Finish it before starting this job.</p><a class="button secondary" href="#time">Open active visit</a>`;
  const s=v?.segments.find(s=>s.segment_active_visit_id!==null);
  const current=v&&n(s?.segment_ticket_id)===j.ticket_id;
  return `<section class="${matching&&!v?'match':'subsection'}"><h2>${matching&&!v?'At the right stop?':current?label(v.visit_status):v?'Work on this ticket':'Start this visit'}</h2><p class="hint">${matching&&!v?'Your location and schedule match this job. Confirm before marking yourself onsite.':v?'Time stays in one visit. Changing the activity ends the previous timer.':'Confirm arrival to start onsite time, or start with travel.'}</p><div class="actions">${(!current||v?.visit_status!=='onsite')?actionButton('arrival','Mark onsite',`data-ticket="${j.ticket_id}"`):''}${!v?actionButton('travel','Start travel',`data-ticket="${j.ticket_id}"`,true):`<a class="button secondary" href="#time">Manage visit</a>`}</div></section>`;
}
function overview(j) {
  const phone=(j.contact.phone||'').replace(/[^+\d,;#*]/g,'');
  return visitControls(j)+`<div class="split"><div><section class="subsection"><h2>Work to do</h2><p class="prose">${e(j.details||'No job instructions have been added.')}</p></section>${j.next_action?`<section class="subsection"><h2>Next action</h2><p>${e(j.next_action)}</p></section>`:''}<div class="actions"><a class="button" href="${jobHref(j.ticket_id,'notes')}">Write a work note</a><a class="button secondary" href="${jobHref(j.ticket_id,'issues')}">Report an issue</a></div></div><aside class="aside"><h2>Before you start</h2><p><strong>${e(j.contact.name||'No contact set')}</strong></p>${phone?`<a class="button secondary" href="tel:${e(phone)}">Call contact</a>`:''}${j.address?`<p><a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(j.address)}" target="_blank" rel="noopener noreferrer">Directions to site</a></p>`:''}<p class="prose">${e(j.site_notes||'No site access notes available.')}</p>${j.site_hours?`<p class="muted">Hours: ${e(j.site_hours)}</p>`:''}<p class="hint">${j.pin_valid?'Arrival pin verified for this address.':'This address needs a verified arrival pin for automatic matching.'}</p>${state.boot.user.verify_site&&j.location_id&&!j.terminal?actionButton('pin',j.pin_valid?'Update site pin':'Verify this site',`data-ticket="${j.ticket_id}"`,true):''}${j.promises.length?`<h3 class="subsection">Customer commitments</h3>${j.promises.map(p=>`<p>${e(p.ticket_customer_promise_summary)}<br><span class="muted">Due ${e(fmt(p.ticket_customer_promise_due_at))}</span></p>`).join('')}`:''}</aside></div>`;
}
function docRows(docs) {
  return docs.length?`<div class="list">${docs.map(d=>`<div class="list-row"><div class="row-main"><button class="quiet" data-action="document" data-id="${n(d.document_id)}">${e(d.document_name)}</button><p class="muted">${e(d.document_description||'')}</p><div class="meta"><span>${d.last_verified_at?'Verified '+e(fmt(d.last_verified_at)):'Verification not recorded'}</span>${n(d.asset_linked)?'<span class="tag">Linked asset</span>':''}</div></div></div>`).join('')}</div>`:empty('No matching documents','Report what is missing so the owner can correct it.');
}
function docs(j) {
  if(!state.boot.user.documents)return empty('Documentation access required','Your administrator can grant client documentation access.');
  return `<div class="section-head"><h2>Client documentation</h2>${writable()&&!j.terminal?actionButton('new-issue','Report missing docs','data-kind="documentation"',true):''}</div><form id="doc-search" class="search"><input name="q" aria-label="Search client documentation" placeholder="Search access notes, diagrams, runbooks…" maxlength="200"><button>Search</button></form><div id="doc-list">${docRows(j.documents)}</div><div id="doc-content"></div><section class="subsection"><div class="section-head"><h2>Assets at this site</h2>${actionButton('scan','Scan code','',true)}</div><form id="asset-search" class="search"><input name="q" required maxlength="200" aria-label="Asset name or serial number" placeholder="Asset name or serial number"><button>Find</button></form><div id="assets">${assetRows(j.assets)}</div></section>`;
}
function assetRows(assets) {return assets.length?`<div class="list">${assets.map(a=>`<div class="list-row"><div><h3>${e(a.asset_name)}</h3><p class="muted">${e([a.asset_make,a.asset_model,a.asset_serial].filter(Boolean).join(' · '))}</p></div><a href="/agent/asset.php?client_id=${n(state.job?.client_id)}&asset_id=${n(a.asset_id)}">Open asset</a></div>`).join('')}</div>`:empty('No assets found','Search by name or serial number. Scanning fills the same search.');}
function taskRows(tasks,ticketId) {
  return tasks.length?`<ul class="task-list">${tasks.map(t=>`<li class="task ${t.task_completed_at?'task-completed':''}"><div class="meta"><span class="tag">${e(t.task_state)}</span><span>${e(t.assigned_name||'Unassigned')}</span>${t.task_due_at?`<span>Due ${e(fmt(t.task_due_at))}</span>`:''}</div><h3>${e(t.task_name)}</h3>${t.task_instructions?`<p class="prose">${e(t.task_instructions)}</p>`:''}${t.dependencies.length?`<details><summary>${t.dependencies.length} dependencies</summary>${t.dependencies.map(d=>`<p class="muted">${e(d.task_name)} · ${e(d.task_state)}</p>`).join('')}</details>`:''}${t.task_waiting_reason?`<p class="hint">${e(t.task_waiting_reason)}</p>`:''}${t.task_evidence_required!=='none'?`<p class="hint">Evidence: ${e(t.task_evidence_prompt||t.task_evidence_required)}</p>`:''}${!t.can_complete&&!t.task_completed_at?`<p class="hint">${e(t.completion_error)}</p>`:''}${writable()&&!t.task_completed_at&&t.task_state!=='Skipped'?`<div class="actions">${actionButton('complete-task','Complete task',`data-task="${n(t.task_id)}" data-ticket="${n(ticketId)}" ${!t.can_complete?'disabled':''}`)}<a class="button secondary" href="${jobHref(ticketId,'notes')}?task=${n(t.task_id)}">Add note</a><a class="button secondary" href="${jobHref(ticketId,'photos')}?task=${n(t.task_id)}">Add photo</a>${actionButton('new-issue','Report issue',`data-task="${n(t.task_id)}" data-ticket="${n(ticketId)}"`,true)}</div>`:''}</li>`).join('')}</ul>`:empty('No tasks on this job','Use the job instructions and record your work in Notes.');
}
function taskOptions(j,selected=0) {return `<option value="0">Whole ticket</option>${j.tasks.filter(t=>!t.task_completed_at&&t.task_state!=='Skipped').map(t=>`<option value="${n(t.task_id)}" ${n(selected)===n(t.task_id)?'selected':''}>${e(t.task_name)}</option>`).join('')}`;}
const templates = {troubleshooting:['What did you investigate or change?','What did testing show?','What remains, or how was the fix confirmed?'],installation:['What did you install and configure?','What passed acceptance testing?','What is needed for handover?'],maintenance:['What checks or maintenance did you perform?','What did you find?','What follow-up is needed?'],survey:['What did you inspect or measure?','What conditions or requirements did you find?','What should happen next?'],project:['What project work did you complete?','What changed or passed validation?','What task or dependency comes next?']};
async function notes(j, task=0, offline=false) {
  if(!state.note || state.noteTicket!==j.ticket_id) {
    let existing=state.notes.get(j.ticket_id);
    if(!existing&&state.vault?.key){existing=(await state.vault.list()).find(d=>n(d.ticket_id)===j.ticket_id&&!d.unreadable);if(existing)existing.persisted_at=existing.saved_at;}
    state.note=existing||{id:crypto.randomUUID(),request_key:crypto.randomUUID(),ticket_id:j.ticket_id,reference:j.reference,subject:j.subject,template:'troubleshooting',task_id:task,work_action:'',work_result:'',work_next_step:'',additional_details:''};state.noteTicket=j.ticket_id;state.dirty=!!existing;
  }
  const d=state.note;const hints=templates[d.template]||templates.troubleshooting;
  return `${!j.terminal&&(writable()||offline)?`<form id="note-form" class="form-grid inline-form"><div><h2>Guided work note</h2><p class="hint">Internal note · Use your keyboard microphone for dictation.</p></div><label>Note template<select name="template">${Object.keys(templates).map(k=>`<option value="${k}" ${d.template===k?'selected':''}>${e(k[0].toUpperCase()+k.slice(1))}</option>`).join('')}</select></label><label>Attach evidence to<select name="task_id">${taskOptions(j,task||d.task_id)}</select></label>${[['work_action','Action taken',hints[0]],['work_result','Result',hints[1]],['work_next_step','Next step',hints[2]]].map(([name,title,hint])=>`<label>${title}<textarea name="${name}" required minlength="2" maxlength="500" placeholder="${e(hint)}">${e(d[name])}</textarea></label>`).join('')}<label>Additional detail <span class="muted">Optional</span><textarea name="additional_details" maxlength="10000">${e(d.additional_details)}</textarea></label><div><p class="save-state" id="note-save-state">${state.vault?.key?(d.saved_at?'Encrypted draft recovered.':'Encrypted recovery is ready.'):'Draft stays in this tab until you enable recovery.'}</p>${!state.vault?.key?actionButton('vault',state.vault?.configured?'Unlock note recovery':'Enable encrypted recovery','',true):''}</div><div class="actions"><button type="submit">Save work note</button><p class="hint">Time is reviewed separately.</p></div></form>`:''}<div class="section-head"><h2>Recent notes</h2></div><div class="list">${j.notes.map(note=>`<article class="list-row"><div><div class="note-meta"><strong>${e(note.user_name||'System')}</strong><span>${e(fmt(note.ticket_reply_created_at))}</span><span>${e(note.ticket_reply_type)}</span></div><p class="prose note-body">${e(note.ticket_reply)}</p></div></article>`).join('')||'<p class="hint">No notes yet.</p>'}</div>`;
}
function issueRows(issues) {
  return issues.length?`<div class="list">${issues.map(i=>`<article class="list-row"><div class="row-main"><div class="meta"><span class="tag ${i.blocker_status==='resolved'?'':'attention'}">${e(i.blocker_status)}</span><span>${e(i.blocker_kind)}</span><a href="${jobHref(i.blocker_ticket_id,'issues')}">${e(i.ticket_prefix+i.ticket_number)}</a></div><h3>${e(i.blocker_title)}</h3><p class="prose issue-details">${e(i.blocker_details)}</p><p class="hint">Impact: ${e(i.blocker_impact)}</p><p class="hint">${e(i.owner_name||'Owner unavailable')} · Response due ${e(fmt(i.blocker_due_at))}</p>${i.blocker_attachment_id?`<p><a href="/agent/field/api.php?action=attachment&ticket_id=${n(i.blocker_ticket_id)}&attachment_id=${n(i.blocker_attachment_id)}" target="_blank" rel="noopener">View issue photo</a></p>`:''}${i.blocker_resolution||i.blocker_response?`<p class="prose issue-response">${e(i.blocker_resolution||i.blocker_response)}</p>`:''}${writable()&&i.blocker_status!=='resolved'&&(state.boot.user.admin||n(i.blocker_owner_id)===state.boot.user.id)?`<div class="actions">${i.blocker_status==='open'?actionButton('issue-response','Acknowledge',`data-id="${n(i.blocker_id)}" data-ticket="${n(i.blocker_ticket_id)}" data-status="acknowledged"`,true):''}${actionButton('issue-response','Resolve issue',`data-id="${n(i.blocker_id)}" data-ticket="${n(i.blocker_ticket_id)}" data-status="resolved"`)}</div>`:''}</div></article>`).join('')}</div>`:empty('No open issues','Report a blocker, access problem, parts request, or documentation gap from a job.');
}
function photos(j,task=0) {
  return `${writable()&&!j.terminal?`<form id="photo-form" class="form-grid inline-form"><h2>Photo evidence</h2><p class="hint">Photos upload while connected. They are not saved offline.</p><label>Photo<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></label><label>Caption<input name="caption" maxlength="200" required placeholder="What this photo shows"></label><label>Attach evidence to<select name="task_id">${taskOptions(j,task)}</select></label><button type="submit">Upload photo</button><p class="hint">JPG, PNG, or WebP · Up to 12 MB</p></form>`:''}<div class="section-head"><h2>Ticket attachments</h2></div><div class="list">${j.attachments.map(a=>`<div class="list-row"><a href="/agent/field/api.php?action=attachment&ticket_id=${j.ticket_id}&attachment_id=${n(a.ticket_attachment_id)}" target="_blank" rel="noopener">${e(a.ticket_attachment_name)}</a><span class="muted">${e(fmt(a.ticket_attachment_created_at))}</span></div>`).join('')||'<p class="hint">No attachments yet.</p>'}</div>`;
}
function projects() {
  return header('Your projects','Open a project for its full work plan, dependencies, and issues.')+(state.boot.projects.length?`<div class="list">${state.boot.projects.map(p=>`<div class="list-row"><div class="row-main"><a class="title" href="#project/${n(p.project_id)}">${e(p.project_name)}</a><p class="muted">${e(p.client_name)}</p><div class="meta"><span>${e(p.manager_name||'No project manager')}</span><span>${p.project_due?'Due '+e(p.project_due):'No due date'}</span></div></div><div><p class="hint">${n(p.completed_count)} of ${n(p.ticket_count)} jobs done</p><progress class="progress" max="${n(p.ticket_count)||1}" value="${n(p.completed_count)}" aria-label="Project jobs completed"></progress></div></div>`).join('')}</div>`:empty('No assigned projects','Projects appear here when you manage the project or have assigned work within it.'));
}
function projectView(p) {
  const mine=state.projectFilter==='mine';
  return `<a class="back" href="#projects">Back to projects</a>`+header(p.project_name,p.client_name+' · '+(p.manager_name||'No project manager'))+`<p class="prose">${e(p.project_description)}</p><div class="section-head"><h2>Work plan</h2><div class="segmented" aria-label="Project task filter"><button data-action="project-filter" data-filter="mine" aria-pressed="${mine}">My tasks</button><button data-action="project-filter" data-filter="all" aria-pressed="${!mine}">Entire project</button></div></div>`+p.jobs.map(j=>{
    const tasks=mine?j.tasks.filter(t=>n(t.task_assigned_to)===state.boot.user.id):j.tasks;
    if(mine&&!tasks.length&&j.assigned_to!==state.boot.user.id)return '';
    return `<section class="subsection"><div class="section-head"><h3><a href="${jobHref(j.ticket_id)}">${e(j.reference)} · ${e(j.subject)}</a></h3><span class="tag">${e(j.status)}</span></div>${taskRows(tasks,j.ticket_id)}</section>`;
  }).join('')+`<div class="section-head"><h2>Project issues</h2></div>`+issueRows(p.blockers);
}
function timeView() {
  const v=activeVisit();
  return header('Visit time','One active visit. Review recorded time before it reaches the ticket.')+(v?`<section class="current-visit"><div><span class="tag">${e(label(v.visit_status))}</span><h2>${e(v.client_name)}</h2><p>${e(v.ticket_prefix+v.ticket_number)} · Started ${e(fmt(v.visit_started_at))}</p></div><a class="button secondary" href="${jobHref(v.visit_ticket_id)}">Open job</a></section><section class="subsection"><h2>Current activity</h2><div class="actions activity-options">${['travel','onsite','waiting','break'].map(kind=>actionButton(kind==='onsite'?'arrival':'activity',label(kind),`data-kind="${kind}" data-ticket="${n(v.segments.find(s=>s.segment_active_visit_id!==null)?.segment_ticket_id||v.visit_ticket_id)}" ${v.visit_status===kind?'disabled':''}`,true)).join('')}</div><div class="actions">${actionButton('finish','Finish visit',`data-ticket="${n(v.visit_ticket_id)}"`)}${actionButton('sharing','Customer update',`data-ticket="${n(v.visit_ticket_id)}"`,true)}</div><p class="hint subsection">To split this visit across another ticket at the same site, open that ticket and mark onsite.</p></section>`:'')+`<div class="section-head"><h2>Review and history</h2></div>`+(state.boot.visits.length?`<div class="list">${state.boot.visits.map(visit=>{const pending=visit.segments.filter(s=>!s.segment_submitted_at);return `<div class="list-row"><div class="row-main"><h3>${e(visit.client_name)} · ${e(visit.ticket_prefix+visit.ticket_number)}</h3><p class="muted">${e(fmt(visit.visit_started_at))} · ${e(label(visit.visit_status))}</p><p class="hint">${visit.segments.length} activities${visit.visit_finished_at?(pending.length?' · Awaiting time review':' · Time submitted'):''}</p></div>${visit.visit_finished_at&&pending.length?actionButton('review-time','Review time',`data-visit="${n(visit.visit_id)}"`,true):''}</div>`;}).join('')}</div>`:empty('No visits recorded','Open your scheduled job and start travel or confirm arrival.'));
}
async function draftsView() {
  let accounts='';
  if(!state.vault&&knownAccounts().length) accounts=`<label>Technician account<select id="vault-account">${knownAccounts().map(id=>`<option value="${e(id)}">Technician ${e(id)}</option>`).join('')}</select></label>`;
  const head=header('Recovered notes','Drafts stay encrypted on this device. Reconnect and review before sending.')+accounts;
  if(!state.vault?.key)return head+`<div class="empty"><h2>${state.vault?.configured||accounts?'Unlock your notes':'Keep notes through connection loss'}</h2><p class="muted">Use a separate recovery passphrase. It cannot be reset without losing these local drafts. Client documents, photos, and signatures are not stored offline.</p><div class="actions">${actionButton('vault',state.vault?.configured||accounts?'Unlock note recovery':'Set up note recovery')}</div></div>`;
  const drafts=await state.vault.list();
  return head+(drafts.length?`<div class="list">${drafts.map(d=>`<div class="list-row"><div class="row-main"><h3>${e(d.unreadable?'Unreadable draft':d.reference+' · '+d.subject)}</h3><p class="muted">${d.unreadable?'This draft could not be decrypted. Its encrypted copy has been kept.':'Saved '+e(fmt(d.saved_at))}</p><div class="actions">${!d.unreadable?actionButton('open-draft','Open draft',`data-id="${e(d.id)}"`,true):''}${actionButton('delete-draft','Discard draft',`data-id="${e(d.id)}"`,true)}</div></div></div>`).join('')}</div>`:empty('No saved drafts','Notes you write with recovery unlocked are saved here automatically.'));
}
async function route() {
  await saveDraft();const version=++state.route;
  const hash=location.hash.slice(1)||'today';const [path,params]=hash.split('?');const [view,id,panel='overview']=path.split('/');
  document.querySelectorAll('[data-nav]').forEach(a=>a.setAttribute('aria-current',a.dataset.nav===view||(view==='project'&&a.dataset.nav==='projects')||(view==='job'&&a.dataset.nav==='today')?'page':'false'));
  if(!state.boot && view!=='drafts') {$('#work').innerHTML=header('Reconnect to your work','Your private job data is available after sign-in.')+`<div class="actions">${actionButton('refresh','Try connection')}<a class="button secondary" href="/agent/">Sign in through PSA</a><a class="button secondary" href="#drafts">Open encrypted drafts</a></div>`;return;}
  $('#work').setAttribute('aria-busy','true');
  try {
    let html;
    if(view==='job') {
      const job=await api('ticket',null,{ticket_id:id});if(version!==state.route)return;state.job=job;
      const task=n(new URLSearchParams(params).get('task'));
      const panelHtml=await ({overview:()=>overview(job),docs:()=>docs(job),tasks:()=>taskRows(job.tasks,job.ticket_id),notes:()=>notes(job,task),issues:()=>`<div class="section-head"><h2>Job issues</h2>${writable()&&!job.terminal?actionButton('new-issue','Report issue'):''}</div>`+issueRows(job.blockers),photos:()=>photos(job,task)}[panel]||(()=>overview(job)))();
      html=jobTop(job,panel)+panelHtml;
    } else if(view==='project') {state.project=await api('project',null,{project_id:id});html=projectView(state.project);}
    else if(view==='projects')html=projects();
    else if(view==='time')html=timeView();
    else if(view==='issues')html=header('Issues assigned to you','Acknowledge requests and record the response where the work happens.')+issueRows(state.boot.issues);
    else if(view==='drafts')html=await draftsView();
    else html=today();
    if(version!==state.route)return;
    $('#work').innerHTML=html;if(view==='job'&&panel==='notes'&&state.note?.pending){$('#note-form')?.querySelectorAll('input,textarea').forEach(input=>input.readOnly=true);$('#note-form')?.querySelectorAll('select').forEach(input=>input.disabled=true);}
    window.scrollTo({top:0,behavior:'instant'});updateElapsed();
  } catch(error) {if(version===state.route)$('#work').innerHTML=header('This view could not be loaded',error.message)+`<div class="actions">${actionButton('refresh','Retry')}<a href="#drafts" class="button secondary">Open encrypted drafts</a><a href="#today" class="button secondary">Today</a></div>`;}
  finally {$('#work').removeAttribute('aria-busy');}
}
function sheet(title,html) {stopCamera();$('#sheet-title').textContent=title;$('#sheet-body').innerHTML=html;$('#sheet').showModal();}
function closeSheet() {stopCamera();$('#sheet').close();$('#sheet-body').replaceChildren();}
function formError(form,error) {let node=$('.error-text',form);if(!node){node=document.createElement('p');node.className='error-text';node.setAttribute('role','alert');form.append(node);}node.textContent=error.message;}
function keyFor(form) {return form.dataset.requestKey ||= crypto.randomUUID();}
async function write(action,payload,form) {
  if(form?.dataset.busy)return;
  if(form) {form.dataset.busy='1';form.querySelectorAll('button[type=submit],button:not([type])').forEach(b=>b.disabled=true);}
  try {
    if(payload instanceof FormData)payload.set('request_key',keyFor(form));else payload.request_key ||= form?keyFor(form):crypto.randomUUID();
    if(form){payload=form._payload||payload;form._payload=payload;}
    const result=await api(action,payload);notice(result.message);return result;
  } catch(error) {
    if(form){
      if(error.status>=400&&error.status<500){delete form._payload;delete form.dataset.requestKey;if(form.id==='note-form'&&state.note){delete state.note.pending;state.note.request_key=crypto.randomUUID();await saveDraft();}}
      else {form.querySelectorAll('input:not([type=hidden]),textarea').forEach(input=>input.readOnly=true);form.querySelectorAll('select').forEach(input=>input.disabled=true);}
      formError(form,error);
    }else notice(error.message);throw error;}
  finally {if(form){delete form.dataset.busy;form.querySelectorAll('button[type=submit],button:not([type])').forEach(b=>b.disabled=false);}}
}
async function reload() {await boot();await route();startSharing();}
function snapshotNote() {
  const form=$('#note-form');if(!form||!state.note)return;
  const data=Object.fromEntries(new FormData(form));
  if(state.note.pending){state.notes.set(n(state.note.ticket_id),state.note);return;}
  const changed=Object.entries(data).some(([key,value])=>String(value)!==String(state.note[key]??''));
  Object.assign(state.note,data);
  state.dirty=['work_action','work_result','work_next_step','additional_details'].some(key=>String(state.note[key]||'').trim());
  if(state.dirty){
    if(changed){state.note.saved_at=Date.now();state.unsaved=true;}
    state.notes.set(n(state.note.ticket_id),state.note);
  }
}

async function saveDraft() {
  clearTimeout(state.saveTimer);snapshotNote();
  if(!state.note||!state.dirty||!state.vault?.key)return;
  const draft=structuredClone(state.note);const vault=state.vault;
  state.savePromise=(state.savePromise||Promise.resolve()).catch(()=>{}).then(()=>vault.save(draft.id,draft));
  try {await state.savePromise;if(state.notes.get(n(draft.ticket_id))?.saved_at===draft.saved_at){state.notes.get(n(draft.ticket_id)).persisted_at=draft.saved_at;state.unsaved=false;}const node=$('#note-save-state');if(node)node.textContent='Encrypted draft saved on this device.';}
  catch {const node=$('#note-save-state');if(node)node.textContent='Draft could not be saved on this device. Keep this tab open or save the note while connected.';}
}
function geo() {
  if(document.visibilityState!=='visible'||state.cover)return Promise.reject(new Error('Open Field Mode to check location.'));
  if(!navigator.geolocation)return Promise.reject(new Error('Location is unavailable on this device. Choose the job and check in manually.'));
  return new Promise((resolve,reject)=>navigator.geolocation.getCurrentPosition(p=>{
    const value={latitude:p.coords.latitude,longitude:p.coords.longitude,accuracy:p.coords.accuracy,observed_at:p.timestamp};state.position=value;resolve(value);
  },error=>reject(new Error(error.code===1?'Location permission is off. Choose your job and check in manually.':'A precise location could not be found. Try again outdoors, or check in manually.')),{enableHighAccuracy:true,maximumAge:20000,timeout:12000}));
}
async function locate(navigate=false) {
  state.matches=[];state.position=null;
  try {
    const pos=await geo();state.matches=(await api('match',pos)).candidates;
    state.locationMessage=pos.accuracy>100?'Location is too approximate for an automatic match. Choose your job manually.':state.matches.length?'Location checked just now. Confirm the job before marking onsite.':'No verified scheduled stop matches this location. Choose a job for manual check-in.';
    if(navigate && state.matches.length===1 && !activeVisit())location.hash=jobHref(state.matches[0].ticket_id);
    else if(['','#today'].includes(location.hash))await route();
    else {const node=$('#location-message');if(node)node.textContent=state.locationMessage;}
  } catch(error){state.matches=[];state.position=null;state.locationMessage=error.message;const node=$('#location-message');if(node)node.textContent=error.message;else if(!navigate)notice(error.message);}
}
function stopSharing(){if(state.watch!==null){navigator.geolocation?.clearWatch(state.watch);state.watch=null;}}
function startSharing() {
  stopSharing();const v=activeVisit();
  if(!v||!n(v.visit_share_location)||!writable()||document.visibilityState!=='visible'||state.cover)return;
  state.watch=navigator.geolocation?.watchPosition(async p=>{
    if(Date.now()-state.lastPositionSend<60000||document.visibilityState!=='visible'||state.cover)return;
    state.lastPositionSend=Date.now();
    const position={latitude:p.coords.latitude,longitude:p.coords.longitude,accuracy:p.coords.accuracy,observed_at:p.timestamp};state.position=position;
    try {await api('position',{ticket_id:n(v.visit_ticket_id),visit_id:n(v.visit_id),share_location:1,...position,request_key:crypto.randomUUID()});}
    catch { /* The portal continues to label the last successful update. */ }
  },()=>{}, {enableHighAccuracy:true,maximumAge:30000,timeout:15000}) ?? null;
}
function visitForm(kind,ticketId) {
  const v=activeVisit();
  sheet(kind==='onsite'?'Confirm arrival':'Start travel',`<form id="visit-form" class="form-grid"><input type="hidden" name="ticket_id" value="${n(ticketId)}"><input type="hidden" name="visit_id" value="${n(v?.visit_id)}"><input type="hidden" name="kind" value="${kind}"><input type="hidden" name="confirm_onsite" value="${kind==='onsite'?1:0}"><p>${kind==='onsite'?'Mark yourself onsite and begin recording time for this job?':'Start recording travel time for this job?'}</p><p class="hint">${e(state.job?.ticket_id===n(ticketId)?state.job.address:'You can change the activity or finish the visit from Time.')}</p><label class="check"><input type="checkbox" name="share_location" value="1" ${n(v?.visit_share_location)?'checked':''}>Share my location with this appointment’s customer while Field Mode is visible.</label><p class="hint">The portal shows when location was last updated. Sharing ends when the visit finishes.</p><button type="submit">${kind==='onsite'?'Confirm onsite':'Start travel'}</button></form>`);
}
async function newIssue(button) {
  const ticketId=n(button.dataset.ticket||state.job?.ticket_id);
  const j=state.job?.ticket_id===ticketId?state.job:await api('ticket',null,{ticket_id:ticketId});
  sheet('Report an issue',`<form id="issue-form" class="form-grid"><input type="hidden" name="ticket_id" value="${ticketId}"><input type="hidden" name="document_id" value="${n(button.dataset.document)}"><label>Type<select name="kind">${['blocker','issue','access','parts','scope','documentation'].map(kind=>`<option value="${kind}" ${button.dataset.kind===kind?'selected':''}>${e(kind[0].toUpperCase()+kind.slice(1))}</option>`).join('')}</select></label><label>Title<input name="title" required maxlength="200" placeholder="What is stopping or changing the work?"></label><label>What happened and what help is needed?<textarea name="details" required maxlength="10000"></textarea></label><label>Effect on the work<input name="impact" required maxlength="500" placeholder="For example, installation cannot continue"></label><label>Linked task<select name="task_id">${taskOptions(j,n(button.dataset.task))}</select></label><label>Response owner<select name="owner_id" required><option value="">Choose an owner</option>${j.owners.map(u=>`<option value="${n(u.user_id)}">${e(u.user_name)}</option>`).join('')}</select></label><label>Response needed by<input name="due_at" type="datetime-local" required></label><label>Photo <span class="muted">Optional · Connected upload only</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment"></label><label>Photo caption <span class="muted">Required if a photo is attached</span><input name="caption" maxlength="200"></label><p class="hint">The owner receives an in-app notification. A scope request records the need for a decision; it does not authorize extra work.</p><button type="submit">Submit issue</button></form>`);
}
function finishForm(ticketId) {
  const v=activeVisit();if(!v)return;
  sheet('Finish the visit',`<form id="finish-form" class="form-grid"><input type="hidden" name="ticket_id" value="${n(ticketId)}"><input type="hidden" name="visit_id" value="${n(v.visit_id)}"><input type="hidden" name="kind" value="finished"><label>Customer visit summary<textarea name="customer_summary" required maxlength="10000" placeholder="Work completed, any remaining work, and what happens next"></textarea></label><p class="hint">This summary is visible on the original appointment ticket in the customer portal. Include only details appropriate for that customer.</p>${v.visit_signature_attachment_id?`<p class="hint">Acknowledged by ${e(v.visit_acknowledged_name)}.</p>`:`<label>Acknowledged by <span class="muted">Optional</span><input name="acknowledged_name" maxlength="200" placeholder="Name of the person acknowledging the visit"></label><button type="button" class="secondary" data-action="signature">Capture optional signature</button>`}<p class="hint">The timer and location sharing stop. You will review the visit’s time next.</p><button type="submit">Finish and review time</button></form>`);
}
function reviewForm(visitId) {
  const v=state.boot.visits.find(v=>n(v.visit_id)===n(visitId));if(!v)return;
  const rows=v.segments.filter(s=>!s.segment_submitted_at);
  sheet('Review visit time',`<form id="time-form" class="form-grid"><input type="hidden" name="ticket_id" value="${n(v.visit_ticket_id)}"><input type="hidden" name="visit_id" value="${n(v.visit_id)}"><p class="hint">Recorded time becomes an internal time entry on each ticket. Breaks add no worked time.</p>${rows.map(s=>{const seconds=Math.max(0,Math.round((new Date(s.segment_ended_at)-new Date(s.segment_started_at))/1000));return `<div class="time-entry" data-segment="${n(s.segment_id)}"><div><h3>${e(label(s.segment_kind))}</h3><p class="hint">${e(s.ticket_prefix+s.ticket_number)}<br>${e(clock(s.segment_started_at))} – ${e(clock(s.segment_ended_at))}</p></div><label>Minutes<input data-minutes type="number" min="0" max="1440" step="any" required value="${s.segment_kind==='break'?0:(seconds/60).toFixed(3)}" ${s.segment_kind==='break'?'readonly':''}></label><label class="review-reason">Adjustment reason <span class="muted">Required if changed by over one minute</span><input data-reason maxlength="500"></label></div>`;}).join('')}<button type="submit">Submit reviewed time</button></form>`);
}
function stopCamera(){if(state.stream){state.stream.getTracks().forEach(t=>t.stop());state.stream=null;}}
async function scan() {
  if(!('BarcodeDetector' in window)){notice('Scanning is unavailable in this browser. Enter the asset name or serial number.');$('#asset-search input')?.focus();return;}
  sheet('Scan an asset label','<video id="scanner" autoplay muted playsinline></video><p class="hint">Aim at a serial-number barcode or QR label. You can also enter the value in asset search.</p>');
  try {
    const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});if(!$('#sheet').open){stream.getTracks().forEach(t=>t.stop());return;}
    state.stream=stream;const video=$('#scanner');video.srcObject=stream;await video.play();const detector=new BarcodeDetector();
    const tick=async()=>{if(!state.stream||!$('#sheet').open)return;try {const codes=await detector.detect(video);if(codes.length){const text=codes[0].rawValue.slice(0,200);closeSheet();$('#asset-search input').value=text;$('#asset-search').requestSubmit();return;}}catch{}setTimeout(tick,350);};tick();
  } catch {closeSheet();notice('Camera access was unavailable. Enter the asset name or serial number.');}
}
function signatureForm() {
  // Preserve the checkout text in memory while the focused signature form is open.
  state.checkout=Object.fromEntries(new FormData($('#finish-form')));
  const v=activeVisit();
  sheet('Visit acknowledgment',`<form id="signature-form" class="form-grid"><label>Name<input name="acknowledged_name" required maxlength="200" value="${e(state.checkout.acknowledged_name)}"></label><p class="hint">I acknowledge the technician’s visit. This does not approve additional scope or waive outstanding issues.</p><canvas id="signature" class="signature" width="900" height="360" aria-label="Signature drawing area"></canvas><button type="button" class="secondary" data-action="clear-signature">Clear signature</button><label class="check"><input type="checkbox" name="confirm_acknowledgment" value="1" required>I agree to record this visit acknowledgment.</label><p class="hint">You can close this form and use a typed name instead. Signatures upload immediately and are not saved offline.</p><button type="submit">Save acknowledgment</button></form>`);
  const canvas=$('#signature');const ctx=canvas.getContext('2d');ctx.strokeStyle='#0a2423';ctx.lineWidth=3;ctx.lineCap='round';ctx.lineJoin='round';state.signatureDrawn=false;
  let drawing=false;
  const point=ev=>{const rect=canvas.getBoundingClientRect();return [(ev.clientX-rect.left)*canvas.width/rect.width,(ev.clientY-rect.top)*canvas.height/rect.height];};
  canvas.addEventListener('pointerdown',ev=>{drawing=true;canvas.setPointerCapture(ev.pointerId);ctx.beginPath();ctx.moveTo(...point(ev));});
  canvas.addEventListener('pointermove',ev=>{if(drawing){ctx.lineTo(...point(ev));ctx.stroke();state.signatureDrawn=true;}});
  canvas.addEventListener('pointerup',()=>drawing=false);canvas.addEventListener('pointercancel',()=>drawing=false);
}
function updateElapsed(){document.querySelectorAll('[data-elapsed]').forEach(el=>{const seconds=Math.max(0,Math.floor((Date.now()-new Date(el.dataset.elapsed))/1000));el.textContent=`${Math.floor(seconds/3600)}h ${Math.floor(seconds%3600/60)}m in this activity`;});}
async function cover(){if(state.cover)return;await saveDraft();state.cover=true;stopSharing();stopCamera();state.vault?.lock();state.coveredSheet=$('#sheet').open;if(state.coveredSheet){$('#sheet').querySelectorAll('input[type=password]').forEach(input=>input.value='');$('#sheet').close();}document.body.classList.add('cover-active');$('#work').inert=true;$('.main-nav').inert=true;$('#privacy').hidden=false;$('#privacy button').focus();}
function restoreCheckout(){if(!state.checkout)return;finishForm(activeVisit()?.visit_ticket_id);for(const [key,value] of Object.entries(state.checkout)){const input=$(`[name="${key}"]`,$('#finish-form'));if(input)input.value=value;}state.checkout=null;}
document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-action]');if(!button)return;
  const action=button.dataset.action;
  try {
    if(action==='close-sheet'){const checkout=!!state.checkout;closeSheet();if(checkout)restoreCheckout();}
    else if(action==='refresh')await reload();
    else if(action==='locate')await locate(true);
    else if(action==='arrival'||action==='travel')visitForm(action==='arrival'?'onsite':'travel',button.dataset.ticket);
    else if(action==='activity') {const v=activeVisit();await write('visit',{ticket_id:n(button.dataset.ticket),visit_id:n(v.visit_id),kind:button.dataset.kind,share_location:n(v.visit_share_location)});await reload();}
    else if(action==='finish')finishForm(button.dataset.ticket);
    else if(action==='review-time')reviewForm(button.dataset.visit);
    else if(action==='new-issue')await newIssue(button);
    else if(action==='issue-response')sheet(button.dataset.status==='resolved'?'Resolve this issue':'Acknowledge this issue',`<form id="response-form" class="form-grid"><input type="hidden" name="ticket_id" value="${n(button.dataset.ticket)}"><input type="hidden" name="blocker_id" value="${n(button.dataset.id)}"><input type="hidden" name="status" value="${e(button.dataset.status)}"><label>${button.dataset.status==='resolved'?'Resolution and outcome':'Response and next action'}<textarea name="note" required maxlength="10000"></textarea></label><button type="submit">Save response</button></form>`);
    else if(action==='complete-task'){button.disabled=true;try{await write('task',{ticket_id:n(button.dataset.ticket),task_id:n(button.dataset.task)});await reload();}finally{button.disabled=false;}}
    else if(action==='project-filter'){state.projectFilter=button.dataset.filter;$('#work').innerHTML=projectView(state.project);}
    else if(action==='document'){
      const doc=await api('document',null,{ticket_id:state.job.ticket_id,document_id:button.dataset.id});
      $('#doc-content').innerHTML=`<article class="doc-view"><h2>${e(doc.name)}</h2><p class="prose">${e(doc.content||'This document has no readable text. Open the full document for diagrams or embedded content.')}</p><div class="actions"><a class="button secondary" href="/agent/document.php?client_id=${state.job.client_id}&document_id=${n(doc.id)}" target="_blank" rel="noopener">Open full document</a>${writable()&&!state.job.terminal?actionButton('new-issue','Report incorrect docs',`data-kind="documentation" data-document="${n(doc.id)}"`,true):''}</div></article>`;$('#doc-content').scrollIntoView({block:'start'});
    }
    else if(action==='pin'){
      const pos=await geo();if(pos.accuracy>100)throw new Error('Get a location accurate to 100 metres or better before verifying this site.');
      sheet('Verify the site address',`<form id="pin-form" class="form-grid"><p><strong>${e(state.job.location_name)}</strong></p><p>${e(state.job.address)}</p><p class="hint">The current reading is accurate to approximately ${Math.round(pos.accuracy)} metres. Verify only while physically at this address.</p><label>Arrival radius in metres<input name="radius" type="number" min="50" max="500" value="150" required></label><label class="check"><input type="checkbox" name="confirm_site" value="1" required>I am at this address and the site is correct.</label><button type="submit">Save arrival pin</button></form>`);
      state.pinPosition=pos;
    }
    else if(action==='sharing'){
      const v=activeVisit();sheet('Customer appointment update',`<form id="sharing-form" class="form-grid"><label class="check"><input type="checkbox" name="share_location" value="1" ${n(v.visit_share_location)?'checked':''}>Share my current location while Field Mode is visible.</label><label>Estimated arrival <span class="muted">Optional</span><input name="eta_at" type="datetime-local"></label><p class="hint">Customers see visit status, estimated arrival, and the last successful location update. Location is removed when sharing is turned off or the visit ends.</p><button type="submit">Save customer update</button></form>`);
    }
    else if(action==='scan')await scan();
    else if(action==='signature')signatureForm();
    else if(action==='clear-signature'){const canvas=$('#signature');canvas.getContext('2d').clearRect(0,0,canvas.width,canvas.height);state.signatureDrawn=false;}
    else if(action==='vault'){
      if(!state.vault){const id=$('#vault-account')?.value;if(id)state.vault=new DraftVault(id);}
      if(!state.vault)throw new Error('Sign in once to set up encrypted note recovery for your account.');
      const configured=state.vault.configured;if(!configured&&!state.boot)throw new Error('Connect and sign in before setting up note recovery.');
      sheet(configured?'Unlock note recovery':'Set up note recovery',`<form id="vault-form" class="form-grid"><p class="hint">${configured?'Enter your separate note-recovery passphrase.':'Choose a separate passphrase of at least 10 characters. Forgotten passphrases cannot recover these device drafts.'}</p><label>Recovery passphrase<input name="passphrase" type="password" autocomplete="${configured?'current-password':'new-password'}" required minlength="${configured?1:10}"></label>${!configured?'<label>Confirm passphrase<input name="confirmation" type="password" autocomplete="new-password" required minlength="10"></label>':''}<button type="submit">${configured?'Unlock notes':'Enable recovery'}</button></form>`);
    }
    else if(action==='open-draft'){
      const d=(await state.vault.list()).find(d=>d.id===button.dataset.id);if(!d||d.unreadable)return;
      state.note=d;state.note.persisted_at=d.saved_at;state.notes.set(n(d.ticket_id),state.note);state.noteTicket=n(d.ticket_id);state.dirty=true;
      if(state.boot&&state.online)location.hash=jobHref(d.ticket_id,'notes');
      else {$('#work').innerHTML=`<a class="back" href="#drafts">Back to drafts</a>`+header(d.reference+' · '+d.subject,'Offline note draft · Reconnect to review and send.')+await notes({ticket_id:n(d.ticket_id),reference:d.reference,subject:d.subject,terminal:false,tasks:[],notes:[]},0,true);}
    }
    else if(action==='delete-draft')sheet('Discard this local draft?',`<form id="discard-form" class="form-grid"><input type="hidden" name="draft_id" value="${e(button.dataset.id)}"><p>This removes the encrypted draft from this device. It cannot be recovered.</p><button class="danger" type="submit">Discard draft</button></form>`);
    else if(action==='lock')await cover();
    else if(action==='uncover'){
      try{await boot();}catch{state.boot=null;state.job=null;state.project=null;connection(false);}
      state.cover=false;state.lastActivity=Date.now();document.body.classList.remove('cover-active');$('#work').inert=false;$('.main-nav').inert=false;$('#privacy').hidden=true;
      await route();startSharing();
      if(state.boot){await locate(false);await route();if(state.coveredSheet){$('#sheet').showModal();state.coveredSheet=false;notice(state.locationMessage);}else if(!activeVisit()&&state.matches.length===1){location.hash=jobHref(state.matches[0].ticket_id);}else if(state.matches.length&&!['','#today'].includes(location.hash)){$('#work').insertAdjacentHTML('afterbegin',matchesMarkup());}else notice(state.locationMessage);}
    }
  } catch(error){notice(error.message);}
});
document.addEventListener('submit',async event=>{
  const form=event.target;if(!form.id)return;event.preventDefault();
  try {
    if(form.id==='note-form'){
      snapshotNote();await saveDraft();
      if(!state.boot||!state.online)throw new Error('Draft kept on this device if recovery is unlocked. Reconnect and sign in before sending.');
      const note=state.note;
      note.pending ||= {ticket_id:note.ticket_id,request_key:note.request_key,task_id:n(note.task_id),work_action:note.work_action,work_result:note.work_result,work_next_step:note.work_next_step,additional_details:note.additional_details,work_waiting_on:'none'};
      await saveDraft();
      const result=await write('note',note.pending,form);if(!result)return;
      state.vault?.remove(note.id);state.notes.delete(n(note.ticket_id));state.note=null;state.dirty=false;await reload();
    } else if(form.id==='doc-search') {$('#doc-list').innerHTML=docRows(await api('documents',null,{ticket_id:state.job.ticket_id,q:new FormData(form).get('q')}));}
    else if(form.id==='asset-search') {$('#assets').innerHTML=assetRows(await api('assets',null,{ticket_id:state.job.ticket_id,q:new FormData(form).get('q')}));}
    else if(form.id==='vault-form'){
      const data=new FormData(form);if(!state.vault.configured&&data.get('passphrase')!==data.get('confirmation'))throw new Error('The passphrases do not match.');
      const creating=!state.vault.configured;await state.vault.unlock(data.get('passphrase'),creating);form.reset();closeSheet();await saveDraft();notice('Encrypted note recovery unlocked.');await route();
    } else if(form.id==='discard-form'){
      const id=new FormData(form).get('draft_id');state.vault.remove(id);for(const [ticket,note] of state.notes){if(note.id===id)state.notes.delete(ticket);}if(state.note?.id===id){state.note=null;state.dirty=false;}closeSheet();await route();
    } else if(form.id==='signature-form'){
      if(!state.signatureDrawn)throw new Error('Add a signature, or close this form and use a typed acknowledgment.');
      const v=activeVisit();const data=new FormData(form);const blob=await new Promise(resolve=>$('#signature').toBlob(resolve,'image/png'));
      if(!blob)throw new Error('The signature could not be prepared. Try again.');
      data.set('photo',blob,'visit-acknowledgment.png');data.set('signature','1');data.set('ticket_id',v.visit_ticket_id);data.set('visit_id',v.visit_id);
      const result=await write('photo',data,form);if(!result)return;await boot();closeSheet();restoreCheckout();
    } else {
      let action;let payload=Object.fromEntries(new FormData(form));
      if(form.id==='visit-form') {action='visit';try{Object.assign(payload,await geo());}catch{}payload.share_location=n(payload.share_location);}
      else if(form.id==='finish-form')action='visit';
      else if(form.id==='time-form') {action='time';payload.reviews={};form.querySelectorAll('[data-segment]').forEach(row=>payload.reviews[row.dataset.segment]={seconds:Math.round(n($('[data-minutes]',row).value)*60),reason:$('[data-reason]',row).value});}
      else if(form.id==='issue-form'){action='issue';payload=new FormData(form);if(!payload.get('photo')?.size)payload.delete('photo');payload.set('due_at',new Date(payload.get('due_at')).toISOString());}
      else if(form.id==='response-form')action='issue_update';
      else if(form.id==='photo-form'){action='photo';payload=new FormData(form);payload.set('ticket_id',state.job.ticket_id);}
      else if(form.id==='pin-form'){action='pin';payload={...payload,...state.pinPosition,ticket_id:state.job.ticket_id,address_hash:state.job.address_hash};}
      else if(form.id==='sharing-form'){
        action='position';const v=activeVisit();payload={...payload,ticket_id:n(v.visit_ticket_id),visit_id:n(v.visit_id),share_location:n(payload.share_location)};
        if(payload.eta_at)payload.eta_at=new Date(payload.eta_at).toISOString();
        if(payload.share_location)Object.assign(payload,await geo());
      }
      if(!action)return;
      const result=await write(action,payload,form);if(!result)return;
      const finish=form.id==='finish-form';closeSheet();await reload();if(finish)reviewForm(result.visit_id);
    }
  } catch(error){formError(form,error);}
});
document.addEventListener('input',event=>{
  if(event.target.closest('#note-form')){
    if(state.note?.pending){notice('A note submission is awaiting confirmation. Retry saving it before starting another note.');return;}
    snapshotNote();const node=$('#note-save-state');if(node)node.textContent=state.vault?.key?'Saving encrypted draft…':'Draft stays in this tab. Enable recovery to survive a reload.';
    clearTimeout(state.saveTimer);state.saveTimer=setTimeout(saveDraft,500);
    if(event.target.name==='template'){const hints=templates[event.target.value];['work_action','work_result','work_next_step'].forEach((name,i)=>{$(`[name="${name}"]`,$('#note-form')).placeholder=hints[i];});}
  }
});
for(const event of ['pointerdown','keydown','input','scroll'])document.addEventListener(event,()=>state.lastActivity=Date.now(),{passive:true});
window.addEventListener('hashchange',()=>route());
window.addEventListener('pageshow',event=>{if(event.persisted)cover();});
window.addEventListener('offline',()=>{connection(false);saveDraft();});
window.addEventListener('online',()=>notice('Connection is back. Refresh your work or review a saved draft before sending.'));
window.addEventListener('beforeunload',event=>{if([...state.notes.values()].some(note=>(note.saved_at||0)>(note.persisted_at||0))){event.preventDefault();event.returnValue='';}});
document.addEventListener('visibilitychange',()=>{if(document.hidden){stopSharing();stopCamera();saveDraft();}else{if(Date.now()-state.lastActivity>=60000)cover();else {startSharing();if(state.boot)locate(false);}}});
$('#sheet').addEventListener('cancel',event=>{event.preventDefault();closeSheet();if(state.checkout)restoreCheckout();});
setInterval(()=>{updateElapsed();if(!state.cover&&Date.now()-state.lastActivity>=60000)cover();},1000);
window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;$('#install').hidden=false;});
$('#install').addEventListener('click',async()=>{if(installPrompt){await installPrompt.prompt();installPrompt=null;$('#install').hidden=true;}});
window.addEventListener('appinstalled',()=>$('#install').hidden=true);
if('serviceWorker' in navigator)navigator.serviceWorker.register('/agent/field/sw.js',{scope:'/agent/field/'}).catch(()=>{});
(async()=>{
  const params=new URLSearchParams(location.search);const explicit=params.has('ticket_id')||params.has('project_id')||!!location.hash;
  if(!location.hash&&params.get('ticket_id'))history.replaceState(null,'',jobHref(params.get('ticket_id'),params.get('panel')||'overview'));
  else if(!location.hash&&params.get('project_id'))history.replaceState(null,'','#project/'+n(params.get('project_id')));
  try{await boot();await route();startSharing();await locate(!explicit);}catch(error){connection(false);await route();notice(error.message);}
})();
