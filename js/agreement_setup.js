(function () {
    'use strict';
    const form = document.getElementById('agreement-setup-form');
    if (!form) return;
    const steps = Array.from(form.querySelectorAll('[data-setup-step]'));
    const navigation = document.querySelector('[data-setup-navigation]');
    const back = form.querySelector('[data-setup-back]');
    const next = form.querySelector('[data-setup-next]');
    const save = form.querySelector('[data-setup-save]');
    const status = form.querySelector('[data-setup-status]');
    const exceptionList = form.querySelector('[data-exception-list]');
    const addException = form.querySelector('[data-add-exception]');
    let current = 0;
    let dirty = false;
    let submitting = false;
    let nextException = exceptionList.children.length;
    const field = name => form.elements.namedItem(name);
    const value = name => field(name).value.trim();
    const choice = name => field(name).selectedOptions[0]?.textContent.trim() || 'Not selected';

    function syncCalendar() {
        const business = value('calendar[mode]') === 'business_hours';
        const container = form.querySelector('[data-business-calendar]');
        container.hidden = !business;
        const days = Array.from(container.querySelectorAll('input[type="checkbox"]'));
        container.querySelectorAll('input').forEach(input => { input.disabled = !business; });
        field('calendar[start]').required = business;
        field('calendar[end]').required = business;
        days[0].setCustomValidity(business && !days.some(day => day.checked) ? 'Choose at least one support day.' : '');
        field('calendar[end]').setCustomValidity(business && value('calendar[start]') && value('calendar[end]')
            && value('calendar[end]') <= value('calendar[start]') ? 'End time must be later than start time.' : '');
    }

    function syncSla(row, loadProfile) {
        const profile = row.querySelector('[data-sla-profile]');
        const response = row.querySelector('[data-sla-response]');
        const resolution = row.querySelector('[data-sla-resolution]');
        const hasProfile = profile.value !== '' && profile.value !== '0';
        if (loadProfile) {
            const option = profile.selectedOptions[0];
            response.value = hasProfile ? option.dataset.response : '';
            resolution.value = hasProfile ? option.dataset.resolution : '';
        }
        response.disabled = resolution.disabled = !hasProfile;
        response.required = hasProfile;
        resolution.setCustomValidity(hasProfile && Number(response.value) > 0 && Number(resolution.value) > 0
            && Number(resolution.value) < Number(response.value) ? 'Resolution cannot be sooner than the response target.' : '');
    }

    function syncExceptions() {
        const rows = exceptionList.querySelectorAll('[data-exception-row]');
        rows.forEach(row => {
            row.querySelector('[data-remove-exception]').hidden = false;
            row.querySelector('[data-exception-record]').required = row.querySelector('input').value.trim() !== '';
        });
        addException.disabled = rows.length >= 20;
    }

    function syncDates() {
        field('effective_until').setCustomValidity(value('effective_from') && value('effective_until')
            && value('effective_until') < value('effective_from') ? 'End date cannot precede start date.' : '');
    }

    function summaryGroup(title, step, entries) {
        const section = document.createElement('section');
        section.className = 'n45-agreement-summary-group';
        const heading = document.createElement('h3');
        heading.textContent = title;
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-sm btn-light';
        edit.textContent = 'Edit';
        edit.setAttribute('aria-label', 'Edit ' + title.toLowerCase());
        edit.addEventListener('click', () => show(step));
        const header = document.createElement('div');
        header.append(heading, edit);
        const list = document.createElement('dl');
        entries.forEach(([label, text]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const detail = document.createElement('dd');
            detail.textContent = text || 'Not specified';
            list.append(term, detail);
        });
        section.append(header, list);
        return section;
    }

    function renderSummary() {
        const summary = form.querySelector('[data-setup-summary]');
        summary.replaceChildren();
        summary.append(summaryGroup('Agreement & expectations', 0, [
            ['Name', value('name')], ['Service model', choice('type')],
            ['Term', value('effective_from') + ' to ' + (value('effective_until') || 'evergreen')],
            ['Client responsibilities', value('responsibilities')]
        ]));
        const coverage = ['users', 'devices', 'services', 'locations'].map(scope => [
            scope[0].toUpperCase() + scope.slice(1), choice('coverage[' + scope + '][classification]')
                + (value('coverage[' + scope + '][limit]') !== '' ? '; limit ' + value('coverage[' + scope + '][limit]') : '; no quantity limit')
        ]);
        exceptionList.querySelectorAll('[data-exception-row]').forEach(row => {
            const record = row.querySelector('[data-exception-record]');
            if (record.value) {
                coverage.push([record.selectedOptions[0].textContent,
                    row.querySelectorAll('select')[1].selectedOptions[0].textContent + (row.querySelector('input').value ? ': ' + row.querySelector('input').value : '')]);
            }
        });
        coverage.push(['Scope notes', value('scope_notes')]);
        summary.append(summaryGroup('Coverage', 1, coverage));
        const days = Array.from(form.querySelectorAll('[name="calendar[days][]"]:checked'))
            .map(day => day.closest('label').textContent.trim()).join(', ');
        const calendar = value('calendar[mode]') === '24x7' ? '24/7'
            : days + ', ' + value('calendar[start]') + '-' + value('calendar[end]');
        const targets = [['Support calendar', calendar + ' (' + value('calendar[timezone]') + ')']];
        form.querySelectorAll('[data-priority]').forEach(row => {
            const priority = row.dataset.priority;
            const profile = value('sla[' + priority + '][profile_id]');
            const response = value('sla[' + priority + '][response]');
            const resolution = value('sla[' + priority + '][resolution]');
            targets.push([priority, profile === '0' ? 'No SLA (best effort)'
                : (Number(response) > 0 ? response + ' min response' : 'No response target') + '; '
                    + (Number(resolution) > 0 ? resolution + ' min resolution' : 'no resolution target')]);
        });
        targets.push(['Escalation & after-hours process', value('escalation')]);
        summary.append(summaryGroup('Service levels', 2, targets));
        summary.append(summaryGroup('Business reviews', 3, [
            ['Cadence', 'Every ' + value('review_cadence_months') + ' month(s), starting one interval after activation'],
            ['Renewal notice', value('renewal_notice_days') + ' days'],
            ['Onboarding & agenda', value('review_notes')]
        ]));
    }

    function show(index, focus = true) {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });
        navigation.querySelectorAll('button').forEach((button, i) => {
            if (i === index) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        back.hidden = index === 0;
        next.hidden = index === steps.length - 1;
        save.hidden = !next.hidden;
        if (index === steps.length - 1) renderSummary();
        if (focus) steps[index].querySelector('h2').focus();
        status.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
    }

    function validateThrough(index) {
        syncCalendar();
        syncDates();
        syncExceptions();
        form.querySelectorAll('[data-priority]').forEach(row => syncSla(row, false));
        for (let i = 0; i <= index; i++) {
            const invalid = Array.from(steps[i].querySelectorAll('input, select, textarea'))
                .find(input => !input.disabled && !input.checkValidity());
            if (invalid) {
                show(i, false);
                invalid.focus();
                invalid.reportValidity();
                return false;
            }
        }
        return true;
    }

    form.addEventListener('input', () => { dirty = true; syncDates(); syncCalendar(); syncExceptions(); });
    form.addEventListener('change', event => {
        dirty = true;
        if (event.target.matches('[data-sla-profile]')) syncSla(event.target.closest('[data-priority]'), true);
        else if (event.target.closest('[data-priority]')) syncSla(event.target.closest('[data-priority]'), false);
        syncCalendar();
    });
    navigation.addEventListener('click', event => {
        const button = event.target.closest('[data-go-step]');
        if (!button) return;
        const index = Number(button.dataset.goStep);
        if (index <= current || validateThrough(index - 1)) show(index);
    });
    back.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => { if (validateThrough(current)) show(current + 1); });
    addException.addEventListener('click', () => {
        if (exceptionList.children.length >= 20) return;
        const template = document.getElementById('agreement-exception-template');
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextException++));
        exceptionList.append(wrapper.firstElementChild);
        dirty = true;
        syncExceptions();
        exceptionList.lastElementChild.querySelector('select').focus();
    });
    exceptionList.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-exception]');
        if (!button) return;
        button.closest('[data-exception-row]').remove();
        dirty = true;
        syncExceptions();
        addException.focus();
    });
    form.addEventListener('submit', event => {
        if (submitting || !validateThrough(steps.length - 1)) { event.preventDefault(); return; }
        if (current !== steps.length - 1) { event.preventDefault(); show(steps.length - 1); return; }
        submitting = true;
        save.disabled = true;
        save.textContent = 'Saving draft...';
        status.textContent = 'Saving the agreement, coverage and SLA terms together.';
    });
    window.addEventListener('beforeunload', event => {
        if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });
    // Keep the server-rendered form fully usable when scripts are unavailable.
    form.noValidate = true;
    navigation.hidden = false;
    addException.hidden = false;
    syncCalendar();
    syncExceptions();
    form.querySelectorAll('[data-priority]').forEach(row => syncSla(row, false));
    show(0, false);
}());
