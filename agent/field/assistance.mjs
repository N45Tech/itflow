// Client-scoped follow-ups and reviewed knowledge use the existing online-only receipt flow.
export function createAssistance(ctx) {
  const {$,e,n,state,api,write,reload,sheet,closeSheet,header,empty,jobHref,fmt}=ctx;
  let filters={scope:'mine',due:'due',kind:''}, queue, knowledgeQueue, knowledgeState='review';
  const local=v=>{const d=new Date(v);return Number.isNaN(d.getTime())?'':new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,16);};
  const button=(action,label,attrs='')=>`<button type="button" class="button secondary" data-action="assist-${action}" ${attrs}>${e(label)}</button>`;
  const history=rows=>rows?.length?`<details class="subsection"><summary>Activity history</summary>${rows.map(r=>`<p><strong>${e(r.event_action)}</strong> · ${e(r.user_name||'System')}<br>${e(r.event_note)}<br><small>${e(fmt(r.event_created_at+'Z'))}</small></p>`).join('')}</details>`:'';
  const choices=(rows,selected,zero='')=>(zero?`<option value="0">${e(zero)}</option>`:!rows.some(r=>n(r.user_id)===n(selected))?'<option value="">Choose an owner</option>':'')+rows.map(r=>`<option value="${n(r.user_id)}" ${n(r.user_id)===n(selected)?'selected':''}>${e(r.user_name)}</option>`).join('');
  const queueRows=items=>items.map(f=>`<article class="list-row"><div class="row-main"><div class="meta"><span class="tag ${f.overdue?'attention':''}">${e(f.escalated?'Escalation due':f.overdue?'Follow-up due':'Planned')}</span><span>${e(f.kind_label)}</span></div><h2>${e(f.summary)}</h2><p class="muted">${e(f.client_name)} · ${e(f.reference)}</p><p>${e(f.owner_name)} · ${e(fmt(f.due_at))}</p>${f.planned?`<p class="prose">${e(f.note)}</p><p class="hint">Original due: ${e(fmt(f.source_due_at))}</p>`:''}<p class="hint">Escalation: ${e(f.escalate_name)}</p><div class="actions">${button('plan','Review follow-up',`data-ticket="${n(f.ticket_id)}" data-key="${e(f.key)}"`)}<a class="button secondary" href="${jobHref(f.ticket_id,['ticket_approval','task_approval'].includes(f.kind)?'approvals':f.kind==='blocker'?'issues':'overview')}">Open source job</a></div></div></article>`).join('');
  const knowledgeRows=items=>items.map(k=>`<article class="list-row"><div><a class="title" href="#knowledge/${n(k.knowledge_id)}">${e(k.knowledge_title)}</a><p class="muted">${e(k.client_name)} · ${e(k.user_name||'Former technician')}</p></div></article>`).join('');
  async function route(view,id) {
    if(view==='followups') {
      queue=await api('followups',null,filters);
      return header('Follow-ups','Promises, approvals and blocked work that need a next step.')+`<details class="subsection"><summary>Filter follow-ups</summary><form id="assist-filter" class="form-grid inline-form"><div class="form-columns"><label>Assignment<select name="scope"><option value="mine">Mine and escalated to me</option><option value="all" ${filters.scope==='all'?'selected':''}>All accessible work</option></select></label><label>When<select name="due"><option value="due">Due now</option><option value="all" ${filters.due==='all'?'selected':''}>All upcoming follow-ups</option></select></label></div><label>Type<select name="kind">${Object.entries({'':'All types',next_action:'Next action',promise:'Customer commitment',ticket_approval:'Ticket approval',task_approval:'Task approval',blocker:'Field issue'}).map(([k,v])=>`<option value="${k}" ${filters.kind===k?'selected':''}>${v}</option>`).join('')}</select></label><label>Client name<input name="client_query" maxlength="200" value="${e(filters.client_query||'')}" placeholder="Any accessible client"></label><button>Apply filters</button></form></details><p class="hint">${filters.scope==='all'?'All accessible work':'Mine and escalated to me'} · ${filters.due==='all'?'Upcoming and due':'Due now'}${filters.client_query?' · '+e(filters.client_query):''}</p>${queue.limited?'<p class="hint">This view reached 5,000 source items. Narrow the type or client filter.</p>':''}<p class="hint">${n(queue.total)} follow-ups match. Pending approvals become due after 24 hours.</p><div class="list" id="assist-queue">${queueRows(queue.items)||empty('No follow-ups in this view','Choose all upcoming follow-ups or all accessible work to see other commitments.')}</div>${queue.next!==null?button('more','Load more follow-ups'):''}`;
    }
    if(view==='knowledge') {
      if(id)return article(await api('knowledge',null,{knowledge_id:id}));
      const q=knowledgeQueue=await api('knowledge_queue',null,{state:knowledgeState});
      return header('Resolution knowledge','Review useful fixes before they become internal client documents.')+`<form id="assist-knowledge-filter" class="search"><select name="state" aria-label="Article state">${Object.entries({review:'Awaiting review',draft:'Drafts',published:'Published'}).map(([k,v])=>`<option value="${k}" ${k===knowledgeState?'selected':''}>${v}</option>`).join('')}</select><button>Apply</button></form><div class="list" id="assist-knowledge-queue">${knowledgeRows(q.items)||empty('No articles in this view','Capture a successful resolution from Suggested fixes on a resolved job.')}</div>${q.next!==null?button('knowledge-more','Load more articles'):''}`;
    }
    return null;
  }
  async function fixes(job) {
    const s=await api('suggestions',null,{ticket_id:job.ticket_id});
    return `<div class="section-head"><h2>Suggested fixes</h2>${s.can_capture?button('capture','Capture this resolution',`data-ticket="${n(job.ticket_id)}"`):''}</div><p class="hint">Relevant records from this client. Check applicability before using a previous fix.</p><div class="list">${s.items.map(r=>`<article class="list-row"><div class="row-main"><span class="tag">${e(r.label)}</span><h3>${e(r.title)}</h3><p class="hint">${e(r.reasons.join(' · '))}</p><p class="prose">${e(r.excerpt)}</p><p class="hint">Updated ${e(fmt(r.updated_at))}</p>${r.kind==='ticket'?`<a class="button secondary" href="${jobHref(r.id)}">Open source job</a>`:`<button type="button" class="button secondary" data-action="assist-doc" data-ticket="${n(job.ticket_id)}" data-id="${n(r.id)}">Read source document</button>`}</div></article>`).join('')||empty('No relevant fixes found','As your team records resolutions and documentation, relevant records will appear here.')}</div>${s.limited?'<p class="hint">Suggestions use the 200 most recent matching records per source.</p>':''}<p><a href="#knowledge">Open knowledge review</a></p>`;
  }
  function article(k) {
    const canWrite=state.boot.user.write&&state.boot.user.client_write;
    const canReview=canWrite&&state.boot.user.admin&&![n(k.knowledge_created_by),n(k.knowledge_edited_by)].includes(n(state.boot.user.id));
    const base=`<input type="hidden" name="ticket_id" value="${n(k.knowledge_ticket_id)}"><input type="hidden" name="knowledge_id" value="${n(k.knowledge_id)}"><input type="hidden" name="expected_revision" value="${n(k.knowledge_revision)}"><input type="hidden" name="expected_document_hash" value="${e(k.document_hash)}">`;
    let content=`<a class="back" href="#knowledge">Back to knowledge review</a>`+header(k.knowledge_title,`${k.client_name} · ${k.knowledge_state} · Revision ${k.knowledge_revision}`)+`<p><a href="${jobHref(k.knowledge_ticket_id)}">Read source job: ${e(k.ticket_prefix+k.ticket_number)}</a></p>${!k.source_current?'<p class="hint">The source resolution has changed. Recheck it before publication.</p>':''}`;
    if(k.document_available)content+=button('doc','Read current client document',`data-ticket="${n(k.knowledge_ticket_id)}" data-id="${n(k.knowledge_document_id)}"`);
    if(!k.document_current)content+='<p class="hint">The client document changed or is unavailable. It is excluded from reviewed suggestions until a new revision is published.</p>';
    if(k.knowledge_state==='draft'&&canWrite)content+=`<form id="assist-knowledge-save" class="form-grid inline-form">${base}<label>Article title<input name="title" required minlength="5" maxlength="200" value="${e(k.knowledge_title)}"></label>${[['problem','Problem and applicability',10000],['solution','Resolution steps',10000],['cautions','Checks and cautions',5000]].map(([f,label,max])=>`<label>${label}<textarea aria-label="${e(label)}" name="${f}" maxlength="${max}" ${f==='cautions'?'':'required'}>${e(k['knowledge_'+f])}</textarea></label>`).join('')}${!k.source_current?'<label class="check"><input type="checkbox" name="confirm_source" value="1" required> I checked the changed source and updated this draft.</label>':''}${!k.document_current?'<label class="check"><input type="checkbox" name="confirm_document" value="1" required> I read the current client document and included its relevant changes in this draft.</label>':''}<p class="hint">Remove secrets and state how to validate the fix. Publication requires a different authorized reviewer.</p><div class="actions"><button name="operation" value="save" class="secondary">Save draft</button><button name="operation" value="submit">Request review</button></div></form>`;
    else {
      content+=['problem','solution','cautions'].map((f,i)=>`<section class="subsection"><h2>${['Problem and applicability','Resolution steps','Checks and cautions'][i]}</h2><p class="prose">${e(k['knowledge_'+f])}</p></section>`).join('');
      if(k.knowledge_state==='review'&&canReview)content+=`<form id="assist-knowledge-save" class="form-grid inline-form">${base}<label>Review reason<textarea name="note" required minlength="5" maxlength="1000"></textarea></label><div class="actions"><button name="operation" value="publish" ${!k.source_current?'disabled':''}>Publish internal article</button><button name="operation" value="return" class="secondary">Return for changes</button></div></form>`;
      else if(k.knowledge_state==='review')content+='<p class="hint">Awaiting review by a different authorized technician.</p>';
    }
    if(k.knowledge_state==='published'&&canWrite&&k.document_available)content+=`<form id="assist-knowledge-save" class="form-grid inline-form">${base}<label>Revision reason<textarea name="note" required minlength="5" maxlength="1000"></textarea></label><p class="hint">Start from the last reviewed article. A new revision requires independent review and preserves the previous document version.</p><button name="operation" value="revise">Start a revision</button></form>`;
    if(k.knowledge_state!=='published'&&!k.reviewer_count)content+='<p class="hint">No independent reviewer currently has Full Support and documentation write access for this client. An administrator must assign that access before publication.</p>';
    return content+history(k.history);
  }
  async function click(action,b) {
    if(!action.startsWith('assist-'))return false;
    if(action==='assist-doc') {
      const doc=await api('document',null,{ticket_id:b.dataset.ticket,document_id:b.dataset.id});
      sheet(doc.name,`<p class="prose">${e(doc.content||'No readable text. View the full document for diagrams or embedded content.')}</p>${button('doc-full','View full document',`data-ticket="${n(b.dataset.ticket)}" data-id="${n(doc.id)}"`)}`);
    } else if(action==='assist-doc-full') {
      closeSheet();sheet('Full document',`<iframe class="file-preview" title="Client document" sandbox="allow-same-origin" src="/agent/field/api.php?action=document_view&ticket_id=${n(b.dataset.ticket)}&document_id=${n(b.dataset.id)}&theme=${document.documentElement.dataset.theme==='dark'?'dark':'light'}"></iframe>`);
    } else if(action==='assist-plan') {
      const f=await api('followup',null,{ticket_id:b.dataset.ticket,key:b.dataset.key});
      const due=new Date(Math.max(Date.now()+3600000,new Date(f.due_at).getTime()));
      const escalation=new Date(Math.max(due.getTime()+86400000,new Date(f.escalate_at).getTime()));
      sheet('Plan this follow-up',`<p><strong>${e(f.summary)}</strong></p><p class="hint">${e(f.reference)} · Original due ${e(fmt(f.source_due_at))}</p>${state.boot.user.write?`<form id="assist-plan-save" class="form-grid"><input type="hidden" name="ticket_id" value="${n(f.ticket_id)}"><input type="hidden" name="key" value="${e(f.key)}"><input type="hidden" name="expected_version" value="${e(f.version)}"><label>Follow-up owner<select aria-label="Follow-up owner" name="owner_id" required>${choices(f.owners,f.owner_id)}</select></label><label>Next follow-up<input type="datetime-local" name="due_at" value="${local(due)}" required></label><label>Escalate to<select aria-label="Escalate to" name="escalate_to">${choices(f.owners,f.escalate_to,'Owner only · no other recipient')}</select></label><label data-assist-escalation ${f.escalate_to?'':'hidden'}>Escalate after<input type="datetime-local" name="escalate_at" value="${local(escalation)}" required></label><label>Next step and reason<textarea name="note" minlength="5" maxlength="1000" required>${e(f.note)}</textarea></label><p class="hint">In-app reminders only. The original commitment and approval deadlines stay unchanged.</p><button>Save follow-up plan</button></form>`:''}${history(f.history)}`);
    } else if(action==='assist-capture') {
      b.disabled=true;try{const result=await write('knowledge_capture',{ticket_id:n(b.dataset.ticket)});location.hash='#knowledge/'+result.knowledge_id;}finally{b.disabled=false;}
    } else if(action==='assist-knowledge-more') {
      b.disabled=true;try{knowledgeQueue=await api('knowledge_queue',null,{state:knowledgeState,offset:knowledgeQueue.next});$('#assist-knowledge-queue').insertAdjacentHTML('beforeend',knowledgeRows(knowledgeQueue.items));if(knowledgeQueue.next===null)b.remove();}finally{b.disabled=false;}
    } else if(action==='assist-more') {
      b.disabled=true;try{queue=await api('followups',null,{...filters,offset:queue.next});$('#assist-queue').insertAdjacentHTML('beforeend',queueRows(queue.items));if(queue.next===null)b.remove();}finally{b.disabled=false;}
    }
    return true;
  }
  async function submit(form,submitter) {
    if(!form.id.startsWith('assist-'))return false;
    const data=Object.fromEntries(new FormData(form));
    if(form.id==='assist-filter'){filters=data;await reload();}
    else if(form.id==='assist-knowledge-filter'){knowledgeState=data.state;await reload();}
    else {
      data.ticket_id=n(data.ticket_id);
      if(form.id==='assist-plan-save')for(const key of ['due_at','escalate_at'])data[key]=new Date(data[key]).toISOString();
      if(form.id==='assist-knowledge-save')data.operation=submitter?.value||'save';
      const result=await write(form.id==='assist-plan-save'?'followup_plan':'knowledge_save',data,form);
      if(result){if(form.id==='assist-plan-save')closeSheet();await reload();}
    }
    return true;
  }
  document.addEventListener('change',event=>{
    if(event.target.matches('#assist-plan-save [name="escalate_to"]'))$('#assist-plan-save [data-assist-escalation]').hidden=!n(event.target.value);
  });
  return {route,fixes,click,submit};
}
