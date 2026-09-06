'use strict';
(() => {
    const root = document.querySelector('[data-field-appointment]');
    if (!root) return;
    const text = (tag, value, className = '') => {
        const element = document.createElement(tag);
        element.textContent = value;
        element.className = className;
        return element;
    };
    const date = value => new Date(value).toLocaleString(undefined, {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
    let busy = false;
    async function refresh() {
        if (document.hidden || busy) return;
        busy = true;
        try {
            const response = await fetch('/client/field_status.php?ticket_id=' + encodeURIComponent(root.dataset.fieldAppointment), {cache:'no-store', credentials:'same-origin', redirect:'error'});
            if (!response.ok) throw new Error('Unavailable');
            const {data} = await response.json();
            root.replaceChildren();
            root.hidden = !data;
            if (!data) return;
            root.append(text('h2', 'Your appointment', 'h5'));
            root.append(text('p', `${data.technician} · ${{travel:'On the way',onsite:'Onsite',finished:'Visit complete'}[data.status] || 'Visit in progress'}`, 'mb-2'));
            if (data.eta_at && data.status === 'travel') root.append(text('p','Estimated arrival: ' + date(data.eta_at), 'mb-2'));
            if (data.location) {
                const at = new Date(data.location.updated_at);
                const stale = Date.now() - at.getTime() > 120000;
                root.append(text('p', `Location last updated ${date(at)}${stale ? ' · Awaiting a fresh update' : ''}. Accuracy approximately ${data.location.accuracy} metres.`, 'small text-muted mb-2'));
                const link = text('a', 'View last shared location', 'btn btn-sm btn-outline-secondary mb-2');
                link.href = `https://www.google.com/maps/search/?api=1&query=${data.location.latitude},${data.location.longitude}`;
                link.target = '_blank';link.rel = 'noopener noreferrer';root.append(link);
            } else if (data.status !== 'finished') root.append(text('p', 'A current location is not being shared.', 'small text-muted mb-2'));
            if (data.summary) root.append(text('p', data.summary, 'mb-0 text-break'));
        } catch {
            if (!root.hidden) {
                root.replaceChildren(text('h2','Your appointment','h5'), text('p','Appointment updates are temporarily unavailable. Refresh the page to try again.','small mb-0'));
            }
        } finally {busy = false;}
    }
    refresh();setInterval(refresh, 30000);document.addEventListener('visibilitychange', refresh);
})();
