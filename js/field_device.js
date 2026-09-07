/* Device choice is automatic; a narrow desktop window remains the desktop PSA. */
(() => {
  const mobile = !!navigator.userAgentData?.mobile
    || /Android|iPhone|iPad|iPod|Windows Phone|IEMobile|BlackBerry/i.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  window.n45FieldMobile = mobile;
  const url = new URL(location.href), field = url.pathname.startsWith('/agent/field/');
  const id = value => /^\d+$/.test(value || '') && Number(value) > 0 ? String(Number(value)) : '';
  if (field && !mobile) {
    const [view, entity, panel] = url.hash.slice(1).split('/');
    // Hash navigation can move to another job while the original query string remains.
    const ticket = view === 'job' ? id(entity) : !url.hash ? id(url.searchParams.get('ticket_id')) : '';
    const project = view === 'project' ? id(entity) : !url.hash ? id(url.searchParams.get('project_id')) : '';
    location.replace(ticket ? `/agent/ticket.php?ticket_id=${ticket}${panel === 'followups' ? '#followups' : ''}`
      : project ? `/agent/project.php?project_id=${project}` : view === 'projects' ? '/agent/projects.php' : '/agent/tickets.php');
  } else if (!field && mobile) {
    const ticket = id(url.searchParams.get('ticket_id')), project = id(url.searchParams.get('project_id'));
    let target = '/agent/field/';
    if (ticket) target += `?ticket_id=${ticket}${url.hash === '#followups' ? '&panel=followups' : ''}`;
    else if (project) target += `?project_id=${project}`;
    else if (url.pathname.endsWith('/tickets.php')) {
      const filters = new URLSearchParams();
      for (const key of ['q', 'client_id', 'queue']) if (url.searchParams.has(key)) filters.set(key, url.searchParams.get(key));
      filters.set('scope', 'all');
      filters.set('state', ['closed', 'all'].includes(url.searchParams.get('state')) ? 'all' : 'open');
      target += '#jobs?' + filters;
    } else if (url.pathname.endsWith('/projects.php')) target += '#projects';
    location.replace(target);
  }
})();
