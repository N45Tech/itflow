// Follow-up plans stay with their source ticket. Finding due work uses the ticket filter.
export function createAssistance(ctx) {
  const {$, e, n, state, api, write, reload, sheet, closeSheet, fmt} = ctx;
  const local = value => { const date = new Date(value); return new Date(date - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
  const history = rows => rows?.length ? `<details class="subsection"><summary>Activity history</summary>${rows.map(r => `<p><strong>${e(r.event_action)}</strong> · ${e(r.user_name || 'System')}<br>${e(r.event_note)}<br><small>${e(fmt(r.event_created_at + 'Z'))}</small></p>`).join('')}</details>` : '';
  const rows = items => items.map(f => `<div class="list-row"><div class="row-main"><h3>${e(f.summary)}</h3><p class="hint">${e(f.owner_name)} · ${e(fmt(f.due_at))}${f.overdue ? ' · Due now' : ''}</p>${f.note ? `<p class="prose">${e(f.note)}</p>` : ''}<button type="button" class="secondary" data-action="assist-plan" data-ticket="${n(f.ticket_id)}" data-key="${e(f.key)}">Review follow-up</button></div></div>`).join('');
  function ticket(job) {
    const queue = job.followups;
    if (!queue?.items?.length) return '';
    return `<details class="subsection" ${location.hash.endsWith('/followups') ? 'open' : ''}><summary>Follow-ups (${n(queue.total)})</summary><div id="ticket-followups">${rows(queue.items)}</div>${queue.next !== null ? `<button type="button" class="secondary" data-action="assist-more" data-ticket="${n(job.ticket_id)}" data-offset="${n(queue.next)}">More follow-ups</button>` : ''}</details>`;
  }
  async function click(action, button) {
    if (!action.startsWith('assist-')) return false;
    if (action === 'assist-more') {
      const queue = await api('followups', null, {ticket_id: button.dataset.ticket, scope: 'all', due: 'all', offset: button.dataset.offset});
      $('#ticket-followups').insertAdjacentHTML('beforeend', rows(queue.items));
      if (queue.next === null) button.remove(); else button.dataset.offset = String(queue.next);
    } else if (action === 'assist-plan') {
      const f = await api('followup', null, {ticket_id: button.dataset.ticket, key: button.dataset.key});
      const due = Math.max(Date.now() + 3600000, Date.parse(f.due_at));
      const escalation = Math.max(due + 86400000, Date.parse(f.escalate_at));
      const choices = selected => f.owners.map(o => `<option value="${n(o.user_id)}" ${n(o.user_id) === n(selected) ? 'selected' : ''}>${e(o.user_name)}</option>`).join('');
      sheet('Follow-up plan', `<form id="assist-plan-save" class="form-grid"><input type="hidden" name="ticket_id" value="${n(f.ticket_id)}"><input type="hidden" name="key" value="${e(f.key)}"><input type="hidden" name="expected_version" value="${e(f.version)}"><p><strong>${e(f.summary)}</strong></p><p class="hint">Original due: ${e(fmt(f.source_due_at))}</p>${state.boot.user.write ? `<label>Follow-up owner<select name="owner_id" aria-label="Follow-up owner" required>${f.owner_id ? '' : '<option value="">Choose an owner</option>'}${choices(f.owner_id)}</select></label><label>Next follow-up<input name="due_at" type="datetime-local" required value="${local(due)}"></label><label>Escalate to<select name="escalate_to" aria-label="Escalate to"><option value="0">Owner only</option>${choices(f.escalate_to)}</select></label><label id="assist-escalation-date" ${f.escalate_to ? '' : 'hidden'}>Escalate after<input name="escalate_at" type="datetime-local" value="${local(escalation)}"></label><label>Next step and reason<textarea name="note" aria-label="Next step and reason" required minlength="5" maxlength="1000">${e(f.note)}</textarea></label><button>Save follow-up plan</button>` : '<p class="hint">Ticket write access is required to change this plan.</p>'}${history(f.history)}</form>`);
    }
    return true;
  }
  async function submit(form) {
    if (form.id !== 'assist-plan-save') return false;
    const input = Object.fromEntries(new FormData(form));
    for (const key of ['due_at', 'escalate_at']) if (input[key]) input[key] = new Date(input[key]).toISOString();
    const result = await write('followup_plan', input, form);
    if (result) { closeSheet(); await reload(); }
    return true;
  }
  document.addEventListener('change', event => {
    if (event.target.matches('#assist-plan-save [name=escalate_to]')) $('#assist-escalation-date').hidden = event.target.value === '0';
  });
  return {ticket, click, submit};
}
